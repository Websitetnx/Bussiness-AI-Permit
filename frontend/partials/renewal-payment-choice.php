<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) { http_response_code(404); exit; }
?>
<?php if ($installmentsReady): ?>
<article class="panel ops-panel">
<h3>Renewal payment frequency and assessment preview</h3>
<p>Enter gross sales and inspection selections above, choose a payment frequency and proposed effective date, then preview before selecting documents. BPLO confirms the permit dates and final schedule. Installment payments never extend or shorten the permit term.</p>
<div class="form-grid">
<label class="field">Payment frequency <select name="payment_frequency" required><option value="">Choose payment frequency</option><option value="Annual" <?= ($_POST['payment_frequency']??'')==='Annual'?'selected':'' ?>>Annual — full assessment in one payment</option><option value="Quarterly" <?= ($_POST['payment_frequency']??'')==='Quarterly'?'selected':'' ?> <?= !$paymentPolicy['quarterly_enabled']?'disabled':'' ?>>Quarterly — eligible fees in four installments</option></select></label>
<label class="field">Proposed permit effective date<input type="date" name="requested_effective_date" required value="<?= e($_POST['requested_effective_date']??'') ?>"><small>BPLO confirms this date under the LGU policy.</small></label>
</div>
<?php if (!$paymentPolicy['quarterly_enabled']): ?><p>Quarterly payment is unavailable until BPLO configures and enables its approved policy.</p><?php endif; ?>
<button class="button button-secondary" name="intent" value="preview" formnovalidate type="submit">Preview assessment and payment schedule</button>
<?php if ($quote): $plan=$quote['plan']; ?>
<input type="hidden" name="quote_token" value="<?= e($quoteToken) ?>">
<?php if (!empty($quote['fsic_penalty']['penalty_applied'])): $fsicPen = $quote['fsic_penalty']; ?>
<div class="form-alert form-alert-warning" style="border-left: 4px solid #dc2626; background: #fef2f2; margin: 1rem 0; padding: 12px 16px;">
  <strong style="color: #991b1b;">⚠️ Late Renewal Surcharge Applied (9% on Total Assessment)</strong>
  <p style="margin: 4px 0 0; color: #4b5563;">
    Your previous FSIC expired on <strong><?= e($fsicPen['fsic_expiry_date']) ?></strong> (<?= (int)$fsicPen['days_overdue'] ?> day(s) overdue).
    A 9% surcharge of <strong>₱<?= e(number_format((float)$fsicPen['penalty_amount'], 2)) ?></strong> is included in your total.
    (Base assessment: ₱<?= e(number_format((float)$fsicPen['base_total_amount'], 2)) ?> → Total with penalty: <strong>₱<?= e(number_format((float)$fsicPen['total_amount_due'], 2)) ?></strong>).
  </p>
</div>
<?php endif; ?>
<h4>Server-calculated assessment preview</h4>
<dl class="detail-list"><div><dt>Annual assessment</dt><dd>PHP <?= e($plan['annual_assessment']) ?></dd></div><div><dt>Quarterly-eligible amount</dt><dd>PHP <?= e($plan['eligible_amount']) ?></dd></div><div><dt>Separate upfront fees</dt><dd>PHP <?= e($plan['upfront_amount']) ?></dd></div><div><dt>Due now / required before release</dt><dd>PHP <?= e($plan['amount_due_now']) ?></dd></div><div><dt>Remaining after these amounts are verified</dt><dd>PHP <?= e($plan['remaining_after_due_now']) ?></dd></div></dl>
<div class="table-responsive"><table><thead><tr><th>Payment</th><th>Amount</th><th>Due date</th><th>Required before release</th></tr></thead><tbody><?php foreach($plan['installments'] as $row): ?><tr><td><?= e($row['label']) ?></td><td>PHP <?= e($row['amount']) ?></td><td><?= e($row['due_date']) ?></td><td><?= $row['required_before_release']?'Yes':'When due' ?></td></tr><?php endforeach; ?></tbody></table></div>
<details><summary>Annual fee breakdown and quarterly eligibility</summary><ul><?php foreach($plan['fees'] as $fee): ?><li <?= ($fee['key'] ?? '') === 'fsic_late_renewal_penalty' ? 'style="color: #b91c1c; font-weight: 600;"' : '' ?>><?= e($fee['label']) ?>: PHP <?= e(number_format((float)$fee['amount'],2)) ?> — <?= $fee['quarterly_eligible']?'Quarterly eligible':'Upfront under quarterly payment' ?></li><?php endforeach; ?></ul></details>
<p>Review complete. Select your documents below and submit within 30 minutes. Changing financial inputs or dates requires another preview. Final due dates follow the BPLO-confirmed effective date.</p>
<?php endif; ?>

</article>
<?php else: ?><p class="form-alert">Annual payment applies to the existing workflow. BPLO must install migration 009 to enable the payment-frequency preview and explicit permit terms.</p><?php endif; ?>
