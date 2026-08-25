<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json');
sendNoCacheHeadersUser();

$controller = new Controller();
$dbh = $controller->pdo();

if (!function_exists('userFriendCode') || !function_exists('userEmail') || !function_exists('userId')) {
    echo json_encode(['ok'=>false, 'error'=>'missing_user_helpers']);
    exit;
}

$meId    = (int)userId();
$meCode  = trim((string)userFriendCode());
$meEmail = trim((string)userEmail());

if ($meId <= 0 || ($meCode === '' && $meEmail === '')) {
    echo json_encode(['ok'=>false, 'error'=>'missing_identity']);
    exit;
}

// Helpers (same formatting as messages.php)
function fmt_time_short_php(string $dt): string {
    if ($dt === '') return '';
    $ts = strtotime($dt);
    return $ts ? date('h:i A', $ts) : '';
}
function fmt_time_full_php(string $dt): string {
    if ($dt === '') return '';
    $ts = strtotime($dt);
    return $ts ? date('M d, Y h:i A', $ts) : '';
}

/**
 * Thread list
 * This mirrors the SQL in messages.php (feedback table, friend_code/email compatibility)
 */
$sql = "
    SELECT
        u.id AS peer_id,
        u.friend_code AS peer_key,
        COALESCE(NULLIF(uc.display_name,''), u.friend_code) AS peer_display,

        (
            SELECT f2.feedbackdata
            FROM feedback f2
            WHERE f2.channel = 'user_user'
              AND (
                    (f2.sender IN (?, ?) AND f2.receiver IN (u.friend_code, u.email))
                 OR (f2.receiver IN (?, ?) AND f2.sender IN (u.friend_code, u.email))
              )
            ORDER BY f2.created_at DESC, f2.id DESC
            LIMIT 1
        ) AS last_message,

        (
            SELECT f2.created_at
            FROM feedback f2
            WHERE f2.channel = 'user_user'
              AND (
                    (f2.sender IN (?, ?) AND f2.receiver IN (u.friend_code, u.email))
                 OR (f2.receiver IN (?, ?) AND f2.sender IN (u.friend_code, u.email))
              )
            ORDER BY f2.created_at DESC, f2.id DESC
            LIMIT 1
        ) AS last_time,

        (
            SELECT COUNT(*)
            FROM feedback f3
            WHERE f3.channel = 'user_user'
              AND f3.is_read = 0
              AND f3.receiver IN (?, ?)
              AND f3.sender IN (u.friend_code, u.email)
        ) AS unread_count

    FROM users u
    LEFT JOIN user_contacts uc
      ON uc.owner_user_id = ?
     AND uc.friend_user_id = u.id
    WHERE u.status = 1
      AND u.friend_code <> ?
      AND EXISTS (
            SELECT 1
            FROM feedback f
            WHERE f.channel = 'user_user'
              AND (
                    (f.sender IN (?, ?) AND f.receiver IN (u.friend_code, u.email))
                 OR (f.receiver IN (?, ?) AND f.sender IN (u.friend_code, u.email))
              )
      )
    ORDER BY last_time DESC
";

$params = [
    $meCode, $meEmail,  $meCode, $meEmail,
    $meCode, $meEmail,  $meCode, $meEmail,

    $meCode, $meEmail,
    $meId,
    $meCode,
    $meCode, $meEmail,  $meCode, $meEmail,
];

$st = $dbh->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $key = (string)($r['peer_key'] ?? '');
    $display = (string)($r['peer_display'] ?? $key);
    $lastMsg = (string)($r['last_message'] ?? '');
    $lastTime = (string)($r['last_time'] ?? '');
    $lastTs = $lastTime !== '' ? (int)strtotime($lastTime) : 0;

    $out[] = [
        'peer_key' => $key,
        'peer_display' => $display,
        'last_message' => $lastMsg,
        'last_time_short' => fmt_time_short_php($lastTime),
        'last_time_full' => fmt_time_full_php($lastTime),
        'last_ts' => $lastTs,
        'unread_count' => (int)($r['unread_count'] ?? 0),
    ];
}

echo json_encode(['ok'=>true, 'threads'=>$out]);
