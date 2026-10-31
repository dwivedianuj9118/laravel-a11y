// End-to-end: serves tests/fixtures/pages, runs the real scanner (Playwright + axe-core) and checks
// every deliberate keyboard error is found at its marker line, with no extra keyboard findings.
// Run from a folder with playwright + axe-core installed:  node --test tests/js/scanner.e2e.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const pages = path.join(here, '../fixtures/pages');
const scanner = path.join(here, '../../js/scanner/scan.mjs');

async function scan(files, extra = {}) {
    const server = http.createServer((req, res) => {
        try {
            const body = readFileSync(path.join(pages, path.basename(req.url)));
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end(body);
        } catch {
            res.statusCode = 404;
            res.end();
        }
    });
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    const base = `http://127.0.0.1:${server.address().port}`;
    const dir = mkdtempSync(path.join(os.tmpdir(), 'a11y-'));

    writeFileSync(path.join(dir, 'in.json'), JSON.stringify({
        urls: files.map((f) => `${base}/${f}`),
        tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'],
        appRoot: process.env.A11Y_NODE_MODULES_ROOT || process.cwd(),
        timeoutMs: 15000,
        ...extra,
    }));

    try {
        // async: a sync call would block this process's HTTP server while the scanner requests pages
        await promisify(execFile)(process.execPath, [scanner, path.join(dir, 'in.json'), path.join(dir, 'out.json')], { env: process.env });
    } finally {
        server.close();
    }

    return JSON.parse(readFileSync(path.join(dir, 'out.json'), 'utf8'));
}

test('keyboard checks find K1–K5 at their lines and nothing else', async () => {
    const out = await scan(['kb.html', 'trap.html'], { variants: [] });
    const found = out.pages.flatMap((p) => p.violations.filter((v) => v.tags.includes('cat.keyboard')).flatMap((v) => v.nodes.map((n) => `${v.id} ${n.src}`)));

    assert.deepEqual(found.sort(), [
        'focus-not-visible resources/views/kb.blade.php:9',
        'focus-obscured resources/views/kb.blade.php:12',
        'focus-trap resources/views/trap.blade.php:3',
        'keyboard-unreachable resources/views/kb.blade.php:5',
        'keyboard-unreachable resources/views/kb.blade.php:6',
    ]);
});

test('320px pass finds R1, R2 (not the scrolling table); dark pass finds D1 only in dark mode', async () => {
    const out = await scan(['reflow.html', 'dark.html'], { variants: ['mobile', 'dark'], keyboard: false });
    const found = out.pages.flatMap((p) => p.violations.filter((v) => ['reflow', 'color-contrast'].includes(v.id))
        .flatMap((v) => v.nodes.map((n) => `${p.variant} ${v.id} ${n.src}`)));

    assert.deepEqual(found.sort(), [
        'dark color-contrast resources/views/dark.blade.php:3',
        'mobile reflow resources/views/reflow.blade.php:4',
        'mobile reflow resources/views/reflow.blade.php:5',
    ]);
});

test('states pass opens the menu and <details>, finds S1–S3, and never clicks submit, links or wire:click', async () => {
    const out = await scan(['states.html'], { variants: [], keyboard: false });
    const desktop = out.pages.find((p) => p.variant === 'desktop');
    const found = out.pages.filter((p) => p.variant === 'states').flatMap((p) => p.violations
        .filter((v) => v.id !== 'target-size').flatMap((v) => v.nodes.map((n) => `${v.id} ${n.src}`)));

    assert.equal(desktop.states.triggers, 2, 'only the two disclosure controls are clicked');
    assert.equal(new URL(desktop.finalUrl).pathname, '/states.html');
    assert.deepEqual(found.sort(), [
        'image-alt resources/views/states.blade.php:6',
        'label resources/views/states.blade.php:11',
        'link-name resources/views/states.blade.php:7',
    ]);
});
