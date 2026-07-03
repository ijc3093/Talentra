<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $meId = (int)($_SESSION['user_id'] ?? 0);
    if ($meId > 0) {
        $st = $dbh->prepare("UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1");
        $st->execute([':id' => $meId]);

        $st2 = $dbh->prepare("SELECT last_seen FROM users WHERE id = :id LIMIT 1");
        $st2->execute([':id'=>$meId]);
        $row = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'ok' => true,
            'server_time' => date('c'),
            'last_seen' => ($row['last_seen'] ?? null)
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'No session id']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Exception']);
}
