(() => {
 'use strict';
 const labels={not_started:'Não iniciado',editing:'Em edição — alterações não salvas',saving:'Salvando…',saved:'Salvo como rascunho',review:'Em revisão',completed:'Concluído',published:'Publicado',issued:'Emitido — sem assinatura digital',conflict:'Conflito de edição — nada foi sobrescrito',error:'Não foi possível confirmar o salvamento',expired:'Sessão expirada — salvamento não confirmado'};
 function set(root,key,at){const el=root?.matches?.('[data-workflow-state]')?root:root?.querySelector?.('[data-workflow-state]');if(!el)return;el.dataset.state=key;el.querySelector('[data-state-label]').textContent=labels[key]||key;if(at!==undefined&&el.querySelector('[data-state-time]'))el.querySelector('[data-state-time]').textContent=at?' · '+at:'';}
 window.ClyoWorkflow={set,labels};
 document.querySelectorAll('form').forEach(form=>{if(form.querySelector('[data-workflow-state]')&&window.ClyoForms?.isDirty(form))set(form,'editing');});
 document.addEventListener('input',e=>{const form=e.target.closest('form');if(!form||e.target.matches('input[type=search],[data-transient]'))return;const root=form.closest('[data-quick-plan]')||form;if(root.querySelector('[data-workflow-state]'))set(root,'editing');});
 // Native disclosures also work after an authorized partial refresh.
 const mounted=new WeakSet();
 const selector='details.plan-actions-dropdown,details[data-op-menu]';
 function position(det){
  if(!det.open)return;
  const menu=det.querySelector('.plan-actions-menu,.op-menu'),summary=det.querySelector(':scope > summary');
  if(!menu||!summary)return;
  menu.classList.add('op-menu');
  const r=summary.getBoundingClientRect(),gap=8;
  const below=Math.max(0,innerHeight-r.bottom-gap),above=Math.max(0,r.top-gap);
  const down=below>=Math.min(menu.scrollHeight,280)||below>=above;
  Object.assign(menu.style,{position:'fixed',right:'auto',bottom:'auto',maxHeight:Math.max(44,down?below:above)+'px',overflow:'auto',maxWidth:'calc(100vw - 16px)'});
  const rect=menu.getBoundingClientRect();
  menu.style.left=Math.max(gap,Math.min(r.left,innerWidth-rect.width-gap))+'px';
  menu.style.top=Math.max(gap,down?r.bottom+4:r.top-rect.height-4)+'px';
 }
 function mount(root=document){
  root.querySelectorAll('table').forEach(table=>{
   table.classList.add('op-table');
   let wrap=table.closest('.table-responsive');
   if(!wrap){wrap=document.createElement('div');wrap.className='table-responsive';table.before(wrap);wrap.append(table);}
   if(getComputedStyle(table).display!=='table'&&!table.hasAttribute('role'))table.setAttribute('role','table');
   const ownScroll=table.scrollWidth>table.clientWidth&&['auto','scroll'].includes(getComputedStyle(table).overflowX);
   const target=ownScroll?table:wrap;
   if(target.scrollWidth>target.clientWidth){
    target.tabIndex=0;
    if(target!==table&&!target.hasAttribute('role'))target.setAttribute('role','region');
    if(!target.hasAttribute('aria-label')&&!target.hasAttribute('aria-labelledby'))target.setAttribute('aria-label',table.caption?.textContent.trim()||'Tabela de dados — rolagem horizontal');
   }
  });
  const menus=[...(root.matches?.(selector)?[root]:[]),...root.querySelectorAll(selector)];
  menus.forEach(det=>{
   if(mounted.has(det))return;mounted.add(det);
   det.addEventListener('toggle',()=>position(det));
   det.addEventListener('focusout',e=>{if(e.relatedTarget&&!det.contains(e.relatedTarget))det.open=false;});
   det.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();det.open=false;det.querySelector(':scope > summary')?.focus();}});
  });
 }
 window.ClyoOperationalUI={mount};
 mount();
 document.fonts?.ready.then(()=>mount());
 document.addEventListener('toggle',e=>{if(e.target instanceof Element)mount(e.target);},true);
 document.addEventListener('clyo:forms-updated',()=>mount());
 document.addEventListener('click',e=>{document.querySelectorAll(selector).forEach(det=>{if(det.open&&!det.contains(e.target))det.open=false;});});
 const reposition=()=>document.querySelectorAll(selector).forEach(position);
 window.addEventListener('resize',()=>{mount();reposition();});
 // Scroll events inside the menu must not dismiss or jump its panel.
 document.addEventListener('scroll',e=>{if(e.target instanceof Element&&e.target.closest('.op-menu'))return;reposition();},true);
 // Invalid controls in collapsed sections must be visible before focus is moved to them.
 document.addEventListener('invalid',e=>{let node=e.target.parentElement;while(node){if(node.tagName==='DETAILS')node.open=true;node=node.parentElement;}},true);
})();
