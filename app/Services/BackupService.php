<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Backup database (mysqldump) ke storage/backups (nonpublik). Setiap backup diverifikasi:
 * footer "Dump completed", seluruh tabel ada, jumlah baris INSERT = jumlah baris di database saat backup, dan SHA-256 dicatat.
 * Backup berisi password hash pengguna -> jangan dibagikan; folder dilindungi .htaccess & hanya Super Admin dapat mengunduh.
 * Restore sungguhan (ke database terpisah) dilakukan lewat CLI: database/tools/restore.php (lihat docs/BACKUP_RESTORE.md).
 */
final class BackupService
{
    public static function dir(): string
    {
        $d = APP_ROOT . '/storage/backups';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^backup-\d{8}-\d{6}\.sql$/', $name);
    }

    public static function tables(): array
    {
        return array_column(Db::all('SHOW TABLES'), 'Tables_in_' . env('DB_NAME', 'cashflow_hme'));
    }

    /** @return array<string,int> jumlah baris tiap tabel */
    public static function liveCounts(): array
    {
        $c = [];
        foreach (self::tables() as $t) {
            $c[$t] = (int) Db::val('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`');
        }
        return $c;
    }

    public static function create(?string $by = null): array
    {
        $dump = (string) env('MYSQLDUMP_PATH', 'mysqldump');
        if (!is_file($dump)) {
            throw new RuntimeException('mysqldump tidak ditemukan. Atur MYSQLDUMP_PATH di .env.');
        }
        $name = 'backup-' . date('Ymd-His') . '.sql';
        $file = self::dir() . '/' . $name;
        $cmd = [
            $dump, '--host=' . env('DB_HOST', '127.0.0.1'), '--port=' . env('DB_PORT', '3306'), '--user=' . env('DB_USER'),
            '--single-transaction', '--skip-extended-insert', '--default-character-set=utf8mb4', '--result-file=' . $file, (string) env('DB_NAME'),
        ];
        $liveBefore = self::liveCounts();
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => (string) env('DB_PASS'), 'PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot')]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Tidak dapat menjalankan mysqldump.');
        }
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !is_file($file) || filesize($file) < 200) {
            @unlink($file);
            app_log('mysqldump gagal (' . $code . '): ' . trim((string) $err));
            throw new RuntimeException('Backup gagal dibuat. Detail teknis ada di log server.');
        }
        $liveAfter = self::liveCounts();
        $res = self::verify($file, $liveAfter);
        $meta = ['file' => $name, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file), 'created_at' => date('c'), 'created_by' => $by,
            'live_counts' => $liveAfter, 'dump_counts' => $res['counts'], 'verified' => $res['ok'], 'problems' => $res['problems'], 'live_changed_during_backup' => $liveBefore !== $liveAfter];
        file_put_contents($file . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($file, 0640);
        return $meta;
    }

    /** Verifikasi struktur & jumlah baris dump terhadap database hidup. */
    public static function verify(string $file, array $live): array
    {
        $problems = [];
        $counts = [];
        $footer = false;
        $createTables = [];
        $fh = fopen($file, 'rb');
        if (!$fh) {
            return ['ok' => false, 'problems' => ['Berkas tidak dapat dibaca'], 'counts' => []];
        }
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, 'INSERT INTO `')) {
                $t = substr($line, 13, strpos($line, '`', 13) - 13);
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            } elseif (str_starts_with($line, 'CREATE TABLE `')) {
                $createTables[] = substr($line, 14, strpos($line, '`', 14) - 14);
            } elseif (str_starts_with($line, '-- Dump completed')) {
                $footer = true;
            }
        }
        fclose($fh);
        if (!$footer) { $problems[] = 'Footer "Dump completed" tidak ada (dump terpotong)'; }
        foreach (array_keys($live) as $t) {
            if (!in_array($t, $createTables, true)) { $problems[] = "Tabel $t tidak ada di dump"; }
            $d = $counts[$t] ?? 0;
            if ($d !== $live[$t]) { $problems[] = "Jumlah baris $t: dump $d ≠ database {$live[$t]}"; }
        }
        return ['ok' => !$problems, 'problems' => $problems, 'counts' => $counts];
    }

    /** @return array<int,array> metadata backup, terbaru dahulu */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*.sql') ?: [] as $f) {
            if (!self::validName(basename($f))) {
                continue; // nama tak baku tidak bisa diunduh/dipulihkan lewat aplikasi; jangan ditampilkan sebagai tautan mati
            }
            $meta = is_file($f . '.json') ? json_decode((string) file_get_contents($f . '.json'), true) : null;
            $out[] = is_array($meta) ? $meta + ['exists' => true] : ['file' => basename($f), 'size' => filesize($f), 'created_at' => date('c', filemtime($f)), 'verified' => null, 'problems' => ['Tanpa metadata verifikasi'], 'exists' => true];
        }
        usort($out, static fn($a, $b) => strcmp($b['file'], $a['file']));
        return $out;
    }
}
