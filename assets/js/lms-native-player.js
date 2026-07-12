/**
 * LMS — the learner's player for an authored course.
 *
 * One lesson per step: read the body, answer that lesson's questions, move on.
 * Answers are held locally until the end and graded in one go by the server —
 * the client has no answer key to check against, by design (see
 * api/lms/course_content.php), so it cannot tell you if you're right and cannot
 * be made to lie about it either.
 */
const LMSPlayer = (() => {
    const API = window.API_BASE;
    const COURSE_ID = window.COURSE_ID;

    let course = null;
    let lessons = [];
    let index = 0;
    let responses = {};        // question_id -> [answer_id]
    let finished = false;
    let startedAt = Date.now();

    const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
    const elapsed = () => Math.round((Date.now() - startedAt) / 1000);

    async function init() {
        const r = await fetch(`${API}course_content.php?course_id=${COURSE_ID}`);
        const d = await r.json();
        if (!d.success) { document.getElementById('stage').innerHTML = `<p class="lms-empty">${esc(d.error)}</p>`; return; }

        course = d.course;
        lessons = d.lessons || [];

        if (!lessons.length) {
            document.getElementById('stage').innerHTML = `<p class="lms-empty">${esc(window.t('lms.player.no_lessons'))}</p>`;
            return;
        }

        // Register the attempt and find out where they got to last time.
        const pr = await fetch(`${API}native_progress.php?course_id=${COURSE_ID}`);
        const pd = await pr.json();
        if (pd.success && pd.bookmark) {
            const at = lessons.findIndex(l => String(l.id) === String(pd.bookmark));
            if (at > 0) index = at;
        }

        document.getElementById('navBar').style.display = '';
        renderToc();
        renderLesson();

        window.addEventListener('beforeunload', () => {
            if (!finished) saveBookmark(true);
        });
    }

    function renderToc() {
        document.getElementById('toc').innerHTML = lessons.map((l, i) => `
            <button class="lms-toc-item ${i === index ? 'active' : ''} ${i < index ? 'done' : ''}" data-i="${i}">
                <span class="lms-toc-num">${i + 1}</span>
                <span>${esc(l.title)}</span>
            </button>
        `).join('');

        document.querySelectorAll('.lms-toc-item').forEach(b => {
            b.addEventListener('click', () => go(parseInt(b.dataset.i, 10)));
        });

        const pct = Math.round(((index + 1) / lessons.length) * 100);
        document.getElementById('progressFill').style.width = pct + '%';
        document.getElementById('stepLabel').textContent =
            window.t('lms.player.step', { current: index + 1, total: lessons.length });
    }

    function renderLesson() {
        const l = lessons[index];

        // The body is HTML written by an analyst in TinyMCE — the same trust level
        // as a knowledge article, and rendered the same way.
        let html = `<article class="lms-lesson-body"><h1>${esc(l.title)}</h1>${l.body || ''}</article>`;

        if (l.questions && l.questions.length) {
            html += `<section class="lms-quiz"><h2>${esc(window.t('lms.player.check'))}</h2>`;
            l.questions.forEach(q => {
                const multiple = q.question_type === 'multiple';
                html += `
                    <div class="lms-quiz-q" data-q="${q.id}">
                        <p class="lms-quiz-text">${esc(q.question_text)}</p>
                        ${multiple ? `<p class="lms-quiz-hint">${esc(window.t('lms.player.pick_several'))}</p>` : ''}
                        <div class="lms-quiz-answers">
                            ${q.answers.map(a => `
                                <label class="lms-quiz-answer">
                                    <input type="${multiple ? 'checkbox' : 'radio'}" name="q${q.id}" value="${a.id}"
                                           ${(responses[q.id] || []).includes(a.id) ? 'checked' : ''}>
                                    <span>${esc(a.answer_text)}</span>
                                </label>
                            `).join('')}
                        </div>
                    </div>`;
            });
            html += `</section>`;
        }

        const stage = document.getElementById('stage');
        stage.innerHTML = html;
        stage.scrollTop = 0;

        // Remember answers as they're given, so moving back and forth doesn't lose them.
        stage.querySelectorAll('.lms-quiz-q').forEach(box => {
            const qid = parseInt(box.dataset.q, 10);
            box.querySelectorAll('input').forEach(inp => {
                inp.addEventListener('change', () => {
                    responses[qid] = [...box.querySelectorAll('input:checked')].map(i => parseInt(i.value, 10));
                });
            });
        });

        document.getElementById('prevBtn').disabled = index === 0;
        document.getElementById('nextBtn').textContent = index === lessons.length - 1
            ? window.t('lms.player.finish')
            : window.t('lms.player.next');
    }

    /** Every question in the course must be answered before it can be graded. */
    function unanswered() {
        let n = 0;
        lessons.forEach(l => (l.questions || []).forEach(q => {
            if (!(responses[q.id] || []).length) n++;
        }));
        return n;
    }

    function go(i) {
        if (finished || i < 0 || i >= lessons.length) return;
        index = i;
        saveBookmark(false);
        renderToc();
        renderLesson();
    }

    function next() {
        if (index < lessons.length - 1) { go(index + 1); return; }
        finish();
    }

    function prev() { go(index - 1); }

    async function saveBookmark(unloading) {
        const body = JSON.stringify({ course_id: COURSE_ID, bookmark: String(lessons[index].id), elapsed_seconds: elapsed() });
        startedAt = Date.now();   // time is accumulated server-side; don't double-count it

        // On unload a normal fetch is killed mid-flight — sendBeacon survives it.
        if (unloading && navigator.sendBeacon) {
            navigator.sendBeacon(API + 'native_progress.php', new Blob([body], { type: 'application/json' }));
            return;
        }
        try {
            await fetch(API + 'native_progress.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
        } catch (e) { /* a lost bookmark is not worth interrupting the learner for */ }
    }

    async function finish() {
        const missing = unanswered();
        if (missing > 0) {
            const ok = await showConfirm({
                title: window.t('lms.player.unanswered_title'),
                message: window.t('lms.player.unanswered_msg', { n: missing }),
                okLabel: window.t('lms.player.submit_anyway'),
                okClass: 'danger'
            });
            if (!ok) return;
        }

        const payload = {
            course_id: COURSE_ID,
            finished: true,
            elapsed_seconds: elapsed(),
            responses: Object.keys(responses).map(qid => ({ question_id: parseInt(qid, 10), answer_ids: responses[qid] }))
        };

        const r = await fetch(API + 'native_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (!d.success) { showToast(d.error, 'error'); return; }

        finished = true;
        renderResult(d);
    }

    function renderResult(d) {
        document.getElementById('navBar').style.display = 'none';
        document.getElementById('progressFill').style.width = '100%';

        const passed = d.status === 'passed';
        const failed = d.status === 'failed';
        const banner = passed ? 'pass' : (failed ? 'fail' : 'done');

        const headline = passed ? window.t('lms.player.passed')
            : failed ? window.t('lms.player.failed')
            : window.t('lms.player.completed');

        let html = `
            <div class="lms-result ${banner}">
                <h1>${esc(headline)}</h1>
                ${d.total > 0 ? `<p class="lms-result-score">${d.correct} / ${d.total} &mdash; ${d.score}%</p>` : ''}
                ${d.pass_mark !== null && d.total > 0 ? `<p class="lms-result-sub">${esc(window.t('lms.player.pass_mark_was', { pct: d.pass_mark }))}</p>` : ''}
            </div>`;

        // The review is the point of the quiz — show what was wrong and why.
        if (d.review && d.review.length) {
            html += `<section class="lms-review"><h2>${esc(window.t('lms.player.review'))}</h2>`;
            d.review.forEach((r, i) => {
                html += `
                    <div class="lms-review-item ${r.is_correct ? 'correct' : 'incorrect'}">
                        <p class="lms-review-q"><strong>${i + 1}.</strong> ${esc(r.question_text)}</p>
                        <p class="lms-review-a">${esc(window.t('lms.player.your_answer'))}: <span>${esc(r.your_answer || '—')}</span></p>
                        ${!r.is_correct ? `<p class="lms-review-a correct">${esc(window.t('lms.player.correct_answer'))}: <span>${esc(r.correct_answer)}</span></p>` : ''}
                        ${r.explanation ? `<p class="lms-review-why">${esc(r.explanation)}</p>` : ''}
                    </div>`;
            });
            html += `</section>`;
        }

        html += `<div class="lms-native-nav"><a href="./" class="btn btn-primary">${esc(window.t('lms.player.back'))}</a>
                 ${failed ? `<button class="btn btn-secondary" onclick="location.reload()">${esc(window.t('lms.player.retry'))}</button>` : ''}</div>`;

        const stage = document.getElementById('stage');
        stage.innerHTML = html;
        stage.scrollTop = 0;
    }

    document.addEventListener('DOMContentLoaded', init);

    return { next, prev };
})();
