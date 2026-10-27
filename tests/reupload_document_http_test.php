<?php
declare(strict_types=1);

// Exercise real multipart uploads using a temporary app copy and a new test DB.
// Only connection credentials are read locally; live records/uploads are never changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/functions.php';
require __DIR__ . '/support/project_fixture.php';

$root = dirname(__DIR__);
$settings = app_config('db');
$database = 'permit_test_reupload_' . bin2hex(random_bytes(6));
$workspace = sys_get_temp_dir() . '/' . $database;
$server = new PDO(
    'mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';charset=utf8mb4',
    $settings['user'], $settings['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$created = false;
$process = null;
$scanProcess = null;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $server->exec('USE `' . $database . '`');
    $schema = preg_replace('/\ACREATE DATABASE.*?;\s*USE permitflow;\s*/s', '', file_get_contents($root . '/database/schema.sql'), 1, $replaced);
    $check($replaced === 1, 'Schema must not select or create the live database.');
    $server->exec($schema);

    foreach (['', '/includes', '/api', '/admin', '/storage/uploads', '/sessions'] as $directory) {
        if (!is_dir($workspace . $directory)) mkdir($workspace . $directory, 0700, true);
    }
    copy_project_test_fixture($root, $workspace);
    file_put_contents($workspace . '/config.local.php', '<?php return '
        . var_export([
            'db' => array_replace($settings, ['name' => $database]),
            'mail' => ['host' => '', 'username' => '', 'password' => ''],
            'openai' => ['api_key' => '', 'allow_sensitive_documents' => false],
        ], true) . ';');

    $server->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES
        (1,'Owner','owner@example.test','test-only','applicant'),
        (2,'Other','other@example.test','test-only','applicant'),
        (3,'Reviewer','reviewer@example.test','test-only','admin'),
        (4,'Treasurer','treasurer@example.test','test-only','treasurer')");
    $server->exec("INSERT INTO businesses (id,user_id,business_name,business_type,organization_type,tin,contact,email,address)
        VALUES (1,1,'Upload Fixture','Retail','Sole Proprietorship','123456789','09123456789','owner@example.test','Test address')");
    $server->exec("INSERT INTO applications (id,user_id,business_id,reference,status) VALUES (1,1,1,'BPL-TEST-UPLOAD','Needs Revision')");
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    foreach ([32 => 'registration_doc', 33 => 'bfp_application_doc', 34 => 'consent_form_doc'] as $id => $type) {
        file_put_contents($workspace . '/storage/uploads/original-' . $id . '.pdf', $pdf);
        $server->prepare('INSERT INTO application_documents (id,application_id,document_type,original_name,stored_name,mime_type,file_size) VALUES (?,1,?,?,?,\'application/pdf\',?)')
            ->execute([$id, $type, 'original-' . $id . '.pdf', 'original-' . $id . '.pdf', strlen($pdf)]);
    }
    // Failed service calls can still carry stale scores from a previous scan.
    $server->exec("INSERT INTO document_ai_scans (document_id,application_id,scan_status,matches_expected_type,quality_score,confidence_score,model)
        VALUES (32,1,'Failed',0,89,99,'test'),(33,1,'Failed',0,92,99,'test'),(34,1,'Completed',1,99,99,'test')");
    $replacement = $workspace . '/replacement.pdf';
    file_put_contents($replacement, $pdf . '% replacement fixture');
    file_put_contents($workspace . '/invalid.txt', 'Not a permitted document');

    session_save_path($workspace . '/sessions');
    session_name('permitflow_session');
    foreach ([1 => 'applicant', 2 => 'applicant', 3 => 'admin', 4 => 'treasurer'] as $id => $role) {
        session_id('reuploadfixture' . $id);
        session_start();
        $_SESSION = ['user' => ['id' => $id, 'role' => $role], 'csrf_token' => 'fixture-csrf'];
        session_write_close();
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $log = $workspace . '/http.log';
    // Set paths in the test router so nested requests and Windows paths work alike.
    file_put_contents($workspace . '/router.php', '<?php require_once ' . var_export($root . '/vendor/autoload.php', true)
        . '; session_save_path(__DIR__ . "/sessions"); return false;');
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', '.', 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $workspace);
    $check(is_resource($process), 'HTTP test server must start.');
    fclose($pipes[0]);

    $request = static function (string $path, int $user = 1, ?array $post = null) use ($address): array {
        $curl = curl_init('http://' . $address . '/' . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIE => 'permitflow_session=reuploadfixture' . $user]);
        if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$code, (string) $body];
    };
    $upload = static function (int $id, int $user = 1, string $token = 'fixture-csrf', ?string $file = null, int $applicationId = 1) use ($request, $replacement): array {
        return $request('api/reupload-document.php', $user, [
            'application_id' => $applicationId, 'document_id' => $id, 'csrf_token' => $token,
            'replacement_file' => new CURLFile($file ?? $replacement, 'application/pdf', 'corrected.pdf'),
        ]);
    };
    $hasForm = static function (string $html, int $id): bool {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        return (new DOMXPath($dom))->query('//section[@id="document-' . $id . '"]//form[contains(@class,"reupload-form")]')->length === 1;
    };
    $manualButton = static function (string $html, int $id): ?DOMElement {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        return (new DOMXPath($dom))->query('//form[@action="scan-document.php"][input[@name="document_id" and @value="' . $id . '"]]//button')->item(0);
    };
    $storedName = static fn(int $id): string => (string) $server->query('SELECT stored_name FROM application_documents WHERE id=' . $id)->fetchColumn();
    for ($i = 0; $i < 40; $i++) {
        [$code, $body] = $request('application.php?id=1');
        if ($code !== 0) break;
        usleep(100000);
    }
    $check($code === 200 && $hasForm($body, 32) && $hasForm($body, 33), 'Both unavailable scans must offer replacement.');
    $check($hasForm($body, 34), 'Manual revision must allow replacing a file even if AI passed it.');
    $check(!$hasForm($request('application.php?id=1', 3)[1], 32), 'Staff must not see applicant replacement controls.');
    $check($request('application.php?id=1', 2)[0] === 404, 'Another applicant must not see the application.');
    $check($upload(32, 1, 'bad-token')[0] === 419 && $storedName(32) === 'original-32.pdf', 'Missing/invalid CSRF must not replace a file.');
    $check($upload(32, 2)[0] === 302 && $storedName(32) === 'original-32.pdf', 'Another applicant must not replace a file.');
    $check($upload(32, 3)[0] === 302 && $storedName(32) === 'original-32.pdf', 'An admin must not upload as the owner.');
    $check($upload(32, 1, 'fixture-csrf', $workspace . '/invalid.txt')[0] === 302 && $storedName(32) === 'original-32.pdf', 'Unsupported MIME content must not replace a file.');

    $check($upload(32)[0] === 302 && $storedName(32) !== 'original-32.pdf', 'First replacement must save despite unavailable AI.');
    $check($server->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'For Review', 'Unavailable checks must remain nonblocking.');
    $check($server->query('SELECT scan_status FROM document_ai_scans WHERE document_id=33')->fetchColumn() === 'Failed', 'Replacing one file must preserve other scans.');
    [$code, $body] = $request('application.php?id=1');
    $check($code === 200 && $hasForm($body, 33), 'Second replacement must remain available after returning to review.');
    $check($hasForm($body, 32), 'An unscanned replacement must remain replaceable during review.');
    $check(!$hasForm($body, 34), 'A verified file must stay protected during normal review.');
    $check($upload(34)[0] === 302 && $storedName(34) === 'original-34.pdf', 'The endpoint must enforce verified-file protection too.');
    $check($upload(33)[0] === 302 && $storedName(33) !== 'original-33.pdf', 'Second unavailable document must accept a real multipart upload.');
    foreach ([32, 33] as $id) {
        $check(file_get_contents($workspace . '/storage/uploads/' . $storedName($id)) === file_get_contents($replacement), 'Replacement bytes must be saved.');
        $check(is_file($workspace . '/storage/uploads/original-' . $id . '.pdf'), 'Original bytes must remain in version history.');
    }
    $check((int) $server->query('SELECT COUNT(*) FROM document_versions')->fetchColumn() === 4, 'Both previous and replacement versions must be recorded.');
    $check((int) $server->query('SELECT COUNT(*) FROM document_scan_history')->fetchColumn() === 2, 'Previous scan results must be archived.');
    $check((int) $server->query('SELECT COUNT(*) FROM email_delivery_attempts')->fetchColumn() === 0, 'Tests must never send real email.');

    $server->exec("INSERT INTO document_ai_scans (document_id,application_id,scan_status,matches_expected_type,quality_score,confidence_score) VALUES (32,1,'Completed',0,90,99)");
    $check($hasForm($request('application.php?id=1')[1], 32), 'A completed blocking scan must allow correction during review.');
    $check($upload(33)[0] === 302 && $server->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'Needs Revision', 'Other blocking scan findings must keep the application in revision.');

    foreach (['Approved', 'Released', 'Rejected'] as $status) {
        $server->prepare('UPDATE applications SET status=? WHERE id=1')->execute([$status]);
        $before = $storedName(32);
        $check(!$hasForm($request('application.php?id=1')[1], 32), $status . ' must hide the replacement form.');
        $check($upload(32)[0] === 302 && $storedName(32) === $before, $status . ' must reject direct replacement requests.');
    }
    // Exercise the production API client against a second local HTTP server.
    copy($root . '/tests/fixtures/auto_scan_service.php', $workspace . '/mock-scan.php');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $scanAddress = stream_socket_get_name($socket, false);
    fclose($socket);
    $scanProcess = proc_open([PHP_BINARY, '-S', $scanAddress, '-t', '.', 'mock-scan.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $scanPipes, $workspace);
    $check(is_resource($scanProcess), 'Local scan fixture must start.');
    fclose($scanPipes[0]);
    for ($i = 0; $i < 40; $i++) {
        $socket = @stream_socket_client('tcp://' . $scanAddress, $socketError, $socketMessage, 0.1);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $scanConfig = [
        'db' => array_replace($settings, ['name' => $database]),
        'mail' => ['host' => '', 'username' => '', 'password' => ''],
        'openai' => ['api_key' => 'synthetic-key-only', 'base_url' => 'http://' . $scanAddress,
            'daily_scan_limit' => 100, 'allow_sensitive_documents' => false],
    ];
    file_put_contents($workspace . '/config.local.php', '<?php return ' . var_export($scanConfig, true) . ';');
    $scanRequests = static fn(): array => is_file($workspace . '/scan-requests.jsonl')
        ? array_map(static fn(string $line): array => json_decode($line, true), file($workspace . '/scan-requests.jsonl', FILE_IGNORE_NEW_LINES)) : [];
    $scanRow = static fn(int $id): array => $server->query('SELECT * FROM document_ai_scans WHERE document_id=' . $id)->fetch() ?: [];

    $server->exec("UPDATE applications SET status='Needs Revision' WHERE id=1");
    $check($upload(32)[0] === 302 && ($scanRow(32)['scan_status'] ?? '') === 'Completed', 'A real re-upload POST must trigger an automatic scan.');
    $check((int) $scanRow(32)['scanned_by'] === 1 && (int) $scanRow(32)['matches_expected_type'] === 1, 'Automatic results must be recorded for the applicant.');
    $check(count($scanRequests()) === 1, 'Only the replaced file is scanned.');
    $check($server->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'For Review', 'A corrected replacement clears blocking findings.');
    [$code, $body] = $request('admin/review.php?id=1', 3);
    $check($code === 200 && str_contains($body, 'Automatic document scanning') && str_contains($body, 'action="scan-document.php"'), 'The admin must retain manual scan forms alongside automatic results.');
    $againButton = $manualButton($body, 32);
    $scanButton = $manualButton($body, 33);
    $check($againButton !== null && trim($againButton->textContent) === 'Scan again' && !$againButton->hasAttribute('disabled'), 'A completed scan must retain the enabled Scan again button.');
    $check($scanButton !== null && trim($scanButton->textContent) === 'AI scan' && !$scanButton->hasAttribute('disabled'), 'An unscanned file must retain the enabled AI scan button.');
    $request('application.php?id=1');
    $check(count($scanRequests()) === 1, 'Opening either review page must not scan a file again.');

    file_put_contents($workspace . '/bad-document.pdf', $pdf . '% invalid fixture');
    $server->exec("UPDATE applications SET status='Needs Revision' WHERE id=1");
    $check($upload(32, 1, 'fixture-csrf', $workspace . '/bad-document.pdf')[0] === 302, 'A new replacement must submit without admin action.');
    $check((int) $scanRow(32)['matches_expected_type'] === 0 && count($scanRequests()) === 2, 'Each replacement must get fresh findings.');
    $check($server->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'Needs Revision', 'An invalid replacement automatically requests revision.');
    $check((int) $server->query("SELECT COUNT(*) FROM notification_events WHERE title='Document correction required'")->fetchColumn() > 0, 'Automatic failure creates an applicant correction alert.');
    $check((int) $server->query('SELECT COUNT(*) FROM email_delivery_attempts')->fetchColumn() > 0, 'Automatic scan alerts attempt delivery after commit; the test SMTP config is disabled.');
    $check((int) $server->query("SELECT COUNT(*) FROM email_outbox WHERE status='sent'")->fetchColumn() === 0, 'The isolated test must not send real email.');

    $newSubmission = [
        'csrf_token' => 'fixture-csrf', 'business_name' => 'Automatic Scan Fixture',
        'business_type' => 'Retail', 'organization_type' => 'Sole Proprietorship',
        'tin' => '123456789', 'contact' => '09123456789', 'email' => 'owner@example.test',
        'address' => 'Synthetic test address', 'declared_capital' => '10000', 'declaration' => '1',
    ];
    foreach (document_definitions() as $key => $definition) {
        if ($definition[1] || $key === 'occupancy_doc') $newSubmission[$key] = new CURLFile($replacement, 'application/pdf', $key . '.pdf');
    }
    // A PNG verifies the image-input path as well as the PDF-input path.
    file_put_contents($workspace . '/fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGk0AAAAASUVORK5CYII='));
    $newSubmission['registration_doc'] = new CURLFile($workspace . '/fixture.png', 'image/png', 'registration.png');
    $newSubmission['health_results_doc'] = new CURLFile($replacement, 'application/pdf', 'sensitive.pdf');
    $check($request('apply.php', 1, $newSubmission)[0] === 302, 'A new application must submit through the real form handler.');
    $newId = (int) $server->query('SELECT MAX(id) FROM applications')->fetchColumn();
    $check($newId > 1, 'The submission must create a separate application.');
    $newDocuments = $server->query('SELECT d.document_type,s.scan_status,s.scanned_by FROM application_documents d LEFT JOIN document_ai_scans s ON s.document_id=d.id WHERE d.application_id=' . $newId)->fetchAll();
    foreach ($newDocuments as $row) {
        $check($row['document_type'] === 'health_results_doc'
            ? $row['scan_status'] === null
            : $row['scan_status'] === 'Completed' && (int) $row['scanned_by'] === 1,
            'Every eligible initial upload must be scanned automatically; sensitive scanning stays disabled.');
    }
    $check(in_array('input_image', array_column($scanRequests(), 'type'), true), 'Uploaded images must be scanned automatically.');

    $server->exec("UPDATE applications SET status='Approved',permit_number='BP-AUTO-TEST' WHERE id=1");
    $server->exec("UPDATE permit_fee_settings SET is_configured=1,lgu_name='Fixture LGU' WHERE id=1");
    $server->exec("UPDATE permit_business_type_rates SET is_active=1,renewal_lbt_rate_percent=1,mayors_permit_fee=12.34 WHERE business_type='Retail'");
    $server->exec("UPDATE permit_payment_policy SET policy_reference='Fixture approved policy',quarterly_enabled=1,quarterly_fee_keys='[\"local_business_tax\"]',installment_schedule='[{\"months\":0,\"day\":0},{\"months\":3,\"day\":0},{\"months\":6,\"day\":0},{\"months\":9,\"day\":0}]',allow_installment_release=1,release_installments=1 WHERE id=1");
    $effectiveDate=(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $expiryDate=(new DateTimeImmutable($effectiveDate))->modify('+1 year')->modify('-1 day')->format('Y-m-d');
    $renewal = array_replace($newSubmission, ['permit_number' => 'BP-AUTO-TEST', 'gross_sales' => '5000','payment_frequency'=>'Annual','requested_effective_date'=>$effectiveDate]);
    $missingFrequency=$renewal;unset($missingFrequency['payment_frequency']);
    $check($request('renew.php',1,$missingFrequency)[0]===200 && (int)$server->query('SELECT MAX(id) FROM applications')->fetchColumn()===$newId,'Renewal payment frequency and preview must be required on the server.');
    [$code,$preview]=$request('renew.php',1,array_replace($renewal,['intent'=>'preview']));
    preg_match('/name="quote_token" value="([a-f0-9]{64})"/',$preview,$quoteMatch);
    $check($code===200 && !empty($quoteMatch[1]) && str_contains($preview,'Server-calculated assessment preview'),'Renewal preview must display server-calculated totals before submission.');
    $renewal['quote_token']=$quoteMatch[1];
    $check($request('renew.php', 1, $renewal)[0] === 302, 'A renewal must submit through the real form handler.');
    $renewalId = (int) $server->query('SELECT MAX(id) FROM applications')->fetchColumn();
    $check($renewalId > $newId, 'A new renewal must be created.');
    $renewalScans = (int) $server->query("SELECT COUNT(*) FROM document_ai_scans WHERE application_id=$renewalId AND scan_status='Completed' AND scanned_by=1")->fetchColumn();
    $check($renewalScans === count($newDocuments) - 1, 'Every eligible renewal file must be scanned automatically.');
    $check($request('renew.php',1,$renewal)[0]===302 && (int)$server->query('SELECT MAX(id) FROM applications')->fetchColumn()===$renewalId,'Duplicate renewal submission must return the original application without another upload or scan.');

    file_put_contents($workspace . '/outage.pdf', $pdf . '% service unavailable');
    $server->exec("UPDATE applications SET status='Needs Revision' WHERE id=1");
    $check($upload(32, 1, 'fixture-csrf', $workspace . '/outage.pdf')[0] === 302 && $scanRow(32)['scan_status'] === 'Failed', 'A temporary API outage must retain the replacement and record the failed attempt.');
    $check(is_file($workspace . '/storage/uploads/' . $storedName(32)), 'The replacement must remain saved during an API outage.');

    $callsBeforeCap = count($scanRequests());
    $attempts = (int) $server->query("SELECT COUNT(*) FROM audit_logs WHERE action='ai_auto_scan_attempt' AND created_at>=CURDATE()")->fetchColumn();
    $scanConfig['openai']['daily_scan_limit'] = $attempts;
    file_put_contents($workspace . '/config.local.php', '<?php return ' . var_export($scanConfig, true) . ';');
    $check($upload(32)[0] === 302 && !$scanRow(32), 'Exhausted daily capacity must preserve the upload without extra API calls.');
    $check(count($scanRequests()) === $callsBeforeCap, 'The daily cap must be enforced.');
    $check(str_contains($request('admin/review.php?id=1', 3)[1], 'daily AI scan limit'), 'Staff must see why automatic scanning stopped.');
    $check((int) $server->query("SELECT COUNT(*) FROM audit_logs WHERE action='ai_scan_document'")->fetchColumn() === 0, 'No admin scanning was needed for any upload.');

    // Manual controls are optional, share capacity, and never scan on page views.
    $manualScan = static fn(int $id): array => $request('admin/scan-document.php', 3, ['document_id' => $id, 'csrf_token' => 'fixture-csrf']);
    $check($manualScan(32)[0] === 302 && !$scanRow(32) && count($scanRequests()) === $callsBeforeCap, 'Manual scanning must honor capacity already consumed by automatic scans.');
    $scanConfig['openai']['daily_scan_limit'] = 100;
    file_put_contents($workspace . '/config.local.php', '<?php return ' . var_export($scanConfig, true) . ';');
    $check($request('admin/scan-document.php', 3)[0] === 405, 'Manual scanning must reject GET requests.');
    $check($request('admin/scan-document.php', 3, ['document_id' => 32])[0] === 419, 'Manual scanning requires CSRF protection.');
    $check($request('admin/scan-document.php', 1, ['document_id' => 32, 'csrf_token' => 'fixture-csrf'])[0] === 302, 'Applicants must not invoke the manual admin endpoint.');
    $check(count($scanRequests()) === $callsBeforeCap && !$scanRow(32), 'Unauthorized and invalid requests must not scan or change findings.');

    $check($manualScan(32)[0] === 302 && ($scanRow(32)['scan_status'] ?? '') === 'Completed', 'The restored manual scan endpoint must save a successful assessment.');
    $check((int) $scanRow(32)['scanned_by'] === 3 && (int) $scanRow(32)['matches_expected_type'] === 1, 'Manual results must identify the admin who requested them.');
    $check(count($scanRequests()) === $callsBeforeCap + 1, 'One manual submission must make exactly one API request.');
    $check((int) $server->query("SELECT COUNT(*) FROM audit_logs WHERE action='ai_scan_document'")->fetchColumn() === 1, 'A successful manual scan must retain its audit trail.');
    $check($manualScan(32)[0] === 302 && count($scanRequests()) === $callsBeforeCap + 1, 'The existing 30-second rescan cooldown must prevent accidental repeat scans.');

    $server->exec("UPDATE document_ai_scans SET scanned_at=DATE_SUB(NOW(),INTERVAL 31 SECOND),summary='Earlier manual result to preserve' WHERE document_id=32");
    $versionsBefore = (int) $server->query('SELECT COUNT(*) FROM document_versions WHERE document_id=32')->fetchColumn();
    $check($manualScan(32)[0] === 302 && $scanRow(32)['scan_status'] === 'Completed' && count($scanRequests()) === $callsBeforeCap + 2, 'Scan again must run a fresh assessment after the cooldown.');
    $history = $server->query('SELECT h.result_json FROM document_scan_history h JOIN document_versions v ON v.id=h.version_id WHERE v.document_id=32')->fetchAll(PDO::FETCH_COLUMN);
    $check(count(array_filter($history, static fn(string $json): bool => (json_decode($json, true)['summary'] ?? '') === 'Earlier manual result to preserve')) === 1, 'Manual rescanning must archive the previous result.');
    $check((int) $server->query('SELECT COUNT(*) FROM document_versions WHERE document_id=32')->fetchColumn() === $versionsBefore, 'Manual rescanning must not create or replace uploaded file versions.');

    $sensitiveId = (int) $server->query("SELECT id FROM application_documents WHERE application_id=$newId AND document_type='health_results_doc'")->fetchColumn();
    $sensitiveButton = $manualButton($request('admin/review.php?id=' . $newId, 3)[1], $sensitiveId);
    $check($sensitiveId > 0 && $sensitiveButton !== null && $sensitiveButton->hasAttribute('disabled'), 'Sensitive medical files must retain the disabled manual scan control.');
    $check($manualScan($sensitiveId)[0] === 302 && !$scanRow($sensitiveId) && count($scanRequests()) === $callsBeforeCap + 2, 'Manual scanning must enforce medical-document privacy on the server too.');

    // Prepare an unscanned invalid replacement at the shared quota, then check
    // manual correction alerts with SMTP disabled in this isolated app copy.
    $manualCount = (int) $server->query("SELECT COUNT(*) FROM audit_logs WHERE action='ai_scan_document' AND created_at>=CURDATE()")->fetchColumn();
    $scanConfig['openai']['daily_scan_limit'] = $attempts + $manualCount;
    file_put_contents($workspace . '/config.local.php', '<?php return ' . var_export($scanConfig, true) . ';');
    $check($upload(33, 1, 'fixture-csrf', $workspace . '/bad-document.pdf')[0] === 302 && !$scanRow(33) && count($scanRequests()) === $callsBeforeCap + 2, 'Automatic scans must honor capacity consumed by manual scans too.');
    $scanConfig['openai']['daily_scan_limit'] = 100;
    file_put_contents($workspace . '/config.local.php', '<?php return ' . var_export($scanConfig, true) . ';');
    $alertsBefore = (int) $server->query('SELECT COUNT(*) FROM notification_events')->fetchColumn();
    $mailAttemptsBefore = (int) $server->query('SELECT COUNT(*) FROM email_delivery_attempts')->fetchColumn();
    $check($manualScan(33)[0] === 302 && $scanRow(33)['scan_status'] === 'Completed' && (int) $scanRow(33)['matches_expected_type'] === 0, 'Manual scans must persist document-correction findings.');
    $check((int) $server->query('SELECT COUNT(*) FROM notification_events')->fetchColumn() === $alertsBefore + 1, 'Manual correction findings must queue a new notification.');
    $check((int) $server->query('SELECT COUNT(*) FROM email_delivery_attempts')->fetchColumn() === $mailAttemptsBefore + 2, 'Manual correction alerts must immediately attempt applicant and admin email delivery.');
    $check((int) $server->query("SELECT COUNT(*) FROM email_outbox WHERE status='sent'")->fetchColumn() === 0, 'All manual scan email tests must use disabled SMTP, without sending real messages.');

    // Render representative public URLs after the frontend/backend split.
    // All records and sessions below still belong only to this temporary DB.
    $server->exec("INSERT INTO payments (id,application_id,amount,status,receipt_number,paid_at,verified_by,proof_original_name,proof_stored_name,proof_mime_type)
        VALUES (1,1,1500,'Paid','RCP-TEST-SPLIT',NOW(),3,'proof.pdf','proof.pdf','application/pdf')");
    file_put_contents($workspace . '/storage/uploads/proof.pdf', $pdf);
    $server->exec("UPDATE applications SET status='Released',stage=4,permit_number='BP-AUTO-TEST' WHERE id=1");
    $server->prepare("UPDATE applications SET permit_effective_date=?,permit_expiration_date=?,validity_source='bplo',validity_confirmed_at=NOW() WHERE id=1")->execute([$effectiveDate,$expiryDate]);
    $server->exec("INSERT INTO application_status_history (application_id,status,changed_by,notes) VALUES (1,'Released',3,'Synthetic release for page checks.')");
    foreach (['dashboard.php', 'application.php?id=1', 'apply.php', 'renew.php', 'track.php?reference=BPL-TEST-UPLOAD', 'payments.php', 'payment.php?application_id=1', 'receipt.php?id=1', 'certificate.php?id=1', 'document-history.php?application_id=1'] as $path) {
        [$code, $body] = $request($path);
        $check($code === 200 && str_contains($body, '<html'), 'Applicant page must still render at its original URL: ' . $path);
        $check(str_contains($body, 'frontend/assets/styles.css') && str_contains($body, 'frontend/assets/app.js'), 'Page must use the separated frontend assets: ' . $path);
    }
    foreach (['admin/index.php', 'admin/review.php?id=1', 'admin/analytics.php', 'admin/assignments.php', 'admin/reports.php', 'admin/fee-settings.php', 'admin/users.php', 'admin/payments.php', 'admin/payment.php?id=1'] as $path) {
        [$code, $body] = $request($path, 3);
        $check($code === 200 && str_contains($body, '<html'), 'Admin page must still render at its original URL: ' . $path);
        $check(str_contains($body, 'frontend/assets/styles.css') && str_contains($body, 'frontend/assets/app.js'), 'Admin page must use the separated frontend assets: ' . $path);
    }
    foreach (['login.php', 'register.php', 'forgot-password.php', 'setup-admin.php'] as $path) {
        [$code, $body] = $request($path, 0);
        $check($code === 200 && str_contains($body, 'frontend/assets/styles.css'), 'Guest page must keep its public route and styling: ' . $path);
    }
    $check($request('verify-permit.php', 0)[0] === 404, 'Invalid permit verification must still render a not-found response.');
    $verificationToken = $server->query('SELECT token FROM permit_verification WHERE application_id=1')->fetchColumn();
    $check($request('verify-permit.php?token=' . $verificationToken, 0)[0] === 200, 'Public verification must still render a valid released permit.');
    $check($request('document.php?id=32')[0] === 200 && $request('document.php?id=32', 2)[0] === 404, 'Document downloads must retain owner authorization.');
    $versionId = (int) $server->query('SELECT id FROM document_versions WHERE document_id=32 ORDER BY id LIMIT 1')->fetchColumn();
    $check($request('document-version.php?id=' . $versionId)[0] === 200, 'Historical downloads must retain their original route.');
    $check($request('payment-proof.php?id=1', 3)[0] === 200, 'Payment proof downloads must retain their original route.');
    foreach (['api/notifications.php', 'api/search-suggestions.php?q=Upload'] as $path) {
        [$code, $body] = $request($path);
        $check($code === 200 && is_array(json_decode($body, true)), 'JSON endpoints must retain their public routes: ' . $path);
    }
    $check($request('api/reupload-document.php', 1, ['document_id' => 32])[0] === 419, 'Separated upload handling must keep CSRF protection.');
    foreach (['backend/controllers/apply.php', 'backend/controllers/admin/scan-document.php', 'backend/controllers/api/reupload-document.php', 'backend/includes/functions.php', 'backend/includes/bootstrap.php', 'frontend/views/login.php', 'frontend/views/admin/review.php', 'frontend/layouts/header.php'] as $path) {
        [$code, $body] = $request($path, 3);
        $check($code === 404 && $body === '', 'Internal PHP files must not be directly accessible: ' . $path);
    }
    foreach (['app.js', 'styles.css', 'admin-review.js', 'chatbot.js', 'permit-qr.js', 'logo-light.png', 'logo.png', 'vendor/qrcode.js'] as $asset) {
        [$code, $body] = $request('frontend/assets/' . $asset, 0);
        $check($code === 200 && hash('sha256', $body) === hash_file('sha256', $root . '/frontend/assets/' . $asset), 'Frontend assets must be served intact: ' . $asset);
    }
    // Full HTTP renewal/installment workflow, still using only synthetic data.
    $quarterly=array_replace($renewal,['payment_frequency'=>'Quarterly','gross_sales'=>'10001','intent'=>'preview']);
    $server->exec('UPDATE permit_payment_policy SET quarterly_enabled=0 WHERE id=1');
    [$code,$body]=$request('renew.php',1,$quarterly);
    $check($code===200 && str_contains($body,'Quarterly payments are not enabled'),'Unconfigured quarterly payment is rejected by the server.');
    $server->exec('UPDATE permit_payment_policy SET quarterly_enabled=1 WHERE id=1');
    [$code,$body]=$request('renew.php',1,$quarterly);
    preg_match('/name="quote_token" value="([a-f0-9]{64})"/',$body,$quarterQuote);
    $check($code===200 && !empty($quarterQuote[1]) && str_contains($body,'112.35') && str_contains($body,'25.01'),'Quarterly preview shows exact assessment and rounding-adjusted final installment.');
    $quarterly['quote_token']=$quarterQuote[1];$quarterly['intent']='submit';
    $beforeId=(int)$server->query('SELECT MAX(id) FROM applications')->fetchColumn();
    $check($request('renew.php',1,array_replace($quarterly,['gross_sales'=>'20000']))[0]===200 && (int)$server->query('SELECT MAX(id) FROM applications')->fetchColumn()===$beforeId,'Changing assessment inputs requires a fresh preview, even with a valid token.');
    $quarterly['assessed_amount']='0.01';
    $check($request('renew.php',1,$quarterly)[0]===302,'Quarterly renewal submits with a validated preview.');
    $quarterApplication=(int)$server->query('SELECT MAX(id) FROM applications')->fetchColumn();
    $check($quarterApplication>$beforeId && $server->query('SELECT payment_frequency FROM applications WHERE id='.$quarterApplication)->fetchColumn()==='Quarterly','Quarterly selection is stored on the renewal.');
    $decision=['csrf_token'=>'fixture-csrf','application_id'=>$quarterApplication,'decision'=>'Approved','admin_notes'=>'Fixture approval','assessed_amount'=>'0.01','permit_effective_date'=>$effectiveDate,'permit_expiration_date'=>$expiryDate,'confirm_permit_dates'=>'1'];
    $noConfirmation=$decision;unset($noConfirmation['confirm_permit_dates']);
    $check($request('admin/review.php?id='.$quarterApplication,3,$noConfirmation)[0]===200 && (int)$server->query('SELECT COUNT(*) FROM payments WHERE application_id='.$quarterApplication)->fetchColumn()===0,'Approval must require BPLO date confirmation.');
    $check($request('admin/review.php?id='.$quarterApplication,3,$decision)[0]===302,'BPLO can approve confirmed one-year dates and create the installment schedule.');
    $quarterPayment=$server->query('SELECT * FROM payments WHERE application_id='.$quarterApplication)->fetch();
    $check($quarterPayment && $quarterPayment['amount']==='112.35','Client-supplied assessed amounts must not override server calculations.');
    $quarterRows=$server->query('SELECT * FROM payment_installments WHERE payment_id='.(int)$quarterPayment['id'].' ORDER BY sequence_no')->fetchAll();
    $check(count($quarterRows)===5 && array_column($quarterRows,'amount')===['12.34','25.00','25.00','25.00','25.01'],'Approval creates four rounded installments and a separate upfront fee.');
    $check($request('admin/review.php?id='.$quarterApplication,3,$decision)[0]===302 && (int)$server->query('SELECT COUNT(*) FROM payment_installments WHERE payment_id='.(int)$quarterPayment['id'])->fetchColumn()===5,'Repeated approval must preserve existing installments.');
    $quarterPath='payment.php?application_id='.$quarterApplication;
    [$code,$body]=$request($quarterPath);
    $check($code===200 && str_contains($body,'Quarter 4') && str_contains($body,'112.35'),'Applicant sees installment amounts, due dates, and annual balance.');
    $check($request($quarterPath,2)[0]===404,'Other applicants cannot view the installment plan.');
    $installmentToken=static function(string $html,int $id):string {
        $dom=new DOMDocument();@$dom->loadHTML($html);
        return (string)(new DOMXPath($dom))->evaluate('string(//form[input[@name="installment_id" and @value="'.$id.'"]]/input[@name="submission_token"]/@value)');
    };
    $upfrontId=(int)$quarterRows[0]['id'];
    $evidence=['csrf_token'=>'fixture-csrf','application_id'=>$quarterApplication,'installment_id'=>$upfrontId,'submission_token'=>$installmentToken($body,$upfrontId),'payment_method'=>'GCash','payer_name'=>'Owner','payment_reference'=>'HTTP-UPFRONT','payment_proof'=>new CURLFile($replacement,'application/pdf','upfront-proof.pdf')];
    $check(strlen($evidence['submission_token'])===64,'Each installment form has an idempotent submission token.');
    $check($request($quarterPath,1,array_replace($evidence,['csrf_token'=>'bad']))[0]===419,'Installment evidence requires CSRF protection.');
    $check($request($quarterPath,1,$evidence)[0]===302,'A real multipart proof is accepted for the selected installment.');
    $check($request($quarterPath,1,$evidence)[0]===302 && (int)$server->query('SELECT COUNT(*) FROM payment_evidence WHERE installment_id='.$upfrontId)->fetchColumn()===1,'Double submission must not duplicate installment evidence.');
    $staffDecision=['csrf_token'=>'fixture-csrf','payment_id'=>$quarterPayment['id'],'installment_id'=>$upfrontId,'submission_version'=>1,'action'=>'verify','admin_notes'=>'Evidence verified'];
    $check($request('admin/payment-action.php',1,$staffDecision)[0]===302 && $server->query('SELECT status FROM payment_installments WHERE id='.$upfrontId)->fetchColumn()==='Pending','Applicant cannot verify their own installment.');
    $check($request('admin/payment-action.php',4,$staffDecision)[0]===302 && $server->query('SELECT status FROM payment_installments WHERE id='.$upfrontId)->fetchColumn()==='Paid','Treasurer can verify a single installment through the original endpoint.');
    $check($server->query('SELECT status FROM payments WHERE id='.(int)$quarterPayment['id'])->fetchColumn()==='Partially Paid','One installment does not mark the annual payment Paid.');
    $release=array_replace($decision,['decision'=>'Released']);
    $check($request('admin/review.php?id='.$quarterApplication,3,$release)[0]===200 && $server->query('SELECT status FROM applications WHERE id='.$quarterApplication)->fetchColumn()==='Approved','Release endpoint blocks unpaid first-quarter requirement.');
    $quarterOneId=(int)$quarterRows[1]['id'];
    $body=$request($quarterPath)[1];
    $evidence=array_replace($evidence,['installment_id'=>$quarterOneId,'submission_token'=>$installmentToken($body,$quarterOneId),'payment_reference'=>'HTTP-QUARTER-ONE']);
    $check($request($quarterPath,1,$evidence)[0]===302,'First-quarter evidence can be submitted separately.');
    $staffDecision['installment_id']=$quarterOneId;
    $check($request('admin/payment-action.php',3,$staffDecision)[0]===302 && $server->query('SELECT status FROM payment_installments WHERE id='.$quarterOneId)->fetchColumn()==='Paid','Authorized BPLO staff can verify installment evidence too.');
    $check($request('admin/review.php?id='.$quarterApplication,3,$release)[0]===302 && $server->query('SELECT status FROM applications WHERE id='.$quarterApplication)->fetchColumn()==='Released','LGU-enabled release succeeds after upfront and release-required installments are verified.');
    $check($server->query('SELECT status FROM payments WHERE id='.(int)$quarterPayment['id'])->fetchColumn()==='Partially Paid','Releasing the permit does not mark future quarterly installments paid.');
    [$code,$certificateBody]=$request('certificate.php?id='.$quarterApplication);
    $check($code===200 && str_contains($certificateBody,$effectiveDate) && str_contains($certificateBody,$expiryDate),'Certificate shows the BPLO-confirmed one-year dates for a partially paid permit.');
    $token=$server->query('SELECT token FROM permit_verification WHERE application_id='.$quarterApplication)->fetchColumn();
    $qrBody=$request('verify-permit.php?token='.$token,0)[1];
    $dashboardBody=$request('dashboard.php')[1];
    $check(str_contains($qrBody,$effectiveDate) && str_contains($qrBody,$expiryDate) && str_contains($dashboardBody,$effectiveDate) && str_contains($dashboardBody,$expiryDate),'Dashboard, certificate, and QR display the same stored dates.');
    [$code,$receiptBody]=$request('receipt.php?installment_id='.$upfrontId);
    $check($code===200 && str_contains($receiptBody,'covers Fees payable upfront only') && str_contains($receiptBody,'12.34'),'Each verified installment has its own correctly scoped receipt.');
    $check($request('receipt.php?installment_id='.$upfrontId,2)[0]===403 && $request('receipt.php?installment_id='.(int)$quarterRows[2]['id'])[0]===404,'Other applicants and unpaid installments cannot access verified receipts.');
    $check($request('payment-proof.php?installment_id='.$upfrontId,4)[0]===200 && $request('payment-proof.php?installment_id='.$upfrontId,2)[0]===403,'Proof downloads preserve financial-role and owner authorization.');
    $check($request('admin/reports.php?type=outstanding',4)[0]===200 && $request('admin/reports.php?type=overdue',4)[0]===200,'Treasurer can access outstanding and overdue financial reports.');
    $check($request('admin/fee-settings.php',4)[0]===302,'Treasurer cannot change the LGU release policy.');
    $body=$request($quarterPath)[1];$laterId=(int)$quarterRows[2]['id'];
    $evidence=array_replace($evidence,['installment_id'=>$laterId,'submission_token'=>$installmentToken($body,$laterId),'payment_reference'=>'HTTP-LATER-QUARTER']);
    $check($request($quarterPath,1,$evidence)[0]===302,'Applicants can pay later installments after permit release.');
    $staffDecision['installment_id']=$laterId;
    $check($request('admin/payment-action.php',4,$staffDecision)[0]===302,'Later installment verification works after release.');
    $term=$server->query('SELECT permit_effective_date,permit_expiration_date FROM applications WHERE id='.$quarterApplication)->fetch();
    $check($term['permit_effective_date']===$effectiveDate && $term['permit_expiration_date']===$expiryDate,'Paying a later installment cannot extend or shorten permit validity.');
    // Annual path must still require the whole verified assessment.
    $annualDecision=array_replace($decision,['application_id'=>$renewalId]);
    $invalidDate=array_replace($annualDecision,['permit_effective_date'=>'not-a-date','permit_expiration_date'=>'']);
    $check($request('admin/review.php?id='.$renewalId,3,$invalidDate)[0]===200,'Invalid permit dates return a validation page, not a server error.');
    $check($request('admin/review.php?id='.$renewalId,3,$annualDecision)[0]===302,'Annual renewal approves through the same date-confirming endpoint.');
    $annualPayment=$server->query('SELECT * FROM payments WHERE application_id='.$renewalId)->fetch();
    $annualRows=$server->query('SELECT * FROM payment_installments WHERE payment_id='.(int)$annualPayment['id'])->fetchAll();
    $check(count($annualRows)===1 && $annualRows[0]['amount']==='62.34','Annual approval creates exactly one whole-assessment obligation.');
    $annualRelease=array_replace($annualDecision,['decision'=>'Released']);
    $check($request('admin/review.php?id='.$renewalId,3,$annualRelease)[0]===200,'Annual release refuses unpaid obligations.');
    $annualPath='payment.php?application_id='.$renewalId;$annualChildId=(int)$annualRows[0]['id'];
    $annualBody=$request($annualPath)[1];
    $annualEvidence=array_replace($evidence,['application_id'=>$renewalId,'installment_id'=>$annualChildId,'submission_token'=>$installmentToken($annualBody,$annualChildId),'payment_reference'=>'HTTP-ANNUAL']);
    $check($request($annualPath,1,$annualEvidence)[0]===302,'Annual evidence submits through the installment endpoint.');
    $check($request('admin/review.php?id='.$renewalId,3,$annualRelease)[0]===200,'Annual evidence alone cannot release the permit.');
    $annualVerify=array_replace($staffDecision,['payment_id'=>$annualPayment['id'],'installment_id'=>$annualChildId]);
    $check($request('admin/payment-action.php',4,$annualVerify)[0]===302 && $server->query('SELECT status FROM payments WHERE id='.(int)$annualPayment['id'])->fetchColumn()==='Paid','Full annual verification marks the annual assessment paid.');
    $check($request('admin/review.php?id='.$renewalId,3,$annualRelease)[0]===302 && $server->query('SELECT status FROM applications WHERE id='.$renewalId)->fetchColumn()==='Released','Verified annual assessment permits release.');
    $check($request('receipt.php?installment_id='.$annualChildId)[0]===200,'Annual verified payment also receives its individual receipt.');
    // Saving new policy must neither rewrite existing schedules nor bypass roles/CSRF.
    $policyPost=['csrf_token'=>'fixture-csrf','settings_section'=>'permit_policy','policy_reference'=>'New fixture policy','validity_mode'=>'one_year','schedule_mode'=>'offsets','annual_due_days'=>0,'upfront_due_days'=>0,'payment_reminder_days'=>10,'expiration_reminder_days'=>45,'release_installments'=>4];
    $originalPlan=$server->query('SELECT plan_snapshot FROM payments WHERE id='.(int)$quarterPayment['id'])->fetchColumn();
    $check($request('admin/fee-settings.php',4,$policyPost)[0]===302 && $server->query('SELECT policy_reference FROM permit_payment_policy')->fetchColumn()==='Fixture approved policy','Treasurer cannot save policy settings.');
    $check($request('admin/fee-settings.php',3,array_replace($policyPost,['csrf_token'=>'bad']))[0]===419,'Policy updates require CSRF.');
    $check($request('admin/fee-settings.php',3,$policyPost)[0]===302 && (int)$server->query('SELECT quarterly_enabled FROM permit_payment_policy')->fetchColumn()===0,'Admin can explicitly disable quarterly for future quotes.');
    $check($server->query('SELECT plan_snapshot FROM payments WHERE id='.(int)$quarterPayment['id'])->fetchColumn()===$originalPlan,'Saving policy preserves previous assessment snapshots and release rules.');
    $check((int) $server->query("SELECT COUNT(*) FROM email_outbox WHERE status='sent'")->fetchColumn() === 0, 'Page regression checks must not send real email.');
    echo 'Page, upload, re-upload, and manual scan HTTP regression tests passed (' . $checks . " checks; isolated data and local fake AI only).\n";
} catch (Throwable $exception) {
    if (isset($log) && is_file($log)) fwrite(STDERR, file_get_contents($log));
    throw $exception;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_resource($scanProcess)) { proc_terminate($scanProcess); proc_close($scanProcess); }
    if ($created && preg_match('/^permit_test_reupload_[a-f0-9]{12}$/D', $database)) {
        $server->exec('DROP DATABASE `' . $database . '`');
    }
    if (is_dir($workspace) && realpath(dirname($workspace)) === realpath(sys_get_temp_dir()) && basename($workspace) === $database) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($workspace);
    }
}
