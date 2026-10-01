/*!
 * Shamcey v2.0.0 (https://themepixels.me/shamcey)
 * Copyright 2017-2018 ThemePixels
 * Licensed under ThemeForest License
 */

 'use strict';

 $(document).ready(function(){

  // custom scrollbar style — skip org shell (uses native nested scroll for long sales nav)
  $('.sh-sideleft-menu').not('.org-sideleft-shell').perfectScrollbar();
  if ($.fn.perfectScrollbar) {
    $('.sh-sideleft-menu.org-sideleft-shell').each(function () {
      var $el = $(this);
      if ($el.hasClass('ps') || $el.data('perfect-scrollbar')) {
        try { $el.perfectScrollbar('destroy'); } catch (e) {}
      }
    });
  }

  // showing sub navigation to nav with sub nav.
  $('.with-sub.active + .nav-sub').slideDown();

  // showing sub menu while hiding others
  $('.with-sub').on('click', function(e) {
    e.preventDefault();
    var nextElem = $(this).next();
    if(!nextElem.is(':visible')) {
      $('.nav-sub').slideUp();
    }
    nextElem.slideToggle();
  });

  // hide left menu bar
  $('#navicon').on('click', function(e) {
    e.preventDefault();
    $('body').toggleClass('hide-left');
  });

  // push/hide left menu bar in mobile
  $('#naviconMobile').on('click', function(e) {
    e.preventDefault();
    $('body').toggleClass('show-left');
  });

});
