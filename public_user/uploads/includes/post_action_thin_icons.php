<?php
declare(strict_types=1);

function post_action_thin_icon(string $kind, bool $active = false): string
{
    static $allowed = ['heart' => true, 'comment' => true, 'share' => true, 'bookmark' => true];
    $kind = strtolower(trim($kind));
    if (!isset($allowed[$kind])) {
        return '';
    }
    $activeClass = $active ? ' is-active' : '';

    return '<i class="msb-pact msb-pact-' . $kind . $activeClass . '" aria-hidden="true"></i>';
}

function post_action_thin_icons_render_css(): void
{
    if (defined('MSB_POST_ACTION_THIN_ICONS_CSS')) {
        return;
    }
    define('MSB_POST_ACTION_THIN_ICONS_CSS', true);
    echo '<style id="post-action-thin-icons-css">';
    include __DIR__ . '/post_action_thin_icons.css.php';
    echo '</style>';
}
