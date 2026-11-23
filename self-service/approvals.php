<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal - Approvals (GH #160 Part C2, #348 slice 2).
 *
 * Where the requester's manager and a cost centre's owner (or its deputy) approve
 * catalogue requests, signed in as themselves. Budget holders are usually not
 * service desk staff, and a second account with a lot of screens for somebody who
 * approves a few laptops a month is the wrong answer.
 *
 * ⚠️ NOTHING HERE DECIDES WHAT SOMEBODY MAY SEE OR DECIDE. It draws what
 * api/self-service/approvals.php returns, and that asks, of each request's current
 * step and right then, whether this person may decide it. A request id in the address
 * (?id=, from the bell or the email) only scrolls to the card - it grants nothing.
 *
 * Named staff and teams keep deciding in Forms -> Approvals; that page is unchanged.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}

$pageTitleKey = 'self-service.approvals.title';
$activeNav    = 'approvals';
$translationNamespaces = ['common', 'self-service'];

$pageStyles = <<<'CSS'
.ap-wrap { width: 100%; box-sizing: border-box; padding: 24px 28px 48px; max-width: 980px; }
.ap-wrap h1 { font-size: var(--font-size-4xl, 24px); font-weight: 600; margin: 0 0 4px; color: var(--text, #333); }
.ap-sub { color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); margin: 0 0 18px; line-height: 1.55; }

.ap-tabs { display: flex; gap: 6px; margin: 0 0 16px; flex-wrap: wrap; }
.ap-tab { border: 1px solid var(--border, #e5e7eb); background: var(--surface, #fff); color: var(--text, #333);
          border-radius: 999px; padding: 6px 14px; font-size: var(--font-size-md, 13px); cursor: pointer; }
.ap-tab.active { background: var(--ss-accent, #0078d4); border-color: var(--ss-accent, #0078d4); color: var(--on-accent, #fff); }

.ap-away { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
           padding: 12px 16px; margin: 0 0 16px; font-size: var(--font-size-md, 13px); color: var(--text, #333);
           display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.ap-away .hint { color: var(--text-muted, #666); flex-basis: 100%; }
.ap-away input[type=date] { padding: 5px 8px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px;
                            background: var(--surface, #fff); color: var(--text, #333); }

.ap-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
           padding: 16px 18px; margin: 0 0 14px; }
.ap-card.ap-focus { border-color: var(--ss-accent, #0078d4); box-shadow: 0 0 0 2px var(--ss-accent-soft, #e6f0fa); }
.ap-card h2 { font-size: var(--font-size-xl, 16px); font-weight: 600; margin: 0 0 6px; color: var(--text, #333); }
.ap-meta { display: flex; flex-wrap: wrap; gap: 4px 18px; font-size: var(--font-size-md, 13px); color: var(--text, #333); margin: 0 0 10px; }
.ap-meta .lbl { color: var(--text-muted, #666); margin-right: 4px; }
.ap-as { font-size: var(--font-size-md, 13px); color: var(--text-muted, #666); margin: 0 0 10px; }
.ap-answers { display: grid; grid-template-columns: minmax(120px, max-content) 1fr; gap: 4px 16px;
              font-size: var(--font-size-md, 13px); margin: 0 0 12px; }
.ap-answers dt { color: var(--text-muted, #666); }
.ap-answers dd { margin: 0; color: var(--text, #333); word-break: break-word; white-space: pre-wrap; }
.ap-note { width: 100%; box-sizing: border-box; min-height: 60px; padding: 8px 10px; border: 1px solid var(--border, #e5e7eb);
           border-radius: 6px; font: inherit; font-size: var(--font-size-md, 13px); background: var(--surface, #fff); color: var(--text, #333); }
.ap-actions { display: flex; gap: 8px; margin-top: 8px; align-items: center; flex-wrap: wrap; }
.ap-actions small { color: var(--text-muted, #666); font-size: var(--font-size-sm, 12px); }
.ap-btn { border: 1px solid var(--border, #e5e7eb); border-radius: 6px; padding: 7px 16px; font-size: var(--font-size-md, 13px);
          cursor: pointer; background: var(--surface, #fff); color: var(--text, #333); }
.ap-btn:disabled { opacity: .6; cursor: default; }
.ap-btn.approve { background: var(--success-accent, #16a34a); border-color: var(--success-accent, #16a34a); color: var(--on-accent, #fff); }
.ap-btn.reject  { background: var(--danger-accent, #d13438); border-color: var(--danger-accent, #d13438); color: var(--on-accent, #fff); }
.ap-pill { display: inline-block; border-radius: 999px; padding: 2px 10px; font-size: var(--font-size-sm, 12px); font-weight: 600; }
.ap-pill.approved, .ap-pill.step_passed { background: var(--success-bg, #dcfce7); color: var(--success-text, #166534); }
.ap-pill.rejected { background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); }
.ap-pill.pending, .ap-pill.cancelled { background: var(--surface-2, #f3f4f6); color: var(--text-muted, #666); }
.ap-empty { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 28px;
            text-align: center; color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); line-height: 1.6; }
@media (max-width: 600px) {
    .ap-wrap { padding: 16px 16px 40px; }
    .ap-answers { grid-template-columns: 1fr; gap: 0 0; }
    .ap-answers dd { margin-bottom: 6px; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="ap-wrap">
        <h1><?php echo htmlspecialchars(t('self-service.approvals.title')); ?></h1>
        <p class="ap-sub"><?php echo htmlspecialchars(t('self-service.approvals.subtitle')); ?></p>
        <div class="ap-tabs" role="tablist">
            <button type="button" class="ap-tab active" data-filter="mine" onclick="apSetFilter('mine')"></button>
            <button type="button" class="ap-tab" data-filter="decided" onclick="apSetFilter('decided')"></button>
        </div>
        <div id="apAway" class="ap-away" style="display:none"></div>
        <?php /* Empty until there is something to say - see my-equipment.php. */ ?>
        <div id="apList"></div>
    </div>
<?php
$pageScripts = <<<'JS'
const apEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
const apT = (k, p) => window.t('self-service.approvals.' + k, p);
let apFilter = 'mine';
const apFocusId = Number(new URLSearchParams(location.search).get('id')) || 0;
let apFocused = false;

function apSetFilter(f) {
    apFilter = f;
    document.querySelectorAll('.ap-tab').forEach(b => b.classList.toggle('active', b.dataset.filter === f));
    apLoad();
}

function apDate(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T') + 'Z');
    return isNaN(d) ? s : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

async function apLoad() {
    try {
        const res = await fetch('../api/self-service/approvals.php?filter=' + apFilter, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { apEmpty(apT('load_failed')); return; }
        const tabs = document.querySelectorAll('.ap-tab');
        tabs[0].textContent = apT('tab_mine', { n: data.counts.mine });
        tabs[1].textContent = apT('tab_decided', { n: data.counts.decided });
        apAway(data);
        apRender(data.items || []);
    } catch (e) {
        apEmpty(apT('load_failed'));
    }
}

function apEmpty(msg) {
    document.getElementById('apList').innerHTML = '<div class="ap-empty">' + apEsc(msg) + '</div>';
}

/** "Away until" - only when the deputy is cover, the one case it changes anything. */
function apAway(data) {
    const box = document.getElementById('apAway');
    if (data.deputy_mode !== 'cover') { box.style.display = 'none'; return; }
    box.style.display = '';
    const today = new Date().toISOString().slice(0, 10);
    box.innerHTML = data.away_until
        ? '<span>' + apEsc(apT('away_on', { date: apDate(data.away_until) })) + '</span>'
          + '<button type="button" class="ap-btn" onclick="apSetAway(\'\')">' + apEsc(apT('away_back')) + '</button>'
          + '<span class="hint">' + apEsc(apT('away_hint')) + '</span>'
        : '<label for="apAwayDate">' + apEsc(apT('away_label')) + '</label>'
          + '<input type="date" id="apAwayDate" min="' + today + '">'
          + '<button type="button" class="ap-btn" onclick="apSetAway(document.getElementById(\'apAwayDate\').value)">' + apEsc(apT('away_save')) + '</button>'
          + '<span class="hint">' + apEsc(apT('away_hint')) + '</span>';
}

async function apSetAway(until) {
    try {
        const res = await fetch('../api/self-service/approvals.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ action: 'away', until: until })
        });
        const data = await res.json();
        if (!data.success) { showToast(data.error || apT('save_failed'), 'error'); return; }
        showToast(data.away_until ? apT('away_saved', { date: apDate(data.away_until) }) : apT('away_cleared'), 'success');
        apLoad();
    } catch (e) { showToast(apT('save_failed'), 'error'); }
}

function apAsText(it) {
    if (it.as === 'manager') return apT('as_manager');
    if (it.as === 'owner') return apT('as_owner', { cc: it.step_cost_centre });
    if (it.as === 'deputy') return apT('as_deputy', { cc: it.step_cost_centre });
    return '';
}

function apRender(items) {
    if (!items.length) { apEmpty(apT(apFilter === 'mine' ? 'none_mine' : 'none_decided')); return; }
    document.getElementById('apList').innerHTML = items.map(it => {
        const meta = [
            [apT('requester'), it.requester],
            [apT('submitted'), apDate(it.submitted_date)],
            it.price_label ? [window.t('common.catalogue_price.label'), it.price_label] : null,
            it.cost_centre ? [apT('cost_centre'), it.cost_centre] : null,
            it.step && it.status === 'pending' && it.step.count > 1
                ? [apT('step'), apT(it.step.name ? 'step_of_named' : 'step_of', { p: it.step.position, n: it.step.count, name: it.step.name })] : null,
        ].filter(Boolean).map(m => '<span><span class="lbl">' + apEsc(m[0]) + '</span>' + apEsc(m[1]) + '</span>').join('');
        const answers = (it.answers || []).length
            ? '<dl class="ap-answers">' + it.answers.map(a => '<dt>' + apEsc(a.label) + '</dt><dd>' + apEsc(a.value || '-') + '</dd>').join('') + '</dl>'
            : '';
        let foot;
        if (apFilter === 'mine' && it.status === 'pending') {
            foot = '<textarea class="ap-note" id="apNote' + it.id + '" maxlength="2000" placeholder="' + apEsc(apT('note_ph')) + '"></textarea>'
                + '<div class="ap-actions">'
                + '<button type="button" class="ap-btn approve" onclick="apDecide(' + it.id + ', \'approved\', this)">' + apEsc(apT('approve')) + '</button>'
                + '<button type="button" class="ap-btn reject" onclick="apDecide(' + it.id + ', \'rejected\', this)">' + apEsc(apT('reject')) + '</button>'
                + '<small>' + apEsc(apT('reject_hint')) + '</small></div>';
        } else {
            const st = it.status === 'pending' ? 'step_passed' : it.status;
            foot = '<div class="ap-actions"><span class="ap-pill ' + apEsc(st) + '">' + apEsc(apT('status_' + st)) + '</span>'
                + (it.ticket_number ? '<span>' + apEsc(it.ticket_number) + '</span>' : '')
                + (it.decided_date ? '<small>' + apEsc(apDate(it.decided_date)) + '</small>' : '') + '</div>'
                + (it.comment ? '<p class="ap-as">' + apEsc(it.comment) + '</p>' : '');
        }
        const as = apFilter === 'mine' ? apAsText(it) : '';
        return '<article class="ap-card' + (it.id === apFocusId ? ' ap-focus' : '') + '" id="apCard' + it.id + '">'
            + '<h2>' + apEsc(it.form_title) + '</h2>'
            + '<div class="ap-meta">' + meta + '</div>'
            + (as ? '<p class="ap-as">' + apEsc(as) + '</p>' : '')
            + answers + foot + '</article>';
    }).join('');
    if (apFocusId && !apFocused) {
        const card = document.getElementById('apCard' + apFocusId);
        if (card) { card.scrollIntoView({ block: 'start', behavior: 'smooth' }); apFocused = true; }
    }
}

async function apDecide(id, decision, btn) {
    const note = (document.getElementById('apNote' + id) || {}).value || '';
    if (decision === 'rejected' && !note.trim()) { showToast(apT('reason_needed'), 'error'); return; }
    const card = btn.closest('.ap-card');
    card.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
        const res = await fetch('../api/self-service/approvals.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ action: 'decide', submission_id: id, decision: decision, comment: note })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || apT('decide_failed'), 'error');
            card.querySelectorAll('button').forEach(b => b.disabled = false);
            return;
        }
        showToast(data.decision === 'step_passed' ? apT('passed_toast')
                : data.decision === 'approved' ? apT('approved_toast') : apT('rejected_toast'), 'success');
        apLoad();
    } catch (e) {
        showToast(apT('decide_failed'), 'error');
        card.querySelectorAll('button').forEach(b => b.disabled = false);
    }
}

apLoad();
JS;

require_once __DIR__ . '/includes/footer.php';
