<?php
declare(strict_types=1);

/**
 * Unified home tabs: home.php?tab=for-you|discover|news|…
 * Internal key "public" is exposed in URLs as "discover".
 */

if (!function_exists('home_tab_internal')) {
    function home_tab_internal(string $tab): string
    {
        $tab = strtolower(trim($tab));
        if ($tab === '' || $tab === 'discover') {
            return $tab === '' ? 'for-you' : 'public';
        }
        return $tab;
    }
}

if (!function_exists('home_tab_url_key')) {
    function home_tab_url_key(string $internalTab): string
    {
        $internalTab = strtolower(trim($internalTab));
        return $internalTab === 'public' ? 'discover' : $internalTab;
    }
}

if (!function_exists('home_tab_url')) {
    /**
     * @param array<string, scalar|null> $extra
     */
    function home_tab_url(string $internalTab, array $extra = []): string
    {
        $query = [];
        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query[(string)$key] = $value;
        }
        $query['tab'] = home_tab_url_key($internalTab);
        return 'home.php?' . http_build_query($query);
    }
}

if (!function_exists('home_entry_script')) {
    /** Which legacy page powers this tab. */
    function home_entry_script(string $internalTab): string
    {
        $internalTab = home_tab_internal($internalTab);
        if ($internalTab === 'for-you') {
            return 'feed';
        }
        if ($internalTab === 'news') {
            return 'news';
        }
        return 'public';
    }
}

if (!function_exists('home_redirect_legacy_entry')) {
    /**
     * Direct hits on feed.php / public.php / news.php → home.php?tab=…
     * Skipped when home.php is already bootstrapping the include.
     *
     * @param array<string, scalar|null>|null $params
     */
    function home_redirect_legacy_entry(string $defaultInternalTab, ?array $params = null): void
    {
        if (defined('MSB_HOME_BOOTSTRAP')) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            return;
        }
        // Keep soft tab prefetch / ajax fragments on the legacy URL they requested.
        if ((string)($_GET['ajax_discover'] ?? '') === '1') {
            return;
        }
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') {
            return;
        }
        $params = $params ?? $_GET;
        $tabRaw = (string)($params['tab'] ?? $defaultInternalTab);
        $tab = home_tab_internal($tabRaw !== '' ? $tabRaw : $defaultInternalTab);
        $params['tab'] = home_tab_url_key($tab);
        $qs = http_build_query($params);
        header('Location: home.php' . ($qs !== '' ? '?' . $qs : ''));
        exit;
    }
}
