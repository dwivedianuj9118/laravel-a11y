<?php

namespace DwivediAnuj\A11y\Commands;

use DwivediAnuj\A11y\Commands\Concerns\RendersIssues;
use DwivediAnuj\A11y\Lint\BladeLinter;
use DwivediAnuj\A11y\Markers\BladeMarkers;
use DwivediAnuj\A11y\Report\Baseline;
use DwivediAnuj\A11y\Report\IssueGrouper;
use DwivediAnuj\A11y\Report\SarifReporter;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class LintCommand extends Command
{
    use RendersIssues;

    protected $signature = 'a11y:lint
        {paths?* : Blade files or folders to check (default: resources/views)}
        {--fail-on= : Exit 1 on this impact or worse: minor, moderate, serious, critical, none}
        {--json= : Write the issues as JSON to this path}
        {--sarif= : Write SARIF 2.1.0 (GitHub code scanning annotations) to this path}
        {--baseline : Accept all current issues: save them to the baseline file and exit successfully}
        {--baseline-file= : Baseline path (default: a11y-lint-baseline.json)}';

    protected $description = 'Check Blade files for common accessibility mistakes, without a browser (fast, for pre-commit and CI)';

    public function handle(BladeLinter $linter): int
    {
        $paths = $this->argument('paths') ?: [resource_path('views')];
        $files = $this->bladeFiles($paths);

        if ($files === []) {
            $this->components->warn('No .blade.php files found in: '.implode(', ', $paths));

            return self::SUCCESS;
        }

        $issues = [];
        foreach ($files as $file) {
            $relative = BladeMarkers::relative($file, base_path());
            foreach ($linter->lint((string) file_get_contents($file), $relative) as $issue) {
                $issues[] = $issue;
            }
        }

        usort($issues, fn ($a, $b) => [IssueGrouper::IMPACT_ORDER[$b['impact']] ?? 0, $a['file'], $a['line']]
            <=> [IssueGrouper::IMPACT_ORDER[$a['impact']] ?? 0, $b['file'], $b['line']]);

        $baselineFile = $this->option('baseline-file') ?: 'a11y-lint-baseline.json';
        $baselinePath = str_starts_with($baselineFile, '/') ? $baselineFile : base_path($baselineFile);
        $baseline = $this->option('baseline') ? [] : Baseline::load($baselinePath);
        $issues = Baseline::mark($issues, $baseline);

        $this->newLine();
        $this->line('  <options=bold>laravel-a11y lint</>  ·  '.count($files).' Blade file(s)');
        $this->renderIssues($issues, $baseline !== [], 'No problems found in '.count($files).' Blade file(s). Run a11y:scan for the full check in a browser.');

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode(['files' => count($files), 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("JSON written to {$path}");
        }

        if ($path = $this->option('sarif')) {
            file_put_contents($path, json_encode(SarifReporter::build($issues), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("SARIF written to {$path}");
        }

        if ($this->option('baseline')) {
            Baseline::save($baselinePath, $issues);
            $this->components->info(count($issues)." issue(s) saved as the lint baseline in {$baselineFile}.");

            return self::SUCCESS;
        }

        $failOn = $this->option('fail-on') ?: config('a11y.fail_on', 'serious');
        $failing = $failOn === 'none' ? [] : array_filter(
            $issues,
            fn ($i) => empty($i['baseline']) && IssueGrouper::meetsThreshold($i['impact'], $failOn),
        );

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function bladeFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $path = str_starts_with($path, '/') ? $path : base_path($path);

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (! is_dir($path)) {
                $this->components->warn("Not found: {$path}");

                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php') && ! str_contains($f->getPathname(), '/vendor/')) {
                    $files[] = $f->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }
}
