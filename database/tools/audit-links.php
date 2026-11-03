<?php
declare(strict_types=1);
/**
 * Audit relasi Transaksi <-> Hutang/Piutang/Proyeksi (HANYA BACA — tidak mengubah data apa pun).
 *   php database/tools/audit-links.php            (database sesuai .env)
 *   CASHFLOW_ENV_FILE=.env.test php database/tools/audit-links.php
 * Dipakai untuk memeriksa transaksi historis yang belum punya relasi sebelum dipetakan, dan memverifikasi invarian setelah Revisi 3.
 * Pemetaan historis TIDAK dilakukan otomatis: nominal "dibayar" di Excel sudah memuat penerimaan lama, jadi menautkan transaksi lama
 * lewat Transaksi akan menghitung dua kali. Daftar kandidat di bawah hanya untuk ditinjau manusia.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\LinkService;

$line = static function (string $k, $v): void { printf("%-62s %s\n", $k, is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v); };
echo "== Audit relasi (hanya baca) · DB " . env('DB_NAME') . " ==\n\n";

echo "-- Hutang\n";
$line('hutang (tidak dibatalkan)', Db::val('SELECT COUNT(*) FROM debts WHERE voided_at IS NULL'));
$line('pembayaran lewat Transaksi (bertaut transaksi)', Db::val('SELECT COUNT(*) FROM debt_payments WHERE transaction_id IS NOT NULL'));
$line('pembayaran historis tanpa transaksi (Excel) — tetap dihitung', Db::val('SELECT COUNT(*) FROM debt_payments WHERE transaction_id IS NULL'));
$line('hutang belum terpetakan ke master supplier', Db::val('SELECT COUNT(*) FROM debts WHERE supplier_id IS NULL'));
$line('hutang dgn rekening sumber teks lama (belum dipetakan ke rekening)', Db::val("SELECT COUNT(*) FROM debts WHERE source_account_id IS NULL AND source_account IS NOT NULL AND source_account <> ''"));

echo "\n-- Piutang\n";
$line('piutang total / paid_known=0 (Perlu konfirmasi)', Db::one('SELECT COUNT(*) total, COALESCE(SUM(paid_known = 0), 0) konfirmasi FROM invoices'));
$line('piutang dengan nominal dibayar historis (> 0)', Db::val('SELECT COUNT(*) FROM invoices WHERE paid > 0'));
$line('piutang bertaut transaksi historis (pay_tx_id)', Db::val('SELECT COUNT(*) FROM invoices WHERE pay_tx_id IS NOT NULL'));
$line('penerimaan lewat Transaksi (invoice_payments)', Db::val('SELECT COUNT(*) FROM invoice_payments'));
$cand = LinkService::matchInvoiceTransactions(false);
$line('kandidat pencocokan kode bukti (belum diterapkan)', count($cand));

echo "\n-- Proyeksi\n";
$line('item proyeksi / tertaut Hutang / tertaut Piutang', Db::one('SELECT COUNT(*) total, (SELECT COUNT(*) FROM debts WHERE forecast_item_id IS NOT NULL) hutang, (SELECT COUNT(*) FROM invoices WHERE forecast_item_id IS NOT NULL) piutang FROM forecast_items'));
$line('realisasi lewat Transaksi (forecast_realizations)', Db::val('SELECT COUNT(*) FROM forecast_realizations'));
$line('item ditandai dibayar manual (data lama, tanpa realisasi)', Db::val('SELECT COUNT(*) FROM forecast_items f WHERE f.paid_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM forecast_realizations r WHERE r.forecast_id = f.id) AND f.id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL) AND f.id NOT IN (SELECT forecast_item_id FROM invoices WHERE forecast_item_id IS NOT NULL)'));

echo "\n-- Transaksi\n";
$line('transaksi aktif', Db::val('SELECT COUNT(*) FROM transactions WHERE voided_at IS NULL'));
$line('transaksi dengan teks No. invoice/PO lama (bukan relasi)', Db::val("SELECT COUNT(*) FROM transactions WHERE voided_at IS NULL AND ref_no IS NOT NULL AND ref_no <> ''"));
$rows = Db::all(
    "SELECT t.id, t.tx_date, t.ref_no, t.debit, t.credit, 'piutang' AS jenis, i.id AS sid, i.customer, i.amount - i.paid AS sisa
     FROM transactions t JOIN invoices i ON LOWER(TRIM(i.invoice_no)) = LOWER(TRIM(t.ref_no))
     WHERE t.voided_at IS NULL AND t.debit > 0 AND t.ref_no <> '' AND t.id NOT IN (SELECT transaction_id FROM invoice_payments) AND t.id NOT IN (SELECT pay_tx_id FROM invoices WHERE pay_tx_id IS NOT NULL)
     UNION ALL
     SELECT t.id, t.tx_date, t.ref_no, t.debit, t.credit, 'hutang', d.id, d.supplier, NULL
     FROM transactions t JOIN debts d ON LOWER(TRIM(d.ref_no)) = LOWER(TRIM(t.ref_no))
     WHERE t.voided_at IS NULL AND t.credit > 0 AND t.ref_no <> '' AND t.id NOT IN (SELECT transaction_id FROM debt_payments WHERE transaction_id IS NOT NULL)
     LIMIT 50"
);
$line('kandidat transaksi lama yang teks invoice/PO-nya sama dgn sumber (tinjau manual)', count($rows));
foreach ($rows as $r) {
    printf("    tx #%d %s %s %s ↔ %s #%d %s\n", $r['id'], $r['tx_date'], $r['ref_no'], bccomp((string) $r['debit'], '0', 2) > 0 ? '+' . $r['debit'] : '-' . $r['credit'], $r['jenis'], $r['sid'], $r['customer']);
}

echo "\n-- Invarian (harus 0)\n";
$bad = 0;
$check = static function (string $name, int $n) use (&$bad, $line): void { $line($name, $n); $bad += $n; };
$check('transaksi tertaut ke lebih dari satu sumber', (int) Db::val('SELECT COUNT(*) FROM (SELECT transaction_id FROM (SELECT transaction_id FROM debt_payments WHERE transaction_id IS NOT NULL UNION ALL SELECT transaction_id FROM invoice_payments UNION ALL SELECT transaction_id FROM forecast_realizations UNION ALL SELECT pay_tx_id FROM invoices WHERE pay_tx_id IS NOT NULL) u GROUP BY transaction_id HAVING COUNT(*) > 1) x'));
$check('hutang dibayar melebihi nominal', (int) Db::val('SELECT COUNT(*) FROM debts d WHERE d.amount - COALESCE((SELECT SUM(p.amount) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = d.id AND (p.transaction_id IS NULL OR t.voided_at IS NULL)), 0) < -0.004'));
$check('realisasi proyeksi melebihi nilai rencana', (int) Db::val('SELECT COUNT(*) FROM forecast_items f WHERE f.amount - COALESCE((SELECT SUM(r.amount) FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id WHERE r.forecast_id = f.id AND t.voided_at IS NULL), 0) < -0.004 AND NOT EXISTS (SELECT 1 FROM debts WHERE forecast_item_id = f.id)'));
$check('piutang: dibayar < Σ penerimaan lewat Transaksi', (int) Db::val('SELECT COUNT(*) FROM invoices i WHERE i.paid + 0.004 < COALESCE((SELECT SUM(p.amount) FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id WHERE p.invoice_id = i.id AND t.voided_at IS NULL), 0)'));
$check('arah salah: hutang/proyeksi keluar dgn Mutasi Masuk', (int) Db::val('SELECT COUNT(*) FROM debt_payments p JOIN transactions t ON t.id = p.transaction_id WHERE t.credit <= 0'));
$check('arah salah: piutang dgn Mutasi Keluar', (int) Db::val('SELECT COUNT(*) FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id WHERE t.debit <= 0'));
echo "\n" . ($bad === 0 ? 'OK — semua invarian terpenuhi.' : "PERLU DITINJAU — $bad pelanggaran.") . "\n";
exit($bad === 0 ? 0 : 1);
