<?php
declare(strict_types=1);

require_once __DIR__ . '/external_news_feed.php';
require_once __DIR__ . '/news_publishers.php';
require_once __DIR__ . '/news_publisher_follows.php';

/**
 * Live X (Twitter) timelines via official syndication embed endpoint — no DB storage.
 */

function twitter_news_platform_label(): string
{
    return 'X / Twitter';
}

function twitter_accounts_for_category(string $category, int $userId = 0): array
{
    if ($category === 'following') {
        $followed = news_publisher_follows_read($userId);
        if (!$followed) {
            return [];
        }
        return news_publishers_as_twitter_accounts($followed);
    }

    $map = news_publishers_category_map();

    if ($category === 'all') {
        $merged = [];
        $seen = [];
        foreach ($map as $accounts) {
            foreach ($accounts as $row) {
                $handle = strtolower((string)($row['handle'] ?? ''));
                if ($handle === '' || isset($seen[$handle])) {
                    continue;
                }
                $seen[$handle] = true;
                $merged[] = $row;
            }
        }
        return $merged;
    }

    return $map[$category] ?? $map['news'];
}

function twitter_syndication_get(string $handle): array
{
    $handle = preg_replace('/[^a-zA-Z0-9_]/', '', $handle) ?? '';
    if ($handle === '') {
        return [];
    }

    $cacheKey = 'twitter_synd_v1_' . strtolower($handle);
    $cached = external_news_cache_get($cacheKey, 600);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }

    $url = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($handle);
    $body = external_news_http_get($url, 18, 'Mozilla/5.0 (compatible; Talentra/1.0; +https://localhost)');
    if ($body === '' || strpos($body, '__NEXT_DATA__') === false) {
        return [];
    }
    if (!preg_match('/__NEXT_DATA__[^>]*>(.*?)<\\/script>/s', $body, $m)) {
        return [];
    }
    $data = json_decode((string)($m[1] ?? ''), true);
    if (!is_array($data)) {
        return [];
    }
    $entries = $data['props']['pageProps']['timeline']['entries'] ?? [];
    if (!is_array($entries)) {
        $entries = [];
    }

    external_news_cache_set($cacheKey, json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $entries;
}

function twitter_media_from_tweet(array $tweet): array
{
    $image = '';
    $video = '';
    $mediaList = $tweet['extended_entities']['media'] ?? $tweet['entities']['media'] ?? [];
    if (!is_array($mediaList)) {
        return ['image' => '', 'video' => ''];
    }

    foreach ($mediaList as $media) {
        if (!is_array($media)) {
            continue;
        }
        $type = strtolower(trim((string)($media['type'] ?? '')));
        $mediaUrl = trim((string)($media['media_url_https'] ?? ''));
        if ($image === '' && $mediaUrl !== '' && ($type === 'photo' || $type === 'animated_gif')) {
            $image = external_news_upgrade_image_url($mediaUrl);
        }
        if ($type === 'video' || $type === 'animated_gif') {
            if ($image === '' && $mediaUrl !== '') {
                $image = external_news_upgrade_image_url($mediaUrl);
            }
            $variants = $media['video_info']['variants'] ?? [];
            if (is_array($variants)) {
                $bestMp4 = '';
                $bestBitrate = 0;
                foreach ($variants as $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }
                    $contentType = strtolower(trim((string)($variant['content_type'] ?? '')));
                    $variantUrl = trim((string)($variant['url'] ?? ''));
                    if ($contentType === 'video/mp4' && $variantUrl !== '') {
                        $bitrate = (int)($variant['bitrate'] ?? 0);
                        if ($bitrate >= $bestBitrate) {
                            $bestBitrate = $bitrate;
                            $bestMp4 = $variantUrl;
                        }
                    }
                }
                if ($bestMp4 !== '') {
                    $video = $bestMp4;
                }
            }
        }
        if ($image !== '' && $video !== '') {
            break;
        }
    }

    return ['image' => $image, 'video' => $video];
}

function twitter_parse_created_at(string $createdAt): string
{
    $createdAt = trim($createdAt);
    if ($createdAt === '') {
        return gmdate('c');
    }
    $ts = strtotime($createdAt);
    return $ts ? gmdate('c', $ts) : gmdate('c');
}

function twitter_normalize_tweet(array $tweet, string $category, string $sourceLabel, string $publisherKey = ''): array
{
    $idStr = trim((string)($tweet['id_str'] ?? ''));
    $user = is_array($tweet['user'] ?? null) ? $tweet['user'] : [];
    $screenName = trim((string)($user['screen_name'] ?? ''));
    $displayName = trim((string)($user['name'] ?? $sourceLabel));
    $avatar = trim((string)($user['profile_image_url_https'] ?? ''));
    $text = external_news_strip_html((string)($tweet['full_text'] ?? $tweet['text'] ?? ''));
    $permalink = trim((string)($tweet['permalink'] ?? ''));
    if ($permalink === '' && $idStr !== '' && $screenName !== '') {
        $permalink = 'https://x.com/' . rawurlencode($screenName) . '/status/' . rawurlencode($idStr);
    }
    if ($permalink !== '' && stripos($permalink, 'http') !== 0) {
        $permalink = 'https://x.com' . (strpos($permalink, '/') === 0 ? '' : '/') . ltrim($permalink, '/');
    }

    $media = twitter_media_from_tweet($tweet);
    $title = $text;
    if (mb_strlen($title) > 140) {
        $title = mb_substr($title, 0, 137) . '…';
    }

    return [
        'id' => 'x:' . ($idStr !== '' ? $idStr : substr(sha1($text . $permalink), 0, 16)),
        'category' => $category,
        'platform' => 'x',
        'source' => $sourceLabel,
        'title' => $title,
        'description' => $text,
        'url' => $permalink,
        'image' => $media['image'],
        'video' => $media['video'],
        'published_at' => twitter_parse_created_at((string)($tweet['created_at'] ?? '')),
        'author' => $displayName,
        'author_handle' => $screenName,
        'avatar' => $avatar,
        'publisher_key' => $publisherKey,
        'x_likes' => (int)($tweet['favorite_count'] ?? 0),
        'x_retweets' => (int)($tweet['retweet_count'] ?? 0),
        'x_replies' => (int)($tweet['reply_count'] ?? 0),
        'is_external' => true,
        'is_twitter' => true,
    ];
}

function twitter_fetch_account(string $handle, string $category, string $sourceLabel, int $limit = 5, string $publisherKey = ''): array
{
    $entries = twitter_syndication_get($handle);
    $items = [];
    foreach ($entries as $entry) {
        if (count($items) >= $limit) {
            break;
        }
        if (!is_array($entry)) {
            continue;
        }
        if ((string)($entry['type'] ?? '') !== 'tweet') {
            continue;
        }
        $tweet = $entry['content']['tweet'] ?? null;
        if (!is_array($tweet)) {
            continue;
        }
        $text = trim((string)($tweet['full_text'] ?? $tweet['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $items[] = twitter_normalize_tweet($tweet, $category, $sourceLabel, $publisherKey);
    }
    return $items;
}

function twitter_news_collect(string $category = 'all', string $query = '', int $limit = 40, int $userId = 0): array
{
    $category = strtolower(trim($category));
    $isFollowing = ($category === 'following');
    if (!$isFollowing && !external_news_is_valid_category($category)) {
        $category = 'all';
    }
    $query = trim($query);
    $limit = max(1, min($limit, 80));

    $cacheKey = 'twitter_feed_v2_' . md5($category . '|' . $query . '|' . $limit . '|' . $userId);
    $cached = external_news_cache_get($cacheKey, $isFollowing ? 300 : 480);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $accounts = twitter_accounts_for_category($category, $userId);
    if ($isFollowing && !$accounts) {
        return [];
    }
    if ($category === 'all' && count($accounts) > 14) {
        $accounts = array_slice($accounts, 0, 14);
    }

    $perAccount = ($category === 'all' || $isFollowing) ? 3 : 4;
    $items = [];
    foreach ($accounts as $account) {
        $handle = (string)($account['handle'] ?? '');
        $label = (string)($account['label'] ?? $handle);
        $pubKey = (string)($account['publisher_key'] ?? '');
        $cat = ($category === 'all' || $isFollowing) ? (string)(news_publisher_key($pubKey)['category'] ?? 'news') : $category;
        if ($cat === 'following') {
            $cat = 'news';
        }
        foreach (twitter_fetch_account($handle, $cat, $label, $perAccount, $pubKey) as $row) {
            $items[] = $row;
        }
    }

    if ($query !== '') {
        $q = mb_strtolower($query);
        $items = array_values(array_filter($items, static function (array $it) use ($q): bool {
            $hay = mb_strtolower(
                ($it['title'] ?? '') . ' ' . ($it['description'] ?? '') . ' '
                . ($it['source'] ?? '') . ' ' . ($it['author'] ?? '') . ' @' . ($it['author_handle'] ?? '')
            );
            return strpos($hay, $q) !== false;
        }));
    }

    $seen = [];
    $deduped = [];
    foreach ($items as $it) {
        $key = strtolower(trim((string)($it['id'] ?? '')));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $it;
    }

    usort($deduped, static function (array $a, array $b): int {
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
