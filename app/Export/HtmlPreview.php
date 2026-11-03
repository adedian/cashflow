<?php
declare(strict_types=1);

namespace App\Export;

/** Pratinjau "tampilan Excel" (HTML) dari model Sheet yang sama dengan berkas .xlsx. Semua teks di-escape. */
final class HtmlPreview
{
    public const MAX_ROWS = 300;

    /** @var array<string,string> deklarasi CSS => nama kelas (menghindari gaya inline berulang) */
    private static array $css = [];

    private static function colName(int $c): string
    {
        $s = '';
        while ($c > 0) {
            $m = ($c - 1) % 26;
            $s = chr(65 + $m) . $s;
            $c = intdiv($c - 1, 26);
        }
        return $s;
    }

    /** @return array{html:string,truncated:bool,sheets:int} */
    public static function render(Document $doc, int $maxRows = self::MAX_ROWS): array
    {
        $tabs = '';
        $body = '';
        $trunc = false;
        self::$css = [];
        foreach ($doc->sheets as $i => $sh) {
            $tabs .= '<button type="button" class="xl-tab' . ($i === 0 ? ' on' : '') . '" data-xl-tab="' . $i . '">' . htmlspecialchars($sh->name, ENT_QUOTES, 'UTF-8') . '</button>';
            [$html, $t] = self::sheet($sh, $maxRows);
            $trunc = $trunc || $t;
            $body .= '<div class="xl-pane' . ($i === 0 ? ' on' : '') . '" data-xl-pane="' . $i . '">' . $html . '</div>';
        }
        $rules = '';
        foreach (self::$css as $decl => $cls) {
            $rules .= '.xl td.' . $cls . '{' . $decl . '}';
        }
        $html = '<style>' . $rules . '</style><div class="xl-wrap">' . $body . '<div class="xl-tabs">' . $tabs . '</div></div>';
        return ['html' => $html, 'truncated' => $trunc, 'sheets' => count($doc->sheets)];
    }

    /** @return array{0:string,1:bool} */
    private static function sheet(Sheet $sh, int $maxRows): array
    {
        $maxC = max(1, $sh->cols());
        $last = max(1, $sh->lastRow());
        $show = min($last, $maxRows);
        $master = [];
        $covered = [];
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
        $px = static fn(float $w): int => (int) round($w * 7 + 5);
        $total = 42;
        $cols = '';
        for ($c = 1; $c <= $maxC; $c++) {
            $w = $px($sh->colWidths[$c] ?? 8.43);
            $total += $w;
            $cols .= '<col style="width:' . $w . 'px">';
        }
        // data-w = lebar asli (px); export.js menskalakan tabel agar muat lebar panel tanpa gulir horizontal
        $o = '<div class="xl-scroll"><table class="xl" style="width:' . $total . 'px" data-w="' . $total . '"><colgroup><col style="width:42px">' . $cols;
        $o .= '</colgroup><thead><tr><th class="xl-corner"></th>';
        for ($c = 1; $c <= $maxC; $c++) {
            $o .= '<th class="xl-ch">' . self::colName($c) . '</th>';
        }
        $o .= '</tr></thead><tbody>';
        for ($r = 1; $r <= $show; $r++) {
            $row = $sh->rows[$r] ?? ['h' => null, 'cells' => []];
            $h = $row['h'] ?? null;
            $o .= '<tr' . ($h ? ' style="height:' . round($h * 1.3333, 1) . 'px"' : '') . '><th class="xl-rn">' . $r . '</th>';
            for ($c = 1; $c <= $maxC; $c++) {
                if (isset($covered[$r . ',' . $c])) {
                    continue;
                }
                $cell = $row['cells'][$c] ?? null;
                $m = $master[$r . ',' . $c] ?? null;
                $span = $m ? ($m[3] > $m[1] ? ' colspan="' . ($m[3] - $m[1] + 1) . '"' : '') . ($m[2] > $m[0] ? ' rowspan="' . ($m[2] - $m[0] + 1) . '"' : '') : '';
                if ($cell === null) {
                    $o .= '<td' . $span . '></td>';
                    continue;
                }
                $st = Fmt::style($cell['s']);
                [$text, $forced] = Fmt::display($cell, $st);
                $css = 'font-family:' . ($st['font'] === 'Comic Sans MS' ? "'Comic Sans MS','Comic Neue',cursive" : "Calibri,Carlito,'Segoe UI',Arial,sans-serif") . ';font-size:' . round($st['size'] * 1.3333, 1) . 'px;';
                $css .= 'color:#' . ($forced ?? $st['color']) . ';';
                if ($st['bold']) { $css .= 'font-weight:700;'; }
                if ($st['italic']) { $css .= 'font-style:italic;'; }
                if ($st['fill']) { $css .= 'background:#' . $st['fill'] . ';'; }
                $css .= 'text-align:' . Fmt::align($cell, $st) . ';vertical-align:' . ($st['v'] === 'center' ? 'middle' : $st['v']) . ';';
                $css .= $st['wrap'] ? 'white-space:pre-wrap;word-break:break-word;' : 'white-space:nowrap;overflow:hidden;text-overflow:clip;';
                foreach (['l' => 'left', 'r' => 'right', 't' => 'top', 'b' => 'bottom'] as $k => $side) {
                    if (str_contains($st['border'], $k)) {
                        $css .= 'border-' . $side . ':1px solid #000;';
                    }
                }
                $cls = (self::$css[$css] ??= 's' . count(self::$css));
                $o .= '<td' . $span . ' class="' . $cls . '">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            }
            $o .= '</tr>';
        }
        $o .= '</tbody></table></div>';
        return [$o, $last > $show];
    }
}
