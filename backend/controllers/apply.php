<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_role('applicant');
$errors = [];
$location = posted_geolocation($_POST);
$allowedBusinessTypes = business_type_options();
$allowedOrganizations = ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative', 'Banking Institution/Other Financial Institution'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $businessName = trim((string) ($_POST['business_name'] ?? ''));
    $businessType = trim((string) ($_POST['business_type'] ?? ''));
    $organizationType = trim((string) ($_POST['organization_type'] ?? ''));
    $tin = trim((string) ($_POST['tin'] ?? ''));
    $contact = trim((string) ($_POST['contact'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $address = trim((string) ($_POST['address'] ?? ''));
    $declaredCapital = str_replace([',', '₱', ' '], '', (string) ($_POST['declared_capital'] ?? ''));
    $requiresBuildingInspection = isset($_POST['requires_building_inspection']) ? 1 : 0;
    $requiresElectricalInspection = isset($_POST['requires_electrical_inspection']) ? 1 : 0;
    $requiresPlumbingInspection = isset($_POST['requires_plumbing_inspection']) ? 1 : 0;

    if (strlen($businessName) < 2 || strlen($businessName) > 190) $errors[] = 'Enter the registered business name.';
    if (!in_array($businessType, $allowedBusinessTypes, true)) $errors[] = 'Select a valid business type.';
    if (!in_array($organizationType, $allowedOrganizations, true)) $errors[] = 'Select a valid organization type.';
    if (!preg_match('/^\d{3}-?\d{3}-?\d{3}(?:-?\d{3})?$/', preg_replace('/\s+/', '', $tin))) $errors[] = 'Enter a valid 9 or 12-digit TIN.';
    if (strlen(preg_replace('/\D+/', '', $contact)) < 10) $errors[] = 'Enter a valid contact number.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid contact email.';
    if (strlen($address) < 8) $errors[] = 'Enter the complete business address.';
    if (!is_numeric($declaredCapital) || (float) $declaredCapital <= 0 || (float) $declaredCapital > 999999999999.99) $errors[] = 'Enter a valid declared capital investment.';
    if ($location['error']) $errors[] = $location['error'];
    if (!isset($_POST['declaration'])) $errors[] = 'Confirm the accuracy declaration before submitting.';

    if (!$errors) {
        $pdo = db();
        $submissionCommitted = false;
        try {
            $pdo->beginTransaction();
            $businessStatement = $pdo->prepare('INSERT INTO businesses (user_id, business_name, business_type, organization_type, tin, contact, email, address, latitude, longitude, location_accuracy_m, location_captured_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $businessStatement->execute([$user['id'], $businessName, $businessType, $organizationType, $tin, $contact, $email, $address, $location['latitude'], $location['longitude'], $location['accuracy'], $location['latitude'] !== null ? date('Y-m-d H:i:s') : null]);
            $businessId = (int) $pdo->lastInsertId();
            $reference = create_reference($pdo);
            $applicationStatement = $pdo->prepare("INSERT INTO applications (user_id, business_id, reference, application_type, status, stage, declared_capital, requires_building_inspection, requires_electrical_inspection, requires_plumbing_inspection) VALUES (?, ?, ?, 'New', 'For Review', 1, ?, ?, ?, ?)");
            $applicationStatement->execute([$user['id'], $businessId, $reference, number_format((float) $declaredCapital, 2, '.', ''), $requiresBuildingInspection, $requiresElectricalInspection, $requiresPlumbingInspection]);
            $applicationId = (int) $pdo->lastInsertId();
            $uploadErrors = store_application_documents($pdo, $applicationId);
            if ($uploadErrors) throw new RuntimeException(implode(' ', $uploadErrors));
            notify_application($pdo,$applicationId,'submitted:'.$applicationId,'Application submitted','Your new application was received. Documents will be checked before BPLO review.');

            // Persist before contacting the external AI service so a slow or
            // unavailable scan never holds application database locks.
            $pdo->commit();
            $submissionCommitted = true;

            $scanFailures = auto_scan_uploaded_documents($pdo, $applicationId, (int) $user['id']);

            $pdo->beginTransaction();
            if ($scanFailures) {
                $pdo->prepare("UPDATE applications SET status = 'Needs Revision', stage = 1 WHERE id = ?")->execute([$applicationId]);
                record_status($pdo, $applicationId, 'Needs Revision', (int) $user['id'], 'Auto-scan detected document issues requiring correction.');
                $scanEventId = queue_scan_failure_alert($pdo,$applicationId,$scanFailures);
                audit($pdo, (int) $user['id'], 'submit_application', 'application', $applicationId);
                $pdo->commit();

                deliver_scan_failure_alert($pdo, $scanEventId);
                flash('error', 'Your application was saved, but automated verification found documents that need revision. Review the results below and re-upload those files.');
                redirect('application.php?id=' . $applicationId);
            } else {
                record_status($pdo, $applicationId, 'For Review', (int) $user['id'], 'New application submitted.');
                audit($pdo, (int) $user['id'], 'submit_application', 'application', $applicationId);
                $pdo->commit();
                flash('success', 'Application submitted. Your reference is ' . $reference . '.');
                redirect('application.php?id=' . $applicationId);
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($submissionCommitted && isset($applicationId, $reference)) {
                // The durable record defaults to For Review. A post-upload AI or
                // bookkeeping failure therefore falls back to normal review.
                try {
                    record_status($pdo, $applicationId, 'For Review', (int) $user['id'], 'Application submitted; automated verification was unavailable.');
                    audit($pdo, (int) $user['id'], 'submit_application', 'application', $applicationId);
                } catch (Throwable) {
                }
                flash('success', 'Application submitted. Automated document verification was unavailable, so BPLO will review the files normally. Your reference is ' . $reference . '.');
                redirect('application.php?id=' . $applicationId);
            }
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : ($exception instanceof PDOException ? 'Import database/migrations/005_fee_assessment_formula.sql, then submit the application again.' : 'The application could not be submitted. Please try again.');
        }
    }
}

$definitions = document_definitions();

require dirname(__DIR__, 2) . '/frontend/views/apply.php';
