-- Revisi 25 Nov 2026 (lanjutan Invoice Belum Terbit): penerbitan BERTAHAP per project.
--  * invoices.project_id    : relasi ID ke master Project (sumber Invoice Belum Terbit wajib punya project; invoice proyek lama diisi bila namanya cocok)
--  * invoices.issue_status  : + 'selesai' = Invoice Belum Terbit yang sudah diterbitkan penuh (sisa 0; histori tetap)
--  * invoice_issuances      : SATU baris per (penerbitan, sumber): source_id = Invoice Belum Terbit, invoice_id = invoice normal hasil penerbitan,
--                             amount = nominal yang menggunakan saldo sumber itu. Sisa sumber = invoices.amount (nominal awal, tidak diubah)
--                             - SUM(amount) yang cancelled_at-nya NULL. batch_key (unik per sumber) melindungi klik ganda.
--                             Pembatalan penerbitan (hapus invoice hasil) menandai cancelled_at, bukan menghapus baris histori.
-- Aman dijalankan ulang. Dijalankan oleh root (butuh hak ALTER/CREATE/GRANT).

ALTER TABLE invoices
  MODIFY COLUMN issue_status ENUM('terbit','belum_terbit','selesai') NOT NULL DEFAULT 'terbit',
  ADD COLUMN IF NOT EXISTS project_id INT UNSIGNED NULL AFTER project_name,
  ADD KEY IF NOT EXISTS ix_inv_project (project_id);

UPDATE invoices i JOIN projects p ON p.name = i.project_name
  SET i.project_id = p.id
  WHERE i.kind = 'proyek' AND i.project_id IS NULL AND i.project_name IS NOT NULL AND i.project_name <> '';

CREATE TABLE IF NOT EXISTS invoice_issuances (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_key    CHAR(32) NOT NULL COMMENT 'kunci satu aksi Terbitkan (idempoten: klik ganda tidak memotong saldo dua kali)',
  source_id    INT UNSIGNED NOT NULL COMMENT 'invoices.id = Invoice Belum Terbit (sumber)',
  invoice_id   INT UNSIGNED NULL COMMENT 'invoices.id = invoice normal hasil penerbitan; NULL bila penerbitan dibatalkan',
  invoice_no   VARCHAR(80) NULL COMMENT 'salinan No. invoice saat diterbitkan (histori)',
  amount       DECIMAL(18,2) NOT NULL,
  issued_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by    INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_iss_batch_source (batch_key, source_id),
  KEY ix_iss_source (source_id),
  KEY ix_iss_invoice (invoice_id),
  CONSTRAINT fk_iss_source  FOREIGN KEY (source_id)  REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_iss_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_iss_by      FOREIGN KEY (issued_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
