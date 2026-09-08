<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

$version = hash_file('xxh128', public_path('build/manifest.json'));

// Test 1: Without X-Inertia header (should return HTML view)
$request1 = \Illuminate\Http\Request::create('/login', 'GET');
$response1 = $kernel->handle($request1);
echo "Without X-Inertia:\n";
echo "  Class: " . get_class($response1) . "\n";
echo "  Status: " . $response1->getStatusCode() . "\n";
echo "  Content-Type: " . $response1->headers->get('Content-Type') . "\n";
echo "  Body length: " . strlen($response1->getContent()) . "\n";
echo "  Body starts with: " . substr($response1->getContent(), 0, 50) . "\n\n";

// Test 2: With X-Inertia header and correct version (should return JSON)
$request2 = \Illuminate\Http\Request::create('/login', 'GET');
$request2->headers->set('X-Inertia', 'true');
$request2->headers->set('X-Inertia-Version', $version);
$response2 = $kernel->handle($request2);
echo "With X-Inertia + correct version:\n";
echo "  Class: " . get_class($response2) . "\n";
echo "  Status: " . $response2->getStatusCode() . "\n";
echo "  Content-Type: " . $response2->headers->get('Content-Type') . "\n";
echo "  X-Inertia: " . ($response2->headers->get('X-Inertia') ?? 'NONE') . "\n";
echo "  Vary: " . ($response2->headers->get('Vary') ?? 'NONE') . "\n";
echo "  Body length: " . strlen($response2->getContent()) . "\n";
echo "  Body starts with: " . substr($response2->getContent(), 0, 100) . "\n";
