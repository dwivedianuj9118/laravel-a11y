# laravel-a11y

Find WCAG accessibility issues in your Laravel app and get the **exact file:line** to fix, not a CSS selector.

> Status: early development. Blade, Livewire, Filament 4/5, Inertia (Vue 3 / React) and logged-in scans work.

## How it works

1. In `local`/`testing` only, a Blade hook stamps `data-a11y-src="resources/views/x.blade.php:42"` on every HTML tag while views compile. Production HTML is never touched.
2. `a11y:scan` finds your pages from routes, opens each in headless Chromium (Playwright) and runs [axe-core](https://github.com/dequelabs/axe-core).
3. Each failing element is mapped through its marker to the exact Blade line. Issues are grouped by file:line, so one broken component used on 40 pages is one fix.

## Install

```bash
composer require dwivedianuj/laravel-a11y --dev
php artisan a11y:install   # config, playwright + axe-core + Chromium, and the Vite plugin for Vue/React
```

### Vue and React (Inertia)

`a11y:install` adds this to your `vite.config.js` when it finds Vue or React in `package.json`:

```js
import a11y from './vendor/dwivedianuj/laravel-a11y/js/vite-plugin/index.mjs';

export default defineConfig({
    plugins: [a11y(), laravel({ ... }), vue()],
});
```

It stamps every element in `.vue` templates and `.jsx`/`.tsx` with `data-a11y-src="file:line"` while `npm run dev` runs (APP_ENV local/testing only). `npm run build` never adds markers unless you set `A11Y_BUILD_MARKERS=true`, so production bundles stay clean. Options: `a11y({ vueComponents: true })` also marks Vue component call sites (may log "extraneous attributes" warnings for multi-root components); `reactComponents: false` turns them off for React.

## Use

```bash
php artisan serve &                     # the app must be running at APP_URL (or set A11Y_BASE_URL)
php artisan a11y:scan                   # every GET route without login
php artisan a11y:scan /pricing /blog    # specific pages
php artisan a11y:scan --standard=wcag21aa --fail-on=critical --json=a11y.json
php artisan a11y:scan --as=1            # also pages behind login + every Filament panel/resource page, as user 1
```

### Where issues point

| Label | Meaning |
| --- | --- |
| `file:line` (cyan) | Exact line in your code: the element's own marker, the line where you used a vendor component (`<x-filament::icon-button>`), or the Filament field's `->make('name')` call |
| `in file` | Inertia page component that rendered it (Vite plugin not active, so no exact line) |
| `near file:line` | Nearest marked parent; the element was built by JavaScript or PHP |
| `vendor file:line` | Inside a package view with no call site in your code |

### Logged-in pages

`--as=ID` (or `A11Y_USER`) logs the scanner in through a signed, 30-minute, local-only link (`/_a11y/login/{id}`). The route only exists in `a11y.environments`. Filament panels are scanned with each panel's own guard. Multi-tenant panels are not supported yet.

Example output:

```
  CRITICAL button-name WCAG 4.1.2 ×2
  resources/views/components/icon-button.blade.php:1
  <button type="button" class="icon-button"></button>
  Fix: Put text inside the button, or add aria-label="…" for icon-only buttons.
```

Elements created by JavaScript have no marker; they are reported as **near** the closest marked parent, never as an exact line.

## Config

`config/a11y.php`: environments, standard, excluded routes, route parameter values (`params`), concurrency, `fail_on`, editor links (vscode, cursor, phpstorm).

After turning markers on or off, run `php artisan view:clear` (`a11y:scan` does this for you).

## Limits

Automated checks catch only part of WCAG. Also test keyboard use, focus order, alt-text quality and captions by hand.

## License

MIT
