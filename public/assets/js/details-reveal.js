/* NI2-887: abre o <details> que contém o alvo da âncora (#secao-...) ou um campo inválido, para nenhuma seção ficar inalcançável. */
(() => {
  'use strict';
  const reveal = (el) => { for (let d = el && el.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) d.open = true; };
  const fromHash = () => {
    let id; try { id = decodeURIComponent(location.hash.slice(1)); } catch { return; }
    const target = id ? document.getElementById(id) : null;
    if (!target || !target.closest('details:not([open])')) return;
    reveal(target); target.scrollIntoView({ block: 'start' });
  };
  window.addEventListener('hashchange', fromHash);
  document.addEventListener('invalid', (e) => reveal(e.target), true);
  if (location.hash) fromHash();
})();
