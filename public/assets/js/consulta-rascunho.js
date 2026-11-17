/* UX-03 (v14/v15/v16): rascunho das anotações da consulta (/consulta/{id}).
 * Salva automaticamente (1,5 s após digitar), indica explicitamente salvando/salvo/erro/conflito,
 * preserva cópia temporária (TTL 15 min, escopada por clínica+usuário+consulta) em caso de falha de rede/POST,
 * nunca limpa o backup local antes da confirmação do servidor no submit, protege a saída
 * (beforeunload + "Pausar e Voltar à Agenda") e limpa dados ao concluir ou encerrar sessão. */
(function () {
  var KEY_PREFIX = 'cn_consult_draft:';
  var MAX_AGE_MS = 15 * 60 * 1000; // 15 minutos (evita retenção prolongada de PHI no navegador)

  var closedEl = document.querySelector('[data-testid="consult-closed"][data-draft-scope]');
  if (closedEl) {
    try { window.sessionStorage.removeItem(KEY_PREFIX + closedEl.getAttribute('data-draft-scope')); } catch (e) {}
  }

  var form = document.querySelector('form[data-consult-draft]');
  if (!form) return;
  var url = form.getAttribute('data-draft-url');
  var csrf = form.getAttribute('data-csrf');
  var scope = form.getAttribute('data-draft-scope') || (url || window.location.pathname);
  var userPrefix = scope.split(':').slice(0, 2).join(':') + ':';
  var version = parseInt(form.getAttribute('data-draft-version') || '0', 10);
  var statusEl = document.getElementById('consulta-draft-status');
  var names = ['evolution_summary', 'weight_kg', 'waist_cm'];
  var storageKey = KEY_PREFIX + scope;
  var timer = null, saving = null, dirty = false, submitting = false, inConflict = false, retryCount = 0;

  function purgeStaleOrForeignBackups() {
    try {
      var now = Date.now();
      for (var i = window.sessionStorage.length - 1; i >= 0; i--) {
        var k = window.sessionStorage.key(i);
        if (!k) continue;
        if (k.indexOf('clyo_consult_draft_v1:') === 0 || k.indexOf('clyo_consult_draft_v2:') === 0) {
          window.sessionStorage.removeItem(k);
          continue;
        }
        if (k.indexOf(KEY_PREFIX) !== 0) continue;
        var tail = k.slice(KEY_PREFIX.length);
        if (userPrefix.length > 2 && tail.indexOf(':') !== -1 && tail.indexOf(userPrefix) !== 0) {
          window.sessionStorage.removeItem(k);
          continue;
        }
        var raw = window.sessionStorage.getItem(k);
        var parsed = raw ? JSON.parse(raw) : null;
        if (!parsed || typeof parsed.savedAt !== 'number' || (now - parsed.savedAt) > MAX_AGE_MS) {
          window.sessionStorage.removeItem(k);
        }
      }
    } catch (e) {}
  }

  function say(text, tone, state, correlationId) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.dataset.tone = tone || 'info';
    statusEl.dataset.state = ({salvando:'saving',salvo:'saved',erro:'error',conflito:'conflict'}[state]||state||'not_started');
    if (correlationId) {
      statusEl.dataset.correlationId = correlationId;
    } else {
      delete statusEl.dataset.correlationId;
    }
  }
  function hhmm(utc) { if (!utc) return ''; var d = new Date(utc.replace(' ', 'T') + 'Z'); return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
  function values() { var o = { version: version }; names.forEach(function (n) { var el = form.elements[n]; o[n] = el ? el.value : ''; }); return o; }

  function norm(v) { return v === null || v === undefined ? '' : String(v).trim(); }
  function sameAsServer(payload, server) {
    return names.every(function (n) { return n === 'evolution_summary' ? String(payload[n] || '') === String(server[n] || '') : norm(payload[n]) === norm(server[n]); });
  }
  // v20: ao voltar pelo botão "Voltar" (página restaurada do cache), a versão embutida no HTML pode estar velha.
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && typeof j.version === 'number' && sameAsServer(values(), j)) { version = j.version; inConflict = false; } })
      .catch(function () {});
  });
  function backupLocally(payload) {
    try { window.sessionStorage.setItem(storageKey, JSON.stringify({ scope: scope, savedAt: Date.now(), values: payload })); } catch (e) {}
  }
  function clearLocalBackup() {
    try { window.sessionStorage.removeItem(storageKey); } catch (e) {}
  }

  function restorePendingLocalBackupIfSafe() {
    purgeStaleOrForeignBackups();
    if (form.hasAttribute('data-draft-recovered')) {
      backupLocally(values());
      return;
    }
    try {
      var raw = window.sessionStorage.getItem(storageKey);
      if (!raw) return;
      var saved = JSON.parse(raw);
      if (!saved || !saved.values || (Date.now() - (saved.savedAt || 0)) > MAX_AGE_MS) {
        clearLocalBackup();
        return;
      }
      var localVer = typeof saved.values.version === 'number' ? saved.values.version : 0;
      if (localVer < version) {
        // Servidor já possui versão mais recente confirmada; descarta cópia local obsoleta sem sobrescrever.
        clearLocalBackup();
        return;
      }
      var currentVals = values();
      var hasDiff = names.some(function (n) {
        return typeof saved.values[n] === 'string' && saved.values[n] !== currentVals[n];
      });
      if (!hasDiff) {
        clearLocalBackup();
        return;
      }
      names.forEach(function (n) {
        var el = form.elements[n];
        if (el && typeof saved.values[n] === 'string') {
          el.value = saved.values[n];
        }
      });
      dirty = true;
      say('Rascunho recuperado após reconexão — sincronizando…', 'info', 'salvando');
      if (statusEl && typeof statusEl.after === 'function' && typeof document.createElement === 'function') {
        var note = document.createElement('p');
        note.className = 'op-message op-message--info';
        if (note.setAttribute) {
          note.setAttribute('role', 'status');
          note.setAttribute('data-testid', 'draft-recovered-banner');
        }
        note.textContent = 'Rascunho recuperado após reconexão. Sincronizando com o servidor…';
        statusEl.after(note);
      }
      timer = setTimeout(save, 500);
    } catch (e) {}
  }

  function save() {
    clearTimeout(timer);
    if (!dirty || inConflict) return Promise.resolve(!dirty && !inConflict);
    if (saving) return saving.then(save);
    dirty = false;
    var payload = values();
    say('Salvando rascunho…', 'info', 'salvando');
    saving = fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(payload)
    })
      .then(function (r) {
        return r.text().then(function (txt) {
          var j = {};
          try { j = JSON.parse(txt); } catch (e) {}
          return { status: r.status, redirected: !!r.redirected, body: j };
        });
      })
      .then(function (res) {
        if (res.status === 409 || (res.body && res.body.status === 'conflict')) {
          // v20: falso conflito — o servidor já tem exatamente este texto (ex.: a 1ª tentativa gravou mas a
          // resposta se perdeu e o retry foi com a versão antiga, ou a página voltou do cache do "Voltar").
          if (res.body && res.body.server && sameAsServer(payload, res.body.server) && typeof res.body.version === 'number') {
            version = res.body.version; inConflict = false;
            if (!dirty) clearLocalBackup();
            say('Salvo como rascunho.', 'ok', 'salvo');
            return true;
          }
          version = (res.body && typeof res.body.version === 'number') ? res.body.version : version;
          dirty = true;
          inConflict = true;
          backupLocally(payload);
          say('Conflito de edição — outra aba salvou alterações. Compare antes de escolher; nada foi sobrescrito.', 'warn', 'conflict');
          form.querySelector('[data-draft-conflict]')?.remove();
          var panel=document.createElement('section');panel.setAttribute('data-draft-conflict','');panel.className='op-alert op-alert--warning';
          var area=document.createElement('div'),title=document.createElement('strong');title.textContent='Respostas atualmente salvas';area.append(title);
          names.forEach(function(n){var label=document.createElement('p');label.textContent=({evolution_summary:'Anotações',weight_kg:'Peso',waist_cm:'Cintura'}[n])+': '+String(res.body.server?.[n]||'Não informado');area.append(label);});
          var keep=document.createElement('button');keep.type='button';keep.className='op-button op-button--secondary';keep.textContent='Retomar o salvo';keep.addEventListener('click',function(){submitting=true;location.reload();});
          var mine=document.createElement('button');mine.type='button';mine.className='op-button op-button--secondary';mine.textContent='Comparei: salvar alterações desta aba';mine.addEventListener('click',function(){inConflict=false;dirty=true;panel.remove();save();});area.append(keep,mine);panel.append(area);statusEl.after(panel);
          return false;
        }
        if (res.redirected || res.status === 401 || res.status === 403 || (res.body && (res.body.status === 'unauthenticated' || res.body.status === 'forbidden'))) {
          dirty = true;
          backupLocally(payload);
          if (statusEl) statusEl.dataset.authExpired = '1';
          var authMsg = (res.body && res.body.error) || 'Sessão expirada ou token inválido (HTTP ' + (res.status || 401) + ').';
          say(authMsg + ' Últimas alterações sem confirmação; retome o último rascunho salvo após entrar novamente.', 'error', 'expired');
          return false;
        }
        if (res.status >= 400 || !res.body || res.body.status !== 'saved') {
          var err = new Error((res.body && res.body.error) || 'Erro ao salvar rascunho');
          err.correlationId = res.body && res.body.correlation_id ? res.body.correlation_id : '';
          throw err;
        }
        version = res.body.version;
        inConflict = false;
        retryCount = 0;
        if (statusEl) delete statusEl.dataset.authExpired;
        if (!dirty) {
          clearLocalBackup();
        }
        say('Salvo como rascunho às ' + hhmm(res.body.updated_at_utc) + '.', 'ok', 'salvo');
        return true;
      })
      .catch(function (err) {
        dirty = true;
        backupLocally(payload);
        var cid = err && err.correlationId ? ' (ref. ' + err.correlationId + ')' : '';
        say('Erro ao salvar o rascunho' + cid + '. Seu texto está preservado nesta aba; tentaremos novamente.', 'error', 'erro', err && err.correlationId);
        if (!submitting && retryCount < 5) {
          var delay = Math.min(30000, 3000 * Math.pow(2, retryCount));
          retryCount++;
          timer = setTimeout(save, delay);
        }
        return false;
      })
      .then(function (ok) { saving = null; return ok; });
    return saving;
  }
  function schedule() {
    dirty = true;
    if(inConflict){backupLocally(values());say('Conflito de edição — compare com o salvo antes de gravar.','warn','conflict');return;}
    backupLocally(values());
    say('Em edição — alterações não salvas.', 'warn', 'editing');
    clearTimeout(timer);
    timer = setTimeout(save, 1500);
  }
  names.forEach(function (n) { var el = form.elements[n]; if (el) el.addEventListener('input', schedule); });

  restorePendingLocalBackupIfSafe();

  window.addEventListener('online', function () {
    if (dirty && !saving && !inConflict) {
      clearTimeout(timer);
      timer = setTimeout(save, 300);
    }
  });

  window.addEventListener('beforeunload', function (ev) { if (!submitting && (dirty || saving)) { ev.preventDefault(); ev.returnValue = ''; } });
  form.addEventListener('submit', function (ev) {
    submitting = true;
    clearTimeout(timer);
    // V15-02 / V16-04: preserva o snapshot local durante o POST para nunca perder texto se a conclusão falhar;
    // se houver fetch() de autosave em voo (`saving`), aguarda sua conclusão (até 1,5 s) antes de prosseguir com o submit.
    backupLocally(values());
    if (saving) {
      ev.preventDefault();
      var currentSaving = saving;
      Promise.race([
        currentSaving,
        new Promise(function (resolve) { setTimeout(resolve, 1500); })
      ]).then(function () {
        backupLocally(values());
        HTMLFormElement.prototype.submit.call(form);
      });
      return;
    }
    if (dirty && !inConflict) {
      save();
    }
  });

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


