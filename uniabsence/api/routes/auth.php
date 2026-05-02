<?php
// api/routes/auth.php — مصادقة المستخدمين وإدارة التسجيل
// المتغيرات المتاحة: $method, $id, $sub

// POST /api/auth/login
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && $id === 'login') {
    $body = getBody();
    $regNum = trim($body['registration_number'] ?? '');
    $pass   = $body['password'] ?? '';
    if (!$regNum || !$pass)
        jsonResponse(['error' => 'رقم التسجيل وكلمة المرور مطلوبان'], 400);

    $user = dbQueryOne('SELECT * FROM users WHERE registration_number = ?', [$regNum]);
    if (!$user)
        jsonResponse(['error' => 'رقم التسجيل أو كلمة المرور غير صحيحة'], 401);

    if ($user['is_pending'])
        jsonResponse(['error' => 'طلب تسجيلك لا يزال قيد المراجعة من الإدارة'], 403);
    if (!$user['is_active'])
        jsonResponse(['error' => 'حسابك معطّل، تواصل مع الإدارة'], 403);
    if ($user['is_locked'])
        jsonResponse(['error' => 'حسابك مقفل بسبب محاولات دخول متعددة'], 403);

    if (!password_verify($pass, $user['password_hash'])) {
        $attempts = ($user['failed_login_attempts'] ?? 0) + 1;
        $lock     = $attempts >= 5 ? 1 : 0;
        dbExec('UPDATE users SET failed_login_attempts=?, is_locked=? WHERE id=?', [$attempts, $lock, $user['id']]);
        if ($lock) jsonResponse(['error' => 'تم قفل حسابك بعد 5 محاولات فاشلة'], 403);
        jsonResponse(['error' => 'رقم التسجيل أو كلمة المرور غير صحيحة'], 401);
    }

    dbExec('UPDATE users SET failed_login_attempts=0, last_login=NOW() WHERE id=?', [$user['id']]);

    $access  = signAccessToken($user['id'], $user['role']);
    $refresh = signRefreshToken($user['id']);
    $hash    = password_hash($refresh, PASSWORD_BCRYPT, ['cost' => 8]);
    $expires = date('Y-m-d H:i:s', time() + REFRESH_EXPIRES);
    dbExec('INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at) VALUES (UUID(),?,?,?)',
           [$user['id'], $hash, $expires]);

    setTokenCookies($access, $refresh);
    logAudit($user['id'], 'LOGIN', 'users', $user['id']);
    jsonResponse(['user' => formatUser($user), 'message' => 'تم تسجيل الدخول بنجاح']);
}

// POST /api/auth/register
if ($method === 'POST' && $id === 'register') {
    $body = getBody();
    $firstname = trim($body['firstname'] ?? '');
    $lastname  = trim($body['lastname']  ?? '');
    $email     = strtolower(trim($body['email'] ?? ''));
    $role      = $body['role']      ?? '';
    $specialty = $body['specialty'] ?? null;
    $year      = $body['year']      ?? null;
    $pass      = $body['password']  ?? '';
    $regNum    = trim($body['registration_number'] ?? '');

    if (!$firstname || !$lastname || !$role || !$pass || !$regNum)
        jsonResponse(['error' => 'البيانات الأساسية مطلوبة'], 400);
    if (!in_array($role, ['student', 'professor']))
        jsonResponse(['error' => 'الدور يجب أن يكون طالب أو أستاذ'], 400);
    if (strlen($pass) < 8)
        jsonResponse(['error' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'], 400);

    $dup = dbQueryOne(
        'SELECT id FROM users WHERE registration_number=? OR (email=? AND email IS NOT NULL AND email != "")',
        [$regNum, $email ?: '__NONE__']
    );
    if ($dup) jsonResponse(['error' => 'رقم التسجيل أو البريد الإلكتروني مستخدم بالفعل'], 409);

    $hash      = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $isPending = ($role === 'professor') ? 1 : 0;
    $isActive  = ($role === 'student')   ? 1 : 0;

    dbExec(
        'INSERT INTO users (id, registration_number, password_hash, role, full_name_ar, email, specialization, year_of_study, is_active, is_pending, faculty_code)
         VALUES (UUID(),?,?,?,?,?,?,?,?,?,?)',
        [$regNum, $hash, $role, "$firstname $lastname", $email ?: null,
         $specialty ?: null, $role === 'student' ? ((int)$year ?: null) : null,
         $isActive, $isPending, 'GEN']
    );

    $newUser = dbQueryOne('SELECT * FROM users WHERE registration_number=?', [$regNum]);
    logAudit($newUser['id'], 'REGISTER', 'users', $newUser['id'], ['role' => $role]);

    jsonResponse([
        'message'             => $isPending
            ? 'تم تقديم طلب التسجيل، سيتم إعلامك عبر البريد الإلكتروني بعد المراجعة'
            : 'تم إنشاء الحساب بنجاح، يمكنك تسجيل الدخول الآن',
        'registration_number' => $regNum,
        'pending'             => (bool)$isPending,
    ], 201);
}

// GET /api/auth/me
if ($method === 'GET' && $id === 'me') {
    $user = authenticate();
    jsonResponse(['user' => formatUser($user)]);
}

// POST /api/auth/logout
if ($method === 'POST' && $id === 'logout') {
    $rt = $_COOKIE['refresh_token'] ?? null;
    if ($rt) {
        $payload = jwtVerify($rt, REFRESH_SECRET);
        if ($payload) dbExec('DELETE FROM refresh_tokens WHERE user_id=?', [$payload['userId']]);
    }
    setcookie('access_token',  '', time() - 3600, '/', '', false, true);
    setcookie('refresh_token', '', time() - 3600, '/', '', false, true);
    jsonResponse(['message' => 'تم تسجيل الخروج']);
}

// POST /api/auth/refresh
if ($method === 'POST' && $id === 'refresh') {
    $rt = $_COOKIE['refresh_token'] ?? null;
    if (!$rt) jsonResponse(['error' => 'لا يوجد refresh token', 'code' => 'NO_REFRESH'], 401);
    $payload = jwtVerify($rt, REFRESH_SECRET);
    if (!$payload) {
        setcookie('access_token',  '', time() - 3600, '/', '', false, true);
        setcookie('refresh_token', '', time() - 3600, '/', '', false, true);
        jsonResponse(['error' => 'refresh token غير صالح', 'code' => 'REFRESH_INVALID'], 401);
    }
    $user = dbQueryOne('SELECT * FROM users WHERE id=? AND is_active=1', [$payload['userId']]);
    if (!$user) jsonResponse(['error' => 'المستخدم غير موجود', 'code' => 'USER_NOT_FOUND'], 401);
    $newAccess = signAccessToken($user['id'], $user['role']);
    setTokenCookies($newAccess);
    jsonResponse(['user' => formatUser($user), 'message' => 'تم تجديد الجلسة']);
}

// GET /api/auth/pending
if ($method === 'GET' && $id === 'pending') {
    $user = authenticate(); requireRole($user, 'admin');
    $rows = dbQuery('SELECT id, registration_number, full_name_ar, email, specialization, year_of_study, role, created_at FROM users WHERE is_pending=1 ORDER BY created_at ASC');
    jsonResponse(['pending' => $rows]);
}

// POST /api/auth/pending/:uuid/decide   ($id='pending', $sub=uuid, $subsub='decide')
if ($method === 'POST' && $id === 'pending' && $sub && ($subsub ?? '') === 'decide') {
    $targetId = $sub;
    $user = authenticate(); requireRole($user, 'admin');
    $body     = getBody();
    $decision = $body['decision'] ?? '';
    $reason   = $body['rejectionReason'] ?? '';
    if (!in_array($decision, ['accepted', 'rejected']))
        jsonResponse(['error' => 'القرار يجب أن يكون accepted أو rejected'], 400);

    $pending = dbQueryOne('SELECT * FROM users WHERE id=? AND is_pending=1', [$targetId]);
    if (!$pending) jsonResponse(['error' => 'الطلب غير موجود'], 404);

    if ($decision === 'accepted') {
        dbExec('UPDATE users SET is_pending=0, is_active=1 WHERE id=?', [$targetId]);
    } else {
        dbExec('DELETE FROM users WHERE id=?', [$targetId]);
    }

    logAudit($user['id'], 'REGISTRATION_' . strtoupper($decision), 'users', $targetId);
    jsonResponse(['message' => $decision === 'accepted' ? 'تم قبول طلب التسجيل وتفعيل الحساب' : 'تم رفض طلب التسجيل']);
}

jsonResponse(['error' => 'المسار غير موجود'], 404);