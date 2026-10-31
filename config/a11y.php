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

    // Route URIs to skip (Str::is patterns).
    'exclude' => [
        '_ignition*', '_debugbar*', 'telescope*', 'horizon*', 'pulse*', 'api/*', 'sanctum/*',
        'livewire*', 'storage/*', 'up', 'filament/*', '*/login', 'login', 'register', 'password/*',
        'lens-for-laravel*', '_inertia*', '_boost*',
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

    // Exit with code 1 when an issue of this impact or worse is found: minor, moderate, serious, critical
    'fail_on' => 'serious',

    // Editor for clickable file:line links: vscode, cursor, phpstorm, none
    'editor' => env('A11Y_EDITOR', 'vscode'),
];
