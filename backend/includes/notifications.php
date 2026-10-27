<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

/**
 * Intelligent Notification & Renewal Alert Engine
 *
 * Provides automated permit expiration checks, user renewal reminders,
 * status change notices, and in-app notification management.
 */

function create_notification(PDO $pdo, int $userId, string $message, ?int $applicationId = null): int
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, application_id, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $applicationId, $message]);
    return (int) $pdo->lastInsertId();
}

/**
 * Create one bounded notification per rejected document so every failure fits
 * the existing VARCHAR(500) column and remains actionable to the applicant.
 */
function create_document_scan_failure_notifications(
    PDO $pdo,
    int $userId,
    int $applicationId,
    string $reference,
    array $failures,
    string $submissionLabel = 'application'
): void {
    $count = count($failures);
    create_notification(
        $pdo,
        $userId,
        'Automated verification found ' . $count . ' document' . ($count === 1 ? '' : 's')
            . ' needing revision in ' . $submissionLabel . ' ' . $reference
            . '. Open the application to replace the listed files.',
        $applicationId
    );

    foreach ($failures as $failure) {
        $label = trim((string) ($failure['label'] ?? 'Uploaded document'));
        $reasons = array_values(array_filter(
            array_map(static fn(mixed $reason): string => trim((string) $reason), (array) ($failure['reasons'] ?? [])),
            static fn(string $reason): bool => $reason !== ''
        ));
        $message = $reference . ' - ' . $label . ' failed verification: '
            . ($reasons ? implode(' ', $reasons) : 'The file did not meet the automated document checks.')
            . ' Please re-upload a corrected copy.';

        // Core validation reasons are normally well below this limit. This is a
        // final guard for unexpectedly verbose model-provided document labels.
        if (strlen($message) > 500) {
            $suffix = ' Please open the application for the complete scan details.';
            $cutLength = 500 - strlen($suffix);
            $message = (function_exists('mb_strcut')
                ? mb_strcut($message, 0, $cutLength, 'UTF-8')
                : substr($message, 0, $cutLength)) . $suffix;
        }
        create_notification($pdo, $userId, $message, $applicationId);
    }
}

function get_user_notifications(PDO $pdo, int $userId, int $limit = 20): array
{
    $stmt = $pdo->prepare('SELECT n.id, n.message, n.application_id, n.read_at, n.created_at, a.reference, a.permit_number, a.status application_status, p.id payment_id FROM notifications n LEFT JOIN applications a ON a.id = n.application_id LEFT JOIN payments p ON p.application_id = a.id WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function get_unread_notification_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function mark_notification_as_read(PDO $pdo, int $notificationId, int $userId): bool
{
    $stmt = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
    $stmt->execute([$notificationId, $userId]);
    return $stmt->rowCount() > 0;
}

function mark_all_notifications_as_read(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * Automatically scans active permits for approaching expiration dates and triggers renewal notices.
 * Explicit BPLO-confirmed dates are used after migration 009. The old
 * issue-date calculation below is retained for pre-migration deployments.
 */
function check_and_generate_renewal_notices(PDO $pdo, ?int $userId = null): array
{
    if (function_exists('installment_workflow_ready') && installment_workflow_ready($pdo)) {
        generate_installment_reminders($pdo,$userId);
        return generate_permit_expiration_reminders($pdo,$userId);
    }
    // Find approved/released permits for this user
    $stmt = $pdo->prepare("SELECT a.id, a.user_id, a.reference, a.permit_number, a.approved_at, a.submitted_at, b.business_name FROM applications a JOIN businesses b ON b.id = a.business_id WHERE a.status IN ('Approved', 'Released') AND a.permit_number IS NOT NULL" . ($userId !== null ? ' AND a.user_id = ?' : '') . ' ORDER BY a.approved_at DESC');
    $stmt->execute($userId !== null ? [$userId] : []);
    $permits = $stmt->fetchAll();

    $generatedAlerts = [];
    $currentDate = new DateTimeImmutable();
    $currentYear = (int) $currentDate->format('Y');

    foreach ($permits as $permit) {
        $approvedDate = !empty($permit['approved_at']) ? new DateTimeImmutable($permit['approved_at']) : new DateTimeImmutable($permit['submitted_at']);

        // Business permits expire one year (inclusive) from the approved/issued date
        $expiryDate = $approvedDate->modify('+1 year')->modify('-1 day')->setTime(23, 59, 59);
        $daysUntilExpiry = (int) $currentDate->diff($expiryDate)->format('%r%a');

        // Notify within 60 days before expiration, and separately after expiration.
        $isExpiringSoon = $daysUntilExpiry <= 60 && $daysUntilExpiry >= 0;
        $isExpired = $currentDate > $expiryDate;

        if ($isExpiringSoon || $isExpired) {
            // Check if renewal application is already in progress for this permit
            $renewalCheck = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE user_id = ? AND permit_number = ? AND application_type = 'Renewal' AND status IN ('For Review', 'Needs Revision', 'Approved', 'Released') AND YEAR(submitted_at) = ?");
            $renewalCheck->execute([$permit['user_id'], $permit['permit_number'], $currentYear]);
            $hasRenewal = (int) $renewalCheck->fetchColumn() > 0;

            if (!$hasRenewal) {
                // The durable event queues email even if an older in-app-only notice exists.
                // Separate upcoming/expired keys allow one email at each stage without duplicates.
                $noticeText = $isExpired
                        ? "⚠️ Action Required: Business permit {$permit['permit_number']} for {$permit['business_name']} expired on {$expiryDate->format('M j, Y')}. Submit your renewal application now to avoid late penalties."
                        : "🔔 Renewal Notice: Business permit {$permit['permit_number']} for {$permit['business_name']} will expire in {$daysUntilExpiry} days ({$expiryDate->format('M j, Y')}). Submit renewal early for fast processing.";

                notify_application($pdo, (int) $permit['id'],
                    'permit-expiration:' . $permit['id'] . ':' . $expiryDate->format('Y-m-d') . ':' . ($isExpired ? 'expired' : 'upcoming'),
                    $isExpired ? 'Permit expiration notice' : 'Approaching permit expiration',
                    $noticeText, 'applicant');

                $generatedAlerts[] = [
                    'permit_number' => $permit['permit_number'],
                    'business_name' => $permit['business_name'],
                    'application_id' => (int) $permit['id'],
                    'expiry_date' => $expiryDate->format('M j, Y'),
                    'days_until_expiry' => $daysUntilExpiry,
                    'is_expired' => $isExpired,
                ];
            }
        }
    }

    return $generatedAlerts;
}

function create_fsic_penalty_notification(PDO $pdo, int $applicationId, int $userId, array $penaltyDetails): void
{
    $penaltyAmountFormatted = '₱' . number_format((float) ($penaltyDetails['penalty_amount'] ?? 0), 2);
    $totalFormatted = '₱' . number_format((float) ($penaltyDetails['total_amount_due'] ?? 0), 2);
    $daysOverdue = (int) ($penaltyDetails['days_overdue'] ?? 0);
    $message = "A 9% late renewal surcharge of {$penaltyAmountFormatted} was applied to application #{$applicationId} because the FSIC expired {$daysOverdue} day(s) ago. Total amount due: {$totalFormatted}.";

    create_notification($pdo, $userId, $message, $applicationId);

    notify_application(
        $pdo,
        $applicationId,
        'fsic-penalty:' . $applicationId . ':' . date('Y-m-d'),
        'FSIC Late Renewal Penalty Applied',
        $message,
        'all'
    );
}

