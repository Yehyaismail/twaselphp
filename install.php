<?php
/**
 * Tawasul Installer
 *
 * استخدم هذا الملف مرة واحدة بعد رفع المشروع على الاستضافة.
 * بعد نجاح التركيب احذف install.php أو غيّر اسمه حمايةً للتطبيق.
 */
$config = require __DIR__ . '/config/config.php';
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $d = $config['db'];
        $dsnNoDb = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
        $pdo = new PDO($dsnNoDb, $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$d['name']}`");
        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $pdo->exec($sql);

        // إنشاء المدير الافتراضي بتشفير PHP الصحيح. ON DUPLICATE يمنع التكرار عند إعادة التركيب.
        $hash = password_hash($config['admin']['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,online,created_at) VALUES (?,?,?,?,0,NOW()) ON DUPLICATE KEY UPDATE role=VALUES(role)');
        $stmt->execute([$config['admin']['name'], $config['admin']['email'], $hash, 'admin']);
        $message = 'تم تركيب قاعدة البيانات بنجاح. بيانات المدير: ' . $config['admin']['email'] . ' / ' . $config['admin']['password'] . ' — غيّر كلمة المرور فوراً.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تركيب تواصل</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4"><main class="bg-white border border-slate-200 shadow-xl rounded-2xl p-8 max-w-xl w-full"><h1 class="text-3xl font-light mb-3">تركيب مشروع تواصل PHP</h1><p class="text-slate-600 mb-6">سيتصل هذا المعالج بقاعدة MySQL وفق القيم الموجودة في <code>config/config.php</code> ثم ينشئ الجداول وحساب المدير.</p><?php if($message): ?><div class="p-4 rounded-lg bg-emerald-50 text-emerald-700 mb-4"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><?php if($error): ?><div class="p-4 rounded-lg bg-red-50 text-red-700 mb-4">خطأ: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post"><button class="w-full bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl py-3">بدء التركيب الآن</button></form><p class="text-xs text-slate-400 mt-5">بعد النجاح احذف هذا الملف من الخادم.</p></main></body></html>
