<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role(['applicant', 'admin']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(404);
    exit('Document not found.');
}

$sql = 'SELECT d.*, a.user_id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ?';
$statement = db()->prepare($sql);
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document || (!can_manage_applications($user['role'] ?? '') && (int) $document['user_id'] !== (int) $user['id'])) {
    http_response_code(404);
    exit('Document not found.');
}
$path = app_config('upload_dir') . '/' . basename($document['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Stored document is missing.');
}
$downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $document['original_name']) ?: 'document';
header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . addslashes($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
