<?php
/*
 * PikaRelay front controller. Every request is routed through this file.
 *
 * Keep this file parseable by old PHP versions so an unsupported host gets a
 * readable message instead of a syntax error.
 */
if (PHP_VERSION_ID < 70400) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PikaRelay requires PHP 7.4 or newer. This server runs PHP ' . PHP_VERSION . '.';
    exit;
}

// PHP built-in development server (php -S host:port index.php): serve static assets directly.
if (PHP_SAPI === 'cli-server') {
    $pikaPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#^/assets/[A-Za-z0-9._-]+$#', $pikaPath) && is_file(__DIR__ . $pikaPath)) {
        return false;
    }
}

require __DIR__ . '/src/bootstrap.php';

\PikaRelay\App::fromEnvironment()->handle(\PikaRelay\Http\Request::fromGlobals())->send();
