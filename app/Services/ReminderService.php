<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pengingat pembayaran. Sumber = (1) item Proyeksi berjenis PENGELUARAN yang aktif, punya tanggal jatuh tempo
 * (expected_date) dan belum ditandai dibayar (paid_at IS NULL), dan (2) Hutang (usaha / direksi / lain-lain) yang punya tanggal
 * jatuh tempo dan masih ada sisa. Item Proyeksi yang sudah ditautkan ke Hutang tidak dihitung dua kali. Item tanpa tanggal tidak pernah ditebak.
 * Mutasi keluar biasa BUKAN tagihan, jadi tidak ikut diingatkan. Pemasukan juga tidak.
 *
 * Jendela: dari hari ini sampai hari ini + N hari kalender (N = pengaturan reminder_days, bawaan 11),
 * ditambah semua yang sudah lewat tanggal dan belum dibayar (Terlambat). Hari dihitung pada zona waktu aplikasi.
 */
final class ReminderService
{
    public const FORECAST_LABEL = 'Estimasi Biaya (Proyeksi)';
    public const DEFAULT_DAYS = 11;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 60;
    /** Selisih hari <= nilai ini dianggap mendesak (nada amber penuh). */
    private const URGENT_DAYS = 3;

    public static function days(): int
    {
        $d = (int) setting('reminder_days', (string) self::DEFAULT_DAYS);
        return $d >= self::MIN_DAYS && $d <= self::MAX_DAYS ? $d : self::DEFAULT_DAYS;
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    }

    /** Selisih hari kalender (due − today); negatif = sudah lewat. Bebas DST/pergantian bulan. */
    public static function daysLeft(string $due, string $today): int
    {
        $tz = new DateTimeZone('UTC');
        $a = new DateTimeImmutable($today, $tz);
        $b = new DateTimeImmutable($due, $tz);
        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * @return array{status:string,label:string,tone:string,rel:string,priority:string}
     */
    public static function classify(int $left): array
    {
        if ($left < 0) {
            $n = -$left;
            return ['status' => 'late', 'label' => 'Terlambat', 'tone' => 'red', 'rel' => 'Terlambat ' . $n . ' hari', 'priority' => 'high'];
        }
        if ($left === 0) {
            return ['status' => 'today', 'label' => 'Jatuh Tempo Hari Ini', 'tone' => 'amber', 'rel' => 'Hari ini', 'priority' => 'high'];
        }
        $urgent = $left <= self::URGENT_DAYS;
        return [
            'status' => 'soon', 'label' => 'Segera Jatuh Tempo', 'tone' => $urgent ? 'amber' : 'neutral',
            'rel' => $left === 1 ? 'Besok' : $left . ' hari lagi', 'priority' => $urgent ? 'medium' : 'low',
        ];
    }

    /**
     * Daftar pengingat aktif, terurut: terlambat (tertua dulu) → hari ini → mendatang. Satu baris = satu item (tanpa duplikat).
     * @return array{today:string,days:int,count:int,late:int,total:string,items:array<int,array>,categories:array<int,array>}
     */
    public static function active(?string $today = null, ?int $days = null): array
    {
        $today ??= self::today();
        $days ??= self::days();
        $until = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d');
        // item Proyeksi yang sudah ditautkan ke sebuah Hutang aktif dilewati: hutangnya yang mengingatkan (tanpa notifikasi ganda)
        $rows = Db::all(
            "SELECT f.id, f.description, f.account_hint, f.amount - COALESCE(r.realized, 0) AS amount, f.amount AS plan, COALESCE(r.realized, 0) AS realized, f.expected_date
             FROM forecast_items f LEFT JOIN (" . TxLinkService::FC_REAL_SUB . ") r ON r.forecast_id = f.id
             WHERE f.direction = 'out' AND f.is_active = 1 AND f.paid_at IS NULL AND f.closed_at IS NULL AND f.expected_date IS NOT NULL AND f.expected_date <= ?
               AND f.id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL AND voided_at IS NULL)
             ORDER BY f.expected_date, f.id",
            [$until]
        );
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['kind' => 'forecast', 'cat' => 'estimasi', 'cat_label' => self::FORECAST_LABEL, 'id' => (int) $r['id'], 'href' => '/forecast/' . (int) $r['id'], 'description' => (string) $r['description'],
                'name' => (string) $r['description'], 'note' => null, 'ref' => null, 'total' => bcadd((string) $r['plan'], '0', 2), 'paid' => bcadd((string) $r['realized'], '0', 2),
                'account' => $r['account_hint'], 'amount' => (string) $r['amount'], 'due' => (string) $r['expected_date']];
        }
        // Hutang: hanya yang berjatuh tempo (tanggal tersedia), belum lunas, dan tidak dibatalkan; nominal = SISA hutang
        $debts = Db::all(
            "SELECT d.id, d.category, d.supplier, d.description, d.ref_no, d.source_account, d.due_date, d.amount AS total, COALESCE(p.paid, 0) AS paid, d.amount - COALESCE(p.paid, 0) AS remaining
             FROM debts d LEFT JOIN (" . TxLinkService::DEBT_PAID_SUB . ") p ON p.debt_id = d.id
             WHERE d.voided_at IS NULL AND d.due_date IS NOT NULL AND d.due_date <= ? AND d.amount - COALESCE(p.paid, 0) > 0.004
             ORDER BY d.due_date, d.id",
            [$until]
        );
        foreach ($debts as $r) {
            $list[] = ['kind' => 'debt', 'cat' => (string) $r['category'], 'cat_label' => DebtService::CATEGORIES[$r['category']] ?? 'Hutang', 'tag' => DebtService::CATEGORIES[$r['category']] ?? 'Hutang',
                'id' => (int) $r['id'], 'href' => '/debts/' . (int) $r['id'],
                'description' => (string) $r['supplier'] . ($r['description'] !== null && $r['description'] !== '' ? ' — ' . $r['description'] : ''),
                'name' => (string) $r['supplier'], 'note' => ($r['description'] ?? '') !== '' ? (string) $r['description'] : null, 'ref' => ($r['ref_no'] ?? '') !== '' ? (string) $r['ref_no'] : null,
                'total' => bcadd((string) $r['total'], '0', 2), 'paid' => bcadd((string) $r['paid'], '0', 2),
                'account' => $r['source_account'], 'amount' => bcadd((string) $r['remaining'], '0', 2), 'due' => (string) $r['due_date']];
        }
        usort($list, static fn($a, $b) => [$a['due'], $a['kind'], $a['id']] <=> [$b['due'], $b['kind'], $b['id']]);

        $items = [];
        $late = 0;
        $total = '0.00';
        foreach ($list as $r) {
            $left = self::daysLeft($r['due'], $today);
            $c = self::classify($left);
            if ($c['status'] === 'late') {
                $late++;
            }
            $total = bcadd($total, $r['amount'], 2);
            $r['part'] = bccomp((string) $r['paid'], '0.004', 3) > 0;
            $r['pay_status'] = $r['part'] ? 'Dibayar sebagian' : 'Belum dibayar';
            $items[] = $r + ['days_left' => $left] + $c;
        }
        return ['today' => $today, 'days' => $days, 'count' => count($items), 'late' => $late, 'total' => $total, 'items' => $items, 'categories' => self::categories($items)];
    }

    /**
     * Ringkasan per kategori (kartu): selalu semua kategori (yang kosong tampil redup) — Hutang Usaha, Hutang Direksi,
     * Hutang Lain-lain, dan Estimasi Biaya (Proyeksi).
     * @return array<int,array{key:string,label:string,dir:string,count:int,total:string,late:int,today:int,soon:int,urgent:int,next_due:?string}>
     */
    public static function categories(array $items): array
    {
        $labels = DebtService::CATEGORIES + ['estimasi' => self::FORECAST_LABEL];
        $cats = [];
        foreach ($labels as $k => $label) {
            $cats[$k] = ['key' => $k, 'label' => $label, 'dir' => 'out', 'count' => 0, 'total' => '0.00', 'late' => 0, 'today' => 0, 'soon' => 0, 'urgent' => 0, 'next_due' => null];
        }
        foreach ($items as $it) {
            $c = &$cats[$it['cat']];
            $c['count']++;
            $c['total'] = bcadd($c['total'], (string) $it['amount'], 2);
            match ($it['status']) { 'late' => $c['late']++, 'today' => $c['today']++, default => $c['soon']++ };
            if ($it['status'] === 'soon' && $it['priority'] === 'medium') {
                $c['urgent']++;
            }
            if ($c['next_due'] === null || $it['due'] < $c['next_due']) {
                $c['next_due'] = $it['due'];
            }
            unset($c);
        }
        return array_values($cats);
    }
}
