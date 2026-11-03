<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Satu jalur pencatatan: pembayaran Hutang, penerimaan Piutang, dan realisasi Proyeksi dicatat SEKALI lewat menu Transaksi.
 * Sebuah transaksi terhubung ke paling banyak SATU sumber (UNIQUE transaction_id di tiap tabel tautan + pemeriksaan lintas-tabel
 * di bawah kunci baris transaksi), sehingga saldo/pembayaran/realisasi tidak pernah dihitung dua kali.
 *
 *   Hutang   -> debt_payments            (sisa = nominal − Σ pembayaran yang transaksinya tidak dibatalkan)
 *   Piutang  -> invoice_payments         (invoices.paid = total dibayar: historis Excel + penerimaan ini; disesuaikan per tautan)
 *   Proyeksi -> forecast_realizations    (sisa = rencana − Σ realisasi yang transaksinya tidak dibatalkan)
 * Item Proyeksi yang tertaut ke Hutang/Piutang TIDAK direalisasikan langsung: ia otomatis mengikuti sumbernya (LinkService).
 */
final class TxLinkService
{
    private const EPS = '0.004';

    /** Σ pembayaran hutang yang sah per hutang (transaksi dibatalkan tidak dihitung; pembayaran lama tanpa transaksi tetap dihitung). */
    public const DEBT_PAID_SUB = 'SELECT p.debt_id, SUM(p.amount) AS paid, MAX(p.pay_date) AS last_pay FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.transaction_id IS NULL OR t.voided_at IS NULL GROUP BY p.debt_id';
    public const FC_REAL_SUB = 'SELECT r.forecast_id, SUM(r.amount) AS realized FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id WHERE t.voided_at IS NULL GROUP BY r.forecast_id';

    private const TABLES = ['debt' => ['debt_payments', 'debt_id'], 'invoice' => ['invoice_payments', 'invoice_id'], 'forecast' => ['forecast_realizations', 'forecast_id']];
    private const NAMES = ['debt' => 'Hutang', 'invoice' => 'Piutang', 'forecast' => 'Proyeksi'];

    /** @return array{0:string,1:int}|null */
    public static function parseRef(?string $s): ?array
    {
        if ($s === null || !preg_match('/^(debt|invoice|forecast):([1-9]\d{0,9})$/', trim($s), $m)) {
            return null;
        }
        return [$m[1], (int) $m[2]];
    }

    public static function name(string $type): string
    {
        return self::NAMES[$type] ?? $type;
    }

    /** Tautan transaksi: ['type','id'(sumber),'row'(id baris tautan),'amount','legacy'(true = data historis invoices.pay_tx_id)] */
    public static function linkOf(int $txId): ?array
    {
        foreach (self::TABLES as $type => [$tbl, $fk]) {
            $r = Db::one("SELECT id, $fk AS sid, amount FROM $tbl WHERE transaction_id = ?", [$txId]);
            if ($r) {
                return ['type' => $type, 'id' => (int) $r['sid'], 'row' => (int) $r['id'], 'amount' => (string) $r['amount'], 'legacy' => false];
            }
        }
        $i = Db::one('SELECT id FROM invoices WHERE pay_tx_id = ? ORDER BY id LIMIT 1', [$txId]);
        return $i ? ['type' => 'invoice', 'id' => (int) $i['id'], 'row' => 0, 'amount' => '0.00', 'legacy' => true] : null;
    }

    /**
     * Keadaan sumber. $exceptTx = transaksi yang sedang disimpan (pembayarannya sendiri tidak dihitung agar edit tidak "melebihi sisa").
     * @return array{type:string,id:int,direction:string,party:?string,ref_no:?string,title:string,amount:string,paid:string,remaining:string,due:?string,account_id:?int,block:?string}|null
     */
    public static function source(string $type, int $id, ?int $exceptTx = null, bool $lock = false): ?array
    {
        $fu = $lock ? ' FOR UPDATE' : '';
        $ex = $exceptTx ?? 0;
        if ($type === 'debt') {
            $d = Db::one('SELECT * FROM debts WHERE id = ?' . $fu, [$id]);
            if (!$d) {
                return null;
            }
            $paid = (string) Db::val('SELECT COALESCE(SUM(p.amount), 0) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = ? AND (p.transaction_id IS NULL OR t.voided_at IS NULL) AND (p.transaction_id IS NULL OR p.transaction_id <> ?)', [$id, $ex]);
            return [
                'type' => 'debt', 'tag' => DebtService::CATEGORIES[$d['category']] ?? 'Hutang', 'id' => $id, 'direction' => 'out', 'party' => $d['supplier'], 'ref_no' => $d['ref_no'],
                'title' => (string) ($d['ref_no'] ?: ($d['description'] ?: $d['supplier'])), 'description' => $d['description'],
                'amount' => (string) $d['amount'], 'paid' => $paid, 'remaining' => bcsub((string) $d['amount'], $paid, 2), 'due' => $d['due_date'],
                'account_id' => $d['source_account_id'] !== null ? (int) $d['source_account_id'] : null,
                'block' => $d['voided_at'] !== null ? 'Hutang ini sudah dibatalkan.' : null,
            ];
        }
        if ($type === 'invoice') {
            $i = Db::one('SELECT * FROM invoices WHERE id = ?' . $fu, [$id]);
            if (!$i) {
                return null;
            }
            $own = (string) Db::val('SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id WHERE p.invoice_id = ? AND p.transaction_id = ? AND t.voided_at IS NULL', [$id, $ex]);
            $paid = bcsub((string) $i['paid'], $own, 2);
            return [
                'type' => 'invoice', 'id' => $id, 'direction' => 'in', 'party' => str_starts_with((string) $i['customer'], '(tidak tercatat') ? null : $i['customer'], 'ref_no' => $i['invoice_no'],
                'title' => (string) ($i['invoice_no'] ?: ($i['description'] ?: $i['customer'])), 'description' => $i['description'] ?: $i['project_name'],
                'amount' => (string) $i['amount'], 'paid' => $paid, 'remaining' => bcsub((string) $i['amount'], $paid, 2), 'due' => $i['due_date'],
                'account_id' => null,
                'block' => (int) $i['paid_known'] !== 1 ? 'Status pembayaran piutang ini “Perlu konfirmasi” (tidak terbaca di Excel). Konfirmasi dulu lewat Ubah piutang.' : null,
            ];
        }
        if ($type === 'forecast') {
            $f = Db::one('SELECT * FROM forecast_items WHERE id = ?' . $fu, [$id]);
            if (!$f) {
                return null;
            }
            $paid = (string) Db::val('SELECT COALESCE(SUM(r.amount), 0) FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id WHERE r.forecast_id = ? AND t.voided_at IS NULL AND r.transaction_id <> ?', [$id, $ex]);
            $block = null;
            if (!$f['is_active']) {
                $block = 'Item Proyeksi ini nonaktif.';
            } elseif ($f['closed_at'] !== null && $f['paid_at'] === null) {
                $block = 'Sisa estimasi ini sudah diakhiri (Selesai — Sisa Diakhiri) sehingga tidak dapat direalisasikan lagi. Buka kembali sisanya di halaman item Proyeksi bila masih akan direalisasikan.';
            } elseif (Db::val('SELECT id FROM debts WHERE forecast_item_id = ?', [$id]) || Db::val('SELECT id FROM invoices WHERE forecast_item_id = ?', [$id])) {
                $block = 'Item Proyeksi ini tertaut ke Hutang/Piutang. Catat lewat Hutang/Piutang-nya; item Proyeksi mengikutinya otomatis.';
            } elseif ($f['paid_at'] !== null && !Db::val('SELECT id FROM forecast_realizations WHERE forecast_id = ? LIMIT 1', [$id])) {
                $block = 'Item Proyeksi ini sudah ditandai ' . ($f['direction'] === 'out' ? 'dibayar' : 'diterima') . ' secara manual (data lama).';
            }
            return [
                'type' => 'forecast', 'id' => $id, 'direction' => $f['direction'], 'party' => null, 'ref_no' => null,
                'title' => (string) $f['description'], 'description' => $f['description'],
                'amount' => (string) $f['amount'], 'paid' => $paid, 'remaining' => bcsub((string) $f['amount'], $paid, 2), 'due' => $f['expected_date'],
                'account_id' => null, 'block' => $block,
            ];
        }
        return null;
    }

    /* ------------------------------------------------------------------ simpan / sinkron ------------------------------------------------------------------ */

    /**
     * Dipanggil DI DALAM transaksi DB yang menyimpan transaksi (create/update). Menyesuaikan tautan dengan pilihan $refRaw
     * ("debt:12" | "invoice:3" | "forecast:7" | kosong = tanpa tautan). Melempar LinkError -> seluruh penyimpanan di-rollback.
     * @param array $tx account_id, tx_date, debit, credit (nilai yang disimpan)
     */
    public static function sync(int $txId, array $tx, ?string $refRaw, int $userId, bool $confirmDup): void
    {
        Db::val('SELECT id FROM transactions WHERE id = ? FOR UPDATE', [$txId]);
        $refRaw = trim((string) $refRaw);
        $want = $refRaw === '' ? null : self::parseRef($refRaw);
        if ($refRaw !== '' && $want === null) {
            throw new LinkError('Referensi Invoice/PO tidak valid. Pilih ulang dari daftar.');
        }
        $cur = self::linkOf($txId);
        if ($cur && $cur['legacy']) {
            if ($want) {
                throw new LinkError('Transaksi ini sudah tercatat sebagai bukti penerimaan Piutang (data historis). Lepaskan tautannya di halaman Piutang terlebih dahulu.');
            }
            return;
        }
        if (!$want && !$cur) {
            return;
        }
        $in = bccomp((string) $tx['debit'], '0', 2) !== 0;
        $dir = $in ? 'in' : 'out';
        $amt = $in ? (string) $tx['debit'] : (string) $tx['credit'];
        $same = $want && $cur && $want[0] === $cur['type'] && $want[1] === $cur['id'];
        if ($cur && !$same) {
            self::detach($txId, $cur, $userId);
            $cur = null;
        }
        if (!$want) {
            return;
        }
        if (bccomp($amt, '0', 2) <= 0) {
            throw new LinkError('Nominal transaksi harus lebih dari nol agar dapat dihubungkan ke ' . self::name($want[0]) . '.');
        }
        $src = self::source($want[0], $want[1], $txId, true);
        if (!$src) {
            throw new LinkError('Referensi Invoice/PO tidak ditemukan (mungkin sudah dihapus). Pilih ulang dari daftar.');
        }
        if ($src['block']) {
            throw new LinkError($src['block']);
        }
        if ($src['direction'] !== $dir) {
            throw new LinkError($src['direction'] === 'out'
                ? ($want[0] === 'debt' ? 'Hutang hanya dapat dibayar dengan Mutasi Keluar.' : 'Item Proyeksi pengeluaran hanya dapat direalisasikan dengan Mutasi Keluar.')
                : ($want[0] === 'invoice' ? 'Piutang hanya dapat diterima dengan Mutasi Masuk.' : 'Item Proyeksi penerimaan hanya dapat direalisasikan dengan Mutasi Masuk.'));
        }
        if (bccomp($amt, $src['remaining'], 2) > 0) {
            throw new LinkError('Nominal ' . money($amt) . ' melebihi sisa ' . self::name($want[0]) . ' (' . money(bccomp($src['remaining'], '0', 2) > 0 ? $src['remaining'] : '0') . '). Untuk pembayaran sebagian, isi nominal sesuai yang benar-benar ' . ($dir === 'out' ? 'dibayar' : 'diterima') . '.');
        }
        [$tbl, $fk] = self::TABLES[$want[0]];
        if (!$same) {
            // peringatan pembayaran identik (sumber, nominal, tanggal sama) yang sudah tercatat
            $dup = Db::val("SELECT t.id FROM $tbl l JOIN transactions t ON t.id = l.transaction_id WHERE l.$fk = ? AND l.amount = ? AND t.tx_date = ? AND t.voided_at IS NULL AND t.id <> ? LIMIT 1", [$want[1], $amt, $tx['tx_date'], $txId]);
            if ($dup && !$confirmDup) {
                $e = new LinkError('Pembayaran yang sama (' . self::name($want[0]) . ' ini, ' . money($amt) . ', tanggal ' . fmt_date($tx['tx_date']) . ') sudah tercatat pada transaksi #' . $dup . '. Bila ini memang pembayaran terpisah, centang konfirmasi di bawah lalu simpan lagi.');
                $e->duplicate = true;
                throw $e;
            }
            // satu transaksi hanya boleh terhubung ke satu sumber (pemeriksaan lintas tabel; UNIQUE per tabel menjaga di tingkat DB)
            Db::insert($tbl, self::row($want[0], $want[1], $txId, $tx['tx_date'], $amt, $userId));
            if ($want[0] === 'invoice') {
                Db::q('UPDATE invoices SET paid = paid + ?, paid_date = CASE WHEN paid_date IS NULL OR paid_date < ? THEN ? ELSE paid_date END WHERE id = ?', [$amt, $tx['tx_date'], $tx['tx_date'], $want[1]]);
            }
            Audit::log('tx_link', 'transaction', (string) $txId, 'Transaksi #' . $txId . ' dihubungkan ke ' . self::name($want[0]) . ' #' . $want[1] . ' (' . $amt . ')', null, ['type' => $want[0], 'source_id' => $want[1], 'amount' => $amt]);
        } else {
            $old = $cur['amount'];
            $upd = ['amount' => $amt];
            if ($want[0] !== 'forecast') {
                $upd['pay_date'] = $tx['tx_date'];
            }
            Db::update($tbl, $cur['row'], $upd);
            if ($want[0] === 'invoice' && bccomp($old, $amt, 2) !== 0) {
                Db::q('UPDATE invoices SET paid = paid - ? + ? WHERE id = ?', [$old, $amt, $want[1]]);
            }
            if (bccomp($old, $amt, 2) !== 0) {
                Audit::log('tx_link', 'transaction', (string) $txId, 'Nominal tautan transaksi #' . $txId . ' ke ' . self::name($want[0]) . ' #' . $want[1] . ' diubah', ['amount' => $old], ['amount' => $amt]);
            }
        }
        self::afterChange($want[0], $want[1]);
    }

    private static function row(string $type, int $sid, int $txId, string $date, string $amt, int $userId): array
    {
        return match ($type) {
            'debt' => ['debt_id' => $sid, 'transaction_id' => $txId, 'pay_date' => $date, 'amount' => $amt, 'note' => 'Dicatat lewat Transaksi #' . $txId, 'created_by' => $userId],
            'invoice' => ['invoice_id' => $sid, 'transaction_id' => $txId, 'pay_date' => $date, 'amount' => $amt, 'created_by' => $userId],
            default => ['forecast_id' => $sid, 'transaction_id' => $txId, 'amount' => $amt, 'created_by' => $userId],
        };
    }

    /** Lepas tautan (baris tautan dihapus; pengaruhnya pada sisa/status dibatalkan). Transaksi itu sendiri TIDAK disentuh. */
    private static function detach(int $txId, array $cur, int $userId): void
    {
        [$tbl] = self::TABLES[$cur['type']];
        $txVoid = Db::val('SELECT voided_at FROM transactions WHERE id = ?', [$txId]) !== null;
        Db::q("DELETE FROM $tbl WHERE id = ?", [$cur['row']]);
        if ($cur['type'] === 'invoice' && !$txVoid) {
            Db::q('UPDATE invoices SET paid = GREATEST(paid - ?, 0) WHERE id = ?', [$cur['amount'], $cur['id']]);
        }
        Audit::log('tx_unlink', 'transaction', (string) $txId, 'Tautan transaksi #' . $txId . ' ke ' . self::name($cur['type']) . ' #' . $cur['id'] . ' dilepas', ['amount' => $cur['amount']], null);
        self::afterChange($cur['type'], $cur['id']);
    }

    /** Sumber ikut diperbarui: status Proyeksi tertaut, versi realtime (Dashboard/daftar memuat ulang). */
    private static function afterChange(string $type, int $id): void
    {
        if ($type === 'debt') {
            LinkService::syncDebt($id);
            Version::bump('debts');
        } elseif ($type === 'invoice') {
            LinkService::syncInvoice($id);
            Version::bump('invoices');
        } else {
            LinkService::syncRealization($id);
            Version::bump('forecast');
        }
    }

    /** Transaksi dibatalkan (void). Dipanggil setelah voided_at diisi, di dalam transaksi DB yang sama. */
    public static function onVoid(int $txId): void
    {
        $cur = self::linkOf($txId);
        if (!$cur) {
            return;
        }
        if ($cur['legacy']) {
            // data historis: lepas rujukan transaksi saja (nominal "dibayar" historis di Excel tidak diubah)
            Db::q('UPDATE invoices SET pay_tx_id = NULL WHERE pay_tx_id = ?', [$txId]);
            Audit::log('unlink', 'transaction', (string) $txId, 'Transaksi dibatalkan: rujukan Piutang historis dilepas');
            Version::bump('invoices');
            return;
        }
        if ($cur['type'] === 'invoice') {
            Db::q('UPDATE invoices SET paid = GREATEST(paid - ?, 0) WHERE id = ?', [$cur['amount'], $cur['id']]);
        }
        Audit::log('tx_link', 'transaction', (string) $txId, 'Transaksi dibatalkan: pembayaran/penerimaan ' . self::name($cur['type']) . ' #' . $cur['id'] . ' tidak lagi dihitung', ['amount' => $cur['amount']], null);
        self::afterChange($cur['type'], $cur['id']);
    }

    /** Transaksi dipulihkan: pembayaran dihitung lagi bila masih muat dalam sisa (kalau tidak, pemulihan ditolak). */
    public static function onRestore(int $txId): void
    {
        $cur = self::linkOf($txId);
        if (!$cur || $cur['legacy']) {
            return;
        }
        $src = self::source($cur['type'], $cur['id'], $txId, true);
        // piutang: paid tersimpan belum memuat penerimaan ini (dikurangkan saat void), jadi sisa = nominal − paid tersimpan
        $rem = !$src ? '0.00' : ($cur['type'] === 'invoice' ? bcsub($src['amount'], bcadd($src['paid'], (string) Db::val('SELECT amount FROM invoice_payments WHERE id = ?', [$cur['row']]), 2), 2) : $src['remaining']);
        if (!$src || bccomp($cur['amount'], $rem, 2) > 0) {
            throw new LinkError('Transaksi tidak dapat dipulihkan: nominalnya (' . money($cur['amount']) . ') kini melebihi sisa ' . self::name($cur['type']) . ' (' . money(bccomp($rem, '0', 2) > 0 ? $rem : '0') . ') karena sudah ada pembayaran lain. Ubah transaksi lain terlebih dahulu.');
        }
        if ($cur['type'] === 'invoice') {
            Db::q('UPDATE invoices SET paid = paid + ? WHERE id = ?', [$cur['amount'], $cur['id']]);
        }
        self::afterChange($cur['type'], $cur['id']);
    }

    /**
     * Semua item Proyeksi + realisasi (Σ transaksi sah) + 'eff' = nilai yang masih diproyeksikan (rencana − realisasi, min 0).
     * Realisasi sudah masuk saldo bank aktual, jadi hanya 'eff' yang dihitung ke saldo proyeksi (tidak ganda).
     */
    public static function forecastItems(): array
    {
        $rows = Db::all('SELECT f.*, COALESCE(r.realized, 0) AS realized,
                (EXISTS (SELECT 1 FROM debts d WHERE d.forecast_item_id = f.id) OR EXISTS (SELECT 1 FROM invoices i WHERE i.forecast_item_id = f.id)) AS linked
            FROM forecast_items f LEFT JOIN (' . self::FC_REAL_SUB . ') r ON r.forecast_id = f.id ORDER BY f.period_month, COALESCE(f.expected_date, "9999-12-31"), f.id');
        foreach ($rows as &$r) {
            $left = bcsub((string) $r['amount'], (string) $r['realized'], 2);
            $r['realized'] = bcadd((string) $r['realized'], '0', 2);
            $r['left'] = bccomp($left, '0', 2) > 0 ? $left : '0.00';
            $r['status'] = (int) $r['linked'] ? null : ForecastService::status($r, $r['realized']);
            // sisa yang diakhiri tidak lagi menjadi estimasi aktif: tidak dihitung ke saldo proyeksi (histori tetap tampil)
            $r['eff'] = $r['status'] === 'diakhiri' ? '0.00' : $r['left'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Bukti bank Piutang: transaksi Mutasi Masuk aktif yang No. invoice/PO-nya sama persis (tanpa beda huruf/spasi) dengan nomor invoice,
     * ditambah penerimaan yang dihubungkan lewat Transaksi. HANYA INFORMASI (dibaca langsung; tidak mengubah "dibayar" maupun saldo),
     * sehingga tidak ada penghitungan ganda. Dipakai Piutang Proyek agar "Terhubung" sesuai bukti bank seperti Piutang Lampu.
     * @param array<int,array> $invoices baris invoices (id, invoice_no)
     * @return array<int,array{list:array,sum:string}> id invoice => bukti
     */
    public static function bankProofs(array $invoices): array
    {
        $ids = [];
        foreach ($invoices as $i) {
            if (($i['invoice_no'] ?? '') !== '' && ($i['invoice_no'] ?? null) !== null) {
                $ids[] = (int) $i['id'];
            }
        }
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return self::groupProofs(Db::all(
            "SELECT DISTINCT i.id AS src, t.id, t.tx_date, t.voucher_code, t.debit AS amt, t.debit, a.name AS account
             FROM invoices i
             JOIN transactions t ON t.voided_at IS NULL AND t.debit <> 0
              AND ((t.ref_no IS NOT NULL AND t.ref_no <> '' AND LOWER(TRIM(t.ref_no)) = LOWER(TRIM(i.invoice_no)))
                   OR t.id IN (SELECT p.transaction_id FROM invoice_payments p WHERE p.invoice_id = i.id))
             JOIN accounts a ON a.id = t.account_id
             WHERE i.id IN ($in) ORDER BY t.tx_date, t.id",
            $ids
        ));
    }

    /**
     * Bukti bank Hutang (Mutasi Keluar / BK): transaksi aktif yang No. referensi-nya sama dengan referensi hutang,
     * ditambah pembayaran yang dihubungkan lewat Transaksi. HANYA INFORMASI (tidak mengubah "dibayar" maupun saldo).
     * @param array<int,array> $debts baris debts (id, ref_no)
     * @return array<int,array{list:array,sum:string}> id hutang => bukti
     */
    public static function debtProofs(array $debts): array
    {
        $ids = array_map(static fn($d) => (int) $d['id'], $debts);
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return self::groupProofs(Db::all(
            "SELECT DISTINCT d.id AS src, t.id, t.tx_date, t.voucher_code, t.credit AS amt, t.credit, a.name AS account
             FROM debts d
             JOIN transactions t ON t.voided_at IS NULL AND t.credit <> 0
              AND ((d.ref_no IS NOT NULL AND d.ref_no <> '' AND t.ref_no IS NOT NULL AND LOWER(TRIM(t.ref_no)) = LOWER(TRIM(d.ref_no)))
                   OR t.id IN (SELECT p.transaction_id FROM debt_payments p WHERE p.debt_id = d.id AND p.transaction_id IS NOT NULL))
             JOIN accounts a ON a.id = t.account_id
             WHERE d.id IN ($in) ORDER BY t.tx_date, t.id",
            $ids
        ));
    }

    /**
     * Penerimaan piutang yang dicatat lewat menu Transaksi (invoice_payments, transaksi aktif), satu entri per pembayaran,
     * untuk kolom "Tgl. Bayar"/"Kode Bukti"/"Dibayar" di cetak piutang.
     * @param int[] $ids id invoice
     * @return array<int,list<array{date:?string,code:string,amount:string}>>
     */
    public static function invoicePayments(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Db::all(
            'SELECT p.invoice_id, p.pay_date, p.amount, t.id, t.voucher_code FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id AND t.voided_at IS NULL
             WHERE p.invoice_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY p.pay_date, p.id',
            $ids
        ) as $p) {
            $out[(int) $p['invoice_id']][] = [
                'date' => $p['pay_date'],
                'code' => trim((string) $p['voucher_code']) !== '' ? trim((string) $p['voucher_code']) : '#' . $p['id'],
                'amount' => (string) $p['amount'],
            ];
        }
        return $out;
    }

    /** @return array<int,array{list:array,sum:string}> */
    private static function groupProofs(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (int) $r['src'];
            $out[$k]['list'][] = $r;
            $out[$k]['sum'] = bcadd($out[$k]['sum'] ?? '0', (string) $r['amt'], 2);
        }
        return $out;
    }

    /* ------------------------------------------------------------------ daftar pilihan (dropdown No. Invoice/PO) ------------------------------------------------------------------ */

    /**
     * @return array<int,array<string,mixed>> item pilihan; 'group' menandai jenis sumber (Piutang | Hutang | Proyeksi)
     */
    public static function options(string $dir, string $q, ?int $exceptTx, int $limit = 40): array
    {
        $ex = $exceptTx ?? 0;
        $q = mb_substr(trim($q), 0, 80);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $out = [];
        $own = $ex > 0 ? self::linkOf($ex) : null;
        $ownId = static fn(string $t) => $own && !$own['legacy'] && $own['type'] === $t ? $own['id'] : 0;

        if ($dir === 'in') {
            $w = 'i.paid_known = 1 AND (i.amount - i.paid + COALESCE((SELECT SUM(p.amount) FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id WHERE p.invoice_id = i.id AND p.transaction_id = ? AND t.voided_at IS NULL), 0) > 0.004 OR i.id = ?)';
            $p = [$ex, $ownId('invoice')];
            if ($q !== '') {
                $w .= ' AND (i.invoice_no LIKE ? OR i.customer LIKE ? OR i.description LIKE ? OR i.project_name LIKE ?)';
                array_push($p, $like, $like, $like, $like);
            }
            foreach (Db::all("SELECT i.id FROM invoices i WHERE $w ORDER BY (i.due_date IS NULL), i.due_date, i.id LIMIT $limit", $p) as $r) {
                if ($s = self::source('invoice', (int) $r['id'], $ex)) {
                    $out[] = self::opt($s, 'Piutang');
                }
            }
        } else {
            $w = 'd.voided_at IS NULL AND (d.amount - COALESCE((SELECT SUM(p.amount) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = d.id AND (p.transaction_id IS NULL OR (t.voided_at IS NULL AND p.transaction_id <> ?))), 0) > 0.004 OR d.id = ?)';
            $p = [$ex, $ownId('debt')];
            if ($q !== '') {
                $w .= ' AND (d.ref_no LIKE ? OR d.supplier LIKE ? OR d.description LIKE ?)';
                array_push($p, $like, $like, $like);
            }
            foreach (Db::all("SELECT d.id FROM debts d WHERE $w ORDER BY (d.due_date IS NULL), d.due_date, d.id LIMIT $limit", $p) as $r) {
                if ($s = self::source('debt', (int) $r['id'], $ex)) {
                    $out[] = self::opt($s, 'Hutang');
                }
            }
        }
        $w = 'f.direction = ? AND f.is_active = 1 AND f.id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL) AND f.id NOT IN (SELECT forecast_item_id FROM invoices WHERE forecast_item_id IS NOT NULL)';
        $p = [$dir];
        if ($q !== '') {
            $w .= ' AND f.description LIKE ?';
            $p[] = $like;
        }
        foreach (Db::all("SELECT f.id FROM forecast_items f WHERE $w ORDER BY (f.expected_date IS NULL), f.expected_date, f.id LIMIT 200", $p) as $r) {
            $s = self::source('forecast', (int) $r['id'], $ex);
            if ($s && !$s['block'] && (bccomp($s['remaining'], self::EPS, 3) > 0 || $ownId('forecast') === (int) $r['id'])) {
                $out[] = self::opt($s, 'Proyeksi');
                if (count(array_filter($out, static fn($o) => $o['group'] === 'Proyeksi')) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function opt(array $s, string $group): array
    {
        $rem = bccomp($s['remaining'], '0', 2) > 0 ? $s['remaining'] : '0.00';
        return [
            'value' => $s['type'] . ':' . $s['id'], 'group' => $group, 'tag' => $s['tag'] ?? $group, 'title' => $s['title'], 'party' => $s['party'], 'ref_no' => $s['ref_no'],
            'description' => $s['description'], 'amount' => $s['amount'], 'amount_fmt' => money($s['amount']), 'remaining' => $rem, 'remaining_fmt' => money($rem),
            'paid_fmt' => money($s['paid']), 'due' => $s['due'], 'due_fmt' => $s['due'] ? fmt_date($s['due']) : null, 'account_id' => $s['account_id'], 'direction' => $s['direction'],
        ];
    }

    /** Teks tampilan satu referensi untuk nilai awal dropdown (form ubah transaksi / tautan dari tombol Bayar/Terima/Realisasikan). */
    public static function label(string $type, int $id, ?int $exceptTx = null): ?array
    {
        $s = self::source($type, $id, $exceptTx);
        return $s ? self::opt($s, self::name($type)) + ['block' => $s['block']] : null;
    }
}
