<?php
declare(strict_types=1);

/**
 * Shop commerce alerts (org_shop_insert_commerce_notification) end with one of these
 * route markers. They belong to the Shop notifications hub, not the social bell.
 * Shop posts that get social reactions end with "[r:shop] [p:N]" and stay social.
 */
function app_notification_shop_like_patterns(): array
{
    $patterns = ['% [r:shop]', '% [r:orgsales]'];
    foreach (app_notification_shop_legacy_prefixes() as $prefix) {
        $patterns[] = $prefix . '%';
    }
    return $patterns;
}

/**
 * Opening words of every shop commerce alert. Rows written while notitype was
 * VARCHAR(100) were cut before their "[r:shop]" marker, so they are matched by prefix.
 */
function app_notification_shop_legacy_prefixes(): array
{
    return [
        'Payment incomplete for your order',
        'Pending — payment incomplete',
        'Your order',
        'Your item is now delivered',
        'New order',
        'Payment received',
        'Order (ORD-',
        'Order for ',
        'Order update',
    ];
}

/**
 * Widens notification.notitype so route markers ("[r:shop]", "[p:N]") are not cut off.
 * Returns the usable character capacity of the column.
 */
function app_notification_type_capacity(PDO $dbh): int
{
    static $capacity = null;
    if ($capacity !== null) {
        return $capacity;
    }
    $capacity = 100;
    try {
        $col = $dbh->query("SHOW COLUMNS FROM notification LIKE 'notitype'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $type = strtolower((string)($col['Type'] ?? ''));
        if (strpos($type, 'text') !== false) {
            $capacity = 60000;
        } elseif (preg_match('/varchar\((\d+)\)/', $type, $m)) {
            $capacity = (int)$m[1];
            if ($capacity < 600) {
                $nullSql = strtoupper((string)($col['Null'] ?? 'YES')) === 'NO' ? 'NOT NULL' : 'NULL';
                $default = $col['Default'] ?? null;
                $defaultSql = $default !== null ? ' DEFAULT ' . $dbh->quote((string)$default) : '';
                $dbh->exec("ALTER TABLE notification MODIFY notitype VARCHAR(600) {$nullSql}{$defaultSql}");
                $capacity = 600;
            }
        }
    } catch (Throwable $e) {
        // keep the detected capacity
    }
    return $capacity;
}

/** notitype NOT LIKE patterns for the social bell: chat rows + shop commerce alerts. */
function app_notification_social_exclude_patterns(): array
{
    return array_merge(
        ['New chat message%', 'Internal Chat%', 'New internal message%'],
        app_notification_shop_like_patterns()
    );
}

function app_notification_social_exclude_sql(string $column = 'notitype'): string
{
    return str_repeat(' AND ' . $column . ' NOT LIKE ?', count(app_notification_social_exclude_patterns()));
}

/** " AND (notitype LIKE ? OR ...)" — only shop commerce alerts. */
function app_notification_shop_only_sql(string $column = 'notitype'): string
{
    $parts = array_fill(0, count(app_notification_shop_like_patterns()), $column . ' LIKE ?');
    return ' AND (' . implode(' OR ', $parts) . ')';
}

function app_notification_is_shop_type(string $type): bool
{
    $type = trim($type);
    if (preg_match('/\s\[r:(?:shop|orgsales)\]$/i', $type)) {
        return true;
    }
    foreach (app_notification_shop_legacy_prefixes() as $prefix) {
        if (strncasecmp($type, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }
    return false;
}

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
    $channel = app_notification_is_shop_type($type) ? 'shop' : 'social';
    $liveId = 0;
    $route = '';
    $postId = 0;
    $commentId = 0;
    $isStory = false;
    $profileUserId = 0;

    $communityInviteId = 0;
    $communityId = 0;
    $communityMemberUserId = 0;

    while (preg_match('/\s\[(live|r|p|c|story|u|ci|cc|cm):([^\]]+)\]\s*$/', $type, $m)) {
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
        } elseif ($key === 'ci') {
            $communityInviteId = (int)$value;
        } elseif ($key === 'cc') {
            $communityId = (int)$value;
        } elseif ($key === 'cm') {
            $communityMemberUserId = (int)$value;
        }
        $type = trim((string)preg_replace('/\s\[(?:live|r|p|c|story|u|ci|cc|cm):[^\]]+\]\s*$/', '', $type, 1));
    }
    if (!$isStory && stripos($type, ' in a story') !== false) {
        $isStory = true;
    }

    $url = '';
    if ($liveId > 0) {
        $url = 'live_watch.php?live=' . $liveId;
    } elseif (($route === 'cinvr' || $route === 'cleft' || $route === 'cjoin' || $route === 'cjoinr' || $route === 'cpost' || $route === 'cevent') && $communityId > 0) {
        $url = 'community_profile.php?id=' . $communityId . ($route === 'cjoin' ? '&tab=members' : '');
        if ($postId > 0) {
            $url .= '&post=' . $postId;
        }
    } elseif ($communityInviteId > 0 || $route === 'cinv') {
        $url = 'community.php?tab=invitations' . ($communityInviteId > 0 ? ('&invite=' . $communityInviteId) : '');
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
        'channel' => $channel,
        'live_id' => $liveId,
        'post_id' => $postId,
        'comment_id' => $commentId,
        'is_story' => $isStory ? 1 : 0,
        'community_invite_id' => $communityInviteId,
      'community_id' => $communityId,
        'community_member_user_id' => $communityMemberUserId,
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
    " . app_notification_social_exclude_sql();
    $params = array_merge($receivers, app_notification_social_exclude_patterns());
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
        // Dual-write (username + email) creates two rows for one alert — count unique alerts.
        $stU = $dbh->prepare("
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM notification
                WHERE notireceiver IN ($receiverPh)
                  AND is_read = 0
                  " . app_notification_social_exclude_sql() . "
                GROUP BY notiuser, notitype
            ) AS uniq_noti
        ");
        $stU->execute(array_merge($receivers, app_notification_social_exclude_patterns()));
        $unread = (int)$stU->fetchColumn();
    } catch (Throwable $e) {
        $seenUnread = [];
        foreach ($rawRows as $rr) {
            if ((int)($rr['is_read'] ?? 0) !== 0) {
                continue;
            }
            $fp = strtolower(trim((string)($rr['notiuser'] ?? '')) . '|' . trim((string)($rr['notitype'] ?? '')));
            if ($fp === '|' || isset($seenUnread[$fp])) {
                continue;
            }
            $seenUnread[$fp] = true;
            $unread++;
        }
    }
    $items = [];
    $seenInviteIds = [];
    $seenJoinRequestKeys = [];
    $seenFingerprints = [];
    foreach ($rawRows as $row) {
        $item = app_notification_item_from_row($row);
        $inviteId = (int)($item['community_invite_id'] ?? 0);
        $joinMemberId = (int)($item['community_member_user_id'] ?? 0);
        $joinCommunityId = (int)($item['community_id'] ?? 0);
        if ($inviteId > 0) {
            if (isset($seenInviteIds[$inviteId])) {
                continue;
            }
            $seenInviteIds[$inviteId] = true;
        } elseif ($joinMemberId > 0 && $joinCommunityId > 0) {
            $jk = $joinCommunityId . ':' . $joinMemberId;
            if (isset($seenJoinRequestKeys[$jk])) {
                continue;
            }
            $seenJoinRequestKeys[$jk] = true;
        } else {
            $fp = strtolower(trim((string)($item['sender'] ?? '')) . '|' . trim((string)($item['text'] ?? '')));
            if ($fp !== '|' && isset($seenFingerprints[$fp])) {
                continue;
            }
            if ($fp !== '|') {
                $seenFingerprints[$fp] = true;
            }
        }
        $items[] = $item;
    }
    return ['ok' => true, 'unread' => $unread, 'items' => $items];
}

function app_notification_mark(PDO $dbh, array $receivers, int $id = 0, bool $all = false): bool
{
    if ($receivers === []) {
        return false;
    }
    $receiverPh = implode(',', array_fill(0, count($receivers), '?'));
    $exclude = app_notification_social_exclude_patterns();
    $excludeSql = app_notification_social_exclude_sql();
    if ($all) {
        $st = $dbh->prepare("
            UPDATE notification
            SET is_read = 1
            WHERE notireceiver IN ($receiverPh)
              AND is_read = 0
              $excludeSql
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
          $excludeSql
        LIMIT 1
    ");
    $st->execute(array_merge([$id], $receivers, $exclude));
    return $st->rowCount() > 0;
}
