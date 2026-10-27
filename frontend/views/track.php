<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Track Application', 'track');
?>
<div class="section-heading">
  <div>
    <p class="eyebrow">Real-Time Tracker</p>
    <h2>Application Status & Predictive Timeline</h2>
    <p class="muted">Monitor live progress, estimated turnaround times, and official processing transaction logs.</p>
  </div>
</div>

<div class="panel lookup-panel">
  <form method="get" class="lookup-form search-wrapper">
    <label class="field">
      <span>Application Reference or Permit Number</span>
      <input name="reference" required value="<?= e($query) ?>" data-suggest-search placeholder="e.g. BPL-2026-12345 or BP-2026-98765" autofocus>
    </label>
    <button class="button" type="submit">Track status ⌕</button>
  </form>
</div>

<?php if ($searched && !$result): ?>
  <div class="panel result-card empty-state">
    <strong>No matching application record found.</strong>
    <p>Please double check the reference or permit number. You can also ask our AI Assistant (bottom-right) to help locate your application.</p>
  </div>
<?php endif; ?>

<?php if ($result && $prediction): ?>
  <article class="panel result-card tracking-result-card">
    <div class="record-summary">
      <div>
        <span class="eyebrow-pill"><?= e($result['reference']) ?></span>
        <h3><?= e($result['business_name']) ?></h3>
        <p class="muted">
          <?= e($result['application_type']) ?> Application · Business Type: <?= e($result['business_type']) ?> · Submitted <?= e(date('F j, Y', strtotime($result['submitted_at']))) ?>
        </p>
      </div>
      <div class="status-summary-side">
        <span class="status <?= e(status_class($result['status'])) ?>"><?= e($result['status']) ?></span>
        <?php if ($result['permit_number']): ?>
          <small class="permit-tag">Permit: <?= e($result['permit_number']) ?></small>
        <?php endif; ?>
      </div>
    </div>

    <!-- 4-Stage Progress Stepper -->
    <div class="stepper-wrapper">
      <ol class="timeline">
        <?php
        $stages = [
            1 => ['Submitted', 'Application & documents received'],
            2 => ['Validation', 'Requirement & inspection checks'],
            3 => ['Assessment & Fee', 'Tax calculation & payment verification'],
            4 => ['Permit Release', 'Official permit issued'],
        ];
        foreach ($stages as $stageNum => [$title, $desc]):
            $isDone = $stageNum < (int) $result['stage'] || ($stageNum === (int) $result['stage'] && in_array($result['status'], ['Approved', 'Released']));
            $isCurrent = $stageNum === (int) $result['stage'] && !in_array($result['status'], ['Approved', 'Released']);
        ?>
          <li class="<?= $isDone ? 'done' : ($isCurrent ? 'current' : '') ?>">
            <span class="step-num"><?= $isDone ? '✓' : $stageNum ?></span>
            <div class="step-text">
              <strong><?= e($title) ?></strong>
              <small><?= e($desc) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>

    <!-- Rule-Based Predictive Analytics Banner -->
    <div class="prediction-card">
      <div class="prediction-header">
        <div class="prediction-title-group">
          <span class="prediction-badge">Rule-Based Predictive Analytics</span>
          <h4>Approval Timeline Forecast</h4>
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
          <span>Target Date: <?= e($prediction['estimated_approval_date']) ?></span>
        </div>
        <div class="prediction-metric">
          <small>Queue Position</small>
          <strong>#<?= e((string)$prediction['queue_position']) ?></strong>
          <span>out of <?= e((string)$prediction['total_in_queue']) ?> pending in queue</span>
        </div>
        <div class="prediction-metric">
          <small>Document Completeness</small>
          <strong><?= e((string)$prediction['completeness']['completeness_percent']) ?>%</strong>
          <span><?= e((string)$prediction['completeness']['required_uploaded']) ?> of <?= e((string)$prediction['completeness']['total_required']) ?> key requirements uploaded</span>
        </div>
      </div>

      <div class="prediction-factors">
        <p class="factors-title"><strong>Prediction Calculation Breakdown:</strong></p>
        <ul class="factors-list">
          <?php foreach ($prediction['factors'] as $factor): ?>
            <li>
              <span class="factor-badge <?= $factor['impact_days'] < 0 ? 'factor-bonus' : '' ?>">
                <?= $factor['impact_days'] > 0 ? '+' : '' ?><?= e((string)$factor['impact_days']) ?> days
              </span>
              <span class="factor-label"><strong><?= e($factor['label']) ?></strong> — <?= e($factor['description']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- Official Transaction Logs & Processing History Audit Trail -->
    <div class="transaction-history-section">
      <div class="section-subhead">
        <h5>Official Transaction Logs & Audit Trail</h5>
        <span class="uptime-badge">● 99.9% Live Sync</span>
      </div>
      <div class="table-wrap">
        <table class="transaction-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Status Transition</th>
              <th>Processing Notes & Remarks</th>
              <th>Authorized Officer / User</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history as $log): ?>
              <tr>
                <td><?= e(date('M j, Y — g:i A', strtotime($log['created_at']))) ?></td>
                <td><span class="status <?= e(status_class($log['status'])) ?>"><?= e($log['status']) ?></span></td>
                <td><?= e($log['notes'] ?: 'Status updated successfully.') ?></td>
                <td><?= e($log['changed_by_name'] ?: 'Applicant / System Trigger') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
              <tr><td colspan="4" class="empty-state">No transaction logs recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="form-actions tracking-actions">
      <a class="button button-secondary" href="dashboard.php">← Back to dashboard</a>
      <a class="button" href="application.php?id=<?= (int) $result['id'] ?>">View complete application record →</a>
    </div>
  </article>
<?php endif; ?>

<?php render_app_footer(); ?>
