/**
 * LMS — My Courses (learner landing). Lists the courses assigned to me with my
 * own status, and a Launch button. Read-only; no management here.
 */
(() => {
    const API = window.API_BASE;
    const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

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

    async function load() {
        const list = document.getElementById('mycList');
        let data;
        try {
            const r = await fetch(API + 'my_courses.php');
            const d = await r.json();
            if (!d.success) throw new Error(d.error);
            data = d.data || [];
        } catch (e) {
            list.innerHTML = `<div class="myc-empty"><p>${esc(String(e.message || e))}</p></div>`;
            return;
        }

        if (!data.length) {
            list.innerHTML = `<div class="myc-empty">
                <h3>${esc(window.t('lms.my.empty_title'))}</h3>
                <p>${esc(window.t('lms.my.empty_body'))}</p>
            </div>`;
            return;
        }

        list.innerHTML = data.map(row => {
            const deadline = row.deadline
                ? `<span class="myc-deadline ${row.is_overdue ? 'overdue' : ''}">${esc(window.t('lms.my.due'))} ${esc(parseUTCDate(row.deadline).toLocaleDateString(undefined, tzOpts({})))}</span>`
                : '';
            const score = (row.score_raw !== null && row.score_raw !== undefined && (row.status === 'passed' || row.status === 'failed'))
                ? `<span class="myc-score">${Math.round(row.score_raw)}%</span>`
                : '';
            return `<div class="myc-card">
                <div class="myc-card-main">
                    <h3>${esc(row.title)}</h3>
                    ${row.description ? `<p>${esc(row.description)}</p>` : ''}
                    <div class="myc-meta">${statusPill(row)}${score}${deadline}</div>
                </div>
                <div class="myc-actions">
                    <a class="btn btn-primary" href="player.php?course_id=${row.id}">${esc(launchLabel(row.status))}</a>
                </div>
            </div>`;
        }).join('');
    }

    document.addEventListener('DOMContentLoaded', load);
})();
