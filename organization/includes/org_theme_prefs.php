<?php
declare(strict_types=1);

require_once __DIR__ . '/../../public_user/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/../../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../../public_user/includes/appearance_palettes.php';
require_once __DIR__ . '/../../public_user/includes/appearance_bridge.php';
require_once __DIR__ . '/session_org_login.php';

/** Resolve linked publisher users.id from org session (never BUSINESS_ONLY_USER). */
function org_theme_resolve_publisher_user_id(PDO $dbh): int
{
    if (function_exists('isOrgManager') && isOrgManager()) {
        $managerId = orgAccountId();
        if ($managerId > 0) {
            $uid = publisher_org_manager_publisher_user_id($dbh, $managerId);
            if ($uid > 0) {
                return $uid;
            }
        }
    }

    $orgId = orgActiveOrgId();
    if ($orgId > 0) {
        $uid = staff_pub_org_publisher_user_id($dbh, $orgId);
        if ($uid > 0) {
            return $uid;
        }

        if (publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id')) {
            try {
                $st = $dbh->prepare('SELECT publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
                $st->execute([':id' => $orgId]);
                $uid = (int)($st->fetchColumn() ?: 0);
                if ($uid > 0) {
                    return $uid;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    return 0;
}

function org_theme_sync_session_publisher(PDO $dbh): void
{
    $uid = org_theme_resolve_publisher_user_id($dbh);
    if ($uid > 0) {
        $_SESSION['org_publisher_user_id'] = $uid;
    } else {
        unset($_SESSION['org_publisher_user_id']);
    }
}

function org_theme_viewer_user_id(PDO $dbh): int
{
    org_theme_sync_session_publisher($dbh);
    return (int)($_SESSION['org_publisher_user_id'] ?? 0);
}

function org_theme_appearance_mode(PDO $dbh, int $userId): string
{
    return appearance_bridge_user_mode($dbh, $userId);
}

function org_theme_js_config(PDO $dbh, int $userId): array
{
    $mode = org_theme_appearance_mode($dbh, $userId);
    $manualMode = 'dark';
    if ($mode === 'light') {
        $manualMode = 'light';
    } elseif ($mode === 'dark') {
        $manualMode = 'dark';
    } elseif ($mode !== 'system') {
        $manualMode = appearance_palette_is_dark_slug($mode) ? 'dark' : 'light';
    }

    return [
        'userId' => $userId,
        'autoEnabled' => appearance_bridge_theme_auto_enabled($dbh, $userId),
        'manualMode' => $manualMode,
        'appearanceMode' => $mode,
    ];
}

function org_theme_head_was_printed(): bool
{
    return !empty($GLOBALS['__MSB_ORG_THEME_HEAD_PRINTED']);
}

function org_theme_css_was_printed(): bool
{
    return !empty($GLOBALS['__MSB_ORG_THEME_CSS_PRINTED']);
}

function org_theme_is_named_palette(string $mode): bool
{
    return appearance_bridge_is_named_palette($mode);
}

/** @deprecated Use appearance_bridge_print_shell_critical via org_theme_print_head_bootstrap */
function org_theme_print_shell_palette_critical(PDO $dbh): void
{
    appearance_bridge_print_shell_critical($dbh, org_theme_viewer_user_id($dbh));
}

function org_theme_print_head_bootstrap(PDO $dbh): void
{
    if (!empty($GLOBALS['__MSB_ORG_THEME_HEAD_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_ORG_THEME_HEAD_PRINTED'] = true;

    $userId = org_theme_viewer_user_id($dbh);
    appearance_bridge_print_theme_stack($dbh, $userId, '../public_user/', true, true);
    $GLOBALS['__MSB_ORG_THEME_CSS_PRINTED'] = true;
}
