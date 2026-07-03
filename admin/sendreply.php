<?php
/**
 * admin/sendreply.php
 * Signed, expiring redirect to mailbox.php?peer=...
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

// If you defined APP_SIGNING_KEY in a config, include it here if not already loaded.
// require_once __DIR__ . '/config.php';

function signing_key(): string {
    if (defined('APP_SIGNING_KEY') && APP_SIGNING_KEY !== '') {
        return (string)APP_SIGNING_KEY;
    }
    if (empty($_SESSION['csrf_link_key'])) {
        $_SESSION['csrf_link_key'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_link_key'];
}

function expected_sig(string $action, string $peer, string $view, int $expiresTs): string {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $sid = session_id();
    $payload = $action . '|' . $peer . '|' . $view . '|' . $expiresTs . '|' . $adminId . '|' . $sid;
    return hash_hmac('sha256', $payload, signing_key());
}

function safe_fail_redirect(): void {
    header('Location: mailbox.php');
    exit;
}

$peer = trim((string)($_GET['reply'] ?? ''));
if ($peer === '') safe_fail_redirect();

$view = trim((string)($_GET['view'] ?? ''));
$view = in_array($view, ['public','internal'], true) ? $view : '';

$exp = (int)($_GET['exp'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');

// ✅ Require signature + expiry
if ($exp <= 0 || $sig === '') safe_fail_redirect();

// ✅ Expired
if (time() > $exp) safe_fail_redirect();

// ✅ Constant-time compare
$want = expected_sig('reply', $peer, $view, $exp);
if (!hash_equals($want, $sig)) safe_fail_redirect();

// Optional passthrough thread UID
$thread = trim((string)($_GET['t'] ?? ''));

$q = 'peer=' . urlencode($peer);
if ($view !== '') $q .= '&view=' . urlencode($view);
if ($thread !== '') $q .= '&t=' . urlencode($thread);

header('Location: mailbox.php?' . $q);
exit;
