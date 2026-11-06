-- Revisi 11 (6 Nov 2026) — Kategori & Proyek dipindah ke TIAP BARIS URAIAN pada Transaksi.
-- Migrasi ADITIF: hanya membuat tabel baru transaction_items; tidak mengubah/menghapus data. Aman dijalankan berulang.
--   mysql -u root cashflow_hme < database/migrations/2026-11-06_uraian_kategori_proyek.sql
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- Satu baris = satu uraian: uraian + kategori + proyek + jumlah bertanda (boleh negatif, mis. potongan PPh).
-- transactions.debit/credit = Σ amount; arah (masuk/keluar) mengikuti transaksi. Laporan/dashboard per kategori & proyek
-- dihitung dari baris ini; transaksi lama/impor (tanpa baris) memakai category_id/project_id pada transactions.
-- Kolom transactions.items (JSON, Revisi 10) tetap dibaca sebagai cadangan untuk transaksi yang dibuat sebelum revisi ini;
-- transaksi yang disimpan ulang otomatis pindah ke tabel ini.
-- Hosting (InfinityFree): pengguna DB = pengguna akun, tidak perlu GRANT. Server sendiri: lihat database/tools/grants.sql.
CREATE TABLE IF NOT EXISTS transaction_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  line_no        SMALLINT UNSIGNED NOT NULL,
  description    VARCHAR(500) NOT NULL,
  category_id    INT UNSIGNED NULL,
  project_id     INT UNSIGNED NULL,
  amount         DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_txi_line (transaction_id, line_no),
  KEY ix_txi_cat (category_id),
  KEY ix_txi_proj (project_id),
  CONSTRAINT fk_txi_tx   FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_txi_cat  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_txi_proj FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
