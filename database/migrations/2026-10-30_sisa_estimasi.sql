-- Revisi 4 (30 Okt 2026) — Sisa realisasi Proyeksi: pilihan "Akhiri" (tutup sisa) / "Biarkan" (tetap aktif) tanpa menghilangkan histori.
-- Migrasi ADITIF: hanya menambah kolom NULL pada forecast_items; tidak mengubah/menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-10-30_sisa_estimasi.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- closed_at/closed_by      : kapan & siapa mengakhiri sisa estimasi (NULL = tidak diakhiri)
-- closed_remaining         : SISA (estimasi − realisasi valid) pada saat diakhiri — catatan histori, bukan transaksi/pembayaran
-- close_note               : alasan/catatan penutupan (opsional)
-- kept_at/kept_by          : kapan & siapa memilih "Biarkan" (sisa tetap estimasi aktif)
-- Estimasi awal = amount (perubahan tercatat di audit log); total realisasi & sisa selalu dihitung dari forecast_realizations.
ALTER TABLE forecast_items
  ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER paid_by,
  ADD COLUMN IF NOT EXISTS closed_by INT UNSIGNED NULL AFTER closed_at,
  ADD COLUMN IF NOT EXISTS closed_remaining DECIMAL(18,2) NULL AFTER closed_by,
  ADD COLUMN IF NOT EXISTS close_note VARCHAR(255) NULL AFTER closed_remaining,
  ADD COLUMN IF NOT EXISTS kept_at DATETIME NULL AFTER close_note,
  ADD COLUMN IF NOT EXISTS kept_by INT UNSIGNED NULL AFTER kept_at;
ALTER TABLE forecast_items
  ADD FOREIGN KEY IF NOT EXISTS fk_fc_closed_by (closed_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD FOREIGN KEY IF NOT EXISTS fk_fc_kept_by (kept_by) REFERENCES users(id) ON DELETE SET NULL;
