<?php
/**
 * Projects - the methodology presets, the palette and the icon set (3.2.0).
 *
 * 🔑 A METHODOLOGY IS A LENS, NOT A SCHEMA. Every project is built from the same
 * pieces - tasks, time boxes (project_stages), and later the RAID log - and a
 * preset only says what the time boxes are called, how they behave and which
 * tools are switched on. That is why switching method halfway through is safe:
 * nothing is converted, nothing is deleted. Closed time boxes stay exactly as
 * they were; the open ones are relabelled. See docs/design/projects.md §3.
 *
 * Defined in code, not in the database, for the same reason the Warbot tool
 * registry is: the behaviour behind each key is code, so the key list must be.
 *
 * ⚠️ PRINCE2 is a registered trademark. "Staged" is described in our own words as
 * a PRINCE2-STYLE approach. Never copy text from the manual into this module.
 */

/**
 * The presets. 'timebox' is the project_stages.kind used for new and open time
 * boxes; 'single_active' allows only one active time box at a time.
 *
 * @return array<string,array{label_key:string,desc_key:string,timebox:string,single_active:bool}>
 */
function projectMethodologies(): array
{
    return [
        'simple' => ['label_key' => 'projects.method.simple', 'desc_key' => 'projects.method.simple_desc', 'timebox' => 'phase',  'single_active' => false],
        'staged' => ['label_key' => 'projects.method.staged', 'desc_key' => 'projects.method.staged_desc', 'timebox' => 'stage',  'single_active' => true],
        'agile'  => ['label_key' => 'projects.method.agile',  'desc_key' => 'projects.method.agile_desc',  'timebox' => 'sprint', 'single_active' => true],
    ];
}

function projectStatuses(): array
{
    return ['proposed', 'active', 'on_hold', 'closed', 'cancelled'];
}

/** Statuses a project is finished in: no health, not counted as live. */
function projectFinishedStatuses(): array
{
    return ['closed', 'cancelled'];
}

function projectHealthValues(): array
{
    return ['auto', 'green', 'amber', 'red'];
}

function projectStageKinds(): array
{
    return ['phase', 'stage', 'sprint'];
}

function projectStageStatuses(): array
{
    return ['planned', 'active', 'closed'];
}

/**
 * The colour a project picks for its card: a fixed palette, never free CSS, so
 * a stored value can only ever be one of these keys.
 *
 * @return array<string,array{0:string,1:string}> key => [from, to]
 */
function projectColours(): array
{
    return [
        'coral'  => ['#f43f5e', '#e11d48'],
        'sunset' => ['#fb923c', '#e11d48'],
        'amber'  => ['#f59e0b', '#d97706'],
        'lime'   => ['#84cc16', '#4d7c0f'],
        'teal'   => ['#14b8a6', '#0f766e'],
        'sky'    => ['#38bdf8', '#0369a1'],
        'indigo' => ['#818cf8', '#4338ca'],
        'violet' => ['#a78bfa', '#7c3aed'],
        'pink'   => ['#f472b6', '#be185d'],
        'slate'  => ['#94a3b8', '#475569'],
    ];
}

/**
 * The icons a project can pick. Keys only - the SVG lives in assets/js/projects.js
 * so the stored value is never markup.
 */
function projectIcons(): array
{
    return ['rocket', 'laptop', 'building', 'mail', 'server', 'shield', 'network', 'cloud', 'users', 'flag', 'wrench', 'box', 'phone', 'database', 'star', 'heart'];
}
