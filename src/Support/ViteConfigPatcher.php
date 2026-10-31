<?php

namespace DwivediAnuj\A11y\Support;

/**
 * Adds the laravel-a11y Vite plugin to vite.config.{js,ts,mjs,mts}:
 *   import a11y from './vendor/dwivedianuj/laravel-a11y/js/vite-plugin/index.mjs';
 *   plugins: [a11y(), ...]
 */
final class ViteConfigPatcher
{
    public const IMPORT = "import a11y from './vendor/dwivedianuj/laravel-a11y/js/vite-plugin/index.mjs';";

    public static function find(string $basePath): ?string
    {
        foreach (['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.mts'] as $name) {
            if (is_file($basePath.'/'.$name)) {
                return $basePath.'/'.$name;
            }
        }

        return null;
    }

    /**
     * @return string|null the patched source, or null if it cannot be patched safely
     */
    public static function patch(string $source): ?string
    {
        if (str_contains($source, 'laravel-a11y')) {
            return $source; // already there
        }

        if (preg_match_all('/\bplugins\s*:\s*\[/', $source) !== 1) {
            return null; // zero or several plugin arrays: let a human decide
        }

        $patched = preg_replace('/\bplugins\s*:\s*\[/', '$0'."\n        a11y(),", $source, 1);

        // Put the import after the last existing import, or at the top.
        // Whole import statements, including multi-line `import {\n a,\n b\n} from 'x';`.
        if (preg_match_all('/^import\b[\s\S]*?[\'"][^\'"\n]+[\'"];?[ \t]*$/m', $patched, $m, PREG_OFFSET_CAPTURE) && $m[0] !== []) {
            [$text, $offset] = end($m[0]);
            $at = $offset + strlen(rtrim($text));

            return substr($patched, 0, $at)."\n".self::IMPORT.substr($patched, $at);
        }

        return self::IMPORT."\n".$patched;
    }
}
