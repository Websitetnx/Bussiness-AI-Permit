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
$applicationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$applicationId) {
    http_response_code(404);
    exit('Business certificate not found.');
}

$pdo = db();
$termColumns=installment_workflow_ready($pdo)?'a.permit_effective_date,a.permit_expiration_date,':'NULL permit_effective_date,NULL permit_expiration_date,';
$sql = "SELECT a.id, a.user_id, a.reference, a.permit_number, a.application_type,
               a.reviewed_at, a.approved_at, a.updated_at,
               b.business_name, b.business_type, b.organization_type, b.tin, b.address,
               $termColumns u.name owner_name, p.assessment_breakdown, s.lgu_name
        FROM applications a
        JOIN businesses b ON b.id = a.business_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN payments p ON p.application_id = a.id
        LEFT JOIN permit_fee_settings s ON s.id = 1
        WHERE a.id = ? AND a.status = 'Released' AND a.permit_number IS NOT NULL";
$parameters = [$applicationId];
if (!$canManageApplications) {
    $sql .= ' AND a.user_id = ?';
    $parameters[] = $user['id'];
}

$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$certificate = $statement->fetch();
if (!$certificate) {
    http_response_code(404);
    exit('Released business certificate not found.');
}

$releaseStatement = $pdo->prepare("SELECT h.created_at, u.name released_by_name
    FROM application_status_history h
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.application_id = ? AND h.status = 'Released'
    ORDER BY h.created_at ASC, h.id ASC
    LIMIT 1");
$releaseStatement->execute([$applicationId]);
$release = $releaseStatement->fetch() ?: [];

$issuedAtValue = $release['created_at']
    ?? $certificate['reviewed_at']
    ?? $certificate['approved_at']
    ?? $certificate['updated_at'];
$issuedAt = new DateTimeImmutable((string) $issuedAtValue);
$validUntil = $issuedAt->modify('+1 year')->modify('-1 day')->setTime(23, 59, 59);
$effectiveAt = !empty($certificate['permit_effective_date']) ? permit_date($certificate['permit_effective_date']) : $issuedAt;
if (!empty($certificate['permit_expiration_date'])) $validUntil=permit_date($certificate['permit_expiration_date'])->setTime(23,59,59);
$isExpired = new DateTimeImmutable() > $validUntil;
$notYetEffective = new DateTimeImmutable() < $effectiveAt;

$assessment = decoded_fee_breakdown($certificate['assessment_breakdown'] ?? null);
$lguName = trim((string) ($assessment['lgu_name'] ?? $certificate['lgu_name'] ?? ''));
if ($lguName === '') {
    $lguName = 'Local Government Unit';
}
$certificateType = $certificate['application_type'] === 'Renewal'
    ? 'Business Permit Renewal Certificate'
    : 'Business Permit Certificate';
$backPath = $canManageApplications
    ? 'admin/review.php?id=' . $applicationId
    : 'application.php?id=' . $applicationId;


$verification = permit_verification_record($pdo, (int)$applicationId);
$publicRoot = rtrim((string)app_config('app_url'), '/');
$verificationUrl = filter_var($publicRoot, FILTER_VALIDATE_URL) && in_array(parse_url($publicRoot, PHP_URL_SCHEME), ['https','http'], true)
    ? $publicRoot . '/verify-permit.php?token=' . $verification['token'] : '';

require dirname(__DIR__, 2) . '/frontend/views/certificate.php';
