(() => {
  // NI2-73: init(box) também serve a linhas criadas depois (ingredientes da receita).
  function initFoodPicker(box) {
    if (!box || box.dataset.foodPickerReady === '1') return;
    box.dataset.foodPickerReady = '1';
    const input = box.querySelector('[data-food-query]'), select = box.querySelector('select');
    const status = box.querySelector('[role=status]'), next = box.querySelector('[data-food-next]'), retry = box.querySelector('[data-food-retry]');
    const results = document.createElement('div'), chosen = document.createElement('p');
    const globalAlert=document.createElement('p'); globalAlert.className='food-picker-global-alert op-message op-message--warning';globalAlert.setAttribute('role','status');globalAlert.hidden=true;input.after(globalAlert);
    const isGlobalAlert=alert => alert && alert.status === 'allergy_not_evaluated';
    results.className = 'food-search-results'; results.setAttribute('role','group'); results.setAttribute('aria-label', 'Alimentos encontrados');
    chosen.className = 'food-selection'; chosen.hidden = true;
    select.before(results, chosen); select.hidden = true; select.required = false;
    box.querySelector('[data-food-results-label]').hidden = true;
    input.required = true;
    const validate = () => input.setCustomValidity(select.value ? '' : 'Selecione um alimento nos resultados da busca.');
    validate();
    let page = 1, timer, controller, sequence = 0, started = false, foods = [];
    // NI2-415 (UX-03): fonte, tipo (catálogo x personalizado da clínica), categoria, composição por 100 g e código
    const norm = v => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    const fmt = v => Number(v || 0).toLocaleString('pt-BR', {maximumFractionDigits: 1});
    // UX-08 (v14): itens equivalentes (mesmo nome normalizado + mesma composição; chave vinda do servidor)
    // aparecem como um só resultado, com os demais cadastros em "ver equivalentes". Só apresentação:
    // cada botão continua selecionando o id original (planos existentes não mudam).
    const refOf = food => food.reference || ((food.is_custom ? 'Cadastro da clínica nº ' : 'Código ') + food.id);
    // NI2-520 (v43): a origem mostra fonte e versão (ex.: TACO 4ª ed.); cadastros da clínica seguem identificados.
    const originOf = food => (food.is_custom ? 'Personalizado da sua clínica' : (food.source_label ? 'Fonte: ' + food.source_label : ('Catálogo ' + (food.source || '')))).trim();
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
    const post = (url, body) => fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(body || {}) });
    let lastChosen = null;
    // NI2-520 (v43): métrica de seleção. Trocar a escolha na mesma busca conta como correção (seleção incorreta).
    function recordSelection(food) {
      const term = input.value.trim();
      if (!term) return;
      const event = lastChosen !== null && lastChosen !== String(food.id) ? 'corrected' : 'selected';
      lastChosen = String(food.id);
      post('/catalogo/alimentos/selecao', { term, food_id: food.id, event }).catch(() => {});
    }
    function updateAllergenBox(food) {
      const form = box.closest?.('form');
      const alertBox = form?.querySelector?.('[data-allergen-item-alert]');
      if (!alertBox) return;
      alertBox.replaceChildren();
      const alert = food && food.allergen_alert ? food.allergen_alert : null;
      if (!alert || !alert.requires_attention || isGlobalAlert(alert)) {
        alertBox.hidden = true;
        return;
      }
      alertBox.hidden = false;
      alertBox.classList.add('op-message');alertBox.classList.remove('op-message--critical','op-message--warning');
      alertBox.classList.add(alert.status==='conflict'?'op-message--critical':'op-message--warning');
      alertBox.setAttribute('role',alert.status==='conflict'?'alert':'status');
      const heading=document.createElement('strong');heading.textContent=alert.status==='conflict'?'Alerta clínico — conflito alergênico / restrição':'Atenção — revisão clínica necessária';alertBox.append(heading);
      alertBox.dataset.allergenStatus = alert.status || '';
      const msg = document.createElement('p');
      msg.className = 'u-mt-0 u-mb-2 u-font-semibold';
      msg.textContent = alert.message || 'Atenção clínica: verifique alergias e restrições antes de prescrever.';
      alertBox.append(msg);
      if(alert.provenance){const provenance=document.createElement('p');provenance.textContent=alert.provenance;alertBox.append(provenance);}
      const patientId=box.dataset.patientId||box.closest('[data-patient-id]')?.dataset.patientId;
      const contexts=[...document.querySelectorAll('[data-clinical-context]')];
      const panel=box.closest('[data-dialog=modal]')?.querySelector('[data-clinical-context]')||contexts.find(el=>patientId?el.dataset.patientId===String(patientId):true);
      if(panel){const source=document.createElement('p');source.textContent='Fontes clínicas registradas: '+panel.dataset.clinicalSourceLabel;const link=document.createElement('a');link.href='#'+panel.id;link.dataset.openClinicalContext='';link.textContent='Conferir origem e revisão clínica';source.append(' ',link);alertBox.append(source);}

      if (alert.status === 'conflict') {
        const gate = document.createElement('input');
        gate.type = 'hidden'; gate.name = 'enforce_allergen_gate'; gate.value = '1';
        const lbl = document.createElement('label');
        lbl.className = 'u-flex u-gap-2 u-items-start u-text-compact u-mb-2';
        const chk = document.createElement('input');
        chk.type = 'checkbox'; chk.name = 'confirm_allergen_conflict'; chk.value = '1'; chk.required = true;
        const span = document.createElement('span');
        span.textContent = 'Confirmo ciência do conflito alergênico / restrição e desejo adicionar este alimento com justificativa clínica.';
        lbl.append(chk, span);
        const reasonInput = document.createElement('input');
        reasonInput.type = 'text'; reasonInput.name = 'allergen_ack_reason'; reasonInput.required = true; reasonInput.minLength = 10;
        reasonInput.className = 'form-control';
        reasonInput.placeholder = 'Justificativa clínica obrigatória (mín. 10 caracteres)...';
        const reasonLabel=document.createElement('label');reasonLabel.textContent='Justificativa clínica';reasonLabel.append(reasonInput);alertBox.append(gate,lbl,reasonLabel);
      }
    }
    function choose(food) {
      if (!foods.some(item => String(item.id) === String(food.id))) { foods.push(food); select.add(new Option(food.name, food.id)); }
      select.value = String(food.id); validate(); render();
      select.dispatchEvent(new Event('change', { bubbles: true })); // NI2-74: unit-check.js confere a unidade do alimento
      chosen.textContent = 'Selecionado: ' + food.name + ' (' + refOf(food) + ')'; chosen.hidden = false;
      updateAllergenBox(food);
      // NI2-519 (v42): avisa a tela (plan-live.js) da energia por 100 g do alimento escolhido.
      box.dispatchEvent(new CustomEvent('clyo:food-chosen', { bubbles: true, detail: { id: food.id, name: food.name, energy_kcal: Number(food.energy_kcal), allergen_alert: food.allergen_alert || null } }));
    }
    // NI2-519 (v43): sugestões de troca escolhem o alimento pelo mesmo caminho do clique no resultado.
    box.addEventListener('clyo:choose-food', ev => { if (ev.detail && ev.detail.id) choose(ev.detail); });
    function favoriteButton(food) {
      const fav = document.createElement('button'); fav.type = 'button'; fav.className = 'food-result__favorite';
      const paint = on => { fav.setAttribute('aria-pressed', String(on)); fav.textContent = on ? '★ Favorito da clínica' : '☆ Marcar como favorito'; fav.setAttribute('aria-label',fav.textContent+' — '+food.name); };
      paint(!!food.is_favorite);
      fav.addEventListener('click', async () => {
        fav.disabled = true;
        try {
          const r = await post('/catalogo/alimentos/' + encodeURIComponent(food.id) + '/favorito');
          if (!r.ok) throw Error('falha');
          const d = await r.json(); food.is_favorite = !!d.favorite; paint(food.is_favorite);
          status.textContent = food.is_favorite ? food.name + ' agora aparece antes nas buscas da clínica.' : food.name + ' saiu dos favoritos.';
        } catch (e) { status.textContent = 'Não foi possível atualizar o favorito. Tente novamente.'; }
        finally { fav.disabled = false; }
      });
      return fav;
    }
    const expanded = new Set();
    function foodButton(food, extra) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'food-result' + (extra ? ' food-result--equivalent' : '');
      button.setAttribute('aria-pressed', String(select.value === String(food.id)));
      button.dataset.foodId = String(food.id);
      const name = document.createElement('strong'), meta = document.createElement('span'), energy = document.createElement('span');
      name.textContent = food.name;
      const badge = document.createElement('span'); badge.className = 'food-result__badge' + (food.is_custom ? ' food-result__badge--custom' : ''); badge.textContent = originOf(food);
      meta.className = 'food-result__meta'; meta.dataset.reference = refOf(food);
      meta.textContent = [food.category, refOf(food)].filter(Boolean).join(' · ');
      energy.textContent = fmt(food.energy_kcal) + ' kcal · P ' + fmt(food.protein_g) + ' g · C ' + fmt(food.carbohydrate_g) + ' g · G ' + fmt(food.lipid_g) + ' g por 100 g';
      // NI2-73: porção de referência e medidas caseiras cadastradas (ou o aviso de que não há).
      const portion = document.createElement('span'); portion.className = 'food-result__portion';
      portion.textContent = (food.portion && food.portion.label) || 'Valores por 100 g';
      const parts = [name, badge];
      // NI2-520 (v42): forma de preparo (cru, cozido, grelhado...) visível no resultado, quando o nome do catálogo informa.
      // NI2-520 (v43): sem preparo no nome, o resultado diz isso em vez de omitir (evita confundir cru e cozido).
      const prepText = food.preparation || food.preparation_label || '';
      if (prepText) { const prep = document.createElement('span'); prep.className = 'food-result__badge food-result__badge--prep' + (food.preparation ? '' : ' food-result__badge--muted'); prep.textContent = prepText; parts.push(prep); }
      // v44: o nome não tem o termo digitado e o alimento veio pelo sinônimo regional ("aipim = mandioca").
      if (food.synonym_match) { const syn = document.createElement('span'); syn.className = 'food-result__used'; syn.textContent = 'Por sinônimo: ' + food.synonym_match; parts.push(syn); }
      if (food.is_favorite) { const f = document.createElement('span'); f.className = 'food-result__used'; f.textContent = 'Favorito da clínica'; parts.push(f); }
      parts.push(meta, energy, portion);
      // NI2-520: alimento que a própria clínica já usou em planos sobe na ordem; o selo explica por quê.
      if (Number(food.clinic_uses) > 0) { const used = document.createElement('span'); used.className = 'food-result__used'; used.textContent = 'Já usado em planos da clínica'; parts.push(used); }
      // NI2-525: alerta contextual de alérgenos / restrições no resultado da busca
      if (food.allergen_alert && food.allergen_alert.requires_attention && !isGlobalAlert(food.allergen_alert)) {
        const alBadge = document.createElement('span');
        alBadge.className = 'food-result__allergen food-result__allergen--' + (food.allergen_alert.status || 'warning');
        alBadge.textContent = food.allergen_alert.status === 'conflict'
          ? '⚠ Conflito alergênico / restrição (' + ([...(food.allergen_alert.matched_allergens || []), ...(food.allergen_alert.restriction_matches || [])].join(', ') || 'verifique anamnese') + ')'
          : (food.allergen_alert.status === 'unverified' ? '⚠ Alérgenos não verificados no catálogo' : '⚠ Alergias não avaliadas na anamnese');
        parts.push(alBadge);
      }
      button.append(...parts);
      // Visible result text includes its provenance and clinical warning in the accessible name.
      button.addEventListener('click', () => { recordSelection(food); choose(food); });
      return button;
    }
    function render() {
      results.replaceChildren();
      const unknown=foods.some(food=>isGlobalAlert(food.allergen_alert));
      globalAlert.hidden=!unknown;
      globalAlert.textContent=unknown?'⚠ Alergias não avaliadas na anamnese. Confira o estado clínico antes de prescrever.':'';
      const groups = new Map(), nameKeys = {};
      foods.forEach(food => {
        const key = food.equivalence_key || ('id:' + food.id);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(food);
      });
      groups.forEach((items, key) => { const n = norm(String(items[0].name).replace(/\btaco\b/gi, '')); (nameKeys[n] = nameKeys[n] || new Set()).add(key); });
      groups.forEach((items, key) => {
        // Principal: o que tem nº TACO; senão o de menor id. Se o selecionado for um equivalente, ele vira o principal.
        items.sort((a, b) => (b.taco_code != null) - (a.taco_code != null) || a.id - b.id);
        const selIdx = items.findIndex(f => String(f.id) === select.value);
        if (selIdx > 0) items.unshift(items.splice(selIdx, 1)[0]);
        const wrap = document.createElement('div'); wrap.className = 'food-result-group';
        const main = foodButton(items[0], false); wrap.append(main);
        if (!items[0].is_custom) wrap.append(favoriteButton(items[0]));
        const n = norm(String(items[0].name).replace(/\btaco\b/gi, ''));
        if (nameKeys[n] && nameKeys[n].size > 1) {
          const dup = document.createElement('span'); dup.className = 'food-result__dup';
          dup.textContent = 'Mesmo nome com composição diferente: compare a origem e os valores antes de escolher.';
          main.append(dup); // Visible duplication warning remains part of the accessible name.
        }
        if (items.length > 1) {
          const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'food-result__equivalents';
          const open = expanded.has(key);
          toggle.setAttribute('aria-expanded', String(open));
          toggle.textContent = (open ? 'Ocultar' : 'Ver') + ' ' + (items.length - 1) + (items.length === 2 ? ' cadastro equivalente' : ' cadastros equivalentes') + ' (mesmo nome e composição: ' + items.slice(1).map(refOf).join('; ') + ')';
          toggle.addEventListener('click', () => { open ? expanded.delete(key) : expanded.add(key); render(); });
          wrap.append(toggle);
          if (open) items.slice(1).forEach(f => wrap.append(foodButton(f, true)));
        }
        results.append(wrap);
      });
    }
    box.closest?.('form')?.addEventListener('clyo:discard', () => {
      controller?.abort(); sequence++; page = 1; foods = []; started = false; lastChosen = null;
      select.replaceChildren(new Option('Selecione um alimento', '')); chosen.hidden = true; next.hidden = true; retry.hidden = true;
      render(); validate(); status.textContent = '';
    });
    async function load(append = false) {
      started = true; next.hidden = true; retry.hidden = true;
      const seq = ++sequence; controller?.abort(); controller = new AbortController();
      status.textContent = 'Buscando alimentos…'; box.setAttribute('aria-busy', 'true');
      try {
        const planCtx = box.dataset?.planId || box.closest?.('[data-plan-id]')?.dataset?.planId || (typeof window !== 'undefined' && window.location?.pathname?.match(/^\/planos\/(\d+)/)?.[1]) || '';
        const patientCtx = box.dataset?.patientId || box.closest?.('[data-patient-id]')?.dataset?.patientId || '';
        const ctxParams = (planCtx ? '&plan_id=' + encodeURIComponent(planCtx) : '') + (patientCtx ? '&patient_id=' + encodeURIComponent(patientCtx) : '');
        const response = await fetch('/catalogo/alimentos/busca?q=' + encodeURIComponent(input.value) + '&page=' + page + ctxParams, {signal: controller.signal, headers: {Accept: 'application/json'}});
        if (response.status === 403) { const e = Error('sem acesso'); e.name = 'ForbiddenError'; throw e; }
        if (!response.ok) throw Error('Falha na busca');
        const data = await response.json(); if (seq !== sequence) return;
        if (!append) { foods = []; select.replaceChildren(new Option('Selecione um alimento', '')); }
        data.data.forEach(food => { if (!foods.some(item => String(item.id) === String(food.id))) { foods.push(food); select.add(new Option(food.name, food.id)); } });
        render(); validate();
        const total = Number.isFinite(data.total) ? data.total : foods.length;
        status.textContent = foods.length
          ? (total === 1 ? '1 alimento encontrado.' : (foods.length < total ? 'Mostrando ' + foods.length + ' de ' + total + ' alimentos.' : total + ' alimentos encontrados.')) + ' Clique para selecionar.'
          : 'Nenhum alimento encontrado. Tente outro nome ou menos palavras.';
        next.hidden = !data.has_more;
      } catch (error) {
        if (error.name === 'ForbiddenError' && seq === sequence) { status.textContent = 'Seu perfil não tem acesso ao catálogo de alimentos. Peça a uma nutricionista da clínica.'; return; }
        if (error.name !== 'AbortError' && seq === sequence) { status.textContent = 'Não foi possível buscar alimentos. Tente novamente.'; retry.hidden = false; }
      } finally { if (seq === sequence) box.setAttribute('aria-busy', 'false'); }
    }
    input.addEventListener('focus', () => { if (!started) load(); });
    input.addEventListener('input', () => {
      clearTimeout(timer); controller?.abort(); sequence++; page = 1; foods = [];
      select.replaceChildren(new Option('Selecione um alimento', '')); chosen.hidden = true; next.hidden = true; retry.hidden = true;
      render(); validate(); status.textContent = 'Buscando alimentos…'; timer = setTimeout(() => load(), 250);
    });
    next.addEventListener('click', () => { page++; load(true); });
    retry.addEventListener('click', () => load(page > 1));
    // Teclado: seta para baixo do campo vai ao primeiro resultado; setas percorrem os botões (resultados,
    // equivalentes); seta para cima no primeiro volta ao campo. Os resultados ficam dentro de grupos
    // (div), por isso o foco vai sempre para os botões, não para o primeiro filho.
    const resultButtons = () => [...results.querySelectorAll('button')];
    input.addEventListener('keydown', event => {
      const first = resultButtons()[0];
      if (event.key === 'ArrowDown' && first) { event.preventDefault(); first.focus(); }
    });
    results.addEventListener('keydown', event => {
      if (event.key === 'Escape') { input.focus(); return; }
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
      const list = resultButtons(), i = list.indexOf(document.activeElement);
      if (i < 0) return;
      event.preventDefault();
      if (event.key === 'ArrowDown') (list[i + 1] || list[i]).focus();
      else (i === 0 ? input : list[i - 1]).focus();
    });
  }
  window.ClyoFoodPicker = { init: initFoodPicker };
  document.querySelectorAll('[data-food-picker]').forEach(initFoodPicker);
  // NI2-417 (UX-05): os diálogos de alimento usam o componente compartilhado (clinical-journeys.js)
  window.openFoodDialog = id => {
    const modal = window.ClyoDialog ? window.ClyoDialog.open(id) : document.getElementById(id);
    if (!window.ClyoDialog) modal.style.display = 'flex';
    setTimeout(() => modal.querySelector('[data-food-query]').focus(), 0);
  };
})();
