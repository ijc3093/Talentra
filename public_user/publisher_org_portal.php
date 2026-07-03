<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_organization_bridge.php';

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);

$meId = publisher_session_canonical_user_id();
if ($meId <= 0) {
    $meId = (int)($_SESSION['user_id'] ?? 0);
}
if ($meId <= 0) {
    header('Location: feed.php');
    exit;
}

try {
    $st = $dbh->prepare('
        SELECT id, name, username, friend_code, account_kind, publisher_category, status
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute([':id' => $meId]);
    $meRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $meRow = [];
}

$sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
$isPublisherAccount = publisher_user_row_looks_like_publisher($dbh, $meRow) || $sessionKind === 'publisher';
if (!$isPublisherAccount) {
    header('Location: feed.php');
    exit;
}

publisher_repair_user_as_publisher($dbh, $meId, trim((string)($meRow['publisher_category'] ?? '')));

$orgId = (int)($_GET['org_id'] ?? 0);
$orgs = publisher_org_list_for_public_user($dbh, $meId);

if ($orgId <= 0 && $orgs) {
    $orgId = (int)($orgs[0]['id'] ?? 0);
}

if ($orgId <= 0 || !publisher_org_public_user_can_access($dbh, $meId, $orgId)) {
    header('Location: feed.php');
    exit;
}

try {
    $st = $dbh->prepare('
        SELECT name, username, email, password, publisher_category
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute([':id' => $meId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        publisher_org_provision_publisher_account(
            $dbh,
            $meId,
            (string)($user['name'] ?? ''),
            trim((string)($user['username'] ?? '')),
            trim((string)($user['email'] ?? '')),
            (string)($user['password'] ?? ''),
            (string)($user['publisher_category'] ?? 'news')
        );
    }
} catch (Throwable $e) {
    // continue — session may still work if already provisioned
}

$managerId = publisher_org_begin_session_for_publisher($dbh, $meId, $orgId);
if ($managerId <= 0) {
    header('Location: feed.php');
    exit;
}

header('Location: ../organization/feed.php');
exit;
