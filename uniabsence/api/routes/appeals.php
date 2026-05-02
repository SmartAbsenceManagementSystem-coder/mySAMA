<?php
// api/routes/appeals.php — إدارة الطعون
// المتغيرات من index.php: $method, $id, $sub

$user = authenticate();

/* ─── مساعد: تنسيق طعن واحد ──────────────────────────────────────────── */
function formatAppeal(array $ap): array {
    return [
        'id'              => $ap['id'],
        'justificationId' => $ap['justification_id'],
        'appealText'      => $ap['appeal_text'],
        'status'          => $ap['status'],
        'createdAt'       => $ap['created_at'],
        'resolvedAt'      => $ap['resolved_at'] ?? null,
        'studentName'     => $ap['student_name']      ?? '',
        'absenceDate'     => $ap['absence_date']      ?? '',
        'rejectionReason' => $ap['review_notes']      ?? '',
    ];
}

/* ──────────────────────────────────────────────────────────────────────────
   POST /api/appeals/:id/resolve  — البت في طعن (admin)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'POST' && $id && $sub === 'resolve') {
    requireRole($user, 'admin');
    $body     = getBody();
    $decision = $body['decision'] ?? '';
    if (!in_array($decision, ['accepted', 'rejected']))
        jsonResponse(['error' => 'القرار يجب أن يكون accepted أو rejected'], 400);

    $appeal = dbQueryOne('SELECT * FROM appeals WHERE id=?', [$id]);
    if (!$appeal) jsonResponse(['error' => 'الطعن غير موجود'], 404);

    dbExec(
        'UPDATE appeals SET status=?, resolved_at=NOW(), resolved_by=? WHERE id=?',
        [$decision, $user['id'], $id]
    );

    // إذا قُبل الطعن → تحديث حالة التبرير إلى مقبول
    if ($decision === 'accepted') {
        dbExec(
            "UPDATE justifications SET status='accepted', review_notes=NULL WHERE id=?",
            [$appeal['justification_id']]
        );
        // تمييز الغياب كمبرر
        $just = dbQueryOne('SELECT absence_id FROM justifications WHERE id=?', [$appeal['justification_id']]);
        if ($just) dbExec('UPDATE absences SET is_justified=1 WHERE id=?', [$just['absence_id']]);
    }

    logAudit($user['id'], 'APPEAL_RESOLVED', 'appeals', $id, ['decision' => $decision]);
    jsonResponse(['message' => $decision === 'accepted' ? 'تم قبول الطعن' : 'تم رفض الطعن']);
}

/* ──────────────────────────────────────────────────────────────────────────
   GET /api/appeals  — قائمة الطعون
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'GET' && !$id) {
    $sql = "SELECT ap.*, u.full_name_ar AS student_name,
                   a.absence_date, j.review_notes
            FROM appeals ap
            JOIN users u        ON ap.student_id      = u.id
            JOIN justifications j ON ap.justification_id = j.id
            JOIN absences a      ON j.absence_id       = a.id
            WHERE 1=1";
    $params = [];

    if ($user['role'] === 'student') {
        $sql .= ' AND ap.student_id = ?';
        $params[] = $user['id'];
    }

    $sql .= ' ORDER BY ap.created_at DESC';
    $rows = dbQuery($sql, $params);
    jsonResponse(['appeals' => array_map('formatAppeal', $rows)]);
}

/* ──────────────────────────────────────────────────────────────────────────
   POST /api/appeals  — تقديم طعن جديد (طالب)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'POST' && !$id) {
    requireRole($user, 'student', 'admin');
    $body         = getBody();
    $justId       = $body['justification_id'] ?? '';
    $appealText   = trim($body['appeal_text'] ?? '');

    if (!$justId || !$appealText)
        jsonResponse(['error' => 'معرّف التبرير ونص الطعن مطلوبان'], 400);

    // التحقق أن التبرير مرفوض وتابع للطالب
    $just = dbQueryOne(
        'SELECT j.*, a.student_id FROM justifications j JOIN absences a ON j.absence_id=a.id WHERE j.id=?',
        [$justId]
    );
    if (!$just) jsonResponse(['error' => 'التبرير غير موجود'], 404);
    if ($user['role'] === 'student' && $just['student_id'] !== $user['id'])
        jsonResponse(['error' => 'غير مصرح'], 403);
    if ($just['status'] !== 'rejected')
        jsonResponse(['error' => 'لا يمكن تقديم طعن إلا على تبرير مرفوض'], 400);

    // تأكد من عدم وجود طعن سابق
    $existing = dbQueryOne('SELECT id FROM appeals WHERE justification_id=? AND student_id=?', [$justId, $just['student_id']]);
    if ($existing) jsonResponse(['error' => 'لقد قدّمت طعناً لهذا التبرير من قبل'], 409);

    $appealId = generateUUID();
    dbExec(
        'INSERT INTO appeals (id, justification_id, student_id, appeal_text) VALUES (?,?,?,?)',
        [$appealId, $justId, $just['student_id'], $appealText]
    );

    logAudit($user['id'], 'APPEAL_SUBMITTED', 'appeals', $appealId);
    jsonResponse(['message' => 'تم تقديم الطعن بنجاح'], 201);
}

/* ──────────────────────────────────────────────────────────────────────────
   PUT /api/appeals/:id  — تعديل نص الطعن (قيد الانتظار فقط)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'PUT' && $id && !$sub) {
    requireRole($user, 'student', 'admin');
    $body       = getBody();
    $appealText = trim($body['appeal_text'] ?? '');
    if (!$appealText) jsonResponse(['error' => 'نص الطعن مطلوب'], 400);

    $appeal = dbQueryOne('SELECT * FROM appeals WHERE id=?', [$id]);
    if (!$appeal) jsonResponse(['error' => 'الطعن غير موجود'], 404);
    if ($user['role'] === 'student' && $appeal['student_id'] !== $user['id'])
        jsonResponse(['error' => 'غير مصرح'], 403);
    if ($appeal['status'] !== 'pending')
        jsonResponse(['error' => 'لا يمكن تعديل طعن تم البت فيه'], 400);

    dbExec('UPDATE appeals SET appeal_text=? WHERE id=?', [$appealText, $id]);
    jsonResponse(['message' => 'تم تحديث الطعن']);
}

/* ──────────────────────────────────────────────────────────────────────────
   DELETE /api/appeals/:id  — حذف طعن (admin)
   ──────────────────────────────────────────────────────────────────────── */
if ($method === 'DELETE' && $id && !$sub) {
    requireRole($user, 'admin');
    $appeal = dbQueryOne('SELECT id FROM appeals WHERE id=?', [$id]);
    if (!$appeal) jsonResponse(['error' => 'الطعن غير موجود'], 404);

    dbExec('DELETE FROM appeals WHERE id=?', [$id]);
    logAudit($user['id'], 'APPEAL_DELETED', 'appeals', $id);
    jsonResponse(['message' => 'تم حذف الطعن']);
}

jsonResponse(['error' => 'المسار غير موجود'], 404);
