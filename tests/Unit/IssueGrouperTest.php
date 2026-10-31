<?php

namespace DwivediAnuj\A11y\Tests\Unit;

use DwivediAnuj\A11y\Report\IssueGrouper;
use DwivediAnuj\A11y\Resolve\FilamentFieldLocator;
use DwivediAnuj\A11y\Resolve\SourceResolver;
use PHPUnit\Framework\TestCase;

class IssueGrouperTest extends TestCase
{
    public function test_groups_by_file_line_and_rule(): void
    {
        $scan = json_decode(file_get_contents(__DIR__.'/../fixtures/scan-output.json'), true);
        $issues = IssueGrouper::group($scan);

        $buttons = array_values(array_filter($issues, fn ($i) => $i['rule'] === 'button-name'));
        $this->assertSame(1, count($buttons), 'same component used twice = one issue');
        $this->assertSame(2, $buttons[0]['count']);
        $this->assertSame('resources/views/components/icon-button.blade.php', $buttons[0]['file']);
        $this->assertSame(1, $buttons[0]['line']);
        $this->assertSame('marker', $buttons[0]['how']);

        $jsImg = array_values(array_filter($issues, fn ($i) => $i['rule'] === 'image-alt' && $i['how'] === 'ancestor'));
        $this->assertSame(1, count($jsImg), 'JS-built element is reported as near its parent, not as exact');

        $this->assertSame('critical', $issues[0]['impact'], 'sorted worst first');
        $this->assertSame(['1.1.1'], array_values(array_filter($issues, fn ($i) => $i['rule'] === 'image-alt'))[0]['wcag']);
    }

    public function test_threshold(): void
    {
        $this->assertTrue(IssueGrouper::meetsThreshold('critical', 'serious'));
        $this->assertSame(false, IssueGrouper::meetsThreshold('moderate', 'serious'));
        $this->assertSame(false, IssueGrouper::meetsThreshold('critical', 'none'));
    }

    public function test_real_scanner_output_with_filament_and_inertia_resolves_to_app_code(): void
    {
        $scan = json_decode(file_get_contents(__DIR__.'/../fixtures/scan-output-sessions.json'), true);
        $base = __DIR__.'/../fixtures/app';
        $issues = IssueGrouper::group($scan, new SourceResolver(new FilamentFieldLocator($base), $base));

        $where = [];
        foreach ($issues as $i) {
            $where[$i['rule'].' '.$i['how']][] = $i['file'].($i['line'] ? ':'.$i['line'] : '');
        }

        $this->assertSame(['resources/views/filament/pages/fixture-report.blade.php:4'], $where['button-name call-site']);
        $this->assertSame(['app/Filament/Resources/Users/UserResource.php:23'], $where['label field']);
        $this->assertSame(['resources/views/filament/pages/fixture-report.blade.php:2'], $where['image-alt marker']);
        $this->assertSame(['resources/js/Pages/Fixture.vue'], $where['image-alt page']);
        $this->assertSame(['user:1'], $issues[0]['sessions']);
    }
}
