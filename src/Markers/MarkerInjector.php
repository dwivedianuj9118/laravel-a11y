<?php

namespace DwivediAnuj\A11y\Markers;

/**
 * Stamps data-a11y-src="file:line" onto every HTML opening tag in a Blade template.
 *
 * Works on the raw template (before Blade compiles it), so line numbers match the
 * file on disk. It is deliberately conservative: anything it does not understand is
 * copied through untouched. Skipped regions:
 *   {{-- comments --}}, {{ echoes }}, {!! raw echoes !!}, @{{ escaped }},
 *   @php ... @endphp, @verbatim ... @endverbatim, @directive(...) arguments,
 *   <!-- html comments -->, <script>/<style> bodies, <?php ?> blocks.
 * Component tags (<x-foo>, <flux:foo>) get data-a11y-use="file:line" instead: components that
 * forward $attributes carry it onto their root element, so an issue inside a vendor component
 * (e.g. <x-filament::icon-button>) can be traced back to the line in your view that used it.
 * <livewire:...> and <x-slot> tags are never stamped.
 */
final class MarkerInjector
{
    public const ATTRIBUTE = 'data-a11y-src';

    /** Stamped on component tags (<x-foo>, <flux:bar>): where the component is used. */
    public const USE_ATTRIBUTE = 'data-a11y-use';

    /** Elements that are never shown to users or that axe never reports on. */
    private const SKIP_TAGS = [
        'html', 'head', 'meta', 'link', 'title', 'base', 'script', 'style', 'noscript', 'template', 'slot',
    ];

    /** Elements whose body is raw text, not markup. */
    private const RAW_TEXT_TAGS = ['script', 'style'];

    private string $src;

    private int $len;

    private int $line;

    private string $out;

    public function inject(string $source, string $file): string
    {
        $this->src = $source;
        $this->len = strlen($source);
        $this->line = 1;
        $this->out = '';
        $this->cursor = 0;
        $escaped = htmlspecialchars($file, ENT_QUOTES);
        $attr = ' '.self::ATTRIBUTE.'="'.$escaped.':';
        $this->useAttr = ' '.self::USE_ATTRIBUTE.'="'.$escaped.':';

        $i = 0;
        while ($i < $this->len) {
            $c = $this->src[$i];

            if ($c === '{') {
                if ($this->startsWith($i, '{{--')) {
                    $i = $this->copyUntil($i, '--}}');

                    continue;
                }
                if ($this->startsWith($i, '{!!')) {
                    $i = $this->copyUntil($i, '!!}');

                    continue;
                }
                if ($this->startsWith($i, '{{')) {
                    $i = $this->copyUntil($i, '}}');

                    continue;
                }
            }

            if ($c === '@') {
                if ($this->startsWith($i, '@@') || $this->startsWith($i, '@{{')) {
                    $i = $this->copyTo($i + ($this->startsWith($i, '@{{') ? 3 : 2));

                    continue;
                }
                $next = $this->skipAtSign($i);
                if ($next !== null) {
                    $i = $this->copyTo($next);

                    continue;
                }
            }

            if ($c === '<') {
                if ($this->startsWith($i, '<!--')) {
                    $i = $this->copyUntil($i, '-->');

                    continue;
                }
                if ($this->startsWith($i, '<?php') || $this->startsWith($i, '<?=')) {
                    $i = $this->copyUntil($i, '?>');

                    continue;
                }
                $next = $this->handleTag($i, $attr);
                if ($next !== null) {
                    $i = $next;

                    continue;
                }
            }

            $this->emit($c);
            $i++;
        }

        return $this->out;
    }

    /**
     * Opening tag at $i: copy it (stamped if it is a plain HTML element) and return the
     * index after it. Returns null if this "<" is not a tag.
     */
    private function handleTag(int $i, string $attr): ?int
    {
        if (! preg_match('/\G<([A-Za-z][A-Za-z0-9\-_.:]*)/', $this->src, $m, 0, $i)) {
            return null;
        }

        $name = $m[1];
        $nameEnd = $i + strlen($m[0]);
        $end = $this->findTagEnd($nameEnd);
        if ($end === null) {
            return null;
        }

        $lower = strtolower($name);
        $tagText = substr($this->src, $i, $end - $i);
        $isComponent = str_contains($name, ':') || str_starts_with($lower, 'x-');
        $insert = null;

        if (! $isComponent) {
            if (! in_array($lower, self::SKIP_TAGS, true) && ! str_contains($tagText, self::ATTRIBUTE)) {
                $insert = $attr.$this->line.'"';
            }
        } elseif ($this->stampComponents
            && ! str_starts_with($lower, 'livewire:')
            && ! preg_match('/^x-slot(\b|:)/', $lower)
            && ! str_contains($tagText, self::USE_ATTRIBUTE)) {
            $insert = $this->useAttr.$this->line.'"';
        }

        if ($this->collected !== null) {
            $this->collected[] = ['name' => $name, 'text' => $tagText, 'line' => $this->line, 'start' => $i, 'end' => $end];
        }

        $this->copyTo($nameEnd, $i);
        if ($insert !== null) {
            $this->out .= $insert;
        }
        $this->copyTo($end, $nameEnd);

        if (in_array($lower, self::RAW_TEXT_TAGS, true) && ! str_ends_with(rtrim($tagText), '/>')) {
            $close = stripos($this->src, '</'.$lower, $end);

            return $this->copyTo($close === false ? $this->len : $close, $end);
        }

        return $end;
    }

    /** Index just after the ">" that closes a tag whose attributes start at $i. */
    private function findTagEnd(int $i): ?int
    {
        while ($i < $this->len) {
            $c = $this->src[$i];

            if ($c === '>') {
                return $i + 1;
            }
            if ($c === '<' && ! $this->startsWith($i, '<?')) {
                return null; // a new tag started before this one closed: not a real tag
            }
            if ($c === '"' || $c === "'") {
                $i = $this->skipQuoted($i, $c);

                continue;
            }
            if ($this->startsWith($i, '{{--')) {
                $i = $this->indexAfter($i, '--}}');

                continue;
            }
            if ($this->startsWith($i, '{!!')) {
                $i = $this->indexAfter($i, '!!}');

                continue;
            }
            if ($this->startsWith($i, '{{')) {
                $i = $this->indexAfter($i, '}}');

                continue;
            }
            if ($this->startsWith($i, '<?')) {
                $i = $this->indexAfter($i, '?>');

                continue;
            }
            if ($c === '@') {
                $next = $this->skipDirectiveArgs($i);
                if ($next !== null) {
                    $i = $next;

                    continue;
                }
            }
            $i++;
        }

        return null;
    }

    /** Skip a quoted attribute value, honouring Blade echoes inside it. */
    private function skipQuoted(int $i, string $quote): int
    {
        $i++;
        while ($i < $this->len) {
            if ($this->startsWith($i, '{{')) {
                $i = $this->indexAfter($i, $this->startsWith($i, '{{--') ? '--}}' : '}}');

                continue;
            }
            if ($this->startsWith($i, '{!!')) {
                $i = $this->indexAfter($i, '!!}');

                continue;
            }
            if ($this->src[$i] === $quote) {
                return $i + 1;
            }
            $i++;
        }

        return $this->len;
    }

    /**
     * In text: @php...@endphp, @verbatim...@endverbatim, or @directive(args).
     * Returns the index after the region, or null if this is not a directive with a body/args.
     */
    private function skipAtSign(int $i): ?int
    {
        if ($i > 0 && preg_match('/[\w.\-]/', $this->src[$i - 1])) {
            return null; // e-mail address or similar
        }
        if (preg_match('/\G@verbatim\b/', $this->src, $m, 0, $i)) {
            return $this->indexAfter($i, '@endverbatim');
        }
        if (preg_match('/\G@php\b(?!\s*\()/', $this->src, $m, 0, $i)) {
            return $this->indexAfter($i, '@endphp');
        }

        return $this->skipDirectiveArgs($i);
    }

    /** @name( ... ) with balanced parentheses; null if no parenthesised arguments follow. */
    private function skipDirectiveArgs(int $i): ?int
    {
        if (! preg_match('/\G@[A-Za-z_][\w:]*[ \t]*\(/', $this->src, $m, 0, $i)) {
            return null;
        }

        $j = $i + strlen($m[0]);
        $depth = 1;
        while ($j < $this->len && $depth > 0) {
            $c = $this->src[$j];
            if ($c === '"' || $c === "'") {
                $j = $this->skipPhpString($j, $c);

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            $j++;
        }

        return $j;
    }

    private function skipPhpString(int $i, string $quote): int
    {
        $i++;
        while ($i < $this->len) {
            if ($this->src[$i] === '\\') {
                $i += 2;

                continue;
            }
            if ($this->src[$i] === $quote) {
                return $i + 1;
            }
            $i++;
        }

        return $this->len;
    }

    private function startsWith(int $i, string $needle): bool
    {
        return substr_compare($this->src, $needle, $i, strlen($needle)) === 0;
    }

    private function indexAfter(int $i, string $needle): int
    {
        $pos = strpos($this->src, $needle, $i + 1);

        return $pos === false ? $this->len : $pos + strlen($needle);
    }

    private function copyUntil(int $i, string $needle): int
    {
        return $this->copyTo($this->indexAfter($i, $needle), $i);
    }

    /** Copy source from $from (default: current end of output) up to $to, tracking lines. */
    private function copyTo(int $to, ?int $from = null): int
    {
        $from ??= $this->consumed();
        $chunk = substr($this->src, $from, $to - $from);
        $this->out .= $chunk;
        $this->line += substr_count($chunk, "\n");
        $this->cursor = $to;

        return $to;
    }

    private function emit(string $c): void
    {
        $this->out .= $c;
        if ($c === "\n") {
            $this->line++;
        }
        $this->cursor++;
    }

    private int $cursor = 0;

    private string $useAttr = '';

    /** @var array<int, array{name: string, text: string, line: int, start: int, end: int}>|null */
    private ?array $collected = null;

    /**
     * Every opening tag in a Blade template (same rules as inject(): echoes, @php, comments,
     * directives and script/style bodies are skipped), for static checks.
     *
     * @return array<int, array{name: string, text: string, line: int, start: int, end: int}>
     */
    public function tags(string $source): array
    {
        $this->collected = [];
        try {
            $this->inject($source, '');

            return $this->collected;
        } finally {
            $this->collected = null;
        }
    }

    public function __construct(private bool $stampComponents = true) {}

    private function consumed(): int
    {
        return $this->cursor;
    }
}
