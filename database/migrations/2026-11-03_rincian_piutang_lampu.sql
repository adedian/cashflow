-- Revisi 7 (3 Nov 2026) — Piutang Lampu berpola rekap baru: rincian barang per surat jalan (Nama barang, Qty, Harga satuan;
-- Total Piutang = Σ Qty × Harga) dan jatuh tempo dalam jumlah hari (Tgl. jatuh tempo = Tgl. nota + hari).
-- Rincian disimpan sebagai JSON di invoices.items (hanya ditampilkan/diekspor, tidak di-query) sehingga tidak perlu tabel/GRANT baru.
-- Migrasi ADITIF: dua kolom NULL; piutang lama tanpa rincian tetap memakai nominalnya. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-03_rincian_piutang_lampu.sql
SET NAMES utf8mb4;

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS due_days SMALLINT UNSIGNED NULL COMMENT 'jatuh tempo dalam hari sejak tanggal nota (Piutang Lampu)' AFTER due_date;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS items TEXT NULL COMMENT 'JSON [{name,qty,price,subtotal}] rincian barang Piutang Lampu; amount = Σ subtotal' AFTER description;

-- isi jumlah hari dari data lama (tanggal nota & jatuh tempo sudah ada)
UPDATE invoices SET due_days = DATEDIFF(due_date, invoice_date)
 WHERE kind = 'lampu' AND due_days IS NULL AND due_date IS NOT NULL AND invoice_date IS NOT NULL AND due_date >= invoice_date;
