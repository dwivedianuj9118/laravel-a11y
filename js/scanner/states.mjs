// Interactive states: open menus, accordions, popups and dialogs, then run axe again, so issues
// that only exist after a click are found too.
//
// Safety: only explicit disclosure controls are clicked (aria-expanded="false", aria-haspopup,
// <summary>, data-bs-toggle, Alpine toggles like @click="open = !open" or $dispatch('open-modal')).
// Never submit buttons, links that navigate, or wire:click. And while this pass runs, every
// non-GET request is aborted in the browser, so nothing can be saved, deleted or sent.

export async function statesPass(page, prepare, tags, options = {}) {
    const max = options.maxStates ?? 5;

    await page.route('**/*', (route) => (route.request().method() === 'GET' ? route.continue() : route.abort()));

    try {
        const triggers = await findTriggers(page, max);
        const out = [];
        const startUrl = page.url();

        for (let i = 0; i < triggers.length; i++) {
            const t = triggers[i];

            if (i > 0) {
                await page.goto(startUrl, { waitUntil: 'domcontentloaded', timeout: options.timeoutMs ?? 30000 });
                await prepare(page);
            }

            const clicked = await page
                .evaluate((sel) => {
                    const el = document.querySelector(sel);
                    if (!el) return false;
                    el.scrollIntoView({ block: 'center' });
                    el.click();
                    return true;
                }, t.selector)
                .catch(() => false);
            if (!clicked) continue;

            await page.waitForTimeout(400);
            await page.waitForLoadState('networkidle', { timeout: 2000 }).catch(() => {});

            if (page.url().split('#')[0] !== startUrl.split('#')[0]) continue; // it navigated: not a disclosure

            const state = `after clicking ${t.label}${t.src ? ` (${t.src})` : ''}`;
            const violations = await page
                .evaluate(async ({ tags, state }) => {
                    const res = await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] });
                    return res.violations.map((v) => ({
                        id: v.id, impact: v.impact, help: v.help, helpUrl: v.helpUrl, tags: v.tags,
                        nodes: v.nodes.map((n) => ({
                            target: n.target, html: window.__a11y.strip(n.html), summary: n.failureSummary, state,
                            ...window.__a11y.locate(window.__a11y.fromTarget(n.target)),
                        })),
                    }));
                }, { tags, state })
                .catch(() => []);

            out.push(...violations);
        }

        return { triggers: triggers.length, violations: out };
    } finally {
        await page.unroute('**/*').catch(() => {});
    }
}

async function findTriggers(page, max) {
    return page.evaluate((max) => {
        const TOGGLE = /^\s*!?\s*\$?[\w.]+\s*=\s*(!\s*\$?[\w.]+|true)\s*;?\s*$|\$dispatch\(\s*['"]open-modal|\$refs\.\w+\.(show|open)|\.toggle\(\)/;
        const visible = (el) => {
            const r = el.getBoundingClientRect();
            const s = getComputedStyle(el);
            return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
        };
        const safe = (el) => {
            if (el.disabled || el.closest('[inert],[aria-hidden="true"]')) return false;
            if (el.matches('[type=submit],input[type=image]') || (el.tagName === 'BUTTON' && !el.getAttribute('type') && el.form)) return false;
            if (el.getAttributeNames().some((a) => a.startsWith('wire:click') || a === 'wire:submit')) return false;
            const href = el.getAttribute('href');
            if (el.tagName === 'A' && href && !href.startsWith('#') && !href.startsWith('javascript:')) return false;
            return true;
        };

        const candidates = [
            ...document.querySelectorAll('[aria-expanded="false"],[aria-haspopup]:not([aria-haspopup="false"]),details:not([open]) > summary,[data-bs-toggle],[data-toggle]'),
            ...[...document.querySelectorAll('*')].filter((el) =>
                el.getAttributeNames().some((a) => /^(@click|x-on:click)/.test(a) && TOGGLE.test(el.getAttribute(a) || ''))),
        ];

        const out = [];
        const seen = new Set();
        for (const el of candidates) {
            if (out.length >= max || !visible(el) || !safe(el)) continue;
            const selector = window.__a11y.selector(el);
            if (seen.has(selector)) continue;
            seen.add(selector);
            const text = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40);
            out.push({ selector, label: text ? `“${text}”` : `<${el.tagName.toLowerCase()}>`, src: el.closest('[data-a11y-src]')?.getAttribute('data-a11y-src') || null });
        }
        return out;
    }, max);
}
