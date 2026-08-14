<?php
declare(strict_types=1);

/**
 * Shared helpers for create-post media upload (eager AJAX + post_save claim).
 */

function post_upload_safe_filename(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9_\.-]+/', '_', $name) ?? 'file';
    $name = trim($name, '._');
    return $name !== '' ? $name : 'file';
}

/** Ensure per-slide title/body columns exist for presentation carousels. */
function post_attachments_ensure_slide_columns(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = [];
        foreach ($dbh->query('SHOW COLUMNS FROM public_post_attachments')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
        if (empty($cols['slide_title'])) {
            $dbh->exec("ALTER TABLE public_post_attachments ADD COLUMN slide_title VARCHAR(120) NOT NULL DEFAULT '' AFTER thumb_path");
        }
        if (empty($cols['slide_body'])) {
            $dbh->exec('ALTER TABLE public_post_attachments ADD COLUMN slide_body MEDIUMTEXT NULL AFTER slide_title');
        }
    } catch (Throwable $e) {
        // keep callers resilient
    }
}

function post_upload_allowed_ext(): array
{
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'mp4', 'webm', 'ogg', 'mov', 'm4v',
        'pdf',
        'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
        'txt', 'zip',
    ];
}

function post_upload_allowed_mime_by_ext(): array
{
    return [
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
}

function post_upload_ext_from_mime(string $detectedMime, string $ext): string
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

function post_upload_mime_is_allowed(string $detectedMime, string $ext, array $allowedMimeByExt): bool
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

function post_upload_ensure_dir(): array
{
    $baseDir = dirname(__DIR__) . '/uploads/posts';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }
    @chmod($baseDir, 0775);
    $ym = date('Ym');
    $subDir = $baseDir . '/' . $ym;
    if (!is_dir($subDir)) {
        @mkdir($subDir, 0775, true);
    }
    @chmod($subDir, 0775);
    return [$subDir, $ym];
}

function post_upload_att_type(string $mime, string $ext): string
{
    $mime = strtolower($mime);
    $ext = strtolower($ext);
    $isImg = (strpos($mime, 'image/') === 0) || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    $isVid = (strpos($mime, 'video/') === 0) || in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], true);
    $isPdf = ($mime === 'application/pdf') || ($ext === 'pdf');
    if ($isImg) {
        return 'image';
    }
    if ($isVid) {
        return 'video';
    }
    if ($isPdf) {
        return 'pdf';
    }
    return 'file';
}

function post_upload_pending_bag(): array
{
    if (!isset($_SESSION['post_pending_uploads']) || !is_array($_SESSION['post_pending_uploads'])) {
        $_SESSION['post_pending_uploads'] = [];
    }
    return $_SESSION['post_pending_uploads'];
}

function post_upload_pending_meta_dir(int $userId): string
{
    $dir = __DIR__ . '/../uploads/posts/pending_meta/' . max(0, $userId);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function post_upload_pending_meta_path(int $userId, string $token): string
{
    return post_upload_pending_meta_dir($userId) . '/' . $token . '.json';
}

/**
 * @param array{user_id:int,abs:string,web:string,type:string,name:string,size:int,created:int} $row
 */
function post_upload_write_pending_meta(string $token, array $row): void
{
    $userId = (int)($row['user_id'] ?? 0);
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
    if ($userId <= 0 || $token === '') {
        return;
    }
    $path = post_upload_pending_meta_path($userId, $token);
    @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function post_upload_read_pending_meta(int $userId, string $token): ?array
{
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
    if ($userId <= 0 || $token === '') {
        return null;
    }
    $path = post_upload_pending_meta_path($userId, $token);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function post_upload_delete_pending_meta(int $userId, string $token): void
{
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
    if ($userId <= 0 || $token === '') {
        return;
    }
    $path = post_upload_pending_meta_path($userId, $token);
    if (is_file($path)) {
        @unlink($path);
    }
}

function post_upload_purge_stale(int $maxAgeSeconds = 7200): void
{
    $now = time();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $bag = post_upload_pending_bag();
        $changed = false;
        foreach ($bag as $token => $row) {
            $created = (int)($row['created'] ?? 0);
            if ($created > 0 && ($now - $created) > $maxAgeSeconds) {
                $abs = (string)($row['abs'] ?? '');
                if ($abs !== '' && is_file($abs)) {
                    @unlink($abs);
                }
                post_upload_delete_pending_meta((int)($row['user_id'] ?? 0), (string)$token);
                unset($bag[$token]);
                $changed = true;
            }
        }
        if ($changed) {
            $_SESSION['post_pending_uploads'] = $bag;
        }
    }

    $root = __DIR__ . '/../uploads/posts/pending_meta';
    if (!is_dir($root)) {
        return;
    }
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $userDir) {
        foreach (glob($userDir . '/*.json') ?: [] as $metaFile) {
            $mtime = (int)@filemtime($metaFile);
            if ($mtime > 0 && ($now - $mtime) > $maxAgeSeconds) {
                $data = json_decode((string)@file_get_contents($metaFile), true);
                if (is_array($data)) {
                    $abs = (string)($data['abs'] ?? '');
                    if ($abs !== '' && is_file($abs)) {
                        @unlink($abs);
                    }
                }
                @unlink($metaFile);
            }
        }
    }
}

function post_upload_php_error_code(int $err): string
{
    switch ($err) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'too_large';
        case UPLOAD_ERR_PARTIAL:
            return 'partial';
        case UPLOAD_ERR_NO_FILE:
            return 'no_file';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'no_tmp_dir';
        case UPLOAD_ERR_CANT_WRITE:
            return 'cant_write';
        case UPLOAD_ERR_EXTENSION:
            return 'blocked_extension';
        default:
            return 'upload_error';
    }
}

/**
 * Save one uploaded file into pending storage for later claim by post_save.
 * @return array{ok:bool,error?:string,token?:string,name?:string,type?:string,size?:int,web?:string}
 */
function post_upload_store_pending(int $userId, array $file): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'session'];
    }
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => post_upload_php_error_code($err)];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'invalid_tmp'];
    }

    $orig = post_upload_safe_filename((string)($file['name'] ?? 'file'));
    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'bin';
    }
    // Normalize common camera/export aliases.
    if ($ext === 'jfif' || $ext === 'jpe') {
        $ext = 'jpg';
    }
    if ($ext === 'heic' || $ext === 'heif') {
        return ['ok' => false, 'error' => 'heic_unsupported'];
    }
    $allowedExt = post_upload_allowed_ext();
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'unsupported_type'];
    }

    $size = (int)($file['size'] ?? 0);
    $maxBytes = 100 * 1024 * 1024;
    if ($size <= 0) {
        $size = (int)@filesize($tmp);
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'too_large'];
    }

    // Fast trust path for common image/video extensions — skip full-file finfo sniff.
    $clientMime = strtolower(trim((string)($file['type'] ?? '')));
    $fastExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mp4', 'webm', 'mov', 'm4v', 'ogg'];
    $detectedMime = '';
    $trustClient = in_array($ext, $fastExts, true) && (
        $clientMime === ''
        || strncmp($clientMime, 'image/', 6) === 0
        || strncmp($clientMime, 'video/', 6) === 0
        || $clientMime === 'application/octet-stream'
    );
    if ($trustClient) {
        $detectedMime = ($clientMime === '' || $clientMime === 'application/octet-stream') ? '' : $clientMime;
    } else {
        $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
        if ($finfo) {
            $detectedMime = strtolower(trim((string)$finfo->file($tmp)));
        }
        if (!post_upload_mime_is_allowed($detectedMime, $ext, post_upload_allowed_mime_by_ext())) {
            return ['ok' => false, 'error' => 'mime_mismatch'];
        }
        $ext = post_upload_ext_from_mime($detectedMime, $ext);
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => 'unsupported_type'];
        }
    }

    $mime = $detectedMime !== '' ? $detectedMime : $clientMime;
    $attType = post_upload_att_type($mime, $ext);

    [$subDir, $ym] = post_upload_ensure_dir();
    if (!is_dir($subDir) || !is_writable($subDir)) {
        return ['ok' => false, 'error' => 'dir_not_writable'];
    }
    $token = bin2hex(random_bytes(16));
    $fname = 'pending_u' . $userId . '_' . $token . '.' . $ext;
    $destAbs = $subDir . '/' . $fname;
    $moved = @move_uploaded_file($tmp, $destAbs);
    if (!$moved) {
        // Some CGI setups report is_uploaded_file correctly but move fails; copy fallback.
        $moved = @copy($tmp, $destAbs);
        if ($moved) {
            @unlink($tmp);
        }
    }
    if (!$moved || !is_file($destAbs)) {
        return ['ok' => false, 'error' => 'move_failed'];
    }
    @chmod($destAbs, 0644);

    $webPath = 'uploads/posts/' . $ym . '/' . $fname;
    // Purge stale pending files only ~10% of the time (avoid session/disk churn every upload).
    if (random_int(0, 9) === 0) {
        post_upload_purge_stale();
    }
    $row = [
        'user_id' => $userId,
        'abs' => $destAbs,
        'web' => $webPath,
        'type' => $attType,
        'name' => $orig,
        'size' => $size > 0 ? $size : (int)@filesize($destAbs),
        'created' => time(),
    ];
    // Disk meta is the source of truth so ajax uploads can release the PHP
    // session lock immediately and multiple files can upload in parallel.
    post_upload_write_pending_meta($token, $row);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $bag = post_upload_pending_bag();
        $bag[$token] = $row;
        $_SESSION['post_pending_uploads'] = $bag;
    }

    return [
        'ok' => true,
        'token' => $token,
        'name' => $orig,
        'type' => $attType,
        'size' => (int)$row['size'],
        'web' => $webPath,
    ];
}

/**
 * Claim pending tokens into public_post_attachments for a post.
 * @param list<string> $tokens
 * @param array<string,string> $slideBodiesByToken token => slide description
 * @param array<string,string> $slideTitlesByToken token => slide title
 * @return array{saved:int, types:list<string>}
 */
function post_upload_claim_pending(PDO $dbh, int $userId, int $postId, array $tokens, bool $rename = false, array $slideBodiesByToken = [], array $slideTitlesByToken = []): array
{
    $saved = 0;
    $types = [];
    if ($userId <= 0 || $postId <= 0 || $tokens === []) {
        return ['saved' => 0, 'types' => []];
    }

    post_attachments_ensure_slide_columns($dbh);

    $bag = session_status() === PHP_SESSION_ACTIVE ? post_upload_pending_bag() : [];
    $hasSlideCols = true;
    try {
        $stA = $dbh->prepare(
            'INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, slide_title, slide_body, created_at)
             VALUES (:pid, :t, :fp, NULL, :st, :sb, NOW())'
        );
    } catch (Throwable $e) {
        $hasSlideCols = false;
        $stA = $dbh->prepare(
            'INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at)
             VALUES (:pid, :t, :fp, NULL, NOW())'
        );
    }

    foreach ($tokens as $token) {
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token) ?? '';
        if ($token === '') {
            continue;
        }
        $row = (isset($bag[$token]) && is_array($bag[$token])) ? $bag[$token] : null;
        if ($row === null) {
            $row = post_upload_read_pending_meta($userId, $token);
        }
        if (!is_array($row)) {
            continue;
        }
        if ((int)($row['user_id'] ?? 0) !== $userId) {
            continue;
        }
        $abs = (string)($row['abs'] ?? '');
        $web = (string)($row['web'] ?? '');
        $type = (string)($row['type'] ?? 'file');
        if ($abs === '' || $web === '' || !is_file($abs)) {
            unset($bag[$token]);
            post_upload_delete_pending_meta($userId, $token);
            continue;
        }

        // Rename is optional — skip on fast submit path (pending_* names are fine).
        if ($rename) {
            $dir = dirname($abs);
            $ext = strtolower((string)pathinfo($abs, PATHINFO_EXTENSION));
            $finalName = 'p' . $postId . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
            $finalAbs = $dir . '/' . $finalName;
            $finalWeb = preg_replace('#/[^/]+$#', '/' . $finalName, $web) ?: $web;
            if (@rename($abs, $finalAbs)) {
                $web = $finalWeb;
                $abs = $finalAbs;
            }
        }

        $slideTitle = trim((string)($slideTitlesByToken[$token] ?? ''));
        $slideBody = trim((string)($slideBodiesByToken[$token] ?? ''));
        if (function_exists('mb_substr')) {
            $slideTitle = mb_substr($slideTitle, 0, 120);
        } else {
            $slideTitle = substr($slideTitle, 0, 120);
        }

        try {
            if ($hasSlideCols) {
                $stA->execute([
                    ':pid' => $postId,
                    ':t' => $type,
                    ':fp' => $web,
                    ':st' => $slideTitle,
                    ':sb' => $slideBody !== '' ? $slideBody : null,
                ]);
            } else {
                $stA->execute([':pid' => $postId, ':t' => $type, ':fp' => $web]);
            }
            $saved++;
            $types[] = $type;
        } catch (Throwable $e) {
            try {
                $stFallback = $dbh->prepare(
                    'INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at)
                     VALUES (:pid, :t, :fp, NULL, NOW())'
                );
                $stFallback->execute([':pid' => $postId, ':t' => $type, ':fp' => $web]);
                $saved++;
                $types[] = $type;
            } catch (Throwable $e2) {
                continue;
            }
        }
        unset($bag[$token]);
        post_upload_delete_pending_meta($userId, $token);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['post_pending_uploads'] = $bag;
    }
    return ['saved' => $saved, 'types' => $types];
}
