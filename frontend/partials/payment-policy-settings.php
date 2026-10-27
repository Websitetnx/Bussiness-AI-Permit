<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) { http_response_code(404); exit; }
?>
<article class="panel ops-panel">
<h3>Permit validity and renewal installment policy</h3>
<?php if (!$policyReady): ?><p>Import database/migrations/009_permit_terms_installments.sql to configure these features.</p>
<?php else: ?>
<p>Enter only rules approved by your LGU. Changes apply to future assessments, not existing installment plans or released permit dates. Quarterly payments never change permit validity.</p>
<?php if ($policyError): ?><div class="form-alert form-alert-error"><?= e($policyError) ?></div><?php endif; ?>
<form method="post" class="form-grid">
<?= csrf_field() ?><input type="hidden" name="settings_section" value="permit_policy">
<label class="field field-wide">Approved policy / ordinance reference<input name="policy_reference" required maxlength="255" value="<?= e($paymentPolicy['policy_reference']) ?>"></label>
<label class="field">Permit-term rule<select name="validity_mode"><option value="one_year" <?= $paymentPolicy['validity_mode']==='one_year'?'selected':'' ?>>One year, inclusive (BPLO confirms dates)</option><option value="bplo_dates" <?= $paymentPolicy['validity_mode']==='bplo_dates'?'selected':'' ?>>Explicit BPLO dates under approved policy</option></select></label>
<label class="declaration"><input type="checkbox" name="quarterly_enabled" <?= $paymentPolicy['quarterly_enabled']?'checked':'' ?>> Enable quarterly renewal payments</label>
<fieldset class="field field-wide"><legend>Fees eligible for quarterly installments</legend><p>Unchecked fees are collected separately, upfront. Eligibility does not alter the annual assessment.</p>
<?php foreach (quarterly_fee_options() as $key=>$label): ?><label class="declaration"><input type="checkbox" name="quarterly_fee_keys[]" value="<?= e($key) ?>" <?= in_array($key,$paymentPolicy['quarterly_fee_keys'],true)?'checked':'' ?>><?= e($label) ?></label><?php endforeach; ?>
</fieldset>
<label class="field field-wide">Quarterly deadline calculation<select name="schedule_mode"><option value="offsets" <?= $paymentPolicy['schedule_mode']==='offsets'?'selected':'' ?>>Months after effective date + day of month</option><option value="calendar" <?= $paymentPolicy['schedule_mode']==='calendar'?'selected':'' ?>>Calendar month/day + year offset from effective date</option></select></label>
<p class="field-wide">Complete the selected rule for all four quarters. Day 0 uses the effective day, clamped to the last day of shorter months. Calendar deadlines use MM-DD and year offset 0 for the effective year or 1 for the next year. No installment dates are active until quarterly payments are enabled and saved.</p>
<?php for($i=0;$i<4;$i++): $entry=$paymentPolicy['installment_schedule'][$i]??[]; ?>
<fieldset class="field field-wide form-grid"><legend>Quarter <?= $i+1 ?></legend>
<label class="field">Months after effective date<input type="number" min="0" max="24" name="months_<?= $i ?>" value="<?= e($entry['months']??'') ?>"></label>
<label class="field">Day of month (0 = effective day)<input type="number" min="0" max="31" name="day_<?= $i ?>" value="<?= e($entry['day']??0) ?>"></label>
<label class="field">Calendar month/day<input name="month_day_<?= $i ?>" placeholder="MM-DD" value="<?= e($entry['month_day']??'') ?>"></label>
<label class="field">Calendar year offset<input type="number" min="0" max="2" name="year_offset_<?= $i ?>" value="<?= e($entry['year_offset']??0) ?>"></label>
</fieldset><?php endfor; ?>
<?php foreach(['annual_due_days'=>'Annual deadline: days relative to effective date','upfront_due_days'=>'Upfront fees deadline: days relative to effective date','payment_reminder_days'=>'Upcoming payment reminder: days before due date','expiration_reminder_days'=>'Expiration reminder: days before expiration'] as $key=>$label): ?><label class="field"><?= e($label) ?><input type="number" name="<?= e($key) ?>" required min="<?= str_contains($key,'due_days')?-366:0 ?>" max="<?= str_contains($key,'due_days')?366:365 ?>" value="<?= (int)$paymentPolicy[$key] ?>"></label><?php endforeach; ?>
<label class="declaration field-wide"><input type="checkbox" name="allow_installment_release" <?= $paymentPolicy['allow_installment_release']?'checked':'' ?>> LGU permits release before all four quarters are paid</label>
<label class="field">Quarters required before installment-based release<select name="release_installments"><?php for($n=1;$n<=4;$n++): ?><option value="<?= $n ?>" <?= (int)$paymentPolicy['release_installments']===$n?'selected':'' ?>><?= $n ?></option><?php endfor; ?></select></label>
<p class="field-wide">Upfront fees and every installment already due must always be verified before release. With installment-based release disabled, full payment is required.</p>
<button class="button" type="submit">Save permit and payment policy</button>
</form><?php endif; ?>
</article>
