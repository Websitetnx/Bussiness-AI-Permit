<?php
declare(strict_types=1);

/** Copy app code only; private config, live uploads, and vendor files stay out. */
function copy_project_test_fixture(string $root, string $workspace): void
{
    foreach (glob($root . '/*.php') as $source) {
        if (basename($source) !== 'config.local.php') {
            copy($source, $workspace . '/' . basename($source));
        }
    }
    foreach (['includes', 'admin', 'api', 'backend', 'frontend'] as $directory) {
        $base = $root . '/' . $directory;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $relative = $directory . '/' . substr($file->getPathname(), strlen($base) + 1);
            $target = $workspace . '/' . $relative;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            copy($file->getPathname(), $target);
        }
    }
}
