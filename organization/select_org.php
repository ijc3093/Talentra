<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

require_once __DIR__ . '/includes/session_org_login.php';
orgRequireManagerOnly();
require_once __DIR__ . '/includes/org_publisher_access.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$mid = (int)($_SESSION['org_account_id'] ?? 0);
if ($mid <= 0) {
  // fallback if your session key differs
  $mid = (int)($_SESSION['manager_id'] ?? 0);
}
if ($mid <= 0) {
  die('Invalid manager session.');
}

// Publishers registered on public_user/register.php go straight to their company feed.
if (org_manager_is_registered_publisher($dbh, $mid)) {
  org_manager_apply_registered_publisher_login($dbh, $mid);
  publisher_session_establish_for_manager($dbh, $mid);
  header('Location: feed.php');
  exit;
}

/**
 * ✅ Ensure org has default roles
 * returns array: ['manager'=>roleId, 'staff'=>roleId]
 */
function ensureDefaultRoles(PDO $dbh, int $orgId): array {
  $want = ['Manager','Staff'];
  $out = [];
  foreach ($want as $name) {
    $st = $dbh->prepare("SELECT id FROM org_roles WHERE org_id=:org AND name=:name LIMIT 1");
    $st->execute([':org'=>$orgId, ':name'=>$name]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id <= 0) {
      $ins = $dbh->prepare("
        INSERT INTO org_roles (org_id, name, is_system, created_at)
        VALUES (:org, :name, 1, NOW())
      ");
      $ins->execute([':org'=>$orgId, ':name'=>$name]);
      $id = (int)$dbh->lastInsertId();
    }
    $out[strtolower($name)] = $id;
  }
  return $out;
}

/**
 * ✅ Ensure manager membership exists for selected org:
 * - org_members row (org identity)
 * - organization_users row (membership layer)
 */
function ensureManagerMembership(PDO $dbh, int $orgId, int $managerId): void {
  // 1) roles
  $roles = ensureDefaultRoles($dbh, $orgId);
  $managerRoleId = (int)($roles['manager'] ?? 0);
  if ($managerRoleId <= 0) {
    throw new RuntimeException('Manager role missing for org.');
  }

  // 2) org_members (INSERT IGNORE then SELECT to get id)
  $stM = $dbh->prepare("
    INSERT IGNORE INTO org_members
      (org_id, member_type, member_id, role_id, relationship_label, status, joined_at, created_at)
    VALUES
      (:org, 'manager', :mid, :role, NULL, 1, NOW(), NOW())
  ");
  $stM->execute([':org'=>$orgId, ':mid'=>$managerId, ':role'=>$managerRoleId]);

  $stGet = $dbh->prepare("
    SELECT id
    FROM org_members
    WHERE org_id = :org
      AND member_type = 'manager'
      AND member_id = :mid
    ORDER BY id DESC
    LIMIT 1
  ");
  $stGet->execute([':org'=>$orgId, ':mid'=>$managerId]);
  $orgMemberId = (int)($stGet->fetchColumn() ?: 0);

  if ($orgMemberId <= 0) {
    throw new RuntimeException('Could not resolve org member id for manager.');
  }

  // 3) organization_users must point to org_members.id
  $stOU = $dbh->prepare("
    INSERT INTO organization_users (org_id, user_id, role, joined_at)
    VALUES (:org, :uid, 'manager', NOW())
    ON DUPLICATE KEY UPDATE role = VALUES(role)
  ");
  $stOU->execute([':org'=>$orgId, ':uid'=>$orgMemberId]);
}

// Get manager org list (owned orgs + publisher company orgs from public_user registration)
$orgs = org_manager_accessible_orgs($dbh, $mid);

// If no orgs yet, do NOT redirect back to select_org (loop). Go create org.
if (!$orgs) {
  header("Location: create_org.php");
  exit;
}

$err = '';

// ✅ Flash messages (no UI redesign)
$flash = '';
if (isset($_GET['e']) && $_GET['e'] === 'invalid_org') {
  $flash = 'That organization is no longer available or you do not have access.';
}
if (isset($_GET['m']) && $_GET['m'] === 'switched') {
  $flash = 'Organization switched successfully.';
}

$activeOrgId = (int)($_SESSION['org_active_org_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $orgId = (int)($_POST['org_id'] ?? 0);

  // Validate selected org belongs to manager or is a publisher company org
  if (org_manager_can_access_org($dbh, $orgId, $mid)) {
    $ok = $orgId;
  } else {
    $ok = 0;
  }

  if ($ok > 0) {
    // ✅ Ensure membership rows exist (silent auto-fix)
    try {
      $dbh->beginTransaction();
      ensureManagerMembership($dbh, $orgId, $mid);
      $dbh->commit();
    } catch (Throwable $e) {
      if ($dbh->inTransaction()) $dbh->rollBack();
      $err = "Could not activate org (membership fix failed): " . $e->getMessage();
    }

    if ($err === '') {
      $_SESSION['org_active_org_id'] = $orgId;
      unset($_SESSION['org_member_id'], $_SESSION['org_role_id']); // reload membership for new org
      require_once __DIR__ . '/../public_user/includes/staff_publisher_access.php';
      $pubUid = staff_pub_org_publisher_user_id($dbh, $orgId);
      if ($pubUid <= 0) {
        $pubUid = publisher_org_manager_publisher_user_id($dbh, $mid);
      }
      if ($pubUid > 0) {
        $_SESSION['org_publisher_user_id'] = $pubUid;
      } else {
        unset($_SESSION['org_publisher_user_id']);
      }
      header("Location: feed.php?m=switched");
      exit;
    }
  } else {
    $err = "Invalid organization selection.";
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Select Organization</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/org-compact.css?v=3">
</head>
<body class="org-app">
  <div class="sh-signup-wrapper">
    <div class="signpanel-wrapper">
      <div class="signbox">
        <div class="signbox-header">
          <h2>Choose Organization</h2>
          <p class="mg-b-0">Select which organization you want to manage.</p>
        </div><!-- signbox-header -->
        <form method="post" autocomplete="off">
            <div class="signbox-body">

              <?php if ($flash): ?>
                <div class="alert alert-warning"><?= h($flash) ?></div>
              <?php endif; ?>

              <?php if ($err): ?>
                <div class="alert alert-danger"><?= h($err) ?></div>
              <?php endif; ?>

              <?php if ($activeOrgId > 0): ?>
                <div class="alert alert-info">
                  Active organization is already selected. You can switch below.
                </div>
              <?php endif; ?>

              <div class="form-group">
                <select name="org_id" class="form-control" required>
                  <?php foreach ($orgs as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= ((int)$o['id'] === $activeOrgId ? 'selected' : '') ?>>
                      <?= htmlspecialchars((string)$o['name']) ?> (<?= htmlspecialchars((string)$o['org_code']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div><!-- form-group -->
              <button class="btn btn-primary btn-block">Continue</button>
              <div class="tx-center bg-white bd pd-10 mg-t-40"> <a href="logout.php">Sign Out</a></div>
            </div><!-- signbox-body -->
        </div>

      </div><!-- signbox -->
    </div><!-- signpanel-wrapper -->
  </div>
<?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_footer_assets(); ?>
</body>
</html>
