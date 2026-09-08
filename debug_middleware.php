<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

// Check if Inertia middleware is registered
$allMiddleware = $kernel->getMiddleware();
echo "Global middleware:\n";
foreach ($allMiddleware as $key => $m) {
    if (str_contains($m, 'Inertia') || str_contains($m, 'inertia')) {
        echo "  FOUND: $key => $m\n";
    }
}

$groups = $kernel->getMiddlewareGroups();
echo "\nWeb group middleware with 'Inertia':\n";
foreach ($groups['web'] as $m) {
    if (str_contains($m, 'Inertia') || str_contains($m, 'inertia')) {
        echo "  $m\n";
    }
}

echo "\nSSR enabled: " . var_export(config('inertia.ssr.enabled'), true) . "\n";
