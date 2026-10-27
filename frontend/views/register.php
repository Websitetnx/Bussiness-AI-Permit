<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Create account | PERMIT</title><link rel="stylesheet" href="frontend/assets/styles.css?v=20260903-certificate1"><link rel="icon" type="image/png" href="frontend/assets/logo-light.png"></head>
<body class="auth-page">
  <main class="auth-shell">
    <section class="auth-visual"><a class="brand brand-logo-link auth-brand" href="index.php"><img class="brand-logo" src="frontend/assets/logo-light.png" alt="PERMIT — Web-Based Business Permit Management System"></a><div><p class="eyebrow light">Applicant registration</p><h1>Create one secure permit account.</h1><p>Your applications, renewals, documents, and status history remain connected to your account.</p></div><ul class="auth-points"><li>✓ Apply for a new permit</li><li>✓ Renew an approved permit</li><li>✓ Track LGU review updates</li></ul></section>
    <section class="auth-card">
      <div><p class="eyebrow">Get started</p><h2>Create applicant account</h2><p class="muted">We will email you a one-time code before creating your account.</p></div>
      <?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <label class="field">Complete name<input name="name" autocomplete="name" required maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label class="field">Email address<input type="email" name="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label class="field">Password<input type="password" name="password" autocomplete="new-password" minlength="8" required><small class="muted">Use at least 8 characters.</small></label>
        <label class="field">Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></label>
        <button class="button" type="submit">Email verification code</button>
      </form>
      <p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p>
    </section>
  </main>
  <script src="frontend/assets/app.js?v=20260903-certificate1"></script>
</body>
</html>
