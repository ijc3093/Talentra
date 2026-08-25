<?php
declare(strict_types=1);

/**
 * user_backgrounds column names differ between SQL dumps.
 * Live/Data schema: born_in, lives_in, family_details, education_history,
 * work_details, hobbies, social_facebook, about_text
 * Some PHP/SQL copies: born_in, lives_in, family_details, education_history,
 * work_details, hobbies, social_facebook, about_text
 */

function user_background_logical_defaults(): array
{
    return [
        'pronouns' => '',
        'born_in' => '',
        'lives_in' => '',
        'birthday' => '',
        'relationship_status' => '',
        'languages' => '',
        'family_details' => '',
        'education_history' => '',
        'work_details' => '',
        'hobbies' => '',
        'profile_link' => '',
        'about_text' => '',
    ];
}

function user_background_column_aliases(): array
{
    return [
        'pronouns' => ['pronouns'],
        'born_in' => ['born_in', 'born_in'],
        'lives_in' => ['lives_in', 'lives_in'],
        'birthday' => ['birthday'],
        'relationship_status' => ['relationship_status'],
        'languages' => ['languages'],
        'family_details' => ['family_details', 'family_details'],
        'education_history' => ['education_history', 'education_history'],
        'work_details' => ['work_details', 'work_details'],
        'hobbies' => ['hobbies', 'hobbies'],
        'profile_link' => ['profile_link', 'website', 'website_url', 'link'],
        'about_text' => ['about_text', 'about_text'],
    ];
}

function user_background_multiline_keys(): array
{
    return ['family_details', 'education_history', 'work_details', 'hobbies', 'about_text'];
}

function user_background_ensure_profile_link_column(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!user_background_table_exists($dbh)) {
        return;
    }
    $cols = user_background_table_columns($dbh);
    if (isset($cols['profile_link'])) {
        return;
    }
    try {
        $dbh->exec('ALTER TABLE user_backgrounds ADD COLUMN profile_link VARCHAR(255) NULL DEFAULT NULL AFTER hobbies');
    } catch (Throwable $e) {
        // Column may already exist on another connection.
    }
}

function user_background_normalize_link(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^(javascript|data|vbscript):#i', $value) === 1) {
        return '';
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return $value;
}

function user_background_table_exists(PDO $dbh): bool
{
    try {
        $chk = $dbh->query("SHOW TABLES LIKE 'user_backgrounds'");
        return (bool)($chk && $chk->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function user_background_table_columns(PDO $dbh): array
{
    $cols = [];
    try {
        $st = $dbh->query('SHOW COLUMNS FROM user_backgrounds');
        while ($row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $cols;
}

function user_background_resolve_column(array $cols, string $logical): ?string
{
    foreach (user_background_column_aliases()[$logical] ?? [$logical] as $name) {
        if (isset($cols[$name])) {
            return $name;
        }
    }
    return null;
}

function user_background_row_to_logical(array $row): array
{
    $out = user_background_logical_defaults();
    $cols = [];
    foreach ($row as $key => $_) {
        $cols[(string)$key] = true;
    }
    foreach ($out as $logical => $_) {
        $col = user_background_resolve_column($cols, $logical);
        if ($col === null || !array_key_exists($col, $row)) {
            continue;
        }
        $out[$logical] = trim((string)$row[$col]);
    }
    return $out;
}

function user_background_load(PDO $dbh, int $userId): array
{
    $out = user_background_logical_defaults();
    if ($userId <= 0 || !user_background_table_exists($dbh)) {
        return $out;
    }
    user_background_ensure_profile_link_column($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM user_backgrounds WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($row) {
            $out = user_background_row_to_logical($row);
            if ($out['profile_link'] === '') {
                foreach (['social_x', 'social_instagram', 'social_facebook', 'social_linkedin'] as $legacy) {
                    $legacyVal = user_background_normalize_link(trim((string)($row[$legacy] ?? '')));
                    if ($legacyVal !== '') {
                        $out['profile_link'] = $legacyVal;
                        break;
                    }
                }
            } else {
                $out['profile_link'] = user_background_normalize_link($out['profile_link']);
            }
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function user_background_from_post(array $post, callable $cleanText, callable $cleanMultiline): array
{
    $out = user_background_logical_defaults();
    $multiline = array_fill_keys(user_background_multiline_keys(), true);
    foreach ($out as $logical => $_) {
        $raw = $post[$logical] ?? null;
        if ($raw === null) {
            foreach (user_background_column_aliases()[$logical] ?? [] as $alt) {
                if (array_key_exists($alt, $post)) {
                    $raw = $post[$alt];
                    break;
                }
            }
        }
        if ($raw === null) {
            continue;
        }
        if (isset($multiline[$logical])) {
            $out[$logical] = $cleanMultiline($raw);
        } else {
            $limit = ($logical === 'profile_link') ? 255 : 150;
            $out[$logical] = $cleanText($raw, $limit);
            if ($logical === 'profile_link') {
                $out[$logical] = user_background_normalize_link($out[$logical]);
            }
        }
    }
    return $out;
}

function user_background_save(PDO $dbh, int $userId, array $logicalValues): void
{
    if ($userId <= 0 || !user_background_table_exists($dbh)) {
        return;
    }
    user_background_ensure_profile_link_column($dbh);
    $cols = user_background_table_columns($dbh);
    if ($cols === []) {
        return;
    }

    $insertCols = ['user_id'];
    $placeholders = [':user_id'];
    $updates = [];
    $params = [':user_id' => $userId];
    $used = ['user_id' => true];

    foreach (user_background_logical_defaults() as $logical => $_) {
        $col = user_background_resolve_column($cols, $logical);
        if ($col === null || isset($used[$col])) {
            continue;
        }
        $used[$col] = true;
        $safe = str_replace('`', '', $col);
        $insertCols[] = '`' . $safe . '`';
        $phInsert = ':in_' . $safe;
        $phUpdate = ':up_' . $safe;
        $placeholders[] = $phInsert;
        $updates[] = '`' . $safe . '` = ' . $phUpdate;
        $value = (string)($logicalValues[$logical] ?? '');
        if ($logical === 'profile_link') {
            $value = user_background_normalize_link($value);
        }
        $params[$phInsert] = $value;
        $params[$phUpdate] = $value;
    }

    if (count($insertCols) < 2) {
        return;
    }

    $sql = 'INSERT INTO user_backgrounds (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    $st = $dbh->prepare($sql);
    $st->execute($params);
}

function user_background_parse_sql_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    try {
        $dt = new DateTimeImmutable($value);
        $year = (int)$dt->format('Y');
        if ($year < 1900 || $year > 2100) {
            return null;
        }
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function user_background_sync_users_birthday(PDO $dbh, int $userId, string $birthdayValue, array $usersCols): void
{
    if ($userId <= 0 || !isset($usersCols['birthday'])) {
        return;
    }
    $sqlDate = user_background_parse_sql_date($birthdayValue);
    if ($sqlDate === null) {
        return;
    }
    try {
        $st = $dbh->prepare('UPDATE users SET birthday = :birthday WHERE id = :id LIMIT 1');
        $st->execute([':birthday' => $sqlDate, ':id' => $userId]);
    } catch (Throwable $e) {
        // users.birthday is DATE; skip invalid values without blocking About save.
    }
}

function user_background_allowed_pin_keys(): array
{
    return [
        'phone' => true,
        'email' => true,
        'friend_code' => true,
        'born_in' => true,
        'lives_in' => true,
        'birthday' => true,
        'relationship' => true,
        'languages' => true,
        'gender' => true,
        'work' => true,
        'family' => true,
        'education' => true,
        'hobby' => true,
        'link' => true,
        'about_me' => true,
    ];
}

function user_background_ensure_sidebar_column(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!user_background_table_exists($dbh)) {
        return;
    }
    $cols = user_background_table_columns($dbh);
    if (isset($cols['about_sidebar_json'])) {
        return;
    }
    try {
        $dbh->exec('ALTER TABLE user_backgrounds ADD COLUMN about_sidebar_json TEXT NULL DEFAULT NULL');
    } catch (Throwable $e) {
        // ignore
    }
}

function user_background_normalize_pin_list(array $keys): array
{
    $allowed = user_background_allowed_pin_keys();
    $out = [];
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '' || !isset($allowed[$key]) || isset($out[$key])) {
            continue;
        }
        $out[$key] = $key;
    }
    return array_values($out);
}

function user_background_default_pin_list(): array
{
    return ['work'];
}

function user_background_load_sidebar_pins(PDO $dbh, int $userId): array
{
    if ($userId <= 0 || !user_background_table_exists($dbh)) {
        return user_background_default_pin_list();
    }
    user_background_ensure_sidebar_column($dbh);
    try {
        $st = $dbh->prepare('SELECT about_sidebar_json FROM user_backgrounds WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $raw = trim((string)$st->fetchColumn());
        if ($raw === '') {
            return user_background_default_pin_list();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return user_background_default_pin_list();
        }
        $keys = user_background_normalize_pin_list($decoded);
        return $keys === [] ? user_background_default_pin_list() : $keys;
    } catch (Throwable $e) {
        return user_background_default_pin_list();
    }
}

function user_background_save_sidebar_pins(PDO $dbh, int $userId, array $keys): array
{
    $keys = user_background_normalize_pin_list($keys);
    if ($keys === []) {
        $keys = user_background_default_pin_list();
    }
    if ($userId <= 0 || !user_background_table_exists($dbh)) {
        return $keys;
    }
    user_background_ensure_sidebar_column($dbh);
    $json = json_encode(array_values($keys), JSON_UNESCAPED_SLASHES);
    $sql = 'INSERT INTO user_backgrounds (user_id, about_sidebar_json) VALUES (:in_uid, :in_json)
            ON DUPLICATE KEY UPDATE about_sidebar_json = :up_json';
    $st = $dbh->prepare($sql);
    $st->execute([
        ':in_uid' => $userId,
        ':in_json' => $json,
        ':up_json' => $json,
    ]);
    return $keys;
}

function user_background_toggle_sidebar_pin(PDO $dbh, int $userId, string $key, bool $on): array
{
    $key = trim($key);
    $allowed = user_background_allowed_pin_keys();
    $pins = user_background_load_sidebar_pins($dbh, $userId);
    if (!isset($allowed[$key])) {
        return $pins;
    }
    $pins = array_values(array_filter($pins, static function ($item) use ($key): bool {
        return (string)$item !== $key;
    }));
    if ($on) {
        $pins[] = $key;
    }
    return user_background_save_sidebar_pins($dbh, $userId, $pins);
}
