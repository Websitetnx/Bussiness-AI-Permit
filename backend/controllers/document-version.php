<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/includes/bootstrap.php';$user=require_role(['applicant','admin']);
$q=db()->prepare('SELECT v.*,a.user_id FROM document_versions v JOIN application_documents d ON d.id=v.document_id JOIN applications a ON a.id=d.application_id WHERE v.id=?');
$q->execute([filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0]);$v=$q->fetch();
if (!$v || (!can_manage_applications($user['role']) && (int)$v['user_id']!==(int)$user['id'])) {http_response_code(404);exit('Document not found.');}
$path=app_config('upload_dir').'/'.basename($v['stored_name']);
if (!is_file($path)) {http_response_code(404);exit('Stored document is missing.');}
header('Cache-Control: private, no-store');header('Content-Type: '.$v['mime_type']);header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="'.(preg_replace('/[^A-Za-z0-9._ -]/','_',$v['original_name'])?:'document').'"');
readfile($path);
