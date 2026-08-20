<?php
declare(strict_types=1);

/**
 * Admin moderation activity helpers for user_activity / reports / report_detail.
 * Evidence for humans — unusual ≠ automatically bad.
 */

if (!function_exists('msb_mod_table_exists')) {
    function msb_mod_table_exists(PDO $dbh, string $table): bool
    {
        static $cache = [];
        $table = preg_replace('/[^a-z0-9_]/i', '', $table) ?? '';
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
            $cache[$table] = (bool)($st && $st->fetchColumn());
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('msb_mod_ensure_status_schema')) {
    function msb_mod_ensure_status_schema(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS user_moderation_status (
                  user_id INT NOT NULL,
                  status ENUM('normal','review','high_risk') NOT NULL DEFAULT 'normal',
                  note TEXT NULL,
                  set_by_admin_id INT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // ignore — page still works with computed risk only
        }
    }
}

if (!function_exists('msb_mod_status_get')) {
    /**
     * @return array{status:string,note:string,updated_at:?string,set_by_admin_id:int}|null
     */
    function msb_mod_status_get(PDO $dbh, int $userId): ?array
    {
        msb_mod_ensure_status_schema($dbh);
        if ($userId <= 0 || !msb_mod_table_exists($dbh, 'user_moderation_status')) {
            return null;
        }
        try {
            $st = $dbh->prepare('SELECT status, note, updated_at, set_by_admin_id FROM user_moderation_status WHERE user_id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return [
                'status' => (string)($row['status'] ?? 'normal'),
                'note' => (string)($row['note'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
                'set_by_admin_id' => (int)($row['set_by_admin_id'] ?? 0),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('msb_mod_status_set')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function msb_mod_status_set(PDO $dbh, int $userId, string $status, int $adminId, string $note = ''): array
    {
        msb_mod_ensure_status_schema($dbh);
        $status = strtolower(trim($status));
        if (!in_array($status, ['normal', 'review', 'high_risk'], true)) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Invalid user.'];
        }
        if (!msb_mod_table_exists($dbh, 'user_moderation_status')) {
            return ['ok' => false, 'error' => 'Moderation status table unavailable.'];
        }
        $note = trim($note);
        if (mb_strlen($note) > 2000) {
            $note = mb_substr($note, 0, 2000);
        }
        try {
            $st = $dbh->prepare('
                INSERT INTO user_moderation_status (user_id, status, note, set_by_admin_id, created_at, updated_at)
                VALUES (:uid, :st, :note, :aid, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  status = VALUES(status),
                  note = VALUES(note),
                  set_by_admin_id = VALUES(set_by_admin_id),
                  updated_at = NOW()
            ');
            $st->execute([
                ':uid' => $userId,
                ':st' => $status,
                ':note' => $note !== '' ? $note : null,
                ':aid' => $adminId > 0 ? $adminId : null,
            ]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not save moderation status.'];
        }
    }
}

if (!function_exists('msb_mod_count_safe')) {
    function msb_mod_count_safe(PDO $dbh, string $sql, array $params = []): int
    {
        try {
            $st = $dbh->prepare($sql);
            $st->execute($params);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('msb_mod_user_activity_summary')) {
    /**
     * @return array<string,mixed>
     */
    function msb_mod_user_activity_summary(PDO $dbh, int $userId): array
    {
        $out = [
            'posts_24h' => 0,
            'posts_7d' => 0,
            'posts_total' => 0,
            'posts_deleted' => 0,
            'posts_edited_proxy' => 0,
            'likes_given_7d' => 0,
            'likes_received_7d' => 0,
            'comments_given_7d' => 0,
            'comments_received_7d' => 0,
            'shares_given_7d' => 0,
            'follows_out_7d' => 0,
            'follows_in_7d' => 0,
            'reports_about_pending' => 0,
            'reports_about_total' => 0,
            'reports_filed_total' => 0,
            'sessions_active' => 0,
            'last_login_at' => null,
            'last_login_ip' => '',
            'account_age_days' => 0,
        ];
        if ($userId <= 0) {
            return $out;
        }

        if (msb_mod_table_exists($dbh, 'public_posts')) {
            $out['posts_total'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_posts
                WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
            ', [':uid' => $userId]);
            $out['posts_24h'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_posts
                WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 1 DAY)
            ', [':uid' => $userId]);
            $out['posts_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_posts
                WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
            $out['posts_deleted'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_posts
                WHERE user_id = :uid AND is_deleted = 1
            ', [':uid' => $userId]);
            $out['posts_edited_proxy'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_posts
                WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
                  AND updated_at IS NOT NULL AND updated_at > DATE_ADD(created_at, INTERVAL 2 MINUTE)
            ', [':uid' => $userId]);
        }

        if (msb_mod_table_exists($dbh, 'public_post_reactions')) {
            $out['likes_given_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_post_reactions
                WHERE user_id = :uid AND reacted_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
            $out['likes_received_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_post_reactions r
                INNER JOIN public_posts p ON p.id = r.post_id
                WHERE p.user_id = :uid AND r.reacted_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
        }

        if (msb_mod_table_exists($dbh, 'public_post_comments')) {
            $out['comments_given_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_post_comments
                WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
            $out['comments_received_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_post_comments c
                INNER JOIN public_posts p ON p.id = c.post_id
                WHERE p.user_id = :uid AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
                  AND c.created_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
        }

        if (msb_mod_table_exists($dbh, 'public_post_shares')) {
            $out['shares_given_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_post_shares
                WHERE user_id = :uid AND shared_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
        }

        if (msb_mod_table_exists($dbh, 'public_follows')) {
            $out['follows_out_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_follows
                WHERE follower_id = :uid AND created_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
            $out['follows_in_7d'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_follows
                WHERE following_id = :uid AND created_at >= (NOW() - INTERVAL 7 DAY)
            ', [':uid' => $userId]);
        }

        if (msb_mod_table_exists($dbh, 'public_user_reports')) {
            $out['reports_about_pending'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports
                WHERE target_user_id = :uid AND status = \'pending\'
            ', [':uid' => $userId]);
            $out['reports_about_total'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports WHERE target_user_id = :uid
            ', [':uid' => $userId]);
            $out['reports_filed_total'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports WHERE reporter_id = :uid
            ', [':uid' => $userId]);
        }

        if (msb_mod_table_exists($dbh, 'user_sessions')) {
            $out['sessions_active'] = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM user_sessions
                WHERE user_id = :uid AND (revoked_at IS NULL OR revoked_at = \'0000-00-00 00:00:00\')
            ', [':uid' => $userId]);
            try {
                $st = $dbh->prepare('
                    SELECT last_seen_at, ip_address
                    FROM user_sessions
                    WHERE user_id = :uid
                    ORDER BY COALESCE(last_seen_at, created_at) DESC
                    LIMIT 1
                ');
                $st->execute([':uid' => $userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['last_login_at'] = $row['last_seen_at'] ?? null;
                $out['last_login_ip'] = (string)($row['ip_address'] ?? '');
            } catch (Throwable $e) {
            }
        }

        try {
            $st = $dbh->prepare('SELECT created_at FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $created = (string)($st->fetchColumn() ?: '');
            if ($created !== '') {
                $ts = strtotime($created);
                if ($ts) {
                    $out['account_age_days'] = max(0, (int)floor((time() - $ts) / 86400));
                }
            }
        } catch (Throwable $e) {
        }

        return $out;
    }
}

if (!function_exists('msb_mod_user_recent_posts_full')) {
    /**
     * @return list<array<string,mixed>>
     */
    function msb_mod_user_recent_posts_full(PDO $dbh, int $userId, int $limit = 20, bool $includeDeleted = true): array
    {
        if ($userId <= 0 || !msb_mod_table_exists($dbh, 'public_posts')) {
            return [];
        }
        $limit = max(1, min(80, $limit));
        $whereDel = $includeDeleted ? '1=1' : '(is_deleted = 0 OR is_deleted IS NULL)';
        try {
            $st = $dbh->prepare("
                SELECT id, user_id, title, description, body, visibility,
                       device_label, device_viewport,
                       COALESCE(views_count, 0) AS views_count,
                       created_at, updated_at,
                       COALESCE(is_deleted, 0) AS is_deleted,
                       COALESCE(is_archived, 0) AS is_archived
                FROM public_posts
                WHERE user_id = :uid AND {$whereDel}
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $st->execute([':uid' => $userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
        $attachMap = [];
        if ($ids && msb_mod_table_exists($dbh, 'public_post_attachments')) {
            try {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $q = $dbh->prepare("
                    SELECT post_id, type, file_path, thumb_path
                    FROM public_post_attachments
                    WHERE post_id IN ($in)
                    ORDER BY id ASC
                ");
                $q->execute($ids);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $pid = (int)($a['post_id'] ?? 0);
                    if (!isset($attachMap[$pid])) {
                        $attachMap[$pid] = [];
                    }
                    $attachMap[$pid][] = $a;
                }
            } catch (Throwable $e) {
            }
        }

        foreach ($rows as &$r) {
            $pid = (int)($r['id'] ?? 0);
            $r['attachments'] = $attachMap[$pid] ?? [];
            $text = trim((string)($r['body'] ?? ''));
            if ($text === '') {
                $text = trim((string)($r['description'] ?? ''));
            }
            if ($text === '') {
                $text = trim((string)($r['title'] ?? ''));
            }
            $r['text_preview'] = $text;
            $edited = false;
            $created = (string)($r['created_at'] ?? '');
            $updated = (string)($r['updated_at'] ?? '');
            if ($created !== '' && $updated !== '') {
                $c = strtotime($created);
                $u = strtotime($updated);
                $edited = $c && $u && ($u - $c) > 120;
            }
            $r['was_edited'] = $edited;
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('msb_mod_post_detail')) {
    /**
     * @return array<string,mixed>|null
     */
    function msb_mod_post_detail(PDO $dbh, int $postId): ?array
    {
        if ($postId <= 0 || !msb_mod_table_exists($dbh, 'public_posts')) {
            return null;
        }
        try {
            $st = $dbh->prepare('
                SELECT p.*, u.username, u.name, u.email, u.friend_code, u.account_kind
                FROM public_posts p
                LEFT JOIN users u ON u.id = p.user_id
                WHERE p.id = :id
                LIMIT 1
            ');
            $st->execute([':id' => $postId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        $row['attachments'] = [];
        if (msb_mod_table_exists($dbh, 'public_post_attachments')) {
            try {
                $q = $dbh->prepare('SELECT type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :id ORDER BY id ASC');
                $q->execute([':id' => $postId]);
                $row['attachments'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
            }
        }
        return $row;
    }
}

if (!function_exists('msb_mod_behavior_indicators')) {
    /**
     * @param array<string,mixed> $summary
     * @return array{tier:string,label:string,score:int,flags:list<array{code:string,label:string,level:string}>}
     */
    function msb_mod_behavior_indicators(array $summary): array
    {
        $flags = [];
        $score = 0;

        $posts24 = (int)($summary['posts_24h'] ?? 0);
        $posts7 = (int)($summary['posts_7d'] ?? 0);
        $age = (int)($summary['account_age_days'] ?? 0);
        $pending = (int)($summary['reports_about_pending'] ?? 0);
        $reportsAbout = (int)($summary['reports_about_total'] ?? 0);
        $followsOut = (int)($summary['follows_out_7d'] ?? 0);
        $likesGiven = (int)($summary['likes_given_7d'] ?? 0);
        $commentsGiven = (int)($summary['comments_given_7d'] ?? 0);
        $deleted = (int)($summary['posts_deleted'] ?? 0);
        $total = max(1, (int)($summary['posts_total'] ?? 0));

        if ($posts24 >= 8) {
            $flags[] = ['code' => 'burst_posts', 'label' => 'Many posts in the last 24 hours (' . $posts24 . ')', 'level' => 'high'];
            $score += 3;
        } elseif ($posts24 >= 4) {
            $flags[] = ['code' => 'elevated_posts', 'label' => 'Elevated posting in last 24 hours (' . $posts24 . ')', 'level' => 'mid'];
            $score += 2;
        }

        if ($age <= 3 && ($posts7 >= 5 || $followsOut >= 20 || $likesGiven >= 40)) {
            $flags[] = ['code' => 'new_account_volume', 'label' => 'New account with high early activity (age ' . $age . 'd)', 'level' => 'high'];
            $score += 3;
        } elseif ($age <= 7 && $posts7 >= 3) {
            $flags[] = ['code' => 'new_account_active', 'label' => 'Young account already posting often', 'level' => 'mid'];
            $score += 1;
        }

        if ($followsOut >= 40) {
            $flags[] = ['code' => 'follow_burst', 'label' => 'Large follow burst in 7 days (' . $followsOut . ')', 'level' => 'high'];
            $score += 3;
        } elseif ($followsOut >= 15) {
            $flags[] = ['code' => 'follow_elevated', 'label' => 'Elevated follows in 7 days (' . $followsOut . ')', 'level' => 'mid'];
            $score += 1;
        }

        if (($likesGiven + $commentsGiven) >= 80) {
            $flags[] = ['code' => 'engage_burst', 'label' => 'High likes/comments given in 7 days', 'level' => 'mid'];
            $score += 2;
        }

        if ($pending >= 3) {
            $flags[] = ['code' => 'many_pending_reports', 'label' => $pending . ' pending reports about this user', 'level' => 'high'];
            $score += 4;
        } elseif ($pending >= 1) {
            $flags[] = ['code' => 'pending_reports', 'label' => $pending . ' pending report(s) about this user', 'level' => 'mid'];
            $score += 2;
        } elseif ($reportsAbout >= 3) {
            $flags[] = ['code' => 'report_history', 'label' => $reportsAbout . ' lifetime reports about this user', 'level' => 'mid'];
            $score += 1;
        }

        if ($deleted >= 5 || ($deleted / $total) >= 0.4) {
            $flags[] = ['code' => 'delete_rate', 'label' => 'High deleted-post count (' . $deleted . ')', 'level' => 'mid'];
            $score += 1;
        }

        if ($flags === []) {
            $flags[] = ['code' => 'none', 'label' => 'No strong unusual patterns detected from available signals', 'level' => 'ok'];
        }

        if ($score >= 6) {
            $tier = 'high_risk';
            $label = 'High risk';
        } elseif ($score >= 2) {
            $tier = 'review';
            $label = 'Review';
        } else {
            $tier = 'normal';
            $label = 'Normal';
        }

        return [
            'tier' => $tier,
            'label' => $label,
            'score' => $score,
            'flags' => $flags,
        ];
    }
}

if (!function_exists('msb_mod_tier_pill_class')) {
    function msb_mod_tier_pill_class(string $tier): string
    {
        $tier = strtolower(trim($tier));
        if ($tier === 'high_risk') {
            return 'bad';
        }
        if ($tier === 'review') {
            return 'warn';
        }
        return 'ok';
    }
}

if (!function_exists('msb_mod_tier_emoji')) {
    function msb_mod_tier_emoji(string $tier): string
    {
        $tier = strtolower(trim($tier));
        if ($tier === 'high_risk') {
            return '🔴';
        }
        if ($tier === 'review') {
            return '🟡';
        }
        return '🟢';
    }
}

if (!function_exists('msb_mod_short_text')) {
    function msb_mod_short_text(string $s, int $n = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        if (mb_strlen($s) <= $n) {
            return $s;
        }
        return mb_substr($s, 0, $n - 1) . '…';
    }
}

if (!function_exists('msb_mod_activity_table_stats')) {
    /**
     * Dashboard cards for last 24h vs prior 24h.
     *
     * @return array<string,array{value:int,prev:int,delta_pct:int}>
     */
    function msb_mod_activity_table_stats(PDO $dbh): array
    {
        $stat = static function (int $now, int $prev): array {
            $delta = 0;
            if ($prev > 0) {
                $delta = (int)round((($now - $prev) / $prev) * 100);
            } elseif ($now > 0) {
                $delta = 100;
            }
            return ['value' => $now, 'prev' => $prev, 'delta_pct' => $delta];
        };

        $postsNow = msb_mod_count_safe($dbh, '
            SELECT COUNT(*) FROM public_posts
            WHERE (is_deleted = 0 OR is_deleted IS NULL)
              AND created_at >= (NOW() - INTERVAL 1 DAY)
        ');
        $postsPrev = msb_mod_count_safe($dbh, '
            SELECT COUNT(*) FROM public_posts
            WHERE (is_deleted = 0 OR is_deleted IS NULL)
              AND created_at >= (NOW() - INTERVAL 2 DAY)
              AND created_at < (NOW() - INTERVAL 1 DAY)
        ');

        $usersNow = 0;
        $usersPrev = 0;
        if (msb_mod_table_exists($dbh, 'public_posts')) {
            $usersNow = msb_mod_count_safe($dbh, '
                SELECT COUNT(DISTINCT user_id) FROM public_posts
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 1 DAY)
            ');
            $usersPrev = msb_mod_count_safe($dbh, '
                SELECT COUNT(DISTINCT user_id) FROM public_posts
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                  AND created_at >= (NOW() - INTERVAL 2 DAY)
                  AND created_at < (NOW() - INTERVAL 1 DAY)
            ');
        }

        $reportsNow = 0;
        $reportsPrev = 0;
        $flaggedNow = 0;
        $flaggedPrev = 0;
        if (msb_mod_table_exists($dbh, 'public_user_reports')) {
            $reportsNow = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports
                WHERE created_at >= (NOW() - INTERVAL 1 DAY)
            ');
            $reportsPrev = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports
                WHERE created_at >= (NOW() - INTERVAL 2 DAY)
                  AND created_at < (NOW() - INTERVAL 1 DAY)
            ');
            $flaggedNow = msb_mod_count_safe($dbh, '
                SELECT COUNT(DISTINCT target_id) FROM public_user_reports
                WHERE target_type = \'post\'
                  AND created_at >= (NOW() - INTERVAL 1 DAY)
            ');
            $flaggedPrev = msb_mod_count_safe($dbh, '
                SELECT COUNT(DISTINCT target_id) FROM public_user_reports
                WHERE target_type = \'post\'
                  AND created_at >= (NOW() - INTERVAL 2 DAY)
                  AND created_at < (NOW() - INTERVAL 1 DAY)
            ');
        }

        $highNow = 0;
        $highPrev = 0;
        if (msb_mod_table_exists($dbh, 'user_moderation_status')) {
            msb_mod_ensure_status_schema($dbh);
            $highNow = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM user_moderation_status
                WHERE status = \'high_risk\'
                  AND updated_at >= (NOW() - INTERVAL 1 DAY)
            ');
            $highPrev = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM user_moderation_status
                WHERE status = \'high_risk\'
                  AND updated_at >= (NOW() - INTERVAL 2 DAY)
                  AND updated_at < (NOW() - INTERVAL 1 DAY)
            ');
        }
        // Fallback: pending reports about users as high-attention proxy when no saved high_risk.
        if ($highNow === 0 && $highPrev === 0 && msb_mod_table_exists($dbh, 'public_user_reports')) {
            $highNow = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports
                WHERE status = \'pending\' AND created_at >= (NOW() - INTERVAL 1 DAY)
            ');
            $highPrev = msb_mod_count_safe($dbh, '
                SELECT COUNT(*) FROM public_user_reports
                WHERE status = \'pending\'
                  AND created_at >= (NOW() - INTERVAL 2 DAY)
                  AND created_at < (NOW() - INTERVAL 1 DAY)
            ');
        }

        return [
            'new_posts' => $stat($postsNow, $postsPrev),
            'users_active' => $stat($usersNow, $usersPrev),
            'reports' => $stat($reportsNow, $reportsPrev),
            'posts_flagged' => $stat($flaggedNow, $flaggedPrev),
            'high_risk' => $stat($highNow, $highPrev),
        ];
    }
}

if (!function_exists('msb_mod_activity_table_rows')) {
    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    function msb_mod_activity_table_rows(
        PDO $dbh,
        string $tab = 'all',
        string $q = '',
        string $visibility = 'all',
        string $postType = 'all',
        string $status = 'all',
        string $risk = 'all',
        string $dateFrom = '',
        string $dateTo = '',
        int $page = 1,
        int $perPage = 25,
        int $userId = 0,
        string $kind = ''
    ): array {
        if (!msb_mod_table_exists($dbh, 'public_posts')) {
            return ['rows' => [], 'total' => 0];
        }

        $tab = strtolower(trim($tab));
        $visibility = strtolower(trim($visibility));
        $postType = strtolower(trim($postType));
        $status = strtolower(trim($status));
        $risk = strtolower(trim($risk));
        $q = trim($q);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['(p.is_deleted = 0 OR p.is_deleted IS NULL)'];
        $params = [];

        if ($userId > 0) {
            $where[] = 'p.user_id = :uid';
            $params[':uid'] = $userId;
        }

        if ($kind !== '' && function_exists('admin_kind_user_where')) {
            $where[] = admin_kind_user_where($kind, 'u');
        }

        if ($dateFrom !== '') {
            $where[] = 'p.created_at >= :df';
            $params[':df'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'p.created_at <= :dt';
            $params[':dt'] = $dateTo . ' 23:59:59';
        }
        if (in_array($visibility, ['public', 'friends', 'private'], true)) {
            $where[] = 'p.visibility = :vis';
            $params[':vis'] = $visibility;
        }
        if ($q !== '') {
            $where[] = '(u.username LIKE :q OR u.name LIKE :q OR u.email LIKE :q OR CAST(p.id AS CHAR) LIKE :q OR p.title LIKE :q OR p.description LIKE :q OR p.body LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $joinReports = '';
        if (msb_mod_table_exists($dbh, 'public_user_reports')) {
            $joinReports = '
                LEFT JOIN (
                    SELECT target_id AS post_id, COUNT(*) AS report_count,
                           SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_count
                    FROM public_user_reports
                    WHERE target_type = \'post\'
                    GROUP BY target_id
                ) rr ON rr.post_id = p.id
            ';
        }

        if ($tab === 'new') {
            $where[] = 'p.created_at >= (NOW() - INTERVAL 1 DAY)';
        } elseif ($tab === 'flagged' || $tab === 'reported') {
            $where[] = 'COALESCE(rr.report_count, 0) > 0';
        }

        if ($status === 'review') {
            // pending reports or saved review
            $where[] = '(COALESCE(rr.pending_count, 0) > 0 OR ums.status = \'review\')';
        } elseif ($status === 'under_review') {
            $where[] = 'COALESCE(rr.pending_count, 0) > 0';
        } elseif ($status === 'normal') {
            $where[] = '(COALESCE(rr.pending_count, 0) = 0 AND (ums.status IS NULL OR ums.status = \'normal\'))';
        } elseif ($status === 'high_risk') {
            $where[] = 'ums.status = \'high_risk\'';
        }

        $joinMod = '';
        $hasUms = msb_mod_table_exists($dbh, 'user_moderation_status');
        if ($hasUms) {
            msb_mod_ensure_status_schema($dbh);
            $joinMod = 'LEFT JOIN user_moderation_status ums ON ums.user_id = p.user_id';
        } else {
            // Replace ums-dependent filters when table is missing.
            $where = array_values(array_filter($where, static function ($w) {
                return strpos($w, 'ums.') === false;
            }));
            if ($status === 'review') {
                $where[] = 'COALESCE(rr.pending_count, 0) > 0';
            } elseif ($status === 'high_risk') {
                $where[] = '0=1';
            } elseif ($status === 'normal') {
                $where[] = 'COALESCE(rr.pending_count, 0) = 0';
            }
        }

        if (!$joinReports) {
            $where = array_values(array_filter($where, static function ($w) {
                return strpos($w, 'rr.') === false;
            }));
        }

        $selectUms = $hasUms ? 'ums.status AS mod_status' : 'NULL AS mod_status';
        $selectRrCount = $joinReports !== '' ? 'COALESCE(rr.report_count, 0) AS report_count' : '0 AS report_count';
        $selectRrPending = $joinReports !== '' ? 'COALESCE(rr.pending_count, 0) AS pending_count' : '0 AS pending_count';

        $sqlBase = '
            FROM public_posts p
            LEFT JOIN users u ON u.id = p.user_id
            ' . $joinReports . '
            ' . $joinMod . '
            WHERE ' . implode(' AND ', $where);

        $total = 0;
        try {
            $st = $dbh->prepare('SELECT COUNT(*) ' . $sqlBase);
            $st->execute($params);
            $total = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0];
        }

        $rows = [];
        try {
            $sql = '
                SELECT
                    p.id, p.user_id, p.title, p.description, p.body, p.visibility,
                    p.created_at, p.updated_at, COALESCE(p.views_count, 0) AS views_count,
                    u.username, u.name, u.email, u.friend_code, COALESCE(u.account_kind, \'personal\') AS account_kind,
                    ' . $selectRrCount . ',
                    ' . $selectRrPending . ',
                    ' . $selectUms . '
                ' . $sqlBase . '
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
            $st = $dbh->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => $total];
        }

        $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
        $userIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['user_id'] ?? 0), $rows))));
        $attachMap = [];
        if ($ids && msb_mod_table_exists($dbh, 'public_post_attachments')) {
            try {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $qAtt = $dbh->prepare("SELECT post_id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id IN ($in) ORDER BY id ASC");
                $qAtt->execute($ids);
                foreach ($qAtt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $pid = (int)$a['post_id'];
                    if (!isset($attachMap[$pid])) {
                        $attachMap[$pid] = [];
                    }
                    $attachMap[$pid][] = $a;
                }
            } catch (Throwable $e) {
            }
        }

        // 7d engagement per author (cached per page)
        $eng = [];
        foreach ($userIds as $uid) {
            $sum = msb_mod_user_activity_summary($dbh, $uid);
            $beh = msb_mod_behavior_indicators($sum);
            $saved = msb_mod_status_get($dbh, $uid);
            $tier = (string)(($saved['status'] ?? '') !== '' ? $saved['status'] : ($beh['tier'] ?? 'normal'));
            $eng[$uid] = [
                'posts_7d' => (int)($sum['posts_7d'] ?? 0),
                'likes_7d' => (int)($sum['likes_given_7d'] ?? 0),
                'comments_7d' => (int)($sum['comments_given_7d'] ?? 0),
                'shares_7d' => (int)($sum['shares_given_7d'] ?? 0),
                'tier' => $tier,
                'suggested' => (string)($beh['tier'] ?? 'normal'),
            ];
        }

        $out = [];
        foreach ($rows as $r) {
            $pid = (int)($r['id'] ?? 0);
            $uid = (int)($r['user_id'] ?? 0);
            $atts = $attachMap[$pid] ?? [];
            $types = [];
            $thumb = '';
            foreach ($atts as $a) {
                $t = strtolower(trim((string)($a['type'] ?? 'file')));
                $types[] = $t;
                if ($thumb === '' && !empty($a['thumb_path'])) {
                    $thumb = (string)$a['thumb_path'];
                } elseif ($thumb === '' && $t === 'image' && !empty($a['file_path'])) {
                    $thumb = (string)$a['file_path'];
                }
            }
            $types = array_values(array_unique($types));
            $postTypeLabel = 'Text';
            if (in_array('video', $types, true)) {
                $postTypeLabel = 'Video';
            } elseif (in_array('image', $types, true)) {
                $postTypeLabel = 'Image';
            } elseif (preg_match('~https?://~i', (string)($r['body'] ?? $r['description'] ?? ''))) {
                $postTypeLabel = 'Link';
            }

            if ($postType !== 'all') {
                $want = ucfirst($postType);
                if (strcasecmp($want, $postTypeLabel) !== 0) {
                    continue;
                }
            }

            $text = trim((string)($r['body'] ?? ''));
            if ($text === '') {
                $text = trim((string)($r['description'] ?? ''));
            }
            if ($text === '') {
                $text = trim((string)($r['title'] ?? ''));
            }

            $tier = (string)($eng[$uid]['tier'] ?? 'normal');
            if ($risk !== 'all') {
                $map = [
                    'high' => 'high_risk',
                    'medium' => 'review',
                    'low' => 'normal',
                    'high_risk' => 'high_risk',
                    'review' => 'review',
                    'normal' => 'normal',
                ];
                $wantRisk = $map[$risk] ?? $risk;
                if ($tier !== $wantRisk) {
                    continue;
                }
            }

            $pending = (int)($r['pending_count'] ?? 0);
            $modStatus = strtolower(trim((string)($r['mod_status'] ?? '')));
            if ($pending > 0) {
                $rowStatus = 'under_review';
            } elseif ($modStatus === 'review') {
                $rowStatus = 'review';
            } elseif ($modStatus === 'high_risk') {
                $rowStatus = 'high_risk';
            } else {
                $rowStatus = 'normal';
            }

            $createdAt = (string)($r['created_at'] ?? '');
            $updatedAt = (string)($r['updated_at'] ?? '');
            $wasEdited = false;
            if ($createdAt !== '' && $updatedAt !== '') {
                $cTs = strtotime($createdAt);
                $uTs = strtotime($updatedAt);
                $wasEdited = $cTs && $uTs && ($uTs - $cTs) > 120;
            }

            $out[] = [
                'id' => $pid,
                'user_id' => $uid,
                'username' => (string)($r['username'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'email' => (string)($r['email'] ?? ''),
                'friend_code' => (string)($r['friend_code'] ?? ''),
                'account_kind' => (string)($r['account_kind'] ?? 'personal'),
                'publisher_category' => (string)($r['publisher_category'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'description' => (string)($r['description'] ?? ''),
                'body' => (string)($r['body'] ?? ''),
                'post_type' => $postTypeLabel,
                'text_preview' => $text,
                'thumb' => $thumb,
                'attachments' => $atts,
                'visibility' => (string)($r['visibility'] ?? 'public'),
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
                'views_count' => (int)($r['views_count'] ?? 0),
                'report_count' => (int)($r['report_count'] ?? 0),
                'pending_count' => $pending,
                'activity_7d' => $eng[$uid] ?? ['posts_7d' => 0, 'likes_7d' => 0, 'comments_7d' => 0, 'shares_7d' => 0],
                'risk' => $tier,
                'status' => $rowStatus,
                'mod_status' => $modStatus,
                'was_edited' => $wasEdited,
            ];
        }

        // If post-type/risk filters removed rows client-side, total is approximate.
        return ['rows' => $out, 'total' => $total];
    }
}

if (!function_exists('msb_mod_reports_for_post')) {
    /**
     * Reports targeting a specific post (same source as activity table report counts).
     *
     * @return list<array<string,mixed>>
     */
    function msb_mod_reports_for_post(PDO $dbh, int $postId, int $limit = 40): array
    {
        if ($postId <= 0 || !msb_mod_table_exists($dbh, 'public_user_reports')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            $st = $dbh->prepare('
                SELECT
                  r.id, r.reporter_id, r.reporter_label, r.target_type, r.target_id, r.target_user_id,
                  r.reason, r.status, r.details, r.created_at, r.reviewed_at,
                  ru.username AS reporter_username,
                  ru.name AS reporter_name
                FROM public_user_reports r
                LEFT JOIN users ru ON ru.id = r.reporter_id
                WHERE r.target_type = \'post\' AND r.target_id = :pid
                ORDER BY r.id DESC
                LIMIT ' . (int)$limit
            );
            $st->execute([':pid' => $postId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('msb_mod_user_timeline')) {
    /**
     * Real recent activity events for overview timeline (posts, likes, comments, follows, shares).
     *
     * @return list<array{when:string,when_raw:string,icon:string,tone:string,text:string,meta:string,href?:string}>
     */
    function msb_mod_user_timeline(PDO $dbh, int $userId, int $limit = 12): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(40, $limit));
        $events = [];

        if (msb_mod_table_exists($dbh, 'public_posts')) {
            try {
                $st = $dbh->prepare('
                    SELECT id, created_at, updated_at
                    FROM public_posts
                    WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
                    ORDER BY created_at DESC, id DESC
                    LIMIT 20
                ');
                $st->execute([':uid' => $userId]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $pid = (int)($p['id'] ?? 0);
                    $created = (string)($p['created_at'] ?? '');
                    $updated = (string)($p['updated_at'] ?? '');
                    $c = $created !== '' ? strtotime($created) : false;
                    $u = $updated !== '' ? strtotime($updated) : false;
                    $edited = $c && $u && ($u - $c) > 120;
                    $events[] = [
                        'when_raw' => $created,
                        'when' => $created,
                        'icon' => 'fa-file-text-o',
                        'tone' => 'purple',
                        'text' => 'Created post #' . $pid,
                        'meta' => 'Post #' . $pid,
                        'href' => 'user_activity.php?user_id=' . $userId . '&post_id=' . $pid,
                    ];
                    if ($edited) {
                        $events[] = [
                            'when_raw' => $updated,
                            'when' => $updated,
                            'icon' => 'fa-pencil',
                            'tone' => 'orange',
                            'text' => 'Edited post #' . $pid,
                            'meta' => 'Post #' . $pid,
                            'href' => 'user_activity.php?user_id=' . $userId . '&post_id=' . $pid,
                        ];
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (msb_mod_table_exists($dbh, 'public_post_reactions')) {
            try {
                $st = $dbh->prepare('
                    SELECT COUNT(*) AS cnt, MAX(reacted_at) AS last_at
                    FROM public_post_reactions
                    WHERE user_id = :uid AND reacted_at >= (NOW() - INTERVAL 7 DAY)
                ');
                $st->execute([':uid' => $userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $cnt = (int)($row['cnt'] ?? 0);
                if ($cnt > 0) {
                    $events[] = [
                        'when_raw' => (string)($row['last_at'] ?? ''),
                        'when' => 'Last 7 days',
                        'icon' => 'fa-heart',
                        'tone' => 'pink',
                        'text' => 'Liked ' . $cnt . ' post' . ($cnt === 1 ? '' : 's'),
                        'meta' => $cnt . ' likes',
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        if (msb_mod_table_exists($dbh, 'public_post_comments')) {
            try {
                $st = $dbh->prepare('
                    SELECT COUNT(*) AS cnt, MAX(created_at) AS last_at
                    FROM public_post_comments
                    WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
                      AND created_at >= (NOW() - INTERVAL 7 DAY)
                ');
                $st->execute([':uid' => $userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $cnt = (int)($row['cnt'] ?? 0);
                if ($cnt > 0) {
                    $events[] = [
                        'when_raw' => (string)($row['last_at'] ?? ''),
                        'when' => 'Last 7 days',
                        'icon' => 'fa-comment',
                        'tone' => 'blue',
                        'text' => 'Commented on ' . $cnt . ' post' . ($cnt === 1 ? '' : 's'),
                        'meta' => $cnt . ' comments',
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        if (msb_mod_table_exists($dbh, 'public_post_shares')) {
            try {
                $st = $dbh->prepare('
                    SELECT COUNT(*) AS cnt, MAX(shared_at) AS last_at
                    FROM public_post_shares
                    WHERE user_id = :uid AND shared_at >= (NOW() - INTERVAL 7 DAY)
                ');
                $st->execute([':uid' => $userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $cnt = (int)($row['cnt'] ?? 0);
                if ($cnt > 0) {
                    $events[] = [
                        'when_raw' => (string)($row['last_at'] ?? ''),
                        'when' => 'Last 7 days',
                        'icon' => 'fa-share',
                        'tone' => 'teal',
                        'text' => 'Shared ' . $cnt . ' post' . ($cnt === 1 ? '' : 's'),
                        'meta' => $cnt . ' shares',
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        if (msb_mod_table_exists($dbh, 'public_follows')) {
            try {
                $st = $dbh->prepare('
                    SELECT COUNT(*) AS cnt, MAX(created_at) AS last_at
                    FROM public_follows
                    WHERE follower_id = :uid AND created_at >= (NOW() - INTERVAL 7 DAY)
                ');
                $st->execute([':uid' => $userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $cnt = (int)($row['cnt'] ?? 0);
                if ($cnt > 0) {
                    $events[] = [
                        'when_raw' => (string)($row['last_at'] ?? ''),
                        'when' => 'Last 7 days',
                        'icon' => 'fa-user-plus',
                        'tone' => 'green',
                        'text' => 'Followed ' . $cnt . ' account' . ($cnt === 1 ? '' : 's'),
                        'meta' => $cnt . ' follows',
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        if (msb_mod_table_exists($dbh, 'user_sessions')) {
            try {
                $st = $dbh->prepare('
                    SELECT last_seen_at, ip_address
                    FROM user_sessions
                    WHERE user_id = :uid
                    ORDER BY COALESCE(last_seen_at, created_at) DESC
                    LIMIT 1
                ');
                $st->execute([':uid' => $userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($row['last_seen_at'])) {
                    $ip = trim((string)($row['ip_address'] ?? ''));
                    $events[] = [
                        'when_raw' => (string)$row['last_seen_at'],
                        'when' => (string)$row['last_seen_at'],
                        'icon' => 'fa-sign-in',
                        'tone' => 'slate',
                        'text' => 'Device / session activity',
                        'meta' => $ip !== '' ? ('IP ' . $ip) : 'Session',
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        usort($events, static function ($a, $b) {
            $ta = strtotime((string)($a['when_raw'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['when_raw'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });

        $out = [];
        foreach (array_slice($events, 0, $limit) as $ev) {
            $when = (string)($ev['when'] ?? '');
            if ($when !== 'Last 7 days' && $when !== '') {
                $ev['when'] = function_exists('org_admin_fmt_dt') ? org_admin_fmt_dt($when) : $when;
            }
            unset($ev['when_raw']);
            $out[] = $ev;
        }
        return $out;
    }
}

if (!function_exists('msb_mod_activity_row_for_post')) {
    /**
     * One activity-table-shaped row for a post id (exact match for overview).
     *
     * @return array<string,mixed>|null
     */
    function msb_mod_activity_row_for_post(PDO $dbh, int $postId): ?array
    {
        if ($postId <= 0 || !msb_mod_table_exists($dbh, 'public_posts')) {
            return null;
        }
        try {
            $joinReports = '';
            $selectRrCount = '0 AS report_count';
            $selectRrPending = '0 AS pending_count';
            if (msb_mod_table_exists($dbh, 'public_user_reports')) {
                $joinReports = '
                    LEFT JOIN (
                        SELECT target_id AS post_id, COUNT(*) AS report_count,
                               SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_count
                        FROM public_user_reports
                        WHERE target_type = \'post\' AND target_id = ' . (int)$postId . '
                        GROUP BY target_id
                    ) rr ON rr.post_id = p.id
                ';
                $selectRrCount = 'COALESCE(rr.report_count, 0) AS report_count';
                $selectRrPending = 'COALESCE(rr.pending_count, 0) AS pending_count';
            }
            $joinMod = '';
            $selectUms = 'NULL AS mod_status';
            if (msb_mod_table_exists($dbh, 'user_moderation_status')) {
                msb_mod_ensure_status_schema($dbh);
                $joinMod = 'LEFT JOIN user_moderation_status ums ON ums.user_id = p.user_id';
                $selectUms = 'ums.status AS mod_status';
            }
            $st = $dbh->prepare('
                SELECT
                    p.id, p.user_id, p.title, p.description, p.body, p.visibility,
                    p.created_at, p.updated_at, COALESCE(p.views_count, 0) AS views_count,
                    COALESCE(p.is_deleted, 0) AS is_deleted,
                    u.username, u.name, u.email, u.friend_code,
                    ' . $selectRrCount . ',
                    ' . $selectRrPending . ',
                    ' . $selectUms . '
                FROM public_posts p
                LEFT JOIN users u ON u.id = p.user_id
                ' . $joinReports . '
                ' . $joinMod . '
                WHERE p.id = :pid
                LIMIT 1
            ');
            $st->execute([':pid' => $postId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        $uid = (int)($r['user_id'] ?? 0);
        $atts = [];
        if (msb_mod_table_exists($dbh, 'public_post_attachments')) {
            try {
                $q = $dbh->prepare('SELECT post_id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :id ORDER BY id ASC');
                $q->execute([':id' => $postId]);
                $atts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
            }
        }

        $types = [];
        $thumb = '';
        foreach ($atts as $a) {
            $t = strtolower(trim((string)($a['type'] ?? 'file')));
            $types[] = $t;
            if ($thumb === '' && !empty($a['thumb_path'])) {
                $thumb = (string)$a['thumb_path'];
            } elseif ($thumb === '' && $t === 'image' && !empty($a['file_path'])) {
                $thumb = (string)$a['file_path'];
            } elseif ($thumb === '' && !empty($a['file_path']) && preg_match('~\.(jpe?g|png|gif|webp)$~i', (string)$a['file_path'])) {
                $thumb = (string)$a['file_path'];
            }
        }
        $types = array_values(array_unique($types));
        $postTypeLabel = 'Text';
        if (in_array('video', $types, true)) {
            $postTypeLabel = 'Video';
        } elseif (in_array('image', $types, true)) {
            $postTypeLabel = 'Image';
        } elseif (preg_match('~https?://~i', (string)($r['body'] ?? $r['description'] ?? ''))) {
            $postTypeLabel = 'Link';
        }

        $text = trim((string)($r['body'] ?? ''));
        if ($text === '') {
            $text = trim((string)($r['description'] ?? ''));
        }
        if ($text === '') {
            $text = trim((string)($r['title'] ?? ''));
        }

        $sum = msb_mod_user_activity_summary($dbh, $uid);
        $beh = msb_mod_behavior_indicators($sum);
        $saved = msb_mod_status_get($dbh, $uid);
        $tier = (string)(($saved['status'] ?? '') !== '' ? $saved['status'] : ($beh['tier'] ?? 'normal'));

        $pending = (int)($r['pending_count'] ?? 0);
        $modStatus = strtolower(trim((string)($r['mod_status'] ?? '')));
        if ($pending > 0) {
            $rowStatus = 'under_review';
        } elseif ($modStatus === 'review') {
            $rowStatus = 'review';
        } elseif ($modStatus === 'high_risk') {
            $rowStatus = 'high_risk';
        } else {
            $rowStatus = 'normal';
        }

        $createdAt = (string)($r['created_at'] ?? '');
        $updatedAt = (string)($r['updated_at'] ?? '');
        $wasEdited = false;
        if ($createdAt !== '' && $updatedAt !== '') {
            $cTs = strtotime($createdAt);
            $uTs = strtotime($updatedAt);
            $wasEdited = $cTs && $uTs && ($uTs - $cTs) > 120;
        }

        return [
            'id' => $postId,
            'user_id' => $uid,
            'username' => (string)($r['username'] ?? ''),
            'name' => (string)($r['name'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'friend_code' => (string)($r['friend_code'] ?? ''),
            'title' => (string)($r['title'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'body' => (string)($r['body'] ?? ''),
            'post_type' => $postTypeLabel,
            'text_preview' => $text,
            'thumb' => $thumb,
            'attachments' => $atts,
            'visibility' => (string)($r['visibility'] ?? 'public'),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'views_count' => (int)($r['views_count'] ?? 0),
            'report_count' => (int)($r['report_count'] ?? 0),
            'pending_count' => $pending,
            'activity_7d' => [
                'posts_7d' => (int)($sum['posts_7d'] ?? 0),
                'likes_7d' => (int)($sum['likes_given_7d'] ?? 0),
                'comments_7d' => (int)($sum['comments_given_7d'] ?? 0),
                'shares_7d' => (int)($sum['shares_given_7d'] ?? 0),
            ],
            'risk' => $tier,
            'status' => $rowStatus,
            'mod_status' => $modStatus,
            'was_edited' => $wasEdited,
            'is_deleted' => (int)($r['is_deleted'] ?? 0) === 1,
            'activity_summary' => $sum,
            'behavior' => $beh,
            'saved_mod' => $saved,
        ];
    }
}

if (!function_exists('msb_mod_activity_overview_bundle')) {
    /**
     * Full overview payload for user_activity.php — same enrichment as user_activity_table.php.
     *
     * @return array<string,mixed>
     */
    function msb_mod_activity_overview_bundle(PDO $dbh, int $userId, int $focusPostId = 0): array
    {
        $activity = msb_mod_user_activity_summary($dbh, $userId);
        $behavior = msb_mod_behavior_indicators($activity);
        $savedMod = msb_mod_status_get($dbh, $userId);

        $bundle = [
            'posts' => [],
            'focus' => null,
            'post_index' => 0,
            'post_total' => 0,
            'prev' => null,
            'next' => null,
            'reports_on_post' => [],
            'timeline' => [],
            'activity' => $activity,
            'behavior' => $behavior,
            'saved_mod' => $savedMod,
        ];

        // Exact clicked post first (source of truth for left panel + risk/status/reports).
        $focusRow = null;
        if ($focusPostId > 0) {
            $focusRow = msb_mod_activity_row_for_post($dbh, $focusPostId);
            if ($focusRow && (int)($focusRow['user_id'] ?? 0) !== $userId) {
                // Post belongs to another user — trust the post's owner for accuracy.
                $userId = (int)$focusRow['user_id'];
                $bundle['activity'] = $focusRow['activity_summary'] ?? msb_mod_user_activity_summary($dbh, $userId);
                $bundle['behavior'] = $focusRow['behavior'] ?? msb_mod_behavior_indicators($bundle['activity']);
                $bundle['saved_mod'] = $focusRow['saved_mod'] ?? msb_mod_status_get($dbh, $userId);
            }
        }

        // Same list the activity table would show for this user (for prev/next).
        $table = msb_mod_activity_table_rows(
            $dbh,
            'all',
            '',
            'all',
            'all',
            'all',
            'all',
            '',
            '',
            1,
            100,
            $userId
        );
        $posts = $table['rows'] ?? [];

        $idx = 0;
        $found = false;
        if ($focusPostId > 0) {
            foreach ($posts as $i => $p) {
                if ((int)($p['id'] ?? 0) === $focusPostId) {
                    $idx = $i;
                    $found = true;
                    // Prefer exact row enrichment (includes attachments / pending).
                    if ($focusRow) {
                        $posts[$i] = array_merge($p, $focusRow);
                    }
                    break;
                }
            }
            if (!$found && $focusRow) {
                array_unshift($posts, $focusRow);
                $idx = 0;
                $found = true;
            }
        }

        if (!$found && $posts && $focusPostId <= 0) {
            $idx = 0;
            $focusPostId = (int)($posts[0]['id'] ?? 0);
            $focusRow = msb_mod_activity_row_for_post($dbh, $focusPostId) ?: $posts[0];
            $posts[0] = array_merge($posts[0], $focusRow);
        } elseif ($found && $focusRow) {
            // keep merged
        } elseif (!$found && $focusRow) {
            $posts = [$focusRow];
            $idx = 0;
        }

        $bundle['posts'] = $posts;
        $bundle['post_total'] = count($posts);
        $bundle['post_index'] = $idx;
        $bundle['focus'] = $posts[$idx] ?? $focusRow;
        $bundle['prev'] = $idx > 0 ? $posts[$idx - 1] : null;
        $bundle['next'] = ($idx + 1) < count($posts) ? $posts[$idx + 1] : null;

        $focusId = (int)($bundle['focus']['id'] ?? $focusPostId);
        $bundle['reports_on_post'] = $focusId > 0 ? msb_mod_reports_for_post($dbh, $focusId, 40) : [];
        // Keep report_count on focus in sync with actual report rows.
        if ($bundle['focus'] !== null) {
            $bundle['focus']['report_count'] = max(
                (int)($bundle['focus']['report_count'] ?? 0),
                count($bundle['reports_on_post'])
            );
            $pending = 0;
            foreach ($bundle['reports_on_post'] as $rep) {
                if (strtolower((string)($rep['status'] ?? '')) === 'pending') {
                    $pending++;
                }
            }
            $bundle['focus']['pending_count'] = max((int)($bundle['focus']['pending_count'] ?? 0), $pending);
        }
        $bundle['timeline'] = msb_mod_user_timeline($dbh, $userId, 12);
        $bundle['resolved_user_id'] = $userId;

        return $bundle;
    }
}

