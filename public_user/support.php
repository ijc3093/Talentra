<?php
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$meId = myUserId();

function getOrCreateSupportConversation(PDO $dbh, int $meId): string {
    // one support conversation per user
    $st = $dbh->prepare("
      SELECT c.uuid
      FROM conversations c
      JOIN conversation_participants p ON p.conversation_id = c.id AND p.user_id = :me
      WHERE c.type='support'
      LIMIT 1
    ");
    $st->execute([':me'=>$meId]);
    $uuid = $st->fetchColumn();
    if ($uuid) return $uuid;

    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );

    $dbh->beginTransaction();
    $ins = $dbh->prepare("INSERT INTO conversations (uuid, type, created_by_user_id) VALUES (:u,'support',:c)");
    $ins->execute([':u'=>$uuid, ':c'=>$meId]);
    $cid = (int)$dbh->lastInsertId();

    $p = $dbh->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (:cid,:me)");
    $p->execute([':cid'=>$cid, ':me'=>$meId]);

    $dbh->commit();
    return $uuid;
}

$uuid = getOrCreateSupportConversation($dbh, $meId);
header("Location: chat.php?c=" . urlencode($uuid));
exit;
