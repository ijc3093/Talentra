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
        'break' => 'Break',
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

/** Unpaid time (e.g. break) — shown on the card but not paid in payroll. */
function org_timecard_is_unpaid_type(string $type): bool
{
    return in_array(strtolower(trim($type)), ['break'], true);
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
    // Linked when a pay run is approved — those hours are compensated and leave the Start pay run list.
    org_timecard_add_column($dbh, 'org_time_cards', 'pay_run_id', "BIGINT UNSIGNED NULL");
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

/**
 * Log a closed entry from calendar date + start/end clock times (same-day shift).
 * @return array{ok:bool,error?:string}
 */
function org_timecard_log_range(
    PDO $dbh,
    int $orgId,
    int $orgMemberId,
    string $date,
    string $startTime,
    string $endTime,
    string $entryType = 'regular',
    string $note = ''
): array {
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

    $date = trim($date);
    $startTime = trim($startTime);
    $endTime = trim($endTime);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'error' => 'Pick a valid work date.'];
    }
    if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $endTime)) {
        return ['ok' => false, 'error' => 'Pick a start and end time.'];
    }

    $startNorm = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
    $endNorm = strlen($endTime) === 5 ? $endTime . ':00' : $endTime;
    $clockIn = $date . ' ' . $startNorm;
    $clockOut = $date . ' ' . $endNorm;
    $inTs = strtotime($clockIn);
    $outTs = strtotime($clockOut);
    if ($inTs === false || $outTs === false) {
        return ['ok' => false, 'error' => 'Invalid date or time.'];
    }
    // Overnight: end before start rolls to the next calendar day.
    if ($outTs <= $inTs) {
        $outTs += 86400;
        $clockOut = date('Y-m-d H:i:s', $outTs);
    }
    $secs = $outTs - $inTs;
    if ($secs <= 0 || $secs > 24 * 3600) {
        return ['ok' => false, 'error' => 'Worked time must be greater than 0 and at most 24 hours.'];
    }

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
            ':cin' => date('Y-m-d H:i:s', $inTs),
            ':cout' => $clockOut,
            ':secs' => $secs,
            ':note' => mb_substr(trim($note), 0, 255),
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save time range.'];
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
            ORDER BY employee_name ASC, org_member_id ASC, DATE(clock_in) ASC, clock_in ASC, id ASC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Group submitted time cards by employee, then by calendar day
 * (so Regular + Break for the same day stay together; employees stay separate).
 *
 * @param list<array<string,mixed>>|null $rows
 * @return list<array{
 *   org_member_id:int,
 *   employee_name:string,
 *   employee_role:string,
 *   entry_count:int,
 *   total_seconds:int,
 *   days:list<array{date:string,date_label:string,total_seconds:int,entries:list<array<string,mixed>>}>
 * }>
 */
function org_timecard_group_submitted(?array $rows): array
{
    if (!$rows) {
        return [];
    }
    $byMember = [];
    foreach ($rows as $row) {
        $mid = (int)($row['org_member_id'] ?? 0);
        $key = $mid > 0 ? ('m' . $mid) : ('n' . strtolower(trim((string)($row['employee_name'] ?? 'unknown'))));
        if (!isset($byMember[$key])) {
            $byMember[$key] = [
                'org_member_id' => $mid,
                'employee_name' => trim((string)($row['employee_name'] ?? '')) ?: 'Employee',
                'employee_role' => (string)($row['employee_role'] ?? ''),
                'entry_count' => 0,
                'total_seconds' => 0,
                'days' => [],
            ];
        }
        $clockIn = (string)($row['clock_in'] ?? '');
        $dayKey = $clockIn !== '' ? date('Y-m-d', strtotime($clockIn)) : 'unknown';
        if (!isset($byMember[$key]['days'][$dayKey])) {
            $dayTs = $dayKey !== 'unknown' ? strtotime($dayKey) : false;
            $byMember[$key]['days'][$dayKey] = [
                'date' => $dayKey,
                'date_label' => $dayTs ? date('D, M j, Y', $dayTs) : 'Unknown date',
                'total_seconds' => 0,
                'entries' => [],
            ];
        }
        $secs = (int)($row['worked_seconds'] ?? 0);
        $byMember[$key]['days'][$dayKey]['entries'][] = $row;
        $byMember[$key]['days'][$dayKey]['total_seconds'] += $secs;
        $byMember[$key]['entry_count']++;
        $byMember[$key]['total_seconds'] += $secs;
    }

    $out = [];
    foreach ($byMember as $group) {
        $group['days'] = array_values($group['days']);
        $out[] = $group;
    }
    return $out;
}

/**
 * Approve or reject all submitted entries for one member.
 * @return array{ok:bool,count:int,error?:string}
 */
function org_timecard_review_member_submitted(
    PDO $dbh,
    int $orgId,
    int $orgMemberId,
    bool $approve,
    int $reviewerMemberId = 0
): array {
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'count' => 0, 'error' => 'Invalid employee.'];
    }
    org_timecard_ensure_schema($dbh);
    try {
        $stList = $dbh->prepare("
            SELECT id
            FROM org_time_cards
            WHERE org_id = :org
              AND org_member_id = :mid
              AND status = 'submitted'
              AND clock_out IS NOT NULL
        ");
        $stList->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $ids = array_map('intval', $stList->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$ids) {
            return ['ok' => true, 'count' => 0];
        }
        $count = 0;
        foreach ($ids as $eid) {
            $res = org_timecard_review_entry($dbh, $orgId, $eid, $approve, $reviewerMemberId);
            if (!empty($res['ok'])) {
                $count++;
            }
        }
        return ['ok' => true, 'count' => $count];
    } catch (Throwable $e) {
        return ['ok' => false, 'count' => 0, 'error' => 'Could not update time cards.'];
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
        $stGet = $dbh->prepare('SELECT * FROM org_time_cards WHERE id = :id AND org_id = :org LIMIT 1');
        $stGet->execute([':id' => $entryId, ':org' => $orgId]);
        $before = $stGet->fetch(PDO::FETCH_ASSOC) ?: null;

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
        if ($st->rowCount() < 1 && !$before) {
            return ['ok' => false, 'error' => 'Entry not found.'];
        }

        // Send approved earnings to the employee's account (or reverse on reject).
        require_once __DIR__ . '/org_member_earnings.php';
        if ($approve) {
            $stGet->execute([':id' => $entryId, ':org' => $orgId]);
            $after = $stGet->fetch(PDO::FETCH_ASSOC) ?: $before;
            if ($after) {
                org_member_earnings_credit_timecard($dbh, $orgId, $entryId, $reviewerMemberId, $after);
            }
        } else {
            org_member_earnings_reverse_timecard($dbh, $orgId, $entryId, $reviewerMemberId);
        }

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
        $stList = $dbh->prepare("
            SELECT id
            FROM org_time_cards
            WHERE org_id = :org AND status = 'submitted' AND clock_out IS NOT NULL
        ");
        $stList->execute([':org' => $orgId]);
        $ids = array_map('intval', $stList->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET status = 'approved', approved_at = NOW(), approved_by_member_id = :by, updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org AND status = 'submitted' AND clock_out IS NOT NULL
        ");
        $st->execute([':by' => $reviewerMemberId > 0 ? $reviewerMemberId : null, ':org' => $orgId]);
        $approved = $st->rowCount();

        require_once __DIR__ . '/org_member_earnings.php';
        foreach ($ids as $eid) {
            if ($eid > 0) {
                org_member_earnings_credit_timecard($dbh, $orgId, $eid, $reviewerMemberId);
            }
        }

        return ['ok' => true, 'approved' => $approved];
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
              AND (pay_run_id IS NULL OR pay_run_id = 0)
              AND LOWER(COALESCE(entry_type, 'regular')) <> 'break'
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
              AND (pay_run_id IS NULL OR pay_run_id = 0)
              AND LOWER(COALESCE(entry_type, 'regular')) <> 'break'
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
              AND (pay_run_id IS NULL OR pay_run_id = 0)
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
 * org_member_ids with approved time cards that are NOT yet on an approved/paid pay run.
 * Payroll "Employee" list uses this — after Approve payroll, the name disappears until
 * new approved time cards arrive.
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
              AND (pay_run_id IS NULL OR pay_run_id = 0)
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
 * Attach approved, uncompensated time cards in a period to a pay run (on Approve payroll).
 * @return int rows updated
 */
function org_timecard_attach_to_pay_run(
    PDO $dbh,
    int $orgId,
    int $payRunId,
    int $orgMemberId,
    string $periodStart,
    string $periodEnd
): int {
    if ($orgId <= 0 || $payRunId <= 0 || $orgMemberId <= 0) {
        return 0;
    }
    org_timecard_ensure_schema($dbh);
    $startTs = strtotime($periodStart);
    $endTs = strtotime($periodEnd);
    if ($startTs === false || $endTs === false) {
        return 0;
    }
    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET pay_run_id = :run, updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org
              AND org_member_id = :mid
              AND clock_out IS NOT NULL
              AND status = 'approved'
              AND (pay_run_id IS NULL OR pay_run_id = 0)
              AND DATE(clock_in) BETWEEN :start AND :end
        ");
        $st->execute([
            ':run' => $payRunId,
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':start' => date('Y-m-d', $startTs),
            ':end' => date('Y-m-d', $endTs),
        ]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Detach time cards from a pay run (when reopening a draft for edits). @return int */
function org_timecard_detach_from_pay_run(PDO $dbh, int $orgId, int $payRunId): int
{
    if ($orgId <= 0 || $payRunId <= 0) {
        return 0;
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            UPDATE org_time_cards
            SET pay_run_id = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org AND pay_run_id = :run
        ");
        $st->execute([':org' => $orgId, ':run' => $payRunId]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Backfill pay_run_id for orgs that already have approved/paid runs (so employees
 * already compensated leave the Start pay run list).
 */
function org_timecard_backfill_pay_run_links(PDO $dbh, int $orgId): void
{
    if ($orgId <= 0) {
        return;
    }
    org_timecard_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT r.id AS run_id, r.period_start, r.period_end, l.org_member_id
            FROM org_pay_runs r
            INNER JOIN org_pay_run_lines l ON l.pay_run_id = r.id AND l.org_id = r.org_id
            WHERE r.org_id = :org
              AND r.status IN ('approved', 'paid')
        ");
        $st->execute([':org' => $orgId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            org_timecard_attach_to_pay_run(
                $dbh,
                $orgId,
                (int)($row['run_id'] ?? 0),
                (int)($row['org_member_id'] ?? 0),
                (string)($row['period_start'] ?? ''),
                (string)($row['period_end'] ?? '')
            );
        }
    } catch (Throwable $e) {
        // ignore — older schemas may lack pay runs
    }
}

/**
 * Break a member's approved period hours into regular / overtime / leave buckets (Steps 3, 8).
 * Overtime = worked hours beyond 40 per calendar week, plus any 'overtime' typed entries.
 * Only uncompensated (not yet on an approved pay run) cards count.
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
              AND (pay_run_id IS NULL OR pay_run_id = 0)
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
                case 'break':
                    // Unpaid — do not include in regular/OT/leave pay buckets.
                    break;
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

/** Latest hourly rate saved on a pay-run line for this member (manager may set it there). */
function org_timecard_latest_line_rate_cents(PDO $dbh, int $orgId, int $orgMemberId): int
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return 0;
    }
    if (!function_exists('org_payroll_ensure_schema')) {
        return 0;
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT l.hourly_rate_cents
            FROM org_pay_run_lines l
            INNER JOIN org_pay_runs r ON r.id = l.pay_run_id AND r.org_id = :org
            WHERE l.org_member_id = :mid AND l.hourly_rate_cents > 0
            ORDER BY r.period_end DESC, l.updated_at DESC, l.id DESC
            LIMIT 1
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $rate = (int)$st->fetchColumn();
        if ($rate > 0) {
            return $rate;
        }

        // Implied rate from gross regular pay ÷ worked hours on the latest line.
        $st = $dbh->prepare("
            SELECT l.regular_cents, l.worked_seconds
            FROM org_pay_run_lines l
            INNER JOIN org_pay_runs r ON r.id = l.pay_run_id AND r.org_id = :org
            WHERE l.org_member_id = :mid AND l.regular_cents > 0 AND l.worked_seconds > 0
            ORDER BY r.period_end DESC, l.updated_at DESC, l.id DESC
            LIMIT 1
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $regular = (int)($row['regular_cents'] ?? 0);
            $secs = (int)($row['worked_seconds'] ?? 0);
            if ($regular > 0 && $secs > 0) {
                return max(1, (int)round($regular / ($secs / 3600)));
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return 0;
}

/**
 * Best hourly rate (cents) for timecard earnings: profile rate → salary/default gross → pay-run line.
 */
function org_timecard_resolve_hourly_rate_cents(PDO $dbh, int $orgId, int $orgMemberId, ?array $payProfile = null): int
{
    if ($payProfile === null && $orgId > 0 && $orgMemberId > 0 && function_exists('org_payroll_list_employees')) {
        foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
            if ((int)($emp['org_member_id'] ?? 0) === $orgMemberId) {
                $payProfile = $emp;
                break;
            }
        }
    }

    if (is_array($payProfile)) {
        $rate = (int)($payProfile['hourly_rate_cents'] ?? 0);
        if ($rate > 0) {
            return $rate;
        }

        $annual = (int)($payProfile['annual_salary_cents'] ?? 0);
        if ($annual > 0) {
            return max(1, (int)round($annual / 2080));
        }

        $defaultGross = (int)($payProfile['default_gross_cents'] ?? 0);
        if ($defaultGross > 0 && function_exists('org_payroll_periods_per_year') && function_exists('org_payroll_normalize_frequency')) {
            $freq = org_payroll_normalize_frequency((string)($payProfile['pay_frequency'] ?? 'monthly'));
            $periods = org_payroll_periods_per_year($freq);
            $periodHours = 2080 / max(1, $periods);
            return max(1, (int)round($defaultGross / $periodHours));
        }
    }

    return org_timecard_latest_line_rate_cents($dbh, $orgId, $orgMemberId);
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

/** Standard full-time week used for manager income budget when no custom hours are set. */
function org_timecard_expected_weekly_hours(?array $payProfile = null): float
{
    if (is_array($payProfile)) {
        $h = (float)($payProfile['expected_weekly_hours'] ?? 0);
        if ($h > 0) {
            return function_exists('org_payroll_normalize_weekly_hours')
                ? org_payroll_normalize_weekly_hours($h)
                : min(168.0, round($h, 2));
        }
    }
    return 40.0;
}

function org_timecard_expected_weekly_income_cents(int $hourlyRateCents, ?array $payProfile = null): int
{
    if ($hourlyRateCents <= 0) {
        return 0;
    }
    return (int)round($hourlyRateCents * org_timecard_expected_weekly_hours($payProfile));
}

function org_timecard_earn_cents_from_seconds(int $seconds, string $entryType, int $rateCents): int
{
    if ($rateCents <= 0 || $seconds <= 0) {
        return 0;
    }
    if (org_timecard_is_unpaid_type($entryType)) {
        return 0;
    }
    $mult = strtolower(trim($entryType)) === 'overtime' ? 1.5 : 1.0;
    return (int)round(($seconds / 3600) * $rateCents * $mult);
}

/**
 * Sunday 00:00 → next Sunday 00:00 for the week containing $forDate (Y-m-d or datetime).
 * @return array{0:string,1:string} [weekStartDatetime, weekEndDatetime)
 */
function org_timecard_week_bounds(?string $forDate = null): array
{
    $ts = $forDate ? strtotime($forDate) : time();
    if ($ts === false) {
        $ts = time();
    }
    $dow = (int)date('w', $ts); // 0 = Sunday
    $startTs = strtotime(date('Y-m-d 00:00:00', $ts)) - ($dow * 86400);
    $endTs = $startTs + (7 * 86400);
    return [date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)];
}

/**
 * Sum estimated earnings for a member's current calendar week (Sun–Sat), excluding rejected.
 * @return array{cents:int,seconds:int,expected_cents:int,rate_cents:int,over:bool}
 */
function org_timecard_member_week_income(PDO $dbh, int $orgId, int $orgMemberId, ?int $rateCents = null, ?string $forDate = null, ?array $payProfile = null): array
{
    if ($payProfile === null && $orgId > 0 && $orgMemberId > 0 && function_exists('org_payroll_list_employees')) {
        foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
            if ((int)($emp['org_member_id'] ?? 0) === $orgMemberId) {
                $payProfile = $emp;
                break;
            }
        }
    }
    $rateCents = $rateCents ?? org_timecard_resolve_hourly_rate_cents($dbh, $orgId, $orgMemberId, $payProfile);
    $expected = org_timecard_expected_weekly_income_cents($rateCents, $payProfile);
    $out = [
        'cents' => 0,
        'seconds' => 0,
        'expected_cents' => $expected,
        'expected_hours' => org_timecard_expected_weekly_hours($payProfile),
        'rate_cents' => max(0, $rateCents),
        'over' => false,
    ];
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return $out;
    }
    org_timecard_ensure_schema($dbh);
    [$weekStart, $weekEnd] = org_timecard_week_bounds($forDate);
    try {
        $st = $dbh->prepare("
            SELECT entry_type, clock_in, clock_out, worked_seconds, status
            FROM org_time_cards
            WHERE org_id = :org AND org_member_id = :mid
              AND clock_in >= :ws AND clock_in < :we
              AND status <> 'rejected'
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':ws' => $weekStart,
            ':we' => $weekEnd,
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $secs = org_timecard_entry_duration_seconds($row);
            $out['seconds'] += $secs;
            $out['cents'] += org_timecard_earn_cents_from_seconds(
                $secs,
                (string)($row['entry_type'] ?? 'regular'),
                $rateCents
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    $out['over'] = $expected > 0 && $out['cents'] >= $expected;
    return $out;
}

/**
 * Validate that projected week income stays under the manager's rate × hours budget.
 * Alert when earned income is at least the expected weekly amount (equal or over).
 *
 * @return array{ok:bool,over:bool,message:string,earned_cents:int,expected_cents:int,rate_cents:int,expected_hours:float}
 */
function org_timecard_check_weekly_income_cap(
    PDO $dbh,
    int $orgId,
    int $orgMemberId,
    int $extraSeconds = 0,
    string $extraEntryType = 'regular',
    ?string $forDate = null
): array {
    $payProfile = null;
    if (function_exists('org_payroll_list_employees')) {
        foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
            if ((int)($emp['org_member_id'] ?? 0) === $orgMemberId) {
                $payProfile = $emp;
                break;
            }
        }
    }
    $rateCents = org_timecard_resolve_hourly_rate_cents($dbh, $orgId, $orgMemberId, $payProfile);
    $week = org_timecard_member_week_income($dbh, $orgId, $orgMemberId, $rateCents, $forDate, $payProfile);
    $earned = (int)$week['cents'] + org_timecard_earn_cents_from_seconds($extraSeconds, $extraEntryType, $rateCents);
    $expected = (int)$week['expected_cents'];
    $hours = (float)($week['expected_hours'] ?? org_timecard_expected_weekly_hours($payProfile));
    $rateLabel = $rateCents > 0 && function_exists('org_payroll_format_cents')
        ? org_payroll_format_cents($rateCents) . '/hr'
        : 'your hourly rate';
    $expectedLabel = $expected > 0 && function_exists('org_payroll_format_cents')
        ? org_payroll_format_cents($expected)
        : '$0.00';
    $earnedLabel = function_exists('org_payroll_format_cents')
        ? org_payroll_format_cents($earned)
        : ('$' . number_format($earned / 100, 2));

    if ($rateCents <= 0 || $expected <= 0) {
        return [
            'ok' => true,
            'over' => false,
            'message' => '',
            'earned_cents' => $earned,
            'expected_cents' => $expected,
            'rate_cents' => $rateCents,
            'expected_hours' => $hours,
        ];
    }

    $over = $earned >= $expected;
    $message = '';
    if ($over) {
        $hoursLabel = rtrim(rtrim(number_format($hours, 2), '0'), '.');
        $message = 'Your earn income is not match with what the manager setup the amount of the income according to your per hour'
            . ' (' . $rateLabel . ' × ' . $hoursLabel . ' hrs = ' . $expectedLabel . ').'
            . ' This week is at ' . $earnedLabel . '.'
            . ' Please edit your time card and click Okay to close the pop.';
    }

    return [
        'ok' => !$over,
        'over' => $over,
        'message' => $message,
        'earned_cents' => $earned,
        'expected_cents' => $expected,
        'rate_cents' => $rateCents,
        'expected_hours' => $hours,
    ];
}

function org_timecard_fmt(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date('M j, Y g:i A', $ts) : '';
}
