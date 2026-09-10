<?php
declare(strict_types=1);

if (!function_exists('msb_switch_accounts_h')) {
  function msb_switch_accounts_h(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('msb_switch_accounts_assets')) {
  function msb_switch_accounts_assets(): void
  {
    if (!empty($GLOBALS['msb_switch_accounts_assets'])) {
      return;
    }
    $GLOBALS['msb_switch_accounts_assets'] = true;
    ?>
<style id="msb-switch-accounts-css">
.sa-page{
  max-width:640px;
  margin:0 auto;
  padding:18px 18px 48px;
}
.sa-head{
  display:flex;
  align-items:flex-start;
  gap:12px;
  margin:0 0 18px;
}
.sa-back{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:42px;
  height:42px;
  flex:0 0 42px;
  margin-top:2px;
  border:0;
  background:transparent;
  color:var(--msb-palette-text,#0b1220);
  text-decoration:none;
  font-size:22px;
  font-weight:800;
  line-height:1;
  cursor:pointer;
}
.sa-back:hover{color:inherit;text-decoration:none;}
.sa-title{
  margin:0;
  font-size:28px;
  font-weight:800;
  letter-spacing:-.03em;
  color:var(--msb-palette-text,#0b1220);
}
.as-wrap{display:flex;flex-direction:column;gap:16px;}
.as-lead,.as-empty,.as-add-copy{
  margin:0;
  font-size:14px;
  line-height:1.45;
  font-weight:500;
  color:var(--msb-palette-text-muted,#667085);
}
.as-empty[hidden]{display:none !important;}
.sa-head .as-lead{margin-top:6px;}
.as-list{
  list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0;
  border-radius:14px;overflow:hidden;
  background:var(--msb-palette-surface, var(--msb-palette-hover-bg, rgba(148,163,184,.12)));
}
.as-row{
  display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;
  padding:12px 14px;border:0;border-radius:0;background:transparent;
}
.as-row + .as-row{border-top:1px solid var(--msb-palette-border, rgba(148,163,184,.18));}
.as-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;display:block;background:var(--msb-palette-hover-bg,#eef2ff);}
.as-name{font-size:16px;font-weight:700;line-height:1.2;color:var(--msb-palette-text,#0b1220);}
.as-meta{font-size:13px;font-weight:500;margin-top:3px;color:var(--msb-palette-text-muted,#667085);}
.as-using{font-size:12px;font-weight:800;color:var(--msb-palette-text-muted,#667085);}
.as-btn{
  display:inline-flex;align-items:center;justify-content:center;
  padding:0 14px;height:34px;border-radius:10px;border:0;
  color:#fff;background:#2563eb;font-weight:700;font-size:13px;
  text-decoration:none;cursor:pointer;
}
.as-btn:hover{background:#1d4ed8;color:#fff;}
.as-btn.as-btn-ghost{
  width:100%;height:42px;border-radius:12px;border:1px solid var(--msb-palette-border,#c0c2c4);
  color:var(--msb-palette-text,#0b1220);background:var(--msb-palette-surface, var(--msb-palette-hover-bg, #f3f4f6));
  font-weight:700;font-size:14px;
}
.as-btn.as-btn-ghost:hover{background:var(--msb-palette-hover-bg,#eef2ff);color:inherit;}
.as-add{padding-top:12px;}
.as-add-title{font-size:15px;font-weight:800;margin:0 0 6px;color:var(--msb-palette-text,#0b1220);}
.as-add-row{display:flex;flex-direction:column;gap:10px;margin-top:10px;}
.as-logout-dialog{
  width:min(360px, calc(100vw - 32px));
  max-width:360px;
  padding:20px 18px 16px;
  border:1px solid var(--msb-palette-border, rgba(148,163,184,.28));
  border-radius:14px;
  background:var(--msb-palette-surface, var(--msb-palette-bg, #171d24));
  color:var(--msb-palette-text, #f4f6fb);
  box-shadow:0 18px 48px rgba(0,0,0,.4);
  text-align:center;
}
.as-logout-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);}
.as-logout-dialog h2{margin:0 0 8px;font-size:16px;font-weight:800;line-height:1.3;color:inherit;}
.as-logout-dialog p{margin:0;font-size:13px;line-height:1.45;color:var(--msb-palette-text-muted, #98a2b3);}
.as-logout-actions{display:flex;gap:8px;margin-top:16px;}
.as-logout-actions .as-btn{flex:1 1 0;height:34px;}
.as-logout-confirm{border-color:#dc2626;background:#dc2626;color:#fff;}
.as-logout-confirm:hover{background:#b91c1c;border-color:#b91c1c;}
dialog.sa-modal{
  width:min(520px, calc(100vw - 24px));
  max-width:520px;
  max-height:min(90vh, 840px);
  margin:auto;
  padding:0;
  border:0;
  border-radius:18px;
  background:var(--msb-palette-bg,#f6f7fb);
  color:var(--msb-palette-text,#0b1220);
  box-shadow:0 24px 64px rgba(15,23,42,.28);
  overflow:auto;
}
dialog.sa-modal::backdrop{
  background:rgba(15,23,42,.62);
  backdrop-filter:blur(6px);
}
dialog.sa-modal .sa-page{padding:18px 18px 28px;position:relative;}
dialog.sa-modal:focus,
dialog.sa-modal:focus-visible{outline:none;}
.as-logout-overlay{
  position:absolute;inset:0;
  display:flex;align-items:center;justify-content:center;
  padding:18px;
  background:rgba(15,23,42,.45);
  z-index:3;
}
.as-logout-overlay[hidden]{display:none !important;}
.as-logout-overlay-card{
  width:min(360px, 100%);
  padding:20px 18px 16px;
  border-radius:14px;
  background:var(--msb-palette-surface, #171d24);
  color:var(--msb-palette-text, #f4f6fb);
  text-align:center;
  box-shadow:0 18px 48px rgba(0,0,0,.4);
}
.as-logout-overlay-card h2{margin:0 0 8px;font-size:16px;font-weight:800;}
.as-logout-overlay-card p{margin:0;font-size:13px;line-height:1.45;color:var(--msb-palette-text-muted,#98a2b3);}
</style>
    <?php
  }
}

if (!function_exists('msb_switch_accounts_js')) {
  function msb_switch_accounts_js(): void
  {
    if (!empty($GLOBALS['msb_switch_accounts_js'])) {
      return;
    }
    $GLOBALS['msb_switch_accounts_js'] = true;
    ?>
<script>
(function(){
  if (window.__msbSwitchAccountsBound) return;
  window.__msbSwitchAccountsBound = true;

  function switchDialog(){
    return document.getElementById('saSwitchDialog');
  }

  function closeSwitchDialog(){
    var d = switchDialog();
    if (d && d.open) d.close();
  }

  window.msbOpenSwitchAccounts = function(){
    var d = switchDialog();
    if (!d) {
      window.location.href = 'switch_accounts.php';
      return false;
    }
    document.querySelectorAll('.msb-signout-group.is-open').forEach(function(el){
      el.classList.remove('is-open');
      var btn = el.querySelector('.msb-signout-toggle');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
    if (d.showModal && !d.open) d.showModal();
    loadSwitchAccountList(d);
    return true;
  };

  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function uniqueHandle(row){
    var user = String(row.username || row.handle || '').replace(/^@/, '').trim();
    var id = parseInt(row.id || row.user_id || 0, 10);
    return user ? ('@' + user) : ('ID ' + id);
  }

  function uniqueDetail(row, counts){
    var user = String(row.username || row.handle || '').replace(/^@/, '').trim();
    var name = String(row.name || row.display_name || '').trim();
    var kind = String(row.kind || 'Personal').trim();
    var id = parseInt(row.id || row.user_id || 0, 10);
    var key = (user || ('id:' + id)).toLowerCase();
    var parts = [];
    if (name && name.toLowerCase() !== user.toLowerCase()) parts.push(name);
    if (kind) parts.push(kind);
    if ((counts[key] || 0) > 1) parts.push('ID ' + id);
    return parts.length ? parts.join(' · ') : uniqueHandle(row);
  }

  function renderSwitchRows(list, accounts, csrf, currentId){
    var counts = {};
    (accounts || []).forEach(function(row){
      var user = String(row.username || row.handle || '').replace(/^@/, '').trim();
      var id = parseInt(row.id || row.user_id || 0, 10);
      var key = (user || ('id:' + id)).toLowerCase();
      counts[key] = (counts[key] || 0) + 1;
    });
    list.innerHTML = (accounts || []).map(function(row){
      var id = parseInt(row.id || row.user_id || 0, 10);
      var current = !!(row.current || row.is_current || (currentId && id === currentId));
      var title = uniqueHandle(row);
      var detail = uniqueDetail(row, counts);
      var av = String(row.avatar_url || row.image || ('avatar.php?u=' + id + '&name=' + encodeURIComponent(title)));
      var action = current
        ? '<span class="as-using">Using now</span>'
        : '<button type="button" class="as-btn js-account-switch" data-user-id="' + id + '" data-csrf="' + esc(csrf || '') + '" aria-label="Switch to ' + esc(title) + '">Switch</button>';
      return '<li class="as-row' + (current ? ' is-current' : '') + '">'
        + '<img class="as-avatar" src="' + esc(av) + '" alt="" width="44" height="44" data-name="' + esc(title) + '" onerror="this.onerror=null;this.src=\'avatar.php?name=\'+encodeURIComponent(this.getAttribute(\'data-name\')||\'U\')+\'&amp;s=96\';">'
        + '<div class="as-copy"><div class="as-name">' + esc(title) + '</div><div class="as-meta">' + esc(detail) + '</div></div>'
        + action
        + '</li>';
    }).join('');
  }

  function loadSwitchAccountList(dialog){
    var list = dialog.querySelector('.as-list');
    var emptyEl = dialog.querySelector('.as-empty');
    var leadEl = dialog.querySelector('.as-lead');
    if (!list) return;
    fetch('ajax/account_switch.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function(res){ return res.json(); }).then(function(data){
      if (!data || data.ok === false) throw new Error((data && data.error) || 'Unable to load accounts');
      if (data.staff_blocked) {
        if (leadEl) leadEl.textContent = 'You are in a staff publisher session. Switch accounts from a personal or owner login.';
        list.innerHTML = '';
        if (emptyEl) emptyEl.hidden = true;
        return;
      }
      var accounts = data.accounts || data.users || [];
      var csrf = data.csrf_token || data.csrf || '';
      var currentId = parseInt(data.current_user_id || 0, 10);
      renderSwitchRows(list, accounts, csrf, currentId);
      if (emptyEl) emptyEl.hidden = accounts.length > 0;
      var current = accounts.filter(function(row){ return row.current || row.is_current || (currentId && parseInt(row.id || row.user_id || 0, 10) === currentId); })[0];
      var handle = current ? uniqueHandle(current) : 'this account';
      if (leadEl) leadEl.textContent = 'Select a unique account to replace ' + handle + '. Usernames stay distinct so you can tell them apart.';
    }).catch(function(){
      if (emptyEl) {
        emptyEl.hidden = false;
        emptyEl.textContent = 'Could not load linked accounts. Try again.';
      }
    });
  }

  function wantsSwitchModal(el){
    if (!el) return false;
    if (el.classList && el.classList.contains('js-open-switch-accounts')) return true;
    var href = (el.getAttribute && (el.getAttribute('href') || el.getAttribute('data-href'))) || '';
    return /switch_accounts\.php/i.test(href);
  }

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest ? e.target.closest('.js-open-switch-accounts, a[href*="switch_accounts.php"], [data-href*="switch_accounts.php"]') : null;
    if (trigger && switchDialog() && wantsSwitchModal(trigger)) {
      e.preventDefault();
      e.stopPropagation();
      window.msbOpenSwitchAccounts();
    }
  }, true);

  document.addEventListener('click', function(e){
    var dSwitch = switchDialog();
    if (dSwitch && dSwitch.open && e.target === dSwitch) {
      dSwitch.close();
      return;
    }
    var back = e.target && e.target.closest ? e.target.closest('.js-sa-back, .js-sa-close') : null;
    if (back) {
      e.preventDefault();
      var modal = back.closest ? back.closest('#saSwitchDialog, dialog.sa-modal') : null;
      if (modal) {
        closeSwitchDialog();
        return;
      }
      if (window.history.length > 1) window.history.back();
      else window.location.href = back.getAttribute('href') || 'home.php';
      return;
    }
    var addBtn = e.target.closest('.js-as-add-logout');
    if (addBtn) {
      e.preventDefault();
      var inModal = !!(addBtn.closest && addBtn.closest('#saSwitchDialog'));
      var dialog = document.getElementById(inModal ? 'saAddLogoutDialog' : 'asAddLogoutDialog')
        || document.getElementById('saAddLogoutDialog')
        || document.getElementById('asAddLogoutDialog');
      var copyEl = (dialog && dialog.querySelector('#saAddLogoutCopy, #asAddLogoutCopy'))
        || document.getElementById(inModal ? 'saAddLogoutCopy' : 'asAddLogoutCopy');
      var type = (addBtn.getAttribute('data-account-type') || 'personal').toLowerCase();
      var view = (addBtn.getAttribute('data-auth-view') || '').toLowerCase();
      var labels = { personal: 'personal', publisher: 'publisher', commerce: 'commerce' };
      if (!labels[type]) type = 'personal';
      if (dialog) {
        dialog.setAttribute('data-pending-type', type);
        dialog.setAttribute('data-pending-view', view === 'register' ? 'register' : '');
      }
      if (copyEl) {
        copyEl.textContent = view === 'register'
          ? 'You will leave this account to create a new one. Cancel to stay. Logout ends this session and you cannot come back without signing in.'
          : ('You will leave this account to continue as ' + labels[type]
            + '. Cancel to stay. Logout ends this session and you cannot come back without signing in.');
      }
      if (dialog && dialog.showModal && dialog.tagName === 'DIALOG') dialog.showModal();
      else if (dialog) dialog.hidden = false;
      return;
    }
    if (e.target && (e.target.id === 'asAddLogoutCancel' || e.target.id === 'saAddLogoutCancel')) {
      var d = e.target.closest('dialog, .as-logout-overlay') || document.getElementById('saAddLogoutDialog') || document.getElementById('asAddLogoutDialog');
      if (d && d.open) d.close();
      else if (d) d.hidden = true;
      return;
    }
    if (e.target && (e.target.id === 'asAddLogoutConfirm' || e.target.id === 'saAddLogoutConfirm')) {
      var d2 = e.target.closest('dialog, .as-logout-overlay') || document.getElementById('saAddLogoutDialog') || document.getElementById('asAddLogoutDialog');
      var type2 = d2 ? (d2.getAttribute('data-pending-type') || 'personal') : 'personal';
      var view2 = d2 ? (d2.getAttribute('data-pending-view') || '') : '';
      var url = 'logout.php?account_type=' + encodeURIComponent(type2);
      if (view2 === 'register') url += '&view=register';
      window.location.replace(url);
      return;
    }
    var btn = e.target.closest('.js-account-switch');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var uid = parseInt(btn.getAttribute('data-user-id') || '0', 10);
    if (!uid) return;
    btn.disabled = true;
    var body = new FormData();
    body.append('target_user_id', String(uid));
    body.append('csrf_token', btn.getAttribute('data-csrf') || window.__MSB_CSRF_TOKEN || '');
    fetch('ajax/account_switch.php', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      redirect: 'error',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function(res){
      var ct = (res.headers.get('content-type') || '');
      if (!res.ok || ct.indexOf('json') === -1) {
        throw new Error('Could not switch accounts.');
      }
      return res.json();
    }).then(function(data){
      if (!data || !data.ok) throw new Error((data && data.error) || 'switch failed');
      var next = String(data.redirect || 'home.php?tab=for-you');
      if (/index\.php/i.test(next)) next = 'home.php?tab=for-you';
      window.location.replace(next);
    }).catch(function(err){
      btn.disabled = false;
      window.alert(err && err.message ? err.message : 'Could not switch accounts.');
    });
  });
  document.addEventListener('cancel', function(e){
    if (!e.target || !e.target.id) return;
    if (e.target.id === 'asAddLogoutDialog' || e.target.id === 'saAddLogoutDialog' || e.target.id === 'saSwitchDialog') {
      e.preventDefault();
      if (e.target.open) e.target.close();
    }
  }, true);
})();
</script>
    <?php
  }
}

if (!function_exists('msb_render_switch_accounts_picker')) {
  function msb_render_switch_accounts_picker(array $accounts, bool $staffBlocked, $mode = 'embed'): void
  {
    if ($mode === true) {
      $mode = 'page';
    } elseif ($mode === false || $mode === null || $mode === '') {
      $mode = 'embed';
    }
    $showChrome = ($mode === 'page' || $mode === 'modal');
    $skipAddDialog = ($mode === 'modal');
    $backClass = $mode === 'modal' ? 'sa-back js-sa-close' : 'sa-back js-sa-back';

    msb_switch_accounts_assets();
    $h = 'msb_switch_accounts_h';
    $csrf = function_exists('csrfToken') ? csrfToken() : '';
    $currentHandle = '';
    foreach ($accounts as $acc) {
      if (!empty($acc['current']) || !empty($acc['is_current'])) {
        $uh = trim((string)($acc['username'] ?? $acc['handle'] ?? ''));
        $currentHandle = $uh !== '' ? ('@' . ltrim($uh, '@')) : ('ID ' . (int)($acc['id'] ?? 0));
        break;
      }
    }
    $lead = 'Select a unique account to replace ' . ($currentHandle !== '' ? $currentHandle : 'this account') . '. Usernames stay distinct so you can tell them apart.';
    if ($showChrome) {
      echo '<div class="sa-page">';
      echo '<header class="sa-head">';
      echo '<a class="' . $backClass . '" href="home.php" aria-label="Back">‹</a>';
      echo '<div>';
      echo '<h1 class="sa-title"' . ($mode === 'modal' ? ' id="saSwitchTitle"' : '') . '>Switch accounts</h1>';
      echo '<p class="as-lead">' . $h($lead) . '</p>';
      echo '</div></header>';
    }
    if ($staffBlocked) {
      echo '<p class="as-lead">You are in a staff publisher session. Switch accounts from a personal or owner login.</p>';
      if ($showChrome) {
        echo '</div>';
      }
      msb_switch_accounts_js();
      return;
    }
    $handleCounts = [];
    foreach ($accounts as $acc) {
      $uh = strtolower(trim((string)($acc['username'] ?? $acc['handle'] ?? '')));
      if ($uh === '') {
        $uh = 'id:' . (int)($acc['id'] ?? 0);
      }
      $handleCounts[$uh] = ($handleCounts[$uh] ?? 0) + 1;
    }
    echo '<div class="as-wrap">';
    if (!$showChrome) {
      echo '<p class="as-lead">' . $h($lead) . '</p>';
    }
    if ($accounts === []) {
      echo '<p class="as-empty">This account is ready. Add another login to switch between them.</p>';
    } else {
      echo '<p class="as-empty" hidden>This account is ready. Add another login to switch between them.</p>';
    }
    echo '<ul class="as-list" id="' . ($mode === 'modal' ? 'saAccountList' : 'asAccountList') . '">';
    foreach ($accounts as $acc) {
      $aid = (int)($acc['id'] ?? 0);
      $aname = trim((string)($acc['name'] ?? $acc['display_name'] ?? ''));
      $auser = trim((string)($acc['username'] ?? $acc['handle'] ?? ''));
      $akind = trim((string)($acc['kind'] ?? 'Personal'));
      $current = !empty($acc['current']) || !empty($acc['is_current']);
      $handleKey = strtolower($auser !== '' ? $auser : ('id:' . $aid));
      $uniqueTitle = $auser !== '' ? ('@' . ltrim($auser, '@')) : ('ID ' . $aid);
      $parts = [];
      if ($aname !== '' && strcasecmp($aname, $auser) !== 0) {
        $parts[] = $aname;
      }
      if ($akind !== '') {
        $parts[] = $akind;
      }
      if (($handleCounts[$handleKey] ?? 0) > 1) {
        $parts[] = 'ID ' . $aid;
      }
      $uniqueDetail = $parts !== [] ? implode(' · ', $parts) : $uniqueTitle;
      $av = function_exists('account_switch_avatar_url')
        ? account_switch_avatar_url($acc, 96)
        : ('avatar.php?u=' . $aid . '&name=' . rawurlencode($uniqueTitle));
      echo '<li class="as-row' . ($current ? ' is-current' : '') . '">';
      echo '<img class="as-avatar" src="' . $h($av) . '" alt="" width="44" height="44" data-name="' . $h($uniqueTitle) . '" onerror="this.onerror=null;this.src=\'avatar.php?name=\'+encodeURIComponent(this.getAttribute(\'data-name\')||\'U\')+\'&amp;s=96\';">';
      echo '<div class="as-copy"><div class="as-name">' . $h($uniqueTitle) . '</div>';
      echo '<div class="as-meta">' . $h($uniqueDetail) . '</div></div>';
      if ($current) {
        echo '<span class="as-using">Using now</span>';
      } else {
        echo '<button type="button" class="as-btn js-account-switch" data-user-id="' . (int)$aid . '" data-csrf="' . $h($csrf) . '" aria-label="Switch to ' . $h($uniqueTitle) . '">Switch</button>';
      }
      echo '</li>';
    }
    echo '</ul>';
    echo '<div class="as-add">';
    echo '<div class="as-add-title">Add another account</div>';
    echo '<p class="as-add-copy">Sign in or create a second unique username. It stays linked so you can switch to it later.</p>';
    echo '<div class="as-add-row">';
    echo '<a class="as-btn as-btn-ghost js-as-add-logout" href="logout.php?account_type=personal" data-account-type="personal">Add personal</a>';
    echo '<a class="as-btn as-btn-ghost js-as-add-logout" href="logout.php?account_type=publisher" data-account-type="publisher">Add publisher</a>';
    echo '<a class="as-btn as-btn-ghost js-as-add-logout" href="logout.php?account_type=commerce" data-account-type="commerce">Add commerce</a>';
    echo '<a class="as-btn as-btn-ghost js-as-add-logout" href="logout.php?account_type=personal&amp;view=register" data-account-type="personal" data-auth-view="register">Create new</a>';
    echo '</div></div>';
    echo '</div>';
    if ($skipAddDialog) {
      echo '<div class="as-logout-overlay" id="saAddLogoutDialog" hidden role="dialog" aria-labelledby="saAddLogoutTitle">';
      echo '<div class="as-logout-overlay-card">';
      echo '<h2 id="saAddLogoutTitle">Log out to continue?</h2>';
      echo '<p id="saAddLogoutCopy">You will leave this account. Cancel to stay, or log out. After logout you cannot come back to this session.</p>';
      echo '<div class="as-logout-actions">';
      echo '<button type="button" class="as-btn as-btn-ghost" id="saAddLogoutCancel">Cancel</button>';
      echo '<button type="button" class="as-btn as-logout-confirm" id="saAddLogoutConfirm">Logout</button>';
      echo '</div></div></div>';
    } else {
      echo '<dialog class="as-logout-dialog" id="asAddLogoutDialog" aria-labelledby="asAddLogoutTitle">';
      echo '<h2 id="asAddLogoutTitle">Log out to continue?</h2>';
      echo '<p id="asAddLogoutCopy">You will leave this account. Cancel to stay, or log out. After logout you cannot come back to this session.</p>';
      echo '<div class="as-logout-actions">';
      echo '<button type="button" class="as-btn as-btn-ghost" id="asAddLogoutCancel">Cancel</button>';
      echo '<button type="button" class="as-btn as-logout-confirm" id="asAddLogoutConfirm">Logout</button>';
      echo '</div></dialog>';
    }
    if ($showChrome) {
      echo '</div>';
    }
    msb_switch_accounts_js();
  }
}

if (!function_exists('msb_mount_switch_accounts_modal')) {
  function msb_mount_switch_accounts_modal($dbh = null, int $meId = 0): void
  {
    if (!empty($GLOBALS['msb_switch_accounts_modal_mounted'])) {
      return;
    }
    $GLOBALS['msb_switch_accounts_modal_mounted'] = true;
    require_once __DIR__ . '/account_switch.php';
    if (!($dbh instanceof PDO)) {
      try {
        require_once dirname(__DIR__) . '/controller.php';
        $dbh = (new Controller())->pdo();
      } catch (Throwable $e) {
        $dbh = null;
      }
    }
    if ($meId <= 0) {
      $meId = (int)($_SESSION['user_id'] ?? 0);
    }
    $staffBlocked = function_exists('account_switch_is_staff_session') && account_switch_is_staff_session();
    $accounts = [];
    if ($dbh instanceof PDO && $meId > 0 && !$staffBlocked && function_exists('account_switch_list')) {
      try {
        $accounts = account_switch_list($dbh, $meId);
      } catch (Throwable $e) {
        $accounts = [];
      }
    }
    echo '<dialog class="sa-modal" id="saSwitchDialog" aria-labelledby="saSwitchTitle">';
    msb_render_switch_accounts_picker($accounts, $staffBlocked, 'modal');
    echo '</dialog>';
  }
}
