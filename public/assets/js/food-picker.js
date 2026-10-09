(() => {
  document.querySelectorAll('[data-food-picker]').forEach(box=>{
    const input=box.querySelector('[data-food-query]'), select=box.querySelector('select'),status=box.querySelector('[role=status]'), next=box.querySelector('[data-food-next]'), retry=box.querySelector('[data-food-retry]');
    let page=1,timer,controller,sequence=0,started=false;
    async function load(append=false){
      started=true;next.hidden=true;select.disabled=true;const seq=++sequence;controller?.abort();controller=new AbortController();status.textContent='Buscando alimentos…';box.setAttribute('aria-busy','true');retry.hidden=true;
      try{
        const response=await fetch('/catalogo/alimentos/busca?q='+encodeURIComponent(input.value)+'&page='+page,{signal:controller.signal,headers:{Accept:'application/json'}});
        if(!response.ok)throw Error('Falha na busca');const data=await response.json();if(seq!==sequence)return;
        const selected=select.value;if(!append)select.replaceChildren(new Option('Selecione um alimento',''));data.data.forEach(food=>select.add(new Option(food.name+' · '+food.energy_kcal+' kcal/100g',food.id)));if([...select.options].some(o=>o.value===selected))select.value=selected;
        status.textContent=data.data.length ? data.data.length+' alimentos encontrados. Use o seletor abaixo.' : 'Nenhum alimento corresponde à busca.';next.hidden=!data.has_more;
      }catch(error){if(error.name!=='AbortError'){status.textContent='Não foi possível buscar alimentos. Tente novamente.';retry.hidden=false;}}
      finally{if(seq===sequence){box.setAttribute('aria-busy','false');select.disabled=false;}}
    }
    input.addEventListener('focus',()=>{if(!started)load();});
    input.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();sequence++;page=1;select.replaceChildren(new Option('Selecione um alimento',''));next.hidden=true;timer=setTimeout(()=>load(),250);});
    next.addEventListener('click',()=>{page++;load(true);});retry.addEventListener('click',()=>load(page>1));
    input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();select.focus();}});
  });
})();
