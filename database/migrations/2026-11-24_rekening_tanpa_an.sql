-- 24 Nov 2026: label Bank / rekening pihak tidak lagi memuat akhiran " a.n. <nama pemilik>".
-- Pemilik rekening sudah tercatat di counterparty_accounts.party_id (kolom "Pihak"), jadi nama di belakang label hanya mengulang.
--  * transaksi dulu (menyalin teks label lama), lalu master — keduanya memakai aturan potong yang sama
--  * label yang akan bentrok dengan label lain setelah dipotong DILEWATI (kunci unik uq_cpa_label)
--  * updated_at dibiarkan apa adanya
-- Aman dijalankan ulang (setelah dipotong, tidak ada lagi " a.n. " yang cocok).

UPDATE transactions t
  JOIN counterparty_accounts c ON c.label = t.counterparty_detail
   SET t.counterparty_detail = TRIM(SUBSTRING_INDEX(c.label, ' a.n. ', 1)), t.updated_at = t.updated_at
 WHERE c.label LIKE '% a.n. %'
   AND TRIM(SUBSTRING_INDEX(c.label, ' a.n. ', 1)) <> ''
   AND NOT EXISTS (SELECT 1 FROM (SELECT id, label FROM counterparty_accounts) d
                    WHERE d.id <> c.id AND d.label = TRIM(SUBSTRING_INDEX(c.label, ' a.n. ', 1)));

UPDATE counterparty_accounts c
   SET c.label = TRIM(SUBSTRING_INDEX(c.label, ' a.n. ', 1)), c.updated_at = c.updated_at
 WHERE c.label LIKE '% a.n. %'
   AND TRIM(SUBSTRING_INDEX(c.label, ' a.n. ', 1)) <> ''
   AND NOT EXISTS (SELECT 1 FROM (SELECT id, label FROM counterparty_accounts) d
                    WHERE d.id <> c.id AND d.label = TRIM(SUBSTRING_INDEX(c.label, ' a.n. ', 1)));
