<?php

declare(strict_types=1);

if (!defined('PIKA_ROOT')) {
    define('PIKA_ROOT', dirname(__DIR__));
}

// Shortest round-trip float encoding, regardless of the host's php.ini.
ini_set('serialize_precision', '-1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'PikaRelay\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
