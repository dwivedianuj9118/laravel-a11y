<?php

namespace DwivediAnuj\A11y\Report;

/**
 * SARIF 2.1.0 for GitHub code scanning: each issue shows up as an annotation on the exact
 * line in the pull request's "Files changed" view and in the Security tab.
 */
final class SarifReporter
{
    private const LEVELS = ['critical' => 'error', 'serious' => 'error', 'moderate' => 'warning', 'minor' => 'note'];

    public static function build(array $issues, string $version = 'dev'): array
    {
        $rules = [];
        $results = [];

        foreach ($issues as $issue) {
            if ($issue['file'] === null || str_starts_with($issue['file'], '/') || str_starts_with($issue['file'], 'vendor/')) {
                continue; // code scanning needs a file inside the repository
            }

            $rules[$issue['rule']] ??= [
                'id' => $issue['rule'],
                'name' => str_replace(' ', '', ucwords(str_replace('-', ' ', $issue['rule']))),
                'shortDescription' => ['text' => $issue['help']],
                'helpUri' => $issue['helpUrl'],
                'help' => ['text' => $issue['help'].' — '.$issue['helpUrl']],
                'properties' => [
                    'tags' => array_merge(['accessibility'], array_map(fn ($c) => "wcag-{$c}", $issue['wcag'])),
                ],
            ];

            $pages = implode(', ', array_slice($issue['pages'], 0, 5)).(count($issue['pages']) > 5 ? ', …' : '');
            $text = "{$issue['help']} ({$issue['impact']}".($issue['wcag'] ? ', WCAG '.implode(', ', $issue['wcag']) : '').").\n"
                ."Fix: {$issue['fix']}\n"
                ."Element: ".mb_strimwidth(preg_replace('/\s+/', ' ', $issue['html']), 0, 200, '…')."\n"
                ."Seen on: {$pages}";

            $results[] = [
                'ruleId' => $issue['rule'],
                'level' => self::LEVELS[$issue['impact']] ?? 'warning',
                'message' => ['text' => $text],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $issue['file'], 'uriBaseId' => '%SRCROOT%'],
                        'region' => ['startLine' => max(1, (int) ($issue['line'] ?? 1))],
                    ],
                ]],
                'partialFingerprints' => ['laravelA11y/v1' => $issue['fingerprint'] ?? Baseline::fingerprint($issue)],
                'baselineState' => ! empty($issue['baseline']) ? 'unchanged' : 'new',
            ];
        }

        return [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => ['driver' => [
                    'name' => 'laravel-a11y',
                    'informationUri' => 'https://github.com/dwivedianuj9118/laravel-a11y',
                    'version' => $version,
                    'rules' => array_values($rules),
                ]],
                'results' => $results,
            ]],
        ];
    }
}
