<?php
declare(strict_types=1);

function app_notification_receivers(PDO $dbh, int $userId, array $sessionBits = []): array
{
    $receivers = [];
    foreach ($sessionBits as $bit) {
        $v = trim((string)$bit);
        if ($v !== '') {
            $receivers[] = $v;
        }
    }
    if ($userId > 0) {
        try {
            $st = $dbh->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['username', 'email'] as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '') {
                    $receivers[] = $v;
                }
            }
        } catch (Throwable $e) {
            // keep session receivers
        }
    }
    $out = [];
    foreach ($receivers as $v) {
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;
}

function app_notification_item_from_row(array $row): array
{
    $type = trim((string)($row['notitype'] ?? 'sent a notification'));
    $liveId = 0;
    $route = '';
    $postId = 0;
    $commentId = 0;
    $isStory = false;
    $profileUserId = 0;

    while (preg_match('/\s\[(live|r|p|c|story|u):([^\]]+)\]\s*$/', $type, $m)) {
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
        } elseif ($key === 'story') {
            $isStory = ((int)$value === 1) || strtolower($value) === '1';
        } elseif ($key === 'u') {
            $profileUserId = (int)$value;
        }
        $type = trim((string)preg_replace('/\s\[(?:live|r|p|c|story|u):[^\]]+\]\s*$/', '', $type, 1));
    }
    if (!$isStory && stripos($type, ' in a story') !== false) {
        $isStory = true;
    }

    $url = '';
    if ($liveId > 0) {
        $url = 'live_watch.php?live=' . $liveId;
    } elseif ($postId > 0 && $isStory) {
        $url = 'home.php?tab=for-you&story_post=' . $postId;
    } elseif ($postId > 0) {
        $page = 'feed.php';
        if ($route === 'pf') {
            $page = 'profile.php';
        } elseif ($route === 'pb') {
            $page = 'public.php';
        } elseif ($route === 'shop') {
            $page = 'shop.php';
        } elseif ($route === 'orgsales') {
            $page = 'org_shop.php';
        } elseif ($route === 'fd') {
            $page = 'home.php';
        }
        $params = ['open_post' => $postId];
        if ($page === 'home.php') {
            $params['tab'] = 'for-you';
        }
        if ($commentId > 0) {
            $params['open_comment'] = $commentId;
        }
        $typeLower = strtolower($type);
        if (strpos($typeLower, 'mention') !== false
            || strpos($typeLower, 'tagged you') !== false
            || strpos($typeLower, 'comment') !== false
            || strpos($typeLower, 'replied') !== false
            || $commentId > 0) {
            $params['hide_nav'] = 1;
        }
        $url = $page . '?' . http_build_query($params);
    } elseif ($profileUserId > 0) {
        $url = 'profile.php?tab=about&id=' . $profileUserId;
    } elseif ($route === 'pf') {
        $url = 'profile.php?tab=about';
    } elseif (stripos($type, 'friend request') !== false) {
        $url = 'contact_requests.php';
    }

    $sender = trim((string)($row['notiuser'] ?? 'Someone')) ?: 'Someone';
    return [
        'id' => (int)($row['id'] ?? 0),
        'sender' => $sender,
        'text' => $type,
        'live_id' => $liveId,
        'post_id' => $postId,
        'comment_id' => $commentId,
        'is_story' => $isStory ? 1 : 0,
        'url' => $url,
        'created_at' => (string)($row['created_at'] ?? ''),
        'is_read' => (int)($row['is_read'] ?? 0),
        'avatar_url' => 'avatar.php?name=' . rawurlencode($sender),
    ];
}

function app_notification_fetch(PDO $dbh, array $receivers, int $userId, bool $unreadOnly = false, int $limit = 200): array
{
    if ($receivers === []) {
        return ['ok' => false, 'unread' => 0, 'items' => [], 'error' => 'No session'];
    }
    $receiverPh = implode(',', array_fill(0, count($receivers), '?'));
    $sql = "
        SELECT id, notiuser, notitype, created_at, is_read
        FROM notification
        WHERE notireceiver IN ($receiverPh)
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
    ";
    $params = array_merge($receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']);
    if ($unreadOnly) {
        $sql .= ' AND is_read = 0';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(400, $limit));
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rawRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (function_exists('profile_filter_notification_rows')) {
        $rawRows = profile_filter_notification_rows($dbh, $userId, $rawRows);
    }
    $unread = 0;
    try {
        $stU = $dbh->prepare("
            SELECT COUNT(*)
            FROM notification
            WHERE notireceiver IN ($receiverPh)
              AND is_read = 0
              AND notitype NOT LIKE ?
              AND notitype NOT LIKE ?
              AND notitype NOT LIKE ?
        ");
        $stU->execute(array_merge($receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));
        $unread = (int)$stU->fetchColumn();
    } catch (Throwable $e) {
        foreach ($rawRows as $rr) {
            if ((int)($rr['is_read'] ?? 0) === 0) {
                $unread++;
            }
        }
    }
    $items = [];
    foreach ($rawRows as $row) {
        $items[] = app_notification_item_from_row($row);
    }
    return ['ok' => true, 'unread' => $unread, 'items' => $items];
}

function app_notification_mark(PDO $dbh, array $receivers, int $id = 0, bool $all = false): bool
{
    if ($receivers === []) {
        return false;
    }
    $receiverPh = implode(',', array_fill(0, count($receivers), '?'));
    $exclude = ['New chat message%', 'Internal Chat%', 'New internal message%'];
    if ($all) {
        $st = $dbh->prepare("
            UPDATE notification
            SET is_read = 1
            WHERE notireceiver IN ($receiverPh)
              AND is_read = 0
              AND notitype NOT LIKE ?
              AND notitype NOT LIKE ?
              AND notitype NOT LIKE ?
        ");
        $st->execute(array_merge($receivers, $exclude));
        return true;
    }
    if ($id <= 0) {
        return false;
    }
    $st = $dbh->prepare("
        UPDATE notification
        SET is_read = 1
        WHERE id = ?
          AND notireceiver IN ($receiverPh)
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
        LIMIT 1
    ");
    $st->execute(array_merge([$id], $receivers, $exclude));
    return $st->rowCount() > 0;
}
