<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Review Application', 'review');
?>
<p><a class="button button-secondary" href="<?= e(url('admin/assignments.php?application_id=' . $id)) ?>">Assign reviewer / deadline</a> <a class="button button-secondary" href="<?= e(url('document-history.php?application_id=' . $id)) ?>">Document history and notes</a></p>
<div class="section-heading"><div><p class="eyebrow"><?= e($application['reference']) ?></p><h2><?= e($application['business_name']) ?></h2><p class="muted"><?= e($application['owner_name']) ?> · <?= e($application['application_type']) ?> application</p></div><span class="status <?= e(status_class($application['status'])) ?>"><?= e($application['status']) ?></span></div>
<div class="auto-scan-banner"><span>AI</span><div><strong>Automatic document scanning</strong><small>Scans start when the applicant submits or re-uploads a file. <?= $autoScannedCount ?> document<?= $autoScannedCount === 1 ? '' : 's' ?> currently have automatic results. Administrators can also use AI scan or Scan again below. Review the findings and original files before deciding.</small></div></div>
<?php if (!openai_enabled()): ?><div class="form-alert form-alert-error">AI scanning is unavailable because the AI service is not configured. Contact the system administrator.</div><?php elseif ($autoScanCapacity['remaining'] === 0): ?><div class="form-alert form-alert-error">The daily AI scan limit of <?= (int) $autoScanCapacity['limit'] ?> has been reached. Automatic and manual scans share this allowance. Uploaded files remain saved. Contact the system administrator about scan capacity.</div><?php endif; ?>
<div class="prediction-card">
  <div class="prediction-header">
    <div class="prediction-title-group">
      <span class="prediction-badge">Predictive Analytics</span>
      <h4>Turnaround Time Estimate & Queue Load</h4>
    </div>
    <div class="prediction-accuracy-badge">
      <span class="accuracy-score"><?= e((string)$prediction['confidence_percentage']) ?>%</span>
      <small>Prediction Confidence</small>
    </div>
  </div>
  <div class="prediction-body-grid">
    <div class="prediction-metric">
      <small>Estimated Approval Window</small>
      <strong><?= $prediction['is_completed'] ? 'Completed' : e($prediction['estimated_range_days']) ?></strong>
      <span>Target: <?= e($prediction['estimated_approval_date']) ?></span>
    </div>
    <div class="prediction-metric">
      <small>Queue Backlog Position</small>
      <strong>#<?= e((string)$prediction['queue_position']) ?></strong>
      <span>of <?= e((string)$prediction['total_in_queue']) ?> pending in queue</span>
    </div>
    <div class="prediction-metric">
      <small>Document Completeness</small>
      <strong><?= e((string)$prediction['completeness']['completeness_percent']) ?>%</strong>
      <span><?= e((string)$prediction['completeness']['required_uploaded']) ?> of <?= e((string)$prediction['completeness']['total_required']) ?> key requirements uploaded</span>
    </div>
  </div>
</div>
<?php if ($errors): ?><div class="form-alert form-alert-error"><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$paymentWorkflowReady): ?><div class="form-alert form-alert-error">Import <strong>database/migrations/004_payment_workflow.sql</strong> to enable fee assessment, payment verification, and receipts.</div><?php endif; ?>
<?php if (!$feeFormulaInstalled): ?><div class="form-alert form-alert-error">Import <strong>database/migrations/005_fee_assessment_formula.sql</strong> to collect capital/inspection information and calculate permit fees.</div><?php elseif (!$feeAssessment): ?><div class="form-alert form-alert-error">The automatic assessment is not ready. <a href="fee-settings.php">Configure the official LGU fee schedule</a> and confirm that the application contains its tax basis.</div><?php endif; ?>
<div class="content-grid review-layout"><article class="panel"><div class="panel-header"><div><p class="eyebrow">Applicant record</p><h3>Business information</h3></div><a href="<?= e(url('application.php?id=' . $id)) ?>">Full timeline</a></div><div class="detail-list detail-list-padded"><div><small>Applicant</small><strong><?= e($application['owner_name']) ?></strong></div><div><small>TIN</small><strong><?= e($application['tin']) ?></strong></div><div><small>Business type</small><strong><?= e($application['business_type']) ?></strong></div><div><small>Organization</small><strong><?= e($application['organization_type']) ?></strong></div><div><small><?= $application['application_type'] === 'New' ? 'Declared capital' : 'Previous-year gross sales' ?></small><strong>₱<?= e(number_format((float) ($application['application_type'] === 'New' ? ($application['declared_capital'] ?? 0) : ($application['gross_sales'] ?? 0)), 2)) ?></strong></div><div><small>Additional inspections</small><strong><?= e(implode(', ', array_filter([!empty($application['requires_building_inspection']) ? 'Building' : null, !empty($application['requires_electrical_inspection']) ? 'Electrical' : null, !empty($application['requires_plumbing_inspection']) ? 'Plumbing' : null])) ?: 'None selected') ?></strong></div><div><small>Contact</small><strong><?= e($application['contact']) ?></strong></div><div><small>Email</small><strong><?= e($application['email']) ?></strong></div><div class="detail-wide"><small>Address</small><strong><?= e($application['address']) ?></strong></div><div class="detail-wide business-location"><small>Applicant-captured location</small><?php if ($application['latitude'] !== null && $application['longitude'] !== null): ?><strong><?= e(number_format((float) $application['latitude'], 7)) ?>, <?= e(number_format((float) $application['longitude'], 7)) ?></strong><span><?= $application['location_accuracy_m'] !== null ? 'Accuracy ±' . e(number_format((float) $application['location_accuracy_m'], 0)) . ' m · ' : '' ?><a href="<?= e(openstreetmap_url($application['latitude'], $application['longitude'])) ?>" target="_blank" rel="noopener">Verify on map ↗</a></span><?php else: ?><strong>Not provided</strong><span>Verify using the written address and submitted documents.</span><?php endif; ?></div></div></article>
<aside class="panel decision-panel">
  <div class="panel-header"><div><p class="eyebrow">LGU decision</p><h3>Review &amp; decision</h3></div></div>

  <?php if ($application['status'] === 'Released'): ?>
    <div class="certificate-admin-action">
      <div><strong>Business certificate issued</strong><small>Permit <?= e($application['permit_number']) ?></small></div>
      <a class="button button-secondary" href="<?= e(url('certificate.php?id=' . (int) $id)) ?>">View certificate</a>
    </div>
  <?php elseif ($application['status'] === 'Approved'): ?>
    <div class="form-alert" style="margin-bottom:16px">
      <strong>&#10003; Application approved.</strong>
      The applicant has been notified and can now submit permit payment.
      This decision panel will unlock &ldquo;Released&rdquo; once payment is verified by the Treasurer.
    </div>
  <?php endif; ?>

  <?php if ($feeAssessment): ?>
    <div class="assessment-preview">
      <div><span>Calculated amount due</span><strong>&#8369;<?= e(number_format((float) $feeAssessment['total'], 2)) ?></strong><small><?= e($feeAssessment['tax_basis_label']) ?> &middot; &#8369;<?= e(number_format((float) $feeAssessment['tax_basis'], 2)) ?></small></div>
      <details><summary>View fee breakdown</summary><dl>
        <?php foreach ($feeAssessment['components'] as $component): ?><div><dt><?= e($component['label']) ?></dt><dd>&#8369;<?= e(number_format((float) $component['amount'], 2)) ?></dd></div><?php endforeach; ?>
      </dl></details>
    </div>
  <?php endif; ?>

  <form method="post" class="decision-form" id="decisionForm">
    <?= csrf_field() ?>
    <input type="hidden" name="application_id" value="<?= (int) $id ?>">

    <label class="field">Decision
      <select name="decision" required id="decisionSelect">
        <?php foreach (['For Review', 'Needs Revision', 'Approved', 'Released', 'Rejected'] as $option):
          $isSelected  = $application['status'] === $option ? 'selected' : '';
          $isDisabled  = ($option === 'Released' && !$canRelease) ? 'disabled' : '';
          $suffix = '';
          if ($option === 'Approved') $suffix = ' — notifies applicant to pay';
          if ($option === 'Released') $suffix = !$canRelease ? ' (locked — required payments outstanding)' : ' — issues certificate';
        ?>
          <option value="<?= e($option) ?>" <?= $isSelected ?> <?= $isDisabled ?>><?= e($option . $suffix) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div id="feeField" <?= !in_array($application['status'], ['', 'For Review', 'Needs Revision', 'Approved'], true) ? 'hidden' : '' ?>>
      <label class="field">Assessed permit fee
        <small><?= $feeAssessment ? 'Auto-calculated from LGU schedule' : 'Required when approving' ?></small>
        <input name="assessed_amount" inputmode="decimal"
               value="<?= e((string) ($feeAssessment['total'] ?? $_POST['assessed_amount'] ?? $payment['amount'] ?? '')) ?>"
               placeholder="0.00" <?= $feeAssessment ? 'readonly' : '' ?>>
      </label>
    </div>

    <label class="field">BPLO notes
      <textarea name="admin_notes" rows="5" placeholder="Instructions, findings, or decision notes"><?= e($_POST['admin_notes'] ?? $application['admin_notes']) ?></textarea>
    </label>

    <?php if ($installmentsReady): ?>
    <fieldset class="field"><legend>BPLO-confirmed permit term</legend>
      <p>Policy: <?= e($paymentPolicy['policy_reference'] ?: 'Configure approved policy in Fee settings first') ?>. Payment frequency: <?= e($application['payment_frequency']) ?>. Installments do not change these dates.</p>
      <label class="field">Effective date<input type="date" name="permit_effective_date" value="<?= e($effectiveSuggestion) ?>"></label>
      <label class="field">Expiration date (inclusive)<input type="date" name="permit_expiration_date" value="<?= e($expirationSuggestion) ?>"></label>
      <small><?= $paymentPolicy['validity_mode']==='one_year'?'For a one-year term, expiration is the day before the following anniversary. Dates are locked once assessed.':'Confirm both dates according to the approved LGU policy.' ?></small>
      <label class="declaration"><input type="checkbox" name="confirm_permit_dates" <?= !empty($application['validity_confirmed_at'])?'checked':'' ?>> I confirm these dates under the LGU approved policy.</label>
    </fieldset>
    <?php endif; ?>


    <fieldset class="field"><legend>FSIC compliance details</legend>
      <p>Fire Safety Inspection Certificate (FSIC) validity dates. Late renewal within 1–30 days of expiry automatically assesses a 9% surcharge.</p>
      <label class="field">FSIC issue date<input type="date" name="fsic_issue_date" value="<?= e((string)($fsicIssueSuggestion ?? '')) ?>"></label>
      <label class="field">FSIC expiration date<input type="date" name="fsic_expiry_date" value="<?= e((string)($fsicExpirationSuggestion ?? '')) ?>"></label>
      <?php if (!empty($fsicCompliance) && !empty($fsicCompliance['penalty_amount']) && (float)$fsicCompliance['penalty_amount'] > 0): ?>
        <p class="form-alert form-alert-warning" style="margin-top: 8px;">
          ⚠️ <strong>Late renewal penalty applied:</strong> ₱<?= e(number_format((float) $fsicCompliance['penalty_amount'], 2)) ?> (9% surcharge, <?= (int)$fsicCompliance['days_overdue'] ?> day(s) overdue).
        </p>
      <?php endif; ?>
    </fieldset>


    <?php if (!$canRelease): ?>
      <p class="release-guard">&#128274; Release requires verified full annual payment, or every upfront, release-required, and already-due installment under an LGU-approved installment release rule.</p>
    <?php endif; ?>

    <button class="button" type="submit" id="decisionSubmit">Save decision</button>
  </form>

  <script src="<?= e(url('frontend/assets/admin-review.js')) ?>" defer></script>
</aside>
</div>
<?php if ($paymentBalance): ?>
<article class="panel ops-panel"><h3>Annual assessment and installment verification</h3><p>Assessment: PHP <?= e($paymentBalance['total']) ?> · Verified: PHP <?= e($paymentBalance['paid']) ?> · Remaining: PHP <?= e($paymentBalance['remaining']) ?> · Overdue: PHP <?= e($paymentBalance['overdue']) ?></p><p>Payment status: <?= e($paymentBalance['status']) ?>. Overdue installments require staff review; they do not automatically revoke the permit.</p><a class="button" href="<?= e(url('admin/payment.php?id='.$payment['id'])) ?>">Review individual installments and receipts</a></article>
<?php elseif ($paymentWorkflowReady && $payment): ?>
<article class="panel admin-payment-panel"><div class="panel-header"><div><p class="eyebrow">City Treasurer workflow</p><h3>Payment verification</h3></div><span class="status <?= e(payment_status_class($payment['status'])) ?>"><?= e($payment['status']) ?></span></div><div class="admin-payment-grid"><div class="payment-summary"><dl><div><dt>Assessed amount</dt><dd>₱<?= e(number_format((float) $payment['amount'], 2)) ?></dd></div><div><dt>Method</dt><dd><?= e($payment['payment_method'] ?: 'Not submitted') ?></dd></div><div><dt>Payer</dt><dd><?= e($payment['payer_name'] ?: '—') ?></dd></div><div><dt>Reference</dt><dd><?= e($payment['payment_reference'] ?: '—') ?></dd></div><div><dt>Submitted</dt><dd><?= $payment['submitted_at'] ? e(date('M j, Y g:i A', strtotime($payment['submitted_at']))) : 'Awaiting applicant' ?></dd></div></dl><?php if ($payment['proof_stored_name']): ?><a class="button button-secondary" href="<?= e(url('payment-proof.php?id=' . $payment['id'])) ?>" target="_blank" rel="noopener">View payment confirmation ↗</a><?php endif; ?><?php if ($payment['status'] === 'Paid'): ?><a class="button" href="<?= e(url('receipt.php?id=' . $payment['id'])) ?>">View receipt</a><?php endif; ?></div>
  <div class="payment-actions"><?php if ($payment['submitted_at'] && $payment['status'] === 'Pending'): ?><form method="post" action="payment-action.php"><?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><input type="hidden" name="action" value="verify"><label class="field">Verification note<textarea name="admin_notes" rows="3" placeholder="Treasury verification note"></textarea></label><button class="button" type="submit">Verify payment and issue receipt</button></form><form method="post" action="payment-action.php" class="reject-payment-form"><?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><input type="hidden" name="action" value="reject"><label class="field">Reason for rejection<textarea name="admin_notes" rows="3" required placeholder="Explain what must be corrected"></textarea></label><button class="button button-danger" type="submit">Reject payment proof</button></form><?php elseif (!$payment['submitted_at']): ?><div class="empty-state"><p>Waiting for the applicant to submit payment details.</p></div><?php elseif ($payment['status'] === 'Failed'): ?><div class="form-alert form-alert-error"><strong>Correction requested:</strong> <?= e($payment['admin_notes']) ?></div><?php else: ?><div class="payment-success compact"><span>✓</span><div><strong>Payment verified</strong><p><?= e($payment['receipt_number']) ?></p></div></div><?php endif; ?></div></div></article>
<?php endif; ?>
<article class="panel document-panel ai-document-panel">
  <div class="panel-header"><div><p class="eyebrow">AI-assisted verification</p><h3>Submitted requirements</h3><p class="muted">AI findings are advisory. Open and verify every original document before making a decision.</p></div><span class="ai-provider">OpenAI · <?= e(openai_settings()['model']) ?></span></div>
  <div class="ai-privacy-note"><strong>Privacy notice:</strong> Scanning sends the selected document to the configured OpenAI API project with response storage disabled. Confirm that your LGU is authorized to process it. Medical-result scanning is blocked unless explicitly enabled by the server administrator.</div>
  <?php if (!$aiTablesReady): ?><div class="form-alert form-alert-error ai-migration-alert">Import <strong>database/migrations/002_ai_features.sql</strong> to enable AI scanning.</div><?php endif; ?>
  <div class="ai-document-list">
    <?php foreach ($documentRows as $document):
      $label = $definitions[$document['document_type']][0] ?? $document['document_type'];
      $issues = isset($document['issues']) ? (json_decode((string) $document['issues'], true) ?: []) : [];
      $fields = isset($document['extracted_fields']) ? (json_decode((string) $document['extracted_fields'], true) ?: []) : [];
      $sensitiveBlocked = $document['document_type'] === 'health_results_doc' && !openai_settings()['allow_sensitive_documents'];
    ?>
      <section class="ai-document-row">
        <div class="ai-document-main">
          <a class="ai-document-link" href="<?= e(url('document.php?id=' . $document['id'])) ?>" target="_blank" rel="noopener"><span class="document-icon">▧</span><span><strong><?= e($label) ?></strong><small><?= e($document['original_name']) ?> · <?= e(number_format((int) $document['file_size'] / 1024, 1)) ?> KB</small></span></a>
          <form method="post" action="scan-document.php">
            <?= csrf_field() ?>
            <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
            <button class="button button-secondary ai-scan-button" type="submit" <?= (!$aiTablesReady || $sensitiveBlocked) ? 'disabled' : '' ?>><?= $sensitiveBlocked ? 'Sensitive file' : (($document['scan_status'] ?? '') === 'Completed' ? 'Scan again' : 'AI scan') ?></button>
          </form>
        </div>
        <?php if (($document['scan_status'] ?? '') === 'Completed'): ?>
          <div class="ai-scan-result">
            <div class="ai-score-row"><span class="ai-chip <?= (int) $document['matches_expected_type'] === 1 ? 'good' : 'warning' ?>"><?= (int) $document['matches_expected_type'] === 1 ? 'Type matched' : 'Check type' ?></span><span class="ai-chip">Quality <?= (int) $document['quality_score'] ?>%</span><span class="ai-chip">Confidence <?= (int) $document['confidence_score'] ?>%</span><?php if ((int) $document['requires_human_review'] === 1): ?><span class="ai-chip review">Human review required</span><?php endif; ?></div>
            <p><strong>Detected:</strong> <?= e($document['detected_document_type']) ?></p><p><?= e($document['summary']) ?></p>
            <?php if ($issues): ?><div class="ai-findings"><strong>Items to check</strong><ul><?php foreach ($issues as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($fields): ?><details><summary>Extracted fields</summary><div class="ai-field-grid"><?php foreach ($fields as $field): ?><div><small><?= e($field['field'] ?? 'Field') ?></small><strong><?= e($field['value'] ?? '') ?></strong><em><?= (int) ($field['confidence'] ?? 0) ?>% confidence</em></div><?php endforeach; ?></div></details><?php endif; ?>
            <small class="ai-timestamp">Scanned <?= e(date('M j, Y g:i A', strtotime($document['scanned_at']))) ?> · <?= e($document['model']) ?></small>
          </div>
        <?php elseif (($document['scan_status'] ?? '') === 'Failed'): ?>
          <div class="ai-scan-error"><strong>Scan unavailable.</strong> <?= e($document['error_message']) ?></div>
        <?php elseif (!$sensitiveBlocked): ?>
          <div class="ai-scan-error">No scan result is available for this file. Uploads and replacements trigger automatic scanning; an administrator can also use AI scan above, subject to service availability and the daily limit.</div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</article>
<div class="form-actions"><a class="button button-secondary" href="index.php#queue">← Back to review queue</a></div>
<?php render_app_footer(); ?>
