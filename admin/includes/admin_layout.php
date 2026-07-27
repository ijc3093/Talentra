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
        // Narrow icon-rail sidebar (64px)
        echo '<style id="admin-layout-critical">'
            . 'html,body{background:#f8f9fa;}'
            . '@media (min-width:1200px){'
            . '.sh-logopanel{left:0!important;width:64px!important;}'
            . '.sh-sideleft-menu{left:0!important;width:64px!important;}'
            . '.sh-headpanel{left:64px!important;}'
            . '.sh-mainpanel{margin-left:64px!important;}'
            . '}'
            . '</style>' . "\n";
        echo '<link rel="stylesheet" href="css/admin-layout.css?v=9">' . "\n";
        echo '<link rel="stylesheet" href="css/admin-tables-shamcey.css?v=8">' . "\n";
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
     * Pending/attention counts for sidebar badges.
     *
     * @return array{publisher_requests:int,shop_rent:int,commerce_brands:int,inbox:int}
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
            'inbox' => 0,
        ];

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
            $receivers = ['Admin'];
            $friendCode = trim((string)($_SESSION['admin_friend_code'] ?? ''));
            if ($friendCode !== '' && strcasecmp($friendCode, 'Admin') !== 0) {
                $receivers[] = $friendCode;
            }
            $placeholders = [];
            $params = [];
            foreach ($receivers as $i => $receiver) {
                $key = ':r' . $i;
                $placeholders[] = $key;
                $params[$key] = $receiver;
            }
            $st = $dbh->prepare('
                SELECT COUNT(*)
                FROM feedback_admin
                WHERE is_read = 0
                  AND receiver IN (' . implode(',', $placeholders) . ')
            ');
            $st->execute($params);
            $counts['inbox'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $counts['inbox'] = 0;
        }

        $cached = $counts;
        return $counts;
    }
}
