<?php
declare(strict_types=1);

$ndAlerts = org_sales_notifications($dbh, $orgId);
$ndEvents = [];
try {
    $ndEvents = org_sales_commerce_event_feed($dbh, $orgId, 20);
} catch (Throwable $e) {
    $ndEvents = [];
}

$ndLaneOf = static function (string $title, string $copy, string $href = ''): string {
    $blob = strtolower($title . ' ' . $copy . ' ' . $href);
    if (
        str_contains($blob, 'order')
        || str_contains($blob, 'payment')
        || str_contains($blob, 'ship')
        || str_contains($blob, 'deliver')
        || str_contains($blob, 'cancel')
        || str_contains($blob, 'refund')
        || str_contains($blob, 'pending')
        || str_contains($blob, 'paid')
    ) {
        return 'orders';
    }
    if (
        str_contains($blob, 'product')
        || str_contains($blob, 'inventory')
        || str_contains($blob, 'stock')
        || str_contains($blob, 'sold out')
        || str_contains($blob, 'draft')
    ) {
        return 'products';
    }
    if (
        str_contains($blob, 'customer')
        || str_contains($blob, 'buyer')
        || str_contains($blob, 'crm')
        || str_contains($blob, 'quote')
    ) {
        return 'customers';
    }
    return 'system';
};

$ndItems = [];
foreach ($ndAlerts as $a) {
    $title = (string)($a['type'] ?? 'Store alert');
    $copy = (string)($a['message'] ?? '');
    $href = (string)($a['action'] ?? '#');
    $ndItems[] = [
        'title' => $title,
        'copy' => $copy,
        'icon' => 'fa-exclamation-triangle',
        'tone' => 'orange',
        'time' => 'Now',
        'href' => $href,
        'lane' => $ndLaneOf($title, $copy, $href),
        'unread' => true,
    ];
}
foreach ($ndEvents as $e) {
    $type = (string)($e['type'] ?? 'Store activity');
    $title = ucwords($type);
    $copy = (string)($e['message'] ?? $e['description'] ?? 'Store activity updated.');
    $href = '#';
    $ndItems[] = [
        'title' => $title,
        'copy' => $copy,
        'icon' => stripos($type, 'order') !== false ? 'fa-shopping-cart' : 'fa-bell-o',
        'tone' => 'blue',
        'time' => (string)($e['created_at'] ?? 'Recent'),
        'href' => $href,
        'lane' => $ndLaneOf($title, $copy, $href),
        'unread' => true,
    ];
}
if (!$ndItems) {
    $ndItems = [[
        'title' => 'Welcome to notifications',
        'copy' => 'Important store alerts and activities will appear here.',
        'icon' => 'fa-bell-o',
        'tone' => 'blue',
        'time' => 'Now',
        'href' => '#',
        'lane' => 'system',
        'unread' => false,
    ]];
}

$ndCount = count($ndItems);
$ndUnread = count(array_filter($ndItems, static fn(array $n): bool => !empty($n['unread'])));
$ndLaneCounts = [
    'all' => $ndCount,
    'unread' => $ndUnread,
    'orders' => 0,
    'products' => 0,
    'customers' => 0,
    'system' => 0,
];
foreach ($ndItems as $n) {
    $lane = (string)($n['lane'] ?? 'system');
    if (isset($ndLaneCounts[$lane])) {
        $ndLaneCounts[$lane]++;
    }
}

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<style>
.nd{--t:#10204a;--m:#687593;--b:#dce5f1;--accent:#143dff;--accent-soft:#eef3ff;color:var(--t);height:calc(100vh - var(--org-header-h,48px) - 34px);display:flex;flex-direction:column;gap:8px;overflow:hidden}
.nd *{box-sizing:border-box}
.nd-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.nd h1{font-size:21px;margin:0;color:var(--t)}
.nd-crumb,.nd-sub{font-size:12px;font-weight:700;margin:0 0 2px}
.nd-sub{color:var(--m)}
.nd-crumb{color:var(--m)}
.nd-read{height:34px;border:1px solid var(--b);border-radius:8px;background:var(--ch-surface,#fff);color:var(--accent);padding:0 14px;font-size:12px;font-weight:800;cursor:pointer}
.nd-read:hover{background:var(--accent-soft)}
.nd-grid{display:grid;grid-template-columns:1.75fr .88fr;gap:8px;flex:1;min-height:0}
.nd-main{display:flex;flex-direction:column;min-height:0;gap:0}
.nd-card{border:1px solid var(--b);border-radius:8px;background:var(--ch-surface,#fff);overflow:hidden;color:var(--t);display:flex;flex-direction:column;min-height:0;flex:1}
.nd-tabs{
  height:44px;display:flex;align-items:stretch;gap:0;padding:0;margin:0 0 10px;
  overflow-x:auto;width:100%;background:transparent;border:0;border-radius:0;box-shadow:none;
}
.nd-tabs button{
  appearance:none;border:0;outline:0;background:transparent;cursor:pointer;box-shadow:none;
  flex:1 1 0;min-width:0;height:44px;display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:0 8px 10px;font:inherit;font-size:12px;font-weight:800;color:var(--m);
  border-bottom:2px solid rgba(148,163,184,.35);border-radius:0;white-space:nowrap;
}
.nd-tabs button:hover,
.nd-tabs button:focus,
.nd-tabs button:focus-visible{
  color:var(--t);
  border-bottom-color:rgba(148,163,184,.7);
  outline:0;box-shadow:none;
}
.nd-tabs button.is-on{
  color:var(--accent);
  border-bottom-color:var(--accent);
}
.nd-tabs b{background:var(--accent-soft);border-radius:999px;padding:2px 7px;color:var(--accent);font-size:11px;font-weight:800}
.nd-list{padding:4px 14px;overflow:auto;flex:1;min-height:0}
.nd-item{display:grid;grid-template-columns:38px 1fr auto;gap:10px;align-items:center;min-height:64px;border-bottom:1px solid var(--b);text-decoration:none;color:var(--t);font-size:12px}
.nd-item.is-read{opacity:.72}
.nd-item[hidden]{display:none !important}
.nd-icon{width:34px;height:34px;border-radius:7px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:15px}
.nd-icon.orange{background:#fff1e5;color:#f97316}
.nd-item strong{display:block;font-size:13px;margin-bottom:3px;color:var(--t)}
.nd-item p{margin:0;color:var(--m);line-height:1.4}
.nd-time{font-size:11px;color:var(--m);font-weight:700}
.nd-empty{padding:28px 12px;text-align:center;color:var(--m);font-size:13px;font-weight:700}
.nd-empty[hidden]{display:none !important}
.nd-pages{height:40px;display:flex;justify-content:center;align-items:center;gap:8px;font-size:12px;color:var(--m);border-top:1px solid var(--b)}
.nd-pages button{
  appearance:none;border:1px solid var(--b);background:var(--ch-surface,#fff);color:var(--t);
  min-width:30px;height:28px;border-radius:7px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;padding:0 8px;
}
.nd-pages button.is-on{background:var(--accent);border-color:var(--accent);color:#fff}
.nd-pages button:disabled{opacity:.45;cursor:not-allowed}
.nd-side{display:grid;grid-template-rows:1.2fr .72fr .9fr;gap:8px;min-height:0}
.nd-box{padding:11px;overflow:auto}
.nd-box h2{font-size:14px;margin:0 0 4px;color:var(--t)}
.nd-box>p{font-size:12px;color:var(--m);margin:0 0 8px}
.nd-pref{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--b);font-size:12px;color:var(--t)}
.nd-pref i{width:27px;height:27px;border-radius:6px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:12px}
.nd-pref span{flex:1;color:var(--t)}
.nd-pref strong{color:var(--t)}
.nd-pref small{display:block;color:var(--m)}
.nd-on,.nd-off{font-size:11px;border-radius:999px;padding:3px 7px;background:#dcfce7;color:#15803d;font-weight:800}
.nd-off{background:#eef2f7;color:#64748b}
.nd-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.nd-sum{border:1px solid var(--b);border-radius:6px;text-align:center;padding:7px 3px;font-size:11px;color:var(--m)}
.nd-sum i{font-size:13px;color:var(--accent)}
.nd-sum strong{display:block;font-size:14px;margin:4px 0;color:var(--t)}
.nd-activity{display:flex;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid var(--b);font-size:12px;color:var(--t)}
.nd-activity small{color:var(--m);white-space:nowrap}
html.dark-auto .nd,
html[data-theme="dark"] .nd,
html[data-msb-appearance] .nd,
html.msb-palette-active .nd{
  --t:var(--msb-palette-text,#f3f6fb);
  --m:var(--msb-palette-text-muted,#cbd5e1);
  --b:var(--msb-palette-border,rgba(148,163,184,.28));
  --accent:var(--msb-palette-action,#93c5fd);
  --accent-soft:var(--msb-palette-action-soft,rgba(147,197,253,.16));
  color:var(--t)!important;
}
html.dark-auto .nd .nd-card,
html.dark-auto .nd .nd-read,
html.dark-auto .nd .nd-pages button,
html[data-msb-appearance] .nd .nd-card,
html[data-msb-appearance] .nd .nd-read,
html.msb-palette-active .nd .nd-card{
  background:var(--ch-surface,var(--msb-palette-surface,var(--msb-palette-bg,#171d24)))!important;
  border-color:var(--b)!important;
  color:var(--t)!important;
}
html.dark-auto .nd h1,
html.dark-auto .nd .nd-item,
html.dark-auto .nd .nd-item strong,
html.dark-auto .nd .nd-box h2,
html.dark-auto .nd .nd-pref,
html.dark-auto .nd .nd-pref strong,
html.dark-auto .nd .nd-sum strong,
html.dark-auto .nd .nd-activity,
html.dark-auto .nd .nd-pages button,
html[data-msb-appearance] .nd h1,
html[data-msb-appearance] .nd .nd-item strong,
html.msb-palette-active .nd h1{
  color:var(--t)!important;
}
html.dark-auto .nd .nd-crumb,
html.dark-auto .nd .nd-sub,
html.dark-auto .nd .nd-tabs button,
html.dark-auto .nd .nd-item p,
html.dark-auto .nd .nd-time,
html.dark-auto .nd .nd-box>p,
html.dark-auto .nd .nd-pref small,
html.dark-auto .nd .nd-sum,
html.dark-auto .nd .nd-activity small,
html.dark-auto .nd .nd-empty,
html.dark-auto .nd .nd-pages{
  color:var(--m)!important;
}
html.dark-auto .nd .nd-tabs,
html[data-msb-appearance] .nd .nd-tabs,
html.msb-palette-active .nd .nd-tabs{
  background:transparent!important;
  border:0!important;
  box-shadow:none!important;
}
html.dark-auto .nd .nd-tabs button,
html[data-msb-appearance] .nd .nd-tabs button,
html.msb-palette-active .nd .nd-tabs button{
  color:var(--m)!important;
  background:transparent!important;
  border:0!important;
  border-bottom:2px solid rgba(148,163,184,.4)!important;
  border-radius:0!important;
  box-shadow:none!important;
  outline:0!important;
}
html.dark-auto .nd .nd-tabs button:hover,
html.dark-auto .nd .nd-tabs button:focus,
html.dark-auto .nd .nd-tabs button:focus-visible,
html[data-msb-appearance] .nd .nd-tabs button:hover{
  color:var(--t)!important;
  border-bottom-color:rgba(148,163,184,.75)!important;
}
html.dark-auto .nd .nd-tabs button.is-on,
html.dark-auto .nd .nd-read,
html.dark-auto .nd .nd-sum i,
html.dark-auto .nd .nd-icon:not(.orange),
html.dark-auto .nd .nd-pref i{
  color:var(--accent)!important;
}
html.dark-auto .nd .nd-tabs button.is-on{
  border-bottom-color:var(--accent)!important;
}
html.dark-auto .nd .nd-tabs b{background:var(--accent-soft)!important;color:var(--accent)!important}
html.dark-auto .nd .nd-icon:not(.orange),
html.dark-auto .nd .nd-pref i{background:var(--accent-soft)!important}
html.dark-auto .nd .nd-off{background:rgba(148,163,184,.18)!important;color:var(--m)!important}
html.dark-auto .nd .nd-pages button.is-on{background:var(--accent)!important;border-color:var(--accent)!important;color:#0f172a!important}
@media(max-width:900px){.nd{height:auto;overflow:visible}.nd-grid{grid-template-columns:1fr}.nd-side{grid-template-rows:auto}}
</style>

<main class="nd" id="ndRoot">
  <header class="nd-head">
    <div>
      <p class="nd-crumb">Home › Notifications</p>
      <h1>Notifications</h1>
      <p class="nd-sub">Stay updated with important alerts and activities in your store.</p>
    </div>
    <button type="button" class="nd-read" id="ndRead">Mark all as read</button>
  </header>

  <div class="nd-grid">
    <div class="nd-main">
      <div class="nd-tabs" id="ndTabs" role="tablist" aria-label="Notification filters">
        <?php
        $ndTabDefs = [
            'all' => 'All',
            'unread' => 'Unread',
            'orders' => 'Orders',
            'products' => 'Products',
            'customers' => 'Customers',
            'system' => 'System',
        ];
        foreach ($ndTabDefs as $key => $label):
            $count = (int)($ndLaneCounts[$key] ?? 0);
        ?>
          <button
            type="button"
            role="tab"
            class="<?= $key === 'all' ? 'is-on' : '' ?>"
            data-nd-tab="<?= $h($key) ?>"
            aria-selected="<?= $key === 'all' ? 'true' : 'false' ?>"
          >
            <?= $h($label) ?>
            <b data-nd-count="<?= $h($key) ?>"><?= $count ?></b>
          </button>
        <?php endforeach; ?>
      </div>

      <section class="nd-card">
        <div class="nd-list" id="ndList">
          <?php foreach ($ndItems as $idx => $n): ?>
            <a
              class="nd-item<?= empty($n['unread']) ? ' is-read' : '' ?>"
              href="<?= $h((string)$n['href']) ?>"
              data-nd-lane="<?= $h((string)$n['lane']) ?>"
              data-nd-unread="<?= !empty($n['unread']) ? '1' : '0' ?>"
              data-nd-index="<?= (int)$idx ?>"
              <?php
                $frag = '';
                if (preg_match('/#([A-Za-z0-9_-]+)/', (string)$n['href'], $m)) {
                    $frag = $m[1];
                }
                if ($frag !== ''):
              ?>
                data-sales-nav="<?= $h($frag) ?>"
              <?php endif; ?>
            >
              <i class="fa <?= $h((string)$n['icon']) ?> nd-icon <?= $h((string)$n['tone']) ?>" aria-hidden="true"></i>
              <div>
                <strong><?= $h((string)$n['title']) ?></strong>
                <p><?= $h((string)$n['copy']) ?></p>
              </div>
              <span class="nd-time"><?= $h((string)$n['time']) ?></span>
            </a>
          <?php endforeach; ?>
          <div class="nd-empty" id="ndEmpty" hidden>No notifications in this tab.</div>
        </div>

        <div class="nd-pages" id="ndPages" aria-label="Notification pages"></div>
      </section>
    </div>

    <aside class="nd-side">
      <section class="nd-card nd-box">
        <h2>Notification Preferences</h2>
        <p>Manage how you receive notifications.</p>
        <?php foreach ([
            ['fa-envelope-o', 'Email Notifications', 'Receive emails for important updates', 1],
            ['fa-bell-o', 'In-App Notifications', 'Show notifications in the system', 1],
            ['fa-commenting-o', 'SMS Notifications', 'Receive SMS for critical alerts', 0],
            ['fa-mobile', 'Push Notifications', 'Receive push notifications on mobile', 1],
        ] as $p): ?>
          <div class="nd-pref">
            <i class="fa <?= $h($p[0]) ?>"></i>
            <span><strong><?= $h($p[1]) ?></strong><small><?= $h($p[2]) ?></small></span>
            <b class="<?= $p[3] ? 'nd-on' : 'nd-off' ?>"><?= $p[3] ? 'On' : 'Off' ?></b>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="nd-card nd-box">
        <h2>Notification Summary</h2>
        <p>Overview of your notifications</p>
        <div class="nd-summary">
          <?php foreach ([
              ['fa-envelope-o', 12, 'Email'],
              ['fa-bell-o', $ndCount, 'In-App'],
              ['fa-commenting-o', 2, 'SMS'],
              ['fa-mobile', 6, 'Push'],
          ] as $s): ?>
            <div class="nd-sum">
              <i class="fa <?= $h($s[0]) ?>"></i>
              <strong><?= (int)$s[1] ?></strong>
              <?= $h((string)$s[2]) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="nd-card nd-box">
        <h2>Recent Activity</h2>
        <?php foreach (array_slice($ndItems, 0, 4) as $n): ?>
          <div class="nd-activity">
            <span><?= $h((string)$n['title']) ?></span>
            <small><?= $h((string)$n['time']) ?></small>
          </div>
        <?php endforeach; ?>
      </section>
    </aside>
  </div>
</main>

<script>
(function () {
  var root = document.getElementById('ndRoot');
  if (!root || root.getAttribute('data-nd-ready') === '1') return;
  root.setAttribute('data-nd-ready', '1');

  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-nd-tab]'));
  var items = Array.prototype.slice.call(root.querySelectorAll('.nd-item'));
  var emptyEl = document.getElementById('ndEmpty');
  var pagesEl = document.getElementById('ndPages');
  var unreadBadge = root.querySelector('[data-nd-count="unread"]');
  var readBtn = document.getElementById('ndRead');
  var activeTab = 'all';
  var page = 1;
  var pageSize = 8;

  function matching() {
    return items.filter(function (el) {
      var lane = String(el.getAttribute('data-nd-lane') || 'system');
      var unread = el.getAttribute('data-nd-unread') === '1';
      if (activeTab === 'all') return true;
      if (activeTab === 'unread') return unread;
      return lane === activeTab;
    });
  }

  function render() {
    var vis = matching();
    var total = vis.length;
    var pages = Math.max(1, Math.ceil(total / pageSize) || 1);
    if (page > pages) page = pages;
    var start = (page - 1) * pageSize;
    var end = start + pageSize;

    items.forEach(function (el) { el.hidden = true; });
    vis.forEach(function (el, i) {
      el.hidden = !(i >= start && i < end);
    });

    if (emptyEl) emptyEl.hidden = total > 0;

    if (pagesEl) {
      pagesEl.innerHTML = '';
      var prev = document.createElement('button');
      prev.type = 'button';
      prev.textContent = '‹';
      prev.disabled = page <= 1;
      prev.addEventListener('click', function () {
        if (page > 1) { page -= 1; render(); }
      });
      pagesEl.appendChild(prev);

      for (var i = 1; i <= pages; i++) {
        (function (n) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.textContent = String(n);
          if (n === page) btn.className = 'is-on';
          btn.addEventListener('click', function () {
            page = n;
            render();
          });
          pagesEl.appendChild(btn);
        })(i);
      }

      var next = document.createElement('button');
      next.type = 'button';
      next.textContent = '›';
      next.disabled = page >= pages;
      next.addEventListener('click', function () {
        if (page < pages) { page += 1; render(); }
      });
      pagesEl.appendChild(next);
    }
  }

  function setTab(tab) {
    activeTab = String(tab || 'all');
    page = 1;
    tabs.forEach(function (btn) {
      var on = btn.getAttribute('data-nd-tab') === activeTab;
      btn.classList.toggle('is-on', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    render();
  }

  tabs.forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setTab(btn.getAttribute('data-nd-tab') || 'all');
    });
  });

  if (readBtn) {
    readBtn.addEventListener('click', function () {
      items.forEach(function (el) {
        el.setAttribute('data-nd-unread', '0');
        el.classList.add('is-read');
      });
      if (unreadBadge) unreadBadge.textContent = '0';
      readBtn.textContent = 'All read';
      if (activeTab === 'unread') render();
    });
  }

  setTab('all');
})();
</script>
