<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('Dashboard', 'dashboard');
?>
<?php if (!empty($renewalAlerts)): ?>
  <?php foreach ($renewalAlerts as $alert): ?>
    <div class="renewal-alert-banner">
      <div class="renewal-alert-icon"><?= $alert['is_expired'] ? '⚠️' : '🔔' ?></div>
      <div class="renewal-alert-content">
        <strong><?= $alert['is_expired'] ? 'Business Permit Expired' : 'Annual Renewal Notice' ?>: <?= e($alert['business_name']) ?></strong>
        <p>Permit <strong><?= e($alert['permit_number']) ?></strong> <?= $alert['is_expired'] ? 'expired on ' . e($alert['expiry_date']) . '. Avoid late penalties by renewing now.' : 'will expire on ' . e($alert['expiry_date']) . ' (' . $alert['days_until_expiry'] . ' days left). Early renewal is open.' ?></p>
      </div>
      <a class="button button-warning" href="renew.php?permit_number=<?= urlencode($alert['permit_number']) ?>">Renew permit now →</a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($fsicWarnings)): ?>
  <?php foreach ($fsicWarnings as $warn): ?>
    <div class="renewal-alert-banner <?= $warn['type'] === 'expired' ? 'fsic-alert-expired' : 'fsic-alert-warning' ?>" style="border-left: 4px solid <?= $warn['type'] === 'expired' ? '#dc2626' : '#d97706' ?>; background: <?= $warn['type'] === 'expired' ? '#fef2f2' : '#fffbeb' ?>; margin-bottom: 1rem;">
      <div class="renewal-alert-icon"><?= $warn['type'] === 'expired' ? '⚠️' : '🔔' ?></div>
      <div class="renewal-alert-content">
        <strong style="color: <?= $warn['type'] === 'expired' ? '#991b1b' : '#92400e' ?>;">
          <?= $warn['type'] === 'expired' ? 'FSIC Expired — Late Surcharge (9%)' : 'FSIC Expiring Soon' ?>: <?= e($warn['business_name']) ?>
        </strong>
        <p style="color: #4b5563;">
          <?php if ($warn['type'] === 'expired'): ?>
            Fire Safety Inspection Certificate (FSIC) expired on <strong><?= e($warn['fsic_expiry_date']) ?></strong> (<?= (int)$warn['days_overdue'] ?> day(s) overdue).
            <?php if (!empty($warn['penalty_applies'])): ?>
              A <strong>9% late renewal surcharge</strong> applies to your renewal assessment. Renew immediately to avoid further sanctions.
            <?php else: ?>
              Please renew your permit and FSIC compliance promptly.
            <?php endif; ?>
          <?php else: ?>
            Fire Safety Inspection Certificate (FSIC) expires on <strong><?= e($warn['fsic_expiry_date']) ?></strong> (<?= (int)$warn['days_remaining'] ?> day(s) remaining). Renew on time to avoid the automatic 9% surcharge.
          <?php endif; ?>
        </p>
      </div>
      <a class="button <?= $warn['type'] === 'expired' ? 'button-danger' : 'button-warning' ?>" href="renew.php?permit_number=<?= urlencode($warn['permit_number'] ?: '') ?>">Renew now →</a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="welcome-card">

  <div>
    <p class="eyebrow light">Welcome back, <?= e($user['name']) ?></p>
    <h2>Manage your permits without the long lines.</h2>
    <p>Submit requirements, monitor progress, receive automated renewal updates, and track predictions in one secure place.</p>
    <div class="welcome-actions">
      <a class="button button-light" href="apply.php">Start new application <span>→</span></a>
      <a class="button button-light-secondary" href="renew.php">Renew existing permit ↻</a>
    </div>
  </div>
  <div class="welcome-art" aria-hidden="true"><span>✓</span></div>
</div>

<div class="dashboard-search-wrap">
  <div class="search-wrapper">
    <span class="search-icon">🔍</span>
    <input type="search" class="quick-search-input" data-suggest-search placeholder="Personalized search: Find your application by Reference, Business Name, or type 'renew'..." aria-label="Personalized search">
  </div>
</div>

<div class="section-heading"><div><p class="eyebrow">At a glance</p><h2>Your permit activity</h2></div><p class="muted"><?= e(date('F j, Y')) ?></p></div>
<div class="stat-grid">
  <article class="stat-card"><span>▤</span><strong><?= (int) ($counts['total'] ?? 0) ?></strong><small>Total applications</small></article>
  <article class="stat-card"><span>◷</span><strong><?= (int) ($counts['for_review'] ?? 0) ?></strong><small>Under review</small></article>
  <article class="stat-card"><span>✓</span><strong><?= (int) ($counts['approved'] ?? 0) ?></strong><small>Approved permits</small></article>
  <article class="stat-card"><span>!</span><strong><?= (int) ($counts['needs_revision'] ?? 0) ?></strong><small>Needs attention</small></article>
</div>

<div class="content-grid">
  <article class="panel">
    <div class="panel-header"><div><p class="eyebrow">Applications</p><h3>Recent activity</h3></div><a href="track.php">Track with live timeline →</a></div>
    <?php if (!$recent): ?><p class="empty-state">No applications yet. Start your first application when your documents are ready.</p><?php endif; ?>
    <?php foreach ($recent as $application):
      $pred = $recentPredictions[(int) $application['id']];
    ?>
      <a class="application-item application-link" href="application.php?id=<?= (int) $application['id'] ?>">
        <div class="application-icon"><?= $application['application_type'] === 'Renewal' ? '↻' : '＋' ?></div>
        <div class="application-copy">
          <strong><?= e($application['business_name']) ?></strong>
          <small><?= e($application['reference']) ?> · <?= e(date('M j, Y', strtotime($application['submitted_at']))) ?>
            <?php if (!$pred['is_completed'] && $application['status'] === 'For Review'): ?>
              · <span class="prediction-pill">⏱ Est. <?= e($pred['estimated_range_days']) ?></span>
            <?php endif; ?>
          </small>
        </div>
        <span class="status <?= e(status_class($application['status'])) ?>"><?= e($application['status']) ?></span>
      </a>
    <?php endforeach; ?>
  </article>
  <aside class="panel checklist-panel">
    <div class="panel-header"><div><p class="eyebrow">Before you apply</p><h3>Standard requirements</h3></div></div>
    <ul class="checklist">
      <li><span>✓</span> DTI / SEC / CDA registration</li>
      <li><span>✓</span> BFP application form</li>
      <li><span>✓</span> BFP questionnaire</li>
      <li><span>✓</span> Signed consent form</li>
      <li><span>✓</span> Occupancy permit or affidavit alternative</li>
    </ul>
    <p class="hint">Accepted files: PDF, JPG, or PNG up to 5 MB per document.</p>
    <div class="assistant-prompt-box">
      <strong>Need assistance?</strong>
      <p>Ask our <strong>BPLO AI Assistant</strong> (bottom-right 💬) anytime for requirement checklists, fee estimates, and real-time status tracking!</p>
    </div>
  </aside>
</div>
<?php if($permitTerms): ?><article class="panel ops-panel"><h3>BPLO-confirmed permit validity</h3><div class="table-responsive"><table><thead><tr><th>Business / permit</th><th>Permit status</th><th>Approved date</th><th>FSIC expiry date</th></tr></thead><tbody><?php foreach($permitTerms as $term): ?><tr><td><a href="<?= e(url('application.php?id='.$term['id'])) ?>"><?= e($term['business_name'].' · '.$term['permit_number']) ?></a></td><td><?= e($term['status']) ?></td><td><?= e($term['approved_at'] ? date('Y-m-d', strtotime($term['approved_at'])) : 'Awaiting BPLO confirmation') ?></td><td><?= e($term['fsic_expiry_date']??'Awaiting BPLO confirmation') ?></td></tr><?php endforeach; ?></tbody></table></div><p>Quarterly installment payments do not change these dates.</p></article><?php endif; ?>

<?php render_app_footer(); ?>
