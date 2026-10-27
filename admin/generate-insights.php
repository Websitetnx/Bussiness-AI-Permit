<?php
declare(strict_types=1);

// Public URL: request handling and page templates live in separate layers.
define('PERMIT_ENTRY', true);
require dirname(__DIR__) . '/backend/controllers/admin/generate-insights.php';
