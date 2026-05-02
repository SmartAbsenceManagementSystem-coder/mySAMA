<?php
// index.php — نقطة الدخول الموحّدة
// يُوجّه طلبات /api/* إلى api/index.php وكل ما عداها إلى index.html

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// طلبات API
if (str_starts_with($uri, '/api/') || $uri === '/api') {
    require __DIR__ . '/api/index.php';
    exit;
}

// ملفات ثابتة موجودة (CSS, JS, uploads...)
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && is_file($file)) {
    return false; // دع الخادم يخدمها مباشرة (PHP built-in server)
}

// كل ما تبقّى → index.html (SPA)
readfile(__DIR__ . '/index.html');
