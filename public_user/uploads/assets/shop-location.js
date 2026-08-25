(function () {
  var state = window.__shopLocState || {
    label: '', city: '', state: '', country: '', postal: '', miles: 10, lat: null, lng: null
  };
  var modal = document.getElementById('shopLocModal');
  if (!modal) return;

  var map = null;
  var marker = null;
  var circle = null;
  var mapReady = false;

  function $(id) { return document.getElementById(id); }

  function setMsg(text, isError) {
    var el = $('shopLocSearchMsg');
    if (!el) return;
    if (!text) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.textContent = text;
    el.classList.toggle('is-error', !!isError);
  }

  function syncFields() {
    var labelEl = $('shopLocFieldLabel');
    var searchEl = $('shopLocSearchInput');
    var radiusEl = $('shopLocRadius');
    var summary = state.label || [state.city, state.state].filter(Boolean).join(', ') || 'Choose a place';
    if (labelEl) labelEl.textContent = summary;
    if (searchEl && document.activeElement !== searchEl) searchEl.value = state.label || summary;
    if (radiusEl) radiusEl.value = String(state.miles || 10);
    var openBtn = $('shopNavLocationOpen');
    if (openBtn && state.label) {
      openBtn.textContent = summary + ' · Within ' + (state.miles || 10) + ' mi';
    }
  }

  function ensureMap() {
    if (mapReady || typeof L === 'undefined') return;
    var el = $('shopLocMap');
    if (!el) return;
    map = L.map(el, { zoomControl: true, attributionControl: true });
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; OpenStreetMap &copy; CARTO',
      maxZoom: 18
    }).addTo(map);
    mapReady = true;
    updateMap();
    setTimeout(function () { if (map) map.invalidateSize(); }, 80);
  }

  function updateMap() {
    if (!mapReady || !map) return;
    var lat = state.lat != null ? Number(state.lat) : 32.9126;
    var lng = state.lng != null ? Number(state.lng) : -96.6389;
    var miles = Number(state.miles || 10);
    var meters = miles * 1609.34;
    if (!marker) {
      marker = L.marker([lat, lng]).addTo(map);
    } else {
      marker.setLatLng([lat, lng]);
    }
    if (!circle) {
      circle = L.circle([lat, lng], {
        radius: meters,
        color: '#60a5fa',
        fillColor: '#93c5fd',
        fillOpacity: 0.22,
        weight: 2
      }).addTo(map);
    } else {
      circle.setLatLng([lat, lng]);
      circle.setRadius(meters);
    }
    map.setView([lat, lng], miles <= 10 ? 11 : (miles <= 25 ? 10 : 9));
  }

  function openModal() {
    modal.hidden = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    syncFields();
    ensureMap();
    setTimeout(function () {
      if (map) map.invalidateSize();
      updateMap();
    }, 60);
    var input = $('shopLocSearchInput');
    if (input) input.focus();
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    setMsg('');
  }

  function applyPlace(place) {
    if (!place) return;
    state.label = place.label || [place.city, place.state].filter(Boolean).join(', ');
    state.city = place.city || '';
    state.state = place.state || '';
    state.country = place.country || '';
    state.postal = place.postal || '';
    if (place.lat != null) state.lat = Number(place.lat);
    if (place.lng != null) state.lng = Number(place.lng);
    syncFields();
    updateMap();
  }

  function searchPlace() {
    var q = (($('shopLocSearchInput') || {}).value || '').trim();
    if (q.length < 2) {
      setMsg('Enter a city, neighborhood, or ZIP.', true);
      return;
    }
    setMsg('Searching…');
    fetch('ajax/shop_location_search.php?action=search&q=' + encodeURIComponent(q), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setMsg((data && data.error) || 'No places found.', true);
          return;
        }
        setMsg('');
        applyPlace(data.place);
      })
      .catch(function () {
        setMsg('Could not search right now. Try again.', true);
      });
  }

  function applyLocation() {
    var radiusEl = $('shopLocRadius');
    state.miles = radiusEl ? parseInt(radiusEl.value, 10) || 10 : (state.miles || 10);
    if (!state.label && !state.city) {
      setMsg('Search and choose a location first.', true);
      return;
    }
    var applyBtn = $('shopLocApply');
    if (applyBtn) applyBtn.disabled = true;
    setMsg('Applying…');
    var body = new URLSearchParams();
    body.set('action', 'apply');
    body.set('label', state.label || '');
    body.set('city', state.city || '');
    body.set('state', state.state || '');
    body.set('country', state.country || '');
    body.set('postal', state.postal || '');
    body.set('miles', String(state.miles || 10));
    if (state.lat != null) body.set('lat', String(state.lat));
    if (state.lng != null) body.set('lng', String(state.lng));

    fetch('ajax/shop_location_search.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setMsg((data && data.error) || 'Could not save location.', true);
          if (applyBtn) applyBtn.disabled = false;
          return;
        }
        closeModal();
        window.location.href = data.redirect || 'shop.php';
      })
      .catch(function () {
        setMsg('Could not apply location.', true);
        if (applyBtn) applyBtn.disabled = false;
      });
  }

  var openBtn = $('shopNavLocationOpen');
  if (openBtn) openBtn.addEventListener('click', function (e) {
    e.preventDefault();
    openModal();
  });
  var closeBtn = $('shopLocModalClose');
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  var searchBtn = $('shopLocSearchBtn');
  if (searchBtn) searchBtn.addEventListener('click', searchPlace);
  var searchInput = $('shopLocSearchInput');
  if (searchInput) {
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        searchPlace();
      }
    });
  }
  var radiusEl = $('shopLocRadius');
  if (radiusEl) {
    radiusEl.addEventListener('change', function () {
      state.miles = parseInt(radiusEl.value, 10) || 10;
      updateMap();
    });
  }
  var applyBtn = $('shopLocApply');
  if (applyBtn) applyBtn.addEventListener('click', applyLocation);

  syncFields();
})();
