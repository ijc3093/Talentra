<?php
declare(strict_types=1);

/**
 * Helpers for Settings → Notifications (list, classify, mutate).
 */

if (!function_exists('admin_notif_table_exists')) {
    function admin_notif_table_exists(PDO $dbh, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
            $cache[$table] = $st && $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('admin_notif_receiver_keys')) {
    /**
     * @return list<string>
     */
    function admin_notif_receiver_keys(): array
    {
        $allowed = ['Admin', 'Manager', 'Gospel', 'Staff'];
        $keys = function_exists('myNotificationReceiverKeys')
            ? myNotificationReceiverKeys()
            : [];
        return array_values(array_intersect((array)$keys, $allowed));
    }
}

if (!function_exists('admin_notif_strip_tags_label')) {
    function admin_notif_strip_tags_label(string $notitype): string
    {
        $t = trim($notitype);
        // Strip bracket tags like [r:fd], [sys], etc.
        $t = preg_replace('/\[[^\]]*\]/', '', $t) ?? $t;
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return trim($t);
    }
}

if (!function_exists('admin_notif_classify_type')) {
    /**
     * @return 'system'|'user_report'|'security'|'moderation'|'engagement'|'updates'
     */
    function admin_notif_classify_type(string $notitype): string
    {
        $t = mb_strtolower($notitype);
        if (preg_match('/\b(report|flag|abuse|complaint)\b/', $t)) {
            return 'user_report';
        }
        if (preg_match('/\[account\]|deactivat|delete requested|exported data|reset settings|switched account|removed access|linked another|no longer using/', $t)) {
            return 'security';
        }
        if (preg_match('/\b(login|password|2fa|lockout|failed|security|auth)\b/', $t)) {
            return 'security';
        }
        if (preg_match('/\b(publish|moderat|review|approve|reject)\b/', $t)) {
            return 'moderation';
        }
        if (preg_match('/\b(mention|tag|like|comment|follow|friend)\b/', $t)) {
            return 'engagement';
        }
        if (preg_match('/\b(update|version|release|changelog|upgrade)\b/', $t)) {
            return 'updates';
        }
        if (preg_match('/\b(create\s*account|publisher|signup|register|welcome|system)\b/', $t)) {
            return 'system';
        }
        return 'system';
    }
}

if (!function_exists('admin_notif_classify_priority')) {
    /**
     * @return 'high'|'medium'|'low'
     */
    function admin_notif_classify_priority(string $notitype, string $type = ''): string
    {
        $t = mb_strtolower($notitype);
        $type = $type !== '' ? $type : admin_notif_classify_type($notitype);
        if ($type === 'security' || preg_match('/\b(failed|breach|lockout|urgent|critical)\b/', $t)) {
            return 'high';
        }
        if ($type === 'user_report' || preg_match('/\b(report|flag)\b/', $t)) {
            return 'medium';
        }
        if (preg_match('/\b(create\s*account|welcome|signup)\b/', $t) || $type === 'engagement') {
            return 'low';
        }
        if ($type === 'moderation') {
            return 'medium';
        }
        return 'low';
    }
}

if (!function_exists('admin_notif_title')) {
    function admin_notif_title(string $notitype): string
    {
        $t = admin_notif_strip_tags_label($notitype);
        if ($t === '') {
            return 'Notification';
        }
        if (mb_strlen($t) > 72) {
            return mb_substr($t, 0, 69) . '…';
        }
        return $t;
    }
}

if (!function_exists('admin_notif_body')) {
    function admin_notif_body(string $notiuser, string $notitype): string
    {
        $user = trim($notiuser);
        $raw = admin_notif_strip_tags_label($notitype);
        if (preg_match('/\bfrom\s+([A-Za-z0-9_@.+\- ]{2,60})/i', $raw, $m)) {
            return 'From ' . trim($m[1]);
        }
        if ($user !== '') {
            return 'From ' . $user;
        }
        return 'System notification';
    }
}

if (!function_exists('admin_notif_type_label')) {
    function admin_notif_type_label(string $type): string
    {
        $map = [
            'system' => 'System',
            'user_report' => 'User Report',
            'security' => 'Security',
            'moderation' => 'Moderation',
            'engagement' => 'Engagement',
            'updates' => 'Updates',
        ];
        return $map[$type] ?? 'System';
    }
}

if (!function_exists('admin_notif_icon_class')) {
    function admin_notif_icon_class(string $type): string
    {
        $map = [
            'system' => 'fa-cog',
            'user_report' => 'fa-flag',
            'security' => 'fa-shield',
            'moderation' => 'fa-gavel',
            'engagement' => 'fa-heart',
            'updates' => 'fa-refresh',
        ];
        return $map[$type] ?? 'fa-bell';
    }
}

if (!function_exists('admin_notif_normalize_row')) {
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function admin_notif_normalize_row(array $row, bool $virtual = false): array
    {
        $notitype = (string)($row['notitype'] ?? '');
        $notiuser = (string)($row['notiuser'] ?? '');
        $type = (string)($row['type'] ?? '');
        if ($type === '') {
            $type = admin_notif_classify_type($notitype);
        }
        $priority = (string)($row['priority'] ?? '');
        if ($priority === '') {
            $priority = admin_notif_classify_priority($notitype, $type);
        }
        $isRead = (int)($row['is_read'] ?? 0) === 1;
        $relatedHref = (string)($row['related_href'] ?? '');
        if ($relatedHref === '' && preg_match('/(?:u|user_id)=(\d+)/', $notitype, $m)) {
            $relatedHref = 'user_form.php?user_id=' . (int)$m[1];
        }
        $body = (string)($row['body'] ?? admin_notif_body($notiuser, $notitype));
        if (stripos($notitype, '[account]') !== false || stripos($notitype, 'no longer using') !== false) {
            if (stripos($notitype, 'Deactivated') !== false) {
                $body = 'User stopped using this account. Leave it paused unless they ask to return.';
            } elseif (stripos($notitype, 'Delete requested') !== false) {
                $body = 'User wants the account gone. Follow your delete policy; deactivate if still live.';
            } elseif (stripos($notitype, 'Exported data') !== false) {
                $body = 'They downloaded their data. Watch for deactivate or delete next.';
            } elseif (stripos($notitype, 'Reset settings') !== false) {
                $body = 'Gear was reset. Help them set privacy/theme again if they ask.';
            } elseif (stripos($notitype, 'Removed access') !== false) {
                $body = 'Other devices were signed out. They can sign in again on a trusted device.';
            } elseif (stripos($notitype, 'Switched account') !== false) {
                $body = 'They switched to a linked account. Still on the platform — not churn.';
            } elseif (stripos($notitype, 'Linked another') !== false) {
                $body = 'They added another account. Keep both rows.';
            }
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'virtual' => $virtual || !empty($row['virtual']),
            'virtual_key' => (string)($row['virtual_key'] ?? ''),
            'notiuser' => $notiuser,
            'notireceiver' => (string)($row['notireceiver'] ?? ''),
            'notitype' => $notitype,
            'created_at' => (string)($row['created_at'] ?? ''),
            'is_read' => $isRead ? 1 : 0,
            'read_at' => $row['read_at'] ?? null,
            'type' => $type,
            'type_label' => admin_notif_type_label($type),
            'priority' => $priority,
            'title' => (string)($row['title'] ?? admin_notif_title($notitype)),
            'body' => $body,
            'related' => (string)($row['related'] ?? ($notiuser !== '' ? $notiuser : '—')),
            'related_href' => $relatedHref,
            'icon' => admin_notif_icon_class($type),
        ];
    }
}

if (!function_exists('admin_notif_pending_reports_virtual')) {
    /**
     * Optional virtual rows from pending public_user_reports.
     *
     * @return list<array<string,mixed>>
     */
    function admin_notif_pending_reports_virtual(PDO $dbh, int $limit = 5): array
    {
        if ($limit < 1 || !admin_notif_table_exists($dbh, 'public_user_reports')) {
            return [];
        }
        try {
            $st = $dbh->prepare("
                SELECT id, reporter_label, reason, details, target_type, target_id, created_at
                FROM public_user_reports
                WHERE status = 'pending'
                ORDER BY created_at DESC
                LIMIT " . (int)$limit
            );
            $st->execute();
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $who = trim((string)($r['reporter_label'] ?? ''));
                if ($who === '') {
                    $who = 'User #' . (int)($r['id'] ?? 0);
                }
                $reason = trim((string)($r['reason'] ?? 'other'));
                $target = trim((string)($r['target_type'] ?? 'other'));
                $title = 'Pending user report: ' . $reason;
                $out[] = admin_notif_normalize_row([
                    'id' => 0,
                    'virtual' => true,
                    'virtual_key' => 'report:' . (int)$r['id'],
                    'notiuser' => $who,
                    'notireceiver' => 'Admin',
                    'notitype' => 'User report / flag — ' . $reason . ' on ' . $target,
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'is_read' => 0,
                    'type' => 'user_report',
                    'priority' => 'medium',
                    'title' => $title,
                    'body' => 'From ' . $who,
                    'related' => $who,
                    'related_href' => 'report_detail.php?id=' . (int)$r['id'],
                ], true);
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('admin_notif_fetch_all')) {
    /**
     * Load notifications for allowed receivers, classify, optionally merge virtual reports.
     *
     * @param list<string> $receiverKeys
     * @return list<array<string,mixed>>
     */
    function admin_notif_fetch_all(PDO $dbh, array $receiverKeys, bool $includeVirtualReports = true): array
    {
        if ($receiverKeys === [] || !admin_notif_table_exists($dbh, 'notification')) {
            return $includeVirtualReports ? admin_notif_pending_reports_virtual($dbh, 5) : [];
        }
        $ph = implode(',', array_fill(0, count($receiverKeys), '?'));
        try {
            $st = $dbh->prepare("
                SELECT id, notiuser, notireceiver, notitype, created_at, is_read, read_at
                FROM notification
                WHERE notireceiver IN ($ph)
                ORDER BY created_at DESC
                LIMIT 500
            ");
            $st->execute(array_values($receiverKeys));
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = admin_notif_normalize_row($r);
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        if ($includeVirtualReports) {
            $virtual = admin_notif_pending_reports_virtual($dbh, 5);
            if ($virtual !== []) {
                $rows = array_merge($virtual, $rows);
                usort($rows, static function (array $a, array $b): int {
                    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
                });
            }
        }
        return $rows;
    }
}

if (!function_exists('admin_notif_filter_rows')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    function admin_notif_filter_rows(array $rows, array $filters): array
    {
        $tab = (string)($filters['ntab'] ?? 'all');
        $q = mb_strtolower(trim((string)($filters['nq'] ?? '')));
        $type = (string)($filters['ntype'] ?? '');
        $priority = (string)($filters['npriority'] ?? '');
        $status = (string)($filters['nstatus'] ?? 'all');
        $from = trim((string)($filters['nfrom'] ?? ''));
        $to = trim((string)($filters['nto'] ?? ''));
        $fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : false;
        $toTs = $to !== '' ? strtotime($to . ' 23:59:59') : false;

        $out = [];
        foreach ($rows as $row) {
            $rowType = (string)($row['type'] ?? 'system');
            $isRead = (int)($row['is_read'] ?? 0) === 1;

            if ($tab === 'unread' && $isRead) {
                continue;
            }
            if ($tab === 'system' && $rowType !== 'system') {
                continue;
            }
            if ($tab === 'user_report' && $rowType !== 'user_report') {
                continue;
            }
            if ($tab === 'security' && $rowType !== 'security') {
                continue;
            }
            if ($tab === 'updates' && $rowType !== 'updates') {
                continue;
            }

            if ($type !== '' && $type !== 'all' && $rowType !== $type) {
                continue;
            }
            if ($priority !== '' && $priority !== 'all' && (string)($row['priority'] ?? '') !== $priority) {
                continue;
            }
            if ($status === 'unread' && $isRead) {
                continue;
            }
            if ($status === 'read' && !$isRead) {
                continue;
            }

            if ($fromTs !== false || $toTs !== false) {
                $created = strtotime((string)($row['created_at'] ?? '')) ?: 0;
                if ($fromTs !== false && $created < $fromTs) {
                    continue;
                }
                if ($toTs !== false && $created > $toTs) {
                    continue;
                }
            }

            if ($q !== '') {
                $hay = mb_strtolower(
                    (string)($row['title'] ?? '') . ' ' .
                    (string)($row['body'] ?? '') . ' ' .
                    (string)($row['notitype'] ?? '') . ' ' .
                    (string)($row['related'] ?? '') . ' ' .
                    (string)($row['notiuser'] ?? '')
                );
                if (mb_strpos($hay, $q) === false) {
                    continue;
                }
            }

            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('admin_notif_overview')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return array{total:int,unread:int,high:int,medium:int,low:int}
     */
    function admin_notif_overview(array $rows): array
    {
        $o = ['total' => 0, 'unread' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($rows as $row) {
            $o['total']++;
            if ((int)($row['is_read'] ?? 0) === 0) {
                $o['unread']++;
            }
            $p = (string)($row['priority'] ?? 'low');
            if (isset($o[$p])) {
                $o[$p]++;
            }
        }
        return $o;
    }
}

if (!function_exists('admin_notif_by_type')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{key:string,label:string,count:int,pct:float}>
     */
    function admin_notif_by_type(array $rows): array
    {
        $order = ['system', 'user_report', 'security', 'moderation', 'engagement'];
        $counts = array_fill_keys($order, 0);
        $total = 0;
        foreach ($rows as $row) {
            $t = (string)($row['type'] ?? 'system');
            if ($t === 'updates') {
                $t = 'system';
            }
            if (!isset($counts[$t])) {
                $t = 'system';
            }
            $counts[$t]++;
            $total++;
        }
        $out = [];
        foreach ($order as $key) {
            $c = (int)$counts[$key];
            $out[] = [
                'key' => $key,
                'label' => admin_notif_type_label($key),
                'count' => $c,
                'pct' => $total > 0 ? round(($c / $total) * 100, 1) : 0.0,
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_notif_format_dt')) {
    function admin_notif_format_dt(?string $dt): string
    {
        if ($dt === null || trim($dt) === '' || $dt === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($dt);
        return $ts ? date('M j, Y g:i A', $ts) : $dt;
    }
}

if (!function_exists('admin_notif_mark_all_read')) {
    /**
     * @param list<string> $receiverKeys
     */
    function admin_notif_mark_all_read(PDO $dbh, array $receiverKeys): int
    {
        if ($receiverKeys === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($receiverKeys), '?'));
        $st = $dbh->prepare("
            UPDATE notification
            SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE notireceiver IN ($ph) AND is_read = 0
        ");
        $st->execute(array_values($receiverKeys));
        return $st->rowCount();
    }
}

if (!function_exists('admin_notif_mark_read')) {
    /**
     * @param list<string> $receiverKeys
     */
    function admin_notif_mark_read(PDO $dbh, int $id, array $receiverKeys): bool
    {
        if ($id < 1 || $receiverKeys === []) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($receiverKeys), '?'));
        $st = $dbh->prepare("
            UPDATE notification
            SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE id = ? AND notireceiver IN ($ph)
        ");
        $st->execute(array_merge([$id], array_values($receiverKeys)));
        return $st->rowCount() > 0;
    }
}

if (!function_exists('admin_notif_delete')) {
    /**
     * @param list<string> $receiverKeys
     */
    function admin_notif_delete(PDO $dbh, int $id, array $receiverKeys): bool
    {
        if ($id < 1 || $receiverKeys === []) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($receiverKeys), '?'));
        $st = $dbh->prepare("DELETE FROM notification WHERE id = ? AND notireceiver IN ($ph)");
        $st->execute(array_merge([$id], array_values($receiverKeys)));
        return $st->rowCount() > 0;
    }
}

if (!function_exists('admin_notif_insert_test')) {
    function admin_notif_insert_test(PDO $dbh, string $fromUser = 'System'): bool
    {
        if (!admin_notif_table_exists($dbh, 'notification')) {
            return false;
        }
        $st = $dbh->prepare("
            INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
            VALUES (?, 'Admin', ?, 0)
        ");
        return $st->execute([
            $fromUser !== '' ? $fromUser : 'System',
            'Test notification [sys] — Settings check ' . date('Y-m-d H:i'),
        ]);
    }
}
