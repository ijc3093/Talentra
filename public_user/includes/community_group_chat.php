<?php
declare(strict_types=1);

/**
 * Community ↔ group chat bridge.
 * One chat_groups row per community (community_id), named after the community.
 * Active community members are synced into the group (members only).
 */

if (!function_exists('msb_cg_db_column_exists')) {
    function msb_cg_db_column_exists(PDO $dbh, string $table, string $column): bool
    {
        try {
            $st = $dbh->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $st->execute([$column]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('msb_cg_ensure_community_column')) {
    function msb_cg_ensure_community_column(PDO $dbh): bool
    {
        try {
            $st = $dbh->query("SHOW TABLES LIKE 'chat_groups'");
            if (!$st || !$st->fetchColumn()) {
                return false;
            }
            if (!msb_cg_db_column_exists($dbh, 'chat_groups', 'community_id')) {
                $dbh->exec("ALTER TABLE chat_groups ADD community_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER created_by_user_id");
            }
            // Unique among non-null community links (MySQL allows multiple NULLs in UNIQUE).
            $idx = $dbh->query("SHOW INDEX FROM chat_groups WHERE Key_name = 'uq_chat_groups_community'")->fetch(PDO::FETCH_ASSOC);
            if (!$idx) {
                try {
                    $dbh->exec("ALTER TABLE chat_groups ADD UNIQUE KEY uq_chat_groups_community (community_id)");
                } catch (Throwable $e) {
                    /* ignore if already present or unsupported */
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('msb_open_community_group_chat')) {
    /**
     * @return array{ok:bool,group_id?:int,message?:string}
     */
    function msb_open_community_group_chat(PDO $dbh, int $meId, int $communityId): array
    {
        if ($meId <= 0 || $communityId <= 0) {
            return ['ok' => false, 'message' => 'Invalid community.'];
        }
        if (!msb_cg_ensure_community_column($dbh)) {
            return ['ok' => false, 'message' => 'Group chat is unavailable.'];
        }

        try {
            $st = $dbh->prepare("
                SELECT c.id, c.name, c.owner_user_id, c.status,
                       m.status AS my_status, m.role AS my_role
                FROM communities c
                LEFT JOIN community_members m
                  ON m.community_id = c.id AND m.user_id = :me
                WHERE c.id = :cid
                LIMIT 1
            ");
            $st->execute([':cid' => $communityId, ':me' => $meId]);
            $community = $st->fetch(PDO::FETCH_ASSOC);
            if (!$community || (int)($community['status'] ?? 0) !== 1) {
                return ['ok' => false, 'message' => 'Community not found.'];
            }
            if ((string)($community['my_status'] ?? '') !== 'active') {
                return ['ok' => false, 'message' => 'Join this community to message members.'];
            }

            $groupName = trim(preg_replace('/\s+/', ' ', (string)($community['name'] ?? '')));
            if ($groupName === '') {
                $groupName = 'Community';
            }
            if (mb_strlen($groupName) > 150) {
                $groupName = mb_substr($groupName, 0, 150);
            }

            $ownerId = (int)($community['owner_user_id'] ?? 0);
            if ($ownerId <= 0) {
                $ownerId = $meId;
            }

            // Find existing linked group.
            $st = $dbh->prepare("
                SELECT id, name, status
                FROM chat_groups
                WHERE community_id = :cid
                ORDER BY id ASC
                LIMIT 1
            ");
            $st->execute([':cid' => $communityId]);
            $group = $st->fetch(PDO::FETCH_ASSOC);

            $dbh->beginTransaction();

            if ($group && (int)($group['status'] ?? 0) === 1) {
                $groupId = (int)$group['id'];
                // Keep group title in sync with community name.
                if ((string)($group['name'] ?? '') !== $groupName) {
                    $dbh->prepare("UPDATE chat_groups SET name = :n, updated_at = NOW() WHERE id = :gid LIMIT 1")
                        ->execute([':n' => $groupName, ':gid' => $groupId]);
                }
            } else {
                $st = $dbh->prepare("
                    INSERT INTO chat_groups (name, created_by_user_id, community_id, status, created_at, updated_at)
                    VALUES (:name, :owner, :cid, 1, NOW(), NOW())
                ");
                $st->execute([
                    ':name' => $groupName,
                    ':owner' => $ownerId,
                    ':cid' => $communityId,
                ]);
                $groupId = (int)$dbh->lastInsertId();
            }

            // Sync active community members into the group chat.
            $st = $dbh->prepare("
                SELECT user_id, role
                FROM community_members
                WHERE community_id = :cid AND status = 'active'
            ");
            $st->execute([':cid' => $communityId]);
            $members = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $ins = $dbh->prepare("
                INSERT INTO chat_group_members (group_id, user_id, role, added_by_user_id, joined_at, left_at, blocked_at)
                VALUES (:gid, :uid, :role, :added_by, NOW(), NULL, NULL)
                ON DUPLICATE KEY UPDATE
                    left_at = NULL,
                    blocked_at = NULL,
                    role = IF(role = 'owner', 'owner', VALUES(role))
            ");

            $meInList = false;
            foreach ($members as $member) {
                $uid = (int)($member['user_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                if ($uid === $meId) {
                    $meInList = true;
                }
                $cRole = (string)($member['role'] ?? 'member');
                $gRole = 'member';
                if ($uid === $ownerId || $cRole === 'owner') {
                    $gRole = 'owner';
                } elseif (in_array($cRole, ['admin', 'moderator'], true)) {
                    $gRole = 'admin';
                }
                $ins->execute([
                    ':gid' => $groupId,
                    ':uid' => $uid,
                    ':role' => $gRole,
                    ':added_by' => $meId,
                ]);
            }
            if (!$meInList) {
                $ins->execute([
                    ':gid' => $groupId,
                    ':uid' => $meId,
                    ':role' => ($meId === $ownerId ? 'owner' : 'member'),
                    ':added_by' => $meId,
                ]);
            }

            $dbh->prepare("UPDATE chat_groups SET updated_at = NOW() WHERE id = :gid LIMIT 1")
                ->execute([':gid' => $groupId]);

            $dbh->commit();
            return ['ok' => true, 'group_id' => $groupId, 'message' => 'Opened community chat.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            return ['ok' => false, 'message' => 'Unable to open community chat right now.'];
        }
    }
}
