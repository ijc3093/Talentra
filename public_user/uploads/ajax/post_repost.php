<?php
declare(strict_types=1);

/**
 * Clone a viewable post as a new post by the current user.
 * Destination: friends → feed.php Friend tab; public → public.php Public tab.
 *
 * Copies title, description, body, media, and per-slide captions.
 * Do not copy source category_id: trg_public_posts_category_owner_bi requires
 * category ownership to match the new post's user_id.
 */
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';
require_once __DIR__ . '/../includes/publisher_accounts.php';
require_once __DIR__ . '/../includes/device_profile.php';
require_once __DIR__ . '/../includes/post_upload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$sourceId = (int)($_POST['post_id'] ?? $_POST['id'] ?? $_POST['source_post_id'] ?? 0);
$visibility = strtolower(trim((string)($_POST['visibility'] ?? 'friends')));

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Please sign in.']);
    exit;
}
if ($sourceId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing post.']);
    exit;
}

if (!in_array($visibility, ['friends', 'public'], true)) {
    $visibility = publisher_is_publisher_user($dbh, $meId) ? 'public' : 'friends';
}
$visibility = publisher_post_visibility($dbh, $meId, $visibility);

try {
    publisher_ensure_schema($dbh);
    if (function_exists('device_profile_ensure_post_columns')) {
        device_profile_ensure_post_columns($dbh);
    }
    if (function_exists('post_attachments_ensure_slide_columns')) {
        post_attachments_ensure_slide_columns($dbh);
    }

    $st = $dbh->prepare("
      SELECT p.*
      FROM public_posts p
      WHERE p.id = :id AND COALESCE(p.is_deleted, 0) = 0
      LIMIT 1
    ");
    $st->execute([':id' => $sourceId]);
    $src = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$src) {
        echo json_encode(['ok' => false, 'error' => 'Post not found.']);
        exit;
    }

    if (!publisher_can_view_post($dbh, $meId, $src)) {
        echo json_encode(['ok' => false, 'error' => 'You cannot repost this.']);
        exit;
    }

    $title = trim((string)($src['title'] ?? ''));
    $description = trim((string)($src['description'] ?? ''));
    $body = trim((string)($src['body'] ?? ''));

    // Keep caption text even when one field is empty (create-post paths vary).
    if ($body === '' && $description !== '') {
        $body = preg_replace('/\s*\[\[layout:[a-z0-9_]+\]\]\s*/i', ' ', $description) ?? $description;
        $body = trim(preg_replace('/\s{2,}/', ' ', $body) ?? $body);
    }
    if ($description === '' && $body !== '') {
        $description = $body;
    }
    if ($title === '' && $body !== '') {
        // Do not invent a title from body; leave empty so body is the caption.
        $title = '';
    }

    if (function_exists('mb_substr')) {
        if (mb_strlen($description) > 255) {
            $description = mb_substr($description, 0, 255);
        }
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }
    } else {
        if (strlen($description) > 255) {
            $description = substr($description, 0, 255);
        }
        if (strlen($title) > 255) {
            $title = substr($title, 0, 255);
        }
    }

    $musicTitle = trim((string)($src['music_title'] ?? ''));
    $musicArtist = trim((string)($src['music_artist'] ?? ''));
    $deviceLabel = trim((string)($src['device_label'] ?? ''));
    $deviceViewport = trim((string)($src['device_viewport'] ?? ''));
    $layoutOverride = trim((string)($src['layout_override'] ?? $src['declared_layout'] ?? ''));

    // Never copy another user's category — DB trigger rejects cross-owner category_id.
    $categoryId = null;

    $layoutColumn = '';
    foreach (['layout_override', 'declared_layout'] as $col) {
        try {
            $cols = $dbh->query('SHOW COLUMNS FROM public_posts LIKE ' . $dbh->quote($col))->fetch(PDO::FETCH_ASSOC);
            if (!empty($cols)) {
                $layoutColumn = $col;
                break;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $dbh->beginTransaction();

    $params = [
        ':uid' => $meId,
        ':t' => $title !== '' ? $title : null,
        ':d' => $description !== '' ? $description : null,
        ':b' => $body !== '' ? $body : null,
        ':v' => $visibility,
        ':dl' => $deviceLabel,
        ':dv' => $deviceViewport,
        ':mt' => $musicTitle,
        ':ma' => $musicArtist,
        ':cid' => $categoryId,
    ];

    if ($layoutColumn !== '') {
        $ins = $dbh->prepare("
          INSERT INTO public_posts
            (user_id, title, description, body, visibility, device_label, device_viewport, music_title, music_artist, {$layoutColumn}, category_id, created_at, updated_at, is_deleted)
          VALUES
            (:uid, :t, :d, :b, :v, :dl, :dv, :mt, :ma, :layoutv, :cid, NOW(), NOW(), 0)
        ");
        $params[':layoutv'] = $layoutOverride !== '' ? $layoutOverride : null;
        $ins->execute($params);
    } else {
        $ins = $dbh->prepare("
          INSERT INTO public_posts
            (user_id, title, description, body, visibility, device_label, device_viewport, music_title, music_artist, category_id, created_at, updated_at, is_deleted)
          VALUES
            (:uid, :t, :d, :b, :v, :dl, :dv, :mt, :ma, :cid, NOW(), NOW(), 0)
        ");
        $ins->execute($params);
    }

    $newId = (int)$dbh->lastInsertId();
    if ($newId <= 0) {
        throw new RuntimeException('Could not create post.');
    }

    // Verify caption landed (some schemas/triggers can drop empty-looking values).
    try {
        $stFix = $dbh->prepare('
          UPDATE public_posts
          SET title = COALESCE(NULLIF(title, \'\'), :t),
              description = COALESCE(NULLIF(description, \'\'), :d),
              body = COALESCE(NULLIF(body, \'\'), :b)
          WHERE id = :id AND user_id = :uid
          LIMIT 1
        ');
        $stFix->execute([
            ':t' => $title !== '' ? $title : null,
            ':d' => $description !== '' ? $description : null,
            ':b' => $body !== '' ? $body : null,
            ':id' => $newId,
            ':uid' => $meId,
        ]);
    } catch (Throwable $eFix) {
        // non-fatal
    }

    $hasSlideCols = true;
    try {
        $att = $dbh->prepare("
          SELECT type, file_path, thumb_path, slide_title, slide_body
          FROM public_post_attachments
          WHERE post_id = :pid
          ORDER BY id ASC
        ");
        $att->execute([':pid' => $sourceId]);
        $rows = $att->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $eAtt) {
        $hasSlideCols = false;
        $att = $dbh->prepare("
          SELECT type, file_path, thumb_path
          FROM public_post_attachments
          WHERE post_id = :pid
          ORDER BY id ASC
        ");
        $att->execute([':pid' => $sourceId]);
        $rows = $att->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($rows) {
        $allowedTypes = ['image', 'video', 'pdf', 'file'];
        if ($hasSlideCols) {
            $insA = $dbh->prepare("
              INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, slide_title, slide_body, created_at)
              VALUES (:pid, :t, :fp, :tp, :st, :sb, NOW())
            ");
        } else {
            $insA = $dbh->prepare("
              INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at)
              VALUES (:pid, :t, :fp, :tp, NOW())
            ");
        }
        foreach ($rows as $row) {
            $fp = trim((string)($row['file_path'] ?? ''));
            if ($fp === '') {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'image')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'file';
            }
            $thumb = trim((string)($row['thumb_path'] ?? ''));
            $slideTitle = trim((string)($row['slide_title'] ?? ''));
            $slideBody = trim((string)($row['slide_body'] ?? ''));
            if (function_exists('mb_substr') && mb_strlen($slideTitle) > 120) {
                $slideTitle = mb_substr($slideTitle, 0, 120);
            } elseif (strlen($slideTitle) > 120) {
                $slideTitle = substr($slideTitle, 0, 120);
            }
            if ($hasSlideCols) {
                $insA->execute([
                    ':pid' => $newId,
                    ':t' => $type,
                    ':fp' => $fp,
                    ':tp' => $thumb !== '' ? $thumb : null,
                    ':st' => $slideTitle,
                    ':sb' => $slideBody !== '' ? $slideBody : null,
                ]);
            } else {
                $insA->execute([
                    ':pid' => $newId,
                    ':t' => $type,
                    ':fp' => $fp,
                    ':tp' => $thumb !== '' ? $thumb : null,
                ]);
            }
        }
    }

    $dbh->commit();

    $redirect = ($visibility === 'public')
        ? ('public.php?tab=public&post=' . $newId)
        : ('feed.php?post=' . $newId);

    echo json_encode([
        'ok' => true,
        'post_id' => $newId,
        'visibility' => $visibility,
        'redirect' => $redirect,
        'title' => $title,
        'has_body' => $body !== '' ? 1 : 0,
        'message' => $visibility === 'public'
            ? 'Reposted to Public.'
            : 'Reposted to Friends.',
    ]);
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    error_log('post_repost.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Unable to repost. Try again.']);
}
