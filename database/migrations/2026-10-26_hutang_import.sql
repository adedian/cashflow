-- Revisi 2 (26 Okt 2026) — menu Hutang + Impor Excel berpemetaan. Migrasi ADITIF: tidak menghapus / mengubah data yang ada.
-- Aman dijalankan berulang. Jalankan sebagai pengguna DB berhak CREATE/ALTER (mis. root XAMPP), pada database yang dituju:
--   mysql -u root cashflow_hme < database/migrations/2026-10-26_hutang_import.sql
-- lalu beri hak tabel baru ke pengguna aplikasi (lihat bagian "Revisi 2" di database/tools/grants.sql).
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- 1. Hutang (kewajiban kepada supplier/vendor). BUKAN transaksi kas: tidak memengaruhi saldo, laporan, maupun proyeksi.
--    Sisa & status dihitung dari amount - SUM(debt_payments.amount); tidak disimpan.
CREATE TABLE IF NOT EXISTS debts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  seq_no           INT UNSIGNED NULL COMMENT 'No. urut di Excel (informasi)',
  ref_no           VARCHAR(80) NULL COMMENT 'nomor transaksi / dokumen bila tersedia',
  supplier         VARCHAR(160) NOT NULL,
  description      VARCHAR(500) NULL,
  debt_date        DATE NULL COMMENT 'tanggal transaksi / hutang timbul (hanya bila tersedia)',
  due_date         DATE NULL COMMENT 'tanggal jatuh tempo',
  source_account   VARCHAR(80) NULL COMMENT 'rekening sumber pembayaran (teks bebas dari Excel)',
  amount           DECIMAL(18,2) NOT NULL,
  note             VARCHAR(255) NULL,
  forecast_item_id INT UNSIGNED NULL COMMENT 'item Proyeksi yang sama (agar pengingat & proyeksi tidak ganda)',
  source_sheet     VARCHAR(60) NULL,
  source_row       INT UNSIGNED NULL,
  import_batch_id  INT UNSIGNED NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  voided_at        DATETIME NULL,
  voided_by        INT UNSIGNED NULL,
  void_reason      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_debt_forecast (forecast_item_id),
  KEY ix_debt_due (voided_at, due_date),
  KEY ix_debt_supplier (supplier),
  KEY ix_debt_batch (import_batch_id),
  CONSTRAINT fk_debt_fc    FOREIGN KEY (forecast_item_id) REFERENCES forecast_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_debt_batch FOREIGN KEY (import_batch_id)  REFERENCES import_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_debt_cby   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Pembayaran hutang (boleh bertahap). transaction_id = rujukan opsional ke transaksi Cashflow yang sama
--    (UNIQUE: satu transaksi tidak dipakai membayar dua kali). Mencatat pembayaran TIDAK membuat transaksi baru.
CREATE TABLE IF NOT EXISTS debt_payments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  debt_id         INT UNSIGNED NOT NULL,
  pay_date        DATE NULL,
  amount          DECIMAL(18,2) NOT NULL,
  note            VARCHAR(255) NULL,
  transaction_id  BIGINT UNSIGNED NULL,
  import_batch_id INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dp_tx (transaction_id),
  KEY ix_dp_debt (debt_id),
  CONSTRAINT fk_dp_debt  FOREIGN KEY (debt_id) REFERENCES debts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dp_tx    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_dp_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dp_cby   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Template pemetaan kolom Impor Excel (header Excel ternormalisasi -> field sistem) yang sudah dikonfirmasi pengguna.
CREATE TABLE IF NOT EXISTS import_templates (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind         ENUM('transactions','debts') NOT NULL,
  name         VARCHAR(80) NOT NULL,
  mapping_json MEDIUMTEXT NOT NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_it_kind_name (kind, name),
  CONSTRAINT fk_it_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Jenis batch impor: 'workbook' = impor workbook CASH FLOW HME (lama), 'transactions'/'debts' = Impor Excel berpemetaan.
--    Pemeriksaan "data impor aktif" milik impor workbook hanya memperhitungkan batch 'workbook'.
ALTER TABLE import_batches
  ADD COLUMN IF NOT EXISTS kind ENUM('workbook','transactions','debts') NOT NULL DEFAULT 'workbook' AFTER filename;

-- 5. Penghitung versi realtime untuk menu Hutang.
INSERT IGNORE INTO data_versions (scope, version) VALUES ('debts', 1);
