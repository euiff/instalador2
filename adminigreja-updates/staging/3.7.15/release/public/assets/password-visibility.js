/* Igreja Master 3.7.15: accessible password reveal on existing and newly opened forms. */
(function () {
  'use strict';

  const openEye = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  const closedEye = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.5 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a15.5 15.5 0 0 1-3.2 4.1M6.2 6.2A16 16 0 0 0 2 12s3.5 7 10 7a10.3 10.3 0 0 0 5-1.2"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/></svg>';

  function decorate(field) {
    if (!(field instanceof HTMLInputElement) || field.type !== 'password') return;
    if (field.dataset.eyeReady === '1' || !field.parentNode) return;
    if (field.disabled && field.type !== 'password') return;

    const wrapper = document.createElement('span');
    wrapper.className = 'im-password-eye-wrap';

    field.parentNode.insertBefore(wrapper, field);
    wrapper.appendChild(field);
    field.dataset.eyeReady = '1';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'im-password-eye-button';
    button.setAttribute('aria-label', 'Mostrar senha');
    button.setAttribute('title', 'Mostrar senha');
    button.setAttribute('aria-pressed', 'false');
    button.innerHTML = openEye;

    button.addEventListener('click', function () {
      const reveal = field.type === 'password';
      field.type = reveal ? 'text' : 'password';
      field.classList.toggle('im-password-visible', reveal);
      button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      button.setAttribute('aria-label', reveal ? 'Ocultar senha' : 'Mostrar senha');
      button.setAttribute('title', reveal ? 'Ocultar senha' : 'Mostrar senha');
      button.innerHTML = reveal ? closedEye : openEye;
      try { field.focus({ preventScroll: true }); } catch (_) { field.focus(); }
    });

    wrapper.appendChild(button);
  }

  function scan(container) {
    if (!container) return;
    if (container.nodeType === 1 && container.matches && container.matches('input[type="password"]')) {
      decorate(container);
    }
    if (container.querySelectorAll) {
      container.querySelectorAll('input[type="password"]:not([data-eye-ready="1"])').forEach(decorate);
    }
  }

  function initialize() {
    scan(document);
    if (typeof MutationObserver !== 'undefined') {
      const observer = new MutationObserver(function (mutations) {
        for (const mutation of mutations) {
          for (const node of mutation.addedNodes) {
            if (node.nodeType === 1 || node.nodeType === 11) scan(node);
          }
        }
      });
      observer.observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
