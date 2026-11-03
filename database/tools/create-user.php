<?php
declare(strict_types=1);
/**
 * Membuat / mengatur ulang akun lewat CLI (tidak ada registrasi publik).
 *
 *   php database/tools/create-user.php --username=admin --name="Nama Admin" --role=super_admin --out=storage/kredensial.txt
 *   php database/tools/create-user.php --username=viewer --name="Nama Viewer" --role=viewer      --out=storage/kredensial.txt
 *   php database/tools/create-user.php --username=viewer --reset --out=...    (atur ulang password akun yang sudah ada)
 *
 * Password default = username + "123" (mis. viewer -> viewer123); tidak dipaksa diganti saat login pertama.
 * Jika --out diberikan, kredensial ditulis ke file itu (di storage/ yang tidak bisa diakses web); hapus setelah dibaca.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\Audit;

$o = getopt('', ['username:', 'name:', 'role:', 'out:', 'reset']);
$username = trim((string) ($o['username'] ?? ''));
if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Username harus 3-50 karakter: huruf kecil, angka, titik, garis bawah, strip.\n");
    exit(1);
}
$existing = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
$pw = $username . '123';
$hash = password_hash($pw, PASSWORD_DEFAULT);

if (isset($o['reset'])) {
    if (!$existing) { fwrite(STDERR, "Akun tidak ditemukan.\n"); exit(1); }
    Db::update('users', (int) $existing['id'], ['password_hash' => $hash, 'must_change_password' => 0, 'status' => 'active']);
    Db::q('DELETE FROM login_attempts WHERE username = ?', [$username]);
    Audit::log('user_password_reset', 'user', (string) $existing['id'], 'Password diatur ulang via CLI: ' . $username, null, null, null, 'cli');
} else {
    if ($existing) { fwrite(STDERR, "Akun sudah ada. Gunakan --reset untuk mengatur ulang password.\n"); exit(1); }
    $role = (string) ($o['role'] ?? '');
    $name = trim((string) ($o['name'] ?? ''));
    if (!in_array($role, ['viewer', 'super_admin'], true) || $name === '') {
        fwrite(STDERR, "Wajib: --name=\"Nama\" --role=viewer|super_admin\n");
        exit(1);
    }
    $id = Db::insert('users', ['username' => $username, 'full_name' => $name, 'password_hash' => $hash, 'role' => $role, 'status' => 'active', 'must_change_password' => 0]);
    Audit::log('user_create', 'user', (string) $id, "Akun dibuat via CLI: $username ($role)", null, ['username' => $username, 'role' => $role], null, 'cli');
}

$line = "$username\t$pw\t(password sementara - disarankan diganti)\n";
if (!empty($o['out'])) {
    $path = str_starts_with((string) $o['out'], '/') || preg_match('/^[A-Za-z]:/', (string) $o['out']) ? (string) $o['out'] : APP_ROOT . '/' . $o['out'];
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    echo "Akun '$username' siap. Password sementara ditulis ke: $path (hapus file setelah dibaca)\n";
} else {
    echo "Akun '$username' siap. Password sementara: $pw\n";
}
