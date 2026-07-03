<?php
declare(strict_types=1);

/**
 * Curated X (Twitter) news publishers — read-only sources, not app user accounts.
 */

function news_publishers_catalog(): array
{
    return [
        'cnn' => ['handle' => 'CNN', 'label' => 'CNN', 'category' => 'news'],
        'foxnews' => ['handle' => 'FoxNews', 'label' => 'Fox News', 'category' => 'news'],
        'abc' => ['handle' => 'ABC', 'label' => 'ABC News', 'category' => 'news'],
        'bbcnews' => ['handle' => 'BBCNews', 'label' => 'BBC News', 'category' => 'news'],
        'ap' => ['handle' => 'AP', 'label' => 'AP News', 'category' => 'news'],
        'reuters' => ['handle' => 'Reuters', 'label' => 'Reuters', 'category' => 'news'],
        'foxsports' => ['handle' => 'FOXSports', 'label' => 'FOX Sports', 'category' => 'sports'],
        'espn' => ['handle' => 'ESPNN', 'label' => 'ESPN', 'category' => 'sports'],
        'bbcsport' => ['handle' => 'BBCSport', 'label' => 'BBC Sport', 'category' => 'sports'],
        'cnnbusiness' => ['handle' => 'CNNBusiness', 'label' => 'CNN Business', 'category' => 'business'],
        'bloomberg' => ['handle' => 'Bloomberg', 'label' => 'Bloomberg', 'category' => 'business'],
        'wsj' => ['handle' => 'WSJ', 'label' => 'Wall Street Journal', 'category' => 'business'],
        'nasa' => ['handle' => 'NASA', 'label' => 'NASA', 'category' => 'science'],
        'natgeo' => ['handle' => 'NatGeo', 'label' => 'National Geographic', 'category' => 'science'],
        'usda' => ['handle' => 'USDA', 'label' => 'USDA', 'category' => 'agriculture'],
        'spotify' => ['handle' => 'Spotify', 'label' => 'Spotify', 'category' => 'music'],
        'billboard' => ['handle' => 'Billboard', 'label' => 'Billboard', 'category' => 'music'],
        'metmuseum' => ['handle' => 'metmuseum', 'label' => 'The Met', 'category' => 'arts'],
        'moma' => ['handle' => 'MuseumModernArt', 'label' => 'MoMA', 'category' => 'arts'],
        'topgear' => ['handle' => 'TopGear', 'label' => 'Top Gear', 'category' => 'auto'],
    ];
}

function news_publisher_key(string $key): ?array
{
    $key = strtolower(trim($key));
    $catalog = news_publishers_catalog();
    return $catalog[$key] ?? null;
}

function news_publisher_avatar_url(string $handle): string
{
    $handle = preg_replace('/[^a-zA-Z0-9_]/', '', $handle) ?? '';
    if ($handle === '') {
        return '';
    }
    return 'https://unavatar.io/twitter/' . rawurlencode($handle);
}

function news_publishers_for_category(string $category): array
{
    $category = strtolower(trim($category));
    $out = [];
    foreach (news_publishers_catalog() as $key => $row) {
        if ($category === 'all' || (string)($row['category'] ?? '') === $category) {
            $out[$key] = $row + ['key' => $key];
        }
    }
    return $out;
}

function news_publishers_as_twitter_accounts(array $publisherKeys): array
{
    $catalog = news_publishers_catalog();
    $accounts = [];
    foreach ($publisherKeys as $key) {
        $key = strtolower(trim((string)$key));
        if ($key === '' || !isset($catalog[$key])) {
            continue;
        }
        $row = $catalog[$key];
        $accounts[] = [
            'handle' => (string)$row['handle'],
            'label' => (string)$row['label'],
            'publisher_key' => $key,
        ];
    }
    return $accounts;
}

function news_publishers_category_map(): array
{
    $map = [];
    foreach (news_publishers_catalog() as $key => $row) {
        $cat = (string)($row['category'] ?? 'news');
        if (!isset($map[$cat])) {
            $map[$cat] = [];
        }
        $map[$cat][] = [
            'handle' => (string)$row['handle'],
            'label' => (string)$row['label'],
            'publisher_key' => $key,
        ];
    }
    return $map;
}
