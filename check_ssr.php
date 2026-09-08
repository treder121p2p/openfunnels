<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'SSR enabled: ' . var_export(config('inertia.ssr.enabled'), true) . PHP_EOL;
echo 'SSR config: ' . var_export(config('inertia.ssr'), true) . PHP_EOL;
