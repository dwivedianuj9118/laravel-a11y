// Injected into every scanned page as window.__a11y: maps elements to their source markers and
// builds stable selectors. Used by the axe mapping and by the keyboard / reflow checks.
export const PAGE_HELPERS = String.raw`
(() => {
  if (window.__a11y) return;
  const ATTR = 'data-a11y-src', USE = 'data-a11y-use';
  const isVendor = (s) => /^(vendor|storage)\//.test(s) || s.startsWith('/');
  const strip = (html) => html.replace(/\s(data-a11y-src|data-a11y-use)="[^"]*"/g, '');

  function locate(el) {
    if (!el || el.nodeType !== 1) return { src: null, how: 'none', uses: [], selfUse: null, ctx: {} };
    const srcEl = el.closest('[' + ATTR + ']');
    const src = srcEl ? srcEl.getAttribute(ATTR) : null;
    const how = srcEl === el ? 'marker' : srcEl ? 'ancestor' : 'none';

    const uses = [];
    for (let n = el, hops = 0; n && n.nodeType === 1 && hops < 25; n = n.parentElement, hops++) {
      const u = n.getAttribute(USE);
      if (u) uses.push(u);
      const s = n.getAttribute(ATTR);
      if (s && !isVendor(s)) break;
    }

    const ctx = {};
    for (let n = el, h = 0; n && n.nodeType === 1 && h < 8 && !ctx.model; n = n.parentElement, h++) {
      for (const a of n.getAttributeNames()) {
        if (a.startsWith('wire:model') || a.startsWith('x-model')) { ctx.model = n.getAttribute(a); break; }
      }
    }
    const lw = el.closest('[wire\\:id]');
    if (lw) {
      ctx.component = lw.getAttribute('wire:name');
      if (!ctx.component) { try { ctx.component = JSON.parse(lw.getAttribute('wire:snapshot')).memo.name; } catch (e) {} }
    }
    try {
      const holder = el.closest('[data-page]');
      const json = (holder && holder.getAttribute('data-page')) || (document.querySelector('script[data-page]') || {}).textContent;
      const page = json ? JSON.parse(json) : (window.history.state || {}).page;
      if (page && page.component) ctx.inertia = page.component;
    } catch (e) {}

    return { src, how, uses, selfUse: el.getAttribute(USE), ctx };
  }

  // Short unique CSS selector: id if unique, else tag:nth-of-type chain.
  function selector(el) {
    if (!el || el.nodeType !== 1) return '';
    if (el.id && document.querySelectorAll('#' + CSS.escape(el.id)).length === 1) return '#' + CSS.escape(el.id);
    const parts = [];
    for (let n = el; n && n.nodeType === 1 && n !== document.documentElement; n = n.parentElement) {
      if (n.id && document.querySelectorAll('#' + CSS.escape(n.id)).length === 1) { parts.unshift('#' + CSS.escape(n.id)); break; }
      let i = 1;
      for (let s = n.previousElementSibling; s; s = s.previousElementSibling) if (s.tagName === n.tagName) i++;
      parts.unshift(n.tagName.toLowerCase() + ':nth-of-type(' + i + ')');
    }
    return parts.join(' > ');
  }

  function fromTarget(target) {
    let el = null;
    try {
      let root = document;
      for (const sel of target) {
        const s = Array.isArray(sel) ? sel[0] : sel;
        el = root.querySelector(s);
        if (!el) break;
        root = el.shadowRoot || el.contentDocument || el;
      }
    } catch (e) {}
    return el;
  }

  function outer(el) {
    const html = el.outerHTML || '';
    const open = html.slice(0, html.indexOf('>') + 1);
    return strip(html.length > 300 ? open : html);
  }

  // A violation node in the same shape the axe mapping produces.
  function node(el, summary) {
    return Object.assign({ target: [selector(el)], html: outer(el), summary }, locate(el));
  }

  window.__a11y = { locate, selector, fromTarget, strip, node, outer };
})();
`;
