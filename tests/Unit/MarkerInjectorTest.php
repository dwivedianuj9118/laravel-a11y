<?php

namespace DwivediAnuj\A11y\Tests\Unit;

use DwivediAnuj\A11y\Markers\MarkerInjector;
use PHPUnit\Framework\TestCase;

class MarkerInjectorTest extends TestCase
{
    private function inject(string $src): string
    {
        return (new MarkerInjector)->inject($src, 'v.blade.php');
    }

    public function test_stamps_tags_with_their_line(): void
    {
        $this->assertSame(
            "<main data-a11y-src=\"v.blade.php:1\">\n  <img data-a11y-src=\"v.blade.php:2\" src=\"a.png\">\n</main>",
            $this->inject("<main>\n  <img src=\"a.png\">\n</main>"),
        );
    }

    public function test_line_numbers_survive_multiline_comments_php_and_verbatim(): void
    {
        $src = "{{-- a\nb --}}\n@php\n\$x = 1;\n@endphp\n@verbatim\n<b>{{ raw }}</b>\n@endverbatim\n<p>x</p>";
        $out = $this->inject($src);

        $this->assertStringContainsString('<p data-a11y-src="v.blade.php:9">', $out);
        $this->assertStringContainsString('<b>{{ raw }}</b>', $out, 'verbatim content untouched');
    }

    public function test_head_tags_get_no_marker_and_components_get_a_call_site_marker(): void
    {
        $out = $this->inject("<html><head><title>T</title></head>\n<x-avatar src=\"a\" />\n<livewire:form /><flux:button>Hi</flux:button><x-slot name=\"s\">a</x-slot><x-slot:footer>f</x-slot:footer>");

        $this->assertSame(0, substr_count($out, 'data-a11y-src'));
        $this->assertStringContainsString('<x-avatar data-a11y-use="v.blade.php:2" src="a" />', $out);
        $this->assertStringContainsString('<flux:button data-a11y-use="v.blade.php:3">', $out);
        $this->assertStringContainsString('<livewire:form />', $out);
        $this->assertStringContainsString('<x-slot name="s">', $out);
        $this->assertStringContainsString('<x-slot:footer>', $out);
    }

    public function test_component_markers_can_be_turned_off(): void
    {
        $out = (new MarkerInjector(stampComponents: false))->inject('<x-avatar /><p>x</p>', 'v');

        $this->assertSame('<x-avatar /><p data-a11y-src="v:1">x</p>', $out);
    }

    public function test_ignores_less_than_inside_echoes_directives_and_attributes(): void
    {
        $src = "@if (\$a <b)\n<p class=\"{{ \$a < \$b ? 'x' : 'y' }}\" x-show=\"count <items\" @class(['on' => \$n > 1])>{{ \$a <c }}</p>\n@endif";
        $out = $this->inject($src);

        $this->assertSame(1, substr_count($out, 'data-a11y-src'));
        $this->assertStringContainsString('<p data-a11y-src="v.blade.php:2" class=', $out);
        $this->assertStringContainsString("@class(['on' => \$n > 1])>", $out);
    }

    public function test_quoted_attribute_with_nested_quotes_in_echo(): void
    {
        $out = $this->inject('<a href="{{ route("home") }}" title="x>y">Go</a><span>s</span>');

        $this->assertSame(2, substr_count($out, 'data-a11y-src'));
        $this->assertStringContainsString('<span data-a11y-src="v.blade.php:1">', $out);
    }

    public function test_script_and_style_bodies_are_left_alone(): void
    {
        $out = $this->inject("<script>if (a<b) { el.innerHTML = '<div>'; }</script>\n<style>a>b{}</style><div>x</div>");

        $this->assertSame(1, substr_count($out, 'data-a11y-src'));
        $this->assertStringContainsString("el.innerHTML = '<div>'", $out);
        $this->assertStringContainsString('<div data-a11y-src="v.blade.php:2">x</div>', $out);
    }

    public function test_alpine_and_livewire_attributes_are_kept(): void
    {
        $src = '<button @click="open = !open" x-on:keydown.escape="open = false" wire:click="save(1)" :disabled="a > b">Save</button>';
        $out = $this->inject($src);

        $this->assertSame(
            '<button data-a11y-src="v.blade.php:1" @click="open = !open" x-on:keydown.escape="open = false" wire:click="save(1)" :disabled="a > b">Save</button>',
            $out,
        );
    }

    public function test_email_addresses_html_comments_and_existing_markers(): void
    {
        $out = $this->inject("<!-- <div> -->\nmail me@example.com <p data-a11y-src=\"keep:1\">p</p>");

        $this->assertStringContainsString('<!-- <div> -->', $out);
        $this->assertStringContainsString('<p data-a11y-src="keep:1">', $out);
        $this->assertSame(1, substr_count($out, 'data-a11y-src'));
    }

    public function test_self_closing_and_uppercase_tags(): void
    {
        $out = $this->inject("<IMG src=a />\n<br/>");

        $this->assertSame("<IMG data-a11y-src=\"v.blade.php:1\" src=a />\n<br data-a11y-src=\"v.blade.php:2\"/>", $out);
    }

    public function test_unclosed_or_fake_tags_are_copied_unchanged(): void
    {
        $src = 'if a<b and c <d then <p>ok</p>';
        $out = $this->inject($src);

        $this->assertStringContainsString('<p data-a11y-src="v.blade.php:1">ok</p>', $out);
        $this->assertStringStartsWith('if a<b and c <d then', $out);
    }

    public function test_output_without_markers_equals_input(): void
    {
        $fixtures = glob(__DIR__.'/../fixtures/*.blade.php') ?: [];
        $this->assertNotEmpty($fixtures);

        foreach ($fixtures as $file) {
            $src = file_get_contents($file);
            $out = (new MarkerInjector)->inject($src, 'f');
            $this->assertSame($src, preg_replace('/ data-a11y-(src|use)="f:\d+"/', '', $out), basename($file));
        }
    }
}
