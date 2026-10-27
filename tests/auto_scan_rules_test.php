<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/notifications.php';

$cases = [
    'exact thresholds pass' => [
        ['scan_status' => 'Completed', 'matches_expected_type' => true, 'quality_score' => 40, 'confidence_score' => 30],
        0,
    ],
    'type mismatch fails' => [
        ['scan_status' => 'Completed', 'matches_expected_type' => false, 'quality_score' => 100, 'confidence_score' => 100],
        1,
    ],
    'quality below threshold fails' => [
        ['scan_status' => 'Completed', 'matches_expected_type' => true, 'quality_score' => 39, 'confidence_score' => 100],
        1,
    ],
    'confidence below threshold fails' => [
        ['scan_status' => 'Completed', 'matches_expected_type' => true, 'quality_score' => 100, 'confidence_score' => 29],
        1,
    ],
    'all blocking rules are reported' => [
        ['scan_status' => 'Completed', 'matches_expected_type' => 0, 'quality_score' => 0, 'confidence_score' => 0],
        3,
    ],
    'advisory issues alone pass' => [
        ['scan_status' => 'Completed', 'matches_expected_type' => true, 'quality_score' => 90, 'confidence_score' => 90, 'issues' => ['Check the date.']],
        0,
    ],
    'failed service call is nonblocking' => [
        ['scan_status' => 'Failed', 'matches_expected_type' => false, 'quality_score' => 0, 'confidence_score' => 0],
        0,
    ],
    'missing assessment is nonblocking' => [
        ['matches_expected_type' => null, 'quality_score' => null, 'confidence_score' => null],
        0,
    ],
];

$failures = [];
foreach ($cases as $name => [$scan, $expectedCount]) {
    $actualCount = count(document_scan_failure_reasons($scan, 'Test Document'));
    if ($actualCount !== $expectedCount) {
        $failures[] = $name . ': expected ' . $expectedCount . ', got ' . $actualCount;
    }
}

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY, application_id INTEGER, document_type TEXT)');
    $pdo->exec('CREATE TABLE document_ai_scans (document_id INTEGER PRIMARY KEY, scan_status TEXT, detected_document_type TEXT, matches_expected_type INTEGER, quality_score INTEGER, confidence_score INTEGER, issues TEXT, summary TEXT)');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, application_id INTEGER, message VARCHAR(500))');
    $insertDocument = $pdo->prepare('INSERT INTO application_documents (id, application_id, document_type) VALUES (?, 1, ?)');
    $insertScan = $pdo->prepare('INSERT INTO document_ai_scans (document_id, scan_status, detected_document_type, matches_expected_type, quality_score, confidence_score, issues) VALUES (?, ?, ?, ?, ?, ?, ?)');

    foreach ([
        [1, 'registration_doc', 'Completed', 'DTI Registration', 1, 40, 30, '["Advisory only"]'],
        [2, 'consent_form_doc', 'Failed', null, null, null, null, null],
        [3, 'bfp_application_doc', 'Completed', 'Lease Contract', 0, 90, 90, '[]'],
        [4, 'bfp_questionnaire_doc', 'Completed', 'BFP Questionnaire', 1, 39, 29, '[]'],
    ] as [$id, $type, $status, $detected, $matches, $quality, $confidence, $issues]) {
        $insertDocument->execute([$id, $type]);
        $insertScan->execute([$id, $status, $detected, $matches, $quality, $confidence, $issues]);
    }

    $persistedFailures = application_scan_failures($pdo, 1);
    $failedDocumentIds = array_column($persistedFailures, 'document_id');
    if ($failedDocumentIds !== [3, 4]) {
        $failures[] = 'persisted scan evaluation: expected document IDs 3 and 4, got ' . json_encode($failedDocumentIds);
    }

    create_document_scan_failure_notifications($pdo, 7, 1, 'BPL-TEST-1', [[
        'label' => str_repeat('Document ', 30),
        'reasons' => [str_repeat('Detailed validation reason. ', 40)],
    ]]);
    $notificationMessages = $pdo->query('SELECT message FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if (count($notificationMessages) !== 2 || max(array_map('strlen', $notificationMessages)) > 500) {
        $failures[] = 'notification bounds: expected a header and one document notice, each at most 500 bytes';
    }
}

// A missing key must short-circuit before DB/API work and remain nonblocking.
if (isset($pdo)) {
    putenv('OPENAI_API_KEY=');
    if (auto_scan_uploaded_documents($pdo, 1, 1) !== []) {
        $failures[] = 'disabled AI fallback: expected no blocking failures';
    }
}

if ($failures) {
    fwrite(STDERR, "Auto-scan rule regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Auto-scan regression tests passed (' . count($cases) . " rule cases plus persistence, notification, and fallback checks).\n";
