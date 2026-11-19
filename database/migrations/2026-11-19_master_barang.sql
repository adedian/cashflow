-- Master Barang (19 Nov 2026) — Master Data → tab Barang.
-- Migrasi ADITIF: hanya membuat tabel baru products; tidak mengubah/menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-19_master_barang.sql
-- Buat backup dulu: php database/tools/backup.php
-- Hosting (InfinityFree): pengguna DB = pengguna akun, tidak perlu GRANT. Server sendiri: lihat database/tools/grants.sql.
SET NAMES utf8mb4;

-- Daftar barang: nama (unik), satuan (opsional, mis. pcs/unit/set), harga satuan acuan (opsional).
-- Belum menjadi rujukan tabel lain; rincian barang pada Piutang Lampu masih teks bebas.
CREATE TABLE IF NOT EXISTS products (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(160) NOT NULL,
  unit       VARCHAR(30) NULL,
  price      DECIMAL(18,2) NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
