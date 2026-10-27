<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('New Business Permit', 'apply');
?>
<div class="section-heading"><div><p class="eyebrow">New application</p><h2>Apply for a business permit</h2><p class="muted">Complete the business details and upload clear copies of the requirements.</p></div><span class="secure-badge">◆ Secure form</span></div>
<?php if ($errors): ?><div class="form-alert form-alert-error"><strong>Please correct the following:</strong><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<ol class="stepper" aria-label="Application progress"><li class="active" data-step-indicator="1"><span>1</span><div><strong>Business details</strong><small>Basic information</small></div></li><li data-step-indicator="2"><span>2</span><div><strong>Requirements</strong><small>Upload documents</small></div></li><li data-step-indicator="3"><span>3</span><div><strong>Review</strong><small>Confirm and submit</small></div></li></ol>

<form id="applicationForm" method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="form-step active" data-form-step="1">
    <div class="panel form-panel"><div class="panel-header"><div><p class="eyebrow">Step 1 of 3</p><h3>Business information</h3></div></div>
      <div class="form-grid">
        <label class="field field-wide">Registered business name<input name="business_name" autocomplete="organization" required maxlength="190" value="<?= e($_POST['business_name'] ?? '') ?>"></label>
        <label class="field">Business type<select name="business_type" required><option value="">Select type</option><?php foreach ($allowedBusinessTypes as $option): ?><option <?= ($_POST['business_type'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
        <label class="field">Organization type<select name="organization_type" required><option value="">Select organization</option><?php foreach ($allowedOrganizations as $option): ?><option <?= ($_POST['organization_type'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
        <label class="field field-wide">Declared capital investment <small>Used as the new-application LBT basis</small><input name="declared_capital" inputmode="decimal" required value="<?= e($_POST['declared_capital'] ?? '') ?>" placeholder="0.00"><span class="field-help">Enter the amount declared in your registration and supporting records.</span></label>
        <label class="field">TIN<input name="tin" required maxlength="15" value="<?= e($_POST['tin'] ?? '') ?>" placeholder="000-000-000-000"></label>
        <label class="field">Contact number<input name="contact" type="tel" required value="<?= e($_POST['contact'] ?? '') ?>" placeholder="09XX XXX XXXX"></label>
        <label class="field field-wide">Business address<input name="address" required value="<?= e($_POST['address'] ?? '') ?>" placeholder="Building, street, barangay, city"></label>
        <div class="field field-wide geolocation-control">
          <span>Business location <small>Optional</small></span>
          <input type="hidden" name="latitude" value="<?= e($_POST['latitude'] ?? '') ?>">
          <input type="hidden" name="longitude" value="<?= e($_POST['longitude'] ?? '') ?>">
          <input type="hidden" name="location_accuracy_m" value="<?= e($_POST['location_accuracy_m'] ?? '') ?>">
          <div><button class="button button-secondary geolocation-button" type="button" data-geolocate>⌖ Use current location</button><p class="location-status<?= $location['latitude'] !== null ? ' success' : '' ?>" data-location-status aria-live="polite"><?= $location['latitude'] !== null ? 'Location captured. You can capture it again if needed.' : 'Your browser will ask permission. Manual address entry remains available.' ?></p></div>
        </div>
        <label class="field field-wide">Contact email<input name="email" type="email" required value="<?= e($_POST['email'] ?? $user['email']) ?>"></label>

      </div>
    </div>
    <div class="form-actions"><span></span><button class="button" type="button" data-next-step="2">Continue to requirements →</button></div>
  </div>

  <div class="form-step" data-form-step="2">
    <div class="panel form-panel"><div class="panel-header"><div><p class="eyebrow">Step 2 of 3</p><h3>Upload permit requirements</h3><p class="muted">Four files are standard requirements. The remaining files apply based on the business or location.</p></div></div>
      <div class="ai-upload-disclosure"><strong>Automated document check</strong><p>Eligible files are sent to the configured OpenAI API to check document type and quality. Medical results are skipped unless sensitive scanning is explicitly enabled. AI findings are advisory, and your submission continues normally if scanning is unavailable.</p></div>
      <?php foreach ([[true, 'Standard requirements'], [false, 'Conditional requirements']] as [$required, $heading]): ?>
        <div class="requirements-group <?= $required ? '' : 'conditional-group' ?>">
          <div class="requirements-heading"><div><h4><?= e($heading) ?></h4><p><?= $required ? 'These documents are required to submit.' : 'Upload these only when they apply to the business.' ?></p></div></div>
          <div class="upload-grid">
            <?php foreach ($definitions as $field => [$label, $isRequired, $tag]): if ($isRequired !== $required) continue; ?>
              <label class="upload-card"><span class="requirement-tag <?= $isRequired ? 'required-tag' : '' ?>"><?= e($tag) ?></span><span class="upload-icon">⇧</span><strong><?= e($label) ?></strong><small>PDF, JPG, or PNG · Maximum 5 MB</small><input name="<?= e($field) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png" <?= $isRequired ? 'required' : '' ?>><em>Choose file</em><small class="error"></small></label>
            <?php endforeach; ?>
          </div>
          <?php if (!$required): ?><div class="conditional-note"><strong>Occupancy reminder:</strong> Upload the Occupancy Permit when available. Otherwise, upload the Affidavit of Undertaking in Absence of Occupancy.</div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-prev-step="1">← Back</button><button class="button" type="button" data-next-step="3">Review application →</button></div>
  </div>

  <div class="form-step" data-form-step="3">
    <div class="panel form-panel"><div class="panel-header"><div><p class="eyebrow">Step 3 of 3</p><h3>Confirm and submit</h3><p class="muted">Review the information and selected filenames before sending them to the BPLO.</p></div></div><dl class="review-grid" id="applicationReview"></dl><label class="declaration"><input name="declaration" type="checkbox" required <?= isset($_POST['declaration']) ? 'checked' : '' ?>><span>I certify that the information and documents are accurate, and I understand that eligible files may undergo the automated document checks described above.</span></label></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-prev-step="2">← Back</button><button class="button" type="submit">Submit application</button></div>
  </div>
</form>
<?php render_app_footer(); ?>
