-- ============================================================
--   schema.sql — UniAbsence | MySQL
--   شغّله مرة واحدة على قاعدة بيانات فارغة
-- ============================================================

CREATE DATABASE IF NOT EXISTS uniabsence CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uniabsence;

-- ─── جدول المستخدمين ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id                    CHAR(36)     PRIMARY KEY DEFAULT (UUID()),
    registration_number   VARCHAR(50)  UNIQUE NOT NULL,
    password_hash         TEXT         NOT NULL,
    role                  ENUM('admin','professor','student') NOT NULL,
    full_name_ar          VARCHAR(150) NOT NULL,
    email                 VARCHAR(150) UNIQUE,
    faculty_code          VARCHAR(20)  DEFAULT 'GEN',
    department            VARCHAR(100),
    specialization        VARCHAR(100),
    year_of_study         TINYINT      CHECK (year_of_study IS NULL OR (year_of_study >= 1 AND year_of_study <= 7)),
    is_active             TINYINT(1)   DEFAULT 1,
    is_pending            TINYINT(1)   DEFAULT 0,
    is_locked             TINYINT(1)   DEFAULT 0,
    failed_login_attempts INT          DEFAULT 0,
    last_login            DATETIME,
    created_at            DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── جدول التخصصات ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS specialties (
    id           CHAR(36)     PRIMARY KEY DEFAULT (UUID()),
    name         VARCHAR(100) UNIQUE NOT NULL,
    faculty_code VARCHAR(20)  DEFAULT 'GEN',
    is_active    TINYINT(1)   DEFAULT 1,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- ─── جدول المواد الدراسية ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subjects (
    id            CHAR(36)     PRIMARY KEY DEFAULT (UUID()),
    code          VARCHAR(50)  UNIQUE NOT NULL,
    name_ar       VARCHAR(150) NOT NULL,
    professor_id  CHAR(36),
    faculty_code  VARCHAR(20)  DEFAULT 'GEN',
    department    VARCHAR(100),
    semester      VARCHAR(10)  DEFAULT '1',
    academic_year VARCHAR(10)  DEFAULT '1',
    is_active     TINYINT(1)   DEFAULT 1,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ─── جدول الغيابات ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS absences (
    id            CHAR(36)    PRIMARY KEY DEFAULT (UUID()),
    student_id    CHAR(36)    NOT NULL,
    subject_id    CHAR(36)    NOT NULL,
    absence_date  DATE        NOT NULL,
    session_type  ENUM('cours','td','tp','exam') DEFAULT 'cours',
    session_time  VARCHAR(20),
    is_justified  TINYINT(1)  DEFAULT 0,
    created_at    DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- ─── جدول التبريرات ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS justifications (
    id                  CHAR(36)     PRIMARY KEY DEFAULT (UUID()),
    absence_id          CHAR(36)     NOT NULL,
    student_id          CHAR(36)     NOT NULL,
    text_content        TEXT,
    file_path           TEXT,
    file_original_name  VARCHAR(255),
    file_type           VARCHAR(100),
    status              ENUM('pending','accepted','rejected','info_requested') DEFAULT 'pending',
    submitted_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
    reviewed_at         DATETIME,
    reviewed_by         CHAR(36),
    review_notes        TEXT,
    FOREIGN KEY (absence_id)  REFERENCES absences(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id)  REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)     ON DELETE SET NULL
);

-- ─── جدول الطعون ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS appeals (
    id                 CHAR(36)    PRIMARY KEY DEFAULT (UUID()),
    justification_id   CHAR(36)    NOT NULL,
    student_id         CHAR(36)    NOT NULL,
    appeal_text        TEXT        NOT NULL,
    status             ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at         DATETIME    DEFAULT CURRENT_TIMESTAMP,
    resolved_at        DATETIME,
    resolved_by        CHAR(36),
    FOREIGN KEY (justification_id) REFERENCES justifications(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id)       REFERENCES users(id)          ON DELETE CASCADE,
    FOREIGN KEY (resolved_by)      REFERENCES users(id)          ON DELETE SET NULL
);

-- ─── جدول Refresh Tokens ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id          CHAR(36)  PRIMARY KEY DEFAULT (UUID()),
    user_id     CHAR(36)  NOT NULL,
    token_hash  TEXT      NOT NULL,
    expires_at  DATETIME  NOT NULL,
    created_at  DATETIME  DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── جدول سجل الأحداث ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
    id          CHAR(36)    PRIMARY KEY DEFAULT (UUID()),
    user_id     CHAR(36),
    action      VARCHAR(80) NOT NULL,
    resource    VARCHAR(50),
    resource_id VARCHAR(100),
    ip          VARCHAR(50),
    metadata    JSON        DEFAULT (JSON_OBJECT()),
    created_at  DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ─── فهارس للأداء ────────────────────────────────────────────────────────────
CREATE INDEX idx_absences_student   ON absences(student_id);
CREATE INDEX idx_absences_subject   ON absences(subject_id);
CREATE INDEX idx_justs_student      ON justifications(student_id);
CREATE INDEX idx_justs_status       ON justifications(status);
CREATE INDEX idx_appeals_student    ON appeals(student_id);
CREATE INDEX idx_audit_user         ON audit_logs(user_id);
CREATE INDEX idx_users_reg          ON users(registration_number);

-- ─── حساب الإدارة الافتراضي — كلمة المرور: Admin@123456 ─────────────────────
INSERT IGNORE INTO users (
    id, registration_number, password_hash, role,
    full_name_ar, email, faculty_code, is_active
) VALUES (
    UUID(),
    'FAC-INFO-01',
    '$2y$12$9SG1aKzeF1kbY0yo9t/coOkOEpQMuZZMZKFJvnRNBX5YwZrCEOhpS',
    'admin',
    'مدير النظام',
    'admin@univ.dz',
    'GEN',
    1
);

SELECT 'تم إنشاء قاعدة البيانات بنجاح ✅' AS النتيجة;
SELECT registration_number, full_name_ar, role, is_active FROM users;