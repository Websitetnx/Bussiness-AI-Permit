<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}


// Compatibility URL for older bookmarks.
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_role('admin');
redirect('admin/index.php');
