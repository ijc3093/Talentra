<?php
declare(strict_types=1);

require_once __DIR__ . '/session_user.php';
require_once __DIR__ . '/publisher_accounts.php';
require_once __DIR__ . '/profile_access.php';
require_once __DIR__ . '/appearance_palettes.php';
require_once __DIR__ . '/appearance_bridge.php';

function theme_prefs_viewer_user_id(): int
{
    if (function_exists('profile_session_owner_user_id')) {
        $uid = profile_session_owner_user_id();
        if ($uid > 0) {
            return $uid;
        }
    }

    if (function_exists('myUserId')) {
        $uid = myUserId();
        if ($uid > 0) {
            return $uid;
        }
    }

    return (int)($_SESSION['user_id'] ?? 0);
}

function theme_prefs_appearance_mode(PDO $dbh, int $userId): string
{
    if ($userId <= 0) {
        return 'system';
    }

    appearance_palette_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('SELECT appearance_mode FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $mode = trim((string)($st->fetchColumn() ?: ''));
        return appearance_palette_normalize_mode($mode);
    } catch (Throwable $e) {
        // fall through
    }

    return 'system';
}

function theme_prefs_js_config(PDO $dbh, int $userId): array
{
    $mode = theme_prefs_appearance_mode($dbh, $userId);
    $manualMode = 'dark';
    if ($mode === 'light') {
        $manualMode = 'light';
    } elseif ($mode === 'dark') {
        $manualMode = 'dark';
    } elseif ($mode !== 'system') {
        $manualMode = appearance_palette_is_dark_slug($mode) ? 'dark' : 'light';
    }

    return [
        'userId' => $userId,
        'autoEnabled' => ($mode === 'system'),
        'manualMode' => $manualMode,
        'appearanceMode' => $mode,
    ];
}

function theme_prefs_print_head_bootstrap(PDO $dbh, int $userId = 0): void
{
    if ($userId <= 0) {
        $userId = theme_prefs_viewer_user_id();
    }
    if ($userId <= 0) {
        return;
    }

    appearance_bridge_print_theme_stack($dbh, $userId, './', false);
}

function theme_prefs_is_named_palette(string $mode): bool
{
    $mode = appearance_palette_normalize_mode($mode);
    return !in_array($mode, ['system', 'light', 'dark'], true);
}

function theme_prefs_print_post_card_tail(PDO $dbh, int $userId = 0): void
{
    if ($userId <= 0) {
        $userId = theme_prefs_viewer_user_id();
    }
    if ($userId <= 0) {
        return;
    }

    $mode = theme_prefs_appearance_mode($dbh, $userId);
    if (!theme_prefs_is_named_palette($mode)) {
        return;
    }

    echo '<style id="msb-palette-post-card-tail">' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-head:not(.mf-head--on-media),' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-foot,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card > .mf-actions,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card.mf-card-video,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card.mf-card-reel,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card.mf-card-live,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .card-header,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-header,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actionbar,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-caption-block,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-header,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .actions,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-copy,' . "\n";
    echo 'html[data-msb-appearance] body .standard-text-card,' . "\n";
    echo 'html[data-msb-appearance] body .standard-text-topbar,' . "\n";
    echo 'html[data-msb-appearance] body.dark-auto .mf-card,' . "\n";
    echo 'html[data-msb-appearance] body.dark-auto .card-header,' . "\n";
    echo 'html[data-msb-appearance] html.dark-auto body .mf-card,' . "\n";
    echo 'html[data-msb-appearance] html.dark-auto body .card-header {' . "\n";
    echo '  background-color: var(--msb-palette-bg) !important;' . "\n";
    echo '  background-image: none !important;' . "\n";
    echo '  color: var(--msb-palette-text) !important;' . "\n";
    echo '  border-color: var(--msb-palette-border) !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-name,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-title,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-menu-btn,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-username,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-username a,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .head-meta .name,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-author-link,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .standard-media-bottom .standard-media-name,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .standard-text-name {' . "\n";
    echo '  color: var(--msb-palette-text) !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-time,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-time,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .head-meta .time,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .standard-media-bottom .standard-media-time {' . "\n";
    echo '  color: var(--msb-palette-text-muted) !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card > .mf-actions .fa,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-menu-btn,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-more,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .more-btn,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .standard-media-bottom .standard-media-more,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .action-btn i {' . "\n";
    echo '  color: var(--msb-palette-icon) !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-head:not(.mf-head--on-media) a:hover,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-head:not(.mf-head--on-media) .mf-peer-link:hover,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-header a:hover,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .card-header a:hover,' . "\n";
    echo 'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-header .ig-insta-username a:hover,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-header a:hover,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-author-link:hover,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .standard-text-topbar a:hover {' . "\n";
    echo '  background-color: transparent !important;' . "\n";
    echo '  background-image: none !important;' . "\n";
    echo '  color: var(--msb-palette-text) !important;' . "\n";
    echo '  border-color: transparent !important;' . "\n";
    echo '  box-shadow: none !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-head:not(.mf-head--on-media) a:hover .mf-name,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-head:not(.mf-head--on-media) a:hover .mf-time,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-header a:hover .name,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-author-link:hover .name {' . "\n";
    echo '  color: var(--msb-palette-text) !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card .mf-head:not(.mf-head--on-media) a:hover .mf-time,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card .post-header a:hover .time {' . "\n";
    echo '  color: var(--msb-palette-text-muted) !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-head--on-media,' . "\n";
    echo 'html[data-msb-appearance] body.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage,' . "\n";
    echo 'html[data-msb-appearance] body.news-page .post.public-post-card:has(.standard-media-topbar) .standard-media-topbar,' . "\n";
    echo 'html[data-msb-appearance] body.news-page .post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-bottom,' . "\n";
    echo 'html[data-msb-appearance] body.news-page .post.public-post-card[data-is-publisher="1"] .public-media-shell > .standard-media-bottom,' . "\n";
    echo 'html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell,' . "\n";
    echo 'html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,' . "\n";
    echo 'html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media,' . "\n";
    echo 'html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-head--on-media {' . "\n";
    echo '  background: transparent !important;' . "\n";
    echo '  background-color: transparent !important;' . "\n";
    echo '  background-image: none !important;' . "\n";
    echo '}' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-bottom,' . "\n";
    echo 'html[data-msb-appearance] body .post.public-post-card[data-is-publisher="1"] .public-media-shell > .standard-media-bottom,' . "\n";
    echo 'html[data-msb-appearance] body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .standard-media-bottom {' . "\n";
    echo '  background: none !important;' . "\n";
    echo '  background-color: transparent !important;' . "\n";
    echo '  background-image: none !important;' . "\n";
    echo '}' . "\n";
    echo '</style>' . "\n";
}
