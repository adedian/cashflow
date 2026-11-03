<?php
declare(strict_types=1);
/**
 * Buat backup database + verifikasi (bisa dijadwalkan lewat Windows Task Scheduler / cron).
 *   php database/tools/backup.php
 * Hasil: storage/backups/backup-YYYYMMDD-HHMMSS.sql (+ .json berisi SHA-256 & hasil verifikasi).
 * Exit code 0 = backup dibuat DAN terverifikasi; 1 = gagal.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\Audit;
use App\Services\BackupService;

try {
    $m = BackupService::create('cli');
    Audit::log('backup_create', 'backup', $m['file'], 'Backup via CLI: ' . $m['file'] . ($m['verified'] ? ' (terverifikasi)' : ' (GAGAL verifikasi)'), null, ['size' => $m['size'], 'sha256' => $m['sha256']], null, 'cli');
    echo ($m['verified'] ? 'OK ' : 'GAGAL VERIFIKASI ') . $m['file'] . ' ' . $m['size'] . " bytes sha256=" . $m['sha256'] . "\n";
    foreach ($m['problems'] as $p) {
        echo " - $p\n";
    }
    exit($m['verified'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
