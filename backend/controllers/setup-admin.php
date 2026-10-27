<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

if (is_treasurer_role(current_user()['role'] ?? '')) {
    deny_access('Access denied. Treasurer accounts are limited to payment management.');
}

$errors = [];
$databaseReady = true;
$adminExists = false;
try {
    $adminExists = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
} catch (PDOException) {
    $databaseReady = false;
}

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$configuredKey = (string) app_config('admin_setup_key');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady && !$adminExists) {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (!$isLocal && ($configuredKey === '' || !hash_equals($configuredKey, (string) ($_POST['setup_key'] ?? '')))) $errors[] = 'The administrator setup key is invalid.';
    if (strlen($name) < 2 || strlen($name) > 120) $errors[] = 'Enter the administrator name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid administrator email.';
    if (strlen($password) < 12) $errors[] = 'Administrator passwords must contain at least 12 characters.';
    if ($password !== $confirmation) $errors[] = 'Password confirmation does not match.';
    if (!$errors) {
        try {
            $statement = db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int) db()->lastInsertId();
            audit(db(), $id, 'create_first_admin', 'user', $id);
            flash('success', 'Administrator account created. You can now sign in.');
            redirect('login.php');
        } catch (PDOException $exception) {
            $errors[] = $exception->getCode() === '23000' ? 'That email address is already registered.' : 'Administrator creation failed.';
        }
    }
}

require dirname(__DIR__, 2) . '/frontend/views/setup-admin.php';
