<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$installmentId = filter_input(INPUT_GET, 'installment_id', FILTER_VALIDATE_INT);
if (!$id && !$installmentId) { http_response_code(404); exit('Receipt not found.'); }
if ($installmentId && installment_workflow_ready(db())) {
    $statement=db()->prepare("SELECT i.*,p.amount annual_assessment,a.user_id,a.reference application_reference,a.permit_number,b.business_name,b.address,u.name applicant_name,v.name verified_by_name FROM payment_installments i JOIN payments p ON p.id=i.payment_id JOIN applications a ON a.id=p.application_id JOIN businesses b ON b.id=a.business_id JOIN users u ON u.id=a.user_id LEFT JOIN users v ON v.id=i.verified_by WHERE i.id=? AND i.status='Paid' AND i.receipt_number IS NOT NULL");
    $statement->execute([$installmentId]);
} else {
    $statement = db()->prepare("SELECT p.*, a.user_id, a.reference application_reference, a.permit_number, b.business_name, b.address, u.name applicant_name, v.name verified_by_name FROM payments p JOIN applications a ON a.id = p.application_id JOIN businesses b ON b.id = a.business_id JOIN users u ON u.id = a.user_id LEFT JOIN users v ON v.id = p.verified_by WHERE p.id = ? AND p.status = 'Paid' AND p.receipt_number IS NOT NULL");
    $statement->execute([$id]);
}
$payment = $statement->fetch();
if (!$payment) { http_response_code(404); exit('Verified receipt not found.'); }
if (!can_manage_payments($user['role'] ?? '') && (int) $payment['user_id'] !== (int) $user['id']) { http_response_code(403); exit('Access denied.'); }
$feeBreakdown = decoded_fee_breakdown($payment['assessment_breakdown'] ?? null);
$canManagePayments = can_manage_payments($user['role'] ?? '');

require dirname(__DIR__, 2) . '/frontend/views/receipt.php';
