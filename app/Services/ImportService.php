<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Impor workbook cash flow asli dengan PEMETAAN SHEET EKSPLISIT (tidak menebak struktur):
 *   sheet rekening sesuai profil impor (config/import_profile.php)  -> transactions
 *   CASH FLOW (baris 20-97)                                   -> forecast_items
 *   Tagihan PLTS - Fee (rekap invoice, baris 212+)            -> invoices
 * Tidak diimpor: sheet tersembunyi, Piutang Lampu (status bayar tidak terbaca), hutang, Estimasi biaya.
 */
final class ImportService
{
    private XlsxReader $xl;

    public function __construct(private string $path)
    {
        $this->xl = new XlsxReader($path);
    }

    public function close(): void
    {
        $this->xl->close();
    }

    /** Parse + validasi (tanpa menyentuh database). */
    public function parse(): array
    {
        $issues = [];
        $accounts = [];
        $txs = [];
        $stats = [];

        foreach (ImportProfile::get()['tx_sheets'] as $sheet => $def) {
            // nama sheet di Excel bisa memiliki spasi akhir; cari persis lalu longgar
            $real = $this->resolveSheet($sheet);
            if ($real === null) {
                $issues[] = ['level' => 'error', 'sheet' => $sheet, 'row' => 0, 'msg' => 'Sheet wajib tidak ditemukan di berkas.'];
                continue;
            }
            $rows = $this->xl->rows($real);
            $acc = $def['account'];
            $opening = '0.00';
            if ($acc['open_cell']) {
                $opening = XlsxReader::money($this->cell($rows, $acc['open_cell'])) ?? '0.00';
            }
            $acc['opening_balance'] = $opening;
            $accounts[$acc['code']] = $acc;

            $c = $def['cols'];
            $seq = 0;
            $prevDate = null;
            $prevCode = null;
            $seen = [];
            $sumD = '0.00';
            $sumC = '0.00';
            $n = 0;
            foreach ($rows as $r => $cells) {
                if ($r < $def['first'] || $r > $def['last']) {
                    continue;
                }
                $get = static fn(?string $col): string => $col && isset($cells[$col]) ? trim($cells[$col]['v']) : '';
                // baris judul bulan ("Bulan Januari 2026")
                if (preg_match('/^bulan\s/i', $get($c['party']))) {
                    continue;
                }
                $dRaw = $get($c['debit']);
                $cRaw = $get($c['credit']);
                if ($dRaw === '' && $cRaw === '') {
                    continue;
                }
                foreach ([['debit', $dRaw], ['credit', $cRaw]] as [$nm, $raw]) {
                    if ($raw !== '' && !is_numeric($raw)) {
                        $issues[] = ['level' => 'error', 'sheet' => $sheet, 'row' => $r, 'msg' => "Nilai $nm bukan angka: $raw"];
                    }
                }
                $debit = XlsxReader::money($dRaw) ?? '0.00';
                $credit = XlsxReader::money($cRaw) ?? '0.00';

                $flags = [];
                $date = XlsxReader::serialToDate($get($c['date']));
                $code = $get($c['code']);
                if ($date === null) {
                    if ($prevDate === null) {
                        $issues[] = ['level' => 'error', 'sheet' => $sheet, 'row' => $r, 'msg' => 'Tanggal tidak ada dan tidak ada baris sebelumnya.'];
                        continue;
                    }
                    $date = $prevDate;
                    $flags[] = 'Tanggal tidak ada di Excel; mengikuti baris sebelumnya';
                    if ($code === '' && $c['code'] !== null) {
                        $code = (string) $prevCode;
                        $flags[] = 'kode bukti mengikuti baris sebelumnya';
                    }
                    $issues[] = ['level' => 'warn', 'sheet' => $sheet, 'row' => $r, 'msg' => 'Tanggal kosong: dipakai tanggal baris sebelumnya (' . $date . ')'];
                } else {
                    $prevDate = $date;
                }
                $prevCode = $code !== '' ? $code : $prevCode;

                [$party, $detail] = self::splitParty($get($c['party']));
                $catRaw = $get($c['cat']);
                $desc = $get($c['desc']);

                $key = md5($date . '|' . $code . '|' . $get($c['party']) . '|' . $desc . '|' . $debit . '|' . $credit);
                if (isset($seen[$key]) && $code !== '') {
                    $flags[] = 'Duplikat dicurigai: sama persis dengan baris Excel ' . $seen[$key];
                    $issues[] = ['level' => 'warn', 'sheet' => $sheet, 'row' => $r, 'msg' => 'Duplikat dicurigai dengan baris ' . $seen[$key] . ' (diimpor apa adanya agar saldo sama dengan Excel)'];
                } else {
                    $seen[$key] = $r;
                }
                if ($catRaw === '') {
                    $flags[] = 'Kategori kosong di Excel';
                }

                $seq++;
                $n++;
                $sumD = bcadd($sumD, $debit, 2);
                $sumC = bcadd($sumC, $credit, 2);
                $txs[] = [
                    'account' => $acc['code'], 'date' => $date, 'seq' => $seq, 'voucher' => $code !== '' ? $code : null,
                    'party' => $party, 'party_detail' => $detail, 'cat_raw' => $catRaw, 'desc' => $desc,
                    'note' => $get($c['note']), 'ref' => $get($c['ref']),
                    'debit' => $debit, 'credit' => $credit, 'flag' => $flags ? implode('; ', $flags) : null,
                    'sheet' => $sheet, 'row' => $r,
                ];
            }

            // rekonsiliasi dengan saldo akhir di Excel
            $calc = $acc['mode'] === 'asset'
                ? bcsub(bcadd($opening, $sumD, 2), $sumC, 2)
                : bcsub(bcadd($opening, $sumC, 2), $sumD, 2); // liability: pemakaian = penarikan - pembayaran
            $excel = null;
            if ($def['excel_final']) {
                $excel = XlsxReader::money($this->cell($rows, $def['excel_final']));
            } elseif ($acc['mode'] === 'liability') {
                // Time Loan: pemakaian = limit + saldo sheet (I39 = penarikan - pembayaran - plafon, bernilai -limit bila pemakaian 0)
                $i39 = XlsxReader::money($this->cell($rows, 'I39'));
                $excel = $i39 === null ? null : bcadd((string) $acc['limit'], $i39, 2);
            }
            $stats[$acc['code']] = ['sheet' => $sheet, 'rows' => $n, 'sum_debit' => $sumD, 'sum_credit' => $sumC, 'opening' => $opening,
                'calc_final' => $calc, 'excel_final' => $excel,
                'match' => $excel !== null && abs((float) bcsub($calc, $excel, 2)) <= 1.0];
            if ($excel === null || abs((float) bcsub($calc, $excel, 2)) > 1.0) {
                $issues[] = ['level' => 'error', 'sheet' => $sheet, 'row' => 0, 'msg' => "Saldo akhir hasil hitung ($calc) tidak cocok dengan Excel (" . ($excel ?? 'n/a') . ')'];
            }
        }

        $forecast = $this->parseForecast($issues);
        $invoices = $this->parseInvoices($issues);

        // pemetaan kategori
        $map = [];
        foreach ($txs as $t) {
            $raw = $t['cat_raw'];
            if (!isset($map[$raw])) {
                $map[$raw] = CategoryMapper::map($raw) + ['count' => 0];
            }
            $map[$raw]['count']++;
        }
        foreach ($map as $raw => $m) {
            if ($m['category'] === CategoryMapper::UNCAT && $raw !== '') {
                $issues[] = ['level' => 'warn', 'sheet' => '-', 'row' => 0, 'msg' => "Kategori '$raw' tidak dikenali -> Belum Dikategorikan ($m[count] baris)"];
            }
        }

        $errors = count(array_filter($issues, static fn($i) => $i['level'] === 'error'));
        return [
            'accounts' => $accounts, 'transactions' => $txs, 'forecast' => $forecast, 'invoices' => $invoices,
            'category_map' => $map, 'stats' => $stats, 'issues' => $issues, 'errors' => $errors,
            'skipped' => ['Sheet1 (tersembunyi, data 2020, entitas lain)', 'Piutang Lampu (status pembayaran tidak terbaca: formula #REF!)', 'hutang & Estimasi biaya (sumber baris yang sudah ada di CASH FLOW)'],
        ];
    }

    /** @return array<int,array> */
    private function parseForecast(array &$issues): array
    {
        $real = $this->resolveSheet('CASH FLOW');
        if ($real === null) {
            $issues[] = ['level' => 'warn', 'sheet' => 'CASH FLOW', 'row' => 0, 'msg' => 'Sheet CASH FLOW tidak ditemukan; proyeksi dilewati.'];
            return [];
        }
        $rows = $this->xl->rows($real);
        $months = ['mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12];
        $out = [];
        $period = null;
        foreach ($rows as $r => $cells) {
            if ($r < 20 || $r > 97) {
                continue;
            }
            $C = isset($cells['C']) ? trim($cells['C']['v']) : '';
            if (preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $C, $m) && isset($months[strtolower($m[1])]) && !isset($cells['E']) && !isset($cells['H'])) {
                $period = sprintf('%04d-%02d', (int) $m[2], $months[strtolower($m[1])]);
                continue;
            }
            if ($period === null || $C === '') {
                continue;
            }
            $date = isset($cells['A']) ? XlsxReader::serialToDate($cells['A']['v']) : null;
            $hint = isset($cells['D']) ? trim($cells['D']['v']) : null;
            foreach ([['E', 'out'], ['H', 'in']] as [$col, $dir]) {
                if (!isset($cells[$col]) || !is_numeric($cells[$col]['v'])) {
                    continue;
                }
                $amt = XlsxReader::money($cells[$col]['v']);
                if ($amt === null || (float) $amt == 0.0) {
                    continue;
                }
                $out[] = ['period' => $period, 'date' => $date, 'direction' => $dir, 'desc' => mb_substr($C, 0, 500), 'hint' => $hint !== null ? mb_substr($hint, 0, 80) : null, 'amount' => $amt, 'row' => $r];
            }
        }
        return $out;
    }

    /** @return array<int,array> */
    private function parseInvoices(array &$issues): array
    {
        $real = $this->resolveSheet('Tagihan PLTS - Fee');
        if ($real === null) {
            return [];
        }
        $rows = $this->xl->rows($real);
        // cari baris header rekap ("Customer" di kolom A)
        $start = null;
        foreach ($rows as $r => $cells) {
            if (isset($cells['A']) && trim($cells['A']['v']) === 'Customer' && isset($cells['F']) && stripos($cells['F']['v'], 'Invoice') !== false) {
                $start = $r;
            }
        }
        if ($start === null) {
            $issues[] = ['level' => 'warn', 'sheet' => 'Tagihan PLTS - Fee', 'row' => 0, 'msg' => 'Tabel rekap invoice tidak ditemukan; dilewati.'];
            return [];
        }
        $out = [];
        $cust = null;
        foreach ($rows as $r => $cells) {
            if ($r <= $start || $r > $start + 60) {
                continue;
            }
            $g = static fn(string $c): string => isset($cells[$c]) ? trim($cells[$c]['v']) : '';
            $inv = $g('F');
            $amount = XlsxReader::money($g('G'));
            if ($inv === '' || $amount === null || preg_match('/^total$/i', $inv)) {
                continue;   // baris subtotal ("TOTAL"/"Total") bukan invoice
            }
            if ($g('A') !== '') {
                $cust = $g('A');
            }
            $out[] = [
                'customer' => $g('A') !== '' ? $g('A') : '(tidak tercatat di Excel)',
                'project' => $g('C') !== '' ? $g('C') : null, 'desc' => $g('D') !== '' ? $g('D') : null,
                'date' => XlsxReader::serialToDate($g('E')), 'no' => $inv, 'amount' => $amount,
                'paid' => XlsxReader::money($g('H')) ?? '0.00', 'row' => $r,
            ];
        }
        return $out;
    }

    private function resolveSheet(string $name): ?string
    {
        $names = array_keys($this->xl->sheetNames());
        foreach ($names as $n) {
            if ($n === $name) {
                return $n;
            }
        }
        foreach ($names as $n) {
            if (trim($n) === trim($name)) {
                return $n;
            }
        }
        return null;
    }

    private function cell(array $rows, string $ref)
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
            return null;
        }
        return $rows[(int) $m[2]][$m[1]]['v'] ?? null;
    }

    /** Pisahkan "Nama - BCA 123456" menjadi nama dan detail rekening. */
    public static function splitParty(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        if ($raw === '') {
            return [null, null];
        }
        if (preg_match('/^(.*?)\s*-\s*((?:BCA|BRI|BNI|Mandiri|UOB|Seabank|BTN|CIMB|BSI|Permata|Jago|BJB|Muamalat|OCBC|Danamon|BVA|Cab\.)\b.*)$/i', $raw, $m) && trim($m[1]) !== '') {
            return [mb_substr(trim($m[1]), 0, 255), mb_substr(trim($m[2]), 0, 255)];
        }
        if (preg_match('/^(.*?)\s+(?:(?:BCA|BRI|BNI|Mandiri|UOB|Seabank|BVA)\s*)?(?:ac\.?|a\/c|rek\.?)\s*[:.]?\s*(\d[\d\s-]{5,})$/i', $raw, $m) && trim($m[1]) !== '') {
            return [mb_substr(trim($m[1]), 0, 255), mb_substr(trim($m[2]), 0, 255)];
        }
        return [mb_substr($raw, 0, 255), null];
    }

    /* ======================= COMMIT ======================= */

    /**
     * Simpan hasil parse ke database dalam SATU transaksi. Tidak menimpa data lama:
     * ditolak bila hasil impor sebelumnya masih aktif (batalkan batch lama lebih dulu).
     */
    public static function commit(array $parsed, ?int $userId, string $filename, string $sha256): int
    {
        if ($parsed['errors'] > 0) {
            throw new RuntimeException('Impor dibatalkan: masih ada kesalahan validasi.');
        }
        $dup = Db::val("SELECT id FROM import_batches WHERE sha256 = ? AND status = 'imported' AND kind = 'workbook'", [$sha256]);
        if ($dup) {
            throw new RuntimeException('Berkas yang sama sudah pernah diimpor (batch #' . $dup . ').');
        }
        $active = (int) Db::val("SELECT COUNT(*) FROM transactions t JOIN import_batches b ON b.id = t.import_batch_id WHERE b.status = 'imported' AND b.kind = 'workbook' AND t.voided_at IS NULL");
        if ($active > 0) {
            throw new RuntimeException('Masih ada data hasil impor sebelumnya yang aktif. Batalkan (void) batch lama terlebih dahulu.');
        }

        return (int) Db::tx(function () use ($parsed, $userId, $filename, $sha256) {
            $batchId = Db::insert('import_batches', [
                'filename' => mb_substr($filename, 0, 255), 'sha256' => $sha256, 'status' => 'imported', 'created_by' => $userId,
                'stats_json' => json_encode(['stats' => $parsed['stats'], 'forecast' => count($parsed['forecast']), 'invoices' => count($parsed['invoices'])]),
            ]);

            // akun (dipakai ulang bila kode sudah ada)
            $accId = [];
            foreach ($parsed['accounts'] as $code => $a) {
                $row = Db::one('SELECT id FROM accounts WHERE code = ?', [$code]);
                $accId[$code] = $row ? (int) $row['id'] : Db::insert('accounts', [
                    'code' => $code, 'name' => $a['name'], 'kind' => $a['kind'], 'balance_mode' => $a['mode'],
                    'credit_limit' => $a['limit'], 'opening_balance' => $a['opening_balance'], 'opening_date' => $a['open_date'],
                    'acct_hint' => $a['hint'], 'sort_order' => $a['sort'],
                ]);
            }

            // kategori, proyek, pemetaan
            $catId = [];
            $projId = [];
            foreach ($parsed['category_map'] as $raw => $m) {
                if (!isset($catId[$m['category']])) {
                    $r = Db::one('SELECT id FROM categories WHERE name = ?', [$m['category']]);
                    $catId[$m['category']] = $r ? (int) $r['id'] : Db::insert('categories', ['name' => $m['category'], 'kind' => $m['kind']]);
                }
                $pid = null;
                if ($m['project'] !== null) {
                    if (!isset($projId[$m['project']])) {
                        $r = Db::one('SELECT id FROM projects WHERE name = ?', [$m['project']]);
                        $projId[$m['project']] = $r ? (int) $r['id'] : Db::insert('projects', ['name' => $m['project']]);
                    }
                    $pid = $projId[$m['project']];
                }
                Db::q('INSERT INTO category_map (raw_text, category_id, project_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), project_id = VALUES(project_id)',
                    [mb_substr($raw, 0, 190), $catId[$m['category']], $pid]);
            }

            $st = Db::pdo()->prepare('INSERT INTO transactions (account_id, tx_date, seq, voucher_code, counterparty, counterparty_detail, category_id, project_id, category_raw, description, note, ref_no, debit, credit, flag, source_sheet, source_row, import_batch_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($parsed['transactions'] as $t) {
                $m = $parsed['category_map'][$t['cat_raw']];
                $pid = $m['project'] !== null ? $projId[$m['project']] : null;
                $st->execute([
                    $accId[$t['account']], $t['date'], $t['seq'], $t['voucher'], $t['party'], $t['party_detail'],
                    $catId[$m['category']], $pid, $t['cat_raw'] !== '' ? mb_substr($t['cat_raw'], 0, 190) : null,
                    $t['desc'] !== '' ? mb_substr($t['desc'], 0, 500) : null, $t['note'] !== '' ? $t['note'] : null,
                    $t['ref'] !== '' ? mb_substr($t['ref'], 0, 190) : null, $t['debit'], $t['credit'],
                    $t['flag'] !== null ? mb_substr($t['flag'], 0, 190) : null, $t['sheet'], $t['row'], $batchId, $userId,
                ]);
            }

            foreach ($parsed['forecast'] as $f) {
                Db::insert('forecast_items', ['period_month' => $f['period'], 'expected_date' => $f['date'], 'direction' => $f['direction'],
                    'description' => $f['desc'], 'account_hint' => $f['hint'], 'amount' => $f['amount'], 'source_sheet' => 'CASH FLOW', 'source_row' => $f['row'], 'import_batch_id' => $batchId, 'created_by' => $userId]);
            }
            foreach ($parsed['invoices'] as $i) {
                Db::insert('invoices', ['customer' => mb_substr($i['customer'], 0, 160), 'project_name' => $i['project'] !== null ? mb_substr($i['project'], 0, 200) : null,
                    'description' => $i['desc'] !== null ? mb_substr($i['desc'], 0, 255) : null, 'invoice_date' => $i['date'], 'invoice_no' => mb_substr($i['no'], 0, 80),
                    'amount' => $i['amount'], 'paid' => $i['paid'], 'source_row' => $i['row'], 'import_batch_id' => $batchId, 'created_by' => $userId]);
            }

            PartyService::syncFromTransactions();   // pihak baru dari impor masuk ke master (dropdown Pihak)
            foreach (Version::SCOPES as $s) {
                Version::bump($s);
            }
            Audit::log('import', 'import_batch', (string) $batchId, 'Impor Excel: ' . $filename . ' (' . count($parsed['transactions']) . ' transaksi)', null, ['stats' => $parsed['stats']], $userId, $userId === null ? 'cli' : null);
            return $batchId;
        });
    }

    /** Batalkan batch: semua transaksinya di-void (tidak dihapus fisik). */
    public static function voidBatch(int $batchId, int $userId): int
    {
        return (int) Db::tx(function () use ($batchId, $userId) {
            $b = Db::one("SELECT * FROM import_batches WHERE id = ? AND status = 'imported'", [$batchId]);
            if (!$b) {
                throw new RuntimeException('Batch tidak ditemukan atau sudah dibatalkan.');
            }
            $n = Db::q("UPDATE transactions SET voided_at = NOW(), voided_by = ?, void_reason = 'Batch impor dibatalkan' WHERE import_batch_id = ? AND voided_at IS NULL", [$userId, $batchId])->rowCount();
            // hutang hasil Impor Excel berpemetaan: di-void (riwayat & pembayaran tetap tersimpan), bukan dihapus
            $nd = Db::q("UPDATE debts SET voided_at = NOW(), voided_by = ?, void_reason = 'Batch impor dibatalkan' WHERE import_batch_id = ? AND voided_at IS NULL", [$userId, $batchId])->rowCount();
            Db::q('DELETE FROM forecast_items WHERE import_batch_id = ?', [$batchId]);
            Db::q('DELETE FROM invoices WHERE import_batch_id = ?', [$batchId]);
            Db::update('import_batches', $batchId, ['status' => 'voided']);
            foreach (Version::SCOPES as $s) {
                Version::bump($s);
            }
            Audit::log('import_void', 'import_batch', (string) $batchId, "Batch impor #$batchId dibatalkan ($n transaksi, $nd hutang di-void)");
            return $n + $nd;
        });
    }
}
