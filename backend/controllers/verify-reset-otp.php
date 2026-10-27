<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_guest();

$request = $_SESSION['password_reset_request'] ?? null;
if (!is_array($request) || !filter_var($request['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Request a new password reset code to continue.');
    redirect('forgot-password.php');
}

$email = strtolower((string) $request['email']);
$challengeId = isset($request['challenge_id']) ? (int) $request['challenge_id'] : 0;
$requestToken = (string) ($request['request_token'] ?? '');
$otpLifetimeSeconds = auth_otp_settings()['expiry_seconds'];
$otpLifetimeMinutes = max(1, (int) ceil($otpLifetimeSeconds / 60));
$errors = [];
$notices = pull_flashes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        try {
            $statement = db()->prepare("SELECT id, name, email FROM users WHERE email = ? AND role = 'applicant' AND is_active = 1 LIMIT 1");
            $statement->execute([$email]);
            $user = $statement->fetch();
            if ($user) {
                if ($challengeId > 0 && preg_match('/^[a-f0-9]{64}$/D', $requestToken) === 1) {
                    $challenge = resend_auth_otp(
                        db(),
                        $challengeId,
                        $requestToken,
                        $email,
                        'password_reset',
                        ['name' => (string) $user['name']]
                    );
                } else {
                    $challenge = issue_auth_otp(db(), [
                        'email' => (string) $user['email'],
                        'name' => (string) $user['name'],
                        'purpose' => 'password_reset',
                        'user_id' => (int) $user['id'],
                    ]);
                }
                $_SESSION['password_reset_request']['challenge_id'] = (int) $challenge['id'];
                $_SESSION['password_reset_request']['request_token'] = (string) $challenge['request_token'];
            }
        } catch (Throwable $exception) {
            error_log('Password reset OTP resend was not issued: ' . $exception->getMessage());
        }

        flash('success', 'If an active applicant account uses that email, a valid verification code has been sent.');
        redirect('verify-reset-otp.php');
    }

    $code = trim((string) ($_POST['otp_code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        $errors[] = 'Enter the 6-digit verification code.';
    } else {
        $authorizedUser = null;
        try {
            $verified = $challengeId > 0 && consume_auth_otp(
                db(),
                $challengeId,
                $requestToken,
                $email,
                'password_reset',
                $code,
                static function (array $challenge, PDO $pdo) use (&$authorizedUser): void {
                    $statement = $pdo->prepare("SELECT id, email FROM users WHERE id = ? AND email = ? AND role = 'applicant' AND is_active = 1 LIMIT 1");
                    $statement->execute([(int) ($challenge['user_id'] ?? 0), (string) $challenge['email']]);
                    $authorizedUser = $statement->fetch() ?: null;
                    if (!$authorizedUser) {
                        throw new RuntimeException('The password reset account is unavailable.');
                    }
                }
            );

            if (!$verified || !$authorizedUser) {
                $errors[] = 'The code is incorrect, expired, or has reached its attempt limit.';
            } else {
                unset($_SESSION['password_reset_request']);
                session_regenerate_id(true);
                $_SESSION['password_reset_authorized'] = [
                    'user_id' => (int) $authorizedUser['id'],
                    'email' => (string) $authorizedUser['email'],
                    'expires_at' => time() + $otpLifetimeSeconds,
                ];
                redirect('reset-password.php');
            }
        } catch (Throwable $exception) {
            error_log('Password reset OTP verification failed: ' . $exception->getMessage());
            $errors[] = 'The code is incorrect, expired, or has reached its attempt limit.';
        }
    }
}

require dirname(__DIR__, 2) . '/frontend/views/verify-reset-otp.php';
