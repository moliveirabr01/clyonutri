(() => {
  const input = document.getElementById('patient-search');
  if (!input) return;
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
  const rows = [...document.querySelectorAll('[data-patient-row]')].map(row => ({
    row,
    name: normalize(row.cells[0].textContent),
    email: normalize(row.cells[1].textContent),
    phone: row.cells[2].textContent.replace(/\D/g, '')
  }));
  const status = document.getElementById('patient-search-count');
  const empty = document.getElementById('patient-search-empty');
  function filter() {
    const query = normalize(input.value);
    const digits = query.replace(/\D/g, '');
    const phoneQuery = digits.length > 0 && /^[\d\s()+.\-]+$/.test(query);
    let count = 0;
    rows.forEach(item => {
      const match = !query || item.name.includes(query) || item.email.includes(query) || (phoneQuery && item.phone.includes(digits));
      item.row.hidden = !match;
      if (match) count++;
    });
    const originalEmpty = document.getElementById('patient-list-empty');
    if (originalEmpty) originalEmpty.hidden = Boolean(query);
    empty.hidden = !query || count > 0;
    status.textContent = `${count} paciente${count === 1 ? '' : 's'}${query ? ' encontrado' + (count === 1 ? '' : 's') : ' na lista'}.`;
  }
  let debounceTimer = null;
  function debounceFilter() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(filter, 80);
  }
  input.addEventListener('input', debounceFilter);
  document.getElementById('patient-search-clear').addEventListener('click', () => { input.value = ''; filter(); input.focus(); });
  filter();
})();
