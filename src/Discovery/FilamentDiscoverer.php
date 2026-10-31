<?php

namespace DwivediAnuj\A11y\Discovery;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Lists every Filament panel page and resource page (index, create, edit, view …) as URLs,
 * grouped by the auth guard each panel uses. Works with Filament 4 and 5; does nothing if
 * Filament is not installed.
 */
class FilamentDiscoverer
{
    /** @var array<int, array{uri: string, reason: string}> */
    public array $skipped = [];

    public static function available(): bool
    {
        return class_exists(\Filament\Facades\Filament::class);
    }

    /**
     * @return array<string, array<int, string>> guard => relative URLs
     */
    public function discover(): array
    {
        $this->skipped = [];

        if (! static::available()) {
            return [];
        }

        $byGuard = [];

        foreach (\Filament\Facades\Filament::getPanels() as $panel) {
            $id = $panel->getId();

            if ($panel->hasTenancy()) {
                $this->skipped[] = ['uri' => $panel->getPath(), 'reason' => "Filament panel [{$id}] uses tenancy (not supported yet)"];

                continue;
            }

            $guard = $panel->getAuthGuard();
            $urls = [];

            foreach ($panel->getPages() as $page) {
                $url = $this->tryUrl(fn () => $page::getUrl(panel: $id), $page);
                if ($url) {
                    $urls[] = $url;
                }
            }

            foreach ($panel->getResources() as $resource) {
                foreach (array_keys($resource::getPages()) as $name) {
                    $url = $this->tryUrl(fn () => $resource::getUrl($name, panel: $id, shouldGuessMissingParameters: true), "{$resource}::{$name}")
                        ?? $this->withFirstRecord($resource, $name, $id);

                    if ($url) {
                        $urls[] = $url;
                    }
                }
            }

            $byGuard[$guard] = array_values(array_unique([...($byGuard[$guard] ?? []), ...$urls]));
        }

        return $byGuard;
    }

    private function withFirstRecord(string $resource, string $name, string $panel): ?string
    {
        try {
            /** @var class-string<Model> $model */
            $model = $resource::getModel();
            $record = $model::query()->first();
        } catch (Throwable) {
            $record = null;
        }

        if (! $record) {
            $this->skipped[] = ['uri' => class_basename($resource)."::{$name}", 'reason' => 'no record in the table to open'];

            return null;
        }

        return $this->tryUrl(fn () => $resource::getUrl($name, ['record' => $record], panel: $panel), "{$resource}::{$name}", true);
    }

    private function tryUrl(callable $make, string $label, bool $report = false): ?string
    {
        try {
            $url = $make();
        } catch (Throwable $e) {
            if ($report) {
                $this->skipped[] = ['uri' => class_basename($label), 'reason' => 'could not build URL: '.strtok($e->getMessage(), "\n")];
            }

            return null;
        }

        // Make it relative so it follows a11y.base_url rather than APP_URL.
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? "{$path}?{$query}" : $path;
    }
}
