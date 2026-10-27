<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) { http_response_code(404); exit; }

/** Save only a newly received proof; previous evidence is never deleted. */
function store_installment_proof(?array $file): array
{
    if (!$file || (int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return [];
    if ((int)$file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('The payment evidence could not be uploaded.');
    if (filesize($file['tmp_name'])>(int)app_config('max_upload_bytes')) throw new InvalidArgumentException('Payment evidence must be 5 MB or smaller.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types=['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'];
    if (!isset($types[$mime])) throw new InvalidArgumentException('Upload a PDF, JPG, or PNG payment confirmation.');
    $name=bin2hex(random_bytes(24)).'.'.$types[$mime];
    $directory=(string)app_config('upload_dir');
    if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) throw new RuntimeException('Secure upload storage is unavailable.');
    if (!move_uploaded_file($file['tmp_name'],$directory.'/'.$name)) throw new RuntimeException('Could not save payment evidence.');
    return ['stored_name'=>$name,'original_name'=>basename($file['name']),'mime_type'=>$mime];
}

/** Consistent lock order: application, annual assessment, installment. */
function lock_installment_context(PDO $pdo, int $installmentId): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Payment changes require a transaction.');
    $q=$pdo->prepare('SELECT i.payment_id,p.application_id FROM payment_installments i JOIN payments p ON p.id=i.payment_id WHERE i.id=?');$q->execute([$installmentId]);$ids=$q->fetch();
    if (!$ids) throw new RuntimeException('Installment not found.');
    $q=$pdo->prepare('SELECT * FROM applications WHERE id=? FOR UPDATE');$q->execute([$ids['application_id']]);$app=$q->fetch();
    $q=$pdo->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([$ids['payment_id']]);$parent=$q->fetch();
    $q=$pdo->prepare('SELECT * FROM payment_installments WHERE id=? FOR UPDATE');$q->execute([$installmentId]);$row=$q->fetch();
    return [$app,$parent,$row];
}

function submit_installment_evidence(PDO $pdo, int $userId, int $installmentId, array $input, array $proof, string $token): bool
{
    [$app,$parent,$row]=lock_installment_context($pdo,$installmentId);
    if ((int)$app['user_id']!==$userId || !in_array($app['status'],['Approved','Released'],true)) throw new RuntimeException('This installment is not available for your account.');
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('Reload the payment form before submitting.');
    $method=trim((string)($input['payment_method']??''));$reference=trim((string)($input['payment_reference']??''));$payer=trim((string)($input['payer_name']??''));
    if (!in_array($method,['GCash','Maya','Bank Transfer','City Treasurer Counter'],true) || strlen($reference)<3 || strlen($reference)>100 || strlen($payer)<2 || strlen($payer)>120) throw new InvalidArgumentException('Enter a valid payment method, payer, and transaction reference.');
    $q=$pdo->prepare('SELECT installment_id,payment_method,payment_reference FROM payment_evidence WHERE submission_token=?');$q->execute([hash('sha256',$token)]);$duplicate=$q->fetch();
    if ($duplicate) {
        if ((int)$duplicate['installment_id']!==$installmentId || $duplicate['payment_method']!==$method || $duplicate['payment_reference']!==$reference) throw new RuntimeException('Submission token was already used for another payment.');
        return false;
    }
    if ($row['status']==='Paid' || $row['status']==='Refunded' || ($row['status']==='Pending' && $row['submitted_at'])) throw new RuntimeException('This installment is already paid or awaiting verification.');
    if ($method!=='City Treasurer Counter' && empty($proof['stored_name'])) throw new InvalidArgumentException('Upload evidence for this installment.');
    $referenceHash=hash('sha256',strtolower($method).':'.strtolower($reference));
    $q=$pdo->prepare('INSERT INTO payment_reference_claims(reference_hash,installment_id) VALUES(?,?) ON DUPLICATE KEY UPDATE reference_hash=VALUES(reference_hash)');$q->execute([$referenceHash,$installmentId]);
    $q=$pdo->prepare('SELECT installment_id FROM payment_reference_claims WHERE reference_hash=?');$q->execute([$referenceHash]);
    if ((int)$q->fetchColumn()!==$installmentId) throw new RuntimeException('This transaction reference is already assigned to another installment.');
    $version=(int)$row['submission_version']+1;
    $q=$pdo->prepare("INSERT INTO payment_evidence(installment_id,attempt_number,submission_token,payment_method,payer_name,payment_reference,proof_original_name,proof_stored_name,proof_mime_type,status,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,'Pending',NOW())");
    $q->execute([$installmentId,$version,hash('sha256',$token),$method,$payer,$reference,$proof['original_name']??null,$proof['stored_name']??null,$proof['mime_type']??null]);
    $q=$pdo->prepare("UPDATE payment_installments SET payment_method=?,payer_name=?,payment_reference=?,proof_original_name=?,proof_stored_name=?,proof_mime_type=?,status='Pending',submitted_at=NOW(),submission_version=?,verified_by=NULL,verified_at=NULL,admin_notes=NULL WHERE id=?");
    $q->execute([$method,$payer,$reference,$proof['original_name']??null,$proof['stored_name']??null,$proof['mime_type']??null,$version,$installmentId]);
    notify_application($pdo,(int)$app['id'],'installment-submitted:'.$installmentId.':'.$version,'Installment evidence submitted',$app['reference'].' — '.$row['label'].' (PHP '.$row['amount'].') is awaiting payment verification.','finance');
    audit($pdo,$userId,'submit_installment','payment_installment',$installmentId);
    return true;
}

function verify_installment_payment(PDO $pdo, int $staffId, int $installmentId, int $version, string $action, string $notes): string
{
    $q=$pdo->prepare('SELECT role,is_active FROM users WHERE id=?');$q->execute([$staffId]);$staff=$q->fetch();
    if (!$staff || !$staff['is_active'] || !can_manage_payments($staff['role'])) throw new RuntimeException('Payment verification requires authorized BPLO or Treasurer access.');
    [$app,$parent,$row]=lock_installment_context($pdo,$installmentId);
    if (strlen($notes)>4000) throw new InvalidArgumentException('Payment review notes must not exceed 4,000 bytes.');
    if (!in_array($action,['verify','reject'],true) || ($action==='reject' && strlen(trim($notes))<5)) throw new InvalidArgumentException('Select a payment decision and provide a correction reason when rejecting.');
    if (!in_array($app['status'],['Approved','Released'],true)) throw new RuntimeException('This application is not eligible for payment verification.');
    if ($row['status']!=='Pending' || !$row['submitted_at'] || (int)$row['submission_version']!==$version) throw new RuntimeException('Payment was already processed or the evidence changed. Reload before deciding.');
    $status=$action==='verify'?'Paid':'Failed';
    $receipt=$action==='verify'?create_receipt_number($pdo):null;
    $q=$pdo->prepare("UPDATE payment_installments SET status=?,receipt_number=?,paid_at=CASE WHEN ?='Paid' THEN NOW() ELSE NULL END,verified_by=?,verified_at=NOW(),admin_notes=? WHERE id=? AND submission_version=? AND status='Pending'");
    $q->execute([$status,$receipt,$status,$staffId,$notes?:null,$installmentId,$version]);
    if ($q->rowCount()!==1) throw new RuntimeException('Payment was already processed.');
    $q=$pdo->prepare('UPDATE payment_evidence SET status=?,verified_by=?,verified_at=NOW(),admin_notes=? WHERE installment_id=? AND attempt_number=?');$q->execute([$status,$staffId,$notes?:null,$installmentId,$version]);
    $balance=synchronize_payment_balance($pdo,(int)$parent['id']);
    $message=$app['reference'].' — '.$row['label'].($receipt?' verified. Receipt '.$receipt.'.':' requires correction: '.$notes.'.').' Annual balance remaining: PHP '.$balance['remaining'].'. Permit validity dates are unchanged.';
    notify_application($pdo,(int)$app['id'],'installment-decision:'.$installmentId.':'.$version,'Installment '.($receipt?'verified':'requires correction'),$message,'finance');
    audit($pdo,$staffId,$action.'_installment','payment_installment',$installmentId);
    return $receipt ?? 'Correction requested';
}
