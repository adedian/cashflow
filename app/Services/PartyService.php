<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Master "Pihak" (penerima/pembayar) untuk dropdown di formulir Transaksi. transactions.counterparty tetap menyimpan salinan teks
 * (laporan, ekspor, pencarian tidak berubah); master memastikan nama konsisten (tanpa variasi ketikan).
 * Pencocokan nama tidak peka huruf besar/kecil (kolasi utf8mb4_unicode_ci).
 */
final class PartyService
{
    public static function clean(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $name)), 0, 255);
    }

    /** Nama baku di master (huruf sesuai master) atau null bila belum ada. */
    public static function canonical(string $name): ?string
    {
        $n = self::clean($name);
        if ($n === '') {
            return null;
        }
        $v = Db::val('SELECT name FROM parties WHERE name = ?', [$n]);
        return $v === null ? null : (string) $v;
    }

    /** Pastikan ada di master (idempoten); mengembalikan ID. */
    public static function ensure(string $name): ?int
    {
        $n = self::clean($name);
        if ($n === '') {
            return null;
        }
        $id = Db::val('SELECT id FROM parties WHERE name = ?', [$n]);
        return $id ? (int) $id : Db::insert('parties', ['name' => $n]);
    }

    /** Masukkan semua nama pihak di transaksi yang belum ada di master (dipakai setelah impor). */
    public static function syncFromTransactions(): int
    {
        return Db::q("INSERT IGNORE INTO parties (name) SELECT DISTINCT TRIM(counterparty) FROM transactions WHERE counterparty IS NOT NULL AND TRIM(counterparty) <> ''")->rowCount();
    }

    /** Daftar untuk dropdown: aktif + (opsional) nilai yang sedang dipakai walau nonaktif. */
    public static function options(?string $keep = null): array
    {
        return Db::all('SELECT id, name, is_active FROM parties WHERE is_active = 1 OR name = ? ORDER BY name', [(string) $keep]);
    }
}
