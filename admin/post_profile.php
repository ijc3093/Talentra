<?php
declare(strict_types=1);

/**
 * Admin — Full post detail (viewport-fit, same visual language as posts side panel).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/posts_admin_helpers.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) {
    header('Location: posts.php');
    exit;
}

$detail = msb_mod_post_detail($dbh, $postId);
if (!$detail) {
    header('Location: posts.php');
    exit;
}

$eng = posts_admin_engagement($dbh, $postId);
$reportCount = 0;
$pendingCount = 0;
if (msb_mod_table_exists($dbh, 'public_user_reports')) {
    $reportCount = msb_mod_count_safe(
        $dbh,
        'SELECT COUNT(*) FROM public_user_reports WHERE target_type = \'post\' AND target_id = :id',
        [':id' => $postId]
    );
    $pendingCount = msb_mod_count_safe(
        $dbh,
        'SELECT COUNT(*) FROM public_user_reports WHERE target_type = \'post\' AND target_id = :id AND status = \'pending\'',
        [':id' => $postId]
    );
}
$statusInfo = posts_admin_status_from_row([
    'is_deleted' => (int)($detail['is_deleted'] ?? 0),
    'report_count' => $reportCount,
    'pending_count' => $pendingCount,
]);

$atts = $detail['attachments'] ?? [];
$types = [];
$hero = '';
$video = '';
foreach ($atts as $a) {
    $t = strtolower(trim((string)($a['type'] ?? 'file')));
    $types[] = $t;
    $fp = (string)($a['file_path'] ?? '');
    $tp = (string)($a['thumb_path'] ?? '');
    if ($t === 'video' && $fp !== '' && $video === '') {
        $video = $fp;
    }
    if ($hero === '' && $t === 'image' && $fp !== '') {
        $hero = $fp;
    } elseif ($hero === '' && $tp !== '') {
        $hero = $tp;
    }
}
$types = array_values(array_unique($types));
$postType = 'Text';
if (in_array('video', $types, true)) {
    $postType = 'Video';
} elseif (in_array('image', $types, true)) {
    $postType = 'Image';
} elseif (preg_match('~https?://~i', (string)($detail['body'] ?? $detail['description'] ?? ''))) {
    $postType = 'Link';
}

$bodyText = trim((string)($detail['body'] ?? ''));
if ($bodyText === '') {
    $bodyText = trim((string)($detail['description'] ?? ''));
}
if ($bodyText === '') {
    $bodyText = trim((string)($detail['title'] ?? ''));
}

$userId = (int)($detail['user_id'] ?? 0);
$username = (string)($detail['username'] ?? '');
$name = (string)($detail['name'] ?? '');
$vis = (string)($detail['visibility'] ?? 'public');
$ini = posts_admin_initials($name !== '' ? $name : ($username !== '' ? $username : 'U'));
$avBg = posts_admin_avatar_color($username !== '' ? $username : (string)$userId);
$heroUrl = posts_admin_media_url($hero);
$videoUrl = posts_admin_media_url($video);
$pidLabel = 'P-' . $postId;
$activityHref = org_admin_user_activity_link($userId) . '&post_id=' . $postId;

$visIcon = 'fa-globe';
if (strtolower($vis) === 'friends') {
    $visIcon = 'fa-users';
} elseif (strtolower($vis) === 'private') {
    $visIcon = 'fa-lock';
}

org_admin_render_head('Post ' . $pidLabel);
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Post Details');
?>

<style>
  html,body{height:100% !important;overflow:hidden !important;max-height:100dvh !important;}
  body.azia-admin{overflow:hidden !important;}
  .sh-mainpanel{
    height:100vh !important;max-height:100dvh !important;
    display:flex !important;flex-direction:column !important;overflow:hidden !important;
  }
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:8px !important;padding-bottom:8px !important;padding-left:10px !important;padding-right:10px !important;
    margin-left:0 !important;margin-right:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .pp-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .pp-top{flex:0 0 auto;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;min-width:0;}
  .pp-top h1{margin:0;font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.02em;}
  .pp-top p{margin:2px 0 0;font-size:11px;color:#64748b;}
  .pp-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .pp-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .pp-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .pp-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .pp-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .pp-btn.ghost{background:#fff;}

  .pp-grid{
    flex:1 1 auto;min-height:0;min-width:0;
    display:grid;grid-template-columns:minmax(0,1.2fr) minmax(260px,.8fr);gap:10px;overflow:hidden;
  }
  .pp-card{
    min-width:0;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .pp-card-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:12px 14px;border-bottom:1px solid #eef2f7;
  }
  .pp-card-hd h2{margin:0;font-size:14px;font-weight:800;color:#0f172a;}
  .pp-card-bd{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;padding:14px;}
  .pp-card-ft{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:12px 14px;border-top:1px solid #eef2f7;background:#fafbfc;
  }

  .ps-id{color:#2563eb;font-weight:800;text-decoration:none;white-space:nowrap;}
  .ps-id:hover{text-decoration:underline;}
  .ps-idrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
  .ps-copy{
    height:24px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    font-size:10px;font-weight:700;color:#475569;cursor:pointer;
  }
  .ps-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .ps-pill.published{background:#dcfce7;color:#15803d;}
  .ps-pill.pending{background:#ffedd5;color:#c2410c;}
  .ps-pill.flagged{background:#fee2e2;color:#b91c1c;}
  .ps-pill.removed{background:#f1f5f9;color:#64748b;}
  .ps-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:10px 0 14px;}
  .ps-dates .lab{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;}
  .ps-dates .val{font-size:11px;font-weight:700;color:#334155;margin-top:2px;}
  .ps-author{display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px;border-radius:10px;background:#f8fafc;}
  .ps-av{
    width:32px;height:32px;border-radius:999px;color:#fff;font-size:11px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 32px;
  }
  .ps-author .nm{font-weight:800;font-size:12px;color:#0f172a;}
  .ps-author .un{font-size:11px;color:#64748b;}
  .ps-meta{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#475569;white-space:nowrap;}
  .ps-meta i{color:#94a3b8;}
  .ps-body{font-size:13px;line-height:1.55;color:#0f172a;white-space:pre-wrap;word-break:break-word;margin-bottom:12px;}
  .ps-hero{
    width:100%;max-height:360px;object-fit:cover;border-radius:12px;background:#e2e8f0;margin-bottom:12px;display:block;
  }
  video.ps-hero{max-height:420px;object-fit:contain;background:#0f172a;}
  .ps-eng{
    display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:14px;
    font-size:12px;font-weight:700;color:#64748b;
  }
  .ps-eng span{display:inline-flex;align-items:center;gap:5px;}
  .ps-kv{display:flex;flex-direction:column;gap:0;border:1px solid #eef2f7;border-radius:10px;overflow:hidden;}
  .ps-kv-row{
    display:flex;justify-content:space-between;gap:10px;padding:9px 11px;
    border-bottom:1px solid #f1f5f9;font-size:11px;
  }
  .ps-kv-row:last-child{border-bottom:0;}
  .ps-kv-row .k{color:#64748b;font-weight:700;}
  .ps-kv-row .v{color:#0f172a;font-weight:800;text-align:right;}

  @media (max-width:900px){
    .pp-grid{grid-template-columns:1fr;overflow:auto;}
    .pp-wrap{overflow:auto !important;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="pp-wrap">

      <div class="pp-top">
        <div>
          <h1>Post Details</h1>
          <p>Full post record for <?= posts_admin_h($pidLabel) ?>.</p>
        </div>
        <div class="pp-actions">
          <a class="pp-btn" href="posts.php"><i class="fa fa-arrow-left"></i> Back to posts</a>
          <a class="pp-btn" href="posts.php?post_id=<?= $postId ?>"><i class="fa fa-list"></i> Open in list</a>
        </div>
      </div>

      <div class="pp-grid">
        <section class="pp-card">
          <div class="pp-card-hd">
            <h2>Content</h2>
            <span class="ps-pill <?= posts_admin_h($statusInfo['cls']) ?>"><?= posts_admin_h($statusInfo['label']) ?></span>
          </div>
          <div class="pp-card-bd">
            <div class="ps-idrow">
              <span class="ps-id"><?= posts_admin_h($pidLabel) ?></span>
              <button type="button" class="ps-copy" data-copy="<?= posts_admin_h($pidLabel) ?>">Copy</button>
            </div>
            <div class="ps-dates">
              <div>
                <div class="lab">Created</div>
                <div class="val"><?= posts_admin_h(posts_admin_fmt((string)($detail['created_at'] ?? ''))) ?></div>
              </div>
              <div>
                <div class="lab">Updated</div>
                <div class="val"><?= posts_admin_h(posts_admin_fmt((string)($detail['updated_at'] ?? ''))) ?></div>
              </div>
            </div>
            <div class="ps-author">
              <span class="ps-av" style="background:<?= posts_admin_h($avBg) ?>;"><?= posts_admin_h($ini) ?></span>
              <div style="min-width:0;">
                <div class="nm"><?= posts_admin_h($username !== '' ? '@' . $username : ('User #' . $userId)) ?></div>
                <div class="un"><?= posts_admin_h($name !== '' ? $name : '—') ?></div>
              </div>
              <span class="ps-meta" style="margin-left:auto;"><i class="fa <?= posts_admin_h($visIcon) ?>"></i> <?= posts_admin_h(ucfirst($vis)) ?></span>
            </div>
            <?php if ($bodyText !== ''): ?>
              <div class="ps-body"><?= posts_admin_h($bodyText) ?></div>
            <?php endif; ?>
            <?php if ($videoUrl !== ''): ?>
              <video class="ps-hero" src="<?= posts_admin_h($videoUrl) ?>" controls playsinline preload="metadata"<?= $heroUrl !== '' ? ' poster="' . posts_admin_h($heroUrl) . '"' : '' ?>></video>
            <?php elseif ($heroUrl !== ''): ?>
              <img class="ps-hero" src="<?= posts_admin_h($heroUrl) ?>" alt="">
            <?php endif; ?>
            <div class="ps-eng">
              <span><i class="fa fa-heart-o"></i> <?= number_format((int)$eng['likes']) ?> likes</span>
              <span><i class="fa fa-comment-o"></i> <?= number_format((int)$eng['comments']) ?> comments</span>
              <span><i class="fa fa-share-square-o"></i> <?= number_format((int)$eng['shares']) ?> shares</span>
              <span><i class="fa fa-bookmark-o"></i> <?= number_format((int)$eng['saves']) ?> saves</span>
            </div>
          </div>
          <div class="pp-card-ft">
            <?php if ($userId > 0): ?>
              <a class="pp-btn ghost" href="user_form.php?id=<?= $userId ?>">View User</a>
              <a class="pp-btn primary" href="<?= posts_admin_h($activityHref) ?>">View Activity</a>
            <?php endif; ?>
            <a class="pp-btn" href="reports.php?status=all&amp;q=<?= rawurlencode((string)$postId) ?>&amp;type=post">Reports</a>
            <a class="pp-btn" href="posts.php?post_id=<?= $postId ?>">Open in list</a>
          </div>
        </section>

        <aside class="pp-card">
          <div class="pp-card-hd">
            <h2>Attributes</h2>
          </div>
          <div class="pp-card-bd">
            <div class="ps-kv">
              <div class="ps-kv-row"><span class="k">Post ID</span><span class="v"><?= posts_admin_h($pidLabel) ?></span></div>
              <div class="ps-kv-row"><span class="k">Status</span><span class="v"><?= posts_admin_h($statusInfo['label']) ?></span></div>
              <div class="ps-kv-row"><span class="k">Type</span><span class="v"><?= posts_admin_h($postType) ?></span></div>
              <div class="ps-kv-row"><span class="k">Visibility</span><span class="v"><?= posts_admin_h(ucfirst($vis)) ?></span></div>
              <div class="ps-kv-row"><span class="k">Author</span><span class="v"><?= posts_admin_h($username !== '' ? '@' . $username : ('#' . $userId)) ?></span></div>
              <div class="ps-kv-row"><span class="k">Reports</span><span class="v"><?= number_format($reportCount) ?></span></div>
              <div class="ps-kv-row"><span class="k">Pending reports</span><span class="v"><?= number_format($pendingCount) ?></span></div>
              <div class="ps-kv-row"><span class="k">Views</span><span class="v"><?= number_format((int)($detail['views_count'] ?? 0)) ?></span></div>
              <div class="ps-kv-row"><span class="k">Likes</span><span class="v"><?= number_format((int)$eng['likes']) ?></span></div>
              <div class="ps-kv-row"><span class="k">Comments</span><span class="v"><?= number_format((int)$eng['comments']) ?></span></div>
              <div class="ps-kv-row"><span class="k">Shares</span><span class="v"><?= number_format((int)$eng['shares']) ?></span></div>
              <div class="ps-kv-row"><span class="k">Saves</span><span class="v"><?= number_format((int)$eng['saves']) ?></span></div>
              <div class="ps-kv-row"><span class="k">Created</span><span class="v"><?= posts_admin_h(posts_admin_fmt((string)($detail['created_at'] ?? ''))) ?></span></div>
              <div class="ps-kv-row"><span class="k">Updated</span><span class="v"><?= posts_admin_h(posts_admin_fmt((string)($detail['updated_at'] ?? ''))) ?></span></div>
            </div>
          </div>
        </aside>
      </div>

    </div>
  </div>
</div>
<script>
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = btn.getAttribute('data-copy') || '';
      if (!t || !navigator.clipboard) return;
      navigator.clipboard.writeText(t).then(function () {
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = 'Copy'; }, 1200);
      }).catch(function () {});
    });
  });
})();
</script>
<?php org_admin_render_foot(); ?>
