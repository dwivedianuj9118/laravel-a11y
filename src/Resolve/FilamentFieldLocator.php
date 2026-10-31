<?php

namespace DwivediAnuj\A11y\Resolve;

use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Filament 4/5 renders most fields in PHP (toEmbeddedHtml), so their HTML has no Blade marker.
 * We get the field's state path from wire:model (e.g. "data.nickname") and the Livewire page
 * component from the DOM, then find the ->make('nickname') call in that page's Resource files.
 */
class FilamentFieldLocator
{
    /** @var array<string, array<int, string>> */
    private array $fileCache = [];

    public function __construct(private string $basePath) {}

    /**
     * @return array{file: string, line: int, code: string}|null
     */
    public function locate(string $statePath, ?string $component): ?array
    {
        $files = $this->candidateFiles($component);

        foreach ($this->fieldNames($statePath) as $name) {
            $pattern = '/::make\(\s*[\'"]'.preg_quote($name, '/').'[\'"]/';

            foreach ($files as $file) {
                foreach ($this->lines($file) as $i => $line) {
                    if (preg_match($pattern, $line)) {
                        return [
                            'file' => $this->relative($file),
                            'line' => $i + 1,
                            'code' => self::shorten($line),
                        ];
                    }
                }
            }
        }

        return null;
    }

    /** "TextInput::make('name')->required()->…  // note" → "TextInput::make('name')->required()->…" (max 70 chars) */
    public static function shorten(string $line): string
    {
        $code = trim(preg_replace('#\s//.*$#', '', $line));
        $code = rtrim($code, ',; ');

        return mb_strlen($code) > 70 ? mb_substr($code, 0, 69).'…' : $code;
    }

    /**
     * "data.address.street" → ["address.street", "street"]; "mountedActions.0.data.email" → [..., "email"].
     *
     * @return array<int, string>
     */
    public function fieldNames(string $statePath): array
    {
        $parts = array_values(array_filter(explode('.', $statePath), fn ($p) => $p !== '' && ! ctype_digit($p)));
        array_shift($parts); // the form's own state path, usually "data"

        $names = [];
        for ($i = 0; $i < count($parts); $i++) {
            $names[] = implode('.', array_slice($parts, $i));
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * Page class first, then its Resource and every file next to it (Schemas/, Tables/, Pages/),
     * then the rest of app/Filament.
     *
     * @return array<int, string>
     */
    private function candidateFiles(?string $component): array
    {
        $files = [];
        $class = $component ? $this->livewireClass($component) : null;

        if ($class) {
            $files[] = $this->fileOf($class);

            $resource = null;
            try {
                if (method_exists($class, 'getResource')) {
                    $resource = $class::getResource();
                }
            } catch (Throwable) {
            }

            $root = $resource ? $this->fileOf($resource) : null;
            if ($root) {
                $files[] = $root;
                $files = [...$files, ...$this->phpFiles(dirname($root).'/'.class_basename($resource))];
                $files = [...$files, ...$this->phpFiles(dirname($root))];
            } elseif ($files[0]) {
                $files = [...$files, ...$this->phpFiles(dirname($files[0]))];
            }
        }

        $files = [...$files, ...$this->phpFiles($this->basePath.'/app/Filament')];

        return array_values(array_unique(array_filter($files)));
    }

    private function livewireClass(string $name): ?string
    {
        $tries = [
            fn () => app('livewire.finder')->resolveClassComponentClassName($name),             // Livewire 4
            fn () => app(\Livewire\Mechanisms\ComponentRegistry::class)->getClass($name),        // Livewire 3
            fn () => implode('\\', array_map(fn ($s) => Str::studly($s), explode('.', $name))), // default naming
        ];

        foreach ($tries as $try) {
            try {
                $class = $try();
                if (is_string($class) && class_exists($class)) {
                    return $class;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function fileOf(string $class): ?string
    {
        try {
            return (new ReflectionClass($class))->getFileName() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string> */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
            if (count($out) > 500) {
                break;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<int, string> */
    private function lines(string $file): array
    {
        return $this->fileCache[$file] ??= is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    private function relative(string $file): string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
