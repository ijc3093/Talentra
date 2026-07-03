<?php
declare(strict_types=1);

if (!function_exists('account_admin_role_label')) {
    function account_admin_role_label(?PDO $dbh, int $roleId): string
    {
        static $cache = [];

        if ($roleId <= 0) {
            return '';
        }
        if (isset($cache[$roleId])) {
            return $cache[$roleId];
        }

        $fallback = [
            1 => 'Admin',
            2 => 'Manager',
            3 => 'Gospel',
            4 => 'Staff',
            8 => 'Teacher',
        ];

        if ($dbh instanceof PDO) {
            try {
                $st = $dbh->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
                $st->execute([':id' => $roleId]);
                $name = trim((string)($st->fetchColumn() ?: ''));
                if ($name !== '') {
                    $cache[$roleId] = $name;
                    return $name;
                }
            } catch (Throwable $e) {
                // ignore and use fallback map
            }
        }

        $cache[$roleId] = (string)($fallback[$roleId] ?? '');
        return $cache[$roleId];
    }
}

if (!function_exists('account_portal_staff_role_label_from_admin')) {
    function account_portal_staff_role_label_from_admin(?PDO $dbh, int $adminId): string
    {
        if ($adminId <= 0) {
            return '';
        }

        $bootstrap = dirname(__DIR__, 2) . '/admin/includes/admin_linked_accounts_load.php';
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }
        if (!function_exists('admin_linked_fetch_admin')) {
            return '';
        }

        if (!$dbh instanceof PDO) {
            try {
                require_once dirname(__DIR__) . '/../controller.php';
                $dbh = (new Controller())->pdo();
            } catch (Throwable $e) {
                return '';
            }
        }

        $admin = admin_linked_fetch_admin($dbh, $adminId);
        if (!$admin) {
            return '';
        }

        return account_admin_role_label($dbh, (int)($admin['role'] ?? 0));
    }
}

if (!function_exists('account_portal_staff_role_label')) {
    function account_portal_staff_role_label(?PDO $dbh = null): string
    {
        $cached = trim((string)($_SESSION['portal_staff_role_label'] ?? ''));
        if ($cached !== '') {
            return $cached;
        }

        $bootstrap = dirname(__DIR__, 2) . '/admin/includes/admin_linked_bootstrap_load.php';
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }
        if (function_exists('admin_linked_read_admin_portal_intent')) {
            $intent = admin_linked_read_admin_portal_intent();
            if ($intent && (int)($intent['admin_id'] ?? 0) > 0) {
                $label = account_portal_staff_role_label_from_admin($dbh, (int)$intent['admin_id']);
                if ($label !== '') {
                    $_SESSION['portal_staff_role_label'] = $label;
                    return $label;
                }
            }
        }

        if (function_exists('staff_pub_is_staff_session') && staff_pub_is_staff_session()) {
            return 'Staff';
        }

        return '';
    }
}

if (!function_exists('account_account_kind_label')) {
    function account_account_kind_label(bool $isPublisher): string
    {
        return $isPublisher ? 'Publisher' : 'Personal';
    }
}

if (!function_exists('account_display_name_parts')) {
    function account_display_name_parts(string $rawName, bool $isPublisher, ?PDO $dbh = null): array
    {
        $rawName = trim($rawName);
        if ($rawName === '') {
            $rawName = 'My Account';
        }

        $displayName = $rawName;
        $badge = '';
        $staffRole = account_portal_staff_role_label($dbh);

        if ($staffRole !== '') {
            $badge = $staffRole . ' ' . account_account_kind_label($isPublisher);
            if ($isPublisher) {
                $suffix = ' Admin Publisher';
                if ($rawName !== '' && strcasecmp(substr($rawName, -strlen($suffix)), $suffix) === 0) {
                    $displayName = trim(substr($rawName, 0, -strlen($suffix)));
                } elseif (stripos($rawName, ' Admin Publ') !== false) {
                    $displayName = trim(preg_replace('/\s+Admin\s+Publisher\s*$/i', '', $rawName) ?? $rawName);
                } elseif (preg_match('/\s+Publisher\s*$/i', $rawName)) {
                    $displayName = trim(preg_replace('/\s+Publisher\s*$/i', '', $rawName) ?? $rawName);
                }
            }
        } elseif ($isPublisher) {
            $suffix = ' Admin Publisher';
            if ($rawName !== '' && strcasecmp(substr($rawName, -strlen($suffix)), $suffix) === 0) {
                $displayName = trim(substr($rawName, 0, -strlen($suffix)));
                $badge = 'Admin Publisher';
            } elseif (stripos($rawName, ' Admin Publ') !== false) {
                $displayName = trim(preg_replace('/\s+Admin\s+Publisher\s*$/i', '', $rawName) ?? $rawName);
                $badge = 'Admin Publisher';
            }
        }

        if ($displayName === '') {
            $displayName = $rawName;
        }

        return [
            'display_name' => $displayName,
            'badge' => $badge,
            'staff_role' => $staffRole,
            'account_kind_label' => account_account_kind_label($isPublisher),
        ];
    }
}

if (!function_exists('account_publisher_brand_label')) {
    /**
     * Org/publisher chrome label: prefer role badge over prefixed admin name.
     */
    function account_publisher_brand_label(string $rawName): string
    {
        $parts = account_display_name_parts($rawName, true);
        return $parts['badge'] !== '' ? $parts['badge'] : $parts['display_name'];
    }
}
