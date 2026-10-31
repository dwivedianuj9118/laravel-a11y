// Run: node --test tests/js/vite-plugin.test.mjs   (from an app or folder where vue + @babel/parser are installed,
// or set A11Y_NODE_MODULES_ROOT to such a folder)
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import path from 'node:path';
import { stampVue, stampJsx } from '../../js/vite-plugin/index.mjs';

const req = createRequire(path.join(process.env.A11Y_NODE_MODULES_ROOT || process.cwd(), 'package.json'));
const strip = (s) => s.replace(/ data-a11y-(src|use)="[^"]*"/g, '');

// Every stamped tag must really start on the line its marker names.
function assertLinesMatch(original, stamped) {
    const lines = original.split('\n');
    const re = /<([A-Za-z][\w.:-]*)(?:<[^>]*>)? data-a11y-(?:src|use)="[^":]*:(\d+)"/g;
    let m;
    let n = 0;
    while ((m = re.exec(stamped))) {
        n++;
        assert.ok(lines[m[2] - 1].includes('<' + m[1]), `line ${m[2]} should contain <${m[1]}: ${lines[m[2] - 1]}`);
    }

    return n;
}

const VUE = `<script setup>
import IconButton from '../Components/IconButton.vue';
const items = [1, 2];
// a < b in script must not matter: <div>
</script>

<template>
    <main>
        <h1>Inertia fixture</h1>
        <img src="/vue.png" />
        <input type="text" v-model="q" :class="{ on: a < b }" />
        <IconButton @click="go" />
        <template v-if="items.length">
            <ul><li v-for="i in items" :key="i">{{ i < 2 ? 'a' : 'b' }}</li></ul>
        </template>
        <p v-else>none</p>
        <slot name="footer" />
    </main>
</template>

<style scoped>
main > p { color: red }
</style>
`;

test('vue: stamps every html element with its exact line, leaves the rest untouched', () => {
    const out = stampVue(VUE, 'resources/js/Pages/Fixture.vue', false, req);

    assert.equal(strip(out), VUE);
    assert.equal(assertLinesMatch(VUE, out), 7); // main h1 img input ul li p
    assert.match(out, /<img data-a11y-src="resources\/js\/Pages\/Fixture.vue:10" src/);
    assert.match(out, /<IconButton @click/); // components untouched by default
    assert.match(out, /<slot name="footer"/);
    assert.match(out, /<template v-if/);
});

test('vue: component call sites when enabled; existing markers and pug templates are respected', () => {
    const out = stampVue(VUE, 'F.vue', true, req);
    assert.match(out, /<IconButton data-a11y-use="F.vue:12" @click/);

    const pre = `<template><p data-a11y-src="keep:1">x</p></template>`;
    assert.equal(stampVue(pre, 'F.vue', false, req), pre);

    const pug = `<template lang="pug">\np hello\n</template>`;
    assert.equal(stampVue(pug, 'F.vue', false, req), pug);

    assert.equal(stampVue(`<script setup>const a = 1</script>`, 'F.vue', false, req), `<script setup>const a = 1</script>`);
});

const TSX = `import { Link, Head } from '@inertiajs/react';
import { Fragment } from 'react';
import { Select } from './Select';

type P = { items: string[] };

export default function Fixture({ items }: P) {
    const cmp = 1 < 2 && items.length > 0; // "<div>" in a string: '<span>'
    return (
        <>
            <Head title="x" />
            <main className="p-4">
                <img src="/r.png" />
                <input type="text" />
                <Link href="/a">Go</Link>
                <Select<string> options={items} />
                {items.map((i) => (
                    <Fragment key={i}><li>{i}</li></Fragment>
                ))}
                {cmp ? <svg:rect /> : <span>no</span>}
                <motion.div layout />
            </main>
        </>
    );
}
`;

test('react: host elements get src, components get use, builtins are skipped', () => {
    const out = stampJsx(TSX, 'resources/js/Pages/Fixture.tsx', true, true, req);

    assert.equal(strip(out), TSX);
    assertLinesMatch(TSX, out);
    assert.match(out, /<main data-a11y-src="resources\/js\/Pages\/Fixture.tsx:12" className/);
    assert.match(out, /<img data-a11y-src="resources\/js\/Pages\/Fixture.tsx:13"/);
    assert.match(out, /<Link data-a11y-use="resources\/js\/Pages\/Fixture.tsx:15" href/);
    assert.match(out, /<Select<string> data-a11y-use="resources\/js\/Pages\/Fixture.tsx:16" options/);
    assert.match(out, /<motion.div data-a11y-use=/);
    assert.match(out, /<li data-a11y-src="resources\/js\/Pages\/Fixture.tsx:18">/);
    assert.match(out, /<span data-a11y-src=/);
    assert.match(out, /<Head title/);
    assert.match(out, /<Fragment key/);
    assert.match(out, /'<span>'/); // strings untouched
});

test('react: plain jsx and component markers off', () => {
    const jsx = `export const A = () => <div><Btn /></div>;\n`;
    const out = stampJsx(jsx, 'A.jsx', false, false, req);

    assert.equal(out, `export const A = () => <div data-a11y-src="A.jsx:1"><Btn /></div>;\n`);
});
