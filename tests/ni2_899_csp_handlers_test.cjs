'use strict';
// Unit execution of production listeners; minimal DOM doubles, not a browser/CSP integration test.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const listeners = {};
let accepted = false, confirmCalls = 0;
const form = { hasAttribute: n => n === 'data-confirm', getAttribute: () => 'Confirmar incidente TESTE' };
const event = () => ({ target: form, prevented: false, stopped: false,
  preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } });
vm.runInNewContext(fs.readFileSync(path.join(root, 'public/assets/js/csp-actions.js'), 'utf8'), {
  document: { addEventListener: (n, fn) => { listeners[n] = fn; } },
  window: { confirm: message => { assert.equal(message, 'Confirmar incidente TESTE'); confirmCalls++; return accepted; } }
});
let ev = event(); listeners.submit(ev);
assert.ok(ev.prevented && ev.stopped); console.log('PASS confirmação recusada bloqueia envio');
accepted = true; ev = event(); listeners.submit(ev);
assert.ok(!ev.prevented && !ev.stopped); assert.equal(confirmCalls, 2); console.log('PASS confirmação aceita permite envio');
const panels = { 'tab-login': { hidden: false }, 'tab-first': { hidden: true } };
function tab(id, panel) { return { id, attributes: { 'aria-controls': panel }, events: {},
  getAttribute(n) { return this.attributes[n]; }, setAttribute(n, v) { this.attributes[n] = v; },
  addEventListener(n, fn) { this.events[n] = fn; }, focus() { this.focused = true; } }; }
const login = tab('tab-btn-login', 'tab-login'), first = tab('tab-btn-first', 'tab-first');
const link = { events: {}, addEventListener(n, fn) { this.events[n] = fn; } };
const view = fs.readFileSync(path.join(root, 'views/patient/app.php'), 'utf8');
const script = view.match(/<script nonce="[^\n]*">([\s\S]*?)<\/script>/)[1];
let targetExists = true;
vm.runInNewContext(script, { document: {
  querySelectorAll: selector => selector === '[role="tab"]' ? [login, first] : [link],
  getElementById: id => id === 'tab-btn-first' ? (targetExists ? first : null) : panels[id]
} });
ev = event(); link.events.click(ev);
assert.ok(ev.prevented && !panels['tab-first'].hidden && panels['tab-login'].hidden);
assert.equal(first.attributes['aria-selected'], 'true'); assert.equal(login.attributes['aria-selected'], 'false');
assert.equal(first.tabIndex, 0); assert.equal(login.tabIndex, -1); assert.ok(first.focused);
console.log('PASS atalho ativa painel, ARIA, tabindex e foco');
login.events.click(); assert.ok(!panels['tab-login'].hidden && panels['tab-first'].hidden);
console.log('PASS retorno à aba login');
ev = { key: 'End', preventDefault() {} }; login.events.keydown(ev);
assert.equal(first.attributes['aria-selected'], 'true');
ev.key = 'Home'; first.events.keydown(ev); assert.equal(login.attributes['aria-selected'], 'true');
console.log('PASS teclado Home/End preservado');
targetExists = false; ev = event(); link.events.click(ev); assert.equal(ev.prevented, false);
console.log('PASS destino ausente preserva fallback do link');
const admin = fs.readFileSync(path.join(root, 'views/admin/evento_detalhe.php'), 'utf8');
assert.match(admin.replace(/<\?[\s\S]*?\?>/g, ''), /<form[^>]*data-confirm="Confirmar abertura/);
assert.doesNotMatch(admin, /\sonsubmit\s*=/i); assert.doesNotMatch(view, /\sonclick\s*=/i);
console.log('PASS views conectam atributos declarativos sem handlers inline');
