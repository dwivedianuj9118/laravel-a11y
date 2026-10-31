#!/usr/bin/env node
// laravel-a11y scanner: loads each URL in headless Chromium, runs axe-core, and maps every
// failing element to the nearest data-a11y-src="file:line" marker.
//
// Usage: node scan.mjs <input.json> <output.json>
// input:  { sessions: [{ name, loginUrl|null, urls: [] }], tags: [], appRoot, waitMs, timeoutMs, concurrency, headers }
//         (a plain { urls: [] } is treated as one guest session)
// output: { pages: [{ session, url, finalUrl, status, error, violations: [...] }], sessions: [...], meta: {...} }

import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const [, , inputPath, outputPath] = process.argv;
if (!inputPath || !outputPath) {
    console.error('Usage: node scan.mjs <input.json> <output.json>');
    process.exit(2);
}

const input = JSON.parse(readFileSync(inputPath, 'utf8'));
const appRoot = input.appRoot || process.cwd();

// Resolve playwright + axe-core from the Laravel app's node_modules, then from global installs.
function load(name) {
    const tries = [path.join(appRoot, 'package.json'), import.meta.url];
    for (const base of tries) {
        try {
            return createRequire(base)(name);
        } catch {}
    }
    throw new Error(`Cannot find "${name}". Run: php artisan a11y:install`);
}
function resolve(name) {
    for (const base of [path.join(appRoot, 'package.json'), import.meta.url]) {
        try {
            return createRequire(base).resolve(name);
        } catch {}
    }
    throw new Error(`Cannot find "${name}". Run: php artisan a11y:install`);
}

const { chromium } = load('playwright');
const axeSource = readFileSync(resolve('axe-core/axe.min.js'), 'utf8');
const ATTR = 'data-a11y-src';
const USE = 'data-a11y-use';

async function scanPage(context, url, session) {
    const page = await context.newPage();
    const result = { session, url, finalUrl: url, status: null, error: null, violations: [] };

    try {
        // Don't block on slow third-party images/fonts: start once the HTML is parsed,
        // then give the full load a capped wait.
        const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: input.timeoutMs ?? 30000 });
        result.status = response ? response.status() : null;
        await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});

        // Best effort: let Livewire / Inertia / lazy content settle, but never hang on a busy network.
        await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
        await page
            .evaluate(
                () =>
                    new Promise((done) => {
                        const ready = () => requestAnimationFrame(() => setTimeout(done, 50));
                        if (window.Livewire && !window.Livewire.all?.().length && document.querySelector('[wire\\:id],[wire\\:snapshot]')) {
                            document.addEventListener('livewire:initialized', ready, { once: true });
                            setTimeout(done, 3000);
                        } else ready();
                    }),
            )
            .catch(() => {});
        if (input.waitMs) await page.waitForTimeout(input.waitMs);

        result.finalUrl = page.url();

        await page.addScriptTag({ content: axeSource });
        result.violations = await page.evaluate(
            async ({ tags, ATTR, USE }) => {
                const res = await window.axe.run(document, {
                    runOnly: { type: 'tag', values: tags },
                    resultTypes: ['violations'],
                });

                const locate = (target) => {
                    let el = null;
                    try {
                        // axe targets are arrays; nested arrays mean iframe/shadow DOM hops.
                        let root = document;
                        for (const sel of target) {
                            const s = Array.isArray(sel) ? sel[0] : sel;
                            el = root.querySelector(s);
                            if (!el) break;
                            root = el.shadowRoot || el.contentDocument || el;
                        }
                    } catch {}
                    if (!el) return { src: null, how: 'none', uses: [], ctx: {} };

                    const srcEl = el.closest(`[${ATTR}]`);
                    const src = srcEl ? srcEl.getAttribute(ATTR) : null;
                    const how = srcEl === el ? 'marker' : srcEl ? 'ancestor' : 'none';
                    const isVendor = (s) => /^(vendor|storage)\//.test(s) || s.startsWith('/');

                    // Component call sites between the element and the first marker in app code.
                    const uses = [];
                    for (let n = el, hops = 0; n && n.nodeType === 1 && hops < 25; n = n.parentElement, hops++) {
                        const u = n.getAttribute(USE);
                        if (u) uses.push(u);
                        const s = n.getAttribute(ATTR);
                        if (s && !isVendor(s)) break;
                    }

                    // Livewire / Filament: the bound state path and the Livewire component name.
                    const ctx = {};
                    for (let n = el, h = 0; n && n.nodeType === 1 && h < 8 && !ctx.model; n = n.parentElement, h++) {
                        for (const a of n.getAttributeNames()) {
                            if (a.startsWith('wire:model') || a.startsWith('x-model')) {
                                ctx.model = n.getAttribute(a);
                                break;
                            }
                        }
                    }
                    const lw = el.closest('[wire\\:id]');
                    if (lw) {
                        ctx.component = lw.getAttribute('wire:name');
                        if (!ctx.component) {
                            try {
                                ctx.component = JSON.parse(lw.getAttribute('wire:snapshot')).memo.name;
                            } catch {}
                        }
                    }

                    // Inertia: which page component rendered this.
                    try {
                        const holder = el.closest('[data-page]');
                        const json = holder?.getAttribute('data-page') || document.querySelector('script[data-page]')?.textContent;
                        ctx.inertia = (json ? JSON.parse(json) : window.history.state?.page)?.component || undefined;
                    } catch {}

                    // A call-site marker on the failing element itself (vendor component that forwards
                    // $attributes and renders its HTML in PHP, e.g. Filament 5's icon button).
                    const selfUse = el.getAttribute(USE);

                    return { src, how, uses, selfUse, ctx };
                };

                const strip = (html) => html.replace(new RegExp(`\\s(${ATTR}|${USE})="[^"]*"`, 'g'), '');

                return res.violations.map((v) => ({
                    id: v.id,
                    impact: v.impact,
                    help: v.help,
                    helpUrl: v.helpUrl,
                    tags: v.tags,
                    nodes: v.nodes.map((n) => ({
                        target: n.target,
                        html: strip(n.html),
                        summary: n.failureSummary,
                        ...locate(n.target),
                    })),
                }));
            },
            { tags: input.tags, ATTR, USE },
        );
    } catch (e) {
        result.error = String(e && e.message ? e.message : e).split('\n')[0];
    } finally {
        await page.close().catch(() => {});
    }

    return result;
}

const browser = await chromium.launch();
const sessions = input.sessions?.length ? input.sessions : [{ name: 'guest', loginUrl: null, urls: input.urls || [] }];
const pages = [];
const sessionInfo = [];
const started = Date.now();

for (const session of sessions) {
    const context = await browser.newContext({
        extraHTTPHeaders: input.headers || {},
        viewport: { width: 1280, height: 900 },
    });

    let loginError = null;
    if (session.loginUrl) {
        const page = await context.newPage();
        try {
            const res = await page.goto(session.loginUrl, { waitUntil: 'domcontentloaded', timeout: input.timeoutMs ?? 30000 });
            if (!res || res.status() !== 200) {
                loginError = `login failed (HTTP ${res ? res.status() : 'no response'}): ${(await page.textContent('body').catch(() => '') || '').trim().slice(0, 160)}`;
            }
        } catch (e) {
            loginError = `login failed: ${String(e.message || e).split('\n')[0]}`;
        }
        await page.close().catch(() => {});
    }
    sessionInfo.push({ name: session.name, loginError });
    process.stderr.write(`-- ${session.name}${loginError ? ' (' + loginError + ')' : ''}\n`);

    if (loginError) {
        for (const url of session.urls) pages.push({ session: session.name, url, finalUrl: url, status: null, error: loginError, violations: [] });
        await context.close();
        continue;
    }

    const queue = [...session.urls];
    const workers = Array.from({ length: Math.max(1, Math.min(input.concurrency ?? 3, queue.length)) }, async () => {
        while (queue.length) {
            const url = queue.shift();
            const r = await scanPage(context, url, session.name);
            pages.push(r);
            process.stderr.write(`${r.error ? 'ERR ' : 'ok  '}${url}\n`);
        }
    });
    await Promise.all(workers);
    await context.close();
}

await browser.close();

const order = sessions.flatMap((s) => s.urls.map((u) => s.name + ' ' + u));
pages.sort((a, b) => order.indexOf(a.session + ' ' + a.url) - order.indexOf(b.session + ' ' + b.url));
writeFileSync(
    outputPath,
    JSON.stringify({ pages, sessions: sessionInfo, meta: { axe: JSON.parse(readFileSync(resolve('axe-core/package.json'), 'utf8')).version, ms: Date.now() - started } }, null, 2),
);
