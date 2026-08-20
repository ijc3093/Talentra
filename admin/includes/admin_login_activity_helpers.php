<?php
declare(strict_types=1);

/**
 * Login activity helpers for admin/login_activity.php.
 */

if (!function_exists('admin_login_activity_parse_ua')) {
    /**
     * @return array{login_type:string,login_type_icon:string,browser:string,browser_icon:string,os:string,device_label:string}
     */
    function admin_login_activity_parse_ua(string $ua): array
    {
        $ua = trim($ua);
        $isMobile = (bool)preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua);
        $loginType = $isMobile ? 'Mobile App' : 'Web';
        $loginIcon = $isMobile ? 'fa-mobile' : 'fa-desktop';

        $browser = 'Browser';
        $browserIcon = 'fa-globe';
        if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Edge ' . preg_replace('/^(\d+\.\d+).*/', '$1', $m[1]);
            $browserIcon = 'fa-windows';
        } elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m) && !preg_match('/Edg\//i', $ua)) {
            $browser = 'Chrome ' . preg_replace('/^(\d+\.\d+).*/', '$1', $m[1]);
            $browserIcon = 'fa-chrome';
        } elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Firefox ' . preg_replace('/^(\d+\.\d+).*/', '$1', $m[1]);
            $browserIcon = 'fa-firefox';
        } elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m) || (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua))) {
            $ver = $m[1] ?? '';
            $browser = 'Safari' . ($ver !== '' ? (' ' . preg_replace('/^(\d+\.\d+).*/', '$1', $ver)) : '');
            $browserIcon = 'fa-apple';
        }

        $os = 'Unknown OS';
        if (preg_match('/Windows NT 10/i', $ua)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
            $os = 'macOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
            $os = 'iOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            $os = 'Android ' . $m[1];
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $osIcon = 'fa-laptop';
        $osFamily = 'other';
        if (preg_match('/Windows/i', $ua)) {
            $osIcon = 'fa-windows';
            $osFamily = 'windows';
        } elseif (preg_match('/Android/i', $ua)) {
            $osIcon = 'fa-android';
            $osFamily = 'android';
        } elseif (preg_match('/iPhone|iPad|Mac OS X|Macintosh/i', $ua)) {
            $osIcon = 'fa-apple';
            $osFamily = 'apple';
        } elseif (preg_match('/Linux/i', $ua)) {
            $osIcon = 'fa-linux';
            $osFamily = 'linux';
        }

        $browserName = preg_replace('/\s+[\d.]+$/', '', $browser) ?: 'Browser';
        $osShort = preg_replace('/^macOS\s+.*/', 'macOS', $os);
        $osShort = preg_replace('/^iOS\s+.*/', 'iOS', $osShort);
        $osShort = preg_replace('/^Android\s+.*/', 'Android', $osShort);
        $osShort = preg_replace('/^Windows.*/', 'Windows', (string)$osShort);
        $deviceTitle = $browserName . ' on ' . $osShort;

        return [
            'login_type' => $loginType,
            'login_type_icon' => $loginIcon,
            'browser' => $browser,
            'browser_icon' => $browserIcon,
            'browser_name' => $browserName,
            'os' => $os,
            'os_icon' => $osIcon,
            'os_family' => $osFamily,
            'device_title' => $deviceTitle,
            'device_label' => $browser . ', ' . $os,
            'is_mobile' => $isMobile,
        ];
    }
}

if (!function_exists('admin_device_activity_status')) {
    /**
     * @return array{key:string,label:string,cls:string}
     */
    function admin_device_activity_status(?string $revokedAt, ?string $lastSeenAt): array
    {
        $revoked = $revokedAt !== null && trim($revokedAt) !== '' && trim($revokedAt) !== '0000-00-00 00:00:00';
        if ($revoked) {
            return ['key' => 'blocked', 'label' => 'Blocked', 'cls' => 'blocked'];
        }
        $ts = $lastSeenAt ? strtotime($lastSeenAt) : false;
        if ($ts !== false && (time() - $ts) <= 900) {
            return ['key' => 'active', 'label' => 'Active', 'cls' => 'active'];
        }
        return ['key' => 'inactive', 'label' => 'Inactive', 'cls' => 'inactive'];
    }
}

if (!function_exists('admin_device_activity_metrics')) {
    /**
     * @return array{
     *   total:array{value:int,sub:string,sub_cls:string},
     *   active:array{value:int,sub:string,sub_cls:string},
     *   mobile:array{value:int,sub:string,sub_cls:string},
     *   desktop:array{value:int,sub:string,sub_cls:string},
     *   blocked:array{value:int,sub:string,sub_cls:string}
     * }
     */
    function admin_device_activity_metrics(PDO $dbh): array
    {
        $z = ['value' => 0, 'sub' => '—', 'sub_cls' => 'flat'];
        $out = [
            'total' => $z,
            'active' => $z,
            'mobile' => $z,
            'desktop' => $z,
            'blocked' => $z,
        ];
        if (!msb_mod_table_exists($dbh, 'user_sessions')) {
            return $out;
        }

        $mobileSql = "(user_agent LIKE '%Mobile%' OR user_agent LIKE '%Android%' OR user_agent LIKE '%iPhone%' OR user_agent LIKE '%iPad%')";
        $activeSql = "(revoked_at IS NULL OR revoked_at = '0000-00-00 00:00:00') AND last_seen_at >= (NOW() - INTERVAL 15 MINUTE)";
        $blockedSql = "(revoked_at IS NOT NULL AND revoked_at <> '0000-00-00 00:00:00')";

        $pack = static function (PDO $dbh, string $whereExtra = '1=1') {
            $total = msb_mod_count_safe($dbh, "SELECT COUNT(*) FROM user_sessions WHERE {$whereExtra}");
            $now7 = msb_mod_count_safe($dbh, "
                SELECT COUNT(*) FROM user_sessions
                WHERE {$whereExtra} AND created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $prev7 = msb_mod_count_safe($dbh, "
                SELECT COUNT(*) FROM user_sessions
                WHERE {$whereExtra}
                  AND created_at >= (NOW() - INTERVAL 14 DAY)
                  AND created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $delta = admin_login_activity_delta_pct($now7, $prev7);
            return [
                'value' => $total,
                'sub' => (($delta >= 0 ? '▲ ' : '▼ ') . number_format(abs($delta), 1) . '% vs last 7 days'),
                'sub_cls' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            ];
        };

        // Active Now uses last_seen window — delta still based on created_at for "active" cohort is weak;
        // compute active count live, delta from sessions created that are currently active-ish.
        $out['total'] = $pack($dbh, '1=1');
        $activeNow = msb_mod_count_safe($dbh, "SELECT COUNT(*) FROM user_sessions WHERE {$activeSql}");
        $activePrev = msb_mod_count_safe($dbh, "
            SELECT COUNT(*) FROM user_sessions
            WHERE (revoked_at IS NULL OR revoked_at = '0000-00-00 00:00:00')
              AND last_seen_at >= (NOW() - INTERVAL 7 DAY - INTERVAL 15 MINUTE)
              AND last_seen_at < (NOW() - INTERVAL 7 DAY)
        ");
        $dActive = admin_login_activity_delta_pct($activeNow, $activePrev);
        $out['active'] = [
            'value' => $activeNow,
            'sub' => (($dActive >= 0 ? '▲ ' : '▼ ') . number_format(abs($dActive), 1) . '% vs last 7 days'),
            'sub_cls' => $dActive > 0 ? 'up' : ($dActive < 0 ? 'down' : 'flat'),
        ];
        $out['mobile'] = $pack($dbh, $mobileSql);
        $out['desktop'] = $pack($dbh, "NOT {$mobileSql}");
        $out['blocked'] = $pack($dbh, $blockedSql);

        return $out;
    }
}

if (!function_exists('admin_login_activity_delta_pct')) {
    function admin_login_activity_delta_pct(int $now, int $prev): float
    {
        if ($prev > 0) {
            return round((($now - $prev) / $prev) * 100, 1);
        }
        return $now > 0 ? 100.0 : 0.0;
    }
}

if (!function_exists('admin_login_activity_metrics')) {
    /**
     * @return array{
     *   total:array{value:int,delta:float,sub:string,sub_cls:string},
     *   success:array{value:int,delta:float,sub:string,sub_cls:string},
     *   failed:array{value:int,delta:float,sub:string,sub_cls:string},
     *   locations:array{value:int,delta:float,sub:string,sub_cls:string},
     *   devices:array{value:int,delta:float,sub:string,sub_cls:string}
     * }
     */
    function admin_login_activity_metrics(PDO $dbh): array
    {
        $z = ['value' => 0, 'delta' => 0.0, 'sub' => '—', 'sub_cls' => 'flat'];
        $out = [
            'total' => $z,
            'success' => $z,
            'failed' => $z,
            'locations' => $z,
            'devices' => $z,
        ];
        if (!msb_mod_table_exists($dbh, 'user_sessions')) {
            return $out;
        }

        $total = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM user_sessions');
        $now7 = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM user_sessions WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
        $prev7 = msb_mod_count_safe($dbh, '
            SELECT COUNT(*) FROM user_sessions
            WHERE created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ');
        $deltaTotal = admin_login_activity_delta_pct($now7, $prev7);
        $out['total'] = [
            'value' => $total,
            'delta' => $deltaTotal,
            'sub' => (($deltaTotal >= 0 ? '▲ ' : '▼ ') . number_format(abs($deltaTotal), 1) . '% vs last 7 days'),
            'sub_cls' => $deltaTotal > 0 ? 'up' : ($deltaTotal < 0 ? 'down' : 'flat'),
        ];

        // Sessions are created only after successful auth — no public failed-login log yet.
        $success = $total;
        $failed = 0;
        $successPct = $total > 0 ? round(($success / $total) * 100, 1) : 0.0;
        $failedPct = $total > 0 ? round(($failed / $total) * 100, 1) : 0.0;
        $out['success'] = [
            'value' => $success,
            'delta' => 0.0,
            'sub' => number_format($successPct, 1) . '% of total',
            'sub_cls' => 'up',
        ];
        $out['failed'] = [
            'value' => $failed,
            'delta' => 0.0,
            'sub' => number_format($failedPct, 1) . '% of total',
            'sub_cls' => 'down',
        ];

        $locs = msb_mod_count_safe($dbh, "
            SELECT COUNT(DISTINCT ip_address) FROM user_sessions
            WHERE ip_address IS NOT NULL AND TRIM(ip_address) <> ''
        ");
        $locs7 = msb_mod_count_safe($dbh, "
            SELECT COUNT(DISTINCT ip_address) FROM user_sessions
            WHERE ip_address IS NOT NULL AND TRIM(ip_address) <> ''
              AND created_at >= (NOW() - INTERVAL 7 DAY)
        ");
        $locsPrev = msb_mod_count_safe($dbh, "
            SELECT COUNT(DISTINCT ip_address) FROM user_sessions
            WHERE ip_address IS NOT NULL AND TRIM(ip_address) <> ''
              AND created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ");
        $dLoc = admin_login_activity_delta_pct($locs7, $locsPrev);
        $out['locations'] = [
            'value' => $locs,
            'delta' => $dLoc,
            'sub' => (($dLoc >= 0 ? '▲ ' : '▼ ') . number_format(abs($dLoc), 1) . '% vs last 7 days'),
            'sub_cls' => $dLoc > 0 ? 'up' : ($dLoc < 0 ? 'down' : 'flat'),
        ];

        $newDev = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM user_sessions WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
        $newDevPrev = msb_mod_count_safe($dbh, '
            SELECT COUNT(*) FROM user_sessions
            WHERE created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)
        ');
        $dDev = admin_login_activity_delta_pct($newDev, $newDevPrev);
        $out['devices'] = [
            'value' => $newDev,
            'delta' => $dDev,
            'sub' => (($dDev >= 0 ? '▲ ' : '▼ ') . number_format(abs($dDev), 1) . '% vs last 7 days'),
            'sub_cls' => $dDev > 0 ? 'up' : ($dDev < 0 ? 'down' : 'flat'),
        ];

        return $out;
    }
}
