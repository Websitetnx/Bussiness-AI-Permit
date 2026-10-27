<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) { http_response_code(404); exit; }
render_app_header('Permit Payment','payments');
?>
<div class="section-heading"><div><h2><?= e($payment['business_name']) ?></h2><p><?= e($payment['application_reference']) ?> · <?= e($payment['payment_frequency']) ?> payment</p></div><span class="status <?= e(payment_status_class($paymentBalance['status'])) ?>"><?= e($paymentBalance['status']) ?></span></div>
<?php foreach($errors as $error): ?><div class="form-alert form-alert-error"><?= e($error) ?></div><?php endforeach; ?>
<article class="panel ops-panel"><h3>Annual assessment and verified balance</h3><dl class="detail-list"><div><dt>Annual assessment</dt><dd>PHP <?= e($paymentBalance['total']) ?></dd></div><div><dt>Verified payments</dt><dd>PHP <?= e($paymentBalance['paid']) ?></dd></div><div><dt>Remaining balance</dt><dd>PHP <?= e($paymentBalance['remaining']) ?></dd></div><div><dt>Due now / before release</dt><dd>PHP <?= e($paymentBalance['due_now']) ?></dd></div><div><dt>Overdue for staff review</dt><dd>PHP <?= e($paymentBalance['overdue']) ?></dd></div></dl><p>Permit status: <?= e($payment['application_status']) ?>. Quarterly payment does not shorten or extend your BPLO-confirmed validity. Release depends on the approved LGU release rule and verified amounts.</p></article>
<?php foreach($installments as $installment): ?>
<article class="panel ops-panel" id="installment-<?= (int)$installment['id'] ?>">
<h3><?= e($installment['label']) ?> — PHP <?= e($installment['amount']) ?></h3><p>Due <?= e($installment['due_date']) ?> · <?= e($installment['status']) ?> <?= $installment['required_before_release']?'· Required before release':'' ?></p>
<?php if($installment['status']!=='Paid' && $installment['due_date']<date('Y-m-d')): ?><p class="form-alert form-alert-error">Overdue — contact the City Treasurer. The permit is not automatically revoked.</p><?php endif; ?>
<?php if($installment['proof_stored_name']): ?><a href="<?= e(url('payment-proof.php?installment_id='.$installment['id'])) ?>" target="_blank" rel="noopener">View current evidence</a><?php endif; ?>
<?php if($installment['status']==='Paid'): ?><p>Verified <?= e($installment['paid_at']) ?>. Receipt <?= e($installment['receipt_number']) ?></p><a class="button" href="<?= e(url('receipt.php?installment_id='.$installment['id'])) ?>">View this payment receipt</a>
<?php elseif($installment['status']==='Pending' && $installment['submitted_at']): ?><p>Evidence received <?= e($installment['submitted_at']) ?>; awaiting verification. It is not counted as paid yet.</p>
<?php elseif($installment['status']!=='Refunded'): ?>
<?php if($installment['admin_notes']): ?><p class="form-alert form-alert-error"><?= e($installment['admin_notes']) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="form-grid">
<?= csrf_field() ?><input type="hidden" name="application_id" value="<?= (int)$applicationId ?>"><input type="hidden" name="installment_id" value="<?= (int)$installment['id'] ?>"><input type="hidden" name="submission_token" value="<?= e($submissionTokens[$installment['id']]) ?>">
<label class="field">Payment method<select name="payment_method" required><?php foreach($methods as $method): ?><option><?= e($method) ?></option><?php endforeach; ?></select></label>
<label class="field">Payer name<input name="payer_name" required maxlength="120" value="<?= e($user['name']) ?>"></label>
<label class="field">Transaction / treasury reference<input name="payment_reference" required minlength="3" maxlength="100"><small>Use a distinct transaction reference for each installment.</small></label>
<label class="field">Payment evidence<input type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png"><small>Required for GCash, Maya, and bank transfer. Maximum 5 MB.</small></label>
<button class="button" type="submit">Submit evidence for <?= e($installment['label']) ?></button>
</form><?php endif; ?>
</article><?php endforeach; ?>
<?php render_app_footer(); ?>
