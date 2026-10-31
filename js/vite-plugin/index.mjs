// laravel-a11y Vite plugin: stamps data-a11y-src="file:line" on every HTML element in Vue SFC
// templates and React JSX/TSX, and data-a11y-use="file:line" on component tags, so
// `php artisan a11y:scan` can point Inertia issues at the exact line.
//
//   // vite.config.js
//   import a11y from './vendor/dwivedianuj/laravel-a11y/js/vite-plugin/index.mjs';
//   plugins: [a11y(), laravel({...}), vue()]
//
// On by default for `vite` (dev server). For `vite build` it is off unless A11Y_BUILD_MARKERS=true,
// so production bundles never carry markers by accident.

import { createRequire } from 'node:module';
import path from 'node:path';

const SRC = 'data-a11y-src';
const USE = 'data-a11y-use';
const SKIP_ELEMENTS = new Set(['html', 'head', 'meta', 'link', 'title', 'base', 'script', 'style', 'noscript', 'template', 'slot']);

export default function laravelA11y(options = {}) {
    const include = options.include ?? /\.(vue|jsx|tsx)$/;
    // Vue warns about "extraneous non-props attributes" on multi-root components, so component
    // call-site markers are opt-in there; React silently ignores unknown props.
    const markVueComponents = options.vueComponents ?? false;
    const markReactComponents = options.reactComponents ?? true;
    let root = process.cwd();
    let enabled = false;
    let requireFromApp = createRequire(path.join(root, 'package.json'));

    return {
        name: 'laravel-a11y',
        enforce: 'pre',

        configResolved(config) {
            root = config.root;
            requireFromApp = createRequire(path.join(root, 'package.json'));

            const env = { ...process.env, ...(config.env || {}) };
            const appEnv = env.APP_ENV || readDotEnv(root, 'APP_ENV') || 'local';
            const flag = (name) => String(env[name] ?? readDotEnv(root, name) ?? '').toLowerCase();

            if (options.enabled !== undefined) {
                enabled = !!options.enabled;
            } else if (flag('A11Y_MARKERS') === 'false') {
                enabled = false;
            } else if (config.command === 'serve') {
                enabled = ['local', 'testing'].includes(appEnv);
            } else {
                enabled = flag('A11Y_BUILD_MARKERS') === 'true';
            }

            if (enabled && config.command === 'build') {
                config.logger.warn('[laravel-a11y] A11Y_BUILD_MARKERS=true: this build contains data-a11y-src markers. Do not deploy it.');
            }
        },

        transform(code, id) {
            if (!enabled) return null;

            const file = id.split('?')[0];
            if (file.includes('/node_modules/') || !include.test(file) || id.includes('?vue&type=')) return null;

            const rel = path.relative(root, file).split(path.sep).join('/');

            try {
                const out = file.endsWith('.vue')
                    ? stampVue(code, rel, markVueComponents, requireFromApp)
                    : stampJsx(code, rel, file.endsWith('.tsx'), markReactComponents, requireFromApp);

                return out === code ? null : { code: out, map: null };
            } catch (e) {
                this.warn(`[laravel-a11y] could not add markers to ${rel}: ${e.message}`);

                return null;
            }
        },
    };
}

// ---------------------------------------------------------------- Vue

export function stampVue(code, rel, markComponents = true, req = createRequire(import.meta.url)) {
    const { parse } = loadFrom(req, ['vue/compiler-sfc', '@vue/compiler-sfc']);
    const { descriptor, errors } = parse(code, { filename: rel });
    const template = descriptor.template;

    if (errors?.length || !template?.ast || (template.lang && template.lang !== 'html')) return code;

    const inserts = [];
    const visit = (node) => {
        if (node.type === 1) {
            const attrs = (node.props || []).map((p) => p.name);
            const lower = node.tag.toLowerCase();

            if (node.tagType === 0 && !SKIP_ELEMENTS.has(lower) && !attrs.includes(SRC)) {
                inserts.push([node.loc.start.offset + 1 + node.tag.length, ` ${SRC}="${rel}:${node.loc.start.line}"`]);
            } else if (node.tagType === 1 && markComponents && !attrs.includes(USE) && !isVueBuiltin(node.tag)) {
                inserts.push([node.loc.start.offset + 1 + node.tag.length, ` ${USE}="${rel}:${node.loc.start.line}"`]);
            }
        }
        (node.children || []).forEach(visit);
        if (node.branches) node.branches.forEach(visit);
    };
    template.ast.children.forEach(visit);

    return applyInserts(code, inserts);
}

function isVueBuiltin(tag) {
    return /^(component|transition|transition-group|keep-alive|teleport|suspense|Transition|TransitionGroup|KeepAlive|Teleport|Suspense|Head)$/.test(tag);
}

// ---------------------------------------------------------------- React (JSX / TSX)

export function stampJsx(code, rel, typescript, markComponents = true, req = createRequire(import.meta.url)) {
    if (!code.includes('<')) return code;

    const { parse } = loadFrom(req, ['@babel/parser']);
    const ast = parse(code, {
        sourceType: 'module',
        errorRecovery: true,
        plugins: typescript ? ['jsx', 'typescript'] : ['jsx'],
    });

    const inserts = [];
    const visit = (node) => {
        if (!node || typeof node.type !== 'string') return;

        if (node.type === 'JSXOpeningElement') {
            const name = node.name;
            const attrs = node.attributes.filter((a) => a.type === 'JSXAttribute').map((a) => a.name?.name);
            const line = node.loc.start.line;

            const isHost = (name.type === 'JSXIdentifier' && /^[a-z]/.test(name.name)) || name.type === 'JSXNamespacedName';
            if (isHost) {
                if (!SKIP_ELEMENTS.has(name.name ?? '') && !attrs.includes(SRC)) {
                    inserts.push([name.end, ` ${SRC}="${rel}:${line}"`]);
                }
            } else if (markComponents && !attrs.includes(USE) && !isReactBuiltin(name)) {
                // generics like <Select<T> ...> put the type args after the name
                const at = node.typeArguments?.end ?? node.typeParameters?.end ?? name.end;
                inserts.push([at, ` ${USE}="${rel}:${line}"`]);
            }
        }

        for (const key in node) {
            if (key === 'loc' || key === 'start' || key === 'end' || key === 'extra' || key === 'comments' || key.endsWith('Comments')) continue;
            const v = node[key];
            if (Array.isArray(v)) v.forEach(visit);
            else if (v && typeof v === 'object' && typeof v.type === 'string') visit(v);
        }
    };
    visit(ast.program);

    return applyInserts(code, inserts);
}

function isReactBuiltin(name) {
    const text = name.type === 'JSXMemberExpression' ? `${name.object.name}.${name.property.name}` : name.name;

    return /^(Fragment|React\.Fragment|Suspense|React\.Suspense|StrictMode|React\.StrictMode|Head)$/.test(text || '');
}

// ---------------------------------------------------------------- helpers

function applyInserts(code, inserts) {
    if (!inserts.length) return code;
    inserts.sort((a, b) => b[0] - a[0]);
    let out = code;
    for (const [at, text] of inserts) out = out.slice(0, at) + text + out.slice(at);

    return out;
}

function loadFrom(req, names) {
    for (const base of [req, createRequire(import.meta.url)]) {
        for (const n of names) {
            try {
                return base(n);
            } catch {}
        }
    }
    throw new Error(`cannot find ${names.join(' or ')} (npm install -D ${names.at(-1)})`);
}

function readDotEnv(root, key) {
    try {
        const req = createRequire(import.meta.url);
        const fs = req('node:fs');
        const text = fs.readFileSync(path.join(root, '.env'), 'utf8');
        const m = text.match(new RegExp(`^${key}=(.*)$`, 'm'));

        return m ? m[1].trim().replace(/^["']|["']$/g, '') : undefined;
    } catch {
        return undefined;
    }
}
