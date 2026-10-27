<?php
/** เริ่มระบบ: autoload, config, timezone, session, error handler */

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';
require APP_ROOT . '/app/helpers.php';
require APP_ROOT . '/app/helpers_ui.php';

date_default_timezone_set(config('timezone'));

ini_set('display_errors', config('debug') ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    if (config('debug')) {
        echo '<pre>' . e((string) $e) . '</pre>';
        return;
    }
    echo view_render('errors/500', [], 'layouts/public');
});

\App\Core\Session::start();
