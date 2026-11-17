(() => {
  'use strict';
  document.querySelectorAll('[data-copy-booking-link]').forEach(button => {
    button.addEventListener('click', async () => {
      const root = button.closest('[data-testid="agenda-booking-shortcut"]');
      const status = root.querySelector('[data-booking-copy-status]');
      const url = new URL(button.dataset.bookingUrl, window.location.origin).href;
      try {
        await navigator.clipboard.writeText(url);
        status.textContent = 'Link copiado.';
      } catch (_) {
        const fallback = root.querySelector('[data-booking-link-fallback]');
        fallback.hidden = false;
        const input = fallback.querySelector('input');
        input.value = url;
        input.focus();
        input.select();
        status.textContent = 'Selecione e copie o link exibido.';
      }
    });
  });
})();
