<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

try {
    $request = \Illuminate\Http\Request::create('/login', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->headers->set('X-Inertia-Version', 'test');
    $response = $kernel->handle($request);
    echo "Response class: " . get_class($response) . "\n";
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content-Type: " . $response->headers->get('Content-Type') . "\n";
    echo "X-Inertia: " . ($response->headers->get('X-Inertia') ?? 'NONE') . "\n";
    echo "Vary: " . ($response->headers->get('Vary') ?? 'NONE') . "\n";
    echo "Body length: " . strlen($response->getContent()) . "\n";
    echo "Body preview: " . substr($response->getContent(), 0, 200) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
