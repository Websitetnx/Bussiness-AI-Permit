<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

final class AuthOtpRateLimitException extends RuntimeException
{
    private int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct('Please wait ' . $retryAfter . ' seconds before requesting another verification code.');
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}

function auth_otp_settings(): array
{
    $settings = app_config('auth_otp');
    $settings = is_array($settings) ? $settings : [];

    return [
        'expiry_seconds' => max(60, (int) ($settings['expiry_seconds'] ?? 600)),
        'max_attempts' => max(1, (int) ($settings['max_attempts'] ?? 5)),
        'resend_cooldown_seconds' => max(0, (int) ($settings['resend_cooldown_seconds'] ?? 60)),
    ];
}

function mask_email(string $email): string
{
    $email = strtolower(trim($email));
    $separator = strrpos($email, '@');
    if ($separator === false || $separator === 0 || $separator === strlen($email) - 1) {
        return '***';
    }

    $local = substr($email, 0, $separator);
    $domain = substr($email, $separator + 1);
    return substr($local, 0, 1) . '***@' . $domain;
}

/** @return array{owns_transaction: bool, savepoint: ?string} */
function auth_otp_begin_atomic(PDO $pdo): array
{
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        return ['owns_transaction' => true, 'savepoint' => null];
    }

    static $savepointCounter = 0;
    $savepoint = 'auth_otp_' . ++$savepointCounter;
    $pdo->exec('SAVEPOINT ' . $savepoint);
    return ['owns_transaction' => false, 'savepoint' => $savepoint];
}

function auth_otp_finish_atomic(PDO $pdo, array $scope): void
{
    if ($scope['owns_transaction']) {
        $pdo->commit();
        return;
    }

    $pdo->exec('RELEASE SAVEPOINT ' . $scope['savepoint']);
}

function auth_otp_rollback_atomic(PDO $pdo, array $scope): void
{
    try {
        if ($scope['owns_transaction']) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return;
        }

        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $scope['savepoint']);
            $pdo->exec('RELEASE SAVEPOINT ' . $scope['savepoint']);
        }
    } catch (Throwable) {
        // Preserve the original operation error when rollback itself fails.
    }
}

function auth_otp_normalize_purpose(string $purpose): string
{
    $purpose = strtolower(trim($purpose));
    if (!in_array($purpose, ['registration', 'password_reset'], true)) {
        throw new InvalidArgumentException('Unsupported verification purpose.');
    }
    return $purpose;
}

function auth_otp_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
    return $email;
}

function auth_otp_request_token_hash(string $requestToken): string
{
    $requestToken = strtolower(trim($requestToken));
    if (preg_match('/^[a-f0-9]{64}$/D', $requestToken) !== 1) {
        throw new InvalidArgumentException('A valid verification request token is required.');
    }

    return hash('sha256', $requestToken);
}

/**
 * Return a usable challenge without exposing its OTP hash.
 */
function auth_otp_challenge(
    PDO $pdo,
    int $challengeId,
    string $requestToken,
    string $email,
    string $purpose
): ?array
{
    if ($challengeId <= 0) {
        return null;
    }

    try {
        $email = auth_otp_normalize_email($email);
        $purpose = auth_otp_normalize_purpose($purpose);
        $requestTokenHash = auth_otp_request_token_hash($requestToken);
    } catch (InvalidArgumentException) {
        return null;
    }

    $settings = auth_otp_settings();
    $maxAttempts = $settings['max_attempts'];
    $statement = $pdo->prepare("SELECT id, email, purpose, user_id, pending_name, pending_password_hash,
            attempts, expires_at, last_sent_at,
            GREATEST(0, {$maxAttempts} - attempts) AS remaining_attempts
        FROM auth_otp_challenges
        WHERE id = ? AND request_token_hash = ? AND email = ? AND purpose = ?
          AND consumed_at IS NULL AND expires_at > NOW() AND attempts < {$maxAttempts}
        LIMIT 1");
    $statement->execute([$challengeId, $requestTokenHash, $email, $purpose]);
    $challenge = $statement->fetch();
    if (!$challenge) {
        return null;
    }

    $challenge['id'] = (int) $challenge['id'];
    $challenge['user_id'] = $challenge['user_id'] === null ? null : (int) $challenge['user_id'];
    $challenge['attempts'] = (int) $challenge['attempts'];
    $challenge['remaining_attempts'] = (int) $challenge['remaining_attempts'];
    return $challenge;
}

/**
 * Create or replace the single challenge for an email/purpose pair and email it.
 *
 * Registration data must contain pending_name and pending_password_hash.
 * Password reset data must contain user_id. The raw OTP is never returned or
 * persisted; an injected transport can inspect message['code'] in tests.
 */
function issue_auth_otp(PDO $pdo, array $data, ?callable $transport = null): array
{
    $email = auth_otp_normalize_email((string) ($data['email'] ?? ''));
    $purpose = auth_otp_normalize_purpose((string) ($data['purpose'] ?? ''));
    $recipientName = trim((string) ($data['name'] ?? $data['pending_name'] ?? ''));
    $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
    $pendingName = isset($data['pending_name']) ? trim((string) $data['pending_name']) : null;
    $pendingPasswordHash = isset($data['pending_password_hash']) ? (string) $data['pending_password_hash'] : null;

    if ($purpose === 'registration') {
        $pendingName ??= $recipientName;
        if (strlen($pendingName) < 2 || strlen($pendingName) > 120) {
            throw new InvalidArgumentException('A valid applicant name is required.');
        }
        if ($pendingPasswordHash === null || $pendingPasswordHash === '' || strlen($pendingPasswordHash) > 255) {
            throw new InvalidArgumentException('A pending password hash is required for registration.');
        }
        $recipientName = $recipientName !== '' ? $recipientName : $pendingName;
        $userId = null;
    } else {
        if ($userId === null || $userId <= 0) {
            throw new InvalidArgumentException('A valid user is required for password reset.');
        }
        $pendingName = null;
        $pendingPasswordHash = null;
    }

    $settings = auth_otp_settings();
    $expirySeconds = $settings['expiry_seconds'];
    $cooldownSeconds = $settings['resend_cooldown_seconds'];
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($code, PASSWORD_DEFAULT);
    if (!is_string($otpHash) || $otpHash === '') {
        throw new RuntimeException('The verification code could not be secured.');
    }
    $requestToken = bin2hex(random_bytes(32));
    $requestTokenHash = auth_otp_request_token_hash($requestToken);

    $scope = auth_otp_begin_atomic($pdo);
    try {
        // Keep public registration attempts from growing this table forever.
        // Expired rows can no longer be verified, and old consumed rows retain
        // no usable credential material.
        $cleanup = $pdo->prepare('DELETE FROM auth_otp_challenges
            WHERE expires_at <= NOW()
               OR (consumed_at IS NOT NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
            LIMIT 100');
        $cleanup->execute();

        $find = $pdo->prepare("SELECT id,
                GREATEST(0, {$cooldownSeconds} - TIMESTAMPDIFF(SECOND, last_sent_at, NOW())) AS retry_after
            FROM auth_otp_challenges
            WHERE email = ? AND purpose = ?
            ORDER BY last_sent_at DESC, id DESC
            LIMIT 1 FOR UPDATE");
        $find->execute([$email, $purpose]);
        $latest = $find->fetch();

        if ($latest && (int) $latest['retry_after'] > 0) {
            throw new AuthOtpRateLimitException((int) $latest['retry_after']);
        }

        // Every initial request gets its own session-bound row. Never update a
        // registration row selected only by email: doing so could replace the
        // pending password created in a different browser.
        $save = $pdo->prepare("INSERT INTO auth_otp_challenges
            (request_token_hash, email, purpose, otp_hash, user_id, pending_name,
             pending_password_hash, attempts, expires_at, last_sent_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL {$expirySeconds} SECOND), NOW(), NOW(), NOW())");
        $save->execute([
            $requestTokenHash,
            $email,
            $purpose,
            $otpHash,
            $userId,
            $pendingName,
            $pendingPasswordHash,
        ]);
        $challengeId = (int) $pdo->lastInsertId();

        $challenge = auth_otp_challenge($pdo, $challengeId, $requestToken, $email, $purpose);
        if ($challenge === null) {
            throw new RuntimeException('The verification challenge could not be created.');
        }

        send_auth_otp_email($email, $recipientName, $code, $purpose, $transport);
        auth_otp_finish_atomic($pdo, $scope);

        return $challenge + [
            'request_token' => $requestToken,
            'masked_email' => mask_email($email),
            'resend_after_seconds' => $cooldownSeconds,
        ];
    } catch (Throwable $exception) {
        auth_otp_rollback_atomic($pdo, $scope);
        throw $exception;
    }
}

/**
 * Replace the code only for the challenge bound to this browser's random
 * request token. Registration credential data is never selected by email.
 */
function resend_auth_otp(
    PDO $pdo,
    int $challengeId,
    string $requestToken,
    string $email,
    string $purpose,
    array $fallback = [],
    ?callable $transport = null
): array {
    if ($challengeId <= 0) {
        throw new RuntimeException('The verification request is no longer available.');
    }

    $email = auth_otp_normalize_email($email);
    $purpose = auth_otp_normalize_purpose($purpose);
    $requestTokenHash = auth_otp_request_token_hash($requestToken);
    $settings = auth_otp_settings();
    $expirySeconds = $settings['expiry_seconds'];
    $cooldownSeconds = $settings['resend_cooldown_seconds'];

    $scope = auth_otp_begin_atomic($pdo);
    try {
        $latestStatement = $pdo->prepare("SELECT id,
                GREATEST(0, {$cooldownSeconds} - TIMESTAMPDIFF(SECOND, last_sent_at, NOW())) AS retry_after
            FROM auth_otp_challenges
            WHERE email = ? AND purpose = ?
            ORDER BY last_sent_at DESC, id DESC
            LIMIT 1 FOR UPDATE");
        $latestStatement->execute([$email, $purpose]);
        $latest = $latestStatement->fetch();
        if ($latest && (int) $latest['retry_after'] > 0) {
            throw new AuthOtpRateLimitException((int) $latest['retry_after']);
        }

        $find = $pdo->prepare('SELECT id, email, purpose, user_id, pending_name, pending_password_hash
            FROM auth_otp_challenges
            WHERE id = ? AND request_token_hash = ? AND email = ? AND purpose = ?
            LIMIT 1 FOR UPDATE');
        $find->execute([$challengeId, $requestTokenHash, $email, $purpose]);
        $stored = $find->fetch();
        if (!$stored) {
            throw new RuntimeException('The verification request is no longer available.');
        }

        $recipientName = trim((string) ($fallback['name'] ?? $stored['pending_name'] ?? ''));
        $userId = $stored['user_id'] === null ? null : (int) $stored['user_id'];
        $pendingName = $stored['pending_name'] === null ? null : trim((string) $stored['pending_name']);
        $pendingPasswordHash = $stored['pending_password_hash'] === null
            ? null
            : (string) $stored['pending_password_hash'];

        if ($purpose === 'registration') {
            $pendingName = $pendingName ?: trim((string) ($fallback['pending_name'] ?? ''));
            $pendingPasswordHash = $pendingPasswordHash ?: (string) ($fallback['pending_password_hash'] ?? '');
            if (strlen($pendingName) < 2 || strlen($pendingName) > 120 || $pendingPasswordHash === '') {
                throw new RuntimeException('The pending registration is no longer available.');
            }
            $recipientName = $recipientName !== '' ? $recipientName : $pendingName;
            $userId = null;
        } elseif ($userId === null || $userId <= 0) {
            throw new RuntimeException('The password reset account is no longer available.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($code, PASSWORD_DEFAULT);
        if (!is_string($otpHash) || $otpHash === '') {
            throw new RuntimeException('The verification code could not be secured.');
        }

        $save = $pdo->prepare("UPDATE auth_otp_challenges
            SET otp_hash = ?, user_id = ?, pending_name = ?, pending_password_hash = ?,
                attempts = 0, expires_at = DATE_ADD(NOW(), INTERVAL {$expirySeconds} SECOND),
                last_sent_at = NOW(), consumed_at = NULL, updated_at = NOW()
            WHERE id = ? AND request_token_hash = ? AND email = ? AND purpose = ?");
        $save->execute([
            $otpHash,
            $userId,
            $pendingName,
            $pendingPasswordHash,
            $challengeId,
            $requestTokenHash,
            $email,
            $purpose,
        ]);
        if ($save->rowCount() !== 1) {
            throw new RuntimeException('The verification request could not be refreshed.');
        }

        $challenge = auth_otp_challenge($pdo, $challengeId, $requestToken, $email, $purpose);
        if ($challenge === null) {
            throw new RuntimeException('The verification challenge could not be refreshed.');
        }

        send_auth_otp_email($email, $recipientName, $code, $purpose, $transport);
        auth_otp_finish_atomic($pdo, $scope);

        return $challenge + [
            'request_token' => $requestToken,
            'masked_email' => mask_email($email),
            'resend_after_seconds' => $cooldownSeconds,
        ];
    } catch (Throwable $exception) {
        auth_otp_rollback_atomic($pdo, $scope);
        throw $exception;
    }
}

/**
 * Atomically verify and consume a challenge.
 *
 * The callback runs inside the same transaction and receives
 * callable(array $challenge, PDO $pdo): void. If it throws, consumption rolls
 * back, allowing a corrected operation to use the still-valid code.
 */
function consume_auth_otp(
    PDO $pdo,
    int $challengeId,
    string $requestToken,
    string $email,
    string $purpose,
    string $code,
    callable $onValid
): bool {
    if ($challengeId <= 0) {
        return false;
    }

    try {
        $email = auth_otp_normalize_email($email);
        $purpose = auth_otp_normalize_purpose($purpose);
        $requestTokenHash = auth_otp_request_token_hash($requestToken);
    } catch (InvalidArgumentException) {
        return false;
    }

    $settings = auth_otp_settings();
    $maxAttempts = $settings['max_attempts'];
    $scope = auth_otp_begin_atomic($pdo);

    try {
        $find = $pdo->prepare("SELECT id, email, purpose, otp_hash, user_id, pending_name,
                pending_password_hash, attempts, expires_at, last_sent_at,
                GREATEST(0, {$maxAttempts} - attempts) AS remaining_attempts
            FROM auth_otp_challenges
            WHERE id = ? AND request_token_hash = ? AND email = ? AND purpose = ?
              AND consumed_at IS NULL AND expires_at > NOW() AND attempts < {$maxAttempts}
            LIMIT 1 FOR UPDATE");
        $find->execute([$challengeId, $requestTokenHash, $email, $purpose]);
        $challenge = $find->fetch();

        if (!$challenge) {
            auth_otp_finish_atomic($pdo, $scope);
            return false;
        }

        $isSixDigits = preg_match('/^\d{6}$/D', trim($code)) === 1;
        $matches = password_verify($isSixDigits ? trim($code) : 'invalid-code', (string) $challenge['otp_hash']);
        if (!$isSixDigits || !$matches) {
            $attempts = (int) $challenge['attempts'] + 1;
            $failure = $pdo->prepare("UPDATE auth_otp_challenges
                SET attempts = ?, consumed_at = CASE WHEN ? >= {$maxAttempts} THEN NOW() ELSE consumed_at END,
                    otp_hash = CASE WHEN ? >= {$maxAttempts} THEN '' ELSE otp_hash END,
                    pending_name = CASE WHEN ? >= {$maxAttempts} THEN NULL ELSE pending_name END,
                    pending_password_hash = CASE WHEN ? >= {$maxAttempts} THEN NULL ELSE pending_password_hash END,
                    updated_at = NOW()
                WHERE id = ? AND request_token_hash = ? AND consumed_at IS NULL");
            $failure->execute([$attempts, $attempts, $attempts, $attempts, $attempts, $challengeId, $requestTokenHash]);
            auth_otp_finish_atomic($pdo, $scope);
            return false;
        }

        $consume = $pdo->prepare("UPDATE auth_otp_challenges
            SET consumed_at = NOW(), otp_hash = '', pending_name = NULL,
                pending_password_hash = NULL, updated_at = NOW()
            WHERE id = ? AND request_token_hash = ?
              AND consumed_at IS NULL AND expires_at > NOW() AND attempts < {$maxAttempts}");
        $consume->execute([$challengeId, $requestTokenHash]);
        if ($consume->rowCount() !== 1) {
            auth_otp_finish_atomic($pdo, $scope);
            return false;
        }

        unset($challenge['otp_hash']);
        $challenge['id'] = (int) $challenge['id'];
        $challenge['user_id'] = $challenge['user_id'] === null ? null : (int) $challenge['user_id'];
        $challenge['attempts'] = (int) $challenge['attempts'];
        $challenge['remaining_attempts'] = (int) $challenge['remaining_attempts'];
        $onValid($challenge, $pdo);

        auth_otp_finish_atomic($pdo, $scope);
        return true;
    } catch (Throwable $exception) {
        auth_otp_rollback_atomic($pdo, $scope);
        throw $exception;
    }
}
