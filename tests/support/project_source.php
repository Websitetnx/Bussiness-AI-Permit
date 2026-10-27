<?php
declare(strict_types=1);

/** Read the implementation behind a public URL for source-based regression checks. */
function project_test_source(string $root, string $relativePath): string
{
    $paths = [];
    if (is_file($root . '/backend/controllers/' . $relativePath)) {
        $paths[] = 'backend/controllers/' . $relativePath;
        if (is_file($root . '/frontend/views/' . $relativePath)) {
            $paths[] = 'frontend/views/' . $relativePath;
        }
    } elseif (str_starts_with($relativePath, 'includes/')) {
        $paths[] = 'backend/' . $relativePath;
        if ($relativePath === 'includes/layout.php') {
            $paths[] = 'frontend/layouts/header.php';
            $paths[] = 'frontend/layouts/footer.php';
        }
    } elseif (in_array($relativePath, ['app.js', 'styles.css'], true)) {
        $paths[] = 'frontend/assets/' . $relativePath;
    } elseif (str_starts_with($relativePath, 'assets/')) {
        $paths[] = 'frontend/' . $relativePath;
    } else {
        $paths[] = $relativePath;
    }

    $parts = [];
    foreach ($paths as $path) {
        $source = file_get_contents($root . '/' . $path);
        if ($source === false) throw new RuntimeException('Could not read ' . $path);
        $parts[] = $source;
    }
    return implode("\n", $parts);
}
