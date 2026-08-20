<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/org_shop_connect.php';
require_once __DIR__ . '/../public_user/includes/stripe_shop.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
org_shop_connect_ensure_schema($dbh);

$msg = '';
$error = '';
$stripeReady = stripe_shop_is_configured();

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'linked', 'ready', 'incomplete', 'missing'], true)) {
    $filter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['sync_connect'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    if ($orgId <= 0) {
        $error = 'Invalid organization.';
    } elseif (!$stripeReady) {
        $error = 'Stripe is not configured on this server.';
    } else {
        $status = org_shop_connect_sync_account($dbh, $orgId);
        $msg = $status['account_id'] !== ''
            ? 'Connect status synced for org #' . $orgId . '.'
            : 'Org #' . $orgId . ' has no Connect account yet.';
    }
}

if (isset($_POST['sync_all_linked'])) {
    if (!$stripeReady) {
        $error = 'Stripe is not configured on this server.';
    } else {
        $linked = org_admin_list_connect_orgs($dbh, 'linked', '');
        $n = 0;
        foreach ($linked as $row) {
            $oid = (int)($row['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            org_shop_connect_sync_account($dbh, $oid);
            $n++;
            if ($n >= 40) {
                break;
            }
        }
        $msg = 'Synced Connect status for ' . $n . ' linked org' . ($n === 1 ? '' : 's') . '.';
    }
}

if (isset($_POST['clear_connect'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    $res = org_admin_clear_org_connect($dbh, $orgId);
    if (!empty($res['ok'])) {
        $msg = 'Local Connect link cleared for org #' . $orgId . '.';
    } else {
        $error = (string)($res['error'] ?? 'Could not clear Connect link.');
    }
}

$rows = org_admin_list_connect_orgs($dbh, $filter, $search);
$total = count($rows);

org_admin_render_head('Stripe Connect');
?>
<?php
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Stripe Connect',
    'description' => 'Admin oversight of seller Connect accounts (public_user customers pay platform; org sellers receive payouts)',
]);
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card sh-admin-table-card">
      <div class="card-header">
        Stripe Connect
        <div class="sub">Admin oversight of seller Connect accounts (public_user customers pay platform; org sellers receive payouts)</div>
      </div>

      <div class="pro-tools">
        <div class="filter-tabs">
          <?php
            $tabs = [
              'all' => 'All seller orgs',
              'linked' => 'Linked',
              'ready' => 'Payouts ready',
              'incomplete' => 'Incomplete',
              'missing' => 'Not linked',
            ];
            foreach ($tabs as $key => $label):
              $href = 'org_stripe_connect.php?filter=' . rawurlencode($key) . ($search !== '' ? '&q=' . rawurlencode($search) : '');
          ?>
            <a href="<?= org_admin_h($href) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= org_admin_h($label) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="sub"><?= (int)$total ?> org<?= $total === 1 ? '' : 's' ?></div>
        <form class="search-form" method="get" action="org_stripe_connect.php">
          <input type="hidden" name="filter" value="<?= org_admin_h($filter) ?>">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search org, manager, publisher, acct…">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($search !== ''): ?>
            <a class="btn-mini" href="org_stripe_connect.php?filter=<?= rawurlencode($filter) ?>">Clear</a>
          <?php endif; ?>
        </form>
        <form method="post" style="margin-left:auto;">
          <button type="submit" name="sync_all_linked" class="btn-mini primary"<?= $stripeReady ? '' : ' disabled' ?>>Sync linked (max 40)</button>
        </form>
      </div>

      <?php if (!$stripeReady): ?>
        <div class="alert-lite bad" style="margin:12px 16px;">Stripe keys are not configured — sync is disabled. Local status still shows.</div>
      <?php endif; ?>

      <div class="card-body-fixed">
        <div class="table-scroll">
          <table class="table table-bordered table-hover mg-b-0 admin-table">
            <thead>
              <tr>
                <th>Organization</th>
                <th>Publisher user</th>
                <th>Connect account</th>
                <th>Details</th>
                <th>Charges</th>
                <th>Payouts</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-center muted" style="padding:28px;">No organizations found.</td></tr>
            <?php else: foreach ($rows as $row): ?>
              <?php
                $orgId = (int)($row['id'] ?? 0);
                $acct = trim((string)($row['stripe_connect_account_id'] ?? ''));
                $details = (int)($row['stripe_connect_details_submitted'] ?? 0) === 1;
                $charges = (int)($row['stripe_connect_charges_enabled'] ?? 0) === 1;
                $payouts = (int)($row['stripe_connect_payouts_enabled'] ?? 0) === 1;
              ?>
              <tr>
                <td>
                  <div><strong><a href="orgdetail.php?id=<?= $orgId ?>"><?= org_admin_h($row['name'] ?? '') ?></a></strong></div>
                  <div class="muted"><?= org_admin_h($row['org_code'] ?? '') ?> · <?= org_admin_h($row['manager_username'] ?? '') ?></div>
                </td>
                <td>
                  <?php if (!empty($row['pub_user_id'])): ?>
                    <div><?= org_admin_render_public_user_link((int)$row['pub_user_id'], (string)($row['pub_username'] ?? ''), (string)($row['pub_username'] ?? ''), (string)($row['pub_code'] ?? '')) ?></div>
                  <?php else: ?>
                    <span class="muted">Not linked</span>
                  <?php endif; ?>
                </td>
                <td><?= $acct !== '' ? '<code>' . org_admin_h($acct) . '</code>' : '<span class="muted">—</span>' ?></td>
                <td><span class="pill <?= $details ? 'ok' : 'bad' ?>"><?= $details ? 'ok' : 'no' ?></span></td>
                <td><span class="pill <?= $charges ? 'ok' : 'bad' ?>"><?= $charges ? 'on' : 'off' ?></span></td>
                <td><span class="pill <?= $payouts ? 'ok' : 'bad' ?>"><?= $payouts ? 'on' : 'off' ?></span></td>
                <td style="white-space:nowrap;">
                  <a class="btn-mini" href="orgdetail.php?id=<?= $orgId ?>">Open</a>
                  <?php if ($acct !== ''): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="org_id" value="<?= $orgId ?>">
                      <button type="submit" name="sync_connect" class="btn-mini primary"<?= $stripeReady ? '' : ' disabled' ?>>Sync</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Clear local Connect link?');">
                      <input type="hidden" name="org_id" value="<?= $orgId ?>">
                      <button type="submit" name="clear_connect" class="btn-mini warn">Clear</button>
                    </form>
                  <?php endif; ?>
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
