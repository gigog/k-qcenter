<?php
declare(strict_types=1);

// Public gateway: expose only K-QOnline, queue status, and explicitly allowed APIs.
$projectRoot = dirname(__DIR__);
$requestPath = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$requestPath = '/' . ltrim(str_replace('\\', '/', $requestPath), '/');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

if ($requestPath === '/api' || $requestPath === '/api/' || $requestPath === '/api/index.php') {
    $action = (string)($_GET['action'] ?? '');
    $allowed = ['health', 'public-slots', 'create-booking', 'queue-status'];
    if (!in_array($action, $allowed, true)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok'=>false, 'error'=>'ไม่พบ Public API endpoint'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require $projectRoot . '/api/index.php';
}

if (preg_match('#^/q/([a-f0-9]{32})$#', $requestPath, $match)) {
    header('Location: /status.html?t=' . rawurlencode($match[1]), true, 302);
    exit;
}

$routes = [
    '/' => ['dist/online.html', 'text/html; charset=utf-8'],
    '/online.html' => ['dist/online.html', 'text/html; charset=utf-8'],
    '/status.html' => ['dist/status.html', 'text/html; charset=utf-8'],
    '/online.js' => ['dist/online.js', 'text/javascript; charset=utf-8'],
    '/status.js' => ['dist/status.js', 'text/javascript; charset=utf-8'],
    '/thai-date.js' => ['dist/thai-date.js', 'text/javascript; charset=utf-8'],
    '/qrcode.js' => ['dist/qrcode.js', 'text/javascript; charset=utf-8'],
    '/public-queue.css' => ['dist/public-queue.css', 'text/css; charset=utf-8'],
    '/online-shell.css' => ['dist/online-shell.css', 'text/css; charset=utf-8'],
    '/ticket.css' => ['dist/ticket.css', 'text/css; charset=utf-8'],
    '/manifest.webmanifest' => ['dist/manifest.webmanifest', 'application/manifest+json; charset=utf-8'],
    '/sw.js' => ['dist/sw.js', 'text/javascript; charset=utf-8'],
    '/icons/app-icon.svg' => ['dist/icons/app-icon.svg', 'image/svg+xml'],
    '/icons/app-icon-192.png' => ['dist/icons/app-icon-192.png', 'image/png'],
    '/icons/app-icon-512.png' => ['dist/icons/app-icon-512.png', 'image/png'],
];

if (!isset($routes[$requestPath])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ไม่พบหน้าที่ร้องขอ';
    exit;
}

[$relativeFile, $contentType] = $routes[$requestPath];
$file = $projectRoot . '/' . $relativeFile;
if (!is_file($file)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ระบบยังไม่พร้อมให้บริการ';
    exit;
}

header('Content-Type: ' . $contentType);
header(str_ends_with($relativeFile, '.html') || str_ends_with($relativeFile, 'sw.js') ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=3600');
readfile($file);

