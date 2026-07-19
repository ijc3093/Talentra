<?php
declare(strict_types=1);

/**
 * Organization time card: hired employees clock in / out.
 * Entries are keyed by org_members.id (works for staff and managers).
 */

function org_timecard_column_exists(PDO $dbh, string $table, string $col): bool
{
    try {
        $st = $dbh->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function org_timecard_add_column(PDO $dbh, string $table, string $col, string $ddl): void
{
    if (org_timecard_column_exists($dbh, $table, $col)) {
        return;
    }
    try {
        $dbh->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$ddl}");
    } catch (Throwable $e) {
        // ignore
    }
}

/** Valid time card entry types (Step 2-3). */
function org_timecard_entry_types(): array
{
    return [
        'regular' => 'Regular',
        'overtime' => 'Overtime',
        'pto' => 'PTO',
        'sick' => 'Sick',
        'holiday' => 'Holiday',
        'vacation' => 'Vacation',
    ];
}

function org_timecard_entry_type_label(string $type): string
{
    $map = org_timecard_entry_types();
    $type = strtolower(trim($type));
    return $map[$type] ?? 'Regular';
}

/** Leave/absence types are entered as hours rather than clocked. */
function org_timecard_is_leave_type(string $type): bool
{
    return in_array(strtolower(trim($type)), ['pto', 'sick', 'holiday', 'vacation'], true);
}

function org_timecard_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_time_cards (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              org_id BIGINT UNSIGNED NOT NULL,
              org_member_id BIGINT UNSIGNED NOT NULL,
              employee_name VARCHAR(190) NOT NULL DEFAULT '',
              employee_role VARCHAR(40) NOT NULL DEFAULT 'staff',
              entry_type VARCHAR(20) NOT NULL DEFAULT 'regular',
              status VARCHAR(20) NOT NULL DEFAULT 'approved',
              clock_in DATETIME NOT NULL,
              clock_out DATETIME NULL,
              worked_seconds INT NOT NULL DEFAULT 0,
              note VARCHAR(255) NULL,
              approved_at DATETIME NULL,
              approved_by_member_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_org_time_cards_member (org_id, org_member_id),
              KEY idx_org_time_cards_open (org_id, org_member_id, clock_out),
              KEY idx_org_time_cards_status (org_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    // Backfill columns for existing installs.
    org_timecard_add_column($dbh, 'org_time_cards', 'entry_type', "VARCHAR(20) NOT NULL DEFAULT 'regular'");
    org_timecard_add_column($dbh, 'org_time_cards', 'status', "VARCHAR(20) NOT NULL DEFAULT 'approved'");
    org_timecard_add_column($dbh, 'org_time_cards', 'approved_at', "DATETIME NULL");
    org_timecard_add_column($dbh, 'org_time_cards', 'approved_by_member_id', "BIGINT UNSIGNED NULL");
}

/** @return array<string,mixed>|null */
function org_timecard_member(PDO $dbh, int $orgId, int $orgMemberId): ?array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT
              om.id AS org_member_id,
              om.member_type,
              COALESCE(m.fullname, s.fullname, m.username, s.username, 'Employee') AS name
            FROM org_members om
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE om.id = :id AND om.org_id = :org AND om.status = 1
            LIMIT 1
        ");
        $st->execute([':id' => $orgMemberId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array<string,mixed>|null Open (not yet clocked out) entry for a member. */
function org_timecard_open_entry(PDO $dbh, int $orgId, int $orgMemberId): ?array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return null;
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_time_cards
            WHERE org_id = :org AND org_member_id = :mid AND clock_out IS NULL
            ORDER BY clock_in DESC, id DESC
            LIMIT 1
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array{ok:bool,error?:string} */
function org_timecard_clock_in(PDO $dbh, int $orgId, int $orgMemberId, string $note = ''): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid employee.'];
    }
    org_timecard_ensure_schema($dbh);

    $member = org_timecard_member($dbh, $orgId, $orgMemberId);
    if (!$member) {
        return ['ok' => false, 'error' => 'Employee not found in this organization.'];
    }
    if (org_timecard_open_entry($dbh, $orgId, $orgMemberId)) {
        return ['ok' => false, 'error' => 'You are already clocked in. Clock out first.'];
    }

    try {
        $st = $dbh->prepare("
            INSERT INTO org_time_cards
              (org_id, org_member_id, employee_name, employee_role, entry_type, status, clock_in, clock_out, worked_seconds, note)
            VALUES
              (:org, :mid, :name, :role, 'regular', 'draft', NOW(), NULL, 0, :note)
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':name' => mb_substr(trim((string)($member['name'] ?? 'Employee')), 0, 190),
            ':role' => mb_substr(strtolower((string)($member['member_type'] ?? 'staff')), 0, 40),
            ':note' => mb_substr(trim($note), 0, 255),
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not clock in.'];
    }
}

/**
 * Log a manual hours entry for a day (Step 2-3): regular/overtime worked, or PTO/sick/holiday/vacation leave.
 * Stored as a closed entry so it flows through submit → approve like punches.
 * @return array{ok:bool,error?:string}
 */
function org_timecard_log_hours(PDO $dbh, int $orgId, int $orgMemberId, string $date, float $hours, string $entryType, string $note = ''): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid employee.'];
    }
    org_timecard_ensure_schema($dbh);

    $member = org_timecard_member($dbh, $orgId, $orgMemberId);
    if (!$member) {
        return ['ok' => false, 'error' => 'Employee not found in this organization.'];
    }

    $entryType = strtolower(trim($entryType));
    if (!array_key_exists($entryType, org_timecard_entry_types())) {
        $entryType = 'regular';
    }
    $hours = round($hours, 2);
    if ($hours <= 0 || $hours > 24) {
        return ['ok' => false, 'error' => 'Enter hours between 0 and 24.'];
    }
    $dateTs = strtotime($date);
    if ($dateTs === false) {
        return ['ok' => false, 'error' => 'Invalid date.'];
    }
    $secs = (int)round($hours * 3600);
    $clockIn = date('Y-m-d', $dateTs) . ' 09:00:00';
    $clockOut = date('Y-m-d H:i:s', strtotime($clockIn) + $secs);

    try {
        $st = $dbh->prepare("
            INSERT INTO org_time_cards
              (org_id, org_member_id, employee_name, employee_role, entry_type, status, clock_in, clock_out, worked_seconds, note)
            VALUES
              (:org, :mid, :name, :role, :etype, 'draft', :cin, :cout, :secs, :note)
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':name' => mb_substr(trim((string)($member['name'] ?? 'Employee')), 0, 190),
            ':role' => mb_substr(strtolower((string)($member['member_type'] ?? 'staff')), 0, 40),
            ':etype' => $entryType,
            ':cin' => $clockIn,
            ':cout' => $clockOut,
            ':secs' => $secs,
            ':note' => mb_substr(trim($note), 0, 255),
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not log hours.'];
    }
}

/** Employee submits their closed draft entries for manager approval (Step 3). @return array{ok:bool,submitted:int} */
function org_timecard_submit_entries(PDO $dbh, int $orgId, int $orgMemberId): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'submitted' => 0];
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET status = 'submitted', updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org AND org_member_id = :mid AND clock_out IS NOT NULL AND status = 'draft'
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        return ['ok' => true, 'submitted' => $st->rowCount()];
    } catch (Throwable $e) {
        return ['ok' => false, 'submitted' => 0];
    }
}

/** Employee submits a single closed draft entry for manager approval. @return array{ok:bool,error?:string} */
function org_timecard_submit_entry(PDO $dbh, int $orgId, int $orgMemberId, int $entryId): array
{
    if ($orgId <= 0 || $orgMemberId <= 0 || $entryId <= 0) {
        return ['ok' => false, 'error' => 'Invalid entry.'];
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET status = 'submitted', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND org_id = :org AND org_member_id = :mid
              AND clock_out IS NOT NULL AND status IN ('draft','rejected')
            LIMIT 1
        ");
        $st->execute([':id' => $entryId, ':org' => $orgId, ':mid' => $orgMemberId]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Entry is not open for submission (already submitted or approved).'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not submit entry.'];
    }
}

/** List submitted (pending) time card entries awaiting approval — shown in Payroll. @return list<array<string,mixed>> */
function org_timecard_list_submitted(PDO $dbh, int $orgId, int $limit = 100): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_timecard_ensure_schema($dbh);
    $limit = max(1, min(300, $limit));
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_time_cards
            WHERE org_id = :org AND status = 'submitted' AND clock_out IS NOT NULL
            ORDER BY clock_in ASC, id ASC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Manager approves or rejects a submitted time card entry (Step 4). @return array{ok:bool,error?:string} */
function org_timecard_review_entry(PDO $dbh, int $orgId, int $entryId, bool $approve, int $reviewerMemberId = 0): array
{
    if ($orgId <= 0 || $entryId <= 0) {
        return ['ok' => false, 'error' => 'Invalid entry.'];
    }
    org_timecard_ensure_schema($dbh);
    $newStatus = $approve ? 'approved' : 'rejected';
    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET status = :st,
                approved_at = " . ($approve ? 'NOW()' : 'NULL') . ",
                approved_by_member_id = :by,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND org_id = :org AND clock_out IS NOT NULL
            LIMIT 1
        ");
        $st->execute([
            ':st' => $newStatus,
            ':by' => $reviewerMemberId > 0 ? $reviewerMemberId : null,
            ':id' => $entryId,
            ':org' => $orgId,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update entry.'];
    }
}

/** Manager approves all submitted entries at once. @return array{ok:bool,approved:int} */
function org_timecard_approve_all_submitted(PDO $dbh, int $orgId, int $reviewerMemberId = 0): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'approved' => 0];
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET status = 'approved', approved_at = NOW(), approved_by_member_id = :by, updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org AND status = 'submitted' AND clock_out IS NOT NULL
        ");
        $st->execute([':by' => $reviewerMemberId > 0 ? $reviewerMemberId : null, ':org' => $orgId]);
        return ['ok' => true, 'approved' => $st->rowCount()];
    } catch (Throwable $e) {
        return ['ok' => false, 'approved' => 0];
    }
}

/** @return array{ok:bool,error?:string} */
function org_timecard_clock_out(PDO $dbh, int $orgId, int $orgMemberId, string $note = ''): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid employee.'];
    }
    org_timecard_ensure_schema($dbh);

    $open = org_timecard_open_entry($dbh, $orgId, $orgMemberId);
    if (!$open) {
        return ['ok' => false, 'error' => 'You are not clocked in.'];
    }

    $inTs = strtotime((string)($open['clock_in'] ?? ''));
    $worked = $inTs ? max(0, time() - $inTs) : 0;
    $noteExisting = trim((string)($open['note'] ?? ''));
    $note = trim($note);
    $combined = $note !== '' && $noteExisting !== '' ? ($noteExisting . ' | ' . $note) : ($note !== '' ? $note : $noteExisting);

    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET clock_out = NOW(), worked_seconds = :w, note = :note, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND org_id = :org AND org_member_id = :mid
            LIMIT 1
        ");
        $st->execute([
            ':w' => $worked,
            ':note' => mb_substr($combined, 0, 255),
            ':id' => (int)($open['id'] ?? 0),
            ':org' => $orgId,
            ':mid' => $orgMemberId,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not clock out.'];
    }
}

/** @return list<array<string,mixed>> */
function org_timecard_list_for_member(PDO $dbh, int $orgId, int $orgMemberId, int $limit = 30): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return [];
    }
    org_timecard_ensure_schema($dbh);
    $limit = max(1, min(200, $limit));
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_time_cards
            WHERE org_id = :org AND org_member_id = :mid
            ORDER BY clock_in DESC, id DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string,mixed>> Manager view: recent entries across all employees. */
function org_timecard_list_for_org(PDO $dbh, int $orgId, int $limit = 60): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_timecard_ensure_schema($dbh);
    $limit = max(1, min(300, $limit));
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_time_cards
            WHERE org_id = :org
            ORDER BY (clock_out IS NULL) DESC, clock_in DESC, id DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Total worked seconds for one member across a pay period (closed shifts only).
 */
function org_timecard_worked_seconds_for_period(PDO $dbh, int $orgId, int $orgMemberId, string $startDate, string $endDate): int
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return 0;
    }
    org_timecard_ensure_schema($dbh);
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false) {
        return 0;
    }
    try {
        $st = $dbh->prepare("
            SELECT COALESCE(SUM(worked_seconds), 0)
            FROM org_time_cards
            WHERE org_id = :org
              AND org_member_id = :mid
              AND clock_out IS NOT NULL
              AND status = 'approved'
              AND DATE(clock_in) BETWEEN :start AND :end
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':start' => date('Y-m-d', $startTs),
            ':end' => date('Y-m-d', $endTs),
        ]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Worked seconds for every member in an org across a pay period.
 * @return array<int,int> org_member_id => worked_seconds
 */
function org_timecard_period_hours_map(PDO $dbh, int $orgId, string $startDate, string $endDate): array
{
    $out = [];
    if ($orgId <= 0) {
        return $out;
    }
    org_timecard_ensure_schema($dbh);
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false) {
        return $out;
    }
    try {
        $st = $dbh->prepare("
            SELECT org_member_id, COALESCE(SUM(worked_seconds), 0) AS secs
            FROM org_time_cards
            WHERE org_id = :org
              AND clock_out IS NOT NULL
              AND status = 'approved'
              AND DATE(clock_in) BETWEEN :start AND :end
            GROUP BY org_member_id
        ");
        $st->execute([
            ':org' => $orgId,
            ':start' => date('Y-m-d', $startTs),
            ':end' => date('Y-m-d', $endTs),
        ]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)($row['org_member_id'] ?? 0)] = (int)($row['secs'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * Earliest and latest approved time-card dates (optionally for one member).
 * Used by Payroll to derive a pay run period straight from the Time card,
 * so the manager never re-types period dates in Pay runs.
 * @return array{start:?string,end:?string}
 */
function org_timecard_approved_period_range(PDO $dbh, int $orgId, int $orgMemberId = 0): array
{
    $out = ['start' => null, 'end' => null];
    if ($orgId <= 0) {
        return $out;
    }
    org_timecard_ensure_schema($dbh);
    try {
        $sql = "
            SELECT DATE(MIN(clock_in)) AS min_d, DATE(MAX(clock_in)) AS max_d
            FROM org_time_cards
            WHERE org_id = :org
              AND clock_out IS NOT NULL
              AND status = 'approved'
        ";
        $params = [':org' => $orgId];
        if ($orgMemberId > 0) {
            $sql .= ' AND org_member_id = :mid';
            $params[':mid'] = $orgMemberId;
        }
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['start'] = !empty($row['min_d']) ? (string)$row['min_d'] : null;
        $out['end'] = !empty($row['max_d']) ? (string)$row['max_d'] : null;
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * org_member_ids that have at least one approved time card entry.
 * Payroll uses this so the "Start pay run" Employee list only shows people
 * whose time cards the manager has already approved.
 * @return list<int>
 */
function org_timecard_approved_member_ids(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT DISTINCT org_member_id
            FROM org_time_cards
            WHERE org_id = :org
              AND clock_out IS NOT NULL
              AND status = 'approved'
        ");
        $st->execute([':org' => $orgId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $mid) {
            $out[] = (int)$mid;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Break a member's approved period hours into regular / overtime / leave buckets (Steps 3, 8).
 * Overtime = worked hours beyond 40 per calendar week, plus any 'overtime' typed entries.
 * @return array{regular_secs:int,overtime_secs:int,pto_secs:int,sick_secs:int,holiday_secs:int,vacation_secs:int}
 */
function org_timecard_period_breakdown(PDO $dbh, int $orgId, int $orgMemberId, string $startDate, string $endDate, float $weeklyOtThresholdHours = 40.0): array
{
    $out = ['regular_secs' => 0, 'overtime_secs' => 0, 'pto_secs' => 0, 'sick_secs' => 0, 'holiday_secs' => 0, 'vacation_secs' => 0];
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return $out;
    }
    org_timecard_ensure_schema($dbh);
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false) {
        return $out;
    }
    $threshold = (int)round($weeklyOtThresholdHours * 3600);
    try {
        $st = $dbh->prepare("
            SELECT entry_type, worked_seconds, YEARWEEK(clock_in, 3) AS wk
            FROM org_time_cards
            WHERE org_id = :org AND org_member_id = :mid
              AND clock_out IS NOT NULL AND status = 'approved'
              AND DATE(clock_in) BETWEEN :start AND :end
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':start' => date('Y-m-d', $startTs),
            ':end' => date('Y-m-d', $endTs),
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $regularByWeek = [];
        foreach ($rows as $r) {
            $type = strtolower((string)($r['entry_type'] ?? 'regular'));
            $secs = (int)($r['worked_seconds'] ?? 0);
            if ($secs <= 0) {
                continue;
            }
            switch ($type) {
                case 'overtime':
                    $out['overtime_secs'] += $secs;
                    break;
                case 'pto':
                    $out['pto_secs'] += $secs;
                    break;
                case 'sick':
                    $out['sick_secs'] += $secs;
                    break;
                case 'holiday':
                    $out['holiday_secs'] += $secs;
                    break;
                case 'vacation':
                    $out['vacation_secs'] += $secs;
                    break;
                case 'regular':
                default:
                    $wk = (string)($r['wk'] ?? '0');
                    $regularByWeek[$wk] = ($regularByWeek[$wk] ?? 0) + $secs;
                    break;
            }
        }
        // Split each week's regular hours at the 40h/week overtime threshold.
        foreach ($regularByWeek as $weekSecs) {
            if ($weekSecs > $threshold) {
                $out['regular_secs'] += $threshold;
                $out['overtime_secs'] += ($weekSecs - $threshold);
            } else {
                $out['regular_secs'] += $weekSecs;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * @return array{on_clock:int,employees:int,hours_today:float,hours_week:float,pending:int}
 */
function org_timecard_org_stats(PDO $dbh, int $orgId): array
{
    $out = ['on_clock' => 0, 'employees' => 0, 'hours_today' => 0.0, 'hours_week' => 0.0, 'pending' => 0];
    if ($orgId <= 0) {
        return $out;
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_time_cards WHERE org_id = :org AND clock_out IS NULL");
        $st->execute([':org' => $orgId]);
        $out['on_clock'] = (int)$st->fetchColumn();

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_time_cards WHERE org_id = :org AND status = 'submitted' AND clock_out IS NOT NULL");
        $st->execute([':org' => $orgId]);
        $out['pending'] = (int)$st->fetchColumn();

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = :org AND status = 1 AND member_type = 'staff'");
        $st->execute([':org' => $orgId]);
        $out['employees'] = (int)$st->fetchColumn();

        $st = $dbh->prepare("
            SELECT COALESCE(SUM(worked_seconds), 0)
            FROM org_time_cards
            WHERE org_id = :org AND clock_out IS NOT NULL AND DATE(clock_in) = CURDATE()
        ");
        $st->execute([':org' => $orgId]);
        $out['hours_today'] = round(((int)$st->fetchColumn()) / 3600, 2);

        $st = $dbh->prepare("
            SELECT COALESCE(SUM(worked_seconds), 0)
            FROM org_time_cards
            WHERE org_id = :org AND clock_out IS NOT NULL AND clock_in >= (CURDATE() - INTERVAL 7 DAY)
        ");
        $st->execute([':org' => $orgId]);
        $out['hours_week'] = round(((int)$st->fetchColumn()) / 3600, 2);
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

function org_timecard_status_label(string $status): string
{
    $map = ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    return $map[strtolower(trim($status))] ?? 'Draft';
}

function org_timecard_duration_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h <= 0 && $m <= 0) {
        return '0m';
    }
    if ($h <= 0) {
        return $m . 'm';
    }
    return $h . 'h ' . $m . 'm';
}

function org_timecard_entry_duration_seconds(array $entry): int
{
    if (!empty($entry['clock_out'])) {
        return (int)($entry['worked_seconds'] ?? 0);
    }
    $inTs = strtotime((string)($entry['clock_in'] ?? ''));
    return $inTs ? max(0, time() - $inTs) : 0;
}

function org_timecard_fmt(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date('M j, Y g:i A', $ts) : '';
}
