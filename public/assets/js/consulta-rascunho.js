/* UX-03 (v14): rascunho das anotações da consulta (/consulta/{id}).
 * Salva automaticamente (1,5 s após digitar), mostra o estado, protege a saída
 * (beforeunload + "Pausar e Voltar à Agenda") e o servidor restaura o texto ao retomar. */
(function () {
  var form = document.querySelector('form[data-consult-draft]');
  if (!form) return;
  var url = form.getAttribute('data-draft-url');
  var csrf = form.getAttribute('data-csrf');
  var version = parseInt(form.getAttribute('data-draft-version') || '0', 10);
  var statusEl = document.getElementById('consulta-draft-status');
  var names = ['evolution_summary', 'weight_kg', 'waist_cm'];
  var timer = null, saving = null, dirty = false, submitting = false;

  function say(text, tone) { if (statusEl) { statusEl.textContent = text; statusEl.dataset.tone = tone || 'info'; } }
  function hhmm(utc) { if (!utc) return ''; var d = new Date(utc.replace(' ', 'T') + 'Z'); return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
  function values() { var o = { version: version }; names.forEach(function (n) { var el = form.elements[n]; o[n] = el ? el.value : ''; }); return o; }

  function save() {
    clearTimeout(timer);
    if (!dirty) return Promise.resolve(true);
    if (saving) return saving.then(save);
    dirty = false;
    say('Salvando rascunho…');
    saving = fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(values()) })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.status === 409) {
          version = res.body.version || 0; dirty = true;
          say('Há uma versão mais nova deste rascunho em outra aba. O texto desta tela será gravado por cima na próxima alteração.', 'warn');
          return false;
        }
        if (res.status >= 400) throw new Error(res.body.error || 'Erro');
        version = res.body.version;
        say('Rascunho salvo às ' + hhmm(res.body.updated_at_utc) + '.', 'ok');
        return true;
      })
      .catch(function () { dirty = true; say('Não foi possível salvar o rascunho agora. Não feche a página; tentaremos de novo.', 'error'); timer = setTimeout(save, 5000); return false; })
      .then(function (ok) { saving = null; return ok; });
    return saving;
  }
  function schedule() { dirty = true; say('Alterações não salvas…', 'warn'); clearTimeout(timer); timer = setTimeout(save, 1500); }
  names.forEach(function (n) { var el = form.elements[n]; if (el) el.addEventListener('input', schedule); });

  window.addEventListener('beforeunload', function (ev) { if (!submitting && (dirty || saving)) { ev.preventDefault(); ev.returnValue = ''; } });
  form.addEventListener('submit', function () { submitting = true; clearTimeout(timer); });

  var pause = form.querySelector('[data-draft-pause]');
  if (pause) pause.addEventListener('click', function (ev) {
    if (!dirty && !saving) return; // tudo salvo: segue para a agenda
    ev.preventDefault();
    save().then(function (ok) {
      if (ok || window.confirm('O rascunho não pôde ser salvo. Sair mesmo assim e perder as últimas alterações?')) {
        submitting = true; window.location.href = pause.href;
      }
    });
  });
})();
