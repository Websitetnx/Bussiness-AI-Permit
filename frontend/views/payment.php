<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Permit Payment', 'payments');
?>
<div class="section-heading"><div><p class="eyebrow"><?= e($payment['application_reference']) ?></p><h2>Permit Payment</h2><p class="muted"><?= e($payment['business_name']) ?></p></div><?php if ($payment['id']): ?><span class="status <?= e(payment_status_class($payment['status'])) ?>"><?= e($payment['status']) ?></span><?php endif; ?></div>
<?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$payment['id']): ?>
  <article class="panel empty-state"><h3>Fee assessment is not ready</h3><p>BPLO must enter the assessed permit amount before payment can be submitted.</p><a class="button button-secondary" href="application.php?id=<?= (int) $applicationId ?>">Back to application</a></article>
<?php else: ?>
  <div class="content-grid payment-layout">
    <article class="panel payment-form-panel"><div class="panel-header"><div><p class="eyebrow">Amount due</p><h3>₱<?= e(number_format((float) $payment['amount'], 2)) ?></h3></div></div>
      <?php if ($feeBreakdown): ?><div class="applicant-fee-breakdown"><div><strong>Official assessment breakdown</strong><small><?= e($feeBreakdown['lgu_name']) ?> · <?= e($feeBreakdown['tax_basis_label']) ?> ₱<?= e(number_format((float) $feeBreakdown['tax_basis'], 2)) ?></small></div><dl><?php foreach ($feeBreakdown['components'] as $component): ?><div <?= ($component['key'] ?? '') === 'fsic_late_renewal_penalty' ? 'style="color:#b91c1c;font-weight:600;background:#fef2f2;padding:4px 8px;border-radius:4px;"' : '' ?>><dt><?= e($component['label']) ?></dt><dd>₱<?= e(number_format((float) $component['amount'], 2)) ?></dd></div><?php endforeach; ?><div class="fee-total"><dt>Total amount due</dt><dd>₱<?= e(number_format((float) $feeBreakdown['total'], 2)) ?></dd></div></dl><p>Assessment is based on the LGU schedule saved by BPLO. Contact the BPLO or City Treasurer if any business information is incorrect.</p></div><?php endif; ?>

      <?php if ($payment['status'] === 'Paid'): ?>
        <div class="payment-success"><span>✓</span><div><h3>Payment verified</h3><p>Verified on <?= e(date('F j, Y g:i A', strtotime($payment['paid_at']))) ?>.</p><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px"><a class="button" href="receipt.php?id=<?= (int) $payment['id'] ?>">View payment receipt</a><?php if ($payment['application_status'] === 'Released'): ?><a class="button button-secondary" href="certificate.php?id=<?= (int) $applicationId ?>">View certificate →</a><?php endif; ?></div></div></div>
      <?php else: ?>
        <?php if ($payment['status'] === 'Failed'): ?><div class="form-alert form-alert-error"><strong>Payment needs correction:</strong> <?= e($payment['admin_notes'] ?: 'Submit valid payment details again.') ?></div><?php elseif ($payment['submitted_at'] && $payment['status'] === 'Pending'): ?><div class="form-alert"><strong>Verification pending:</strong> Your payment was submitted on <?= e(date('F j, Y g:i A', strtotime($payment['submitted_at']))) ?>. The City Treasurer or administrator will verify it shortly.</div><?php endif; ?>
        <?php if ($payment['application_status'] === 'Released' && ($payment['status'] ?? '') !== 'Paid'): ?>
          <div class="form-alert form-alert-error"><strong>Permit released without verified payment.</strong> Contact the BPLO for assistance.</div>
        <?php elseif ($payment['application_status'] !== 'Released'): ?>
        <?php if ($payment['submitted_at'] && $payment['status'] === 'Pending'): ?>
          <div class="detail-list detail-list-padded" style="margin-bottom:16px">
            <div><small>Payment method</small><strong><?= e($payment['payment_method']) ?></strong></div>
            <div><small>Payer name</small><strong><?= e($payment['payer_name']) ?></strong></div>
            <div><small>Reference</small><strong><?= e($payment['payment_reference']) ?></strong></div>
            <div><small>Submitted</small><strong><?= e(date('F j, Y g:i A', strtotime($payment['submitted_at']))) ?></strong></div>
          </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="payment-form"><?= csrf_field() ?><input type="hidden" name="application_id" value="<?= (int) $applicationId ?>">
          <div class="form-grid no-side-padding">
            <label class="field">Payment method
              <select name="payment_method" required>
                <option value="">Select method</option>
                <?php foreach ($methods as $option): ?>
                  <option value="<?= e($option) ?>" <?= ($_POST['payment_method'] ?? $payment['payment_method']) === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field">Payer name
              <input name="payer_name" required maxlength="120" value="<?= e($_POST['payer_name'] ?? $payment['payer_name'] ?? $user['name']) ?>">
            </label>
            <label class="field field-wide">Transaction or treasury reference
              <input name="payment_reference" required maxlength="100" value="<?= e($_POST['payment_reference'] ?? $payment['payment_reference'] ?? '') ?>" placeholder="Transaction ID or official treasury reference">
            </label>
            <label class="field field-wide">Payment confirmation
              <small>Required for GCash, Maya, and bank transfer</small>
              <input type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png">
              <span class="field-help">PDF, JPG, or PNG · Maximum 5 MB</span>
            </label>
          </div>
          <label class="declaration">
            <input type="checkbox" required>
            <span>I confirm that these payment details are accurate. Submitting payment details does not automatically release the permit.</span>
          </label>
          <button class="button" type="submit"><?= $payment['submitted_at'] ? 'Resubmit payment details' : 'Submit payment for verification' ?></button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </article>
    <aside class="panel payment-instructions"><div class="panel-header"><div><p class="eyebrow">Payment instructions</p><h3>Before submitting</h3></div></div><ol><li>Pay the exact assessed amount through an LGU-authorized channel.</li><li>Keep the transaction reference or treasury receipt.</li><li>Upload a clear confirmation for online payments.</li><li>Wait for City Treasurer or administrator verification.</li></ol><p class="hint">PERMIT records payment evidence but does not directly transfer funds. Use only payment channels officially announced by your LGU.</p></aside>
  </div>
<?php endif; ?>
<div class="form-actions"><a class="button button-secondary" href="application.php?id=<?= (int) $applicationId ?>">← Back to application</a></div>
<?php render_app_footer(); ?>
