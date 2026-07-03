<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$userId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: account_search.php');
    exit;
}

$user = org_admin_get_public_user($dbh, $userId);
if (!$user) {
    header('Location: account_search.php');
    exit;
}

$isPublisher = strtolower(trim((string)($user['account_kind'] ?? ''))) === 'publisher';
$postCount = org_admin_user_post_count($dbh, $userId);
$followerCount = $isPublisher ? org_admin_user_follower_count($dbh, $userId) : 0;
$friendCount = !$isPublisher ? org_admin_user_friend_count($dbh, $userId) : 0;
$posts = org_admin_user_recent_posts($dbh, $userId, 30);
$orgLinks = org_admin_user_org_links($dbh, $userId);
$approval = $isPublisher
    ? org_admin_user_publisher_approval($dbh, $userId, (string)($user['name'] ?? ''))
    : null;

$displayName = trim((string)($user['name'] ?? ''));
$username = trim((string)($user['username'] ?? ''));
$friendCode = trim((string)($user['friend_code'] ?? ''));
$profileUrl = org_admin_public_profile_url($userId, $username, $friendCode);
$feedUrl = '../public_user/feed.php';
$publicUrl = '../public_user/public.php';

org_admin_render_head('Public activity · ' . ($displayName !== '' ? $displayName : $username));
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-person"></i></div>
      <div>
        <h2><?= org_admin_h($displayName !== '' ? $displayName : $username) ?></h2>
        <p class="mg-b-0">What this account does on public_user</p>
      </div>
    </div>
    <div class="sh-pagetitle-right" style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="userlist.php" class="btn-mini">Back to list</a>
      <a href="user_form.php?user_id=<?= (int)$userId ?>" class="btn-mini primary">Edit user</a>
      <a href="<?= org_admin_h($profileUrl) ?>" target="_blank" rel="noopener" class="btn-mini">Open profile</a>
      <a href="<?= org_admin_h($feedUrl) ?>" target="_blank" rel="noopener" class="btn-mini">Open feed</a>
    </div>
  </div>

  <div class="sh-pagebody">
    <div class="card admin-card">
      <div class="card-header pro">
        Public User Activity
        <div class="sub">
          <?= org_admin_h($friendCode) ?>
          · <?= $isPublisher ? 'Publisher' : 'Personal' ?>
          · <?= org_admin_status_badge((int)($user['status'] ?? 0)) ?>
        </div>
      </div>

      <div class="detail-grid">
        <div class="detail-box">
          <div class="label">Account</div>
          <div class="value"><?= org_admin_h($username) ?></div>
          <div class="muted" style="margin-top:6px;"><?= org_admin_h($user['email'] ?? '') ?></div>
          <?php if (!empty($user['designation'])): ?>
            <div class="muted" style="margin-top:6px;"><?= org_admin_h($user['designation']) ?></div>
          <?php endif; ?>
        </div>
        <div class="detail-box">
          <div class="label">Activity stats</div>
          <div class="value"><?= (int)$postCount ?> post<?= $postCount === 1 ? '' : 's' ?></div>
          <div class="muted" style="margin-top:6px;">
            <?php if ($isPublisher): ?>
              <?= (int)$followerCount ?> follower<?= $followerCount === 1 ? '' : 's' ?>
            <?php else: ?>
              <?= (int)$friendCount ?> friend<?= $friendCount === 1 ? '' : 's' ?>
            <?php endif; ?>
          </div>
          <div class="muted" style="margin-top:6px;">Joined <?= org_admin_h(org_admin_fmt_dt($user['created_at'] ?? '')) ?></div>
          <?php if (!empty($user['last_seen'])): ?>
            <div class="muted">Last seen <?= org_admin_h(org_admin_fmt_dt($user['last_seen'])) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($isPublisher): ?>
        <div class="detail-box">
          <div class="label">Publisher</div>
          <div class="value"><?= org_admin_h($user['publisher_category'] ?? '') ?: 'Uncategorized' ?></div>
          <?php if (!empty($user['publisher_tagline'])): ?>
            <div class="muted" style="margin-top:6px;"><?= org_admin_h($user['publisher_tagline']) ?></div>
          <?php endif; ?>
          <?php if ($approval): ?>
            <div style="margin-top:8px;">
              <?php
                $apStatus = strtolower(trim((string)($approval['status'] ?? '')));
                $pillClass = $apStatus === 'approved' ? 'ok' : ($apStatus === 'rejected' ? 'bad' : 'warn');
              ?>
              <span class="pill <?= $pillClass ?>"><?= org_admin_h(ucfirst($apStatus !== '' ? $apStatus : 'unknown')) ?></span>
              <span class="muted"> · <?= org_admin_h($approval['publisher_name'] ?? '') ?></span>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="detail-box">
          <div class="label">Open on public_user</div>
          <div class="value" style="display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
            <a class="btn-mini primary" href="<?= org_admin_h($profileUrl) ?>" target="_blank" rel="noopener">Profile page</a>
            <a class="btn-mini" href="<?= org_admin_h($publicUrl) ?>" target="_blank" rel="noopener">Public browse</a>
          </div>
          <div class="muted" style="margin-top:8px;">These links open public_user in a new tab. You must be logged into public_user separately to see private content.</div>
        </div>
      </div>

      <?php if ($orgLinks): ?>
      <div class="pro-tools"><strong>Linked organizations</strong></div>
      <div class="table-scroll" style="max-height:180px;">
        <table class="table admin-table">
          <thead><tr><th>Organization</th><th>Type</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($orgLinks as $org): ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($org['name'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($org['org_code'] ?? '') ?></div>
              </td>
              <td><?= (int)($org['is_publisher_org'] ?? 0) === 1 ? '<span class="pill info">Publisher</span>' : '<span class="pill">Regular</span>' ?></td>
              <td><?= org_admin_status_badge((int)($org['status'] ?? 0)) ?></td>
              <td><a class="btn-mini" href="orgdetail.php?id=<?= (int)($org['id'] ?? 0) ?>">Admin org view</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="pro-tools">
        <strong>Recent public posts</strong>
        <span class="muted"><?= count($posts) ?> shown</span>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>Post</th>
              <th>Visibility</th>
              <th>Views</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$posts): ?>
            <tr><td colspan="5" class="text-center muted" style="padding:28px;">No public posts yet.</td></tr>
          <?php else: foreach ($posts as $post): ?>
            <?php
              $postId = (int)($post['id'] ?? 0);
              $title = trim((string)($post['title'] ?? ''));
              $desc = trim((string)($post['description'] ?? ''));
              $preview = $title !== '' ? $title : ($desc !== '' ? $desc : ('Post #' . $postId));
            ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($preview) ?></strong></div>
                <?php if ($title !== '' && $desc !== ''): ?>
                  <div class="muted"><?= org_admin_h(strlen($desc) > 120 ? substr($desc, 0, 120) . '…' : $desc) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="pill"><?= org_admin_h($post['visibility'] ?? 'public') ?></span></td>
              <td class="muted"><?= (int)($post['views_count'] ?? 0) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($post['created_at'] ?? '')) ?></td>
              <td>
                <a class="btn-mini" href="<?= org_admin_h(org_admin_public_post_url($postId)) ?>" target="_blank" rel="noopener">View post</a>
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
