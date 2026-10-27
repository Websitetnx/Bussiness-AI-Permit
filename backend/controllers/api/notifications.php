<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}


require dirname(__DIR__, 2) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        // Renewal notices are applicant workflow; Treasurer notifications stay payment-only.
        if (!is_treasurer_role($user['role'] ?? '')) {
            check_and_generate_renewal_notices($pdo, (int) $user['id']);
        }

        $notifications = get_user_notifications($pdo, (int) $user['id'], 30);
        $unreadCount = get_unread_notification_count($pdo, (int) $user['id']);

        echo json_encode([
            'ok' => true,
            'unread_count' => $unreadCount,
            'notifications' => array_map(static function (array $item) use ($user): array {
                $detailUrl = null;
                $actionLabel = 'View details';
                if (str_contains(strtolower((string)$item['message']),'installment') && !empty($item['payment_id'])) {
                    $detailUrl = can_manage_payments($user['role']??'') ? url('admin/payment.php?id='.(int)$item['payment_id']) : url('payment.php?application_id='.(int)$item['application_id']);
                    $actionLabel = 'View installments';
                } elseif (is_treasurer_role($user['role'] ?? '') && !empty($item['payment_id'])) {
                    $detailUrl = url('admin/payment.php?id=' . (int) $item['payment_id']);
                } elseif (can_manage_applications($user['role'] ?? '') && !empty($item['application_id'])) {
                    $detailUrl = url('admin/review.php?id=' . (int) $item['application_id']);
                } elseif (!is_admin_role($user['role'] ?? '') && !empty($item['application_id'])) {
                    $isCertificateNotice = ($item['application_status'] ?? '') === 'Released'
                        && str_starts_with((string) $item['message'], 'Business permit certificate ');
                    $detailUrl = url(($isCertificateNotice ? 'certificate.php' : 'application.php') . '?id=' . (int) $item['application_id']);
                    if ($isCertificateNotice) {
                        $actionLabel = 'View certificate';
                    }
                }
                return [
                    'id' => (int) $item['id'],
                    'message' => $item['message'],
                    'application_id' => $item['application_id'] ? (int) $item['application_id'] : null,
                    'reference' => $item['reference'] ?? null,
                    'permit_number' => $item['permit_number'] ?? null,
                    'detail_url' => $detailUrl,
                    'action_label' => $actionLabel,
                    'is_read' => !empty($item['read_at']),
                    'created_at' => $item['created_at'],
                    'time_ago' => date('M j, Y g:i A', strtotime($item['created_at'])),
                ];
            }, $notifications),
        ]);
        exit;
    }

    if ($action === 'mark_read') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
            exit;
        }

        mark_notification_as_read($pdo, $id, (int) $user['id']);
        $unreadCount = get_unread_notification_count($pdo, (int) $user['id']);

        echo json_encode(['ok' => true, 'unread_count' => $unreadCount]);
        exit;
    }

    if ($action === 'mark_all_read') {
        mark_all_notifications_as_read($pdo, (int) $user['id']);
        echo json_encode(['ok' => true, 'unread_count' => 0]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
