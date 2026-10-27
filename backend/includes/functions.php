<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}

function app_config(?string $key = null): mixed
{
    static $config = null;
    $config ??= require dirname(__DIR__, 2) . '/config.php';
    return $key === null ? $config : ($config[$key] ?? null);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (str_ends_with($directory, '/admin') || str_ends_with($directory, '/api')) {
        $directory = dirname($directory);
    }
    return $directory === '/' || $directory === '.' ? '' : rtrim($directory, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function posted_geolocation(array $source): array
{
    $latitudeRaw = trim((string) ($source['latitude'] ?? ''));
    $longitudeRaw = trim((string) ($source['longitude'] ?? ''));
    $accuracyRaw = trim((string) ($source['location_accuracy_m'] ?? ''));

    if ($latitudeRaw === '' && $longitudeRaw === '') {
        return ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'error' => null];
    }
    if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
        return ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'error' => 'Capture the business location again or leave it blank.'];
    }

    $latitude = (float) $latitudeRaw;
    $longitude = (float) $longitudeRaw;
    $accuracy = $accuracyRaw === '' ? null : (is_numeric($accuracyRaw) ? (float) $accuracyRaw : -1.0);
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($accuracy !== null && ($accuracy < 0 || $accuracy > 100000))) {
        return ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'error' => 'The captured business location is invalid. Please capture it again.'];
    }

    return ['latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy, 'error' => null];
}

function openstreetmap_url(mixed $latitude, mixed $longitude): string
{
    $lat = number_format((float) $latitude, 7, '.', '');
    $lng = number_format((float) $longitude, 7, '.', '');
    return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lng) . '#map=18/' . rawurlencode($lat) . '/' . rawurlencode($lng);
}

function redirect(string $path): never
{
    if (defined('PERMIT_MOBILE') && PERMIT_MOBILE) {
        $GLOBALS['permit_mobile_redirect'] = $path;
        $GLOBALS['permit_mobile_messages'] = pull_flashes();
        csrf_token();
        exit;
    }
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('The form expired. Please go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function normalize_role(?string $role): string
{
    return strtolower(trim((string) $role));
}

function is_admin_role(?string $role): bool
{
    return in_array(normalize_role($role), ['admin', 'treasurer', 'staff', 'superadmin'], true);
}

function is_treasurer_role(?string $role): bool
{
    return normalize_role($role) === 'treasurer';
}

function role_allows(?string $userRole, string|array $allowedRoles): bool
{
    $allowed = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    $allowed = array_map(static fn(mixed $role): string => normalize_role((string) $role), $allowed);
    $normalizedUserRole = normalize_role($userRole);

    if (in_array($normalizedUserRole, $allowed, true)) {
        return true;
    }

    // Preserve legacy staff/superadmin roles used by older installations.
    return in_array('admin', $allowed, true)
        && in_array($normalizedUserRole, ['staff', 'superadmin'], true);
}

function can_manage_applications(?string $role): bool
{
    return role_allows($role, 'admin');
}

function can_manage_payments(?string $role): bool
{
    return role_allows($role, ['admin', 'treasurer']);
}

function role_home_path(?string $role): string
{
    if (is_treasurer_role($role)) {
        return 'admin/payments.php';
    }
    return can_manage_applications($role) ? 'admin/index.php' : 'dashboard.php';
}

function require_guest(): void
{
    $user = current_user();
    if ($user) {
        redirect(role_home_path($user['role'] ?? ''));
    }
}

function require_login(): array
{
    $user = current_user();
    if ($user) {
        $q=db()->prepare('SELECT id,name,email,role,profile_picture FROM users WHERE id=? AND is_active=1');
        $q->execute([$user['id']]);
        $user=$q->fetch() ?: null;
        if ($user) $_SESSION['user']=$user;
        else unset($_SESSION['user']);
    }
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $user;
}

function deny_access(string $message = 'Access denied. You do not have permission to perform this action.'): never
{
    $user = current_user();
    flash('error', $message);
    if ($user) {
        redirect(role_home_path($user['role'] ?? ''));
    }
    redirect('login.php');
}

function require_role(string|array $role): array
{
    $user = require_login();
    $userRole = (string) ($user['role'] ?? '');

    if (!role_allows($userRole, $role)) {
        if (is_admin_role($userRole)) {
            $message = is_treasurer_role($userRole)
                ? 'Access denied. Treasurer accounts are limited to payment management.'
                : 'Access denied. You do not have permission to access that page.';
            flash('error', $message);
            redirect(role_home_path($userRole));
        } else {
            flash('error', 'Access denied. You do not have permission to access that page.');
            redirect('dashboard.php');
        }
    }

    return $user;
}

function status_class(string $status): string
{
    return match ($status) {
        'Approved' => 'approved',
        'Needs Revision' => 'revision',
        'Released' => 'released',
        default => 'review',
    };
}

function application_stage(string $status): int
{
    return match ($status) {
        'Approved' => 3,
        'Released' => 4,
        default => 2,
    };
}

function create_reference(PDO $pdo): string
{
    do {
        $reference = 'BPL-' . date('Y') . '-' . random_int(10000, 99999);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE reference = ?');
        $statement->execute([$reference]);
    } while ((int) $statement->fetchColumn() > 0);
    return $reference;
}

function create_permit_number(PDO $pdo): string
{
    do {
        $number = 'BP-' . date('Y') . '-' . random_int(10000, 99999);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE permit_number = ?');
        $statement->execute([$number]);
    } while ((int) $statement->fetchColumn() > 0);
    return $number;
}

function create_receipt_number(PDO $pdo): string
{
    $withInstallments = function_exists('installment_workflow_ready') && installment_workflow_ready($pdo);
    do {
        $number = 'OR-' . date('Y') . '-' . random_int(100000, 999999);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE receipt_number = ?');
        $statement->execute([$number]);
        $exists = (int) $statement->fetchColumn() > 0;
        if ($withInstallments) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM payment_installments WHERE receipt_number=?');
            $statement->execute([$number]);
            $exists = $exists || (int) $statement->fetchColumn() > 0;
        }
    } while ($exists);
    return $number;
}

function payment_status_class(string $status): string
{
    return match ($status) {
        'Paid' => 'approved',
        'Failed', 'Refunded' => 'revision',
        default => 'review',
    };
}

function business_type_options(): array
{
    return ['Retail', 'Food and Beverage', 'Professional Services', 'Manufacturing', 'Other'];
}

function permit_fee_assessment(PDO $pdo, array $application): ?array
{
    $statement = $pdo->prepare('SELECT s.*, r.id rate_id, r.new_lbt_rate_percent, r.renewal_lbt_rate_percent, r.mayors_permit_fee FROM permit_fee_settings s JOIN permit_business_type_rates r ON r.business_type = ? AND r.is_active = 1 WHERE s.id = 1 AND s.is_configured = 1');
    $statement->execute([(string) $application['business_type']]);
    $rates = $statement->fetch();
    if (!$rates) return null;

    $isNew = ($application['application_type'] ?? 'New') === 'New';
    $basis = (float) ($isNew ? ($application['declared_capital'] ?? 0) : ($application['gross_sales'] ?? 0));
    if (($isNew && $basis <= 0) || (!$isNew && $basis < 0)) return null;
    $lbtRate = (float) ($isNew ? $rates['new_lbt_rate_percent'] : $rates['renewal_lbt_rate_percent']);
    $money = static fn(float $amount): float => round(max(0, $amount), 2);

    $lbt = $money($basis * ($lbtRate / 100));
    $mayor = $money((float) $rates['mayors_permit_fee']);
    $sanitary = $money((float) $rates['sanitary_fee']);
    $zoning = $money((float) $rates['zoning_fee']);
    $generalInspection = $money((float) $rates['general_inspection_fee']);
    $building = !empty($application['requires_building_inspection']) ? $money((float) $rates['building_inspection_fee']) : 0.0;
    $electrical = !empty($application['requires_electrical_inspection']) ? $money((float) $rates['electrical_inspection_fee']) : 0.0;
    $plumbing = !empty($application['requires_plumbing_inspection']) ? $money((float) $rates['plumbing_inspection_fee']) : 0.0;
    $barangay = $money((float) $rates['barangay_clearance_fee']);
    $communityTax = $money((float) $rates['community_tax_fee']);
    $regulatory = $money($sanitary + $zoning + $generalInspection + $building + $electrical + $plumbing);
    $fireBase = $money($mayor + $regulatory);
    $fireRate = max(0, (float) $rates['bfp_rate_percent']);
    $fireMinimum = $money((float) $rates['bfp_minimum_fee']);
    $fireFee = $fireBase > 0 && ($fireRate > 0 || $fireMinimum > 0)
        ? $money(max($fireBase * ($fireRate / 100), $fireMinimum))
        : 0.0;

    $components = [
        ['key' => 'local_business_tax', 'label' => 'Local business tax (LBT)', 'amount' => $lbt],
        ['key' => 'mayors_permit_fee', 'label' => "Mayor's permit / license fee", 'amount' => $mayor],
        ['key' => 'sanitary_fee', 'label' => 'Sanitary / health inspection', 'amount' => $sanitary],
        ['key' => 'zoning_fee', 'label' => 'Zoning / locational clearance', 'amount' => $zoning],
        ['key' => 'general_inspection_fee', 'label' => 'General inspection fee', 'amount' => $generalInspection],
    ];
    if (!empty($application['requires_building_inspection'])) $components[] = ['key' => 'building_inspection_fee', 'label' => 'Building inspection', 'amount' => $building];
    if (!empty($application['requires_electrical_inspection'])) $components[] = ['key' => 'electrical_inspection_fee', 'label' => 'Electrical inspection', 'amount' => $electrical];
    if (!empty($application['requires_plumbing_inspection'])) $components[] = ['key' => 'plumbing_inspection_fee', 'label' => 'Plumbing inspection', 'amount' => $plumbing];
    $components[] = ['key' => 'fire_safety_inspection_fee', 'label' => 'BFP fire safety inspection fee', 'amount' => $fireFee];
    $components[] = ['key' => 'barangay_clearance_fee', 'label' => 'Barangay clearance', 'amount' => $barangay];
    $components[] = ['key' => 'community_tax_fee', 'label' => 'Community tax certificate', 'amount' => $communityTax];

    return [
        'version' => 1,
        'lgu_name' => (string) $rates['lgu_name'],
        'application_type' => $isNew ? 'New' : 'Renewal',
        'business_type' => (string) $application['business_type'],
        'tax_basis_label' => $isNew ? 'Declared capital investment' : 'Previous-year gross sales',
        'tax_basis' => $money($basis),
        'lbt_rate_percent' => $lbtRate,
        'bfp_rate_percent' => $fireRate,
        'bfp_fee_base' => $fireBase,
        'regulatory_subtotal' => $regulatory,
        'components' => $components,
        'total' => $money(array_sum(array_column($components, 'amount'))),
        'calculated_at' => date(DATE_ATOM),
    ];
}

function decoded_fee_breakdown(mixed $value): ?array
{
    if (!is_string($value) || trim($value) === '') return null;
    $decoded = json_decode($value, true);
    return is_array($decoded) && isset($decoded['components'], $decoded['total']) ? $decoded : null;
}

function document_definitions(): array
{
    return [
        'registration_doc' => ['DTI / SEC / CDA / BSP Registration', true, 'Required'],
        'brgy_clearance_doc' => ['Barangay Business Clearance', true, 'Required'],
        'proof_of_ownership_doc' => ['Proof of Ownership', true, 'Required'],
        'zoning_clearance_doc' => ['Zoning Clearance', true, 'Required'],
        'lease_contract_doc' => ['Lease Contract for Private Building', false, 'If renting'],
        'occupancy_doc' => ['Occupancy Permit', false, 'Provide one option'],
        'tax_declaration_doc' => ['Tax Declaration — Current Year', false, 'If owner'],
        'nga_clearance_doc' => ['NGA Clearance', false, 'Regulated businesses'],
        'occupancy_affidavit_doc' => ['Affidavit of Undertaking in Absence of Occupancy', false, 'Occupancy alternative'],
        'building_owner_permit_doc' => ["Building Owner's Business Permit", false, 'If renting'],
        'current_fsic_doc' => ['Fire Safety Inspection Certificate (FSIC)', false, 'Current year'],
        'sanitary_permit_doc' => ['Sanitary Permit', false, 'Current year'],
        'dti_receipt_doc' => ['DTI Receipt', false, 'If applicable'],
    ];
}

function store_application_documents(PDO $pdo, int $applicationId): array
{
    $config = app_config();
    $definitions = document_definitions();
    $allowedTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $errors = [];
    $prepared = [];

    foreach ($definitions as $field => [$label, $required]) {
        $file = $_FILES[$field] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $errors[] = $label . ' is required.';
            }
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = $label . ' could not be uploaded.';
            continue;
        }
        if ((int) $file['size'] > $config['max_upload_bytes']) {
            $errors[] = $label . ' must be 5 MB or smaller.';
            continue;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowedTypes[$mime])) {
            $errors[] = $label . ' must be a PDF, JPG, or PNG file.';
            continue;
        }
        $prepared[] = [$field, $label, $file, $mime, $allowedTypes[$mime]];
    }

    $hasOccupancy = isset($_FILES['occupancy_doc']) && ($_FILES['occupancy_doc']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $hasAffidavit = isset($_FILES['occupancy_affidavit_doc']) && ($_FILES['occupancy_affidavit_doc']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    if (!$hasOccupancy && !$hasAffidavit) {
        $errors[] = 'Upload an Occupancy Permit or its Affidavit of Undertaking alternative.';
    }
    if ($errors) {
        return $errors;
    }

    if (!is_dir($config['upload_dir']) && !mkdir($config['upload_dir'], 0750, true) && !is_dir($config['upload_dir'])) {
        return ['The secure upload directory could not be created.'];
    }

    $insert = $pdo->prepare('INSERT INTO application_documents (application_id, document_type, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)');
    $movedPaths = [];
    foreach ($prepared as [$field, $label, $file, $mime, $extension]) {
        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $config['upload_dir'] . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $errors[] = $label . ' could not be saved.';
            break;
        }
        $movedPaths[] = $destination;
        $insert->execute([$applicationId, $field, basename($file['name']), $storedName, $mime, (int) $file['size']]);
        snapshot_document($pdo, (int) $pdo->lastInsertId());
    }

    if ($errors) {
        foreach ($movedPaths as $path) {
            if (is_file($path)) unlink($path);
        }
    }

    return $errors;
}

function record_status(PDO $pdo, int $applicationId, string $status, ?int $changedBy, ?string $notes = null): void
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM applications WHERE id=? FOR UPDATE'); $lock->execute([$applicationId]);
        $last=$pdo->prepare('SELECT status,notes FROM application_status_history WHERE application_id=? ORDER BY id DESC LIMIT 1');
        $last->execute([$applicationId]); $previous=$last->fetch();
        if (!$previous || $previous['status']!==$status || (string)$previous['notes']!==(string)$notes) {
            $statement = $pdo->prepare('INSERT INTO application_status_history (application_id, status, notes, changed_by) VALUES (?, ?, ?, ?)');
            $statement->execute([$applicationId, $status, $notes ?: null, $changedBy]);
            $historyId=(int)$pdo->lastInsertId();
            notify_application($pdo,$applicationId,'status:'.$historyId,$status==='Released'?'Business permit certificate ready':'Application: '.$status,
                'Application status: '.$status.'. '.($notes ?: 'Open your application for details.'));
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function audit(PDO $pdo, int $userId, string $action, string $entityType, ?int $entityId = null): void
{
    $statement = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$userId, $action, $entityType, $entityId, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * Return the exact rules that make a completed document scan blocking.
 * Missing values are treated as an unavailable assessment, not a failure.
 */
function document_scan_failure_reasons(array $scan, string $documentLabel): array
{
    if (isset($scan['scan_status']) && $scan['scan_status'] !== 'Completed') {
        return [];
    }

    $reasons = [];
    $matchesExpected = null;
    if (array_key_exists('matches_expected_type', $scan) && $scan['matches_expected_type'] !== null) {
        $matchValue = $scan['matches_expected_type'];
        if ($matchValue === false || $matchValue === 0 || $matchValue === '0') {
            $matchesExpected = false;
        } elseif ($matchValue === true || $matchValue === 1 || $matchValue === '1') {
            $matchesExpected = true;
        }
    }

    if ($matchesExpected === false) {
        $detectedType = is_string($scan['detected_document_type'] ?? null)
            ? trim(preg_replace('/\s+/', ' ', $scan['detected_document_type']) ?? '')
            : '';
        $reasons[] = $detectedType !== ''
            ? 'Expected "' . $documentLabel . '", but detected "' . substr($detectedType, 0, 190) . '".'
            : 'The uploaded file does not match the expected "' . $documentLabel . '" document type.';
    }
    if (isset($scan['quality_score']) && is_numeric($scan['quality_score']) && (int) $scan['quality_score'] < 40) {
        $reasons[] = 'Document quality is too low (' . (int) $scan['quality_score'] . '%). The minimum is 40%.';
    }
    if (isset($scan['confidence_score']) && is_numeric($scan['confidence_score']) && (int) $scan['confidence_score'] < 30) {
        $reasons[] = 'Document identification confidence is too low (' . (int) $scan['confidence_score'] . '%). The minimum is 30%.';
    }

    return $reasons;
}

/**
 * Normalize advisory AI issues for display. These issues never make a scan fail.
 */
function document_scan_advisory_issues(mixed $issues): array
{
    if (is_string($issues)) {
        try {
            $issues = json_decode($issues, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
    }
    if (!is_array($issues)) {
        return [];
    }

    $normalized = [];
    foreach ($issues as $issue) {
        if (!is_scalar($issue)) {
            continue;
        }
        $issue = trim(preg_replace('/\s+/', ' ', (string) $issue) ?? '');
        if ($issue === '') {
            continue;
        }
        $normalized[] = strlen($issue) > 300 ? substr($issue, 0, 297) . '...' : $issue;
        if (count($normalized) >= 5) {
            break;
        }
    }
    return $normalized;
}

/** Allow corrections during review without treating an unavailable scan as a rejection. */
function document_reupload_allowed(string $applicationStatus, array $document): bool
{
    if ($applicationStatus === 'Needs Revision') {
        return true;
    }
    if (!in_array($applicationStatus, ['Submitted', 'For Review'], true)) {
        return false;
    }
    if (($document['scan_status'] ?? null) !== 'Completed') {
        return true;
    }

    $label = document_definitions()[$document['document_type'] ?? ''][0] ?? 'Uploaded document';
    return document_scan_failure_reasons($document, $label) !== [];
}

/**
 * Return all persisted, completed scans that currently block an application.
 * Failed and absent scans are intentionally nonblocking. If scan storage is not
 * available, return no blockers so AI remains an enhancement rather than a gate.
 */
function application_scan_failures(PDO $pdo, int $applicationId): array
{
    try {
        $statement = $pdo->prepare("SELECT d.id, d.document_type, s.detected_document_type, s.matches_expected_type, s.quality_score, s.confidence_score, s.issues, s.summary
            FROM application_documents d
            JOIN document_ai_scans s ON s.document_id = d.id AND s.scan_status = 'Completed'
            WHERE d.application_id = ?
            ORDER BY d.id");
        $statement->execute([$applicationId]);
        $documents = $statement->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $definitions = document_definitions();
    $failures = [];
    foreach ($documents as $document) {
        $documentType = (string) $document['document_type'];
        $documentLabel = $definitions[$documentType][0] ?? $documentType;
        $reasons = document_scan_failure_reasons($document, $documentLabel);
        if (!$reasons) {
            continue;
        }
        $failures[] = [
            'label' => $documentLabel,
            'reasons' => $reasons,
            'issues' => document_scan_advisory_issues($document['issues'] ?? null),
            'summary' => $document['summary'] ?? '',
            'document_id' => (int) $document['id'],
        ];
    }
    return $failures;
}

/** Shared daily allowance for automatic and manual document checks. */
function automatic_scan_capacity(PDO $pdo): array
{
    $limit = (int) (app_config('openai')['daily_scan_limit'] ?? 0);
    // Admin scans and automatic attempts consume the same daily allowance.
    $used = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('ai_scan_document', 'ai_auto_scan_attempt') AND created_at >= CURDATE()")->fetchColumn();
    return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
}

/**
 * Automatically scan documents that do not already have a scan result.
 *
 * Passing a document ID limits the operation to that document. Existing scan
 * rows, including historical manual scans and failed attempts, are never overwritten.
 * Returns only persisted validation failures. Configuration, quota, storage,
 * API, rate-limit, and network failures stop or skip scanning without gating.
 */
function auto_scan_uploaded_documents(PDO $pdo, int $applicationId, int $userId, ?int $documentId = null): array
{
    try {
        require_once __DIR__ . '/openai.php';
        $settings = openai_settings();
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? ''));
        $dailyLimit = (int) ($settings['daily_scan_limit'] ?? 0);
        $uploadDir = app_config('upload_dir');
        if ($apiKey === '' || $model === '' || $dailyLimit <= 0 || !is_string($uploadDir) || $uploadDir === '') {
            error_log('[PERMIT auto-scan] Application ' . $applicationId . ': automatic scanning is not configured.');
            return [];
        }
        if ($documentId !== null && $documentId <= 0) {
            return [];
        }

        $remainingScans = automatic_scan_capacity($pdo)['remaining'];
        if ($remainingScans <= 0) {
            error_log('[PERMIT auto-scan] Application ' . $applicationId . ': daily scan limit reached.');
            return [];
        }

        $sql = 'SELECT d.id, d.document_type, d.original_name, d.stored_name, d.mime_type
            FROM application_documents d
            LEFT JOIN document_ai_scans s ON s.document_id = d.id
            WHERE d.application_id = ? AND s.document_id IS NULL';
        $parameters = [$applicationId];
        if ($documentId !== null) {
            $sql .= ' AND d.id = ?';
            $parameters[] = $documentId;
        }
        $sql .= ' ORDER BY d.id';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $documents = $statement->fetchAll();
        if (!$documents) {
            return [];
        }

        $saveCompleted = $pdo->prepare("INSERT INTO document_ai_scans
            (document_id, application_id, scan_status, detected_document_type, matches_expected_type, quality_score, confidence_score, extracted_fields, issues, summary, requires_human_review, model, error_message, scanned_by)
            SELECT ?, ?, 'Completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?
            FROM application_documents
            WHERE id = ? AND application_id = ? AND stored_name = ?");
        $saveFailed = $pdo->prepare("INSERT INTO document_ai_scans
            (document_id, application_id, scan_status, requires_human_review, model, error_message, scanned_by)
            SELECT ?, ?, 'Failed', 1, ?, ?, ?
            FROM application_documents
            WHERE id = ? AND application_id = ? AND stored_name = ?");
    } catch (Throwable) {
        return [];
    }

    $definitions = document_definitions();
    $allowSensitive = (bool) ($settings['allow_sensitive_documents'] ?? false);
    $failures = [];
    $attemptedScans = 0;

    foreach ($documents as $document) {
        if ($attemptedScans >= $remainingScans) {
            break;
        }

        $currentDocumentId = (int) $document['id'];
        $documentType = (string) $document['document_type'];
        $documentLabel = $definitions[$documentType][0] ?? $documentType;
        if ($documentType === 'health_results_doc' && !$allowSensitive) {
            continue;
        }

        $attemptedApi = false;
        try {
            $scanPath = $uploadDir . '/' . basename((string) $document['stored_name']);
            if (!is_file($scanPath) || !is_readable($scanPath)) {
                throw new RuntimeException('The uploaded document could not be found or read. Please upload it again.');
            }
            // Each API call can take up to 150 seconds. Keep a multi-file upload
            // from exhausting PHP's request timer between documents.
            if (function_exists('set_time_limit') && (int) ini_get('max_execution_time') > 0) {
                @set_time_limit(180);
            }
            // Count every outbound auto-scan attempt before making the request so
            // service failures cannot be retried past the configured daily limit.
            audit($pdo, $userId, 'ai_auto_scan_attempt', 'application_document', $currentDocumentId);
            $attemptedScans++;
            $attemptedApi = true;
            $result = scan_permit_document(
                $scanPath,
                (string) $document['original_name'],
                (string) $document['mime_type'],
                $documentLabel,
                $userId
            );
            if (!array_key_exists('matches_expected_type', $result)
                || !is_bool($result['matches_expected_type'])
                || !isset($result['quality_score'], $result['confidence_score'])
                || !is_numeric($result['quality_score'])
                || !is_numeric($result['confidence_score'])) {
                throw new RuntimeException('The AI service returned an incomplete document assessment.');
            }

            $normalized = [
                'detected_document_type' => is_string($result['detected_document_type'] ?? null)
                    ? substr($result['detected_document_type'], 0, 190)
                    : '',
                'matches_expected_type' => $result['matches_expected_type'],
                'quality_score' => max(0, min(100, (int) $result['quality_score'])),
                'confidence_score' => max(0, min(100, (int) $result['confidence_score'])),
            ];
            $issues = document_scan_advisory_issues($result['issues'] ?? []);
            $extractedFields = is_array($result['extracted_fields'] ?? null) ? $result['extracted_fields'] : [];
            $extractedJson = json_encode($extractedFields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $issuesJson = json_encode($issues, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $scanError) {
            try {
                $saveFailed->execute([
                    $currentDocumentId,
                    $applicationId,
                    $model,
                    substr($scanError->getMessage(), 0, 1000),
                    $userId,
                    $currentDocumentId,
                    $applicationId,
                    (string) $document['stored_name'],
                ]);
                snapshot_document($pdo, $currentDocumentId);
            } catch (Throwable) {
                // A concurrent/manual scan or unavailable scan table remains nonblocking.
            }
            // A missing local file should not stop other uploaded documents.
            // A service outage stops the batch to avoid repeated failed API calls.
            if ($attemptedApi) break;
            continue;
        }

        try {
            $saveCompleted->execute([
                $currentDocumentId,
                $applicationId,
                $normalized['detected_document_type'],
                $normalized['matches_expected_type'] ? 1 : 0,
                $normalized['quality_score'],
                $normalized['confidence_score'],
                $extractedJson,
                $issuesJson,
                is_string($result['summary'] ?? null) ? $result['summary'] : '',
                is_bool($result['requires_human_review'] ?? null) ? ($result['requires_human_review'] ? 1 : 0) : 1,
                $model,
                $userId,
                $currentDocumentId,
                $applicationId,
                (string) $document['stored_name'],
            ]);
            if ($saveCompleted->rowCount() === 1) snapshot_document($pdo, $currentDocumentId);
            if ($saveCompleted->rowCount() !== 1) {
                return application_scan_failures($pdo, $applicationId);
            }
        } catch (Throwable) {
            // Never gate on a result that could not be persisted. Existing scans
            // are preserved because auto-scanning does not use an upsert.
            return application_scan_failures($pdo, $applicationId);
        }

        $reasons = document_scan_failure_reasons($normalized, $documentLabel);
        if ($reasons) {
            $failures[] = [
                'label' => $documentLabel,
                'reasons' => $reasons,
                'issues' => $issues,
                'document_id' => $currentDocumentId,
            ];
        }

        try {
            audit($pdo, $userId, 'ai_auto_scan_document', 'application_document', $currentDocumentId);
        } catch (Throwable) {
            // Stop when quota accounting is unavailable, but keep this persisted result.
            break;
        }
    }

    // Re-read persisted results so concurrent manual scans and successful rows
    // saved before a later outage cannot be omitted from the status decision.
    return application_scan_failures($pdo, $applicationId);
}
