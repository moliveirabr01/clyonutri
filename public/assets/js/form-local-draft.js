/**
 * NI2-874 (F-23): Rascunho local automático para formulários longos (cadastro de paciente e financeiro),
 * evitando perda de dados caso haja queda de rede no envio.
 */
(() => {
  const TARGET_FORMS = 'form[action^="/pacientes"], form[action^="/financeiro"]';
  const PREFIX = 'clyo:form-draft:';

  function keyOf(form) {
    const action = new URL(form.action, location.origin).pathname;
    return PREFIX + location.pathname + '|' + action;
  }

  function saveForm(form) {
    try {
      const data = {};
      for (const el of form.elements) {
        if (!el.name || el.type === 'hidden' || el.type === 'password' || el.type === 'submit' || el.disabled) continue;
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked) data[el.name] = el.value;
        } else if (el.value !== '') {
          data[el.name] = el.value;
        }
      }
      sessionStorage.setItem(keyOf(form), JSON.stringify({ savedAt: Date.now(), data }));
    } catch (_) {}
  }

  function restoreForm(form) {
    try {
      const raw = sessionStorage.getItem(keyOf(form));
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (!parsed || !parsed.data || (Date.now() - (parsed.savedAt || 0)) > 1800000) {
        sessionStorage.removeItem(keyOf(form));
        return;
      }
      for (const [name, val] of Object.entries(parsed.data)) {
        const el = form.elements[name];
        if (!el || el.type === 'hidden' || el.type === 'password') continue;
        if (el instanceof RadioNodeList) continue;
        if (el.value === '') {
          el.value = String(val);
        }
      }
    } catch (_) {}
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(TARGET_FORMS).forEach(form => {
      if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-local-draft')) return;
      restoreForm(form);
      form.addEventListener('input', () => saveForm(form));
      form.addEventListener('change', () => saveForm(form));
      form.addEventListener('submit', () => {
        if (navigator.onLine) {
          try { sessionStorage.removeItem(keyOf(form)); } catch (_) {}
        } else {
          saveForm(form);
        }
      });
    });
  });
})();
