-- Revisi 24 Nov 2026: No. Invoice/PO, dan Catatan per baris uraian.
--  * transaction_items: source_ref (mis. "debt:12" | "invoice:3" | "forecast:7"), ref_no (nomor dokumen untuk tampilan), note (catatan baris)
--  * satu transaksi boleh menghubungi BEBERAPA sumber (satu per baris uraian): kunci unik tabel tautan berubah dari
--    (transaction_id) menjadi (transaction_id, <sumber>) — baris uraian dengan sumber sama dijumlahkan menjadi satu tautan.
-- Aman dijalankan ulang. Dijalankan oleh root (butuh hak ALTER); hak tabel aplikasi tidak berubah.

ALTER TABLE transaction_items
  ADD COLUMN IF NOT EXISTS source_ref VARCHAR(30)  NULL AFTER project_id,
  ADD COLUMN IF NOT EXISTS ref_no     VARCHAR(190) NULL AFTER source_ref,
  ADD COLUMN IF NOT EXISTS note       VARCHAR(500) NULL AFTER ref_no;

-- kunci baru lebih dulu (kolom transaction_id tetap berindeks untuk foreign key), baru kunci lama dilepas
ALTER TABLE debt_payments         ADD UNIQUE KEY IF NOT EXISTS uq_dp_tx_src (transaction_id, debt_id);
ALTER TABLE debt_payments         DROP INDEX IF EXISTS uq_dp_tx;
ALTER TABLE invoice_payments      ADD UNIQUE KEY IF NOT EXISTS uq_ip_tx_src (transaction_id, invoice_id);
ALTER TABLE invoice_payments      DROP INDEX IF EXISTS uq_ip_tx;
ALTER TABLE forecast_realizations ADD UNIQUE KEY IF NOT EXISTS uq_fr_tx_src (transaction_id, forecast_id);
ALTER TABLE forecast_realizations DROP INDEX IF EXISTS uq_fr_tx;

-- transaksi lama yang sudah bertaut: seluruh barisnya mengikuti sumber transaksi (sama dengan aturan lama "satu transaksi = satu sumber")
UPDATE transaction_items i
  JOIN debt_payments p ON p.transaction_id = i.transaction_id
  JOIN debts d ON d.id = p.debt_id
  SET i.source_ref = CONCAT('debt:', p.debt_id), i.ref_no = NULLIF(d.ref_no, '')
  WHERE i.source_ref IS NULL;
UPDATE transaction_items i
  JOIN invoice_payments p ON p.transaction_id = i.transaction_id
  JOIN invoices v ON v.id = p.invoice_id
  SET i.source_ref = CONCAT('invoice:', p.invoice_id), i.ref_no = NULLIF(v.invoice_no, '')
  WHERE i.source_ref IS NULL;
UPDATE transaction_items i
  JOIN forecast_realizations p ON p.transaction_id = i.transaction_id
  SET i.source_ref = CONCAT('forecast:', p.forecast_id)
  WHERE i.source_ref IS NULL;
-- teks No. referensi lama (tanpa tautan) tetap tampil pada setiap barisnya
UPDATE transaction_items i
  JOIN transactions t ON t.id = i.transaction_id
  SET i.ref_no = t.ref_no
  WHERE i.ref_no IS NULL AND i.source_ref IS NULL AND t.ref_no IS NOT NULL AND t.ref_no <> '';
