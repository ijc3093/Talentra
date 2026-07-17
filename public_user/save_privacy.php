<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/profile_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/appearance_palettes.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$userId = profile_session_owner_user_id();

if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not signed in']);
    exit;
}

profile_require_edit_access($dbh, $userId);

$field = trim((string)($_POST['field'] ?? ''));
$value = trim((string)($_POST['value'] ?? ''));

$allowed = [
    'profile_visibility' => ['type' => 'enum', 'values' => ['public','friends','only_me','approved_visitors']],
    'about_visibility' => ['type' => 'enum', 'values' => ['public','friends','only_me','approved_visitors']],
    'gallery_visibility' => ['type' => 'enum', 'values' => ['public','friends','only_me','approved_visitors']],
    'comment_permission' => ['type' => 'enum', 'values' => ['public','friends','only_me','approved_visitors']],
    'friend_request_permission' => ['type' => 'enum', 'values' => ['public','friends','only_me','approved_visitors']],
    'message_permission' => ['type' => 'enum', 'values' => ['public','friends','only_me','approved_visitors']],
    'timeline_visit_approval' => ['type' => 'bool'],

    'auto_show_timeline' => ['type' => 'bool'],
    'resurface_old_memories' => ['type' => 'bool'],
    'show_timeline_reactions' => ['type' => 'bool'],
    'show_timeline_comments' => ['type' => 'bool'],
    'archive_memory_enabled' => ['type' => 'bool'],
    'pin_memory_enabled' => ['type' => 'bool'],

    'email_notifications' => ['type' => 'bool'],
    'friend_request_notifications' => ['type' => 'bool'],
    'comment_notifications' => ['type' => 'bool'],
    'reaction_notifications' => ['type' => 'bool'],
    'share_notifications' => ['type' => 'bool'],


    'blocked_users_enabled' => ['type' => 'bool'],
    'hidden_users_enabled' => ['type' => 'bool'],
    'mute_users_enabled' => ['type' => 'bool'],
    'report_history_enabled' => ['type' => 'bool'],

    'appearance_mode' => ['type' => 'appearance_palette'],
    'theme_auto_enabled' => ['type' => 'bool'],
    'gallery_grid_size' => ['type' => 'enum', 'values' => ['small','medium','large']],
    'autoplay_videos' => ['type' => 'bool'],
    'sound_enabled' => ['type' => 'bool'],
    'app_language' => ['type' => 'enum', 'values' => ['English','French','Spanish','German','Portuguese','Arabic']],
    'date_format' => ['type' => 'enum', 'values' => ['F j, Y','m/d/Y','d/m/Y','Y-m-d','M j, Y']],
    'theme_color' => ['type' => 'enum', 'values' => ['indigo','blue','emerald','rose','amber']],


    'allow_download_data' => ['type' => 'bool'],
    'allow_deactivate_account' => ['type' => 'bool'],
    'allow_delete_account' => ['type' => 'bool'],
    'allow_logout_all_devices' => ['type' => 'bool'],
];

if (!isset($allowed[$field])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid field']);
    exit;
}

$rule = $allowed[$field];
if ($rule['type'] === 'bool') {
    $value = ($value === '1') ? '1' : '0';
} elseif ($rule['type'] === 'appearance_palette') {
    appearance_palette_ensure_schema($dbh);
    if ($value === 'system') {
        $value = 'system';
    } elseif (!appearance_palette_is_valid_slug($value)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid appearance color']);
        exit;
    }
    $value = appearance_palette_normalize_mode($value);
} elseif (!in_array($value, $rule['values'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid value']);
    exit;
}

try {
    appearance_palette_ensure_schema($dbh);

    $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
    $hasTable = (bool)($chk && $chk->fetchColumn());
    if (!$hasTable) {
        throw new RuntimeException('user_profile_settings table not found');
    }

    $ensure = $dbh->prepare("INSERT INTO user_profile_settings (user_id) VALUES (:uid) ON DUPLICATE KEY UPDATE user_id = user_id");
    $ensure->execute([':uid' => $userId]);

    $sql = "UPDATE user_profile_settings SET {$field} = :val WHERE user_id = :uid";
    $st = $dbh->prepare($sql);
    $st->execute([':val' => $value, ':uid' => $userId]);

    if ($st->rowCount() < 1 && $field === 'appearance_mode') {
        $verify = $dbh->prepare('SELECT appearance_mode FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $verify->execute([':uid' => $userId]);
        $saved = trim((string)($verify->fetchColumn() ?: ''));
        if ($saved !== $value) {
            throw new RuntimeException('Appearance setting was not saved for this account.');
        }
    }

    echo json_encode(['ok' => true, 'field' => $field, 'value' => $value, 'user_id' => $userId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
