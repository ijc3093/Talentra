<?php
declare(strict_types=1);

if (!function_exists('org_feed_pulse_stats')) {
    /**
     * 7-day feed activity counts for header stat pills.
     *
     * @return array{posts_7d:int,comments_7d:int,acks_7d:int}
     */
    function org_feed_pulse_stats(PDO $dbh, int $orgId): array
    {
        $pulse = ['posts_7d' => 0, 'comments_7d' => 0, 'acks_7d' => 0];
        if ($orgId <= 0) {
            return $pulse;
        }

        try {
            $stP = $dbh->prepare("
                SELECT
                  (SELECT COUNT(*) FROM org_posts p
                   WHERE p.org_id = :org_id AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  ) AS posts_7d,

                  (SELECT COUNT(*) FROM org_post_comments c
                   JOIN org_posts p2 ON p2.id = c.post_id
                   WHERE p2.org_id = :org_id AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  ) AS comments_7d,

                  (SELECT COUNT(*) FROM org_post_acknowledgements a
                   JOIN org_posts p3 ON p3.id = a.post_id
                   WHERE p3.org_id = :org_id AND a.acknowledged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  ) AS acks_7d
            ");
            $stP->execute([':org_id' => $orgId]);
            $rowPulse = $stP->fetch(PDO::FETCH_ASSOC);
            if (is_array($rowPulse)) {
                $pulse['posts_7d'] = (int)($rowPulse['posts_7d'] ?? 0);
                $pulse['comments_7d'] = (int)($rowPulse['comments_7d'] ?? 0);
                $pulse['acks_7d'] = (int)($rowPulse['acks_7d'] ?? 0);
            }
        } catch (Throwable $e) {
            // keep defaults
        }

        return $pulse;
    }
}
