<?php
declare(strict_types=1);

/**
 * Helpers for Settings → API (keys, rate limits, allowlist, webhooks).
 */

if (!function_exists('admin_api_settings_h')) {
    function admin_api_settings_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_api_settings_ensure_table')) {
    function admin_api_settings_ensure_table(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS admin_api_keys (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(120) NOT NULL,
              key_prefix VARCHAR(32) NOT NULL,
              key_last4 VARCHAR(8) NOT NULL,
              key_hash VARCHAR(64) NOT NULL,
              permissions VARCHAR(64) NOT NULL DEFAULT 'read',
              status VARCHAR(20) NOT NULL DEFAULT 'active',
              created_by INT NULL,
              created_by_label VARCHAR(80) NULL,
              last_used_at DATETIME NULL,
              created_at DATETIME NULL,
              revoked_at DATETIME NULL,
              KEY idx_api_status (status),
              KEY idx_api_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    }
}

if (!function_exists('admin_api_settings_normalize_permissions')) {
    function admin_api_settings_normalize_permissions(string $permissions): string
    {
        $p = strtolower(trim($permissions));
        if ($p === 'read,write' || $p === 'write' || $p === 'read_write' || $p === 'rw') {
            return 'read,write';
        }
        return 'read';
    }
}

if (!function_exists('admin_api_settings_normalize_status')) {
    function admin_api_settings_normalize_status(string $status): string
    {
        $s = strtolower(trim($status));
        return in_array($s, ['active', 'inactive', 'revoked'], true) ? $s : 'active';
    }
}

if (!function_exists('admin_api_settings_mask_key')) {
    function admin_api_settings_mask_key(string $prefix, string $last4, bool $reveal = false): string
    {
        $prefix = trim($prefix);
        $last4 = trim($last4);
        if ($reveal) {
            return $prefix . '••••••••••••••••' . $last4;
        }
        return $prefix . '••••••••' . $last4;
    }
}

if (!function_exists('admin_api_settings_generate_plaintext')) {
    /**
     * @return array{plaintext:string,prefix:string,last4:string,hash:string}
     */
    function admin_api_settings_generate_plaintext(): array
    {
        $prefix = 'sk_live_';
        $body = bin2hex(random_bytes(16));
        $plaintext = $prefix . $body;
        return [
            'plaintext' => $plaintext,
            'prefix' => $prefix,
            'last4' => substr($body, -4),
            'hash' => hash('sha256', $plaintext),
        ];
    }
}

if (!function_exists('admin_api_settings_list_keys')) {
    /**
     * @param array{q?:string,status?:string,page?:int,per?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,per:int,pages:int}
     */
    function admin_api_settings_list_keys(PDO $dbh, array $filters = []): array
    {
        admin_api_settings_ensure_table($dbh);
        $q = trim((string)($filters['q'] ?? ''));
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if ($status === 'all') {
            $status = '';
        }
        if ($status !== '' && !in_array($status, ['active', 'inactive', 'revoked'], true)) {
            $status = '';
        }
        $page = max(1, (int)($filters['page'] ?? 1));
        $per = (int)($filters['per'] ?? 10);
        if (!in_array($per, [10, 25], true)) {
            $per = 10;
        }

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(name LIKE :q OR key_prefix LIKE :q2 OR key_last4 LIKE :q3 OR created_by_label LIKE :q4 OR permissions LIKE :q5)';
            $like = '%' . $q . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
        }
        if ($status !== '') {
            $where[] = 'status = :st';
            $params[':st'] = $status;
        }
        $sqlWhere = implode(' AND ', $where);

        $total = 0;
        try {
            $st = $dbh->prepare("SELECT COUNT(*) FROM admin_api_keys WHERE {$sqlWhere}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $total = 0;
        }

        $pages = max(1, (int)ceil($total / $per));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $per;

        $rows = [];
        try {
            $st = $dbh->prepare("
                SELECT id, name, key_prefix, key_last4, permissions, status,
                       created_by, created_by_label, last_used_at, created_at, revoked_at
                FROM admin_api_keys
                WHERE {$sqlWhere}
                ORDER BY created_at DESC, id DESC
                LIMIT {$per} OFFSET {$offset}
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per' => $per,
            'pages' => $pages,
        ];
    }
}

if (!function_exists('admin_api_settings_create_key')) {
    /**
     * @return array{row:array<string,mixed>,plaintext:string}
     */
    function admin_api_settings_create_key(
        PDO $dbh,
        string $name,
        string $permissions,
        ?int $adminId,
        ?string $adminLabel
    ): array {
        admin_api_settings_ensure_table($dbh);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('API key name is required.');
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }
        $perms = admin_api_settings_normalize_permissions($permissions);
        $gen = admin_api_settings_generate_plaintext();
        $label = $adminLabel !== null ? trim($adminLabel) : '';
        if ($label === '') {
            $label = null;
        } elseif (mb_strlen($label) > 80) {
            $label = mb_substr($label, 0, 80);
        }

        $st = $dbh->prepare('
            INSERT INTO admin_api_keys
              (name, key_prefix, key_last4, key_hash, permissions, status,
               created_by, created_by_label, last_used_at, created_at, revoked_at)
            VALUES
              (:name, :prefix, :last4, :hash, :perms, \'active\',
               :by, :label, NULL, NOW(), NULL)
        ');
        $st->execute([
            ':name' => $name,
            ':prefix' => $gen['prefix'],
            ':last4' => $gen['last4'],
            ':hash' => $gen['hash'],
            ':perms' => $perms,
            ':by' => ($adminId !== null && $adminId > 0) ? $adminId : null,
            ':label' => $label,
        ]);
        $id = (int)$dbh->lastInsertId();

        $row = [
            'id' => $id,
            'name' => $name,
            'key_prefix' => $gen['prefix'],
            'key_last4' => $gen['last4'],
            'permissions' => $perms,
            'status' => 'active',
            'created_by' => ($adminId !== null && $adminId > 0) ? $adminId : null,
            'created_by_label' => $label,
            'last_used_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'revoked_at' => null,
        ];

        return ['row' => $row, 'plaintext' => $gen['plaintext']];
    }
}

if (!function_exists('admin_api_settings_set_status')) {
    function admin_api_settings_set_status(PDO $dbh, int $id, string $status): bool
    {
        admin_api_settings_ensure_table($dbh);
        if ($id <= 0) {
            return false;
        }
        $status = admin_api_settings_normalize_status($status);
        $st = $dbh->prepare('
            UPDATE admin_api_keys
            SET status = :st,
                revoked_at = CASE
                  WHEN :st2 = \'revoked\' THEN COALESCE(revoked_at, NOW())
                  ELSE NULL
                END
            WHERE id = :id
        ');
        $st->execute([
            ':st' => $status,
            ':st2' => $status,
            ':id' => $id,
        ]);
        return $st->rowCount() > 0;
    }
}

if (!function_exists('admin_api_settings_delete_key')) {
    function admin_api_settings_delete_key(PDO $dbh, int $id): bool
    {
        admin_api_settings_ensure_table($dbh);
        if ($id <= 0) {
            return false;
        }
        $st = $dbh->prepare('DELETE FROM admin_api_keys WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->rowCount() > 0;
    }
}

if (!function_exists('admin_api_settings_revoke_key')) {
    function admin_api_settings_revoke_key(PDO $dbh, int $id): bool
    {
        return admin_api_settings_set_status($dbh, $id, 'revoked');
    }
}

if (!function_exists('admin_api_settings_allowlist_count')) {
    function admin_api_settings_allowlist_count(string $allowlist): int
    {
        $n = 0;
        foreach (preg_split('/\R+/', $allowlist) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '' && $line[0] !== '#') {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('admin_api_settings_list_webhooks')) {
    /**
     * @param array<string,mixed> $settings
     * @return list<array{id:string,name:string,url:string,events:string,status:string}>
     */
    function admin_api_settings_list_webhooks(array $settings): array
    {
        $raw = $settings['api_webhooks'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $i => $wh) {
            if (!is_array($wh)) {
                continue;
            }
            $out[] = [
                'id' => (string)($wh['id'] ?? ('wh_' . $i)),
                'name' => (string)($wh['name'] ?? 'Webhook'),
                'url' => (string)($wh['url'] ?? ''),
                'events' => (string)($wh['events'] ?? ''),
                'status' => (string)($wh['status'] ?? 'active'),
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_api_settings_save_webhooks')) {
    /**
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $webhooks
     */
    function admin_api_settings_save_webhooks(array &$settings, array $webhooks): void
    {
        $clean = [];
        foreach ($webhooks as $wh) {
            if (!is_array($wh)) {
                continue;
            }
            $name = trim((string)($wh['name'] ?? ''));
            $url = trim((string)($wh['url'] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }
            $clean[] = [
                'id' => (string)($wh['id'] ?? ('wh_' . bin2hex(random_bytes(4)))),
                'name' => mb_substr($name, 0, 120),
                'url' => mb_substr($url, 0, 500),
                'events' => mb_substr(trim((string)($wh['events'] ?? '')), 0, 200),
                'status' => in_array(($wh['status'] ?? 'active'), ['active', 'inactive'], true)
                    ? (string)$wh['status']
                    : 'active',
            ];
        }
        $settings['api_webhooks'] = $clean;
    }
}

if (!function_exists('admin_api_settings_add_webhook')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_api_settings_add_webhook(array &$settings, string $name, string $url, string $events): bool
    {
        $list = admin_api_settings_list_webhooks($settings);
        $name = trim($name);
        $url = trim($url);
        if ($name === '' || $url === '') {
            return false;
        }
        $list[] = [
            'id' => 'wh_' . bin2hex(random_bytes(4)),
            'name' => $name,
            'url' => $url,
            'events' => trim($events),
            'status' => 'active',
        ];
        admin_api_settings_save_webhooks($settings, $list);
        return true;
    }
}

if (!function_exists('admin_api_settings_delete_webhook')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_api_settings_delete_webhook(array &$settings, string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        $list = admin_api_settings_list_webhooks($settings);
        $next = [];
        $found = false;
        foreach ($list as $wh) {
            if (($wh['id'] ?? '') === $id) {
                $found = true;
                continue;
            }
            $next[] = $wh;
        }
        if (!$found) {
            return false;
        }
        admin_api_settings_save_webhooks($settings, $next);
        return true;
    }
}

if (!function_exists('admin_api_settings_save_limits')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_api_settings_save_limits(array &$settings, int $dailyLimit): void
    {
        $settings['api_daily_limit'] = max(100, min(10000000, $dailyLimit));
    }
}

if (!function_exists('admin_api_settings_save_allowlist')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_api_settings_save_allowlist(array &$settings, string $allowlist): void
    {
        $settings['api_ip_allowlist'] = trim($allowlist);
    }
}

if (!function_exists('admin_api_settings_bump_demo_requests')) {
    /**
     * Light demo counter bump on check/create actions.
     *
     * @param array<string,mixed> $settings
     */
    function admin_api_settings_bump_demo_requests(array &$settings, int $n = 1): void
    {
        $today = (int)($settings['api_requests_today'] ?? 0);
        $settings['api_requests_today'] = max(0, $today + max(1, $n));
    }
}

if (!function_exists('admin_api_settings_count_stats')) {
    /**
     * @param array<string,mixed> $settings
     * @return array{
     *   total:int,active:int,inactive:int,revoked:int,
     *   active_pct:int,requests_today:int,requests_yesterday:int,
     *   requests_delta:int,daily_limit:int,rate_usage_pct:int,
     *   remaining:int,allowlist_count:int,delta_keys_month:int
     * }
     */
    function admin_api_settings_count_stats(PDO $dbh, array $settings): array
    {
        admin_api_settings_ensure_table($dbh);
        $total = 0;
        $active = 0;
        $inactive = 0;
        $revoked = 0;
        $deltaMonth = 0;
        try {
            $st = $dbh->query("
                SELECT
                  COUNT(*) AS total,
                  SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_n,
                  SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_n,
                  SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked_n,
                  SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN 1 ELSE 0 END) AS month_n
                FROM admin_api_keys
            ");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row)) {
                $total = (int)($row['total'] ?? 0);
                $active = (int)($row['active_n'] ?? 0);
                $inactive = (int)($row['inactive_n'] ?? 0);
                $revoked = (int)($row['revoked_n'] ?? 0);
                $deltaMonth = (int)($row['month_n'] ?? 0);
            }
        } catch (Throwable $e) {
        }

        $dailyLimit = max(1, (int)($settings['api_daily_limit'] ?? 100000));
        $reqToday = (int)($settings['api_requests_today'] ?? 0);
        $reqYday = (int)($settings['api_requests_yesterday'] ?? 0);

        // Demo metrics when counters are empty: derive lightly from key counts.
        if ($reqToday <= 0 && $total > 0) {
            $reqToday = max(12, $active * 37 + $total * 11);
        }
        if ($reqYday <= 0 && $total > 0) {
            $reqYday = max(8, (int)round($reqToday * 0.86));
        }

        $usagePct = (int)min(100, round(($reqToday / $dailyLimit) * 100));
        $remaining = max(0, $dailyLimit - $reqToday);
        $activePct = $total > 0 ? (int)round(($active / $total) * 100) : 0;
        $allowlist = (string)($settings['api_ip_allowlist'] ?? '');

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'revoked' => $revoked,
            'active_pct' => $activePct,
            'requests_today' => $reqToday,
            'requests_yesterday' => $reqYday,
            'requests_delta' => $reqToday - $reqYday,
            'daily_limit' => $dailyLimit,
            'rate_usage_pct' => $usagePct,
            'remaining' => $remaining,
            'allowlist_count' => admin_api_settings_allowlist_count($allowlist),
            'delta_keys_month' => $deltaMonth,
        ];
    }
}

if (!function_exists('admin_api_settings_format_dt')) {
    function admin_api_settings_format_dt(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return 'Never';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('M j, Y g:i A', $ts) : $raw;
    }
}

if (!function_exists('admin_api_settings_format_number')) {
    function admin_api_settings_format_number(int $n): string
    {
        return number_format($n);
    }
}
