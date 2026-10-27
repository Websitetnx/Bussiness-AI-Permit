<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

/**
 * Reliable Email Queue Engine
 *
 * Provides persistent, deduplicated, retryable email delivery. Every outbound
 * notification (except time-critical OTPs) is enqueued here and delivered
 * asynchronously by the CLI processor or inline at enqueue time.
 *
 * Retry schedule (exponential back-off):
 *   Attempt 1 → immediate
 *   Attempt 2 → +1 minute
 *   Attempt 3 → +5 minutes
 *   Attempt 4 → +15 minutes
 *   Attempt 5 → +60 minutes
 *   After 5 failures → permanently_failed
 */

/** Back-off delays in seconds, indexed by attempt number (1-based). */
function email_queue_backoff_seconds(int $failedAttempts): int
{
    return match (true) {
        $failedAttempts <= 1 => 60,
        $failedAttempts === 2 => 300,
        $failedAttempts === 3 => 900,
        $failedAttempts === 4 => 3600,
        default               => 14400,
    };
}

/**
 * Compute a deterministic idempotency key for an outbound email.
 *
 * The key prevents the same logical notification from being queued twice.
 * It combines the recipient, subject, context type, and context ID so that
 * different event types for the same entity each get their own slot.
 */
function email_queue_idempotency_key(
    string $recipientEmail,
    string $subject,
    string $contextType,
    ?int $contextId
): string {
    return hash('sha256', implode('|', [
        strtolower(trim($recipientEmail)),
        trim($subject),
        $contextType,
        (string) ($contextId ?? ''),
    ]));
}

/**
 * Enqueue an email for reliable delivery.
 *
 * If an entry with the same idempotency key already exists the insert is
 * silently skipped — this is the duplicate-prevention mechanism.
 *
 * @param  PDO    $pdo         Database connection
 * @param  array  $message     Must contain: to_email, to_name, subject, html_body, text_body
 * @param  string $contextType Event label, e.g. 'payment_verified', 'permit_released'
 * @param  int|null $contextId Related entity ID (application_id, payment_id, etc.)
 * @return int|null            Queue row ID, or null if duplicate was skipped
 */
function enqueue_email(PDO $pdo, array $message, string $contextType = '', ?int $contextId = null): ?int
{
    $recipientEmail = strtolower(trim((string) ($message['to_email'] ?? '')));
    $recipientName  = trim((string) ($message['to_name'] ?? ''));
    $subject        = trim((string) ($message['subject'] ?? ''));
    $htmlBody       = (string) ($message['html_body'] ?? '');
    $textBody       = (string) ($message['text_body'] ?? '');

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid recipient email address is required for the queue.');
    }
    if ($subject === '' || $htmlBody === '' || $textBody === '') {
        throw new InvalidArgumentException('Email subject and body content are required for the queue.');
    }

    $idempotencyKey = email_queue_idempotency_key($recipientEmail, $subject, $contextType, $contextId);

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO email_queue
            (idempotency_key, recipient_email, recipient_name, subject, html_body, text_body,
             context_type, context_id, status, attempts, next_retry_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', 0, NOW())'
    );
    $stmt->execute([
        $idempotencyKey,
        $recipientEmail,
        $recipientName,
        $subject,
        $htmlBody,
        $textBody,
        $contextType,
        $contextId,
    ]);

    if ($stmt->rowCount() === 0) {
        // Duplicate idempotency key — silently skipped
        return null;
    }

    return (int) $pdo->lastInsertId();
}

/**
 * Process the email queue: send pending and retriable failed messages.
 *
 * Designed to be called from a cron job every minute. Each invocation
 * processes up to $batchSize messages. Uses SELECT … FOR UPDATE SKIP LOCKED
 * to allow concurrent processors without double-sends.
 *
 * @return array{processed: int, succeeded: int, failed: int}
 */
function process_email_queue(PDO $pdo, int $batchSize = 10, ?callable $transport = null): array
{
    $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

    $batchSize = max(1, min(100, $batchSize));

    // Read candidate IDs first so a slow SMTP server does not lock the whole
    // batch. Each candidate is claimed with SELECT ... FOR UPDATE below.
    $select = $pdo->prepare(
        'SELECT id FROM email_queue
         WHERE status IN (\'pending\', \'failed\')
           AND (next_retry_at IS NULL OR next_retry_at <= NOW())
         ORDER BY next_retry_at ASC, id ASC
         LIMIT ?'
    );
    $select->bindValue(1, $batchSize, PDO::PARAM_INT);
    $select->execute();
    $candidateIds = $select->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($candidateIds as $candidateId) {
        $queueId = (int) $candidateId;

        try {
            $pdo->beginTransaction();
            $claim = $pdo->prepare(
                'SELECT id, recipient_email, recipient_name, subject, html_body, text_body,
                        attempts, max_attempts
                 FROM email_queue
                 WHERE id = ? AND status IN (\'pending\', \'failed\')
                   AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                 FOR UPDATE'
            );
            $claim->execute([$queueId]);
            $row = $claim->fetch();
            if (!$row) {
                $pdo->commit();
                continue;
            }

            $result['processed']++;
            $attempt = (int) $row['attempts'] + 1;
            $maxAttempts = max(1, (int) $row['max_attempts']);

            try {
                send_app_mail([
                    'to_email'  => $row['recipient_email'],
                    'to_name'   => $row['recipient_name'],
                    'subject'   => $row['subject'],
                    'html_body' => $row['html_body'],
                    'text_body' => $row['text_body'],
                ], $transport);

                $update = $pdo->prepare(
                    'UPDATE email_queue
                     SET status = \'sent\', attempts = ?, sent_at = NOW(),
                         next_retry_at = NULL, last_error = NULL
                     WHERE id = ?'
                );
                $update->execute([$attempt, $queueId]);
                $result['succeeded']++;
            } catch (Throwable $exception) {
                $errorMessage = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $exception->getMessage()) ?? '');
                if ($errorMessage === '') {
                    $errorMessage = 'Unknown delivery error';
                }

                if ($attempt >= $maxAttempts) {
                    $update = $pdo->prepare(
                        'UPDATE email_queue
                         SET status = \'permanently_failed\', attempts = ?, last_error = ?, next_retry_at = NULL
                         WHERE id = ?'
                    );
                    $update->execute([$attempt, $errorMessage, $queueId]);
                } else {
                    $backoffSeconds = email_queue_backoff_seconds($attempt);
                    $update = $pdo->prepare(
                        'UPDATE email_queue
                         SET status = \'failed\', attempts = ?, last_error = ?,
                             next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                         WHERE id = ?'
                    );
                    $update->execute([$attempt, $errorMessage, $backoffSeconds, $queueId]);
                }

                log_mail_failure('queue delivery (id=' . $queueId . ', attempt=' . $attempt . ')', $exception);
                $result['failed']++;
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_mail_failure('queue processor (id=' . $queueId . ')', $exception);
            $result['failed']++;
        }
    }

    return $result;
}

/**
 * Reset a permanently-failed email so the queue processor retries it.
 */
function retry_queued_email(PDO $pdo, int $queueId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = \'failed\', attempts = 0, sent_at = NULL,
             next_retry_at = NOW(), last_error = NULL
         WHERE id = ? AND status = \'permanently_failed\''
    );
    $stmt->execute([$queueId]);
    return $stmt->rowCount() > 0;
}

/**
 * Paginated email queue log for the admin UI.
 *
 * @return array{rows: array, total: int}
 */
function get_email_queue_log(PDO $pdo, int $limit = 25, int $offset = 0, ?string $statusFilter = null): array
{
    $where = '';
    $params = [];
    if ($statusFilter !== null && $statusFilter !== '') {
        $where = ' WHERE status = ?';
        $params[] = $statusFilter;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM email_queue' . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $query = 'SELECT id, idempotency_key, recipient_email, recipient_name, subject,
                     context_type, context_id, status, attempts, max_attempts,
                     last_error, next_retry_at, sent_at, created_at, updated_at
              FROM email_queue' . $where . '
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?';
    $stmt = $pdo->prepare($query);
    $paramIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($paramIndex++, $param);
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total];
}

/**
 * Summary counts by status for dashboard badges.
 *
 * @return array{pending: int, sent: int, failed: int, permanently_failed: int, total: int}
 */
function email_queue_stats(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT status, COUNT(*) cnt FROM email_queue GROUP BY status'
    );
    $rows = $stmt->fetchAll();
    $stats = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'permanently_failed' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $key = str_replace(' ', '_', (string) $row['status']);
        if (isset($stats[$key])) {
            $stats[$key] = (int) $row['cnt'];
        }
        $stats['total'] += (int) $row['cnt'];
    }
    return $stats;
}
