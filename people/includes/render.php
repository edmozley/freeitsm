<?php
/**
 * People — the section cards a person page and a company page share.
 *
 * Server-rendered: the page is one read of includes/people.php, and every
 * section in it has already been through its module's access and company
 * checks. A section the reader may not see is simply not in $sections, so
 * nothing here has to know about permissions.
 */

const PPL_VISIBLE_ROWS = 10;

function pplE($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pplUrl(?string $rel): string { return $rel === null ? '#' : BASE_URL . $rel; }

/** The coloured strip of counts across the top of a page. */
function pplStats(array $sections, bool $person, array $labels = []): string
{
    $out = '';
    // $labels renames a section's figure - a supplier's contracts are not "as customer".
    $add = function (string $key, string $label, string $value, string $class = '') use (&$out, $labels) {
        $label = $labels[$key] ?? $label;
        $out .= '<a class="ppl-stat ' . $class . '" href="#sec-' . $key . '"><span class="v">' . pplE($value) . '</span><span class="l">' . pplE($label) . '</span></a>';
    };
    if (isset($sections['people']))    $add('people', t('people.company.people'), (string)count($sections['people']));
    if (isset($sections['contacts']))  $add('contacts', t('people.supplier.contacts'), (string)count($sections['contacts']));
    if (isset($sections['tickets']))   $add('tickets', t('people.section.tickets'), t('people.section.tickets_open', ['open' => $sections['tickets']['open'], 'total' => $sections['tickets']['total']]), $sections['tickets']['open'] > 0 ? 'accent' : '');
    if (isset($sections['assets']))    $add('assets', t($person ? 'people.section.assets_person' : 'people.section.assets'), (string)$sections['assets']['total']);
    if (isset($sections['contracts'])) $add('contracts', t('people.section.contracts'), (string)$sections['contracts']['total']);
    if (isset($sections['domains']))   $add('domains', t($person ? 'people.section.domains_person' : 'people.section.domains'), (string)$sections['domains']['total']);
    if (isset($sections['courses']))   $add('courses', t('people.section.courses'), (string)$sections['courses']['total']);
    if (isset($sections['forms']))     $add('forms', t('people.section.forms'), (string)$sections['forms']['total']);
    if (isset($sections['tasks']))     $add('tasks', t('people.section.contractor_tasks'), t('people.section.tickets_open', ['open' => $sections['tasks']['open'], 'total' => $sections['tasks']['total']]), $sections['tasks']['open'] > 0 ? 'accent' : '');
    if (isset($sections['projects']))  $add('projects', t($person ? 'people.section.projects_person' : 'people.section.projects'), (string)$sections['projects']['total']);
    return $out === '' ? '' : '<div class="ppl-stats">' . $out . '</div>';
}

/** One section card: a heading with its count, then a table or "Nothing yet". */
function pplCard(string $key, string $title, int $count, array $head, array $rows, bool $more = false, string $extra = ''): string
{
    $h = '<section class="ppl-card" id="sec-' . pplE($key) . '"><div class="ppl-card-h"><h3>' . pplE($title) . '</h3><span class="ppl-count">' . $count . '</span>' . $extra . '</div>';
    if (!$rows) return $h . '<div class="ppl-empty">' . pplE(t('people.section.empty')) . '</div></section>';
    $h .= '<div class="ppl-table-wrap"><table class="ppl-table"><thead><tr>';
    foreach ($head as $th) $h .= '<th>' . pplE($th) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $n => $cells) {
        $h .= $n >= PPL_VISIBLE_ROWS ? '<tr class="ppl-extra" hidden>' : '<tr>';
        foreach ($cells as $i => $c) $h .= '<td data-label="' . pplE($head[$i] ?? '') . '">' . $c . '</td>';
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';
    if (count($rows) > PPL_VISIBLE_ROWS) {
        $h .= '<button type="button" class="ppl-showall" onclick="pplShowAll(this)">' . pplE(t('people.section.show_all', ['n' => count($rows)])) . '</button>';
    }
    if ($more) $h .= '<div class="ppl-more">' . pplE(t('people.section.more', ['n' => PEOPLE_SECTION_LIMIT])) . '</div>';
    return $h . '</section>';
}

function pplLink(?string $url, string $text): string
{
    return $url === null ? pplE($text) : '<a href="' . pplE(pplUrl($url)) . '">' . pplE($text) . '</a>';
}

function pplPill(?string $text, ?string $colour = null, string $class = ''): string
{
    if ($text === null || $text === '') return '';
    $style = $colour ? ' style="--pill:' . pplE($colour) . '"' : '';
    return '<span class="ppl-pill ' . $class . ($colour ? ' coloured' : '') . '"' . $style . '>' . pplE($text) . '</span>';
}

function pplSectionTickets(array $s, bool $person): string
{
    $head = [t('people.section.col_number'), t('people.section.col_subject'), t('people.section.col_status'), t('people.section.col_priority')];
    $head[] = $person ? t('people.section.col_analyst') : t('people.section.col_requester');
    $head[] = t('people.section.col_raised');
    $rows = array_map(fn($r) => [
        '<span class="ppl-ref">' . pplLink($r['url'], $r['number'] ?: '#' . $r['id']) . '</span>',
        pplE($r['subject']),
        pplPill($r['status'], $r['status_colour']),
        pplE($r['priority']),
        pplE($person ? $r['analyst'] : $r['requester']),
        pplE(fmt_date($r['created'])),
    ], $s['rows']);
    $extra = '<span class="ppl-sub">' . pplE(t('people.section.tickets_open', ['open' => $s['open'], 'total' => $s['total']])) . '</span>';
    return pplCard('tickets', t('people.section.tickets'), $s['total'], $head, $rows, $s['total'] > count($s['rows']), $extra);
}

function pplSectionAssets(array $s, bool $person, ?string $title = null): string
{
    $head = [t('people.section.col_asset'), t('people.section.col_type'), t('people.section.col_model'), t('people.section.col_status')];
    $head[] = $person ? t('people.section.col_since') : t('people.section.col_holder');
    $rows = array_map(fn($r) => [
        pplLink($r['url'], $r['name']),
        pplE($r['type']),
        pplE($r['model']),
        pplE($r['status']),
        pplE($person ? fmt_date($r['assigned']) : ($r['holder'] ?? '')),
    ], $s['rows']);
    return pplCard('assets', $title ?? t($person ? 'people.section.assets_person' : 'people.section.assets'), $s['total'], $head, $rows, $s['total'] > count($s['rows']));
}

function pplSectionContracts(array $s, bool $withParty = true, ?string $title = null): string
{
    // A supplier's own page leaves out "With" - every row would name the supplier.
    $head = $withParty
        ? [t('people.section.col_contract'), t('people.section.col_with'), t('people.section.col_status'), t('people.section.col_ends')]
        : [t('people.section.col_contract'), t('people.section.col_status'), t('people.section.col_ends')];
    $rows = array_map(function ($r) use ($withParty) {
        $cells = [pplLink($r['url'], trim(($r['number'] ? $r['number'] . ' - ' : '') . $r['title'])) . ($r['is_active'] ? '' : ' ' . pplPill(t('people.section.inactive'), null, 'muted'))];
        if ($withParty) $cells[] = pplE($r['party']);
        $cells[] = pplE($r['status']);
        $cells[] = pplE(peopleBareDate($r['end']));
        return $cells;
    }, $s['rows']);
    return pplCard('contracts', $title ?? t('people.section.contracts'), $s['total'], $head, $rows);
}

function pplSectionDomains(array $s, bool $person, ?string $title = null): string
{
    // Supplier and contact pages say HOW each domain involves them (#162).
    $roles = $s['rows'] && array_key_exists('roles', $s['rows'][0]);
    $head = [t('people.section.col_domain')];
    if ($roles) $head[] = t('people.section.col_as');
    array_push($head, t('people.section.col_status'), t('people.section.col_expires'), t('people.section.col_grade'));
    $rows = array_map(function ($r) use ($roles) {
        $cells = [pplLink($r['url'], $r['name']) . ($r['name'] !== $r['domain'] ? '<div class="ppl-dim">' . pplE($r['domain']) . '</div>' : '')];
        if ($roles) $cells[] = implode(' ', array_map(fn($x) => pplPill(t('people.section.domain_role.' . $x), null, 'muted'), $r['roles']));
        array_push($cells, pplPill($r['status'], $r['status_colour']), pplE(peopleBareDate($r['expiry'])), pplE($r['grade']));
        return $cells;
    }, $s['rows']);
    return pplCard('domains', $title ?? t($person ? 'people.section.domains_person' : 'people.section.domains'), $s['total'], $head, $rows);
}

function pplSectionCoursesPerson(array $s): string
{
    $head = [t('people.section.col_course'), t('people.section.col_status'), t('people.section.col_progress'), t('people.section.col_deadline')];
    $rows = array_map(function ($r) {
        $status = t('people.section.course_status.' . $r['status']);
        if (str_starts_with($status, 'people.')) $status = $r['status'];
        $done = in_array($r['status'], ['completed', 'passed'], true);
        $progress = $done ? pplE(fmt_date($r['completed'])) : ($r['lesson_count'] > 0 ? pplE(t('people.section.lesson_of', ['n' => $r['lesson_position'], 'total' => $r['lesson_count']])) : '');
        return [
            pplE($r['title']),
            pplPill($status, null, $done ? 'good' : ($r['is_overdue'] ? 'bad' : '')),
            $progress,
            pplE(peopleBareDate($r['deadline'])) . ($r['is_overdue'] ? ' ' . pplPill(t('people.section.overdue'), null, 'bad') : ''),
        ];
    }, $s['rows']);
    return pplCard('courses', t('people.section.courses'), $s['total'], $head, $rows);
}

function pplSectionCoursesCompany(array $s): string
{
    $head = [t('people.section.col_course'), t('people.section.col_finished'), t('people.section.col_in_progress')];
    $rows = array_map(fn($r) => [pplE($r['title']), (string)$r['finished'], (string)$r['in_progress']], $s['rows']);
    return pplCard('courses', t('people.section.courses'), $s['total'], $head, $rows);
}

function pplSectionForms(array $s, bool $person): string
{
    $head = [t('people.section.col_form'), t('people.section.col_submitted')];
    if (!$person) $head[] = t('people.section.col_by');
    array_push($head, t('people.section.col_approval'), t('people.section.col_ticket'));
    $rows = array_map(function ($r) use ($person) {
        $cells = [pplLink($r['url'], $r['form']), pplE(fmt_datetime($r['submitted']))];
        if (!$person) $cells[] = pplE($r['submitter']);
        $cells[] = pplE($r['approval'] ? ucfirst(str_replace('_', ' ', $r['approval'])) : '');
        $cells[] = $r['ticket'] ? pplLink($r['ticket_url'], $r['ticket']) : '';
        return $cells;
    }, $s['rows']);
    return pplCard('forms', t('people.section.forms'), $s['total'], $head, $rows, $s['total'] >= PEOPLE_SECTION_LIMIT);
}

/**
 * A person's RACI duties on one project, one line per letter: "Accountable for
 * Phones working on day one, Every desk patched and 2 more". The full list is
 * the line's tooltip.
 */
function pplRaciDuties(array $duties): string
{
    $h = '';
    foreach ($duties as $letter => $titles) {
        $shown = array_slice($titles, 0, 2);
        $items = implode(', ', $shown) . (count($titles) > 2 ? ' ' . t('people.section.duty_more', ['n' => count($titles) - 2]) : '');
        $h .= '<div class="ppl-dim" title="' . pplE(implode("\n", $titles)) . '">'
            . pplE(t('people.section.duty', ['role' => t('projects.raci.' . strtolower($letter)), 'items' => $items])) . '</div>';
    }
    return $h;
}

/** Projects (3.2.0): a person's memberships with their role and RACI duties, or a company's projects. */
function pplSectionProjects(array $s, bool $person): string
{
    $head = [t('people.section.col_project')];
    if ($person) $head[] = t('people.section.col_role');
    array_push($head, t('people.section.col_status'), t('people.section.col_progress'), t('people.section.col_owner'), t('people.section.col_target'));
    $health = ['green' => 'good', 'amber' => '', 'red' => 'bad'];
    $rows = array_map(function ($r) use ($person, $health) {
        $status = t('projects.status.' . $r['status']);
        $cells = ['<span class="ppl-swatch" style="background:' . pplE($r['colour']) . '"></span>' . pplLink($r['url'], $r['name']) . '<div class="ppl-dim">' . pplE($r['code']) . '</div>'];
        if ($person) $cells[] = pplE($r['role'] ?? '') . pplRaciDuties($r['duties'] ?? []);
        array_push($cells,
            pplPill($status, null, $health[$r['health']] ?? 'muted'),
            pplE($r['progress'] . '%'),
            pplE($r['owner'] ?? ''),
            pplE(peopleBareDate($r['target_end_date'])));
        return $cells;
    }, $s['rows']);
    return pplCard('projects', t($person ? 'people.section.projects_person' : 'people.section.projects'), $s['total'], $head, $rows, $s['total'] >= PEOPLE_SECTION_LIMIT);
}

/** Contractors (3.3.0): the tasks given to a supplier, or to one person there. */
function pplSectionContractorTasks(array $s, bool $byContact): string
{
    $head = [t('people.section.col_task'), t('people.section.col_status'), t('people.section.col_due'), t('people.section.col_project')];
    if (!$byContact) $head[] = t('people.section.col_contact');
    $head[] = t('people.section.col_chased_by');
    $rows = array_map(function ($r) use ($byContact) {
        $cells = [pplLink($r['url'], $r['title']), pplPill((string)$r['status'], $r['status_colour']),
                  pplE(peopleBareDate($r['due_date'])) . ($r['late'] ? ' ' . pplPill(t('people.section.overdue'), null, 'bad') : ''),
                  $r['project'] ? pplLink($r['project_url'], $r['project']) : ''];
        if (!$byContact) $cells[] = pplE($r['contact'] ?? '');
        $cells[] = pplE($r['owner'] ?? '');
        return $cells;
    }, $s['rows']);
    return pplCard('tasks', t('people.section.contractor_tasks'), $s['total'], $head, $rows, $s['total'] >= PEOPLE_SECTION_LIMIT);
}

/** Every section present, in a fixed order. */
function pplSections(array $sections, bool $person): string
{
    $h = '';
    if (isset($sections['tickets']))   $h .= pplSectionTickets($sections['tickets'], $person);
    if (isset($sections['assets']))    $h .= pplSectionAssets($sections['assets'], $person);
    if (isset($sections['contracts'])) $h .= pplSectionContracts($sections['contracts']);
    if (isset($sections['domains']))   $h .= pplSectionDomains($sections['domains'], $person);
    if (isset($sections['courses']))   $h .= $person ? pplSectionCoursesPerson($sections['courses']) : pplSectionCoursesCompany($sections['courses']);
    if (isset($sections['forms']))     $h .= pplSectionForms($sections['forms'], $person);
    if (isset($sections['projects']))  $h .= pplSectionProjects($sections['projects'], $person);
    return $h;
}

/** The <head> every People page shares. $extraCss: page stylesheets, linked
 *  BEFORE mobile.css so the mobile layer still wins its ties (Techniques §9). */
function pplHead(string $title, array $namespaces = ['common', 'people'], array $extraCss = []): void
{
    ?>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo pplE(systemName() . ' - ' . $title); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($namespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/people.css?v=5">
    <?php foreach ($extraCss as $css): ?><link rel="stylesheet" href="<?php echo BASE_URL . pplE($css); ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=192">
    <script>function pplShowAll(b) { b.closest('.ppl-card').querySelectorAll('tr.ppl-extra').forEach(function (r) { r.hidden = false; }); b.remove(); }</script>
    <?php
}

/** Two letters for an avatar. */
function pplInitials(string $name): string
{
    $parts = preg_split('/[\s@._-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) $i .= mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
    return $i;
}
