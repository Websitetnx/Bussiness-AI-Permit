<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_guest();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    try {
        $statement = db()->prepare('SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
            login_user($user);
            audit(db(), (int) $user['id'], 'login', 'user', (int) $user['id']);
            redirect(role_home_path($user['role']));
        }
        $error = 'The email address or password is incorrect.';
    } catch (PDOException) {
        $error = 'Database connection failed. Import database/schema.sql and check config.php.';
    }
}
$flashes = pull_flashes();

require dirname(__DIR__, 2) . '/frontend/views/login.php';
