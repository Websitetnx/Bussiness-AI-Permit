<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require __DIR__ . '/support/project_source.php';
$checks = 0;
$failures = [];

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function (string $relativePath) use ($root, &$failures): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $contents = project_test_source($root, $relativePath);
    if ($contents === false) {
        $failures[] = "Could not read {$relativePath}.";
        return '';
    }
    return $contents;
};

$extractBalancedBlock = static function (string $source, string $startPattern): string {
    if (!preg_match($startPattern, $source, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = (int) $match[0][1];
    $openingBrace = strpos($source, '{', $start);
    if ($openingBrace === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($source);
    for ($position = $openingBrace; $position < $length; $position++) {
        if ($source[$position] === '{') {
            $depth++;
        } elseif ($source[$position] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $position - $start + 1);
            }
        }
    }

    return '';
};

$extractAssetVersion = static function (string $source, string $asset): ?string {
    $pattern = '/' . preg_quote($asset, '/') . '[^\r\n]{0,160}?\?v=([A-Za-z0-9._-]+)/i';
    return preg_match($pattern, $source, $match) === 1 ? $match[1] : null;
};

$app = $read('app.js');
$styles = $read('styles.css');

// Motion must enhance the existing markup without making content depend on JavaScript.
$check(str_contains($app, 'motion-reveal'), 'app.js must opt stable content into the shared reveal system.');
$check(str_contains($styles, '.motion-reveal'), 'styles.css must style the shared reveal class.');
$check(str_contains($app, 'is-revealed'), 'app.js must expose revealed content with an explicit state class.');
$check(str_contains($styles, '.is-revealed'), 'styles.css must include the revealed state.');

// Respect both the browser preference in JavaScript and a CSS-only preference fallback.
$check(
    preg_match('/matchMedia\s*\(\s*[\'\"]\(prefers-reduced-motion:\s*reduce\)[\'\"]\s*\)/i', $app) === 1,
    'app.js must detect prefers-reduced-motion before starting nonessential motion.'
);
$reducedMotionBlock = $extractBalancedBlock($styles, '/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/i');
$check($reducedMotionBlock !== '', 'styles.css must define a prefers-reduced-motion media query.');
$check(
    $reducedMotionBlock !== '' && str_contains($reducedMotionBlock, 'motion-reveal'),
    'Reduced-motion CSS must explicitly leave motion-reveal content visible.'
);
$check(
    $reducedMotionBlock !== ''
        && preg_match('/(?:animation(?:-duration)?|transition(?:-duration)?)\s*:\s*(?:none|0(?:\.0+)?(?:m?s)?|\.0*1ms)/i', $reducedMotionBlock) === 1,
    'Reduced-motion CSS must disable or effectively eliminate nonessential animation.'
);

// Reveal content once when supported, while still revealing it in older browsers.
$check(str_contains($app, 'IntersectionObserver'), 'app.js must use IntersectionObserver for scroll-triggered reveals.');
$observerFallbackPatterns = [
    '/!\s*\(?\s*[\'\"]IntersectionObserver[\'\"]\s+in\s+window/i',
    '/!\s*(?:window\.)?IntersectionObserver\b/i',
    '/typeof\s+(?:window\.)?IntersectionObserver\s*={2,3}\s*[\'\"]undefined[\'\"]/i',
    '/[\'\"]IntersectionObserver[\'\"]\s+in\s+window[\s\S]{0,7000}?\}\s*else\s*\{[\s\S]{0,1500}?(?:is-revealed|revealElements\.forEach\s*\(\s*reveal\s*\))/i',
];
$hasObserverFallback = false;
foreach ($observerFallbackPatterns as $pattern) {
    if (preg_match($pattern, $app) === 1) {
        $hasObserverFallback = true;
        break;
    }
}
$check($hasObserverFallback, 'app.js must reveal content when IntersectionObserver is unavailable.');

// Pointer feedback should be decorative and removed after its animation completes.
$check(str_contains($app, 'motion-ripple'), 'app.js must create the shared button/link ripple element.');
$check(str_contains($styles, '.motion-ripple'), 'styles.css must style the shared ripple element.');
$check(
    preg_match('/addEventListener\s*\(\s*[\'\"](?:click|pointerdown)[\'\"]/i', $app) === 1,
    'The ripple must be driven by a click or pointer event.'
);
$check(
    preg_match('/@keyframes\s+[\w-]*ripple[\w-]*/i', $styles) === 1,
    'The ripple must have a dedicated CSS keyframe animation.'
);
$check(
    preg_match('/(?:animationend|\.remove\s*\(\s*\))/i', $app) === 1,
    'Temporary ripple elements must be removed after use.'
);

// Dashboard totals should count to their server-rendered values without replacing them permanently.
$check(
    preg_match('/(?:animate(?:Counter|Count|Number|Stat)|counter)/i', $app) === 1,
    'app.js must include an identifiable numeric-counter routine.'
);
$check(str_contains($app, 'requestAnimationFrame'), 'Numeric counters must use requestAnimationFrame.');
$check(
    preg_match('/(?:parseInt|parseFloat|Number)\s*\(/', $app) === 1,
    'Numeric counters must parse the rendered numeric value instead of assuming an integer literal.'
);
$check(
    str_contains($app, '.stat-card') && str_contains($app, 'textContent'),
    'Numeric counter handling must target the rendered statistic values.'
);

// Charts, progress timelines, and activity histories receive one shared visibility hook.
foreach (['.bar-chart', '.forecast-chart', '.timeline', '.history-list'] as $selector) {
    $check(str_contains($app, $selector), "app.js must register {$selector} with the motion sequence observer.");
}
$hasSequenceHook = preg_match('/motion-(?:chart|sequence|data)-visible|is-motion-visible/i', $app) === 1;
$check($hasSequenceHook, 'app.js must add an explicit visibility hook for charts and timelines.');
$check(
    preg_match('/motion-(?:chart|sequence|data)-visible|is-motion-visible/i', $styles) === 1,
    'styles.css must animate chart and timeline children from the shared visibility hook.'
);
$check(
    str_contains($styles, '.bar-fill') && str_contains($styles, '.forecast-bar') && str_contains($styles, '.timeline'),
    'Motion styles must cover bar charts, forecast charts, and timelines.'
);

// Every entry page must serve the same newly cache-busted motion assets.
$entryFiles = ['includes/layout.php', 'login.php', 'register.php', 'setup-admin.php'];
$styleVersions = [];
$scriptVersions = [];
foreach ($entryFiles as $entryFile) {
    $source = $read($entryFile);
    $styleVersion = $extractAssetVersion($source, 'styles.css');
    $scriptVersion = $extractAssetVersion($source, 'app.js');
    $styleVersions[$entryFile] = $styleVersion;
    $scriptVersions[$entryFile] = $scriptVersion;

    $check($styleVersion !== null, "{$entryFile} must load a cache-busted styles.css asset.");
    $check($scriptVersion !== null, "{$entryFile} must load a cache-busted app.js asset.");
    $check(
        preg_match('/<script\b[^>\r\n]*app\.js[^>\r\n]*>/i', $source) === 1,
        "{$entryFile} must load app.js so the shared motion layer runs."
    );
}

$nonNullStyleVersions = array_values(array_filter($styleVersions, static fn (?string $version): bool => $version !== null));
$nonNullScriptVersions = array_values(array_filter($scriptVersions, static fn (?string $version): bool => $version !== null));
$check(
    count($nonNullStyleVersions) === count($entryFiles) && count(array_unique($nonNullStyleVersions)) === 1,
    'All entry pages must use the same styles.css cache version.'
);
$check(
    count($nonNullScriptVersions) === count($entryFiles) && count(array_unique($nonNullScriptVersions)) === 1,
    'All entry pages must use the same app.js cache version.'
);
$check(
    !in_array('20260901-1', $nonNullStyleVersions, true),
    'The styles.css cache key must be bumped for the new motion styles.'
);
$check(
    !in_array('20260902-1', $nonNullScriptVersions, true),
    'The app.js cache key must be bumped for the new motion behavior.'
);

if ($failures) {
    fwrite(STDERR, "Motion system regression test failed ({$checks} checks):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Motion system regression tests passed ({$checks} checks).\n";
