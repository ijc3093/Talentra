<?php
declare(strict_types=1);

/**
 * Home / mailing addresses for org members (managers and staff).
 * Lets a manager keep a mailing directory so letters can be posted to an
 * employee's home address.
 */

function org_member_address_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_member_addresses (
              id INT AUTO_INCREMENT PRIMARY KEY,
              org_id INT NOT NULL,
              org_member_id INT NOT NULL,
              recipient_name VARCHAR(160) NOT NULL DEFAULT '',
              line1 VARCHAR(200) NOT NULL DEFAULT '',
              line2 VARCHAR(200) NOT NULL DEFAULT '',
              city VARCHAR(120) NOT NULL DEFAULT '',
              state VARCHAR(120) NOT NULL DEFAULT '',
              postal_code VARCHAR(40) NOT NULL DEFAULT '',
              country VARCHAR(120) NOT NULL DEFAULT '',
              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_org_member_addr (org_id, org_member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore — callers degrade gracefully
    }
}

/**
 * Active org members (managers first, then staff) with display names.
 * @return list<array<string,mixed>>
 */
function org_member_address_list_members(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    try {
        $st = $dbh->prepare("
            SELECT
              om.id AS org_member_id,
              om.member_type,
              om.relationship_label,
              COALESCE(m.fullname, s.fullname, m.username, s.username, 'Team member') AS name,
              COALESCE(m.email, s.email, '') AS email
            FROM org_members om
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE om.org_id = :org AND om.status = 1
            ORDER BY
              CASE WHEN om.member_type = 'manager' THEN 0 ELSE 1 END,
              name ASC
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Saved addresses keyed by org_member_id.
 * @return array<int,array<string,mixed>>
 */
function org_member_address_map(PDO $dbh, int $orgId): array
{
    $out = [];
    if ($orgId <= 0) {
        return $out;
    }
    org_member_address_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_member_addresses WHERE org_id = :org');
        $st->execute([':org' => $orgId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)($row['org_member_id'] ?? 0)] = $row;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/** One member's saved address (or null). @return array<string,mixed>|null */
function org_member_address_get(PDO $dbh, int $orgId, int $orgMemberId): ?array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return null;
    }
    org_member_address_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_member_addresses WHERE org_id = :org AND org_member_id = :mid LIMIT 1');
        $st->execute([':org' => $orgId, ':mid' => $orgMemberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Insert/update one member's home address.
 * @param array<string,string> $fields recipient_name, line1, line2, city, state, postal_code, country
 * @return array{ok:bool,error?:string}
 */
function org_member_address_save(PDO $dbh, int $orgId, int $orgMemberId, array $fields): array
{
    if ($orgId <= 0 || $orgMemberId <= 0) {
        return ['ok' => false, 'error' => 'Invalid member.'];
    }
    org_member_address_ensure_schema($dbh);

    $clip = static function ($v, int $max): string {
        return mb_substr(trim((string)$v), 0, $max);
    };
    $recipient = $clip($fields['recipient_name'] ?? '', 160);
    $line1 = $clip($fields['line1'] ?? '', 200);
    $line2 = $clip($fields['line2'] ?? '', 200);
    $city = $clip($fields['city'] ?? '', 120);
    $state = $clip($fields['state'] ?? '', 120);
    $postal = $clip($fields['postal_code'] ?? '', 40);
    $country = $clip($fields['country'] ?? '', 120);

    try {
        $st = $dbh->prepare("
            INSERT INTO org_member_addresses
              (org_id, org_member_id, recipient_name, line1, line2, city, state, postal_code, country)
            VALUES
              (:org, :mid, :rn, :l1, :l2, :city, :state, :postal, :country)
            ON DUPLICATE KEY UPDATE
              recipient_name = VALUES(recipient_name),
              line1 = VALUES(line1),
              line2 = VALUES(line2),
              city = VALUES(city),
              state = VALUES(state),
              postal_code = VALUES(postal_code),
              country = VALUES(country),
              updated_at = CURRENT_TIMESTAMP
        ");
        $st->execute([
            ':org' => $orgId,
            ':mid' => $orgMemberId,
            ':rn' => $recipient,
            ':l1' => $line1,
            ':l2' => $line2,
            ':city' => $city,
            ':state' => $state,
            ':postal' => $postal,
            ':country' => $country,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save address.'];
    }
}

/** Format an address row as a single mailing block string. */
function org_member_address_format(array $row): string
{
    $parts = [];
    $name = trim((string)($row['recipient_name'] ?? ''));
    if ($name !== '') {
        $parts[] = $name;
    }
    foreach (['line1', 'line2'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    $cityLine = trim(implode(', ', array_filter([
        trim((string)($row['city'] ?? '')),
        trim(implode(' ', array_filter([
            trim((string)($row['state'] ?? '')),
            trim((string)($row['postal_code'] ?? '')),
        ]))),
    ])));
    if ($cityLine !== '') {
        $parts[] = $cityLine;
    }
    $country = trim((string)($row['country'] ?? ''));
    if ($country !== '') {
        $parts[] = $country;
    }
    return implode("\n", $parts);
}
