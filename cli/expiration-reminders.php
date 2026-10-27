<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/backend/includes/bootstrap.php';
$userId=filter_var($argv[1]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$userId) { fwrite(STDERR,"Usage: php cli/expiration-reminders.php USER_ID\n"); exit(1); }
try {
    $pdo=db();
    $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='applicant' AND is_active=1");
    $q->execute([$userId]);
    if (!$q->fetchColumn()) throw new RuntimeException('Active applicant required.');
    check_and_generate_renewal_notices($pdo,$userId);
    // Deliver only this applicant's expiration reminders, including due retries.
    $q=$pdo->prepare("SELECT DISTINCT e.id FROM notification_events e JOIN email_outbox o ON o.event_id=e.id JOIN applications a ON a.id=e.application_id WHERE a.user_id=? AND o.user_id=? AND e.audience='applicant' AND e.title IN ('Approaching permit expiration','Permit expiration notice') AND o.status='pending' AND o.available_at<=NOW() AND o.attempts<5 ORDER BY e.id LIMIT 50");
    $q->execute([$userId,$userId]);
    $counts=['sent'=>0,'failed'=>0];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eventId) {
        $result=deliver_outbox($pdo,1,null,(int)$eventId);
        $counts['sent']+=$result['sent'];$counts['failed']+=$result['failed'];
        if ($result['busy']) break;
    }
    echo json_encode($counts,JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,"Expiration worker failed; check database and SMTP configuration.\n"); exit(1);
}
