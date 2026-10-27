<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Verify reset code | PERMIT</title><link rel="stylesheet" href="frontend/assets/styles.css?v=20260903-certificate1"><link rel="icon" type="image/png" href="frontend/assets/logo-light.png"></head>
<body class="auth-page simple-auth">
  <main class="auth-card setup-card">
    <a class="auth-logo auth-logo-image" href="login.php"><img src="frontend/assets/logo-light.png" alt="PERMIT — Web-Based Business Permit Management System"></a>
    <div><p class="eyebrow">Password verification</p><h1>Enter your reset code</h1><p class="muted">If the account is eligible, the 6-digit code sent to <strong><?= e(mask_email($email)) ?></strong> is valid for <?= $otpLifetimeMinutes ?> minutes.</p></div>
    <?php foreach ($notices as $message): ?><div class="form-alert <?= $message['type'] === 'error' ? 'form-alert-error' : '' ?>"><?= e($message['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label class="field">Verification code<input name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000"></label>
      <button class="button" type="submit" name="action" value="verify">Verify code</button>
      <button class="button button-secondary" type="submit" name="action" value="resend" formnovalidate>Resend code</button>
    </form>
    <p class="auth-switch"><a href="forgot-password.php">Use a different email</a></p>
  </main>
  <script src="frontend/assets/app.js?v=20260903-certificate1"></script>
</body>
</html>
