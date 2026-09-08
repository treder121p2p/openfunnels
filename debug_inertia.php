<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "SSR enabled: " . var_export(config('inertia.ssr.enabled'), true) . "\n";
echo "Version: " . hash_file('xxh128', public_path('build/manifest.json')) . "\n";

// Simulate Inertia request
$request = \Illuminate\Http\Request::create('/login', 'GET', [], [], [], [
    'HTTP_X_INERTIA' => 'true',
    'HTTP_X_INERTIA_VERSION' => hash_file('xxh128', public_path('build/manifest.json')),
]);

$version = config('inertia.ssr.enabled') ? 'SSR-ON' : 'SSR-OFF';
echo "SSR status: $version\n";

// Check what the Response would return
$response = \Inertia\Inertia::render('auth/login', ['errors' => (object)[]]);
$httpResponse = $response->toResponse($request);
echo "Response class: " . get_class($httpResponse) . "\n";
echo "Content-Type: " . $httpResponse->headers->get('Content-Type') . "\n";
echo "X-Inertia: " . ($httpResponse->headers->get('X-Inertia') ?? 'NONE') . "\n";
echo "Body length: " . strlen($httpResponse->getContent()) . "\n";
