-- Revisi 10 (6 Nov 2026) — Rincian uraian per transaksi (lebih dari satu baris uraian + jumlahnya) untuk cetak Bukti Bank Keluar/Masuk.
-- Migrasi ADITIF: hanya menambah satu kolom NULL pada transactions; tidak mengubah/menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-06_rincian_uraian.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- items : JSON [{"description":"…","amount":"43283472.02"}, …] — uraian & jumlah bertanda (boleh negatif, mis. potongan PPh).
--         debit/credit pada transaksi = Σ amount (dihitung server saat simpan). NULL = transaksi lama/impor: satu baris dari description + nominal.
--         Hanya ditampilkan/dicetak (tidak di-query), jadi tidak perlu tabel/GRANT baru.
ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS items TEXT NULL COMMENT 'JSON [{description,amount}] rincian uraian; debit/credit = Σ amount' AFTER description;
