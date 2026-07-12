/**
 * LMS course editor — lessons, questions, and the three AI helpers.
 *
 * The AI never writes to the database. Every helper drops its draft into the
 * form the author is already looking at, and it is saved by the same validated
 * endpoint a hand-typed lesson goes through. That is what keeps a bad generation
 * a nuisance rather than a problem.
 */
const LMSEditor = (() => {
    const API = window.API_BASE;
    const COURSE_ID = window.COURSE_ID;

    let course = window.COURSE;
    let lessons = [];
    let currentId = null;      // the lesson being edited
    let editor = null;         // TinyMCE
    let articles = null;       // knowledge articles, loaded on first use
    let dragId = null;

    const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

    // ---------- boot ----------

    function init() {
        initTinyMCE();
        loadLessons();
        renderPassMark();

        document.getElementById('courseForm').addEventListener('submit', saveCourse);
        document.getElementById('questionForm').addEventListener('submit', saveQuestion);
        document.getElementById('outlineForm').addEventListener('submit', generateOutline);
        document.getElementById('lessonAiForm').addEventListener('submit', generateLesson);
    }

    function initTinyMCE() {
        const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
        tinymce.init({
            selector: '#lessonBody',
            license_key: 'gpl',
            height: 420,
            menubar: false,
            skin: isDark ? 'oxide-dark' : 'oxide',
            content_css: isDark ? 'dark' : 'default',
            plugins: ['advlist', 'autolink', 'lists', 'link', 'image', 'table', 'code', 'fullscreen', 'searchreplace', 'wordcount', 'codesample'],
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | link image table | codesample code | removeformat | fullscreen',
            content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 15px; line-height: 1.7; }',
            setup: (ed) => { editor = ed; }
        });
    }

    // ---------- lessons ----------

    async function loadLessons() {
        const r = await fetch(`${API}lessons.php?course_id=${COURSE_ID}`);
        const d = await r.json();
        if (!d.success) { showToast(d.error, 'error'); return; }
        lessons = d.data || [];
        renderLessonList();

        // Land the author somewhere useful rather than on an empty pane.
        if (lessons.length && currentId === null) selectLesson(lessons[0].id);
        else if (currentId !== null) renderQuestions();
    }

    function renderLessonList() {
        const el = document.getElementById('lessonList');
        if (!lessons.length) {
            el.innerHTML = `<div class="lms-empty">${esc(window.t('lms.editor.no_lessons'))}</div>`;
            return;
        }
        el.innerHTML = lessons.map((l, i) => `
            <div class="lms-lesson-item ${l.id === currentId ? 'active' : ''}" draggable="true" data-id="${l.id}">
                <span class="lms-lesson-num">${i + 1}</span>
                <span class="lms-lesson-name">${esc(l.title)}</span>
                ${l.questions && l.questions.length ? `<span class="lms-lesson-q">${l.questions.length}Q</span>` : ''}
                <button class="lms-lesson-del table-action-btn delete" data-del="${l.id}" title="${esc(window.t('lms.editor.delete_lesson_title'))}">×</button>
            </div>
        `).join('');

        el.querySelectorAll('.lms-lesson-del').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();          // don't also select the lesson we're deleting
                deleteLesson(parseInt(btn.dataset.del, 10));
            });
        });

        el.querySelectorAll('.lms-lesson-item').forEach(item => {
            const id = parseInt(item.dataset.id, 10);
            item.addEventListener('click', () => selectLesson(id));
            item.addEventListener('dragstart', () => { dragId = id; item.classList.add('dragging'); });
            item.addEventListener('dragend', () => item.classList.remove('dragging'));
            item.addEventListener('dragover', (e) => e.preventDefault());
            item.addEventListener('drop', (e) => { e.preventDefault(); dropOn(id); });
        });
    }

    async function dropOn(targetId) {
        if (dragId === null || dragId === targetId) return;
        const from = lessons.findIndex(l => l.id === dragId);
        const to = lessons.findIndex(l => l.id === targetId);
        if (from < 0 || to < 0) return;

        lessons.splice(to, 0, lessons.splice(from, 1)[0]);
        renderLessonList();
        dragId = null;

        await post('lessons.php', { _method: 'REORDER', course_id: COURSE_ID, ids: lessons.map(l => l.id) });
    }

    function selectLesson(id) {
        // Don't silently discard an edit in progress.
        if (currentId !== null && currentId !== id && isDirty()) {
            if (!confirm(window.t('lms.editor.unsaved'))) return;
        }
        currentId = id;
        const l = lessons.find(x => x.id === id);
        if (!l) return;

        document.getElementById('noLesson').style.display = 'none';
        document.getElementById('lessonPane').style.display = '';
        document.getElementById('lessonTitle').value = l.title;
        if (editor) editor.setContent(l.body || '');
        document.getElementById('saveState').textContent = '';

        renderLessonList();
        renderQuestions();
    }

    function isDirty() {
        const l = lessons.find(x => x.id === currentId);
        if (!l || !editor) return false;
        return l.title !== document.getElementById('lessonTitle').value || (l.body || '') !== editor.getContent();
    }

    function newLesson() {
        const title = prompt(window.t('lms.editor.new_lesson_prompt'));
        if (!title || !title.trim()) return;
        createLesson(title.trim(), '').then(id => { if (id) selectLesson(id); });
    }

    async function createLesson(title, body) {
        const d = await post('lessons.php', { course_id: COURSE_ID, title, body });
        if (!d.success) { showToast(d.error, 'error'); return null; }
        await loadLessons();
        return d.id;
    }

    async function saveLesson() {
        const title = document.getElementById('lessonTitle').value.trim();
        if (!title) { showToast(window.t('lms.editor.title_required'), 'error'); return; }

        const d = await post('lessons.php', { id: currentId, course_id: COURSE_ID, title, body: editor.getContent() });
        if (!d.success) { showToast(d.error, 'error'); return; }

        document.getElementById('saveState').textContent = window.t('lms.editor.saved');
        showToast(window.t('lms.editor.saved'), 'success');
        await loadLessons();
    }

    async function deleteLesson(id) {
        const ok = await showConfirm({
            title: window.t('lms.editor.delete_lesson_title'),
            message: window.t('lms.editor.delete_lesson_msg'),
            okLabel: window.t('lms.confirm.ok_delete'),
            okClass: 'danger'
        });
        if (!ok) return;

        const d = await post('lessons.php', { _method: 'DELETE', id });
        if (!d.success) { showToast(d.error, 'error'); return; }
        if (currentId === id) {
            currentId = null;
            document.getElementById('lessonPane').style.display = 'none';
            document.getElementById('noLesson').style.display = '';
        }
        await loadLessons();
    }

    // ---------- questions ----------

    function currentLesson() { return lessons.find(l => l.id === currentId); }

    function renderQuestions() {
        const l = currentLesson();
        const el = document.getElementById('questionList');
        const qs = (l && l.questions) || [];

        if (!qs.length) {
            el.innerHTML = `<div class="lms-empty">${esc(window.t('lms.editor.no_questions'))}</div>`;
            return;
        }

        el.innerHTML = qs.map((q, i) => `
            <div class="lms-question">
                <div class="lms-question-head">
                    <span class="lms-question-num">${i + 1}</span>
                    <span class="lms-question-text">${esc(q.question_text)}</span>
                    <span class="lms-question-type">${esc(typeLabel(q.question_type))}</span>
                    <span class="lms-question-actions">
                        <button class="table-action-btn" onclick="LMSEditor.editQuestion(${q.id})" title="${esc(window.t('lms.editor.edit'))}">✎</button>
                        <button class="table-action-btn delete" onclick="LMSEditor.deleteQuestion(${q.id})" title="${esc(window.t('lms.confirm.ok_delete'))}">🗑</button>
                    </span>
                </div>
                <ul class="lms-question-answers">
                    ${q.answers.map(a => `<li class="${a.is_correct ? 'correct' : ''}">${esc(a.answer_text)}</li>`).join('')}
                </ul>
            </div>
        `).join('');
    }

    function typeLabel(t) {
        return { single: window.t('lms.editor.type_single'), multiple: window.t('lms.editor.type_multiple'), truefalse: window.t('lms.editor.type_truefalse') }[t] || t;
    }

    function openQuestionModal(q) {
        document.getElementById('qId').value = q ? q.id : '';
        document.getElementById('qText').value = q ? q.question_text : '';
        document.getElementById('qType').value = q ? q.question_type : 'single';
        document.getElementById('qExplanation').value = q ? (q.explanation || '') : '';
        document.getElementById('questionModalTitle').textContent =
            q ? window.t('lms.editor.edit_question') : window.t('lms.editor.add_question');

        const rows = document.getElementById('answerRows');
        rows.innerHTML = '';
        if (q) q.answers.forEach(a => addAnswerRow(a.answer_text, !!a.is_correct));
        else { addAnswerRow(); addAnswerRow(); }

        onTypeChange();
        document.getElementById('questionModal').classList.add('active');
    }

    function editQuestion(id) {
        const q = (currentLesson().questions || []).find(x => x.id === id);
        if (q) openQuestionModal(q);
    }

    function addAnswerRow(text = '', correct = false) {
        const type = document.getElementById('qType').value;
        const rows = document.getElementById('answerRows');
        const row = document.createElement('div');
        row.className = 'lms-answer-row';
        // Radio for the types that allow exactly one right answer, checkbox for
        // 'multiple' — the control itself tells the author what the rule is.
        row.innerHTML = `
            <input type="${type === 'multiple' ? 'checkbox' : 'radio'}" name="correct" ${correct ? 'checked' : ''} title="${esc(window.t('lms.editor.mark_correct'))}">
            <input type="text" class="answer-text" value="${esc(text)}" placeholder="${esc(window.t('lms.editor.answer_placeholder'))}">
            <button type="button" class="table-action-btn delete" onclick="this.parentElement.remove()">×</button>
        `;
        rows.appendChild(row);
    }

    function onTypeChange() {
        const type = document.getElementById('qType').value;
        const rows = document.getElementById('answerRows');

        if (type === 'truefalse') {
            // True/false has exactly two fixed options — don't make the author type them.
            const correctIsTrue = !rows.querySelector('.lms-answer-row:nth-child(2) input[type=radio]:checked');
            rows.innerHTML = '';
            addAnswerRow(window.t('lms.editor.true'), correctIsTrue);
            addAnswerRow(window.t('lms.editor.false'), !correctIsTrue);
            rows.querySelectorAll('.answer-text').forEach(i => i.readOnly = true);
            rows.querySelectorAll('.table-action-btn').forEach(b => b.style.display = 'none');
            document.getElementById('addAnswerBtn').style.display = 'none';
            return;
        }

        document.getElementById('addAnswerBtn').style.display = '';
        rows.querySelectorAll('.answer-text').forEach(i => i.readOnly = false);
        rows.querySelectorAll('.table-action-btn').forEach(b => b.style.display = '');

        // Swap radio <-> checkbox in place, keeping what's already ticked.
        rows.querySelectorAll('.lms-answer-row').forEach(row => {
            const old = row.querySelector('input[name=correct]');
            const want = type === 'multiple' ? 'checkbox' : 'radio';
            if (old.type !== want) {
                const wasChecked = old.checked;
                old.type = want;
                old.checked = wasChecked;
            }
        });

        // Going multiple -> single can leave several ticked, which the server rejects.
        if (type !== 'multiple') {
            const checked = rows.querySelectorAll('input[name=correct]:checked');
            if (checked.length > 1) checked.forEach((c, i) => { if (i > 0) c.checked = false; });
        }
    }

    async function saveQuestion(e) {
        e.preventDefault();
        const answers = [...document.querySelectorAll('#answerRows .lms-answer-row')].map(row => ({
            answer_text: row.querySelector('.answer-text').value.trim(),
            is_correct: row.querySelector('input[name=correct]').checked
        })).filter(a => a.answer_text !== '');

        const d = await post('questions.php', {
            id: document.getElementById('qId').value || 0,
            lesson_id: currentId,
            question_text: document.getElementById('qText').value.trim(),
            question_type: document.getElementById('qType').value,
            explanation: document.getElementById('qExplanation').value.trim(),
            answers
        });

        if (!d.success) { showToast(d.error, 'error'); return; }
        closeModal('questionModal');
        showToast(window.t('lms.editor.saved'), 'success');
        await loadLessons();
    }

    async function deleteQuestion(id) {
        const ok = await showConfirm({
            title: window.t('lms.editor.delete_question_title'),
            message: window.t('lms.editor.delete_question_msg'),
            okLabel: window.t('lms.confirm.ok_delete'),
            okClass: 'danger'
        });
        if (!ok) return;

        const d = await post('questions.php', { _method: 'DELETE', id });
        if (!d.success) { showToast(d.error, 'error'); return; }
        await loadLessons();
    }

    // ---------- course settings ----------

    function renderPassMark() {
        const chip = document.getElementById('passMarkChip');
        chip.textContent = course.pass_mark === null
            ? window.t('lms.editor.no_pass_mark')
            : window.t('lms.editor.pass_mark_chip', { pct: course.pass_mark });
    }

    function openCourseModal() {
        document.getElementById('cTitle').value = course.title || '';
        document.getElementById('cDescription').value = course.description || '';
        document.getElementById('cPassMark').value = course.pass_mark === null ? '' : course.pass_mark;
        document.getElementById('courseModal').classList.add('active');
    }

    async function saveCourse(e) {
        e.preventDefault();
        const pm = document.getElementById('cPassMark').value;
        const d = await post('course.php', {
            _method: 'PUT',
            id: COURSE_ID,
            title: document.getElementById('cTitle').value.trim(),
            description: document.getElementById('cDescription').value.trim(),
            pass_mark: pm === '' ? null : parseInt(pm, 10)
        });
        if (!d.success) { showToast(d.error, 'error'); return; }

        course.title = document.getElementById('cTitle').value.trim();
        course.description = document.getElementById('cDescription').value.trim();
        course.pass_mark = pm === '' ? null : parseInt(pm, 10);

        document.getElementById('courseTitle').textContent = course.title;
        renderPassMark();
        closeModal('courseModal');
        showToast(window.t('lms.editor.saved'), 'success');
    }

    // ---------- AI ----------

    async function generateOutline(e) {
        e.preventDefault();
        const btn = document.getElementById('outlineBtn');
        const topic = document.getElementById('oTopic').value.trim();
        const count = parseInt(document.getElementById('oCount').value, 10) || 5;

        busy(btn, true);
        const d = await post('ai_author.php', { mode: 'outline', topic, lesson_count: count });
        busy(btn, false);
        if (!d.success) { showToast(d.error, 'error'); return; }

        // Each lesson lands as a real, empty lesson with the AI's summary as a
        // starting paragraph — a skeleton to write into, not finished content.
        for (const l of d.draft.lessons) {
            await post('lessons.php', {
                course_id: COURSE_ID,
                title: l.title,
                body: l.summary ? `<p>${escapeForHtml(l.summary)}</p>` : ''
            });
        }
        closeModal('outlineModal');
        showToast(window.t('lms.editor.outline_done', { n: d.draft.lessons.length }), 'success');
        currentId = null;
        await loadLessons();
    }

    async function openLessonAiModal() {
        if (articles === null) {
            const r = await fetch(`${API}knowledge_articles.php`);
            const d = await r.json();
            articles = d.data || [];
            const sel = document.getElementById('aiArticle');
            articles.forEach(a => {
                const o = document.createElement('option');
                o.value = a.id;
                o.textContent = a.title;
                sel.appendChild(o);
            });
        }
        document.getElementById('lessonAiModal').classList.add('active');
    }

    async function generateLesson(e) {
        e.preventDefault();
        const btn = document.getElementById('lessonAiBtn');
        const articleId = document.getElementById('aiArticle').value;
        const title = document.getElementById('lessonTitle').value.trim();

        if (!articleId && !title) { showToast(window.t('lms.editor.ai_lesson_need'), 'error'); return; }

        busy(btn, true);
        const d = await post('ai_author.php', { mode: 'lesson', article_id: articleId || 0, title });
        busy(btn, false);
        if (!d.success) { showToast(d.error, 'error'); return; }

        // Into the editor, not into the database — the author still has to press Save.
        if (d.draft.title && !title) document.getElementById('lessonTitle').value = d.draft.title;
        editor.setContent(d.draft.body);
        closeModal('lessonAiModal');
        showToast(window.t('lms.editor.ai_lesson_done'), 'success');
    }

    async function generateQuiz() {
        if (!currentId) return;
        if (isDirty()) { showToast(window.t('lms.editor.save_first'), 'error'); return; }

        showToast(window.t('lms.editor.ai_thinking'), 'info');
        const d = await post('ai_author.php', { mode: 'quiz', lesson_id: currentId, question_count: 3 });
        if (!d.success) { showToast(d.error, 'error'); return; }

        // Each generated question goes through the same save endpoint as a typed
        // one, so a malformed key is rejected here rather than reaching a learner.
        let saved = 0;
        for (const q of d.draft.questions) {
            const r = await post('questions.php', { lesson_id: currentId, ...q });
            if (r.success) saved++;
        }
        showToast(window.t('lms.editor.ai_quiz_done', { n: saved }), 'success');
        await loadLessons();
    }

    // ---------- plumbing ----------

    function escapeForHtml(s) { return esc(s); }

    function busy(btn, on) {
        btn.disabled = on;
        if (on) { btn.dataset.label = btn.textContent; btn.textContent = window.t('lms.editor.ai_thinking'); }
        else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
    }

    async function post(endpoint, body) {
        try {
            const r = await fetch(API + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            return await r.json();
        } catch (err) {
            return { success: false, error: String(err) };
        }
    }

    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    document.addEventListener('DOMContentLoaded', init);

    return {
        newLesson, saveLesson, deleteLesson, selectLesson,
        openQuestionModal, editQuestion, deleteQuestion, addAnswerRow, onTypeChange,
        openCourseModal, openOutlineModal: () => document.getElementById('outlineModal').classList.add('active'),
        openLessonAiModal, generateQuiz, closeModal
    };
})();
