<?php
declare(strict_types=1);

putenv('OTP_EXPIRY_SECONDS=600');
putenv('OTP_MAX_ATTEMPTS=5');
putenv('OTP_RESEND_COOLDOWN_SECONDS=60');

$root = dirname(__DIR__);
require __DIR__ . '/support/project_source.php';
require $root . '/includes/functions.php';
require $root . '/includes/mailer.php';
require $root . '/includes/auth_otp.php';

/**
 * A narrow PDO double for the queries issued by includes/auth_otp.php.
 * It keeps this regression test independent of SMTP and a configured MySQL
 * database while still exercising the production OTP orchestration.
 */
final class AuthOtpTestPdo extends PDO
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;
    private int $lastId = 0;
    private bool $activeTransaction = false;
    /** @var array{rows: array<int, array<string, mixed>>, next_id: int, last_id: int}|null */
    private ?array $snapshot = null;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AuthOtpTestStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        if ($this->activeTransaction) {
            return false;
        }
        $this->snapshot = [
            'rows' => $this->rows,
            'next_id' => $this->nextId,
            'last_id' => $this->lastId,
        ];
        $this->activeTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        if (!$this->activeTransaction) {
            return false;
        }
        $this->snapshot = null;
        $this->activeTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->activeTransaction || $this->snapshot === null) {
            return false;
        }
        $this->rows = $this->snapshot['rows'];
        $this->nextId = $this->snapshot['next_id'];
        $this->lastId = $this->snapshot['last_id'];
        $this->snapshot = null;
        $this->activeTransaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->activeTransaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastId;
    }

    public function insertChallenge(array $params, int $expirySeconds): void
    {
        [$requestTokenHash, $email, $purpose, $otpHash, $userId, $pendingName, $pendingPasswordHash] = $params;
        $now = time();
        $id = $this->nextId++;
        $this->lastId = $id;
        $this->rows[$id] = [
            'id' => $id,
            'request_token_hash' => $requestTokenHash,
            'email' => $email,
            'purpose' => $purpose,
            'otp_hash' => $otpHash,
            'user_id' => $userId,
            'pending_name' => $pendingName,
            'pending_password_hash' => $pendingPasswordHash,
            'attempts' => 0,
            'expires_at' => $now + $expirySeconds,
            'last_sent_at' => $now,
            'consumed_at' => null,
        ];
    }

    public function replaceChallenge(array $params, int $expirySeconds): int
    {
        [$otpHash, $userId, $pendingName, $pendingPasswordHash, $id] = $params;
        $id = (int) $id;
        if (!isset($this->rows[$id])) {
            return 0;
        }
        $now = time();
        $this->rows[$id] = array_replace($this->rows[$id], [
            'otp_hash' => $otpHash,
            'user_id' => $userId,
            'pending_name' => $pendingName,
            'pending_password_hash' => $pendingPasswordHash,
            'attempts' => 0,
            'expires_at' => $now + $expirySeconds,
            'last_sent_at' => $now,
            'consumed_at' => null,
        ]);
        return 1;
    }
}

final class AuthOtpTestStatement extends PDOStatement
{
    private AuthOtpTestPdo $pdo;
    private string $query;
    private mixed $result = false;
    private int $affectedRows = 0;

    public function __construct(AuthOtpTestPdo $pdo, string $query)
    {
        $this->pdo = $pdo;
        $this->query = preg_replace('/\s+/', ' ', trim($query)) ?: $query;
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->result = false;
        $this->affectedRows = 0;

        if (str_starts_with($this->query, 'DELETE FROM auth_otp_challenges WHERE expires_at <= NOW()')) {
            foreach ($this->pdo->rows as $id => $row) {
                if ((int) $row['expires_at'] <= time()) {
                    unset($this->pdo->rows[$id]);
                    $this->affectedRows++;
                }
                if ($this->affectedRows >= 100) {
                    break;
                }
            }
            return true;
        }

        if (str_starts_with($this->query, 'INSERT INTO auth_otp_challenges')) {
            $this->pdo->insertChallenge($params, $this->numberAfter('INTERVAL ', 600));
            $this->affectedRows = 1;
            return true;
        }

        if (str_starts_with($this->query, 'UPDATE auth_otp_challenges SET otp_hash')) {
            $this->affectedRows = $this->pdo->replaceChallenge(
                $params,
                $this->numberAfter('INTERVAL ', 600)
            );
            return true;
        }

        if (str_contains($this->query, 'TIMESTAMPDIFF(SECOND, last_sent_at, NOW())')) {
            [$email, $purpose] = $params;
            $cooldown = $this->numberAfter('GREATEST(0, ', 60);
            foreach ($this->pdo->rows as $row) {
                if ($row['email'] === $email && $row['purpose'] === $purpose) {
                    $this->result = [
                        'id' => $row['id'],
                        'retry_after' => max(0, $cooldown - (time() - (int) $row['last_sent_at'])),
                    ];
                    break;
                }
            }
            return true;
        }

        if (str_starts_with($this->query, 'SELECT id, email, purpose')) {
            [$id, $requestTokenHash, $email, $purpose] = $params;
            $row = $this->pdo->rows[(int) $id] ?? null;
            $maxAttempts = $this->numberAfter('GREATEST(0, ', 5);
            if ($row
                && $row['request_token_hash'] === $requestTokenHash
                && $row['email'] === $email
                && $row['purpose'] === $purpose
                && $row['consumed_at'] === null
                && (int) $row['expires_at'] > time()
                && (int) $row['attempts'] < $maxAttempts) {
                $row['remaining_attempts'] = max(0, $maxAttempts - (int) $row['attempts']);
                if (!str_contains($this->query, 'otp_hash')) {
                    unset($row['otp_hash'], $row['consumed_at']);
                }
                $this->result = $row;
            }
            return true;
        }

        if (str_contains($this->query, 'SET attempts = ?')) {
            $attempts = (int) $params[0];
            $maxAttempts = $this->numberAfter('>= ', 5);
            $id = (int) $params[count($params) - 2];
            if (isset($this->pdo->rows[$id]) && $this->pdo->rows[$id]['consumed_at'] === null) {
                $this->pdo->rows[$id]['attempts'] = $attempts;
                if ($attempts >= $maxAttempts) {
                    $this->pdo->rows[$id]['consumed_at'] = time();
                    $this->pdo->rows[$id]['otp_hash'] = '';
                    $this->pdo->rows[$id]['pending_name'] = null;
                    $this->pdo->rows[$id]['pending_password_hash'] = null;
                }
                $this->affectedRows = 1;
            }
            return true;
        }

        if (str_contains($this->query, 'SET consumed_at = NOW()')) {
            $id = (int) $params[0];
            $maxAttempts = $this->numberAfter('attempts < ', 5);
            $row = $this->pdo->rows[$id] ?? null;
            if ($row
                && $row['consumed_at'] === null
                && (int) $row['expires_at'] > time()
                && (int) $row['attempts'] < $maxAttempts) {
                $this->pdo->rows[$id]['consumed_at'] = time();
                $this->pdo->rows[$id]['otp_hash'] = '';
                $this->pdo->rows[$id]['pending_name'] = null;
                $this->pdo->rows[$id]['pending_password_hash'] = null;
                $this->affectedRows = 1;
            }
            return true;
        }

        throw new RuntimeException('Unexpected OTP test query: ' . $this->query);
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $result = $this->result;
        $this->result = false;
        return $result;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    private function numberAfter(string $marker, int $fallback): int
    {
        $position = strpos($this->query, $marker);
        if ($position === false) {
            return $fallback;
        }
        $tail = substr($this->query, $position + strlen($marker));
        return preg_match('/^(\d+)/', $tail, $match) === 1 ? (int) $match[1] : $fallback;
    }
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$throws = static function (callable $operation, string $expectedClass, string $message) use ($assert): Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        $assert($exception instanceof $expectedClass, $message . ' (unexpected ' . $exception::class . ')');
        return $exception;
    }
    $assert(false, $message . ' (no exception)');
    throw new LogicException('Unreachable');
};

$assert(mask_email(' Alice.Example@Example.COM ') === 'a***@example.com', 'Email masking should normalize case and hide the local part.');
$assert(mask_email('not-an-email') === '***', 'Invalid email input should be fully masked.');
$assert(auth_otp_normalize_email(' USER@Example.com ') === 'user@example.com', 'OTP email normalization should be deterministic.');
$throws(
    static fn() => auth_otp_normalize_purpose('login'),
    InvalidArgumentException::class,
    'Unknown OTP purposes must be rejected.'
);

$mail = null;
send_auth_otp_email(
    ' Applicant@Example.com ',
    '<Applicant>',
    '001234',
    'registration',
    static function (array $message) use (&$mail): bool {
        $mail = $message;
        return true;
    }
);
$assert(is_array($mail), 'The injected mail transport should receive a message.');
$assert($mail['to_email'] === 'applicant@example.com', 'Mail recipients should be normalized.');
$assert($mail['code'] === '001234' && $mail['purpose'] === 'registration', 'OTP mail metadata should retain leading zeroes and purpose.');
$assert(str_contains($mail['html_body'], '&lt;Applicant&gt;'), 'HTML email output should escape the applicant name.');
$assert(!str_contains($mail['html_body'], '<Applicant>'), 'Unescaped recipient content must not enter HTML email output.');
$throws(
    static fn() => send_auth_otp_email('applicant@example.com', 'Applicant', '12345', 'registration', static fn() => true),
    InvalidArgumentException::class,
    'OTP email codes must contain exactly six digits.'
);

$pdo = new AuthOtpTestPdo();
$sent = [];
$pendingHash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
$registration = issue_auth_otp($pdo, [
    'email' => ' New.Applicant@Example.com ',
    'name' => 'New Applicant',
    'purpose' => 'registration',
    'pending_name' => 'New Applicant',
    'pending_password_hash' => $pendingHash,
], static function (array $message) use (&$sent): bool {
    $sent[] = $message;
    return true;
});

$registrationId = (int) $registration['id'];
$registrationCode = (string) $sent[0]['code'];
$storedRegistration = $pdo->rows[$registrationId];
$assert($registrationId === 1, 'Issuing an OTP should return its challenge ID.');
$assert($registration['masked_email'] === 'n***@example.com', 'Issued challenges should return only a masked display email.');
$assert(preg_match('/^\d{6}$/D', $registrationCode) === 1, 'Issued OTPs must contain six digits.');
$assert($storedRegistration['otp_hash'] !== $registrationCode, 'The raw OTP must not be stored.');
$assert(password_verify($registrationCode, $storedRegistration['otp_hash']), 'The stored OTP hash must verify the delivered code.');
$assert($storedRegistration['pending_password_hash'] === $pendingHash, 'Registration data should retain only the pending password hash.');
$assert(!array_key_exists('otp_hash', $registration), 'Public challenge results must not expose the OTP hash.');

$usable = auth_otp_challenge($pdo, $registrationId, $registration['request_token'], 'new.applicant@example.com', 'registration');
$assert(is_array($usable) && !array_key_exists('otp_hash', $usable), 'Challenge lookup must not expose the OTP hash.');

$wrongCode = $registrationCode === '999999' ? '000000' : '999999';
$callbackRuns = 0;
$assert(
    consume_auth_otp(
        $pdo,
        $registrationId,
        $registration['request_token'],
        'new.applicant@example.com',
        'registration',
        $wrongCode,
        static function () use (&$callbackRuns): void {
            $callbackRuns++;
        }
    ) === false,
    'An incorrect OTP must fail verification.'
);
$assert($pdo->rows[$registrationId]['attempts'] === 1, 'An incorrect OTP should increment the attempt count.');
$assert($callbackRuns === 0, 'The success callback must not run for an incorrect OTP.');

$assert(
    consume_auth_otp(
        $pdo,
        $registrationId,
        $registration['request_token'],
        'NEW.APPLICANT@example.com',
        'registration',
        $registrationCode,
        static function (array $challenge) use (&$callbackRuns, $assert): void {
            $callbackRuns++;
            $assert(!array_key_exists('otp_hash', $challenge), 'The success callback must not receive the OTP hash.');
        }
    ),
    'The delivered OTP should verify once.'
);
$assert($callbackRuns === 1, 'The success callback should run exactly once.');
$assert(
    $pdo->rows[$registrationId]['otp_hash'] === ''
        && $pdo->rows[$registrationId]['pending_name'] === null
        && $pdo->rows[$registrationId]['pending_password_hash'] === null,
    'Consumed registration challenges must clear OTP and pending credential data.'
);
$assert(
    !consume_auth_otp($pdo, $registrationId, $registration['request_token'], 'new.applicant@example.com', 'registration', $registrationCode, static fn() => null),
    'A consumed OTP must not be reusable.'
);

$resetMail = null;
$reset = issue_auth_otp($pdo, [
    'email' => 'reset@example.com',
    'name' => 'Reset Applicant',
    'purpose' => 'password_reset',
    'user_id' => 42,
], static function (array $message) use (&$resetMail): bool {
    $resetMail = $message;
    return true;
});
$resetId = (int) $reset['id'];
$resetCode = (string) $resetMail['code'];
$throws(
    static fn() => consume_auth_otp(
        $pdo,
        $resetId,
        $reset['request_token'],
        'reset@example.com',
        'password_reset',
        $resetCode,
        static function (): void {
            throw new RuntimeException('Simulated account update failure.');
        }
    ),
    RuntimeException::class,
    'A failing success callback should be surfaced.'
);
$assert($pdo->rows[$resetId]['consumed_at'] === null, 'Callback failure should roll back OTP consumption.');
$assert(
    consume_auth_otp($pdo, $resetId, $reset['request_token'], 'reset@example.com', 'password_reset', $resetCode, static fn() => null),
    'An OTP should remain usable after its transactional callback rolls back.'
);

$expiredMail = null;
$expired = issue_auth_otp($pdo, [
    'email' => 'expired@example.com',
    'name' => 'Expired Applicant',
    'purpose' => 'password_reset',
    'user_id' => 44,
], static function (array $message) use (&$expiredMail): bool {
    $expiredMail = $message;
    return true;
});
$expiredId = (int) $expired['id'];
$pdo->rows[$expiredId]['expires_at'] = time() - 1;
$assert(auth_otp_challenge($pdo, $expiredId, $expired['request_token'], 'expired@example.com', 'password_reset') === null, 'Expired challenges must not be returned as usable.');
$assert(
    !consume_auth_otp($pdo, $expiredId, $expired['request_token'], 'expired@example.com', 'password_reset', (string) $expiredMail['code'], static fn() => null),
    'An expired OTP must not be accepted.'
);

$attemptMail = null;
$attempt = issue_auth_otp($pdo, [
    'email' => 'attempt-limit@example.com',
    'name' => 'Attempt Limit',
    'purpose' => 'password_reset',
    'user_id' => 45,
], static function (array $message) use (&$attemptMail): bool {
    $attemptMail = $message;
    return true;
});
$attemptId = (int) $attempt['id'];
$attemptCode = (string) $attemptMail['code'];
$assert(!isset($pdo->rows[$expiredId]), 'Issuing a later challenge should purge expired OTP rows.');
$badAttemptCode = $attemptCode === '111111' ? '222222' : '111111';
$limitedCallbackRuns = 0;
for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
    $accepted = consume_auth_otp(
        $pdo,
        $attemptId,
        $attempt['request_token'],
        'attempt-limit@example.com',
        'password_reset',
        $badAttemptCode,
        static function () use (&$limitedCallbackRuns): void {
            $limitedCallbackRuns++;
        }
    );
    $assert(!$accepted, "Incorrect OTP attempt {$attemptNumber} must fail.");
}
$assert($pdo->rows[$attemptId]['attempts'] === 5, 'The attempt counter should stop a challenge at the configured limit.');
$assert($pdo->rows[$attemptId]['consumed_at'] !== null, 'A challenge should be invalidated after the final allowed failed attempt.');
$assert(
    $pdo->rows[$attemptId]['otp_hash'] === ''
        && $pdo->rows[$attemptId]['pending_name'] === null
        && $pdo->rows[$attemptId]['pending_password_hash'] === null,
    'Attempt-locked challenges must clear OTP and pending credential data.'
);
$assert(
    !consume_auth_otp($pdo, $attemptId, $attempt['request_token'], 'attempt-limit@example.com', 'password_reset', $attemptCode, static fn() => null),
    'The correct OTP must fail after the attempt limit is reached.'
);
$assert($limitedCallbackRuns === 0, 'Attempt-limited challenges must never run the success callback.');

$rateMailCount = 0;
$rateData = [
    'email' => 'rate-limit@example.com',
    'name' => 'Rate Limit',
    'purpose' => 'password_reset',
    'user_id' => 43,
];
issue_auth_otp($pdo, $rateData, static function () use (&$rateMailCount): bool {
    $rateMailCount++;
    return true;
});
$rateException = $throws(
    static fn() => issue_auth_otp($pdo, $rateData, static function () use (&$rateMailCount): bool {
        $rateMailCount++;
        return true;
    }),
    AuthOtpRateLimitException::class,
    'Immediate resend requests should be rate limited.'
);
$assert($rateException->getRetryAfter() > 0, 'Rate-limit errors should report a positive retry delay.');
$assert($rateMailCount === 1, 'A rate-limited resend must not send another email.');

$rowCountBeforeFailure = count($pdo->rows);
$throws(
    static fn() => issue_auth_otp($pdo, [
        'email' => 'delivery-failure@example.com',
        'name' => 'Delivery Failure',
        'purpose' => 'registration',
        'pending_name' => 'Delivery Failure',
        'pending_password_hash' => password_hash('password-for-test', PASSWORD_DEFAULT),
    ], static fn() => false),
    RuntimeException::class,
    'A failed mail transport should fail OTP issuance.'
);
$assert(count($pdo->rows) === $rowCountBeforeFailure, 'Failed email delivery should roll back the pending challenge.');

$read = static function (string $relativePath) use ($root): string {
    $contents = project_test_source($root, $relativePath);
    if ($contents === false) {
        throw new RuntimeException("Could not read {$relativePath}.");
    }
    return $contents;
};

foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $variable) {
    $assert(str_contains($read('config.php'), "getenv('{$variable}')"), "config.php must support {$variable}.");
}

foreach (['register.php', 'verify-registration.php', 'forgot-password.php', 'verify-reset-otp.php', 'reset-password.php', 'login.php'] as $page) {
    $assert(is_file($root . '/' . $page), "{$page} must exist.");
    $assert(str_contains($read($page), 'csrf_'), "{$page} must retain CSRF protection.");
}

$assert(str_contains($read('register.php'), "'purpose' => 'registration'"), 'Registration must issue a registration OTP.');
$assert(str_contains($read('verify-registration.php'), "'registration'"), 'Registration verification must consume a registration OTP.');
$assert(str_contains($read('forgot-password.php'), 'If an active applicant account uses that email'), 'Forgot-password responses must avoid account discovery.');
$assert(str_contains($read('verify-reset-otp.php'), "'password_reset'"), 'Password recovery must consume a password-reset OTP.');
$assert(str_contains($read('reset-password.php'), 'password_hash('), 'Password reset must hash the replacement password.');
$assert(str_contains($read('login.php'), 'forgot-password.php'), 'The sign-in page must link to password recovery.');

$migration = $read('database/migrations/007_auth_otp.sql');
$assert(str_contains($migration, 'auth_otp_challenges'), 'Migration 007 must create the OTP challenge table.');
$assert(str_contains($migration, 'otp_hash'), 'Migration 007 must persist only the OTP hash.');
$assert(str_contains($migration, 'UNIQUE'), 'Migration 007 must enforce unique request-token bindings.');

echo "Authentication OTP regression tests passed ({$checks} checks; no email sent and no production database used).\n";
