<?php
declare(strict_types=1);

/**
 * Shared home-tab catalog + pin storage for home.php and feed_api.php.
 * Pins sync the website Add Programs bar with the Swift app.
 */

if (!function_exists('home_feed_core_tabs')) {
    function home_feed_core_tabs(): array
    {
        return [
            'for-you' => 'Circle',
            'public' => 'Discover',
        ];
    }
}

if (!function_exists('home_feed_optional_tabs')) {
    function home_feed_optional_tabs(): array
    {
        return [
            'enterprise' => 'Commerce',
            'trending' => 'Trending',
            'news' => 'News',
            'sports' => 'Sports',
            'business' => 'Business',
            'science' => 'Science',
            'music' => 'Music',
            'arts' => 'Arts & Painting',
            'agriculture' => 'Agriculture',
            'auto' => 'Auto',
            'political' => 'Political',
        ];
    }
}

if (!function_exists('home_feed_rail_tabs')) {
    function home_feed_rail_tabs(): array
    {
        $rail = [
            'entertainment' => 'Entertainment',
            'library' => 'Library',
            'cook' => 'Cook',
            'seek-around-the-world' => 'Seek around the World',
            'geology' => 'Geology',
            'animation' => 'Animation',
            'make-a-new-friend' => 'Make a new Friend',
            'agents' => 'Agents',
            'deep-research' => 'Deep research',
        ];
        if (function_exists('publisher_academic_categories')) {
            $rail = $rail + publisher_academic_categories();
        }
        return $rail;
    }
}

if (!function_exists('home_feed_reserved_slugs')) {
    function home_feed_reserved_slugs(): array
    {
        return [
            'for-you' => true,
            'public' => true,
            'discover' => true,
            'feed' => true,
            'circle' => true,
        ];
    }
}

if (!function_exists('home_feed_normalize_slug')) {
    function home_feed_normalize_slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === 'discover') {
            return 'public';
        }
        if ($slug === 'commerce') {
            return 'enterprise';
        }
        if ($slug === 'circle') {
            return 'for-you';
        }
        return $slug;
    }
}

if (!function_exists('home_feed_normalize_pins')) {
    /**
     * @param mixed $raw
     * @return list<string>
     */
    function home_feed_normalize_pins($raw): array
    {
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
                $decoded = json_decode($trim, true);
                $raw = is_array($decoded) ? $decoded : (preg_split('/[,\s]+/', $trim) ?: []);
            } else {
                $raw = preg_split('/[,\s]+/', $trim) ?: [];
            }
        }
        $out = [];
        $seen = [];
        $reserved = home_feed_reserved_slugs();
        foreach ((array) $raw as $item) {
            $slug = home_feed_normalize_slug((string) $item);
            if ($slug === '' || isset($reserved[$slug]) || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }
        return $out;
    }
}

if (!function_exists('home_feed_ensure_pins_column')) {
    function home_feed_ensure_pins_column(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
            if (!$chk || !$chk->fetchColumn()) {
                return;
            }
            $col = $dbh->query("SHOW COLUMNS FROM user_profile_settings LIKE 'feed_program_pins'");
            if ($col && $col->fetch(PDO::FETCH_ASSOC)) {
                return;
            }
            $dbh->exec("ALTER TABLE user_profile_settings ADD COLUMN feed_program_pins TEXT NULL");
        } catch (Throwable $e) {
            // Keep localStorage-only behavior if the column cannot be added.
        }
    }
}

if (!function_exists('home_feed_load_pins_state')) {
    /**
     * @return array{pins: list<string>, saved: bool}
     */
    function home_feed_load_pins_state(PDO $dbh, int $userId): array
    {
        home_feed_ensure_pins_column($dbh);
        if ($userId <= 0) {
            return ['pins' => [], 'saved' => false];
        }
        try {
            $st = $dbh->prepare('SELECT feed_program_pins FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
            $st->execute([':uid' => $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !array_key_exists('feed_program_pins', $row) || $row['feed_program_pins'] === null || $row['feed_program_pins'] === '') {
                return ['pins' => [], 'saved' => false];
            }
            return [
                'pins' => home_feed_normalize_pins($row['feed_program_pins']),
                'saved' => true,
            ];
        } catch (Throwable $e) {
            return ['pins' => [], 'saved' => false];
        }
    }
}

if (!function_exists('home_feed_save_pins')) {
    /**
     * @param list<string>|array<int,string> $pins
     * @return list<string>
     */
    function home_feed_save_pins(PDO $dbh, int $userId, array $pins): array
    {
        $pins = home_feed_normalize_pins($pins);
        if ($userId <= 0) {
            return $pins;
        }
        home_feed_ensure_pins_column($dbh);
        try {
            $ensure = $dbh->prepare('INSERT INTO user_profile_settings (user_id) VALUES (:uid) ON DUPLICATE KEY UPDATE user_id = user_id');
            $ensure->execute([':uid' => $userId]);
            $st = $dbh->prepare('UPDATE user_profile_settings SET feed_program_pins = :val WHERE user_id = :uid');
            $st->execute([
                ':val' => json_encode(array_values($pins), JSON_UNESCAPED_SLASHES),
                ':uid' => $userId,
            ]);
        } catch (Throwable $e) {
            // Ignore write failures; callers still keep the in-memory list.
        }
        return $pins;
    }
}

if (!function_exists('home_feed_program_catalog')) {
    /**
     * @return array<string,string>
     */
    function home_feed_program_catalog(?PDO $dbh = null): array
    {
        $catalog = home_feed_optional_tabs() + home_feed_rail_tabs();
        if ($dbh instanceof PDO && function_exists('publisher_custom_categories')) {
            try {
                $catalog = $catalog + publisher_custom_categories($dbh);
            } catch (Throwable $e) {
            }
        }
        return $catalog;
    }
}

if (!function_exists('home_feed_tabs_payload')) {
    /**
     * @param list<string> $pins
     * @return array{tabs: list<array<string,mixed>>, programs: list<array<string,string>>, pins: list<string>}
     */
    function home_feed_tabs_payload(?PDO $dbh, array $pins, bool $pinsSaved): array
    {
        if (!$pinsSaved && $pins === []) {
            $pins = [];
        }
        $pins = home_feed_normalize_pins($pins);
        $pinSet = array_fill_keys($pins, true);
        $catalog = home_feed_program_catalog($dbh);
        $tabs = [];
        foreach (home_feed_core_tabs() as $key => $label) {
            $tabs[] = [
                'key' => $key,
                'label' => $label,
                'url_tab' => function_exists('home_tab_url_key') ? home_tab_url_key($key) : ($key === 'public' ? 'discover' : $key),
                'optional' => false,
                'visible' => 1,
            ];
        }
        foreach ($catalog as $key => $label) {
            $key = home_feed_normalize_slug((string) $key);
            if ($key === '' || isset(home_feed_reserved_slugs()[$key])) {
                continue;
            }
            $tabs[] = [
                'key' => $key,
                'label' => (string) $label,
                'url_tab' => function_exists('home_tab_url_key') ? home_tab_url_key($key) : $key,
                'optional' => true,
                'visible' => isset($pinSet[$key]) ? 1 : 0,
            ];
        }
        $programs = [];
        foreach ($catalog as $key => $label) {
            $key = home_feed_normalize_slug((string) $key);
            if ($key === '' || isset(home_feed_reserved_slugs()[$key])) {
                continue;
            }
            $programs[] = [
                'slug' => $key,
                'label' => (string) $label,
            ];
        }
        return [
            'tabs' => $tabs,
            'programs' => $programs,
            'pins' => $pins,
        ];
    }
}
