<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/Helpers/functions.php';

App\Core\Env::load((string) (getenv('CASHFLOW_ENV_FILE') ?: APP_ROOT . '/.env'));
date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Jakarta'));
mb_internal_encoding('UTF-8');

if (env('APP_DEBUG', '0') === '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
