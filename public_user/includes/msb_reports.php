<?php
declare(strict_types=1);

/**
 * Platform abuse / content reports → admin moderation queue.
 * Table: public_user_reports (also counted by security_tools.php).
 */

function msb_reports_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS `public_user_reports` (
              `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `reporter_id` INT(11) NOT NULL DEFAULT 0,
              `reporter_kind` ENUM('user','manager','staff') NOT NULL DEFAULT 'user',
              `reporter_org_id` BIGINT(20) NULL DEFAULT NULL,
              `reporter_label` VARCHAR(160) NULL DEFAULT NULL,
              `target_type` ENUM('post','user','message','product','org','other') NOT NULL DEFAULT 'other',
              `target_id` BIGINT(20) NOT NULL DEFAULT 0,
              `target_user_id` BIGINT(20) NULL DEFAULT NULL,
              `target_org_id` BIGINT(20) NULL DEFAULT NULL,
              `reason` VARCHAR(40) NOT NULL DEFAULT 'other',
              `details` TEXT NULL,
              `status` ENUM('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
              `admin_note` TEXT NULL,
              `reviewed_by_admin_id` INT(11) NULL DEFAULT NULL,
              `reviewed_at` DATETIME NULL DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_reports_status_created` (`status`, `created_at`),
              KEY `idx_reports_reporter` (`reporter_id`, `created_at`),
              KEY `idx_reports_target` (`target_type`, `target_id`),
              KEY `idx_reports_target_user` (`target_user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return list<string> */
function msb_reports_reasons(): array
{
    return ['spam', 'harassment', 'hate', 'violence', 'nudity', 'scam', 'fake_product', 'copyright', 'other'];
}

function msb_reports_normalize_reason(string $reason): string
{
    $reason = strtolower(trim($reason));
    $reason = str_replace([' ', '-'], '_', $reason);
    if (!in_array($reason, msb_reports_reasons(), true)) {
        return 'other';
    }
    return $reason;
}

function msb_reports_normalize_target_type(string $type): string
{
    $type = strtolower(trim($type));
    $allowed = ['post', 'user', 'message', 'product', 'org', 'other'];
    return in_array($type, $allowed, true) ? $type : 'other';
}

/**
 * Enrich target_user_id / target_org_id from DB when possible.
 *
 * @return array{target_user_id:?int,target_org_id:?int,ok:bool,error?:string}
 */
function msb_reports_resolve_target(PDO $dbh, string $targetType, int $targetId): array
{
    $out = ['target_user_id' => null, 'target_org_id' => null, 'ok' => true];
    if ($targetId <= 0 && $targetType !== 'other') {
        return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'Missing target.'];
    }

    try {
        if ($targetType === 'post') {
            $st = $dbh->prepare('SELECT user_id FROM public_posts WHERE id = :id LIMIT 1');
            $st->execute([':id' => $targetId]);
            $uid = (int)($st->fetchColumn() ?: 0);
            if ($uid <= 0) {
                return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'Post not found.'];
            }
            $out['target_user_id'] = $uid;
        } elseif ($targetType === 'user') {
            $st = $dbh->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $targetId]);
            if (!(int)$st->fetchColumn()) {
                return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'User not found.'];
            }
            $out['target_user_id'] = $targetId;
        } elseif ($targetType === 'product') {
            $st = $dbh->prepare('SELECT org_id FROM org_products WHERE id = :id LIMIT 1');
            $st->execute([':id' => $targetId]);
            $orgId = (int)($st->fetchColumn() ?: 0);
            if ($orgId <= 0) {
                return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'Product not found.'];
            }
            $out['target_org_id'] = $orgId;
            try {
                $st2 = $dbh->prepare('SELECT publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
                $st2->execute([':id' => $orgId]);
                $pub = (int)($st2->fetchColumn() ?: 0);
                if ($pub > 0) {
                    $out['target_user_id'] = $pub;
                }
            } catch (Throwable $e) {
            }
        } elseif ($targetType === 'org') {
            $st = $dbh->prepare('SELECT id, publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
            $st->execute([':id' => $targetId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'Organization not found.'];
            }
            $out['target_org_id'] = $targetId;
            $pub = (int)($row['publisher_user_id'] ?? 0);
            if ($pub > 0) {
                $out['target_user_id'] = $pub;
            }
        } elseif ($targetType === 'message') {
            // target_id = peer user id (DM peer being reported)
            $st = $dbh->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $targetId]);
            if (!(int)$st->fetchColumn()) {
                return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'User not found.'];
            }
            $out['target_user_id'] = $targetId;
        }
    } catch (Throwable $e) {
        return ['target_user_id' => null, 'target_org_id' => null, 'ok' => false, 'error' => 'Could not resolve target.'];
    }

    return $out;
}

/**
 * @return array{ok:bool,id?:int,error?:string,duplicate?:bool}
 */
function msb_reports_create(
    PDO $dbh,
    int $reporterId,
    string $reporterKind,
    string $targetType,
    int $targetId,
    string $reason,
    string $details = '',
    int $reporterOrgId = 0,
    string $reporterLabel = ''
): array {
    msb_reports_ensure_schema($dbh);

    $reporterKind = strtolower(trim($reporterKind));
    if (!in_array($reporterKind, ['user', 'manager', 'staff'], true)) {
        $reporterKind = 'user';
    }
    $targetType = msb_reports_normalize_target_type($targetType);
    $reason = msb_reports_normalize_reason($reason);
    $details = trim($details);
    if (mb_strlen($details) > 2000) {
        $details = mb_substr($details, 0, 2000);
    }
    $reporterLabel = trim($reporterLabel);
    if (mb_strlen($reporterLabel) > 160) {
        $reporterLabel = mb_substr($reporterLabel, 0, 160);
    }

    if ($reporterId <= 0 && $reporterKind === 'user') {
        return ['ok' => false, 'error' => 'Please sign in to report.'];
    }
    if ($targetType !== 'other' && $targetId <= 0) {
        return ['ok' => false, 'error' => 'Missing target.'];
    }

    // Don't allow reporting yourself as a user target.
    if (in_array($targetType, ['user', 'message'], true) && $reporterId > 0 && $targetId === $reporterId) {
        return ['ok' => false, 'error' => 'You cannot report yourself.'];
    }

    $resolved = msb_reports_resolve_target($dbh, $targetType, $targetId);
    if (empty($resolved['ok'])) {
        return ['ok' => false, 'error' => (string)($resolved['error'] ?? 'Invalid target.')];
    }

    if ($targetType === 'post' && $reporterId > 0 && (int)($resolved['target_user_id'] ?? 0) === $reporterId) {
        return ['ok' => false, 'error' => 'You cannot report your own post.'];
    }

    try {
        $dup = $dbh->prepare('
            SELECT id
            FROM public_user_reports
            WHERE reporter_id = :rid
              AND reporter_kind = :rk
              AND target_type = :tt
              AND target_id = :tid
              AND status = \'pending\'
            LIMIT 1
        ');
        $dup->execute([
            ':rid' => $reporterId,
            ':rk' => $reporterKind,
            ':tt' => $targetType,
            ':tid' => $targetId,
        ]);
        $existing = (int)($dup->fetchColumn() ?: 0);
        if ($existing > 0) {
            // Keep the same pending report, but attach new details if the first submit had none.
            if ($details !== '') {
                try {
                    $up = $dbh->prepare("
                        UPDATE public_user_reports
                        SET details = COALESCE(NULLIF(TRIM(details), ''), :d)
                        WHERE id = :id
                        LIMIT 1
                    ");
                    $up->execute([':d' => $details, ':id' => $existing]);
                } catch (Throwable $eUp) {
                }
            }
            // Reports stay on Admin → Reports / user activity — not Admin Inbox.
            return ['ok' => true, 'id' => $existing, 'duplicate' => true];
        }

        $st = $dbh->prepare('
            INSERT INTO public_user_reports
              (reporter_id, reporter_kind, reporter_org_id, reporter_label,
               target_type, target_id, target_user_id, target_org_id,
               reason, details, status, created_at)
            VALUES
              (:rid, :rk, :roid, :rlabel,
               :tt, :tid, :tuid, :toid,
               :reason, :details, \'pending\', NOW())
        ');
        $st->execute([
            ':rid' => $reporterId,
            ':rk' => $reporterKind,
            ':roid' => $reporterOrgId > 0 ? $reporterOrgId : null,
            ':rlabel' => $reporterLabel !== '' ? $reporterLabel : null,
            ':tt' => $targetType,
            ':tid' => $targetId,
            ':tuid' => $resolved['target_user_id'],
            ':toid' => $resolved['target_org_id'],
            ':reason' => $reason,
            ':details' => $details !== '' ? $details : null,
        ]);

        $newId = (int)$dbh->lastInsertId();
        // Content reports belong in Admin → Reports + user Activity — not feedback Inbox.

        return ['ok' => true, 'id' => $newId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save report.'];
    }
}

/**
 * @deprecated Content reports must not write to feedback_admin / feedback.php.
 * Kept as a no-op so older call sites stay safe.
 *
 * @param array<string,mixed> $meta
 */
function msb_reports_notify_admin_inbox(PDO $dbh, array $meta): void
{
    // Intentionally empty: reports → reports.php / user_activity.php only.
    unset($dbh, $meta);
}

/**
 * @return list<array<string,mixed>>
 */
function msb_reports_list_for_admin(PDO $dbh, string $status = 'pending', string $search = '', int $limit = 200): array
{
    msb_reports_ensure_schema($dbh);
    $limit = max(1, min(500, $limit));
    $where = ['1=1'];
    $params = [];

    $status = strtolower(trim($status));
    if (in_array($status, ['pending', 'reviewed', 'resolved', 'dismissed'], true)) {
        $where[] = 'r.status = :status';
        $params[':status'] = $status;
    }

    $search = trim($search);
    if ($search !== '') {
        $where[] = '(r.reporter_label LIKE :q OR r.reason LIKE :q OR r.details LIKE :q OR ru.username LIKE :q OR tu.username LIKE :q OR CAST(r.target_id AS CHAR) LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $sql = '
        SELECT
            r.*,
            ru.username AS reporter_username,
            ru.name AS reporter_name,
            ru.friend_code AS reporter_code,
            ru.email AS reporter_email,
            tu.username AS target_username,
            tu.name AS target_name,
            tu.friend_code AS target_code,
            tu.account_kind AS target_account_kind,
            COALESCE(tu.publisher_category, \'\') AS target_publisher_category,
            o.name AS target_org_name,
            o.org_code AS target_org_code
        FROM public_user_reports r
        LEFT JOIN users ru ON ru.id = r.reporter_id
        LEFT JOIN users tu ON tu.id = r.target_user_id
        LEFT JOIN organizations o ON o.id = r.target_org_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY
            CASE r.status WHEN \'pending\' THEN 0 WHEN \'reviewed\' THEN 1 ELSE 2 END,
            r.id DESC
        LIMIT ' . (int)$limit;

    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Older schemas may lack publisher_category / account_kind on users.
        try {
            $sqlFallback = '
                SELECT
                    r.*,
                    ru.username AS reporter_username,
                    ru.name AS reporter_name,
                    ru.friend_code AS reporter_code,
                    ru.email AS reporter_email,
                    tu.username AS target_username,
                    tu.name AS target_name,
                    tu.friend_code AS target_code,
                    COALESCE(tu.account_kind, \'personal\') AS target_account_kind,
                    \'\' AS target_publisher_category,
                    o.name AS target_org_name,
                    o.org_code AS target_org_code
                FROM public_user_reports r
                LEFT JOIN users ru ON ru.id = r.reporter_id
                LEFT JOIN users tu ON tu.id = r.target_user_id
                LEFT JOIN organizations o ON o.id = r.target_org_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY
                    CASE r.status WHEN \'pending\' THEN 0 WHEN \'reviewed\' THEN 1 ELSE 2 END,
                    r.id DESC
                LIMIT ' . (int)$limit;
            $st = $dbh->prepare($sqlFallback);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function msb_reports_set_status(
    PDO $dbh,
    int $reportId,
    string $status,
    int $adminId,
    string $adminNote = ''
): array {
    msb_reports_ensure_schema($dbh);
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'reviewed', 'resolved', 'dismissed'], true)) {
        return ['ok' => false, 'error' => 'Invalid status.'];
    }
    if ($reportId <= 0) {
        return ['ok' => false, 'error' => 'Invalid report.'];
    }
    $adminNote = trim($adminNote);
    if (mb_strlen($adminNote) > 2000) {
        $adminNote = mb_substr($adminNote, 0, 2000);
    }

    try {
        $st = $dbh->prepare('
            UPDATE public_user_reports
            SET status = :st,
                admin_note = :note,
                reviewed_by_admin_id = :aid,
                reviewed_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([
            ':st' => $status,
            ':note' => $adminNote !== '' ? $adminNote : null,
            ':aid' => $adminId > 0 ? $adminId : null,
            ':id' => $reportId,
        ]);
        return ['ok' => $st->rowCount() > 0 || true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update report.'];
    }
}

/**
 * Full report row for admin detail page.
 *
 * @return array<string,mixed>|null
 */
function msb_reports_get_by_id(PDO $dbh, int $reportId): ?array
{
    msb_reports_ensure_schema($dbh);
    if ($reportId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare('
            SELECT
                r.*,
                ru.username AS reporter_username,
                ru.name AS reporter_name,
                ru.email AS reporter_email,
                ru.friend_code AS reporter_code,
                ru.created_at AS reporter_created_at,
                tu.username AS target_username,
                tu.name AS target_name,
                tu.email AS target_email,
                tu.friend_code AS target_code,
                tu.status AS target_status,
                tu.created_at AS target_created_at,
                o.name AS target_org_name,
                o.org_code AS target_org_code
            FROM public_user_reports r
            LEFT JOIN users ru ON ru.id = r.reporter_id
            LEFT JOIN users tu ON tu.id = r.target_user_id
            LEFT JOIN organizations o ON o.id = r.target_org_id
            WHERE r.id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $reportId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function msb_reports_pending_count(PDO $dbh): int
{
    msb_reports_ensure_schema($dbh);
    try {
        $st = $dbh->query("SELECT COUNT(*) FROM public_user_reports WHERE status = 'pending'");
        return (int)($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

if (!function_exists('msb_reports_admin_stats')) {
/**
 * Dashboard card counts (+ vs yesterday) for admin reports UI.
 *
 * @return array<string,array{value:int,delta_pct:int,dir:string}>
 */
function msb_reports_admin_stats(PDO $dbh): array
{
    msb_reports_ensure_schema($dbh);
    $one = static function (PDO $dbh, string $statusFilter) : array {
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

/**
 * Inbox-style preview line for a content report (what used to show in feedback.php).
 *
 * @param array<string,mixed> $rep
 */
function msb_reports_activity_preview(array $rep): string
{
    $id = (int)($rep['id'] ?? 0);
    $tt = strtoupper(trim((string)($rep['target_type'] ?? 'other')));
    $tid = (int)($rep['target_id'] ?? 0);
    $reason = trim((string)($rep['reason'] ?? 'other'));
    $details = trim((string)($rep['details'] ?? ''));
    $line = '[Report #' . $id . '] ' . $tt . ' #' . $tid . ' Reason: ' . $reason;
    if ($details !== '') {
        $line .= ' Details: ' . $details;
    }
    return $line;
}

/**
 * @return list<array<string,mixed>>
 */
function msb_reports_list_for_reporter(PDO $dbh, int $reporterId, int $limit = 50): array
{
    msb_reports_ensure_schema($dbh);
    if ($reporterId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $st = $dbh->prepare('
            SELECT
              r.id, r.target_type, r.target_id, r.target_user_id, r.reason, r.status, r.details,
              r.created_at, r.reviewed_at, r.admin_note,
              tu.username AS target_username,
              tu.name AS target_name,
              tu.friend_code AS target_code
            FROM public_user_reports r
            LEFT JOIN users tu ON tu.id = r.target_user_id
            WHERE r.reporter_id = :uid
            ORDER BY r.id DESC
            LIMIT ' . (int)$limit
        );
        $st->execute([':uid' => $reporterId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Reports about this user's content / account (they are the target).
 *
 * @return list<array<string,mixed>>
 */
function msb_reports_list_about_user(PDO $dbh, int $targetUserId, int $limit = 50): array
{
    msb_reports_ensure_schema($dbh);
    if ($targetUserId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $st = $dbh->prepare('
            SELECT
              r.id, r.reporter_id, r.reporter_label, r.target_type, r.target_id, r.reason, r.status,
              r.details, r.created_at, r.reviewed_at,
              ru.username AS reporter_username,
              ru.name AS reporter_name
            FROM public_user_reports r
            LEFT JOIN users ru ON ru.id = r.reporter_id
            WHERE r.target_user_id = :uid
            ORDER BY r.id DESC
            LIMIT ' . (int)$limit
        );
        $st->execute([':uid' => $targetUserId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
