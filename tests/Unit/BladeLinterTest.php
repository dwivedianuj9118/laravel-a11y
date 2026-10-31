<?php

namespace DwivediAnuj\A11y\Tests\Unit;

use DwivediAnuj\A11y\Lint\BladeLinter;
use PHPUnit\Framework\TestCase;

class BladeLinterTest extends TestCase
{
    private function rules(string $blade): array
    {
        return array_map(fn ($i) => $i['rule'].':'.$i['line'], (new BladeLinter)->lint($blade, 'v.blade.php'));
    }

    public function test_finds_the_fixture_errors_at_their_lines(): void
    {
        $issues = (new BladeLinter)->lint(file_get_contents(__DIR__.'/../fixtures/blade-page.blade.php'), 'blade-page.blade.php');

        $this->assertSame(['image-alt:12', 'label:15', 'link-name:18'], array_map(fn ($i) => $i['rule'].':'.$i['line'], $issues));
        $this->assertSame('lint', $issues[0]['how']);
        $this->assertSame(['1.1.1'], $issues[0]['wcag']);
    }

    public function test_reports_obvious_problems(): void
    {
        $this->assertSame([
            'image-alt:1', 'button-name:2', 'button-name:3', 'link-name:4', 'html-has-lang:5', 'frame-title:6', 'tabindex:7', 'keyboard-unreachable:8', 'label:9',
        ], $this->rules(implode("\n", [
            '<img src="a.png">',
            '<button type="button"></button>',
            '<button><svg viewBox="0 0 1 1"><path d="M0"/></svg></button>',
            '<a href="/x"><i class="fa fa-home"></i></a>',
            '<html>',
            '<iframe src="/v"></iframe>',
            '<span tabindex="3">x</span>',
            '<div wire:click="save">Save</div>',
            '<select name="c"><option>1</option></select>',
        ])));
    }

    public function test_does_not_report_things_that_may_be_fine_at_runtime(): void
    {
        $this->assertSame([], $this->rules(implode("\n", [
            '<img src="a.png" alt="">',
            '<img src="{{ $u }}" :alt="$name">',
            '<img {{ $attributes }}>',
            '<img src="a.png" aria-hidden="true">',
            '<button type="button">{{ __("Save") }}</button>',
            '<button type="button"><x-heroicon-o-trash /></button>',
            '<button type="button" aria-label="Close"><svg></svg></button>',
            '<button type="button"><svg></svg><span class="sr-only">Delete</span></button>',
            '<a href="/x">Home</a>',
            '<a href="/x">{{ $slot }}</a>',
            '<a name="top"></a>',
            '<label for="email">Email</label><input id="email" type="email">',
            '<label>Name <input type="text"></label>',
            '<input type="hidden" name="t"><input type="submit" value="Go">',
            '<input id="{{ $id }}" type="text">',
            '<input type="text" placeholder="Search">',
            '<html lang="{{ app()->getLocale() }}">',
            '<iframe title="Video" src="/v"></iframe>',
            '<div role="button" tabindex="0" wire:click="go">Go</div>',
            '<button wire:click="save">Save</button>',
            '<span tabindex="0">x</span>',
            '<x-avatar src="a" />',
            '{{-- <img src="commented"> --}}',
            '@php $x = "<img src=x>"; @endphp',
        ])));
    }
}
