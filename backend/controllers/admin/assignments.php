<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2).'/includes/bootstrap.php';
require dirname(__DIR__, 2).'/includes/layout.php';
$user=require_role('admin'); $pdo=db(); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $id=filter_input(INPUT_POST,'application_id',FILTER_VALIDATE_INT);
        $reviewer=filter_input(INPUT_POST,'reviewer_id',FILTER_VALIDATE_INT);
        $due=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($_POST['due_at']??''));
        if (!$id || !$reviewer || !$due || $due->format('Y-m-d\TH:i')!==($_POST['due_at']??'') || $due<=new DateTimeImmutable()) throw new RuntimeException('Select an application, reviewer and future deadline.');
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT id FROM applications WHERE id=? AND status IN ('For Review','Needs Revision') FOR UPDATE");$q->execute([$id]);
        if (!$q->fetch()) throw new RuntimeException('Only applications awaiting review or correction can be assigned.');
        $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1 FOR UPDATE");$q->execute([$reviewer]);
        if (!$q->fetch()) throw new RuntimeException('Select an active BPLO administrator.');
        $pdo->prepare('INSERT INTO application_assignments (application_id,reviewer_id,assigned_by,due_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE reviewer_id=VALUES(reviewer_id),assigned_by=VALUES(assigned_by),assigned_at=NOW(),due_at=VALUES(due_at),revision=revision+1')->execute([$id,$reviewer,$user['id'],$due->format('Y-m-d H:i:s')]);
        $pdo->prepare('INSERT INTO assignment_history (application_id,reviewer_id,assigned_by,due_at) VALUES (?,?,?,?)')->execute([$id,$reviewer,$user['id'],$due->format('Y-m-d H:i:s')]);
        $event=(int)$pdo->lastInsertId();
        notify_application($pdo,$id,'assignment:'.$event,'Reviewer assignment updated','A BPLO reviewer has been assigned. Internal review deadline: '.$due->format('Y-m-d H:i').'.','staff');
        audit($pdo,(int)$user['id'],'assign_reviewer','application',$id);$pdo->commit();
        flash('success','Reviewer assigned and staff notifications queued.');redirect('admin/assignments.php');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e instanceof PDOException?'Assignment could not be saved.':$e->getMessage(); }
}
$filter=(string)($_GET['filter']??'all');
$where="a.status IN ('For Review','Needs Revision')";
if ($filter==='mine') $where.=' AND x.reviewer_id='.(int)$user['id'];
if ($filter==='overdue') $where.=" AND a.status='For Review' AND x.due_at<NOW()";
if ($filter==='unassigned') $where.=' AND x.application_id IS NULL';
$rows=$pdo->query("SELECT a.id,a.reference,a.status,u.name,u.is_active,x.due_at FROM applications a LEFT JOIN application_assignments x ON x.application_id=a.id LEFT JOIN users u ON u.id=x.reviewer_id WHERE $where ORDER BY x.due_at IS NULL,x.due_at,a.id DESC LIMIT 200")->fetchAll();
$reviewers=$pdo->query("SELECT id,name FROM users WHERE role='admin' AND is_active=1 ORDER BY name")->fetchAll();

$history=$pdo->query('SELECT h.*,a.reference,u.name FROM assignment_history h JOIN applications a ON a.id=h.application_id JOIN users u ON u.id=h.reviewer_id ORDER BY h.id DESC LIMIT 50')->fetchAll();

require dirname(__DIR__, 3) . '/frontend/views/admin/assignments.php';
