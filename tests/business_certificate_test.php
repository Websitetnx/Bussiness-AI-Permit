<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require __DIR__ . '/support/project_source.php';
$checks = 0;
$failures = [];

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function (string $relativePath) use ($root, &$failures): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $contents = project_test_source($root, $relativePath);
    if ($contents === false) {
        $failures[] = "Could not read {$relativePath}.";
        return '';
    }
    return $contents;
};

$certificate = $read('certificate.php');
$application = $read('application.php');
$review = $read('admin/review.php');
$notifications = $read('api/notifications.php');
$client = $read('app.js');
$styles = $read('styles.css');

$check(str_contains($certificate, "require_role(['applicant', 'admin'])"), 'Certificate access must exclude Treasurer accounts.');
$check(str_contains($certificate, "a.status = 'Released'"), 'Only released applications may render a certificate.');
$check(str_contains($certificate, "a.user_id = ?"), 'Applicant certificate access must be scoped to its owner.');
$check(str_contains($certificate, "h.status = 'Released'"), 'The certificate issue date must come from Released history.');
$check(str_contains($certificate, 'p.assessment_breakdown') && !str_contains($certificate, "p.status = 'Paid'"), 'Released quarterly certificates retain their assessment snapshot before the annual balance is fully paid.');
$check(str_contains($certificate, 'Print / Save as PDF'), 'Certificate must expose a printable/saveable action.');
$check(str_contains($certificate, "modify('+1 year')->modify('-1 day')"), 'The pre-migration certificate fallback uses a one-year inclusive term.');
$check(str_contains($certificate, "permit_date(\$certificate['permit_expiration_date'])"), 'Stored expiration overrides the legacy fallback.');
$check(str_contains($certificate, "permit_date(\$certificate['permit_effective_date'])"), 'Certificate displays the same stored effective date as the application.');

$check(str_contains($application, "\$application['status'] === 'Released'"), 'Application page must gate the certificate action to Released status.');
$check(str_contains($application, "certificate.php?id="), 'Released application must link to its certificate.');
$check(str_contains($application, 'Payment verified and permit released'), 'Released payment copy must reflect the completed release.');

$check(str_contains($review, 'record_status($pdo'), 'Release must persist a status event with queued notifications.');
$check(str_contains($read('includes/functions.php'), 'notify_application($pdo'), 'Status changes must use durable notification events.');
$check(str_contains($review, "certificate.php?id="), 'Administrator review must expose the issued certificate.');

$check(str_contains($notifications, "'certificate.php'"), 'Certificate-ready notifications must route directly to the certificate.');
$check(str_contains($notifications, "'View certificate'"), 'Certificate-ready notifications must have an actionable label.');
$check(str_contains($client, 'item.action_label || "View details"'), 'Notification client must render server-selected action labels with a safe fallback.');
$check(str_contains($client, 'escapeHtml(actionLabel)'), 'Notification action labels must be HTML escaped.');

$check(str_contains($styles, '.certificate-card'), 'Certificate must have document styling.');
$check(str_contains($styles, '.certificate-toolbar'), 'Certificate must have toolbar styling.');
$check(preg_match('/@media\s+print[\s\S]*?\.certificate-toolbar[\s\S]*?\.certificate-card/i', $styles) === 1, 'Print CSS must hide controls and format the certificate.');

if ($failures) {
    fwrite(STDERR, "Business certificate regression test failed ({$checks} checks):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Business certificate regression tests passed ({$checks} checks).\n";
