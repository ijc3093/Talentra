<?php
declare(strict_types=1);

/**
 * Admin-side fallbacks for reports dashboard helpers.
 * Used when production public_user/includes/msb_reports.php is older.
 */

if (!function_exists('msb_reports_admin_stats')) {
    /**
     * @return array<string,array{value:int,delta_pct:int,dir:string}>
     */
    function msb_reports_admin_stats(PDO $dbh): array
    {
        if (function_exists('msb_reports_ensure_schema')) {
            msb_reports_ensure_schema($dbh);
        }
        $one = static function (PDO $dbh, string $statusFilter): array {
            $whereTotal = $statusFilter === '' ? '1=1' : ("status = '" . str_replace("'", '', $statusFilter) . "'");
            try {
                $now = (int)$dbh->query("SELECT COUNT(*) FROM public_user_reports WHERE {$whereTotal}")->fetchColumn();
                $today = (int)$dbh->query("SELECT COUNT(*) FROM public_user_reports WHERE {$whereTotal} AND created_at >= CURDATE()")->fetchColumn();
                $yesterday = (int)$dbh->query("SELECT COUNT(*) FROM public_user_reports WHERE {$whereTotal} AND created_at >= (CURDATE() - INTERVAL 1 DAY) AND created_at < CURDATE()")->fetchColumn();
            } catch (Throwable $e) {
                return ['value' => 0, 'delta_pct' => 0, 'dir' => 'flat'];
            }
            if ($yesterday <= 0) {
                $pct = $today > 0 ? 100 : 0;
            } else {
                $pct = (int)round((($today - $yesterday) / $yesterday) * 100);
            }
            $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
            return ['value' => $now, 'delta_pct' => abs($pct), 'dir' => $dir];
        };

        return [
            'all' => $one($dbh, ''),
            'pending' => $one($dbh, 'pending'),
            'reviewed' => $one($dbh, 'reviewed'),
            'resolved' => $one($dbh, 'resolved'),
            'dismissed' => $one($dbh, 'dismissed'),
        ];
    }
}

if (!function_exists('msb_reports_priority_for')) {
    function msb_reports_priority_for(string $reason, string $riskTier = 'normal'): string
    {
        $reason = strtolower(trim($reason));
        $riskTier = strtolower(trim($riskTier));
        if ($riskTier === 'high_risk' || in_array($reason, ['harassment', 'violence', 'hate', 'nudity'], true)) {
            return 'high';
        }
        if ($riskTier === 'review' || in_array($reason, ['spam', 'scam', 'fake_product'], true)) {
            return 'medium';
        }
        return 'low';
    }
}

if (!function_exists('msb_reports_status_label')) {
    function msb_reports_status_label(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'pending') {
            return 'Pending Review';
        }
        if ($status === 'reviewed') {
            return 'In Progress';
        }
        if ($status === 'resolved') {
            return 'Resolved';
        }
        if ($status === 'dismissed') {
            return 'Dismissed';
        }
        return ucfirst($status !== '' ? $status : 'Unknown');
    }
}

if (!function_exists('msb_reports_audience_kind')) {
    /**
     * Classify a report into Personal / Publisher / Commerce for admin tabs.
     * Uses the reported target (user / org / product), same rules as userlist.
     *
     * @param array<string,mixed> $row
     * @return 'personal'|'publisher'|'commerce'
     */
    function msb_reports_audience_kind(array $row): string
    {
        $tt = strtolower(trim((string)($row['target_type'] ?? 'other')));
        if ($tt === 'org' || $tt === 'product') {
            return 'commerce';
        }

        $accountKind = strtolower(trim((string)($row['target_account_kind'] ?? 'personal')));
        $category = strtolower(trim((string)($row['target_publisher_category'] ?? '')));
        $friendCode = strtoupper(trim((string)($row['target_code'] ?? '')));

        if ($accountKind === 'commerce' || $category === 'commerce') {
            return 'commerce';
        }
        if ($accountKind === 'publisher' || strpos($friendCode, 'PUB-') === 0) {
            return 'publisher';
        }
        return 'personal';
    }
}

if (!function_exists('msb_reports_stats_from_rows')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array{value:int,delta_pct:int,dir:string}>
     */
    function msb_reports_stats_from_rows(array $rows): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day') ?: time());
        $bucket = static function (array $rows, string $status) use ($today, $yesterday): array {
            $now = 0;
            $tCount = 0;
            $yCount = 0;
            foreach ($rows as $r) {
                $st = strtolower(trim((string)($r['status'] ?? '')));
                if ($status !== '' && $st !== $status) {
                    continue;
                }
                $now++;
                $day = substr((string)($r['created_at'] ?? ''), 0, 10);
                if ($day === $today) {
                    $tCount++;
                } elseif ($day === $yesterday) {
                    $yCount++;
                }
            }
            if ($yCount <= 0) {
                $pct = $tCount > 0 ? 100 : 0;
            } else {
                $pct = (int)round((($tCount - $yCount) / $yCount) * 100);
            }
            $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
            return ['value' => $now, 'delta_pct' => abs($pct), 'dir' => $dir];
        };

        return [
            'all' => $bucket($rows, ''),
            'pending' => $bucket($rows, 'pending'),
            'reviewed' => $bucket($rows, 'reviewed'),
            'resolved' => $bucket($rows, 'resolved'),
            'dismissed' => $bucket($rows, 'dismissed'),
        ];
    }
}
