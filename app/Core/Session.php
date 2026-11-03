<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('HME_SESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path() === '' ? '/' : base_path() . '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        $idle = (int) env('SESSION_IDLE_MINUTES', 120) * 60;
        $now = time();
        if (isset($_SESSION['last_seen']) && ($now - (int) $_SESSION['last_seen']) > $idle) {
            self::destroy();
            session_start();
            self::$started = true;
        }
        $_SESSION['last_seen'] = $now;
    }

    /** Lepas kunci sesi (untuk endpoint baca/polling agar tidak saling menunggu). */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
