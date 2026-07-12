/**
 * LMS Dashboard JavaScript
 * Handles CRUD for courses, learning groups, assignments, and progress tracking.
 */
const LMS = (() => {
    let courses = [];
    let groups = [];
    let analysts = [];
    let assignments = [];

    const ICON_LAUNCH = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>';
    const ICON_EDIT   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
    const ICON_VIEW   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';

    // =========================================================
    //  Init
    // =========================================================
    function init() {
        loadCourses();
        loadAnalysts();

        document.getElementById('uploadForm').addEventListener('submit', uploadCourse);
        document.getElementById('createForm').addEventListener('submit', createCourse);
        document.getElementById('groupForm').addEventListener('submit', saveGroup);
        document.getElementById('assignForm').addEventListener('submit', saveAssignment);
    }

    // =========================================================
    //  Creating an authored course
    //  Only the shell is made here — the title, the blurb and the pass mark.
    //  The lessons are written in the editor, so we go straight there.
    // =========================================================
    function openCreateModal() {
        document.getElementById('newCourseTitle').value = '';
        document.getElementById('newCourseDescription').value = '';
        document.getElementById('newCoursePassMark').value = '';
        openModal('createModal');
    }

    async function createCourse(e) {
        e.preventDefault();
        const pm = document.getElementById('newCoursePassMark').value;

        const r = await fetch(API_BASE + 'course.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                _method: 'CREATE_NATIVE',
                title: document.getElementById('newCourseTitle').value.trim(),
                description: document.getElementById('newCourseDescription').value.trim(),
                pass_mark: pm === '' ? null : parseInt(pm, 10)
            })
        });
        const d = await r.json();
        if (!d.success) { showToast(d.error, 'error'); return; }

        window.location.href = 'editor.php?course_id=' + d.id;
    }

    // =========================================================
    //  Tab switching
    // =========================================================
    function switchTab(tab) {
        document.querySelectorAll('.lms-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
        document.querySelectorAll('.lms-panel').forEach(p => p.style.display = 'none');
        document.getElementById('panel-' + tab).style.display = '';

        if (tab === 'courses') loadCourses();
        if (tab === 'groups') loadGroups();
        if (tab === 'assignments') loadAssignments();
        if (tab === 'progress') loadProgress();
        // 'settings' is a static panel — the AI settings panel loads itself.
    }

    // =========================================================
    //  Courses
    // =========================================================
    async function loadCourses() {
        try {
            const r = await fetch(API_BASE + 'courses.php');
            const d = await r.json();
            if (d.success) {
                courses = d.data;
                renderCourses();
            }
        } catch (e) { console.error(e); }
    }

    function renderCourses() {
        const tbody = document.getElementById('coursesBody');
        if (!courses.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="lms-empty">' + esc(window.t('lms.courses.empty')) + '</td></tr>';
            return;
        }
        tbody.innerHTML = courses.map(c => {
            const isNative = (c.content_type || 'scorm') === 'native';

            // An authored course has no SCORM version — that column shows what KIND
            // of course it is instead, which is the more useful thing to know here.
            const version = isNative
                ? `<span class="native-badge">${esc(window.t('lms.courses.authored'))}</span>`
                : (c.scorm_version
                    ? `<span class="scorm-badge">SCORM ${esc(c.scorm_version)}</span>`
                    : `<span style="color:#999;">${esc(window.t('lms.courses.version_unknown'))}</span>`);

            const date = c.created_datetime ? parseUTCDate(c.created_datetime).toLocaleDateString(undefined, tzOpts({})) : '';

            // Only authored courses can be edited here; a SCORM package is edited in
            // whatever tool built it, so it gets no pencil rather than a broken one.
            const edit = isNative
                ? `<a class="table-action-btn" href="editor.php?course_id=${c.id}" title="${esc(window.t('lms.courses.edit'))}">${ICON_EDIT}</a>`
                : '';

            return `<tr>
                <td><strong>${esc(c.title)}</strong>${c.description ? '<br><small style="color:#888;">' + esc(c.description).substring(0, 80) + '</small>' : ''}</td>
                <td>${version}</td>
                <td>${date}</td>
                <td>${c.is_active == 1 ? '<span class="lms-status completed">' + esc(window.t('lms.courses.active')) + '</span>' : '<span class="lms-status not_started">' + esc(window.t('lms.courses.inactive')) + '</span>'}</td>
                <td class="lms-actions">
                    <a class="table-action-btn" href="player.php?course_id=${c.id}" title="${esc(window.t('lms.courses.launch'))}">${ICON_LAUNCH}</a>
                    ${edit}
                    <button class="table-action-btn delete" onclick="LMS.deleteCourse(${c.id})" title="${esc(window.t('lms.courses.delete'))}">${ICON_DELETE}</button>
                </td>
            </tr>`;
        }).join('');
    }

    function openUploadModal() {
        document.getElementById('courseTitle').value = '';
        document.getElementById('courseDescription').value = '';
        document.getElementById('courseFile').value = '';
        document.getElementById('uploadProgress').style.display = 'none';
        document.getElementById('uploadBtn').disabled = false;
        openModal('uploadModal');
    }

    async function uploadCourse(e) {
        e.preventDefault();
        const btn = document.getElementById('uploadBtn');
        btn.disabled = true;

        const formData = new FormData();
        formData.append('title', document.getElementById('courseTitle').value);
        formData.append('description', document.getElementById('courseDescription').value);
        formData.append('file', document.getElementById('courseFile').files[0]);

        document.getElementById('uploadProgress').style.display = '';
        document.getElementById('uploadStatus').textContent = window.t('lms.upload_modal.uploading');

        try {
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    document.getElementById('uploadBar').style.width = pct + '%';
                    document.getElementById('uploadStatus').textContent = window.t('lms.upload_modal.percent_uploaded', { pct: pct });
                }
            });

            const result = await new Promise((resolve, reject) => {
                xhr.onload = function() {
                    try { resolve(JSON.parse(xhr.responseText)); }
                    catch (e) { reject(new Error('Invalid response')); }
                };
                xhr.onerror = function() { reject(new Error('Upload failed')); };
                xhr.open('POST', API_BASE + 'courses.php');
                xhr.send(formData);
            });

            if (result.success) {
                document.getElementById('uploadStatus').textContent = window.t('lms.upload_modal.done', { version: result.scorm_version || '?' });
                showToast(window.t('lms.toast.course_uploaded'), 'success');
                setTimeout(() => {
                    closeModal('uploadModal');
                    loadCourses();
                }, 1000);
            } else {
                showToast(result.error, 'error');
                btn.disabled = false;
            }
        } catch (e) {
            showToast(window.t('lms.toast.upload_failed'), 'error');
            btn.disabled = false;
        }
    }

    async function deleteCourse(id) {
        if (!(await showConfirm({ title: window.t('lms.confirm.delete_title'), message: window.t('lms.confirm.delete_course'), okLabel: window.t('lms.confirm.ok_delete'), okClass: 'danger' }))) return;
        try {
            const r = await fetch(API_BASE + 'course.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, _method: 'DELETE' })
            });
            const d = await r.json();
            if (d.success) { showToast(window.t('lms.toast.deleted'), 'success'); loadCourses(); }
            else showToast(d.error, 'error');
        } catch (e) { showToast(window.t('lms.toast.delete_failed'), 'error'); }
    }

    // =========================================================
    //  Learning Groups
    // =========================================================
    async function loadGroups() {
        try {
            const r = await fetch(API_BASE + 'groups.php');
            const d = await r.json();
            if (d.success) {
                groups = d.data;
                renderGroups();
            }
        } catch (e) { console.error(e); }
    }

    function renderGroups() {
        const tbody = document.getElementById('groupsBody');
        if (!groups.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="lms-empty">' + esc(window.t('lms.groups.empty')) + '</td></tr>';
            return;
        }
        tbody.innerHTML = groups.map(g => {
            const members = (g.members || []).map(m => esc(m.full_name)).join(', ') || `<em style="color:#999;">${esc(window.t('lms.groups.no_members'))}</em>`;
            return `<tr>
                <td><strong>${esc(g.name)}</strong></td>
                <td>${esc(g.description || '')}</td>
                <td>${members}</td>
                <td class="lms-actions">
                    <button class="table-action-btn" onclick="LMS.editGroup(${g.id})" title="${esc(window.t('lms.groups.edit'))}">${ICON_EDIT}</button>
                    <button class="table-action-btn delete" onclick="LMS.deleteGroup(${g.id})" title="${esc(window.t('lms.groups.delete'))}">${ICON_DELETE}</button>
                </td>
            </tr>`;
        }).join('');
    }

    async function loadAnalysts() {
        try {
            const r = await fetch(API_BASE + 'analysts.php');
            const d = await r.json();
            if (d.success) analysts = d.data;
        } catch (e) { console.error(e); }
    }

    function openGroupModal(group = null) {
        document.getElementById('groupModalTitle').textContent = group ? window.t('lms.group_modal.title_edit') : window.t('lms.group_modal.title_new');
        document.getElementById('groupId').value = group ? group.id : '';
        document.getElementById('groupName').value = group ? group.name : '';
        document.getElementById('groupDescription').value = group ? (group.description || '') : '';

        const memberIds = group ? (group.members || []).map(m => +m.analyst_id) : [];
        const container = document.getElementById('membersList');
        container.innerHTML = analysts.map(a => {
            const checked = memberIds.includes(+a.id) ? ' checked' : '';
            return `<label class="member-item"><input type="checkbox" value="${a.id}"${checked}> ${esc(a.full_name)} (${esc(a.username)})</label>`;
        }).join('');

        openModal('groupModal');
    }

    function editGroup(id) {
        const group = groups.find(g => g.id == id);
        if (group) openGroupModal(group);
    }

    async function saveGroup(e) {
        e.preventDefault();
        const id = document.getElementById('groupId').value;
        const memberIds = [...document.querySelectorAll('#membersList input:checked')].map(cb => +cb.value);

        const payload = {
            name: document.getElementById('groupName').value,
            description: document.getElementById('groupDescription').value,
            member_ids: memberIds
        };

        try {
            let r;
            if (id) {
                payload.id = +id;
                payload._method = 'PUT';
                r = await fetch(API_BASE + 'group.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } else {
                r = await fetch(API_BASE + 'groups.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            }
            const d = await r.json();
            if (d.success) {
                showToast(window.t('lms.toast.saved'), 'success');
                closeModal('groupModal');
                loadGroups();
            } else {
                showToast(d.error, 'error');
            }
        } catch (e) { showToast(window.t('lms.toast.save_failed'), 'error'); }
    }

    async function deleteGroup(id) {
        if (!(await showConfirm({ title: window.t('lms.confirm.delete_title'), message: window.t('lms.confirm.delete_group'), okLabel: window.t('lms.confirm.ok_delete'), okClass: 'danger' }))) return;
        try {
            const r = await fetch(API_BASE + 'group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, _method: 'DELETE' })
            });
            const d = await r.json();
            if (d.success) { showToast(window.t('lms.toast.deleted'), 'success'); loadGroups(); }
        } catch (e) { showToast(window.t('lms.toast.failed'), 'error'); }
    }

    // =========================================================
    //  Assignments
    // =========================================================
    async function loadAssignments() {
        try {
            const r = await fetch(API_BASE + 'assignments.php');
            const d = await r.json();
            if (d.success) {
                assignments = d.data;
                renderAssignments();
            }
        } catch (e) { console.error(e); }
    }

    function renderAssignments() {
        const tbody = document.getElementById('assignmentsBody');
        if (!assignments.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="lms-empty">' + esc(window.t('lms.assignments.empty')) + '</td></tr>';
            return;
        }
        tbody.innerHTML = assignments.map(a => {
            const deadline = a.deadline ? new Date(a.deadline).toLocaleDateString() : `<em style="color:#999;">${esc(window.t('lms.assignments.no_deadline'))}</em>`;
            return `<tr>
                <td>${esc(a.course_title)}</td>
                <td>${esc(a.group_name)}</td>
                <td>${deadline}</td>
                <td>${esc(a.assigned_by_name || '')}</td>
                <td class="lms-actions">
                    <button class="table-action-btn delete" onclick="LMS.deleteAssignment(${a.id})" title="${esc(window.t('lms.assignments.delete'))}">${ICON_DELETE}</button>
                </td>
            </tr>`;
        }).join('');
    }

    async function openAssignModal() {
        // Load fresh data for dropdowns
        if (!courses.length) await loadCourses();
        if (!groups.length) await loadGroups();

        const courseSelect = document.getElementById('assignCourse');
        courseSelect.innerHTML = '<option value="">' + esc(window.t('lms.assign_modal.select_course')) + '</option>' +
            courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');

        const groupSelect = document.getElementById('assignGroup');
        groupSelect.innerHTML = '<option value="">' + esc(window.t('lms.assign_modal.select_group')) + '</option>' +
            groups.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('');

        document.getElementById('assignDeadline').value = '';
        openModal('assignModal');
    }

    async function saveAssignment(e) {
        e.preventDefault();
        const payload = {
            course_id: +document.getElementById('assignCourse').value,
            group_id: +document.getElementById('assignGroup').value,
            deadline: document.getElementById('assignDeadline').value || null
        };

        try {
            const r = await fetch(API_BASE + 'assignments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                showToast(window.t('lms.toast.assigned'), 'success');
                closeModal('assignModal');
                loadAssignments();
            } else {
                showToast(d.error, 'error');
            }
        } catch (e) { showToast(window.t('lms.toast.failed'), 'error'); }
    }

    async function deleteAssignment(id) {
        if (!(await showConfirm({ title: window.t('lms.confirm.delete_title'), message: window.t('lms.confirm.remove_assignment'), okLabel: window.t('lms.confirm.ok_delete'), okClass: 'danger' }))) return;
        try {
            const r = await fetch(API_BASE + 'assignment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, _method: 'DELETE' })
            });
            const d = await r.json();
            if (d.success) { showToast(window.t('lms.toast.removed'), 'success'); loadAssignments(); }
        } catch (e) { showToast(window.t('lms.toast.failed'), 'error'); }
    }

    // =========================================================
    //  Progress
    // =========================================================
    async function loadProgress() {
        // Populate filter dropdowns
        if (!courses.length) await loadCourses();
        if (!groups.length) await loadGroups();

        const fc = document.getElementById('filterCourse');
        if (fc.options.length <= 1) {
            fc.innerHTML = '<option value="">' + esc(window.t('lms.progress.all_courses')) + '</option>' +
                courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
        }
        const fg = document.getElementById('filterGroup');
        if (fg.options.length <= 1) {
            fg.innerHTML = '<option value="">' + esc(window.t('lms.progress.all_groups')) + '</option>' +
                groups.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('');
        }

        const params = new URLSearchParams();
        const courseId = document.getElementById('filterCourse').value;
        const groupId = document.getElementById('filterGroup').value;
        const status = document.getElementById('filterStatus').value;
        if (courseId) params.set('course_id', courseId);
        if (groupId) params.set('group_id', groupId);
        if (status) params.set('status', status);

        try {
            const r = await fetch(API_BASE + 'progress.php?' + params.toString());
            const d = await r.json();
            if (d.success) renderProgress(d.data);
        } catch (e) { console.error(e); }
    }

    function renderProgress(data) {
        const tbody = document.getElementById('progressBody');
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="lms-empty">' + esc(window.t('lms.progress.empty')) + '</td></tr>';
            return;
        }
        tbody.innerHTML = data.map(row => {
            let statusClass = row.status;
            let statusLabel = statusText(row.status);
            if (row.is_overdue) {
                statusClass = 'overdue';
                statusLabel = window.t('lms.status.overdue');
            }

            const score = row.score_raw !== null ? row.score_raw + (row.score_max ? '/' + row.score_max : '') : '';
            const deadline = row.deadline ? new Date(row.deadline).toLocaleDateString() : '';
            const lastAccess = row.last_access ? parseUTCDate(row.last_access).toLocaleString(undefined, tzOpts({})) : '';
            const trStyle = row.is_overdue ? ' style="background: #fff5f5;"' : '';

            const viewBtn = row.status !== 'not_started'
                ? `<button class="table-action-btn" onclick="LMS.viewLearnerData(${row.analyst_id}, ${row.course_id})" title="${esc(window.t('lms.progress.view'))}">${ICON_VIEW}</button>`
                : '';

            return `<tr${trStyle}>
                <td>${esc(row.analyst_name)}</td>
                <td>${esc(row.course_title)}</td>
                <td>${esc(row.group_name)}</td>
                <td><span class="lms-status ${statusClass}">${statusLabel}</span></td>
                <td>${score}</td>
                <td>${deadline}</td>
                <td>${lastAccess}</td>
                <td class="lms-actions">${viewBtn}</td>
            </tr>`;
        }).join('');
    }

    // =========================================================
    //  Learner Data View
    // =========================================================
    async function viewLearnerData(analystId, courseId) {
        const body = document.getElementById('learnerDataBody');
        body.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">' + esc(window.t('lms.learner_modal.loading')) + '</div>';
        openModal('learnerDataModal');

        try {
            const r = await fetch(API_BASE + 'learner_data.php?analyst_id=' + analystId + '&course_id=' + courseId);
            const d = await r.json();
            if (!d.success) {
                body.innerHTML = '<div style="color:#c33; padding:20px;">' + esc(d.error) + '</div>';
                return;
            }

            const p = d.progress;
            document.getElementById('learnerDataTitle').textContent = p.analyst_name + ' — ' + p.course_title;

            let html = '';

            // Summary cards
            const statusLabel = statusText(p.status || 'not_started');
            const scoreDisplay = p.score_raw !== null ? p.score_raw + (p.score_max ? ' / ' + p.score_max : '') : '—';
            html += `<div class="ld-summary">
                <div class="ld-stat"><div class="ld-stat-value"><span class="lms-status ${p.status}">${statusLabel}</span></div><div class="ld-stat-label">${esc(window.t('lms.learner_modal.stat_status'))}</div></div>
                <div class="ld-stat"><div class="ld-stat-value">${esc(scoreDisplay)}</div><div class="ld-stat-label">${esc(window.t('lms.learner_modal.stat_score'))}</div></div>
                <div class="ld-stat"><div class="ld-stat-value">${p.attempt_count || 0}</div><div class="ld-stat-label">${esc(window.t('lms.learner_modal.stat_attempts'))}</div></div>
                <div class="ld-stat"><div class="ld-stat-value">${formatTime(p.total_time)}</div><div class="ld-stat-label">${esc(window.t('lms.learner_modal.stat_time_spent'))}</div></div>
                <div class="ld-stat"><div class="ld-stat-value">${p.first_access ? parseUTCDate(p.first_access).toLocaleDateString(undefined, tzOpts({})) : '—'}</div><div class="ld-stat-label">${esc(window.t('lms.learner_modal.stat_first_access'))}</div></div>
                <div class="ld-stat"><div class="ld-stat-value">${p.last_access ? parseUTCDate(p.last_access).toLocaleDateString(undefined, tzOpts({})) : '—'}</div><div class="ld-stat-label">${esc(window.t('lms.learner_modal.stat_last_access'))}</div></div>
            </div>`;

            // Interactions (quiz responses)
            if (d.interactions.length > 0) {
                html += `<div class="ld-section">
                    <div class="ld-section-title">${esc(window.t('lms.learner_modal.responses'))} <span class="ld-count">${d.interactions.length}</span></div>`;

                d.interactions.forEach((ix, i) => {
                    const result = ix.result || '';
                    let resultClass = 'neutral';
                    if (/correct/i.test(result)) resultClass = 'correct';
                    else if (/wrong|incorrect/i.test(result)) resultClass = 'wrong';

                    html += `<div class="ld-interaction">
                        <div class="ld-interaction-header">
                            <span class="ld-interaction-id">${esc(ix.id || window.t('lms.learner_modal.question_n', { n: i + 1 }))}</span>
                            ${result ? '<span class="ld-result ' + resultClass + '">' + esc(result) + '</span>' : ''}
                        </div>`;

                    if (ix.description) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_question'))}</span><span class="ld-field-value">${esc(ix.description)}</span></div>`;
                    if (ix.type) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_type'))}</span><span class="ld-field-value">${esc(ix.type)}</span></div>`;
                    if (ix.learner_response || ix.student_response) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_response'))}</span><span class="ld-field-value">${esc(ix.learner_response || ix.student_response)}</span></div>`;
                    if (ix.correct_responses) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_correct'))}</span><span class="ld-field-value">${esc(ix.correct_responses)}</span></div>`;
                    if (ix.weighting) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_weight'))}</span><span class="ld-field-value">${esc(ix.weighting)}</span></div>`;
                    if (ix.latency) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_time_taken'))}</span><span class="ld-field-value">${esc(ix.latency)}</span></div>`;

                    html += '</div>';
                });

                html += '</div>';
            }

            // Objectives
            if (d.objectives.length > 0) {
                html += `<div class="ld-section">
                    <div class="ld-section-title">${esc(window.t('lms.learner_modal.objectives'))} <span class="ld-count">${d.objectives.length}</span></div>`;
                d.objectives.forEach(obj => {
                    const objStatus = obj.status || obj.completion_status || '';
                    html += `<div class="ld-interaction">
                        <div class="ld-interaction-header">
                            <span class="ld-interaction-id">${esc(obj.id || window.t('lms.learner_modal.objective'))}</span>
                            ${objStatus ? '<span class="lms-status ' + objStatus + '">' + esc(objStatus) + '</span>' : ''}
                        </div>`;
                    if (obj.description) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_description'))}</span><span class="ld-field-value">${esc(obj.description)}</span></div>`;
                    if (obj.score_raw) html += `<div class="ld-field"><span class="ld-field-label">${esc(window.t('lms.learner_modal.field_score'))}</span><span class="ld-field-value">${esc(obj.score_raw)}${obj.score_max ? ' / ' + esc(obj.score_max) : ''}</span></div>`;
                    html += '</div>';
                });
                html += '</div>';
            }

            // Suspend data
            if (d.suspend_data_raw) {
                html += `<div class="ld-section">
                    <div class="ld-section-title">${esc(window.t('lms.learner_modal.suspend_data'))}</div>`;

                if (d.suspend_data_decoded) {
                    html += '<div class="ld-suspend ld-suspend-json">' + formatJson(d.suspend_data_decoded) + '</div>';
                } else {
                    // Try to detect and format common patterns
                    const raw = d.suspend_data_raw;
                    if (raw.length > 2000) {
                        html += '<div class="ld-suspend">' + window.t('lms.learner_modal.suspend_truncated', { shown: esc(raw.substring(0, 2000)), total: raw.length }) + '</div>';
                    } else {
                        html += '<div class="ld-suspend">' + esc(raw) + '</div>';
                    }
                }
                html += '</div>';
            }

            // All CMI data (raw key-value pairs)
            if (d.general.length > 0) {
                html += `<div class="ld-section">
                    <div class="ld-section-title">${esc(window.t('lms.learner_modal.all_elements'))} <span class="ld-count">${d.general.length}</span></div>
                    <table class="ld-kv-table">`;
                d.general.forEach(g => {
                    const val = g.value || '';
                    const displayVal = val.length > 200 ? esc(val.substring(0, 200)) + '...' : esc(val);
                    html += `<tr><td>${esc(g.element)}</td><td>${displayVal}</td></tr>`;
                });
                html += '</table></div>';
            }

            if (!d.interactions.length && !d.objectives.length && !d.suspend_data_raw && !d.general.length) {
                html += '<div style="text-align:center; padding:30px; color:#999;">' + esc(window.t('lms.learner_modal.no_data')) + '</div>';
            }

            body.innerHTML = html;

        } catch (e) {
            body.innerHTML = '<div style="color:#c33; padding:20px;">' + esc(window.t('lms.learner_modal.load_failed')) + '</div>';
        }
    }

    function formatTime(timeStr) {
        if (!timeStr) return '—';
        // Handle SCORM time formats: "00:05:30" or "PT5M30S"
        if (timeStr.startsWith('PT')) {
            const h = (timeStr.match(/(\d+)H/) || [])[1] || 0;
            const m = (timeStr.match(/(\d+)M/) || [])[1] || 0;
            const s = (timeStr.match(/(\d+(?:\.\d+)?)S/) || [])[1] || 0;
            if (+h > 0) return h + 'h ' + m + 'm';
            if (+m > 0) return m + 'm ' + Math.round(+s) + 's';
            return Math.round(+s) + 's';
        }
        // HH:MM:SS format
        const parts = timeStr.split(':');
        if (parts.length === 3) {
            const h = parseInt(parts[0]);
            const m = parseInt(parts[1]);
            const s = Math.round(parseFloat(parts[2]));
            if (h > 0) return h + 'h ' + m + 'm';
            if (m > 0) return m + 'm ' + s + 's';
            return s + 's';
        }
        return timeStr;
    }

    function formatJson(obj, indent = 0) {
        if (obj === null) return '<span class="ld-json-null">null</span>';
        if (typeof obj === 'boolean') return '<span class="ld-json-bool">' + obj + '</span>';
        if (typeof obj === 'number') return '<span class="ld-json-number">' + obj + '</span>';
        if (typeof obj === 'string') return '<span class="ld-json-string">"' + esc(obj) + '"</span>';

        const pad = '  '.repeat(indent);
        const pad1 = '  '.repeat(indent + 1);

        if (Array.isArray(obj)) {
            if (obj.length === 0) return '[]';
            const items = obj.map(item => pad1 + formatJson(item, indent + 1));
            return '[\n' + items.join(',\n') + '\n' + pad + ']';
        }

        const keys = Object.keys(obj);
        if (keys.length === 0) return '{}';
        const entries = keys.map(k => pad1 + '<span class="ld-json-key">"' + esc(k) + '"</span>: ' + formatJson(obj[k], indent + 1));
        return '{\n' + entries.join(',\n') + '\n' + pad + '}';
    }

    // =========================================================
    //  Modal helpers
    // =========================================================
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    // =========================================================
    //  Utilities
    // =========================================================
    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Translate a normalised LMS status code to its display label. Unknown
    // codes (e.g. SCORM-reported values without a key) fall back to a
    // prettified version of the raw code.
    function statusText(status) {
        const known = ['not_started', 'incomplete', 'completed', 'passed', 'failed', 'overdue'];
        if (known.includes(status)) return window.t('lms.status.' + status);
        return (status || '').replace('_', ' ');
    }

    // Boot
    document.addEventListener('DOMContentLoaded', init);

    return {
        switchTab, openUploadModal, openCreateModal, deleteCourse,
        openGroupModal, editGroup, deleteGroup,
        openAssignModal, deleteAssignment,
        loadProgress, viewLearnerData, closeModal
    };
})();
