<?php
// api/routes/justifications.php — إدارة التبريرات
// المتغيرات من index.php: $method, $id, $sub

$user = authenticate();

/* ─── مساعد: تنسيق تبرير واحد للإرجاع ────────────────────────────────── */
function formatJustification(array $j): array {
    $meta = [];
    if ($j['text_content']) {
        $decoded = json_decode($j['text_content'], true);
        if (is_array($decoded)) $meta = $decoded;
    }
    return [
        'id'              => $j['id'],
        'date'            => $j['absence_date'] ?? ($meta['date'] ?? ''),
        'timeFrom'        => $meta['timeFrom'] ?? '',
        'timeTo'          => $meta['timeTo']   ?? '',
        'sessions'        => $meta['sessions'] ?? [],
        'sessionType'     => $j['session_type'] ?? ($meta['sessionType'] ?? ''),
        'sessionTypeLabel'=> $j['session_type'] ?? ($meta['sessionType'] ?? ''),
        'notes'           => $meta['notes']    ?? ($j['text_content'] ?? ''),
        'status'          => $j['status'],
        'fileName'        => $j['file_original_name'] ?? null,
        'studentName'     => $j['student_name']    ?? '',
        'studentSpecialty'=> $j['student_specialty'] ?? '',
        'rejectionReason' => $j['review_notes']   ?? '',
        'submittedAt'     => $j['submitted_at'],
        'reviewedAt'      => $j['reviewed_at']    ?? null,
    ];
}

/* ──────────────────────────────────────────────────────────────────────────
   GET /api/justifications/:id/file  — تحميل ملف التبرير
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'GET' && $id && $sub === 'file') {
    $just = dbQueryOne(
        'SELECT j.*, a.student_id FROM justifications j
         JOIN absences a ON j.absence_id = a.id WHERE j.id = ?', [$id]
    );
    if (!$just) jsonResponse(['error' => 'التبرير غير موجود'], 404);

    // التحقق من الصلاحية
    if ($user['role'] === 'student' && $just['student_id'] !== $user['id'])
        jsonResponse(['error' => 'غير مصرح'], 403);

    if (!$just['file_path'] || !file_exists($just['file_path']))
        jsonResponse(['error' => 'الملف غير موجود'], 404);

    $mime = $just['file_type'] ?: mime_content_type($just['file_path']) ?: 'application/octet-stream';
    $name = $just['file_original_name'] ?: basename($just['file_path']);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . filesize($just['file_path']));
    readfile($just['file_path']);
    exit;
}

/* ──────────────────────────────────────────────────────────────────────────
   POST /api/justifications/:id/review  — مراجعة تبرير
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'POST' && $id && $sub === 'review') {
    requireRole($user, 'admin', 'professor');
    $body     = getBody();
    $decision = $body['decision'] ?? '';
    $notes    = trim($body['notes'] ?? '');

    if (!in_array($decision, ['accepted', 'rejected', 'info_requested']))
        jsonResponse(['error' => 'قرار غير صالح'], 400);
    if ($decision === 'rejected' && !$notes)
        jsonResponse(['error' => 'يرجى كتابة سبب الرفض'], 400);

    $just = dbQueryOne('SELECT * FROM justifications WHERE id = ?', [$id]);
    if (!$just) jsonResponse(['error' => 'التبرير غير موجود'], 404);

    dbExec(
        'UPDATE justifications SET status=?, review_notes=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?',
        [$decision, $notes, $user['id'], $id]
    );

    // إذا قُبل → mark الغياب مبرراً
    if ($decision === 'accepted') {
        dbExec('UPDATE absences SET is_justified=1 WHERE id=?', [$just['absence_id']]);
    }

    logAudit($user['id'], 'JUSTIFICATION_REVIEWED', 'justifications', $id, ['decision' => $decision]);
    jsonResponse(['message' => 'تم تحديث القرار']);
}

/* ──────────────────────────────────────────────────────────────────────────
   GET /api/justifications  — قائمة التبريرات (حسب الدور)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'GET' && !$id) {
    $status    = $_GET['status']    ?? null;
    $specialty = $_GET['specialty'] ?? null;
    $search    = $_GET['search']    ?? null;

    $sql = "SELECT j.*, a.absence_date, a.session_type, a.student_id,
                   u.full_name_ar AS student_name, u.specialization AS student_specialty
            FROM justifications j
            JOIN absences a ON j.absence_id = a.id
            JOIN users    u ON a.student_id = u.id
            WHERE 1=1";
    $params = [];

    // الطالب يرى تبريراته فقط
    if ($user['role'] === 'student') {
        $sql .= ' AND a.student_id = ?';
        $params[] = $user['id'];
    }
    // الأستاذ يرى تبريرات مواده فقط
    elseif ($user['role'] === 'professor') {
        $sql .= ' AND a.subject_id IN (SELECT id FROM subjects WHERE professor_id = ?)';
        $params[] = $user['id'];
    }

    if ($status && $status !== 'all') {
        if ($status === 'pending') {
            $sql .= " AND j.status IN ('pending','info_requested')";
        } else {
            $sql .= ' AND j.status = ?';
            $params[] = $status;
        }
    }

    if ($search) {
        $like = '%' . strtolower($search) . '%';
        $sql .= ' AND (LOWER(u.full_name_ar) LIKE ? OR LOWER(u.specialization) LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY j.submitted_at DESC';
    $rows = dbQuery($sql, $params);
    jsonResponse(['justifications' => array_map('formatJustification', $rows)]);
}

/* ──────────────────────────────────────────────────────────────────────────
   POST /api/justifications  — إضافة تبرير جديد (طالب)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'POST' && !$id) {
    requireRole($user, 'admin', 'student');

    // البيانات من multipart/form-data
    $date        = $_POST['date']         ?? '';
    $timeFrom    = $_POST['time_from']    ?? '';
    $timeTo      = $_POST['time_to']      ?? '';
    $sessionType = $_POST['session_type'] ?? 'cours';
    $sessionsRaw = $_POST['sessions']     ?? '[]';
    $notes       = trim($_POST['notes']   ?? '');

    if (!$date) jsonResponse(['error' => 'التاريخ مطلوب'], 400);

    $sessions = json_decode($sessionsRaw, true) ?? [];

    // اختيار subject_id (أول مادة أو null)
    $subjectId = !empty($sessions[0]['subjectId']) ? $sessions[0]['subjectId'] : null;
    if ($subjectId) {
        $subjectExists = dbQueryOne('SELECT id FROM subjects WHERE id = ?', [$subjectId]);
        if (!$subjectExists) $subjectId = null;
    }

    // إنشاء سجل غياب
    $absenceId = generateUUID();
    dbExec(
        'INSERT INTO absences (id, student_id, subject_id, absence_date, session_type, session_time)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$absenceId, $user['id'], $subjectId, $date, $sessionType, "$timeFrom-$timeTo"]
    );

    // معالجة الملف
    $filePath = null; $fileOrigName = null; $fileType = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = UPLOAD_DIR;
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $tmpFile  = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        $mimeType = $_FILES['file']['type'];
        $size     = $_FILES['file']['size'];

        if ($size > MAX_FILE_SIZE)
            jsonResponse(['error' => 'حجم الملف يتجاوز 5MB'], 400);

        $allowed = ['application/pdf','image/jpeg','image/png','image/gif'];
        if (!in_array($mimeType, $allowed))
            jsonResponse(['error' => 'نوع الملف غير مدعوم (PDF أو صور فقط)'], 400);

        $ext      = pathinfo($origName, PATHINFO_EXTENSION);
        $saveName = uniqid('just_', true) . '.' . $ext;
        $fullPath = $uploadDir . $saveName;
        if (!move_uploaded_file($tmpFile, $fullPath))
            jsonResponse(['error' => 'فشل رفع الملف'], 500);

        $filePath = $fullPath; $fileOrigName = $origName; $fileType = $mimeType;
    }

    // تخزين البيانات الإضافية كـ JSON في text_content
    $meta = json_encode([
        'notes'       => $notes,
        'sessions'    => $sessions,
        'timeFrom'    => $timeFrom,
        'timeTo'      => $timeTo,
        'sessionType' => $sessionType,
        'date'        => $date,
    ], JSON_UNESCAPED_UNICODE);

    $justId = generateUUID();
    dbExec(
        'INSERT INTO justifications (id, absence_id, student_id, text_content, file_path, file_original_name, file_type, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "pending")',
        [$justId, $absenceId, $user['id'], $meta, $filePath, $fileOrigName, $fileType]
    );

    logAudit($user['id'], 'JUSTIFICATION_SUBMITTED', 'justifications', $justId);
    $created = dbQueryOne(
        'SELECT j.*, a.absence_date, a.session_type, a.student_id, u.full_name_ar AS student_name, u.specialization AS student_specialty
         FROM justifications j JOIN absences a ON j.absence_id=a.id JOIN users u ON a.student_id=u.id WHERE j.id=?',
        [$justId]
    );
    jsonResponse(['justification' => formatJustification($created), 'message' => 'تم إرسال التبرير بنجاح'], 201);
}

/* ──────────────────────────────────────────────────────────────────────────
   PUT /api/justifications/:id  — تعديل تبرير (طالب/admin)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'PUT' && $id && !$sub) {
    $just = dbQueryOne(
        'SELECT j.*, a.student_id FROM justifications j JOIN absences a ON j.absence_id=a.id WHERE j.id=?', [$id]
    );
    if (!$just) jsonResponse(['error' => 'التبرير غير موجود'], 404);

    if ($user['role'] === 'student' && $just['student_id'] !== $user['id'])
        jsonResponse(['error' => 'غير مصرح'], 403);

    $body = getBody();
    $notes       = trim($body['notes']        ?? '');
    $sessionType = $body['session_type']      ?? '';
    $timeFrom    = $body['time_from']         ?? '';
    $timeTo      = $body['time_to']           ?? '';

    // تحديث meta
    $meta = [];
    if ($just['text_content']) {
        $meta = json_decode($just['text_content'], true) ?? [];
    }
    if ($notes)       $meta['notes']       = $notes;
    if ($sessionType) $meta['sessionType'] = $sessionType;
    if ($timeFrom)    $meta['timeFrom']    = $timeFrom;
    if ($timeTo)      $meta['timeTo']      = $timeTo;

    dbExec('UPDATE justifications SET text_content=? WHERE id=?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
    if ($sessionType) dbExec('UPDATE absences SET session_type=? WHERE id=?', [$sessionType, $just['absence_id']]);

    logAudit($user['id'], 'JUSTIFICATION_UPDATED', 'justifications', $id);
    jsonResponse(['message' => 'تم تحديث التبرير']);
}

/* ──────────────────────────────────────────────────────────────────────────
   DELETE /api/justifications/:id  — حذف تبرير
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'DELETE' && $id && !$sub) {
    requireRole($user, 'admin', 'student');
    $just = dbQueryOne(
        'SELECT j.*, a.student_id FROM justifications j JOIN absences a ON j.absence_id=a.id WHERE j.id=?', [$id]
    );
    if (!$just) jsonResponse(['error' => 'التبرير غير موجود'], 404);

    if ($user['role'] === 'student' && $just['student_id'] !== $user['id'])
        jsonResponse(['error' => 'غير مصرح'], 403);

    // حذف الملف من القرص
    if ($just['file_path'] && file_exists($just['file_path'])) {
        @unlink($just['file_path']);
    }

    dbExec('DELETE FROM justifications WHERE id=?', [$id]);
    logAudit($user['id'], 'JUSTIFICATION_DELETED', 'justifications', $id);
    jsonResponse(['message' => 'تم حذف التبرير']);
}

jsonResponse(['error' => 'المسار غير موجود'], 404);
