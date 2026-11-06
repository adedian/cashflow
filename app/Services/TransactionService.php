<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Logika bisnis transaksi: validasi server, penyimpanan atomik (transaksi DB), audit, dan penghitung versi realtime.
 * Saldo TIDAK disimpan; dihitung dari transaksi aktif oleh LedgerService, sehingga edit/void historis selalu konsisten.
 */
final class TransactionService
{
    /** @return array{0:array,1:array} [data bersih, daftar error per field] */
    public static function validate(array $in, ?array $existing = null): array
    {
        $err = [];
        $d = [];
        $src = null;

        $acc = Db::one('SELECT * FROM accounts WHERE id = ? AND is_active = 1', [(int) ($in['account_id'] ?? 0)]);
        if (!$acc) {
            $err['account_id'] = 'Pilih rekening yang valid.';
        }
        $d['account_id'] = $acc ? (int) $acc['id'] : 0;

        $date = Filters::date($in['tx_date'] ?? '');
        if ($date === null) {
            $err['tx_date'] = 'Tanggal tidak valid.';
        } else {
            if ($acc && $date < $acc['opening_date']) {
                $err['tx_date'] = 'Tanggal tidak boleh sebelum tanggal saldo awal rekening (' . fmt_date($acc['opening_date']) . ').';
            }
            if ($date > date('Y-m-d', strtotime('+366 days'))) {
                $err['tx_date'] = 'Tanggal terlalu jauh di masa depan.';
            }
        }
        $d['tx_date'] = $date ?? '';

        $dir = (string) ($in['direction'] ?? '');
        if (!in_array($dir, ['in', 'out'], true)) {
            $err['direction'] = 'Pilih jenis: Mutasi Masuk atau Mutasi Keluar.';
        }
        // Uraian & nominal: satu atau banyak baris (uraian + jumlah); nominal transaksi = Σ jumlah. Bentuk lama (satu
        // "description" + satu "amount") tetap diterima sebagai satu baris tanpa rincian tersimpan.
        [$lines, $amount, $lineErr, $summary] = self::parseLines($in);
        $err += $lineErr;
        $d['items'] = null;          // JSON lama (Revisi 10) tidak ditulis lagi; baris disimpan di tabel transaction_items
        $d['_lines'] = $lines;       // dipisahkan oleh create()/update() sebelum menulis ke transactions
        $d['debit'] = ($dir === 'in' && $amount !== null) ? $amount : '0.00';
        $d['credit'] = ($dir === 'out' && $amount !== null) ? $amount : '0.00';

        if ($lines !== null) {
            // mode baru: kategori & proyek ada di TIAP baris. Kolom pada transactions = nilai bersama bila semua baris sama,
            // selain itu NULL (laporan/filter membaca per baris; kolom ini hanya untuk tampilan ringkas & data lama).
            $common = static function (string $k) use ($lines): ?int {
                $v = array_values(array_unique(array_map(static fn($l) => (int) ($l[$k] ?? 0), $lines)));
                return count($v) === 1 && $v[0] > 0 ? $v[0] : null;
            };
            $d['category_id'] = $common('category_id');
            $d['project_id'] = $common('project_id');
        } else {
            // bentuk lama: satu kategori & satu proyek untuk seluruh transaksi
            $cat = (int) ($in['category_id'] ?? 0);
            if ($cat > 0 && !Db::one('SELECT id FROM categories WHERE id = ? AND is_active = 1', [$cat])) {
                $err['category_id'] = 'Kategori tidak valid.';
            }
            $d['category_id'] = $cat > 0 ? $cat : null;
            $pr = (int) ($in['project_id'] ?? 0);
            if ($pr > 0 && !Db::one('SELECT id FROM projects WHERE id = ? AND is_active = 1', [$pr])) {
                $err['project_id'] = 'Proyek tidak valid.';
            }
            $d['project_id'] = $pr > 0 ? $pr : null;
        }

        $lim = static fn(string $k, int $n) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $n);
        $d['voucher_code'] = $lim('voucher_code', 30) ?: null;
        $d['counterparty'] = PartyService::clean((string) ($in['counterparty'] ?? '')) ?: null;
        $d['description'] = $summary !== '' ? $summary : null;   // ringkasan (daftar/pencarian): uraian pertama, atau semua uraian digabung
        $d['note'] = trim((string) ($in['note'] ?? '')) !== '' ? mb_substr(trim((string) $in['note']), 0, 5000) : null;
        if (array_key_exists('source_ref', $in)) {
            // No. Invoice/PO = pilihan sumber (Piutang | Hutang | Proyeksi): nomor dokumen diturunkan dari sumber, bukan diketik
            $ref = trim((string) $in['source_ref']);
            if ($ref !== '') {
                $p = TxLinkService::parseRef($ref);
                $src = $p ? TxLinkService::source($p[0], $p[1], $existing ? (int) $existing['id'] : null) : null;
                if (!$src) {
                    $err['source_ref'] = 'Referensi Invoice/PO tidak ditemukan. Pilih ulang dari daftar.';
                }
                $d['ref_no'] = $src && ($src['ref_no'] ?? '') !== '' ? mb_substr((string) $src['ref_no'], 0, 190) : null;
            } else {
                // tanpa referensi: teks lama (data impor) dipertahankan kecuali dihapus pengguna / tautan sebelumnya dilepas
                $hadLink = $existing && ($l = TxLinkService::linkOf((int) $existing['id'])) && !$l['legacy'];
                $d['ref_no'] = ($existing && empty($in['ref_clear']) && !$hadLink) ? ($existing['ref_no'] ?? null) : null;
            }
        } else {
            $d['ref_no'] = $lim('ref_no', 190) ?: null;
        }
        // Pihak = pilihan dari master (nama baku dipakai). Boleh: nilai lama transaksi yang sama (data impor), atau pihak dari sumber
        // yang dipilih (supplier/customer) — dimasukkan ke master saat disimpan.
        if ($d['counterparty'] !== null) {
            $canon = PartyService::canonical($d['counterparty']);
            if ($canon !== null) {
                $d['counterparty'] = $canon;
            } elseif (!(($existing['counterparty'] ?? null) !== null && mb_strtolower((string) $existing['counterparty']) === mb_strtolower($d['counterparty']))
                && !(isset($src) && $src && isset($src['party']) && mb_strtolower((string) $src['party']) === mb_strtolower($d['counterparty']))) {
                $err['counterparty'] = 'Pilih pihak dari daftar, atau tambahkan lewat tombol “Tambah” di dropdown (atau Master Data → Customer).';
            }
        }
        if ($existing === null || ($in['counterparty_detail'] ?? null) !== null) {
            $d['counterparty_detail'] = $lim('counterparty_detail', 255) ?: null;
            // harus pilihan master rekening lawan; nilai lama transaksi yang sama tetap boleh (data impor / rekening nonaktif)
            if ($d['counterparty_detail'] !== null
                && $d['counterparty_detail'] !== ($existing['counterparty_detail'] ?? null)
                && !Db::val('SELECT id FROM counterparty_accounts WHERE label = ? AND is_active = 1', [$d['counterparty_detail']])) {
                $err['counterparty_detail'] = 'Pilih rekening lawan dari daftar (tambahkan di Master Data jika belum ada).';
            }
        }
        return [$d, $err];
    }

    public const MAX_LINES = 30;

    /**
     * Baris uraian dari formulir. Bentuk baru: line_desc[] + line_cat[] + line_proj[] + line_amount[] (baris kosong diabaikan,
     * jumlah boleh negatif). Bentuk lama: description + amount (+ category_id/project_id untuk seluruh transaksi).
     * @return array{0:?array,1:?string,2:array,3:string} [baris (null = bentuk lama), total, error, ringkasan uraian]
     */
    private static function parseLines(array $in): array
    {
        $err = [];
        $bad = static fn(?string $a) => $a === null
            ? 'Nominal tidak valid (contoh: 1250000 atau 1.250.000,50).'
            : (bccomp($a, '0', 2) === 0 ? 'Nominal tidak boleh nol.' : (bccomp($a, '9999999999999.99', 2) > 0 || bccomp($a, '-9999999999999.99', 2) < 0 ? 'Nominal terlalu besar.' : null));

        if (!is_array($in['line_desc'] ?? null)) {
            $amount = parse_amount($in['amount'] ?? '', true);
            if (($m = $bad($amount)) !== null) {
                $err['amount'] = $m;
            }
            $desc = mb_substr(trim((string) ($in['description'] ?? '')), 0, 500);
            if ($desc === '') {
                $err['description'] = 'Uraian wajib diisi.';
            }
            return [null, $amount, $err, $desc];
        }

        $descs = array_values($in['line_desc']);
        $amts = is_array($in['line_amount'] ?? null) ? array_values($in['line_amount']) : [];
        $cats = is_array($in['line_cat'] ?? null) ? array_values($in['line_cat']) : [];
        $projs = is_array($in['line_proj'] ?? null) ? array_values($in['line_proj']) : [];
        $okCat = array_flip(array_map('intval', array_column(Db::all('SELECT id FROM categories WHERE is_active = 1'), 'id')));
        $okProj = array_flip(array_map('intval', array_column(Db::all('SELECT id FROM projects WHERE is_active = 1'), 'id')));
        $lines = [];
        $total = '0.00';
        foreach ($descs as $i => $rawDesc) {
            $desc = is_scalar($rawDesc) ? trim((string) $rawDesc) : '';
            $rawAmt = $amts[$i] ?? '';
            $rawAmt = is_scalar($rawAmt) ? trim((string) $rawAmt) : '';
            $cid = is_scalar($cats[$i] ?? null) ? (int) $cats[$i] : 0;
            $pid = is_scalar($projs[$i] ?? null) ? (int) $projs[$i] : 0;
            if ($desc === '' && $rawAmt === '' && $cid === 0 && $pid === 0) {
                continue;   // baris kosong diabaikan
            }
            $row = $i + 1;
            if (count($lines) >= self::MAX_LINES) {
                $err['items'] ??= 'Uraian terlalu banyak (maksimal ' . self::MAX_LINES . ' baris).';
                break;
            }
            if ($desc === '') {
                $err['items'] ??= 'Uraian baris ' . $row . ' wajib diisi.';
            }
            $a = $rawAmt === '' ? null : parse_amount($rawAmt, true);
            if ($rawAmt === '') {
                $err['items'] ??= 'Jumlah baris ' . $row . ' wajib diisi.';
            } elseif (($m = $bad($a)) !== null) {
                $err['items'] ??= 'Jumlah baris ' . $row . ': ' . lcfirst($m);
            }
            if ($cid > 0 && !isset($okCat[$cid])) {
                $err['items'] ??= 'Kategori baris ' . $row . ' tidak valid.';
            }
            if ($pid > 0 && !isset($okProj[$pid])) {
                $err['items'] ??= 'Proyek baris ' . $row . ' tidak valid.';
            }
            if ($a !== null) {
                $total = bcadd($total, $a, 2);
            }
            $lines[] = ['description' => mb_substr($desc, 0, 500), 'category_id' => $cid > 0 ? $cid : null, 'project_id' => $pid > 0 ? $pid : null, 'amount' => $a ?? '0.00'];
        }
        if (!$lines) {
            $err['items'] = 'Isi minimal satu uraian beserta jumlahnya.';
            return [[], null, $err, ''];
        }
        if (!isset($err['items']) && ($m = $bad($total)) !== null) {
            $err['items'] = 'Total ' . lcfirst($m);
        }
        $summary = mb_substr(implode(' · ', array_column($lines, 'description')), 0, 500);
        return [$lines, $err ? null : $total, $err, $summary];
    }

    /**
     * Rincian uraian suatu transaksi untuk tampilan/cetak/ubah. Sumber berurutan: tabel transaction_items; JSON lama
     * (kolom items, Revisi 10); lalu satu baris dari description + nominal + kategori/proyek pada transaksi (data lama/impor).
     * @return array<int,array{description:string,amount:string,category_id:?int,project_id:?int}>
     */
    public static function lines(array $tx): array
    {
        if (!empty($tx['id'])) {
            $rows = Db::all('SELECT description, category_id, project_id, amount FROM transaction_items WHERE transaction_id = ? ORDER BY line_no', [(int) $tx['id']]);
            if ($rows) {
                return array_map(static fn($r) => [
                    'description' => (string) $r['description'],
                    'amount' => bcadd((string) $r['amount'], '0', 2),
                    'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                    'project_id' => $r['project_id'] !== null ? (int) $r['project_id'] : null,
                ], $rows);
            }
        }
        $hc = ($tx['category_id'] ?? null) !== null ? (int) $tx['category_id'] : null;
        $hp = ($tx['project_id'] ?? null) !== null ? (int) $tx['project_id'] : null;
        $raw = $tx['items'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j) && $j) {
                $out = [];
                foreach ($j as $r) {
                    $out[] = ['description' => (string) ($r['description'] ?? ''), 'amount' => bcadd((string) ($r['amount'] ?? '0'), '0', 2), 'category_id' => $hc, 'project_id' => $hp];
                }
                return $out;
            }
        }
        $net = bcsub((string) ($tx['debit'] ?? '0'), (string) ($tx['credit'] ?? '0'), 2);
        $one = bccomp((string) ($tx['debit'] ?? '0'), '0', 2) !== 0 && bccomp((string) ($tx['credit'] ?? '0'), '0', 2) === 0 ? (string) $tx['debit']
            : (bccomp((string) ($tx['credit'] ?? '0'), '0', 2) !== 0 && bccomp((string) ($tx['debit'] ?? '0'), '0', 2) === 0 ? (string) $tx['credit'] : ltrim($net, '-'));
        return [['description' => (string) ($tx['description'] ?? ''), 'amount' => bcadd($one, '0', 2), 'category_id' => $hc, 'project_id' => $hp]];
    }

    /** Baris tersimpan (tabel transaction_items) lengkap dengan nama kategori/proyek; kosong bila transaksi belum memakai tabel itu. */
    public static function itemRows(int $txId): array
    {
        return Db::all(
            'SELECT i.line_no, i.description, i.amount, i.category_id, i.project_id, c.name AS category_name, p.name AS project_name
             FROM transaction_items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN projects p ON p.id = i.project_id
             WHERE i.transaction_id = ? ORDER BY i.line_no',
            [$txId]
        );
    }

    /** Ganti seluruh baris tersimpan suatu transaksi (dipanggil di dalam transaksi DB). */
    private static function saveLines(int $txId, ?array $lines): void
    {
        Db::q('DELETE FROM transaction_items WHERE transaction_id = ?', [$txId]);
        foreach ($lines ?? [] as $i => $l) {
            Db::insert('transaction_items', [
                'transaction_id' => $txId, 'line_no' => $i + 1, 'description' => $l['description'],
                'category_id' => $l['category_id'] ?? null, 'project_id' => $l['project_id'] ?? null, 'amount' => $l['amount'],
            ]);
        }
    }

    /** Ringkasan teks baris untuk jejak audit. */
    private static function linesText(array $lines): string
    {
        return implode(' | ', array_map(static fn($l) => $l['description'] . ' [kat=' . ($l['category_id'] ?? '-') . ', proy=' . ($l['project_id'] ?? '-') . '] ' . $l['amount'], $lines));
    }

    public static function create(array $d, int $userId, ?string $idemKey, ?string $ref = null, bool $confirmDup = false): int
    {
        if ($idemKey !== null && !preg_match('/^[a-f0-9]{32}$/', $idemKey)) {
            $idemKey = null;
        }
        if ($idemKey !== null) {
            $dup = Db::val('SELECT id FROM transactions WHERE idem_key = ?', [$idemKey]);
            if ($dup) {
                return (int) $dup; // kiriman ganda (double-submit): kembalikan transaksi yang sudah tersimpan
            }
        }
        $lines = $d['_lines'] ?? null;
        unset($d['_lines']);
        return (int) Db::tx(function () use ($d, $lines, $userId, $idemKey, $ref, $confirmDup) {
            $seq = (int) Db::val('SELECT COALESCE(MAX(seq), 0) + 1 FROM transactions WHERE account_id = ?', [$d['account_id']]);
            if (($d['counterparty'] ?? null) !== null) {
                PartyService::ensure($d['counterparty']);
            }
            $id = Db::insert('transactions', $d + ['seq' => $seq, 'idem_key' => $idemKey, 'created_by' => $userId]);
            if ($lines !== null) {
                self::saveLines($id, $lines);
            }
            Audit::log('tx_create', 'transaction', (string) $id, self::summaryLine($d), null, $d + ($lines !== null ? ['uraian' => self::linesText($lines)] : []));
            if ($ref !== null) {
                TxLinkService::sync($id, $d, $ref, $userId, $confirmDup);   // LinkError -> seluruh penyimpanan dibatalkan
            }
            Version::bump('transactions');
            return $id;
        });
    }

    public static function update(int $id, array $d, int $userId, ?int $expectedVersion, ?string $ref = null, bool $confirmDup = false): void
    {
        $lines = $d['_lines'] ?? null;
        unset($d['_lines']);
        Db::tx(function () use ($id, $d, $lines, $userId, $expectedVersion, $ref, $confirmDup) {
            $old = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }
            if ($old['voided_at'] !== null) {
                throw new RuntimeException('Transaksi yang sudah dibatalkan tidak dapat diubah. Pulihkan terlebih dahulu.');
            }
            if ($expectedVersion === null || (int) $old['row_version'] !== $expectedVersion) {
                throw new RuntimeException('Data ini baru saja diubah oleh pengguna lain. Muat ulang halaman untuk melihat versi terbaru.');
            }
            $set = $d;
            if (($d['counterparty'] ?? null) !== null) {
                PartyService::ensure($d['counterparty']);
            }
            if ((int) $old['account_id'] !== (int) $d['account_id']) {
                $set['seq'] = (int) Db::val('SELECT COALESCE(MAX(seq), 0) + 1 FROM transactions WHERE account_id = ?', [$d['account_id']]);
            }
            $set['updated_by'] = $userId;
            $set['row_version'] = (int) $old['row_version'] + 1;
            $oldLines = self::lines($old);
            Db::update('transactions', $id, $set);
            self::saveLines($id, $lines);   // bentuk lama (null) = satu baris dari kolom transaksi: baris tersimpan dihapus
            $new = Db::one('SELECT * FROM transactions WHERE id = ?', [$id]);
            Audit::log('tx_update', 'transaction', (string) $id, 'Ubah: ' . self::summaryLine($d),
                self::pick($old) + ['uraian' => self::linesText($oldLines)], self::pick($new) + ['uraian' => self::linesText(self::lines($new))]);
            if ($ref !== null) {
                TxLinkService::sync($id, $d, $ref, $userId, $confirmDup);
            } elseif (($link = TxLinkService::linkOf($id)) && !$link['legacy']) {
                // form lama tanpa kolom referensi: tautan dipertahankan, tetapi arah/nominal baru harus tetap muat pada sumbernya
                TxLinkService::sync($id, $d, $link['type'] . ':' . $link['id'], $userId, true);
            }
            Version::bump('transactions');
        });
    }

    public static function void(int $id, int $userId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }
        Db::tx(function () use ($id, $userId, $reason) {
            $old = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }
            if ($old['voided_at'] !== null) {
                throw new RuntimeException('Transaksi sudah dibatalkan.');
            }
            Db::q('UPDATE transactions SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?', [$userId, $reason, $id]);
            Audit::log('tx_void', 'transaction', (string) $id, 'Batalkan (void): ' . self::summaryLine($old) . ' — ' . $reason, self::pick($old), ['voided' => true, 'reason' => $reason]);
            TxLinkService::onVoid($id);   // pembayaran/penerimaan/realisasi yang bertaut tidak lagi dihitung
            Version::bump('transactions');
        });
    }

    public static function restore(int $id, int $userId): void
    {
        Db::tx(function () use ($id, $userId) {
            $old = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$old || $old['voided_at'] === null) {
                throw new RuntimeException('Transaksi tidak ditemukan atau tidak dalam keadaan dibatalkan.');
            }
            Db::q('UPDATE transactions SET voided_at = NULL, voided_by = NULL, void_reason = NULL, updated_by = ? WHERE id = ?', [$userId, $id]);
            Audit::log('tx_restore', 'transaction', (string) $id, 'Pulihkan transaksi: ' . self::summaryLine($old), ['voided' => true], ['voided' => false]);
            TxLinkService::onRestore($id);   // dihitung lagi bila masih muat dalam sisa sumbernya
            Version::bump('transactions');
        });
    }

    private static function pick(array $r): array
    {
        $keys = ['account_id', 'tx_date', 'voucher_code', 'counterparty', 'category_id', 'project_id', 'description', 'items', 'note', 'ref_no', 'debit', 'credit'];
        return array_intersect_key($r, array_flip($keys));
    }

    private static function summaryLine(array $d): string
    {
        $in = bccomp((string) $d['debit'], '0', 2) !== 0;
        return ($d['tx_date'] ?? '') . ' ' . ($in ? 'masuk ' . $d['debit'] : 'keluar ' . $d['credit']) . ' — ' . mb_substr((string) ($d['description'] ?? ''), 0, 120);
    }
}
