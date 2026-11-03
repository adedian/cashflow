<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Services\XlsxReader;

/**
 * Pembaca nilai sel untuk Impor Excel berpemetaan: tanggal, nominal, teks. Murni (tanpa database), deterministik,
 * dan tidak pernah mengeksekusi formula/makro — hanya membaca teks/angka tersimpan.
 */
final class ImportParser
{
    private const MONTHS = [
        'januari' => 1, 'jan' => 1, 'january' => 1, 'februari' => 2, 'feb' => 2, 'pebruari' => 2, 'february' => 2,
        'maret' => 3, 'mar' => 3, 'march' => 3, 'april' => 4, 'apr' => 4, 'mei' => 5, 'may' => 5, 'juni' => 6, 'jun' => 6, 'june' => 6,
        'juli' => 7, 'jul' => 7, 'july' => 7, 'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8, 'august' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'oct' => 10, 'october' => 10,
        'november' => 11, 'nov' => 11, 'nop' => 11, 'desember' => 12, 'des' => 12, 'dec' => 12, 'december' => 12,
    ];

    /** Teks sel dengan spasi berlebih dirapikan (NBSP -> spasi biasa). */
    public static function text($v): string
    {
        $s = str_replace("\xC2\xA0", ' ', (string) $v);
        return trim(preg_replace('/[ \t]+/', ' ', $s) ?? $s);
    }

    /** Normalisasi untuk pencocokan header/identitas: huruf kecil, tanda baca -> spasi, spasi tunggal. */
    public static function norm($v): string
    {
        $s = mb_strtolower(self::text($v));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Huruf kolom Excel dari indeks 0-based: 0 => A, 26 => AA. */
    public static function colLetter(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /**
     * Tanggal -> 'Y-m-d' atau null. Menerima serial Excel, ISO (Y-m-d), d/m/Y, d-m-Y, d.m.Y, dan "15 Mei 2025".
     * Pola angka/angka/tahun dibaca HARI/BULAN/TAHUN (kebiasaan Indonesia); bila bulan > 12 dianggap tidak valid.
     */
    public static function date(string $raw, bool $isText = true): ?string
    {
        $raw = self::text($raw);
        if ($raw === '') {
            return null;
        }
        if (!$isText && is_numeric($raw)) {
            return XlsxReader::serialToDate($raw);
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $raw, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $raw, $m)) {
            $y = (int) $m[3];
            if (strlen($m[3]) === 2) {
                $y += 2000;
            }
            return self::ymd($y, (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})[ \-.]+([\p{L}]+)\.?[ \-.,]+(\d{2}|\d{4})$/u', $raw, $m)) {
            $mon = self::MONTHS[mb_strtolower($m[2])] ?? null;
            if ($mon !== null) {
                $y = (int) $m[3];
                if (strlen($m[3]) === 2) {
                    $y += 2000;
                }
                return self::ymd($y, $mon, (int) $m[1]);
            }
        }
        // serial Excel yang tersimpan sebagai teks (mis. dari CSV)
        if (preg_match('/^\d{5}(\.\d+)?$/', $raw)) {
            return XlsxReader::serialToDate($raw);
        }
        return null;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if ($y < 2000 || $y > 2099 || !checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Nominal -> ['ok'=>bool, 'val'=>?string]. val = string desimal 2 digit; null bila sel kosong ("" atau "-").
     * $isText = true untuk sel teks / CSV: pemisah ribuan & desimal ditafsirkan ("1.250.000,50", "1,250,000.50", "Rp 1.250").
     * Sel angka Excel (bukan teks) dibaca apa adanya. "(1.000)" = negatif.
     */
    public static function amount(string $raw, bool $isText = true): array
    {
        $s = self::text($raw);
        if ($s === '' || $s === '-' || $s === '–') {
            return ['ok' => true, 'val' => null];
        }
        if (!$isText) {
            $v = is_numeric($s) ? self::fit(XlsxReader::money($s)) : null;
            return $v === null ? ['ok' => false, 'val' => null] : ['ok' => true, 'val' => $v];
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $neg = true;
            $s = $m[1];
        }
        $s = preg_replace('/^(rp\.?|idr)\s*/i', '', $s) ?? $s;
        $s = str_replace(' ', '', $s);
        if (str_starts_with($s, '-')) {
            $neg = !$neg;
            $s = substr($s, 1);
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }
        if (!preg_match('/^\d[\d.,]*$/', $s)) {
            return ['ok' => false, 'val' => null];
        }
        $dot = substr_count($s, '.');
        $com = substr_count($s, ',');
        if ($dot && $com) {
            $decSep = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $thou = $decSep === '.' ? ',' : '.';
            $s = str_replace($thou, '', $s);
            $s = str_replace($decSep, '.', $s);
        } elseif ($com) {
            if (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
                $s = str_replace(',', '', $s);                 // 1,250,000 (ribuan gaya Inggris)
            } elseif (preg_match('/^\d+,\d{1,2}$/', $s)) {
                $s = str_replace(',', '.', $s);                // 1250,5 (desimal Indonesia)
            } else {
                return ['ok' => false, 'val' => null];
            }
        } elseif ($dot) {
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
                $s = str_replace('.', '', $s);                 // 1.250.000 / 1.250 (ribuan Indonesia)
            } elseif (!preg_match('/^\d+\.\d{1,2}$/', $s)) {
                return ['ok' => false, 'val' => null];
            }
        }
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) {
            return ['ok' => false, 'val' => null];
        }
        $v = bcadd($s, '0', 2);
        return ['ok' => true, 'val' => $neg && bccomp($v, '0', 2) !== 0 ? '-' . $v : $v];
    }

    /** Batasi nominal ke rentang kolom DECIMAL(18,2) yang aman dipakai aplikasi (± 9.999.999.999.999,99). */
    private static function fit(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        return (bccomp($v, '9999999999999.99', 2) > 0 || bccomp($v, '-9999999999999.99', 2) < 0) ? null : $v;
    }

    /** Potong teks ke batas kolom; $cut diisi true bila terpotong. */
    public static function limit(string $s, int $n, ?bool &$cut = null): string
    {
        $cut = mb_strlen($s) > $n;
        return $cut ? mb_substr($s, 0, $n) : $s;
    }

    /** Netralkan awal sel yang dapat dibaca sebagai formula oleh Excel/Sheets (=, +, -, @, tab, CR) pada ekspor CSV. */
    public static function csvSafe($v): string
    {
        $s = (string) $v;
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+([.,]\d+)?$/', $s)) {
            return "'" . $s;
        }
        return $s;
    }
}
