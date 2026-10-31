<?php

namespace DwivediAnuj\A11y\Report;

/**
 * Known issues you accept for now, so CI fails only on new ones (like a PHPStan baseline).
 *
 * Fingerprints use rule + file + the element's normalised HTML, not the line number, so
 * adding code above an old issue does not make it "new".
 */
final class Baseline
{
    public const VERSION = 1;

    public static function fingerprint(array $issue): string
    {
        $html = strtolower(preg_replace('/\s+/', ' ', trim($issue['html'] ?? '')));
        $html = preg_replace('/\s(wire:id|wire:snapshot|wire:effects|id|for|aria-labelledby|aria-describedby|aria-controls|x-ref)="[^"]*"/', '', $html);
        $where = $issue['file'] ?? ('selector:'.($issue['target'] ?? ''));

        return substr(hash('sha256', ($issue['rule'] ?? '').'|'.$where.'|'.$html), 0, 16);
    }

    /** @return array<string, array> fingerprint => entry */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        $out = [];
        foreach ($data['issues'] ?? [] as $entry) {
            if (isset($entry['fingerprint'])) {
                $out[$entry['fingerprint']] = $entry;
            }
        }

        return $out;
    }

    public static function save(string $path, array $issues): void
    {
        $entries = array_map(fn (array $i) => [
            'fingerprint' => self::fingerprint($i),
            'rule' => $i['rule'],
            'impact' => $i['impact'],
            'file' => $i['file'],
            'line' => $i['line'],
        ], $issues);

        usort($entries, fn ($a, $b) => [$a['file'] ?? '~', $a['line'] ?? 0, $a['rule']] <=> [$b['file'] ?? '~', $b['line'] ?? 0, $b['rule']]);

        file_put_contents($path, json_encode([
            'version' => self::VERSION,
            'note' => 'Known accessibility issues accepted for now. a11y:scan fails only on issues not listed here. Regenerate with: php artisan a11y:scan --baseline',
            'issues' => array_values(array_unique($entries, SORT_REGULAR)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** Adds 'fingerprint' and 'baseline' (bool) to every issue. */
    public static function mark(array $issues, array $baseline): array
    {
        return array_map(function (array $i) use ($baseline) {
            $i['fingerprint'] = self::fingerprint($i);
            $i['baseline'] = isset($baseline[$i['fingerprint']]);

            return $i;
        }, $issues);
    }
}
