<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$middleware = $kernel->getMiddlewareGroups();
echo "Web middleware group:\n";
print_r($middleware['web'] ?? []);
echo "\nAll middleware groups:\n";
print_r(array_keys($middleware));
