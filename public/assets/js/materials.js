// Materiais educativos: filtro da lista de pacientes no envio (sem atributos on*; a CSP bloqueia script-src-attr).
(function () {
  'use strict';
  var input = document.querySelector('[data-mat-filter]');
  var list = document.querySelector('[data-mat-list]');
  if (!input || !list) { return; }
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    list.querySelectorAll('label[data-name]').forEach(function (l) { l.hidden = q !== '' && l.getAttribute('data-name').indexOf(q) === -1; });
  });
})();
