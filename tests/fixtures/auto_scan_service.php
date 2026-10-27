<?php
declare(strict_types=1);

// Local-only Responses API fixture. The test copies this into its temporary app.
if (PHP_SAPI !== 'cli-server' || !preg_match('/^permit_test_reupload_[a-f0-9]{12}$/D', basename(__DIR__))) {
    http_response_code(404);
    exit;
}
if ($_SERVER['REQUEST_URI'] !== '/responses' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
$content = $payload['input'][0]['content'] ?? [];
$file = $content[0] ?? [];
$bytes = base64_decode(explode(',', $file['file_data'] ?? $file['image_url'] ?? '', 2)[1] ?? '', true);
$expected = $content[1]['text'] ?? '';
preg_match('/^Expected permit requirement: (.*?)\. Scan this document/s', $expected, $requirement);
if (!in_array($file['type'] ?? '', ['input_file', 'input_image'], true)
    || $bytes === false || $bytes === '' || ($payload['store'] ?? null) !== false
    || ($payload['text']['format']['type'] ?? '') !== 'json_schema') {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'Unexpected automatic scan request.']]);
    exit;
}
file_put_contents(__DIR__ . '/scan-requests.jsonl', json_encode([
    'type' => $file['type'], 'filename' => $file['filename'] ?? '',
    'safety_identifier' => $payload['safety_identifier'] ?? '',
]) . PHP_EOL, FILE_APPEND | LOCK_EX);
if (str_contains($bytes, '% service unavailable')) {
    http_response_code(503);
    echo json_encode(['error' => ['message' => 'Synthetic service outage.']]);
    exit;
}
$invalid = str_contains($bytes, '% invalid fixture');
$result = [
    'detected_document_type' => $invalid ? 'Unrelated document' : ($requirement[1] ?? 'Permit requirement'),
    'matches_expected_type' => !$invalid,
    'quality_score' => 95, 'confidence_score' => 98,
    'extracted_fields' => [], 'issues' => [],
    'summary' => $invalid ? 'Fixture does not match the requirement.' : 'Synthetic document assessment completed.',
    'requires_human_review' => true,
];
echo json_encode(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]]);
