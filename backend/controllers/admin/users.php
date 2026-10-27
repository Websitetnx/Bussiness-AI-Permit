<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
$user = require_role('admin');
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (strlen($name) < 2 || strlen($name) > 120) $errors[] = 'Enter the administrator name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (strlen($password) < 12) $errors[] = 'Administrator passwords must contain at least 12 characters.';
    if ($password !== $confirmation) $errors[] = 'Password confirmation does not match.';
    if (!$errors) {
        try {
            $insert = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $newId = (int) $pdo->lastInsertId();
            audit($pdo, (int) $user['id'], 'create_admin', 'user', $newId);
            flash('success', 'Administrator account created successfully.');
            redirect('admin/users.php');
        } catch (PDOException $exception) {
            $errors[] = $exception->getCode() === '23000' ? 'That email address is already registered.' : 'The administrator could not be created.';
        }
    }
}

$users = $pdo->query("SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at, COUNT(a.id) application_count FROM users u LEFT JOIN applications a ON a.user_id = u.id GROUP BY u.id ORDER BY FIELD(u.role, 'admin', 'applicant'), u.created_at DESC")->fetchAll();

require dirname(__DIR__, 3) . '/frontend/views/admin/users.php';
