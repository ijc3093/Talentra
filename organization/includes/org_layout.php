<?php
declare(strict_types=1);

if (!function_exists('org_layout_head_assets')) {
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

        echo '<link rel="stylesheet" href="css/org-layout.css?v=13">' . "\n";
        echo '<link rel="stylesheet" href="css/org-compact.css?v=14">' . "\n";
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
        require __DIR__ . '/org_footer_scripts.php';
        echo '<script src="js/org-nav.js?v=13" defer></script>' . "\n";
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
            'products.php',
            'orders.php',
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
