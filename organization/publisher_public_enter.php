<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_publisher_access.php';
require_once __DIR__ . '/includes/org_public_publish.php';
require_once __DIR__ . '/../public_user/includes/publisher_accounts.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$managerId = (int)orgAccountId();
if ($managerId <= 0 || !org_manager_is_registered_publisher($dbh, $managerId)) {
    $_SESSION['org_flash'] = 'No linked publisher account for this organization.';
    header('Location: feed.php');
    exit;
}

publisher_session_establish_for_manager($dbh, $managerId);

$to = strtolower(trim((string)($_GET['to'] ?? 'compose')));
$publisherUserId = org_public_publish_publisher_user_id($dbh);
if ($publisherUserId <= 0) {
    $publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
}

if ($to === 'feed') {
    header('Location: ../public_user/feed.php');
    exit;
}

if ($to === 'profile' && $publisherUserId > 0) {
    try {
        $st = $dbh->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $publisherUserId]);
        $username = trim((string)($st->fetchColumn() ?: ''));
        if ($username !== '') {
            header('Location: ../public_user/profile.php?u=' . rawurlencode($username));
            exit;
        }
    } catch (Throwable $e) {
        // fall through
    }
}

header('Location: ../public_user/dashboard.php');
exit;
