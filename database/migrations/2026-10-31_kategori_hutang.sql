-- Revisi 5 (31 Okt 2026) — Jenis hutang: Hutang Usaha / Hutang Direksi / Hutang Lain-lain.
-- Migrasi ADITIF: hanya menambah satu kolom pada debts; tidak mengubah atau menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-10-31_kategori_hutang.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- Semua hutang yang sudah ada diberi 'usaha' (hutang kepada supplier/vendor, sesuai asal menu Hutang); ubah per hutang lewat Ubah.
ALTER TABLE debts
  ADD COLUMN IF NOT EXISTS category ENUM('usaha','direksi','lain') NOT NULL DEFAULT 'usaha' COMMENT 'jenis hutang: usaha | direksi | lain-lain' AFTER id;
ALTER TABLE debts
  ADD KEY IF NOT EXISTS ix_debt_category (category);
