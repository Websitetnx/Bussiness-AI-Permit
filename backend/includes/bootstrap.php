<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

// CLI workers do not need a browser session, and should not depend on the web session path.
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('permitflow_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/auth_otp.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/prediction.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/permit_payments.php';

// Shared request lock lets CLI backup wait for active requests before taking an exclusive lock.
$maintenanceLock = fopen(dirname(__DIR__, 2) . '/storage/maintenance.lock', 'c');
if (!$maintenanceLock || !flock($maintenanceLock, LOCK_SH | LOCK_NB)) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('Scheduled maintenance is in progress. Please try again shortly.');
}
register_shutdown_function(static function () use ($maintenanceLock): void { flock($maintenanceLock, LOCK_UN); fclose($maintenanceLock); });

date_default_timezone_set((string) app_config('timezone'));

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(self)');
}
