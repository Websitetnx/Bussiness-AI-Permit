<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) { http_response_code(404); exit; }

/** Durable once-per-installment/deadline reminders; no permit status changes. */
function generate_installment_reminders(PDO $pdo, ?int $userId = null): int
{
    if (!installment_workflow_ready($pdo)) return 0;
    $policy=permit_payment_policy($pdo);$today=date('Y-m-d');
    $through=permit_date($today)->modify('+'.(int)$policy['payment_reminder_days'].' days')->format('Y-m-d');
    $q=$pdo->prepare("SELECT i.id,i.label,i.amount,i.due_date,a.id application_id,a.reference FROM payment_installments i JOIN payments p ON p.id=i.payment_id JOIN applications a ON a.id=p.application_id WHERE i.status<>'Paid' AND a.status IN ('Approved','Released') AND i.due_date<=?".($userId!==null?' AND a.user_id=?':''));
    $q->execute($userId!==null?[$through,$userId]:[$through]);$count=0;
    foreach($q->fetchAll() as $row) {
        $overdue=$row['due_date']<$today;
        notify_application($pdo,(int)$row['application_id'],'installment-reminder:'.$row['id'].':'.$row['due_date'].':'.($overdue?'overdue':'upcoming'),$overdue?'Overdue installment — staff review required':'Upcoming installment payment',$row['reference'].' — '.$row['label'].': PHP '.$row['amount'].' due '.$row['due_date'].'. Submit evidence from the application payment page. Submitted evidence is not paid until verified. Permit status and validity remain unchanged.',$overdue?'finance':'applicant');
        $count++;
    }
    return $count;
}

function generate_permit_expiration_reminders(PDO $pdo, ?int $userId = null): array
{
    $policy=permit_payment_policy($pdo);$today=date('Y-m-d');
    $through=permit_date($today)->modify('+'.(int)$policy['expiration_reminder_days'].' days')->format('Y-m-d');
    // Use fsic_expiry_date (from compliance table or applications) as the expiry proxy
    $q=$pdo->prepare("SELECT a.id,a.permit_number,COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) AS expiry_date,b.business_name FROM applications a JOIN businesses b ON b.id=a.business_id LEFT JOIN fsic_compliance f ON f.application_id=a.id WHERE a.status='Released' AND COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) IS NOT NULL AND COALESCE(f.fsic_expiry_date, a.fsic_expiry_date)<=? AND NOT EXISTS(SELECT 1 FROM applications newer WHERE newer.permit_number=a.permit_number AND newer.user_id=a.user_id AND newer.id>a.id AND newer.application_type='Renewal' AND newer.status IN ('For Review','Needs Revision','Approved','Released'))".($userId!==null?' AND a.user_id=?':''));
    $q->execute($userId!==null?[$through,$userId]:[$through]);$alerts=[];
    foreach($q->fetchAll() as $row) {
        if (!$row['expiry_date']) continue;
        $expired=$row['expiry_date']<$today;
        $days=(int)permit_date($today)->diff(permit_date($row['expiry_date']))->format('%r%a');
        notify_application($pdo,(int)$row['id'],'permit-expiration:'.$row['id'].':'.$row['expiry_date'].':'.($expired?'expired':'upcoming'),$expired?'Permit expiration notice':'Approaching permit expiration','Permit '.$row['permit_number'].' for '.$row['business_name'].($expired?' expired on ':' expires on ').$row['expiry_date'].'. Review your renewal options with BPLO. Payment installment dates do not extend this term.','applicant');
        $alerts[]=['permit_number'=>$row['permit_number'],'business_name'=>$row['business_name'],'application_id'=>(int)$row['id'],'expiry_date'=>permit_date($row['expiry_date'])->format('M j, Y'),'days_until_expiry'=>$days,'is_expired'=>$expired];
    }
    return $alerts;
}

function generate_fsic_expiration_reminders(PDO $pdo, ?int $userId = null): array
{
    $today = date('Y-m-d');
    $through = permit_date($today)->modify('+30 days')->format('Y-m-d');
    $q = $pdo->prepare("
        SELECT a.id, a.permit_number, b.business_name,
               COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) AS fsic_expiry_date
        FROM applications a
        JOIN businesses b ON b.id = a.business_id
        LEFT JOIN fsic_compliance f ON f.application_id = a.id
        WHERE a.status = 'Released'
          AND COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) IS NOT NULL
          AND COALESCE(f.fsic_expiry_date, a.fsic_expiry_date) <= ?
          AND NOT EXISTS (
              SELECT 1 FROM applications newer
              WHERE newer.permit_number = a.permit_number
                AND newer.user_id = a.user_id
                AND newer.id > a.id
                AND newer.application_type = 'Renewal'
                AND newer.status IN ('For Review','Needs Revision','Approved','Released')
          )" . ($userId !== null ? ' AND a.user_id = ?' : ''));
    $q->execute($userId !== null ? [$through, $userId] : [$through]);
    $alerts = [];
    foreach ($q->fetchAll() as $row) {
        $expiry = (string) $row['fsic_expiry_date'];
        $expired = $expiry < $today;
        $dExpiry = new DateTimeImmutable($expiry);
        $dToday = new DateTimeImmutable($today);
        $daysOverdue = $expired ? (int) $dExpiry->diff($dToday)->days : 0;
        $daysUntilExpiry = !$expired ? (int) $dToday->diff($dExpiry)->days : 0;

        $message = $expired
            ? "FSIC for {$row['business_name']} (Permit {$row['permit_number']}) expired on {$expiry} ({$daysOverdue} day(s) ago). Renewing 1–30 days past expiry incurs an automatic 9% surcharge on the total assessment."
            : "FSIC for {$row['business_name']} (Permit {$row['permit_number']}) expires on {$expiry} (in {$daysUntilExpiry} days). Renew on time to avoid the 9% late renewal penalty.";

        notify_application(
            $pdo,
            (int) $row['id'],
            'fsic-expiry:' . $row['id'] . ':' . $expiry . ':' . ($expired ? 'expired' : 'upcoming'),
            $expired ? 'FSIC Expired — Late Surcharge Applies' : 'Approaching FSIC Expiration',
            $message,
            'applicant'
        );

        $alerts[] = [
            'permit_number' => $row['permit_number'],
            'business_name' => $row['business_name'],
            'application_id' => (int) $row['id'],
            'fsic_expiry_date' => $expiry,
            'days_overdue' => $daysOverdue,
            'days_until_expiry' => $daysUntilExpiry,
            'is_expired' => $expired,
        ];
    }
    return $alerts;
}

