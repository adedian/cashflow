<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json($data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function redirect(string $path, int $status = 302): self
    {
        $loc = preg_match('#^https?://#i', $path) ? $path : url($path);
        return new self('', $status, ['Location' => $loc]);
    }

    public static function download(string $content, string $filename, string $mime): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
