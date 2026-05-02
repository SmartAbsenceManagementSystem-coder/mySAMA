<?php
// api/routes/stats.php — إحصائيات لوحة الإدارة
// المتغيرات من index.php: $method, $id

$user = authenticate();
requireRole($user, 'admin', 'professor');

if ($method === 'GET' && !$id) {
    // ─── إحصائيات التبريرات ──────────────────────────────────────────────
    $totalJusts    = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM justifications")['c'] ?? 0);
    $pendingJusts  = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM justifications WHERE status IN ('pending','info_requested')")['c'] ?? 0);
    $acceptedJusts = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM justifications WHERE status='accepted'")['c'] ?? 0);
    $rejectedJusts = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM justifications WHERE status='rejected'")['c'] ?? 0);

    $acceptanceRate = $totalJusts > 0 ? round(($acceptedJusts / $totalJusts) * 100) : 0;

    // ─── إحصائيات الطعون ─────────────────────────────────────────────────
    $pendingAppeals = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM appeals WHERE status='pending'")['c'] ?? 0);

    // ─── إحصائيات المستخدمين ─────────────────────────────────────────────
    $totalProfessors = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM users WHERE role='professor' AND is_active=1")['c'] ?? 0);
    $totalStudents   = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM users WHERE role='student'  AND is_active=1")['c'] ?? 0);

    // ─── عدد التخصصات ────────────────────────────────────────────────────
    $totalSpecialties = (int)(dbQueryOne("SELECT COUNT(*) AS c FROM specialties WHERE is_active=1")['c'] ?? 0);

    // ─── هذا الشهر ───────────────────────────────────────────────────────
    $thisMonth = (int)(dbQueryOne(
        "SELECT COUNT(*) AS c FROM justifications WHERE YEAR(submitted_at)=YEAR(NOW()) AND MONTH(submitted_at)=MONTH(NOW())"
    )['c'] ?? 0);

    // ─── بيانات آخر 6 أشهر (للرسم البياني) ──────────────────────────────
    $monthlyData = dbQuery(
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS count
         FROM justifications
         WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(submitted_at, '%Y-%m')
         ORDER BY month ASC"
    );

    // ملء الأشهر الفارغة
    $monthMap = [];
    foreach ($monthlyData as $m) $monthMap[$m['month']] = (int)$m['count'];
    $filledMonths = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-$i months"));
        $filledMonths[] = ['month' => $key, 'count' => $monthMap[$key] ?? 0];
    }

    jsonResponse([
        'totalJustifications'   => $totalJusts,
        'pendingJustifications' => $pendingJusts,
        'acceptedJustifications'=> $acceptedJusts,
        'rejectedJustifications'=> $rejectedJusts,
        'acceptanceRate'        => $acceptanceRate,
        'pendingAppeals'        => $pendingAppeals,
        'totalProfessors'       => $totalProfessors,
        'totalStudents'         => $totalStudents,
        'totalSpecialties'      => $totalSpecialties,
        'thisMonth'             => $thisMonth,
        'monthlyData'           => $filledMonths,
    ]);
}

jsonResponse(['error' => 'المسار غير موجود'], 404);
