<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$permit=public_permit_status(db(),(string)($_GET['token']??''));
if (!$permit) http_response_code(404);

require dirname(__DIR__, 2) . '/frontend/views/verify-permit.php';
