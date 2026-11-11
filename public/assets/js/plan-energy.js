// NI2-514: comportamento do formulário "Energia e metas" do editor de plano (sem cálculo no navegador).
(() => {
  const form = document.querySelector('[data-energy-form]');
  if (!form) return;
  const formula = form.querySelector('[data-energy-formula]');
  const syncFormula = () => {
    const needsFfm = formula.selectedOptions[0]?.dataset.ffm === '1';
    form.querySelectorAll('[data-energy-eq]').forEach(el => { el.hidden = needsFfm; });
    form.querySelectorAll('[data-energy-ffm]').forEach(el => { el.hidden = !needsFfm; });
    const ffm = form.querySelector('[name=ffm_kg]');
    if (ffm) ffm.required = needsFfm;
    ['height_cm', 'age_years', 'sex'].forEach(name => { const f = form.querySelector('[name=' + name + ']'); if (f) f.required = !needsFfm; });
  };
  formula.addEventListener('change', syncFormula);
  syncFormula();

  const preset = form.querySelector('[data-energy-preset]');
  const factor = form.querySelector('[data-energy-factor]');
  preset.addEventListener('change', () => { if (preset.value) factor.value = preset.value; });
  factor.addEventListener('input', () => {
    const v = Number(String(factor.value).replace(',', '.'));
    const key = Number.isFinite(v) ? v.toFixed(2) : '';
    preset.value = [...preset.options].some(o => o.value === key) ? key : '';
  });


  const mode = form.querySelector('[data-energy-macro-mode]');
  const syncMode = () => form.querySelectorAll('[data-energy-unit]').forEach(el => { el.textContent = mode.value === 'g_per_kg' ? '(g/kg)' : '(%)'; });
  mode.addEventListener('change', syncMode);
  syncMode();
})();
