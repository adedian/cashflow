-- Revisi 1 (20 Okt 2026) — migrasi ADITIF: tidak menghapus / mengubah data yang ada. Aman dijalankan berulang.
-- Jalankan sebagai pengguna DB berhak ALTER (mis. root XAMPP), pada database yang dituju:
--   mysql -u root cashflow_hme < database/migrations/2026-10-20_revisi1.sql
-- (pengguna aplikasi sengaja tidak punya hak ALTER). Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- 1. Status pembayaran item proyeksi (dasar Pengingat Pembayaran).
--    paid_at NULL = belum dibayar. Jatuh tempo = expected_date (tidak dikarang dari tanggal lain).
ALTER TABLE forecast_items
  ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL AFTER is_active,
  ADD COLUMN IF NOT EXISTS paid_by INT UNSIGNED NULL AFTER paid_at;
ALTER TABLE forecast_items
  ADD KEY IF NOT EXISTS ix_fc_due (direction, is_active, paid_at, expected_date);

-- 2. Informasi rekening perusahaan untuk dashboard. Semua opsional; diisi Super Admin lewat Master Data.
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS bank_name VARCHAR(80) NULL AFTER name,
  ADD COLUMN IF NOT EXISTS account_holder VARCHAR(120) NULL AFTER bank_name,
  ADD COLUMN IF NOT EXISTS account_no VARCHAR(30) NULL COMMENT 'nomor rekening lengkap (hanya digit); tampil sesuai hak akses' AFTER account_holder,
  ADD COLUMN IF NOT EXISTS show_on_dashboard TINYINT(1) NOT NULL DEFAULT 1 AFTER acct_hint,
  ADD COLUMN IF NOT EXISTS viewer_can_see_no TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Viewer boleh melihat nomor lengkap' AFTER show_on_dashboard;

-- 3. Pengaturan jangka pengingat (hari kalender ke depan, termasuk hari ini = hari ke-0). Bawaan 11.
INSERT IGNORE INTO app_settings (k, v) VALUES ('reminder_days', '11');
