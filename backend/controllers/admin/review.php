<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
$user = require_role('admin');
$pdo = db();
$installmentsReady = installment_workflow_ready($pdo);
$paymentPolicy = $installmentsReady ? permit_payment_policy($pdo) : null;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'application_id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('Application not found.'); }

$statement = $pdo->prepare('SELECT a.*, b.business_name, b.business_type, b.organization_type, b.tin, b.contact, b.email, b.address, b.latitude, b.longitude, b.location_accuracy_m, b.location_captured_at, u.name owner_name FROM applications a JOIN businesses b ON b.id = a.business_id JOIN users u ON u.id = a.user_id WHERE a.id = ?');
$statement->execute([$id]);
$application = $statement->fetch();
if (!$application) { http_response_code(404); exit('Application not found.'); }

$paymentWorkflowReady = true;
try {
    $paymentStatement = $pdo->prepare('SELECT * FROM payments WHERE application_id = ? LIMIT 1');
    $paymentStatement->execute([$id]);
    $payment = $paymentStatement->fetch() ?: null;
} catch (PDOException) {
    $paymentWorkflowReady = false;
    $payment = null;
}

$feeFormulaInstalled = true;
try {
    $feeAssessment = permit_fee_assessment($pdo, $application);
} catch (PDOException) {
    $feeFormulaInstalled = false;
    $feeAssessment = null;
}

$errors = [];
if ($installmentsReady) {
    $savedQuote = json_decode((string)($application['renewal_quote'] ?? ''),true) ?: null;
    if ($savedQuote) $feeAssessment = $savedQuote['assessment'];
    if ($payment && decoded_fee_breakdown($payment['assessment_breakdown'] ?? null)) $feeAssessment = decoded_fee_breakdown($payment['assessment_breakdown']);
}
if ($feeAssessment && $application['application_type'] === 'Renewal') {
    $existingFsic = get_fsic_compliance_for_application($pdo, (int) $id);
    if ($existingFsic && (float)($existingFsic['penalty_amount'] ?? 0) > 0) {
        $hasPen = false;
        foreach ($feeAssessment['components'] as $comp) {
            if (($comp['key'] ?? '') === 'fsic_late_renewal_penalty') { $hasPen = true; break; }
        }
        if (!$hasPen) {
            $feeAssessment['components'][] = [
                'key' => 'fsic_late_renewal_penalty',
                'label' => 'FSIC late renewal surcharge (9%)',
                'amount' => (float) $existingFsic['penalty_amount'],
            ];
            $feeAssessment['total'] = round((float)$feeAssessment['total'] + (float)$existingFsic['penalty_amount'], 2);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $decision = (string) ($_POST['decision'] ?? '');
    $notes = trim((string) ($_POST['admin_notes'] ?? ''));
    $allowed = ['For Review', 'Needs Revision', 'Approved', 'Released', 'Rejected'];
    $assessedAmountRaw = $feeAssessment ? (string) $feeAssessment['total'] : str_replace([',', '₱', ' '], '', (string) ($_POST['assessed_amount'] ?? ''));
    if (!in_array($decision, $allowed, true)) $errors[] = 'Select a valid decision.';
    if (in_array($decision, ['Needs Revision', 'Rejected'], true) && strlen($notes) < 5) $errors[] = 'Explain what the applicant must correct or why the application was rejected.';
    if ($decision === 'Approved') {
        if (!$paymentWorkflowReady) $errors[] = 'Import database/migrations/004_payment_workflow.sql before approving and assessing fees.';
        if ($feeFormulaInstalled && !$feeAssessment) $errors[] = 'Configure the official permit fee schedule before approving this application.';
        if (!is_numeric($assessedAmountRaw) || (float) $assessedAmountRaw <= 0 || (float) $assessedAmountRaw > 99999999.99) $errors[] = 'Enter a valid assessed permit fee before approval.';
    }
    if (!$installmentsReady && $decision === 'Released' && (!$payment || $payment['status'] !== 'Paid')) $errors[] = 'Payment must be verified as paid before releasing the permit.';
    if ($installmentsReady && in_array($decision,['Approved','Released'],true) && !isset($_POST['confirm_permit_dates'])) $errors[] = 'Confirm the permit dates under the approved LGU policy.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $appLock=$pdo->prepare('SELECT * FROM applications WHERE id=? FOR UPDATE');
            $appLock->execute([$id]);
            $lockedApplication=$appLock->fetch();
            if (!$lockedApplication || $lockedApplication['status']!==$application['status']) throw new RuntimeException('The application changed. Reload before making a decision.');
            $application=$lockedApplication+$application;
            if ($installmentsReady && in_array($decision,['Approved','Released'],true)) {
                confirm_permit_term($pdo,$application,(string)($_POST['permit_effective_date']??''),(string)($_POST['permit_expiration_date']??''),(int)$user['id'],$paymentPolicy);
                $appLock->execute([$id]);
                $application=$appLock->fetch()+$application;
                if ($decision==='Released') {
                    if ($application['status']!=='Approved' && $application['status']!=='Released') throw new RuntimeException('Approve the application before releasing the permit.');
                    assert_permit_release($pdo,$application);
                }
            }
            $permitNumber = $application['permit_number'];
            if (in_array($decision, ['Approved', 'Released'], true) && !$permitNumber) $permitNumber = create_permit_number($pdo);
            $stage = application_stage($decision);
            $update = $pdo->prepare('UPDATE applications SET status = ?, stage = ?, admin_notes = ?, permit_number = ?, reviewed_at = NOW(), approved_at = CASE WHEN ? IN (\'Approved\', \'Released\') THEN COALESCE(approved_at, NOW()) ELSE approved_at END WHERE id = ?');
            $update->execute([$decision, $stage, $notes ?: null, $permitNumber ?: null, $decision, $id]);

            $fsicIssueInput = !empty($_POST['fsic_issue_date']) ? (string)$_POST['fsic_issue_date'] : null;
            $fsicExpiryInput = !empty($_POST['fsic_expiry_date']) ? (string)$_POST['fsic_expiry_date'] : null;
            if ($fsicExpiryInput || in_array($decision, ['Approved', 'Released'], true)) {
                $existingFsic = get_fsic_compliance_for_application($pdo, (int) $id);
                $fallbackDate = $application['approved_at'] ? date('Y-m-d', strtotime($application['approved_at'])) : date('Y-m-d');
                $fallbackExpiry = date('Y-m-d', strtotime($fallbackDate . ' +1 year -1 day'));
                $resolvedExpiry = $fsicExpiryInput ?: ($existingFsic['fsic_expiry_date'] ?? $fallbackExpiry);
                $resolvedIssue = $fsicIssueInput ?: ($existingFsic['fsic_issue_date'] ?? $fallbackDate);

                if ($resolvedExpiry) {
                    save_fsic_compliance($pdo, (int) $id, (int) $application['business_id'], [
                        'fsic_issue_date' => $resolvedIssue,
                        'fsic_expiry_date' => $resolvedExpiry,
                        'fsic_status' => ($resolvedExpiry < date('Y-m-d')) ? 'expired' : 'valid',
                        'base_fsic_fee' => (float) ($existingFsic['base_fsic_fee'] ?? 0.00),
                        'penalty_rate' => (float) ($existingFsic['penalty_rate'] ?? 0.0000),
                        'penalty_amount' => (float) ($existingFsic['penalty_amount'] ?? 0.00),
                        'total_amount_due' => (float) ($existingFsic['total_amount_due'] ?? 0.00),
                        'days_overdue' => (int) ($existingFsic['days_overdue'] ?? 0),
                        'notes' => $existingFsic['notes'] ?? null,
                    ], (int) $user['id']);
                }
            }

            if ($decision === 'Approved') {
                if ($installmentsReady) {
                    if (!$feeAssessment) throw new RuntimeException('A server-calculated fee assessment is required.');
                    $planPolicy=$savedQuote['plan']['policy']??$paymentPolicy;
                    $plan=build_installment_plan($feeAssessment,$application['payment_frequency'],$application['permit_effective_date'],$planPolicy);
                    create_payment_plan($pdo,(int)$id,$feeAssessment,$plan,(int)$user['id']);
                } elseif ($feeAssessment) {
                    $assessment = $pdo->prepare("INSERT INTO payments (application_id, amount, assessment_breakdown, assessed_by, assessed_at, status) VALUES (?, ?, ?, ?, NOW(), 'Pending') ON DUPLICATE KEY UPDATE amount = IF(status = 'Paid', amount, VALUES(amount)), assessment_breakdown = IF(status = 'Paid', assessment_breakdown, VALUES(assessment_breakdown)), assessed_by = IF(status = 'Paid', assessed_by, VALUES(assessed_by)), assessed_at = IF(status = 'Paid', assessed_at, NOW())");
                    $assessment->execute([$id, number_format((float) $assessedAmountRaw, 2, '.', ''), json_encode($feeAssessment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $user['id']]);
                } else {
                    $assessment = $pdo->prepare("INSERT INTO payments (application_id, amount, status) VALUES (?, ?, 'Pending') ON DUPLICATE KEY UPDATE amount = IF(status = 'Paid', amount, VALUES(amount))");
                    $assessment->execute([$id, number_format((float) $assessedAmountRaw, 2, '.', '')]);
                }
            }
            record_status($pdo, (int) $id, $decision, (int) $user['id'], $notes);
            $message = $decision === 'Approved'
                ? 'Application ' . $application['reference'] . ' was approved. Assessed permit fee: ₱' . number_format((float) $assessedAmountRaw, 2) . '. Submit payment from your application page.'
                : ($decision === 'Released'
                    ? 'Business permit certificate ready for ' . $application['business_name'] . '. Permit no. ' . ($permitNumber ?: $application['permit_number']) . ' has been released. View and download your certificate now.'
                    : 'Application ' . $application['reference'] . ' was updated to ' . $decision . '.');
            // record_status writes transactional in-app and email notifications.
            audit($pdo, (int) $user['id'], 'update_status_' . strtolower(str_replace(' ', '_', $decision)), 'application', (int) $id);
            $pdo->commit();

            flash('success', 'The application status was updated to ' . $decision . '. Notifications are queued for delivery.');
            redirect('admin/review.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'The decision could not be saved. ' . $e->getMessage();
        }
    }
}

$aiTablesReady = true;
try {
    $documents = $pdo->prepare('SELECT d.id, d.document_type, d.original_name, d.file_size, s.scan_status, s.detected_document_type, s.matches_expected_type, s.quality_score, s.confidence_score, s.extracted_fields, s.issues, s.summary, s.requires_human_review, s.model, s.error_message, s.scanned_at FROM application_documents d LEFT JOIN document_ai_scans s ON s.document_id = d.id WHERE d.application_id = ? ORDER BY d.id');
    $documents->execute([$id]);
} catch (PDOException) {
    $aiTablesReady = false;
    $documents = $pdo->prepare('SELECT id, document_type, original_name, file_size FROM application_documents WHERE application_id = ? ORDER BY id');
    $documents->execute([$id]);
}
$definitions = document_definitions();
$autoScannedCount = 0;
if ($aiTablesReady) {
    try {
        $autoScanStatement = $pdo->prepare("SELECT COUNT(DISTINCT d.id) FROM application_documents d JOIN document_ai_scans s ON s.document_id = d.id WHERE d.application_id = ? AND s.scan_status = 'Completed' AND s.scanned_by = ?");
        $autoScanStatement->execute([$id, $application['user_id']]);
        $autoScannedCount = (int) $autoScanStatement->fetchColumn();
    } catch (PDOException) {
        $autoScannedCount = 0;
    }
}
$autoScanCapacity = automatic_scan_capacity($pdo);

$prediction = estimate_application_timeline($pdo, $application);

$documentRows = $documents->fetchAll();

$installments = $installmentsReady && $payment ? payment_installments($pdo,(int)$payment['id']) : [];
$paymentBalance = $installments ? installment_balance($payment,$installments) : null;
$canRelease = $installmentsReady ? ($paymentBalance['can_release'] ?? false) : ($payment && $payment['status']==='Paid');
$effectiveSuggestion = $_POST['permit_effective_date'] ?? $application['permit_effective_date'] ?? $application['requested_effective_date'] ?? date('Y-m-d');
try { $suggestedExpiration=one_year_expiration((string)$effectiveSuggestion); }
catch (InvalidArgumentException) { $suggestedExpiration=''; }
$expirationSuggestion = $_POST['permit_expiration_date'] ?? $application['permit_expiration_date'] ?? $suggestedExpiration;

$fsicCompliance = get_fsic_compliance_for_application($pdo, (int) $id);
$fsicIssueSuggestion = $_POST['fsic_issue_date'] ?? $fsicCompliance['fsic_issue_date'] ?? $effectiveSuggestion;
$fsicExpirationSuggestion = $_POST['fsic_expiry_date'] ?? $fsicCompliance['fsic_expiry_date'] ?? $expirationSuggestion;

require dirname(__DIR__, 3) . '/frontend/views/admin/review.php';

