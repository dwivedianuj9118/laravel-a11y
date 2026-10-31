<?php

namespace DwivediAnuj\A11y\Report;

/**
 * Short, Laravel-flavoured fixes for the most common axe rules.
 * Rules not listed fall back to axe's own one-line help text.
 */
final class FixHints
{
    private const HINTS = [
        'image-alt' => 'Add alt="…" describing the image, or alt="" if it is decorative.',
        'input-image-alt' => 'Add alt="…" to the <input type="image">.',
        'label' => 'Give the field a <label for="id">, or aria-label="…" if no visible label fits.',
        'select-name' => 'Give the <select> a <label for="id"> or aria-label="…".',
        'button-name' => 'Put text inside the button, or add aria-label="…" for icon-only buttons.',
        'link-name' => 'Put text inside the link, or add aria-label="…" for icon-only links.',
        'color-contrast' => 'Darken the text or lighten the background to reach 4.5:1 (3:1 for large text).',
        'target-size' => 'Make the clickable area at least 24×24 px (padding or min-width/min-height).',
        'html-has-lang' => 'Add lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}" to <html>.',
        'document-title' => 'Add a <title> inside <head>.',
        'frame-title' => 'Add title="…" to the <iframe>.',
        'heading-order' => 'Do not skip heading levels (h2 → h4); use the next level down.',
        'page-has-heading-one' => 'Add one <h1> that names the page.',
        'landmark-one-main' => 'Wrap the main content in <main>.',
        'region' => 'Put all content inside landmarks (<header>, <nav>, <main>, <footer>).',
        'list' => 'Only <li>, <script> or <template> may be direct children of <ul>/<ol>.',
        'listitem' => 'Place <li> inside a <ul> or <ol>.',
        'duplicate-id' => 'Make id values unique (watch ids inside @foreach and reused components).',
        'duplicate-id-aria' => 'Make id values used by aria-* and <label for> unique.',
        'aria-hidden-focus' => 'Remove focusable elements from aria-hidden containers, or add tabindex="-1".',
        'aria-required-attr' => 'Add the aria-* attributes this role requires.',
        'aria-valid-attr-value' => 'Fix the aria-* value: it must match an existing id or allowed value.',
        'nested-interactive' => 'Do not put buttons or links inside other buttons or links.',
        'link-in-text-block' => 'Underline links inside text, or give them 3:1 contrast with the text.',
        'meta-viewport' => 'Remove user-scalable=no / maximum-scale from the viewport meta tag.',
        'autocomplete-valid' => 'Use a valid autocomplete value (email, name, tel, current-password…).',
        'empty-heading' => 'Put text in the heading or remove it.',
        'keyboard-unreachable' => 'Use <button type="button" wire:click="…"> / <button @click="…"> instead of a <div>/<span> with a click handler (or add tabindex="0", a role and Enter/Space handling).',
        'focus-trap' => 'Let Tab move on: remove keydown handlers that preventDefault() on Tab; modals must close with Esc and return focus.',
        'focus-not-visible' => 'Add a :focus-visible style (outline: 2px solid; outline-offset: 2px, or Tailwind focus-visible:ring-2); never remove outlines without a replacement.',
        'focus-obscured' => 'Keep focused elements visible: add scroll-padding-top (sticky headers) or scroll-padding-bottom (cookie bars) on html, or move the overlay.',
        'reflow' => 'Remove fixed widths / white-space:nowrap / wide tables at small widths; use max-width:100%, flex-wrap, overflow-x:auto on the table wrapper.',
        'svg-img-alt' => 'Add role="img" with aria-label="…" (or <title>) to meaningful SVGs; aria-hidden="true" to decorative ones.',
    ];

    /** Filament-specific wording when the fix lives in a Resource/Schema or a Filament component call. */
    public static function forLocation(string $rule, string $how, string $note, string $default): string
    {
        if ($how === 'field') {
            return match ($rule) {
                'label' => "Give the field a label: remove ->label('') / ->hiddenLabel(), or add ->extraInputAttributes(['aria-label' => '…']).",
                'color-contrast' => 'Check styles set on this field (->extraInputAttributes(), ->extraAttributes()) or the panel colors (->colors()) / theme CSS.',
                default => $default.' (set it on this Filament field, e.g. via ->extraInputAttributes([...]))',
            };
        }

        if ($how === 'call-site' && in_array($rule, ['button-name', 'link-name'], true)) {
            return 'Pass label="…" to this component (Filament renders it as aria-label/tooltip), or put text inside it.';
        }

        return $default;
    }

    public static function for(string $rule, string $fallback): string
    {
        return self::HINTS[$rule] ?? $fallback;
    }
}
