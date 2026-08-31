<?php
declare(strict_types=1);

/**
 * Loads Safety Center counts and report history.
 * Expects $dbh (PDO) and $meId (int). Optional $profileSettings.
 */
$securityCenterSettings = [
    'blocked_users_enabled' => 1,
    'hidden_users_enabled' => 1,
    'mute_users_enabled' => 1,
    'report_history_enabled' => 1,
];
$securityCenterCounts = ['blocked' => 0, 'hidden' => 0, 'muted' => 0, 'reports' => 0];
$securityCenterReports = [];

if (!isset($dbh) || !($dbh instanceof PDO) || (int)($meId ?? 0) <= 0) {
    return;
}

$ownerId = (int)$meId;
if (isset($profileSettings) && is_array($profileSettings)) {
    foreach ($securityCenterSettings as $k => $v) {
        if (array_key_exists($k, $profileSettings) && $profileSettings[$k] !== null && $profileSettings[$k] !== '') {
            $securityCenterSettings[$k] = (int)$profileSettings[$k];
        }
    }
} else {
    try {
        $st = $dbh->prepare('SELECT blocked_users_enabled, hidden_users_enabled, mute_users_enabled, report_history_enabled FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($securityCenterSettings as $k => $v) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $securityCenterSettings[$k] = (int)$row[$k];
            }
        }
    } catch (Throwable $e) {
    }
}

$possibleTables = [
    'public_user_blocks' => ['key' => 'blocked', 'userCol' => 'user_id'],
    'user_blocks' => ['key' => 'blocked', 'userCol' => 'user_id'],
    'public_hidden_users' => ['key' => 'hidden', 'userCol' => 'user_id'],
    'user_hidden_users' => ['key' => 'hidden', 'userCol' => 'user_id'],
    'public_muted_users' => ['key' => 'muted', 'userCol' => 'user_id'],
    'user_muted_users' => ['key' => 'muted', 'userCol' => 'user_id'],
    'public_user_reports' => ['key' => 'reports', 'userCol' => 'reporter_id'],
    'user_reports' => ['key' => 'reports', 'userCol' => 'reporter_id'],
];
foreach ($possibleTables as $table => $meta) {
    try {
        $chk = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
        if ($chk && $chk->fetchColumn()) {
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$meta['userCol']}` = :uid";
            $st = $dbh->prepare($sql);
            $st->execute([':uid' => $ownerId]);
            $securityCenterCounts[$meta['key']] = max($securityCenterCounts[$meta['key']], (int)$st->fetchColumn());
        }
    } catch (Throwable $e) {
    }
}

if (!empty($securityCenterSettings['report_history_enabled'])) {
    try {
        if (!function_exists('msb_reports_ensure_schema')) {
            require_once __DIR__ . '/msb_reports.php';
        }
        msb_reports_ensure_schema($dbh);
        $securityCenterReports = msb_reports_list_for_reporter($dbh, $ownerId, 40);
        $st = $dbh->prepare('SELECT COUNT(*) FROM public_user_reports WHERE reporter_id = :uid');
        $st->execute([':uid' => $ownerId]);
        $securityCenterCounts['reports'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
    }
}
