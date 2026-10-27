<?php
declare(strict_types=1);

/** Load a local .env without overriding server/process variables (even empty ones). */
function permit_load_environment(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read the environment file.');
    }
    $values = [];
    foreach ($lines as $index => $line) {
        if (str_contains($line, "\0")) {
            throw new RuntimeException('Invalid environment entry at line ' . ($index + 1) . '.');
        }
        if ($index === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $invalid = static function () use ($index): void {
            // Never include the line contents: they may contain credentials.
            throw new RuntimeException('Invalid environment entry at line ' . ($index + 1) . '.');
        };
        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
            $invalid();
        }
        $raw = $match[2];
        $value = '';
        if ($raw !== '' && ($raw[0] === "'" || $raw[0] === '"')) {
            $quote = $raw[0];
            $closed = false;
            for ($i = 1, $length = strlen($raw); $i < $length; $i++) {
                $char = $raw[$i];
                if ($char === '\\' && $i + 1 < $length && ($raw[$i + 1] === $quote || $raw[$i + 1] === '\\')) {
                    $value .= $raw[++$i];
                } elseif ($char === $quote) {
                    $tail = trim(substr($raw, $i + 1));
                    if ($tail !== '' && $tail[0] !== '#') {
                        $invalid();
                    }
                    $closed = true;
                    break;
                } else {
                    $value .= $char;
                }
            }
            if (!$closed) {
                $invalid();
            }
        } else {
            $value = rtrim(preg_replace('/(?:^|\s+)#.*$/', '', $raw));
        }
        if (str_contains($value, "\0")) {
            $invalid();
        }
        $values[$match[1]] = $value;
    }
    // Validate the entire file before changing the environment.
    foreach ($values as $name => $value) {
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}
