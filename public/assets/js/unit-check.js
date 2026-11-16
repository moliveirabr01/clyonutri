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

    const nutritionBox = scope.querySelector('[data-unit-nutrition]');
    const measuresBox = box.querySelector('[data-unit-measures]');
    const setState = (text, needsConfirm, invalid, nutrition) => {
      msg.textContent = text;
      box.hidden = !text && !nutrition && (!measuresBox || measuresBox.hidden);
      confirmLabel.hidden = !needsConfirm;
      confirm.required = !!needsConfirm;
      if (!needsConfirm) confirm.checked = false;
      syncConfirm();
      box.classList.toggle('unit-check--warn', !!needsConfirm);
      box.classList.toggle('unit-check--error', !!invalid);
      unit.setCustomValidity(invalid ? text : '');
      if (nutritionBox) {
        if (nutrition && nutrition.energy_kcal !== undefined && !invalid) {
          nutritionBox.hidden = false;
          nutritionBox.innerHTML = '<strong>' + fmt(nutrition.energy_kcal) + ' kcal</strong> · P ' + fmt(nutrition.protein_g) + ' g · C ' + fmt(nutrition.carbohydrate_g) + ' g · G ' + fmt(nutrition.lipid_g) + ' g <span style="opacity:0.85;">(' + fmt(nutrition.grams) + ' g)</span>';
        } else {
          nutritionBox.hidden = true;
          nutritionBox.innerHTML = '';
        }
      }
    };

    // NI2-609: lista as medidas do alimento com a origem do peso; sem medida, oferece o cadastro (com fonte).
    function showMeasures(measures, registerUrl) {
      if (!measuresBox) return;
      measuresBox.textContent = '';
      if (measures.length) {
        measuresBox.append('Medidas cadastradas: ');
        measures.forEach((m, i) => {
          if (i) measuresBox.append('; ');
          const b = document.createElement('strong'); b.textContent = '1 ' + m.name.replace(/\s*\(.*\)\s*$/, '') + ' = ' + fmt(m.grams) + ' g';
          measuresBox.append(b, ' (' + (m.source_label || 'medida cadastrada') + ')');
        });
        measuresBox.append('. ');
      } else {
        measuresBox.append('Este alimento não tem medida caseira cadastrada (unidade, fatia…): use gramas ou ml. ');
      }
      if (registerUrl) {
        const a = document.createElement('a');
        a.href = registerUrl; a.target = '_blank'; a.rel = 'noopener'; a.dataset.testid = 'unit-register-measure';
        a.textContent = measures.length ? 'Gerenciar medidas ↗' : 'Cadastrar medida caseira ↗';
        measuresBox.append(a);
      }
      measuresBox.hidden = false;
    }

    function fillMeasures(id, measures, registerUrl) {
      if (unit.tagName !== 'SELECT' || measuresFor === id) return;
      measuresFor = id;
      showMeasures(measures, registerUrl);
      unit.querySelector('optgroup[data-unit-food]')?.remove();
      if (!measures.length) return;
      const group = document.createElement('optgroup');
      group.label = 'Medidas deste alimento'; group.dataset.unitFood = '';
      measures.forEach((m) => {
        const text = m.name.includes('(') && (m.name.includes('g)') || m.name.includes(' g)'))
          ? m.name
          : m.name + ' (' + fmt(m.grams) + ' g)';
        const o = new Option(text, m.name);
        if (m.source_label) o.title = m.source_label;
        group.append(o);
      });
      unit.prepend(group);
    }

    async function check() {
      const id = foodId();
      if (!id || !unit.value) { setState('', false, false, null); return; }
      const mySeq = ++seq; controller?.abort(); controller = new AbortController();
      try {
        const q = '?amount=' + encodeURIComponent(amount.value || '1') + '&unit=' + encodeURIComponent(unit.value);
        const res = await fetch('/catalogo/alimentos/' + encodeURIComponent(id) + '/conversao' + q, { signal: controller.signal, headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('conversao');
        const data = await res.json(); if (mySeq !== seq) return;
        fillMeasures(id, data.measures || [], data.register_measure_url || '');
        if (!data.ok) { setState(data.message, false, true, null); return; }
        if (data.needs_confirmation) { setState(data.message, true, false, data.nutrition || null); return; }
        const origin = data.source === 'mass' ? '' : ' (' + (data.source_label || (data.source === 'food_serving' ? 'porção cadastrada do alimento' : 'medida cadastrada para o alimento')) + ')';
        // Reteste UX v54.62 (R-001): porção acima de 1 kg ou 1.000 kcal pede conferência antes de incluir (não bloqueia).
        const big = Number(data.grams) > 1000 || Number(data.nutrition && data.nutrition.energy_kcal) > 1000;
        setState('= ' + fmt(data.grams) + ' g' + origin + (big ? '. Confira a quantidade: porção maior que o comum para uma refeição.' : ''), false, false, data.nutrition || null);
        box.classList.toggle('unit-check--warn', big);
        box.toggleAttribute('data-unit-check-big', big);
      } catch (e) {
        if (e.name !== 'AbortError' && mySeq === seq) setState('', false, false, null); // o servidor confere ao salvar
      }
    }
    const later = () => { clearTimeout(timer); timer = setTimeout(check, 250); };
    unit.addEventListener('change', check); unit.addEventListener('input', later);
    amount.addEventListener('input', later);
    foodSelect?.addEventListener('change', () => { measuresFor = null; if (measuresBox && !foodId()) measuresBox.hidden = true; check(); });
    setState('', false, false);
    if (foodId()) check();
  }
  window.ClyoUnitCheck = { init };
  document.querySelectorAll('[data-unit-scope]').forEach(init);
})();
