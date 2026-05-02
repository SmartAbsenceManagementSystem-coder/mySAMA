<?php
// api/db.php — اتصال MySQL عبر PDO
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage()]));
    }
    return $pdo;
}

/**
 * تنفيذ استعلام مع معاملات وإرجاع النتيجة
 */
function dbQuery(string $sql, array $params = []): array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function dbQueryOne(string $sql, array $params = []): ?array {
    $rows = dbQuery($sql, $params);
    return $rows[0] ?? null;
}

function dbExec(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function dbLastId(): string {
    return getDB()->lastInsertId();
}

function generateUUID(): string {
    $row = dbQueryOne('SELECT UUID() as id');
    return $row['id'];
}