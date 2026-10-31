<?php

namespace DwivediAnuj\A11y\Commands;

use DwivediAnuj\A11y\Support\ViteConfigPatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class InstallCommand extends Command
{
    protected $signature = 'a11y:install
        {--no-npm : Skip installing playwright, axe-core and Chromium}
        {--no-vite : Do not add the Vite plugin (Vue/React markers) to vite.config}
        {--ci : Also add a GitHub Actions workflow (.github/workflows/a11y.yml)}';

    protected $description = 'Publish laravel-a11y config, install the browser scanner and add the Vite plugin for Vue/React';

    public function handle(): int
    {
        if (! file_exists(config_path('a11y.php'))) {
            Artisan::call('vendor:publish', ['--tag' => 'a11y-config']);
            $this->components->info('Published config/a11y.php');
        }

        $package = json_decode((string) @file_get_contents(base_path('package.json')), true) ?: [];
        $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
        $usesReact = isset($deps['react']);
        $usesVue = isset($deps['vue']);

        if (! $this->option('no-npm')) {
            $npm = ['npm', 'install', '--save-dev', 'playwright', 'axe-core'];
            if ($usesReact && ! isset($deps['@babel/parser'])) {
                $npm[] = '@babel/parser'; // the Vite plugin parses JSX/TSX with it
            }

            foreach ([$npm, ['npx', 'playwright', 'install', 'chromium']] as $cmd) {
                $this->components->task(implode(' ', $cmd), function () use ($cmd) {
                    $result = Process::path(base_path())->timeout(600)->run($cmd);
                    if (! $result->successful()) {
                        $this->newLine();
                        $this->line($result->errorOutput() ?: $result->output());
                    }

                    return $result->successful();
                });
            }
        }

        if (! $this->option('no-vite') && ($usesVue || $usesReact)) {
            $this->addVitePlugin();
        }

        if ($this->option('ci')) {
            $this->addWorkflow();
        }

        Artisan::call('view:clear');
        $this->components->info('Done. Start your app, then run: php artisan a11y:scan');

        return self::SUCCESS;
    }

    private function addWorkflow(): void
    {
        $target = base_path('.github/workflows/a11y.yml');

        if (file_exists($target)) {
            $this->components->info('.github/workflows/a11y.yml already exists; left unchanged');

            return;
        }

        @mkdir(dirname($target), 0755, true);
        copy(__DIR__.'/../../stubs/github-action.yml', $target);
        $this->components->info('Added .github/workflows/a11y.yml (check the "Prepare app" step matches your app, e.g. seeders and the --as user id)');
    }

    private function addVitePlugin(): void
    {
        $file = ViteConfigPatcher::find(base_path());

        if (! $file) {
            $this->components->warn('No vite.config found. Add the plugin by hand (see README: Vue and React).');

            return;
        }

        $source = file_get_contents($file);
        $patched = ViteConfigPatcher::patch($source);

        if ($patched === null) {
            $this->components->warn('Could not safely edit '.basename($file).'. Add these two lines yourself:');
            $this->line('  '.ViteConfigPatcher::IMPORT);
            $this->line('  plugins: [a11y(), ...]');

            return;
        }

        if ($patched === $source) {
            $this->components->info(basename($file).' already uses the laravel-a11y plugin');

            return;
        }

        file_put_contents($file, $patched);
        $this->components->info('Added the laravel-a11y plugin to '.basename($file).' (Vue/React markers in `npm run dev`; restart Vite)');
    }
}
