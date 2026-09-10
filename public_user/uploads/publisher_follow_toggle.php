<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

$meId = (int)$_SESSION['user_id'];
$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);

$targetId = (int)($_POST['target_id'] ?? $_GET['target_id'] ?? 0);
if ($targetId <= 0 || $targetId === $meId) {
    echo json_encode(['ok' => false]);
    exit;
}

if (!publisher_is_publisher_user($dbh, $targetId)) {
    echo json_encode(['ok' => false, 'error' => 'not_publisher']);
    exit;
}

try {
    $st = $dbh->prepare('SELECT 1 FROM public_follows WHERE follower_id = :me AND following_id = :you LIMIT 1');
    $st->execute([':me' => $meId, ':you' => $targetId]);
    $exists = (bool)$st->fetchColumn();
    $following = false;

    if ($exists) {
        $st = $dbh->prepare('DELETE FROM public_follows WHERE follower_id = :me AND following_id = :you LIMIT 1');
        $st->execute([':me' => $meId, ':you' => $targetId]);
    } else {
        $st = $dbh->prepare('INSERT INTO public_follows (follower_id, following_id, created_at) VALUES (:me, :you, NOW())');
        $st->execute([':me' => $meId, ':you' => $targetId]);
        $following = true;
    }

    echo json_encode(['ok' => true, 'following' => $following, 'target_id' => $targetId]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
