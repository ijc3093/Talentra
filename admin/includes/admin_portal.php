<?php
declare(strict_types=1);

/**
 * Admin workspace portals: Public_user / Publisher / Commerce.
 * Left nav shows all groups as dropdowns (no header switch).
 */

if (!function_exists('admin_portal_normalize')) {
    /** @return 'public_user'|'publisher'|'commerce' */
    function admin_portal_normalize(string $portal): string
    {
        $portal = strtolower(trim($portal));
        if ($portal === 'public' || $portal === 'user' || $portal === 'public-user') {
            $portal = 'public_user';
        }
        if ($portal === 'comerce') {
            $portal = 'commerce';
        }
        // Legacy Shop portal merges into Commerce
        if ($portal === 'shop') {
            $portal = 'commerce';
        }
        return in_array($portal, ['public_user', 'publisher', 'commerce'], true) ? $portal : 'public_user';
    }
}

if (!function_exists('admin_portal_page_map')) {
    /**
     * @return array<string,'public_user'|'publisher'|'commerce'>
     */
    function admin_portal_page_map(): array
    {
        return [
            // Public_user
            'overview.php' => 'public_user',
            'reports.php' => 'public_user',
            'report_detail.php' => 'public_user',
            'posts.php' => 'public_user',
            'post_profile.php' => 'public_user',
            'device_activity.php' => 'public_user',
            'login_activity.php' => 'public_user',
            'audience.php' => 'public_user',
            'trends.php' => 'public_user',
            'user_activity_table.php' => 'public_user',
            'user_activity.php' => 'public_user',
            'userlist.php' => 'public_user',
            'user_form.php' => 'public_user',
            'account_search.php' => 'public_user',
            'adminroles.php' => 'public_user',
            'roleslist.php' => 'public_user',

            // Publisher
            'publisher_requests.php' => 'publisher',
            'publisher_request_detail.php' => 'publisher',

            // Commerce (includes former Shop)
            'Orders.php' => 'commerce',
            'inventory.php' => 'commerce',
            'transactions.php' => 'commerce',
            'commerce/transactions.php' => 'commerce',
            'dispute.php' => 'commerce',
            'disputes.php' => 'commerce',
            'dispute_detail.php' => 'commerce',
            'service_fees.php' => 'commerce',
            'customer_memberships.php' => 'commerce',
            'orglist.php' => 'commerce',
            'managerlist.php' => 'commerce',
            'stafflist.php' => 'commerce',
            'org_stripe_connect.php' => 'commerce',
            'org_rent.php' => 'commerce',
            'org_commerce_brands.php' => 'commerce',
        ];
    }
}

if (!function_exists('admin_portal_home')) {
    function admin_portal_home(string $portal): string
    {
        return match (admin_portal_normalize($portal)) {
            'publisher' => 'publisher_requests.php',
            'commerce' => 'dispute.php?filter=unread',
            default => 'overview.php',
        };
    }
}

if (!function_exists('admin_portal_labels')) {
    /**
     * @return list<array{key:string,label:string,icon:string}>
     */
    function admin_portal_labels(): array
    {
        return [
            ['key' => 'public_user', 'label' => 'Public_user', 'icon' => 'ion-ios-people'],
            ['key' => 'publisher', 'label' => 'Publisher', 'icon' => 'ion-ios-paper'],
            ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'ion-ios-cart'],
        ];
    }
}

if (!function_exists('admin_portal_infer_from_page')) {
    function admin_portal_infer_from_page(?string $page = null): string
    {
        if ($page === null || $page === '') {
            $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
        }
        $page = strtolower(basename($page));
        $map = admin_portal_page_map();
        return $map[$page] ?? 'public_user';
    }
}

if (!function_exists('admin_portal_current')) {
    /** @return 'public_user'|'publisher'|'commerce' */
    function admin_portal_current(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $req = strtolower(trim((string)($_GET['portal'] ?? '')));
        if ($req !== '') {
            $portal = admin_portal_normalize($req);
            $_SESSION['admin_portal'] = $portal;
            return $portal;
        }

        $page = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $map = admin_portal_page_map();
        $shellPages = [
            'dashboard.php',
            'feedback.php',
            'mailbox.php',
            'notification.php',
            'settings.php',
            'change-password.php',
            'logout.php',
            'index.php',
        ];
        if ($page !== '' && isset($map[$page]) && !in_array($page, $shellPages, true)) {
            $portal = $map[$page];
            $_SESSION['admin_portal'] = $portal;
            return $portal;
        }

        $saved = admin_portal_normalize((string)($_SESSION['admin_portal'] ?? 'public_user'));
        $_SESSION['admin_portal'] = $saved;
        return $saved;
    }
}

if (!function_exists('admin_portal_allows_page')) {
    /**
     * All portal pages stay visible in the grouped left nav.
     * Kept for backward compatibility with older callers.
     */
    function admin_portal_allows_page(string $hrefOrPage, ?string $portal = null): bool
    {
        return true;
    }
}

if (!function_exists('admin_portal_switch_href')) {
    function admin_portal_switch_href(string $portal): string
    {
        $portal = admin_portal_normalize($portal);
        $home = admin_portal_home($portal);
        $sep = strpos($home, '?') === false ? '?' : '&';
        return $home . $sep . 'portal=' . rawurlencode($portal);
    }
}

if (!function_exists('admin_portal_tabs_css')) {
    function admin_portal_tabs_css(): string
    {
        return '';
    }
}

if (!function_exists('admin_portal_tabs_html')) {
    function admin_portal_tabs_html(?string $active = null): string
    {
        return '';
    }
}
