<?php

namespace DwivediAnuj\A11y\Commands\Concerns;

use DwivediAnuj\A11y\Report\IssueGrouper;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** Terminal output shared by a11y:scan and a11y:lint. */
trait RendersIssues
{
    protected function renderIssues(array $issues, bool $hasBaseline = false, ?string $emptyMessage = null): void
    {
        $this->newLine();

        if ($issues === []) {
            $this->components->info($emptyMessage ?? 'No WCAG issues found by automated checks. Still test keyboard use, focus order and screen-reader output by hand.');

            return;
        }

        $colors = ['critical' => 'red', 'serious' => 'red', 'moderate' => 'yellow', 'minor' => 'gray'];

        foreach ($issues as $n => $issue) {
            $color = $colors[$issue['impact']] ?? 'white';
            $wcag = $issue['wcag'] ? ' WCAG '.implode(', ', $issue['wcag']) : '';
            $times = $issue['count'] > 1 ? " <fg=gray>×{$issue['count']}</>" : '';
            $pages = count($issue['pages']) > 1 ? ' <fg=gray>on '.count($issue['pages']).' pages</>' : '';

            $known = ! empty($issue['baseline']) ? ' <fg=gray>(in baseline)</>' : '';
            if ($only = IssueGrouper::variantNote($issue)) {
                $known .= " <fg=magenta>({$only})</>";
            }
            $this->line(sprintf('  <fg=%s;options=bold>%-8s</> <options=bold>%s</><fg=gray>%s</>%s', $color, strtoupper($issue['impact']), $issue['rule'], $wcag, $times.$pages.$known));
            $this->line('  '.$this->location($issue));
            foreach ($issue['also'] as $also) {
                $this->line('  <fg=gray>  ↳ '.OutputFormatter::escape($also).'</>');
            }
            $this->line('  <fg=gray>'.OutputFormatter::escape(mb_strimwidth(preg_replace('/\s+/', ' ', $issue['html']), 0, 110, '…')).'</>');
            $this->line('  <fg=green>Fix:</> '.OutputFormatter::escape($issue['fix']));
            if ($n < count($issues) - 1) {
                $this->newLine();
            }
        }

        $this->newLine();
        $byImpact = array_count_values(array_column($issues, 'impact'));
        $exact = count(array_filter($issues, fn ($i) => in_array($i['how'], ['marker', 'call-site', 'field', 'lint'], true)));
        $summary = collect(['critical', 'serious', 'moderate', 'minor'])
            ->filter(fn ($k) => isset($byImpact[$k]))
            ->map(fn ($k) => "{$byImpact[$k]} {$k}")
            ->implode(', ');

        $newCount = count(array_filter($issues, fn ($i) => empty($i['baseline'])));
        $this->line('  <options=bold>'.count($issues)." issue(s)</>: {$summary}  ·  {$exact}/".count($issues).' mapped to an exact line in your code'
            .($hasBaseline ? "  ·  <options=bold>{$newCount} new</> since baseline" : ''));
        $this->line('  <fg=gray>Automated checks find only part of WCAG. Also test keyboard use, focus order, alt-text quality and captions by hand.</>');
    }

    protected function location(array $issue): string
    {
        if ($issue['file'] === null) {
            return '<fg=yellow>source not found</> <fg=gray>(no marker on this element or its parents) · '.OutputFormatter::escape($issue['target']).'</>';
        }

        $label = $issue['line'] !== null ? "{$issue['file']}:{$issue['line']}" : $issue['file'];
        $link = $this->editorLink($issue['file'], $issue['line'] ?? 1);
        $text = $link ? "<href={$link}>".OutputFormatter::escape($label).'</>' : OutputFormatter::escape($label);
        $note = $issue['note'] ? ' <fg=gray>('.OutputFormatter::escape($issue['note']).')</>' : '';

        return match ($issue['how']) {
            'marker', 'call-site', 'field', 'lint' => "<fg=cyan>{$text}</>{$note}",
            'page' => "<fg=yellow>in</> {$text}{$note} <fg=gray>(exact line arrives with the Vite plugin)</>",
            'vendor' => "<fg=yellow>vendor</> {$text}{$note}",
            default => "<fg=yellow>near</> {$text}{$note} <fg=gray>(nearest marked parent; the element itself was added by JavaScript or PHP)</>",
        };
    }

    protected function editorLink(string $file, int $line): ?string
    {
        $abs = str_starts_with($file, '/') ? $file : base_path($file);

        return match (config('a11y.editor')) {
            'vscode' => "vscode://file/{$abs}:{$line}",
            'cursor' => "cursor://file/{$abs}:{$line}",
            'phpstorm' => "phpstorm://open?file={$abs}&line={$line}",
            default => null,
        };
    }
}
