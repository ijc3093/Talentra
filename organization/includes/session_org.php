<?php
// /Business_only3/organization/includes/session_org.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $bootstrapLoad = __DIR__ . '/../../admin/includes/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
        require_once $bootstrapLoad;
        // Default PHPSESSID — set cookie path before start (name already default).
        admin_linked_apply_session_cookie_path();
    }
    session_start();
}

// ✅ Login guard + helpers (orgAccountType(), orgAccountId(), isOrgManager(), etc.)
require_once __DIR__ . '/session_org_login.php';
orgRequireLoginOnly();

require_once __DIR__ . '/../../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();
require_once __DIR__ . '/org_publisher_access.php';
require_once __DIR__ . '/org_theme_prefs.php';
org_theme_sync_session_publisher($dbh);

/* =========================
   Session helpers
   ========================= */
function clearOrgSession(): void {
    unset(
        $_SESSION['org_auth'],
        $_SESSION['org_account_type'],
        $_SESSION['org_account_id'],
        $_SESSION['org_active_org_id'],
        $_SESSION['org_member_id'],
        $_SESSION['org_role_id'],
        $_SESSION['org_publisher_user_id']
    );
}

function orgMemberId(): int    { return (int)($_SESSION['org_member_id'] ?? 0); }
function orgRoleId(): int      { return (int)($_SESSION['org_role_id'] ?? 0); }

/* =========================
   Ensure org selected
   ========================= */
function ensureOrgSelected(PDO $dbh): void {
    $orgId = orgActiveOrgId();
    $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $adminOversight = function_exists('admin_linked_is_org_admin_oversight') && admin_linked_is_org_admin_oversight();

    // Platform admin opening a product/order: bind active org to that record's seller org.
    if ($adminOversight && in_array($cur, ['products_detail.php', 'inventory_detail.php', 'order_details.php'], true)) {
        $lookupId = (int)($_GET['id'] ?? 0);
        if ($lookupId > 0) {
            try {
                if ($cur === 'order_details.php') {
                    $stProd = $dbh->prepare('
                        SELECT org_id
                        FROM org_orders
                        WHERE id = :id
                        LIMIT 1
                    ');
                } else {
                    $stProd = $dbh->prepare('
                        SELECT org_id
                        FROM org_products
                        WHERE id = :id AND COALESCE(is_deleted, 0) = 0
                        LIMIT 1
                    ');
                }
                $stProd->execute([':id' => $lookupId]);
                $productOrgId = (int)($stProd->fetchColumn() ?: 0);
                if ($productOrgId > 0) {
                    $_SESSION['org_active_org_id'] = $productOrgId;
                    $orgId = $productOrgId;
                }
            } catch (Throwable $e) {
                // Non-fatal — fall through to normal selection rules.
            }
        }
    }

    // pages allowed without org selected
    $allowedNoOrg = [
        'login.php',
        'select_org.php',
        'create_org.php',
        'switch_org.php',
        'logout.php'
    ];

    if ($orgId <= 0) {
        if (isOrgManager()) {
            $managerId = (int)orgAccountId();
            if ($managerId > 0 && org_manager_is_registered_publisher($dbh, $managerId)) {
                org_manager_apply_registered_publisher_login($dbh, $managerId);
                publisher_session_establish_for_manager($dbh, $managerId);
                if (orgActiveOrgId() > 0) {
                    return;
                }
            }

            if (in_array($cur, $allowedNoOrg, true)) {
                return;
            }
            // Admin oversight on product/order detail: don't force select_org.
            if ($adminOversight && in_array($cur, ['products_detail.php', 'inventory_detail.php', 'order_details.php'], true)) {
                return;
            }
            header("Location: select_org.php");
            exit;
        }

        clearOrgSession();
        header("Location: login.php?e=org");
        exit;
    }

    // ensure org exists + active
    $st = $dbh->prepare("SELECT status FROM organizations WHERE id = :id LIMIT 1");
    $st->execute([':id' => $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['status'] !== 1) {
        if ($adminOversight && in_array($cur, ['products_detail.php', 'inventory_detail.php', 'order_details.php'], true)) {
            return;
        }
        clearOrgSession();
        header("Location: login.php?e=org_disabled");
        exit;
    }
}

/* =========================
   Default roles auto-heal
   ========================= */
function ensureDefaultOrgRoles(PDO $dbh, int $orgId): array {
    $want = ['Manager','Staff'];
    $ids = [];

    foreach ($want as $name) {
        $st = $dbh->prepare(
            "SELECT id FROM org_roles WHERE org_id = :org AND name = :name LIMIT 1"
        );
        $st->execute([':org' => $orgId, ':name' => $name]);
        $id = (int)($st->fetchColumn() ?: 0);

        if ($id <= 0) {
            $ins = $dbh->prepare("
                INSERT INTO org_roles (org_id, name, is_system, created_at)
                VALUES (:org, :name, 1, NOW())
            ");
            $ins->execute([':org' => $orgId, ':name' => $name]);
            $id = (int)$dbh->lastInsertId();
        }

        $ids[strtolower($name)] = $id;
    }

    return $ids;
}

/* =========================
   organization_users auto-heal
   ========================= */
function ensureOrganizationUsersRow(
    PDO $dbh,
    int $orgId,
    int $orgMemberId,
    string $role
): void {
    if (!in_array($role, ['admin','manager','staff'], true)) {
        $role = 'staff';
    }

    $st = $dbh->prepare("
        INSERT INTO organization_users (org_id, user_id, role, joined_at)
        VALUES (:o, :u, :r, NOW())
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ");
    $st->execute([
        ':o' => $orgId,
        ':u' => $orgMemberId,
        ':r' => $role
    ]);
}

/* =========================
   Ensure org membership
   ========================= */
function ensureOrgMembership(PDO $dbh): void {
    // Platform admin may view any seller product without belonging to that org.
    if (function_exists('admin_linked_is_org_admin_oversight') && admin_linked_is_org_admin_oversight()) {
        if (orgMemberId() <= 0) {
            $_SESSION['org_member_id'] = 0;
            $_SESSION['org_role_id'] = 0;
        }
        return;
    }

    if (orgMemberId() > 0 && orgRoleId() > 0) {
        return;
    }

    $orgId = orgActiveOrgId();
    $acctType = (string)orgAccountType(); // manager | staff
    $acctId   = (int)orgAccountId();      // managers.id | staff_accounts.id

    if ($orgId <= 0 || $acctId <= 0) {
        clearOrgSession();
        header("Location: login.php?e=org");
        exit;
    }

    try {
        $didFix = false;

        // ensure roles exist
        $roles = ensureDefaultOrgRoles($dbh, $orgId);
        $managerRoleId = (int)($roles['manager'] ?? 0);
        $staffRoleId   = (int)($roles['staff'] ?? 0);

        // load org_members
        $st = $dbh->prepare("
            SELECT id, role_id, status
            FROM org_members
            WHERE org_id = :org
              AND member_type = :mt
              AND member_id = :mid
            LIMIT 1
        ");
        $st->execute([
            ':org' => $orgId,
            ':mt'  => $acctType,
            ':mid' => $acctId
        ]);
        $m = $st->fetch(PDO::FETCH_ASSOC);

        // auto-create for manager
        if (!$m && $acctType === 'manager') {
            if ($managerRoleId <= 0) {
                throw new RuntimeException('Manager role missing.');
            }

            $ins = $dbh->prepare("
                INSERT IGNORE INTO org_members
                  (org_id, member_type, member_id, role_id, relationship_label, status, joined_at, created_at)
                VALUES
                  (:org, 'manager', :mid, :role, NULL, 1, NOW(), NOW())
            ");
            $ins->execute([
                ':org'  => $orgId,
                ':mid'  => $acctId,
                ':role' => $managerRoleId
            ]);

            $didFix = true;

            $st->execute([
                ':org' => $orgId,
                ':mt'  => $acctType,
                ':mid' => $acctId
            ]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
        }

        if (!$m || (int)$m['status'] !== 1) {
            clearOrgSession();
            header("Location: login.php?e=not_member");
            exit;
        }

        $_SESSION['org_member_id'] = (int)$m['id'];
        $_SESSION['org_role_id']   = (int)$m['role_id'];

        // map role enum
        $roleEnum = 'staff';
        if ($acctType === 'manager') {
            $roleEnum = 'manager';
        }
        if ($managerRoleId > 0 && (int)$m['role_id'] === $managerRoleId) {
            $roleEnum = 'manager';
        }
        if ($staffRoleId > 0 && (int)$m['role_id'] === $staffRoleId) {
            $roleEnum = 'staff';
        }

        ensureOrganizationUsersRow($dbh, $orgId, (int)$m['id'], $roleEnum);
        $didFix = true;

        if ($didFix) {
            $_SESSION['org_flash'] = 'Membership repaired automatically for this organization.';
        }

    } catch (Throwable $e) {
        clearOrgSession();
        header("Location: login.php?e=org_error");
        exit;
    }
}

/* =========================
   Apply guards
   ========================= */
ensureOrgSelected($dbh);
ensureOrgMembership($dbh);
