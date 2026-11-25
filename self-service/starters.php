<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal - Starters & leavers cases (5.11.0), for HR.
 *
 * Read-only: who is joining, moving or leaving in the person's own company, the date,
 * how many steps are done and who owns the case. Off unless an administrator gives it
 * to chosen People groups (System -> Self-service portal). See
 * includes/portal_starters.php for what is shown and why the rest is not.
 *
 * ⚠️ NOTHING HERE DECIDES WHAT SOMEBODY MAY SEE. It draws what
 * api/self-service/starters.php returns, and that asks portalStartersAllowed().
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_starters.php';

if (empty($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}
// A hidden nav link is not a permission.
if (!portalStartersAllowed(connectToDatabase(), (int)$_SESSION['ss_user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitleKey = 'self-service.starters.title';
$activeNav    = 'starters';
$translationNamespaces = ['common', 'self-service', 'starters'];

$pageStyles = <<<'CSS'
.jp-wrap { width: 100%; box-sizing: border-box; padding: 24px 28px 48px; max-width: 980px; }
.jp-wrap h1 { font-size: var(--font-size-4xl, 24px); font-weight: 600; margin: 0 0 4px; color: var(--text, #333); }
.jp-sub { color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); margin: 0 0 18px; line-height: 1.55; }
.jp-tabs { display: flex; gap: 6px; margin: 0 0 16px; flex-wrap: wrap; }
.jp-tab { border: 1px solid var(--border, #e5e7eb); background: var(--surface, #fff); color: var(--text, #333);
          border-radius: 999px; padding: 6px 14px; font-size: var(--font-size-md, 13px); cursor: pointer; }
.jp-tab.active { background: var(--ss-accent, #0078d4); border-color: var(--ss-accent, #0078d4); color: var(--on-accent, #fff); }
.jp-list { display: flex; flex-direction: column; gap: 10px; }
.jp-case { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 14px 18px;
           display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; align-items: center; }
.jp-name { font-size: var(--font-size-xl, 16px); font-weight: 600; color: var(--text, #333); }
.jp-job { color: var(--text-muted, #666); font-size: var(--font-size-md, 13px); }
.jp-meta { display: flex; flex-wrap: wrap; gap: 4px 16px; font-size: var(--font-size-md, 13px); color: var(--text, #333); grid-column: 1 / -1; }
.jp-meta .lbl { color: var(--text-muted, #666); margin-right: 4px; }
.jp-kind { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: var(--font-size-sm, 12px); font-weight: 600;
           border: 1px solid var(--border, #e5e7eb); color: var(--text, #333); background: var(--surface-2, #f7f8fa); }
.jp-progress { min-width: 180px; text-align: right; font-size: var(--font-size-md, 13px); color: var(--text-muted, #666); }
.jp-bar { height: 6px; background: var(--surface-2, #eef0f3); border-radius: 3px; overflow: hidden; margin-top: 4px; }
.jp-bar > span { display: block; height: 100%; background: var(--ss-accent, #0078d4); }
.jp-empty { background: var(--surface, #fff); border: 1px dashed var(--border, #e5e7eb); border-radius: 8px; padding: 28px;
            text-align: center; color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); line-height: 1.6; }
@media (max-width: 600px) {
    .jp-wrap { padding: 16px 16px 32px; }
    .jp-case { grid-template-columns: 1fr; }
    .jp-progress { text-align: left; min-width: 0; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="jp-wrap">
        <h1><?php echo htmlspecialchars(t('self-service.starters.title')); ?></h1>
        <p class="jp-sub"><?php echo htmlspecialchars(t('self-service.starters.subtitle')); ?></p>
        <div class="jp-tabs" role="tablist">
            <button type="button" class="jp-tab active" data-view="open" onclick="jpSetView('open')"></button>
            <button type="button" class="jp-tab" data-view="done" onclick="jpSetView('done')"></button>
        </div>
        <div id="jpList" class="jp-list"></div>
    </div>
<?php
$pageScripts = <<<'JS'
const jpEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
const jpT = (k, p) => window.t('self-service.starters.' + k, p);
let jpView = 'open';

function jpSetView(v) {
    jpView = v;
    document.querySelectorAll('.jp-tab').forEach(b => b.classList.toggle('active', b.dataset.view === v));
    jpLoad();
}
function jpDate(ymd) {
    const d = new Date(String(ymd) + 'T00:00:00');
    return isNaN(d) ? ymd : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}
function jpEmpty(msg) { document.getElementById('jpList').innerHTML = '<div class="jp-empty">' + jpEsc(msg) + '</div>'; }

async function jpLoad() {
    try {
        const res = await fetch('../api/self-service/starters.php?view=' + jpView, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { jpEmpty(jpT('load_failed')); return; }
        const tabs = document.querySelectorAll('.jp-tab');
        tabs[0].textContent = jpT('tab_open', { n: data.counts.open });
        tabs[1].textContent = jpT('tab_done', { n: data.counts.done });
        if (!data.cases.length) { jpEmpty(jpT(jpView === 'done' ? 'none_done' : 'none_open')); return; }
        document.getElementById('jpList').innerHTML = data.cases.map(c => {
            const pct = c.steps_total ? Math.round(100 * c.steps_done / c.steps_total) : 0;
            const progress = c.steps_total
                ? jpEsc(jpT('progress', { done: c.steps_done, total: c.steps_total })) + '<div class="jp-bar"><span style="width:' + pct + '%"></span></div>'
                : jpEsc(jpT('no_steps'));
            return '<div class="jp-case">'
                 + '<div><div class="jp-name">' + jpEsc(c.person_name) + '</div>' + (c.job_title ? '<div class="jp-job">' + jpEsc(c.job_title) + '</div>' : '') + '</div>'
                 + '<div class="jp-progress">' + progress + '</div>'
                 + '<div class="jp-meta">'
                 +   '<span class="jp-kind">' + jpEsc(window.t('starters.kind.' + c.kind)) + '</span>'
                 +   '<span><span class="lbl">' + jpEsc(window.t('starters.date_label.' + c.kind)) + '</span>' + jpEsc(jpDate(c.effective_date)) + '</span>'
                 +   (c.owner_name ? '<span><span class="lbl">' + jpEsc(jpT('owner')) + '</span>' + jpEsc(c.owner_name) + '</span>' : '')
                 + '</div></div>';
        }).join('');
    } catch (e) {
        jpEmpty(jpT('load_failed'));
    }
}
jpLoad();
JS;
require_once __DIR__ . '/includes/footer.php';
