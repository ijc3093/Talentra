<?php
declare(strict_types=1);

/**
 * About-tab relationship / family tags (@username → full name + notify).
 */

function profile_people_tags_relationship_roles(): array
{
    return [
        'single' => 'Single',
        'boyfriend' => 'Boyfriend',
        'girlfriend' => 'Girlfriend',
        'husband' => 'Husband',
        'wife' => 'Wife',
        'fiance' => 'Fiancé',
        'fiancee' => 'Fiancée',
        'engaged' => 'Engaged',
        'married' => 'Married',
        'partner' => 'Partner',
        'in_a_relationship' => 'In a relationship',
    ];
}

function profile_people_tags_family_roles(): array
{
    return [
        'father' => 'Father',
        'mother' => 'Mother',
        'brother' => 'Brother',
        'sister' => 'Sister',
        'son' => 'Son',
        'daughter' => 'Daughter',
        'uncle' => 'Uncle',
        'aunt' => 'Aunt',
        'cousin' => 'Cousin',
        'grandfather' => 'Grandfather',
        'grandmother' => 'Grandmother',
        'nephew' => 'Nephew',
        'niece' => 'Niece',
        'stepfather' => 'Step-father',
        'stepmother' => 'Step-mother',
    ];
}

function profile_people_tags_ensure_table(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec(
            "CREATE TABLE IF NOT EXISTS profile_people_tags (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_id INT NOT NULL,
                tagged_user_id INT NOT NULL,
                kind VARCHAR(20) NOT NULL,
                role_key VARCHAR(40) NOT NULL,
                tagged_username VARCHAR(80) NOT NULL DEFAULT '',
                tagged_name VARCHAR(160) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_owner_kind_tagged (owner_id, kind, tagged_user_id),
                KEY idx_owner_kind (owner_id, kind),
                KEY idx_tagged (tagged_user_id, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Table may already exist.
    }
}

function profile_people_tags_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function profile_people_tags_role_label(string $kind, string $roleKey): string
{
    $map = $kind === 'family'
        ? profile_people_tags_family_roles()
        : profile_people_tags_relationship_roles();
    $key = strtolower(trim($roleKey));
    return $map[$key] ?? '';
}

function profile_people_tags_find_user(PDO $dbh, int $userId, string $username): ?array
{
    $username = ltrim(trim($username), '@');
    $username = preg_replace('/[^A-Za-z0-9_]/', '', $username) ?? '';
    try {
        if ($userId > 0) {
            $st = $dbh->prepare(
                'SELECT id, username, name FROM users WHERE id = :id LIMIT 1'
            );
            $st->execute([':id' => $userId]);
        } elseif ($username !== '') {
            $st = $dbh->prepare(
                'SELECT id, username, name FROM users WHERE username = :u OR LOWER(username) = LOWER(:u2) LIMIT 1'
            );
            $st->execute([':u' => $username, ':u2' => $username]);
        } else {
            return null;
        }
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        $id = (int)($row['id'] ?? 0);
        $user = trim((string)($row['username'] ?? ''));
        if ($id <= 0 || $user === '') {
            return null;
        }
        $name = trim((string)($row['name'] ?? ''));
        return [
            'id' => $id,
            'username' => $user,
            'name' => $name !== '' ? $name : $user,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function profile_people_tags_hydrate_row(PDO $dbh, array $row): array
{
    $taggedId = (int)($row['tagged_user_id'] ?? 0);
    $live = $taggedId > 0 ? profile_people_tags_find_user($dbh, $taggedId, '') : null;
    $username = $live['username'] ?? trim((string)($row['tagged_username'] ?? ''));
    $name = $live['name'] ?? trim((string)($row['tagged_name'] ?? ''));
    if ($name === '') {
        $name = $username;
    }
    $kind = trim((string)($row['kind'] ?? ''));
    $roleKey = strtolower(trim((string)($row['role_key'] ?? '')));
    $href = '';
    if ($taggedId > 0) {
        $href = 'profile.php?id=' . $taggedId . '&tab=posts';
        if ($username !== '') {
            $href .= '&username=' . rawurlencode($username);
        }
    } elseif ($username !== '') {
        $href = 'profile.php?username=' . rawurlencode($username) . '&tab=posts';
    }
    return [
        'id' => (int)($row['id'] ?? 0),
        'owner_id' => (int)($row['owner_id'] ?? 0),
        'tagged_user_id' => $taggedId,
        'kind' => $kind,
        'role_key' => $roleKey,
        'role_label' => profile_people_tags_role_label($kind, $roleKey),
        'username' => $username,
        'name' => $name,
        'profile_url' => $href,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function profile_people_tags_list(PDO $dbh, int $ownerId, string $kind): array
{
    profile_people_tags_ensure_table($dbh);
    if ($ownerId <= 0) {
        return [];
    }
    try {
        $st = $dbh->prepare(
            'SELECT * FROM profile_people_tags
             WHERE owner_id = :o AND kind = :k
             ORDER BY id ASC'
        );
        $st->execute([':o' => $ownerId, ':k' => $kind]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = profile_people_tags_hydrate_row($dbh, $row);
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function profile_people_tags_get_relationship(PDO $dbh, int $ownerId): ?array
{
    $rows = profile_people_tags_list($dbh, $ownerId, 'relationship');
    return $rows[0] ?? null;
}

/**
 * @return list<array<string,mixed>>
 */
function profile_people_tags_list_family(PDO $dbh, int $ownerId): array
{
    return profile_people_tags_list($dbh, $ownerId, 'family');
}

function profile_people_tags_format_relationship(?array $row, string $fallback = ''): string
{
    if (!$row) {
        return $fallback;
    }
    $role = trim((string)($row['role_label'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    if ($role === '' && $name === '') {
        return $fallback;
    }
    if ($name === '') {
        return $role;
    }
    return $role !== '' ? ($role . ' · ' . $name) : $name;
}

function profile_people_tags_format_family(array $rows, string $fallback = ''): string
{
    if ($rows === []) {
        return $fallback;
    }
    $lines = [];
    foreach ($rows as $row) {
        $role = trim((string)($row['role_label'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if ($role === '' && $name === '') {
            continue;
        }
        $lines[] = $role !== '' ? ($role . ' · ' . $name) : $name;
    }
    return $lines !== [] ? implode("\n", $lines) : $fallback;
}

function profile_people_tags_person_anchor(array $row): string
{
    $name = profile_people_tags_esc((string)($row['name'] ?? ''));
    $url = trim((string)($row['profile_url'] ?? ''));
    if ($url === '') {
        return $name;
    }
    return '<a class="about-link people-tag-link" href="' . profile_people_tags_esc($url) . '">' . $name . '</a>';
}

function profile_people_tags_relationship_html(?array $row, string $fallback = ''): string
{
    if (!$row) {
        return $fallback !== '' ? profile_people_tags_esc($fallback) : '';
    }
    $role = profile_people_tags_esc((string)($row['role_label'] ?? ''));
    $person = profile_people_tags_person_anchor($row);
    if ($role === '') {
        return $person;
    }
    return $role . ' · ' . $person;
}

function profile_people_tags_family_html(array $rows, string $fallback = ''): string
{
    if ($rows === []) {
        return $fallback !== '' ? nl2br(profile_people_tags_esc($fallback), false) : '';
    }
    $parts = [];
    foreach ($rows as $row) {
        $role = profile_people_tags_esc((string)($row['role_label'] ?? ''));
        $person = profile_people_tags_person_anchor($row);
        $parts[] = $role !== '' ? ($role . ' · ' . $person) : $person;
    }
    return implode('<br>', $parts);
}

function profile_people_tags_notify(PDO $dbh, int $ownerId, int $taggedId, string $message): void
{
    if ($ownerId <= 0 || $taggedId <= 0 || $ownerId === $taggedId || $message === '') {
        return;
    }
    if (function_exists('profile_user_wants_notification') && !profile_user_wants_notification($dbh, $taggedId, 'tagged_notifications')) {
        return;
    }
    $owner = profile_people_tags_find_user($dbh, $ownerId, '');
    $tagged = profile_people_tags_find_user($dbh, $taggedId, '');
    if (!$owner || !$tagged) {
        return;
    }
    $sender = (string)$owner['name'];
    $receiver = (string)$tagged['username'];
    $type = $message . ' [r:pf] [u:' . $ownerId . ']';
    if (function_exists('mb_substr')) {
        $type = mb_substr($type, 0, 100);
    } else {
        $type = substr($type, 0, 100);
    }
    try {
        $st = $dbh->prepare(
            'INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
             VALUES (:s, :r, :t, 0)'
        );
        $st->execute([
            ':s' => $sender,
            ':r' => $receiver,
            ':t' => $type,
        ]);
    } catch (Throwable $e) {
        // Do not block About save if notification columns differ.
    }
}

function profile_people_tags_sync_background(PDO $dbh, int $ownerId, string $kind): void
{
    if ($ownerId <= 0 || !function_exists('user_background_load') || !function_exists('user_background_save')) {
        return;
    }
    $about = user_background_load($dbh, $ownerId);
    if ($kind === 'relationship') {
        $rel = profile_people_tags_get_relationship($dbh, $ownerId);
        $about['relationship_status'] = $rel
            ? profile_people_tags_format_relationship($rel)
            : 'Single';
    } elseif ($kind === 'family') {
        $fam = profile_people_tags_list_family($dbh, $ownerId);
        $about['family_details'] = profile_people_tags_format_family($fam, '');
    } else {
        return;
    }
    user_background_save($dbh, $ownerId, $about);
}

function profile_people_tags_save_relationship(PDO $dbh, int $ownerId, string $roleKey, int $taggedId, string $username): array
{
    profile_people_tags_ensure_table($dbh);
    $roles = profile_people_tags_relationship_roles();
    $roleKey = strtolower(trim($roleKey));
    if (!isset($roles[$roleKey])) {
        return ['ok' => false, 'error' => 'Choose a relationship type.'];
    }

    try {
        $del = $dbh->prepare('DELETE FROM profile_people_tags WHERE owner_id = :o AND kind = :k');
        $del->execute([':o' => $ownerId, ':k' => 'relationship']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update relationship.'];
    }

    if ($roleKey === 'single') {
        profile_people_tags_sync_background($dbh, $ownerId, 'relationship');
        return [
            'ok' => true,
            'kind' => 'relationship',
            'relationship' => null,
            'display' => 'Single',
            'html' => profile_people_tags_esc('Single'),
        ];
    }

    $user = profile_people_tags_find_user($dbh, $taggedId, $username);
    if (!$user) {
        return ['ok' => false, 'error' => 'Type @username and pick someone on Talsora.'];
    }
    if ((int)$user['id'] === $ownerId) {
        return ['ok' => false, 'error' => 'You cannot tag yourself.'];
    }

    try {
        $ins = $dbh->prepare(
            'INSERT INTO profile_people_tags
                (owner_id, tagged_user_id, kind, role_key, tagged_username, tagged_name)
             VALUES (:o, :t, :k, :role, :un, :nm)'
        );
        $ins->execute([
            ':o' => $ownerId,
            ':t' => (int)$user['id'],
            ':k' => 'relationship',
            ':role' => $roleKey,
            ':un' => (string)$user['username'],
            ':nm' => (string)$user['name'],
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save that tag.'];
    }

    $label = $roles[$roleKey];
    profile_people_tags_notify(
        $dbh,
        $ownerId,
        (int)$user['id'],
        'tagged you as their ' . $label
    );
    profile_people_tags_sync_background($dbh, $ownerId, 'relationship');
    $row = profile_people_tags_get_relationship($dbh, $ownerId);
    return [
        'ok' => true,
        'kind' => 'relationship',
        'relationship' => $row,
        'display' => profile_people_tags_format_relationship($row, $label),
        'html' => profile_people_tags_relationship_html($row, $label),
    ];
}

function profile_people_tags_add_family(PDO $dbh, int $ownerId, string $roleKey, int $taggedId, string $username): array
{
    profile_people_tags_ensure_table($dbh);
    $roles = profile_people_tags_family_roles();
    $roleKey = strtolower(trim($roleKey));
    if (!isset($roles[$roleKey])) {
        return ['ok' => false, 'error' => 'Choose a family role.'];
    }
    $user = profile_people_tags_find_user($dbh, $taggedId, $username);
    if (!$user) {
        return ['ok' => false, 'error' => 'Type @username and pick someone on Talsora.'];
    }
    if ((int)$user['id'] === $ownerId) {
        return ['ok' => false, 'error' => 'You cannot tag yourself.'];
    }

    $existing = profile_people_tags_list_family($dbh, $ownerId);
    if (count($existing) >= 20) {
        return ['ok' => false, 'error' => 'You can tag up to 20 family members.'];
    }
    foreach ($existing as $row) {
        if ((int)$row['tagged_user_id'] === (int)$user['id']) {
            return ['ok' => false, 'error' => 'That person is already tagged in Family.'];
        }
    }

    try {
        $ins = $dbh->prepare(
            'INSERT INTO profile_people_tags
                (owner_id, tagged_user_id, kind, role_key, tagged_username, tagged_name)
             VALUES (:o, :t, :k, :role, :un, :nm)'
        );
        $ins->execute([
            ':o' => $ownerId,
            ':t' => (int)$user['id'],
            ':k' => 'family',
            ':role' => $roleKey,
            ':un' => (string)$user['username'],
            ':nm' => (string)$user['name'],
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save that family tag.'];
    }

    $label = $roles[$roleKey];
    profile_people_tags_notify(
        $dbh,
        $ownerId,
        (int)$user['id'],
        'tagged you as family (' . $label . ')'
    );
    profile_people_tags_sync_background($dbh, $ownerId, 'family');
    $rows = profile_people_tags_list_family($dbh, $ownerId);
    return [
        'ok' => true,
        'kind' => 'family',
        'family' => $rows,
        'display' => profile_people_tags_format_family($rows),
        'html' => profile_people_tags_family_html($rows),
    ];
}

function profile_people_tags_remove(PDO $dbh, int $ownerId, int $tagId): array
{
    profile_people_tags_ensure_table($dbh);
    if ($tagId <= 0) {
        return ['ok' => false, 'error' => 'Missing tag.'];
    }
    $kind = '';
    try {
        $st = $dbh->prepare('SELECT kind FROM profile_people_tags WHERE id = :id AND owner_id = :o LIMIT 1');
        $st->execute([':id' => $tagId, ':o' => $ownerId]);
        $kind = trim((string)$st->fetchColumn());
        if ($kind === '') {
            return ['ok' => false, 'error' => 'Tag not found.'];
        }
        $del = $dbh->prepare('DELETE FROM profile_people_tags WHERE id = :id AND owner_id = :o LIMIT 1');
        $del->execute([':id' => $tagId, ':o' => $ownerId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove that tag.'];
    }

    profile_people_tags_sync_background($dbh, $ownerId, $kind);
    if ($kind === 'relationship') {
        return [
            'ok' => true,
            'kind' => 'relationship',
            'relationship' => null,
            'display' => 'Single',
            'html' => profile_people_tags_esc('Single'),
        ];
    }
    $rows = profile_people_tags_list_family($dbh, $ownerId);
    $fallback = $rows === [] ? '' : profile_people_tags_format_family($rows);
    return [
        'ok' => true,
        'kind' => 'family',
        'family' => $rows,
        'display' => $fallback,
        'html' => $rows === [] ? '' : profile_people_tags_family_html($rows),
    ];
}

function profile_people_tags_public_payload(PDO $dbh, int $ownerId): array
{
    $rel = profile_people_tags_get_relationship($dbh, $ownerId);
    $fam = profile_people_tags_list_family($dbh, $ownerId);
    return [
        'relationship' => $rel,
        'family' => $fam,
        'relationship_roles' => profile_people_tags_relationship_roles(),
        'family_roles' => profile_people_tags_family_roles(),
    ];
}

function profile_people_tags_render_relationship_editor(?array $row): void
{
    $roles = profile_people_tags_relationship_roles();
    $roleKey = $row['role_key'] ?? 'single';
    if (!isset($roles[$roleKey])) {
        $roleKey = 'single';
    }
    $username = $row ? ('@' . (string)$row['username']) : '';
    $uid = $row ? (int)$row['tagged_user_id'] : 0;
    $picked = $row ? (string)$row['name'] : '';
    ?>
    <div class="about-people" data-people-kind="relationship">
      <div class="about-people-row">
        <select class="about-people-role" data-people-role aria-label="Relationship type">
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?php echo profile_people_tags_esc($key); ?>"<?php echo $key === $roleKey ? ' selected' : ''; ?>><?php echo profile_people_tags_esc($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="about-people-row about-people-tag-row">
        <input type="hidden" data-people-uid value="<?php echo $uid > 0 ? $uid : ''; ?>">
        <input class="about-people-mention" type="text" data-people-mention data-msb-mention="1" autocomplete="off" placeholder="Type @username" value="<?php echo profile_people_tags_esc($username); ?>">
        <span class="about-people-picked" data-people-picked><?php echo profile_people_tags_esc($picked); ?></span>
      </div>
      <div class="about-people-actions">
        <button type="button" class="about-people-save" data-people-save>Save</button>
        <span class="about-people-msg" data-people-msg></span>
      </div>
    </div>
    <?php
}

function profile_people_tags_render_family_editor(array $rows): void
{
    $roles = profile_people_tags_family_roles();
    ?>
    <div class="about-people" data-people-kind="family">
      <ul class="about-people-chips" data-people-chips>
        <?php foreach ($rows as $row): ?>
          <li data-tag-id="<?php echo (int)$row['id']; ?>">
            <span><?php echo profile_people_tags_esc((string)$row['role_label']); ?> · <?php echo profile_people_tags_person_anchor($row); ?></span>
            <button type="button" class="about-people-remove" data-people-remove="<?php echo (int)$row['id']; ?>" aria-label="Remove">&times;</button>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="about-people-row">
        <select class="about-people-role" data-people-role aria-label="Family role">
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?php echo profile_people_tags_esc($key); ?>"><?php echo profile_people_tags_esc($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="about-people-row">
        <input type="hidden" data-people-uid value="">
        <input class="about-people-mention" type="text" data-people-mention data-msb-mention="1" autocomplete="off" placeholder="Type @username">
        <span class="about-people-picked" data-people-picked></span>
      </div>
      <div class="about-people-actions">
        <button type="button" class="about-people-save" data-people-save>Add</button>
        <span class="about-people-msg" data-people-msg></span>
      </div>
    </div>
    <?php
}
