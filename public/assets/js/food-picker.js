(() => {
  document.querySelectorAll('[data-food-picker]').forEach(box => {
    const input = box.querySelector('[data-food-query]'), select = box.querySelector('select');
    const status = box.querySelector('[role=status]'), next = box.querySelector('[data-food-next]'), retry = box.querySelector('[data-food-retry]');
    const results = document.createElement('div'), chosen = document.createElement('p');
    results.className = 'food-search-results'; results.setAttribute('aria-label', 'Alimentos encontrados');
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
    const originOf = food => (food.is_custom ? 'Personalizado da sua clínica' : ('Catálogo ' + (food.source || ''))).trim();
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
      button.append(name, badge, meta, energy);
      button.setAttribute('aria-label', food.name + ', ' + originOf(food) + ', ' + meta.textContent + ', ' + energy.textContent);
      button.addEventListener('click', () => {
        select.value = String(food.id); validate(); render();
        chosen.textContent = 'Selecionado: ' + food.name + ' (' + refOf(food) + ')'; chosen.hidden = false;
      });
      return button;
    }
    function render() {
      results.replaceChildren();
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
        const n = norm(String(items[0].name).replace(/\btaco\b/gi, ''));
        if (nameKeys[n] && nameKeys[n].size > 1) {
          const dup = document.createElement('span'); dup.className = 'food-result__dup';
          dup.textContent = 'Mesmo nome com composição diferente: compare a origem e os valores antes de escolher.';
          main.append(dup); main.setAttribute('aria-label', main.getAttribute('aria-label') + ', mesmo nome com composição diferente');
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
      controller?.abort(); sequence++; page = 1; foods = []; started = false;
      select.replaceChildren(new Option('Selecione um alimento', '')); chosen.hidden = true; next.hidden = true; retry.hidden = true;
      render(); validate(); status.textContent = '';
    });
    async function load(append = false) {
      started = true; next.hidden = true; retry.hidden = true;
      const seq = ++sequence; controller?.abort(); controller = new AbortController();
      status.textContent = 'Buscando alimentos…'; box.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch('/catalogo/alimentos/busca?q=' + encodeURIComponent(input.value) + '&page=' + page, {signal: controller.signal, headers: {Accept: 'application/json'}});
        if (!response.ok) throw Error('Falha na busca');
        const data = await response.json(); if (seq !== sequence) return;
        if (!append) { foods = []; select.replaceChildren(new Option('Selecione um alimento', '')); }
        data.data.forEach(food => { if (!foods.some(item => String(item.id) === String(food.id))) { foods.push(food); select.add(new Option(food.name, food.id)); } });
        render(); validate();
        status.textContent = foods.length ? (foods.length === 1 ? '1 alimento encontrado.' : foods.length + ' alimentos encontrados.') + ' Clique para selecionar.' : 'Nenhum alimento encontrado. Tente outro nome.';
        next.hidden = !data.has_more;
      } catch (error) {
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
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
      const list = resultButtons(), i = list.indexOf(document.activeElement);
      if (i < 0) return;
      event.preventDefault();
      if (event.key === 'ArrowDown') (list[i + 1] || list[i]).focus();
      else (i === 0 ? input : list[i - 1]).focus();
    });
  });
  // NI2-417 (UX-05): os diálogos de alimento usam o componente compartilhado (clinical-journeys.js)
  window.openFoodDialog = id => {
    const modal = window.ClyoDialog ? window.ClyoDialog.open(id) : document.getElementById(id);
    if (!window.ClyoDialog) modal.style.display = 'flex';
    setTimeout(() => modal.querySelector('[data-food-query]').focus(), 0);
  };
})();
