-- Revisi 3b (29 Okt 2026) — master "Pihak" (penerima/pembayar) untuk dropdown di formulir Transaksi.
-- Migrasi ADITIF: hanya membuat tabel & mengisinya dari nama yang SUDAH ada (transaksi, supplier, customer piutang). Tidak mengubah data lama.
-- Aman dijalankan berulang. Jalankan sebagai pengguna DB berhak CREATE (mis. root XAMPP):
--   mysql -u root cashflow_hme < database/migrations/2026-10-29_pihak.sql
-- lalu beri hak tabel baru ke pengguna aplikasi (blok "Revisi 3b" di database/tools/grants.sql).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS parties (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(255) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parties_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO parties (name) SELECT DISTINCT TRIM(counterparty) FROM transactions WHERE counterparty IS NOT NULL AND TRIM(counterparty) <> '';
INSERT IGNORE INTO parties (name) SELECT DISTINCT TRIM(name) FROM suppliers WHERE TRIM(name) <> '';
INSERT IGNORE INTO parties (name) SELECT DISTINCT TRIM(customer) FROM invoices WHERE TRIM(customer) <> '' AND customer NOT LIKE '(tidak tercatat%';
