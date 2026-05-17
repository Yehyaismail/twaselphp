<?php
/**
 * Tawasul PHP Configuration
 *
 * هذا الملف هو نقطة التحكم الرئيسية في الاتصال بقاعدة البيانات وإعدادات الأمان.
 * عند النشر على استضافة مجانية غيّر القيم التالية حسب بيانات MySQL التي توفرها الاستضافة.
 */

return [
    // بيانات اتصال MySQL. يمكن أيضاً تمريرها عبر Environment Variables عند الاستضافة المتقدمة.
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'tawasul_php',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    // اسم جلسة PHP. تغيير الاسم يقلل تعارض الجلسات عند وجود أكثر من تطبيق على نفس الدومين.
    'session_name' => 'TAWASUL_SESSION',

    // الحد الأقصى لحجم الملف المسموح رفعه بالبايت. الافتراضي 25MB.
    'max_upload_size' => 25 * 1024 * 1024,

    // أنواع ملفات مسموحة. يمكن توسيعها مستقبلاً حسب الحاجة.
    'allowed_mime_prefixes' => ['image/', 'video/', 'audio/'],
    'allowed_mime_exact' => [
        'application/pdf', 'application/zip', 'application/x-zip-compressed',
        'text/plain', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream'
    ],

    // بيانات المدير الافتراضي للتركيب الأول. غيّر كلمة المرور فوراً بعد أول دخول.
    'admin' => [
        'email' => getenv('ADMIN_EMAIL') ?: 'admin@example.com',
        'password' => getenv('ADMIN_PASSWORD') ?: 'admin123',
        'name' => getenv('ADMIN_NAME') ?: 'Admin',
    ],
];
