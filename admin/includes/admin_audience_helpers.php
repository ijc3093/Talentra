<?php
declare(strict_types=1);

/**
 * Helpers for admin/audience.php.
 */

require_once __DIR__ . '/admin_kind_tabs.php';

if (!function_exists('admin_audience_h')) {
    function admin_audience_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_audience_kind_sql')) {
    /** Bare users-table predicate (no alias). */
    function admin_audience_kind_sql(string $kind): string
    {
        return admin_kind_user_where($kind, '');
    }
}

if (!function_exists('admin_audience_delta_pct')) {
    function admin_audience_delta_pct(int $now, int $prev): float
    {
        if ($prev > 0) {
            return round((($now - $prev) / $prev) * 100, 1);
        }
        return $now > 0 ? 100.0 : 0.0;
    }
}

if (!function_exists('admin_audience_count')) {
    function admin_audience_count(PDO $dbh, string $sql, array $params = []): int
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

if (!function_exists('admin_audience_table_exists')) {
    function admin_audience_table_exists(PDO $dbh, string $table): bool
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

if (!function_exists('admin_audience_metric_sub')) {
    /**
     * @return array{value:int,sub:string,sub_cls:string,delta:float}
     */
    function admin_audience_metric_sub(int $value, float $delta): array
    {
        $cls = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
        $arrow = $delta > 0 ? '▲ ' : ($delta < 0 ? '▼ ' : '');
        return [
            'value' => $value,
            'delta' => $delta,
            'sub' => $arrow . number_format(abs($delta), 1) . '% vs last 7 days',
            'sub_cls' => $cls,
        ];
    }
}

if (!function_exists('admin_audience_metrics')) {
    /**
     * @return array{
     *   total:array{value:int,sub:string,sub_cls:string,delta:float},
     *   new:array{value:int,sub:string,sub_cls:string,delta:float},
     *   active:array{value:int,sub:string,sub_cls:string,delta:float},
     *   returning:array{value:int,sub:string,sub_cls:string,delta:float},
     *   engaged:array{value:int,sub:string,sub_cls:string,delta:float}
     * }
     */
    function admin_audience_metrics(PDO $dbh, string $kind = 'personal'): array
    {
        $z = admin_audience_metric_sub(0, 0.0);
        $out = ['total' => $z, 'new' => $z, 'active' => $z, 'returning' => $z, 'engaged' => $z];
        if (!admin_audience_table_exists($dbh, 'users')) {
            return $out;
        }

        $kw = admin_audience_kind_sql($kind);
        $aWhere = admin_kind_user_where($kind, 'u');

        $total = admin_audience_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw}");
        $new7 = admin_audience_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw} AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $newPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ");
        // Total growth: compare net new this week vs last week.
        $out['total'] = admin_audience_metric_sub($total, admin_audience_delta_pct($new7, $newPrev));
        $out['new'] = admin_audience_metric_sub($new7, admin_audience_delta_pct($new7, $newPrev));

        $active7 = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 7 DAY)
        ");
        $activePrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 14 DAY)
              AND last_seen < (NOW() - INTERVAL 7 DAY)
        ");
        $out['active'] = admin_audience_metric_sub($active7, admin_audience_delta_pct($active7, $activePrev));

        $returning = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 7 DAY)
              AND created_at < (NOW() - INTERVAL 7 DAY)
        ");
        $returningPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 14 DAY)
              AND last_seen < (NOW() - INTERVAL 7 DAY)
              AND created_at < (NOW() - INTERVAL 14 DAY)
        ");
        $out['returning'] = admin_audience_metric_sub($returning, admin_audience_delta_pct($returning, $returningPrev));

        $engaged = 0;
        $engagedPrev = 0;
        if (admin_audience_table_exists($dbh, 'public_posts')) {
            $engaged = admin_audience_count($dbh, "
                SELECT COUNT(DISTINCT p.user_id) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere}
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND p.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $engagedPrev = admin_audience_count($dbh, "
                SELECT COUNT(DISTINCT p.user_id) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere}
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND p.created_at >= (NOW() - INTERVAL 14 DAY)
                  AND p.created_at < (NOW() - INTERVAL 7 DAY)
            ");
        }
        if ($engaged === 0) {
            // Fallback: users seen in last 24h.
            $engaged = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 1 DAY)
            ");
            $engagedPrev = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM users
                WHERE {$kw} AND last_seen IS NOT NULL
                  AND last_seen >= (NOW() - INTERVAL 2 DAY)
                  AND last_seen < (NOW() - INTERVAL 1 DAY)
            ");
        }
        $out['engaged'] = admin_audience_metric_sub($engaged, admin_audience_delta_pct($engaged, $engagedPrev));

        return $out;
    }
}

if (!function_exists('admin_audience_growth_series')) {
    /**
     * @return array{labels:list<string>,total:list<int>,new:list<int>}
     */
    function admin_audience_growth_series(PDO $dbh, string $from, string $to, string $kind = 'personal'): array
    {
        $labels = [];
        $total = [];
        $new = [];
        $kw = admin_audience_kind_sql($kind);
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
        for ($i = 0; $i < $days; $i++) {
            $ts = $start + ($i * 86400);
            $day = date('Y-m-d', $ts);
            $labels[] = date('M j', $ts);
            $new[] = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM users WHERE {$kw} AND DATE(created_at) = :d
            ", [':d' => $day]);
            $total[] = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM users WHERE {$kw} AND DATE(created_at) <= :d
            ", [':d' => $day]);
        }
        return ['labels' => $labels, 'total' => $total, 'new' => $new];
    }
}

if (!function_exists('admin_audience_age_years')) {
    function admin_audience_age_years(?string $birthday, ?string $ageField): ?int
    {
        $src = '';
        foreach ([$birthday, $ageField] as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '' || $cand === '0000-00-00') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $cand)) {
                $src = substr($cand, 0, 10);
                break;
            }
            if (ctype_digit($cand) && (int)$cand > 0 && (int)$cand < 120) {
                return (int)$cand;
            }
        }
        if ($src === '') {
            return null;
        }
        $ts = strtotime($src);
        if ($ts === false) {
            return null;
        }
        $years = (int)floor((time() - $ts) / (365.25 * 86400));
        return ($years >= 0 && $years < 120) ? $years : null;
    }
}

if (!function_exists('admin_audience_age_breakdown')) {
    /**
     * @return list<array{label:string,count:int,pct:float,color:string}>
     */
    function admin_audience_age_breakdown(PDO $dbh, string $kind = 'personal'): array
    {
        $buckets = [
            '18-24' => 0,
            '25-34' => 0,
            '35-44' => 0,
            '45-54' => 0,
            '55+' => 0,
            'Unknown' => 0,
        ];
        $colors = [
            '18-24' => '#2563eb',
            '25-34' => '#22c55e',
            '35-44' => '#a855f7',
            '45-54' => '#f59e0b',
            '55+' => '#ef4444',
            'Unknown' => '#94a3b8',
        ];
        if (!admin_audience_table_exists($dbh, 'users')) {
            return [];
        }
        $kw = admin_audience_kind_sql($kind);
        try {
            $st = $dbh->query("
                SELECT birthday, age, account_kind, publisher_category, friend_code
                FROM users WHERE {$kw}
            ");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            try {
                $st = $dbh->query("SELECT birthday, age FROM users WHERE {$kw}");
                $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (Throwable $e2) {
                return [];
            }
        }
        foreach ($rows as $r) {
            $y = admin_audience_age_years(
                isset($r['birthday']) ? (string)$r['birthday'] : null,
                isset($r['age']) ? (string)$r['age'] : null
            );
            if ($y === null) {
                $buckets['Unknown']++;
            } elseif ($y < 25) {
                $buckets['18-24']++;
            } elseif ($y < 35) {
                $buckets['25-34']++;
            } elseif ($y < 45) {
                $buckets['35-44']++;
            } elseif ($y < 55) {
                $buckets['45-54']++;
            } else {
                $buckets['55+']++;
            }
        }
        $sum = array_sum($buckets);
        $out = [];
        foreach ($buckets as $label => $count) {
            if ($label === 'Unknown' && $count === 0) {
                continue;
            }
            // Hide Unknown when every user is unknown so donut still shows empty-friendly legend.
            $out[] = [
                'label' => $label,
                'count' => $count,
                'pct' => $sum > 0 ? round(($count / $sum) * 100, 1) : 0.0,
                'color' => $colors[$label] ?? '#94a3b8',
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_audience_gender_breakdown')) {
    /**
     * @return list<array{label:string,count:int,pct:float,color:string}>
     */
    function admin_audience_gender_breakdown(PDO $dbh, string $kind = 'personal'): array
    {
        $map = [
            'Male' => 0,
            'Female' => 0,
            'Other' => 0,
            'Prefer not to say' => 0,
        ];
        $colors = [
            'Male' => '#2563eb',
            'Female' => '#ec4899',
            'Other' => '#a855f7',
            'Prefer not to say' => '#94a3b8',
        ];
        if (!admin_audience_table_exists($dbh, 'users')) {
            return [];
        }
        $kw = admin_audience_kind_sql($kind);
        try {
            $st = $dbh->query("SELECT gender, COUNT(*) AS c FROM users WHERE {$kw} GROUP BY gender");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $g = strtolower(trim((string)($r['gender'] ?? '')));
            $c = (int)($r['c'] ?? 0);
            if ($g === 'male' || $g === 'm') {
                $map['Male'] += $c;
            } elseif ($g === 'female' || $g === 'f') {
                $map['Female'] += $c;
            } elseif ($g === 'other' || $g === 'non-binary' || $g === 'nonbinary') {
                $map['Other'] += $c;
            } else {
                $map['Prefer not to say'] += $c;
            }
        }
        $sum = array_sum($map);
        $out = [];
        foreach ($map as $label => $count) {
            $out[] = [
                'label' => $label,
                'count' => $count,
                'pct' => $sum > 0 ? round(($count / $sum) * 100, 1) : 0.0,
                'color' => $colors[$label] ?? '#94a3b8',
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_audience_donut_bg')) {
    /**
     * @param list<array{pct:float,color:string}> $rows
     */
    function admin_audience_donut_bg(array $rows): string
    {
        $parts = [];
        $cursor = 0.0;
        foreach ($rows as $r) {
            $pct = (float)($r['pct'] ?? 0);
            if ($pct <= 0) {
                continue;
            }
            $next = $cursor + $pct;
            $parts[] = ($r['color'] ?? '#94a3b8') . ' ' . $cursor . '% ' . $next . '%';
            $cursor = $next;
        }
        if ($parts === []) {
            return 'conic-gradient(#e2e8f0 0 100%)';
        }
        if ($cursor < 100) {
            $parts[] = '#e2e8f0 ' . $cursor . '% 100%';
        }
        return 'conic-gradient(' . implode(', ', $parts) . ')';
    }
}

if (!function_exists('admin_audience_top_locations')) {
    /**
     * @return list<array{label:string,count:int,pct:float,flag:string}>
     */
    function admin_audience_top_locations(PDO $dbh, int $limit = 5): array
    {
        if (function_exists('admin_overview_top_countries')) {
            $rows = admin_overview_top_countries($dbh, $limit);
            if ($rows !== []) {
                return $rows;
            }
        }
        // Soft fallback: distinct session IPs as “locations” without geo.
        if (!admin_audience_table_exists($dbh, 'user_sessions')) {
            return [];
        }
        $known = admin_audience_count($dbh, "
            SELECT COUNT(DISTINCT ip_address) FROM user_sessions
            WHERE ip_address IS NOT NULL AND TRIM(ip_address) <> ''
        ");
        if ($known <= 0) {
            return [];
        }
        return [[
            'label' => 'Unknown location',
            'count' => $known,
            'pct' => 100.0,
            'flag' => '🌐',
        ]];
    }
}

if (!function_exists('admin_audience_top_devices')) {
    /**
     * @return list<array{label:string,icon:string,count:int,pct:float}>
     */
    function admin_audience_top_devices(PDO $dbh, int $limit = 4): array
    {
        $buckets = ['Mobile' => 0, 'Desktop' => 0, 'Tablet' => 0, 'Other' => 0];
        $icons = [
            'Mobile' => 'fa-mobile',
            'Desktop' => 'fa-desktop',
            'Tablet' => 'fa-tablet',
            'Other' => 'fa-question-circle',
        ];
        if (!admin_audience_table_exists($dbh, 'user_sessions')) {
            return [];
        }
        try {
            $st = $dbh->query('SELECT user_agent FROM user_sessions');
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $ua = (string)($r['user_agent'] ?? '');
            if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) {
                $buckets['Tablet']++;
            } elseif (preg_match('/Mobile|Android|iPhone|iPod/i', $ua)) {
                $buckets['Mobile']++;
            } elseif (trim($ua) === '') {
                $buckets['Other']++;
            } else {
                $buckets['Desktop']++;
            }
        }
        $sum = array_sum($buckets);
        $out = [];
        foreach ($buckets as $label => $count) {
            $out[] = [
                'label' => $label,
                'icon' => $icons[$label] ?? 'fa-laptop',
                'count' => $count,
                'pct' => $sum > 0 ? round(($count / $sum) * 100, 1) : 0.0,
            ];
        }
        usort($out, static fn($a, $b) => $b['count'] <=> $a['count']);
        return array_slice($out, 0, $limit);
    }
}

if (!function_exists('admin_audience_overview_rows')) {
    /**
     * @return list<array{metric:string,value:string,delta:float,delta_cls:string}>
     */
    function admin_audience_overview_rows(PDO $dbh, string $kind = 'personal'): array
    {
        $kw = admin_audience_kind_sql($kind);
        $aWhere = admin_kind_user_where($kind, 'u');
        $dau = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 1 DAY)
        ");
        $dauPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 2 DAY)
              AND last_seen < (NOW() - INTERVAL 1 DAY)
        ");
        $wau = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 7 DAY)
        ");
        $wauPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 14 DAY)
              AND last_seen < (NOW() - INTERVAL 7 DAY)
        ");
        $mau = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 30 DAY)
        ");
        $mauPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 60 DAY)
              AND last_seen < (NOW() - INTERVAL 30 DAY)
        ");

        $avgSec = 0.0;
        $avgPrev = 0.0;
        $bounce = 0.0;
        $bouncePrev = 0.0;
        if (admin_audience_table_exists($dbh, 'user_sessions')) {
            try {
                $st = $dbh->query("
                    SELECT AVG(TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.last_seen_at, s.created_at))) AS avg_sec
                    FROM user_sessions s
                    INNER JOIN users u ON u.id = s.user_id
                    WHERE {$aWhere}
                      AND s.created_at >= (NOW() - INTERVAL 7 DAY)
                      AND TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.last_seen_at, s.created_at)) BETWEEN 0 AND 86400
                ");
                $avgSec = (float)($st ? $st->fetchColumn() : 0);
            } catch (Throwable $e) {
                $avgSec = 0.0;
            }
            try {
                $st = $dbh->query("
                    SELECT AVG(TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.last_seen_at, s.created_at))) AS avg_sec
                    FROM user_sessions s
                    INNER JOIN users u ON u.id = s.user_id
                    WHERE {$aWhere}
                      AND s.created_at >= (NOW() - INTERVAL 14 DAY)
                      AND s.created_at < (NOW() - INTERVAL 7 DAY)
                      AND TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.last_seen_at, s.created_at)) BETWEEN 0 AND 86400
                ");
                $avgPrev = (float)($st ? $st->fetchColumn() : 0);
            } catch (Throwable $e) {
                $avgPrev = 0.0;
            }
            $short = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                WHERE {$aWhere}
                  AND s.created_at >= (NOW() - INTERVAL 7 DAY)
                  AND TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.last_seen_at, s.created_at)) < 30
            ");
            $all = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                WHERE {$aWhere} AND s.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $bounce = $all > 0 ? round(($short / $all) * 100, 1) : 0.0;
            $shortPrev = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                WHERE {$aWhere}
                  AND s.created_at >= (NOW() - INTERVAL 14 DAY)
                  AND s.created_at < (NOW() - INTERVAL 7 DAY)
                  AND TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.last_seen_at, s.created_at)) < 30
            ");
            $allPrev = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                WHERE {$aWhere}
                  AND s.created_at >= (NOW() - INTERVAL 14 DAY)
                  AND s.created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $bouncePrev = $allPrev > 0 ? round(($shortPrev / $allPrev) * 100, 1) : 0.0;
        }

        $mins = (int)floor($avgSec / 60);
        $secs = (int)round($avgSec % 60);
        $avgLabel = sprintf('%02dm %02ds', $mins, $secs);

        $pages = 0.0;
        if (admin_audience_table_exists($dbh, 'public_posts') && $wau > 0) {
            $posts7 = admin_audience_count($dbh, "
                SELECT COUNT(*) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere}
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND p.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $pages = round(max(1, $posts7) / max(1, $wau), 1);
        }

        $pack = static function (string $metric, string $value, float $delta, bool $invert = false): array {
            $cls = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
            if ($invert) {
                // Lower bounce is good → flip colors.
                if ($delta < 0) {
                    $cls = 'up';
                } elseif ($delta > 0) {
                    $cls = 'down';
                }
            }
            return [
                'metric' => $metric,
                'value' => $value,
                'delta' => $delta,
                'delta_cls' => $cls,
            ];
        };

        return [
            $pack('Daily Active Users (DAU)', number_format($dau), admin_audience_delta_pct($dau, $dauPrev)),
            $pack('Weekly Active Users (WAU)', number_format($wau), admin_audience_delta_pct($wau, $wauPrev)),
            $pack('Monthly Active Users (MAU)', number_format($mau), admin_audience_delta_pct($mau, $mauPrev)),
            $pack('Avg. Session Duration', $avgLabel, admin_audience_delta_pct((int)round($avgSec), (int)round($avgPrev))),
            $pack('Bounce Rate', number_format($bounce, 1) . '%', admin_audience_delta_pct((int)round($bounce * 10), (int)round($bouncePrev * 10)), true),
            $pack('Pages per Session', number_format($pages, 1), 0.0),
        ];
    }
}

if (!function_exists('admin_audience_segments')) {
    /**
     * @return list<array{key:string,label:string,icon:string,cls:string,value:int,delta:float,desc:string}>
     */
    function admin_audience_segments(PDO $dbh, string $kind = 'personal'): array
    {
        $kw = admin_audience_kind_sql($kind);
        $aWhere = admin_kind_user_where($kind, 'u');
        $new7 = admin_audience_count($dbh, "SELECT COUNT(*) FROM users WHERE {$kw} AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $newPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ");
        $active7 = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 7 DAY)
        ");
        $activePrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 14 DAY)
              AND last_seen < (NOW() - INTERVAL 7 DAY)
        ");
        $engaged = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 1 DAY)
        ");
        if (admin_audience_table_exists($dbh, 'public_posts')) {
            $engagedPosts = admin_audience_count($dbh, "
                SELECT COUNT(DISTINCT p.user_id) FROM public_posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE {$aWhere}
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND p.created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            if ($engagedPosts > 0) {
                $engaged = $engagedPosts;
            }
        }
        $engagedPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND last_seen IS NOT NULL
              AND last_seen >= (NOW() - INTERVAL 2 DAY)
              AND last_seen < (NOW() - INTERVAL 1 DAY)
        ");
        $atRisk = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw}
              AND (last_seen IS NULL OR last_seen < (NOW() - INTERVAL 14 DAY))
              AND (status = 1 OR status IS NULL)
        ");
        $atRiskPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw}
              AND (last_seen IS NULL OR last_seen < (NOW() - INTERVAL 21 DAY))
              AND (status = 1 OR status IS NULL)
              AND created_at < (NOW() - INTERVAL 14 DAY)
        ");
        // Approximate prior at-risk size for delta.
        $churned = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND (
              status = 0
              OR (last_seen IS NOT NULL AND last_seen < (NOW() - INTERVAL 30 DAY))
            )
        ");
        $churnedPrev = admin_audience_count($dbh, "
            SELECT COUNT(*) FROM users
            WHERE {$kw} AND (
              status = 0
              OR (last_seen IS NOT NULL AND last_seen < (NOW() - INTERVAL 37 DAY))
            )
        ");

        return [
            [
                'key' => 'new',
                'label' => 'New Users',
                'icon' => 'fa-user-plus',
                'cls' => 'blue',
                'value' => $new7,
                'delta' => admin_audience_delta_pct($new7, $newPrev),
                'desc' => 'Users who joined in the last 7 days.',
            ],
            [
                'key' => 'active',
                'label' => 'Active Users',
                'icon' => 'fa-bolt',
                'cls' => 'green',
                'value' => $active7,
                'delta' => admin_audience_delta_pct($active7, $activePrev),
                'desc' => 'Users active in the last 7 days.',
            ],
            [
                'key' => 'engaged',
                'label' => 'High Engagement',
                'icon' => 'fa-heart',
                'cls' => 'purple',
                'value' => $engaged,
                'delta' => admin_audience_delta_pct($engaged, $engagedPrev),
                'desc' => 'Users with high interaction.',
            ],
            [
                'key' => 'risk',
                'label' => 'At Risk Users',
                'icon' => 'fa-exclamation-triangle',
                'cls' => 'orange',
                'value' => $atRisk,
                'delta' => admin_audience_delta_pct($atRisk, $atRiskPrev),
                'desc' => 'Users inactive for 14+ days.',
            ],
            [
                'key' => 'churned',
                'label' => 'Churned Users',
                'icon' => 'fa-user-times',
                'cls' => 'red',
                'value' => $churned,
                'delta' => admin_audience_delta_pct($churned, $churnedPrev),
                'desc' => 'Users who left in the last 30 days.',
            ],
        ];
    }
}
