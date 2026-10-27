<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';

$root = dirname(__DIR__);
require __DIR__ . '/support/project_source.php';
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(normalize_role(' Treasurer ') === 'treasurer', 'Treasurer role should normalize casing and whitespace.');
$assert(normalize_role('TREASURER') === 'treasurer', 'Uppercase Treasurer role should normalize.');
$assert(!role_allows('treasurer', 'admin'), 'Treasurer must not inherit administrator permissions.');
$assert(role_allows('treasurer', ['admin', 'treasurer']), 'Treasurer must be allowed on payment-management routes.');
$assert(!can_manage_applications('treasurer'), 'Treasurer must not manage applications.');
$assert(can_manage_payments('treasurer'), 'Treasurer must manage payments.');
$assert(can_manage_applications('admin') && can_manage_payments('admin'), 'Administrator must retain application and payment permissions.');
$assert(can_manage_applications('staff') && can_manage_payments('staff'), 'Existing staff administrator tier must remain supported.');
$assert(can_manage_applications('superadmin') && can_manage_payments('superadmin'), 'Existing super-administrator tier must remain supported.');
$assert(!can_manage_applications('applicant') && !can_manage_payments('applicant'), 'Applicant must not receive back-office permissions.');
$assert(!can_manage_applications(null) && !can_manage_payments(null), 'Missing role must not receive permissions.');
$assert(role_home_path('treasurer') === 'admin/payments.php', 'Treasurer home must be the payment queue.');
$assert(role_home_path('admin') === 'admin/index.php', 'Administrator home must remain the admin overview.');
$assert(role_home_path('applicant') === 'dashboard.php', 'Applicant home must remain the dashboard.');

$read = static function (string $relativePath) use ($root): string {
    $contents = project_test_source($root, $relativePath);
    if ($contents === false) {
        fwrite(STDERR, "FAIL: Could not read {$relativePath}.\n");
        exit(1);
    }
    return $contents;
};

$paymentGuard = "require_role(['admin', 'treasurer'])";
foreach (['admin/payments.php', 'admin/payment.php', 'admin/payment-action.php'] as $path) {
    $assert(str_contains($read($path), $paymentGuard), "{$path} must explicitly allow Administrator and Treasurer.");
}

foreach (['admin/index.php', 'admin/review.php', 'admin/analytics.php', 'admin/generate-insights.php', 'admin/scan-document.php', 'admin/fee-settings.php', 'admin/users.php'] as $path) {
    $contents = $read($path);
    $assert(str_contains($contents, "require_role('admin')"), "{$path} must remain administrator-only.");
    $assert(!str_contains($contents, $paymentGuard), "{$path} must not allow Treasurer.");
}

foreach (['application.php', 'track.php', 'document.php'] as $path) {
    $assert(str_contains($read($path), "require_role(['applicant', 'admin'])"), "{$path} must exclude Treasurer from application data.");
}

$layout = $read('includes/layout.php');
$assert(str_contains($layout, 'if ($isTreasurer):'), 'Layout must render a dedicated Treasurer navigation branch.');
$assert(str_contains($layout, "admin/payments.php"), 'Treasurer navigation must link to payments.');

$search = $read('api/search-suggestions.php');
$assert(str_contains($search, 'is_treasurer_role'), 'Search suggestions must identify Treasurer separately.');
$assert(str_contains($search, "admin/payment.php?id="), 'Treasurer search results must link to payment details.');

$chatbot = $read('api/chatbot.php');
$assert(str_contains($chatbot, 'Treasurer accounts are limited to payment verification'), 'Chatbot must enforce payment-only Treasurer guidance.');
$assert(str_contains($chatbot, "admin/payment.php?id="), 'Treasurer chatbot lookups must link only to payment details.');

$notifications = $read('api/notifications.php');
$assert(str_contains($notifications, "if (!is_treasurer_role"), 'Treasurer notification fetch must skip renewal workflow mutations.');
$assert(str_contains($notifications, "admin/payment.php?id="), 'Treasurer notifications must link to payment details.');
$assert(str_contains($read('app.js'), 'item.detail_url || null'), 'Notification UI must use the server-authorized detail URL.');
$assert(str_contains($read('payment.php'), "role = 'treasurer'"), 'Payment submissions must notify active Treasurer accounts; administrators use the outbox.');
$assert(str_contains($read('admin/payment-action.php'), "'admin/payment.php?id='"), 'Payment actions must return to the payment-only detail page.');
$assert(str_contains($read('payment-proof.php'), 'can_manage_payments'), 'Payment proof access must use the payment capability.');
$assert(str_contains($read('receipt.php'), 'can_manage_payments'), 'Receipt access must use the payment capability.');

$setup = $read('setup-admin.php');
$assert(str_contains($setup, "is_treasurer_role(current_user()['role']"), 'First-admin setup must deny a signed-in Treasurer.');

$schema = $read('database/schema.sql');
$migration = $read('database/migrations/006_treasurer_role.sql');
$roleEnum = "ENUM('applicant', 'admin', 'treasurer')";
$assert(str_contains($schema, $roleEnum), 'Fresh schema must support the Treasurer role.');
$assert(str_contains($migration, $roleEnum), 'Treasurer migration must install the role on older databases.');

$originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$_SERVER['SCRIPT_NAME'] = '/Business-permit test/api/search-suggestions.php';
$assert(base_url() === '/Business-permit test', 'API-generated links must resolve from the application root.');
if ($originalScriptName === null) {
    unset($_SERVER['SCRIPT_NAME']);
} else {
    $_SERVER['SCRIPT_NAME'] = $originalScriptName;
}

echo "Treasurer permission regression tests passed ({$checks} checks).\n";
