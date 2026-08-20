<?php
declare(strict_types=1);

/**
 * admin/account_search.php
 * Cross-account search — viewport-fit UI matching orglist / managerlist.
 * Preserves search GET + org_admin_search_accounts behavior.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/admin_kind_tabs.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$kindFilter = admin_kind_from_request();
$query = trim((string)($_GET['q'] ?? ''));
$lane = strtolower(trim((string)($_GET['lane'] ?? 'all')));
if (!in_array($lane, ['all', 'users', 'managers', 'organizations'], true)) {
    $lane = 'all';
}

$results = org_admin_search_accounts($dbh, $query);
$kindCounts = admin_kind_user_counts($dbh);
if ($query !== '') {
    $kindCounts = ['personal' => 0, 'publisher' => 0, 'commerce' => 0];
    foreach (($results['users'] ?? []) as $ur) {
        $kk = admin_kind_classify_user_row($ur);
        $kindCounts[$kk]++;
    }
    $results['users'] = array_values(array_filter(
        $results['users'] ?? [],
        static fn($u): bool => admin_kind_classify_user_row($u) === $kindFilter
    ));
}
$userCount = count($results['users'] ?? []);
$managerCount = count($results['managers'] ?? []);
$orgCount = count($results['organizations'] ?? []);
$totalHits = $userCount + $managerCount + $orgCount;
$hasQuery = $query !== '';

function as_initials(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '??';
    }
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
    if (!$parts) {
        return '??';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function as_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

$qParam = $hasQuery ? 'q=' . rawurlencode($query) : '';
$laneHref = static function (string $l) use ($qParam, $kindFilter): string {
    $base = 'account_search.php?lane=' . rawurlencode($l) . '&kind=' . rawurlencode($kindFilter);
    return $qParam !== '' ? $base . '&' . $qParam : $base;
};

org_admin_render_head('Account Search');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Account Search',
    'description' => 'Search public users, managers, and organizations in one place.',
]);
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
  .as-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .as-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
  .as-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .as-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .as-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .as-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .as-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .as-btn.sm{height:22px;padding:0 7px;font-size:9px;}

  .as-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;}
  .as-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
  }
  .as-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
  .as-card.is-kind:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .as-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .as-card.is-disabled{opacity:.55;pointer-events:none;}
  .as-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .as-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .as-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
  .as-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .as-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .as-ico.green{background:#f0fdf4;color:#16a34a;}
  .as-ico.blue{background:#dbeafe;color:#2563eb;}
  .as-ico.orange{background:#fff7ed;color:#ea580c;}
  .as-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .as-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

  .as-kinds{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .as-kinds a{
    flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .as-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .as-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .as-kinds a:hover{color:#0f172a;text-decoration:none;}

  .as-panel{
    flex:1 1 auto;min-height:0;min-width:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden !important;
  }
  .as-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .as-search{position:relative;flex:1 1 220px;min-width:160px;max-width:420px;}
  .as-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .as-search input,.as-filters select{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
  }
  .as-search input{width:100%;padding-left:28px;}
  .as-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .as-clear:hover{text-decoration:underline;}

  .as-body{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;padding:0;}
  .as-section{display:none;min-height:0;}
  .as-section.is-on{display:flex;flex-direction:column;min-height:0;}
  .as-sec-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-bottom:1px solid #f1f5f9;background:#fff;
    position:sticky;top:0;z-index:2;
  }
  .as-sec-hd h2{margin:0;font-size:12px;font-weight:800;color:#0f172a;}
  .as-sec-hd .muted{font-size:10px;font-weight:700;color:#94a3b8;}

  .as-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .as-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;white-space:nowrap;
  }
  .as-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .as-table tr:hover td{background:#f8fafc;}
  .as-table th:nth-child(1),.as-table td:nth-child(1){width:32%;}
  .as-table th:nth-child(2),.as-table td:nth-child(2){width:16%;}
  .as-table th:nth-child(3),.as-table td:nth-child(3){width:12%;}
  .as-table th:nth-child(4),.as-table td:nth-child(4){width:18%;}
  .as-table th:nth-child(5),.as-table td:nth-child(5){width:14%;}

  .as-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .as-av{
    width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;
  }
  .as-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .as-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .as-when{font-size:10px;color:#64748b;}
  .as-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .as-pill.ok{background:#dcfce7;color:#15803d;}
  .as-pill.bad{background:#fee2e2;color:#b91c1c;}
  .as-pill.blue{background:#dbeafe;color:#1d4ed8;}
  .as-pill.orange{background:#ffedd5;color:#c2410c;}
  .as-pill.gray{background:#f1f5f9;color:#475569;}
  .as-empty{
    padding:36px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:700;
  }
  .as-empty .hint{font-size:11px;font-weight:600;color:#94a3b8;margin-top:6px;}
  .as-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .pill.ok{background:#dcfce7;color:#15803d;}
  .pill.bad{background:#fee2e2;color:#b91c1c;}
  .pill.info{background:#dbeafe;color:#1d4ed8;}

  @media (max-width:900px){
    .as-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
<?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="as-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          static function ($k) use ($query, $lane) {
              $params = ['kind' => $k, 'lane' => $lane];
              if ($query !== '') {
                  $params['q'] = $query;
              }
              return 'account_search.php?' . http_build_query($params);
          },
          fn($s) => org_admin_h((string)$s),
          'ak',
          'default'
      ) ?>

      <div class="as-top">
        <div class="as-actions">
          <a class="as-btn" href="userlist.php"><i class="fa fa-user"></i> Users</a>
          <a class="as-btn" href="managerlist.php"><i class="fa fa-users"></i> Managers</a>
          <a class="as-btn" href="orglist.php"><i class="fa fa-building"></i> Organizations</a>
        </div>
      </div>

      <div class="as-cards">
        <a class="as-card is-kind<?= $lane === 'all' ? ' is-active' : '' ?><?= !$hasQuery ? ' is-disabled' : '' ?>" href="<?= org_admin_h($laneHref('all')) ?>">
          <div class="as-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="as-ico purple"><i class="fa fa-search"></i></div>
              <div class="lab">Total hits</div>
            </div>
            <div class="delta">• all</div>
          </div>
          <div class="val"><?= $hasQuery ? number_format($totalHits) : '—' ?></div>
          <div class="sub"><?= $hasQuery ? 'Across all systems' : 'Enter a query' ?></div>
        </a>
        <a class="as-card is-kind<?= $lane === 'users' ? ' is-active' : '' ?><?= !$hasQuery ? ' is-disabled' : '' ?>" href="<?= org_admin_h($laneHref('users')) ?>">
          <div class="as-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="as-ico blue"><i class="fa fa-user"></i></div>
              <div class="lab">Public users</div>
            </div>
            <div class="delta">• users</div>
          </div>
          <div class="val"><?= $hasQuery ? number_format($userCount) : '—' ?></div>
          <div class="sub">Up to 50 matches</div>
        </a>
        <a class="as-card is-kind<?= $lane === 'managers' ? ' is-active' : '' ?><?= !$hasQuery ? ' is-disabled' : '' ?>" href="<?= org_admin_h($laneHref('managers')) ?>">
          <div class="as-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="as-ico orange"><i class="fa fa-briefcase"></i></div>
              <div class="lab">Managers</div>
            </div>
            <div class="delta">• org</div>
          </div>
          <div class="val"><?= $hasQuery ? number_format($managerCount) : '—' ?></div>
          <div class="sub">Owner accounts</div>
        </a>
        <a class="as-card is-kind<?= $lane === 'organizations' ? ' is-active' : '' ?><?= !$hasQuery ? ' is-disabled' : '' ?>" href="<?= org_admin_h($laneHref('organizations')) ?>">
          <div class="as-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="as-ico green"><i class="fa fa-building"></i></div>
              <div class="lab">Organizations</div>
            </div>
            <div class="delta">• orgs</div>
          </div>
          <div class="val"><?= $hasQuery ? number_format($orgCount) : '—' ?></div>
          <div class="sub">Workspaces</div>
        </a>
      </div>

      <?php if ($hasQuery): ?>
      <nav class="as-kinds" aria-label="Result type">
        <a href="<?= org_admin_h($laneHref('all')) ?>" class="<?= $lane === 'all' ? 'is-active' : '' ?>">All<span class="cnt">(<?= (int)$totalHits ?>)</span></a>
        <a href="<?= org_admin_h($laneHref('users')) ?>" class="<?= $lane === 'users' ? 'is-active' : '' ?>">Users<span class="cnt">(<?= (int)$userCount ?>)</span></a>
        <a href="<?= org_admin_h($laneHref('managers')) ?>" class="<?= $lane === 'managers' ? 'is-active' : '' ?>">Managers<span class="cnt">(<?= (int)$managerCount ?>)</span></a>
        <a href="<?= org_admin_h($laneHref('organizations')) ?>" class="<?= $lane === 'organizations' ? 'is-active' : '' ?>">Organizations<span class="cnt">(<?= (int)$orgCount ?>)</span></a>
      </nav>
      <?php endif; ?>

      <div class="as-panel">
        <form class="as-filters" method="get" action="account_search.php" id="asFilters">
          <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
          <div class="as-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= org_admin_h($query) ?>" placeholder="e.g. fox2, PUB-, MGR-, name@email.com" autocomplete="off" autofocus>
          </div>
          <select name="lane" aria-label="Lane" onchange="this.form.submit()">
            <option value="all"<?= $lane === 'all' ? ' selected' : '' ?>>All results</option>
            <option value="users"<?= $lane === 'users' ? ' selected' : '' ?>>Users</option>
            <option value="managers"<?= $lane === 'managers' ? ' selected' : '' ?>>Managers</option>
            <option value="organizations"<?= $lane === 'organizations' ? ' selected' : '' ?>>Organizations</option>
          </select>
          <button type="submit" class="as-btn sm primary">Search</button>
          <?php if ($hasQuery): ?>
            <a class="as-clear" href="account_search.php"><i class="fa fa-refresh"></i> Clear</a>
          <?php endif; ?>
        </form>

        <div class="as-body">
          <?php if (!$hasQuery): ?>
            <div class="as-empty">
              Enter a search term to find accounts across all three systems.
              <div class="hint">Try a username, email, friend code, or organization name.</div>
            </div>
          <?php else: ?>

            <?php if ($lane === 'all' || $lane === 'users'): ?>
            <section class="as-section is-on" id="asUsers">
              <div class="as-sec-hd">
                <h2>Public users</h2>
                <span class="muted"><?= (int)$userCount ?> result<?= $userCount === 1 ? '' : 's' ?></span>
              </div>
              <table class="as-table">
                <thead><tr><th>User</th><th>Kind</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php if (!$results['users']): ?>
                  <tr><td colspan="5"><div class="as-empty" style="padding:18px;">No public users matched.</div></td></tr>
                <?php else: foreach ($results['users'] as $u):
                  $uname = (string)($u['username'] ?? '');
                  $dname = (string)($u['name'] ?? '');
                  $code = (string)($u['friend_code'] ?? '');
                  $kind = strtolower(trim((string)($u['account_kind'] ?? 'personal')));
                  $ini = as_initials($dname !== '' ? $dname : ($uname !== '' ? $uname : $code));
                  $bg = as_avatar_color($code !== '' ? $code : $uname);
                ?>
                  <tr>
                    <td>
                      <div class="as-user">
                        <span class="as-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
                        <div style="min-width:0;">
                          <div class="nm"><?= org_admin_h($uname) ?><?= $dname !== '' ? ' · ' . org_admin_h($dname) : '' ?></div>
                          <div class="un"><?= org_admin_h($code) ?> · <?= org_admin_h((string)($u['email'] ?? '')) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <?php if ($kind === 'publisher'): ?>
                        <span class="as-pill blue">publisher</span>
                      <?php elseif ($kind === 'commerce'): ?>
                        <span class="as-pill orange">commerce</span>
                      <?php else: ?>
                        <span class="as-pill gray"><?= org_admin_h($kind !== '' ? $kind : 'personal') ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= org_admin_status_badge((int)($u['status'] ?? 0)) ?></td>
                    <td><div class="as-when"><?= org_admin_h(org_admin_fmt_dt($u['created_at'] ?? '')) ?></div></td>
                    <td>
                      <a class="as-btn sm" href="<?= org_admin_h(org_admin_user_activity_link((int)($u['id'] ?? 0))) ?>">Activity</a>
                      <a class="as-btn sm primary" href="user_form.php?id=<?= (int)($u['id'] ?? 0) ?>">Open</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </section>
            <?php endif; ?>

            <?php if ($lane === 'all' || $lane === 'managers'): ?>
            <section class="as-section is-on" id="asManagers">
              <div class="as-sec-hd">
                <h2>Managers</h2>
                <span class="muted"><?= (int)$managerCount ?> result<?= $managerCount === 1 ? '' : 's' ?></span>
              </div>
              <table class="as-table">
                <thead><tr><th>Manager</th><th>Publisher link</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php if (!$results['managers']): ?>
                  <tr><td colspan="5"><div class="as-empty" style="padding:18px;">No managers matched.</div></td></tr>
                <?php else: foreach ($results['managers'] as $m):
                  $muname = (string)($m['username'] ?? '');
                  $mcode = (string)($m['friend_code'] ?? '');
                  $mfull = (string)($m['fullname'] ?? '');
                  $ini = as_initials($mfull !== '' ? $mfull : ($muname !== '' ? $muname : $mcode));
                  $bg = as_avatar_color($mcode !== '' ? $mcode : $muname);
                ?>
                  <tr>
                    <td>
                      <div class="as-user">
                        <span class="as-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
                        <div style="min-width:0;">
                          <div class="nm"><?= org_admin_h($muname) ?></div>
                          <div class="un"><?= org_admin_h($mcode) ?> · <?= org_admin_h((string)($m['email'] ?? '')) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <?php if (!empty($m['publisher_user_id'])): ?>
                        <span class="as-pill blue">User #<?= (int)$m['publisher_user_id'] ?></span>
                      <?php else: ?>
                        <span class="as-pill gray">None</span>
                      <?php endif; ?>
                    </td>
                    <td><?= org_admin_status_badge((int)($m['status'] ?? 0)) ?></td>
                    <td><div class="as-when"><?= org_admin_h(org_admin_fmt_dt($m['created_at'] ?? '')) ?></div></td>
                    <td>
                      <a class="as-btn sm primary" href="managerlist.php?q=<?= rawurlencode($muname) ?>">Open</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </section>
            <?php endif; ?>

            <?php if ($lane === 'all' || $lane === 'organizations'): ?>
            <section class="as-section is-on" id="asOrgs">
              <div class="as-sec-hd">
                <h2>Organizations</h2>
                <span class="muted"><?= (int)$orgCount ?> result<?= $orgCount === 1 ? '' : 's' ?></span>
              </div>
              <table class="as-table">
                <thead><tr><th>Organization</th><th>Type</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php if (!$results['organizations']): ?>
                  <tr><td colspan="5"><div class="as-empty" style="padding:18px;">No organizations matched.</div></td></tr>
                <?php else: foreach ($results['organizations'] as $o):
                  $oname = (string)($o['name'] ?? '');
                  $ocode = (string)($o['org_code'] ?? '');
                  $isPub = (int)($o['is_publisher_org'] ?? 0) === 1;
                  $ini = as_initials($oname !== '' ? $oname : $ocode);
                  $bg = as_avatar_color($ocode !== '' ? $ocode : $oname);
                ?>
                  <tr>
                    <td>
                      <div class="as-user">
                        <span class="as-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
                        <div style="min-width:0;">
                          <div class="nm"><?= org_admin_h($oname) ?></div>
                          <div class="un"><?= org_admin_h($ocode) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= $isPub ? '<span class="as-pill blue">Publisher</span>' : '<span class="as-pill gray">Regular</span>' ?></td>
                    <td><?= org_admin_status_badge((int)($o['status'] ?? 0)) ?></td>
                    <td><div class="as-when"><?= org_admin_h(org_admin_fmt_dt($o['created_at'] ?? '')) ?></div></td>
                    <td>
                      <a class="as-btn sm primary" href="orgdetail.php?id=<?= (int)($o['id'] ?? 0) ?>">View</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </section>
            <?php endif; ?>

          <?php endif; ?>
        </div>

        <div class="as-foot">
          <?php if ($hasQuery): ?>
            <span><?= (int)$totalHits ?> hit<?= $totalHits === 1 ? '' : 's' ?> for “<?= org_admin_h($query) ?>”</span>
            <span><?= org_admin_h(ucfirst($lane === 'all' ? 'All lanes' : $lane)) ?></span>
          <?php else: ?>
            <span>Ready to search</span>
            <span>Name · username · email · code</span>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
