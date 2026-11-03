-- Revisi 2b (27 Okt 2026) — Piutang Lampu + keterhubungan Hutang/Piutang <-> Proyeksi <-> Transaksi.
-- Migrasi ADITIF (kecuali satu pembersihan baris "TOTAL" yang salah masuk, lihat butir 3). Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-10-27_tautan_piutang_lampu.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- 1. Register invoice dipakai bersama oleh Piutang Proyek (kind='proyek') dan Piutang Lampu (kind='lampu').
--    paid_known = 0 -> nominal pembayaran di Excel tidak terbaca (#REF!): status "Perlu konfirmasi", TIDAK dianggap lunas/belum.
--    forecast_item_id = item Proyeksi penerimaan yang sama; pay_tx_id = transaksi Mutasi Masuk yang membayar (kode bukti BM cocok).
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS kind ENUM('proyek','lampu') NOT NULL DEFAULT 'proyek' AFTER id,
  ADD COLUMN IF NOT EXISTS due_date DATE NULL AFTER invoice_date,
  ADD COLUMN IF NOT EXISTS due_note VARCHAR(30) NULL COMMENT 'mis. CASH / Trial bila jatuh tempo bukan tanggal' AFTER due_date,
  ADD COLUMN IF NOT EXISTS salesperson VARCHAR(40) NULL AFTER due_note,
  ADD COLUMN IF NOT EXISTS paid_date DATE NULL AFTER paid,
  ADD COLUMN IF NOT EXISTS paid_known TINYINT(1) NOT NULL DEFAULT 1 AFTER paid_date,
  ADD COLUMN IF NOT EXISTS pay_ref VARCHAR(40) NULL COMMENT 'kode bukti pembayaran di Excel (mis. BM 11/02)' AFTER paid_known,
  ADD COLUMN IF NOT EXISTS pay_tx_id BIGINT UNSIGNED NULL AFTER pay_ref,
  ADD COLUMN IF NOT EXISTS forecast_item_id INT UNSIGNED NULL AFTER pay_tx_id;
ALTER TABLE invoices
  ADD UNIQUE KEY IF NOT EXISTS uq_inv_forecast (forecast_item_id),
  ADD KEY IF NOT EXISTS ix_inv_kind (kind, invoice_date),
  ADD KEY IF NOT EXISTS ix_inv_paytx (pay_tx_id);
ALTER TABLE invoices
  ADD FOREIGN KEY IF NOT EXISTS fk_inv_fc (forecast_item_id) REFERENCES forecast_items(id) ON DELETE SET NULL,
  ADD FOREIGN KEY IF NOT EXISTS fk_inv_tx (pay_tx_id) REFERENCES transactions(id) ON DELETE SET NULL;

-- 2. Jenis batch impor untuk muat Piutang Lampu.
ALTER TABLE import_batches
  MODIFY COLUMN kind ENUM('workbook','transactions','debts','receivables') NOT NULL DEFAULT 'workbook';

-- 3. Pembersihan: dua baris subtotal Excel ("TOTAL"/"Total" pada rekap Tagihan PLTS) pernah ikut diimpor sebagai invoice
--    dan melipatgandakan total Piutang Proyek. Hanya baris yang PERSIS ciri itu (tanpa customer/proyek/tanggal) yang dihapus.
DELETE FROM invoices
 WHERE kind = 'proyek' AND LOWER(TRIM(invoice_no)) = 'total' AND invoice_date IS NULL AND project_name IS NULL
   AND customer = '(tidak tercatat di Excel)' AND forecast_item_id IS NULL;
