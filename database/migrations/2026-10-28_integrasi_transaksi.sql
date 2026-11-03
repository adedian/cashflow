-- Revisi 3 (28 Okt 2026) — Integrasi Transaksi <-> Hutang / Piutang / Proyeksi + master Supplier.
-- Migrasi ADITIF: tidak menghapus / mengubah nilai data yang ada (hanya menambah tabel/kolom dan mengisi kolom BARU).
-- Aman dijalankan berulang. Jalankan sebagai pengguna DB berhak CREATE/ALTER (mis. root XAMPP):
--   mysql -u root cashflow_hme < database/migrations/2026-10-28_integrasi_transaksi.sql
-- lalu beri hak tabel baru ke pengguna aplikasi (blok "Revisi 3" di database/tools/grants.sql).
-- Buat backup dulu: php database/tools/backup.php
SET NAMES utf8mb4;

-- 1. Master supplier. Diisi dari nama supplier yang SUDAH ada di tabel hutang (tanpa data karangan).
CREATE TABLE IF NOT EXISTS suppliers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(160) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO suppliers (name) SELECT DISTINCT TRIM(supplier) FROM debts WHERE TRIM(supplier) <> '';

-- 2. Hutang: rujukan supplier & rekening sumber (rencana). Kolom teks lama (supplier, source_account) TETAP sebagai salinan tampilan.
ALTER TABLE debts
  ADD COLUMN IF NOT EXISTS supplier_id INT UNSIGNED NULL AFTER supplier,
  ADD COLUMN IF NOT EXISTS source_account_id INT UNSIGNED NULL COMMENT 'rekening yang RENCANA dipakai membayar (informasi; rekening aktual dicatat di Transaksi)' AFTER source_account;
ALTER TABLE debts
  ADD KEY IF NOT EXISTS ix_debt_supplier_id (supplier_id),
  ADD KEY IF NOT EXISTS ix_debt_source_acct (source_account_id);
ALTER TABLE debts
  ADD FOREIGN KEY IF NOT EXISTS fk_debt_supplier (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  ADD FOREIGN KEY IF NOT EXISTS fk_debt_srcacct (source_account_id) REFERENCES accounts(id) ON DELETE SET NULL;

-- Pemetaan HANYA bila namanya persis sama (satu-satunya kecocokan). Sisanya dibiarkan NULL — tidak ditebak.
UPDATE debts d JOIN suppliers s ON s.name = TRIM(d.supplier) SET d.supplier_id = s.id WHERE d.supplier_id IS NULL;
UPDATE debts d JOIN accounts a ON (LOWER(TRIM(d.source_account)) = LOWER(a.name) OR LOWER(TRIM(d.source_account)) = LOWER(a.code))
   SET d.source_account_id = a.id
 WHERE d.source_account_id IS NULL AND TRIM(COALESCE(d.source_account, '')) <> ''
   AND (SELECT COUNT(*) FROM accounts a2 WHERE LOWER(TRIM(d.source_account)) IN (LOWER(a2.name), LOWER(a2.code))) = 1;

-- 3. Penerimaan Piutang lewat Transaksi (Mutasi Masuk). Satu transaksi = satu penerimaan (UNIQUE). Nominal = nominal transaksi.
--    invoices.paid tetap total dibayar (historis Excel + penerimaan ini); angka historis tidak disentuh.
CREATE TABLE IF NOT EXISTS invoice_payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id     INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  pay_date       DATE NULL,
  amount         DECIMAL(18,2) NOT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ip_tx (transaction_id),
  KEY ix_ip_invoice (invoice_id),
  CONSTRAINT fk_ip_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ip_tx      FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ip_cby     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Realisasi item Proyeksi lewat Transaksi (bertahap). Nilai rencana (forecast_items.amount) tetap; sisa = rencana − Σ realisasi.
CREATE TABLE IF NOT EXISTS forecast_realizations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  forecast_id    INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  amount         DECIMAL(18,2) NOT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fr_tx (transaction_id),
  KEY ix_fr_forecast (forecast_id),
  CONSTRAINT fk_fr_fc  FOREIGN KEY (forecast_id) REFERENCES forecast_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fr_tx  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fr_cby FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
