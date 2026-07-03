<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_account_helpers_load.php';
admin_account_require();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = admin_account_db();
$adminId = (int)($_GET['admin_id'] ?? $_GET['id'] ?? 0);
$isEdit = $adminId > 0;
$admin = $isEdit ? admin_account_get_full($dbh, $adminId) : null;

if ($isEdit && !$admin) {
    header('Location: adminroles.php');
    exit;
}

$roles = admin_account_roles($dbh);
$msg = '';
$error = '';
$createdFriendCode = '';

$defaults = [
    'fullname' => '',
    'username' => '',
    'email' => '',
    'password' => '',
    'gender' => 'N/A',
    'mobile' => 'N/A',
    'designation' => 'Internal',
    'role' => 1,
    'status' => 1,
];

$form = $isEdit ? array_merge($defaults, $admin) : $defaults;
$form['password'] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin'])) {
    $input = admin_account_normalize_input($_POST);
    if ($isEdit) {
        $result = admin_account_update($dbh, $adminId, $input);
        if (!empty($result['ok'])) {
            header('Location: adminroles.php?msg=' . rawurlencode('Admin account updated successfully.'));
            exit;
        }
        $error = (string)($result['error'] ?? 'Update failed.');
        $form = array_merge($form, $input);
        $form['password'] = '';
    } else {
        $result = admin_account_create($dbh, $input);
        if (!empty($result['ok'])) {
            $q = 'msg=' . rawurlencode('Admin account created successfully.');
            if (!empty($result['friend_code'])) {
                $q .= '&fc=' . rawurlencode((string)$result['friend_code']);
            }
            header('Location: adminroles.php?' . $q);
            exit;
        }
        $error = (string)($result['error'] ?? 'Create failed.');
        $form = array_merge($form, $input);
        $form['password'] = '';
    }
}

$pageTitle = $isEdit ? 'Edit admin account' : '';
org_admin_render_head($pageTitle);
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagebody">
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card" style="max-width:920px;margin:0 auto;">
      <div class="card-header pro">
        <?= $isEdit ? 'Edit Admin #' . (int)$adminId : 'New Admin Account' ?>
        <div class="sh-pagetitle-right" style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="adminroles.php" class="btn-mini">Back to list</a>
    </div>
        <?php if ($isEdit && !empty($admin['friend_code'])): ?>
          <div class="sub">Friend code: <?= org_admin_h($admin['friend_code']) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-scroll">
        <form method="post" autocomplete="off" class="user-crud-form">
          <input type="hidden" name="save_admin" value="1">

          <div class="field">
            <label>Status</label>
            <select name="status" class="form-control">
              <option value="1" <?= (int)($form['status'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= (int)($form['status'] ?? 1) === 0 ? 'selected' : '' ?>>Blocked / inactive</option>
            </select>
          </div>

          <div class="field">
            <label>Full name <span class="req">*</span></label>
            <input type="text" name="fullname" class="form-control" required maxlength="20"
                   value="<?= org_admin_h($form['fullname'] ?? '') ?>">
          </div>

          <div class="form-row-2">
            <div class="field">
              <label>Username <span class="req">*</span></label>
              <input type="text" name="username" class="form-control" required maxlength="100"
                     value="<?= org_admin_h($form['username'] ?? '') ?>">
            </div>
            <div class="field">
              <label>Email <span class="req">*</span></label>
              <input type="email" name="email" class="form-control" required maxlength="100"
                     value="<?= org_admin_h($form['email'] ?? '') ?>">
            </div>
          </div>

          <div class="field">
            <label>Password <?= $isEdit ? '<span class="hint">(leave blank to keep current)</span>' : '<span class="req">*</span>' ?></label>
            <input type="password" name="password" class="form-control" <?= $isEdit ? '' : 'required' ?> minlength="6"
                   autocomplete="new-password" placeholder="<?= $isEdit ? 'Unchanged if empty' : 'Min. 6 characters' ?>">
          </div>

          <div class="form-row-2">
            <div class="field">
              <label>Gender</label>
              <input type="text" name="gender" class="form-control" maxlength="50"
                     value="<?= org_admin_h($form['gender'] ?? 'N/A') ?>">
            </div>
            <div class="field">
              <label>Phone</label>
              <input type="text" name="mobile" class="form-control" maxlength="50"
                     value="<?= org_admin_h($form['mobile'] ?? 'N/A') ?>">
            </div>
          </div>

          <div class="form-row-2">
            <div class="field">
              <label>Designation</label>
              <input type="text" name="designation" class="form-control" maxlength="50"
                     value="<?= org_admin_h($form['designation'] ?? 'Internal') ?>">
            </div>
            <div class="field">
              <label>Role <span class="req">*</span></label>
              <select name="role" class="form-control" required>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= (int)$r['idrole'] ?>" <?= (int)($form['role'] ?? 1) === (int)$r['idrole'] ? 'selected' : '' ?>>
                    <?= org_admin_h($r['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-actions">
            <a href="adminroles.php" class="btn-mini">Cancel</a>
            <button type="submit" class="btn-mini primary">
              <?= $isEdit ? 'Save changes' : 'Create admin' ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
  .form-scroll{ flex:1 1 auto; min-height:0; overflow:auto; padding:20px 22px 28px; }
  .user-crud-form{ display:flex; flex-direction:column; gap:14px; }
  .form-row-2{ display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  @media (max-width:720px){ .form-row-2{ grid-template-columns:1fr; } }
  .field label{ display:block; font-size:12px; font-weight:800; color:#334155; margin-bottom:6px; }
  .field .req{ color:#dc2626; }
  .field .hint{ font-weight:600; color:#64748b; }
  .form-control{
    width:100%; border:1px solid rgba(17,24,39,.12); border-radius:12px;
    padding:10px 12px; font-size:14px;
  }
  .form-actions{ display:flex; justify-content:flex-end; gap:10px; padding-top:8px; }
</style>

<?php org_admin_render_foot(); ?>
