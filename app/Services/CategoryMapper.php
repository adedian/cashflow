<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Normalisasi kolom "Kategori Biaya" Excel (teks bebas) menjadi KATEGORI (sifat transaksi) + PROYEK.
 * Aturan eksplisit & deterministik (tanpa tebakan): teks yang tidak dikenali -> "Belum Dikategorikan".
 * Hasilnya disimpan di tabel category_map sehingga dapat diaudit dan diubah Super Admin.
 */
final class CategoryMapper
{
    public const UNCAT = 'Belum Dikategorikan';

    /** [kategori => jenis] */
    public const KINDS = [
        'Penerimaan Proyek' => 'pendapatan', 'Penerimaan Penjualan Lampu' => 'pendapatan', 'Penerimaan Piutang Usaha' => 'pendapatan',
        'Pendapatan Bunga' => 'pendapatan', 'Pendapatan Lain-lain' => 'pendapatan',
        'Biaya Proyek' => 'biaya', 'Fee Proyek' => 'biaya', 'Pengembalian Biaya Proyek' => 'biaya', 'Diskon Proyek' => 'biaya',
        'Biaya Gaji Staff' => 'biaya', 'Biaya Gaji Project' => 'biaya', 'Biaya Perjalanan Dinas' => 'biaya',
        'Biaya Perjalanan Dinas Sales PLTS' => 'biaya', 'Biaya Sewa Kendaraan' => 'biaya', 'Biaya Sewa' => 'biaya',
        'Biaya Pemanfaatan Kendaraan' => 'biaya', 'Biaya Pemeliharaan Kendaraan' => 'biaya', 'Biaya Asuransi Kendaraan' => 'biaya',
        'Biaya Pemeliharaan Inventaris Kantor' => 'biaya', 'Biaya Pemeliharaan Gedung' => 'biaya', 'Inventaris Kantor' => 'biaya',
        'Inventaris Project' => 'biaya', 'Pembelian Stok / Lampu' => 'biaya', 'Biaya Admin Bank' => 'biaya', 'Biaya Transfer' => 'biaya',
        'Biaya Bunga Bank' => 'biaya', 'Biaya Telpon' => 'biaya', 'Biaya Internet' => 'biaya', 'Biaya Iuran Kantor' => 'biaya',
        'Iuran BPJS Kesehatan' => 'biaya', 'Iuran BPJS Ketenagakerjaan' => 'biaya', 'Biaya Konsultan' => 'biaya',
        'Biaya Pelatihan' => 'biaya', 'Biaya Renovasi Kantor' => 'biaya', 'Biaya Perijinan' => 'biaya', 'Biaya Komisi' => 'biaya',
        'Biaya Asosiasi' => 'biaya', 'Biaya Langganan' => 'biaya', 'Biaya BBM Admin' => 'biaya', 'Biaya Lain-lain' => 'biaya', 'Biaya Kirim' => 'biaya',
        'Pajak' => 'pajak', 'Pajak Bunga Bank' => 'pajak', 'Pajak PBB' => 'pajak',
        'Pinjaman & Hutang Bank' => 'pembiayaan',
        'Pindah Dana' => 'transfer',
        'Piutang Karyawan' => 'lainnya', 'Bon Sementara' => 'lainnya', 'Uang Muka' => 'lainnya', 'Kas Kecil' => 'lainnya',
        'Pembayaran Hutang Usaha' => 'lainnya', 'Potongan' => 'lainnya', self::UNCAT => 'lainnya',
    ];

    /** Akhiran proyek => kategori */
    private const SUFFIX = [
        'tagihan fee' => 'Penerimaan Proyek', 'pembayaran tagihan' => 'Penerimaan Proyek', 'tagihan' => 'Penerimaan Proyek',
        'piutang' => 'Penerimaan Proyek', 'pembagian fee' => 'Fee Proyek', 'fee' => 'Fee Proyek',
        'bon sementara' => 'Bon Sementara', 'potong bon sementara' => 'Bon Sementara', 'pengembalian bon sementara' => 'Bon Sementara',
        'bs' => 'Bon Sementara', 'pengembalian' => 'Pengembalian Biaya Proyek', 'diskon' => 'Diskon Proyek', 'pajak' => 'Pajak',
        'perjalana dinas' => 'Biaya Perjalanan Dinas', 'biaya lain-lain' => 'Biaya Lain-lain', 'biaya admin bank' => 'Biaya Admin Bank',
        'biaya bunga bank' => 'Biaya Bunga Bank', 'operasional' => 'Biaya Proyek',
    ];

    /** Pola non-proyek (regex pada teks huruf kecil, urut) => kategori */
    private const RULES = [
        ['/^(pajak bunga bank)$/', 'Pajak Bunga Bank'],
        ['/^pajak pbb$/', 'Pajak PBB'],
        ['/^pajak$/', 'Pajak'],
        ['/^piutang usaha - penjualan lampu$/', 'Penerimaan Penjualan Lampu'],
        ['/^piutang usaha( - project .*)?$/', 'Penerimaan Piutang Usaha'],
        ['/^pendapatan bunga$/', 'Pendapatan Bunga'],
        ['/^pendapatan lain-lain$/', 'Pendapatan Lain-lain'],
        ['/^piutang karyawan$/', 'Piutang Karyawan'],
        ['/^(bon sementara|bon sementara - pengembalian|sisa bon sementara)$/', 'Bon Sementara'],
        ['/^uang muka( biaya)?$/', 'Uang Muka'],
        ['/^kas kecil$/', 'Kas Kecil'],
        ['/sales plts/', 'Biaya Perjalanan Dinas Sales PLTS'],
        ['/^(biaya )?(perjalanan dinas|perlananan dinas|perjalana)( project)?$/', 'Biaya Perjalanan Dinas'],
        ['/^biaya transfer$/', 'Biaya Transfer'],
        ['/^biaya adm(in)?( bank)?$/', 'Biaya Admin Bank'],
        ['/^(bunga pinjaman|biaya bunga bank)$/', 'Biaya Bunga Bank'],
        ['/^biaya gaji( staff)?$/', 'Biaya Gaji Staff'],
        ['/^biaya gaji project$/', 'Biaya Gaji Project'],
        ['/^iuran bpjs kesehatan$/', 'Iuran BPJS Kesehatan'],
        ['/^iuran bpjs ketenagakerjaan$/', 'Iuran BPJS Ketenagakerjaan'],
        ['/^(biaya )?sewa kendaraan$/', 'Biaya Sewa Kendaraan'],
        ['/^biaya sewa$/', 'Biaya Sewa'],
        ['/^biaya pemanfaatan kendaraan$/', 'Biaya Pemanfaatan Kendaraan'],
        ['/^(biaya )?pemeliharaan kendaraan$/', 'Biaya Pemeliharaan Kendaraan'],
        ['/^biaya asuransi kendaraan$/', 'Biaya Asuransi Kendaraan'],
        ['/^biaya pemeliharaan (inventaris kantor|inv kantor|inventory|inventaris)$/', 'Biaya Pemeliharaan Inventaris Kantor'],
        ['/^biaya pemeliharaan (gedung|bangunan)$/', 'Biaya Pemeliharaan Gedung'],
        ['/^(biaya )?inventory kantor$|^inventaris kantor$|^biaya inventory kantor$/', 'Inventaris Kantor'],
        ['/^inventaris project$/', 'Inventaris Project'],
        ['/^(stok|untuk stok|pembelian lampu)$/', 'Pembelian Stok / Lampu'],
        ['/^biaya (konsultan|tenaga ahli)$/', 'Biaya Konsultan'],
        ['/^biaya pelatihan$/', 'Biaya Pelatihan'],
        ['/^biaya renovasi kantor$/', 'Biaya Renovasi Kantor'],
        ['/^biaya perijinan$/', 'Biaya Perijinan'],
        ['/^biaya komisi$/', 'Biaya Komisi'],
        ['/^biaya asosiasi$/', 'Biaya Asosiasi'],
        ['/^biaya telpon$/', 'Biaya Telpon'],
        ['/^biaya internet$/', 'Biaya Internet'],
        ['/^biaya iuran kantor$/', 'Biaya Iuran Kantor'],
        ['/^biaya langganan$/', 'Biaya Langganan'],
        ['/^biaya bbm admin$/', 'Biaya BBM Admin'],
        ['/^(biaya lain-lain|biaya)$/', 'Biaya Lain-lain'],
        ['/^biaya kirim$/', 'Biaya Kirim'],
        ['/^inventaris$/', 'Inventaris Kantor'],
        ['/^hutang bank - bunga bank$/', 'Biaya Bunga Bank'],
        ['/^(bayar hutang bank|hutang bank|hutang bca|hutang bank bca|pinjaman bank|pembayaran pinjaman|hutang bank - pelunasan|hutang time loan|bayar hutang bca)$/', 'Pinjaman & Hutang Bank'],
        ['/^(bayar hutang lain|hutang usaha)$/', 'Pembayaran Hutang Usaha'],
        ['/^potongan$/', 'Potongan'],
        ['/^pindah dana$/', 'Pindah Dana'],
    ];

    /**
     * @return array{category:string,kind:string,project:?string,rule:string}
     */
    public static function map(?string $raw): array
    {
        $orig = (string) $raw;
        $n = preg_replace('/\s+/', ' ', trim($orig));
        $l = mb_strtolower((string) $n);
        if ($l === '') {
            return self::res(self::UNCAT, null, 'kosong');
        }

        // proyek gabungan: tidak dibebankan ke satu proyek
        $p = ImportProfile::get();
        if (preg_match('/^keterangan project -$/', $l) || ($p['combined'] !== null && preg_match($p['combined'], $l))) {
            return self::res('Biaya Proyek', null, 'proyek gabungan/tanpa nama');
        }

        // "Project <nama> [- <akhiran>]"
        if (preg_match('/^project (.+)$/', $l, $m)) {
            $rest = trim($m[1]);
            $cat = 'Biaya Proyek';
            $namePart = $rest;
            // akhiran terpanjang dahulu
            $suffixMap = self::SUFFIX + $p['extra_suffix'];
            $suffixes = array_keys($suffixMap);
            usort($suffixes, static fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($suffixes as $sf) {
                if (preg_match('/^(.*?)\s*-\s*' . preg_quote($sf, '/') . '$/', $rest, $mm)) {
                    $cat = $suffixMap[$sf];
                    $namePart = trim($mm[1]);
                    break;
                }
            }
            $proj = $p['projects'][$namePart] ?? null;
            if ($proj === null) {
                return self::res(self::UNCAT, null, 'proyek tidak dikenal: ' . $namePart);
            }
            return self::res($cat, $proj, 'proyek');
        }
        // "Piutang Usaha - Project X"
        if (preg_match('/^piutang usaha - project (.+)$/', $l, $m)) {
            return self::res('Penerimaan Piutang Usaha', $p['projects'][trim($m[1])] ?? null, 'piutang usaha proyek');
        }
        foreach (array_merge(self::RULES, $p['extra_rules']) as [$re, $cat]) {
            if (preg_match($re, $l)) {
                $proj = null;
                if (preg_match('/^piutang usaha - project (.+)$/', $l, $m2)) {
                    $proj = $p['projects'][trim($m2[1])] ?? null;
                }
                return self::res($cat, $proj, 'aturan');
            }
        }
        return self::res(self::UNCAT, null, 'tidak dikenal');
    }

    private static function res(string $cat, ?string $proj, string $rule): array
    {
        return ['category' => $cat, 'kind' => (self::KINDS + ImportProfile::get()['extra_kinds'])[$cat] ?? 'lainnya', 'project' => $proj, 'rule' => $rule];
    }
}
