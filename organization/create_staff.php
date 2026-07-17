<?php
// /Business_only3/organization/create_staff.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

if (!isOrgManager()) {
    header("Location: feed.php");
    exit;
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function genFriendCode(): string {
    $a = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    $b = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    return "STF-{$a}-{$b}";
}

// CSRF
if (empty($_SESSION['csrf_create_staff'])) {
    $_SESSION['csrf_create_staff'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_create_staff'];
function csrf_ok_create_staff(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_create_staff'])
        && is_string($_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf_create_staff'], (string)$_POST['csrf']);
}

$err = '';
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok_create_staff()) {
        $err = 'Security check failed. Please refresh and try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $fullname = trim((string)($_POST['fullname'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $rel      = trim((string)($_POST['relationship_label'] ?? ''));
        $pass     = (string)($_POST['password'] ?? '');

        if ($username === '' || $pass === '') {
            $err = "Username and password required.";
        } else {
            // Uniqueness checks
            try {
                // username uniqueness in staff_accounts
                $stU = $dbh->prepare("SELECT 1 FROM staff_accounts WHERE username = :u LIMIT 1");
                $stU->execute([':u'=>$username]);
                if ($stU->fetchColumn()) {
                    $err = "Username already exists. Choose another username.";
                }

                // email uniqueness in staff_accounts (if provided)
                if ($err === '' && $email !== '') {
                    $stE = $dbh->prepare("SELECT 1 FROM staff_accounts WHERE email = :e LIMIT 1");
                    $stE->execute([':e'=>$email]);
                    if ($stE->fetchColumn()) {
                        $err = "Email already exists. Use another email.";
                    }
                }
            } catch (Throwable $e) {
                $err = "DB error: " . $e->getMessage();
            }

            if ($err === '') {
                // Generate friend_code until unique
                $friend = '';
                try {
                    for ($i=0; $i<10; $i++) {
                        $try = genFriendCode();
                        $stF = $dbh->prepare("SELECT 1 FROM staff_accounts WHERE friend_code = :fc LIMIT 1");
                        $stF->execute([':fc'=>$try]);
                        if (!$stF->fetchColumn()) { $friend = $try; break; }
                    }
                } catch (Throwable $e) {
                    $friend = '';
                }
                if ($friend === '') {
                    $err = "Could not generate unique friend code. Try again.";
                } else {

                    $hash = password_hash($pass, PASSWORD_DEFAULT);

                    // role: Staff (org_roles)
                    $orgActive = (int)orgActiveOrgId();
                    $stR = $dbh->prepare("SELECT id FROM org_roles WHERE org_id=:org AND name='Staff' LIMIT 1");
                    $stR->execute([':org'=>$orgActive]);
                    $role = $stR->fetch(PDO::FETCH_ASSOC);

                    if (!$role) {
                        $err = "Staff role missing for this org. Create org roles first.";
                    } else {
                        $dbh->beginTransaction();
                        try {
                            // 1) staff_accounts
                            $st = $dbh->prepare("
                              INSERT INTO staff_accounts (org_id, friend_code, username, email, password, fullname, status, force_password_change, created_at)
                              VALUES (:org, :fc, :u, :e, :p, :fn, 1, 1, NOW())
                            ");
                            $st->execute([
                                ':org'=>$orgActive,
                                ':fc'=>$friend,
                                ':u'=>$username,
                                ':e'=>($email!==''?$email:null),
                                ':p'=>$hash,
                                ':fn'=>($fullname!==''?$fullname:null),
                            ]);
                            $staffId = (int)$dbh->lastInsertId();

                            // 2) org_members (org-scoped identity)
                            $stM = $dbh->prepare("
                              INSERT INTO org_members (org_id, member_type, member_id, role_id, relationship_label, status, joined_at)
                              VALUES (:org, 'staff', :sid, :role, :rel, 1, NOW())
                            ");
                            $stM->execute([
                                ':org'=>$orgActive,
                                ':sid'=>$staffId,
                                ':role'=>(int)$role['id'],
                                ':rel'=>($rel!==''?$rel:null),
                            ]);
                            $orgMemberId = (int)$dbh->lastInsertId();

                            // 3) organization_users (points to org_members.id)
                            $stOU = $dbh->prepare("
                                INSERT INTO organization_users (org_id, user_id, role, joined_at)
                                VALUES (:org, :uid, 'staff', NOW())
                                ON DUPLICATE KEY UPDATE role = VALUES(role)
                            ");
                            $stOU->execute([':org'=>$orgActive, ':uid'=>$orgMemberId]);

                            $dbh->commit();

                            $created = [
                                'username'=>$username,
                                'password'=>$pass,
                                'friend_code'=>$friend,
                            ];
                        } catch (Throwable $e) {
                            if ($dbh->inTransaction()) $dbh->rollBack();
                            $err = "Create staff failed: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Create Staff</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    html,body{ height:100%; overflow:hidden; }
    .sh-mainpanel{ height:100vh; display:flex; flex-direction:column; overflow:hidden; }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto; overflow:hidden; padding-bottom:0!important;
      display:flex; flex-direction:column; min-height:0;
    }
    .create-card{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
    .card-body-fixed{ flex:1 1 auto; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
    .rows-scroll{ flex:1 1 auto; min-height:0; overflow:auto; padding: 15px; }
    .actions-fixed{
      flex:0 0 auto; padding: 12px 15px; border-top: 1px solid rgba(17,24,39,.10);
      background: rgba(248,250,252,.96); display:flex; gap:10px; flex-wrap:wrap;
      justify-content:flex-end; align-items:center;
    }
    .actions-fixed .btn{ font-weight:800; }
    .created-box code{ font-weight:900; }
  </style>
</head>

<body class="org-app">
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <?php org_page_body_open('', 'border-bottom: 1px solid #4a535c;'); ?>

    <div class="card bd-0 create-card">
      <div class="card-body card-body-fixed">

        <form method="post" autocomplete="off" style="height:100%;display:flex;flex-direction:column;min-height:0;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <div class="rows-scroll">

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= h($err) ?></div>
            <?php endif; ?>

            <?php if ($created): ?>
              <div class="alert alert-success created-box">
                <div style="font-weight:900;margin-bottom:6px;">Staff created successfully.</div>
                <div><strong>Username:</strong> <code><?= h($created['username']) ?></code></div>
                <div><strong>Password:</strong> <code><?= h($created['password']) ?></code></div>
                <div><strong>Friend Code:</strong> <code><?= h($created['friend_code']) ?></code></div>
                <small style="opacity:.85;">Staff will be forced to change password on first login.</small>
              </div>
            <?php endif; ?>

            <div class="form-group">
              <label>Username</label>
              <input type="text" name="username" class="form-control" required value="<?= h((string)($_POST['username'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Full name</label>
              <input type="text" name="fullname" class="form-control" value="<?= h((string)($_POST['fullname'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Email (optional)</label>
              <input type="email" name="email" class="form-control" value="<?= h((string)($_POST['email'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Role label</label>
              <input type="text" name="relationship_label" class="form-control" value="<?= h((string)($_POST['relationship_label'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Temporary password</label>
              <input type="text" name="password" class="form-control" required value="<?= h((string)($_POST['password'] ?? '')) ?>">
              <small class="form-text text-muted">Staff will be required to change password on first login.</small>
            </div>

          </div>

          <div class="actions-fixed">
            <a href="feed.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">
              <i class="ion-person-add mg-r-5"></i> Create Staff
            </button>
          </div>

        </form>

      </div>
    </div>

  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>

</div>
<?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_footer_assets(); ?>
</body>
</html>
