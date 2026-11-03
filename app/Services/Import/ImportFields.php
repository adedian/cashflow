<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * Katalog field tujuan Impor Excel + kamus alias header + saran pemetaan.
 * Pemetaan hanyalah SARAN: halaman pemetaan selalu ditampilkan dan pengguna wajib mengonfirmasi.
 * Header "Kas Masuk/Kas Keluar" dikenali sebagai alias Mutasi Masuk/Mutasi Keluar (Revisi 3).
 */
final class ImportFields
{
    public const KINDS = ['transactions' => 'Transaksi Cashflow', 'debts' => 'Hutang'];

    /** field => [label, wajib, alias (sudah dinormalisasi: huruf kecil, tanda baca = spasi)] */
    private const DEF = [
        'transactions' => [
            'date'         => ['Tanggal', true, ['tanggal', 'tgl', 'tanggal transaksi', 'tgl transaksi', 'tanggal bukti', 'tgl bukti', 'date', 'transaction date', 'tanggal mutasi']],
            'voucher'      => ['Nomor Transaksi / Kode Bukti', false, ['kode bukti', 'no bukti', 'nomor bukti', 'kode', 'no transaksi', 'nomor transaksi', 'no trx', 'id transaksi', 'voucher', 'no voucher', 'kode voucher', 'no dokumen', 'nomor dokumen']],
            'party'        => ['Pihak (penerima / pembayar)', false, ['pihak', 'nama pihak', 'penerima', 'pembayar', 'rekening tujuan', 'dari rekening rekening tujuan', 'dari rekening dan rekening tujuan', 'dari rekening tujuan', 'nama', 'vendor', 'nama vendor', 'supplier', 'nama supplier', 'customer', 'counterparty']],
            'party_detail' => ['Bank / rekening pihak', false, ['bank', 'rekening pihak', 'bank rekening pihak', 'bank rekening', 'no rekening pihak', 'rekening lawan']],
            'category'     => ['Kategori', false, ['kategori', 'kategori biaya', 'category', 'jenis biaya', 'jenis']],
            'description'  => ['Keterangan', false, ['keterangan', 'uraian', 'deskripsi', 'description', 'memo', 'remarks', 'narasi', 'berita', 'detail']],
            'note'         => ['Catatan', false, ['catatan', 'note', 'notes', 'catatan tambahan']],
            'ref'          => ['No. Invoice / PO', false, ['no invoice', 'nomor invoice', 'no invoice no po', 'no invoice dan no po', 'no invoice dan po', 'no po', 'nomor po', 'referensi', 'ref', 'no ref', 'no referensi', 'invoice']],
            'debit'        => ['Mutasi Masuk (debit)', false, ['mutasi masuk', 'kas masuk', 'debit', 'debet', 'masuk', 'uang masuk', 'penerimaan', 'pemasukan', 'dana masuk']],
            'credit'       => ['Mutasi Keluar (kredit)', false, ['mutasi keluar', 'kas keluar', 'kredit', 'keluar', 'uang keluar', 'pengeluaran', 'dana keluar']],
            'balance'      => ['Saldo (hanya untuk verifikasi)', false, ['saldo', 'saldo akhir', 'balance', 'saldo berjalan']],
        ],
        'debts' => [
            'seq_no'         => ['No. Urut', false, ['no urut', 'no', 'nomor urut', 'urut', 'no.']],
            'ref_no'         => ['Nomor Transaksi / Dokumen', false, ['no transaksi', 'nomor transaksi', 'no hutang', 'nomor hutang', 'kode hutang', 'no dokumen', 'nomor dokumen', 'no invoice', 'nomor invoice', 'no faktur', 'no po', 'nomor po']],
            'category'       => ['Jenis Hutang (Usaha / Direksi / Lain-lain)', false, ['jenis hutang', 'kategori hutang', 'jenis', 'kategori']],
            'supplier'       => ['Nama Supplier / Vendor', true, ['nama supplier', 'supplier', 'vendor', 'nama vendor', 'nama pihak', 'pihak', 'kreditur', 'pemasok', 'nama pemasok', 'nama']],
            'description'    => ['Keterangan', false, ['keterangan', 'uraian', 'deskripsi', 'description', 'detail']],
            'debt_date'      => ['Tanggal Transaksi', false, ['tanggal transaksi', 'tgl transaksi', 'tanggal', 'tgl', 'tanggal hutang', 'tanggal invoice', 'tgl invoice']],
            'due_date'       => ['Tanggal Jatuh Tempo', false, ['tgl jatuh tempo', 'tanggal jatuh tempo', 'jatuh tempo', 'due date', 'tempo', 'tgl tempo']],
            'source_account' => ['Rekening Sumber', false, ['rekening sumber', 'rekening', 'sumber dana', 'rekening pembayaran', 'bank sumber']],
            'amount'         => ['Nominal Hutang', true, ['nominal', 'jumlah', 'nilai', 'total hutang', 'jumlah hutang', 'nominal hutang', 'amount', 'total', 'tagihan']],
            'paid'           => ['Sudah Dibayar', false, ['dibayar', 'sudah dibayar', 'pembayaran', 'terbayar', 'bayar', 'jumlah dibayar', 'nominal dibayar']],
            'remaining'      => ['Sisa Hutang (verifikasi)', false, ['sisa', 'sisa hutang', 'kurang', 'outstanding', 'saldo hutang']],
            'status'         => ['Status (verifikasi)', false, ['status', 'status pembayaran']],
            'note'           => ['Catatan', false, ['catatan', 'note', 'notes']],
        ],
    ];

    public static function labels(string $kind): array
    {
        $o = [];
        foreach (self::DEF[$kind] ?? [] as $k => $d) {
            $o[$k] = ['label' => $d[0], 'req' => $d[1]];
        }
        return $o;
    }

    public static function valid(string $kind, string $field): bool
    {
        return isset(self::DEF[$kind][$field]);
    }

    /** Skor sebuah baris sebagai baris header: jumlah sel yang persis sama dengan alias salah satu field. */
    public static function headerScore(string $kind, array $cells): int
    {
        $n = 0;
        foreach ($cells as $c) {
            $h = ImportParser::norm($c['v']);
            if ($h === '') {
                continue;
            }
            foreach (self::DEF[$kind] as $d) {
                if (in_array($h, $d[2], true)) {
                    $n++;
                    break;
                }
            }
        }
        return $n;
    }

    /**
     * Tebak baris header (dalam 40 baris pertama) dan jenis data.
     * @return array{header:int,kind:string,score:array<string,int>}
     */
    public static function detect(array $rows): array
    {
        $best = ['transactions' => [0, 0], 'debts' => [0, 0]];   // kind => [skor, baris]
        $first = 0;
        $i = 0;
        foreach ($rows as $r => $cells) {
            if (++$i > 40) {
                break;
            }
            $first = $first ?: $r;
            foreach (array_keys($best) as $k) {
                $s = self::headerScore($k, $cells);
                if ($s > $best[$k][0]) {
                    $best[$k] = [$s, $r];
                }
            }
        }
        $kind = $best['debts'][0] > $best['transactions'][0] ? 'debts' : 'transactions';
        $hdr = $best[$kind][0] >= 2 ? $best[$kind][1] : $first;
        return ['header' => (int) $hdr, 'kind' => $kind, 'score' => ['transactions' => $best['transactions'][0], 'debts' => $best['debts'][0]]];
    }

    /**
     * Saran pemetaan per kolom.
     * @param array<string,string> $headers   kolom(huruf) => teks header (boleh kosong)
     * @param array<string,string> $templates header ternormalisasi => field (dari template tersimpan)
     * @param array<string,string[]> $samples kolom => contoh nilai (untuk menebak kolom tanpa header)
     * @return array<string,array{field:string,how:string,note:string}> how: template|exact|likely|guess|none
     */
    public static function suggest(string $kind, array $headers, array $templates, array $samples): array
    {
        $out = [];
        $claims = [];   // field => [col, kekuatan]
        foreach ($headers as $col => $h) {
            $hn = ImportParser::norm($h);
            $field = '';
            $how = 'none';
            $power = 0;
            if ($hn !== '' && isset($templates[$hn]) && self::valid($kind, $templates[$hn])) {
                [$field, $how, $power] = [$templates[$hn], 'template', 1000];
            } elseif ($hn !== '') {
                foreach (self::DEF[$kind] as $f => $d) {
                    foreach ($d[2] as $a) {
                        if ($hn === $a && 500 + strlen($a) > $power) {
                            [$field, $how, $power] = [$f, 'exact', 500 + strlen($a)];
                        } elseif ($how !== 'exact' && $how !== 'template' && strlen($a) >= 4 && preg_match('/(^| )' . preg_quote($a, '/') . '( |$)/', $hn) && strlen($a) > $power) {
                            [$field, $how, $power] = [$f, 'likely', strlen($a)];
                        }
                    }
                }
            } else {
                // kolom tanpa header: tebak dari isinya (hanya pola yang sangat jelas)
                $vals = array_values(array_filter($samples[$col] ?? [], static fn($v) => $v !== ''));
                if (count($vals) >= 3 && $kind === 'transactions') {
                    $v = count(array_filter($vals, static fn($x) => (bool) preg_match('/^[A-Za-z]{1,5}\s?\d{1,2}\/\d{1,4}$/', $x)));
                    if ($v / count($vals) >= 0.7) {
                        [$field, $how, $power] = ['voucher', 'guess', 1];
                    }
                }
            }
            $out[$col] = ['field' => $field, 'how' => $how, 'note' => ''];
            if ($field !== '') {
                if (!isset($claims[$field]) || $power > $claims[$field][1]) {
                    if (isset($claims[$field])) {
                        $o = $claims[$field][0];
                        $out[$o] = ['field' => '', 'how' => 'none', 'note' => 'Bentrok dengan kolom ' . $col . ' untuk field yang sama; pilih manual.'];
                    }
                    $claims[$field] = [$col, $power];
                } else {
                    $out[$col] = ['field' => '', 'how' => 'none', 'note' => 'Bentrok dengan kolom ' . $claims[$field][0] . ' untuk field yang sama; pilih manual.'];
                }
            }
        }
        return $out;
    }
}
