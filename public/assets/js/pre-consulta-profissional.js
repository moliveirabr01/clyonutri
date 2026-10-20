/* Pré-consulta, tela do nutricionista: copiar o link com um clique. Sem script inline (CSP). */
(function () {
  'use strict';
  var btn = document.getElementById('pc-copy-link');
  if (!btn) return;
  var input = document.getElementById(btn.getAttribute('data-copy-target'));
  var status = document.getElementById('pc-copy-status');
  var label = btn.textContent;

  function done(ok) {
    btn.textContent = ok ? 'Copiado ✓' : label;
    if (status) status.textContent = ok ? 'Link copiado. Cole na conversa com o paciente.' : 'Não foi possível copiar: o link está selecionado, use Ctrl+C (ou ⌘+C).';
    if (ok) setTimeout(function () { btn.textContent = label; }, 2500);
  }

  btn.addEventListener('click', function () {
    if (!input) return;
    input.focus();
    input.select();
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(function () { done(true); }, function () { done(false); });
    } else {
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      done(ok);
    }
  });
})();
