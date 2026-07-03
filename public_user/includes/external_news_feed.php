<?php
declare(strict_types=1);

/**
 * Live external news/topics feed (RSS + free public JSON). No database tables.
 */

function external_news_categories(): array
{
    return [
        'all' => ['label' => 'All topics', 'icon' => 'fa-globe'],
        'news' => ['label' => 'News', 'icon' => 'fa-newspaper-o'],
        'business' => ['label' => 'Business', 'icon' => 'fa-briefcase'],
        'sports' => ['label' => 'Sports', 'icon' => 'fa-futbol-o'],
        'agriculture' => ['label' => 'Agriculture', 'icon' => 'fa-leaf'],
        'music' => ['label' => 'Music', 'icon' => 'fa-music'],
        'science' => ['label' => 'Science', 'icon' => 'fa-flask'],
        'arts' => ['label' => 'Arts & Print', 'icon' => 'fa-paint-brush'],
        'auto' => ['label' => 'Auto', 'icon' => 'fa-car'],
        'political' => ['label' => 'Political', 'icon' => 'fa-flag'],
    ];
}

function external_news_category_keys(): array
{
    return array_keys(external_news_categories());
}

function external_news_is_valid_category(string $category): bool
{
    return array_key_exists($category, external_news_categories());
}

function external_news_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/external_news';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function external_news_http_get(string $url, int $timeout = 12, string $userAgent = ''): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if ($userAgent === '') {
        $userAgent = 'TalentraNews/1.0 (+https://localhost)';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, application/json, text/xml, */*'],
        ]);
        $body = (string)curl_exec($ch);
        unset($ch);
        return $body;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => 'User-Agent: ' . $userAgent . "\r\nAccept: application/rss+xml, application/xml, application/json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : '';
}

function external_news_cache_get(string $key, int $ttlSeconds = 600): ?string
{
    $path = external_news_cache_dir() . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', $key) . '.json';
    if (!is_file($path)) {
        return null;
    }
    if (filemtime($path) < (time() - $ttlSeconds)) {
        return null;
    }
    $raw = @file_get_contents($path);
    return is_string($raw) && $raw !== '' ? $raw : null;
}

function external_news_cache_set(string $key, string $payload): void
{
    $path = external_news_cache_dir() . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', $key) . '.json';
    @file_put_contents($path, $payload, LOCK_EX);
}

function external_news_strip_html(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function external_news_is_image_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return false;
    }
    return (bool)preg_match('~\.(jpg|jpeg|png|gif|webp|bmp|avif)(\?|$)~i', $url)
        || stripos($url, 'ichef.bbci.co.uk') !== false
        || stripos($url, 'i.redd.it') !== false
        || stripos($url, 'preview.redd.it') !== false
        || stripos($url, 'external-preview.redd.it') !== false
        || stripos($url, 'ytimg.com') !== false
        || stripos($url, 'i.guim.co.uk') !== false
        || stripos($url, 'nasa.gov') !== false;
}

function external_news_youtube_id(string $url): string
{
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
        return (string)($m[1] ?? '');
    }
    return '';
}

function external_news_is_video_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return false;
    }
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)~i', $url)) {
        return true;
    }
    if (preg_match('~(?:tiktok\.com/|vm\.tiktok\.com/)~i', $url)) {
        return true;
    }
    if (preg_match('~(?:vimeo\.com/\d+|player\.vimeo\.com/video/)~i', $url)) {
        return true;
    }
    return (bool)preg_match('~\.(mp4|webm|mov|m4v|m3u8)(\?|$)~i', $url)
        || stripos($url, 'v.redd.it') !== false
        || stripos($url, 'reddit_video') !== false
        || stripos($url, 'video.cdn.bsky.app') !== false;
}

function external_news_infer_video(string $video, string $url, string $description = ''): string
{
    $video = trim($video);
    if ($video !== '' && external_news_is_video_url($video)) {
        return $video;
    }
    if (external_news_is_video_url($url)) {
        return trim($url);
    }
    $hay = $description . ' ' . $url;
    if (preg_match('~https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)[^\s"\'<>]+~i', $hay, $m)) {
        return trim((string)($m[0] ?? ''));
    }
    if (preg_match('~https?://(?:www\.)?(?:tiktok\.com/|vm\.tiktok\.com/)[^\s"\'<>]+~i', $hay, $m)) {
        return trim((string)($m[0] ?? ''));
    }
    if (preg_match('~https?://(?:www\.)?(?:vimeo\.com/\d+|player\.vimeo\.com/video/\d+)[^\s"\'<>]*~i', $hay, $m)) {
        return trim((string)($m[0] ?? ''));
    }
    if (preg_match('~https?://[^\s"\'<>]+\.(?:mp4|webm|m3u8)(?:\?[^\s"\'<>]*)?~i', $hay, $m)) {
        return trim((string)($m[0] ?? ''));
    }
    return $video;
}

function external_news_youtube_thumb(string $videoUrl): string
{
    $id = external_news_youtube_id($videoUrl);
    return $id !== '' ? ('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg') : '';
}

function external_news_image_from_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (preg_match('/<img[^>]+src=(["\'])([^"\']+)\1/i', $html, $m)) {
        $url = html_entity_decode(trim((string)($m[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (external_news_is_image_url($url)) {
            return $url;
        }
    }
    if (preg_match('/src=(["\'])([^"\']+)\1/i', $html, $m)) {
        $url = html_entity_decode(trim((string)($m[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (external_news_is_image_url($url)) {
            return $url;
        }
    }
    return '';
}

function external_news_image_width_hint(string $url): int
{
    $url = trim($url);
    if ($url === '') {
        return 0;
    }
    if (preg_match('/[?&]width=(\d+)/i', $url, $m)) {
        return (int)$m[1];
    }
    if (preg_match('~ichef\.bbci\.co\.uk/ace/standard/(\d+)/~i', $url, $m)) {
        return (int)$m[1];
    }
    if (stripos($url, 'i.redd.it') !== false || stripos($url, '/master/') !== false) {
        return 1600;
    }
    if (stripos($url, 'ytimg.com') !== false) {
        return stripos($url, 'maxresdefault') !== false ? 1280 : 480;
    }
    return 0;
}

function external_news_pick_best_image(array $candidates): string
{
    $bestUrl = '';
    $bestWidth = 0;
    foreach ($candidates as $candidate) {
        $url = trim((string)($candidate['url'] ?? ''));
        if ($url === '' || !external_news_is_image_url($url)) {
            continue;
        }
        $width = (int)($candidate['width'] ?? 0);
        if ($width <= 0) {
            $width = external_news_image_width_hint($url);
        }
        if ($width >= $bestWidth) {
            $bestWidth = $width;
            $bestUrl = $url;
        }
    }
    return $bestUrl !== '' ? external_news_upgrade_image_url($bestUrl) : '';
}

function external_news_upgrade_image_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('~ichef\.bbci\.co\.uk/ace/standard/\d+/~i', $url)) {
        return preg_replace('~/ace/standard/\d+/~i', '/ace/standard/976/', $url) ?? $url;
    }
    if (preg_match('~preview\.redd\.it/([^?]+)\?~i', $url, $m)) {
        return 'https://i.redd.it/' . rawurldecode((string)($m[1] ?? ''));
    }
    if (preg_match('~i\.ytimg\.com/vi/([A-Za-z0-9_-]+)/hqdefault\.jpg~i', $url, $m)) {
        return 'https://i.ytimg.com/vi/' . $m[1] . '/maxresdefault.jpg';
    }
    return $url;
}

function external_news_extract_rss_media(SimpleXMLElement $node): array
{
    $imageCandidates = [];
    $video = '';

    $pushImage = static function (string $url, int $width = 0) use (&$imageCandidates): void {
        $url = trim($url);
        if ($url === '') {
            return;
        }
        $imageCandidates[] = ['url' => $url, 'width' => $width];
    };

    $media = $node->children('media', true);
    if (isset($media->thumbnail)) {
        foreach ($media->thumbnail as $thumb) {
            $attrs = $thumb->attributes();
            $pushImage((string)($attrs['url'] ?? ''), (int)($attrs['width'] ?? 0));
        }
    }
    if (isset($media->group)) {
        foreach ($media->group as $group) {
            $gMedia = $group->children('media', true);
            if (isset($gMedia->thumbnail)) {
                foreach ($gMedia->thumbnail as $thumb) {
                    $attrs = $thumb->attributes();
                    $pushImage((string)($attrs['url'] ?? ''), (int)($attrs['width'] ?? 0));
                }
            }
            if (isset($gMedia->content)) {
                foreach ($gMedia->content as $content) {
                    $attrs = $content->attributes();
                    $candidate = trim((string)($attrs['url'] ?? ''));
                    $type = strtolower(trim((string)($attrs['type'] ?? '')));
                    $medium = strtolower(trim((string)($attrs['medium'] ?? '')));
                    if ($candidate === '') {
                        continue;
                    }
                    if ($medium === 'image' || strpos($type, 'image/') === 0 || external_news_is_image_url($candidate)) {
                        $pushImage($candidate, (int)($attrs['width'] ?? 0));
                    }
                    if ($video === '' && ($medium === 'video' || strpos($type, 'video/') === 0 || external_news_is_video_url($candidate))) {
                        $video = $candidate;
                    }
                }
            }
        }
    }
    if (isset($media->content)) {
        foreach ($media->content as $content) {
            $attrs = $content->attributes();
            $candidate = trim((string)($attrs['url'] ?? ''));
            $type = strtolower(trim((string)($attrs['type'] ?? '')));
            $medium = strtolower(trim((string)($attrs['medium'] ?? '')));
            if ($candidate === '') {
                continue;
            }
            if ($medium === 'image' || strpos($type, 'image/') === 0 || external_news_is_image_url($candidate)) {
                $pushImage($candidate, (int)($attrs['width'] ?? 0));
            }
            if ($video === '' && ($medium === 'video' || strpos($type, 'video/') === 0 || external_news_is_video_url($candidate))) {
                $video = $candidate;
            }
        }
    }

    if (isset($node->enclosure)) {
        foreach ($node->enclosure as $enc) {
            $attrs = $enc->attributes();
            $encUrl = trim((string)($attrs['url'] ?? ''));
            $type = strtolower(trim((string)($attrs['type'] ?? '')));
            if ($encUrl === '') {
                continue;
            }
            if (strpos($type, 'image/') === 0 || external_news_is_image_url($encUrl)) {
                $pushImage($encUrl, 0);
            }
            if ($video === '' && (strpos($type, 'video/') === 0 || external_news_is_video_url($encUrl))) {
                $video = $encUrl;
            }
        }
    }

    $itunes = $node->children('itunes', true);
    if (isset($itunes->image)) {
        $attrs = $itunes->image->attributes();
        $pushImage((string)($attrs['href'] ?? ''), 0);
    }

    $htmlBlocks = [
        (string)($node->description ?? ''),
        (string)($node->summary ?? ''),
        (string)($node->content ?? ''),
    ];
    $contentNs = $node->children('content', true);
    if (isset($contentNs->encoded)) {
        $htmlBlocks[] = (string)$contentNs->encoded;
    }
    foreach ($htmlBlocks as $html) {
        $htmlImage = external_news_image_from_html($html);
        if ($htmlImage !== '') {
            $pushImage($htmlImage, external_news_image_width_hint($htmlImage));
        }
        if ($video === '' && preg_match('~https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)[^\s"\'<>]+~i', $html, $m)) {
            $video = trim((string)($m[0] ?? ''));
        }
        if ($video === '' && preg_match('~https?://(?:www\.)?(?:tiktok\.com/|vm\.tiktok\.com/)[^\s"\'<>]+~i', $html, $m)) {
            $video = trim((string)($m[0] ?? ''));
        }
    }

    return ['image' => external_news_pick_best_image($imageCandidates), 'video' => $video];
}

function external_news_extract_reddit_media(array $post): array
{
    $image = '';
    $video = '';

    $preview = $post['preview']['images'][0] ?? null;
    if (is_array($preview)) {
        $source = $preview['source']['url'] ?? '';
        if (is_string($source) && $source !== '') {
            $image = external_news_upgrade_image_url(str_replace('&amp;', '&', html_entity_decode($source)));
        }
        if ($image === '' && !empty($preview['resolutions']) && is_array($preview['resolutions'])) {
            $best = end($preview['resolutions']);
            if (is_array($best) && !empty($best['url'])) {
                $image = external_news_upgrade_image_url(str_replace('&amp;', '&', html_entity_decode((string)$best['url'])));
            }
        }
    }

    $url = trim((string)($post['url'] ?? ''));
    if ($image === '' && external_news_is_image_url($url)) {
        $image = external_news_upgrade_image_url($url);
    }

    if (!empty($post['is_video']) && is_array($post['media']['reddit_video'] ?? null)) {
        $rv = $post['media']['reddit_video'];
        $fallback = trim((string)($rv['fallback_url'] ?? ''));
        if ($fallback !== '') {
            $video = html_entity_decode($fallback);
        }
        if ($image === '' && !empty($rv['fallback_url'])) {
            $image = preg_replace('~DASH_\d+\.mp4.*~', 'thumbnail.jpg', $video) ?? $image;
        }
    }

    if ($video === '' && external_news_is_video_url($url)) {
        $video = $url;
    }

    if ($image === '' && !empty($post['thumbnail']) && preg_match('~^https?://~i', (string)$post['thumbnail'])) {
        $thumb = (string)$post['thumbnail'];
        if (external_news_is_image_url($thumb) && stripos($thumb, 'default') === false) {
            $image = external_news_upgrade_image_url($thumb);
        }
    }

    if ($image === '' && !empty($post['media_metadata']) && is_array($post['media_metadata'])) {
        foreach ($post['media_metadata'] as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $src = $meta['s']['u'] ?? $meta['p'][0]['u'] ?? '';
            if (is_string($src) && $src !== '') {
                $image = external_news_upgrade_image_url(str_replace('&amp;', '&', html_entity_decode($src)));
                break;
            }
        }
    }

    if ($image === '' && !empty($post['crosspost_parent_list'][0]) && is_array($post['crosspost_parent_list'][0])) {
        $cross = external_news_extract_reddit_media($post['crosspost_parent_list'][0]);
        $image = (string)($cross['image'] ?? '');
        $video = (string)($cross['video'] ?? '');
    }

    return ['image' => $image, 'video' => $video];
}

function external_news_item_id(string $category, string $url, string $title): string
{
    return 'ext:' . $category . ':' . substr(sha1(strtolower(trim($url . '|' . $title))), 0, 16);
}

function external_news_normalize_item(array $item): array
{
    $title = external_news_strip_html((string)($item['title'] ?? ''));
    $description = external_news_strip_html((string)($item['description'] ?? ''));
    $url = trim((string)($item['url'] ?? ''));
    $category = external_news_is_valid_category((string)($item['category'] ?? '')) ? (string)$item['category'] : 'news';
    $published = trim((string)($item['published_at'] ?? ''));
    if ($published === '') {
        $published = gmdate('c');
    }
    $image = trim((string)($item['image'] ?? ''));
    if ($image !== '' && !preg_match('~^https?://~i', $image)) {
        $image = '';
    }
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = '';
    }

    $video = external_news_infer_video(trim((string)($item['video'] ?? '')), $url, $description);
    if ($image === '' && $video !== '') {
        $ytThumb = external_news_youtube_thumb($video);
        if ($ytThumb !== '') {
            $image = $ytThumb;
        }
    }

    return [
        'id' => external_news_item_id($category, $url !== '' ? $url : $title, $title),
        'category' => $category,
        'source' => trim((string)($item['source'] ?? 'External')),
        'title' => $title,
        'description' => $description,
        'url' => $url,
        'image' => $image,
        'video' => $video,
        'published_at' => $published,
        'author' => trim((string)($item['author'] ?? '')),
        'is_external' => true,
    ];
}

function external_news_parse_rss(string $xml, string $category, string $sourceLabel, int $limit = 12): array
{
    $xml = trim($xml);
    if ($xml === '') {
        return [];
    }
    libxml_use_internal_errors(true);
    $feed = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($feed === false) {
        return [];
    }

    $items = [];
    $nodes = $feed->channel->item ?? $feed->entry ?? [];
    foreach ($nodes as $node) {
        if (count($items) >= $limit) {
            break;
        }
        $title = external_news_strip_html((string)($node->title ?? ''));
        if ($title === '') {
            continue;
        }
        $link = '';
        if (isset($node->link)) {
            $attrs = $node->link->attributes();
            $href = isset($attrs['href']) ? (string)$attrs['href'] : '';
            $link = $href !== '' ? $href : (string)$node->link;
        }
        $description = external_news_strip_html((string)($node->description ?? $node->summary ?? $node->content ?? ''));
        $pubDate = (string)($node->pubDate ?? $node->published ?? $node->updated ?? '');
        $ts = $pubDate !== '' ? strtotime($pubDate) : false;
        $media = external_news_extract_rss_media($node);
        $image = (string)($media['image'] ?? '');
        $video = (string)($media['video'] ?? '');
        if ($video === '' && external_news_is_video_url($link)) {
            $video = $link;
        }

        $items[] = external_news_normalize_item([
            'category' => $category,
            'source' => $sourceLabel,
            'title' => $title,
            'description' => $description,
            'url' => $link,
            'image' => $image,
            'video' => $video,
            'published_at' => $ts ? gmdate('c', $ts) : gmdate('c'),
        ]);
    }
    return $items;
}

function external_news_fetch_rss(string $url, string $category, string $sourceLabel, int $limit = 12): array
{
    $cacheKey = 'rss_v3_' . md5($url . '|' . $category . '|' . $limit);
    $cached = external_news_cache_get($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }
    $body = external_news_http_get($url);
    $items = external_news_parse_rss($body, $category, $sourceLabel, $limit);
    external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $items;
}

function external_news_fetch_reddit_rss(string $subreddit, string $category, int $limit = 10): array
{
    $subreddit = preg_replace('/[^a-zA-Z0-9_]/', '', $subreddit) ?? '';
    if ($subreddit === '') {
        return [];
    }
    $cacheKey = 'reddit_rss_v1_' . $subreddit . '_' . $category . '_' . $limit;
    $cached = external_news_cache_get($cacheKey, 600);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }

    $url = 'https://www.reddit.com/r/' . rawurlencode($subreddit) . '/.rss';
    $body = external_news_http_get($url, 12, 'Talentra:1.0.0 (external news)');
    $items = external_news_parse_rss($body, $category, 'Reddit r/' . $subreddit, $limit);
    external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $items;
}

function external_news_fetch_reddit(string $subreddit, string $category, int $limit = 10): array
{
    $subreddit = preg_replace('/[^a-zA-Z0-9_]/', '', $subreddit) ?? '';
    if ($subreddit === '') {
        return [];
    }
    $cacheKey = 'reddit_v4_' . $subreddit . '_' . $category . '_' . $limit;
    $cached = external_news_cache_get($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }

    $url = 'https://www.reddit.com/r/' . rawurlencode($subreddit) . '/hot.json?limit=' . max(1, min($limit, 25)) . '&raw_json=1';
    $body = external_news_http_get($url, 12, 'Talentra:1.0.0 (external news)');
    if ($body === '' || (isset($body[0]) && $body[0] === '<')) {
        $url = 'https://old.reddit.com/r/' . rawurlencode($subreddit) . '/hot.json?limit=' . max(1, min($limit, 25)) . '&raw_json=1';
        $body = external_news_http_get($url, 12, 'Talentra:1.0.0 (external news)');
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['data']['children'])) {
        $items = external_news_fetch_reddit_rss($subreddit, $category, $limit);
        external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
        return $items;
    }
    $children = $data['data']['children'] ?? [];
    $items = [];
    foreach ($children as $child) {
        if (count($items) >= $limit) {
            break;
        }
        $post = $child['data'] ?? null;
        if (!is_array($post)) {
            continue;
        }
        if (!empty($post['stickied']) || !empty($post['over_18'])) {
            continue;
        }
        $title = external_news_strip_html((string)($post['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $permalink = 'https://www.reddit.com' . (string)($post['permalink'] ?? '');
        $description = external_news_strip_html((string)($post['selftext'] ?? ''));
        if ($description === '') {
            $description = 'Discussion on r/' . $subreddit;
        }
        $redditMedia = external_news_extract_reddit_media($post);
        $image = (string)($redditMedia['image'] ?? '');
        $video = (string)($redditMedia['video'] ?? '');
        $created = (int)($post['created_utc'] ?? 0);
        $items[] = external_news_normalize_item([
            'category' => $category,
            'source' => 'Reddit r/' . $subreddit,
            'title' => $title,
            'description' => mb_substr($description, 0, 420),
            'url' => $permalink,
            'image' => $image,
            'video' => $video,
            'published_at' => $created > 0 ? gmdate('c', $created) : gmdate('c'),
            'author' => (string)($post['author'] ?? ''),
        ]);
    }
    external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $items;
}

function external_news_fetch_youtube(string $channelId, string $category, string $sourceLabel, int $limit = 8): array
{
    $channelId = preg_replace('/[^a-zA-Z0-9_-]/', '', $channelId) ?? '';
    if ($channelId === '') {
        return [];
    }
    $cacheKey = 'youtube_v1_' . md5($channelId . '|' . $category . '|' . $limit);
    $cached = external_news_cache_get($cacheKey, 900);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }

    $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId);
    $body = external_news_http_get($url);
    $body = trim($body);
    if ($body === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $feed = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($feed === false) {
        return [];
    }

    $items = [];
    foreach ($feed->entry ?? [] as $entry) {
        if (count($items) >= $limit) {
            break;
        }
        $title = external_news_strip_html((string)($entry->title ?? ''));
        if ($title === '') {
            continue;
        }
        $link = '';
        foreach ($entry->link ?? [] as $nodeLink) {
            $attrs = $nodeLink->attributes();
            if ((string)($attrs['rel'] ?? 'alternate') === 'alternate') {
                $link = trim((string)($attrs['href'] ?? ''));
                break;
            }
        }
        $yt = $entry->children('yt', true);
        $videoId = trim((string)($yt->videoId ?? ''));
        if ($link === '' && $videoId !== '') {
            $link = 'https://www.youtube.com/watch?v=' . $videoId;
        }
        $published = trim((string)($entry->published ?? $entry->updated ?? ''));
        $ts = $published !== '' ? strtotime($published) : false;
        $image = '';
        $media = $entry->children('media', true);
        if (isset($media->group)) {
            $group = $media->group->children('media', true);
            if (isset($group->thumbnail)) {
                foreach ($group->thumbnail as $thumb) {
                    $attrs = $thumb->attributes();
                    $candidate = trim((string)($attrs['url'] ?? ''));
                    if ($candidate !== '') {
                        $image = external_news_upgrade_image_url($candidate);
                        break;
                    }
                }
            }
        }
        if ($image === '' && $videoId !== '') {
            $image = 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
        }
        $description = 'Watch on YouTube';
        if (isset($media->group)) {
            $groupMedia = $media->group->children('media', true);
            if (isset($groupMedia->description)) {
                $descText = external_news_strip_html((string)$groupMedia->description);
                if ($descText !== '') {
                    $description = $descText;
                }
            }
        }
        $items[] = external_news_normalize_item([
            'category' => $category,
            'source' => $sourceLabel,
            'title' => $title,
            'description' => $description,
            'url' => $link,
            'image' => $image,
            'video' => $link,
            'published_at' => $ts ? gmdate('c', $ts) : gmdate('c'),
        ]);
    }

    external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $items;
}

function external_news_has_media(array $item): bool
{
    return trim((string)($item['image'] ?? '')) !== '' || trim((string)($item['video'] ?? '')) !== '';
}

function external_news_has_video(array $item): bool
{
    $video = trim((string)($item['video'] ?? ''));
    if ($video !== '' && external_news_is_video_url($video)) {
        return true;
    }
    $url = trim((string)($item['url'] ?? ''));
    return external_news_is_video_url($url);
}

function external_news_fetch_nasa_apod(): array
{
    $cacheKey = 'nasa_apod';
    $cached = external_news_cache_get($cacheKey, 3600);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : [];
    }
    $url = 'https://api.nasa.gov/planetary/apod?api_key=DEMO_KEY';
    $body = external_news_http_get($url);
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['title'])) {
        return [];
    }
    $image = '';
    $video = '';
    if (!empty($data['media_type']) && $data['media_type'] === 'video' && !empty($data['url'])) {
        $video = (string)$data['url'];
    } elseif (!empty($data['url'])) {
        $image = (string)$data['url'];
    }
    $items = [external_news_normalize_item([
        'category' => 'science',
        'source' => 'NASA APOD',
        'title' => (string)$data['title'],
        'description' => external_news_strip_html((string)($data['explanation'] ?? '')),
        'url' => (string)($data['hdurl'] ?? $data['url'] ?? 'https://apod.nasa.gov/apod/'),
        'image' => $image,
        'video' => $video,
        'published_at' => !empty($data['date']) ? gmdate('c', strtotime((string)$data['date'])) : gmdate('c'),
    ])];
    external_news_cache_set($cacheKey, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    return $items;
}

function external_news_sources_for_category(string $category): array
{
    $map = [
        'news' => [
            ['type' => 'rss', 'url' => 'https://feeds.bbci.co.uk/news/rss.xml', 'source' => 'BBC News'],
            ['type' => 'rss', 'url' => 'https://www.theguardian.com/world/rss', 'source' => 'The Guardian'],
            ['type' => 'rss', 'url' => 'http://rss.cnn.com/rss/cnn_topstories.rss', 'source' => 'CNN'],
            ['type' => 'rss', 'url' => 'https://moxie.foxnews.com/google-publisher/latest.xml', 'source' => 'Fox News'],
            ['type' => 'rss', 'url' => 'https://feeds.npr.org/1001/rss.xml', 'source' => 'NPR News'],
            ['type' => 'reddit', 'sub' => 'worldnews'],
        ],
        'business' => [
            ['type' => 'rss', 'url' => 'https://feeds.bbci.co.uk/news/business/rss.xml', 'source' => 'BBC Business'],
            ['type' => 'rss', 'url' => 'https://www.theguardian.com/business/rss', 'source' => 'Guardian Business'],
            ['type' => 'reddit', 'sub' => 'business'],
        ],
        'sports' => [
            ['type' => 'rss', 'url' => 'https://feeds.bbci.co.uk/sport/rss.xml', 'source' => 'BBC Sport'],
            ['type' => 'rss', 'url' => 'https://www.theguardian.com/sport/rss', 'source' => 'Guardian Sport'],
            ['type' => 'reddit', 'sub' => 'sports'],
        ],
        'agriculture' => [
            ['type' => 'reddit', 'sub' => 'Agriculture'],
            ['type' => 'reddit', 'sub' => 'farming'],
            ['type' => 'reddit', 'sub' => 'homestead'],
        ],
        'music' => [
            ['type' => 'youtube', 'channel' => 'UC-9-kyTW8ZkZNDOjb7LAYYg', 'source' => 'YouTube Music'],
            ['type' => 'rss', 'url' => 'https://www.theguardian.com/music/rss', 'source' => 'Guardian Music'],
            ['type' => 'reddit', 'sub' => 'listentothis'],
            ['type' => 'reddit', 'sub' => 'Music'],
        ],
        'science' => [
            ['type' => 'nasa'],
            ['type' => 'youtube', 'channel' => 'UCLA_DiR1FfKNvjuUpBHmylQ', 'source' => 'NASA YouTube'],
            ['type' => 'reddit', 'sub' => 'space'],
            ['type' => 'reddit', 'sub' => 'science'],
        ],
        'arts' => [
            ['type' => 'rss', 'url' => 'https://www.theguardian.com/artanddesign/rss', 'source' => 'Guardian Art'],
            ['type' => 'reddit', 'sub' => 'Art'],
            ['type' => 'reddit', 'sub' => 'printmaking'],
            ['type' => 'reddit', 'sub' => 'graphic_design'],
        ],
        'auto' => [
            ['type' => 'rss', 'url' => 'https://feeds.bbci.co.uk/news/technology/rss.xml', 'source' => 'BBC Tech & Auto'],
            ['type' => 'reddit', 'sub' => 'cars'],
            ['type' => 'reddit', 'sub' => 'carporn'],
            ['type' => 'reddit', 'sub' => 'autos'],
        ],
        'political' => [
            ['type' => 'rss', 'url' => 'https://feeds.bbci.co.uk/news/politics/rss.xml', 'source' => 'BBC Politics'],
            ['type' => 'rss', 'url' => 'https://www.theguardian.com/politics/rss', 'source' => 'Guardian Politics'],
            ['type' => 'rss', 'url' => 'http://rss.cnn.com/rss/cnn_allpolitics.rss', 'source' => 'CNN Politics'],
            ['type' => 'rss', 'url' => 'https://moxie.foxnews.com/google-publisher/politics.xml', 'source' => 'Fox News Politics'],
            ['type' => 'reddit', 'sub' => 'politics'],
        ],
    ];

    if ($category === 'all') {
        $merged = [];
        foreach (array_keys($map) as $cat) {
            foreach ($map[$cat] as $src) {
                $merged[] = array_merge($src, ['category' => $cat]);
            }
        }
        return $merged;
    }

    $sources = $map[$category] ?? [];
    return array_map(static fn(array $src): array => array_merge($src, ['category' => $category]), $sources);
}

function external_news_collect(string $category = 'all', string $query = '', int $limit = 40): array
{
    $category = strtolower(trim($category));
    if (!external_news_is_valid_category($category)) {
        $category = 'all';
    }
    $query = trim($query);
    $limit = max(1, min($limit, 80));
    $sources = external_news_sources_for_category($category);
    $items = [];

    foreach ($sources as $src) {
        $cat = (string)($src['category'] ?? $category);
        if ($cat === 'all') {
            $cat = 'news';
        }
        $chunk = [];
        if (($src['type'] ?? '') === 'rss') {
            $chunk = external_news_fetch_rss((string)$src['url'], $cat, (string)$src['source'], 8);
        } elseif (($src['type'] ?? '') === 'reddit') {
            $chunk = external_news_fetch_reddit((string)$src['sub'], $cat, 6);
        } elseif (($src['type'] ?? '') === 'nasa') {
            $chunk = external_news_fetch_nasa_apod();
        } elseif (($src['type'] ?? '') === 'youtube') {
            $chunk = external_news_fetch_youtube((string)$src['channel'], $cat, (string)$src['source'], 6);
        }
        foreach ($chunk as $row) {
            $items[] = $row;
        }
    }

    if ($query !== '') {
        $q = mb_strtolower($query);
        $items = array_values(array_filter($items, static function (array $it) use ($q): bool {
            $hay = mb_strtolower(($it['title'] ?? '') . ' ' . ($it['description'] ?? '') . ' ' . ($it['source'] ?? ''));
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
        $aMedia = external_news_has_media($a) ? 1 : 0;
        $bMedia = external_news_has_media($b) ? 1 : 0;
        if ($aMedia !== $bMedia) {
            return $bMedia <=> $aMedia;
        }
        return strcmp((string)($b['published_at'] ?? ''), (string)($a['published_at'] ?? ''));
    });

    return array_slice($deduped, 0, $limit);
}

function external_news_time_label(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return '';
    }
    $sec = time() - $ts;
    if ($sec < 3600) {
        return max(1, (int)floor($sec / 60)) . 'm';
    }
    if ($sec < 86400) {
        return (int)floor($sec / 3600) . 'h';
    }
    if ($sec < 604800) {
        return (int)floor($sec / 86400) . 'd';
    }
    return date('M j', $ts);
}
