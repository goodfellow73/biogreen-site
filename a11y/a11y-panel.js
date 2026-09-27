/*! ==========================================================================
 *  a11y-panel — a self-contained accessibility panel.
 *
 *  Drop into any site with one tag, nothing else:
 *      <script src="a11y/a11y-panel.js" defer></script>
 *
 *  Options go on the tag:
 *      data-position="left|right"   which side the launcher sits on (left)
 *      data-accent="#0B8F5A"        button + active-state colour
 *      data-lang="he|en"            panel language (he)
 *      data-statement="/accessibility/"   link to the accessibility statement
 *      data-bottom="24"             px from the bottom, to clear a sticky bar
 *
 *  Portability notes:
 *   - No dependencies and no build step.
 *   - The UI lives in a shadow root, so the host page cannot restyle it and
 *     its own CSS cannot leak out. That is what makes it safe to paste into a
 *     site whose CSS you do not control.
 *   - Everything it does to the page is either a data-attribute on <html>
 *     (driven by one injected stylesheet) or an inline font-size, so removing
 *     the script leaves no trace.
 *
 *  It does NOT make a site conform to WCAG / ת"י 5568 on its own. Real
 *  conformance lives in the page's own markup, contrast and keyboard order.
 *  ====================================================================== */

(function () {
  'use strict';

  if (window.__a11yPanel) return;          // never initialise twice
  window.__a11yPanel = true;

  var TAG = document.currentScript;
  var CFG = {
    position: (TAG && TAG.dataset.position) || 'left',
    accent: (TAG && TAG.dataset.accent) || '#0B8F5A',
    lang: (TAG && TAG.dataset.lang) || 'he',
    statement: (TAG && TAG.dataset.statement) || '',
    bottom: parseInt((TAG && TAG.dataset.bottom) || '24', 10)
  };

  var STORE = 'a11y-prefs-v1';

  var T = {
    he: {
      dir: 'rtl', open: 'אפשרויות נגישות', title: 'נגישות', close: 'סגירה',
      reset: 'איפוס הגדרות', statement: 'הצהרת נגישות',
      groups: { text: 'טקסט', colour: 'צבע וניגודיות', nav: 'ניווט וקריאה' },
      fontSize: 'גודל טקסט', lineHeight: 'גובה שורה', letterSpacing: 'ריווח אותיות',
      readableFont: 'גופן קריא', contrast: 'ניגודיות גבוהה', grayscale: 'גווני אפור',
      links: 'הדגשת קישורים', mask: 'מסכת קריאה', cursor: 'סמן גדול',
      focus: 'הדגשת פוקוס', motion: 'עצירת אנימציות',
      off: 'כבוי', level: 'רמה'
    },
    en: {
      dir: 'ltr', open: 'Accessibility options', title: 'Accessibility', close: 'Close',
      reset: 'Reset settings', statement: 'Accessibility statement',
      groups: { text: 'Text', colour: 'Colour & contrast', nav: 'Navigation & reading' },
      fontSize: 'Text size', lineHeight: 'Line height', letterSpacing: 'Letter spacing',
      readableFont: 'Readable font', contrast: 'High contrast', grayscale: 'Grayscale',
      links: 'Highlight links', mask: 'Reading mask', cursor: 'Large cursor',
      focus: 'Focus outline', motion: 'Stop animations',
      off: 'Off', level: 'Level'
    }
  }[CFG.lang] || null;

  var L = T || {
    dir: 'ltr', open: 'Accessibility', title: 'Accessibility', close: 'Close',
    reset: 'Reset', statement: '', groups: {}, off: 'Off', level: 'Level'
  };

  /* ---------------------------------------------------------------- state */

  var DEFAULTS = {
    fontSize: 0, lineHeight: 0, letterSpacing: 0,   // step indexes
    readableFont: false, contrast: false, grayscale: false, links: false,
    mask: false, cursor: false, focus: false, motion: false
  };

  var FONT_STEPS = [1, 1.15, 1.3, 1.5];
  var LINE_STEPS = [0, 1.7, 2, 2.4];
  var SPACE_STEPS = [0, 0.05, 0.1, 0.16];

  var prefs = load();

  function load() {
    try {
      var raw = localStorage.getItem(STORE);
      if (!raw) return Object.assign({}, DEFAULTS);
      return Object.assign({}, DEFAULTS, JSON.parse(raw));
    } catch (e) { return Object.assign({}, DEFAULTS); }
  }
  function save() {
    try { localStorage.setItem(STORE, JSON.stringify(prefs)); } catch (e) {}
  }

  /* ------------------------------------------------- effects on the page */

  var root = document.documentElement;

  // One stylesheet drives every toggle. `html[data-a11y-*]` keeps the page's
  // own CSS untouched, so nothing has to be undone by hand.
  var PAGE_CSS = [
    'html[data-a11y-motion] *, html[data-a11y-motion] *::before, html[data-a11y-motion] *::after {',
    '  animation-duration:.001ms!important; animation-iteration-count:1!important;',
    '  transition-duration:.001ms!important; scroll-behavior:auto!important; }',

    'html[data-a11y-grayscale] body { filter:grayscale(1)!important; }',

    'html[data-a11y-contrast] body { background:#000!important; }',
    'html[data-a11y-contrast] body *:not([data-a11y-root]):not([data-a11y-root] *) {',
    '  background-color:transparent!important; color:#fff!important;',
    '  border-color:#fff!important; box-shadow:none!important; text-shadow:none!important; }',
    'html[data-a11y-contrast] body a:not([data-a11y-root] *),',
    'html[data-a11y-contrast] body button:not([data-a11y-root] *) { color:#ffdd33!important; }',
    'html[data-a11y-contrast] body img:not([data-a11y-root] *) { filter:grayscale(1) contrast(1.2); }',

    'html[data-a11y-links] body a:not([data-a11y-root] *) {',
    '  text-decoration:underline!important; text-underline-offset:3px!important;',
    '  outline:2px solid #ffdd33!important; outline-offset:2px!important; }',

    'html[data-a11y-focus] body :focus-visible:not([data-a11y-root] *) {',
    '  outline:4px solid #ffdd33!important; outline-offset:3px!important;',
    '  box-shadow:0 0 0 7px rgba(0,0,0,.65)!important; }',

    'html[data-a11y-readable] body *:not([data-a11y-root]):not([data-a11y-root] *):not(svg):not(svg *) {',
    '  font-family:Arial,"Helvetica Neue",Helvetica,sans-serif!important;',
    '  font-variant-ligatures:none!important; }',

    'html[data-a11y-cursor] body, html[data-a11y-cursor] body * {',
    "  cursor:url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"48\" height=\"48\" viewBox=\"0 0 32 32\"><path d=\"M6 2l20 13-9 2 5 10-4 2-5-10-7 6z\" fill=\"%23000\" stroke=\"%23fff\" stroke-width=\"2\"/></svg>') 4 2, auto!important; }",

    'html[data-a11y-lh] body *:not([data-a11y-root]):not([data-a11y-root] *):not(svg):not(svg *) {',
    '  line-height:var(--a11y-lh)!important; }',
    'html[data-a11y-ls] body *:not([data-a11y-root]):not([data-a11y-root] *):not(svg):not(svg *) {',
    '  letter-spacing:var(--a11y-ls)!important; }'
  ].join('\n');

  var pageStyle = document.createElement('style');
  pageStyle.setAttribute('data-a11y-style', '');
  pageStyle.textContent = PAGE_CSS;
  document.head.appendChild(pageStyle);

  /* Text scaling.
     Font sizes cannot be scaled with a single CSS rule on an arbitrary site:
     most sites size in px, and a multiplier applied to every element compounds
     through inheritance. So each element's ORIGINAL computed size is measured
     once and cached, and every later change is computed from that original. */
  function scaleText(factor) {
    var els = document.body.querySelectorAll('*');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.hasAttribute('data-a11y-root') || el.closest('[data-a11y-root]')) continue;
      if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE') continue;
      if (el.namespaceURI && el.namespaceURI.indexOf('svg') > -1) continue;

      var base = el.getAttribute('data-a11y-fs');
      if (base === null) {
        base = parseFloat(getComputedStyle(el).fontSize);
        if (!base) continue;
        el.setAttribute('data-a11y-fs', base);
      }
      if (factor === 1) {
        el.style.fontSize = '';
        el.removeAttribute('data-a11y-fs');
      } else {
        el.style.fontSize = (parseFloat(base) * factor).toFixed(2) + 'px';
      }
    }
  }

  function apply() {
    var f = FONT_STEPS[prefs.fontSize] || 1;
    scaleText(f);

    var lh = LINE_STEPS[prefs.lineHeight] || 0;
    if (lh) { root.style.setProperty('--a11y-lh', String(lh)); root.setAttribute('data-a11y-lh', ''); }
    else { root.removeAttribute('data-a11y-lh'); }

    var ls = SPACE_STEPS[prefs.letterSpacing] || 0;
    if (ls) { root.style.setProperty('--a11y-ls', ls + 'em'); root.setAttribute('data-a11y-ls', ''); }
    else { root.removeAttribute('data-a11y-ls'); }

    [['readableFont', 'readable'], ['contrast', 'contrast'], ['grayscale', 'grayscale'],
     ['links', 'links'], ['cursor', 'cursor'], ['focus', 'focus'], ['motion', 'motion']
    ].forEach(function (pair) {
      if (prefs[pair[0]]) root.setAttribute('data-a11y-' + pair[1], '');
      else root.removeAttribute('data-a11y-' + pair[1]);
    });

    mask.style.display = prefs.mask ? 'block' : 'none';
    save();
    paint();
  }

  /* ------------------------------------------------------------- the UI */

  var host = document.createElement('div');
  host.setAttribute('data-a11y-root', '');
  document.body.appendChild(host);
  var sr = host.attachShadow({ mode: 'open' });

  var side = CFG.position === 'right' ? 'right' : 'left';

  var UI_CSS =
    ':host{all:initial;}' +
    '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;}' +

    '.launcher{position:fixed;' + side + ':18px;bottom:' + CFG.bottom + 'px;z-index:2147483000;' +
    'width:54px;height:54px;border-radius:50%;border:0;cursor:pointer;' +
    'background:' + CFG.accent + ';color:#fff;display:flex;align-items:center;justify-content:center;' +
    'box-shadow:0 4px 14px rgba(0,0,0,.28);transition:transform .15s ease;}' +
    '.launcher:hover{transform:scale(1.06);}' +
    '.launcher:focus-visible{outline:3px solid #ffdd33;outline-offset:3px;}' +
    '.launcher svg{width:30px;height:30px;}' +

    '.panel{position:fixed;' + side + ':18px;bottom:' + (CFG.bottom + 64) + 'px;z-index:2147483000;' +
    'width:340px;max-width:calc(100vw - 36px);max-height:min(76vh,620px);overflow:auto;' +
    'background:#fff;color:#16211c;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.3);' +
    'direction:' + L.dir + ';text-align:' + (L.dir === 'rtl' ? 'right' : 'left') + ';}' +
    '.panel[hidden]{display:none;}' +

    '.head{display:flex;align-items:center;justify-content:space-between;gap:10px;' +
    'padding:14px 16px;background:' + CFG.accent + ';color:#fff;position:sticky;top:0;}' +
    '.head h2{margin:0;font-size:17px;font-weight:700;}' +
    '.x{background:transparent;border:0;color:#fff;cursor:pointer;padding:6px;border-radius:8px;line-height:0;}' +
    '.x:hover{background:rgba(255,255,255,.18);}' +
    '.x:focus-visible{outline:3px solid #ffdd33;outline-offset:2px;}' +

    '.body{padding:14px 16px 16px;}' +
    'fieldset{border:0;margin:0 0 16px;padding:0;}' +
    'legend{font-size:12px;letter-spacing:.06em;color:#5b6b64;padding:0 0 8px;font-weight:700;}' +
    '.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}' +

    '.item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;' +
    'padding:12px 8px;min-height:74px;background:#f2f5f3;border:2px solid transparent;' +
    'border-radius:12px;cursor:pointer;font-size:13px;font-weight:600;color:#16211c;text-align:center;}' +
    '.item:hover{background:#e8ede9;}' +
    '.item:focus-visible{outline:3px solid ' + CFG.accent + ';outline-offset:2px;}' +
    '.item[aria-pressed="true"],.item[data-on="1"]{border-color:' + CFG.accent + ';background:#fff;}' +
    '.item svg{width:22px;height:22px;stroke:' + CFG.accent + ';fill:none;stroke-width:1.8;' +
    'stroke-linecap:round;stroke-linejoin:round;}' +

    '.dots{display:flex;gap:4px;margin-top:2px;}' +
    '.dots i{width:14px;height:5px;border-radius:3px;background:#cfd8d3;display:block;}' +
    '.dots i.on{background:' + CFG.accent + ';}' +

    '.foot{display:flex;align-items:center;justify-content:space-between;gap:10px;' +
    'padding-top:12px;border-top:1px solid #e4ebe4;}' +
    '.reset{background:#f2f5f3;border:0;border-radius:999px;padding:10px 16px;cursor:pointer;' +
    'font-size:13px;font-weight:700;color:#16211c;}' +
    '.reset:hover{background:#e3e9e4;}' +
    '.reset:focus-visible{outline:3px solid ' + CFG.accent + ';outline-offset:2px;}' +
    '.stmt{font-size:12px;color:#5b6b64;text-decoration:underline;}' +

    '.mask{position:fixed;inset:0;z-index:2147482000;pointer-events:none;display:none;}' +
    '.mask i{position:absolute;left:0;right:0;background:rgba(0,0,0,.62);display:block;}' +

    '@media (max-width:480px){.panel{width:calc(100vw - 24px);' + side + ':12px;' +
    'bottom:' + (CFG.bottom + 60) + 'px;}}';

  function ico(paths) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' + paths + '</svg>';
  }
  var ICONS = {
    a11y: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="4.5" r="2"/><path d="M4 8.5h16M12 10.5V21M12 14l-3.5 7M12 14l3.5 7"/></svg>',
    text: ico('<path d="M4 6h16M8 6v13M4 6V4h16v2"/>'),
    line: ico('<path d="M3 5h18M3 12h18M3 19h18M6 8l-2 2 2 2M6 16l-2 2 2 2"/>'),
    space: ico('<path d="M4 7v10M20 7v10M8 12h8M8 12l2-2M8 12l2 2M16 12l-2-2M16 12l-2 2"/>'),
    font: ico('<path d="M4 18L9 6l5 12M6 14h6M17 18v-6a2 2 0 1 1 4 0v6M21 15h-4"/>'),
    contrast: ico('<circle cx="12" cy="12" r="9"/><path d="M12 3v18a9 9 0 0 0 0-18z" fill="currentColor" stroke="none"/>'),
    gray: ico('<path d="M12 3s6 6 6 10a6 6 0 0 1-12 0c0-4 6-10 6-10z"/>'),
    link: ico('<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>'),
    mask: ico('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 14h18"/>'),
    cursor: ico('<path d="M5 3l14 9-6 1.5L16 21l-3 1.2L10 15l-5 4z"/>'),
    focus: ico('<circle cx="12" cy="12" r="3"/><path d="M4 8V5a1 1 0 0 1 1-1h3M20 8V5a1 1 0 0 0-1-1h-3M4 16v3a1 1 0 0 0 1 1h3M20 16v3a1 1 0 0 1-1 1h-3"/>'),
    motion: ico('<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z" fill="currentColor" stroke="none"/>')
  };

  function dots(n, total) {
    var out = '<span class="dots">';
    for (var i = 1; i < total; i++) out += '<i class="' + (i <= n ? 'on' : '') + '"></i>';
    return out + '</span>';
  }

  function stepItem(key, label, icon, total) {
    return '<button type="button" class="item" data-step="' + key + '" data-total="' + total + '">' +
      icon + '<span>' + label + '</span>' + dots(prefs[key], total) + '</button>';
  }
  function toggleItem(key, label, icon) {
    return '<button type="button" class="item" data-toggle="' + key + '" aria-pressed="' +
      (prefs[key] ? 'true' : 'false') + '">' + icon + '<span>' + label + '</span></button>';
  }

  sr.innerHTML =
    '<style>' + UI_CSS + '</style>' +
    '<button class="launcher" type="button" aria-expanded="false" aria-label="' + L.open + '">' +
      ICONS.a11y + '</button>' +
    '<div class="mask" aria-hidden="true"><i data-top></i><i data-bottom></i></div>' +
    '<div class="panel" role="dialog" aria-modal="false" aria-label="' + L.title + '" hidden>' +
      '<div class="head"><h2>' + L.title + '</h2>' +
        '<button class="x" type="button" aria-label="' + L.close + '">' +
        '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>' +
        '</button></div>' +
      '<div class="body">' +
        '<fieldset><legend>' + L.groups.text + '</legend><div class="grid">' +
          stepItem('fontSize', L.fontSize, ICONS.text, FONT_STEPS.length) +
          stepItem('lineHeight', L.lineHeight, ICONS.line, LINE_STEPS.length) +
          stepItem('letterSpacing', L.letterSpacing, ICONS.space, SPACE_STEPS.length) +
          toggleItem('readableFont', L.readableFont, ICONS.font) +
        '</div></fieldset>' +
        '<fieldset><legend>' + L.groups.colour + '</legend><div class="grid">' +
          toggleItem('contrast', L.contrast, ICONS.contrast) +
          toggleItem('grayscale', L.grayscale, ICONS.gray) +
          toggleItem('links', L.links, ICONS.link) +
          toggleItem('motion', L.motion, ICONS.motion) +
        '</div></fieldset>' +
        '<fieldset><legend>' + L.groups.nav + '</legend><div class="grid">' +
          toggleItem('mask', L.mask, ICONS.mask) +
          toggleItem('cursor', L.cursor, ICONS.cursor) +
          toggleItem('focus', L.focus, ICONS.focus) +
        '</div></fieldset>' +
        '<div class="foot">' +
          '<button class="reset" type="button">' + L.reset + '</button>' +
          (CFG.statement ? '<a class="stmt" href="' + CFG.statement + '">' + L.statement + '</a>' : '') +
        '</div>' +
      '</div>' +
    '</div>';

  var launcher = sr.querySelector('.launcher');
  var panel = sr.querySelector('.panel');
  var mask = sr.querySelector('.mask');
  var maskTop = sr.querySelector('[data-top]');
  var maskBottom = sr.querySelector('[data-bottom]');

  function paint() {
    sr.querySelectorAll('[data-step]').forEach(function (b) {
      var k = b.getAttribute('data-step');
      var total = +b.getAttribute('data-total');
      b.querySelector('.dots').outerHTML = dots(prefs[k], total);
      b.setAttribute('data-on', prefs[k] ? '1' : '0');
      b.setAttribute('aria-label', b.querySelector('span').textContent +
        ' — ' + (prefs[k] ? L.level + ' ' + prefs[k] : L.off));
    });
    sr.querySelectorAll('[data-toggle]').forEach(function (b) {
      b.setAttribute('aria-pressed', prefs[b.getAttribute('data-toggle')] ? 'true' : 'false');
    });
  }

  function open() {
    panel.hidden = false;
    launcher.setAttribute('aria-expanded', 'true');
    panel.querySelector('.x').focus();
  }
  function close() {
    panel.hidden = true;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus();
  }

  launcher.addEventListener('click', function () {
    panel.hidden ? open() : close();
  });
  sr.querySelector('.x').addEventListener('click', close);

  sr.addEventListener('click', function (e) {
    var step = e.target.closest && e.target.closest('[data-step]');
    if (step) {
      var k = step.getAttribute('data-step');
      prefs[k] = (prefs[k] + 1) % +step.getAttribute('data-total');
      apply();
      return;
    }
    var tog = e.target.closest && e.target.closest('[data-toggle]');
    if (tog) {
      var key = tog.getAttribute('data-toggle');
      prefs[key] = !prefs[key];
      apply();
    }
  });

  sr.querySelector('.reset').addEventListener('click', function () {
    prefs = Object.assign({}, DEFAULTS);
    apply();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) close();
  });

  // Reading mask follows the pointer; two shades leave a clear band between.
  var BAND = 130;
  document.addEventListener('mousemove', function (e) {
    if (!prefs.mask) return;
    var y = e.clientY;
    maskTop.style.top = '0px';
    maskTop.style.height = Math.max(0, y - BAND / 2) + 'px';
    maskBottom.style.top = (y + BAND / 2) + 'px';
    maskBottom.style.bottom = '0px';
  }, { passive: true });

  apply();

  window.A11yPanel = {
    open: open, close: close,
    get: function () { return Object.assign({}, prefs); },
    reset: function () { prefs = Object.assign({}, DEFAULTS); apply(); }
  };
})();
