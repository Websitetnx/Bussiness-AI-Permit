<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

function ai_tables_ready(PDO $pdo): bool
{
    try {
        $statement = $pdo->query("SHOW TABLES LIKE 'ai_analytics_reports'");
        return (bool) $statement->fetchColumn();
    } catch (PDOException) {
        return false;
    }
}

function linear_forecast(array $values, int $futurePeriods): array
{
    $count = count($values);
    if ($count === 0) return ['slope' => 0.0, 'values' => array_fill(0, $futurePeriods, 0)];
    $sumX = $sumY = $sumXY = $sumXX = 0.0;
    foreach (array_values($values) as $x => $value) {
        $sumX += $x;
        $sumY += $value;
        $sumXY += $x * $value;
        $sumXX += $x * $x;
    }
    $denominator = ($count * $sumXX) - ($sumX * $sumX);
    $slope = $denominator == 0.0 ? 0.0 : (($count * $sumXY) - ($sumX * $sumY)) / $denominator;
    $intercept = ($sumY - ($slope * $sumX)) / $count;
    $forecast = [];
    for ($index = 0; $index < $futurePeriods; $index++) {
        $forecast[] = max(0, (int) round($intercept + $slope * ($count + $index)));
    }
    return ['slope' => round($slope, 3), 'values' => $forecast];
}

function build_predictive_metrics(PDO $pdo): array
{
    $startDate = (new DateTimeImmutable('today'))->modify('-29 days');
    $dailyRows = $pdo->prepare('SELECT DATE(submitted_at) day, COUNT(*) count FROM applications WHERE submitted_at >= ? GROUP BY DATE(submitted_at) ORDER BY day');
    $dailyRows->execute([$startDate->format('Y-m-d 00:00:00')]);
    $dailyMap = array_column($dailyRows->fetchAll(), 'count', 'day');
    $dailySeries = [];
    $dailyValues = [];
    for ($index = 0; $index < 30; $index++) {
        $date = $startDate->modify('+' . $index . ' days')->format('Y-m-d');
        $value = (int) ($dailyMap[$date] ?? 0);
        $dailySeries[] = ['date' => $date, 'count' => $value];
        $dailyValues[] = $value;
    }
    $forecast = linear_forecast($dailyValues, 7);

    $summary = $pdo->query("SELECT COUNT(*) total,
        SUM(status = 'For Review') backlog,
        SUM(status = 'Needs Revision') revisions,
        SUM(status IN ('Approved','Released')) completed,
        AVG(CASE WHEN status IN ('Approved','Released') THEN TIMESTAMPDIFF(HOUR, submitted_at, COALESCE(approved_at, updated_at)) / 24 END) average_processing_days,
        SUM(CASE WHEN status IN ('Approved','Released') AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) completed_last_30
        FROM applications")->fetch() ?: [];

    $scanSummary = ['completed_scans' => 0, 'average_quality' => null, 'type_mismatches' => 0];
    try {
        $scanSummary = $pdo->query("SELECT COUNT(*) completed_scans, ROUND(AVG(quality_score), 1) average_quality, SUM(matches_expected_type = 0) type_mismatches FROM document_ai_scans WHERE scan_status = 'Completed'")->fetch() ?: $scanSummary;
    } catch (PDOException) {
        // AI migration is optional until imported.
    }

    $total = (int) ($summary['total'] ?? 0);
    $backlog = (int) ($summary['backlog'] ?? 0);
    $completed = (int) ($summary['completed'] ?? 0);
    $averageDays = $summary['average_processing_days'] === null ? null : round((float) $summary['average_processing_days'], 1);
    $weeklyCapacity = max(1.0, (float) ($summary['completed_last_30'] ?? 0) / 4.2857);
    $clearanceEstimate = $completed === 0 ? null : ($backlog === 0 ? max(1.0, (float) $averageDays) : max(1.0, (float) $averageDays + ($backlog / $weeklyCapacity) * 5));
    $revisionRate = $total ? round(((int) ($summary['revisions'] ?? 0) / $total) * 100, 1) : 0.0;
    $confidence = min(90, 15 + min(45, $total * 2) + min(30, $completed * 3));

    return [
        'generated_at' => date(DATE_ATOM),
        'historical_applications' => $total,
        'current_backlog' => $backlog,
        'completed_applications' => $completed,
        'average_processing_days' => $averageDays,
        'estimated_backlog_clearance_days' => $clearanceEstimate === null ? null : round($clearanceEstimate, 1),
        'predicted_applications_next_7_days' => array_sum($forecast['values']),
        'daily_trend_slope' => $forecast['slope'],
        'revision_rate_percent' => $revisionRate,
        'forecast_confidence_percent' => $confidence,
        'completed_ai_scans' => (int) ($scanSummary['completed_scans'] ?? 0),
        'average_document_quality' => $scanSummary['average_quality'] === null ? null : (float) $scanSummary['average_quality'],
        'document_type_mismatches' => (int) ($scanSummary['type_mismatches'] ?? 0),
        'daily_series' => $dailySeries,
        'seven_day_forecast' => $forecast['values'],
    ];
}
