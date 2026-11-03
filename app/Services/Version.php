<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Penghitung versi untuk sinkronisasi realtime.
 * bump() harus dipanggil di dalam transaksi DB yang sama dengan perubahan data,
 * sehingga perubahan baru "diumumkan" setelah COMMIT.
 */
final class Version
{
    public const SCOPES = ['transactions', 'notes', 'master', 'invoices', 'forecast', 'debts'];

    public static function bump(string $scope): void
    {
        Db::q('INSERT INTO data_versions (scope, version) VALUES (?, 1) ON DUPLICATE KEY UPDATE version = version + 1', [$scope]);
    }

    public static function all(): array
    {
        $out = [];
        foreach (Db::all('SELECT scope, version FROM data_versions') as $r) {
            $out[$r['scope']] = (int) $r['version'];
        }
        return $out;
    }
}
