# Changelog

## 2.0.0 — 2026-10-31

New checks beyond static axe-core rules. They run by default, so a scan can now report more issues than 1.x — use `--baseline` to accept existing ones.

- **Keyboard & focus** (real Tab presses): click handlers keyboard users can't reach — `wire:click`/`@click`/`onclick` on `div`/`span` (2.1.1), keyboard traps (2.1.2), no visible focus indicator (2.4.7), focused element hidden behind sticky headers or cookie bars (2.4.11). `--no-keyboard` to skip.
- **320 px pass** (on by default): horizontal scrolling at 320 px wide, with the outermost overflowing element or overflowing text mapped to its line (1.4.10), plus every axe rule at phone width. `--no-mobile` to skip.
- **Dark mode pass** (`--dark` or `'dark' => true`; `dark_class` for class-based themes).
- **Interactive states**: opens menus, accordions, popups and dialogs (only explicit disclosure controls; never submit, navigating links or `wire:click`; all non-GET requests blocked) and scans what they reveal. Issues say which click revealed them. `--no-states` to skip.
- **`a11y:lint`**: static Blade checks without a browser (alt, labels, empty buttons/links, lang, iframe title, positive tabindex, unreachable click handlers), with JSON, SARIF and its own baseline.
- Issues only seen in an extra pass are labelled (*only at 320 px wide*, *only in dark mode*, *only after opening a menu or dialog*); the same element across passes counts once.
- HTML report: long paths now wrap on small screens (found by the new 320 px check).

## 1.0.0 — 2026-10-31

First release.

- Blade compile-time source markers (`data-a11y-src`) with exact line numbers; component call-site markers (`data-a11y-use`).
- Vite plugin for Vue 3 SFCs and React JSX/TSX; off for production builds unless `A11Y_BUILD_MARKERS=true`.
- `a11y:scan`: route discovery (skips routes that look like they change data), Filament 4/5 panel and resource discovery, logged-in scans via signed local-only login link (`--as`).
- Source resolution: own marker → vendor component call site → Filament field (`wire:model` → `->make()`) → Inertia page → nearest parent, each clearly labelled.
- Reports: terminal, JSON, self-contained HTML, SARIF 2.1.0 for GitHub code scanning.
- Baseline (`--baseline`): fail CI only on new issues; fingerprints survive line shifts.
- Detects PHP warnings printed into pages.
- `a11y:install` (config, Playwright, axe-core, Chromium, Vite plugin) and `--ci` GitHub Actions workflow.
