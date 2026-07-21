<?php
declare(strict_types=1);

/**
 * Member earnings account — credited when a time card is approved (staff or manager).
 * Balance is gross estimated pay (hours × rate), separate from payroll net/paystubs.
 */

require_once __DIR__ . '/org_timecard.php';
require_once __DIR__ . '/org_payroll.php';

function org_member_earnings_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once dirname(__DIR__, 2) . '/public_user/includes/msb_migrations.php';
    $migration = dirname(__DIR__, 2) . '/Data/migrations/20260721_org_member_earnings.sql';
    if (is_file($migration) && function_exists('msb_run_sql_migration_file')) {
        msb_run_sql_migration_file($dbh, $migration);
        return;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_member_earnings_accounts (
              id INT AUTO_INCREMENT PRIMARY KEY,
              org_id INT NOT NULL,
              org_member_id INT NOT NULL,
              balance_cents BIGINT NOT NULL DEFAULT 0,
              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_org_member_earn_acct (org_id, org_member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_member_earnings_transactions (
              id INT AUTO_INCREMENT PRIMARY KEY,
              org_id INT NOT NULL,
              org_member_id INT NOT NULL,
              amount_cents INT NOT NULL,
              balance_after_cents BIGINT NOT NULL DEFAULT 0,
              txn_type VARCHAR(40) NOT NULL,
              reference_type VARCHAR(40) NOT NULL DEFAULT 'time_card',
              reference_id INT NOT NULL DEFAULT 0,
              description VARCHAR(255) NULL,
              created_by_member_id INT NULL,
              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_earn_txn_idempotent (org_id, reference_type, reference_id, txn_type),
              KEY idx_earn_txn_member (org_id, org_member_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // callers degrade gracefully
    }
}

/** @return array{ok:bool,balance_cents:int} */
function org_member_earnings_ensure_account(PDO $dbh, int $orgId, int $orgMemberId): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'balance_cents' => 0];
    }
    org_member_earnings_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            INSERT INTO org_member_earnings_accounts (org_id, org_member_id, balance_cents)
            VALUES (:org, :mid, 0)
            ON DUPLICATE KEY UPDATE org_id = org_id
        ');
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        return ['ok' => true, 'balance_cents' => org_member_earnings_get_balance($dbh, $orgId, $orgMemberId)];
    } catch (Throwable $e) {
        return ['ok' => false, 'balance_cents' => 0];
    }
}

function org_member_earnings_get_balance(PDO $dbh, int $orgId, int $orgMemberId): int
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return 0;
    }
    org_member_earnings_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT balance_cents
            FROM org_member_earnings_accounts
            WHERE org_id = :org AND org_member_id = :mid
            LIMIT 1
        ');
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        return (int)($row['balance_cents'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function org_member_earnings_list_transactions(PDO $dbh, int $orgId, int $orgMemberId, int $limit = 50): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return [];
    }
    org_member_earnings_ensure_schema($dbh);
    $limit = max(1, min(200, $limit));
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_member_earnings_transactions
            WHERE org_id = :org AND org_member_id = :mid
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_member_earnings_has_txn(PDO $dbh, int $orgId, int $referenceId, string $txnType): bool
{
    if ($orgId <= 0 || $referenceId <= 0 || $txnType === '') {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT id
            FROM org_member_earnings_transactions
            WHERE org_id = :org
              AND reference_type = \'time_card\'
              AND reference_id = :rid
              AND txn_type = :tt
            LIMIT 1
        ');
        $st->execute([':org' => $orgId, ':rid' => $referenceId, ':tt' => $txnType]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Compute gross cents for a closed time-card row using the member's hourly rate.
 * @param array<string,mixed> $entry
 */
function org_member_earnings_amount_for_entry(PDO $dbh, int $orgId, array $entry): int
{
    $memberId = (int)($entry['org_member_id'] ?? 0);
    if ($memberId <= 0) {
        return 0;
    }
    $secs = function_exists('org_timecard_entry_duration_seconds')
        ? org_timecard_entry_duration_seconds($entry)
        : max(0, (int)($entry['worked_seconds'] ?? 0));
    $rate = org_timecard_resolve_hourly_rate_cents($dbh, $orgId, $memberId);
    return org_timecard_earn_cents_from_seconds($secs, (string)($entry['entry_type'] ?? 'regular'), $rate);
}

/**
 * @param array<string,mixed> $entry
 */
function org_member_earnings_describe_entry(array $entry, int $amountCents): string
{
    $in = (string)($entry['clock_in'] ?? '');
    $ts = $in !== '' ? strtotime($in) : false;
    $day = $ts ? date('M j, Y', $ts) : 'shift';
    $secs = function_exists('org_timecard_entry_duration_seconds')
        ? org_timecard_entry_duration_seconds($entry)
        : max(0, (int)($entry['worked_seconds'] ?? 0));
    $hours = function_exists('org_timecard_duration_label')
        ? org_timecard_duration_label($secs)
        : (round($secs / 3600, 2) . 'h');
    $type = function_exists('org_timecard_entry_type_label')
        ? org_timecard_entry_type_label((string)($entry['entry_type'] ?? 'regular'))
        : 'Regular';
    $amt = function_exists('org_payroll_format_cents')
        ? org_payroll_format_cents($amountCents)
        : ('$' . number_format($amountCents / 100, 2));
    return "Approved time card · {$day} · {$type} · {$hours} · {$amt}";
}

/**
 * Credit employee account for an approved time card (idempotent).
 * @param array<string,mixed>|null $entry optional preloaded row
 * @return array{ok:bool,credited:bool,amount_cents?:int,error?:string}
 */
function org_member_earnings_credit_timecard(
    PDO $dbh,
    int $orgId,
    int $entryId,
    int $byMemberId = 0,
    ?array $entry = null
): array {
    if ($orgId <= 0 || $entryId <= 0) {
        return ['ok' => false, 'credited' => false, 'error' => 'Invalid entry.'];
    }
    org_member_earnings_ensure_schema($dbh);

    if (org_member_earnings_has_txn($dbh, $orgId, $entryId, 'timecard_credit')) {
        return ['ok' => true, 'credited' => false, 'amount_cents' => 0];
    }

    try {
        if (!$entry) {
            $st = $dbh->prepare('SELECT * FROM org_time_cards WHERE id = :id AND org_id = :org LIMIT 1');
            $st->execute([':id' => $entryId, ':org' => $orgId]);
            $entry = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$entry) {
            return ['ok' => false, 'credited' => false, 'error' => 'Time card not found.'];
        }
        if (strtolower((string)($entry['status'] ?? '')) !== 'approved') {
            return ['ok' => false, 'credited' => false, 'error' => 'Time card is not approved.'];
        }
        if (empty($entry['clock_out'])) {
            return ['ok' => false, 'credited' => false, 'error' => 'Open shift cannot be credited.'];
        }

        $memberId = (int)($entry['org_member_id'] ?? 0);
        $amount = org_member_earnings_amount_for_entry($dbh, $orgId, $entry);
        if ($memberId <= 0) {
            return ['ok' => false, 'credited' => false, 'error' => 'Invalid employee on entry.'];
        }
        if ($amount <= 0) {
            return ['ok' => true, 'credited' => false, 'amount_cents' => 0, 'error' => 'No hourly rate set — nothing credited.'];
        }

        $dbh->beginTransaction();
        org_member_earnings_ensure_account($dbh, $orgId, $memberId);

        $stBal = $dbh->prepare('
            SELECT balance_cents
            FROM org_member_earnings_accounts
            WHERE org_id = :org AND org_member_id = :mid
            LIMIT 1
            FOR UPDATE
        ');
        $stBal->execute([':org' => $orgId, ':mid' => $memberId]);
        $bal = (int)($stBal->fetchColumn() ?: 0);
        $newBal = $bal + $amount;

        $ins = $dbh->prepare('
            INSERT INTO org_member_earnings_transactions
              (org_id, org_member_id, amount_cents, balance_after_cents, txn_type, reference_type, reference_id, description, created_by_member_id)
            VALUES
              (:org, :mid, :amt, :after, \'timecard_credit\', \'time_card\', :rid, :desc, :by)
        ');
        $ins->execute([
            ':org' => $orgId,
            ':mid' => $memberId,
            ':amt' => $amount,
            ':after' => $newBal,
            ':rid' => $entryId,
            ':desc' => org_member_earnings_describe_entry($entry, $amount),
            ':by' => $byMemberId > 0 ? $byMemberId : null,
        ]);

        $up = $dbh->prepare('
            UPDATE org_member_earnings_accounts
            SET balance_cents = :bal, updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org AND org_member_id = :mid
            LIMIT 1
        ');
        $up->execute([':bal' => $newBal, ':org' => $orgId, ':mid' => $memberId]);
        $dbh->commit();

        return ['ok' => true, 'credited' => true, 'amount_cents' => $amount];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        // Duplicate unique key = already credited
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            return ['ok' => true, 'credited' => false, 'amount_cents' => 0];
        }
        return ['ok' => false, 'credited' => false, 'error' => 'Could not credit account.'];
    }
}

/**
 * Reverse a prior credit when a manager rejects an approved (or submitted) entry.
 * @return array{ok:bool,reversed:bool,amount_cents?:int,error?:string}
 */
function org_member_earnings_reverse_timecard(
    PDO $dbh,
    int $orgId,
    int $entryId,
    int $byMemberId = 0
): array {
    if ($orgId <= 0 || $entryId <= 0) {
        return ['ok' => false, 'reversed' => false, 'error' => 'Invalid entry.'];
    }
    org_member_earnings_ensure_schema($dbh);

    if (!org_member_earnings_has_txn($dbh, $orgId, $entryId, 'timecard_credit')) {
        return ['ok' => true, 'reversed' => false, 'amount_cents' => 0];
    }
    if (org_member_earnings_has_txn($dbh, $orgId, $entryId, 'timecard_reversal')) {
        return ['ok' => true, 'reversed' => false, 'amount_cents' => 0];
    }

    try {
        $stC = $dbh->prepare('
            SELECT *
            FROM org_member_earnings_transactions
            WHERE org_id = :org
              AND reference_type = \'time_card\'
              AND reference_id = :rid
              AND txn_type = \'timecard_credit\'
            LIMIT 1
        ');
        $stC->execute([':org' => $orgId, ':rid' => $entryId]);
        $credit = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$credit) {
            return ['ok' => true, 'reversed' => false, 'amount_cents' => 0];
        }

        $memberId = (int)($credit['org_member_id'] ?? 0);
        $amount = (int)($credit['amount_cents'] ?? 0);
        if ($memberId <= 0 || $amount <= 0) {
            return ['ok' => true, 'reversed' => false, 'amount_cents' => 0];
        }

        $dbh->beginTransaction();
        $stBal = $dbh->prepare('
            SELECT balance_cents
            FROM org_member_earnings_accounts
            WHERE org_id = :org AND org_member_id = :mid
            LIMIT 1
            FOR UPDATE
        ');
        $stBal->execute([':org' => $orgId, ':mid' => $memberId]);
        $bal = (int)($stBal->fetchColumn() ?: 0);
        $newBal = max(0, $bal - $amount);

        $desc = 'Reversal · ' . trim((string)($credit['description'] ?? 'time card credit'));
        $ins = $dbh->prepare('
            INSERT INTO org_member_earnings_transactions
              (org_id, org_member_id, amount_cents, balance_after_cents, txn_type, reference_type, reference_id, description, created_by_member_id)
            VALUES
              (:org, :mid, :amt, :after, \'timecard_reversal\', \'time_card\', :rid, :desc, :by)
        ');
        $ins->execute([
            ':org' => $orgId,
            ':mid' => $memberId,
            ':amt' => -$amount,
            ':after' => $newBal,
            ':rid' => $entryId,
            ':desc' => mb_substr($desc, 0, 255),
            ':by' => $byMemberId > 0 ? $byMemberId : null,
        ]);

        $up = $dbh->prepare('
            UPDATE org_member_earnings_accounts
            SET balance_cents = :bal, updated_at = CURRENT_TIMESTAMP
            WHERE org_id = :org AND org_member_id = :mid
            LIMIT 1
        ');
        $up->execute([':bal' => $newBal, ':org' => $orgId, ':mid' => $memberId]);
        $dbh->commit();

        return ['ok' => true, 'reversed' => true, 'amount_cents' => $amount];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            return ['ok' => true, 'reversed' => false, 'amount_cents' => 0];
        }
        return ['ok' => false, 'reversed' => false, 'error' => 'Could not reverse credit.'];
    }
}

/**
 * Backfill credits for already-approved time cards missing a ledger row (this member).
 * @return array{ok:bool,credited:int}
 */
function org_member_earnings_backfill_member(PDO $dbh, int $orgId, int $orgMemberId): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'credited' => 0];
    }
    org_member_earnings_ensure_schema($dbh);
    $credited = 0;
    try {
        $st = $dbh->prepare("
            SELECT tc.*
            FROM org_time_cards tc
            LEFT JOIN org_member_earnings_transactions t
              ON t.org_id = tc.org_id
             AND t.reference_type = 'time_card'
             AND t.reference_id = tc.id
             AND t.txn_type = 'timecard_credit'
            WHERE tc.org_id = :org
              AND tc.org_member_id = :mid
              AND tc.status = 'approved'
              AND tc.clock_out IS NOT NULL
              AND t.id IS NULL
            ORDER BY tc.id ASC
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $entry) {
            $res = org_member_earnings_credit_timecard(
                $dbh,
                $orgId,
                (int)($entry['id'] ?? 0),
                (int)($entry['approved_by_member_id'] ?? 0),
                $entry
            );
            if (!empty($res['credited'])) {
                $credited++;
            }
        }
        return ['ok' => true, 'credited' => $credited];
    } catch (Throwable $e) {
        return ['ok' => false, 'credited' => $credited];
    }
}
