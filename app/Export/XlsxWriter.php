<?php
declare(strict_types=1);

namespace App\Export;

use ZipArchive;

/** Penulis .xlsx minimal (tanpa pustaka luar): ZipArchive + XML SpreadsheetML. */
final class XlsxWriter
{
    private array $fonts = [];
    private array $fills = [];
    private array $borders = [];
    private array $numFmts = [];
    private array $xfs = [];

    public static function build(Document $doc): string
    {
        return (new self())->make($doc);
    }

    private static function x(?string $s): string
    {
        $s = (string) $s;
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function col(int $c): string
    {
        $s = '';
        while ($c > 0) {
            $m = ($c - 1) % 26;
            $s = chr(65 + $m) . $s;
            $c = intdiv($c - 1, 26);
        }
        return $s;
    }

    /* ---------- registri gaya ---------- */
    private function font(array $s): int
    {
        $k = implode('|', [$s['font'], $s['size'], (int) $s['bold'], (int) $s['italic'], $s['color']]);
        return $this->fonts[$k] ??= count($this->fonts);
    }

    private function fill(?string $c): int
    {
        if ($c === null) {
            return 0;
        }
        return $this->fills[$c] ??= count($this->fills) + 2; // 0 = none, 1 = gray125
    }

    private function border(string $b): int
    {
        if ($b === '') {
            return 0;
        }
        $b = implode('', array_unique(str_split($b)));
        return $this->borders[$b] ??= count($this->borders) + 1;
    }

    private function numFmt(string $f): int
    {
        if ($f === 'text') {
            return 0;
        }
        if ($f === 'int') {
            return 3;
        }
        return $this->numFmts[$f] ??= 164 + count($this->numFmts);
    }

    private function xf(array $style): int
    {
        $s = Fmt::style($style);
        $key = implode('|', [$this->font($s), $this->fill($s['fill']), $this->border($s['border']), $this->numFmt($s['fmt']), (string) $s['h'], $s['v'], (int) $s['wrap']]);
        if (!isset($this->xfs[$key])) {
            $this->xfs[$key] = ['i' => count($this->xfs) + 1, 's' => $s]; // 0 = gaya bawaan
        }
        return $this->xfs[$key]['i'];
    }

    /* ---------- pembuatan berkas ---------- */
    private function make(Document $doc): string
    {
        $sheetXml = [];
        foreach ($doc->sheets as $sh) {
            $sheetXml[] = $this->sheet($sh, $doc);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Tidak dapat membuat berkas Excel.');
        }
        $n = count($doc->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x($doc->title) . '</dc:title><dc:creator>' . self::x($doc->company) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Cashflow HME</Application></Properties>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $names = '';
        foreach ($doc->sheets as $i => $sh) {
            $id = $i + 1;
            $wb .= '<sheet name="' . self::x($sh->name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            if ($sh->repeatRows) {
                $q = "'" . str_replace("'", "''", $sh->name) . "'";
                $names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . self::x($q . '!$' . $sh->repeatRows[0] . ':$' . $sh->repeatRows[1]) . '</definedName>';
            }
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $wb .= '</sheets>' . ($names !== '' ? '<definedNames>' . $names . '</definedNames>' : '') . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($sheetXml as $i => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private function stylesXml(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($this->numFmts) {
            $x .= '<numFmts count="' . count($this->numFmts) . '">';
            foreach ($this->numFmts as $key => $id) {
                $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::x(Fmt::XL[$key] ?? 'General') . '"/>';
            }
            $x .= '</numFmts>';
        }
        // fonts: indeks 0 harus ada
        $fonts = $this->fonts ?: ['Calibri|11|0|0|000000' => 0];
        $x .= '<fonts count="' . count($fonts) . '">';
        foreach (array_keys($fonts) as $k) {
            [$name, $size, $b, $i, $color] = explode('|', $k);
            $x .= '<font>' . ($b ? '<b/>' : '') . ($i ? '<i/>' : '') . '<sz val="' . $size . '"/><color rgb="FF' . $color . '"/><name val="' . self::x($name) . '"/><family val="2"/></font>';
        }
        $x .= '</fonts>';
        $x .= '<fills count="' . (count($this->fills) + 2) . '"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach (array_keys($this->fills) as $c) {
            $x .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $c . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $x .= '</fills>';
        $x .= '<borders count="' . (count($this->borders) + 1) . '"><border><left/><right/><top/><bottom/><diagonal/></border>';
        foreach (array_keys($this->borders) as $b) {
            $side = static fn(string $ch, string $tag): string => str_contains($b, $ch) ? '<' . $tag . ' style="thin"><color rgb="FF000000"/></' . $tag . '>' : '<' . $tag . '/>';
            $x .= '<border>' . $side('l', 'left') . $side('r', 'right') . $side('t', 'top') . $side('b', 'bottom') . '<diagonal/></border>';
        }
        $x .= '</borders>';
        $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $x .= '<cellXfs count="' . (count($this->xfs) + 1) . '"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        foreach ($this->xfs as $xf) {
            $s = $xf['s'];
            $al = ($s['h'] ? ' horizontal="' . $s['h'] . '"' : '') . ' vertical="' . ($s['v'] === 'center' ? 'center' : $s['v']) . '"' . ($s['wrap'] ? ' wrapText="1"' : '');
            $x .= '<xf numFmtId="' . $this->numFmt($s['fmt']) . '" fontId="' . $this->font($s) . '" fillId="' . $this->fill($s['fill']) . '" borderId="' . $this->border($s['border']) . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment' . $al . '/></xf>';
        }
        $x .= '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return $x;
    }

    private function sheet(Sheet $sh, Document $doc): string
    {
        $maxR = max(1, $sh->lastRow());
        $maxC = max(1, $sh->cols());
        foreach ($sh->rows as $row) {
            foreach (array_keys($row['cells'] ?? []) as $c) {
                $maxC = max($maxC, $c);
            }
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . self::col($maxC) . $maxR . '"/>';
        $x .= '<sheetViews><sheetView workbookViewId="0"' . ($sh->showGrid ? '' : ' showGridLines="0"') . ' zoomScale="85" zoomScaleNormal="85">';
        if ($sh->freezeRow > 0 || $sh->freezeCol > 0) {
            $tl = self::col($sh->freezeCol + 1) . ($sh->freezeRow + 1);
            $pane = $sh->freezeRow > 0 && $sh->freezeCol > 0 ? 'bottomRight' : ($sh->freezeRow > 0 ? 'bottomLeft' : 'topRight');
            $x .= '<pane' . ($sh->freezeCol > 0 ? ' xSplit="' . $sh->freezeCol . '"' : '') . ($sh->freezeRow > 0 ? ' ySplit="' . $sh->freezeRow . '"' : '') . ' topLeftCell="' . $tl . '" activePane="' . $pane . '" state="frozen"/><selection pane="' . $pane . '" activeCell="' . $tl . '" sqref="' . $tl . '"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($sh->colWidths) {
            $x .= '<cols>';
            ksort($sh->colWidths);
            foreach ($sh->colWidths as $c => $w) {
                $x .= '<col min="' . $c . '" max="' . $c . '" width="' . round($w, 2) . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($sh->rows);
        foreach ($sh->rows as $r => $row) {
            $ht = $row['h'] ?? null;
            $x .= '<row r="' . $r . '"' . ($ht ? ' ht="' . round($ht, 2) . '" customHeight="1"' : '') . '>';
            $cells = $row['cells'] ?? [];
            ksort($cells);
            foreach ($cells as $c => $cell) {
                $ref = self::col($c) . $r;
                $s = $this->xf($cell['s']);
                $v = $cell['v'];
                if ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '" s="' . $s . '"/>';
                } elseif ($cell['t'] === 'num' && is_numeric($v)) {
                    $f = $cell['f'] ? '<f>' . self::x(ltrim((string) $cell['f'], '=')) . '</f>' : '';
                    $x .= '<c r="' . $ref . '" s="' . $s . '">' . $f . '<v>' . self::x($v) . '</v></c>';
                } elseif ($cell['t'] === 'date' && ($ser = Fmt::serial((string) $v)) !== null) {
                    $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $ser . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::x(mb_substr((string) $v, 0, 32000)) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh->merges) {
            $x .= '<mergeCells count="' . count($sh->merges) . '">';
            foreach ($sh->merges as [$r1, $c1, $r2, $c2]) {
                $x .= '<mergeCell ref="' . self::col($c1) . $r1 . ':' . self::col($c2) . $r2 . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.5" header="0.2" footer="0.25"/>';
        $paper = $sh->paper === 'legal' ? 5 : 9;
        $x .= '<pageSetup paperSize="' . $paper . '" orientation="' . ($sh->orientation()) . '" fitToWidth="1" fitToHeight="0"/>';
        $x .= '<headerFooter><oddFooter>&amp;L&amp;8' . self::x(str_replace('&', '&&', $doc->company . ' · Dicetak ' . $doc->printedAt . ($doc->printedBy !== '' ? ' oleh ' . $doc->printedBy : ''))) . '&amp;R&amp;8Halaman &amp;P dari &amp;N</oddFooter></headerFooter>';
        return $x . '</worksheet>';
    }
}
