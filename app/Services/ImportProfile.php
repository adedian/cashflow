<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Profil impor workbook asli: pemetaan sheet rekening, nama proyek kanonik, dan aturan kategori khusus usaha.
 * Data usaha nyata ada di config/import_profile.php (tidak di-commit); bila tidak ada, dipakai contoh
 * config/import_profile.example.php.
 */
final class ImportProfile
{
    private static ?array $profile = null;

    public static function get(): array
    {
        if (self::$profile === null) {
            $file = APP_ROOT . '/config/import_profile.php';
            $p = is_file($file) ? require $file : require APP_ROOT . '/config/import_profile.example.php';
            self::$profile = $p + ['tx_sheets' => [], 'projects' => [], 'extra_suffix' => [], 'combined' => null, 'extra_kinds' => [], 'extra_rules' => []];
        }
        return self::$profile;
    }
}
