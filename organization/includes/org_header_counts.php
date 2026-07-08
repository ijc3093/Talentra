<?php
declare(strict_types=1);

if (!function_exists('org_header_message_unread_count')) {
    function org_header_message_unread_count(PDO $dbh, int $orgId, int $meMemberId): int
    {
        if ($orgId <= 0 || $meMemberId <= 0) {
            return 0;
        }
        try {
            $st = $dbh->prepare("
                SELECT COUNT(*)
                FROM org_messages
                WHERE org_id = :org
                  AND receiver_member_id = :me
                  AND is_read = 0
            ");
            $st->execute([':org' => $orgId, ':me' => $meMemberId]);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('org_header_feed_unread_count')) {
    function org_header_feed_unread_count(PDO $dbh, int $orgId, int $meMemberId): int
    {
        if ($orgId <= 0 || $meMemberId <= 0) {
            return 0;
        }

        $defaultBaseline = time() - (180 * 86400);
        $tabs = [
            'work' => " AND p.post_type IN ('announcement','direction','update','weekly_update') ",
            'culture' => " AND p.post_type = 'recognition' ",
        ];

        $total = 0;
        foreach ($tabs as $tab => $whereType) {
            $readAtKey = 'feed_last_read_at_' . $orgId . '_' . $tab . '_' . $meMemberId;
            $lastRead = (int)($_SESSION[$readAtKey] ?? 0);
            if ($lastRead <= 0) {
                $lastRead = $defaultBaseline;
            }

            try {
                $st = $dbh->prepare("
                    SELECT COUNT(*)
                    FROM org_posts p
                    WHERE p.org_id = :org
                      $whereType
                      AND p.created_at > FROM_UNIXTIME(:ts)
                ");
                $st->execute([':org' => $orgId, ':ts' => $lastRead]);
                $total += (int)($st->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                continue;
            }
        }

        return $total;
    }
}
