<script>
(function(){
  var roots = document.querySelectorAll('.about-people');
  if (!roots.length) return;

  function esc(s){
    return String(s || '').replace(/[&<>"']/g, function(ch){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]);
    });
  }

  function usernameOf(input){
    return String(input && input.value || '').replace(/^@/, '').trim();
  }

  function setMsg(box, text, ok){
    var el = box.querySelector('[data-people-msg]');
    if (!el) return;
    el.textContent = text || '';
    el.classList.toggle('is-ok', !!ok);
  }

  function updateCard(box, data){
    var card = box.closest('.about-card') || box.closest('.gear-edit-field') || box.closest('.acc-field') || box.closest('.field');
    if (!card || !data) return;
    var val = String(data.display || '');
    card.setAttribute('data-pin-value', val);
    var view = card.querySelector('[data-people-value]');
    if (view) {
      view.innerHTML = data.html || esc(val);
      view.classList.toggle('empty', val === '');
    }
    var hidden = card.querySelector('input[name="relationship_status"], input[name="family_details"]');
    if (hidden) hidden.value = val;
    var pinKey = card.getAttribute('data-pin-key') || '';
    var pin = pinKey ? document.querySelector('#igAboutPins [data-pin-key="' + pinKey + '"] .ig-pin-value') : null;
    if (pin) {
      if (data.html) pin.innerHTML = data.html;
      else pin.textContent = val;
    }
  }

  function renderFamilyChips(box, rows){
    var list = box.querySelector('[data-people-chips]');
    if (!list) return;
    list.innerHTML = (rows || []).map(function(row){
      var id = Number(row.id || 0);
      return '<li data-tag-id="' + id + '"><span>' + esc(row.role_label || '') + ' · ' +
        (row.profile_url
          ? ('<a class="about-link people-tag-link" href="' + esc(row.profile_url) + '">' + esc(row.name || '') + '</a>')
          : esc(row.name || '')) +
        '</span><button type="button" class="about-people-remove" data-people-remove="' + id + '" aria-label="Remove">&times;</button></li>';
    }).join('');
  }

  function post(action, payload){
    var body = new FormData();
    body.append('action', action);
    Object.keys(payload || {}).forEach(function(k){
      if (payload[k] !== undefined && payload[k] !== null) body.append(k, String(payload[k]));
    });
    return fetch('profile.php?ajax=about_people', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(res){ return res.json(); });
  }

  function applyPick(box, u){
    if (!u) return;
    var mention = box.querySelector('[data-people-mention]');
    var uid = box.querySelector('[data-people-uid]');
    var picked = box.querySelector('[data-people-picked]');
    if (uid) uid.value = String(u.id || '');
    if (mention) mention.value = '@' + String(u.username || '');
    if (picked) picked.textContent = u.name || u.username || '';
    saveBox(box, true);
  }

  function saveBox(box, fromPick){
    var kind = box.getAttribute('data-people-kind') || '';
    var mention = box.querySelector('[data-people-mention]');
    var uid = box.querySelector('[data-people-uid]');
    var picked = box.querySelector('[data-people-picked]');
    var role = box.querySelector('[data-people-role]');
    var save = box.querySelector('[data-people-save]');
    var roleVal = role ? role.value : '';
    if (kind === 'relationship' && roleVal === 'single' && fromPick && usernameOf(mention)) {
      roleVal = 'in_a_relationship';
      if (role) role.value = roleVal;
    }
    if (save) save.disabled = true;
    setMsg(box, fromPick ? 'Saving…' : '', true);
    var action = kind === 'family' ? 'add_family' : 'save_relationship';
    post(action, {
      role: roleVal,
      user_id: uid ? uid.value : '',
      username: usernameOf(mention)
    }).then(function(data){
      if (!data || !data.ok) throw new Error((data && data.error) || 'Could not save');
      updateCard(box, data);
      if (kind === 'family') {
        renderFamilyChips(box, data.family || []);
        if (mention) mention.value = '';
        if (uid) uid.value = '';
        if (picked) picked.textContent = '';
      }
      setMsg(box, 'Saved', true);
    }).catch(function(err){
      setMsg(box, err.message || 'Could not save', false);
    }).finally(function(){
      if (save) save.disabled = false;
    });
  }

  function bindPicker(box){
    var mention = box.querySelector('[data-people-mention]');
    var uid = box.querySelector('[data-people-uid]');
    var picked = box.querySelector('[data-people-picked]');
    if (!mention) return;

    var menu = document.createElement('div');
    menu.className = 'about-people-ac';
    menu.hidden = true;
    mention.parentNode.appendChild(menu);

    var timer = null;
    var seq = 0;

    function hideMenu(){
      menu.hidden = true;
      menu.innerHTML = '';
    }

    function showUsers(list){
      if (!list || !list.length) {
        menu.innerHTML = '<div class="about-people-ac-empty">No people found</div>';
        menu.hidden = false;
        return;
      }
      menu.innerHTML = list.map(function(u, i){
        return '<button type="button" class="about-people-ac-item" data-i="' + i + '">' +
          '<span class="about-people-ac-user">@' + esc(u.username || '') + '</span>' +
          (u.name ? '<span class="about-people-ac-name">' + esc(u.name) + '</span>' : '') +
          '</button>';
      }).join('');
      menu.hidden = false;
      Array.prototype.forEach.call(menu.querySelectorAll('.about-people-ac-item'), function(btn){
        btn.addEventListener('mousedown', function(ev){
          ev.preventDefault();
          var i = parseInt(btn.getAttribute('data-i') || '-1', 10);
          if (i < 0 || !list[i]) return;
          hideMenu();
          applyPick(box, list[i]);
        });
      });
    }

    function search(q){
      var n = ++seq;
      fetch('ajax/mention_search.php?q=' + encodeURIComponent(q) + '&limit=8', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function(r){ return r.json(); }).then(function(data){
        if (n !== seq) return;
        showUsers((data && data.users) || []);
      }).catch(function(){
        if (n !== seq) return;
        hideMenu();
      });
    }

    mention.addEventListener('input', function(){
      var raw = String(mention.value || '');
      if (uid && !/^@?[A-Za-z0-9_]*$/.test(raw.trim())) uid.value = '';
      if (picked && !raw.trim()) picked.textContent = '';
      var q = raw.replace(/^@/, '').trim();
      clearTimeout(timer);
      if (raw.indexOf('@') === -1 && q.length < 1) {
        hideMenu();
        return;
      }
      timer = setTimeout(function(){ search(q); }, 120);
    });

    mention.addEventListener('focus', function(){
      var q = usernameOf(mention);
      if (String(mention.value || '').indexOf('@') !== -1 || q.length) search(q);
    });

    mention.addEventListener('blur', function(){ setTimeout(hideMenu, 180); });

    mention.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        var first = menu.querySelector('.about-people-ac-item');
        if (first && !menu.hidden) {
          ev.preventDefault();
          first.dispatchEvent(new Event('mousedown'));
        }
      } else if (ev.key === 'Escape') {
        hideMenu();
      }
    });

    if (window.MSBMentionAC && typeof window.MSBMentionAC.bind === 'function') {
      window.MSBMentionAC.bind(mention, function(u){ applyPick(box, u); });
    }
  }

  Array.prototype.forEach.call(roots, function(box){
    var kind = box.getAttribute('data-people-kind') || '';
    var mention = box.querySelector('[data-people-mention]');
    var uid = box.querySelector('[data-people-uid]');
    var picked = box.querySelector('[data-people-picked]');
    var role = box.querySelector('[data-people-role]');
    bindPicker(box);

    box.addEventListener('click', function(e){
      var rm = e.target.closest('[data-people-remove]');
      if (!rm) return;
      e.preventDefault();
      var tagId = rm.getAttribute('data-people-remove') || '';
      rm.disabled = true;
      post('remove', { tag_id: tagId }).then(function(data){
        if (!data || !data.ok) throw new Error((data && data.error) || 'Could not remove');
        renderFamilyChips(box, data.family || []);
        if (data.kind === 'relationship') {
          if (role) role.value = 'single';
          if (mention) mention.value = '';
          if (uid) uid.value = '';
          if (picked) picked.textContent = '';
        }
        updateCard(box, data);
        setMsg(box, 'Saved', true);
      }).catch(function(err){
        setMsg(box, err.message || 'Could not remove', false);
      }).finally(function(){ rm.disabled = false; });
    });

    var save = box.querySelector('[data-people-save]');
    if (!save) return;
    save.addEventListener('click', function(){ saveBox(box, false); });
  });
})();
</script>
