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

$captureSources = [
    'web' => ['label' => 'Website', 'icon' => 'ion-ios-world', 'desc' => 'Web form or landing page inquiry'],
    'portal' => ['label' => 'Portal', 'icon' => 'ion-ios-keypad', 'desc' => 'Customer or partner portal signup'],
    'phone' => ['label' => 'Phone', 'icon' => 'ion-ios-telephone', 'desc' => 'Inbound or outbound call lead'],
];

$source = strtolower(trim((string)($_GET['source'] ?? 'web')));
if (!isset($captureSources[$source])) {
    $source = 'web';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = org_crm_capture_lead($dbh, $orgId, $_POST, $memberId);
    if (!empty($res['ok'])) {
        $ok = 'Lead captured.';
        if (!empty($res['contact_id'])) {
            header('Location: crm_contact.php?id=' . (int)$res['contact_id']);
            exit;
        }
    } else {
        $err = (string)($res['error'] ?? 'Could not capture lead.');
    }
}

$pageTitle = 'Capture leads';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="mg-b-20">
    <a href="crm.php" class="tx-12">&larr; CRM lifecycle</a>
    <h4 class="mg-b-0">Capture</h4>
    <p class="tx-color-03">Log leads from website, portal, or phone into the CRM hub.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <div class="row row-sm mg-b-20">
    <?php foreach ($captureSources as $key => $src): ?>
    <div class="col-md-4 mg-b-15">
      <a href="crm_capture.php?source=<?= org_crm_h($key) ?>" class="card shadow-base d-block" style="text-decoration:none;color:inherit;<?= $source === $key ? 'border:2px solid #0861bc;' : '' ?>">
        <div class="card-body text-center">
          <i class="icon <?= org_crm_h($src['icon']) ?> tx-32 tx-primary"></i>
          <h6 class="mg-t-10"><?= org_crm_h($src['label']) ?></h6>
          <p class="tx-12 tx-color-03 mg-b-0"><?= org_crm_h($src['desc']) ?></p>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card shadow-base">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">New lead — <?= org_crm_h($captureSources[$source]['label'] ?? 'Website') ?></h6></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="lead_source" value="<?= org_crm_h($source) ?>">
        <input type="hidden" name="lifecycle_stage" value="lead">
        <div class="row">
          <div class="col-md-4 form-group"><label>Full name *</label><input name="full_name" class="form-control" required></div>
          <div class="col-md-4 form-group"><label>Email</label><input name="email" type="email" class="form-control"></div>
          <div class="col-md-4 form-group"><label>Phone</label><input name="phone" class="form-control"></div>
          <div class="col-md-4 form-group"><label>Company</label><input name="company" class="form-control"></div>
          <div class="col-md-4 form-group"><label>Job title</label><input name="job_title" class="form-control"></div>
          <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Call summary, form details, or inquiry context"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Capture lead</button>
        <a href="crm_contacts.php?import=1" class="btn btn-outline-secondary mg-l-5">Import shop buyers</a>
      </form>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
