<?php
declare(strict_types=1);

require_once __DIR__ . '/friend_system.php';

if (!function_exists('live_browse_table_exists')) {
    function live_browse_table_exists(PDO $dbh): bool
    {
        try {
            $st = $dbh->prepare("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'user_video_lives'
                LIMIT 1
            ");
            $st->execute();
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('live_browse_can_view')) {
    /**
     * Match live_watch.php / live_watch_room.php access rules.
     */
    function live_browse_can_view(PDO $dbh, int $meId, array $row): bool
    {
        $ownerId = (int)($row['user_id'] ?? 0);
        $visibility = strtolower(trim((string)($row['visibility'] ?? 'private')));
        if ($ownerId === $meId) {
            return true;
        }
        if ($visibility === 'public') {
            return true;
        }
        if ($visibility === 'friends') {
            return fs_are_friends($dbh, $meId, $ownerId);
        }
        return false;
    }
}

if (!function_exists('live_browse_fetch_live_rows')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function live_browse_fetch_live_rows(PDO $dbh, int $meId, string $scope = 'hub', int $limit = 50, string $search = ''): array
    {
        if ($meId <= 0 || !live_browse_table_exists($dbh)) {
            return [];
        }

        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['hub', 'public', 'friends'], true)) {
            $scope = 'hub';
        }
        $limit = max(1, min(100, $limit));
        $search = trim($search);

        $sql = "
            SELECT
                l.id,
                l.user_id,
                l.title,
                l.description,
                l.viewer_count,
                l.started_at,
                l.updated_at,
                l.visibility,
                l.status,
                COALESCE(l.device_label, '') AS device_label,
                COALESCE(l.device_viewport, '') AS device_viewport,
                u.name,
                u.username,
                u.email,
                u.friend_code
            FROM user_video_lives l
            JOIN users u ON u.id = l.user_id
            WHERE l.status = 'live'
        ";
        $params = [];

        if ($scope === 'public') {
            $sql .= " AND l.visibility = 'public' ";
        } elseif ($scope === 'friends') {
            $sql .= "
              AND l.visibility = 'friends'
              AND (
                l.user_id = :me_id
                OR EXISTS (
                  SELECT 1 FROM user_contacts uc
                  WHERE uc.owner_user_id = :me_id_2 AND uc.friend_user_id = l.user_id
                )
                OR EXISTS (
                  SELECT 1 FROM user_contacts uc2
                  WHERE uc2.owner_user_id = l.user_id AND uc2.friend_user_id = :me_id_3
                )
              )
            ";
            $params[':me_id'] = $meId;
            $params[':me_id_2'] = $meId;
            $params[':me_id_3'] = $meId;
        } else {
            $sql .= "
              AND (
                l.visibility = 'public'
                OR l.user_id = :me
                OR (
                  l.visibility = 'friends'
                  AND (
                    EXISTS (
                      SELECT 1 FROM user_contacts uc
                      WHERE uc.owner_user_id = :me2 AND uc.friend_user_id = l.user_id
                    )
                    OR EXISTS (
                      SELECT 1 FROM user_contacts uc2
                      WHERE uc2.owner_user_id = l.user_id AND uc2.friend_user_id = :me3
                    )
                  )
                )
              )
            ";
            $params[':me'] = $meId;
            $params[':me2'] = $meId;
            $params[':me3'] = $meId;
        }

        if ($search !== '') {
            $sql .= "
              AND (
                COALESCE(l.title, '') LIKE :q
                OR COALESCE(l.description, '') LIKE :q
                OR COALESCE(u.name, '') LIKE :q
                OR COALESCE(u.username, '') LIKE :q
              )
            ";
            $params[':q'] = '%' . $search . '%';
        }

        $sql .= "
            ORDER BY COALESCE(l.started_at, l.updated_at) DESC, l.id DESC
            LIMIT " . (int)$limit . "
        ";

        try {
            $st = $dbh->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!live_browse_can_view($dbh, $meId, $row)) {
                continue;
            }
            $filtered[] = $row;
        }

        return $filtered;
    }
}

if (!function_exists('live_browse_normalize_hub_item')) {
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    function live_browse_normalize_hub_item(array $row, int $meId): array
    {
        $host = trim((string)($row['name'] ?? $row['username'] ?? 'Host'));
        $title = trim((string)($row['title'] ?? 'Live session'));
        if ($title === '') {
            $title = 'Live session';
        }
        $userId = (int)($row['user_id'] ?? 0);
        $visibility = strtolower(trim((string)($row['visibility'] ?? 'public')));
        $liveId = (int)($row['id'] ?? 0);
        $snapshotVersion = '';
        if ($liveId > 0) {
            $snapshotPath = __DIR__ . '/../storage/live_snapshots/' . $liveId . '.jpg';
            if (is_file($snapshotPath)) {
                $snapshotVersion = (string)(@md5_file($snapshotPath) ?: '');
            }
        }

        return [
            'id' => $liveId,
            'title' => $title,
            'host' => $host,
            'user_id' => $userId,
            'viewer_count' => (int)($row['viewer_count'] ?? 0),
            'visibility' => $visibility,
            'is_owner' => $userId === $meId,
            'watch_url' => 'live_watch.php?live=' . $liveId,
            'snapshot_version' => $snapshotVersion,
        ];
    }
}

if (!function_exists('live_browse_door_rows')) {
    /**
     * Door hub lists: Chat = host own only; Public tab = public; Friend tab = friends-only.
     *
     * @return array{
     *   own_live_id: int,
     *   chat_rows: array<int, array<string, mixed>>,
     *   friend_rows: array<int, array<string, mixed>>,
     *   public_rows: array<int, array<string, mixed>>
     * }
     */
    function live_browse_door_rows(PDO $dbh, int $meId, int $limit = 50): array
    {
        $ownLiveId = 0;
        $chatRows = [];
        $friendRows = [];
        $publicRows = [];

        foreach (live_browse_fetch_live_rows($dbh, $meId, 'hub', $limit) as $row) {
            if ((int)($row['user_id'] ?? 0) === $meId) {
                $chatRows[] = $row;
                if ($ownLiveId <= 0) {
                    $ownLiveId = (int)($row['id'] ?? 0);
                }
            }
        }

        foreach (live_browse_fetch_live_rows($dbh, $meId, 'friends', $limit) as $row) {
            if ((int)($row['user_id'] ?? 0) === $meId) {
                continue;
            }
            $friendRows[] = $row;
        }

        foreach (live_browse_fetch_live_rows($dbh, $meId, 'public', $limit) as $row) {
            if ((int)($row['user_id'] ?? 0) === $meId) {
                continue;
            }
            $publicRows[] = $row;
        }

        return [
            'own_live_id' => $ownLiveId,
            'chat_rows' => $chatRows,
            'friend_rows' => $friendRows,
            'public_rows' => $publicRows,
        ];
    }
}

if (!function_exists('live_browse_hub_surface')) {
    function live_browse_hub_surface(?string $surface = null): string
    {
        $surface = strtolower(trim((string)($surface ?? 'public')));
        return in_array($surface, ['feed', 'public'], true) ? $surface : 'public';
    }
}

if (!function_exists('live_browse_hub_payload')) {
    /**
     * @return array{
     *   lives: array<int, array<string, mixed>>,
     *   public_lives: array<int, array<string, mixed>>,
     *   friend_lives: array<int, array<string, mixed>>,
     *   browse_lives: array<int, array<string, mixed>>,
     *   chat_lives: array<int, array<string, mixed>>,
     *   featured: ?array<string, mixed>,
     *   own_live_id: int,
     *   hub_surface: string,
     *   fingerprint: string,
     *   public_fingerprint: string,
     *   friend_fingerprint: string,
     *   browse_fingerprint: string,
     *   chat_fingerprint: string
     * }
     */
    function live_browse_hub_payload(PDO $dbh, int $meId, int $limit = 50, ?string $hubSurface = null): array
    {
        $hubSurface = live_browse_hub_surface($hubSurface);
        $door = live_browse_door_rows($dbh, $meId, $limit);
        $publicLives = [];
        $friendLives = [];
        $chatLives = [];

        foreach ($door['chat_rows'] as $row) {
            $item = live_browse_normalize_hub_item($row, $meId);
            if ((int)($item['id'] ?? 0) <= 0) {
                continue;
            }
            $chatLives[] = $item;
        }

        foreach ($door['friend_rows'] as $row) {
            $item = live_browse_normalize_hub_item($row, $meId);
            if ((int)($item['id'] ?? 0) <= 0) {
                continue;
            }
            $friendLives[] = $item;
        }

        foreach ($door['public_rows'] as $row) {
            $item = live_browse_normalize_hub_item($row, $meId);
            if ((int)($item['id'] ?? 0) <= 0) {
                continue;
            }
            $publicLives[] = $item;
        }

        $browseLives = $hubSurface === 'feed' ? $friendLives : $publicLives;
        $ownLiveId = (int)($door['own_live_id'] ?? 0);
        $featured = null;
        foreach ($chatLives as $item) {
            if (!empty($item['is_owner'])) {
                $featured = $item;
                break;
            }
        }
        if ($featured === null && $browseLives) {
            $featured = $browseLives[0];
        }

        $fingerprintIds = array_map(static function (array $live): string {
            return (string)((int)($live['id'] ?? 0));
        }, array_merge($chatLives, $friendLives, $publicLives));

        return [
            'lives' => array_merge($chatLives, $friendLives, $publicLives),
            'public_lives' => $publicLives,
            'friend_lives' => $friendLives,
            'browse_lives' => $browseLives,
            'chat_lives' => $chatLives,
            'featured' => $featured,
            'own_live_id' => $ownLiveId,
            'hub_surface' => $hubSurface,
            'fingerprint' => implode(',', $fingerprintIds),
            'public_fingerprint' => implode(',', array_map(static function (array $live): string {
                return (string)((int)($live['id'] ?? 0));
            }, $publicLives)),
            'friend_fingerprint' => implode(',', array_map(static function (array $live): string {
                return (string)((int)($live['id'] ?? 0));
            }, $friendLives)),
            'browse_fingerprint' => implode(',', array_map(static function (array $live): string {
                return (string)((int)($live['id'] ?? 0));
            }, $browseLives)),
            'chat_fingerprint' => implode(',', array_map(static function (array $live): string {
                return (string)((int)($live['id'] ?? 0));
            }, $chatLives)),
        ];
    }
}
