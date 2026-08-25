<?php
declare(strict_types=1);

/**
 * User @mentions / people tags on public posts.
 * Uses public_post_tags (tagged_user_id) and notification messages containing "tagged" / "mentioned".
 */

if (!function_exists('msb_post_tags_ensure_schema')) {
    function msb_post_tags_ensure_schema(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS public_post_tags (
                  id INT(11) NOT NULL AUTO_INCREMENT,
                  post_id BIGINT(20) UNSIGNED NOT NULL,
                  tagged_user_id INT(11) NOT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_post_tagged (post_id, tagged_user_id),
                  KEY idx_post (post_id),
                  KEY idx_tagged (tagged_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Table may already exist without unique key.
        }
        try {
            $st = $dbh->query("SHOW INDEX FROM public_post_tags WHERE Key_name = 'uq_post_tagged'");
            $has = $st && $st->fetch(PDO::FETCH_ASSOC);
            if (!$has) {
                $dbh->exec('ALTER TABLE public_post_tags ADD UNIQUE KEY uq_post_tagged (post_id, tagged_user_id)');
            }
        } catch (Throwable $e) {
            // non-fatal
        }
    }
}

if (!function_exists('msb_mention_usernames_from_text')) {
    /**
     * @return list<string> lowercase usernames without @
     */
    function msb_mention_usernames_from_text(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/(?:^|[^A-Za-z0-9_])@([A-Za-z0-9_]{2,50})\b/u', $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $u) {
            $u = strtolower(trim((string)$u));
            if ($u === '' || isset($out[$u])) {
                continue;
            }
            $out[$u] = $u;
        }
        return array_values($out);
    }
}

if (!function_exists('msb_mention_resolve_user_ids')) {
    /**
     * @param list<string> $usernames
     * @return array<string,int> username(lower) => user id
     */
    function msb_mention_resolve_user_ids(PDO $dbh, array $usernames): array
    {
        $usernames = array_values(array_unique(array_filter(array_map(static function ($u) {
            return strtolower(trim((string)$u));
        }, $usernames), static function ($u) {
            return $u !== '' && preg_match('/^[a-z0-9_]{2,50}$/', $u);
        })));
        if ($usernames === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($usernames), '?'));
        try {
            $st = $dbh->prepare("
                SELECT id, LOWER(TRIM(username)) AS uname
                FROM users
                WHERE status = 1
                  AND LOWER(TRIM(username)) IN ({$ph})
            ");
            $st->execute($usernames);
            $map = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $uname = (string)($row['uname'] ?? '');
                $id = (int)($row['id'] ?? 0);
                if ($uname !== '' && $id > 0) {
                    $map[$uname] = $id;
                }
            }
            return $map;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('msb_mention_ids_from_texts')) {
    /**
     * @param list<string> $texts
     * @param list<int> $extraIds
     * @return list<int>
     */
    function msb_mention_ids_from_texts(PDO $dbh, array $texts, array $extraIds = []): array
    {
        $names = [];
        foreach ($texts as $t) {
            foreach (msb_mention_usernames_from_text((string)$t) as $n) {
                $names[$n] = $n;
            }
        }
        $map = msb_mention_resolve_user_ids($dbh, array_values($names));
        $ids = array_values($map);
        foreach ($extraIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique(array_filter($ids, static function ($id) {
            return (int)$id > 0;
        })));
    }
}

if (!function_exists('msb_post_tags_list_for_post')) {
    /**
     * @return list<int>
     */
    function msb_post_tags_list_for_post(PDO $dbh, int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }
        msb_post_tags_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT tagged_user_id FROM public_post_tags WHERE post_id = :pid');
            $st->execute([':pid' => $postId]);
            $ids = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($row['tagged_user_id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            return $ids;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('msb_post_tags_sync')) {
    /**
     * Replace tags for a post, notify newly tagged users.
     *
     * @param list<int> $taggedUserIds
     * @return list<int> final tagged ids
     */
    function msb_post_tags_sync(
        PDO $dbh,
        int $postId,
        int $actorId,
        array $taggedUserIds,
        string $visibility = 'friends',
        bool $notify = true
    ): array {
        if ($postId <= 0 || $actorId <= 0) {
            return [];
        }
        msb_post_tags_ensure_schema($dbh);

        $wanted = [];
        foreach ($taggedUserIds as $id) {
            $id = (int)$id;
            if ($id > 0 && $id !== $actorId) {
                $wanted[$id] = $id;
            }
        }
        $wanted = array_values($wanted);
        $existing = msb_post_tags_list_for_post($dbh, $postId);
        $existingMap = [];
        foreach ($existing as $id) {
            $existingMap[(int)$id] = true;
        }

        $toAdd = [];
        $wantedMap = [];
        foreach ($wanted as $id) {
            $wantedMap[$id] = true;
            if (!isset($existingMap[$id])) {
                $toAdd[] = $id;
            }
        }
        $toRemove = [];
        foreach ($existing as $id) {
            if (!isset($wantedMap[$id])) {
                $toRemove[] = $id;
            }
        }

        try {
            if ($toRemove !== []) {
                $ph = implode(',', array_fill(0, count($toRemove), '?'));
                $st = $dbh->prepare("DELETE FROM public_post_tags WHERE post_id = ? AND tagged_user_id IN ({$ph})");
                $st->execute(array_merge([$postId], $toRemove));
            }
            if ($toAdd !== []) {
                $st = $dbh->prepare('INSERT IGNORE INTO public_post_tags (post_id, tagged_user_id, created_at) VALUES (:pid, :uid, NOW())');
                foreach ($toAdd as $uid) {
                    $st->execute([':pid' => $postId, ':uid' => $uid]);
                }
            }
        } catch (Throwable $e) {
            return $existing;
        }

        if ($notify && $toAdd !== []) {
            msb_post_tags_notify($dbh, $actorId, $toAdd, $postId, $visibility);
        }

        return $wanted;
    }
}

if (!function_exists('msb_post_tags_notify')) {
    /**
     * @param list<int> $receiverIds
     */
    function msb_post_tags_notify(PDO $dbh, int $actorId, array $receiverIds, int $postId, string $visibility = 'friends'): void
    {
        if ($actorId <= 0 || $postId <= 0 || $receiverIds === []) {
            return;
        }
        $route = strtolower(trim($visibility)) === 'public' ? 'pb' : 'fd';
        if (function_exists('feedRouteForPostOwner')) {
            // Prefer feed helper when available (e.g. feed_api.php).
            $route = feedRouteForPostOwner($receiverIds[0] ?? 0, $actorId, $visibility);
        }
        foreach ($receiverIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0 || $rid === $actorId) {
                continue;
            }
            $notifRoute = $route;
            if (function_exists('feedRouteForPostOwner')) {
                $notifRoute = feedRouteForPostOwner($rid, $actorId, $visibility);
            }
            if (function_exists('feedAddNotification')) {
                feedAddNotification($dbh, $actorId, $rid, 'tagged you in a post', 'mention', [
                    'route' => $notifRoute,
                    'post_id' => $postId,
                ]);
                continue;
            }
            try {
                $stS = $dbh->prepare('SELECT id, name, username FROM users WHERE id = :id LIMIT 1');
                $stS->execute([':id' => $actorId]);
                $sender = $stS->fetch(PDO::FETCH_ASSOC) ?: [];
                $stR = $dbh->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
                $stR->execute([':id' => $rid]);
                $receiver = $stR->fetch(PDO::FETCH_ASSOC) ?: [];
                $senderLabel = trim((string)($sender['name'] ?? '')) !== ''
                    ? trim((string)$sender['name'])
                    : trim((string)($sender['username'] ?? ''));
                $receiverUsername = trim((string)($receiver['username'] ?? ''));
                if ($senderLabel === '' || $receiverUsername === '') {
                    continue;
                }
                $type = 'tagged you in a post [r:' . preg_replace('/[^a-z]/i', '', $notifRoute) . '] [p:' . $postId . ']';
                if (function_exists('mb_substr')) {
                    $type = mb_substr($type, 0, 100);
                } else {
                    $type = substr($type, 0, 100);
                }
                $st = $dbh->prepare('INSERT INTO notification (notiuser, notireceiver, notitype, is_read) VALUES (:s, :r, :t, 0)');
                $st->execute([
                    ':s' => $senderLabel,
                    ':r' => $receiverUsername,
                    ':t' => $type,
                ]);
            } catch (Throwable $e) {
                // non-fatal
            }
        }
    }
}

if (!function_exists('msb_comment_mentions_notify')) {
    /**
     * Notify users @mentioned in a comment (does not write public_post_tags).
     *
     * @param list<string> $texts
     */
    function msb_comment_mentions_notify(
        PDO $dbh,
        int $actorId,
        int $postId,
        int $commentId,
        string $text,
        int $postOwnerId,
        string $visibility
    ): void {
        if ($actorId <= 0 || $postId <= 0 || trim($text) === '') {
            return;
        }
        $ids = msb_mention_ids_from_texts($dbh, [$text], []);
        foreach ($ids as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0 || $rid === $actorId) {
                continue;
            }
            if (function_exists('feedAddNotification') && function_exists('feedRouteForPostOwner')) {
                feedAddNotification($dbh, $actorId, $rid, 'mentioned you in a comment', 'mention', [
                    'route' => feedRouteForPostOwner($rid, $postOwnerId, $visibility),
                    'post_id' => $postId,
                    'comment_id' => $commentId,
                ]);
                continue;
            }
            msb_post_tags_notify($dbh, $actorId, [$rid], $postId, $visibility);
        }
    }
}

if (!function_exists('msb_mention_search_users')) {
    /**
     * Autocomplete candidates: contacts first, then other active users.
     *
     * @return list<array{id:int,username:string,name:string,image:string}>
     */
    function msb_mention_search_users(PDO $dbh, int $meId, string $query, int $limit = 8): array
    {
        if ($meId <= 0) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $query = ltrim(trim($query), '@');
        $query = preg_replace('/[^A-Za-z0-9_]/', '', $query) ?? '';
        $params = [':me' => $meId];
        $searchSql = '';
        if ($query !== '') {
            $searchSql = ' AND (u.username LIKE :q OR u.name LIKE :q2)';
            $params[':q'] = $query . '%';
            $params[':q2'] = '%' . $query . '%';
        }
        $rows = [];
        try {
            // Friends / contacts first
            $st = $dbh->prepare("
                SELECT u.id, u.username, u.name, COALESCE(NULLIF(u.image,''), '') AS image
                FROM users u
                INNER JOIN user_contacts uc
                  ON uc.friend_user_id = u.id AND uc.owner_user_id = :me
                WHERE u.status = 1
                  AND u.id <> :me
                  AND COALESCE(NULLIF(TRIM(u.username), ''), '') <> ''
                  {$searchSql}
                ORDER BY u.username ASC
                LIMIT {$limit}
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }

        if (count($rows) < $limit) {
            $have = [];
            foreach ($rows as $r) {
                $have[(int)$r['id']] = true;
            }
            $need = $limit - count($rows);
            try {
                $st = $dbh->prepare("
                    SELECT u.id, u.username, u.name, COALESCE(NULLIF(u.image,''), '') AS image
                    FROM users u
                    WHERE u.status = 1
                      AND u.id <> :me
                      AND COALESCE(NULLIF(TRIM(u.username), ''), '') <> ''
                      {$searchSql}
                    ORDER BY
                      CASE WHEN u.username LIKE :qExact THEN 0 ELSE 1 END,
                      u.username ASC
                    LIMIT {$need}
                ");
                $p2 = $params;
                $p2[':qExact'] = ($query !== '' ? $query . '%' : '%');
                $st->execute($p2);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $id = (int)($r['id'] ?? 0);
                    if ($id <= 0 || isset($have[$id])) {
                        continue;
                    }
                    $rows[] = $r;
                    $have[$id] = true;
                    if (count($rows) >= $limit) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            $username = trim((string)($r['username'] ?? ''));
            if ($id <= 0 || $username === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'username' => $username,
                'name' => trim((string)($r['name'] ?? '')),
                'image' => trim((string)($r['image'] ?? '')),
            ];
        }
        return $out;
    }
}

if (!function_exists('msb_linkify_mentions_html')) {
    /**
     * Escape text then wrap @username in profile links.
     */
    function msb_linkify_mentions_html(string $text, string $profileBase = 'profile.php'): string
    {
        $esc = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $base = htmlspecialchars($profileBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return (string)preg_replace_callback(
            '/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]{2,50})\b/',
            static function (array $m) use ($base): string {
                $user = $m[2];
                $href = $base . '?username=' . rawurlencode($user);
                return $m[1] . '<a class="msb-mention" href="' . $href . '">@' . htmlspecialchars($user, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            },
            $esc
        );
    }
}
