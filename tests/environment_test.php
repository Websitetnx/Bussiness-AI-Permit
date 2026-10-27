<?php
declare(strict_types=1);

$workspace = sys_get_temp_dir() . '/permit-env-' . bin2hex(random_bytes(8));
mkdir($workspace . '/backend/includes', 0700, true);
copy(dirname(__DIR__) . '/config.php', $workspace . '/config.php');
copy(dirname(__DIR__) . '/backend/includes/environment.php', $workspace . '/backend/includes/environment.php');
require_once $workspace . '/backend/includes/environment.php';
$names = ['DB_HOST', 'DB_PASS', 'SMTP_AUTH', 'OPENAI_API_KEY', 'ENV_TEST_LITERAL', 'ENV_TEST_ESCAPED', 'ENV_TEST_EMPTY', 'ENV_TEST_COMMENT', 'ENV_TEST_DUPLICATE', 'ENV_TEST_ATOMIC'];
$previous = [];
foreach ($names as $name) {
    $previous[$name] = getenv($name);
    putenv($name);
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
try {
    permit_load_environment($workspace . '/missing.env');
    $check(getenv('DB_HOST') === false, 'Missing .env must be optional.');
    putenv('DB_HOST=server-host');
    putenv('OPENAI_API_KEY=');
    $fixture = <<<'ENV'
# Dummy values only; never load the live .env in this test.
DB_HOST=file-host
DB_PASS='0'
SMTP_AUTH=false
OPENAI_API_KEY=dummy-file-key
export ENV_TEST_LITERAL=' spaces # $HOME ${DB_HOST} = value '
ENV_TEST_ESCAPED="quote\" and slash\\ and literal\n"
ENV_TEST_EMPTY=
ENV_TEST_COMMENT=value # comment
ENV_TEST_DUPLICATE=first
ENV_TEST_DUPLICATE=last
ENV;
    file_put_contents($workspace . '/.env', "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $fixture));
    $config = require $workspace . '/config.php';
    $check($config['db']['host'] === 'server-host', 'Server variables must win.');
    $check($config['db']['pass'] === '0', 'Password zero must not be treated as missing.');
    $check($config['mail']['smtp_auth'] === false, 'Boolean false must work.');
    $check($config['openai']['api_key'] === '', 'Explicit empty server values must win.');
    $check(getenv('ENV_TEST_LITERAL') === ' spaces # $HOME ${DB_HOST} = value ', 'Quoted secrets must remain literal.');
    $check(getenv('ENV_TEST_ESCAPED') === 'quote" and slash\\ and literal\n', 'Escaped quotes and backslashes must round trip.');
    $check(getenv('ENV_TEST_EMPTY') === '', 'Empty file values must be supported.');
    $check(getenv('ENV_TEST_COMMENT') === 'value', 'Unquoted inline comments must be stripped.');
    $check(getenv('ENV_TEST_DUPLICATE') === 'last', 'Last duplicate file entry must win.');
    file_put_contents($workspace . '/config.local.php', "<?php return ['db' => ['host' => 'legacy-host']];");
    $config = require $workspace . '/config.php';
    $check($config['db']['host'] === 'legacy-host', 'Legacy overrides must remain compatible.');

    foreach (["NOT AN ASSIGNMENT secret-sentinel", "DB_PASS='secret-sentinel", 'DB_PASS="secret-sentinel" trailing', "DB_PASS=secret-sentinel\0"] as $invalid) {
        file_put_contents($workspace . '/bad.env', "ENV_TEST_ATOMIC=changed\n" . $invalid);
        try {
            permit_load_environment($workspace . '/bad.env');
            throw new LogicException('Malformed .env was accepted.');
        } catch (RuntimeException $error) {
            $check(str_contains($error->getMessage(), 'line 2'), 'Errors must identify the line.');
            $check(!str_contains($error->getMessage(), 'secret-sentinel'), 'Errors must never expose values.');
        }
        $check(getenv('ENV_TEST_ATOMIC') === false, 'Invalid files must not partially update environment.');
    }
    echo 'Environment tests passed (' . $checks . " checks).\n";
} finally {
    foreach ($previous as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
    foreach (['config.php', 'config.local.php', '.env', 'bad.env', 'backend/includes/environment.php'] as $file) {
        if (is_file($workspace . '/' . $file)) unlink($workspace . '/' . $file);
    }
    rmdir($workspace . '/backend/includes');
    rmdir($workspace . '/backend');
    rmdir($workspace);
}
