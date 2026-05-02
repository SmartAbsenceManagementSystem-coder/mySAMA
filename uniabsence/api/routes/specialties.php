<?php
// api/routes/specialties.php — إدارة التخصصات والمواد
// المتغيرات المتاحة من index.php: $method, $id, $sub

$user = authenticate();

/* ─── مساعد: بناء قائمة التخصصات مع مواد كل تخصص ──────────────────────── */
function buildSpecialtiesList(): array {
    $specs = dbQuery(
        "SELECT s.id, s.name, s.faculty_code, s.is_active
         FROM specialties s WHERE s.is_active = 1 ORDER BY s.name"
    );
    foreach ($specs as &$sp) {
        $sp['subjects'] = dbQuery(
            "SELECT sub.id, sub.name_ar AS name, sub.academic_year AS year,
                    sub.professor_id AS teacherId,
                    u.full_name_ar AS teacherName
             FROM subjects sub
             LEFT JOIN users u ON sub.professor_id = u.id
             WHERE sub.department = ? AND sub.is_active = 1
             ORDER BY sub.academic_year, sub.name_ar",
            [$sp['name']]
        );
    }
    return $specs;
}

/* ──────────────────────────────────────────────────────────────────────────
   GET /api/specialties/list  — قائمة مبسّطة (للطلاب وقائمة التسجيل)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'GET' && $id === 'list') {
    $specs = buildSpecialtiesList();
    jsonResponse(['specialties' => $specs]);
}

/* ──────────────────────────────────────────────────────────────────────────
   GET /api/specialties/subjects  — كل المواد (للتبرير admin view)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'GET' && $id === 'subjects' && !$sub) {
    requireRole($user, 'admin', 'professor');
    $rows = dbQuery(
        "SELECT sub.id, sub.code, sub.name_ar AS name, sub.academic_year AS year,
                sub.department, sub.professor_id AS teacherId, u.full_name_ar AS teacherName
         FROM subjects sub
         LEFT JOIN users u ON sub.professor_id = u.id
         WHERE sub.is_active = 1
         ORDER BY sub.name_ar"
    );
    jsonResponse(['subjects' => $rows]);
}

/* ──────────────────────────────────────────────────────────────────────────
   DELETE /api/specialties/subjects/:subId  — حذف مادة
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'DELETE' && $id === 'subjects' && $sub) {
    requireRole($user, 'admin');
    $existing = dbQueryOne('SELECT id FROM subjects WHERE id = ?', [$sub]);
    if (!$existing) jsonResponse(['error' => 'المادة غير موجودة'], 404);
    dbExec('DELETE FROM subjects WHERE id = ?', [$sub]);
    logAudit($user['id'], 'SUBJECT_DELETED', 'subjects', $sub);
    jsonResponse(['message' => 'تم حذف المادة']);
}

/* ──────────────────────────────────────────────────────────────────────────
   POST /api/specialties/subjects  — إضافة مادة
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'POST' && $id === 'subjects' && !$sub) {
    requireRole($user, 'admin');
    $body       = getBody();
    $name       = trim($body['name'] ?? '');
    $department = trim($body['department'] ?? '');
    $profId     = $body['professor_id'] ?? null;
    $year       = isset($body['year']) ? (int)$body['year'] : 1;

    if (!$name || !$department)
        jsonResponse(['error' => 'اسم المادة والتخصص مطلوبان'], 400);

    $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name)) . rand(100, 999);
    dbExec(
        "INSERT INTO subjects (id, code, name_ar, professor_id, department, academic_year, faculty_code)
         VALUES (UUID(), ?, ?, ?, ?, ?, 'GEN')",
        [$code, $name, $profId ?: null, $department, $year]
    );
    $newSubj = dbQueryOne('SELECT id, name_ar AS name, academic_year AS year, professor_id AS teacherId FROM subjects WHERE code = ?', [$code]);
    logAudit($user['id'], 'SUBJECT_CREATED', 'subjects', $newSubj['id'] ?? '');
    jsonResponse(['subject' => $newSubj], 201);
}

/* ──────────────────────────────────────────────────────────────────────────
   PUT /api/specialties/subjects/:subId  — تعديل مادة
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'PUT' && $id === 'subjects' && $sub) {
    requireRole($user, 'admin');
    $body       = getBody();
    $name       = trim($body['name'] ?? '');
    $profId     = $body['professor_id'] ?? null;
    $year       = isset($body['year']) ? (int)$body['year'] : null;
    $department = trim($body['department'] ?? '');

    if (!$name) jsonResponse(['error' => 'اسم المادة مطلوب'], 400);

    $existing = dbQueryOne('SELECT * FROM subjects WHERE id = ?', [$sub]);
    if (!$existing) jsonResponse(['error' => 'المادة غير موجودة'], 404);

    dbExec(
        'UPDATE subjects SET name_ar = ?, professor_id = ?, academic_year = ?, department = ? WHERE id = ?',
        [$name, $profId ?: null, $year ?? $existing['academic_year'], $department ?: $existing['department'], $sub]
    );
    logAudit($user['id'], 'SUBJECT_UPDATED', 'subjects', $sub);
    jsonResponse(['message' => 'تم تحديث المادة']);
}

/* ──────────────────────────────────────────────────────────────────────────
   GET /api/specialties  — قائمة التخصصات الكاملة مع المواد (للإدارة)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'GET' && !$id) {
    requireRole($user, 'admin', 'professor', 'student');
    $specs = buildSpecialtiesList();
    jsonResponse(['specialties' => $specs]);
}

/* ──────────────────────────────────────────────────────────────────────────
   POST /api/specialties  — إضافة تخصص جديد
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'POST' && !$id) {
    requireRole($user, 'admin');
    $body = getBody();
    $name = trim($body['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'اسم التخصص مطلوب'], 400);

    $dup = dbQueryOne('SELECT id FROM specialties WHERE name = ?', [$name]);
    if ($dup) jsonResponse(['error' => 'التخصص موجود مسبقاً'], 409);

    dbExec("INSERT INTO specialties (id, name, faculty_code) VALUES (UUID(), ?, 'GEN')", [$name]);
    $new = dbQueryOne('SELECT * FROM specialties WHERE name = ?', [$name]);
    $new['subjects'] = [];
    logAudit($user['id'], 'SPECIALTY_CREATED', 'specialties', $new['id']);
    jsonResponse(['specialty' => $new], 201);
}

/* ──────────────────────────────────────────────────────────────────────────
   PUT /api/specialties/:id  — تعديل تخصص
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'PUT' && $id && $id !== 'subjects') {
    requireRole($user, 'admin');
    $body = getBody();
    $name = trim($body['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'اسم التخصص مطلوب'], 400);

    $existing = dbQueryOne('SELECT * FROM specialties WHERE id = ?', [$id]);
    if (!$existing) jsonResponse(['error' => 'التخصص غير موجود'], 404);

    // تحديث department في المواد المرتبطة
    dbExec('UPDATE subjects SET department = ? WHERE department = ?', [$name, $existing['name']]);
    dbExec('UPDATE specialties SET name = ? WHERE id = ?', [$name, $id]);
    logAudit($user['id'], 'SPECIALTY_UPDATED', 'specialties', $id);
    jsonResponse(['message' => 'تم تحديث التخصص']);
}

/* ──────────────────────────────────────────────────────────────────────────
   DELETE /api/specialties/:id  — حذف تخصص
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'DELETE' && $id && $id !== 'subjects') {
    requireRole($user, 'admin');
    $existing = dbQueryOne('SELECT * FROM specialties WHERE id = ?', [$id]);
    if (!$existing) jsonResponse(['error' => 'التخصص غير موجود'], 404);

    dbExec('DELETE FROM subjects WHERE department = ?', [$existing['name']]);
    dbExec('DELETE FROM specialties WHERE id = ?', [$id]);
    logAudit($user['id'], 'SPECIALTY_DELETED', 'specialties', $id);
    jsonResponse(['message' => 'تم حذف التخصص وجميع مواده']);
}

jsonResponse(['error' => 'المسار غير موجود'], 404);
