<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
org_require_manager();

org_require_commerce_seller();
require_once __DIR__ . '/includes/org_crm_lifecycle.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);

$err = '';
$ok = '';
$stage = strtolower(trim((string)($_GET['stage'] ?? 'all')));
$q = trim((string)($_GET['q'] ?? ''));
$showNew = isset($_GET['new']);
$editId = (int)($_GET['edit'] ?? 0);

if (isset($_GET['import']) && $_GET['import'] === '1') {
    $imp = org_crm_import_shop_buyers($dbh, $orgId, $memberId);
    if (!empty($imp['ok'])) {
        $ok = 'Imported ' . (int)($imp['imported'] ?? 0) . ' buyers from shop orders.';
    } else {
        $err = (string)($imp['error'] ?? 'Import failed.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_contact'])) {
        $cid = (int)($_POST['contact_id'] ?? 0);
        try {
            $dbh->prepare('UPDATE org_crm_contacts SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                ->execute([':id' => $cid, ':org' => $orgId]);
            $ok = 'Contact removed.';
        } catch (Throwable $e) {
            $err = 'Could not remove contact.';
        }
    } else {
        $pid = (int)($_POST['contact_id'] ?? 0);
        $res = org_crm_save_contact($dbh, $orgId, $_POST, $pid > 0 ? $pid : null, $memberId);
        if (!empty($res['ok'])) {
            $ok = $pid > 0 ? 'Contact updated.' : 'Contact created.';
            $editId = 0;
            $showNew = false;
        } else {
            $err = (string)($res['error'] ?? 'Save failed.');
            $showNew = true;
            $editId = $pid;
        }
    }
}

$contacts = org_crm_list_contacts($dbh, $orgId, $stage, $q);
$editContact = $editId > 0 ? org_crm_get_contact($dbh, $orgId, $editId) : null;
if ($showNew && !$editContact) {
    $editContact = ['lifecycle_stage' => 'lead', 'lead_source' => 'manual'];
}

$pageTitle = 'CRM Contacts';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="card bd-0 shadow-base mg-b-20">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
      <h6 class="card-title tx-uppercase tx-14 mg-b-0">Contacts &amp; leads</h6>
      <div class="d-flex flex-wrap" style="gap:8px;">
        <a href="crm.php" class="btn btn-sm btn-outline-secondary">CRM home</a>
        <a href="crm_contacts.php?new=1" class="btn btn-sm btn-primary">Add contact</a>
        <a href="crm_contacts.php?import=1" class="btn btn-sm btn-outline-secondary">Import shop buyers</a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

      <form method="get" class="form-inline mg-b-15">
        <input type="text" name="q" class="form-control form-control-sm mg-r-10" placeholder="Search" value="<?= org_crm_h($q) ?>">
        <select name="stage" class="form-control form-control-sm mg-r-10">
          <?php foreach (['all','lead','prospect','customer','partner','churned'] as $s): ?>
            <option value="<?= $s ?>" <?= $stage === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
      </form>

      <?php if ($showNew || $editContact): ?>
      <form method="post" class="mg-b-20 pd-15 org-form-panel">
        <input type="hidden" name="contact_id" value="<?= (int)($editContact['id'] ?? 0) ?>">
        <div class="row">
          <div class="col-md-4 form-group"><label>Name *</label><input name="full_name" class="form-control" required value="<?= org_crm_h((string)($editContact['full_name'] ?? '')) ?>"></div>
          <div class="col-md-4 form-group"><label>Email</label><input name="email" type="email" class="form-control" value="<?= org_crm_h((string)($editContact['email'] ?? '')) ?>"></div>
          <div class="col-md-4 form-group"><label>Phone</label><input name="phone" class="form-control" value="<?= org_crm_h((string)($editContact['phone'] ?? '')) ?>"></div>
          <div class="col-md-4 form-group"><label>Company</label><input name="company" class="form-control" value="<?= org_crm_h((string)($editContact['company'] ?? '')) ?>"></div>
          <div class="col-md-4 form-group"><label>Job title</label><input name="job_title" class="form-control" value="<?= org_crm_h((string)($editContact['job_title'] ?? '')) ?>"></div>
          <div class="col-md-2 form-group"><label>Stage</label>
            <select name="lifecycle_stage" class="form-control">
              <?php foreach (['lead','prospect','customer','partner','churned'] as $s): ?>
                <option value="<?= $s ?>" <?= (($editContact['lifecycle_stage'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 form-group"><label>Source</label>
            <select name="lead_source" class="form-control">
              <?php foreach (['manual','shop','referral','web','portal','phone','import','other'] as $s): ?>
                <option value="<?= $s ?>" <?= (($editContact['lead_source'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= org_crm_h((string)($editContact['notes'] ?? '')) ?></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Save contact</button>
        <a href="crm_contacts.php" class="btn btn-light mg-l-5">Cancel</a>
      </form>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table table-hover mg-b-0">
          <thead><tr><th>Name</th><th>Email</th><th>Company</th><th>Stage</th><th>Source</th><th></th></tr></thead>
          <tbody>
          <?php if (!$contacts): ?>
            <tr><td colspan="6" class="text-center tx-color-03">No contacts yet.</td></tr>
          <?php else: foreach ($contacts as $c): ?>
            <tr>
              <td><a href="crm_contact.php?id=<?= (int)$c['id'] ?>"><strong><?= org_crm_h((string)$c['full_name']) ?></strong></a></td>
              <td><?= org_crm_h((string)($c['email'] ?? '')) ?></td>
              <td><?= org_crm_h((string)($c['company'] ?? '')) ?></td>
              <td><span class="badge <?= org_crm_stage_badge((string)$c['lifecycle_stage']) ?>"><?= org_crm_h((string)$c['lifecycle_stage']) ?></span></td>
              <td><?= org_crm_h((string)$c['lead_source']) ?></td>
              <td class="text-right">
                <a href="crm_contacts.php?edit=<?= (int)$c['id'] ?>" class="btn btn-xs btn-outline-primary">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove this contact?');">
                  <input type="hidden" name="delete_contact" value="1">
                  <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger">Delete</button>
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
<?php org_page_shell_close(); ?>
