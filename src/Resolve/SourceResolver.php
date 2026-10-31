<?php

namespace DwivediAnuj\A11y\Resolve;

use DwivediAnuj\A11y\Report\IssueGrouper;

/**
 * Picks the most useful place to fix each failing element, in this order:
 *
 *  1. marker      – the element's own data-a11y-src in your code (exact line)
 *  2. call-site   – the line in your view that used a vendor component (<x-filament::icon-button>)
 *  3. field       – a Filament field: the ->make('name') line in your Resource/Schema
 *  4. page        – an Inertia page component file (line unknown until the Vite plugin lands)
 *  5. vendor / ancestor / none – best remaining clue, clearly labelled as such
 */
class SourceResolver
{
    public function __construct(
        private ?FilamentFieldLocator $filament = null,
        private ?string $basePath = null,
    ) {}

    /**
     * @param  array{src?: ?string, how?: string, uses?: array, ctx?: array}  $node
     * @return array{file: ?string, line: ?int, how: string, note: ?string, also: array<int, string>}
     */
    public function resolve(array $node): array
    {
        [$file, $line] = IssueGrouper::splitSrc($node['src'] ?? null);
        $how = $node['how'] ?? 'none';
        $ctx = $node['ctx'] ?? [];
        $appUse = null;

        foreach ($node['uses'] ?? [] as $use) {
            if (! self::isVendor($use)) {
                $appUse = $use;
                break;
            }
        }

        // 1. Exact marker in the app's own code.
        if ($file !== null && $how === 'marker' && ! self::isVendor($file)) {
            return $this->result($file, $line, 'marker', null, $appUse && $appUse !== "{$file}:{$line}" ? ["used at {$appUse}"] : []);
        }

        // 2. The element itself was rendered by a vendor component used in the app's view: either it
        //    carries the vendor view's marker, or the call-site marker was forwarded onto it directly.
        $selfUse = $node['selfUse'] ?? null;
        if ($selfUse !== null && ! self::isVendor($selfUse)) {
            // Only name the vendor view when it is the element's own (not just a wrapping parent).
            return $this->callSite($selfUse, $how === 'marker' ? $file : null, $line, 'call-site');
        }

        if ($appUse !== null && $how === 'marker') {
            return $this->callSite($appUse, $file, $line, 'call-site');
        }

        // 3. Filament field rendered in PHP.
        if (! empty($ctx['model']) && $this->filament) {
            $hit = $this->filament->locate($ctx['model'], $ctx['component'] ?? null);
            if ($hit) {
                return $this->result($hit['file'], $hit['line'], 'field', "Filament field `{$hit['code']}`", []);
            }
        }

        // 4. Inertia page component.
        if (! empty($ctx['inertia']) && $how !== 'marker') {
            $page = $this->inertiaPage($ctx['inertia']);
            if ($page) {
                return $this->result($page, null, 'page', "inside Inertia page {$ctx['inertia']}", []);
            }
        }

        // An element built by JS/PHP inside a vendor component used in the app: point near that use.
        if ($appUse !== null) {
            return $this->callSite($appUse, $file, $line, 'ancestor');
        }

        // 5. Whatever is left.
        if ($file !== null && self::isVendor($file)) {
            return $this->result($file, $line, 'vendor', 'inside a vendor view; no call site in your code was found', []);
        }

        return $this->result($file, $line, $file !== null ? $how : 'none', null, []);
    }

    private function callSite(string $use, ?string $file, ?int $line, string $how): array
    {
        [$uFile, $uLine] = IssueGrouper::splitSrc($use);

        return $this->result($uFile, $uLine, $how, 'component used here', $file ? ["renders {$file}:{$line}"] : []);
    }

    public static function isVendor(?string $path): bool
    {
        return $path !== null && (str_starts_with($path, 'vendor/') || str_starts_with($path, 'storage/') || str_starts_with($path, '/'));
    }

    private function inertiaPage(string $component): ?string
    {
        if (! $this->basePath) {
            return null;
        }

        foreach (['resources/js/Pages', 'resources/js/pages', 'resources/ts/Pages', 'resources/ts/pages'] as $dir) {
            foreach (['vue', 'tsx', 'jsx', 'svelte', 'ts', 'js'] as $ext) {
                $rel = "{$dir}/{$component}.{$ext}";
                if (is_file($this->basePath.'/'.$rel)) {
                    return $rel;
                }
            }
        }

        return null;
    }

    private function result(?string $file, ?int $line, string $how, ?string $note, array $also): array
    {
        return compact('file', 'line', 'how', 'note', 'also');
    }
}
