<?php

namespace DwivediAnuj\A11y\Lint;

use DwivediAnuj\A11y\Markers\MarkerInjector;
use DwivediAnuj\A11y\Report\FixHints;

/**
 * Fast checks on Blade source, no browser and no running app. Catches the common mistakes
 * before they reach a page; the browser scan (a11y:scan) remains the full check.
 *
 * Deliberately conservative: anything that may be filled in at runtime — {{ $attributes }}
 * spreads, dynamic :alt / :aria-label, Blade echoes or components inside a button — counts as
 * "probably fine", so the linter reports only near-certain problems.
 */
class BladeLinter
{
    public const RULES = [
        'image-alt' => ['critical', ['1.1.1'], 'Images must have alternative text'],
        'label' => ['critical', ['4.1.2', '1.3.1'], 'Form elements must have labels'],
        'button-name' => ['critical', ['4.1.2'], 'Buttons must have discernible text'],
        'link-name' => ['serious', ['2.4.4', '4.1.2'], 'Links must have discernible text'],
        'html-has-lang' => ['serious', ['3.1.1'], '<html> element must have a lang attribute'],
        'frame-title' => ['serious', ['4.1.2'], 'Frames must have an accessible name'],
        'tabindex' => ['serious', ['2.4.3'], 'Positive tabindex breaks the natural focus order'],
        'keyboard-unreachable' => ['serious', ['2.1.1'], 'Clickable element cannot be reached or used with the keyboard'],
    ];

    private const NO_LABEL_TYPES = ['hidden', 'submit', 'button', 'reset', 'image'];

    private const CLICK_ONLY = ['div', 'span', 'li', 'p', 'td', 'tr', 'img', 'i', 'section', 'article', 'svg', 'ul'];

    /**
     * @return array<int, array> issues in the same shape as a11y:scan's
     */
    public function lint(string $source, string $file): array
    {
        $tags = (new MarkerInjector(false))->tags($source);
        $labelFor = [];
        $labelRanges = [];

        foreach ($tags as $t) {
            if (strtolower($t['name']) === 'label') {
                $attrs = $this->attributes($t['text']);
                if (isset($attrs['for'])) {
                    $labelFor[$attrs['for']] = true;
                }
                $close = stripos($source, '</label', $t['end']);
                $labelRanges[] = [$t['start'], $close === false ? $t['end'] : $close];
            }
        }

        $issues = [];
        foreach ($tags as $t) {
            $name = strtolower($t['name']);
            if (str_contains($name, ':') || str_starts_with($name, 'x-')) {
                continue; // components are linted in their own file
            }

            $attrs = $this->attributes($t['text']);
            // A Blade echo outside quoted values ({{ $attributes }}, {{ $inputAttributes->merge(...) }})
            // can add any attribute at runtime, so "missing attribute" rules cannot be trusted.
            $unquoted = preg_replace('/"[^"]*"|\'[^\']*\'/', '', $t['text']);
            $spread = (bool) preg_match('/\{\{|\{!!|\$attributes\b/', $unquoted);
            $has = fn (string ...$names) => (bool) array_intersect_key($attrs, array_flip($names));
            $ariaName = $has('aria-label', ':aria-label', 'x-bind:aria-label', 'aria-labelledby', ':aria-labelledby', 'title', ':title');
            $rule = null;

            switch ($name) {
                case 'img':
                    if (! $spread && ! $has('alt', ':alt', 'x-bind:alt', 'v-bind:alt') && ! $ariaName
                        && ($attrs['role'] ?? '') !== 'presentation' && ($attrs['aria-hidden'] ?? '') !== 'true') {
                        $rule = 'image-alt';
                    }
                    break;

                case 'input':
                case 'select':
                case 'textarea':
                    $type = strtolower($attrs['type'] ?? 'text');
                    $id = $attrs['id'] ?? null;
                    $wrapped = (bool) array_filter($labelRanges, fn ($r) => $t['start'] > $r[0] && $t['start'] < $r[1]);
                    if (! $spread && ! in_array($type, self::NO_LABEL_TYPES, true) && ! $ariaName && ! $wrapped
                        && ! $has('placeholder', ':placeholder')
                        && ! ($id !== null && (isset($labelFor[$id]) || str_contains($id, '{{')))) {
                        $rule = 'label';
                    }
                    break;

                case 'button':
                case 'a':
                    if ($name === 'a' && ! $has('href', ':href', 'wire:navigate')) {
                        break; // anchors without href are not links
                    }
                    $dynamicText = $has('x-text', 'x-html', 'v-text', 'v-html', ':aria-label', 'wire:loading.attr');
                    if (! $spread && ! $ariaName && ! $dynamicText && ! $this->hasText($source, $t['end'], $name)) {
                        $rule = $name === 'a' ? 'link-name' : 'button-name';
                    }
                    break;

                case 'html':
                    if (! $has('lang', ':lang')) {
                        $rule = 'html-has-lang';
                    }
                    break;

                case 'iframe':
                    if (! $spread && ! $ariaName) {
                        $rule = 'frame-title';
                    }
                    break;
            }

            if (! $rule && isset($attrs['tabindex']) && ctype_digit($attrs['tabindex']) && (int) $attrs['tabindex'] > 0) {
                $rule = 'tabindex';
            }

            if (! $rule && in_array($name, self::CLICK_ONLY, true) && ! $has('tabindex', ':tabindex') && ! $has('role')
                && ($click = preg_grep('/^(wire:click|@click|x-on:click|onclick)/', array_keys($attrs)))
                && ! self::isDismiss((string) $attrs[reset($click)])
                && ! $this->wrapsControl($source, $t['end'], $name)) {
                $rule = 'keyboard-unreachable';
            }

            if ($rule) {
                $issues[] = $this->issue($rule, $file, $t);
            }
        }

        return $issues;
    }

    /**
     * Backdrops and overlays that close something on click are a mouse shortcut: keyboard users
     * close with Esc or a close button, so they are not 2.1.1 failures.
     */
    public static function isDismiss(string $handler): bool
    {
        return (bool) preg_match('/close|hide|dismiss|cancel|\b\w*open\w*\s*=\s*false|isOpen\s*=\s*false|\$store\.\w+\.close/i', $handler);
    }

    /** The clickable wrapper contains a real control (or a component that may be one), which handles the keyboard. */
    private function wrapsControl(string $source, int $from, string $tag): bool
    {
        $close = stripos($source, '</'.$tag, $from);
        $inner = substr($source, $from, ($close === false ? min(strlen($source), $from + 2000) : $close) - $from);

        // Echoes count too: Filament actions, components and slots render real buttons/links.
        return (bool) preg_match('/<(button|a\s[^>]*href|input|select|textarea|summary|x-|livewire:)|tabindex=|\{\{|\{!!|@include|@livewire/i', $inner);
    }

    /** Visible text, or something that may produce it at runtime, before the closing tag. */
    private function hasText(string $source, int $from, string $tag): bool
    {
        $close = stripos($source, '</'.$tag, $from);
        $inner = substr($source, $from, ($close === false ? strlen($source) : $close) - $from);

        if (preg_match('/\{\{|\{!!|@lang|@choice|__\(|trans\(|\$slot|<x-|<livewire:|@include|@yield|@stack|aria-label|alt="[^"]+"|<slot/', $inner)) {
            return true;
        }

        $inner = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $inner);
        $inner = preg_replace('/<(svg|i|span)[^>]*class="[^"]*\b(sr-only|visually-hidden)\b[^"]*"[^>]*>.*?<\/\1>/si', 'x', $inner);
        $inner = preg_replace('/<svg\b.*?<\/svg>/si', '', $inner);
        $text = trim(html_entity_decode(strip_tags($inner)));

        return $text !== '' && ! preg_match('/^@\w+(\(.*\))?$/s', $text);
    }

    /** @return array<string, string> */
    private function attributes(string $tagText): array
    {
        $body = preg_replace('/^<[^\s>\/]+/', '', $tagText);
        $body = preg_replace('/\{\{--.*?--\}\}/s', '', $body);
        preg_match_all('/([:@A-Za-z_][\w:.\-@]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/', $body, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $a) {
            $out[strtolower($a[1])] = isset($a[2]) ? trim($a[2], '"\'') : '';
        }

        return $out;
    }

    private function issue(string $rule, string $file, array $tag): array
    {
        [$impact, $wcag, $help] = self::RULES[$rule];

        return [
            'rule' => $rule,
            'impact' => $impact,
            'wcag' => $wcag,
            'help' => $help,
            'helpUrl' => $rule === 'keyboard-unreachable'
                ? 'https://www.w3.org/WAI/WCAG22/Understanding/keyboard.html'
                : "https://dequeuniversity.com/rules/axe/4.10/{$rule}",
            'fix' => FixHints::for($rule, $help),
            'file' => $file,
            'line' => $tag['line'],
            'how' => 'lint',
            'note' => null,
            'also' => [],
            'count' => 1,
            'pages' => [],
            'sessions' => [],
            'html' => mb_strimwidth(preg_replace('/\s+/', ' ', $tag['text']), 0, 300, '…'),
            'target' => '',
        ];
    }
}
