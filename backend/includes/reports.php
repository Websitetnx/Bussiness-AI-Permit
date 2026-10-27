<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/permit_payments.php';

function report_range(string $start, string $end): array
{
    $a=DateTimeImmutable::createFromFormat('!Y-m-d',$start);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$end);
    if (!$a || !$b || $a->format('Y-m-d')!==$start || $b->format('Y-m-d')!==$end || $a>$b || $a->diff($b)->days>366) throw new InvalidArgumentException('Select a valid date range of up to 367 days.');
    return [$a->format('Y-m-d H:i:s'),$b->modify('+1 day')->format('Y-m-d H:i:s')];
}

function report_rows(PDO $pdo, string $type, array $range): array
{
    if (installment_workflow_ready($pdo)) {
        if ($type==='collections') {
            // Never count a parent assessment in addition to its installments.
            $q=$pdo->prepare("SELECT DATE(paid_at) payment_day,payment_method,COUNT(*) verified_payments,SUM(amount) total_php FROM (SELECT i.amount,i.paid_at,i.payment_method FROM payment_installments i WHERE i.status='Paid' UNION ALL SELECT p.amount,p.paid_at,p.payment_method FROM payments p WHERE p.status='Paid' AND NOT EXISTS(SELECT 1 FROM payment_installments i WHERE i.payment_id=p.id)) collected WHERE paid_at>=? AND paid_at<? GROUP BY DATE(paid_at),payment_method ORDER BY payment_day,payment_method");
            $q->execute($range);return $q->fetchAll();
        }
        if ($type==='outstanding') {
            $q=$pdo->prepare("SELECT a.reference,a.permit_number,a.status permit_status,p.payment_frequency,p.amount annual_assessment,COALESCE(x.paid,CASE WHEN p.status='Paid' THEN p.amount ELSE 0 END) verified_payments,GREATEST(0,p.amount-COALESCE(x.paid,CASE WHEN p.status='Paid' THEN p.amount ELSE 0 END)) outstanding_balance,COALESCE(x.overdue,0) overdue_amount FROM payments p JOIN applications a ON a.id=p.application_id LEFT JOIN (SELECT payment_id,SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END) paid,SUM(CASE WHEN status<>'Paid' AND due_date<? THEN amount ELSE 0 END) overdue FROM payment_installments GROUP BY payment_id) x ON x.payment_id=p.id WHERE p.created_at>=? AND p.created_at<? HAVING outstanding_balance>0 ORDER BY a.reference");
            $q->execute(array_merge([date('Y-m-d')],$range));return $q->fetchAll();
        }
        if ($type==='overdue') {
            $q=$pdo->prepare("SELECT a.reference,a.permit_number,a.status permit_status,i.label installment,i.amount outstanding_amount,i.due_date,i.status payment_status,i.submitted_at evidence_submitted,DATEDIFF(?,i.due_date) days_overdue FROM payment_installments i JOIN payments p ON p.id=i.payment_id JOIN applications a ON a.id=p.application_id WHERE i.status<>'Paid' AND i.due_date<? AND i.due_date>=DATE(?) AND i.due_date<DATE(?) ORDER BY i.due_date,a.reference,i.sequence_no");
            $q->execute(array_merge([date('Y-m-d'),date('Y-m-d')],$range));return $q->fetchAll();
        }
    }
    $queries=[
        'applications'=>"SELECT DATE(submitted_at) day,application_type,status,COUNT(*) application_count FROM applications WHERE submitted_at>=? AND submitted_at<? GROUP BY DATE(submitted_at),application_type,status ORDER BY day,application_type,status",
        'processing'=>"SELECT DATE(first_approval) approval_day,application_type,COUNT(*) approvals,ROUND(AVG(TIMESTAMPDIFF(SECOND,submitted_at,first_approval))/86400,2) average_calendar_days,ROUND(MAX(TIMESTAMPDIFF(SECOND,submitted_at,first_approval))/86400,2) longest_calendar_days FROM (SELECT a.id,a.application_type,a.submitted_at,MIN(h.created_at) first_approval FROM applications a JOIN application_status_history h ON h.application_id=a.id AND h.status IN ('Approved','Released') GROUP BY a.id,a.application_type,a.submitted_at) approvals WHERE first_approval>=? AND first_approval<? AND first_approval>=submitted_at GROUP BY DATE(first_approval),application_type ORDER BY approval_day,application_type",
        'collections'=>"SELECT DATE(paid_at) payment_day,payment_method,COUNT(*) verified_payments,SUM(amount) total_php FROM payments WHERE status='Paid' AND paid_at>=? AND paid_at<? GROUP BY DATE(paid_at),payment_method ORDER BY payment_day,payment_method",
        'revisions'=>"SELECT DATE(created_at) revision_day,COUNT(*) revision_events,COUNT(DISTINCT application_id) affected_applications FROM application_status_history WHERE status='Needs Revision' AND created_at>=? AND created_at<? GROUP BY DATE(created_at) ORDER BY revision_day",
        'renewals'=>"SELECT DATE(submitted_at) day,status,COUNT(*) renewals FROM applications WHERE application_type='Renewal' AND submitted_at>=? AND submitted_at<? GROUP BY DATE(submitted_at),status ORDER BY day,status",
    ];
    if (!isset($queries[$type])) throw new InvalidArgumentException('Unknown report.');
    $q=$pdo->prepare($queries[$type]);$q->execute($range);return $q->fetchAll();
}

function csv_safe_cell(mixed $value): string
{
    $text=(string)($value??'');
    return preg_match('/^[\s]*[=+@\-]/u',$text) || preg_match('/^[\t\r\n]/',$text)?"'".$text:$text;
}
