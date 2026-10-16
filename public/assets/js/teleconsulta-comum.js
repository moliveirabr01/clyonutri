/* Confirmações de formulário sem handlers inline (compatível com CSP script-src 'self'). */
(function () {
  document.addEventListener('submit', function (ev) {
    var msg = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  }, true);
})();
