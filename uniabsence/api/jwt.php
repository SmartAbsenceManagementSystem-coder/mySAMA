<?php
// api/jwt.php — توليد والتحقق من JWT بدون مكتبة خارجية
require_once __DIR__ . '/config.php';

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function jwtSign(array $payload, string $secret, int $expiresIn): string {
    $header  = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $expiresIn;
    $body    = base64UrlEncode(json_encode($payload));
    $sig     = base64UrlEncode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

function jwtVerify(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64UrlEncode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64UrlDecode($body), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}

function signAccessToken(string $userId, string $role): string {
    return jwtSign(['userId' => $userId, 'role' => $role], JWT_SECRET, JWT_EXPIRES);
}

function signRefreshToken(string $userId): string {
    return jwtSign(['userId' => $userId], REFRESH_SECRET, REFRESH_EXPIRES);
}

function setTokenCookies(string $accessToken, ?string $refreshToken = null): void {
    $isProd = ($_SERVER['HTTPS'] ?? '') === 'on';
    setcookie('access_token', $accessToken, [
        'expires'  => time() + JWT_EXPIRES,
        'path'     => '/',
        'secure'   => $isProd,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($refreshToken) {
        setcookie('refresh_token', $refreshToken, [
            'expires'  => time() + REFRESH_EXPIRES,
            'path'     => '/',
            'secure'   => $isProd,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}