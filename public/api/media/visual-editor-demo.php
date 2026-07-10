<?php

declare(strict_types=1);

use Webconsulting\Typo3CaminoDemo\Http\ByteRange;

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$file = $root . '/public/fileadmin/camino/visual-editor-demo.mp4';
$fileSize = is_file($file) ? filesize($file) : false;
if (!is_int($fileSize) || $fileSize < 1) {
    http_response_code(404);
    exit;
}

try {
    $range = ByteRange::fromHeader(
        isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : null,
        $fileSize,
    );
} catch (InvalidArgumentException) {
    header(sprintf('Content-Range: bytes */%d', $fileSize));
    header('Content-Length: 0');
    http_response_code(416);
    exit;
}

$start = $range?->start ?? 0;
$end = $range?->end ?? $fileSize - 1;
$length = $range?->length() ?? $fileSize;

header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=31536000, immutable');
header('CDN-Cache-Control: no-store');
header('Vercel-CDN-Cache-Control: no-store');
header('Content-Type: video/mp4');
header('Content-Disposition: inline; filename="visual-editor-demo.mp4"');
header(sprintf('Content-Length: %d', $length));
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

if ($range !== null) {
    http_response_code(206);
    header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $fileSize));
}

if ($method === 'HEAD') {
    exit;
}

$handle = fopen($file, 'rb');
if (!is_resource($handle) || fseek($handle, $start) !== 0) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    http_response_code(500);
    exit;
}

$remaining = $length;
while ($remaining > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
    $chunk = fread($handle, min(1024 * 1024, $remaining));
    if (!is_string($chunk) || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($handle);
