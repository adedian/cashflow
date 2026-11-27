-- Piutang Lain-lain: jenis ketiga di register Piutang (selain Proyek & Lampu). Tanpa proyek; jatuh tempo opsional.
ALTER TABLE invoices
  MODIFY COLUMN kind ENUM('proyek','lampu','lain') NOT NULL DEFAULT 'proyek';
