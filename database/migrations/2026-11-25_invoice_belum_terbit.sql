-- Revisi 25 Nov 2026: Invoice Belum Terbit.
--  * invoices.issue_status: 'terbit' (bawaan: semua data lama) | 'belum_terbit' (belum diterbitkan: tanpa jatuh tempo, tanpa item Proyeksi,
--    tanpa pengingat, tidak masuk hitungan piutang). Satu sumber data; "Terbitkan Invoice" hanya mengubah status (ID & histori tetap).
--  * issued_at / issued_by: kapan & siapa menerbitkan (NULL untuk data lama).
-- Aman dijalankan ulang. Dijalankan oleh root (butuh hak ALTER); hak tabel aplikasi tidak berubah.

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS issue_status ENUM('terbit','belum_terbit') NOT NULL DEFAULT 'terbit' AFTER kind,
  ADD COLUMN IF NOT EXISTS issued_at DATETIME NULL AFTER updated_at,
  ADD COLUMN IF NOT EXISTS issued_by INT UNSIGNED NULL AFTER issued_at;

ALTER TABLE invoices ADD KEY IF NOT EXISTS ix_inv_issue (kind, issue_status);
