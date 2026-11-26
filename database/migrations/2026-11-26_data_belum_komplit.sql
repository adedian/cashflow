-- Data belum komplit (26 Nov 2026) — kotak centang "Data belum komplit" pada formulir Transaksi + filter Status data di daftar.
-- Migrasi ADITIF: hanya menambah satu kolom (default 0 = komplit) dan satu indeks pada transactions; data lama tidak berubah.
-- Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-26_data_belum_komplit.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- incomplete : 1 = data transaksi belum lengkap (dicentang pengguna), 0 = komplit (default; tidak perlu dicentang).
ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS incomplete TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = data belum komplit (centang pada formulir)' AFTER flag;
ALTER TABLE transactions
  ADD KEY IF NOT EXISTS ix_tx_incomplete (incomplete);
