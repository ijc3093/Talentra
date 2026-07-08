<?php
declare(strict_types=1);

require_once __DIR__ . '/appearance_palettes.php';

function appearance_bridge_user_mode(PDO $dbh, int $userId): string
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
        return 'system';
    }
}

function appearance_bridge_is_named_palette(string $mode): bool
{
    $mode = appearance_palette_normalize_mode($mode);
    return !in_array($mode, ['system', 'light', 'dark'], true);
}

function appearance_bridge_org_page_bg(): string
{
    return '#171d24';
}

function appearance_bridge_print_door_shell_critical(): void
{
    if (!empty($GLOBALS['__MSB_DOOR_SHELL_CRITICAL_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_DOOR_SHELL_CRITICAL_PRINTED'] = true;

    echo '<style id="msb-door-shell-critical">'
        . 'html.dark-auto #ttLeftbarOverlays .tt-live-wrap,'
        . 'html.dark-auto #ttLeftbarOverlays .tt-live-door-shade,'
        . 'html.dark-auto #ttLeftbarOverlays .tt-live-door-frame,'
        . 'html.dark-auto .msb-live-door-host .tt-live-wrap,'
        . 'html.dark-auto .msb-live-door-host .tt-live-door-shade,'
        . 'html.dark-auto .msb-live-door-host .tt-live-door-frame,'
        . 'html[data-theme="dark"] #ttLeftbarOverlays .tt-live-wrap,'
        . 'html[data-theme="dark"] #ttLeftbarOverlays .tt-live-door-shade,'
        . 'html[data-theme="dark"] #ttLeftbarOverlays .tt-live-door-frame,'
        . 'html[data-theme="dark"] .msb-live-door-host .tt-live-wrap,'
        . 'html[data-theme="dark"] .msb-live-door-host .tt-live-door-shade,'
        . 'html[data-theme="dark"] .msb-live-door-host .tt-live-door-frame,'
        . 'html.dark-auto #ttLeftbarOverlays .tt-menu-wrap,'
        . 'html[data-theme="dark"] #ttLeftbarOverlays .tt-menu-wrap{'
        . 'background-color:var(--msb-palette-bg,#171d24)!important;background-image:none!important;}'
        . '</style>' . "\n";
}

/** FOUC guard: paint shell from Gear appearance before JS/CSS fully load. */
function appearance_bridge_print_shell_critical(PDO $dbh, int $userId, bool $orgSurface = false): void
{
    if (!empty($GLOBALS['__MSB_SHELL_PALETTE_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_SHELL_PALETTE_PRINTED'] = true;

    if ($userId <= 0 && !$orgSurface) {
        return;
    }

    $orgBg = appearance_bridge_org_page_bg();
    $orgBgAttr = htmlspecialchars($orgBg, ENT_QUOTES, 'UTF-8');

    if ($orgSurface) {
        echo '<style id="org-page-bg-critical">'
            . 'html body.org-app,html body.org-app .sh-mainpanel,html body.org-app .sh-pagebody,'
            . 'html body.org-app .sh-sideleft-menu,html body.org-app .sh-logopanel,'
            . 'html body.org-app .sh-headpanel,html body.org-app .card,html body.org-app .card-body,'
            . 'html body.org-app .dashboard-card{'
            . 'background-color:' . $orgBgAttr . '!important;background-image:none!important;}'
            . 'html body.org-app{--org-page-bg:' . $orgBgAttr . ';--org-bg:' . $orgBgAttr . ';'
            . '--bg-main:' . $orgBgAttr . ';--bg-card:' . $orgBgAttr . ';--bg-sidebar:' . $orgBgAttr . ';}'
            . '</style>' . "\n";
    }

    if ($userId <= 0) {
        return;
    }

    $mode = appearance_bridge_user_mode($dbh, $userId);
    if (!appearance_bridge_is_named_palette($mode)) {
        return;
    }

    $hex = appearance_palette_hex_for_slug($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : '#0f172a';
    $modeJson = json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $hexAttr = htmlspecialchars($hex, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $pageBgAttr = $orgSurface ? $orgBgAttr : $hexAttr;

    echo '<script>document.documentElement.setAttribute("data-msb-appearance",' . $modeJson . ');</script>' . "\n";
    echo '<style id="msb-shell-palette-critical">'
        . 'html[data-msb-appearance]{--msb-palette-bg:' . $pageBgAttr . ';--org-bg:' . $pageBgAttr . ';--msb-palette-text:' . $textAttr . ';}'
        . ($orgSurface
            ? 'html[data-msb-appearance] body.org-app,html[data-msb-appearance] body.org-app .sh-mainpanel,'
              . 'html[data-msb-appearance] body.org-app .sh-pagebody,html[data-msb-appearance] body.org-app .sh-sideleft-menu,'
              . 'html[data-msb-appearance] body.org-app .sh-logopanel,html[data-msb-appearance] body.org-app .sh-headpanel,'
              . 'html[data-msb-appearance] body.org-app .card,html[data-msb-appearance] body.org-app .card-body,'
              . 'html[data-msb-appearance] body.org-app .dashboard-card{'
              . 'background-color:' . $orgBgAttr . '!important;background-image:none!important;color:var(--msb-palette-text)!important;}'
            : 'html[data-msb-appearance],html[data-msb-appearance] body,'
              . 'html[data-msb-appearance] .sh-mainpanel,html[data-msb-appearance] .sh-pagebody,'
              . 'html[data-msb-appearance] .sh-sideleft-menu,html[data-msb-appearance] .sh-logopanel,'
              . 'html[data-msb-appearance] .sh-headpanel,'
              . 'html[data-msb-appearance] .feed-desktop-layout,html[data-msb-appearance] .feed-desktop-center,'
              . 'html[data-msb-appearance] .messages-shell,html[data-msb-appearance] .profile-page,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-wrap,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-head,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-body,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-live-wrap,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-live-door-shade,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-live-door-frame,'
              . 'html[data-msb-appearance] .msb-live-door-host .tt-live-wrap,'
              . 'html[data-msb-appearance] .msb-live-door-host .tt-live-door-shade,'
              . 'html[data-msb-appearance] .msb-live-door-host .tt-live-door-frame,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-panel{'
              . 'background-color:var(--msb-palette-bg)!important;background-image:none!important;'
              . 'color:var(--msb-palette-text)!important;}'
        )
        . '</style>' . "\n";
}

function appearance_bridge_normalize_asset_prefix(string $prefix): string
{
    $prefix = trim($prefix);
    if ($prefix === '') {
        return './';
    }
    return rtrim($prefix, '/') . '/';
}

function appearance_bridge_print_css_link(string $assetPrefix = './'): void
{
    if (!empty($GLOBALS['__MSB_APPEARANCE_BRIDGE_CSS'])) {
        return;
    }
    $GLOBALS['__MSB_APPEARANCE_BRIDGE_CSS'] = true;
    $prefix = appearance_bridge_normalize_asset_prefix($assetPrefix);
    $href = htmlspecialchars($prefix . 'css/appearance-bridge.css?v=14', ENT_QUOTES, 'UTF-8');
    echo '<link rel="stylesheet" href="' . $href . '">' . "\n";
}

/** Shell paint + JS config + theme-bootstrap + shared appearance CSS for any app surface. */
function appearance_bridge_print_theme_stack(PDO $dbh, int $userId, string $assetPrefix = './', bool $disableLocalStorage = false, bool $orgSurface = false): void
{
    appearance_bridge_print_door_shell_critical();
    appearance_bridge_print_shell_critical($dbh, $userId, $orgSurface);

    if ($disableLocalStorage) {
        echo '<script>window.__MSB_THEME_DISABLE_LOCAL = true;</script>' . "\n";
    }

    if ($userId <= 0) {
        return;
    }

    require_once __DIR__ . '/appearance_palettes.php';

    $mode = appearance_bridge_user_mode($dbh, $userId);
    $manualMode = 'dark';
    if ($mode === 'light') {
        $manualMode = 'light';
    } elseif ($mode === 'dark') {
        $manualMode = 'dark';
    } elseif ($mode !== 'system') {
        $manualMode = appearance_palette_is_dark_slug($mode) ? 'dark' : 'light';
    }

    $autoEnabled = ($mode === 'system') ? 'true' : 'false';
    $manualModeJson = json_encode($manualMode);
    $appearanceMode = json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $paletteJson = json_encode(appearance_palette_js_map(), JSON_UNESCAPED_SLASHES);

    echo '<script>window.__MSB_APPEARANCE_PALETTES = ' . $paletteJson . ';window.__MSB_THEME_USER_ID = ' . $userId . ';window.__MSB_THEME_DEFAULTS = {autoEnabled: ' . $autoEnabled . ', manualMode: ' . $manualModeJson . ', appearanceMode: ' . $appearanceMode . '};window.__MSB_THEME_DB_MODE = ' . $appearanceMode . ';window.__MSBThemePrefsUserId = ' . $userId . ';window.__MSBThemePrefs = {autoEnabled: ' . $autoEnabled . ', manualMode: ' . $manualModeJson . ', appearanceMode: ' . $appearanceMode . '};</script>' . "\n";

    $prefix = appearance_bridge_normalize_asset_prefix($assetPrefix);
    if (empty($GLOBALS['__MSB_THEME_BOOTSTRAP_JS'])) {
        $GLOBALS['__MSB_THEME_BOOTSTRAP_JS'] = true;
        echo '<script src="' . htmlspecialchars($prefix . 'js/theme-bootstrap.js?v=61', ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }
    if (!defined('MSB_APPEARANCE_PALETTE_CSS')) {
        define('MSB_APPEARANCE_PALETTE_CSS', true);
        echo '<link rel="stylesheet" href="' . htmlspecialchars($prefix . 'css/appearance-palette.css?v=77', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    appearance_bridge_print_css_link($assetPrefix);
    if (!defined('MSB_THEME_DARK_CSS')) {
        define('MSB_THEME_DARK_CSS', true);
        echo '<link rel="stylesheet" href="' . htmlspecialchars($prefix . 'css/dark-auto.css?v=10', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if (!defined('MSB_THEME_DARK_JS')) {
        define('MSB_THEME_DARK_JS', true);
        echo '<script src="' . htmlspecialchars($prefix . 'js/dark-auto.js?v=6', ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }
}
