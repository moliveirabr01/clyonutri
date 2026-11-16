/**
 * NI2-847 (UX-003): Campos numéricos clínicos aceitam vírgula ou ponto decimal
 * e exibem o valor interpretado (ex.: "1,70 m (170 cm)", "72,4 kg") antes de salvar.
 */
(() => {
  const SELECTOR = [
    'input[data-decimal-field]',
    'input[name="weight_kg"]',
    'input[name="height_cm"]',
    'input[name="waist_cm"]',
    'input[name="hip_cm"]',
    'input[name="neck_cm"]',
    'input[name="arm_cm"]',
    'input[name="chest_cm"]',
    'input[name="thigh_cm"]',
    'input[name="calf_cm"]',
    'input[name="abdomen_cm"]',
    'input[name="shoulder_cm"]',
    'input[name="arm_contracted_cm"]',
    'input[name="forearm_cm"]',
    'input[name="wrist_cm"]',
    'input[name="thigh_proximal_cm"]',
    'input[name="calf_left_cm"]',
    'input[name="body_fat_pct"]',
    'input[name="muscle_mass_pct"]',
    'input[name="visceral_fat_level"]',
    'input[name="total_body_water_pct"]',
    'input[name="bone_mass_kg"]',
    'input[name="phase_angle_deg"]',
    'input[name="ffm_kg"]',
    'input[name^="readings["]'
  ].join(',');

  function parseDecimal(raw) {
    if (raw === null || raw === undefined) return { empty: true };
    let s = String(raw).trim();
    if (s === '') return { empty: true };
    if (/^-?\d{1,3}(\.\d{3})+,\d+$/.test(s)) {
      s = s.replace(/\./g, '');
    }
    s = s.replace(',', '.');
    if (!/^[+-]?\d+(\.\d+)?$/.test(s)) {
      return { empty: false, valid: false };
    }
    const num = Number(s);
    if (!Number.isFinite(num)) {
      return { empty: false, valid: false };
    }
    return { empty: false, valid: true, value: num };
  }

  function formatPtBr(num, decimals = 1) {
    return num.toFixed(decimals).replace('.', ',');
  }

  function describeField(el, num) {
    const name = el.getAttribute('name') || '';
    const kind = el.getAttribute('data-decimal-field') || '';
    if (name === 'height_cm' || kind === 'height') {
      let cm = num;
      let meters = num / 100;
      if (num > 0 && num <= 3.0) {
        meters = num;
        cm = Math.round(num * 1000) / 10;
      }
      if (cm < 40 || cm > 260) {
        return {
          valid: false,
          error: 'Informe a altura entre 45 e 250 cm (ou 0,45 a 2,50 m).'
        };
      }
      const cmText = cm % 1 === 0 ? String(Math.round(cm)) : formatPtBr(cm, 1);
      return {
        valid: true,
        text: `Interpretado: ${formatPtBr(meters, 2)} m (${cmText} cm)`
      };
    }

    // Reteste UX v54.62 (R-001): quantidade de alimento no plano ("1,5 unidade", "0,25 xícara").
    if (kind === 'quantity') {
      if (!(num > 0)) {
        return { valid: false, error: 'Informe uma quantidade maior que zero (ex.: 1,5).' };
      }
      const txt = (Math.round(num * 100) / 100).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
      return { valid: true, text: `Interpretado: ${txt}` };
    }

    const minAttr = el.getAttribute('data-min') ?? el.getAttribute('min');
    const maxAttr = el.getAttribute('data-max') ?? el.getAttribute('max');
    const min = minAttr !== null && minAttr !== '' ? Number(minAttr) : null;
    const max = maxAttr !== null && maxAttr !== '' ? Number(maxAttr) : null;

    let unit = '';
    if (name === 'weight_kg' || name.endsWith('_kg') || kind === 'weight') unit = ' kg';
    else if (name.endsWith('_cm') || kind === 'cm') unit = ' cm';
    else if (name.endsWith('_pct') || kind === 'pct') unit = '%';
    else if (name.startsWith('readings[') || kind === 'mm') unit = ' mm';

    if (min !== null && Number.isFinite(min) && num < min) {
      return {
        valid: false,
        error: `Informe um valor entre ${formatPtBr(min, min % 1 === 0 ? 0 : 1)} e ${max !== null ? formatPtBr(max, max % 1 === 0 ? 0 : 1) : 'o limite'}${unit}.`
      };
    }
    if (max !== null && Number.isFinite(max) && num > max) {
      return {
        valid: false,
        error: `Informe um valor entre ${min !== null ? formatPtBr(min, min % 1 === 0 ? 0 : 1) : '0'} e ${formatPtBr(max, max % 1 === 0 ? 0 : 1)}${unit}.`
      };
    }

    return {
      valid: true,
      text: `Interpretado: ${formatPtBr(num, 1)}${unit}`
    };
  }

  function ensureEchoNode(el) {
    if (el._decimalEchoNode && el._decimalEchoNode.isConnected) {
      return el._decimalEchoNode;
    }
    const nameKey = (el.getAttribute('name') || el.id || 'campo').replace(/[^a-zA-Z0-9_-]/g, '_');
    const node = document.createElement('small');
    node.className = 'decimal-echo u-block u-text-caption u-color-neutral-gray-600 u-mt-1';
    node.setAttribute('data-decimal-echo', nameKey);
    node.setAttribute('data-testid', 'decimal-echo-' + nameKey);
    node.setAttribute('aria-live', 'polite');
    node.hidden = true;
    el.insertAdjacentElement('afterend', node);
    el._decimalEchoNode = node;
    return node;
  }

  function updateField(el, reportValidity = false) {
    const echo = ensureEchoNode(el);
    const parsed = parseDecimal(el.value);
    if (parsed.empty) {
      echo.textContent = '';
      echo.hidden = true;
      echo.classList.remove('u-color-semantic-error');
      el.setCustomValidity('');
      return;
    }
    if (!parsed.valid) {
      const msg = 'Informe um número válido (ex.: 72,4).';
      echo.textContent = msg;
      echo.hidden = false;
      echo.classList.add('u-color-semantic-error');
      el.setCustomValidity(msg);
      if (reportValidity) el.reportValidity();
      return;
    }
    const desc = describeField(el, parsed.value);
    if (!desc.valid) {
      echo.textContent = desc.error;
      echo.hidden = false;
      echo.classList.add('u-color-semantic-error');
      el.setCustomValidity(desc.error);
      if (reportValidity) el.reportValidity();
      return;
    }
    el.setCustomValidity('');
    echo.classList.remove('u-color-semantic-error');
    echo.textContent = desc.text;
    echo.hidden = false;
  }

  function bindInput(el) {
    if (el.dataset.decimalBound === '1') return;
    el.dataset.decimalBound = '1';
    if (el.getAttribute('type') === 'number') {
      if (el.hasAttribute('min') && !el.hasAttribute('data-min')) el.setAttribute('data-min', el.getAttribute('min'));
      if (el.hasAttribute('max') && !el.hasAttribute('data-max')) el.setAttribute('data-max', el.getAttribute('max'));
      el.setAttribute('type', 'text');
    }
    if (!el.hasAttribute('inputmode')) {
      el.setAttribute('inputmode', 'decimal');
    }
    el.addEventListener('input', () => updateField(el, false));
    el.addEventListener('change', () => updateField(el, false));
    el.addEventListener('blur', () => updateField(el, false));
    if (el.value && String(el.value).trim() !== '') {
      updateField(el, false);
    }
  }

  function mountAll(root = document) {
    root.querySelectorAll(SELECTOR).forEach(bindInput);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => mountAll());
  } else {
    mountAll();
  }
  document.addEventListener('clyo:forms-updated', () => mountAll());
})();
