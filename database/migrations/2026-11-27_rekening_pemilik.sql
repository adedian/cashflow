-- Revisi 27 Nov 2026 — Master Rekening Lawan: tambah "Nama pemilik rekening" (account_holder).
-- Konsep satu rekening lawan: Pihak (supplier/customer, party_id) + Bank + No. rekening + Nama pemilik rekening (mis. CV Mount Dragon / BCA / 123645 / Agus Djoko).
-- Migrasi ADITIF: hanya menambah 1 kolom NULL; tidak mengubah/menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-27_rekening_pemilik.sql
-- Buat backup dulu: php database/tools/backup.php
-- Hak akses tabel sudah ada (grants.sql); kolom baru otomatis ikut. Hosting InfinityFree: tidak perlu GRANT.
SET NAMES utf8mb4;

ALTER TABLE counterparty_accounts
  ADD COLUMN IF NOT EXISTS account_holder VARCHAR(160) NULL AFTER account_no;
