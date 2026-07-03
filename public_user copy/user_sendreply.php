<?php
// /Business_only3/user_sendreply.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

/**
 * Get my friend code from session
 */
function myFriendCode(): string
{
    if (function_exists('userFriendCode')) {
        return trim((string) userFriendCode());
    }
    return trim((string) ($_SESSION['user_friend_code'] ?? ''));
}

/**
 * Resolve user by friend code (preferred)
 */
function resolveByFriendCode(PDO $dbh, string $code): array
{
    $code = trim($code);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Invalid reply target (friend code required).'];
    }

    // accept your friend code style: USR-XXXX-XXXX
    if (!preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $code)) {
        return ['ok' => false, 'error' => 'Invalid reply target (friend code required).'];
    }

    $st = $dbh->prepare("SELECT id, name, username, email, friend_code, status FROM users WHERE friend_code = ? LIMIT 1");
    $st->execute([$code]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) return ['ok' => false, 'error' => 'User not found.'];
    if ((int)($u['status'] ?? 1) !== 1) return ['ok' => false, 'error' => 'User account is inactive.'];

    $display = (string)($u['name'] ?: ($u['username'] ?: $u['friend_code']));

    return [
        'ok' => true,
        'peerId' => (int)$u['id'],
        'peerCode' => (string)$u['friend_code'],
        'peerEmail' => (string)$u['email'],
        'peerDisplay' => $display,
    ];
}

/**
 * Legacy support: resolve by email -> friend_code
 * (ONLY if ?reply= is used)
 */
function resolveByEmail(PDO $dbh, string $email): array
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return ['ok' => false, 'error' => 'Invalid reply target.'];
    }

    $st = $dbh->prepare("SELECT id, name, username, email, friend_code, status FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) return ['ok' => false, 'error' => 'User not found.'];
    if ((int)($u['status'] ?? 1) !== 1) return ['ok' => false, 'error' => 'User account is inactive.'];

    $code = trim((string)($u['friend_code'] ?? ''));
    if ($code === '') return ['ok' => false, 'error' => 'User missing friend code.'];

    $display = (string)($u['name'] ?: ($u['username'] ?: $u['friend_code']));

    return [
        'ok' => true,
        'peerId' => (int)$u['id'],
        'peerCode' => (string)$u['friend_code'],
        'peerEmail' => (string)$u['email'],
        'peerDisplay' => $display,
    ];
}

/**
 * MAIN
 * Preferred:
 *   /user_sendreply.php?to=USR-XXXX-XXXX
 * Also supported (your header dropdown currently uses this):
 *   /user_sendreply.php?peer=USR-XXXX-XXXX
 * Legacy:
 *   /user_sendreply.php?reply=sara@gmail.com
 */
$meCode = myFriendCode();
if ($meCode === '') {
    header("Location: index.php?session=reset");
    exit;
}

$to    = trim((string)($_GET['to'] ?? ''));
$peer  = trim((string)($_GET['peer'] ?? ''));   // ✅ support header dropdown
$reply = trim((string)($_GET['reply'] ?? ''));

$target = $to !== '' ? $to : ($peer !== '' ? $peer : '');


// if ($target !== '') {
//     $peerRes = resolveByFriendCode($dbh, $target);
// } elseif ($reply !== '') {
//     $peerRes = resolveByEmail($dbh, $reply);
// } else {
//     $peerRes = ['ok' => false, 'error' => 'Invalid reply target (friend code required).'];
// }

if ($to === '' && $peer !== '') {
    $to = $peer; // alias support
}

if ($to !== '') {
    $peerRes = resolveByFriendCode($dbh, $to);
} elseif ($reply !== '') {
    $peerRes = resolveByEmail($dbh, $reply);
} else {
    $peerRes = ['ok' => false, 'error' => 'Invalid reply target (friend code required).'];
}


if (empty($peerRes['ok'])) {
    die(htmlspecialchars((string)($peerRes['error'] ?? 'Invalid reply target.'), ENT_QUOTES, 'UTF-8'));
}

$peerCode = (string)$peerRes['peerCode'];

if (strcasecmp($peerCode, $meCode) === 0) {
    die("You cannot message yourself.");
}

// ✅ Jump into Messages selecting the left peer list by friend code
header("Location: messages.php?peer=" . urlencode($peerCode));
exit;
