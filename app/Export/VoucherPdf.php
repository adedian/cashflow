<?php
declare(strict_types=1);

namespace App\Export;

/**
 * Bukti Bank Keluar (BK, merah) dan Bukti Bank Masuk (BM, hijau): satu bukti per halaman.
 *
 * Aturan cetak SAMA dengan Sistem Stok (hexastok, bank/voucher_preview.php + _voucher.php):
 *  - halaman POTRET; kertas Legal (215,9 x 355,6 mm; default, kertas yang dipakai pengguna), Folio (215 x 330 mm,
 *    dasar desain stok) atau A4 (pilihan);
 *  - margin kanan/kiri 10 mm; voucher berpadding kiri 14 mm (kanan 0) => kotak voucher mulai 24 mm dari tepi kiri,
 *    lebar = kertas - 34 mm. Sisi ATAS = 5 mm (TOP_MM, atas permintaan pengguna; stok memakai 3 mm yang membuat garis
 *    atas terpotong di printer pengguna, 10 mm dinilai terlalu turun);
 *  - voucher mengisi SETENGAH bagian ATAS kertas (tinggi kotak ±138 mm dari y = 5 mm; kaki ±6 mm di bawahnya;
 *    berhenti > 1 cm sebelum garis tengah kertas Folio 165 mm supaya aman dipotong), sisanya kosong;
 *  - font isi 12 px (9 pt), judul 18 px (13,5 pt) tebal, spasi huruf judul kolom 2 px, "KEPERLUAN INTERN" 3 px;
 *  - kolom grid 10,7% (kosong) | 2,5% (KEPERLUAN INTERN) | 12,3% Perkiraan | 57,4% Uraian | 17,1% Jumlah;
 *    header 29,25% | 42% | 28,75%; bawah: CATATAN 29,25% + 4 kolom tanda tangan sama lebar;
 *  - 14 baris isi + baris "Ch/G.B. No. | TOTAL"; baris bertambah bila uraian lebih banyak;
 *  - warna: keluar #FF0000 / isian #EDE1E0, masuk #00A651 / isian #E2EFDA;
 *  - kaki: "Dibuat oleh: <pembuat transaksi> · <tanggal jam> WIB" (abu-abu, 10 px, rata kanan).
 * Digambar langsung (bukan lewat model Sheet) dengan font Helvetica bawaan PDF, sama seperti PdfWriter.
 *
 * Koordinat: titik PDF, sumbu-y ke bawah (halaman dibalik dengan matriks cm, seperti PdfWriter).
 */
final class VoucherPdf
{
    private const MM = 2.834645669;   // titik PDF per milimeter
    public const PAPER = ['legal' => [215.9, 355.6], 'folio' => [215.0, 330.0], 'a4' => [210.0, 297.0]];   // lebar x tinggi, mm
    public const PAPER_LABEL = ['legal' => 'Legal (215,9 × 355,6 mm)', 'folio' => 'Folio (215 × 330 mm)', 'a4' => 'A4 (210 × 297 mm)'];
    public const DEFAULT_PAPER = 'legal';
    private const MIN_ROWS = 14;
    private const TOP_MM = 5.0;       // jarak kotak voucher dari tepi atas kertas (permintaan pengguna: 0,5 cm lebih naik dari 10 mm)
    private const KIND = [
        'BK' => ['accent' => 'FF0000', 'fill' => 'EDE1E0', 'title' => 'BUKTI BANK KELUAR', 'who' => 'Dibayarkan Kepada :', 'last' => 'Penerima'],
        'BM' => ['accent' => '00A651', 'fill' => 'E2EFDA', 'title' => 'BUKTI BANK MASUK', 'who' => 'Diterima dari :', 'last' => 'Penyetor'],
    ];

    private string $o = '';

    /**
     * @param array<int,array{kind:string,number:string,date:string,party:string,by:string,lines:array<int,array{description:string,amount:string}>,total:string}> $vouchers
     */
    public static function build(array $vouchers, Document $doc, string $paper = self::DEFAULT_PAPER): string
    {
        $paper = isset(self::PAPER[$paper]) ? $paper : self::DEFAULT_PAPER;
        $pages = [];
        foreach ($vouchers as $v) {
            $pages[] = (new self())->page($v, $doc, $paper);
        }
        if (!$pages) {
            $pages[] = (new self())->page(['kind' => 'BK', 'number' => '', 'date' => '', 'party' => '', 'by' => '', 'lines' => [], 'total' => '0.00'], $doc, $paper);
        }
        return PdfWriter::fromPages($pages, $doc);
    }

    /* ---------- format ---------- */
    /** 3600000 → "3,600,000.00" (koma ribuan, titik desimal, seperti stok). */
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

    private function line(float $x1, float $y1, float $x2, float $y2, string $hex, float $w = 0.75): void
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
     * ($x = pusat vertikal, $base = posisi-x garis dasar).
     */
    private function text(float $x, float $base, string $s, float $size, string $font, string $hex, string $align = 'l', float $tc = 0.0, bool $rot = false): void
    {
        if ($s === '') {
            return;
        }
        $bold = $font === 'b' || $font === 'bi';
        $w = self::tw($s, $bold, $size, $tc);
        $res = ['r' => 'F1', 'b' => 'F2', 'i' => 'F3', 'bi' => 'F4'][$font];
        if ($rot) {
            $tm = '0 -1 -1 0 ' . self::n($base) . ' ' . self::n($x + $w / 2) . ' Tm';
        } else {
            $tx = $align === 'r' ? $x - $w : ($align === 'c' ? $x - $w / 2 : $x);
            $tm = '1 0 0 -1 ' . self::n($tx) . ' ' . self::n($base) . ' Tm';
        }
        // Tc (jarak huruf) menempel antar blok teks -> selalu dikirim
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
    private function page(array $v, Document $doc, string $paper): array
    {
        $K = self::KIND[$v['kind']] ?? self::KIND['BK'];
        $acc = $K['accent'];
        $fillC = $K['fill'];
        $mm = self::MM;
        [$pwMm, $phMm] = self::PAPER[$paper];
        $pw = $pwMm * $mm;
        $ph = $phMm * $mm;
        $ox = (10 + 14) * $mm;               // margin kiri @page 10 mm + padding kiri voucher 14 mm
        $oy = self::TOP_MM * $mm;            // jarak kotak dari tepi atas kertas (di atas area tak-tercetak printer)
        $W = $pw - $ox - 10 * $mm;           // kanan: margin @page 10 mm, padding kanan 0
        $p = static fn(float $pct): float => $W * $pct / 100;

        // tinggi (mm -> pt): total kotak ±138 mm (dipadatkan dari 145 mm supaya voucher yang digeser turun 10 mm
        // dari atas tetap berhenti jauh sebelum garis tengah kertas)
        $hdrH = 13.2 * $mm;
        $headH = 5.6 * $mm;
        $rowH = 5.6 * $mm;
        $lastH = 10.0 * $mm;
        $terbH = 6.6 * $mm;
        $sigBand = 6.8 * $mm;
        $sigH = 17.0 * $mm;

        // ukuran teks (12 px = 9 pt)
        $fs = 9.0;
        $pad = 7 * 0.75;                     // padding sel grid 7 px
        $col = [0.0, $p(10.7), $p(13.2), $p(25.5), $p(82.9), $W];   // blank | label | perkiraan | uraian | jumlah | tepi kanan

        // baris isi: uraian yang tidak muat satu baris dibungkus (baris bertambah); baris pertama tiap uraian memuat jumlahnya
        $rows = [];
        $uw = ($col[4] - $col[3]) - 2 * $pad;
        foreach ($v['lines'] as $ln) {
            $z = self::fitSize($ln['description'], false, $fs, 7.6, $uw);   // boleh mengecil sedikit; kalau tetap tidak muat -> bungkus
            $wrapped = $z !== null ? [$ln['description']] : self::wrap($ln['description'], $fs, $uw);
            foreach ($wrapped as $i => $t) {
                $rows[] = ['t' => $t, 'a' => $i === 0 ? $ln['amount'] : null, 'z' => $z ?? $fs];
            }
        }
        $sizes = array_filter(array_column($rows, 'z'), static fn($z) => $z < $fs);
        if ($sizes) {
            $uni = min($sizes);              // ukuran seragam antar baris yang dikecilkan
            foreach ($rows as $i => $r) {
                if ($r['z'] < $fs) {
                    $rows[$i]['z'] = $uni;
                }
            }
        }
        $nRows = max(self::MIN_ROWS, count($rows));
        $bodyTop = $hdrH + $headH;
        $lastTop = $bodyTop + $nRows * $rowH;
        $terbTop = $lastTop + $lastH;
        $botTop = $terbTop + $terbH;
        $H = $botTop + $sigBand + $sigH;

        $this->o = "q\n1 0 0 -1 0 " . self::n($ph) . " cm\n1 0 0 1 " . self::n($ox) . ' ' . self::n($oy) . " cm\n";

        // --- isian berwarna (di bawah garis)
        $this->fill($col[2], $bodyTop, $col[3] - $col[2], $nRows * $rowH, $fillC);      // Perkiraan
        $this->fill($col[4], $bodyTop, $col[5] - $col[4], $nRows * $rowH, $fillC);      // Jumlah
        $this->fill($col[2], $hdrH, $W - $col[2], $headH, $acc);                        // judul kolom
        $this->fill(0, $terbTop, $W, $terbH, $fillC);                                   // Terbilang (seluruh baris)
        $this->fill($p(29.25), $botTop, $W - $p(29.25), $sigBand, $acc);                // judul tanda tangan
        $this->fill($p(29.25), $botTop + $sigBand, $W - $p(29.25), $sigH, $fillC);      // kotak tanda tangan

        // --- garis
        $this->box(0, 0, $W, $hdrH, $acc);                                              // header
        $this->line($p(29.25), 0, $p(29.25), $hdrH, $acc);
        $this->line($p(71.25), 0, $p(71.25), $hdrH, $acc);
        $this->line(0, $hdrH / 2, $p(29.25), $hdrH / 2, $acc);
        $this->line($p(71.25), $hdrH / 2, $W, $hdrH / 2, $acc);
        $this->box(0, $hdrH, $W, $terbTop - $hdrH, $acc);                               // grid
        foreach ([1, 2, 3, 4] as $c) {
            $this->line($col[$c], $hdrH, $col[$c], $terbTop, $acc);
        }
        $this->line($col[2], $bodyTop, $W, $bodyTop, $acc);
        for ($i = 1; $i <= $nRows; $i++) {
            $this->line($col[2], $bodyTop + $i * $rowH, $W, $bodyTop + $i * $rowH, $acc);
        }
        $this->box(0, $terbTop, $W, $terbH, $acc);                                      // Terbilang
        $this->line($col[1], $terbTop, $col[1], $botTop, $acc);
        $this->box(0, $botTop, $W, $H - $botTop, $acc);                                 // CATATAN + tanda tangan
        for ($c = 0; $c < 4; $c++) {
            $this->line($p(29.25 + 17.6875 * $c), $botTop, $p(29.25 + 17.6875 * $c), $H, $acc);
        }
        $this->line($p(29.25), $botTop + $sigBand, $W, $botTop + $sigBand, $acc);

        // --- kepala: identitas | perusahaan + judul | nomor + tanggal
        $half = $hdrH / 2;
        $hp = 8 * 0.75;                      // padding sel header 8 px
        $this->text($hp, self::mid(0, $half, $fs), $K['who'], $fs, 'r', '1A1A1A');
        $pw1 = $p(29.25) - 2 * $hp;
        $pz = self::fitSize($v['party'], false, $fs, 5.8, $pw1) ?? 5.8;
        $party = $v['party'];
        while (self::tw($party, false, $pz) > $pw1 && mb_strlen($party) > 4) {
            $party = rtrim(mb_substr($party, 0, -2)) . '...';
        }
        $this->text($hp, self::mid($half, $half, $pz), $party, $pz, 'r', '1A1A1A');
        $midX = $p(29.25 + 21);
        $this->text($midX, 3 * 0.75 + $fs * 0.9, $doc->company, $fs, 'i', $acc, 'c');
        $this->text($midX, 3 * 0.75 + $fs * 1.2 + ($hdrH - 3 * 0.75 - $fs * 1.2) / 2 + 13.5 * 0.34, $K['title'], 13.5, 'b', $acc, 'c', 0.75);
        $rx = $p(71.25);
        $vw = $W - ($rx + $hp + 56 * 0.75 + 14 * 0.75) - $hp;
        foreach ([[0.0, 'Nomor', $v['number']], [$half, 'Tanggal', $v['date']]] as [$top, $lbl, $val]) {
            $vz = self::fitSize($val, false, $fs, 5.8, $vw) ?? 5.8;
            $this->text($rx + $hp, self::mid($top, $half, $fs), $lbl, $fs, 'r', '1A1A1A');
            $this->text($rx + $hp + 56 * 0.75, self::mid($top, $half, $fs), ':', $fs, 'r', '1A1A1A');
            $this->text($rx + $hp + 56 * 0.75 + 14 * 0.75, self::mid($top, $half, $vz), $val, $vz, 'r', '1A1A1A');
        }

        // --- judul kolom (tebal, spasi huruf 2 px)
        $tc = 2 * 0.75;
        $this->text(($col[2] + $col[3]) / 2, self::mid($hdrH, $headH, $fs), 'Perkiraan', $fs, 'b', 'FFFFFF', 'c', $tc);
        $this->text(($col[3] + $col[4]) / 2, self::mid($hdrH, $headH, $fs), 'U R A I A N', $fs, 'b', 'FFFFFF', 'c', $tc);
        $this->text(($col[4] + $col[5]) / 2, self::mid($hdrH, $headH, $fs), 'J u m l a h', $fs, 'b', 'FFFFFF', 'c', $tc);

        // --- "KEPERLUAN INTERN" vertikal di kolom label (terbaca dari bawah ke atas), berpusat pada tinggi grid
        $this->text(($hdrH + $terbTop) / 2, ($col[1] + $col[2]) / 2 + $fs * 0.34, 'KEPERLUAN INTERN', $fs, 'r', $acc, 'c', 3 * 0.75, true);

        // --- isi: uraian & jumlah ("Rp" kiri, angka kanan)
        foreach ($rows as $i => $r) {
            $top = $bodyTop + $i * $rowH;
            $this->text($col[3] + $pad, self::mid($top, $rowH, $r['z']), $r['t'], $r['z'], 'r', '1A1A1A');
            if ($r['a'] !== null) {
                $neg = bccomp($r['a'], '0', 2) < 0;
                $n = self::num($r['a']);
                $az = self::fitSize($n, false, $fs, 5.5, $col[5] - $col[4] - 2 * $pad - 14) ?? 5.5;
                $this->text($col[4] + $pad, self::mid($top, $rowH, $az), 'Rp', $az, 'r', '1A1A1A');
                $this->text($col[5] - $pad, self::mid($top, $rowH, $az), $n, $az, 'r', $neg ? 'B00020' : '1A1A1A', 'r');
            }
        }

        // --- Ch/G.B. No. (membungkus jadi dua baris) + TOTAL
        $this->text($col[2] + $pad, $lastTop + $lastH / 2 - 1.2, 'Ch/G.B. No.', $fs, 'b', '1A1A1A');
        $this->text($col[2] + $pad, $lastTop + $lastH / 2 - 1.2 + $fs * 1.3, ':', $fs, 'b', '1A1A1A');
        $tot = self::num($v['total']);
        $tz = self::fitSize($tot, true, $fs, 5.5, $col[5] - $col[4] - 2 * $pad - 14) ?? 5.5;
        $this->text($col[4] - $pad, self::mid($lastTop, $lastH, $fs), 'TOTAL :', $fs, 'b', '1A1A1A', 'r');
        $this->text($col[4] + $pad, self::mid($lastTop, $lastH, $tz), 'Rp', $tz, 'b', '1A1A1A');
        $this->text($col[5] - $pad, self::mid($lastTop, $lastH, $tz), $tot, $tz, 'b', '1A1A1A', 'r');

        // --- Terbilang
        $this->text(4 * 0.75 + 3, self::mid($terbTop, $terbH, $fs), 'Terbilang :', $fs, 'b', '1A1A1A');
        $words = $v['lines'] ? terbilang($v['total']) : '';
        $maxW = $W - $col[1] - 2 * $pad;
        $ts = self::fitSize($words, false, $fs, 5.0, $maxW) ?? 5.0;   // terbilang panjang: kecilkan agar muat satu baris
        $this->text($col[1] + $pad, self::mid($terbTop, $terbH, $ts), $words, $ts, 'i', '1A1A1A');

        // --- CATATAN + tanda tangan
        $cx = 7 * 0.75;
        $this->text($cx, $botTop + 5 * 0.75 + $fs * 0.9, 'CATATAN :', $fs, 'b', $acc);
        $this->line($cx, $botTop + 5 * 0.75 + $fs * 0.9 + 1.4, $cx + self::tw('CATATAN :', true, $fs), $botTop + 5 * 0.75 + $fs * 0.9 + 1.4, $acc, 0.7);
        foreach (['Pembukuan', 'Mengetahui', 'Kasir', $K['last']] as $i => $lbl) {
            $this->text($p(29.25 + 17.6875 * ($i + 0.5)), self::mid($botTop, $sigBand, $fs), $lbl, $fs, 'b', 'FFFFFF', 'c');
        }

        // --- kaki (di dalam alur voucher, rata kanan): pembuat transaksi + waktu cetak
        $by = trim((string) ($v['by'] ?? '')) !== '' ? $v['by'] : '-';
        $this->text($W, $H + 8 * 0.75 + 7.5 * 0.85, 'Dibuat oleh: ' . $by . '  ·  ' . date('d-m-Y H:i') . ' WIB', 7.5, 'r', '999999', 'r');

        $this->o .= "Q\n";
        return ['ops' => $this->o, 'w' => $pw, 'h' => $ph, 'sheet' => $v['number'], 'mL' => $ox, 'mB' => 0.0];
    }
}
