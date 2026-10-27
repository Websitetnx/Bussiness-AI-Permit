<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_role('applicant');
$pdo = db();
$errors = [];
$location = posted_geolocation($_POST);
$record = null;
$permitNumber = strtoupper(trim((string) ($_GET['permit_number'] ?? $_POST['permit_number'] ?? '')));

function find_renewable_permit(PDO $pdo, int $userId, string $permitNumber): ?array
{
    $statement = $pdo->prepare("SELECT a.id source_application_id, a.permit_number, a.fsic_expiry_date, a.requires_building_inspection, a.requires_electrical_inspection, a.requires_plumbing_inspection, b.* FROM applications a JOIN businesses b ON b.id = a.business_id WHERE a.user_id = ? AND a.permit_number = ? AND a.status IN ('Approved','Released') ORDER BY a.approved_at DESC, a.id DESC LIMIT 1");
    $statement->execute([$userId, $permitNumber]);
    return $statement->fetch() ?: null;
}

if ($permitNumber !== '') $record = find_renewable_permit($pdo, (int) $user['id'], $permitNumber);

try {
    $sourceFsic = $record ? get_source_fsic_info($pdo, (int) $record['source_application_id']) : null;
    $fsicWarning = ($sourceFsic && !empty($sourceFsic['fsic_expiry_date']))
        ? calculate_fsic_penalty_details(0, $sourceFsic['fsic_expiry_date'])
        : null;
} catch (PDOException) {
    $sourceFsic = null;
    $fsicWarning = null;
}



$installmentsReady = installment_workflow_ready($pdo);
$paymentPolicy = $installmentsReady ? permit_payment_policy($pdo) : null;
$quote = null;
$quoteToken = (string) ($_POST['quote_token'] ?? '');
$previewOnly = ($_POST['intent'] ?? '') === 'preview';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installmentsReady) {
    verify_csrf();
    try {
        if (!$record) throw new RuntimeException('Find a renewable permit in your account first.');
        $quote = renewal_payment_quote($pdo, $record, $_POST);
        if ($previewOnly) {
            $quoteToken = bin2hex(random_bytes(32));
            $_SESSION['renewal_quote'] = ['token'=>$quoteToken,'signature'=>renewal_quote_signature($quote),'expires'=>time()+1800];
        } else {
            $savedQuote = $_SESSION['renewal_quote'] ?? [];
            if (empty($savedQuote['token']) || !hash_equals($savedQuote['token'],$quoteToken) || ($savedQuote['expires']??0)<time() || !hash_equals($savedQuote['signature'],renewal_quote_signature($quote))) {
                throw new RuntimeException('Preview the assessment again before submitting. Dates, fees, frequency, or policy may have changed.');
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e instanceof PDOException ? 'The renewal assessment is unavailable. Contact BPLO.' : $e->getMessage();
        $quote = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$previewOnly) {
    verify_csrf();
    if (!$record) $errors[] = 'No approved or released permit was found in your account.';
    $grossSales = str_replace([',', '₱', ' '], '', (string) ($_POST['gross_sales'] ?? ''));
    $contact = trim((string) ($_POST['contact'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $address = trim((string) ($_POST['address'] ?? ''));
    $locationUpdated = ($_POST['location_updated'] ?? '') === '1';
    $requiresBuildingInspection = isset($_POST['requires_building_inspection']) ? 1 : 0;
    $requiresElectricalInspection = isset($_POST['requires_electrical_inspection']) ? 1 : 0;
    $requiresPlumbingInspection = isset($_POST['requires_plumbing_inspection']) ? 1 : 0;
    if (!is_numeric($grossSales) || (float) $grossSales < 0) $errors[] = 'Enter valid gross sales for the previous year.';
    if (strlen(preg_replace('/\D+/', '', $contact)) < 10) $errors[] = 'Enter a valid contact number.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid contact email.';
    if (strlen($address) < 8) $errors[] = 'Enter the complete business address.';
    if ($location['error']) $errors[] = $location['error'];
    if (!isset($_POST['declaration'])) $errors[] = 'Confirm the accuracy declaration before submitting.';
    if (!$errors && $record) {
        $submissionCommitted = false;
        try {
            $pdo->beginTransaction();
            if ($installmentsReady) {
                $sourceLock=$pdo->prepare('SELECT id FROM applications WHERE id=? AND user_id=? FOR UPDATE');
                $sourceLock->execute([$record['source_application_id'],$user['id']]);
                $duplicate=$pdo->prepare('SELECT id FROM applications WHERE submission_key=? AND user_id=?');
                $duplicate->execute([hash('sha256',$quoteToken),$user['id']]);
                $duplicateId=$duplicate->fetchColumn();
                if ($duplicateId) { $pdo->rollBack(); redirect('application.php?id='.(int)$duplicateId); }
            }
            $update = $pdo->prepare('UPDATE businesses SET contact = ?, email = ?, address = ?, latitude = ?, longitude = ?, location_accuracy_m = ?, location_captured_at = CASE WHEN ? = 1 THEN NOW() ELSE location_captured_at END WHERE id = ? AND user_id = ?');
            $update->execute([$contact, $email, $address, $location['latitude'], $location['longitude'], $location['accuracy'], $locationUpdated ? 1 : 0, $record['id'], $user['id']]);
            $reference = create_reference($pdo);
            $insert = $pdo->prepare("INSERT INTO applications (user_id, business_id, reference, permit_number, application_type, status, stage, gross_sales, requires_building_inspection, requires_electrical_inspection, requires_plumbing_inspection) VALUES (?, ?, ?, ?, 'Renewal', 'For Review', 1, ?, ?, ?, ?)");
            $insert->execute([$user['id'], $record['id'], $reference, $record['permit_number'], $grossSales, $requiresBuildingInspection, $requiresElectricalInspection, $requiresPlumbingInspection]);
            $applicationId = (int) $pdo->lastInsertId();
            if ($installmentsReady) {
                $paymentChoice=$pdo->prepare('UPDATE applications SET payment_frequency=?,requested_effective_date=?,renewal_quote=?,renewal_source_id=?,submission_key=? WHERE id=?');
                $paymentChoice->execute([$quote['plan']['frequency'],$quote['plan']['effective_date'],json_encode($quote,JSON_THROW_ON_ERROR),$record['source_application_id'],hash('sha256',$quoteToken),$applicationId]);
            }

            $sourceAppId = (int) ($record['source_application_id'] ?? 0);
            $fsicInfo = $sourceAppId > 0 ? get_source_fsic_info($pdo, $sourceAppId) : null;
            $fsicExpiry = $fsicInfo['fsic_expiry_date'] ?? null;
            $baseFsicFee = 0.00;
            if (!empty($quote['assessment']['components'])) {
                foreach ($quote['assessment']['components'] as $c) {
                    if (($c['key'] ?? '') === 'fire_safety_inspection_fee') {
                        $baseFsicFee = (float) ($c['amount'] ?? 0);
                        break;
                    }
                }
            }
            $penaltyDetails = $quote['fsic_penalty'] ?? calculate_fsic_penalty_details(0, $fsicExpiry);
            save_fsic_compliance($pdo, $applicationId, (int) $record['id'], [
                'fsic_issue_date' => $fsicInfo['fsic_issue_date'] ?? null,
                'fsic_expiry_date' => $fsicExpiry,
                'fsic_status' => $penaltyDetails['fsic_status'] ?? 'pending_renewal',
                'base_fsic_fee' => $baseFsicFee,
                'penalty_rate' => $penaltyDetails['penalty_rate'] ?? 0.0000,
                'penalty_amount' => $penaltyDetails['penalty_amount'] ?? 0.00,
                'total_amount_due' => $penaltyDetails['total_amount_due'] ?? 0.00,
                'days_overdue' => $penaltyDetails['days_overdue'] ?? 0,
                'penalty_applied_at' => !empty($penaltyDetails['penalty_applied']) ? date('Y-m-d H:i:s') : null,
                'notes' => !empty($penaltyDetails['penalty_applied'])
                    ? ('Late renewal surcharge (9%) applied: ' . $penaltyDetails['days_overdue'] . ' day(s) past FSIC expiry.')
                    : null,
            ], (int) $user['id']);

            if (!empty($penaltyDetails['penalty_applied'])) {
                audit($pdo, (int) $user['id'], 'apply_fsic_penalty', 'application', $applicationId);
                create_fsic_penalty_notification($pdo, $applicationId, (int) $user['id'], $penaltyDetails);
            }

            $uploadErrors = store_application_documents($pdo, $applicationId);

            if ($uploadErrors) throw new RuntimeException(implode(' ', $uploadErrors));
            notify_application($pdo,$applicationId,'submitted:'.$applicationId,'Application submitted','Your renewal was received. Documents will be checked before BPLO review.');

            // Persist before contacting the external AI service so scanning is
            // advisory and never holds the renewal transaction open.
            $pdo->commit();
            $submissionCommitted = true;

            $scanFailures = auto_scan_uploaded_documents($pdo, $applicationId, (int) $user['id']);

            $pdo->beginTransaction();
            if ($scanFailures) {
                $pdo->prepare("UPDATE applications SET status = 'Needs Revision', stage = 1 WHERE id = ?")->execute([$applicationId]);
                record_status($pdo, $applicationId, 'Needs Revision', (int) $user['id'], 'Auto-scan detected document issues requiring correction.');
                $scanEventId = queue_scan_failure_alert($pdo,$applicationId,$scanFailures);
                audit($pdo, (int) $user['id'], 'submit_renewal', 'application', $applicationId);
                $pdo->commit();

                deliver_scan_failure_alert($pdo, $scanEventId);
                flash('error', 'Your renewal was saved, but automated verification found documents that need revision. Review the results below and re-upload those files.');
                redirect('application.php?id=' . $applicationId);
            } else {
                record_status($pdo, $applicationId, 'For Review', (int) $user['id'], 'Permit renewal submitted.');
                audit($pdo, (int) $user['id'], 'submit_renewal', 'application', $applicationId);
                $pdo->commit();
                flash('success', 'Renewal submitted. Your reference is ' . $reference . '.');
                redirect('application.php?id=' . $applicationId);
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($submissionCommitted && isset($applicationId, $reference)) {
                try {
                    record_status($pdo, $applicationId, 'For Review', (int) $user['id'], 'Renewal submitted; automated verification was unavailable.');
                    audit($pdo, (int) $user['id'], 'submit_renewal', 'application', $applicationId);
                } catch (Throwable) {
                }
                flash('success', 'Renewal submitted. Automated document verification was unavailable, so BPLO will review the files normally. Your reference is ' . $reference . '.');
                redirect('application.php?id=' . $applicationId);
            }
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : ($exception instanceof PDOException ? 'Import database/migrations/005_fee_assessment_formula.sql, then submit the renewal again.' : 'The renewal could not be submitted.');
        }
    }
}

$definitions = document_definitions();

require dirname(__DIR__, 2) . '/frontend/views/renew.php';
