<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

$user = require_login();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));

        if (strlen($name) < 2 || strlen($name) > 120) $errors[] = 'Enter a valid name (2–120 characters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';

        // Check email uniqueness if changed
        if (!$errors && $email !== $user['email']) {
            $check = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $check->execute([$email, $user['id']]);
            if ($check->fetch()) $errors[] = 'That email address is already in use.';
        }

        if (!$errors) {
            $stmt = db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
            $stmt->execute([$name, $email, $user['id']]);
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            $user['name'] = $name;
            $user['email'] = $email;
            flash('success', 'Profile updated successfully.');
            redirect('profile.php');
        }
    } elseif ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        // Fetch current password hash
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($newPassword) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($newPassword !== $confirmPassword) $errors[] = 'New passwords do not match.';

        if (!$errors) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $user['id']]);
            flash('success', 'Password changed successfully.');
            redirect('profile.php');
        }
    } elseif ($action === 'upload_picture') {
        $file = $_FILES['profile_picture'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please select an image to upload.';
        } elseif ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'The image could not be uploaded.';
        } else {
            $maxSize = 2 * 1024 * 1024; // 2 MB
            if ((int) $file['size'] > $maxSize) {
                $errors[] = 'Profile picture must be 2 MB or smaller.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowedMimes[$mime])) {
                    $errors[] = 'Only JPG, PNG, and WebP images are accepted.';
                } else {
                    $ext = $allowedMimes[$mime];
                    $filename = 'profile_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $uploadDir = dirname(__DIR__, 2) . '/storage/uploads/profiles';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    // Delete old profile picture if exists
                    $old = db()->prepare('SELECT profile_picture FROM users WHERE id = ?');
                    $old->execute([$user['id']]);
                    $oldFile = $old->fetchColumn();
                    if ($oldFile && file_exists($uploadDir . '/' . $oldFile)) {
                        unlink($uploadDir . '/' . $oldFile);
                    }

                    if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
                        $stmt = db()->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
                        $stmt->execute([$filename, $user['id']]);
                        $_SESSION['user']['profile_picture'] = $filename;
                        $user['profile_picture'] = $filename;
                        flash('success', 'Profile picture updated.');
                        redirect('profile.php');
                    } else {
                        $errors[] = 'Failed to save the image. Please try again.';
                    }
                }
            }
        }
    } elseif ($action === 'remove_picture') {
        $uploadDir = dirname(__DIR__, 2) . '/storage/uploads/profiles';
        $old = db()->prepare('SELECT profile_picture FROM users WHERE id = ?');
        $old->execute([$user['id']]);
        $oldFile = $old->fetchColumn();
        if ($oldFile && file_exists($uploadDir . '/' . $oldFile)) {
            unlink($uploadDir . '/' . $oldFile);
        }
        $stmt = db()->prepare('UPDATE users SET profile_picture = NULL WHERE id = ?');
        $stmt->execute([$user['id']]);
        $_SESSION['user']['profile_picture'] = null;
        $user['profile_picture'] = null;
        flash('success', 'Profile picture removed.');
        redirect('profile.php');
    }
}

// Re-fetch fresh user data
$stmt = db()->prepare('SELECT id, name, email, role, profile_picture FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user = $stmt->fetch() ?: $user;

require dirname(__DIR__, 2) . '/frontend/views/profile.php';
