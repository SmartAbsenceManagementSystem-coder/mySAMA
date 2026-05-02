<?php
// api/routes/users.php
$user = authenticate();

// GET /api/users — قائمة المستخدمين
if ($method === 'GET' && !$id) {
    requireRole($user, 'admin');
    $role   = $_GET['role']   ?? null;
    $search = $_GET['search'] ?? null;
    $sql    = "SELECT id, registration_number, role, full_name_ar, email, faculty_code, department, year_of_study, specialization, is_active, is_locked, created_at, last_login FROM users WHERE 1=1";
    $params = [];
    if ($role && $role !== 'all') { $sql .= ' AND role=?'; $params[] = $role; }
    if ($search) {
        $like = '%' . strtolower($search) . '%';
        $sql .= ' AND (LOWER(full_name_ar) LIKE ? OR LOWER(registration_number) LIKE ? OR LOWER(email) LIKE ?)';
        $params = array_merge($params, [$like, $like, $like]);
    }
    $sql .= ' ORDER BY created_at DESC';
    $rows = dbQuery($sql, $params);
    jsonResponse(['users' => array_map('formatUser', $rows)]);
}

// GET /api/users/professors
if ($method === 'GET' && $id === 'professors') {
    requireRole($user, 'admin', 'professor');
    $rows = dbQuery("SELECT id, registration_number, full_name_ar, email, specialization, department FROM users WHERE role='professor' AND is_active=1 ORDER BY full_name_ar");
    jsonResponse(['professors' => array_map(fn($p) => [
        'id'                 => $p['id'],
        'registrationNumber' => $p['registration_number'],
        'fullName'           => $p['full_name_ar'],
        'email'              => $p['email'],
        'specialty'          => $p['specialization'] ?? $p['department'] ?? '',
    ], $rows)]);
}

// GET /api/users/students
if ($method === 'GET' && $id === 'students') {
    requireRole($user, 'admin');
    $search = $_GET['search'] ?? null;
    $sql    = "SELECT u.id, u.registration_number, u.full_name_ar, u.email, u.specialization, u.year_of_study, u.is_active,
                      COUNT(DISTINCT a.id) AS total_absences,
                      COUNT(DISTINCT j.id) AS total_justifications
               FROM users u
               LEFT JOIN absences a        ON u.id = a.student_id
               LEFT JOIN justifications j  ON a.id = j.absence_id
               WHERE u.role='student'";
    $params = [];
    if ($search) {
        $like = '%' . strtolower($search) . '%';
        $sql .= ' AND (LOWER(u.full_name_ar) LIKE ? OR LOWER(u.registration_number) LIKE ?)';
        $params = [$like, $like];
    }
    $sql .= ' GROUP BY u.id ORDER BY u.full_name_ar';
    $rows = dbQuery($sql, $params);
    jsonResponse(['students' => array_map(fn($s) => [
        'id'                 => $s['id'],
        'registrationNumber' => $s['registration_number'],
        'fullName'           => $s['full_name_ar'],
        'email'              => $s['email'],
        'specialty'          => $s['specialization'] ?? '',
        'year'               => (int)($s['year_of_study'] ?? 0),
        'isActive'           => (bool)$s['is_active'],
        'absences'           => (int)$s['total_absences'],
        'justified'          => (int)$s['total_justifications'],
    ], $rows)]);
}

// POST /api/users — إنشاء مستخدم
if ($method === 'POST' && !$id) {
    requireRole($user, 'admin');
    $body = getBody();
    ['firstname' => $fn, 'lastname' => $ln, 'role' => $role2, 'password' => $pass] = $body + ['firstname'=>'','lastname'=>'','role'=>'','password'=>''];
    $email  = strtolower(trim($body['email'] ?? ''));
    if (!$fn || !$ln || !$role2 || !$pass)
        jsonResponse(['error' => 'البيانات الأساسية مطلوبة'], 400);

    if ($email) {
        $dup = dbQueryOne('SELECT id FROM users WHERE email=?', [$email]);
        if ($dup) jsonResponse(['error' => 'البريد الإلكتروني مستخدم'], 409);
    }

    $prefix = match ($role2) { 'student' => 'STU', 'professor' => 'PROF', default => 'ADM' };
    $yr     = date('Y');
    $cnt    = dbQueryOne('SELECT COUNT(*) AS c FROM users WHERE role=?', [$role2])['c'] ?? 0;
    $seq    = str_pad($cnt + 1, 4, '0', STR_PAD_LEFT);
    $regNum = "{$prefix}{$yr}{$seq}";

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    dbExec(
        'INSERT INTO users (id, registration_number, password_hash, role, full_name_ar, email, faculty_code, year_of_study, specialization) VALUES (UUID(),?,?,?,?,?,?,?,?)',
        [$regNum, $hash, $role2, "$fn $ln", $email ?: null, $body['faculty_code'] ?? 'GEN',
         $role2 === 'student' ? ($body['year'] ?? null) : null, $body['specialty'] ?? null]
    );
    $newUser = dbQueryOne('SELECT * FROM users WHERE registration_number=?', [$regNum]);
    logAudit($user['id'], 'USER_CREATED', 'users', $newUser['id'], ['role' => $role2]);
    jsonResponse(['user' => formatUser($newUser), 'registration_number' => $regNum], 201);
}

// PUT /api/users/:id
if ($method === 'PUT' && $id) {
    requireRole($user, 'admin');
    $body = getBody();
    $existing = dbQueryOne('SELECT * FROM users WHERE id=?', [$id]);
    if (!$existing) jsonResponse(['error' => 'المستخدم غير موجود'], 404);

    $fn = $body['firstname'] ?? ''; $ln = $body['lastname'] ?? '';
    $fullName  = ($fn && $ln) ? "$fn $ln" : $existing['full_name_ar'];
    $email     = strtolower($body['email']     ?? $existing['email'] ?? '');
    $spec      = $body['specialty']  ?? $existing['specialization'];
    $year      = $body['year']       ?? $existing['year_of_study'];
    $isActive  = $body['is_active']  ?? $existing['is_active'];
    $isLocked  = $body['is_locked']  ?? $existing['is_locked'];
    $pass      = $body['password']   ?? '';

    if ($pass && strlen($pass) >= 8) {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        dbExec('UPDATE users SET full_name_ar=?, email=?, specialization=?, year_of_study=?, is_active=?, is_locked=?, password_hash=?, failed_login_attempts=0 WHERE id=?',
               [$fullName, $email ?: null, $spec, $year, $isActive ? 1 : 0, $isLocked ? 1 : 0, $hash, $id]);
    } else {
        dbExec('UPDATE users SET full_name_ar=?, email=?, specialization=?, year_of_study=?, is_active=?, is_locked=? WHERE id=?',
               [$fullName, $email ?: null, $spec, $year, $isActive ? 1 : 0, $isLocked ? 1 : 0, $id]);
    }

    logAudit($user['id'], 'USER_UPDATED', 'users', $id);
    $updated = dbQueryOne('SELECT * FROM users WHERE id=?', [$id]);
    jsonResponse(['user' => formatUser($updated)]);
}

// DELETE /api/users/:id
if ($method === 'DELETE' && $id) {
    requireRole($user, 'admin');
    if ($id === $user['id']) jsonResponse(['error' => 'لا يمكنك حذف حسابك الخاص'], 400);
    dbExec('DELETE FROM users WHERE id=?', [$id]);
    logAudit($user['id'], 'USER_DELETED', 'users', $id);
    jsonResponse(['message' => 'تم حذف المستخدم']);
}

jsonResponse(['error' => 'المسار غير موجود'], 404);