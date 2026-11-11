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
  function register(form) {
    if (baseline.has(form) || (form.getAttribute('method') || 'get').toLowerCase() !== 'post' || form.hasAttribute('data-no-dirty')) return;
    baseline.set(form, form.hasAttribute('data-start-dirty') ? '\u0002pendente' : snapshot(form));
    form.addEventListener('submit', e => {
      if (e.defaultPrevented || form.hasAttribute('data-workflow-draft') || form.hasAttribute('data-live-plan')) return;
      submitting.add(form);
      form.querySelectorAll('button[type="submit"]').forEach(button => { setTimeout(() => { button.disabled = true; button.setAttribute('aria-busy', 'true'); }, 0); });
    });
  }
  const registerAll = () => { [...baseline.keys()].forEach(f => { if (!f.isConnected) baseline.delete(f); }); document.querySelectorAll('form').forEach(register); };
  registerAll();
  document.addEventListener('clyo:forms-updated', registerAll);
  new MutationObserver(registerAll).observe(document.body,{childList:true,subtree:true});
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
  window.ClyoForms = { isDirty, discard, saved: form => { submitting.delete(form); baseline.set(form,snapshot(form)); form.removeAttribute('data-start-dirty'); }, failed: form => submitting.delete(form), dirtyForms: () => [...baseline.keys()].filter(isDirty) };
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
  const focusables = d => [...d.querySelectorAll('button,input,select,textarea,a[href],summary,[tabindex],[contenteditable="true"],audio[controls],video[controls]')].filter(el => {
    if(el.matches(':disabled')||el.tabIndex<0||el.type==='hidden'||!el.getClientRects().length||el.closest('[inert],[hidden]')||getComputedStyle(el).visibility!=='visible')return false;
    for(let node=el.parentElement;node&&node!==d;node=node.parentElement){
      if(node.tagName==='DETAILS'&&!node.open&&!node.querySelector(':scope > summary')?.contains(el))return false;
    }
    return true;
  });
  const tabStops=d=>focusables(d).filter(el=>{
    if(el.type!=='radio'||!el.name)return true;
    const group=focusables(d).filter(other=>other.type==='radio'&&other.name===el.name&&other.form===el.form);
    return el===(group.find(other=>other.checked)||group[0]);
  });
  let lastPointer = null;
  document.addEventListener('pointerdown', e => { lastPointer = e.target.closest('button,a,[tabindex]'); }, true);
  const state = new WeakMap();
  const show = d => { d.hidden=false;if (d.classList.contains('modal-overlay')) d.classList.add('active'); else d.style.display = isInline(d) ? 'block' : 'flex'; };
  const hide = d => { if (d.classList.contains('modal-overlay')) { d.classList.remove('active'); d.style.removeProperty('display'); } else d.style.display = 'none'; };
  const DISCARD_MSG = 'Descartar o que foi preenchido nesta janela? Rascunhos já salvos e outros formulários não são afetados.';
  const requestClose = d => {
    if(!d||!state.has(d))return false;
    const dirty = [...d.querySelectorAll('form')].filter(isDirty);
    if (dirty.length && !window.confirm(DISCARD_MSG)) { (document.activeElement && d.contains(document.activeElement) ? document.activeElement : focusables(d)[0])?.focus(); return false; }
    dirty.forEach(discard); hide(d); return true;
  };
  const modalStack=[], managedInert=new Set();
  const setBackgroundInert = (d,on) => {
    managedInert.forEach(el=>el.removeAttribute('inert'));managedInert.clear();
    const index=modalStack.indexOf(d);if(index>=0)modalStack.splice(index,1);
    if(on)modalStack.push(d);
    const top=modalStack.at(-1);
    if(top){
      let node=top;
      while(node&&node!==document.body){
        for(const sibling of node.parentElement?.children||[]){
          if(sibling!==node&&!sibling.hasAttribute('inert')&&!['SCRIPT','STYLE','TEMPLATE'].includes(sibling.tagName)){
            sibling.setAttribute('inert','');managedInert.add(sibling);
          }
        }
        node=node.parentElement;
      }
    }
    document.documentElement.classList.toggle('cn-dialog-open',!!top);
  };
  const sync = d => {
    const st = state.get(d), open = isOpen(d);
    if (open && !st.open) {
      st.trigger = (document.activeElement && document.activeElement !== document.body && !d.contains(document.activeElement)) ? document.activeElement : lastPointer;
      if (!isInline(d)) {
        if(!['dialog','alertdialog'].includes(d.getAttribute('role')))d.setAttribute('role','dialog'); d.setAttribute('aria-modal', 'true'); d.classList.add('cn-dialog-is-open');
        setBackgroundInert(d, true);
      }
      const heading = d.querySelector('h1,h2,h3');
      if (heading && !d.hasAttribute('aria-label')) { heading.id ||= (d.id || 'dialogo') + '-title'; d.setAttribute('aria-labelledby', heading.id); }
      const candidates=focusables(d);
      const first=candidates.find(el=>el.hasAttribute('autofocus'))||candidates.find(el=>el.matches('input,select,textarea'))||candidates[0]||d;
      if(first===d&&!d.hasAttribute('tabindex'))d.tabIndex=-1;
      setTimeout(()=>{if(isOpen(d)&&(isInline(d)||modalStack.at(-1)===d))first.focus();},0);
    }
    if (!open && st.open) {
      d.classList.remove('cn-dialog-is-open');
      if (!isInline(d)) setBackgroundInert(d, false);
      if (st.trigger?.isConnected&&!st.trigger.closest('[inert]')) st.trigger.focus();
      else if (st.trigger) {
        const old=st.trigger;
        const equivalent=[...document.querySelectorAll('button,a,summary')].find(el=>el.getClientRects().length &&
          ((old.id&&el.id===old.id)||(old.dataset.call&&el.dataset.call===old.dataset.call&&el.dataset.args===old.dataset.args)||(old.dataset.show&&el.dataset.show===old.dataset.show)));
        const fallback=equivalent||document.querySelector('[data-quick-item] [data-food-query]')||document.querySelector('main h1,main h2');
        if(fallback){if(!equivalent&&!fallback.matches('input,button,a,summary'))fallback.setAttribute('tabindex','-1');fallback.focus();}
      }
      d.dispatchEvent(new CustomEvent('clyo:dialog-closed'));
    }
    st.open = open;
  };
  const dialogs=[];
  function mountDialogs(root=document){
    for(let i=dialogs.length-1;i>=0;i--){
      if(!dialogs[i].isConnected){
        const old=dialogs.splice(i,1)[0],st=state.get(old);
        if(st?.open&&!isInline(old)){
          setBackgroundInert(old,false);
          if(st.trigger?.isConnected&&!st.trigger.closest('[inert]'))st.trigger.focus();
          else {const top=modalStack.at(-1);if(top)(focusables(top)[0]||top).focus();}
        }
      }
    }
    const candidates=[...(root.matches?.(SEL)?[root]:[]),...root.querySelectorAll(SEL)];
    candidates.filter(d=>!d.parentElement?.closest(SEL)).forEach(d=>{
      if(state.has(d))return;
      if(!d.hasAttribute('data-dialog'))d.setAttribute('data-dialog','modal');
      dialogs.push(d);state.set(d,{open:false});
      new MutationObserver(()=>sync(d)).observe(d,{attributes:true,attributeFilter:['style','class','hidden']});
      sync(d);
      d.addEventListener('keydown',e=>{
        if(!isInline(d)&&modalStack.at(-1)!==d)return;
        if(e.key==='Escape'){e.preventDefault();e.stopPropagation();requestClose(d);return;}
        if(e.key==='Tab'&&!isInline(d)){
          const f=tabStops(d),first=f[0],last=f.at(-1);
          if(!f.length){e.preventDefault();d.focus();return;}
          if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
          else if(!e.shiftKey&&(document.activeElement===last||document.activeElement===d)){e.preventDefault();first.focus();}
        }
      });
    });
  }
  mountDialogs();
  document.addEventListener('clyo:forms-updated',()=>mountDialogs());
  new MutationObserver(()=>mountDialogs()).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('focusin',e=>{
    const top=modalStack.at(-1);
    if(top&&!top.contains(e.target)){if(!top.hasAttribute('tabindex'))top.tabIndex=-1;(focusables(top)[0]||top).focus();}
  });
  // Cancelar / ✕ / clique no fundo: interceptados antes dos onclick inline legados
  document.addEventListener('click', e => {
    const closer = e.target.closest('[data-dialog-close]');
    const d = closer ? (closer.dataset.dialogClose ? document.getElementById(closer.dataset.dialogClose) : [...dialogs].find(x => x.contains(closer))) : (dialogs.includes(e.target) && !isInline(e.target) ? e.target : null);
    if (!d || !state.has(d)) return;
    e.preventDefault(); e.stopImmediatePropagation(); requestClose(d);
  }, true);
  window.ClyoDialog = {
    open: idOrEl => { const d = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl; if (d) { mountDialogs(d);if (!d.contains(document.activeElement)) lastPointer = document.activeElement;show(d); } return d; },
    mount: mountDialogs,
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

  // v54.58: responsive navigation is handled by navigation-v54-58.js.
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

/* NI2-567: anchors keep their destination visible below a wrapped sticky index. */
(() => {
  function indexNavigation(index, property, scope) {
    if (!index) return;
    const measure = () => scope.style.setProperty(property, index.getBoundingClientRect().height + 'px');
    measure(); new ResizeObserver(measure).observe(index);
    function reveal(link) {
      const target = document.getElementById(link.hash.slice(1));
      if (!target) return;
      measure(); target.setAttribute('tabindex','-1'); target.focus({preventScroll:true});
      target.scrollIntoView({block:'start'});
    }
    index.addEventListener('click', event => {
      const link = event.target.closest('a[href^="#"]'); if (!link) return;
      event.preventDefault(); history.replaceState(null,'',link.hash); reveal(link);
    });
    const initial = [...index.querySelectorAll('a')].find(a => a.hash === location.hash);
    if (initial) document.fonts.ready.then(() => requestAnimationFrame(() => reveal(initial)));
  }
  const form = document.getElementById('anamnesis-form');
  if (form) indexNavigation(form.querySelector('.cn-anamnesis-index'),'--cn-index-height',form);
  indexNavigation(document.querySelector('.cn-settings-index'),'--cn-settings-index-height',document.documentElement);
  document.querySelectorAll('[data-settings-section] form').forEach(form => {
    if (form.method.toLowerCase() !== 'post') return;
    const state = document.createElement('p'); state.className='cn-form-save-state'; state.setAttribute('role','status');
    const recovered = form.hasAttribute('data-start-dirty') || document.querySelector('[data-settings-error-section]')?.dataset.settingsErrorSection === form.elements.section?.value;
    const initialLabel = document.querySelector('[data-settings-saved-section]')?.dataset.settingsSavedSection === form.elements.section?.value ? 'Esta seção foi salva.' : 'Sem alterações nesta seção.';
    state.textContent=recovered?'Alterações não salvas nesta seção.':initialLabel; form.append(state);
    const values = () => JSON.stringify([...new FormData(form)].filter(([key]) => !key.startsWith('_csrf') && key !== 'current_password'));
    const initial = values();
    const update = () => state.textContent = !recovered && values() === initial ? initialLabel : 'Alterações não salvas nesta seção.';
    form.addEventListener('input',update); form.addEventListener('change',update);
    form.addEventListener('reset',() => setTimeout(update,0));
  });
  const tools = document.querySelector('[data-profile-tools]'), preference = document.querySelector('[data-profile-tools-preference]');
  if (tools && preference) {
    const key='clyo-profile-tools-expanded';
    try { tools.open = localStorage.getItem(key)==='1'; preference.checked=tools.open; } catch (_) {}
    preference.addEventListener('change',() => { tools.open=preference.checked;try{localStorage.setItem(key,preference.checked?'1':'0');}catch(_){} });
  }
  // v54.43: Esc fecha "Mais ações" do perfil e devolve o foco ao botão.
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.patient-header__more[open]').forEach(function(d) {
      d.open = false; var s = d.querySelector('summary'); if (s) s.focus();
    });
  });

  document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'up-file') {
      var file = e.target.files && e.target.files[0];
      var err = document.getElementById('up-file-error');
      if (!file) return;
      var name = file.name.toLowerCase();
      var validExt = name.endsWith('.pdf') || name.endsWith('.jpg') || name.endsWith('.jpeg') || name.endsWith('.png');
      if (!validExt) {
        if (err) { err.textContent = 'Formato não aceito. Envie o laudo em PDF, JPG ou PNG.'; err.hidden = false; }
        e.target.value = '';
        return;
      }
      if (file.size > 15 * 1024 * 1024) {
        if (err) { err.textContent = 'Arquivo muito grande. O limite para laudos é 15 MB.'; err.hidden = false; }
        e.target.value = '';
        return;
      }
      if (err) { err.textContent = ''; err.hidden = true; }
    }
  });
})();


/* NI2-585 (UX-002): "Nega alergias" desliga e limpa o campo de alergias (sem handler em linha: a CSP bloqueia). */
(() => {
  const sync = box => {
    const target = document.getElementById(box.getAttribute('data-allergy-toggle'));
    if (!target) return;
    if (box.checked) target.value = '';
    target.disabled = box.checked;
  };
  document.querySelectorAll('input[type="checkbox"][data-allergy-toggle]').forEach(box => {
    sync(box);
    box.addEventListener('change', () => sync(box));
  });
})();

/* NI2-612: links antigos /configuracoes?tab=agenda#... (sem ?secao=) abrem a seção certa da aba Agenda. */
(() => {
  const q = new URLSearchParams(location.search);
  if (location.pathname !== '/configuracoes' || q.get('tab') !== 'agenda' || q.has('secao') || !location.hash) return;
  const id = decodeURIComponent(location.hash.slice(1));
  if (!id || document.getElementById(id)) return;
  const secao = /^calendar-/.test(id) ? 'feriados' : /^booking-/.test(id) ? 'link' : null;
  if (secao) { q.set('secao', secao); location.replace('/configuracoes?' + q.toString() + location.hash); }
})();

/* NI2-588 (UX-005): links antigos /configuracoes#secao abrem a aba certa. */
(() => {
  if (location.pathname !== '/configuracoes' || !location.hash || new URLSearchParams(location.search).has('tab')) return;
  const id = decodeURIComponent(location.hash.slice(1));
  if (!id || document.getElementById(id)) return;
  const tab = /^(booking-settings|calendar-|settings-agenda|appointment-)/.test(id) ? 'agenda'
    : /^(chat-|notification-|reminder-|patient-actions|self-service|settings-patient|settings-communication|quiet-)/.test(id) ? 'paciente-app'
    : /^(settings-account|account-)/.test(id) ? 'conta' : null;
  if (tab) location.replace('/configuracoes?tab=' + tab + location.hash);
})();
