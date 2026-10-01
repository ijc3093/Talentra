<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/includes/post_card_actions_menu.php';
post_card_actions_menu_render_css();
post_card_actions_menu_render_modals();
post_card_actions_menu_render_js([
    'api_url' => 'feed_api.php',
    'delete_mode' => 'feed',
    'menu_surface' => 'public',
]);
