<?php
declare(strict_types=1);
// Run only against a disposable DB whose name starts with permit_test_.
require dirname(__DIR__).'/includes/functions.php';require dirname(__DIR__).'/includes/database.php';
require dirname(__DIR__).'/includes/mailer.php';require dirname(__DIR__).'/includes/operations.php';
require dirname(__DIR__).'/includes/reports.php';require dirname(__DIR__).'/includes/backup.php';
if (!str_starts_with((string)app_config('db')['name'],'permit_test_')) {fwrite(STDERR,"Use a disposable permit_test_ database.\n");exit(1);}
date_default_timezone_set('Asia/Manila');$pdo=db();$pdo->exec("SET time_zone='+00:00'");
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function expect_failure(callable $fn,string $message): void {try{$fn();}catch(Throwable){return;}throw new RuntimeException($message);}
foreach(['owner'=>'applicant','other'=>'applicant','reviewer'=>'admin','second'=>'admin','inactive'=>'admin','treasury'=>'treasurer'] as $name=>$role){$pdo->prepare('INSERT INTO users (name,email,password_hash,role,is_active) VALUES (?,?,?,?,?)')->execute([$name,$name.'@example.test','synthetic-test-hash',$role,$name==='inactive'?0:1]);}
$pdo->exec("INSERT INTO businesses (user_id,business_name,business_type,organization_type,tin,contact,email,address) VALUES (1,'Synthetic Business','Retail','Sole Proprietorship','123456789','09123456789','unverified-contact@example.test','Private address not for public output')");
$pdo->exec("INSERT INTO applications (user_id,business_id,reference,permit_number,status,submitted_at) VALUES (1,1,'BPL-TEST-1','BP-TEST-1','For Review','2026-01-01 00:00:00')");
notify_application($pdo,1,'test:first','Test event','Please review <script>unsafe</script>.');
notify_application($pdo,1,'test:first','Test event','Please review <script>unsafe</script>.');
check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===3,'One job per active owner/admin; duplicate event suppressed.');
check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'In-app events deduplicated.');
$pdo->beginTransaction();notify_application($pdo,1,'test:rollback','Rollback','Never sent');$pdo->rollBack();
check((int)$pdo->query('SELECT COUNT(*) FROM notification_events')->fetchColumn()===1,'Rollback must remove event and jobs.');
$cfg=app_config('db');$other=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['name'].';charset=utf8mb4',$cfg['user'],$cfg['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$messages=[];$result=deliver_outbox($pdo,50,static function(array $m)use(&$messages,$other):bool{$messages[]=$m;check(!str_contains($m['html_body'],'<script>'),'HTML escaped.');check(deliver_outbox($other,1,static fn()=>true)['busy'],'Concurrent worker must not send.');return $m['to_email']!=='owner@example.test';});
check($result['sent']===2 && $result['failed']===1,'Independent SMTP failure must not stop other recipients.');
check($messages[0]['to_email']==='owner@example.test','Use registered email, not unverified business contact.');
check((int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='pending'")->fetchColumn()===1,'Failure scheduled for retry.');
$pdo->exec("UPDATE email_outbox SET available_at=NOW() WHERE status='pending'");$retry=[];
deliver_outbox($pdo,50,static function($m)use(&$retry){$retry[]=$m;return true;});
check($retry[0]['message_id']===$messages[0]['message_id'],'Retry must keep stable Message-ID.');
check(deliver_outbox($pdo,50,static fn()=>true)['sent']===0,'Sent mail must not be sent again.');
notify_application($pdo,1,'test:revoke','Staff alert','Internal','staff');$pdo->exec('UPDATE users SET is_active=0 WHERE id=4');
deliver_outbox($pdo,50,static fn()=>true);
check((int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='cancelled'")->fetchColumn()===1,'Removed staff authorization cancels delivery.');
notify_application($pdo,1,'test:exhaust','Retry test','Test','staff');
for($i=0;$i<5;$i++){$pdo->exec("UPDATE email_outbox SET available_at=NOW() WHERE status='pending'");deliver_outbox($pdo,50,static fn()=>false);}
check((int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='failed' AND attempts=5")->fetchColumn()===1,'Retries must stop after five attempts.');
$tmp=sys_get_temp_dir().'/permit-ops-'.bin2hex(random_bytes(6));mkdir($tmp,0700);mkdir($tmp.'/uploads',0700);
file_put_contents($tmp.'/uploads/first.pdf','synthetic first file');file_put_contents($tmp.'/uploads/second.pdf','synthetic replacement');
$pdo->exec("INSERT INTO application_documents (application_id,document_type,original_name,stored_name,mime_type,file_size) VALUES (1,'registration_doc','first.pdf','first.pdf','application/pdf',20)");
$v1=snapshot_document($pdo,1);
$pdo->exec("INSERT INTO document_ai_scans (document_id,application_id,scan_status,model,summary,scanned_by) VALUES (1,1,'Completed','test-model','First assessment',3)");snapshot_document($pdo,1);
$pdo->exec("UPDATE document_ai_scans SET summary='Manual reassessment' WHERE document_id=1");snapshot_document($pdo,1);
$pdo->beginTransaction();$pdo->prepare('UPDATE document_versions SET archived_at=NOW() WHERE id=?')->execute([$v1]);
$pdo->exec("UPDATE application_documents SET original_name='second.pdf',stored_name='second.pdf' WHERE id=1");$pdo->exec('DELETE FROM document_ai_scans WHERE document_id=1');$v2=snapshot_document($pdo,1);$pdo->commit();
$pdo->prepare('INSERT INTO document_review_notes (version_id,reviewer_id,note) VALUES (?,?,?)')->execute([$v1,3,'Private review note']);
check($v1!==$v2,'Replacement gets a new version.');check((int)$pdo->query('SELECT COUNT(*) FROM document_scan_history')->fetchColumn()===2,'All previous scan results retained.');check(is_file($tmp.'/uploads/first.pdf'),'Previous upload remains.');
$pdo->exec("INSERT INTO application_assignments (application_id,reviewer_id,assigned_by,due_at) VALUES (1,3,3,'2026-01-01 00:00:00')");
generate_overdue_alerts($pdo);generate_overdue_alerts($pdo);
check((int)$pdo->query("SELECT COUNT(*) FROM notification_events WHERE title='Review overdue'")->fetchColumn()===1,'One overdue alert per application revision per day.');
$pdo->exec("UPDATE applications SET status='Needs Revision' WHERE id=1");check(generate_overdue_alerts($pdo)===0,'Applicant revisions pause overdue alerts.');
$pdo->exec("INSERT INTO application_status_history (application_id,status,created_at) VALUES (1,'Approved','2026-01-03 00:00:00'),(1,'Released',NOW()),(1,'Needs Revision','2026-01-02 00:00:00')");
$pdo->exec("UPDATE applications SET status='Released',permit_effective_date=CURDATE(),permit_expiration_date=DATE_SUB(DATE_ADD(CURDATE(),INTERVAL 1 YEAR),INTERVAL 1 DAY),validity_source='bplo',validity_confirmed_at=NOW() WHERE id=1");$v=permit_verification_record($pdo,1);$public=public_permit_status($pdo,$v['token']);
check(array_keys($public)===['permit_number','status','issued_at','effective_date','valid_until'],'Public verification must expose only minimal fields and the confirmed dates.');check($public['status']==='Released','Released permit visible.');
check(public_permit_status($pdo,'invalid')===null && public_permit_status($pdo,str_repeat('a',64))===null,'Invalid and unknown tokens reveal nothing.');
$pdo->exec("UPDATE applications SET permit_expiration_date='2000-01-01' WHERE id=1");check(public_permit_status($pdo,$v['token'])['status']==='Expired','Expired permit reported from the canonical application date.');
$pdo->exec("UPDATE applications SET status='Rejected' WHERE id=1");check(public_permit_status($pdo,$v['token'])['status']==='Not currently released','Current status always controls validity.');
$pdo->exec("UPDATE applications SET status='Released' WHERE id=1");
$pdo->exec("INSERT INTO applications (user_id,business_id,reference,permit_number,application_type,status,submitted_at,permit_effective_date,permit_expiration_date) VALUES (1,1,'BPL-TEST-2','BP-TEST-1','Renewal','Released','2026-01-05',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 1 YEAR))");
check(public_permit_status($pdo,$v['token'])['status']==='Superseded','New release supersedes old QR.');
$pdo->exec("INSERT INTO payments (application_id,amount,status,paid_at,payment_method) VALUES (1,1500,'Paid','2026-01-10','City Treasurer Counter'),(2,900,'Pending',NULL,'GCash')");
$range=report_range('2026-01-01','2026-01-31');$collections=report_rows($pdo,'collections',$range);
check(count($collections)===1 && (float)$collections[0]['total_php']===1500.0,'Collections must exclude unpaid fees.');
check((float)report_rows($pdo,'processing',$range)[0]['average_calendar_days']===2.0,'Processing uses first approval, not latest status edit.');
check((int)report_rows($pdo,'renewals',$range)[0]['renewals']===1,'Renewals filtered.');check(count(report_rows($pdo,'revisions',$range))===1,'Revision events counted.');
check(csv_safe_cell(' =HYPERLINK("x")')[0]==="'",'Spreadsheet formulas neutralized.');expect_failure(static fn()=>report_range('2026-02-30','2026-03-01'),'Invalid date must fail.');
$pass='synthetic-backup-password-only';$archive=$tmp.'/test.backup.zip';
file_put_contents($tmp.'/uploads/prior-payment.pdf','synthetic rejected payment proof');
file_put_contents($tmp.'/uploads/current-payment.pdf','synthetic corrected payment proof');
$pdo->exec("INSERT INTO payment_installments(payment_id,sequence_no,label,amount,due_date,proof_stored_name) VALUES(2,1,'Annual',900,'2026-01-20','current-payment.pdf')");
$pdo->exec("INSERT INTO payment_evidence(installment_id,attempt_number,submission_token,payment_method,payer_name,payment_reference,proof_stored_name,status,submitted_at) VALUES(1,1,REPEAT('c',64),'GCash','Owner','BACKUP-REF','prior-payment.pdf','Failed',NOW())");
$manifest=create_backup($pdo,$tmp.'/uploads',$archive,$pass);check(count(inspect_backup($archive,$pass)['uploads'])===4,'Backup contains current and historical documents and payment proofs.');
expect_failure(static fn()=>inspect_backup($archive,'wrong'),'Wrong password must fail.');
// The workflow creates a separate empty restore database. Never drop or clear tables here.
$restoreName=getenv('PERMIT_TEST_RESTORE_DB')?:'permit_test_restore';
if (!preg_match('/^permit_test_[a-z0-9_]+$/D',$restoreName) || $restoreName===$cfg['name']) throw new RuntimeException('Restore fixture requires a separate test database.');
$restore=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$restoreName.';charset=utf8mb4',$cfg['user'],$cfg['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$restored=restore_backup($restore,$archive,$tmp.'/restored-uploads',$pass);
check($manifest['tables']===$restored['tables'],'All table counts survive restoration.');check(file_get_contents($tmp.'/restored-uploads/first.pdf')==='synthetic first file','Historical document restored.');
check(file_get_contents($tmp.'/restored-uploads/prior-payment.pdf')==='synthetic rejected payment proof','Rejected installment evidence survives restore.');
check(file_get_contents($tmp.'/restored-uploads/current-payment.pdf')==='synthetic corrected payment proof','Current installment evidence survives restore.');
check(public_permit_status($restore,$v['token'])['status']==='Superseded','QR token and status survive restore.');
expect_failure(static fn()=>restore_backup($restore,$archive,$tmp.'/another',$pass),'Nonempty restore DB must be refused.');
echo "Operations integration tests passed: events, authorization, retries, concurrency, versions, scans, notes, overdue, QR privacy, reports, encrypted backup and verified restore.\n";
