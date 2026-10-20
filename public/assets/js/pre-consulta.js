// Pré-consulta (NI2-437): mostra perguntas condicionais só quando a resposta anterior pede.
// Sem JavaScript tudo aparece e o servidor ignora respostas de perguntas que não se aplicam.
(function () {
  'use strict';
  var form = document.querySelector('[data-pc-form]');
  if (!form) return;

  function valuesOf(qid) {
    var out = [];
    var inputs = form.querySelectorAll('[name="answers[' + qid + ']"], [name="answers[' + qid + '][]"]');
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) continue;
      if (el.value !== '') out.push(el.value);
    }
    return out;
  }

  function refresh() {
    var conditional = form.querySelectorAll('[data-cond-q]');
    for (var i = 0; i < conditional.length; i++) {
      var box = conditional[i];
      var vals = valuesOf(box.getAttribute('data-cond-q'));
      var eq = box.getAttribute('data-cond-eq');
      var inc = box.getAttribute('data-cond-inc');
      var show = eq !== null ? vals.indexOf(eq) !== -1 : vals.indexOf(inc) !== -1;
      box.hidden = !show;
      var note = box.querySelector('[id^="cond-"]');
      if (note) note.hidden = true; // a nota textual só é necessária sem JavaScript
    }
  }

  form.addEventListener('change', refresh);
  refresh();

  var summary = document.getElementById('pc-error-summary');
  if (summary) summary.focus();
})();
