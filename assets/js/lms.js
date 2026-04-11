/**
 * LMS Dashboard JavaScript
 * Handles CRUD for courses, learning groups, assignments, and progress tracking.
 */
const LMS = (() => {
    let courses = [];
    let groups = [];
    let analysts = [];
    let assignments = [];

    // =========================================================
    //  Init
    // =========================================================
    function init() {
        loadCourses();
        loadAnalysts();

        document.getElementById('uploadForm').addEventListener('submit', uploadCourse);
        document.getElementById('groupForm').addEventListener('submit', saveGroup);
        document.getElementById('assignForm').addEventListener('submit', saveAssignment);
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
            tbody.innerHTML = '<tr><td colspan="5" class="lms-empty">No courses uploaded yet</td></tr>';
            return;
        }
        tbody.innerHTML = courses.map(c => {
            const version = c.scorm_version ? `<span class="scorm-badge">SCORM ${esc(c.scorm_version)}</span>` : '<span style="color:#999;">Unknown</span>';
            const date = c.created_datetime ? new Date(c.created_datetime).toLocaleDateString() : '';
            return `<tr>
                <td><strong>${esc(c.title)}</strong>${c.description ? '<br><small style="color:#888;">' + esc(c.description).substring(0, 80) + '</small>' : ''}</td>
                <td>${version}</td>
                <td>${date}</td>
                <td>${c.is_active == 1 ? '<span class="lms-status completed">Active</span>' : '<span class="lms-status not_started">Inactive</span>'}</td>
                <td class="lms-actions">
                    <a class="lms-action-btn launch" href="player.php?course_id=${c.id}" title="Launch">Launch</a>
                    <button class="lms-action-btn delete" onclick="LMS.deleteCourse(${c.id})" title="Delete">&times;</button>
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
        document.getElementById('uploadStatus').textContent = 'Uploading...';

        try {
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    document.getElementById('uploadBar').style.width = pct + '%';
                    document.getElementById('uploadStatus').textContent = pct + '% uploaded';
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
                document.getElementById('uploadStatus').textContent = 'Done! SCORM ' + (result.scorm_version || '?') + ' detected.';
                toast('Course uploaded', 'success');
                setTimeout(() => {
                    closeModal('uploadModal');
                    loadCourses();
                }, 1000);
            } else {
                toast(result.error, 'error');
                btn.disabled = false;
            }
        } catch (e) {
            toast('Upload failed', 'error');
            btn.disabled = false;
        }
    }

    async function deleteCourse(id) {
        if (!confirm('Delete this course?')) return;
        try {
            const r = await fetch(API_BASE + 'course.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, _method: 'DELETE' })
            });
            const d = await r.json();
            if (d.success) { toast('Deleted'); loadCourses(); }
            else toast(d.error, 'error');
        } catch (e) { toast('Failed to delete', 'error'); }
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
            tbody.innerHTML = '<tr><td colspan="4" class="lms-empty">No learning groups created yet</td></tr>';
            return;
        }
        tbody.innerHTML = groups.map(g => {
            const members = (g.members || []).map(m => esc(m.full_name)).join(', ') || '<em style="color:#999;">No members</em>';
            return `<tr>
                <td><strong>${esc(g.name)}</strong></td>
                <td>${esc(g.description || '')}</td>
                <td>${members}</td>
                <td class="lms-actions">
                    <button class="lms-action-btn" onclick="LMS.editGroup(${g.id})">Edit</button>
                    <button class="lms-action-btn delete" onclick="LMS.deleteGroup(${g.id})">&times;</button>
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
        document.getElementById('groupModalTitle').textContent = group ? 'Edit Group' : 'New Group';
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
                toast('Saved', 'success');
                closeModal('groupModal');
                loadGroups();
            } else {
                toast(d.error, 'error');
            }
        } catch (e) { toast('Failed to save', 'error'); }
    }

    async function deleteGroup(id) {
        if (!confirm('Delete this group?')) return;
        try {
            const r = await fetch(API_BASE + 'group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, _method: 'DELETE' })
            });
            const d = await r.json();
            if (d.success) { toast('Deleted'); loadGroups(); }
        } catch (e) { toast('Failed', 'error'); }
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
            tbody.innerHTML = '<tr><td colspan="5" class="lms-empty">No courses assigned yet</td></tr>';
            return;
        }
        tbody.innerHTML = assignments.map(a => {
            const deadline = a.deadline ? new Date(a.deadline).toLocaleDateString() : '<em style="color:#999;">None</em>';
            return `<tr>
                <td>${esc(a.course_title)}</td>
                <td>${esc(a.group_name)}</td>
                <td>${deadline}</td>
                <td>${esc(a.assigned_by_name || '')}</td>
                <td class="lms-actions">
                    <button class="lms-action-btn delete" onclick="LMS.deleteAssignment(${a.id})">&times;</button>
                </td>
            </tr>`;
        }).join('');
    }

    async function openAssignModal() {
        // Load fresh data for dropdowns
        if (!courses.length) await loadCourses();
        if (!groups.length) await loadGroups();

        const courseSelect = document.getElementById('assignCourse');
        courseSelect.innerHTML = '<option value="">Select course...</option>' +
            courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');

        const groupSelect = document.getElementById('assignGroup');
        groupSelect.innerHTML = '<option value="">Select group...</option>' +
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
                toast('Assigned', 'success');
                closeModal('assignModal');
                loadAssignments();
            } else {
                toast(d.error, 'error');
            }
        } catch (e) { toast('Failed', 'error'); }
    }

    async function deleteAssignment(id) {
        if (!confirm('Remove this assignment?')) return;
        try {
            const r = await fetch(API_BASE + 'assignment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, _method: 'DELETE' })
            });
            const d = await r.json();
            if (d.success) { toast('Removed'); loadAssignments(); }
        } catch (e) { toast('Failed', 'error'); }
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
            fc.innerHTML = '<option value="">All courses</option>' +
                courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('');
        }
        const fg = document.getElementById('filterGroup');
        if (fg.options.length <= 1) {
            fg.innerHTML = '<option value="">All groups</option>' +
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
            tbody.innerHTML = '<tr><td colspan="7" class="lms-empty">No progress data found</td></tr>';
            return;
        }
        tbody.innerHTML = data.map(row => {
            let statusClass = row.status;
            let statusLabel = row.status.replace('_', ' ');
            if (row.is_overdue) {
                statusClass = 'overdue';
                statusLabel = 'Overdue';
            }

            const score = row.score_raw !== null ? row.score_raw + (row.score_max ? '/' + row.score_max : '') : '';
            const deadline = row.deadline ? new Date(row.deadline).toLocaleDateString() : '';
            const lastAccess = row.last_access ? new Date(row.last_access).toLocaleString() : '';
            const trStyle = row.is_overdue ? ' style="background: #fff5f5;"' : '';

            return `<tr${trStyle}>
                <td>${esc(row.analyst_name)}</td>
                <td>${esc(row.course_title)}</td>
                <td>${esc(row.group_name)}</td>
                <td><span class="lms-status ${statusClass}">${statusLabel}</span></td>
                <td>${score}</td>
                <td>${deadline}</td>
                <td>${lastAccess}</td>
            </tr>`;
        }).join('');
    }

    // =========================================================
    //  Modal helpers
    // =========================================================
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    // =========================================================
    //  Utilities
    // =========================================================
    function toast(msg, type = 'success') {
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.className = 'toast show ' + type;
        setTimeout(() => { el.className = 'toast'; }, 2500);
    }

    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Boot
    document.addEventListener('DOMContentLoaded', init);

    return {
        switchTab, openUploadModal, deleteCourse,
        openGroupModal, editGroup, deleteGroup,
        openAssignModal, deleteAssignment,
        loadProgress, closeModal
    };
})();
