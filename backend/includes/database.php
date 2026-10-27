<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $settings = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $settings['host'],
        $settings['port'],
        $settings['name'],
        $settings['charset']
    );

    $connection = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
