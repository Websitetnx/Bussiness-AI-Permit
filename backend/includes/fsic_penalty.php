<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

/**
 * Evaluates the penalty rate based on days overdue past the FSIC expiry date.
 * Rule: 1 to 30 days past expiry incurs a 9% surcharge.
 */
function fsic_penalty_rule(int $daysOverdue): array
{
    if ($daysOverdue >= 1 && $daysOverdue <= 30) {
        return [
            'rate' => 0.0900,
            'percent' => 9.0,
            'tier' => '1_to_30_days',
            'applies' => true,
            'label' => 'FSIC late renewal surcharge (9%)',
        ];
    }

    return [
        'rate' => 0.0000,
        'percent' => 0.0,
        'tier' => $daysOverdue > 30 ? 'over_30_days' : 'none',
        'applies' => false,
        'label' => 'No FSIC surcharge',
    ];
}

/**
 * Calculates late renewal penalty details for a given base amount and FSIC expiry date.
 */
function calculate_fsic_penalty_details(float|int|string $baseTotalAmount, ?string $fsicExpiryDate, ?string $asOfDate = null): array
{
    $asOf = $asOfDate ? permit_date($asOfDate)->format('Y-m-d') : date('Y-m-d');
    $daysOverdue = 0;
    $fsicStatus = 'valid';

    if (!empty($fsicExpiryDate)) {
        $expiry = permit_date($fsicExpiryDate)->format('Y-m-d');
        if ($asOf > $expiry) {
            $dExpiry = new DateTimeImmutable($expiry);
            $dAsOf = new DateTimeImmutable($asOf);
            $daysOverdue = (int) $dExpiry->diff($dAsOf)->days;
            $fsicStatus = 'expired';
        }
    }

    $rule = fsic_penalty_rule($daysOverdue);
    $baseCents = money_cents($baseTotalAmount);

    if ($rule['applies']) {
        $penaltyCents = (int) round($baseCents * $rule['rate']);
        $totalDueCents = $baseCents + $penaltyCents;
    } else {
        $penaltyCents = 0;
        $totalDueCents = $baseCents;
    }

    return [
        'days_overdue' => $daysOverdue,
        'fsic_expiry_date' => $fsicExpiryDate,
        'as_of_date' => $asOf,
        'penalty_rate' => $rule['rate'],
        'penalty_percent' => $rule['percent'],
        'penalty_amount' => (float) cents_amount($penaltyCents),
        'penalty_amount_cents' => $penaltyCents,
        'base_total_amount' => (float) cents_amount($baseCents),
        'total_amount_due' => (float) cents_amount($totalDueCents),
        'total_amount_due_cents' => $totalDueCents,
        'fsic_status' => $fsicStatus,
        'penalty_applied' => $penaltyCents > 0,
        'tier' => $rule['tier'],
        'label' => $rule['label'],
    ];
}

/**
 * Looks up the FSIC date details from a source (previous) application.
 * Falls back to approved_at-derived dates if explicit FSIC dates have not yet been recorded.
 */
function get_source_fsic_info(PDO $pdo, int $sourceApplicationId): ?array
{
    $stmt = $pdo->prepare('
        SELECT a.id, a.business_id, a.approved_at, a.submitted_at, a.fsic_expiry_date AS app_fsic_expiry,
               f.fsic_issue_date, f.fsic_expiry_date AS compliance_fsic_expiry, f.fsic_status, f.base_fsic_fee
        FROM applications a
        LEFT JOIN fsic_compliance f ON f.application_id = a.id
        WHERE a.id = ?
        LIMIT 1
    ');
    $stmt->execute([$sourceApplicationId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $fallbackIssue = $row['approved_at'] ? date('Y-m-d', strtotime($row['approved_at'])) : ($row['submitted_at'] ? date('Y-m-d', strtotime($row['submitted_at'])) : null);
    $fallbackExpiry = $fallbackIssue ? date('Y-m-d', strtotime($fallbackIssue . ' +1 year -1 day')) : null;

    $issueDate = $row['fsic_issue_date'] ?: $fallbackIssue;
    $expiryDate = $row['compliance_fsic_expiry'] ?: ($row['app_fsic_expiry'] ?: $fallbackExpiry);

    return [
        'application_id' => (int) $row['id'],
        'business_id' => (int) $row['business_id'],
        'fsic_issue_date' => $issueDate,
        'fsic_expiry_date' => $expiryDate,
        'fsic_status' => $row['fsic_status'] ?? ($expiryDate && $expiryDate < date('Y-m-d') ? 'expired' : 'valid'),
        'base_fsic_fee' => (float) ($row['base_fsic_fee'] ?? 0.00),
    ];
}


/**
 * Appends the FSIC late renewal penalty to an assessment breakdown structure
 * and adjusts the total so all component sums match the assessment total.
 */
function inject_fsic_penalty_to_assessment(array $assessment, array $penaltyDetails): array
{
    if (empty($penaltyDetails['penalty_applied'])) {
        return $assessment;
    }

    $existingComponents = $assessment['components'] ?? [];
    // Prevent duplicate penalty components
    $filtered = array_values(array_filter(
        $existingComponents,
        static fn(array $c): bool => ($c['key'] ?? '') !== 'fsic_late_renewal_penalty'
    ));

    $filtered[] = [
        'key' => 'fsic_late_renewal_penalty',
        'label' => $penaltyDetails['label'] ?? 'FSIC late renewal surcharge (9%)',
        'amount' => $penaltyDetails['penalty_amount'],
        'penalty_rate' => $penaltyDetails['penalty_rate'],
        'days_overdue' => $penaltyDetails['days_overdue'],
    ];

    $assessment['components'] = $filtered;
    $assessment['total'] = $penaltyDetails['total_amount_due'];
    $assessment['fsic_penalty'] = $penaltyDetails;

    return $assessment;
}

/**
 * Inserts or updates the FSIC compliance record for an application.
 */
function save_fsic_compliance(PDO $pdo, int $applicationId, int $businessId, array $data, ?int $userId = null): int
{
    $issueDate = !empty($data['fsic_issue_date']) ? $data['fsic_issue_date'] : null;
    $expiryDate = !empty($data['fsic_expiry_date']) ? $data['fsic_expiry_date'] : null;
    $status = in_array($data['fsic_status'] ?? '', ['valid', 'expired', 'pending_renewal'], true)
        ? $data['fsic_status']
        : 'pending_renewal';
    $baseFee = (float) ($data['base_fsic_fee'] ?? 0.00);
    $rate = (float) ($data['penalty_rate'] ?? 0.0000);
    $penaltyAmount = (float) ($data['penalty_amount'] ?? 0.00);
    $totalAmountDue = (float) ($data['total_amount_due'] ?? 0.00);
    $daysOverdue = (int) ($data['days_overdue'] ?? 0);
    $penaltyAppliedAt = !empty($data['penalty_applied_at']) ? $data['penalty_applied_at'] : ($penaltyAmount > 0 ? date('Y-m-d H:i:s') : null);
    $notes = !empty($data['notes']) ? (string) $data['notes'] : null;

    $stmt = $pdo->prepare('
        INSERT INTO fsic_compliance (
            application_id, business_id, fsic_issue_date, fsic_expiry_date, fsic_status,
            base_fsic_fee, penalty_rate, penalty_amount, total_amount_due, days_overdue,
            penalty_applied_at, penalty_applied_by, notes
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            business_id = VALUES(business_id),
            fsic_issue_date = VALUES(fsic_issue_date),
            fsic_expiry_date = VALUES(fsic_expiry_date),
            fsic_status = VALUES(fsic_status),
            base_fsic_fee = VALUES(base_fsic_fee),
            penalty_rate = VALUES(penalty_rate),
            penalty_amount = VALUES(penalty_amount),
            total_amount_due = VALUES(total_amount_due),
            days_overdue = VALUES(days_overdue),
            penalty_applied_at = VALUES(penalty_applied_at),
            penalty_applied_by = VALUES(penalty_applied_by),
            notes = VALUES(notes),
            updated_at = NOW()
    ');

    $stmt->execute([
        $applicationId, $businessId, $issueDate, $expiryDate, $status,
        $baseFee, $rate, $penaltyAmount, $totalAmountDue, $daysOverdue,
        $penaltyAppliedAt, $userId, $notes
    ]);

    // Keep quick-lookup column on applications table in sync
    if ($expiryDate) {
        $sync = $pdo->prepare('UPDATE applications SET fsic_expiry_date = ? WHERE id = ?');
        $sync->execute([$expiryDate, $applicationId]);
    }

    return (int) ($pdo->lastInsertId() ?: $applicationId);
}

/**
 * Gets the FSIC compliance record for an application.
 */
function get_fsic_compliance_for_application(PDO $pdo, int $applicationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM fsic_compliance WHERE application_id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    return $stmt->fetch() ?: null;
}

/**
 * Gets the latest FSIC compliance record for a business.
 */
function get_fsic_compliance_for_business(PDO $pdo, int $businessId): ?array
{
    $stmt = $pdo->prepare('
        SELECT f.*, a.reference, a.permit_number, a.status AS application_status
        FROM fsic_compliance f
        JOIN applications a ON a.id = f.application_id
        WHERE f.business_id = ?
        ORDER BY f.id DESC
        LIMIT 1
    ');
    $stmt->execute([$businessId]);
    return $stmt->fetch() ?: null;
}

/**
 * Returns FSIC warnings for an applicant dashboard (e.g. expiring within 30 days or already expired).
 */
function get_user_fsic_warnings(PDO $pdo, int $userId): array
{
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT a.id AS application_id, a.business_id, a.permit_number, b.business_name,
               COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) AS fsic_expiry_date,
               f.fsic_status
        FROM applications a
        JOIN businesses b ON b.id = a.business_id
        LEFT JOIN fsic_compliance f ON f.application_id = a.id
        WHERE a.user_id = ?
          AND a.status IN ('Approved', 'Released')
          AND COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) IS NOT NULL
        ORDER BY fsic_expiry_date ASC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();


    $warnings = [];
    foreach ($rows as $row) {
        $expiry = $row['fsic_expiry_date'];
        if (!$expiry) continue;

        $dExpiry = new DateTimeImmutable($expiry);
        $dToday = new DateTimeImmutable($today);

        if ($dToday > $dExpiry) {
            $daysOverdue = (int) $dExpiry->diff($dToday)->days;
            $penaltyRule = fsic_penalty_rule($daysOverdue);
            $warnings[] = [
                'type' => 'expired',
                'application_id' => (int) $row['application_id'],
                'business_id' => (int) $row['business_id'],
                'business_name' => $row['business_name'],
                'permit_number' => $row['permit_number'],
                'fsic_expiry_date' => $expiry,
                'days_overdue' => $daysOverdue,
                'penalty_percent' => $penaltyRule['percent'],
                'penalty_applies' => $penaltyRule['applies'],
            ];
        } else {
            $daysRemaining = (int) $dToday->diff($dExpiry)->days;
            if ($daysRemaining <= 30) {
                $warnings[] = [
                    'type' => 'expiring_soon',
                    'application_id' => (int) $row['application_id'],
                    'business_id' => (int) $row['business_id'],
                    'business_name' => $row['business_name'],
                    'permit_number' => $row['permit_number'],
                    'fsic_expiry_date' => $expiry,
                    'days_remaining' => $daysRemaining,
                    'penalty_percent' => 9.0,
                ];
            }
        }
    }

    return $warnings;
}
