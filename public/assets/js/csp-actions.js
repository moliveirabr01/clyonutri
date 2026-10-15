/*
 * NI2-429: substitui os atributos de evento inline (onclick, onsubmit, onchange, onmouseover...)
 * e links javascript:, que uma CSP sem 'unsafe-inline' bloqueia. Delegação no document, então
 * também vale para linhas criadas depois por JS. Nenhum eval: data-call só chama funções da lista.
 */
(function () {
  'use strict';
  var CALLABLE = ['addIngredientRow', 'addStepRow', 'closeAnthroModal', 'closePatientModal', 'enterWaitingRoom',
    'openAddItemModal', 'openAddSubModal', 'openAnthroModal', 'openPatientModal', 'openPayModal',
    'openRevisionModal', 'testMedia', 'toggleAdminMenu'];
  function call(name, el) {
    if (CALLABLE.indexOf(name) === -1 || typeof window[name] !== 'function') return;
    var args = [];
    if (el.hasAttribute('data-args')) { try { args = JSON.parse(el.getAttribute('data-args')); } catch (e) { return; } }
    window[name].apply(el, args);
  }
  function display(id, show) {
    var m = document.getElementById(id); if (!m) return;
    m.style.display = show ? 'flex' : 'none';
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-confirm]:not(form), [data-call], [data-call-self], [data-show], [data-hide], [data-remove-parent], [data-select-all], [data-dialog-open], [data-toggle-dialog], [data-reset-close-details], [data-history-back]') : null;
    if (!el) return;
    if (el.hasAttribute('data-confirm') && el.tagName !== 'FORM') {
      if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    }
    if (el.hasAttribute('data-call-self')) { if (e.target === el) call(el.getAttribute('data-call-self'), el); return; }
    if (el.hasAttribute('data-call')) { call(el.getAttribute('data-call'), el); }
    if (el.hasAttribute('data-show')) { display(el.getAttribute('data-show'), true); }
    if (el.hasAttribute('data-hide')) { display(el.getAttribute('data-hide'), false); }
    if (el.hasAttribute('data-remove-parent') && el.parentElement) { el.parentElement.remove(); }
    if (el.hasAttribute('data-select-all') && el.select) { el.select(); }
    if (el.hasAttribute('data-dialog-open')) {
      var d = document.getElementById(el.getAttribute('data-dialog-open'));
      if (window.ClyoDialog) window.ClyoDialog.open(el.getAttribute('data-dialog-open')); else if (d) d.style.display = 'flex';
    }
    if (el.hasAttribute('data-toggle-dialog')) {
      var m = document.getElementById(el.getAttribute('data-toggle-dialog'));
      if (m) { if (m.style.display === 'none') m.style.display = 'block'; else if (window.ClyoDialog) window.ClyoDialog.close(m); else m.style.display = 'none'; }
    }
    if (el.hasAttribute('data-reset-close-details') && el.form) { el.form.reset(); var det = el.closest('details'); if (det) det.open = false; }
    if (el.hasAttribute('data-history-back')) { e.preventDefault(); history.back(); }
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute && f.hasAttribute('data-confirm') && !window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    if (f.hasAttribute && f.hasAttribute('data-busy-text')) {
      var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.textContent = f.getAttribute('data-busy-text'); }
    }
  }, true);
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.hasAttribute && el.hasAttribute('data-nav-prefix') && el.value) {
      window.location.href = el.getAttribute('data-nav-prefix') + encodeURIComponent(el.value);
    }
  });
  function hover(e, attr) {
    var el = e.target.closest ? e.target.closest('[' + attr + ']') : null; if (!el) return;
    if (e.relatedTarget && el.contains(e.relatedTarget)) return;
    var v = el.getAttribute(attr), i = v.indexOf(':'); if (i > 0) el.style[v.slice(0, i)] = v.slice(i + 1);
  }
  document.addEventListener('mouseover', function (e) { hover(e, 'data-hover'); });
  document.addEventListener('mouseout', function (e) { hover(e, 'data-unhover'); });
})();
