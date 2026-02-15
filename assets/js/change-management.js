/**
 * Change Management Module - Client-side logic
 */

const API_BASE = window.API_BASE || '../api/change-management/';

let changes = [];
let analysts = [];
let currentChange = null;
let currentFilter = 'all';
let searchQuery = '';
let searchTimeout = null;

// TinyMCE editor instances
const editorIds = ['editorDescription', 'editorReason', 'editorRisk', 'editorTestplan', 'editorRollback', 'editorPir'];
let editorsReady = false;

// ============ Initialization ============

document.addEventListener('DOMContentLoaded', function() {
    loadAnalysts();
    loadChanges();
    setupFileUpload();

    // Handle ?open=ID from calendar or direct link
    const urlParams = new URLSearchParams(window.location.search);
    const openId = urlParams.get('open');
    if (openId) {
        viewChange(parseInt(openId, 10));
    }
});

// ============ Data Loading ============

async function loadAnalysts() {
    try {
        const response = await fetch(API_BASE + 'list.php?analysts=1');
        const data = await response.json();
        if (data.success) {
            analysts = data.analysts;
            populateAnalystDropdowns();
        }
    } catch (error) {
        console.error('Error loading analysts:', error);
    }
}

function populateAnalystDropdowns() {
    const dropdowns = ['changeRequester', 'changeAssignedTo', 'changeApprover'];
    dropdowns.forEach(id => {
        const select = document.getElementById(id);
        if (!select) return;
        // Keep the first "-- Select --" option
        select.innerHTML = '<option value="">-- Select --</option>';
        analysts.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.name;
            select.appendChild(opt);
        });
    });
}

async function loadChanges() {
    try {
        let url = API_BASE + 'list.php?';
        if (currentFilter !== 'all') {
            url += 'status=' + encodeURIComponent(currentFilter) + '&';
        }
        if (searchQuery) {
            url += 'search=' + encodeURIComponent(searchQuery) + '&';
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            changes = data.changes;
            updateCounts(data.counts);
            renderChangeList();
        }
    } catch (error) {
        console.error('Error loading changes:', error);
        document.getElementById('changeList').innerHTML = '<div class="empty-state"><p>Error loading changes</p></div>';
    }
}

function updateCounts(counts) {
    if (!counts) return;
    document.getElementById('countAll').textContent = counts.total || 0;
    document.getElementById('countDraft').textContent = counts.Draft || 0;
    document.getElementById('countPendingApproval').textContent = counts['Pending Approval'] || 0;
    document.getElementById('countApproved').textContent = counts.Approved || 0;
    document.getElementById('countInProgress').textContent = counts['In Progress'] || 0;
    document.getElementById('countCompleted').textContent = counts.Completed || 0;
    document.getElementById('countFailed').textContent = counts.Failed || 0;
    document.getElementById('countCancelled').textContent = counts.Cancelled || 0;
}

// ============ Rendering ============

function renderChangeList() {
    const container = document.getElementById('changeList');
    const countEl = document.getElementById('changeCount');

    if (!changes.length) {
        container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">&#128221;</div><div class="empty-state-text">No changes found</div></div>';
        countEl.textContent = '';
        return;
    }

    countEl.textContent = changes.length + ' change' + (changes.length !== 1 ? 's' : '');

    container.innerHTML = changes.map(c => {
        const ref = 'CHG-' + String(c.id).padStart(4, '0');
        const statusClass = c.status.toLowerCase().replace(/\s+/g, '-');
        const typeClass = c.change_type.toLowerCase();
        const priorityClass = c.priority.toLowerCase();
        const assignedName = c.assigned_to_name || 'Unassigned';
        const workStart = c.work_start_datetime ? formatDate(c.work_start_datetime) : '';

        return `
            <div class="change-card" onclick="viewChange(${c.id})">
                <div class="change-card-ref">${ref}</div>
                <div class="change-card-info">
                    <div class="change-card-title">${escapeHtml(c.title)}</div>
                    <div class="change-card-meta">
                        <span>${assignedName}</span>
                        ${workStart ? '<span>Work: ' + workStart + '</span>' : ''}
                    </div>
                </div>
                <div class="change-card-badges">
                    <span class="type-badge ${typeClass}">${c.change_type}</span>
                    <span class="priority-badge ${priorityClass}">${c.priority}</span>
                    <span class="status-badge ${statusClass}">${c.status}</span>
                </div>
            </div>
        `;
    }).join('');
}

// ============ View Change Detail ============

async function viewChange(id) {
    try {
        const response = await fetch(API_BASE + 'get.php?id=' + id);
        const data = await response.json();

        if (!data.success) {
            showToast('Error: ' + data.error);
            return;
        }

        currentChange = data.change;
        renderChangeDetail();
        showView('detail');
    } catch (error) {
        console.error('Error loading change:', error);
        showToast('Error loading change');
    }
}

function renderChangeDetail() {
    const c = currentChange;
    const ref = 'CHG-' + String(c.id).padStart(4, '0');
    const statusClass = c.status.toLowerCase().replace(/\s+/g, '-');
    const typeClass = c.change_type.toLowerCase();
    const priorityClass = c.priority.toLowerCase();

    let html = `
        <div class="change-detail-sticky-header">
            <div class="sticky-header-top">
                <div class="change-detail-ref">${ref}</div>
                <div class="sticky-header-badges">
                    <span class="status-badge ${statusClass}">${c.status}</span>
                    <span class="type-badge ${typeClass}">${c.change_type}</span>
                    <span class="priority-badge ${priorityClass}">${c.priority}</span>
                </div>
            </div>
            <div class="change-detail-title">${escapeHtml(c.title)}</div>
        </div>

        <div class="detail-meta-grid">
            <div class="detail-meta-item">
                <span class="detail-meta-label">Impact</span>
                <span class="detail-meta-value">${c.impact}</span>
            </div>
            ${c.category ? `<div class="detail-meta-item"><span class="detail-meta-label">Category</span><span class="detail-meta-value">${escapeHtml(c.category)}</span></div>` : ''}
            <div class="detail-meta-item">
                <span class="detail-meta-label">Requester</span>
                <span class="detail-meta-value">${c.requester_name || 'Not set'}</span>
            </div>
            <div class="detail-meta-item">
                <span class="detail-meta-label">Assigned To</span>
                <span class="detail-meta-value">${c.assigned_to_name || 'Not set'}</span>
            </div>
            <div class="detail-meta-item">
                <span class="detail-meta-label">Approver</span>
                <span class="detail-meta-value">${c.approver_name || 'Not set'}</span>
            </div>
            ${c.approval_datetime ? `<div class="detail-meta-item"><span class="detail-meta-label">Approved</span><span class="detail-meta-value">${formatDateTime(c.approval_datetime)}</span></div>` : ''}
            ${c.work_start_datetime ? `<div class="detail-meta-item"><span class="detail-meta-label">Work Start</span><span class="detail-meta-value">${formatDateTime(c.work_start_datetime)}</span></div>` : ''}
            ${c.work_end_datetime ? `<div class="detail-meta-item"><span class="detail-meta-label">Work End</span><span class="detail-meta-value">${formatDateTime(c.work_end_datetime)}</span></div>` : ''}
            ${c.outage_start_datetime ? `<div class="detail-meta-item"><span class="detail-meta-label">Outage Start</span><span class="detail-meta-value">${formatDateTime(c.outage_start_datetime)}</span></div>` : ''}
            ${c.outage_end_datetime ? `<div class="detail-meta-item"><span class="detail-meta-label">Outage End</span><span class="detail-meta-value">${formatDateTime(c.outage_end_datetime)}</span></div>` : ''}
            <div class="detail-meta-item">
                <span class="detail-meta-label">Created</span>
                <span class="detail-meta-value">${formatDateTime(c.created_datetime)}${c.created_by_name ? ' by ' + c.created_by_name : ''}</span>
            </div>
            <div class="detail-meta-item">
                <span class="detail-meta-label">Last Modified</span>
                <span class="detail-meta-value">${formatDateTime(c.modified_datetime)}</span>
            </div>
        </div>

        <div class="detail-sections">
            ${renderDetailSection('Description', c.description)}
            ${renderDetailSection('Reason for Change', c.reason_for_change)}
            ${renderDetailSection('Risk Evaluation', c.risk_evaluation)}
            ${renderDetailSection('Test Plan', c.test_plan)}
            ${renderDetailSection('Rollback Plan', c.rollback_plan)}
            ${renderDetailSection('Post-Implementation Review', c.post_implementation_review)}
        </div>
    `;

    // Attachments
    if (c.attachments && c.attachments.length) {
        html += `
            <div class="attachments-section">
                <h3>Attachments (${c.attachments.length})</h3>
                <div class="attachment-list">
                    ${c.attachments.map(a => `
                        <div class="attachment-item">
                            <div class="attachment-info">
                                <span class="attachment-icon">&#128206;</span>
                                <span class="attachment-name">${escapeHtml(a.file_name)}</span>
                                <span class="attachment-size">${formatFileSize(a.file_size)}</span>
                            </div>
                            <div class="attachment-actions">
                                <button onclick="downloadAttachment(${a.id})" title="Download">&#8595;</button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    document.getElementById('changeDetailContent').innerHTML = html;
}

function renderDetailSection(title, content) {
    if (!content || content.trim() === '') {
        return `
            <div class="detail-section">
                <h3>${title}</h3>
                <div class="detail-section-body"><span class="detail-section-empty">Not provided</span></div>
            </div>
        `;
    }
    return `
        <div class="detail-section">
            <h3>${title}</h3>
            <div class="detail-section-body">${content}</div>
        </div>
    `;
}

// ============ Filtering & Search ============

function filterByStatus(status) {
    currentFilter = status;
    document.querySelectorAll('.status-filter').forEach(el => {
        el.classList.toggle('active', el.dataset.status === status);
    });
    loadChanges();
}

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        searchQuery = document.getElementById('changeSearch').value.trim();
        loadChanges();
    }, 300);
}

// ============ Create / Edit ============

function openCreateChange() {
    document.getElementById('editChangeId').value = '';
    document.getElementById('editorTitle').textContent = 'New Change';
    document.getElementById('changeTitle').value = '';
    document.getElementById('changeType').value = 'Normal';
    document.getElementById('changeStatus').value = 'Draft';
    document.getElementById('changePriority').value = 'Medium';
    document.getElementById('changeImpact').value = 'Medium';
    document.getElementById('changeCategory').value = '';
    document.getElementById('changeRequester').value = '';
    document.getElementById('changeAssignedTo').value = '';
    document.getElementById('changeApprover').value = '';
    document.getElementById('changeWorkStart').value = '';
    document.getElementById('changeWorkEnd').value = '';
    document.getElementById('changeOutageStart').value = '';
    document.getElementById('changeOutageEnd').value = '';
    document.getElementById('editorAttachmentList').innerHTML = '';

    initEditors(() => {
        editorIds.forEach(id => {
            const editor = tinymce.get(id);
            if (editor) editor.setContent('');
        });
    });

    showView('editor');
}

function editCurrentChange() {
    if (!currentChange) return;
    const c = currentChange;

    document.getElementById('editChangeId').value = c.id;
    document.getElementById('editorTitle').textContent = 'Edit Change - CHG-' + String(c.id).padStart(4, '0');
    document.getElementById('changeTitle').value = c.title || '';
    document.getElementById('changeType').value = c.change_type || 'Normal';
    document.getElementById('changeStatus').value = c.status || 'Draft';
    document.getElementById('changePriority').value = c.priority || 'Medium';
    document.getElementById('changeImpact').value = c.impact || 'Medium';
    document.getElementById('changeCategory').value = c.category || '';
    document.getElementById('changeRequester').value = c.requester_id || '';
    document.getElementById('changeAssignedTo').value = c.assigned_to_id || '';
    document.getElementById('changeApprover').value = c.approver_id || '';
    document.getElementById('changeWorkStart').value = toDatetimeLocal(c.work_start_datetime);
    document.getElementById('changeWorkEnd').value = toDatetimeLocal(c.work_end_datetime);
    document.getElementById('changeOutageStart').value = toDatetimeLocal(c.outage_start_datetime);
    document.getElementById('changeOutageEnd').value = toDatetimeLocal(c.outage_end_datetime);

    // Render existing attachments with delete buttons
    renderEditorAttachments(c.attachments || []);

    initEditors(() => {
        setEditorContent('editorDescription', c.description || '');
        setEditorContent('editorReason', c.reason_for_change || '');
        setEditorContent('editorRisk', c.risk_evaluation || '');
        setEditorContent('editorTestplan', c.test_plan || '');
        setEditorContent('editorRollback', c.rollback_plan || '');
        setEditorContent('editorPir', c.post_implementation_review || '');
    });

    showView('editor');
}

function cancelEdit() {
    destroyEditors();
    if (currentChange) {
        showView('detail');
    } else {
        showView('list');
    }
}

// ============ Save ============

async function saveChange() {
    const title = document.getElementById('changeTitle').value.trim();
    if (!title) {
        showToast('Title is required');
        return;
    }

    const changeId = document.getElementById('editChangeId').value;

    const payload = {
        id: changeId ? parseInt(changeId) : null,
        title: title,
        change_type: document.getElementById('changeType').value,
        status: document.getElementById('changeStatus').value,
        priority: document.getElementById('changePriority').value,
        impact: document.getElementById('changeImpact').value,
        category: document.getElementById('changeCategory').value,
        requester_id: document.getElementById('changeRequester').value || null,
        assigned_to_id: document.getElementById('changeAssignedTo').value || null,
        approver_id: document.getElementById('changeApprover').value || null,
        work_start_datetime: document.getElementById('changeWorkStart').value || null,
        work_end_datetime: document.getElementById('changeWorkEnd').value || null,
        outage_start_datetime: document.getElementById('changeOutageStart').value || null,
        outage_end_datetime: document.getElementById('changeOutageEnd').value || null,
        description: getEditorContent('editorDescription'),
        reason_for_change: getEditorContent('editorReason'),
        risk_evaluation: getEditorContent('editorRisk'),
        test_plan: getEditorContent('editorTestplan'),
        rollback_plan: getEditorContent('editorRollback'),
        post_implementation_review: getEditorContent('editorPir')
    };

    try {
        const response = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (data.success) {
            showToast('Change saved successfully');
            destroyEditors();
            // Reload and view the saved change
            await loadChanges();
            await viewChange(data.change_id);
        } else {
            showToast('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error saving change:', error);
        showToast('Error saving change');
    }
}

// ============ Delete ============

function deleteCurrentChange() {
    if (!currentChange) return;
    const ref = 'CHG-' + String(currentChange.id).padStart(4, '0');

    document.getElementById('deleteModal').innerHTML = `
        <div class="modal-overlay" onclick="closeDeleteModal()">
            <div class="modal-box" onclick="event.stopPropagation()">
                <h3>Delete Change</h3>
                <p>Are you sure you want to delete <strong>${ref} - ${escapeHtml(currentChange.title)}</strong>? This cannot be undone.</p>
                <div class="modal-box-actions">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmDelete(${currentChange.id})">Delete</button>
                </div>
            </div>
        </div>
    `;
}

function closeDeleteModal() {
    document.getElementById('deleteModal').innerHTML = '';
}

async function confirmDelete(id) {
    try {
        const response = await fetch(API_BASE + 'delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast('Change deleted');
            closeDeleteModal();
            currentChange = null;
            showView('list');
            loadChanges();
        } else {
            showToast('Error: ' + data.error);
        }
    } catch (error) {
        showToast('Error deleting change');
    }
}

// ============ Attachments ============

function setupFileUpload() {
    const area = document.getElementById('fileUploadArea');
    const input = document.getElementById('fileInput');

    area.addEventListener('click', () => input.click());

    area.addEventListener('dragover', (e) => {
        e.preventDefault();
        area.classList.add('drag-over');
    });

    area.addEventListener('dragleave', () => {
        area.classList.remove('drag-over');
    });

    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            uploadFiles(e.dataTransfer.files);
        }
    });

    input.addEventListener('change', () => {
        if (input.files.length) {
            uploadFiles(input.files);
            input.value = '';
        }
    });
}

async function uploadFiles(files) {
    const changeId = document.getElementById('editChangeId').value;
    if (!changeId) {
        showToast('Please save the change first before uploading attachments');
        return;
    }

    for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('change_id', changeId);

        try {
            const response = await fetch(API_BASE + 'upload_attachment.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showToast('File uploaded: ' + file.name);
                // Refresh the change to get updated attachments
                const refreshResp = await fetch(API_BASE + 'get.php?id=' + changeId);
                const refreshData = await refreshResp.json();
                if (refreshData.success) {
                    currentChange = refreshData.change;
                    renderEditorAttachments(currentChange.attachments || []);
                }
            } else {
                showToast('Upload failed: ' + data.error);
            }
        } catch (error) {
            showToast('Upload error: ' + file.name);
        }
    }
}

function renderEditorAttachments(attachments) {
    const container = document.getElementById('editorAttachmentList');
    if (!attachments.length) {
        container.innerHTML = '';
        return;
    }

    container.innerHTML = attachments.map(a => `
        <div class="attachment-item">
            <div class="attachment-info">
                <span class="attachment-icon">&#128206;</span>
                <span class="attachment-name">${escapeHtml(a.file_name)}</span>
                <span class="attachment-size">${formatFileSize(a.file_size)}</span>
            </div>
            <div class="attachment-actions">
                <button onclick="downloadAttachment(${a.id})" title="Download">&#8595;</button>
                <button class="delete-btn" onclick="deleteAttachment(${a.id})" title="Delete">&#10005;</button>
            </div>
        </div>
    `).join('');
}

function downloadAttachment(id) {
    window.open(API_BASE + 'get_attachment.php?id=' + id, '_blank');
}

async function deleteAttachment(id) {
    if (!confirm('Delete this attachment?')) return;

    try {
        const response = await fetch(API_BASE + 'delete_attachment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast('Attachment deleted');
            // Refresh attachments
            const changeId = document.getElementById('editChangeId').value;
            if (changeId) {
                const refreshResp = await fetch(API_BASE + 'get.php?id=' + changeId);
                const refreshData = await refreshResp.json();
                if (refreshData.success) {
                    currentChange = refreshData.change;
                    renderEditorAttachments(currentChange.attachments || []);
                }
            }
        } else {
            showToast('Error: ' + data.error);
        }
    } catch (error) {
        showToast('Error deleting attachment');
    }
}

// ============ TinyMCE ============

function initEditors(callback) {
    // Destroy existing instances first
    destroyEditors();

    let initialized = 0;
    const total = editorIds.length;

    editorIds.forEach(id => {
        tinymce.init({
            selector: '#' + id,
            license_key: 'gpl',
            height: 300,
            menubar: false,
            plugins: ['advlist', 'autolink', 'lists', 'link', 'table', 'wordcount'],
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat',
            content_style: 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; }',
            setup: function(editor) {
                editor.on('init', function() {
                    initialized++;
                    if (initialized === total) {
                        editorsReady = true;
                        if (callback) callback();
                    }
                });
            }
        });
    });
}

function destroyEditors() {
    editorsReady = false;
    editorIds.forEach(id => {
        const editor = tinymce.get(id);
        if (editor) editor.remove();
    });
}

function getEditorContent(id) {
    const editor = tinymce.get(id);
    return editor ? editor.getContent() : '';
}

function setEditorContent(id, content) {
    const editor = tinymce.get(id);
    if (editor) {
        editor.setContent(content || '');
    }
}

// ============ Rich Text Tabs ============

function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.rich-text-tab').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    // Show correct panel
    document.querySelectorAll('.rich-text-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
}

// ============ View Management ============

function showView(view) {
    document.getElementById('changeListView').style.display = view === 'list' ? '' : 'none';
    document.getElementById('changeDetailView').style.display = view === 'detail' ? '' : 'none';
    document.getElementById('changeEditorView').style.display = view === 'editor' ? '' : 'none';
}

function backToList() {
    currentChange = null;
    showView('list');
    loadChanges();
}

// ============ Utility Functions ============

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
           ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function toDatetimeLocal(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return '';
    // Format as YYYY-MM-DDTHH:MM for datetime-local input
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
           'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// ============ Share Functions ============

function toggleShareDropdown() {
    const menu = document.getElementById('shareDropdownMenu');
    menu.classList.toggle('active');
}

function closeShareDropdown() {
    const menu = document.getElementById('shareDropdownMenu');
    if (menu) menu.classList.remove('active');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.querySelector('.share-dropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        closeShareDropdown();
    }
});

function getChangeRef() {
    if (!currentChange) return '';
    return 'CHG-' + String(currentChange.id).padStart(4, '0');
}

function getChangeUrl() {
    return window.location.origin + window.location.pathname + '?change=' + currentChange.id;
}

function shareChangeLink() {
    closeShareDropdown();
    if (!currentChange) return;

    const url = getChangeUrl();
    navigator.clipboard.writeText(url).then(() => {
        showToast('Link copied to clipboard!');
    }).catch(() => {
        // Fallback for older browsers
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast('Link copied to clipboard!');
    });
}

function shareChangePdf() {
    closeShareDropdown();
    if (!currentChange) return;

    const pdfContent = buildPdfContent();
    const ref = getChangeRef();

    const opt = {
        margin: 10,
        filename: `${ref}_${currentChange.title.replace(/[^a-z0-9]/gi, '_')}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(pdfContent).save();
}

function shareChangeBoth() {
    closeShareDropdown();
    if (!currentChange) return;

    // Reset form
    document.getElementById('shareEmailTo').value = '';
    document.getElementById('shareEmailMessage').value = '';
    document.getElementById('shareIncludeLink').checked = true;
    document.getElementById('shareIncludePdf').checked = true;

    // Show modal
    document.getElementById('shareEmailModal').classList.add('active');
}

function closeShareEmailModal() {
    document.getElementById('shareEmailModal').classList.remove('active');
}

async function sendShareEmail() {
    const toEmail = document.getElementById('shareEmailTo').value.trim();
    const message = document.getElementById('shareEmailMessage').value.trim();
    const includeLink = document.getElementById('shareIncludeLink').checked;
    const includePdf = document.getElementById('shareIncludePdf').checked;

    if (!toEmail) {
        alert('Please enter a recipient email address');
        return;
    }

    if (!includeLink && !includePdf) {
        alert('Please select at least one option to include');
        return;
    }

    // Generate PDF if needed
    let pdfBase64 = null;
    const ref = getChangeRef();
    const pdfFilename = `${ref}_${currentChange.title.replace(/[^a-z0-9]/gi, '_')}.pdf`;

    if (includePdf) {
        const pdfContent = buildPdfContent();

        const opt = {
            margin: 10,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        try {
            const pdfBlob = await html2pdf().set(opt).from(pdfContent).outputPdf('blob');
            pdfBase64 = await blobToBase64(pdfBlob);
        } catch (error) {
            console.error('Error generating PDF:', error);
            alert('Error generating PDF. Please try again.');
            return;
        }
    }

    // Send email via API
    try {
        const response = await fetch(API_BASE + 'send_share_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to_email: toEmail,
                change_title: currentChange.title,
                change_ref: ref,
                change_url: includeLink ? getChangeUrl() : null,
                message: message,
                pdf_data: pdfBase64,
                pdf_filename: includePdf ? pdfFilename : null
            })
        });

        const data = await response.json();

        if (data.success) {
            closeShareEmailModal();
            showToast('Email sent successfully!');
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error sending email:', error);
        alert('Error sending email: ' + error.message);
    }
}

function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => {
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

function buildPdfContent() {
    const c = currentChange;
    const ref = getChangeRef();

    let html = `
        <div style="font-family: 'Segoe UI', Tahoma, sans-serif; padding: 20px;">
            <h1 style="color: #00897b; margin-bottom: 5px;">${ref}</h1>
            <h2 style="color: #333; margin-top: 0; margin-bottom: 20px;">${escapeHtml(c.title)}</h2>

            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 25%;"><strong>Status</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.status}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 25%;"><strong>Change Type</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.change_type}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Priority</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.priority}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Impact</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.impact}</td>
                </tr>
                ${c.category ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Category</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;" colspan="3">${escapeHtml(c.category)}</td>
                </tr>` : ''}
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Requester</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.requester_name || 'Not set'}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Assigned To</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.assigned_to_name || 'Not set'}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Approver</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;" colspan="3">${c.approver_name || 'Not set'}</td>
                </tr>
                ${c.work_start_datetime || c.work_end_datetime ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Work Start</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.work_start_datetime ? formatDateTime(c.work_start_datetime) : 'Not set'}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Work End</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.work_end_datetime ? formatDateTime(c.work_end_datetime) : 'Not set'}</td>
                </tr>` : ''}
                ${c.outage_start_datetime || c.outage_end_datetime ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Outage Start</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.outage_start_datetime ? formatDateTime(c.outage_start_datetime) : 'Not set'}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Outage End</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.outage_end_datetime ? formatDateTime(c.outage_end_datetime) : 'Not set'}</td>
                </tr>` : ''}
            </table>
    `;

    // Add detail sections
    const sections = [
        { title: 'Description', content: c.description },
        { title: 'Reason for Change', content: c.reason_for_change },
        { title: 'Risk Evaluation', content: c.risk_evaluation },
        { title: 'Test Plan', content: c.test_plan },
        { title: 'Rollback Plan', content: c.rollback_plan },
        { title: 'Post-Implementation Review', content: c.post_implementation_review }
    ];

    sections.forEach(section => {
        if (section.content && section.content.trim()) {
            html += `
                <h3 style="color: #00897b; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 20px;">${section.title}</h3>
                <div style="line-height: 1.6;">${section.content}</div>
            `;
        }
    });

    html += `
            <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0 15px 0;">
            <p style="font-size: 11px; color: #888;">
                Created: ${formatDateTime(c.created_datetime)}${c.created_by_name ? ' by ' + c.created_by_name : ''}<br>
                Last Modified: ${formatDateTime(c.modified_datetime)}
            </p>
        </div>
    `;

    const container = document.createElement('div');
    container.innerHTML = html;
    return container;
}

// Check for change ID in URL on page load (for shared links)
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const changeId = urlParams.get('change');
    if (changeId) {
        // Wait for changes to load, then open the specific change
        const checkAndLoad = setInterval(() => {
            if (changes.length > 0 || document.getElementById('changeList').innerHTML.includes('No changes')) {
                clearInterval(checkAndLoad);
                viewChange(changeId);
            }
        }, 100);
        // Timeout after 5 seconds
        setTimeout(() => clearInterval(checkAndLoad), 5000);
    }
})();
