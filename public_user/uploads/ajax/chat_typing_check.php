<?php
// /Business_only/ajax/chat_typing_check.php
// Purpose: check if (peer -> me) is typing. Used by messages.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

function ensure_chat_typing_table(PDO $dbh): void {
    $dbh->exec("
        CREATE TABLE IF NOT EXISTS chat_typing (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender VARCHAR(80) NOT NULL,
            receiver VARCHAR(80) NOT NULL,
            is_typing TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sender_receiver (sender, receiver),
            KEY idx_receiver_updated (receiver, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    ensure_chat_typing_table($dbh);

    $meCode = strtoupper(trim((string)userFriendCode()));
    $peer   = strtoupper(trim((string)($_GET['peer'] ?? '')));

    if ($meCode === '' || $peer === '' || $meCode === $peer) {
        echo json_encode(['ok' => false]);
        exit;
    }

    // If peer typed recently (last 3 seconds), show typing
    $st = $dbh->prepare("
        SELECT is_typing, updated_at
        FROM chat_typing
        WHERE sender = :peer AND receiver = :me
        LIMIT 1
    ");
    $st->execute([':peer' => $peer, ':me' => $meCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $typing = false;
    if ($row) {
        $isTyping = (int)($row['is_typing'] ?? 0) === 1;
        $ts = strtotime((string)($row['updated_at'] ?? ''));
        if ($isTyping && $ts && (time() - $ts) <= 3) {
            $typing = true;
        }
    }

    echo json_encode(['ok' => true, 'typing' => $typing, 'peer_name' => null]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
