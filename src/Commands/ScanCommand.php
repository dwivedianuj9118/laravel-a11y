<?php

namespace DwivediAnuj\A11y\Commands;

use DwivediAnuj\A11y\A11yServiceProvider;
use DwivediAnuj\A11y\Auth\ScanLoginController;
use DwivediAnuj\A11y\Discovery\FilamentDiscoverer;
use DwivediAnuj\A11y\Discovery\RouteDiscoverer;
use DwivediAnuj\A11y\Report\IssueGrouper;
use DwivediAnuj\A11y\Resolve\FilamentFieldLocator;
use DwivediAnuj\A11y\Resolve\SourceResolver;
use DwivediAnuj\A11y\Scan\Scanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class ScanCommand extends Command
{
    protected $signature = 'a11y:scan
        {urls?* : URLs or paths to scan (default: discover from routes and Filament panels)}
        {--as= : Log in as this user id for pages behind login (default: a11y.auth.user)}
        {--guard= : Auth guard for --as (default: a11y.auth.guard or the app default)}
        {--standard= : wcag2a, wcag2aa, wcag21aa or wcag22aa}
        {--best-practice : Also run axe best-practice rules}
        {--fail-on= : Exit 1 on this impact or worse: minor, moderate, serious, critical, none}
        {--json= : Write the full report as JSON to this path}
        {--keep-views : Do not clear compiled views before scanning}';

    protected $description = 'Scan pages for WCAG issues and show the file:line to fix';

    /** @var array<int, array{uri: string, reason: string}> */
    private array $skipped = [];

    public function handle(RouteDiscoverer $routes, FilamentDiscoverer $filament, Scanner $scanner): int
    {
        if (! A11yServiceProvider::enabled()) {
            $this->components->error('laravel-a11y only runs in: '.implode(', ', (array) config('a11y.environments')).' (current: '.app()->environment().')');

            return self::FAILURE;
        }

        $config = config('a11y');
        $base = rtrim($config['base_url'] ?: config('app.url'), '/');
        $standard = $this->option('standard') ?: $config['standard'];
        $failOn = $this->option('fail-on') ?: $config['fail_on'];
        $user = $this->option('as') ?: ($config['auth']['user'] ?? null);
        $guard = $this->option('guard') ?: ($config['auth']['guard'] ?? null);

        try {
            $tags = Scanner::tags($standard, $this->option('best-practice') || $config['best_practice']);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($user && app()->routesAreCached()) {
            $this->components->warn('Routes are cached, so the a11y login route is missing. Run: php artisan route:clear');
        }

        if ($config['markers'] && ! $this->option('keep-views')) {
            Artisan::call('view:clear'); // views compiled before markers were enabled have none
        }

        $sessions = $this->buildSessions($routes, $filament, $config, $base, $user, $guard);
        $total = array_sum(array_map(fn ($s) => count($s['urls']), $sessions));

        if ($total === 0) {
            $this->components->warn('No pages to scan. Pass URLs, check a11y.exclude / a11y.params, or use --as=ID for pages behind login.');
            $this->renderSkipped();

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  <options=bold>laravel-a11y</>  {$standard}".($this->option('best-practice') ? ' + best-practice' : '')."  ·  {$total} page(s)  ·  {$base}");
        $this->newLine();

        try {
            $scan = $scanner->run($sessions, $tags, $config, fn (string $line) => $this->line('  <fg=gray>'.OutputFormatter::escape($line).'</>'));
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('Scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $resolver = new SourceResolver(new FilamentFieldLocator(base_path()), base_path());
        $issues = IssueGrouper::group($scan, $resolver);

        $this->renderPageProblems($scan);
        $this->renderIssues($issues);
        $this->renderSkipped();

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'standard' => $standard,
                'base_url' => $base,
                'axe' => $scan['meta']['axe'] ?? null,
                'sessions' => $scan['sessions'] ?? [],
                'pages' => array_map(fn ($p) => array_intersect_key($p, array_flip(['session', 'url', 'finalUrl', 'status', 'error'])), $scan['pages']),
                'issues' => $issues,
                'skipped' => $this->skipped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("JSON report written to {$path}");
        }

        $failing = $failOn === 'none' ? [] : array_filter($issues, fn ($i) => IssueGrouper::meetsThreshold($i['impact'], $failOn));

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{name: string, loginUrl: ?string, urls: array<int, string>}>
     */
    private function buildSessions(RouteDiscoverer $routes, FilamentDiscoverer $filament, array $config, string $base, ?string $user, ?string $guard): array
    {
        $abs = fn (string $u) => preg_match('#^https?://#', $u) ? $u : $base.'/'.ltrim($u, '/');
        $login = fn (?string $g) => $abs(ScanLoginController::url($user, $g));

        // Explicit URLs: one session, logged in if --as was given.
        if ($this->argument('urls')) {
            return [[
                'name' => $user ? "user {$user}" : 'guest',
                'loginUrl' => $user ? $login($guard) : null,
                'urls' => array_map($abs, $this->argument('urls')),
            ]];
        }

        $withFilament = ($config['filament'] ?? true) && FilamentDiscoverer::available();
        $found = $routes->discover($base, $config, $withFilament);
        $this->skipped = $routes->skipped;

        $sessions = [['name' => 'guest', 'loginUrl' => null, 'urls' => $found['public']]];

        if ($user) {
            $sessions[] = ['name' => "user {$user}", 'loginUrl' => $login($guard), 'urls' => $found['auth']];
        } else {
            foreach ($found['auth'] as $url) {
                $this->skipped[] = ['uri' => ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/'), 'reason' => 'needs login (use --as=ID or A11Y_USER)'];
            }
        }

        if ($withFilament) {
            foreach ($filament->discover() as $panelGuard => $urls) {
                if (! $user) {
                    if ($urls) {
                        $this->skipped[] = ['uri' => count($urls).' Filament page(s)', 'reason' => 'needs login (use --as=ID or A11Y_USER)'];
                    }

                    continue;
                }

                $sessions[] = [
                    'name' => "user {$user} ({$panelGuard} · Filament)",
                    'loginUrl' => $login($panelGuard),
                    'urls' => array_map($abs, $urls),
                ];
            }
            $this->skipped = [...$this->skipped, ...$filament->skipped];
        }

        return array_values(array_filter($sessions, fn ($s) => $s['urls'] !== []));
    }

    private function renderPageProblems(array $scan): void
    {
        foreach ($scan['pages'] as $page) {
            $label = $page['url'].(($page['session'] ?? 'guest') !== 'guest' ? " (as {$page['session']})" : '');

            if ($page['error']) {
                $this->components->warn('Could not scan '.OutputFormatter::escape($label).': '.OutputFormatter::escape($page['error']));
            } elseif (($page['status'] ?? 200) >= 400) {
                $this->components->warn("{$label} returned HTTP {$page['status']}");
            } elseif (rtrim(strtok($page['finalUrl'], '?'), '/') !== rtrim(strtok($page['url'], '?'), '/')) {
                $this->components->warn("{$label} redirected to {$page['finalUrl']} (scanned that page instead)");
            }
        }
    }

    private function renderIssues(array $issues): void
    {
        $this->newLine();

        if ($issues === []) {
            $this->components->info('No WCAG issues found by automated checks. Still test keyboard use, focus order and screen-reader output by hand.');

            return;
        }

        $colors = ['critical' => 'red', 'serious' => 'red', 'moderate' => 'yellow', 'minor' => 'gray'];

        foreach ($issues as $n => $issue) {
            $color = $colors[$issue['impact']] ?? 'white';
            $wcag = $issue['wcag'] ? ' WCAG '.implode(', ', $issue['wcag']) : '';
            $times = $issue['count'] > 1 ? " <fg=gray>×{$issue['count']}</>" : '';
            $pages = count($issue['pages']) > 1 ? ' <fg=gray>on '.count($issue['pages']).' pages</>' : '';

            $this->line(sprintf('  <fg=%s;options=bold>%-8s</> <options=bold>%s</><fg=gray>%s</>%s', $color, strtoupper($issue['impact']), $issue['rule'], $wcag, $times.$pages));
            $this->line('  '.$this->location($issue));
            foreach ($issue['also'] as $also) {
                $this->line('  <fg=gray>  ↳ '.OutputFormatter::escape($also).'</>');
            }
            $this->line('  <fg=gray>'.OutputFormatter::escape(mb_strimwidth(preg_replace('/\s+/', ' ', $issue['html']), 0, 110, '…')).'</>');
            $this->line('  <fg=green>Fix:</> '.OutputFormatter::escape($issue['fix']));
            if ($n < count($issues) - 1) {
                $this->newLine();
            }
        }

        $this->newLine();
        $byImpact = array_count_values(array_column($issues, 'impact'));
        $exact = count(array_filter($issues, fn ($i) => in_array($i['how'], ['marker', 'call-site', 'field'], true)));
        $summary = collect(['critical', 'serious', 'moderate', 'minor'])
            ->filter(fn ($k) => isset($byImpact[$k]))
            ->map(fn ($k) => "{$byImpact[$k]} {$k}")
            ->implode(', ');

        $this->line('  <options=bold>'.count($issues)." issue(s)</>: {$summary}  ·  {$exact}/".count($issues).' mapped to an exact line in your code');
        $this->line('  <fg=gray>Automated checks find only part of WCAG. Also test keyboard use, focus order, alt-text quality and captions by hand.</>');
    }

    private function location(array $issue): string
    {
        if ($issue['file'] === null) {
            return '<fg=yellow>source not found</> <fg=gray>(no marker on this element or its parents) · '.OutputFormatter::escape($issue['target']).'</>';
        }

        $label = $issue['line'] !== null ? "{$issue['file']}:{$issue['line']}" : $issue['file'];
        $link = $this->editorLink($issue['file'], $issue['line'] ?? 1);
        $text = $link ? "<href={$link}>".OutputFormatter::escape($label).'</>' : OutputFormatter::escape($label);
        $note = $issue['note'] ? ' <fg=gray>('.OutputFormatter::escape($issue['note']).')</>' : '';

        return match ($issue['how']) {
            'marker', 'call-site', 'field' => "<fg=cyan>{$text}</>{$note}",
            'page' => "<fg=yellow>in</> {$text}{$note} <fg=gray>(exact line arrives with the Vite plugin)</>",
            'vendor' => "<fg=yellow>vendor</> {$text}{$note}",
            default => "<fg=yellow>near</> {$text}{$note} <fg=gray>(nearest marked parent; the element itself was added by JavaScript or PHP)</>",
        };
    }

    private function editorLink(string $file, int $line): ?string
    {
        $abs = str_starts_with($file, '/') ? $file : base_path($file);

        return match (config('a11y.editor')) {
            'vscode' => "vscode://file/{$abs}:{$line}",
            'cursor' => "cursor://file/{$abs}:{$line}",
            'phpstorm' => "phpstorm://open?file={$abs}&line={$line}",
            default => null,
        };
    }

    private function renderSkipped(): void
    {
        if ($this->skipped === []) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=gray>Skipped '.count($this->skipped).':</>');
        foreach (array_slice($this->skipped, 0, 10) as $s) {
            $this->line('  <fg=gray>· '.OutputFormatter::escape("{$s['uri']} — {$s['reason']}").'</>');
        }
        if (count($this->skipped) > 10) {
            $this->line('  <fg=gray>· …</>');
        }
    }
}
