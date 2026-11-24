/* ==========================================================================
   BioGreen — home page behaviour.
   No dependencies. Everything degrades gracefully without JS except the
   modal (the doorways then fall back to nothing — see NOTE at the bottom).
   ========================================================================== */

/* --------------------------------------------------------------------------
   CONTACT — the only place the phone / WhatsApp number lives.
   -------------------------------------------------------------------------- */
const CONTACT = {
  // The same line takes calls and WhatsApp.
  phone: '072-2503833',              // shown to the user (local format)
  phoneDial: '+972722503833',        // used for tel:
  // wa.me needs the international form with no +, no dashes and no leading 0,
  // so the local 072-2503833 becomes 972 72 2503833.
  whatsapp: '972722503833',          // 072-2503833
  // The opening line of the chat. {page} is the page's data-page-label on
  // <body>, so the team sees where the visitor was before they wrote a word.
  // A page without a label falls back to the generic line.
  whatsappMessage: 'שלום, הגעתי מ{page} באתר, אשמח לקבל פרטים.',
  whatsappMessageFallback: 'שלום, הגעתי מהאתר ואשמח לקבל פרטים.',
};

/* --------------------------------------------------------------------------
   FORMS — where submissions go.

   Every form posts the same JSON shape to one Make webhook, and says which
   form it is in `form_type`. A new form needs no change here: give its <form>
   a data-form-type and it is routed in Make on that value alone.
   -------------------------------------------------------------------------- */
const FORMS = {
  endpoint: 'https://hook.eu1.make.com/8hnp4v23st7q95r8rmaqwq1qq8arabgm',

  // Every submission carries every one of these, whichever form sent it; a
  // field the form does not have arrives empty. The four forms feed one CRM
  // table through Make, and one fixed shape means Make learns a single data
  // structure and every column is mapped once. Add a field here, not only to
  // a form, or Make will not expect it.
  fields: [
    'form_type', 'submitted_at', 'page_url',
    'intent', 'product', 'product_link',
    'name', 'company', 'phone', 'email',
    'quantity', 'notes', 'newsletter_consent',
  ],
};

(function () {
  'use strict';

  /* ---------- form submission ---------- */

  /* Build the payload for one form.

     Checkboxes are the awkward part: an unticked box is simply absent from
     FormData, so reading the entries alone would send newsletter_consent only
     when it is true and leave Make unable to tell "declined" from "that form
     has no such field". Every checkbox is therefore written explicitly as a
     boolean. Disabled fields drop out on their own, which is what we want —
     an answer to a question that was never shown is not an answer. */
  function payloadOf(form) {
    const data = { form_type: form.getAttribute('data-form-type') || 'unknown' };

    new FormData(form).forEach(function (value, key) {
      data[key] = typeof value === 'string' ? value.trim() : value;
    });

    form.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
      if (box.name) data[box.name] = box.checked;
    });

    // An optional choice left unanswered is absent from FormData too. Send it
    // as an empty string so every submission of a form has the same keys —
    // but only for groups that are enabled, since a disabled group is a
    // question this visitor was never shown.
    form.querySelectorAll('input[type="radio"]:not(:disabled)').forEach(function (r) {
      if (r.name && !(r.name in data)) data[r.name] = '';
    });

    // Promised to Make on every submission, including forms with no box to
    // tick (the quick-contact band). No box means no consent was given.
    if (!('newsletter_consent' in data)) data.newsletter_consent = false;

    if (form.hasAttribute('data-product')) {
      data.product = form.getAttribute('data-product');
    }

    // A question with no column of its own in the CRM (the private-label
    // form's business type) rides at the top of notes, labelled, rather than
    // adding a field every other form would have to send empty.
    form.querySelectorAll('[data-into-notes]').forEach(function (f) {
      const value = (f.value || '').trim();
      if (!value) return;
      const line = f.getAttribute('data-into-notes') + ': ' + value;
      data.notes = data.notes ? line + '\n' + data.notes : line;
    });

    // Without the #fragment: a button that jumps to an on-page form leaves one
    // in the address, and the CRM would then list the same page twice.
    data.page_url = window.location.href.split('#')[0];
    data.submitted_at = new Date().toISOString();

    // The fixed shape, in a fixed order.
    const out = {};
    FORMS.fields.forEach(function (k) {
      out[k] = k in data ? data[k] : (k === 'newsletter_consent' ? false : '');
    });
    // Anything a form sends beyond the list still goes through rather than
    // being silently dropped.
    Object.keys(data).forEach(function (k) { if (!(k in out)) out[k] = data[k]; });
    return out;
  }

  function send(form) {
    return fetch(FORMS.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payloadOf(form)),
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res;
    });
  }

  /* Show the invalid fields, or return true when the form is ready to send. */
  function validate(form) {
    form.querySelectorAll('.bg-field').forEach(function (f) {
      f.classList.remove('bg-field--error');
    });
    if (form.checkValidity()) return true;

    form.querySelectorAll(':invalid').forEach(function (f) {
      const field = f.closest('.bg-field');
      if (field) field.classList.add('bg-field--error');
    });
    const first = form.querySelector(':invalid');
    if (first) first.focus();
    return false;
  }

  /* A failed send must not look like a success. The thank-you panel stays
     hidden, the typed details stay in the fields, and the visitor is told to
     try again or use WhatsApp — losing an enquiry silently is the one outcome
     worth real effort to avoid. */
  function failureNote(form) {
    let note = form.querySelector('[data-send-error]');
    if (!note) {
      note = document.createElement('p');
      note.setAttribute('data-send-error', '');
      note.className = 'form-error';
      note.setAttribute('role', 'alert');
      note.textContent = 'השליחה נכשלה. נסו שוב, או דברו איתנו בוואטסאפ.';
      form.querySelector('button[type="submit"]').insertAdjacentElement('afterend', note);
    }
    note.hidden = false;
    return note;
  }

  function wireForm(form, onSuccess) {
    const button = form.querySelector('button[type="submit"]');
    const label = button && button.querySelector('span');
    const original = label && label.textContent;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!validate(form)) return;

      const note = form.querySelector('[data-send-error]');
      if (note) note.hidden = true;
      if (button) button.disabled = true;
      if (label) label.textContent = 'שולח…';

      send(form).then(function () {
        form.reset();
        onSuccess();
      }).catch(function (err) {
        console.error('[BioGreen] form send failed', err);
        failureNote(form).focus && failureNote(form).focus();
      }).then(function () {
        if (button) button.disabled = false;
        if (label) label.textContent = original;
      });
    });
  }

  /* Every other form is wired from its markup: a <form data-form-type> that
     names its thank-you element in data-form-done needs no code here. The
     quote modal and the product form keep their own handlers above and below,
     because their success states do more than reveal one element. */
  document.querySelectorAll('form[data-form-type][data-form-done], form[data-form-type][data-form-redirect]').forEach(function (form) {
    const done = document.getElementById(form.getAttribute('data-form-done'));
    const redirect = form.getAttribute('data-form-redirect');
    wireForm(form, function () {
      // A thank-you page of its own (the guide's download button lives
      // there), relative to this page.
      if (redirect) {
        window.location.href = new URL(redirect, window.location.href).href;
        return;
      }
      form.hidden = true;
      // The section knows it was sent: the contact band hides the line under
      // its heading, which the thank-you repeats (the client, 24/11/2026).
      const host = form.closest('section');
      if (host) host.classList.add('is-sent');
      if (done) {
        done.hidden = false;
        if (done.focus) done.focus();
      }
    });
  });

  /* ---------- information pop-ups ----------
     <a data-dialog="some-id"> opens <dialog id="some-id">. The browser looks
     after focus and Esc; this adds the close button, a click on the dimmed
     backdrop, and holding the page still behind it. */
  document.querySelectorAll('[data-dialog]').forEach(function (trigger) {
    const dialog = document.getElementById(trigger.getAttribute('data-dialog'));
    if (!dialog || typeof dialog.showModal !== 'function') return;
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      dialog.showModal();
      document.body.style.overflow = 'hidden';
    });
  });

  document.querySelectorAll('dialog').forEach(function (dialog) {
    dialog.addEventListener('close', function () { document.body.style.overflow = ''; });
    dialog.querySelectorAll('[data-dialog-close]').forEach(function (btn) {
      btn.addEventListener('click', function () { dialog.close(); });
    });
    // A click on the backdrop lands on the <dialog> itself, outside its box.
    dialog.addEventListener('click', function (e) {
      if (e.target !== dialog) return;
      const r = dialog.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) {
        dialog.close();
      }
    });
  });

  /* ---------- contact links ---------- */

  const pageLabel = (document.body.getAttribute('data-page-label') || '').trim();
  const waText = pageLabel
    ? CONTACT.whatsappMessage.replace('{page}', pageLabel)
    : CONTACT.whatsappMessageFallback;
  const waHref = 'https://wa.me/' + CONTACT.whatsapp +
    '?text=' + encodeURIComponent(waText);

  document.querySelectorAll('[data-wa]').forEach(function (el) {
    el.href = waHref;
    el.target = '_blank';
    el.rel = 'noopener';
  });

  document.querySelectorAll('[data-tel]').forEach(function (el) {
    el.href = 'tel:' + CONTACT.phoneDial;
  });

  document.querySelectorAll('[data-tel-text]').forEach(function (el) {
    el.textContent = CONTACT.phone;
  });

  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();

  /* ---------- mobile navigation ---------- */

  const navToggle = document.querySelector('.nav-toggle');
  const mobileNav = document.getElementById('mobile-nav');

  if (navToggle && mobileNav) {
    navToggle.addEventListener('click', function () {
      const open = navToggle.getAttribute('aria-expanded') === 'true';
      navToggle.setAttribute('aria-expanded', String(!open));
      navToggle.setAttribute('aria-label', open ? 'פתיחת תפריט' : 'סגירת תפריט');
      navToggle.querySelector('use').setAttribute('href', open ? '#i-menu' : '#i-x');
      mobileNav.hidden = open;
    });
  }

  /* ---------- FAQ accordion ---------- */

  document.querySelectorAll('.bg-faq__q').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const item = btn.closest('.bg-faq');
      const panel = document.getElementById(btn.getAttribute('aria-controls'));
      const open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!open));
      item.classList.toggle('bg-faq--open', !open);
      if (panel) panel.hidden = open;
    });
  });

  /* ---------- catalogue filter ---------- */

  // Every product is in the HTML; the tabs only hide what does not match, so
  // the full catalogue stays in the markup for search engines.
  const filterTabs = document.querySelectorAll('.catalog__filters .bg-tab');
  const catalogGrid = document.getElementById('catalog-grid');

  if (filterTabs.length && catalogGrid) {
    const cards = catalogGrid.querySelectorAll('.product');
    const empty = document.querySelector('.catalog__empty');

    // Each category tab has an address of its own (/products/#raw-materials),
    // so a link — the footer's, another page's, one shared by a client —
    // opens the catalogue on that tab. Clicking a tab writes its address
    // into the bar (replaced, not pushed, so Back still leaves the page).
    const showCategory = function (want) {
      let found = false;
      filterTabs.forEach(function (t) {
        const on = t.getAttribute('data-filter') === want;
        if (on) found = true;
        t.classList.toggle('bg-tab--active', on);
        t.setAttribute('aria-selected', String(on));
      });
      if (!found) return false;

      let shown = 0;
      cards.forEach(function (c) {
        const match = want === 'all' || c.getAttribute('data-category') === want;
        c.hidden = !match;
        if (match) shown++;
      });
      if (empty) empty.hidden = shown > 0;
      return true;
    };

    const fromHash = function () {
      return decodeURIComponent(window.location.hash.slice(1));
    };

    filterTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const want = tab.getAttribute('data-filter');
        showCategory(want);
        history.replaceState(null, '', want === 'all'
          ? window.location.pathname + window.location.search
          : '#' + encodeURIComponent(want));
      });
    });

    if (fromHash()) showCategory(fromHash());

    // Already on the page (the footer link from the catalogue itself): the
    // address changes without a reload, so switch the tab and bring the
    // catalogue into view.
    // To the catalogue's start, not to the tab bar: the bar is sticky, and
    // scrolling to a stuck element lands wherever it is stuck.
    window.addEventListener('hashchange', function () {
      if (!showCategory(fromHash() || 'all')) return;
      const catalog = document.getElementById('catalog');
      const header = document.querySelector('.site-header');
      const top = catalog.getBoundingClientRect().top + window.scrollY - (header ? header.offsetHeight : 0);
      const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: top, behavior: still ? 'auto' : 'smooth' });
    });
  }

  /* ---------- glossary topic filter ---------- */

  // Same idea for the glossary's topics: every term stays in the markup.
  const topicTabs = document.querySelectorAll('.glossary__filters .bg-tab');

  if (topicTabs.length) {
    const topics = document.querySelectorAll('.glossary-topic[data-topic]');
    const bar = document.querySelector('.glossary__filters');

    topicTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const want = tab.getAttribute('data-filter');

        topicTabs.forEach(function (t) {
          const on = t === tab;
          t.classList.toggle('bg-tab--active', on);
          t.setAttribute('aria-selected', String(on));
        });

        topics.forEach(function (s) {
          s.hidden = want !== 'all' && s.getAttribute('data-topic') !== want;
        });

        // Picked from far down the page, the bar is stuck to the header and
        // the new topic starts somewhere above it: bring its start into view.
        const header = document.querySelector('.site-header');
        const top = bar.parentElement.getBoundingClientRect().top - (header ? header.offsetHeight : 0);
        if (top < 0) window.scrollBy(0, top);
      });
    });
  }

  /* ---------- knowledge hub: topic tabs and search ---------- */

  // Both only hide cards; every article stays in the markup. A search from
  // the hero scrolls to the cards it filtered.
  const kGrid = document.getElementById('k-grid');

  if (kGrid) {
    const kCards = kGrid.querySelectorAll('.k-card');
    const kTabs = document.querySelectorAll('.k-filters .bg-tab');
    const kSearch = document.getElementById('k-q');
    const kEmpty = document.querySelector('.k-empty');
    let topic = 'all';

    const kApply = function () {
      const q = kSearch ? kSearch.value.trim() : '';
      let shown = 0;
      kCards.forEach(function (c) {
        const match = (topic === 'all' || c.getAttribute('data-category') === topic) &&
          (!q || c.textContent.indexOf(q) !== -1);
        c.hidden = !match;
        if (match) shown++;
      });
      if (kEmpty) kEmpty.hidden = shown > 0;
    };

    kTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        topic = tab.getAttribute('data-filter');
        kTabs.forEach(function (t) {
          const on = t === tab;
          t.classList.toggle('bg-tab--active', on);
          t.setAttribute('aria-selected', String(on));
        });
        kApply();
      });
    });

    if (kSearch) {
      kSearch.addEventListener('input', kApply);
      kSearch.form.addEventListener('submit', function (e) {
        e.preventDefault();
        kApply();
        document.getElementById('articles').scrollIntoView({ behavior: 'smooth' });
      });
    }
  }

  /* ---------- product page tabs ---------- */

  // Every panel is in the HTML; the tabs only swap which one is shown, so the
  // whole dossier stays crawlable.
  const ppTabs = document.querySelectorAll('.pp-tabbar .bg-tab');

  if (ppTabs.length) {
    const panels = document.querySelectorAll('.pp-panel');

    ppTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const want = tab.getAttribute('data-tab');

        ppTabs.forEach(function (t) {
          const on = t === tab;
          t.classList.toggle('bg-tab--active', on);
          t.setAttribute('aria-selected', String(on));
        });

        panels.forEach(function (p) {
          p.hidden = p.id !== 'tab-' + want;
        });

        // Keep the newly opened panel in view under the sticky bar.
        const bar = document.querySelector('.pp-tabbar');
        if (bar && bar.getBoundingClientRect().top < 0) {
          bar.scrollIntoView();
        }
      });
    });
  }

  /* ---------- "משהו אחר" needs no product name ----------
     In a form that asks for the request type and a product, choosing
     "משהו אחר" (intent=other) makes the product optional, asterisk and all
     (the client, 16/11/2026). Radios set from code — the quote pop-up's
     doorways and ?intent= — announce it with a "bg:intent" event. */
  document.querySelectorAll('form').forEach(function (form) {
    const product = form.querySelector('input[name="product"][required]');
    if (!product || !form.querySelector('input[name="intent"][value="other"]')) return;
    const text = product.placeholder.replace(/^\*\s*/, '');
    function sync() {
      const other = !!form.querySelector('input[name="intent"][value="other"]:checked');
      product.required = !other;
      product.placeholder = (other ? '' : '* ') + text;
      const field = product.closest('.bg-field');
      if (other && field) field.classList.remove('bg-field--error');
    }
    form.addEventListener('change', function (e) {
      if (e.target.name === 'intent') sync();
    });
    form.addEventListener('bg:intent', sync);
    sync();
  });

  /* ---------- the sticky phone bar ----------
     On a page whose opening has the same buttons (data-bar-after on their
     row), the bar stays out of the way while they are on screen or still
     below it, and slides in once they have been scrolled past (the client,
     19/11/2026). */
  const barAfter = document.querySelector('[data-bar-after]');
  if (barAfter && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      const e = entries[0];
      document.body.classList.toggle('mobile-bar--away', e.isIntersecting || e.boundingClientRect.top > 0);
    }).observe(barAfter);
  }

  /* ---------- the accessibility button on phones ----------
     It sits in the sticky header, beside the menu button, instead of over
     the page (a11y-panel.js reads these two values below 768px). */
  function placeA11y() {
    const toggle = document.querySelector('.nav-toggle');
    if (!toggle || !window.matchMedia('(max-width: 768px)').matches) return;
    const r = toggle.getBoundingClientRect();
    if (!r.width) return;
    const root = document.documentElement.style;
    root.setProperty('--a11y-m-top', Math.round(r.top + (r.height - 38) / 2) + 'px');
    root.setProperty('--a11y-m-left', Math.round(r.right + 10) + 'px');
  }
  placeA11y();
  window.addEventListener('resize', placeA11y);

  /* ---------- product page quote form ---------- */

  const productForm = document.querySelector('[data-product-form]');

  if (productForm) {
    const doneBox = document.querySelector('.pp-form__done');

    wireForm(productForm, function () {
      productForm.hidden = true;
      if (doneBox) {
        doneBox.hidden = false;
        doneBox.scrollIntoView({ block: 'center' });
      }
    });
  }

  /* ---------- quote modal ---------- */

  const modal = document.getElementById('quote-modal');
  const form = document.getElementById('quote-form');
  const done = document.getElementById('quote-done');
  const productField = document.getElementById('q-product');
  const intro = modal && modal.querySelector('.modal__intro');

  const INTENTS = ['import', 'products', 'raw', 'other'];

  let lastFocused = null;

  function applyIntent(intent) {
    if (INTENTS.indexOf(intent) === -1) intent = 'import';
    const radio = form.querySelector('input[name="intent"][value="' + intent + '"]');
    if (radio) radio.checked = true;
    form.dispatchEvent(new Event('bg:intent'));
  }

  // The intro above the form and the thank-you below it name the same
  // dialog at different moments; the dialog's label follows whichever shows.
  function showIntro(on) {
    if (intro) intro.hidden = !on;
    modal.setAttribute('aria-labelledby', on ? 'quote-title' : 'quote-done-title');
  }

  function openModal(trigger) {
    if (!modal) return;
    lastFocused = trigger || document.activeElement;

    form.hidden = false;
    done.hidden = true;
    showIntro(true);

    applyIntent((trigger && trigger.getAttribute('data-intent')) || 'import');

    const product = trigger && trigger.getAttribute('data-product');
    if (productField) productField.value = product || '';

    modal.hidden = false;
    document.body.style.overflow = 'hidden';

    const firstEmpty = product ? form.querySelector('#q-company') : productField;
    if (firstEmpty) firstEmpty.focus({ preventScroll: true });
  }

  function closeModal() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    document.body.style.overflow = '';
    if (lastFocused) lastFocused.focus({ preventScroll: true });
  }

  document.querySelectorAll('[data-quote]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      openModal(el);
      // A doorway also closes the mobile menu behind it.
      if (mobileNav && !mobileNav.hidden && navToggle) navToggle.click();
    });
  });

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
    modal.querySelectorAll('.modal__close, [data-modal-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
    // While the pop-up is open, Tab and Shift+Tab go round inside it rather
    // than out to the page behind (accessibility audit, 16/11/2026).
    if (e.key === 'Tab' && modal && !modal.hidden) {
      const items = Array.prototype.filter.call(
        modal.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'),
        function (el) { return !el.disabled && el.offsetParent !== null; });
      if (!items.length) return;
      const first = items[0], last = items[items.length - 1];
      const inside = modal.contains(document.activeElement);
      if (e.shiftKey && (document.activeElement === first || !inside)) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || !inside)) {
        e.preventDefault(); first.focus();
      }
    }
  });

  // Deep link: /?intent=import|products|raw|other opens the form on that type,
  // so campaign and e-mail links can land straight on the right request.
  const deepIntent = new URLSearchParams(location.search).get('intent');
  if (deepIntent && INTENTS.indexOf(deepIntent) !== -1) {
    const proxy = document.createElement('button');
    proxy.setAttribute('data-intent', deepIntent);
    openModal(proxy);
    lastFocused = null;
  }

  if (form) {
    form.querySelectorAll('input[name="intent"]').forEach(function (radio) {
      radio.addEventListener('change', function () { applyIntent(radio.value); });
    });

    // After a send only the thank-you and the close button remain: the
    // "let's see how we can help" intro asks for details already given.
    wireForm(form, function () {
      form.hidden = true;
      done.hidden = false;
      showIntro(false);
      document.getElementById('quote-done-title').focus();
    });
  }
})();

/* NOTE: the doorways and CTA buttons are <button data-quote> because they open
   the form in place. If the form later becomes its own page (or a CMS-managed
   page), swap them for <a href="/contact?intent=import"> — the data-intent
   values (import / products / raw) already match that query string. */

/* ---------- cookie consent ----------
   Three kinds of cookies, and nothing optional runs before the visitor says
   so:
     necessary  always on — this choice itself, the accessibility settings
     analytics  Google Analytics 4
     marketing  the Meta (Facebook / Instagram) pixel
   The bar offers "accept all", "reject" and "settings" (one switch per kind);
   "הגדרות עוגיות" in the footer opens the settings again at any time. The
   choice is kept in localStorage "bg-consent".

   Adding the tools is a matter of filling in TRACKING below: each loads only
   once its kind is accepted, on this page view or a later one. Anything else
   that needs consent goes through bgConsent.whenGranted('analytics' |
   'marketing', fn). Withdrawing consent deletes the tools' cookies and
   reloads the page, since a script already running cannot be unloaded. */
(function () {
  'use strict';

  const TRACKING = {
    ga4: '',          // Measurement ID, e.g. 'G-XXXXXXXXXX'
    metaPixel: '',    // Pixel ID, digits only
  };

  const KEY = 'bg-consent';
  const KINDS = ['analytics', 'marketing'];
  const TEXT = {
    bar: 'אנחנו משתמשים בעוגיות הכרחיות כדי שהאתר יפעל כראוי. באישורכם נשתמש גם בעוגיות ' +
         'סטטיסטיקה ושיווק, כדי להבין איך משתמשים באתר ולהציג מודעות רלוונטיות. ' +
         'אפשר לשנות את הבחירה בכל עת ב"הגדרות עוגיות" בתחתית האתר. ' +
         'לפרטים נוספים ראו ',            // + a link to the privacy policy
    necessary: ['עוגיות הכרחיות', 'נדרשות כדי שהאתר יפעל: שמירת הבחירה שלכם כאן והגדרות הנגישות. תמיד פעילות.'],
    analytics: ['סטטיסטיקה', 'עוזרות לנו להבין איך משתמשים באתר ולשפר אותו (Google Analytics).'],
    marketing: ['שיווק', 'מאפשרות להציג לכם מודעות רלוונטיות בפייסבוק ובאינסטגרם ולמדוד אותן (Meta Pixel).'],
  };

  // The privacy policy, from this script's own address (js/site.js sits one
  // level below the site root, whatever the page's depth).
  const POLICY = (function () {
    const s = document.currentScript;
    try { return new URL('../privacy/', (s && s.src) || location.href).href; } catch (e) { return 'privacy/'; }
  }());

  function policyLink() {
    const a = document.createElement('a');
    a.href = POLICY;
    a.target = '_blank';
    a.rel = 'noopener';
    a.textContent = 'מדיניות הפרטיות';
    return a;
  }

  const waiting = { analytics: [], marketing: [] };
  let state = read();
  let bar = null;
  let dialog = null;

  // The choice is kept twice: in localStorage and in a first-party cookie
  // (a year). A browser that clears one of them on exit, or blocks storage
  // for scripts, still remembers the answer through the other; on the real
  // domain the cookie also covers www and the bare domain alike (the client
  // saw the bar come back, 19/11/2026).
  const COOKIE = 'bg_consent';

  function cookieDomain() {
    const m = location.hostname.match(/(?:^|\.)(bio-?green\.co\.il)$/);
    return m ? '; domain=' + m[1] : '';
  }

  function readCookie() {
    const m = document.cookie.match(/(?:^|;\s*)bg_consent=([^;]*)/);
    try { return m ? JSON.parse(decodeURIComponent(m[1])) : null; } catch (e) { return null; }
  }

  function read() {
    let v = null;
    try { v = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { v = null; }
    if (!v) v = readCookie();
    if (v && v.choice) {           // the first version kept one yes/no
      const yes = v.choice === 'granted';
      v = { analytics: yes, marketing: yes, at: v.at };
    }
    return v && typeof v.analytics === 'boolean' ? v : null;
  }

  function save(choice) {
    state = { analytics: !!choice.analytics, marketing: !!choice.marketing, at: new Date().toISOString() };
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* blocked: the cookie below still holds it */ }
    document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(state)) +
      '; max-age=31536000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '') + cookieDomain();
  }

  function granted(kind) { return !!(state && state[kind]); }

  // ---- the tools, loaded only once their kind is accepted

  function addScript(src) {
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    document.head.appendChild(s);
  }

  function loadAnalytics() {
    if (!TRACKING.ga4 || window.gtag) return;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', {
      analytics_storage: 'granted', ad_storage: 'denied',
      ad_user_data: 'denied', ad_personalization: 'denied',
    });
    window.gtag('js', new Date());
    window.gtag('config', TRACKING.ga4, { anonymize_ip: true });
    addScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(TRACKING.ga4));
  }

  function loadPixel() {
    if (!TRACKING.metaPixel || window.fbq) return;
    const fbq = window.fbq = function () {
      fbq.callMethod ? fbq.callMethod.apply(fbq, arguments) : fbq.queue.push(arguments);
    };
    if (!window._fbq) window._fbq = fbq;
    fbq.push = fbq; fbq.loaded = true; fbq.version = '2.0'; fbq.queue = [];
    addScript('https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', TRACKING.metaPixel);
    fbq('track', 'PageView');
  }

  function run() {
    KINDS.forEach(function (k) {
      if (!granted(k)) return;
      while (waiting[k].length) waiting[k].shift()();
    });
  }

  // Cookies the two tools set, on this host and its parent domains.
  function dropCookies(kind) {
    const names = document.cookie.split(';').map(function (c) { return c.split('=')[0].trim(); });
    const match = kind === 'analytics' ? /^(_ga|_gid|_gat)/ : /^(_fbp|_fbc)$/;
    const parts = location.hostname.split('.');
    names.filter(function (n) { return match.test(n); }).forEach(function (n) {
      for (let i = 0; i < parts.length; i++) {
        const domain = parts.slice(i).join('.');
        document.cookie = n + '=; Max-Age=0; path=/; domain=' + domain;
      }
      document.cookie = n + '=; Max-Age=0; path=/';
    });
  }

  function decide(choice) {
    const before = state;
    save(choice);
    if (bar) bar.hidden = true;
    liftA11y();
    if (dialog && dialog.open) dialog.close();
    const withdrawn = before && KINDS.some(function (k) { return before[k] && !state[k]; });
    document.dispatchEvent(new CustomEvent('bg:consent', { detail: state }));
    if (withdrawn) {
      KINDS.forEach(function (k) { if (!state[k]) dropCookies(k); });
      location.reload();
      return;
    }
    run();
  }

  // ---- the bar and the settings

  function button(cls, label, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'bg-btn bg-btn--sm ' + cls;
    b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
  }

  const all = function () { decide({ analytics: true, marketing: true }); };
  const none = function () { decide({ analytics: false, marketing: false }); };

  function buildBar() {
    bar = document.createElement('section');
    bar.className = 'cookie-bar';
    bar.setAttribute('aria-label', 'הסכמה לעוגיות');
    bar.hidden = true;
    const p = document.createElement('p');
    p.className = 'cookie-bar__text';
    p.textContent = TEXT.bar;
    p.append(policyLink(), '.');
    const actions = document.createElement('div');
    actions.className = 'cookie-bar__actions';
    actions.appendChild(button('bg-btn--primary', 'אישור הכל', all));
    actions.appendChild(button('bg-btn--secondary', 'דחייה', none));
    actions.appendChild(button('bg-btn--ghost cookie-bar__settings', 'הגדרות', openSettings));
    bar.appendChild(p);
    bar.appendChild(actions);
    // First in the Tab order, right after "דילוג לתוכן הראשי", so a keyboard
    // user meets it before the page rather than after all of it.
    const skip = document.querySelector('.skip-link');
    if (skip) skip.insertAdjacentElement('afterend', bar);
    else document.body.insertBefore(bar, document.body.firstChild);
  }

  function row(kind, fixed) {
    const label = document.createElement('label');
    label.className = 'cookie-kind';
    const text = document.createElement('span');
    text.className = 'cookie-kind__text';
    const strong = document.createElement('strong');
    strong.textContent = TEXT[kind][0];
    const small = document.createElement('span');
    small.textContent = TEXT[kind][1];
    text.appendChild(strong);
    text.appendChild(small);
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.className = 'cookie-kind__switch';
    box.setAttribute('role', 'switch');
    box.name = kind;
    if (fixed) { box.checked = true; box.disabled = true; }
    label.appendChild(text);
    label.appendChild(box);
    return label;
  }

  function buildDialog() {
    dialog = document.createElement('dialog');
    dialog.className = 'info-dialog cookie-dialog';
    dialog.setAttribute('aria-labelledby', 'cookie-dialog-title');
    dialog.innerHTML =
      '<div class="info-dialog__head"><button class="modal__close" type="button" aria-label="סגירה">' +
      '<svg class="icon" width="24" height="24"><use href="#i-x"></use></svg></button></div>' +
      '<div class="info-dialog__body"><h2 id="cookie-dialog-title">הגדרות עוגיות</h2>' +
      '<p>בחרו אילו עוגיות להפעיל. אפשר לשנות את הבחירה בכל עת.</p>' +
      '<div class="cookie-kinds"></div><div class="cookie-dialog__actions"></div></div>';
    dialog.querySelector('.info-dialog__body > p').append(' לפרטים נוספים ראו ', policyLink(), '.');
    const kinds = dialog.querySelector('.cookie-kinds');
    kinds.appendChild(row('necessary', true));
    KINDS.forEach(function (k) { kinds.appendChild(row(k, false)); });
    const actions = dialog.querySelector('.cookie-dialog__actions');
    actions.appendChild(button('bg-btn--primary', 'שמירת הבחירה', function () {
      decide({
        analytics: dialog.querySelector('[name=analytics]').checked,
        marketing: dialog.querySelector('[name=marketing]').checked,
      });
    }));
    actions.appendChild(button('bg-btn--secondary', 'אישור הכל', all));
    actions.appendChild(button('bg-btn--secondary', 'דחייה', none));
    dialog.querySelector('.modal__close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (e) {
      if (e.target !== dialog) return;
      const r = dialog.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dialog.close();
    });
    dialog.addEventListener('close', function () { document.body.style.overflow = ''; });
    document.body.appendChild(dialog);
  }

  function openSettings() {
    if (!dialog) buildDialog();
    KINDS.forEach(function (k) { dialog.querySelector('[name=' + k + ']').checked = granted(k); });
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
      document.body.style.overflow = 'hidden';
    } else {
      dialog.setAttribute('open', '');
    }
  }

  // The accessibility button sits at the bottom corner; while the bar is
  // showing and under it (phones), lift the button above the bar.
  function liftA11y() {
    let lift = 0;
    if (bar && !bar.hidden) {
      const r = bar.getBoundingClientRect();
      // The site places it on the left (data-position="left"), 18px in.
      if (r.left < 70) lift = Math.max(0, window.innerHeight - r.top - 92 + 12);
    }
    document.documentElement.style.setProperty('--a11y-lift', lift + 'px');
  }

  window.bgConsent = {
    get: function (kind) { return granted(kind); },
    whenGranted: function (kind, fn) {
      if (KINDS.indexOf(kind) === -1) return;
      if (granted(kind)) fn(); else waiting[kind].push(fn);
    },
    open: openSettings,
  };

  bgConsent.whenGranted('analytics', loadAnalytics);
  bgConsent.whenGranted('marketing', loadPixel);

  document.querySelectorAll('[data-cookie-settings]').forEach(function (b) {
    b.addEventListener('click', openSettings);
  });

  if (!state) {
    buildBar();
    bar.hidden = false;
    liftA11y();
    window.addEventListener('resize', liftA11y);
  }
})();
