<?php
// /Business_only3/ajax/contact_rename.php
// Rename a saved contact (inline edit from contacts.php)

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
$newName = trim((string)($_POST['display_name'] ?? ''));

if ($meId <= 0) j(['ok'=>false,'error'=>'Session expired.']);
if ($contactId <= 0) j(['ok'=>false,'error'=>'Invalid contact.']);
if ($newName === '') j(['ok'=>false,'error'=>'Name is required.']);

try {
    $st = $dbh->prepare("SELECT friend_user_id, display_name FROM user_contacts WHERE id = ? AND owner_user_id = ? LIMIT 1");
    $st->execute([$contactId, $meId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) j(['ok'=>false,'error'=>'Contact not found.']);

    $friendId = (int)$row['friend_user_id'];
    $oldName = trim((string)($row['display_name'] ?? ''));

    $dbh->beginTransaction();

    $up = $dbh->prepare("UPDATE user_contacts SET display_name = ? WHERE id = ? AND owner_user_id = ?");
    $up->execute([$newName, $contactId, $meId]);

    // History for undo
    $hist = $dbh->prepare("
        INSERT INTO user_contact_name_history (owner_user_id, friend_user_id, old_name, new_name, action)
        VALUES (?, ?, ?, ?, 'rename')
    ");
    $hist->execute([$meId, $friendId, $oldName, $newName]);

    $dbh->commit();
    j(['ok'=>true]);

} catch (Throwable $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    j(['ok'=>false,'error'=>'Rename failed.']);
}
