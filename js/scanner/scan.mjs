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
import { PAGE_HELPERS } from './page-helpers.mjs';
import { keyboardChecks } from './keyboard.mjs';
import { reflowCheck } from './reflow.mjs';
import { statesPass } from './states.mjs';

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

// Wait until the page is usable: full load (capped), quiet network (capped), Livewire booted.
async function settle(page, variant) {
    await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});
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

    // Apps that switch theme with a class (Tailwind darkMode: 'class') instead of the media query.
    if (variant === 'dark' && input.darkClass) {
        await page.evaluate((c) => document.documentElement.classList.add(c), input.darkClass).catch(() => {});
        await page.waitForTimeout(150);
    }
}

async function inject(page) {
    await page.addScriptTag({ content: axeSource });
    await page.addScriptTag({ content: PAGE_HELPERS });
}

// variant: 'desktop' (default, 1280px), 'mobile' (320px, + reflow check) or 'dark' (prefers-color-scheme: dark)
async function scanPage(context, url, session, variant = 'desktop') {
    const page = await context.newPage();
    const result = { session, variant, url, finalUrl: url, status: null, error: null, violations: [] };

    try {
        // Don't block on slow third-party images/fonts: start once the HTML is parsed,
        // then give the full load a capped wait.
        const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: input.timeoutMs ?? 30000 });
        result.status = response ? response.status() : null;
        await settle(page, variant);
        result.finalUrl = page.url();

        // How many source markers the page carries: zero everywhere means we are scanning the wrong
        // app, a production build, or a page served from cache.
        result.markers = await page.evaluate(() => document.querySelectorAll('[data-a11y-src],[data-a11y-use]').length).catch(() => 0);

        // PHP notices/warnings echoed into the HTML (display_errors=On), e.g. "<b>Deprecated</b>: ..."
        result.phpOutput = await page
            .evaluate(() => {
                const re = /<b>(Deprecated|Warning|Notice|Fatal error|Parse error|Strict Standards|Recoverable fatal error)<\/b>:\s*([^<]{0,140})/g;
                const html = document.documentElement.outerHTML;
                let m, count = 0, first = null;
                while ((m = re.exec(html))) {
                    count++;
                    if (!first) first = `${m[1]}: ${m[2].trim()}`;
                }
                return count ? { count, first } : null;
            })
            .catch(() => null);

        await inject(page);
        result.violations = await page.evaluate(
            async ({ tags }) => {
                const res = await window.axe.run(document, {
                    runOnly: { type: 'tag', values: tags },
                    resultTypes: ['violations'],
                });

                return res.violations.map((v) => ({
                    id: v.id,
                    impact: v.impact,
                    help: v.help,
                    helpUrl: v.helpUrl,
                    tags: v.tags,
                    nodes: v.nodes.map((n) => ({
                        target: n.target,
                        html: window.__a11y.strip(n.html),
                        summary: n.failureSummary,
                        ...window.__a11y.locate(window.__a11y.fromTarget(n.target)),
                    })),
                }));
            },
            { tags: input.tags },
        );

        if (variant === 'mobile') {
            result.violations.push(...(await reflowCheck(page, input.tags).catch(() => [])));
        }

        if (input.keyboard !== false && variant === 'desktop') {
            const kb = await keyboardChecks(page, input.tags, { maxTabs: input.maxTabs }).catch((e) => {
                result.keyboardError = String(e.message || e).split('\n')[0];
                return [];
            });
            result.violations.push(...kb);
        }

        if (input.states !== false && variant === 'desktop') {
            const st = await statesPass(page, async (p) => { await settle(p, variant); await inject(p); }, input.tags, {
                maxStates: input.maxStates, timeoutMs: input.timeoutMs,
            }).catch((e) => ({ triggers: 0, violations: [], error: String(e.message || e).split('\n')[0] }));
            result.states = { triggers: st.triggers, error: st.error || null };
            result.stateViolations = st.violations;
        }
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

    await runQueue(context, session, 'desktop');

    // Extra passes reuse the logged-in cookies of the main context.
    const VARIANTS = {
        mobile: { viewport: { width: 320, height: 640 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
        dark: { viewport: { width: 1280, height: 900 }, colorScheme: 'dark' },
    };
    for (const variant of input.variants || []) {
        if (!VARIANTS[variant]) continue;
        const ctx = await browser.newContext({
            extraHTTPHeaders: input.headers || {},
            storageState: await context.storageState(),
            ...VARIANTS[variant],
        });
        await runQueue(ctx, session, variant);
        await ctx.close();
    }
    await context.close();
}

await browser.close();

async function runQueue(context, session, variant) {
    const queue = [...session.urls];
    const workers = Array.from({ length: Math.max(1, Math.min(input.concurrency ?? 3, queue.length)) }, async () => {
        while (queue.length) {
            const url = queue.shift();
            const r = await scanPage(context, url, session.name, variant);
            const { stateViolations, ...page } = r;
            pages.push(page);
            if (stateViolations?.length) {
                // Issues seen only after opening menus/dialogs, reported as their own pass.
                pages.push({ ...page, variant: 'states', violations: stateViolations, phpOutput: null });
            }
            process.stderr.write(`${r.error ? 'ERR ' : 'ok  '}${url}${variant !== 'desktop' ? ` (${variant})` : ''}\n`);
        }
    });
    await Promise.all(workers);
}

const order = sessions.flatMap((s) => ['desktop', 'states', ...(input.variants || [])].flatMap((v) => s.urls.map((u) => `${s.name} ${v} ${u}`)));
pages.sort((a, b) => order.indexOf(`${a.session} ${a.variant} ${a.url}`) - order.indexOf(`${b.session} ${b.variant} ${b.url}`));
writeFileSync(
    outputPath,
    JSON.stringify({ pages, sessions: sessionInfo, meta: { axe: JSON.parse(readFileSync(resolve('axe-core/package.json'), 'utf8')).version, ms: Date.now() - started } }, null, 2),
);
