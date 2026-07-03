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

        if ($dbh instanceof PDO) {
            try {
                $st = $dbh->prepare('SELECT name FROM role WHERE idrole = :id LIMIT 1');
                $st->execute([':id' => $roleId]);
                $name = trim((string)($st->fetchColumn() ?: ''));
                if ($name !== '') {
                    $cache[$roleId] = $name;
                    return $name;
                }
            } catch (Throwable $e) {
                // ignore and try role helpers
            }

            $roleHelpers = dirname(__DIR__, 2) . '/admin/includes/role_helpers.php';
            if (is_file($roleHelpers)) {
                require_once $roleHelpers;
                if (function_exists('roleDisplayName')) {
                    $name = trim((string)roleDisplayName($dbh, $roleId));
                    if ($name !== '') {
                        $cache[$roleId] = $name;
                        return $name;
                    }
                }
            }
        }

        $cache[$roleId] = '';
        return '';
    }
}

if (!function_exists('account_display_resolve_db')) {
    function account_display_resolve_db(?PDO $dbh = null): ?PDO
    {
        if ($dbh instanceof PDO) {
            return $dbh;
        }
        if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) {
            return $GLOBALS['dbh'];
        }

        try {
            require_once dirname(__DIR__) . '/controller.php';
            return (new Controller())->pdo();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('account_org_member_role_label')) {
    /**
     * Org member role for public badge (relationship_label, else org_roles.name).
     */
    function account_org_member_role_label(?PDO $dbh, string $memberType, int $memberId, int $orgId = 0): string
    {
        $memberType = strtolower(trim($memberType));
        $memberId = (int)$memberId;
        $orgId = (int)$orgId;

        if ($memberId <= 0 || !in_array($memberType, ['manager', 'staff'], true)) {
            return '';
        }

        $dbh = account_display_resolve_db($dbh);
        if (!$dbh instanceof PDO) {
            return '';
        }

        static $cache = [];
        $cacheKey = $memberType . ':' . $memberId . ':' . $orgId;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $sql = '
                SELECT om.relationship_label, om.role_id, om.org_id
                FROM org_members om
                WHERE om.member_type = :mt
                  AND om.member_id = :mid
                  AND om.status = 1
            ';
            $params = [':mt' => $memberType, ':mid' => $memberId];
            if ($orgId > 0) {
                $sql .= ' AND om.org_id = :org';
                $params[':org'] = $orgId;
            }
            $sql .= ' ORDER BY om.id DESC LIMIT 1';

            $st = $dbh->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $cache[$cacheKey] = '';
                return '';
            }

            $label = trim((string)($row['relationship_label'] ?? ''));
            if ($label !== '') {
                $cache[$cacheKey] = $label;
                return $label;
            }

            $roleId = (int)($row['role_id'] ?? 0);
            $rowOrgId = (int)($row['org_id'] ?? 0);
            if ($roleId > 0 && $rowOrgId > 0) {
                $stR = $dbh->prepare('
                    SELECT name
                    FROM org_roles
                    WHERE id = :id AND org_id = :org
                    LIMIT 1
                ');
                $stR->execute([':id' => $roleId, ':org' => $rowOrgId]);
                $label = trim((string)($stR->fetchColumn() ?: ''));
            }

            $cache[$cacheKey] = $label;
            return $label;
        } catch (Throwable $e) {
            $cache[$cacheKey] = '';
            return '';
        }
    }
}

if (!function_exists('account_org_staff_role_label')) {
    function account_org_staff_role_label(?PDO $dbh, int $staffId, int $orgId = 0): string
    {
        return account_org_member_role_label($dbh, 'staff', $staffId, $orgId);
    }
}

if (!function_exists('account_portal_staff_role_label_from_linked_user')) {
    function account_portal_staff_role_label_from_linked_user(?PDO $dbh, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $dbh = account_display_resolve_db($dbh);
        if (!$dbh instanceof PDO) {
            return '';
        }

        try {
            $st = $dbh->prepare('
                SELECT role
                FROM admin
                WHERE status = 1
                  AND (linked_personal_user_id = :uid OR linked_publisher_user_id = :pub_uid)
                ORDER BY idadmin ASC
                LIMIT 1
            ');
            $st->execute([':uid' => $userId, ':pub_uid' => $userId]);
            $roleId = (int)($st->fetchColumn() ?: 0);
            if ($roleId <= 0) {
                return '';
            }

            return account_admin_role_label($dbh, $roleId);
        } catch (Throwable $e) {
            return '';
        }
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
            $dbh = account_display_resolve_db(null);
        }
        if (!$dbh instanceof PDO) {
            return '';
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
        $isStaffPubSession = !empty($_SESSION['staff_publisher_mode'])
            && (int)($_SESSION['staff_account_id'] ?? 0) > 0;
        if ($cached !== '' && !($isStaffPubSession && strcasecmp($cached, 'Staff') === 0)) {
            return $cached;
        }

        $dbh = account_display_resolve_db($dbh);

        if (!empty($_SESSION['org_auth'])) {
            $orgAccountType = strtolower(trim((string)($_SESSION['org_account_type'] ?? '')));
            $orgAccountId = (int)($_SESSION['org_account_id'] ?? 0);
            $orgId = (int)($_SESSION['org_active_org_id'] ?? 0);

            if ($orgAccountId > 0 && in_array($orgAccountType, ['manager', 'staff'], true)) {
                $label = account_org_member_role_label($dbh, $orgAccountType, $orgAccountId, $orgId);
                if ($label !== '') {
                    $_SESSION['portal_staff_role_label'] = $label;
                    return $label;
                }
            }

            $publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
            if ($publisherUserId <= 0 && $dbh instanceof PDO) {
                $managerId = (int)($_SESSION['org_account_id'] ?? 0);
                if ($managerId > 0 && function_exists('publisher_org_manager_publisher_user_id')) {
                    $bridge = dirname(__DIR__) . '/publisher_organization_bridge.php';
                    if (is_file($bridge)) {
                        require_once dirname(__DIR__) . '/publisher_accounts_load.php';
                        require_once $bridge;
                        $publisherUserId = publisher_org_manager_publisher_user_id($dbh, $managerId);
                    }
                }
            }
            if ($publisherUserId > 0) {
                $label = account_portal_staff_role_label_from_linked_user($dbh, $publisherUserId);
                if ($label !== '') {
                    $_SESSION['portal_staff_role_label'] = $label;
                    return $label;
                }
            }
            return '';
        }

        $canReadAdminIntent = !headers_sent();
        if ($canReadAdminIntent) {
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
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            $label = account_portal_staff_role_label_from_linked_user($dbh, $userId);
            if ($label !== '') {
                $_SESSION['portal_staff_role_label'] = $label;
                return $label;
            }
        }

        if (!empty($_SESSION['staff_publisher_mode']) && (int)($_SESSION['staff_account_id'] ?? 0) > 0) {
            $label = account_org_staff_role_label(
                $dbh,
                (int)($_SESSION['staff_account_id'] ?? 0),
                (int)($_SESSION['staff_org_id'] ?? 0)
            );
            if ($label !== '') {
                $_SESSION['portal_staff_role_label'] = $label;
                return $label;
            }
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
    function account_display_name_parts(string $rawName, bool $isPublisher, ?PDO $dbh = null, bool $resolveStaffRole = true): array
    {
        $rawName = trim($rawName);
        if ($rawName === '') {
            $rawName = 'My Account';
        }

        $displayName = $rawName;
        $badge = '';
        $staffRole = $resolveStaffRole ? account_portal_staff_role_label($dbh) : '';

        if ($staffRole !== '') {
            $badge = account_account_kind_label($isPublisher) . ' ' . $staffRole;
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
    function account_publisher_brand_label(string $rawName, ?PDO $dbh = null, bool $resolveStaffRole = true): string
    {
        $parts = account_display_name_parts($rawName, true, $dbh, $resolveStaffRole);
        return $parts['badge'] !== '' ? $parts['badge'] : $parts['display_name'];
    }
}

if (!function_exists('account_org_display_label')) {
    /** Organization name only — strips admin suffix, never substitutes staff role badge. */
    function account_org_display_label(string $rawName): string
    {
        return account_display_name_parts($rawName, true, null, false)['display_name'];
    }
}
