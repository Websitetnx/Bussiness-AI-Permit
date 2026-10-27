<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/screen.php';

$route = $_GET['route'] ?? 'login.php';
if (!is_string($route) || !in_array($route, mobile_routes(), true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Screen not found.']);
    exit;
}
define('PERMIT_MOBILE', true);
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_cookies', '0');
ini_set('session.use_trans_sid', '0');
session_name('permit_mobile_session');
$token = $_SERVER['HTTP_X_PERMIT_SESSION'] ?? '';
if (is_string($token) && preg_match('/^[A-Za-z0-9,-]{22,128}$/D', $token)) session_id($token);
session_start();

require_once dirname(__DIR__) . '/includes/functions.php';
csrf_token();
// Route is transport metadata; forms retain their original GET and POST parameters.
unset($_GET['route']);
$query = http_build_query($_GET);
$screenRoute = $route . ($query !== '' ? '?' . $query : '');
$mobileError = null;
$binary = in_array($route, ['document.php', 'document-version.php', 'payment-proof.php'], true)
    || ($route === 'admin/reports.php' && ($_GET['format'] ?? '') === 'csv');

ob_start(static function (string $body) use ($route, $screenRoute, $binary, &$mobileError): string {
    $mobileRedirect = $GLOBALS['permit_mobile_redirect'] ?? null;
    $status = http_response_code() ?: 200;
    if ($binary && !$mobileRedirect && !$mobileError && $status < 400) return $body;
    header_remove('Location');
    header_remove('Content-Length');
    header_remove('Content-Disposition');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $result = ['ok' => $status < 400, 'version' => 1, 'route' => $screenRoute,
        'session' => session_id(), 'csrf' => csrf_token(), 'user' => current_user()];
    if ($mobileRedirect !== null) {
        $result['redirect'] = $mobileRedirect;
        $result['messages'] = $GLOBALS['permit_mobile_messages'] ?? [];
    } elseif ($mobileError !== null || $status >= 400) {
        $result['ok'] = false;
        $result['error'] = $mobileError ?? ($status >= 500 ? 'The server could not complete this request. Try again shortly.' : trim(strip_tags($body)));
    } elseif (str_starts_with($route, 'api/')) {
        $data = json_decode($body, true);
        if (!is_array($data)) { $result['ok'] = false; $result['error'] = 'The server returned an invalid response.'; }
        else $result['data'] = $data;
    } else {
        $result['screen'] = (new MobileScreen($screenRoute, base_url()))->parse($body);
    }
    return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        exit('Method not allowed.');
    }
    if ($route === 'logout.php' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Sign out requires POST.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();
    if ($route === 'api/notifications.php' && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['action'] ?? 'list') !== 'list') {
        http_response_code(405);
        exit('Notification changes require POST.');
    }
    if (current_user()) {
        require_once dirname(__DIR__) . '/includes/database.php';
        require_login(); // Recheck disabled users and role changes, including existing JSON endpoints.
    }
    require dirname(__DIR__) . '/controllers/' . $route;
} catch (Throwable $exception) {
    error_log('Mobile request failed: ' . $exception->getMessage());
    http_response_code(500);
    $mobileError = 'The server could not complete this request. Check the server configuration or try again shortly.';
}
