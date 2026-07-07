<?php
declare(strict_types=1);

/**
 * Suggested for you panel — Add friend, Follow publishers, Advertise brands.
 * Set $suggestedForYouMode = 'none' to omit (e.g. feed.php).
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
        $img = trim((string)($user['image'] ?? ''));
        if ($img !== '' && $img !== 'default.jpg' && $img !== 'default.png') {
            if (preg_match('~^(https?:)?//~i', $img) || $img[0] === '/') {
                return $img;
            }
            return './' . ltrim($img, './');
        }

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
            'profile_href' => 'profile.php?u=' . rawurlencode(trim((string)($row['username'] ?? ''))),
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
        if (publisher_workspace_viewer($dbh, $meId)) {
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
        try {
            $st = $dbh->prepare("
                SELECT
                  u.id, u.name, u.username, u.email, u.image, u.friend_code,
                  (
                    SELECT COALESCE(
                      NULLIF(TRIM(fc.display_name), ''),
                      NULLIF(TRIM(mu.name), ''),
                      NULLIF(TRIM(mu.username), ''),
                      mu.friend_code
                    )
                    FROM user_contacts fc
                    INNER JOIN user_contacts mf
                      ON mf.owner_user_id = fc.friend_user_id
                    INNER JOIN users mu ON mu.id = fc.friend_user_id
                    WHERE fc.owner_user_id = :meMut
                      AND mf.friend_user_id = u.id
                    LIMIT 1
                  ) AS mutual_via_name
                FROM users u
                WHERE u.status = 1
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
                ORDER BY mutual_via_name IS NOT NULL DESC, u.id DESC
                LIMIT {$limit}
            ");
            $st->execute(array_merge([
                ':me' => $meId,
                ':meMut' => $meId,
                ':meA' => $meId,
                ':meB' => $meId,
                ':meC' => $meId,
                ':meD' => $meId,
            ], $searchParams));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) <= 0) {
                continue;
            }
            $mutual = trim((string)($row['mutual_via_name'] ?? ''));
            $subtitle = $mutual !== '' ? ('Followed by ' . $mutual) : ($query !== '' ? 'People' : 'Suggested friend');
            $out[] = sfy_user_row($row, 'friend', $subtitle, '+');
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
        if ($query !== '') {
            $hits = publisher_search($dbh, $query, $limit, true);
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
                if ($id <= 0 || isset($following[$id]) || in_array($id, $excludeIds, true)) {
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
                  AND {$discoverableSql}
                  AND NOT EXISTS (
                    SELECT 1 FROM public_follows pf
                    WHERE pf.follower_id = :me AND pf.following_id = u.id
                  ){$excludeSql}
                ORDER BY u.name ASC
                LIMIT {$limit}
            ");
            $st->execute([':me' => $meId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $cats = publisher_categories();
        $out = [];
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) <= 0) {
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
        $params = [':me' => $meId];
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
            if ((int)($row['id'] ?? 0) <= 0) {
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
          <a class="sfy-avatar" href="<?= h($profileHref) ?>" aria-hidden="true" tabindex="-1">
            <img src="<?= h(sfy_avatar_url($avatarUser, 96)) ?>" alt="" loading="lazy" width="44" height="44">
          </a>
          <div class="sfy-meta">
            <a class="sfy-name" href="<?= h($profileHref) ?>"><?= h((string)($row['name'] ?? '')) ?></a>
            <div class="sfy-sub"><?= h((string)($row['subtitle'] ?? '')) ?></div>
          </div>
          <?php if ($kind === 'friend'): ?>
            <button type="button" class="sfy-action friend-btn primary" data-peer-id="<?= $rowId ?>" data-status="none">
              <?= h((string)($row['action_label'] ?? '+')) ?>
            </button>
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

$sfyCap = $sfyModeIsPage ? 100 : 8;
$sfyMaxFriends = max(0, min($sfyCap, (int)($suggestedForYouMaxFriends ?? ($sfyModeIsPage ? 100 : 3))));
$sfyMaxFollow = max(0, min($sfyCap, (int)($suggestedForYouMaxFollow ?? ($sfyModeIsPage ? 30 : 3))));
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

$sfyCanShowPersonal = !$sfyIsPublisherWorkspace && !$sfyStaffReadonly && $sfyMaxFriends > 0;

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
        $excludeIds = array_merge(
            array_column($sfyFollow, 'id'),
            array_column($sfyFriends, 'id')
        );
        if ($sfyCanBrowsePublishers && $sfyMaxAdvertise > 0) {
            $sfyAdvertise = sfy_advertise_rows($sfyDbh, $sfyMeId, $sfyMaxAdvertise, $excludeIds);
        }
    }
}

if (!$sfyModeIsPage && !$sfyFriends && !$sfyFollow && !$sfyAdvertise) {
    return;
}

$sfyScope = $sfyModeIsPage ? 'body.sfy-page' : 'body.feed-insta-ui';
?>
<style>
  <?php if (!$sfyModeIsPage): ?>
  body.feed-insta-ui .feed-right-rail .sfy-panel{
    margin:0;padding:0;
    display:flex;flex-direction:column;
    max-height:min(420px, calc(100vh - 280px));
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-head{
    flex:0 0 auto;margin:0 0 12px;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-head .sfy-head{margin:0;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body{
    flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;
    overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.18) transparent;
  }
  body.feed-insta-ui .feed-right-rail .sfy-panel-body::-webkit-scrollbar{width:5px;}
  body.feed-insta-ui .feed-right-rail .sfy-panel-body::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.18);border-radius:999px;
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
  <?= $sfyScope ?> .sfy-see{flex:0 0 auto;font-size:12px;font-weight:800;line-height:1.2;color:var(--msb-palette-text-on-nav,#0d0d0d);text-decoration:none;white-space:nowrap;}
  <?= $sfyScope ?> .sfy-see:hover,<?= $sfyScope ?> .sfy-see:focus{text-decoration:underline;outline:none;}
  <?= $sfyScope ?> .sfy-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px;}
  <?= $sfyScope ?> .sfy-row{display:flex;align-items:center;gap:12px;min-height:60px;padding:6px 0;}
  <?= $sfyScope ?> .sfy-avatar{flex:0 0 44px;width:44px;height:44px;border-radius:50%;overflow:hidden;background:#eef2f7;display:block;text-decoration:none;}
  <?= $sfyScope ?> .sfy-avatar img{display:block;width:100%;height:100%;object-fit:cover;}
  <?= $sfyScope ?> .sfy-meta{flex:1 1 auto;min-width:0;}
  <?= $sfyScope ?> .sfy-name{display:block;font-size:14px;font-weight:700;line-height:1.25;color:var(--msb-palette-text-on-nav,#0d0d0d);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  <?= $sfyScope ?> .sfy-name:hover,<?= $sfyScope ?> .sfy-name:focus{text-decoration:underline;outline:none;}
  <?= $sfyScope ?> .sfy-sub{margin-top:2px;font-size:12px;font-weight:400;line-height:1.3;color:#737373;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  <?= $sfyScope ?> .sfy-action{flex:0 0 auto;border:0;background:transparent;padding:0 4px;font-size:12px;font-weight:800;line-height:1.2;color:#0095f6;cursor:pointer;white-space:nowrap;}
  <?= $sfyScope ?> .sfy-action:hover,<?= $sfyScope ?> .sfy-action:focus{color:#1877f2;outline:none;}
  <?= $sfyScope ?> .sfy-action.is-following,<?= $sfyScope ?> .sfy-action.is-pending,<?= $sfyScope ?> .sfy-action.is-friends,<?= $sfyScope ?> .sfy-action:disabled{color:#737373;cursor:default;}
  <?= $sfyScope ?> .sfy-action.friend-btn,<?= $sfyScope ?> .sfy-action.publisher-follow-btn{font:inherit;}
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
<aside class="feed-right-rail" aria-label="Suggested for you">
  <div class="sfy-panel">
    <?php if ($sfyFriends || $sfyFollow || $sfyAdvertise): ?>
    <div class="sfy-panel-head">
      <header class="sfy-head">
        <h2 class="sfy-title">Suggested for you</h2>
        <a class="sfy-see" href="suggested_for_you.php?tab=<?= $sfyIsPublisherWorkspace ? 'publishers' : 'people' ?>">See all</a>
      </header>
    </div>
  <div class="sfy-panel-body js-sfy-panel-scroll">
    <?php endif; ?>
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
    <?php elseif ($sfyFriends || $sfyFollow || $sfyAdvertise): ?>
      <section class="sfy-block" aria-label="Suggested for you">
        <ul class="sfy-list">
          <?php if ($sfyCanShowPersonal): ?>
          <?php foreach ($sfyFriends as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
          <?php endif; ?>
          <?php foreach ($sfyFollow as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if (!$sfyModeIsPage && ($sfyAdvertise) && !$sfySearchActive): ?>
      <section class="sfy-block" id="advertise" aria-label="Advertise">
        <header class="sfy-head">
          <h2 class="sfy-title">Advertise</h2>
          <a class="sfy-see" href="suggested_for_you.php?tab=publishers#advertise">See all</a>
        </header>
        <?php if ($sfyAdvertise): ?>
        <ul class="sfy-list">
          <?php foreach ($sfyAdvertise as $row): ?>
            <?php sfy_render_row($row); ?>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
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
  <?php elseif (!$sfyModeIsPage && ($sfyFriends || $sfyFollow || $sfyAdvertise)): ?>
  </div>
  <div class="sfy-scroll-rail" aria-label="Scroll suggestions">
    <button type="button" class="sfy-scroll-btn js-sfy-scroll-up" aria-label="Scroll up">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 14l6-6 6 6"/></svg>
    </button>
    <button type="button" class="sfy-scroll-btn js-sfy-scroll-down" aria-label="Scroll down">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10l6 6 6-6"/></svg>
    </button>
  </div>
  <?php endif; ?>
  </div>
<?php if ($sfyModeIsPage): ?>
</main>
<?php else: ?>
</aside>
<?php endif; ?>
<script>
(function(){
  document.querySelectorAll('.js-sfy-panel-scroll').forEach(function(body){
    var panel = body.closest('.sfy-panel');
    if(!panel) return;
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
