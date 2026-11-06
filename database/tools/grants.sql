-- Hak akses pengguna DB aplikasi (ganti 'GANTI_PASSWORD'). Jalankan sebagai root setelah schema.sql.
-- audit_logs hanya SELECT+INSERT agar jejak audit tidak dapat diubah/dihapus oleh aplikasi.
CREATE USER IF NOT EXISTS 'cashflow_hme_app'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD';
CREATE USER IF NOT EXISTS 'cashflow_hme_app'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD';
-- ulangi blok berikut untuk host 'localhost' dan '127.0.0.1'
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.users TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.login_attempts TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.accounts TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.categories TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.projects TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.category_map TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.counterparty_accounts TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.import_batches TO 'cashflow_hme_app'@'127.0.0.1';
-- Revisi 2: Hutang & Impor Excel berpemetaan
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.debts TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.debt_payments TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.import_templates TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.transactions TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.invoices TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.forecast_items TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.note_categories TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.notes TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.data_versions TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.app_settings TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT ON cashflow_hme.audit_logs TO 'cashflow_hme_app'@'127.0.0.1';
-- Revisi 3: integrasi Transaksi <-> Hutang/Piutang/Proyeksi + master Supplier
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.suppliers TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.invoice_payments TO 'cashflow_hme_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.forecast_realizations TO 'cashflow_hme_app'@'127.0.0.1';
-- Revisi 3b: master Pihak
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.parties TO 'cashflow_hme_app'@'127.0.0.1';
-- Revisi 11: kategori & proyek per baris uraian
GRANT SELECT, INSERT, UPDATE, DELETE ON cashflow_hme.transaction_items TO 'cashflow_hme_app'@'127.0.0.1';
GRANT LOCK TABLES ON cashflow_hme.* TO 'cashflow_hme_app'@'127.0.0.1';
FLUSH PRIVILEGES;
