(function () {
  'use strict';
  var form = document.querySelector('[data-protocol-editor]');
  if (!form) return;
  var list = form.querySelector('[data-question-list]');
  function reindex() {
    list.querySelectorAll('[data-editor-question]').forEach(function (box, i) {
      var key = box.querySelector('[data-question-key]');
      if (key.value.includes('__INDEX__')) key.value = 'pergunta_' + Date.now() + '_' + i;
      box.querySelector('legend').textContent = 'Pergunta ' + (i + 1);
      box.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/questions\[[^\]]+\]/, 'questions[' + i + ']'); });
    });
    var previous = [];
    list.querySelectorAll('[data-editor-question]').forEach(function (box) {
      var select = box.querySelector('[data-dependency]'), selected = select.value || select.dataset.selected;
      select.replaceChildren(new Option('Sempre exibir',''));
      previous.forEach(function (q) { select.add(new Option(q.label,q.key)); });
      select.value = selected || ''; select.dataset.selected = select.value;
      previous.push({key:box.querySelector('[data-question-key]').value, label:box.querySelector('[name$="[text]"]').value || box.querySelector('legend').textContent});
    });
  }
  form.addEventListener('change', reindex);
  form.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    if (b.hasAttribute('data-add-question')) {
      list.appendChild(document.getElementById('protocol-question-template').content.cloneNode(true));
      reindex(); list.lastElementChild.querySelector('input').focus(); return;
    }
    var box = b.closest('[data-editor-question]'); if (!box) return;
    if (b.hasAttribute('data-move-up') && box.previousElementSibling) list.insertBefore(box, box.previousElementSibling);
    if (b.hasAttribute('data-move-down') && box.nextElementSibling) list.insertBefore(box.nextElementSibling, box);
    if (b.hasAttribute('data-remove-question')) { box.remove(); form.querySelector('[data-add-question]').focus(); }
    reindex();
  });
  reindex();
})();
