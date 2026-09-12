<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../includes/post_layout.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$url = trim((string)($_GET['url'] ?? $_POST['url'] ?? ''));
if ($url === '') {
    echo json_encode(['ok' => false, 'error' => 'Paste a website link.']);
    exit;
}
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . ltrim($url, '/');
}
if (!filter_var($url, FILTER_VALIDATE_URL) || preg_match('/\s/', $url)) {
    echo json_encode(['ok' => false, 'error' => 'That does not look like a valid link. Paste a full URL (example: https://www.apple.com).']);
    exit;
}

$host = '';
try {
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $host = preg_replace('/^www\./i', '', $host) ?: $host;
} catch (Throwable $e) {
    $host = '';
}
// Require a real domain (must contain a dot), not free text like "Apple Store".
if ($host === '' || !str_contains($host, '.') || preg_match('/[^a-z0-9.-]/i', $host)) {
    echo json_encode(['ok' => false, 'error' => 'That does not look like a valid link. Paste a full URL (example: https://www.apple.com).']);
    exit;
}

/**
 * Sites often block datacenter scrapers. Treat those pages as failed previews.
 */
function msb_link_preview_is_junk(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') {
        return true;
    }
    $needles = [
        'request has been blocked',
        'access denied',
        'access blocked',
        'forbidden',
        'just a moment',
        'attention required',
        'cf-browser-verification',
        'checking your browser',
        'enable javascript and cookies',
        'captcha',
        'bot detection',
        'unusual traffic',
        'please verify you are a human',
        'security check',
        'sorry, you have been blocked',
        'error 403',
        'error 1020',
        'http error 403',
        'not acceptable',
        'akamai',
    ];
    foreach ($needles as $n) {
        if (str_contains($t, $n)) {
            return true;
        }
    }
    return false;
}

function msb_link_preview_host_title(string $host): string
{
    $base = preg_replace('/\.(com|net|org|io|co|app|dev|ai|edu|gov|uk|us|ca|au|de|fr|info|biz)(\.[a-z]{2})?$/i', '', $host) ?: $host;
    $base = preg_replace('/\..+$/', '', $base) ?: $base;
    $base = str_replace(['-', '_'], ' ', $base);
    $nice = ucwords(strtolower(trim($base)));
    return $nice !== '' ? $nice : ($host !== '' ? $host : 'Visit Website');
}

$title = '';
$description = '';
$image = '';
$tags = [];
$httpCode = 0;
$raw = null;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    if ($ch !== false) {
        // Browser-like headers reduce WAF blocks (Microsoft, Cloudflare, etc.).
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Upgrade-Insecure-Requests: 1',
            ],
        ];
        if (defined('CURL_IPRESOLVE_V4')) {
            $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
}

$fetchOk = is_string($raw) && $raw !== '' && $httpCode >= 200 && $httpCode < 400;
if ($fetchOk) {
    $html = substr($raw, 0, 350000);
    // If the HTML itself is a block page, ignore scraped fields.
    if (msb_link_preview_is_junk(strip_tags(substr($html, 0, 4000)))) {
        $fetchOk = false;
    }
}

if ($fetchOk) {
    $html = substr((string)$raw, 0, 350000);
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) {
        $title = html_entity_decode(trim((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = html_entity_decode(trim(strip_tags((string)$m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/i', $html, $m)
        || preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $description = html_entity_decode(trim((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)
        || preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i', $html, $m)
        || preg_match('/<link[^>]+rel=["\']apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']apple-touch-icon[^"\']*["\']/i', $html, $m)) {
        $image = trim((string)$m[1]);
        if ($image !== '' && !preg_match('#^https?://#i', $image) && $host !== '') {
            if (str_starts_with($image, '//')) {
                $image = 'https:' . $image;
            } elseif (str_starts_with($image, '/')) {
                $image = 'https://' . $host . $image;
            }
        }
        if (msb_link_preview_is_junk($image)) {
            $image = '';
        }
    }
    if (preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']keywords["\']/i', $html, $m)) {
        $kw = html_entity_decode(trim((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($kw !== '' && function_exists('post_link_preview_parse_tags')) {
            $tags = post_link_preview_parse_tags($kw);
        }
    }
}

if (msb_link_preview_is_junk($title)) {
    $title = '';
}
if (msb_link_preview_is_junk($description)) {
    $description = '';
}
if ($title === '') {
    $title = msb_link_preview_host_title($host);
}
if ($description === '') {
    $description = 'Open this website to see the full page, details, and latest updates.';
}
if ($tags === [] && function_exists('post_link_preview_chips_from_description') && $fetchOk) {
    // Only derive chips from a real scraped description, not the generic fallback.
    $genericDesc = 'Open this website to see the full page, details, and latest updates.';
    if (trim($description) !== '' && trim($description) !== $genericDesc) {
        $tags = post_link_preview_chips_from_description($description);
    }
}

$isLogo = false;
if ($image === '' && $host !== '' && function_exists('post_link_preview_logo_image')) {
    $logo = post_link_preview_logo_image($host);
    $fallback = function_exists('post_link_preview_fallback_image')
        ? post_link_preview_fallback_image($host)
        : '';
    $image = $logo !== '' ? $logo : $fallback;
    $isLogo = $image !== '';
}
if ($image === '' && $host !== '' && function_exists('post_link_preview_fallback_image')) {
    $image = post_link_preview_fallback_image($host);
    $isLogo = $image !== '';
}

$tagsCsv = implode(', ', $tags);

echo json_encode([
    'ok' => true,
    'link' => [
        'url' => $url,
        'host' => $host,
        'title' => mb_substr($title, 0, 180),
        'description' => mb_substr($description, 0, 280),
        'image' => mb_substr($image, 0, 500),
        'image_fallback' => function_exists('post_link_preview_fallback_image')
            ? mb_substr(post_link_preview_fallback_image($host), 0, 500)
            : '',
        'is_logo' => $isLogo,
        'tags' => $tags,
        'tags_csv' => mb_substr($tagsCsv, 0, 280),
    ],
    'preview_partial' => !$fetchOk,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
