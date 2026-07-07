<?php
declare(strict_types=1);

function post_layout_column(PDO $dbh): ?string
{
    static $cached = false;
    static $found = null;
    if ($cached) {
        return $found;
    }
    $cached = true;
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
    $html = '<div class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-label="Music">';
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
