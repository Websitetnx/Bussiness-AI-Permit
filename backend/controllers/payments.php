<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_role('applicant');
$paymentWorkflowReady = true;
try {
    $statement = db()->prepare(
        "SELECT a.id application_id, a.reference, a.status application_status,
                a.submitted_at, b.business_name, a.permit_number,
                p.id payment_id, p.amount, p.status payment_status,
                p.submitted_at payment_submitted_at, p.paid_at, p.receipt_number
         FROM applications a
         JOIN businesses b ON b.id = a.business_id
         LEFT JOIN payments p ON p.application_id = a.id
         WHERE a.user_id = ?
         ORDER BY a.submitted_at DESC, a.id DESC"
    );
    $statement->execute([$user['id']]);
    $records = $statement->fetchAll();
} catch (PDOException) {
    $paymentWorkflowReady = false;
    $records = [];
}

// Separate approved/payment-eligible from others
$withInstallments=installment_workflow_ready(db());
foreach($records as &$record) {
    $record['payment_balance']=null;
    if ($withInstallments && $record['payment_id']) {
        $rows=payment_installments(db(),(int)$record['payment_id']);
        if ($rows) {
            $record['payment_balance']=installment_balance(['amount'=>$record['amount']],$rows);
            $record['payment_status']=$record['payment_balance']['status'];
        }
    }
}
unset($record);
$paymentRecords = [];
$otherRecords   = [];
foreach ($records as $r) {
    if (in_array($r['application_status'], ['Approved', 'Released'], true)) {
        $paymentRecords[] = $r;
    } else {
        $otherRecords[] = $r;
    }
}

require dirname(__DIR__, 2) . '/frontend/views/payments.php';
