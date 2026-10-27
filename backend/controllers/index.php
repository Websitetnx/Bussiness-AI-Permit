<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

if (!current_user()) {
    redirect('login.php');
}
redirect(role_home_path(current_user()['role'] ?? ''));
