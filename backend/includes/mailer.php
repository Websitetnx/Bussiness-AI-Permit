<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;

if (!class_exists(PHPMailer::class)) {
    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('Email dependencies are missing. Run Composer install.');
    }
    require_once $autoloadPath;
}

function mail_settings(): array
{
    $settings = app_config('mail');
    return is_array($settings) ? $settings : [];
}

/**
 * Write mail failures to the PHP/Apache error log without exposing credentials
 * or complete message bodies.
 */
function log_mail_failure(string $context, Throwable|string $failure): void
{
    $detail = $failure instanceof Throwable ? $failure->getMessage() : $failure;
    $detail = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $detail) ?? '');
    error_log('[PERMIT mail] ' . $context . ': ' . ($detail !== '' ? $detail : 'Unknown delivery error'));
}

function mail_single_line(string $value, string $fallback = ''): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    return $value !== '' ? $value : $fallback;
}

/**
 * Build a trusted absolute portal URL. APP_URL is preferred because request
 * Host headers must not be trusted when generating links for email.
 */
function permit_portal_url(string $path): string
{
    $configuredBase = rtrim(trim((string) app_config('app_url')), '/');
    if ($configuredBase !== '' && filter_var($configuredBase, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string) parse_url($configuredBase, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $configuredBase . '/' . ltrim($path, '/');
        }
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::\d{1,5})?$/iD', $host)) {
        $host = 'localhost';
    }
    return $scheme . '://' . $host . url($path);
}

function mail_http_url(string $url): string
{
    $url = trim(preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '');
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return filter_var($url, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)
        ? $url
        : '';
}

/**
 * Send one application email.
 *
 * Tests can inject a transport that accepts the normalized message array. A
 * custom transport may throw, or return false, to report a delivery failure.
 */
function send_app_mail(array $message, ?callable $transport = null): void
{
    $toEmail = strtolower(trim((string) ($message['to_email'] ?? '')));
    $toName = trim((string) ($message['to_name'] ?? ''));
    $subject = trim((string) ($message['subject'] ?? ''));
    $htmlBody = (string) ($message['html_body'] ?? '');
    $textBody = (string) ($message['text_body'] ?? '');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid recipient email address is required.');
    }
    if ($subject === '' || $htmlBody === '' || $textBody === '') {
        throw new InvalidArgumentException('Email subject and body content are required.');
    }

    $normalized = [
        'to_email' => $toEmail,
        'to_name' => $toName,
        'subject' => $subject,
        'html_body' => $htmlBody,
        'text_body' => $textBody,
    ] + $message;

    if ($transport !== null) {
        if ($transport($normalized) === false) {
            throw new RuntimeException('The email could not be delivered.');
        }
        return;
    }

    $settings = mail_settings();
    $host = trim((string) ($settings['host'] ?? ''));
    $username = trim((string) ($settings['username'] ?? ''));
    $password = (string) ($settings['password'] ?? '');
    $smtpAuth = (bool) ($settings['smtp_auth'] ?? true);
    $fromAddress = strtolower(trim((string) ($settings['from_address'] ?? '')));
    if ($fromAddress === '') {
        $fromAddress = strtolower($username);
    }

    if ($host === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        $exception = new RuntimeException('Email delivery is not configured. Set SMTP_HOST and MAIL_FROM_ADDRESS.');
        log_mail_failure('configuration', $exception);
        throw $exception;
    }
    if ($smtpAuth && ($username === '' || $password === '')) {
        $exception = new RuntimeException('SMTP authentication is enabled, but its username or password is missing.');
        log_mail_failure('configuration', $exception);
        throw $exception;
    }

    $encryption = strtolower(trim((string) ($settings['encryption'] ?? 'tls')));
    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = max(1, (int) ($settings['port'] ?? 587));
        $mailer->SMTPAuth = $smtpAuth;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Timeout = max(1, (int) ($settings['timeout_seconds'] ?? 15));
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;

        if (in_array($encryption, ['ssl', 'smtps'], true)) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($encryption, ['tls', 'starttls'], true)) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($encryption, ['', 'none'], true)) {
            $mailer->SMTPAutoTLS = false;
        } else {
            throw new InvalidArgumentException('SMTP_ENCRYPTION must be tls, smtps, or none.');
        }

        $mailer->setFrom($fromAddress, trim((string) ($settings['from_name'] ?? 'PERMIT')));
        $mailer->addAddress($toEmail, $toName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        if (isset($message['message_id'])) $mailer->MessageID = (string) $message['message_id'];
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (Throwable $exception) {
        log_mail_failure('SMTP delivery', $exception);
        throw new RuntimeException('The email could not be delivered.', 0, $exception);
    }
}

/** Turn one persisted scan failure into the review text used in both emails. */
function document_validation_failure_message(array $failure): string
{
    $documentType = mail_single_line((string) ($failure['label'] ?? ''), 'Uploaded document');
    $summary = trim((string) ($failure['summary'] ?? ''));
    if ($summary === '') {
        $summary = 'The submitted scan did not provide enough valid evidence to satisfy the expected document requirement.';
    }
    $reasons = array_values(array_filter(array_map(
        static fn(mixed $value): string => trim((string) $value),
        (array) ($failure['reasons'] ?? [])
    )));
    $issues = array_values(array_filter(array_map(
        static fn(mixed $value): string => trim((string) $value),
        (array) ($failure['issues'] ?? [])
    )));
    $break = PHP_EOL . PHP_EOL;
    $message = 'Document Review Result: ' . $documentType . ' - Invalid' . $break . $summary
        . $break . 'Why this file failed' . $break . '- '
        . implode(PHP_EOL . '- ', $reasons ?: ['The file did not meet the automated document validation rules.']);
    if ($issues) {
        $message .= $break . 'Additional AI observations' . $break . '- ' . implode(PHP_EOL . '- ', $issues);
    }
    return $message;
}

function validation_email_html(
    string $heading,
    string $intro,
    string $details,
    string $link,
    string $linkLabel
): string {
    $escape = static fn(string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
    $validLink = filter_var($link, FILTER_VALIDATE_URL)
        && in_array($scheme, ['http', 'https'], true);
    $linkHtml = $validLink
        ? '<p><a href=' . chr(34) . $escape($link) . chr(34) . '>' . $escape($linkLabel) . '</a></p>'
            . '<p>Direct link: ' . $escape($link) . '</p>'
        : '';

    return '<!doctype html><html><body><h2>' . $escape($heading) . '</h2>'
        . '<p>' . nl2br($escape($intro), false) . '</p>'
        . '<div>' . nl2br($escape($details), false) . '</div>'
        . $linkHtml
        . '<p><small>This is an automated message from PERMIT.</small></p></body></html>';
}

/**
 * Build the queued invalid-document email for one authorized recipient.
 * The queue worker uses this instead of sending a generic status message so
 * both recipients receive the required validation context and link.
 */
function queued_document_validation_email(array $job, string $root): array
{
    $name = mail_single_line((string) ($job['name'] ?? ''), 'Applicant');
    $reference = mail_single_line((string) ($job['reference'] ?? ''), 'Unknown');
    $applicationId = mail_single_line((string) ($job['application_id'] ?? ''), 'Unknown');
    $body = trim((string) ($job['body'] ?? ''));
    if ($body === '') {
        $body = 'The submitted document did not meet the automated validation requirements.';
    }

    preg_match_all('/Document Review Result:\s*(.+?)\s+-\s+Invalid/u', $body, $matches);
    $documentTypes = array_values(array_unique(array_map(
        static fn(string $value): string => mail_single_line($value, 'Uploaded document'),
        (array) ($matches[1] ?? [])
    )));
    $documentType = $documentTypes ? implode(', ', $documentTypes) : 'Uploaded document';
    $role = (string) ($job['role'] ?? 'applicant');
    $isAdmin = $role === 'admin';
    $path = $isAdmin ? 'admin/review.php?id=' : 'application.php?id=';
    $link = rtrim($root, '/') . '/' . $path . rawurlencode($applicationId);

    if ($isAdmin) {
        $subject = 'Alert: Invalid Document Submitted by ' . $name;
        $intro = 'A document has been marked invalid by automated validation.'
            . PHP_EOL . PHP_EOL . 'Applicant: ' . $name
            . PHP_EOL . 'Application ID: ' . $applicationId
            . PHP_EOL . 'Reference: ' . $reference
            . PHP_EOL . 'Document type: ' . $documentType;
        $details = $body;
        $linkLabel = 'Review in admin dashboard';
    } else {
        $subject = 'Action Required: Document Validation Failed for Your Application';
        $intro = 'Hello ' . $name . ',' . PHP_EOL . PHP_EOL
            . 'Your uploaded ' . $documentType . ' document is invalid and needs to be replaced.';
        $details = $body . PHP_EOL . PHP_EOL
            . 'Please log in to the permit portal, open application ' . $reference
            . ', and upload a corrected document.';
        $linkLabel = 'Open application and resubmit';
    }

    return [
        'to_email' => (string) ($job['email'] ?? ''),
        'to_name' => $name,
        'subject' => $subject,
        'html_body' => validation_email_html(
            $isAdmin ? 'Invalid document alert' : 'Document validation failed',
            $intro,
            $details,
            $link,
            $linkLabel
        ),
        'text_body' => $intro . PHP_EOL . PHP_EOL . $details
            . PHP_EOL . PHP_EOL . 'Direct link: ' . $link . PHP_EOL . PHP_EOL . 'PERMIT',
    ];
}

/**
 * Build a general-purpose notification email for any event type.
 *
 * Unlike queued_document_validation_email() which is specific to document
 * validation failures, this function formats status changes, payment updates,
 * permit releases, and other application events into proper emails.
 */
function build_notification_email(array $job, string $root): array
{
    $name      = mail_single_line((string) ($job['name'] ?? ''), 'Applicant');
    $reference = mail_single_line((string) ($job['reference'] ?? ''), 'Unknown');
    $appId     = mail_single_line((string) ($job['application_id'] ?? ''), '');
    $title     = trim((string) ($job['title'] ?? ''));
    $body      = trim((string) ($job['body'] ?? ''));
    $role      = (string) ($job['role'] ?? 'applicant');
    $isAdmin   = in_array($role,['admin','treasurer'],true);

    $path = $isAdmin ? 'admin/review.php?id=' : 'application.php?id=';
    $link = rtrim($root, '/') . '/' . $path . rawurlencode($appId);
    $isFinancial = in_array($job['audience']??'', ['finance','finance_staff'],true) || str_contains(strtolower($title),'installment');
    if ($role==='treasurer' || $isFinancial) {
        $link=rtrim($root,'/').'/'.($isAdmin?(!empty($job['payment_id'])?'admin/payment.php?id='.(int)$job['payment_id']:'admin/payments.php'):'payment.php?application_id='.rawurlencode($appId));
    }

    // Build a human-friendly subject from the event title.
    if ($title === '') {
        $title = 'Application update';
    }
    $subject = $isAdmin
        ? $title . ' — ' . $reference
        : $title . ' — ' . $reference;

    // Build a human-friendly intro and details.
    if ($isAdmin) {
        $intro = 'Notification for application ' . $reference . '.';
        $details = $body !== '' ? $body : 'Open the admin dashboard for details.';
        $linkLabel = 'Review in admin dashboard';
    } else {
        $intro = 'Hello ' . $name . ',';
        $details = $body !== '' ? $body : 'Open your application for details.';
        $linkLabel = 'View your application';
    }
    if ($role==='treasurer' || $isFinancial) $linkLabel='View payments and installments';

    $escape = static fn(string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    // Determine visual accent based on event type.
    $titleLower = strtolower($title);
    if (str_contains($titleLower, 'released') || str_contains($titleLower, 'certificate ready')) {
        $accentColor = '#087b38';
        $heading     = '🎉 ' . $title;
    } elseif (str_contains($titleLower, 'approved') || str_contains($titleLower, 'verified')) {
        $accentColor = '#075f80';
        $heading     = '✅ ' . $title;
    } elseif (str_contains($titleLower, 'revision') || str_contains($titleLower, 'correction')) {
        $accentColor = '#b86e00';
        $heading     = '⚠️ ' . $title;
    } elseif (str_contains($titleLower, 'rejected') || str_contains($titleLower, 'failed')) {
        $accentColor = '#b82525';
        $heading     = '❌ ' . $title;
    } else {
        $accentColor = '#075f80';
        $heading     = $title;
    }

    $safeLink  = $escape($link);
    $safeLabel = $escape($linkLabel);

    $htmlBody = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#172033;max-width:600px;margin:0 auto">'
        . '<div style="background:' . $accentColor . ';padding:24px 32px;border-radius:12px 12px 0 0">'
        . '<h2 style="margin:0;color:#fff;font-size:20px">' . $escape($heading) . '</h2>'
        . '</div>'
        . '<div style="background:#fff;padding:28px 32px;border:1px solid #dce6eb;border-top:0;border-radius:0 0 12px 12px">'
        . '<p>' . $escape($intro) . '</p>'
        . '<div style="background:#f3f7f8;border-left:4px solid ' . $accentColor . ';padding:14px 18px;margin:18px 0;border-radius:0 8px 8px 0">'
        . '<p style="margin:0;white-space:pre-line">' . nl2br($escape($details), false) . '</p>'
        . '</div>'
        . '<p><strong>Reference:</strong> ' . $escape($reference) . '</p>'
        . '<div style="text-align:center;margin:24px 0">'
        . '<a href="' . $safeLink . '" style="display:inline-block;background:' . $accentColor . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px">' . $safeLabel . ' →</a>'
        . '</div>'
        . '<p style="font-size:13px;color:#647687">If the button above does not work, copy and paste this link into your browser:<br><a href="' . $safeLink . '" style="color:' . $accentColor . '">' . $safeLink . '</a></p>'
        . '<hr style="border:0;border-top:1px solid #dce6eb;margin:20px 0">'
        . '<p style="font-size:12px;color:#647687">This is an automated message from <strong>PERMIT</strong>. Do not reply to this email.</p>'
        . '</div>'
        . '</body></html>';

    $textBody = $intro . PHP_EOL . PHP_EOL
        . $details . PHP_EOL . PHP_EOL
        . 'Reference: ' . $reference . PHP_EOL . PHP_EOL
        . $linkLabel . ': ' . $link . PHP_EOL . PHP_EOL
        . 'PERMIT';

    return [
        'to_email'  => (string) ($job['email'] ?? ''),
        'to_name'   => $name,
        'subject'   => $subject,
        'html_body' => $htmlBody,
        'text_body' => $textBody,
    ];
}
/**
 * Send applicant and administrator alerts independently. Failures are logged
 * and returned without preventing the other delivery attempt.
 */
function send_document_validation_failure_notifications(
    string $applicantEmail,
    string $applicantName,
    string $applicationId,
    string $documentType,
    string $validationMessage,
    string $applicationLink,
    ?string $adminDashboardLink = null,
    ?callable $transport = null
): array {
    $name = mail_single_line($applicantName, 'Applicant');
    $applicationId = mail_single_line($applicationId, 'Unknown');
    $documentType = mail_single_line($documentType, 'Uploaded document');
    $validationMessage = trim(str_replace(chr(13), '', $validationMessage));
    if ($validationMessage === '') {
        $validationMessage = 'The file did not meet the automated document validation rules.';
    }
    $applicationLink = mail_http_url($applicationLink);
    $adminDashboardLink = mail_http_url((string) ($adminDashboardLink ?? $applicationLink));
    $applicantIntro = 'Hello ' . $name . ',' . PHP_EOL . PHP_EOL
        . 'Your uploaded ' . $documentType . ' document is invalid and needs to be replaced.';
    $applicantInstructions = 'Please log in to the permit portal, open application '
        . $applicationId . ', and upload a corrected document.';
    $adminIntro = 'A document has been marked invalid by automated validation.'
        . PHP_EOL . PHP_EOL . 'Applicant: ' . $name
        . PHP_EOL . 'Application ID: ' . $applicationId
        . PHP_EOL . 'Document type: ' . $documentType;
    $applicantText = $applicantIntro . PHP_EOL . PHP_EOL . $validationMessage
        . PHP_EOL . PHP_EOL . $applicantInstructions
        . ($applicationLink !== '' ? PHP_EOL . PHP_EOL . 'Application link: ' . $applicationLink : '');
    $adminText = $adminIntro . PHP_EOL . PHP_EOL . $validationMessage
        . ($adminDashboardLink !== '' ? PHP_EOL . PHP_EOL . 'Admin dashboard: ' . $adminDashboardLink : '');
    $settings = mail_settings();
    $messages = [
        'applicant' => [
            'to_email' => $applicantEmail,
            'to_name' => $name,
            'subject' => 'Action Required: Document Validation Failed for Your Application',
            'html_body' => validation_email_html(
                'Document validation failed',
                $applicantIntro,
                $validationMessage . PHP_EOL . PHP_EOL . $applicantInstructions,
                $applicationLink,
                'Open application and resubmit'
            ),
            'text_body' => $applicantText,
        ],
        'admin' => [
            'to_email' => strtolower(trim((string) ($settings['admin_address'] ?? ''))),
            'to_name' => mail_single_line((string) ($settings['admin_name'] ?? ''), 'PERMIT Administrator'),
            'subject' => 'Alert: Invalid Document Submitted by ' . $name,
            'html_body' => validation_email_html(
                'Invalid document alert',
                $adminIntro,
                $validationMessage,
                $adminDashboardLink,
                'Review in admin dashboard'
            ),
            'text_body' => $adminText,
        ],
    ];

    $results = [];
    foreach ($messages as $role => $message) {
        try {
            send_app_mail($message + [
                'recipient_role' => $role,
                'application_id' => $applicationId,
                'document_type' => $documentType,
            ], $transport);
            $results[$role] = ['sent' => true, 'error' => null];
        } catch (Throwable $exception) {
            log_mail_failure('document alert (' . $role . ', application ' . $applicationId . ')', $exception);
            $results[$role] = ['sent' => false, 'error' => $exception->getMessage()];
        }
    }
    return $results;
}

/** Send one pair of alerts containing all invalid documents in an application. */
function send_application_scan_failure_emails(
    string $applicantEmail,
    string $applicantName,
    string $applicationId,
    array $failures,
    string $applicationLink,
    string $adminDashboardLink,
    ?callable $transport = null
): array {
    $labels = [];
    $messages = [];
    foreach ($failures as $failure) {
        if (!is_array($failure)) {
            continue;
        }
        $labels[] = mail_single_line((string) ($failure['label'] ?? ''), 'Uploaded document');
        $messages[] = document_validation_failure_message($failure);
    }
    if (!$messages) {
        $error = ['sent' => false, 'error' => 'No validation failures were provided.'];
        return ['applicant' => $error, 'admin' => $error];
    }
    $documentType = count($labels) === 1
        ? $labels[0]
        : count($labels) . ' documents: ' . implode(', ', $labels);
    return send_document_validation_failure_notifications(
        $applicantEmail,
        $applicantName,
        $applicationId,
        $documentType,
        implode(PHP_EOL . PHP_EOL . str_repeat('-', 20) . PHP_EOL . PHP_EOL, $messages),
        $applicationLink,
        $adminDashboardLink,
        $transport
    );
}

function send_auth_otp_email(
    string $email,
    string $name,
    string $code,
    string $purpose,
    ?callable $transport = null
): void {
    if (!preg_match('/^\d{6}$/D', $code)) {
        throw new InvalidArgumentException('The verification code must contain six digits.');
    }

    $isRegistration = $purpose === 'registration';
    if (!$isRegistration && $purpose !== 'password_reset') {
        throw new InvalidArgumentException('Unsupported verification purpose.');
    }

    $otpSettings = app_config('auth_otp');
    $expirySeconds = is_array($otpSettings) ? max(60, (int) ($otpSettings['expiry_seconds'] ?? 600)) : 600;
    $expiryMinutes = max(1, (int) ceil($expirySeconds / 60));
    $appName = 'PERMIT';
    $displayName = trim($name) !== '' ? trim($name) : 'Applicant';
    $safeName = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $action = $isRegistration ? 'complete your applicant registration' : 'reset your account password';
    $subjectAction = $isRegistration ? 'registration' : 'password reset';

    $htmlBody = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#172033">'
        . '<p>Hello ' . $safeName . ',</p>'
        . '<p>Use this verification code to ' . $action . ':</p>'
        . '<p style="font-size:30px;font-weight:700;letter-spacing:8px;margin:24px 0">' . $code . '</p>'
        . '<p>This code expires in ' . $expiryMinutes . ' minutes. Do not share it with anyone.</p>'
        . '<p>If you did not request this, you can safely ignore this email.</p>'
        . '<p>' . $safeAppName . '</p>'
        . '</body></html>';
    $textBody = "Hello {$displayName},\n\n"
        . "Your verification code is: {$code}\n\n"
        . "Use it to {$action}. It expires in {$expiryMinutes} minutes. Do not share it with anyone.\n\n"
        . "If you did not request this, you can safely ignore this email.\n\n{$appName}";

    send_app_mail([
        'to_email' => $email,
        'to_name' => $displayName,
        'subject' => $appName . ' ' . $subjectAction . ' code',
        'html_body' => $htmlBody,
        'text_body' => $textBody,
        // Metadata is useful to deterministic, injected transports in tests.
        'code' => $code,
        'purpose' => $purpose,
    ], $transport);
}

function send_permit_released_email(
    string $email,
    string $name,
    string $businessName,
    string $permitNumber,
    string $reference,
    string $certificateUrl,
    ?callable $transport = null
): void {
    $appName    = 'PERMIT';
    $displayName = trim($name) !== '' ? trim($name) : 'Applicant';
    $safeName    = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBiz     = htmlspecialchars($businessName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safePermit  = htmlspecialchars($permitNumber,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeRef     = htmlspecialchars($reference,     ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl     = htmlspecialchars($certificateUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeApp     = htmlspecialchars($appName,        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $htmlBody = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#172033;max-width:600px;margin:0 auto">'
        . '<div style="background:linear-gradient(135deg,#075f80,#087b88);padding:32px 36px;border-radius:12px 12px 0 0">'
        . '<h2 style="margin:0;color:#fff;font-size:22px">🎉 Your Business Permit is Ready!</h2>'
        . '</div>'
        . '<div style="background:#fff;padding:32px 36px;border:1px solid #dce6eb;border-top:0;border-radius:0 0 12px 12px">'
        . '<p>Hello <strong>' . $safeName . '</strong>,</p>'
        . '<p>Great news! The BPLO has officially released your business permit for:</p>'
        . '<div style="background:#f3f7f8;border-left:4px solid #075f80;padding:16px 20px;margin:20px 0;border-radius:0 8px 8px 0">'
        . '<strong style="font-size:18px">' . $safeBiz . '</strong><br>'
        . '<span style="color:#647687">Business Permit No. <strong style="color:#172033">' . $safePermit . '</strong></span><br>'
        . '<span style="color:#647687">Reference: ' . $safeRef . '</span>'
        . '</div>'
        . '<p>Your official Business Permit Certificate is now available. You can view, print, or save it as a PDF from the link below.</p>'
        . '<div style="text-align:center;margin:28px 0">'
        . '<a href="' . $safeUrl . '" style="display:inline-block;background:#075f80;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700;font-size:15px">View Business Permit Certificate →</a>'
        . '</div>'
        . '<p style="font-size:13px;color:#647687">If the button above does not work, copy and paste this link into your browser:<br><a href="' . $safeUrl . '" style="color:#075f80">' . $safeUrl . '</a></p>'
        . '<hr style="border:0;border-top:1px solid #dce6eb;margin:24px 0">'
        . '<p style="font-size:12px;color:#647687">This is an automated message from <strong>' . $safeApp . '</strong>. Do not reply to this email.</p>'
        . '</div>'
        . '</body></html>';

    $textBody = "Hello {$displayName},\n\n"
        . "Your business permit has been officially released by the BPLO.\n\n"
        . "Business: {$businessName}\n"
        . "Permit No.: {$permitNumber}\n"
        . "Reference: {$reference}\n\n"
        . "View your Business Permit Certificate here:\n{$certificateUrl}\n\n"
        . "Congratulations!\n\n{$appName}";

    send_app_mail([
        'to_email' => $email,
        'to_name'  => $displayName,
        'subject'  => 'Business permit certificate ready — ' . $permitNumber,
        'html_body' => $htmlBody,
        'text_body' => $textBody,
    ], $transport);
}
