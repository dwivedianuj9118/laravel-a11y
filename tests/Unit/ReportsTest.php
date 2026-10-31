<?php

namespace DwivediAnuj\A11y\Tests\Unit;

use DwivediAnuj\A11y\Report\Baseline;
use DwivediAnuj\A11y\Report\HtmlReporter;
use DwivediAnuj\A11y\Report\SarifReporter;
use PHPUnit\Framework\TestCase;

class ReportsTest extends TestCase
{
    private function issue(array $over = []): array
    {
        return array_merge([
            'rule' => 'image-alt', 'impact' => 'critical', 'wcag' => ['1.1.1'], 'help' => 'Images must have alternative text',
            'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.14/image-alt', 'fix' => 'Add alt', 'file' => 'resources/views/a.blade.php',
            'line' => 12, 'how' => 'marker', 'note' => null, 'also' => [], 'count' => 1, 'pages' => ['http://x/'], 'sessions' => ['guest'],
            'html' => '<img src="/a.png">', 'target' => 'img',
        ], $over);
    }

    public function test_baseline_survives_line_shifts_and_livewire_ids_but_catches_real_changes(): void
    {
        $a = Baseline::fingerprint($this->issue());

        $this->assertSame($a, Baseline::fingerprint($this->issue(['line' => 40])), 'line moved');
        $this->assertSame($a, Baseline::fingerprint($this->issue(['html' => "<img   src=\"/a.png\">\n"])), 'whitespace');
        $this->assertSame(
            Baseline::fingerprint($this->issue(['html' => '<input wire:id="abc" id="form.x">', 'rule' => 'label'])),
            Baseline::fingerprint($this->issue(['html' => '<input wire:id="zzz" id="form.y">', 'rule' => 'label'])),
            'random ids',
        );
        $this->assertTrue($a !== Baseline::fingerprint($this->issue(['file' => 'resources/views/b.blade.php'])));
        $this->assertTrue($a !== Baseline::fingerprint($this->issue(['rule' => 'role-img-alt'])));
    }

    public function test_baseline_round_trip_marks_only_new_issues(): void
    {
        $path = sys_get_temp_dir().'/a11y-bl-'.uniqid().'.json';
        Baseline::save($path, [$this->issue()]);

        $marked = Baseline::mark([$this->issue(['line' => 99]), $this->issue(['html' => '<img src="/other.png">'])], Baseline::load($path));
        @unlink($path);

        $this->assertSame([true, false], array_column($marked, 'baseline'));
    }

    public function test_sarif_has_rules_results_and_skips_vendor_and_unmapped(): void
    {
        $issues = Baseline::mark([
            $this->issue(),
            $this->issue(['rule' => 'label', 'impact' => 'moderate', 'file' => 'vendor/x/y.blade.php']),
            $this->issue(['rule' => 'region', 'impact' => 'minor', 'file' => null]),
            $this->issue(['rule' => 'image-alt', 'file' => 'resources/js/Pages/Fixture.vue', 'line' => null, 'how' => 'page']),
        ], []);

        $sarif = SarifReporter::build($issues, '1.0.0');
        $run = $sarif['runs'][0];

        $this->assertSame('2.1.0', $sarif['version']);
        $this->assertSame(['image-alt'], array_column($run['tool']['driver']['rules'], 'id'));
        $this->assertSame(2, count($run['results']));
        $loc = $run['results'][0]['locations'][0]['physicalLocation'];
        $this->assertSame(['resources/views/a.blade.php', 12], [$loc['artifactLocation']['uri'], $loc['region']['startLine']]);
        $this->assertSame(1, $run['results'][1]['locations'][0]['physicalLocation']['region']['startLine']);
        $this->assertSame('error', $run['results'][0]['level']);
        $this->assertSame('new', $run['results'][0]['baselineState']);
    }

    public function test_html_report_escapes_content(): void
    {
        $html = HtmlReporter::render([
            'base_url' => 'http://x', 'standard' => 'wcag22aa', 'axe' => '4', 'pages' => [], 'skipped' => [],
            'issues' => [$this->issue(['html' => '<script>alert(1)</script>', 'fix' => '<b>x</b>'])],
        ]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame(false, str_contains($html, '<script>alert(1)'));
        $this->assertStringContainsString('<html lang="en">', $html);
    }
}
