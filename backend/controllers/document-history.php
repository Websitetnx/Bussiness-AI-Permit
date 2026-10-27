<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/includes/bootstrap.php';require dirname(__DIR__).'/includes/layout.php';
$user=require_role(['applicant','admin']);$pdo=db();$admin=can_manage_applications($user['role']);
$id=filter_input(INPUT_GET,'application_id',FILTER_VALIDATE_INT);
$q=$pdo->prepare('SELECT id,user_id,reference FROM applications WHERE id=?');$q->execute([$id?:0]);$a=$q->fetch();
if (!$a || (!$admin && (int)$a['user_id']!==(int)$user['id'])) {http_response_code(404);exit('Application not found.');}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$admin) {http_response_code(403);exit('Administrator access required.');} verify_csrf();
    $version=filter_input(INPUT_POST,'version_id',FILTER_VALIDATE_INT);$note=trim((string)($_POST['note']??''));
    if ($note==='' || mb_strlen($note)>4000) {flash('error','Enter a note of 1 to 4000 characters.');redirect('document-history.php?application_id='.$id);}
    $q=$pdo->prepare('SELECT v.id FROM document_versions v JOIN application_documents d ON d.id=v.document_id WHERE v.id=? AND d.application_id=?');$q->execute([$version?:0,$id]);
    if (!$q->fetch()) {http_response_code(404);exit('Version not found.');}
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO document_review_notes (version_id,reviewer_id,note) VALUES (?,?,?)')->execute([$version,$user['id'],$note]);
    audit($pdo,(int)$user['id'],'document_review_note','document_version',(int)$version);$pdo->commit();
    flash('success','Internal reviewer note saved.');redirect('document-history.php?application_id='.$id);
}
$q=$pdo->prepare('SELECT v.*,d.document_type,d.stored_name current_file,u.name uploader FROM document_versions v JOIN application_documents d ON d.id=v.document_id LEFT JOIN users u ON u.id=v.uploaded_by WHERE d.application_id=? ORDER BY d.id,v.id DESC');$q->execute([$id]);$versions=$q->fetchAll();

$versionScans = [];
$versionNotes = [];
foreach ($versions as $versionRow) {
    $versionId = (int) $versionRow['id'];
    $scanQuery = $pdo->prepare('SELECT * FROM document_scan_history WHERE version_id=? ORDER BY id DESC');
    $scanQuery->execute([$versionId]);
    $versionScans[$versionId] = $scanQuery->fetchAll();
    if ($admin) {
        $noteQuery = $pdo->prepare('SELECT n.note,n.created_at,u.name FROM document_review_notes n JOIN users u ON u.id=n.reviewer_id WHERE n.version_id=? ORDER BY n.id');
        $noteQuery->execute([$versionId]);
        $versionNotes[$versionId] = $noteQuery->fetchAll();
    }
}

require dirname(__DIR__, 2) . '/frontend/views/document-history.php';
