<?php
declare(strict_types=1);

/**
 * Helpers for the owner's private bookmarked posts / stories list.
 */

if (!function_exists('msb_bookmark_ensure_schema')) {
    function msb_bookmark_ensure_schema(PDO $dbh): void
    {
        try {
            $dbh->exec("
              CREATE TABLE IF NOT EXISTS public_post_saves (
                post_id BIGINT(20) UNSIGNED NOT NULL,
                user_id INT(11) NOT NULL,
                saved_at DATETIME NOT NULL,
                PRIMARY KEY (post_id, user_id),
                KEY idx_user_saved_at (user_id, saved_at)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Table may already exist with a different key layout.
        }

        if (function_exists('device_profile_table_has_column')) {
            try {
                if (!device_profile_table_has_column($dbh, 'public_post_saves', 'saved_as_story')) {
                    $dbh->exec('ALTER TABLE public_post_saves ADD COLUMN saved_as_story TINYINT(1) NOT NULL DEFAULT 0 AFTER saved_at');
                }
            } catch (Throwable $e) {
                // Older hosts may lack ALTER rights; display still works via layout detection.
            }
        }
    }
}

if (!function_exists('msb_bookmark_media_src')) {
    function msb_bookmark_media_src(string $path): string
    {
        if (function_exists('msb_archive_media_src')) {
            return msb_archive_media_src($path);
        }
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^(https?:)?//~i', $path)) {
            return $path;
        }
        if (isset($path[0]) && $path[0] === '/') {
            return $path;
        }
        return './' . ltrim($path, './');
    }
}

if (!function_exists('msb_bookmark_h')) {
    function msb_bookmark_h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('msb_bookmark_date_badge')) {
    /**
     * @return array{day:string,month:string}
     */
    function msb_bookmark_date_badge(string $dt): array
    {
        $ts = strtotime(trim($dt));
        if ($ts === false) {
            return ['day' => '', 'month' => ''];
        }
        $day = date('j', $ts);
        $month = date('Y', $ts) === date('Y')
            ? date('M', $ts)
            : date('M Y', $ts);
        return ['day' => $day, 'month' => $month];
    }
}

if (!function_exists('msb_bookmark_fetch_posts')) {
    /**
     * @return list<array<string,mixed>>
     */
    function msb_bookmark_fetch_posts(PDO $dbh, int $userId, int $limit = 200): array
    {
        $userId = max(0, $userId);
        $limit = max(1, min(200, $limit));
        if ($userId <= 0) {
            return [];
        }

        msb_bookmark_ensure_schema($dbh);
        if (function_exists('device_profile_ensure_post_columns')) {
            device_profile_ensure_post_columns($dbh);
        }
        if (!function_exists('post_layout_select_sql')) {
            require_once __DIR__ . '/post_layout.php';
        }
        $layoutSelect = function_exists('post_layout_select_sql')
            ? post_layout_select_sql($dbh)
            : "'' AS declared_layout,";

        $hasStoryFlag = false;
        try {
            $hasStoryFlag = function_exists('device_profile_table_has_column')
                && device_profile_table_has_column($dbh, 'public_post_saves', 'saved_as_story');
        } catch (Throwable $e) {
            $hasStoryFlag = false;
        }
        $storySelect = $hasStoryFlag
            ? 'COALESCE(s.saved_as_story,0) AS saved_as_story,'
            : '0 AS saved_as_story,';

        try {
            $st = $dbh->prepare("
              SELECT
                p.id,
                p.user_id,
                COALESCE(p.title,'') AS title,
                COALESCE(p.description,'') AS description,
                COALESCE(p.body,'') AS body,
                p.created_at,
                COALESCE(p.updated_at, p.created_at) AS updated_at,
                s.saved_at,
                {$storySelect}
                {$layoutSelect}
                COALESCE(u.name,'') AS author_name,
                COALESCE(u.username,'') AS author_username,
                (
                  SELECT aa.file_path
                  FROM public_post_attachments aa
                  WHERE aa.post_id = p.id
                  ORDER BY
                    CASE
                      WHEN aa.type IN ('image','gif') THEN 0
                      WHEN aa.type = 'video' THEN 1
                      ELSE 2
                    END,
                    aa.id ASC
                  LIMIT 1
                ) AS thumb_file,
                (
                  SELECT aa.thumb_path
                  FROM public_post_attachments aa
                  WHERE aa.post_id = p.id
                  ORDER BY
                    CASE
                      WHEN aa.type IN ('image','gif') THEN 0
                      WHEN aa.type = 'video' THEN 1
                      ELSE 2
                    END,
                    aa.id ASC
                  LIMIT 1
                ) AS thumb_path,
                (
                  SELECT aa.type
                  FROM public_post_attachments aa
                  WHERE aa.post_id = p.id
                  ORDER BY
                    CASE
                      WHEN aa.type IN ('image','gif') THEN 0
                      WHEN aa.type = 'video' THEN 1
                      ELSE 2
                    END,
                    aa.id ASC
                  LIMIT 1
                ) AS thumb_type,
                COALESCE(p.views_count,0) AS views_count,
                (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND COALESCE(c.is_deleted,0) = 0) AS comment_count,
                (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction = 'love') AS love_count
              FROM public_post_saves s
              INNER JOIN public_posts p ON p.id = s.post_id
              LEFT JOIN users u ON u.id = p.user_id
              WHERE s.user_id = :me
                AND COALESCE(p.is_deleted,0) = 0
              ORDER BY s.saved_at DESC, p.id DESC
              LIMIT {$limit}
            ");
            $st->execute([':me' => $userId]);
            $posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        foreach ($posts as &$post) {
            $thumb = trim((string)($post['thumb_path'] ?? ''));
            if ($thumb === '') {
                $thumb = trim((string)($post['thumb_file'] ?? ''));
            }
            $post['preview_src'] = msb_bookmark_media_src($thumb);
            $caption = trim((string)(($post['body'] ?? '') !== '' ? $post['body'] : ($post['description'] ?? '')));
            if ($caption === '') {
                $caption = trim((string)($post['title'] ?? ''));
            }
            if (function_exists('post_strip_layout_marker')) {
                $caption = post_strip_layout_marker($caption);
            }
            $post['preview_text'] = $caption;

            $asStory = !empty($post['saved_as_story']) ? 1 : 0;
            if ($asStory === 0 && function_exists('post_is_story_only') && post_is_story_only($post)) {
                $asStory = 1;
            }
            $post['is_story'] = $asStory;
            $post['saved_as_story'] = $asStory;
        }
        unset($post);

        return $posts;
    }
}

if (!function_exists('msb_bookmark_prepare_view')) {
    /**
     * @param list<array<string,mixed>> $posts
     * @return array{storyCircles:list<array<string,mixed>>,hasStories:bool,feedPosts:list<array<string,mixed>>,avatarUrl:string}
     */
    function msb_bookmark_prepare_view(array $posts): array
    {
        $storyPosts = [];
        $feedPosts = [];
        foreach ($posts as $post) {
            if (!empty($post['saved_as_story']) || !empty($post['is_story'])) {
                $storyPosts[] = $post;
            } else {
                $feedPosts[] = $post;
            }
        }
        $storyCircles = [];
        foreach ($storyPosts as $post) {
            $pid = (int)($post['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $previewSrc = (string)($post['preview_src'] ?? '');
            $thumbType = strtolower(trim((string)($post['thumb_type'] ?? '')));
            $isVideo = ($thumbType === 'video');
            $caption = trim((string)($post['preview_text'] ?? ''));
            if (preg_match('/^story\s*#\s*\d+$/i', $caption)) {
                $caption = '';
            }
            $badge = function_exists('msb_archive_date_badge')
                ? msb_archive_date_badge((string)($post['saved_at'] ?? $post['created_at'] ?? ''))
                : msb_bookmark_date_badge((string)($post['saved_at'] ?? $post['created_at'] ?? ''));
            $label = trim($badge['day'] . ' ' . $badge['month']);
            $authorName = trim((string)($post['author_name'] ?? ''));
            if ($authorName === '') {
                $authorName = trim((string)($post['author_username'] ?? 'Story'));
            }
            if ($label === '') {
                $label = $authorName !== '' ? $authorName : 'Story';
            }
            $avatarUrl = '';
            if (function_exists('msb_archive_avatar_url')) {
                $avatarUrl = msb_archive_avatar_url([
                    'id' => (int)($post['user_id'] ?? 0),
                    'name' => $authorName,
                    'username' => (string)($post['author_username'] ?? ''),
                    'image' => '',
                ], 96);
            }
            $storyCircles[] = [
                'postId' => $pid,
                'src' => $previewSrc,
                'type' => $isVideo ? 'video' : ($previewSrc !== '' ? 'image' : 'text'),
                'caption' => $caption,
                'label' => $label,
                'authorName' => $authorName,
                'username' => trim((string)($post['author_username'] ?? '')),
                'avatarUrl' => $avatarUrl,
                'createdAt' => (string)($post['saved_at'] ?? $post['created_at'] ?? ''),
                'ringSrc' => $previewSrc !== '' ? $previewSrc : $avatarUrl,
            ];
        }
        return [
            'storyCircles' => $storyCircles,
            'hasStories' => count($storyCircles) > 0,
            'feedPosts' => $feedPosts,
            'avatarUrl' => '',
        ];
    }
}
