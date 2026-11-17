/* Solicitação de exames (NI2-529; v47): filtro do catálogo, favoritos e ordem dos exames, sem script inline
 * (CSP script-src 'self'). O catálogo inteiro já vem do servidor; a busca só mostra/esconde cartões e o que está
 * marcado nunca some. O sinônimo pelo qual o exame foi achado vai em matched_synonym[id]; o servidor confere.
 * A ordem vai em exam_order (ids separados por vírgula); o servidor só aceita ids que estão marcados. */
(function () {
  'use strict';
  var input = document.querySelector('[data-exam-search]');
  var cat = document.querySelector('[data-exam-category]');
  var box = document.getElementById('exam-catalog');
  if (!input || !box) return;
  var items = Array.prototype.slice.call(box.querySelectorAll('[data-exam-item]'));
  var none = document.getElementById('exam-none');
  var count = document.getElementById('exam-count');
  var orderInput = document.getElementById('exam-order');
  var orderList = document.getElementById('exam-order-list');
  var orderEmpty = document.getElementById('exam-order-empty');
  var lastSyn = {};
  var timer = null, seq = 0;

  function updateCount() {
    var n = box.querySelectorAll('input[type=checkbox]:checked').length;
    if (count) count.textContent = n === 0 ? 'Nenhum exame selecionado' : n + (n === 1 ? ' exame selecionado' : ' exames selecionados');
  }
  function setSyn(item, syn) {
    var h = item.querySelector('[data-exam-synonym]'), l = item.querySelector('[data-exam-synonym-label]');
    if (h) h.value = syn || '';
    if (l) { l.textContent = ''; if (syn) { l.appendChild(document.createElement('br')); l.appendChild(document.createTextNode('achado por: ' + syn)); } }
  }
  function favOnly() { return cat && cat.value === '__fav'; }
  function apply(ids) {
    var visible = 0, c = cat ? cat.value : '';
    items.forEach(function (it) {
      var id = it.getAttribute('data-exam-item');
      var checked = it.querySelector('input[type=checkbox]').checked;
      var inCat = !c || (c === '__fav' ? it.getAttribute('data-favorite') === '1' : it.getAttribute('data-category') === c);
      var match = (ids === null || ids.hasOwnProperty(id)) && inCat;
      it.hidden = !(match || checked);
      if (match) visible++;
    });
    if (none) none.classList.toggle('is-visible', visible === 0);
  }
  function run() {
    var q = input.value.trim();
    if (q === '') { lastSyn = {}; apply(null); return; }
    var my = ++seq;
    var c = cat && cat.value && !favOnly() ? cat.value : '';
    var url = input.getAttribute('data-exam-search') + '?q=' + encodeURIComponent(q) + (c ? '&category=' + encodeURIComponent(c) : '');
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (data) {
        if (my !== seq) return;
        var ids = {}; lastSyn = {};
        (data.results || []).forEach(function (x) { ids[String(x.id)] = true; if (x.matched_synonym) lastSyn[String(x.id)] = x.matched_synonym; });
        apply(ids);
      })
      .catch(function () { if (my === seq) { apply(null); if (none) { none.textContent = 'A busca não respondeu agora. Todos os exames estão listados abaixo.'; none.classList.add('is-visible'); } } });
  }

  // ---- ordem dos exames
  function orderIds() {
    return orderList ? Array.prototype.map.call(orderList.querySelectorAll('[data-order-id]'), function (li) { return li.getAttribute('data-order-id'); }) : [];
  }
  function syncOrder() {
    if (!orderList || !orderInput) return;
    var lis = orderList.querySelectorAll('[data-order-id]');
    Array.prototype.forEach.call(lis, function (li, i) {
      var up = li.querySelector('[data-order-move=up]'), down = li.querySelector('[data-order-move=down]');
      if (up) up.disabled = i === 0;
      if (down) down.disabled = i === lis.length - 1;
    });
    orderInput.value = orderIds().join(',');
    if (orderEmpty) orderEmpty.hidden = lis.length > 0;
  }
  function mkBtn(dir, label, name) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-outline';
    b.setAttribute('data-order-move', dir); b.setAttribute('aria-label', label + ' ' + name);
    b.textContent = label;
    return b;
  }
  function addToOrder(item) {
    if (!orderList) return;
    var id = item.getAttribute('data-exam-item');
    if (orderList.querySelector('[data-order-id="' + id + '"]')) return;
    var name = item.getAttribute('data-name') || '';
    var li = document.createElement('li');
    li.setAttribute('data-order-id', id);
    var s = document.createElement('span'); s.className = 'ex-order-name'; s.textContent = name;
    var btns = document.createElement('span'); btns.className = 'ex-order-btns';
    btns.appendChild(mkBtn('up', 'Subir', name)); btns.appendChild(mkBtn('down', 'Descer', name));
    li.appendChild(s); li.appendChild(btns);
    orderList.appendChild(li);
  }
  function removeFromOrder(id) {
    var li = orderList && orderList.querySelector('[data-order-id="' + id + '"]');
    if (li) li.parentNode.removeChild(li);
  }
  if (orderList) {
    orderList.addEventListener('click', function (e) {
      var b = e.target.closest('[data-order-move]'); if (!b || b.disabled) return;
      var li = b.closest('[data-order-id]');
      if (b.getAttribute('data-order-move') === 'up' && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling);
      else if (b.getAttribute('data-order-move') === 'down' && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li);
      syncOrder();
      var again = li.querySelector('[data-order-move="' + b.getAttribute('data-order-move') + '"]');
      (again && !again.disabled ? again : li.querySelector('button:not([disabled])') || b).focus();
    });
  }

  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 220); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(); } });
  if (cat) cat.addEventListener('change', function () { if (input.value.trim() === '') apply(null); else run(); });
  box.addEventListener('change', function (e) {
    var cb = e.target; if (!cb || cb.type !== 'checkbox') return;
    var item = cb.closest('[data-exam-item]'); if (!item) return;
    item.classList.toggle('is-checked', cb.checked);
    setSyn(item, cb.checked ? (lastSyn[item.getAttribute('data-exam-item')] || '') : '');
    if (cb.checked) addToOrder(item); else removeFromOrder(item.getAttribute('data-exam-item'));
    syncOrder();
    updateCount();
  });
  syncOrder();
})();
