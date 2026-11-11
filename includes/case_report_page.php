<?php
/**
 * Lays out a case report (GH #204) as one printable page: the choices bar on screen, the report
 * underneath, and the browser's Print -> Save as PDF for the file - the same route as the change
 * and repeating-task evidence exports.
 *
 * Why a printed page and not Report Packs' PDF builder: that builder uses the PDF standard fonts,
 * which hold Western European Latin only, so anything else - a Polish name, a Hindi message -
 * would print as "?". An evidence copy must not change what was written, and the browser prints
 * every script the screen can show, with selectable text and long sections running on across pages.
 *
 * Reached from tickets/case-report.php and tasks/case-report.php, which check the module and
 * build the report with CaseReport (includes/case_report.php).
 *
 * TRAP: every stored text was written by a person - a customer, an analyst, a supplier. It goes
 * out through CaseReport::body() (the allow-list cleaner) or htmlspecialchars, never raw.
 *
 * @wiki Case-Report-Developer-Guide
 */

/**
 * Prints the whole page.
 *
 * @param ?array $r  the report from CaseReport, or null when the record was not found or not visible
 * @param bool $print  true when this load is the print (the export was recorded; open the dialog)
 * @param ?string $recordError  set when the export could not be written to the record's history
 */
function caseReportPage(?array $r, bool $print, ?string $recordError): void
{
    $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $T = fn(string $k, array $p = []) => htmlspecialchars(t('case-report.' . $k, $p), ENT_QUOTES, 'UTF-8');
    $when = fn($utc) => $utc ? $e(TaskEvidence::when((string)$utc)) : '-';
    $host = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = $host . (defined('BASE_URL') ? BASE_URL : '/');

    $title = $r ? t('case-report.page_title', ['ref' => $r['ref']]) : t('case-report.not_found_title');
    $sys = function_exists('systemName') ? systemName() : 'FreeITSM';
    $logo = function_exists('brandingLogoUrl') ? brandingLogoUrl() : '';
    $accent = ($r && $r['kind'] === 'task') ? '#7c3aed' : '#0078d4';
    /** A string made safe inside a CSS content: "..." value. */
    $cssStr = fn(string $s) => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', ' ', ' '], $s) . '"';
    ?><!doctype html>
<html lang="<?php echo $e(I18n::getLocale()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($title); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo $e(defined('BASE_URL') ? BASE_URL : '/'); ?>favicon.svg">
<style>
    /* Paper, whatever the analyst's theme: this page is for printing. */
    :root { color-scheme: light; --accent: <?php echo $accent; ?>; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', 'Noto Sans', 'Noto Sans Devanagari', 'Noto Sans Tamil', 'Noto Sans Bengali', Tahoma, sans-serif;
           color: #111; background: #e9ecf1; margin: 0; font-size: 13px; line-height: 1.5; }

    /* ── On screen: banner, choices panel, paper ─────────────────────── */
    .cr-banner { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 14px; padding: 10px 20px;
                 background: linear-gradient(90deg, var(--accent), color-mix(in srgb, var(--accent) 70%, #000)); color: #fff;
                 box-shadow: 0 2px 6px rgba(0,0,0,.18); }
    .cr-banner img { height: 30px; max-width: 140px; object-fit: contain; background: #fff; border-radius: 4px; padding: 3px 6px; }
    .cr-banner .cr-brand { font-size: 12px; opacity: .85; }
    .cr-banner .cr-what { font-size: 16px; font-weight: 600; line-height: 1.2; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cr-banner .cr-grow { flex: 1; min-width: 0; }
    .cr-btn { font: inherit; font-weight: 600; padding: 7px 16px; border-radius: 4px; border: 1px solid #fff; background: #fff; color: var(--accent);
              text-decoration: none; cursor: pointer; white-space: nowrap; }
    .cr-shell { display: grid; grid-template-columns: 270px minmax(0, 1fr); gap: 20px; padding: 20px; align-items: start; }
    .cr-panel { position: sticky; top: 70px; background: #fff; border: 1px solid #d5dbe5; border-radius: 8px; padding: 14px 16px; font-size: 13px; }
    .cr-panel h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #5b6472; margin: 14px 0 6px; }
    .cr-panel h3:first-child { margin-top: 0; }
    .cr-panel label { display: flex; gap: 8px; align-items: flex-start; padding: 3px 0; cursor: pointer; }
    .cr-panel label input { margin-top: 3px; accent-color: var(--accent); }
    .cr-panel .sub { display: block; margin: 2px 0 4px 24px; }
    .cr-panel .cr-print { width: 100%; margin-top: 14px; background: var(--accent); color: #fff; border-color: var(--accent); text-align: center; display: block; }
    .cr-panel .cr-done { margin-top: 10px; color: #1b5e20; font-size: 12px; }
    .cr-paper { background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.15); border-radius: 2px; padding: 36px 44px; max-width: 900px; width: 100%; }
    @media (max-width: 860px) {
        .cr-shell { grid-template-columns: 1fr; padding: 12px; }
        .cr-panel { position: static; }
        .cr-paper { padding: 20px 16px; }
        .cr-banner { padding: 8px 12px; }
        .cr-banner img, .cr-banner .cr-brand { display: none; }
    }

    /* ── The document ───────────────────────────────────────────────── */
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2.sec { font-size: 17px; margin: 30px 0 10px; padding-bottom: 5px; border-bottom: 2px solid var(--accent); }
    h2.sec .num { color: var(--accent); margin-right: 6px; }
    h3 { font-size: 14px; margin: 18px 0 6px; }
    h4 { font-size: 13px; margin: 12px 0 4px; }
    table { border-collapse: collapse; width: 100%; margin: 6px 0 12px; }
    th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    th { background: #f3f4f6; }
    table.kv th { width: 26%; }
    .meta, .sub { color: #555; }
    .sub { font-size: 12px; }
    .box { border: 1px solid #ccc; padding: 8px 10px; margin: 4px 0 12px; overflow-wrap: anywhere; }
    .box * { max-width: 100%; }
    .box table { width: auto; }
    .msg-head { background: #f6f7f9; border-bottom: 1px solid #ddd; margin: -8px -10px 8px; padding: 6px 10px; }
    .tag { display: inline-block; padding: 0 6px; border: 1px solid #999; border-radius: 8px; font-size: 11px; margin-left: 4px; }
    .tag.internal { border-color: #b26a00; color: #8a5200; }
    .notice { border-left: 4px solid #888; background: #f7f7f7; padding: 6px 10px; margin: 6px 0; }
    .notice.warn { border-color: #c62828; background: #fdecea; }
    .task { border: 1px solid #bbb; border-radius: 4px; padding: 8px 12px; margin: 12px 0; }
    .task .task { margin-left: 12px; border-style: dashed; }
    .res-pass { color: #1b5e20; font-weight: 600; } .res-fail { color: #b71c1c; font-weight: 600; } .res-na { color: #555; }
    .exception { color: #b71c1c; }
    .foot { margin-top: 28px; border-top: 1px solid #ccc; padding-top: 6px; font-size: 12px; color: #555; }
    a { color: #0b57d0; }

    /* The head of the report when there is no cover. */
    .cr-head { border-left: 5px solid var(--accent); padding: 4px 0 4px 14px; margin-bottom: 14px; }
    .cr-kicker { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--accent); font-weight: 700; }

    /* Cover */
    .cr-cover { min-height: 230mm; display: flex; flex-direction: column; }
    .cr-cover .cr-cover-band { background: var(--accent); color: #fff; margin: -36px -44px 0; padding: 34px 44px 30px; }
    .cr-cover .cr-cover-band img { height: 46px; max-width: 200px; object-fit: contain; background: #fff; border-radius: 6px; padding: 5px 9px; margin-bottom: 26px; }
    .cr-cover h1 { font-size: 30px; margin: 6px 0 4px; line-height: 1.2; }
    .cr-cover .cr-ref { font-size: 15px; opacity: .9; }
    .cr-cover .cr-facts { margin-top: 30px; }
    .cr-cover .cr-facts th { width: 32%; background: #f6f7f9; }
    .cr-cover .cr-stamp { margin-top: auto; padding-top: 24px; border-top: 1px solid #ddd; color: #444; font-size: 12px; }
    .cr-cover .cr-conf { display: inline-block; margin-top: 14px; padding: 3px 10px; border: 2px solid #b71c1c; color: #b71c1c; font-weight: 700; letter-spacing: .05em; }

    /* Contents */
    .cr-toc h2 { font-size: 20px; margin: 0 0 14px; border: 0; }
    .cr-toc ol { list-style: none; padding: 0; margin: 0; }
    .cr-toc li { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px dotted #c8ccd3; }
    .cr-toc li .num { color: var(--accent); font-weight: 700; min-width: 22px; }
    .cr-toc li a { color: #111; text-decoration: none; flex: 1; }
    .cr-toc li.sub { padding: 3px 0 3px 30px; border-bottom: 0; font-size: 12px; }
    .cr-toc li .count { color: #666; font-size: 12px; }

    @page { margin: 16mm 13mm 16mm; }
<?php if ($r && !empty($r['options']['pagenums'])):
    $pageTpl = t('case-report.page_counter', ['page' => "\x01", 'pages' => "\x02"]);
    $counter = implode(' ', array_filter(array_map(function ($part) use ($cssStr) {
        if ($part === "\x01") return 'counter(page)';
        if ($part === "\x02") return 'counter(pages)';
        return $part === '' ? '' : $cssStr($part);
    }, preg_split("/(\x01|\x02)/", $pageTpl, -1, PREG_SPLIT_DELIM_CAPTURE)), fn($x) => $x !== ''));
    $running = t('case-report.running_head', ['ref' => $r['ref']]);
    $conf = $r['kind'] === 'ticket' && ($r['ticket']['sensitivity_now'] ?? 'normal') === 'confidential' ? t('case-report.confidential_upper') : ''; ?>
    /* Running header and footer (Chrome 131+, Edge; other browsers print without them). */
    @page {
        @top-left { content: <?php echo $cssStr($running); ?>; font-size: 8.5pt; color: #666; font-family: 'Segoe UI', 'Noto Sans', Tahoma, sans-serif; }
        @top-right { content: <?php echo $cssStr($conf); ?>; font-size: 8.5pt; color: #b71c1c; font-weight: 700; font-family: 'Segoe UI', 'Noto Sans', Tahoma, sans-serif; }
        @bottom-left { content: <?php echo $cssStr(t('case-report.running_foot', ['when' => TaskEvidence::when($r['produced_utc'])])); ?>; font-size: 8pt; color: #888; font-family: 'Segoe UI', 'Noto Sans', Tahoma, sans-serif; }
        @bottom-right { content: <?php echo $counter; ?>; font-size: 8.5pt; color: #666; font-family: 'Segoe UI', 'Noto Sans', Tahoma, sans-serif; }
    }
<?php if (!empty($r['options']['cover'])): ?>
    @page :first { @top-left { content: none; } @top-right { content: none; } @bottom-left { content: none; } @bottom-right { content: none; } }
<?php endif; endif; ?>
    @media print {
        body { background: #fff; }
        .noprint { display: none !important; }
        .cr-shell { display: block; padding: 0; }
        .cr-paper { box-shadow: none; padding: 0; max-width: none; border-radius: 0; }
        /* Fits inside both Letter (Chrome's default, ~247mm) and A4 (~265mm) within the margins. */
        .cr-cover { min-height: 0; height: 238mm; break-after: page; }
        .cr-cover .cr-cover-band { margin: 0 0 0; border-radius: 4px; }
        .cr-toc { break-after: page; }
        body.breaks h2.sec { break-before: page; margin-top: 0; }
        body.breaks .cr-toc + h2.sec, body.breaks .cr-cover + h2.sec { break-before: auto; }
        h2, h3, h4 { break-after: avoid; }
        .msg-head, tr { break-inside: avoid; }
        a { color: #111; }
    }
    @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body class="<?php echo ($r && !empty($r['options']['breaks'])) ? 'breaks' : ''; ?>">
<?php if (!$r): ?>
    <div class="cr-banner"><?php if ($logo): ?><img src="<?php echo $e($logo); ?>" alt=""><?php endif; ?><div class="cr-grow"><div class="cr-what"><?php echo $e(t('case-report.not_found_title')); ?></div></div></div>
    <div class="cr-shell" style="display:block"><div class="cr-paper"><p><?php echo $e(t('case-report.not_found')); ?></p></div></div>
</body></html>
<?php return; endif;

    $opt = $r['options'];
    $on = fn(string $s) => in_array($s, $opt['sections'], true);
    $isTicket = $r['kind'] === 'ticket';
    // A task on its own has no conversation, notes or ticket checklists, and is itself the work performed.
    $sectionsHere = $isTicket ? CaseReport::SECTIONS : ['history', 'evidence'];
    $chosen = array_values(array_intersect($sectionsHere, $opt['sections']));
    $left = array_values(array_diff($sectionsHere, $opt['sections']));
    $secName = fn($s) => t('case-report.section_' . $s);
    $idValue = $isTicket ? (int)$r['ticket']['id'] : (int)$r['tasks'][0]['id'];
    $layoutQs = ['cover' => $opt['cover'] ? 1 : null, 'contents' => $opt['contents'] ? 1 : null,
                 'breaks' => $opt['breaks'] ? 1 : null, 'pagenums' => $opt['pagenums'] ? 1 : null];
    $printQs = http_build_query(['id' => $idValue, 'set' => 1, 's' => $opt['sections'], 'internal' => $opt['internal'] ? 1 : null, 'print' => 1] + $layoutQs);

    // The sections that will print, in order, numbered - the contents and the headings share it.
    $printed = ['overview'];
    foreach ($sectionsHere as $s) if ($on($s)) $printed[] = $s;
    if (!$isTicket && !in_array('tasks', $printed, true)) array_splice($printed, 1, 0, ['tasks']);
    $num = array_flip($printed);
    $secCount = [
        'conversation' => count($r['conversation']), 'notes' => count($r['notes']), 'checklists' => count($r['checklists']) + count($r['ticket_forms'] ?? []),
        'tasks' => caseReportCountTasks($r['tasks']),
    ];
    /** A numbered, linkable section heading. */
    $head = function (string $s, ?int $count = null) use ($e, $num, $secName) {
        echo '<h2 class="sec" id="sec-' . $e($s) . '"><span class="num">' . ($num[$s] + 1) . '.</span>' . $e($secName($s))
           . ($count !== null ? ' <span class="sub">(' . $count . ')</span>' : '') . '</h2>';
    };
    $t = $isTicket ? $r['ticket'] : null;
    $status = $isTicket ? ($t['status_name'] ?? '-') : ($r['tasks'][0]['task']['status_name'] ?? '-');
    $confidential = $isTicket && ($t['sensitivity_now'] ?? 'normal') === 'confidential';
    $check = fn(string $name, bool $checked) => '<input type="checkbox" name="' . $e($name) . '" value="1"' . ($checked ? ' checked' : '') . ' onchange="this.form.submit()">';
    ?>
<header class="cr-banner noprint">
    <?php if ($logo): ?><img src="<?php echo $e($logo); ?>" alt=""><?php endif; ?>
    <div class="cr-grow">
        <div class="cr-brand"><?php echo $e($sys); ?> · <?php echo $T('report_name'); ?></div>
        <div class="cr-what"><?php echo $e($r['ref']); ?> - <?php echo $e($r['title']); ?></div>
    </div>
    <a class="cr-btn" href="?<?php echo $e($printQs); ?>"><?php echo $T('print'); ?></a>
</header>

<div class="cr-shell">
<form class="cr-panel noprint" method="get">
    <input type="hidden" name="id" value="<?php echo $idValue; ?>">
    <input type="hidden" name="set" value="1">
    <h3><?php echo $T('panel_sections'); ?></h3>
    <label><input type="checkbox" checked disabled> <span><?php echo $e($secName('overview')); ?></span></label>
    <?php foreach ($sectionsHere as $s): ?>
        <label><input type="checkbox" name="s[]" value="<?php echo $e($s); ?>"<?php echo $on($s) ? ' checked' : ''; ?> onchange="this.form.submit()"> <span><?php echo $e($secName($s)); ?></span></label>
    <?php endforeach; ?>
    <?php if ($isTicket): ?>
    <h3><?php echo $T('panel_notes'); ?></h3>
    <label><?php echo $check('internal', $opt['internal']); ?> <span><?php echo $T('include_internal'); ?></span></label>
    <span class="sub"><?php echo $T('include_internal_hint'); ?></span>
    <?php endif; ?>
    <h3><?php echo $T('panel_layout'); ?></h3>
    <label><?php echo $check('cover', $opt['cover']); ?> <span><?php echo $T('layout_cover'); ?></span></label>
    <label><?php echo $check('contents', $opt['contents']); ?> <span><?php echo $T('layout_contents'); ?></span></label>
    <label><?php echo $check('breaks', $opt['breaks']); ?> <span><?php echo $T('layout_breaks'); ?></span></label>
    <label><?php echo $check('pagenums', $opt['pagenums']); ?> <span><?php echo $T('layout_pagenums'); ?></span></label>
    <noscript><button type="submit" class="cr-btn"><?php echo $T('update'); ?></button></noscript>
    <a class="cr-btn cr-print" href="?<?php echo $e($printQs); ?>"><?php echo $T('print'); ?></a>
    <span class="sub" style="margin-left:0;margin-top:8px"><?php echo $T('print_hint'); ?></span>
    <?php if ($print && $recordError === null): ?><div class="cr-done">✓ <?php echo $T('export_recorded'); ?></div><?php endif; ?>
</form>

<main class="cr-paper">
<?php if ($opt['cover']): ?>
<section class="cr-cover">
    <div class="cr-cover-band">
        <?php if ($logo): ?><img src="<?php echo $e($logo); ?>" alt=""><br><?php endif; ?>
        <div class="cr-kicker" style="color:#fff;opacity:.85"><?php echo $T('report_name'); ?></div>
        <h1><?php echo $e($r['title']); ?></h1>
        <div class="cr-ref"><?php echo $e($r['ref']); ?> · <?php echo $e($status); ?></div>
    </div>
    <?php if ($confidential): ?><div><span class="cr-conf"><?php echo $T('confidential_upper'); ?></span></div><?php endif; ?>
    <table class="kv cr-facts">
        <?php if ($isTicket): ?>
        <tr><th><?php echo $T('f_requester'); ?></th><td><?php echo $e($t['requester_name'] ?? '-'); ?></td></tr>
        <tr><th><?php echo $T('f_assignee_owner'); ?></th><td><?php echo $e($t['assignee_name'] ?: ($t['owner_name'] ?? '-')); ?></td></tr>
        <tr><th><?php echo $T('f_created'); ?></th><td><?php echo $when($t['created_datetime']); ?></td></tr>
        <tr><th><?php echo $T('f_closed'); ?></th><td><?php echo $t['closed_datetime'] ? $when($t['closed_datetime']) : $T('not_closed'); ?></td></tr>
        <?php if ($on('tasks')): ?><tr><th><?php echo $T('f_tasks'); ?></th><td><?php echo $T('tasks_summary', ['n' => caseReportCountTasks($r['tasks']), 'open' => caseReportCountTasks($r['tasks'], true)]); ?></td></tr><?php endif; ?>
        <?php else: $tk = $r['tasks'][0]['task']; ?>
        <tr><th><?php echo $T('f_assigned'); ?></th><td><?php echo $e($tk['assignee_name'] ?? '-'); ?></td></tr>
        <tr><th><?php echo $T('f_created'); ?></th><td><?php echo $when($tk['created_datetime'] ?? null); ?></td></tr>
        <tr><th><?php echo $T('f_completed'); ?></th><td><?php echo !empty($tk['completed_datetime']) ? $when($tk['completed_datetime']) : $T('not_completed'); ?></td></tr>
        <?php endif; ?>
    </table>
    <div class="cr-stamp">
        <div><?php echo $T('produced', ['who' => $r['by'], 'when' => TaskEvidence::when($r['produced_utc']), 'tz' => $r['tz']]); ?></div>
        <div><?php echo $T('scope', ['sections' => implode(', ', array_map($secName, $printed))]); ?>
            <?php if ($left): ?> <?php echo $T('scope_left_out', ['sections' => implode(', ', array_map($secName, $left))]); ?><?php endif; ?></div>
        <?php if ($isTicket && $on('notes')): ?><div><?php echo $opt['internal'] ? $T('internal_included') : $T('internal_excluded', ['n' => $r['hidden']['internal_notes']]); ?></div><?php endif; ?>
        <div style="margin-top:6px"><?php echo $T('footer_short'); ?></div>
    </div>
</section>
<?php else: ?>
<div class="cr-head">
    <div class="cr-kicker"><?php echo $T('report_name'); ?><?php if ($confidential): ?> · <?php echo $T('confidential_upper'); ?><?php endif; ?></div>
    <h1><?php echo $e($r['ref']); ?>: <?php echo $e($r['title']); ?></h1>
    <div class="meta"><?php echo $T('produced', ['who' => $r['by'], 'when' => TaskEvidence::when($r['produced_utc']), 'tz' => $r['tz']]); ?></div>
    <div class="meta"><?php echo $T('scope', ['sections' => implode(', ', array_map($secName, $printed))]); ?>
        <?php if ($left): ?> <?php echo $T('scope_left_out', ['sections' => implode(', ', array_map($secName, $left))]); ?><?php endif; ?></div>
    <?php if ($isTicket && $on('notes')): ?><div class="meta"><?php echo $opt['internal'] ? $T('internal_included') : $T('internal_excluded', ['n' => $r['hidden']['internal_notes']]); ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($opt['contents']): ?>
<nav class="cr-toc">
    <h2><?php echo $T('contents'); ?></h2>
    <ol>
        <?php foreach ($printed as $i => $s): ?>
        <li><span class="num"><?php echo $i + 1; ?>.</span><a href="#sec-<?php echo $e($s); ?>"><?php echo $e($secName($s)); ?></a>
            <?php if (isset($secCount[$s])): ?><span class="count"><?php echo $T('toc_count', ['n' => $secCount[$s]]); ?></span><?php endif; ?></li>
        <?php if ($s === 'tasks'): foreach ($r['tasks'] as $b): ?>
        <li class="sub"><a href="#task-<?php echo (int)$b['task']['id']; ?>">#<?php echo (int)$b['task']['id']; ?> <?php echo $e($b['task']['title'] ?? ''); ?></a></li>
        <?php endforeach; endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>

<?php /* ── 1. Overview ─────────────────────────────────────────── */ ?>
<?php $head('overview'); ?>
<?php if ($recordError !== null): ?><div class="notice warn"><?php echo $T('export_not_recorded'); ?></div><?php endif; ?>
<?php if ($isTicket && !empty($r['ticket']['deleted_datetime'])): ?><div class="notice warn"><?php echo $T('in_trash', ['when' => TaskEvidence::when($r['ticket']['deleted_datetime'])]); ?></div><?php endif; ?>
<?php foreach ($r['unavailable'] as $sec => $_): ?>
<div class="notice warn"><?php echo $T('unavailable', ['section' => $secName($sec)]); ?></div>
<?php endforeach; ?>
<?php
    $hiddenLines = [];
    foreach (['tasks', 'subtasks_deeper', 'assets', 'cis', 'links', 'documents'] as $k) {
        if (!empty($r['hidden'][$k])) $hiddenLines[] = t('case-report.hidden_' . $k, ['n' => $r['hidden'][$k]]);
    }
    if (in_array('tasks_no_module', $r['notes_scope'], true)) $hiddenLines[] = t('case-report.tasks_no_module');
    foreach ($hiddenLines as $h): ?>
<div class="notice"><?php echo $e($h); ?></div>
<?php endforeach; ?>
<?php if ($isTicket): ?>
<table class="kv">
    <tr><th><?php echo $T('f_reference'); ?></th><td><?php echo $e($t['ticket_number']); ?> · <a href="<?php echo $e($base . 'tickets/?ticket_id=' . (int)$t['id']); ?>"><?php echo $T('open_record'); ?></a></td></tr>
    <tr><th><?php echo $T('f_subject'); ?></th><td><?php echo $e($t['subject']); ?></td></tr>
    <tr><th><?php echo $T('f_status'); ?></th><td><?php echo $e($t['status_name'] ?? '-'); ?></td></tr>
    <tr><th><?php echo $T('f_priority'); ?></th><td><?php echo $e($t['priority_name'] ?? '-'); ?></td></tr>
    <tr><th><?php echo $T('f_type_category'); ?></th><td><?php echo $e(trim(($t['type_name'] ?? '') . ' / ' . ($t['category_name'] ?? ''), ' /') ?: '-'); ?></td></tr>
    <tr><th><?php echo $T('f_department_team'); ?></th><td><?php echo $e(trim(($t['department_name'] ?? '') . ' / ' . ($t['team_name'] ?? ''), ' /') ?: '-'); ?></td></tr>
    <tr><th><?php echo $T('f_assignee_owner'); ?></th><td><?php
        $who = array_filter([$t['assignee_name'] ? $e($t['assignee_name']) : null,
                             (!empty($t['owner_name']) && $t['owner_name'] !== $t['assignee_name']) ? $T('owner_is', ['name' => $t['owner_name']]) : null]);
        echo $who ? implode(' · ', $who) : '-'; ?></td></tr>
    <tr><th><?php echo $T('f_requester'); ?></th><td><?php echo $e($t['requester_name'] ?? '-'); ?><?php if (!empty($t['requester_email']) && $t['requester_email'] !== $t['requester_name']): ?> &lt;<?php echo $e($t['requester_email']); ?>&gt;<?php endif; ?></td></tr>
    <?php if (!empty($t['company_name']) && !empty($r['multi'])): ?><tr><th><?php echo $T('f_company'); ?></th><td><?php echo $e($t['company_name']); ?></td></tr><?php endif; ?>
    <tr><th><?php echo $T('f_origin'); ?></th><td><?php echo $e($t['origin_name'] ?? '-'); ?></td></tr>
    <tr><th><?php echo $T('f_sensitivity'); ?></th><td><?php echo ($t['sensitivity_now'] ?? 'normal') === 'confidential' ? $T('confidential') : $T('normal'); ?></td></tr>
    <tr><th><?php echo $T('f_created'); ?></th><td><?php echo $when($t['created_datetime']); ?></td></tr>
    <tr><th><?php echo $T('f_updated'); ?></th><td><?php echo $when($t['updated_datetime']); ?></td></tr>
    <tr><th><?php echo $T('f_closed'); ?></th><td><?php echo $t['closed_datetime'] ? $when($t['closed_datetime']) : $T('not_closed'); ?>
        <?php if (!empty($t['closure_category_name']) || !empty($t['resolution_name'])): ?><div class="sub"><?php echo $e(trim(($t['closure_category_name'] ?? '') . ' / ' . ($t['resolution_name'] ?? ''), ' /')); ?></div><?php endif; ?></td></tr>
    <?php if (!empty($t['work_start_datetime'])): ?>
    <tr><th><?php echo $T('f_work_window'); ?></th><td><?php echo $T('wall_clock', ['from' => (string)$t['work_start_datetime'], 'to' => (string)($t['work_end_datetime'] ?? '-')]); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($r['fields'] as $f): ?>
    <tr><th><?php echo $e($f['label']); ?></th><td><?php echo $e($f['value']); ?></td></tr>
    <?php endforeach; ?>
    <?php if ($r['assets']): ?><tr><th><?php echo $T('f_assets'); ?></th><td><?php echo $e(implode(', ', $r['assets'])); ?></td></tr><?php endif; ?>
    <?php if ($r['cis']): ?><tr><th><?php echo $T('f_cis'); ?></th><td><?php echo $e(implode(', ', $r['cis'])); ?></td></tr><?php endif; ?>
    <?php if ($r['links']): ?><tr><th><?php echo $T('f_linked_tickets'); ?></th><td><?php foreach ($r['links'] as $l): ?><div><?php echo $e($l['number']); ?> - <?php echo $e($l['subject']); ?> <span class="sub">(<?php echo $e($l['relation']); ?>)</span></div><?php endforeach; ?></td></tr><?php endif; ?>
    <?php if ($on('tasks')): ?><tr><th><?php echo $T('f_tasks'); ?></th><td><?php echo $T('tasks_summary', ['n' => caseReportCountTasks($r['tasks']), 'open' => caseReportCountTasks($r['tasks'], true)]); ?></td></tr><?php endif; ?>
</table>
<?php else: ?>
<p class="sub"><?php echo $T('task_overview'); ?></p>
<?php endif; ?>

<?php /* ── 2. Conversation ─────────────────────────────────────── */ ?>
<?php if ($isTicket && $on('conversation')): ?>
<?php $head('conversation', $secCount['conversation']); ?>
<p class="sub"><?php echo $T('conversation_note'); ?></p>
<?php if (!$r['conversation'] && empty($r['unavailable']['conversation'])): ?><p><?php echo $T('none'); ?></p><?php endif; ?>
<?php foreach ($r['conversation'] as $m): ?>
<div class="box">
    <div class="msg-head">
        <strong><?php echo $e($m['from_name'] ?: $m['from_address']); ?></strong>
        <?php if ($m['from_name'] && $m['from_address']): ?><span class="sub">&lt;<?php echo $e($m['from_address']); ?>&gt;</span><?php endif; ?>
        <span class="tag"><?php echo $e(strtolower((string)$m['direction']) === 'outbound' ? t('case-report.outbound') : t('case-report.inbound')); ?><?php echo ($m['channel'] ?? 'email') !== 'email' ? ' · ' . $e($m['channel']) : ''; ?></span>
        <div class="sub"><?php echo $when($m['received_datetime']); ?><?php if (!empty($m['subject'])): ?> · <?php echo $e($m['subject']); ?><?php endif; ?></div>
        <?php if (!empty($m['to_recipients'])): ?><div class="sub"><?php echo $T('to'); ?> <?php echo $e(caseReportRecipients($m['to_recipients'])); ?></div><?php endif; ?>
        <?php if (!empty($m['cc_recipients'])): ?><div class="sub"><?php echo $T('cc'); ?> <?php echo $e(caseReportRecipients($m['cc_recipients'])); ?></div><?php endif; ?>
    </div>
    <?php echo CaseReport::body($m['body_content'], (string)($m['body_type'] ?? 'html')) ?: '<em>' . $T('empty_message') . '</em>'; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php /* ── 3. Notes ───────────────────────────────────────────── */ ?>
<?php if ($isTicket && $on('notes')): ?>
<?php $head('notes', $secCount['notes']); ?>
<?php if (!$r['notes'] && empty($r['unavailable']['notes'])): ?><p><?php echo $T('none'); ?></p><?php endif; ?>
<?php foreach ($r['notes'] as $n): ?>
<div class="box">
    <div class="msg-head"><strong><?php echo $e($n['author'] ?? t('case-report.former_analyst')); ?></strong>
        <span class="tag<?php echo (int)$n['is_internal'] === 1 ? ' internal' : ''; ?>"><?php echo (int)$n['is_internal'] === 1 ? $T('note_internal') : $T('note_shared'); ?></span>
        <div class="sub"><?php echo $when($n['created_datetime']); ?></div></div>
    <?php echo CaseReport::body($n['note_text']); ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php /* ── 4. Ticket checklists ─────────────────────────────────── */ ?>
<?php if ($isTicket && $on('checklists')): ?>
<?php $head('checklists', $secCount['checklists']); ?>
<?php if ($r['checklists']): ?><p class="sub"><?php echo $T('ticket_checklists_note'); ?></p><?php endif; ?>
<?php if (!$r['checklists'] && empty($r['ticket_forms']) && empty($r['unavailable']['checklists'])): ?><p><?php echo $T('none'); ?></p><?php endif; ?>
<?php foreach ($r['checklists'] as $c): ?>
<h3><?php echo $e($c['title']); ?><?php if (!empty($c['template_version'])): ?> <span class="sub"><?php echo $T('template_version', ['n' => $c['template_version']]); ?></span><?php endif; ?></h3>
<table>
    <tr><th><?php echo $T('col_step'); ?></th><th><?php echo $T('col_state'); ?></th><th><?php echo $T('col_by_when'); ?></th></tr>
    <?php foreach ($c['items'] as $it): ?>
    <tr><td><?php echo $e($it['title']); ?><?php if ((int)$it['is_mandatory'] === 1): ?> <span class="sub">(<?php echo $T('mandatory'); ?>)</span><?php endif; ?></td>
        <td><?php /* GH #271: a ticket step can record a result too, shown as a task's is */ ?>
            <?php if (!empty($it['result'])): ?><span class="res-<?php echo $e($it['result']); ?>"><?php echo $e(t('tasks.evidence.result_' . $it['result'])); ?></span>
            <?php else: ?><?php echo (int)$it['is_completed'] === 1 ? $T('done') : $T('not_done'); ?><?php endif; ?>
            <?php if ((string)($it['response_value'] ?? '') !== ''): ?><div class="sub"><?php echo $e($it['response_value']); ?></div><?php endif; ?>
            <?php if (!empty($it['exception_note'])): ?><div class="exception"><?php echo $e($it['exception_note']); ?></div><?php endif; ?></td>
        <td class="sub"><?php echo $e(trim((string)$it['completed_by_name'] . ' ' . ($it['completed_datetime'] ? TaskEvidence::when($it['completed_datetime']) : ''))); ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endforeach; ?>

<?php /* GH #272: forms filled in on the ticket itself - the same layout as a task's forms */ ?>
<?php foreach (($r['ticket_forms'] ?? []) as $f): ?>
<h3><?php echo $T('form', ['title' => $f['title']]); ?><?php if ($f['is_required']): ?> <span class="sub">(<?php echo $T('required'); ?>)</span><?php endif; ?></h3>
<?php if (!$f['submission_id']): ?>
    <p class="sub"><?php echo $T('form_not_submitted'); ?></p>
<?php else: ?>
    <p class="sub"><?php echo $T('form_submitted', ['who' => (string)($f['submitted_by'] ?? '-'), 'when' => TaskEvidence::when($f['submitted_utc'])]); ?>
        <?php if (($f['revision'] ?? 0) > 1): ?> · <?php echo $T('form_revision', ['n' => $f['revision']]); ?><?php endif; ?></p>
    <?php if (!$f['may_read']): ?>
        <p class="sub"><em><?php echo $T('form_answers_hidden'); ?></em></p>
    <?php elseif (!empty($f['answers'])): ?>
        <table>
            <?php foreach ($f['answers'] as $a): ?>
            <tr><th style="width:35%"><?php echo $e($a['label']); ?></th><td style="white-space:pre-wrap"<?php echo !empty($a['exception']) ? ' class="exception"' : ''; ?>><?php echo $e($a['value']); ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php /* ── 5. Work performed ──────────────────────────────────── */ ?>
<?php if ($on('tasks') || !$isTicket): ?>
<?php $head('tasks', $secCount['tasks']); ?>
<?php if (!$r['tasks'] && empty($r['unavailable']['tasks'])): ?><p><?php echo $T('no_tasks'); ?></p><?php endif; ?>
<?php foreach ($r['tasks'] as $b) caseReportTask($b, $r, $base); ?>
<?php endif; ?>

<?php /* ── 6. History ─────────────────────────────────────────── */ ?>
<?php if ($on('history')): ?>
<?php $head('history'); ?>
<p class="sub"><?php echo $T('history_note'); ?></p>
<?php if ($isTicket): ?>
<h3><?php echo $T('ticket_history', ['ref' => $r['ref']]); ?> <span class="sub">(<?php echo count($r['history']); ?>)</span></h3>
<?php if (!$r['history'] && empty($r['unavailable']['history'])): ?><p><?php echo $T('none'); ?></p><?php endif; ?>
<?php if ($r['history']): ?>
<table>
    <tr><th style="width:18%"><?php echo $T('col_when'); ?></th><th style="width:18%"><?php echo $T('col_who'); ?></th><th><?php echo $T('col_change'); ?></th></tr>
    <?php foreach ($r['history'] as $h): ?>
    <tr><td class="sub"><?php echo $when($h['created_datetime']); ?></td>
        <td class="sub"><?php echo $e($h['who'] ?? t('case-report.by_system')); ?></td>
        <td><strong><?php echo $e($h['field_name']); ?></strong>: <?php echo caseReportChange($h['old_value'], $h['new_value']); ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php endif; ?>
<?php caseReportTaskHistories($r['tasks']); ?>
<?php endif; ?>

<?php /* ── 7. Evidence index ──────────────────────────────────── */ ?>
<?php if ($on('evidence')): ?>
<?php $head('evidence'); ?>
<p class="sub"><?php echo $T('evidence_note'); ?></p>
<?php if (!$r['evidence']['attachments'] && !$r['evidence']['documents'] && empty($r['unavailable']['evidence'])): ?><p><?php echo $T('none'); ?></p><?php endif; ?>
<?php if ($r['evidence']['documents']): ?>
<h3><?php echo $T('documents_heading'); ?> <span class="sub">(<?php echo count($r['evidence']['documents']); ?>)</span></h3>
<table>
    <tr><th><?php echo $T('col_document'); ?></th><th><?php echo $T('col_attached_to'); ?></th><th><?php echo $T('col_added'); ?></th><th><?php echo $T('col_fingerprint'); ?></th></tr>
    <?php foreach ($r['evidence']['documents'] as $d): ?>
    <tr><td><strong><?php echo $e($d['title']); ?></strong>
            <?php if ($d['kind'] === 'link' || !empty($d['external_url'])): ?><div class="sub"><?php echo $T('link'); ?>: <?php echo $e($d['external_url']); ?></div>
            <?php else: ?><div class="sub"><?php echo $e($d['original_name']); ?> · <?php echo $e(caseReportSize($d['size_bytes'])); ?> · <?php echo $e($d['mime_type']); ?></div><?php endif; ?></td>
        <td class="sub"><?php echo $e(caseReportWhere($d['where'])); ?></td>
        <td class="sub"><?php echo $e($d['uploaded_by'] ?? '-'); ?><br><?php echo $when($d['created_datetime']); ?></td>
        <td class="sub" style="font-family:monospace;font-size:11px"><?php echo $d['content_hash'] ? $e($d['content_hash']) : $T('no_fingerprint'); ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ($r['evidence']['attachments']): ?>
<h3><?php echo $T('attachments_heading'); ?> <span class="sub">(<?php echo count($r['evidence']['attachments']); ?>)</span></h3>
<table>
    <tr><th><?php echo $T('col_file'); ?></th><th><?php echo $T('col_message'); ?></th><th><?php echo $T('col_size'); ?></th></tr>
    <?php foreach ($r['evidence']['attachments'] as $a): ?>
    <tr><td><?php echo $e($a['filename']); ?><?php if ((int)$a['is_inline'] === 1): ?> <span class="tag"><?php echo $T('inline'); ?></span><?php endif; ?><div class="sub"><?php echo $e($a['content_type']); ?></div></td>
        <td class="sub"><?php echo $e($a['from_name'] ?: $a['from_address']); ?><br><?php echo $when($a['received_datetime']); ?></td>
        <td class="sub"><?php echo $e(caseReportSize($a['file_size'])); ?></td></tr>
    <?php endforeach; ?>
</table>
<p class="sub"><?php echo $T('attachments_no_fingerprint'); ?></p>
<?php endif; ?>
<?php endif; ?>

<div class="foot"><?php echo $T('footer', ['when' => TaskEvidence::when($r['produced_utc']), 'tz' => $r['tz']]); ?></div>
</main>
</div>
<?php if ($print): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
<?php endif; ?>
</body>
</html>
<?php
}

/** One task block: the record, its work, its results, and its subtasks inside it. */
function caseReportTask(array $b, array $r, string $base): void
{
    $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $T = fn(string $k, array $p = []) => htmlspecialchars(t('case-report.' . $k, $p), ENT_QUOTES, 'UTF-8');
    $when = fn($utc) => $utc ? $e(TaskEvidence::when((string)$utc)) : '-';
    $t = $b['task'];
    ?>
<div class="task" id="task-<?php echo (int)$t['id']; ?>">
    <h3>#<?php echo (int)$t['id']; ?> <?php echo $e($t['title']); ?>
        <span class="tag"><?php echo $e($t['status_name'] ?? '-'); ?></span></h3>
    <table class="kv">
        <tr><th><?php echo $T('f_assigned'); ?></th><td><?php echo $e(trim(($t['assignee_name'] ?? '') . (!empty($t['team_name']) ? ' / ' . $t['team_name'] : '') . (!empty($t['supplier_name']) ? ' / ' . $t['supplier_name'] : ''), ' /') ?: '-'); ?></td></tr>
        <tr><th><?php echo $T('f_priority'); ?></th><td><?php echo $e($t['priority_name'] ?? '-'); ?></td></tr>
        <tr><th><?php echo $T('f_dates'); ?></th><td><?php echo $T('task_dates', ['start' => $t['start_date'] ? TaskEvidence::day($t['start_date']) : '-', 'due' => $t['due_date'] ? TaskEvidence::day($t['due_date']) : '-']); ?></td></tr>
        <tr><th><?php echo $T('f_created'); ?></th><td><?php echo $when($t['created_datetime'] ?? null); ?><?php if (!empty($t['created_by_name'])): ?> · <?php echo $e($t['created_by_name']); ?><?php endif; ?></td></tr>
        <tr><th><?php echo $T('f_completed'); ?></th><td><?php echo !empty($t['completed_datetime']) ? $when($t['completed_datetime']) . (!empty($t['completed_by_name']) ? ' · ' . $e($t['completed_by_name']) : '') : $T('not_completed'); ?></td></tr>
        <?php if (!empty($t['work_start_datetime'])): ?><tr><th><?php echo $T('f_work_window'); ?></th><td><?php echo $T('wall_clock', ['from' => (string)$t['work_start_datetime'], 'to' => (string)($t['work_end_datetime'] ?? '-')]); ?></td></tr><?php endif; ?>
        <?php if ($b['ticket_ref'] !== null): ?><tr><th><?php echo $T('f_ticket'); ?></th><td><?php echo $b['ticket_ref'] === false ? $T('ticket_not_shown') : $e($b['ticket_ref']); ?></td></tr><?php endif; ?>
        <tr><th><?php echo $T('f_reference'); ?></th><td><a href="<?php echo $e($base . 'tasks/?task=' . (int)$t['id']); ?>"><?php echo $T('open_record'); ?></a></td></tr>
    </table>
    <?php foreach ($b['unavailable'] as $part => $_): ?><div class="notice warn"><?php echo $T('task_part_unavailable', ['part' => t('case-report.part_' . $part)]); ?></div><?php endforeach; ?>

    <?php if (trim(strip_tags((string)($t['description'] ?? ''))) !== ''): ?>
    <h4><?php echo $T('description'); ?></h4>
    <div class="box"><?php echo CaseReport::body($t['description']); ?></div>
    <?php endif; ?>

    <?php foreach ($b['checklists'] as $c): ?>
    <h4><?php echo $T('checklist', ['title' => $c['title']]); ?><?php if (!empty($c['template_version'])): ?> <span class="sub"><?php echo $T('template_version', ['n' => $c['template_version']]); ?></span><?php endif; ?></h4>
    <table>
        <tr><th><?php echo $T('col_step'); ?></th><th><?php echo $T('col_result'); ?></th><th><?php echo $T('col_by_when'); ?></th></tr>
        <?php foreach ($c['items'] as $it): ?>
        <tr><td><?php echo $e($it['title']); ?><?php if ((int)$it['is_mandatory'] === 1): ?> <span class="sub">(<?php echo $T('mandatory'); ?>)</span><?php endif; ?></td>
            <td><?php if (!empty($it['result'])): ?><span class="res-<?php echo $e($it['result']); ?>"><?php echo $e(t('tasks.evidence.result_' . $it['result'])); ?></span>
                <?php else: ?><?php echo (int)$it['is_completed'] === 1 ? $T('done') : $T('not_done'); ?><?php endif; ?>
                <?php if ((string)($it['response_value'] ?? '') !== ''): ?><div class="sub"><?php echo $e($it['response_value']); ?></div><?php endif; ?>
                <?php if (!empty($it['exception_note'])): ?><div class="exception"><?php echo $e($it['exception_note']); ?></div><?php endif; ?></td>
            <td class="sub"><?php echo $e(trim((string)($it['completed_by_name'] ?? '') . ' ' . (!empty($it['completed_datetime']) ? TaskEvidence::when($it['completed_datetime']) : ''))); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endforeach; ?>

    <?php foreach ($b['forms'] as $f): ?>
    <h4><?php echo $T('form', ['title' => $f['title']]); ?><?php if ($f['is_required']): ?> <span class="sub">(<?php echo $T('required'); ?>)</span><?php endif; ?></h4>
    <?php if (!$f['submission_id']): ?>
        <p class="sub"><?php echo $T('form_not_submitted'); ?></p>
    <?php else: ?>
        <p class="sub"><?php echo $T('form_submitted', ['who' => (string)($f['submitted_by'] ?? '-'), 'when' => TaskEvidence::when($f['submitted_utc'])]); ?>
            <?php if (($f['revision'] ?? 0) > 1): ?> · <?php echo $T('form_revision', ['n' => $f['revision']]); ?><?php endif; ?></p>
        <?php if (!$f['may_read']): ?>
            <p class="sub"><em><?php echo $T('form_answers_hidden'); ?></em></p>
        <?php elseif (!empty($f['answers'])): ?>
            <table>
                <?php foreach ($f['answers'] as $a): ?>
                <tr><th style="width:35%"><?php echo $e($a['label']); ?></th><td style="white-space:pre-wrap"<?php echo !empty($a['exception']) ? ' class="exception"' : ''; ?>><?php echo $e($a['value']); ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($b['procedures']): ?>
    <h4><?php echo $T('procedures'); ?></h4>
    <ul>
        <?php foreach ($b['procedures'] as $p): ?>
        <li><?php echo $e($p['title'] ?? ($p['frozen_title'] ?? t('case-report.procedure_hidden'))); ?>
            <span class="sub"><?php echo !empty($p['frozen'])
                ? $T('procedure_frozen', ['n' => $p['frozen']['version'] ?? '-', 'when' => TaskEvidence::when($p['frozen']['at_utc'] ?? null), 'ref' => (int)$p['frozen']['snapshot_id']])
                : $T('procedure_not_frozen'); ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($b['follow_ups']): ?>
    <h4><?php echo $T('follow_ups'); ?></h4>
    <ul>
        <?php foreach ($b['follow_ups'] as $fu): ?>
        <li><?php echo $e($fu['number'] ?? ($fu['kind'] . ' #' . $fu['record_id'])); ?><?php if (!empty($fu['title'])): ?> - <?php echo $e($fu['title']); ?><?php endif; ?>
            <span class="sub">(<?php echo $fu['open'] === null ? $T('follow_up_gone') : ($fu['open'] ? $T('follow_up_open') : $T('follow_up_closed')); ?>)</span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($b['comments']): ?>
    <h4><?php echo $T('comments'); ?> <span class="sub">(<?php echo count($b['comments']); ?>)</span></h4>
    <?php foreach ($b['comments'] as $c): ?>
    <div class="box"><div class="msg-head"><strong><?php echo $e($c['author'] ?? t('case-report.former_analyst')); ?></strong> <span class="sub"><?php echo $when($c['created_datetime']); ?></span></div>
        <?php echo CaseReport::body($c['comment']); ?></div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($b['documents']): ?>
    <p class="sub"><?php echo $T('task_documents', ['n' => count($b['documents'])]); ?></p>
    <?php endif; ?>

    <?php foreach ($b['subtasks'] as $sb) caseReportTask($sb, $r, $base); ?>
</div>
<?php
}

/** Each task's own history (and its checklist step changes), under the History section. */
function caseReportTaskHistories(array $blocks): void
{
    $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $T = fn(string $k, array $p = []) => htmlspecialchars(t('case-report.' . $k, $p), ENT_QUOTES, 'UTF-8');
    foreach ($blocks as $b) {
        $t = $b['task'];
        $rows = $b['history'];
        foreach ($b['steps'] as $st) {
            $after = json_decode((string)($st['after_json'] ?? ''), true) ?: [];
            $rows[] = ['kind' => 'step', 'created_datetime' => $st['created_datetime'], 'who' => $st['actor_name'] ?? null,
                       'text' => t('tasks.evidence.step_event', ['step' => (string)$st['item_title'], 'action' => t('tasks.evidence.step_' . $st['action']),
                                    'who' => (string)($st['actor_name'] ?? '-'), 'result' => isset($after['result']) ? t('tasks.evidence.result_' . $after['result']) : '']),
                       'late' => !empty($st['after_completion'])];
        }
        usort($rows, fn($a, $c) => strcmp((string)$a['created_datetime'], (string)$c['created_datetime']));
        ?>
<h3><?php echo $T('task_history', ['id' => (int)$t['id'], 'title' => (string)$t['title']]); ?> <span class="sub">(<?php echo count($rows); ?>)</span></h3>
<?php if (!$rows): ?><p><?php echo $T('none'); ?></p><?php else: ?>
<table>
    <tr><th style="width:18%"><?php echo $T('col_when'); ?></th><th style="width:18%"><?php echo $T('col_who'); ?></th><th><?php echo $T('col_change'); ?></th></tr>
    <?php foreach ($rows as $h): ?>
    <tr><td class="sub"><?php echo $e(TaskEvidence::when($h['created_datetime'])); ?></td>
        <td class="sub"><?php echo $e($h['kind'] === 'event' ? ($h['actor_name'] ?: t('case-report.by_system')) : ($h['who'] ?? t('case-report.by_system'))); ?></td>
        <td><?php
            if ($h['kind'] === 'audit') echo '<strong>' . $e($h['field_name']) . '</strong>: ' . caseReportChange($h['old_value'], $h['new_value']);
            elseif ($h['kind'] === 'event') echo $e(caseReportEvent($h));
            else echo $e($h['text']) . (!empty($h['late']) ? ' <span class="tag">' . $T('after_completion') . '</span>' : '');
        ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif;
        caseReportTaskHistories($b['subtasks']);
    }
}

/** One task_events row in words, using the task evidence report's own phrasing. */
function caseReportEvent(array $h): string
{
    $key = 'tasks.evidence.event_' . $h['event_type'];
    $text = t($key, ['from' => (string)($h['from_value'] ?? ''), 'to' => (string)($h['to_value'] ?? ''), 'who' => (string)($h['actor_name'] ?: t('case-report.by_system'))]);
    return $text === $key ? $h['event_type'] . ': ' . trim(($h['from_value'] ?? '') . ' → ' . ($h['to_value'] ?? ''), ' →') : $text;
}

/** "old → new", escaped, with an empty side shown as a dash. */
function caseReportChange($old, $new): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $o = ($old === null || $old === '') ? '-' : $e($old);
    $n = ($new === null || $new === '') ? '-' : $e($new);
    return $o === '-' ? $n : $o . ' → ' . $n;
}

/** Tasks in the report, counting subtasks; with $openOnly, only those not yet completed. */
function caseReportCountTasks(array $blocks, bool $openOnly = false): int
{
    $n = 0;
    foreach ($blocks as $b) {
        $closed = (int)($b['task']['status_closed'] ?? 0) === 1 || !empty($b['task']['completed_datetime']);
        if (!$openOnly || !$closed) $n++;
        $n += caseReportCountTasks($b['subtasks'], $openOnly);
    }
    return $n;
}

/** A stored recipient list (JSON or plain text) as "Name <address>, ...". */
function caseReportRecipients($raw): string
{
    $list = json_decode((string)$raw, true);
    if (!is_array($list)) return (string)$raw;
    $out = [];
    foreach ($list as $x) {
        if (is_string($x)) { $out[] = $x; continue; }
        $addr = $x['emailAddress']['address'] ?? $x['address'] ?? $x['email'] ?? '';
        $name = $x['emailAddress']['name'] ?? $x['name'] ?? '';
        $out[] = $name && $name !== $addr ? "$name <$addr>" : $addr;
    }
    return implode(', ', array_filter($out));
}

/** A byte count as a short size. */
function caseReportSize($bytes): string
{
    $b = (float)$bytes;
    if ($b <= 0) return '-';
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024 || $u === 'GB') return ($u === 'B' ? (int)$b : number_format($b, 1)) . ' ' . $u;
        $b /= 1024;
    }
    return '';
}

/** Where a document is attached, in words. */
function caseReportWhere(string $where): string
{
    if ($where === 'ticket') return t('case-report.where_ticket');
    if ($where === 'note') return t('case-report.where_note');
    if (str_starts_with($where, 'task:')) return t('case-report.where_task', ['id' => (int)substr($where, 5)]);
    return $where;
}
