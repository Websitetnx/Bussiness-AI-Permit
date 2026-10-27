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
$errors = [];
$ready = true;
$policyReady = installment_workflow_ready($pdo);
$paymentPolicy = $policyReady ? permit_payment_policy($pdo) : null;
$policyError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_section'] ?? '') === 'permit_policy') {
    verify_csrf();
    try {
        if (!$policyReady) throw new RuntimeException('Import migration 009 before configuring installments.');
        $values = validate_payment_policy($_POST);
        $pdo->beginTransaction();
        $q = $pdo->prepare('UPDATE permit_payment_policy SET policy_reference=?,validity_mode=?,quarterly_enabled=?,quarterly_fee_keys=?,schedule_mode=?,installment_schedule=?,annual_due_days=?,upfront_due_days=?,allow_installment_release=?,release_installments=?,payment_reminder_days=?,expiration_reminder_days=?,updated_by=? WHERE id=1');
        $q->execute([$values['policy_reference'],$values['validity_mode'],$values['quarterly_enabled'],json_encode($values['quarterly_fee_keys'],JSON_THROW_ON_ERROR),$values['schedule_mode'],json_encode($values['installment_schedule'],JSON_THROW_ON_ERROR),$values['annual_due_days'],$values['upfront_due_days'],$values['allow_installment_release'],$values['release_installments'],$values['payment_reminder_days'],$values['expiration_reminder_days'],$user['id']]);
        audit($pdo,(int)$user['id'],'update_permit_payment_policy','permit_payment_policy',1);
        $pdo->commit();
        flash('success','Permit/payment policy saved. Existing confirmed dates and payment plans were not changed.');
        redirect('admin/fee-settings.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $policyError = $e instanceof PDOException ? 'The policy could not be saved.' : $e->getMessage();
    }
}
$fixedFields = [
    'sanitary_fee' => 'Sanitary / health inspection fee',
    'zoning_fee' => 'Zoning / locational clearance fee',
    'general_inspection_fee' => 'General inspection fee',
    'building_inspection_fee' => 'Building inspection fee',
    'electrical_inspection_fee' => 'Electrical inspection fee',
    'plumbing_inspection_fee' => 'Plumbing inspection fee',
    'barangay_clearance_fee' => 'Barangay clearance fee',
    'community_tax_fee' => 'Community tax certificate fee',
    'bfp_rate_percent' => 'BFP fee rate (%)',
    'bfp_minimum_fee' => 'BFP minimum fee',
];

try {
    $settings = $pdo->query('SELECT * FROM permit_fee_settings WHERE id = 1')->fetch();
    $rateRows = $pdo->query('SELECT * FROM permit_business_type_rates ORDER BY id')->fetchAll();
} catch (PDOException) {
    $ready = false;
    $settings = null;
    $rateRows = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_section'] ?? '') !== 'permit_policy') {
    verify_csrf();
    if (!$ready) $errors[] = 'Import database/migrations/005_fee_assessment_formula.sql before saving rates.';
    $lguName = trim((string) ($_POST['lgu_name'] ?? ''));
    if (strlen($lguName) < 2 || strlen($lguName) > 190) $errors[] = 'Enter the city or municipality name.';
    $fixedValues = [];
    foreach ($fixedFields as $field => $label) {
        $raw = str_replace([',', '₱', ' '], '', (string) ($_POST[$field] ?? ''));
        $maximum = $field === 'bfp_rate_percent' ? 100 : 99999999.99;
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $maximum) $errors[] = 'Enter a valid value for ' . $label . '.';
        $fixedValues[$field] = is_numeric($raw) ? (float) $raw : 0.0;
    }
    $types = business_type_options();
    $typeValues = [];
    foreach ($types as $type) {
        foreach (['new_lbt_rate_percent', 'renewal_lbt_rate_percent', 'mayors_permit_fee'] as $field) {
            $raw = str_replace([',', '₱', ' '], '', (string) ($_POST[$field][$type] ?? ''));
            $maximum = str_contains($field, 'percent') ? 100 : 99999999.99;
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $maximum) $errors[] = 'Enter a valid ' . str_replace('_', ' ', $field) . ' for ' . $type . '.';
            $typeValues[$type][$field] = is_numeric($raw) ? (float) $raw : 0.0;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE permit_fee_settings SET lgu_name = ?, sanitary_fee = ?, zoning_fee = ?, general_inspection_fee = ?, building_inspection_fee = ?, electrical_inspection_fee = ?, plumbing_inspection_fee = ?, barangay_clearance_fee = ?, community_tax_fee = ?, bfp_rate_percent = ?, bfp_minimum_fee = ?, is_configured = 1, updated_by = ? WHERE id = 1');
            $update->execute([$lguName, $fixedValues['sanitary_fee'], $fixedValues['zoning_fee'], $fixedValues['general_inspection_fee'], $fixedValues['building_inspection_fee'], $fixedValues['electrical_inspection_fee'], $fixedValues['plumbing_inspection_fee'], $fixedValues['barangay_clearance_fee'], $fixedValues['community_tax_fee'], $fixedValues['bfp_rate_percent'], $fixedValues['bfp_minimum_fee'], $user['id']]);
            $rateUpdate = $pdo->prepare('UPDATE permit_business_type_rates SET new_lbt_rate_percent = ?, renewal_lbt_rate_percent = ?, mayors_permit_fee = ?, is_active = 1 WHERE business_type = ?');
            foreach ($typeValues as $type => $values) $rateUpdate->execute([$values['new_lbt_rate_percent'], $values['renewal_lbt_rate_percent'], $values['mayors_permit_fee'], $type]);
            audit($pdo, (int) $user['id'], 'update_permit_fee_schedule', 'permit_fee_settings', 1);
            $pdo->commit();
            flash('success', 'The permit fee schedule was saved. New assessments will use these rates.');
            redirect('admin/fee-settings.php');
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'The permit fee schedule could not be saved.';
        }
    }
}

$rates = [];
foreach ($rateRows as $row) $rates[$row['business_type']] = $row;

require dirname(__DIR__, 3) . '/frontend/views/admin/fee-settings.php';
