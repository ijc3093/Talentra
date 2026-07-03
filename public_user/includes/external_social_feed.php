<?php
declare(strict_types=1);

require_once __DIR__ . '/external_news_feed.php';

function social_news_platforms(): array
{
    return [
        'rss' => 'Headlines',
        'reddit' => 'Reddit',
        'bluesky' => 'Bluesky',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
    ];
}

function social_news_http_json(string $url, int $timeout = 12): array
{
    $body = external_news_http_get($url, $timeout, 'TalentraSocial/1.0');
    if ($body === '' || (isset($body[0]) && $body[0] === '<')) {
        return [];
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

function social_news_item_id(string $platform, string $seed): string
{
    return 'n2:' . strtolower($platform) . ':' . substr(sha1(strtolower(trim($seed))), 0, 16);
}

function social_news_wrap_item(array $item, string $platform): array
{
    $url = trim((string)($item['url'] ?? ''));
    $title = trim((string)($item['title'] ?? ''));
    $seed = $url !== '' ? $url : ($title . '|' . (string)($item['published_at'] ?? ''));
    $id = social_news_item_id($platform, $seed);

    return array_merge($item, [
        'id' => $id,
        'platform' => strtolower($platform),
        'is_external' => true,
        'is_social' => true,
    ]);
}

function social_bluesky_post_url(array $post): string
{
    $uri = trim((string)($post['uri'] ?? ''));
    $handle = trim((string)($post['author']['handle'] ?? ''));
    if ($uri === '' || $handle === '') {
        return '';
    }
    if (preg_match('~/app\.bsky\.feed\.post/([^/]+)$~', $uri, $m)) {
        return 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode((string)$m[1]);
    }
    return '';
}

function social_bluesky_embed_media(array $post): array
{
    $image = '';
    $video = '';
    $embed = $post['embed'] ?? null;
    if (!is_array($embed)) {
        return ['image' => '', 'video' => ''];
    }
    $type = (string)($embed['$type'] ?? '');
    if (strpos($type, 'images') !== false && !empty($embed['images'][0])) {
        $img = $embed['images'][0];
        $image = trim((string)($img['fullsize'] ?? $img['thumb'] ?? ''));
    } elseif (strpos($type, 'external') !== false) {
        $image = trim((string)($embed['external']['thumb'] ?? ''));
    } elseif (strpos($type, 'video') !== false) {
        $video = trim((string)($embed['playlist'] ?? $embed['media']['playlist'] ?? ''));
        $image = trim((string)($embed['thumbnail'] ?? ''));
    }
    if ($image !== '') {
        $image = external_news_upgrade_image_url($image);
    }
    return ['image' => $image, 'video' => $video];
}

function social_fetch_bluesky_author(string $handle, string $category, string $sourceLabel, int $limit = 5): array
{
    $handle = preg_replace('/[^a-zA-Z0-9._-]/', '', $handle) ?? '';
    if ($handle === '') {
        return [];
    }
    $cacheKey = 'bsky_v1_' . md5($handle . '|' . $category . '|' . $limit);
    $cached = external_news_cache_get($cacheKey, 600);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }

    $url = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor='
        . rawurlencode($handle) . '&limit=' . max(1, min($limit, 25));
    $data = social_news_http_json($url);
    $feed = $data['feed'] ?? [];
    $items = [];

    foreach ($feed as $row) {
        if (count($items) >= $limit) {
            break;
        }
        $post = $row['post'] ?? null;
        if (!is_array($post)) {
            continue;
        }
        $record = $post['record'] ?? [];
        $text = external_news_strip_html((string)($record['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $title = $text;
        if (mb_strlen($title) > 120) {
            $title = mb_substr($title, 0, 117) . '…';
        }
        $media = social_bluesky_embed_media($post);
        $published = trim((string)($post['indexedAt'] ?? $record['createdAt'] ?? ''));
        $ts = $published !== '' ? strtotime($published) : false;
        $link = social_bluesky_post_url($post);
        $authorHandle = trim((string)($post['author']['handle'] ?? $handle));

        $items[] = social_news_wrap_item(external_news_normalize_item([
            'category' => $category,
            'source' => $sourceLabel . ' (@' . $authorHandle . ')',
            'title' => $title,
            'description' => mb_substr($text, 0, 900),
            'url' => $link,
            'image' => $media['image'],
            'video' => $media['video'],
            'published_at' => $ts ? gmdate('c', $ts) : gmdate('c'),
            'author' => $authorHandle,
        ]), 'bluesky');
    }

    external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $items;
}

function social_bluesky_sources_for_category(string $category): array
{
    $map = [
        'news' => [
            ['handle' => 'cnn.com', 'label' => 'CNN Bluesky'],
            ['handle' => 'apnews.com', 'label' => 'AP News Bluesky'],
            ['handle' => 'reuters.com', 'label' => 'Reuters Bluesky'],
            ['handle' => 'npr.org', 'label' => 'NPR Bluesky'],
        ],
        'business' => [
            ['handle' => 'wsj.com', 'label' => 'WSJ Bluesky'],
            ['handle' => 'reuters.com', 'label' => 'Reuters Bluesky'],
        ],
        'sports' => [
            ['handle' => 'espn.com', 'label' => 'ESPN Bluesky'],
        ],
        'music' => [
            ['handle' => 'pitchfork.com', 'label' => 'Pitchfork Bluesky'],
        ],
        'science' => [
            ['handle' => 'apnews.com', 'label' => 'AP Science Bluesky'],
        ],
        'political' => [
            ['handle' => 'cnn.com', 'label' => 'CNN Politics Bluesky'],
            ['handle' => 'apnews.com', 'label' => 'AP Politics Bluesky'],
        ],
    ];
    return $map[$category] ?? [];
}

function social_video_subreddits_for_category(string $category): array
{
    $map = [
        'news' => ['videos', 'news'],
        'business' => ['business', 'stocks'],
        'sports' => ['sports', 'nba'],
        'agriculture' => ['farming', 'Agriculture'],
        'music' => ['listentothis', 'MusicVideos'],
        'science' => ['space', 'science'],
        'arts' => ['Art', 'Damnthatsinteresting'],
        'auto' => ['carporn', 'cars'],
        'political' => ['politics', 'PoliticalVideo'],
    ];
    return $map[$category] ?? ['videos'];
}

function social_youtube_sources_for_category(string $category): array
{
    $map = [
        'news' => [
            ['channel' => 'UCupvqj-4sRVuxt_ngVSFhgg', 'source' => 'CNN YouTube'],
            ['channel' => 'UC16niRr50-MSwWj-wsJgWg', 'source' => 'BBC News YouTube'],
            ['channel' => 'UC8AM-Y0wg7ApyK5Q2belQhw', 'source' => 'Fox News YouTube'],
        ],
        'business' => [
            ['channel' => 'UCqK_GSMZDq_FKP105M-6Qzg', 'source' => 'Bloomberg YouTube'],
        ],
        'sports' => [
            ['channel' => 'UCiWLfSweyRNmL12EHKTt8wg', 'source' => 'ESPN YouTube'],
        ],
        'music' => [
            ['channel' => 'UC-9-kyTW8ZkZNDOjb7LAYYg', 'source' => 'YouTube Music'],
        ],
        'science' => [
            ['channel' => 'UCLA_DiR1FfKNvjuUpBHmylQ', 'source' => 'NASA YouTube'],
            ['channel' => 'UC6nSFpj9HTCZI5lLsXP7-NQ', 'source' => 'National Geographic YouTube'],
        ],
        'auto' => [
            ['channel' => 'UCsqUgWDsg20eDC2K7M7C2NA', 'source' => 'Doug DeMuro YouTube'],
        ],
        'political' => [
            ['channel' => 'UCupvqj-4sRVuxt_ngVSFhgg', 'source' => 'CNN Politics YouTube'],
        ],
    ];
    return $map[$category] ?? [];
}

function social_tiktok_reddit_sources(): array
{
    return [
        ['sub' => 'tiktok', 'label' => 'TikTok Reddit'],
        ['sub' => 'TikTokCringe', 'label' => 'TikTok Clips'],
        ['sub' => 'tiktokgossip', 'label' => 'TikTok Gossip'],
    ];
}

function social_fetch_tiktok_proxy(string $subreddit, string $category, int $limit = 5): array
{
    $items = external_news_fetch_reddit($subreddit, $category, $limit);
    return array_map(static function (array $item): array {
        $wrapped = social_news_wrap_item($item, 'tiktok');
        $wrapped['source'] = 'TikTok · ' . (string)($item['source'] ?? 'Reddit');
        return $wrapped;
    }, $items);
}

function social_news_sources_for_category_single(string $category): array
{
    $sources = [];

    foreach (social_youtube_sources_for_category($category) as $src) {
        $sources[] = [
            'social_type' => 'youtube',
            'channel' => $src['channel'],
            'source' => $src['source'],
            'category' => $category,
            'priority' => 1,
        ];
    }

    foreach (social_video_subreddits_for_category($category) as $sub) {
        $sources[] = [
            'social_type' => 'reddit',
            'sub' => $sub,
            'category' => $category,
            'priority' => 1,
        ];
    }

    foreach (external_news_sources_for_category($category) as $src) {
        $sources[] = array_merge($src, ['social_type' => (string)($src['type'] ?? 'rss')]);
    }

    foreach (social_bluesky_sources_for_category($category) as $src) {
        $sources[] = [
            'social_type' => 'bluesky',
            'handle' => $src['handle'],
            'source' => $src['label'],
            'category' => $category,
        ];
    }

    $redditMap = [
        'news' => ['worldnews', 'news'],
        'business' => ['business', 'stocks'],
        'sports' => ['sports'],
        'agriculture' => ['farming', 'Agriculture'],
        'music' => ['Music', 'listentothis'],
        'science' => ['science', 'space'],
        'arts' => ['Art', 'printmaking', 'graphic_design'],
        'auto' => ['cars', 'carporn'],
        'political' => ['politics'],
    ];
    foreach ($redditMap[$category] ?? [] as $sub) {
        $sources[] = [
            'social_type' => 'reddit',
            'sub' => $sub,
            'category' => $category,
        ];
    }

    if (in_array($category, ['news', 'music'], true)) {
        foreach (social_tiktok_reddit_sources() as $src) {
            $sources[] = [
                'social_type' => 'tiktok',
                'sub' => $src['sub'],
                'source' => $src['label'],
                'category' => $category,
            ];
        }
    }

    return $sources;
}

function social_news_sources_for_category(string $category): array
{
    $category = strtolower(trim($category));
    if ($category === 'all') {
        $merged = [];
        foreach (array_keys(external_news_categories()) as $catKey) {
            if ($catKey === 'all') {
                continue;
            }
            foreach (social_news_sources_for_category_single($catKey) as $src) {
                $merged[] = array_merge($src, ['category' => $catKey]);
            }
        }
        return $merged;
    }

    if (!external_news_is_valid_category($category)) {
        $category = 'news';
    }

    return array_map(
        static fn(array $src): array => array_merge($src, ['category' => $category]),
        social_news_sources_for_category_single($category)
    );
}

function social_news_collect(string $category = 'all', string $query = '', int $limit = 40): array
{
    $category = strtolower(trim($category));
    if (!external_news_is_valid_category($category)) {
        $category = 'all';
    }
    $query = trim($query);
    $limit = max(1, min($limit, 80));

    $cacheKey = 'social_feed_v2_' . md5($category . '|' . $query . '|' . $limit);
    $cached = external_news_cache_get($cacheKey, 600);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $sources = social_news_sources_for_category($category);
    usort($sources, static function (array $a, array $b): int {
        $ap = (int)($a['priority'] ?? 0);
        $bp = (int)($b['priority'] ?? 0);
        if ($ap !== $bp) {
            return $bp <=> $ap;
        }
        return 0;
    });
    if ($category === 'all' && count($sources) > 28) {
        $sources = array_slice($sources, 0, 28);
    }
    $items = [];

    foreach ($sources as $src) {
        $cat = (string)($src['category'] ?? $category);
        if ($cat === 'all') {
            $cat = 'news';
        }
        $chunk = [];
        $socialType = (string)($src['social_type'] ?? $src['type'] ?? '');

        if ($socialType === 'rss') {
            $rssUrl = trim((string)($src['url'] ?? ''));
            if ($rssUrl === '') {
                continue;
            }
            $perLimit = !empty($src['priority']) ? 4 : 3;
            $chunk = external_news_fetch_rss($rssUrl, $cat, (string)($src['source'] ?? 'News'), $perLimit);
            $chunk = array_map(static fn(array $it): array => social_news_wrap_item($it, 'rss'), $chunk);
        } elseif ($socialType === 'reddit' || ($src['type'] ?? '') === 'reddit') {
            $perLimit = !empty($src['priority']) ? 5 : 3;
            $chunk = external_news_fetch_reddit((string)$src['sub'], $cat, $perLimit);
            $chunk = array_map(static fn(array $it): array => social_news_wrap_item($it, 'reddit'), $chunk);
        } elseif ($socialType === 'bluesky') {
            $chunk = social_fetch_bluesky_author((string)$src['handle'], $cat, (string)$src['source'], 3);
        } elseif ($socialType === 'tiktok') {
            $chunk = social_fetch_tiktok_proxy((string)$src['sub'], $cat, 4);
        } elseif ($socialType === 'nasa' || ($src['type'] ?? '') === 'nasa') {
            $chunk = array_map(static fn(array $it): array => social_news_wrap_item($it, 'rss'), external_news_fetch_nasa_apod());
        } elseif ($socialType === 'youtube' || ($src['type'] ?? '') === 'youtube') {
            $chunk = array_map(
                static fn(array $it): array => social_news_wrap_item($it, 'youtube'),
                external_news_fetch_youtube((string)$src['channel'], $cat, (string)$src['source'], 4)
            );
        }

        foreach ($chunk as $row) {
            $items[] = $row;
        }
    }

    if ($query !== '') {
        $q = mb_strtolower($query);
        $items = array_values(array_filter($items, static function (array $it) use ($q): bool {
            $hay = mb_strtolower(
                ($it['title'] ?? '') . ' ' . ($it['description'] ?? '') . ' ' . ($it['source'] ?? '') . ' ' . ($it['platform'] ?? '')
            );
            return strpos($hay, $q) !== false;
        }));
    }

    $seen = [];
    $deduped = [];
    foreach ($items as $it) {
        $key = strtolower(trim((string)($it['url'] ?? ''))) . '|' . strtolower(trim((string)($it['title'] ?? '')));
        if ($key === '|' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $it;
    }

    usort($deduped, static function (array $a, array $b): int {
        $aVideo = external_news_has_video($a) ? 1 : 0;
        $bVideo = external_news_has_video($b) ? 1 : 0;
        if ($aVideo !== $bVideo) {
            return $bVideo <=> $aVideo;
        }
        $aMedia = external_news_has_media($a) ? 1 : 0;
        $bMedia = external_news_has_media($b) ? 1 : 0;
        if ($aMedia !== $bMedia) {
            return $bMedia <=> $aMedia;
        }
        return strcmp((string)($b['published_at'] ?? ''), (string)($a['published_at'] ?? ''));
    });

    $result = array_slice($deduped, 0, $limit);
    external_news_cache_set($cacheKey, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $result;
}
