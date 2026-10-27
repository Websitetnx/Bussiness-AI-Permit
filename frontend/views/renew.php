<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Renew Business Permit', 'renew');
?>
<div class="section-heading"><div><p class="eyebrow">Permit renewal</p><h2>Renew an existing permit</h2><p class="muted">Only approved or released permits connected to your account can be renewed.</p></div></div>
<?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="panel lookup-panel"><form method="get" class="lookup-form"><label class="field"><span>Business permit number</span><input name="permit_number" required value="<?= e($permitNumber) ?>" placeholder="BP-2026-12345"></label><button class="button" type="submit">Find permit</button></form></div>
<?php if ($permitNumber && !$record): ?><div class="panel result-card empty-state"><strong>No renewable permit found.</strong><p>Check the permit number or contact the BPLO Help Desk.</p></div><?php endif; ?>
<?php if ($record): ?>
<?php if (!empty($fsicWarning) && $fsicWarning['days_overdue'] > 0): ?>
  <div class="form-alert form-alert-warning" style="margin-bottom: 1.25rem; border-left: 4px solid #dc2626; background: #fef2f2; padding: 14px 18px;">
    <strong style="color: #991b1b;">⚠️ Notice: FSIC Expired (<?= (int) $fsicWarning['days_overdue'] ?> day(s) overdue)</strong>
    <p style="margin: 6px 0 0; color: #4b5563;">
      Fire Safety Inspection Certificate for this permit expired on <strong><?= e((string)$fsicWarning['fsic_expiry_date']) ?></strong>.
      <?php if (!empty($fsicWarning['penalty_applied'])): ?>
        Because renewal is submitted within 1–30 days after expiry, an automatic <strong>9% late renewal surcharge</strong> will be applied to your total assessment.
      <?php else: ?>
        Renewal is past 30 days of expiry. Please ensure BFP inspection compliance upon renewal.
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="renewal-application-form">
  <?= csrf_field() ?><input type="hidden" name="permit_number" value="<?= e($record['permit_number']) ?>">
  <article class="panel result-card"><div class="record-summary"><div><p class="eyebrow">Permit record found</p><h3><?= e($record['business_name']) ?></h3><p class="muted"><?= e($record['permit_number']) ?> · <?= e($record['address']) ?></p></div><span class="status approved">Active record</span></div>

    <div class="form-grid no-side-padding"><label class="field">Gross sales for previous year<input name="gross_sales" inputmode="decimal" required value="<?= e($_POST['gross_sales'] ?? '') ?>" placeholder="0.00"><span class="field-help">Used as the renewal LBT basis.</span></label><label class="field">Contact number<input name="contact" required value="<?= e($_POST['contact'] ?? $record['contact']) ?>"></label><label class="field">Email<input type="email" name="email" required value="<?= e($_POST['email'] ?? $record['email']) ?>"></label><label class="field field-wide">Business address<input name="address" required value="<?= e($_POST['address'] ?? $record['address']) ?>"></label>
      <div class="field field-wide geolocation-control"><span>Business location <small>Optional</small></span><input type="hidden" name="latitude" value="<?= e($_POST['latitude'] ?? $record['latitude'] ?? '') ?>"><input type="hidden" name="longitude" value="<?= e($_POST['longitude'] ?? $record['longitude'] ?? '') ?>"><input type="hidden" name="location_accuracy_m" value="<?= e($_POST['location_accuracy_m'] ?? $record['location_accuracy_m'] ?? '') ?>"><input type="hidden" name="location_updated" value="<?= e($_POST['location_updated'] ?? '0') ?>"><div><button class="button button-secondary geolocation-button" type="button" data-geolocate>⌖ Update current location</button><p class="location-status<?= ($record['latitude'] ?? null) !== null ? ' success' : '' ?>" data-location-status aria-live="polite"><?= ($record['latitude'] ?? null) !== null ? 'A saved location is on file. Capture again to update it.' : 'Your browser will ask permission. Manual address entry remains available.' ?></p></div></div>

    </div>
  </article>
  <?php require dirname(__DIR__) . '/partials/renewal-payment-choice.php'; ?>
  <article class="panel document-panel renewal-documents"><div class="panel-header"><div><p class="eyebrow">Renewal requirements</p><h3>Upload current documents</h3><p class="muted">Required documents and the occupancy alternative are validated again for this renewal.</p></div></div>
    <div class="ai-upload-disclosure"><strong>Automated document check</strong><p>Eligible files are sent to the configured OpenAI API to check document type and quality. Medical results are skipped unless sensitive scanning is explicitly enabled. AI findings are advisory, and your renewal continues normally if scanning is unavailable.</p></div>
    <?php foreach ([[true, 'Standard requirements'], [false, 'Conditional requirements']] as [$required, $heading]): ?><div class="requirements-group <?= $required ? '' : 'conditional-group' ?>"><div class="requirements-heading"><div><h4><?= e($heading) ?></h4></div></div><div class="upload-grid"><?php foreach ($definitions as $field => [$label, $isRequired, $tag]): if ($isRequired !== $required) continue; ?><label class="upload-card"><span class="requirement-tag <?= $isRequired ? 'required-tag' : '' ?>"><?= e($tag) ?></span><span class="upload-icon">⇧</span><strong><?= e($label) ?></strong><small>PDF, JPG, or PNG · Maximum 5 MB</small><input name="<?= e($field) ?>" type="file" accept=".pdf,.jpg,.jpeg,.png" <?= $isRequired ? 'required' : '' ?>><em>Choose file</em><small class="error"></small></label><?php endforeach; ?></div><?php if (!$required): ?><div class="conditional-note"><strong>Occupancy reminder:</strong> Upload either the Occupancy Permit or the Affidavit of Undertaking alternative.</div><?php endif; ?></div><?php endforeach; ?>
    <label class="declaration"><input name="declaration" type="checkbox" required><span>I certify that the renewal information and documents are accurate, and I understand that eligible files may undergo the automated document checks described above.</span></label>
  </article>
  <div class="form-actions"><span></span><button class="button" type="submit" name="intent" value="submit" <?= $installmentsReady && !$quote ? 'disabled' : '' ?>>Submit renewal</button></div>
</form>
<?php endif; ?>
<?php render_app_footer(); ?>
