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
    function render() {
      results.replaceChildren();
      foods.forEach(food => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'food-result';
        button.setAttribute('aria-pressed', String(select.value === String(food.id)));
        const name = document.createElement('strong'), energy = document.createElement('span');
        name.textContent = food.name;
        energy.textContent = Number(food.energy_kcal).toLocaleString('pt-BR') + ' kcal por 100 g';
        button.append(name, energy);
        button.addEventListener('click', () => {
          select.value = String(food.id); validate(); render();
          chosen.textContent = 'Selecionado: ' + food.name; chosen.hidden = false;
        });
        results.append(button);
      });
    }
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
  let trigger;
  const close = modal => { modal.style.display = 'none'; trigger?.focus(); };
  window.openFoodDialog = id => {
    trigger = document.activeElement;
    const modal = document.getElementById(id); modal.style.display = 'flex'; modal.querySelector('[data-food-query]').focus();
  };
  document.querySelectorAll('.food-dialog-overlay').forEach(modal => {
    modal.querySelector('[data-food-close]').addEventListener('click', () => close(modal));
    modal.addEventListener('click', event => { if (event.target === modal) close(modal); });
    modal.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); close(modal); }
      if (event.key === 'Tab') {
        const focusable = [...modal.querySelectorAll('button, input, select, [tabindex]')].filter(el => !el.disabled && el.offsetParent !== null && el.type !== 'hidden');
        const first = focusable[0], last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
  });
})();
