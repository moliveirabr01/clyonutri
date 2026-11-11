// NI2-663 · Clyo Academy: comportamentos sem atributos on* (a CSP bloqueia script-src-attr).
(function () {
  'use strict';
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches && t.matches('select[data-autosubmit]') && t.form) { t.form.submit(); }
    if (t.matches && t.matches('select[data-collection-nav]')) {
      window.location.href = '/academy/colecao' + (t.value ? '?collection=' + encodeURIComponent(t.value) : '');
    }
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-copy]') : null;
    if (!b) { return; }
    var src = b.nextElementSibling; var text = src ? src.textContent : '';
    var done = function (ok) { b.textContent = ok ? 'Copiado' : 'Não foi possível copiar'; b.setAttribute('aria-live', 'polite'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
    } else { done(false); }
  });
})();
