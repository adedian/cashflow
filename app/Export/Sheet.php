<?php
declare(strict_types=1);

namespace App\Export;

/**
 * Model lembar kerja netral (bukan format file). Satu model dirender menjadi XLSX, PDF, dan pratinjau HTML
 * sehingga ketiganya selalu memuat isi, urutan kolom, dan tata letak yang sama.
 *
 * Sel: ['v' => nilai, 't' => 'text'|'num'|'date', 's' => gaya, 'f' => rumus (opsional, hanya untuk Excel)].
 * Gaya (semua opsional): font, size, bold, italic, color (RRGGBB), fill (RRGGBB), border (huruf l,r,t,b),
 * h (left|center|right), v (top|center|bottom), wrap (bool), fmt (text|int|acc0|acc2|bal2|date).
 */
final class Sheet
{
    public array $colWidths = [];   // nomor kolom (1-based) => lebar dalam karakter Excel
    public array $rows = [];        // nomor baris => ['h' => ?float, 'cells' => [kolom => sel]]
    public array $merges = [];      // [[r1, c1, r2, c2], ...]
    public array $repeatRows = [];  // [baris awal, baris akhir] diulang di tiap halaman cetak
    public array $keepNext = [];    // baris => true : jangan dipisah dari baris berikutnya
    public int $freezeRow = 0;      // jumlah baris beku
    public int $freezeCol = 0;
    public string $paper = 'a4';    // a4 | legal
    public string $orient = 'auto';     // auto | portrait | landscape (auto: potret bila lebar kolom muat di kertas potret)
    public bool $showGrid = false;
    public ?string $footerNote = null;

    public function __construct(public string $name)
    {
    }

    /** Lebar total semua kolom dalam poin (lebar Excel: piksel = karakter*7+5, poin = piksel*0.75). */
    public function widthPt(): float
    {
        $t = 0.0;
        for ($c = 1; $c <= $this->cols(); $c++) {
            $t += ((($this->colWidths[$c] ?? 8.43) * 7) + 5) * 0.75;
        }
        return $t;
    }

    public function orientation(): string
    {
        if ($this->orient !== 'auto') {
            return $this->orient;
        }
        // area cetak potret kertas Legal/A4 ≈ 576 poin; ambang 720 poin berarti skala minimal ≈ 80%
        return $this->widthPt() <= 720 ? 'portrait' : 'landscape';
    }

    public function widths(array $w): self
    {
        foreach (array_values($w) as $i => $x) {
            $this->colWidths[$i + 1] = (float) $x;
        }
        return $this;
    }

    /**
     * Lebarkan kolom $c agar isi terpanjang di baris $r1..$r2 muat satu baris (diukur dengan metrik font PDF),
     * paling lebar $max karakter; lebar yang sudah ada menjadi batas bawah.
     */
    public function fit(int $c, int $r1, int $r2, float $max): self
    {
        $need = 0.0;
        for ($r = $r1; $r <= $r2; $r++) {
            $cell = $this->rows[$r]['cells'][$c] ?? null;
            if (!$cell) {
                continue;
            }
            $st = Fmt::style($cell['s']);
            foreach (preg_split('/\r\n|\r|\n/', Fmt::display($cell, $st)[0]) as $line) {
                $need = max($need, PdfWriter::textWidth($line, (bool) $st['bold'], (float) $st['size']));
            }
        }
        // poin = (karakter*7+5)*0.75; +12 poin untuk bantalan sel (pembungkusan memakai lebar-5) agar teks tidak mepet garis
        $w = (($need + 12) / 0.75 - 5) / 7;
        $this->colWidths[$c] = max($this->colWidths[$c] ?? 8.43, min($max, round($w, 2)));
        return $this;
    }

    public function cols(): int
    {
        return $this->colWidths ? max(array_keys($this->colWidths)) : 1;
    }

    public function lastRow(): int
    {
        return $this->rows ? max(array_keys($this->rows)) : 0;
    }

    /** Isi satu sel. $type null = ditentukan dari nilai (angka berbentuk string desimal => num). */
    public function set(int $r, int $c, $v = null, array $style = [], ?string $type = null, ?string $formula = null): self
    {
        if ($type === null) {
            $type = ($v === null || $v === '') ? 'text' : (is_int($v) || is_float($v) ? 'num' : 'text');
        }
        $this->rows[$r]['cells'][$c] = ['v' => $v === null ? null : (string) $v, 't' => $type, 's' => $style, 'f' => $formula];
        $this->rows[$r]['h'] ??= null;
        return $this;
    }

    /** Sel berisi angka (string desimal dari BCMath) — kosong bila null. */
    public function num(int $r, int $c, $v, array $style = [], ?string $formula = null): self
    {
        return $this->set($r, $c, ($v === null || $v === '') ? null : (string) $v, $style, 'num', $formula);
    }

    public function date(int $r, int $c, ?string $ymd, array $style = []): self
    {
        return $this->set($r, $c, $ymd ?: null, $style + ['fmt' => 'date'], 'date');
    }

    /** Beri gaya pada rentang kolom (sel kosong) agar border/warna tampil utuh. */
    public function paint(int $r, int $c1, int $c2, array $style): self
    {
        for ($c = $c1; $c <= $c2; $c++) {
            if (!isset($this->rows[$r]['cells'][$c])) {
                $this->set($r, $c, null, $style);
            }
        }
        return $this;
    }

    public function height(int $r, float $h): self
    {
        $this->rows[$r]['h'] = $h;
        $this->rows[$r]['cells'] ??= [];
        return $this;
    }

    public function merge(int $r1, int $c1, int $r2, int $c2): self
    {
        $this->merges[] = [$r1, $c1, $r2, $c2];
        return $this;
    }

    public function keep(int $r): self
    {
        $this->keepNext[$r] = true;
        return $this;
    }

    public function blank(int $r, ?float $h = null): self
    {
        $this->rows[$r]['cells'] ??= [];
        $this->rows[$r]['h'] = $h;
        return $this;
    }
}
