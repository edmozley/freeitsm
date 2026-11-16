<?php
/**
 * Each module's accent comes from its colour in System -> Module colours (GH #302,
 * discussion #363).
 *
 * The UI style guide always said so - "each module's accent comes from
 * includes/module-colors.php through --accent" - but nothing did it. Every page's
 * --accent was the Tickets blue from theme.css, and the ~23 modules with their own
 * accent set (--cal-accent, --kb-accent ...) carried a hand-typed copy of their
 * default colour. So a module whose colour an administrator changed got a new
 * header and kept its old buttons, and a module with no set of its own (Assets,
 * People ...) had blue buttons in a green module.
 *
 * moduleAccentCss() returns the one <style> body that fixes both, printed by
 * renderWaffleMenuPanel() for the module the page belongs to:
 *
 *   - Colour left at its default, module HAS its own set: --accent points at that
 *     set (var(--cal-accent) ...), so the hand-tuned light AND dark shades stay
 *     exactly as they are.
 *   - Colour left at its default, no set of its own: --accent becomes the module
 *     colour, with its pale and dark-mode shades worked out from it.
 *   - Colour CHANGED in Module colours: --accent and the module's own set are both
 *     worked out from the chosen colour, light and dark, so everything follows.
 *   - Tickets at its default: nothing - theme.css's --accent already is Tickets.
 *
 * Worked-out shades must stay readable, because --accent is also used as TEXT
 * (links, the active tab): a colour too pale for that on white is deepened until
 * it reaches 4.5:1 (WCAG AA), and in dark mode lifted until it does against the
 * dark surface. A colour that already reads well is used exactly as chosen. The
 * text ON a filled button is white or near-black, whichever reads.
 *
 * TRAP: a colour reaches CSS only as #rrggbb (checked here) - anything else is
 * dropped, never printed, because it came from a settings row.
 *
 * @wiki UI-Style-Guide
 */

/** Module key => the prefix of its own accent set in assets/css/theme.css. */
const MODULE_ACCENT_PREFIX = [
    'knowledge' => 'kb', 'changes' => 'cm', 'problems' => 'pm', 'calendar' => 'cal',
    'morning-checks' => 'mc', 'reporting' => 'rep', 'software' => 'sw', 'forms' => 'forms',
    'cmdb' => 'cmdb', 'tasks' => 'tsk', 'projects' => 'prj', 'starters' => 'jml',
    'contracts' => 'con', 'domains' => 'dom', 'files' => 'fil', 'service-status' => 'ss',
    'network-mapper' => 'nm', 'workflow' => 'wf', 'process-mapper' => 'pmap', 'wiki' => 'wiki',
    'lms' => 'lms', 'watchtower' => 'wt', 'system' => 'sys',
];

/** The light and dark surfaces in theme.css that accent text must read against. */
const MODULE_ACCENT_LIGHT_SURFACE = '#ffffff';
const MODULE_ACCENT_DARK_SURFACE  = '#1e2228';

/**
 * The CSS for one module's accent, or '' when there is nothing to change.
 *
 * @param string $module     the module key (as in module-colors.php)
 * @param array  $colours    [main, hover] from getModuleColors()
 * @param array  $defaults   [main, hover] the module's default ($defaultModuleColors)
 */
function moduleAccentCss(string $module, array $colours, array $defaults): string
{
    $hex = '/^#[0-9a-fA-F]{6}$/';
    [$c0, $c1] = [(string)($colours[0] ?? ''), (string)($colours[1] ?? '')];
    if (!preg_match($hex, $c0)) return '';
    if (!preg_match($hex, $c1)) $c1 = $c0;
    $customised = strcasecmp($c0, (string)($defaults[0] ?? '')) !== 0 || strcasecmp($c1, (string)($defaults[1] ?? '')) !== 0;
    $prefix = MODULE_ACCENT_PREFIX[$module] ?? null;

    if (!$customised && $module === 'tickets') return '';
    if (!$customised && $prefix) {
        // Point at the module's own hand-tuned set (light and dark both live in theme.css).
        return ":root{--accent:var(--{$prefix}-accent,$c0);--accent-hover:var(--{$prefix}-accent-hover,$c1);"
             . "--accent-soft:var(--{$prefix}-accent-soft,var(--accent-soft));--on-accent:var(--{$prefix}-on-accent,#fff)}";
    }

    $light = moduleAccentShades($c0, $c1, MODULE_ACCENT_LIGHT_SURFACE, '#000000', 0.12);
    $dark  = moduleAccentShades($c0, null, MODULE_ACCENT_DARK_SURFACE, '#ffffff', 0.25);
    $decl = fn(array $s) => "--accent:{$s['accent']};--accent-hover:{$s['hover']};--accent-soft:{$s['soft']};--on-accent:{$s['on']}";
    $alias = $prefix
        ? ";--{$prefix}-accent:var(--accent);--{$prefix}-accent-hover:var(--accent-hover);--{$prefix}-accent-soft:var(--accent-soft);--{$prefix}-on-accent:var(--on-accent)"
        : '';
    return ':root{' . $decl($light) . $alias . '}'
         . ':root[data-theme-mode="dark"]{' . $decl($dark) . $alias . '}';
}

/**
 * The four shades for one surface: the accent (moved towards $towards until it
 * reads at 4.5:1 on $surface), its hover, a pale tint of it on $surface, and the
 * text colour on it.
 *
 * @return array{accent: string, hover: string, soft: string, on: string}
 */
function moduleAccentShades(string $c0, ?string $c1, string $surface, string $towards, float $softShare): array
{
    $accent = $c0;
    for ($i = 1; $i <= 20 && moduleAccentContrast($accent, $surface) < 4.5; $i++) {
        $accent = moduleAccentMix($c0, $towards, $i * 0.05);
    }
    $hover = ($c1 !== null && strcasecmp($accent, $c0) === 0)
        ? $c1                                            // the chosen hover, when the colour needed no help
        : moduleAccentMix($accent, $towards, 0.15);      // otherwise a step further the same way
    $soft = moduleAccentMix($surface, $accent, $softShare);
    $on = moduleAccentContrast($accent, '#ffffff') >= moduleAccentContrast($accent, '#1a1a1a') ? '#fff' : '#1a1a1a';
    return ['accent' => $accent, 'hover' => $hover, 'soft' => $soft, 'on' => $on];
}

/** $a moved a share $t (0..1) of the way to $b, as #rrggbb. */
function moduleAccentMix(string $a, string $b, float $t): string
{
    $out = '#';
    for ($i = 1; $i <= 5; $i += 2) {
        $x = hexdec(substr($a, $i, 2)); $y = hexdec(substr($b, $i, 2));
        $out .= sprintf('%02x', (int)round($x + ($y - $x) * $t));
    }
    return $out;
}

/** WCAG contrast ratio between two #rrggbb colours (1 to 21). */
function moduleAccentContrast(string $a, string $b): float
{
    $lum = function (string $h): float {
        $lin = function (int $v): float { $c = $v / 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * $lin(hexdec(substr($h, 1, 2))) + 0.7152 * $lin(hexdec(substr($h, 3, 2))) + 0.0722 * $lin(hexdec(substr($h, 5, 2)));
    };
    [$l1, $l2] = [$lum($a), $lum($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
