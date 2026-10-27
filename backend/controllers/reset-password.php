<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_guest();

$authorization = $_SESSION['password_reset_authorized'] ?? null;
if (!is_array($authorization)
    || empty($authorization['user_id'])
    || !filter_var($authorization['email'] ?? null, FILTER_VALIDATE_EMAIL)
    || (int) ($authorization['expires_at'] ?? 0) < time()) {
    unset($_SESSION['password_reset_authorized']);
    flash('error', 'Your password reset authorization expired. Request a new code.');
    redirect('forgot-password.php');
}

$userId = (int) $authorization['user_id'];
$email = strtolower((string) $authorization['email']);
$otpLifetimeMinutes = max(1, (int) ceil(auth_otp_settings()['expiry_seconds'] / 60));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (strlen($password) < 8) $errors[] = 'Password must contain at least 8 characters.';
    if ($password !== $confirmation) $errors[] = 'Password confirmation does not match.';

    if (!$errors) {
        $pdo = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND email = ? AND role = 'applicant' AND is_active = 1");
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId, $email]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The applicant account is unavailable.');
            }
            $clearCodes = $pdo->prepare("DELETE FROM auth_otp_challenges WHERE user_id = ? AND purpose = 'password_reset'");
            $clearCodes->execute([$userId]);
            audit($pdo, $userId, 'reset_password', 'user', $userId);
            $pdo->commit();

            unset($_SESSION['password_reset_authorized'], $_SESSION['password_reset_request']);
            session_regenerate_id(true);
            flash('success', 'Your password was updated. You can now sign in.');
            redirect('login.php');
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Password reset failed: ' . $exception->getMessage());
            $errors[] = 'We could not update your password. Please request a new reset code.';
        }
    }
}

require dirname(__DIR__, 2) . '/frontend/views/reset-password.php';
