<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Administrator setup | PERMIT</title><link rel="stylesheet" href="frontend/assets/styles.css?v=20260903-certificate1"><link rel="icon" type="image/png" href="frontend/assets/logo-light.png"></head>
<body class="auth-page simple-auth">
  <main class="auth-card setup-card">
    <a class="auth-logo auth-logo-image" href="login.php"><img src="frontend/assets/logo-light.png" alt="PERMIT — Web-Based Business Permit Management System"></a>
    <div><p class="eyebrow">One-time setup</p><h1>Create administrator account</h1></div>
    <?php if (!$databaseReady): ?>
      <div class="form-alert form-alert-error">The database is not ready. Import <strong>database/schema.sql</strong>, update <strong>config.php</strong>, then reload this page.</div>
    <?php elseif ($adminExists): ?>
      <div class="form-alert">An administrator already exists, so first-admin setup is locked.</div><a class="button" href="login.php">Return to sign in</a>
    <?php else: ?>
      <p class="muted">This page works only until the first administrator is created. Public registration always creates applicant accounts.</p>
      <?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <?php if (!$isLocal): ?><label class="field">Setup key<input type="password" name="setup_key" required><small class="muted">Set ADMIN_SETUP_KEY on the server before running setup.</small></label><?php endif; ?>
        <label class="field">Administrator name<input name="name" autocomplete="name" required maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label class="field">Administrator email<input type="email" name="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label class="field">Password<input type="password" name="password" autocomplete="new-password" minlength="12" required><small class="muted">Use at least 12 characters.</small></label>
        <label class="field">Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
        <button class="button" type="submit">Create administrator</button>
      </form>
    <?php endif; ?>
  </main>
  <script src="frontend/assets/app.js?v=20260903-certificate1"></script>
</body>
</html>
