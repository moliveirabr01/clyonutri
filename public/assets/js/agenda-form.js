/*
 * NI2-591 (UX-008): formulário "Agendar nova consulta" da agenda.
 * Arquivo externo (a CSP bloqueia <script> em linha sem nonce): busca de paciente enquanto digita,
 * "+ novo paciente" (modal), botões da grade de horários livres e o menu "Mais ações".
 * O aviso de "mesmo dia" vem da prévia de disponibilidade (agenda-availability.js), pela data escolhida.
 */
(() => {
  'use strict';
  const modal = document.getElementById('patientModal');
  window.openPatientModal = () => {
    if (!modal) return;
    if (window.ClyoDialog) window.ClyoDialog.open(modal); else modal.classList.add('active');
    const name = document.getElementById('p_name'); if (name) name.focus();
  };
  window.closePatientModal = () => {
    if (!modal) return;
    if (window.ClyoDialog) window.ClyoDialog.close(modal); else modal.classList.remove('active');
  };

  const searchInput = document.getElementById('appointment-patient-search');
  const select = document.getElementById('dc11-patient_id');
  const matchesBox = document.getElementById('appointment-patient-matches');
  if(select&&searchInput&&matchesBox){
    searchInput.setAttribute('role','combobox');searchInput.setAttribute('aria-required',String(select.required));searchInput.setAttribute('aria-autocomplete','list');searchInput.setAttribute('aria-controls',matchesBox.id);searchInput.setAttribute('aria-expanded','false');
    matchesBox.setAttribute('role','listbox');matchesBox.setAttribute('aria-label','Pacientes encontrados');
    const status=document.createElement('p');status.className='visually-hidden';status.setAttribute('role','status');searchInput.after(status);
    const all=[...select.options].filter(o=>o.value).map(o=>({id:o.value,name:o.text.trim()}));
    const norm=v=>(v||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    let active=-1, found=[];
    const close=()=>{matchesBox.style.display='none';searchInput.setAttribute('aria-expanded','false');searchInput.removeAttribute('aria-activedescendant');active=-1;};
    const choose=person=>{select.value=person.id;searchInput.value=person.name;close();select.dispatchEvent(new Event('change',{bubbles:true}));};
    const highlight=index=>{
      if(!found.length)return;
      active=(index+found.length)%found.length;
      const options=[...matchesBox.querySelectorAll('[role=option]')];
      options.forEach((el,i)=>el.setAttribute('aria-selected',String(i===active)));
      searchInput.setAttribute('aria-activedescendant',options[active].id);
      options[active].scrollIntoView({block:'nearest'});
    };
    const render=(forceOpen=false)=>{
      active=-1;searchInput.removeAttribute('aria-activedescendant');matchesBox.replaceChildren();
      const query=norm(searchInput.value.trim());
      if(!forceOpen&&query.length<1){close();status.textContent='Digite para buscar ou use a seta para baixo para listar pacientes.';return;}
      found=(query.length?all.filter(person=>norm(person.name).includes(query)):all).slice(0,10);
      found.forEach((person,i)=>{
        const item=document.createElement('div');item.className='patient-match-option';item.id=matchesBox.id+'-option-'+i;item.setAttribute('role','option');item.setAttribute('aria-selected','false');item.textContent=person.name;
        item.addEventListener('pointerdown',e=>e.preventDefault());
        item.addEventListener('click',()=>choose(person));matchesBox.append(item);
      });
      status.textContent=found.length?found.length+' paciente(s) encontrado(s). Use as setas e Enter para selecionar.':'Nenhum paciente encontrado. Use o botão novo paciente ou a lista de pacientes.';
      if(!found.length){close();return;}
      matchesBox.classList.remove('u-hidden');
      matchesBox.style.display='block';searchInput.setAttribute('aria-expanded','true');
    };
    if(select.value)searchInput.value=select.options[select.selectedIndex].text.trim();
    select.addEventListener('change',()=>{const option=select.options[select.selectedIndex];if(option?.value)searchInput.value=option.text.trim();});
    searchInput.addEventListener('input',()=>{const previous=select.value;select.value='';if(previous)select.dispatchEvent(new Event('change',{bubbles:true}));render(false);});
    searchInput.addEventListener('keydown',e=>{
      const open=searchInput.getAttribute('aria-expanded')==='true';
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){
        if(!open)render(true);if(!found.length||searchInput.getAttribute('aria-expanded')!=='true')return;
        e.preventDefault();highlight(active<0?(e.key==='ArrowDown'?0:found.length-1):active+(e.key==='ArrowDown'?1:-1));
      }else if(e.key==='Enter'&&open&&(active>=0||found.length===1)){e.preventDefault();choose(found[active>=0?active:0]);}
      else if(e.key==='Escape'&&open){e.preventDefault();e.stopPropagation();close();}
      else if(e.key==='Tab')close();
    });
    document.addEventListener('click',e=>{if(!matchesBox.contains(e.target)&&e.target!==searchInput)close();});
    searchInput.addEventListener('blur',()=>setTimeout(close,0));
  }

  document.querySelectorAll('.agenda-slot-btn').forEach(btn => btn.addEventListener('click', () => {
    const card = document.getElementById('card-novo-agendamento'); if (!card) return;
    card.style.display = 'block';
    const time = document.getElementById('new-appointment-time');
    if (time) { time.value = btn.getAttribute('data-slot-time') || ''; time.dispatchEvent(new Event('change', { bubbles: true })); }
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));

  /*
   * "Mais ações": o menu abre em position:fixed, calculado pelo botão. Dentro de .table-responsive
   * (overflow-x:auto) um menu absoluto era cortado e "Não compareceu"/"Cancelar" não recebiam clique.
   */
  const place = det => {
    const menu = det.querySelector('[data-more-actions-menu]'), btn = det.querySelector('summary');
    if (!menu || !btn) return;
    const r = btn.getBoundingClientRect();
    const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
    // NI2-607: sem right:0 (o estilo em linha esticava o menu) e largura do conteúdo, limitada à área visível.
    // O menu fica dentro da área de conteúdo: não cobre o menu lateral.
    const area = document.querySelector('.app-main')?.getBoundingClientRect();
    const minLeft = Math.max(8, area && area.left < vw / 2 ? area.left + 8 : 8);
    Object.assign(menu.style, { position: 'fixed', visibility: 'hidden', right: 'auto', left: '0px', top: '0px', margin: '0',
      width: 'max-content', maxWidth: Math.max(140, vw - minLeft - 8) + 'px' });
    menu.style.maxHeight=Math.max(44,vh-16)+'px';menu.style.overflow='auto';
    const mw = menu.offsetWidth, mh = menu.offsetHeight;
    const left = Math.max(minLeft, Math.min(r.right - mw, vw - mw - 8)); // alinhado à direita do botão
    let top = r.bottom + 4; if (top + mh > vh - 8 && r.top - mh - 4 > 8) top = r.top - mh - 4;
    menu.style.left = left + 'px'; menu.style.top = Math.max(8,Math.min(top,vh-menu.offsetHeight-8)) + 'px'; menu.style.visibility = '';
  };
  const menus = [...document.querySelectorAll('details[data-more-actions]')];
  menus.forEach(det => det.addEventListener('toggle', () => {
    if (det.open) { menus.forEach(o => { if (o !== det) o.open = false; }); place(det); }
  }));
  const reposition=()=>menus.filter(d=>d.open).forEach(d=>{
    const summary=d.querySelector('summary'),r=summary.getBoundingClientRect();
    if(r.bottom<0||r.top>innerHeight){d.open=false;if(d.contains(document.activeElement))summary.focus();}
    else place(d);
  });
  window.addEventListener('scroll',e=>{if(e.target instanceof Element&&e.target.closest('[data-more-actions-menu]'))return;reposition();},{passive:true,capture:true});
  window.addEventListener('resize',reposition);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { const o = menus.find(d => d.open); if (o) { o.open = false; o.querySelector('summary')?.focus(); } } });
  document.addEventListener('click', e => menus.forEach(d => { if (d.open && !d.contains(e.target)) d.open = false; }));
})();
