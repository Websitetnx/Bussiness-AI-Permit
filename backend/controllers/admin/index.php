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

$stats = $pdo->query("SELECT COUNT(*) total, SUM(status = 'For Review') for_review, SUM(status = 'Needs Revision') needs_revision, SUM(status IN ('Approved','Released')) approved FROM applications")->fetch() ?: [];
$status = trim((string) ($_GET['status'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$allowedStatuses = ['all', 'For Review', 'Needs Revision', 'Approved', 'Released', 'Rejected'];
if (!in_array($status, $allowedStatuses, true)) $status = 'all';
$where = [];
$params = [];
if ($status !== 'all') { $where[] = 'a.status = ?'; $params[] = $status; }
if ($search !== '') { $where[] = '(a.reference LIKE ? OR b.business_name LIKE ? OR u.name LIKE ?)'; $term = '%' . $search . '%'; array_push($params, $term, $term, $term); }
$sql = 'SELECT a.id, a.reference, a.permit_number, a.application_type, a.status, a.submitted_at, b.business_name, u.name owner_name FROM applications a JOIN businesses b ON b.id = a.business_id JOIN users u ON u.id = a.user_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY FIELD(a.status, \'For Review\', \'Needs Revision\', \'Approved\', \'Released\', \'Rejected\'), a.submitted_at DESC LIMIT 100';
$queue = $pdo->prepare($sql);
$queue->execute($params);

$stageRows = $pdo->query('SELECT stage, COUNT(*) count FROM applications GROUP BY stage')->fetchAll();
$stageCounts = array_column($stageRows, 'count', 'stage');
$total = max(1, (int) ($stats['total'] ?? 0));


$queueRows = $queue->fetchAll();

require dirname(__DIR__, 3) . '/frontend/views/admin/index.php';
