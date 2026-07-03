<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$query = trim((string)($_GET['q'] ?? ''));
$results = org_admin_search_accounts($dbh, $query);

org_admin_render_head('Account Search');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <!-- <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-search"></i></div>
      <div>
        <h2>Account Search</h2>
        <p class="mg-b-0">Search public users, managers, and organizations in one place.</p>
      </div>
    </div>
  </div> -->

  <div class="sh-pagebody">
    <div class="card admin-card">
      <!-- <div class="card-header pro">
        Cross-Account Search
        <div class="sub">Search by name, username, email, or friend code</div>
      </div> -->

      <div class="pro-tools">
        <div class="sub">Search by name, username, email, or friend code</div>
        <form class="search-form" method="get" action="account_search.php">
          <input type="text" name="q" value="<?= org_admin_h($query) ?>" placeholder="e.g. fox2, PUB-, MGR-, seth@gmail.com">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($query !== ''): ?><a class="btn-mini" href="account_search.php">Clear</a><?php endif; ?>
        </form>
      </div>

      <?php if ($query === ''): ?>
        <div class="table-scroll"><div class="muted" style="padding:28px 18px;">Enter a search term to find accounts across all three systems.</div></div>
      <?php else: ?>

      <div class="pro-tools"><strong>Public users</strong><span class="muted"><?= count($results['users']) ?> result(s)</span></div>
      <div class="table-scroll" style="max-height:34vh;">
        <table class="table admin-table">
          <thead><tr><th>User</th><th>Kind</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php if (!$results['users']): ?>
            <tr><td colspan="5" class="muted" style="padding:16px 18px;">No public users matched.</td></tr>
          <?php else: foreach ($results['users'] as $u): ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($u['username'] ?? '') ?></strong> · <?= org_admin_h($u['name'] ?? '') ?></div>
                <div class="muted"><?= org_admin_h($u['friend_code'] ?? '') ?> · <?= org_admin_h($u['email'] ?? '') ?></div>
              </td>
              <td><span class="pill <?= ($u['account_kind'] ?? '') === 'publisher' ? 'info' : '' ?>"><?= org_admin_h($u['account_kind'] ?? 'personal') ?></span></td>
              <td><?= org_admin_status_badge((int)($u['status'] ?? 0)) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($u['created_at'] ?? '')) ?></td>
              <td><?= org_admin_render_public_user_link((int)($u['id'] ?? 0), 'View activity', (string)($u['username'] ?? ''), (string)($u['friend_code'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pro-tools"><strong>Managers</strong><span class="muted"><?= count($results['managers']) ?> result(s)</span></div>
      <div class="table-scroll" style="max-height:28vh;">
        <table class="table admin-table">
          <thead><tr><th>Manager</th><th>Publisher link</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php if (!$results['managers']): ?>
            <tr><td colspan="5" class="muted" style="padding:16px 18px;">No managers matched.</td></tr>
          <?php else: foreach ($results['managers'] as $m): ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($m['username'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($m['friend_code'] ?? '') ?> · <?= org_admin_h($m['email'] ?? '') ?></div>
              </td>
              <td><?= !empty($m['publisher_user_id']) ? '<span class="pill info">User #' . (int)$m['publisher_user_id'] . '</span>' : '<span class="muted">None</span>' ?></td>
              <td><?= org_admin_status_badge((int)($m['status'] ?? 0)) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($m['created_at'] ?? '')) ?></td>
              <td><a class="btn-mini" href="managerlist.php?q=<?= rawurlencode((string)($m['username'] ?? '')) ?>">Open managers</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pro-tools"><strong>Organizations</strong><span class="muted"><?= count($results['organizations']) ?> result(s)</span></div>
      <div class="table-scroll" style="max-height:28vh;">
        <table class="table admin-table">
          <thead><tr><th>Organization</th><th>Type</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php if (!$results['organizations']): ?>
            <tr><td colspan="5" class="muted" style="padding:16px 18px;">No organizations matched.</td></tr>
          <?php else: foreach ($results['organizations'] as $o): ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($o['name'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($o['org_code'] ?? '') ?></div>
              </td>
              <td><?= (int)($o['is_publisher_org'] ?? 0) === 1 ? '<span class="pill info">Publisher</span>' : '<span class="pill">Regular</span>' ?></td>
              <td><?= org_admin_status_badge((int)($o['status'] ?? 0)) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($o['created_at'] ?? '')) ?></td>
              <td><a class="btn-mini" href="orgdetail.php?id=<?= (int)($o['id'] ?? 0) ?>">View</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php endif; ?>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
