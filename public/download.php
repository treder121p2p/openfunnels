<?php
$file = $_GET['file'] ?? '';
$file = basename($file);
$file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $file);

if (empty($file) || !preg_match('/\.(json|md)$/', $file)) {
    http_response_code(400);
    exit('Invalid file');
}

$path = __DIR__ . '/templates/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$size = filesize($path);
$ext = pathinfo($file, PATHINFO_EXTENSION);
$mime = $ext === 'json' ? 'application/json' : 'text/markdown';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-cache');
readfile($path);
exit;
