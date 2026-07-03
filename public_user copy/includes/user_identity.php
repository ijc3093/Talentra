<?php
declare(strict_types=1);

require_once __DIR__ . '/session_user.php';
require_once __DIR__ . '/publisher_accounts.php';
require_once __DIR__ . '/../controller.php';

/**
 * Internal cache for the current logged-in user row.
 */
function _currentUserRow(PDO $dbh): array {
    static $cached = null;
    if (is_array($cached)) return $cached;

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $username = (string)($_SESSION['user_login'] ?? '');
    $username = trim($username);

    if ($userId <= 0 && $username === '') {
        $cached = [];
        return $cached;
    }

    $row = false;

    // Prefer the stable session user id and keep username lookup as a legacy fallback.
    if ($userId > 0) {
        $st = $dbh->prepare("
            SELECT id, name, username, friend_code, email, role, status
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($row) && $username !== '') {
        $st = $dbh->prepare("
            SELECT id, name, username, friend_code, email, role, status
            FROM users
            WHERE username = :username
            LIMIT 1
        ");
        $st->execute([':username' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    $cached = is_array($row) ? $row : [];
    return $cached;
}

/** Get a PDO handle safely */
function _identityPdo(): PDO {
    $controller = new Controller();
    return $controller->pdo();
}

/** Session username */
// function userUsername(): string {
//     return trim((string)($_SESSION['user_login'] ?? ''));
// }

/** Users.id — canonical session owner (matches myUserId / publisher session bind). */
function userId(): int {
    if (function_exists('myUserId')) {
        $sessionId = myUserId();
        if ($sessionId > 0) {
            return $sessionId;
        }
    }

    try {
        $dbh = _identityPdo();
        $u = _currentUserRow($dbh);
        return (int)($u['id'] ?? 0);
    } catch (Throwable $e) {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

/** Users.email */
// function userEmail(): string {
//     try {
//         $dbh = _identityPdo();
//         $u = _currentUserRow($dbh);
//         return trim((string)($u['email'] ?? ''));
//     } catch (Throwable $e) {
//         return '';
//     }
// }

/** Users.friend_code */
// function userFriendCode(): string {
//     try {
//         $dbh = _identityPdo();
//         $u = _currentUserRow($dbh);
//         return trim((string)($u['friend_code'] ?? ''));
//     } catch (Throwable $e) {
//         return '';
//     }
// }

/** Users.role */
// function userRoleId(): int {
//     try {
//         $dbh = _identityPdo();
//         $u = _currentUserRow($dbh);
//         return (int)($u['role'] ?? 0);
//     } catch (Throwable $e) {
//         return 0;
//     }
// }

/** Users.status */
function userStatus(): int {
    try {
        $dbh = _identityPdo();
        $u = _currentUserRow($dbh);
        return (int)($u['status'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}
