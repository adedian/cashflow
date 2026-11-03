<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    public array $json = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode($uri);
        $base = base_path();
        if ($base !== '' && strncmp($uri, $base, strlen($base)) === 0) {
            $uri = substr($uri, strlen($base));
        }
        $this->path = '/' . trim($uri, '/');
        $this->query = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;

        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $d = json_decode($raw ?: '', true);
            if (is_array($d)) {
                $this->json = $d;
            }
        }
    }

    public function input(string $key, $default = null)
    {
        return $this->post[$key] ?? $this->json[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, null);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key, null);
        return $v === '1' || $v === 1 || $v === true || $v === 'on' || $v === 'true';
    }

    public function header(string $name): string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$k] ?? '');
    }

    public function wantsJson(): bool
    {
        return strncmp($this->path, '/api/', 5) === 0
            || stripos($this->header('Accept'), 'application/json') !== false
            || strtolower($this->header('X-Requested-With')) === 'xmlhttprequest';
    }
}
