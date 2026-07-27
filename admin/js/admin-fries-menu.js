(function () {
  'use strict';
  if (window.__adminFriesMenuBound) return;
  window.__adminFriesMenuBound = true;

  function closeAll(except) {
    document.querySelectorAll('.fries-menu.is-open').forEach(function (menu) {
      if (except && menu === except) return;
      menu.classList.remove('is-open');
      var drop = menu.querySelector('.fries-dropdown');
      if (!drop) return;
      drop.classList.remove('is-fixed-open');
      drop.style.top = '';
      drop.style.left = '';
      drop.style.right = '';
    });
  }

  function placeDropdown(toggle, drop) {
    var rect = toggle.getBoundingClientRect();
    var width = Math.max(drop.offsetWidth || 168, 168);
    var left = Math.min(
      Math.max(8, rect.right - width),
      window.innerWidth - width - 8
    );
    var top = rect.bottom + 6;
    var approxHeight = drop.offsetHeight || 140;
    if (top + approxHeight > window.innerHeight - 8) {
      top = Math.max(8, rect.top - approxHeight - 6);
    }
    drop.style.top = top + 'px';
    drop.style.left = left + 'px';
    drop.style.right = 'auto';
    drop.classList.add('is-fixed-open');
  }

  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.fries-toggle');
    if (toggle) {
      e.preventDefault();
      e.stopPropagation();
      if (toggle.disabled) return;

      var menu = toggle.closest('.fries-menu');
      if (!menu) return;
      var willOpen = !menu.classList.contains('is-open');
      closeAll();
      if (!willOpen) return;

      menu.classList.add('is-open');
      var drop = menu.querySelector('.fries-dropdown');
      if (drop) placeDropdown(toggle, drop);
      return;
    }

    if (e.target.closest('.fries-item')) {
      // Let the action run, then close the menu.
      setTimeout(function () { closeAll(); }, 0);
      return;
    }

    if (!e.target.closest('.fries-menu') && !e.target.closest('.fries-dropdown')) {
      closeAll();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });

  ['scroll', 'resize'].forEach(function (evt) {
    window.addEventListener(evt, function () { closeAll(); }, true);
  });
})();
