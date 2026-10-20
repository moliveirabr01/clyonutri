(() => {
  /* ------------------------------------------------------------------
   * NI2-414 (UX-02): estado de alteração por formulário, comparado com o
   * snapshot inicial dos valores persistíveis. Busca transitória
   * (type=search, [data-food-query], [data-transient]) não conta.
   * ------------------------------------------------------------------ */
  const isTransient = el => el.matches('[data-transient],[data-food-query],input[type="search"],input[name="_csrf_token"]');
  const snapshot = form => {
    const out = [];
    for (const el of form.elements) {
      if (!el.name || el.disabled || isTransient(el) || ['submit', 'button', 'reset', 'file', 'hidden'].includes(el.type)) continue; // hidden = contexto definido pelo código (ex.: meal_id), não digitação
      if (el.type === 'checkbox' || el.type === 'radio') out.push(el.name + '=' + (el.checked ? el.value : '\u0000'));
      else if (el.tagName === 'SELECT' && el.multiple) out.push(el.name + '=' + [...el.selectedOptions].map(o => o.value).join(','));
      else out.push(el.name + '=' + el.value);
    }
    return out.join('\u0001');
  };
  const baseline = new Map(), submitting = new Set();
  document.querySelectorAll('form').forEach(form => {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post' || form.hasAttribute('data-no-dirty')) return;
    baseline.set(form, form.hasAttribute('data-start-dirty') ? '\u0002pendente' : snapshot(form)); // reenvio com erro: dados devolvidos ainda não foram salvos
    form.addEventListener('submit', () => {
      submitting.add(form); // só este formulário deixa de ser considerado pendente
      form.querySelectorAll('button[type="submit"]').forEach(button => { setTimeout(() => { button.disabled = true; button.setAttribute('aria-busy', 'true'); }, 0); });
    });
  });
  const isDirty = form => baseline.has(form) && !submitting.has(form) && snapshot(form) !== baseline.get(form);
  // NI2-414 (V4-UX-01): descarte explícito. reset() volta aos valores padrão do HTML — último estado salvo
  // (edição) ou formulário vazio (cadastro); preenchimento recuperado após erro é aplicado por JS e não é
  // restaurado. O novo baseline é tirado DEPOIS do reset e dos handlers de clyo:discard (ex.: food-picker
  // recria opções) e substitui a sentinela de recuperação. Só este formulário é afetado.
  const discard = form => {
    form.reset();
    form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
    form.querySelectorAll('.field-error').forEach(m => { m.textContent = ''; });
    form.querySelectorAll('[data-recovery-notice]').forEach(n => n.remove());
    form.dispatchEvent(new CustomEvent('clyo:discard'));
    form.removeAttribute('data-start-dirty');
    if (baseline.has(form)) baseline.set(form, snapshot(form));
  };
  window.ClyoForms = { isDirty, discard, dirtyForms: () => [...baseline.keys()].filter(isDirty) };
  window.addEventListener('beforeunload', e => { if (window.ClyoForms.dirtyForms().length) { e.preventDefault(); e.returnValue = ''; } });

  /* ------------------------------------------------------------------
   * NI2-417 / NI2-423 (UX-05/UX-11): componente de diálogo único,
   * selecionado por atributo [data-dialog] (compatível com ids "modal-" e
   * .modal-overlay). Título associado, foco inicial, Tab contido, fundo
   * inerte, Escape/Cancelar/clique fora com regra de descarte explícita e
   * retorno do foco ao gatilho.
   * ------------------------------------------------------------------ */
  const SEL = '[data-dialog],div[id^="modal-"],.modal-overlay';
  const isInline = d => d.dataset.dialog === 'inline';
  const isOpen = d => getComputedStyle(d).display !== 'none';
  const focusables = d => [...d.querySelectorAll('button,input,select,textarea,a[href],[tabindex]:not([tabindex="-1"])')].filter(el => !el.disabled && el.type !== 'hidden' && el.getClientRects().length && !el.closest('[inert]'));
  let lastPointer = null;
  document.addEventListener('pointerdown', e => { lastPointer = e.target.closest('button,a,[tabindex]'); }, true);
  const state = new WeakMap();
  const show = d => { if (d.classList.contains('modal-overlay')) d.classList.add('active'); else d.style.display = isInline(d) ? 'block' : 'flex'; };
  const hide = d => { if (d.classList.contains('modal-overlay')) { d.classList.remove('active'); d.style.removeProperty('display'); } else d.style.display = 'none'; };
  const DISCARD_MSG = 'Descartar o que foi preenchido nesta janela? Rascunhos já salvos e outros formulários não são afetados.';
  const requestClose = d => {
    const dirty = [...d.querySelectorAll('form')].filter(isDirty);
    if (dirty.length && !window.confirm(DISCARD_MSG)) { (document.activeElement && d.contains(document.activeElement) ? document.activeElement : focusables(d)[0])?.focus(); return false; }
    dirty.forEach(discard); hide(d); return true;
  };
  const setBackgroundInert = (d, on) => {
    const st = state.get(d);
    if (on) {
      st.inerted = [];
      let node = d;
      while (node && node !== document.body) {
        for (const sib of node.parentElement.children) {
          if (sib !== node && !sib.hasAttribute('inert') && !['SCRIPT', 'STYLE', 'TEMPLATE'].includes(sib.tagName)) { sib.setAttribute('inert', ''); st.inerted.push(sib); }
        }
        node = node.parentElement;
      }
      document.documentElement.classList.add('cn-dialog-open');
    } else {
      (st.inerted || []).forEach(el => el.removeAttribute('inert')); st.inerted = [];
      if (!document.querySelector('.cn-dialog-is-open')) document.documentElement.classList.remove('cn-dialog-open');
    }
  };
  const sync = d => {
    const st = state.get(d), open = isOpen(d);
    if (open && !st.open) {
      st.trigger = (document.activeElement && document.activeElement !== document.body && !d.contains(document.activeElement)) ? document.activeElement : lastPointer;
      if (!isInline(d)) {
        d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); d.classList.add('cn-dialog-is-open');
        setBackgroundInert(d, true);
      }
      const heading = d.querySelector('h1,h2,h3');
      if (heading && !d.hasAttribute('aria-label')) { heading.id ||= (d.id || 'dialogo') + '-title'; d.setAttribute('aria-labelledby', heading.id); }
      const first = d.querySelector('[autofocus]') || focusables(d).find(el => el.matches('input,select,textarea')) || focusables(d)[0];
      setTimeout(() => first?.focus(), 0);
    }
    if (!open && st.open) {
      d.classList.remove('cn-dialog-is-open');
      if (!isInline(d)) setBackgroundInert(d, false);
      if (st.trigger?.isConnected) st.trigger.focus();
    }
    st.open = open;
  };
  const dialogs = [...document.querySelectorAll(SEL)].filter(d => !d.parentElement?.closest(SEL)); // títulos/elementos internos (ex.: modal-x-title) não são diálogos
  dialogs.forEach(d => {
    if (!d.hasAttribute('data-dialog')) d.setAttribute('data-dialog', 'modal');
    state.set(d, { open: false });
    new MutationObserver(() => sync(d)).observe(d, { attributes: true, attributeFilter: ['style', 'class'] });
    sync(d);
    d.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); requestClose(d); return; }
      if (e.key === 'Tab' && !isInline(d)) {
        const f = focusables(d), first = f[0], last = f.at(-1);
        if (!f.length) { e.preventDefault(); return; }
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
  });
  // Cancelar / ✕ / clique no fundo: interceptados antes dos onclick inline legados
  document.addEventListener('click', e => {
    const closer = e.target.closest('[data-dialog-close]');
    const d = closer ? (closer.dataset.dialogClose ? document.getElementById(closer.dataset.dialogClose) : [...dialogs].find(x => x.contains(closer))) : (dialogs.includes(e.target) && !isInline(e.target) ? e.target : null);
    if (!d || !state.has(d)) return;
    e.preventDefault(); e.stopImmediatePropagation(); requestClose(d);
  }, true);
  window.ClyoDialog = {
    open: idOrEl => { const d = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl; if (d) { if (!d.contains(document.activeElement)) lastPointer = document.activeElement; show(d); } return d; },
    close: idOrEl => requestClose(typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl),
  };

  /* Recuperação após falha (mantida): aplicada depois do snapshot, portanto o formulário recuperado conta como pendente. */
  const recovery = document.getElementById('clinical-form-recovery');
  if (recovery) {
    const saved = JSON.parse(recovery.textContent);
    const form = [...document.forms].find(f => new URL(f.action).pathname === saved.action && f.method.toLowerCase() === 'post');
    if (form) {
      for (const field of form.elements) {
        const name = field.name.replace(/\[\]$/, ''); if (!name || field.type === 'hidden' || ['submit', 'button', 'reset', 'file'].includes(field.type)) continue;
        // UX-02 (v14): checkbox desmarcado não é enviado no POST; ausência = desmarcado (não volta ao padrão do HTML).
        if (field.type === 'checkbox' || field.type === 'radio') { const value = saved.values[name]; field.checked = name in saved.values && (Array.isArray(value) ? value.includes(field.value) : value === field.value); continue; }
        if (!(name in saved.values)) continue;
        const value = saved.values[name];
        if (typeof value === 'string') field.value = value;
      }
      const notice = document.createElement('p'); notice.setAttribute('role', 'status'); notice.setAttribute('data-recovery-notice', ''); notice.textContent = 'Preenchimento recuperado após a falha. Confira os dados antes de salvar. Disponível nesta sessão por até uma hora.'; form.prepend(notice);
      const modal = dialogs.find(x => x.contains(form)); if (modal) show(modal);
    }
  }

  /* NI2-416 (UX-04): erro nativo associado ao campo (aria-invalid + aria-describedby); o navegador foca o primeiro inválido. */
  document.addEventListener('invalid', e => {
    const el = e.target; if (!el.id) el.id = 'campo-' + (el.name || 'x').replace(/\W/g, '') + '-' + Math.random().toString(36).slice(2, 7);
    const id = el.id + '-erro'; let msg = document.getElementById(id);
    if (!msg) {
      msg = document.createElement('p'); msg.id = id; msg.className = 'field-error';
      (el.closest('[data-food-picker]') ? el.closest('[data-food-picker]') : el).insertAdjacentElement(el.closest('[data-food-picker]') ? 'beforeend' : 'afterend', msg);
      el.setAttribute('aria-describedby', [el.getAttribute('aria-describedby'), id].filter(Boolean).join(' '));
    }
    el.setAttribute('aria-invalid', 'true'); msg.textContent = el.validationMessage;
  }, true);
  const clearIfValid = e => { const el = e.target; if (el.getAttribute?.('aria-invalid') === 'true' && el.checkValidity()) { el.removeAttribute('aria-invalid'); const m = document.getElementById(el.id + '-erro'); if (m) m.textContent = ''; } };
  document.addEventListener('input', clearIfValid, true); document.addEventListener('change', clearIfValid, true);

  /* Navegação lateral (mantida) */
  const toggle = document.getElementById('clinical-menu-toggle'), nav = document.getElementById('clinical-navigation');
  toggle?.addEventListener('click', () => { const open = toggle.getAttribute('aria-expanded') !== 'true'; toggle.setAttribute('aria-expanded', String(open)); nav?.classList.toggle('is-open', open); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && toggle?.getAttribute('aria-expanded') === 'true') { toggle.click(); toggle.focus(); } });
  document.querySelectorAll('.app-nav__item.active').forEach(el => el.setAttribute('aria-current', 'page'));

  /* V15-02: higienização de rascunhos locais no sessionStorage após confirmação de conclusão da consulta ou no logout */
  const clearDraftStorage = () => {
    try {
      for (let i = window.sessionStorage.length - 1; i >= 0; i--) {
        const k = window.sessionStorage.key(i);
        if (k && k.startsWith('cn_consult_draft:')) window.sessionStorage.removeItem(k);
      }
    } catch (e) {}
  };
  if (window.location.search.includes('success=consulta_concluida')) {
    clearDraftStorage();
  }
  document.querySelectorAll('form.logout-form, form[action="/logout"]').forEach(f => {
    f.addEventListener('submit', clearDraftStorage);
  });
})();

