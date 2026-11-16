/* ClyoNutri v54.58 — a mobile dialog, a stable sidebar on desktop. */
(() => {
  'use strict';
  const trigger = document.getElementById('clinical-menu-toggle');
  const panel = document.getElementById('clinical-navigation');
  const backdrop = document.querySelector('[data-nav-backdrop]');
  if (!trigger || !panel || !backdrop) return;
  const mobile = matchMedia('(max-width: 768px)');
  const background = [document.querySelector('.app-main'), document.querySelector('.skip-clinical-content'), ...document.querySelectorAll('.app-sidebar > :not(#clinical-navigation):not([data-nav-backdrop])')].filter(Boolean);
  let opened = false, previousOverflow = '', priorInert = [];
  const focusables = () => [...panel.querySelectorAll('a[href],button:not([disabled]),input:not([type=hidden]):not([disabled]),select:not([disabled]),[tabindex="0"]')].filter(el => el.checkVisibility() && !el.closest('[inert]'));
  function close(restore = true) {
    if (!opened) return;
    opened = false;
    trigger.setAttribute('aria-expanded', 'false');
    panel.classList.remove('is-open');
    panel.removeAttribute('role'); panel.removeAttribute('aria-modal'); panel.removeAttribute('aria-labelledby');
    backdrop.hidden = true;
    background.forEach((el, i) => { el.inert = priorInert[i]; });
    document.body.style.overflow = previousOverflow;
    document.body.classList.remove('cn58-menu-open');
    if (restore && mobile.matches) trigger.focus({preventScroll:true});
  }
  function open() {
    if (!mobile.matches || opened) return;
    opened = true;
    previousOverflow = document.body.style.overflow;
    priorInert = background.map(el => el.inert);
    background.forEach(el => { el.inert = true; });
    trigger.setAttribute('aria-expanded', 'true');
    panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true'); panel.setAttribute('aria-labelledby', 'cn58-menu-title');
    panel.classList.add('is-open'); backdrop.hidden = false;
    document.body.style.overflow = 'hidden'; document.body.classList.add('cn58-menu-open');
    (focusables()[0] || panel).focus({preventScroll:true});
  }
  trigger.addEventListener('click', () => opened ? close() : open());
  panel.querySelector('[data-nav-close]')?.addEventListener('click', () => close());
  backdrop.addEventListener('click', () => close());
  panel.addEventListener('click', e => { if (e.target.closest('a[href]')) close(false); });
  panel.addEventListener('submit', () => close(false));
  document.addEventListener('keydown', e => {
    if (!opened) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key !== 'Tab') return;
    const list = focusables(), first = list[0], last = list[list.length - 1];
    if (!first) { e.preventDefault(); panel.focus(); return; }
    if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  document.addEventListener('focusin', e => {
    if (opened && !panel.contains(e.target)) (focusables()[0] || panel).focus({preventScroll:true});
  });
  mobile.addEventListener('change', () => close(false));
  addEventListener('pagehide', () => close(false));

  // v54.62 (NI2-854 / UX-015): grupos recolhíveis (Biblioteca nutricional, Gestão) com estado lembrado na sessão.
  panel.querySelectorAll('details.cn58-nav-collapsible[data-nav-group]').forEach(det => {
    const key = 'cn_nav_group:' + (det.getAttribute('data-nav-group') || '');
    try {
      const saved = sessionStorage.getItem(key);
      if (saved === '1') det.open = true;
      else if (saved === '0' && !det.querySelector('.app-nav__item.active')) det.open = false;
    } catch (_) {}
    det.addEventListener('toggle', () => {
      try { sessionStorage.setItem(key, det.open ? '1' : '0'); } catch (_) {}
    });
  });
})();
