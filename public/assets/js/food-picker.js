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
    function render() {
      results.replaceChildren();
      const counts = {};
      foods.forEach(food => { const k = norm(food.name); counts[k] = (counts[k] || 0) + 1; });
      foods.forEach(food => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'food-result';
        button.setAttribute('aria-pressed', String(select.value === String(food.id)));
        const name = document.createElement('strong'), meta = document.createElement('span'), energy = document.createElement('span');
        name.textContent = food.name;
        const origin = food.is_custom ? 'Personalizado da sua clínica' : ('Catálogo ' + (food.source || ''));
        const badge = document.createElement('span'); badge.className = 'food-result__badge' + (food.is_custom ? ' food-result__badge--custom' : ''); badge.textContent = origin.trim();
        meta.className = 'food-result__meta';
        meta.textContent = [food.category, 'Código ' + food.id].filter(Boolean).join(' · ');
        energy.textContent = fmt(food.energy_kcal) + ' kcal · P ' + fmt(food.protein_g) + ' g · C ' + fmt(food.carbohydrate_g) + ' g · G ' + fmt(food.lipid_g) + ' g por 100 g';
        button.append(name, badge, meta, energy);
        if (counts[norm(food.name)] > 1) {
          const dup = document.createElement('span'); dup.className = 'food-result__dup';
          dup.textContent = 'Nome repetido no catálogo: compare código e composição antes de escolher.';
          button.append(dup);
        }
        button.setAttribute('aria-label', food.name + ', ' + origin + ', ' + meta.textContent + ', ' + energy.textContent + (counts[norm(food.name)] > 1 ? ', nome repetido no catálogo' : ''));
        button.addEventListener('click', () => {
          select.value = String(food.id); validate(); render();
          chosen.textContent = 'Selecionado: ' + food.name + ' (' + origin.trim() + ', código ' + food.id + ')'; chosen.hidden = false;
        });
        results.append(button);
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
    input.addEventListener('keydown', event => { if (event.key === 'ArrowDown' && results.firstElementChild) { event.preventDefault(); results.firstElementChild.focus(); } });
  });
  // NI2-417 (UX-05): os diálogos de alimento usam o componente compartilhado (clinical-journeys.js)
  window.openFoodDialog = id => {
    const modal = window.ClyoDialog ? window.ClyoDialog.open(id) : document.getElementById(id);
    if (!window.ClyoDialog) modal.style.display = 'flex';
    setTimeout(() => modal.querySelector('[data-food-query]').focus(), 0);
  };
})();
