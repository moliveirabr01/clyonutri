(() => {
  document.querySelectorAll('form[data-availability-preview]').forEach(form => {
    const output=form.querySelector('[data-availability-feedback]'),date=form.elements.scheduled_date,time=form.elements.scheduled_time;
    let sequence=0,controller;
    // NI2-591: aviso pela data escolhida no formulário (não pelo dia aberto na agenda).
    const warn=document.getElementById('patient-same-day-warning'),allowLabel=document.getElementById('label-allow-same-day'),allowBox=document.getElementById('input_allow_same_day');
    function sameDay(timeText){
      if(!warn)return;
      if(timeText){warn.textContent='Este paciente já tem consulta neste dia às '+timeText+'. Para manter as duas, confirme abaixo.';warn.style.display='block';if(allowLabel)allowLabel.style.display='flex';}
      else{warn.style.display='none';warn.textContent='';if(allowLabel&&!(allowBox&&allowBox.checked))allowLabel.style.display='none';}
    }
    async function preview(){
      const request=++sequence;controller?.abort();controller=new AbortController();
      if(!date.value){output.textContent='Selecione uma data para conferir a jornada.';return;}
      output.textContent='Conferindo horários…';
      const patient=form.elements.patient_id?form.elements.patient_id.value:'';
      const query=new URLSearchParams({availability_preview:'1',date:date.value,time:time.value,duration:form.elements.duration_minutes.value,patient_id:patient});
      try{
        const response=await fetch('/agenda?'+query,{headers:{Accept:'application/json'},signal:controller.signal});const data=await response.json();
        if(request!==sequence)return;
        if(!response.ok)throw Error(data.error||'Não foi possível conferir a disponibilidade.');
        sameDay(data.same_day_time||null);
        output.replaceChildren();const message=document.createElement('p');
        message.textContent=data.selected_reason||data.message;output.append(message);
        if(data.can_override_conflict && form.elements.allow_conflict.checked){const note=document.createElement('p');note.textContent='Encaixe selecionado. A jornada, os feriados e os bloqueios continuam obrigatórios; o servidor revalida ao salvar.';output.append(note);}
        if(data.times.length){const label=document.createElement('label');label.textContent='Horários sugeridos (sem reserva): ';const options=document.createElement('select');options.className='form-control';options.add(new Option('Escolha uma sugestão',''));
          data.times.forEach(value=>options.add(new Option(value,value)));options.value=data.times.includes(time.value)?time.value:'';
          options.addEventListener('change',()=>{if(options.value){time.value=options.value;time.dispatchEvent(new Event('change',{bubbles:true}));time.focus();}});label.append(options);output.append(label);
        }
        const note=document.createElement('small');note.textContent='Prévia sem reserva: outro agendamento pode ocupar a vaga. Confira o resultado ao confirmar.';output.append(note);
      }catch(error){if(error.name!=='AbortError'&&request===sequence)output.textContent=error.message+' Seus dados foram mantidos; tente novamente antes de confirmar.';}
    }
    [date,time,form.elements.duration_minutes,form.elements.type,form.elements.allow_conflict,form.elements.patient_id].forEach(input=>input?.addEventListener('change',preview));preview();
  });
})();
