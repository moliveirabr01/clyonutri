/* v20 (auditoria rodada 2): mensagens de validação nativas em pt-BR, independente do idioma do navegador.
   Carregado antes de clinical-journeys.js (que copia validationMessage para o texto de erro do campo). */
(function () {
  'use strict';
  function num(v) { return String(v).replace('.', ','); }
  function message(el) {
    var v = el.validity;
    if (v.valueMissing) return el.type === 'checkbox' ? 'Marque esta opção para continuar.' : (el.tagName === 'SELECT' ? 'Selecione uma opção.' : 'Preencha este campo.');
    if (v.typeMismatch) return el.type === 'email' ? 'Informe um e-mail válido (ex.: nome@dominio.com).' : (el.type === 'url' ? 'Informe um endereço válido (ex.: https://...).' : 'Valor em formato inválido.');
    if (v.badInput) return 'Informe um número válido.';
    if (v.rangeUnderflow) return 'O valor deve ser maior ou igual a ' + num(el.min) + '.';
    if (v.rangeOverflow) return 'O valor deve ser menor ou igual a ' + num(el.max) + '.';
    if (v.stepMismatch) return 'Use no máximo ' + (String(el.step).split('.')[1] || '').length + ' casa(s) decimal(is).';
    if (v.tooShort) return 'Use pelo menos ' + el.minLength + ' caracteres (agora: ' + el.value.length + ').';
    if (v.tooLong) return 'Use no máximo ' + el.maxLength + ' caracteres.';
    if (v.patternMismatch) return el.title ? el.title : 'Valor em formato inválido.';
    return '';
  }
  document.addEventListener('invalid', function (e) {
    var el = e.target;
    if (!el || !el.validity || (el.validity.customError && !el.dataset.ptbrMsg)) return; // respeita mensagens próprias de outros scripts
    el.setCustomValidity('');
    var m = message(el);
    if (m) { el.setCustomValidity(m); el.dataset.ptbrMsg = '1'; }
  }, true);
  function reset(e) {
    var el = e.target;
    if (el && el.dataset && el.dataset.ptbrMsg) { el.setCustomValidity(''); delete el.dataset.ptbrMsg; }
  }
  document.addEventListener('input', reset, true);
  document.addEventListener('change', reset, true);
})();
