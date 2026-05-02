<?php
// api/config.php — إعدادات المشروع
define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     getenv('DB_PORT')     ?: '3306');
define('DB_NAME',     getenv('DB_NAME')     ?: 'uniabsence');
define('DB_USER',     getenv('DB_USER')     ?: 'root');
define('DB_PASS',     getenv('DB_PASS')     ?: '');

define('JWT_SECRET',          getenv('JWT_SECRET')          ?: 'change_this_secret_key_in_production');
define('JWT_EXPIRES',         getenv('JWT_EXPIRES')         ?: 8 * 3600);      // 8 ساعات
define('REFRESH_SECRET',      getenv('REFRESH_SECRET')      ?: 'change_this_refresh_secret');
define('REFRESH_EXPIRES',     getenv('REFRESH_EXPIRES')     ?: 7 * 24 * 3600); // 7 أيام

define('UPLOAD_DIR',  __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

define('FRONTEND_URL', getenv('FRONTEND_URL') ?: 'http://localhost');

// ─── CORS ────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: ' . FRONTEND_URL);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}