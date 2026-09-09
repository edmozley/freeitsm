<?php
/**
 * Self-Service Portal — My training.
 *
 * The portal end of the LMS: the courses somebody has been given, and where
 * they got to. The data comes from api/lms/my_courses.php, the SAME endpoint the
 * analyst My Courses page uses — the two views are the same question asked by
 * two different kinds of person, and lmsMyCourses() answers it once so they
 * cannot drift apart.
 *
 * ⚠️ NOTHING HERE DECIDES WHAT SOMEBODY MAY SEE. The endpoint returns only what
 * is assigned to the caller, and course.php re-checks on the way in, so a
 * hand-typed course id gets nowhere. This page draws what it is given.
 */
$pageTitleKey = 'self-service.training.title';
$activeNav    = 'training';
$translationNamespaces = ['common', 'self-service'];

$pageStyles = <<<'CSS'
.tr-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 20px 48px; }
.tr-wrap h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; color: var(--text, #333); }
.tr-sub { color: var(--text-muted, #666); font-size: 14px; margin: 0 0 22px; }

.tr-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

.tr-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 18px;
    /* A column, so the status row and the button can be pinned to the foot with
       margin-top:auto. Descriptions are real sentences of different lengths, and
       left to follow the text every button sat at a different height. The same
       fix the analyst My Courses cards needed. */
    display: flex;
    flex-direction: column;
}

.tr-card h2 { font-size: 16px; font-weight: 600; margin: 0 0 6px; color: var(--text, #333); }
.tr-desc { font-size: 13px; color: var(--text-muted, #666); margin: 0 0 14px; line-height: 1.5; }

.tr-foot { margin-top: auto; }

.tr-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }

.tr-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid transparent;
}
.tr-badge.ok      { background: var(--success-bg, #e8f5e9); color: var(--success-text, #1b5e20); border-color: var(--success-border, #c8e6c9); }
.tr-badge.warn    { background: var(--warning-bg, #fff8e1); color: var(--warning-text, #8d6e00); border-color: var(--warning-border, #ffe082); }
.tr-badge.bad     { background: var(--danger-bg, #fdecea); color: var(--danger-text, #b3261e); border-color: var(--danger-border, #f5c6c2); }
.tr-badge.neutral { background: var(--surface-3, #f5f6f8); color: var(--text-muted, #666); border-color: var(--border, #e5e7eb); }

.tr-due { font-size: 12px; color: var(--text-muted, #666); }
.tr-due.over { color: var(--danger-text, #b3261e); font-weight: 600; }

/* The bar always carries words beside it. Nothing stores a percentage — the
   position in the lesson list is the honest reading, so it is the one shown. */
.tr-bar { height: 6px; border-radius: 3px; background: var(--surface-3, #eef0f3); overflow: hidden; margin-bottom: 6px; }
.tr-bar-fill { height: 100%; background: var(--ss-accent, var(--accent, #0078d4)); }
.tr-step { font-size: 12px; color: var(--text-muted, #666); margin-bottom: 12px; display: block; }

.tr-btn {
    display: inline-block;
    width: 100%;
    box-sizing: border-box;
    text-align: center;
    padding: 9px 14px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    background: var(--ss-accent, var(--accent, #0078d4));
    color: var(--ss-on-accent, var(--on-accent, #fff));
}
.tr-btn:hover { background: var(--ss-accent-hover, var(--accent-hover, #106ebe)); }

.tr-empty {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted, #666);
}

@media (max-width: 640px) {
    .tr-wrap { padding: 16px 14px 32px; }
    .tr-grid { grid-template-columns: 1fr; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="tr-wrap">
        <h1><?php echo htmlspecialchars(t('self-service.training.heading')); ?></h1>
        <p class="tr-sub"><?php echo htmlspecialchars(t('self-service.training.subtitle')); ?></p>
        <div id="trBody">
            <div class="tr-empty"><?php echo htmlspecialchars(t('self-service.training.loading')); ?></div>
        </div>
    </div>
<?php
$pageScripts = <<<'JS'
// The LMS endpoints live under their own module, not the portal's, because the
// question ("what am I assigned?") and the answer are identical for an analyst
// and a portal user. API_BASE from footer.php points at the portal's own API, so
// this page names its own.
const LMS_API = '../api/lms/';

/* 🔴 EVERY LMS CALL FROM THE PORTAL SAYS SO. The analyst app and the portal are
   the same host and share one PHP session, so an administrator signed into both
   has two identities available and the server cannot tell from the session which
   one is acting. Without this, THIS PAGE showed the administrator's own ten
   courses under the heading "Courses you have been asked to complete" — measured,
   and reported by Ed with two tabs open. It can only ever select between
   identities this session has already proven; it cannot name somebody else. */
const LMS_AS = '?as=portal';

const trEsc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

function trBadge(row) {
    if (row.is_overdue) return ['bad', window.t('self-service.training.status.overdue')];
    switch (row.status) {
        case 'passed':    return ['ok',      window.t('self-service.training.status.passed')];
        case 'completed': return ['ok',      window.t('self-service.training.status.completed')];
        case 'failed':    return ['bad',     window.t('self-service.training.status.failed')];
        case 'incomplete':return ['warn',    window.t('self-service.training.status.in_progress')];
        default:          return ['neutral', window.t('self-service.training.status.not_started')];
    }
}

async function trLoad() {
    const body = document.getElementById('trBody');
    let rows = [];
    try {
        const r = await fetch(LMS_API + 'my_courses.php' + LMS_AS);
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'failed');
        rows = d.data || [];
    } catch (e) {
        body.innerHTML = `<div class="tr-empty">${trEsc(window.t('self-service.training.failed'))}</div>`;
        return;
    }

    if (!rows.length) {
        body.innerHTML = `<div class="tr-empty">${trEsc(window.t('self-service.training.none'))}</div>`;
        return;
    }

    body.innerHTML = '<div class="tr-grid">' + rows.map(row => {
        const [cls, label] = trBadge(row);

        // A SCORM package gets no bar: it has no lessons to count, and its
        // bookmark is arbitrary text from inside the package rather than a
        // position we can honestly turn into a fraction.
        let bar = '';
        if (row.lesson_count > 0) {
            const pct = Math.round((row.lesson_position / row.lesson_count) * 100);
            bar = `<div class="tr-bar"><div class="tr-bar-fill" style="width:${pct}%"></div></div>
                   <span class="tr-step">${trEsc(window.t('self-service.training.step', {
                       current: row.lesson_position, total: row.lesson_count }))}</span>`;
        }

        // fmtNaiveDate: the deadline is a picked calendar day stored at midnight,
        // not an instant, so it must not be converted between timezones on the
        // way to the screen. The analyst screens use the same function, so an
        // analyst and a portal user are told the same due date.
        const due = row.deadline
            ? `<span class="tr-due${row.is_overdue ? ' over' : ''}">${trEsc(window.t('self-service.training.due', {
                   date: fmtNaiveDate(row.deadline) }))}</span>`
            : '';

        // Three states, not two. "Continue" on a course somebody has already
        // passed reads as though there is more to do — the honest word for
        // opening a finished course is Review.
        const done    = ['passed', 'completed'].indexOf(row.status) > -1;
        const started = row.status && row.status !== 'not_started';
        const action  = done    ? window.t('self-service.training.review')
                      : started ? window.t('self-service.training.resume')
                                : window.t('self-service.training.start');

        return `<article class="tr-card">
            <h2>${trEsc(row.title)}</h2>
            ${row.description ? `<p class="tr-desc">${trEsc(row.description)}</p>` : ''}
            <div class="tr-foot">
                <div class="tr-meta">
                    <span class="tr-badge ${cls}">${trEsc(label)}</span>
                    ${due}
                </div>
                ${bar}
                <a class="tr-btn" href="course.php?id=${encodeURIComponent(row.id)}">${trEsc(action)}</a>
            </div>
        </article>`;
    }).join('') + '</div>';
}

trLoad();
JS;

require_once __DIR__ . '/includes/footer.php';
