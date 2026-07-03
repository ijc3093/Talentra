<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$orgId = (int)($_GET['id'] ?? 0);
if ($orgId <= 0) {
    header('Location: orglist.php');
    exit;
}

if (isset($_POST['set_org_status'])) {
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if (org_admin_set_status($dbh, 'organizations', $orgId, $status)) {
        $msg = $status === 1 ? 'Organization activated.' : 'Organization disabled.';
    } else {
        $error = 'Could not update organization status.';
    }
}

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

$org = org_admin_get_organization($dbh, $orgId);
if (!$org) {
    header('Location: orglist.php');
    exit;
}

$members = org_admin_list_org_members($dbh, $orgId);
$orgStatus = (int)($org['status'] ?? 0);
$managerStatus = (int)($org['manager_status'] ?? 0);

org_admin_render_head('Organization · ' . (string)($org['name'] ?? ''));
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-briefcase"></i></div>
      <div>
        <h2><?= org_admin_h($org['name'] ?? 'Organization') ?></h2>
        <p class="mg-b-0"><?= org_admin_h($org['org_code'] ?? '') ?></p>
      </div>
    </div>
    <div class="sh-pagetitle-right">
      <a href="orglist.php" class="btn-mini">Back to list</a>
    </div>
  </div>

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card">
      <div class="card-header pro">
        Organization Details
        <div class="sub">
          <?= (int)($org['is_publisher_org'] ?? 0) === 1 ? 'Publisher workspace' : 'Regular workspace' ?>
          · <?= org_admin_h(org_admin_fmt_dt($org['created_at'] ?? '')) ?>
        </div>
      </div>

      <div class="detail-grid">
        <div class="detail-box">
          <div class="label">Organization status</div>
          <div class="value"><?= org_admin_status_badge($orgStatus) ?></div>
          <form method="post" style="margin-top:10px;" onsubmit="return confirm('Change organization status?');">
            <input type="hidden" name="status_value" value="<?= $orgStatus === 1 ? 0 : 1 ?>">
            <button type="submit" name="set_org_status" class="btn-mini <?= $orgStatus === 1 ? 'warn' : 'primary' ?>">
              <?= $orgStatus === 1 ? 'Disable org' : 'Activate org' ?>
            </button>
          </form>
        </div>
        <div class="detail-box">
          <div class="label">Owner manager</div>
          <div class="value">
            <?= org_admin_h($org['manager_username'] ?? '') ?>
            <?php if (!empty($org['manager_fullname'])): ?>
              <div class="muted"><?= org_admin_h($org['manager_fullname']) ?></div>
            <?php endif; ?>
          </div>
          <div class="muted" style="margin-top:6px;">
            <?= org_admin_h($org['manager_code'] ?? '') ?> · <?= org_admin_h($org['manager_email'] ?? '') ?>
          </div>
          <div style="margin-top:8px;"><?= org_admin_status_badge($managerStatus) ?></div>
          <form method="post" style="margin-top:10px;" onsubmit="return confirm('Change manager status?');">
            <input type="hidden" name="manager_id" value="<?= (int)($org['manager_id'] ?? 0) ?>">
            <input type="hidden" name="status_value" value="<?= $managerStatus === 1 ? 0 : 1 ?>">
            <button type="submit" name="set_manager_status" class="btn-mini <?= $managerStatus === 1 ? 'warn' : 'primary' ?>">
              <?= $managerStatus === 1 ? 'Disable manager' : 'Activate manager' ?>
            </button>
          </form>
        </div>
        <div class="detail-box">
          <div class="label">Linked publisher user</div>
          <div class="value">
            <?php if (!empty($org['pub_user_id'])): ?>
              <div style="margin-bottom:8px;">
                <?= org_admin_render_public_user_link(
                  (int)$org['pub_user_id'],
                  (string)($org['pub_username'] ?? ''),
                  (string)($org['pub_username'] ?? ''),
                  (string)($org['pub_code'] ?? '')
                ) ?>
              </div>
              <div class="muted"><?= org_admin_h($org['pub_code'] ?? '') ?> · <?= org_admin_h($org['pub_email'] ?? '') ?></div>
              <div style="margin-top:8px;"><?= org_admin_status_badge((int)($org['pub_user_status'] ?? 0)) ?></div>
            <?php else: ?>
              <span class="muted">No public_user link on this org</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="detail-box">
          <div class="label">Publisher registry</div>
          <div class="value">
            <?php if (!empty($org['registered_publisher_name'])): ?>
              <?php if (!empty($org['pub_user_id'])): ?>
                <a href="<?= org_admin_h(org_admin_user_activity_link((int)$org['pub_user_id'])) ?>"><?= org_admin_h($org['registered_publisher_name']) ?></a>
              <?php else: ?>
                <?= org_admin_h($org['registered_publisher_name']) ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">No publisher_name_options row</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($org['publisher_category'])): ?>
            <div class="muted" style="margin-top:6px;">Category: <?= org_admin_h($org['publisher_category']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="pro-tools">
        <strong>Members</strong>
        <span class="muted"><?= count($members) ?> membership row<?= count($members) === 1 ? '' : 's' ?></span>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Account</th>
              <th>Role</th>
              <th>Status</th>
              <th>Joined</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$members): ?>
            <tr><td colspan="6" class="text-center muted" style="padding:28px;">No members found.</td></tr>
          <?php else: foreach ($members as $m): ?>
            <?php
              $memberType = (string)($m['member_type'] ?? '');
              $accountStatus = (int)($m['account_status'] ?? 0);
              $memberId = (int)($m['member_id'] ?? 0);
            ?>
            <tr>
              <td><span class="pill <?= $memberType === 'staff' ? 'warn' : 'info' ?>"><?= org_admin_h(ucfirst($memberType)) ?></span></td>
              <td>
                <div><strong><?= org_admin_h($m['member_username'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($m['member_code'] ?? '') ?></div>
                <?php if (!empty($m['member_email'])): ?>
                  <div class="muted"><?= org_admin_h($m['member_email']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= org_admin_h($m['role_name'] ?? '') ?><?php if (!empty($m['relationship_label'])): ?><div class="muted"><?= org_admin_h($m['relationship_label']) ?></div><?php endif; ?></td>
              <td><?= org_admin_status_badge($accountStatus) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($m['joined_at'] ?? '')) ?></td>
              <td>
                <?php if ($memberType === 'staff' && $memberId > 0): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Change staff status?');">
                    <input type="hidden" name="staff_id" value="<?= $memberId ?>">
                    <input type="hidden" name="status_value" value="<?= $accountStatus === 1 ? 0 : 1 ?>">
                    <button type="submit" name="set_staff_status" class="btn-mini <?= $accountStatus === 1 ? 'warn' : 'primary' ?>">
                      <?= $accountStatus === 1 ? 'Disable' : 'Activate' ?>
                    </button>
                  </form>
                <?php elseif ($memberType === 'manager' && $memberId > 0 && $memberId !== (int)($org['manager_id'] ?? 0)): ?>
                  <span class="muted">Use manager panel</span>
                <?php else: ?>
                  <span class="muted">Owner</span>
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
<?php org_admin_render_foot(); ?>
