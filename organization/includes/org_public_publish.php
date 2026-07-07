<?php
declare(strict_types=1);

/**
 * Publish org manager updates to the linked public publisher feed.
 */

require_once __DIR__ . '/org_theme_prefs.php';
require_once __DIR__ . '/../../public_user/includes/publisher_accounts.php';
require_once __DIR__ . '/../../public_user/includes/post_categories.php';

function org_public_publish_publisher_user_id(PDO $dbh): int
{
    return org_theme_viewer_user_id($dbh);
}

function org_public_publish_layout_column(PDO $dbh): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached === '' ? null : $cached;
    }
    $cached = '';
    foreach (['declared_layout', 'layout_type', 'layout'] as $col) {
        try {
            $st = $dbh->prepare('
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'public_posts\' AND COLUMN_NAME = :col
                LIMIT 1
            ');
            $st->execute([':col' => $col]);
            if ($st->fetchColumn()) {
                $cached = $col;
                return $col;
            }
        } catch (Throwable $e) {
            // try next
        }
    }
    return null;
}

function org_public_attachment_kind(string $ext, string $mime): string
{
    $ext = strtolower(trim($ext));
    $mime = strtolower(trim($mime));
    if (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return 'image';
    }
    if (strpos($mime, 'video/') === 0 || in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], true)) {
        return 'video';
    }
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        return 'pdf';
    }
    return 'file';
}

/**
 * Copy org_post_attachments (image/video/pdf) onto a public post.
 *
 * @return int number of attachments copied
 */
function org_public_publish_copy_attachments(PDO $dbh, int $orgId, int $orgPostId, int $publicPostId): int
{
    if ($orgId <= 0 || $orgPostId <= 0 || $publicPostId <= 0) {
        return 0;
    }

    try {
        $st = $dbh->prepare('
            SELECT file_path, mime_type, mime, ext
            FROM org_post_attachments
            WHERE org_id = :org AND post_id = :pid
            ORDER BY id ASC
        ');
        $st->execute([':org' => $orgId, ':pid' => $orgPostId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 0;
    }

    if (!$rows) {
        return 0;
    }

    $baseDir = dirname(__DIR__, 2) . '/public_user/uploads/posts';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }
    $subDir = $baseDir . '/' . date('Ym');
    if (!is_dir($subDir)) {
        @mkdir($subDir, 0775, true);
    }

    $orgRoot = dirname(__DIR__);
    $copied = 0;
    $stIns = $dbh->prepare('
        INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at)
        VALUES (:pid, :t, :fp, NULL, NOW())
    ');

    foreach ($rows as $row) {
        $rel = ltrim(str_replace('\\', '/', (string)($row['file_path'] ?? '')), '/');
        if ($rel === '') {
            continue;
        }
        $srcAbs = $orgRoot . '/' . $rel;
        if (!is_file($srcAbs)) {
            continue;
        }

        $ext = strtolower(trim((string)($row['ext'] ?? pathinfo($rel, PATHINFO_EXTENSION))));
        $mime = trim((string)($row['mime_type'] ?? $row['mime'] ?? ''));
        $kind = org_public_attachment_kind($ext, $mime);
        if (!in_array($kind, ['image', 'video', 'pdf'], true)) {
            continue;
        }

        if ($ext === '' || $ext === 'bin') {
            $ext = $kind === 'image' ? 'jpg' : ($kind === 'video' ? 'mp4' : 'pdf');
        }

        $fname = 'p' . $publicPostId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destAbs = $subDir . '/' . $fname;
        if (!@copy($srcAbs, $destAbs)) {
            continue;
        }

        $webPath = 'uploads/posts/' . date('Ym') . '/' . $fname;
        $stIns->execute([
            ':pid' => $publicPostId,
            ':t' => $kind,
            ':fp' => $webPath,
        ]);
        $copied++;
    }

    return $copied;
}

/**
 * Create a public_posts row for the linked publisher.
 *
 * @return int public post id, or 0 on failure
 */
function org_public_publish_from_org_post(
    PDO $dbh,
    int $publisherUserId,
    int $orgId,
    int $orgPostId,
    string $title,
    string $body
): int {
    if ($publisherUserId <= 0 || $orgId <= 0) {
        return 0;
    }
    if (!publisher_account_is($dbh, $publisherUserId)) {
        return 0;
    }

    $title = trim($title);
    $body = trim($body);
    if ($title === '' && $body === '') {
        return 0;
    }

    if (mb_strlen($title) > 120) {
        $title = mb_substr($title, 0, 120);
    }
    if (mb_strlen($body) > 50000) {
        $body = mb_substr($body, 0, 50000);
    }

    $description = null;
    if ($title === '' && $body !== '') {
        $description = mb_substr($body, 0, 255);
    }

    $visibility = publisher_post_visibility($dbh, $publisherUserId, 'public');
    $layoutColumn = org_public_publish_layout_column($dbh);
    $categoryId = resolveUserPostCategoryId($dbh, $publisherUserId, 0, 'text');

    try {
        if ($layoutColumn) {
            $st = $dbh->prepare("
                INSERT INTO public_posts (
                    user_id, title, description, body, visibility,
                    device_label, device_viewport, music_title, music_artist,
                    {$layoutColumn}, category_id, created_at, updated_at, is_deleted
                ) VALUES (
                    :uid, :t, :d, :b, :v,
                    '', '', '', '',
                    NULL, :cid, NOW(), NOW(), 0
                )
            ");
            $st->execute([
                ':uid' => $publisherUserId,
                ':t' => $title !== '' ? $title : null,
                ':d' => $description,
                ':b' => $body !== '' ? $body : null,
                ':v' => $visibility,
                ':cid' => $categoryId ?: null,
            ]);
        } else {
            $st = $dbh->prepare('
                INSERT INTO public_posts (
                    user_id, title, description, body, visibility,
                    device_label, device_viewport, music_title, music_artist,
                    category_id, created_at, updated_at, is_deleted
                ) VALUES (
                    :uid, :t, :d, :b, :v,
                    \'\', \'\', \'\', \'\',
                    :cid, NOW(), NOW(), 0
                )
            ');
            $st->execute([
                ':uid' => $publisherUserId,
                ':t' => $title !== '' ? $title : null,
                ':d' => $description,
                ':b' => $body !== '' ? $body : null,
                ':v' => $visibility,
                ':cid' => $categoryId ?: null,
            ]);
        }

        $publicPostId = (int)$dbh->lastInsertId();
        if ($publicPostId <= 0) {
            return 0;
        }

        publisher_repair_user_as_publisher($dbh, $publisherUserId);
        if ($orgPostId > 0) {
            org_public_publish_copy_attachments($dbh, $orgId, $orgPostId, $publicPostId);
        }

        return $publicPostId;
    } catch (Throwable $e) {
        return 0;
    }
}
