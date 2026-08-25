<?php
// /Business_only3/ajax/contact_undo_rename.php
// Undo last rename for a contact

declare(strict_types=1);
error_reporting(0);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../includes/user_identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $arr): void { echo json_encode($arr); exit; }

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)userId();
$contactId = (int)($_POST['contact_id'] ?? 0);

if ($meId <= 0) j(['ok'=>false,'error'=>'Session expired.']);
if ($contactId <= 0) j(['ok'=>false,'error'=>'Invalid contact.']);

try {
    $st = $dbh->prepare("SELECT friend_user_id, display_name FROM user_contacts WHERE id = ? AND owner_user_id = ? LIMIT 1");
    $st->execute([$contactId, $meId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) j(['ok'=>false,'error'=>'Contact not found.']);

    $friendId = (int)$c['friend_user_id'];
    $currentName = trim((string)($c['display_name'] ?? ''));

    $h = $dbh->prepare("
        SELECT id, old_name, new_name
        FROM user_contact_name_history
        WHERE owner_user_id = ? AND friend_user_id = ?
        ORDER BY changed_at DESC, id DESC
        LIMIT 1
    ");
    $h->execute([$meId, $friendId]);
    $last = $h->fetch(PDO::FETCH_ASSOC);

    if (!$last) j(['ok'=>false,'error'=>'Nothing to undo.']);

    $undoTo = trim((string)($last['old_name'] ?? ''));
    if ($undoTo === '') j(['ok'=>false,'error'=>'Nothing to undo.']);

    $dbh->beginTransaction();

    $up = $dbh->prepare("UPDATE user_contacts SET display_name = ? WHERE id = ? AND owner_user_id = ?");
    $up->execute([$undoTo, $contactId, $meId]);

    $hist = $dbh->prepare("
        INSERT INTO user_contact_name_history (owner_user_id, friend_user_id, old_name, new_name, action)
        VALUES (?, ?, ?, ?, 'undo')
    ");
    $hist->execute([$meId, $friendId, $currentName, $undoTo]);

    $dbh->commit();

    j(['ok'=>true,'display_name'=>$undoTo]);

} catch (Throwable $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    j(['ok'=>false,'error'=>'Undo failed.']);
}
