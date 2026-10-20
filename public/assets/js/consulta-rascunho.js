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
  var timer = null, saving = null, dirty = false, submitting = false, inConflict = false;

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
    statusEl.dataset.state = state || 'idle';
    if (correlationId) {
      statusEl.dataset.correlationId = correlationId;
    } else {
      delete statusEl.dataset.correlationId;
    }
  }
  function hhmm(utc) { if (!utc) return ''; var d = new Date(utc.replace(' ', 'T') + 'Z'); return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
  function values() { var o = { version: version }; names.forEach(function (n) { var el = form.elements[n]; o[n] = el ? el.value : ''; }); return o; }

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
        if (el && typeof saved.values[n] === 'string') el.value = saved.values[n];
      });
      dirty = true;
      say('Alterações locais não enviadas foram restauradas nesta aba. Salvando rascunho…', 'warn', 'salvando');
      timer = setTimeout(save, 400);
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
          version = (res.body && typeof res.body.version === 'number') ? res.body.version : version;
          dirty = true;
          inConflict = true;
          backupLocally(payload);
          say('Conflito de versão: outra aba salvou alterações mais recentes (v' + version + '). Edite o campo para confirmar e sobrescrever com o texto desta aba ou recarregue a página.', 'warn', 'conflito');
          return false;
        }
        if (res.redirected || res.status === 401 || res.status === 403 || (res.body && (res.body.status === 'unauthenticated' || res.body.status === 'forbidden'))) {
          dirty = true;
          backupLocally(payload);
          if (statusEl) statusEl.dataset.authExpired = '1';
          var authMsg = (res.body && res.body.error) || 'Sessão expirada ou token inválido (HTTP ' + (res.status || 401) + ').';
          say(authMsg + ' Seu texto está preservado nesta aba; recarregue a página ou faça login em outra aba para continuar.', 'error', 'erro');
          return false;
        }
        if (res.status >= 400 || !res.body || res.body.status !== 'saved') {
          var err = new Error((res.body && res.body.error) || 'Erro ao salvar rascunho');
          err.correlationId = res.body && res.body.correlation_id ? res.body.correlation_id : '';
          throw err;
        }
        version = res.body.version;
        inConflict = false;
        if (statusEl) delete statusEl.dataset.authExpired;
        if (!dirty) {
          clearLocalBackup();
        }
        say('Rascunho salvo às ' + hhmm(res.body.updated_at_utc) + '.', 'ok', 'salvo');
        return true;
      })
      .catch(function (err) {
        dirty = true;
        backupLocally(payload);
        var cid = err && err.correlationId ? ' (ref. ' + err.correlationId + ')' : '';
        say('Erro ao salvar o rascunho' + cid + '. Seu texto está preservado nesta aba; tentaremos novamente.', 'error', 'erro', err && err.correlationId);
        if (!submitting) {
          timer = setTimeout(save, 5000);
        }
        return false;
      })
      .then(function (ok) { saving = null; return ok; });
    return saving;
  }
  function schedule() {
    dirty = true;
    inConflict = false;
    backupLocally(values());
    say('Alterações não salvas…', 'warn', 'salvando');
    clearTimeout(timer);
    timer = setTimeout(save, 1500);
  }
  names.forEach(function (n) { var el = form.elements[n]; if (el) el.addEventListener('input', schedule); });

  restorePendingLocalBackupIfSafe();

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
    inConflict = false;
    save().then(function (ok) {
      if (ok || window.confirm('O rascunho não pôde ser salvo. Sair mesmo assim e perder as últimas alterações?')) {
        submitting = true; window.location.href = pause.href;
      }
    });
  });
})();


