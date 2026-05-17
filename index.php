<?php
/**
 * Tawasul PHP Front Controller
 *
 * هذه الصفحة تعرض واجهة التطبيق فقط؛ أما العمليات الحقيقية فتتم عبر /api/index.php.
 * اعتمدنا هذا الفصل ليبقى التصميم قابلاً للتطوير دون خلط منطق قاعدة البيانات مع HTML.
 */
require_once __DIR__ . '/config/bootstrap.php';
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>محادثات</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="bg-slate-50 dark:bg-slate-900">
    <!--
        الجذر الوحيد للتطبيق. يتم رسم صفحات تسجيل الدخول والدردشة ولوحة التحكم داخله.
        يمكن مستقبلاً تحويله إلى قوالب PHP منفصلة إذا رغبت في تقليل JavaScript.
    -->
    <main id="app" class="min-h-screen"></main>
    <script src="assets/js/app.js"></script>
</body>
</html>
