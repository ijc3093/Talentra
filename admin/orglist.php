<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'active', 'disabled', 'publisher', 'regular'], true)) {
    $filter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['set_org_status'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($orgId <= 0) {
        $error = 'Invalid organization id.';
    } elseif (org_admin_set_status($dbh, 'organizations', $orgId, $status)) {
        $msg = $status === 1 ? 'Organization activated.' : 'Organization disabled.';
    } else {
        $error = 'Could not update organization status.';
    }
}

$rows = org_admin_list_organizations($dbh, $filter, $search);
$total = count($rows);

org_admin_render_head('Organizations');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card">
      <!-- <div class="card-header pro">
        Organization List
        <div class="sub"><?= (int)$total ?> organization<?= $total === 1 ? '' : 's' ?></div>
      </div> -->

      <div class="pro-tools">
        <div class="filter-tabs">
          <?php
            $tabs = [
              'all' => 'All',
              'active' => 'Active',
              'disabled' => 'Disabled',
              'publisher' => 'Publisher orgs',
              'regular' => 'Regular orgs',
            ];
            foreach ($tabs as $key => $label):
              $href = 'orglist.php?filter=' . rawurlencode($key) . ($search !== '' ? '&q=' . rawurlencode($search) : '');
          ?>
            <a href="<?= org_admin_h($href) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= org_admin_h($label) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="sub"><?= (int)$total ?> organization<?= $total === 1 ? '' : 's' ?></div>
        <form class="search-form" method="get" action="orglist.php">
          <input type="hidden" name="filter" value="<?= org_admin_h($filter) ?>">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search org, manager, publisher…">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($search !== ''): ?>
            <a class="btn-mini" href="orglist.php?filter=<?= rawurlencode($filter) ?>">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card-body-fixed">
        <div class="table-scroll">
          <table class="table admin-table">
          <thead>
            <tr>
              <th>Org</th>
              <th>Owner manager</th>
              <th>Publisher user</th>
              <th>Members</th>
              <th>Type</th>
              <th>Status</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center muted" style="padding:28px;">No organizations found.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <?php
              $orgId = (int)($row['id'] ?? 0);
              $status = (int)($row['status'] ?? 0);
              $isPub = (int)($row['is_publisher_org'] ?? 0) === 1;
            ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($row['name'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($row['org_code'] ?? '') ?></div>
              </td>
              <td>
                <div><?= org_admin_h($row['manager_username'] ?? '') ?></div>
                <div class="muted"><?= org_admin_h($row['manager_code'] ?? '') ?></div>
              </td>
              <td>
                <?php if (!empty($row['pub_user_id'])): ?>
                  <div><?= org_admin_render_public_user_link((int)$row['pub_user_id'], (string)($row['pub_username'] ?? ''), (string)($row['pub_username'] ?? ''), (string)($row['pub_code'] ?? '')) ?></div>
                  <div class="muted"><?= org_admin_h($row['pub_code'] ?? '') ?></div>
                <?php else: ?>
                  <span class="muted">Not linked</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="muted"><?= (int)($row['manager_count'] ?? 0) ?> mgr</span>
                ·
                <span class="muted"><?= (int)($row['staff_count'] ?? 0) ?> staff</span>
              </td>
              <td><?= $isPub ? '<span class="pill info">Publisher</span>' : '<span class="pill">Regular</span>' ?></td>
              <td><?= org_admin_status_badge($status) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></td>
              <td style="white-space:nowrap;">
                <a class="btn-mini" href="orgdetail.php?id=<?= $orgId ?>">View</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Change organization status?');">
                  <input type="hidden" name="org_id" value="<?= $orgId ?>">
                  <input type="hidden" name="status_value" value="<?= $status === 1 ? 0 : 1 ?>">
                  <button type="submit" name="set_org_status" class="btn-mini <?= $status === 1 ? 'warn' : 'primary' ?>">
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
</div>
<?php org_admin_render_foot(); ?>
