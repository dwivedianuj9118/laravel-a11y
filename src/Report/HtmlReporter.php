<?php

namespace DwivediAnuj\A11y\Report;

/**
 * One self-contained HTML file (no external assets) to open locally or attach to a ticket.
 * The report itself is built to pass the checks it reports on.
 */
final class HtmlReporter
{
    public static function render(array $report, ?callable $editorLink = null): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $issues = $report['issues'];
        $counts = array_count_values(array_column($issues, 'impact'));
        $new = count(array_filter($issues, fn ($i) => empty($i['baseline'])));
        $exact = count(array_filter($issues, fn ($i) => in_array($i['how'], ['marker', 'call-site', 'field'], true)));
        $pages = $report['pages'];
        $hasBaseline = ($report['baseline_count'] ?? 0) > 0;

        $impactRow = '';
        foreach (['critical', 'serious', 'moderate', 'minor'] as $level) {
            $impactRow .= '<li class="stat '.$level.'"><span class="n">'.($counts[$level] ?? 0).'</span> '.$level.'</li>';
        }

        $filters = '';
        foreach (['critical', 'serious', 'moderate', 'minor'] as $level) {
            $filters .= '<label><input type="checkbox" name="impact" value="'.$level.'" checked> '.ucfirst($level).'</label> ';
        }
        if ($hasBaseline) {
            $filters .= '<label><input type="checkbox" id="only-new"> Only new (not in baseline)</label>';
        }

        $cards = '';
        foreach ($issues as $n => $i) {
            $where = $i['file'] === null
                ? '<span class="muted">Source not found</span> <code>'.$e($i['target']).'</code>'
                : self::location($i, $editorLink, $e);
            $also = '';
            foreach ($i['also'] ?? [] as $a) {
                $also .= '<li>'.$e($a).'</li>';
            }
            $pageList = '';
            foreach ($i['pages'] as $p) {
                $pageList .= '<li><a href="'.$e($p).'">'.$e($p).'</a></li>';
            }
            $wcag = $i['wcag'] ? 'WCAG '.$e(implode(', ', $i['wcag'])) : 'Best practice';
            $badgeNew = $hasBaseline ? (empty($i['baseline']) ? '<span class="tag new">new</span>' : '<span class="tag">in baseline</span>') : '';
            $times = $i['count'] > 1 ? ' <span class="muted">×'.$i['count'].'</span>' : '';
            $only = IssueGrouper::variantNote($i);
            $times .= $only ? ' <span class="tag">'.$e($only).'</span>' : '';

            $cards .= '<article class="issue" data-impact="'.$e($i['impact']).'" data-new="'.(empty($i['baseline']) ? '1' : '0').'" aria-labelledby="i'.$n.'">'
                .'<h3 id="i'.$n.'"><span class="badge '.$e($i['impact']).'">'.$e($i['impact']).'</span> '.$e($i['help']).$times.' '.$badgeNew.'</h3>'
                .'<p class="meta"><a href="'.$e($i['helpUrl']).'">'.$e($i['rule']).'</a> · '.$wcag.'</p>'
                .'<p class="where">'.$where.'</p>'
                .($also ? '<ul class="also">'.$also.'</ul>' : '')
                .'<pre><code>'.$e(mb_strimwidth(preg_replace('/\s+/', ' ', $i['html']), 0, 400, '…')).'</code></pre>'
                .'<p class="fix"><strong>Fix:</strong> '.$e($i['fix']).'</p>'
                .'<details><summary>Seen on '.count($i['pages']).' page(s)</summary><ul>'.$pageList.'</ul></details>'
                .'</article>';
        }

        if ($issues === []) {
            $cards = '<p class="ok">No issues found by automated checks.</p>';
        }

        $pageRows = '';
        foreach ($pages as $p) {
            $state = $p['error'] ? '<span class="bad">'.$e($p['error']).'</span>' : 'HTTP '.$e($p['status'] ?? '?');
            if (! empty($p['phpOutput'])) {
                $state .= '<br><span class="bad">PHP output in page: '.$e($p['phpOutput']['count'].'× '.$p['phpOutput']['first']).'</span>';
            }
            $pageRows .= '<tr><td><a href="'.$e($p['url']).'">'.$e($p['url']).'</a></td><td>'.$e($p['session'] ?? 'guest').'</td><td>'.$state.'</td></tr>';
        }

        $skipped = '';
        foreach ($report['skipped'] ?? [] as $s) {
            $skipped .= '<li><code>'.$e($s['uri']).'</code> — '.$e($s['reason']).'</li>';
        }

        $title = 'Accessibility report — '.$report['base_url'];
        $newText = $hasBaseline ? " · {$new} new since baseline" : '';
        $generated = $e($report['generated_at'] ?? date('Y-m-d H:i'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($title)}</title>
<style>
:root{--fg:#1d1f23;--muted:#555b66;--bg:#fff;--card:#f6f7f9;--line:#d9dce1;--crit:#a3121a;--ser:#b4380b;--mod:#7a5a00;--min:#4b5563;--ok:#146c2e;--link:#0b57d0}
@media (prefers-color-scheme:dark){:root{--fg:#e8eaed;--muted:#b0b6bf;--bg:#16181c;--card:#1f2228;--line:#3a3f47;--crit:#ff8a8f;--ser:#ffab7a;--mod:#f2cc60;--min:#c3c9d2;--ok:#7ddc95;--link:#8ab4f8}}
*{box-sizing:border-box}body{margin:0;font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--fg);background:var(--bg)}
header,main,footer{max-width:1000px;margin:0 auto;padding:16px 24px}a{color:var(--link)}code,pre{font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace}
h1{font-size:24px;margin:8px 0 4px}h2{font-size:19px;margin:28px 0 10px;border-bottom:1px solid var(--line);padding-bottom:6px}h3{font-size:16px;margin:0 0 4px}
.muted,.meta{color:var(--muted)}.stats{display:flex;gap:12px;flex-wrap:wrap;list-style:none;padding:0;margin:12px 0}
.stat{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:8px 14px;text-transform:capitalize}.stat .n{font-weight:700;font-size:20px;margin-right:4px}
.stat.critical .n{color:var(--crit)}.stat.serious .n{color:var(--ser)}.stat.moderate .n{color:var(--mod)}.stat.minor .n{color:var(--min)}
fieldset{border:1px solid var(--line);border-radius:8px;padding:8px 14px}fieldset label{margin-right:14px;white-space:nowrap}
.issue{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:12px 0}
.badge{display:inline-block;font-size:12px;font-weight:700;text-transform:uppercase;border-radius:4px;padding:1px 6px;border:1.5px solid currentColor;vertical-align:1px}
.badge.critical{color:var(--crit)}.badge.serious{color:var(--ser)}.badge.moderate{color:var(--mod)}.badge.minor{color:var(--min)}
.tag{font-size:12px;border:1px solid var(--line);border-radius:4px;padding:0 6px;color:var(--muted)}.tag.new{color:var(--crit);border-color:currentColor}
.where{margin:6px 0;font-weight:600}.where .how{font-weight:400;color:var(--muted)}pre{background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:8px 10px;white-space:pre-wrap;word-break:break-all;margin:8px 0}
.also{margin:0 0 6px;color:var(--muted)}.fix{margin:6px 0}.ok{color:var(--ok);font-weight:600}.bad{color:var(--crit)}
table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border-bottom:1px solid var(--line);padding:6px 8px;text-align:left;vertical-align:top}
.where,.also,.issue h3,td,li,a{overflow-wrap:anywhere}th:nth-child(2),td:nth-child(2){width:22%}
[hidden]{display:none!important}
</style>
</head>
<body>
<header>
<h1>Accessibility report</h1>
<p class="muted">{$e($report['base_url'])} · {$e($report['standard'])} · axe-core {$e($report['axe'] ?? '?')} · generated {$generated}</p>
<ul class="stats">{$impactRow}</ul>
<p><strong>{$e(count($issues))} issue(s)</strong>, {$e($exact)} mapped to an exact line in your code · {$e(count($pages))} page(s) scanned{$e($newText)}</p>
</header>
<main>
<section aria-labelledby="h-issues">
<h2 id="h-issues">Issues</h2>
<fieldset><legend>Show</legend>{$filters}</fieldset>
<p id="shown" class="muted" aria-live="polite"></p>
{$cards}
</section>
<section aria-labelledby="h-pages">
<h2 id="h-pages">Pages scanned</h2>
<table><thead><tr><th scope="col">URL</th><th scope="col">As</th><th scope="col">Result</th></tr></thead><tbody>{$pageRows}</tbody></table>
</section>
<section aria-labelledby="h-skipped">
<h2 id="h-skipped">Skipped</h2>
<ul>{$skipped}</ul>
</section>
<section aria-labelledby="h-manual">
<h2 id="h-manual">Check by hand</h2>
<p>Automated tools find only part of WCAG. On each key page, also check:</p>
<ul>
<li>Everything works with the keyboard alone (Tab, Shift+Tab, Enter, Space, Esc), and focus is always visible.</li>
<li>Focus order follows the visual order; modals trap focus and return it when closed.</li>
<li>Alt text describes what the image means in context, not just that it exists.</li>
<li>Form errors are announced and say how to fix them.</li>
<li>Pages still work at 200% zoom and 320&nbsp;px width; no content is lost.</li>
<li>Videos have captions; audio has a transcript; nothing flashes more than 3 times a second.</li>
</ul>
</section>
</main>
<footer><p class="muted">Generated by laravel-a11y.</p></footer>
<script>
(function () {
  var boxes = document.querySelectorAll('input[name="impact"]'), onlyNew = document.getElementById('only-new'), shown = document.getElementById('shown');
  function apply() {
    var on = {}, n = 0, cards = document.querySelectorAll('.issue');
    boxes.forEach(function (b) { on[b.value] = b.checked; });
    cards.forEach(function (c) {
      var show = on[c.dataset.impact] && (!onlyNew || !onlyNew.checked || c.dataset.new === '1');
      c.hidden = !show; if (show) n++;
    });
    shown.textContent = 'Showing ' + n + ' of ' + cards.length + ' issues.';
  }
  boxes.forEach(function (b) { b.addEventListener('change', apply); });
  if (onlyNew) onlyNew.addEventListener('change', apply);
  apply();
})();
</script>
</body>
</html>
HTML;
    }

    private static function location(array $i, ?callable $editorLink, callable $e): string
    {
        $label = $i['line'] !== null ? "{$i['file']}:{$i['line']}" : $i['file'];
        $href = $editorLink ? $editorLink($i['file'], $i['line'] ?? 1) : null;
        $text = $href ? '<a href="'.$e($href).'">'.$e($label).'</a>' : '<code>'.$e($label).'</code>';
        $how = [
            'marker' => '',
            'call-site' => 'where the component is used',
            'field' => $i['note'] ?? 'Filament field',
            'page' => 'Inertia page (no exact line)',
            'vendor' => 'inside a vendor view',
            'ancestor' => 'near: nearest marked parent',
        ][$i['how']] ?? '';

        return $text.($how !== '' ? ' <span class="how">('.$e($how).')</span>' : '');
    }
}
