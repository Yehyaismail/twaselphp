<?php
/**
 * Tawasul API Router
 *
 * كل الطلبات من الواجهة تمر من هنا بصيغة /api/{endpoint}.
 * تم استخدام Prepared Statements في جميع الاستعلامات لمنع SQL Injection.
 */
require_once __DIR__ . '/../config/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api#', '', $uri);
$path = '/' . trim($path, '/');

// دعم طلبات OPTIONS في بعض الاستضافات أو عند استخدام دومين منفصل.
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    exit;
}

try {
    /** ===================== Auth ===================== */
    if ($method === 'POST' && $path === '/auth/register') {
        $data = request_data();
        $name = clean_string($data['name'] ?? '', 120);
        $email = strtolower(clean_string($data['email'] ?? '', 190));
        $password = (string)($data['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 6) {
            json_response(['detail' => 'الرجاء إدخال اسم وبريد صحيح وكلمة مرور من 6 أحرف على الأقل'], 422);
        }
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) json_response(['detail' => 'Email already registered'], 400);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO users (name,email,password_hash,online,last_seen,created_at) VALUES (?,?,?,?,NOW(),NOW())');
        $stmt->execute([$name, $email, $hash, 1]);
        $_SESSION['user_id'] = (int)db()->lastInsertId();
        json_response(['id'=>(string)$_SESSION['user_id'],'name'=>$name,'email'=>$email,'avatar'=>null,'online'=>true,'role'=>'user']);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $data = request_data();
        $email = strtolower(clean_string($data['email'] ?? '', 190));
        $password = (string)($data['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_response(['detail' => 'Invalid email or password'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        db()->prepare('UPDATE users SET online=1,last_seen=NOW() WHERE id=?')->execute([$user['id']]);
        json_response(['id'=>(string)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'avatar'=>$user['avatar'],'online'=>true,'role'=>$user['role']]);
    }

    if ($method === 'GET' && $path === '/auth/me') {
        json_response(current_user());
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        $u = current_user();
        db()->prepare('UPDATE users SET online=0,last_seen=NOW() WHERE id=?')->execute([$u['id']]);
        $_SESSION = [];
        session_destroy();
        json_response(['message' => 'Logged out successfully']);
    }

    if ($method === 'POST' && $path === '/auth/refresh') {
        json_response(current_user());
    }

    /** ===================== Users ===================== */
    if ($method === 'GET' && $path === '/users') {
        $u = current_user();
        $stmt = db()->prepare('SELECT id,name,email,avatar,online,last_seen FROM users WHERE id <> ? ORDER BY online DESC, name ASC LIMIT 500');
        $stmt->execute([$u['id']]);
        $rows = [];
        foreach ($stmt as $r) {
            $r['id'] = (string)$r['id'];
            $r['online'] = (bool)$r['online'];
            $rows[] = $r;
        }
        json_response($rows);
    }

    /** ===================== Uploads and downloads ===================== */
    if ($method === 'POST' && $path === '/upload') {
        global $config;
        $u = current_user();
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_response(['detail' => 'File upload failed'], 400);
        }
        $file = $_FILES['file'];
        if ($file['size'] > $config['max_upload_size']) json_response(['detail' => 'File too large'], 413);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        $allowed = in_array($mime, $config['allowed_mime_exact'], true);
        foreach ($config['allowed_mime_prefixes'] as $prefix) {
            if (str_starts_with($mime, $prefix)) $allowed = true;
        }
        if (!$allowed) json_response(['detail' => 'File type not allowed'], 415);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'bin';
        $uuid = bin2hex(random_bytes(16));
        $safeName = $uuid . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
        $relDir = 'uploads/' . $u['id'];
        $absDir = realpath(__DIR__ . '/..') . '/' . $relDir;
        if (!is_dir($absDir)) mkdir($absDir, 0755, true);
        $storagePath = $relDir . '/' . $safeName;
        $absPath = realpath(__DIR__ . '/..') . '/' . $storagePath;
        if (!move_uploaded_file($file['tmp_name'], $absPath)) json_response(['detail' => 'Cannot save file'], 500);
        $cat = file_category($mime, $file['name']);
        $stmt = db()->prepare('INSERT INTO files (file_id,uploader_id,storage_path,original_filename,content_type,file_size,category) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$uuid, $u['id'], $storagePath, $file['name'], $mime, $file['size'], $cat]);
        json_response(['file_id'=>$uuid,'storage_path'=>$storagePath,'original_filename'=>$file['name'],'content_type'=>$mime,'category'=>$cat,'size'=>(int)$file['size']]);
    }

    if ($method === 'GET' && str_starts_with($path, '/files/')) {
        current_user();
        $storagePath = urldecode(substr($path, strlen('/files/')));
        $stmt = db()->prepare('SELECT * FROM files WHERE storage_path = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$storagePath]);
        $file = $stmt->fetch();
        if (!$file) json_response(['detail' => 'File not found'], 404);
        $base = realpath(__DIR__ . '/..');
        $abs = realpath($base . '/' . $file['storage_path']);
        if (!$abs || !str_starts_with($abs, $base) || !is_file($abs)) json_response(['detail' => 'File not found'], 404);
        header('Content-Type: ' . $file['content_type']);
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;
    }

    /** ===================== Messages ===================== */
    if ($method === 'POST' && $path === '/messages') {
        $u = current_user();
        $data = request_data();
        $receiver = as_id($data['receiver_id'] ?? 0);
        $text = clean_string($data['text'] ?? $data['content'] ?? '', 10000);
        $type = $data['message_type'] ?? $data['type'] ?? 'text';
        if (!in_array($type, ['text','image','video','voice','file'], true)) $type = 'text';
        if (!$receiver || ($type === 'text' && $text === '')) json_response(['detail' => 'Invalid message'], 422);
        $fileUrl = clean_string($data['file_url'] ?? '', 500) ?: null;
        $fileName = clean_string($data['file_name'] ?? '', 255) ?: null;
        $fileType = clean_string($data['file_type'] ?? '', 120) ?: null;
        $fileId = null;
        if ($fileUrl) {
            $fs = db()->prepare('SELECT id FROM files WHERE storage_path=? LIMIT 1');
            $fs->execute([$fileUrl]);
            $fileId = $fs->fetchColumn() ?: null;
        }
        $replyTo = !empty($data['reply_to']) ? as_id($data['reply_to']) : null;
        $stmt = db()->prepare('INSERT INTO messages (sender_id,receiver_id,text,message_type,file_id,file_url,file_name,file_type,reply_to,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,"sent",NOW())');
        $stmt->execute([$u['id'],$receiver,$text,$type,$fileId,$fileUrl,$fileName,$fileType,$replyTo]);
        $id = (int)db()->lastInsertId();
        json_response(message_output($id, (int)$u['id']));
    }

    if ($method === 'GET' && ($path === '/messages' || preg_match('#^/messages/(\d+)$#', $path, $m))) {
        $u = current_user();
        $other = isset($m[1]) ? (int)$m[1] : (int)($_GET['contact_id'] ?? 0);
        if (!$other) json_response(['detail' => 'Contact ID required'], 400);
        db()->prepare('UPDATE messages SET status="read" WHERE sender_id=? AND receiver_id=? AND status <> "read"')->execute([$other, $u['id']]);
        $sql = 'SELECT m.* FROM messages m
                LEFT JOIN message_hidden h ON h.message_id=m.id AND h.user_id=?
                WHERE h.message_id IS NULL AND ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
                ORDER BY m.created_at ASC LIMIT 500';
        $stmt = db()->prepare($sql);
        $stmt->execute([$u['id'],$u['id'],$other,$other,$u['id']]);
        $rows = [];
        foreach ($stmt as $r) $rows[] = format_message_row($r);
        json_response($rows);
    }

    if ($method === 'PUT' && preg_match('#^/messages/(\d+)$#', $path, $m)) {
        $u = current_user();
        $data = request_data();
        $text = clean_string($data['text'] ?? '', 10000);
        $stmt = db()->prepare('UPDATE messages SET text=?, edited=1 WHERE id=? AND sender_id=? AND deleted=0 AND message_type="text"');
        $stmt->execute([$text, (int)$m[1], $u['id']]);
        json_response(['message'=>'Message edited','id'=>(string)$m[1],'text'=>$text,'edited'=>true]);
    }

    if ($method === 'POST' && preg_match('#^/messages/(\d+)/delete$#', $path, $m)) {
        $u = current_user();
        $data = request_data();
        $mode = $data['mode'] ?? 'for_all';
        $msgId = (int)$m[1];
        if ($mode === 'for_me') {
            db()->prepare('INSERT IGNORE INTO message_hidden (message_id,user_id) VALUES (?,?)')->execute([$msgId,$u['id']]);
            json_response(['message'=>'Message hidden for you','id'=>(string)$msgId]);
        }
        $stmt = db()->prepare('UPDATE messages SET deleted=1,text="",file_id=NULL,file_url=NULL,file_name=NULL,file_type=NULL,message_type="text" WHERE id=? AND sender_id=?');
        $stmt->execute([$msgId,$u['id']]);
        json_response(['message'=>'Message deleted for everyone','id'=>(string)$msgId]);
    }

    if ($method === 'DELETE' && preg_match('#^/messages/conversation/(\d+)$#', $path, $m)) {
        $u = current_user();
        $other = (int)$m[1];
        $stmt = db()->prepare('DELETE FROM messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)');
        $stmt->execute([$u['id'],$other,$other,$u['id']]);
        json_response(['message'=>'Conversation cleared','deleted_count'=>$stmt->rowCount()]);
    }

    if ($method === 'POST' && preg_match('#^/messages/(\d+)/react$#', $path, $m)) {
        $u = current_user();
        $data = request_data();
        $emoji = clean_string($data['emoji'] ?? '', 16);
        $msgId = (int)$m[1];
        $stmt = db()->prepare('SELECT emoji FROM message_reactions WHERE message_id=? AND user_id=?');
        $stmt->execute([$msgId,$u['id']]);
        $old = $stmt->fetchColumn();
        if ($old === $emoji) {
            db()->prepare('DELETE FROM message_reactions WHERE message_id=? AND user_id=?')->execute([$msgId,$u['id']]);
        } else {
            db()->prepare('INSERT INTO message_reactions (message_id,user_id,emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji=VALUES(emoji),updated_at=NOW()')->execute([$msgId,$u['id'],$emoji]);
        }
        json_response(['message'=>'Reaction updated','id'=>(string)$msgId,'reactions'=>reactions_for_message($msgId)]);
    }

    if ($method === 'GET' && preg_match('#^/messages/(\d+)/export$#', $path, $m)) {
        $u = current_user();
        $other = (int)$m[1];
        $nameStmt = db()->prepare('SELECT name FROM users WHERE id=?');
        $nameStmt->execute([$other]);
        $otherName = $nameStmt->fetchColumn() ?: 'مستخدم';
        $stmt = db()->prepare('SELECT * FROM messages WHERE deleted=0 AND ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) ORDER BY created_at ASC LIMIT 5000');
        $stmt->execute([$u['id'],$other,$other,$u['id']]);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename=chat_export.html');
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:Segoe UI,Tahoma,sans-serif;background:#f8fafc;max-width:760px;margin:auto;padding:25px}.msg{margin:8px 0;padding:10px 14px;border-radius:12px;max-width:80%}.sent{background:#d1fae5;margin-right:auto}.received{background:#fff;border:1px solid #e2e8f0;margin-left:auto}.sender{font-weight:700;color:#059669;font-size:12px}.time{font-size:11px;color:#94a3b8}</style></head><body>';
        echo '<h1>محادثة مع ' . htmlspecialchars($otherName, ENT_QUOTES, 'UTF-8') . '</h1>';
        foreach ($stmt as $r) {
            $own = (int)$r['sender_id'] === (int)$u['id'];
            $text = $r['message_type'] === 'text' ? $r['text'] : ($r['message_type'] === 'voice' ? 'رسالة صوتية' : ($r['message_type'] === 'image' ? 'صورة' : ($r['message_type'] === 'video' ? 'فيديو' : 'ملف: '.$r['file_name'])));
            echo '<div class="msg '.($own?'sent':'received').'"><div class="sender">'.($own?'أنت':htmlspecialchars($otherName,ENT_QUOTES,'UTF-8')).'</div><div>'.nl2br(htmlspecialchars($text ?? '',ENT_QUOTES,'UTF-8')).'</div><div class="time">'.$r['created_at'].'</div></div>';
        }
        echo '</body></html>';
        exit;
    }

    /** ===================== Conversations ===================== */
    if ($method === 'GET' && $path === '/conversations') {
        $u = current_user();
        db()->prepare('UPDATE messages SET status="delivered" WHERE receiver_id=? AND status="sent"')->execute([$u['id']]);
        $sql = 'SELECT other_id, MAX(id) AS last_id FROM (
                    SELECT receiver_id AS other_id, id, created_at FROM messages WHERE sender_id=?
                    UNION ALL
                    SELECT sender_id AS other_id, id, created_at FROM messages WHERE receiver_id=?
                ) x GROUP BY other_id ORDER BY MAX(id) DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$u['id'],$u['id']]);
        $out = [];
        foreach ($stmt as $c) {
            $userStmt = db()->prepare('SELECT id,name,email,avatar,online,last_seen FROM users WHERE id=?');
            $userStmt->execute([$c['other_id']]);
            $other = $userStmt->fetch();
            if (!$other) continue;
            $msgStmt = db()->prepare('SELECT text,message_type,created_at FROM messages WHERE id=?');
            $msgStmt->execute([$c['last_id']]);
            $msg = $msgStmt->fetch();
            $un = db()->prepare('SELECT COUNT(*) FROM messages WHERE sender_id=? AND receiver_id=? AND status<>"read"');
            $un->execute([$other['id'],$u['id']]);
            $last = $msg['text'] ?? '';
            if ($msg['message_type'] === 'image') $last = 'صورة';
            if ($msg['message_type'] === 'video') $last = 'فيديو';
            if ($msg['message_type'] === 'voice') $last = 'رسالة صوتية';
            if ($msg['message_type'] === 'file') $last = 'ملف';
            $other['id'] = (string)$other['id']; $other['online'] = (bool)$other['online'];
            $out[] = ['id'=>(string)$c['other_id'],'other_user'=>$other,'last_message'=>$last,'last_message_time'=>$msg['created_at'] ?? null,'unread_count'=>(int)$un->fetchColumn()];
        }
        json_response($out);
    }

    /** ===================== Typing ===================== */
    if ($method === 'POST' && $path === '/typing') {
        $u = current_user();
        $d = request_data();
        db()->prepare('INSERT INTO typing_status (user_id,receiver_id,is_typing,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE is_typing=VALUES(is_typing),updated_at=NOW()')->execute([$u['id'], as_id($d['receiver_id'] ?? 0), !empty($d['is_typing']) ? 1 : 0]);
        json_response(['ok'=>true]);
    }
    if ($method === 'GET' && preg_match('#^/typing/(\d+)$#', $path, $m)) {
        $u = current_user();
        $stmt = db()->prepare('SELECT is_typing, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM typing_status WHERE user_id=? AND receiver_id=?');
        $stmt->execute([(int)$m[1], $u['id']]);
        $r = $stmt->fetch();
        json_response(['is_typing'=>$r && (int)$r['age'] <= 5 && (bool)$r['is_typing']]);
    }

    /** ===================== Nicknames ===================== */
    if ($method === 'GET' && $path === '/nicknames') {
        $u = current_user();
        $stmt = db()->prepare('SELECT other_user_id,nickname FROM nicknames WHERE user_id=?');
        $stmt->execute([$u['id']]);
        $out=[]; foreach($stmt as $r) $out[(string)$r['other_user_id']]=$r['nickname'];
        json_response($out);
    }
    if ($method === 'PUT' && preg_match('#^/nicknames/(\d+)$#', $path, $m)) {
        $u = current_user(); $d = request_data(); $nick = clean_string($d['nickname'] ?? '', 120);
        db()->prepare('INSERT INTO nicknames (user_id,other_user_id,nickname) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname),updated_at=NOW()')->execute([$u['id'],(int)$m[1],$nick]);
        json_response(['message'=>'Nickname updated','nickname'=>$nick]);
    }
    if ($method === 'DELETE' && preg_match('#^/nicknames/(\d+)$#', $path, $m)) {
        $u = current_user();
        db()->prepare('DELETE FROM nicknames WHERE user_id=? AND other_user_id=?')->execute([$u['id'],(int)$m[1]]);
        json_response(['message'=>'Nickname removed']);
    }

    /** ===================== Admin ===================== */
    if ($method === 'GET' && $path === '/admin/stats') {
        require_admin();
        json_response([
            'total_users'=>(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'online_users'=>(int)db()->query('SELECT COUNT(*) FROM users WHERE online=1')->fetchColumn(),
            'total_messages'=>(int)db()->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
            'total_files'=>(int)db()->query('SELECT COUNT(*) FROM files WHERE is_deleted=0')->fetchColumn(),
        ]);
    }
    if ($method === 'GET' && $path === '/admin/users') {
        require_admin();
        $stmt = db()->query('SELECT u.id,u.name,u.email,u.online,u.last_seen,u.created_at,u.role,COUNT(m.id) AS message_count FROM users u LEFT JOIN messages m ON m.sender_id=u.id OR m.receiver_id=u.id GROUP BY u.id ORDER BY u.created_at DESC LIMIT 500');
        $rows=[]; foreach($stmt as $r){$r['id']=(string)$r['id'];$r['online']=(bool)$r['online'];$r['message_count']=(int)$r['message_count'];$rows[]=$r;}
        json_response($rows);
    }
    if ($method === 'DELETE' && preg_match('#^/admin/users/(\d+)$#', $path, $m)) {
        $u = require_admin(); $target=(int)$m[1];
        if ($target === (int)$u['id']) json_response(['detail'=>'Cannot delete yourself'],400);
        db()->prepare('DELETE FROM users WHERE id=? AND role<>"admin"')->execute([$target]);
        json_response(['message'=>'User deleted successfully','deleted_user_id'=>(string)$target]);
    }

    json_response(['detail' => 'Endpoint not found', 'path'=>$path], 404);
} catch (Throwable $e) {
    json_response(['detail' => 'Server error', 'error' => $e->getMessage()], 500);
}

/** تحويل رسالة قاعدة البيانات إلى شكل JSON مطابق تقريباً للنسخة الأصلية. */
function format_message_row(array $msg): array
{
    $deleted = (bool)$msg['deleted'];
    return [
        'id' => (string)$msg['id'],
        'sender_id' => (string)$msg['sender_id'],
        'receiver_id' => (string)$msg['receiver_id'],
        'text' => $deleted ? 'تم حذف هذه الرسالة' : ($msg['text'] ?? ''),
        'message_type' => $deleted ? 'text' : $msg['message_type'],
        'file_url' => $deleted ? null : $msg['file_url'],
        'file_name' => $deleted ? null : $msg['file_name'],
        'file_type' => $deleted ? null : $msg['file_type'],
        'reply_to' => $msg['reply_to'] ? (string)$msg['reply_to'] : null,
        'timestamp' => $msg['created_at'],
        'status' => $msg['status'],
        'edited' => (bool)$msg['edited'],
        'deleted' => $deleted,
        'reactions' => reactions_for_message((int)$msg['id']),
    ];
}

function message_output(int $id, int $viewerId): array
{
    $stmt = db()->prepare('SELECT * FROM messages WHERE id=?');
    $stmt->execute([$id]);
    return format_message_row($stmt->fetch());
}

function reactions_for_message(int $messageId): array
{
    $stmt = db()->prepare('SELECT user_id, emoji FROM message_reactions WHERE message_id=?');
    $stmt->execute([$messageId]);
    $out=[]; foreach($stmt as $r) $out[(string)$r['user_id']]=$r['emoji'];
    return $out;
}
