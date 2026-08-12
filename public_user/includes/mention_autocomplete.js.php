<?php
// Shared @mention autocomplete for text fields (create post, comments, etc.)
if (!defined('MSB_MENTION_AC_LOADED')) {
    define('MSB_MENTION_AC_LOADED', 1);
?>
<style id="msbMentionAcStyle">
.msb-mention-ac{
  position:fixed;z-index:2147483647;min-width:220px;max-width:320px;
  max-height:min(240px, 40vh);overflow:auto;
  background:var(--msb-palette-bg,#fff);
  color:var(--msb-palette-text,#0f172a);
  border:1px solid var(--msb-palette-border,rgba(15,23,42,.12));
  border-radius:12px;
  box-shadow:0 12px 32px rgba(15,23,42,.28);
  padding:6px;
  display:none;
  pointer-events:auto;
}
dialog .msb-mention-ac,
dialog[open] .msb-mention-ac{
  position:absolute;
  z-index:2147483647;
}
#pcmTagSheet.pcm-tag-dialog,
dialog#pcmTagSheet,
#pcmMentionSheet.pcm-tag-dialog,
dialog#pcmMentionSheet{
  overflow:visible !important;
  position:fixed;
}
.msb-mention-ac.is-open{display:block;}
.msb-mention-ac-item{
  display:flex;align-items:center;gap:10px;
  width:100%;border:0;background:transparent;cursor:pointer;
  text-align:left;padding:8px 10px;border-radius:10px;
  color:inherit;font:inherit;
}
.msb-mention-ac-item:hover,
.msb-mention-ac-item.is-active{
  background:var(--msb-palette-hover-bg,rgba(15,23,42,.06));
}
.msb-mention-ac-ava{
  width:32px;height:32px;border-radius:999px;object-fit:cover;
  background:#e2e8f0;flex:0 0 32px;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;color:#475569;
}
.msb-mention-ac-meta{min-width:0;flex:1;}
.msb-mention-ac-user{font-weight:800;font-size:13px;line-height:1.2;}
.msb-mention-ac-name{font-size:12px;opacity:.7;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.msb-mention-ac-empty{padding:10px 12px;font-size:12px;opacity:.7;}
a.msb-mention{color:var(--msb-palette-text, #0b1220);font-weight:800;text-decoration:none;}
a.msb-mention:hover{text-decoration:underline;opacity:.85;}
.msb-tag-people{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.msb-tag-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:5px 10px;border-radius:999px;
  background:var(--msb-palette-action-soft,rgba(37,99,235,.1));
  color:var(--msb-palette-text,#0f172a);
  font-size:12px;font-weight:700;
}
.msb-tag-chip button{
  border:0;background:transparent;cursor:pointer;padding:0 2px;
  color:inherit;font-size:14px;line-height:1;opacity:.7;
}
.msb-tag-chip button:hover{opacity:1;}
</style>
<script>
(function(){
  if (window.MSBMentionAC) return;

  var ENDPOINT = 'ajax/mention_search.php';
  var active = null;
  var menu = null;
  var items = [];
  var hi = -1;
  var timer = null;
  var reqSeq = 0;

  function menuHostFor(el){
    try {
      var d = el && el.closest ? el.closest('dialog') : null;
      if (d && (d.open || d.hasAttribute('open'))) return d;
    } catch (e) {}
    return document.body;
  }

  function ensureMenu(el){
    var host = menuHostFor(el);
    if (!menu) {
      menu = document.createElement('div');
      menu.className = 'msb-mention-ac';
      menu.setAttribute('role', 'listbox');
    }
    if (menu.parentNode !== host) {
      host.appendChild(menu);
    }
    return menu;
  }

  function closeMenu(){
    if (!menu) return;
    menu.classList.remove('is-open');
    menu.innerHTML = '';
    items = [];
    hi = -1;
    active = null;
    // Park on body when closed so it is not trapped in a closed dialog.
    if (menu.parentNode && menu.parentNode !== document.body) {
      try { document.body.appendChild(menu); } catch (e) {}
    }
  }

  function positionMenu(el){
    if (!menu || !el) return;
    ensureMenu(el);
    menu.style.zIndex = '2147483647';
    var r = el.getBoundingClientRect();
    var host = menu.parentNode;
    var inDialog = !!(host && host.tagName === 'DIALOG');
    // Inside <dialog>, use absolute coords relative to the dialog so the
    // menu stays in the top layer (body-mounted menus paint under the modal).
    if (inDialog) {
      var hr = host.getBoundingClientRect();
      var spaceBelow = hr.bottom - r.bottom;
      var spaceAbove = r.top - hr.top;
      var preferAbove = spaceBelow < 140 && spaceAbove > spaceBelow;
      var left = r.left - hr.left;
      var maxW = Math.max(180, hr.width - 16);
      if (left < 8) left = 8;
      if (left > maxW - 180) left = Math.max(8, maxW - 180);
      menu.style.position = 'absolute';
      menu.style.left = left + 'px';
      menu.style.right = 'auto';
      menu.style.width = Math.min(320, maxW) + 'px';
      if (preferAbove) {
        menu.style.top = 'auto';
        menu.style.bottom = (hr.bottom - r.top + 4) + 'px';
        menu.style.maxHeight = Math.min(240, Math.max(120, spaceAbove - 8)) + 'px';
      } else {
        menu.style.bottom = 'auto';
        menu.style.top = (r.bottom - hr.top + 4) + 'px';
        menu.style.maxHeight = Math.min(240, Math.max(120, Math.max(spaceBelow, 160) - 8)) + 'px';
      }
      return;
    }
    menu.style.position = 'fixed';
    var spaceBelow = window.innerHeight - r.bottom;
    var preferAbove = spaceBelow < 160 && r.top > spaceBelow;
    var left = r.left;
    var maxLeft = document.documentElement.clientWidth - 240;
    if (left > maxLeft) left = Math.max(8, maxLeft);
    if (left < 8) left = 8;
    menu.style.left = left + 'px';
    menu.style.right = 'auto';
    menu.style.width = 'min(320px, calc(100vw - 16px))';
    if (preferAbove) {
      menu.style.top = 'auto';
      menu.style.bottom = (window.innerHeight - r.top + 4) + 'px';
      menu.style.maxHeight = Math.min(240, Math.max(120, r.top - 16)) + 'px';
    } else {
      menu.style.bottom = 'auto';
      menu.style.top = (r.bottom + 4) + 'px';
      menu.style.maxHeight = Math.min(240, Math.max(120, spaceBelow - 16)) + 'px';
    }
  }

  function getMentionQuery(el){
    var val = String(el.value || '');
    var start = typeof el.selectionStart === 'number' ? el.selectionStart : val.length;
    var before = val.slice(0, start);
    var m = before.match(/(^|[\s([{])@([A-Za-z0-9_]{0,50})$/);
    if (!m) return null;
    return { query: m[2] || '', atStart: before.length - (m[2] || '').length - 1 };
  }

  function replaceMention(el, atStart, username){
    var val = String(el.value || '');
    var caret = typeof el.selectionStart === 'number' ? el.selectionStart : val.length;
    var before = val.slice(0, atStart);
    var after = val.slice(caret);
    var insert = '@' + username + ' ';
    el.value = before + insert + after;
    var next = before.length + insert.length;
    try { el.setSelectionRange(next, next); } catch (e) {}
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.focus();
  }

  function render(list, el, atStart){
    ensureMenu(el);
    items = list || [];
    hi = items.length ? 0 : -1;
    if (!items.length) {
      menu.innerHTML = '<div class="msb-mention-ac-empty">No people found</div>';
      menu.classList.add('is-open');
      positionMenu(el);
      return;
    }
    menu.innerHTML = items.map(function(u, i){
      var name = (u.name || '').replace(/</g,'&lt;');
      var user = (u.username || '').replace(/</g,'&lt;');
      var img = (u.image || '').replace(/"/g,'');
      var ava = img
        ? '<img class="msb-mention-ac-ava" src="'+img+'" alt="">'
        : '<span class="msb-mention-ac-ava">'+(user.slice(0,2).toUpperCase()||'?')+'</span>';
      return '<button type="button" class="msb-mention-ac-item'+(i===0?' is-active':'')+'" data-i="'+i+'" role="option">'
        + ava
        + '<span class="msb-mention-ac-meta"><div class="msb-mention-ac-user">@'+user+'</div>'
        + (name ? '<div class="msb-mention-ac-name">'+name+'</div>' : '')
        + '</span></button>';
    }).join('');
    menu.classList.add('is-open');
    positionMenu(el);
    Array.prototype.forEach.call(menu.querySelectorAll('.msb-mention-ac-item'), function(btn){
      btn.addEventListener('mousedown', function(ev){
        ev.preventDefault();
        var i = parseInt(btn.getAttribute('data-i') || '-1', 10);
        if (i < 0 || !items[i]) return;
        replaceMention(el, atStart, items[i].username);
        if (typeof el.__msbOnMentionPick === 'function') {
          try { el.__msbOnMentionPick(items[i]); } catch (e) {}
        }
        closeMenu();
      });
    });
  }

  function search(el, q, atStart){
    var seq = ++reqSeq;
    active = { el: el, atStart: atStart };
    fetch(ENDPOINT + '?q=' + encodeURIComponent(q) + '&limit=8', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function(r){ return r.json(); }).then(function(data){
      if (seq !== reqSeq || !active || active.el !== el) return;
      render((data && data.users) || [], el, atStart);
    }).catch(function(){
      if (seq !== reqSeq) return;
      closeMenu();
    });
  }

  function onInput(ev){
    var el = ev.target;
    if (!el || !el.matches || !el.matches('textarea, input[type="text"], input:not([type])')) return;
    if (el.getAttribute('data-msb-mention') === '0') return;
    var info = getMentionQuery(el);
    if (!info) { closeMenu(); return; }
    clearTimeout(timer);
    timer = setTimeout(function(){ search(el, info.query, info.atStart); }, 120);
  }

  function onKey(ev){
    if (!menu || !menu.classList.contains('is-open') || !items.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      hi = (hi + 1) % items.length;
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      hi = (hi - 1 + items.length) % items.length;
    } else if (ev.key === 'Enter' || ev.key === 'Tab') {
      if (hi < 0 || !items[hi] || !active) return;
      ev.preventDefault();
      replaceMention(active.el, active.atStart, items[hi].username);
      if (typeof active.el.__msbOnMentionPick === 'function') {
        try { active.el.__msbOnMentionPick(items[hi]); } catch (e) {}
      }
      closeMenu();
      return;
    } else if (ev.key === 'Escape') {
      closeMenu();
      return;
    } else {
      return;
    }
    Array.prototype.forEach.call(menu.querySelectorAll('.msb-mention-ac-item'), function(btn, i){
      btn.classList.toggle('is-active', i === hi);
    });
  }

  function bindField(el, onPick){
    if (!el) return;
    // Always allow a later caller (e.g. Tag people sheet) to attach/replace onPick,
    // even if bindRoot already wired the field for @autocomplete.
    if (typeof onPick === 'function') el.__msbOnMentionPick = onPick;
    if (el.__msbMentionBound) return;
    el.__msbMentionBound = true;
    el.addEventListener('input', onInput);
    el.addEventListener('keydown', onKey);
    el.addEventListener('blur', function(){ setTimeout(closeMenu, 160); });
  }

  function bindRoot(root){
    root = root || document;
    Array.prototype.forEach.call(root.querySelectorAll('textarea, input[type="text"]'), function(el){
      if (el.getAttribute('data-msb-mention') === '0') return;
      // Prefer post composers + comment boxes
      var name = (el.getAttribute('name') || '').toLowerCase();
      var id = (el.id || '').toLowerCase();
      var ph = (el.getAttribute('placeholder') || '').toLowerCase();
      var useful = /body|title|comment|caption|slide|intro|desc/.test(name + ' ' + id + ' ' + ph)
        || el.classList.contains('pv-comment-input')
        || el.closest('.create-post-slide-fields, .create-post-dialog, form[action*="post_save"], .pv-input, .tt-comments-foot');
      if (!useful && el.getAttribute('data-msb-mention') !== '1') return;
      bindField(el);
    });
  }

  /** Tag-people chips helper for create-post */
  function mountTagPeople(opts){
    opts = opts || {};
    var wrap = opts.wrap;
    var hidden = opts.hidden;
    var input = opts.input;
    if (!wrap || !hidden) return;
    var selected = {};

    function syncHidden(){
      hidden.value = Object.keys(selected).join(',');
    }
    function renderChips(){
      wrap.innerHTML = '';
      Object.keys(selected).forEach(function(id){
        var u = selected[id];
        var chip = document.createElement('span');
        chip.className = 'msb-tag-chip';
        chip.innerHTML = '@' + (u.username || id) + ' <button type="button" aria-label="Remove">&times;</button>';
        chip.querySelector('button').addEventListener('click', function(){
          delete selected[id];
          syncHidden();
          renderChips();
        });
        wrap.appendChild(chip);
      });
    }
    function addUser(u){
      if (!u || !u.id) return;
      selected[String(u.id)] = u;
      syncHidden();
      renderChips();
      if (input) {
        input.value = '';
        try { input.focus(); } catch (e) {}
      }
    }
    if (input) {
      bindField(input, addUser);
      input.setAttribute('data-msb-mention', '1');
      input.setAttribute('placeholder', input.getAttribute('placeholder') || 'Tag people with @username');
    }
    return { addUser: addUser, selected: selected };
  }

  document.addEventListener('input', onInput, true);
  document.addEventListener('keydown', onKey, true);
  window.addEventListener('scroll', function(){ if (active && active.el) positionMenu(active.el); }, true);
  window.addEventListener('resize', function(){ if (active && active.el) positionMenu(active.el); });

  window.MSBMentionAC = {
    bind: bindField,
    bindRoot: bindRoot,
    mountTagPeople: mountTagPeople,
    close: closeMenu,
    linkify: function(text){
      return String(text || '').replace(/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]{2,50})\b/g, function(_, pre, user){
        return pre + '<a class="msb-mention" href="profile.php?username=' + encodeURIComponent(user) + '">@' + user + '</a>';
      });
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ bindRoot(document); });
  } else {
    bindRoot(document);
  }
})();
</script>
<?php
}
