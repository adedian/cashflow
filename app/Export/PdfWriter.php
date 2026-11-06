<?php
declare(strict_types=1);

namespace App\Export;

/**
 * Penulis PDF tanpa pustaka luar. Memakai 4 font bawaan PDF (Helvetica) sehingga tidak perlu menanam font.
 * Tata letak mengikuti model Sheet: lebar kolom sama dengan Excel, skala otomatis "muat satu halaman lebar"
 * (seperti Fit All Columns on One Page di Excel), baris judul diulang di tiap halaman, nomor halaman di kaki.
 */
final class PdfWriter
{
    private const PAPER = ['a4' => [595.28, 841.89], 'legal' => [612.0, 1008.0]];

    private const REG = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    private array $pages = [];   // tiap halaman: ['ops' => string, 'w' => float, 'h' => float, 'sheet' => string]
    private array $cache = [];
    private bool $noScale = false;   // PDF dari fromPages(): minta penampil mencetak 100% (tanpa fit-to-page)

    public static function build(Document $doc): string
    {
        $w = new self();
        foreach ($doc->sheets as $sh) {
            $w->renderSheet($sh);
        }
        return $w->assemble($doc);
    }

    /** Berkas PDF dari halaman yang sudah digambar pihak lain (mis. VoucherPdf): tanpa kaki halaman bawaan. */
    public static function fromPages(array $pages, Document $doc): string
    {
        $w = new self();
        $w->pages = $pages;
        $w->noScale = true;   // bukti BK/BM: ukuran halaman = ukuran kertas; jangan diperbesar/digeser saat dicetak
        return $w->assemble($doc, false);
    }

    /** Teks siap dimasukkan ke operator Tj: dikodekan Windows-1252 dan di-escape. */
    public static function escapeText(string $s): string
    {
        static $w = null;
        $w ??= new self();
        return $w->esc($s);
    }

    /* ---------- pengukuran teks ---------- */
    private function cp(string $s): string
    {
        $k = 'c' . $s;
        if (!isset($this->cache[$k])) {
            $prev = mb_substitute_character();
            mb_substitute_character(0x3F);
            $this->cache[$k] = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
            mb_substitute_character($prev);
        }
        return $this->cache[$k];
    }

    /** Lebar teks dalam poin (Helvetica), dipakai Sheet::fit untuk menentukan lebar kolom. */
    public static function textWidth(string $text, bool $bold, float $size): float
    {
        static $w = null;
        $w ??= new self();
        return $w->tw($text, $bold, $size);
    }

    private function tw(string $text, bool $bold, float $size): float
    {
        $bytes = $this->cp($text);
        $tab = $bold ? self::BOLD : self::REG;
        $sum = 0;
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $o = ord($bytes[$i]);
            $sum += ($o >= 32 && $o <= 126) ? $tab[$o - 32] : ($o === 0xB7 ? 278 : ($o === 0x97 ? 1000 : ($bold ? 611 : 556)));
        }
        return $sum * $size / 1000;
    }

    /** @return string[] baris hasil pembungkusan kata */
    private function wrap(string $text, bool $bold, float $size, float $width): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->tw($try, $bold, $size) <= $width) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                    $line = '';
                }
                // kata lebih panjang dari kolom: potong per karakter
                while ($this->tw($word, $bold, $size) > $width && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->tw(mb_substr($word, 0, $cut), $bold, $size) > $width) {
                        $cut--;
                    }
                    $out[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }

    private static function rowBase(float $size): float
    {
        return $size <= 10 ? 12.75 : max(15.0, $size * 1.31);
    }

    private static function rgb(string $hex): string
    {
        return sprintf('%.3f %.3f %.3f', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.') ?: '0';
    }

    /* ---------- tata letak & gambar satu lembar ---------- */
    private function renderSheet(Sheet $sh): void
    {
        [$pw, $ph] = self::PAPER[$sh->paper] ?? self::PAPER['a4'];
        if ($sh->orientation() === 'landscape') {
            [$pw, $ph] = [$ph, $pw];
        }
        $mL = 18.0; $mR = 18.0; $mT = 24.0; $mB = 34.0;

        $maxC = max(1, $sh->cols());
        $maxR = max(1, $sh->lastRow());
        $cw = [];
        $cx = [1 => 0.0];
        for ($c = 1; $c <= $maxC; $c++) {
            $w = $sh->colWidths[$c] ?? 8.43;
            $cw[$c] = round(($w * 7 + 5) * 0.75, 2);
            $cx[$c + 1] = $cx[$c] + $cw[$c];
        }
        $totalW = $cx[$maxC + 1];
        $s = min(1.0, ($pw - $mL - $mR) / $totalW);

        // peta gabungan sel
        $master = [];   // "r,c" => [r1,c1,r2,c2]
        $covered = [];  // "r,c" => true (bukan sel induk)
        foreach ($sh->merges as $m) {
            [$r1, $c1, $r2, $c2] = $m;
            $master[$r1 . ',' . $c1] = $m;
            for ($r = $r1; $r <= $r2; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    if ($r !== $r1 || $c !== $c1) {
                        $covered[$r . ',' . $c] = true;
                    }
                }
            }
        }

        // tinggi baris + isi sel terformat
        $heights = [];
        $prepared = [];
        for ($r = 1; $r <= $maxR; $r++) {
            $row = $sh->rows[$r] ?? ['h' => null, 'cells' => []];
            $need = 15.0;
            $maxSize = 0.0;
            foreach ($row['cells'] as $c => $cell) {
                if (isset($covered[$r . ',' . $c])) {
                    continue;
                }
                $st = Fmt::style($cell['s']);
                [$text, $forced] = Fmt::display($cell, $st);
                $m = $master[$r . ',' . $c] ?? null;
                $c2 = $m ? $m[3] : $c;
                $r2 = $m ? $m[2] : $r;
                $width = $cx[$c2 + 1] - $cx[$c];
                $size = (float) $st['size'];
                $maxSize = max($maxSize, $size);
                $lines = [$text];
                if ($text !== '') {
                    $lines = $st['wrap'] && $cell['t'] === 'text'
                        ? $this->wrap($text, (bool) $st['bold'], $size, $width - 5)
                        : [$text];
                }
                if ($r2 === $r && count($lines) > 1) {
                    $need = max($need, count($lines) * $size * 1.22 + 3);
                }
                $prepared[$r][$c] = ['st' => $st, 'lines' => $lines, 'forced' => $forced, 'cell' => $cell, 'm' => $m, 'x' => $cx[$c], 'w' => $width];
            }
            $base = $maxSize > 0 ? self::rowBase($maxSize) : 15.0;
            $h = $row['h'] ?? null;
            $heights[$r] = max($h ?: $base, $need > 15.0 ? $need : 0);
        }

        // paginasi
        $limit = ($ph - $mT - $mB) / $s;
        $rep = $sh->repeatRows;
        $pages = [];
        $cur = [];
        $y = 0.0;
        for ($r = 1; $r <= $maxR; $r++) {
            $need = $heights[$r];
            if (!empty($sh->keepNext[$r]) && $r < $maxR) {
                $need += $heights[$r + 1] + (isset($heights[$r + 2]) ? $heights[$r + 2] : 0);
            }
            if ($cur && $y + $need > $limit) {
                $pages[] = $cur;
                $cur = [];
                $y = 0.0;
                if ($rep && $r > $rep[1]) {
                    for ($q = $rep[0]; $q <= $rep[1]; $q++) {
                        $cur[] = [$q, $y];
                        $y += $heights[$q];
                    }
                }
            }
            $cur[] = [$r, $y];
            $y += $heights[$r];
        }
        if ($cur) {
            $pages[] = $cur;
        }

        // gambar tiap halaman
        foreach ($pages as $items) {
            $fills = '';
            $borders = '';
            $texts = '';
            $yEnd = 0.0;
            foreach ($items as [$r, $y0]) {
                $yEnd = max($yEnd, $y0 + $heights[$r]);
                foreach ($prepared[$r] ?? [] as $c => $p) {
                    $st = $p['st'];
                    $m = $p['m'];
                    $h = $heights[$r];
                    if ($m) {
                        $h = 0.0;
                        for ($q = $m[0]; $q <= $m[2]; $q++) {
                            $h += $heights[$q];
                        }
                    }
                    $x = $p['x'];
                    $w = $p['w'];
                    if ($st['fill']) {
                        $fills .= self::rgb($st['fill']) . ' rg ' . self::n($x) . ' ' . self::n($y0) . ' ' . self::n($w) . ' ' . self::n($h) . " re f\n";
                    }
                    $b = $st['border'];
                    if ($b !== '') {
                        $x2 = $x + $w;
                        $y2 = $y0 + $h;
                        foreach (['l' => [$x, $y0, $x, $y2], 'r' => [$x2, $y0, $x2, $y2], 't' => [$x, $y0, $x2, $y0], 'b' => [$x, $y2, $x2, $y2]] as $side => [$ax, $ay, $bx, $by]) {
                            if (str_contains($b, $side)) {
                                $borders .= self::n($ax) . ' ' . self::n($ay) . ' m ' . self::n($bx) . ' ' . self::n($by) . " l S\n";
                            }
                        }
                    }
                    if ($p['lines'] === [''] || $p['lines'] === []) {
                        continue;
                    }
                    $texts .= $this->textOps($p, $x, $y0, $w, $h);
                }
            }
            $ops = "q\n" . self::n($s) . ' 0 0 ' . self::n(-$s) . ' ' . self::n($mL) . ' ' . self::n($ph - $mT) . " cm\n";
            $ops .= "0.75 w 0 0 0 RG\n" . $fills . $borders . $texts . "Q\n";
            $this->pages[] = ['ops' => $ops, 'w' => $pw, 'h' => $ph, 'sheet' => $sh->name, 'mL' => $mL, 'mB' => $mB];
        }
    }

    private function textOps(array $p, float $x, float $y0, float $w, float $h): string
    {
        $st = $p['st'];
        $size = (float) $st['size'];
        $bold = (bool) $st['bold'];
        $font = $bold ? ($st['italic'] ? 'F4' : 'F2') : ($st['italic'] ? 'F3' : 'F1');
        $color = $p['forced'] ?? $st['color'];
        $al = Fmt::align($p['cell'], $st);
        $lh = $size * 1.22;
        $lines = $p['lines'];
        $block = count($lines) * $lh;
        $top = match ($st['v']) {
            'top' => $y0 + 1.5,
            'bottom' => $y0 + $h - $block - 1,
            default => $y0 + ($h - $block) / 2,
        };
        $pad = 2.5;
        $isNum = $p['cell']['t'] === 'num' && in_array($st['fmt'], ['acc0', 'acc2', 'bal2', 'int'], true);
        $padR = $isNum && str_starts_with($st['fmt'], 'acc') ? $size * 0.45 + $pad : ($isNum ? $size * 0.3 + $pad : $pad);
        $o = "q " . self::n($x) . ' ' . self::n($y0) . ' ' . self::n($w) . ' ' . self::n($h) . " re W n\nBT " . self::rgb($color) . " rg /$font " . self::n($size) . " Tf\n";
        foreach ($lines as $i => $line) {
            $tw = $this->tw($line, $bold, $size);
            $tx = match ($al) {
                'right' => $x + $w - $padR - $tw,
                'center' => $x + ($w - $tw) / 2,
                default => $x + $pad,
            };
            $base = $top + $i * $lh + ($lh - $size) / 2 + $size * 0.8;
            $o .= '1 0 0 -1 ' . self::n($tx) . ' ' . self::n($base) . ' Tm (' . $this->esc($line) . ") Tj\n";
        }
        return $o . "ET Q\n";
    }

    private function esc(string $s): string
    {
        return strtr($this->cp($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    /* ---------- perakitan berkas PDF ---------- */
    private function assemble(Document $doc, bool $withFooter = true): string
    {
        if (!$this->pages) {
            $sh = new Sheet('Kosong');
            $sh->set(1, 1, 'Tidak ada data.');
            $sh->widths([40]);
            $this->renderSheet($sh);
        }
        $total = count($this->pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R' . ($this->noScale ? ' /ViewerPreferences << /PrintScaling /None >>' : '') . ' >>';
        $fontNames = ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique'];
        foreach ($fontNames as $i => $fn) {
            $objs[3 + $i] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $fn . ' /Encoding /WinAnsiEncoding >>';
        }
        $objs[7] = '<< /Title (' . $this->esc($doc->title) . ') /Author (' . $this->esc($doc->company) . ') /Producer (Cashflow HME) /CreationDate (D:' . date('YmdHis') . ') >>';
        $kids = [];
        $id = 8;
        $stream = [];
        foreach ($this->pages as $i => $pg) {
            $foot = $withFooter ? $this->footer($doc, $pg, $i + 1, $total) : '';
            $data = gzcompress($pg['ops'] . $foot, 6);
            $pageId = $id++;
            $contId = $id++;
            $kids[] = $pageId . ' 0 R';
            $objs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($pg['w']) . ' ' . self::n($pg['h']) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >> >> /Contents ' . $contId . ' 0 R >>';
            $stream[$contId] = $data;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        ksort($objs);
        $all = $objs + array_fill_keys(array_keys($stream), null);
        ksort($all);
        foreach ($all as $n => $body) {
            $off[$n] = strlen($out);
            if (isset($stream[$n])) {
                $out .= $n . " 0 obj\n<< /Length " . strlen($stream[$n]) . " /Filter /FlateDecode >>\nstream\n" . $stream[$n] . "\nendstream\nendobj\n";
            } else {
                $out .= $n . " 0 obj\n" . $body . "\nendobj\n";
            }
        }
        $xref = strlen($out);
        $max = max(array_keys($all));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $out .= sprintf("%010d 00000 n \n", $off[$n] ?? 0);
        }
        return $out . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 7 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function footer(Document $doc, array $pg, int $n, int $total): string
    {
        $left = $doc->company . ' · ' . $doc->title . ' · Dicetak ' . $doc->printedAt . ($doc->printedBy !== '' ? ' oleh ' . $doc->printedBy : '');
        $right = 'Halaman ' . $n . ' dari ' . $total;
        $size = 7.5;
        $y = $pg['h'] - 20;
        $rw = $this->tw($right, false, $size);
        $o = "BT 0.40 0.43 0.52 rg /F1 7.5 Tf\n";
        $o .= '1 0 0 1 ' . self::n($pg['mL']) . ' ' . self::n($y) . ' Tm (' . $this->esc($left) . ") Tj\n";
        $o .= '1 0 0 1 ' . self::n($pg['w'] - $pg['mL'] - $rw) . ' ' . self::n($y) . ' Tm (' . $this->esc($right) . ") Tj\nET\n";
        return $o;
    }
}
