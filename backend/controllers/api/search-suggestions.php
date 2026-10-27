<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}


require dirname(__DIR__, 2) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$query = trim((string) ($_GET['q'] ?? ''));
$pdo = db();
$user = current_user();

$results = [];

if (strlen($query) >= 2) {
    $term = '%' . $query . '%';

    // 1. If user is logged in, find user's own applications/businesses first
    if ($user) {
        $userId = (int) $user['id'];
        $isTreasurer = is_treasurer_role($user['role'] ?? '');
        $canManageApplications = can_manage_applications($user['role'] ?? '');

        if ($isTreasurer) {
            $stmt = $pdo->prepare('SELECT p.id, p.status payment_status, p.amount, p.payment_reference, p.receipt_number, a.reference, b.business_name, u.name applicant_name FROM payments p JOIN applications a ON a.id = p.application_id JOIN businesses b ON b.id = a.business_id JOIN users u ON u.id = a.user_id WHERE a.reference LIKE ? OR b.business_name LIKE ? OR u.name LIKE ? OR p.payment_reference LIKE ? OR p.receipt_number LIKE ? ORDER BY p.submitted_at DESC, p.id DESC LIMIT 6');
            $stmt->execute([$term, $term, $term, $term, $term]);
            foreach ($stmt->fetchAll() as $payment) {
                $results[] = [
                    'type' => 'payment',
                    'title' => $payment['business_name'],
                    'subtitle' => "{$payment['reference']} · {$payment['applicant_name']} · ₱" . number_format((float) $payment['amount'], 2) . " ({$payment['payment_status']})",
                    'url' => url('admin/payment.php?id=' . $payment['id']),
                    'category' => 'Payments',
                ];
            }
        } elseif ($canManageApplications) {
            $stmt = $pdo->prepare('SELECT a.id, a.reference, a.permit_number, a.application_type, a.status, b.business_name, u.name owner_name FROM applications a JOIN businesses b ON b.id = a.business_id JOIN users u ON u.id = a.user_id WHERE a.reference LIKE ? OR b.business_name LIKE ? OR u.name LIKE ? OR a.permit_number LIKE ? ORDER BY a.submitted_at DESC LIMIT 6');
            $stmt->execute([$term, $term, $term, $term]);
            $apps = $stmt->fetchAll();
            foreach ($apps as $app) {
                $results[] = [
                    'type' => 'application',
                    'title' => $app['business_name'],
                    'subtitle' => "{$app['reference']} · {$app['application_type']} ({$app['status']}) · {$app['owner_name']}",
                    'url' => url('admin/review.php?id=' . $app['id']),
                    'category' => 'Applications',
                ];
            }
        } else {
            $stmt = $pdo->prepare('SELECT a.id, a.reference, a.permit_number, a.application_type, a.status, b.business_name FROM applications a JOIN businesses b ON b.id = a.business_id WHERE a.user_id = ? AND (a.reference LIKE ? OR b.business_name LIKE ? OR a.permit_number LIKE ?) ORDER BY a.submitted_at DESC LIMIT 5');
            $stmt->execute([$userId, $term, $term, $term]);
            $apps = $stmt->fetchAll();
            foreach ($apps as $app) {
                $results[] = [
                    'type' => 'application',
                    'title' => $app['business_name'],
                    'subtitle' => "Ref: {$app['reference']} · {$app['application_type']} · Status: {$app['status']}",
                    'url' => url('application.php?id=' . $app['id']),
                    'category' => 'Your Applications',
                ];
            }
        }
    }

    // 2. Common Action Suggestions & Quick Help
    $actions = $user && is_treasurer_role($user['role'] ?? '')
        ? [
            ['keyword' => 'pay', 'title' => 'Review Payment Records', 'subtitle' => 'Verify submitted payments and issue receipts', 'url' => url('admin/payments.php'), 'category' => 'Payment Actions'],
            ['keyword' => 'receipt', 'title' => 'Find Payment Receipts', 'subtitle' => 'Open verified payment records and receipts', 'url' => url('admin/payments.php'), 'category' => 'Payment Actions'],
        ]
        : [
            ['keyword' => 'apply', 'title' => 'Apply for New Business Permit', 'subtitle' => 'Start new permit registration wizard', 'url' => url('apply.php'), 'category' => 'Quick Actions'],
            ['keyword' => 'new', 'title' => 'New Business Permit Application', 'subtitle' => 'Submit 4 standard + conditional requirements', 'url' => url('apply.php'), 'category' => 'Quick Actions'],
            ['keyword' => 'renew', 'title' => 'Renew Existing Permit', 'subtitle' => 'Fast renewal using previous permit number', 'url' => url('renew.php'), 'category' => 'Quick Actions'],
            ['keyword' => 'track', 'title' => 'Track Permit Progress', 'subtitle' => 'Real-time status & predictive timeline tracker', 'url' => url('track.php'), 'category' => 'Quick Actions'],
            ['keyword' => 'pay', 'title' => 'Pay Assessed Permit Fees', 'subtitle' => 'GCash, Maya, Bank Transfer, or City Hall Counter', 'url' => url('payments.php'), 'category' => 'Quick Actions'],
            ['keyword' => 'receipt', 'title' => 'View Payment Receipts', 'subtitle' => 'Download or print verified electronic receipts', 'url' => url('payments.php'), 'category' => 'Quick Actions'],
        ];

    foreach ($actions as $act) {
        if (stripos($act['keyword'], $query) !== false || stripos($act['title'], $query) !== false) {
            $results[] = [
                'type' => 'action',
                'title' => $act['title'],
                'subtitle' => $act['subtitle'],
                'url' => $act['url'],
                'category' => $act['category'],
            ];
        }
    }
}

echo json_encode([
    'ok' => true,
    'query' => $query,
    'suggestions' => array_slice($results, 0, 8),
]);
