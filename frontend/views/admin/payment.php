<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Payment Details', 'payments');
?>
<div class="section-heading">
  <div><p class="eyebrow">City Treasurer workflow</p><h2><?= e($payment['business_name']) ?></h2><p class="muted"><?= e($payment['application_reference']) ?> · <?= e($payment['applicant_name']) ?></p></div>
  <a class="button button-secondary" href="<?= e(url('admin/payments.php')) ?>">← Payment records</a>
</div>

<article class="panel admin-payment-panel">
  <div class="panel-header"><div><p class="eyebrow">Payment record</p><h3>Verification details</h3></div><span class="status <?= e(payment_status_class($payment['status'])) ?>"><?= e($payment['submitted_at'] ? $payment['status'] : 'Awaiting applicant') ?></span></div>
  <div class="admin-payment-grid">
    <div class="payment-summary">
      <dl>
        <div><dt>Assessed amount</dt><dd>₱<?= e(number_format((float) $payment['amount'], 2)) ?></dd></div>
        <div><dt>Payment method</dt><dd><?= e($payment['payment_method'] ?: 'Not submitted') ?></dd></div>
        <div><dt>Payer</dt><dd><?= e($payment['payer_name'] ?: '—') ?></dd></div>
        <div><dt>Payment reference</dt><dd><?= e($payment['payment_reference'] ?: '—') ?></dd></div>
        <div><dt>Submitted</dt><dd><?= $payment['submitted_at'] ? e(date('M j, Y g:i A', strtotime($payment['submitted_at']))) : 'Awaiting applicant' ?></dd></div>
        <div><dt>Receipt</dt><dd><?= e($payment['receipt_number'] ?: 'Not issued') ?></dd></div>
      </dl>
      <?php if ($feeBreakdown): ?>
        <div class="receipt-breakdown"><strong>Assessment breakdown</strong><dl><?php foreach ($feeBreakdown['components'] as $component): ?><div><dt><?= e($component['label']) ?></dt><dd>₱<?= e(number_format((float) $component['amount'], 2)) ?></dd></div><?php endforeach; ?></dl></div>
      <?php endif; ?>
      <?php if ($payment['proof_stored_name']): ?><a class="button button-secondary" href="<?= e(url('payment-proof.php?id=' . $payment['id'])) ?>" target="_blank" rel="noopener">View payment confirmation ↗</a><?php endif; ?>
      <?php if ($payment['status'] === 'Paid'): ?><a class="button" href="<?= e(url('receipt.php?id=' . $payment['id'])) ?>">View receipt</a><?php endif; ?>
      <?php if (can_manage_applications($user['role'] ?? '')): ?><a class="button button-secondary" href="<?= e(url('admin/review.php?id=' . $payment['application_id'])) ?>">Open application review</a><?php endif; ?>
    </div>

    <div class="payment-actions">
      <?php if ($payment['submitted_at'] && $payment['status'] === 'Pending'): ?>
        <form method="post" action="payment-action.php">
          <?= csrf_field() ?>
          <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
          <input type="hidden" name="action" value="verify">
          <label class="field">Verification note<textarea name="admin_notes" rows="3" placeholder="Treasury verification note"></textarea></label>
          <button class="button" type="submit">Verify payment and issue receipt</button>
        </form>
        <form method="post" action="payment-action.php" class="reject-payment-form">
          <?= csrf_field() ?>
          <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
          <input type="hidden" name="action" value="reject">
          <label class="field">Reason for rejection<textarea name="admin_notes" rows="3" required minlength="5" placeholder="Explain what must be corrected"></textarea></label>
          <button class="button button-danger" type="submit">Reject payment proof</button>
        </form>
      <?php elseif (!$payment['submitted_at']): ?>
        <div class="empty-state"><p>Waiting for the applicant to submit payment details.</p></div>
      <?php elseif ($payment['status'] === 'Failed'): ?>
        <div class="form-alert form-alert-error"><strong>Correction requested:</strong> <?= e($payment['admin_notes'] ?: 'The payment submission was rejected.') ?></div>
      <?php elseif ($payment['status'] === 'Paid'): ?>
        <div class="payment-success compact"><span>✓</span><div><strong>Payment verified</strong><p><?= e($payment['receipt_number']) ?><?= $payment['verified_by_name'] ? ' · ' . e($payment['verified_by_name']) : '' ?></p></div></div>
      <?php else: ?>
        <div class="empty-state"><p>This payment is <?= e(strtolower((string) $payment['status'])) ?> and has no available action.</p></div>
      <?php endif; ?>
    </div>
  </div>
</article>
<?php render_app_footer(); ?>
