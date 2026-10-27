<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot password | PERMIT</title><link rel="stylesheet" href="frontend/assets/styles.css?v=20260903-certificate1"><link rel="icon" type="image/png" href="frontend/assets/logo-light.png"></head>
<body class="auth-page simple-auth">
  <main class="auth-card setup-card">
    <a class="auth-logo auth-logo-image" href="login.php"><img src="frontend/assets/logo-light.png" alt="PERMIT — Web-Based Business Permit Management System"></a>
    <div><p class="eyebrow">Applicant recovery</p><h1>Forgot your password?</h1><p class="muted">Enter your applicant email. If it matches an active account, we will send a one-time verification code.</p></div>
    <?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label class="field">Email address<input type="email" name="email" autocomplete="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com"></label>
      <button class="button" type="submit">Email reset code</button>
    </form>
    <p class="auth-switch"><a href="login.php">Return to sign in</a></p>
  </main>
  <script src="frontend/assets/app.js?v=20260903-certificate1"></script>
</body>
</html>
