<?php
declare(strict_types=1);

/**
 * Viewer typography: header size (Small / Normal / Larger) + independent body pt,
 * each with its own font family.
 */

function msb_type_fonts(): array
{
    return [
        'Arial' => ['stack' => 'Arial, Helvetica, sans-serif', 'google' => ''],
        'Calibri' => ['stack' => 'Calibri, Carlito, "Segoe UI", sans-serif', 'google' => ''],
        'Helvetica' => ['stack' => 'Helvetica, "Helvetica Neue", Arial, sans-serif', 'google' => ''],
        'Georgia' => ['stack' => 'Georgia, "Times New Roman", serif', 'google' => ''],
        'Times New Roman' => ['stack' => '"Times New Roman", Times, serif', 'google' => ''],
        'Courier New' => ['stack' => '"Courier New", Courier, monospace', 'google' => ''],
        'Comic Sans MS' => ['stack' => '"Comic Sans MS", "Comic Sans", cursive', 'google' => ''],
        'Impact' => ['stack' => 'Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif', 'google' => ''],
        'Verdana' => ['stack' => 'Verdana, Geneva, sans-serif', 'google' => ''],
        'Trebuchet MS' => ['stack' => '"Trebuchet MS", Tahoma, sans-serif', 'google' => ''],
        'Segoe UI' => ['stack' => '"Segoe UI", Tahoma, sans-serif', 'google' => ''],
        'Roboto' => ['stack' => 'Roboto, Arial, sans-serif', 'google' => 'Roboto:wght@400;500;700'],
        'Poppins' => ['stack' => 'Poppins, Arial, sans-serif', 'google' => 'Poppins:wght@400;500;600;700'],
        'Merriweather' => ['stack' => 'Merriweather, Georgia, serif', 'google' => 'Merriweather:wght@400;700'],
        'Lora' => ['stack' => 'Lora, Georgia, serif', 'google' => 'Lora:wght@400;600;700'],
        'Lexend' => ['stack' => 'Lexend, Arial, sans-serif', 'google' => 'Lexend:wght@400;600'],
        'Lobster' => ['stack' => 'Lobster, cursive', 'google' => 'Lobster'],
        'Comfortaa' => ['stack' => 'Comfortaa, Arial, sans-serif', 'google' => 'Comfortaa:wght@400;700'],
        'Caveat' => ['stack' => 'Caveat, cursive', 'google' => 'Caveat:wght@400;700'],
        'EB Garamond' => ['stack' => '"EB Garamond", Garamond, serif', 'google' => 'EB+Garamond:wght@400;600;700'],
        'Amatic SC' => ['stack' => '"Amatic SC", cursive', 'google' => 'Amatic+SC:wght@400;700'],
    ];
}

function msb_type_font_values(): array
{
    return array_keys(msb_type_fonts());
}

function msb_type_font_options(): array
{
    $out = [];
    foreach (msb_type_font_values() as $name) {
        $out[$name] = $name;
    }
    return $out;
}

function msb_type_font_normalize(string $name): string
{
    $name = trim($name);
    $fonts = msb_type_fonts();
    if (isset($fonts[$name])) {
        return $name;
    }
    foreach ($fonts as $label => $_meta) {
        if (strcasecmp($label, $name) === 0) {
            return $label;
        }
    }
    return 'Arial';
}

function msb_type_font_stack(string $name): string
{
    $fonts = msb_type_fonts();
    $key = msb_type_font_normalize($name);
    return (string)($fonts[$key]['stack'] ?? 'Arial, Helvetica, sans-serif');
}

function msb_type_header_sizes(): array
{
    return [
        'small' => '16px',
        'medium' => '20px',
        'large' => '28px',
    ];
}

function msb_type_prefs_defaults(): array
{
    return [
        'headerSize' => 'small',
        'headerFont' => 'Arial',
        'bodyPt' => 9,
        'bodyFont' => 'Arial',
        'textColor' => '#000000',
    ];
}

function msb_type_header_size_normalize(string $size): string
{
    $size = strtolower(trim($size));
    return in_array($size, ['small', 'medium', 'large'], true) ? $size : 'small';
}

function msb_type_body_pt_values(): array
{
    return [8, 9, 10, 11, 12, 14, 16, 17, 18, 20, 24, 30, 36];
}

function msb_type_body_pt_options(): array
{
    $out = [];
    foreach (msb_type_body_pt_values() as $pt) {
        $out[(string)$pt] = (string)$pt;
    }
    return $out;
}

function msb_type_body_pt_normalize($pt): int
{
    $n = (int)$pt;
    if ($n < 8) {
        return 8;
    }
    if ($n > 72) {
        return 72;
    }
    return $n;
}

function msb_type_text_color_normalize(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === 'theme') {
        return 'theme';
    }
    if ($value === '' || $value === 'default' || $value === 'system') {
        return '#000000';
    }
    if (function_exists('appearance_palette_parse_custom_hex')) {
        $hex = appearance_palette_parse_custom_hex($value);
        if (is_string($hex) && $hex !== '') {
            return $hex;
        }
    }
    if (preg_match('/^#?([0-9a-f]{6})$/', $value, $m)) {
        return '#' . $m[1];
    }
    return '#000000';
}

function msb_type_text_color_presets(): array
{
    return [
        '#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#ffffff',
        '#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff',
        '#4a86e8', '#0000ff', '#9900ff', '#ff00ff',
    ];
}

function msb_type_prefs_from_settings(array $row): array
{
    return [
        'headerSize' => msb_type_header_size_normalize((string)($row['header_type_size'] ?? 'small')),
        'headerFont' => msb_type_font_normalize((string)($row['header_font_family'] ?? 'Arial')),
        'bodyPt' => msb_type_body_pt_normalize($row['body_font_size_pt'] ?? 9),
        'bodyFont' => msb_type_font_normalize((string)($row['body_font_family'] ?? 'Arial')),
        'textColor' => msb_type_text_color_normalize((string)($row['text_color'] ?? '#000000')),
    ];
}

function msb_type_prefs_response_allows_html(): bool
{
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xrw === 'xmlhttprequest') {
        return false;
    }
    if (trim((string)($_GET['ajax'] ?? $_POST['ajax'] ?? '')) !== '') {
        return false;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') !== 0) {
            continue;
        }
        $ct = strtolower($h);
        if (strpos($ct, 'application/json') !== false
            || strpos($ct, 'text/javascript') !== false
            || strpos($ct, 'application/javascript') !== false
            || strpos($ct, 'text/css') !== false
            || strpos($ct, 'octet-stream') !== false
            || strpos($ct, 'image/') !== false
            || strpos($ct, 'audio/') !== false
            || strpos($ct, 'video/') !== false) {
            return false;
        }
    }
    return true;
}

function msb_type_prefs_print(PDO $dbh, int $userId): void
{
    if (!empty($GLOBALS['msb_type_prefs_printed'])) {
        return;
    }
    if (!msb_type_prefs_response_allows_html()) {
        return;
    }
    $GLOBALS['msb_type_prefs_printed'] = true;

    if ($userId > 0 && function_exists('profile_settings_ensure_tab_privacy_columns')) {
        profile_settings_ensure_tab_privacy_columns($dbh);
    }

    $row = [];
    if ($userId > 0 && function_exists('profile_settings_row')) {
        $row = profile_settings_row($dbh, $userId);
    }
    $prefs = msb_type_prefs_from_settings(is_array($row) ? $row : []);
    $headerPx = msb_type_header_sizes()[$prefs['headerSize']] ?? '20px';
    $headerStack = msb_type_font_stack($prefs['headerFont']);
    $bodyStack = msb_type_font_stack($prefs['bodyFont']);
    $bodyPt = (int)$prefs['bodyPt'];
    $textColor = (string)($prefs['textColor'] ?? 'theme');
    $textColorCss = ($textColor !== 'theme') ? $textColor : '';

    $google = [];
    $fonts = msb_type_fonts();
    foreach ([$prefs['headerFont'], $prefs['bodyFont']] as $fontName) {
        $g = trim((string)($fonts[$fontName]['google'] ?? ''));
        if ($g !== '' && !in_array($g, $google, true)) {
            $google[] = $g;
        }
    }
    if ($google) {
        $href = 'https://fonts.googleapis.com/css2?';
        $parts = [];
        foreach ($google as $g) {
            $parts[] = 'family=' . $g;
        }
        $href .= implode('&', $parts) . '&display=swap';
        echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    $map = [];
    foreach (msb_type_fonts() as $name => $meta) {
        $map[$name] = (string)$meta['stack'];
    }
    $jsonMap = json_encode($map, JSON_UNESCAPED_SLASHES);
    $jsonPrefs = json_encode($prefs, JSON_UNESCAPED_SLASHES);
    if (!is_string($jsonMap)) {
        $jsonMap = '{}';
    }
    if (!is_string($jsonPrefs)) {
        $jsonPrefs = '{}';
    }

    echo '<style id="msb-type-prefs">'
        . 'html{--msb-header-font:' . htmlspecialchars($headerStack, ENT_QUOTES, 'UTF-8')
        . ';--msb-body-font:' . htmlspecialchars($bodyStack, ENT_QUOTES, 'UTF-8')
        . ';--msb-header-size:' . htmlspecialchars($headerPx, ENT_QUOTES, 'UTF-8')
        . ';--msb-body-size:' . $bodyPt . 'pt'
        . ($textColorCss !== '' ? ';--msb-text-color:' . htmlspecialchars($textColorCss, ENT_QUOTES, 'UTF-8') : '')
        . ';}'
        . 'html[data-msb-header-size="small"]{--msb-header-size:16px;}'
        . 'html[data-msb-header-size="medium"]{--msb-header-size:20px;}'
        . 'html[data-msb-header-size="large"]{--msb-header-size:28px;}'
        . 'html[data-msb-type] body .mf-title,'
        . 'html[data-msb-type] body .ig-insta-caption-block .ig-title,'
        . 'html[data-msb-type] body .ig-fullname-name{'
        . 'font-family:var(--msb-header-font) !important;'
        . 'font-size:var(--msb-header-size) !important;}'
        . 'html[data-msb-type] body .mf-card > .mf-body,'
        . 'html[data-msb-type] body .mf-card > .mf-body .mf-body-formatted,'
        . 'html[data-msb-type] body .mf-card > .mf-body .post-card-paragraph,'
        . 'html[data-msb-type] body .mf-card > .mf-body p,'
        . 'html[data-msb-type] body .ig-insta-caption-block .ig-body,'
        . 'html[data-msb-type] body .post.public-post-card .standard-text-caption,'
        . 'html[data-msb-type] body .post.public-post-card .standard-media-caption{'
        . 'font-family:var(--msb-body-font) !important;'
        . 'font-size:var(--msb-body-size) !important;}'
        . 'html[data-msb-text-color] body .mf-title,'
        . 'html[data-msb-text-color] body .ig-insta-caption-block .ig-title,'
        . 'html[data-msb-text-color] body .ig-fullname-name,'
        . 'html[data-msb-text-color] body .mf-card > .mf-body,'
        . 'html[data-msb-text-color] body .mf-card > .mf-body .mf-body-formatted,'
        . 'html[data-msb-text-color] body .ig-insta-caption-block .ig-body,'
        . 'html[data-msb-text-color] body .post.public-post-card .standard-text-caption,'
        . 'html[data-msb-text-color] body .post.public-post-card .standard-media-caption{'
        . 'color:var(--msb-text-color) !important;}'
        . '</style>' . "\n";

    $headerPxJs = json_encode(msb_type_header_sizes());
    if (!is_string($headerPxJs)) {
        $headerPxJs = '{}';
    }

    echo '<script>window.MSB_TYPE_FONTS=' . $jsonMap . ';'
        . 'window.MSB_TYPE_PREFS=' . $jsonPrefs . ';'
        . 'window.MSB_TYPE_HEADER_PX=' . $headerPxJs . ';'
        . '(function(){var r=document.documentElement;var p=window.MSB_TYPE_PREFS||{};var fonts=window.MSB_TYPE_FONTS||{};'
        . 'var px=(window.MSB_TYPE_HEADER_PX||{})[p.headerSize]||"16px";'
        . 'r.setAttribute("data-msb-type","1");'
        . 'r.setAttribute("data-msb-header-size",String(p.headerSize||"small"));'
        . 'r.style.setProperty("--msb-header-font",fonts[p.headerFont]||"Arial, Helvetica, sans-serif");'
        . 'r.style.setProperty("--msb-body-font",fonts[p.bodyFont]||"Arial, Helvetica, sans-serif");'
        . 'r.style.setProperty("--msb-header-size",px);'
        . 'r.style.setProperty("--msb-body-size",String(p.bodyPt||9)+"pt");'
        . 'if(p.textColor&&p.textColor!=="theme"){r.setAttribute("data-msb-text-color","1");r.style.setProperty("--msb-text-color",String(p.textColor));}'
        . 'else{r.removeAttribute("data-msb-text-color");r.style.removeProperty("--msb-text-color");}'
        . '})();</script>' . "\n";

    if (empty($GLOBALS['msb_type_prefs_shutdown'])) {
        $GLOBALS['msb_type_prefs_shutdown'] = true;
        register_shutdown_function('msb_type_prefs_print_apply_css');
    }
}

function msb_type_prefs_header_selectors(): string
{
    return implode(',', [
        'html[data-msb-type] body h1',
        'html[data-msb-type] body h2',
        'html[data-msb-type] body h3',
        'html[data-msb-type] body .mf-title',
        'html[data-msb-type] body .mf-feed .mf-title',
        'html[data-msb-type] body .mf-card .mf-title',
        'html[data-msb-type] body .mf-slide-title',
        'html[data-msb-type] body .ig-insta-caption-block .ig-title',
        'html[data-msb-type] body .ig-fullname-name',
        'html[data-msb-type] body .standard-text-title',
        'html[data-msb-type] body .standard-media-title',
        'html[data-msb-type] body.public-page.feed-insta-ui .standard-text-title',
        'html[data-msb-type] body.public-page.feed-insta-ui .standard-media-title',
        'html[data-msb-type] body.home-page.feed-insta-ui .standard-text-title',
        'html[data-msb-type] body.home-page.feed-insta-ui .standard-media-title',
        'html[data-msb-type] body.news-page.feed-insta-ui .standard-text-title',
        'html[data-msb-type] body.news-page.feed-insta-ui .standard-media-title',
        'html[data-msb-type] body.feed-page.feed-insta-ui .mf-feed .mf-title',
        'html[data-msb-type] body.profile-page .ig-fullname-name',
        'html[data-msb-type] body.profile-page.settings-page .gear-nav-section-label',
        'html[data-msb-type] body.profile-page.settings-page .gear-detail-title',
        'html[data-msb-type] body.profile-page.settings-page .gear-row-group-title',
        'html[data-msb-type] body #profilePostsFeed .mf-title',
        'html[data-msb-type] body #profilePostsFeed .mf-card .mf-title',
    ]);
}

function msb_type_prefs_body_selectors(): string
{
    return implode(',', [
        'html[data-msb-type] body .mf-body',
        'html[data-msb-type] body .mf-feed .mf-body',
        'html[data-msb-type] body .mf-feed .mf-video-body',
        'html[data-msb-type] body .mf-card .mf-body',
        'html[data-msb-type] body .mf-card > .mf-body',
        'html[data-msb-type] body .mf-body .mf-body-formatted',
        'html[data-msb-type] body .mf-body .post-card-paragraph',
        'html[data-msb-type] body .mf-body p',
        'html[data-msb-type] body .ig-insta-caption-block .ig-body',
        'html[data-msb-type] body .standard-text-caption',
        'html[data-msb-type] body .standard-media-caption',
        'html[data-msb-type] body .standard-text-caption .post-card-paragraph',
        'html[data-msb-type] body .standard-media-caption .post-card-paragraph',
        'html[data-msb-type] body .standard-text-caption .post-card-caption-formatted',
        'html[data-msb-type] body .standard-media-caption .post-card-caption-formatted',
        'html[data-msb-type] body .standard-media-summary',
        'html[data-msb-type] body.public-page.feed-insta-ui .standard-text-caption',
        'html[data-msb-type] body.public-page.feed-insta-ui .standard-media-caption',
        'html[data-msb-type] body.home-page.feed-insta-ui .standard-text-caption',
        'html[data-msb-type] body.home-page.feed-insta-ui .standard-media-caption',
        'html[data-msb-type] body.news-page.feed-insta-ui .standard-text-caption',
        'html[data-msb-type] body.news-page.feed-insta-ui .standard-media-caption',
        'html[data-msb-type] body.feed-page.feed-insta-ui .mf-feed .mf-body',
        'html[data-msb-type] body.profile-page.settings-page .gear-nav-item-desc',
        'html[data-msb-type] body.profile-page.settings-page .gear-detail-desc',
        'html[data-msb-type] body.profile-page.settings-page .gear-row-group-intro',
        'html[data-msb-type] body #profilePostsFeed .mf-body',
        'html[data-msb-type] body #profilePostsFeed .mf-card > .mf-body',
        'html[data-msb-type] body #profilePostsFeed .mf-body .mf-body-formatted',
        'html[data-msb-type] body #profilePostsFeed .mf-body .post-card-paragraph',
    ]);
}

function msb_type_prefs_print_apply_css(): void
{
    if (!empty($GLOBALS['msb_type_prefs_apply_printed'])) {
        return;
    }
    if (!msb_type_prefs_response_allows_html()) {
        return;
    }
    $GLOBALS['msb_type_prefs_apply_printed'] = true;
    $headers = msb_type_prefs_header_selectors();
    $bodies = msb_type_prefs_body_selectors();
    $color = str_replace('html[data-msb-type]', 'html[data-msb-text-color]', $headers . ',' . $bodies);
    echo '<style id="msb-type-prefs-apply">'
        . $headers . '{'
        . 'font-family:var(--msb-header-font) !important;'
        . 'font-size:var(--msb-header-size) !important;'
        . '}'
        . $bodies . '{'
        . 'font-family:var(--msb-body-font) !important;'
        . 'font-size:var(--msb-body-size) !important;'
        . '}'
        . $color . '{'
        . 'color:var(--msb-text-color) !important;'
        . '}'
        . 'html[data-msb-type] body .sh-logopanel,'
        . 'html[data-msb-type] body .sh-logopanel *,'
        . 'html[data-msb-text-color] body .sh-logopanel,'
        . 'html[data-msb-text-color] body .sh-logopanel *{'
        . 'font-size:revert !important;'
        . 'font-family:var(--msb-font-logo, inherit) !important;'
        . 'color:revert !important;'
        . '}'
        . '</style>' . "\n";
}
