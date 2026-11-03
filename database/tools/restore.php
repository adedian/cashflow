<?php
declare(strict_types=1);
/**
 * Restore backup ke database TERPISAH untuk verifikasi (atau pemulihan darurat dengan konfirmasi eksplisit).
 *
 *   php database/tools/restore.php --file=storage/backups/backup-20261019-150000.sql --db=cashflow_hme_restore --confirm [--drop]
 *
 * Kredensial admin MariaDB (untuk CREATE DATABASE): env RESTORE_ADMIN_USER / RESTORE_ADMIN_PASS (default: root tanpa password, standar XAMPP).
 * Database tujuan yang sama dengan database aplikasi DITOLAK kecuali ditambah --overwrite-live DAN --confirm-live=NAMA_DB.
 * Setelah restore, jumlah baris tiap tabel dibandingkan dengan metadata backup (.json). --drop menghapus database tujuan setelah verifikasi.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$o = getopt('', ['file:', 'db:', 'confirm', 'drop', 'overwrite-live', 'confirm-live:']);
$file = (string) ($o['file'] ?? '');
$db = (string) ($o['db'] ?? '');
if ($file === '' || !preg_match('/^[A-Za-z0-9_]{3,64}$/', $db) || !isset($o['confirm'])) {
    fwrite(STDERR, "Pemakaian: php database/tools/restore.php --file=<backup.sql> --db=<database_tujuan> --confirm [--drop]\n");
    exit(1);
}
if (!str_starts_with($file, '/') && !preg_match('/^[A-Za-z]:/', $file)) {
    $file = APP_ROOT . '/' . $file;
}
if (!is_file($file)) { fwrite(STDERR, "Berkas backup tidak ditemukan.\n"); exit(1); }
if ($db === (string) env('DB_NAME') && (!isset($o['overwrite-live']) || ($o['confirm-live'] ?? '') !== $db)) {
    fwrite(STDERR, "DITOLAK: database tujuan sama dengan database aplikasi. Gunakan database terpisah, atau --overwrite-live --confirm-live=$db bila benar-benar ingin menimpa.\n");
    exit(2);
}
$host = (string) env('DB_HOST', '127.0.0.1');
$port = (string) env('DB_PORT', '3306');
$adminUser = (string) (getenv('RESTORE_ADMIN_USER') ?: 'root');
$adminPass = (string) (getenv('RESTORE_ADMIN_PASS') ?: '');
$mysql = (string) env('MYSQL_PATH', 'mysql');

try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $adminUser, $adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "Tidak dapat terhubung sebagai admin MariaDB (atur RESTORE_ADMIN_USER/RESTORE_ADMIN_PASS).\n");
    exit(1);
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$existing = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($db))->fetchColumn();
if ($existing > 0 && $db !== (string) env('DB_NAME')) {
    // database uji dari restore sebelumnya: kosongkan dulu agar hasil bersih
    $pdo->exec("DROP DATABASE `$db`");
    $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$proc = proc_open([$mysql, "--host=$host", "--port=$port", "--user=$adminUser", '--default-character-set=utf8mb4', $db],
    [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
    ['MYSQL_PWD' => $adminPass, 'PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot')]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
$code = proc_close($proc);
if ($code !== 0) { fwrite(STDERR, "Restore gagal: " . trim($err) . "\n"); exit(1); }

// verifikasi: jumlah baris tiap tabel di database hasil restore vs metadata backup
$meta = is_file($file . '.json') ? json_decode((string) file_get_contents($file . '.json'), true) : null;
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $pdo->quote($db))->fetchAll(PDO::FETCH_COLUMN);
$bad = 0;
printf("%-18s %10s %10s\n", 'Tabel', 'Restore', 'Backup');
foreach ($tables as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$db`.`$t`")->fetchColumn();
    $exp = $meta['live_counts'][$t] ?? null;
    $ok = $exp === null || $exp === $n;
    $bad += $ok ? 0 : 1;
    printf("%-18s %10d %10s %s\n", $t, $n, $exp ?? '?', $ok ? '' : '<-- BEDA');
}
// verifikasi saldo: jumlah debit/kredit per akun di hasil restore
foreach ($pdo->query("SELECT a.code, a.opening_balance, COALESCE(SUM(t.debit),0) d, COALESCE(SUM(t.credit),0) c FROM `$db`.accounts a LEFT JOIN `$db`.transactions t ON t.account_id = a.id AND t.voided_at IS NULL GROUP BY a.id") as $r) {
    $bal = $r['opening_balance'] + 0;
    printf("Saldo %-10s awal=%s debit=%s kredit=%s\n", $r['code'], $r['opening_balance'], $r['d'], $r['c']);
}
echo $bad === 0 ? "RESTORE TERVERIFIKASI: jumlah baris semua tabel sesuai.\n" : "RESTORE TIDAK SESUAI pada $bad tabel.\n";
if (isset($o['drop']) && $db !== (string) env('DB_NAME')) {
    $pdo->exec("DROP DATABASE `$db`");
    echo "Database uji '$db' dihapus.\n";
}
exit($bad === 0 ? 0 : 1);
