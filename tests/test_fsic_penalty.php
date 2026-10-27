<?php
declare(strict_types=1);

define('PERMIT_ENTRY', 1);
require_once __DIR__ . '/../backend/includes/bootstrap.php';

echo "Running FSIC Penalty Tests...\n";

// 1. Rule verification
$tests = [
    ['days' => -5, 'expect_applied' => false, 'expect_rate' => 0.0],
    ['days' => 0, 'expect_applied' => false, 'expect_rate' => 0.0],
    ['days' => 1, 'expect_applied' => true, 'expect_rate' => 0.09],
    ['days' => 15, 'expect_applied' => true, 'expect_rate' => 0.09],
    ['days' => 30, 'expect_applied' => true, 'expect_rate' => 0.09],
    ['days' => 31, 'expect_applied' => false, 'expect_rate' => 0.0],
    ['days' => 60, 'expect_applied' => false, 'expect_rate' => 0.0],
];

foreach ($tests as $t) {
    $rule = fsic_penalty_rule($t['days']);
    if ($rule['applies'] !== $t['expect_applied'] || $rule['rate'] !== $t['expect_rate']) {
        echo "FAILED rule test for days: {$t['days']}\n";
        exit(1);
    }
}
echo "✓ fsic_penalty_rule boundary tests passed\n";

// 2. Penalty calculation details (10 days overdue on 1000.00 total)
$today = date('Y-m-d');
$expiry10DaysAgo = date('Y-m-d', strtotime('-10 days'));
$res = calculate_fsic_penalty_details(1000.00, $expiry10DaysAgo, $today);

if (!$res['penalty_applied']) {
    echo "FAILED: penalty_applied should be true\n";
    exit(1);
}
if ($res['days_overdue'] !== 10) {
    echo "FAILED: days_overdue expected 10, got {$res['days_overdue']}\n";
    exit(1);
}
if ($res['penalty_amount'] !== 90.00) {
    echo "FAILED: penalty_amount expected 90.00, got {$res['penalty_amount']}\n";
    exit(1);
}
if ($res['total_amount_due'] !== 1090.00) {
    echo "FAILED: total_amount_due expected 1090.00, got {$res['total_amount_due']}\n";
    exit(1);
}
echo "✓ calculate_fsic_penalty_details overdue test passed (Base 1000.00 -> Penalty 90.00 -> Total 1090.00)\n";

// 3. Penalty calculation details on fractional cents
$resFractional = calculate_fsic_penalty_details(1234.56, $expiry10DaysAgo, $today);
// 1234.56 * 0.09 = 111.1104 -> 111.11
// Total = 1234.56 + 111.11 = 1345.67
if ($resFractional['penalty_amount'] !== 111.11 || $resFractional['total_amount_due'] !== 1345.67) {
    echo "FAILED fractional rounding test: got penalty {$resFractional['penalty_amount']} and total {$resFractional['total_amount_due']}\n";
    exit(1);
}
echo "✓ calculate_fsic_penalty_details fractional rounding test passed (1234.56 -> Penalty 111.11 -> Total 1345.67)\n";

// 4. Valid FSIC (future date)
$futureExpiry = date('Y-m-d', strtotime('+20 days'));
$resValid = calculate_fsic_penalty_details(1000.00, $futureExpiry, $today);
if ($resValid['penalty_applied'] || $resValid['penalty_amount'] !== 0.0 || $resValid['total_amount_due'] !== 1000.0) {
    echo "FAILED valid FSIC test\n";
    exit(1);
}
echo "✓ calculate_fsic_penalty_details valid FSIC test passed (0% penalty)\n";

// 5. Test inject_fsic_penalty_to_assessment
$dummyAssessment = [
    'components' => [
        ['key' => 'local_business_tax', 'label' => 'LBT', 'amount' => 500.00],
        ['key' => 'fire_safety_inspection_fee', 'label' => 'BFP', 'amount' => 500.00],
    ],
    'total' => 1000.00,
];

$injected = inject_fsic_penalty_to_assessment($dummyAssessment, $res);
if ($injected['total'] !== 1090.00) {
    echo "FAILED inject assessment total test\n";
    exit(1);
}
$penaltyFound = false;
foreach ($injected['components'] as $c) {
    if ($c['key'] === 'fsic_late_renewal_penalty') {
        $penaltyFound = true;
        if ($c['amount'] !== 90.00) {
            echo "FAILED component amount mismatch\n";
            exit(1);
        }
    }
}
if (!$penaltyFound) {
    echo "FAILED penalty component not found\n";
    exit(1);
}
echo "✓ inject_fsic_penalty_to_assessment test passed\n";

echo "ALL FSIC PENALTY TESTS PASSED!\n";
