/* Impressão do prontuário: sem script inline (CSP script-src 'self'). */
(function () {
  var btn = document.getElementById('pi-print');
  if (btn) btn.addEventListener('click', function () { window.print(); });
})();
