<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }
function ensure_live_join_request_table(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_join_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                call_id BIGINT UNSIGNED NULL DEFAULT NULL,
                host_user_id INT NOT NULL,
                viewer_user_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_live_viewer (live_id, viewer_user_id),
                KEY idx_live_host_status (live_id, host_user_id, status),
                KEY idx_live_viewer_status (live_id, viewer_user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$meCode = strtoupper(trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? '')));
if ($meId <= 0 || $meCode === '') j(['ok' => false, 'error' => 'Invalid session']);

$liveId = (int)($_POST['live_id'] ?? 0);
$requestOnly = (string)($_POST['request_only'] ?? '') === '1';
if ($liveId <= 0) j(['ok' => false, 'error' => 'Missing live session']);

try {
    $stLive = $dbh->prepare("
        SELECT l.id, l.user_id, l.visibility, l.status, u.friend_code AS host_code
        FROM user_video_lives l
        JOIN users u ON u.id = l.user_id
        WHERE l.id = :id
        LIMIT 1
    ");
    $stLive->execute([':id' => $liveId]);
    $live = $stLive->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$live) j(['ok' => false, 'error' => 'Live session not found']);

    $hostId = (int)($live['user_id'] ?? 0);
    $hostCode = strtoupper(trim((string)($live['host_code'] ?? '')));
    $visibility = (string)($live['visibility'] ?? 'public');
    $status = (string)($live['status'] ?? '');

    $canJoin = ($hostId === $meId)
        || $visibility === 'public'
        || ($visibility === 'friends' && fs_are_friends($dbh, $meId, $hostId));

    if (!$canJoin) {
        $error = ($visibility === 'friends')
            ? 'This live is set to Friends only. Only accepted friends can request to join.'
            : 'You do not have access to this live session.';
        j(['ok' => false, 'error' => $error]);
    }
    if ($status !== 'live') j(['ok' => false, 'error' => 'This live session is not active']);
    if ($hostId <= 0 || $hostCode === '') j(['ok' => false, 'error' => 'Host not available']);
    if (!ensure_live_join_request_table($dbh)) j(['ok' => false, 'error' => 'Database error']);

    $requestRow = null;
    try {
        $stRequest = $dbh->prepare("
            SELECT id, call_id, status
            FROM user_video_live_join_requests
            WHERE live_id = :live_id
              AND viewer_user_id = :viewer_id
            LIMIT 1
        ");
        $stRequest->execute([
            ':live_id' => $liveId,
            ':viewer_id' => $meId,
        ]);
        $requestRow = $stRequest->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $requestRow = null;
    }

    $stOpen = $dbh->prepare("
        SELECT id, status
        FROM user_video_calls
        WHERE caller_user_id = :viewer_id
          AND callee_user_id = :host_id
          AND status IN ('initiated','ringing','active')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stOpen->execute([
        ':viewer_id' => $meId,
        ':host_id' => $hostId,
    ]);
    $open = $stOpen->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($open && !$requestOnly) {
        $existingStatus = (string)($open['status'] ?? '');
        j([
            'ok' => true,
            'call_id' => (int)($open['id'] ?? 0),
            'host_code' => $hostCode,
            'host_id' => $hostId,
            'status' => $existingStatus,
            'request_pending' => false,
            'approved' => true,
            'reused' => true,
        ]);
    }

    if ($requestOnly && $requestRow) {
        $requestStatus = (string)($requestRow['status'] ?? 'pending');
        $requestCallId = (int)($requestRow['call_id'] ?? 0);
        if ($requestStatus === 'approved' && $requestCallId > 0) {
            j([
                'ok' => true,
                'call_id' => $requestCallId,
                'host_code' => $hostCode,
                'host_id' => $hostId,
                'status' => 'initiated',
                'request_pending' => false,
                'approved' => true,
                'reused' => true,
            ]);
        }
        if ($requestStatus === 'pending' && $requestCallId > 0) {
            j([
                'ok' => true,
                'call_id' => $requestCallId,
                'host_code' => $hostCode,
                'host_id' => $hostId,
                'status' => 'requested',
                'request_pending' => true,
                'approved' => false,
                'reused' => true,
            ]);
        }
    }

    $stIns = $dbh->prepare("
        INSERT INTO user_video_calls
          (call_mode, caller_user_id, caller_code, callee_user_id, callee_code, status, created_at, updated_at)
        VALUES
          ('video', :caller_id, :caller_code, :callee_id, :callee_code, 'initiated', NOW(), NOW())
    ");
    $stIns->execute([
        ':caller_id' => $meId,
        ':caller_code' => $meCode,
        ':callee_id' => $hostId,
        ':callee_code' => $hostCode,
    ]);

    $callId = (int)$dbh->lastInsertId();

    if ($requestOnly) {
        $stRequestSave = $dbh->prepare("
            INSERT INTO user_video_live_join_requests
              (live_id, call_id, host_user_id, viewer_user_id, status, created_at, updated_at, responded_at)
            VALUES
              (:live_id, :call_id, :host_user_id, :viewer_user_id, 'pending', NOW(), NOW(), NULL)
            ON DUPLICATE KEY UPDATE
              call_id = VALUES(call_id),
              host_user_id = VALUES(host_user_id),
              status = 'pending',
              updated_at = NOW(),
              responded_at = NULL
        ");
        $stRequestSave->execute([
            ':live_id' => $liveId,
            ':call_id' => $callId,
            ':host_user_id' => $hostId,
            ':viewer_user_id' => $meId,
        ]);
    }

    j([
        'ok' => true,
        'call_id' => $callId,
        'host_code' => $hostCode,
        'host_id' => $hostId,
        'status' => $requestOnly ? 'requested' : 'initiated',
        'request_pending' => $requestOnly,
        'approved' => !$requestOnly,
        'reused' => false,
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
