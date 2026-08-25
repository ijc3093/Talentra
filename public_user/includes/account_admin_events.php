<?php
declare(strict_types=1);

/**
 * Tell admin when a public user takes Danger Zone / account-center actions
 * so staff know whether the person left, switched, exported, or needs restore.
 */

function account_admin_events_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec(
            "CREATE TABLE IF NOT EXISTS user_account_admin_events (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                title VARCHAR(255) NOT NULL,
                detail TEXT NOT NULL,
                admin_next TEXT NOT NULL,
                still_using TINYINT(1) NOT NULL DEFAULT 1,
                meta_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_account_admin_events_user (user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // table may already exist
    }
}

/**
 * @return array{title:string,detail:string,admin_next:string,still_using:int,priority:string}
 */
function account_admin_event_copy(string $type, array $ctx = []): array
{
    $fromName = trim((string)($ctx['from_label'] ?? ''));
    $toName = trim((string)($ctx['to_label'] ?? ''));
    $switchBit = '';
    if ($fromName !== '' && $toName !== '') {
        $switchBit = ' Switched from ' . $fromName . ' to ' . $toName . '.';
    } elseif ($toName !== '') {
        $switchBit = ' Now using ' . $toName . '.';
    }

    $map = [
        'deactivate' => [
            'title' => 'Account deactivated — user is no longer using this account',
            'detail' => 'The user confirmed Deactivate. This profile is paused (status off) and they were signed out.',
            'admin_next' => 'Leave the account disabled. Do not treat this as a ban. If they contact support, set Status to Confirmed/active on Users and tell them to sign in again.',
            'still_using' => 0,
            'priority' => 'high',
        ],
        'delete' => [
            'title' => 'Delete account requested — user wants this account gone',
            'detail' => 'The user typed DELETE in Danger Zone. Full wipe is not completed in the app yet, so the account may still exist.',
            'admin_next' => 'Follow up. Confirm they meant permanent removal. Until a full delete exists, deactivate the account if it is still active, then handle data removal by your policy.',
            'still_using' => 0,
            'priority' => 'high',
        ],
        'export_data' => [
            'title' => 'Account data exported',
            'detail' => 'The user downloaded a JSON copy of profile, About, settings, and posts from Danger Zone / Account tools.',
            'admin_next' => 'No restore needed. This is often a step before deactivate or delete. Watch for a follow-up event. Do not disable the account only because they exported.',
            'still_using' => 1,
            'priority' => 'medium',
        ],
        'reset_settings' => [
            'title' => 'Account settings reset',
            'detail' => 'The user restored Gear privacy, notifications, and appearance to defaults. Posts and friends were not deleted.',
            'admin_next' => 'No account lock. If they report missing privacy or theme, they chose Reset. You can help them set Gear again; do not recreate deleted settings from backup unless they ask.',
            'still_using' => 1,
            'priority' => 'low',
        ],
        'remove_access' => [
            'title' => 'Device or staff access removed',
            'detail' => 'The user signed out other devices or removed access from Danger Zone / Manage devices.',
            'admin_next' => 'They still own the account. If they are locked out, they can sign in again on a trusted device. Do not reactivate or reset password unless they prove identity.',
            'still_using' => 1,
            'priority' => 'medium',
        ],
        'switch_account' => [
            'title' => 'Switched account — still on the platform',
            'detail' => 'The user switched to another linked account on this device.' . $switchBit . ' They did not deactivate or delete this profile.',
            'admin_next' => 'Do not mark this as churn. Both linked accounts stay. The session now belongs to the other user id. Open that profile if you need the active login.',
            'still_using' => 1,
            'priority' => 'low',
        ],
        'add_account' => [
            'title' => 'Linked another account',
            'detail' => 'The user added a second login to their switcher list.' . $switchBit,
            'admin_next' => 'They intend to use more than one account. Keep both rows. Do not merge or delete either account unless they request delete.',
            'still_using' => 1,
            'priority' => 'low',
        ],
    ];

    $row = $map[$type] ?? [
        'title' => 'Account action',
        'detail' => 'The user took an account-center action.',
        'admin_next' => 'Open the user record and review the latest event before changing status.',
        'still_using' => 1,
        'priority' => 'low',
    ];
    return $row;
}

function account_admin_event_notify(PDO $dbh, int $userId, string $type, array $ctx = []): void
{
    if ($userId <= 0 || $type === '') {
        return;
    }
    account_admin_events_ensure_schema($dbh);
    $copy = account_admin_event_copy($type, $ctx);

    $email = trim((string)($ctx['email'] ?? ''));
    $username = trim((string)($ctx['username'] ?? ''));
    $name = trim((string)($ctx['name'] ?? ''));
    if ($email === '' || $username === '') {
        try {
            $st = $dbh->prepare('SELECT name, username, email FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($name === '') {
                $name = trim((string)($u['name'] ?? ''));
            }
            if ($username === '') {
                $username = trim((string)($u['username'] ?? ''));
            }
            if ($email === '') {
                $email = trim((string)($u['email'] ?? ''));
            }
        } catch (Throwable $e) {
            // keep provided context
        }
    }
    $who = $email !== '' ? $email : ($username !== '' ? $username : ('user #' . $userId));
    $meta = $ctx;
    $meta['user_id'] = $userId;
    $meta['type'] = $type;

    try {
        $ins = $dbh->prepare(
            'INSERT INTO user_account_admin_events
             (user_id, event_type, title, detail, admin_next, still_using, meta_json)
             VALUES (:uid, :type, :title, :detail, :next, :using, :meta)'
        );
        $ins->execute([
            ':uid' => $userId,
            ':type' => $type,
            ':title' => $copy['title'],
            ':detail' => $copy['detail'],
            ':next' => $copy['admin_next'],
            ':using' => (int)$copy['still_using'],
            ':meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // continue to notification
    }

    $short = [
        'deactivate' => '[account] Deactivated — no longer using. Restore if they ask. u=' . $userId,
        'delete' => '[account] Delete requested — wipe or deactivate. u=' . $userId,
        'export_data' => '[account] Exported data — watch for leave. u=' . $userId,
        'reset_settings' => '[account] Reset settings — no lock needed. u=' . $userId,
        'remove_access' => '[account] Removed access — they can sign in. u=' . $userId,
        'switch_account' => '[account] Switched account — still on platform. u=' . $userId,
        'add_account' => '[account] Linked another account — keep both. u=' . $userId,
    ];
    $notitype = $short[$type] ?? ('[account] Account action. u=' . $userId);
    if (strlen($notitype) > 100) {
        $notitype = substr($notitype, 0, 100);
    }
    try {
        $noti = $dbh->prepare(
            "INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
             VALUES (:u, 'Admin', :type, 0)"
        );
        $noti->execute([
            ':u' => $who,
            ':type' => $notitype,
        ]);
    } catch (Throwable $e) {
        // admin inbox is optional
    }

    try {
        require_once __DIR__ . '/../controller.php';
        if (class_exists('Controller', false)) {
            $c = new Controller();
            if (method_exists($c, 'logSecurity')) {
                $c->logSecurity(
                    'user_account_' . $type,
                    true,
                    $email !== '' ? $email : null,
                    $username !== '' ? $username : null,
                    null,
                    $meta
                );
            }
        }
    } catch (Throwable $e) {
        // audit log is optional
    }
}

/**
 * @return list<array<string,mixed>>
 */
function account_admin_events_for_user(PDO $dbh, int $userId, int $limit = 12): array
{
    account_admin_events_ensure_schema($dbh);
    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $st = $dbh->prepare(
            'SELECT id, user_id, event_type, title, detail, admin_next, still_using, created_at
             FROM user_account_admin_events
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<int> $userIds
 * @return array<int,array<string,mixed>>
 */
function account_admin_events_latest_by_users(PDO $dbh, array $userIds): array
{
    account_admin_events_ensure_schema($dbh);
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', $ids);
    try {
        $sql = "SELECT e.user_id, e.event_type, e.title, e.admin_next, e.still_using, e.created_at
                FROM user_account_admin_events e
                INNER JOIN (
                    SELECT user_id, MAX(id) AS max_id
                    FROM user_account_admin_events
                    WHERE user_id IN ($in)
                    GROUP BY user_id
                ) t ON t.max_id = e.id";
        $rows = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['user_id']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
