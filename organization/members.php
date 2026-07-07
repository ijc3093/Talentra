<?php
// /Business_only3/organization/members.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

$orgId      = (int)orgActiveOrgId();
$meMemberId = (int)orgMemberId();

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * ✅ Online/Offline accuracy:
 * bump last_seen for current org account (manager/staff) whenever members page loads.
 */
try {
    $t  = (string)orgAccountType();
    $id = (int)orgAccountId();

    if ($id > 0) {
        if ($t === 'manager') {
            $st = $dbh->prepare("UPDATE managers SET last_seen = NOW() WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
        } elseif ($t === 'staff') {
            $st = $dbh->prepare("UPDATE staff_accounts SET last_seen = NOW() WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
        }
    }
} catch (Throwable $e) {
    // ignore
}

function fmtPresence(?string $lastSeen): array {
    if (!$lastSeen) return ['Offline', 'badge badge-secondary'];
    $ts = strtotime($lastSeen);
    if ($ts <= 0) return ['Offline', 'badge badge-secondary'];
    $diff = time() - $ts;

    // online if last_seen within 5 minutes
    if ($diff <= 300) return ['Online', 'badge badge-success'];

    if ($diff < 3600) {
        $m = (int)floor($diff / 60);
        return ["$m min ago", 'badge badge-secondary'];
    }
    if ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        return ["$h hr ago", 'badge badge-secondary'];
    }
    $d = (int)floor($diff / 86400);
    return ["$d d ago", 'badge badge-secondary'];
}

function isOnline(?string $lastSeen): bool {
    if (!$lastSeen) return false;
    $ts = strtotime($lastSeen);
    if ($ts <= 0) return false;
    return (time() - $ts) <= 300;
}

/* =========================
   DATA
   ========================= */

// ---- Load Managers in org ----
$stManagers = $dbh->prepare("
    SELECT
        om.id AS member_row_id,
        om.member_type,
        om.member_id,
        om.relationship_label,
        om.role_id,
        om.status,
        COALESCE(m.fullname, '') AS fullname,
        COALESCE(m.username, '') AS username,
        m.email AS email,
        m.last_seen AS last_seen
    FROM org_members om
    LEFT JOIN managers m
      ON om.member_type = 'manager' AND m.id = om.member_id
    WHERE om.org_id = :org
      AND om.status = 1
      AND om.member_type = 'manager'
    ORDER BY om.id DESC
");
$stManagers->execute([':org' => $orgId]);
$managers = $stManagers->fetchAll(PDO::FETCH_ASSOC);

// Online-first ordering
usort($managers, function(array $a, array $b): int {
    $ao = isOnline($a['last_seen'] ?? null) ? 1 : 0;
    $bo = isOnline($b['last_seen'] ?? null) ? 1 : 0;
    if ($ao !== $bo) return $bo <=> $ao;
    $an = strtolower(trim((string)($a['fullname'] ?? $a['username'] ?? '')));
    $bn = strtolower(trim((string)($b['fullname'] ?? $b['username'] ?? '')));
    return $an <=> $bn;
});

// ---- Load Staff/Family in org ----
$stStaff = $dbh->prepare("
    SELECT
        om.id AS member_row_id,
        om.member_type,
        om.member_id,
        om.relationship_label,
        om.role_id,
        om.status,
        COALESCE(s.fullname, '') AS fullname,
        COALESCE(s.username, '') AS username,
        s.email AS email,
        s.last_seen AS last_seen
    FROM org_members om
    LEFT JOIN staff_accounts s
      ON om.member_type = 'staff' AND s.id = om.member_id
    WHERE om.org_id = :org
      AND om.status = 1
      AND om.member_type = 'staff'
    ORDER BY om.id DESC
");
$stStaff->execute([':org' => $orgId]);
$staff = $stStaff->fetchAll(PDO::FETCH_ASSOC);

// Online-first ordering
usort($staff, function(array $a, array $b): int {
    $ao = isOnline($a['last_seen'] ?? null) ? 1 : 0;
    $bo = isOnline($b['last_seen'] ?? null) ? 1 : 0;
    if ($ao !== $bo) return $bo <=> $ao;
    $an = strtolower(trim((string)($a['fullname'] ?? $a['username'] ?? '')));
    $bn = strtolower(trim((string)($b['fullname'] ?? $b['username'] ?? '')));
    return $an <=> $bn;
});

// Counts for "Online Now"
$onlineManagers = 0;
foreach ($managers as $m) { if (isOnline($m['last_seen'] ?? null)) $onlineManagers++; }
$onlineStaff = 0;
foreach ($staff as $s) { if (isOnline($s['last_seen'] ?? null)) $onlineStaff++; }
$onlineTotal = $onlineManagers + $onlineStaff;

// ---- Pending Invites (manager-only view) ----
$invites = [];
if (isOrgManager()) {
    $stInv = $dbh->prepare("
        SELECT
          id,
          invite_code,
          role_id,
          relationship_label,
          invite_email,
          invite_phone,
          temp_username,
          status,
          expires_at
        FROM org_invites
        WHERE org_id = :org
          AND status = 1
        ORDER BY id DESC
    ");
    $stInv->execute([':org' => $orgId]);
    $invites = $stInv->fetchAll(PDO::FETCH_ASSOC);
}

$tab = (string)($_GET['tab'] ?? 'managers');
if (!in_array($tab, ['managers','staff','invites'], true)) $tab = 'managers';
if (!isOrgManager() && $tab === 'invites') $tab = 'managers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <title><?= h((string)($ORG['name'] ?? 'Organization')) ?> - Members</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    .member-row { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .member-left { display:flex; align-items:center; gap:12px; min-width: 0; }
    .member-avatar {
      width: 40px;
      height: 40px;
      min-width: 40px;
      min-height: 40px;
      max-width: 40px;
      max-height: 40px;
      border-radius: 12px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: rgba(79, 70, 229, 0.12);
      border: 1px solid rgba(79, 70, 229, 0.18);
      color: #4f46e5;
      flex: 0 0 auto;
      aspect-ratio: auto;
      overflow: hidden;
    }
    .member-avatar .icon { font-size: 18px; line-height: 1; }
    .member-meta { min-width:0; }
    .member-name { font-weight:600; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .member-sub { margin:0; opacity:.75; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .badge { font-weight:600; }

    /* ===============================
       FIXED PAGE (like userlist.php)
       ✅ sh-pagetitle + card-header + nav-tabs DO NOT SCROLL
       ✅ ONLY rows area scrolls
       =============================== */
    html,body{ height:100%; overflow:hidden; }

    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      min-height:0;
    }

    .members-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .members-card .card-header{ flex:0 0 auto; }
    .members-card .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    .nav.nav-tabs{ flex:0 0 auto; }

    .tab-content{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      padding-top:0;
    }

    .tab-pane{
      height:100%;
      min-height:0;
    }

    .tab-content > .tab-pane.active{
      display:flex !important;
      flex-direction:column;
      min-height:0;
    }

    /* ✅ ONLY SCROLL AREA */
    .rows-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding: 15px;
    }

    /* ✅ Sticky invites header (only inside scroll area) */
    .rows-scroll table thead th{
      position:sticky;
      top:0;
      z-index:20;
      background:#fff;
      box-shadow:0 2px 0 rgba(0,0,0,.06);
    }
    .rows-scroll table thead.thead-light th{ background:#f8f9fa; }

    /* Search input box spacing */
    #memberSearch{ height:42px; border-radius:10px; }

    /* Hide any stray feed video/audio ghosts on this page */
    video, audio, .feed-post-media, .feed-post, .feed-layout, .feed-sidebar, .feed-main, .feed-card, .media-strip, #mainMedia {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
      pointer-events: none !important;
      width: 0 !important;
      height: 0 !important;
      position: fixed !important;
      left: -10000px !important;
      top: -10000px !important;
      z-index: -1 !important;
    }
  </style>
</head>

<body class="org-app">

<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  

  <div class="sh-pagebody">

    <div class="card bd-0 members-card">
      <div class="card-header bg-transparent pd-y-15 d-flex align-items-center justify-content-between">
        <div>
          <div class="mg-t-5" style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="badge badge-primary">Online Now: <?= (int)$onlineTotal ?></span>
            <span class="badge badge-info">Managers: <?= (int)$onlineManagers ?></span>
            <span class="badge badge-secondary">Staff/Family: <?= (int)$onlineStaff ?></span>
          </div>
        </div>

        <?php if (isOrgManager()): ?>
          <div class="d-flex" style="gap:10px;">
            <a class="btn btn-sm btn-outline-primary" href="create_staff.php"<?php echo org_layout_nav_attrs('create_staff.php'); ?>>
              <i class="icon ion-person-add"></i> Create Staff
            </a>
            <a class="btn btn-sm btn-outline-primary" href="create_org.php">
              <i class="icon ion-ios-plus-outline"></i> New Org
            </a>
          </div>
        <?php endif; ?>
      </div>

      <div class="card-body-fixed">
        <ul class="nav nav-tabs">
          <li class="nav-item">
            <a class="nav-link <?= $tab==='managers'?'active':'' ?>" href="members.php?tab=managers">Managers</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= $tab==='staff'?'active':'' ?>" href="members.php?tab=staff">Staff / Family</a>
          </li>
          <?php if (isOrgManager()): ?>
            <li class="nav-item">
              <a class="nav-link <?= $tab==='invites'?'active':'' ?>" href="members.php?tab=invites">Pending Invites</a>
            </li>
          <?php endif; ?>
        </ul>

        <div class="tab-content">

          <!-- MANAGERS -->
          <div class="tab-pane fade <?= $tab==='managers'?'show active':'' ?>">
            <div class="rows-scroll">
              <?php if (!$managers): ?>
                <div class="alert alert-info">No managers found in this organization.</div>
              <?php else: ?>
                <div class="list-group">
                  <?php foreach ($managers as $m): ?>
                    <?php
                      $name = trim((string)$m['fullname']);
                      if ($name === '') $name = trim((string)$m['username']);
                      if ($name === '') $name = 'Manager';

                      $rel = trim((string)($m['relationship_label'] ?? ''));
                      $sub = $rel !== '' ? $rel : 'Manager';

                      [$presenceLabel, $presenceCls] = fmtPresence($m['last_seen'] ?? null);
                    ?>
                    <div class="list-group-item">
                      <div class="member-row">
                        <div class="member-left">
                          <div class="member-avatar"><i class="icon ion-ios-briefcase-outline"></i></div>
                          <div class="member-meta">
                            <p class="member-name"><?= h($name) ?></p>
                            <p class="member-sub"><?= h($sub) ?><?= !empty($m['email']) ? ' • '.h((string)$m['email']) : '' ?></p>
                          </div>
                        </div>
                        <span class="<?= h($presenceCls) ?>"><?= h($presenceLabel) ?></span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- STAFF / FAMILY -->
          <div class="tab-pane fade <?= $tab==='staff'?'show active':'' ?>">
            <div class="rows-scroll">
              <?php if (!$staff): ?>
                <div class="alert alert-info">No staff/family members yet.</div>
              <?php else: ?>
                <div class="list-group">
                  <?php foreach ($staff as $s): ?>
                    <?php
                      $name = trim((string)$s['fullname']);
                      if ($name === '') $name = trim((string)$s['username']);
                      if ($name === '') $name = 'Member';

                      $rel = trim((string)($s['relationship_label'] ?? ''));
                      $sub = $rel !== '' ? $rel : 'Staff';

                      [$presenceLabel, $presenceCls] = fmtPresence($s['last_seen'] ?? null);
                    ?>
                    <div class="list-group-item">
                      <div class="member-row">
                        <div class="member-left">
                          <div class="member-avatar"><i class="icon ion-ios-people-outline"></i></div>
                          <div class="member-meta">
                            <p class="member-name"><?= h($name) ?></p>
                            <p class="member-sub"><?= h($sub) ?><?= !empty($s['email']) ? ' • '.h((string)$s['email']) : '' ?></p>
                          </div>
                        </div>
                        <span class="<?= h($presenceCls) ?>"><?= h($presenceLabel) ?></span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- INVITES -->
          <?php if (isOrgManager()): ?>
          <div class="tab-pane fade <?= $tab==='invites'?'show active':'' ?>">
            <div class="rows-scroll">
              <?php if (!$invites): ?>
                <div class="alert alert-info">No pending invites.</div>
              <?php else: ?>
                <div class="table-responsive" style="margin:0;">
                  <table class="table table-bordered table-hover" style="margin:0;">
                    <thead class="thead-light">
                      <tr>
                        <th>Invite Code</th>
                        <th>Relationship</th>
                        <th>Email/Phone</th>
                        <th>Temp Username</th>
                        <th>Expires</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($invites as $iv): ?>
                        <tr>
                          <td><code><?= h((string)$iv['invite_code']) ?></code></td>
                          <td><?= h((string)($iv['relationship_label'] ?? '')) ?></td>
                          <td>
                            <?= h((string)($iv['invite_email'] ?? '')) ?>
                            <?php if (!empty($iv['invite_phone'])): ?>
                              <div style="opacity:.7;font-size:12px;"><?= h((string)$iv['invite_phone']) ?></div>
                            <?php endif; ?>
                          </td>
                          <td><?= h((string)($iv['temp_username'] ?? '')) ?></td>
                          <td><?= h((string)($iv['expires_at'] ?? 'No expiry')) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>
    </div>

  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

<script>
  (function(){
    function purgeFeedGhosts() {
      var page = window.location.pathname.split('/').pop() || '';
      if (page === 'feed.php' || document.body.classList.contains('org-page-feed')) return;
      document.documentElement.setAttribute('data-org-feed', '0');
      document.querySelectorAll(
        'video,audio,.feed-post-media,.feed-post,.feed-layout,.feed-sidebar,.feed-main,.feed-card,.media-strip,#mainMedia'
      ).forEach(function (el) {
        if (el.closest('.sh-headpanel')) return;
        if (el.closest('.msg-attach')) return;
        try { if (el.pause) el.pause(); } catch (e) {}
        if (el.parentNode) el.parentNode.removeChild(el);
      });
    }
    purgeFeedGhosts();
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', purgeFeedGhosts);
    }
  })();
</script>

<script>
  (function(){
    var inp = document.getElementById('memberSearch');
    if (!inp) return;

    function norm(s){ return (s || '').toLowerCase().trim(); }

    function filter(){
      var q = norm(inp.value);

      // list items (managers/staff)
      var items = document.querySelectorAll('.list-group .list-group-item');
      items.forEach(function(li){
        var txt = norm(li.innerText);
        li.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
      });

      // invite rows
      var rows = document.querySelectorAll('table tbody tr');
      rows.forEach(function(tr){
        var txt = norm(tr.innerText);
        tr.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
      });
    }

    inp.addEventListener('input', filter);
  })();
</script>

</body>
</html>
