/* v20 (auditoria rodada 2): confirmações na avaliação antropométrica.
   - Altura digitada em metros (ex.: 1,70) é mostrada convertida (170 cm) antes de salvar.
   - Liberar/revogar avaliação para o app do paciente pede confirmação explícita. */
(function () {
  'use strict';
  function fmt(n) { return String(Math.round(n * 10) / 10).replace('.', ','); }
  function parse(v) { v = String(v || '').trim().replace(',', '.'); return v === '' ? NaN : Number(v); }

  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (!el || el.name !== 'height_cm') return;
    var hint = el.parentNode.querySelector('.ant-height-hint');
    if (!hint) { hint = document.createElement('small'); hint.className = 'ant-height-hint form-hint'; hint.setAttribute('aria-live', 'polite'); el.parentNode.appendChild(hint); }
    var h = parse(el.value);
    hint.textContent = (h > 0 && h <= 3) ? 'Será salvo como ' + fmt(h * 100) + ' cm (convertido de ' + fmt(h) + ' m).' : '';
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    var action;
    try { action = new URL(form.action, document.baseURI).pathname; }
    catch (_) { return; }
    if (/\/antropometria\/\d+\/compartilhar$/.test(action)) {
      var sh = form.querySelector('input[name="is_shared"]');
      var liberar = sh && sh.value === '1';
      var msg = liberar
        ? 'Liberar esta avaliação para o paciente? Ela ficará visível no aplicativo dele.'
        : 'Revogar esta avaliação do aplicativo do paciente? Ele deixará de vê-la.';
      if (!window.confirm(msg)) ev.preventDefault();
      return;
    }
    if (/\/antropometria$/.test(action)) {
      var hEl = form.querySelector('[name="height_cm"]');
      var h = hEl ? parse(hEl.value) : NaN;
      // Reteste UX v54.62 (R-007): com o eco "Interpretado: 1,70 m (170 cm)" visível ao lado do campo (decimal-input.js),
      // a confirmação nativa é redundante e custa um clique a mais. Sem o eco, a confirmação continua.
      var echoed = hEl && hEl.nextElementSibling && hEl.nextElementSibling.hasAttribute('data-decimal-echo') && !hEl.nextElementSibling.hidden;
      if (!echoed && h > 0 && h <= 3 && !window.confirm('A altura ' + fmt(h) + ' foi entendida em metros e será salva como ' + fmt(h * 100) + ' cm. Confirmar?')) { ev.preventDefault(); return; }
      var rel = form.querySelector('input[type="checkbox"][name="is_shared"]');
      if (rel && rel.checked && !window.confirm('Esta avaliação será liberada imediatamente no aplicativo do paciente. Confirmar?')) { ev.preventDefault(); }
    }
  }, true);
})();
