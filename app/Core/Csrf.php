<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function reset(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function verify(Request $req): bool
    {
        $sent = (string) ($req->post['_csrf'] ?? $req->json['_csrf'] ?? $req->header('X-CSRF-Token'));
        $real = (string) ($_SESSION['csrf'] ?? '');
        return $real !== '' && $sent !== '' && hash_equals($real, $sent);
    }
}
