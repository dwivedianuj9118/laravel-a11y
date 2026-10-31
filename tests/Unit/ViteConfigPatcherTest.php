<?php

namespace DwivediAnuj\A11y\Tests\Unit;

use DwivediAnuj\A11y\Support\ViteConfigPatcher;
use PHPUnit\Framework\TestCase;

class ViteConfigPatcherTest extends TestCase
{
    public function test_patches_a_standard_laravel_vite_config(): void
    {
        $src = "import { defineConfig } from 'vite';\nimport laravel from 'laravel-vite-plugin';\nimport vue from '@vitejs/plugin-vue';\n\nexport default defineConfig({\n    plugins: [\n        laravel({ input: ['resources/js/app.js'] }),\n        vue(),\n    ],\n});\n";
        $out = ViteConfigPatcher::patch($src);

        $this->assertSame(
            "import { defineConfig } from 'vite';\nimport laravel from 'laravel-vite-plugin';\nimport vue from '@vitejs/plugin-vue';\n".ViteConfigPatcher::IMPORT."\n\nexport default defineConfig({\n    plugins: [\n        a11y(),\n        laravel({ input: ['resources/js/app.js'] }),\n        vue(),\n    ],\n});\n",
            $out,
        );
        $this->assertSame($out, ViteConfigPatcher::patch($out), 'idempotent');
    }

    public function test_multiline_imports_and_no_semicolons(): void
    {
        $src = "import {\n  defineConfig,\n  loadEnv\n} from \"vite\"\nimport react from \"@vitejs/plugin-react\"\nexport default defineConfig({ plugins: [react()] })\n";
        $out = ViteConfigPatcher::patch($src);

        $this->assertStringContainsString("import react from \"@vitejs/plugin-react\"\n".ViteConfigPatcher::IMPORT."\nexport default", $out);
        $this->assertStringContainsString("plugins: [\n        a11y(),react()]", $out);
        $this->assertStringStartsWith("import {\n  defineConfig,\n  loadEnv\n} from \"vite\"\n", $out);
    }

    public function test_refuses_when_unsure(): void
    {
        $this->assertSame(null, ViteConfigPatcher::patch("export default { build: {} }\n"));
        $this->assertSame(null, ViteConfigPatcher::patch("const a = { plugins: [] };\nconst b = { plugins: [] };\n"));
    }
}
