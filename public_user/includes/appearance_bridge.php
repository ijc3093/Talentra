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

function appearance_bridge_theme_auto_enabled(PDO $dbh, int $userId): bool
{
    if ($userId <= 0) {
        return true;
    }

    appearance_palette_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('SELECT theme_auto_enabled, appearance_mode FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }
        if (array_key_exists('theme_auto_enabled', $row)) {
            return ((int)($row['theme_auto_enabled'] ?? 0)) === 1;
        }
        $mode = appearance_palette_normalize_mode((string)($row['appearance_mode'] ?? 'system'));
        return $mode === 'system';
    } catch (Throwable $e) {
        return true;
    }
}

function appearance_bridge_is_named_palette(string $mode): bool
{
    $mode = appearance_palette_normalize_mode($mode);
    return !in_array($mode, ['system', 'light', 'dark'], true);
}

/** Match JS shouldAutoDark(): night canvas from 5pm-6am. */
function appearance_bridge_is_night_now(): bool
{
    $hour = (int)date('G');
    return ($hour >= 17 || $hour < 6);
}

/** Early html.dark-auto so org CSS wins before theme-bootstrap runs (uses browser local clock). */
function appearance_bridge_print_early_dark_auto_class(bool $autoEnabled): void
{
    if (!$autoEnabled) {
        return;
    }
    if (!empty($GLOBALS['__MSB_EARLY_DARK_AUTO_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_EARLY_DARK_AUTO_PRINTED'] = true;
    echo '<script data-msb-theme-style="1">'
        . '(function(){var h=(new Date()).getHours();'
        . 'if(!(h>=17||h<6))return;'
        . 'var r=document.documentElement;r.classList.add("dark-auto");'
        . 'r.setAttribute("data-msb-theme-auto","1");'
        . 'r.removeAttribute("data-msb-org-light");'
        . 'if(document.body){document.body.classList.add("dark-auto");}'
        . 'else{document.addEventListener("DOMContentLoaded",function(){if(document.body)document.body.classList.add("dark-auto");r.removeAttribute("data-msb-org-light");});}'
        . '})();</script>' . "\n";
}

function appearance_bridge_org_page_bg(): string
{
    return '#171d24';
}

function appearance_bridge_org_light_page_bg(): string
{
    return '#ffffff';
}

/** Org canvas is white for Light/System appearance — never when a named palette color is selected. */
function appearance_bridge_org_uses_light_canvas(string $mode, bool $autoEnabled = false): bool
{
    $mode = appearance_palette_normalize_mode($mode);
    if (appearance_bridge_is_named_palette($mode)) {
        return false;
    }
    if ($mode === 'dark') {
        return false;
    }
    return $mode === 'light' || $mode === 'system';
}

/** Named Gear palette paints org whenever a color slug is selected (independent of Dark auto). */
function appearance_bridge_org_uses_named_canvas(string $mode, bool $autoEnabled = false): bool
{
    return appearance_bridge_is_named_palette($mode);
}

function appearance_bridge_print_org_canvas_attrs(string $mode, bool $autoEnabled = false): void
{
    $mode = appearance_palette_normalize_mode($mode);
    if (appearance_bridge_is_named_palette($mode)) {
        return;
    }
    if ($autoEnabled) {
        if (appearance_bridge_org_uses_light_canvas($mode, $autoEnabled)) {
            echo '<script>(function(){var r=document.documentElement,h=(new Date()).getHours();'
                . 'r.setAttribute("data-msb-theme-auto","1");'
                . 'if(h>=17||h<6){r.removeAttribute("data-msb-org-light");}'
                . 'else{r.setAttribute("data-msb-org-light","1");}'
                . '})();</script>' . "\n";
            return;
        }
        echo '<script>document.documentElement.setAttribute("data-msb-theme-auto","1");</script>' . "\n";
    }
    if (appearance_bridge_org_uses_light_canvas($mode, $autoEnabled)) {
        echo '<script>document.documentElement.setAttribute("data-msb-org-light","1");</script>' . "\n";
    }
}

function appearance_bridge_print_pub_light_critical(): void
{
    if (!empty($GLOBALS['__MSB_PUB_LIGHT_CRITICAL_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_PUB_LIGHT_CRITICAL_PRINTED'] = true;

    echo '<style id="pub-page-light-critical">'
        . 'html[data-msb-org-light] body.dashboard-page,'
        . 'html[data-msb-org-light] body.dashboard-page .sh-mainpanel,'
        . 'html[data-msb-org-light] body.dashboard-page .sh-pagebody,'
        . 'html[data-msb-org-light] body.dashboard-page .sh-pagetitle,'
        . 'html[data-msb-org-light] body.dashboard-page .row,'
        . 'html[data-msb-org-light] body.dashboard-page .row-sm,'
        . 'html[data-msb-org-light] body.dashboard-page .col-lg-12,'
        . 'html[data-msb-org-light] body.dashboard-page .card,'
        . 'html[data-msb-org-light] body.dashboard-page .card-body,'
        . 'html[data-msb-org-light] body.dashboard-page .card-header,'
        . 'html[data-msb-org-light] body.dashboard-page .card-footer,'
        . 'html[data-msb-org-light] body.dashboard-page .card.mb-3,'
        . 'html[data-msb-org-light] body.dashboard-page .form-control,'
        . 'html[data-msb-org-light] body.dashboard-page select.form-control,'
        . 'html[data-msb-org-light] body.dashboard-page textarea.form-control,'
        . 'html[data-msb-org-light] body.dashboard-page .msb-readonly-field,'
        . 'html[data-msb-org-light] body.dashboard-page .alert,'
        . 'html[data-msb-org-light] body.dashboard-page .alert-info,'
        . 'html[data-msb-org-light] body.dashboard-page .modal-content,'
        . 'html[data-msb-org-light] body.dashboard-page .modal-body,'
        . 'html[data-msb-org-light] body.dashboard-page #instructionBox,'
        . 'html[data-msb-org-light] body.dashboard-modal-page,'
        . 'html[data-msb-org-light] body.dashboard-modal-page .sh-mainpanel,'
        . 'html[data-msb-org-light] body.dashboard-modal-page .sh-pagebody,'
        . 'html[data-msb-org-light] body.dashboard-modal-page .card,'
        . 'html[data-msb-org-light] body.dashboard-modal-page .card-body{'
        . 'background-color:#ffffff!important;background-image:none!important;color:#111827!important;'
        . 'border-color:rgba(15,23,42,0.12)!important;}'
        . 'html[data-msb-org-light] body.dashboard-page label,'
        . 'html[data-msb-org-light] body.dashboard-page .card-title,'
        . 'html[data-msb-org-light] body.dashboard-page h6,'
        . 'html[data-msb-org-light] body.dashboard-page .form-check-label,'
        . 'html[data-msb-org-light] body.dashboard-modal-page label,'
        . 'html[data-msb-org-light] body.dashboard-modal-page .card-title,'
        . 'html[data-msb-org-light] body.dashboard-modal-page h6{'
        . 'color:#111827!important;}'
        . 'html[data-msb-org-light] body.dashboard-page .text-muted,'
        . 'html[data-msb-org-light] body.dashboard-page small,'
        . 'html[data-msb-org-light] body.dashboard-modal-page .text-muted,'
        . 'html[data-msb-org-light] body.dashboard-modal-page small{'
        . 'color:#64748b!important;}'
        . 'html[data-msb-org-light] body.dashboard-page .form-control::placeholder,'
        . 'html[data-msb-org-light] body.dashboard-page textarea.form-control::placeholder{'
        . 'color:#94a3b8!important;}'
        . 'html[data-msb-org-light] body.dashboard-page{--bg-main:#ffffff;--bg-card:#ffffff;--text-primary:#111827;'
        . '--text-secondary:#64748b;--text-muted:#64748b;}'
        . '</style>' . "\n";
}

/** Named Gear palette paints publisher surfaces whenever a color is selected (independent of Dark auto). */
function appearance_bridge_pub_uses_named_canvas(string $mode, bool $autoEnabled = false): bool
{
    return appearance_bridge_is_named_palette($mode);
}

function appearance_bridge_shell_palette_dashboard_selectors(): string
{
    return 'html[data-msb-appearance] body.dashboard-page,'
        . 'html[data-msb-appearance] body.dashboard-page .sh-mainpanel,'
        . 'html[data-msb-appearance] body.dashboard-page .sh-pagebody,'
        . 'html[data-msb-appearance] body.dashboard-page .sh-pagetitle,'
        . 'html[data-msb-appearance] body.dashboard-page .row,'
        . 'html[data-msb-appearance] body.dashboard-page .row-sm,'
        . 'html[data-msb-appearance] body.dashboard-page .col-lg-12,'
        . 'html[data-msb-appearance] body.dashboard-page .card,'
        . 'html[data-msb-appearance] body.dashboard-page .card-body,'
        . 'html[data-msb-appearance] body.dashboard-page .card-header,'
        . 'html[data-msb-appearance] body.dashboard-page .card-footer,'
        . 'html[data-msb-appearance] body.dashboard-page .card.mb-3,'
        . 'html[data-msb-appearance] body.dashboard-page .alert,'
        . 'html[data-msb-appearance] body.dashboard-page .alert-info,'
        . 'html[data-msb-appearance] body.dashboard-page #instructionBox,'
        . 'html[data-msb-appearance] body.dashboard-modal-page,'
        . 'html[data-msb-appearance] body.dashboard-modal-page .sh-mainpanel,'
        . 'html[data-msb-appearance] body.dashboard-modal-page .sh-pagebody,'
        . 'html[data-msb-appearance] body.dashboard-modal-page .card,'
        . 'html[data-msb-appearance] body.dashboard-modal-page .card-body';
}

function appearance_bridge_print_pub_palette_critical(string $mode): void
{
    if (!appearance_bridge_is_named_palette($mode)) {
        return;
    }

    $pageBg = appearance_palette_unified_bg_hex($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
    $muted = $usesDarkChrome ? '#b1bcce' : appearance_palette_chromatic_muted_hex($mode);
    $icon = $text;
    $action = appearance_palette_chromatic_action_hex($mode);
    $pageBgAttr = htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $mutedAttr = htmlspecialchars($muted, ENT_QUOTES, 'UTF-8');
    $iconAttr = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $dash = appearance_bridge_shell_palette_dashboard_selectors();

    echo '<style id="pub-dashboard-palette-critical">'
        . 'html[data-msb-appearance],html.msb-palette-active{--msb-palette-bg:' . $pageBgAttr . ';--bg-main:' . $pageBgAttr . ';--bg-card:' . $pageBgAttr . ';'
        . '--msb-palette-text:' . $textAttr . ';--msb-palette-text-muted:' . $mutedAttr . ';'
        . '--msb-palette-icon:' . $iconAttr . ';--msb-palette-action:' . $actionAttr . ';'
        . '--msb-palette-text-on-nav:' . $textAttr . ';--text-primary:' . $textAttr . ';--text-muted:' . $mutedAttr . ';}'
        . $dash . '{background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;background-image:none!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;border-color:var(--msb-palette-border,rgba(15,23,42,0.12))!important;}'
        . 'html[data-msb-appearance] body.dashboard-page label,'
        . 'html[data-msb-appearance] body.dashboard-page .card-title,'
        . 'html[data-msb-appearance] body.dashboard-page h6,'
        . 'html[data-msb-appearance] body.dashboard-page .form-check-label,'
        . 'html[data-msb-appearance] body.dashboard-modal-page label,'
        . 'html[data-msb-appearance] body.dashboard-modal-page .card-title,'
        . 'html[data-msb-appearance] body.dashboard-modal-page h6{'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.dashboard-page .text-muted,'
        . 'html[data-msb-appearance] body.dashboard-page small,'
        . 'html[data-msb-appearance] body.dashboard-page .form-text,'
        . 'html[data-msb-appearance] body.dashboard-modal-page .text-muted,'
        . 'html[data-msb-appearance] body.dashboard-modal-page small{'
        . 'color:var(--msb-palette-text-muted,' . $mutedAttr . ')!important;}'
        . 'html[data-msb-appearance] body.dashboard-page .form-control,'
        . 'html[data-msb-appearance] body.dashboard-page select.form-control,'
        . 'html[data-msb-appearance] body.dashboard-page textarea.form-control,'
        . 'html[data-msb-appearance] body.dashboard-page .msb-readonly-field{'
        . 'background-color:var(--msb-palette-input-bg,var(--msb-palette-surface,var(--msb-palette-bg,' . $pageBgAttr . ')))!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;'
        . 'border-color:var(--msb-palette-border-strong,var(--msb-palette-border,rgba(15,23,42,0.18)))!important;}'
        . '</style>' . "\n";
}

function appearance_bridge_print_profile_palette_critical(string $mode): void
{
    if (!appearance_bridge_is_named_palette($mode)) {
        return;
    }
    if (!empty($GLOBALS['__MSB_PROFILE_PALETTE_CRITICAL_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_PROFILE_PALETTE_CRITICAL_PRINTED'] = true;

    $pageBg = appearance_palette_unified_bg_hex($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
    $muted = $usesDarkChrome ? '#cbd5e1' : appearance_palette_chromatic_muted_hex($mode);
    $icon = $text;
    $action = appearance_palette_chromatic_action_hex($mode);
    $btnBg = appearance_palette_btn_bg_hex($mode);
    $btnText = appearance_palette_btn_text_hex($mode);
    $navActiveBg = appearance_palette_nav_active_bg_hex($mode);
    $navActiveText = appearance_palette_nav_active_text_hex($mode);
    $pageBgAttr = htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $mutedAttr = htmlspecialchars($muted, ENT_QUOTES, 'UTF-8');
    $iconAttr = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $btnBgAttr = htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8');
    $btnTextAttr = htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8');
    $navActiveBgAttr = htmlspecialchars($navActiveBg, ENT_QUOTES, 'UTF-8');
    $navActiveTextAttr = htmlspecialchars($navActiveText, ENT_QUOTES, 'UTF-8');

    $surfaces = 'html[data-msb-appearance] body.profile-page,'
        . 'html[data-msb-appearance] body.profile-page .sh-mainpanel,'
        . 'html[data-msb-appearance] body.profile-page .sh-pagebody,'
        . 'html[data-msb-appearance] body.profile-page .ig-card,'
        . 'html[data-msb-appearance] body.profile-page .ig-profile-shell,'
        . 'html[data-msb-appearance] body.profile-page .ig-profile-head,'
        . 'html[data-msb-appearance] body.profile-page .ig-profile-scroll,'
        . 'html[data-msb-appearance] body.profile-page .ig-highlights,'
        . 'html[data-msb-appearance] body.profile-page .ig-tabs,'
        . 'html[data-msb-appearance] body.profile-page .profile-panel,'
        . 'html[data-msb-appearance] body.profile-page .coming-wrap,'
        . 'html[data-msb-appearance] body.profile-page .about-wrap,'
        . 'html[data-msb-appearance] body.profile-page .about-card,'
        . 'html[data-msb-appearance] body.profile-page .mf-feed-empty,'
        . 'html[data-msb-appearance] body.profile-page .gear-wrap,'
        . 'html[data-msb-appearance] body.profile-page .gear-shell,'
        . 'html[data-msb-appearance] body.profile-page .gear-sidebar,'
        . 'html[data-msb-appearance] body.profile-page .gear-sidebar-head,'
        . 'html[data-msb-appearance] body.profile-page .gear-main,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-panel,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-head,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-body,'
        . 'html.msb-palette-active body.profile-page,'
        . 'html.msb-palette-active body.profile-page .sh-mainpanel,'
        . 'html.msb-palette-active body.profile-page .sh-pagebody,'
        . 'html.msb-palette-active body.profile-page .ig-card,'
        . 'html.msb-palette-active body.profile-page .ig-profile-shell,'
        . 'html.msb-palette-active body.profile-page .ig-profile-head,'
        . 'html.msb-palette-active body.profile-page .ig-profile-scroll,'
        . 'html.msb-palette-active body.profile-page .ig-tabs,'
        . 'html.msb-palette-active body.profile-page .profile-panel,'
        . 'html.msb-palette-active body.profile-page .gear-wrap,'
        . 'html.msb-palette-active body.profile-page .gear-shell,'
        . 'html.msb-palette-active body.profile-page .gear-sidebar,'
        . 'html.msb-palette-active body.profile-page .gear-main';

    echo '<style id="profile-palette-critical">'
        . 'html[data-msb-appearance],html.msb-palette-active{--msb-palette-bg:' . $pageBgAttr . ';--bg-main:' . $pageBgAttr . ';--bg-card:' . $pageBgAttr . ';'
        . '--msb-palette-text:' . $textAttr . ';--msb-palette-text-muted:' . $mutedAttr . ';'
        . '--msb-palette-icon:' . $iconAttr . ';--msb-palette-action:' . $actionAttr . ';'
        . '--msb-palette-btn-bg:' . $btnBgAttr . ';--msb-palette-btn-text:' . $btnTextAttr . ';'
        . '--msb-palette-nav-active-bg:' . $navActiveBgAttr . ';--msb-palette-nav-active-text:' . $navActiveTextAttr . ';'
        . '--msb-palette-text-on-nav:' . $textAttr . ';}'
        . $surfaces . '{'
        . 'background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;background-image:none!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .profile-account-badge,'
        . 'html[data-msb-appearance] body.profile-page span.profile-account-badge,'
        . 'html.msb-palette-active body.profile-page .profile-account-badge{'
        . 'background:transparent!important;background-color:transparent!important;border-color:transparent!important;'
        . 'color:var(--msb-palette-action,' . $actionAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .ig-username,'
        . 'html[data-msb-appearance] body.profile-page .ig-stat,'
        . 'html[data-msb-appearance] body.profile-page .ig-bio,'
        . 'html[data-msb-appearance] body.profile-page .gear-sidebar-title,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-title,'
        . 'html[data-msb-appearance] body.profile-page .gear-nav-section-toggle,'
        . 'html[data-msb-appearance] body.profile-page .gear-nav-item,'
        . 'html[data-msb-appearance] body.profile-page .gear-control,'
        . 'html.msb-palette-active body.profile-page .ig-username,'
        . 'html.msb-palette-active body.profile-page .ig-stat,'
        . 'html.msb-palette-active body.profile-page .ig-bio{'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .gear-nav-section-icon,'
        . 'html.msb-palette-active body.profile-page .gear-nav-section-icon{'
        . 'background:var(--msb-palette-action-soft,var(--msb-palette-hover-bg))!important;'
        . 'color:var(--msb-palette-action,' . $actionAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .profile-cover-badge,'
        . 'html.msb-palette-active body.profile-page .profile-cover-badge{'
        . 'background:var(--msb-palette-surface-2,var(--msb-palette-bg,' . $pageBgAttr . '))!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . '</style>' . "\n";
}

function appearance_bridge_shell_palette_shop_selectors(): string
{
    return 'html[data-msb-appearance] body.shop-page,'
        . 'html[data-msb-appearance] body.shop-page .sh-mainpanel,'
        . 'html[data-msb-appearance] body.shop-page .sh-pagebody,'
        . 'html[data-msb-appearance] body.shop-page .shop-page-shell,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-rail,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-nav,'
        . 'html[data-msb-appearance] body.shop-page .shop-nav-filters,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-nav,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-rail-page-head,'
        . 'html[data-msb-appearance] body.shop-page .ig-feed-header,'
        . 'html[data-msb-appearance] body.shop-page .shop-market-card,'
        . 'html[data-msb-appearance] body.shop-page .shop-market-cover,'
        . 'html[data-msb-appearance] body.shop-page .shop-buy-card,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-banner,'
        . 'html[data-msb-appearance] body.shop-page .cart-row,'
        . 'html[data-msb-appearance] body.shop-page .orders-row,'
        . 'html[data-msb-appearance] body.shop-page .cart-thumb,'
        . 'html[data-msb-appearance] body.shop-page .orders-thumb';
}

function appearance_bridge_print_shop_palette_critical(string $mode): void
{
    if (!appearance_bridge_is_named_palette($mode) || !empty($GLOBALS['__MSB_SHOP_PALETTE_CRITICAL_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_SHOP_PALETTE_CRITICAL_PRINTED'] = true;

    $pageBg = appearance_palette_unified_bg_hex($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
    $muted = $usesDarkChrome ? '#cbd5e1' : appearance_palette_chromatic_muted_hex($mode);
    $action = appearance_palette_chromatic_action_hex($mode);
    $btnBg = appearance_palette_btn_bg_hex($mode);
    $btnText = appearance_palette_btn_text_hex($mode);
    $pageBgAttr = htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $mutedAttr = htmlspecialchars($muted, ENT_QUOTES, 'UTF-8');
    $actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $btnBgAttr = htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8');
    $btnTextAttr = htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8');
    $surfaces = appearance_bridge_shell_palette_shop_selectors();

    echo '<style id="shop-palette-critical">'
        . $surfaces . '{background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;background-image:none!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;border-color:var(--msb-palette-border,rgba(15,23,42,0.12))!important;}'
        . 'html[data-msb-appearance] body.shop-page .shop-market-title,'
        . 'html[data-msb-appearance] body.shop-page .shop-market-title a,'
        . 'html[data-msb-appearance] body.shop-page .shop-market-price,'
        . 'html[data-msb-appearance] body.shop-page .shop-page-title,'
        . 'html[data-msb-appearance] body.shop-page .cart-title,'
        . 'html[data-msb-appearance] body.shop-page .orders-title,'
        . 'html[data-msb-appearance] body.shop-page .cart-name,'
        . 'html[data-msb-appearance] body.shop-page .orders-name,'
        . 'html[data-msb-appearance] body.shop-page .cart-name a,'
        . 'html[data-msb-appearance] body.shop-page .orders-name a,'
        . 'html[data-msb-appearance] body.shop-page .cart-price,'
        . 'html[data-msb-appearance] body.shop-page .orders-price,'
        . 'html[data-msb-appearance] body.shop-page .cart-line-total,'
        . 'html[data-msb-appearance] body.shop-page .orders-line-total,'
        . 'html[data-msb-appearance] body.shop-page .cart-page-subtotal,'
        . 'html[data-msb-appearance] body.shop-page .orders-page-total,'
        . 'html[data-msb-appearance] body.shop-page .ig-feed-header,'
        . 'html[data-msb-appearance] body.shop-page .ig-feed-top-lead,'
        . 'html[data-msb-appearance] body.shop-page .ig-feed-user-name,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-rail-page-title,'
        . 'html[data-msb-appearance] body.shop-page .shop-nav-filter-toggle,'
        . 'html[data-msb-appearance] body.shop-page .shop-nav-filter-label,'
        . 'html[data-msb-appearance] body.shop-page .shop-nav-filter-option,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-nav-text strong,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-banner-text strong,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-nav-item,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-nav-label{'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.shop-page .shop-page-sub,'
        . 'html[data-msb-appearance] body.shop-page .shop-market-fulfill,'
        . 'html[data-msb-appearance] body.shop-page .cart-sub,'
        . 'html[data-msb-appearance] body.shop-page .orders-sub,'
        . 'html[data-msb-appearance] body.shop-page .cart-seller,'
        . 'html[data-msb-appearance] body.shop-page .orders-seller,'
        . 'html[data-msb-appearance] body.shop-page .orders-meta,'
        . 'html[data-msb-appearance] body.shop-page .cart-empty,'
        . 'html[data-msb-appearance] body.shop-page .orders-empty,'
        . 'html[data-msb-appearance] body.shop-page .cart-qty label,'
        . 'html[data-msb-appearance] body.shop-page .orders-qty-label,'
        . 'html[data-msb-appearance] body.shop-page .feed-left-rail-page-sub,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-nav-head,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-nav-text span,'
        . 'html[data-msb-appearance] body.shop-page .shop-nav-filter-option,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-banner-text span{'
        . 'color:var(--msb-palette-text-muted,' . $mutedAttr . ')!important;}'
        . 'html[data-msb-appearance] body.shop-page .shop-market-add-cart,'
        . 'html[data-msb-appearance] body.shop-page .shop-buy-submit{'
        . 'background-color:var(--msb-palette-btn-bg,' . $btnBgAttr . ')!important;'
        . 'border:1px solid var(--msb-palette-border,rgba(177,188,206,.55))!important;'
        . 'color:var(--msb-palette-btn-text,' . $btnTextAttr . ')!important;}'
        . 'html[data-msb-appearance] body.shop-page .shop-nav-filter-clear,'
        . 'html[data-msb-appearance] body.shop-page .shop-brand-nav-clear,'
        . 'html[data-msb-appearance] body.shop-page .shop-market-fit-link,'
        . 'html[data-msb-appearance] body.shop-page .cart-back,'
        . 'html[data-msb-appearance] body.shop-page .orders-back,'
        . 'html[data-msb-appearance] body.shop-page .cart-details-link,'
        . 'html[data-msb-appearance] body.shop-page .orders-details-link{'
        . 'color:var(--msb-palette-link,var(--msb-palette-action,' . $actionAttr . '))!important;}'
        . 'html[data-msb-appearance] body.shop-page .cart-checkout-btn{'
        . 'background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;'
        . 'border-color:var(--msb-palette-text,' . $textAttr . ')!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.shop-page .cart-qty input{'
        . 'background-color:var(--msb-palette-input-bg,var(--msb-palette-bg,' . $pageBgAttr . '))!important;'
        . 'border-color:var(--msb-palette-border,rgba(15,23,42,0.12))!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . '</style>' . "\n";
}

/** Footer pass — wins over profile.php inline styles. */
function appearance_bridge_print_profile_palette_tail(string $mode): void
{
    if (!appearance_bridge_is_named_palette($mode) || !empty($GLOBALS['__MSB_PROFILE_PALETTE_TAIL_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_PROFILE_PALETTE_TAIL_PRINTED'] = true;

    $pageBg = appearance_palette_unified_bg_hex($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
    $action = appearance_palette_chromatic_action_hex($mode);
    $btnBg = appearance_palette_btn_bg_hex($mode);
    $btnText = appearance_palette_btn_text_hex($mode);
    $navActiveBg = appearance_palette_nav_active_bg_hex($mode);
    $navActiveText = appearance_palette_nav_active_text_hex($mode);
    $pageBgAttr = htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $btnBgAttr = htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8');
    $btnTextAttr = htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8');
    $navActiveBgAttr = htmlspecialchars($navActiveBg, ENT_QUOTES, 'UTF-8');
    $navActiveTextAttr = htmlspecialchars($navActiveText, ENT_QUOTES, 'UTF-8');

    $surfaces = 'html[data-msb-appearance] body.profile-page .ig-card,'
        . 'html[data-msb-appearance] body.profile-page .ig-profile-shell,'
        . 'html[data-msb-appearance] body.profile-page .ig-profile-head,'
        . 'html[data-msb-appearance] body.profile-page .ig-profile-scroll,'
        . 'html[data-msb-appearance] body.profile-page .ig-tabs,'
        . 'html[data-msb-appearance] body.profile-page .profile-panel,'
        . 'html[data-msb-appearance] body.profile-page .gear-wrap,'
        . 'html[data-msb-appearance] body.profile-page .gear-shell,'
        . 'html[data-msb-appearance] body.profile-page .gear-sidebar,'
        . 'html[data-msb-appearance] body.profile-page .gear-sidebar-head,'
        . 'html[data-msb-appearance] body.profile-page .gear-main,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-panel,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-head,'
        . 'html[data-msb-appearance] body.profile-page .gear-detail-body,'
        . 'html[data-msb-appearance] body.profile-page .gear-search,'
        . 'html[data-msb-appearance] body.profile-page .gear-control,'
        . 'html.msb-palette-active body.profile-page .ig-card,'
        . 'html.msb-palette-active body.profile-page .ig-profile-shell,'
        . 'html.msb-palette-active body.profile-page .ig-profile-head,'
        . 'html.msb-palette-active body.profile-page .ig-profile-scroll,'
        . 'html.msb-palette-active body.profile-page .ig-tabs,'
        . 'html.msb-palette-active body.profile-page .profile-panel,'
        . 'html.msb-palette-active body.profile-page .gear-wrap,'
        . 'html.msb-palette-active body.profile-page .gear-shell,'
        . 'html.msb-palette-active body.profile-page .gear-sidebar,'
        . 'html.msb-palette-active body.profile-page .gear-main';

    echo '<style id="profile-palette-tail">'
        . $surfaces . '{'
        . 'background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;background-image:none!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .profile-account-badge,'
        . 'html[data-msb-appearance] body.profile-page span.profile-account-badge,'
        . 'html.msb-palette-active body.profile-page .profile-account-badge{'
        . 'background:transparent!important;color:var(--msb-palette-action,' . $actionAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .gear-nav-section-icon,'
        . 'html.msb-palette-active body.profile-page .gear-nav-section-icon{'
        . 'background:var(--msb-palette-action-soft)!important;color:var(--msb-palette-action,' . $actionAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .gear-nav-item.is-active,'
        . 'html.msb-palette-active body.profile-page .gear-nav-item.is-active{'
        . 'color:var(--msb-palette-action,' . $actionAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .gear-nav-section-toggle,'
        . 'html.msb-palette-active body.profile-page .gear-nav-section-toggle{'
        . 'background:var(--msb-palette-action-soft)!important;'
        . 'border:1px solid var(--msb-palette-border)!important;'
        . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn,'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn:link,'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn:visited,'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn:hover,'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn:focus,'
        . 'html.msb-palette-active body.profile-page a.gear-detail-open-btn{'
        . 'background:var(--msb-palette-btn-bg,var(--msb-palette-action,' . $actionAttr . '))!important;'
        . 'border-color:var(--msb-palette-btn-bg,var(--msb-palette-action,' . $actionAttr . '))!important;'
        . 'color:var(--msb-palette-btn-text,' . $btnTextAttr . ')!important;'
        . '-webkit-text-fill-color:var(--msb-palette-btn-text,' . $btnTextAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn i,'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn .icon,'
        . 'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn [class*="ion-"]{'
        . 'color:var(--msb-palette-btn-text,' . $btnTextAttr . ')!important;'
        . '-webkit-text-fill-color:var(--msb-palette-btn-text,' . $btnTextAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .ig-tab.active,'
        . 'html.msb-palette-active body.profile-page .ig-tab.active{'
        . 'background:var(--msb-palette-nav-active-bg,' . $navActiveBgAttr . ')!important;'
        . 'color:var(--msb-palette-nav-active-text,' . $navActiveTextAttr . ')!important;'
        . 'border-top-color:var(--msb-palette-nav-active-text,' . $navActiveTextAttr . ')!important;}'
        . 'html[data-msb-appearance] body.profile-page .ig-tab.active i,'
        . 'html.msb-palette-active body.profile-page .ig-tab.active i{'
        . 'color:inherit!important;}'
        . '</style>' . "\n";
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
        . 'html[data-theme="dark"] #ttLeftbarOverlays .tt-menu-wrap,'
        . 'html.dark-auto #ttLeftbarOverlays .tt-profile-wrap,'
        . 'html[data-theme="dark"] #ttLeftbarOverlays .tt-profile-wrap,'
        . 'html.dark-auto .msb-profile-door-host .tt-profile-wrap,'
        . 'html[data-theme="dark"] .msb-profile-door-host .tt-profile-wrap,'
        . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap,'
        . 'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap{'
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

    $mode = 'system';
    $autoEnabled = true;
    $hasNamedPalette = false;
    if ($userId > 0) {
        $mode = appearance_bridge_user_mode($dbh, $userId);
        $autoEnabled = appearance_bridge_theme_auto_enabled($dbh, $userId);
        $hasNamedPalette = appearance_bridge_is_named_palette($mode);
    }

    if ($orgSurface && appearance_bridge_org_uses_light_canvas($mode, $autoEnabled)) {
        appearance_bridge_print_org_canvas_attrs($mode, $autoEnabled);
        $orgBgAttr = htmlspecialchars(appearance_bridge_org_light_page_bg(), ENT_QUOTES, 'UTF-8');
        $orgTextAttr = '#111827';
        $darkBg = htmlspecialchars(appearance_bridge_org_page_bg(), ENT_QUOTES, 'UTF-8');
        // Light canvas only while Dark auto is OFF for night — never override html.dark-auto.
        $lightScope = 'html:not(.dark-auto) body.org-app';
        echo '<style id="org-page-bg-critical">'
            . $lightScope . ',' . $lightScope . ' .sh-mainpanel,' . $lightScope . ' .sh-pagebody,'
            . $lightScope . ' .sh-sideleft-menu,' . $lightScope . ' .sh-logopanel,'
            . $lightScope . ' .sh-headpanel,' . $lightScope . ' .card,' . $lightScope . ' .card-body,'
            . $lightScope . ' .dashboard-card,' . $lightScope . ' .feed-sidebar,' . $lightScope . ' .feed-card,'
            . $lightScope . ' .feed-toolbar,' . $lightScope . ' .feed-layout,' . $lightScope . ' .feed-main,'
            . $lightScope . ' .rows-scroll,' . $lightScope . ' .sidebar-head,'
            . $lightScope . ' .sidebar-list,' . $lightScope . ' .commerce-page,'
            . $lightScope . ' .compose-intro,' . $lightScope . ' .compose-preview,'
            . $lightScope . ' .actions-fixed,' . $lightScope . ' .compose-card,'
            . $lightScope . ' .card-body-fixed,'
            . $lightScope . ' .commerce-kpi,' . $lightScope . ' .commerce-panel,'
            . $lightScope . ' .commerce-action-tile,' . $lightScope . ' .commerce-panel-head{'
            . 'background-color:' . $orgBgAttr . '!important;background-image:none!important;'
            . 'color:' . $orgTextAttr . '!important;}'
            . $lightScope . '{--org-page-bg:' . $orgBgAttr . ';--org-bg:' . $orgBgAttr . ';'
            . '--bg-main:' . $orgBgAttr . ';--bg-card:' . $orgBgAttr . ';--bg-sidebar:' . $orgBgAttr . ';'
            . ($autoEnabled ? '--org-btn-filled-text:#ffffff;--org-btn-on-accent:#ffffff;' : '')
            . '}'
            . $lightScope . ' .commerce-page{--ch-bg:' . $orgBgAttr . ';--ch-surface:' . $orgBgAttr . ';'
            . '--ch-ink:#111827;--ch-muted:#64748b;--ch-line:rgba(15,23,42,0.12);}'
            . 'html.dark-auto body.org-app,html.dark-auto body.org-app .sh-mainpanel,'
            . 'html.dark-auto body.org-app .sh-pagebody,html.dark-auto body.org-app .sh-sideleft-menu,'
            . 'html.dark-auto body.org-app .sh-logopanel,html.dark-auto body.org-app .sh-headpanel,'
            . 'html.dark-auto body.org-app .card,html.dark-auto body.org-app .card-body,'
            . 'html.dark-auto body.org-app .dashboard-card,html.dark-auto body.org-app .feed-sidebar,'
            . 'html.dark-auto body.org-app .feed-card,html.dark-auto body.org-app .feed-toolbar,'
            . 'html.dark-auto body.org-app .feed-layout,html.dark-auto body.org-app .feed-main,'
            . 'html.dark-auto body.org-app .feed-post-detail,html.dark-auto body.org-app .feed-compose,'
            . 'html.dark-auto body.org-app .comment-item,html.dark-auto body.org-app .rows-scroll,'
            . 'html.dark-auto body.org-app .sidebar-head,html.dark-auto body.org-app .sidebar-list,'
            . 'html.dark-auto body.org-app .compose-card,html.dark-auto body.org-app .compose-intro,'
            . 'html.dark-auto body.org-app .compose-preview,html.dark-auto body.org-app .actions-fixed,'
            . 'html.dark-auto body.org-app .card-body-fixed,html.dark-auto body.org-app .chat-shell,'
            . 'html.dark-auto body.org-app .fixed-card,html.dark-auto body.org-app .fixed-head,'
            . 'html.dark-auto body.org-app .fixed-body,html.dark-auto body.org-app .members-list-wrap,'
            . 'html.dark-auto body.org-app .conv-topbar,html.dark-auto body.org-app .history-card,'
            . 'html.dark-auto body.org-app .msg-scroll,html.dark-auto body.org-app .composer-fixed,'
            . 'html.dark-auto body.org-app .list-group-item,html.dark-auto body.org-app .commerce-page,'
            . 'html.dark-auto body.org-app .commerce-kpi,html.dark-auto body.org-app .commerce-panel,'
            . 'html.dark-auto body.org-app .commerce-action-tile,html.dark-auto body.org-app .commerce-panel-head,'
            . 'html.dark-auto body.org-app .commerce-brand-system-panel,html.dark-auto body.org-app .commerce-int-item,'
            . 'html.dark-auto body.org-app .commerce-channel,html.dark-auto body.org-app .commerce-table-wrap,'
            . 'html.dark-auto body.org-app .sales-management-metric,html.dark-auto body.org-app .sales-management-table-wrap{'
            . 'background-color:' . $darkBg . '!important;background-image:none!important;color:#e8edf5!important;}'
            . 'html.dark-auto body.org-app .commerce-page{--ch-bg:' . $darkBg . ';--ch-surface:' . $darkBg . ';'
            . '--ch-ink:#e8edf5;--ch-muted:#b1bcce;--ch-line:#334155;--ch-card:' . $darkBg . ';}'
            . 'html.dark-auto body.org-app .sidebar-search,html.dark-auto body.org-app .feed-compose-input,'
            . 'html.dark-auto body.org-app .org-pill,html.dark-auto body.org-app .ig-feed-account-badge,'
            . 'html.dark-auto body.org-app .form-control,html.dark-auto body.org-app select.form-control,'
            . 'html.dark-auto body.org-app textarea.form-control,'
            . 'html.dark-auto body.org-app .members-search input,html.dark-auto body.org-app .history-search input,'
            . 'html.dark-auto body.org-app .composer-row textarea{'
            . 'background-color:#252f3d!important;background-image:none!important;color:#e8edf5!important;'
            . 'border-color:rgba(177,188,206,.38)!important;}'
            . 'html.dark-auto body.org-app{--org-page-bg:' . $darkBg . ';--org-bg:' . $darkBg . ';'
            . '--bg-main:' . $darkBg . ';--bg-card:' . $darkBg . ';--bg-sidebar:' . $darkBg . ';'
            . '--org-surface:' . $darkBg . ';--org-input-bg:#252f3d;}'
            . ($autoEnabled
                ? 'html[data-msb-theme-auto]:not(.dark-auto) body.org-app a.feed-tab-link.active,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app a.feed-tab-link.active,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app .btn-primary,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app .btn-success,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app a.btn-primary,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app a.btn-success{color:#ffffff!important;}'
                : '')
            . '</style>' . "\n";
    } elseif ($orgSurface && !appearance_bridge_org_uses_named_canvas($mode, $autoEnabled)) {
        appearance_bridge_print_org_canvas_attrs($mode, $autoEnabled);
        $orgBgAttr = htmlspecialchars(appearance_bridge_org_page_bg(), ENT_QUOTES, 'UTF-8');
        echo '<style id="org-page-bg-critical">'
            . 'html body.org-app,html body.org-app .sh-mainpanel,html body.org-app .sh-pagebody,'
            . 'html body.org-app .sh-sideleft-menu,html body.org-app .sh-logopanel,'
            . 'html body.org-app .sh-headpanel,html body.org-app .card,html body.org-app .card-body,'
            . 'html body.org-app .dashboard-card,html body.org-app .feed-sidebar,html body.org-app .feed-card,'
            . 'html body.org-app .feed-toolbar,html body.org-app .rows-scroll,html body.org-app .sidebar-head,'
            . 'html body.org-app .sidebar-list,html body.org-app .commerce-page{'
            . 'background-color:' . $orgBgAttr . '!important;background-image:none!important;'
            . 'color:#e8edf5!important;}'
            . 'html body.org-app{--org-page-bg:' . $orgBgAttr . ';--org-bg:' . $orgBgAttr . ';'
            . '--bg-main:' . $orgBgAttr . ';--bg-card:' . $orgBgAttr . ';--bg-sidebar:' . $orgBgAttr . ';}'
            . '</style>' . "\n";
    }

    if (!$orgSurface && $userId > 0 && appearance_bridge_org_uses_light_canvas($mode, $autoEnabled)) {
        appearance_bridge_print_org_canvas_attrs($mode, $autoEnabled);
        appearance_bridge_print_pub_light_critical();
    }

    if ($userId <= 0) {
        return;
    }

    $usesOrgPalette = appearance_bridge_org_uses_named_canvas($mode, $autoEnabled);
    $usesPubPalette = !$orgSurface && appearance_bridge_pub_uses_named_canvas($mode, $autoEnabled);
    if (!$usesOrgPalette && !$usesPubPalette) {
        return;
    }

    $hex = appearance_palette_hex_for_slug($mode);
    $pageBg = appearance_palette_unified_bg_hex($mode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
    $text = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
    $muted = $usesDarkChrome ? '#cbd5e1' : appearance_palette_chromatic_muted_hex($mode);
    $icon = $text;
    $action = appearance_palette_chromatic_action_hex($mode);
    $modeJson = json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $pageBgAttr = htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8');
    $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $mutedAttr = htmlspecialchars($muted, ENT_QUOTES, 'UTF-8');
    $iconAttr = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

    echo '<script>document.documentElement.setAttribute("data-msb-appearance",' . $modeJson . ');'
        . 'document.documentElement.removeAttribute("data-msb-org-light");</script>' . "\n";
    echo '<style id="msb-shell-palette-critical">'
        . 'html[data-msb-appearance]{--msb-palette-bg:' . $pageBgAttr . ';--org-bg:' . $pageBgAttr . ';--org-page-bg:' . $pageBgAttr . ';'
        . '--bg-main:' . $pageBgAttr . ';--bg-card:' . $pageBgAttr . ';--bg-sidebar:' . $pageBgAttr . ';'
        . '--msb-palette-text:' . $textAttr . ';--msb-palette-text-muted:' . $mutedAttr . ';'
        . '--msb-palette-icon:' . $iconAttr . ';--msb-palette-action:' . $actionAttr . ';'
        . '--msb-palette-text-on-nav:' . $textAttr . ';}'
        . ($orgSurface
            ? 'html[data-msb-appearance] body.org-app,html[data-msb-appearance] body.org-app .sh-mainpanel,'
              . 'html[data-msb-appearance] body.org-app .sh-pagebody,html[data-msb-appearance] body.org-app .sh-sideleft-menu,'
              . 'html[data-msb-appearance] body.org-app .org-sideleft-scroll,html[data-msb-appearance] body.org-app .sh-logopanel,'
              . 'html[data-msb-appearance] body.org-app .sh-headpanel,html[data-msb-appearance] body.org-app .chat-shell,'
              . 'html[data-msb-appearance] body.org-app .chat-left,html[data-msb-appearance] body.org-app .chat-right,'
              . 'html[data-msb-appearance] body.org-app .card,html[data-msb-appearance] body.org-app .card-body,'
              . 'html[data-msb-appearance] body.org-app .card-body-fixed,html[data-msb-appearance] body.org-app .dashboard-card,'
              . 'html[data-msb-appearance] body.org-app .feed-sidebar,html[data-msb-appearance] body.org-app .feed-card,'
              . 'html[data-msb-appearance] body.org-app .feed-toolbar,html[data-msb-appearance] body.org-app .feed-layout,'
              . 'html[data-msb-appearance] body.org-app .feed-main,html[data-msb-appearance] body.org-app .feed-post-detail,'
              . 'html[data-msb-appearance] body.org-app .rows-scroll,html[data-msb-appearance] body.org-app .sidebar-head,'
              . 'html[data-msb-appearance] body.org-app .sidebar-list,html[data-msb-appearance] body.org-app .members-card,'
              . 'html[data-msb-appearance] body.org-app .members-card .card-body-fixed,'
              . 'html[data-msb-appearance] body.org-app .members-card .tab-content,'
              . 'html[data-msb-appearance] body.org-app .members-card .tab-pane,'
              . 'html[data-msb-appearance] body.org-app .members-card .list-group,'
              . 'html[data-msb-appearance] body.org-app .members-card .list-group-item{'
              . 'background-color:var(--msb-palette-bg)!important;background-image:none!important;color:var(--msb-palette-text)!important;}'
              . 'html[data-msb-appearance] body.org-app .commerce-page,'
              . 'html[data-msb-appearance] body.org-app .commerce-card,'
              . 'html[data-msb-appearance] body.org-app .commerce-table-wrap{'
              . 'background-color:var(--msb-palette-bg)!important;background-image:none!important;color:var(--msb-palette-text)!important;}'
            : 'html[data-msb-appearance],html[data-msb-appearance] body,'
              . 'html[data-msb-appearance] .sh-mainpanel,html[data-msb-appearance] .sh-pagebody,'
              . 'html[data-msb-appearance] .sh-sideleft-menu,html[data-msb-appearance] .sh-logopanel,'
              . 'html[data-msb-appearance] .sh-headpanel,'
              . 'html[data-msb-appearance] .feed-desktop-layout,html[data-msb-appearance] .feed-desktop-center,'
              . 'html[data-msb-appearance] .messages-shell,html[data-msb-appearance] .profile-page,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-wrap,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-head,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-body,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-head,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-body,'
              . 'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap,'
              . 'html[data-msb-appearance] .msb-profile-door-host .tt-profile-head,'
              . 'html[data-msb-appearance] .msb-profile-door-host .tt-profile-body,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-live-wrap,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-live-door-shade,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-live-door-frame,'
              . 'html[data-msb-appearance] .msb-live-door-host .tt-live-wrap,'
              . 'html[data-msb-appearance] .msb-live-door-host .tt-live-door-shade,'
              . 'html[data-msb-appearance] .msb-live-door-host .tt-live-door-frame,'
              . 'html[data-msb-appearance] #ttLeftbarOverlays .tt-menu-panel{'
              . 'background-color:var(--msb-palette-bg)!important;background-image:none!important;'
              . 'color:var(--msb-palette-text)!important;}'
              . ($usesPubPalette
                  ? appearance_bridge_shell_palette_dashboard_selectors() . '{'
                    . 'background-color:var(--msb-palette-bg)!important;background-image:none!important;'
                    . 'color:var(--msb-palette-text)!important;}'
                  : '')
        )
        . '</style>' . "\n";

    if ($usesPubPalette) {
        appearance_bridge_print_pub_palette_critical($mode);
        if (!$orgSurface) {
            appearance_bridge_print_profile_palette_critical($mode);
            appearance_bridge_print_shop_palette_critical($mode);
        }
    }
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
    $href = htmlspecialchars($prefix . 'css/appearance-bridge.css?v=52', ENT_QUOTES, 'UTF-8');
    echo '<link rel="stylesheet" href="' . $href . '">' . "\n";
}

function appearance_bridge_print_profile_door_text_critical(PDO $dbh, int $userId): void
{
    if ($userId <= 0 || !empty($GLOBALS['__MSB_DOOR_TEXT_CRITICAL_PRINTED'])) {
        return;
    }
    $GLOBALS['__MSB_DOOR_TEXT_CRITICAL_PRINTED'] = true;

    $mode = appearance_bridge_user_mode($dbh, $userId);
    $autoEnabled = appearance_bridge_theme_auto_enabled($dbh, $userId);
    $usesNamed = appearance_bridge_pub_uses_named_canvas($mode, $autoEnabled);
    $textFallback = '#101828';
    $mutedFallback = '#374151';
    if ($usesNamed) {
        $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
        $textFallback = $usesDarkChrome ? '#f3f6fb' : '#0a0a0a';
        $mutedFallback = $usesDarkChrome ? '#cbd5e1' : '#374151';
    }
    $textAttr = htmlspecialchars($textFallback, ENT_QUOTES, 'UTF-8');
    $mutedAttr = htmlspecialchars($mutedFallback, ENT_QUOTES, 'UTF-8');

    echo '<style id="msb-profile-door-text-critical">'
        . 'html #ttLeftbarOverlays .tt-profile-head .title,'
        . 'html .msb-profile-door-host .tt-profile-head .title,'
        . 'html #ttLeftbarOverlays .tt-profile-name,'
        . 'html .msb-profile-door-host .tt-profile-name,'
        . 'html #ttLeftbarOverlays .tt-profile-badge,'
        . 'html .msb-profile-door-host .tt-profile-badge,'
        . 'html #ttLeftbarOverlays .tt-profile-nav a,'
        . 'html .msb-profile-door-host .tt-profile-nav a{'
        . 'color:var(--msb-palette-text,var(--tt-text,' . $textAttr . '))!important;'
        . '-webkit-text-fill-color:var(--msb-palette-text,var(--tt-text,' . $textAttr . '))!important;'
        . 'background:transparent!important;background-color:transparent!important;}'
        . 'html #ttLeftbarOverlays .tt-profile-nav a .icon,'
        . 'html .msb-profile-door-host .tt-profile-nav a .icon{'
        . 'color:var(--msb-palette-text,var(--msb-palette-icon,var(--tt-text,' . $textAttr . ')))!important;'
        . 'opacity:1!important;}'
        . 'html #ttLeftbarOverlays .tt-profile-email,'
        . 'html #ttLeftbarOverlays .tt-profile-code,'
        . 'html .msb-profile-door-host .tt-profile-email,'
        . 'html .msb-profile-door-host .tt-profile-code{'
        . 'color:var(--msb-palette-text-muted,var(--msb-palette-text,var(--tt-muted,' . $mutedAttr . ')))!important;'
        . 'opacity:1!important;}'
        . '</style>' . "\n";
}

function appearance_bridge_print_profile_builtin_light_critical(string $mode, bool $autoEnabled): void
{
    if (!empty($GLOBALS['__MSB_PROFILE_LIGHT_CRITICAL_PRINTED'])) {
        return;
    }
    $mode = appearance_palette_normalize_mode($mode);
    if (appearance_bridge_is_named_palette($mode) || $mode === 'dark') {
        return;
    }
    if ($mode === 'system' && $autoEnabled) {
        return;
    }
    $GLOBALS['__MSB_PROFILE_LIGHT_CRITICAL_PRINTED'] = true;

    echo '<style id="msb-profile-builtin-light-critical">'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page,'
        . 'html:not(.dark-auto):not([data-msb-appearance]) body.profile-page,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .sh-mainpanel,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .sh-pagebody,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-card,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-profile-shell,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-profile-head,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-profile-scroll,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-tabs,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .profile-panel,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-wrap,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-shell,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-sidebar,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-sidebar-head,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-main{'
        . 'background-color:#f5f7fb!important;background-image:none!important;'
        . 'color:#0f172a!important;border-color:rgba(15,23,42,.12)!important;}'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-sidebar-title,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-detail-title,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-nav-section-toggle,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-nav-item,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .gear-control,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-username,'
        . 'html[data-theme="light"]:not([data-msb-appearance]) body.profile-page .ig-stat{'
        . 'color:#0f172a!important;}'
        . '</style>' . "\n";
}

/** Shell paint + JS config + theme-bootstrap + shared appearance CSS for any app surface. */
function appearance_bridge_print_theme_stack(PDO $dbh, int $userId, string $assetPrefix = './', bool $disableLocalStorage = false, bool $orgSurface = false): void
{
    appearance_bridge_print_door_shell_critical();
    if ($userId > 0) {
        appearance_bridge_print_early_dark_auto_class(appearance_bridge_theme_auto_enabled($dbh, $userId));
    }
    appearance_bridge_print_shell_critical($dbh, $userId, $orgSurface);
    if ($userId > 0) {
        appearance_bridge_print_profile_door_text_critical($dbh, $userId);
        $mode = appearance_bridge_user_mode($dbh, $userId);
        $autoEnabled = appearance_bridge_theme_auto_enabled($dbh, $userId);
        appearance_bridge_print_profile_builtin_light_critical($mode, $autoEnabled);
    }

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

    $autoEnabled = appearance_bridge_theme_auto_enabled($dbh, $userId);
    $autoEnabledJson = $autoEnabled ? 'true' : 'false';
    $manualModeJson = json_encode($manualMode);
    $appearanceMode = json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $paletteJson = json_encode(appearance_palette_js_map(), JSON_UNESCAPED_SLASHES);

    echo '<script>window.__MSB_APPEARANCE_PALETTES = ' . $paletteJson . ';window.__MSB_THEME_USER_ID = ' . $userId . ';window.__MSB_THEME_DEFAULTS = {autoEnabled: ' . $autoEnabledJson . ', manualMode: ' . $manualModeJson . ', appearanceMode: ' . $appearanceMode . '};window.__MSB_THEME_DB_MODE = ' . $appearanceMode . ';window.__MSBThemePrefsUserId = ' . $userId . ';window.__MSBThemePrefs = {autoEnabled: ' . $autoEnabledJson . ', manualMode: ' . $manualModeJson . ', appearanceMode: ' . $appearanceMode . '};</script>' . "\n";
    if (appearance_bridge_org_uses_light_canvas($mode, $autoEnabled)) {
        appearance_bridge_print_org_canvas_attrs($mode, $autoEnabled);
        if (!$orgSurface) {
            appearance_bridge_print_pub_light_critical();
        }
    }

    $prefix = appearance_bridge_normalize_asset_prefix($assetPrefix);
    if (empty($GLOBALS['__MSB_THEME_BOOTSTRAP_JS'])) {
        $GLOBALS['__MSB_THEME_BOOTSTRAP_JS'] = true;
        echo '<script src="' . htmlspecialchars($prefix . 'js/theme-bootstrap.js?v=105', ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }
    if (!defined('MSB_APPEARANCE_PALETTE_CSS')) {
        define('MSB_APPEARANCE_PALETTE_CSS', true);
        echo '<link rel="stylesheet" href="' . htmlspecialchars($prefix . 'css/appearance-palette.css?v=88', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    appearance_bridge_print_css_link($assetPrefix);
    if (!defined('MSB_THEME_DARK_CSS')) {
        define('MSB_THEME_DARK_CSS', true);
        echo '<link rel="stylesheet" href="' . htmlspecialchars($prefix . 'css/dark-auto.css?v=32', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if (!defined('MSB_THEME_DARK_JS')) {
        define('MSB_THEME_DARK_JS', true);
        echo '<script src="' . htmlspecialchars($prefix . 'js/dark-auto.js?v=8', ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }
}

/** Final org canvas paint — loads in footer so it wins over page/header CSS. */
function appearance_bridge_print_org_tail_critical(PDO $dbh, int $userId): void
{
    if (!empty($GLOBALS['__MSB_ORG_TAIL_CRITICAL_PRINTED']) || $userId <= 0) {
        return;
    }
    $GLOBALS['__MSB_ORG_TAIL_CRITICAL_PRINTED'] = true;

    $mode = appearance_bridge_user_mode($dbh, $userId);
    $autoEnabled = appearance_bridge_theme_auto_enabled($dbh, $userId);
    $hasNamedPalette = appearance_bridge_is_named_palette($mode);

    if (appearance_bridge_org_uses_named_canvas($mode, $autoEnabled)) {
        $pageBg = appearance_palette_unified_bg_hex($mode);
        $usesDarkChrome = appearance_palette_uses_dark_chrome($mode);
        $text = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
        $muted = $usesDarkChrome ? '#cbd5e1' : appearance_palette_chromatic_muted_hex($mode);
        $icon = $text;
        $action = appearance_palette_chromatic_action_hex($mode);
        $pageBgAttr = htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8');
        $textAttr = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $mutedAttr = htmlspecialchars($muted, ENT_QUOTES, 'UTF-8');
        $iconAttr = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
        $actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $orgSurfaces = 'html[data-msb-appearance],html[data-msb-appearance] body.org-app,'
            . 'html[data-msb-appearance] body.org-app .sh-mainpanel,'
            . 'html[data-msb-appearance] body.org-app .sh-pagebody,'
            . 'html[data-msb-appearance] body.org-app .sh-sideleft-menu,'
            . 'html[data-msb-appearance] body.org-app .sh-logopanel,'
            . 'html[data-msb-appearance] body.org-app .sh-headpanel,'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-top,'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-bottom,'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-scroll,'
            . 'html[data-msb-appearance] body.org-app .chat-shell,'
            . 'html[data-msb-appearance] body.org-app .chat-left,'
            . 'html[data-msb-appearance] body.org-app .chat-right,'
            . 'html[data-msb-appearance] body.org-app .card.fixed-card,'
            . 'html[data-msb-appearance] body.org-app .card,'
            . 'html[data-msb-appearance] body.org-app .card-body,'
            . 'html[data-msb-appearance] body.org-app .commerce-page,'
            . 'html[data-msb-appearance] body.org-app .commerce-card,'
            . 'html[data-msb-appearance] body.org-app .commerce-table-wrap,'
            . 'html[data-msb-appearance] body.org-app .product-table-page';
        echo '<style id="org-tail-palette-critical">'
            . 'html[data-msb-appearance],html.msb-palette-active{--msb-palette-bg:' . $pageBgAttr . ';--org-bg:' . $pageBgAttr . ';'
            . '--org-page-bg:' . $pageBgAttr . ';--bg-main:' . $pageBgAttr . ';--bg-card:' . $pageBgAttr . ';'
            . '--msb-palette-text:' . $textAttr . ';--msb-palette-text-muted:' . $mutedAttr . ';'
            . '--msb-palette-icon:' . $iconAttr . ';--msb-palette-action:' . $actionAttr . ';'
            . '--msb-palette-text-on-nav:' . $textAttr . ';}'
            . $orgSurfaces . '{'
            . 'background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;background-image:none!important;'
            . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
            . 'html[data-msb-appearance] body.org-app .sh-sideleft-menu,'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-top,'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-bottom,'
            . 'html.msb-palette-active body.org-app .sh-sideleft-menu,'
            . 'html.msb-palette-active body.org-app .org-sideleft-top,'
            . 'html.msb-palette-active body.org-app .org-sideleft-bottom{'
            . 'background-color:var(--msb-palette-bg,' . $pageBgAttr . ')!important;background-image:none!important;}'
            . 'html[data-msb-appearance] body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link,'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-scroll .nav > .nav-item > .nav-link,'
            . 'html[data-msb-appearance] body.org-app .sh-sidebar-label,'
            . 'html[data-msb-appearance] body.org-app .org-logo-label{'
            . 'color:var(--msb-palette-text,' . $textAttr . ')!important;}'
            . 'html[data-msb-appearance] body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link i,'
            . 'html[data-msb-appearance] body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link [class*="ion-"],'
            . 'html[data-msb-appearance] body.org-app .org-sideleft-scroll .nav > .nav-item > .nav-link i{'
            . 'color:var(--msb-palette-icon,var(--msb-palette-text,' . $textAttr . '))!important;}'
            . 'html[data-msb-appearance] body.org-app .org-sales-nav-badge rect,'
            . 'html.msb-palette-active body.org-app .org-sales-nav-badge rect{fill:#dc3545!important;}'
            . 'html[data-msb-appearance] body.org-app .org-sales-nav-badge text,'
            . 'html.msb-palette-active body.org-app .org-sales-nav-badge text{fill:#ffffff!important;color:#ffffff!important;}'
            . 'html[data-msb-appearance] body.org-app .commerce-page{'
            . '--ch-bg:var(--msb-palette-bg,' . $pageBgAttr . ');'
            . '--ch-surface:var(--msb-palette-surface,var(--msb-palette-bg,' . $pageBgAttr . '));}'
            . '</style>' . "\n";
        return;
    }

    if (appearance_bridge_org_uses_light_canvas($mode, $autoEnabled) && !appearance_bridge_org_uses_named_canvas($mode, $autoEnabled)) {
        $darkBg = htmlspecialchars(appearance_bridge_org_page_bg(), ENT_QUOTES, 'UTF-8');
        if ($autoEnabled) {
            echo '<script>(function(){var r=document.documentElement,h=(new Date()).getHours();'
                . 'r.setAttribute("data-msb-theme-auto","1");'
                . 'if(h>=17||h<6){r.removeAttribute("data-msb-org-light");}'
                . 'else{r.setAttribute("data-msb-org-light","1");}'
                . '})();</script>' . "\n";
        } else {
            echo '<script>document.documentElement.setAttribute("data-msb-org-light","1");</script>' . "\n";
        }
        $lightScope = 'html:not(.dark-auto) body.org-app';
        echo '<style id="org-tail-light-critical">'
            . $lightScope . ',' . $lightScope . ' .sh-mainpanel,' . $lightScope . ' .sh-pagebody,'
            . $lightScope . ' .sh-sideleft-menu,' . $lightScope . ' .org-sideleft-scroll,'
            . $lightScope . ' .feed-sidebar,' . $lightScope . ' .feed-card,'
            . $lightScope . ' .feed-toolbar,' . $lightScope . ' .feed-layout,' . $lightScope . ' .feed-main,'
            . $lightScope . ' .sidebar-head,' . $lightScope . ' .sidebar-list,'
            . $lightScope . ' .commerce-page,' . $lightScope . ' .commerce-kpi,'
            . $lightScope . ' .commerce-panel,' . $lightScope . ' .commerce-action-tile,'
            . $lightScope . ' .commerce-panel-head,' . $lightScope . ' .commerce-table-wrap,'
            . $lightScope . ' .compose-card,' . $lightScope . ' .compose-intro,'
            . $lightScope . ' .compose-preview,' . $lightScope . ' .actions-fixed,'
            . $lightScope . ' .rows-scroll,' . $lightScope . ' .card-body-fixed{'
            . 'background-color:#ffffff!important;background-image:none!important;color:#111827!important;'
            . 'border-color:rgba(15,23,42,0.12)!important;}'
            . ($autoEnabled
                ? 'html[data-msb-theme-auto]:not(.dark-auto) body.org-app a.feed-tab-link.active,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app a.feed-tab-link.active,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app .btn-primary,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app .btn-success,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app a.btn-primary,'
                  . 'html[data-msb-org-light]:not(.dark-auto) body.org-app a.btn-success{color:#ffffff!important;}'
                : '')
            . $lightScope . ' .commerce-page{--ch-bg:#ffffff;--ch-surface:#ffffff;'
            . '--ch-ink:#111827;--ch-muted:#64748b;--ch-line:rgba(15,23,42,0.12);}'
            . $lightScope . ' .commerce-kpi-label,' . $lightScope . ' .commerce-kpi-sub,'
            . $lightScope . ' .commerce-panel-head span,' . $lightScope . ' .commerce-action-tile span{'
            . 'color:#64748b!important;}'
            . 'html.dark-auto body.org-app,html.dark-auto body.org-app .sh-mainpanel,'
            . 'html.dark-auto body.org-app .sh-pagebody,html.dark-auto body.org-app .sh-sideleft-menu,'
            . 'html.dark-auto body.org-app .org-sideleft-scroll,html.dark-auto body.org-app .feed-sidebar,'
            . 'html.dark-auto body.org-app .feed-card,html.dark-auto body.org-app .feed-toolbar,'
            . 'html.dark-auto body.org-app .feed-layout,html.dark-auto body.org-app .feed-main,'
            . 'html.dark-auto body.org-app .feed-post-detail,html.dark-auto body.org-app .feed-compose,'
            . 'html.dark-auto body.org-app .comment-item,html.dark-auto body.org-app .sidebar-head,'
            . 'html.dark-auto body.org-app .sidebar-list,html.dark-auto body.org-app .rows-scroll,'
            . 'html.dark-auto body.org-app .compose-card,html.dark-auto body.org-app .compose-intro,'
            . 'html.dark-auto body.org-app .compose-preview,html.dark-auto body.org-app .actions-fixed,'
            . 'html.dark-auto body.org-app .card-body-fixed,html.dark-auto body.org-app .chat-shell,'
            . 'html.dark-auto body.org-app .fixed-card,html.dark-auto body.org-app .fixed-head,'
            . 'html.dark-auto body.org-app .fixed-body,html.dark-auto body.org-app .members-list-wrap,'
            . 'html.dark-auto body.org-app .conv-topbar,html.dark-auto body.org-app .history-card,'
            . 'html.dark-auto body.org-app .msg-scroll,html.dark-auto body.org-app .composer-fixed,'
            . 'html.dark-auto body.org-app .list-group-item,html.dark-auto body.org-app .commerce-page,'
            . 'html.dark-auto body.org-app .commerce-kpi,html.dark-auto body.org-app .commerce-panel,'
            . 'html.dark-auto body.org-app .commerce-action-tile,html.dark-auto body.org-app .commerce-panel-head,'
            . 'html.dark-auto body.org-app .commerce-brand-system-panel,html.dark-auto body.org-app .commerce-int-item,'
            . 'html.dark-auto body.org-app .commerce-channel,html.dark-auto body.org-app .commerce-table-wrap,'
            . 'html.dark-auto body.org-app .sales-management-metric,html.dark-auto body.org-app .sales-management-table-wrap{'
            . 'background-color:' . $darkBg . '!important;background-image:none!important;color:#e8edf5!important;}'
            . 'html.dark-auto body.org-app .commerce-page{--ch-bg:' . $darkBg . ';--ch-surface:' . $darkBg . ';'
            . '--ch-ink:#e8edf5;--ch-muted:#b1bcce;--ch-line:#334155;--ch-card:' . $darkBg . ';}'
            . 'html.dark-auto body.org-app .sidebar-search,html.dark-auto body.org-app .feed-compose-input,'
            . 'html.dark-auto body.org-app .org-pill,html.dark-auto body.org-app .ig-feed-account-badge,'
            . 'html.dark-auto body.org-app .form-control,html.dark-auto body.org-app select.form-control,'
            . 'html.dark-auto body.org-app textarea.form-control,'
            . 'html.dark-auto body.org-app .members-search input,html.dark-auto body.org-app .history-search input,'
            . 'html.dark-auto body.org-app .composer-row textarea{'
            . 'background-color:#252f3d!important;background-image:none!important;color:#e8edf5!important;'
            . 'border-color:rgba(177,188,206,.38)!important;}'
            . 'html.dark-auto body.org-app{--org-page-bg:' . $darkBg . ';--org-bg:' . $darkBg . ';'
            . '--bg-main:' . $darkBg . ';--bg-card:' . $darkBg . ';--bg-sidebar:' . $darkBg . ';'
            . '--org-surface:' . $darkBg . ';--org-input-bg:#252f3d;}'
            . '</style>' . "\n";
        return;
    }

    echo '<style id="org-tail-dark-critical">'
        . 'html:not([data-msb-appearance]):not([data-msb-org-light]) body.org-app,'
        . 'html:not([data-msb-appearance]):not([data-msb-org-light]) body.org-app .sh-mainpanel,'
        . 'html:not([data-msb-appearance]):not([data-msb-org-light]) body.org-app .sh-pagebody,'
        . 'html:not([data-msb-appearance]):not([data-msb-org-light]) body.org-app .commerce-page{'
        . 'background-color:#171d24!important;background-image:none!important;}'
        . '</style>' . "\n";
}
