<?php
declare(strict_types=1);

/**
 * Profile About / Gear / Favorites may only be changed by the session account owner.
 * Blocks staff, other users, and other publishers from modifying someone else's settings.
 */

require_once __DIR__ . '/staff_publisher_access.php';
require_once __DIR__ . '/publisher_accounts.php';
require_once __DIR__ . '/type_prefs.php';

function profile_session_owner_user_id(): int
{
    if (function_exists('publisher_session_canonical_user_id')) {
        return publisher_session_canonical_user_id();
    }

    return (int)($_SESSION['user_id'] ?? 0);
}

/** True when the profile being viewed belongs to the logged-in session owner. */
function profile_is_own_account(int $accountUserId): bool
{
    if ($accountUserId <= 0) {
        return false;
    }

    return profile_session_owner_user_id() === $accountUserId;
}

function profile_may_edit_account(PDO $dbh, int $accountUserId): bool
{
    if ($accountUserId <= 0) {
        return false;
    }

    if (!profile_is_own_account($accountUserId)) {
        return false;
    }

    if (staff_pub_is_staff_session()) {
        return false;
    }

    if (!empty($_SESSION['staff_publisher_mode']) || !empty($_SESSION['publisher_session_staff_id'])) {
        return false;
    }

    if (publisher_is_staff_workspace_session()) {
        return false;
    }

    if (publisher_is_publisher_user($dbh, $accountUserId)) {
        if (publisher_session_is_owner()) {
            return true;
        }

        $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($sessionUserId === $accountUserId) {
            try {
                publisher_session_bind_owner($dbh, $accountUserId);
            } catch (Throwable $e) {
                // fall through
            }
            return publisher_session_is_owner();
        }

        return false;
    }

    return true;
}

function profile_settings_ensure_tab_privacy_columns(PDO $dbh): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    try {
        $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
        if (!$chk || !$chk->fetchColumn()) {
            return;
        }
        $cols = [
            'show_tags_tab' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'show_about_tab' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'show_saved_tab' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'tagged_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'saved_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'birthday_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'followed_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'event_reminder_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'memory_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'inapp_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'push_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'email_digest_notifications' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mention_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'message_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'publisher_post_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'product_update_notifications' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'tips_notifications' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'gallery_grid_size' => "VARCHAR(16) NOT NULL DEFAULT 'medium'",
            'header_type_size' => "VARCHAR(16) NOT NULL DEFAULT 'small'",
            'header_font_family' => "VARCHAR(64) NOT NULL DEFAULT 'Arial'",
            'body_font_size_pt' => 'SMALLINT NOT NULL DEFAULT 9',
            'body_font_family' => "VARCHAR(64) NOT NULL DEFAULT 'Arial'",
            'text_color' => "VARCHAR(16) NOT NULL DEFAULT '#000000'",
            'autoplay_videos' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sound_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'post_visibility' => "VARCHAR(32) NOT NULL DEFAULT 'friends'",
            'story_visibility' => "VARCHAR(32) NOT NULL DEFAULT 'friends'",
            'reel_visibility' => "VARCHAR(32) NOT NULL DEFAULT 'friends'",
            'post_hide_from' => "TEXT NULL",
            'story_hide_from' => "TEXT NULL",
            'reel_hide_from' => "TEXT NULL",
        ];
        foreach ($cols as $name => $ddl) {
            $st = $dbh->query('SHOW COLUMNS FROM user_profile_settings LIKE ' . $dbh->quote($name));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }
            $dbh->exec('ALTER TABLE user_profile_settings ADD COLUMN `' . $name . '` ' . $ddl);
        }
        try {
            $dbh->exec("ALTER TABLE user_profile_settings MODIFY `header_type_size` VARCHAR(16) NOT NULL DEFAULT 'small'");
            $dbh->exec("ALTER TABLE user_profile_settings MODIFY `body_font_size_pt` SMALLINT NOT NULL DEFAULT 9");
            $dbh->exec("ALTER TABLE user_profile_settings MODIFY `text_color` VARCHAR(16) NOT NULL DEFAULT '#000000'");
            $dbh->exec(
                "UPDATE user_profile_settings
                 SET header_type_size = 'small', body_font_size_pt = 9, text_color = '#000000'
                 WHERE header_type_size = 'medium'
                   AND CAST(body_font_size_pt AS SIGNED) = 14
                   AND (text_color = 'theme' OR text_color = '' OR text_color IS NULL)
                   AND header_font_family = 'Arial'
                   AND body_font_family = 'Arial'"
            );
        } catch (Throwable $eTypeDefault) {
        }
        $enumCols = [
            'profile_visibility' => 'public',
            'about_visibility' => 'friends',
            'gallery_visibility' => 'friends',
            'comment_permission' => 'friends',
            'friend_request_permission' => 'public',
            'message_permission' => 'friends',
        ];
        foreach ($enumCols as $name => $default) {
            $st = $dbh->query('SHOW COLUMNS FROM user_profile_settings LIKE ' . $dbh->quote($name));
            $info = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $type = strtolower((string)($info['Type'] ?? ''));
            if ($type === '' || strpos($type, 'everyone') !== false) {
                continue;
            }
            if (strpos($type, 'enum') === 0) {
                $safeDefault = preg_replace('/[^a-z_]/', '', $default) ?: 'public';
                $dbh->exec(
                    'ALTER TABLE user_profile_settings MODIFY `' . $name . '` '
                    . "ENUM('everyone','public','friends','only_me','approved_visitors') NOT NULL DEFAULT '" . $safeDefault . "'"
                );
            }
        }
    } catch (Throwable $e) {
        // Keep defaults in PHP if the table cannot be altered.
    }
}

function profile_setting_is_on(array $settings, string $field, int $defaultOn = 1): bool
{
    if (!array_key_exists($field, $settings) || $settings[$field] === null || $settings[$field] === '') {
        return $defaultOn === 1;
    }
    return (int)$settings[$field] === 1;
}

function profile_notification_pref_fields(): array
{
    return [
        'tagged_notifications' => true,
        'saved_notifications' => true,
        'birthday_notifications' => true,
        'followed_notifications' => true,
        'event_reminder_notifications' => true,
        'memory_notifications' => true,
        'friend_request_notifications' => true,
        'comment_notifications' => true,
        'reaction_notifications' => true,
        'share_notifications' => true,
        'email_notifications' => true,
        'inapp_notifications' => true,
        'push_notifications' => true,
        'email_digest_notifications' => false,
        'mention_notifications' => true,
        'message_notifications' => true,
        'publisher_post_notifications' => true,
        'product_update_notifications' => false,
        'tips_notifications' => false,
    ];
}

function profile_user_wants_notification(PDO $dbh, int $userId, string $field): bool
{
    $allowed = profile_notification_pref_fields();
    if ($userId <= 0 || $field === '' || !array_key_exists($field, $allowed)) {
        return true;
    }
    $defaultOn = !empty($allowed[$field]);
    profile_settings_ensure_tab_privacy_columns($dbh);
    try {
        $st = $dbh->prepare('SELECT `' . str_replace('`', '', $field) . '` FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $val = $st->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return $defaultOn;
        }
        return (int)$val === 1;
    } catch (Throwable $e) {
        return $defaultOn;
    }
}

function profile_quiet_hours_values(): array
{
    return ['off', '22-07', '21-08', '23-06'];
}

function profile_user_in_quiet_hours(PDO $dbh, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    profile_settings_ensure_tab_privacy_columns($dbh);
    $window = 'off';
    try {
        $st = $dbh->prepare('SELECT quiet_hours FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $window = strtolower(trim((string)($st->fetchColumn() ?: 'off')));
    } catch (Throwable $e) {
        return false;
    }
    if ($window === '' || $window === 'off') {
        return false;
    }
    if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', $window, $m)) {
        return false;
    }
    $start = ((int)$m[1]) % 24;
    $end = ((int)$m[2]) % 24;
    $hour = (int)date('G');
    if ($start === $end) {
        return false;
    }
    if ($start < $end) {
        return $hour >= $start && $hour < $end;
    }
    return $hour >= $start || $hour < $end;
}

function profile_privacy_audience_values(): array
{
    return ['everyone', 'public', 'friends', 'only_me', 'approved_visitors'];
}

function profile_privacy_hide_people_decode($raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === '[]' || $raw === 'null') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $item) {
        if (is_string($item) || is_int($item)) {
            $username = trim((string)$item);
            $id = 0;
        } else {
            $username = trim((string)($item['username'] ?? ''));
            $id = (int)($item['id'] ?? 0);
        }
        $username = ltrim($username, '@');
        if ($username === '' && $id <= 0) {
            continue;
        }
        $key = $id > 0 ? ('id:' . $id) : ('u:' . strtolower($username));
        $out[$key] = ['id' => $id, 'username' => $username];
    }
    return array_values($out);
}

function profile_privacy_hide_people_encode(PDO $dbh, int $ownerId, $raw): string
{
    $people = is_array($raw) ? $raw : profile_privacy_hide_people_decode($raw);
    $clean = [];
    $seen = [];
    foreach ($people as $item) {
        $username = ltrim(trim((string)($item['username'] ?? $item)), '@');
        $id = (int)($item['id'] ?? 0);
        if ($username === '' && $id <= 0) {
            continue;
        }
        try {
            if ($id > 0) {
                $st = $dbh->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => $id]);
            } else {
                $st = $dbh->prepare('SELECT id, username FROM users WHERE LOWER(TRIM(username)) = LOWER(:u) LIMIT 1');
                $st->execute([':u' => $username]);
            }
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }
        $id = (int)($row['id'] ?? 0);
        $username = trim((string)($row['username'] ?? $username));
        if ($id <= 0 || $id === $ownerId || $username === '') {
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $clean[] = ['id' => $id, 'username' => $username];
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function profile_settings_row_forget(?int $userId = null): void
{
    if ($userId === null || $userId <= 0) {
        $GLOBALS['__msb_profile_settings_rows'] = [];
        return;
    }
    if (isset($GLOBALS['__msb_profile_settings_rows']) && is_array($GLOBALS['__msb_profile_settings_rows'])) {
        unset($GLOBALS['__msb_profile_settings_rows'][$userId]);
    }
}

function profile_settings_row(PDO $dbh, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    if (!isset($GLOBALS['__msb_profile_settings_rows']) || !is_array($GLOBALS['__msb_profile_settings_rows'])) {
        $GLOBALS['__msb_profile_settings_rows'] = [];
    }
    if (array_key_exists($userId, $GLOBALS['__msb_profile_settings_rows'])) {
        return $GLOBALS['__msb_profile_settings_rows'][$userId];
    }
    profile_settings_ensure_tab_privacy_columns($dbh);
    $row = [];
    try {
        $st = $dbh->prepare('SELECT * FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $fetched = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($fetched)) {
            $row = $fetched;
        }
    } catch (Throwable $e) {
        $row = [];
    }
    $GLOBALS['__msb_profile_settings_rows'][$userId] = $row;
    return $row;
}

function profile_setting_text(PDO $dbh, int $userId, string $field, string $default = ''): string
{
    $row = profile_settings_row($dbh, $userId);
    if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
        return $default;
    }
    return trim((string)$row[$field]);
}

function profile_setting_bool_for_user(PDO $dbh, int $userId, string $field, bool $defaultOn = true): bool
{
    $row = profile_settings_row($dbh, $userId);
    return profile_setting_is_on($row, $field, $defaultOn ? 1 : 0);
}

function profile_format_user_date(PDO $dbh, int $userId, int $timestamp, string $fallback = 'F j, Y'): string
{
    if ($timestamp <= 0) {
        return '';
    }
    $fmt = profile_setting_text($dbh, $userId, 'date_format', $fallback);
    $allowed = ['F j, Y', 'm/d/Y', 'd/m/Y', 'Y-m-d', 'M j, Y', 'F Y'];
    if (!in_array($fmt, $allowed, true)) {
        $fmt = $fallback;
    }
    return date($fmt, $timestamp);
}

function profile_viewer_is_approved(PDO $dbh, int $ownerId, int $viewerId): bool
{
    if ($ownerId <= 0 || $viewerId <= 0) {
        return false;
    }
    if ($ownerId === $viewerId) {
        return true;
    }
    try {
        $st = $dbh->prepare(
            'SELECT status FROM timeline_access_requests WHERE owner_user_id = :o AND requester_user_id = :r LIMIT 1'
        );
        $st->execute([':o' => $ownerId, ':r' => $viewerId]);
        return strtolower(trim((string)($st->fetchColumn() ?: ''))) === 'approved';
    } catch (Throwable $e) {
        return false;
    }
}

function profile_audience_allows_viewer(PDO $dbh, int $ownerId, int $viewerId, string $audience): bool
{
    if ($ownerId <= 0) {
        return false;
    }
    if ($viewerId === $ownerId) {
        return true;
    }
    $audience = strtolower(trim($audience));
    if ($audience === '' || $audience === 'everyone' || $audience === 'public') {
        return $viewerId > 0;
    }
    if ($audience === 'only_me' || $audience === 'private') {
        return false;
    }
    if ($audience === 'friends') {
        if ($viewerId <= 0) {
            return false;
        }
        if (!function_exists('fs_are_friends')) {
            require_once __DIR__ . '/friend_system.php';
        }
        return function_exists('fs_are_friends') && fs_are_friends($dbh, $ownerId, $viewerId);
    }
    if ($audience === 'approved_visitors') {
        return profile_viewer_is_approved($dbh, $ownerId, $viewerId);
    }
    return $viewerId > 0;
}

function profile_owner_allows_interaction(PDO $dbh, int $ownerId, int $viewerId, string $permissionField): bool
{
    if ($ownerId <= 0) {
        return false;
    }
    if ($viewerId === $ownerId) {
        return true;
    }
    $audience = profile_setting_text($dbh, $ownerId, $permissionField, 'friends');
    return profile_audience_allows_viewer($dbh, $ownerId, $viewerId, $audience);
}

function profile_content_hidden_from_viewer(PDO $dbh, int $ownerId, int $viewerId, string $kind): bool
{
    if ($ownerId <= 0 || $viewerId <= 0 || $ownerId === $viewerId) {
        return false;
    }
    $uname = '';
    try {
        $st = $dbh->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $viewerId]);
        $uname = trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $uname = '';
    }
    return profile_viewer_hidden_from_content(profile_settings_row($dbh, $ownerId), $viewerId, $uname, $kind);
}

function profile_notification_kind_from_text(string $type): string
{
    $t = strtolower(trim($type));
    if ($t === '') {
        return '';
    }
    if (strpos($t, 'new chat message') !== false || strpos($t, 'support message') !== false) {
        return 'message';
    }
    if (strpos($t, 'friend request') !== false || strpos($t, 'wants to connect') !== false) {
        return 'friend_request';
    }
    if (strpos($t, 'follow') !== false) {
        return 'follow';
    }
    if (strpos($t, 'mention') !== false) {
        return 'mention';
    }
    if (strpos($t, 'tag') !== false) {
        return 'tagged';
    }
    if (strpos($t, 'share') !== false || strpos($t, 'reposted') !== false) {
        return 'share';
    }
    if (strpos($t, 'favorit') !== false || strpos($t, 'saved') !== false) {
        return 'save';
    }
    if (strpos($t, 'comment') !== false || strpos($t, 'replied') !== false) {
        return 'comment';
    }
    if (preg_match('/\b(like|love|react|smile|laugh|clap|wow|sad|angry|dislike)\b/', $t)) {
        return 'reaction';
    }
    if (strpos($t, 'birthday') !== false) {
        return 'birthday';
    }
    if (strpos($t, 'event') !== false) {
        return 'event';
    }
    if (strpos($t, 'memory') !== false || strpos($t, 'on this day') !== false) {
        return 'memory';
    }
    if (strpos($t, 'what\'s up') !== false || strpos($t, 'publisher') !== false) {
        return 'publisher_post';
    }
    if (strpos($t, 'product update') !== false) {
        return 'product';
    }
    if (strpos($t, 'tip') !== false || strpos($t, 'recommend') !== false) {
        return 'tips';
    }
    return '';
}

function profile_notification_pref_for_kind(string $kind): string
{
    $map = [
        'comment' => 'comment_notifications',
        'reaction' => 'reaction_notifications',
        'share' => 'share_notifications',
        'save' => 'saved_notifications',
        'mention' => 'mention_notifications',
        'tagged' => 'tagged_notifications',
        'follow' => 'followed_notifications',
        'friend_request' => 'friend_request_notifications',
        'message' => 'message_notifications',
        'publisher_post' => 'publisher_post_notifications',
        'birthday' => 'birthday_notifications',
        'event' => 'event_reminder_notifications',
        'memory' => 'memory_notifications',
        'product' => 'product_update_notifications',
        'tips' => 'tips_notifications',
    ];
    return $map[$kind] ?? '';
}

function profile_notification_visible_for_user(PDO $dbh, int $userId, string $notitype): bool
{
    if ($userId <= 0) {
        return true;
    }
    if (!profile_user_wants_notification($dbh, $userId, 'inapp_notifications')) {
        return false;
    }
    $kind = profile_notification_kind_from_text($notitype);
    $pref = profile_notification_pref_for_kind($kind);
    if ($pref === '') {
        return true;
    }
    return profile_user_wants_notification($dbh, $userId, $pref);
}

function profile_filter_notification_rows(PDO $dbh, int $userId, array $rows): array
{
    if ($userId <= 0 || $rows === []) {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        $type = (string)($row['notitype'] ?? $row['text'] ?? '');
        if (profile_notification_visible_for_user($dbh, $userId, $type)) {
            $out[] = $row;
        }
    }
    return $out;
}

function profile_viewer_prefs_js(PDO $dbh, int $userId): array
{
    $row = profile_settings_row($dbh, $userId);
    return [
        'autoplay' => profile_setting_is_on($row, 'autoplay_videos', 1),
        'sound' => profile_setting_is_on($row, 'sound_enabled', 1),
        'galleryGrid' => profile_setting_text($dbh, $userId, 'gallery_grid_size', 'medium') ?: 'medium',
        'headerSize' => function_exists('msb_type_header_size_normalize')
            ? msb_type_header_size_normalize((string)($row['header_type_size'] ?? 'small'))
            : 'small',
        'headerFont' => function_exists('msb_type_font_normalize')
            ? msb_type_font_normalize((string)($row['header_font_family'] ?? 'Arial'))
            : 'Arial',
        'bodyPt' => function_exists('msb_type_body_pt_normalize')
            ? msb_type_body_pt_normalize($row['body_font_size_pt'] ?? 9)
            : 9,
        'bodyFont' => function_exists('msb_type_font_normalize')
            ? msb_type_font_normalize((string)($row['body_font_family'] ?? 'Arial'))
            : 'Arial',
        'textColor' => function_exists('msb_type_text_color_normalize')
            ? msb_type_text_color_normalize((string)($row['text_color'] ?? '#000000'))
            : '#ffffff',
        'inapp' => profile_setting_is_on($row, 'inapp_notifications', 1),
        'dateFormat' => profile_setting_text($dbh, $userId, 'date_format', 'F j, Y') ?: 'F j, Y',
    ];
}

function profile_viewer_prefs_print_js(PDO $dbh, int $userId): void
{
    if (!empty($GLOBALS['msb_viewer_prefs_js_printed'])) {
        return;
    }
    $GLOBALS['msb_viewer_prefs_js_printed'] = true;
    $prefs = profile_viewer_prefs_js($dbh, $userId);
    $json = json_encode($prefs, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }
    echo '<script>window.MSB_VIEWER_PREFS=' . $json . ';'
        . '(function(){var p=window.MSB_VIEWER_PREFS||{};var r=document.documentElement;'
        . 'r.setAttribute("data-msb-autoplay",p.autoplay?"1":"0");'
        . 'r.setAttribute("data-msb-sound",p.sound?"1":"0");'
        . 'r.setAttribute("data-msb-gallery-grid",String(p.galleryGrid||"medium"));'
        . 'function apply(v){if(!v||!v.tagName||v.tagName.toLowerCase()!=="video")return;'
        . 'if(!p.autoplay){try{v.removeAttribute("autoplay");v.pause();}catch(e){}}'
        . 'if(!p.sound){try{v.muted=true;v.defaultMuted=true;}catch(e){}}}'
        . 'function scan(root){(root.querySelectorAll?root.querySelectorAll("video"):[]).forEach(apply);if(root.tagName==="VIDEO")apply(root);}'
        . 'function boot(){scan(document);if(window.MutationObserver){new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(function(n){if(n.nodeType===1)scan(n);});});}).observe(document.documentElement,{childList:true,subtree:true});}}'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();})();</script>' . "\n";
}

function profile_viewer_hidden_from_content(array $settings, int $viewerId, string $viewerUsername, string $kind): bool
{
    if ($viewerId <= 0) {
        return false;
    }
    $map = [
        'post' => 'post_hide_from',
        'story' => 'story_hide_from',
        'reel' => 'reel_hide_from',
    ];
    $field = $map[$kind] ?? '';
    if ($field === '') {
        return false;
    }
    $viewerUsername = strtolower(ltrim(trim($viewerUsername), '@'));
    foreach (profile_privacy_hide_people_decode($settings[$field] ?? '') as $person) {
        if ((int)($person['id'] ?? 0) === $viewerId) {
            return true;
        }
        if ($viewerUsername !== '' && strtolower((string)($person['username'] ?? '')) === $viewerUsername) {
            return true;
        }
    }
    return false;
}

function profile_require_edit_access(PDO $dbh, int $accountUserId, bool $json = true): void
{
    if (profile_may_edit_account($dbh, $accountUserId)) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden_profile_edit',
            'message' => 'You can only change About, Gear, and Favorites on your own account.',
        ], JSON_UNESCAPED_SLASHES);
    } else {
        header('Location: profile.php?tab=posts');
    }
    exit;
}
