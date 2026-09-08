<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

try {
    // Test Inertia::location()
    $location = \Inertia\Inertia::location('http://localhost:8000/login');
    echo "Location response class: " . get_class($location) . "\n";
    echo "Location status: " . $location->getStatusCode() . "\n";
    echo "Location headers: \n";
    foreach ($location->headers->all() as $key => $value) {
        echo "  $key: " . implode(', ', $value) . "\n";
    }
    echo "Location body: " . $location->getContent() . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
