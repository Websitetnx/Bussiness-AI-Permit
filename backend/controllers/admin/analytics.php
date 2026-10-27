<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
$user = require_role('admin');
$pdo = db();
$metrics = build_predictive_metrics($pdo);
$tablesReady = ai_tables_ready($pdo);
$latestReport = null;
if ($tablesReady) {
    $latestReport = $pdo->query('SELECT * FROM ai_analytics_reports ORDER BY created_at DESC, id DESC LIMIT 1')->fetch() ?: null;
}
$insights = $latestReport ? (json_decode($latestReport['insights'], true) ?: null) : null;
$chartValues = array_merge([1], array_map('intval', array_column($metrics['daily_series'], 'count')), array_map('intval', $metrics['seven_day_forecast']));
$maxDaily = max($chartValues);
$risk = $insights['workload_risk'] ?? ($metrics['current_backlog'] > max(5, $metrics['predicted_applications_next_7_days']) ? 'High' : ($metrics['current_backlog'] > 0 ? 'Moderate' : 'Low'));

require dirname(__DIR__, 3) . '/frontend/views/admin/analytics.php';
