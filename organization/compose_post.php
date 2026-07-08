<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_feed_post.php';
require_once __DIR__ . '/includes/org_public_publish.php';

org_require_manager();

if (!isset($dbh) || !($dbh instanceof PDO)) {
    require_once __DIR__ . '/../admin/controller.php';
    $controller = new Controller();
    $dbh = $controller->pdo();
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function is_managerish(string $role): bool
{
    return in_array($role, ['admin', 'manager'], true);
}

$orgId = (int)($ORG['id'] ?? 0);
if ($orgId <= 0 && function_exists('orgActiveOrgId')) {
    $orgId = (int)orgActiveOrgId();
}
if ($orgId <= 0) {
    die('Invalid organization context.');
}

$meMemberId = function_exists('orgMemberId') ? (int)orgMemberId() : 0;
$meRole = '';
try {
    $stMe = $dbh->prepare("
        SELECT om.id, r.name AS role_name
        FROM org_members om
        LEFT JOIN org_roles r ON r.id = om.role_id
        WHERE om.org_id = :org AND om.id = :mid
        LIMIT 1
    ");
    $stMe->execute([':org' => $orgId, ':mid' => $meMemberId]);
    $meRow = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
    $meRole = strtolower(trim((string)($meRow['role_name'] ?? '')));
} catch (Throwable $e) {
    $meRole = '';
}
if ($meRole === '' && function_exists('isOrgManager') && isOrgManager()) {
    $meRole = 'manager';
}

if (empty($_SESSION['csrf_org_dash'])) {
    $_SESSION['csrf_org_dash'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_org_dash'];

function compose_post_csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf_org_dash'])
        && is_string($_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf_org_dash'], (string)$_POST['csrf']);
}

$publisherUserId = org_public_publish_publisher_user_id($dbh);
$postTypes = org_feed_post_types();

$flashErr = '';
$defaults = [
    'post_type' => (string)($_GET['type'] ?? 'announcement'),
    'visibility' => 'organization',
    'title' => '',
    'body' => '',
];

if (!array_key_exists($defaults['post_type'], $postTypes)) {
    $defaults['post_type'] = 'announcement';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!compose_post_csrf_ok()) {
        $flashErr = 'Security check failed. Please refresh and try again.';
    } else {
        try {
            $postType = (string)($_POST['post_type'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $visibility = (string)($_POST['visibility'] ?? 'organization');
            $alsoPublic = !empty($_POST['also_publish_public']);

            $result = org_feed_post_create(
                $dbh,
                $orgId,
                $meMemberId,
                $meRole,
                $postType,
                $title,
                $body,
                $visibility,
                $alsoPublic
            );

            $tab = org_feed_post_tab_for_type((string)$result['post_type']);
            $q = 'feed.php?id=' . (int)$result['post_id'] . '&tab=' . rawurlencode($tab) . '&posted=1';
            if ((int)$result['public_post_id'] > 0) {
                $q .= '&public=1';
            }
            header('Location: ' . $q);
            exit;
        } catch (Throwable $e) {
            $flashErr = $e->getMessage();
            $defaults['post_type'] = (string)($_POST['post_type'] ?? $defaults['post_type']);
            $defaults['visibility'] = (string)($_POST['visibility'] ?? 'organization');
            $defaults['title'] = trim((string)($_POST['title'] ?? ''));
            $defaults['body'] = trim((string)($_POST['body'] ?? ''));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h((string)($ORG['name'] ?? 'Organization')) ?> - New announcement</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    html, body { height: 100%; overflow: hidden; }
    .sh-mainpanel { height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
    .sh-pagebody {
      flex: 1 1 auto; overflow: hidden; padding-bottom: 0 !important;
      display: flex; flex-direction: column; min-height: 0;
    }
    .compose-card { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
    .card-body-fixed { flex: 1 1 auto; min-height: 0; overflow: hidden; display: flex; flex-direction: column; }
    .rows-scroll { flex: 1 1 auto; min-height: 0; overflow: auto; padding: 15px; }
    .actions-fixed {
      flex: 0 0 auto; padding: 12px 15px; border-top: 1px solid rgba(177,188,206,.12);
      background: #171d24; display: flex; gap: 10px; flex-wrap: wrap;
      justify-content: flex-end; align-items: center;
    }
    .compose-intro {
      margin-bottom: 14px; padding: 12px 14px; border-radius: 10px;
      background: #171d24; border: 1px solid rgba(177,188,206,.18);
      font-size: 12px; line-height: 1.45;
    }
    .compose-preview {
      margin-top: 14px; padding: 12px 14px; border-radius: 10px;
      border: 1px dashed rgba(177,188,206,.25); background: #171d24;
    }
    .compose-preview .feed-badge { display: inline-block; margin-bottom: 6px; }
    .publisher-check { display: flex; gap: 8px; align-items: flex-start; margin: 10px 0; }
    .publisher-check .hint { display: block; opacity: .75; font-size: 11px; margin-top: 2px; }
  </style>
</head>

<body class="org-app">
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody" style="border-bottom: 1px solid #4a535c;">
    <div class="card bd-0 compose-card">
      <div class="card-body card-body-fixed">

        <form method="post" enctype="multipart/form-data" autocomplete="off" style="height:100%;display:flex;flex-direction:column;min-height:0;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <div class="rows-scroll">

            <div class="compose-intro">
              <strong>Organization feed announcement</strong> — this posts to your team feed
              (<a href="feed.php">feed.php</a>), not the public social feed.
              Staff see it under Work or Culture tabs.
            </div>

            <?php if ($flashErr): ?>
              <div class="alert alert-danger"><?= h($flashErr) ?></div>
            <?php endif; ?>

            <div class="form-row">
              <div class="form-group col-md-4">
                <label class="mini-muted">Type</label>
                <select name="post_type" id="postType" class="form-control" required>
                  <?php foreach ($postTypes as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $defaults['post_type'] === $value ? ' selected' : '' ?>>
                      <?= h($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group col-md-4">
                <label class="mini-muted">Visibility</label>
                <select name="visibility" class="form-control">
                  <option value="organization"<?= $defaults['visibility'] === 'organization' ? ' selected' : '' ?>>Organization</option>
                  <option value="team"<?= $defaults['visibility'] === 'team' ? ' selected' : '' ?>>Team</option>
                </select>
              </div>

              <div class="form-group col-md-4">
                <label class="mini-muted">Title (optional)</label>
                <input type="text" name="title" id="postTitle" maxlength="200" class="form-control"
                  value="<?= h($defaults['title']) ?>" placeholder="Short title (optional)">
              </div>
            </div>

            <div class="form-group">
              <label class="mini-muted">Message</label>
              <textarea name="body" id="postBody" class="form-control" rows="6" required
                placeholder="Share an announcement, direction, update, or recognition…"><?= h($defaults['body']) ?></textarea>
            </div>

            <?php if ($publisherUserId > 0): ?>
            <div class="publisher-check">
              <input type="checkbox" name="also_publish_public" value="1" id="alsoPublishPublic" class="mg-t-2">
              <label for="alsoPublishPublic">
                Also publish to public feed
                <span class="hint">Optional — shares a copy with followers on your public publisher page.</span>
              </label>
            </div>
            <?php endif; ?>

            <div class="form-group">
              <label class="mini-muted">Attachments (optional)</label>
              <input
                type="file"
                name="attachments[]"
                class="form-control"
                multiple
                accept="image/*,video/*,application/pdf,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation">
              <div class="mini-muted" style="margin-top:6px;">
                Images, videos, PDF, PowerPoint. Max 35MB each.
              </div>
            </div>

            <div class="compose-preview" aria-live="polite">
              <span class="feed-badge" id="previewBadge">Announcement</span>
              <div id="previewTitle" style="font-weight:700;margin-bottom:4px;">Title preview</div>
              <div id="previewBody" class="mini-muted">Your message will appear here on the feed card.</div>
            </div>

          </div>

          <div class="actions-fixed">
            <a href="feed.php" class="btn btn-outline-secondary">Cancel</a>
            <button class="btn btn-primary" type="submit">
              <i class="fa fa-paper-plane mg-r-5"></i> Post to organization feed
            </button>
          </div>
        </form>

      </div>
    </div>
  </div>
</div>

<?php org_layout_footer_assets(); ?>
<script>
(function () {
  var typeEl = document.getElementById('postType');
  var titleEl = document.getElementById('postTitle');
  var bodyEl = document.getElementById('postBody');
  var badgeEl = document.getElementById('previewBadge');
  var previewTitle = document.getElementById('previewTitle');
  var previewBody = document.getElementById('previewBody');
  if (!typeEl || !bodyEl) return;

  var labels = {
    announcement: 'Announcement',
    direction: 'Direction',
    update: 'Update',
    weekly_update: 'Weekly Update',
    recognition: 'Recognition'
  };

  function syncPreview() {
    var t = typeEl.value || 'update';
    if (badgeEl) badgeEl.textContent = labels[t] || t;
    var title = (titleEl && titleEl.value) ? titleEl.value.trim() : '';
    if (previewTitle) previewTitle.textContent = title || (labels[t] || 'Update');
    var body = (bodyEl.value || '').trim();
    if (previewBody) previewBody.textContent = body || 'Your message will appear here on the feed card.';
  }

  typeEl.addEventListener('change', syncPreview);
  if (titleEl) titleEl.addEventListener('input', syncPreview);
  bodyEl.addEventListener('input', syncPreview);
  syncPreview();
})();
</script>
</body>
</html>
