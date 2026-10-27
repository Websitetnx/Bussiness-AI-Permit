<?php
declare(strict_types=1);

// Real HTTP and multipart requests against a disposable database and app copy.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require $root . '/includes/functions.php';
require __DIR__ . '/support/project_fixture.php';
$settings = app_config('db');
$database = 'permit_test_mobile_' . bin2hex(random_bytes(6));
$workspace = sys_get_temp_dir() . '/' . $database;
$pdo = new PDO('mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';charset=utf8mb4', $settings['user'], $settings['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$process = null;
$created = false;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
$nodes = static function (array $list) use (&$nodes): array {
    $out = [];
    foreach ($list as $node) {
        $out[] = $node;
        array_push($out, ...$nodes($node['children'] ?? []));
        foreach ($node['rows'] ?? [] as $row) foreach ($row as $cell) array_push($out, ...$nodes($cell));
    }
    return $out;
};
try {
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $pdo->exec('USE `' . $database . '`');
    $schema = preg_replace('/\ACREATE DATABASE.*?;\s*USE permitflow;\s*/s', '', file_get_contents($root . '/database/schema.sql'), 1, $replaced);
    $check($replaced === 1, 'Test schema must not select the live database.');
    $pdo->exec($schema);
    mkdir($workspace . '/storage/uploads', 0700, true);
    mkdir($workspace . '/sessions', 0700, true);
    copy_project_test_fixture($root, $workspace);
    file_put_contents($workspace . '/config.local.php', '<?php return ' . var_export([
        'db' => array_replace($settings, ['name' => $database]),
        'app_url' => 'https://permit.example.test',
        'mail' => ['host' => '', 'username' => '', 'password' => ''],
        'openai' => ['api_key' => ''],
    ], true) . ';');
    $hash = password_hash('Mobile-fixture-123!', PASSWORD_DEFAULT);
    foreach ([1 => 'applicant', 2 => 'applicant', 3 => 'admin', 4 => 'treasurer'] as $id => $role) {
        $pdo->prepare('INSERT INTO users (id,name,email,password_hash,role) VALUES (?,?,?,?,?)')->execute([$id, 'Test User ' . $id, 'test' . $id . '@example.test', $hash, $role]);
    }
    $pdo->exec("INSERT INTO businesses (id,user_id,business_name,business_type,organization_type,tin,contact,email,address) VALUES (1,1,'Mobile Fixture','Retail','Sole Proprietorship','123456789','09123456789','test1@example.test','Synthetic test address')");
    $pdo->exec("INSERT INTO applications (id,user_id,business_id,reference,status,declared_capital) VALUES (1,1,1,'BPL-MOBILE-TEST','For Review',10000)");
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    file_put_contents($workspace . '/storage/uploads/test.pdf', $pdf);
    file_put_contents($workspace . '/upload.pdf', $pdf);
    $pdo->prepare("INSERT INTO application_documents (id,application_id,document_type,original_name,stored_name,mime_type,file_size) VALUES (1,1,'registration_doc','test.pdf','test.pdf','application/pdf',?)")->execute([strlen($pdf)]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    file_put_contents($workspace . '/router.php', '<?php require_once ' . var_export($root . '/vendor/autoload.php', true) . '; session_save_path(__DIR__."/sessions"); return false;');
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', '.', 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/http.log', 'a'], 2 => ['file', $workspace . '/http.log', 'a']], $pipes, $workspace);
    $check(is_resource($process), 'Start isolated HTTP server.');
    fclose($pipes[0]);
    $request = static function (string $route, array &$client, ?array $post = null, bool $csrf = true) use ($address): array {
        [$path, $query] = array_pad(explode('?', $route, 2), 2, '');
        $curl = curl_init('http://' . $address . '/api/mobile.php?route=' . rawurlencode($path) . ($query ? '&' . $query : ''));
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['X-Permit-Session: ' . ($client['session'] ?? '')]]);
        if ($post !== null) {
            if ($csrf) $post['csrf_token'] = $client['csrf'] ?? '';
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
        }
        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        $data = json_decode($body, true);
        if (is_array($data)) {
            foreach (['session', 'csrf'] as $key) if (!empty($data[$key])) $client[$key] = $data[$key];
        }
        return ['status' => $status, 'data' => $data, 'body' => $body, 'type' => $type];
    };
    $guest = [];
    for ($i = 0; $i < 50; $i++) { if ($request('login.php', $guest)['status']) break; usleep(100000); }
    $result = $request('login.php', $guest);
    $check($result['status'] === 200 && isset($result['data']['screen']['nodes']), 'Login is native screen JSON.');
    $check(strlen($guest['session']) >= 22 && strlen($guest['csrf']) === 64, 'Issue session and CSRF token.');
    $check($request('config.php', $guest)['status'] === 404, 'Do not dispatch unlisted files.');
    $check($request('../config.php', $guest)['status'] === 404, 'Reject traversal.');
    $check($request('dashboard.php', $guest)['data']['redirect'] === 'login.php', 'Guests redirected in JSON.');
    $check($request('login.php', $guest, ['email' => 'test1@example.test', 'password' => 'Mobile-fixture-123!'], false)['status'] === 419, 'Sign-in requires CSRF.');
    $clients = [];
    foreach ([1, 2, 3, 4] as $id) {
        $clients[$id] = [];
        $request('login.php', $clients[$id]);
        $old = $clients[$id]['session'];
        $login = $request('login.php', $clients[$id], ['email' => 'test' . $id . '@example.test', 'password' => 'Mobile-fixture-123!']);
        $check($login['status'] === 200 && isset($login['data']['redirect']) && $login['data']['user']['id'] === $id, 'Authenticate role ' . $id);
        $check($old !== $clients[$id]['session'], 'Rotate authenticated session ID.');
        $check(!str_contains($login['body'], $hash) && !str_contains($login['body'], 'password_hash'), 'Exclude password hashes.');
        $oldClient = ['session' => $old];
        $check($request('dashboard.php', $oldClient)['data']['redirect'] === 'login.php', 'Old session invalidated.');
    }
    foreach (['dashboard.php', 'apply.php', 'renew.php', 'track.php', 'payments.php', 'application.php?id=1', 'document-history.php?application_id=1'] as $route) {
        $result = $request($route, $clients[1]);
        $check($result['status'] === 200 && isset($result['data']['screen']), 'Applicant native screen: ' . $route);
    }
    $check($request('application.php?id=1', $clients[2])['status'] === 404, 'Other applicants cannot read applications.');
    $check($request('document.php?id=1', $clients[2])['status'] === 404, 'Other applicants cannot read files.');
    $result = $request('document.php?id=1', $clients[1]);
    $check($result['body'] === $pdf && str_contains($result['type'], 'application/pdf'), 'Authorized downloads preserve exact bytes.');
    $check($request('admin/index.php', $clients[1])['data']['redirect'] === 'dashboard.php', 'Applicant denied administration.');
    $check($request('admin/index.php', $clients[4])['data']['redirect'] === 'admin/payments.php', 'Treasurer denied application review.');
    $check($request('admin/reports.php?type=applications', $clients[4])['status'] === 403, 'Treasurer denied nonfinancial reports.');
    foreach (['admin/index.php', 'admin/review.php?id=1', 'admin/payments.php', 'admin/analytics.php', 'admin/users.php', 'admin/fee-settings.php', 'admin/assignments.php', 'admin/reports.php'] as $route) {
        $result = $request($route, $clients[3]);
        $check($result['status'] === 200 && isset($result['data']['screen']), 'Administrator native screen: ' . $route);
    }
    $result = $request('admin/reports.php?type=collections&format=csv', $clients[4]);
    $check($result['status'] === 200 && str_contains($result['type'], 'text/csv'), 'Treasurer may export collections.');
    $check($request('api/notifications.php?action=mark_all_read', $clients[1])['status'] === 405, 'GET cannot change notification state.');
    $check($request('api/notifications.php', $clients[1], ['action' => 'mark_all_read'], false)['status'] === 419, 'Notification writes require CSRF.');
    $check($request('api/notifications.php', $clients[1], ['action' => 'mark_all_read'])['data']['data']['ok'], 'Native notification update.');

    $decision = $request('admin/review.php?id=1', $clients[3], ['application_id' => '1', 'decision' => 'Needs Revision', 'admin_notes' => 'Replace the registration document.']);
    $check(($decision['data']['redirect'] ?? '') === 'admin/review.php?id=1', 'Native staff decision redirects correctly.');
    $check($pdo->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'Needs Revision', 'Staff decision persisted by existing controller.');
    $check($request('api/notifications.php?action=list', $clients[1])['data']['data']['unread_count'] > 0, 'Decision notifies applicant.');

    $file = static fn(): CURLFile => new CURLFile($workspace . '/upload.pdf', 'application/pdf', 'mobile.pdf');
    $submission = ['business_name' => 'Native Upload Business', 'business_type' => 'Retail', 'organization_type' => 'Sole Proprietorship',
        'tin' => '123456789', 'contact' => '09123456789', 'email' => 'test1@example.test', 'address' => 'Synthetic mobile address', 'declared_capital' => '5000', 'declaration' => 'on'];
    foreach (['registration_doc', 'bfp_application_doc', 'bfp_questionnaire_doc', 'consent_form_doc', 'occupancy_doc'] as $name) $submission[$name] = $file();
    $result = $request('apply.php', $clients[1], $submission);
    $check(isset($result['data']['redirect']) && str_starts_with($result['data']['redirect'], 'application.php?id='), 'Native multipart application accepted.');
    $newId = (int) substr($result['data']['redirect'], strlen('application.php?id='));
    $check((int) $pdo->query('SELECT COUNT(*) FROM application_documents WHERE application_id=' . $newId)->fetchColumn() === 5, 'All native attachments stored.');
    $replacement = $request('api/reupload-document.php', $clients[1], ['application_id' => '1', 'document_id' => '1', 'replacement_file' => $file()]);
    $check(isset($replacement['data']['redirect']), 'Native re-upload uses the protected controller.');

    // Exercise a complete approval -> installment -> receipt -> release -> renewal cycle.
    $pdo->exec("UPDATE permit_fee_settings SET is_configured=1,lgu_name='Test LGU' WHERE id=1");
    $pdo->exec("UPDATE permit_business_type_rates SET new_lbt_rate_percent=1,renewal_lbt_rate_percent=1,mayors_permit_fee=100 WHERE business_type='Retail'");
    $pdo->exec("UPDATE permit_payment_policy SET policy_reference='TEST ONLY',annual_due_days=30 WHERE id=1");
    $effective = date('Y-m-d');
    $expiration = date('Y-m-d', strtotime($effective . ' +1 year -1 day'));
    $approval = ['application_id' => '1', 'decision' => 'Approved', 'admin_notes' => 'Test approval',
        'permit_effective_date' => $effective, 'permit_expiration_date' => $expiration, 'confirm_permit_dates' => 'on'];
    $result = $request('admin/review.php?id=1', $clients[3], $approval);
    $check(isset($result['data']['redirect']) && $pdo->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'Approved', 'Native approval creates a server-assessed payment plan.');
    $paymentId = (int) $pdo->query('SELECT id FROM payments WHERE application_id=1')->fetchColumn();
    $installmentId = (int) $pdo->query('SELECT id FROM payment_installments WHERE payment_id=' . $paymentId)->fetchColumn();
    $result = $request('payment.php?application_id=1', $clients[1]);
    $paymentFields = array_column(array_filter($nodes($result['data']['screen']['nodes']), static fn(array $node): bool => $node['kind'] === 'field'), null, 'name');
    $check(isset($paymentFields['submission_token']) && $paymentFields['installment_id']['value'] === (string) $installmentId, 'Native payment screen includes server-issued installment token.');
    $result = $request('payment.php?application_id=1', $clients[1], ['application_id' => '1', 'installment_id' => (string) $installmentId,
        'submission_token' => $paymentFields['submission_token']['value'], 'payment_method' => 'City Treasurer Counter', 'payer_name' => 'Test Payer', 'payment_reference' => 'MOBILE-TEST-COUNTER']);
    $check(isset($result['data']['redirect']), 'Native installment evidence submission.');
    $release = array_replace($approval, ['decision' => 'Released']);
    $request('admin/review.php?id=1', $clients[3], $release);
    $check($pdo->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'Approved', 'Release remains blocked until payment is verified.');
    $version = $pdo->query('SELECT submission_version FROM payment_installments WHERE id=' . $installmentId)->fetchColumn();
    $result = $request('admin/payment-action.php', $clients[4], ['payment_id' => (string) $paymentId, 'installment_id' => (string) $installmentId,
        'submission_version' => (string) $version, 'action' => 'verify', 'admin_notes' => 'Test verification']);
    $check(isset($result['data']['redirect']) && $pdo->query('SELECT status FROM payment_installments WHERE id=' . $installmentId)->fetchColumn() === 'Paid', 'Treasurer verifies the individual installment.');
    $check(isset($request('receipt.php?installment_id=' . $installmentId, $clients[1])['data']['screen']), 'Applicant sees native verified receipt.');
    $check($request('receipt.php?installment_id=' . $installmentId, $clients[2])['status'] === 403, 'Receipt ownership enforced.');
    $request('admin/review.php?id=1', $clients[3], $release);
    $check($pdo->query('SELECT status FROM applications WHERE id=1')->fetchColumn() === 'Released', 'Verified permit can be released.');
    $result = $request('certificate.php?id=1', $clients[1]);
    $check(isset($result['data']['screen']) && count(array_filter($nodes($result['data']['screen']['nodes']), static fn(array $node): bool => $node['kind'] === 'qr')) === 1, 'Released native certificate contains verification QR data.');
    $permitNumber = $pdo->query('SELECT permit_number FROM applications WHERE id=1')->fetchColumn();
    $renewal = ['permit_number' => $permitNumber, 'intent' => 'preview', 'gross_sales' => '20000', 'payment_frequency' => 'Annual',
        'requested_effective_date' => date('Y-m-d', strtotime($effective . ' +1 year')), 'contact' => '09123456789', 'email' => 'test1@example.test',
        'address' => 'Synthetic renewed address', 'declaration' => 'on'];
    $result = $request('renew.php', $clients[1], $renewal);
    $quoteFields = array_column(array_filter($nodes($result['data']['screen']['nodes'] ?? []), static fn(array $node): bool => $node['kind'] === 'field'), null, 'name');
    $check(!empty($quoteFields['quote_token']['value']), 'Native renewal preview returns the server-calculated quote token.');
    $renewal['intent'] = 'submit';
    $renewal['quote_token'] = $quoteFields['quote_token']['value'];
    foreach (['registration_doc', 'bfp_application_doc', 'bfp_questionnaire_doc', 'consent_form_doc', 'occupancy_affidavit_doc'] as $name) $renewal[$name] = $file();
    $result = $request('renew.php', $clients[1], $renewal);
    $check(isset($result['data']['redirect']) && (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE application_type='Renewal'")->fetchColumn() === 1, 'Native renewal submits with its quote and occupancy alternative.');

    $pdo->exec('UPDATE users SET is_active=0 WHERE id=2');
    $check($request('api/notifications.php', $clients[2])['data']['redirect'] === 'login.php', 'Inactive users lose JSON endpoint access.');
    $check($request('logout.php', $clients[1])['status'] === 405, 'Sign out requires POST.');
    $oldSession = $clients[1];
    $check($request('logout.php', $clients[1], [])['data']['redirect'] === 'login.php', 'Native sign out.');
    $check($request('dashboard.php', $oldSession)['data']['redirect'] === 'login.php', 'Logout invalidates previous token.');
    echo "Mobile HTTP integration passed ({$checks} checks). No live records, uploads, or emails changed.\n";
} catch (Throwable $exception) {
    if (is_file($workspace . '/http.log')) fwrite(STDERR, implode('', array_slice(file($workspace . '/http.log'), -25)));
    throw $exception;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($created && preg_match('/^permit_test_mobile_[a-f0-9]{12}$/D', $database)) $pdo->exec('DROP DATABASE `' . $database . '`');
    // Restrict recursive cleanup to this test's own resolved temporary directory.
    $resolved = realpath($workspace);
    $temporary = realpath(sys_get_temp_dir());
    if ($resolved && $temporary && dirname($resolved) === $temporary && basename($resolved) === $database) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($resolved);
    }
}
