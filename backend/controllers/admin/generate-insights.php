<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();
$pdo = db();

if (!ai_tables_ready($pdo)) {
    flash('error', 'Import database/migrations/002_ai_features.sql before generating AI insights.');
    redirect('admin/analytics.php');
}

try {
    $lastGenerated = $pdo->query('SELECT created_at FROM ai_analytics_reports ORDER BY created_at DESC, id DESC LIMIT 1')->fetchColumn();
    if ($lastGenerated && strtotime((string) $lastGenerated) > time() - (int) openai_settings()['insight_cooldown_seconds']) {
        throw new RuntimeException('Please wait before generating another AI analytics summary.');
    }
    $metrics = build_predictive_metrics($pdo);
    $insights = generate_analytics_insights($metrics, (int) $user['id']);
    $insert = $pdo->prepare('INSERT INTO ai_analytics_reports (metrics, insights, model, generated_by) VALUES (?, ?, ?, ?)');
    $insert->execute([
        json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($insights, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        openai_settings()['model'],
        $user['id'],
    ]);
    audit($pdo, (int) $user['id'], 'generate_ai_analytics', 'ai_analytics_report', (int) $pdo->lastInsertId());
    flash('success', 'Aggregate AI management insights were generated successfully.');
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
}
redirect('admin/analytics.php');
