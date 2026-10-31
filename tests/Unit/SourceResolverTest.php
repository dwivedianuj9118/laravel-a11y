<?php

namespace DwivediAnuj\A11y\Tests\Unit;

use DwivediAnuj\A11y\Resolve\FilamentFieldLocator;
use DwivediAnuj\A11y\Resolve\SourceResolver;
use PHPUnit\Framework\TestCase;

class SourceResolverTest extends TestCase
{
    private function resolver(): SourceResolver
    {
        $base = __DIR__.'/../fixtures/app';

        return new SourceResolver(new FilamentFieldLocator($base), $base);
    }

    public function test_own_marker_in_app_code_wins(): void
    {
        $r = $this->resolver()->resolve(['src' => 'resources/views/a.blade.php:3', 'how' => 'marker', 'uses' => []]);

        $this->assertSame(['resources/views/a.blade.php', 3, 'marker'], [$r['file'], $r['line'], $r['how']]);
    }

    public function test_vendor_component_points_to_call_site(): void
    {
        $r = $this->resolver()->resolve([
            'src' => 'vendor/filament/support/resources/views/components/icon-button.blade.php:110', 'how' => 'marker',
            'uses' => ['resources/views/filament/pages/fixture-report.blade.php:4', 'resources/views/filament/pages/fixture-report.blade.php:1'],
        ]);

        $this->assertSame(['resources/views/filament/pages/fixture-report.blade.php', 4, 'call-site'], [$r['file'], $r['line'], $r['how']]);
        $this->assertSame(['renders vendor/filament/support/resources/views/components/icon-button.blade.php:110'], $r['also']);
    }

    public function test_filament_field_beats_the_page_wrapper_call_site(): void
    {
        $r = $this->resolver()->resolve([
            'src' => 'vendor/filament/filament/resources/views/components/page/index.blade.php:3', 'how' => 'ancestor',
            'uses' => ['resources/views/filament/pages/fixture-report.blade.php:1'],
            'ctx' => ['model' => 'data.nickname', 'component' => 'app.filament.resources.users.pages.edit-user'],
        ]);

        $this->assertSame(['app/Filament/Resources/Users/UserResource.php', 23, 'field'], [$r['file'], $r['line'], $r['how']]);
        $this->assertStringContainsString("TextInput::make('nickname')", $r['note']);
    }

    public function test_nested_state_paths_and_schema_classes(): void
    {
        $loc = new FilamentFieldLocator(__DIR__.'/../fixtures/app');

        $this->assertSame(['address.street', 'street'], $loc->fieldNames('data.address.street'));
        $this->assertSame(['data.email', 'email'], $loc->fieldNames('mountedActions.0.data.email'));
        $hit = $loc->locate('data.address.street', null);
        $this->assertSame('app/Filament/Resources/Users/Schemas/UserForm.php', $hit['file']);
        $this->assertSame(5, $hit['line']);
    }

    public function test_inertia_page_and_unmapped(): void
    {
        $r = $this->resolver()->resolve(['src' => 'resources/views/app.blade.php:10', 'how' => 'ancestor', 'uses' => [], 'ctx' => ['inertia' => 'Fixture']]);
        $this->assertSame(['resources/js/Pages/Fixture.vue', null, 'page'], [$r['file'], $r['line'], $r['how']]);

        $r = $this->resolver()->resolve(['src' => null, 'how' => 'none']);
        $this->assertSame([null, 'none'], [$r['file'], $r['how']]);

        $r = $this->resolver()->resolve(['src' => 'vendor/x/y.blade.php:2', 'how' => 'marker', 'uses' => ['vendor/x/z.blade.php:1']]);
        $this->assertSame('vendor', $r['how']);
    }

    public function test_call_site_marker_forwarded_onto_a_php_rendered_element_is_exact(): void
    {
        $r = $this->resolver()->resolve([
            'src' => 'vendor/filament/filament/resources/views/components/page/index.blade.php:106', 'how' => 'ancestor',
            'uses' => ['resources/views/filament/pages/fixture-report.blade.php:4'],
            'selfUse' => 'resources/views/filament/pages/fixture-report.blade.php:4',
        ]);

        $this->assertSame(['resources/views/filament/pages/fixture-report.blade.php', 4, 'call-site'], [$r['file'], $r['line'], $r['how']]);
    }

    public function test_field_note_is_trimmed_and_wrapper_view_not_named(): void
    {
        $this->assertSame("TextInput::make('nickname')->label('')->dehydrated(false)",
            FilamentFieldLocator::shorten("            TextInput::make('nickname')->label('')->dehydrated(false), // GT:F1 note"));
        $this->assertSame(70, mb_strlen(FilamentFieldLocator::shorten(str_repeat('a', 100))));

        $r = $this->resolver()->resolve(['src' => 'vendor/x/page.blade.php:1', 'how' => 'ancestor', 'selfUse' => 'resources/views/p.blade.php:4']);
        $this->assertSame([], $r['also']);
    }
}
