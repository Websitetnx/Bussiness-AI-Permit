<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_guest();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if (!$errors) {
        $previousRequest = $_SESSION['password_reset_request'] ?? null;
        $previousChallengeId = is_array($previousRequest)
            && strtolower((string) ($previousRequest['email'] ?? '')) === $email
            ? (int) ($previousRequest['challenge_id'] ?? 0)
            : 0;
        $previousRequestToken = is_array($previousRequest)
            && strtolower((string) ($previousRequest['email'] ?? '')) === $email
            ? (string) ($previousRequest['request_token'] ?? '')
            : '';
        unset($_SESSION['password_reset_authorized']);
        $_SESSION['password_reset_request'] = [
            'email' => $email,
            'challenge_id' => $previousChallengeId ?: null,
            'request_token' => preg_match('/^[a-f0-9]{64}$/D', $previousRequestToken) === 1
                ? $previousRequestToken
                : null,
        ];

        try {
            $statement = db()->prepare("SELECT id, name, email FROM users WHERE email = ? AND role = 'applicant' AND is_active = 1 LIMIT 1");
            $statement->execute([$email]);
            $user = $statement->fetch();
            if ($user) {
                $challenge = issue_auth_otp(db(), [
                    'email' => (string) $user['email'],
                    'name' => (string) $user['name'],
                    'purpose' => 'password_reset',
                    'user_id' => (int) $user['id'],
                    'pending_name' => null,
                    'pending_password_hash' => null,
                ]);
                $_SESSION['password_reset_request']['challenge_id'] = (int) $challenge['id'];
                $_SESSION['password_reset_request']['request_token'] = (string) $challenge['request_token'];
            }
        } catch (Throwable $exception) {
            // Keep the browser response identical for unknown, disabled, throttled,
            // and temporarily undeliverable accounts to prevent account discovery.
            error_log('Password reset OTP request was not issued: ' . $exception->getMessage());
        }

        flash('success', 'If an active applicant account uses that email, a verification code has been sent.');
        redirect('verify-reset-otp.php');
    }
}

require dirname(__DIR__, 2) . '/frontend/views/forgot-password.php';
