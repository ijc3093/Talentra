(function (window) {
  'use strict';

  var IPAD_VIEWPORT_MAP = {
    '744x1133': 'iPad mini (8.3")',
    '768x1024': 'iPad (9.7" / 10.2")',
    '810x1080': 'iPad (10.2")',
    '820x1180': 'iPad Air / iPad (10.9")',
    '834x1112': 'iPad Pro 10.5"',
    '834x1194': 'iPad Air 11" / iPad Pro 11"',
    '1024x1366': 'iPad Pro 12.9" / iPad Air 13"',
    '1032x1376': 'iPad Pro 13"'
  };

  var IPHONE_VIEWPORT_MAP = {
    '320x568': 'iPhone SE (1st Gen)',
    '375x667': 'iPhone SE (2nd & 3rd Gen) / iPhone 6 / 7 / 8',
    '360x780': 'iPhone 12 Mini / 13 Mini',
    '375x812': 'iPhone X / XS / 11 Pro',
    '390x844': 'iPhone 12 / 13 / 14 / 15 / 16 / 17 / 16e',
    '393x852': 'iPhone 15 Pro / 16 Pro / 17 Pro',
    '402x874': 'iPhone 16 Pro / 17 Pro',
    '414x736': 'iPhone 6 / 7 / 8 Plus',
    '414x896': 'iPhone XR / 11 / XS Max',
    '428x926': 'iPhone 12 / 13 / 14 Pro Max',
    '430x932': 'iPhone 14 Plus / 15 Plus / 16 Plus / 15 Pro Max / 16 Pro Max',
    '440x956': 'iPhone 16 Pro Max / 17 Pro Max',
    '420x912': 'iPhone 17 Air'
  };

  var IPHONE_LABEL_VIEWPORT_RULES = [
    ['se (1st', '320x568'],
    ['17 pro max', '440x956'],
    ['17 air', '420x912'],
    ['17 pro', '393x852'],
    ['17', '390x844'],
    ['16 pro max', '440x956'],
    ['16 pro', '402x874'],
    ['16 plus', '430x932'],
    ['16e', '390x844'],
    ['16', '390x844'],
    ['15 pro max', '430x932'],
    ['15 pro', '393x852'],
    ['15 plus', '430x932'],
    ['15', '390x844'],
    ['14 pro max', '428x926'],
    ['14 plus', '430x932'],
    ['14 pro', '393x852'],
    ['14', '390x844'],
    ['13 pro max', '428x926'],
    ['13 mini', '360x780'],
    ['13 pro', '390x844'],
    ['13', '390x844'],
    ['12 pro max', '428x926'],
    ['12 mini', '360x780'],
    ['12 pro', '390x844'],
    ['12', '390x844'],
    ['11 pro max', '414x896'],
    ['11 pro', '375x812'],
    ['11', '414x896'],
    ['xr', '414x896'],
    ['xs max', '414x896'],
    ['xs', '375x812'],
    [' x', '375x812'],
    ['8 plus', '414x736'],
    ['7 plus', '414x736'],
    ['6s plus', '414x736'],
    ['6 plus', '414x736'],
    ['se (2nd', '375x667'],
    ['se (3rd', '375x667'],
    ['8', '375x667'],
    ['7', '375x667'],
    ['6s', '375x667'],
    ['6', '375x667']
  ];

  function parseViewport(viewport) {
    viewport = String(viewport || '').trim();
    var m = viewport.match(/^(\d{2,5})x(\d{2,5})/);
    if (!m) return null;
    return { w: Number(m[1]), h: Number(m[2]) };
  }

  function viewportKey(viewport) {
    var dims = parseViewport(viewport);
    if (!dims) return '';
    return Math.min(dims.w, dims.h) + 'x' + Math.max(dims.w, dims.h);
  }

  function viewportToStyle(viewport) {
    var dims = parseViewport(viewport);
    if (!dims) return '';
    return '--device-ar-w:' + dims.w + ';--device-ar-h:' + dims.h + ';';
  }

  function iphoneDefaultViewport(label) {
    label = String(label || '').trim().toLowerCase();
    if (!label || label.indexOf('iphone') === -1) return '390x844';
    for (var i = 0; i < IPHONE_LABEL_VIEWPORT_RULES.length; i++) {
      if (label.indexOf(IPHONE_LABEL_VIEWPORT_RULES[i][0]) !== -1) {
        return IPHONE_LABEL_VIEWPORT_RULES[i][1];
      }
    }
    return '390x844';
  }

  function labelFromViewport(viewport, fallback) {
    var key = viewportKey(viewport);
    if (!key) return fallback || '';
    if (IPHONE_VIEWPORT_MAP[key]) return IPHONE_VIEWPORT_MAP[key];
    if (IPAD_VIEWPORT_MAP[key]) return IPAD_VIEWPORT_MAP[key];
    return fallback || '';
  }

  function computeDisplayWidth(viewport, frame) {
    frame = String(frame || '').trim();
    var dims = parseViewport(viewport);
    if (!dims) return 0;
    var deviceShort = Math.min(dims.w, dims.h);
    var deviceLong = Math.max(dims.w, dims.h);
    var scale = frame === 'tablet-shot' ? (560 / 834) : (286 / 390);
    return Math.round(deviceShort * scale);
  }

  function defaultStyle(label, isPhoneShot, isTabletShot, viewport) {
    if (isPhoneShot) {
      var vp = viewportKey(viewport) || iphoneDefaultViewport(label);
      return viewportToStyle(vp);
    }
    if (isTabletShot) return '--device-ar-w:834;--device-ar-h:1194;';
    return '';
  }

  function isTabletLabel(label) {
    label = String(label || '').trim().toLowerCase();
    if (!label) return false;
    if (/iphone|android phone|pixel/i.test(label)) return false;
    return /ipad|android tablet|samsung tablet/i.test(label) || label === 'tablet';
  }

  function guess() {
    var ua = String((window.navigator && navigator.userAgent) || '').toLowerCase();
    var w = Math.max(window.innerWidth || 0, document.documentElement ? document.documentElement.clientWidth || 0 : 0);
    var h = Math.max(window.innerHeight || 0, document.documentElement ? document.documentElement.clientHeight || 0 : 0);
    var shortSide = Math.min(w, h);
    var longSide = Math.max(w, h);
    var viewport = shortSide > 0 && longSide > 0 ? (shortSide + 'x' + longSide) : '';

    function has(text) { return ua.indexOf(text) !== -1; }

    var label = '';
    if (has('iphone')) {
      var iphoneKey = viewportKey(viewport);
      label = IPHONE_VIEWPORT_MAP[iphoneKey] || 'iPhone';
    } else if (has('ipad')) {
      label = IPAD_VIEWPORT_MAP[viewportKey(viewport)] || 'iPad';
    } else if (has('surface duo')) {
      label = 'Surface Duo';
    } else if (has('surface')) {
      label = 'Surface';
    } else if (has('pixel')) {
      label = 'Pixel';
    } else if (has('samsung') || has('sm-')) {
      label = shortSide >= 768 ? 'Samsung Tablet' : 'Samsung Galaxy';
    } else if (has('android')) {
      label = shortSide >= 768 ? 'Android Tablet' : 'Android Phone';
    } else if (has('macintosh') || has('windows') || has('linux') || has('cros')) {
      label = shortSide >= 900 ? 'Desktop / Laptop' : 'Tablet / Laptop';
    } else if (shortSide > 0) {
      label = shortSide >= 900 ? 'Desktop / Laptop' : (shortSide >= 768 ? 'Tablet' : 'Phone');
    }

    return { label: label, viewport: viewport };
  }

  function cardMeta(label, viewport) {
    label = String(label || '').trim();
    viewport = String(viewport || '').trim();
    var dims = parseViewport(viewport);
    var style = dims ? viewportToStyle(viewport) : '';
    var phoneShot = false;
    var tabletShot = false;
    if (dims) {
      var short = Math.min(dims.w, dims.h);
      var long = Math.max(dims.w, dims.h);
      if (short <= 480 && (long / Math.max(short, 1)) >= 1.2) {
        phoneShot = true;
      } else if (short > 480 && short < 900) {
        tabletShot = true;
      }
    }
    if (!phoneShot && /iphone|android phone|pixel/i.test(label)) {
      phoneShot = true;
    }
    if (!phoneShot && !tabletShot && isTabletLabel(label)) {
      tabletShot = true;
    }
    var deviceFrame = '';
    if (dims) {
      var shortSide = Math.min(dims.w, dims.h);
      if (phoneShot) deviceFrame = 'phone-shot';
      else if (shortSide < 900) deviceFrame = 'tablet-shot';
      else deviceFrame = 'desktop-shot';
    } else if (phoneShot) {
      deviceFrame = 'phone-shot';
    } else if (tabletShot) {
      deviceFrame = 'tablet-shot';
    }
    if (phoneShot) tabletShot = false;
    if (!style) style = defaultStyle(label, phoneShot, tabletShot, viewport);
    return {
      phone_shot: phoneShot,
      tablet_shot: tabletShot,
      device_frame: deviceFrame,
      style: style,
      label: label,
      viewport: viewport
    };
  }

  function applyToForm(form) {
    if (!form) return;
    var profile = guess();
    var labelInput = form.querySelector('input[name="device_label"]');
    var viewportInput = form.querySelector('input[name="device_viewport"]');
    if (labelInput) labelInput.value = profile.label || '';
    if (viewportInput) viewportInput.value = profile.viewport || '';
  }

  function bindForm(form) {
    if (!form || form.dataset.deviceProfileBound === '1') return;
    form.dataset.deviceProfileBound = '1';
    applyToForm(form);
    window.addEventListener('resize', function () { applyToForm(form); });
    form.addEventListener('submit', function () { applyToForm(form); });
  }

  window.MSBDeviceProfile = {
    parseViewport: parseViewport,
    viewportKey: viewportKey,
    viewportToStyle: viewportToStyle,
    iphoneDefaultViewport: iphoneDefaultViewport,
    labelFromViewport: labelFromViewport,
    computeDisplayWidth: computeDisplayWidth,
    defaultStyle: defaultStyle,
    guess: guess,
    cardMeta: cardMeta,
    applyToForm: applyToForm,
    bindForm: bindForm,
    IPHONE_VIEWPORT_MAP: IPHONE_VIEWPORT_MAP,
    IPAD_VIEWPORT_MAP: IPAD_VIEWPORT_MAP
  };
})(window);
