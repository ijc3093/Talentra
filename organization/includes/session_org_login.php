<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $bootstrapLoad = __DIR__ . '/../../admin/includes/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
        require_once $bootstrapLoad;
        admin_linked_apply_session_cookie_path();
    }
    session_start();
}

function orgRequireLoginOnly(): void {
    if (empty($_SESSION['org_auth']) || empty($_SESSION['org_account_type']) || empty($_SESSION['org_account_id'])) {
        try {
            require_once __DIR__ . '/../../admin/includes/admin_linked_bootstrap_load.php';
            $bootstrapped = admin_linked_try_bootstrap_org();
            if (!$bootstrapped && function_exists('admin_linked_try_bootstrap_org_admin_oversight')) {
                $bootstrapped = admin_linked_try_bootstrap_org_admin_oversight();
            }
            if (!$bootstrapped) {
                header("Location: login.php");
                exit;
            }
        } catch (Throwable $e) {
            header("Location: login.php");
            exit;
        }
    } elseif (function_exists('admin_linked_platform_admin_snapshot') && function_exists('admin_linked_mark_org_admin_oversight')) {
        // Already in an org session — still mark oversight when platform admin is signed in.
        try {
            require_once __DIR__ . '/../../admin/includes/admin_linked_bootstrap_load.php';
            $snap = admin_linked_platform_admin_snapshot();
            if ($snap) {
                admin_linked_mark_org_admin_oversight((int)$snap['admin_id']);
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
}

function orgRequireManagerOnly(): void {
    orgRequireLoginOnly();
    if (($_SESSION['org_account_type'] ?? '') !== 'manager') {
        header("Location: feed.php");
        exit;
    }
}


function orgAccountType(): string { return (string)($_SESSION['org_account_type'] ?? ''); }
function orgAccountId(): int { return (int)($_SESSION['org_account_id'] ?? 0); }
function isOrgManager(): bool { return orgAccountType() === 'manager'; }
function isOrgStaff(): bool { return orgAccountType() === 'staff'; }
function orgActiveOrgId(): int { return (int)($_SESSION['org_active_org_id'] ?? 0); }
