// NI2-74: mostra quanto pesa a medida escolhida para o alimento e exige confirmação quando o peso é só o padrão
// (medida sem peso cadastrado para o alimento). A regra é a do servidor: GET /catalogo/alimentos/{id}/conversao.
(() => {
  const fmt = (v) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  function init(scope) {
    if (!scope || scope.dataset.unitCheckReady === '1') return;
    const box = scope.querySelector('[data-unit-check]');
    const unit = scope.querySelector('[name$="measure_unit"], [name$="[measure_unit]"]');
    const amount = scope.querySelector('[name$="amount"], [name$="[amount]"]');
    if (!box || !unit || !amount) return;
    scope.dataset.unitCheckReady = '1';
    const msg = box.querySelector('[data-unit-check-msg]');
    const confirmLabel = box.querySelector('[data-unit-check-confirm]');
    const confirm = confirmLabel.querySelector('input[type=checkbox]');
    const foodSelect = scope.querySelector('select[name$="food_id"], select[name$="[food_id]"]');
    const foodId = () => (foodSelect && foodSelect.value) || scope.dataset.foodId || '';
    let timer, controller, seq = 0, measuresFor = null;
    const syncConfirm = () => {
      confirm.setCustomValidity(confirm.required && !confirm.checked ? 'Marque «Confirmo o peso estimado» ou use gramas/uma medida cadastrada.' : '');
      if (confirm.checkValidity() && confirm.getAttribute('aria-invalid') === 'true') { // limpa o aviso do campo (clinical-journeys.js)
        confirm.removeAttribute('aria-invalid'); const m = document.getElementById(confirm.id + '-erro'); if (m) m.textContent = '';
      }
    };
    confirm.addEventListener('change', syncConfirm);

    const setState = (text, needsConfirm, invalid) => {
      msg.textContent = text;
      box.hidden = !text;
      confirmLabel.hidden = !needsConfirm;
      confirm.required = !!needsConfirm;
      if (!needsConfirm) confirm.checked = false;
      syncConfirm();
      box.classList.toggle('unit-check--warn', !!needsConfirm);
      box.classList.toggle('unit-check--error', !!invalid);
      unit.setCustomValidity(invalid ? text : '');
    };

    function fillMeasures(id, measures) {
      if (unit.tagName !== 'SELECT' || measuresFor === id) return;
      measuresFor = id;
      unit.querySelector('optgroup[data-unit-food]')?.remove();
      if (!measures.length) return;
      const group = document.createElement('optgroup');
      group.label = 'Medidas deste alimento'; group.dataset.unitFood = '';
      measures.forEach((m) => { const o = new Option(m.name + ' (' + fmt(m.grams) + ' g)', m.name); group.append(o); });
      unit.prepend(group);
    }

    async function check() {
      const id = foodId();
      if (!id || !unit.value) { setState('', false, false); return; }
      const mySeq = ++seq; controller?.abort(); controller = new AbortController();
      try {
        const q = '?amount=' + encodeURIComponent(amount.value || '1') + '&unit=' + encodeURIComponent(unit.value);
        const res = await fetch('/catalogo/alimentos/' + encodeURIComponent(id) + '/conversao' + q, { signal: controller.signal, headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('conversao');
        const data = await res.json(); if (mySeq !== seq) return;
        fillMeasures(id, data.measures || []);
        if (!data.ok) { setState(data.message, false, true); return; }
        if (data.needs_confirmation) { setState(data.message, true, false); return; }
        const origin = data.source === 'mass' ? '' : data.source === 'food_serving' ? ' (porção cadastrada do alimento)' : ' (medida cadastrada para o alimento)';
        setState('= ' + fmt(data.grams) + ' g' + origin, false, false);
      } catch (e) {
        if (e.name !== 'AbortError' && mySeq === seq) setState('', false, false); // o servidor confere ao salvar
      }
    }
    const later = () => { clearTimeout(timer); timer = setTimeout(check, 250); };
    unit.addEventListener('change', check); unit.addEventListener('input', later);
    amount.addEventListener('input', later);
    foodSelect?.addEventListener('change', () => { measuresFor = null; check(); });
    setState('', false, false);
    if (foodId()) check();
  }
  window.ClyoUnitCheck = { init };
  document.querySelectorAll('[data-unit-scope]').forEach(init);
})();
