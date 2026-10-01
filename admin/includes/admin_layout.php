<?php
declare(strict_types=1);

if (!function_exists('admin_theme_viewer_user_id')) {
    /**
     * Public-user id whose Appearance (Dark auto / Appearance color / Progress color)
     * should paint the Admin shell. Prefers linked personal, then publisher.
     */
    function admin_theme_viewer_user_id(?PDO $dbh = null): int
    {
        static $cached = null;
        if ($cached !== null) {
            return (int)$cached;
        }
        $cached = 0;
        try {
            if (!$dbh instanceof PDO && function_exists('adminDbh')) {
                $dbh = adminDbh();
            }
            if (!$dbh instanceof PDO) {
                return 0;
            }
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            if ($adminId <= 0) {
                return 0;
            }
            require_once __DIR__ . '/admin_linked_accounts_load.php';
            if (function_exists('admin_linked_ensure_provisioned')) {
                admin_linked_ensure_provisioned($dbh, $adminId);
            }
            $admin = function_exists('admin_linked_fetch_admin')
                ? admin_linked_fetch_admin($dbh, $adminId)
                : null;
            if (!is_array($admin)) {
                $st = $dbh->prepare('SELECT linked_personal_user_id, linked_publisher_user_id FROM admin WHERE idadmin = :id LIMIT 1');
                $st->execute([':id' => $adminId]);
                $admin = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $personalId = (int)($admin['linked_personal_user_id'] ?? 0);
            $publisherId = (int)($admin['linked_publisher_user_id'] ?? 0);
            $cached = $personalId > 0 ? $personalId : $publisherId;
        } catch (Throwable $e) {
            $cached = 0;
        }
        return (int)$cached;
    }
}

if (!function_exists('admin_layout_head_assets')) {
    function admin_layout_head_assets(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;

        // Appearance from Settings (Dark auto / Appearance color / Progress color).
        try {
            $dbh = function_exists('adminDbh') ? adminDbh() : null;
            if ($dbh instanceof PDO) {
                require_once __DIR__ . '/../../public_user/includes/appearance_bridge.php';
                $viewerId = admin_theme_viewer_user_id($dbh);
                if ($viewerId > 0) {
                    appearance_bridge_print_theme_stack($dbh, $viewerId, '../public_user/', true, false);
                } else {
                    // Cookie fallback when no linked public user yet.
                    require_once __DIR__ . '/../../public_user/includes/appearance_palettes.php';
                    $mode = appearance_bridge_read_cookie_mode();
                    $auto = ($mode === 'system');
                    appearance_bridge_print_early_dark_auto_class($auto);
                    if ($mode === 'light' || (!$auto && $mode === 'system')) {
                        echo '<script>document.documentElement.setAttribute("data-msb-org-light","1");</script>' . "\n";
                    }
                    if ($mode !== 'system' && $mode !== 'light' && $mode !== 'dark') {
                        $pageBg = appearance_palette_unified_bg_hex($mode);
                        $text = appearance_palette_uses_dark_chrome($mode) ? '#f3f6fb' : appearance_palette_chromatic_text_hex($mode);
                        $muted = appearance_palette_uses_dark_chrome($mode) ? '#cbd5e1' : appearance_palette_chromatic_muted_hex($mode);
                        $action = appearance_palette_chromatic_action_hex($mode);
                        $modeJson = json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                        echo '<script>document.documentElement.setAttribute("data-msb-appearance",' . $modeJson . ');'
                            . 'document.documentElement.removeAttribute("data-msb-org-light");</script>' . "\n";
                        echo '<style id="admin-cookie-palette-critical">'
                            . 'html[data-msb-appearance]{--msb-palette-bg:' . htmlspecialchars($pageBg, ENT_QUOTES, 'UTF-8') . ';'
                            . '--msb-palette-text:' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . ';'
                            . '--msb-palette-text-muted:' . htmlspecialchars($muted, ENT_QUOTES, 'UTF-8') . ';'
                            . '--msb-palette-action:' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . ';}'
                            . '</style>' . "\n";
                    }
                    if (!defined('MSB_THEME_DARK_CSS')) {
                        define('MSB_THEME_DARK_CSS', true);
                        echo '<link rel="stylesheet" href="../public_user/css/dark-auto.css?v=57">' . "\n";
                    }
                    if (!defined('MSB_APPEARANCE_PALETTE_CSS')) {
                        define('MSB_APPEARANCE_PALETTE_CSS', true);
                        echo '<link rel="stylesheet" href="../public_user/css/appearance-palette.css?v=131">' . "\n";
                    }
                    appearance_bridge_print_css_link('../public_user/');
                    if (empty($GLOBALS['__MSB_THEME_BOOTSTRAP_JS'])) {
                        $GLOBALS['__MSB_THEME_BOOTSTRAP_JS'] = true;
                        echo '<script src="../public_user/js/theme-bootstrap.js?v=143"></script>' . "\n";
                    }
                    if (!defined('MSB_THEME_DARK_JS')) {
                        define('MSB_THEME_DARK_JS', true);
                        echo '<script src="../public_user/js/dark-auto.js?v=8" defer></script>' . "\n";
                    }
                }
            }
        } catch (Throwable $e) {
            // keep default admin chrome
        }

        // Labeled grouped sidebar (248px) — navigate colors follow Appearance tokens.
        echo '<style id="admin-layout-critical">'
            . 'html,body{background:var(--msb-palette-bg,var(--azia-bg,#f8f9fa));}'
            . ':root{--admin-nav-bg:#0b1220;--admin-nav-text:#cbd5e1;--admin-nav-muted:#94a3b8;--admin-nav-active:#2563eb;}'
            . 'html.dark-auto{--admin-nav-bg:var(--org-page-bg,#171d24);--admin-nav-text:#e8edf5;--admin-nav-muted:#94a3b8;}'
            . 'html[data-msb-org-light]:not(.dark-auto):not([data-msb-appearance]){'
            . '--admin-nav-bg:#ffffff;--admin-nav-text:#0f172a;--admin-nav-muted:#64748b;--admin-nav-active:#2563eb;'
            . '}'
            . 'html[data-msb-appearance],html.msb-palette-active{'
            . '--admin-nav-bg:var(--msb-palette-bg,#0b1220);'
            . '--admin-nav-text:var(--msb-palette-text,#e8edf5);'
            . '--admin-nav-muted:var(--msb-palette-text-muted,#94a3b8);'
            . '--admin-nav-active:var(--msb-palette-action,#2563eb);'
            . '--azia-bg:var(--msb-palette-bg);--azia-text:var(--msb-palette-text);'
            . '}'
            . 'html[data-msb-appearance] body.azia-admin,html[data-msb-appearance] body.admin-app,'
            . 'html.dark-auto body.azia-admin,html.dark-auto body.admin-app,'
            . 'html[data-msb-org-light]:not(.dark-auto) body.azia-admin,html[data-msb-org-light]:not(.dark-auto) body.admin-app{'
            . 'background-color:var(--msb-palette-bg,var(--admin-nav-bg,#f8f9fa))!important;color:var(--msb-palette-text,var(--admin-nav-text))!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-logopanel,html[data-msb-appearance] .sh-sideleft-menu,'
            . 'html.dark-auto .sh-logopanel,html.dark-auto .sh-sideleft-menu,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-logopanel,html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu,'
            . 'html[data-msb-appearance] body.azia-admin .sh-logopanel,html[data-msb-appearance] body.azia-admin .sh-sideleft-menu,'
            . 'html.dark-auto body.azia-admin .sh-logopanel,html.dark-auto body.azia-admin .sh-sideleft-menu,'
            . 'html[data-msb-org-light]:not(.dark-auto) body.azia-admin .sh-logopanel,html[data-msb-org-light]:not(.dark-auto) body.azia-admin .sh-sideleft-menu{'
            . 'background:var(--admin-nav-bg)!important;color:var(--admin-nav-text)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .nav > .nav-item > .nav-link,'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-group-toggle,'
            . 'html.dark-auto .sh-sideleft-menu .nav > .nav-item > .nav-link,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-group-toggle,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .nav > .nav-item > .nav-link,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-group-toggle{'
            . 'color:var(--admin-nav-text)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .nav > .nav-item > .nav-link i,'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-group-toggle i,'
            . 'html.dark-auto .sh-sideleft-menu .nav > .nav-item > .nav-link i,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-group-toggle i,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .nav > .nav-item > .nav-link i,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-group-toggle i{'
            . 'color:var(--admin-nav-text)!important;opacity:1!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-section span,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-section span,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-section span{color:var(--admin-nav-muted)!important;}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-sub .nav-link,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-sub .nav-link,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-sub .nav-link{'
            . 'color:var(--admin-nav-text)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-sub .nav-link:hover,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-sub .nav-link:hover,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-sub .nav-link:hover{'
            . 'background:transparent!important;color:var(--msb-palette-action,#7da6ff)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-sub .nav-link:hover > span:not(.admin-nav-badge),'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-sub .nav-link:hover > span:not(.admin-nav-badge),'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-sub .nav-link:hover > span:not(.admin-nav-badge){'
            . 'color:var(--msb-palette-action,#7da6ff)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-sub .nav-link.active,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-sub .nav-link.active,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-sub .nav-link.active,'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-sub .nav-link.active:hover,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-sub .nav-link.active:hover,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-sub .nav-link.active:hover{'
            . 'background:transparent!important;color:var(--msb-palette-action,#7da6ff)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-sub .nav-link.active > span:not(.admin-nav-badge),'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-sub .nav-link.active > span:not(.admin-nav-badge),'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-sub .nav-link.active > span:not(.admin-nav-badge){'
            . 'color:var(--msb-palette-action,#7da6ff)!important;'
            . '}'
            . 'html[data-msb-appearance] .sh-sideleft-menu .nav > .nav-item > .nav-link.active,'
            . 'html[data-msb-appearance] .sh-sideleft-menu .admin-nav-group-toggle.is-active,'
            . 'html.dark-auto .sh-sideleft-menu .nav > .nav-item > .nav-link.active,'
            . 'html.dark-auto .sh-sideleft-menu .admin-nav-group-toggle.is-active,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .nav > .nav-item > .nav-link.active,'
            . 'html[data-msb-org-light]:not(.dark-auto) .sh-sideleft-menu .admin-nav-group-toggle.is-active{'
            . 'background:var(--msb-palette-action-soft,rgba(37,99,235,.16))!important;color:var(--admin-nav-active)!important;'
            . '}'
            . 'html[data-msb-appearance] .azia-brand,html.dark-auto .azia-brand,html[data-msb-org-light]:not(.dark-auto) .azia-brand{color:var(--msb-palette-action,var(--admin-nav-active))!important;}'
            . '@media (min-width:1200px){'
            . '.sh-logopanel{left:0!important;width:248px!important;}'
            . '.sh-sideleft-menu{left:0!important;width:248px!important;}'
            . '.sh-headpanel{left:248px!important;}'
            . '.sh-mainpanel{margin-left:248px!important;}'
            . '}'
            . '</style>' . "\n";
        echo '<link rel="stylesheet" href="css/admin-layout.css?v=32">' . "\n";
        echo '<link rel="stylesheet" href="css/admin-tables-shamcey.css?v=9">' . "\n";
        echo '<link rel="stylesheet" href="css/admin-ui-scale.css?v=5">' . "\n";
        echo '<link rel="stylesheet" href="css/admin-appearance-contrast.css?v=16">' . "\n";
        echo '<script defer src="js/admin-fries-menu.js?v=1"></script>' . "\n";
    }
}

if (!function_exists('admin_layout_footer_assets')) {
    function admin_layout_footer_assets(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;
        // Intentionally no admin-nav.js — use normal full-page link navigation.
    }
}

if (!function_exists('admin_layout_current_page')) {
    function admin_layout_current_page(): string
    {
        return basename($_SERVER['PHP_SELF'] ?? '');
    }
}

if (!function_exists('admin_layout_nav_class')) {
    function admin_layout_nav_class(string $page, ?string $currentPage = null): string
    {
        $currentPage = $currentPage ?? admin_layout_current_page();
        return ($page === $currentPage) ? 'nav-link active' : 'nav-link';
    }
}

if (!function_exists('admin_layout_nav_attrs')) {
    function admin_layout_nav_attrs(string $href, bool $enabled = true): string
    {
        // Full page navigation only — do not mark links for AJAX admin-nav.
        // (SPA nav was dropping the admin session for some pages, e.g. Service Fees.)
        return '';
    }
}

if (!function_exists('admin_nav_badge_label')) {
    function admin_nav_badge_label(int $count): string
    {
        if ($count <= 0) {
            return '';
        }
        return $count > 99 ? '99+' : (string)$count;
    }
}

if (!function_exists('admin_nav_badge_html')) {
    /** Visible count pill for icon-only admin sidebar links. */
    function admin_nav_badge_html(int $count): string
    {
        $label = admin_nav_badge_label($count);
        if ($label === '') {
            return '';
        }
        // Use <b> (not <span>) — icon rail CSS hides label spans.
        return '<b class="admin-nav-badge" aria-hidden="true">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>';
    }
}

if (!function_exists('admin_nav_attention_counts')) {
    /**
     * Pending/attention counts for sidebar badges across admin workspaces.
     *
     * @return array<string,int>
     */
    function admin_nav_attention_counts(PDO $dbh): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $counts = [
            'publisher_requests' => 0,
            'shop_rent' => 0,
            'commerce_brands' => 0,
            'stripe_connect' => 0,
            'reports' => 0,
            'inbox' => 0,
            'disputes' => 0,
            'product_disputes' => 0,
            'support_customer' => 0,
            'support_seller' => 0,
            'support_publisher' => 0,
            'support_personal' => 0,
            'support_internal' => 0,
            'notifications' => 0,
            'notifications_security' => 0,
            'orders_pending' => 0,
            'products_new' => 0,
            'public_total' => 0,
            'activity_total' => 0,
            'admin_total' => 0,
            'publisher_total' => 0,
            'commerce_total' => 0,
            'help_total' => 0,
            'total' => 0,
        ];

        $adminReceivers = ['Admin'];
        $friendCode = trim((string)($_SESSION['admin_friend_code'] ?? ''));
        if ($friendCode !== '' && strcasecmp($friendCode, 'Admin') !== 0) {
            $adminReceivers[] = $friendCode;
        }
        $adminUsername = trim((string)($_SESSION['admin_login'] ?? ''));
        $adminEmail = trim((string)($_SESSION['admin_email'] ?? ''));

        // Keys used for internal staff chat (friend_code / username / email legacy).
        $meMessageKeys = [];
        foreach ([$friendCode, $adminUsername, $adminEmail] as $k) {
            $k = trim((string)$k);
            if ($k !== '' && strcasecmp($k, 'Admin') !== 0 && !in_array($k, $meMessageKeys, true)) {
                $meMessageKeys[] = $k;
            }
        }

        $supportNotReportSql = "
            COALESCE(title, '') <> 'Content Report'
            AND COALESCE(feedbackdata, '') NOT LIKE '[Report #%'
            AND COALESCE(feedbackdata, '') NOT LIKE 'Reporter message:%'
            AND COALESCE(title, '') NOT LIKE '%Dispute%'
            AND COALESCE(feedbackdata, '') NOT LIKE '[Dispute]%'
            AND COALESCE(feedbackdata, '') NOT LIKE '[Seller dispute]%'
            AND channel <> 'dispute'
        ";

        try {
            require_once __DIR__ . '/../../public_user/includes/publisher_authority.php';
            $counts['publisher_requests'] = publisher_authority_pending_count($dbh);
        } catch (Throwable $e) {
            $counts['publisher_requests'] = 0;
        }

        try {
            $st = $dbh->query("
                SELECT COUNT(*)
                FROM organizations
                WHERE (is_publisher_org = 1 OR org_kind = 'shop')
                  AND rent_status IN ('overdue', 'suspended')
            ");
            $counts['shop_rent'] = (int)($st ? $st->fetchColumn() : 0);
        } catch (Throwable $e) {
            $counts['shop_rent'] = 0;
        }

        try {
            $st = $dbh->query("
                SELECT COUNT(*)
                FROM organizations
                WHERE (is_publisher_org = 1 OR org_kind = 'shop')
                  AND (commerce_brand_id IS NULL OR commerce_brand_id = 0)
            ");
            $counts['commerce_brands'] = (int)($st ? $st->fetchColumn() : 0);
        } catch (Throwable $e) {
            $counts['commerce_brands'] = 0;
        }

        try {
            require_once __DIR__ . '/org_admin_helpers_load.php';
            $counts['stripe_connect'] = function_exists('org_admin_connect_incomplete_count')
                ? org_admin_connect_incomplete_count($dbh)
                : 0;
        } catch (Throwable $e) {
            $counts['stripe_connect'] = 0;
        }

        try {
            require_once __DIR__ . '/../../public_user/includes/msb_reports.php';
            $counts['reports'] = msb_reports_pending_count($dbh);
        } catch (Throwable $e) {
            $counts['reports'] = 0;
        }

        // Unread Help inbox (customer / seller / publisher / personal) → Admin.
        try {
            $st = $dbh->prepare("
                SELECT COUNT(*)
                FROM feedback_admin
                WHERE is_read = 0
                  AND receiver = 'Admin'
                  AND channel = 'user_admin'
                  AND ({$supportNotReportSql})
            ");
            $st->execute();
            $counts['inbox'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $counts['inbox'] = 0;
        }

        // Unread internal staff ↔ admin messages (friend_code / username / email receivers).
        try {
            $internalChannels = [];
            if (!function_exists('allowedInternalChannelsForMe')) {
                require_once __DIR__ . '/identity.php';
            }
            if (function_exists('allowedInternalChannelsForMe')) {
                $internalChannels = allowedInternalChannelsForMe();
            }
            if ($internalChannels === []) {
                $roleName = '';
                try {
                    if (function_exists('baseRoleName')) {
                        $roleName = baseRoleName($dbh, (int)($_SESSION['userRole'] ?? 0));
                    }
                } catch (Throwable $eRole) {
                    $roleName = '';
                }
                $roleName = strtolower(trim($roleName));
                if ($roleName === 'admin') {
                    $internalChannels = ['admin_manager', 'admin_staff', 'admin_admin', 'manager_staff', 'manager_manager', 'staff_staff', 'admin_internal'];
                } elseif ($roleName === 'manager') {
                    $internalChannels = ['admin_manager', 'manager_manager', 'manager_staff', 'admin_internal'];
                } elseif ($roleName === 'staff') {
                    $internalChannels = ['admin_staff', 'staff_staff', 'manager_staff', 'admin_internal'];
                } else {
                    $internalChannels = ['admin_internal'];
                }
            } else {
                $internalChannels[] = 'admin_internal';
                $internalChannels = array_values(array_unique($internalChannels));
            }

            if ($meMessageKeys !== [] && $internalChannels !== []) {
                $rPh = [];
                $cPh = [];
                $params = [];
                foreach ($meMessageKeys as $i => $k) {
                    $key = ':ir' . $i;
                    $rPh[] = $key;
                    $params[$key] = $k;
                }
                foreach ($internalChannels as $i => $ch) {
                    $key = ':ic' . $i;
                    $cPh[] = $key;
                    $params[$key] = $ch;
                }
                $st = $dbh->prepare('
                    SELECT COUNT(*)
                    FROM feedback_admin
                    WHERE is_read = 0
                      AND receiver IN (' . implode(',', $rPh) . ')
                      AND channel IN (' . implode(',', $cPh) . ')
                ');
                $st->execute($params);
                $counts['support_internal'] = (int)$st->fetchColumn();
            }
        } catch (Throwable $e) {
            $counts['support_internal'] = 0;
        }

        // Lane split so workspace badges can show customer vs seller vs publisher help.
        $laneCount = static function (PDO $dbh, array $receivers, string $lane, string $notReportSql): int {
            try {
                $placeholders = [];
                $params = [':lane_scope' => $lane];
                foreach ($receivers as $i => $receiver) {
                    $key = ':lr' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $receiver;
                }
                if ($lane === 'seller') {
                    $fallback = "(
                        (COALESCE(title, '') LIKE 'Seller%' OR COALESCE(feedbackdata, '') LIKE '[Seller %')
                        AND COALESCE(title, '') NOT LIKE '%Dispute%'
                        AND COALESCE(feedbackdata, '') NOT LIKE '[Seller dispute]%'
                    )";
                } elseif ($lane === 'publisher') {
                    $fallback = "(
                        COALESCE(title, '') LIKE 'Publisher%'
                        OR COALESCE(feedbackdata, '') LIKE '[Publisher %'
                    )";
                } elseif ($lane === 'personal') {
                    $fallback = "(
                        COALESCE(title, '') LIKE 'Personal%'
                        OR COALESCE(feedbackdata, '') LIKE '[Personal %'
                    )";
                } else {
                    $fallback = "(
                        COALESCE(title, '') LIKE 'Customer Help%'
                        OR COALESCE(feedbackdata, '') LIKE '[Help] %'
                    )";
                }
                $st = $dbh->prepare("
                    SELECT COUNT(*)
                    FROM feedback_admin
                    WHERE is_read = 0
                      AND receiver IN (" . implode(',', $placeholders) . ")
                      AND channel = 'user_admin'
                      AND ({$notReportSql})
                      AND (
                            LOWER(TRIM(COALESCE(scope, ''))) = :lane_scope
                         OR (
                              TRIM(COALESCE(scope, '')) = ''
                              AND {$fallback}
                            )
                      )
                ");
                $st->execute($params);
                return max(0, (int)$st->fetchColumn());
            } catch (Throwable $e) {
                return 0;
            }
        };

        $counts['support_customer'] = $laneCount($dbh, $adminReceivers, 'customer', $supportNotReportSql);
        $counts['support_seller'] = $laneCount($dbh, $adminReceivers, 'seller', $supportNotReportSql);
        $counts['support_publisher'] = $laneCount($dbh, $adminReceivers, 'publisher', $supportNotReportSql);
        $counts['support_personal'] = $laneCount($dbh, $adminReceivers, 'personal', $supportNotReportSql);

        // Unread dispute thread messages (legacy feedback_admin disputes).
        try {
            $st = $dbh->query("
                SELECT COUNT(*)
                FROM feedback_admin
                WHERE is_read = 0
                  AND receiver = 'Admin'
                  AND (
                        channel = 'dispute'
                     OR (
                          channel = 'user_admin'
                          AND (
                            COALESCE(title, '') LIKE '%Dispute%'
                            OR COALESCE(feedbackdata, '') LIKE '[Dispute]%'
                            OR COALESCE(feedbackdata, '') LIKE '[Seller dispute]%'
                          )
                        )
                  )
            ");
            $counts['disputes'] = (int)($st ? $st->fetchColumn() : 0);
        } catch (Throwable $e) {
            $counts['disputes'] = 0;
        }

        // Open product / seller business dispute cases.
        try {
            require_once __DIR__ . '/../../public_user/includes/commerce_disputes.php';
            if (function_exists('commerce_dispute_list_open_admin')) {
                $counts['product_disputes'] = count(commerce_dispute_list_open_admin($dbh, 100));
            } else {
                commerce_disputes_ensure_schema($dbh);
                $st = $dbh->query("
                    SELECT COUNT(*)
                    FROM commerce_disputes
                    WHERE status IN ('open', 'seller_notified')
                       OR (customer_case_closed = 0 OR seller_case_closed = 0)
                ");
                $counts['product_disputes'] = (int)($st ? $st->fetchColumn() : 0);
            }
        } catch (Throwable $e) {
            $counts['product_disputes'] = 0;
        }

        // Admin notification bell (unread).
        try {
            require_once __DIR__ . '/admin_notifications_settings.php';
            $keys = function_exists('admin_notif_receiver_keys') ? admin_notif_receiver_keys() : ['Admin'];
            if ($keys === []) {
                $keys = ['Admin'];
            }
            $ph = [];
            $params = [];
            foreach ($keys as $i => $k) {
                $key = ':n' . $i;
                $ph[] = $key;
                $params[$key] = $k;
            }
            $st = $dbh->prepare("
                SELECT notitype
                FROM notification
                WHERE notireceiver IN (" . implode(',', $ph) . ")
                  AND is_read = 0
                ORDER BY id DESC
                LIMIT 120
            ");
            $st->execute($params);
            $unreadNotifs = 0;
            $securityNotifs = 0;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $type) {
                $unreadNotifs++;
                $classified = function_exists('admin_notif_classify_type')
                    ? admin_notif_classify_type((string)$type)
                    : 'system';
                $priority = function_exists('admin_notif_classify_priority')
                    ? admin_notif_classify_priority((string)$type, $classified)
                    : 'low';
                if ($classified === 'security' || $priority === 'high') {
                    $securityNotifs++;
                }
            }
            $counts['notifications'] = $unreadNotifs;
            $counts['notifications_security'] = $securityNotifs;
        } catch (Throwable $e) {
            $counts['notifications'] = 0;
            $counts['notifications_security'] = 0;
        }

        // Marketplace orders still pending / processing.
        try {
            $st = $dbh->query("
                SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(order_code), ''), CONCAT('id:', id)))
                FROM org_orders
                WHERE status IN ('pending', 'confirmed', 'paid', 'processing')
            ");
            $counts['orders_pending'] = (int)($st ? $st->fetchColumn() : 0);
        } catch (Throwable $e) {
            try {
                $st = $dbh->query("
                    SELECT COUNT(*)
                    FROM org_orders
                    WHERE status IN ('pending', 'confirmed', 'paid')
                ");
                $counts['orders_pending'] = (int)($st ? $st->fetchColumn() : 0);
            } catch (Throwable $e2) {
                $counts['orders_pending'] = 0;
            }
        }

        // Newly listed active products (last 7 days) — product oversight.
        try {
            $st = $dbh->query("
                SELECT COUNT(*)
                FROM org_products
                WHERE is_deleted = 0
                  AND status = 'active'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $counts['products_new'] = (int)($st ? $st->fetchColumn() : 0);
        } catch (Throwable $e) {
            $counts['products_new'] = 0;
        }

        $counts['public_total'] = (int)$counts['reports']
            + (int)$counts['support_customer']
            + (int)$counts['support_personal'];

        $counts['activity_total'] = (int)$counts['notifications_security'];

        $counts['admin_total'] = (int)$counts['notifications'];

        $counts['publisher_total'] = (int)$counts['publisher_requests']
            + (int)$counts['support_publisher'];

        // Commerce parent badge = sum of badges shown on Commerce submenu items only.
        $counts['commerce_total'] = (int)$counts['disputes']
            + (int)$counts['product_disputes']
            + (int)$counts['support_seller']
            + (int)$counts['stripe_connect']
            + (int)$counts['shop_rent']
            + (int)$counts['commerce_brands']
            + (int)$counts['orders_pending']
            + (int)$counts['products_new'];

        // Help = every unread message admin must not miss:
        // customer/seller/publisher/personal support + dispute threads + internal staff chat.
        $counts['help_total'] = (int)$counts['inbox']
            + (int)$counts['disputes']
            + (int)$counts['support_internal'];

        // Distinct attention for a global sense of workload.
        $counts['total'] = (int)$counts['reports']
            + (int)$counts['publisher_requests']
            + (int)$counts['inbox']
            + (int)$counts['disputes']
            + (int)$counts['support_internal']
            + (int)$counts['product_disputes']
            + (int)$counts['stripe_connect']
            + (int)$counts['shop_rent']
            + (int)$counts['commerce_brands']
            + (int)$counts['notifications']
            + (int)$counts['orders_pending']
            + (int)$counts['products_new'];

        $cached = $counts;
        return $counts;
    }
}
