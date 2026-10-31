// Keyboard & focus checks that axe-core cannot do from a static DOM. Runs real Tab presses.
//
//   keyboard-unreachable  2.1.1 (A)   clickable element (wire:click, @click, onclick, role=button…) that Tab never reaches
//   focus-trap            2.1.2 (A)   Tab stops moving, or loops inside a small group, before the rest of the page is reached
//   focus-not-visible     2.4.7 (AA)  nothing visibly changes when an element receives keyboard focus
//   focus-obscured        2.4.11 (AA, WCAG 2.2)  focused element entirely hidden behind something else (sticky header, cookie bar)
//
// Each check returns violations in the same shape as the axe mapping, so grouping and file:line
// resolution work unchanged.

const RULES = {
    'keyboard-unreachable': {
        impact: 'serious',
        tags: ['wcag2a', 'wcag211', 'cat.keyboard'],
        help: 'Clickable element cannot be reached or used with the keyboard',
        helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/keyboard.html',
    },
    'focus-trap': {
        impact: 'critical',
        tags: ['wcag2a', 'wcag212', 'cat.keyboard'],
        help: 'Keyboard focus gets stuck and cannot move on with Tab',
        helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/no-keyboard-trap.html',
    },
    'focus-not-visible': {
        impact: 'serious',
        tags: ['wcag2aa', 'wcag247', 'cat.keyboard'],
        help: 'No visible focus indicator when this element is focused with the keyboard',
        helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/focus-visible.html',
    },
    'focus-obscured': {
        impact: 'serious',
        tags: ['wcag22aa', 'wcag2411', 'cat.keyboard'],
        help: 'Focused element is completely hidden behind other content',
        helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html',
    },
};

export async function keyboardChecks(page, tags, options = {}) {
    const enabled = Object.keys(RULES).filter((id) => tags.includes(RULES[id].tags[0]));
    if (!enabled.length) return [];

    const found = Object.fromEntries(enabled.map((id) => [id, []]));

    // ---- 1. Static pass: things that look clickable but are not focusable -------------------
    if (found['keyboard-unreachable']) {
        found['keyboard-unreachable'] = await page.evaluate(() => {
            const NATIVE = 'a[href],area[href],button,input,select,textarea,summary,iframe,[contenteditable=""],[contenteditable="true"],audio[controls],video[controls],label';
            // Click handlers that only close/dismiss (backdrops, overlays) are mouse shortcuts, not 2.1.1 failures.
            const DISMISS = /close|hide|dismiss|cancel|\b\w*open\w*\s*=\s*false|isOpen\s*=\s*false|\$store\.\w+\.close/i;
            const handler = (el) => {
                const names = el.getAttributeNames().filter((a) => a === 'onclick' || /^(wire:click|@click|x-on:click|v-on:click|hx-(get|post))/.test(a));
                if (names.length) return !names.every((a) => DISMISS.test(el.getAttribute(a) || ''));
                return ['button', 'link', 'tab', 'menuitem', 'checkbox', 'switch', 'option'].includes(el.getAttribute('role'));
            };
            const visible = (el) => {
                const r = el.getBoundingClientRect();
                const s = getComputedStyle(el);
                return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
            };
            const out = [];
            for (const el of document.body.querySelectorAll('*')) {
                if (!handler(el) || el.matches(NATIVE) || el.hasAttribute('tabindex') && el.tabIndex >= 0) continue;
                if (el.closest('[inert],[aria-hidden="true"]') || !visible(el)) continue;
                if (el.querySelector(NATIVE + ',[tabindex]:not([tabindex="-1"])')) continue; // a real control inside handles it
                out.push(window.__a11y.node(el, 'Make it a <button type="button"> (or <a href>), or add tabindex="0", a role and Enter/Space key handling.'));
                if (out.length >= 50) break;
            }
            return out;
        });
    }

    // ---- 2. Tab walk ------------------------------------------------------------------------
    const expected = await page.evaluate(() => {
        document.activeElement && document.activeElement.blur && document.activeElement.blur();
        window.scrollTo(0, 0);
        const sel = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[tabindex],[contenteditable="true"],iframe';
        return [...document.querySelectorAll(sel)].filter((el) => {
            if (el.disabled || el.tabIndex < 0 || el.closest('[inert]')) return false;
            const r = el.getBoundingClientRect();
            const s = getComputedStyle(el);
            return r.width > 0 && r.height > 0 && s.visibility !== 'hidden';
        }).length;
    });

    if (expected === 0) return pack(found);

    const maxSteps = Math.min(options.maxTabs ?? 150, expected + 10);
    const seen = new Map(); // selector -> { order, focusedStyle }
    const sequence = [];
    let stuck = 0;
    let last = null;

    // Focused-state snapshot: style signature + whether it is visibly obscured.
    const snap = () =>
        page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body || el === document.documentElement) return null;
            const key = window.__a11y.selector(el);
            const sig = (e, pseudo) => {
                if (!e) return '';
                const s = getComputedStyle(e, pseudo);
                return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.outlineOffset, s.boxShadow, s.borderTopColor, s.borderBottomColor,
                    s.borderLeftColor, s.borderRightColor, s.borderTopWidth, s.backgroundColor, s.color, s.textDecorationLine, s.textDecorationColor,
                    s.opacity, s.transform, pseudo ? s.content : ''].join('|');
            };
            const r = el.getBoundingClientRect();
            // Page coordinates (not viewport), so scrolling between the two snapshots does not count as a change.
            const signature = [sig(el), sig(el, '::before'), sig(el, '::after'), sig(el.parentElement),
                Math.round(r.left + scrollX), Math.round(r.top + scrollY), Math.round(r.width), Math.round(r.height)].join('#');

            // Obscured: every sample point inside the element is covered by an unrelated element.
            let obscuredBy = null;
            const vw = innerWidth, vh = innerHeight;
            const pts = [[0.5, 0.5], [0.15, 0.2], [0.85, 0.2], [0.15, 0.8], [0.85, 0.8]]
                .map(([fx, fy]) => [r.left + r.width * fx, r.top + r.height * fy])
                .filter(([x, y]) => x >= 0 && y >= 0 && x < vw && y < vh);
            if (pts.length && r.width > 2 && r.height > 2) {
                let covered = 0, by = null;
                for (const [x, y] of pts) {
                    const top = document.elementFromPoint(x, y);
                    if (top && top !== el && !el.contains(top) && !top.contains(el)) { covered++; by = by || top; }
                }
                if (covered === pts.length) obscuredBy = window.__a11y.outer(by).slice(0, 120);
            }
            const inViewport = r.bottom > 0 && r.right > 0 && r.top < vh && r.left < vw;

            return { key, signature, obscuredBy, inViewport, node: window.__a11y.node(el, '') };
        });

    for (let step = 0; step < maxSteps; step++) {
        await page.keyboard.press('Tab');
        const s = await snap().catch(() => null);
        if (!s) {
            if (sequence.length) break; // focus left the document: walked off the end, normal
            continue;
        }

        if (last && s.key === last) {
            if (++stuck >= 2 && found['focus-trap'] && sequence.length < expected * 0.9) {
                s.node.summary = 'Tab keeps focus on this element. Let Tab move on (check keydown handlers that call preventDefault on Tab, or focus() loops).';
                found['focus-trap'].push(s.node);
                break;
            }
            continue;
        }
        stuck = 0;
        last = s.key;

        if (seen.has(s.key)) {
            // Looped back. Fine if (almost) everything was visited; a trap if we are cycling in a small group.
            const loop = sequence.slice(seen.get(s.key).order);
            if (found['focus-trap'] && seen.size < expected * 0.6 && loop.length < expected * 0.5) {
                s.node.summary = `Tab cycles between ${loop.length} element(s) and never reaches the other ${expected - seen.size}. If this is a modal, it must close with Esc; otherwise remove the focus loop.`;
                found['focus-trap'].push(s.node);
            }
            break;
        }

        seen.set(s.key, { order: sequence.length, focused: s.signature, node: s.node, inViewport: s.inViewport });
        sequence.push(s.key);

        if (s.obscuredBy && found['focus-obscured']) {
            s.node.summary = `Covered by: ${s.obscuredBy}. Add scroll-padding-top/scroll-margin for sticky headers, or move the overlay.`;
            found['focus-obscured'].push(s.node);
        }
    }

    // ---- 3. Focus visible: compare each focused signature with the same element unfocused ----
    if (found['focus-not-visible'] && seen.size) {
        const keys = [...seen.keys()];
        const unfocused = await page.evaluate((keys) => {
            document.activeElement && document.activeElement.blur && document.activeElement.blur();
            const sig = (e, pseudo) => {
                if (!e) return '';
                const s = getComputedStyle(e, pseudo);
                return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.outlineOffset, s.boxShadow, s.borderTopColor, s.borderBottomColor,
                    s.borderLeftColor, s.borderRightColor, s.borderTopWidth, s.backgroundColor, s.color, s.textDecorationLine, s.textDecorationColor,
                    s.opacity, s.transform, pseudo ? s.content : ''].join('|');
            };
            return keys.map((k) => {
                const el = document.querySelector(k);
                if (!el) return null;
                const r = el.getBoundingClientRect();
                return [sig(el), sig(el, '::before'), sig(el, '::after'), sig(el.parentElement),
                    Math.round(r.left + scrollX), Math.round(r.top + scrollY), Math.round(r.width), Math.round(r.height)].join('#');
            });
        }, keys);

        keys.forEach((k, i) => {
            const entry = seen.get(k);
            if (unfocused[i] !== null && unfocused[i] === entry.focused && entry.inViewport) {
                entry.node.summary = 'Add a :focus-visible style (e.g. outline: 2px solid; outline-offset: 2px, or Tailwind focus-visible:ring-2). Do not remove outlines without a replacement.';
                found['focus-not-visible'].push(entry.node);
            }
        });
    }

    return pack(found);
}

function pack(found) {
    return Object.entries(found)
        .filter(([, nodes]) => nodes.length)
        .map(([id, nodes]) => ({ id, ...RULES[id], nodes }));
}
