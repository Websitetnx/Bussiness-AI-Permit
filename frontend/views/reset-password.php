<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Choose new password | PERMIT</title><link rel="stylesheet" href="frontend/assets/styles.css?v=20260903-certificate1"><link rel="icon" type="image/png" href="frontend/assets/logo-light.png"></head>
<body class="auth-page simple-auth">
  <main class="auth-card setup-card">
    <a class="auth-logo auth-logo-image" href="login.php"><img src="frontend/assets/logo-light.png" alt="PERMIT — Web-Based Business Permit Management System"></a>
    <div><p class="eyebrow">Secure your account</p><h1>Choose a new password</h1><p class="muted">Set a new password for <strong><?= e(mask_email($email)) ?></strong>. This authorization expires in <?= $otpLifetimeMinutes ?> minutes.</p></div>
    <?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label class="field">New password<input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus><small class="muted">Use at least 8 characters.</small></label>
      <label class="field">Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></label>
      <button class="button" type="submit">Update password</button>
    </form>
    <p class="auth-switch"><a href="login.php">Cancel and return to sign in</a></p>
  </main>
  <script src="frontend/assets/app.js?v=20260903-certificate1"></script>
</body>
</html>
