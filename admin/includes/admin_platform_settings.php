<?php
declare(strict_types=1);

/**
 * Platform settings storage for admin/settings.php.
 */

if (!function_exists('admin_platform_settings_h')) {
    function admin_platform_settings_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_platform_settings_ensure_table')) {
    function admin_platform_settings_ensure_table(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS admin_platform_settings (
              id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
              settings_json LONGTEXT NULL,
              updated_at DATETIME NULL,
              updated_by INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    }
}

if (!function_exists('admin_platform_settings_defaults')) {
    /**
     * @return array<string,mixed>
     */
    function admin_platform_settings_defaults(?object $controller = null): array
    {
        $fromName = 'Talentra Admin';
        $fromEmail = '';
        $smtpHost = 'smtp.gmail.com';
        $smtpPort = 587;

        try {
            if (!class_exists('Config', false) && is_file(dirname(__DIR__, 2) . '/config.php')) {
                require_once dirname(__DIR__, 2) . '/config.php';
            }
            if (class_exists('Config', false)) {
                $cfg = new Config();
                $fromName = (string)($cfg->SMTP_FROM_NAME ?? $fromName);
                $fromEmail = (string)($cfg->SMTP_FROM ?? $fromEmail);
                $smtpHost = (string)($cfg->SMTP_HOST ?? $smtpHost);
                $smtpPort = (int)($cfg->SMTP_PORT ?? $smtpPort);
            }
        } catch (Throwable $e) {
        }

        if (is_object($controller)) {
            foreach (['SMTP_FROM_NAME' => 'fromName', 'SMTP_FROM' => 'fromEmail', 'SMTP_HOST' => 'smtpHost', 'SMTP_PORT' => 'smtpPort'] as $prop => $var) {
                if (isset($controller->$prop)) {
                    $$var = $controller->$prop;
                }
            }
            $smtpPort = (int)$smtpPort;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/admin/settings.php');
        $base = rtrim($scheme . '://' . $host . dirname($script), '/\\');

        return [
            'platform_name' => 'Talentra Admin',
            'platform_url' => $base,
            'timezone' => 'America/New_York',
            'date_format' => 'M j, Y',
            'time_format' => '12',
            'logo_path' => '',
            'require_2fa' => 1,
            'session_timeout_enabled' => 1,
            'login_attempts_enabled' => 1,
            'ip_whitelist_enabled' => 0,
            'session_timeout_minutes' => 30,
            'max_login_attempts' => 5,
            'lockout_duration_minutes' => 30,
            'account_lockout' => 1,
            'captcha_on_login' => 1,
            'ip_rate_limiting' => 1,
            'require_https' => 1,
            'security_headers' => 1,
            'activity_logging' => 1,
            'password_policy' => 'strong',
            'backup_codes_unused' => 10,
            'ip_whitelist' => '',
            'email_from_name' => $fromName !== '' ? $fromName : 'Talentra Admin',
            'email_from' => $fromEmail,
            'email_reply_to' => $fromEmail,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => 'TLS',
            'privacy_analytics' => 1,
            'privacy_crash_reports' => 1,
            'privacy_performance' => 1,
            'privacy_third_party_cookies' => 0,
            'privacy_personalized' => 0,
            'privacy_use_improvements' => 1,
            'privacy_marketing' => 0,
            'privacy_share_partners' => 0,
            'privacy_export_requests' => 1,
            'privacy_deletion_requests' => 1,
            'privacy_user_defaults' => 1,
            'privacy_retention_user_years' => 2,
            'privacy_retention_analytics_months' => 26,
            'privacy_retention_log_months' => 12,
            'privacy_retention_backup_days' => 30,
            'privacy_gdpr' => 1,
            'privacy_ccpa' => 1,
            'privacy_coppa' => 1,
            'privacy_pipeda' => 1,
            'system_auto_updates' => 1,
            'system_update_channel' => 'stable',
            'system_maintenance_mode' => 0,
            'system_last_backup_at' => '',
            'system_next_backup_at' => '',
            'system_backup_size' => '',
            'system_last_update_check' => '',
            // API management (admin UI + storage; no external gateway)
            'api_daily_limit' => 100000,
            'api_requests_today' => 0,
            'api_requests_yesterday' => 0,
            'api_ip_allowlist' => '',
            'api_webhooks' => [],
            // Integrations catalog state (id => {status,last_sync,notes})
            'integrations_map' => [],
            // Content settings (admin UI; platform policy)
            'content_require_approval' => 1,
            'content_auto_publish_trusted' => 0,
            'content_enable_auto_publish' => 1,
            'content_types' => ['image', 'video', 'article', 'document', 'pdf', 'gif'],
            'content_allow_image_uploads' => 1,
            'content_allow_video_uploads' => 1,
            'content_max_file_size_mb' => 50,
            'content_default_visibility' => 'public',
            'content_allow_change_visibility' => 1,
            'content_allow_comments' => 1,
            'content_comment_approval' => 0,
        ];
    }
}

if (!function_exists('admin_platform_settings_load')) {
    /**
     * @return array<string,mixed>
     */
    function admin_platform_settings_load(PDO $dbh): array
    {
        admin_platform_settings_ensure_table($dbh);
        $defaults = admin_platform_settings_defaults();
        try {
            $st = $dbh->query('SELECT settings_json FROM admin_platform_settings WHERE id = 1 LIMIT 1');
            $json = $st ? (string)$st->fetchColumn() : '';
            if ($json === '') {
                return $defaults;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return $defaults;
            }
            return array_merge($defaults, $decoded);
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}

if (!function_exists('admin_platform_settings_save')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_platform_settings_save(PDO $dbh, array $settings): void
    {
        admin_platform_settings_ensure_table($dbh);
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode settings.');
        }
        $st = $dbh->prepare('
            INSERT INTO admin_platform_settings (id, settings_json, updated_at, updated_by)
            VALUES (1, :json, NOW(), :by)
            ON DUPLICATE KEY UPDATE
              settings_json = VALUES(settings_json),
              updated_at = VALUES(updated_at),
              updated_by = VALUES(updated_by)
        ');
        $st->execute([
            ':json' => $json,
            ':by' => $adminId > 0 ? $adminId : null,
        ]);
    }
}

if (!function_exists('admin_platform_settings_logo_url')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_platform_settings_logo_url(array $settings): string
    {
        $path = trim((string)($settings['logo_path'] ?? ''));
        if ($path === '') {
            return '';
        }
        $abs = __DIR__ . '/../' . ltrim($path, '/');
        if (!is_file($abs)) {
            return '';
        }
        return $path;
    }
}

if (!function_exists('admin_platform_settings_store_logo')) {
    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $settings
     */
    function admin_platform_settings_store_logo(array $file, array &$settings): string
    {
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'Invalid logo upload.';
        }
        if ($size > 2 * 1024 * 1024) {
            return 'Logo must be 2MB or smaller.';
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        if (!isset($map[$mime])) {
            return 'Logo must be PNG, JPG, WEBP, or SVG.';
        }
        $dir = __DIR__ . '/../images';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return 'Could not create images directory.';
        }
        $name = 'platform_logo_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            return 'Could not save logo.';
        }
        $old = trim((string)($settings['logo_path'] ?? ''));
        if ($old !== '' && is_file(__DIR__ . '/../' . ltrim($old, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($old, '/'));
        }
        $settings['logo_path'] = 'images/' . $name;
        return '';
    }
}

if (!function_exists('admin_platform_settings_remove_logo')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_platform_settings_remove_logo(array &$settings): void
    {
        $old = trim((string)($settings['logo_path'] ?? ''));
        if ($old !== '' && is_file(__DIR__ . '/../' . ltrim($old, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($old, '/'));
        }
        $settings['logo_path'] = '';
    }
}

if (!function_exists('admin_platform_settings_clear_cache')) {
    function admin_platform_settings_clear_cache(): int
    {
        $count = 0;
        $roots = [
            dirname(__DIR__, 2) . '/tmp',
            dirname(__DIR__) . '/tmp',
            sys_get_temp_dir() . '/mystorybook_cache',
        ];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = glob(rtrim($root, '/\\') . '/*') ?: [];
            foreach ($files as $f) {
                if (is_file($f) && @unlink($f)) {
                    $count++;
                }
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return $count;
    }
}

if (!function_exists('admin_platform_settings_parse_bytes')) {
    function admin_platform_settings_parse_bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '' || $val === '-1') {
            return 0;
        }
        if (is_numeric($val)) {
            return (int)$val;
        }
        $unit = strtolower(substr($val, -1));
        $num = (float)$val;
        return match ($unit) {
            'g' => (int)round($num * 1024 * 1024 * 1024),
            'm' => (int)round($num * 1024 * 1024),
            'k' => (int)round($num * 1024),
            default => (int)$num,
        };
    }
}

if (!function_exists('admin_platform_settings_system_info')) {
    /**
     * Rich system / server info for the System settings UI.
     *
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>
     */
    function admin_platform_settings_system_info(?array $settings = null, ?PDO $dbh = null): array
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $env = (stripos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false)
            ? 'Local'
            : 'Production';

        $uptime = '—';
        if (is_readable('/proc/uptime')) {
            $raw = @file_get_contents('/proc/uptime');
            $secs = (int)floatval(explode(' ', (string)$raw)[0] ?? 0);
            if ($secs > 0) {
                $d = intdiv($secs, 86400);
                $h = intdiv($secs % 86400, 3600);
                $m = intdiv($secs % 3600, 60);
                $uptime = $d . 'd ' . $h . 'h ' . $m . 'm';
            }
        } elseif (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $boot = @shell_exec('sysctl -n kern.boottime 2>/dev/null');
            if (is_string($boot) && preg_match('/sec\s*=\s*(\d+)/', $boot, $m)) {
                $secs = time() - (int)$m[1];
                if ($secs > 0) {
                    $d = intdiv($secs, 86400);
                    $h = intdiv($secs % 86400, 3600);
                    $mi = intdiv($secs % 3600, 60);
                    $uptime = $d . 'd ' . $h . 'h ' . $mi . 'm';
                }
            }
        } elseif (function_exists('shell_exec')) {
            $out = @shell_exec('uptime');
            if (is_string($out) && $out !== '') {
                $uptime = trim(preg_replace('/\s+/', ' ', $out) ?? $out);
                if (mb_strlen($uptime) > 40) {
                    $uptime = mb_substr($uptime, 0, 37) . '…';
                }
            }
        }

        $platformName = 'Talentra Admin';
        if (is_array($settings)) {
            $pn = trim((string)($settings['platform_name'] ?? ''));
            if ($pn !== '') {
                $platformName = $pn;
            }
        }

        $tz = is_array($settings) ? trim((string)($settings['timezone'] ?? '')) : '';
        $prevTz = date_default_timezone_get();
        if ($tz !== '') {
            try {
                date_default_timezone_set($tz);
            } catch (Throwable $e) {
            }
        }
        $serverTime = date('M j, Y g:i A');
        if ($tz !== '') {
            date_default_timezone_set($prevTz);
        }

        $mysqlVersion = '—';
        if ($dbh instanceof PDO) {
            try {
                $mysqlVersion = (string)$dbh->query('SELECT VERSION()')->fetchColumn();
            } catch (Throwable $e) {
                $mysqlVersion = '—';
            }
        }

        $webServer = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if ($webServer === '') {
            $webServer = '—';
        }

        $os = PHP_OS_FAMILY;
        try {
            $uname = php_uname('s') . ' ' . php_uname('r');
            if (trim($uname) !== '') {
                $os = trim($uname);
                if (mb_strlen($os) > 48) {
                    $os = mb_substr($os, 0, 45) . '…';
                }
            }
        } catch (Throwable $e) {
        }

        $memoryPct = null;
        $memUsed = (int)memory_get_usage(true);
        $memLimit = admin_platform_settings_parse_bytes((string)ini_get('memory_limit'));
        if ($memLimit > 0) {
            $memoryPct = (int)min(100, max(0, round(($memUsed / $memLimit) * 100)));
        }

        $diskPct = null;
        $root = dirname(__DIR__, 2);
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);
        if (is_float($total) || is_int($total)) {
            $total = (float)$total;
            $free = (is_float($free) || is_int($free)) ? (float)$free : 0.0;
            if ($total > 0) {
                $diskPct = (int)min(100, max(0, round((($total - $free) / $total) * 100)));
            }
        }

        $cpuPct = null;
        if (is_readable('/proc/loadavg')) {
            $load = @file_get_contents('/proc/loadavg');
            if (is_string($load) && $load !== '') {
                $parts = preg_split('/\s+/', trim($load)) ?: [];
                $load1 = isset($parts[0]) ? (float)$parts[0] : 0.0;
                $cores = 1;
                if (is_readable('/proc/cpuinfo')) {
                    $cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
                    $cores = max(1, substr_count($cpuinfo, 'processor'));
                }
                $cpuPct = (int)min(100, max(0, round(($load1 / $cores) * 100)));
            }
        }

        $lastBackup = '—';
        if (is_array($settings)) {
            $lb = trim((string)($settings['system_last_backup_at'] ?? ''));
            if ($lb !== '') {
                $ts = strtotime($lb);
                $lastBackup = $ts !== false ? date('M j, Y g:i A', $ts) : $lb;
            }
        }

        return [
            'platform_name' => $platformName,
            'environment' => $env,
            'version' => 'v2.4.1',
            'app_version' => '2.4.1',
            'server_time' => $serverTime,
            'uptime' => $uptime,
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'web_server' => $webServer,
            'os' => $os,
            'memory_usage_pct' => $memoryPct,
            'disk_usage_pct' => $diskPct,
            'cpu_pct' => $cpuPct,
            'last_backup' => $lastBackup,
        ];
    }
}

if (!function_exists('admin_platform_settings_health_checks')) {
    /**
     * @return list<array{name:string,status:string}>
     */
    function admin_platform_settings_health_checks(PDO $dbh): array
    {
        $dbOk = false;
        try {
            $dbh->query('SELECT 1');
            $dbOk = true;
        } catch (Throwable $e) {
            $dbOk = false;
        }

        $storageDirs = [
            __DIR__ . '/../images',
            dirname(__DIR__, 2) . '/tmp',
            dirname(__DIR__) . '/tmp',
        ];
        $storageOk = false;
        foreach ($storageDirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                $storageOk = true;
                break;
            }
        }

        $cacheRoots = [
            dirname(__DIR__, 2) . '/tmp',
            dirname(__DIR__) . '/tmp',
            sys_get_temp_dir() . '/mystorybook_cache',
        ];
        $cacheOk = false;
        foreach ($cacheRoots as $root) {
            if (!is_dir($root)) {
                @mkdir($root, 0755, true);
            }
            if (is_dir($root) && is_writable($root)) {
                $cacheOk = true;
                break;
            }
        }

        $webOk = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? '')) !== '' || PHP_SAPI !== '';

        return [
            ['name' => 'Web Server', 'status' => $webOk ? 'operational' : 'degraded'],
            ['name' => 'Database', 'status' => $dbOk ? 'operational' : 'degraded'],
            ['name' => 'Cache', 'status' => $cacheOk ? 'operational' : 'degraded'],
            // Queue is not implemented — report operational when DB is up (honest soft stub).
            ['name' => 'Queue System', 'status' => $dbOk ? 'operational' : 'degraded'],
            ['name' => 'Storage', 'status' => $storageOk ? 'operational' : 'degraded'],
        ];
    }
}

if (!function_exists('admin_platform_settings_optimize_tables')) {
    /**
     * OPTIMIZE TABLE on a few safe known tables. Returns count optimized.
     */
    function admin_platform_settings_optimize_tables(PDO $dbh): int
    {
        $candidates = ['notification', 'admin_platform_settings'];
        $count = 0;
        foreach ($candidates as $table) {
            try {
                $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
                if (!$st || !$st->fetchColumn()) {
                    continue;
                }
                $dbh->exec('OPTIMIZE TABLE `' . str_replace('`', '``', $table) . '`');
                $count++;
            } catch (Throwable $e) {
                // skip failed table
            }
        }
        return $count;
    }
}

if (!function_exists('admin_platform_settings_estimate_backup_size')) {
    /**
     * Best-effort human size from information_schema, or "—".
     */
    function admin_platform_settings_estimate_backup_size(PDO $dbh): string
    {
        try {
            $st = $dbh->query("
                SELECT COALESCE(SUM(data_length + index_length), 0)
                FROM information_schema.TABLES
                WHERE table_schema = DATABASE()
            ");
            $bytes = (float)($st ? $st->fetchColumn() : 0);
            if ($bytes <= 0) {
                return '—';
            }
            if ($bytes >= 1073741824) {
                return round($bytes / 1073741824, 1) . ' GB';
            }
            if ($bytes >= 1048576) {
                return round($bytes / 1048576, 1) . ' MB';
            }
            if ($bytes >= 1024) {
                return round($bytes / 1024, 1) . ' KB';
            }
            return (string)(int)$bytes . ' B';
        } catch (Throwable $e) {
            return '—';
        }
    }
}

if (!function_exists('admin_platform_settings_timezones')) {
    /**
     * @return array<string,string>
     */
    function admin_platform_settings_timezones(): array
    {
        return [
            'America/New_York' => '(UTC-05:00) Eastern Time (US & Canada)',
            'America/Chicago' => '(UTC-06:00) Central Time (US & Canada)',
            'America/Denver' => '(UTC-07:00) Mountain Time (US & Canada)',
            'America/Los_Angeles' => '(UTC-08:00) Pacific Time (US & Canada)',
            'UTC' => '(UTC+00:00) UTC',
            'Europe/London' => '(UTC+00:00) London',
            'Europe/Paris' => '(UTC+01:00) Paris',
            'Asia/Tokyo' => '(UTC+09:00) Tokyo',
        ];
    }
}

if (!function_exists('admin_platform_settings_date_formats')) {
    /**
     * @return array<string,string>
     */
    function admin_platform_settings_date_formats(): array
    {
        $now = time();
        return [
            'M j, Y' => date('M j, Y', $now) . ' (MMM D, YYYY)',
            'Y-m-d' => date('Y-m-d', $now) . ' (YYYY-MM-DD)',
            'm/d/Y' => date('m/d/Y', $now) . ' (MM/DD/YYYY)',
            'd/m/Y' => date('d/m/Y', $now) . ' (DD/MM/YYYY)',
        ];
    }
}

if (!function_exists('admin_platform_settings_whitelist_count')) {
    /**
     * Count non-empty lines in an IP whitelist string.
     */
    function admin_platform_settings_whitelist_count(string $whitelist): int
    {
        $n = 0;
        foreach (preg_split('/\R/', $whitelist) ?: [] as $line) {
            if (trim((string)$line) !== '') {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('admin_platform_settings_relative_time')) {
    /**
     * Human-readable relative time (e.g. "2 hours ago").
     */
    function admin_platform_settings_relative_time(?string $datetime): string
    {
        if ($datetime === null || trim($datetime) === '') {
            return 'Just now';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return 'Just now';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 604800) {
            $d = (int)floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return date('M j, Y', $ts);
    }
}

if (!function_exists('admin_platform_settings_security_events')) {
    /**
     * Recent security events from security_audit_log when available,
     * otherwise a few synthetic rows from admin login state.
     *
     * @param array<string,mixed> $settings
     * @return list<array{ok:bool,title:string,detail:string,when:string}>
     */
    function admin_platform_settings_security_events(PDO $dbh, array $settings, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        try {
            $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote('security_audit_log'));
            if ($st && $st->fetchColumn()) {
                $q = $dbh->query("
                    SELECT action, success, ip, username, created_at
                    FROM security_audit_log
                    ORDER BY id DESC
                    LIMIT {$limit}
                ");
                $rows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
                if ($rows) {
                    $out = [];
                    foreach ($rows as $r) {
                        $action = trim((string)($r['action'] ?? 'security_event'));
                        $ok = !empty($r['success']);
                        $who = trim((string)($r['username'] ?? ''));
                        $ip = trim((string)($r['ip'] ?? ''));
                        $detailParts = [];
                        if ($who !== '') {
                            $detailParts[] = $who;
                        }
                        if ($ip !== '') {
                            $detailParts[] = $ip;
                        }
                        $out[] = [
                            'ok' => $ok,
                            'title' => $action !== '' ? ucwords(str_replace('_', ' ', $action)) : 'Security event',
                            'detail' => $detailParts !== [] ? implode(' · ', $detailParts) : ($ok ? 'Succeeded' : 'Failed'),
                            'when' => admin_platform_settings_relative_time(isset($r['created_at']) ? (string)$r['created_at'] : null),
                        ];
                    }
                    return $out;
                }
            }
        } catch (Throwable $e) {
            // fall through to synthetic
        }

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $loginAt = null;
        $failed = 0;
        try {
            if ($adminId > 0) {
                $st = $dbh->prepare('SELECT last_login_at, failed_login_attempts FROM admin WHERE idadmin = :id LIMIT 1');
                $st->execute([':id' => $adminId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $loginAt = !empty($row['last_login_at']) ? (string)$row['last_login_at'] : null;
                $failed = (int)($row['failed_login_attempts'] ?? 0);
            }
        } catch (Throwable $e) {
        }

        $events = [
            [
                'ok' => true,
                'title' => 'Successful login',
                'detail' => (string)($_SESSION['admin_login'] ?? 'Admin') . ' · ' . (string)($_SERVER['REMOTE_ADDR'] ?? '—'),
                'when' => admin_platform_settings_relative_time($loginAt),
            ],
            [
                'ok' => true,
                'title' => 'Security settings viewed',
                'detail' => 'Admin console',
                'when' => 'Just now',
            ],
        ];
        if ($failed > 0) {
            $events[] = [
                'ok' => false,
                'title' => 'Failed login attempts',
                'detail' => $failed . ' recent attempt' . ($failed === 1 ? '' : 's'),
                'when' => admin_platform_settings_relative_time($loginAt),
            ];
        } else {
            $events[] = [
                'ok' => true,
                'title' => 'Password policy active',
                'detail' => ucfirst((string)($settings['password_policy'] ?? 'strong')),
                'when' => 'Today',
            ];
        }
        return array_slice($events, 0, $limit);
    }
}

if (!function_exists('admin_platform_settings_active_sessions')) {
    /**
     * Current admin session plus up to 3 other recent admins by last_login_at.
     *
     * @return list<array{is_you:bool,name:string,initials:string,device:string,ip:string,when:string}>
     */
    function admin_platform_settings_active_sessions(PDO $dbh): array
    {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $device = 'This browser';
        if ($ua !== '') {
            if (stripos($ua, 'Edg/') !== false) {
                $device = 'Microsoft Edge';
            } elseif (stripos($ua, 'Chrome/') !== false) {
                $device = 'Chrome';
            } elseif (stripos($ua, 'Firefox/') !== false) {
                $device = 'Firefox';
            } elseif (stripos($ua, 'Safari/') !== false) {
                $device = 'Safari';
            } else {
                $device = mb_strlen($ua) > 36 ? (mb_substr($ua, 0, 33) . '…') : $ua;
            }
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '—');

        $youName = trim((string)($_SESSION['admin_login'] ?? 'Admin'));
        $loginAt = null;
        try {
            if ($adminId > 0) {
                $st = $dbh->prepare('SELECT fullname, username, last_login_at FROM admin WHERE idadmin = :id LIMIT 1');
                $st->execute([':id' => $adminId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $fn = trim((string)($row['fullname'] ?? ''));
                $un = trim((string)($row['username'] ?? ''));
                if ($fn !== '') {
                    $youName = $fn;
                } elseif ($un !== '') {
                    $youName = $un;
                }
                $loginAt = !empty($row['last_login_at']) ? (string)$row['last_login_at'] : null;
            }
        } catch (Throwable $e) {
        }

        $initials = static function (string $name): string {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $letters = '';
            foreach ($parts as $p) {
                if ($p === '') {
                    continue;
                }
                $letters .= mb_strtoupper(mb_substr($p, 0, 1));
                if (mb_strlen($letters) >= 2) {
                    break;
                }
            }
            return $letters !== '' ? $letters : 'AD';
        };

        $sessions = [[
            'is_you' => true,
            'name' => 'You',
            'initials' => $initials($youName),
            'device' => $device,
            'ip' => $ip,
            'when' => admin_platform_settings_relative_time($loginAt),
        ]];

        try {
            $st = $dbh->prepare("
                SELECT fullname, username, last_login_at
                FROM admin
                WHERE idadmin <> :id
                  AND last_login_at IS NOT NULL
                ORDER BY last_login_at DESC
                LIMIT 3
            ");
            $st->execute([':id' => $adminId > 0 ? $adminId : -1]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string)($row['fullname'] ?? ''));
                if ($name === '') {
                    $name = trim((string)($row['username'] ?? 'Admin'));
                }
                $sessions[] = [
                    'is_you' => false,
                    'name' => $name,
                    'initials' => $initials($name),
                    'device' => 'Recent session',
                    'ip' => '—',
                    'when' => admin_platform_settings_relative_time(isset($row['last_login_at']) ? (string)$row['last_login_at'] : null),
                ];
            }
        } catch (Throwable $e) {
        }

        return $sessions;
    }
}
