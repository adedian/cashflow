<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use RuntimeException;

/**
 * Keterhubungan antar modul — satu kebenaran, tanpa penghitungan ganda:
 *   Hutang (debts)      <-> item Proyeksi pengeluaran  (debts.forecast_item_id)
 *   Piutang (invoices)  <-> item Proyeksi penerimaan   (invoices.forecast_item_id)
 *   Piutang (invoices)  ->  Transaksi Mutasi Masuk     (invoices.pay_tx_id, lewat kode bukti BM yang sama)
 *   Hutang  (debt_payments) -> Transaksi Mutasi Keluar (debt_payments.transaction_id)
 * Item Proyeksi tertaut MENGIKUTI pasangannya otomatis (nominal = sisa, tanggal = jatuh tempo, status dibayar/diterima = lunas); tidak ada tombol sinkron.
 * Tautan tidak pernah mengubah saldo/transaksi. Hutang/Piutang tetap sumber kebenaran; item Proyeksi tertaut dikunci dari ubah manual nominal/tanggal.
 */
final class LinkService
{
    private const EPS = '0.004';

    private static function actor(): ?int
    {
        $u = Auth::user();
        return $u ? (int) $u['id'] : null;
    }

    /* ------------------------------------------------------------------ sinkron status ------------------------------------------------------------------ */

    /**
     * Item Proyeksi yang tertaut MENGIKUTI Hutang secara otomatis (tanpa tombol sinkron):
     *   nominal = sisa hutang (nominal − Σ pembayaran; 0 bila lunas) · tanggal = jatuh tempo (bila ada) · paid_at = lunas.
     * Hutang dibatalkan: status dibuka lagi, nominal/tanggal Proyeksi dibiarkan pada nilai terakhir.
     * Dipanggil di dalam transaksi yang sama dengan setiap perubahan hutang/pembayaran.
     */
    public static function syncDebt(int $debtId): void
    {
        $d = Db::one('SELECT d.id, d.supplier, d.amount, d.due_date, d.voided_at, d.forecast_item_id, COALESCE((SELECT SUM(p.amount) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = d.id AND (p.transaction_id IS NULL OR t.voided_at IS NULL)), 0) AS paid FROM debts d WHERE d.id = ?', [$debtId]);
        if (!$d || !$d['forecast_item_id']) {
            return;
        }
        $rem = bcsub((string) $d['amount'], (string) $d['paid'], 2);
        $rem = bccomp($rem, self::EPS, 3) > 0 ? $rem : '0.00';
        $active = $d['voided_at'] === null;
        self::applySync((int) $d['forecast_item_id'], 'Hutang ' . $d['supplier'], $active && $rem === '0.00', $active ? $rem : null, $active ? $d['due_date'] : null);
    }

    /** Item Proyeksi penerimaan mengikuti Piutang: nominal = sisa (hanya bila pembayaran pasti), tanggal = jatuh tempo bila ada, paid_at = lunas & pasti. */
    public static function syncInvoice(int $invoiceId): void
    {
        $i = Db::one('SELECT id, customer, amount, paid, paid_known, due_date, forecast_item_id FROM invoices WHERE id = ?', [$invoiceId]);
        if (!$i || !$i['forecast_item_id']) {
            return;
        }
        $known = (int) $i['paid_known'] === 1;
        $rem = bcsub((string) $i['amount'], (string) $i['paid'], 2);
        $rem = bccomp($rem, self::EPS, 3) > 0 ? $rem : '0.00';
        self::applySync((int) $i['forecast_item_id'], 'Piutang ' . $i['customer'], $known && $rem === '0.00', $known ? $rem : null, $i['due_date']);
    }

    /** Sinkron ulang satu item Proyeksi dari pasangannya (dipakai setelah item Proyeksi diubah manual). */
    public static function syncForecast(int $fid): void
    {
        if ($id = Db::val('SELECT id FROM debts WHERE forecast_item_id = ?', [$fid])) {
            self::syncDebt((int) $id);
        } elseif ($id = Db::val('SELECT id FROM invoices WHERE forecast_item_id = ?', [$fid])) {
            self::syncInvoice((int) $id);
        } else {
            self::syncRealization($fid);
        }
    }

    /**
     * Item Proyeksi berdiri sendiri yang direalisasikan lewat Transaksi: nominal rencana tetap; paid_at terisi bila realisasi ≥ rencana
     * (hanya bila sudah ada realisasi — tanda manual lama tidak disentuh), kosong lagi bila realisasi berkurang/dibatalkan.
     */
    public static function syncRealization(int $fid): void
    {
        $f = Db::one('SELECT id, amount, paid_at FROM forecast_items WHERE id = ? FOR UPDATE', [$fid]);
        if (!$f || !Db::val('SELECT id FROM forecast_realizations WHERE forecast_id = ? LIMIT 1', [$fid])) {
            return;
        }
        $real = (string) Db::val('SELECT COALESCE(SUM(r.amount), 0) FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id WHERE r.forecast_id = ? AND t.voided_at IS NULL', [$fid]);
        $settled = bccomp((string) $f['amount'], '0', 2) > 0 && bccomp(bcsub((string) $f['amount'], $real, 2), self::EPS, 3) <= 0;
        if ($settled === ($f['paid_at'] !== null)) {
            return;
        }
        $upd = $settled ? ['paid_at' => date('Y-m-d H:i:s'), 'paid_by' => self::actor()] : ['paid_at' => null, 'paid_by' => null];
        Db::update('forecast_items', $fid, $upd);
        Audit::log('forecast_sync', 'forecast', (string) $fid, 'Proyeksi ' . ($settled ? 'terealisasi penuh' : 'realisasi berkurang') . ' (dari Transaksi)', array_intersect_key($f, $upd), $upd);
        Version::bump('forecast');
    }

    private static function applySync(int $fid, string $who, bool $settled, ?string $remaining, ?string $due): void
    {
        $f = Db::one('SELECT id, amount, expected_date, period_month, paid_at FROM forecast_items WHERE id = ? FOR UPDATE', [$fid]);
        if (!$f) {
            return;
        }
        $upd = [];
        if ($settled !== ($f['paid_at'] !== null)) {
            $upd += $settled ? ['paid_at' => date('Y-m-d H:i:s'), 'paid_by' => self::actor()] : ['paid_at' => null, 'paid_by' => null];
        }
        if ($remaining !== null && bccomp($remaining, (string) $f['amount'], 2) !== 0) {
            $upd['amount'] = $remaining;
        }
        if ($due !== null && $due !== $f['expected_date']) {
            $upd['expected_date'] = $due;
            $upd['period_month'] = substr($due, 0, 7);
        }
        if (!$upd) {
            return;
        }
        Db::update('forecast_items', $fid, $upd);
        Audit::log('forecast_sync', 'forecast', (string) $fid, 'Proyeksi otomatis mengikuti ' . $who, array_intersect_key($f, $upd), $upd);
        Version::bump('forecast');
    }
    /* ------------------------------------------------------------------ tautkan / lepas ------------------------------------------------------------------ */

    private static function forecastFree(int $fid, string $dir): array
    {
        $f = Db::one('SELECT * FROM forecast_items WHERE id = ? FOR UPDATE', [$fid]);
        if (!$f) {
            throw new RuntimeException('Item Proyeksi tidak ditemukan.');
        }
        if ($f['direction'] !== $dir) {
            throw new RuntimeException($dir === 'out' ? 'Hutang hanya dapat ditautkan ke item Proyeksi berjenis Pengeluaran.' : 'Piutang hanya dapat ditautkan ke item Proyeksi berjenis Penerimaan.');
        }
        if (Db::val('SELECT id FROM debts WHERE forecast_item_id = ?', [$fid]) || Db::val('SELECT id FROM invoices WHERE forecast_item_id = ?', [$fid])) {
            throw new RuntimeException('Item Proyeksi ini sudah tertaut ke hutang/piutang lain.');
        }
        return $f;
    }

    public static function linkDebt(int $debtId, int $fid): void
    {
        Db::tx(static function () use ($debtId, $fid) {
            $d = Db::one('SELECT * FROM debts WHERE id = ? FOR UPDATE', [$debtId]);
            if (!$d || $d['voided_at'] !== null) {
                throw new RuntimeException('Hutang tidak ditemukan atau sudah dibatalkan.');
            }
            if ($d['forecast_item_id']) {
                throw new RuntimeException('Hutang ini sudah tertaut ke item Proyeksi. Lepas tautan lebih dulu.');
            }
            self::forecastFree($fid, 'out');
            Db::update('debts', $debtId, ['forecast_item_id' => $fid]);
            Audit::log('link', 'debt', (string) $debtId, 'Tautkan hutang ' . $d['supplier'] . ' ke item Proyeksi #' . $fid, null, ['forecast_item_id' => $fid]);
            self::syncDebt($debtId);
            Version::bump('debts');
            Version::bump('forecast');
        });
    }

    public static function unlinkDebt(int $debtId): void
    {
        Db::tx(static function () use ($debtId) {
            $d = Db::one('SELECT * FROM debts WHERE id = ? FOR UPDATE', [$debtId]);
            if (!$d || !$d['forecast_item_id']) {
                throw new RuntimeException('Hutang ini tidak tertaut ke item Proyeksi.');
            }
            Db::update('debts', $debtId, ['forecast_item_id' => null]);
            Audit::log('unlink', 'debt', (string) $debtId, 'Lepas tautan hutang ' . $d['supplier'] . ' dari item Proyeksi #' . $d['forecast_item_id'], ['forecast_item_id' => $d['forecast_item_id']], null);
            Version::bump('debts');
            Version::bump('forecast');
        });
    }

    public static function linkInvoice(int $invoiceId, int $fid): void
    {
        Db::tx(static function () use ($invoiceId, $fid) {
            $i = Db::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
            if (!$i) {
                throw new RuntimeException('Piutang tidak ditemukan.');
            }
            if ($i['forecast_item_id']) {
                throw new RuntimeException('Piutang ini sudah tertaut ke item Proyeksi. Lepas tautan lebih dulu.');
            }
            self::forecastFree($fid, 'in');
            Db::update('invoices', $invoiceId, ['forecast_item_id' => $fid]);
            Audit::log('link', 'invoice', (string) $invoiceId, 'Tautkan piutang ' . $i['customer'] . ' ke item Proyeksi #' . $fid, null, ['forecast_item_id' => $fid]);
            self::syncInvoice($invoiceId);
            Version::bump('invoices');
            Version::bump('forecast');
        });
    }

    public static function unlinkInvoice(int $invoiceId): void
    {
        Db::tx(static function () use ($invoiceId) {
            $i = Db::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
            if (!$i || !$i['forecast_item_id']) {
                throw new RuntimeException('Piutang ini tidak tertaut ke item Proyeksi.');
            }
            Db::update('invoices', $invoiceId, ['forecast_item_id' => null]);
            Audit::log('unlink', 'invoice', (string) $invoiceId, 'Lepas tautan piutang ' . $i['customer'] . ' dari item Proyeksi #' . $i['forecast_item_id'], ['forecast_item_id' => $i['forecast_item_id']], null);
            Version::bump('invoices');
            Version::bump('forecast');
        });
    }

    /** Buat Hutang dari item Proyeksi pengeluaran (nama supplier wajib: Proyeksi tidak memuatnya) lalu tautkan. */
    public static function debtFromForecast(int $fid, string $supplier, int $userId, string $category = 'usaha'): int
    {
        $supplier = mb_substr(trim($supplier), 0, 160);
        if ($supplier === '') {
            throw new RuntimeException('Nama supplier wajib diisi.');
        }
        $category = isset(DebtService::CATEGORIES[$category]) ? $category : 'usaha';
        return (int) Db::tx(static function () use ($fid, $supplier, $userId, $category) {
            $f = self::forecastFree($fid, 'out');
            $res = DebtService::create([
                'category' => $category, 'supplier' => $supplier, 'description' => mb_substr((string) $f['description'], 0, 500), 'due_date' => $f['expected_date'],
                'amount' => (string) $f['amount'], 'source_account' => mb_substr((string) ($f['account_hint'] ?? ''), 0, 80), 'ref_no' => '', 'note' => '',
                'seq_no' => null, 'debt_date' => null, 'paid' => '0.00',
            ], $userId);
            Db::q('UPDATE debts SET forecast_item_id = ? WHERE id = ?', [$fid, $res['id']]);
            Audit::log('link', 'debt', (string) $res['id'], 'Hutang dibuat dari item Proyeksi #' . $fid, null, ['forecast_item_id' => $fid]);
            self::syncDebt($res['id']);
            Version::bump('forecast');
            return $res['id'];
        });
    }

    /* ------------------------------------------------------------------ informasi tautan ------------------------------------------------------------------ */

    /**
     * @param int[] $fids
     * @return array<int,array{type:string,id:int,label:string,href:string,remaining:string,amount:string,due:?string,status:string,differs:bool,forecast_amount:string}>
     */
    public static function info(array $fids): array
    {
        $fids = array_values(array_filter(array_map('intval', $fids)));
        if (!$fids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($fids), '?'));
        $out = [];
        foreach (Db::all(
            "SELECT d.id, d.category, d.supplier, d.amount, d.due_date, d.voided_at, d.forecast_item_id AS fid, f.amount AS fa, f.expected_date AS fd, COALESCE((SELECT SUM(p.amount) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = d.id AND (p.transaction_id IS NULL OR t.voided_at IS NULL)), 0) AS paid
             FROM debts d JOIN forecast_items f ON f.id = d.forecast_item_id WHERE d.forecast_item_id IN ($in)",
            $fids
        ) as $r) {
            $rem = bcsub((string) $r['amount'], (string) $r['paid'], 2);
            $open = bccomp($rem, self::EPS, 3) > 0;
            $rem = $open ? $rem : '0.00';
            $out[(int) $r['fid']] = [
                'type' => 'debt', 'id' => (int) $r['id'], 'label' => (DebtService::CATEGORIES[$r['category']] ?? 'Hutang') . ' ' . $r['supplier'], 'href' => '/debts/' . (int) $r['id'], 'remaining' => $rem,
                'amount' => (string) $r['amount'], 'due' => $r['due_date'], 'forecast_amount' => (string) $r['fa'],
                'status' => $r['voided_at'] !== null ? 'batal' : (!$open ? 'lunas' : (bccomp((string) $r['paid'], '0', 2) > 0 ? 'sebagian' : 'belum')),
                'differs' => $r['voided_at'] === null && $open && (bccomp($rem, (string) $r['fa'], 2) !== 0 || ($r['due_date'] !== null && $r['due_date'] !== $r['fd'])),
            ];
        }
        foreach (Db::all(
            "SELECT i.id, i.customer, i.kind, i.amount, i.paid, i.paid_known, i.due_date, i.forecast_item_id AS fid, f.amount AS fa
             FROM invoices i JOIN forecast_items f ON f.id = i.forecast_item_id WHERE i.forecast_item_id IN ($in)",
            $fids
        ) as $r) {
            $known = (int) $r['paid_known'] === 1;
            $rem = bcsub((string) $r['amount'], (string) $r['paid'], 2);
            $open = bccomp($rem, self::EPS, 3) > 0;
            $rem = $open ? $rem : '0.00';
            $out[(int) $r['fid']] = [
                'type' => 'invoice', 'id' => (int) $r['id'], 'label' => 'Piutang ' . $r['customer'], 'href' => '/invoices/' . (int) $r['id'], 'remaining' => $known ? $rem : (string) $r['amount'],
                'amount' => (string) $r['amount'], 'due' => $r['due_date'], 'forecast_amount' => (string) $r['fa'],
                'status' => !$known ? 'konfirmasi' : (!$open ? 'lunas' : (bccomp((string) $r['paid'], '0', 2) > 0 ? 'sebagian' : 'belum')),
                'differs' => $known && $open && bccomp($rem, (string) $r['fa'], 2) !== 0 && abs((float) bcsub($rem, (string) $r['fa'], 2)) > 1.0,
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ penautan otomatis (hanya kecocokan pasti) ------------------------------------------------------------------ */

    /**
     * Cari pasangan yang PASTI: hutang <-> item Proyeksi (tanggal, nominal, uraian sama persis), piutang <-> item Proyeksi
     * (nomor invoice tertulis di uraian + sisa piutang sama dalam Rp 1). Kandidat ganda / ambigu dilewati, tidak ditebak.
     * @return array{debts:array,invoices:array,skipped:array,applied:int}
     */
    public static function autoMatch(bool $apply): array
    {
        $res = ['debts' => [], 'invoices' => [], 'skipped' => [], 'applied' => 0];
        $claimed = [];
        $free = static function (string $dir) {
            return Db::all(
                "SELECT f.* FROM forecast_items f WHERE f.direction = ? AND f.id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL)
                 AND f.id NOT IN (SELECT forecast_item_id FROM invoices WHERE forecast_item_id IS NOT NULL)",
                [$dir]
            );
        };
        $fOut = $free('out');
        foreach (Db::all('SELECT * FROM debts WHERE voided_at IS NULL AND forecast_item_id IS NULL AND due_date IS NOT NULL ORDER BY id') as $d) {
            $c = array_values(array_filter($fOut, static fn($f) => $f['expected_date'] === $d['due_date']
                && mb_strtolower(trim((string) $f['description'])) === mb_strtolower(trim((string) $d['description'])) && trim((string) $d['description']) !== '' && !isset($claimed[$f['id']])));
            if (count($c) === 1) {
                $claimed[$c[0]['id']] = true;
                $res['debts'][] = ['debt' => (int) $d['id'], 'forecast' => (int) $c[0]['id'], 'label' => $d['supplier'] . ' ↔ ' . $c[0]['description']];
            } elseif (count($c) > 1) {
                $res['skipped'][] = 'Hutang #' . $d['id'] . ' cocok dengan ' . count($c) . ' item Proyeksi (ambigu).';
            }
        }
        $fIn = $free('in');
        foreach (Db::all("SELECT * FROM invoices WHERE forecast_item_id IS NULL AND paid_known = 1 AND invoice_no IS NOT NULL AND CHAR_LENGTH(invoice_no) >= 4 AND amount - paid > 0.004 ORDER BY id") as $i) {
            $no = mb_strtolower(trim((string) $i['invoice_no']));
            $tok = mb_strtolower(explode('/', $no)[0]);   // nomor urut tanpa bulan/tahun romawi (Excel kadang tak konsisten: VII vs VIII)
            $c = array_values(array_filter($fIn, static function ($f) use ($no, $tok, $claimed) {
                $d = mb_strtolower((string) $f['description']);
                return (str_contains($d, $no) || (mb_strlen($tok) >= 3 && str_contains($d, $tok . '/'))) && !isset($claimed['i' . $f['id']]);
            }));
            if (count($c) === 1) {
                $claimed['i' . $c[0]['id']] = true;
                $res['invoices'][] = ['invoice' => (int) $i['id'], 'forecast' => (int) $c[0]['id'], 'label' => $i['customer'] . ' ' . $i['invoice_no'] . ' ↔ ' . mb_substr((string) $c[0]['description'], 0, 60)];
            } elseif (count($c) > 1) {
                $res['skipped'][] = 'Piutang ' . $i['invoice_no'] . ' cocok dengan ' . count($c) . ' item Proyeksi (ambigu).';
            }
        }
        if ($apply) {
            foreach ($res['debts'] as $m) {
                self::linkDebt($m['debt'], $m['forecast']);
                $res['applied']++;
            }
            foreach ($res['invoices'] as $m) {
                self::linkInvoice($m['invoice'], $m['forecast']);
                $res['applied']++;
            }
        }
        return $res;
    }

    /**
     * Hutang baru yang BELUM punya item Proyeksi (tak ada kecocokan pasti) dibuatkan item Proyeksi pengeluaran dan langsung ditautkan,
     * sehingga hutang selalu muncul di Proyeksi. Syarat: jatuh tempo ada (tidak dikarang), masih ada sisa, tidak dibatalkan, belum tertaut.
     * Nominal item = sisa hutang; setelah itu mengikuti hutang secara otomatis. @return ?int id item Proyeksi yang dibuat
     */
    public static function ensureForecastForDebt(int $debtId): ?int
    {
        try {
            return Db::tx(static function () use ($debtId) {
                $d = Db::one('SELECT d.*, COALESCE((SELECT SUM(p.amount) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = d.id AND (p.transaction_id IS NULL OR t.voided_at IS NULL)), 0) AS paid FROM debts d WHERE d.id = ? FOR UPDATE', [$debtId]);
                if (!$d || $d['forecast_item_id'] || $d['voided_at'] !== null || !$d['due_date']) {
                    return null;
                }
                $rem = bcsub((string) $d['amount'], (string) $d['paid'], 2);
                if (bccomp($rem, self::EPS, 3) <= 0) {
                    return null;
                }
                $desc = mb_substr(trim($d['supplier'] . (($d['description'] ?? '') !== '' ? ' — ' . $d['description'] : '')), 0, 500);
                $fid = Db::insert('forecast_items', [
                    'period_month' => substr((string) $d['due_date'], 0, 7), 'expected_date' => $d['due_date'], 'direction' => 'out', 'description' => $desc,
                    'account_hint' => ($d['source_account'] ?? '') !== '' ? mb_substr((string) $d['source_account'], 0, 80) : null, 'amount' => $rem, 'is_active' => 1, 'created_by' => self::actor(),
                ]);
                Db::update('debts', $debtId, ['forecast_item_id' => $fid]);
                Audit::log('forecast_create', 'forecast', (string) $fid, 'Item Proyeksi dibuat otomatis dari hutang ' . $d['supplier'] . ' #' . $debtId, null, ['amount' => $rem, 'expected_date' => $d['due_date']]);
                Version::bump('forecast');
                Version::bump('debts');
                return $fid;
            });
        } catch (\Throwable $e) {
            app_log('ensureForecastForDebt gagal: ' . $e->getMessage());
            return null;
        }
    }

    /** Piutang baru (jatuh tempo ada, pembayaran pasti, masih ada sisa) tanpa item Proyeksi dibuatkan item penerimaan dan ditautkan. */
    public static function ensureForecastForInvoice(int $invoiceId): ?int
    {
        try {
            return Db::tx(static function () use ($invoiceId) {
                $i = Db::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
                if (!$i || $i['forecast_item_id'] || !$i['due_date'] || (int) $i['paid_known'] !== 1) {
                    return null;
                }
                $rem = bcsub((string) $i['amount'], (string) $i['paid'], 2);
                if (bccomp($rem, self::EPS, 3) <= 0) {
                    return null;
                }
                $desc = mb_substr(trim($i['customer'] . (($i['invoice_no'] ?? '') !== '' ? ' — ' . ($i['kind'] === 'lampu' ? 'SJ ' : 'Invoice no. ') . $i['invoice_no'] : '')), 0, 500);
                $fid = Db::insert('forecast_items', [
                    'period_month' => substr((string) $i['due_date'], 0, 7), 'expected_date' => $i['due_date'], 'direction' => 'in', 'description' => $desc,
                    'account_hint' => null, 'amount' => $rem, 'is_active' => 1, 'created_by' => self::actor(),
                ]);
                Db::update('invoices', $invoiceId, ['forecast_item_id' => $fid]);
                Audit::log('forecast_create', 'forecast', (string) $fid, 'Item Proyeksi dibuat otomatis dari piutang ' . $i['customer'] . ' #' . $invoiceId, null, ['amount' => $rem, 'expected_date' => $i['due_date']]);
                Version::bump('forecast');
                Version::bump('invoices');
                return $fid;
            });
        } catch (\Throwable $e) {
            app_log('ensureForecastForInvoice gagal: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Penautan otomatis senyap: dipanggil setelah hutang/piutang/item Proyeksi dibuat atau diubah. Hanya kecocokan PASTI
     * (hutang: uraian + jatuh tempo sama; piutang: nomor invoice tertulis di uraian, tidak ambigu). Tak pernah mengganggu aksi utama.
     */
    public static function autoLink(): void
    {
        try {
            self::autoMatch(true);
            self::matchInvoiceTransactions(true);
        } catch (\Throwable $e) {
            app_log('autoLink gagal: ' . $e->getMessage());
        }
    }

    /**
     * Cocokkan pembayaran Piutang (kode bukti Excel, mis. "BM 11/02") ke transaksi Mutasi Masuk dengan kode sama,
     * nominal selisih <= Rp 5 dan tanggal <= 31 hari dari tanggal bayar. Kandidat ganda -> yang selisihnya terkecil, bila tunggal.
     * @return array<int,array{invoice:int,tx:int,label:string}>
     */
    public static function matchInvoiceTransactions(bool $apply): array
    {
        $norm = static fn(string $s): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? $s));
        $byCode = [];
        foreach (Db::all('SELECT id, voucher_code, tx_date, debit, counterparty FROM transactions WHERE voided_at IS NULL AND voucher_code IS NOT NULL AND debit > 0 AND id NOT IN (SELECT transaction_id FROM invoice_payments) AND id NOT IN (SELECT transaction_id FROM debt_payments WHERE transaction_id IS NOT NULL) AND id NOT IN (SELECT transaction_id FROM forecast_realizations) AND id NOT IN (SELECT pay_tx_id FROM invoices WHERE pay_tx_id IS NOT NULL)') as $t) {
            $byCode[$norm((string) $t['voucher_code'])][] = $t;
        }
        $out = [];
        $used = [];
        foreach (Db::all("SELECT * FROM invoices WHERE pay_ref IS NOT NULL AND pay_ref <> '' AND pay_tx_id IS NULL AND paid_known = 1 AND paid > 0 ORDER BY id") as $i) {
            $best = null;
            $bestDiff = null;
            foreach ($byCode[$norm((string) $i['pay_ref'])] ?? [] as $t) {
                $diff = abs((float) bcsub((string) $t['debit'], (string) $i['paid'], 2));
                if ($diff > 5.0) {
                    continue;
                }
                if ($i['paid_date'] !== null && abs((strtotime((string) $t['tx_date']) - strtotime((string) $i['paid_date'])) / 86400) > 31) {
                    continue;
                }
                if (isset($used[$t['id']])) {
                    continue;
                }
                if ($bestDiff === null || $diff < $bestDiff) {
                    [$best, $bestDiff] = [$t, $diff];
                }
            }
            if ($best !== null) {
                $used[$best['id']] = true;
                $out[] = ['invoice' => (int) $i['id'], 'tx' => (int) $best['id'], 'label' => $i['customer'] . ' ← ' . $i['pay_ref'] . ' (#' . $best['id'] . ' ' . $best['tx_date'] . ')'];
                if ($apply) {
                    Db::update('invoices', (int) $i['id'], ['pay_tx_id' => (int) $best['id']]);
                }
            }
        }
        if ($apply && $out) {
            Audit::log('link', 'invoice', null, 'Cocokkan ' . count($out) . ' pembayaran piutang ke transaksi (kode bukti)', null, ['pairs' => array_map(static fn($m) => [$m['invoice'], $m['tx']], $out)]);
            Version::bump('invoices');
        }
        return $out;
    }
}
