<?php
// /public_user/post_save.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/post_categories.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

sendNoCacheHeadersUser();

if (empty($_SESSION['user_login']) || empty($_SESSION['user_id'])) {
    header("Location: index.php?session=reset");
    exit;
}

try {
    $controllerForSession = new Controller();
    $dbhForSession = $controllerForSession->pdo();
    if (!validateCurrentUserSession($dbhForSession)) {
        ensureUserSessionRecord((int)($_SESSION['user_id'] ?? 0), $dbhForSession);
    }
} catch (Throwable $e) {
    // keep auth flow resilient if session validation fails unexpectedly
}

staff_pub_deny_write();

try {
    bumpUserLastSeenThrottled();
} catch (Throwable $e) {
    // ignore presence update failures
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();
ensurePostCategorySchema($dbh);
device_profile_ensure_post_columns($dbh);
publisher_ensure_schema($dbh);

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    header("Location: dashboard.php?err=session");
    exit;
}

function safe_filename(string $name): string {
    $name = preg_replace('/[^a-zA-Z0-9_\.-]+/', '_', $name);
    $name = trim($name, '._');
    if ($name === '') $name = 'file';
    return $name;
}

function firstExistingPostLayoutColumn(PDO $dbh): ?string {
    static $cached = false;
    static $found = null;
    if ($cached) return $found;
    $cached = true;
    try {
        $rows = $dbh->query("SHOW COLUMNS FROM public_posts")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fields = array_map(static fn(array $r): string => (string)($r['Field'] ?? ''), $rows);
        foreach (['layout_type','layout','post_type','type'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $found = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $found = null;
    }
    return $found;
}

function layoutOverrideMarker(string $layoutOverride): string {
    $layoutOverride = trim($layoutOverride);
    if ($layoutOverride === '') return '';
    return '[[layout:' . preg_replace('/[^a-z0-9_]+/i', '', $layoutOverride) . ']]';
}

function stripLayoutOverrideMarker(string $description): string {
    return trim((string)preg_replace('/\[\[layout:[a-z0-9_]+\]\]/i', '', $description));
}

function upload_ext_from_mime(string $detectedMime, string $ext): string
{
    $detectedMime = strtolower(trim($detectedMime));
    $ext = strtolower(trim($ext));
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'video/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'video/x-m4v' => 'm4v',
        'application/pdf' => 'pdf',
    ];
    if ($detectedMime !== '' && isset($map[$detectedMime])) {
        return $map[$detectedMime];
    }
    return $ext !== '' ? $ext : 'bin';
}

function upload_mime_is_allowed(string $detectedMime, string $ext, array $allowedMimeByExt): bool
{
    $detectedMime = strtolower(trim($detectedMime));
    $ext = strtolower(trim($ext));
    if ($detectedMime === '') {
        return true;
    }
    $allowedMimes = $allowedMimeByExt[$ext] ?? [];
    if ($allowedMimes && in_array($detectedMime, $allowedMimes, true)) {
        return true;
    }
    $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/svg+xml'];
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    if (in_array($detectedMime, $imageMimes, true) && in_array($ext, $imageExts, true)) {
        return true;
    }
    $videoMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg', 'application/ogg', 'video/x-m4v'];
    $videoExts = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];
    if (in_array($detectedMime, $videoMimes, true) && in_array($ext, $videoExts, true)) {
        return true;
    }
    return false;
}

$postId = (int)($_POST['post_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
$visibility = (string)($_POST['visibility'] ?? 'public');
$layoutOverride = post_allowed_layout_override((string)($_POST['layout_override'] ?? ''));
$categoryId = (int)($_POST['category_id'] ?? 0);
$isStoryPost = ($layoutOverride === 'story');
$isPublisherPoster = publisher_account_is($dbh, $meId)
    || ((string)($_POST['publisher_account'] ?? '') === '1' && (int)($_SESSION['user_id'] ?? 0) === $meId);
$visibility = publisher_post_visibility($dbh, $meId, $visibility);
$description = stripLayoutOverrideMarker($description);
$description = post_strip_music_marker($description);

$musicTitle = mb_substr(trim((string)($_POST['music_title'] ?? '')), 0, 120);
$musicArtist = mb_substr(trim((string)($_POST['music_artist'] ?? '')), 0, 120);
if ($musicTitle === '' && $musicArtist === '') {
    $musicFromMarker = post_extract_music_marker($description . "\n" . $body . "\n" . $title);
    $musicTitle = (string)($musicFromMarker['title'] ?? '');
    $musicArtist = (string)($musicFromMarker['artist'] ?? '');
}

if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);

if ($title === '' && $description === '' && $body === '' && (empty($_FILES['attachments']) || empty($_FILES['attachments']['name']))) {
    header("Location: dashboard.php?err=empty");
    exit;
}

$deviceProfile = device_profile_read_from_request();
$deviceLabel = (string)($deviceProfile['label'] ?? '');
$deviceViewport = (string)($deviceProfile['viewport'] ?? '');

try {
    $layoutColumn = firstExistingPostLayoutColumn($dbh);
    $hasTextContent = ($title !== '' || $description !== '' || $body !== '');
    $existingAttachmentTypes = [];
    if ($postId > 0) {
        try {
            $stExisting = $dbh->prepare("SELECT type FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC");
            $stExisting->execute([':pid' => $postId]);
            $existingAttachmentTypes = array_map(static fn(array $row): string => (string)($row['type'] ?? ''), $stExisting->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            $existingAttachmentTypes = [];
        }
    }
    $incomingAttachmentTypes = [];
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $names = $_FILES['attachments']['name'];
        $type  = $_FILES['attachments']['type'];
        $err   = $_FILES['attachments']['error'];
        for ($i = 0; $i < count($names); $i++) {
            if ((int)($err[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $orig = safe_filename((string)($names[$i] ?? ''));
            $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
            $mime = (string)($type[$i] ?? '');
            $isImg = (strpos($mime, 'image/') === 0) || in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true);
            $isVid = (strpos($mime, 'video/') === 0) || in_array($ext, ['mp4','webm','ogg','mov','m4v'], true);
            $incomingAttachmentTypes[] = $isImg ? 'image' : ($isVid ? 'video' : 'file');
        }
    }
    $detectedCategoryType = detectPostCategoryType(!empty($incomingAttachmentTypes) ? $incomingAttachmentTypes : $existingAttachmentTypes, $hasTextContent);
    $resolvedCategoryId = resolveUserPostCategoryId($dbh, $meId, $categoryId, $detectedCategoryType);

    if (!$layoutColumn && $layoutOverride !== '') {
        $description = trim(layoutOverrideMarker($layoutOverride) . ' ' . $description);
    }
    if ($postId > 0) {
        // ensure owner
        $st = $dbh->prepare("SELECT id FROM public_posts WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
        $st->execute([':id' => $postId, ':uid' => $meId]);
        if (!$st->fetchColumn()) {
            header("Location: dashboard.php?err=forbidden");
            exit;
        }

        if ($layoutColumn) {
            $stU = $dbh->prepare("UPDATE public_posts SET title=:t, description=:d, body=:b, visibility=:v, music_title=:mt, music_artist=:ma, {$layoutColumn}=:layoutv, category_id=:cid, updated_at=NOW() WHERE id=:id LIMIT 1");
            $stU->execute([':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':layoutv'=>$layoutOverride ?: null, ':cid'=>$resolvedCategoryId ?: null, ':id'=>$postId]);
        } else {
            $stU = $dbh->prepare("UPDATE public_posts SET title=:t, description=:d, body=:b, visibility=:v, music_title=:mt, music_artist=:ma, category_id=:cid, updated_at=NOW() WHERE id=:id LIMIT 1");
            $stU->execute([':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':cid'=>$resolvedCategoryId ?: null, ':id'=>$postId]);
        }
    } else {
        if ($layoutColumn) {
            $stI = $dbh->prepare("INSERT INTO public_posts (user_id, title, description, body, visibility, device_label, device_viewport, music_title, music_artist, {$layoutColumn}, category_id, created_at, updated_at, is_deleted)
                                  VALUES (:uid, :t, :d, :b, :v, :dl, :dv, :mt, :ma, :layoutv, :cid, NOW(), NOW(), 0)");
            $stI->execute([':uid'=>$meId, ':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':dl'=>$deviceLabel, ':dv'=>$deviceViewport, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':layoutv'=>$layoutOverride ?: null, ':cid'=>$resolvedCategoryId ?: null]);
        } else {
            $stI = $dbh->prepare("INSERT INTO public_posts (user_id, title, description, body, visibility, device_label, device_viewport, music_title, music_artist, category_id, created_at, updated_at, is_deleted)
                                  VALUES (:uid, :t, :d, :b, :v, :dl, :dv, :mt, :ma, :cid, NOW(), NOW(), 0)");
            $stI->execute([':uid'=>$meId, ':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':dl'=>$deviceLabel, ':dv'=>$deviceViewport, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':cid'=>$resolvedCategoryId ?: null]);
        }
        $postId = (int)$dbh->lastInsertId();
    }

    if ($isPublisherPoster) {
        publisher_repair_user_as_publisher($dbh, $meId);
    }

    // upload directory
    $baseDir = __DIR__ . '/uploads/posts';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }
    $subDir = $baseDir . '/' . date('Ym');
    if (!is_dir($subDir)) {
        @mkdir($subDir, 0775, true);
    }

    // handle attachments[] (multiple)
    // ✅ Allow: images, videos, pdf, office docs, text, zip (as generic files)
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $names = $_FILES['attachments']['name'];
        $tmp   = $_FILES['attachments']['tmp_name'];
        $err   = $_FILES['attachments']['error'];
        $type  = $_FILES['attachments']['type'];
        $size  = $_FILES['attachments']['size'];

        // basic allow-list by extension (fallback if mime is unreliable)
        $allowedExt = [
            'jpg','jpeg','png','gif','webp','bmp',
            'mp4','webm','ogg','mov','m4v',
            'pdf',
            'doc','docx','ppt','pptx','xls','xlsx',
            'txt','zip'
        ];

        $allowedMimeByExt = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'ogg' => ['video/ogg', 'application/ogg'],
            'mov' => ['video/quicktime'],
            'm4v' => ['video/x-m4v', 'video/mp4'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'txt' => ['text/plain'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
        ];

        $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
        $uploadAttempts = 0;
        $uploadSaved = 0;

        for ($i = 0; $i < count($names); $i++) {
            if ((int)$err[$i] !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmp[$i])) continue;
            $uploadAttempts++;

            $orig = safe_filename((string)$names[$i]);
            $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));

            // If no ext, try to infer a safe one
            if ($ext === '') {
                $ext = 'bin';
            }

            if (!in_array($ext, $allowedExt, true)) {
                // skip unsupported types quietly
                continue;
            }

            $detectedMime = '';
            if ($finfo) {
                $detectedMime = strtolower(trim((string)$finfo->file($tmp[$i])));
            }
            if (!upload_mime_is_allowed($detectedMime, $ext, $allowedMimeByExt)) {
                continue;
            }
            $ext = upload_ext_from_mime($detectedMime, $ext);
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            // (optional) size guard: 50MB max per file (adjust if you want)
            $maxBytes = 50 * 1024 * 1024;
            if (is_numeric($size[$i]) && (int)$size[$i] > $maxBytes) {
                continue;
            }

            $mime = $detectedMime !== '' ? $detectedMime : (string)($type[$i] ?? '');

            $isImg = (strpos($mime, 'image/') === 0) || in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true);
            $isVid = (strpos($mime, 'video/') === 0) || in_array($ext, ['mp4','webm','ogg','mov','m4v'], true);
            $isPdf = ($mime === 'application/pdf') || ($ext === 'pdf');

            $attType = 'file';
            if ($isImg) $attType = 'image';
            else if ($isVid) $attType = 'video';
            else if ($isPdf) $attType = 'pdf';

            $fname = 'p' . $postId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destAbs = $subDir . '/' . $fname;

            if (!move_uploaded_file($tmp[$i], $destAbs)) continue;

            $webPath = 'uploads/posts/' . date('Ym') . '/' . $fname;

            $stA = $dbh->prepare("INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at)
                                  VALUES (:pid, :t, :fp, NULL, NOW())");
            $stA->execute([':pid' => $postId, ':t' => $attType, ':fp' => $webPath]);
            $uploadSaved++;
        }
    }
$dest = $isPublisherPoster ? 'feed.php' : publisher_post_redirect($dbh, $meId, $visibility);
$queryKey = $isStoryPost ? 'story_post' : 'post';
$redirect = $dest . '?' . $queryKey . '=' . $postId;
if (isset($uploadAttempts) && $uploadAttempts > 0 && ($uploadSaved ?? 0) === 0) {
    $redirect .= '&upload_warn=1';
}
header('Location: ' . $redirect);
    exit;

} catch (Throwable $e) {
    header("Location: dashboard.php?err=server");
    exit;
}
