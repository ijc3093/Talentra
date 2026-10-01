<?php
/** Bottom-right account gear on home (same Account chooser as the login page). */
$hagMeId = (int)($_SESSION['user_id'] ?? 0);
if ($hagMeId <= 0 || !empty($_GET['tab_embed']) || !empty($GLOBALS['msb_home_account_gear_rendered'])) {
    return;
}
$GLOBALS['msb_home_account_gear_rendered'] = true;

$hagKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? 'personal')));
$hagCategory = '';
if ($hagMeId > 0) {
    try {
        require_once __DIR__ . '/../controller.php';
        $hagSt = (new Controller())->pdo()->prepare('SELECT account_kind, publisher_category FROM users WHERE id = :id LIMIT 1');
        $hagSt->execute([':id' => $hagMeId]);
        $hagRow = $hagSt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($hagRow) {
            $hagKind = strtolower(trim((string)($hagRow['account_kind'] ?? $hagKind)));
            $hagCategory = strtolower(trim((string)($hagRow['publisher_category'] ?? '')));
        }
    } catch (Throwable $e) {
    }
}
$hagCurrent = ($hagKind === 'publisher') ? ($hagCategory === 'commerce' ? 'commerce' : 'publisher') : 'personal';
$hagHints = [
    'personal' => app_t('Friends & family — your personal story space.'),
    'publisher' => app_t('News & media brands — CNN, Fox, and more.'),
    'commerce' => app_t('Brand stores and seller accounts'),
];
$hagLabels = [
    'personal' => app_t('Personal user'),
    'publisher' => app_t('Publisher'),
    'commerce' => app_t('Commerce'),
];
?>
<div class="home-gear-wrap" id="homeGearWrap">
  <button type="button" class="home-gear" id="homeGearBtn" aria-label="<?= app_t_attr('Settings') ?>" aria-haspopup="true" aria-expanded="false" aria-controls="homeGearMenu">
    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
      <path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96c-.5-.39-1.04-.7-1.63-.94l-.36-2.54A.5.5 0 0014.4 2h-4.8a.5.5 0 00-.5.42l-.36 2.54c-.59.24-1.13.55-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.7 8.48a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.82 14.16a.5.5 0 00-.12.64l1.92 3.32c.14.23.4.32.64.22l2.39-.96c.5.39 1.04.7 1.63.94l.36 2.54c.05.24.26.42.5.42h4.8c.24 0 .45-.18.5-.42l.36-2.54c.59-.24 1.13-.55 1.63-.94l2.39.96c.24.1.51 0 .64-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1112 8.4a3.6 3.6 0 010 7.2z"/>
    </svg>
  </button>
  <div class="home-gear-menu" id="homeGearMenu" hidden role="dialog" aria-labelledby="homeGearBtn">
    <h2 class="home-gear-title"><?= htmlspecialchars(app_t('Account'), ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="home-gear-list" role="radiogroup" aria-label="<?= app_t_attr('Choose account type') ?>">
      <?php foreach ($hagLabels as $hagType => $hagLabel): ?>
      <label class="home-gear-option">
        <input type="radio" name="home_account_type" value="<?= $hagType ?>"<?= $hagCurrent === $hagType ? ' checked' : '' ?>>
        <span><?= htmlspecialchars($hagLabel, ENT_QUOTES, 'UTF-8') ?></span>
      </label>
      <?php endforeach; ?>
    </div>
    <p class="home-gear-hint" id="homeGearHint"><?= htmlspecialchars($hagHints[$hagCurrent], ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</div>
<style>
.home-gear-wrap{position:fixed;right:12px;bottom:40px;z-index:96;}
.home-gear{
  width:40px;height:40px;border:0;padding:0;border-radius:50%;
  background:transparent;color:var(--msb-palette-icon,#111);cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;
}
.home-gear:hover,.home-gear:focus-visible{background:var(--msb-palette-hover-bg,#f4f4f4);outline:none;}
.home-gear svg{display:block;}
.home-gear-menu{
  position:absolute;right:0;bottom:calc(100% + 6px);min-width:240px;
  background:var(--msb-palette-bg,#fff);color:var(--msb-palette-text,#0f172a);
  border:1px solid #2a2f36;border-radius:12px;padding:12px 12px 6px;box-sizing:border-box;
}
.home-gear-menu[hidden]{display:none !important;}
.home-gear-title{margin:0 0 6px;font-size:11px;font-weight:600;color:var(--msb-palette-text-muted,#64748b);}
.home-gear-list{display:flex;flex-direction:column;gap:6px;}
.home-gear-option{display:flex;align-items:center;gap:6px;padding:4px 0;font-size:12px;font-weight:600;cursor:pointer;}
.home-gear-option input{margin:0;}
.home-gear-hint{margin:8px 0 6px;font-size:11px;line-height:1.4;color:var(--msb-palette-text-muted,#64748b);}
@media (max-width:1024px){.home-gear-wrap{display:none;}}
</style>
<script>
(function(){
  var btn = document.getElementById('homeGearBtn');
  var menu = document.getElementById('homeGearMenu');
  var hint = document.getElementById('homeGearHint');
  if (!btn || !menu) return;
  var current = <?= json_encode($hagCurrent) ?>;
  var hints = <?= json_encode($hagHints, JSON_UNESCAPED_UNICODE) ?>;
  var kindLabel = { personal: 'Personal', publisher: 'Publisher', commerce: 'Commerce' };
  var busy = false;

  function close(){
    menu.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  }
  function resetChoice(){
    menu.querySelectorAll('input[name="home_account_type"]').forEach(function(r){ r.checked = r.value === current; });
    if (hint) hint.textContent = hints[current] || '';
  }
  btn.addEventListener('click', function(ev){
    ev.stopPropagation();
    var open = menu.hidden;
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', close);
  menu.addEventListener('click', function(ev){ ev.stopPropagation(); });
  document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape') close(); });

  menu.addEventListener('change', function(ev){
    var radio = ev.target;
    if (!radio || radio.name !== 'home_account_type' || busy) return;
    var type = radio.value;
    if (hint) hint.textContent = hints[type] || '';
    if (type === current) return;
    busy = true;
    var addHref = 'index.php?add_account=1&account_type=' + encodeURIComponent(type) + (type === 'personal' ? '' : '&view=register');
    fetch('ajax/account_switch.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(data){
        var list = (data && data.accounts) || [];
        var target = null;
        var preferredId = Number((data && data.preferred && data.preferred[type]) || 0);
        for (var i = 0; i < list.length; i++) {
          if (preferredId > 0 && Number(list[i].id) === preferredId && !list[i].is_current) { target = list[i]; break; }
        }
        if (!target && type !== 'personal') {
          for (var j = 0; j < list.length; j++) {
            if (!list[j].is_current && String(list[j].kind || '') === kindLabel[type]) { target = list[j]; break; }
          }
        }
        if (!target || data.staff_blocked) {
          window.location.href = addHref;
          return;
        }
        var body = new URLSearchParams();
        body.set('target_user_id', String(target.id));
        body.set('csrf_token', String(data.csrf_token || ''));
        return fetch('ajax/account_switch.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString()
        }).then(function(r){ return r.json(); }).then(function(res){
          if (res && res.ok) {
            window.location.href = res.redirect || 'home.php?tab=for-you';
            return;
          }
          busy = false;
          resetChoice();
          alert((res && res.error) || 'Unable to switch accounts right now.');
        });
      })
      .catch(function(){
        busy = false;
        resetChoice();
      });
  });
})();
</script>
