<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['set_staff_status'])) {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($staffId <= 0) {
        $error = 'Invalid staff id.';
    } elseif (org_admin_set_status($dbh, 'staff_accounts', $staffId, $status)) {
        $msg = $status === 1 ? 'Staff account activated.' : 'Staff account disabled.';
    } else {
        $error = 'Could not update staff status.';
    }
}

$rows = org_admin_list_staff($dbh, $search);
$total = count($rows);

org_admin_render_head('Org Staff');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <!-- <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-people"></i></div>
      <div>
        <h2>Organization Staff</h2>
        <p class="mg-b-0">Staff accounts created inside organization workspaces.</p>
      </div>
    </div>
  </div> -->

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card">
      <!-- <div class="card-header pro">
        Staff Accounts
        <div class="sub"><?= (int)$total ?> staff account<?= $total === 1 ? '' : 's' ?></div>
      </div> -->

      <div class="pro-tools">
        <div class="sub"><?= (int)$total ?> staff account<?= $total === 1 ? '' : 's' ?></div>
        <form class="search-form" method="get" action="stafflist.php">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search staff or organization…">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($search !== ''): ?><a class="btn-mini" href="stafflist.php">Clear</a><?php endif; ?>
        </form>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>Staff</th>
              <th>Organization</th>
              <th>Status</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center muted" style="padding:28px;">No staff accounts found.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <?php
              $staffId = (int)($row['id'] ?? 0);
              $status = (int)($row['status'] ?? 0);
            ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($row['username'] ?? '') ?></strong></div>
                <?php if (!empty($row['fullname'])): ?><div class="muted"><?= org_admin_h($row['fullname']) ?></div><?php endif; ?>
                <div class="muted"><?= org_admin_h($row['friend_code'] ?? '') ?><?php if (!empty($row['email'])): ?> · <?= org_admin_h($row['email']) ?><?php endif; ?></div>
              </td>
              <td>
                <div><a href="orgdetail.php?id=<?= (int)($row['org_id'] ?? 0) ?>"><?= org_admin_h($row['org_name'] ?? '') ?></a></div>
                <div class="muted"><?= org_admin_h($row['org_code'] ?? '') ?></div>
              </td>
              <td><?= org_admin_status_badge($status) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></td>
              <td>
                <form method="post" style="display:inline;" onsubmit="return confirm('Change staff status?');">
                  <input type="hidden" name="staff_id" value="<?= $staffId ?>">
                  <input type="hidden" name="status_value" value="<?= $status === 1 ? 0 : 1 ?>">
                  <button type="submit" name="set_staff_status" class="btn-mini <?= $status === 1 ? 'warn' : 'primary' ?>">
                    <?= $status === 1 ? 'Disable' : 'Activate' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
