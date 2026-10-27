<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();
$documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
if (!$documentId) {
    flash('error', 'The selected document was not found.');
    redirect('admin/index.php#queue');
}

$pdo = db();
$statement = $pdo->prepare('SELECT d.*, a.id application_id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ?');
$statement->execute([$documentId]);
$document = $statement->fetch();
if (!$document) {
    flash('error', 'The selected document was not found.');
    redirect('admin/index.php#queue');
}

$definitions = document_definitions();
$expectedType = $definitions[$document['document_type']][0] ?? $document['document_type'];
$path = app_config('upload_dir') . '/' . basename($document['stored_name']);
$model = openai_settings()['model'];
$attemptedApi = false;
$scanFailureEventId = null;

try {
    if ($document['document_type'] === 'health_results_doc' && !openai_settings()['allow_sensitive_documents']) {
        throw new RuntimeException('AI scanning of medical results is disabled by default. A privacy-authorized administrator must explicitly set ALLOW_SENSITIVE_AI_SCAN=true.');
    }
    $scanCapacity = automatic_scan_capacity($pdo);
    if ($scanCapacity['remaining'] === 0) {
        throw new RuntimeException('The daily AI document-scan limit has been reached.');
    }
    // Compare timestamps in the database so PHP/server timezone differences do
    // not bypass the cooldown or prevent a rescan for several hours.
    $lastScan = $pdo->prepare('SELECT scanned_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) FROM document_ai_scans WHERE document_id = ?');
    $lastScan->execute([$documentId]);
    if ((int) $lastScan->fetchColumn() === 1) {
        throw new RuntimeException('Please wait 30 seconds before scanning the same document again.');
    }
    snapshot_document($pdo, (int)$documentId);
    $attemptedApi = true;
    $result = scan_permit_document($path, $document['original_name'], $document['mime_type'], $expectedType, (int) $user['id']);
    $pdo->beginTransaction();
    $guard=$pdo->prepare('SELECT stored_name FROM application_documents WHERE id=? FOR UPDATE');
    $guard->execute([$documentId]);
    if ($guard->fetchColumn() !== $document['stored_name']) {
        $pdo->rollBack();
        $attemptedApi=false;
        throw new RuntimeException('The applicant replaced this file during the scan. Please scan the current version.');
    }
    snapshot_document($pdo, (int)$documentId);
    $save = $pdo->prepare("INSERT INTO document_ai_scans
        (document_id, application_id, scan_status, detected_document_type, matches_expected_type, quality_score, confidence_score, extracted_fields, issues, summary, requires_human_review, model, error_message, scanned_by)
        VALUES (?, ?, 'Completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
        ON DUPLICATE KEY UPDATE scan_status = 'Completed', detected_document_type = VALUES(detected_document_type), matches_expected_type = VALUES(matches_expected_type), quality_score = VALUES(quality_score), confidence_score = VALUES(confidence_score), extracted_fields = VALUES(extracted_fields), issues = VALUES(issues), summary = VALUES(summary), requires_human_review = VALUES(requires_human_review), model = VALUES(model), error_message = NULL, scanned_by = VALUES(scanned_by), scanned_at = CURRENT_TIMESTAMP");
    $save->execute([
        $documentId,
        $document['application_id'],
        $result['detected_document_type'],
        $result['matches_expected_type'] ? 1 : 0,
        max(0, min(100, (int) $result['quality_score'])),
        max(0, min(100, (int) $result['confidence_score'])),
        json_encode($result['extracted_fields'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($result['issues'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $result['summary'],
        $result['requires_human_review'] ? 1 : 0,
        $model,
        $user['id'],
    ]);
    snapshot_document($pdo, (int)$documentId);
    $scanFailureEventId = queue_scan_failure_alert($pdo,(int)$document['application_id'],application_scan_failures($pdo,(int)$document['application_id']));
    audit($pdo, (int) $user['id'], 'ai_scan_document', 'application_document', (int) $documentId);
    $pdo->commit();

    // Notify after commit; unsuccessful email deliveries remain queued for retry.
    deliver_scan_failure_alert($pdo, $scanFailureEventId);
    flash('success', 'AI document scan completed. Review the advisory findings against the original file.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($attemptedApi) {
        try {
            $pdo->beginTransaction();
            $guard=$pdo->prepare('SELECT stored_name FROM application_documents WHERE id=? FOR UPDATE');
            $guard->execute([$documentId]);
            if ($guard->fetchColumn() !== $document['stored_name']) throw new RuntimeException('File replaced.');
            $save = $pdo->prepare("INSERT INTO document_ai_scans (document_id, application_id, scan_status, model, error_message, scanned_by) VALUES (?, ?, 'Failed', ?, ?, ?) ON DUPLICATE KEY UPDATE scan_status = 'Failed', model = VALUES(model), error_message = VALUES(error_message), scanned_by = VALUES(scanned_by), scanned_at = CURRENT_TIMESTAMP");
            $save->execute([$documentId, $document['application_id'], $model, substr($exception->getMessage(), 0, 1000), $user['id']]);
            snapshot_document($pdo, (int)$documentId);
            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // The migration may not have been imported yet; the user-facing error below still explains the failure.
        }
    }
    flash('error', $exception->getMessage());
}

redirect('admin/review.php?id=' . $document['application_id']);
