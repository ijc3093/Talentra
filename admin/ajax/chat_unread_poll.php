<?php
// /Business_only3/admin/ajax/chat_unread_poll.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$controller = new Controller();
$dbh = $controller->pdo();

/**
 * IMPORTANT:
 * Internal admin chat stores sender/receiver as friend_code (ADM-XXXX-XXXX).
 * So unread must be counted by my friend_code, not my username.
 */
$meCode = myAdminFriendCode();
if ($meCode === '') {
    // identity.php contains ensureAdminFriendCode(PDO $dbh) which sets session too
    $meCode = ensureAdminFriendCode($dbh);
}
if ($meCode === '') {
    // fallback only for legacy data
    $meCode = myUsername();
}

$role = myRoleId();

if ($meCode === '' || $role <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'Invalid session']);
    exit;
}

try {
    $unread = 0;

    // ✅ internal unread (receiver=friend_code)
    $internalChannels = allowedInternalChannelsForMe();
    if (!empty($internalChannels)) {

        // Use named placeholders to avoid HY093 with dynamic IN (...)
        $bind = [':me' => $meCode];
        $keys = [];

        foreach (array_values($internalChannels) as $i => $ch) {
            $k = ':ch' . $i;
            $keys[] = $k;
            $bind[$k] = $ch;
        }

        $inSql = implode(',', $keys);

        $st = $dbh->prepare("
            SELECT COUNT(*)
            FROM feedback_admin
            WHERE receiver = :me
              AND channel IN ($inSql)
              AND is_read = 0
        ");
        $st->execute($bind);
        $unread += (int)$st->fetchColumn();
    }

    // ✅ public unread only for Admin base role
    if (isAdmin()) {
        $st2 = $dbh->prepare("
            SELECT COUNT(*)
            FROM feedback_admin
            WHERE channel = 'user_admin'
              AND receiver = 'Admin'
              AND is_read = 0
        ");
        $st2->execute();
        $unread += (int)$st2->fetchColumn();
    }

    echo json_encode(['ok'=>true, 'unread'=>$unread]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'Server error']);
    exit;
}
