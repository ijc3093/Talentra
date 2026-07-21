<?php
declare(strict_types=1);

/**
 * Employee HR / bank detail helpers for detail_employee.php.
 * Pay rates stay in org_payroll_profiles; profile extras live here.
 */

require_once __DIR__ . '/org_payroll.php';

function org_employee_detail_column_exists(PDO $dbh, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = $dbh->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE :c');
        $st->execute([':c' => $col]);
        $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function org_employee_detail_add_column(PDO $dbh, string $table, string $col, string $ddl): void
{
    if (org_employee_detail_column_exists($dbh, $table, $col)) {
        return;
    }
    try {
        $dbh->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $col) . '` ' . $ddl);
    } catch (Throwable $e) {
        // ignore
    }
}

function org_employee_detail_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_employee_details (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              org_id BIGINT UNSIGNED NOT NULL,
              org_member_id BIGINT UNSIGNED NOT NULL,
              phone VARCHAR(40) NOT NULL DEFAULT '',
              job_id VARCHAR(60) NOT NULL DEFAULT '',
              employment_status VARCHAR(40) NOT NULL DEFAULT 'full_time',
              department VARCHAR(120) NOT NULL DEFAULT '',
              supervisor_name VARCHAR(160) NOT NULL DEFAULT '',
              dob DATE NULL,
              gender VARCHAR(30) NOT NULL DEFAULT '',
              blood_group VARCHAR(10) NOT NULL DEFAULT '',
              tin VARCHAR(80) NOT NULL DEFAULT '',
              bank_account_holder VARCHAR(160) NOT NULL DEFAULT '',
              bank_account_number VARCHAR(80) NOT NULL DEFAULT '',
              bank_branch VARCHAR(160) NOT NULL DEFAULT '',
              bank_routing VARCHAR(40) NOT NULL DEFAULT '',
              bank_swift VARCHAR(40) NOT NULL DEFAULT '',
              self_service_enabled TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_org_employee_detail_member (org_id, org_member_id),
              KEY idx_org_employee_details_org (org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    // Bank name stays on payroll profile; ensure account fields exist if table was created earlier.
    foreach ([
        'phone' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'job_id' => "VARCHAR(60) NOT NULL DEFAULT ''",
        'employment_status' => "VARCHAR(40) NOT NULL DEFAULT 'full_time'",
        'department' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'supervisor_name' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'dob' => 'DATE NULL',
        'gender' => "VARCHAR(30) NOT NULL DEFAULT ''",
        'blood_group' => "VARCHAR(10) NOT NULL DEFAULT ''",
        'tin' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'bank_account_holder' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'bank_account_number' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'bank_branch' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'bank_routing' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'bank_swift' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'self_service_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ] as $col => $ddl) {
        org_employee_detail_add_column($dbh, 'org_employee_details', $col, $ddl);
    }
}

/** @return array<string,mixed> */
function org_employee_detail_get(PDO $dbh, int $orgId, int $orgMemberId): array
{
    org_employee_detail_ensure_schema($dbh);
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return [];
    }
    try {
        $st = $dbh->prepare('SELECT * FROM org_employee_details WHERE org_id = :org AND org_member_id = :mid LIMIT 1');
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string,mixed> $fields
 * @return array{ok:bool,error?:string}
 */
function org_employee_detail_save(PDO $dbh, int $orgId, int $orgMemberId, array $fields): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid employee.'];
    }
    org_employee_detail_ensure_schema($dbh);

    $clip = static function ($v, int $max): string {
        return mb_substr(trim((string)$v), 0, $max);
    };

    $status = strtolower(str_replace([' ', '-'], '_', $clip($fields['employment_status'] ?? 'full_time', 40)));
    if (!in_array($status, ['full_time', 'part_time', 'contract', 'intern', 'temporary'], true)) {
        $status = 'full_time';
    }

    $dobRaw = trim((string)($fields['dob'] ?? ''));
    $dob = null;
    if ($dobRaw !== '') {
        $ts = strtotime($dobRaw);
        if ($ts !== false) {
            $dob = date('Y-m-d', $ts);
        }
    }

    $selfService = !empty($fields['self_service_enabled']) ? 1 : 0;

    try {
        $st = $dbh->prepare("
            INSERT INTO org_employee_details
              (org_id, org_member_id, phone, job_id, employment_status, department, supervisor_name,
               dob, gender, blood_group, tin,
               bank_account_holder, bank_account_number, bank_branch, bank_routing, bank_swift,
               self_service_enabled)
            VALUES
              (:org, :mid, :phone, :job, :status, :dept, :sup,
               :dob, :gender, :blood, :tin,
               :holder, :acct, :branch, :routing, :swift,
               :ss)
            ON DUPLICATE KEY UPDATE
              phone = VALUES(phone),
              job_id = VALUES(job_id),
              employment_status = VALUES(employment_status),
              department = VALUES(department),
              supervisor_name = VALUES(supervisor_name),
              dob = VALUES(dob),
              gender = VALUES(gender),
              blood_group = VALUES(blood_group),
              tin = VALUES(tin),
              bank_account_holder = VALUES(bank_account_holder),
              bank_account_number = VALUES(bank_account_number),
              bank_branch = VALUES(bank_branch),
              bank_routing = VALUES(bank_routing),
              bank_swift = VALUES(bank_swift),
              self_service_enabled = VALUES(self_service_enabled),
              updated_at = CURRENT_TIMESTAMP
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':phone' => $clip($fields['phone'] ?? '', 40),
            ':job' => $clip($fields['job_id'] ?? '', 60),
            ':status' => $status,
            ':dept' => $clip($fields['department'] ?? '', 120),
            ':sup' => $clip($fields['supervisor_name'] ?? '', 160),
            ':dob' => $dob,
            ':gender' => $clip($fields['gender'] ?? '', 30),
            ':blood' => $clip($fields['blood_group'] ?? '', 10),
            ':tin' => $clip($fields['tin'] ?? '', 80),
            ':holder' => $clip($fields['bank_account_holder'] ?? '', 160),
            ':acct' => $clip($fields['bank_account_number'] ?? '', 80),
            ':branch' => $clip($fields['bank_branch'] ?? '', 160),
            ':routing' => $clip($fields['bank_routing'] ?? '', 40),
            ':swift' => $clip($fields['bank_swift'] ?? '', 40),
            ':ss' => $selfService,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save employee details.'];
    }
}

function org_employee_detail_status_label(string $status): string
{
    $map = [
        'full_time' => 'Full-time',
        'part_time' => 'Part-time',
        'contract' => 'Contract',
        'intern' => 'Intern',
        'temporary' => 'Temporary',
    ];
    $key = strtolower(str_replace([' ', '-'], '_', trim($status)));
    return $map[$key] ?? (ucfirst(str_replace('_', ' ', $key)) ?: '—');
}

function org_employee_detail_service_length(?string $joinedAt): string
{
    if (!$joinedAt) {
        return '—';
    }
    $start = strtotime($joinedAt);
    if ($start <= 0) {
        return '—';
    }
    $now = new DateTimeImmutable('today');
    $then = (new DateTimeImmutable('@' . $start))->setTimezone($now->getTimezone());
    $diff = $then->diff($now);
    $parts = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . ' yr' . ($diff->y === 1 ? '' : 's');
    }
    if ($diff->m > 0) {
        $parts[] = $diff->m . ' mon';
    }
    if ($diff->d > 0 || !$parts) {
        $parts[] = $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
    }
    return implode(' ', $parts);
}

/**
 * Load staff or manager org member + payroll profile for detail views.
 * @return array<string,mixed>|null
 */
function org_employee_detail_load_member(PDO $dbh, int $orgId, int $memberId): ?array
{
    if ($orgId <= 0 || $memberId <= 0) {
        return null;
    }
    org_payroll_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT
              om.id AS org_member_id,
              om.member_type,
              om.member_id AS account_id,
              om.relationship_label,
              om.role_id,
              om.status AS member_status,
              COALESCE(om.joined_at, s.created_at) AS member_since,
              COALESCE(s.fullname, m.fullname, '') AS fullname,
              COALESCE(s.username, m.username, '') AS username,
              COALESCE(s.email, m.email, '') AS email,
              COALESCE(s.friend_code, '') AS friend_code,
              COALESCE(s.last_seen, m.last_seen) AS last_seen,
              COALESCE(s.created_at, om.joined_at) AS account_created,
              COALESCE(pp.pay_type, 'hourly') AS pay_type,
              COALESCE(pp.pay_frequency, 'monthly') AS pay_frequency,
              COALESCE(pp.hourly_rate_cents, 0) AS hourly_rate_cents,
              COALESCE(pp.expected_weekly_hours, 40) AS expected_weekly_hours,
              COALESCE(pp.default_gross_cents, 0) AS default_gross_cents,
              COALESCE(pp.default_deductions_cents, 0) AS default_deductions_cents,
              COALESCE(pp.default_employer_tax_cents, 0) AS default_employer_tax_cents,
              COALESCE(pp.annual_salary_cents, 0) AS annual_salary_cents,
              COALESCE(pp.tax_status, 'single') AS tax_status,
              COALESCE(pp.bank_name, '') AS bank_name,
              COALESCE(pp.overtime_eligible, 1) AS overtime_eligible,
              COALESCE(pp.notes, '') AS profile_notes
            FROM org_members om
            LEFT JOIN staff_accounts s
              ON om.member_type = 'staff' AND s.id = om.member_id
            LEFT JOIN managers m
              ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN org_payroll_profiles pp
              ON pp.org_id = om.org_id AND pp.org_member_id = om.id
            WHERE om.id = :id
              AND om.org_id = :org
              AND om.status = 1
              AND om.member_type IN ('staff', 'manager')
            LIMIT 1
        ");
        $st->execute([':id' => $memberId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
