<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_role(['applicant', 'admin']);
$canManageApplications = can_manage_applications($user['role'] ?? '');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(404);
    exit('Application not found.');
}

$sql = 'SELECT a.*, b.business_name, b.business_type, b.organization_type, b.tin, b.contact, b.email, b.address, b.latitude, b.longitude, b.location_accuracy_m, b.location_captured_at, u.name owner_name
        FROM applications a JOIN businesses b ON b.id = a.business_id JOIN users u ON u.id = a.user_id WHERE a.id = ?';
$params = [$id];
if (!$canManageApplications) {
    $sql .= ' AND a.user_id = ?';
    $params[] = $user['id'];
}
$statement = db()->prepare($sql);
$statement->execute($params);
$application = $statement->fetch();
if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

$aiScanReady = true;
try {
    $documents = db()->prepare('SELECT d.id, d.document_type, d.original_name, d.mime_type, d.file_size, d.uploaded_at, s.scan_status, s.detected_document_type, s.matches_expected_type, s.quality_score, s.confidence_score, s.issues, s.summary FROM application_documents d LEFT JOIN document_ai_scans s ON s.document_id = d.id WHERE d.application_id = ? ORDER BY d.uploaded_at');
    $documents->execute([$id]);
} catch (PDOException) {
    $aiScanReady = false;
    $documents = db()->prepare('SELECT id, document_type, original_name, mime_type, file_size, uploaded_at FROM application_documents WHERE application_id = ? ORDER BY uploaded_at');
    $documents->execute([$id]);
}
$history = db()->prepare('SELECT h.status, h.notes, h.created_at, u.name changed_by_name FROM application_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.application_id = ? ORDER BY h.created_at DESC, h.id DESC');
$history->execute([$id]);
$definitions = document_definitions();
$paymentWorkflowReady = true;
try {
    $paymentStatement = db()->prepare('SELECT * FROM payments WHERE application_id = ? LIMIT 1');
    $paymentStatement->execute([$id]);
    $payment = $paymentStatement->fetch() ?: null;
} catch (PDOException) {
    $paymentWorkflowReady = false;
    $payment = null;
}


$prediction = estimate_application_timeline(db(), $application);

$allDocuments = $documents->fetchAll();
$hasFailedScans = false;
$firstFailedDocumentId = null;
foreach ($allDocuments as $doc) {
    if (isset($doc['scan_status']) && $doc['scan_status'] === 'Completed') {
        $docFailed = document_scan_failure_reasons($doc, $definitions[$doc['document_type']][0] ?? $doc['document_type']);
        if ($docFailed) {
            $hasFailedScans = true;
            $firstFailedDocumentId ??= (int) $doc['id'];
        }
    }
}
$isApplicant = !$canManageApplications;
$showReupload = $isApplicant && in_array($application['status'], ['Submitted', 'For Review', 'Needs Revision'], true);

$historyRows = $history->fetchAll();

$installments=$payment && installment_workflow_ready(db())?payment_installments(db(),(int)$payment['id']):[];
$paymentBalance=$installments?installment_balance($payment,$installments):null;
try { $fsicCompliance=get_fsic_compliance_for_application(db(),(int)$id); }
catch (PDOException) { $fsicCompliance=null; }


require dirname(__DIR__, 2) . '/frontend/views/application.php';

