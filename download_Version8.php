<?php
// Secure-ish download proxy for quote uploads.
// Usage: /download.php?f=uploads/filename.ext
// NOTE: This does not do authentication; it only prevents directory traversal.

$rel = isset($_GET['f']) ? (string)$_GET['f'] : '';
$rel = str_replace(["\0", "\\", "\r", "\n"], '', $rel);

// Must be inside uploads/
if ($rel === '' || strpos($rel, 'uploads/') !== 0) {
    http_response_code(400);
    exit('Invalid file.');
}

// Block traversal
if (strpos($rel, '..') !== false) {
    http_response_code(400);
    exit('Invalid path.');
}

$abs = __DIR__ . '/' . $rel;

if (!is_file($abs)) {
    http_response_code(404);
    exit('File not found.');
}

$filename = basename($abs);
$mime = 'application/octet-stream';

// A small mime hint for common types (optional)
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (in_array($ext, ['pdf'], true)) $mime = 'application/pdf';
if (in_array($ext, ['png'], true)) $mime = 'image/png';
if (in_array($ext, ['jpg', 'jpeg'], true)) $mime = 'image/jpeg';

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string)filesize($abs));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($abs);
exit;