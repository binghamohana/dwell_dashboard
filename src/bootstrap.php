<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Dwell Central needs PHP 8.1 or newer (this server runs ' . PHP_VERSION . ').');
}

define('DC_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Dwell\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    } elseif (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $file = DC_ROOT . '/lib/PHPMailer/' . substr($class, 20) . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require $file;
    }
});

Dwell\Core\App::boot();
