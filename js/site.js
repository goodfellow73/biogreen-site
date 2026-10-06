/* ==========================================================================
   BioGreen — home page behaviour.
   No dependencies. Everything degrades gracefully without JS except the
   modal (the doorways then fall back to nothing — see NOTE at the bottom).
   ========================================================================== */

/* --------------------------------------------------------------------------
   CONTACT — the only place the phone / WhatsApp number lives.
   TODO: replace with the client's real details before launch.
   -------------------------------------------------------------------------- */
const CONTACT = {
  phone: '03-0000000',               // shown to the user (local format)
  phoneDial: '+97230000000',         // used for tel:
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
};

(function () {
  'use strict';

  /* ---------- form submission ---------- */

  /* Build the payload for one form.

     Checkboxes are the awkward part: an unticked box is simply absent from
     FormData, so reading the entries alone would send newsletter_consent only
     when it is true and leave Make unable to tell "declined" from "that form
     has no such field". Every checkbox is therefore written explicitly as a
     boolean. Fields hidden by the current intent are disabled by applyIntent
     and drop out on their own, which is what we want — an answer to a question
     that was never shown is not an answer. */
  function payloadOf(form) {
    const data = { form_type: form.getAttribute('data-form-type') || 'unknown' };

    new FormData(form).forEach(function (value, key) {
      data[key] = typeof value === 'string' ? value.trim() : value;
    });

    form.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
      if (box.name) data[box.name] = box.checked;
    });

    if (form.hasAttribute('data-product')) {
      data.product = form.getAttribute('data-product');
    }

    data.page_url = window.location.href;
    data.submitted_at = new Date().toISOString();
    return data;
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

    filterTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const want = tab.getAttribute('data-filter');

        filterTabs.forEach(function (t) {
          const on = t === tab;
          t.classList.toggle('bg-tab--active', on);
          t.setAttribute('aria-selected', String(on));
        });

        let shown = 0;
        cards.forEach(function (c) {
          const match = want === 'all' || c.getAttribute('data-category') === want;
          c.hidden = !match;
          if (match) shown++;
        });

        if (empty) empty.hidden = shown > 0;
      });
    });
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

  // Step 4 adapts to the selected intent.
  const STEP4_TITLES = {
    import: 'יש לכם כבר ספק?',
    products: 'מה אתם מחפשים?',
    raw: 'איזה חומר גלם אתם מחפשים?',
  };

  let lastFocused = null;

  function applyIntent(intent) {
    if (!STEP4_TITLES[intent]) intent = 'import';

    const radio = form.querySelector('input[name="intent"][value="' + intent + '"]');
    if (radio) radio.checked = true;

    const title = form.querySelector('[data-step4-title]');
    if (title) title.textContent = STEP4_TITLES[intent];

    form.querySelectorAll('[data-step4]').forEach(function (group) {
      const match = group.getAttribute('data-step4') === intent;
      group.hidden = !match;
      // Keep hidden inputs out of validation and out of the payload.
      group.querySelectorAll('input, textarea, select').forEach(function (f) {
        f.disabled = !match;
      });
    });
  }

  function openModal(trigger) {
    if (!modal) return;
    lastFocused = trigger || document.activeElement;

    form.hidden = false;
    done.hidden = true;

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
  });

  // Deep link: /?intent=import|products|raw opens the form on that type,
  // so campaign and e-mail links can land straight on the right request.
  const deepIntent = new URLSearchParams(location.search).get('intent');
  if (deepIntent && STEP4_TITLES[deepIntent]) {
    const proxy = document.createElement('button');
    proxy.setAttribute('data-intent', deepIntent);
    openModal(proxy);
    lastFocused = null;
  }

  if (form) {
    form.querySelectorAll('input[name="intent"]').forEach(function (radio) {
      radio.addEventListener('change', function () { applyIntent(radio.value); });
    });

    wireForm(form, function () {
      form.hidden = true;
      done.hidden = false;
      done.querySelector('h2').focus && done.querySelector('h2').focus();
    });
  }
})();

/* NOTE: the doorways and CTA buttons are <button data-quote> because they open
   the form in place. If the form later becomes its own page (or a CMS-managed
   page), swap them for <a href="/contact?intent=import"> — the data-intent
   values (import / products / raw) already match that query string. */
