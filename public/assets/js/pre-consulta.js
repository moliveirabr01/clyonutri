(function () {
  'use strict';
  var form = document.querySelector('[data-pc-form]'); if (!form) return;
  function valueOf(key) {
    var fields = Array.from(form.elements).filter(function (el) { return el.name === 'answers[' + key + ']' || el.name === 'answers[' + key + '][]'; });
    var vals = fields.filter(function (el) { return !el.closest('[data-condition][hidden]') && (!['radio','checkbox'].includes(el.type) || el.checked); }).map(function (el) { return el.value; }).filter(Boolean);
    return fields.some(function (el) { return el.type === 'checkbox'; }) ? vals : (vals[0] || null);
  }
  function holds(c) {
    if (c.all) return c.all.every(holds);
    if (c.any) return c.any.some(holds);
    var a = valueOf(c.question), empty = a === null || a === '' || (Array.isArray(a) && a.length === 0);
    if ('answered' in c) return c.answered ? !empty : empty;
    if (empty) return false;
    if ('equals' in c) return String(a) === String(c.equals);
    if ('in' in c) return c.in.map(String).includes(String(a));
    if ('includes' in c) return (Array.isArray(a) ? a : [a]).map(String).includes(String(c.includes));
    if (!Number.isFinite(Number(a))) return false;
    if ('gt' in c) return Number(a) > Number(c.gt);
    if ('gte' in c) return Number(a) >= Number(c.gte);
    if ('lt' in c) return Number(a) < Number(c.lt);
    if ('lte' in c) return Number(a) <= Number(c.lte);
    return false;
  }
  function refresh() {
    form.querySelectorAll('[data-condition]').forEach(function (box) {
      box.hidden = !holds(JSON.parse(box.dataset.condition));
      var note = box.querySelector('[id^="cond-"]'); if (note) note.hidden = true;
    });
  }
  form.addEventListener('change', refresh); refresh();
  var steps = Array.from(form.querySelectorAll('[data-pc-step]')), nav = form.querySelector('[data-pc-navigation]'), current = 0;
  function showStep(i, focus) {
    current = i; steps.forEach(function (s,j) { s.hidden = j !== i; });
    nav.querySelector('[data-pc-progress]').textContent = 'Etapa ' + (i + 1) + ' de ' + steps.length;
    nav.querySelector('[data-pc-prev]').disabled = i === 0;
    nav.querySelector('[data-pc-next]').disabled = i === steps.length - 1;
    if (focus) { var heading = steps[i].querySelector('h2'); heading.tabIndex = -1; heading.focus(); }
  }
  if (nav && steps.length > 1) {
    nav.hidden = false;
    nav.querySelector('[data-pc-prev]').addEventListener('click', function () { showStep(current - 1,true); });
    nav.querySelector('[data-pc-next]').addEventListener('click', function () { showStep(current + 1,true); });
    form.querySelectorAll('[name="action"]').forEach(function (b) { b.closest('section').removeAttribute('data-pc-step'); });
    steps = Array.from(form.querySelectorAll('[data-pc-step]'));
    var firstError = form.querySelector('.pc-has-error');
    if (firstError) { var idx = steps.indexOf(firstError.closest('[data-pc-step]')); if (idx >= 0) current = idx; }
    showStep(current,false);
    document.querySelectorAll('.pc-error-summary a').forEach(function (a) { a.addEventListener('click', function () { var target = document.getElementById(a.hash.slice(1)), idx = target ? steps.indexOf(target.closest('[data-pc-step]')) : -1; if (idx >= 0) showStep(idx,false); }); });
  }
  var dirty = false;
  form.addEventListener('input',function () { dirty = true; });
  form.addEventListener('submit',function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  var summary = document.getElementById('pc-error-summary'); if (summary) summary.focus();
})();
