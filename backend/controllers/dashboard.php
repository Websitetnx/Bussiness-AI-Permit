<?php
declare(strict_types=1);

if (!defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_role('applicant');
$pdo = db();

// Check for automated renewal alerts
$renewalAlerts = check_and_generate_renewal_notices($pdo, (int) $user['id']);
try { $fsicWarnings = get_user_fsic_warnings($pdo, (int) $user['id']); }
catch (PDOException) { $fsicWarnings = []; }



$countStatement = $pdo->prepare("SELECT COUNT(*) total,
    SUM(status = 'For Review') for_review,
    SUM(status IN ('Approved','Released')) approved,
    SUM(status = 'Needs Revision') needs_revision
    FROM applications WHERE user_id = ?");
$countStatement->execute([$user['id']]);
$counts = $countStatement->fetch() ?: [];

$recentStatement = $pdo->prepare('SELECT a.id, a.reference, a.permit_number, a.application_type, a.status, a.submitted_at, a.stage, a.requires_building_inspection, a.requires_electrical_inspection, a.requires_plumbing_inspection, b.business_name FROM applications a JOIN businesses b ON b.id = a.business_id WHERE a.user_id = ? ORDER BY a.submitted_at DESC LIMIT 5');
$recentStatement->execute([$user['id']]);
$recent = $recentStatement->fetchAll();

$permitTerms = [];
if (installment_workflow_ready($pdo)) {
    $terms=$pdo->prepare("SELECT a.id,a.permit_number,a.approved_at,a.fsic_expiry_date,a.status,b.business_name FROM applications a JOIN businesses b ON b.id=a.business_id WHERE a.user_id=? AND a.status IN ('Approved','Released') ORDER BY a.approved_at DESC,a.id DESC");
    $terms->execute([$user['id']]);$permitTerms=$terms->fetchAll();

}

$recentPredictions = [];
foreach ($recent as $recentApplication) {
    $recentPredictions[(int) $recentApplication['id']] = estimate_application_timeline($pdo, $recentApplication);
}

require dirname(__DIR__, 2) . '/frontend/views/dashboard.php';
