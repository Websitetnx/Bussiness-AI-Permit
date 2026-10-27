<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) { http_response_code(404); exit; }
render_app_header('Payment Details','payments');
?>
<h2><?= e($payment['business_name']) ?> — <?= e($payment['application_reference']) ?></h2>
<article class="panel ops-panel"><h3><?= e($payment['payment_frequency']) ?> assessment</h3><p>Annual assessment: PHP <?= e($paymentBalance['total']) ?> · Verified: PHP <?= e($paymentBalance['paid']) ?> · Remaining: PHP <?= e($paymentBalance['remaining']) ?> · Overdue: PHP <?= e($paymentBalance['overdue']) ?></p><p>Payment status: <?= e($paymentBalance['status']) ?>. Payment verification does not change the permit term or automatically release or revoke a permit.</p></article>
<?php foreach($installments as $installment): ?>
<article class="panel ops-panel"><h3><?= e($installment['label']) ?> — PHP <?= e($installment['amount']) ?></h3><p>Due <?= e($installment['due_date']) ?> · <?= e($installment['status']) ?><?= $installment['required_before_release']?' · Required before release':'' ?></p>
<?php if($installment['status']!=='Paid' && $installment['due_date']<date('Y-m-d')): ?><p class="form-alert form-alert-error">Overdue installment — staff review required. Permit status is unchanged.</p><?php endif; ?>
<p>Method: <?= e($installment['payment_method']??'Not submitted') ?> · Reference: <?= e($installment['payment_reference']??'Not submitted') ?></p>
<?php if($installment['proof_stored_name']): ?><a class="button button-secondary" href="<?= e(url('payment-proof.php?installment_id='.$installment['id'])) ?>" target="_blank" rel="noopener">View evidence</a><?php endif; ?>
<?php if($installment['status']==='Paid'): ?><a class="button" href="<?= e(url('receipt.php?installment_id='.$installment['id'])) ?>">Receipt <?= e($installment['receipt_number']) ?></a>
<?php elseif($installment['status']==='Pending' && $installment['submitted_at']): ?>
<form method="post" action="payment-action.php" class="form-grid">
<?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"><input type="hidden" name="installment_id" value="<?= (int)$installment['id'] ?>"><input type="hidden" name="submission_version" value="<?= (int)$installment['submission_version'] ?>">
<label class="field field-wide">Verification note / rejection reason<textarea name="admin_notes" maxlength="4000"></textarea></label>
<button class="button" name="action" value="verify">Verify installment and issue receipt</button><button class="button button-danger" name="action" value="reject">Reject evidence</button>
</form><?php endif; ?>
<details><summary>Payment evidence and decision history</summary><?php foreach($evidenceHistory[$installment['id']] as $evidence): ?><p>Attempt <?= (int)$evidence['attempt_number'] ?> · <?= e($evidence['submitted_at']) ?> · <?= e($evidence['payment_reference']) ?> · <?= e($evidence['status']) ?> · <?= e($evidence['admin_notes']??'') ?> <?php if($evidence['proof_stored_name']): ?><a href="<?= e(url('payment-proof.php?evidence_id='.$evidence['id'])) ?>" target="_blank" rel="noopener">Original evidence</a><?php endif; ?></p><?php endforeach; ?></details>
</article><?php endforeach; ?>
<?php render_app_footer(); ?>
