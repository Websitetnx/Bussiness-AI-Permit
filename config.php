<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/includes/environment.php';
permit_load_environment(__DIR__ . '/.env');

$config = [
    'app_name' => 'permit',
    'app_url' => rtrim(getenv('APP_URL') ?: '', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Manila',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'permitflow',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
    'upload_dir' => __DIR__ . '/storage/uploads',
    'max_upload_bytes' => 5 * 1024 * 1024,
    'admin_setup_key' => getenv('ADMIN_SETUP_KEY') ?: '',
    'mail' => [
        'host' => getenv('SMTP_HOST') ?: (getenv('MAIL_HOST') ?: ''),
        'port' => max(1, (int) (getenv('SMTP_PORT') ?: (getenv('MAIL_PORT') ?: 587))),
        'username' => getenv('SMTP_USERNAME') ?: (getenv('MAIL_USERNAME') ?: ''),
        'password' => getenv('SMTP_PASSWORD') ?: (getenv('MAIL_PASSWORD') ?: ''),
        'encryption' => strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: (getenv('MAIL_ENCRYPTION') ?: 'tls')))),
        'smtp_auth' => filter_var(getenv('SMTP_AUTH') ?: (getenv('MAIL_SMTP_AUTH') ?: 'true'), FILTER_VALIDATE_BOOL),
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: '',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'PERMIT',
        'admin_address' => getenv('DOCUMENT_ALERT_ADMIN_EMAIL') ?: (getenv('ADMIN_EMAIL') ?: 'admin@example.com'),
        'admin_name' => getenv('DOCUMENT_ALERT_ADMIN_NAME') ?: 'PERMIT Administrator',
        'timeout_seconds' => max(1, (int) (getenv('MAIL_TIMEOUT_SECONDS') ?: 15)),
    ],
    'auth_otp' => [
        'expiry_seconds' => max(60, (int) (getenv('OTP_EXPIRY_SECONDS') ?: 600)),
        'max_attempts' => max(1, (int) (getenv('OTP_MAX_ATTEMPTS') ?: 5)),
        'resend_cooldown_seconds' => max(0, (int) (getenv('OTP_RESEND_COOLDOWN_SECONDS') ?: 60)),
    ],
    'openai' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model' => getenv('OPENAI_MODEL') ?: 'gpt-5.6-luna',
        'base_url' => rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/'),
        'allow_sensitive_documents' => filter_var(getenv('ALLOW_SENSITIVE_AI_SCAN') ?: 'false', FILTER_VALIDATE_BOOL),
        'daily_scan_limit' => max(1, (int) (getenv('AI_DAILY_SCAN_LIMIT') ?: 100)),
        'insight_cooldown_seconds' => max(30, (int) (getenv('AI_INSIGHT_COOLDOWN_SECONDS') ?: 60)),
    ],
];

// Legacy compatibility: prefer .env for new deployments. Any settings left in
// config.local.php override both .env and server environment values.
$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (!is_array($localConfig)) {
        throw new UnexpectedValueException('config.local.php must return a configuration array.');
    }
    $config = array_replace_recursive($config, $localConfig);
}

return $config;
