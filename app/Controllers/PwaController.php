<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;

/**
 * PWA: manifest, service worker, dan halaman offline.
 * Dilayani lewat rute (bukan berkas statis) agar start_url/scope mengikuti APP_BASE di tiap lingkungan.
 * Service worker HANYA menyimpan aset statis (CSS/JS/gambar) dan halaman offline — halaman & API
 * berisi data keuangan tidak pernah di-cache, sehingga tidak tertinggal di perangkat setelah logout.
 */
final class PwaController extends Controller
{
    /** Aset yang dimuat hampir di setiap halaman; ikut di-precache. */
    private const PRECACHE_ASSETS = [
        'css/app.css', 'js/app.js', 'js/combo.js', 'js/export.js', 'js/realtime.js',
        'js/reminders.js', 'js/login.js', 'js/pwa.js',
        'images/logo-hme.png', 'images/icon-192.png', 'images/icon-512.png',
    ];

    public function manifest(): Response
    {
        $icon = static fn(string $f, string $size, string $purpose = 'any'): array => [
            'src' => url('assets/images/' . $f), 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose,
        ];
        $manifest = [
            'name' => 'Cashflow HME',
            'short_name' => 'Cashflow',
            'description' => 'Pengelolaan arus kas PT Hexa Multi Energi',
            'lang' => 'id',
            'dir' => 'ltr',
            'id' => url('/'),
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => '#0b5cff',
            'background_color' => '#ffffff',
            'icons' => [
                $icon('icon-192.png', '192x192'),
                $icon('icon-512.png', '512x512'),
                $icon('icon-maskable-512.png', '512x512', 'maskable'),
            ],
        ];
        return new Response(
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'no-cache']
        );
    }

    public function worker(): Response
    {
        $precache = [url('/offline')];
        foreach (self::PRECACHE_ASSETS as $a) {
            $precache[] = asset($a); // sudah memuat ?v=<mtime>, jadi cache otomatis berganti saat aset berubah
        }
        $js = file_get_contents(APP_ROOT . '/app/Views/pwa/sw.js');
        $flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
        $js = strtr((string) $js, [
            '__VERSION__' => substr(md5(implode('|', $precache)), 0, 10),
            '__BASE__' => json_encode(base_path(), $flags),
            '__OFFLINE__' => json_encode(url('/offline'), $flags),
            '__PRECACHE__' => json_encode($precache, $flags),
        ]);
        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function offline(): Response
    {
        $resp = $this->view('pwa/offline', ['title' => 'Offline'], 'layouts/auth');
        $resp->headers['Cache-Control'] = 'no-cache';
        return $resp;
    }
}
