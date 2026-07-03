<?php
// /Business_only3/organization/rooms.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

$orgId = orgActiveOrgId();
$meMid = orgMemberId();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$err = '';
$ok  = '';

// Create room (manager only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_room'])) {
  if (!isOrgManager()) {
    $err = 'Only managers can create rooms.';
  } else {
    $name = trim((string)($_POST['room_name'] ?? ''));
    if ($name === '' || strlen($name) > 80) {
      $err = 'Room name is required (max 80 chars).';
    } else {
      try {
        $st = $dbh->prepare("
          INSERT INTO org_rooms (org_id, name, room_type, created_by_member_id, status, created_at)
          VALUES (:org, :name, 'group', :by, 1, NOW())
        ");
        $st->execute([':org'=>$orgId, ':name'=>$name, ':by'=>$meMid]);
        $rid = (int)$dbh->lastInsertId();

        // auto-join creator as owner
        $st2 = $dbh->prepare("
          INSERT INTO org_room_members (room_id, member_id, role, status, joined_at)
          VALUES (:rid, :mid, 'owner', 1, NOW())
        ");
        $st2->execute([':rid'=>$rid, ':mid'=>$meMid]);

        header("Location: groupchat.php?room=".$rid);
        exit;
      } catch (Throwable $e) {
        $err = "Create failed: ".$e->getMessage();
      }
    }
  }
}

// Join room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_room'])) {
  $rid = (int)($_POST['room_id'] ?? 0);
  if ($rid > 0) {
    try {
      // ensure room in this org
      $stR = $dbh->prepare("SELECT id FROM org_rooms WHERE id=:id AND org_id=:org AND status=1 LIMIT 1");
      $stR->execute([':id'=>$rid, ':org'=>$orgId]);
      if ($stR->fetchColumn()) {
        $stJ = $dbh->prepare("
          INSERT INTO org_room_members (room_id, member_id, role, status, joined_at)
          VALUES (:rid, :mid, 'member', 1, NOW())
          ON DUPLICATE KEY UPDATE status=1
        ");
        $stJ->execute([':rid'=>$rid, ':mid'=>$meMid]);
        header("Location: groupchat.php?room=".$rid);
        exit;
      } else {
        $err = "Room not found.";
      }
    } catch (Throwable $e) {
      $err = "Join failed: ".$e->getMessage();
    }
  }
}

// Load rooms list
$st = $dbh->prepare("
  SELECT r.id, r.name,
    (SELECT COUNT(*) FROM org_room_members rm WHERE rm.room_id=r.id AND rm.status=1) AS member_count,
    (SELECT COUNT(*) FROM org_room_messages m WHERE m.room_id=r.id) AS msg_count,
    (SELECT MAX(created_at) FROM org_room_messages m2 WHERE m2.room_id=r.id) AS last_msg_at,
    EXISTS(SELECT 1 FROM org_room_members rm2 WHERE rm2.room_id=r.id AND rm2.member_id=:me AND rm2.status=1) AS i_am_in
  FROM org_rooms r
  WHERE r.org_id=:org AND r.status=1
  ORDER BY r.id DESC
");
$st->execute([':org'=>$orgId, ':me'=>$meMid]);
$rooms = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Rooms</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="card bd-0">
      <div class="card-header bg-transparent pd-y-15">
        <h6 class="card-title mg-b-0">Group Rooms</h6>
      </div>
      <div class="card-body">

        <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

        <?php if (isOrgManager()): ?>
          <form method="post" class="form-inline mg-b-20">
            <input type="hidden" name="create_room" value="1">
            <input type="text" name="room_name" class="form-control mg-r-10" placeholder="New room name (e.g. General)" required>
            <button class="btn btn-primary">Create Room</button>
          </form>
        <?php endif; ?>

        <div class="table-responsive">
          <table class="table table-bordered table-hover">
            <thead class="thead-light">
              <tr>
                <th>Name</th>
                <th>Members</th>
                <th>Messages</th>
                <th>Last activity</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rooms as $r): ?>
                <tr>
                  <td><a href="groupchat.php?room=<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></a></td>
                  <td><?= (int)$r['member_count'] ?></td>
                  <td><?= (int)$r['msg_count'] ?></td>
                  <td><?= h((string)($r['last_msg_at'] ?? '')) ?></td>
                  <td>
                    <?php if ((int)$r['i_am_in'] === 1): ?>
                      <a class="btn btn-sm btn-outline-primary" href="groupchat.php?room=<?= (int)$r['id'] ?>">Open</a>
                    <?php else: ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="join_room" value="1">
                        <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-success">Join</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rooms): ?>
                <tr><td colspan="5" class="text-center">No rooms yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>
</body>
</html>
