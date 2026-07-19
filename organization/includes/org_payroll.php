<?php
declare(strict_types=1);

/**
 * Organization payroll: manager pays employees (Gross, Deductions, Net, Employer Taxes).
 * Payees are org_members (typically staff hired via create_staff.php).
 */

function org_payroll_money_to_cents(string $raw): int
{
    $raw = trim(str_replace([',', '$', ' '], '', $raw));
    if ($raw === '' || !is_numeric($raw)) {
        return 0;
    }
    return (int)round(((float)$raw) * 100);
}

function org_payroll_format_cents(int $cents): string
{
    if (function_exists('org_sales_money')) {
        return org_sales_money($cents);
    }
    if (function_exists('org_shop_format_price')) {
        return org_shop_format_price($cents, 'USD');
    }
    return '$' . number_format($cents / 100, 2);
}

function org_payroll_column_exists(PDO $dbh, string $table, string $col): bool
{
    try {
        $st = $dbh->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function org_payroll_add_column(PDO $dbh, string $table, string $col, string $ddl): void
{
    if (org_payroll_column_exists($dbh, $table, $col)) {
        return;
    }
    try {
        $dbh->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$ddl}");
    } catch (Throwable $e) {
        // ignore
    }
}

function org_payroll_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_payroll_profiles (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              org_id BIGINT UNSIGNED NOT NULL,
              org_member_id BIGINT UNSIGNED NOT NULL,
              pay_type VARCHAR(20) NOT NULL DEFAULT 'salary',
              pay_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
              hourly_rate_cents INT NOT NULL DEFAULT 0,
              default_gross_cents INT NOT NULL DEFAULT 0,
              default_deductions_cents INT NOT NULL DEFAULT 0,
              default_employer_tax_cents INT NOT NULL DEFAULT 0,
              notes VARCHAR(500) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_org_payroll_profile_member (org_id, org_member_id),
              KEY idx_org_payroll_profiles_org (org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_pay_runs (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              org_id BIGINT UNSIGNED NOT NULL,
              created_by_member_id BIGINT UNSIGNED NULL,
              label VARCHAR(120) NOT NULL DEFAULT '',
              pay_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
              period_start DATE NOT NULL,
              period_end DATE NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'draft',
              notes TEXT NULL,
              paid_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_org_pay_runs_org_status (org_id, status),
              KEY idx_org_pay_runs_period (org_id, period_start, period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_pay_run_lines (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              pay_run_id BIGINT UNSIGNED NOT NULL,
              org_id BIGINT UNSIGNED NOT NULL,
              org_member_id BIGINT UNSIGNED NOT NULL,
              employee_name VARCHAR(190) NOT NULL DEFAULT '',
              employee_role VARCHAR(40) NOT NULL DEFAULT 'staff',
              worked_seconds INT NOT NULL DEFAULT 0,
              hourly_rate_cents INT NOT NULL DEFAULT 0,
              gross_cents INT NOT NULL DEFAULT 0,
              deductions_cents INT NOT NULL DEFAULT 0,
              net_cents INT NOT NULL DEFAULT 0,
              employer_tax_cents INT NOT NULL DEFAULT 0,
              deduction_note VARCHAR(255) NULL,
              line_note VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_org_pay_run_line_member (pay_run_id, org_member_id),
              KEY idx_org_pay_run_lines_run (pay_run_id),
              KEY idx_org_pay_run_lines_org (org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    // Backfill columns for existing installs.
    org_payroll_add_column($dbh, 'org_payroll_profiles', 'pay_frequency', "VARCHAR(20) NOT NULL DEFAULT 'monthly'");
    org_payroll_add_column($dbh, 'org_payroll_profiles', 'hourly_rate_cents', "INT NOT NULL DEFAULT 0");
    org_payroll_add_column($dbh, 'org_pay_runs', 'pay_frequency', "VARCHAR(20) NOT NULL DEFAULT 'monthly'");
    org_payroll_add_column($dbh, 'org_pay_run_lines', 'worked_seconds', "INT NOT NULL DEFAULT 0");
    org_payroll_add_column($dbh, 'org_pay_run_lines', 'hourly_rate_cents', "INT NOT NULL DEFAULT 0");

    // Step 1 — HR onboarding fields on the employee profile.
    org_payroll_add_column($dbh, 'org_payroll_profiles', 'annual_salary_cents', "BIGINT NOT NULL DEFAULT 0");
    org_payroll_add_column($dbh, 'org_payroll_profiles', 'tax_status', "VARCHAR(20) NOT NULL DEFAULT 'single'");
    org_payroll_add_column($dbh, 'org_payroll_profiles', 'bank_name', "VARCHAR(120) NOT NULL DEFAULT ''");
    org_payroll_add_column($dbh, 'org_payroll_profiles', 'overtime_eligible', "TINYINT NOT NULL DEFAULT 1");

    // Steps 12-14 — pay run approval trail.
    org_payroll_add_column($dbh, 'org_pay_runs', 'approved_at', "DATETIME NULL");
    org_payroll_add_column($dbh, 'org_pay_runs', 'approved_by_member_id', "BIGINT UNSIGNED NULL");

    // Steps 7-11 — itemized gross components on each pay line.
    foreach ([
        'regular_cents', 'overtime_cents', 'bonus_cents', 'commission_cents', 'holiday_cents', 'vacation_cents',
        'ded_federal_cents', 'ded_state_cents', 'ded_health_cents', 'ded_dental_cents', 'ded_retirement_cents', 'ded_other_cents',
        'etax_social_cents', 'etax_medicare_cents', 'etax_unemp_cents', 'etax_workers_cents',
    ] as $col) {
        org_payroll_add_column($dbh, 'org_pay_run_lines', $col, 'INT NOT NULL DEFAULT 0');
    }
    org_payroll_add_column($dbh, 'org_pay_run_lines', 'overtime_seconds', 'INT NOT NULL DEFAULT 0');
    org_payroll_add_column($dbh, 'org_pay_run_lines', 'paid_leave_seconds', 'INT NOT NULL DEFAULT 0');
}

/**
 * Default US employer-side statutory tax rates (Step 11). Editable per line before saving.
 * Percentages of gross pay.
 */
function org_payroll_employer_tax_rates(): array
{
    return [
        'social'  => 0.062,   // Social Security (employer share)
        'medicare' => 0.0145, // Medicare (employer share)
        'unemp'   => 0.006,   // Federal/State unemployment (simplified)
        'workers' => 0.010,   // Workers' compensation insurance (illustrative)
    ];
}

/**
 * Compute employer taxes from gross pay using the default rates.
 * @return array{social:int,medicare:int,unemp:int,workers:int,total:int}
 */
function org_payroll_compute_employer_taxes(int $grossCents): array
{
    $grossCents = max(0, $grossCents);
    $rates = org_payroll_employer_tax_rates();
    $social = (int)round($grossCents * $rates['social']);
    $medicare = (int)round($grossCents * $rates['medicare']);
    $unemp = (int)round($grossCents * $rates['unemp']);
    $workers = (int)round($grossCents * $rates['workers']);
    return [
        'social' => $social,
        'medicare' => $medicare,
        'unemp' => $unemp,
        'workers' => $workers,
        'total' => $social + $medicare + $unemp + $workers,
    ];
}

/** Normalize a pay frequency string. */
function org_payroll_normalize_frequency(string $freq): string
{
    $freq = strtolower(trim($freq));
    $freq = str_replace([' ', '-'], '_', $freq);
    $allowed = ['weekly', 'bi_weekly', 'monthly'];
    return in_array($freq, $allowed, true) ? $freq : 'monthly';
}

function org_payroll_frequency_label(string $freq): string
{
    $map = ['weekly' => 'Weekly', 'bi_weekly' => 'Bi-weekly', 'monthly' => 'Monthly'];
    return $map[org_payroll_normalize_frequency($freq)] ?? 'Monthly';
}

/**
 * Suggested period end for a start date given a frequency.
 * @return array{start:string,end:string}
 */
function org_payroll_frequency_period(string $freq, ?string $startDate = null): array
{
    $freq = org_payroll_normalize_frequency($freq);
    $startTs = $startDate ? strtotime($startDate) : false;
    if ($startTs === false) {
        $startTs = strtotime(date('Y-m-01'));
    }
    switch ($freq) {
        case 'weekly':
            $endTs = strtotime('+6 days', $startTs);
            break;
        case 'bi_weekly':
            $endTs = strtotime('+13 days', $startTs);
            break;
        case 'monthly':
        default:
            $endTs = strtotime(date('Y-m-t', $startTs));
            break;
    }
    return ['start' => date('Y-m-d', $startTs), 'end' => date('Y-m-d', $endTs)];
}

/**
 * @return list<array<string,mixed>>
 */
function org_payroll_list_employees(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT
              om.id AS org_member_id,
              om.member_type,
              om.relationship_label,
              COALESCE(m.fullname, s.fullname, m.username, s.username, 'Team member') AS name,
              COALESCE(m.email, s.email, '') AS email,
              COALESCE(pp.pay_type, 'salary') AS pay_type,
              COALESCE(pp.pay_frequency, 'monthly') AS pay_frequency,
              COALESCE(pp.hourly_rate_cents, 0) AS hourly_rate_cents,
              COALESCE(pp.default_gross_cents, 0) AS default_gross_cents,
              COALESCE(pp.default_deductions_cents, 0) AS default_deductions_cents,
              COALESCE(pp.default_employer_tax_cents, 0) AS default_employer_tax_cents,
              COALESCE(pp.annual_salary_cents, 0) AS annual_salary_cents,
              COALESCE(pp.tax_status, 'single') AS tax_status,
              COALESCE(pp.bank_name, '') AS bank_name,
              COALESCE(pp.overtime_eligible, 1) AS overtime_eligible,
              COALESCE(pp.notes, '') AS profile_notes
            FROM org_members om
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            LEFT JOIN org_payroll_profiles pp ON pp.org_id = om.org_id AND pp.org_member_id = om.id
            WHERE om.org_id = :org AND om.status = 1
            ORDER BY
              CASE WHEN om.member_type = 'staff' THEN 0 ELSE 1 END,
              name ASC
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function org_payroll_list_runs(PDO $dbh, int $orgId, int $limit = 30): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_payroll_ensure_schema($dbh);
    $limit = max(1, min(100, $limit));
    try {
        $st = $dbh->prepare("
            SELECT
              r.*,
              COUNT(l.id) AS employee_count,
              COALESCE(SUM(l.gross_cents), 0) AS total_gross_cents,
              COALESCE(SUM(l.deductions_cents), 0) AS total_deductions_cents,
              COALESCE(SUM(l.net_cents), 0) AS total_net_cents,
              COALESCE(SUM(l.employer_tax_cents), 0) AS total_employer_tax_cents
            FROM org_pay_runs r
            LEFT JOIN org_pay_run_lines l ON l.pay_run_id = r.id
            WHERE r.org_id = :org
            GROUP BY r.id
            ORDER BY r.period_end DESC, r.id DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string,mixed>|null */
function org_payroll_get_run(PDO $dbh, int $orgId, int $runId): ?array
{
    if ($orgId <= 0 || $runId <= 0) {
        return null;
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_pay_runs WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $runId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function org_payroll_run_lines(PDO $dbh, int $orgId, int $runId): array
{
    if ($orgId <= 0 || $runId <= 0) {
        return [];
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_pay_run_lines
            WHERE pay_run_id = :run AND org_id = :org
            ORDER BY employee_name ASC, id ASC
        ");
        $st->execute([':run' => $runId, ':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Split a member's period hours into regular / overtime / paid-leave seconds.
 * Uses the time card breakdown when available (weekly 40h overtime rule).
 * @return array{regular_secs:int,overtime_secs:int,paid_leave_secs:int}
 */
function org_payroll_period_components(PDO $dbh, int $orgId, int $memberId, string $start, string $end, int $fallbackSecs = 0): array
{
    if (function_exists('org_timecard_period_breakdown')) {
        $b = org_timecard_period_breakdown($dbh, $orgId, $memberId, $start, $end);
        return [
            'regular_secs' => (int)($b['regular_secs'] ?? 0),
            'overtime_secs' => (int)($b['overtime_secs'] ?? 0),
            'paid_leave_secs' => (int)(($b['pto_secs'] ?? 0) + ($b['holiday_secs'] ?? 0) + ($b['vacation_secs'] ?? 0) + ($b['sick_secs'] ?? 0)),
        ];
    }
    return ['regular_secs' => max(0, $fallbackSecs), 'overtime_secs' => 0, 'paid_leave_secs' => 0];
}

/** @return array{ok:bool,error?:string,run_id?:int} */
function org_payroll_create_run(
    PDO $dbh,
    int $orgId,
    int $createdByMemberId,
    string $periodStart,
    string $periodEnd,
    string $label = '',
    bool $seedEmployees = true,
    string $payFrequency = 'monthly',
    int $onlyMemberId = 0
): array {
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organization.'];
    }
    org_payroll_ensure_schema($dbh);

    $payFrequency = org_payroll_normalize_frequency($payFrequency);
    $periodStart = trim($periodStart);
    $periodEnd = trim($periodEnd);
    // The pay period comes from the Time card, not from Pay runs. When no dates are
    // supplied, derive them from the employee's approved time cards; fall back to the
    // current calendar month if there are none yet.
    if ($periodStart === '' || $periodEnd === '') {
        $range = function_exists('org_timecard_approved_period_range')
            ? org_timecard_approved_period_range($dbh, $orgId, $onlyMemberId)
            : ['start' => null, 'end' => null];
        $periodStart = $periodStart !== '' ? $periodStart : (string)($range['start'] ?? date('Y-m-01'));
        $periodEnd = $periodEnd !== '' ? $periodEnd : (string)($range['end'] ?? date('Y-m-t'));
    }
    $tsStart = strtotime($periodStart);
    $tsEnd = strtotime($periodEnd);
    if ($tsStart === false || $tsEnd === false) {
        return ['ok' => false, 'error' => 'Invalid pay period dates.'];
    }
    if ($tsEnd < $tsStart) {
        return ['ok' => false, 'error' => 'Period end must be on or after period start.'];
    }

    $psDate = date('Y-m-d', $tsStart);
    $peDate = date('Y-m-d', $tsEnd);

    $label = trim($label);
    if ($label === '') {
        $label = org_payroll_frequency_label($payFrequency) . ' ' . date('M j', $tsStart) . ' – ' . date('M j, Y', $tsEnd);
    }

    // Worked hours from time cards for the period (for hourly employees).
    $hoursMap = [];
    if (function_exists('org_timecard_period_hours_map')) {
        $hoursMap = org_timecard_period_hours_map($dbh, $orgId, $psDate, $peDate);
    }

    try {
        $dbh->beginTransaction();
        $ins = $dbh->prepare("
            INSERT INTO org_pay_runs
              (org_id, created_by_member_id, label, pay_frequency, period_start, period_end, status, notes)
            VALUES
              (:org, :by, :label, :freq, :ps, :pe, 'draft', NULL)
        ");
        $ins->execute([
            ':org' => $orgId,
            ':by' => $createdByMemberId > 0 ? $createdByMemberId : null,
            ':label' => mb_substr($label, 0, 120),
            ':freq' => $payFrequency,
            ':ps' => $psDate,
            ':pe' => $peDate,
        ]);
        $runId = (int)$dbh->lastInsertId();
        if ($runId <= 0) {
            $dbh->rollBack();
            return ['ok' => false, 'error' => 'Could not create pay run.'];
        }

        $dbh->commit();

        // Seed itemized employee pay lines (Steps 7-11). Done after commit so
        // org_payroll_upsert_line() sees a persisted draft run.
        if ($seedEmployees) {
            $employees = org_payroll_list_employees($dbh, $orgId);
            foreach ($employees as $emp) {
                $type = strtolower((string)($emp['member_type'] ?? ''));
                $payType = strtolower((string)($emp['pay_type'] ?? 'salary'));
                $rateCents = (int)($emp['hourly_rate_cents'] ?? 0);
                $memberId = (int)$emp['org_member_id'];
                // When a single employee is requested, skip everyone else.
                if ($onlyMemberId > 0 && $memberId !== $onlyMemberId) {
                    continue;
                }
                $otEligible = (int)($emp['overtime_eligible'] ?? 1) === 1;

                $fallbackSecs = (int)($hoursMap[$memberId] ?? 0);
                $comp = org_payroll_period_components($dbh, $orgId, $memberId, $psDate, $peDate, $fallbackSecs);
                $regSecs = (int)$comp['regular_secs'];
                $otSecs = $otEligible ? (int)$comp['overtime_secs'] : 0;
                if (!$otEligible) {
                    $regSecs += (int)$comp['overtime_secs'];
                }
                $leaveSecs = (int)$comp['paid_leave_secs'];
                $hasHours = ($regSecs + $otSecs + $leaveSecs) > 0 || $fallbackSecs > 0;

                $hasProfile = ((int)($emp['default_gross_cents'] ?? 0) > 0)
                    || ((int)($emp['default_deductions_cents'] ?? 0) > 0)
                    || ((int)($emp['annual_salary_cents'] ?? 0) > 0)
                    || $rateCents > 0;
                // Auto-include on the pay run: hired staff, anyone with payroll set up,
                // and anyone who clocked approved hours in the period (e.g. working managers).
                // A specifically requested employee is always included.
                if ($onlyMemberId <= 0 && $type !== 'staff' && !$hasProfile && !$hasHours) {
                    continue;
                }

                $grossArr = ['regular' => 0, 'overtime' => 0, 'bonus' => 0, 'commission' => 0, 'holiday' => 0, 'vacation' => 0];
                if ($payType === 'hourly' && $rateCents > 0) {
                    // Regular = hours × rate; Overtime = OT hours × rate × 1.5; paid leave × rate.
                    $grossArr['regular'] = (int)round(($regSecs / 3600) * $rateCents);
                    $grossArr['overtime'] = (int)round(($otSecs / 3600) * $rateCents * 1.5);
                    $grossArr['vacation'] = (int)round(($leaveSecs / 3600) * $rateCents);
                } else {
                    $grossArr['regular'] = (int)($emp['default_gross_cents'] ?? 0);
                }
                $grossSum = array_sum($grossArr);

                // Cap default deductions at gross so the line is always seeded
                // (even at $0 gross before a rate is entered) instead of being rejected.
                $dedOther = min((int)($emp['default_deductions_cents'] ?? 0), $grossSum);
                $dedArr = [
                    'federal' => 0, 'state' => 0, 'health' => 0, 'dental' => 0, 'retirement' => 0,
                    'other' => max(0, $dedOther),
                ];

                org_payroll_upsert_line(
                    $dbh,
                    $orgId,
                    $runId,
                    $memberId,
                    $grossArr,
                    $dedArr,
                    null, // employer taxes auto-computed from gross
                    '',
                    $rateCents,
                    $regSecs + $otSecs,
                    $otSecs,
                    $leaveSecs
                );
            }
        }

        return ['ok' => true, 'run_id' => $runId];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not create pay run.'];
    }
}

/** Pay periods in a year for a frequency. */
function org_payroll_periods_per_year(string $freq): int
{
    switch (org_payroll_normalize_frequency($freq)) {
        case 'weekly': return 52;
        case 'bi_weekly': return 26;
        case 'monthly':
        default: return 12;
    }
}

/** @return array{ok:bool,error?:string} */
function org_payroll_save_profile(
    PDO $dbh,
    int $orgId,
    int $orgMemberId,
    string $payType,
    int $grossCents,
    int $deductionsCents,
    int $employerTaxCents,
    string $notes = '',
    int $hourlyRateCents = 0,
    string $payFrequency = 'monthly',
    int $annualSalaryCents = 0,
    string $taxStatus = 'single',
    string $bankName = '',
    bool $overtimeEligible = true
): array {
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid employee.'];
    }
    org_payroll_ensure_schema($dbh);

    $payType = strtolower(trim($payType));
    if (!in_array($payType, ['salary', 'hourly', 'commission'], true)) {
        $payType = 'salary';
    }
    $payFrequency = org_payroll_normalize_frequency($payFrequency);

    $taxStatus = strtolower(trim($taxStatus));
    if (!in_array($taxStatus, ['single', 'married', 'head'], true)) {
        $taxStatus = 'single';
    }
    $annualSalaryCents = max(0, $annualSalaryCents);

    // For salaried employees, derive per-period gross from the annual salary (Step 7).
    if ($payType === 'salary' && $annualSalaryCents > 0) {
        $periods = org_payroll_periods_per_year($payFrequency);
        $grossCents = (int)round($annualSalaryCents / max(1, $periods));
    }

    try {
        $chk = $dbh->prepare('SELECT id FROM org_members WHERE id = :id AND org_id = :org AND status = 1 LIMIT 1');
        $chk->execute([':id' => $orgMemberId, ':org' => $orgId]);
        if (!(int)$chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'Employee not found in this organization.'];
        }

        $st = $dbh->prepare("
            INSERT INTO org_payroll_profiles
              (org_id, org_member_id, pay_type, pay_frequency, hourly_rate_cents,
               default_gross_cents, default_deductions_cents, default_employer_tax_cents,
               annual_salary_cents, tax_status, bank_name, overtime_eligible, notes)
            VALUES
              (:org, :mid, :pt, :freq, :rate, :g, :d, :e, :ann, :tax, :bank, :ot, :n)
            ON DUPLICATE KEY UPDATE
              pay_type = VALUES(pay_type),
              pay_frequency = VALUES(pay_frequency),
              hourly_rate_cents = VALUES(hourly_rate_cents),
              default_gross_cents = VALUES(default_gross_cents),
              default_deductions_cents = VALUES(default_deductions_cents),
              default_employer_tax_cents = VALUES(default_employer_tax_cents),
              annual_salary_cents = VALUES(annual_salary_cents),
              tax_status = VALUES(tax_status),
              bank_name = VALUES(bank_name),
              overtime_eligible = VALUES(overtime_eligible),
              notes = VALUES(notes),
              updated_at = CURRENT_TIMESTAMP
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':pt' => $payType,
            ':freq' => $payFrequency,
            ':rate' => max(0, $hourlyRateCents),
            ':g' => max(0, $grossCents),
            ':d' => max(0, $deductionsCents),
            ':e' => max(0, $employerTaxCents),
            ':ann' => $annualSalaryCents,
            ':tax' => $taxStatus,
            ':bank' => mb_substr(trim($bankName), 0, 120),
            ':ot' => $overtimeEligible ? 1 : 0,
            ':n' => mb_substr(trim($notes), 0, 500),
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save pay defaults.'];
    }
}

/**
 * Save an itemized pay line (Steps 7-11).
 *
 * @param array<string,int> $gross      keys: regular, overtime, bonus, commission, holiday, vacation (cents)
 * @param array<string,int> $deductions keys: federal, state, health, dental, retirement, other (cents)
 * @param array<string,int>|null $employerTax keys: social, medicare, unemp, workers (cents); null = auto from gross
 * @return array{ok:bool,error?:string}
 */
function org_payroll_upsert_line(
    PDO $dbh,
    int $orgId,
    int $runId,
    int $orgMemberId,
    array $gross,
    array $deductions,
    ?array $employerTax = null,
    string $lineNote = '',
    ?int $hourlyRateCents = null,
    ?int $workedSeconds = null,
    ?int $overtimeSeconds = null,
    ?int $paidLeaveSeconds = null
): array {
    if ($orgId <= 0 || $runId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid pay line.'];
    }
    org_payroll_ensure_schema($dbh);

    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    if (strtolower((string)($run['status'] ?? '')) !== 'draft') {
        return ['ok' => false, 'error' => 'This pay run is locked (approved or paid) and cannot be edited.'];
    }

    $ci = static fn($arr, $k) => max(0, (int)round((float)($arr[$k] ?? 0)));

    $gRegular = $ci($gross, 'regular');
    $gOvertime = $ci($gross, 'overtime');
    $gBonus = $ci($gross, 'bonus');
    $gCommission = $ci($gross, 'commission');
    $gHoliday = $ci($gross, 'holiday');
    $gVacation = $ci($gross, 'vacation');
    $grossCents = $gRegular + $gOvertime + $gBonus + $gCommission + $gHoliday + $gVacation;

    $dFederal = $ci($deductions, 'federal');
    $dState = $ci($deductions, 'state');
    $dHealth = $ci($deductions, 'health');
    $dDental = $ci($deductions, 'dental');
    $dRetire = $ci($deductions, 'retirement');
    $dOther = $ci($deductions, 'other');
    $deductionsCents = $dFederal + $dState + $dHealth + $dDental + $dRetire + $dOther;

    if ($deductionsCents > $grossCents) {
        return ['ok' => false, 'error' => 'Deductions cannot exceed gross pay.'];
    }
    $netCents = $grossCents - $deductionsCents;

    // Employer taxes: use provided values, or auto-compute from gross (Step 11).
    if ($employerTax === null) {
        $et = org_payroll_compute_employer_taxes($grossCents);
    } else {
        $et = [
            'social' => $ci($employerTax, 'social'),
            'medicare' => $ci($employerTax, 'medicare'),
            'unemp' => $ci($employerTax, 'unemp'),
            'workers' => $ci($employerTax, 'workers'),
        ];
    }
    $employerTaxCents = (int)($et['social'] + $et['medicare'] + $et['unemp'] + $et['workers']);

    $rateCents = $hourlyRateCents !== null ? max(0, $hourlyRateCents) : 0;
    $workedSeconds = max(0, (int)($workedSeconds ?? 0));
    $overtimeSeconds = max(0, (int)($overtimeSeconds ?? 0));
    $paidLeaveSeconds = max(0, (int)($paidLeaveSeconds ?? 0));

    try {
        $empSt = $dbh->prepare("
            SELECT
              om.id,
              om.member_type,
              COALESCE(m.fullname, s.fullname, m.username, s.username, 'Employee') AS name
            FROM org_members om
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE om.id = :id AND om.org_id = :org AND om.status = 1
            LIMIT 1
        ");
        $empSt->execute([':id' => $orgMemberId, ':org' => $orgId]);
        $emp = $empSt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            return ['ok' => false, 'error' => 'Employee not found.'];
        }

        $st = $dbh->prepare("
            INSERT INTO org_pay_run_lines
              (pay_run_id, org_id, org_member_id, employee_name, employee_role,
               worked_seconds, overtime_seconds, paid_leave_seconds, hourly_rate_cents,
               regular_cents, overtime_cents, bonus_cents, commission_cents, holiday_cents, vacation_cents,
               gross_cents,
               ded_federal_cents, ded_state_cents, ded_health_cents, ded_dental_cents, ded_retirement_cents, ded_other_cents,
               deductions_cents, net_cents,
               etax_social_cents, etax_medicare_cents, etax_unemp_cents, etax_workers_cents, employer_tax_cents,
               line_note)
            VALUES
              (:run, :org, :mid, :name, :role,
               :secs, :otsecs, :plsecs, :rate,
               :greg, :got, :gbonus, :gcomm, :ghol, :gvac,
               :gross,
               :dfed, :dstate, :dhealth, :ddental, :dretire, :dother,
               :ded, :net,
               :etsoc, :etmed, :etunemp, :etwork, :etax,
               :lnote)
            ON DUPLICATE KEY UPDATE
              employee_name = VALUES(employee_name),
              employee_role = VALUES(employee_role),
              worked_seconds = VALUES(worked_seconds),
              overtime_seconds = VALUES(overtime_seconds),
              paid_leave_seconds = VALUES(paid_leave_seconds),
              hourly_rate_cents = VALUES(hourly_rate_cents),
              regular_cents = VALUES(regular_cents),
              overtime_cents = VALUES(overtime_cents),
              bonus_cents = VALUES(bonus_cents),
              commission_cents = VALUES(commission_cents),
              holiday_cents = VALUES(holiday_cents),
              vacation_cents = VALUES(vacation_cents),
              gross_cents = VALUES(gross_cents),
              ded_federal_cents = VALUES(ded_federal_cents),
              ded_state_cents = VALUES(ded_state_cents),
              ded_health_cents = VALUES(ded_health_cents),
              ded_dental_cents = VALUES(ded_dental_cents),
              ded_retirement_cents = VALUES(ded_retirement_cents),
              ded_other_cents = VALUES(ded_other_cents),
              deductions_cents = VALUES(deductions_cents),
              net_cents = VALUES(net_cents),
              etax_social_cents = VALUES(etax_social_cents),
              etax_medicare_cents = VALUES(etax_medicare_cents),
              etax_unemp_cents = VALUES(etax_unemp_cents),
              etax_workers_cents = VALUES(etax_workers_cents),
              employer_tax_cents = VALUES(employer_tax_cents),
              line_note = VALUES(line_note),
              updated_at = CURRENT_TIMESTAMP
        ");
        $st->execute([
            ':run' => $runId,
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':name' => mb_substr(trim((string)($emp['name'] ?? 'Employee')), 0, 190),
            ':role' => mb_substr(strtolower((string)($emp['member_type'] ?? 'staff')), 0, 40),
            ':secs' => $workedSeconds,
            ':otsecs' => $overtimeSeconds,
            ':plsecs' => $paidLeaveSeconds,
            ':rate' => $rateCents,
            ':greg' => $gRegular,
            ':got' => $gOvertime,
            ':gbonus' => $gBonus,
            ':gcomm' => $gCommission,
            ':ghol' => $gHoliday,
            ':gvac' => $gVacation,
            ':gross' => $grossCents,
            ':dfed' => $dFederal,
            ':dstate' => $dState,
            ':dhealth' => $dHealth,
            ':ddental' => $dDental,
            ':dretire' => $dRetire,
            ':dother' => $dOther,
            ':ded' => $deductionsCents,
            ':net' => $netCents,
            ':etsoc' => (int)$et['social'],
            ':etmed' => (int)$et['medicare'],
            ':etunemp' => (int)$et['unemp'],
            ':etwork' => (int)$et['workers'],
            ':etax' => $employerTaxCents,
            ':lnote' => mb_substr(trim($lineNote), 0, 255),
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save pay line.'];
    }
}

function org_timecard_worked_seconds_available(): bool
{
    return function_exists('org_timecard_worked_seconds_for_period');
}

/** @return array{ok:bool,error?:string} */
function org_payroll_delete_line(PDO $dbh, int $orgId, int $runId, int $lineId): array
{
    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    if (strtolower((string)($run['status'] ?? '')) !== 'draft') {
        return ['ok' => false, 'error' => 'Only draft pay runs can be changed. Reopen it first.'];
    }
    try {
        $st = $dbh->prepare('DELETE FROM org_pay_run_lines WHERE id = :id AND pay_run_id = :run AND org_id = :org LIMIT 1');
        $st->execute([':id' => $lineId, ':run' => $runId, ':org' => $orgId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove pay line.'];
    }
}

/**
 * Approve a draft pay run (Steps 12-14: quality check + HR/Finance sign-off).
 * @return array{ok:bool,error?:string}
 */
function org_payroll_approve_run(PDO $dbh, int $orgId, int $runId, int $approvedByMemberId = 0): array
{
    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    $status = strtolower((string)($run['status'] ?? ''));
    if ($status === 'paid') {
        return ['ok' => false, 'error' => 'This pay run is already paid.'];
    }
    if ($status === 'approved') {
        return ['ok' => true];
    }
    $lines = org_payroll_run_lines($dbh, $orgId, $runId);
    if (!$lines) {
        return ['ok' => false, 'error' => 'Add at least one employee pay line before approving.'];
    }
    foreach ($lines as $line) {
        if ((int)($line['gross_cents'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'Every employee needs a gross pay amount before approving.'];
        }
    }
    try {
        $st = $dbh->prepare("
            UPDATE org_pay_runs
            SET status = 'approved', approved_at = NOW(), approved_by_member_id = :by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ");
        $st->execute([':by' => $approvedByMemberId > 0 ? $approvedByMemberId : null, ':id' => $runId, ':org' => $orgId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not approve pay run.'];
    }
}

/** Reopen an approved (not paid) run back to draft for edits. @return array{ok:bool,error?:string} */
function org_payroll_reopen_run(PDO $dbh, int $orgId, int $runId): array
{
    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    if (strtolower((string)($run['status'] ?? '')) === 'paid') {
        return ['ok' => false, 'error' => 'Paid pay runs cannot be reopened.'];
    }
    try {
        $st = $dbh->prepare("
            UPDATE org_pay_runs
            SET status = 'draft', approved_at = NULL, approved_by_member_id = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND org_id = :org LIMIT 1
        ");
        $st->execute([':id' => $runId, ':org' => $orgId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not reopen pay run.'];
    }
}

/**
 * Re-pull approved time card hours into a draft run's lines.
 * Timecard-driven pay (regular/overtime/paid-leave) is refreshed; manually entered
 * bonus/commission/holiday and deductions on existing lines are preserved.
 * @return array{ok:bool,error?:string,count?:int}
 */
function org_payroll_refresh_run(PDO $dbh, int $orgId, int $runId): array
{
    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    if (strtolower((string)($run['status'] ?? '')) !== 'draft') {
        return ['ok' => false, 'error' => 'Only draft pay runs can be refreshed. Reopen it first.'];
    }

    $psDate = (string)($run['period_start'] ?? '');
    $peDate = (string)($run['period_end'] ?? '');

    $hoursMap = function_exists('org_timecard_period_hours_map')
        ? org_timecard_period_hours_map($dbh, $orgId, $psDate, $peDate)
        : [];

    // Index existing lines by employee to preserve manual entries.
    $existingByMember = [];
    foreach (org_payroll_run_lines($dbh, $orgId, $runId) as $ln) {
        $existingByMember[(int)($ln['org_member_id'] ?? 0)] = $ln;
    }

    $count = 0;
    foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
        $type = strtolower((string)($emp['member_type'] ?? ''));
        $payType = strtolower((string)($emp['pay_type'] ?? 'salary'));
        $rateCents = (int)($emp['hourly_rate_cents'] ?? 0);
        $memberId = (int)$emp['org_member_id'];
        $otEligible = (int)($emp['overtime_eligible'] ?? 1) === 1;
        $existing = $existingByMember[$memberId] ?? null;

        $fallbackSecs = (int)($hoursMap[$memberId] ?? 0);
        $comp = org_payroll_period_components($dbh, $orgId, $memberId, $psDate, $peDate, $fallbackSecs);
        $regSecs = (int)$comp['regular_secs'];
        $otSecs = $otEligible ? (int)$comp['overtime_secs'] : 0;
        if (!$otEligible) {
            $regSecs += (int)$comp['overtime_secs'];
        }
        $leaveSecs = (int)$comp['paid_leave_secs'];
        $hasHours = ($regSecs + $otSecs + $leaveSecs) > 0 || $fallbackSecs > 0;

        $hasProfile = ((int)($emp['default_gross_cents'] ?? 0) > 0)
            || ((int)($emp['default_deductions_cents'] ?? 0) > 0)
            || ((int)($emp['annual_salary_cents'] ?? 0) > 0)
            || $rateCents > 0;
        if ($type !== 'staff' && !$hasProfile && !$hasHours && !$existing) {
            continue;
        }

        // Timecard-driven earnings.
        $grossArr = ['regular' => 0, 'overtime' => 0, 'bonus' => 0, 'commission' => 0, 'holiday' => 0, 'vacation' => 0];
        if ($payType === 'hourly' && $rateCents > 0) {
            $grossArr['regular'] = (int)round(($regSecs / 3600) * $rateCents);
            $grossArr['overtime'] = (int)round(($otSecs / 3600) * $rateCents * 1.5);
            $grossArr['vacation'] = (int)round(($leaveSecs / 3600) * $rateCents);
        } else {
            $grossArr['regular'] = $existing
                ? (int)($existing['regular_cents'] ?? 0)
                : (int)($emp['default_gross_cents'] ?? 0);
            if ($existing) {
                $grossArr['vacation'] = (int)($existing['vacation_cents'] ?? 0);
            }
        }
        // Preserve manually entered extras.
        if ($existing) {
            $grossArr['bonus'] = (int)($existing['bonus_cents'] ?? 0);
            $grossArr['commission'] = (int)($existing['commission_cents'] ?? 0);
            $grossArr['holiday'] = (int)($existing['holiday_cents'] ?? 0);
        }
        $grossSum = array_sum($grossArr);

        // Preserve existing deductions, else seed from profile default.
        if ($existing) {
            $dedArr = [
                'federal' => (int)($existing['ded_federal_cents'] ?? 0),
                'state' => (int)($existing['ded_state_cents'] ?? 0),
                'health' => (int)($existing['ded_health_cents'] ?? 0),
                'dental' => (int)($existing['ded_dental_cents'] ?? 0),
                'retirement' => (int)($existing['ded_retirement_cents'] ?? 0),
                'other' => (int)($existing['ded_other_cents'] ?? 0),
            ];
        } else {
            $dedArr = ['federal' => 0, 'state' => 0, 'health' => 0, 'dental' => 0, 'retirement' => 0, 'other' => (int)($emp['default_deductions_cents'] ?? 0)];
        }
        // Trim deductions so they never exceed the (possibly lower) refreshed gross.
        $overflow = array_sum($dedArr) - $grossSum;
        if ($overflow > 0) {
            foreach (['other', 'retirement', 'dental', 'health', 'state', 'federal'] as $k) {
                if ($overflow <= 0) {
                    break;
                }
                $take = min($dedArr[$k], $overflow);
                $dedArr[$k] -= $take;
                $overflow -= $take;
            }
        }

        $lineNote = $existing ? (string)($existing['line_note'] ?? '') : '';

        $res = org_payroll_upsert_line(
            $dbh,
            $orgId,
            $runId,
            $memberId,
            $grossArr,
            $dedArr,
            null,
            $lineNote,
            $rateCents,
            $regSecs + $otSecs,
            $otSecs,
            $leaveSecs
        );
        if (!empty($res['ok'])) {
            $count++;
        }
    }

    return ['ok' => true, 'count' => $count];
}

/** @return array{ok:bool,error?:string} */
function org_payroll_mark_paid(PDO $dbh, int $orgId, int $runId): array
{
    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    $status = strtolower((string)($run['status'] ?? ''));
    if ($status === 'paid') {
        return ['ok' => true];
    }
    if ($status !== 'approved') {
        return ['ok' => false, 'error' => 'Approve the pay run before marking it paid.'];
    }
    $lines = org_payroll_run_lines($dbh, $orgId, $runId);
    if (!$lines) {
        return ['ok' => false, 'error' => 'Add at least one employee pay line before marking paid.'];
    }
    foreach ($lines as $line) {
        if ((int)($line['gross_cents'] ?? 0) <= 0 && (int)($line['net_cents'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'Every employee needs a gross pay amount before marking paid.'];
        }
    }
    try {
        $st = $dbh->prepare("
            UPDATE org_pay_runs
            SET status = 'paid', paid_at = NOW(), updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ");
        $st->execute([':id' => $runId, ':org' => $orgId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not mark pay run as paid.'];
    }
}

/** @return array{ok:bool,error?:string} */
function org_payroll_delete_run(PDO $dbh, int $orgId, int $runId): array
{
    $run = org_payroll_get_run($dbh, $orgId, $runId);
    if (!$run) {
        return ['ok' => false, 'error' => 'Pay run not found.'];
    }
    if (strtolower((string)($run['status'] ?? '')) !== 'draft') {
        return ['ok' => false, 'error' => 'Only draft pay runs can be deleted. Reopen it first.'];
    }
    try {
        $dbh->beginTransaction();
        $dbh->prepare('DELETE FROM org_pay_run_lines WHERE pay_run_id = :run AND org_id = :org')
            ->execute([':run' => $runId, ':org' => $orgId]);
        $dbh->prepare('DELETE FROM org_pay_runs WHERE id = :id AND org_id = :org LIMIT 1')
            ->execute([':id' => $runId, ':org' => $orgId]);
        $dbh->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not delete pay run.'];
    }
}

/** @return array<string,mixed>|null A pay line joined with its run (for pay stubs). */
function org_payroll_get_line(PDO $dbh, int $orgId, int $lineId): ?array
{
    if ($orgId <= 0 || $lineId <= 0) {
        return null;
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT l.*, r.label AS run_label, r.period_start, r.period_end,
                   r.pay_frequency, r.status AS run_status, r.paid_at, r.approved_at
            FROM org_pay_run_lines l
            INNER JOIN org_pay_runs r ON r.id = l.pay_run_id
            WHERE l.id = :id AND l.org_id = :org
            LIMIT 1
        ");
        $st->execute([':id' => $lineId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Year-to-date paid totals for one employee (Step 16 pay stub).
 * @return array{gross:int,deductions:int,net:int,employer_tax:int}
 */
function org_payroll_ytd_for_member(PDO $dbh, int $orgId, int $orgMemberId, int $year): array
{
    $out = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'employer_tax' => 0];
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return $out;
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT
              COALESCE(SUM(l.gross_cents),0) AS g,
              COALESCE(SUM(l.deductions_cents),0) AS d,
              COALESCE(SUM(l.net_cents),0) AS n,
              COALESCE(SUM(l.employer_tax_cents),0) AS e
            FROM org_pay_run_lines l
            INNER JOIN org_pay_runs r ON r.id = l.pay_run_id
            WHERE l.org_id = :org AND l.org_member_id = :mid
              AND r.status = 'paid' AND YEAR(r.period_end) = :yr
        ");
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId, ':yr' => $year]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['gross'] = (int)($row['g'] ?? 0);
        $out['deductions'] = (int)($row['d'] ?? 0);
        $out['net'] = (int)($row['n'] ?? 0);
        $out['employer_tax'] = (int)($row['e'] ?? 0);
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * @return array{employees:int,open_runs:int,paid_runs:int,net_paid_cents:int,employer_tax_cents:int}
 */
function org_payroll_dashboard_stats(PDO $dbh, int $orgId): array
{
    $out = [
        'employees' => 0,
        'open_runs' => 0,
        'paid_runs' => 0,
        'net_paid_cents' => 0,
        'employer_tax_cents' => 0,
    ];
    if ($orgId <= 0) {
        return $out;
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = :org AND status = 1 AND member_type = 'staff'");
        $st->execute([':org' => $orgId]);
        $out['employees'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_pay_runs WHERE org_id = :org AND status IN ('draft','approved')");
        $st->execute([':org' => $orgId]);
        $out['open_runs'] = (int)$st->fetchColumn();
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_pay_runs WHERE org_id = :org AND status = 'paid'");
        $st->execute([':org' => $orgId]);
        $out['paid_runs'] = (int)$st->fetchColumn();
        $st = $dbh->prepare("
            SELECT
              COALESCE(SUM(l.net_cents), 0),
              COALESCE(SUM(l.employer_tax_cents), 0)
            FROM org_pay_run_lines l
            INNER JOIN org_pay_runs r ON r.id = l.pay_run_id
            WHERE r.org_id = :org AND r.status = 'paid'
        ");
        $st->execute([':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $out['net_paid_cents'] = (int)($row[0] ?? 0);
        $out['employer_tax_cents'] = (int)($row[1] ?? 0);
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}
