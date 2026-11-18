-- Revisi 13 (18 Nov 2026) — Tambah Cepat Bank/Rekening Pihak di formulir Transaksi.
-- Migrasi ADITIF: hanya menambah 3 kolom NULL pada counterparty_accounts; tidak mengubah/menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-18_rekening_lawan_pihak.sql
-- Buat backup dulu: php database/tools/backup.php
-- Hak akses tabel sudah ada (grants.sql); kolom baru otomatis ikut. Hosting InfinityFree: tidak perlu GRANT.
SET NAMES utf8mb4;

-- party_id = pemilik rekening (master parties; NULL = belum dihubungkan / rekening lama). Tanpa FOREIGN KEY: master pihak tidak pernah dihapus (hanya dinonaktifkan).
-- bank_name / account_no = bagian terstruktur dari label (hanya terisi untuk rekening yang ditambahkan lewat Tambah Cepat); label tetap
-- satu-satunya teks yang disalin ke transactions.counterparty_detail.
ALTER TABLE counterparty_accounts
  ADD COLUMN IF NOT EXISTS party_id INT UNSIGNED NULL AFTER label,
  ADD COLUMN IF NOT EXISTS bank_name VARCHAR(80) NULL AFTER party_id,
  ADD COLUMN IF NOT EXISTS account_no VARCHAR(40) NULL AFTER bank_name;

ALTER TABLE counterparty_accounts
  ADD INDEX IF NOT EXISTS ix_cpa_party (party_id),
  ADD INDEX IF NOT EXISTS ix_cpa_no (account_no);
