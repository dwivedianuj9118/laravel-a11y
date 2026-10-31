<?php

namespace DwivediAnuj\A11y\Markers;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Hooks the injector into Blade. Laravel 11+ runs prepareStringsForCompilationUsing
 * callbacks on the raw template before anything else, so line numbers are exact.
 */
final class BladeMarkers
{
    /** @var array<string, string> */
    private static array $fileCache = [];

    public static function register(BladeCompiler $compiler, string $basePath, bool $markVendor, bool $markCallSites = true): void
    {
        $injector = new MarkerInjector($markCallSites);

        $callback = function (string $value) use ($compiler, $injector, $basePath, $markVendor) {
            $path = $compiler->getPath();

            if (! $path || ! self::isTheFileBeingCompiled($path, $value)) {
                return $value; // inline Blade::render() strings etc.: no reliable file:line
            }

            $relative = self::relative($path, $basePath);

            if (! $markVendor && str_starts_with($relative, 'vendor/')) {
                return $value;
            }

            return $injector->inject($value, $relative);
        };

        // Run before other raw-string hooks (e.g. Livewire 4 islands rewrite the template),
        // so we always see the file exactly as it is on disk.
        $prepended = (function () use ($callback): bool {
            if (! property_exists($this, 'prepareStringsForCompilationUsing') || ! is_array($this->prepareStringsForCompilationUsing)) {
                return false;
            }
            array_unshift($this->prepareStringsForCompilationUsing, $callback);

            return true;
        })->call($compiler);

        if (! $prepended) {
            $compiler->prepareStringsForCompilationUsing($callback);
        }
    }

    /**
     * getPath() keeps the last compiled path, so a later compileString() call for an
     * inline string would be attributed to the wrong file. Only stamp when the string
     * really is that file's contents.
     */
    private static function isTheFileBeingCompiled(string $path, string $value): bool
    {
        if (! isset(self::$fileCache[$path])) {
            if (count(self::$fileCache) > 500) {
                self::$fileCache = [];
            }
            self::$fileCache[$path] = is_file($path) ? (string) file_get_contents($path) : '';
        }

        return self::$fileCache[$path] === $value;
    }

    public static function relative(string $path, string $basePath): string
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
