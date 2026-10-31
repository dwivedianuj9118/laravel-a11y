<?php

namespace DwivediAnuj\A11y\Discovery;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns the app's GET routes into concrete URLs to scan.
 */
class RouteDiscoverer
{
    /** @var array<int, array{uri: string, reason: string}> */
    public array $skipped = [];

    public function __construct(private Router $router) {}

    /**
     * @return array{public: array<int, string>, auth: array<int, string>} absolute URLs
     */
    public function discover(string $baseUrl, array $config, bool $skipFilament = false): array
    {
        $found = ['public' => [], 'auth' => []];
        $this->skipped = [];

        /** @var Route $route */
        foreach ($this->router->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (Str::is($config['exclude'] ?? [], $uri) || str_starts_with($uri, '_a11y')) {
                continue;
            }

            // Filament pages are found (with their records and guard) by FilamentDiscoverer.
            if ($skipFilament && str_starts_with((string) $route->getName(), 'filament.')) {
                continue;
            }

            if ($route->getDomain()) {
                $this->skipped[] = ['uri' => $uri, 'reason' => 'domain route'];

                continue;
            }

            $resolved = $this->fillParameters($route, $uri, $config['params'] ?? []);

            if ($resolved === null) {
                continue;
            }

            $found[$this->needsAuth($route) ? 'auth' : 'public'][] = rtrim($baseUrl, '/').'/'.ltrim($resolved, '/');
        }

        $max = (int) ($config['max_pages'] ?? 100);

        return [
            'public' => array_slice(array_values(array_unique($found['public'])), 0, $max),
            'auth' => array_slice(array_values(array_unique($found['auth'])), 0, $max),
        ];
    }

    private function needsAuth(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $name = is_string($middleware) ? $middleware : '';

            if ($name === 'auth' || str_starts_with($name, 'auth:') || $name === 'verified'
                || str_ends_with($name, '\\Authenticate') || str_contains($name, 'Authenticate:')) {
                return true;
            }
        }

        return false;
    }

    private function fillParameters(Route $route, string $uri, array $params): ?string
    {
        if (! preg_match_all('/\{(\w+)(\?)?(?::\w+)?\}/', $uri, $matches, PREG_SET_ORDER)) {
            return $uri;
        }

        foreach ($matches as $match) {
            // PCRE drops a trailing unmatched group, so "?" may be missing entirely.
            [$token, $name, $optional] = [$match[0], $match[1], $match[2] ?? ''];

            $value = $params[$name] ?? $this->firstModelKey($route, $name);

            if ($value === null && $optional === '?') {
                $uri = str_replace('/'.$token, '', $uri);
                $uri = str_replace($token, '', $uri);

                continue;
            }

            if ($value === null) {
                $this->skipped[] = ['uri' => $route->uri(), 'reason' => "no value for {{$name}} (set a11y.params.{$name})"];

                return null;
            }

            $uri = str_replace($token, rawurlencode((string) $value), $uri);
        }

        return $uri;
    }

    private function firstModelKey(Route $route, string $name): string|int|null
    {
        try {
            foreach ($route->signatureParameters(UrlRoutable::class) as $parameter) {
                if ($parameter->getName() !== $name) {
                    continue;
                }

                $class = $parameter->getType()?->getName();

                if ($class && is_subclass_of($class, Model::class)) {
                    /** @var Model $model */
                    $model = new $class;
                    $field = $route->bindingFieldFor($name) ?? $model->getRouteKeyName();

                    return $class::query()->value($field);
                }
            }
        } catch (Throwable) {
            // Database not migrated, closure routes without types, etc.
        }

        return null;
    }
}
