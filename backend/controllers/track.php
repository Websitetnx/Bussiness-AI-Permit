<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_role(['applicant', 'admin']);
$canManageApplications = can_manage_applications($user['role'] ?? '');
$pdo = db();

$query = strtoupper(trim((string) ($_GET['reference'] ?? $_GET['q'] ?? '')));
$result = null;
$history = [];
$prediction = null;
$searched = $query !== '';

if ($searched) {
    $sql = 'SELECT a.*, b.business_name, b.business_type, b.address, b.contact, u.name owner_name
            FROM applications a
            JOIN businesses b ON b.id = a.business_id
            JOIN users u ON u.id = a.user_id
            WHERE (a.reference = ? OR a.permit_number = ?)';
    $params = [$query, $query];

    if (!$canManageApplications) {
        $sql .= ' AND a.user_id = ?';
        $params[] = $user['id'];
    }
    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $result = $statement->fetch();

    if ($result) {
        $historyStmt = $pdo->prepare('SELECT h.status, h.notes, h.created_at, u.name changed_by_name FROM application_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.application_id = ? ORDER BY h.created_at ASC, h.id ASC');
        $historyStmt->execute([(int) $result['id']]);
        $history = $historyStmt->fetchAll();

        $prediction = estimate_application_timeline($pdo, $result);
    }
}

require dirname(__DIR__, 2) . '/frontend/views/track.php';
