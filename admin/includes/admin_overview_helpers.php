<?php
declare(strict_types=1);

/**
 * Helpers for admin/overview.php — live platform snapshot.
 */

if (!function_exists('admin_overview_h')) {
    function admin_overview_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_overview_table_exists')) {
    function admin_overview_table_exists(PDO $dbh, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
            $cache[$key] = $st && $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('admin_overview_count')) {
    function admin_overview_count(PDO $dbh, string $sql, array $params = []): int
    {
        try {
            $st = $dbh->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('admin_overview_delta_pct')) {
    function admin_overview_delta_pct(int $now, int $prev): int
    {
        if ($prev > 0) {
            return (int)round((($now - $prev) / $prev) * 100);
        }
        return $now > 0 ? 100 : 0;
    }
}

if (!function_exists('admin_overview_metric_bundle')) {
    /**
     * @return array{
     *   users:array{value:int,delta_pct:int},
     *   posts:array{value:int,delta_pct:int},
     *   comments:array{value:int,delta_pct:int},
     *   reports:array{value:int,delta_pct:int},
     *   suspended:array{value:int,delta_pct:int}
     * }
     */
    function admin_overview_metric_bundle(PDO $dbh): array
    {
        $z = ['value' => 0, 'delta_pct' => 0];
        $out = [
            'users' => $z,
            'posts' => $z,
            'comments' => $z,
            'reports' => $z,
            'suspended' => $z,
        ];

        if (admin_overview_table_exists($dbh, 'users')) {
            $total = admin_overview_count($dbh, 'SELECT COUNT(*) FROM users');
            $now7 = admin_overview_count($dbh, 'SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
            $prev7 = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM users
                WHERE created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
            ');
            $out['users'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];

            $susp = admin_overview_count($dbh, 'SELECT COUNT(*) FROM users WHERE status = 0');
            $susp7 = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM users
                WHERE status = 0 AND COALESCE(updated_at, created_at) >= (NOW() - INTERVAL 7 DAY)
            ');
            $suspPrev = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM users
                WHERE status = 0
                  AND COALESCE(updated_at, created_at) >= (NOW() - INTERVAL 14 DAY)
                  AND COALESCE(updated_at, created_at) < (NOW() - INTERVAL 7 DAY)
            ');
            $out['suspended'] = ['value' => $susp, 'delta_pct' => admin_overview_delta_pct($susp7, $suspPrev)];
        }

        if (admin_overview_table_exists($dbh, 'public_posts')) {
            $total = admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_posts');
            $now7 = admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_posts WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
            $prev7 = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_posts
                WHERE created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
            ');
            $out['posts'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        if (admin_overview_table_exists($dbh, 'public_post_comments')) {
            $total = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_comments
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
            ');
            $now7 = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_comments
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 7 DAY)
            ');
            $prev7 = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_comments
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 14 DAY)
                  AND created_at < (NOW() - INTERVAL 7 DAY)
            ');
            $out['comments'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        if (admin_overview_table_exists($dbh, 'public_user_reports')) {
            $total = admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_user_reports');
            $now7 = admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_user_reports WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
            $prev7 = admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_user_reports
                WHERE created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
            ');
            $out['reports'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        return $out;
    }
}

if (!function_exists('admin_overview_daily_series')) {
    /**
     * Daily counts for the last $days days (oldest → newest) and the previous equal window.
     *
     * @return array{labels:list<string>,this_week:list<int>,last_week:list<int>}
     */
    function admin_overview_daily_series(PDO $dbh, string $metric, int $days = 7): array
    {
        $days = max(3, min(30, $days));
        $labels = [];
        $thisWeek = [];
        $lastWeek = [];

        $tableSql = [
            'users' => ['users', '1=1'],
            'posts' => ['public_posts', '1=1'],
            'comments' => ['public_post_comments', '(is_deleted = 0 OR is_deleted IS NULL)'],
        ];
        if (!isset($tableSql[$metric])) {
            $metric = 'users';
        }
        [$table, $extra] = $tableSql[$metric];
        if (!admin_overview_table_exists($dbh, $table)) {
            for ($i = $days - 1; $i >= 0; $i--) {
                $ts = strtotime('-' . $i . ' day');
                $labels[] = $ts ? date('M j', $ts) : '';
                $thisWeek[] = 0;
                $lastWeek[] = 0;
            }
            return ['labels' => $labels, 'this_week' => $thisWeek, 'last_week' => $lastWeek];
        }

        for ($i = $days - 1; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' day');
            $day = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
            $labels[] = $ts ? date('M j', $ts) : $day;
            $thisWeek[] = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM {$table}
                WHERE {$extra} AND DATE(created_at) = :d
            ", [':d' => $day]);
            $prevTs = strtotime('-' . ($i + $days) . ' day');
            $prevDay = $prevTs ? date('Y-m-d', $prevTs) : $day;
            $lastWeek[] = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM {$table}
                WHERE {$extra} AND DATE(created_at) = :d
            ", [':d' => $prevDay]);
        }

        return ['labels' => $labels, 'this_week' => $thisWeek, 'last_week' => $lastWeek];
    }
}

if (!function_exists('admin_overview_engagement_bundle')) {
    /**
     * @return array{value:int,delta_pct:int,new_users:int,new_users_delta:int}
     */
    function admin_overview_engagement_bundle(PDO $dbh): array
    {
        $eng = 0;
        $eng7 = 0;
        $engPrev = 0;
        if (admin_overview_table_exists($dbh, 'public_post_reactions')) {
            $eng += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_reactions');
            $eng7 += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_reactions WHERE reacted_at >= (NOW() - INTERVAL 7 DAY)');
            $engPrev += admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_reactions
                WHERE reacted_at >= (NOW() - INTERVAL 14 DAY) AND reacted_at < (NOW() - INTERVAL 7 DAY)
            ');
        }
        if (admin_overview_table_exists($dbh, 'public_post_comments')) {
            $eng += admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_comments WHERE (is_deleted = 0 OR is_deleted IS NULL)
            ');
            $eng7 += admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_comments
                WHERE (is_deleted = 0 OR is_deleted IS NULL) AND created_at >= (NOW() - INTERVAL 7 DAY)
            ');
            $engPrev += admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_comments
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
            ');
        }
        if (admin_overview_table_exists($dbh, 'public_post_shares')) {
            $eng += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_shares');
            $eng7 += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_shares WHERE shared_at >= (NOW() - INTERVAL 7 DAY)');
            $engPrev += admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_shares
                WHERE shared_at >= (NOW() - INTERVAL 14 DAY) AND shared_at < (NOW() - INTERVAL 7 DAY)
            ');
        }
        if (admin_overview_table_exists($dbh, 'public_post_saves')) {
            $eng += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_saves');
            $eng7 += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_saves WHERE saved_at >= (NOW() - INTERVAL 7 DAY)');
            $engPrev += admin_overview_count($dbh, '
                SELECT COUNT(*) FROM public_post_saves
                WHERE saved_at >= (NOW() - INTERVAL 14 DAY) AND saved_at < (NOW() - INTERVAL 7 DAY)
            ');
        }

        $newUsers = admin_overview_count($dbh, 'SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
        $newPrev = admin_overview_count($dbh, '
            SELECT COUNT(*) FROM users
            WHERE created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ');

        return [
            'value' => $eng,
            'delta_pct' => admin_overview_delta_pct($eng7, $engPrev),
            'new_users' => $newUsers,
            'new_users_delta' => admin_overview_delta_pct($newUsers, $newPrev),
        ];
    }
}

if (!function_exists('admin_overview_multi_activity_series')) {
    /**
     * @return array{labels:list<string>,users:list<int>,posts:list<int>,comments:list<int>,engagements:list<int>}
     */
    function admin_overview_multi_activity_series(PDO $dbh, int $days = 7): array
    {
        $days = max(3, min(30, $days));
        $labels = [];
        $users = [];
        $posts = [];
        $comments = [];
        $engagements = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' day');
            $day = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
            $labels[] = $ts ? date('M j', $ts) : $day;
            $users[] = admin_overview_count($dbh, 'SELECT COUNT(*) FROM users WHERE DATE(created_at) = :d', [':d' => $day]);
            $posts[] = admin_overview_table_exists($dbh, 'public_posts')
                ? admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_posts WHERE DATE(created_at) = :d', [':d' => $day])
                : 0;
            $comments[] = admin_overview_table_exists($dbh, 'public_post_comments')
                ? admin_overview_count($dbh, '
                    SELECT COUNT(*) FROM public_post_comments
                    WHERE (is_deleted = 0 OR is_deleted IS NULL) AND DATE(created_at) = :d
                ', [':d' => $day])
                : 0;
            $eng = 0;
            if (admin_overview_table_exists($dbh, 'public_post_reactions')) {
                $eng += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_reactions WHERE DATE(reacted_at) = :d', [':d' => $day]);
            }
            if (admin_overview_table_exists($dbh, 'public_post_comments')) {
                $eng += admin_overview_count($dbh, '
                    SELECT COUNT(*) FROM public_post_comments
                    WHERE (is_deleted = 0 OR is_deleted IS NULL) AND DATE(created_at) = :d
                ', [':d' => $day]);
            }
            if (admin_overview_table_exists($dbh, 'public_post_shares')) {
                $eng += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_shares WHERE DATE(shared_at) = :d', [':d' => $day]);
            }
            if (admin_overview_table_exists($dbh, 'public_post_saves')) {
                $eng += admin_overview_count($dbh, 'SELECT COUNT(*) FROM public_post_saves WHERE DATE(saved_at) = :d', [':d' => $day]);
            }
            $engagements[] = $eng;
        }
        return [
            'labels' => $labels,
            'users' => $users,
            'posts' => $posts,
            'comments' => $comments,
            'engagements' => $engagements,
        ];
    }
}

if (!function_exists('admin_overview_content_status')) {
    /**
     * @param array<string,array{value?:int}> $postStats
     * @return list<array{label:string,count:int,pct:float,color:string}>
     */
    function admin_overview_content_status(array $postStats): array
    {
        $published = (int)($postStats['published']['value'] ?? 0);
        $pending = (int)($postStats['pending']['value'] ?? 0);
        $flagged = (int)($postStats['flagged']['value'] ?? 0);
        $removed = (int)($postStats['removed']['value'] ?? 0);
        $all = (int)($postStats['all']['value'] ?? 0);
        // Soft buckets with no dedicated columns yet
        $scheduled = 0;
        $drafts = max(0, $all - $published - $pending - $flagged - $removed);
        // Fold flagged into pending review for the donut legend
        $pendingReview = $pending + $flagged;

        $rows = [
            ['label' => 'Published', 'count' => $published, 'color' => '#16a34a'],
            ['label' => 'Pending Review', 'count' => $pendingReview, 'color' => '#f59e0b'],
            ['label' => 'Scheduled', 'count' => $scheduled, 'color' => '#3b82f6'],
            ['label' => 'Drafts', 'count' => $drafts, 'color' => '#8b5cf6'],
            ['label' => 'Removed', 'count' => $removed, 'color' => '#ef4444'],
        ];
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int)$r['count'];
        }
        foreach ($rows as &$r) {
            $r['pct'] = $sum > 0 ? round(((int)$r['count'] / $sum) * 100, 1) : 0.0;
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('admin_overview_donut_bg')) {
    /**
     * @param list<array{count:int,color:string}> $rows
     */
    function admin_overview_donut_bg(array $rows): string
    {
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int)($r['count'] ?? 0);
        }
        if ($sum <= 0) {
            return 'conic-gradient(#e2e8f0 0deg, #e2e8f0 360deg)';
        }
        $parts = [];
        $deg = 0.0;
        foreach ($rows as $r) {
            $slice = ((int)$r['count'] / $sum) * 360;
            $color = (string)$r['color'];
            $end = $deg + $slice;
            $parts[] = $color . ' ' . $deg . 'deg ' . $end . 'deg';
            $deg = $end;
        }
        return 'conic-gradient(' . implode(', ', $parts) . ')';
    }
}

if (!function_exists('admin_overview_relative_time')) {
    function admin_overview_relative_time(?string $dt): string
    {
        $dt = trim((string)$dt);
        if ($dt === '') {
            return '—';
        }
        $ts = strtotime($dt);
        if ($ts === false) {
            return $dt;
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int)floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (int)floor($diff / 3600) . 'h ago';
        }
        if ($diff < 86400 * 7) {
            return (int)floor($diff / 86400) . 'd ago';
        }
        return date('M j', $ts);
    }
}

if (!function_exists('admin_overview_reason_label')) {
    function admin_overview_reason_label(string $reason): string
    {
        $map = [
            'spam' => 'Spam',
            'harassment' => 'Harassment',
            'hate' => 'Hate speech',
            'violence' => 'Violence',
            'nudity' => 'Inappropriate content',
            'scam' => 'Scam',
            'fake_product' => 'Fake product',
            'copyright' => 'Copyright',
            'other' => 'Other',
        ];
        $reason = strtolower(trim($reason));
        return $map[$reason] ?? ucwords(str_replace('_', ' ', $reason));
    }
}

if (!function_exists('admin_overview_report_badge')) {
    /**
     * @return array{label:string,cls:string}
     */
    function admin_overview_report_badge(string $status): array
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'pending' => ['label' => 'New', 'cls' => 'new'],
            'reviewed' => ['label' => 'In Review', 'cls' => 'review'],
            'resolved' => ['label' => 'Resolved', 'cls' => 'resolved'],
            'dismissed' => ['label' => 'Dismissed', 'cls' => 'pending'],
            default => ['label' => ucfirst($status !== '' ? $status : 'Pending'), 'cls' => 'pending'],
        };
    }
}

if (!function_exists('admin_overview_top_users')) {
    /**
     * @return list<array{id:int,username:string,name:string,score:int}>
     */
    function admin_overview_top_users(PDO $dbh, int $limit = 5): array
    {
        if (!admin_overview_table_exists($dbh, 'public_posts') || !admin_overview_table_exists($dbh, 'users')) {
            return [];
        }
        try {
            $st = $dbh->prepare('
                SELECT u.id, u.username, u.name, COUNT(*) AS score
                FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE p.created_at >= (NOW() - INTERVAL 30 DAY)
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                GROUP BY u.id, u.username, u.name
                ORDER BY score DESC
                LIMIT ' . (int)$limit . '
            ');
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int)($r['id'] ?? 0),
                    'username' => (string)($r['username'] ?? ''),
                    'name' => (string)($r['name'] ?? ''),
                    'score' => (int)($r['score'] ?? 0),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('admin_overview_top_countries')) {
    /**
     * Soft estimate from publisher registration_country when available.
     *
     * @return list<array{label:string,count:int,pct:float,flag:string}>
     */
    function admin_overview_top_countries(PDO $dbh, int $limit = 5): array
    {
        if (!admin_overview_table_exists($dbh, 'publisher_name_authority')) {
            return [];
        }
        try {
            $st = $dbh->query("
                SELECT TRIM(registration_country) AS country, COUNT(*) AS c
                FROM publisher_name_authority
                WHERE registration_country IS NOT NULL AND TRIM(registration_country) <> ''
                GROUP BY TRIM(registration_country)
                ORDER BY c DESC
                LIMIT " . (int)$limit . "
            ");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int)($r['c'] ?? 0);
        }
        $flagMap = [
            'united states' => '🇺🇸', 'usa' => '🇺🇸', 'us' => '🇺🇸',
            'india' => '🇮🇳', 'brazil' => '🇧🇷', 'united kingdom' => '🇬🇧', 'uk' => '🇬🇧',
            'indonesia' => '🇮🇩', 'canada' => '🇨🇦', 'mexico' => '🇲🇽', 'nigeria' => '🇳🇬',
            'philippines' => '🇵🇭', 'germany' => '🇩🇪', 'france' => '🇫🇷', 'australia' => '🇦🇺',
        ];
        $out = [];
        foreach ($rows as $r) {
            $label = trim((string)($r['country'] ?? ''));
            if ($label === '') {
                continue;
            }
            $count = (int)($r['c'] ?? 0);
            $key = strtolower($label);
            $out[] = [
                'label' => $label,
                'count' => $count,
                'pct' => $sum > 0 ? round(($count / $sum) * 100, 1) : 0.0,
                'flag' => $flagMap[$key] ?? '🌐',
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_overview_normalize_kind')) {
    /** @return 'personal'|'publisher'|'commerce' */
    function admin_overview_normalize_kind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return in_array($kind, ['personal', 'publisher', 'commerce'], true) ? $kind : 'personal';
    }
}

if (!function_exists('admin_overview_user_kind_where')) {
    /**
     * SQL predicate matching userlist_row_kind() for alias $alias (default users columns without alias).
     */
    function admin_overview_user_kind_where(string $kind, string $alias = ''): string
    {
        $kind = admin_overview_normalize_kind($kind);
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

if (!function_exists('admin_overview_kind_counts')) {
    /**
     * @return array{personal:int,publisher:int,commerce:int}
     */
    function admin_overview_kind_counts(PDO $dbh): array
    {
        $out = ['personal' => 0, 'publisher' => 0, 'commerce' => 0];
        if (!admin_overview_table_exists($dbh, 'users')) {
            return $out;
        }
        foreach (['personal', 'publisher', 'commerce'] as $k) {
            $where = admin_overview_user_kind_where($k);
            $out[$k] = admin_overview_count($dbh, "SELECT COUNT(*) FROM users WHERE {$where}");
        }
        return $out;
    }
}

if (!function_exists('admin_overview_metric_bundle_for_kind')) {
    /**
     * @return array{
     *   users:array{value:int,delta_pct:int},
     *   posts:array{value:int,delta_pct:int},
     *   comments:array{value:int,delta_pct:int},
     *   reports:array{value:int,delta_pct:int},
     *   suspended:array{value:int,delta_pct:int}
     * }
     */
    function admin_overview_metric_bundle_for_kind(PDO $dbh, string $kind): array
    {
        $kind = admin_overview_normalize_kind($kind);
        $z = ['value' => 0, 'delta_pct' => 0];
        $out = [
            'users' => $z,
            'posts' => $z,
            'comments' => $z,
            'reports' => $z,
            'suspended' => $z,
        ];
        $uWhere = admin_overview_user_kind_where($kind);

        if (admin_overview_table_exists($dbh, 'users')) {
            $total = admin_overview_count($dbh, "SELECT COUNT(*) FROM users WHERE {$uWhere}");
            $now7 = admin_overview_count($dbh, "SELECT COUNT(*) FROM users WHERE {$uWhere} AND created_at >= (NOW() - INTERVAL 7 DAY)");
            $prev7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$uWhere}
                  AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $out['users'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        $authorWhere = admin_overview_user_kind_where($kind, 'u');
        if (admin_overview_table_exists($dbh, 'public_posts') && admin_overview_table_exists($dbh, 'users')) {
            $total = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$authorWhere}
            ");
            $now7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$authorWhere} AND p.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $prev7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$authorWhere}
                  AND p.created_at >= (NOW() - INTERVAL 14 DAY) AND p.created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $out['posts'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        if (admin_overview_table_exists($dbh, 'public_post_comments') && admin_overview_table_exists($dbh, 'users')) {
            $total = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE {$authorWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
            ");
            $now7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE {$authorWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
                  AND c.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $prev7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE {$authorWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
                  AND c.created_at >= (NOW() - INTERVAL 14 DAY) AND c.created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $out['comments'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        // Reports / suspended (Overview metric cards)
        if (admin_overview_table_exists($dbh, 'users')) {
            $susp = admin_overview_count($dbh, "SELECT COUNT(*) FROM users WHERE {$uWhere} AND status = 0");
            $susp7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$uWhere} AND status = 0
                  AND COALESCE(updated_at, created_at) >= (NOW() - INTERVAL 7 DAY)
            ");
            $suspPrev = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$uWhere} AND status = 0
                  AND COALESCE(updated_at, created_at) >= (NOW() - INTERVAL 14 DAY)
                  AND COALESCE(updated_at, created_at) < (NOW() - INTERVAL 7 DAY)
            ");
            $out['suspended'] = ['value' => $susp, 'delta_pct' => admin_overview_delta_pct($susp7, $suspPrev)];
        }
        if (admin_overview_table_exists($dbh, 'public_user_reports') && admin_overview_table_exists($dbh, 'users')) {
            $total = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_user_reports r
                LEFT JOIN users u ON u.id = r.target_user_id
                WHERE (
                    (r.target_user_id IS NOT NULL AND r.target_user_id > 0 AND {$authorWhere})
                    OR (
                        (r.target_user_id IS NULL OR r.target_user_id = 0)
                        AND " . ($kind === 'commerce'
                            ? "LOWER(TRIM(COALESCE(r.target_type,''))) IN ('org','product')"
                            : ($kind === 'publisher'
                                ? "1=0"
                                : "LOWER(TRIM(COALESCE(r.target_type,''))) NOT IN ('org','product')")) . "
                    )
                )
            ");
            $now7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_user_reports r
                LEFT JOIN users u ON u.id = r.target_user_id
                WHERE r.created_at >= (NOW() - INTERVAL 7 DAY)
                  AND (
                    (r.target_user_id IS NOT NULL AND r.target_user_id > 0 AND {$authorWhere})
                    OR (
                        (r.target_user_id IS NULL OR r.target_user_id = 0)
                        AND " . ($kind === 'commerce'
                            ? "LOWER(TRIM(COALESCE(r.target_type,''))) IN ('org','product')"
                            : ($kind === 'publisher'
                                ? "1=0"
                                : "LOWER(TRIM(COALESCE(r.target_type,''))) NOT IN ('org','product')")) . "
                    )
                  )
            ");
            $prev7 = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_user_reports r
                LEFT JOIN users u ON u.id = r.target_user_id
                WHERE r.created_at >= (NOW() - INTERVAL 14 DAY) AND r.created_at < (NOW() - INTERVAL 7 DAY)
                  AND (
                    (r.target_user_id IS NOT NULL AND r.target_user_id > 0 AND {$authorWhere})
                    OR (
                        (r.target_user_id IS NULL OR r.target_user_id = 0)
                        AND " . ($kind === 'commerce'
                            ? "LOWER(TRIM(COALESCE(r.target_type,''))) IN ('org','product')"
                            : ($kind === 'publisher'
                                ? "1=0"
                                : "LOWER(TRIM(COALESCE(r.target_type,''))) NOT IN ('org','product')")) . "
                    )
                  )
            ");
            $out['reports'] = ['value' => $total, 'delta_pct' => admin_overview_delta_pct($now7, $prev7)];
        }

        return $out;
    }
}

if (!function_exists('admin_overview_engagement_bundle_for_kind')) {
    /**
     * @return array{value:int,delta_pct:int,new_users:int,new_users_delta:int}
     */
    function admin_overview_engagement_bundle_for_kind(PDO $dbh, string $kind): array
    {
        $kind = admin_overview_normalize_kind($kind);
        $uWhere = admin_overview_user_kind_where($kind, 'u');
        $eng = 0;
        $eng7 = 0;
        $engPrev = 0;

        $actorJoinCount = static function (PDO $dbh, string $table, string $userCol, string $timeCol, string $extra, string $uWhere, ?string $since, ?string $until) : int {
            if (!admin_overview_table_exists($dbh, $table) || !admin_overview_table_exists($dbh, 'users')) {
                return 0;
            }
            $sql = "
                SELECT COUNT(*) FROM {$table} t
                INNER JOIN users u ON u.id = t.{$userCol}
                WHERE {$uWhere} {$extra}
            ";
            $params = [];
            if ($since !== null) {
                $sql .= " AND t.{$timeCol} >= (NOW() - INTERVAL {$since})";
            }
            if ($until !== null) {
                $sql .= " AND t.{$timeCol} < (NOW() - INTERVAL {$until})";
            }
            return admin_overview_count($dbh, $sql, $params);
        };

        $eng += $actorJoinCount($dbh, 'public_post_reactions', 'user_id', 'reacted_at', '', $uWhere, null, null);
        $eng7 += $actorJoinCount($dbh, 'public_post_reactions', 'user_id', 'reacted_at', '', $uWhere, '7 DAY', null);
        $engPrev += $actorJoinCount($dbh, 'public_post_reactions', 'user_id', 'reacted_at', '', $uWhere, '14 DAY', '7 DAY');

        $cExtra = 'AND (t.is_deleted = 0 OR t.is_deleted IS NULL)';
        $eng += $actorJoinCount($dbh, 'public_post_comments', 'user_id', 'created_at', $cExtra, $uWhere, null, null);
        $eng7 += $actorJoinCount($dbh, 'public_post_comments', 'user_id', 'created_at', $cExtra, $uWhere, '7 DAY', null);
        $engPrev += $actorJoinCount($dbh, 'public_post_comments', 'user_id', 'created_at', $cExtra, $uWhere, '14 DAY', '7 DAY');

        $eng += $actorJoinCount($dbh, 'public_post_shares', 'user_id', 'shared_at', '', $uWhere, null, null);
        $eng7 += $actorJoinCount($dbh, 'public_post_shares', 'user_id', 'shared_at', '', $uWhere, '7 DAY', null);
        $engPrev += $actorJoinCount($dbh, 'public_post_shares', 'user_id', 'shared_at', '', $uWhere, '14 DAY', '7 DAY');

        $eng += $actorJoinCount($dbh, 'public_post_saves', 'user_id', 'saved_at', '', $uWhere, null, null);
        $eng7 += $actorJoinCount($dbh, 'public_post_saves', 'user_id', 'saved_at', '', $uWhere, '7 DAY', null);
        $engPrev += $actorJoinCount($dbh, 'public_post_saves', 'user_id', 'saved_at', '', $uWhere, '14 DAY', '7 DAY');

        $plainWhere = admin_overview_user_kind_where($kind);
        $newUsers = admin_overview_count($dbh, "SELECT COUNT(*) FROM users WHERE {$plainWhere} AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $newPrev = admin_overview_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$plainWhere}
              AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ");

        return [
            'value' => $eng,
            'delta_pct' => admin_overview_delta_pct($eng7, $engPrev),
            'new_users' => $newUsers,
            'new_users_delta' => admin_overview_delta_pct($newUsers, $newPrev),
        ];
    }
}

if (!function_exists('admin_overview_multi_activity_series_for_kind')) {
    /**
     * @return array{labels:list<string>,users:list<int>,posts:list<int>,comments:list<int>,engagements:list<int>}
     */
    function admin_overview_multi_activity_series_for_kind(PDO $dbh, string $kind, int $days = 7): array
    {
        $kind = admin_overview_normalize_kind($kind);
        $days = max(3, min(30, $days));
        $uWhere = admin_overview_user_kind_where($kind);
        $aWhere = admin_overview_user_kind_where($kind, 'u');
        $labels = [];
        $users = [];
        $posts = [];
        $comments = [];
        $engagements = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' day');
            $day = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
            $labels[] = $ts ? date('M j', $ts) : $day;
            $users[] = admin_overview_count($dbh, "SELECT COUNT(*) FROM users WHERE {$uWhere} AND DATE(created_at) = :d", [':d' => $day]);
            $posts[] = (admin_overview_table_exists($dbh, 'public_posts') && admin_overview_table_exists($dbh, 'users'))
                ? admin_overview_count($dbh, "
                    SELECT COUNT(*) FROM public_posts p
                    INNER JOIN users u ON u.id = p.user_id
                    WHERE {$aWhere} AND DATE(p.created_at) = :d
                ", [':d' => $day])
                : 0;
            $comments[] = (admin_overview_table_exists($dbh, 'public_post_comments') && admin_overview_table_exists($dbh, 'users'))
                ? admin_overview_count($dbh, "
                    SELECT COUNT(*) FROM public_post_comments c
                    INNER JOIN users u ON u.id = c.user_id
                    WHERE {$aWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL) AND DATE(c.created_at) = :d
                ", [':d' => $day])
                : 0;
            $eng = 0;
            if (admin_overview_table_exists($dbh, 'public_post_reactions')) {
                $eng += admin_overview_count($dbh, "
                    SELECT COUNT(*) FROM public_post_reactions t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND DATE(t.reacted_at) = :d
                ", [':d' => $day]);
            }
            if (admin_overview_table_exists($dbh, 'public_post_comments')) {
                $eng += admin_overview_count($dbh, "
                    SELECT COUNT(*) FROM public_post_comments t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND (t.is_deleted = 0 OR t.is_deleted IS NULL) AND DATE(t.created_at) = :d
                ", [':d' => $day]);
            }
            if (admin_overview_table_exists($dbh, 'public_post_shares')) {
                $eng += admin_overview_count($dbh, "
                    SELECT COUNT(*) FROM public_post_shares t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND DATE(t.shared_at) = :d
                ", [':d' => $day]);
            }
            if (admin_overview_table_exists($dbh, 'public_post_saves')) {
                $eng += admin_overview_count($dbh, "
                    SELECT COUNT(*) FROM public_post_saves t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND DATE(t.saved_at) = :d
                ", [':d' => $day]);
            }
            $engagements[] = $eng;
        }

        return [
            'labels' => $labels,
            'users' => $users,
            'posts' => $posts,
            'comments' => $comments,
            'engagements' => $engagements,
        ];
    }
}

if (!function_exists('admin_overview_post_stats_for_kind')) {
    /**
     * @return array{all:array{value:int},pending:array{value:int},published:array{value:int},flagged:array{value:int},removed:array{value:int}}
     */
    function admin_overview_post_stats_for_kind(PDO $dbh, string $kind): array
    {
        $kind = admin_overview_normalize_kind($kind);
        $z = ['value' => 0];
        $out = ['all' => $z, 'pending' => $z, 'published' => $z, 'flagged' => $z, 'removed' => $z];
        if (!admin_overview_table_exists($dbh, 'public_posts') || !admin_overview_table_exists($dbh, 'users')) {
            return $out;
        }
        $aWhere = admin_overview_user_kind_where($kind, 'u');
        $all = admin_overview_count($dbh, "
            SELECT COUNT(*) FROM public_posts p
            INNER JOIN users u ON u.id = p.user_id
            WHERE {$aWhere}
        ");
        $removed = admin_overview_count($dbh, "
            SELECT COUNT(*) FROM public_posts p
            INNER JOIN users u ON u.id = p.user_id
            WHERE {$aWhere} AND p.is_deleted = 1
        ");
        $published = admin_overview_count($dbh, "
            SELECT COUNT(*) FROM public_posts p
            INNER JOIN users u ON u.id = p.user_id
            WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
        ");
        $pending = 0;
        $flagged = 0;
        if (admin_overview_table_exists($dbh, 'public_user_reports')) {
            $pending = admin_overview_count($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id AND r.status = 'pending'
                WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
            ");
            $flagged = admin_overview_count($dbh, "
                SELECT COUNT(DISTINCT p.id)
                FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                INNER JOIN public_user_reports r ON r.target_type = 'post' AND r.target_id = p.id
                WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND NOT EXISTS (
                    SELECT 1 FROM public_user_reports r2
                    WHERE r2.target_type = 'post' AND r2.target_id = p.id AND r2.status = 'pending'
                  )
            ");
            $published = admin_overview_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND NOT EXISTS (
                    SELECT 1 FROM public_user_reports r
                    WHERE r.target_type = 'post' AND r.target_id = p.id
                  )
            ");
        }
        return [
            'all' => ['value' => $all],
            'pending' => ['value' => $pending],
            'published' => ['value' => $published],
            'flagged' => ['value' => $flagged],
            'removed' => ['value' => $removed],
        ];
    }
}

if (!function_exists('admin_overview_top_users_for_kind')) {
    /**
     * @return list<array{id:int,username:string,name:string,score:int}>
     */
    function admin_overview_top_users_for_kind(PDO $dbh, string $kind, int $limit = 5): array
    {
        $kind = admin_overview_normalize_kind($kind);
        if (!admin_overview_table_exists($dbh, 'public_posts') || !admin_overview_table_exists($dbh, 'users')) {
            return [];
        }
        $aWhere = admin_overview_user_kind_where($kind, 'u');
        try {
            $st = $dbh->prepare("
                SELECT u.id, u.username, u.name, COUNT(*) AS score
                FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere}
                  AND p.created_at >= (NOW() - INTERVAL 30 DAY)
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                GROUP BY u.id, u.username, u.name
                ORDER BY score DESC
                LIMIT " . (int)$limit . '
            ');
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int)($r['id'] ?? 0),
                    'username' => (string)($r['username'] ?? ''),
                    'name' => (string)($r['name'] ?? ''),
                    'score' => (int)($r['score'] ?? 0),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('admin_overview_kind_profile')) {
    /**
     * Copy + admin actions that explain each audience behavior.
     *
     * @return array{
     *   label:string,
     *   short:string,
     *   blurb:string,
     *   focus:list<string>,
     *   user_label:string,
     *   new_label:string,
     *   chart_user_label:string,
     *   quick:list<array{0:string,1:string,2:string}>,
     *   list_href:string,
     *   add_href:string
     * }
     */
    function admin_overview_kind_profile(string $kind): array
    {
        $kind = admin_overview_normalize_kind($kind);
        if ($kind === 'publisher') {
            return [
                'label' => 'Publisher',
                'short' => 'Content brands & newsrooms',
                'blurb' => 'Publishers run branded profiles, name authority requests, and public content feeds. Admin work is mostly approvals, post moderation, and publisher account status.',
                'focus' => [
                    'Review publisher name / authority requests',
                    'Moderate publisher posts and flagged content',
                    'Manage publisher user accounts and staff orgs',
                    'Watch new publisher signups and category mix',
                ],
                'user_label' => 'Publishers',
                'new_label' => 'New Publishers',
                'chart_user_label' => 'Publishers',
                'quick' => [
                    ['Publisher Requests', 'publisher_requests.php?status=pending', 'fa-hourglass-half'],
                    ['Publisher Users', 'userlist.php?kind=publisher', 'fa-bullhorn'],
                    ['Moderate Posts', 'posts.php', 'fa-file-text-o'],
                    ['Add Publisher', 'user_form.php?account_kind=publisher', 'fa-user-plus'],
                ],
                'list_href' => 'userlist.php?kind=publisher',
                'add_href' => 'user_form.php?account_kind=publisher',
            ];
        }
        if ($kind === 'commerce') {
            return [
                'label' => 'Commerce',
                'short' => 'Sellers & shop brands',
                'blurb' => 'Commerce accounts are sellers: brands, listings, org shops, payouts, and disputes. Admin work focuses on brand linking, rent/Stripe, and seller account health.',
                'focus' => [
                    'Approve seller / commerce brand requests',
                    'Link orgs to commerce brands',
                    'Watch shop rent, Stripe Connect, and disputes',
                    'Manage seller users and blocked shops',
                ],
                'user_label' => 'Sellers',
                'new_label' => 'New Sellers',
                'chart_user_label' => 'Sellers',
                'quick' => [
                    ['Seller Requests', 'publisher_requests.php?status=pending', 'fa-shopping-bag'],
                    ['Seller Users', 'userlist.php?kind=commerce', 'fa-tags'],
                    ['Commerce Brands', 'org_commerce_brands.php', 'fa-link'],
                    ['Disputes', 'dispute.php', 'fa-gavel'],
                ],
                'list_href' => 'userlist.php?kind=commerce',
                'add_href' => 'user_form.php?account_kind=publisher&publisher_category=commerce',
            ];
        }
        return [
            'label' => 'Personal',
            'short' => 'Everyday members',
            'blurb' => 'Personal users are social members: profiles, friends, comments, and reports. Admin work is account support, safety reports, and login/device activity.',
            'focus' => [
                'Handle user reports and safety flags',
                'Support account search, login, and device issues',
                'Review personal posts and comments',
                'Add or suspend personal accounts when needed',
            ],
            'user_label' => 'Personal Users',
            'new_label' => 'New Users',
            'chart_user_label' => 'Users',
            'quick' => [
                ['Personal Users', 'userlist.php?kind=personal', 'fa-users'],
                ['Reported Content', 'reports.php?status=pending', 'fa-flag'],
                ['Login Activity', 'login_activity.php', 'fa-sign-in'],
                ['Add Personal User', 'user_form.php?account_kind=personal', 'fa-user-plus'],
            ],
            'list_href' => 'userlist.php?kind=personal',
            'add_href' => 'user_form.php?account_kind=personal',
        ];
    }
}
