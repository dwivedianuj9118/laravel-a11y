# laravel-a11y

Find WCAG accessibility issues in your Laravel app and get the **exact file and line to fix** — in Blade, Livewire, Filament and Inertia (Vue / React) — not a CSS selector.

```
  CRITICAL button-name WCAG 4.1.2 ×2
  resources/views/components/icon-button.blade.php:1
  <button type="button" class="icon-button"></button>
  Fix: Put text inside the button, or add aria-label="…" for icon-only buttons.

  SERIOUS  color-contrast WCAG 1.4.3
  app/Filament/Resources/Users/UserResource.php:21 (Filament field `TextInput::make('name')->required()->…`)
  <input id="form.name" type="text" wire:model="data.name" style="color:#d0d0d0;">
  Fix: Check styles set on this field (->extraInputAttributes(), ->extraAttributes()) or the panel colors…
```

## Quick start

```bash
composer require dwivedianuj/laravel-a11y --dev
php artisan a11y:install
php artisan serve                       # in another terminal; plus `npm run dev` for Vue/React
php artisan a11y:scan --html=a11y.html  # then open a11y.html
```

## Why

Browser tools (axe DevTools, WAVE, Lighthouse) tell you *which element* fails. In a Laravel app that element came from a Blade component, a partial inside a `@foreach`, a Livewire view, a Filament field defined in PHP, or a Vue/React component — and finding that line is most of the work.

laravel-a11y stamps every element with where it came from **while your views compile** (dev only), so the answer is a lookup, not a guess:

| Stack | How the line is found |
| --- | --- |
| Blade, components, `@include`, layouts | Blade hook adds `data-a11y-src="file:line"` to each tag at compile time |
| Livewire 3 / 4 | Same Blade hook (Livewire views are Blade) |
| Vendor Blade components (`<x-filament::icon-button>`, Flux…) | `data-a11y-use` marks the line in *your* view that used the component |
| Filament 4 / 5 fields (rendered in PHP) | `wire:model="data.email"` → the `->make('email')` line in your Resource / Schema |
| Inertia + Vue 3 / React (JSX/TSX) | Vite plugin adds markers to `.vue` / `.jsx` / `.tsx` in dev |

Issues are grouped by source line, so one broken component used on 40 pages is **one** fix.

## Requirements

PHP 8.2+, Laravel 11 / 12 / 13, Node 18+. Optional: Livewire 3/4, Filament 4/5, Inertia 2/3 with Vue 3 or React 18/19.

## Install

```bash
composer require dwivedianuj/laravel-a11y --dev
php artisan a11y:install          # config, playwright + axe-core + Chromium, Vite plugin for Vue/React
php artisan a11y:install --ci     # …and a GitHub Actions workflow
```

Markers and the scanner only run in the environments in `a11y.environments` (`local`, `testing`). Production HTML and production builds are never touched.

## Scan

Start your app, then:

```bash
php artisan a11y:scan                         # every GET page found from your routes
php artisan a11y:scan --as=1                  # also pages behind login + every Filament page, as user 1
php artisan a11y:scan /pricing /blog/hello    # just these pages
php artisan a11y:scan --best-practice         # also axe best-practice rules (headings, landmarks…)
php artisan a11y:scan --html=a11y.html        # shareable HTML report
```

| Option | |
| --- | --- |
| `--as=ID`, `--guard=` | Log in as this user (signed, 30-minute, local-only link). Filament panels use their own guard. |
| `--standard=` | `wcag2a`, `wcag2aa`, `wcag21aa`, `wcag22aa` (default) |
| `--fail-on=` | Exit 1 on `minor`, `moderate`, `serious` (default), `critical`, or `none` |
| `--json=`, `--html=`, `--sarif=` | Write reports |
| `--baseline` | Accept the current issues (see below) |

Not on `APP_URL`? Use `A11Y_BASE_URL=http://127.0.0.1:8000 php artisan a11y:scan`.

### What gets scanned

- Every GET route without required parameters, plus routes whose parameters you give in `a11y.params` (`['post' => 'hello-world']`). Model-bound parameters use the first record.
- With `--as`: routes behind `auth` and every Filament panel page and resource page (index, create, edit, view).
- **Never** routes whose URI or name looks like it changes something — `logout`, `clear-cache`, `delete`, `export`, `cron`… (`a11y.unsafe_words`). Pass them explicitly if you really want them.
- Skipped routes are listed with the reason.

### Where issues point

| Shown as | Meaning |
| --- | --- |
| `file:line` | Exact: the element's own marker, the line where you used a vendor component, or the Filament field's `->make()` |
| `in file` | Inertia page component (the Vite plugin wasn't active, so no exact line) |
| `near file:line` | Nearest marked parent; the element was built by JavaScript or PHP |
| `vendor file:line` | Inside a package view, and no line in your code uses it |

The scanner also warns when **PHP warnings are printed into a page** (`<b>Deprecated</b>: …`) — visitors would see them.

## Vue and React (Inertia)

`a11y:install` adds the plugin to `vite.config.js`:

```js
import a11y from './vendor/dwivedianuj/laravel-a11y/js/vite-plugin/index.mjs';

export default defineConfig({
    plugins: [a11y(), laravel({ /* … */ }), vue()],
});
```

- On for `npm run dev` when `APP_ENV` is `local`/`testing`. Off for `npm run build` unless `A11Y_BUILD_MARKERS=true` (CI only — don't deploy that build).
- `A11Y_MARKERS=false` turns it off.
- `a11y({ vueComponents: true })` also marks Vue component call sites (Vue may warn about extraneous attributes on multi-root components). `reactComponents: false` turns them off for React.
- React needs `@babel/parser` (`a11y:install` adds it).

## CI: fail only on new issues

```bash
php artisan a11y:scan --baseline     # writes a11y-baseline.json — commit it
```

From then on `a11y:scan` marks known issues as *in baseline* and fails only on new ones. Fingerprints use the rule, the file and the element's HTML (not the line number), so adding code above an old issue doesn't make it "new".

`php artisan a11y:install --ci` adds `.github/workflows/a11y.yml`, which on every pull request:

1. builds the front-end with markers, serves the app, scans as user 1 with `--fail-on=serious`;
2. uploads **SARIF**, so issues appear as annotations on the exact lines in the PR's *Files changed* tab;
3. uploads the HTML report as a build artifact.

Code-scanning annotations are free on public repositories; private repositories need GitHub Code Security (the workflow keeps going without it). Check the *Prepare app* step matches your app (seeders, database, the `--as` user).

## Config

`config/a11y.php` (published by `a11y:install`): environments, markers, standard, `exclude` patterns, `unsafe_words`, `params`, `auth.user`/`auth.guard`, `filament`, concurrency and timeouts, `baseline`, `fail_on`, `editor` (`vscode`, `cursor`, `phpstorm`) for clickable `file:line` links.

After turning Blade markers on or off, run `php artisan view:clear` (`a11y:scan` does this for you).

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `Cannot reach http://…` | The app isn't answering at the base URL. Start it (`php artisan serve --port=8123`) and run `A11Y_BASE_URL=http://127.0.0.1:8123 php artisan a11y:scan`. For Herd/Valet `.test` sites, first check `curl -I http://yoursite.test` works (`herd restart`, `herd link`). |
| "None of the scanned pages has laravel-a11y source markers" | You're scanning a different app (another server on that port: `kill $(lsof -ti tcp:8123)`), a production build, a non-local `APP_ENV`, or a cached page. Markers only appear in `a11y.environments`. |
| Every page times out | Same as "Cannot reach", or the page needs more than `a11y.timeout_ms` (default 30 s) to load. |
| Issues show `near …` or `in resources/js/Pages/X.vue` instead of a line | The Vite plugin isn't active: run `npm run dev` (APP_ENV local), or build with `A11Y_BUILD_MARKERS=true npm run build`. Check `vite.config.js` has `a11y()` in `plugins`. |
| Pages behind login are skipped | Pass `--as=ID` (a real user id), or set `A11Y_USER`. If routes are cached, run `php artisan route:clear` (the login route is registered at runtime). |
| A route is skipped with "no value for {param}" | Add an example value in `config/a11y.php` → `params`, e.g. `'slug' => 'hello-world'`. |
| A route you need is skipped with "may change data" | Its name contains a word from `unsafe_words`. Pass it explicitly: `php artisan a11y:scan /reports/export-preview`. |
| "PHP output printed into the page" | `display_errors` is on and PHP warnings/deprecations are written into your HTML. Fix the cause or turn `display_errors` off. |
| `composer require` fails on PHP 8.5 with a dev dependency (e.g. paratest) | Not this package: an existing lock-file entry doesn't allow your PHP version yet. Use `composer require dwivedianuj/laravel-a11y --dev --ignore-platform-req=php`. |
| Old views have no markers | `php artisan view:clear` (`a11y:scan` does it automatically unless `--keep-views`). |

## Limits

- Automated checks (axe-core) find only part of WCAG. Also test keyboard use, focus order, alt-text quality, zoom/reflow and captions by hand — the HTML report has a checklist.
- Filament multi-tenant panels are not scanned yet.
- Svelte and Inertia SSR-only markup are not marked yet.

## Testing the package

```bash
composer test                                        # PHP unit tests
node --test tests/js/vite-plugin.test.mjs            # Vite plugin (needs vue + @babel/parser installed)
```

## License

MIT © Anuj Dwivedi
