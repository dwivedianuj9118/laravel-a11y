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

    public function test_passes_are_merged_and_variant_only_issues_are_labelled(): void
    {
        $issues = IssueGrouper::group(json_decode(file_get_contents(__DIR__.'/../fixtures/scan-output-variants.json'), true));
        $by = [];
        foreach ($issues as $i) {
            $by[$i['rule'].' '.$i['file'].':'.$i['line']] = $i;
        }

        $this->assertSame('only at 320 px wide', IssueGrouper::variantNote($by['reflow resources/views/reflow.blade.php:4']));
        $this->assertSame('only at 320 px wide', IssueGrouper::variantNote($by['reflow resources/views/reflow.blade.php:5']));
        $this->assertSame('only in dark mode', IssueGrouper::variantNote($by['color-contrast resources/views/dark.blade.php:3']));
        $this->assertSame(1, $by['color-contrast resources/views/dark.blade.php:3']['count']);
        $this->assertSame(null, IssueGrouper::variantNote(['variants' => ['desktop', 'mobile']]));
    }

    public function test_same_element_in_several_passes_counts_once(): void
    {
        $page = fn ($variant) => ['session' => 'guest', 'variant' => $variant, 'url' => 'http://x/', 'finalUrl' => 'http://x/', 'violations' => [[
            'id' => 'image-alt', 'impact' => 'critical', 'help' => 'h', 'helpUrl' => 'u', 'tags' => ['wcag111'],
            'nodes' => [['target' => ['img'], 'html' => '<img>', 'src' => 'a.blade.php:3', 'how' => 'marker']],
        ]]];
        $issues = IssueGrouper::group(['pages' => [$page('desktop'), $page('mobile'), $page('dark')]]);

        $this->assertSame(1, count($issues));
        $this->assertSame(1, $issues[0]['count']);
        $this->assertSame(['desktop', 'mobile', 'dark'], $issues[0]['variants']);
    }

    public function test_state_only_issues_say_which_click_reveals_them(): void
    {
        $issues = IssueGrouper::group(json_decode(file_get_contents(__DIR__.'/../fixtures/scan-output-states.json'), true));
        $by = [];
        foreach ($issues as $i) {
            $by[$i['rule'].' '.$i['line']] = $i;
        }

        $this->assertSame('only after opening a menu or dialog', IssueGrouper::variantNote($by['image-alt 6']));
        $this->assertSame(['appears after clicking “Menu” (resources/views/states.blade.php:3)'], $by['image-alt 6']['also']);
        $this->assertSame(['appears after clicking “More filters” (resources/views/states.blade.php:10)'], $by['label 11']['also']);
        $this->assertSame(null, IssueGrouper::variantNote($by['target-size 3']), 'also visible without clicking');
    }
}
