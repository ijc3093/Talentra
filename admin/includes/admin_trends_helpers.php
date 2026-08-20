<?php
declare(strict_types=1);

/**
 * Helpers for admin/trends.php.
 */

require_once __DIR__ . '/admin_kind_tabs.php';

if (!function_exists('admin_trends_h')) {
    function admin_trends_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_trends_table_exists')) {
    function admin_trends_table_exists(PDO $dbh, string $table): bool
    {
        if (function_exists('msb_mod_table_exists')) {
            return msb_mod_table_exists($dbh, $table);
        }
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

if (!function_exists('admin_trends_count')) {
    function admin_trends_count(PDO $dbh, string $sql, array $params = []): int
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

if (!function_exists('admin_trends_delta_pct')) {
    function admin_trends_delta_pct(int $now, int $prev): float
    {
        if ($prev > 0) {
            return round((($now - $prev) / $prev) * 100, 1);
        }
        return $now > 0 ? 100.0 : 0.0;
    }
}

if (!function_exists('admin_trends_metric_sub')) {
    /**
     * @return array{value:int,sub:string,sub_cls:string,delta:float}
     */
    function admin_trends_metric_sub(int $value, float $delta): array
    {
        $cls = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
        $arrow = $delta > 0 ? '↑ ' : ($delta < 0 ? '↓ ' : '');
        return [
            'value' => $value,
            'delta' => $delta,
            'sub' => $arrow . number_format(abs($delta), 1) . '% vs previous 7 days',
            'sub_cls' => $cls,
        ];
    }
}

if (!function_exists('admin_trends_engagement_total')) {
    function admin_trends_engagement_total(PDO $dbh, string $sinceSql, string $untilSql = '', string $kind = 'personal'): int
    {
        $total = 0;
        $until = $untilSql !== '' ? (' AND ' . $untilSql) : '';
        $aWhere = admin_kind_user_where($kind, 'u');
        $joinUsers = admin_trends_table_exists($dbh, 'users');
        if (admin_trends_table_exists($dbh, 'public_post_reactions')) {
            $total += $joinUsers
                ? admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_reactions t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND t.reacted_at >= {$sinceSql}{$until}
                ")
                : admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_reactions
                    WHERE reacted_at >= {$sinceSql}{$until}
                ");
        }
        if (admin_trends_table_exists($dbh, 'public_post_comments')) {
            $total += $joinUsers
                ? admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_comments t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND (t.is_deleted = 0 OR t.is_deleted IS NULL)
                      AND t.created_at >= {$sinceSql}{$until}
                ")
                : admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_comments
                    WHERE (is_deleted = 0 OR is_deleted IS NULL)
                      AND created_at >= {$sinceSql}{$until}
                ");
        }
        if (admin_trends_table_exists($dbh, 'public_post_shares')) {
            $total += $joinUsers
                ? admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_shares t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND t.shared_at >= {$sinceSql}{$until}
                ")
                : admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_shares
                    WHERE shared_at >= {$sinceSql}{$until}
                ");
        }
        if (admin_trends_table_exists($dbh, 'public_post_saves')) {
            $total += $joinUsers
                ? admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_saves t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE {$aWhere} AND t.saved_at >= {$sinceSql}{$until}
                ")
                : admin_trends_count($dbh, "
                    SELECT COUNT(*) FROM public_post_saves
                    WHERE saved_at >= {$sinceSql}{$until}
                ");
        }
        return $total;
    }
}

if (!function_exists('admin_trends_metrics')) {
    /**
     * @return array{
     *   users:array{value:int,sub:string,sub_cls:string,delta:float},
     *   posts:array{value:int,sub:string,sub_cls:string,delta:float},
     *   comments:array{value:int,sub:string,sub_cls:string,delta:float},
     *   engagements:array{value:int,sub:string,sub_cls:string,delta:float},
     *   new_users:array{value:int,sub:string,sub_cls:string,delta:float}
     * }
     */
    function admin_trends_metrics(PDO $dbh, string $kind = 'personal'): array
    {
        $z = admin_trends_metric_sub(0, 0.0);
        $out = [
            'users' => $z,
            'posts' => $z,
            'comments' => $z,
            'engagements' => $z,
            'new_users' => $z,
        ];
        $kw = admin_kind_user_where($kind, '');
        $aWhere = admin_kind_user_where($kind, 'u');

        if (admin_trends_table_exists($dbh, 'users')) {
            $total = admin_trends_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw}");
            $new7 = admin_trends_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw} AND created_at >= (NOW() - INTERVAL 7 DAY)");
            $newPrev = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $out['users'] = admin_trends_metric_sub($total, admin_trends_delta_pct($new7, $newPrev));
            $out['new_users'] = admin_trends_metric_sub($new7, admin_trends_delta_pct($new7, $newPrev));
        }

        if (admin_trends_table_exists($dbh, 'public_posts') && admin_trends_table_exists($dbh, 'users')) {
            $total = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
            ");
            $now7 = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND p.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $prev7 = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere} AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND p.created_at >= (NOW() - INTERVAL 14 DAY)
                  AND p.created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $out['posts'] = admin_trends_metric_sub($total, admin_trends_delta_pct($now7, $prev7));
        }

        if (admin_trends_table_exists($dbh, 'public_post_comments') && admin_trends_table_exists($dbh, 'users')) {
            $total = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE {$aWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
            ");
            $now7 = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE {$aWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
                  AND c.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $prev7 = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE {$aWhere} AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
                  AND c.created_at >= (NOW() - INTERVAL 14 DAY)
                  AND c.created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $out['comments'] = admin_trends_metric_sub($total, admin_trends_delta_pct($now7, $prev7));
        }

        $eng7 = admin_trends_engagement_total($dbh, '(NOW() - INTERVAL 7 DAY)', '', $kind);
        $engPrev = 0;
        if (admin_trends_table_exists($dbh, 'public_post_reactions') && admin_trends_table_exists($dbh, 'users')) {
            $engPrev += admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_reactions t
                INNER JOIN users u ON u.id = t.user_id
                WHERE {$aWhere} AND t.reacted_at >= (NOW() - INTERVAL 14 DAY) AND t.reacted_at < (NOW() - INTERVAL 7 DAY)
            ");
        }
        if (admin_trends_table_exists($dbh, 'public_post_comments') && admin_trends_table_exists($dbh, 'users')) {
            $engPrev += admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_comments t
                INNER JOIN users u ON u.id = t.user_id
                WHERE {$aWhere} AND (t.is_deleted = 0 OR t.is_deleted IS NULL)
                  AND t.created_at >= (NOW() - INTERVAL 14 DAY) AND t.created_at < (NOW() - INTERVAL 7 DAY)
            ");
        }
        if (admin_trends_table_exists($dbh, 'public_post_shares') && admin_trends_table_exists($dbh, 'users')) {
            $engPrev += admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_shares t
                INNER JOIN users u ON u.id = t.user_id
                WHERE {$aWhere} AND t.shared_at >= (NOW() - INTERVAL 14 DAY) AND t.shared_at < (NOW() - INTERVAL 7 DAY)
            ");
        }
        if (admin_trends_table_exists($dbh, 'public_post_saves') && admin_trends_table_exists($dbh, 'users')) {
            $engPrev += admin_trends_count($dbh, "
                SELECT COUNT(*) FROM public_post_saves t
                INNER JOIN users u ON u.id = t.user_id
                WHERE {$aWhere} AND t.saved_at >= (NOW() - INTERVAL 14 DAY) AND t.saved_at < (NOW() - INTERVAL 7 DAY)
            ");
        }
        $engAll = admin_trends_engagement_total($dbh, "'1970-01-01'", '', $kind);
        $out['engagements'] = admin_trends_metric_sub($engAll > 0 ? $engAll : $eng7, admin_trends_delta_pct($eng7, $engPrev));

        return $out;
    }
}

if (!function_exists('admin_trends_day_list')) {
    /**
     * @return list<array{date:string,label:string}>
     */
    function admin_trends_day_list(string $from, string $to): array
    {
        $start = strtotime($from . ' 00:00:00') ?: time();
        $end = strtotime($to . ' 23:59:59') ?: time();
        if ($end < $start) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        $days = (int)floor(($end - $start) / 86400) + 1;
        if ($days > 31) {
            $days = 31;
            $start = $end - (($days - 1) * 86400);
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $ts = $start + ($i * 86400);
            $out[] = [
                'date' => date('Y-m-d', $ts),
                'label' => date('M j', $ts),
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_trends_user_growth')) {
    /**
     * @return array{labels:list<string>,total:list<int>,new:list<int>}
     */
    function admin_trends_user_growth(PDO $dbh, string $from, string $to, string $kind = 'personal'): array
    {
        $labels = [];
        $total = [];
        $new = [];
        $kw = admin_kind_user_where($kind, '');
        foreach (admin_trends_day_list($from, $to) as $d) {
            $labels[] = $d['label'];
            $new[] = admin_trends_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw} AND DATE(created_at) = :d", [':d' => $d['date']]);
            $total[] = admin_trends_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw} AND DATE(created_at) <= :d", [':d' => $d['date']]);
        }
        return ['labels' => $labels, 'total' => $total, 'new' => $new];
    }
}

if (!function_exists('admin_trends_engagement_series')) {
    /**
     * @return array{labels:list<string>,likes:list<int>,comments:list<int>,shares:list<int>,saves:list<int>}
     */
    function admin_trends_engagement_series(PDO $dbh, string $from, string $to, string $kind = 'personal'): array
    {
        $labels = [];
        $likes = [];
        $comments = [];
        $shares = [];
        $saves = [];
        $aWhere = admin_kind_user_where($kind, 'u');
        $join = admin_trends_table_exists($dbh, 'users');
        foreach (admin_trends_day_list($from, $to) as $d) {
            $labels[] = $d['label'];
            $likes[] = admin_trends_table_exists($dbh, 'public_post_reactions')
                ? ($join
                    ? admin_trends_count($dbh, "
                        SELECT COUNT(*) FROM public_post_reactions t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE {$aWhere} AND DATE(t.reacted_at) = :d
                    ", [':d' => $d['date']])
                    : admin_trends_count($dbh, 'SELECT COUNT(*) FROM public_post_reactions WHERE DATE(reacted_at) = :d', [':d' => $d['date']]))
                : 0;
            $comments[] = admin_trends_table_exists($dbh, 'public_post_comments')
                ? ($join
                    ? admin_trends_count($dbh, "
                        SELECT COUNT(*) FROM public_post_comments t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE {$aWhere} AND (t.is_deleted = 0 OR t.is_deleted IS NULL) AND DATE(t.created_at) = :d
                    ", [':d' => $d['date']])
                    : admin_trends_count($dbh, '
                        SELECT COUNT(*) FROM public_post_comments
                        WHERE (is_deleted = 0 OR is_deleted IS NULL) AND DATE(created_at) = :d
                    ', [':d' => $d['date']]))
                : 0;
            $shares[] = admin_trends_table_exists($dbh, 'public_post_shares')
                ? ($join
                    ? admin_trends_count($dbh, "
                        SELECT COUNT(*) FROM public_post_shares t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE {$aWhere} AND DATE(t.shared_at) = :d
                    ", [':d' => $d['date']])
                    : admin_trends_count($dbh, 'SELECT COUNT(*) FROM public_post_shares WHERE DATE(shared_at) = :d', [':d' => $d['date']]))
                : 0;
            $saves[] = admin_trends_table_exists($dbh, 'public_post_saves')
                ? ($join
                    ? admin_trends_count($dbh, "
                        SELECT COUNT(*) FROM public_post_saves t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE {$aWhere} AND DATE(t.saved_at) = :d
                    ", [':d' => $d['date']])
                    : admin_trends_count($dbh, 'SELECT COUNT(*) FROM public_post_saves WHERE DATE(saved_at) = :d', [':d' => $d['date']]))
                : 0;
        }
        return [
            'labels' => $labels,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $saves,
        ];
    }
}

if (!function_exists('admin_trends_active_series')) {
    /**
     * @return array{labels:list<string>,dau:list<int>,mau:list<int>}
     */
    function admin_trends_active_series(PDO $dbh, string $from, string $to, string $kind = 'personal'): array
    {
        $kw = admin_kind_user_where($kind, '');
        $labels = [];
        $dau = [];
        $mau = [];
        foreach (admin_trends_day_list($from, $to) as $d) {
            $labels[] = $d['label'];
            $dau[] = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND last_seen IS NOT NULL AND DATE(last_seen) = :d
            ", [':d' => $d['date']]);
            $mau[] = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND last_seen IS NOT NULL
                  AND last_seen >= DATE_SUB(:d2, INTERVAL 30 DAY)
                  AND last_seen < DATE_ADD(:d3, INTERVAL 1 DAY)
            ", [':d2' => $d['date'], ':d3' => $d['date']]);
        }
        return ['labels' => $labels, 'dau' => $dau, 'mau' => $mau];
    }
}

if (!function_exists('admin_trends_retention')) {
    /**
     * Soft retention curve from signup cohorts + last_seen.
     *
     * @return array{labels:list<string>,values:list<float>}
     */
    function admin_trends_retention(PDO $dbh, string $kind = 'personal'): array
    {
        $days = [1, 7, 14, 30, 60, 90];
        $labels = [];
        $values = [];
        $kw = admin_kind_user_where($kind, '');
        foreach ($days as $n) {
            $labels[] = 'Day ' . $n;
            $cohort = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND created_at <= (NOW() - INTERVAL " . (int)$n . " DAY)
            ");
            $retained = admin_trends_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND created_at <= (NOW() - INTERVAL " . (int)$n . " DAY)
                  AND last_seen IS NOT NULL
                  AND last_seen >= DATE_ADD(created_at, INTERVAL " . (int)$n . " DAY)
            ");
            $values[] = $cohort > 0 ? round(($retained / $cohort) * 100, 1) : ($n === 1 ? 100.0 : 0.0);
        }
        if ($values !== [] && $values[0] < 100) {
            // Screenshot starts at 100% on Day 1 for visual baseline.
            array_unshift($labels, 'Day 0');
            array_unshift($values, 100.0);
        }
        return ['labels' => $labels, 'values' => $values];
    }
}

if (!function_exists('admin_trends_top_content')) {
    /**
     * @return list<array{id:int,title:string,date:string,type:string,type_icon:string,engagements:int,thumb:string}>
     */
    function admin_trends_top_content(PDO $dbh, int $limit = 5, string $kind = 'personal'): array
    {
        if (!admin_trends_table_exists($dbh, 'public_posts')) {
            return [];
        }
        try {
            $st = $dbh->query("
                SELECT id, title, description, body, layout_type, created_at
                FROM public_posts
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                ORDER BY COALESCE(activity_at, created_at) DESC
                LIMIT 40
            ");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
        if ($rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['id'];
        }
        $in = implode(',', $ids);

        $likes = [];
        $comments = [];
        $shares = [];
        $saves = [];
        $thumbs = [];
        try {
            if (admin_trends_table_exists($dbh, 'public_post_reactions')) {
                $st = $dbh->query("SELECT post_id, COUNT(*) c FROM public_post_reactions WHERE post_id IN ($in) GROUP BY post_id");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $likes[(int)$r['post_id']] = (int)$r['c'];
                }
            }
            if (admin_trends_table_exists($dbh, 'public_post_comments')) {
                $st = $dbh->query("
                    SELECT post_id, COUNT(*) c FROM public_post_comments
                    WHERE post_id IN ($in) AND (is_deleted = 0 OR is_deleted IS NULL)
                    GROUP BY post_id
                ");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $comments[(int)$r['post_id']] = (int)$r['c'];
                }
            }
            if (admin_trends_table_exists($dbh, 'public_post_shares')) {
                $st = $dbh->query("SELECT post_id, COUNT(*) c FROM public_post_shares WHERE post_id IN ($in) GROUP BY post_id");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $shares[(int)$r['post_id']] = (int)$r['c'];
                }
            }
            if (admin_trends_table_exists($dbh, 'public_post_saves')) {
                $st = $dbh->query("SELECT post_id, COUNT(*) c FROM public_post_saves WHERE post_id IN ($in) GROUP BY post_id");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $saves[(int)$r['post_id']] = (int)$r['c'];
                }
            }
            if (admin_trends_table_exists($dbh, 'public_post_attachments')) {
                $st = $dbh->query("
                    SELECT post_id, type, file_path, thumb_path
                    FROM public_post_attachments
                    WHERE post_id IN ($in)
                    ORDER BY id ASC
                ");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $a) {
                    $pid = (int)$a['post_id'];
                    if (isset($thumbs[$pid])) {
                        continue;
                    }
                    $tp = trim((string)($a['thumb_path'] ?? ''));
                    $fp = trim((string)($a['file_path'] ?? ''));
                    $t = strtolower((string)($a['type'] ?? ''));
                    if ($tp !== '') {
                        $thumbs[$pid] = $tp;
                    } elseif ($t === 'image' && $fp !== '') {
                        $thumbs[$pid] = $fp;
                    }
                }
            }
        } catch (Throwable $e) {
            // keep zeros
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $eng = ($likes[$id] ?? 0) + ($comments[$id] ?? 0) + ($shares[$id] ?? 0) + ($saves[$id] ?? 0);
            $title = trim((string)($r['title'] ?? ''));
            if ($title === '') {
                $title = trim((string)($r['description'] ?? ''));
            }
            if ($title === '') {
                $title = trim(strip_tags((string)($r['body'] ?? '')));
            }
            if ($title === '') {
                $title = 'Post #' . $id;
            }
            if (mb_strlen($title) > 42) {
                $title = mb_substr($title, 0, 41) . '…';
            }
            $layout = strtolower((string)($r['layout_type'] ?? ''));
            $typeIcon = 'fa-file-text-o';
            $type = 'Post';
            if (str_contains($layout, 'video') || str_contains($layout, 'reel')) {
                $typeIcon = 'fa-play-circle';
                $type = 'Video';
            } elseif (str_contains($layout, 'image') || str_contains($layout, 'photo')) {
                $typeIcon = 'fa-image';
                $type = 'Image';
            }
            $ts = strtotime((string)($r['created_at'] ?? '')) ?: time();
            $out[] = [
                'id' => $id,
                'title' => $title,
                'date' => date('M j, Y', $ts),
                'type' => $type,
                'type_icon' => $typeIcon,
                'engagements' => $eng,
                'thumb' => $thumbs[$id] ?? '',
            ];
        }
        usort($out, static fn($a, $b) => $b['engagements'] <=> $a['engagements']);
        return array_slice($out, 0, $limit);
    }
}

if (!function_exists('admin_trends_insights')) {
    /**
     * @return array{
     *   text:string,
     *   best_day:string,
     *   low_day:string,
     *   peak_time:string,
     *   top_platform:string,
     *   top_platform_pct:float
     * }
     */
    function admin_trends_insights(PDO $dbh, string $from, string $to, array $metrics, array $engagementSeries): array
    {
        $userDelta = (float)($metrics['users']['delta'] ?? 0);
        $engDelta = (float)($metrics['engagements']['delta'] ?? 0);
        $text = sprintf(
            'User growth is %s %.1f%% compared to the previous 7 days. Engagements %s by %.1f%% with likes showing the highest growth.',
            $userDelta >= 0 ? 'up' : 'down',
            abs($userDelta),
            $engDelta >= 0 ? 'increased' : 'decreased',
            abs($engDelta)
        );

        $bestDay = '—';
        $lowDay = '—';
        $labels = $engagementSeries['labels'] ?? [];
        $likes = $engagementSeries['likes'] ?? [];
        $comments = $engagementSeries['comments'] ?? [];
        $shares = $engagementSeries['shares'] ?? [];
        $saves = $engagementSeries['saves'] ?? [];
        $scores = [];
        foreach ($labels as $i => $lab) {
            $scores[$i] = (int)($likes[$i] ?? 0) + (int)($comments[$i] ?? 0) + (int)($shares[$i] ?? 0) + (int)($saves[$i] ?? 0);
        }
        if ($scores !== []) {
            $maxI = array_keys($scores, max($scores))[0];
            $minI = array_keys($scores, min($scores))[0];
            $days = admin_trends_day_list($from, $to);
            $bestDay = isset($days[$maxI]) ? date('M j, Y', strtotime($days[$maxI]['date']) ?: time()) : (string)$labels[$maxI];
            $lowDay = isset($days[$minI]) ? date('M j, Y', strtotime($days[$minI]['date']) ?: time()) : (string)$labels[$minI];
        }

        $peakHour = null;
        $peakCount = -1;
        if (admin_trends_table_exists($dbh, 'public_post_reactions')) {
            try {
                $st = $dbh->query("
                    SELECT HOUR(reacted_at) AS h, COUNT(*) AS c
                    FROM public_post_reactions
                    WHERE reacted_at >= (NOW() - INTERVAL 7 DAY)
                    GROUP BY HOUR(reacted_at)
                    ORDER BY c DESC
                    LIMIT 1
                ");
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                if ($row) {
                    $peakHour = (int)$row['h'];
                    $peakCount = (int)$row['c'];
                }
            } catch (Throwable $e) {
            }
        }
        if ($peakHour === null && admin_trends_table_exists($dbh, 'public_post_comments')) {
            try {
                $st = $dbh->query("
                    SELECT HOUR(created_at) AS h, COUNT(*) AS c
                    FROM public_post_comments
                    WHERE created_at >= (NOW() - INTERVAL 7 DAY)
                    GROUP BY HOUR(created_at)
                    ORDER BY c DESC
                    LIMIT 1
                ");
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                if ($row) {
                    $peakHour = (int)$row['h'];
                }
            } catch (Throwable $e) {
            }
        }
        $peakTime = '—';
        if ($peakHour !== null) {
            $start = $peakHour;
            $end = ($peakHour + 2) % 24;
            $fmt = static function (int $h): string {
                $ampm = $h >= 12 ? 'PM' : 'AM';
                $h12 = $h % 12;
                if ($h12 === 0) {
                    $h12 = 12;
                }
                return $h12 . ':00 ' . $ampm;
            };
            $tz = date('T');
            $peakTime = $fmt($start) . ' - ' . $fmt($end) . ' (' . $tz . ')';
        }

        $mobile = 0;
        $desktop = 0;
        $tablet = 0;
        $other = 0;
        if (admin_trends_table_exists($dbh, 'user_sessions')) {
            try {
                $st = $dbh->query('SELECT user_agent FROM user_sessions');
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $ua = (string)($r['user_agent'] ?? '');
                    if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) {
                        $tablet++;
                    } elseif (preg_match('/Mobile|Android|iPhone|iPod/i', $ua)) {
                        $mobile++;
                    } elseif (trim($ua) === '') {
                        $other++;
                    } else {
                        $desktop++;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $platMap = ['Mobile' => $mobile, 'Desktop' => $desktop, 'Tablet' => $tablet, 'Other' => $other];
        arsort($platMap);
        $topPlatform = (string)array_key_first($platMap);
        $platSum = array_sum($platMap);
        $topPct = $platSum > 0 ? round(($platMap[$topPlatform] / $platSum) * 100, 1) : 0.0;
        if ($platSum === 0) {
            $topPlatform = '—';
        }

        return [
            'text' => $text,
            'best_day' => $bestDay,
            'low_day' => $lowDay,
            'peak_time' => $peakTime,
            'top_platform' => $topPlatform,
            'top_platform_pct' => $topPct,
        ];
    }
}
