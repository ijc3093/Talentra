<?php
declare(strict_types=1);

/**
 * explore_api.php — JSON for mobile Explore grid (same filters as explore.php).
 * GET: q (optional search)
 * Response: { ok, me_id, q, items: [{ id, is_video, type, media_url, thumb_url }] }
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/missing_media.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('explore_api_media_src')) {
    function explore_api_media_src(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = (string)preg_replace('#^(/+)?public_user/#', '', $path);
        if (preg_match('~^(https?:)?//~i', $path)) {
            return $path;
        }
        return ltrim($path, './');
    }
}

if (!function_exists('explore_api_path_is_video')) {
    function explore_api_path_is_video(string $path): bool
    {
        return (bool)preg_match('/\.(mp4|webm|mov|m4v|ogg)(\?|#|$)/i', $path);
    }
}

if (!function_exists('explore_api_path_is_image')) {
    function explore_api_path_is_image(string $path): bool
    {
        return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(\?|#|$)/i', $path);
    }
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    publisher_ensure_schema($dbh);
    if (function_exists('sendNoCacheHeadersUser')) {
        sendNoCacheHeadersUser();
    }

    $meId = (int)($_SESSION['user_id'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));
    $isPublisherWorkspaceViewer = publisher_workspace_viewer($dbh, $meId);

    $where = 'COALESCE(p.is_deleted, 0) = 0 AND COALESCE(p.is_archived,0) = 0 AND ' . publisher_discover_list_where_sql($dbh, $meId);
    $params = publisher_discover_list_where_params($dbh, $meId);
    if ($meId > 0 && function_exists('fs_ensure_blocks_table') && fs_ensure_blocks_table($dbh)) {
        $where .= ' AND ' . fs_block_exclude_author_sql('p.user_id', ':fsBlockMe', ':fsBlockMe2');
        $params[':fsBlockMe'] = $meId;
        $params[':fsBlockMe2'] = $meId;
    }
    $where .= ' AND ' . publisher_public_surface_scope_sql($dbh, $meId, false);
    $params = array_merge($params, publisher_public_surface_scope_params($dbh, $meId, false));
    if (publisher_public_stranger_surface($dbh, $meId)) {
        $where .= " AND (
            COALESCE(u.account_kind, 'personal') <> 'publisher'
            OR p.user_id = :pubBrandOwn
            OR " . publisher_public_discoverable_publisher_sql($dbh, 'u') . '
        )';
        $params[':pubBrandOwn'] = $meId;
    }
    if ($isPublisherWorkspaceViewer) {
        $where .= ' AND ' . publisher_author_is_publisher_sql('u');
    } else {
        $where .= ' AND ' . publisher_author_is_personal_sql('u');
    }
    $where .= " AND EXISTS (
        SELECT 1 FROM public_post_attachments a
        WHERE a.post_id = p.id
          AND LOWER(TRIM(COALESCE(a.type,''))) IN ('image','video','gif')
    )";
    if ($q !== '') {
        $where .= " AND (COALESCE(p.title,'') LIKE :qTitle OR COALESCE(p.body,'') LIKE :qBody OR COALESCE(u.name,u.username,'') LIKE :qName OR COALESCE(u.username,'') LIKE :qUser)";
        $qLike = '%' . $q . '%';
        $params[':qTitle'] = $qLike;
        $params[':qBody'] = $qLike;
        $params[':qName'] = $qLike;
        $params[':qUser'] = $qLike;
    }

    $sql = "
SELECT
  p.id
FROM public_posts p
JOIN users u ON u.id = p.user_id
WHERE {$where}
ORDER BY COALESCE(p.updated_at,p.created_at) DESC, p.id DESC
LIMIT 240";
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($posts as $post) {
        $pid = (int)($post['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        try {
            $stA = $dbh->prepare('SELECT type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC');
            $stA->execute([':pid' => $pid]);
            $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eAtt) {
            $attachments = [];
        }

        $media = [];
        foreach ($attachments as $a) {
            $type = strtolower(trim((string)($a['type'] ?? '')));
            if (!in_array($type, ['image', 'video', 'gif'], true)) {
                continue;
            }
            $rawFile = trim((string)($a['file_path'] ?? ''));
            $rawThumb = trim((string)($a['thumb_path'] ?? ''));
            $usableFile = function_exists('msb_public_media_usable')
                ? msb_public_media_usable($rawFile)
                : $rawFile;
            if ($usableFile === '') {
                continue;
            }
            $usableThumb = '';
            if ($rawThumb !== '') {
                $usableThumb = function_exists('msb_public_media_usable')
                    ? msb_public_media_usable($rawThumb)
                    : $rawThumb;
            }
            $media[] = [
                'type' => $type,
                'file_path' => explore_api_media_src($usableFile),
                'thumb_path' => $usableThumb !== '' ? explore_api_media_src($usableThumb) : '',
            ];
        }

        if ($media === [] || count($media) > 1) {
            continue;
        }

        $first = $media[0];
        $type = (string)($first['type'] ?? '');
        $file = (string)($first['file_path'] ?? '');
        $thumb = (string)($first['thumb_path'] ?? '');
        $isVideo = ($type === 'video') || explore_api_path_is_video($file);
        if ($isVideo && $file === '') {
            continue;
        }

        $thumbURL = '';
        if ($thumb !== '' && explore_api_path_is_image($thumb)) {
            $thumbURL = $thumb;
        } elseif (!$isVideo && explore_api_path_is_image($file)) {
            $thumbURL = $file;
        }

        $items[] = [
            'id' => $pid,
            'is_video' => $isVideo,
            'type' => $isVideo ? 'video' : ($type === 'gif' ? 'gif' : 'image'),
            'media_url' => $file,
            'thumb_url' => $thumbURL,
            'file_path' => $file,
            'thumb_path' => $thumbURL,
        ];

        if (count($items) >= 100) {
            break;
        }
    }

    echo json_encode([
        'ok' => true,
        'me_id' => $meId,
        'q' => $q,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load explore posts.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
