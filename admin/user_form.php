<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/user_admin_helpers_load.php';
user_admin_require();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = user_admin_db();
$userId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);
$isEdit = $userId > 0;
$user = $isEdit ? user_admin_get_user_full($dbh, $userId) : null;

if ($isEdit && !$user) {
    header('Location: userlist.php');
    exit;
}

$roles = user_admin_roles($dbh);
$genders = user_admin_genders();
$pubCategories = user_admin_publisher_categories();
$msg = '';
$error = '';
$createdFriendCode = '';

$defaults = [
    'name' => '',
    'username' => '',
    'email' => '',
    'password' => '',
    'gender' => '',
    'mobile' => '',
    'designation' => '',
    'role' => 4,
    'account_kind' => 'personal',
    'publisher_category' => 'news',
    'publisher_tagline' => '',
    'status' => 1,
    'birthday' => '',
];

$form = $isEdit ? array_merge($defaults, $user) : $defaults;
if (!$isEdit) {
    $prefillKind = strtolower(trim((string)($_GET['account_kind'] ?? '')));
    if (in_array($prefillKind, ['personal', 'publisher'], true)) {
        $form['account_kind'] = $prefillKind;
    }
}
$form['password'] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $input = user_admin_normalize_input($_POST);
    if ($isEdit) {
        $result = user_admin_update($dbh, $userId, $input);
        if (!empty($result['ok'])) {
            header('Location: userlist.php?msg=' . rawurlencode('User updated successfully.'));
            exit;
        }
        $error = (string)($result['error'] ?? 'Update failed.');
        $form = array_merge($form, $input);
        $form['password'] = '';
    } else {
        $result = user_admin_create($dbh, $input);
        if (!empty($result['ok'])) {
            $q = 'msg=' . rawurlencode('User created successfully.');
            if (!empty($result['friend_code'])) {
                $q .= '&fc=' . rawurlencode((string)$result['friend_code']);
            }
            header('Location: userlist.php?' . $q);
            exit;
        }
        $error = (string)($result['error'] ?? 'Create failed.');
        $form = array_merge($form, $input);
        $form['password'] = '';
    }
}

$pageTitle = $isEdit ? 'Edit user' : 'Add user';
org_admin_render_head($pageTitle);
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-person"></i></div>
      <div>
        <h2><?= org_admin_h($pageTitle) ?></h2>
        <p class="mg-b-0"><?= $isEdit ? 'Update public_user account details' : 'Create a new public_user account' ?></p>
      </div>
    </div>
    <div class="sh-pagetitle-right" style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="userlist.php" class="btn-mini">Back to list</a>
      <?php if ($isEdit): ?>
        <a href="user_activity.php?user_id=<?= (int)$userId ?>" class="btn-mini">View activity</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="sh-pagebody">
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card" style="max-width:920px;margin:0 auto;">
      <div class="card-header pro">
        <?= $isEdit ? 'Edit User #' . (int)$userId : 'New Public User' ?>
        <?php if ($isEdit && !empty($user['friend_code'])): ?>
          <div class="sub">Friend code: <?= org_admin_h($user['friend_code']) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-scroll">
        <form method="post" autocomplete="off" class="user-crud-form">
          <input type="hidden" name="save_user" value="1">

          <div class="form-row-2">
            <div class="field">
              <label>Account type</label>
              <select name="account_kind" id="accountKind" class="form-control">
                <option value="personal" <?= ($form['account_kind'] ?? '') === 'personal' ? 'selected' : '' ?>>Personal</option>
                <option value="publisher" <?= ($form['account_kind'] ?? '') === 'publisher' ? 'selected' : '' ?>>Publisher</option>
              </select>
            </div>
            <div class="field">
              <label>Status</label>
              <select name="status" class="form-control">
                <option value="1" <?= (int)($form['status'] ?? 1) === 1 ? 'selected' : '' ?>>Confirmed / active</option>
                <option value="0" <?= (int)($form['status'] ?? 1) === 0 ? 'selected' : '' ?>>Unconfirmed / disabled</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label>Full name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="100"
                   value="<?= org_admin_h($form['name'] ?? '') ?>">
          </div>

          <div class="form-row-2">
            <div class="field">
              <label>Username <span class="req">*</span></label>
              <input type="text" name="username" class="form-control" required maxlength="50"
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

          <div class="form-row-2 personal-only">
            <div class="field">
              <label>Gender <span class="req personal-req">*</span></label>
              <select name="gender" class="form-control" id="genderField">
                <option value="">— Select —</option>
                <?php foreach ($genders as $g): ?>
                  <option value="<?= org_admin_h($g) ?>" <?= ($form['gender'] ?? '') === $g ? 'selected' : '' ?>><?= org_admin_h($g) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Phone <span class="req personal-req">*</span></label>
              <input type="text" name="mobile" class="form-control" maxlength="50"
                     value="<?= org_admin_h($form['mobile'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row-2">
            <div class="field">
              <label>Designation</label>
              <input type="text" name="designation" class="form-control" maxlength="255"
                     value="<?= org_admin_h($form['designation'] ?? '') ?>">
            </div>
            <div class="field personal-only">
              <label>Birthday</label>
              <input type="date" name="birthday" class="form-control"
                     value="<?= org_admin_h($form['birthday'] ?? '') ?>">
            </div>
          </div>

          <div class="field">
            <label>Role</label>
            <select name="role" class="form-control">
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['idrole'] ?>" <?= (int)($form['role'] ?? 4) === (int)$r['idrole'] ? 'selected' : '' ?>>
                  <?= org_admin_h($r['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="publisher-only">
            <div class="form-row-2">
              <div class="field">
                <label>Publisher category <span class="req">*</span></label>
                <select name="publisher_category" class="form-control">
                  <?php foreach ($pubCategories as $key => $label): ?>
                    <option value="<?= org_admin_h($key) ?>" <?= ($form['publisher_category'] ?? 'news') === $key ? 'selected' : '' ?>>
                      <?= org_admin_h($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Publisher tagline</label>
                <input type="text" name="publisher_tagline" class="form-control" maxlength="255"
                       value="<?= org_admin_h($form['publisher_tagline'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="form-actions">
            <a href="userlist.php" class="btn-mini">Cancel</a>
            <button type="submit" class="btn-mini primary">
              <?= $isEdit ? 'Save changes' : 'Create user' ?>
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
  .publisher-only{ display:none; }
  body.is-publisher .publisher-only{ display:block; }
  body.is-publisher .personal-only .personal-req{ display:none; }
</style>

<script>
(function(){
  var kind = document.getElementById('accountKind');
  function sync(){
    document.body.classList.toggle('is-publisher', kind && kind.value === 'publisher');
  }
  if (kind) {
    kind.addEventListener('change', sync);
    sync();
  }
})();
</script>

<?php org_admin_render_foot(); ?>
