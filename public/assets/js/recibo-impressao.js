/* v54.36: recibo imprimível — sem script inline (CSP script-src 'self'). */
(function () {
  var btn = document.getElementById('rc-print');
  if (btn) btn.addEventListener('click', function () { window.print(); });
  var cp = document.getElementById('rc-copy');
  if (cp) cp.addEventListener('click', function () {
    var url = cp.getAttribute('data-url') || '';
    var done = function () { cp.textContent = 'Link copiado'; };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copie o link do recibo:', url); }); }
    else { window.prompt('Copie o link do recibo:', url); }
  });
})();
