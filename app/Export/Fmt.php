<?php
declare(strict_types=1);

namespace App\Export;

/** Format tampilan sel (PDF & pratinjau) + gaya bawaan. Angka diproses sebagai string desimal (BCMath), bukan float. */
final class Fmt
{
    public const MONTHS = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** Kode format angka Excel untuk tiap kunci fmt (sama dengan buku Excel sumber). */
    public const XL = [
        'int' => '#,##0',
        'acc0' => '_(* #,##0_);_(* \(#,##0\);_(* "-"??_);_(@_)',
        'acc2' => '_(* #,##0.00_);_(* \(#,##0.00\);_(* "-"??_);_(@_)',
        'bal2' => '#,##0.00_ ;[Red]\-#,##0.00\ ',
        'date' => 'd\ mmm\ yyyy',
    ];

    public static function style(array $s): array
    {
        return $s + [
            'font' => 'Calibri', 'size' => 11, 'bold' => false, 'italic' => false, 'color' => '000000',
            'fill' => null, 'border' => '', 'h' => null, 'v' => 'center', 'wrap' => false, 'fmt' => 'text',
        ];
    }

    /** "1234567.5" -> ['-' atau '', '1.234.567', '50'] */
    private static function parts(string $v, int $dec): array
    {
        $v = bcadd($v, '0', $dec);
        $neg = $v !== '' && $v[0] === '-';
        if ($neg) {
            $v = substr($v, 1);
        }
        [$int, $d] = array_pad(explode('.', $v), 2, '');
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $int);
        $zero = trim($v, '0.') === '';
        return [$neg && !$zero, $int, $d, $zero];
    }

    /** @return array{0:string,1:?string} teks tampilan dan warna paksa (null = ikut gaya) */
    public static function display(array $cell, array $style): array
    {
        $v = $cell['v'];
        if ($v === null || $v === '') {
            return ['', null];
        }
        $fmt = $style['fmt'] ?? 'text';
        if ($cell['t'] === 'date') {
            $ts = strtotime((string) $v);
            return [$ts ? (int) date('j', $ts) . ' ' . self::MONTHS[(int) date('n', $ts)] . ' ' . date('Y', $ts) : (string) $v, null];
        }
        if ($cell['t'] !== 'num') {
            return [(string) $v, null];
        }
        $v = (string) $v;
        switch ($fmt) {
            case 'acc2':
            case 'acc0':
                [$neg, $int, $d, $zero] = self::parts($v, $fmt === 'acc2' ? 2 : 0);
                if ($zero) {
                    return ['-', null];
                }
                $t = $int . ($d !== '' ? ',' . $d : '');
                return [$neg ? '(' . $t . ')' : $t, null];
            case 'bal2':
                [$neg, $int, $d] = self::parts($v, 2);
                return [($neg ? '-' : '') . $int . ',' . $d, $neg ? 'FF0000' : null];
            case 'int':
                [$neg, $int] = self::parts($v, 0);
                return [($neg ? '-' : '') . $int, null];
            default:
                return [$v, null];
        }
    }

    /** Serial tanggal Excel dari Y-m-d. */
    public static function serial(string $ymd): ?int
    {
        $ts = strtotime($ymd . ' 00:00:00 UTC');
        return $ts === false ? null : (int) floor($ts / 86400) + 25569;
    }

    /** Rata default menurut jenis sel. */
    public static function align(array $cell, array $style): string
    {
        if ($style['h']) {
            return $style['h'];
        }
        return $cell['t'] === 'num' ? 'right' : 'left';
    }
}
