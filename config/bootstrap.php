<?php
/**
 * Bootstrap
 *
 * يحتوي هذا الملف على دوال مشتركة تستخدمها كل صفحات API.
 * تم فصلها هنا لتسهيل الصيانة والتطوير مستقبلاً بدلاً من تكرار الكود.
 */

$config = require __DIR__ . '/config.php';

// إعدادات جلسة أكثر أماناً. في HTTPS فعّل secure=true من الاستضافة إن رغبت.
if (session_status() === PHP_SESSION_NONE) {
    session_name($config['session_name']);
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 7,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** إنشاء اتصال PDO آمن بقاعدة MySQL. */
function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d = $config['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/** إرسال JSON مع كود HTTP مناسب. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** قراءة JSON من جسم الطلب مع fallback للـ form-data. */
function request_data(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST ?: [];
}

/** اختصار آمن لتحويل قيمة إلى رقم موجب. */
function as_id($value): int
{
    return max(0, (int)$value);
}

/** إرجاع المستخدم الحالي أو خطأ 401. */
function current_user(): array
{
    if (empty($_SESSION['user_id'])) {
        json_response(['detail' => 'Not authenticated'], 401);
    }
    $stmt = db()->prepare('SELECT id, name, email, avatar, role, online, last_seen, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        json_response(['detail' => 'User not found'], 401);
    }
    $user['id'] = (string)$user['id'];
    $user['online'] = (bool)$user['online'];
    return $user;
}

/** التحقق من صلاحية المدير. */
function require_admin(): array
{
    $user = current_user();
    if (($user['role'] ?? 'user') !== 'admin') {
        json_response(['detail' => 'Admin access required'], 403);
    }
    return $user;
}

/** تنظيف نصوص المستخدم لمنع القيم غير المرغوبة. */
function clean_string($value, int $max = 10000): string
{
    $value = trim((string)$value);
    if (mb_strlen($value, 'UTF-8') > $max) {
        $value = mb_substr($value, 0, $max, 'UTF-8');
    }
    return $value;
}

/** إرجاع أول حرفين من الاسم للواجهة عند الحاجة. */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name));
    $out = '';
    foreach ($parts as $p) {
        $out .= mb_substr($p, 0, 1, 'UTF-8');
        if (mb_strlen($out, 'UTF-8') >= 2) break;
    }
    return mb_strtoupper($out ?: 'U', 'UTF-8');
}

/** تصنيف الملف بناءً على MIME. */
function file_category(string $mime, string $name = ''): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/') || in_array($ext, ['webm','mp3','wav','ogg','aac','m4a'], true)) return 'voice';
    return 'file';
}
