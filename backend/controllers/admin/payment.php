<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

$user = require_role(['admin', 'treasurer']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(404);
    exit('Payment record not found.');
}

$statement = db()->prepare(
    'SELECT p.*, a.id application_id, a.reference application_reference, a.permit_number,
            b.business_name, u.name applicant_name, v.name verified_by_name
     FROM payments p
     JOIN applications a ON a.id = p.application_id
     JOIN businesses b ON b.id = a.business_id
     JOIN users u ON u.id = a.user_id
     LEFT JOIN users v ON v.id = p.verified_by
     WHERE p.id = ?'
);
$statement->execute([$id]);
$payment = $statement->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Payment record not found.');
}

$feeBreakdown = decoded_fee_breakdown($payment['assessment_breakdown'] ?? null);

$installments=installment_workflow_ready(db())?payment_installments(db(),(int)$payment['id']):[];
if ($installments) {
    $paymentBalance=installment_balance($payment,$installments);
    $evidenceHistory=[];
    $q=db()->prepare('SELECT * FROM payment_evidence WHERE installment_id=? ORDER BY attempt_number DESC');
    foreach($installments as $installment) { $q->execute([$installment['id']]);$evidenceHistory[$installment['id']]=$q->fetchAll(); }
    require dirname(__DIR__,3).'/frontend/partials/admin-installments-page.php';
    return;
}

require dirname(__DIR__, 3) . '/frontend/views/admin/payment.php';
