<?php
declare(strict_types=1);

/**
 * Admin account create/edit — viewport-fit UI matching user_form.php.
 * Preserves save_admin create/update + redirect to adminroles.php.
 */
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
$error = '';

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

$msg = (($_GET['msg'] ?? '') === 'saved') ? 'Changes saved.' : '';
$openEdit = $error !== '' && $isEdit;

$fullname = trim((string)($form['fullname'] ?? ''));
$username = trim((string)($form['username'] ?? ''));
$email = trim((string)($form['email'] ?? ''));
$mobile = trim((string)($form['mobile'] ?? ''));
$gender = trim((string)($form['gender'] ?? 'N/A'));
$designation = trim((string)($form['designation'] ?? 'Internal'));
$friendCode = trim((string)(($admin['friend_code'] ?? '') ?: ''));
$isActive = (int)($form['status'] ?? 1) === 1;
$createdAt = (string)(($admin['created_at'] ?? '') ?: '');
$lastLoginAt = (string)(($admin['last_login_at'] ?? '') ?: '');
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);
$isSelf = $isEdit && $currentAdminId > 0 && $adminId === $currentAdminId;

$roleName = '';
foreach ($roles as $r) {
    if ((int)$r['idrole'] === (int)($form['role'] ?? 1)) {
        $roleName = (string)$r['name'];
        break;
    }
}

$iniSrc = $fullname !== '' ? $fullname : ($username !== '' ? $username : 'A');
$parts = preg_split('/\s+/', trim(str_replace(['_', '.', '-', '@'], ' ', $iniSrc))) ?: [];
$ini = strtoupper(mb_substr((string)($parts[0] ?? 'A'), 0, 1) . mb_substr((string)($parts[count($parts) > 1 ? count($parts) - 1 : 0] ?? ''), $parts && count($parts) > 1 ? 0 : 1, 1));
$hash = crc32(strtolower($email !== '' ? $email : $iniSrc));
$palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
$avBg = $palette[$hash % count($palette)];

$accountAgeDays = 0;
if ($createdAt !== '') {
    $cts = strtotime($createdAt);
    if ($cts) {
        $accountAgeDays = max(0, (int)floor((time() - $cts) / 86400));
    }
}

$totalAdmins = 0;
$sameRoleCount = 0;
try {
    $totalAdmins = (int)$dbh->query('SELECT COUNT(*) FROM admin')->fetchColumn();
    $st = $dbh->prepare('SELECT COUNT(*) FROM admin WHERE role = :r');
    $st->execute([':r' => (int)($form['role'] ?? 1)]);
    $sameRoleCount = (int)$st->fetchColumn();
} catch (Throwable $e) {
}

$pageTitle = $isEdit ? ('Admin · @' . ($username !== '' ? $username : (string)$adminId)) : 'New Admin';
org_admin_render_head($pageTitle);
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Admin Account');

$renderAdminFields = static function (array $form, array $roles, bool $isActive, bool $isEdit): void {
    ?>
    <input type="hidden" name="save_admin" value="1">
    <div class="af-row">
      <div class="af-field">
        <label>Status</label>
        <select name="status">
          <option value="1" <?= $isActive ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= !$isActive ? 'selected' : '' ?>>Blocked / inactive</option>
        </select>
      </div>
      <div class="af-field">
        <label>Role <span class="req">*</span></label>
        <select name="role" required>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['idrole'] ?>" <?= (int)($form['role'] ?? 1) === (int)$r['idrole'] ? 'selected' : '' ?>>
              <?= org_admin_h($r['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="af-field">
      <label>Full name <span class="req">*</span></label>
      <input type="text" name="fullname" required maxlength="20" value="<?= org_admin_h($form['fullname'] ?? '') ?>">
    </div>
    <div class="af-row">
      <div class="af-field">
        <label>Username <span class="req">*</span></label>
        <input type="text" name="username" required maxlength="100" value="<?= org_admin_h($form['username'] ?? '') ?>">
      </div>
      <div class="af-field">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" required maxlength="100" value="<?= org_admin_h($form['email'] ?? '') ?>">
      </div>
    </div>
    <div class="af-field">
      <label>Password <?php if ($isEdit): ?><span class="hint">(blank = keep)</span><?php else: ?><span class="req">*</span><?php endif; ?></label>
      <input type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6" autocomplete="new-password"
             placeholder="<?= $isEdit ? 'Unchanged if empty' : 'Min. 6 characters' ?>">
    </div>
    <div class="af-row">
      <div class="af-field">
        <label>Gender</label>
        <input type="text" name="gender" maxlength="50" value="<?= org_admin_h($form['gender'] ?? 'N/A') ?>">
      </div>
      <div class="af-field">
        <label>Phone</label>
        <input type="text" name="mobile" maxlength="50" value="<?= org_admin_h($form['mobile'] ?? 'N/A') ?>">
      </div>
    </div>
    <div class="af-field">
      <label>Designation</label>
      <input type="text" name="designation" maxlength="50" value="<?= org_admin_h($form['designation'] ?? 'Internal') ?>">
    </div>
    <?php
};
?>

<style>
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:4px !important;padding-bottom:4px !important;
  }
  .af-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:5px;overflow:hidden;padding:0 2px;box-sizing:border-box;
  }
  .af-btn{
    height:24px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    font-size:10px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:4px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .af-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .af-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .af-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .af-btn.danger{border-color:#fecaca;color:#b91c1c;}
  .af-btn.sm{height:20px;padding:0 6px;font-size:9px;}

  .af-hero{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:6px 10px;
    display:flex;align-items:flex-start;justify-content:space-between;gap:8px;min-width:0;
  }
  .af-hero-left{display:flex;gap:8px;min-width:0;align-items:flex-start;flex:1 1 auto;}
  .af-av{width:40px;height:40px;border-radius:999px;color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex:0 0 40px;}
  .af-hero h1{margin:0;font-size:14px;font-weight:800;color:#0f172a;line-height:1.15;display:inline-flex;align-items:center;gap:5px;}
  .af-hero .name{font-size:11px;color:#64748b;font-weight:600;margin-top:1px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .af-meta{margin-top:3px;display:grid;grid-template-columns:1fr 1fr;gap:1px 12px;}
  .af-meta-row{display:flex;align-items:center;gap:5px;font-size:10px;color:#475569;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .af-meta-row i{width:12px;color:#94a3b8;text-align:center;font-size:10px;flex:0 0 auto;}
  .af-hero-actions{display:flex;gap:5px;flex-wrap:wrap;align-items:center;justify-content:flex-end;}

  .af-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:800;}
  .af-badge.ok,.af-badge.green{background:#dcfce7;color:#15803d;}
  .af-badge.bad{background:#fee2e2;color:#b91c1c;}
  .af-badge.blue{background:#dbeafe;color:#1d4ed8;}
  .af-badge.gray{background:#f1f5f9;color:#475569;}

  .af-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px;min-width:0;}
  .af-metric{background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 8px;min-width:0;overflow:hidden;}
  .af-metric-top{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-bottom:1px;}
  .af-metric .lab{font-size:9px;font-weight:700;color:#64748b;}
  .af-metric .val{font-size:14px;font-weight:800;color:#0f172a;line-height:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .af-mico{width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:8px;flex:0 0 auto;}
  .af-mico.purple{background:#f5f3ff;color:#7c3aed;}
  .af-mico.blue{background:#dbeafe;color:#2563eb;}
  .af-mico.green{background:#dcfce7;color:#16a34a;}
  .af-mico.orange{background:#ffedd5;color:#ea580c;}
  .af-mico.yellow{background:#fef9c3;color:#ca8a04;}
  .af-mico.red{background:#fee2e2;color:#dc2626;}

  .af-summary{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 10px;
    display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;min-width:0;
  }
  .af-sum-item{min-width:0;overflow:hidden;}
  .af-sum-item .k{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;}
  .af-sum-item .v{font-size:10px;font-weight:700;color:#0f172a;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

  .af-tabs{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:8px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .af-tabs a{
    flex:0 0 auto;padding:5px 8px;font-size:10px;font-weight:700;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .af-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .af-tabs a:hover{color:#0f172a;text-decoration:none;}

  .af-board{
    flex:1 1 auto;min-height:0;min-width:0;
    display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,.85fr) minmax(0,.85fr);
    gap:5px;overflow:hidden;
  }
  .af-col{min-height:0;min-width:0;display:flex;flex-direction:column;gap:5px;overflow:hidden;}
  .af-card{
    background:#fff;border:1px solid #eef2f7;border-radius:8px;overflow:hidden;min-width:0;min-height:0;
    display:flex;flex-direction:column;
  }
  .af-card.flex{flex:1 1 auto;}
  .af-card-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;
    padding:4px 8px;border-bottom:1px solid #f1f5f9;
  }
  .af-card-hd h2{margin:0;font-size:11px;font-weight:800;color:#0f172a;}
  .af-card-bd{flex:1 1 auto;min-height:0;padding:6px 8px;overflow:hidden;}
  .af-card-bd.scroll{overflow:auto;overscroll-behavior:contain;}

  .af-form{display:flex;flex-direction:column;gap:6px;min-height:0;}
  .af-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px;}
  .af-field label{display:block;font-size:9px;font-weight:800;color:#64748b;margin:0 0 2px;}
  .af-field .req{color:#dc2626;}
  .af-field .hint{font-weight:600;color:#94a3b8;}
  .af-field input,.af-field select{
    width:100%;max-width:100%;height:28px;border:1px solid #e2e8f0;border-radius:6px;padding:0 7px;
    font-size:11px;color:#0f172a;background:#fff;box-sizing:border-box;
  }
  .af-actions{display:flex;justify-content:flex-end;gap:5px;margin-top:8px;}

  .af-kv{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px solid #f8fafc;font-size:10px;}
  .af-kv:last-child{border-bottom:0;}
  .af-kv .k{color:#64748b;font-weight:700;}
  .af-kv .v{color:#0f172a;font-weight:800;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%;}

  .af-note{
    background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:6px 7px;margin-bottom:5px;
    font-size:10px;color:#1e3a8a;line-height:1.35;
  }
  .af-note.warn{background:#fffbeb;border-color:#fde68a;color:#78350f;}
  .af-note:last-child{margin-bottom:0;}

  .af-quick{display:grid;grid-template-columns:1fr 1fr;gap:4px;}
  .af-qbtn{
    border:1px solid #e2e8f0;border-radius:6px;padding:7px 4px;background:#fff;text-align:center;
    font-size:9px;font-weight:800;color:#334155;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:2px;
  }
  .af-qbtn i{font-size:11px;}
  .af-qbtn:hover{text-decoration:none;background:#f8fafc;}
  .af-qbtn.green{border-color:#bbf7d0;background:#f0fdf4;color:#166534;}
  .af-qbtn.orange{border-color:#fed7aa;background:#fff7ed;color:#c2410c;}
  .af-qbtn.red{border-color:#fecaca;background:#fef2f2;color:#b91c1c;}
  .af-qbtn.blue{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;}
  .af-qbtn.is-disabled{opacity:.45;pointer-events:none;}

  .af-alert{flex:0 0 auto;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;}
  .af-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .af-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .af-drop{position:relative;}
  .af-drop-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:30;min-width:150px;
    background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(15,23,42,.12);padding:4px;
  }
  .af-drop.open .af-drop-menu{display:block;}
  .af-drop-menu a,.af-drop-menu button{
    display:block;width:100%;text-align:left;padding:6px 8px;border-radius:6px;font-size:11px;font-weight:700;
    color:#334155;text-decoration:none;border:0;background:transparent;cursor:pointer;
  }
  .af-drop-menu a:hover,.af-drop-menu button:hover{background:#f8fafc;}
  .af-empty{padding:6px 4px;text-align:center;color:#64748b;font-size:10px;}

  .af-modal{
    display:none;position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.4);
    align-items:center;justify-content:center;padding:16px;
  }
  .af-modal.open{display:flex;}
  .af-modal-panel{
    width:min(560px,100%);max-height:min(86vh,640px);background:#fff;border-radius:10px;
    border:1px solid #e2e8f0;box-shadow:0 20px 40px rgba(15,23,42,.2);
    display:flex;flex-direction:column;overflow:hidden;
  }
  .af-modal-hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #f1f5f9;}
  .af-modal-hd h3{margin:0;font-size:13px;font-weight:800;}
  .af-modal-bd{flex:1 1 auto;overflow:auto;padding:12px;min-height:0;}

  @media (max-width:1100px){
    .af-wrap{overflow:auto;}
    .af-board,.af-metrics,.af-summary,.af-meta,.af-row,.af-quick{grid-template-columns:1fr;}
    .af-col,.af-card{overflow:visible;max-height:none;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="af-wrap">
      <?php if ($error !== ''): ?><div class="af-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="af-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <section class="af-hero">
        <div class="af-hero-left">
          <div class="af-av" style="background:<?= org_admin_h($avBg) ?>;"><?= org_admin_h($ini) ?></div>
          <div style="min-width:0;">
            <h1>
              <?php if ($isEdit && $username !== ''): ?>@<?= org_admin_h($username) ?><?php elseif ($isEdit): ?>Admin #<?= (int)$adminId ?><?php else: ?>New Admin<?php endif; ?>
              <?php if ($isEdit): ?><i class="fa fa-shield" style="color:#2563eb;font-size:12px;" title="Admin account"></i><?php endif; ?>
            </h1>
            <div class="name">
              <?= org_admin_h($fullname !== '' ? $fullname : ($isEdit ? '—' : 'Create admin-side account')) ?>
              <?php if ($isEdit): ?>
                <span class="af-badge <?= $isActive ? 'ok' : 'bad' ?>"><?= $isActive ? 'Active' : 'Blocked' ?></span>
                <?php if ($isSelf): ?><span class="af-badge blue">You</span><?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="af-meta">
              <?php if ($isEdit): ?>
                <div class="af-meta-row"><i class="fa fa-calendar"></i> Created <?= $createdAt !== '' ? org_admin_h(org_admin_fmt_dt($createdAt)) : '—' ?></div>
                <div class="af-meta-row"><i class="fa fa-briefcase"></i> <?= org_admin_h($designation !== '' ? $designation : '—') ?></div>
                <div class="af-meta-row"><i class="fa fa-envelope"></i> <?= org_admin_h($email !== '' ? $email : '—') ?></div>
                <div class="af-meta-row"><i class="fa fa-hashtag"></i> Admin ID #<?= (int)$adminId ?><?= $friendCode !== '' ? ' · ' . org_admin_h($friendCode) : '' ?></div>
              <?php else: ?>
                <div class="af-meta-row"><i class="fa fa-info-circle"></i> Fill the form below to create an admin account</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="af-hero-actions">
          <?php if ($isEdit): ?>
            <div class="af-drop" id="afActionsDrop">
              <button type="button" class="af-btn" onclick="document.getElementById('afActionsDrop').classList.toggle('open')"><i class="fa fa-ellipsis-v"></i> Actions</button>
              <div class="af-drop-menu">
                <button type="button" onclick="afOpenEdit()">Edit account fields</button>
                <a href="roleslist.php">Manage Roles</a>
                <a href="adminroles.php">All admin accounts</a>
              </div>
            </div>
            <a class="af-btn" href="roleslist.php"><i class="fa fa-id-badge"></i> Manage Roles</a>
          <?php endif; ?>
          <a class="af-btn primary" href="adminroles.php"><i class="fa fa-angle-left"></i> Back to Admins</a>
        </div>
      </section>

      <div class="af-metrics">
        <div class="af-metric"><div class="af-metric-top"><span class="lab">Role</span><span class="af-mico blue"><i class="fa fa-shield"></i></span></div><div class="val"><?= org_admin_h($roleName !== '' ? $roleName : '—') ?></div></div>
        <div class="af-metric"><div class="af-metric-top"><span class="lab">Status</span><span class="af-mico <?= $isActive ? 'green' : 'red' ?>"><i class="fa <?= $isActive ? 'fa-check' : 'fa-ban' ?>"></i></span></div><div class="val"><?= $isActive ? 'Active' : 'Blocked' ?></div></div>
        <div class="af-metric"><div class="af-metric-top"><span class="lab">Age (days)</span><span class="af-mico purple"><i class="fa fa-clock-o"></i></span></div><div class="val"><?= $isEdit ? number_format($accountAgeDays) : '—' ?></div></div>
        <div class="af-metric"><div class="af-metric-top"><span class="lab">Same role</span><span class="af-mico orange"><i class="fa fa-users"></i></span></div><div class="val"><?= number_format($sameRoleCount) ?></div></div>
        <div class="af-metric"><div class="af-metric-top"><span class="lab">All admins</span><span class="af-mico yellow"><i class="fa fa-database"></i></span></div><div class="val"><?= number_format($totalAdmins) ?></div></div>
        <div class="af-metric"><div class="af-metric-top"><span class="lab">Friend code</span><span class="af-mico green"><i class="fa fa-key"></i></span></div><div class="val"><?= org_admin_h($friendCode !== '' ? $friendCode : ($isEdit ? '—' : 'Auto')) ?></div></div>
      </div>

      <div class="af-summary">
        <div class="af-sum-item"><div class="k">Status</div><div class="v"><span class="af-badge <?= $isActive ? 'ok' : 'bad' ?>"><?= $isActive ? 'Active' : 'Blocked' ?></span></div></div>
        <div class="af-sum-item"><div class="k">Role</div><div class="v"><?= org_admin_h($roleName !== '' ? $roleName : '—') ?></div></div>
        <div class="af-sum-item"><div class="k">Designation</div><div class="v"><?= org_admin_h($designation !== '' ? $designation : '—') ?></div></div>
        <div class="af-sum-item"><div class="k">Email</div><div class="v"><?= org_admin_h($email !== '' ? $email : '—') ?></div></div>
        <div class="af-sum-item"><div class="k">Phone</div><div class="v"><?= org_admin_h($mobile !== '' ? $mobile : '—') ?></div></div>
        <div class="af-sum-item"><div class="k">Last Login</div><div class="v"><?= org_admin_h($lastLoginAt !== '' ? org_admin_fmt_dt($lastLoginAt) : '—') ?></div></div>
        <div class="af-sum-item"><div class="k">Gender</div><div class="v"><?= org_admin_h($gender !== '' ? $gender : '—') ?></div></div>
      </div>

      <nav class="af-tabs">
        <?php if ($isEdit): ?>
          <a href="#afAccountCard" class="is-active">Overview</a>
          <a href="#afAccountCard">Edit Account</a>
          <a href="roleslist.php">Roles</a>
          <a href="adminroles.php">All Admins</a>
          <a href="#afNotes">Notes</a>
        <?php else: ?>
          <a href="#afCreateCard" class="is-active">Create Account</a>
        <?php endif; ?>
      </nav>

      <?php if (!$isEdit): ?>
        <div class="af-board" style="grid-template-columns:minmax(0,1fr);">
          <section class="af-card flex" id="afCreateCard">
            <div class="af-card-hd"><h2>Create Admin Account</h2></div>
            <div class="af-card-bd scroll">
              <form method="post" autocomplete="off" class="af-form">
                <?php $renderAdminFields($form, $roles, $isActive, false); ?>
                <div class="af-actions">
                  <a class="af-btn" href="adminroles.php">Cancel</a>
                  <button type="submit" class="af-btn primary">Create admin</button>
                </div>
              </form>
            </div>
          </section>
        </div>
      <?php else: ?>
      <div class="af-board">
        <div class="af-col">
          <section class="af-card flex" id="afAccountCard">
            <div class="af-card-hd">
              <h2>Edit Account</h2>
              <button type="button" class="af-btn sm" onclick="afOpenEdit()">Open modal</button>
            </div>
            <div class="af-card-bd scroll">
              <form method="post" autocomplete="off" class="af-form" id="afSaveForm">
                <?php $renderAdminFields($form, $roles, $isActive, true); ?>
                <div class="af-actions">
                  <a class="af-btn" href="adminroles.php">Cancel</a>
                  <button type="submit" class="af-btn primary">Save changes</button>
                </div>
              </form>
            </div>
          </section>
        </div>

        <div class="af-col">
          <section class="af-card flex">
            <div class="af-card-hd"><h2>Account Snapshot</h2></div>
            <div class="af-card-bd scroll">
              <div class="af-kv"><span class="k">Admin ID</span><span class="v">#<?= (int)$adminId ?></span></div>
              <div class="af-kv"><span class="k">Username</span><span class="v">@<?= org_admin_h($username !== '' ? $username : '—') ?></span></div>
              <div class="af-kv"><span class="k">Friend code</span><span class="v"><?= org_admin_h($friendCode !== '' ? $friendCode : '—') ?></span></div>
              <div class="af-kv"><span class="k">Created</span><span class="v"><?= org_admin_h($createdAt !== '' ? org_admin_fmt_dt($createdAt) : '—') ?></span></div>
              <div class="af-kv"><span class="k">Last login</span><span class="v"><?= org_admin_h($lastLoginAt !== '' ? org_admin_fmt_dt($lastLoginAt) : '—') ?></span></div>
              <div class="af-kv"><span class="k">Role</span><span class="v"><?= org_admin_h($roleName !== '' ? $roleName : '—') ?></span></div>
            </div>
          </section>

          <section class="af-card flex" id="afNotes">
            <div class="af-card-hd"><h2>Security Notes</h2></div>
            <div class="af-card-bd scroll">
              <?php if ($isSelf): ?>
                <div class="af-note warn">This is your signed-in admin account. Blocking or deleting yourself is restricted from the list page.</div>
              <?php endif; ?>
              <div class="af-note">Leave password blank to keep the current password. New passwords must be at least 6 characters.</div>
              <div class="af-note">Role changes take effect on the admin’s next authenticated request.</div>
            </div>
          </section>
        </div>

        <div class="af-col">
          <section class="af-card" style="flex:0 0 auto;">
            <div class="af-card-hd"><h2>Access Summary</h2></div>
            <div class="af-card-bd">
              <div class="af-kv"><span class="k">Can sign in</span><span class="v"><?= $isActive ? 'Yes' : 'No' ?></span></div>
              <div class="af-kv"><span class="k">Admins with role</span><span class="v"><?= number_format($sameRoleCount) ?></span></div>
              <div class="af-kv"><span class="k">Total admins</span><span class="v"><?= number_format($totalAdmins) ?></span></div>
              <div class="af-kv"><span class="k">Account age</span><span class="v"><?= number_format($accountAgeDays) ?>d</span></div>
            </div>
          </section>

          <section class="af-card flex">
            <div class="af-card-hd"><h2>Quick Actions</h2></div>
            <div class="af-card-bd">
              <div class="af-quick">
                <button type="submit" form="afSaveForm" class="af-qbtn green"><i class="fa fa-save"></i> Save Changes</button>
                <button type="button" class="af-qbtn blue" onclick="afOpenEdit()"><i class="fa fa-pencil"></i> Edit Modal</button>
                <a class="af-qbtn orange" href="roleslist.php"><i class="fa fa-id-badge"></i> Manage Roles</a>
                <a class="af-qbtn" href="adminroles.php"><i class="fa fa-list"></i> All Admins</a>
                <a class="af-qbtn <?= $isSelf ? 'is-disabled' : 'red' ?>" href="adminroles.php" title="<?= $isSelf ? 'Use list page for other accounts' : 'Block/delete from list' ?>"><i class="fa fa-ban"></i> Status Tools</a>
                <a class="af-qbtn" href="admin_form.php"><i class="fa fa-plus"></i> New Admin</a>
              </div>
            </div>
          </section>
        </div>
      </div>

      <div class="af-modal<?= $openEdit ? ' open' : '' ?>" id="afEditModal" role="dialog" aria-modal="true">
        <div class="af-modal-panel">
          <div class="af-modal-hd">
            <h3>Edit Admin Account</h3>
            <button type="button" class="af-btn" onclick="afCloseEdit()">Close</button>
          </div>
          <div class="af-modal-bd">
            <form method="post" autocomplete="off" class="af-form">
              <?php $renderAdminFields($form, $roles, $isActive, true); ?>
              <div class="af-actions">
                <button type="button" class="af-btn" onclick="afCloseEdit()">Cancel</button>
                <button type="submit" class="af-btn primary">Save changes</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
(function(){
  document.addEventListener('click', function(e){
    var drop = document.getElementById('afActionsDrop');
    if (!drop) return;
    if (!drop.contains(e.target)) drop.classList.remove('open');
  });
  window.afOpenEdit = function(){
    var m = document.getElementById('afEditModal');
    if (m) m.classList.add('open');
    var d = document.getElementById('afActionsDrop');
    if (d) d.classList.remove('open');
  };
  window.afCloseEdit = function(){
    var m = document.getElementById('afEditModal');
    if (m) m.classList.remove('open');
  };
  var modal = document.getElementById('afEditModal');
  if (modal) {
    modal.addEventListener('click', function(e){
      if (e.target === modal) afCloseEdit();
    });
  }
})();
</script>
<?php org_admin_render_foot(); ?>
