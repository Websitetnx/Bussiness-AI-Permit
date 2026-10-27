<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Application Details', $canManageApplications ? 'review' : 'track');
?>
<p><a class="button button-secondary" href="<?= e(url('document-history.php?application_id=' . $application['id'])) ?>">Document version history</a></p>
<div class="section-heading"><div><p class="eyebrow"><?= e($application['reference']) ?></p><h2><?= e($application['business_name']) ?></h2><p class="muted"><?= e($application['application_type']) ?> application · Submitted <?= e(date('F j, Y', strtotime($application['submitted_at']))) ?></p></div><span class="status <?= e(status_class($application['status'])) ?>"><?= e($application['status']) ?></span></div>
<?php if(!empty($application['approved_at'])): ?><article class="panel ops-panel"><h3>BPLO-confirmed permit validity</h3><p>Approved: <?= e(date('Y-m-d', strtotime($application['approved_at']))) ?><?php if(!empty($application['fsic_expiry_date'])): ?> · FSIC Expiry: <?= e($application['fsic_expiry_date']) ?><?php endif; ?></p></article><?php endif; ?>

<?php if(!empty($fsicCompliance)): ?>
<article class="panel ops-panel" style="<?= (!empty($fsicCompliance['penalty_amount']) && (float)$fsicCompliance['penalty_amount'] > 0) ? 'border-left: 4px solid #dc2626; background: #fffaf0;' : '' ?>">
  <h3>Fire Safety Inspection Certificate (FSIC) Compliance</h3>
  <p>
    FSIC issue date: <?= e($fsicCompliance['fsic_issue_date'] ?: 'Not recorded') ?> · Expiration: <?= e($fsicCompliance['fsic_expiry_date'] ?: 'Not recorded') ?> · Status: <strong><?= e(ucfirst((string)$fsicCompliance['fsic_status'])) ?></strong>
  </p>
  <?php if (!empty($fsicCompliance['penalty_amount']) && (float)$fsicCompliance['penalty_amount'] > 0): ?>
    <p style="color: #b91c1c; font-weight: 600; margin-top: 6px;">
      ⚠️ Late renewal surcharge: ₱<?= e(number_format((float) $fsicCompliance['penalty_amount'], 2)) ?> (9% on total assessment, <?= (int)$fsicCompliance['days_overdue'] ?> day(s) overdue). Total amount due: ₱<?= e(number_format((float)$fsicCompliance['total_amount_due'], 2)) ?>.
    </p>
  <?php endif; ?>
</article>
<?php endif; ?>

<ol class="timeline panel timeline-panel">
  <?php foreach (['Submitted', 'Validation', 'Assessment & payment', 'Permit release'] as $stage => $label): $step = $stage + 1; ?>
    <li class="<?= $step < (int) $application['stage'] ? 'done' : ($step === (int) $application['stage'] ? 'current' : '') ?>"><span><?= $step <= (int) $application['stage'] ? '✓' : $step ?></span><?= e($label) ?></li>
  <?php endforeach; ?>
</ol>
<?php if ($application['admin_notes']): ?><div class="form-alert <?= $application['status'] === 'Needs Revision' ? 'form-alert-error' : '' ?>"><strong>BPLO note:</strong> <?= nl2br(e($application['admin_notes'])) ?></div><?php endif; ?>

<div class="prediction-card">
  <div class="prediction-header">
    <div class="prediction-title-group">
      <span class="prediction-badge">Predictive Analytics</span>
      <h4>Estimated Processing Timeline</h4>
    </div>
    <div class="prediction-accuracy-badge">
      <span class="accuracy-score"><?= e((string)$prediction['confidence_percentage']) ?>%</span>
      <small>Confidence Score</small>
    </div>
  </div>
  <div class="prediction-body-grid">
    <div class="prediction-metric">
      <small>Estimated Approval Period</small>
      <strong><?= $prediction['is_completed'] ? 'Completed' : e($prediction['estimated_range_days']) ?></strong>
      <span>Target: <?= e($prediction['estimated_approval_date']) ?></span>
    </div>
    <div class="prediction-metric">
      <small>Current Queue Position</small>
      <strong>#<?= e((string)$prediction['queue_position']) ?></strong>
      <span>of <?= e((string)$prediction['total_in_queue']) ?> active submissions</span>
    </div>
    <div class="prediction-metric">
      <small>Requirements Status</small>
      <strong><?= e((string)$prediction['completeness']['completeness_percent']) ?>% Complete</strong>
      <span><?= e((string)$prediction['completeness']['total_uploaded']) ?> uploaded documents</span>
    </div>
  </div>
</div>

<div class="content-grid application-detail-grid">
  <article class="panel"><div class="panel-header"><div><p class="eyebrow">Business record</p><h3>Application information</h3></div></div><div class="detail-list detail-list-padded">
    <div><small>Applicant</small><strong><?= e($application['owner_name']) ?></strong></div><div><small>Application type</small><strong><?= e($application['application_type']) ?></strong></div>
    <div><small>Business type</small><strong><?= e($application['business_type']) ?></strong></div><div><small>Organization</small><strong><?= e($application['organization_type']) ?></strong></div>
    <div><small><?= $application['application_type'] === 'New' ? 'Declared capital' : 'Previous-year gross sales' ?></small><strong>₱<?= e(number_format((float) ($application['application_type'] === 'New' ? ($application['declared_capital'] ?? 0) : ($application['gross_sales'] ?? 0)), 2)) ?></strong></div><div><small>Additional inspections</small><strong><?= e(implode(', ', array_filter([!empty($application['requires_building_inspection']) ? 'Building' : null, !empty($application['requires_electrical_inspection']) ? 'Electrical' : null, !empty($application['requires_plumbing_inspection']) ? 'Plumbing' : null])) ?: 'None selected') ?></strong></div>
    <div><small>TIN</small><strong><?= e($application['tin']) ?></strong></div><div><small>Permit number</small><strong><?= e($application['permit_number'] ?: 'Assigned after approval') ?></strong></div>
    <div><small>Contact</small><strong><?= e($application['contact']) ?></strong></div><div><small>Email</small><strong><?= e($application['email']) ?></strong></div>
    <div class="detail-wide"><small>Address</small><strong><?= e($application['address']) ?></strong></div>
    <div class="detail-wide business-location"><small>Captured location</small><?php if ($application['latitude'] !== null && $application['longitude'] !== null): ?><strong><?= e(number_format((float) $application['latitude'], 7)) ?>, <?= e(number_format((float) $application['longitude'], 7)) ?></strong><span><?= $application['location_accuracy_m'] !== null ? 'Accuracy ±' . e(number_format((float) $application['location_accuracy_m'], 0)) . ' m · ' : '' ?><a href="<?= e(openstreetmap_url($application['latitude'], $application['longitude'])) ?>" target="_blank" rel="noopener">Open map ↗</a></span><?php else: ?><strong>Not provided</strong><span>The written business address remains the official address.</span><?php endif; ?></div>
  </div></article>
  <aside class="panel"><div class="panel-header"><div><p class="eyebrow">Status log</p><h3>Processing history</h3></div></div><div class="history-list">
    <?php foreach ($historyRows as $entry): ?><div><span class="history-dot"></span><p><strong><?= e($entry['status']) ?></strong><small><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?><?= $entry['changed_by_name'] ? ' · ' . e($entry['changed_by_name']) : '' ?></small><?php if ($entry['notes']): ?><em><?= e($entry['notes']) ?></em><?php endif; ?></p></div><?php endforeach; ?>
  </div></aside>
</div>

<?php if ($application['status'] === 'Released'): ?>
<article class="panel certificate-ready-card">
  <span class="certificate-ready-icon" aria-hidden="true">&#10003;</span>
  <div>
    <p class="eyebrow">Official document</p>
    <h3>Your business permit certificate is ready</h3>
    <p>Permit <?= e($application['permit_number']) ?> can now be viewed, printed, or saved as a PDF.</p>
  </div>
  <a class="button" href="<?= e(url('certificate.php?id=' . (int) $application['id'])) ?>">View certificate</a>
</article>
<?php endif; ?>

<?php if($paymentBalance): ?><article class="panel ops-panel"><h3><?= e($payment['payment_frequency']) ?> payment plan</h3><p>Annual assessment: PHP <?= e($paymentBalance['total']) ?> · Verified payments: PHP <?= e($paymentBalance['paid']) ?> · Remaining: PHP <?= e($paymentBalance['remaining']) ?></p><p>Due now / before release: PHP <?= e($paymentBalance['due_now']) ?> · Overdue: PHP <?= e($paymentBalance['overdue']) ?> · Payment status: <?= e($paymentBalance['status']) ?></p><p>Overdue installments require staff review and do not automatically revoke a permit.</p><a class="button" href="<?= e(url($canManageApplications?'admin/payment.php?id='.$payment['id']:'payment.php?application_id='.$application['id'])) ?>">View installments, payment evidence, and receipts</a></article><?php endif; ?>
<?php if (!$paymentBalance && in_array($application['status'], ['Approved', 'Released'], true)): ?>
<article class="panel applicant-payment-card"><div class="panel-header"><div><p class="eyebrow">Permit payment</p><h3><?= $payment ? 'Assessed fee: ₱' . e(number_format((float) $payment['amount'], 2)) : 'Awaiting fee assessment' ?></h3></div><?php if ($payment): ?><span class="status <?= e(payment_status_class($payment['status'])) ?>"><?= e($payment['status']) ?></span><?php endif; ?></div>
  <?php if (!$paymentWorkflowReady): ?><div class="form-alert form-alert-error">The payment workflow requires database/migrations/004_payment_workflow.sql.</div><?php elseif (!$payment): ?><div class="payment-card-body"><p>BPLO approved the application but has not entered the assessed amount yet.</p></div><?php elseif ($payment['status'] === 'Paid'): ?><div class="payment-card-body"><div><strong><?= $application['status'] === 'Released' ? 'Payment verified and permit released' : 'Payment verified' ?></strong><p><?= $application['status'] === 'Released' ? 'Receipt ' . e($payment['receipt_number']) . ' and your business permit certificate are ready.' : 'Receipt ' . e($payment['receipt_number']) . ' is ready. BPLO can now release the permit.' ?></p></div><a class="button" href="receipt.php?id=<?= (int) $payment['id'] ?>">View receipt</a></div><?php elseif ($payment['status'] === 'Failed'): ?><div class="payment-card-body"><div><strong>Payment correction required</strong><p><?= e($payment['admin_notes'] ?: 'Review and submit valid payment details again.') ?></p></div><a class="button" href="payment.php?application_id=<?= (int) $application['id'] ?>">Correct payment</a></div><?php elseif ($payment['submitted_at']): ?><div class="payment-card-body"><div><strong>Waiting for verification</strong><p>Your submitted payment is being checked. Permit release remains locked until verification.</p></div><a class="button button-secondary" href="payment.php?application_id=<?= (int) $application['id'] ?>">View payment</a></div><?php else: ?><div class="payment-card-body"><div><strong>Payment is now available</strong><p>Submit the assessed payment details for City Treasurer or administrator verification.</p></div><a class="button" href="payment.php?application_id=<?= (int) $application['id'] ?>">Pay permit fees →</a></div><?php endif; ?>
</article>
<?php endif; ?>

<article class="panel document-panel ai-applicant-document-panel">
  <div class="panel-header"><div><p class="eyebrow">Submitted requirements</p><h3>Uploaded documents</h3><p class="muted">Documents are scanned automatically when you submit or re-upload them.</p><?php if ($hasFailedScans): ?><p class="muted">Documents marked with issues need to be re-uploaded.</p><?php endif; ?></div><span class="document-count"><?= count($allDocuments) ?> files</span></div>
  <?php if ($showReupload && $hasFailedScans): ?>
  <div class="form-alert form-alert-error scan-revision-alert">
    <div><strong>Document issues detected</strong><p>Your application is saved. Review the exact findings below and replace only the documents marked “Needs revision.”</p></div>
    <?php if ($firstFailedDocumentId): ?><a class="button" href="#document-<?= $firstFailedDocumentId ?>">Re-upload documents</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="ai-applicant-document-list">
    <?php foreach ($allDocuments as $document):
      $label = $definitions[$document['document_type']][0] ?? $document['document_type'];
      $hasScan = isset($document['scan_status']) && $document['scan_status'] === 'Completed';
      $scanUnavailable = isset($document['scan_status']) && $document['scan_status'] === 'Failed';
      $failReasons = $hasScan ? document_scan_failure_reasons($document, $label) : [];
      $scanPassed = $hasScan && !$failReasons;
      $docIssues = $hasScan ? document_scan_advisory_issues($document['issues'] ?? null) : [];
      $sensitiveSkipped = $document['document_type'] === 'health_results_doc' && !openai_settings()['allow_sensitive_documents'];
      $canReuploadDocument = $isApplicant && document_reupload_allowed($application['status'], $document);
    ?>
      <section id="document-<?= (int) $document['id'] ?>" class="ai-applicant-doc-row <?= $hasScan ? ($scanPassed ? 'scan-passed' : 'scan-failed') : 'scan-not-run' ?>">
        <div class="ai-applicant-doc-main">
          <a class="ai-applicant-doc-link" href="<?= e(url('document.php?id=' . $document['id'])) ?>" target="_blank" rel="noopener">
            <span class="document-icon">▧</span>
            <span>
              <strong><?= e($label) ?></strong>
              <small><?= e($document['original_name']) ?> · <?= e(number_format((int) $document['file_size'] / 1024, 1)) ?> KB</small>
            </span>
          </a>
          <?php if ($hasScan): ?>
            <span class="ai-applicant-scan-chip <?= $scanPassed ? 'chip-pass' : 'chip-fail' ?>">
              <?= $scanPassed ? '✓ Verified' : '✕ Needs revision' ?>
            </span>
          <?php elseif ($scanUnavailable): ?>
            <span class="ai-applicant-scan-chip chip-neutral">Check unavailable</span>
          <?php else: ?>
            <span class="ai-applicant-scan-chip chip-neutral">Not AI-scanned</span>
          <?php endif; ?>
        </div>
        <?php if ($hasScan && !$scanPassed): ?>
          <div class="ai-applicant-scan-details">
            <p class="scan-summary"><?= e($document['summary'] ?? '') ?></p>
            <strong class="scan-detail-title">Why this file failed</strong>
            <ul class="scan-issues"><?php foreach ($failReasons as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
            <?php if ($docIssues): ?><div class="scan-advisories"><strong>Additional AI observations</strong><ul><?php foreach ($docIssues as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
          </div>
        <?php elseif ($hasScan && $scanPassed): ?>
          <div class="ai-applicant-scan-details scan-pass-details">
            <p class="scan-summary"><?= e($document['summary'] ?? 'Document verified successfully.') ?></p>
            <small>Quality <?= (int)$document['quality_score'] ?>% · Confidence <?= (int)$document['confidence_score'] ?>%</small>
            <?php if ($docIssues): ?><div class="scan-advisories"><strong>Advisory observations (non-blocking)</strong><ul><?php foreach ($docIssues as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="ai-applicant-scan-details scan-neutral-details"><p><?= $sensitiveSkipped ? 'Automated scanning was skipped to protect this sensitive medical document. BPLO will review it directly.' : 'Automated verification was unavailable or skipped. This file remains submitted for normal BPLO review.' ?></p></div>
        <?php endif; ?>
        <?php if ($canReuploadDocument): ?>
          <div class="ai-applicant-scan-details scan-neutral-details">
            <p>Choose a replacement file. It will be saved and scanned automatically when you click Re-upload.</p>
            <form method="post" action="<?= e(url('api/reupload-document.php')) ?>" enctype="multipart/form-data" class="reupload-form">
              <?= csrf_field() ?>
              <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
              <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
              <label class="reupload-input">
                <span>Upload replacement file (PDF, JPG or PNG, up to 5 MB)</span>
                <input name="replacement_file" type="file" accept=".pdf,.jpg,.jpeg,.png" required>
              </label>
              <button class="button button-secondary" type="submit">Re-upload</button>
            </form>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</article>
<?php if ($canManageApplications): ?><div class="form-actions"><a class="button button-secondary" href="admin/index.php#queue">← Review queue</a><a class="button" href="admin/review.php?id=<?= (int) $application['id'] ?>">Review decision →</a></div><?php endif; ?>
<?php render_app_footer(); ?>
