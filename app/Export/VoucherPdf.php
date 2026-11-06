<?php
declare(strict_types=1);

namespace App\Export;

/**
 * Bukti Bank Keluar (BK, merah) dan Bukti Bank Masuk (BM, hijau): satu bukti per halaman.
 * Tata letak dibuat sama dengan contoh dari pengguna (kotak identitas, tabel Perkiraan/Uraian/Jumlah dengan 14 baris,
 * Ch/G.B. No. + TOTAL, Terbilang, kolom tanda tangan). Digambar langsung (bukan lewat model Sheet) karena memakai garis & isian
 * berwarna per jenis, teks vertikal "KEPERLUAN INTERN", dan baris yang bertambah bila uraian lebih dari 14 baris.
 * Memakai font Helvetica bawaan PDF, sama seperti PdfWriter.
 *
 * Koordinat: titik PDF, sumbu-y ke bawah (halaman dibalik dengan matriks cm, seperti PdfWriter).
 */
final class VoucherPdf
{
    private const PAGE_W = 595.28;
    private const MARGIN = 16.0;
    private const MIN_ROWS = 14;
    private const KIND = [
        'BK' => ['accent' => 'FF0000', 'light' => 'EBDEDD', 'title' => 'BUKTI BANK KELUAR', 'who' => 'Dibayarkan Kepada :', 'last' => 'Penerima'],
        'BM' => ['accent' => '00A651', 'light' => 'E2EFDA', 'title' => 'BUKTI BANK MASUK', 'who' => 'Diterima dari :', 'last' => 'Penyetor'],
    ];

    private string $o = '';

    /**
     * @param array<int,array{kind:string,number:string,date:string,party:string,lines:array<int,array{description:string,amount:string}>,total:string}> $vouchers
     */
    public static function build(array $vouchers, Document $doc): string
    {
        $pages = [];
        foreach ($vouchers as $v) {
            $pages[] = (new self())->page($v, $doc);
        }
        if (!$pages) {
            $pages[] = (new self())->page(['kind' => 'BK', 'number' => '', 'date' => '', 'party' => '', 'lines' => [], 'total' => '0.00'], $doc);
        }
        return PdfWriter::fromPages($pages, $doc);
    }

    /* ---------- format ---------- */
    /** 3600000 → "3,600,000.00" (format contoh: koma ribuan, titik desimal). */
    private static function num(string $v): string
    {
        $v = bcadd($v, '0', 2);
        $neg = $v[0] === '-';
        [$i, $d] = explode('.', ltrim($v, '-'));
        return ($neg ? '-' : '') . preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $i) . '.' . $d;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.') ?: '0';
    }

    private static function rgb(string $hex): string
    {
        return sprintf('%.3f %.3f %.3f', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    /* ---------- primitif gambar ---------- */
    private function fill(float $x, float $y, float $w, float $h, string $hex): void
    {
        $this->o .= self::rgb($hex) . ' rg ' . self::n($x) . ' ' . self::n($y) . ' ' . self::n($w) . ' ' . self::n($h) . " re f\n";
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $hex, float $w = 0.6): void
    {
        $this->o .= self::rgb($hex) . ' RG ' . self::n($w) . ' w ' . self::n($x1) . ' ' . self::n($y1) . ' m ' . self::n($x2) . ' ' . self::n($y2) . " l S\n";
    }

    private function box(float $x, float $y, float $w, float $h, string $hex): void
    {
        $this->line($x, $y, $x + $w, $y, $hex);
        $this->line($x, $y + $h, $x + $w, $y + $h, $hex);
        $this->line($x, $y, $x, $y + $h, $hex);
        $this->line($x + $w, $y, $x + $w, $y + $h, $hex);
    }

    private static function tw(string $s, bool $bold, float $size, float $tc = 0.0): float
    {
        return PdfWriter::textWidth($s, $bold, $size) + $tc * max(0, mb_strlen($s));
    }

    /**
     * Teks. $font: r | b | i | bi. $align: l (x = tepi kiri) | r (x = tepi kanan) | c (x = tengah). $rot: terbaca dari bawah ke atas
     * ($base = posisi x garis dasar, $x diabaikan; teks berpusat pada $y).
     */
    private function text(float $x, float $base, string $s, float $size, string $font, string $hex, string $align = 'l', float $tc = 0.0, bool $rot = false): void
    {
        if ($s === '') {
            return;
        }
        $bold = $font === 'b' || $font === 'bi';
        $w = self::tw($s, $bold, $size, $tc);
        $res = ['r' => 'F1', 'b' => 'F2', 'i' => 'F3', 'bi' => 'F4'][$font];
        $tm = '';
        if ($rot) {
            // baca dari bawah ke atas: berpusat pada y = $x (parameter dipakai sebagai pusat vertikal), garis dasar di x = $base
            $tm = '0 -1 -1 0 ' . self::n($base) . ' ' . self::n($x + $w / 2) . ' Tm';
        } else {
            $tx = $align === 'r' ? $x - $w : ($align === 'c' ? $x - $w / 2 : $x);
            $tm = '1 0 0 -1 ' . self::n($tx) . ' ' . self::n($base) . ' Tm';
        }
        $this->o .= 'BT ' . self::rgb($hex) . ' rg /' . $res . ' ' . self::n($size) . ' Tf ' . self::n($tc) . ' Tc ' . $tm . ' (' . PdfWriter::escapeText($s) . ") Tj ET\n";
    }

    /** Garis dasar agar teks tampak berada di tengah tinggi sel. */
    private static function mid(float $top, float $h, float $size): float
    {
        return $top + $h / 2 + $size * 0.34;
    }

    /** Ukuran terbesar (maks ke min) yang membuat teks muat satu baris selebar $width; null bila pada ukuran min pun tidak muat. */
    private static function fitSize(string $s, bool $bold, float $max, float $min, float $width): ?float
    {
        for ($z = $max; $z >= $min - 0.001; $z -= 0.2) {
            if (self::tw($s, $bold, $z) <= $width) {
                return round($z, 1);
            }
        }
        return null;
    }

    /** @return string[] */
    private static function wrap(string $text, float $size, float $width): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $para) {
            $cur = '';
            foreach (preg_split('/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if (self::tw($try, false, $size) <= $width) {
                    $cur = $try;
                    continue;
                }
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
                while (self::tw($word, false, $size) > $width && mb_strlen($word) > 1) {   // kata lebih panjang dari kolom
                    $cut = mb_strlen($word);
                    while ($cut > 1 && self::tw(mb_substr($word, 0, $cut), false, $size) > $width) {
                        $cut--;
                    }
                    $out[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $cur = $word;
            }
            $out[] = $cur;
        }
        return $out ?: [''];
    }

    /* ---------- satu halaman bukti ---------- */
    private function page(array $v, Document $doc): array
    {
        $K = self::KIND[$v['kind']] ?? self::KIND['BK'];
        $acc = $K['accent'];
        $light = $K['light'];
        $W = self::PAGE_W - 2 * self::MARGIN;
        $k = $W / 452;                       // skala dari tata letak contoh (452 satuan lebar)
        $ox = self::MARGIN;
        $oy = self::MARGIN;
        $col = [0.0, 60 * $k, 115 * $k, 374 * $k, $W];   // kolom kiri vertikal | Perkiraan | Uraian | Jumlah
        $hdrH = 38 * $k;
        $bandH = 15 * $k;
        $rowH = 15.2 * $k;
        $chH = 28 * $k;
        $terbH = 18 * $k;
        $sigBand = 19 * $k;
        $sigH = 45 * $k;
        $fs = 9.0;                           // ukuran teks isi (sama dengan contoh); mengecil bila uraian tidak muat satu baris
        $fsMin = 6.8;
        $fsWrap = 7.6;                       // bila tetap tidak muat: dibungkus ke beberapa baris pada ukuran ini
        $pad = 4.5;

        // baris isi: tiap uraian dibungkus; baris pertama tiap uraian memuat jumlahnya
        $rows = [];
        $uw = ($col[3] - $col[2]) - 2 * $pad;
        foreach ($v['lines'] as $ln) {
            $z = self::fitSize($ln['description'], false, $fs, $fsMin, $uw);
            $wrapped = $z !== null ? [$ln['description']] : self::wrap($ln['description'], $fsWrap, $uw);
            foreach ($wrapped as $i => $t) {
                $rows[] = ['t' => $t, 'a' => $i === 0 ? $ln['amount'] : null, 'z' => $z ?? $fsWrap];
            }
        }
        // ukuran font seragam antar baris uraian yang muat satu baris (ikut yang terkecil), seperti contoh
        $sizes = array_filter(array_column($rows, 'z'), static fn($z) => $z !== $fsWrap);
        if ($sizes) {
            $uni = min($sizes);
            foreach ($rows as $i => $r) {
                if ($r['z'] !== $fsWrap) {
                    $rows[$i]['z'] = $uni;
                }
            }
        }
        $nRows = max(self::MIN_ROWS, count($rows));
        $bodyTop = $hdrH + $bandH;
        $chTop = $bodyTop + $nRows * $rowH;
        $terbTop = $chTop + $chH;
        $botTop = $terbTop + $terbH;
        $H = $botTop + $sigBand + $sigH;
        $pageH = $oy + $H + 30;

        $this->o = "q\n1 0 0 -1 0 " . self::n($pageH) . " cm\n" . '1 0 0 1 ' . self::n($ox) . ' ' . self::n($oy) . " cm\n";

        // --- isian berwarna (di bawah garis)
        $this->fill($col[1], $bodyTop, $col[2] - $col[1], $nRows * $rowH, $light);      // Perkiraan
        $this->fill($col[3], $bodyTop, $col[4] - $col[3], $nRows * $rowH, $light);      // Jumlah
        $this->fill($col[1], $hdrH, $W - $col[1], $bandH, $acc);                        // judul kolom
        $this->fill(51 * $k, $terbTop, $W - 51 * $k, $terbH, $light);                   // Terbilang
        $this->fill(132 * $k, $botTop, $W - 132 * $k, $sigBand, $acc);                  // judul tanda tangan
        $this->fill(132 * $k, $botTop + $sigBand, $W - 132 * $k, $sigH, $light);        // kotak tanda tangan

        // --- garis
        $this->box(0, 0, $W, $H, $acc);
        $this->line(132 * $k, 0, 132 * $k, $hdrH, $acc);
        $this->line(322 * $k, 0, 322 * $k, $hdrH, $acc);
        $this->line(0, $hdrH / 2, 132 * $k, $hdrH / 2, $acc);
        $this->line(322 * $k, $hdrH / 2, $W, $hdrH / 2, $acc);
        $this->line(0, $hdrH, $W, $hdrH, $acc);
        $this->line(0, $bodyTop, $W, $bodyTop, $acc);
        for ($i = 1; $i < $nRows; $i++) {
            $this->line($col[1], $bodyTop + $i * $rowH, $W, $bodyTop + $i * $rowH, $acc);
        }
        $this->line(0, $chTop, $W, $chTop, $acc);
        for ($c = 1; $c <= 3; $c++) {
            $this->line($col[$c], $hdrH, $col[$c], $terbTop, $acc);
        }
        $this->line(0, $terbTop, $W, $terbTop, $acc);
        $this->line(51 * $k, $terbTop, 51 * $k, $botTop, $acc);
        $this->line(0, $botTop, $W, $botTop, $acc);
        $this->line(132 * $k, $botTop, 132 * $k, $H, $acc);
        for ($c = 1; $c <= 3; $c++) {
            $this->line((132 + 80 * $c) * $k, $botTop, (132 + 80 * $c) * $k, $H, $acc);
        }

        // --- kepala: identitas, perusahaan + judul, nomor + tanggal
        $half = $hdrH / 2;
        $this->text(6, self::mid(0, $half, 9), $K['who'], 9, 'r', '000000');
        $pz = self::fitSize($v['party'], false, 9, 5.8, 132 * $k - 12) ?? 5.8;
        $party = $v['party'];
        while (self::tw($party, false, $pz) > 132 * $k - 12 && mb_strlen($party) > 4) {
            $party = rtrim(mb_substr($party, 0, -2)) . '...';
        }
        $this->text(6, self::mid($half, $half, $pz), $party, $pz, 'r', '000000');
        $this->text((132 + 95) * $k, self::mid(0, $half, 9), $doc->company, 9, 'i', $acc, 'c');
        $this->text((132 + 95) * $k, $hdrH * 0.71 + 15 * 0.34, $K['title'], 15, 'b', $acc, 'c');
        $nx = 322 * $k;
        $vw = $W - ($nx + 56 * $k) - 5;
        foreach ([[0.0, 'Nomor', $v['number']], [$half, 'Tanggal', $v['date']]] as [$top, $lbl, $val]) {
            $vz = self::fitSize($val, false, 9, 5.8, $vw) ?? 5.8;
            $this->text($nx + 7, self::mid($top, $half, 9), $lbl, 9, 'r', '000000');
            $this->text($nx + 46 * $k, self::mid($top, $half, 9), ':', 9, 'r', '000000');
            $this->text($nx + 56 * $k, self::mid($top, $half, $vz), $val, $vz, 'r', '000000');
        }

        // --- judul kolom
        $this->text(($col[1] + $col[2]) / 2, self::mid($hdrH, $bandH, 8.6), 'Perkiraan', 8.6, 'b', 'FFFFFF', 'c');
        $this->text(($col[2] + $col[3]) / 2, self::mid($hdrH, $bandH, 8.6), 'U R A I A N', 8.6, 'b', 'FFFFFF', 'c', 1.4);
        $this->text(($col[3] + $col[4]) / 2, self::mid($hdrH, $bandH, 8.6), 'J u m l a h', 8.6, 'b', 'FFFFFF', 'c', 1.4);

        // --- teks vertikal "KEPERLUAN INTERN" di kolom kiri (terbaca dari bawah ke atas)
        $this->text($bodyTop + ($nRows * $rowH) / 2, $col[1] / 2 + 2.9, 'KEPERLUAN INTERN', 8.6, 'r', $acc, 'c', 3.8, true);

        // --- isi: uraian & jumlah
        foreach ($rows as $i => $r) {
            $top = $bodyTop + $i * $rowH;
            $this->text($col[2] + $pad, self::mid($top, $rowH, $r['z']), $r['t'], $r['z'], 'r', '000000');
            if ($r['a'] !== null) {
                $neg = bccomp($r['a'], '0', 2) < 0;
                $n = self::num($r['a']);
                $az = self::fitSize($n, false, $fs, 5.5, $col[4] - $col[3] - 2 * $pad - 14) ?? 5.5;
                $this->text($col[3] + 4, self::mid($top, $rowH, $az), 'Rp', $az, 'r', '000000');
                $this->text($col[4] - $pad, self::mid($top, $rowH, $az), $n, $az, 'r', $neg ? 'C00000' : '000000', 'r');
            }
        }

        // --- Ch/G.B. No. + TOTAL
        $this->text($col[1] + 3, $chTop + 12, 'Ch/G.B. No.', 8, 'b', '000000');
        $this->text($col[1] + 3, $chTop + 23, ':', 8, 'b', '000000');
        $tot = self::num($v['total']);
        $tz = self::fitSize($tot, true, 9, 5.5, $col[4] - $col[3] - 2 * $pad - 14) ?? 5.5;
        $this->text($col[3] - 8, self::mid($chTop, $chH, 9), 'TOTAL :', 9, 'b', '000000', 'r');
        $this->text($col[3] + 4, self::mid($chTop, $chH, $tz), 'Rp', $tz, 'b', '000000');
        $this->text($col[4] - $pad, self::mid($chTop, $chH, $tz), $tot, $tz, 'b', '000000', 'r');

        // --- Terbilang
        $this->text(6, self::mid($terbTop, $terbH, 9), 'Terbilang :', 9, 'b', '000000');
        $words = $v['lines'] ? terbilang($v['total']) : '';
        $maxW = $W - 51 * $k - 12;
        $ts = self::fitSize($words, false, 8.4, 5.0, $maxW) ?? 5.0;   // terbilang panjang: kecilkan agar muat satu baris
        $this->text(51 * $k + 6, self::mid($terbTop, $terbH, $ts), $words, $ts, 'i', '404040');

        // --- Catatan + tanda tangan
        $this->text(6, $botTop + 14, 'CATATAN :', 9, 'b', $acc);
        $this->line(6, $botTop + 16, 6 + self::tw('CATATAN :', true, 9), $botTop + 16, $acc, 0.7);
        foreach (['Pembukuan', 'Mengetahui', 'Kasir', $K['last']] as $i => $lbl) {
            $this->text((132 + 80 * $i + 40) * $k, self::mid($botTop, $sigBand, 8.6), $lbl, 8.6, 'b', 'FFFFFF', 'c');
        }

        // --- keterangan cetak (di bawah kotak, rata kanan)
        $by = $doc->printedBy !== '' ? $doc->printedBy : '-';
        $this->text($W, $H + 11, 'Dibuat oleh: ' . $by . '   ' . date('d-m-Y H:i') . ' WIB', 7, 'r', '8C8C8C', 'r');

        $this->o .= "Q\n";
        return ['ops' => $this->o, 'w' => self::PAGE_W, 'h' => $pageH, 'sheet' => $v['number'], 'mL' => self::MARGIN, 'mB' => 0.0];
    }
}
