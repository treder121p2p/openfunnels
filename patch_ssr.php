<?php
// Patch vendor Inertia config to disable SSR
$configPath = '/app/vendor/inertiajs/inertia-laravel/config/inertia.php';
$content = file_get_contents($configPath);
$content = str_replace(
    "'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),",
    "'enabled' => false,",
    $content
);
file_put_contents($configPath, $content);
echo "Patched vendor config: SSR disabled\n";

// Clear all caches
echo shell_exec('cd /app && php artisan optimize:clear 2>&1');
