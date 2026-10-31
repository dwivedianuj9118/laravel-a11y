# Changelog

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
