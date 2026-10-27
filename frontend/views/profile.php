<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

$title = 'My Profile';
$active = 'profile';
render_app_header($title, $active);

$profilePicUrl = null;
if (!empty($user['profile_picture'])) {
    $profilePicUrl = url('storage/uploads/profiles/' . $user['profile_picture']);
}
$initials = implode('', array_map(static fn(string $part): string => strtoupper($part[0]), array_slice(preg_split('/\s+/', trim($user['name'])) ?: [], 0, 2)));
?>

<div class="content-grid profile-layout">

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $err): ?>
        <p><?= e($err) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Profile Picture Section -->
  <article class="panel profile-picture-panel">
    <div class="panel-header"><div><p class="eyebrow">Profile</p><h3>Profile picture</h3></div></div>
    <div class="profile-picture-section">
      <div class="profile-picture-preview">
        <?php if ($profilePicUrl): ?>
          <img src="<?= e($profilePicUrl) ?>?t=<?= time() ?>" alt="Profile picture" class="profile-img-large">
        <?php else: ?>
          <div class="profile-initials-large"><?= e($initials ?: 'U') ?></div>
        <?php endif; ?>
      </div>
      <div class="profile-picture-actions">
        <form method="post" enctype="multipart/form-data" class="profile-upload-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="upload_picture">
          <label class="button button-secondary profile-file-label">
            📷 Choose image
            <input type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.webp" hidden id="profilePictureInput">
          </label>
          <button class="button" type="submit" id="uploadPictureBtn" disabled>Upload</button>
        </form>
        <?php if ($profilePicUrl): ?>
          <form method="post" class="profile-remove-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove_picture">
            <button class="button button-danger" type="submit" onclick="return confirm('Remove your profile picture?')">Remove picture</button>
          </form>
        <?php endif; ?>
        <p class="field-help">JPG, PNG, or WebP · Maximum 2 MB</p>
        <p class="profile-filename" id="profileFilename"></p>
      </div>
    </div>
  </article>

  <!-- Profile Info Section -->
  <article class="panel profile-info-panel">
    <div class="panel-header"><div><p class="eyebrow">Account</p><h3>Personal information</h3></div></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_profile">
      <div class="form-grid">
        <label class="field">Full name<input name="name" required value="<?= e($_POST['name'] ?? $user['name']) ?>" minlength="2" maxlength="120"></label>
        <label class="field">Email address<input name="email" type="email" required value="<?= e($_POST['email'] ?? $user['email']) ?>"></label>
        <div class="field">
          <span>Role</span>
          <input value="<?= e(ucfirst($user['role'])) ?>" disabled>
        </div>
        <div class="field">
          <span>Member since</span>
          <input value="<?= e($user['created_at'] ?? 'N/A') ?>" disabled>
        </div>
      </div>
      <div class="form-actions"><span></span><button class="button" type="submit">Save changes</button></div>
    </form>
  </article>

  <!-- Password Section -->
  <article class="panel profile-password-panel">
    <div class="panel-header"><div><p class="eyebrow">Security</p><h3>Change password</h3></div></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="form-grid">
        <label class="field field-wide">Current password<input name="current_password" type="password" required></label>
        <label class="field">New password<input name="new_password" type="password" required minlength="8"><span class="field-help">At least 8 characters.</span></label>
        <label class="field">Confirm new password<input name="confirm_password" type="password" required></label>
      </div>
      <div class="form-actions"><span></span><button class="button" type="submit">Change password</button></div>
    </form>
  </article>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var input = document.getElementById('profilePictureInput');
  var btn = document.getElementById('uploadPictureBtn');
  var label = document.getElementById('profileFilename');
  if (input && btn) {
    input.addEventListener('change', function() {
      if (input.files && input.files.length > 0) {
        btn.disabled = false;
        if (label) label.textContent = input.files[0].name;
      } else {
        btn.disabled = true;
        if (label) label.textContent = '';
      }
    });
  }
});
</script>

<?php render_app_footer(); ?>
