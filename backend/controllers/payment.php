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
$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'application_id', FILTER_VALIDATE_INT);
if (!$applicationId) { http_response_code(404); exit('Payment record not found.'); }

try {
    $statement = $pdo->prepare('SELECT a.id application_id, a.reference application_reference, a.status application_status, a.user_id, b.business_name, p.* FROM applications a JOIN businesses b ON b.id = a.business_id LEFT JOIN payments p ON p.application_id = a.id WHERE a.id = ? AND a.user_id = ?');
    $statement->execute([$applicationId, $user['id']]);
} catch (PDOException) {
    flash('error', 'Import database/migrations/004_payment_workflow.sql before using permit payments.');
    redirect('application.php?id=' . $applicationId);
}
$payment = $statement->fetch();
if (!$payment) { http_response_code(404); exit('Payment record not found.'); }
if (!in_array($payment['application_status'], ['Approved', 'Released'], true)) { http_response_code(403); exit('Payment is available only after BPLO approval.'); }

$errors = [];
$installments = installment_workflow_ready($pdo) && $payment['id'] ? payment_installments($pdo,(int)$payment['id']) : [];
$proof = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && $installments) {
    verify_csrf();
    try {
        $installmentId=filter_input(INPUT_POST,'installment_id',FILTER_VALIDATE_INT);
        if (!$installmentId || !in_array((int)$installmentId,array_map('intval',array_column($installments,'id')),true)) throw new RuntimeException('Choose an installment from this assessment.');
        $proof=store_installment_proof($_FILES['payment_proof']??null);
        $pdo->beginTransaction();
        $created=submit_installment_evidence($pdo,(int)$user['id'],(int)$installmentId,$_POST,$proof,(string)($_POST['submission_token']??''));
        $pdo->commit();
        if (!$created && !empty($proof['stored_name'])) unlink(app_config('upload_dir').'/'.$proof['stored_name']);
        flash('success',$created?'Evidence submitted for this installment. The annual balance changes only after verification.':'This submission was already received; no duplicate payment was created.');
        redirect('payment.php?application_id='.$applicationId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($proof['stored_name'])) { $newProofPath=app_config('upload_dir').'/'.$proof['stored_name']; if(is_file($newProofPath))unlink($newProofPath); }
        $errors[]=$e instanceof PDOException?'The evidence could not be recorded. Please retry.':$e->getMessage();
    }
}
$methods = ['GCash', 'Maya', 'Bank Transfer', 'City Treasurer Counter'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installments) {
    verify_csrf();
    if (!$payment['id']) $errors[] = 'The BPLO has not assessed the permit fee yet.';
    if (($payment['status'] ?? '') === 'Paid') $errors[] = 'This payment has already been verified.';
    if ($payment['application_status'] === 'Released' && ($payment['status'] ?? '') === 'Paid') $errors[] = 'The permit has been released and the payment is already verified.';
    $method = trim((string) ($_POST['payment_method'] ?? ''));
    $payerName = trim((string) ($_POST['payer_name'] ?? ''));
    $reference = trim((string) ($_POST['payment_reference'] ?? ''));
    if (!in_array($method, $methods, true)) $errors[] = 'Select a valid payment method.';
    if (strlen($payerName) < 2 || strlen($payerName) > 120) $errors[] = 'Enter the payer name.';
    if (strlen($reference) < 3 || strlen($reference) > 100) $errors[] = 'Enter the transaction or treasury reference number.';

    $file = $_FILES['payment_proof'] ?? null;
    $fileError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    $requiresProof = $method !== 'City Treasurer Counter';
    $mime = null;
    $extension = null;
    if ($fileError === UPLOAD_ERR_NO_FILE && $requiresProof) {
        $errors[] = 'Upload a payment confirmation for online or bank payment.';
    } elseif ($fileError !== UPLOAD_ERR_NO_FILE) {
        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = 'The payment confirmation could not be uploaded.';
        } elseif ((int) $file['size'] > (int) app_config('max_upload_bytes')) {
            $errors[] = 'The payment confirmation must be 5 MB or smaller.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $allowedTypes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowedTypes[$mime])) $errors[] = 'The payment confirmation must be a PDF, JPG, or PNG file.';
            else $extension = $allowedTypes[$mime];
        }
    }

    if (!$errors) {
        $newStoredName = null;
        $oldStoredName = $payment['proof_stored_name'] ?? null;
        try {
            if ($fileError === UPLOAD_ERR_OK && $extension) {
                $uploadDirectory = (string) app_config('upload_dir');
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) throw new RuntimeException('The secure upload directory could not be created.');
                $newStoredName = bin2hex(random_bytes(24)) . '.' . $extension;
                if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $newStoredName)) throw new RuntimeException('The payment confirmation could not be saved.');
            }
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE payments SET payment_method = ?, payer_name = ?, payment_reference = ?, status = 'Pending', proof_original_name = COALESCE(?, proof_original_name), proof_stored_name = COALESCE(?, proof_stored_name), proof_mime_type = COALESCE(?, proof_mime_type), submitted_at = NOW(), verified_by = NULL, verified_at = NULL, admin_notes = NULL WHERE id = ? AND application_id = ?");
            $update->execute([$method, $payerName, $reference, $newStoredName ? basename((string) $file['name']) : null, $newStoredName, $mime, $payment['id'], $applicationId]);
            $message = 'Payment submitted for application ' . $payment['application_reference'] . ' and is awaiting verification.';
            $notice = $pdo->prepare("INSERT INTO notifications (user_id, application_id, message) SELECT id, ?, ? FROM users WHERE role = 'treasurer' AND is_active = 1");
            $notice->execute([$applicationId, $message]);
            notify_application($pdo,(int)$applicationId,'payment-submitted:'.$payment['id'].':'.hash('sha256',$reference.':'.($newStoredName??'').':'.date('Y-m-d H:i:s')),'Payment submitted',$message);
            audit($pdo, (int) $user['id'], 'submit_payment', 'payment', (int) $payment['id']);
            $pdo->commit();
            if ($newStoredName && $oldStoredName && $oldStoredName !== $newStoredName) {
                $oldPath = (string) app_config('upload_dir') . '/' . basename((string) $oldStoredName);
                if (is_file($oldPath)) unlink($oldPath);
            }
            flash('success', 'Payment information submitted. The City Treasurer or administrator must verify it before permit release.');
            redirect('payment.php?application_id=' . $applicationId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newStoredName) {
                $newPath = (string) app_config('upload_dir') . '/' . $newStoredName;
                if (is_file($newPath)) unlink($newPath);
            }
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The payment could not be submitted.';
        }
    }
}

$statement->execute([$applicationId, $user['id']]);
$payment = $statement->fetch();
$feeBreakdown = decoded_fee_breakdown($payment['assessment_breakdown'] ?? null);

if ($installments) {
    $installments=payment_installments($pdo,(int)$payment['id']);
    $paymentBalance=installment_balance($payment,$installments);
    $submissionTokens=[];
    foreach($installments as $installment) $submissionTokens[$installment['id']]=bin2hex(random_bytes(32));
    require dirname(__DIR__,2).'/frontend/partials/installment-payment-page.php';
    return;
}

require dirname(__DIR__, 2) . '/frontend/views/payment.php';
