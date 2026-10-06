<?php
/**
 * Local development router for PHP built-in web server.
 * Run with: php -S localhost:5000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If the request is for an API endpoint, forward to php-backend/index.php
if (str_starts_with($uri, '/api/') || $uri === '/api') {
    require __DIR__ . '/php-backend/index.php';
    exit;
}

// Serve existing static file (html, js, css, images, etc.)
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false;
}

// Default root / to index.html
if ($uri === '/' || $uri === '') {
    include __DIR__ . '/index.html';
    exit;
}

return false;
