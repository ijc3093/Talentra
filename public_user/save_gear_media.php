<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/profile_access.php';
require_once __DIR__ . '/includes/profile_cover_slides.php';

function gear_media_detect_ext(string $tmp, string $orig): string
{
    $nameExt = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($nameExt === 'webb') {
        $nameExt = 'webp';
    }
    if ($nameExt === 'jpeg') {
        $nameExt = 'jpg';
    }

    $head = @file_get_contents($tmp, false, null, 0, 16) ?: '';
    if (strlen($head) >= 12 && strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    if (strncmp($head, "\x89PNG", 4) === 0) {
        return 'png';
    }
    if (strncmp($head, 'GIF8', 4) === 0) {
        return 'gif';
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($tmp));
    } elseif (function_exists('mime_content_type')) {
        $mime = strtolower((string)@mime_content_type($tmp));
    }
    $byMime = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/x-webp' => 'webp',
    ];
    if (isset($byMime[$mime])) {
        return $byMime[$mime];
    }

    if (in_array($nameExt, ['jpg', 'png', 'gif', 'webp'], true) && is_file($tmp) && (int)@filesize($tmp) > 32) {
        return $nameExt;
    }
    return '';
}

function gear_media_file_list(array $bag): array
{
    if (!isset($bag['name'])) {
        return [];
    }
    if (!is_array($bag['name'])) {
        return [[
            'name' => (string)$bag['name'],
            'tmp_name' => (string)($bag['tmp_name'] ?? ''),
            'error' => (int)($bag['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($bag['size'] ?? 0),
        ]];
    }
    $out = [];
    foreach ($bag['name'] as $i => $name) {
        $out[] = [
            'name' => (string)$name,
            'tmp_name' => (string)($bag['tmp_name'][$i] ?? ''),
            'error' => (int)($bag['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($bag['size'][$i] ?? 0),
        ];
    }
    return $out;
}

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$userId = profile_session_owner_user_id();

profile_require_edit_access($dbh, $userId);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$kind = strtolower(trim((string)($_POST['kind'] ?? '')));
if (!in_array($kind, ['avatar', 'cover'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid media type']);
    exit;
}
$coverMax = profile_cover_slides_max();
if ($kind === 'cover' && profile_cover_slides_count($dbh, $userId) >= $coverMax) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'You can add up to ' . $coverMax . ' slideshow photos']);
    exit;
}
if (empty($_FILES['media']) || !is_array($_FILES['media'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
    exit;
}
$files = gear_media_file_list($_FILES['media']);
if ($kind === 'avatar') {
    $files = array_slice($files, 0, 1);
} elseif ($kind === 'cover') {
    $room = max(0, $coverMax - profile_cover_slides_count($dbh, $userId));
    $files = array_slice($files, 0, max(1, $room));
}
if ($files === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
    exit;
}

$maxBytes = ($kind === 'avatar') ? 5 * 1024 * 1024 : 8 * 1024 * 1024;
$folder = __DIR__ . '/uploads/' . ($kind === 'avatar' ? 'avatars' : 'covers');
if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not create upload folder']);
    exit;
}

$saved = [];
$rel = '';
foreach ($files as $file) {
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        continue;
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
        continue;
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $orig = (string)($file['name'] ?? 'upload');
    $ext = gear_media_detect_ext($tmp, $orig);
    if ($ext === '') {
        continue;
    }
    $filename = $kind . '-' . $userId . '-' . str_replace('.', '', uniqid('', true)) . '-' . count($saved) . '.' . $ext;
    $dest = $folder . '/' . $filename;
    if (!@move_uploaded_file($tmp, $dest)) {
        continue;
    }
    $rel = 'uploads/' . ($kind === 'avatar' ? 'avatars/' : 'covers/') . $filename;
    $saved[] = ['rel' => $rel, 'dest' => $dest];
}
if ($saved === [] || $rel === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Use JPG, PNG, GIF, or WebP (.webp) photos']);
    exit;
}
$field = ($kind === 'avatar') ? 'avatar_image_path' : 'cover_image_path';

try {
    $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
    $hasTable = (bool)($chk && $chk->fetchColumn());
    if (!$hasTable) {
        throw new RuntimeException('user_profile_settings table not found');
    }
    $ensure = $dbh->prepare("INSERT INTO user_profile_settings (user_id) VALUES (:uid) ON DUPLICATE KEY UPDATE user_id = user_id");
    $ensure->execute([':uid' => $userId]);
    $oldCover = '';
    if ($kind === 'cover') {
        try {
            $oldSt = $dbh->prepare('SELECT cover_image_path FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
            $oldSt->execute([':uid' => $userId]);
            $oldCover = trim((string)$oldSt->fetchColumn());
        } catch (Throwable $e) {
            $oldCover = '';
        }
    }
    $st = $dbh->prepare("UPDATE user_profile_settings SET {$field} = :path WHERE user_id = :uid");
    $st->execute([':path' => $rel, ':uid' => $userId]);
    if ($kind === 'cover') {
        $newPaths = [];
        foreach ($saved as $row) {
            $newPaths[] = (string)$row['rel'];
        }
        profile_cover_slides_add_many($dbh, $userId, $newPaths, $oldCover);
    }

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
    $payload = [
        'ok' => true,
        'kind' => $kind,
        'path' => $rel,
        'saved' => count($saved),
        'preview' => ($kind === 'avatar') ? ('avatar.php?u=' . $userId . '&v=' . time()) : ($rel . '?v=' . time()),
    ];
    if ($kind === 'cover') {
        $payload['slides'] = profile_cover_slides_for_user($dbh, $userId, $rel);
    }
    echo json_encode($payload);
} catch (Throwable $e) {
    foreach ($saved as $row) {
        if (!empty($row['dest'])) {
            @unlink((string)$row['dest']);
        }
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
