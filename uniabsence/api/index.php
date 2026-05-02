<?php
// api/index.php — الراوتر الرئيسي
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// تنظيف المسار: أزل /api من البداية إن كانت موجودة
$uri    = preg_replace('#^/api#', '', $uri);
$uri    = rtrim($uri, '/') ?: '/';
$parts  = explode('/', ltrim($uri, '/'));

$resource = $parts[0] ?? '';
$id       = $parts[1] ?? null;
$sub      = $parts[2] ?? null;
$subsub   = $parts[3] ?? null;

// ─── التوجيه ─────────────────────────────────────────────────────────────────
match ($resource) {
    'auth'           => require __DIR__ . '/routes/auth.php',
    'users'          => require __DIR__ . '/routes/users.php',
    'specialties'    => require __DIR__ . '/routes/specialties.php',
    'justifications' => require __DIR__ . '/routes/justifications.php',
    'appeals'        => require __DIR__ . '/routes/appeals.php',
    'stats'          => require __DIR__ . '/routes/stats.php',
    default          => jsonResponse(['error' => 'المسار غير موجود'], 404),
};