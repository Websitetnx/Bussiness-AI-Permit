<?php
declare(strict_types=1);
if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
render_app_header('AI Predictive Analytics', 'analytics');
?>
<div class="section-heading"><div><p class="eyebrow">AI-assisted operations</p><h2>Predict permit workload and processing demand</h2><p class="muted">Forecasts use PERMIT's aggregate historical records. They do not predict whether an individual permit should be approved.</p></div><form method="post" action="generate-insights.php"><?= csrf_field() ?><button class="button" type="submit" <?= !$tablesReady ? 'disabled' : '' ?>>Generate AI insight</button></form></div>
<?php if (!$tablesReady): ?><div class="form-alert form-alert-error">Import <strong>database/migrations/002_ai_features.sql</strong> to save AI analytics reports and document scans.</div><?php endif; ?>
<?php if (!openai_enabled()): ?><div class="form-alert ai-config-alert"><strong>API setup needed:</strong> Set the <code>OPENAI_API_KEY</code> environment variable. Statistical forecasts below continue to work without the API.</div><?php endif; ?>

<div class="stat-grid ai-stat-grid">
  <article class="stat-card"><span>↗</span><strong><?= (int) $metrics['predicted_applications_next_7_days'] ?></strong><small>Predicted applications · next 7 days</small></article>
  <article class="stat-card"><span>◷</span><strong><?= $metrics['estimated_backlog_clearance_days'] === null ? '—' : e(number_format((float) $metrics['estimated_backlog_clearance_days'], 1)) ?></strong><small><?= $metrics['estimated_backlog_clearance_days'] === null ? 'Needs completed cases for estimate' : 'Estimated days to clear backlog' ?></small></article>
  <article class="stat-card"><span>▤</span><strong><?= (int) $metrics['current_backlog'] ?></strong><small>Applications currently for review</small></article>
  <article class="stat-card"><span>◇</span><strong><?= (int) $metrics['forecast_confidence_percent'] ?>%</strong><small>Forecast confidence from sample size</small></article>
</div>

<div class="content-grid analytics-grid">
  <article class="panel forecast-panel"><div class="panel-header"><div><p class="eyebrow">37-day view</p><h3>Daily applications and seven-day forecast</h3></div><div class="chart-legend"><span><i class="actual"></i> Historical</span><span><i class="predicted"></i> Predicted</span></div></div><div class="forecast-chart">
    <?php foreach ($metrics['daily_series'] as $index => $point): ?><div class="forecast-column" title="<?= e($point['date']) ?>: <?= (int) $point['count'] ?>"><span class="forecast-value"><?= (int) $point['count'] ?></span><div class="forecast-bar actual" style="height:<?= max(3, round(((int) $point['count'] / $maxDaily) * 100)) ?>%"></div><small><?= $index % 5 === 0 ? e(date('M j', strtotime($point['date']))) : '' ?></small></div><?php endforeach; ?>
    <?php foreach ($metrics['seven_day_forecast'] as $index => $value): ?><div class="forecast-column" title="Forecast day <?= $index + 1 ?>: <?= (int) $value ?>"><span class="forecast-value"><?= (int) $value ?></span><div class="forecast-bar predicted" style="height:<?= max(3, round(((int) $value / $maxDaily) * 100)) ?>%"></div><small><?= $index === 0 ? '+7 days' : '' ?></small></div><?php endforeach; ?>
  </div></article>
  <aside class="panel ai-health-panel"><div class="panel-header"><div><p class="eyebrow">Operating indicators</p><h3>Current processing health</h3></div><span class="risk-badge <?= strtolower($risk) ?>"><?= e($risk) ?> workload risk</span></div><div class="metric-list"><div><span>Average processing time</span><strong><?= $metrics['average_processing_days'] === null ? 'No completed cases' : e(number_format((float) $metrics['average_processing_days'], 1)) . ' days' ?></strong></div><div><span>Revision rate</span><strong><?= e(number_format((float) $metrics['revision_rate_percent'], 1)) ?>%</strong></div><div><span>Documents AI-scanned</span><strong><?= (int) $metrics['completed_ai_scans'] ?></strong></div><div><span>Average scan quality</span><strong><?= $metrics['average_document_quality'] === null ? 'No data' : e(number_format((float) $metrics['average_document_quality'], 1)) . '%' ?></strong></div><div><span>Document type mismatches</span><strong><?= (int) $metrics['document_type_mismatches'] ?></strong></div></div></aside>
</div>

<article class="panel ai-insight-panel"><div class="panel-header"><div><p class="eyebrow">Aggregate AI insight</p><h3><?= e($insights['headline'] ?? 'Generate an advisory management summary') ?></h3></div><?php if ($latestReport): ?><small class="muted"><?= e(date('M j, Y g:i A', strtotime($latestReport['created_at']))) ?> · <?= e($latestReport['model']) ?></small><?php endif; ?></div>
  <?php if ($insights): ?><div class="ai-insight-body"><p><?= e($insights['summary']) ?></p><h4>Recommended operational actions</h4><ol><?php foreach ($insights['recommendations'] as $recommendation): ?><li><?= e($recommendation) ?></li><?php endforeach; ?></ol><div class="ai-limitation"><strong>Limitations:</strong> <?= e($insights['limitations']) ?></div></div><?php else: ?><div class="empty-state"><p>The numerical forecast is already available. Configure the API and select “Generate AI insight” for an advisory summary based only on aggregate metrics.</p></div><?php endif; ?>
</article>
<?php render_app_footer(); ?>
