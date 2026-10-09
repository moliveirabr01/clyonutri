(() => {
  const recovery=document.getElementById('clinical-form-recovery');
  if(recovery){
    const saved=JSON.parse(recovery.textContent);
    const form=[...document.forms].find(f=>new URL(f.action).pathname===saved.action&&f.method.toLowerCase()==='post');
    if(form){
      for(const field of form.elements){
        const name=field.name.replace(/\[\]$/,'');if(!(name in saved.values)||field.type==='hidden')continue;
        const value=saved.values[name];
        if(field.type==='checkbox'||field.type==='radio')field.checked=Array.isArray(value)?value.includes(field.value):value===field.value;
        else if(typeof value==='string')field.value=value;
      }
      const notice=document.createElement('p');notice.setAttribute('role','status');notice.textContent='Preenchimento recuperado após a falha. Confira os dados antes de salvar. Disponível nesta sessão por até uma hora.';form.prepend(notice);
      const modal=form.closest('[id^="modal-"]');if(modal)modal.style.display='flex';
    }
  }
  const toggle=document.getElementById('clinical-menu-toggle'),nav=document.getElementById('clinical-navigation');
  toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(open));nav?.classList.toggle('is-open',open);});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&toggle?.getAttribute('aria-expanded')==='true'){toggle.click();toggle.focus();}});
  document.querySelectorAll('.app-nav__item.active').forEach(el=>el.setAttribute('aria-current','page'));
  const dirtyForms=new Set();
  document.querySelectorAll('form[method="POST"],form[method="post"]').forEach(form=>{
    form.addEventListener('input',()=>dirtyForms.add(form));
    form.addEventListener('reset',()=>dirtyForms.delete(form));
    form.addEventListener('submit',()=>{dirtyForms.clear();form.querySelectorAll('button[type="submit"]').forEach(button=>{setTimeout(()=>{button.disabled=true;button.setAttribute('aria-busy','true');},0);});});
  });
  document.querySelectorAll('[id^="modal-"]').forEach(modal=>{
    if(!modal.querySelector('form'))return;
    let trigger=null,wasOpen=false;
    const focusable=()=>[...modal.querySelectorAll('button,input,select,textarea,a[href],[tabindex]')].filter(el=>!el.disabled&&el.type!=='hidden'&&el.getClientRects().length);
    const state=()=>{
      const open=getComputedStyle(modal).display!=='none';
      if(open&&!wasOpen){trigger=document.activeElement;modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');const heading=modal.querySelector('h2,h3');if(heading){heading.id ||= modal.id+'-title';modal.setAttribute('aria-labelledby',heading.id);}focusable()[0]?.focus();}
      if(!open&&wasOpen&&trigger?.isConnected)trigger.focus();wasOpen=open;
    };
    new MutationObserver(state).observe(modal,{attributes:true,attributeFilter:['style','class']});state();
    modal.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();modal.style.display='none';}
      if(e.key==='Tab'){const fields=focusable();const first=fields[0],last=fields.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}}
    });
  });
  window.addEventListener('beforeunload',e=>{if(dirtyForms.size){e.preventDefault();e.returnValue='';}});
})();
