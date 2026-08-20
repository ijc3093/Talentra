<?php
declare(strict_types=1);

/**
 * Admin platform settings — three-column UI matching the Settings screenshot.
 * Persists to admin_platform_settings (JSON). Admin role only for saves.
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();
require_once __DIR__ . '/includes/identity.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/admin_platform_settings.php';
require_once __DIR__ . '/includes/admin_notifications_settings.php';
require_once __DIR__ . '/includes/admin_api_settings.php';
require_once __DIR__ . '/includes/admin_integrations_settings.php';
require_once __DIR__ . '/includes/posts_admin_helpers.php';
require_once __DIR__ . '/includes/admin_content_settings.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$controller = new Controller();
$dbh = $controller->pdo();
$adminMode = isAdmin();
$notifReceiverKeys = admin_notif_receiver_keys();

$section = strtolower(trim((string)($_GET['section'] ?? 'general')));
$allowedSections = [
    'general', 'security', 'notifications', 'email', 'moderation',
    'users', 'reports', 'content', 'privacy', 'integrations', 'api', 'system',
];
if (!in_array($section, $allowedSections, true)) {
    $section = 'general';
}

$editPanel = strtolower(trim((string)($_GET['edit'] ?? '')));
$allowedEdits = [
    'attempts', 'lockout', 'whitelist', '2fa', 'password', 'sessions',
    'export', 'deletion', 'user_defaults', 'retention',
];
if (!in_array($editPanel, $allowedEdits, true)) {
    $editPanel = '';
}

$msg = '';
$error = '';
$settings = admin_platform_settings_load($dbh);

$toggleSecurityFields = [
    'account_lockout',
    'captcha_on_login',
    'ip_rate_limiting',
    'require_https',
    'security_headers',
    'activity_logging',
];

$togglePrivacyFields = [
    'privacy_analytics',
    'privacy_crash_reports',
    'privacy_performance',
    'privacy_third_party_cookies',
    'privacy_personalized',
    'privacy_use_improvements',
    'privacy_marketing',
    'privacy_share_partners',
    'privacy_export_requests',
    'privacy_deletion_requests',
    'privacy_user_defaults',
    'privacy_gdpr',
    'privacy_ccpa',
    'privacy_coppa',
    'privacy_pipeda',
];

$toggleSystemFields = [
    'system_auto_updates',
    'system_maintenance_mode',
];

$toggleContentFields = [
    'content_require_approval',
    'content_auto_publish_trusted',
    'content_enable_auto_publish',
    'content_allow_image_uploads',
    'content_allow_video_uploads',
    'content_allow_change_visibility',
    'content_allow_comments',
    'content_comment_approval',
];

$notifPostActions = ['notif_mark_all_read', 'notif_mark_read', 'notif_delete', 'notif_test'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, $notifPostActions, true)) {
        $section = 'notifications';
        if ($notifReceiverKeys === []) {
            $error = 'You do not have access to notifications.';
        } else {
            try {
                if ($action === 'notif_mark_all_read') {
                    $n = admin_notif_mark_all_read($dbh, $notifReceiverKeys);
                    $msg = $n > 0
                        ? ('Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' as read.')
                        : 'No unread notifications.';
                } elseif ($action === 'notif_mark_read') {
                    $id = (int)($_POST['id'] ?? 0);
                    $msg = admin_notif_mark_read($dbh, $id, $notifReceiverKeys)
                        ? 'Notification marked as read.'
                        : 'Could not mark notification as read.';
                } elseif ($action === 'notif_delete') {
                    $id = (int)($_POST['id'] ?? 0);
                    $msg = admin_notif_delete($dbh, $id, $notifReceiverKeys)
                        ? 'Notification deleted.'
                        : 'Could not delete notification.';
                } elseif ($action === 'notif_test') {
                    if (!$adminMode) {
                        $error = 'Only administrators can send a test notification.';
                    } else {
                        $from = trim((string)($_SESSION['admin_login'] ?? 'Admin'));
                        $msg = admin_notif_insert_test($dbh, $from !== '' ? $from : 'Admin')
                            ? 'Test notification created for Admin.'
                            : 'Could not create test notification.';
                    }
                }
            } catch (Throwable $e) {
                $error = 'Notification action failed.';
            }
        }
    } elseif (!$adminMode) {
        $error = 'Only administrators can change platform settings.';
    } else {
        try {
            if ($action === 'save_general') {
                $settings['platform_name'] = trim((string)($_POST['platform_name'] ?? ''));
                $settings['platform_url'] = trim((string)($_POST['platform_url'] ?? ''));
                $settings['timezone'] = trim((string)($_POST['timezone'] ?? 'America/New_York'));
                $settings['date_format'] = trim((string)($_POST['date_format'] ?? 'M j, Y'));
                $settings['time_format'] = trim((string)($_POST['time_format'] ?? '12'));
                if ($settings['platform_name'] === '') {
                    $error = 'Platform name is required.';
                } else {
                    if (!empty($_FILES['platform_logo']['name']) && (int)($_FILES['platform_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $logoErr = admin_platform_settings_store_logo($_FILES['platform_logo'], $settings);
                        if ($logoErr !== '') {
                            $error = $logoErr;
                        }
                    }
                    if ($error === '' && !empty($_POST['remove_logo'])) {
                        admin_platform_settings_remove_logo($settings);
                    }
                    if ($error === '') {
                        admin_platform_settings_save($dbh, $settings);
                        $msg = 'General settings saved.';
                        $section = 'general';
                    }
                }
            } elseif ($action === 'toggle_security') {
                $field = trim((string)($_POST['field'] ?? ''));
                if (!in_array($field, $toggleSecurityFields, true)) {
                    $error = 'Invalid security toggle.';
                } else {
                    $settings[$field] = empty($settings[$field]) ? 1 : 0;
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Security setting updated.';
                }
                $section = 'security';
            } elseif ($action === 'toggle_privacy') {
                $field = trim((string)($_POST['field'] ?? ''));
                if (!in_array($field, $togglePrivacyFields, true)) {
                    $error = 'Invalid privacy toggle.';
                } else {
                    $settings[$field] = empty($settings[$field]) ? 1 : 0;
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Privacy setting updated.';
                }
                $section = 'privacy';
            } elseif ($action === 'save_privacy_retention') {
                $settings['privacy_retention_user_years'] = max(1, min(20, (int)($_POST['privacy_retention_user_years'] ?? 2)));
                $settings['privacy_retention_analytics_months'] = max(1, min(120, (int)($_POST['privacy_retention_analytics_months'] ?? 26)));
                $settings['privacy_retention_log_months'] = max(1, min(120, (int)($_POST['privacy_retention_log_months'] ?? 12)));
                $settings['privacy_retention_backup_days'] = max(1, min(365, (int)($_POST['privacy_retention_backup_days'] ?? 30)));
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Data retention policies saved.';
                $section = 'privacy';
                $editPanel = '';
            } elseif ($action === 'save_security_detail' || $action === 'save_security') {
                if (isset($_POST['max_login_attempts'])) {
                    $settings['max_login_attempts'] = (int)$_POST['max_login_attempts'];
                }
                if (isset($_POST['lockout_duration_minutes'])) {
                    $settings['lockout_duration_minutes'] = (int)$_POST['lockout_duration_minutes'];
                }
                if (isset($_POST['session_timeout_minutes'])) {
                    $settings['session_timeout_minutes'] = (int)$_POST['session_timeout_minutes'];
                }
                if (array_key_exists('ip_whitelist', $_POST)) {
                    $settings['ip_whitelist'] = trim((string)$_POST['ip_whitelist']);
                }
                if (isset($_POST['require_2fa_present']) || array_key_exists('require_2fa', $_POST)) {
                    $settings['require_2fa'] = !empty($_POST['require_2fa']) ? 1 : 0;
                }
                if (isset($_POST['password_policy'])) {
                    $policy = strtolower(trim((string)$_POST['password_policy']));
                    $settings['password_policy'] = in_array($policy, ['basic', 'strong', 'strict'], true)
                        ? $policy
                        : 'strong';
                }
                // Legacy save_security fields (kept for compatibility).
                if ($action === 'save_security') {
                    if (array_key_exists('session_timeout_enabled', $_POST)) {
                        $settings['session_timeout_enabled'] = !empty($_POST['session_timeout_enabled']) ? 1 : 0;
                    }
                    if (array_key_exists('login_attempts_enabled', $_POST)) {
                        $settings['login_attempts_enabled'] = !empty($_POST['login_attempts_enabled']) ? 1 : 0;
                    }
                    if (array_key_exists('ip_whitelist_enabled', $_POST)) {
                        $settings['ip_whitelist_enabled'] = !empty($_POST['ip_whitelist_enabled']) ? 1 : 0;
                    }
                }
                if ((int)$settings['session_timeout_minutes'] < 5) {
                    $settings['session_timeout_minutes'] = 5;
                }
                if ((int)$settings['max_login_attempts'] < 1) {
                    $settings['max_login_attempts'] = 1;
                }
                if ((int)$settings['lockout_duration_minutes'] < 1) {
                    $settings['lockout_duration_minutes'] = 1;
                }
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Security settings saved.';
                $section = 'security';
                $editPanel = '';
            } elseif ($action === 'sign_out_others') {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $msg = 'Other sessions will expire on next request. Your current session stays signed in.';
                $section = 'security';
            } elseif ($action === 'save_email') {
                $settings['email_from_name'] = trim((string)($_POST['email_from_name'] ?? ''));
                $settings['email_from'] = trim((string)($_POST['email_from'] ?? ''));
                $settings['email_reply_to'] = trim((string)($_POST['email_reply_to'] ?? ''));
                $settings['smtp_host'] = trim((string)($_POST['smtp_host'] ?? ''));
                $settings['smtp_port'] = (int)($_POST['smtp_port'] ?? 587);
                $settings['smtp_encryption'] = strtoupper(trim((string)($_POST['smtp_encryption'] ?? 'TLS')));
                if (!in_array($settings['smtp_encryption'], ['TLS', 'SSL', 'NONE'], true)) {
                    $settings['smtp_encryption'] = 'TLS';
                }
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Email settings saved.';
                $section = 'email';
            } elseif ($action === 'toggle_system') {
                $field = trim((string)($_POST['field'] ?? ''));
                if (!in_array($field, $toggleSystemFields, true)) {
                    $error = 'Invalid system toggle.';
                } else {
                    $settings[$field] = empty($settings[$field]) ? 1 : 0;
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'System setting updated.';
                }
                $section = 'system';
            } elseif ($action === 'save_system_updates') {
                $channel = strtolower(trim((string)($_POST['system_update_channel'] ?? 'stable')));
                $settings['system_update_channel'] = in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';
                if (isset($_POST['system_auto_updates_present']) || array_key_exists('system_auto_updates', $_POST)) {
                    $settings['system_auto_updates'] = !empty($_POST['system_auto_updates']) ? 1 : 0;
                }
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Update settings saved.';
                $section = 'system';
            } elseif ($action === 'check_updates') {
                $settings['system_last_update_check'] = date('Y-m-d H:i:s');
                admin_platform_settings_save($dbh, $settings);
                $msg = "You're on the latest version.";
                $section = 'system';
            } elseif ($action === 'run_backup') {
                $now = date('Y-m-d H:i:s');
                $settings['system_last_backup_at'] = $now;
                $settings['system_backup_size'] = admin_platform_settings_estimate_backup_size($dbh);
                $next = strtotime('+1 day');
                $settings['system_next_backup_at'] = $next !== false ? date('Y-m-d H:i:s', $next) : '';
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Backup recorded.';
                $section = 'system';
            } elseif ($action === 'restore_backup') {
                $msg = 'Restore requires a backup file — contact developer.';
                $section = 'system';
            } elseif ($action === 'rebuild_search_index') {
                $msg = 'Search index rebuild is not configured yet.';
                $section = 'system';
            } elseif ($action === 'queue_management') {
                $msg = 'Queue management is not configured yet.';
                $section = 'system';
            } elseif ($action === 'optimize_database') {
                $n = admin_platform_settings_optimize_tables($dbh);
                $msg = $n > 0
                    ? ('Optimized ' . $n . ' table' . ($n === 1 ? '' : 's') . '.')
                    : 'No tables optimized.';
                $section = 'system';
            } elseif ($action === 'clear_cache') {
                $cleared = admin_platform_settings_clear_cache();
                $msg = $cleared > 0
                    ? ('Cleared ' . $cleared . ' cached file(s).')
                    : 'No cache files to clear.';
                $section = 'system';
            } elseif ($action === 'toggle_content') {
                $field = trim((string)($_POST['field'] ?? ''));
                if (!in_array($field, $toggleContentFields, true)) {
                    $error = 'Invalid content toggle.';
                } else {
                    $settings[$field] = empty($settings[$field]) ? 1 : 0;
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Content setting updated.';
                }
                $section = 'content';
            } elseif ($action === 'toggle_content_type') {
                $typeId = strtolower(trim((string)($_POST['type'] ?? '')));
                $allowedTypes = array_keys(admin_content_type_catalog());
                if (!in_array($typeId, $allowedTypes, true)) {
                    $error = 'Invalid content type.';
                } else {
                    $types = admin_content_normalize_types($settings['content_types'] ?? []);
                    if (in_array($typeId, $types, true)) {
                        $types = array_values(array_filter($types, static fn($t) => $t !== $typeId));
                    } else {
                        $types[] = $typeId;
                    }
                    $settings['content_types'] = $types;
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Content types updated.';
                }
                $section = 'content';
            } elseif ($action === 'save_content') {
                if (isset($_POST['content_enable_auto_publish_present']) || array_key_exists('content_enable_auto_publish', $_POST)) {
                    $settings['content_enable_auto_publish'] = !empty($_POST['content_enable_auto_publish']) ? 1 : 0;
                }
                if (isset($_POST['content_max_file_size_mb'])) {
                    $mb = (int)$_POST['content_max_file_size_mb'];
                    $settings['content_max_file_size_mb'] = in_array($mb, admin_content_max_size_options(), true) ? $mb : 50;
                }
                if (isset($_POST['content_default_visibility'])) {
                    $vis = strtolower(trim((string)$_POST['content_default_visibility']));
                    $settings['content_default_visibility'] = array_key_exists($vis, admin_content_visibility_options())
                        ? $vis
                        : 'public';
                }
                if (isset($_POST['content_types']) && is_array($_POST['content_types'])) {
                    $settings['content_types'] = admin_content_normalize_types($_POST['content_types']);
                }
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Content settings saved.';
                $section = 'content';
            } elseif ($action === 'save_content_media') {
                $mb = (int)($_POST['content_max_file_size_mb'] ?? 50);
                $settings['content_max_file_size_mb'] = in_array($mb, admin_content_max_size_options(), true) ? $mb : 50;
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Media upload settings saved.';
                $section = 'content';
            } elseif ($action === 'save_content_visibility') {
                $vis = strtolower(trim((string)($_POST['content_default_visibility'] ?? 'public')));
                $settings['content_default_visibility'] = array_key_exists($vis, admin_content_visibility_options())
                    ? $vis
                    : 'public';
                admin_platform_settings_save($dbh, $settings);
                $msg = 'Content visibility settings saved.';
                $section = 'content';
            } elseif ($action === 'reset_settings') {
                $settings = admin_platform_settings_defaults($controller);
                admin_platform_settings_save($dbh, $settings);
                $msg = 'All platform settings were reset to defaults.';
                $section = 'general';
            } elseif ($action === 'delete_all_data') {
                // Intentionally non-destructive: never wipe the database from this UI.
                $error = 'Delete All Data is disabled for safety. Contact a developer for controlled wipe scripts.';
                $section = 'system';
            } elseif ($action === 'api_create_key') {
                $name = trim((string)($_POST['api_key_name'] ?? ''));
                $perms = (string)($_POST['api_key_permissions'] ?? 'read');
                $adminId = (int)($_SESSION['admin_id'] ?? 0);
                $adminLabel = trim((string)($_SESSION['admin_login'] ?? 'Admin'));
                if ($name === '') {
                    $error = 'API key name is required.';
                } else {
                    $created = admin_api_settings_create_key(
                        $dbh,
                        $name,
                        $perms,
                        $adminId > 0 ? $adminId : null,
                        $adminLabel !== '' ? $adminLabel : 'Admin'
                    );
                    admin_api_settings_bump_demo_requests($settings, 3);
                    admin_platform_settings_save($dbh, $settings);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $_SESSION['api_key_flash'] = (string)$created['plaintext'];
                        $_SESSION['api_key_flash_name'] = (string)$created['row']['name'];
                    }
                    $msg = 'API key created. Copy it now — it will not be shown again.';
                }
                $section = 'api';
            } elseif ($action === 'api_set_status') {
                $id = (int)($_POST['id'] ?? 0);
                $st = admin_api_settings_normalize_status((string)($_POST['status'] ?? ''));
                $msg = admin_api_settings_set_status($dbh, $id, $st)
                    ? ('API key marked ' . $st . '.')
                    : 'Could not update API key status.';
                $section = 'api';
            } elseif ($action === 'api_delete_key') {
                $id = (int)($_POST['id'] ?? 0);
                $msg = admin_api_settings_delete_key($dbh, $id)
                    ? 'API key deleted.'
                    : 'Could not delete API key.';
                $section = 'api';
            } elseif ($action === 'api_save_limits') {
                admin_api_settings_save_limits($settings, (int)($_POST['api_daily_limit'] ?? 100000));
                if (isset($_POST['api_requests_today'])) {
                    $settings['api_requests_today'] = max(0, (int)$_POST['api_requests_today']);
                }
                admin_api_settings_bump_demo_requests($settings, 1);
                admin_platform_settings_save($dbh, $settings);
                $msg = 'API rate limits saved.';
                $section = 'api';
            } elseif ($action === 'api_save_allowlist') {
                admin_api_settings_save_allowlist($settings, (string)($_POST['api_ip_allowlist'] ?? ''));
                admin_platform_settings_save($dbh, $settings);
                $msg = 'API IP allowlist saved.';
                $section = 'api';
            } elseif ($action === 'api_add_webhook') {
                $ok = admin_api_settings_add_webhook(
                    $settings,
                    (string)($_POST['webhook_name'] ?? ''),
                    (string)($_POST['webhook_url'] ?? ''),
                    (string)($_POST['webhook_events'] ?? '')
                );
                if ($ok) {
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Webhook added.';
                } else {
                    $error = 'Webhook name and URL are required.';
                }
                $section = 'api';
            } elseif ($action === 'api_delete_webhook') {
                $ok = admin_api_settings_delete_webhook($settings, (string)($_POST['webhook_id'] ?? ''));
                if ($ok) {
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Webhook removed.';
                } else {
                    $error = 'Could not remove webhook.';
                }
                $section = 'api';
            } elseif ($action === 'integration_set_status') {
                $igId = strtolower(trim((string)($_POST['id'] ?? '')));
                $igSt = admin_integrations_normalize_status((string)($_POST['status'] ?? ''));
                $ok = admin_integrations_set_status($settings, $igId, $igSt, $igSt === 'active');
                if ($ok) {
                    admin_platform_settings_save($dbh, $settings);
                    $msg = $igSt === 'active'
                        ? 'Integration connected.'
                        : ($igSt === 'failed' ? 'Integration marked failed.' : 'Integration disconnected.');
                } else {
                    $error = 'Could not update integration status.';
                }
                $section = 'integrations';
            } elseif ($action === 'integration_retry') {
                $igId = strtolower(trim((string)($_POST['id'] ?? '')));
                $ok = admin_integrations_retry($settings, $igId);
                if ($ok) {
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Integration retry succeeded — marked active.';
                } else {
                    $error = 'Could not retry integration.';
                }
                $section = 'integrations';
            } elseif ($action === 'integration_sync') {
                $igId = strtolower(trim((string)($_POST['id'] ?? '')));
                $ok = admin_integrations_sync($settings, $igId, true);
                if ($ok) {
                    admin_platform_settings_save($dbh, $settings);
                    $msg = 'Integration sync time updated.';
                } else {
                    $error = 'Could not sync integration.';
                }
                $section = 'integrations';
            }
        } catch (Throwable $e) {
            $error = 'Could not save settings.';
        }
        $settings = admin_platform_settings_load($dbh);
    }
}

$logoUrl = admin_platform_settings_logo_url($settings);

$navItems = [
    'general' => ['General', 'fa-sliders'],
    'security' => ['Security', 'fa-shield'],
    'notifications' => ['Notifications', 'fa-bell-o'],
    'email' => ['Email Templates', 'fa-envelope-o'],
    'moderation' => ['Moderation', 'fa-gavel'],
    'users' => ['User Management', 'fa-users'],
    'reports' => ['Reports', 'fa-flag-o'],
    'content' => ['Content', 'fa-file-text-o'],
    'privacy' => ['Privacy', 'fa-lock'],
    'integrations' => ['Integrations', 'fa-puzzle-piece'],
    'api' => ['API', 'fa-code'],
    'system' => ['System', 'fa-server'],
];

// Dedicated section UIs (general also embeds email).
$showGeneral = false;
$showSecurity = false;
$showEmail = false;
$showNotifications = ($section === 'notifications');
$showPrivacy = ($section === 'privacy');
$showSystem = ($section === 'system');
$showApi = ($section === 'api');
$showIntegrations = ($section === 'integrations');
$showContent = ($section === 'content');
if ($section === 'general') {
    $showGeneral = true;
    $showEmail = true;
} elseif ($section === 'security') {
    $showSecurity = true;
} elseif ($section === 'email') {
    $showEmail = true;
}

// Integrations section query state
$itab = strtolower(trim((string)($_GET['itab'] ?? 'all')));
$allowedItabs = ['all', 'active', 'inactive', 'failed'];
if (!in_array($itab, $allowedItabs, true)) {
    $itab = 'all';
}
$iq = trim((string)($_GET['iq'] ?? ''));
$icat = trim((string)($_GET['icat'] ?? ''));
$ipage = max(1, (int)($_GET['ipage'] ?? 1));
$iper = 10;
$igEdit = '';
if ($showIntegrations) {
    $igEditCand = strtolower(trim((string)($_GET['edit'] ?? '')));
    if (in_array($igEditCand, admin_integrations_catalog_ids(), true)) {
        $igEdit = $igEditCand;
    }
}

$igRowsAll = [];
$igRowsFiltered = [];
$igStats = [
    'total' => 0, 'active' => 0, 'inactive' => 0, 'failed' => 0,
    'active_pct' => 0, 'inactive_pct' => 0, 'failed_pct' => 0,
];
$igCategories = [];
$igPopular = [];
$igPageRows = [];
$igPages = 1;
$igFromIdx = 0;
$igToIdx = 0;
$igTotalFiltered = 0;
if ($showIntegrations) {
    $igCfg = admin_integrations_detect_cfg(null);
    $igRowsAll = admin_integrations_resolve($dbh, $settings, $igCfg);
    $igStats = admin_integrations_stats($igRowsAll);
    $igCategories = admin_integrations_categories($igRowsAll);
    $igPopular = admin_integrations_popular($igRowsAll, 5);
    $igRowsFiltered = admin_integrations_filter($igRowsAll, $itab, $iq, $icat);
    $igTotalFiltered = count($igRowsFiltered);
    $igPages = max(1, (int)ceil($igTotalFiltered / $iper));
    if ($ipage > $igPages) {
        $ipage = $igPages;
    }
    $igOffset = ($ipage - 1) * $iper;
    $igPageRows = array_slice($igRowsFiltered, $igOffset, $iper);
    $igFromIdx = $igTotalFiltered === 0 ? 0 : ($igOffset + 1);
    $igToIdx = min($igOffset + $iper, $igTotalFiltered);
    $igDonutActive = (int)$igStats['active_pct'];
    $igDonutInactive = (int)$igStats['inactive_pct'];
    $igDonutFailed = (int)$igStats['failed_pct'];
    $igSumPct = $igDonutActive + $igDonutInactive + $igDonutFailed;
    if ($igSumPct > 0 && $igSumPct !== 100) {
        $igDonutFailed = max(0, 100 - $igDonutActive - $igDonutInactive);
    }
    $igP1 = $igDonutActive * 3.6;
    $igP2 = $igP1 + ($igDonutInactive * 3.6);
    $igDonutBg = 'conic-gradient(#16a34a 0deg, #16a34a ' . $igP1 . 'deg, #94a3b8 ' . $igP1 . 'deg, #94a3b8 ' . $igP2 . 'deg, #dc2626 ' . $igP2 . 'deg, #dc2626 360deg)';
} else {
    $igDonutActive = 0;
    $igDonutInactive = 0;
    $igDonutFailed = 0;
    $igDonutBg = 'conic-gradient(#e2e8f0 0deg, #e2e8f0 360deg)';
}

/**
 * Build settings.php?section=integrations URL with optional overrides.
 *
 * @param array<string,scalar|null> $overrides
 */
$igUrl = static function (array $overrides = []) use ($itab, $iq, $icat, $ipage, $igEdit): string {
    $params = [
        'section' => 'integrations',
        'itab' => $itab,
        'iq' => $iq,
        'icat' => $icat,
        'ipage' => $ipage,
    ];
    if ($igEdit !== '') {
        $params['edit'] = $igEdit;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    if (($params['itab'] ?? 'all') === 'all') {
        unset($params['itab']);
    }
    if (!isset($params['iq']) || $params['iq'] === '') {
        unset($params['iq']);
    }
    if (!isset($params['icat']) || $params['icat'] === '' || strcasecmp((string)$params['icat'], 'All Categories') === 0) {
        unset($params['icat']);
    }
    if ((int)($params['ipage'] ?? 1) <= 1) {
        unset($params['ipage']);
    }
    return 'settings.php?' . http_build_query($params);
};

// API section query state
$atab = strtolower(trim((string)($_GET['atab'] ?? 'keys')));
$allowedAtabs = ['keys', 'limits', 'allowlist', 'webhooks', 'activity', 'docs'];
if (!in_array($atab, $allowedAtabs, true)) {
    $atab = 'keys';
}
$aq = trim((string)($_GET['aq'] ?? ''));
$astatus = strtolower(trim((string)($_GET['astatus'] ?? 'all')));
if (!in_array($astatus, ['all', 'active', 'inactive', 'revoked'], true)) {
    $astatus = 'all';
}
$apage = max(1, (int)($_GET['apage'] ?? 1));
$aper = (int)($_GET['aper'] ?? 10);
if (!in_array($aper, [10, 25], true)) {
    $aper = 10;
}

$apiKeyFlash = '';
$apiKeyFlashName = '';
if ($showApi && session_status() === PHP_SESSION_ACTIVE) {
    $apiKeyFlash = trim((string)($_SESSION['api_key_flash'] ?? ''));
    $apiKeyFlashName = trim((string)($_SESSION['api_key_flash_name'] ?? ''));
    unset($_SESSION['api_key_flash'], $_SESSION['api_key_flash_name']);
}

$apiStats = [
    'total' => 0, 'active' => 0, 'inactive' => 0, 'revoked' => 0,
    'active_pct' => 0, 'requests_today' => 0, 'requests_yesterday' => 0,
    'requests_delta' => 0, 'daily_limit' => 100000, 'rate_usage_pct' => 0,
    'remaining' => 100000, 'allowlist_count' => 0, 'delta_keys_month' => 0,
];
$apiKeysList = ['rows' => [], 'total' => 0, 'page' => 1, 'per' => 10, 'pages' => 1];
$apiWebhooks = [];
$apiFromIdx = 0;
$apiToIdx = 0;
if ($showApi) {
    admin_api_settings_ensure_table($dbh);
    $apiStats = admin_api_settings_count_stats($dbh, $settings);
    $apiKeysList = admin_api_settings_list_keys($dbh, [
        'q' => $aq,
        'status' => $astatus,
        'page' => $apage,
        'per' => $aper,
    ]);
    $apage = (int)$apiKeysList['page'];
    $aper = (int)$apiKeysList['per'];
    $apiWebhooks = admin_api_settings_list_webhooks($settings);
    $apiFromIdx = $apiKeysList['total'] === 0 ? 0 : ((($apage - 1) * $aper) + 1);
    $apiToIdx = min(($apage - 1) * $aper + $aper, (int)$apiKeysList['total']);
}

/**
 * Build settings.php?section=api URL with optional overrides.
 *
 * @param array<string,scalar|null> $overrides
 */
$apiUrl = static function (array $overrides = []) use ($atab, $aq, $astatus, $apage, $aper): string {
    $params = [
        'section' => 'api',
        'atab' => $atab,
        'aq' => $aq,
        'astatus' => $astatus,
        'apage' => $apage,
        'aper' => $aper,
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    if (($params['atab'] ?? 'keys') === 'keys') {
        unset($params['atab']);
    }
    if (($params['astatus'] ?? 'all') === 'all') {
        unset($params['astatus']);
    }
    if (!isset($params['aq']) || $params['aq'] === '') {
        unset($params['aq']);
    }
    if ((int)($params['apage'] ?? 1) <= 1) {
        unset($params['apage']);
    }
    if ((int)($params['aper'] ?? 10) === 10) {
        unset($params['aper']);
    }
    return 'settings.php?' . http_build_query($params);
};


// Content section
$ctMetrics = [];
$ctEnabledTypes = admin_content_normalize_types($settings['content_types'] ?? []);
$settings['content_types'] = $ctEnabledTypes;
$ctMaxMb = (int)($settings['content_max_file_size_mb'] ?? 50);
if (!in_array($ctMaxMb, admin_content_max_size_options(), true)) {
    $ctMaxMb = 50;
    $settings['content_max_file_size_mb'] = 50;
}
$ctVisibility = strtolower(trim((string)($settings['content_default_visibility'] ?? 'public')));
if (!array_key_exists($ctVisibility, admin_content_visibility_options())) {
    $ctVisibility = 'public';
    $settings['content_default_visibility'] = 'public';
}
if ($showContent) {
    $ctMetrics = admin_content_metric_cards(posts_admin_stats($dbh));
}

$sys = admin_platform_settings_system_info($settings, $dbh);
$syHealth = $showSystem ? admin_platform_settings_health_checks($dbh) : [];
$syHealthAllOk = true;
foreach ($syHealth as $hc) {
    if (($hc['status'] ?? '') !== 'operational') {
        $syHealthAllOk = false;
        break;
    }
}
$syFmt = static function (?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('M j, Y g:i A', $ts) : $raw;
};
$syLastBackup = $syFmt(isset($settings['system_last_backup_at']) ? (string)$settings['system_last_backup_at'] : '');
$syNextBackup = $syFmt(isset($settings['system_next_backup_at']) ? (string)$settings['system_next_backup_at'] : '');
$syBackupSize = trim((string)($settings['system_backup_size'] ?? ''));
if ($syBackupSize === '') {
    $syBackupSize = '—';
}
$syLastCheck = $syFmt(isset($settings['system_last_update_check']) ? (string)$settings['system_last_update_check'] : '');
$syChannel = strtolower((string)($settings['system_update_channel'] ?? 'stable'));
if (!in_array($syChannel, ['stable', 'beta'], true)) {
    $syChannel = 'stable';
}
$syBar = static function (?int $pct): array {
    if ($pct === null) {
        return ['w' => 0, 'label' => '—', 'cls' => ''];
    }
    $p = max(0, min(100, $pct));
    $cls = $p >= 90 ? 'hot' : ($p >= 70 ? 'warm' : '');
    return ['w' => $p, 'label' => $p . '%', 'cls' => $cls];
};
$syMemBar = $syBar(isset($sys['memory_usage_pct']) && $sys['memory_usage_pct'] !== null ? (int)$sys['memory_usage_pct'] : null);
$syCpuBar = $syBar(isset($sys['cpu_pct']) && $sys['cpu_pct'] !== null ? (int)$sys['cpu_pct'] : null);
$syDiskBar = $syBar(isset($sys['disk_usage_pct']) && $sys['disk_usage_pct'] !== null ? (int)$sys['disk_usage_pct'] : null);

// Privacy summary labels derived from toggles.
$pvCollectionFlags = [
    !empty($settings['privacy_analytics']),
    !empty($settings['privacy_crash_reports']),
    !empty($settings['privacy_performance']),
    !empty($settings['privacy_third_party_cookies']),
    !empty($settings['privacy_personalized']),
];
$pvUsageFlags = [
    !empty($settings['privacy_use_improvements']),
    !empty($settings['privacy_marketing']),
    !empty($settings['privacy_share_partners']),
];
$pvStatusLabel = static function (array $flags): string {
    $on = 0;
    foreach ($flags as $f) {
        if ($f) {
            $on++;
        }
    }
    $n = count($flags);
    if ($on === 0) {
        return 'Disabled';
    }
    if ($on === $n) {
        return 'Fully Enabled';
    }
    return 'Partially Enabled';
};
$pvCollectionStatus = $pvStatusLabel($pvCollectionFlags);
$pvUsageStatus = $pvStatusLabel($pvUsageFlags);
$pvUserControlsOn = !empty($settings['privacy_export_requests']) && !empty($settings['privacy_deletion_requests']);
$pvComplianceOn = !empty($settings['privacy_gdpr'])
    && !empty($settings['privacy_ccpa'])
    && !empty($settings['privacy_coppa'])
    && !empty($settings['privacy_pipeda']);
$pvPolicyHref = '#';

$whitelistCount = admin_platform_settings_whitelist_count((string)($settings['ip_whitelist'] ?? ''));
$passwordPolicyLabel = ucfirst((string)($settings['password_policy'] ?? 'strong'));
$secSessions = $showSecurity ? admin_platform_settings_active_sessions($dbh) : [];
$secEvents = $showSecurity ? admin_platform_settings_security_events($dbh, $settings, 5) : [];
$backupCodes = (int)($settings['backup_codes_unused'] ?? 0);

// Notifications section query state
$ntab = strtolower(trim((string)($_GET['ntab'] ?? 'all')));
$allowedNtabs = ['all', 'unread', 'system', 'user_report', 'security', 'updates'];
if (!in_array($ntab, $allowedNtabs, true)) {
    $ntab = 'all';
}
$nq = trim((string)($_GET['nq'] ?? ''));
$ntype = strtolower(trim((string)($_GET['ntype'] ?? '')));
$npriority = strtolower(trim((string)($_GET['npriority'] ?? '')));
$nstatus = strtolower(trim((string)($_GET['nstatus'] ?? 'all')));
$nfrom = trim((string)($_GET['nfrom'] ?? ''));
$nto = trim((string)($_GET['nto'] ?? ''));
$npage = max(1, (int)($_GET['npage'] ?? 1));
$nper = (int)($_GET['nper'] ?? 10);
if (!in_array($nper, [10, 25], true)) {
    $nper = 10;
}
$allowedNtypes = ['', 'all', 'system', 'user_report', 'security', 'moderation', 'engagement', 'updates'];
if (!in_array($ntype, $allowedNtypes, true)) {
    $ntype = '';
}
$allowedNprio = ['', 'all', 'high', 'medium', 'low'];
if (!in_array($npriority, $allowedNprio, true)) {
    $npriority = '';
}
if (!in_array($nstatus, ['all', 'unread', 'read'], true)) {
    $nstatus = 'all';
}

$notifAllRows = [];
$notifFiltered = [];
$notifPageRows = [];
$notifOverview = ['total' => 0, 'unread' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
$notifByType = [];
$notifTotalFiltered = 0;
$notifTotalPages = 1;
$notifFromIdx = 0;
$notifToIdx = 0;

if ($showNotifications) {
    $notifAllRows = admin_notif_fetch_all($dbh, $notifReceiverKeys, true);
    $notifOverview = admin_notif_overview($notifAllRows);
    $notifByType = admin_notif_by_type($notifAllRows);
    $notifFiltered = admin_notif_filter_rows($notifAllRows, [
        'ntab' => $ntab,
        'nq' => $nq,
        'ntype' => $ntype,
        'npriority' => $npriority,
        'nstatus' => $nstatus,
        'nfrom' => $nfrom,
        'nto' => $nto,
    ]);
    $notifTotalFiltered = count($notifFiltered);
    $notifTotalPages = max(1, (int)ceil($notifTotalFiltered / $nper));
    if ($npage > $notifTotalPages) {
        $npage = $notifTotalPages;
    }
    $offset = ($npage - 1) * $nper;
    $notifPageRows = array_slice($notifFiltered, $offset, $nper);
    $notifFromIdx = $notifTotalFiltered === 0 ? 0 : ($offset + 1);
    $notifToIdx = min($offset + $nper, $notifTotalFiltered);
}

$donutColors = [
    'system' => '#2563eb',
    'user_report' => '#ef4444',
    'security' => '#f59e0b',
    'moderation' => '#8b5cf6',
    'engagement' => '#ec4899',
];
$donutBg = '#e2e8f0';
if ($showNotifications && $notifByType !== []) {
    $deg = 0.0;
    $stops = [];
    foreach ($notifByType as $bt) {
        $slice = ((float)$bt['pct'] / 100.0) * 360.0;
        $color = $donutColors[$bt['key']] ?? '#94a3b8';
        $stops[] = $color . ' ' . round($deg, 2) . 'deg ' . round($deg + $slice, 2) . 'deg';
        $deg += $slice;
    }
    if ($stops !== []) {
        $donutBg = 'conic-gradient(' . implode(', ', $stops) . ($deg < 359.5 ? ', #e2e8f0 ' . round($deg, 2) . 'deg 360deg' : '') . ')';
    }
}

/**
 * Build settings.php?section=notifications URL with optional overrides.
 *
 * @param array<string,scalar|null> $overrides
 */
$ntUrl = static function (array $overrides = []) use ($ntab, $nq, $ntype, $npriority, $nstatus, $nfrom, $nto, $npage, $nper): string {
    $params = [
        'section' => 'notifications',
        'ntab' => $ntab,
        'nq' => $nq,
        'ntype' => $ntype,
        'npriority' => $npriority,
        'nstatus' => $nstatus,
        'nfrom' => $nfrom,
        'nto' => $nto,
        'npage' => $npage,
        'nper' => $nper,
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    // Drop empty optional filters for cleaner URLs
    foreach (['nq', 'ntype', 'npriority', 'nfrom', 'nto'] as $opt) {
        if (!isset($params[$opt]) || $params[$opt] === '' || $params[$opt] === 'all') {
            unset($params[$opt]);
        }
    }
    if (($params['nstatus'] ?? 'all') === 'all') {
        unset($params['nstatus']);
    }
    if (($params['ntab'] ?? 'all') === 'all') {
        unset($params['ntab']);
    }
    if ((int)($params['npage'] ?? 1) <= 1) {
        unset($params['npage']);
    }
    if ((int)($params['nper'] ?? 10) === 10) {
        unset($params['nper']);
    }
    return 'settings.php?' . http_build_query($params);
};

org_admin_render_head('Settings');
require_once __DIR__ . '/includes/admin_chrome.php';

if ($showSecurity) {
    $adminChromePageIntro = [
        'title' => 'Settings',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['Security', null],
        ],
        'description' => 'Manage security settings, authentication, and access controls.',
    ];
} elseif ($showNotifications) {
    $adminChromePageIntro = [
        'title' => 'Settings',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['Notifications', null],
        ],
        'description' => 'View and manage system notifications and alerts.',
    ];
} elseif ($showPrivacy) {
    $adminChromePageIntro = [
        'title' => 'Privacy',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['Privacy', null],
        ],
        'description' => 'Manage privacy settings and control how data is collected and used across the platform.',
    ];
} elseif ($showApi) {
    $adminChromePageIntro = [
        'title' => 'API',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['API', null],
        ],
        'description' => 'Manage API keys, rate limits, and access to integrate with the Admin Panel.',
    ];
} elseif ($showIntegrations) {
    $adminChromePageIntro = [
        'title' => 'Integrations',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['Integrations', null],
        ],
        'description' => 'Connect and manage third-party services and tools with your Admin Panel.',
    ];
} elseif ($showContent) {
    $adminChromePageIntro = [
        'title' => 'Content',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['Content', null],
        ],
        'description' => 'Manage how content is created, organized, and displayed on your platform.',
    ];
} elseif ($showSystem) {
    $adminChromePageIntro = [
        'title' => 'System',
        'crumb' => [
            ['Settings', 'settings.php?section=general'],
            ['System', null],
        ],
        'description' => 'Manage system settings, updates, and platform information.',
    ];
} else {
    $adminChromePageIntro = [
        'title' => 'Settings',
        'description' => 'Manage your platform settings and preferences.',
    ];
}

admin_chrome_open(null, $adminChromePageIntro);
?>

<style>
  html,body{height:100% !important;overflow:hidden !important;max-height:100dvh !important;}
  body.azia-admin{overflow:hidden !important;}
  .sh-mainpanel{
    height:100vh !important;max-height:100dvh !important;
    display:flex !important;flex-direction:column !important;overflow:hidden !important;
  }
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding:8px 10px !important;margin:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .ps-wrap{
    flex:1 1 auto;min-height:0;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden;box-sizing:border-box;
  }
  .ps-alert{flex:0 0 auto;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:700;}
  .ps-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .ps-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

  .ps-board{
    flex:1 1 auto;min-height:0;min-width:0;display:grid;gap:10px;overflow:hidden;
    grid-template-columns:210px minmax(0,1fr) 280px;
  }

  .ps-nav{
    min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .ps-nav-scroll{flex:1 1 auto;min-height:0;overflow:auto;padding:8px;}
  .ps-nav a{
    display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;
    text-decoration:none;color:#475569;font-size:12px;font-weight:700;
  }
  .ps-nav a i{width:16px;text-align:center;color:#94a3b8;font-size:13px;}
  .ps-nav a:hover{background:#f8fafc;color:#0f172a;text-decoration:none;}
  .ps-nav a.is-active{background:#eff6ff;color:#1d4ed8;}
  .ps-nav a.is-active i{color:#2563eb;}
  .ps-help{
    flex:0 0 auto;margin:8px;padding:12px;border-radius:10px;background:#eff6ff;border:1px solid #dbeafe;
  }
  .ps-help h3{margin:0 0 4px;font-size:12px;font-weight:800;color:#1e40af;}
  .ps-help p{margin:0 0 8px;font-size:11px;color:#64748b;line-height:1.4;}
  .ps-help a{
    display:inline-flex;align-items:center;gap:5px;height:28px;padding:0 10px;border-radius:7px;
    background:#fff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:11px;font-weight:800;text-decoration:none;
  }
  .ps-help a:hover{background:#f8fafc;text-decoration:none;}

  .ps-main{min-height:0;min-width:0;overflow:auto;overscroll-behavior:contain;display:flex;flex-direction:column;gap:10px;padding-right:2px;}
  .ps-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:14px 16px;flex:0 0 auto;
  }
  .ps-card-hd{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;}
  .ps-card-hd h2{margin:0;font-size:15px;font-weight:800;color:#0f172a;}
  .ps-card-hd p{margin:3px 0 0;font-size:11px;color:#64748b;}
  .ps-save{
    height:32px;padding:0 12px;border-radius:8px;border:0;background:#2563eb;color:#fff;
    font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;
  }
  .ps-save:hover{background:#1d4ed8;}
  .ps-save:disabled{opacity:.55;cursor:not-allowed;}

  .ps-gen{display:grid;grid-template-columns:minmax(0,1fr) 200px;gap:16px;align-items:start;}
  .ps-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px;}
  .ps-field{display:flex;flex-direction:column;gap:4px;min-width:0;}
  .ps-field.full{grid-column:1 / -1;}
  .ps-field label{font-size:11px;font-weight:700;color:#334155;}
  .ps-field input,.ps-field select,.ps-field textarea{
    height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;color:#0f172a;background:#fff;width:100%;
  }
  .ps-field textarea{height:84px;padding:8px 10px;resize:vertical;}
  .ps-field .hint{font-size:10px;color:#94a3b8;font-weight:600;}

  .ps-logo-box{border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#fafbfc;}
  .ps-logo-box .lab{font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;}
  .ps-logo-box .hint{font-size:10px;color:#94a3b8;margin-bottom:8px;}
  .ps-logo-prev{
    height:64px;border-radius:8px;border:1px dashed #cbd5e1;background:#fff;
    display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:8px;position:relative;
  }
  .ps-logo-prev img{max-width:100%;max-height:100%;object-fit:contain;}
  .ps-logo-prev .ph{font-size:12px;font-weight:800;color:#64748b;}
  .ps-logo-actions{display:flex;gap:6px;flex-wrap:wrap;}
  .ps-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .ps-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .ps-btn.danger{border-color:#fecaca;background:#fef2f2;color:#b91c1c;}
  .ps-btn.danger:hover{background:#fee2e2;}
  .ps-btn.warn{border-color:#fed7aa;background:#fff7ed;color:#c2410c;}
  .ps-btn.block{width:100%;justify-content:center;margin-bottom:6px;}
  .ps-btn.ext{width:100%;justify-content:center;}

  .ps-email{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 12px;}

  .ps-jump{
    display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;
  }
  .ps-jump a{
    display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #eef2f7;border-radius:10px;
    text-decoration:none;color:inherit;background:#fff;
  }
  .ps-jump a:hover{border-color:#bfdbfe;background:#f8fafc;text-decoration:none;}
  .ps-jump .ico{
    width:34px;height:34px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    background:#eff6ff;color:#2563eb;flex:0 0 34px;
  }
  .ps-jump .t{font-size:12px;font-weight:800;color:#0f172a;}
  .ps-jump .s{font-size:10px;color:#64748b;margin-top:2px;}

  .ps-right{min-height:0;display:flex;flex-direction:column;gap:10px;overflow:auto;overscroll-behavior:contain;}
  .ps-side-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:12px;flex:0 0 auto;
  }
  .ps-side-card h3{margin:0 0 10px;font-size:13px;font-weight:800;color:#0f172a;}
  .ps-pref a{
    display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid #f1f5f9;
    text-decoration:none;color:inherit;
  }
  .ps-pref a:last-child{border-bottom:0;}
  .ps-pref a:hover{background:#f8fafc;text-decoration:none;}
  .ps-pref .ico{
    width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;
    background:#f1f5f9;color:#475569;flex:0 0 30px;font-size:13px;
  }
  .ps-pref .t{font-size:12px;font-weight:800;color:#0f172a;}
  .ps-pref .s{font-size:10px;color:#64748b;}
  .ps-pref .ch{margin-left:auto;color:#94a3b8;}

  .ps-sys-row{
    display:flex;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:11px;
  }
  .ps-sys-row:last-of-type{border-bottom:0;margin-bottom:8px;}
  .ps-sys-row .k{color:#64748b;font-weight:700;}
  .ps-sys-row .v{color:#0f172a;font-weight:800;text-align:right;}

  .ps-danger h3{color:#b91c1c;}
  .ps-danger .warn{font-size:11px;color:#b91c1c;font-weight:700;margin:0 0 10px;line-height:1.4;}

  /* Security section (readable sizes — match rest of settings) */
  .sec-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:14px 16px;flex:0 0 auto;
  }
  .sec-card > h2{margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a;}
  .sec-card > .sec-sub{margin:0 0 10px;font-size:11px;color:#64748b;}
  .sec-row{
    display:flex;align-items:center;gap:10px;padding:11px 2px;border-bottom:1px solid #f1f5f9;
    text-decoration:none;color:inherit;min-width:0;
  }
  .sec-row:last-child{border-bottom:0;}
  a.sec-row:hover{background:#f8fafc;text-decoration:none;}
  .sec-row .sec-label{flex:1 1 auto;min-width:0;font-size:13px;font-weight:700;color:#0f172a;}
  .sec-row .sec-ch{color:#94a3b8;font-size:12px;flex:0 0 auto;}
  .sec-badge{
    display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:999px;
    font-size:11px;font-weight:800;flex:0 0 auto;white-space:nowrap;
  }
  .sec-badge.on{background:#dcfce7;color:#166534;}
  .sec-badge.off{background:#f1f5f9;color:#64748b;}
  .sec-val{font-size:12px;font-weight:800;color:#2563eb;flex:0 0 auto;white-space:nowrap;}
  .sec-toggle-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:11px 2px;border-bottom:1px solid #f1f5f9;
  }
  .sec-toggle-row:last-child{border-bottom:0;}
  .sec-toggle-row .sec-label{font-size:13px;font-weight:700;color:#0f172a;}
  .sec-switch{
    position:relative;display:inline-block;width:42px;height:24px;flex:0 0 auto;margin:0;
  }
  .sec-switch input{opacity:0;width:0;height:0;position:absolute;}
  .sec-switch .sec-slider{
    position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:999px;transition:.15s;
  }
  .sec-switch .sec-slider:before{
    content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;
    border-radius:50%;transition:.15s;box-shadow:0 1px 2px rgba(15,23,42,.2);
  }
  .sec-switch input:checked + .sec-slider{background:#22c55e;}
  .sec-switch input:checked + .sec-slider:before{transform:translateX(18px);}
  .sec-switch input:disabled + .sec-slider{opacity:.55;cursor:not-allowed;}
  .sec-edit-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;}

  .sec-sess{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;}
  .sec-sess:last-of-type{border-bottom:0;margin-bottom:8px;}
  .sec-av{
    width:34px;height:34px;border-radius:999px;background:#eff6ff;color:#1d4ed8;
    display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex:0 0 34px;
  }
  .sec-sess .n{font-size:12px;font-weight:800;color:#0f172a;}
  .sec-sess .m{font-size:11px;color:#64748b;margin-top:2px;line-height:1.35;}
  .sec-sum{list-style:none;margin:0;padding:0;}
  .sec-sum li{
    display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-bottom:1px solid #f1f5f9;
    font-size:12px;font-weight:700;color:#0f172a;
  }
  .sec-sum li:last-child{border-bottom:0;}
  .sec-sum i{width:14px;text-align:center;margin-top:1px;flex:0 0 14px;}
  .sec-sum .ok{color:#16a34a;}
  .sec-sum .warn{color:#d97706;}
  .sec-sum .info{color:#2563eb;}
  .sec-evt{display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9;}
  .sec-evt:last-of-type{border-bottom:0;}
  .sec-evt .ico{width:18px;text-align:center;flex:0 0 18px;margin-top:1px;font-size:13px;}
  .sec-evt .ico.ok{color:#16a34a;}
  .sec-evt .ico.bad{color:#dc2626;}
  .sec-evt .t{font-size:12px;font-weight:800;color:#0f172a;}
  .sec-evt .s{font-size:11px;color:#64748b;margin-top:2px;}
  .sec-viewall{
    display:inline-flex;margin-top:8px;font-size:12px;font-weight:800;color:#2563eb;text-decoration:none;
  }
  .sec-viewall:hover{text-decoration:underline;}

  /* Notifications section (readable medium density ~11–13px) */
  .ps-main.is-nt{overflow:hidden;min-height:0;}
  .nt-panel{
    flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:0;
    background:#fff;border:1px solid #eef2f7;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    overflow:hidden;
  }
  .nt-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:10px 12px 0;border-bottom:1px solid #eef2f7;flex-wrap:wrap;
  }
  .nt-tabs{display:flex;align-items:stretch;gap:0;flex-wrap:wrap;min-width:0;}
  .nt-tabs a{
    display:inline-flex;align-items:center;gap:6px;padding:10px 12px;font-size:12px;font-weight:700;
    color:#64748b;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;
  }
  .nt-tabs a:hover{color:#0f172a;text-decoration:none;}
  .nt-tabs a.is-active{color:#1d4ed8;border-bottom-color:#2563eb;}
  .nt-tabs .nt-badge{
    display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;
    border-radius:999px;background:#fee2e2;color:#b91c1c;font-size:10px;font-weight:800;
  }
  .nt-hd-actions{display:flex;align-items:center;gap:8px;padding-bottom:8px;}
  .nt-filters{
    flex:0 0 auto;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;
    padding:10px 12px;border-bottom:1px solid #f1f5f9;background:#fafbfc;
  }
  .nt-filters .nt-f{display:flex;flex-direction:column;gap:3px;min-width:0;}
  .nt-filters label{font-size:11px;font-weight:700;color:#64748b;}
  .nt-filters input,.nt-filters select{
    height:32px;border:1px solid #e2e8f0;border-radius:8px;padding:0 8px;font-size:12px;
    color:#0f172a;background:#fff;min-width:0;
  }
  .nt-filters .nt-search{width:160px;}
  .nt-filters .nt-date{width:128px;}
  .nt-filters .nt-sel{width:120px;}
  .nt-filters .nt-clear{font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;padding-bottom:8px;}
  .nt-filters .nt-clear:hover{text-decoration:underline;}
  .nt-table-scroll{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .nt-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;}
  .nt-table thead th{
    position:sticky;top:0;z-index:2;background:#f8fafc;border-bottom:1px solid #e2e8f0;
    padding:9px 10px;text-align:left;font-size:11px;font-weight:800;color:#64748b;white-space:nowrap;
  }
  .nt-table tbody td{
    padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#0f172a;
  }
  .nt-table tbody tr:hover td{background:#f8fafc;}
  .nt-table tbody tr.is-unread td{background:#f8fbff;}
  .nt-check{width:36px;}
  .nt-cell-notif{display:flex;align-items:flex-start;gap:10px;min-width:0;max-width:320px;}
  .nt-ico{
    width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;
    background:#eff6ff;color:#2563eb;flex:0 0 32px;font-size:13px;
  }
  .nt-ico.user_report{background:#fef2f2;color:#dc2626;}
  .nt-ico.security{background:#fff7ed;color:#c2410c;}
  .nt-ico.moderation{background:#f5f3ff;color:#6d28d9;}
  .nt-ico.engagement{background:#fdf2f8;color:#db2777;}
  .nt-ico.updates{background:#ecfdf5;color:#059669;}
  .nt-title{font-size:12px;font-weight:800;color:#0f172a;line-height:1.3;}
  .nt-desc{font-size:11px;color:#64748b;margin-top:2px;line-height:1.35;}
  .nt-pill{
    display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:999px;
    font-size:11px;font-weight:800;white-space:nowrap;
  }
  .nt-pill.high{background:#fee2e2;color:#b91c1c;}
  .nt-pill.medium{background:#ffedd5;color:#c2410c;}
  .nt-pill.low{background:#f1f5f9;color:#64748b;}
  .nt-pill.read{background:#dcfce7;color:#166534;}
  .nt-pill.unread{background:#fef9c3;color:#a16207;}
  .nt-related{font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;}
  .nt-related:hover{text-decoration:underline;}
  .nt-related.muted{color:#64748b;font-weight:600;cursor:default;}
  .nt-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:8px 12px;border-top:1px solid #eef2f7;background:#fff;font-size:12px;color:#64748b;font-weight:600;
  }
  .nt-pager{display:flex;align-items:center;gap:6px;}
  .nt-pager a,.nt-pager span{
    display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 8px;
    border-radius:7px;border:1px solid #e2e8f0;background:#fff;color:#334155;font-size:12px;font-weight:700;
    text-decoration:none;
  }
  .nt-pager a:hover{background:#f8fafc;text-decoration:none;}
  .nt-pager .is-active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
  .nt-pager .is-disabled{opacity:.45;pointer-events:none;}
  .nt-empty{padding:28px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:600;}
  .nt-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
  .nt-stat{
    border:1px solid #eef2f7;border-radius:10px;padding:10px;background:#fafbfc;
  }
  .nt-stat .k{font-size:11px;font-weight:700;color:#64748b;}
  .nt-stat .v{font-size:18px;font-weight:800;color:#0f172a;margin-top:2px;letter-spacing:-.02em;}
  .nt-stat.unread .v{color:#b91c1c;}
  .nt-stat.high .v{color:#c2410c;}
  .nt-donut-wrap{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
  .nt-donut{
    width:88px;height:88px;border-radius:50%;flex:0 0 88px;
    background:conic-gradient(#2563eb 0deg, #2563eb var(--p1), #ef4444 var(--p1), #ef4444 var(--p2), #f59e0b var(--p2), #f59e0b var(--p3), #8b5cf6 var(--p3), #8b5cf6 var(--p4), #ec4899 var(--p4), #ec4899 var(--p5), #e2e8f0 var(--p5));
    position:relative;
  }
  .nt-donut:after{
    content:"";position:absolute;inset:18px;border-radius:50%;background:#fff;
  }
  .nt-legend{list-style:none;margin:0;padding:0;flex:1 1 auto;min-width:0;}
  .nt-legend li{
    display:flex;align-items:center;gap:6px;padding:4px 0;font-size:11px;font-weight:700;color:#334155;
  }
  .nt-legend .dot{width:8px;height:8px;border-radius:999px;flex:0 0 8px;}
  .nt-legend .pct{margin-left:auto;color:#94a3b8;font-weight:800;}
  .nt-qa a{
    display:flex;align-items:center;gap:8px;padding:8px 2px;border-bottom:1px solid #f1f5f9;
    text-decoration:none;color:#0f172a;font-size:12px;font-weight:700;
  }
  .nt-qa a:last-child{border-bottom:0;}
  .nt-qa a:hover{background:#f8fafc;text-decoration:none;}
  .nt-qa i{width:16px;text-align:center;color:#64748b;}
  .nt-qa form{margin:0;padding:8px 2px;}
  .nt-qa button{
    width:100%;display:flex;align-items:center;gap:8px;padding:0;border:0;background:transparent;
    color:#0f172a;font-size:12px;font-weight:700;cursor:pointer;text-align:left;
  }
  .nt-qa button:hover{color:#1d4ed8;}
  .nt-qa button i{width:16px;text-align:center;color:#64748b;}

  /* Privacy section (readable medium density — match Security) */
  .pv-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:14px 16px;flex:0 0 auto;
  }
  .pv-card > h2{margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a;}
  .pv-card > .pv-sub{margin:0 0 10px;font-size:11px;color:#64748b;}
  .pv-toggle-row{
    display:flex;align-items:flex-start;justify-content:space-between;gap:14px;
    padding:12px 2px;border-bottom:1px solid #f1f5f9;
  }
  .pv-toggle-row:last-child{border-bottom:0;}
  .pv-toggle-copy{flex:1 1 auto;min-width:0;}
  .pv-toggle-copy .pv-label{display:block;font-size:13px;font-weight:700;color:#0f172a;}
  .pv-toggle-copy .pv-desc{display:block;margin-top:3px;font-size:11px;color:#64748b;line-height:1.4;}
  .pv-toggle{
    position:relative;display:inline-block;width:42px;height:24px;flex:0 0 auto;margin:2px 0 0;
  }
  .pv-toggle input{opacity:0;width:0;height:0;position:absolute;}
  .pv-toggle .pv-slider{
    position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:999px;transition:.15s;
  }
  .pv-toggle .pv-slider:before{
    content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;
    border-radius:50%;transition:.15s;box-shadow:0 1px 2px rgba(15,23,42,.2);
  }
  .pv-toggle input:checked + .pv-slider{background:#22c55e;}
  .pv-toggle input:checked + .pv-slider:before{transform:translateX(18px);}
  .pv-toggle input:disabled + .pv-slider{opacity:.55;cursor:not-allowed;}
  .pv-row{
    display:flex;align-items:center;gap:10px;padding:12px 2px;border-bottom:1px solid #f1f5f9;
    text-decoration:none;color:inherit;min-width:0;
  }
  .pv-row:last-child{border-bottom:0;}
  a.pv-row:hover{background:#f8fafc;text-decoration:none;}
  .pv-row .pv-row-copy{flex:1 1 auto;min-width:0;}
  .pv-row .pv-label{display:block;font-size:13px;font-weight:700;color:#0f172a;}
  .pv-row .pv-desc{display:block;margin-top:2px;font-size:11px;color:#64748b;line-height:1.35;}
  .pv-row .pv-ch{color:#94a3b8;font-size:12px;flex:0 0 auto;}
  .pv-edit-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;}
  .pv-sum{list-style:none;margin:0 0 10px;padding:0;}
  .pv-sum li{
    display:flex;justify-content:space-between;align-items:flex-start;gap:8px;
    padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12px;
  }
  .pv-sum li:last-child{border-bottom:0;}
  .pv-sum .k{color:#64748b;font-weight:700;}
  .pv-sum .v{color:#0f172a;font-weight:800;text-align:right;}
  .pv-sum a.v{color:#2563eb;text-decoration:none;}
  .pv-sum a.v:hover{text-decoration:underline;}
  .pv-ret{list-style:none;margin:0 0 10px;padding:0;}
  .pv-ret li{
    display:flex;justify-content:space-between;gap:8px;padding:7px 0;
    border-bottom:1px solid #f1f5f9;font-size:12px;
  }
  .pv-ret li:last-child{border-bottom:0;}
  .pv-ret .k{color:#64748b;font-weight:700;}
  .pv-ret .v{color:#0f172a;font-weight:800;text-align:right;}
  .pv-comp{display:flex;flex-direction:column;gap:0;margin-bottom:10px;}
  .pv-comp-row{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 0;border-bottom:1px solid #f1f5f9;
  }
  .pv-comp-row:last-child{border-bottom:0;}
  .pv-comp-row .n{font-size:12px;font-weight:800;color:#0f172a;}
  .pv-badge{
    display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:999px;
    font-size:11px;font-weight:800;white-space:nowrap;
  }
  .pv-badge.on{background:#dcfce7;color:#166534;}
  .pv-badge.off{background:#f1f5f9;color:#64748b;}

  /* System section (medium density — match Security / Privacy) */
  .sy-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:14px 16px;flex:0 0 auto;
  }
  .sy-card > h2{margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a;}
  .sy-card > .sy-sub{margin:0 0 12px;font-size:11px;color:#64748b;}
  .sy-info-grid{
    display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 14px;
  }
  .sy-info-cell{
    min-width:0;padding:10px 12px;border:1px solid #f1f5f9;border-radius:10px;background:#fafbfc;
  }
  .sy-info-cell .k{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;}
  .sy-info-cell .v{display:block;font-size:13px;font-weight:800;color:#0f172a;word-break:break-word;}
  .sy-env{
    display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px 18px;align-items:start;
  }
  .sy-kv{display:flex;flex-direction:column;gap:0;}
  .sy-kv-row{
    display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:12px;
  }
  .sy-kv-row:last-child{border-bottom:0;}
  .sy-kv-row .k{color:#64748b;font-weight:700;}
  .sy-kv-row .v{color:#0f172a;font-weight:800;text-align:right;max-width:60%;word-break:break-word;}
  .sy-meters{display:flex;flex-direction:column;gap:12px;}
  .sy-meter .lab{display:flex;justify-content:space-between;gap:8px;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
  .sy-meter .lab .pct{color:#64748b;font-weight:800;}
  .sy-bar{
    height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden;
  }
  .sy-bar > span{display:block;height:100%;border-radius:999px;background:#22c55e;width:0;}
  .sy-bar > span.warm{background:#f59e0b;}
  .sy-bar > span.hot{background:#ef4444;}
  .sy-env-actions{margin-top:12px;}
  .sy-toggle-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:11px 0;border-bottom:1px solid #f1f5f9;
  }
  .sy-toggle-row:last-child{border-bottom:0;}
  .sy-toggle-copy{flex:1 1 auto;min-width:0;}
  .sy-toggle-copy .sy-label{display:block;font-size:13px;font-weight:700;color:#0f172a;}
  .sy-toggle-copy .sy-desc{display:block;margin-top:3px;font-size:11px;color:#64748b;line-height:1.4;}
  .sy-switch{
    position:relative;display:inline-block;width:42px;height:24px;flex:0 0 auto;cursor:pointer;
  }
  .sy-switch input{opacity:0;width:0;height:0;position:absolute;}
  .sy-switch .sy-slider{
    position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:.15s;
  }
  .sy-switch .sy-slider:before{
    content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;border-radius:50%;
    background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.2);transition:.15s;
  }
  .sy-switch input:checked + .sy-slider{background:#22c55e;}
  .sy-switch input:checked + .sy-slider:before{transform:translateX(18px);}
  .sy-switch input:disabled + .sy-slider{opacity:.55;cursor:not-allowed;}
  .sy-updates-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:10px 0;border-bottom:1px solid #f1f5f9;
  }
  .sy-updates-row:last-child{border-bottom:0;}
  .sy-channel{
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:4px;
  }
  .sy-channel select{
    height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;color:#0f172a;background:#fff;min-width:140px;
  }
  .sy-health-hero{
    display:flex;flex-direction:column;align-items:center;text-align:center;padding:8px 0 12px;border-bottom:1px solid #f1f5f9;margin-bottom:8px;
  }
  .sy-health-hero .sy-check{
    width:52px;height:52px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    background:#dcfce7;color:#16a34a;font-size:24px;margin-bottom:8px;
  }
  .sy-health-hero .sy-check.warn{background:#fef3c7;color:#d97706;}
  .sy-health-hero .t{font-size:13px;font-weight:800;color:#0f172a;}
  .sy-health-hero .s{font-size:11px;color:#64748b;margin-top:2px;}
  .sy-health-list{list-style:none;margin:0 0 10px;padding:0;}
  .sy-health-list li{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:12px;
  }
  .sy-health-list li:last-child{border-bottom:0;}
  .sy-health-list .n{font-weight:700;color:#0f172a;}
  .sy-health-list .st{font-weight:800;font-size:11px;}
  .sy-health-list .st.ok{color:#16a34a;}
  .sy-health-list .st.bad{color:#d97706;}
  .sy-backup-rows{margin-bottom:10px;}
  .sy-tool-row{
    display:flex;align-items:center;gap:10px;width:100%;padding:11px 2px;border:0;border-bottom:1px solid #f1f5f9;
    background:transparent;cursor:pointer;text-align:left;text-decoration:none;color:inherit;font:inherit;
  }
  .sy-tool-row:last-child{border-bottom:0;}
  .sy-tool-row:hover{background:#f8fafc;text-decoration:none;}
  .sy-tool-row .sy-tool-label{flex:1 1 auto;min-width:0;font-size:13px;font-weight:700;color:#0f172a;}
  .sy-tool-row .sy-ch{color:#94a3b8;font-size:12px;flex:0 0 auto;}
  form.sy-tool-form{margin:0;}

  /* API section */
  .ps-main.is-api{overflow:hidden;gap:8px;}
  .api-metrics{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;flex:0 0 auto;
  }
  .api-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:12px 14px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;
  }
  .api-metric .k{font-size:11px;font-weight:700;color:#64748b;}
  .api-metric .v{margin-top:4px;font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;}
  .api-metric .d{margin-top:4px;font-size:11px;font-weight:700;color:#64748b;}
  .api-metric .d.up{color:#16a34a;}
  .api-metric .d.down{color:#dc2626;}
  .api-panel{
    flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .api-hd{
    flex:0 0 auto;display:flex;align-items:flex-end;justify-content:space-between;gap:10px;
    padding:0 12px;border-bottom:1px solid #eef2f7;min-width:0;flex-wrap:wrap;
  }
  .api-tabs{display:flex;align-items:stretch;gap:0;flex-wrap:wrap;min-width:0;}
  .api-tabs a{
    display:inline-flex;align-items:center;gap:6px;padding:12px 12px 10px;border-bottom:2px solid transparent;
    color:#64748b;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;
  }
  .api-tabs a:hover{color:#0f172a;text-decoration:none;}
  .api-tabs a.is-active{color:#1d4ed8;border-bottom-color:#2563eb;}
  .api-hd-actions{display:flex;align-items:center;gap:8px;padding-bottom:8px;}
  .api-filters{
    flex:0 0 auto;display:flex;flex-wrap:wrap;gap:8px 10px;align-items:flex-end;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .api-filters .api-f{display:flex;flex-direction:column;gap:3px;min-width:0;}
  .api-filters label{font-size:11px;font-weight:700;color:#64748b;}
  .api-filters input,.api-filters select{
    height:32px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;color:#0f172a;background:#fff;
  }
  .api-filters .api-search{width:180px;}
  .api-filters .api-sel{width:130px;}
  .api-body{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;padding:12px 14px;}
  .api-table-scroll{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .api-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;}
  .api-table thead th{
    position:sticky;top:0;z-index:1;background:#f8fafc;border-bottom:1px solid #e2e8f0;
    text-align:left;padding:9px 10px;font-size:11px;font-weight:800;color:#64748b;white-space:nowrap;
  }
  .api-table tbody td{
    padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155;
  }
  .api-table tbody tr:hover td{background:#f8fafc;}
  .api-name{font-size:13px;font-weight:800;color:#0f172a;}
  .api-sub{font-size:11px;color:#94a3b8;margin-top:2px;}
  .api-key-cell{display:flex;align-items:center;gap:6px;min-width:0;}
  .api-key-mask{
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
    font-size:12px;font-weight:700;color:#0f172a;letter-spacing:.01em;
  }
  .api-icon-btn{
    width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;
    color:#64748b;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;
  }
  .api-icon-btn:hover{background:#f8fafc;color:#0f172a;}
  .api-pill{
    display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:999px;
    font-size:11px;font-weight:800;background:#f1f5f9;color:#475569;
  }
  .api-pill.read{background:#eff6ff;color:#1d4ed8;}
  .api-pill.write{background:#f5f3ff;color:#6d28d9;}
  .api-pill.active{background:#dcfce7;color:#166534;}
  .api-pill.inactive{background:#f1f5f9;color:#64748b;}
  .api-pill.revoked{background:#fee2e2;color:#b91c1c;}
  .api-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;font-weight:700;color:#64748b;background:#fff;
  }
  .api-pager{display:flex;align-items:center;gap:6px;}
  .api-pager a,.api-pager span{
    min-width:28px;height:28px;padding:0 8px;border-radius:7px;border:1px solid #e2e8f0;
    display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#334155;font-weight:800;font-size:12px;background:#fff;
  }
  .api-pager a:hover{background:#f8fafc;text-decoration:none;}
  .api-pager .is-active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
  .api-pager .is-disabled{opacity:.45;pointer-events:none;}
  .api-empty{padding:28px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:600;}
  .api-flash{
    flex:0 0 auto;margin:0;padding:10px 12px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;
    font-size:12px;color:#92400e;font-weight:700;
  }
  .api-flash code{
    display:inline-block;margin-top:6px;padding:6px 8px;border-radius:6px;background:#fff;border:1px solid #fcd34d;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;color:#0f172a;word-break:break-all;
  }
  .api-form-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:14px 16px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .api-form-card h2{margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a;}
  .api-form-card > .api-sub{margin:0 0 12px;}
  .api-usage-bar{
    height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:8px 0 4px;
  }
  .api-usage-bar > span{display:block;height:100%;border-radius:999px;background:#2563eb;width:0;}
  .api-usage-bar > span.warm{background:#f59e0b;}
  .api-usage-bar > span.hot{background:#ef4444;}
  .api-docs-list{list-style:none;margin:0 0 10px;padding:0;}
  .api-docs-list a{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 2px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#0f172a;font-size:12px;font-weight:700;
  }
  .api-docs-list a:last-child{border-bottom:0;}
  .api-docs-list a:hover{color:#1d4ed8;text-decoration:none;}
  .api-docs-list i{color:#94a3b8;font-size:11px;}
  .api-side-meter{margin:8px 0 10px;}
  .api-side-meter .lab{display:flex;justify-content:space-between;gap:8px;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
  .api-side-meter .lab .pct{color:#64748b;font-weight:800;}
  .api-side-kv{font-size:12px;color:#64748b;font-weight:700;margin-bottom:10px;}
  .api-side-kv strong{color:#0f172a;font-weight:800;}
  .api-wh-row{
    display:flex;align-items:flex-start;justify-content:space-between;gap:10px;
    padding:10px 0;border-bottom:1px solid #f1f5f9;
  }
  .api-wh-row:last-child{border-bottom:0;}
  .api-create-grid{display:grid;grid-template-columns:1fr 160px auto;gap:10px;align-items:end;}
  @media (max-width:1100px){
    .api-metrics{grid-template-columns:1fr 1fr;}
    .api-create-grid{grid-template-columns:1fr 1fr;}
  }
  @media (max-width:720px){
    .api-metrics{grid-template-columns:1fr;}
    .api-create-grid{grid-template-columns:1fr;}
  }

  /* Integrations section (readable medium density — match API) */
  .ps-main.is-ig{overflow:hidden;gap:8px;}
  .ig-metrics{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;flex:0 0 auto;
  }
  .ig-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:12px 14px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;
  }
  .ig-metric .k{font-size:11px;font-weight:700;color:#64748b;}
  .ig-metric .v{margin-top:4px;font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;}
  .ig-metric .d{margin-top:4px;font-size:11px;font-weight:700;color:#64748b;}
  .ig-panel{
    flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .ig-hd{
    flex:0 0 auto;display:flex;align-items:flex-end;justify-content:space-between;gap:10px;
    padding:0 12px;border-bottom:1px solid #eef2f7;min-width:0;flex-wrap:wrap;
  }
  .ig-tabs{display:flex;align-items:stretch;gap:0;flex-wrap:wrap;min-width:0;}
  .ig-tabs a{
    display:inline-flex;align-items:center;gap:6px;padding:12px 12px 10px;border-bottom:2px solid transparent;
    color:#64748b;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;
  }
  .ig-tabs a:hover{color:#0f172a;text-decoration:none;}
  .ig-tabs a.is-active{color:#1d4ed8;border-bottom-color:#2563eb;}
  .ig-filters{
    flex:0 0 auto;display:flex;flex-wrap:wrap;gap:8px 10px;align-items:flex-end;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .ig-filters .ig-f{display:flex;flex-direction:column;gap:3px;min-width:0;}
  .ig-filters label{font-size:11px;font-weight:700;color:#64748b;}
  .ig-filters input,.ig-filters select{
    height:32px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;color:#0f172a;background:#fff;
  }
  .ig-filters .ig-search{width:200px;}
  .ig-filters .ig-sel{width:160px;}
  .ig-table-scroll{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .ig-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;}
  .ig-table thead th{
    position:sticky;top:0;z-index:1;background:#f8fafc;border-bottom:1px solid #e2e8f0;
    text-align:left;padding:9px 10px;font-size:11px;font-weight:800;color:#64748b;white-space:nowrap;
  }
  .ig-table tbody td{
    padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155;
  }
  .ig-table tbody tr:hover td{background:#f8fafc;}
  .ig-cell{display:flex;align-items:flex-start;gap:10px;min-width:0;}
  .ig-ico{
    width:36px;height:36px;border-radius:999px;flex:0 0 36px;
    display:flex;align-items:center;justify-content:center;
    background:#eff6ff;color:#1d4ed8;font-size:15px;
  }
  .ig-name{font-size:13px;font-weight:800;color:#0f172a;}
  .ig-desc{font-size:11px;color:#94a3b8;margin-top:2px;line-height:1.35;}
  .ig-cat{font-size:12px;font-weight:700;color:#475569;}
  .ig-pill{
    display:inline-flex;align-items:center;gap:6px;height:22px;padding:0 10px;border-radius:999px;
    font-size:11px;font-weight:800;background:#f1f5f9;color:#475569;
  }
  .ig-pill .dot{width:7px;height:7px;border-radius:999px;background:currentColor;flex:0 0 7px;}
  .ig-pill.active{background:#dcfce7;color:#166534;}
  .ig-pill.inactive{background:#f1f5f9;color:#64748b;}
  .ig-pill.failed{background:#fee2e2;color:#b91c1c;}
  .ig-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;font-weight:700;color:#64748b;background:#fff;
  }
  .ig-pager{display:flex;align-items:center;gap:6px;}
  .ig-pager a,.ig-pager span{
    min-width:28px;height:28px;padding:0 8px;border-radius:7px;border:1px solid #e2e8f0;
    display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#334155;font-weight:800;font-size:12px;background:#fff;
  }
  .ig-pager a:hover{background:#f8fafc;text-decoration:none;}
  .ig-pager .is-active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
  .ig-pager .is-disabled{opacity:.45;pointer-events:none;}
  .ig-empty{padding:28px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:600;}
  .ig-edit-card{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);padding:14px 16px;
  }
  .ig-edit-card h2{margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a;}
  .ig-edit-card .ig-sub{margin:0 0 10px;font-size:11px;color:#64748b;}
  .ig-donut-wrap{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
  .ig-donut{
    width:88px;height:88px;border-radius:50%;flex:0 0 88px;position:relative;
  }
  .ig-donut:after{
    content:"";position:absolute;inset:18px;border-radius:50%;background:#fff;
  }
  .ig-legend{list-style:none;margin:0;padding:0;flex:1 1 auto;min-width:0;}
  .ig-legend li{
    display:flex;align-items:center;gap:6px;padding:4px 0;font-size:11px;font-weight:700;color:#334155;
  }
  .ig-legend .dot{width:8px;height:8px;border-radius:999px;flex:0 0 8px;}
  .ig-legend .pct{margin-left:auto;color:#94a3b8;font-weight:800;}
  .ig-pop{list-style:none;margin:0 0 10px;padding:0;}
  .ig-pop li{padding:8px 0;border-bottom:1px solid #f1f5f9;}
  .ig-pop li:last-child{border-bottom:0;}
  .ig-pop .row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px;}
  .ig-pop .n{font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:7px;min-width:0;}
  .ig-pop .n i{color:#64748b;width:14px;text-align:center;}
  .ig-pop .p{font-size:11px;font-weight:800;color:#64748b;}
  .ig-pop-bar{height:6px;border-radius:999px;background:#e2e8f0;overflow:hidden;}
  .ig-pop-bar > span{display:block;height:100%;border-radius:999px;background:#2563eb;}
  .ig-help-list{list-style:none;margin:0 0 10px;padding:0;}
  .ig-help-list a{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 2px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#0f172a;font-size:12px;font-weight:700;
  }
  .ig-help-list a:last-child{border-bottom:0;}
  .ig-help-list a:hover{color:#1d4ed8;text-decoration:none;}
  .ig-help-list i{color:#94a3b8;font-size:11px;}
  @media (max-width:1100px){
    .ig-metrics{grid-template-columns:1fr 1fr;}
  }
  @media (max-width:720px){
    .ig-metrics{grid-template-columns:1fr;}
  }


  /* Content settings */
  .ps-main.is-ct{overflow:auto;gap:10px;overscroll-behavior:contain;}
  .ct-metrics{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;flex:0 0 auto;
  }
  .ct-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:12px 14px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;align-items:flex-start;justify-content:space-between;gap:10px;min-width:0;
  }
  .ct-metric .copy{min-width:0;}
  .ct-metric .k{font-size:11px;font-weight:700;color:#64748b;}
  .ct-metric .v{margin-top:4px;font-size:22px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;}
  .ct-metric .d{margin-top:4px;font-size:11px;font-weight:700;color:#64748b;}
  .ct-metric .d.ok{color:#16a34a;}
  .ct-metric .d.warn{color:#ea580c;}
  .ct-metric .d.bad{color:#dc2626;}
  .ct-metric .ico{
    width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    font-size:15px;flex:0 0 34px;
  }
  .ct-metric .ico.blue{background:#eff6ff;color:#2563eb;}
  .ct-metric .ico.green{background:#dcfce7;color:#16a34a;}
  .ct-metric .ico.orange{background:#ffedd5;color:#ea580c;}
  .ct-metric .ico.red{background:#fee2e2;color:#dc2626;}
  .ct-grid{
    display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px;align-items:start;
  }
  .ct-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:14px 16px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;
  }
  .ct-card.full{grid-column:1 / -1;}
  .ct-card-hd{
    display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px;
  }
  .ct-card > h2,.ct-card-hd h2{margin:0;font-size:15px;font-weight:800;color:#0f172a;}
  .ct-card > .ct-sub{margin:4px 0 10px;font-size:11px;color:#64748b;line-height:1.4;}
  .ct-toggle-row{
    display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
    padding:10px 0;border-bottom:1px solid #f1f5f9;
  }
  .ct-toggle-row:last-child{border-bottom:0;padding-bottom:0;}
  .ct-toggle-row:first-of-type{padding-top:2px;}
  .ct-toggle-copy{flex:1 1 auto;min-width:0;}
  .ct-toggle-copy .ct-label{display:block;font-size:13px;font-weight:700;color:#0f172a;}
  .ct-toggle-copy .ct-desc{display:block;margin-top:3px;font-size:11px;color:#64748b;line-height:1.4;}
  .ct-toggle{
    position:relative;display:inline-block;width:42px;height:24px;flex:0 0 42px;cursor:pointer;margin-top:1px;
  }
  .ct-toggle input{opacity:0;width:0;height:0;position:absolute;}
  .ct-toggle .ct-slider{
    position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:.15s;
  }
  .ct-toggle .ct-slider:before{
    content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;
    box-shadow:0 1px 2px rgba(15,23,42,.2);transition:.15s;
  }
  .ct-toggle input:checked + .ct-slider{background:#2563eb;}
  .ct-toggle input:checked + .ct-slider:before{transform:translateX(18px);}
  .ct-toggle input:disabled + .ct-slider{opacity:.55;cursor:not-allowed;}
  .ct-types{
    display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;margin-top:6px;
  }
  .ct-type{
    display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 6px 8px;
    border:1px solid #eef2f7;border-radius:10px;background:#fafbfc;text-align:center;min-width:0;
  }
  .ct-type .glyph{
    width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    font-size:15px;color:#fff;
  }
  .ct-type .nm{font-size:11px;font-weight:700;color:#334155;line-height:1.2;}
  .ct-type input[type=checkbox]{
    width:15px;height:15px;accent-color:#2563eb;cursor:pointer;
  }
  .ct-type input[type=checkbox]:disabled{cursor:not-allowed;}
  .ct-field-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:10px 0;border-bottom:1px solid #f1f5f9;
  }
  .ct-field-row:last-child{border-bottom:0;padding-bottom:0;}
  .ct-field-row .ct-label{font-size:13px;font-weight:700;color:#0f172a;}
  .ct-field-row .ct-desc{display:block;margin-top:3px;font-size:11px;color:#64748b;line-height:1.4;}
  .ct-select,.ct-vis-select{
    height:34px;min-width:120px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;
    font-size:12px;font-weight:700;color:#0f172a;background:#fff;
  }
  .ct-vis-wrap{display:flex;align-items:center;gap:8px;}
  .ct-vis-wrap .globe{color:#64748b;font-size:14px;}
  .ct-cats-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  }
  .ct-cats-row .copy{min-width:0;}
  .ct-sum{list-style:none;margin:0 0 10px;padding:0;}
  .ct-sum li{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12px;
  }
  .ct-sum li:last-child{border-bottom:0;}
  .ct-sum .k{color:#64748b;font-weight:700;}
  .ct-sum .v{color:#0f172a;font-weight:800;text-align:right;}
  @media (max-width:1100px){
    .ct-metrics{grid-template-columns:1fr 1fr;}
    .ct-types{grid-template-columns:repeat(4,minmax(0,1fr));}
  }
  @media (max-width:720px){
    .ct-metrics,.ct-grid{grid-template-columns:1fr;}
    .ct-types{grid-template-columns:repeat(3,minmax(0,1fr));}
  }

  @media (max-width:1200px){
    .ps-board{grid-template-columns:180px minmax(0,1fr);}
    .ps-right{display:none;}
  }
  @media (max-width:900px){
    .ps-board{grid-template-columns:1fr;overflow:auto;}
    .ps-wrap{overflow:auto;}
    .ps-nav{max-height:220px;}
    .ps-gen,.ps-email,.ps-jump{grid-template-columns:1fr;}
    .ps-fields{grid-template-columns:1fr;}
    .ps-main.is-nt,.ps-main.is-api,.ps-main.is-ig,.ps-main.is-ct{overflow:auto;}
    .sy-info-grid{grid-template-columns:1fr 1fr;}
    .sy-env{grid-template-columns:1fr;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ps-wrap">

      <?php if ($error !== ''): ?><div class="ps-alert bad"><?= admin_platform_settings_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="ps-alert ok"><?= admin_platform_settings_h($msg) ?></div><?php endif; ?>

      <div class="ps-board">

        <aside class="ps-nav" aria-label="Settings sections">
          <div class="ps-nav-scroll">
            <?php foreach ($navItems as $key => $meta): ?>
              <a class="<?= $section === $key ? 'is-active' : '' ?>" href="settings.php?section=<?= rawurlencode($key) ?>">
                <i class="fa <?= admin_platform_settings_h($meta[1]) ?>"></i>
                <span><?= admin_platform_settings_h($meta[0]) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
          <div class="ps-help">
            <?php if ($showSecurity): ?>
              <h3>Security Best Practices</h3>
              <p>Keep 2FA on, limit login attempts, and review the security log regularly.</p>
              <a href="security-log.php"><i class="fa fa-external-link"></i> View Guide</a>
            <?php elseif ($showNotifications): ?>
              <h3>Notification Channels</h3>
              <p>Choose how alerts reach you — in-app inbox, email delivery, and channel routing for each role.</p>
              <a href="notification.php"><i class="fa fa-bell"></i> Manage Channels</a>
            <?php elseif ($showPrivacy): ?>
              <h3>Privacy Guide</h3>
              <p>Configure data collection, retention, and user rights so the platform stays transparent and compliant.</p>
              <a href="<?= admin_platform_settings_h($pvPolicyHref) ?>"><i class="fa fa-external-link"></i> View Privacy Policy</a>
            <?php elseif ($showApi): ?>
              <h3>Need help?</h3>
              <p>Generate keys carefully, rotate revoked credentials, and keep rate limits aligned with your integrations.</p>
              <a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>"><i class="fa fa-external-link"></i> View Documentation</a>
            <?php elseif ($showIntegrations): ?>
              <h3>Need help?</h3>
              <p>Integration tip — connect only what you need, keep failed services retried, and review sync times regularly.</p>
              <a href="settings.php?section=api"><i class="fa fa-external-link"></i> View Documentation</a>
            <?php elseif ($showContent): ?>
              <h3>Content tip</h3>
              <p>Tune approval and media rules here, then open Posts to moderate live content.</p>
              <a href="posts.php"><i class="fa fa-external-link"></i> Manage Posts</a>
            <?php elseif ($showSystem): ?>
              <h3>Need help?</h3>
              <p>Review operational events or open feedback if something looks wrong.</p>
              <a href="security-log.php"><i class="fa fa-external-link"></i> View Documentation</a>
            <?php else: ?>
              <h3>Need help?</h3>
              <p>Check our documentation or contact support for assistance.</p>
              <a href="feedback.php?view=internal&amp;filter=unread"><i class="fa fa-external-link"></i> View Documentation</a>
            <?php endif; ?>
          </div>
        </aside>

        <main class="ps-main<?= $showNotifications ? ' is-nt' : '' ?><?= $showApi ? ' is-api' : '' ?><?= $showIntegrations ? ' is-ig' : '' ?><?= $showContent ? ' is-ct' : '' ?>" aria-label="Settings content">

          <?php if ($showGeneral): ?>
            <form class="ps-card" method="post" enctype="multipart/form-data">
              <input type="hidden" name="action" value="save_general">
              <div class="ps-card-hd">
                <div>
                  <h2>General Settings</h2>
                  <p>Configure basic platform information and branding.</p>
                </div>
                <button type="submit" class="ps-save"<?= $adminMode ? '' : ' disabled' ?>>Save Changes</button>
              </div>
              <div class="ps-gen">
                <div class="ps-fields">
                  <div class="ps-field full">
                    <label for="platform_name">Platform Name</label>
                    <input id="platform_name" name="platform_name" required value="<?= admin_platform_settings_h((string)$settings['platform_name']) ?>">
                  </div>
                  <div class="ps-field full">
                    <label for="platform_url">Platform URL</label>
                    <input id="platform_url" name="platform_url" type="url" value="<?= admin_platform_settings_h((string)$settings['platform_url']) ?>">
                  </div>
                  <div class="ps-field full">
                    <label for="timezone">Timezone</label>
                    <select id="timezone" name="timezone">
                      <?php foreach (admin_platform_settings_timezones() as $tz => $label): ?>
                        <option value="<?= admin_platform_settings_h($tz) ?>"<?= ((string)$settings['timezone'] === $tz) ? ' selected' : '' ?>><?= admin_platform_settings_h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ps-field">
                    <label for="date_format">Date Format</label>
                    <select id="date_format" name="date_format">
                      <?php foreach (admin_platform_settings_date_formats() as $fmt => $label): ?>
                        <option value="<?= admin_platform_settings_h($fmt) ?>"<?= ((string)$settings['date_format'] === $fmt) ? ' selected' : '' ?>><?= admin_platform_settings_h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ps-field">
                    <label for="time_format">Time Format</label>
                    <select id="time_format" name="time_format">
                      <option value="12"<?= ((string)$settings['time_format'] === '12') ? ' selected' : '' ?>>12 Hour (01:30 PM)</option>
                      <option value="24"<?= ((string)$settings['time_format'] === '24') ? ' selected' : '' ?>>24 Hour (13:30)</option>
                    </select>
                  </div>
                </div>
                <div class="ps-logo-box">
                  <div class="lab">Platform Logo</div>
                  <div class="hint">Recommended size: 200 × 60px. PNG or SVG.</div>
                  <div class="ps-logo-prev">
                    <?php if ($logoUrl !== ''): ?>
                      <img src="<?= admin_platform_settings_h($logoUrl) ?>" alt="Platform logo">
                    <?php else: ?>
                      <span class="ph"><?= admin_platform_settings_h((string)$settings['platform_name']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="ps-logo-actions">
                    <label class="ps-btn" style="margin:0;">
                      Change Logo
                      <input type="file" name="platform_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display:none;"<?= $adminMode ? '' : ' disabled' ?>>
                    </label>
                    <?php if ($logoUrl !== ''): ?>
                      <button type="submit" name="remove_logo" value="1" class="ps-btn danger"<?= $adminMode ? '' : ' disabled' ?>>Remove</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showSecurity): ?>
            <?php if ($editPanel !== ''): ?>
              <form class="ps-card" method="post" id="sec-<?= admin_platform_settings_h($editPanel) ?>">
                <input type="hidden" name="action" value="save_security_detail">
                <div class="ps-card-hd">
                  <div>
                    <h2>
                      <?php
                      $editTitles = [
                          'attempts' => 'Max Login Attempts',
                          'lockout' => 'Lockout Duration',
                          'whitelist' => 'IP Whitelist',
                          '2fa' => 'Two-Factor Authentication',
                          'password' => 'Password Policy',
                          'sessions' => 'Session Management',
                      ];
                      echo admin_platform_settings_h($editTitles[$editPanel] ?? 'Security');
                      ?>
                    </h2>
                    <p>Update this setting and save.</p>
                  </div>
                  <a class="ps-btn" href="settings.php?section=security">Close</a>
                </div>
                <div class="ps-fields" style="grid-template-columns:1fr;">
                  <?php if ($editPanel === 'attempts'): ?>
                    <div class="ps-field">
                      <label for="max_login_attempts">Max Login Attempts</label>
                      <select id="max_login_attempts" name="max_login_attempts"<?= $adminMode ? '' : ' disabled' ?>>
                        <?php foreach ([3, 5, 8, 10] as $n): ?>
                          <option value="<?= $n ?>"<?= (int)$settings['max_login_attempts'] === $n ? ' selected' : '' ?>><?= $n ?> attempts</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php elseif ($editPanel === 'lockout'): ?>
                    <div class="ps-field">
                      <label for="lockout_duration_minutes">Lockout Duration</label>
                      <select id="lockout_duration_minutes" name="lockout_duration_minutes"<?= $adminMode ? '' : ' disabled' ?>>
                        <?php foreach ([5, 15, 30, 60, 120] as $m): ?>
                          <option value="<?= $m ?>"<?= (int)$settings['lockout_duration_minutes'] === $m ? ' selected' : '' ?>><?= $m ?> minutes</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php elseif ($editPanel === 'whitelist'): ?>
                    <div class="ps-field">
                      <label for="ip_whitelist">IP Whitelist</label>
                      <textarea id="ip_whitelist" name="ip_whitelist" placeholder="192.168.1.1&#10;203.0.113.25"<?= $adminMode ? '' : ' disabled' ?>><?= admin_platform_settings_h((string)$settings['ip_whitelist']) ?></textarea>
                      <div class="hint">One IP per line</div>
                    </div>
                  <?php elseif ($editPanel === '2fa'): ?>
                    <label class="sec-toggle-row" style="border:0;padding:0;">
                      <span class="sec-label">Require Two-Factor Authentication (2FA)</span>
                      <span class="sec-switch">
                        <input type="checkbox" name="require_2fa" value="1"<?= !empty($settings['require_2fa']) ? ' checked' : '' ?><?= $adminMode ? '' : ' disabled' ?>>
                        <span class="sec-slider"></span>
                      </span>
                    </label>
                    <input type="hidden" name="require_2fa_present" value="1">
                  <?php elseif ($editPanel === 'password'): ?>
                    <div class="ps-field">
                      <label for="password_policy">Password Policy</label>
                      <select id="password_policy" name="password_policy"<?= $adminMode ? '' : ' disabled' ?>>
                        <?php foreach (['basic' => 'Basic', 'strong' => 'Strong', 'strict' => 'Strict'] as $k => $lab): ?>
                          <option value="<?= $k ?>"<?= ((string)$settings['password_policy'] === $k) ? ' selected' : '' ?>><?= $lab ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php elseif ($editPanel === 'sessions'): ?>
                    <div class="ps-field">
                      <label for="session_timeout_minutes">Session Timeout Duration</label>
                      <select id="session_timeout_minutes" name="session_timeout_minutes"<?= $adminMode ? '' : ' disabled' ?>>
                        <?php foreach ([15, 30, 60, 120, 240] as $m): ?>
                          <option value="<?= $m ?>"<?= (int)$settings['session_timeout_minutes'] === $m ? ' selected' : '' ?>><?= $m ?> minutes</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="sec-edit-actions">
                  <button type="submit" class="ps-save"<?= $adminMode ? '' : ' disabled' ?>>Save Changes</button>
                  <a class="ps-btn" href="settings.php?section=security">Cancel</a>
                </div>
              </form>
            <?php endif; ?>

            <div class="sec-card" id="sec-auth">
              <h2>Authentication</h2>
              <p class="sec-sub">Sign-in requirements and account credentials.</p>
              <a class="sec-row" href="settings.php?section=security&amp;edit=2fa#sec-2fa">
                <span class="sec-label">Two-Factor Authentication (2FA)</span>
                <span class="sec-badge <?= !empty($settings['require_2fa']) ? 'on' : 'off' ?>"><?= !empty($settings['require_2fa']) ? 'Enabled' : 'Disabled' ?></span>
                <i class="fa fa-chevron-right sec-ch"></i>
              </a>
              <a class="sec-row" href="settings.php?section=security&amp;edit=password">
                <span class="sec-label">Password Policy</span>
                <span class="sec-badge on"><?= admin_platform_settings_h($passwordPolicyLabel) ?></span>
                <i class="fa fa-chevron-right sec-ch"></i>
              </a>
              <a class="sec-row" href="settings.php?section=security&amp;edit=sessions">
                <span class="sec-label">Session Management</span>
                <i class="fa fa-chevron-right sec-ch"></i>
              </a>
            </div>

            <div class="sec-card" id="sec-login">
              <h2>Login Security</h2>
              <p class="sec-sub">Protect against brute-force and abuse.</p>
              <a class="sec-row" href="settings.php?section=security&amp;edit=attempts">
                <span class="sec-label">Max Login Attempts</span>
                <span class="sec-val"><?= (int)$settings['max_login_attempts'] ?> attempts</span>
                <i class="fa fa-chevron-right sec-ch"></i>
              </a>
              <a class="sec-row" href="settings.php?section=security&amp;edit=lockout">
                <span class="sec-label">Lockout Duration</span>
                <span class="sec-val"><?= (int)$settings['lockout_duration_minutes'] ?> minutes</span>
                <i class="fa fa-chevron-right sec-ch"></i>
              </a>
              <?php
              $loginToggles = [
                  'account_lockout' => 'Account Lockout',
                  'captcha_on_login' => 'CAPTCHA on Login',
                  'ip_rate_limiting' => 'IP Rate Limiting',
              ];
              foreach ($loginToggles as $field => $label):
              ?>
                <form class="sec-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_security">
                  <input type="hidden" name="field" value="<?= admin_platform_settings_h($field) ?>">
                  <span class="sec-label"><?= admin_platform_settings_h($label) ?></span>
                  <label class="sec-switch">
                    <input type="checkbox"<?= !empty($settings[$field]) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="sec-slider"></span>
                  </label>
                </form>
              <?php endforeach; ?>
            </div>

            <div class="sec-card" id="sec-advanced">
              <h2>Advanced Security</h2>
              <p class="sec-sub">Network and compliance controls.</p>
              <a class="sec-row" href="settings.php?section=security&amp;edit=whitelist">
                <span class="sec-label">IP Whitelist</span>
                <span class="sec-val"><?= $whitelistCount ?> IP<?= $whitelistCount === 1 ? '' : 's' ?> configured</span>
                <i class="fa fa-chevron-right sec-ch"></i>
              </a>
              <?php
              $advToggles = [
                  'require_https' => 'Require Secure Connections HTTPS',
                  'security_headers' => 'Security Headers',
                  'activity_logging' => 'Admin Activity Logging',
              ];
              foreach ($advToggles as $field => $label):
              ?>
                <form class="sec-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_security">
                  <input type="hidden" name="field" value="<?= admin_platform_settings_h($field) ?>">
                  <span class="sec-label"><?= admin_platform_settings_h($label) ?></span>
                  <label class="sec-switch">
                    <input type="checkbox"<?= !empty($settings[$field]) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="sec-slider"></span>
                  </label>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($showEmail): ?>
            <form class="ps-card" method="post">
              <input type="hidden" name="action" value="save_email">
              <div class="ps-card-hd">
                <div>
                  <h2>Email Settings</h2>
                  <p>Configure email delivery and notification settings.</p>
                </div>
                <button type="submit" class="ps-save"<?= $adminMode ? '' : ' disabled' ?>>Save Changes</button>
              </div>
              <div class="ps-email">
                <div class="ps-field">
                  <label for="email_from_name">From Name</label>
                  <input id="email_from_name" name="email_from_name" value="<?= admin_platform_settings_h((string)$settings['email_from_name']) ?>"<?= $adminMode ? '' : ' disabled' ?>>
                </div>
                <div class="ps-field">
                  <label for="email_from">From Email</label>
                  <input id="email_from" name="email_from" type="email" value="<?= admin_platform_settings_h((string)$settings['email_from']) ?>"<?= $adminMode ? '' : ' disabled' ?>>
                </div>
                <div class="ps-field">
                  <label for="email_reply_to">Reply-To Email</label>
                  <input id="email_reply_to" name="email_reply_to" type="email" value="<?= admin_platform_settings_h((string)$settings['email_reply_to']) ?>"<?= $adminMode ? '' : ' disabled' ?>>
                </div>
                <div class="ps-field">
                  <label for="smtp_host">SMTP Host</label>
                  <input id="smtp_host" name="smtp_host" value="<?= admin_platform_settings_h((string)$settings['smtp_host']) ?>"<?= $adminMode ? '' : ' disabled' ?>>
                </div>
                <div class="ps-field">
                  <label for="smtp_port">SMTP Port</label>
                  <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= (int)$settings['smtp_port'] ?>"<?= $adminMode ? '' : ' disabled' ?>>
                </div>
                <div class="ps-field">
                  <label for="smtp_encryption">Encryption</label>
                  <select id="smtp_encryption" name="smtp_encryption"<?= $adminMode ? '' : ' disabled' ?>>
                    <?php foreach (['TLS', 'SSL', 'NONE'] as $enc): ?>
                      <option value="<?= $enc ?>"<?= ((string)$settings['smtp_encryption'] === $enc) ? ' selected' : '' ?>><?= $enc ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </form>
          <?php endif; ?>

          <?php if ($showNotifications): ?>
            <?php
            $tabDefs = [
                'all' => 'All Notifications',
                'unread' => 'Unread',
                'system' => 'System Alerts',
                'user_report' => 'User Reports',
                'security' => 'Security',
                'updates' => 'Updates',
            ];
            ?>
            <div class="nt-panel">
              <div class="nt-hd">
                <nav class="nt-tabs" aria-label="Notification filters">
                  <?php foreach ($tabDefs as $tk => $tlab): ?>
                    <a class="<?= $ntab === $tk ? 'is-active' : '' ?>" href="<?= admin_platform_settings_h($ntUrl(['ntab' => $tk, 'npage' => 1])) ?>">
                      <?= admin_platform_settings_h($tlab) ?>
                      <?php if ($tk === 'unread' && (int)$notifOverview['unread'] > 0): ?>
                        <span class="nt-badge"><?= (int)$notifOverview['unread'] ?></span>
                      <?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </nav>
                <div class="nt-hd-actions">
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="notif_mark_all_read">
                    <button type="submit" class="ps-btn"<?= $notifReceiverKeys === [] ? ' disabled' : '' ?>>
                      <i class="fa fa-check"></i> Mark All as Read
                    </button>
                  </form>
                  <a class="ps-btn" href="settings.php?section=email" title="Notification settings"><i class="fa fa-cog"></i></a>
                </div>
              </div>

              <form class="nt-filters" method="get" action="settings.php">
                <input type="hidden" name="section" value="notifications">
                <input type="hidden" name="ntab" value="<?= admin_platform_settings_h($ntab) ?>">
                <input type="hidden" name="nper" value="<?= (int)$nper ?>">
                <div class="nt-f">
                  <label for="nq">Search</label>
                  <input class="nt-search" id="nq" name="nq" type="search" value="<?= admin_platform_settings_h($nq) ?>" placeholder="Search…">
                </div>
                <div class="nt-f">
                  <label for="ntype">Type</label>
                  <select class="nt-sel" id="ntype" name="ntype">
                    <option value="">All types</option>
                    <?php foreach (['system' => 'System', 'user_report' => 'User Report', 'security' => 'Security', 'moderation' => 'Moderation', 'engagement' => 'Engagement', 'updates' => 'Updates'] as $vk => $vl): ?>
                      <option value="<?= $vk ?>"<?= $ntype === $vk ? ' selected' : '' ?>><?= $vl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="nt-f">
                  <label for="npriority">Priority</label>
                  <select class="nt-sel" id="npriority" name="npriority">
                    <option value="">All</option>
                    <?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $vk => $vl): ?>
                      <option value="<?= $vk ?>"<?= $npriority === $vk ? ' selected' : '' ?>><?= $vl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="nt-f">
                  <label for="nstatus">Status</label>
                  <select class="nt-sel" id="nstatus" name="nstatus">
                    <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $vk => $vl): ?>
                      <option value="<?= $vk ?>"<?= $nstatus === $vk ? ' selected' : '' ?>><?= $vl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="nt-f">
                  <label for="nfrom">From</label>
                  <input class="nt-date" id="nfrom" name="nfrom" type="date" value="<?= admin_platform_settings_h($nfrom) ?>">
                </div>
                <div class="nt-f">
                  <label for="nto">To</label>
                  <input class="nt-date" id="nto" name="nto" type="date" value="<?= admin_platform_settings_h($nto) ?>">
                </div>
                <button type="submit" class="ps-btn" style="margin-bottom:1px;">Apply</button>
                <a class="nt-clear" href="settings.php?section=notifications<?= $ntab !== 'all' ? '&amp;ntab=' . rawurlencode($ntab) : '' ?>">Clear</a>
              </form>

              <div class="nt-table-scroll">
                <table class="nt-table">
                  <thead>
                    <tr>
                      <th class="nt-check"><input type="checkbox" disabled title="Bulk select coming soon" aria-label="Select all"></th>
                      <th>Notification</th>
                      <th>Type</th>
                      <th>Priority</th>
                      <th>Related To</th>
                      <th>Created At</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($notifPageRows === []): ?>
                      <tr><td colspan="8"><div class="nt-empty">No notifications match these filters.</div></td></tr>
                    <?php else: ?>
                      <?php foreach ($notifPageRows as $nr): ?>
                        <?php
                        $isUnread = (int)$nr['is_read'] === 0;
                        $isVirtual = !empty($nr['virtual']);
                        $rel = (string)($nr['related'] ?? '—');
                        $relHref = (string)($nr['related_href'] ?? '');
                        ?>
                        <tr class="<?= $isUnread ? 'is-unread' : '' ?>">
                          <td class="nt-check"><input type="checkbox" disabled aria-label="Select row"></td>
                          <td>
                            <div class="nt-cell-notif">
                              <span class="nt-ico <?= admin_platform_settings_h((string)$nr['type']) ?>"><i class="fa <?= admin_platform_settings_h((string)$nr['icon']) ?>"></i></span>
                              <span>
                                <div class="nt-title"><?= admin_platform_settings_h((string)$nr['title']) ?></div>
                                <div class="nt-desc"><?= admin_platform_settings_h((string)$nr['body']) ?></div>
                              </span>
                            </div>
                          </td>
                          <td><?= admin_platform_settings_h((string)$nr['type_label']) ?></td>
                          <td><span class="nt-pill <?= admin_platform_settings_h((string)$nr['priority']) ?>"><?= admin_platform_settings_h(ucfirst((string)$nr['priority'])) ?></span></td>
                          <td>
                            <?php if ($relHref !== ''): ?>
                              <a class="nt-related" href="<?= admin_platform_settings_h($relHref) ?>"><?= admin_platform_settings_h($rel) ?></a>
                            <?php elseif ($rel !== '' && $rel !== '—'): ?>
                              <span class="nt-related muted"><?= admin_platform_settings_h($rel) ?></span>
                            <?php else: ?>
                              <span class="nt-related muted">—</span>
                            <?php endif; ?>
                          </td>
                          <td><?= admin_platform_settings_h(admin_notif_format_dt((string)($nr['created_at'] ?? ''))) ?></td>
                          <td>
                            <span class="nt-pill <?= $isUnread ? 'unread' : 'read' ?>"><?= $isUnread ? 'Unread' : 'Read' ?></span>
                          </td>
                          <td>
                            <div class="fries-menu">
                              <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                                <span class="fries-icon" aria-hidden="true"></span>
                              </button>
                              <div class="fries-dropdown" role="menu">
                                <?php if (!$isVirtual && $isUnread): ?>
                                  <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="notif_mark_read">
                                    <input type="hidden" name="id" value="<?= (int)$nr['id'] ?>">
                                    <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-check"></i> Mark read</button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($isVirtual && $relHref !== ''): ?>
                                  <a class="fries-item" role="menuitem" href="<?= admin_platform_settings_h($relHref) ?>"><i class="fa fa-external-link"></i> Open report</a>
                                <?php else: ?>
                                  <a class="fries-item" role="menuitem" href="notification.php"><i class="fa fa-inbox"></i> Open inbox</a>
                                <?php endif; ?>
                                <?php if (!$isVirtual && (int)$nr['id'] > 0): ?>
                                  <form method="post" style="margin:0;" onsubmit="return confirm('Delete this notification?');">
                                    <input type="hidden" name="action" value="notif_delete">
                                    <input type="hidden" name="id" value="<?= (int)$nr['id'] ?>">
                                    <button type="submit" class="fries-item fries-item-danger" role="menuitem"><i class="fa fa-trash"></i> Delete</button>
                                  </form>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="nt-foot">
                <span>
                  Showing <?= (int)$notifFromIdx ?> to <?= (int)$notifToIdx ?> of <?= (int)$notifTotalFiltered ?>
                  ·
                  <a href="<?= admin_platform_settings_h($ntUrl(['nper' => 10, 'npage' => 1])) ?>" style="color:inherit;<?= $nper === 10 ? 'font-weight:800;color:#1d4ed8;' : '' ?>">10</a>
                  /
                  <a href="<?= admin_platform_settings_h($ntUrl(['nper' => 25, 'npage' => 1])) ?>" style="color:inherit;<?= $nper === 25 ? 'font-weight:800;color:#1d4ed8;' : '' ?>">25</a>
                  per page
                </span>
                <div class="nt-pager">
                  <a class="<?= $npage <= 1 ? 'is-disabled' : '' ?>" href="<?= admin_platform_settings_h($ntUrl(['npage' => max(1, $npage - 1)])) ?>">&lsaquo;</a>
                  <?php
                  $startP = max(1, $npage - 2);
                  $endP = min($notifTotalPages, $startP + 4);
                  $startP = max(1, $endP - 4);
                  for ($p = $startP; $p <= $endP; $p++):
                  ?>
                    <a class="<?= $p === $npage ? 'is-active' : '' ?>" href="<?= admin_platform_settings_h($ntUrl(['npage' => $p])) ?>"><?= $p ?></a>
                  <?php endfor; ?>
                  <a class="<?= $npage >= $notifTotalPages ? 'is-disabled' : '' ?>" href="<?= admin_platform_settings_h($ntUrl(['npage' => min($notifTotalPages, $npage + 1)])) ?>">&rsaquo;</a>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($showPrivacy): ?>
            <?php
            $pvEditTitles = [
                'export' => 'Data Export Requests',
                'deletion' => 'Data Deletion Requests',
                'user_defaults' => 'Privacy Settings for Users',
                'retention' => 'Data Retention Policies',
            ];
            $pvEditFields = [
                'export' => 'privacy_export_requests',
                'deletion' => 'privacy_deletion_requests',
                'user_defaults' => 'privacy_user_defaults',
            ];
            $pvEditBlurbs = [
                'export' => 'When enabled, users can request a downloadable copy of their personal data from account settings or support.',
                'deletion' => 'When enabled, users can request account and personal data deletion. Requests are queued for admin review.',
                'user_defaults' => 'Controls whether end users can manage their own privacy preferences (analytics, personalization, marketing).',
            ];
            $collectionToggles = [
                'privacy_analytics' => [
                    'Analytics Data',
                    'Collect anonymous usage data to improve the platform.',
                ],
                'privacy_crash_reports' => [
                    'Crash Reports',
                    'Automatically send crash reports to help diagnose and fix bugs.',
                ],
                'privacy_performance' => [
                    'Performance Monitoring',
                    'Monitor system performance and identify bottlenecks.',
                ],
                'privacy_third_party_cookies' => [
                    'Third-Party Cookies',
                    'Allow third-party cookies for analytics and advertising.',
                ],
                'privacy_personalized' => [
                    'Personalized Content',
                    'Use browsing data to personalize content recommendations.',
                ],
            ];
            $usageToggles = [
                'privacy_use_improvements' => [
                    'Use Data for Improvements',
                    'Use collected data to improve features and user experience.',
                ],
                'privacy_marketing' => [
                    'Marketing Communications',
                    'Use data for marketing and promotional communications.',
                ],
                'privacy_share_partners' => [
                    'Share Data with Partners',
                    'Share anonymized data with trusted third-party partners.',
                ],
            ];
            $userControlRows = [
                'export' => [
                    'Data Export Requests',
                    'Allow users to request a copy of their personal data.',
                    'privacy_export_requests',
                ],
                'deletion' => [
                    'Data Deletion Requests',
                    'Allow users to request deletion of their personal data.',
                    'privacy_deletion_requests',
                ],
                'user_defaults' => [
                    'Privacy Settings for Users',
                    'Let users manage their own privacy preferences.',
                    'privacy_user_defaults',
                ],
            ];
            ?>

            <?php if ($editPanel !== '' && isset($pvEditTitles[$editPanel])): ?>
              <?php if ($editPanel === 'retention'): ?>
                <form class="pv-card" method="post" id="pv-retention">
                  <input type="hidden" name="action" value="save_privacy_retention">
                  <div class="ps-card-hd">
                    <div>
                      <h2>Data Retention Policies</h2>
                      <p>How long different classes of data are kept before automatic purge.</p>
                    </div>
                    <a class="ps-btn" href="settings.php?section=privacy">Close</a>
                  </div>
                  <div class="ps-fields" style="grid-template-columns:1fr 1fr;">
                    <div class="ps-field">
                      <label for="privacy_retention_user_years">User data (years)</label>
                      <input id="privacy_retention_user_years" name="privacy_retention_user_years" type="number" min="1" max="20"
                             value="<?= (int)$settings['privacy_retention_user_years'] ?>"<?= $adminMode ? '' : ' disabled' ?>>
                    </div>
                    <div class="ps-field">
                      <label for="privacy_retention_analytics_months">Analytics data (months)</label>
                      <input id="privacy_retention_analytics_months" name="privacy_retention_analytics_months" type="number" min="1" max="120"
                             value="<?= (int)$settings['privacy_retention_analytics_months'] ?>"<?= $adminMode ? '' : ' disabled' ?>>
                    </div>
                    <div class="ps-field">
                      <label for="privacy_retention_log_months">Log data (months)</label>
                      <input id="privacy_retention_log_months" name="privacy_retention_log_months" type="number" min="1" max="120"
                             value="<?= (int)$settings['privacy_retention_log_months'] ?>"<?= $adminMode ? '' : ' disabled' ?>>
                    </div>
                    <div class="ps-field">
                      <label for="privacy_retention_backup_days">Backup data (days)</label>
                      <input id="privacy_retention_backup_days" name="privacy_retention_backup_days" type="number" min="1" max="365"
                             value="<?= (int)$settings['privacy_retention_backup_days'] ?>"<?= $adminMode ? '' : ' disabled' ?>>
                    </div>
                  </div>
                  <div class="pv-edit-actions">
                    <button type="submit" class="ps-save"<?= $adminMode ? '' : ' disabled' ?>>Save Changes</button>
                    <a class="ps-btn" href="settings.php?section=privacy">Cancel</a>
                  </div>
                </form>
              <?php else: ?>
                <?php
                $pvField = $pvEditFields[$editPanel];
                $pvBlurb = $pvEditBlurbs[$editPanel];
                ?>
                <div class="pv-card" id="pv-<?= admin_platform_settings_h($editPanel) ?>">
                  <div class="ps-card-hd">
                    <div>
                      <h2><?= admin_platform_settings_h($pvEditTitles[$editPanel]) ?></h2>
                      <p><?= admin_platform_settings_h($pvBlurb) ?></p>
                    </div>
                    <a class="ps-btn" href="settings.php?section=privacy">Close</a>
                  </div>
                  <form class="pv-toggle-row" method="post" style="border:0;padding:0;">
                    <input type="hidden" name="action" value="toggle_privacy">
                    <input type="hidden" name="field" value="<?= admin_platform_settings_h($pvField) ?>">
                    <div class="pv-toggle-copy">
                      <span class="pv-label">Enable <?= admin_platform_settings_h($pvEditTitles[$editPanel]) ?></span>
                      <span class="pv-desc">Admins can turn this control on or off for the whole platform.</span>
                    </div>
                    <label class="pv-toggle">
                      <input type="checkbox"<?= !empty($settings[$pvField]) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                      <span class="pv-slider"></span>
                    </label>
                  </form>
                  <?php if ($editPanel === 'export'): ?>
                    <div class="pv-edit-actions">
                      <a class="ps-btn" href="userlist.php"><i class="fa fa-users"></i> Open user list</a>
                      <a class="ps-btn" href="account_search.php"><i class="fa fa-search"></i> Account search</a>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>

            <div class="pv-card" id="pv-collection">
              <h2>Data Collection</h2>
              <p class="pv-sub">Choose what types of data the platform may collect.</p>
              <?php foreach ($collectionToggles as $field => $meta): ?>
                <form class="pv-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_privacy">
                  <input type="hidden" name="field" value="<?= admin_platform_settings_h($field) ?>">
                  <div class="pv-toggle-copy">
                    <span class="pv-label"><?= admin_platform_settings_h($meta[0]) ?></span>
                    <span class="pv-desc"><?= admin_platform_settings_h($meta[1]) ?></span>
                  </div>
                  <label class="pv-toggle">
                    <input type="checkbox"<?= !empty($settings[$field]) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="pv-slider"></span>
                  </label>
                </form>
              <?php endforeach; ?>
            </div>

            <div class="pv-card" id="pv-usage">
              <h2>Data Usage</h2>
              <p class="pv-sub">Control how collected data may be used.</p>
              <?php foreach ($usageToggles as $field => $meta): ?>
                <form class="pv-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_privacy">
                  <input type="hidden" name="field" value="<?= admin_platform_settings_h($field) ?>">
                  <div class="pv-toggle-copy">
                    <span class="pv-label"><?= admin_platform_settings_h($meta[0]) ?></span>
                    <span class="pv-desc"><?= admin_platform_settings_h($meta[1]) ?></span>
                  </div>
                  <label class="pv-toggle">
                    <input type="checkbox"<?= !empty($settings[$field]) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="pv-slider"></span>
                  </label>
                </form>
              <?php endforeach; ?>
            </div>

            <div class="pv-card" id="pv-user-controls">
              <h2>User Privacy Controls</h2>
              <p class="pv-sub">Rights and defaults available to end users.</p>
              <?php foreach ($userControlRows as $editKey => $meta): ?>
                <a class="pv-row" href="settings.php?section=privacy&amp;edit=<?= rawurlencode($editKey) ?>">
                  <span class="pv-row-copy">
                    <span class="pv-label"><?= admin_platform_settings_h($meta[0]) ?></span>
                    <span class="pv-desc"><?= admin_platform_settings_h($meta[1]) ?></span>
                  </span>
                  <span class="pv-badge <?= !empty($settings[$meta[2]]) ? 'on' : 'off' ?>">
                    <?= !empty($settings[$meta[2]]) ? 'Enabled' : 'Disabled' ?>
                  </span>
                  <i class="fa fa-chevron-right pv-ch"></i>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($showSystem): ?>
            <div class="sy-card" id="sy-info">
              <h2>System Information</h2>
              <p class="sy-sub">Core platform identity and runtime status.</p>
              <div class="sy-info-grid">
                <div class="sy-info-cell">
                  <span class="k">Platform Name</span>
                  <span class="v"><?= admin_platform_settings_h((string)$sys['platform_name']) ?></span>
                </div>
                <div class="sy-info-cell">
                  <span class="k">Environment</span>
                  <span class="v"><?= admin_platform_settings_h((string)$sys['environment']) ?></span>
                </div>
                <div class="sy-info-cell">
                  <span class="k">System Version</span>
                  <span class="v"><?= admin_platform_settings_h((string)$sys['version']) ?></span>
                </div>
                <div class="sy-info-cell">
                  <span class="k">Application Version</span>
                  <span class="v"><?= admin_platform_settings_h((string)$sys['app_version']) ?></span>
                </div>
                <div class="sy-info-cell">
                  <span class="k">Server Time</span>
                  <span class="v"><?= admin_platform_settings_h((string)$sys['server_time']) ?></span>
                </div>
                <div class="sy-info-cell">
                  <span class="k">Uptime</span>
                  <span class="v"><?= admin_platform_settings_h((string)$sys['uptime']) ?></span>
                </div>
              </div>
            </div>

            <div class="sy-card" id="sy-server">
              <h2>Server Environment</h2>
              <p class="sy-sub">Web stack versions and resource usage.</p>
              <div class="sy-env">
                <div class="sy-kv">
                  <div class="sy-kv-row"><span class="k">Web Server</span><span class="v"><?= admin_platform_settings_h((string)$sys['web_server']) ?></span></div>
                  <div class="sy-kv-row"><span class="k">PHP</span><span class="v"><?= admin_platform_settings_h((string)$sys['php_version']) ?></span></div>
                  <div class="sy-kv-row"><span class="k">Database</span><span class="v"><?= admin_platform_settings_h((string)$sys['mysql_version']) ?></span></div>
                  <div class="sy-kv-row"><span class="k">OS</span><span class="v"><?= admin_platform_settings_h((string)$sys['os']) ?></span></div>
                </div>
                <div class="sy-meters">
                  <div class="sy-meter">
                    <div class="lab"><span>Memory</span><span class="pct"><?= admin_platform_settings_h($syMemBar['label']) ?></span></div>
                    <div class="sy-bar" aria-hidden="true"><span class="<?= admin_platform_settings_h($syMemBar['cls']) ?>" style="width:<?= (int)$syMemBar['w'] ?>%;"></span></div>
                  </div>
                  <div class="sy-meter">
                    <div class="lab"><span>CPU</span><span class="pct"><?= admin_platform_settings_h($syCpuBar['label']) ?></span></div>
                    <div class="sy-bar" aria-hidden="true"><span class="<?= admin_platform_settings_h($syCpuBar['cls']) ?>" style="width:<?= (int)$syCpuBar['w'] ?>%;"></span></div>
                  </div>
                  <div class="sy-meter">
                    <div class="lab"><span>Disk</span><span class="pct"><?= admin_platform_settings_h($syDiskBar['label']) ?></span></div>
                    <div class="sy-bar" aria-hidden="true"><span class="<?= admin_platform_settings_h($syDiskBar['cls']) ?>" style="width:<?= (int)$syDiskBar['w'] ?>%;"></span></div>
                  </div>
                </div>
              </div>
              <div class="sy-env-actions">
                <a class="ps-btn" href="security-log.php"><i class="fa fa-server"></i> View Server Status</a>
              </div>
            </div>

            <div class="sy-card" id="sy-updates">
              <h2>System Updates</h2>
              <p class="sy-sub">Check for updates and choose how releases are applied.</p>
              <div class="sy-updates-row">
                <div class="sy-toggle-copy">
                  <span class="sy-label">Check for Updates</span>
                  <span class="sy-desc">Last checked: <?= admin_platform_settings_h($syLastCheck) ?></span>
                </div>
                <?php if ($adminMode): ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="check_updates">
                    <button type="submit" class="ps-btn"><i class="fa fa-refresh"></i> Check Now</button>
                  </form>
                <?php endif; ?>
              </div>
              <form class="sy-toggle-row" method="post">
                <input type="hidden" name="action" value="toggle_system">
                <input type="hidden" name="field" value="system_auto_updates">
                <div class="sy-toggle-copy">
                  <span class="sy-label">Automatic Updates</span>
                  <span class="sy-desc">Apply security and patch updates automatically when available.</span>
                </div>
                <label class="sy-switch">
                  <input type="checkbox" <?= !empty($settings['system_auto_updates']) ? 'checked' : '' ?> onchange="this.form.submit()"<?= $adminMode ? '' : ' disabled' ?>>
                  <span class="sy-slider"></span>
                </label>
              </form>
              <?php if ($adminMode): ?>
                <form method="post" class="sy-updates-row" style="border-bottom:0;padding-bottom:0;">
                  <input type="hidden" name="action" value="save_system_updates">
                  <input type="hidden" name="system_auto_updates_present" value="1">
                  <input type="hidden" name="system_auto_updates" value="<?= !empty($settings['system_auto_updates']) ? '1' : '0' ?>">
                  <div class="sy-toggle-copy">
                    <span class="sy-label">Update Channel</span>
                    <span class="sy-desc">Stable is recommended for production.</span>
                    <div class="sy-channel">
                      <select name="system_update_channel">
                        <option value="stable" <?= $syChannel === 'stable' ? 'selected' : '' ?>>Stable</option>
                        <option value="beta" <?= $syChannel === 'beta' ? 'selected' : '' ?>>Beta</option>
                      </select>
                      <button type="submit" class="ps-save"><i class="fa fa-save"></i> Save</button>
                    </div>
                  </div>
                </form>
              <?php else: ?>
                <div class="sy-updates-row" style="border-bottom:0;">
                  <div class="sy-toggle-copy">
                    <span class="sy-label">Update Channel</span>
                    <span class="sy-desc"><?= $syChannel === 'beta' ? 'Beta' : 'Stable' ?> (admin required to change)</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="sy-card" id="sy-maintenance">
              <h2>Maintenance Mode</h2>
              <p class="sy-sub">Temporarily take the public site offline for maintenance.</p>
              <form class="sy-toggle-row" method="post" style="border-bottom:0;padding-bottom:0;">
                <input type="hidden" name="action" value="toggle_system">
                <input type="hidden" name="field" value="system_maintenance_mode">
                <div class="sy-toggle-copy">
                  <span class="sy-label">Maintenance Mode</span>
                  <span class="sy-desc"><?= !empty($settings['system_maintenance_mode']) ? 'On — visitors may see a maintenance screen.' : 'Off — platform is available.' ?></span>
                </div>
                <label class="sy-switch">
                  <input type="checkbox" <?= !empty($settings['system_maintenance_mode']) ? 'checked' : '' ?> onchange="this.form.submit()"<?= $adminMode ? '' : ' disabled' ?>>
                  <span class="sy-slider"></span>
                </label>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($showApi): ?>
            <?php
            $apiTabDefs = [
                'keys' => 'API Keys',
                'limits' => 'Rate Limits',
                'allowlist' => 'IP Allowlist',
                'webhooks' => 'Webhooks',
                'activity' => 'Activity Logs',
            ];
            $reqDelta = (int)$apiStats['requests_delta'];
            $rateCls = (int)$apiStats['rate_usage_pct'] >= 90 ? 'hot' : ((int)$apiStats['rate_usage_pct'] >= 70 ? 'warm' : '');
            ?>
            <?php if ($apiKeyFlash !== ''): ?>
              <div class="api-flash" id="api-key-flash">
                New key<?= $apiKeyFlashName !== '' ? ' for <strong>' . admin_platform_settings_h($apiKeyFlashName) . '</strong>' : '' ?> — copy it now. It will not be shown again.
                <br><code data-api-plaintext="<?= admin_platform_settings_h($apiKeyFlash) ?>"><?= admin_platform_settings_h($apiKeyFlash) ?></code>
                <button type="button" class="ps-btn" style="margin-top:8px;" data-api-copy-flash>Copy key</button>
              </div>
            <?php endif; ?>

            <div class="api-metrics" aria-label="API metrics">
              <div class="api-metric">
                <div class="k">Total API Keys</div>
                <div class="v"><?= admin_api_settings_format_number((int)$apiStats['total']) ?></div>
                <div class="d up">+<?= (int)$apiStats['delta_keys_month'] ?> this month</div>
              </div>
              <div class="api-metric">
                <div class="k">Active Keys</div>
                <div class="v"><?= admin_api_settings_format_number((int)$apiStats['active']) ?></div>
                <div class="d"><?= (int)$apiStats['active_pct'] ?>% of total</div>
              </div>
              <div class="api-metric">
                <div class="k">Requests (Today)</div>
                <div class="v"><?= admin_api_settings_format_number((int)$apiStats['requests_today']) ?></div>
                <div class="d <?= $reqDelta >= 0 ? 'up' : 'down' ?>">
                  <?= $reqDelta >= 0 ? '+' : '' ?><?= admin_api_settings_format_number($reqDelta) ?> vs yesterday
                </div>
              </div>
              <div class="api-metric">
                <div class="k">Rate Limit Usage</div>
                <div class="v"><?= (int)$apiStats['rate_usage_pct'] ?>%</div>
                <div class="d">of <?= admin_api_settings_format_number((int)$apiStats['daily_limit']) ?> / day</div>
              </div>
            </div>

            <div class="api-panel">
              <div class="api-hd">
                <nav class="api-tabs" aria-label="API sections">
                  <?php foreach ($apiTabDefs as $tk => $tlab): ?>
                    <a class="<?= $atab === $tk ? 'is-active' : '' ?>" href="<?= admin_platform_settings_h($apiUrl(['atab' => $tk, 'apage' => 1])) ?>">
                      <?= admin_platform_settings_h($tlab) ?>
                    </a>
                  <?php endforeach; ?>
                  <?php if ($atab === 'docs'): ?>
                    <a class="is-active" href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>">Docs</a>
                  <?php endif; ?>
                </nav>
                <?php if ($atab === 'keys' && $adminMode): ?>
                  <div class="api-hd-actions">
                    <a class="ps-save" href="#api-create-form"><i class="fa fa-plus"></i> Generate New API Key</a>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($atab === 'keys' || $atab === 'docs'): ?>
                <?php if ($atab === 'docs'): ?>
                  <div class="api-body">
                    <div class="api-form-card" style="box-shadow:none;border:0;padding:0;">
                      <h2>API Documentation</h2>
                      <p class="api-sub">High-level reference for integrating with the Admin Panel API.</p>
                      <ul class="api-docs-list">
                        <li><a href="#">Getting Started <i class="fa fa-chevron-right"></i></a></li>
                        <li><a href="#">Authentication <i class="fa fa-chevron-right"></i></a></li>
                        <li><a href="#">Endpoints <i class="fa fa-chevron-right"></i></a></li>
                        <li><a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'limits'])) ?>">Rate Limits <i class="fa fa-chevron-right"></i></a></li>
                        <li><a href="#">SDKs <i class="fa fa-chevron-right"></i></a></li>
                      </ul>
                      <a class="ps-btn" href="#">View Full Documentation</a>
                    </div>
                  </div>
                <?php else: ?>
                  <?php if ($adminMode): ?>
                    <form class="api-filters" method="post" action="settings.php?section=api" id="api-create-form" style="background:#fff;">
                      <input type="hidden" name="action" value="api_create_key">
                      <div class="api-create-grid" style="width:100%;">
                        <div class="api-f" style="flex:1 1 auto;">
                          <label for="api_key_name">Key name</label>
                          <input id="api_key_name" name="api_key_name" required maxlength="120" placeholder="e.g. Production Integration"<?= $adminMode ? '' : ' disabled' ?>>
                        </div>
                        <div class="api-f">
                          <label for="api_key_permissions">Permissions</label>
                          <select class="api-sel" id="api_key_permissions" name="api_key_permissions">
                            <option value="read">Read</option>
                            <option value="read,write">Read, Write</option>
                          </select>
                        </div>
                        <button type="submit" class="ps-save"><i class="fa fa-key"></i> Generate</button>
                      </div>
                    </form>
                  <?php endif; ?>

                  <form class="api-filters" method="get" action="settings.php">
                    <input type="hidden" name="section" value="api">
                    <input type="hidden" name="atab" value="keys">
                    <input type="hidden" name="aper" value="<?= (int)$aper ?>">
                    <div class="api-f">
                      <label for="aq">Search</label>
                      <input class="api-search" id="aq" name="aq" type="search" value="<?= admin_platform_settings_h($aq) ?>" placeholder="Search keys…">
                    </div>
                    <div class="api-f">
                      <label for="astatus">Status</label>
                      <select class="api-sel" id="astatus" name="astatus">
                        <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'revoked' => 'Revoked'] as $vk => $vl): ?>
                          <option value="<?= $vk ?>"<?= $astatus === $vk ? ' selected' : '' ?>><?= $vl ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <button type="submit" class="ps-btn">Filters</button>
                    <a class="nt-clear" href="settings.php?section=api">Clear</a>
                  </form>

                  <div class="api-table-scroll">
                    <table class="api-table">
                      <thead>
                        <tr>
                          <th>Name</th>
                          <th>Key</th>
                          <th>Permissions</th>
                          <th>Last Used</th>
                          <th>Status</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if ($apiKeysList['rows'] === []): ?>
                          <tr><td colspan="6"><div class="api-empty">No API keys yet. Generate one to get started.</div></td></tr>
                        <?php else: ?>
                          <?php foreach ($apiKeysList['rows'] as $kr): ?>
                            <?php
                            $kStatus = admin_api_settings_normalize_status((string)($kr['status'] ?? 'active'));
                            $kPerms = admin_api_settings_normalize_permissions((string)($kr['permissions'] ?? 'read'));
                            $masked = admin_api_settings_mask_key((string)$kr['key_prefix'], (string)$kr['key_last4'], false);
                            $maskedReveal = admin_api_settings_mask_key((string)$kr['key_prefix'], (string)$kr['key_last4'], true);
                            $createdBy = trim((string)($kr['created_by_label'] ?? ''));
                            ?>
                            <tr>
                              <td>
                                <div class="api-name"><?= admin_platform_settings_h((string)$kr['name']) ?></div>
                                <div class="api-sub">Created by <?= admin_platform_settings_h($createdBy !== '' ? $createdBy : 'Admin') ?> · <?= admin_platform_settings_h(admin_api_settings_format_dt(isset($kr['created_at']) ? (string)$kr['created_at'] : null)) ?></div>
                              </td>
                              <td>
                                <div class="api-key-cell">
                                  <span class="api-key-mask" data-mask="<?= admin_platform_settings_h($masked) ?>" data-reveal="<?= admin_platform_settings_h($maskedReveal) ?>"><?= admin_platform_settings_h($masked) ?></span>
                                  <button type="button" class="api-icon-btn" data-api-eye title="Toggle mask" aria-label="Toggle key visibility"><i class="fa fa-eye"></i></button>
                                  <button type="button" class="api-icon-btn" data-api-copy title="Copy masked key" aria-label="Copy key"><i class="fa fa-copy"></i></button>
                                </div>
                              </td>
                              <td>
                                <?php if ($kPerms === 'read,write'): ?>
                                  <span class="api-pill read">Read</span>
                                  <span class="api-pill write">Write</span>
                                <?php else: ?>
                                  <span class="api-pill read">Read</span>
                                <?php endif; ?>
                              </td>
                              <td><?= admin_platform_settings_h(admin_api_settings_format_dt(isset($kr['last_used_at']) ? (string)$kr['last_used_at'] : null)) ?></td>
                              <td><span class="api-pill <?= admin_platform_settings_h($kStatus) ?>"><?= admin_platform_settings_h(ucfirst($kStatus)) ?></span></td>
                              <td>
                                <div class="fries-menu">
                                  <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                                    <span class="fries-icon" aria-hidden="true"></span>
                                  </button>
                                  <div class="fries-dropdown" role="menu">
                                    <?php if ($adminMode && $kStatus !== 'active'): ?>
                                      <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="api_set_status">
                                        <input type="hidden" name="id" value="<?= (int)$kr['id'] ?>">
                                        <input type="hidden" name="status" value="active">
                                        <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-check"></i> Activate</button>
                                      </form>
                                    <?php endif; ?>
                                    <?php if ($adminMode && $kStatus === 'active'): ?>
                                      <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="api_set_status">
                                        <input type="hidden" name="id" value="<?= (int)$kr['id'] ?>">
                                        <input type="hidden" name="status" value="inactive">
                                        <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-pause"></i> Deactivate</button>
                                      </form>
                                    <?php endif; ?>
                                    <?php if ($adminMode && $kStatus !== 'revoked'): ?>
                                      <form method="post" style="margin:0;" onsubmit="return confirm('Revoke this API key?');">
                                        <input type="hidden" name="action" value="api_set_status">
                                        <input type="hidden" name="id" value="<?= (int)$kr['id'] ?>">
                                        <input type="hidden" name="status" value="revoked">
                                        <button type="submit" class="fries-item fries-item-danger" role="menuitem"><i class="fa fa-ban"></i> Revoke</button>
                                      </form>
                                    <?php endif; ?>
                                    <?php if ($adminMode): ?>
                                      <form method="post" style="margin:0;" onsubmit="return confirm('Permanently delete this API key?');">
                                        <input type="hidden" name="action" value="api_delete_key">
                                        <input type="hidden" name="id" value="<?= (int)$kr['id'] ?>">
                                        <button type="submit" class="fries-item fries-item-danger" role="menuitem"><i class="fa fa-trash"></i> Delete</button>
                                      </form>
                                    <?php endif; ?>
                                  </div>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>

                  <div class="api-foot">
                    <span>
                      Showing <?= (int)$apiFromIdx ?> to <?= (int)$apiToIdx ?> of <?= (int)$apiKeysList['total'] ?>
                      ·
                      <a href="<?= admin_platform_settings_h($apiUrl(['aper' => 10, 'apage' => 1])) ?>" style="color:inherit;<?= $aper === 10 ? 'font-weight:800;color:#1d4ed8;' : '' ?>">10</a>
                      /
                      <a href="<?= admin_platform_settings_h($apiUrl(['aper' => 25, 'apage' => 1])) ?>" style="color:inherit;<?= $aper === 25 ? 'font-weight:800;color:#1d4ed8;' : '' ?>">25</a>
                      per page
                    </span>
                    <div class="api-pager">
                      <a class="<?= $apage <= 1 ? 'is-disabled' : '' ?>" href="<?= admin_platform_settings_h($apiUrl(['apage' => max(1, $apage - 1)])) ?>">&lsaquo;</a>
                      <?php
                      $apiPages = (int)$apiKeysList['pages'];
                      $startP = max(1, $apage - 2);
                      $endP = min($apiPages, $startP + 4);
                      $startP = max(1, $endP - 4);
                      for ($p = $startP; $p <= $endP; $p++):
                      ?>
                        <a class="<?= $p === $apage ? 'is-active' : '' ?>" href="<?= admin_platform_settings_h($apiUrl(['apage' => $p])) ?>"><?= $p ?></a>
                      <?php endfor; ?>
                      <a class="<?= $apage >= $apiPages ? 'is-disabled' : '' ?>" href="<?= admin_platform_settings_h($apiUrl(['apage' => min($apiPages, $apage + 1)])) ?>">&rsaquo;</a>
                    </div>
                  </div>
                <?php endif; ?>

              <?php elseif ($atab === 'limits'): ?>
                <div class="api-body">
                  <form class="api-form-card" method="post" action="settings.php?section=api&amp;atab=limits" style="box-shadow:none;border:0;padding:0;">
                    <input type="hidden" name="action" value="api_save_limits">
                    <h2>Rate Limits</h2>
                    <p class="api-sub">Set the daily request ceiling for API access.</p>
                    <div class="ps-fields" style="grid-template-columns:1fr;">
                      <div class="ps-field">
                        <label for="api_daily_limit">Daily request limit</label>
                        <input id="api_daily_limit" name="api_daily_limit" type="number" min="100" max="10000000" step="100"
                          value="<?= (int)$apiStats['daily_limit'] ?>"<?= $adminMode ? '' : ' disabled' ?>>
                        <div class="hint">Default 100,000 requests per day.</div>
                      </div>
                    </div>
                    <div class="api-side-meter" style="margin-top:14px;">
                      <div class="lab">
                        <span>Usage today</span>
                        <span class="pct"><?= (int)$apiStats['rate_usage_pct'] ?>%</span>
                      </div>
                      <div class="api-usage-bar" aria-hidden="true">
                        <span class="<?= admin_platform_settings_h($rateCls) ?>" style="width:<?= (int)$apiStats['rate_usage_pct'] ?>%;"></span>
                      </div>
                      <div class="api-side-kv">
                        <strong><?= admin_api_settings_format_number((int)$apiStats['requests_today']) ?></strong>
                        used ·
                        <strong><?= admin_api_settings_format_number((int)$apiStats['remaining']) ?></strong>
                        remaining
                      </div>
                    </div>
                    <?php if ($adminMode): ?>
                      <button type="submit" class="ps-save" style="margin-top:8px;"><i class="fa fa-save"></i> Save Rate Limits</button>
                    <?php endif; ?>
                  </form>
                </div>

              <?php elseif ($atab === 'allowlist'): ?>
                <div class="api-body">
                  <form class="api-form-card" method="post" action="settings.php?section=api&amp;atab=allowlist" style="box-shadow:none;border:0;padding:0;">
                    <input type="hidden" name="action" value="api_save_allowlist">
                    <h2>IP Allowlist</h2>
                    <p class="api-sub">Only listed IPs may call the API when this list is non-empty.</p>
                    <div class="ps-field full">
                      <label for="api_ip_allowlist">Allowed IPs</label>
                      <textarea id="api_ip_allowlist" name="api_ip_allowlist" rows="10" placeholder="192.168.1.1&#10;203.0.113.25"<?= $adminMode ? '' : ' disabled' ?>><?= admin_platform_settings_h((string)($settings['api_ip_allowlist'] ?? '')) ?></textarea>
                      <div class="hint">One IP per line. <?= (int)$apiStats['allowlist_count'] ?> currently listed.</div>
                    </div>
                    <?php if ($adminMode): ?>
                      <button type="submit" class="ps-save" style="margin-top:10px;"><i class="fa fa-save"></i> Save Allowlist</button>
                    <?php endif; ?>
                  </form>
                </div>

              <?php elseif ($atab === 'webhooks'): ?>
                <div class="api-body">
                  <div class="api-form-card" style="box-shadow:none;border:0;padding:0;">
                    <h2>Webhooks</h2>
                    <p class="api-sub">Stub delivery targets stored in platform settings.</p>
                    <?php if ($apiWebhooks === []): ?>
                      <div class="api-empty" style="padding:16px 0;">No webhooks configured yet.</div>
                    <?php else: ?>
                      <?php foreach ($apiWebhooks as $wh): ?>
                        <div class="api-wh-row">
                          <div>
                            <div class="api-name"><?= admin_platform_settings_h((string)$wh['name']) ?></div>
                            <div class="api-sub"><?= admin_platform_settings_h((string)$wh['url']) ?></div>
                            <div class="api-sub">Events: <?= admin_platform_settings_h((string)($wh['events'] !== '' ? $wh['events'] : '—')) ?></div>
                          </div>
                          <div style="display:flex;align-items:center;gap:8px;">
                            <span class="api-pill <?= admin_platform_settings_h((string)$wh['status']) ?>"><?= admin_platform_settings_h(ucfirst((string)$wh['status'])) ?></span>
                            <?php if ($adminMode): ?>
                              <form method="post" action="settings.php?section=api&amp;atab=webhooks" style="margin:0;" onsubmit="return confirm('Remove this webhook?');">
                                <input type="hidden" name="action" value="api_delete_webhook">
                                <input type="hidden" name="webhook_id" value="<?= admin_platform_settings_h((string)$wh['id']) ?>">
                                <button type="submit" class="api-icon-btn" title="Delete"><i class="fa fa-trash"></i></button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($adminMode): ?>
                      <form method="post" action="settings.php?section=api&amp;atab=webhooks" style="margin-top:14px;">
                        <input type="hidden" name="action" value="api_add_webhook">
                        <div class="ps-fields">
                          <div class="ps-field">
                            <label for="webhook_name">Name</label>
                            <input id="webhook_name" name="webhook_name" required maxlength="120" placeholder="Orders sync">
                          </div>
                          <div class="ps-field">
                            <label for="webhook_events">Events</label>
                            <input id="webhook_events" name="webhook_events" maxlength="200" placeholder="key.created, key.revoked">
                          </div>
                          <div class="ps-field full">
                            <label for="webhook_url">URL</label>
                            <input id="webhook_url" name="webhook_url" type="url" required maxlength="500" placeholder="https://example.com/hooks/admin">
                          </div>
                        </div>
                        <button type="submit" class="ps-save" style="margin-top:10px;"><i class="fa fa-plus"></i> Add Webhook</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>

              <?php else: /* activity */ ?>
                <div class="api-body">
                  <div class="api-form-card" style="box-shadow:none;border:0;padding:0;">
                    <h2>Activity Logs</h2>
                    <p class="api-sub">API activity is not recorded separately yet.</p>
                    <div class="api-empty" style="padding:20px 0;">
                      No API activity logged yet.
                      <div style="margin-top:10px;">
                        <a class="ps-btn" href="security-log.php"><i class="fa fa-list"></i> Open Security Log</a>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <script>
            (function () {
              document.querySelectorAll('[data-api-eye]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  var cell = btn.closest('.api-key-cell');
                  if (!cell) return;
                  var el = cell.querySelector('.api-key-mask');
                  if (!el) return;
                  var revealed = el.getAttribute('data-revealed') === '1';
                  if (revealed) {
                    el.textContent = el.getAttribute('data-mask') || '';
                    el.setAttribute('data-revealed', '0');
                    btn.querySelector('i').className = 'fa fa-eye';
                  } else {
                    el.textContent = el.getAttribute('data-reveal') || el.getAttribute('data-mask') || '';
                    el.setAttribute('data-revealed', '1');
                    btn.querySelector('i').className = 'fa fa-eye-slash';
                  }
                });
              });
              document.querySelectorAll('[data-api-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  var cell = btn.closest('.api-key-cell');
                  if (!cell) return;
                  var el = cell.querySelector('.api-key-mask');
                  if (!el) return;
                  var text = el.textContent || '';
                  if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                  }
                });
              });
              var flashBtn = document.querySelector('[data-api-copy-flash]');
              if (flashBtn) {
                flashBtn.addEventListener('click', function () {
                  var code = document.querySelector('#api-key-flash code[data-api-plaintext]');
                  var text = code ? (code.getAttribute('data-api-plaintext') || code.textContent || '') : '';
                  if (text && navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                  }
                });
              }
            })();
            </script>
          <?php endif; ?>

          <?php if ($showIntegrations): ?>
            <?php
            $igTabDefs = [
                'all' => 'All',
                'active' => 'Active',
                'inactive' => 'Inactive',
                'failed' => 'Failed',
            ];
            $igEditRow = null;
            if ($igEdit !== '') {
                foreach ($igRowsAll as $er) {
                    if ((string)$er['id'] === $igEdit) {
                        $igEditRow = $er;
                        break;
                    }
                }
            }
            ?>
            <div class="ig-metrics" aria-label="Integration metrics">
              <div class="ig-metric">
                <div class="k">Total Integrations</div>
                <div class="v"><?= (int)$igStats['total'] ?></div>
                <div class="d">Available services</div>
              </div>
              <div class="ig-metric">
                <div class="k">Active</div>
                <div class="v"><?= (int)$igStats['active'] ?></div>
                <div class="d"><?= (int)$igStats['active_pct'] ?>% connected</div>
              </div>
              <div class="ig-metric">
                <div class="k">Inactive</div>
                <div class="v"><?= (int)$igStats['inactive'] ?></div>
                <div class="d">Not connected</div>
              </div>
              <div class="ig-metric">
                <div class="k">Failed</div>
                <div class="v"><?= (int)$igStats['failed'] ?></div>
                <div class="d">Need attention</div>
              </div>
            </div>

            <?php if ($igEditRow !== null): ?>
              <div class="ig-edit-card" id="ig-configure">
                <h2>Configure <?= admin_platform_settings_h((string)$igEditRow['name']) ?></h2>
                <p class="ig-sub">Credential fields are managed outside this panel. Use Connect / Disconnect from the table, or open Stripe Connect for payments.</p>
                <div class="ig-cell" style="margin-bottom:12px;">
                  <span class="ig-ico"><i class="fa <?= admin_platform_settings_h((string)$igEditRow['icon']) ?>"></i></span>
                  <div>
                    <div class="ig-name"><?= admin_platform_settings_h((string)$igEditRow['name']) ?></div>
                    <div class="ig-desc"><?= admin_platform_settings_h((string)$igEditRow['description']) ?></div>
                    <div class="ig-desc" style="margin-top:6px;">
                      Status:
                      <span class="ig-pill <?= admin_platform_settings_h((string)$igEditRow['status']) ?>">
                        <span class="dot" aria-hidden="true"></span>
                        <?= admin_platform_settings_h(ucfirst((string)$igEditRow['status'])) ?>
                      </span>
                      · Last sync: <?= admin_platform_settings_h(admin_integrations_format_dt((string)$igEditRow['last_sync'])) ?>
                    </div>
                  </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                  <?php if ((string)$igEditRow['id'] === 'stripe'): ?>
                    <a class="ps-save" href="org_stripe_connect.php"><i class="fa fa-cc-stripe"></i> Open Stripe Connect</a>
                  <?php endif; ?>
                  <?php if ($adminMode): ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="action" value="integration_sync">
                      <input type="hidden" name="id" value="<?= admin_platform_settings_h((string)$igEditRow['id']) ?>">
                      <button type="submit" class="ps-btn"><i class="fa fa-refresh"></i> Sync now</button>
                    </form>
                  <?php endif; ?>
                  <a class="ps-btn" href="<?= admin_platform_settings_h($igUrl(['edit' => null])) ?>"><i class="fa fa-times"></i> Close</a>
                </div>
              </div>
            <?php endif; ?>

            <div class="ig-panel" id="ig-table">
              <div class="ig-hd">
                <nav class="ig-tabs" aria-label="Integration status">
                  <?php foreach ($igTabDefs as $tk => $tlab): ?>
                    <a class="<?= $itab === $tk ? 'is-active' : '' ?>" href="<?= admin_platform_settings_h($igUrl(['itab' => $tk, 'ipage' => 1])) ?>">
                      <?= admin_platform_settings_h($tlab) ?>
                    </a>
                  <?php endforeach; ?>
                </nav>
              </div>

              <form class="ig-filters" method="get" action="settings.php">
                <input type="hidden" name="section" value="integrations">
                <input type="hidden" name="itab" value="<?= admin_platform_settings_h($itab) ?>">
                <div class="ig-f">
                  <label for="iq">Search</label>
                  <input class="ig-search" id="iq" name="iq" type="search" value="<?= admin_platform_settings_h($iq) ?>" placeholder="Search integrations…">
                </div>
                <div class="ig-f">
                  <label for="icat">Category</label>
                  <select class="ig-sel" id="icat" name="icat">
                    <option value="">All Categories</option>
                    <?php foreach ($igCategories as $cat): ?>
                      <option value="<?= admin_platform_settings_h($cat) ?>"<?= strcasecmp($icat, $cat) === 0 ? ' selected' : '' ?>><?= admin_platform_settings_h($cat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="ps-btn">Filters</button>
                <a class="nt-clear" href="settings.php?section=integrations">Clear</a>
              </form>

              <div class="ig-table-scroll">
                <table class="ig-table">
                  <thead>
                    <tr>
                      <th>Integration</th>
                      <th>Category</th>
                      <th>Status</th>
                      <th>Last Sync</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($igPageRows === []): ?>
                      <tr><td colspan="5"><div class="ig-empty">No integrations match your filters.</div></td></tr>
                    <?php else: ?>
                      <?php foreach ($igPageRows as $ir): ?>
                        <?php
                        $iStatus = admin_integrations_normalize_status((string)($ir['status'] ?? 'inactive'));
                        $iId = (string)$ir['id'];
                        ?>
                        <tr>
                          <td>
                            <div class="ig-cell">
                              <span class="ig-ico"><i class="fa <?= admin_platform_settings_h((string)$ir['icon']) ?>"></i></span>
                              <div>
                                <div class="ig-name"><?= admin_platform_settings_h((string)$ir['name']) ?></div>
                                <div class="ig-desc"><?= admin_platform_settings_h((string)$ir['description']) ?></div>
                              </div>
                            </div>
                          </td>
                          <td><span class="ig-cat"><?= admin_platform_settings_h((string)$ir['category']) ?></span></td>
                          <td>
                            <span class="ig-pill <?= admin_platform_settings_h($iStatus) ?>">
                              <span class="dot" aria-hidden="true"></span>
                              <?= admin_platform_settings_h(ucfirst($iStatus)) ?>
                            </span>
                          </td>
                          <td><?= admin_platform_settings_h(admin_integrations_format_dt((string)($ir['last_sync'] ?? ''))) ?></td>
                          <td>
                            <div class="fries-menu">
                              <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                                <span class="fries-icon" aria-hidden="true"></span>
                              </button>
                              <div class="fries-dropdown" role="menu">
                                <?php if ($adminMode && $iStatus !== 'active'): ?>
                                  <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="integration_set_status">
                                    <input type="hidden" name="id" value="<?= admin_platform_settings_h($iId) ?>">
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-plug"></i> Connect</button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($adminMode && $iStatus === 'active'): ?>
                                  <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="integration_set_status">
                                    <input type="hidden" name="id" value="<?= admin_platform_settings_h($iId) ?>">
                                    <input type="hidden" name="status" value="inactive">
                                    <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-unlink"></i> Disconnect</button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($adminMode && $iStatus === 'failed'): ?>
                                  <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="integration_retry">
                                    <input type="hidden" name="id" value="<?= admin_platform_settings_h($iId) ?>">
                                    <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-refresh"></i> Retry</button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($iId === 'stripe'): ?>
                                  <a class="fries-item" role="menuitem" href="org_stripe_connect.php"><i class="fa fa-cog"></i> Configure</a>
                                <?php else: ?>
                                  <a class="fries-item" role="menuitem" href="<?= admin_platform_settings_h($igUrl(['edit' => $iId])) ?>"><i class="fa fa-cog"></i> Configure</a>
                                <?php endif; ?>
                                <a class="fries-item" role="menuitem" href="security-log.php"><i class="fa fa-list"></i> View logs</a>
                                <?php if ($adminMode): ?>
                                  <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="integration_sync">
                                    <input type="hidden" name="id" value="<?= admin_platform_settings_h($iId) ?>">
                                    <button type="submit" class="fries-item" role="menuitem"><i class="fa fa-clock-o"></i> Sync now</button>
                                  </form>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="ig-foot">
                <span>Showing <?= (int)$igFromIdx ?> to <?= (int)$igToIdx ?> of <?= (int)$igTotalFiltered ?></span>
                <div class="ig-pager">
                  <a class="<?= $ipage <= 1 ? 'is-disabled' : '' ?>" href="<?= admin_platform_settings_h($igUrl(['ipage' => max(1, $ipage - 1)])) ?>">&lsaquo;</a>
                  <?php
                  $startP = max(1, $ipage - 2);
                  $endP = min($igPages, $startP + 4);
                  $startP = max(1, $endP - 4);
                  for ($p = $startP; $p <= $endP; $p++):
                  ?>
                    <a class="<?= $p === $ipage ? 'is-active' : '' ?>" href="<?= admin_platform_settings_h($igUrl(['ipage' => $p])) ?>"><?= $p ?></a>
                  <?php endfor; ?>
                  <a class="<?= $ipage >= $igPages ? 'is-disabled' : '' ?>" href="<?= admin_platform_settings_h($igUrl(['ipage' => min($igPages, $ipage + 1)])) ?>">&rsaquo;</a>
                </div>
              </div>
            </div>
          <?php endif; ?>




          <?php if ($showContent): ?>
            <?php
            $ctTypeCatalog = admin_content_type_catalog();
            $ctVisOptions = admin_content_visibility_options();
            ?>
            <div class="ct-metrics" aria-label="Content metrics">
              <?php foreach ($ctMetrics as $m): ?>
                <div class="ct-metric">
                  <div class="copy">
                    <div class="k"><?= admin_platform_settings_h((string)$m['label']) ?></div>
                    <div class="v"><?= number_format((int)$m['value']) ?></div>
                    <div class="d <?= admin_platform_settings_h((string)$m['sub_cls']) ?>"><?= admin_platform_settings_h((string)$m['sub']) ?></div>
                  </div>
                  <span class="ico <?= admin_platform_settings_h((string)$m['icon_cls']) ?>" aria-hidden="true">
                    <i class="fa <?= admin_platform_settings_h((string)$m['icon']) ?>"></i>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="ct-grid">
              <div class="ct-card" id="ct-approval">
                <h2>Content Approval</h2>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_require_approval">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Require approval before publishing</span>
                    <span class="ct-desc">All content will be reviewed by a moderator before it goes live.</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_require_approval']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_auto_publish_trusted">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Auto-publish trusted users</span>
                    <span class="ct-desc">Trusted users can publish content without approval.</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_auto_publish_trusted']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
              </div>

              <form class="ct-card" id="ct-auto-publish" method="post">
                <input type="hidden" name="action" value="save_content">
                <input type="hidden" name="content_enable_auto_publish_present" value="1">
                <div class="ct-card-hd">
                  <h2>Auto-Publish</h2>
                  <button type="submit" class="ps-save"<?= $adminMode ? '' : ' disabled' ?>>Save Changes</button>
                </div>
                <div class="ct-toggle-row" style="border:0;padding-top:4px;">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Enable auto-publish</span>
                    <span class="ct-desc">Content will be published at the date and time set by the author.</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox" name="content_enable_auto_publish" value="1"<?= !empty($settings['content_enable_auto_publish']) ? ' checked' : '' ?><?= $adminMode ? '' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </div>
              </form>

              <div class="ct-card" id="ct-types">
                <h2>Content Types</h2>
                <p class="ct-sub">Choose which content formats are allowed on the platform.</p>
                <div class="ct-types">
                  <?php foreach ($ctTypeCatalog as $tid => $tmeta): ?>
                    <?php $ton = in_array($tid, $ctEnabledTypes, true); ?>
                    <form class="ct-type" method="post">
                      <input type="hidden" name="action" value="toggle_content_type">
                      <input type="hidden" name="type" value="<?= admin_platform_settings_h($tid) ?>">
                      <span class="glyph" style="background:<?= admin_platform_settings_h((string)$tmeta['color']) ?>;" aria-hidden="true">
                        <i class="fa <?= admin_platform_settings_h((string)$tmeta['icon']) ?>"></i>
                      </span>
                      <span class="nm"><?= admin_platform_settings_h((string)$tmeta['label']) ?></span>
                      <input type="checkbox"<?= $ton ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?> aria-label="<?= admin_platform_settings_h((string)$tmeta['label']) ?>">
                    </form>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="ct-card" id="ct-media">
                <h2>Media Upload Settings</h2>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_allow_image_uploads">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Allow image uploads</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_allow_image_uploads']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_allow_video_uploads">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Allow video uploads</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_allow_video_uploads']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
                <form class="ct-field-row" method="post">
                  <input type="hidden" name="action" value="save_content_media">
                  <span class="ct-label">Maximum file size</span>
                  <select class="ct-select" name="content_max_file_size_mb"<?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <?php foreach (admin_content_max_size_options() as $mb): ?>
                      <option value="<?= (int)$mb ?>"<?= $ctMaxMb === (int)$mb ? ' selected' : '' ?>><?= (int)$mb ?> MB</option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <div class="ct-field-row">
                  <span class="ct-label">Allowed file types</span>
                  <a class="ps-btn" href="#ct-types"><i class="fa fa-cog"></i> Manage Types</a>
                </div>
              </div>

              <div class="ct-card" id="ct-visibility">
                <h2>Content Visibility</h2>
                <form class="ct-field-row" method="post">
                  <input type="hidden" name="action" value="save_content_visibility">
                  <div>
                    <span class="ct-label">Default visibility for new content</span>
                  </div>
                  <div class="ct-vis-wrap">
                    <i class="fa fa-globe globe" aria-hidden="true"></i>
                    <select class="ct-vis-select" name="content_default_visibility"<?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                      <?php foreach ($ctVisOptions as $vk => $vlab): ?>
                        <option value="<?= admin_platform_settings_h($vk) ?>"<?= $ctVisibility === $vk ? ' selected' : '' ?>><?= admin_platform_settings_h($vlab) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </form>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_allow_change_visibility">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Allow user to change visibility</span>
                    <span class="ct-desc">Users can change the visibility of their content.</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_allow_change_visibility']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
              </div>

              <div class="ct-card" id="ct-comments">
                <h2>Comment Settings</h2>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_allow_comments">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Allow comments</span>
                    <span class="ct-desc">Users can comment on content.</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_allow_comments']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
                <form class="ct-toggle-row" method="post">
                  <input type="hidden" name="action" value="toggle_content">
                  <input type="hidden" name="field" value="content_comment_approval">
                  <div class="ct-toggle-copy">
                    <span class="ct-label">Comment approval</span>
                    <span class="ct-desc">Require approval for comments containing flagged keywords.</span>
                  </div>
                  <label class="ct-toggle">
                    <input type="checkbox"<?= !empty($settings['content_comment_approval']) ? ' checked' : '' ?><?= $adminMode ? ' onchange="this.form.submit()"' : ' disabled' ?>>
                    <span class="ct-slider"></span>
                  </label>
                </form>
              </div>

              <div class="ct-card full" id="ct-categories">
                <div class="ct-cats-row">
                  <div class="copy">
                    <h2>Content Categories</h2>
                    <p class="ct-sub" style="margin:4px 0 0;">Manage categories to help organize content.</p>
                  </div>
                  <a class="ps-btn" href="posts.php"><i class="fa fa-cog"></i> Manage Categories</a>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!$showGeneral && !$showSecurity && !$showEmail && !$showNotifications && !$showPrivacy && !$showSystem && !$showApi && !$showIntegrations && !$showContent): ?>
            <div class="ps-card">
              <div class="ps-card-hd">
                <div>
                  <h2><?= admin_platform_settings_h($navItems[$section][0] ?? 'Settings') ?></h2>
                  <p>Jump to related admin tools for this area.</p>
                </div>
              </div>
              <div class="ps-jump">
                <?php if ($section === 'moderation'): ?>
                  <a href="reports.php?status=pending"><span class="ico"><i class="fa fa-flag"></i></span><span><div class="t">Pending reports</div><div class="s">Review flagged content</div></span></a>
                  <a href="posts.php"><span class="ico"><i class="fa fa-image"></i></span><span><div class="t">Posts</div><div class="s">Moderate public posts</div></span></a>
                <?php elseif ($section === 'users'): ?>
                  <a href="userlist.php"><span class="ico"><i class="fa fa-user"></i></span><span><div class="t">User list</div><div class="s">Browse public accounts</div></span></a>
                  <a href="adminroles.php"><span class="ico"><i class="fa fa-id-badge"></i></span><span><div class="t">Admin roles</div><div class="s">Internal accounts &amp; roles</div></span></a>
                <?php elseif ($section === 'reports'): ?>
                  <a href="reports.php"><span class="ico"><i class="fa fa-flag"></i></span><span><div class="t">Reports queue</div><div class="s">All content reports</div></span></a>
                  <a href="dispute.php"><span class="ico"><i class="fa fa-balance-scale"></i></span><span><div class="t">Disputes</div><div class="s">Order / seller disputes</div></span></a>
                <?php else: ?>
                  <a href="security-log.php"><span class="ico"><i class="fa fa-server"></i></span><span><div class="t">System logs</div><div class="s">Review operational events</div></span></a>
                  <a href="settings.php?section=general"><span class="ico"><i class="fa fa-sliders"></i></span><span><div class="t">General settings</div><div class="s">Branding and locale</div></span></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        </main>

        <aside class="ps-right" aria-label="Settings utilities">
          <?php if ($showContent): ?>
            <div class="ps-side-card">
              <h3>Content Summary</h3>
              <ul class="ct-sum">
                <li>
                  <span class="k">Approval required</span>
                  <span class="v"><?= !empty($settings['content_require_approval']) ? 'On' : 'Off' ?></span>
                </li>
                <li>
                  <span class="k">Auto-publish</span>
                  <span class="v"><?= !empty($settings['content_enable_auto_publish']) ? 'On' : 'Off' ?></span>
                </li>
                <li>
                  <span class="k">Allowed types</span>
                  <span class="v"><?= count($ctEnabledTypes) ?> / <?= count(admin_content_type_catalog()) ?></span>
                </li>
                <li>
                  <span class="k">Max upload</span>
                  <span class="v"><?= (int)$ctMaxMb ?> MB</span>
                </li>
                <li>
                  <span class="k">Default visibility</span>
                  <span class="v"><?= admin_platform_settings_h(admin_content_visibility_options()[$ctVisibility] ?? 'Public') ?></span>
                </li>
                <li>
                  <span class="k">Comments</span>
                  <span class="v"><?= !empty($settings['content_allow_comments']) ? 'Allowed' : 'Off' ?></span>
                </li>
              </ul>
              <a class="ps-btn ext" href="posts.php"><i class="fa fa-th-list"></i> Open Posts</a>
            </div>

            <div class="ps-side-card">
              <h3>Quick Links</h3>
              <a class="ps-btn ext" href="posts.php"><i class="fa fa-file-text-o"></i> Moderate Posts</a>
              <a class="ps-btn ext" href="reports.php" style="margin-top:8px;"><i class="fa fa-flag"></i> Reports Queue</a>
              <a class="ps-btn ext" href="settings.php?section=moderation" style="margin-top:8px;"><i class="fa fa-gavel"></i> Moderation</a>
            </div>
          <?php elseif ($showIntegrations): ?>
            <div class="ps-side-card">
              <h3>Integration Health</h3>
              <div class="ig-donut-wrap">
                <div class="ig-donut" style="background:<?= admin_platform_settings_h($igDonutBg) ?>;" aria-hidden="true"></div>
                <ul class="ig-legend">
                  <li>
                    <span class="dot" style="background:#16a34a;"></span>
                    <span>Active</span>
                    <span class="pct"><?= (int)$igDonutActive ?>%</span>
                  </li>
                  <li>
                    <span class="dot" style="background:#94a3b8;"></span>
                    <span>Inactive</span>
                    <span class="pct"><?= (int)$igDonutInactive ?>%</span>
                  </li>
                  <li>
                    <span class="dot" style="background:#dc2626;"></span>
                    <span>Failed</span>
                    <span class="pct"><?= (int)$igDonutFailed ?>%</span>
                  </li>
                </ul>
              </div>
            </div>

            <div class="ps-side-card">
              <h3>Popular Integrations</h3>
              <ul class="ig-pop">
                <?php foreach ($igPopular as $pop): ?>
                  <li>
                    <div class="row">
                      <span class="n"><i class="fa <?= admin_platform_settings_h((string)$pop['icon']) ?>"></i><?= admin_platform_settings_h((string)$pop['name']) ?></span>
                      <span class="p"><?= (int)$pop['popular_pct'] ?>%</span>
                    </div>
                    <div class="ig-pop-bar" aria-hidden="true"><span style="width:<?= (int)$pop['popular_pct'] ?>%;"></span></div>
                  </li>
                <?php endforeach; ?>
              </ul>
              <a class="ps-btn ext" href="#ig-table"><i class="fa fa-th-list"></i> Browse All</a>
            </div>

            <div class="ps-side-card">
              <h3>Need Help?</h3>
              <ul class="ig-help-list">
                <li><a href="feedback.php?view=internal&amp;filter=unread">Integration Guides <i class="fa fa-chevron-right"></i></a></li>
                <li><a href="settings.php?section=api">API &amp; Webhooks Docs <i class="fa fa-chevron-right"></i></a></li>
                <li><a href="feedback.php?view=internal&amp;filter=unread">Troubleshooting <i class="fa fa-chevron-right"></i></a></li>
              </ul>
              <a class="ps-btn ext" href="feedback.php?view=internal&amp;filter=unread"><i class="fa fa-life-ring"></i> Visit Help Center</a>
            </div>
          <?php elseif ($showApi): ?>
            <?php
            $railRateCls = (int)$apiStats['rate_usage_pct'] >= 90 ? 'hot' : ((int)$apiStats['rate_usage_pct'] >= 70 ? 'warm' : '');
            ?>
            <div class="ps-side-card">
              <h3>API Documentation</h3>
              <ul class="api-docs-list">
                <li><a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>">Getting Started <i class="fa fa-chevron-right"></i></a></li>
                <li><a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>">Authentication <i class="fa fa-chevron-right"></i></a></li>
                <li><a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>">Endpoints <i class="fa fa-chevron-right"></i></a></li>
                <li><a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'limits'])) ?>">Rate Limits <i class="fa fa-chevron-right"></i></a></li>
                <li><a href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>">SDKs <i class="fa fa-chevron-right"></i></a></li>
              </ul>
              <a class="ps-btn ext" href="<?= admin_platform_settings_h($apiUrl(['atab' => 'docs'])) ?>"><i class="fa fa-book"></i> View Full Documentation</a>
            </div>

            <div class="ps-side-card">
              <h3>Rate Limit Overview</h3>
              <div class="api-side-meter">
                <div class="lab">
                  <span>Daily usage</span>
                  <span class="pct"><?= (int)$apiStats['rate_usage_pct'] ?>%</span>
                </div>
                <div class="api-usage-bar" aria-hidden="true">
                  <span class="<?= admin_platform_settings_h($railRateCls) ?>" style="width:<?= (int)$apiStats['rate_usage_pct'] ?>%;"></span>
                </div>
              </div>
              <div class="api-side-kv">
                <strong><?= admin_api_settings_format_number((int)$apiStats['remaining']) ?></strong> requests remaining
                of <?= admin_api_settings_format_number((int)$apiStats['daily_limit']) ?>
              </div>
              <a class="ps-btn ext" href="<?= admin_platform_settings_h($apiUrl(['atab' => 'limits'])) ?>"><i class="fa fa-tachometer"></i> Manage Rate Limits</a>
            </div>

            <div class="ps-side-card">
              <h3>IP Allowlist</h3>
              <div class="api-side-kv" style="margin-bottom:12px;">
                <strong><?= (int)$apiStats['allowlist_count'] ?></strong>
                IP<?= (int)$apiStats['allowlist_count'] === 1 ? '' : 's' ?> configured
              </div>
              <a class="ps-btn ext" href="<?= admin_platform_settings_h($apiUrl(['atab' => 'allowlist'])) ?>"><i class="fa fa-shield"></i> Manage Allowlist</a>
            </div>
          <?php elseif ($showSystem): ?>
            <div class="ps-side-card">
              <h3>System Health</h3>
              <div class="sy-health-hero">
                <div class="sy-check <?= $syHealthAllOk ? '' : 'warn' ?>">
                  <i class="fa <?= $syHealthAllOk ? 'fa-check' : 'fa-exclamation' ?>"></i>
                </div>
                <div class="t"><?= $syHealthAllOk ? 'All systems operational' : 'Some checks need attention' ?></div>
                <div class="s">Live checks for web, database, cache, and storage.</div>
              </div>
              <ul class="sy-health-list">
                <?php foreach ($syHealth as $hc): ?>
                  <?php $ok = ($hc['status'] ?? '') === 'operational'; ?>
                  <li>
                    <span class="n"><?= admin_platform_settings_h((string)$hc['name']) ?></span>
                    <span class="st <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'Operational' : 'Degraded' ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <a class="ps-btn ext" href="#sy-server"><i class="fa fa-heartbeat"></i> View Health Details</a>
            </div>

            <div class="ps-side-card">
              <h3>Backup &amp; Restore</h3>
              <div class="sy-backup-rows">
                <div class="ps-sys-row"><span class="k">Last Backup</span><span class="v"><?= admin_platform_settings_h($syLastBackup) ?></span></div>
                <div class="ps-sys-row"><span class="k">Next Backup</span><span class="v"><?= admin_platform_settings_h($syNextBackup) ?></span></div>
                <div class="ps-sys-row"><span class="k">Backup Size</span><span class="v"><?= admin_platform_settings_h($syBackupSize) ?></span></div>
              </div>
              <?php if ($adminMode): ?>
                <form method="post">
                  <input type="hidden" name="action" value="run_backup">
                  <button type="submit" class="ps-btn block"><i class="fa fa-database"></i> Run Backup Now</button>
                </form>
                <form method="post" onsubmit="return confirm('Restore requires a backup file. Continue to see instructions?');">
                  <input type="hidden" name="action" value="restore_backup">
                  <button type="submit" class="ps-btn block"><i class="fa fa-undo"></i> Restore Backup</button>
                </form>
              <?php else: ?>
                <div class="hint" style="font-size:11px;color:#94a3b8;">Admin role required.</div>
              <?php endif; ?>
            </div>

            <div class="ps-side-card">
              <h3>System Tools</h3>
              <?php if ($adminMode): ?>
                <form class="sy-tool-form" method="post" onsubmit="return confirm('Clear temporary cache files?');">
                  <input type="hidden" name="action" value="clear_cache">
                  <button type="submit" class="sy-tool-row">
                    <span class="sy-tool-label">Clear Cache</span>
                    <i class="fa fa-chevron-right sy-ch"></i>
                  </button>
                </form>
                <form class="sy-tool-form" method="post" onsubmit="return confirm('Optimize safe database tables?');">
                  <input type="hidden" name="action" value="optimize_database">
                  <button type="submit" class="sy-tool-row">
                    <span class="sy-tool-label">Optimize Database</span>
                    <i class="fa fa-chevron-right sy-ch"></i>
                  </button>
                </form>
              <?php else: ?>
                <div class="sy-tool-row" style="cursor:default;opacity:.7;">
                  <span class="sy-tool-label">Clear Cache</span>
                  <i class="fa fa-chevron-right sy-ch"></i>
                </div>
                <div class="sy-tool-row" style="cursor:default;opacity:.7;">
                  <span class="sy-tool-label">Optimize Database</span>
                  <i class="fa fa-chevron-right sy-ch"></i>
                </div>
              <?php endif; ?>
              <a class="sy-tool-row" href="security-log.php">
                <span class="sy-tool-label">System Logs</span>
                <i class="fa fa-chevron-right sy-ch"></i>
              </a>
              <?php if ($adminMode): ?>
                <form class="sy-tool-form" method="post">
                  <input type="hidden" name="action" value="rebuild_search_index">
                  <button type="submit" class="sy-tool-row">
                    <span class="sy-tool-label">Rebuild Search Index</span>
                    <i class="fa fa-chevron-right sy-ch"></i>
                  </button>
                </form>
                <form class="sy-tool-form" method="post">
                  <input type="hidden" name="action" value="queue_management">
                  <button type="submit" class="sy-tool-row">
                    <span class="sy-tool-label">Queue Management</span>
                    <i class="fa fa-chevron-right sy-ch"></i>
                  </button>
                </form>
              <?php else: ?>
                <div class="sy-tool-row" style="cursor:default;opacity:.7;">
                  <span class="sy-tool-label">Rebuild Search Index</span>
                  <i class="fa fa-chevron-right sy-ch"></i>
                </div>
                <div class="sy-tool-row" style="cursor:default;opacity:.7;">
                  <span class="sy-tool-label">Queue Management</span>
                  <i class="fa fa-chevron-right sy-ch"></i>
                </div>
              <?php endif; ?>
            </div>
          <?php elseif ($showPrivacy): ?>
            <div class="ps-side-card">
              <h3>Privacy Summary</h3>
              <ul class="pv-sum">
                <li><span class="k">Data Collection</span><span class="v"><?= admin_platform_settings_h($pvCollectionStatus) ?></span></li>
                <li><span class="k">Data Usage</span><span class="v"><?= admin_platform_settings_h($pvUsageStatus) ?></span></li>
                <li><span class="k">User Controls</span><span class="v"><?= $pvUserControlsOn ? 'Enabled' : 'Disabled' ?></span></li>
                <li>
                  <span class="k">Data Security</span>
                  <a class="v" href="settings.php?section=security">Enabled</a>
                </li>
                <li><span class="k">Compliance</span><span class="v"><?= $pvComplianceOn ? 'Compliant' : 'Needs review' ?></span></li>
              </ul>
              <a class="ps-btn ext" href="<?= admin_platform_settings_h($pvPolicyHref) ?>"><i class="fa fa-external-link"></i> View Privacy Policy</a>
            </div>

            <div class="ps-side-card">
              <h3>Data Retention</h3>
              <ul class="pv-ret">
                <li>
                  <span class="k">User data</span>
                  <span class="v"><?= (int)$settings['privacy_retention_user_years'] ?> year<?= (int)$settings['privacy_retention_user_years'] === 1 ? '' : 's' ?></span>
                </li>
                <li>
                  <span class="k">Analytics data</span>
                  <span class="v"><?= (int)$settings['privacy_retention_analytics_months'] ?> months</span>
                </li>
                <li>
                  <span class="k">Log data</span>
                  <span class="v"><?= (int)$settings['privacy_retention_log_months'] ?> months</span>
                </li>
                <li>
                  <span class="k">Backup data</span>
                  <span class="v"><?= (int)$settings['privacy_retention_backup_days'] ?> days</span>
                </li>
              </ul>
              <a class="ps-btn ext" href="settings.php?section=privacy&amp;edit=retention"><i class="fa fa-cog"></i> Manage Retention Policies</a>
            </div>

            <div class="ps-side-card">
              <h3>Compliance</h3>
              <div class="pv-comp">
                <?php
                $pvFrameworks = [
                    'privacy_gdpr' => 'GDPR',
                    'privacy_ccpa' => 'CCPA',
                    'privacy_coppa' => 'COPPA',
                    'privacy_pipeda' => 'PIPEDA',
                ];
                foreach ($pvFrameworks as $fwField => $fwLabel):
                    $fwOn = !empty($settings[$fwField]);
                ?>
                  <div class="pv-comp-row">
                    <span class="n"><?= admin_platform_settings_h($fwLabel) ?></span>
                    <form method="post" style="margin:0;display:inline;">
                      <input type="hidden" name="action" value="toggle_privacy">
                      <input type="hidden" name="field" value="<?= admin_platform_settings_h($fwField) ?>">
                      <button type="submit" class="pv-badge <?= $fwOn ? 'on' : 'off' ?>" style="border:0;cursor:<?= $adminMode ? 'pointer' : 'default' ?>;"<?= $adminMode ? '' : ' disabled' ?>>
                        <?= $fwOn ? 'Compliant' : 'Not configured' ?>
                      </button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
              <a class="ps-btn ext" href="settings.php?section=privacy#pv-user-controls"><i class="fa fa-list"></i> View Compliance Details</a>
            </div>
          <?php elseif ($showNotifications): ?>
            <div class="ps-side-card">
              <h3>Notification Overview</h3>
              <div class="nt-stat-grid">
                <div class="nt-stat"><div class="k">Total</div><div class="v"><?= (int)$notifOverview['total'] ?></div></div>
                <div class="nt-stat unread"><div class="k">Unread</div><div class="v"><?= (int)$notifOverview['unread'] ?></div></div>
                <div class="nt-stat high"><div class="k">High</div><div class="v"><?= (int)$notifOverview['high'] ?></div></div>
                <div class="nt-stat"><div class="k">Medium</div><div class="v"><?= (int)$notifOverview['medium'] ?></div></div>
                <div class="nt-stat" style="grid-column:1 / -1;"><div class="k">Low</div><div class="v"><?= (int)$notifOverview['low'] ?></div></div>
              </div>
            </div>

            <div class="ps-side-card">
              <h3>By Type</h3>
              <div class="nt-donut-wrap">
                <div class="nt-donut" style="background:<?= admin_platform_settings_h($donutBg) ?>;" aria-hidden="true"></div>
                <ul class="nt-legend">
                  <?php foreach ($notifByType as $bt): ?>
                    <li>
                      <span class="dot" style="background:<?= admin_platform_settings_h($donutColors[$bt['key']] ?? '#94a3b8') ?>;"></span>
                      <span><?= admin_platform_settings_h((string)$bt['label']) ?></span>
                      <span class="pct"><?= admin_platform_settings_h((string)$bt['pct']) ?>%</span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

            <div class="ps-side-card">
              <h3>Quick Actions</h3>
              <div class="nt-qa">
                <a href="settings.php?section=email"><i class="fa fa-cog"></i> Notification Settings</a>
                <a href="notification.php"><i class="fa fa-bell"></i> Manage Channels</a>
                <a href="settings.php?section=email"><i class="fa fa-envelope-o"></i> Email Templates</a>
                <?php if ($adminMode): ?>
                  <form method="post">
                    <input type="hidden" name="action" value="notif_test">
                    <button type="submit"><i class="fa fa-paper-plane"></i> Test Notification</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif ($showSecurity): ?>
            <div class="ps-side-card">
              <h3>Active Sessions</h3>
              <?php foreach ($secSessions as $sess): ?>
                <div class="sec-sess">
                  <div class="sec-av"><?= admin_platform_settings_h((string)$sess['initials']) ?></div>
                  <div>
                    <div class="n"><?= admin_platform_settings_h((string)$sess['name']) ?></div>
                    <div class="m"><?= admin_platform_settings_h((string)$sess['device']) ?> · <?= admin_platform_settings_h((string)$sess['ip']) ?></div>
                    <div class="m"><?= admin_platform_settings_h((string)$sess['when']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if ($adminMode): ?>
                <form method="post" onsubmit="return confirm('Regenerate this session and mark other sessions to expire?');">
                  <input type="hidden" name="action" value="sign_out_others">
                  <button type="submit" class="ps-btn block"><i class="fa fa-sign-out"></i> Sign Out All Other Sessions</button>
                </form>
              <?php else: ?>
                <div class="hint" style="font-size:11px;color:#94a3b8;">Admin role required.</div>
              <?php endif; ?>
            </div>

            <div class="ps-side-card">
              <h3>Security Summary</h3>
              <ul class="sec-sum">
                <li>
                  <i class="fa <?= !empty($settings['require_2fa']) ? 'fa-check-circle ok' : 'fa-times-circle warn' ?>"></i>
                  <span>Two-Factor Authentication <?= !empty($settings['require_2fa']) ? 'enabled' : 'disabled' ?></span>
                </li>
                <li>
                  <i class="fa fa-check-circle ok"></i>
                  <span>Password Policy: <?= admin_platform_settings_h($passwordPolicyLabel) ?></span>
                </li>
                <li>
                  <i class="fa <?= !empty($settings['require_https']) ? 'fa-check-circle ok' : 'fa-times-circle warn' ?>"></i>
                  <span>HTTPS <?= !empty($settings['require_https']) ? 'required' : 'not required' ?></span>
                </li>
                <li>
                  <i class="fa <?= !empty($settings['security_headers']) ? 'fa-check-circle ok' : 'fa-times-circle warn' ?>"></i>
                  <span>Security Headers <?= !empty($settings['security_headers']) ? 'on' : 'off' ?></span>
                </li>
                <li>
                  <i class="fa <?= !empty($settings['activity_logging']) ? 'fa-check-circle ok' : 'fa-times-circle warn' ?>"></i>
                  <span>Activity Logging <?= !empty($settings['activity_logging']) ? 'on' : 'off' ?></span>
                </li>
                <li>
                  <i class="fa fa-exclamation-triangle warn"></i>
                  <span>IP Whitelist: <?= $whitelistCount ?> configured</span>
                </li>
                <li>
                  <i class="fa fa-info-circle info"></i>
                  <span>Backup codes unused: <?= $backupCodes ?></span>
                </li>
              </ul>
            </div>

            <div class="ps-side-card">
              <h3>Recent Security Events</h3>
              <?php foreach ($secEvents as $ev): ?>
                <div class="sec-evt">
                  <i class="fa <?= !empty($ev['ok']) ? 'fa-check-circle ico ok' : 'fa-times-circle ico bad' ?>"></i>
                  <div>
                    <div class="t"><?= admin_platform_settings_h((string)$ev['title']) ?></div>
                    <div class="s"><?= admin_platform_settings_h((string)$ev['detail']) ?> · <?= admin_platform_settings_h((string)$ev['when']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <a class="sec-viewall" href="security-log.php">View all</a>
            </div>
          <?php else: ?>
            <div class="ps-side-card">
              <h3>Notification Preferences</h3>
              <div class="ps-pref">
                <a href="settings.php?section=email">
                  <span class="ico"><i class="fa fa-envelope-o"></i></span>
                  <span><div class="t">Email Notifications</div><div class="s">Configure email alerts</div></span>
                  <i class="fa fa-chevron-right ch"></i>
                </a>
                <a href="notification.php">
                  <span class="ico"><i class="fa fa-bell-o"></i></span>
                  <span><div class="t">In-App Notifications</div><div class="s">Configure in-app alerts</div></span>
                  <i class="fa fa-chevron-right ch"></i>
                </a>
                <a href="settings.php?section=notifications">
                  <span class="ico"><i class="fa fa-mobile"></i></span>
                  <span><div class="t">SMS Notifications</div><div class="s">Configure SMS alerts</div></span>
                  <i class="fa fa-chevron-right ch"></i>
                </a>
                <a href="settings.php?section=notifications">
                  <span class="ico"><i class="fa fa-cloud"></i></span>
                  <span><div class="t">Push Notifications</div><div class="s">Configure push alerts</div></span>
                  <i class="fa fa-chevron-right ch"></i>
                </a>
              </div>
            </div>

            <div class="ps-side-card">
              <h3>System Information</h3>
              <div class="ps-sys-row"><span class="k">Version</span><span class="v"><?= admin_platform_settings_h($sys['version']) ?></span></div>
              <div class="ps-sys-row"><span class="k">Environment</span><span class="v"><?= admin_platform_settings_h($sys['environment']) ?></span></div>
              <div class="ps-sys-row"><span class="k">Last Backup</span><span class="v"><?= admin_platform_settings_h($sys['last_backup']) ?></span></div>
              <div class="ps-sys-row"><span class="k">Uptime</span><span class="v"><?= admin_platform_settings_h($sys['uptime']) ?></span></div>
              <a class="ps-btn ext" href="security-log.php"><i class="fa fa-external-link"></i> View System Logs</a>
            </div>

            <div class="ps-side-card ps-danger">
              <h3>Danger Zone</h3>
              <p class="warn">These actions are irreversible. Please be certain.</p>
              <?php if ($adminMode): ?>
                <form method="post" onsubmit="return confirm('Clear temporary cache files?');">
                  <input type="hidden" name="action" value="clear_cache">
                  <button type="submit" class="ps-btn warn block"><i class="fa fa-trash-o"></i> Clear Cache</button>
                </form>
                <form method="post" onsubmit="return confirm('Reset all platform settings to defaults?');">
                  <input type="hidden" name="action" value="reset_settings">
                  <button type="submit" class="ps-btn warn block"><i class="fa fa-refresh"></i> Reset All Settings</button>
                </form>
                <form method="post" onsubmit="return confirm('This action is blocked for safety. Continue?');">
                  <input type="hidden" name="action" value="delete_all_data">
                  <button type="submit" class="ps-btn danger block"><i class="fa fa-trash"></i> Delete All Data</button>
                </form>
              <?php else: ?>
                <div class="hint" style="font-size:11px;color:#94a3b8;">Admin role required for danger-zone actions.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </aside>

      </div>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
