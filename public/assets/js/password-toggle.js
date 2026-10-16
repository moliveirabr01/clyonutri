/* NI2-425 (UX-13): mostrar/ocultar senha. Só altera a apresentação: botão type=button (não submete),
   estado anunciado por aria-pressed + rótulo; copiar/colar e gerenciadores de senha continuam funcionando. */
(() => {
  document.querySelectorAll('input[type="password"][data-password-toggle]').forEach(input => {
    const wrap = document.createElement('div'); wrap.className = 'password-field';
    input.parentNode.insertBefore(wrap, input); wrap.appendChild(input);
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'password-toggle'; btn.setAttribute('aria-controls', input.id);
    const live = document.createElement('span'); live.className = 'sr-only'; live.setAttribute('aria-live', 'polite');
    const set = shown => {
      input.type = shown ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(shown));
      btn.textContent = shown ? 'Ocultar' : 'Mostrar';
      btn.setAttribute('aria-label', shown ? 'Ocultar senha' : 'Mostrar senha');
    };
    btn.addEventListener('click', () => { const shown = input.type === 'password'; set(shown); live.textContent = shown ? 'Senha visível.' : 'Senha oculta.'; input.focus(); });
    input.form?.addEventListener('submit', () => set(false)); // nunca envia/renderiza a senha como texto após o envio
    set(false); wrap.append(btn, live);
  });
})();
