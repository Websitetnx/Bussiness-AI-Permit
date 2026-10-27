<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/mailer.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$scanFailure = [
    'label' => 'DTI / SEC / CDA Registration',
    'summary' => 'No usable document evidence is visible in the submitted scan.',
    'reasons' => [
        'Expected ' . chr(34) . 'DTI / SEC / CDA Registration' . chr(34)
            . ', but detected ' . chr(34) . 'No identifiable document' . chr(34) . '.',
        'Document quality is too low (5%). The minimum is 40%.',
    ],
    'issues' => [
        'The image shows a blank blue gradient with no visible text or registration details.',
        'A DTI, SEC, or CDA registration document cannot be identified or assessed.',
    ],
];

$sent = [];
$result = send_application_scan_failure_emails(
    'applicant@example.com',
    'Applicant <script>alert(1)</script>',
    'BPL-TEST-100',
    [$scanFailure],
    'https://permits.example.test/application.php?id=100',
    'https://permits.example.test/admin/review.php?id=100',
    static function (array $message) use (&$sent): bool {
        $sent[$message['recipient_role']] = $message;
        return true;
    }
);

$assert(count($sent) === 2, 'Applicant and admin messages must be sent independently.');
$assert($result['applicant']['sent'] && $result['admin']['sent'], 'Both successful delivery results must be reported.');
$assert(
    ($sent['applicant']['subject'] ?? '') === 'Action Required: Document Validation Failed for Your Application',
    'Applicant subject must match the required text.'
);
$assert(
    ($sent['admin']['subject'] ?? '') === 'Alert: Invalid Document Submitted by Applicant <script>alert(1)</script>',
    'Admin subject must include the applicant name.'
);
$assert(
    str_contains((string) ($sent['applicant']['html_body'] ?? ''), 'Applicant &lt;script&gt;alert(1)&lt;/script&gt;'),
    'User-provided applicant names must be HTML escaped.'
);
$assert(
    !str_contains((string) ($sent['applicant']['html_body'] ?? ''), '<script>'),
    'Unsafe applicant HTML must never be inserted into the message.'
);
$assert(
    str_contains((string) ($sent['applicant']['text_body'] ?? ''), 'Document quality is too low (5%).'),
    'Plain-text fallback must include the validation reason.'
);
$assert(
    str_contains((string) ($sent['admin']['html_body'] ?? ''), 'BPL-TEST-100')
        && str_contains((string) ($sent['admin']['html_body'] ?? ''), 'DTI / SEC / CDA Registration'),
    'Admin HTML must include the application ID and document type.'
);
$assert(
    str_contains((string) ($sent['admin']['text_body'] ?? ''), 'https://permits.example.test/admin/review.php?id=100'),
    'Admin plain-text fallback must include the dashboard link.'
);

$queuedApplicant = queued_document_validation_email([
    'role' => 'applicant',
    'email' => 'applicant@example.com',
    'name' => 'Applicant',
    'reference' => 'BPL-TEST-100',
    'application_id' => 100,
    'body' => "Some submitted files need correction.\n\n" . document_validation_failure_message($scanFailure),
], 'https://permits.example.test');
$queuedAdmin = queued_document_validation_email([
    'role' => 'admin',
    'email' => 'admin@example.com',
    'name' => 'Applicant',
    'reference' => 'BPL-TEST-100',
    'application_id' => 100,
    'body' => "Some submitted files need correction.\n\n" . document_validation_failure_message($scanFailure),
], 'https://permits.example.test');
$assert($queuedApplicant['subject'] === 'Action Required: Document Validation Failed for Your Application', 'Queued applicant subject must match the required text.');
$assert($queuedAdmin['subject'] === 'Alert: Invalid Document Submitted by Applicant', 'Queued admin subject must include the applicant name.');
$assert(str_contains($queuedApplicant['html_body'], 'Open application and resubmit'), 'Queued applicant HTML must include the resubmission action.');
$assert(str_contains($queuedAdmin['html_body'], 'admin/review.php?id=100'), 'Queued admin HTML must include the admin dashboard link.');
$assert(str_contains($queuedApplicant['text_body'], 'Document quality is too low (5%).'), 'Queued plain-text fallback must include validation reasons.');
$attempts = [];
$partial = send_document_validation_failure_notifications(
    'applicant@example.com',
    'Applicant',
    'BPL-TEST-101',
    'DTI / SEC / CDA Registration',
    document_validation_failure_message($scanFailure),
    'https://permits.example.test/application.php?id=101',
    'https://permits.example.test/admin/review.php?id=101',
    static function (array $message) use (&$attempts): bool {
        $attempts[] = $message['recipient_role'];
        return $message['recipient_role'] !== 'applicant';
    }
);

$assert($attempts === ['applicant', 'admin'], 'An applicant failure must not prevent the admin attempt.');
$assert(!$partial['applicant']['sent'] && $partial['admin']['sent'], 'Independent partial-delivery status must be returned.');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Document validation email tests passed.' . PHP_EOL;
