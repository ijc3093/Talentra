<?php
// /Business_only3/ajax/user_notifications_poll.php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $receivers = array_values(array_unique(array_filter([
        trim((string)($_SESSION['user_login'] ?? '')),
        trim((string)($_SESSION['user_email'] ?? '')),
    ], static function ($value) {
        return $value !== '';
    })));
    if (empty($receivers)) {
        echo json_encode(['ok' => false, 'unread' => 0, 'error' => 'No session']);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();
    $receiverPh = implode(',', array_fill(0, count($receivers), '?'));

    // ✅ block chat-related notifications from badge count
    $st = $dbh->prepare("
        SELECT COUNT(*)
        FROM notification
        WHERE notireceiver IN ($receiverPh)
          AND is_read = 0
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
    ");
    $st->execute(array_merge($receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));

    $unread = (int)$st->fetchColumn();

    $stList = $dbh->prepare("
        SELECT id, notiuser, notitype, created_at, is_read
        FROM notification
        WHERE notireceiver IN ($receiverPh)
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
        ORDER BY created_at DESC, id DESC
        LIMIT 8
    ");
    $stList->execute(array_merge($receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));

    $items = [];
    foreach (($stList->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $type = trim((string)($row['notitype'] ?? 'sent a notification'));
        $liveId = 0;
        $route = '';
        $postId = 0;
        $commentId = 0;

        while (preg_match('/\s\[(live|r|p|c):([^\]]+)\]\s*$/', $type, $m)) {
            $key = trim((string)($m[1] ?? ''));
            $value = trim((string)($m[2] ?? ''));
            if ($key === 'live') {
                $liveId = (int)$value;
            } elseif ($key === 'r') {
                $route = preg_replace('/[^a-z]/i', '', $value) ?? '';
            } elseif ($key === 'p') {
                $postId = (int)$value;
            } elseif ($key === 'c') {
                $commentId = (int)$value;
            }
            $type = trim((string)preg_replace('/\s\[(?:live|r|p|c):[^\]]+\]\s*$/', '', $type, 1));
        }

        $url = '';
        if ($liveId > 0) {
            $url = 'live_watch.php?live=' . $liveId;
        } elseif ($postId > 0) {
            $page = 'feed.php';
            if ($route === 'pf') {
                $page = 'profile.php';
            } elseif ($route === 'pb') {
                $page = 'public.php';
            }
            $params = ['open_post' => $postId];
            if ($commentId > 0) {
                $params['open_comment'] = $commentId;
            }
            $url = $page . '?' . http_build_query($params);
        }
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'sender' => trim((string)($row['notiuser'] ?? 'Someone')),
            'text' => $type,
            'live_id' => $liveId,
            'url' => $url,
            'created_at' => (string)($row['created_at'] ?? ''),
            'is_read' => (int)($row['is_read'] ?? 0),
        ];
    }

    echo json_encode(['ok' => true, 'unread' => $unread, 'items' => $items]);
} catch (Throwable $e) {
    // You can temporarily debug like this:
    // echo json_encode(['ok'=>false,'unread'=>0,'error'=>$e->getMessage()]);
    echo json_encode(['ok' => false, 'unread' => 0, 'error' => 'Server error']);
}
