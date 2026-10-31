<?php

namespace DwivediAnuj\A11y\Report;

use DwivediAnuj\A11y\Resolve\SourceResolver;

/**
 * Turns per-page axe results into issues grouped by source location + rule,
 * so one broken component used on 40 pages is one issue, not 40.
 */
final class IssueGrouper
{
    public const IMPACT_ORDER = ['minor' => 1, 'moderate' => 2, 'serious' => 3, 'critical' => 4];

    /**
     * @return array<int, array{rule: string, impact: string, wcag: array, help: string, helpUrl: string,
     *     fix: string, file: ?string, line: ?int, how: string, count: int, pages: array, html: string, target: string}>
     */
    public static function group(array $scan, ?SourceResolver $resolver = null): array
    {
        $resolver ??= new SourceResolver;
        $issues = [];

        foreach ($scan['pages'] ?? [] as $page) {
            foreach ($page['violations'] ?? [] as $violation) {
                foreach ($violation['nodes'] as $node) {
                    $loc = $resolver->resolve($node);
                    [$file, $line] = [$loc['file'], $loc['line']];
                    $target = self::targetString($node['target'] ?? []);

                    // Unmapped nodes are grouped by selector instead of file:line.
                    $key = $violation['id'].'|'.($file !== null ? "{$file}:{$line}" : 'sel:'.$target);

                    $issues[$key] ??= [
                        'rule' => $violation['id'],
                        'impact' => $violation['impact'] ?? 'minor',
                        'wcag' => self::criteria($violation['tags'] ?? []),
                        'help' => $violation['help'],
                        'helpUrl' => $violation['helpUrl'],
                        'fix' => FixHints::for($violation['id'], $violation['help']),
                        'file' => $file,
                        'line' => $line,
                        'how' => $loc['how'],
                        'note' => $loc['note'],
                        'also' => [],
                        'count' => 0,
                        'pages' => [],
                        'sessions' => [],
                        'html' => $node['html'] ?? '',
                        'target' => $target,
                    ];

                    $issues[$key]['count']++;
                    $issues[$key]['pages'][$page['finalUrl'] ?? $page['url']] = true;
                    $issues[$key]['sessions'][$page['session'] ?? 'guest'] = true;
                    foreach ($loc['also'] as $also) {
                        $issues[$key]['also'][$also] = true;
                    }
                }
            }
        }

        $issues = array_map(function (array $i) {
            $i['pages'] = array_keys($i['pages']);
            $i['sessions'] = array_keys($i['sessions']);
            $i['also'] = array_keys($i['also']);
            $i['fix'] = FixHints::forLocation($i['rule'], $i['how'], $i['note'] ?? '', $i['fix']);

            return $i;
        }, array_values($issues));

        usort($issues, fn ($a, $b) => [self::IMPACT_ORDER[$b['impact']] ?? 0, $a['file'] ?? '~', $a['line'] ?? 0]
            <=> [self::IMPACT_ORDER[$a['impact']] ?? 0, $b['file'] ?? '~', $b['line'] ?? 0]);

        return $issues;
    }

    /** "resources/views/x.blade.php:12" → ["resources/views/x.blade.php", 12] */
    public static function splitSrc(?string $src): array
    {
        if (! $src || ! preg_match('/^(.*):(\d+)$/', $src, $m)) {
            return [null, null];
        }

        return [$m[1], (int) $m[2]];
    }

    /** axe tags like "wcag111" / "wcag1410" → ["1.1.1"] / ["1.4.10"] */
    public static function criteria(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            if (preg_match('/^wcag(\d)(\d)(\d{1,2})$/', $tag, $m)) {
                $out[] = "{$m[1]}.{$m[2]}.{$m[3]}";
            }
        }

        return array_values(array_unique($out));
    }

    private static function targetString(array $target): string
    {
        return implode(' >>> ', array_map(fn ($t) => is_array($t) ? implode(' > ', $t) : $t, $target));
    }

    public static function meetsThreshold(string $impact, string $failOn): bool
    {
        return (self::IMPACT_ORDER[$impact] ?? 0) >= (self::IMPACT_ORDER[$failOn] ?? 99);
    }
}
