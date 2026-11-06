<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

/** Membaca & memvalidasi filter dari query string (semua nilai divalidasi sebelum dipakai di SQL terikat). */
final class Filters
{
    public static function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $v;
    }

    /** Periode bawaan: tahun dari transaksi terakhir (atau tahun berjalan bila belum ada data). */
    public static function defaultPeriod(): array
    {
        $last = LedgerService::lastTxDate();
        $y = $last ? (int) substr($last, 0, 4) : (int) date('Y');
        return [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)];
    }

    public static function dashboard(Request $r): array
    {
        [$df, $dt] = self::defaultPeriod();
        $all = $r->str('period') === 'all';
        $from = $all ? null : (self::date($r->str('from')) ?? $df);
        $to = $all ? null : (self::date($r->str('to')) ?? $dt);
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $acc = $r->int('account');
        return ['from' => $from, 'to' => $to, 'account' => $acc > 0 ? $acc : null, 'period' => $all ? 'all' : ''];
    }

    public static function transactions(Request $r): array
    {
        $from = self::date($r->str('from'));
        $to = self::date($r->str('to'));
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $dir = $r->str('dir');
        $kind = $r->str('kind');
        $q = mb_substr($r->str('q'), 0, 100);
        return [
            'from' => $from, 'to' => $to,
            'account' => $r->int('account') ?: null, 'category' => $r->int('category') ?: null, 'project' => $r->int('project') ?: null,
            'dir' => in_array($dir, ['in', 'out'], true) ? $dir : '',
            'kind' => in_array($kind, ['pendapatan', 'biaya', 'pajak', 'pembiayaan', 'transfer', 'lainnya'], true) ? $kind : '',
            'party' => mb_substr(trim($r->str('party')), 0, 190),
            'q' => $q, 'flagged' => $r->str('flagged') === '1',
        ];
    }
}
