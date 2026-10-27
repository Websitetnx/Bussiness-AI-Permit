<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/backend/includes/bootstrap.php';
$pdo=db();
if (installment_workflow_ready($pdo)) exit("SKIP: legacy database required.\n");
function expiration_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo->beginTransaction();
try {
    $tag=bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('Expiration test',?,'not-a-login','applicant')")->execute([$tag.'@example.test']);
    $userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO businesses (user_id,business_name,business_type,organization_type,tin,contact,email,address) VALUES (?,'Expiration test','Retail','Sole Proprietorship','TEST','09123456789','contact@example.test','Synthetic address')")->execute([$userId]);
    $businessId=(int)$pdo->lastInsertId();
    $issued=(new DateTimeImmutable('today'))->modify('+2 days')->modify('-1 year')->format('Y-m-d');
    $pdo->prepare("INSERT INTO applications (user_id,business_id,reference,permit_number,status,approved_at) VALUES (?,?,?,?,'Released',?)")->execute([$userId,$businessId,$tag,$tag,$issued]);
    $appId=(int)$pdo->lastInsertId();
    create_notification($pdo,$userId,'Old in-app renewal reminder',$appId);
    $alerts=check_and_generate_renewal_notices($pdo,$userId);
    check_and_generate_renewal_notices($pdo,$userId);
    $q=$pdo->prepare('SELECT o.user_id,e.title,e.body,e.audience FROM email_outbox o JOIN notification_events e ON e.id=o.event_id WHERE e.application_id=?');
    $q->execute([$appId]);$jobs=$q->fetchAll();
    expiration_check(count($alerts)===1 && !$alerts[0]['is_expired'],'Upcoming alert missing.');
    expiration_check(count($jobs)===1,'Existing in-app notice must allow one email, with no duplicate on recheck.');
    expiration_check((int)$jobs[0]['user_id']===$userId && $jobs[0]['audience']==='applicant','Only the applicant receives expiration email.');
    $message=build_notification_email($jobs[0]+['email'=>$tag.'@example.test','name'=>'Test','reference'=>$tag,'application_id'=>$appId,'role'=>'applicant'],'http://localhost');
    expiration_check(str_contains($message['subject'],'Approaching permit expiration'),'Email subject must describe expiration.');
    // Expired just after midnight must not be classified as upcoming because diff rounds to zero days.
    $expiredIssue=(new DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d');
    $pdo->prepare('UPDATE applications SET approved_at=? WHERE id=?')->execute([$expiredIssue,$appId]);
    $alerts=check_and_generate_renewal_notices($pdo,$userId);
    check_and_generate_renewal_notices($pdo,$userId);
    $q->execute([$appId]);$jobs=$q->fetchAll();
    expiration_check($alerts[0]['is_expired'] && count($jobs)===2,'Expired notice must queue once separately.');
    $pdo->prepare("INSERT INTO applications (user_id,business_id,reference,permit_number,application_type,status) VALUES (?,?,?,?,'Renewal','For Review')")->execute([$userId,$businessId,$tag.'-renew',$tag]);
    expiration_check(check_and_generate_renewal_notices($pdo,$userId)===[],'An active renewal must suppress expiration reminders.');
    echo "PASS: upcoming and expired emails, applicant-only recipients, deduplication, existing in-app notice, renewal suppression.\n";
} finally {
    $pdo->rollBack();
}
