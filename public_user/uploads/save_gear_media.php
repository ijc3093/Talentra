<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/profile_access.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$userId = profile_session_owner_user_id();

profile_require_edit_access($dbh, $userId);

$kind = strtolower(trim((string)($_POST['kind'] ?? '')));
if (!in_array($kind, ['avatar', 'cover'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid media type']);
    exit;
}
if (empty($_FILES['media']) || !is_array($_FILES['media'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
    exit;
}
$file = $_FILES['media'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Upload failed']);
    exit;
}

$maxBytes = ($kind === 'avatar') ? 5 * 1024 * 1024 : 8 * 1024 * 1024;
if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'File is too large']);
    exit;
}

$tmp = (string)($file['tmp_name'] ?? '');
$orig = (string)($file['name'] ?? 'upload');
$mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmp) : '';
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
if ($mime === '' || !isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Only JPG, PNG, GIF, or WEBP images are allowed']);
    exit;
}
$ext = $allowed[$mime];
$folder = __DIR__ . '/uploads/' . ($kind === 'avatar' ? 'avatars' : 'covers');
if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not create upload folder']);
    exit;
}
$filename = $kind . '-' . $userId . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $folder . '/' . $filename;
if (!@move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save upload']);
    exit;
}
$rel = 'uploads/' . ($kind === 'avatar' ? 'avatars/' : 'covers/') . $filename;
$field = ($kind === 'avatar') ? 'avatar_image_path' : 'cover_image_path';

try {
    $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
    $hasTable = (bool)($chk && $chk->fetchColumn());
    if (!$hasTable) {
        throw new RuntimeException('user_profile_settings table not found');
    }
    $ensure = $dbh->prepare("INSERT INTO user_profile_settings (user_id) VALUES (:uid) ON DUPLICATE KEY UPDATE user_id = user_id");
    $ensure->execute([':uid' => $userId]);
    $st = $dbh->prepare("UPDATE user_profile_settings SET {$field} = :path WHERE user_id = :uid");
    $st->execute([':path' => $rel, ':uid' => $userId]);

    if ($kind === 'avatar') {
        try {
            $oldSt = $dbh->prepare("SELECT image FROM users WHERE id = :uid LIMIT 1");
            $oldSt->execute([':uid' => $userId]);
            $oldImage = trim((string)$oldSt->fetchColumn());
            $upUser = $dbh->prepare("UPDATE users SET image = :img WHERE id = :uid LIMIT 1");
            $upUser->execute([':img' => $rel, ':uid' => $userId]);
            if ($oldImage !== '' && $oldImage !== $rel && !preg_match('/^default\.(jpg|jpeg|png|gif|webp)$/i', basename($oldImage))) {
                $oldAbs = realpath(__DIR__ . '/' . ltrim(str_replace('\\', '/', $oldImage), '/')) ?: '';
                $base = realpath(__DIR__) ?: __DIR__;
                if ($oldAbs !== '' && strpos($oldAbs, $base) === 0 && is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
        } catch (Throwable $e) {
            // keep avatar upload successful even if legacy image field update fails
        }
    }
    echo json_encode([
        'ok' => true,
        'kind' => $kind,
        'path' => $rel,
        'preview' => ($kind === 'avatar') ? ('avatar.php?u=' . $userId . '&v=' . time()) : ($rel . '?v=' . time()),
    ]);
} catch (Throwable $e) {
    @unlink($dest);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
