<?php
declare(strict_types=1);

/**
 * Admin posts list / profile helpers (public_posts).
 */
require_once __DIR__ . '/msb_moderation_activity.php';

if (!function_exists('posts_admin_h')) {
    function posts_admin_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('posts_admin_fmt')) {
    function posts_admin_fmt(?string $dt): string
    {
        if (!$dt) {
            return '—';
        }
        $ts = strtotime($dt);
        return $ts ? date('M j, Y g:i A', $ts) : $dt;
    }
}

if (!function_exists('posts_admin_initials')) {
    function posts_admin_initials(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return '??';
        }
        $name = str_replace(['_', '.', '-', '@'], ' ', $name);
        $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
        if (!$parts) {
            return '??';
        }
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $second = count($parts) > 1
            ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
            : mb_strtoupper(mb_substr($parts[0], 1, 1));
        $ini = trim($first . $second);
        return $ini !== '' ? $ini : '??';
    }
}

if (!function_exists('posts_admin_avatar_color')) {
    function posts_admin_avatar_color(string $key): string
    {
        $key = strtolower(trim($key));
        $hash = crc32($key);
        $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
        return $palette[$hash % count($palette)];
    }
}

if (!function_exists('posts_admin_media_url')) {
    function posts_admin_media_url(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }
        if ($path[0] === '/') {
            return '..' . $path;
        }
        return '../public_user/' . ltrim($path, '/');
    }
}

if (!function_exists('posts_admin_preview')) {
    function posts_admin_preview(string $s, int $n = 72): string
    {
        $s = trim(html_entity_decode(strip_tags($s)));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        if (mb_strlen($s) <= $n) {
            return $s;
        }
        return mb_substr($s, 0, $n - 1) . '…';
    }
}

if (!function_exists('posts_admin_status_from_row')) {
    /**
     * @param array<string,mixed> $row
     * @return array{key:string,label:string,cls:string}
     */
    function posts_admin_status_from_row(array $row): array
    {
        if ((int)($row['is_deleted'] ?? 0) === 1) {
            return ['key' => 'removed', 'label' => 'Removed', 'cls' => 'removed'];
        }
        $pending = (int)($row['pending_count'] ?? 0);
        $reports = (int)($row['report_count'] ?? 0);
        if ($pending > 0) {
            return ['key' => 'pending', 'label' => 'Pending Review', 'cls' => 'pending'];
        }
        if ($reports > 0) {
            return ['key' => 'flagged', 'label' => 'Flagged', 'cls' => 'flagged'];
        }
        return ['key' => 'published', 'label' => 'Published', 'cls' => 'published'];
    }
}

if (!function_exists('posts_admin_delta')) {
    /** @return array{value:int,delta_pct:int} */
    function posts_admin_delta(int $now, int $prev): array
    {
        $delta = 0;
        if ($prev > 0) {
            $delta = (int)round((($now - $prev) / $prev) * 100);
        } elseif ($now > 0) {
            $delta = 100;
        }
        return ['value' => $now, 'delta_pct' => $delta];
    }
}

if (!function_exists('posts_admin_normalize_kind')) {
    /** @return 'personal'|'publisher'|'commerce' */
    function posts_admin_normalize_kind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return in_array($kind, ['personal', 'publisher', 'commerce'], true) ? $kind : 'personal';
    }
}

if (!function_exists('posts_admin_user_kind_where')) {
    function posts_admin_user_kind_where(string $kind, string $alias = 'u'): string
    {
        $kind = posts_admin_normalize_kind($kind);
        $p = $alias !== '' ? ($alias . '.') : '';
        $commerce = "(LOWER(COALESCE({$p}account_kind,'')) = 'commerce' OR LOWER(COALESCE({$p}publisher_category,'')) = 'commerce')";
        $publisher = "(LOWER(COALESCE({$p}account_kind,'')) = 'publisher' OR UPPER(COALESCE({$p}friend_code,'')) LIKE 'PUB-%')";
        return match ($kind) {
            'commerce' => $commerce,
            'publisher' => "({$publisher}) AND NOT ({$commerce})",
            default => "NOT ({$commerce}) AND NOT ({$publisher})",
        };
    }
}

if (!function_exists('posts_admin_kind_counts')) {
    /**
     * @return array{personal:int,publisher:int,commerce:int}
     */
    function posts_admin_kind_counts(PDO $dbh): array
    {
        $out = ['personal' => 0, 'publisher' => 0, 'commerce' => 0];
        if (!msb_mod_table_exists($dbh, 'public_posts') || !msb_mod_table_exists($dbh, 'users')) {
            return $out;
        }
        foreach (['personal', 'publisher', 'commerce'] as $k) {
            $where = posts_admin_user_kind_where($k, 'u');
            $out[$k] = msb_mod_count_safe($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$where}
            ");
        }
        return $out;
    }
}

if (!function_exists('posts_admin_stats')) {
    /**
     * @return array<string,array{value:int,delta_pct:int}>
     */
    function posts_admin_stats(PDO $dbh, string $kind = 'all'): array
    {
        $z = ['value' => 0, 'delta_pct' => 0];
        if (!msb_mod_table_exists($dbh, 'public_posts')) {
            return ['all' => $z, 'pending' => $z, 'published' => $z, 'flagged' => $z, 'removed' => $z];
        }

        $kind = strtolower(trim($kind));
        $applyKind = in_array($kind, ['personal', 'publisher', 'commerce'], true);
        $hasUsers = msb_mod_table_exists($dbh, 'users');
        $kindJoin = '';
        $kindWhere = '1=1';
        if ($applyKind && $hasUsers) {
            $kindJoin = 'INNER JOIN users u ON u.id = p.user_id';
            $kindWhere = posts_admin_user_kind_where($kind, 'u');
        }

        $hasReports = msb_mod_table_exists($dbh, 'public_user_reports');

        $all = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere}
        ");
        $all7 = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND p.created_at >= (NOW() - INTERVAL 7 DAY)
        ");
        $all7prev = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere}
              AND p.created_at >= (NOW() - INTERVAL 14 DAY) AND p.created_at < (NOW() - INTERVAL 7 DAY)
        ");

        $removed = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND p.is_deleted = 1
        ");
        $removed7 = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND p.is_deleted = 1 AND COALESCE(p.updated_at, p.created_at) >= (NOW() - INTERVAL 7 DAY)
        ");
        $removed7prev = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND p.is_deleted = 1
              AND COALESCE(p.updated_at, p.created_at) >= (NOW() - INTERVAL 14 DAY)
              AND COALESCE(p.updated_at, p.created_at) < (NOW() - INTERVAL 7 DAY)
        ");

        $pending = 0;
        $flagged = 0;
        $pending7 = 0;
        $pending7prev = 0;
        $flagged7 = 0;
        $flagged7prev = 0;
        $published = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
        ");

        if ($hasReports) {
            $pending = msb_mod_count_safe($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                {$kindJoin}
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id AND r.status = 'pending'
                WHERE {$kindWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
            ");
            $flagged = msb_mod_count_safe($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                {$kindJoin}
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id
                WHERE {$kindWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND NOT EXISTS (
                    SELECT 1 FROM public_user_reports r2
                    WHERE r2.target_type = 'post' AND r2.target_id = p.id AND r2.status = 'pending'
                  )
            ");
            $published = msb_mod_count_safe($dbh, "
                SELECT COUNT(*) FROM public_posts p
                {$kindJoin}
                WHERE {$kindWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND NOT EXISTS (
                    SELECT 1 FROM public_user_reports r
                    WHERE r.target_type = 'post' AND r.target_id = p.id
                  )
            ");
            $pending7 = msb_mod_count_safe($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                {$kindJoin}
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id AND r.status = 'pending'
                WHERE {$kindWhere} AND r.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $pending7prev = msb_mod_count_safe($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                {$kindJoin}
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id AND r.status = 'pending'
                WHERE {$kindWhere}
                  AND r.created_at >= (NOW() - INTERVAL 14 DAY) AND r.created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $flagged7 = msb_mod_count_safe($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                {$kindJoin}
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id
                WHERE {$kindWhere} AND r.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $flagged7prev = msb_mod_count_safe($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                {$kindJoin}
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id
                WHERE {$kindWhere}
                  AND r.created_at >= (NOW() - INTERVAL 14 DAY) AND r.created_at < (NOW() - INTERVAL 7 DAY)
            ");
        }

        $pub7 = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL) AND p.created_at >= (NOW() - INTERVAL 7 DAY)
        ");
        $pub7prev = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM public_posts p
            {$kindJoin}
            WHERE {$kindWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
              AND p.created_at >= (NOW() - INTERVAL 14 DAY) AND p.created_at < (NOW() - INTERVAL 7 DAY)
        ");

        return [
            'all' => ['value' => $all, 'delta_pct' => posts_admin_delta($all7, $all7prev)['delta_pct']],
            'pending' => ['value' => $pending, 'delta_pct' => posts_admin_delta($pending7, $pending7prev)['delta_pct']],
            'published' => ['value' => $published, 'delta_pct' => posts_admin_delta($pub7, $pub7prev)['delta_pct']],
            'flagged' => ['value' => $flagged, 'delta_pct' => posts_admin_delta($flagged7, $flagged7prev)['delta_pct']],
            'removed' => ['value' => $removed, 'delta_pct' => posts_admin_delta($removed7, $removed7prev)['delta_pct']],
        ];
    }
}

if (!function_exists('posts_admin_list')) {
    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    function posts_admin_list(
        PDO $dbh,
        string $status = 'all',
        string $q = '',
        string $visibility = 'all',
        string $postType = 'all',
        string $dateFrom = '',
        string $dateTo = '',
        int $page = 1,
        int $perPage = 10,
        string $kind = 'all'
    ): array {
        if (!msb_mod_table_exists($dbh, 'public_posts')) {
            return ['rows' => [], 'total' => 0];
        }

        $status = strtolower(trim($status));
        $visibility = strtolower(trim($visibility));
        $postType = strtolower(trim($postType));
        $kind = strtolower(trim($kind));
        $applyKind = in_array($kind, ['personal', 'publisher', 'commerce'], true);
        $q = trim($q);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($applyKind && msb_mod_table_exists($dbh, 'users')) {
            $where[] = posts_admin_user_kind_where($kind, 'u');
        }

        if ($status === 'removed') {
            $where[] = 'p.is_deleted = 1';
        } elseif (in_array($status, ['published', 'pending', 'flagged'], true)) {
            $where[] = '(p.is_deleted = 0 OR p.is_deleted IS NULL)';
        }

        if ($dateFrom !== '') {
            $where[] = 'p.created_at >= :df';
            $params[':df'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'p.created_at <= :dt';
            $params[':dt'] = $dateTo . ' 23:59:59';
        }
        if (in_array($visibility, ['public', 'friends', 'private'], true)) {
            $where[] = 'p.visibility = :vis';
            $params[':vis'] = $visibility;
        }
        if ($q !== '') {
            $where[] = '(u.username LIKE :q OR u.name LIKE :q OR u.email LIKE :q OR CAST(p.id AS CHAR) LIKE :q OR p.title LIKE :q OR p.description LIKE :q OR p.body LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $joinReports = '';
        $selectRr = '0 AS report_count, 0 AS pending_count';
        if (msb_mod_table_exists($dbh, 'public_user_reports')) {
            $joinReports = '
                LEFT JOIN (
                    SELECT target_id AS post_id, COUNT(*) AS report_count,
                           SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_count
                    FROM public_user_reports
                    WHERE target_type = \'post\'
                    GROUP BY target_id
                ) rr ON rr.post_id = p.id
            ';
            $selectRr = 'COALESCE(rr.report_count, 0) AS report_count, COALESCE(rr.pending_count, 0) AS pending_count';
            if ($status === 'pending') {
                $where[] = 'COALESCE(rr.pending_count, 0) > 0';
            } elseif ($status === 'flagged') {
                $where[] = 'COALESCE(rr.report_count, 0) > 0 AND COALESCE(rr.pending_count, 0) = 0';
            } elseif ($status === 'published') {
                $where[] = 'COALESCE(rr.report_count, 0) = 0';
            }
        } elseif (in_array($status, ['pending', 'flagged'], true)) {
            return ['rows' => [], 'total' => 0];
        }

        $sqlBase = '
            FROM public_posts p
            LEFT JOIN users u ON u.id = p.user_id
            ' . $joinReports . '
            WHERE ' . implode(' AND ', $where);

        try {
            $st = $dbh->prepare('SELECT COUNT(*) ' . $sqlBase);
            $st->execute($params);
            $total = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0];
        }

        $fetchLimit = $postType !== 'all' ? min(500, max($perPage * 8, 80)) : $perPage;
        $fetchOffset = $postType !== 'all' ? 0 : $offset;

        try {
            $sql = '
                SELECT
                    p.id, p.user_id, p.title, p.description, p.body, p.visibility,
                    p.created_at, p.updated_at, COALESCE(p.views_count, 0) AS views_count,
                    COALESCE(p.is_deleted, 0) AS is_deleted,
                    u.username, u.name, u.email, u.friend_code,
                    ' . $selectRr . '
                ' . $sqlBase . '
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT ' . (int)$fetchLimit . ' OFFSET ' . (int)$fetchOffset;
            $st = $dbh->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => $total];
        }

        $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
        $attachMap = [];
        if ($ids && msb_mod_table_exists($dbh, 'public_post_attachments')) {
            try {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $qAtt = $dbh->prepare("SELECT post_id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id IN ($in) ORDER BY id ASC");
                $qAtt->execute($ids);
                foreach ($qAtt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $pid = (int)$a['post_id'];
                    if (!isset($attachMap[$pid])) {
                        $attachMap[$pid] = [];
                    }
                    $attachMap[$pid][] = $a;
                }
            } catch (Throwable $e) {
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $pid = (int)($r['id'] ?? 0);
            $atts = $attachMap[$pid] ?? [];
            $types = [];
            $thumb = '';
            $hero = '';
            $video = '';
            foreach ($atts as $a) {
                $t = strtolower(trim((string)($a['type'] ?? 'file')));
                $types[] = $t;
                $fp = (string)($a['file_path'] ?? '');
                $tp = (string)($a['thumb_path'] ?? '');
                if ($thumb === '' && $tp !== '') {
                    $thumb = $tp;
                } elseif ($thumb === '' && $t === 'image' && $fp !== '') {
                    $thumb = $fp;
                }
                if ($t === 'video' && $fp !== '' && $video === '') {
                    $video = $fp;
                }
                if ($hero === '' && $t === 'image' && $fp !== '') {
                    $hero = $fp;
                } elseif ($hero === '' && $tp !== '') {
                    $hero = $tp;
                } elseif ($hero === '' && $t === 'video' && $fp !== '') {
                    $hero = $fp;
                }
            }
            $types = array_values(array_unique($types));
            $postTypeLabel = 'Text';
            if (in_array('video', $types, true)) {
                $postTypeLabel = 'Video';
            } elseif (in_array('image', $types, true)) {
                $postTypeLabel = 'Image';
            } elseif (preg_match('~https?://~i', (string)($r['body'] ?? $r['description'] ?? ''))) {
                $postTypeLabel = 'Link';
            }
            if ($postType !== 'all' && strcasecmp($postType, $postTypeLabel) !== 0) {
                continue;
            }

            $text = trim((string)($r['body'] ?? ''));
            if ($text === '') {
                $text = trim((string)($r['description'] ?? ''));
            }
            if ($text === '') {
                $text = trim((string)($r['title'] ?? ''));
            }

            $stInfo = posts_admin_status_from_row($r);
            $out[] = [
                'id' => $pid,
                'user_id' => (int)($r['user_id'] ?? 0),
                'username' => (string)($r['username'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'friend_code' => (string)($r['friend_code'] ?? ''),
                'email' => (string)($r['email'] ?? ''),
                'title' => trim((string)($r['title'] ?? '')),
                'text_preview' => $text,
                'post_type' => $postTypeLabel,
                'thumb' => $thumb,
                'video' => $video,
                'hero' => $hero !== '' ? $hero : ($thumb !== '' ? $thumb : $video),
                'attachments' => $atts,
                'visibility' => (string)($r['visibility'] ?? 'public'),
                'created_at' => (string)($r['created_at'] ?? ''),
                'updated_at' => (string)($r['updated_at'] ?? ''),
                'views_count' => (int)($r['views_count'] ?? 0),
                'report_count' => (int)($r['report_count'] ?? 0),
                'pending_count' => (int)($r['pending_count'] ?? 0),
                'is_deleted' => (int)($r['is_deleted'] ?? 0),
                'status_key' => $stInfo['key'],
                'status_label' => $stInfo['label'],
                'status_cls' => $stInfo['cls'],
            ];
        }

        if ($postType !== 'all') {
            $total = count($out);
            $out = array_slice($out, $offset, $perPage);
        }

        return ['rows' => $out, 'total' => $total];
    }
}

if (!function_exists('posts_admin_engagement')) {
    /** @return array{likes:int,comments:int,shares:int,saves:int} */
    function posts_admin_engagement(PDO $dbh, int $postId): array
    {
        $out = ['likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
        if ($postId <= 0) {
            return $out;
        }
        $out['likes'] = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_reactions WHERE post_id = :id', [':id' => $postId]);
        $out['comments'] = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_comments WHERE post_id = :id AND (is_deleted = 0 OR is_deleted IS NULL)', [':id' => $postId]);
        if (msb_mod_table_exists($dbh, 'public_post_shares')) {
            $out['shares'] = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_shares WHERE post_id = :id', [':id' => $postId]);
        }
        if (msb_mod_table_exists($dbh, 'public_post_saves')) {
            $out['saves'] = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_saves WHERE post_id = :id', [':id' => $postId]);
        } elseif (msb_mod_table_exists($dbh, 'public_saved_posts')) {
            $out['saves'] = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_saved_posts WHERE post_id = :id', [':id' => $postId]);
        } elseif (msb_mod_table_exists($dbh, 'public_post_bookmarks')) {
            $out['saves'] = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_bookmarks WHERE post_id = :id', [':id' => $postId]);
        }
        return $out;
    }
}
