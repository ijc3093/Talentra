<?php
// /Business_only3/organization/includes/session_org.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $bootstrapLoad = __DIR__ . '/../../admin/includes/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
        require_once $bootstrapLoad;
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

    // pages allowed without org selected
    $allowedNoOrg = [
        'login.php',
        'select_org.php',
        'create_org.php',
        'switch_org.php',
        'logout.php'
    ];
    $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');

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
