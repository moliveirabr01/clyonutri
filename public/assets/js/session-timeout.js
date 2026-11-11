// NI2-604 (UX-R3-03): aviso 5 minutos antes do fim da sessão por inatividade, com "Continuar conectado".
// - Uso na página (digitar, clicar) renova a sessão no servidor no máximo a cada 5 min (formulários longos).
// - Antes de avisar ou encerrar, confere o tempo real no servidor (outra aba pode ter renovado).
// - Ao terminar, vai para o login com o motivo e volta para esta página depois de entrar.
(() => {
  const ttl = parseInt(document.body.dataset.sessionTtl || '0', 10);
  if (!ttl || ttl < 600) return;
  const WARN = 5 * 60 * 1000, RENEW_EVERY = 5 * 60 * 1000;
  let deadline = Date.now() + ttl * 1000, lastRenew = Date.now(), box = null, countdown = null, busy = false;
  const back = () => location.pathname + location.search;
  const toLogin = () => { location.href = '/login?error=session_timeout&voltar=' + encodeURIComponent(back()); };
  const get = async (url) => {
    const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    if (r.status === 401) return { authenticated: false };
    return r.json();
  };
  const fmt = (ms) => { const s = Math.max(0, Math.round(ms / 1000)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };

  function hide() { if (box) { box.hidden = true; box.querySelector('[data-session-renew-error]')?.remove(); } clearInterval(countdown); countdown = null; }
  function show() {
    if (!box) {
      box = document.createElement('div');
      box.className = 'session-warning';box.dataset.dialog='modal';box.hidden=true; box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-live', 'assertive');
      box.setAttribute('aria-labelledby', 'session-warning-title'); box.dataset.testid = 'session-warning';
      const t = document.createElement('strong'); t.id = 'session-warning-title'; t.textContent = 'Sua sessão vai encerrar por inatividade';
      const p = document.createElement('p'); p.dataset.sessionLeft = '';p.id='session-warning-description';p.setAttribute('aria-live','off');box.setAttribute('aria-describedby',p.id);
      const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-primary'; b.textContent = 'Continuar conectado';
      b.dataset.testid = 'session-continue';
      b.addEventListener('click', () => renew(true));
      box.append(t, p, b);box.addEventListener('clyo:dialog-closed',()=>{box.hidden=true;clearInterval(countdown);countdown=null;}); document.body.append(box);
    }
    box.hidden = false;
    window.ClyoDialog?.open(box);
    const left = box.querySelector('[data-session-left]');
    const tick = () => { left.textContent = 'Faltam ' + fmt(deadline - Date.now()) + '. O que você digitou nesta página continua aqui.'; };
    tick(); clearInterval(countdown); countdown = setInterval(tick, 1000);
    box.querySelector('button').focus();
  }
  async function renew(fromButton) {
    if (busy) return; busy = true;
    try {
      const d = await get('/sessao/renovar');
      if (!d.authenticated) { toLogin(); return; }
      deadline = Date.now() + (d.remaining || ttl) * 1000; lastRenew = Date.now(); hide();
    } catch (e) {
      if(fromButton){
        show();
        let error=box.querySelector('[data-session-renew-error]');
        if(!error){error=document.createElement('p');error.className='op-error';error.setAttribute('role','alert');error.dataset.sessionRenewError='';box.append(error);}
        error.textContent='Não foi possível confirmar a renovação. Confira a conexão e tente continuar conectado novamente.';
      }
    } finally { busy = false; }
  }
  async function check() {
    const left = deadline - Date.now();
    if (left > WARN) return;
    try {
      const d = await get('/sessao/estado'); // não conta como uso
      if (!d.authenticated || d.remaining <= 0) { toLogin(); return; }
      deadline = Date.now() + d.remaining * 1000;
      if (d.remaining * 1000 <= WARN) show(); else hide();
    } catch (e) { if (left <= 0) toLogin(); }
  }
  // Uso real na página conta como atividade (sem esperar o envio do formulário).
  const onUse = () => { if (Date.now() - lastRenew > RENEW_EVERY && (!box || box.hidden)) renew(false); };
  ['keydown', 'pointerdown', 'input'].forEach((ev) => document.addEventListener(ev, onUse, { passive: true, capture: true }));
  setInterval(check, 15000);
  window.ClyoSession = { check, renew, _setDeadline: (ms) => { deadline = Date.now() + ms; } }; // para testes
})();
