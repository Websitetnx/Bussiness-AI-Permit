<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Permit Fee Settings', 'fee-settings');
?>
<div class="section-heading"><div><p class="eyebrow">Assessment configuration</p><h2>Permit fee schedule</h2><p class="muted">Enter only rates approved in your current local tax ordinance and official fee schedule.</p></div><span class="secure-badge">◆ Admin only</span></div>
<?php if (!$ready): ?><div class="form-alert form-alert-error">Import <strong>database/migrations/005_fee_assessment_formula.sql</strong> first.</div><?php endif; ?>
<?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($ready): ?>
<form method="post" class="fee-settings-form"><?= csrf_field() ?>
  <article class="panel fee-settings-panel"><div class="panel-header"><div><p class="eyebrow">LGU information</p><h3>General and regulatory fees</h3></div><span class="status <?= !empty($settings['is_configured']) ? 'approved' : 'revision' ?>"><?= !empty($settings['is_configured']) ? 'Configured' : 'Setup required' ?></span></div>
    <div class="form-grid"><label class="field field-wide">City or municipality<input name="lgu_name" required maxlength="190" value="<?= e($_POST['lgu_name'] ?? $settings['lgu_name']) ?>"></label><?php foreach ($fixedFields as $field => $label): ?><label class="field"><?= e($label) ?><input name="<?= e($field) ?>" inputmode="decimal" required value="<?= e($_POST[$field] ?? $settings[$field]) ?>"></label><?php endforeach; ?></div>
  </article>
  <article class="panel fee-settings-panel"><div class="panel-header"><div><p class="eyebrow">Business classification</p><h3>LBT and Mayor's Permit rates</h3><p class="muted">Percentages are applied to declared capital for new applications and prior-year gross sales for renewals.</p></div></div>
    <div class="fee-rate-table"><div class="fee-rate-header"><strong>Business type</strong><strong>New LBT %</strong><strong>Renewal LBT %</strong><strong>Mayor's permit</strong></div><?php foreach (business_type_options() as $type): $rate = $rates[$type] ?? []; ?><div class="fee-rate-row"><strong><?= e($type) ?></strong><input aria-label="<?= e($type) ?> new LBT rate" name="new_lbt_rate_percent[<?= e($type) ?>]" inputmode="decimal" required value="<?= e($_POST['new_lbt_rate_percent'][$type] ?? $rate['new_lbt_rate_percent'] ?? '0.0000') ?>"><input aria-label="<?= e($type) ?> renewal LBT rate" name="renewal_lbt_rate_percent[<?= e($type) ?>]" inputmode="decimal" required value="<?= e($_POST['renewal_lbt_rate_percent'][$type] ?? $rate['renewal_lbt_rate_percent'] ?? '0.0000') ?>"><input aria-label="<?= e($type) ?> mayor permit fee" name="mayors_permit_fee[<?= e($type) ?>]" inputmode="decimal" required value="<?= e($_POST['mayors_permit_fee'][$type] ?? $rate['mayors_permit_fee'] ?? '0.00') ?>"></div><?php endforeach; ?></div>
  </article>
  <div class="formula-notice"><strong>Formula used</strong><span>Total = LBT + Mayor's Permit + regulatory and applicable inspection fees + BFP fee + barangay clearance + community tax.</span><small>The BFP percentage is applied to the Mayor's Permit and regulatory-fee subtotal. Set fees collected separately to 0.00. BPLO must verify the final assessment before approval.</small></div>
  <div class="form-actions"><span></span><button class="button" type="submit">Save official fee schedule</button></div>
</form>
<?php endif; ?>
<?php require dirname(__DIR__, 2) . '/partials/payment-policy-settings.php'; ?>
<?php render_app_footer(); ?>
