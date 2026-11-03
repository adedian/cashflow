<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class App
{
    public static function run(): void
    {
        self::securityHeaders();
        $req = new Request();

        try {
            Session::start();
            $routes = require APP_ROOT . '/config/routes.php';
            $match = self::match($routes, $req);

            if ($match === null) {
                self::finish(self::error($req, 404, 'Halaman tidak ditemukan.'));
                return;
            }
            [$route, $params] = $match;
            [, , $handler, $access] = $route;

            // ---- Otorisasi (deny by default: akses harus dideklarasikan eksplisit) ----
            if ($access !== 'public') {
                $user = Auth::user();
                if (!$user) {
                    if ($req->wantsJson()) {
                        self::finish(Response::json(['ok' => false, 'error' => 'Sesi berakhir. Silakan login kembali.', 'login' => true], 401));
                    } else {
                        $_SESSION['intended'] = $req->method === 'GET' ? $req->path : null;
                        self::finish(Response::redirect('/login'));
                    }
                    return;
                }
                $allowed = match ($access) {
                    'any' => in_array($user['role'], ['viewer', 'super_admin'], true),
                    'admin' => $user['role'] === 'super_admin',
                    default => false,
                };
                if (!$allowed) {
                    \App\Services\Audit::log('forbidden', 'route', null, '403 ' . $req->method . ' ' . $req->path);
                    self::finish(self::error($req, 403, 'Anda tidak memiliki hak akses untuk tindakan ini.'));
                    return;
                }
            }

            // ---- Wajib ganti password sementara sebelum memakai aplikasi ----
            if ($access !== 'public' && !empty($user['must_change_password']) && !in_array($req->path, ['/password', '/logout'], true)) {
                self::finish($req->wantsJson()
                    ? Response::json(['ok' => false, 'error' => 'Anda harus mengganti password terlebih dahulu.'], 403)
                    : Response::redirect('/password'));
                return;
            }

            // ---- CSRF untuk semua permintaan yang mengubah data ----
            if (!in_array($req->method, ['GET', 'HEAD'], true) && $access !== 'public_nocsrf') {
                if (!Csrf::verify($req)) {
                    self::finish(self::error($req, 419, 'Token keamanan tidak valid atau kedaluwarsa. Muat ulang halaman dan coba lagi.'));
                    return;
                }
            }

            [$class, $method] = explode('@', $handler);
            $fqcn = 'App\\Controllers\\' . $class;
            $controller = new $fqcn($req);
            $result = $controller->$method(...array_values($params));
            self::finish($result instanceof Response ? $result : Response::html((string) $result));
        } catch (HttpException $e) {
            self::finish(self::error($req, $e->status(), $e->getMessage()));
        } catch (Throwable $e) {
            app_log(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $msg = 'Terjadi kesalahan pada server. Silakan coba lagi.';
            if (env('APP_DEBUG', '0') === '1') {
                $msg .= ' [' . $e->getMessage() . ']';
            }
            self::finish(self::error($req, 500, $msg));
        }
    }

    private static function finish(Response $r): void
    {
        $r->send();
    }

    private static function match(array $routes, Request $req): ?array
    {
        $method = $req->method === 'HEAD' ? 'GET' : $req->method;
        foreach ($routes as $route) {
            if ($route[0] !== $method) {
                continue;
            }
            $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route[1]) . '$#';
            if (preg_match($pattern, $req->path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$route, $params];
            }
        }
        return null;
    }

    private static function error(Request $req, int $status, string $message): Response
    {
        if ($req->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $message], $status);
        }
        $titles = [403 => 'Akses ditolak', 404 => 'Tidak ditemukan', 419 => 'Sesi tidak valid', 500 => 'Kesalahan server'];
        $html = View::render('errors/error', [
            'status' => $status,
            'title' => $titles[$status] ?? 'Kesalahan',
            'message' => $message,
            'user' => (session_status() === PHP_SESSION_ACTIVE) ? Auth::user() : null,
            'flash' => [],
            'path' => $req->path,
        ], 'layouts/auth');
        return Response::html($html, $status);
    }

    private static function securityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header('Cache-Control: no-store');
        header_remove('X-Powered-By');
        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
