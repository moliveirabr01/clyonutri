// NI2-521 (v42): editor do plano sem recarregar a página. Os formulários marcados com data-live-plan são enviados
// por fetch; a resposta é a própria página do editor (mesmo redirecionamento do envio normal), e só as áreas
// [data-live-swap][id] são trocadas. O servidor continua sendo a única fonte dos totais e das regras.
// NI2-519 (v42): sugestão de quantidade pela mesma energia na janela de substituição (só energia, não é equivalência).
(() => {
  const status = document.getElementById('plan-live-status');
  const announce = text => { if (!status) return; status.textContent = ''; setTimeout(() => { status.textContent = text; }, 30); };
  const fmt = (v, d) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: d });
  const supported = typeof window.fetch === 'function' && typeof window.DOMParser === 'function' && typeof window.FormData === 'function';

  // O rótulo original é guardado antes do envio (csp-actions.js troca o texto pelo data-busy-text na captura).
  const rememberLabels = () => document.querySelectorAll('form[data-live-plan] button[type="submit"]').forEach(b => { if (!b.dataset.liveLabel) b.dataset.liveLabel = b.textContent; });
  rememberLabels();
  function restoreButtons(form) {
    form.querySelectorAll('button[type="submit"]').forEach(b => {
      b.disabled = false; b.removeAttribute('aria-busy');
      if (b.dataset.liveLabel) b.textContent = b.dataset.liveLabel;
    });
  }
  function showFormError(form, text) {
    window.ClyoForms?.failed(form);
    window.ClyoWorkflow?.set(document.querySelector('[data-quick-plan]'),'error');
    let box = form.querySelector('[data-live-error]');
    if (!box) { box = document.createElement('p'); box.setAttribute('data-live-error', ''); box.setAttribute('role', 'alert'); box.style.cssText = 'background:#ffebee;color:#c62828;padding:8px 12px;border-radius:6px;margin:8px 0;font-size:0.85rem;'; form.prepend(box); }
    box.textContent = text; box.hidden = false;
  }
  function dedupeFlashes() {
    const flashes = document.querySelectorAll('[data-plan-flash="ok"]');
    if (flashes.length > 1) {
      for (let i = 1; i < flashes.length; i++) {
        flashes[i].remove();
      }
    }
  }
  dedupeFlashes();

  function swap(doc) {
    let swapped = 0;
    document.querySelectorAll('[data-live-swap][id]').forEach(el => {
      const fresh = doc.getElementById(el.id);
      if (!fresh) return;
      el.replaceWith(document.importNode(fresh, true)); swapped++;
    });
    dedupeFlashes();
    document.dispatchEvent(new CustomEvent('clyo:forms-updated'));
    document.querySelectorAll('#plan-live-meals [data-unit-scope], #plan-live-top [data-unit-scope]').forEach(scope => window.ClyoUnitCheck?.init(scope));
    rememberLabels();
    return swapped;
  }

  const attempts=new WeakMap();
  const mapOperation=(form,body)=>{
    const path=new URL(form.action).pathname;
    const match=path.match(/^\/planos\/(\d+)\/(refeicoes|itens|substituicoes)(?:\/(\d+)\/(editar|remover|somar))?$/);if(!match)return null;
    const [,plan,section,id,verb]=match;
    const key=section==='refeicoes'?'meal':section==='itens'?'item':'substitution';
    body.set('action',verb==='somar'?'merge_duplicate':verb==='editar'?'edit_'+key:verb==='remover'?(key==='item'?'remove':'remove_'+key):key==='substitution'?'add_substitution':key);
    if(id)body.set(key==='meal'?'meal_id':key==='item'?'item_id':'sub_id',id);
    return '/api/workflow/plan/'+plan;
  };
  document.addEventListener('submit', async e => {
    const form=e.target;
    if(!supported||!(form instanceof HTMLFormElement)||!form.hasAttribute('data-live-plan')||e.defaultPrevented)return;
    const root=document.querySelector('[data-quick-plan]');if(!root)return;
    if(root.dataset.needsReload){e.preventDefault();restoreButtons(form);showFormError(form,'Recarregue para conferir a gravação já confirmada antes de alterar novamente.');return;}
    let confirmed=false;
    const body=new FormData(form),endpoint=mapOperation(form,body);if(!endpoint)return;e.preventDefault();
    const fp=JSON.stringify([...body.entries()]);let attempt=attempts.get(form);
    if(attempt&&attempt.fp!==fp){restoreButtons(form);showFormError(form,'A tentativa anterior não foi confirmada. Confira o salvo antes de enviar outra alteração.');return;}
    if(!attempt){attempt={fp,key:crypto.randomUUID(),revision:root.dataset.revision};attempts.set(form,attempt);}
    body.set('_request_key',attempt.key);body.set('_base_revision',attempt.revision);
    form.querySelector('[data-live-error]')?.setAttribute('hidden','');
    const dialog=form.closest('[data-dialog]'),scrollY=window.scrollY,focusedName=document.activeElement?.name;
    window.ClyoWorkflow?.set(root,'saving');
    try {
      const response=await fetch(endpoint,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});let j;try{j=await response.json();}catch(_){throw Error('Sem confirmação do servidor. Repita a mesma tentativa ou confira o salvo antes de alterar os campos.');}
      if(!response.ok){if(response.status<500)attempts.delete(form);showFormError(form,j.error||'Não foi possível salvar.');window.ClyoWorkflow?.set(root,response.status===409?'conflict':[401,403].includes(response.status)?'expired':'error');return;}
      attempts.delete(form);root.dataset.revision=j.workflow.revision;confirmed=true;
      const page=await fetch(location.href,{cache:'no-store',headers:{Accept:'text/html'}});if(!page.ok||page.redirected)throw Error('A alteração foi confirmada, mas a tela não atualizou. Recarregue para conferir.');
      const doc=new DOMParser().parseFromString(await page.text(),'text/html');window.ClyoClinicalContext?.refresh(doc);if(!doc.getElementById('plan-live-meals'))throw Error('Confira o estado salvo recarregando a página.');
      swap(doc);window.scrollTo(0,scrollY);
      if(dialog&&document.contains(form)){window.ClyoForms?.discard(form);window.ClyoDialog?.close(dialog);}
      if(form.isConnected)window.ClyoForms?.saved(form);
      else if(form.id&&document.getElementById(form.id)){
        const freshInline=document.getElementById(form.id);
        freshInline.querySelector('[data-food-query], input:not([type=hidden])')?.focus();
      }
      else {const fresh=[...document.forms].find(f=>f.action===form.action);if(fresh){let node=fresh.parentElement;while(node){if(node.tagName==='DETAILS')node.open=true;node=node.parentElement;}const target=fresh.elements[focusedName]||fresh.querySelector('input:not([type=hidden]),button');target?.focus();}else root.querySelector('input:not([type=hidden])')?.focus();}
      const freshRoot=doc.querySelector('[data-quick-plan]');if(freshRoot){root.dataset.revision=freshRoot.dataset.revision;for(const selector of ['#quick-meal-select','[data-copy-meals]']){const local=root.querySelector(selector),fresh=freshRoot.querySelector(selector),value=local.value;local.replaceChildren(...[...fresh.options].map(o=>o.cloneNode(true)));local.value=value;}}
      window.ClyoWorkflow?.set(root,window.ClyoForms?.dirtyForms().some(f=>root.contains(f))?'editing':'saved',j.workflow.saved_at);announce('Salvo como rascunho.');
    }catch(err){showFormError(form,window.ClyoWorkflow?.netMessage?.(err)??err.message);if(confirmed){root.dataset.needsReload='1';window.ClyoWorkflow?.set(root,'saved');}}finally{restoreButtons(form);}
  });

  // Sugestão pela mesma energia (substituição).
  const subForm = document.getElementById('add-sub-item-id')?.form;
  if (!subForm) return;
  const hint = subForm.querySelector('[data-same-energy-hint]');
  const text = hint?.querySelector('[data-same-energy-text]');
  const fill = hint?.querySelector('[data-same-energy-fill]');
  let grams = null;
  const reset = () => { grams = null; if (hint) hint.hidden = true; };
  subForm.addEventListener('clyo:sub-item', reset);
  subForm.addEventListener('clyo:discard', reset);
  subForm.addEventListener('clyo:food-chosen', ev => {
    if (!hint) return;
    const itemKcal = Number(subForm.dataset.itemKcal), per100 = Number(ev.detail?.energy_kcal);
    if (!(itemKcal > 0) || !(per100 > 0) || !isFinite(itemKcal) || !isFinite(per100)) { reset(); return; }
    const g = Math.round(itemKcal / (per100 / 100));
    if (!(g > 0) || g > 5000) { reset(); return; }
    grams = g;
    text.textContent = 'Para a mesma energia do alimento principal (' + fmt(itemKcal, 0) + ' kcal), seriam cerca de ' + fmt(g, 0) + ' g deste alimento. '
      + 'A conta usa só a energia, não é equivalência nutricional: confira proteína, carboidrato e gordura antes de salvar.';
    hint.hidden = false;
  });
  fill?.addEventListener('click', () => {
    if (grams === null) return;
    const amount = subForm.querySelector('[name="amount"]'), unit = subForm.querySelector('[name="measure_unit"]');
    if (unit) { unit.value = 'g'; unit.dispatchEvent(new Event('change', { bubbles: true })); }
    if (amount) { amount.value = String(grams); amount.dispatchEvent(new Event('input', { bubbles: true })); amount.focus(); }
  });
})();
