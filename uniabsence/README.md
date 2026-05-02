# 🎓 UniAbsence — نظام تبريرات الغياب الجامعي

منصة متكاملة لإدارة وتتبع غيابات الطلاب مع دعم التبريرات والطعون.

---

## 📁 هيكل المشروع

```
uniabsence/
├── index.html          ← الواجهة الأمامية (SPA)
├── index.php           ← نقطة الدخول الموحّدة
├── script.js           ← منطق الواجهة
├── style.css           ← التنسيقات
├── schema.sql          ← بنية قاعدة البيانات
├── .htaccess           ← إعادة توجيه Apache
├── uploads/            ← ملفات التبريرات المرفوعة
└── api/
    ├── index.php       ← راوتر الـ API
    ├── config.php      ← الإعدادات (DB, JWT…)
    ├── db.php          ← اتصال PDO
    ├── jwt.php         ← توليد/التحقق من JWT
    ├── middleware.php  ← المصادقة والصلاحيات
    └── routes/
        ├── auth.php            ← تسجيل الدخول، التسجيل، الجلسات
        ├── users.php           ← إدارة المستخدمين
        ├── specialties.php     ← التخصصات والمواد الدراسية
        ├── justifications.php  ← التبريرات + رفع الملفات
        ├── appeals.php         ← الطعون
        └── stats.php           ← إحصائيات الإدارة
```

---

## ⚙️ متطلبات التشغيل

| المكوّن   | الإصدار الأدنى |
|-----------|---------------|
| PHP       | 8.1+          |
| MySQL     | 8.0+          |
| Apache    | 2.4+ (مع mod_rewrite) أو Nginx |

---

## 🚀 التثبيت

### 1. إنشاء قاعدة البيانات

```bash
mysql -u root -p < schema.sql
```

هذا سيُنشئ قاعدة بيانات `uniabsence` مع جميع الجداول وحساب المدير الافتراضي:
- **رقم التسجيل:** `FAC-INFO-01`
- **كلمة المرور:** `Admin@123456`

### 2. إعداد متغيرات البيئة (اختياري)

يمكن تخصيص الإعدادات عبر متغيرات البيئة أو تعديل `api/config.php`:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=uniabsence
DB_USER=root
DB_PASS=your_password

JWT_SECRET=your_very_strong_secret_key
REFRESH_SECRET=another_very_strong_secret

FRONTEND_URL=http://localhost
```

### 3. Apache Virtual Host

```apache
<VirtualHost *:80>
    ServerName uniabsence.local
    DocumentRoot /var/www/uniabsence

    <Directory /var/www/uniabsence>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

تأكد من تفعيل `mod_rewrite`:
```bash
a2enmod rewrite
systemctl restart apache2
```

### 4. مجلد الرفع

```bash
mkdir -p uploads
chmod 755 uploads
chown www-data:www-data uploads
```

### 5. اختبار سريع (PHP Built-in Server)

```bash
cd /path/to/uniabsence
php -S localhost:8000 index.php
```

ثم افتح `http://localhost:8000`

---

## 🔐 الأدوار والصلاحيات

| الدور     | الصلاحيات |
|-----------|-----------|
| **admin** | إدارة كاملة: مستخدمون، تخصصات، تبريرات، طعون، إحصائيات |
| **professor** | عرض ومراجعة تبريرات مواده (قبول/رفض) |
| **student** | تقديم تبريرات وتتبعها، تقديم طعون |

---

## 🗂️ مسارات الـ API

### المصادقة `/api/auth`
| الطريقة | المسار | الوصف |
|---------|--------|-------|
| POST | `/api/auth/login` | تسجيل الدخول |
| POST | `/api/auth/register` | إنشاء حساب |
| GET | `/api/auth/me` | بيانات المستخدم الحالي |
| POST | `/api/auth/logout` | تسجيل الخروج |
| POST | `/api/auth/refresh` | تجديد الجلسة |
| GET | `/api/auth/pending` | طلبات التسجيل المعلّقة (admin) |
| POST | `/api/auth/pending/:id/decide` | قبول/رفض طلب (admin) |

### المستخدمون `/api/users`
| الطريقة | المسار | الوصف |
|---------|--------|-------|
| GET | `/api/users` | قائمة المستخدمين (admin) |
| POST | `/api/users` | إضافة مستخدم (admin) |
| PUT | `/api/users/:id` | تعديل مستخدم (admin) |
| DELETE | `/api/users/:id` | حذف مستخدم (admin) |
| GET | `/api/users/professors` | قائمة الأساتذة |
| GET | `/api/users/students` | قائمة الطلاب (admin) |

### التخصصات `/api/specialties`
| الطريقة | المسار | الوصف |
|---------|--------|-------|
| GET | `/api/specialties` | كل التخصصات مع المواد |
| GET | `/api/specialties/list` | قائمة مبسّطة (للطلاب) |
| POST | `/api/specialties` | إضافة تخصص (admin) |
| PUT | `/api/specialties/:id` | تعديل تخصص (admin) |
| DELETE | `/api/specialties/:id` | حذف تخصص (admin) |
| POST | `/api/specialties/subjects` | إضافة مادة (admin) |
| PUT | `/api/specialties/subjects/:id` | تعديل مادة (admin) |
| DELETE | `/api/specialties/subjects/:id` | حذف مادة (admin) |

### التبريرات `/api/justifications`
| الطريقة | المسار | الوصف |
|---------|--------|-------|
| GET | `/api/justifications` | قائمة التبريرات |
| POST | `/api/justifications` | إرسال تبرير + ملف |
| PUT | `/api/justifications/:id` | تعديل تبرير |
| DELETE | `/api/justifications/:id` | حذف تبرير |
| GET | `/api/justifications/:id/file` | تحميل ملف التبرير |
| POST | `/api/justifications/:id/review` | مراجعة تبرير (admin/professor) |

### الطعون `/api/appeals`
| الطريقة | المسار | الوصف |
|---------|--------|-------|
| GET | `/api/appeals` | قائمة الطعون |
| POST | `/api/appeals` | تقديم طعن (student) |
| PUT | `/api/appeals/:id` | تعديل طعن |
| DELETE | `/api/appeals/:id` | حذف طعن (admin) |
| POST | `/api/appeals/:id/resolve` | البت في طعن (admin) |

### الإحصائيات `/api/stats`
| الطريقة | المسار | الوصف |
|---------|--------|-------|
| GET | `/api/stats` | إحصائيات لوحة الإدارة |

---

## 🐛 استكشاف الأخطاء

**خطأ 500 - فشل الاتصال بقاعدة البيانات:**
تحقق من إعدادات `DB_*` في `api/config.php`.

**خطأ 401 - غير مصرح:**
تأكد أن ملفات تعريف الارتباط (Cookies) مفعّلة وأن `FRONTEND_URL` يطابق عنوان موقعك.

**الملفات لا تُرفع:**
تأكد أن مجلد `uploads/` موجود وله صلاحية الكتابة.
