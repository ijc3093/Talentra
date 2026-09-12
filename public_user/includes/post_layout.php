<?php
declare(strict_types=1);

function post_layout_column(PDO $dbh, bool $refresh = false): ?string
{
    static $cached = false;
    static $found = null;
    if ($cached && !$refresh) {
        return $found;
    }
    $cached = true;
    $found = null;
    try {
        $rows = $dbh->query('SHOW COLUMNS FROM public_posts')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fields = array_map(static fn(array $r): string => (string)($r['Field'] ?? ''), $rows);
        foreach (['layout_type', 'layout', 'post_type', 'type'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $found = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $found = null;
    }
    return $found;
}

/**
 * Ensure public_posts has a layout column so story-circle creates stay distinct from feed cards.
 */
function post_layout_ensure_column(PDO $dbh): ?string
{
    $existing = post_layout_column($dbh);
    if ($existing) {
        return $existing;
    }
    try {
        $dbh->exec("ALTER TABLE public_posts ADD COLUMN layout_type VARCHAR(32) NOT NULL DEFAULT '' AFTER visibility");
    } catch (Throwable $e) {
        return post_layout_column($dbh, true);
    }
    return post_layout_column($dbh, true);
}

function post_extract_layout_marker(string $description): string
{
    if (preg_match('/\[\[layout:([a-z0-9_]+)\]\]/i', $description, $m)) {
        return strtolower(trim((string)($m[1] ?? '')));
    }
    return '';
}

function post_strip_layout_marker(string $description): string
{
    return trim((string)preg_replace('/\[\[layout:[a-z0-9_]+\]\]/i', '', $description));
}

function post_extract_music_marker(string $text): array
{
    $text = (string)$text;
    if (preg_match('/\[\[music:([^|\]]+)\|([^\]]+)\]\]/iu', $text, $m)) {
        return [
            'title' => trim((string)($m[1] ?? '')),
            'artist' => trim((string)($m[2] ?? '')),
        ];
    }
    if (preg_match('/\[\[music:([^\]]+)\]\]/iu', $text, $m)) {
        return [
            'title' => trim((string)($m[1] ?? '')),
            'artist' => '',
        ];
    }

    return ['title' => '', 'artist' => ''];
}

function post_strip_music_marker(string $text): string
{
    return trim((string)preg_replace('/\[\[music:[^\]]+\]\]/iu', '', (string)$text));
}

function post_music_from_row(array $post): array
{
    $title = trim((string)($post['music_title'] ?? ''));
    $artist = trim((string)($post['music_artist'] ?? ''));
    if ($title !== '' || $artist !== '') {
        return ['title' => $title, 'artist' => $artist];
    }

    foreach (['description', 'body', 'title'] as $key) {
        $found = post_extract_music_marker((string)($post[$key] ?? ''));
        if ($found['title'] !== '' || $found['artist'] !== '') {
            return $found;
        }
    }

    return ['title' => '', 'artist' => ''];
}

function post_music_row_html(array $post, string $class = 'mf-music-row'): string
{
    $meta = post_music_from_row($post);
    $title = trim((string)($meta['title'] ?? ''));
    $artist = trim((string)($meta['artist'] ?? ''));
    if ($title === '' && $artist === '') {
        return '';
    }

    $class = trim($class) !== '' ? trim($class) : 'mf-music-row';
    $soundId = (int)($post['sound_id'] ?? 0);
    $attrs = ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-label="Music"';
    if ($soundId > 0) {
        $attrs .= ' role="button" tabindex="0" data-sound-id="' . $soundId . '"'
            . ' data-sound-title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-sound-artist="' . htmlspecialchars($artist, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="Use this sound"';
    }
    $html = '<div' . $attrs . '>';
    $html .= '<i class="fa fa-music mf-music-ic" aria-hidden="true"></i>';
    if ($title !== '') {
        $html .= '<span class="mf-music-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($title !== '' && $artist !== '') {
        $html .= '<span class="mf-music-dot">&middot;</span>';
    }
    if ($artist !== '') {
        $html .= '<span class="mf-music-artist">' . htmlspecialchars($artist, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * First http(s) URL in free text (for in-feed link cards).
 */
function post_first_url_from_text(string $text): string
{
    if ($text === '') {
        return '';
    }
    if (!preg_match('#https?://[^\s<>\'"\]]+#iu', $text, $m)) {
        return '';
    }
    return rtrim((string)$m[0], ".,);]!?");
}

/**
 * Reliable logo/favicon when a site blocks Open Graph scraping.
 */
function post_link_preview_fallback_image(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^www\./i', '', $host) ?: $host;
    if ($host === '' || !str_contains($host, '.') || preg_match('/[^a-z0-9.-]/i', $host)) {
        return '';
    }
    // Google's favicon CDN is reliable even when the site itself blocks scrapers.
    return 'https://www.google.com/s2/favicons?sz=256&domain_url=' . rawurlencode('https://' . $host);
}

/**
 * Brand-style logo URL (aggregated; may fail for some domains — pair with favicon fallback).
 */
function post_link_preview_logo_image(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^www\./i', '', $host) ?: $host;
    if ($host === '' || !str_contains($host, '.') || preg_match('/[^a-z0-9.-]/i', $host)) {
        return '';
    }
    // Unavatar resolves Clearbit / Google / etc. for a solid site mark.
    return 'https://unavatar.io/' . rawurlencode($host) . '?fallback=false';
}

function post_link_preview_is_junk_text(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') {
        return true;
    }
    return (bool)preg_match(
        '/request has been blocked|access denied|just a moment|forbidden|captcha|unusual traffic|enable javascript and cookies|checking your browser/i',
        $t
    );
}

/**
 * Normalize comma/pipe separated topic chips (max 4).
 *
 * @return list<string>
 */
function post_link_preview_parse_tags(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[,|;]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', (string)$part) ?? '');
        $part = trim($part, " \t\n\r\0\x0B.-");
        if ($part === '' || mb_strlen($part) > 32) {
            continue;
        }
        if (post_link_preview_is_junk_text($part)) {
            continue;
        }
        $key = mb_strtolower($part);
        if (isset($out[$key])) {
            continue;
        }
        $out[$key] = $part;
        if (count($out) >= 4) {
            break;
        }
    }
    return array_values($out);
}

/**
 * Build topic chips from description text (e.g. "… hotels, and more.").
 *
 * @return list<string>
 */
function post_link_preview_chips_from_description(string $description): array
{
    $description = trim($description);
    if ($description === '' || post_link_preview_is_junk_text($description)) {
        return [];
    }
    $chunks = preg_split('/[.!?]+/u', $description) ?: [];
    $pool = [];
    foreach ($chunks as $chunk) {
        $chunk = trim((string)$chunk);
        if ($chunk === '') {
            continue;
        }
        // Prefer list-like fragments after commas.
        if (str_contains($chunk, ',')) {
            foreach (preg_split('/,\s*(?:and\s+)?/iu', $chunk) ?: [] as $bit) {
                $bit = trim((string)$bit);
                $bit = preg_replace('/\band\s+/iu', '', $bit) ?? $bit;
                $bit = trim($bit);
                if ($bit === '' || mb_strlen($bit) < 3 || mb_strlen($bit) > 28) {
                    continue;
                }
                // Skip long sentence fragments that are not topics.
                if (str_word_count($bit) > 4) {
                    continue;
                }
                $pool[] = mb_convert_case($bit, MB_CASE_TITLE, 'UTF-8');
            }
        }
    }
    return post_link_preview_parse_tags(implode(', ', $pool));
}

function post_link_preview_from_row(array $post): array
{
    $url = trim((string)($post['link_url'] ?? ''));
    if ($url === '') {
        $chunks = [
            (string)($post['body'] ?? ''),
            (string)($post['description'] ?? ''),
            (string)($post['title'] ?? ''),
        ];
        foreach ($chunks as $chunk) {
            $url = post_first_url_from_text($chunk);
            if ($url !== '') {
                break;
            }
        }
    }
    if ($url === '') {
        return [
            'url' => '',
            'host' => '',
            'label' => '',
            'description' => '',
            'image' => '',
            'image_fallback' => '',
            'is_logo' => false,
            'tags' => [],
        ];
    }
    $host = '';
    try {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = preg_replace('/^www\./i', '', $host) ?: $host;
    } catch (Throwable $e) {
        $host = '';
    }
    $label = trim((string)($post['link_title'] ?? ''));
    if ($label !== '' && post_link_preview_is_junk_text($label)) {
        $label = '';
    }
    if ($label === '') {
        $label = trim((string)($post['title'] ?? ''));
    }
    if ($label === '' || post_link_preview_is_junk_text($label)) {
        $base = preg_replace('/\.(com|net|org|io|co|app|dev|ai|edu|gov|uk|us|ca|au|de|fr|info|biz)(\.[a-z]{2})?$/i', '', $host) ?: $host;
        $base = preg_replace('/\..+$/', '', (string)$base) ?: $host;
        $base = str_replace(['-', '_'], ' ', (string)$base);
        $label = ucwords(strtolower(trim((string)$base)));
        if ($label === '') {
            $label = $host !== '' ? $host : 'Visit link';
        }
    }
    $description = trim((string)($post['link_description'] ?? ''));
    if ($description !== '' && post_link_preview_is_junk_text($description)) {
        $description = '';
    }
    if ($description === '') {
        $description = 'Open this website to see the full page, details, and latest updates.';
    }
    $tags = post_link_preview_parse_tags((string)($post['link_tags'] ?? ''));
    if ($tags === []) {
        $tags = post_link_preview_chips_from_description((string)($post['link_description'] ?? ''));
    }
    $image = trim((string)($post['link_image'] ?? ''));
    $isLogo = false;
    $imageFallback = post_link_preview_fallback_image($host);
    if ($image === '') {
        $logo = post_link_preview_logo_image($host);
        $image = $logo !== '' ? $logo : $imageFallback;
        $isLogo = true;
    } elseif (str_contains($image, 'unavatar.io') || str_contains($image, 'google.com/s2/favicons')) {
        $isLogo = true;
    }
    return [
        'url' => $url,
        'host' => $host,
        'label' => $label,
        'description' => $description,
        'image' => $image,
        'image_fallback' => $imageFallback,
        'is_logo' => $isLogo,
        'tags' => $tags,
    ];
}

function post_link_preview_html(array $post, string $class = 'mf-link-preview'): string
{
    $meta = post_link_preview_from_row($post);
    $url = (string)($meta['url'] ?? '');
    if ($url === '') {
        return '';
    }
    $host = (string)($meta['host'] ?? '');
    $hostShow = $host;
    if ($hostShow !== '' && !str_starts_with(strtolower($hostShow), 'www.')) {
        $hostShow = 'www.' . $hostShow;
    }
    $label = (string)($meta['label'] ?? 'Visit link');
    $description = (string)($meta['description'] ?? '');
    $image = (string)($meta['image'] ?? '');
    $imageFallback = (string)($meta['image_fallback'] ?? '');
    $isLogo = !empty($meta['is_logo']);
    /** @var list<string> $tags */
    $tags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
    $class = trim($class) !== '' ? trim($class) : 'mf-link-preview';
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $hasMedia = $image !== '';
    $html = '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8')
        . ($hasMedia ? ' has-media' : '')
        . ($isLogo ? ' has-logo' : '')
        . '" href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">';
    $html .= '<div class="mf-link-preview-main">';
    if ($hasMedia) {
        $onError = '';
        if ($imageFallback !== '' && $imageFallback !== $image) {
            $onError = ' onerror="this.onerror=null;this.src=\''
                . htmlspecialchars($imageFallback, ENT_QUOTES, 'UTF-8')
                . '\';this.closest(\'.mf-link-preview-media\')&&this.closest(\'.mf-link-preview-media\').classList.add(\'is-logo\')"';
        }
        $html .= '<div class="mf-link-preview-media' . ($isLogo ? ' is-logo' : '') . '"><img src="'
            . htmlspecialchars($image, ENT_QUOTES, 'UTF-8')
            . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"' . $onError . '></div>';
    }
    $html .= '<div class="mf-link-preview-side">';
    $html .= '<div class="mf-link-preview-body">';
    $html .= '<div class="mf-link-preview-top">';
    if ($hostShow !== '') {
        $html .= '<div class="mf-link-preview-host">' . htmlspecialchars($hostShow, ENT_QUOTES, 'UTF-8') . '</div>';
    } else {
        $html .= '<div class="mf-link-preview-host"></div>';
    }
    $html .= '<span class="mf-link-preview-info" title="Website preview" aria-hidden="true"><i class="fa fa-info-circle"></i></span>';
    $html .= '</div>';
    $html .= '<div class="mf-link-preview-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    if ($description !== '') {
        $html .= '<div class="mf-link-preview-desc">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    if ($tags !== []) {
        $html .= '<div class="mf-link-preview-chips">';
        foreach ($tags as $tag) {
            $html .= '<span class="mf-link-preview-chip">' . htmlspecialchars((string)$tag, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="mf-link-preview-cta"><i class="fa fa-link" aria-hidden="true"></i><span>Visit Website</span><i class="fa fa-chevron-right" aria-hidden="true"></i></div>';
    $html .= '</div></div></a>';
    return $html;
}

/**
 * "in" vs "at" for location phrase (Facebook-style).
 */
function post_location_preposition(string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return 'in';
    }
    if (preg_match('/\b(store|church|school|cafe|mall|airport|stadium|park|university|college|hotel|hospital|restaurant|bar|gym|theatre|theater|museum|library|temple|mosque|arena|center|centre|plaza|market|office|campus)\b/i', $location)) {
        return 'at';
    }
    // Street/address-ish → at
    if (preg_match('/\d/', $location) || preg_match('/\b(st|street|ave|avenue|rd|road|blvd|lane|dr|drive)\b/i', $location)) {
        return 'at';
    }
    return 'in';
}

/**
 * Inline story sentence: "is feeling X with Y and in Z."
 * Music is intentionally omitted here (shown on the time subline).
 *
 * @param array<int,array<string,mixed>> $taggedPeople
 */
function post_meta_story_html(array $post, array $taggedPeople = [], string $class = 'mf-story'): string
{
    $feeling = trim((string)($post['feeling_label'] ?? ''));
    $feeling = preg_replace('/^\s*feeling\s+/i', '', $feeling) ?? $feeling;
    $feeling = trim((string)$feeling);
    $location = trim((string)($post['location_label'] ?? ''));

    // Activity verbs use "is {activity}" instead of "is feeling {x}".
    $activityVerbs = ['celebrating', 'watching', 'eating', 'drinking', 'traveling', 'listening', 'working', 'thinking'];
    $feelingPlain = strtolower(trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $feeling) ?? $feeling));
    $isActivity = false;
    foreach ($activityVerbs as $verb) {
        if ($feelingPlain === $verb || str_starts_with($feelingPlain, $verb . ' ')) {
            $isActivity = true;
            break;
        }
    }

    if ($taggedPeople === [] && !empty($post['tagged_people']) && is_array($post['tagged_people'])) {
        $taggedPeople = $post['tagged_people'];
    }
    $tagNames = [];
    foreach ($taggedPeople as $person) {
        if (!is_array($person)) {
            continue;
        }
        $n = trim((string)($person['display_name'] ?? $person['name'] ?? $person['username'] ?? ''));
        if ($n !== '') {
            $tagNames[] = $n;
        }
    }
    if ($feeling === '' && $location === '' && $tagNames === []) {
        return '';
    }

    $conn = static function (string $text): string {
        return '<span class="mf-conn">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    };
    $ent = static function (string $text): string {
        return '<span class="mf-ent">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    };

    $bits = [];
    $started = false;

    if ($feeling !== '') {
        if ($isActivity) {
            $bits[] = $conn('is');
            $bits[] = $ent($feeling);
        } else {
            $bits[] = $conn('is feeling');
            $bits[] = $ent($feeling);
        }
        $started = true;
    }

    if ($tagNames !== []) {
        $peopleHtml = '';
        if (count($tagNames) === 1) {
            $peopleHtml = $ent($tagNames[0]);
        } elseif (count($tagNames) === 2) {
            $peopleHtml = $ent($tagNames[0]) . ' ' . $conn('and') . ' ' . $ent($tagNames[1]);
        } else {
            $extra = count($tagNames) - 1;
            $peopleHtml = $ent($tagNames[0]) . ' ' . $conn('and') . ' ' . $ent($extra . ' others');
        }
        if ($started) {
            $bits[] = $conn('with');
        } else {
            $bits[] = $conn('is with');
            $started = true;
        }
        $bits[] = $peopleHtml;
    }

    if ($location !== '') {
        $prep = post_location_preposition($location);
        if ($started) {
            $bits[] = $conn('and ' . $prep);
        } else {
            $bits[] = $conn('is ' . $prep);
            $started = true;
        }
        $bits[] = $ent(rtrim($location, '.') . '.');
    } else {
        // End sentence when no location trailing period.
        if ($bits !== []) {
            $last = (string)end($bits);
            if (!str_ends_with(strip_tags($last), '.')) {
                $bits[] = $conn('.');
            }
        }
    }

    $class = trim($class) !== '' ? trim($class) : 'mf-story';
    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . implode(' ', $bits) . '</span>';
}

/**
 * Music snippet for the time/subline: note + title · artist
 */
function post_meta_music_inline_html(array $post, string $class = 'mf-music-inline'): string
{
    $music = post_music_from_row($post);
    $musicTitle = trim((string)($music['title'] ?? ''));
    $musicArtist = trim((string)($music['artist'] ?? ''));
    if ($musicTitle === '' && $musicArtist === '') {
        return '';
    }
    $label = $musicTitle;
    if ($musicTitle !== '' && $musicArtist !== '') {
        $label = $musicTitle . ' · ' . $musicArtist;
    } elseif ($musicTitle === '') {
        $label = $musicArtist;
    }
    $soundId = (int)($post['sound_id'] ?? 0);
    $attrs = ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' mf-music-row"';
    if ($soundId > 0) {
        $attrs .= ' role="button" tabindex="0" data-sound-id="' . $soundId . '"'
            . ' data-sound-title="' . htmlspecialchars($musicTitle, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-sound-artist="' . htmlspecialchars($musicArtist, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="Use this sound"';
    }
    return '<span' . $attrs . '><i class="fa fa-music" aria-hidden="true"></i><span class="mf-music-inline-text">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></span>';
}

/**
 * @deprecated Prefer post_meta_story_html + post_meta_music_inline_html.
 * Kept as a thin wrapper so older callers still render the new story UI.
 *
 * @param array<int,array<string,mixed>> $taggedPeople
 */
function post_meta_pills_html(array $post, array $taggedPeople = [], string $class = 'mf-meta-pills'): string
{
    $story = post_meta_story_html($post, $taggedPeople, 'mf-story');
    $music = post_meta_music_inline_html($post);
    if ($story === '' && $music === '') {
        return '';
    }
    // Preserve wrapper class for CSS hooks that still target .mf-meta-pills
    $class = trim($class) !== '' ? trim($class) : 'mf-meta-pills';
    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' is-story">'
        . $story
        . ($story !== '' && $music !== '' ? '<span class="mf-conn mf-story-sep"> · </span>' : '')
        . $music
        . '</span>';
}

function post_declared_layout(array $post): string
{
    foreach (['declared_layout', 'layout_type', 'layout', 'post_type', 'type'] as $key) {
        if (!empty($post[$key])) {
            $value = strtolower(trim((string)$post[$key]));
            if ($value !== '') {
                return $value;
            }
        }
    }
    foreach (['description', 'descr'] as $descKey) {
        $marker = post_extract_layout_marker((string)($post[$descKey] ?? ''));
        if ($marker !== '') {
            return $marker;
        }
    }
    return '';
}

function post_is_story_only(array $post): bool
{
    return post_declared_layout($post) === 'story';
}

function post_media_attachment_count_sql(string $postAlias = 'p'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $postAlias) ?: 'p';
    return "(SELECT COUNT(*) FROM public_post_attachments a_ss WHERE a_ss.post_id = {$alias}.id AND LOWER(TRIM(COALESCE(a_ss.type,''))) IN ('image','video','gif'))";
}

function post_story_layout_sql(PDO $dbh, string $postAlias = 'p'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $postAlias) ?: 'p';
    $story = "COALESCE({$alias}.description,'') LIKE '%[[layout:story]]%' OR COALESCE({$alias}.body,'') LIKE '%[[layout:story]]%' OR COALESCE({$alias}.title,'') LIKE '%[[layout:story]]%'";
    $layoutCol = post_layout_column($dbh);
    if ($layoutCol) {
        $safe = preg_replace('/[^a-z0-9_]/i', '', (string)$layoutCol);
        if ($safe !== '') {
            $story = "LOWER(TRIM(COALESCE({$alias}.`{$safe}`,''))) = 'story' OR " . $story;
        }
    }
    return $story;
}

function post_exclude_slideshow_photos_sql(PDO $dbh, string $postAlias = 'p'): string
{
    return '((' . post_story_layout_sql($dbh, $postAlias) . ') OR ' . post_media_attachment_count_sql($postAlias) . ' <= 1)';
}

function post_is_slideshow_photos(array $post): bool
{
    if (post_is_story_only($post)) {
        return false;
    }
    if (isset($post['slideshow_photo_count'])) {
        return (int)$post['slideshow_photo_count'] > 1;
    }
    if (!empty($post['attachments']) && is_array($post['attachments'])) {
        $photos = 0;
        foreach ($post['attachments'] as $att) {
            $type = strtolower(trim((string)($att['type'] ?? '')));
            if ($type === 'image' || $type === 'gif') {
                $photos++;
            }
        }
        return $photos > 1;
    }
    $kind = strtolower(trim((string)($post['preview_type'] ?? $post['atype'] ?? '')));
    if ($kind === 'video') {
        return false;
    }
    return (int)($post['attachment_count'] ?? $post['media_count'] ?? 0) > 1;
}

function post_normalize_card_plain_text(string $text): string
{
    $text = post_strip_layout_marker($text);
    $text = post_strip_music_marker($text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $text) ?? $text;
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

function post_format_story_text(string $text): string
{
    return post_normalize_card_plain_text($text);
}

function post_story_caption(array $post): string
{
    $title = post_format_story_text((string)($post['title'] ?? ''));
    $description = post_format_story_text(strip_tags((string)($post['description'] ?? ($post['descr'] ?? ''))));
    $body = post_format_story_text((string)($post['body'] ?? ''));

    if ($body !== '') {
        if ($title !== '' && !str_starts_with($body, $title)) {
            return $title . "\n\n" . $body;
        }
        return $body;
    }
    if ($description !== '') {
        if ($title !== '' && $description !== $title) {
            return $title . "\n\n" . $description;
        }
        return $description;
    }
    return $title;
}

/**
 * Slide summary HTML: multi-line text becomes a bullet list; single line stays a short summary.
 */
function post_slide_summary_html(string $text): string
{
    $text = post_normalize_card_plain_text($text);
    if ($text === '') {
        return '';
    }
    $rawLines = preg_split("/\r\n|\r|\n/", $text) ?: [];
    $items = [];
    foreach ($rawLines as $line) {
        $line = trim((string)$line);
        $line = preg_replace('/^(?:[•\-\*]|\d+[\.\)])\s+/u', '', $line) ?? $line;
        if ($line !== '') {
            $items[] = $line;
        }
    }
    if ($items === []) {
        return '';
    }
    if (count($items) === 1) {
        return '<div class="post-slide-summary"><p class="post-slide-summary-p">'
            . htmlspecialchars($items[0], ENT_QUOTES, 'UTF-8')
            . '</p></div>';
    }
    $html = '<div class="post-slide-summary"><ul class="post-slide-summary-list">';
    foreach ($items as $item) {
        $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul></div>';
    return $html;
}

function post_format_card_text_html(string $text): string
{
    $text = post_normalize_card_plain_text($text);
    if ($text === '') {
        return '';
    }

    $blocks = preg_split("/\n\s*\n/", $text) ?: [];
    $html = '';
    foreach ($blocks as $block) {
        $block = trim((string)$block);
        if ($block === '') {
            continue;
        }
        $lines = [];
        foreach (explode("\n", $block) as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $lines[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            }
        }
        if ($lines === []) {
            continue;
        }
        $html .= '<p class="post-card-paragraph">' . implode('<br>', $lines) . '</p>';
    }

    return $html;
}

/** Count sentences for card caption clamp (personal + publisher). */
function post_caption_sentence_count(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($parts);
}

function post_limit_sentences(string $text, int $maxSentences = 3): string
{
    $text = trim($text);
    if ($text === '' || $maxSentences < 1) {
        return $text;
    }

    $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) <= $maxSentences) {
        return $text;
    }

    return trim(implode(' ', array_slice($parts, 0, $maxSentences)));
}

/** True when card should show Read more (more than 3 sentences, or one very long block). */
function post_caption_needs_readmore(string $caption, int $maxSentences = 3, int $maxChars = 170): bool
{
    $caption = post_normalize_card_plain_text(trim($caption));
    if ($caption === '') {
        return false;
    }
    if (post_caption_sentence_count($caption) > $maxSentences) {
        return true;
    }
    return mb_strlen($caption) > $maxChars;
}

/**
 * Formatted caption HTML for feed/public/profile cards.
 * Long text is clamped to ~3 sentences for personal and publisher posts alike.
 */
function post_caption_card_html(string $caption, int $maxSentences = 3, int $maxChars = 170): string
{
    $caption = post_normalize_card_plain_text(trim($caption));
    if ($caption === '') {
        return '';
    }

    $needsClamp = post_caption_needs_readmore($caption, $maxSentences, $maxChars);
    $display = $caption;
    if ($needsClamp) {
        $display = post_limit_sentences($caption, $maxSentences);
        if ($display === $caption && mb_strlen($caption) > $maxChars) {
            $cut = mb_substr($caption, 0, $maxChars);
            $sp = mb_strrpos($cut, ' ');
            if ($sp !== false && $sp > (int)($maxChars * 0.6)) {
                $cut = mb_substr($cut, 0, $sp);
            }
            $display = rtrim($cut) . '…';
        } elseif ($display !== $caption) {
            // Make it visually clear that the caption continues before the
            // inline Read more control, even when the last shown sentence
            // already ends in punctuation.
            $display = rtrim($display, " \t\n\r\0\x0B…") . '…';
        }
    }

    $formatted = post_format_card_text_html($display);
    $class = 'post-card-caption-formatted' . ($needsClamp ? ' is-clamped' : '');
    return '<div class="' . $class . '">' . $formatted . '</div>';
}

function post_allowed_layout_override(string $layout): string
{
    $layout = strtolower(trim($layout));
    return in_array($layout, ['', 'image_bottom', 'media_reel_bottom', 'story'], true) ? $layout : '';
}

function post_layout_select_sql(PDO $dbh): string
{
    $layoutColumn = post_layout_column($dbh);
    return $layoutColumn
        ? ('COALESCE(p.`' . $layoutColumn . '`,\'\') AS declared_layout,')
        : "'' AS declared_layout,";
}
