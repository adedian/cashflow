<?php
declare(strict_types=1);

namespace App\Core;

/** Metadata halaman yang diatur oleh view dan dibaca oleh layout. */
final class Page
{
    private static array $meta = ['title' => '', 'subtitle' => '', 'actions' => '', 'tabs' => [], 'active' => '', 'scripts' => [], 'live' => []];

    public static function set(array $meta): void
    {
        self::$meta = $meta + self::$meta;
    }

    public static function get(string $key)
    {
        return self::$meta[$key] ?? null;
    }

    public static function script(string $file): void
    {
        self::$meta['scripts'][] = $file;
    }
}
