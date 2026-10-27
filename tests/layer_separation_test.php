<?php
declare(strict_types=1);

// Structural checks need neither a database nor an external service.
$root = dirname(__DIR__);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
$phpFiles = static function (string $directory): array {
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $paths[] = $file->getPathname();
    }
    return $paths;
};
$controllers = $phpFiles($root . '/backend/controllers');
$views = $phpFiles($root . '/frontend/views');
$check(count($controllers) === 41 && count($views) === 27, 'All existing routes and page templates must be separated.');
foreach ($controllers as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root . '/backend/controllers/')));
    $source = file_get_contents($path);
    $public = file_get_contents($root . '/' . $relative);
    $check(str_contains($public, "'/backend/controllers/" . $relative . "'"), $relative . ' must delegate to its controller.');
    $check(str_contains($public, "define('PERMIT_ENTRY', true)"), $relative . ' must establish a public entry point.');
    $check(str_contains($source, "!defined('PERMIT_ENTRY')"), $relative . ' must deny direct controller requests.');
    $inline = array_filter(token_get_all($source), static fn($token): bool => is_array($token) && $token[0] === T_INLINE_HTML && trim($token[1]) !== '');
    $check(!$inline, $relative . ' must not contain a page template.');
    if (is_file($root . '/frontend/views/' . $relative)) {
        $check(str_contains($source, "'/frontend/views/" . $relative . "'"), $relative . ' must render its matching template.');
    }
}
foreach (array_merge($views, $phpFiles($root . '/frontend/layouts'), $phpFiles($root . '/frontend/partials')) as $path) {
    $source = file_get_contents($path);
    $check(str_contains($source, "!defined('PERMIT_ENTRY')"), $path . ' must deny direct template requests.');
    $check(!preg_match('/\b(?:db|estimate_application_timeline|send_app_mail|auto_scan_uploaded_documents|verify_csrf|require_role)\s*\(|->(?:prepare|query|execute|fetch|fetchAll|beginTransaction)\s*\(|\$pdo\b/', $source), $path . ' must not access the database or process requests.');
}
foreach (glob($root . '/includes/*.php') as $path) {
    $name = basename($path);
    $check(is_file($root . '/backend/includes/' . $name) && str_contains(file_get_contents($path), "'/backend/includes/" . $name . "'"), $name . ' must retain its compatibility include.');
}
foreach (['app.js', 'styles.css', 'admin-review.js', 'chatbot.js', 'permit-qr.js', 'logo-light.png', 'logo.png', 'vendor/qrcode.js', 'vendor/qrcode-LICENSE.txt'] as $asset) {
    $check(is_file($root . '/frontend/assets/' . $asset), 'Missing frontend asset: ' . $asset);
}
$check(str_contains(file_get_contents($root . '/backend/.htaccess'), 'Require all denied'), 'Apache must block direct backend access.');
$check(str_contains(file_get_contents($root . '/frontend/.htaccess'), 'Require all denied'), 'Apache must block direct template access.');
echo 'Frontend/backend separation tests passed (' . $checks . " checks).\n";
