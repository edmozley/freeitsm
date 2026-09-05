/**
 * LMS — My Courses (learner landing). Lists the courses assigned to me with my
 * own status, how far through I am, and a Launch button. Read-only; no
 * management here.
 */
(() => {
    const API = window.API_BASE;
    const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

    /* The layout choice is remembered per analyst, the same way and through the
       same endpoint as the Knowledge module's. Two views only: this page has one
       kind of thing in it, so a tree and a details table would be furniture. */
    const LAYOUT_KEY = 'lms_my_courses_layout';
    const LAYOUTS = ['list', 'cards'];
    let mycLayout = 'list';
    let mycRows = null;

    const STATUS_LABEL = {
        not_started: () => window.t('lms.status.not_started'),
        incomplete:  () => window.t('lms.status.incomplete'),
        completed:   () => window.t('lms.status.completed'),
        passed:      () => window.t('lms.status.passed'),
        failed:      () => window.t('lms.status.failed'),
    };

    function statusPill(row) {
        // 'overdue' is a derived display state, not a stored status.
        if (row.is_overdue) return `<span class="lms-status overdue">${esc(window.t('lms.status.overdue'))}</span>`;
        const label = (STATUS_LABEL[row.status] || (() => row.status))();
        return `<span class="lms-status ${esc(row.status)}">${esc(label)}</span>`;
    }

    function launchLabel(status) {
        if (status === 'passed' || status === 'completed') return window.t('lms.my.review');
        if (status === 'incomplete' || status === 'failed') return window.t('lms.my.resume');
        return window.t('lms.my.start');
    }

    /**
     * The progress bar, and the reason it always carries words.
     *
     * 🔑 Nothing stores a percentage. `lesson_position` / `lesson_count` come
     * from lmsAttachLessonProgress(), which reads which LESSON the learner is on
     * — so the honest statement is "Lesson 2 of 3" and the bar is an
     * illustration of it. A bar with no label would be read as "you are 67%
     * through the material", which is a claim the data cannot support.
     *
     * Nothing is drawn at all when there is nothing to say: a SCORM course has
     * no lessons to count, and a course nobody has opened has no position.
     */
    function progressBar(row) {
        const total = Number(row.lesson_count) || 0;
        if (total <= 0) return '';

        const done = Math.max(0, Math.min(Number(row.lesson_position) || 0, total));
        if (done === 0) {
            return `<div class="myc-progress">
                <div class="myc-progress-track"><div class="myc-progress-fill" style="width:0"></div></div>
                <span class="myc-progress-label">${esc(window.t('lms.my.not_opened'))}</span>
            </div>`;
        }
        const pct = Math.round((done / total) * 100);
        const isDone = done === total;
        return `<div class="myc-progress">
            <div class="myc-progress-track">
                <div class="myc-progress-fill${isDone ? ' is-done' : ''}" style="width:${pct}%"></div>
            </div>
            <span class="myc-progress-label">${esc(window.t('lms.my.progress', { n: done, total: total }))}</span>
        </div>`;
    }

    function cardHtml(row) {
        const deadline = row.deadline
            ? `<span class="myc-deadline ${row.is_overdue ? 'overdue' : ''}">${esc(window.t('lms.my.due'))} ${esc(fmtDate(row.deadline))}</span>`
            : '';
        const score = (row.score_raw !== null && row.score_raw !== undefined && (row.status === 'passed' || row.status === 'failed'))
            ? `<span class="myc-score">${Math.round(row.score_raw)}%</span>`
            : '';
        return `<div class="myc-card">
            <div class="myc-card-main">
                <h3>${esc(row.title)}</h3>
                ${row.description ? `<p>${esc(row.description)}</p>` : ''}
                <div class="myc-meta">${statusPill(row)}${score}${deadline}</div>
                ${progressBar(row)}
            </div>
            <div class="myc-actions">
                <a class="btn btn-primary" href="player.php?course_id=${row.id}">${esc(launchLabel(row.status))}</a>
            </div>
        </div>`;
    }

    function render() {
        const list = document.getElementById('mycList');
        if (!list || !mycRows) return;

        list.className = 'myc-list myc-layout-' + mycLayout;

        if (!mycRows.length) {
            // The empty state is a message, not a grid of one thing.
            list.className = 'myc-list';
            list.innerHTML = `<div class="myc-empty">
                <h3>${esc(window.t('lms.my.empty_title'))}</h3>
                <p>${esc(window.t('lms.my.empty_body'))}</p>
            </div>`;
            return;
        }
        list.innerHTML = mycRows.map(cardHtml).join('');
    }

    function applyLayout(save) {
        document.querySelectorAll('.myc-layout-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.layout === mycLayout);
        });
        render();
        if (save) {
            fetch('../api/system/set_user_preference.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: LAYOUT_KEY, value: mycLayout })
            }).catch(() => { /* the layout still changed; only the memory of it failed */ });
        }
    }

    window.setMycLayout = function (mode) {
        if (!LAYOUTS.includes(mode)) return;
        mycLayout = mode;
        applyLayout(true);
    };

    async function loadLayoutPreference() {
        try {
            const r = await fetch('../api/system/get_user_preference.php?key=' + encodeURIComponent(LAYOUT_KEY), { credentials: 'same-origin' });
            const d = await r.json();
            mycLayout = (d.success && LAYOUTS.includes(d.value)) ? d.value : 'list';
        } catch (e) {
            mycLayout = 'list';
        }
    }

    async function load() {
        const list = document.getElementById('mycList');
        // The preference and the courses are independent, so fetch both at once
        // rather than making the list wait on a setting.
        const [, coursesResult] = await Promise.all([
            loadLayoutPreference(),
            (async () => {
                const r = await fetch(API + 'my_courses.php');
                const d = await r.json();
                if (!d.success) throw new Error(d.error);
                return d.data || [];
            })().catch(e => e)
        ]);

        if (coursesResult instanceof Error) {
            list.innerHTML = `<div class="myc-empty"><p>${esc(String(coursesResult.message || coursesResult))}</p></div>`;
            return;
        }
        mycRows = coursesResult;
        applyLayout(false);
    }

    document.addEventListener('DOMContentLoaded', load);
})();
