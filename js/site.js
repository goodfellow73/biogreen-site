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
  whatsapp: '97250000000',           // international format, digits only
  whatsappMessage: 'שלום, הגעתי מהאתר ואשמח לקבל פרטים.',
};

(function () {
  'use strict';

  /* ---------- contact links ---------- */

  const waHref = 'https://wa.me/' + CONTACT.whatsapp +
    '?text=' + encodeURIComponent(CONTACT.whatsappMessage);

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

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      // Native validation first, styled by .bg-field--error.
      form.querySelectorAll('.bg-field').forEach(function (f) { f.classList.remove('bg-field--error'); });
      if (!form.checkValidity()) {
        form.querySelectorAll(':invalid').forEach(function (f) {
          const field = f.closest('.bg-field');
          if (field) field.classList.add('bg-field--error');
        });
        const firstInvalid = form.querySelector(':invalid');
        if (firstInvalid) firstInvalid.focus();
        return;
      }

      // TODO: wire to the CRM / mail endpoint. The payload is ready here.
      const payload = Object.fromEntries(new FormData(form).entries());
      console.info('[BioGreen] quote request', payload);

      form.hidden = true;
      done.hidden = false;
      done.querySelector('h2').focus && done.querySelector('h2').focus();
      form.reset();
    });
  }
})();

/* NOTE: the doorways and CTA buttons are <button data-quote> because they open
   the form in place. If the form later becomes its own page (or a CMS-managed
   page), swap them for <a href="/contact?intent=import"> — the data-intent
   values (import / products / raw) already match that query string. */
