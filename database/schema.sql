-- Cashflow HME — skema database (MariaDB 10.4+ / MySQL 8)
-- Jalankan pada database kosong. Tidak berisi data (tanpa akun, tanpa data contoh).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('viewer','super_admin') NOT NULL,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username   VARCHAR(50) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  success    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_la_user_time (username, created_at),
  KEY ix_la_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  bank_name       VARCHAR(80)  NULL,
  account_holder  VARCHAR(120) NULL,
  account_no      VARCHAR(30)  NULL COMMENT 'nomor rekening lengkap (hanya digit); tampil sesuai hak akses',
  kind            ENUM('bank','facility') NOT NULL DEFAULT 'bank',
  balance_mode    ENUM('asset','liability') NOT NULL DEFAULT 'asset',
  credit_limit    DECIMAL(18,2) NULL,
  opening_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  opening_date    DATE NOT NULL,
  acct_hint       VARCHAR(40) NULL COMMENT '4 digit terakhir rekening (otomatis dari account_no bila diisi)',
  show_on_dashboard TINYINT(1) NOT NULL DEFAULT 1,
  viewer_can_see_no TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Viewer boleh melihat nomor lengkap',
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  kind       ENUM('pendapatan','biaya','pajak','pembiayaan','transfer','lainnya') NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(160) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- master rekening pihak lawan (dropdown "Bank / rekening pihak" di formulir transaksi)
CREATE TABLE IF NOT EXISTS counterparty_accounts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label      VARCHAR(255) NOT NULL COMMENT 'mis. "BCA 0490393030 a.n. PT Contoh"',
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cpa_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- hasil normalisasi kategori teks bebas Excel -> kategori + proyek
CREATE TABLE IF NOT EXISTS category_map (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  raw_text    VARCHAR(190) NOT NULL,
  category_id INT UNSIGNED NULL,
  project_id  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_map_raw (raw_text),
  CONSTRAINT fk_cm_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cm_proj FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename   VARCHAR(255) NOT NULL,
  kind       ENUM('workbook','transactions','debts','receivables') NOT NULL DEFAULT 'workbook',
  sha256     CHAR(64) NOT NULL,
  status     ENUM('imported','voided') NOT NULL DEFAULT 'imported',
  stats_json MEDIUMTEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ib_sha (sha256),
  CONSTRAINT fk_ib_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id          INT UNSIGNED NOT NULL,
  tx_date             DATE NOT NULL,
  seq                 INT UNSIGNED NOT NULL DEFAULT 0,
  voucher_code        VARCHAR(30) NULL,
  counterparty        VARCHAR(255) NULL,
  counterparty_detail VARCHAR(255) NULL COMMENT 'bank / nomor rekening — hanya Super Admin',
  category_id         INT UNSIGNED NULL,
  project_id          INT UNSIGNED NULL,
  category_raw        VARCHAR(190) NULL,
  description         VARCHAR(500) NULL,
  note                TEXT NULL,
  ref_no              VARCHAR(190) NULL,
  debit               DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  credit              DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  flag                VARCHAR(190) NULL COMMENT 'catatan tinjauan (duplikat dicurigai, tanggal tidak ada di Excel, dll.)',
  idem_key            CHAR(32) NULL,
  source_sheet        VARCHAR(60) NULL,
  source_row          INT UNSIGNED NULL,
  import_batch_id     INT UNSIGNED NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by          INT UNSIGNED NULL,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'kunci optimistik: naik 1 pada tiap ubah',
  voided_at           DATETIME NULL,
  voided_by           INT UNSIGNED NULL,
  void_reason         VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tx_idem (idem_key),
  KEY ix_tx_acct_date (account_id, tx_date, seq),
  KEY ix_tx_order (voided_at, tx_date, seq),
  KEY ix_tx_agg (account_id, voided_at, tx_date, category_id, project_id, debit, credit),
  KEY ix_tx_run (account_id, voided_at, seq, debit, credit),
  KEY ix_tx_cat (category_id),
  KEY ix_tx_proj (project_id),
  KEY ix_tx_voucher (voucher_code),
  KEY ix_tx_craw (category_raw),
  KEY ix_tx_void (voided_at, debit, credit),
  KEY ix_tx_batch (import_batch_id),
  CONSTRAINT fk_tx_acct  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_cat   FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_proj  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_cby   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_uby   FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_vby   FOREIGN KEY (voided_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- register invoice proyek (rekap piutang)
CREATE TABLE IF NOT EXISTS invoices (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind         ENUM('proyek','lampu') NOT NULL DEFAULT 'proyek',
  customer     VARCHAR(160) NOT NULL,
  project_name VARCHAR(200) NULL,
  description  VARCHAR(255) NULL,
  invoice_date DATE NULL,
  due_date     DATE NULL,
  due_note     VARCHAR(30) NULL COMMENT 'mis. CASH / Trial bila jatuh tempo bukan tanggal',
  salesperson  VARCHAR(40) NULL,
  invoice_no   VARCHAR(80) NULL,
  amount       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  paid         DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  paid_date    DATE NULL,
  paid_known   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = pembayaran di Excel tidak terbaca (#REF!): Perlu konfirmasi',
  pay_ref      VARCHAR(40) NULL COMMENT 'kode bukti pembayaran di Excel (mis. BM 11/02)',
  pay_tx_id    BIGINT UNSIGNED NULL,
  forecast_item_id INT UNSIGNED NULL,
  note         VARCHAR(255) NULL,
  source_row   INT UNSIGNED NULL,
  import_batch_id INT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_inv_date (invoice_date),
  KEY ix_inv_customer (customer),
  UNIQUE KEY uq_inv_forecast (forecast_item_id),
  KEY ix_inv_kind (kind, invoice_date),
  KEY ix_inv_paytx (pay_tx_id),
  CONSTRAINT fk_inv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_fc FOREIGN KEY (forecast_item_id) REFERENCES forecast_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_tx FOREIGN KEY (pay_tx_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rencana / estimasi (BUKAN transaksi aktual; tidak memengaruhi saldo)
CREATE TABLE IF NOT EXISTS forecast_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_month  CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  expected_date DATE NULL,
  direction     ENUM('in','out') NOT NULL,
  description   VARCHAR(500) NOT NULL,
  account_hint  VARCHAR(80) NULL,
  amount        DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  source_sheet  VARCHAR(60) NULL,
  source_row    INT UNSIGNED NULL,
  import_batch_id INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  paid_at       DATETIME NULL COMMENT 'terisi = sudah dibayar (tidak lagi jadi pengingat)',
  paid_by       INT UNSIGNED NULL,
  closed_at     DATETIME NULL COMMENT 'sisa estimasi diakhiri (Selesai - Sisa Diakhiri)',
  closed_by     INT UNSIGNED NULL,
  closed_remaining DECIMAL(18,2) NULL COMMENT 'sisa pada saat diakhiri (histori; bukan transaksi)',
  close_note    VARCHAR(255) NULL,
  kept_at       DATETIME NULL COMMENT 'dipilih Biarkan: sisa tetap estimasi aktif',
  kept_by       INT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_fc_month (period_month),
  KEY ix_fc_due (direction, is_active, paid_at, expected_date),
  CONSTRAINT fk_fc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_fc_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_fc_kept_by FOREIGN KEY (kept_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- master pihak penerima/pembayar (dropdown "Pihak" di formulir Transaksi)
CREATE TABLE IF NOT EXISTS parties (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(255) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parties_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- master supplier (dropdown "Nama supplier / vendor" di formulir Hutang)
CREATE TABLE IF NOT EXISTS suppliers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(160) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hutang kepada supplier/vendor (BUKAN transaksi kas; sisa & status dihitung dari pembayaran)
CREATE TABLE IF NOT EXISTS debts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category         ENUM('usaha','direksi','lain') NOT NULL DEFAULT 'usaha' COMMENT 'jenis hutang: usaha | direksi | lain-lain',
  seq_no           INT UNSIGNED NULL COMMENT 'No. urut di Excel (informasi)',
  ref_no           VARCHAR(80) NULL COMMENT 'nomor transaksi / dokumen bila tersedia',
  supplier         VARCHAR(160) NOT NULL,
  supplier_id      INT UNSIGNED NULL,
  description      VARCHAR(500) NULL,
  debt_date        DATE NULL COMMENT 'tanggal transaksi / hutang timbul (hanya bila tersedia)',
  due_date         DATE NULL COMMENT 'tanggal jatuh tempo',
  source_account   VARCHAR(80) NULL COMMENT 'rekening sumber pembayaran (salinan teks tampilan / data Excel lama)',
  source_account_id INT UNSIGNED NULL COMMENT 'rekening yang RENCANA dipakai membayar (informasi; rekening aktual dicatat di Transaksi)',
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
  KEY ix_debt_category (category),
  KEY ix_debt_supplier (supplier),
  KEY ix_debt_supplier_id (supplier_id),
  KEY ix_debt_source_acct (source_account_id),
  KEY ix_debt_batch (import_batch_id),
  CONSTRAINT fk_debt_fc    FOREIGN KEY (forecast_item_id) REFERENCES forecast_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_debt_batch FOREIGN KEY (import_batch_id)  REFERENCES import_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_debt_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_debt_srcacct FOREIGN KEY (source_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_debt_cby   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- penerimaan Piutang & realisasi Proyeksi lewat Transaksi (Revisi 3)
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

CREATE TABLE IF NOT EXISTS note_categories (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_note_cat (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title         VARCHAR(200) NOT NULL,
  body          TEXT NOT NULL,
  category_id   INT UNSIGNED NULL,
  is_important  TINYINT(1) NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notes_created (created_at),
  KEY ix_notes_imp (is_important),
  CONSTRAINT fk_notes_cat  FOREIGN KEY (category_id) REFERENCES note_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_notes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50) NULL,
  action      VARCHAR(40) NOT NULL,
  entity      VARCHAR(40) NULL,
  entity_id   VARCHAR(40) NULL,
  summary     VARCHAR(500) NULL,
  before_json MEDIUMTEXT NULL,
  after_json  MEDIUMTEXT NULL,
  ip          VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_al_time (created_at),
  KEY ix_al_action (action),
  KEY ix_al_entity (entity, entity_id),
  KEY ix_al_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- penghitung versi untuk sinkronisasi realtime (dinaikkan di dalam transaksi perubahan)
CREATE TABLE IF NOT EXISTS data_versions (
  scope      VARCHAR(30) NOT NULL,
  version    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  k          VARCHAR(60) NOT NULL,
  v          VARCHAR(500) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- nilai awal non-sensitif (bukan data dummy)
INSERT IGNORE INTO data_versions (scope, version) VALUES
  ('transactions',1),('notes',1),('master',1),('invoices',1),('forecast',1),('debts',1);
INSERT IGNORE INTO app_settings (k, v) VALUES
  ('company_name','PT Hexa Multi Energi'),
  ('viewer_can_export','1'),
  ('reminder_days','11');
