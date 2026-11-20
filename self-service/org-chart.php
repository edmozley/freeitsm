<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal - Organisation chart (GH #414).
 *
 * Who reports to whom in the reader's own company: names, job titles and
 * departments, as an expandable tree with a search box. The analyst chart lives
 * in the People module (people/org.php); this is the portal's own, smaller view.
 *
 * TRAP: nothing here decides what somebody may see. It draws what
 * api/self-service/org_chart.php returns, and that endpoint is the rule - see
 * includes/portal_org_chart.php. The guard below runs BEFORE the header for the
 * reason my-equipment.php records: after it, the redirect has nothing to send.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_org_chart.php';

if (empty($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}
// A hidden nav link is not a permission.
if (!portalOrgChartAllowed(connectToDatabase(), (int)$_SESSION['ss_user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitleKey = 'self-service.org_chart.title';
$activeNav    = 'org_chart';
$translationNamespaces = ['common', 'self-service'];

$pageStyles = <<<'CSS'
.oc-wrap { width: 100%; box-sizing: border-box; padding: 24px 28px 48px; }
.oc-wrap h1 { font-size: var(--font-size-4xl, 24px); font-weight: 600; margin: 0 0 4px; color: var(--text, #333); }
.oc-sub { color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); margin: 0 0 18px; max-width: 640px; line-height: 1.55; }

.oc-search { position: relative; max-width: 420px; margin-bottom: 18px; }
.oc-search input {
    width: 100%; box-sizing: border-box; padding: 9px 12px; font-size: var(--font-size-lg, 14px);
    border: 1px solid var(--border, #d1d5db); border-radius: 6px;
    background: var(--surface, #fff); color: var(--text, #333);
}
.oc-results {
    position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 20; max-height: 320px; overflow-y: auto;
    background: var(--surface, #fff); border: 1px solid var(--border, #d1d5db); border-radius: 6px;
    box-shadow: 0 6px 18px var(--shadow, rgba(0,0,0,0.12));
}
.oc-results[hidden] { display: none; }
.oc-result {
    display: block; width: 100%; text-align: left; padding: 8px 12px; border: none; background: none;
    cursor: pointer; color: var(--text, #333); font: inherit; font-size: var(--font-size-md, 13px);
}
.oc-result:hover, .oc-result:focus { background: var(--surface-hover, #f3f4f6); outline: none; }
.oc-result small { display: block; color: var(--text-muted, #666); font-size: var(--font-size-sm, 12px); }
.oc-noresult { padding: 10px 12px; color: var(--text-muted, #666); font-size: var(--font-size-md, 13px); }

.oc-tree, .oc-tree ul { list-style: none; margin: 0; padding: 0; }
.oc-tree ul { margin-left: 13px; padding-left: 14px; border-left: 1px solid var(--border, #e5e7eb); }
.oc-node { display: flex; align-items: flex-start; gap: 6px; padding: 5px 8px 5px 0; border-radius: 6px; }
.oc-node.oc-flash { background: var(--accent-soft, rgba(84,110,122,0.14)); }
.oc-toggle {
    flex: 0 0 22px; width: 22px; height: 22px; margin-top: 1px; border: none; background: none; cursor: pointer;
    color: var(--text-muted, #666); border-radius: 4px; font-size: var(--font-size-xl, 16px); line-height: 22px; padding: 0;
}
.oc-toggle:hover { background: var(--surface-hover, #f3f4f6); }
/* A drawn chevron, not a glyph: the triangle characters render tiny in most portal fonts. */
.oc-toggle::before {
    content: ''; display: inline-block; width: 6px; height: 6px; margin-bottom: 2px;
    border-right: 2px solid currentColor; border-bottom: 2px solid currentColor;
    transform: rotate(-45deg); transition: transform .12s;
}
.oc-toggle[aria-expanded="true"]::before { transform: rotate(45deg); margin-bottom: 4px; }
.oc-spacer { flex: 0 0 22px; }
.oc-who { min-width: 0; }
.oc-name { font-size: var(--font-size-lg, 14px); font-weight: 600; color: var(--text, #333); word-break: break-word; }
.oc-meta { font-size: var(--font-size-md, 13px); color: var(--text-muted, #666); word-break: break-word; }
.oc-count, .oc-you {
    display: inline-block; margin-left: 6px; padding: 0 7px; border-radius: 10px; vertical-align: 1px;
    font-size: var(--font-size-xs, 11px); font-weight: 600; line-height: 18px;
}
.oc-count { background: var(--surface-2, #f1f5f9); color: var(--text-muted, #666); }
.oc-you { background: var(--accent, #546e7a); color: var(--on-accent, #fff); }
.oc-group > .oc-node .oc-name { font-weight: 500; color: var(--text-muted, #666); }

.oc-note, .oc-empty { color: var(--text-muted, #666); font-size: var(--font-size-md, 13px); line-height: 1.6; }
.oc-note { margin-top: 18px; }
.oc-empty {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px; padding: 28px; text-align: center; font-size: var(--font-size-lg, 14px);
}
@media (max-width: 600px) {
    .oc-wrap { padding: 16px 16px 40px; }
    .oc-tree ul { margin-left: 10px; padding-left: 8px; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="oc-wrap">
        <h1><?php echo htmlspecialchars(t('self-service.org_chart.title')); ?></h1>
        <p class="oc-sub"><?php echo htmlspecialchars(t('self-service.org_chart.subtitle')); ?></p>
        <?php /* Empty until there is something to say - see my-equipment.php. */ ?>
        <div id="ocBody"></div>
    </div>
<?php
$pageScripts = <<<'JS'
const ocEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
const OC = { byId: new Map(), kids: new Map(), parent: new Map(), roots: [], loners: [], me: 0 };

async function ocLoad() {
    try {
        const res  = await fetch('../api/self-service/org_chart.php', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { ocEmpty(window.t('self-service.org_chart.load_failed')); return; }
        ocBuild(data.people || [], Number(data.me) || 0);
        ocRender(!!data.truncated);
    } catch (e) {
        ocEmpty(window.t('self-service.org_chart.load_failed'));
    }
}

function ocEmpty(msg) {
    document.getElementById('ocBody').innerHTML = '<div class="oc-empty">' + ocEsc(msg) + '</div>';
}

// The tree from the manager ids. A manager the reader cannot see (a leaver, another
// company) leaves their team at the top. Somebody with neither a manager nor a team
// goes in one group at the end rather than a long row of single boxes at the top.
// Anybody only reachable through a loop in bad data joins that group too, so
// everybody on the list is somewhere on the page.
function ocBuild(people, me) {
    OC.me = me;
    people.forEach(p => { OC.byId.set(p.id, p); OC.kids.set(p.id, []); });
    people.forEach(p => {
        const m = p.manager_id;
        if (m && m !== p.id && OC.byId.has(m)) { OC.kids.get(m).push(p.id); OC.parent.set(p.id, m); }
    });
    const seen = new Set();
    const walk = id => { const stack = [id]; while (stack.length) { const n = stack.pop(); if (seen.has(n)) continue; seen.add(n); OC.kids.get(n).forEach(k => stack.push(k)); } };
    people.forEach(p => {
        if (OC.parent.has(p.id)) return;
        if (OC.kids.get(p.id).length) { OC.roots.push(p.id); walk(p.id); }
    });
    people.forEach(p => { if (!seen.has(p.id)) { OC.loners.push(p.id); OC.parent.delete(p.id); } });
    // A loop's members are in the group now: nobody inside it is anybody's child.
    OC.loners.forEach(id => { OC.kids.set(id, OC.kids.get(id).filter(k => !OC.loners.includes(k))); });
}

function ocRender(truncated) {
    if (!OC.byId.size) { ocEmpty(window.t('self-service.org_chart.none')); return; }
    const body = document.getElementById('ocBody');
    body.innerHTML =
        '<div class="oc-search"><input type="search" id="ocSearch" autocomplete="off" placeholder="' + ocEsc(window.t('self-service.org_chart.search')) + '" aria-label="' + ocEsc(window.t('self-service.org_chart.search')) + '">' +
        '<div class="oc-results" id="ocResults" hidden></div></div>' +
        '<ul class="oc-tree" id="ocTree"></ul>' +
        (truncated ? '<p class="oc-note">' + ocEsc(window.t('self-service.org_chart.truncated')) + '</p>' : '');
    const tree = document.getElementById('ocTree');
    OC.roots.forEach(id => tree.appendChild(ocItem(id)));
    if (OC.loners.length) {
        const li = document.createElement('li');
        li.className = 'oc-group';
        li.innerHTML = '<div class="oc-node"><button type="button" class="oc-toggle" aria-expanded="false" aria-label="' + ocEsc(window.t('self-service.org_chart.expand')) + '"></button>' +
            '<div class="oc-who"><span class="oc-name">' + ocEsc(window.t('self-service.org_chart.no_line')) + '</span><span class="oc-count">' + OC.loners.length + '</span></div></div>';
        li.dataset.group = '1';
        tree.appendChild(li);
    }
    tree.addEventListener('click', e => { const b = e.target.closest('.oc-toggle'); if (b) ocToggle(b.closest('li')); });

    // Opened on the reader's own place: their line expanded down to them. A
    // company with one person at the top opens that person's team as well.
    if (OC.roots.length === 1) ocOpen(ocLi(OC.roots[0]));
    if (OC.byId.has(OC.me)) ocReveal(OC.me, false);

    const input = document.getElementById('ocSearch');
    input.addEventListener('input', ocSearch);
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { input.value = ''; ocSearch(); }
        if (e.key === 'ArrowDown') { const f = document.querySelector('#ocResults .oc-result'); if (f) { e.preventDefault(); f.focus(); } }
    });
    document.getElementById('ocResults').addEventListener('click', e => {
        const b = e.target.closest('.oc-result');
        if (!b) return;
        input.value = '';
        ocSearch();
        ocReveal(Number(b.dataset.id), true);
    });
    document.addEventListener('click', e => { if (!e.target.closest('.oc-search')) document.getElementById('ocResults').hidden = true; });
}

function ocMeta(p) { return [p.job_title, p.department].filter(Boolean).join(' · '); }

function ocItem(id) {
    const p = OC.byId.get(id), kids = OC.kids.get(id);
    const li = document.createElement('li');
    li.dataset.id = id;
    const meta = ocMeta(p);
    li.innerHTML = '<div class="oc-node">' +
        (kids.length ? '<button type="button" class="oc-toggle" aria-expanded="false" aria-label="' + ocEsc(window.t('self-service.org_chart.expand')) + '"></button>' : '<span class="oc-spacer"></span>') +
        '<div class="oc-who"><span class="oc-name">' + ocEsc(p.name) + '</span>' +
        (id === OC.me ? '<span class="oc-you">' + ocEsc(window.t('self-service.org_chart.you')) + '</span>' : '') +
        (kids.length ? '<span class="oc-count" title="' + ocEsc(window.t('self-service.org_chart.reports', { count: kids.length })) + '">' + kids.length + '</span>' : '') +
        (meta ? '<div class="oc-meta">' + ocEsc(meta) + '</div>' : '') +
        '</div></div>';
    return li;
}

// Children are drawn the first time a person is opened, not up front: a company
// of thousands draws one level at a time.
function ocOpen(li) {
    if (!li) return;
    const btn = li.querySelector(':scope > .oc-node > .oc-toggle');
    if (!btn) return;
    let ul = li.querySelector(':scope > ul');
    if (!ul) {
        ul = document.createElement('ul');
        const ids = li.dataset.group ? OC.loners : OC.kids.get(Number(li.dataset.id));
        ids.forEach(k => ul.appendChild(ocItem(k)));
        li.appendChild(ul);
    }
    ul.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-label', window.t('self-service.org_chart.collapse'));
}

function ocToggle(li) {
    const btn = li.querySelector(':scope > .oc-node > .oc-toggle');
    if (btn.getAttribute('aria-expanded') === 'true') {
        li.querySelector(':scope > ul').hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', window.t('self-service.org_chart.expand'));
    } else {
        ocOpen(li);
    }
}

function ocLi(id) { return document.querySelector('#ocTree li[data-id="' + Number(id) + '"]'); }

// Opens every manager above a person, then brings them into view.
function ocReveal(id, flash) {
    const line = [];
    for (let cur = OC.parent.get(id), guard = 0; cur && guard < 200; cur = OC.parent.get(cur), guard++) line.unshift(cur);
    if (OC.loners.includes(id)) ocOpen(document.querySelector('#ocTree li.oc-group'));
    line.forEach(m => ocOpen(ocLi(m)));
    const li = ocLi(id);
    if (!li) return;
    const node = li.querySelector(':scope > .oc-node');
    if (flash) {
        node.scrollIntoView({ block: 'center', behavior: 'smooth' });
        node.classList.add('oc-flash');
        setTimeout(() => node.classList.remove('oc-flash'), 2400);
    }
}

function ocSearch() {
    const q = document.getElementById('ocSearch').value.trim().toLowerCase();
    const box = document.getElementById('ocResults');
    if (q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
    const hits = [];
    for (const p of OC.byId.values()) {
        if ((p.name + ' ' + (p.job_title || '') + ' ' + (p.department || '')).toLowerCase().includes(q)) hits.push(p);
        if (hits.length >= 25) break;
    }
    box.innerHTML = hits.length
        ? hits.map(p => '<button type="button" class="oc-result" data-id="' + p.id + '">' + ocEsc(p.name) +
              (ocMeta(p) ? '<small>' + ocEsc(ocMeta(p)) + '</small>' : '') + '</button>').join('')
        : '<div class="oc-noresult">' + ocEsc(window.t('self-service.org_chart.no_match')) + '</div>';
    box.hidden = false;
}

ocLoad();
JS;

require_once __DIR__ . '/includes/footer.php';
