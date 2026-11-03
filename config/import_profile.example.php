<?php
// Contoh profil impor workbook asli. Salin menjadi config/import_profile.php lalu sesuaikan dengan workbook Anda.
// config/import_profile.php berisi data usaha nyata dan tidak di-commit.
return [
    // nama sheet => rekening tujuan + letak data (baris pertama/terakhir, sel saldo akhir Excel, kolom)
    'tx_sheets' => [
        'Bank Operasional' => [
            'account' => ['code' => 'BANK-OPS', 'name' => 'Bank Operasional', 'kind' => 'bank', 'mode' => 'asset', 'limit' => null, 'hint' => null, 'sort' => 1, 'open_date' => '2026-01-01', 'open_cell' => 'K4'],
            'first' => 5, 'last' => 500, 'excel_final' => null,
            'cols' => ['date' => 'B', 'code' => 'C', 'party' => 'D', 'cat' => 'E', 'desc' => 'F', 'note' => 'G', 'ref' => 'H', 'debit' => 'I', 'credit' => 'J'],
        ],
        'Pinjaman Bank' => [
            'account' => ['code' => 'PINJAMAN', 'name' => 'Pinjaman Bank', 'kind' => 'facility', 'mode' => 'liability', 'limit' => '100000000.00', 'hint' => null, 'sort' => 2, 'open_date' => '2026-01-01', 'open_cell' => null],
            'first' => 7, 'last' => 100, 'excel_final' => null,
            'cols' => ['date' => 'B', 'code' => 'C', 'party' => 'D', 'cat' => 'E', 'desc' => 'F', 'note' => null, 'ref' => null, 'debit' => 'G', 'credit' => 'H'],
        ],
    ],
    // ejaan nama proyek di Excel (huruf kecil) => nama proyek kanonik
    'projects' => [
        'proyek contoh' => 'Proyek Contoh', 'contoh' => 'Proyek Contoh',
    ],
    // akhiran tambahan "Project <nama> - <akhiran>" => kategori
    'extra_suffix' => [],
    // regex teks kategori proyek gabungan (tidak dibebankan ke satu proyek), atau null
    'combined' => null,
    // kategori tambahan => jenis
    'extra_kinds' => [],
    // aturan tambahan [regex huruf kecil, kategori], dicek setelah aturan bawaan
    'extra_rules' => [],
];
