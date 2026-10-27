<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}


function render_app_header(string $title, string $active): void
{
    $user = require_login();
    $isAdmin = is_admin_role($user['role'] ?? '');
    $isTreasurer = is_treasurer_role($user['role'] ?? '');
    $homePath = role_home_path($user['role'] ?? '');
    $initials = implode('', array_map(static fn(string $part): string => strtoupper($part[0]), array_slice(preg_split('/\s+/', trim($user['name'])) ?: [], 0, 2)));
    $flashes = pull_flashes();
    $roleLabel = $isAdmin ? (normalize_role((string) $user['role']) === 'admin' ? 'Administrator' : ucfirst(normalize_role((string) $user['role']))) : 'Applicant';
    require dirname(__DIR__, 2) . '/frontend/layouts/header.php';
}

function render_app_footer(): void
{
    require dirname(__DIR__, 2) . '/frontend/layouts/footer.php';
}
