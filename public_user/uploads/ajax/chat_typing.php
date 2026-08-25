<?php
// /Business_only/ajax/chat_typing.php
// Purpose: set typing state for (me -> peer). Used by messages.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

function ensure_chat_typing_table(PDO $dbh): void {
    // Lightweight safety: create table if missing (runs fast once; harmless afterwards)
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
    $peer = strtoupper(trim((string)($_POST['peer'] ?? '')));
    $typing = ((string)($_POST['typing'] ?? '0')) === '1' ? 1 : 0;

    if ($meCode === '' || $peer === '' || $meCode === $peer) {
        echo json_encode(['ok' => false]);
        exit;
    }

    // Keep my presence alive too (WhatsApp-like)
    $meId = (int)($_SESSION['user_id'] ?? 0);
    if ($meId > 0) {
        $stSeen = $dbh->prepare("UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1");
        $stSeen->execute([':id' => $meId]);
    }

    // Upsert typing state
    $st = $dbh->prepare("
        INSERT INTO chat_typing (sender, receiver, is_typing, updated_at)
        VALUES (:s, :r, :t, NOW())
        ON DUPLICATE KEY UPDATE is_typing = VALUES(is_typing), updated_at = NOW()
    ");
    $st->execute([':s' => $meCode, ':r' => $peer, ':t' => $typing]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
