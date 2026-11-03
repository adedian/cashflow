-- Master Rekening Lawan (21 Okt 2026) — migrasi ADITIF: tidak menghapus / mengubah data yang ada. Aman dijalankan berulang.
-- Jalankan sebagai pengguna DB berhak CREATE (mis. root XAMPP), pada database yang dituju:
--   mysql -u root cashflow_hme < database/migrations/2026-10-21_rekening_lawan.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- Daftar rekening pihak lawan (penerima/pembayar) yang dipilih lewat dropdown "Bank / rekening pihak" di formulir transaksi.
-- Nilai label disalin apa adanya ke transactions.counterparty_detail (hanya terlihat Super Admin).
CREATE TABLE IF NOT EXISTS counterparty_accounts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label      VARCHAR(255) NOT NULL COMMENT 'mis. "BCA 0490393030 a.n. PT Contoh"',
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cpa_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Isi awal dari teks bebas yang sudah ada di transaksi (tidak mengubah transaksi).
INSERT IGNORE INTO counterparty_accounts (label)
SELECT DISTINCT TRIM(counterparty_detail) FROM transactions
WHERE counterparty_detail IS NOT NULL AND TRIM(counterparty_detail) <> '';
