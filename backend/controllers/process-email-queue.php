<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}


/**
 * Email Queue Processor — CLI cron script
 *
 * Run every minute via cron or Task Scheduler:
 *   * * * * * php /path/to/process-email-queue.php
 *
 * Processes pending and retriable failed messages from the email_outbox table.
 * Uses a lock file and database advisory lock to prevent overlapping runs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$lockFile = sys_get_temp_dir() . '/permitflow_email_queue.lock';

// Prevent overlapping runs
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Another queue processor is still running.\n";
    exit(0);
}

// Write PID for debugging
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

$batchSize = max(1, min(200, (int) ($argv[1] ?? 50)));
$startTime = microtime(true);

try {
    $pdo = db();
    check_and_generate_renewal_notices($pdo);
    $result = deliver_outbox($pdo, $batchSize);

    $elapsed = round((microtime(true) - $startTime) * 1000);
    echo date('Y-m-d H:i:s')
        . " [DONE] sent={$result['sent']}"
        . " failed={$result['failed']}"
        . " busy=" . ($result['busy'] ? 'yes' : 'no')
        . " elapsed={$elapsed}ms"
        . " batch={$batchSize}\n";
} catch (Throwable $exception) {
    $elapsed = round((microtime(true) - $startTime) * 1000);
    echo date('Y-m-d H:i:s')
        . " [ERROR] {$exception->getMessage()}"
        . " elapsed={$elapsed}ms\n";
    error_log('[PERMIT email-queue] ' . $exception->getMessage());
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
}
