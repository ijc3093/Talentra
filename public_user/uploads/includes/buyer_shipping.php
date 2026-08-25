<?php
declare(strict_types=1);

/**
 * Buyer shipping addresses — shared between preferences and checkout.
 */

function buyer_shipping_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/msb_migrations.php';
    msb_run_sql_migration_file(
        $dbh,
        dirname(__DIR__, 2) . '/Data/migrations/20260714_buyer_commerce_bridge.sql'
    );
}

/** @return list<array<string, mixed>> */
function buyer_shipping_list(PDO $dbh, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    buyer_shipping_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT * FROM buyer_shipping_addresses
            WHERE user_id = :uid
            ORDER BY is_default DESC, updated_at DESC, id DESC
        ');
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function buyer_shipping_get(PDO $dbh, int $userId, int $addressId): ?array
{
    if ($userId <= 0 || $addressId <= 0) {
        return null;
    }
    buyer_shipping_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM buyer_shipping_addresses WHERE id = :id AND user_id = :uid LIMIT 1');
        $st->execute([':id' => $addressId, ':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function buyer_shipping_format_text(array $row): string
{
    $parts = array_filter([
        trim((string)($row['full_name'] ?? '')),
        trim((string)($row['line1'] ?? '')),
        trim((string)($row['line2'] ?? '')),
        trim(implode(', ', array_filter([
            trim((string)($row['city'] ?? '')),
            trim((string)($row['region'] ?? '')),
            trim((string)($row['postal_code'] ?? '')),
        ]))),
        trim((string)($row['country'] ?? '')),
        trim((string)($row['phone'] ?? '')) !== '' ? 'Tel: ' . trim((string)$row['phone']) : '',
    ], static fn(string $s): bool => $s !== '');
    return implode("\n", $parts);
}

/** Address block for checkout door (street / city lines only — phone is separate). */
function buyer_shipping_format_door_text(array $row): string
{
    $parts = [];
    $line1 = trim((string)($row['line1'] ?? ''));
    $line2 = trim((string)($row['line2'] ?? ''));
    if ($line1 !== '') {
        $parts[] = $line1;
    }
    if ($line2 !== '') {
        $parts[] = $line2;
    }
    $cityLine = trim(implode(', ', array_filter([
        trim((string)($row['city'] ?? '')),
        trim((string)($row['region'] ?? '')),
        trim((string)($row['postal_code'] ?? '')),
    ], static fn(string $s): bool => $s !== '')));
    if ($cityLine !== '') {
        $parts[] = $cityLine;
    }
    $country = trim((string)($row['country'] ?? ''));
    if ($country !== '' && strtoupper($country) !== 'US') {
        $parts[] = $country;
    }
    return implode("\n", $parts);
}

/**
 * Parse freeform door textarea into structured address fields.
 *
 * @return array{line1:string,line2:string,city:string,region:string,postal_code:string,country:string}
 */
function buyer_shipping_parse_door_text(string $text): array
{
    $out = [
        'line1' => '',
        'line2' => '',
        'city' => '',
        'region' => '',
        'postal_code' => '',
        'country' => 'US',
    ];
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', trim($text))), static fn(string $s): bool => $s !== ''));
    if (!$lines) {
        return $out;
    }

    $parseCityLine = static function (string $line) use (&$out): bool {
        if (preg_match('/^(.+?),\s*([A-Za-z]{2,})\s+(\d[\w-]*)$/', $line, $m)) {
            $out['city'] = trim($m[1]);
            $out['region'] = strtoupper(trim($m[2]));
            $out['postal_code'] = trim($m[3]);
            return true;
        }
        if (preg_match('/^(.+?),\s*([^,]+)$/', $line, $m)) {
            $out['city'] = trim($m[1]);
            $out['region'] = trim($m[2]);
            return true;
        }
        return false;
    };

    if (count($lines) === 1) {
        if (!$parseCityLine($lines[0])) {
            $out['line1'] = $lines[0];
        }
        return $out;
    }

    $last = array_pop($lines);
    if (!$parseCityLine($last)) {
        $lines[] = $last;
    }

    $out['line1'] = $lines[0] ?? '';
    if (count($lines) > 1) {
        $out['line2'] = implode(', ', array_slice($lines, 1));
    }
    return $out;
}

/**
 * Save checkout door address into buyer_shipping_addresses (default for future orders).
 *
 * @return array{ok:bool,error?:string,id?:int,address_text?:string}
 */
function buyer_shipping_sync_door_address(
    PDO $dbh,
    int $userId,
    string $addressText,
    string $phone = '',
    ?string $fullName = null
): array {
    $addressText = trim($addressText);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Sign in required.'];
    }
    if ($addressText === '') {
        return ['ok' => false, 'error' => 'Delivery address is required.'];
    }

    if ($fullName === null || trim($fullName) === '') {
        try {
            $st = $dbh->prepare('SELECT name, username FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $fullName = trim((string)($u['name'] ?? '')) ?: trim((string)($u['username'] ?? ''));
        } catch (Throwable $e) {
            $fullName = '';
        }
    }
    if (trim((string)$fullName) === '') {
        $fullName = 'Customer';
    }

    $parsed = buyer_shipping_parse_door_text($addressText);
    if ($parsed['line1'] === '') {
        $parsed['line1'] = mb_substr($addressText, 0, 200);
    }

    $default = buyer_shipping_default_row($dbh, $userId);
    $addressId = $default ? (int)($default['id'] ?? 0) : 0;
    $label = $default ? (string)($default['label'] ?? 'Home') : 'Home';

    $res = buyer_shipping_save($dbh, $userId, [
        'label' => $label,
        'full_name' => $fullName,
        'phone' => $phone,
        'line1' => $parsed['line1'],
        'line2' => $parsed['line2'],
        'city' => $parsed['city'],
        'region' => $parsed['region'],
        'postal_code' => $parsed['postal_code'],
        'country' => $parsed['country'] !== '' ? $parsed['country'] : 'US',
        'is_default' => true,
    ], $addressId);

    if (empty($res['ok'])) {
        return $res;
    }

    $saved = buyer_shipping_get($dbh, $userId, (int)($res['id'] ?? $addressId));
    $res['address_text'] = $saved ? buyer_shipping_format_door_text($saved) : $addressText;
    return $res;
}

function buyer_shipping_default_row(PDO $dbh, int $userId): ?array
{
    $rows = buyer_shipping_list($dbh, $userId);
    return $rows[0] ?? null;
}

function buyer_shipping_default_text(PDO $dbh, int $userId): string
{
    $row = buyer_shipping_default_row($dbh, $userId);
    return $row ? buyer_shipping_format_text($row) : '';
}

function buyer_shipping_default_phone(PDO $dbh, int $userId): string
{
    $row = buyer_shipping_default_row($dbh, $userId);
    return $row ? trim((string)($row['phone'] ?? '')) : '';
}

function buyer_shipping_save(PDO $dbh, int $userId, array $data, int $addressId = 0): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Sign in required.'];
    }
    buyer_shipping_ensure_schema($dbh);

    $label = trim((string)($data['label'] ?? 'Home'));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $line1 = trim((string)($data['line1'] ?? ''));
    $line2 = trim((string)($data['line2'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $region = trim((string)($data['region'] ?? ''));
    $postal = trim((string)($data['postal_code'] ?? ''));
    $country = trim((string)($data['country'] ?? 'US'));
    $isDefault = !empty($data['is_default']) ? 1 : 0;

    if ($label === '') {
        $label = 'Home';
    }
    if ($line1 === '') {
        return ['ok' => false, 'error' => 'Street address is required.'];
    }
    if ($fullName === '') {
        return ['ok' => false, 'error' => 'Full name is required.'];
    }

    try {
        if ($isDefault) {
            $dbh->prepare('UPDATE buyer_shipping_addresses SET is_default = 0 WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
        }

        $existing = buyer_shipping_list($dbh, $userId);
        if (!$existing) {
            $isDefault = 1;
        }

        if ($addressId > 0) {
            $st = $dbh->prepare('
                UPDATE buyer_shipping_addresses
                SET label = :label, full_name = :name, phone = :phone, line1 = :line1, line2 = :line2,
                    city = :city, region = :region, postal_code = :postal, country = :country,
                    is_default = :def, updated_at = NOW()
                WHERE id = :id AND user_id = :uid
                LIMIT 1
            ');
            $st->execute([
                ':label' => substr($label, 0, 80),
                ':name' => substr($fullName, 0, 120),
                ':phone' => $phone !== '' ? substr($phone, 0, 40) : null,
                ':line1' => substr($line1, 0, 200),
                ':line2' => $line2 !== '' ? substr($line2, 0, 200) : null,
                ':city' => substr($city, 0, 100),
                ':region' => $region !== '' ? substr($region, 0, 100) : null,
                ':postal' => $postal !== '' ? substr($postal, 0, 32) : null,
                ':country' => substr($country !== '' ? $country : 'US', 0, 80),
                ':def' => $isDefault,
                ':id' => $addressId,
                ':uid' => $userId,
            ]);
            if ($st->rowCount() <= 0 && !buyer_shipping_get($dbh, $userId, $addressId)) {
                return ['ok' => false, 'error' => 'Address not found.'];
            }
            return ['ok' => true, 'id' => $addressId];
        }

        $st = $dbh->prepare('
            INSERT INTO buyer_shipping_addresses (
                user_id, label, full_name, phone, line1, line2, city, region, postal_code, country, is_default, created_at, updated_at
            ) VALUES (
                :uid, :label, :name, :phone, :line1, :line2, :city, :region, :postal, :country, :def, NOW(), NOW()
            )
        ');
        $st->execute([
            ':uid' => $userId,
            ':label' => substr($label, 0, 80),
            ':name' => substr($fullName, 0, 120),
            ':phone' => $phone !== '' ? substr($phone, 0, 40) : null,
            ':line1' => substr($line1, 0, 200),
            ':line2' => $line2 !== '' ? substr($line2, 0, 200) : null,
            ':city' => substr($city, 0, 100),
            ':region' => $region !== '' ? substr($region, 0, 100) : null,
            ':postal' => $postal !== '' ? substr($postal, 0, 32) : null,
            ':country' => substr($country !== '' ? $country : 'US', 0, 80),
            ':def' => $isDefault,
        ]);
        return ['ok' => true, 'id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save address.'];
    }
}

function buyer_shipping_delete(PDO $dbh, int $userId, int $addressId): bool
{
    if ($userId <= 0 || $addressId <= 0) {
        return false;
    }
    buyer_shipping_ensure_schema($dbh);
    try {
        $wasDefault = false;
        $row = buyer_shipping_get($dbh, $userId, $addressId);
        if ($row) {
            $wasDefault = !empty($row['is_default']);
        }
        $st = $dbh->prepare('DELETE FROM buyer_shipping_addresses WHERE id = :id AND user_id = :uid LIMIT 1');
        $st->execute([':id' => $addressId, ':uid' => $userId]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        if ($wasDefault) {
            $next = buyer_shipping_list($dbh, $userId);
            if ($next) {
                $dbh->prepare('UPDATE buyer_shipping_addresses SET is_default = 1 WHERE id = :id AND user_id = :uid LIMIT 1')
                    ->execute([':id' => (int)$next[0]['id'], ':uid' => $userId]);
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function buyer_shipping_set_default(PDO $dbh, int $userId, int $addressId): bool
{
    if ($userId <= 0 || $addressId <= 0) {
        return false;
    }
    buyer_shipping_ensure_schema($dbh);
    try {
        if (!buyer_shipping_get($dbh, $userId, $addressId)) {
            return false;
        }
        $dbh->prepare('UPDATE buyer_shipping_addresses SET is_default = 0 WHERE user_id = :uid')
            ->execute([':uid' => $userId]);
        $st = $dbh->prepare('UPDATE buyer_shipping_addresses SET is_default = 1, updated_at = NOW() WHERE id = :id AND user_id = :uid LIMIT 1');
        $st->execute([':id' => $addressId, ':uid' => $userId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
