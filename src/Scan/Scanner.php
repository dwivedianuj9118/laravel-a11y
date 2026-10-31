<?php

namespace DwivediAnuj\A11y\Scan;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs the Node scanner (Playwright + axe-core) and returns its decoded JSON.
 */
class Scanner
{
    public const STANDARDS = [
        'wcag2a' => ['wcag2a'],
        'wcag2aa' => ['wcag2a', 'wcag2aa'],
        'wcag21aa' => ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        'wcag22aa' => ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'],
    ];

    public static function tags(string $standard, bool $bestPractice): array
    {
        $tags = self::STANDARDS[$standard] ?? throw new RuntimeException(
            "Unknown standard [{$standard}]. Use one of: ".implode(', ', array_keys(self::STANDARDS))
        );

        return $bestPractice ? [...$tags, 'best-practice'] : $tags;
    }

    /**
     * @param  array<int, array{name: string, loginUrl: ?string, urls: array<int, string>}>  $sessions
     * @param  callable(string): void|null  $onProgress  receives one line per scanned page
     */
    public function run(array $sessions, array $tags, array $options, ?callable $onProgress = null): array
    {
        $dir = storage_path('framework/a11y');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $in = $dir.'/scan-input.json';
        $out = $dir.'/scan-output.json';
        @unlink($out);

        file_put_contents($in, json_encode([
            'sessions' => array_values($sessions),
            'tags' => $tags,
            'appRoot' => base_path(),
            'concurrency' => $options['concurrency'] ?? 3,
            'timeoutMs' => $options['timeout_ms'] ?? 30000,
            'waitMs' => $options['wait_ms'] ?? 0,
            'headers' => ['X-A11y-Scan' => '1'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $script = realpath(__DIR__.'/../../js/scanner/scan.mjs');
        $count = array_sum(array_map(fn ($s) => count($s['urls']) + 1, $sessions));
        $timeout = max(60, (int) ceil($count * (($options['timeout_ms'] ?? 30000) / 1000 + 10)));

        $result = Process::path(base_path())
            ->timeout($timeout)
            ->run([$options['node_binary'] ?? 'node', $script, $in, $out], function (string $type, string $output) use ($onProgress) {
                if ($type === 'err' && $onProgress) {
                    foreach (array_filter(explode("\n", $output)) as $line) {
                        $onProgress($line);
                    }
                }
            });

        if (! $result->successful() || ! is_file($out)) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'Scanner failed with no output.');
        }

        return json_decode(file_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
    }
}
