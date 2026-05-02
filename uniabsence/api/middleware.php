<?php
// api/middleware.php — مصادقة JWT وصلاحيات الأدوار
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

function getTokenFromRequest(): ?string {
    // 1) Cookie
    if (!empty($_COOKIE['access_token'])) return $_COOKIE['access_token'];
    // 2) Authorization header
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($h, 'Bearer ')) return substr($h, 7);
    return null;
}

function authenticate(): array {
    $token = getTokenFromRequest();
    if (!$token) {
        http_response_code(401);
        die(json_encode(['error' => 'غير مصرح، يرجى تسجيل الدخول', 'code' => 'NO_TOKEN']));
    }

    $payload = jwtVerify($token, JWT_SECRET);
    if (!$payload) {
        http_response_code(401);
        die(json_encode(['error' => 'انتهت صلاحية الجلسة أو رمز غير صالح', 'code' => 'TOKEN_INVALID']));
    }

    $user = dbQueryOne('SELECT * FROM users WHERE id = ? AND is_active = 1', [$payload['userId']]);
    if (!$user) {
        http_response_code(401);
        die(json_encode(['error' => 'المستخدم غير موجود أو الحساب معطّل', 'code' => 'USER_NOT_FOUND']));
    }

    return $user;
}

function requireRole(array $user, string ...$roles): void {
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die(json_encode(['error' => 'ليس لديك صلاحية للوصول لهذا المورد']));
    }
}

function logAudit(string $userId, string $action, string $resource, string $resourceId, array $metadata = []): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        dbExec(
            'INSERT INTO audit_logs (id, user_id, action, resource, resource_id, ip, metadata) VALUES (UUID(),?,?,?,?,?,?)',
            [$userId, $action, $resource, $resourceId, $ip, json_encode($metadata)]
        );
    } catch (Throwable) { /* لا نوقف الطلب */ }
}

function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getBody(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $_POST;
}

function formatUser(array $u): array {
    return [
        'id'                 => $u['id'],
        'registrationNumber' => $u['registration_number'],
        'fullName'           => $u['full_name_ar'],
        'role'               => $u['role'],
        'email'              => $u['email'] ?? '',
        'specialty'          => $u['specialization'] ?? $u['department'] ?? '',
        'year'               => (int)($u['year_of_study'] ?? 0),
        'faculty'            => $u['faculty_code'] ?? 'GEN',
        'isActive'           => (bool)$u['is_active'],
        'isLocked'           => (bool)$u['is_locked'],
        'createdAt'          => $u['created_at'],
        'lastLogin'          => $u['last_login'],
    ];
}