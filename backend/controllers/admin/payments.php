<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
$user = require_role(['admin', 'treasurer']);
$paymentWorkflowReady = true;
try {
    $records = db()->query(
        "SELECT p.*, a.reference, a.id application_id, a.status application_status,
                b.business_name, u.name applicant_name
         FROM payments p
         JOIN applications a ON a.id = p.application_id
         JOIN businesses b ON b.id = a.business_id
         JOIN users u ON u.id = a.user_id
         ORDER BY
             FIELD(p.status, 'Pending', 'Failed', 'Paid', 'Refunded'),
             p.submitted_at DESC,
             p.id DESC"
    )->fetchAll();
} catch (PDOException) {
    $paymentWorkflowReady = false;
    $records = [];
}

$pendingCount = 0;
$withInstallments=installment_workflow_ready(db());
foreach($records as &$record) {
    $record['balance']=null;$record['pending_installments']=0;
    if ($withInstallments) {
        $rows=payment_installments(db(),(int)$record['id']);
        if ($rows) {
            $record['balance']=installment_balance($record,$rows);
            $record['status']=$record['balance']['status'];
            foreach($rows as $row) if ($row['submitted_at'] && $row['status']==='Pending') $record['pending_installments']++;
            $pendingCount+=$record['pending_installments'];
        }
    }
}
unset($record);
foreach ($records as $r) {
    if (!$r['balance'] && $r['submitted_at'] && $r['status'] === 'Pending') $pendingCount++;
}

require dirname(__DIR__, 3) . '/frontend/views/admin/payments.php';
