<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    App\Core\App::run();
} catch (Throwable $e) {
    // kegagalan sebelum aplikasi siap (mis. koneksi DB): jangan bocorkan detail
    app_log('FATAL: ' . $e->getMessage());
    http_response_code(500);
    echo 'Terjadi kesalahan pada server.';
}
