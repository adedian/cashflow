<?php
declare(strict_types=1);
/**
 * Impor workbook Cashflow HME.
 *   php database/tools/import-excel.php "C:\path\file.xlsx"            -> hanya PRATINJAU/validasi (tidak menulis DB)
 *   php database/tools/import-excel.php "C:\path\file.xlsx" --commit --user=USERNAME_ADMIN   -> simpan ke database
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\ImportService;

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Berkas tidak ditemukan.\n"); exit(1); }
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if ($a === '--commit') { $opts['commit'] = true; }
    elseif (str_starts_with($a, '--user=')) { $opts['user'] = substr($a, 7); }
    elseif ($a === '--system') { $opts['system'] = true; }
}
// salin dulu ke storage/cache (berkas di OneDrive kadang tidak bisa dibuka langsung oleh ZipArchive)
$tmp = APP_ROOT . '/storage/cache/import-' . getmypid() . '.xlsx';
if (!copy($file, $tmp)) { fwrite(STDERR, "Gagal menyalin berkas.\n"); exit(1); }
$svc = new ImportService($tmp);
register_shutdown_function(static function () use ($svc, $tmp) {
    $svc->close();
    @unlink($tmp);
});
$p = $svc->parse();

echo "=== PRATINJAU IMPOR ===\n";
foreach ($p['stats'] as $code => $s) {
    printf("%-10s %-22s baris=%4d masuk=%18s keluar=%18s awal=%16s hitung=%18s excel=%18s %s\n", $code, $s['sheet'], $s['rows'],
        number_format((float) $s['sum_debit'], 2, ',', '.'), number_format((float) $s['sum_credit'], 2, ',', '.'),
        number_format((float) $s['opening'], 2, ',', '.'), number_format((float) $s['calc_final'], 2, ',', '.'),
        $s['excel_final'] === null ? 'n/a' : number_format((float) $s['excel_final'], 2, ',', '.'), $s['match'] ? 'COCOK' : 'TIDAK COCOK');
}
printf("Proyeksi: %d item | Invoice: %d | Kategori mentah: %d\n", count($p['forecast']), count($p['invoices']), count($p['category_map']));
$cnt = ['error' => 0, 'warn' => 0];
foreach ($p['issues'] as $i) { $cnt[$i['level']]++; printf("[%s] %s baris %s: %s\n", strtoupper($i['level']), $i['sheet'], $i['row'], $i['msg']); }
echo "Kesalahan: {$cnt['error']} | Peringatan: {$cnt['warn']}\n";
echo "Tidak diimpor: " . implode(' | ', $p['skipped']) . "\n";

if (isset($opts['commit'])) {
    if ($p['errors'] > 0) { fwrite(STDERR, "Ada kesalahan; tidak disimpan.\n"); exit(2); }
    $uid = null;   // --system: dicatat sebagai proses sistem (CLI), bukan atas nama pengguna
    if (!isset($opts['system'])) {
        $u = Db::one('SELECT id FROM users WHERE username = ? AND role = ?', [(string) ($opts['user'] ?? ''), 'super_admin']);
        if (!$u) { fwrite(STDERR, "Gunakan --system atau --user=<akun super_admin yang ada>.\n"); exit(1); }
        $uid = (int) $u['id'];
    }
    $id = ImportService::commit($p, $uid, basename($file), hash_file('sha256', $file));
    echo "Impor selesai: batch #$id\n";
}
