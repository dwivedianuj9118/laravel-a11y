// WCAG 1.4.10 Reflow (AA): at 320 CSS px wide, content must not need horizontal scrolling.
// Run in a 320 px wide context. Reports the outermost elements that stick out of the viewport,
// mapped to their source, skipping content inside its own horizontal scroll area (allowed:
// data tables, code blocks, maps…).

export const REFLOW_RULE = {
    impact: 'serious',
    tags: ['wcag21aa', 'wcag1410', 'cat.structure'],
    help: 'Page needs horizontal scrolling at 320 px wide (content does not reflow)',
    helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/reflow.html',
};

export async function reflowCheck(page, tags) {
    if (!tags.includes(REFLOW_RULE.tags[0])) return [];

    const nodes = await page.evaluate(() => {
        const doc = document.documentElement;
        const width = doc.clientWidth;
        if (doc.scrollWidth <= width + 1) return [];

        const scrolls = (el) => {
            for (let n = el.parentElement; n && n !== document.body; n = n.parentElement) {
                const s = getComputedStyle(n);
                if (['auto', 'scroll'].includes(s.overflowX) || s.overflowX === 'hidden' && n.scrollWidth > n.clientWidth) return true;
            }
            return false;
        };

        const out = [];
        const culprits = [];
        for (const el of document.body.querySelectorAll('*')) {
            const s = getComputedStyle(el);
            if (s.display === 'none' || s.visibility === 'hidden' || s.position === 'fixed') continue;
            const r = el.getBoundingClientRect();
            if (r.width === 0 || culprits.some((c) => c.contains(el)) || scrolls(el)) continue;

            // a) the element's own box is too wide (fixed width, min-width, wide image…)
            let over = Math.round(r.right + scrollX - width);
            let why = `${Math.round(r.width)}px wide; ${s.width !== 'auto' ? 'width: ' + s.width : 'min-width: ' + s.minWidth}`;

            // b) the box fits but its own text spills out (white-space: nowrap, long unbroken words)
            if (over <= 1) {
                const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 0);
                const textOver = Math.round(r.left + scrollX + el.scrollWidth - width);
                if (!ownText || s.overflowX !== 'visible' || el.scrollWidth <= el.clientWidth + 1 || textOver <= 1) continue;
                over = textOver;
                why = `its text is ${el.scrollWidth}px wide in a ${Math.round(r.width)}px box; white-space: ${s.whiteSpace}, overflow-wrap: ${s.overflowWrap}`;
            }

            culprits.push(el);
            out.push(window.__a11y.node(el, `Sticks out ${over}px past the 320px viewport (${why}).`));
            if (out.length >= 10) break;
        }
        return out;
    });

    return nodes.length ? [{ id: 'reflow', ...REFLOW_RULE, nodes }] : [];
}
