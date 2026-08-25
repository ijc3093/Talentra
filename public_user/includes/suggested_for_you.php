<?php
declare(strict_types=1);

/**
 * Suggested for you panel — Add friend, Follow publishers, Advertise brands.
 * Include on public.php / news.php right rail. Set $suggestedForYouMode = 'none' on feed.php.
 *
 * Optional before include:
 *   $suggestedForYouStaffReadonly, $suggestedForYouMaxFriends, $suggestedForYouMaxFollow,
 *   $suggestedForYouMaxAdvertise
 */
$suggestedForYouMode = strtolower(trim((string)($suggestedForYouMode ?? ($feedRightRailMode ?? 'panel'))));
$sfyModeIsPage = ($suggestedForYouMode === 'page');

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sfy_avatar_url')) {
    function sfy_avatar_url(array $user, int $size = 96): string
    {
        $params = [];
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $email = trim((string)($user['email'] ?? ''));
        $friendCode = strtoupper(trim((string)($user['friend_code'] ?? '')));
        $username = trim((string)($user['username'] ?? ''));
        $name = trim((string)($user['display_name'] ?? $user['name'] ?? $username));
        if ($userId > 0) {
            $params[] = 'u=' . rawurlencode((string)$userId);
        }
        if ($email !== '') {
            $params[] = 'email=' . rawurlencode($email);
        }
        if ($friendCode !== '') {
            $params[] = 'friend_code=' . rawurlencode($friendCode);
        }
        if ($username !== '') {
            $params[] = 'username=' . rawurlencode($username);
        }
        if ($name !== '') {
            $params[] = 'name=' . rawurlencode($name);
        }
        $params[] = 's=' . rawurlencode((string)$size);
        return 'avatar.php?' . implode('&', $params);
    }
}

if (!function_exists('sfy_profile_href')) {
    /** Profile URL for suggested people/publishers (matches profile.php query params). */
    function sfy_profile_href(array $row): string
    {
        $friendCode = strtoupper(trim((string)($row['friend_code'] ?? '')));
        $username = trim((string)($row['username'] ?? ''));
        $id = (int)($row['id'] ?? $row['user_id'] ?? 0);

        if ($friendCode !== '') {
            return 'profile.php?friend_code=' . rawurlencode($friendCode);
        }
        if ($username !== '') {
            return 'profile.php?username=' . rawurlencode($username);
        }
        if ($id > 0) {
            return 'profile.php?id=' . $id;
        }
        return 'profile.php';
    }
}

if (!function_exists('sfy_user_row')) {
    /** @param array<string, mixed> $row */
    function sfy_user_row(array $row, string $kind, string $subtitle, string $actionLabel): array
    {
        $id = (int)($row['id'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['username'] ?? ''));
        }
        if ($name === '') {
            $name = $kind === 'friend' ? 'User' : 'Publisher';
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'name' => $name,
            'username' => trim((string)($row['username'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'friend_code' => trim((string)($row['friend_code'] ?? '')),
            'image' => trim((string)($row['image'] ?? '')),
            'subtitle' => $subtitle,
            'profile_href' => sfy_profile_href($row),
            'action_label' => $actionLabel,
        ];
    }
}

if (!function_exists('sfy_friend_rows')) {
    function sfy_friend_rows(PDO $dbh, int $meId, int $limit = 3, string $query = ''): array
    {
        if ($meId <= 0 || $limit <= 0) {
            return [];
        }
        require_once __DIR__ . '/publisher_accounts.php';
        if (publisher_workspace_viewer($dbh, $meId) && empty($GLOBALS['suggestedForYouIncludePeople'])) {
            return [];
        }
        $limit = max(1, min($limit, 100));
        $query = trim($query);
        $searchSql = '';
        $searchParams = [];
        if ($query !== '') {
            $searchSql = "
                  AND (
                    u.name LIKE :qName OR u.username LIKE :qUser OR u.email LIKE :qEmail
                    OR UPPER(COALESCE(u.friend_code, '')) LIKE :qCode
                  )";
            $qLike = '%' . $query . '%';
            $searchParams = [
                ':qName' => $qLike,
                ':qUser' => $qLike,
                ':qEmail' => $qLike,
                ':qCode' => '%' . strtoupper($query) . '%',
            ];
        }
        $selectCols = 'u.id, u.name, u.username, u.email, u.image, u.friend_code';
        $orderLimit = " ORDER BY u.id DESC LIMIT {$limit}";
        $attempts = [
            [
                'sql' => "
                SELECT {$selectCols},
                  (
                    SELECT COUNT(*)
                    FROM user_contacts fc
                    INNER JOIN user_contacts mf
                      ON mf.owner_user_id = fc.friend_user_id
                     AND mf.friend_user_id = u.id
                    WHERE fc.owner_user_id = :meMut
                  ) AS mutual_count
                FROM users u
                WHERE COALESCE(u.status, 1) = 1
                  AND u.id <> :me
                  AND COALESCE(NULLIF(TRIM(u.account_kind), ''), 'personal') = 'personal'
                  AND UPPER(COALESCE(u.friend_code, '')) NOT LIKE 'PUB-%'
                  AND NOT EXISTS (
                    SELECT 1 FROM user_contacts uc
                    WHERE uc.owner_user_id = :meA AND uc.friend_user_id = u.id
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM user_contacts uc2
                    WHERE uc2.owner_user_id = u.id AND uc2.friend_user_id = :meB
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM contact_requests cr
                    WHERE cr.status = 'pending'
                      AND (
                        (cr.from_user_id = :meC AND cr.to_user_id = u.id)
                        OR (cr.from_user_id = u.id AND cr.to_user_id = :meD)
                      )
                  ){$searchSql}
                ORDER BY mutual_count DESC, u.id DESC
                LIMIT {$limit}",
                'params' => array_merge([
                    ':me' => $meId, ':meMut' => $meId, ':meA' => $meId, ':meB' => $meId, ':meC' => $meId, ':meD' => $meId,
                ], $searchParams),
            ],
            [
                'sql' => "
                SELECT {$selectCols}
                FROM users u
                WHERE COALESCE(u.status, 1) = 1
                  AND u.id <> :me
                  AND COALESCE(NULLIF(TRIM(u.account_kind), ''), 'personal') = 'personal'
                  AND UPPER(COALESCE(u.friend_code, '')) NOT LIKE 'PUB-%'
                  AND NOT EXISTS (
                    SELECT 1 FROM user_contacts uc
                    WHERE uc.owner_user_id = :meA AND uc.friend_user_id = u.id
                  ){$searchSql}{$orderLimit}",
                'params' => array_merge([':me' => $meId, ':meA' => $meId], $searchParams),
            ],
            [
                'sql' => "
                SELECT {$selectCols}
                FROM users u
                WHERE COALESCE(u.status, 1) = 1
                  AND u.id <> :me
                  AND UPPER(COALESCE(u.friend_code, '')) NOT LIKE 'PUB-%'
                  AND NOT EXISTS (
                    SELECT 1 FROM user_contacts uc
                    WHERE uc.owner_user_id = :meA AND uc.friend_user_id = u.id
                  ){$searchSql}{$orderLimit}",
                'params' => array_merge([':me' => $meId, ':meA' => $meId], $searchParams),
            ],
            [
                'sql' => "
                SELECT {$selectCols}
                FROM users u
                WHERE COALESCE(u.status, 1) = 1
                  AND u.id <> :me
                  AND UPPER(COALESCE(u.friend_code, '')) NOT LIKE 'PUB-%'
                  {$searchSql}{$orderLimit}",
                'params' => array_merge([':me' => $meId], $searchParams),
            ],
        ];

        $rows = [];
        foreach ($attempts as $attempt) {
            try {
                $st = $dbh->prepare($attempt['sql']);
                $st->execute($attempt['params']);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    break;
                }
            } catch (Throwable $e) {
                $rows = [];
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) <= 0) {
                continue;
            }
            $mutualCount = (int)($row['mutual_count'] ?? 0);
            if ($mutualCount > 0) {
                $subtitle = $mutualCount . ' mutual friend' . ($mutualCount === 1 ? '' : 's');
            } else {
                $subtitle = $query !== '' ? 'People' : 'Suggested friend';
            }
            $out[] = sfy_user_row($row, 'friend', $subtitle, 'Add Friend');
        }
        return $out;
    }
}

if (!function_exists('sfy_publisher_rows')) {
    /**
     * @param int[] $excludeIds
     * @return array<int, array<string, mixed>>
     */
    function sfy_publisher_rows(PDO $dbh, int $meId, int $limit = 3, array $excludeIds = [], string $query = ''): array
    {
        if ($meId <= 0 || $limit <= 0) {
            return [];
        }
        require_once __DIR__ . '/publisher_accounts.php';
        if (!publisher_can_browse_publisher_suggestions($dbh, $meId)) {
            return [];
        }

        $query = trim($query);
        // Never suggest the viewer to themselves (publisher must follow other publishers).
        $excludeIds[] = $meId;
        if ($query !== '') {
            $hits = publisher_search($dbh, $query, $limit + 5, true);
            if (!$hits) {
                return [];
            }
            $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), static fn(int $id): bool => $id > 0)));
            $following = [];
            try {
                $stFollow = $dbh->prepare('SELECT following_id FROM public_follows WHERE follower_id = :me');
                $stFollow->execute([':me' => $meId]);
                foreach ($stFollow->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fid) {
                    $following[(int)$fid] = true;
                }
            } catch (Throwable $e) {
                $following = [];
            }
            $cats = publisher_categories();
            $out = [];
            foreach ($hits as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0 || $id === $meId || isset($following[$id]) || in_array($id, $excludeIds, true)) {
                    continue;
                }
                $catKey = (string)($row['publisher_category'] ?? '');
                $catLabel = $cats[$catKey] ?? '';
                $tagline = trim((string)($row['publisher_tagline'] ?? ''));
                $subtitle = $tagline !== '' ? $tagline : ($catLabel !== '' ? $catLabel : 'Publisher');
                $out[] = sfy_user_row($row, 'publisher', $subtitle, 'Follow');
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        }

        $limit = max(1, min($limit, 100));
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), static fn(int $id): bool => $id > 0)));
        $excludeSql = '';
        if ($excludeIds) {
            $excludeSql = ' AND u.id NOT IN (' . implode(',', $excludeIds) . ')';
        }

        $discoverableSql = publisher_public_discoverable_publisher_sql($dbh, 'u');
        try {
            $st = $dbh->prepare("
                SELECT u.id, u.name, u.username, u.email, u.friend_code, u.image,
                       u.publisher_category, u.publisher_tagline
                FROM users u
                WHERE u.status = 1
                  AND COALESCE(u.account_kind, 'personal') = 'publisher'
                  AND u.id <> :meSelf
                  AND {$discoverableSql}
                  AND NOT EXISTS (
                    SELECT 1 FROM public_follows pf
                    WHERE pf.follower_id = :me AND pf.following_id = u.id
                  ){$excludeSql}
                ORDER BY u.name ASC
                LIMIT {$limit}
            ");
            $st->execute([':me' => $meId, ':meSelf' => $meId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            try {
                $st = $dbh->prepare("
                    SELECT u.id, u.name, u.username, u.email, u.friend_code, u.image,
                           u.publisher_category, u.publisher_tagline
                    FROM users u
                    WHERE COALESCE(u.status, 1) = 1
                      AND u.id <> :meSelf
                      AND (
                        COALESCE(u.account_kind, 'personal') = 'publisher'
                        OR UPPER(COALESCE(u.friend_code, '')) LIKE 'PUB-%'
                      )
                      AND NOT EXISTS (
                        SELECT 1 FROM public_follows pf
                        WHERE pf.follower_id = :me AND pf.following_id = u.id
                      ){$excludeSql}
                    ORDER BY u.name ASC
                    LIMIT {$limit}
                ");
                $st->execute([':me' => $meId, ':meSelf' => $meId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {
                $rows = [];
            }
        }

        $cats = publisher_categories();
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || $id === $meId) {
                continue;
            }
            $catKey = (string)($row['publisher_category'] ?? '');
            $catLabel = $cats[$catKey] ?? '';
            $tagline = trim((string)($row['publisher_tagline'] ?? ''));
            $subtitle = $tagline !== '' ? $tagline : ($catLabel !== '' ? $catLabel : 'Publisher');
            $out[] = sfy_user_row($row, 'publisher', $subtitle, 'Follow');
        }
        return $out;
    }
}

if (!function_exists('sfy_advertise_catalog_labels')) {
    /** Brand names commonly used for the advertise strip. */
    function sfy_advertise_catalog_labels(): array
    {
        return [
            'Bank of America',
            'PNC',
            'Chase',
            'Wells Fargo',
            'Capital One',
            'American Express',
            'Verizon',
            'AT&T',
        ];
    }
}

if (!function_exists('sfy_advertise_rows')) {
    /**
     * Public discoverable publisher brands for the advertise list.
     * @param int[] $excludeIds
     */
    function sfy_advertise_rows(PDO $dbh, int $meId, int $limit = 3, array $excludeIds = []): array
    {
        if ($meId <= 0 || $limit <= 0) {
            return [];
        }
        require_once __DIR__ . '/publisher_accounts.php';
        if (!publisher_can_browse_publisher_suggestions($dbh, $meId)) {
            return [];
        }

        $limit = max(1, min($limit, 100));
        $excludeIds[] = $meId;
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), static fn(int $id): bool => $id > 0)));
        $excludeSql = '';
        if ($excludeIds) {
            $excludeSql = ' AND u.id NOT IN (' . implode(',', $excludeIds) . ')';
        }

        $discoverableSql = publisher_public_discoverable_publisher_sql($dbh, 'u');
        $advertiseNames = [];
        foreach (sfy_advertise_catalog_labels() as $label) {
            $advertiseNames[] = mb_strtolower(publisher_registry_normalize_name($label));
        }
        foreach (publisher_public_discoverable_catalog_names_lower() as $name => $_) {
            $advertiseNames[] = $name;
        }
        $advertiseNames = array_values(array_unique(array_filter($advertiseNames)));
        if (!$advertiseNames) {
            return [];
        }

        $namePlaceholders = [];
        $params = [':me' => $meId, ':meSelf' => $meId];
        foreach ($advertiseNames as $i => $name) {
            $key = ':advName' . $i;
            $namePlaceholders[] = $key;
            $params[$key] = $name;
        }
        $nameIn = implode(',', $namePlaceholders);

        try {
            $st = $dbh->prepare("
                SELECT u.id, u.name, u.username, u.email, u.friend_code, u.image,
                       u.publisher_category, u.publisher_tagline
                FROM users u
                WHERE u.status = 1
                  AND COALESCE(u.account_kind, 'personal') = 'publisher'
                  AND u.id <> :meSelf
                  AND {$discoverableSql}
                  AND LOWER(TRIM(COALESCE(u.name, ''))) IN ({$nameIn})
                  AND NOT EXISTS (
                    SELECT 1 FROM public_follows pf
                    WHERE pf.follower_id = :me AND pf.following_id = u.id
                  ){$excludeSql}
                ORDER BY u.name ASC
                LIMIT {$limit}
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $cats = publisher_categories();
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || $id === $meId) {
                continue;
            }
            $catKey = (string)($row['publisher_category'] ?? '');
            $catLabel = $cats[$catKey] ?? '';
            $tagline = trim((string)($row['publisher_tagline'] ?? ''));
            $subtitle = $tagline !== '' ? $tagline : ($catLabel !== '' ? $catLabel : 'Sponsored');
            $out[] = sfy_user_row($row, 'advertise', $subtitle, 'Follow');
        }
        return $out;
    }
}

if (!function_exists('sfy_render_row')) {
    /** @param array<string, mixed> $row */
    function sfy_render_row(array $row): void
    {
        $kind = (string)($row['kind'] ?? '');
        $rowId = (int)($row['id'] ?? 0);
        $profileHref = trim((string)($row['profile_href'] ?? ''));
        if ($profileHref === '') {
            $profileHref = 'profile.php';
        }
        $avatarUser = [
            'id' => $rowId,
            'name' => (string)($row['name'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'friend_code' => (string)($row['friend_code'] ?? ''),
            'image' => (string)($row['image'] ?? ''),
        ];
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string)($row['name'] ?? ''),
            (string)($row['username'] ?? ''),
            (string)($row['email'] ?? ''),
            (string)($row['friend_code'] ?? ''),
            (string)($row['subtitle'] ?? ''),
        ])));
        ?>
        <li class="sfy-row" data-sfy-kind="<?= h($kind) ?>" data-sfy-id="<?= $rowId ?>" data-sfy-hay="<?= h($haystack) ?>">
          <a class="sfy-avatar" href="<?= h($profileHref) ?>" title="<?= h('Open ' . (string)($row['name'] ?? 'profile')) ?>" aria-label="<?= h('Open ' . (string)($row['name'] ?? 'profile')) ?>">
            <img src="<?= h(sfy_avatar_url($avatarUser, 64)) ?>" alt="" loading="lazy" width="28" height="28"
              data-name="<?= h((string)($row['name'] ?? 'User')) ?>"
              onerror="this.onerror=null;this.src='avatar.php?name='+encodeURIComponent(this.getAttribute('data-name')||'U')+'&amp;s=64';">
          </a>
          <div class="sfy-meta">
            <a class="sfy-name" href="<?= h($profileHref) ?>" title="<?= h('Open ' . (string)($row['name'] ?? 'profile')) ?>"><?= h((string)($row['name'] ?? '')) ?></a>
            <div class="sfy-sub"><?= h((string)($row['subtitle'] ?? '')) ?></div>
          </div>
          <?php if ($kind === 'friend'): ?>
            <button type="button" class="sfy-action friend-btn primary" data-peer-id="<?= $rowId ?>" data-status="none" aria-label="Add Friend">
              +
            </button>
            <button type="button" class="sfy-dismiss" data-sfy-dismiss="<?= $rowId ?>" aria-label="Dismiss suggestion">×</button>
          <?php else: ?>
            <button type="button" class="sfy-action publisher-follow-btn" data-publisher-id="<?= $rowId ?>">
              <?= h((string)($row['action_label'] ?? 'Follow')) ?>
            </button>
          <?php endif; ?>
        </li>
        <?php
    }
}

$sfyMeId = (int)($meId ?? $_SESSION['user_id'] ?? 0);
$sfyDbh = $dbh ?? null;
if (!$sfyDbh instanceof PDO && $sfyMeId > 0) {
    require_once __DIR__ . '/../controller.php';
    $sfyDbh = (new Controller())->pdo();
}

$sfyStaffReadonly = !empty($suggestedForYouStaffReadonly ?? $feedRightRailStaffReadonly ?? false);
if (!isset($suggestedForYouStaffReadonly) && !isset($feedRightRailStaffReadonly)) {
    try {
        require_once __DIR__ . '/staff_publisher_access.php';
        $sfyStaffReadonly = staff_pub_is_readonly();
    } catch (Throwable $e) {
        $sfyStaffReadonly = false;
    }
}

$sfyCap = $sfyModeIsPage ? 100 : 20;
$sfyMaxFriends = max(0, min($sfyCap, (int)($suggestedForYouMaxFriends ?? ($sfyModeIsPage ? 100 : 12))));
$sfyMaxFollow = max(0, min($sfyCap, (int)($suggestedForYouMaxFollow ?? ($sfyModeIsPage ? 30 : 12))));
$sfyMaxAdvertise = max(0, min($sfyCap, (int)($suggestedForYouMaxAdvertise ?? ($sfyModeIsPage ? 30 : 3))));
$sfySearchQ = trim((string)($suggestedForYouSearchQ ?? $_GET['q'] ?? ''));
$sfySearchActive = ($sfyModeIsPage && $sfySearchQ !== '');

$sfyCanFollow = false;
$sfyIsPublisherWorkspace = false;
$sfyCanBrowsePublishers = false;
if ($sfyDbh instanceof PDO && $sfyMeId > 0) {
    try {
        require_once __DIR__ . '/publisher_accounts.php';
        $sfyIsPublisherWorkspace = publisher_workspace_viewer($sfyDbh, $sfyMeId);
        $sfyCanFollow = publisher_can_follow_as_viewer($sfyDbh, $sfyMeId);
        $sfyCanBrowsePublishers = publisher_can_browse_publisher_suggestions($sfyDbh, $sfyMeId);
    } catch (Throwable $e) {
        $sfyCanFollow = false;
        $sfyIsPublisherWorkspace = false;
        $sfyCanBrowsePublishers = false;
    }
}

$sfyCanShowPersonal = $sfyMaxFriends > 0 && (
    !empty($GLOBALS['suggestedForYouIncludePeople'])
    || (!$sfyIsPublisherWorkspace && !$sfyStaffReadonly)
);

$sfyPageTab = 'people';
if ($sfyModeIsPage) {
    if ($sfyIsPublisherWorkspace) {
        $sfyPageTab = 'publishers';
    } else {
        $sfyTabRaw = strtolower(trim((string)($_GET['tab'] ?? 'people')));
        if ($sfyTabRaw === 'publishers' && $sfyCanBrowsePublishers) {
            $sfyPageTab = 'publishers';
        }
        if ($sfyStaffReadonly || $sfyMaxFriends <= 0) {
            if ($sfyCanBrowsePublishers) {
                $sfyPageTab = 'publishers';
            }
        }
    }
}

if (!function_exists('sfy_page_tab_href')) {
    function sfy_page_tab_href(string $tab, string $q = ''): string
    {
        $params = ['tab' => $tab];
        $q = trim($q);
        if ($q !== '') {
            $params['q'] = $q;
        }
        return 'suggested_for_you.php?' . http_build_query($params);
    }
}

if ($suggestedForYouMode === 'none') {
    return;
}

$sfyFriends = [];
$sfyFollow = [];
$sfyAdvertise = [];

if ($sfyDbh instanceof PDO && $sfyMeId > 0) {
    if ($sfyModeIsPage) {
        if ($sfyPageTab === 'people' && $sfyCanShowPersonal) {
            $sfyFriends = sfy_friend_rows($sfyDbh, $sfyMeId, $sfyMaxFriends, $sfySearchQ);
        } elseif ($sfyPageTab === 'publishers' && $sfyCanBrowsePublishers && $sfyMaxFollow > 0) {
            $sfyFollow = sfy_publisher_rows($sfyDbh, $sfyMeId, $sfyMaxFollow, [], $sfySearchQ);
            if (!$sfySearchActive && $sfyMaxAdvertise > 0) {
                $excludeIds = array_column($sfyFollow, 'id');
                $sfyAdvertise = sfy_advertise_rows($sfyDbh, $sfyMeId, $sfyMaxAdvertise, $excludeIds);
            }
        }
    } elseif ($sfySearchActive) {
        if ($sfyCanShowPersonal) {
            $sfyFriends = sfy_friend_rows($sfyDbh, $sfyMeId, $sfyMaxFriends, $sfySearchQ);
        }
        if ($sfyCanBrowsePublishers && $sfyMaxFollow > 0) {
            $sfyFollow = sfy_publisher_rows($sfyDbh, $sfyMeId, $sfyMaxFollow, [], $sfySearchQ);
        }
    } else {
        if ($sfyCanShowPersonal) {
            $sfyFriends = sfy_friend_rows($sfyDbh, $sfyMeId, $sfyMaxFriends);
        }
        if ($sfyCanBrowsePublishers && $sfyMaxFollow > 0) {
            $sfyFollow = sfy_publisher_rows($sfyDbh, $sfyMeId, $sfyMaxFollow);
        }
        if (!$sfyFriends && $sfyFollow) {
            $sfyFriends = $sfyFollow;
        }
        $excludeIds = array_merge(
            array_column($sfyFollow, 'id'),
            array_column($sfyFriends, 'id')
        );
        if ($sfyCanBrowsePublishers && $sfyMaxAdvertise > 0) {
            $sfyAdvertise = sfy_advertise_rows($sfyDbh, $sfyMeId, $sfyMaxAdvertise, $excludeIds);
        }
    }
}

$sfyScope = $sfyModeIsPage ? 'body.sfy-page' : 'body.feed-insta-ui';
?>
<style>
  <?php if (!$sfyModeIsPage): ?>
  body.feed-insta-ui .feed-right-rail .sfy-panel{
    margin:0;padding:0;
    display:flex;flex-direction:column;
    flex:0 0 auto;
    height:auto !important;
    max-height:none !important;
    min-height:0;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-head{
    flex:0 0 auto;margin:0 0 12px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-head .sfy-head{margin:0;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body{
    flex:0 1 auto !important;
    min-height:0;
    max-height:148px !important;
    overflow-x:hidden !important;
    overflow-y:auto !important;
    overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
    padding-right:2px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-body::-webkit-scrollbar{width:6px;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body::-webkit-scrollbar-track{background:transparent;margin:2px 0;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.18);border-radius:999px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-body .sfy-row{
    padding-right:2px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-body .sfy-action{
    margin-right:2px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-scroll-rail{
    flex:0 0 auto;display:flex;justify-content:flex-end;gap:8px;padding-top:10px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-scroll-btn{
    width:34px;height:34px;border:0;border-radius:50%;background:#111827;color:#fff;
    display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
    box-shadow:0 6px 16px rgba(15,23,42,.18);padding:0;
  }
  body.feed-insta-ui .feed-right-rail .sfy-scroll-btn svg{
    display:block;width:16px;height:16px;stroke:currentColor;fill:none;
    stroke-width:2;stroke-linecap:round;stroke-linejoin:round;
  }
  body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:hover,
  body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:focus{background:#0f172a;outline:none;}
  body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:disabled{opacity:.35;cursor:default;box-shadow:none;}
  body.feed-insta-ui .feed-right-rail{
    display:flex;flex-direction:column;gap:10px;
    overflow-y:auto;overflow-x:hidden;
    overscroll-behavior:contain;
    scrollbar-width:thin;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel,
  body.feed-insta-ui .feed-right-rail .home-right-card{
    flex:0 0 auto;
    display:flex;flex-direction:column;min-height:0;
    background:var(--msb-palette-bg, #fff);
    border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));
    border-radius:12px;
    padding:10px 10px 8px;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-rail .home-right-card-scroll{
    flex:0 1 auto;min-height:0;max-height:148px;
    overflow-x:hidden;overflow-y:auto;
    overscroll-behavior:contain;-webkit-overflow-scrolling:touch;
    scrollbar-width:thin;padding-right:2px;
  }
  body.feed-insta-ui .feed-right-rail .home-right-card-scroll::-webkit-scrollbar{width:6px;}
  body.feed-insta-ui .feed-right-rail .home-right-card-scroll::-webkit-scrollbar-track{background:transparent;}
  body.feed-insta-ui .feed-right-rail .home-right-card-scroll::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.18);border-radius:999px;
  }
  body.feed-insta-ui .feed-right-rail .home-right-card-head{
    display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 8px;
  }
  body.feed-insta-ui .feed-right-rail .home-right-card-title,
  body.feed-insta-ui .feed-right-rail .sfy-title{
    margin:0;font-size:13px;font-weight:700;line-height:1.2;color:var(--msb-palette-text,#0f172a);
  }
  body.feed-insta-ui .feed-right-rail .home-right-card-see{
    font-size:11px;font-weight:700;color:#7c3aed;text-decoration:none;white-space:nowrap;
  }
  body.feed-insta-ui .feed-right-rail .home-right-card-see:hover{text-decoration:underline;}
  body.feed-insta-ui .feed-right-rail .home-right-empty{margin:0;font-size:11px;color:#737373;}
  body.feed-insta-ui .feed-right-rail .home-trend-list,
  body.feed-insta-ui .feed-right-rail .home-event-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;}
  body.feed-insta-ui .feed-right-rail .home-trend-row{display:flex;align-items:flex-start;gap:8px;}
  body.feed-insta-ui .feed-right-rail .home-trend-num{flex:0 0 14px;padding-top:1px;font-size:11px;color:#94a3b8;font-weight:600;}
  body.feed-insta-ui .feed-right-rail .home-trend-body{min-width:0;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:1px;}
  body.feed-insta-ui .feed-right-rail .home-trend-body strong{font-size:12px;font-weight:700;color:#0f172a;}
  body.feed-insta-ui .feed-right-rail .home-trend-body span{font-size:10px;color:#737373;}
  body.feed-insta-ui .feed-right-rail .home-event-row{display:flex;align-items:flex-start;gap:8px;text-decoration:none;color:inherit;}
  body.feed-insta-ui .feed-right-rail button.home-event-row{
    width:100%;border:0;background:transparent;padding:0;margin:0;text-align:left;cursor:pointer;font:inherit;
  }
  body.feed-insta-ui .feed-right-rail .home-event-bday .home-event-date{border-color:#f9a8d4;background:#fff1f2;}
  body.feed-insta-ui .feed-right-rail .home-event-bday .home-event-date em{color:#db2777;}
  body.feed-insta-ui .feed-right-rail .home-event-date{
    flex:0 0 36px;width:36px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;
    display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4px 0 5px;box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-rail .home-event-date em{font-style:normal;font-size:8px;font-weight:800;letter-spacing:.04em;color:#dc2626;}
  body.feed-insta-ui .feed-right-rail .home-event-date strong{font-size:14px;line-height:1;font-weight:800;color:#0f172a;}
  body.feed-insta-ui .feed-right-rail .home-event-meta{min-width:0;display:flex;flex-direction:column;gap:1px;padding-top:1px;}
  body.feed-insta-ui .feed-right-rail .home-event-meta strong{font-size:12px;font-weight:700;color:#0f172a;line-height:1.25;}
  body.feed-insta-ui .feed-right-rail .home-event-meta span{font-size:10px;color:#737373;line-height:1.3;}
  <?php else: ?>
  body.sfy-page .sh-pagebody{padding-top:12px;height:calc(100vh - 120px);overflow:hidden;box-sizing:border-box;}
  body.sfy-page .sfy-page-main{
    max-width:640px;margin:0 auto;padding:24px 16px 16px;height:100%;
    display:flex;flex-direction:column;box-sizing:border-box;
  }
  body.sfy-page .sfy-page-top{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px;padding-top:24px;}
  body.sfy-page .sfy-tabs{
    flex:0 0 auto;display:flex;align-items:stretch;gap:0;margin:0 0 12px;
    border-bottom:1px solid rgba(15,23,42,.1);
  }
  body.sfy-page .sfy-tab{
    flex:1 1 0;min-width:0;padding:10px 8px 12px;border:0;border-bottom:2px solid transparent;
    background:transparent;color:#737373;font-size:14px;font-weight:700;line-height:1.2;
    text-align:center;text-decoration:none;cursor:pointer;box-sizing:border-box;
  }
  body.sfy-page .sfy-tab:hover,body.sfy-page .sfy-tab:focus{color:#0d0d0d;outline:none;}
  body.sfy-page .sfy-tab.is-active{color:#0d0d0d;border-bottom-color:#0d0d0d;}
  body.sfy-page .sfy-page-toolbar{flex:0 0 auto;margin:0 0 12px;}
  body.sfy-page .sfy-page-toolbar .sfy-search{margin:0;}
  body.sfy-page .sfy-page-title{margin:0;font-size:24px;font-weight:800;line-height:1.2;color:var(--msb-palette-text-on-nav,#0d0d0d);}
  body.sfy-page .sfy-page-back{font-size:13px;font-weight:700;color:#0095f6;text-decoration:none;white-space:nowrap;}
  body.sfy-page .sfy-page-back:hover,body.sfy-page .sfy-page-back:focus{text-decoration:underline;outline:none;}
  body.sfy-page .sfy-page-panel{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;}
  body.sfy-page .sfy-page-panel .sfy-panel-body{
    flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;
    overscroll-behavior:contain;-webkit-overflow-scrolling:touch;
    scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.18) transparent;
  }
  body.sfy-page .sfy-page-panel .sfy-panel-body::-webkit-scrollbar{width:5px;}
  body.sfy-page .sfy-page-panel .sfy-panel-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.18);border-radius:999px;}
  body.sfy-page .sfy-scroll-rail{flex:0 0 auto;display:flex;justify-content:flex-end;gap:8px;padding-top:10px;}
  body.sfy-page .sfy-scroll-btn{
    width:34px;height:34px;border:0;border-radius:50%;background:#111827;color:#fff;
    display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
    box-shadow:0 6px 16px rgba(15,23,42,.18);padding:0;
  }
  body.sfy-page .sfy-scroll-btn svg{
    display:block;width:16px;height:16px;stroke:currentColor;fill:none;
    stroke-width:2;stroke-linecap:round;stroke-linejoin:round;
  }
  body.sfy-page .sfy-scroll-btn:hover,body.sfy-page .sfy-scroll-btn:focus{background:#0f172a;outline:none;}
  body.sfy-page .sfy-scroll-btn:disabled{opacity:.35;cursor:default;box-shadow:none;}
  body.sfy-page .sfy-empty{margin:0;padding:14px 0;font-size:14px;color:#737373;}
  <?php endif; ?>
  <?= $sfyScope ?> .sfy-block + .sfy-block{margin-top:18px;padding-top:14px;border-top:1px solid rgba(15,23,42,.08);}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body .sfy-block{margin-top:0;padding-top:0;border-top:0;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body .sfy-block + .sfy-block{margin-top:18px;padding-top:14px;border-top:1px solid rgba(15,23,42,.08);}
  body.sfy-page .sfy-page-panel .sfy-panel-body .sfy-block{margin-top:0;padding-top:0;border-top:0;}
  body.sfy-page .sfy-page-panel .sfy-panel-body .sfy-block + .sfy-block{margin-top:18px;padding-top:14px;border-top:1px solid rgba(15,23,42,.08);}
  <?= $sfyScope ?> .sfy-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 12px;}
  <?= $sfyScope ?> .sfy-title{margin:0;font-size:15px;font-weight:800;line-height:1.2;color:var(--msb-palette-text-on-nav,#0d0d0d);}
  <?= $sfyScope ?> .sfy-search{margin:0 0 12px;}
  <?= $sfyScope ?> .sfy-search-form{margin:0;}
  <?= $sfyScope ?> .sfy-search-field{position:relative;}
  <?= $sfyScope ?> .sfy-search-input{
    width:100%;height:36px;min-height:36px;box-sizing:border-box;
    border:1px solid rgba(15,23,42,.12);border-radius:10px;
    background:var(--msb-palette-input-bg,#fff);color:var(--msb-palette-text,#0d0d0d);
    padding:0 34px 0 12px;font-size:13px;line-height:1.2;
  }
  <?= $sfyScope ?> .sfy-search-input::placeholder{color:#a3a3a3;}
  <?= $sfyScope ?> .sfy-search-input:focus{outline:none;border-color:#0095f6;box-shadow:0 0 0 2px rgba(0,149,246,.15);}
  <?= $sfyScope ?> .sfy-search-icon{
    position:absolute;right:4px;top:50%;transform:translateY(-50%);
    width:28px;height:28px;border:0;border-radius:50%;background:transparent;
    color:#737373;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;
  }
  <?= $sfyScope ?> .sfy-search-icon i{font-size:13px;line-height:1;}
  <?= $sfyScope ?> .sfy-search-icon:hover,<?= $sfyScope ?> .sfy-search-icon:focus{color:#0095f6;outline:none;}
  <?= $sfyScope ?> .sfy-search-empty{display:none;margin:0;padding:8px 0 4px;font-size:13px;color:#737373;}
  <?= $sfyScope ?> .sfy-search-empty.is-visible{display:block;}
  <?= $sfyScope ?> .sfy-see{flex:0 0 auto;font-size:13px;font-weight:700;line-height:1.2;color:#7c3aed;text-decoration:none;white-space:nowrap;}
  <?= $sfyScope ?> .sfy-see:hover,<?= $sfyScope ?> .sfy-see:focus{text-decoration:underline;outline:none;}
  <?= $sfyScope ?> .sfy-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;}
  <?= $sfyScope ?> .sfy-row{display:flex;align-items:center;gap:10px;min-height:52px;padding:4px 0;}
  <?= $sfyScope ?> .sfy-avatar{flex:0 0 44px;width:44px;height:44px;border-radius:50%;overflow:hidden;background:#eef2f7;display:block;text-decoration:none;cursor:pointer;position:relative;z-index:1;}
  <?= $sfyScope ?> .sfy-avatar img{display:block;width:100%;height:100%;object-fit:cover;pointer-events:none;}
  <?= $sfyScope ?> .sfy-avatar:hover,<?= $sfyScope ?> .sfy-avatar:focus{outline:none;box-shadow:0 0 0 2px rgba(0,149,246,.35);}
  <?= $sfyScope ?> .sfy-meta{flex:1 1 auto;min-width:0;}
  <?= $sfyScope ?> .sfy-name{display:block;font-size:14px;font-weight:700;line-height:1.25;color:var(--msb-palette-text-on-nav,#0d0d0d);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;position:relative;z-index:1;}
  <?= $sfyScope ?> .sfy-name:hover,<?= $sfyScope ?> .sfy-name:focus{text-decoration:underline;outline:none;color:#0095f6;}
  <?= $sfyScope ?> .sfy-sub{margin-top:2px;font-size:12px;font-weight:400;line-height:1.3;color:#737373;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  <?= $sfyScope ?> .sfy-action{flex:0 0 auto;border:0;background:#f3e8ff;padding:7px 10px;border-radius:10px;font-size:12px;font-weight:700;line-height:1.2;color:#7c3aed;cursor:pointer;white-space:nowrap;}
  <?= $sfyScope ?> .sfy-action:hover,<?= $sfyScope ?> .sfy-action:focus{color:#6d28d9;outline:none;background:#ede9fe;}
  <?= $sfyScope ?> .sfy-dismiss{
    flex:0 0 auto;width:22px;height:22px;margin:0;padding:0;border:0;background:transparent;
    color:#94a3b8;font-size:18px;line-height:1;cursor:pointer;border-radius:50%;
  }
  <?= $sfyScope ?> .sfy-dismiss:hover,<?= $sfyScope ?> .sfy-dismiss:focus{color:#475569;background:#f1f5f9;outline:none;}
  <?= $sfyScope ?> .sfy-row.is-dismissed{display:none !important;}
  body.feed-insta-ui .feed-right-rail .sfy-head{margin:0 0 8px;gap:8px;}
  body.feed-insta-ui .feed-right-rail .sfy-see{font-size:11px;}
  body.feed-insta-ui .feed-right-rail .sfy-list{gap:4px;}
  body.feed-insta-ui .feed-right-rail .sfy-row{min-height:0;padding:3px 0;gap:8px;}
  body.feed-insta-ui .feed-right-rail .sfy-avatar{
    flex:0 0 28px !important;width:28px !important;height:28px !important;
    min-width:28px !important;min-height:28px !important;
  }
  body.feed-insta-ui .feed-right-rail .sfy-name{font-size:12px !important;font-weight:650;line-height:1.2;}
  body.feed-insta-ui .feed-right-rail .sfy-sub{margin-top:1px;font-size:10px !important;line-height:1.2;}
  body.feed-insta-ui .feed-right-rail .sfy-action{
    min-width:24px;height:24px;padding:0 8px;border-radius:7px;
    font-size:11px !important;font-weight:700;line-height:1;
    display:inline-flex;align-items:center;justify-content:center;
  }
  body.feed-insta-ui .feed-right-rail .sfy-action.friend-btn{
    width:24px;min-width:24px;padding:0;font-size:15px !important;font-weight:500;
  }
  body.feed-insta-ui .feed-right-rail .sfy-dismiss{width:18px;height:18px;font-size:14px;}
  body.feed-insta-ui .feed-right-rail .sfy-empty{margin:0;padding:2px 0;font-size:11px;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body .sfy-block + .sfy-block{margin-top:8px;padding-top:8px;}
  <?= $sfyScope ?> .sfy-action.is-following,<?= $sfyScope ?> .sfy-action.is-pending,<?= $sfyScope ?> .sfy-action.is-friends,<?= $sfyScope ?> .sfy-action:disabled{color:#737373;cursor:default;}
  <?= $sfyScope ?> .sfy-action.friend-btn,<?= $sfyScope ?> .sfy-action.publisher-follow-btn{font:inherit;}
  html.dark-auto:not([data-msb-appearance]) <?= $sfyScope ?> .sfy-action.friend-btn,
  html.dark-auto:not([data-msb-appearance]) <?= $sfyScope ?> .sfy-action.publisher-follow-btn,
  html[data-theme="dark"]:not([data-msb-appearance]) <?= $sfyScope ?> .sfy-action.friend-btn,
  html[data-theme="dark"]:not([data-msb-appearance]) <?= $sfyScope ?> .sfy-action.publisher-follow-btn,
  html[data-msb-appearance] <?= $sfyScope ?> .sfy-action.friend-btn,
  html[data-msb-appearance] <?= $sfyScope ?> .sfy-action.publisher-follow-btn{
    background:var(--msb-palette-bg, var(--public-page-bg, #171d24)) !important;
    border:1px solid var(--msb-palette-border, rgba(177,188,206,.42)) !important;
    color:var(--msb-palette-text, var(--public-text, #eef4ff)) !important;
    -webkit-text-fill-color:var(--msb-palette-text, var(--public-text, #eef4ff)) !important;
  }
  html.dark-auto:not([data-msb-appearance]) body.feed-insta-ui .feed-right-rail .sfy-scroll-btn,
  html[data-theme="dark"]:not([data-msb-appearance]) body.feed-insta-ui .feed-right-rail .sfy-scroll-btn,
  html[data-msb-appearance] body.feed-insta-ui .feed-right-rail .sfy-scroll-btn,
  html.dark-auto:not([data-msb-appearance]) body.sfy-page .sfy-scroll-btn,
  html[data-theme="dark"]:not([data-msb-appearance]) body.sfy-page .sfy-scroll-btn,
  html[data-msb-appearance] body.sfy-page .sfy-scroll-btn{
    background:var(--msb-palette-bg, var(--public-page-bg, #171d24)) !important;
    border:1px solid var(--msb-palette-border, rgba(177,188,206,.42)) !important;
    color:var(--msb-palette-text, var(--public-text, #eef4ff)) !important;
    box-shadow:none !important;
  }
  html.dark-auto:not([data-msb-appearance]) body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:hover,
  html.dark-auto:not([data-msb-appearance]) body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:focus,
  html[data-theme="dark"]:not([data-msb-appearance]) body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:hover,
  html[data-theme="dark"]:not([data-msb-appearance]) body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:focus,
  html[data-msb-appearance] body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:hover,
  html[data-msb-appearance] body.feed-insta-ui .feed-right-rail .sfy-scroll-btn:focus,
  html.dark-auto:not([data-msb-appearance]) body.sfy-page .sfy-scroll-btn:hover,
  html.dark-auto:not([data-msb-appearance]) body.sfy-page .sfy-scroll-btn:focus,
  html[data-theme="dark"]:not([data-msb-appearance]) body.sfy-page .sfy-scroll-btn:hover,
  html[data-theme="dark"]:not([data-msb-appearance]) body.sfy-page .sfy-scroll-btn:focus,
  html[data-msb-appearance] body.sfy-page .sfy-scroll-btn:hover,
  html[data-msb-appearance] body.sfy-page .sfy-scroll-btn:focus{
    background:var(--msb-palette-bg, var(--public-page-bg, #171d24)) !important;
  }
</style>
<?php if ($sfyModeIsPage): ?>
<main class="sfy-page-main" aria-label="Suggested for you">
  <header class="sfy-page-top">
    <h1 class="sfy-page-title">Suggested for you</h1>
    <a class="sfy-page-back" href="public.php">Back to feed</a>
  </header>
  <nav class="sfy-tabs" aria-label="Suggestion type">
    <?php if ($sfyCanShowPersonal): ?>
    <a
      class="sfy-tab<?= $sfyPageTab === 'people' ? ' is-active' : '' ?>"
      href="<?= h(sfy_page_tab_href('people', $sfySearchQ)) ?>"
      <?= $sfyPageTab === 'people' ? 'aria-current="page"' : '' ?>
    >Personal</a>
    <?php endif; ?>
    <?php if ($sfyCanBrowsePublishers): ?>
    <a
      class="sfy-tab<?= $sfyPageTab === 'publishers' ? ' is-active' : '' ?>"
      href="<?= h(sfy_page_tab_href('publishers', $sfySearchQ)) ?>"
      <?= $sfyPageTab === 'publishers' ? 'aria-current="page"' : '' ?>
    >Publishers</a>
    <?php endif; ?>
  </nav>
  <div class="sfy-page-toolbar">
    <div class="sfy-search" aria-label="Search suggestions">
      <form class="sfy-search-form" method="get" action="suggested_for_you.php" role="search">
        <input type="hidden" name="tab" value="<?= h($sfyPageTab) ?>">
        <div class="sfy-search-field">
          <input
            type="search"
            class="sfy-search-input js-sfy-search-input"
            name="q"
            value="<?= h($sfySearchQ) ?>"
            placeholder="<?= $sfyPageTab === 'publishers' || $sfyIsPublisherWorkspace ? 'Search publishers…' : 'Search personal users…' ?>"
            autocomplete="off"
            enterkeyhint="search"
            aria-label="<?= $sfyPageTab === 'publishers' || $sfyIsPublisherWorkspace ? 'Search publishers' : 'Search personal users' ?>"
          >
          <button type="submit" class="sfy-search-icon" aria-label="Search">
            <i class="fa fa-search" aria-hidden="true"></i>
          </button>
        </div>
      </form>
    </div>
  </div>
  <div class="sfy-panel sfy-page-panel">
  <div class="sfy-panel-body js-sfy-panel-scroll">
<?php else: ?>
<aside class="feed-right-rail" aria-label="Explore">
  <div class="sfy-panel">
    <div class="sfy-panel-head">
      <header class="sfy-head">
        <h2 class="sfy-title">People You May Know</h2>
        <a class="sfy-see" href="suggested_for_you.php?tab=<?= $sfyIsPublisherWorkspace ? 'publishers' : 'people' ?>">See all</a>
      </header>
    </div>
    <div class="sfy-panel-body js-sfy-panel-scroll">
<?php endif; ?>
    <?php if ($sfyModeIsPage): ?>
      <?php if ($sfyPageTab === 'people' && $sfyCanShowPersonal): ?>
      <section class="sfy-block" aria-label="Personal users">
        <?php if ($sfyFriends): ?>
        <ul class="sfy-list js-sfy-suggest-list">
          <?php foreach ($sfyFriends as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
        </ul>
        <p class="sfy-search-empty js-sfy-search-empty" role="status">No matches for your search.</p>
        <?php else: ?>
        <p class="sfy-empty"><?= $sfySearchActive ? 'No personal users found for “' . h($sfySearchQ) . '”.' : 'No personal users to suggest right now.' ?></p>
        <?php endif; ?>
      </section>
      <?php elseif ($sfyPageTab === 'publishers' && $sfyCanBrowsePublishers): ?>
      <section class="sfy-block" aria-label="Publishers">
        <?php if ($sfyFollow): ?>
        <ul class="sfy-list js-sfy-suggest-list">
          <?php foreach ($sfyFollow as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
        </ul>
        <p class="sfy-search-empty js-sfy-search-empty" role="status">No matches for your search.</p>
        <?php elseif ($sfySearchActive): ?>
        <p class="sfy-empty">No publishers found for “<?= h($sfySearchQ) ?>”.</p>
        <?php elseif (!$sfyAdvertise): ?>
        <p class="sfy-empty">No publishers to suggest right now.</p>
        <?php endif; ?>
      </section>
      <?php if (!$sfySearchActive): ?>
      <section class="sfy-block" id="advertise" aria-label="Advertise">
        <header class="sfy-head">
          <h2 class="sfy-title">Advertise</h2>
        </header>
        <?php if ($sfyAdvertise): ?>
        <ul class="sfy-list">
          <?php foreach ($sfyAdvertise as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="sfy-empty">No brands to show right now.</p>
        <?php endif; ?>
      </section>
      <?php endif; ?>
      <?php endif; ?>
    <?php elseif (!$sfyModeIsPage): ?>
      <section class="sfy-block" aria-label="People You May Know">
        <?php if ($sfyFriends): ?>
        <ul class="sfy-list">
          <?php foreach ($sfyFriends as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="sfy-empty">No people to suggest right now.</p>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="sfy-block" aria-label="Suggested for you">
        <p class="sfy-empty">No suggestions available right now.</p>
      </section>
    <?php endif; ?>
  <?php if ($sfyModeIsPage): ?>
  </div>
  <div class="sfy-scroll-rail" aria-label="Scroll suggestions">
    <button type="button" class="sfy-scroll-btn js-sfy-scroll-up" aria-label="Scroll up">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 14l6-6 6 6"/></svg>
    </button>
    <button type="button" class="sfy-scroll-btn js-sfy-scroll-down" aria-label="Scroll down">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10l6 6 6-6"/></svg>
    </button>
  </div>
  <?php elseif (!$sfyModeIsPage): ?>
  </div>
  </div>
  <?php include __DIR__ . '/home_right_rail_widgets.php'; ?>
  <?php endif; ?>
<?php if ($sfyModeIsPage): ?>
  </div>
</main>
<?php else: ?>
</aside>
<?php endif; ?>
<script>
(function(){
  document.querySelectorAll('.js-sfy-panel-scroll').forEach(function(body){
    var panel = body.closest('.sfy-panel');
    if(!panel) return;
    var isEmbedded = !!body.closest('.feed-right-rail');
    if(isEmbedded){
      body.addEventListener('wheel', function(event){
        var max = Math.max(0, body.scrollHeight - body.clientHeight);
        if(max <= 1) return;
        var delta = event.deltaY || 0;
        var next = Math.max(0, Math.min(max, body.scrollTop + delta));
        if(next === body.scrollTop) return;
        event.preventDefault();
        event.stopPropagation();
        body.scrollTop = next;
      }, {passive:false});
    }
    var up = panel.querySelector('.js-sfy-scroll-up');
    var down = panel.querySelector('.js-sfy-scroll-down');
    if(!up || !down) return;
    var step = 120;
    function sync(){
      var max = Math.max(0, body.scrollHeight - body.clientHeight);
      up.disabled = body.scrollTop <= 1;
      down.disabled = body.scrollTop >= max - 1;
      var show = max > 2;
      up.style.display = show ? '' : 'none';
      down.style.display = show ? '' : 'none';
    }
    up.addEventListener('click', function(){ body.scrollBy({top:-step, behavior:'smooth'}); });
    down.addEventListener('click', function(){ body.scrollBy({top:step, behavior:'smooth'}); });
    body.addEventListener('scroll', sync, {passive:true});
    window.addEventListener('resize', sync);
    if(window.ResizeObserver){
      try{ new ResizeObserver(sync).observe(body); }catch(e){}
    }
    sync();
  });
  document.querySelectorAll('.sfy-dismiss').forEach(function(btn){
    btn.addEventListener('click', function(){
      var row = btn.closest('.sfy-row');
      if(row) row.classList.add('is-dismissed');
    });
  });
})();
</script>
<?php if ($sfyModeIsPage): ?>
<script>
(function(){
  function initSfySearch(scope){
    if(!scope || scope.__sfySearchBound) return;
    scope.__sfySearchBound = true;
    var input = scope.querySelector('.js-sfy-search-input');
    var list = scope.querySelector('.js-sfy-suggest-list');
    var empty = scope.querySelector('.js-sfy-search-empty');
    if(!input || !list) return;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.sfy-row'));
    function apply(){
      var q = String(input.value || '').trim().toLowerCase();
      var shown = 0;
      rows.forEach(function(row){
        var hay = String(row.getAttribute('data-sfy-hay') || '');
        var on = !q || hay.indexOf(q) !== -1;
        row.style.display = on ? '' : 'none';
        if(on) shown++;
      });
      if(empty){
        empty.classList.toggle('is-visible', !!q && shown === 0);
      }
    }
    input.addEventListener('input', apply);
    apply();
  }
  var pageMain = document.querySelector('.sfy-page-main');
  if(pageMain) initSfySearch(pageMain);
})();
</script>
<?php endif; ?>
