<?php

namespace DwivediAnuj\A11y\Commands;

use DwivediAnuj\A11y\A11yServiceProvider;
use DwivediAnuj\A11y\Auth\ScanLoginController;
use DwivediAnuj\A11y\Commands\Concerns\RendersIssues;
use DwivediAnuj\A11y\Discovery\FilamentDiscoverer;
use DwivediAnuj\A11y\Discovery\RouteDiscoverer;
use DwivediAnuj\A11y\Report\Baseline;
use DwivediAnuj\A11y\Report\HtmlReporter;
use DwivediAnuj\A11y\Report\IssueGrouper;
use DwivediAnuj\A11y\Report\SarifReporter;
use DwivediAnuj\A11y\Resolve\FilamentFieldLocator;
use DwivediAnuj\A11y\Resolve\SourceResolver;
use DwivediAnuj\A11y\Scan\Scanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class ScanCommand extends Command
{
    use RendersIssues;

    protected $signature = 'a11y:scan
        {urls?* : URLs or paths to scan (default: discover from routes and Filament panels)}
        {--as= : Log in as this user id for pages behind login (default: a11y.auth.user)}
        {--guard= : Auth guard for --as (default: a11y.auth.guard or the app default)}
        {--standard= : wcag2a, wcag2aa, wcag21aa or wcag22aa}
        {--best-practice : Also run axe best-practice rules}
        {--no-keyboard : Skip the keyboard & focus checks (Tab walk)}
        {--no-mobile : Skip the extra pass at 320 px wide (reflow)}
        {--dark : Also scan in dark mode (prefers-color-scheme: dark)}
        {--no-states : Do not open menus/dialogs to scan what they reveal}
        {--fail-on= : Exit 1 on this impact or worse: minor, moderate, serious, critical, none}
        {--json= : Write the full report as JSON to this path}
        {--html= : Write a shareable HTML report to this path}
        {--sarif= : Write SARIF 2.1.0 (GitHub code scanning annotations) to this path}
        {--baseline : Accept all current issues: save them to the baseline file and exit successfully}
        {--baseline-file= : Baseline path (default: a11y.baseline)}
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

        if (! $this->reachable($base)) {
            return self::FAILURE;
        }

        $sessions = $this->buildSessions($routes, $filament, $config, $base, $user, $guard);
        $total = array_sum(array_map(fn ($s) => count($s['urls']), $sessions));

        if ($total === 0) {
            $this->components->warn('No pages to scan. Pass URLs, check a11y.exclude / a11y.params, or use --as=ID for pages behind login.');
            $this->renderSkipped();

            return self::FAILURE;
        }

        $this->newLine();
        $passes = array_filter(['desktop', ($config['mobile'] ?? true) && ! $this->option('no-mobile') ? '320px' : null, ($config['dark'] ?? false) || $this->option('dark') ? 'dark' : null]);
        $this->line("  <options=bold>laravel-a11y</>  {$standard}".($this->option('best-practice') ? ' + best-practice' : '')."  ·  {$total} page(s) × ".implode(' + ', $passes)."  ·  {$base}");
        $this->newLine();

        try {
            if ($this->option('no-keyboard')) {
                $config['keyboard'] = false;
            }
            if ($this->option('no-mobile')) {
                $config['mobile'] = false;
            }
            if ($this->option('dark')) {
                $config['dark'] = true;
            }
            if ($this->option('no-states')) {
                $config['states'] = false;
            }
            $scan = $scanner->run($sessions, $tags, $config, fn (string $line) => $this->line('  <fg=gray>'.OutputFormatter::escape($line).'</>'));
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('Scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $resolver = new SourceResolver(new FilamentFieldLocator(base_path()), base_path());
        $issues = IssueGrouper::group($scan, $resolver);

        $baselineFile = $this->option('baseline-file') ?: ($config['baseline'] ?? 'a11y-baseline.json');
        $baselinePath = str_starts_with($baselineFile, '/') ? $baselineFile : base_path($baselineFile);
        $baseline = $this->option('baseline') ? [] : Baseline::load($baselinePath);
        $issues = Baseline::mark($issues, $baseline);

        $this->renderPageProblems($scan);
        $this->warnIfNoMarkers($scan, $base, (bool) $config['markers']);
        $this->renderIssues($issues, $baseline !== []);
        $this->renderSkipped();
        $this->writeReports($issues, $scan, $standard, $base, count($baseline));

        if ($this->option('baseline')) {
            Baseline::save($baselinePath, $issues);
            $this->newLine();
            $this->components->info(count($issues).' issue(s) saved as the baseline in '.$baselineFile.'. Commit it; from now on a11y:scan fails only on new issues.');

            return self::SUCCESS;
        }

        $failing = $failOn === 'none' ? [] : array_filter(
            $issues,
            fn ($i) => empty($i['baseline']) && IssueGrouper::meetsThreshold($i['impact'], $failOn),
        );

        if ($failing !== [] && $failOn !== 'none') {
            $this->newLine();
            $this->components->error(count($failing)." new issue(s) at '{$failOn}' or worse.");
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Markers on but none on any loaded page: almost always the wrong app or a production build. */
    private function warnIfNoMarkers(array $scan, string $base, bool $markersOn): void
    {
        $loaded = array_filter($scan['pages'], fn ($p) => ! $p['error']);

        if (! $markersOn || $loaded === [] || array_sum(array_map(fn ($p) => (int) ($p['markers'] ?? 0), $loaded)) > 0) {
            return;
        }

        $this->components->warn("None of the scanned pages has laravel-a11y source markers, so no issue can be mapped to your code. Is {$base} really this app (check nothing else is running on that port), with APP_ENV=".app()->environment().' and laravel-a11y installed? If pages are cached (response cache, CDN), clear that cache.');
    }

    private function writeReports(array $issues, array $scan, string $standard, string $base, int $baselineCount): void
    {
        $pages = array_map(
            fn ($p) => array_intersect_key($p, array_flip(['session', 'variant', 'url', 'finalUrl', 'status', 'error', 'phpOutput', 'markers'])),
            $scan['pages'],
        );

        $report = [
            'standard' => $standard,
            'base_url' => $base,
            'axe' => $scan['meta']['axe'] ?? null,
            'generated_at' => now()->format('Y-m-d H:i'),
            'sessions' => $scan['sessions'] ?? [],
            'pages' => $pages,
            'issues' => $issues,
            'skipped' => $this->skipped,
            'baseline_count' => $baselineCount,
        ];

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("JSON report written to {$path}");
        }

        if ($path = $this->option('html')) {
            file_put_contents($path, HtmlReporter::render($report, fn ($f, $l) => $this->editorLink($f, $l)));
            $this->components->info("HTML report written to {$path}");
        }

        if ($path = $this->option('sarif')) {
            file_put_contents($path, json_encode(SarifReporter::build($issues, $this->packageVersion()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info("SARIF written to {$path}");
        }
    }

    private function packageVersion(): string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('dwivedianuj/laravel-a11y') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }

    /** Fail fast with a clear message when the app is not running at the base URL. */
    private function reachable(string $base): bool
    {
        try {
            Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->get($base);

            return true; // any HTTP status means the server answered
        } catch (Throwable $e) {
            $this->components->error("Cannot reach {$base} (".strtok($e->getMessage(), "\n").').');
            $this->line('  Is the app running there? Start it and point the scanner at it, e.g.:');
            $this->line('    php artisan serve --port=8123');
            $this->line('    A11Y_BASE_URL=http://127.0.0.1:8123 php artisan a11y:scan');
            $this->line('  Herd/Valet sites: check the site responds with `curl -I '.$base.'` (herd restart / herd link).');

            return false;
        }
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
            $variant = $page['variant'] ?? 'desktop';
            if ($variant !== 'desktop' && ! $page['error']) {
                continue; // status/redirect/PHP-output warnings were already shown for the desktop pass
            }
            $label = $page['url'].(($page['session'] ?? 'guest') !== 'guest' ? " (as {$page['session']})" : '').($variant !== 'desktop' ? " [{$variant}]" : '');

            if ($page['error']) {
                $this->components->warn('Could not scan '.OutputFormatter::escape($label).': '.OutputFormatter::escape($page['error']));
            } elseif (($page['status'] ?? 200) >= 400) {
                $this->components->warn("{$label} returned HTTP {$page['status']}");
            } elseif (rtrim(strtok($page['finalUrl'], '?'), '/') !== rtrim(strtok($page['url'], '?'), '/')) {
                $this->components->warn("{$label} redirected to {$page['finalUrl']} (scanned that page instead)");
            }

            if (! empty($page['phpOutput'])) {
                $this->components->warn(OutputFormatter::escape("{$label} has PHP output printed into the page ({$page['phpOutput']['count']}×, e.g. \"{$page['phpOutput']['first']}\"). Turn off display_errors or fix the cause; visitors would see it."));
            }
        }
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
