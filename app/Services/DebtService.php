<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Hutang kepada supplier/vendor. BUKAN transaksi kas: tidak menyentuh saldo, laporan, maupun proyeksi.
 * Sisa & status selalu dihitung dari nominal − Σ pembayaran (tidak disimpan), sehingga pembayaran sebagian tidak pernah dianggap lunas.
 * Pembayaran dicatat SATU kali lewat menu Transaksi (Mutasi Keluar) dan ditautkan ke hutang (TxLinkService); menu Hutang tidak membuat transaksi
 * maupun pembayaran sendiri. Pembayaran yang transaksinya dibatalkan (void) tidak dihitung. Baris lama tanpa transaksi (impor Excel) tetap dihitung.
 */
final class DebtService
{
    public const PER_PAGE = 25;
    private const EPS = '0.004';

    /** Jenis hutang (kunci disimpan di debts.category). Hutang lama/impor tanpa pilihan = 'usaha'. */
    public const CATEGORIES = ['usaha' => 'Hutang Usaha', 'direksi' => 'Hutang Direksi', 'lain' => 'Hutang Lain-lain'];

    private const BASE = 'FROM debts d LEFT JOIN (' . TxLinkService::DEBT_PAID_SUB . ') p ON p.debt_id = d.id';

    /** @return array{where:string,params:array} */
    private static function where(array $f, string $today): array
    {
        $w = [];
        $p = [];
        $status = $f['status'] ?? '';
        $w[] = $status === 'batal' ? 'd.voided_at IS NOT NULL' : 'd.voided_at IS NULL';
        if (($f['category'] ?? '') !== '') {
            $w[] = 'd.category = ?';
            $p[] = $f['category'];
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $w[] = '(d.supplier LIKE ? OR d.description LIKE ? OR d.ref_no LIKE ? OR d.source_account LIKE ? OR d.note LIKE ? OR CAST(d.seq_no AS CHAR) = ?)';
            array_push($p, $like, $like, $like, $like, $like, $f['q']);
        }
        $rem = 'd.amount - COALESCE(p.paid, 0)';
        switch ($status) {
            case 'open':      $w[] = "$rem > " . self::EPS; break;
            case 'belum':     $w[] = "COALESCE(p.paid, 0) = 0 AND $rem > " . self::EPS; break;
            case 'sebagian':  $w[] = "COALESCE(p.paid, 0) > 0 AND $rem > " . self::EPS; break;
            case 'lunas':     $w[] = "$rem <= " . self::EPS; break;
            case 'terlambat': $w[] = "$rem > " . self::EPS . ' AND d.due_date IS NOT NULL AND d.due_date < ?'; $p[] = $today; break;
        }
        foreach (['due_from' => ['d.due_date', '>='], 'due_to' => ['d.due_date', '<='], 'date_from' => ['d.debt_date', '>='], 'date_to' => ['d.debt_date', '<=']] as $k => [$col, $op]) {
            if (!empty($f[$k])) {
                $w[] = "$col $op ?";
                $p[] = $f[$k];
            }
        }
        return ['where' => ' WHERE ' . implode(' AND ', $w), 'params' => $p];
    }

    /** @return array{rows:array,total:int,tot:array,pages:int,page:int} */
    public static function search(array $f, int $page, ?string $today = null): array
    {
        $today ??= ReminderService::today();
        $q = self::where($f, $today);
        $count = (int) Db::val('SELECT COUNT(*) ' . self::BASE . $q['where'], $q['params']);
        $pages = max(1, (int) ceil($count / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = Db::all(
            'SELECT d.*, COALESCE(p.paid, 0) AS paid, d.amount - COALESCE(p.paid, 0) AS remaining ' . self::BASE . $q['where']
            . ' ORDER BY (d.amount - COALESCE(p.paid, 0) <= ' . self::EPS . '), (d.due_date IS NULL), d.due_date, d.id LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $q['params']
        );
        $tot = Db::one(
            'SELECT COALESCE(SUM(d.amount), 0) AS a, COALESCE(SUM(COALESCE(p.paid, 0)), 0) AS p, COALESCE(SUM(GREATEST(d.amount - COALESCE(p.paid, 0), 0)), 0) AS o, '
            . 'COALESCE(SUM(CASE WHEN d.amount - COALESCE(p.paid, 0) > ' . self::EPS . ' AND d.due_date IS NOT NULL AND d.due_date < ? THEN d.amount - COALESCE(p.paid, 0) ELSE 0 END), 0) AS late_amt, '
            . 'COALESCE(SUM(d.amount - COALESCE(p.paid, 0) > ' . self::EPS . ' AND d.due_date IS NOT NULL AND d.due_date < ?), 0) AS late_n '
            . self::BASE . $q['where'],
            array_merge([$today, $today], $q['params'])
        );
        foreach ($rows as &$r) {
            $r = self::decorate($r, $today);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $count, 'tot' => $tot, 'pages' => $pages, 'page' => $page];
    }

    /** Semua hutang yang cocok dengan filter (tanpa halaman) untuk cetak, urut tanggal hutang lalu jatuh tempo. */
    public static function listAll(array $f, int $limit, ?string $today = null): array
    {
        $today ??= ReminderService::today();
        $q = self::where($f, $today);
        $rows = Db::all(
            'SELECT d.*, COALESCE(p.paid, 0) AS paid ' . self::BASE . $q['where']
            . ' ORDER BY (d.debt_date IS NULL), d.debt_date, (d.due_date IS NULL), d.due_date, d.seq_no, d.id LIMIT ' . ($limit + 1),
            $q['params']
        );
        return array_map(static fn($r) => self::decorate($r, $today), $rows);
    }

    /** Tambahkan status turunan: status (belum|sebagian|lunas), due (klasifikasi jatuh tempo bila masih ada sisa). */
    public static function decorate(array $r, string $today): array
    {
        $paid = (string) ($r['paid'] ?? '0');
        $rem = bcsub((string) $r['amount'], $paid, 2);
        $open = bccomp($rem, self::EPS, 3) > 0;
        $r['paid'] = bcadd($paid, '0', 2);
        $r['remaining'] = $open ? $rem : '0.00';
        $r['status'] = !$open ? 'lunas' : (bccomp($paid, '0', 2) > 0 ? 'sebagian' : 'belum');
        $r['due'] = null;
        if ($open && !empty($r['due_date'])) {
            $left = ReminderService::daysLeft((string) $r['due_date'], $today);
            $r['due'] = ReminderService::classify($left) + ['days_left' => $left];
        }
        return $r;
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT d.*, COALESCE(p.paid, 0) AS paid ' . self::BASE . ' WHERE d.id = ?', [$id]);
        return $r ? self::decorate($r, ReminderService::today()) : null;
    }

    public static function payments(int $debtId): array
    {
        return Db::all(
            'SELECT pm.*, u.full_name AS by_name, t.tx_date AS tx_date, t.voucher_code AS tx_voucher, t.voided_at AS tx_void, a.name AS tx_account FROM debt_payments pm
             LEFT JOIN users u ON u.id = pm.created_by LEFT JOIN transactions t ON t.id = pm.transaction_id LEFT JOIN accounts a ON a.id = t.account_id
             WHERE pm.debt_id = ? ORDER BY (pm.pay_date IS NULL), pm.pay_date, pm.id',
            [$debtId]
        );
    }

    /** Item Proyeksi pengeluaran yang SAMA PERSIS (tanggal, nominal, uraian) dan belum ditautkan: hanya bila tepat satu. */
    private static function matchForecast(array $d): ?int
    {
        if (empty($d['due_date']) || trim((string) ($d['description'] ?? '')) === '') {
            return null;
        }
        $c = Db::all(
            "SELECT id FROM forecast_items WHERE direction = 'out' AND is_active = 1 AND paid_at IS NULL AND expected_date = ? AND amount = ?
             AND LOWER(TRIM(description)) = LOWER(TRIM(?)) AND id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL) AND id NOT IN (SELECT forecast_item_id FROM invoices WHERE forecast_item_id IS NOT NULL)",
            [$d['due_date'], $d['amount'], $d['description']]
        );
        return count($c) === 1 ? (int) $c[0]['id'] : null;
    }

    /**
     * Buat hutang (+ pembayaran awal bila 'paid' > 0). Dipakai formulir manual dan impor.
     * Untuk impor ($batchId != null) audit & versi dicatat sekali oleh pemanggil.
     * @return array{id:int,linked:bool,payment:bool}
     */
    public static function create(array $d, int $userId, ?int $batchId = null): array
    {
        return Db::tx(function () use ($d, $userId, $batchId) {
            $link = self::matchForecast($d);
            // No. urut hanya penomoran tampilan: otomatis (nomor berikutnya) kecuali impor membawa nomor dari Excel
            $seq = $d['seq_no'] ?? (int) Db::val('SELECT COALESCE(MAX(seq_no), 0) + 1 FROM debts');
            $supplierId = $d['supplier_id'] ?? self::supplierId($d['supplier']);
            $id = Db::insert('debts', [
                'category' => isset(self::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'usaha',
                'seq_no' => $seq, 'ref_no' => ($d['ref_no'] ?? '') !== '' ? $d['ref_no'] : null, 'supplier' => $d['supplier'], 'supplier_id' => $supplierId,
                'source_account_id' => $d['source_account_id'] ?? null,
                'description' => ($d['description'] ?? '') !== '' ? $d['description'] : null, 'debt_date' => $d['debt_date'] ?? null, 'due_date' => $d['due_date'] ?? null,
                'source_account' => ($d['source_account'] ?? '') !== '' ? $d['source_account'] : null, 'amount' => $d['amount'],
                'note' => ($d['note'] ?? '') !== '' ? $d['note'] : null, 'forecast_item_id' => $link,
                'source_sheet' => $d['source_sheet'] ?? null, 'source_row' => $d['source_row'] ?? null, 'import_batch_id' => $batchId, 'created_by' => $userId,
            ]);
            $pay = false;
            if (isset($d['paid']) && bccomp((string) $d['paid'], '0', 2) > 0) {
                Db::insert('debt_payments', ['debt_id' => $id, 'pay_date' => null, 'amount' => $d['paid'], 'note' => 'Nominal dibayar dari kolom Excel (tanggal tidak tersedia)', 'import_batch_id' => $batchId, 'created_by' => $userId]);
                $pay = true;
            }
            LinkService::syncDebt($id);
            if ($batchId === null) {
                Audit::log('debt_create', 'debt', (string) $id, 'Tambah hutang: ' . $d['supplier'] . ' ' . $d['amount'], null, $d);
                Version::bump('debts');
            }
            return ['id' => $id, 'linked' => $link !== null, 'payment' => $pay];
        });
    }

    /** ID master supplier menurut nama persis; dibuat bila belum ada (nama dari Excel/Proyeksi tetap muncul di dropdown). */
    public static function supplierId(string $name): ?int
    {
        $name = mb_substr(trim($name), 0, 160);
        if ($name === '') {
            return null;
        }
        $id = Db::val('SELECT id FROM suppliers WHERE name = ?', [$name]);
        return $id ? (int) $id : Db::insert('suppliers', ['name' => $name]);
    }

    /** @return array{0:array,1:array} [data bersih, galat per field] */
    public static function validate(array $in, ?array $existing = null): array
    {
        $e = [];
        $d = [];
        // tidak diisi (mis. klien lama) = pertahankan jenis yang ada / 'usaha'; nilai di luar daftar ditolak
        $cat = (string) ($in['category'] ?? '');
        if ($cat === '') {
            $cat = (string) ($existing['category'] ?? 'usaha');
        } elseif (!isset(self::CATEGORIES[$cat])) {
            $e['category'] = 'Pilih jenis hutang dari daftar.';
            $cat = (string) ($existing['category'] ?? 'usaha');
        }
        $d['category'] = $cat;
        // supplier = pilihan dari master (supplier_id); nama disalin ke kolom teks untuk tampilan/ekspor
        $sid = (int) ($in['supplier_id'] ?? 0);
        $sup = $sid > 0 ? Db::one('SELECT id, name, is_active FROM suppliers WHERE id = ?', [$sid]) : null;
        if ($sup && !$sup['is_active'] && (int) ($existing['supplier_id'] ?? 0) !== $sid) {
            $sup = null;
        }
        $d['supplier_id'] = $sup ? (int) $sup['id'] : null;
        $d['supplier'] = $sup ? (string) $sup['name'] : '';
        if (!$sup) {
            $e['supplier'] = 'Pilih supplier dari daftar (tambahkan di Master Data → Supplier bila belum ada).';
        }
        $d['ref_no'] = mb_substr(trim((string) ($in['ref_no'] ?? '')), 0, 80);
        $d['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, 500);
        // rekening sumber pembayaran = rencana (opsional); rekening yang benar-benar dipakai dikonfirmasi di form Transaksi saat membayar
        $aid = (int) ($in['source_account_id'] ?? 0);
        $acc = $aid > 0 ? Db::one('SELECT id, name, is_active FROM accounts WHERE id = ?', [$aid]) : null;
        if ($aid > 0 && (!$acc || (!$acc['is_active'] && (int) ($existing['source_account_id'] ?? 0) !== $aid))) {
            $e['source_account_id'] = 'Pilih rekening dari daftar.';
            $acc = null;
        }
        $d['source_account_id'] = $acc ? (int) $acc['id'] : null;
        // tanpa pilihan: teks lama (data Excel) dipertahankan bila belum pernah dipetakan ke rekening
        $d['source_account'] = $acc ? mb_substr((string) $acc['name'], 0, 80) : ($existing && empty($existing['source_account_id']) ? (string) ($existing['source_account'] ?? '') : '');
        $d['note'] = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);
        foreach (['due_date' => 'Tanggal jatuh tempo', 'debt_date' => 'Tanggal transaksi'] as $k => $label) {
            $raw = trim((string) ($in[$k] ?? ''));
            $d[$k] = $raw === '' ? null : Filters::date($raw);
            if ($raw !== '' && $d[$k] === null) {
                $e[$k] = $label . ' tidak valid.';
            }
        }
        $amt = parse_amount($in['amount'] ?? '');
        if ($amt === null || bccomp($amt, '0', 2) <= 0) {
            $e['amount'] = 'Nominal hutang harus berupa angka lebih dari nol.';
            $amt = '0.00';
        }
        $d['amount'] = $amt;
        if ($existing !== null && $amt !== '0.00' && bccomp($amt, (string) $existing['paid'], 2) < 0) {
            $e['amount'] = 'Nominal tidak boleh lebih kecil dari total yang sudah dibayar (' . money($existing['paid']) . ').';
        }
        $d['paid'] = '0.00';   // pembayaran tidak diisi lewat formulir hutang: catat lewat menu Transaksi
        return [$d, $e];
    }

    public static function update(int $id, array $d, int $userId): void
    {
        Db::tx(function () use ($id, $d, $userId) {
            $old = Db::one('SELECT * FROM debts WHERE id = ? AND voided_at IS NULL FOR UPDATE', [$id]);
            if (!$old) {
                throw new RuntimeException('Hutang tidak ditemukan atau sudah dibatalkan.');
            }
            $paid = (string) Db::val('SELECT COALESCE(SUM(p.amount), 0) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = ? AND (p.transaction_id IS NULL OR t.voided_at IS NULL)', [$id]);
            if (bccomp($d['amount'], $paid, 2) < 0) {
                throw new RuntimeException('Nominal tidak boleh lebih kecil dari total yang sudah dibayar (' . money($paid) . ').');
            }
            $set = [];
            foreach (['category', 'ref_no', 'supplier', 'supplier_id', 'description', 'debt_date', 'due_date', 'source_account', 'source_account_id', 'amount', 'note'] as $k) {
                $v = $d[$k] ?? null;
                $set[$k] = ($v === '' ? null : $v);
            }
            $set['updated_by'] = $userId;
            Db::update('debts', $id, $set);
            Audit::log('debt_update', 'debt', (string) $id, 'Ubah hutang: ' . $d['supplier'], array_intersect_key($old, $set), $set);
            LinkService::syncDebt($id);
            Version::bump('debts');
        });
    }

    public static function void(int $id, int $userId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }
        Db::tx(function () use ($id, $userId, $reason) {
            $old = Db::one('SELECT * FROM debts WHERE id = ? FOR UPDATE', [$id]);
            if (!$old || $old['voided_at'] !== null) {
                throw new RuntimeException('Hutang tidak ditemukan atau sudah dibatalkan.');
            }
            Db::update('debts', $id, ['voided_at' => date('Y-m-d H:i:s'), 'voided_by' => $userId, 'void_reason' => $reason]);
            Audit::log('debt_void', 'debt', (string) $id, 'Batalkan hutang: ' . $old['supplier'] . ' — ' . $reason, ['voided' => false], ['voided' => true, 'reason' => $reason]);
            LinkService::syncDebt($id);
            Version::bump('debts');
        });
    }

    public static function restore(int $id, int $userId): void
    {
        Db::tx(function () use ($id, $userId) {
            $old = Db::one('SELECT * FROM debts WHERE id = ? FOR UPDATE', [$id]);
            if (!$old || $old['voided_at'] === null) {
                throw new RuntimeException('Hutang tidak ditemukan atau tidak dalam keadaan dibatalkan.');
            }
            Db::update('debts', $id, ['voided_at' => null, 'voided_by' => null, 'void_reason' => null, 'updated_by' => $userId]);
            Audit::log('debt_restore', 'debt', (string) $id, 'Pulihkan hutang: ' . $old['supplier'], ['voided' => true], ['voided' => false]);
            LinkService::syncDebt($id);
            Version::bump('debts');
        });
    }

    public static function deletePayment(int $debtId, int $payId): void
    {
        Db::tx(function () use ($debtId, $payId) {
            $debt = Db::one('SELECT * FROM debts WHERE id = ? AND voided_at IS NULL FOR UPDATE', [$debtId]);
            $p = Db::one('SELECT * FROM debt_payments WHERE id = ? AND debt_id = ?', [$payId, $debtId]);
            if (!$debt || !$p) {
                throw new RuntimeException('Pembayaran tidak ditemukan.');
            }
            if ($p['transaction_id'] !== null) {
                throw new RuntimeException('Pembayaran ini berasal dari Transaksi #' . $p['transaction_id'] . '. Ubah atau batalkan (void) transaksinya di menu Transaksi.');
            }
            Db::q('DELETE FROM debt_payments WHERE id = ?', [$payId]);
            Audit::log('debt_pay_delete', 'debt', (string) $debtId, 'Hapus catatan pembayaran hutang ' . $debt['supplier'] . ': ' . $p['amount'], $p, null);
            LinkService::syncDebt($debtId);
            Version::bump('debts');
        });
    }
}
