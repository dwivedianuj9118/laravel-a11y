<?php

return [

    /*
    | Environments where markers are added and scans may run. Markers are never added
    | anywhere else, so production HTML is untouched.
    */
    'environments' => ['local', 'testing'],

    /*
    | Stamp data-a11y-src="file:line" on HTML tags when Blade compiles a view.
    | After changing this, run: php artisan view:clear
    */
    'markers' => env('A11Y_MARKERS', true),

    // Also stamp views under vendor/ (Filament, Livewire, packages).
    'mark_vendor_views' => true,

    // Where the scanner sends requests. Defaults to APP_URL.
    'base_url' => env('A11Y_BASE_URL'),

    // wcag2a, wcag2aa, wcag21aa, wcag22aa
    'standard' => env('A11Y_STANDARD', 'wcag22aa'),

    // Also run axe "best-practice" rules (not WCAG requirements).
    'best_practice' => false,

    // Keyboard & focus checks with real Tab presses: unreachable click handlers (2.1.1), focus
    // traps (2.1.2), invisible focus (2.4.7), focus hidden behind sticky bars (2.4.11).
    'keyboard' => true,
    'max_tabs' => 150,

    /*
    | Open menus, accordions, popups and dialogs and scan again (issues that only exist after a
    | click). Only explicit open/close controls are clicked (aria-expanded, aria-haspopup, <summary>,
    | Alpine toggles) — never submit buttons, navigating links or wire:click — and all non-GET
    | requests are blocked during this pass, so nothing can be saved or deleted.
    */
    'states' => true,
    'max_states' => 5, // per page

    // Also scan every page at 320 px wide (WCAG 1.4.10 Reflow + issues that only appear on phones).
    'mobile' => true,

    // Also scan every page with prefers-color-scheme: dark (contrast in your dark theme). CLI: --dark
    'dark' => false,
    // If your dark theme is switched by a class on <html> (Tailwind darkMode: 'class'), name it here, e.g. 'dark'.
    'dark_class' => null,

    // Route URIs to skip (Str::is patterns).
    'exclude' => [
        '_ignition*', '_debugbar*', 'telescope*', 'horizon*', 'pulse*', 'api/*', 'sanctum/*',
        'livewire*', 'storage/*', 'up', 'filament/*', '*/login', 'login', 'register', 'password/*',
        'lens-for-laravel*', '_inertia*', '_boost*',
    ],

    /*
    | GET routes whose URI or name contains one of these words are never visited by route
    | discovery, because opening them may change something (log out, clear cache, run a job…).
    | Pass the URL to a11y:scan explicitly if you really want it scanned.
    */
    'unsafe_words' => [
        'logout', 'log-out', 'signout', 'sign-out', 'clear', 'cache', 'flush', 'artisan', 'migrate', 'seed',
        'optimize', 'install', 'deploy', 'delete', 'destroy', 'remove', 'reset', 'cron', 'queue', 'webhook',
        'callback', 'impersonate', 'unsubscribe', 'export', 'download', 'backup', 'restore', 'sync', 'refresh',
        'run', 'trigger', 'toggle', 'approve', 'reject', 'cancel', 'verify', 'confirm', 'activate', 'payment',
    ],

    // Values for route parameters, e.g. ['user' => 1, 'post' => 'hello-world'].
    // Model-bound parameters without a value here use the first record in the table.
    'params' => [],

    /*
    | Logged-in scanning. Pages behind auth (and Filament panels) are scanned as this user id,
    | via a signed, local-only login link. Leave null to skip them. CLI: --as=ID
    */
    'auth' => [
        'user' => env('A11Y_USER'),
        'guard' => null, // default: config('auth.defaults.guard'); Filament panels use their own guard
    ],

    // Find Filament panel pages and resource pages (index, create, edit, view).
    'filament' => true,

    // Stamp data-a11y-use="file:line" on component tags, so issues inside vendor components
    // (e.g. <x-filament::icon-button>) point to the line in your view that used them.
    'mark_component_call_sites' => true,

    'max_pages' => 100,
    'concurrency' => 3,
    'timeout_ms' => 30000,
    'wait_ms' => 0,

    'node_binary' => env('A11Y_NODE', 'node'),

    // Known issues accepted for now (php artisan a11y:scan --baseline). Commit this file.
    'baseline' => 'a11y-baseline.json',

    // Exit with code 1 when an issue of this impact or worse is found: minor, moderate, serious, critical
    'fail_on' => 'serious',

    // Editor for clickable file:line links: vscode, cursor, phpstorm, none
    'editor' => env('A11Y_EDITOR', 'vscode'),
];
