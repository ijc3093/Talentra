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

if (isset($_POST['set_manager_status'])) {
    $managerId = (int)($_POST['manager_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($managerId <= 0) {
        $error = 'Invalid manager id.';
    } elseif (org_admin_set_status($dbh, 'managers', $managerId, $status)) {
        $msg = $status === 1 ? 'Manager activated.' : 'Manager disabled.';
    } else {
        $error = 'Could not update manager status.';
    }
}

$rows = org_admin_list_managers($dbh, $search);
$total = count($rows);

org_admin_render_head('Managers');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <!-- <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-person-stalker"></i></div>
      <div>
        <h2>Managers</h2>
        <p class="mg-b-0">Organization owner accounts for the org portal.</p>
      </div>
    </div>
  </div> -->

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card">
      <!-- <div class="card-header pro">
        Manager Accounts
        <div class="sub"><?= (int)$total ?> manager<?= $total === 1 ? '' : 's' ?></div>
      </div> -->

      <div class="pro-tools">
        <div class="sub"><?= (int)$total ?> manager<?= $total === 1 ? '' : 's' ?></div>
        <form class="search-form" method="get" action="managerlist.php">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search username, email, code…">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($search !== ''): ?><a class="btn-mini" href="managerlist.php">Clear</a><?php endif; ?>
        </form>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>Manager</th>
              <th>Publisher user</th>
              <th>Organizations</th>
              <th>Status</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center muted" style="padding:28px;">No managers found.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <?php
              $managerId = (int)($row['id'] ?? 0);
              $status = (int)($row['status'] ?? 0);
              $owned = org_admin_manager_orgs($dbh, $managerId);
            ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($row['username'] ?? '') ?></strong></div>
                <?php if (!empty($row['fullname'])): ?><div class="muted"><?= org_admin_h($row['fullname']) ?></div><?php endif; ?>
                <div class="muted"><?= org_admin_h($row['friend_code'] ?? '') ?> · <?= org_admin_h($row['email'] ?? '') ?></div>
              </td>
              <td>
                <?php if (!empty($row['pub_user_id'])): ?>
                  <?= org_admin_render_public_user_link((int)$row['pub_user_id'], (string)($row['pub_username'] ?? ''), (string)($row['pub_username'] ?? ''), (string)($row['pub_code'] ?? '')) ?>
                <?php else: ?>
                  <span class="muted">Not linked</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$owned): ?>
                  <span class="muted">None owned</span>
                <?php else: ?>
                  <?php foreach ($owned as $o): ?>
                    <div><a href="orgdetail.php?id=<?= (int)($o['id'] ?? 0) ?>"><?= org_admin_h($o['name'] ?? '') ?></a></div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td><?= org_admin_status_badge($status) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></td>
              <td style="white-space:nowrap;">
                <form method="post" style="display:inline;" onsubmit="return confirm('Change manager status?');">
                  <input type="hidden" name="manager_id" value="<?= $managerId ?>">
                  <input type="hidden" name="status_value" value="<?= $status === 1 ? 0 : 1 ?>">
                  <button type="submit" name="set_manager_status" class="btn-mini <?= $status === 1 ? 'warn' : 'primary' ?>">
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
