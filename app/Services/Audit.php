<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

final class Audit
{
    private const SENSITIVE = ['password', 'password_hash', 'token', '_csrf', 'new_password', 'password_confirm'];

    /**
     * Catat aktivitas. Dipanggil di dalam transaksi DB yang sama dengan perubahan data bila memungkinkan.
     */
    public static function log(
        string $action,
        ?string $entity = null,
        ?string $entityId = null,
        ?string $summary = null,
        $before = null,
        $after = null,
        ?int $userId = null,
        ?string $username = null
    ): void {
        try {
            $u = ($userId === null && $username === null) ? Auth::user() : null;
            if ($userId !== null && $username === null) {
                $username = (string) Db::val('SELECT username FROM users WHERE id = ?', [$userId]) ?: null;
            }
            Db::insert('audit_logs', [
                'user_id' => $userId ?? ($u['id'] ?? null),
                'username' => $username ?? ($u['username'] ?? null),
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null,
                'before_json' => $before !== null ? self::enc($before) : null,
                'after_json' => $after !== null ? self::enc($after) : null,
                'ip' => client_ip(),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (\Throwable $e) {
            app_log('Audit gagal: ' . $e->getMessage());
            // di dalam transaksi perubahan data, kegagalan audit harus membatalkan perubahan
            if (Db::pdo()->inTransaction()) {
                throw $e;
            }
        }
    }

    private static function enc($data): string
    {
        return (string) json_encode(self::scrub($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function scrub($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::SENSITIVE, true)) {
                $data[$k] = '[disembunyikan]';
            } elseif (is_array($v)) {
                $data[$k] = self::scrub($v);
            }
        }
        return $data;
    }
}
