<?php
// /Business_only3/organization/create_org.php
declare(strict_types=1);

require_once __DIR__ . '/../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

require_once __DIR__ . '/includes/session_org_login.php';
orgRequireManagerOnly();

if (!function_exists('orgAccountId')) {
    function orgAccountId(): int { return (int)($_SESSION['org_account_id'] ?? 0); }
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function genOrgCode(): string {
    // ORG-XXXX-XXXX (uppercase)
    $a = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    $b = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    return "ORG-{$a}-{$b}";
}

function ensureDefaultRoles(PDO $dbh, int $orgId): array {
    // returns role IDs: ['manager'=>id, 'staff'=>id]
    $want = ['Manager','Staff'];
    $ids = [];

    foreach ($want as $name) {
        $st = $dbh->prepare("SELECT id FROM org_roles WHERE org_id=:org AND name=:name LIMIT 1");
        $st->execute([':org'=>$orgId, ':name'=>$name]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if ($r) {
            $ids[strtolower($name)] = (int)$r['id'];
            continue;
        }

        $ins = $dbh->prepare("
            INSERT INTO org_roles (org_id, name, is_system, created_at)
            VALUES (:org, :name, 1, NOW())
        ");
        $ins->execute([':org'=>$orgId, ':name'=>$name]);
        $ids[strtolower($name)] = (int)$dbh->lastInsertId();
    }

    return $ids;
}

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $err = "Organization name required.";
    } else {
        $orgCode = genOrgCode();

        $dbh->beginTransaction();
        try {
            // 1) organizations
            $st = $dbh->prepare("
              INSERT INTO organizations (org_code, name, owner_manager_id, status, created_at)
              VALUES (:code, :name, :mid, 1, NOW())
            ");
            $st->execute([
                ':code' => $orgCode,
                ':name' => $name,
                ':mid'  => orgAccountId()
            ]);
            $orgId = (int)$dbh->lastInsertId();

            // 2) org_settings
            $st2 = $dbh->prepare("
              INSERT INTO org_settings (org_id, logo_type, logo_text, updated_at)
              VALUES (:org, 'text', :logo, NOW())
            ");
            $st2->execute([
                ':org'  => $orgId,
                ':logo' => $name
            ]);

            // 3) ensure roles
            $roles = ensureDefaultRoles($dbh, $orgId);

            // 4) add manager membership in org_members
            // (keep your INSERT IGNORE to avoid duplicates)
            $stM = $dbh->prepare("
              INSERT IGNORE INTO org_members
                (org_id, member_type, member_id, role_id, relationship_label, status, joined_at, created_at)
              VALUES
                (:org, 'manager', :mid, :role, NULL, 1, NOW(), NOW())
            ");
            $stM->execute([
                ':org'  => $orgId,
                ':mid'  => orgAccountId(),
                ':role' => $roles['manager']
            ]);

            // ✅ IMPORTANT:
            // Because INSERT IGNORE may not insert a new row, lastInsertId() can be 0.
            // So we resolve the org_members.id safely by SELECT.
            $stGet = $dbh->prepare("
                SELECT id
                FROM org_members
                WHERE org_id = :org
                  AND member_type = 'manager'
                  AND member_id = :mid
                ORDER BY id DESC
                LIMIT 1
            ");
            $stGet->execute([
                ':org' => $orgId,
                ':mid' => orgAccountId()
            ]);
            $orgMemberId = (int)($stGet->fetchColumn() ?: 0);

            if ($orgMemberId <= 0) {
                throw new RuntimeException('Could not resolve org member id for manager.');
            }

            // ✅ 5) Insert into organization_users (membership layer)
            // user_id MUST be org_members.id (orgMemberId)
            $stOU = $dbh->prepare("
                INSERT INTO organization_users (org_id, user_id, role, joined_at)
                VALUES (:org, :uid, 'manager', NOW())
                ON DUPLICATE KEY UPDATE role = VALUES(role)
            ");
            $stOU->execute([
                ':org' => $orgId,
                ':uid' => $orgMemberId
            ]);

            $dbh->commit();

            // set active org to new org
            $_SESSION['org_active_org_id'] = $orgId;
            unset($_SESSION['org_member_id'], $_SESSION['org_role_id']);

            header("Location: feed.php");
            exit;

        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            $err = "Create failed: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/org_context.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Create Organization</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
  /* ✅ FIXED PAGE (same as settings.php) */
    html,body{ height:100%; overflow:hidden; }

    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }

    .sh-pagetitle{ flex:0 0 auto; }

    .sh-pagebody{
      flex:1 1 auto;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      min-height:0;
    }

    .create-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }

    .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    /* ✅ ONLY THIS SCROLLS */
    .rows-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding: 15px;
    }

    /* ✅ FIXED bottom actions (Create/Cancel not scrolling) */
    .actions-fixed{
      flex:0 0 auto;
      padding: 12px 15px;
      border-top: 1px solid rgba(17,24,39,.10);
      background: rgb(200 213 229 / 96%);
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
      align-items:center;
    }
    .actions-fixed .btn{ font-weight:800; }
  </style>
</head>

<body class="org-app">
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <?php org_page_body_open(); ?>

    <div class="card bd-0 create-card">
      <div class="card-body card-body-fixed">

        <form method="post" style="height:100%;display:flex;flex-direction:column;min-height:0;">

          <div class="rows-scroll">

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= h($err) ?></div>
            <?php endif; ?>

            <div class="form-group">
              <label>Organization Name (logo text)</label>
              <input type="text" name="name" class="form-control" placeholder="Family, Church, Company..." required
                     value="<?= h((string)($_POST['name'] ?? '')) ?>">
              <small class="form-text text-muted">
                This will be used as your org’s logo text by default (you can change it in Settings).
              </small>
            </div>

          </div><!-- /rows-scroll -->

          <!-- ✅ Fixed action buttons -->
          <div class="actions-fixed">
            <a href="feed.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">
              <i class="ion-ios-plus-outline mg-r-5"></i> Create
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
