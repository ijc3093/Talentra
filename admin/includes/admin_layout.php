<?php
declare(strict_types=1);

if (!function_exists('admin_layout_head_assets')) {
    function admin_layout_head_assets(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;
        echo '<style id="admin-layout-critical">'
            . 'html,body{background:#f8f9fa;}'
            . '@media (min-width:1200px){'
            . '.sh-logopanel{left:0!important;}'
            . '.sh-sideleft-menu{left:0!important;}'
            . '.sh-headpanel{left:240px!important;}'
            . '.sh-mainpanel{margin-left:240px!important;}'
            . '}'
            . '</style>' . "\n";
        echo '<link rel="stylesheet" href="css/admin-layout.css?v=2">' . "\n";
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
        echo '<script src="js/admin-nav.js?v=1" defer></script>' . "\n";
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
        if (!$enabled) {
            return '';
        }

        $path = parse_url($href, PHP_URL_PATH) ?: $href;
        $base = basename($path);
        if ($base === 'logout.php' || $base === '') {
            return '';
        }

        return ' data-admin-nav="1"';
    }
}
