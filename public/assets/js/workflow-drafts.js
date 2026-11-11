(() => {
 'use strict';
 document.querySelectorAll('form[data-workflow-draft]').forEach(form=>{
  const buttonDefaults=[...form.querySelectorAll('button[type=submit],button:not([type])')].map(b=>({b,disabled:b.disabled,label:b.textContent}));
  let timer,saving=null,pending=null,blocked=false,serial=0,submitted=false;
  const retry=document.createElement('button');retry.type='button';retry.className='op-button op-button--secondary';retry.textContent='Tentar confirmar o mesmo salvamento';retry.hidden=true;
  const message=document.createElement('p');message.className='op-error';message.setAttribute('role','alert');message.hidden=true;
  const state=form.querySelector('[data-workflow-state]');state.after(message,retry);
  function say(key,text){window.ClyoWorkflow?.set(form,key);if(text){message.textContent=text;message.hidden=false;}}
  // v54.25 NI2-220 (B3): erros por campo devolvidos pelo servidor (422 field_errors) ficam junto do campo.
  function markFields(errs){
   form.querySelectorAll('[data-wf-field-error]').forEach(n=>n.remove());
   form.querySelectorAll('[data-wf-invalid]').forEach(el=>{el.removeAttribute('aria-invalid');el.removeAttribute('data-wf-invalid');const d=el.getAttribute('data-wf-describedby');if(d===null)el.removeAttribute('aria-describedby');else el.setAttribute('aria-describedby',d);el.removeAttribute('data-wf-describedby');});
   let first=null;
   for(const[name,text]of Object.entries(errs||{})){
    const el=form.elements[name];const target=el&&!el.length?el:(el&&el[0]);if(!target||!target.getAttribute)continue;
    const id='err_'+name;if(!document.getElementById(id)){const span=document.createElement('span');span.className='cr-field-error';span.id=id;span.dataset.wfFieldError='';span.textContent=String(text);target.after(span);}
    target.setAttribute('data-wf-describedby',target.getAttribute('aria-describedby')??'');if(!target.hasAttribute('aria-describedby'))target.removeAttribute('data-wf-describedby');
    target.setAttribute('aria-invalid','true');target.setAttribute('aria-describedby',id);target.setAttribute('data-wf-invalid','');first=first||target;
   }
   return first;
  }
  function payload(){const body=new FormData(form);body.delete('_workflow_revision');body.delete('_final_request_key');return body;}
  function fingerprint(body){return JSON.stringify([...body.entries()].filter(([k])=>k!=='_csrf_token'));}
  async function save(){
   clearTimeout(timer);if(blocked)return false;if(saving)return saving;
   const data=payload(),hash=fingerprint(data),currentSerial=serial;
   if(!pending)pending={data,hash,key:crypto.randomUUID(),base:form.dataset.revision};
   const op=pending;const body=new FormData();for(const[k,v]of op.data)body.append(k,v);body.set('_request_key',op.key);body.set('_base_revision',op.base);
   message.hidden=true;retry.hidden=true;say('saving');
   saving=(async()=>{try{
    const r=await fetch(form.dataset.workflowDraft,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
    let j;try{j=await r.json();}catch(_){throw Error('O servidor não confirmou. Somente o último rascunho confirmado pode ser retomado.');}
    if(!r.ok){
     if(r.status===409){blocked=true;say('conflict',j.error+' Use “Retomar o salvo” para comparar. O que está nesta tela ainda não foi salvo.');pending=null;}
     else if([401,403].includes(r.status)){blocked=true;say('expired','Entre novamente para retomar o último rascunho confirmado. As alterações desta tela não têm salvamento confirmado.');pending=null;}
     else if(r.status<500){say('editing',j.error||'Confira os campos. O preenchimento permanece nesta tela.');pending=null;const f=markFields(j.field_errors);if(f&&typeof f.focus==='function')f.focus();}
     else throw Error(j.error||'Sem confirmação do salvamento.');
     return false;
    }
    if(j.status!=='saved'||!j.workflow||typeof j.workflow.revision!=='string')throw Error('Resposta sem confirmação válida. Confira o salvo antes de continuar.');
    pending=null;markFields(null);form.dataset.revision=j.workflow.revision;form.elements._workflow_revision.value=j.workflow.revision;const resume=state.querySelector('[data-resume]');if(resume){resume.hidden=false;resume.href=j.workflow.resume_url;}
    if(j.workflow.kind==='exam'){
     form.dataset.workflowDraft='/api/workflow/exam/'+j.workflow.id;form.action=j.workflow.resume_url;
     history.replaceState(null,'',j.workflow.resume_url);state.querySelector('[data-resume]').href=j.workflow.resume_url;
    }
    if(fingerprint(payload())===op.hash){window.ClyoForms?.saved(form);window.ClyoWorkflow?.set(form,'saved',j.workflow.saved_at);}else{say('editing');timer=setTimeout(save,600);}
    return true;
   }catch(e){say('error',e.message+' Você pode repetir a mesma tentativa sem duplicar a gravação.');retry.hidden=false;return false;}
   finally{saving=null;buttonDefaults.forEach(({b,disabled,label})=>{b.disabled=disabled;b.removeAttribute('aria-busy');b.textContent=label;});}})();return saving;
  }
  function change(e){if(e.target.matches('input[type=search],[data-transient],#exam-category'))return;serial++;say('editing');if(!blocked&&!pending){clearTimeout(timer);timer=setTimeout(save,1200);}}
  form.addEventListener('input',change);form.addEventListener('change',change);
  retry.addEventListener('click',()=>save());
  form.addEventListener('submit',async e=>{
   if(submitted)return;e.preventDefault();const button=e.submitter;
   if(!(await save()))return;
   if(saving||pending||window.ClyoForms?.isDirty(form)){if(!(await save()))return;}
   if(form.id==='exam-request-form'){location.assign(form.action.replace(/\/editar$/,''));return;}
   if(button?.value==='draft'){location.assign(location.pathname+'?success=rascunho_salvo');return;}
   submitted=true;form.requestSubmit(button||undefined);
  });
 });
})();
