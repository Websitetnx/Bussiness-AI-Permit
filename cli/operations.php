<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
try {
    $pdo=db();
    $command=$argv[1]??'run';
    if ($command==='backfill') {
        foreach($pdo->query('SELECT id FROM application_documents')->fetchAll(PDO::FETCH_COLUMN) as $id) snapshot_document($pdo,(int)$id);
        foreach($pdo->query("SELECT id FROM applications WHERE status='Released'")->fetchAll(PDO::FETCH_COLUMN) as $id) permit_verification_record($pdo,(int)$id);
        echo "Current documents/scans and released permits backfilled.\n";
    } elseif ($command==='run') {
        generate_overdue_alerts($pdo);
        check_and_generate_renewal_notices($pdo);
        echo json_encode(deliver_outbox($pdo),JSON_THROW_ON_ERROR).PHP_EOL;
    } else {throw new RuntimeException('Usage: php cli/operations.php [run|backfill]');}
} catch (Throwable $e) {fwrite(STDERR,"Operations command failed. Check migrations and configuration.\n");exit(1);}
