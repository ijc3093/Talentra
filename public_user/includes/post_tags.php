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

if (!function_exists('msb_user_is_tagged_on_post')) {
    function msb_user_is_tagged_on_post(PDO $dbh, int $postId, int $userId): bool
    {
        if ($postId <= 0 || $userId <= 0) {
            return false;
        }
        msb_post_tags_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT 1 FROM public_post_tags WHERE post_id = :pid AND tagged_user_id = :uid LIMIT 1');
            $st->execute([':pid' => $postId, ':uid' => $userId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('msb_viewer_can_self_tag_post')) {
    /**
     * Friends (and anyone who can already see the post) may add the post to their Tags tab.
     */
    function msb_viewer_can_self_tag_post(PDO $dbh, int $viewerId, array $post): bool
    {
        $viewerId = (int)$viewerId;
        $ownerId = (int)($post['user_id'] ?? 0);
        $postId = (int)($post['id'] ?? $post['post_id'] ?? 0);
        if ($viewerId <= 0 || $ownerId <= 0 || $postId <= 0) {
            return false;
        }
        if ($viewerId === $ownerId) {
            return false; // owners use the full Tag people sheet
        }
        $vis = strtolower(trim((string)($post['visibility'] ?? 'friends')));
        if ($vis === 'private') {
            // Private posts are only self-taggable if already tagged (can view).
            return msb_user_is_tagged_on_post($dbh, $postId, $viewerId);
        }
        if ($vis === 'friends') {
            if (!function_exists('fs_are_friends')) {
                require_once __DIR__ . '/friend_system.php';
            }
            return function_exists('fs_are_friends') && fs_are_friends($dbh, $viewerId, $ownerId);
        }
        if ($vis === 'public') {
            // Public Discover / Clips: strangers may mention, but not tag.
            // Already-tagged viewers can still remove themselves.
            if (msb_user_is_tagged_on_post($dbh, $postId, $viewerId)) {
                return true;
            }
            if (!function_exists('fs_are_friends')) {
                require_once __DIR__ . '/friend_system.php';
            }
            return function_exists('fs_are_friends') && fs_are_friends($dbh, $viewerId, $ownerId);
        }
    }
}

if (!function_exists('msb_post_tags_self_set')) {
    /**
     * Add or remove only the viewer from public_post_tags (does not replace others).
     * Self-add does not notify (viewer opted in).
     */
    function msb_post_tags_self_set(PDO $dbh, int $postId, int $userId, bool $add): bool
    {
        if ($postId <= 0 || $userId <= 0) {
            return false;
        }
        msb_post_tags_ensure_schema($dbh);
        try {
            if ($add) {
                $st = $dbh->prepare('INSERT IGNORE INTO public_post_tags (post_id, tagged_user_id, created_at) VALUES (:pid, :uid, NOW())');
                $st->execute([':pid' => $postId, ':uid' => $userId]);
            } else {
                $st = $dbh->prepare('DELETE FROM public_post_tags WHERE post_id = :pid AND tagged_user_id = :uid');
                $st->execute([':pid' => $postId, ':uid' => $userId]);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
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

if (!function_exists('msb_post_tags_people_row')) {
    /**
     * @param array<string,mixed> $row
     * @return array{id:int,username:string,name:string,display_name:string,image:string}
     */
    function msb_post_tags_people_row(array $row): array
    {
        $id = (int)($row['id'] ?? 0);
        $username = trim((string)($row['username'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $display = $name !== '' ? $name : ($username !== '' ? $username : 'User');
        return [
            'id' => $id,
            'username' => $username,
            'name' => $name,
            'display_name' => $display,
            'image' => trim((string)($row['image'] ?? '')),
        ];
    }
}

if (!function_exists('msb_post_tags_people_for_posts')) {
    /**
     * Batch load tagged people for many posts.
     *
     * @param list<int> $postIds
     * @return array<int, list<array{id:int,username:string,name:string,display_name:string,image:string}>>
     */
    function msb_post_tags_people_for_posts(PDO $dbh, array $postIds): array
    {
        $ids = [];
        foreach ($postIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $ids[$pid] = $pid;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }
        msb_post_tags_ensure_schema($dbh);
        $out = [];
        foreach ($ids as $pid) {
            $out[$pid] = [];
        }
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $dbh->prepare("
                SELECT t.post_id, u.id, u.username, u.name, COALESCE(NULLIF(u.image,''), '') AS image
                FROM public_post_tags t
                INNER JOIN users u ON u.id = t.tagged_user_id
                WHERE t.post_id IN ({$ph}) AND u.status = 1
                ORDER BY t.post_id ASC, u.username ASC
            ");
            $st->execute($ids);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)($row['post_id'] ?? 0);
                if ($pid <= 0 || !isset($out[$pid])) {
                    continue;
                }
                $person = msb_post_tags_people_row($row);
                if ($person['id'] <= 0 || $person['username'] === '') {
                    continue;
                }
                $out[$pid][] = $person;
            }
        } catch (Throwable $e) {
            // non-fatal
        }
        return $out;
    }
}

if (!function_exists('msb_post_tags_people_for_post')) {
    /**
     * @return list<array{id:int,username:string,name:string,display_name:string,image:string}>
     */
    function msb_post_tags_people_for_post(PDO $dbh, int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }
        $map = msb_post_tags_people_for_posts($dbh, [$postId]);
        return $map[$postId] ?? [];
    }
}

if (!function_exists('msb_post_person_profile_href')) {
    function msb_post_person_profile_href(array $person, string $profileBase = 'profile.php'): string
    {
        $username = trim((string)($person['username'] ?? ''));
        if ($username !== '') {
            return $profileBase . '?username=' . rawurlencode($username);
        }
        $id = (int)($person['id'] ?? 0);
        if ($id > 0) {
            return $profileBase . '?id=' . $id;
        }
        return $profileBase;
    }
}

if (!function_exists('msb_post_sharing_with_name_html')) {
    /**
     * Talsora: "John is sharing with Akin."
     * When $taggedPeople is empty, returns the escaped author label (optionally linked).
     *
     * @param list<array<string,mixed>> $taggedPeople
     */
    function msb_post_sharing_with_name_html(
        string $authorDisplayName,
        string $authorHref,
        array $taggedPeople,
        array $opts = []
    ): string {
        $authorDisplayName = trim($authorDisplayName);
        if ($authorDisplayName === '') {
            $authorDisplayName = 'User';
        }
        $esc = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $linkClass = trim((string)($opts['link_class'] ?? 'msb-sharing-who'));
        $mutedClass = trim((string)($opts['muted_class'] ?? 'msb-sharing-with'));
        $linkAuthor = !array_key_exists('link_author', $opts) || !empty($opts['link_author']);
        $authorHref = trim($authorHref);

        $authorHtml = $esc($authorDisplayName);
        if ($linkAuthor && $authorHref !== '' && $authorHref !== '#') {
            $authorHtml = '<a class="' . $esc($linkClass) . '" href="' . $esc($authorHref) . '">' . $authorHtml . '</a>';
        }
        $afterAuthorHtml = (string)($opts['after_author_html'] ?? '');

        $people = [];
        foreach ($taggedPeople as $p) {
            if (!is_array($p)) {
                continue;
            }
            $label = trim((string)($p['display_name'] ?? ''));
            if ($label === '') {
                $label = trim((string)($p['name'] ?? ''));
            }
            if ($label === '') {
                $label = trim((string)($p['username'] ?? ''));
            }
            if ($label === '') {
                continue;
            }
            $href = msb_post_person_profile_href($p);
            $people[] = [
                'label' => $label,
                'href' => $href,
                'username' => trim((string)($p['username'] ?? '')),
            ];
        }
        if ($people === []) {
            return $authorHtml . $afterAuthorHtml;
        }

        $linkPerson = static function (array $person) use ($esc, $linkClass): string {
            $label = $esc((string)$person['label']);
            $href = trim((string)($person['href'] ?? ''));
            if ($href === '' || $href === '#') {
                return $label;
            }
            return '<a class="' . $esc($linkClass) . '" href="' . $esc($href) . '">' . $label . '</a>';
        };

        $muted = '<span class="' . $esc($mutedClass) . '"> is sharing with </span>';
        $head = $authorHtml . $afterAuthorHtml . $muted;
        $n = count($people);
        if ($n === 1) {
            return $head . $linkPerson($people[0]) . '.';
        }
        if ($n === 2) {
            return $head . $linkPerson($people[0])
                . '<span class="' . $esc($mutedClass) . '"> and </span>'
                . $linkPerson($people[1]) . '.';
        }
        $items = '';
        for ($i = 1; $i < $n; $i++) {
            $label = $esc((string)$people[$i]['label']);
            $href = trim((string)($people[$i]['href'] ?? ''));
            $uname = trim((string)($people[$i]['username'] ?? ''));
            $items .= '<a class="msb-sharing-others-item" role="option" href="' . $esc($href !== '' ? $href : '#') . '">'
                . '<span class="msb-sharing-others-name">' . $label . '</span>'
                . ($uname !== '' ? '<span class="msb-sharing-others-user">@' . $esc($uname) . '</span>' : '')
                . '</a>';
        }
        $others = '<span class="msb-sharing-others-wrap">'
            . '<button type="button" class="msb-sharing-others-btn" aria-expanded="false" aria-haspopup="listbox">Others</button>'
            . '<span class="msb-sharing-others-menu" role="listbox" hidden>' . $items . '</span>'
            . '</span>';
        return $head . $linkPerson($people[0])
            . '<span class="' . $esc($mutedClass) . '"> and </span>'
            . $others
            . '.';
    }
}

if (!function_exists('msb_text_is_people_tag_only')) {
    /**
     * True when text is only @username tokens (e.g. "@akin_t @dayo_a").
     */
    function msb_text_is_people_tag_only(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (!preg_match('/(?:^|[\s,])@[A-Za-z0-9_]{2,50}\b/u', ' ' . $text)) {
            return false;
        }
        $rest = (string)preg_replace('/@[A-Za-z0-9_]{2,50}\b/u', '', $text);
        $rest = (string)preg_replace('/[\s,.;:!?\-]+/u', '', $rest);
        return $rest === '';
    }
}

if (!function_exists('msb_display_post_text_without_tag_handles')) {
    /**
     * Hide mention-only captions when the post already uses people tags
     * ("John is sharing with Akin").
     *
     * @param list<array<string,mixed>>|list<int> $taggedPeople
     */
    function msb_display_post_text_without_tag_handles(string $text, array $taggedPeople = []): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if ($taggedPeople === []) {
            return $text;
        }
        if (msb_text_is_people_tag_only($text)) {
            return '';
        }
        return $text;
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

if (!function_exists('msb_post_is_story_id')) {
    function msb_post_is_story_id(PDO $dbh, int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        static $cache = [];
        if (array_key_exists($postId, $cache)) {
            return (bool)$cache[$postId];
        }
        if (!function_exists('post_is_story_only')) {
            $layoutFile = __DIR__ . '/post_layout.php';
            if (is_file($layoutFile)) {
                require_once $layoutFile;
            }
        }
        $row = [];
        try {
            $cols = 'id, title, body, description, visibility';
            if (function_exists('post_layout_column')) {
                $layoutCol = post_layout_column($dbh);
                if (is_string($layoutCol) && $layoutCol !== '') {
                    $cols .= ', `' . str_replace('`', '', $layoutCol) . '`';
                }
            }
            $st = $dbh->prepare('SELECT ' . $cols . ' FROM public_posts WHERE id = :id LIMIT 1');
            $st->execute([':id' => $postId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }
        $isStory = $row !== [] && function_exists('post_is_story_only') && post_is_story_only($row);
        $cache[$postId] = $isStory;
        return $isStory;
    }
}

if (!function_exists('msb_insert_user_notification')) {
    /**
     * Always write a notification row (does not depend on feed_api.php).
     *
     * @param array<string,mixed> $meta
     */
    function msb_insert_user_notification(PDO $dbh, int $actorId, int $receiverId, string $message, array $meta = []): bool
    {
        if ($actorId <= 0 || $receiverId <= 0 || $actorId === $receiverId || trim($message) === '') {
            return false;
        }
        $pref = trim((string)($meta['pref'] ?? ''));
        if ($pref !== '' && function_exists('profile_user_wants_notification') && !profile_user_wants_notification($dbh, $receiverId, $pref)) {
            return false;
        }
        try {
            $stS = $dbh->prepare('SELECT id, name, username FROM users WHERE id = :id LIMIT 1');
            $stS->execute([':id' => $actorId]);
            $sender = $stS->fetch(PDO::FETCH_ASSOC) ?: [];
            $stR = $dbh->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
            $stR->execute([':id' => $receiverId]);
            $receiver = $stR->fetch(PDO::FETCH_ASSOC) ?: [];
            $senderLabel = trim((string)($sender['name'] ?? ''));
            if ($senderLabel === '') {
                $senderLabel = trim((string)($sender['username'] ?? ''));
            }
            $receiverUsername = trim((string)($receiver['username'] ?? ''));
            if ($senderLabel === '' || $receiverUsername === '') {
                return false;
            }
            $type = trim($message);
            $route = trim((string)($meta['route'] ?? ''));
            $postId = (int)($meta['post_id'] ?? 0);
            $commentId = (int)($meta['comment_id'] ?? 0);
            $isStory = !empty($meta['story']) || !empty($meta['is_story']);
            if ($route !== '') {
                $type .= ' [r:' . preg_replace('/[^a-z]/i', '', $route) . ']';
            }
            if ($postId > 0) {
                $type .= ' [p:' . $postId . ']';
            }
            if ($commentId > 0) {
                $type .= ' [c:' . $commentId . ']';
            }
            if ($isStory) {
                $type .= ' [story:1]';
            }
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
            return true;
        } catch (Throwable $e) {
            return false;
        }
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
        $isStory = msb_post_is_story_id($dbh, $postId);
        $message = $isStory ? 'tagged you in a story' : 'tagged you in a post';
        $route = strtolower(trim($visibility)) === 'public' ? 'pb' : 'fd';
        if (function_exists('feedRouteForPostOwner')) {
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
            msb_insert_user_notification($dbh, $actorId, $rid, $message, [
                'route' => $notifRoute,
                'post_id' => $postId,
                'story' => $isStory ? 1 : 0,
                'pref' => 'tagged_notifications',
            ]);
        }
    }
}

if (!function_exists('msb_post_mentions_ensure_schema')) {
    function msb_post_mentions_ensure_schema(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS public_post_mentions (
                  id INT(11) NOT NULL AUTO_INCREMENT,
                  post_id BIGINT(20) UNSIGNED NOT NULL,
                  mentioned_user_id INT(11) NOT NULL,
                  mentioned_by INT(11) NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_post_mentioned (post_id, mentioned_user_id),
                  KEY idx_post (post_id),
                  KEY idx_mentioned (mentioned_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // non-fatal
        }
    }
}

if (!function_exists('msb_user_is_mentioned_on_post')) {
    function msb_user_is_mentioned_on_post(PDO $dbh, int $postId, int $userId): bool
    {
        if ($postId <= 0 || $userId <= 0) {
            return false;
        }
        msb_post_mentions_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT 1 FROM public_post_mentions WHERE post_id = :pid AND mentioned_user_id = :uid LIMIT 1');
            $st->execute([':pid' => $postId, ':uid' => $userId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('msb_post_mentions_notify')) {
    /**
     * Fries → Mention: notify people about a post (no Tags tab write).
     *
     * @param list<int> $receiverIds
     * @return list<int> newly notified user ids
     */
    function msb_post_mentions_notify(
        PDO $dbh,
        int $actorId,
        array $receiverIds,
        int $postId,
        string $visibility = 'friends'
    ): array {
        if ($actorId <= 0 || $postId <= 0 || $receiverIds === []) {
            return [];
        }
        msb_post_mentions_ensure_schema($dbh);
        $notified = [];
        $isStory = msb_post_is_story_id($dbh, $postId);
        $message = $isStory ? 'mentioned you in a story' : 'mentioned you in a post';
        $route = strtolower(trim($visibility)) === 'public' ? 'pb' : 'fd';
        foreach ($receiverIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0 || $rid === $actorId) {
                continue;
            }
            try {
                $st = $dbh->prepare('INSERT IGNORE INTO public_post_mentions (post_id, mentioned_user_id, mentioned_by, created_at) VALUES (:pid, :uid, :by, NOW())');
                $st->execute([':pid' => $postId, ':uid' => $rid, ':by' => $actorId]);
            } catch (Throwable $e) {
                // still try notify
            }

            $notifRoute = $route;
            if (function_exists('feedRouteForPostOwner')) {
                $notifRoute = feedRouteForPostOwner($rid, $actorId, $visibility);
            }
            if (msb_insert_user_notification($dbh, $actorId, $rid, $message, [
                'route' => $notifRoute,
                'post_id' => $postId,
                'story' => $isStory ? 1 : 0,
                'pref' => 'tagged_notifications',
            ])) {
                $notified[] = $rid;
            }
        }
        return array_values(array_unique($notified));
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
