-- Tawasul PHP/MySQL Database Schema
-- هذا الملف جاهز للاستيراد على phpMyAdmin أو MySQL CLI.
-- تم تصميم الجداول بعلاقات واضحة وفهارس أداء للمحادثات والرسائل والملفات.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL COMMENT 'اسم المستخدم الظاهر في التطبيق',
    email VARCHAR(190) NOT NULL COMMENT 'البريد الإلكتروني ويجب أن يكون فريداً',
    password_hash VARCHAR(255) NOT NULL COMMENT 'كلمة المرور مشفرة عبر password_hash في PHP',
    avatar VARCHAR(255) NULL COMMENT 'مسار صورة المستخدم عند إضافة الميزة مستقبلاً',
    role ENUM('user','admin') NOT NULL DEFAULT 'user' COMMENT 'صلاحية المستخدم: عادي أو مدير',
    online TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'حالة الاتصال الحالية',
    last_seen DATETIME NULL COMMENT 'آخر ظهور للمستخدم',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_online (online),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حسابات مستخدمي التطبيق';

CREATE TABLE IF NOT EXISTS files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id CHAR(36) NOT NULL COMMENT 'معرف ملف UUID مستقل عن رقم السجل',
    uploader_id BIGINT UNSIGNED NOT NULL COMMENT 'مالك الملف/رافعه',
    storage_path VARCHAR(500) NOT NULL COMMENT 'المسار الداخلي الآمن للملف داخل uploads',
    original_filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    category ENUM('image','video','voice','file') NOT NULL DEFAULT 'file',
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_files_file_id (file_id),
    UNIQUE KEY uq_files_storage_path (storage_path),
    KEY idx_files_uploader (uploader_id),
    KEY idx_files_deleted (is_deleted),
    CONSTRAINT fk_files_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ملفات الصور والفيديو والصوت والمرفقات';

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id BIGINT UNSIGNED NOT NULL,
    receiver_id BIGINT UNSIGNED NOT NULL,
    text TEXT NULL,
    message_type ENUM('text','image','video','voice','file') NOT NULL DEFAULT 'text',
    file_id BIGINT UNSIGNED NULL,
    file_url VARCHAR(500) NULL COMMENT 'يحفظ مسار الملف لتوافق API مع الواجهة',
    file_name VARCHAR(255) NULL,
    file_type VARCHAR(120) NULL,
    reply_to BIGINT UNSIGNED NULL COMMENT 'رد على رسالة سابقة',
    status ENUM('sent','delivered','read') NOT NULL DEFAULT 'sent',
    edited TINYINT(1) NOT NULL DEFAULT 0,
    deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'حذف للجميع مع إبقاء أثر الرسالة',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_pair_time (sender_id, receiver_id, created_at),
    KEY idx_messages_receiver_status (receiver_id, status),
    KEY idx_messages_created (created_at),
    KEY idx_messages_reply (reply_to),
    KEY idx_messages_file (file_id),
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_reply FOREIGN KEY (reply_to) REFERENCES messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسائل المحادثات الفردية';

CREATE TABLE IF NOT EXISTS message_hidden (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id),
    KEY idx_hidden_user (user_id),
    CONSTRAINT fk_hidden_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حذف الرسائل لدى مستخدم واحد فقط';

CREATE TABLE IF NOT EXISTS message_reactions (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id),
    KEY idx_reactions_emoji (emoji),
    CONSTRAINT fk_reactions_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاعلات الإيموجي على الرسائل';

CREATE TABLE IF NOT EXISTS nicknames (
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'المستخدم الذي عيّن اللقب',
    other_user_id BIGINT UNSIGNED NOT NULL COMMENT 'الشخص الذي يظهر له اللقب',
    nickname VARCHAR(120) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, other_user_id),
    CONSTRAINT fk_nick_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_nick_other FOREIGN KEY (other_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ألقاب خاصة تظهر لصاحب الحساب فقط';

CREATE TABLE IF NOT EXISTS typing_status (
    user_id BIGINT UNSIGNED NOT NULL,
    receiver_id BIGINT UNSIGNED NOT NULL,
    is_typing TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, receiver_id),
    KEY idx_typing_receiver (receiver_id),
    CONSTRAINT fk_typing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_typing_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مؤشر يكتب الآن مع انتهاء تلقائي من الكود';

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    avatar_color VARCHAR(20) NOT NULL DEFAULT '#059669',
    chat_bg_color VARCHAR(20) NULL,
    chat_bg_image LONGTEXT NULL,
    sent_bubble_color VARCHAR(20) NULL,
    received_bubble_color VARCHAR(20) NULL,
    font_family VARCHAR(120) NULL,
    font_size ENUM('sm','base','lg','xl') NOT NULL DEFAULT 'base',
    theme ENUM('light','dark') NOT NULL DEFAULT 'light',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات التخصيص القابلة للتوسعة مستقبلاً';

SET FOREIGN_KEY_CHECKS = 1;

-- حساب المدير الافتراضي يتم إنشاؤه أيضاً من install.php لضمان توافق التشفير.
-- البريد: admin@example.com
-- كلمة المرور: admin123
-- ملاحظة: غيّر كلمة مرور المدير فور النشر.
