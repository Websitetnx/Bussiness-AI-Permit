<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/mobile/screen.php';
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$parser = new MobileScreen('admin/review.php?id=42', '/permit office');
$html = <<<'HTML'
<!doctype html><html><head><title>Review | PERMIT</title></head><body>
<aside class="sidebar"><nav><a href="/permit%20office/admin/index.php"><span>ICON</span>Overview</a></nav></aside>
<div id="toastRegion"><div>Saved decision</div></div><section class="page active">
<h2>Résumé ₱</h2><script>secret();</script><form method="post" action="payment-action.php">
<input type="hidden" name="csrf_token" value="token"><input type="hidden" name="payment_id" value="42">
<label class="field">Decision<select name="decision"><option value="Approved">Approve</option><option value="Released" disabled selected>Release</option></select></label>
<label class="field">Password<input type="password" name="password" value="must-not-leak"></label>
<label><input type="checkbox" name="fees[]" value="lbt" checked>Local business tax</label>
<textarea name="admin_notes">Check originals</textarea><input type="file" name="payment_proof" accept=".pdf">
<label class="upload-card"><span>Required</span><span>⇧</span><strong>Registration</strong><input type="file" name="registration_doc"><em>Choose file</em></label>
<button name="action" value="verify">Verify</button><button name="action" value="reject" formnovalidate>Reject</button>
</form><a href="?id=43">Next</a><a href="javascript:alert(1)">Unsafe</a>
<table><thead><tr><th>Amount</th></tr></thead><tbody><tr><td>₱100</td></tr></tbody></table>
<div class="permit-qr" data-permit-verification="https://example.test/verify-permit.php?token=test"></div>
</section></body></html>
HTML;
$screen = $parser->parse($html);
$flat = [];
$walk = static function (array $nodes) use (&$walk, &$flat): void {
    foreach ($nodes as $node) {
        $flat[] = $node;
        $walk($node['children'] ?? []);
        foreach ($node['rows'] ?? [] as $row) foreach ($row as $cell) $walk($cell);
    }
};
$walk($screen['nodes']);
$find = static fn(string $kind): array => array_values(array_filter($flat, static fn(array $node): bool => $node['kind'] === $kind));
$check($screen['title'] === 'Review', 'Extract native screen title.');
$check($screen['navigation'][0] === ['label' => 'Overview', 'href' => 'admin/index.php'], 'Resolve encoded project navigation and remove icons.');
$check($screen['messages'] === ['Saved decision'], 'Preserve validation and success notices.');
$check($find('form')[0]['href'] === 'admin/payment-action.php', 'Resolve relative admin form action.');
$check($find('link')[0]['href'] === 'admin/review.php?id=43', 'Resolve query-only links against current screen.');
$check(count($find('link')) === 1, 'Reject executable links.');
$fields = array_column($find('field'), null, 'name');
$check($fields['decision']['value'] === 'Released' && $fields['decision']['options'][1]['disabled'], 'Preserve selected and disabled options.');
$check($fields['fees[]']['checked'] && $fields['fees[]']['label'] === 'Local business tax', 'Preserve checkbox labels and array names.');
$check($fields['password']['value'] === '', 'Never send passwords back to the client.');
$check($fields['registration_doc']['label'] === 'Registration', 'Document labels exclude decorative upload instructions.');
$check($find('button')[1]['skipValidation'] && $find('button')[1]['value'] === 'reject', 'Keep submit-button semantics.');
$check($find('table')[0]['headers'] === ['Amount'], 'Expose report headings.');
$check(count($find('qr')) === 1, 'Expose certificate QR payload.');
$check(!str_contains(json_encode($screen), 'secret()') && !str_contains(json_encode($screen), 'must-not-leak'), 'Do not ship scripts or password values.');
$check($parser->href('https://example.test/help') === 'https://example.test/help', 'Keep external links separate.');
$check($parser->href('../../config.php') === '' && $parser->href('//evil.test/') === '', 'Reject unexpected routes.');
echo "Mobile screen conversion tests passed.\n";
