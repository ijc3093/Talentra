<?php
declare(strict_types=1);

if (!function_exists('org_layout_head_assets')) {
    require_once __DIR__ . '/org_page_shell.php';

    function org_layout_head_assets(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;
        echo '<style id="org-layout-critical">'
            . '@media (min-width:1200px){'
            . '.sh-logopanel{left:0!important;}'
            . '.sh-sideleft-menu{left:0!important;}'
            . '.sh-headpanel{left:240px!important;}'
            . '.sh-mainpanel{margin-left:240px!important;}'
            . '}'
            . '</style>' . "\n";

        $page = org_layout_current_page();
        $isFeed = ($page === 'feed.php');
        echo '<script>document.documentElement.setAttribute("data-org-feed","' . ($isFeed ? '1' : '0') . '");</script>' . "\n";

        if (!$isFeed) {
            echo '<style id="org-no-feed-media">'
                . 'html[data-org-feed="0"] video,html[data-org-feed="0"] audio,'
                . 'html[data-org-feed="0"] .feed-post-media,html[data-org-feed="0"] .feed-layout,'
                . 'html[data-org-feed="0"] .feed-sidebar,html[data-org-feed="0"] .feed-main,'
                . 'html[data-org-feed="0"] .feed-card,html[data-org-feed="0"] .media-strip,'
                . 'html[data-org-feed="0"] #mainMedia,'
                . 'html[data-org-feed="0"] #readMoreModal,html[data-org-feed="0"] #repliesModal,'
                . 'html[data-org-feed="0"] #editPostModal,html[data-org-feed="0"] .read-more-modal,'
                . 'html[data-org-feed="0"] .modal-backdrop{'
                . 'display:none!important;visibility:hidden!important;'
                . 'width:0!important;height:0!important;max-width:0!important;max-height:0!important;'
                . 'opacity:0!important;pointer-events:none!important;'
                . 'position:fixed!important;left:-10000px!important;top:-10000px!important;'
                . 'z-index:-1!important;overflow:hidden!important;}'
                . '</style>' . "\n";
        }

        echo '<link rel="stylesheet" href="css/org-layout.css?v=28">' . "\n";
        echo '<link rel="stylesheet" href="css/org-compact.css?v=36">' . "\n";
        echo '<link rel="stylesheet" href="css/org-header-actions.css?v=1">' . "\n";
    }
}

if (!function_exists('org_layout_footer_assets')) {
    function org_layout_footer_assets(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;
        require_once __DIR__ . '/org_theme_prefs.php';
        require __DIR__ . '/org_footer_scripts.php';
        global $dbh;
        if (isset($dbh) && $dbh instanceof PDO) {
            $orgTailUserId = org_theme_viewer_user_id($dbh);
            if ($orgTailUserId > 0) {
                appearance_bridge_print_org_tail_critical($dbh, $orgTailUserId);
            }
        }
        echo '<link rel="stylesheet" href="css/org-contrast.css?v=25">' . "\n";
        echo '<style id="org-sales-nav-badge-lock">'
            . 'body.org-app .org-sales-nav-badge rect{fill:#dc3545!important;}'
            . 'body.org-app .org-sales-nav-badge text{fill:#ffffff!important;color:#ffffff!important;}'
            . '@keyframes orgSalesBadgeAlert{'
            . '0%,100%{transform:scale(1) translate(0,0);filter:drop-shadow(0 0 0 rgba(220,53,69,0));}'
            . '12%{transform:scale(1.14) translate(0,-1px);filter:drop-shadow(0 0 7px rgba(220,53,69,.9));}'
            . '24%{transform:scale(1.06) translate(0,0);filter:drop-shadow(0 0 3px rgba(220,53,69,.5));}'
            . '36%{transform:scale(1.12) translate(0,-1px);filter:drop-shadow(0 0 6px rgba(220,53,69,.75));}'
            . '50%,78%{transform:scale(1) translate(0,0);filter:drop-shadow(0 0 2px rgba(220,53,69,.35));}'
            . '82%{transform:scale(1.08) translate(-2px,0);filter:drop-shadow(0 0 5px rgba(220,53,69,.7));}'
            . '86%{transform:scale(1.08) translate(2px,0);}'
            . '90%{transform:scale(1.05) translate(-1px,0);}'
            . '94%{transform:scale(1.03) translate(1px,0);}}'
            . 'body.org-app .org-sales-nav-badge-wrap.is-alert{'
            . 'animation:orgSalesBadgeAlert 2.4s ease-in-out infinite;}'
            . '@media (prefers-reduced-motion:reduce){body.org-app .org-sales-nav-badge-wrap.is-alert{animation:none;}}'
            . '</style>' . "\n";
        echo '<link rel="stylesheet" href="css/org-workspace-pages.css?v=1">' . "\n";
        $currentPage = org_layout_current_page();
        if ($currentPage === 'messages.php') {
            echo '<link rel="stylesheet" href="css/org-messages-theme.css?v=3" id="org-messages-theme-css">' . "\n";
        }
        if ($currentPage === 'commerce.php'
            || $currentPage === 'sales_management.php'
            || strpos($currentPage, 'sales_') === 0
            || in_array($currentPage, [
                'orders.php', 'products.php', 'shop_settings.php', 'seller_journey.php',
                'quotations.php', 'invoices.php', 'payments.php', 'delivery.php',
                'returns_refunds.php', 'discounts_promotions.php', 'commerce_brand_select.php',
                'recent_orders.php', 'commerce_analytics.php',
            ], true)) {
            echo '<link rel="stylesheet" href="css/org-commerce-theme.css?v=2" id="org-commerce-theme-css">' . "\n";
        }
        echo '<script src="js/org-nav.js?v=18" defer></script>' . "\n";
    }
}

if (!function_exists('org_layout_current_page')) {
    function org_layout_current_page(): string
    {
        return basename($_SERVER['PHP_SELF'] ?? '');
    }
}

if (!function_exists('org_layout_nav_class')) {
    function org_layout_nav_class(string $page, ?string $currentPage = null): string
    {
        $currentPage = $currentPage ?? org_layout_current_page();
        return ($page === $currentPage) ? 'nav-link active' : 'nav-link';
    }
}

if (!function_exists('org_layout_nav_attrs')) {
    function org_layout_nav_attrs(string $href, bool $enabled = true): string
    {
        if (!$enabled) {
            return '';
        }

        $path = parse_url($href, PHP_URL_PATH) ?: $href;
        $base = basename($path);
        if ($base === '' || $base === 'logout.php') {
            return '';
        }

        if (strpos($path, '..') !== false) {
            return '';
        }

        if (!preg_match('/\.php$/i', $base)) {
            return '';
        }

        // Form/manager tool pages: full reload avoids feed video ghosts flashing during SPA swap.
        static $hardNavPages = [
            'compose_post.php',
            'create_staff.php',
            'create_org.php',
            'settings.php',
            'members.php',
            'posts.php',
            'commerce.php',
            'sales_management.php',
            'commerce_brand_select.php',
            'products.php',
            'product_table.php',
            'orders.php',
            'order_details.php',
            'quotations.php',
            'quotation_details.php',
            'invoices.php',
            'invoice_details.php',
            'payments.php',
            'delivery.php',
            'returns_refunds.php',
            'discounts_promotions.php',
            'sales_reports.php',
            'salespersons.php',
            'sales_notifications.php',
            'shop_settings.php',
            'commerce_analytics.php',
            'crm.php',
            'crm_contacts.php',
            'crm_contact.php',
            'crm_tickets.php',
            'crm_ticket.php',
            'crm_deals.php',
            'crm_capture.php',
            'crm_convert.php',
            'crm_bookings.php',
            'crm_invoices.php',
            'crm_retain.php',
            'dashboard.php',
        ];
        if (in_array($base, $hardNavPages, true)) {
            return '';
        }

        return ' data-org-nav="1"';
    }
}
