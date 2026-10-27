<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$installmentId=filter_input(INPUT_GET,'installment_id',FILTER_VALIDATE_INT);
$evidenceId=filter_input(INPUT_GET,'evidence_id',FILTER_VALIDATE_INT);
if (!$id && !$installmentId && !$evidenceId) { http_response_code(404); exit('Payment confirmation not found.'); }
if (($installmentId || $evidenceId) && installment_workflow_ready(db())) {
    if ($evidenceId) {
        $statement=db()->prepare('SELECT e.proof_original_name,e.proof_stored_name,e.proof_mime_type,a.user_id FROM payment_evidence e JOIN payment_installments i ON i.id=e.installment_id JOIN payments p ON p.id=i.payment_id JOIN applications a ON a.id=p.application_id WHERE e.id=?');
        $statement->execute([$evidenceId]);
    } else {
        $statement=db()->prepare('SELECT i.proof_original_name,i.proof_stored_name,i.proof_mime_type,a.user_id FROM payment_installments i JOIN payments p ON p.id=i.payment_id JOIN applications a ON a.id=p.application_id WHERE i.id=?');
        $statement->execute([$installmentId]);
    }
} else {
    $statement = db()->prepare('SELECT p.proof_original_name, p.proof_stored_name, p.proof_mime_type, a.user_id FROM payments p JOIN applications a ON a.id = p.application_id WHERE p.id = ?');
    $statement->execute([$id]);
}
$payment = $statement->fetch();
if (!$payment || !$payment['proof_stored_name']) { http_response_code(404); exit('Payment confirmation not found.'); }
if (!can_manage_payments($user['role'] ?? '') && (int) $payment['user_id'] !== (int) $user['id']) { http_response_code(403); exit('Access denied.'); }
$path = (string) app_config('upload_dir') . '/' . basename((string) $payment['proof_stored_name']);
if (!is_file($path)) { http_response_code(404); exit('Payment confirmation not found.'); }
header('Content-Type: ' . ($payment['proof_mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', basename((string) $payment['proof_original_name'])) . '"');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
