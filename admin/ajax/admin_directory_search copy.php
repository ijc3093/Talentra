<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/identity.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $term = trim($_GET['term'] ?? '');
    $term = preg_replace('/\s+/', ' ', $term);

    if ($term === '' || mb_strlen($term) < 1) {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();

    $meRole = myRoleId();
    $termUpper = strtoupper($term);

    $like = '%' . $term . '%';
    $likeUpper = '%' . $termUpper . '%';

    // Return active accounts only, exclude myself
    $sql = "
        SELECT idadmin, fullname, username, friend_code, role
        FROM admin
        WHERE status = 1
          AND username <> :meUser
          AND (
                fullname LIKE :like1
             OR username LIKE :like2
             OR UPPER(friend_code) LIKE :like3
          )
        ORDER BY
          (UPPER(friend_code) = :exactCode) DESC,
          (username = :exactUser) DESC,
          (fullname = :exactName) DESC,
          fullname ASC
        LIMIT 20
    ";

    $st = $dbh->prepare($sql);
    $st->execute([
        ':meUser'    => myUsername(),
        ':like1'     => $like,
        ':like2'     => $like,
        ':like3'     => $likeUpper,
        ':exactCode' => $termUpper,
        ':exactUser' => $term,
        ':exactName' => $term,
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Optional: filter out results that my role cannot chat with
    $items = [];
    foreach ($rows as $r) {
        $peerRole = (int)($r['role'] ?? 0);
        if (channelForAdminRoles($meRole, $peerRole) === '') continue;

        $items[] = [
            'idadmin'     => (int)($r['idadmin'] ?? 0),
            'fullname'    => (string)($r['fullname'] ?? ''),
            'username'    => (string)($r['username'] ?? ''),
            'friend_code' => (string)($r['friend_code'] ?? ''),
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'items' => [], 'error' => $e->getMessage()]);
}
