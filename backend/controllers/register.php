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
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (strlen($name) < 2 || strlen($name) > 120) $errors[] = 'Enter your complete name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (strlen($password) < 8) $errors[] = 'Password must contain at least 8 characters.';
    if ($password !== $confirmation) $errors[] = 'Password confirmation does not match.';

    if (!$errors) {
        try {
            $pdo = db();
            $statement = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $statement->execute([$email]);
            if ($statement->fetchColumn()) {
                $errors[] = 'An account already uses this email address.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $challenge = issue_auth_otp($pdo, [
                    'email' => $email,
                    'name' => $name,
                    'purpose' => 'registration',
                    'user_id' => null,
                    'pending_name' => $name,
                    'pending_password_hash' => $passwordHash,
                ]);
                $_SESSION['pending_registration'] = [
                    'challenge_id' => (int) $challenge['id'],
                    'request_token' => (string) $challenge['request_token'],
                    'email' => $email,
                    'pending_name' => $name,
                    'pending_password_hash' => $passwordHash,
                ];
                redirect('verify-registration.php');
            }
        } catch (AuthOtpRateLimitException $exception) {
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            $errors[] = $exception->getCode() === '23000'
                ? 'An account already uses this email address.'
                : 'Account creation failed. Import the latest database migration and try again.';
        } catch (Throwable $exception) {
            error_log('Registration OTP delivery failed: ' . $exception->getMessage());
            $errors[] = 'We could not send the verification code. Check the mail settings or try again shortly.';
        }
    }
}

require dirname(__DIR__, 2) . '/frontend/views/register.php';
