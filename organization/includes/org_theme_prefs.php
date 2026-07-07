<?php
declare(strict_types=1);

require_once __DIR__ . '/../../public_user/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/../../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../../public_user/includes/appearance_palettes.php';

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
    if ($userId <= 0) {
        return 'system';
    }

    appearance_palette_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('SELECT appearance_mode FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $mode = trim((string)($st->fetchColumn() ?: ''));
        return appearance_palette_normalize_mode($mode);
    } catch (Throwable $e) {
        // fall through
    }

    return 'system';
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
        'autoEnabled' => ($mode === 'system'),
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
    $mode = appearance_palette_normalize_mode($mode);
    return !in_array($mode, ['system', 'light', 'dark'], true);
}

/** Paint org shell chrome from DB before JS/CSS load (sidebar, header, body). */
function org_theme_print_shell_palette_critical(PDO $dbh): void
{
    if (!empty($GLOBALS['__MSB_ORG_SHELL_PALETTE_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_ORG_SHELL_PALETTE_PRINTED'] = true;

    $userId = org_theme_viewer_user_id($dbh);
    if ($userId <= 0) {
        return;
    }

    $mode = org_theme_appearance_mode($dbh, $userId);
    if (!org_theme_is_named_palette($mode)) {
        return;
    }

    $hex = appearance_palette_hex_for_slug($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : '#0f172a';
    $modeJson = json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $hexAttr = htmlspecialchars($hex, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    echo '<script>document.documentElement.setAttribute("data-msb-appearance",' . $modeJson . ');</script>' . "\n";
    echo '<style id="org-shell-palette-critical">'
        . 'html[data-msb-appearance]{--msb-palette-bg:' . $hexAttr . ';--org-bg:' . $hexAttr . ';--msb-palette-text:' . $textAttr . ';}'
        . 'html[data-msb-appearance],html[data-msb-appearance] body,'
        . 'html[data-msb-appearance] .sh-sideleft-menu,'
        . 'html[data-msb-appearance] .sh-logopanel,'
        . 'html[data-msb-appearance] .sh-headpanel{'
        . 'background-color:var(--msb-palette-bg)!important;background-image:none!important;'
        . 'color:var(--msb-palette-text)!important;}'
        . '</style>' . "\n";
}

function org_theme_print_head_bootstrap(PDO $dbh): void
{
    if (!empty($GLOBALS['__MSB_ORG_THEME_HEAD_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_ORG_THEME_HEAD_PRINTED'] = true;

    $userId = org_theme_viewer_user_id($dbh);

    org_theme_print_shell_palette_critical($dbh);

    echo '<script>window.__MSB_THEME_DISABLE_LOCAL = true;</script>' . "\n";

    if ($userId > 0) {
        $cfg = org_theme_js_config($dbh, $userId);
        $uid = (int)($cfg['userId'] ?? 0);
        if ($uid > 0) {
            $autoEnabled = !empty($cfg['autoEnabled']) ? 'true' : 'false';
            $manualMode = json_encode((string)($cfg['manualMode'] ?? 'dark'));
            $appearanceMode = json_encode((string)($cfg['appearanceMode'] ?? org_theme_appearance_mode($dbh, $uid)));
            $paletteJson = json_encode(appearance_palette_js_map(), JSON_UNESCAPED_SLASHES);

            echo '<script>window.__MSB_APPEARANCE_PALETTES = ' . $paletteJson . ';window.__MSB_THEME_USER_ID = ' . $uid . ';window.__MSB_THEME_DEFAULTS = {autoEnabled: ' . $autoEnabled . ', manualMode: ' . $manualMode . ', appearanceMode: ' . $appearanceMode . '};window.__MSB_THEME_DB_MODE = ' . $appearanceMode . ';window.__MSBThemePrefsUserId = ' . $uid . ';window.__MSBThemePrefs = {autoEnabled: ' . $autoEnabled . ', manualMode: ' . $manualMode . ', appearanceMode: ' . $appearanceMode . '};</script>' . "\n";
        }
    }

    echo '<script src="../public_user/js/theme-bootstrap.js?v=56"></script>' . "\n";
    echo '<link rel="stylesheet" href="../public_user/css/appearance-palette.css?v=73">' . "\n";
    echo '<link rel="stylesheet" href="../css/dark-auto.css?v=2">' . "\n";
    $GLOBALS['__MSB_ORG_THEME_CSS_PRINTED'] = true;
}
