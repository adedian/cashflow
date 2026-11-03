<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\Audit;

final class Auth
{
    private const MAX_FAIL_USER = 5;    // gagal per username dalam jendela
    private const MAX_FAIL_IP = 20;     // gagal per IP dalam jendela
    private const WINDOW_MIN = 15;

    private static ?array $user = null;
    private static bool $loaded = false;

    /** Pengguna aktif dari sesi; divalidasi ke DB pada setiap request (perubahan role/status langsung berlaku). */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        $u = Db::one('SELECT id, username, full_name, role, status, must_change_password FROM users WHERE id = ?', [(int) $id]);
        if (!$u || $u['status'] !== 'active') {
            unset($_SESSION['uid']);
            return null;
        }
        self::$user = $u;
        return $u;
    }

    public static function isLocked(string $username, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        $u = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND created_at >= ?', [$username, $since]);
        $i = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?', [$ip, $since]);
        return $u >= self::MAX_FAIL_USER || $i >= self::MAX_FAIL_IP;
    }

    /**
     * @return string 'ok' | 'locked' | 'invalid'
     */
    public static function attempt(string $username, string $password): string
    {
        $username = mb_substr(trim($username), 0, 50);
        $ip = client_ip();

        if ($username === '' || $password === '') {
            return 'invalid';
        }
        if (self::isLocked($username, $ip)) {
            Audit::log('login_blocked', 'user', null, 'Login diblokir sementara (terlalu banyak percobaan): ' . $username, null, null, null, $username);
            return 'locked';
        }

        $u = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        // verifikasi tetap dijalankan walau akun tidak ada agar waktu respons tidak membocorkan keberadaan akun
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltuQ8p1M5kN7y0nE4m5oX3mVJ2dI3o1e';
        $okPw = password_verify($password, $hash);

        if (!$u || !$okPw || $u['status'] !== 'active') {
            Db::insert('login_attempts', ['username' => $username, 'ip' => $ip, 'success' => 0]);
            Audit::log('login_failed', 'user', $u ? (string) $u['id'] : null, 'Login gagal: ' . $username, null, null, null, $username);
            return 'invalid';
        }

        Session::regenerate();
        Csrf::reset();
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['last_seen'] = time();
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', (int) $u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        Db::update('users', (int) $u['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
        Db::insert('login_attempts', ['username' => $username, 'ip' => $ip, 'success' => 1]);
        // gagal sebelumnya tidak lagi dihitung setelah login sukses
        Db::q('DELETE FROM login_attempts WHERE username = ? AND success = 0', [$username]);
        Db::q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        self::$loaded = false;
        self::$user = null;
        Audit::log('login', 'user', (string) $u['id'], 'Login berhasil', null, null, (int) $u['id'], $username);
        return 'ok';
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) {
            Audit::log('logout', 'user', (string) $u['id'], 'Logout');
        }
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }
}
