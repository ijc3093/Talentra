<?php
// /Business_only3/ajax/contact_save_from_messages.php
// Save/rename a friend code from messages.php (user decides the name)

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
$friendCode = strtoupper(trim((string)($_POST['friend_code'] ?? '')));
$name = trim((string)($_POST['display_name'] ?? ''));

if ($meId <= 0) j(['ok'=>false,'error'=>'Session expired.']);
if ($friendCode === '') j(['ok'=>false,'error'=>'Friend code required.']);
if ($name === '') j(['ok'=>false,'error'=>'Name is required.']);

try {
    // Find user by friend code
    $u = $dbh->prepare("SELECT id, friend_code, status FROM users WHERE UPPER(friend_code) = ? LIMIT 1");
    $u->execute([$friendCode]);
    $friend = $u->fetch(PDO::FETCH_ASSOC);

    if (!$friend) j(['ok'=>false,'error'=>'User not found.']);
    if ((int)($friend['status'] ?? 0) !== 1) j(['ok'=>false,'error'=>'User inactive.']);

    $friendId = (int)$friend['id'];
    if ($friendId === $meId) j(['ok'=>false,'error'=>'You cannot rename yourself.']);

    $st = $dbh->prepare("SELECT id, display_name FROM user_contacts WHERE owner_user_id = ? AND friend_user_id = ? LIMIT 1");
    $st->execute([$meId, $friendId]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);

    $dbh->beginTransaction();

    if ($existing) {
        $contactId = (int)$existing['id'];
        $oldName = trim((string)($existing['display_name'] ?? ''));

        $up = $dbh->prepare("UPDATE user_contacts SET display_name = ? WHERE id = ? AND owner_user_id = ?");
        $up->execute([$name, $contactId, $meId]);

        $hist = $dbh->prepare("
            INSERT INTO user_contact_name_history (owner_user_id, friend_user_id, old_name, new_name, action)
            VALUES (?, ?, ?, ?, 'rename')
        ");
        $hist->execute([$meId, $friendId, $oldName, $name]);

    } else {
        $ins = $dbh->prepare("
            INSERT INTO user_contacts (owner_user_id, friend_user_id, display_name, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $ins->execute([$meId, $friendId, $name]);

        $hist = $dbh->prepare("
            INSERT INTO user_contact_name_history (owner_user_id, friend_user_id, old_name, new_name, action)
            VALUES (?, ?, '', ?, 'rename')
        ");
        $hist->execute([$meId, $friendId, $name]);
    }

    $dbh->commit();
    j(['ok'=>true]);

} catch (Throwable $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    j(['ok'=>false,'error'=>'Save failed.']);
}
