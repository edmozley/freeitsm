/**
 * Inbox JavaScript - Service Desk Ticketing System
 */

// API base path - can be overridden by page before loading this script
// Default is 'api/' for root-level pages; module pages should set window.API_BASE = '../api/'
const API_BASE = window.API_BASE || 'api/';

let emails = [];
let selectedEmailId = null;
let composeMode = 'new';
let folderGrouping = 'department'; // 'department' or 'analyst' — persisted via user_preferences

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast' + (isError ? ' toast-error' : '');
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}
let departments = [];
let ticketTypes = [];
let ticketOrigins = [];
let ticketStatuses = [];
let analysts = [];
let currentEmail = null;
let folderCounts = {};
let currentFilter = { type: 'all' };
let expandedFolders = {};
let currentNotes = [];
let emailEditor = null;
let emailAttachments = [];
let ticketAttachments = []; // Attachments linked to current ticket

// Helper function to log audit entries
async function logAudit(ticketId, fieldName, oldValue, newValue) {
    try {
        await fetch(API_BASE + 'log_ticket_audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: ticketId,
                field_name: fieldName,
                old_value: oldValue,
                new_value: newValue
            })
        });
    } catch (error) {
        console.error('Error logging audit:', error);
    }
}

// Helper to get display name for IDs
function getDisplayName(type, id) {
    if (!id) return null;
    if (type === 'department') {
        const dept = departments.find(d => d.id == id);
        return dept ? dept.name : id;
    } else if (type === 'ticket_type') {
        const tt = ticketTypes.find(t => t.id == id);
        return tt ? tt.name : id;
    } else if (type === 'origin') {
        const o = ticketOrigins.find(x => x.id == id);
        return o ? o.name : id;
    } else if (type === 'owner') {
        const a = analysts.find(x => x.id == id);
        return a ? a.full_name : id;
    }
    return id;
}

// Resolve API base for shared endpoints (api/system/...) — works whether the page is at
// the repo root or inside a module folder.
function sharedApiBase() {
    return API_BASE.replace(/[^/]+\/?$/, '');
}

async function loadFolderGroupingPreference() {
    try {
        const res = await fetch(sharedApiBase() + 'system/get_user_preference.php?key=tickets_folder_grouping');
        const data = await res.json();
        if (data && data.success && (data.value === 'analyst' || data.value === 'department')) {
            folderGrouping = data.value;
        }
    } catch (e) { /* fall back to default */ }
    // Sync the toggle UI to whatever we ended up with
    document.querySelectorAll('.folder-group-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.group === folderGrouping);
    });
}

async function setFolderGrouping(mode) {
    if (mode !== 'department' && mode !== 'analyst') return;
    if (mode === folderGrouping) return;
    folderGrouping = mode;

    // Reset selection back to "All Tickets" so we don't leave a stale dept/analyst filter active
    currentFilter = { type: 'all' };
    document.getElementById('emailListTitle').textContent = 'All Tickets';

    // Update the toggle UI
    document.querySelectorAll('.folder-group-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.group === folderGrouping);
    });

    renderFolders();
    loadEmails();

    // Persist (fire-and-forget)
    fetch(sharedApiBase() + 'system/set_user_preference.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'tickets_folder_grouping', value: folderGrouping })
    }).catch(() => {});
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDepartments();
    loadTicketTypes();
    loadTicketOrigins();
    loadTicketStatuses();
    loadAnalysts();
    loadFolderGroupingPreference().then(loadFolderCounts);
    initTinyMCE();
    initAttachmentHandlers();

    // Load all tickets by default
    loadEmails();

    // Check for ticket_id in URL and auto-load that ticket
    const urlParams = new URLSearchParams(window.location.search);
    const ticketId = urlParams.get('ticket_id');
    if (ticketId) {
        // Small delay to ensure page is ready, then load the ticket
        setTimeout(() => loadTicketById(ticketId), 500);
    }
});

// Initialize attachment drag/drop and file input handlers
function initAttachmentHandlers() {
    const dropzone = document.getElementById('attachmentDropzone');
    const fileInput = document.getElementById('attachmentInput');

    if (!dropzone || !fileInput) return;

    // File input change handler
    fileInput.addEventListener('change', function(e) {
        handleFiles(e.target.files);
        fileInput.value = ''; // Reset so same file can be selected again
    });

    // Drag and drop handlers
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });

    // Click on dropzone to open file browser
    dropzone.addEventListener('click', function(e) {
        if (e.target.tagName !== 'A') {
            fileInput.click();
        }
    });
}

// Handle selected files
function handleFiles(files) {
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        // Check if file already added
        if (!emailAttachments.some(a => a.name === file.name && a.size === file.size)) {
            emailAttachments.push(file);
        }
    }
    renderAttachments();
}

// Render attachment list
function renderAttachments() {
    const list = document.getElementById('attachmentList');
    if (!list) return;

    if (emailAttachments.length === 0) {
        list.innerHTML = '';
        return;
    }

    list.innerHTML = emailAttachments.map((file, index) => `
        <div class="attachment-item">
            <div class="attachment-info">
                <span class="attachment-icon">${getFileIcon(file.name)}</span>
                <span class="attachment-name">${escapeHtml(file.name)}</span>
                <span class="attachment-size">(${formatFileSize(file.size)})</span>
            </div>
            <button class="attachment-remove" onclick="removeAttachment(${index})" title="Remove">&times;</button>
        </div>
    `).join('');
}

// Remove attachment by index
function removeAttachment(index) {
    emailAttachments.splice(index, 1);
    renderAttachments();
}

// Get file icon based on extension
function getFileIcon(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const icons = {
        'pdf': '📄',
        'doc': '📝', 'docx': '📝',
        'xls': '📊', 'xlsx': '📊',
        'ppt': '📽️', 'pptx': '📽️',
        'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'bmp': '🖼️',
        'zip': '📦', 'rar': '📦', '7z': '📦',
        'txt': '📃',
        'html': '🌐', 'htm': '🌐',
        'mp3': '🎵', 'wav': '🎵',
        'mp4': '🎬', 'avi': '🎬', 'mov': '🎬'
    };
    return icons[ext] || '📎';
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// Initialize TinyMCE editor
function initTinyMCE() {
    tinymce.init({
        selector: '#emailBody',
        license_key: 'gpl',
        height: 350,
        menubar: false,
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount'
        ],
        toolbar: 'undo redo | blocks | ' +
            'bold italic forecolor backcolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'link | removeformat | help',
        content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; }',
        extended_valid_elements: 'div[style|data-reply-marker]',
        setup: function(editor) {
            emailEditor = editor;
        }
    });
}

// Load departments (filtered by team membership)
async function loadDepartments() {
    try {
        // Use get_my_departments.php which filters based on team membership
        const response = await fetch(API_BASE + 'get_my_departments.php');
        const data = await response.json();

        if (data.success) {
            // Already filtered by API based on team membership
            departments = data.departments;
        }
    } catch (error) {
        console.error('Error loading departments:', error);
    }
}

// Load ticket types
async function loadTicketTypes() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_types.php');
        const data = await response.json();

        if (data.success) {
            ticketTypes = data.ticket_types.filter(t => t.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket types:', error);
    }
}

// Load ticket origins
async function loadTicketOrigins() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_origins.php');
        const data = await response.json();

        if (data.success) {
            ticketOrigins = data.origins.filter(o => o.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket origins:', error);
    }
}

// Load ticket statuses (active only) for the reading-pane Status dropdown
async function loadTicketStatuses() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_statuses.php');
        const data = await response.json();

        if (data.success) {
            ticketStatuses = data.statuses.filter(s => s.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket statuses:', error);
    }
}

// Load analysts
async function loadAnalysts() {
    try {
        const response = await fetch(API_BASE + 'get_analysts.php');
        const data = await response.json();

        if (data.success) {
            analysts = data.analysts.filter(a => a.is_active);
        }
    } catch (error) {
        console.error('Error loading analysts:', error);
    }
}

// Load folder counts
async function loadFolderCounts() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_counts.php');
        const data = await response.json();

        if (data.success) {
            folderCounts = data;
            renderFolders();
        }
    } catch (error) {
        console.error('Error loading folder counts:', error);
    }
}

// Render folder structure. Branches on folderGrouping (department vs analyst).
function renderFolders() {
    const folderListEl = document.getElementById('folderList');

    let html = '';

    // All Tickets folder
    html += `
        <div class="folder-item ${currentFilter.type === 'all' ? 'active' : ''}" data-folder-key="all" onclick="selectFolder('all')">
            <div class="folder-name">
                <span class="folder-icon">📬</span>
                <span>All Tickets</span>
            </div>
            <span class="folder-count">${folderCounts.total_count || 0}</span>
        </div>
    `;

    // Unassigned folder — semantics depend on grouping mode (no department vs no analyst)
    const unassignedCount = folderGrouping === 'analyst'
        ? (folderCounts.unassigned_analyst_count || 0)
        : (folderCounts.unassigned_count || 0);
    html += `
        <div class="folder-item drop-zone ${currentFilter.type === 'unassigned' ? 'active' : ''}"
             data-drop-type="unassigned" onclick="selectFolder('unassigned')">
            <div class="folder-name">
                <span class="folder-icon">⚠️</span>
                <span>Unassigned</span>
            </div>
            <span class="folder-count">${unassignedCount}</span>
        </div>
    `;

    html += '<div class="folder-divider"></div>';

    if (folderGrouping === 'analyst') {
        const analysts = folderCounts.analysts || [];
        analysts.forEach(an => {
            const folderKey = `analyst_${an.id}`;
            const isExpanded = expandedFolders[folderKey];
            const isActive = currentFilter.type === 'analyst' && currentFilter.id == an.id;

            html += `
                <div class="folder-item drop-zone ${isExpanded ? 'expanded' : ''} ${isActive ? 'active' : ''}"
                     data-drop-type="analyst" data-analyst-id="${an.id}"
                     onclick="toggleFolder('${folderKey}', ${an.id}, { kind: 'analyst' })">
                    <div class="folder-name">
                        <span class="folder-icon">👤</span>
                        <span>${escapeHtml(an.name)}</span>
                    </div>
                    <span class="folder-count">${an.count}</span>
                </div>
            `;

            html += `<div class="subfolder-group ${isExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
            const statuses = (folderCounts.statuses || []).map(s => s.name);
            statuses.forEach(status => {
                const count = (an.statuses || {})[status] || 0;
                const subActive = currentFilter.type === 'analyst_status' && currentFilter.analyst_id == an.id && currentFilter.status === status;
                html += `
                    <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                         data-drop-type="analyst_status" data-analyst-id="${an.id}" data-status="${escapeHtml(status)}">
                        <span>${escapeHtml(status)}</span>
                        <span class="folder-count">${count}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        });
    } else if (folderCounts.departments) {
        folderCounts.departments.forEach(dept => {
            const folderKey = `dept_${dept.id}`;
            const isExpanded = expandedFolders[folderKey];
            const isActive = currentFilter.type === 'department' && currentFilter.id == dept.id;

            html += `
                <div class="folder-item drop-zone ${isExpanded ? 'expanded' : ''} ${isActive ? 'active' : ''}"
                     data-drop-type="department" data-dept-id="${dept.id}"
                     onclick="toggleFolder('${folderKey}', ${dept.id}, { kind: 'department' })">
                    <div class="folder-name">
                        <span class="folder-icon"></span>
                        <span>${escapeHtml(dept.name)}</span>
                    </div>
                    <span class="folder-count">${dept.count}</span>
                </div>
            `;

            html += `<div class="subfolder-group ${isExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
            const statuses = (folderCounts.statuses || []).map(s => s.name);
            statuses.forEach(status => {
                const count = dept.statuses[status] || 0;
                const subActive = currentFilter.type === 'dept_status' && currentFilter.dept_id == dept.id && currentFilter.status === status;
                html += `
                    <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                         data-drop-type="dept_status" data-dept-id="${dept.id}" data-status="${escapeHtml(status)}">
                        <span>${escapeHtml(status)}</span>
                        <span class="folder-count">${count}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        });
    }

    folderListEl.innerHTML = html;

    // Wire drag-and-drop on freshly rendered folder rows
    attachFolderDropHandlers();
}

// Update only the .active class on existing folder/subfolder rows — does NOT rebuild
// the folder list. Used by selection paths so the .subfolder-group expand transition
// (which requires the element to persist) actually fires.
function updateActiveFolderClasses() {
    const list = document.getElementById('folderList');
    if (!list) return;
    list.querySelectorAll('.folder-item, .subfolder-item').forEach(el => el.classList.remove('active'));

    if (currentFilter.type === 'all') {
        list.querySelector('[data-folder-key="all"]')?.classList.add('active');
    } else if (currentFilter.type === 'unassigned') {
        list.querySelector('[data-drop-type="unassigned"]')?.classList.add('active');
    } else if (currentFilter.type === 'department') {
        list.querySelector(`[data-drop-type="department"][data-dept-id="${currentFilter.id}"]`)
            ?.classList.add('active');
    } else if (currentFilter.type === 'dept_status') {
        const sel = `.subfolder-item[data-dept-id="${currentFilter.dept_id}"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    } else if (currentFilter.type === 'analyst') {
        list.querySelector(`[data-drop-type="analyst"][data-analyst-id="${currentFilter.id}"]`)
            ?.classList.add('active');
    } else if (currentFilter.type === 'analyst_status') {
        const sel = `.subfolder-item[data-analyst-id="${currentFilter.analyst_id}"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    }
}

// Toggle folder expansion. Works for both department and analyst folders.
// opts.kind — 'department' (default) or 'analyst'
// opts.selectAfter — if false, don't change the active filter/view (used by drag hover)
// opts.forceExpand — if true, only expand (no toggle), used by drag hover
function toggleFolder(folderId, groupId, opts = {}) {
    const { selectAfter = true, forceExpand = false, kind = 'department' } = opts;
    const wasExpanded = !!expandedFolders[folderId];
    let willBeExpanded;
    if (forceExpand) {
        if (wasExpanded) return;
        willBeExpanded = true;
    } else {
        willBeExpanded = !wasExpanded;
    }
    expandedFolders[folderId] = willBeExpanded;

    // Targeted class flip on the existing nodes so the CSS grid-row transition fires.
    const list = document.getElementById('folderList');
    const dataAttr = kind === 'analyst' ? 'data-analyst-id' : 'data-dept-id';
    const folderRow = list?.querySelector(`.folder-item[data-drop-type="${kind}"][${dataAttr}="${groupId}"]`);
    const subGroup = folderRow?.nextElementSibling;
    folderRow?.classList.toggle('expanded', willBeExpanded);
    if (subGroup && subGroup.classList.contains('subfolder-group')) {
        subGroup.classList.toggle('expanded', willBeExpanded);
    }

    if (selectAfter) {
        if (kind === 'analyst') {
            currentFilter = { type: 'analyst', id: groupId };
            const an = folderCounts.analysts?.find(a => a.id == groupId);
            document.getElementById('emailListTitle').textContent = an ? an.name : 'Analyst';
        } else {
            currentFilter = { type: 'department', id: groupId };
            const dept = folderCounts.departments?.find(d => d.id == groupId);
            document.getElementById('emailListTitle').textContent = dept ? dept.name : 'Department';
        }
        updateActiveFolderClasses();
        loadEmails();
    }
}

// Select folder
function selectFolder(type, id = null) {
    if (type === 'all') {
        currentFilter = { type: 'all' };
        document.getElementById('emailListTitle').textContent = 'All Tickets';
    } else if (type === 'unassigned') {
        currentFilter = { type: 'unassigned' };
        document.getElementById('emailListTitle').textContent = 'Unassigned Tickets';
    } else if (type === 'department') {
        currentFilter = { type: 'department', id: id };
        const dept = folderCounts.departments.find(d => d.id == id);
        document.getElementById('emailListTitle').textContent = dept ? dept.name : 'Department';
    }

    updateActiveFolderClasses();
    loadEmails();
}

// Select department + status
function selectDeptStatus(deptId, status) {
    currentFilter = { type: 'dept_status', dept_id: deptId, status: status };
    const dept = folderCounts.departments.find(d => d.id == deptId);
    document.getElementById('emailListTitle').textContent = `${dept ? dept.name : 'Department'} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// Select analyst + status
function selectAnalystStatus(analystId, status) {
    currentFilter = { type: 'analyst_status', analyst_id: analystId, status: status };
    const an = folderCounts.analysts?.find(a => a.id == analystId);
    document.getElementById('emailListTitle').textContent = `${an ? an.name : 'Analyst'} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// ===== Drag-and-drop: tickets onto folders =====
let draggedTicketId = null;
let draggedTicketNumber = null;
let dragHoverTimer = null;
let dragHoverFolderId = null;

function attachEmailDragHandlers() {
    document.querySelectorAll('#emailList .email-item').forEach(el => {
        el.addEventListener('dragstart', (e) => {
            draggedTicketId = el.dataset.ticketId;
            draggedTicketNumber = el.dataset.ticketNumber;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedTicketId);
            el.classList.add('dragging');
        });
        el.addEventListener('dragend', () => {
            el.classList.remove('dragging');
            draggedTicketId = null;
            draggedTicketNumber = null;
            cancelDragHover();
            document.querySelectorAll('.drop-target').forEach(t => t.classList.remove('drop-target'));
        });
    });
}

function attachFolderDropHandlers() {
    // Click handler for subfolder rows (delegated so status names with apostrophes are safe)
    document.querySelectorAll('#folderList .subfolder-item').forEach(el => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropType = el.dataset.dropType;
            const status = el.dataset.status;
            if (dropType === 'analyst_status') {
                const analystId = parseInt(el.dataset.analystId, 10);
                if (analystId && status) selectAnalystStatus(analystId, status);
            } else {
                const deptId = parseInt(el.dataset.deptId, 10);
                if (deptId && status) selectDeptStatus(deptId, status);
            }
        });
    });

    document.querySelectorAll('#folderList .drop-zone').forEach(el => {
        el.addEventListener('dragover', (e) => {
            if (!draggedTicketId) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            el.classList.add('drop-target');

            // Hover-to-expand on collapsed group folders (works for both dept and analyst)
            const dt = el.dataset.dropType;
            if (dt === 'department' || dt === 'analyst') {
                const groupId = dt === 'analyst' ? el.dataset.analystId : el.dataset.deptId;
                const folderId = `${dt === 'analyst' ? 'analyst' : 'dept'}_${groupId}`;
                if (!expandedFolders[folderId]) {
                    if (dragHoverFolderId !== folderId) {
                        cancelDragHover();
                        dragHoverFolderId = folderId;
                        dragHoverTimer = setTimeout(() => {
                            toggleFolder(folderId, groupId, { selectAfter: false, forceExpand: true, kind: dt });
                            dragHoverTimer = null;
                        }, 600);
                    }
                }
            }
        });
        el.addEventListener('dragleave', (e) => {
            el.classList.remove('drop-target');
            const dt = el.dataset.dropType;
            // Only cancel hover timer if leaving the row that started it
            if (dt === 'department' && dragHoverFolderId === `dept_${el.dataset.deptId}`) {
                cancelDragHover();
            } else if (dt === 'analyst' && dragHoverFolderId === `analyst_${el.dataset.analystId}`) {
                cancelDragHover();
            }
        });
        el.addEventListener('drop', (e) => {
            e.preventDefault();
            el.classList.remove('drop-target');
            cancelDragHover();
            if (!draggedTicketId) return;
            handleTicketDrop(el, draggedTicketId, draggedTicketNumber);
        });
    });
}

function cancelDragHover() {
    if (dragHoverTimer) {
        clearTimeout(dragHoverTimer);
        dragHoverTimer = null;
    }
    dragHoverFolderId = null;
}

async function handleTicketDrop(targetEl, ticketId, ticketNumber) {
    const dropType = targetEl.dataset.dropType;
    const payload = { ticket_id: parseInt(ticketId, 10) };
    let toastMsg = '';

    // Capture old values from the in-memory email row for audit logging
    const sourceEmail = emails.find(e => String(e.ticket_id) === String(ticketId));
    const oldDeptName = sourceEmail ? getDisplayName('department', sourceEmail.department_id) : null;
    const oldStatusName = sourceEmail ? sourceEmail.status : null;
    const oldAnalystName = sourceEmail ? getDisplayName('owner', sourceEmail.assigned_analyst_id) : null;

    let newDeptName = null;
    let newStatusName = null;
    let newAnalystName = null;

    // "Unassigned" target means different things depending on the active grouping
    if (dropType === 'unassigned') {
        if (folderGrouping === 'analyst') {
            payload.assigned_analyst_id = '';
            toastMsg = `${ticketNumber || 'Ticket'} → Unassigned (no analyst)`;
        } else {
            payload.department_id = '';
            toastMsg = `${ticketNumber || 'Ticket'} → Unassigned`;
        }
    } else if (dropType === 'department') {
        payload.department_id = parseInt(targetEl.dataset.deptId, 10);
        const dept = folderCounts.departments.find(d => d.id == payload.department_id);
        newDeptName = dept ? dept.name : null;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newDeptName || 'Department'}`;
    } else if (dropType === 'dept_status') {
        payload.department_id = parseInt(targetEl.dataset.deptId, 10);
        payload.status = targetEl.dataset.status;
        const dept = folderCounts.departments.find(d => d.id == payload.department_id);
        newDeptName = dept ? dept.name : null;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newDeptName || 'Department'} / ${payload.status}`;
    } else if (dropType === 'analyst') {
        payload.assigned_analyst_id = parseInt(targetEl.dataset.analystId, 10);
        const an = folderCounts.analysts?.find(a => a.id == payload.assigned_analyst_id);
        newAnalystName = an ? an.name : null;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newAnalystName || 'Analyst'}`;
    } else if (dropType === 'analyst_status') {
        payload.assigned_analyst_id = parseInt(targetEl.dataset.analystId, 10);
        payload.status = targetEl.dataset.status;
        const an = folderCounts.analysts?.find(a => a.id == payload.assigned_analyst_id);
        newAnalystName = an ? an.name : null;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newAnalystName || 'Analyst'} / ${payload.status}`;
    } else {
        return;
    }

    try {
        const res = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Update failed');

        // Audit log — only for fields that actually changed
        const ticketIdInt = parseInt(ticketId, 10);
        const auditCalls = [];
        if (newDeptName !== oldDeptName && (dropType === 'department' || dropType === 'dept_status' || (dropType === 'unassigned' && folderGrouping !== 'analyst'))) {
            auditCalls.push(logAudit(ticketIdInt, 'Department', oldDeptName, newDeptName));
        }
        if (newStatusName !== null && newStatusName !== oldStatusName) {
            auditCalls.push(logAudit(ticketIdInt, 'Status', oldStatusName, newStatusName));
        }
        if (dropType === 'analyst' || dropType === 'analyst_status' || (dropType === 'unassigned' && folderGrouping === 'analyst')) {
            if (newAnalystName !== oldAnalystName) {
                auditCalls.push(logAudit(ticketIdInt, 'Owner', oldAnalystName, newAnalystName));
            }
        }
        await Promise.all(auditCalls);

        showToast(toastMsg);
        await loadFolderCounts();
        loadEmails();

        // If the dragged ticket is the one open in the reading pane, refresh it
        // so the Department/Status dropdowns show the new values.
        if (currentEmail && String(currentEmail.ticket_id) === String(ticketId)) {
            loadTicketById(currentEmail.ticket_id);
        }
    } catch (err) {
        console.error('Drop assign error:', err);
        showToast('Failed to move ticket: ' + (err.message || err), true);
    }
}

// Load emails based on current filter
async function loadEmails() {
    try {
        let url = API_BASE + 'get_emails.php?';

        if (currentFilter.type === 'unassigned') {
            // "Unassigned" semantics depend on the active grouping
            url += folderGrouping === 'analyst' ? 'assignee_id=unassigned' : 'department_id=unassigned';
        } else if (currentFilter.type === 'department') {
            url += `department_id=${currentFilter.id}`;
        } else if (currentFilter.type === 'dept_status') {
            url += `department_id=${currentFilter.dept_id}&status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'analyst') {
            url += `assignee_id=${currentFilter.id}`;
        } else if (currentFilter.type === 'analyst_status') {
            url += `assignee_id=${currentFilter.analyst_id}&status=${encodeURIComponent(currentFilter.status)}`;
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            emails = data.emails;
            renderEmailList();
        } else {
            alert('Error loading emails: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to load emails');
    }
}

// Render email list
function renderEmailList() {
    const emailListEl = document.getElementById('emailList');

    if (emails.length === 0) {
        emailListEl.innerHTML = '<div class="reading-pane-empty">No tickets found</div>';
        return;
    }

    emailListEl.innerHTML = emails.map(email => {
        const emailCount = email.email_count || 1;
        const countBadge = emailCount > 1 ? `<span class="email-count-badge">${emailCount}</span>` : '';
        const ticketId = email.ticket_id || email.id;
        return `
            <div class="email-item ${email.id === selectedEmailId ? 'selected' : ''} ${!email.is_read ? 'unread' : ''}"
                 draggable="true" data-ticket-id="${ticketId}" data-ticket-number="${escapeHtml(email.ticket_number || '')}"
                 onclick="selectEmail(${email.id})">
                <div class="email-from">${escapeHtml(email.ticket_number || '')} - ${escapeHtml(email.from_name || email.from_address)} ${countBadge}</div>
                <div class="email-subject">${escapeHtml(email.subject)}</div>
                <div class="email-preview">${escapeHtml(email.body_preview || '')}</div>
                <div class="email-time">${formatDateTime(email.received_datetime)}</div>
            </div>
        `;
    }).join('');

    // Wire drag handlers on freshly rendered email rows
    attachEmailDragHandlers();
}

// Select and display email by email ID
async function selectEmail(emailId) {
    selectedEmailId = emailId;
    renderEmailList();

    const readingPane = document.getElementById('readingPane');
    readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_BASE}get_email_detail.php?id=${emailId}`);
        const data = await response.json();

        if (data.success) {
            displayEmail(data.email);
        } else {
            readingPane.innerHTML = '<div class="reading-pane-empty">Error loading email</div>';
        }
    } catch (error) {
        console.error('Error:', error);
        readingPane.innerHTML = '<div class="reading-pane-empty">Failed to load email</div>';
    }
}

// Load and display ticket by ticket ID (from URL parameter)
async function loadTicketById(ticketId) {
    const readingPane = document.getElementById('readingPane');
    readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_BASE}get_email_detail.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            selectedEmailId = data.email.id;
            renderEmailList();
            displayEmail(data.email);
        } else {
            readingPane.innerHTML = '<div class="reading-pane-empty">Ticket not found</div>';
        }
    } catch (error) {
        console.error('Error:', error);
        readingPane.innerHTML = '<div class="reading-pane-empty">Failed to load ticket</div>';
    }
}

// Display email in reading pane
function displayEmail(email) {
    currentEmail = email;
    const readingPane = document.getElementById('readingPane');

    // Build department dropdown
    const departmentOptions = departments.map(dept =>
        `<option value="${dept.id}" ${email.department_id == dept.id ? 'selected' : ''}>${escapeHtml(dept.name)}</option>`
    ).join('');

    // Build ticket type dropdown
    const ticketTypeOptions = ticketTypes.map(type =>
        `<option value="${type.id}" ${email.ticket_type_id == type.id ? 'selected' : ''}>${escapeHtml(type.name)}</option>`
    ).join('');

    // Build status dropdown from the active ticket_statuses lookup
    const statusOptions = ticketStatuses.map(s =>
        `<option value="${escapeHtml(s.name)}" ${email.status === s.name ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
    ).join('');

    // Build ticket origin dropdown
    const originOptions = ticketOrigins.map(origin =>
        `<option value="${origin.id}" ${email.origin_id == origin.id ? 'selected' : ''}>${escapeHtml(origin.name)}</option>`
    ).join('');

    // Build first time fix dropdown
    const firstTimeFixOptions = `
        <option value="" ${email.first_time_fix === null ? 'selected' : ''}>--</option>
        <option value="1" ${email.first_time_fix === true || email.first_time_fix === 1 ? 'selected' : ''}>Yes</option>
        <option value="0" ${email.first_time_fix === false || email.first_time_fix === 0 ? 'selected' : ''}>No</option>
    `;

    // Build IT training provided dropdown
    const itTrainingOptions = `
        <option value="" ${email.it_training_provided === null ? 'selected' : ''}>--</option>
        <option value="1" ${email.it_training_provided === true || email.it_training_provided === 1 ? 'selected' : ''}>Yes</option>
        <option value="0" ${email.it_training_provided === false || email.it_training_provided === 0 ? 'selected' : ''}>No</option>
    `;

    // Build owner/analyst dropdown
    const ownerOptions = analysts.map(analyst =>
        `<option value="${analyst.id}" ${email.owner_id == analyst.id ? 'selected' : ''}>${escapeHtml(analyst.full_name)}</option>`
    ).join('');

    // Build summary values for collapsed view
    const summaryDept = getDisplayName('department', email.department_id) || 'None';
    const summaryStatus = email.status || 'Open';
    const summaryOwner = getDisplayName('owner', email.owner_id) || 'Unassigned';

    readingPane.innerHTML = `
        <div class="ticket-properties-container" id="ticketPropertiesContainer">
            <div class="ticket-properties-header" onclick="toggleTicketProperties(event)">
                <div class="ticket-properties-title">
                    <span class="ticket-properties-chevron">&#9660;</span>
                    Ticket Properties
                </div>
                <div class="ticket-properties-summary">
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">Dept:</span>
                        <span class="ticket-properties-summary-value" id="summaryDept">${escapeHtml(summaryDept)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">Status:</span>
                        <span class="ticket-properties-summary-value" id="summaryStatus">${escapeHtml(summaryStatus)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">Owner:</span>
                        <span class="ticket-properties-summary-value" id="summaryOwner">${escapeHtml(summaryOwner)}</span>
                    </span>
                </div>
            </div>
            <div class="ticket-properties-panel">
                <div class="ticket-toolbar">
                    <div class="toolbar-field">
                        <label class="toolbar-label">Department</label>
                        <select class="toolbar-select" id="departmentSelect" onchange="assignDepartment()">
                            <option value=""></option>
                            ${departmentOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">Type</label>
                        <select class="toolbar-select" id="ticketTypeSelect" onchange="assignTicketType()">
                            <option value=""></option>
                            ${ticketTypeOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">Status</label>
                        <select class="toolbar-select" id="statusSelect" onchange="assignStatus()">
                            ${statusOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">Origin</label>
                        <select class="toolbar-select" id="originSelect" onchange="assignOrigin()">
                            <option value=""></option>
                            ${originOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">First Time Fix</label>
                        <select class="toolbar-select" id="firstTimeFixSelect" onchange="assignFirstTimeFix()">
                            ${firstTimeFixOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">IT Training</label>
                        <select class="toolbar-select" id="itTrainingSelect" onchange="assignItTraining()">
                            ${itTrainingOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">Owner</label>
                        <select class="toolbar-select" id="ownerSelect" onchange="assignOwner()">
                            <option value=""></option>
                            ${ownerOptions}
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="email-header">
            <div class="email-subject-line">Ticket ${escapeHtml(email.ticket_number || '')} - ${escapeHtml(email.subject)}</div>
            <div class="email-meta">
                <div class="email-meta-row">
                    <div class="email-meta-label">From:</div>
                    <div class="email-meta-value">${escapeHtml(email.from_name)} &lt;${escapeHtml(email.from_address)}&gt;</div>
                </div>
                <div class="email-meta-row">
                    <div class="email-meta-label">To:</div>
                    <div class="email-meta-value">${escapeHtml(email.to_recipients)}</div>
                </div>
                ${email.cc_recipients ? `
                <div class="email-meta-row">
                    <div class="email-meta-label">Cc:</div>
                    <div class="email-meta-value">${escapeHtml(email.cc_recipients)}</div>
                </div>
                ` : ''}
                <div class="email-meta-row">
                    <div class="email-meta-label">Date:</div>
                    <div class="email-meta-value">${formatFullDateTime(email.received_datetime)}</div>
                </div>
            </div>
        </div>
        <div class="attachment-info-bar" id="attachmentInfoBar" onclick="showAttachmentList()" style="display: none;">
            <span class="attachment-info-icon">📎</span>
            <span>Loading attachments...</span>
        </div>
        <div class="action-toolbar">
            <button class="action-btn" onclick="openNoteModal()">
                <span class="action-btn-icon">📝</span>
                <span>Add Note</span>
            </button>
            <button class="action-btn" onclick="openReplyModal()">
                <span class="action-btn-icon">↩️</span>
                <span>Reply</span>
            </button>
            <button class="action-btn" onclick="openForwardModal()">
                <span class="action-btn-icon">➡️</span>
                <span>Forward</span>
            </button>
            <button class="action-btn" onclick="openScheduleModal()">
                <span class="action-btn-icon">📅</span>
                <span>Schedule</span>
            </button>
            <button class="action-btn" onclick="openTicketAiChat()">
                <span class="action-btn-icon">🤖</span>
                <span>Ask AI</span>
            </button>
            <button class="action-btn" onclick="showAuditHistory()">
                <span class="action-btn-icon">📋</span>
                <span>Audit</span>
            </button>
            <button class="action-btn action-btn-danger" onclick="deleteTicket()">
                <span class="action-btn-icon">🗑️</span>
                <span>Delete</span>
            </button>
        </div>
        <div class="email-body">
            <div id="threadContainer">
                <div class="email-body-content">${email.body_content}</div>
            </div>
            <div id="cmdbObjectsContainer"></div>
            <div id="notesContainer"></div>
        </div>
    `;

    // Load full correspondence thread, notes, attachments and linked CMDB objects after rendering
    loadCorrespondenceThread(email.ticket_id);
    loadNotes(email.ticket_id);
    loadTicketAttachments(email.ticket_id);
    loadCmdbObjects(email.ticket_id);
}

// Load and display all correspondence for a ticket
async function loadCorrespondenceThread(ticketId) {
    const container = document.getElementById('threadContainer');
    if (!container) return;

    try {
        const response = await fetch(`${API_BASE}get_ticket_thread.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (!data.success || !data.emails || data.emails.length === 0) return;

        // Reverse so most recent email is at the top
        const emails = [...data.emails].reverse();

        container.innerHTML = emails.map((e, index) => {
            const isOutbound = e.direction === 'Outbound';
            return `
                ${index > 0 ? '<div class="thread-separator"></div>' : ''}
                <div class="thread-meta">
                    <span class="thread-direction-badge ${isOutbound ? 'outbound' : 'inbound'}">${isOutbound ? 'Sent' : 'Received'}</span>
                    <strong>${escapeHtml(e.from_name || e.from_address)}</strong>
                    &lt;${escapeHtml(e.from_address)}&gt; &mdash; ${formatFullDateTime(e.received_datetime)}
                </div>
                <div class="thread-message-body">${e.body_content}</div>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading thread:', error);
    }
}

// Assign department
async function assignDepartment() {
    const departmentId = document.getElementById('departmentSelect').value;
    const oldValue = getDisplayName('department', currentEmail.department_id);
    const newValue = getDisplayName('department', departmentId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                department_id: departmentId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Department', oldValue, newValue);
            currentEmail.department_id = departmentId || null;
            updatePropertiesSummary();
            loadFolderCounts();
            loadEmails();
        } else {
            alert('Error assigning department: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign department');
    }
}

// Assign ticket type
async function assignTicketType() {
    const ticketTypeId = document.getElementById('ticketTypeSelect').value;
    const oldValue = getDisplayName('ticket_type', currentEmail.ticket_type_id);
    const newValue = getDisplayName('ticket_type', ticketTypeId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                ticket_type_id: ticketTypeId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Ticket Type', oldValue, newValue);
            currentEmail.ticket_type_id = ticketTypeId || null;
        } else {
            alert('Error assigning ticket type: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign ticket type');
    }
}

// Assign status
async function assignStatus() {
    const status = document.getElementById('statusSelect').value;
    const oldValue = currentEmail.status;

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                status: status
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Status', oldValue, status);
            currentEmail.status = status;
            updatePropertiesSummary();
            loadFolderCounts();
            loadEmails();
        } else {
            alert('Error assigning status: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign status');
    }
}

// Assign origin
async function assignOrigin() {
    const originId = document.getElementById('originSelect').value;
    const oldValue = getDisplayName('origin', currentEmail.origin_id);
    const newValue = getDisplayName('origin', originId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                origin_id: originId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Origin', oldValue, newValue);
            currentEmail.origin_id = originId || null;
        } else {
            alert('Error assigning origin: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign origin');
    }
}

// Assign first time fix
async function assignFirstTimeFix() {
    const value = document.getElementById('firstTimeFixSelect').value;
    const oldValue = currentEmail.first_time_fix === null ? null : (currentEmail.first_time_fix ? 'Yes' : 'No');
    const newValue = value === '' ? null : (value === '1' ? 'Yes' : 'No');

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                first_time_fix: value === '' ? null : (value === '1' ? 1 : 0)
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'First Time Fix', oldValue, newValue);
            currentEmail.first_time_fix = value === '' ? null : (value === '1');
        } else {
            alert('Error assigning first time fix: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign first time fix');
    }
}

// Assign IT training provided
async function assignItTraining() {
    const value = document.getElementById('itTrainingSelect').value;
    const oldValue = currentEmail.it_training_provided === null ? null : (currentEmail.it_training_provided ? 'Yes' : 'No');
    const newValue = value === '' ? null : (value === '1' ? 'Yes' : 'No');

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                it_training_provided: value === '' ? null : (value === '1' ? 1 : 0)
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'IT Training', oldValue, newValue);
            currentEmail.it_training_provided = value === '' ? null : (value === '1');
        } else {
            alert('Error assigning IT training: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign IT training');
    }
}

// Assign owner (analyst)
async function assignOwner() {
    const ownerId = document.getElementById('ownerSelect').value;
    const oldValue = getDisplayName('owner', currentEmail.owner_id);
    const newValue = getDisplayName('owner', ownerId);

    try {
        const response = await fetch(API_BASE + 'update_ticket_owner.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                owner_id: ownerId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Owner', oldValue, newValue);
            currentEmail.owner_id = ownerId || null;
            updatePropertiesSummary();
        } else {
            alert('Error assigning owner: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to assign owner');
    }
}

// Delete ticket
async function deleteTicket() {
    if (!currentEmail || !currentEmail.ticket_id) {
        alert('No ticket selected');
        return;
    }

    if (!confirm('Are you sure you want to delete this ticket? This will permanently delete the ticket and all associated emails and notes.')) {
        return;
    }

    try {
        const response = await fetch(API_BASE + 'delete_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id
            })
        });
        const data = await response.json();

        if (data.success) {
            // Clear current selection
            currentEmail = null;
            selectedEmailId = null;

            // Clear reading pane
            document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';

            // Refresh folder counts and email list
            loadFolderCounts();
            loadEmails();
        } else {
            alert('Error deleting ticket: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to delete ticket');
    }
}

// Show audit history modal
async function showAuditHistory() {
    if (!currentEmail || !currentEmail.ticket_id) {
        alert('No ticket selected');
        return;
    }

    try {
        const response = await fetch(`${API_BASE}get_ticket_audit.php?ticket_id=${currentEmail.ticket_id}`);
        const data = await response.json();

        if (data.success) {
            const auditHtml = data.audit.length === 0
                ? '<p style="text-align: center; color: #888;">No audit history for this ticket.</p>'
                : `<table class="audit-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Analyst</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.audit.map(entry => `
                            <tr>
                                <td>${formatFullDateTime(entry.created_datetime)}</td>
                                <td>${escapeHtml(entry.analyst_name || 'Unknown')}</td>
                                <td>${escapeHtml(entry.field_name)}</td>
                                <td>${escapeHtml(entry.old_value || '-')}</td>
                                <td>${escapeHtml(entry.new_value || '-')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;

            // Create modal
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'auditModal';
            modal.innerHTML = `
                <div class="modal-content audit-modal">
                    <div class="modal-header">
                        <h3>Audit History - ${escapeHtml(currentEmail.ticket_number)}</h3>
                        <button class="modal-close" onclick="closeAuditModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${auditHtml}
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        } else {
            alert('Error loading audit history: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to load audit history');
    }
}

// Close audit modal
function closeAuditModal() {
    const modal = document.getElementById('auditModal');
    if (modal) {
        modal.remove();
    }
}

// Refresh current view
function refreshCurrentView() {
    loadFolderCounts();
    if (currentFilter.type !== 'none') {
        loadEmails();
    }
}

// Utility: Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Utility: Format date/time
// Parse a DB datetime string as UTC (append Z if no timezone indicator)
function parseUTCDate(dateStr) {
    if (!dateStr) return null;
    // If the string doesn't already end with Z or have a timezone offset, treat as UTC
    if (!/[Z+\-]\d{0,4}$/.test(dateStr)) {
        dateStr = dateStr.replace(' ', 'T') + 'Z';
    }
    return new Date(dateStr);
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const emailDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());

    if (emailDate.getTime() === today.getTime()) {
        return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } else if (emailDate.getTime() === today.getTime() - 86400000) {
        return 'Yesterday ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } else {
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' +
               date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }
}

// Utility: Format full date/time (always shows date and time)
function formatFullDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    return date.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    }) + ' ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

// Toggle ticket properties panel
function toggleTicketProperties(event) {
    event.stopPropagation();
    const container = document.getElementById('ticketPropertiesContainer');
    if (container) {
        container.classList.toggle('expanded');
    }
}

// Close ticket properties panel when clicking outside
document.addEventListener('click', function(event) {
    const container = document.getElementById('ticketPropertiesContainer');
    if (container && container.classList.contains('expanded')) {
        // Check if click is outside the properties container
        if (!container.contains(event.target)) {
            container.classList.remove('expanded');
        }
    }
});

// Update summary values when properties change
function updatePropertiesSummary() {
    const summaryDept = document.getElementById('summaryDept');
    const summaryStatus = document.getElementById('summaryStatus');
    const summaryOwner = document.getElementById('summaryOwner');

    if (summaryDept && currentEmail) {
        summaryDept.textContent = getDisplayName('department', currentEmail.department_id) || 'None';
    }
    if (summaryStatus && currentEmail) {
        summaryStatus.textContent = currentEmail.status || 'Open';
    }
    if (summaryOwner && currentEmail) {
        summaryOwner.textContent = getDisplayName('owner', currentEmail.owner_id) || 'Unassigned';
    }
}

// ===== Linked CMDB objects on a ticket =====
// Renders an "Affected CMDB" section in the reading pane below the email
// thread. Click + Link to add (autocomplete searches every CMDB object);
// X on a card removes the link.

let cmdbObjectsForTicket = [];
let cmdbAcTimer = null;
let cmdbAcHighlightedIdx = -1;

async function loadCmdbObjects(ticketId) {
    const container = document.getElementById('cmdbObjectsContainer');
    if (!container) return;
    container.innerHTML = '';
    try {
        const res = await fetch('../api/tickets/get_ticket_cmdb_objects.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) return;
        cmdbObjectsForTicket = data.links || [];
        renderCmdbObjects(ticketId);
    } catch (e) { /* silent — section will just stay empty */ }
}

function renderCmdbObjects(ticketId) {
    const container = document.getElementById('cmdbObjectsContainer');
    if (!container) return;
    const cards = cmdbObjectsForTicket.map(link => `
        <a class="cmdb-link-card" href="../cmdb/object.php?id=${link.object_id}" title="Open in CMDB">
            <div class="cmdb-link-card-body">
                <div class="cmdb-link-card-name">${escapeHtml(link.name)}</div>
                <div class="cmdb-link-card-meta">
                    <span class="cmdb-class-badge">${escapeHtml(link.class_name)}</span>
                    ${link.parent_name ? `<span class="cmdb-parent">in <strong>${escapeHtml(link.parent_name)}</strong> (${escapeHtml(link.parent_class_name || '')})</span>` : ''}
                </div>
            </div>
            <button class="cmdb-link-x" title="Unlink" onclick="removeCmdbObject(event, ${link.link_id}, ${ticketId})">×</button>
        </a>
    `).join('');

    container.innerHTML = `
        <div class="cmdb-section">
            <div class="cmdb-section-head">
                <h3>Affected CMDB Objects</h3>
                <button class="btn-link" onclick="openLinkCmdbPicker(${ticketId})">+ Link object</button>
            </div>
            ${cmdbObjectsForTicket.length === 0
                ? '<div class="cmdb-empty">No CMDB objects linked yet.</div>'
                : `<div class="cmdb-link-list">${cards}</div>`}
            <div class="cmdb-picker" id="cmdbPicker_${ticketId}" style="display:none;">
                <input type="text" id="cmdbPickerInput_${ticketId}" placeholder="Type to search any CMDB object…" autocomplete="off">
                <div class="cmdb-picker-results" id="cmdbPickerResults_${ticketId}"></div>
            </div>
        </div>
    `;
}

function openLinkCmdbPicker(ticketId) {
    const picker = document.getElementById('cmdbPicker_' + ticketId);
    const input  = document.getElementById('cmdbPickerInput_' + ticketId);
    const results = document.getElementById('cmdbPickerResults_' + ticketId);
    if (!picker || !input) return;
    picker.style.display = 'block';
    input.value = '';
    results.classList.remove('active');
    input.focus();

    let current = [];
    cmdbAcHighlightedIdx = -1;

    const renderResults = () => {
        if (current.length === 0) {
            results.innerHTML = '<div class="cmdb-picker-empty">No matches.</div>';
            results.classList.add('active');
            return;
        }
        results.innerHTML = current.map((r, i) => `
            <div class="cmdb-picker-result ${i === cmdbAcHighlightedIdx ? 'highlighted' : ''}" data-idx="${i}">
                <span>${escapeHtml(r.name)}</span>
                <span class="cmdb-picker-class">${escapeHtml(r.class_name)}</span>
            </div>`).join('');
        results.classList.add('active');
        results.querySelectorAll('.cmdb-picker-result').forEach(el => {
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                pick(current[parseInt(el.dataset.idx, 10)]);
            });
        });
    };

    const pick = async (r) => {
        try {
            const res = await fetch('../api/tickets/save_ticket_cmdb_object.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: ticketId, cmdb_object_id: r.id })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Link failed');
            if (data.already_linked) {
                showToast(r.name + ' is already linked', true);
            } else {
                showToast('Linked ' + r.name);
            }
            picker.style.display = 'none';
            await loadCmdbObjects(ticketId);
        } catch (err) {
            showToast('Error: ' + err.message, true);
        }
    };

    input.oninput = () => {
        const q = input.value.trim();
        if (cmdbAcTimer) clearTimeout(cmdbAcTimer);
        if (q === '') { results.classList.remove('active'); return; }
        cmdbAcTimer = setTimeout(async () => {
            try {
                const url = '../api/cmdb/search_objects.php?q=' + encodeURIComponent(q);
                const res = await fetch(url);
                const data = await res.json();
                current = data.success ? (data.results || []) : [];
                cmdbAcHighlightedIdx = -1;
                renderResults();
            } catch (e) { /* silent */ }
        }, 200);
    };

    input.onkeydown = e => {
        if (!results.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); cmdbAcHighlightedIdx = Math.min(current.length - 1, cmdbAcHighlightedIdx + 1); renderResults(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); cmdbAcHighlightedIdx = Math.max(0, cmdbAcHighlightedIdx - 1); renderResults(); }
        else if (e.key === 'Enter' && cmdbAcHighlightedIdx >= 0) { e.preventDefault(); pick(current[cmdbAcHighlightedIdx]); }
        else if (e.key === 'Escape') { picker.style.display = 'none'; }
    };
}

async function removeCmdbObject(ev, linkId, ticketId) {
    ev.preventDefault();
    ev.stopPropagation();
    if (!confirm('Unlink this CMDB object from the ticket?')) return;
    try {
        const res = await fetch('../api/tickets/delete_ticket_cmdb_object.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unlink failed');
        showToast('Unlinked');
        await loadCmdbObjects(ticketId);
    } catch (err) {
        showToast('Error: ' + err.message, true);
    }
}

// Load notes for a ticket
async function loadNotes(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_notes.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            currentNotes = data.notes;
            renderNotes();
        }
    } catch (error) {
        console.error('Error loading notes:', error);
    }
}

// Load attachments for a ticket
async function loadTicketAttachments(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_ticket_attachments.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            ticketAttachments = data.attachments;
            renderAttachmentInfoBar();
        }
    } catch (error) {
        console.error('Error loading attachments:', error);
    }
}

// Render the attachment info bar
function renderAttachmentInfoBar() {
    const infoBar = document.getElementById('attachmentInfoBar');
    if (!infoBar) return;

    if (ticketAttachments.length > 0) {
        const regularCount = ticketAttachments.filter(a => !a.is_inline).length;
        const inlineCount = ticketAttachments.filter(a => a.is_inline).length;

        let message = '';
        if (regularCount > 0 && inlineCount > 0) {
            message = `${regularCount} attachment${regularCount === 1 ? '' : 's'} + ${inlineCount} inline`;
        } else if (regularCount > 0) {
            message = `${regularCount} attachment${regularCount === 1 ? '' : 's'}`;
        } else {
            message = `${inlineCount} inline attachment${inlineCount === 1 ? '' : 's'}`;
        }

        infoBar.style.display = 'block';
        infoBar.innerHTML = `
            <span class="attachment-info-icon">📎</span>
            <span>This ticket has ${message} linked to it</span>
        `;
    } else {
        infoBar.style.display = 'none';
    }
}

// Format date as dd/mm/yyyy hh:mm
function formatDateDMY(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${day}/${month}/${year} ${hours}:${minutes}`;
}

// Show attachment list modal
function showAttachmentList() {
    if (ticketAttachments.length === 0) return;

    const tableHtml = `
        <table class="attachment-modal-table">
            <thead>
                <tr>
                    <th>From</th>
                    <th>Date/Time</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Type</th>
                </tr>
            </thead>
            <tbody>
                ${ticketAttachments.map(att => `
                    <tr onclick="openAttachment(${att.id})" class="attachment-row" title="Click to download">
                        <td>${escapeHtml(att.from_name || att.from_address || '')}</td>
                        <td>${formatDateDMY(att.received_datetime)}</td>
                        <td>
                            <span class="attachment-icon">${getFileIcon(att.filename)}</span>
                            ${escapeHtml(att.filename)}
                        </td>
                        <td>${formatFileSize(att.file_size || 0)}</td>
                        <td>${att.is_inline ? '<span class="inline-badge">Inline</span>' : ''}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = 'attachmentListModal';
    modal.innerHTML = `
        <div class="modal-content attachment-list-modal">
            <button class="modal-close-top" onclick="closeAttachmentListModal()">&times;</button>
            <div class="modal-header">
                <h3>Attachments - ${escapeHtml(currentEmail.ticket_number)}</h3>
            </div>
            <div class="modal-body">
                ${tableHtml}
            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

// Close attachment list modal
function closeAttachmentListModal() {
    const modal = document.getElementById('attachmentListModal');
    if (modal) {
        modal.remove();
    }
}

// Open/download an attachment
function openAttachment(attachmentId) {
    window.open(`${API_BASE}get_attachment.php?id=${attachmentId}`, '_blank');
}

// Render notes
function renderNotes() {
    const container = document.getElementById('notesContainer');

    if (!currentNotes || currentNotes.length === 0) {
        container.innerHTML = '';
        return;
    }

    let html = '<div class="notes-section"><div class="notes-header">Notes</div>';

    currentNotes.forEach(note => {
        html += `
            <div class="note-item">
                <div class="note-header">
                    <span class="note-author">${escapeHtml(note.analyst_name)}</span>
                    <span>${formatDateTime(note.created_datetime)}</span>
                </div>
                <div class="note-text">${escapeHtml(note.note_text)}</div>
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

// Open note modal
function openNoteModal() {
    document.getElementById('noteText').value = '';
    document.getElementById('noteModal').classList.add('active');
}

// Close note modal
function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('active');
}

// Save note
async function saveNote() {
    const noteText = document.getElementById('noteText').value.trim();

    if (!noteText) {
        alert('Please enter a note');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                note_text: noteText,
                is_internal: true
            })
        });
        const data = await response.json();

        if (data.success) {
            closeNoteModal();
            loadNotes(currentEmail.ticket_id);
        } else {
            alert('Error saving note: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to save note');
    }
}

// Open reply modal
function openReplyModal() {
    composeMode = 'reply';
    document.getElementById('emailTo').value = currentEmail.from_address;
    document.getElementById('emailCc').value = '';
    // Add ticket reference to subject if not already present
    let subject = currentEmail.subject;
    const ticketRef = `[SDREF:${currentEmail.ticket_number}]`;
    if (!subject.includes(ticketRef)) {
        subject = `RE: ${subject} ${ticketRef}`;
    } else {
        subject = `RE: ${subject}`;
    }
    document.getElementById('emailSubject').value = subject;

    // Empty editor - server will assemble the full thread when sending
    if (emailEditor) {
        emailEditor.setContent('<p><br></p>');
    }

    setReplyCleanupVisibility('reply');
    document.getElementById('emailModal').classList.add('active');
}

// Open forward modal
function openForwardModal() {
    composeMode = 'forward';
    document.getElementById('emailTo').value = '';
    document.getElementById('emailCc').value = '';
    // Add ticket reference to subject if not already present
    let subject = currentEmail.subject;
    const ticketRef = `[SDREF:${currentEmail.ticket_number}]`;
    if (!subject.includes(ticketRef)) {
        subject = `FW: ${subject} ${ticketRef}`;
    } else {
        subject = `FW: ${subject}`;
    }
    document.getElementById('emailSubject').value = subject;

    // Empty editor - server will assemble the full thread when sending
    if (emailEditor) {
        emailEditor.setContent('<p><br></p>');
    }

    setReplyCleanupVisibility('forward');
    document.getElementById('emailModal').classList.add('active');
}

// Close email modal
function closeEmailModal() {
    document.getElementById('emailModal').classList.remove('active');
    composeMode = 'new';
    // Clear the TinyMCE content
    if (emailEditor) {
        emailEditor.setContent('');
    }
    // Clear attachments
    emailAttachments = [];
    renderAttachments();
    hideReplyCleanupUndoBar();
}

// ===== Reply Cleanup AI =====

let replyCleanupOriginalDraft = null;
let replyCleanupUndoTimer = null;
let replyCleanupCountdownTimer = null;

function setReplyCleanupVisibility(mode) {
    const btn = document.getElementById('replyCleanupBtn');
    if (btn) btn.style.display = (mode === 'reply') ? '' : 'none';
    hideReplyCleanupUndoBar();
}

// Convert plain-text-with-blank-lines (Claude's output) to TinyMCE-friendly HTML
function replyCleanupTextToHtml(text) {
    if (!text) return '<p><br></p>';
    // Normalise newlines, split on 2+ newlines for paragraphs, single newlines become <br>
    const normalised = text.replace(/\r\n/g, '\n');
    const paragraphs = normalised.split(/\n{2,}/);
    return paragraphs.map(p => {
        const safe = escapeHtml(p).replace(/\n/g, '<br>');
        return `<p>${safe}</p>`;
    }).join('');
}

async function cleanupReplyDraft() {
    if (!emailEditor) return;
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket loaded', true);
        return;
    }

    const editorContent = emailEditor.getContent({ format: 'text' }).trim();
    if (editorContent === '') {
        showToast('Type something first, then click Cleanup', true);
        return;
    }

    // Stash original HTML so the undo link can restore it verbatim
    replyCleanupOriginalDraft = emailEditor.getContent();

    const cleanupBtn = document.getElementById('replyCleanupBtn');
    const sendBtn = document.getElementById('replySendBtn');
    cleanupBtn.disabled = true;
    sendBtn.disabled = true;
    cleanupBtn.classList.add('is-loading');
    cleanupBtn.innerHTML = '<span class="spinner-inline"></span> Cleaning up…';
    hideReplyCleanupUndoBar();

    // Clear the editor — we'll stream into it.
    emailEditor.setContent('<p><em style="color:#999;">Cleaning up…</em></p>');

    let buffer = '';
    let firstChunk = true;
    let streamFailed = false;

    try {
        const res = await fetch(API_BASE + 'ai_cleanup_reply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                draft_text: editorContent,
            }),
        });

        if (!res.ok || !res.body) {
            throw new Error('HTTP ' + res.status);
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let sseBuffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            sseBuffer += decoder.decode(value, { stream: true });

            // Split on SSE event boundaries (\n\n)
            let idx;
            while ((idx = sseBuffer.indexOf('\n\n')) !== -1) {
                const block = sseBuffer.slice(0, idx);
                sseBuffer = sseBuffer.slice(idx + 2);

                let eventName = '';
                let dataLine = '';
                for (const line of block.split('\n')) {
                    if (line.startsWith('event: ')) eventName = line.slice(7).trim();
                    else if (line.startsWith('data: ')) dataLine += line.slice(6);
                }
                if (!dataLine) continue;
                let payload;
                try { payload = JSON.parse(dataLine); } catch { continue; }

                if (eventName === 'text') {
                    if (firstChunk) {
                        emailEditor.setContent('');
                        firstChunk = false;
                    }
                    buffer += payload.delta || '';
                    emailEditor.setContent(replyCleanupTextToHtml(buffer));
                } else if (eventName === 'error') {
                    streamFailed = true;
                    showToast(payload.message || 'Cleanup failed', true);
                    break;
                }
                // 'usage' / 'done' events are ignored for this UI
            }

            if (streamFailed) break;
        }
    } catch (err) {
        streamFailed = true;
        console.error('Cleanup error:', err);
        showToast('Cleanup failed: ' + err.message, true);
    }

    cleanupBtn.disabled = false;
    sendBtn.disabled = false;
    cleanupBtn.classList.remove('is-loading');
    cleanupBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg> Cleanup';

    if (streamFailed) {
        // Restore the user's original draft so they don't lose their typing
        if (replyCleanupOriginalDraft !== null) {
            emailEditor.setContent(replyCleanupOriginalDraft);
        }
        return;
    }

    showReplyCleanupUndoBar();
}

function showReplyCleanupUndoBar() {
    const bar = document.getElementById('replyCleanupUndoBar');
    const timer = document.getElementById('replyCleanupUndoTimer');
    if (!bar) return;
    bar.style.display = 'block';

    let secondsLeft = 30;
    timer.textContent = `(${secondsLeft}s)`;

    if (replyCleanupCountdownTimer) clearInterval(replyCleanupCountdownTimer);
    replyCleanupCountdownTimer = setInterval(() => {
        secondsLeft--;
        if (secondsLeft <= 0) {
            hideReplyCleanupUndoBar();
        } else {
            timer.textContent = `(${secondsLeft}s)`;
        }
    }, 1000);

    if (replyCleanupUndoTimer) clearTimeout(replyCleanupUndoTimer);
    replyCleanupUndoTimer = setTimeout(hideReplyCleanupUndoBar, 30000);
}

function hideReplyCleanupUndoBar() {
    const bar = document.getElementById('replyCleanupUndoBar');
    if (bar) bar.style.display = 'none';
    if (replyCleanupUndoTimer) {
        clearTimeout(replyCleanupUndoTimer);
        replyCleanupUndoTimer = null;
    }
    if (replyCleanupCountdownTimer) {
        clearInterval(replyCleanupCountdownTimer);
        replyCleanupCountdownTimer = null;
    }
}

// Wire the Undo link once on first inbox.js load
document.addEventListener('DOMContentLoaded', function() {
    const undoLink = document.getElementById('replyCleanupUndoLink');
    if (undoLink) {
        undoLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (replyCleanupOriginalDraft !== null && emailEditor) {
                emailEditor.setContent(replyCleanupOriginalDraft);
                showToast('Restored your original draft');
            }
            hideReplyCleanupUndoBar();
        });
    }
});

// Send email via Microsoft Graph API
async function sendEmail() {
    // Get values from form
    const to = document.getElementById('emailTo').value.trim();
    const cc = document.getElementById('emailCc').value.trim();
    const subject = document.getElementById('emailSubject').value;
    const body = emailEditor ? emailEditor.getContent() : '';

    // Basic validation
    if (!to) {
        alert('Please enter a recipient email address');
        return;
    }
    if (!subject) {
        alert('Please enter a subject');
        return;
    }

    // Get send button and show loading state
    const sendBtn = document.querySelector('#emailModal .btn-primary');
    const originalText = sendBtn.textContent;
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';

    try {
        // Convert attachments to base64
        const attachmentData = await prepareAttachments();

        // Send the email
        const response = await fetch(API_BASE + 'send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to: to,
                cc: cc,
                subject: subject,
                body: body,
                ticket_id: currentEmail ? currentEmail.ticket_id : null,
                type: composeMode,
                attachments: attachmentData
            })
        });

        // Get raw response text first to handle non-JSON errors
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Raw response:', responseText);
            showToast('Server error: ' + responseText.substring(0, 200), true);
            return;
        }

        if (data.success) {
            showToast('Email sent successfully!');
            closeEmailModal();
            // Refresh the current view to show the sent email
            if (currentEmail) {
                selectEmail(selectedEmailId);
            }
        } else {
            showToast('Failed to send email: ' + data.error, true);
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showToast('Error sending email: ' + error.message, true);
    } finally {
        // Restore button state
        sendBtn.disabled = false;
        sendBtn.textContent = originalText;
    }
}

// Prepare attachments by converting to base64
async function prepareAttachments() {
    const attachments = [];

    for (const file of emailAttachments) {
        const base64 = await fileToBase64(file);
        attachments.push({
            name: file.name,
            type: file.type || 'application/octet-stream',
            content: base64
        });
    }

    return attachments;
}

// Convert file to base64
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            // Remove the data URL prefix (e.g., "data:application/pdf;base64,")
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// Logout
function logout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'analyst_logout.php';
    }
}

// New Ticket Modal Functions
function openNewTicketModal() {
    // Clear form
    document.getElementById('newTicketFromName').value = '';
    document.getElementById('newTicketFromEmail').value = '';
    document.getElementById('newTicketSubject').value = '';
    document.getElementById('newTicketBody').value = '';
    document.getElementById('newTicketPriority').value = 'Normal';

    // Populate department dropdown
    const deptSelect = document.getElementById('newTicketDepartment');
    deptSelect.innerHTML = '<option value="">-- Select --</option>' +
        departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

    // Populate ticket type dropdown
    const typeSelect = document.getElementById('newTicketType');
    typeSelect.innerHTML = '<option value="">-- Select --</option>' +
        ticketTypes.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

    document.getElementById('newTicketModal').classList.add('active');
}

function closeNewTicketModal() {
    document.getElementById('newTicketModal').classList.remove('active');
}

async function createNewTicket() {
    const fromName = document.getElementById('newTicketFromName').value.trim();
    const fromEmail = document.getElementById('newTicketFromEmail').value.trim();
    const subject = document.getElementById('newTicketSubject').value.trim();
    const body = document.getElementById('newTicketBody').value.trim();
    const departmentId = document.getElementById('newTicketDepartment').value;
    const ticketTypeId = document.getElementById('newTicketType').value;
    const priority = document.getElementById('newTicketPriority').value;

    // Validate required fields
    if (!fromName) {
        alert('Please enter the requester name');
        return;
    }
    if (!fromEmail) {
        alert('Please enter the requester email');
        return;
    }
    if (!subject) {
        alert('Please enter a subject');
        return;
    }

    // Get the create button and show loading state
    const createBtn = document.querySelector('#newTicketModal .btn-primary');
    const originalText = createBtn.textContent;
    createBtn.disabled = true;
    createBtn.textContent = 'Creating...';

    try {
        const response = await fetch(API_BASE + 'create_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                from_name: fromName,
                from_email: fromEmail,
                subject: subject,
                body: body,
                department_id: departmentId || null,
                ticket_type_id: ticketTypeId || null,
                priority: priority
            })
        });

        const data = await response.json();

        if (data.success) {
            closeNewTicketModal();
            // Refresh the view
            loadFolderCounts();
            loadEmails();
            alert('Ticket created successfully: ' + data.ticket_number);
        } else {
            alert('Error creating ticket: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to create ticket');
    } finally {
        createBtn.disabled = false;
        createBtn.textContent = originalText;
    }
}

// ============================================
// Search Modal Functions
// ============================================

let searchModalDragging = false;
let searchModalOffsetX = 0;
let searchModalOffsetY = 0;

function openSearchModal() {
    const modal = document.getElementById('searchModal');
    modal.classList.add('active');

    // Position modal so right edge aligns with refresh button's right edge
    const refreshBtn = document.querySelector('.refresh-btn');
    if (refreshBtn) {
        const btnRect = refreshBtn.getBoundingClientRect();
        const modalWidth = 500; // matches CSS width
        const rightEdge = btnRect.right;
        const leftPos = rightEdge - modalWidth;

        modal.style.left = Math.max(10, leftPos) + 'px';
        modal.style.top = (btnRect.bottom + 10) + 'px';
        modal.style.transform = 'none';
    } else {
        // Fallback to center
        modal.style.left = '50%';
        modal.style.top = '100px';
        modal.style.transform = 'translateX(-50%)';
    }

    // Initialize dragging
    initSearchModalDrag();

    // Focus the first input
    document.getElementById('searchTicketNumber').focus();
}

function closeSearchModal() {
    document.getElementById('searchModal').classList.remove('active');
    // Don't clear search - user can reopen to see previous results
    // Use the Clear button to reset if needed
}

function initSearchModalDrag() {
    const modal = document.getElementById('searchModal');
    const header = document.getElementById('searchModalHeader');

    header.onmousedown = function(e) {
        if (e.target.classList.contains('search-modal-close')) return;

        searchModalDragging = true;

        // Remove the transform so we can use left/top directly
        const rect = modal.getBoundingClientRect();
        modal.style.transform = 'none';
        modal.style.left = rect.left + 'px';
        modal.style.top = rect.top + 'px';

        searchModalOffsetX = e.clientX - rect.left;
        searchModalOffsetY = e.clientY - rect.top;

        document.onmousemove = function(e) {
            if (!searchModalDragging) return;

            let newX = e.clientX - searchModalOffsetX;
            let newY = e.clientY - searchModalOffsetY;

            // Keep within viewport bounds
            newX = Math.max(0, Math.min(newX, window.innerWidth - modal.offsetWidth));
            newY = Math.max(0, Math.min(newY, window.innerHeight - modal.offsetHeight));

            modal.style.left = newX + 'px';
            modal.style.top = newY + 'px';
        };

        document.onmouseup = function() {
            searchModalDragging = false;
            document.onmousemove = null;
            document.onmouseup = null;
        };
    };
}

async function performSearch() {
    const ticketNumber = document.getElementById('searchTicketNumber').value.trim();
    const email = document.getElementById('searchEmail').value.trim();
    const subject = document.getElementById('searchSubject').value.trim();

    // Validate at least one field
    if (!ticketNumber && !email && !subject) {
        alert('Please enter at least one search criterion');
        return;
    }

    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = '<div class="search-loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(API_BASE + 'search_tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_number: ticketNumber,
                email: email,
                subject: subject
            })
        });

        const data = await response.json();

        if (data.success) {
            renderSearchResults(data.results);
        } else {
            resultsContainer.innerHTML = `<div class="search-results-empty">Error: ${data.error}</div>`;
        }
    } catch (error) {
        console.error('Search error:', error);
        resultsContainer.innerHTML = '<div class="search-results-empty">Search failed. Please try again.</div>';
    }
}

function renderSearchResults(results) {
    const container = document.getElementById('searchResults');

    if (!results || results.length === 0) {
        container.innerHTML = '<div class="search-results-empty">No tickets found matching your criteria</div>';
        return;
    }

    let html = `<div class="search-results-count">${results.length} ticket${results.length === 1 ? '' : 's'} found</div>`;

    results.forEach(ticket => {
        html += `
            <div class="search-result-item" onclick="selectSearchResult(${ticket.email_id})">
                <div class="search-result-ticket">${escapeHtml(ticket.ticket_number)}</div>
                <div class="search-result-subject">${escapeHtml(ticket.subject)}</div>
                <div class="search-result-meta">
                    <span>${escapeHtml(ticket.from_name || ticket.from_address)}</span>
                    <span>${ticket.status}</span>
                    <span>${formatDateTime(ticket.received_datetime)}</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function selectSearchResult(emailId) {
    // Keep the modal open so user can try another result if needed
    // Select the email in the reading pane
    selectEmail(emailId);
}

function clearSearch() {
    document.getElementById('searchTicketNumber').value = '';
    document.getElementById('searchEmail').value = '';
    document.getElementById('searchSubject').value = '';
    document.getElementById('searchResults').innerHTML = '<div class="search-results-empty">Enter search criteria above</div>';
}

// Allow Enter key to trigger search
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = ['searchTicketNumber', 'searchEmail', 'searchSubject'];
    searchInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        }
    });
});

// ============================================
// Schedule Modal Functions
// ============================================

function openScheduleModal() {
    if (!currentEmail || !currentEmail.ticket_id) {
        alert('No ticket selected');
        return;
    }

    // Set ticket info
    document.getElementById('scheduleTicketInfo').textContent =
        `${currentEmail.ticket_number} - ${currentEmail.subject}`;

    // Set default date to today and time to next hour
    const now = new Date();
    const dateStr = now.toISOString().split('T')[0];
    document.getElementById('scheduleDate').value = dateStr;

    // Round to next hour
    now.setHours(now.getHours() + 1, 0, 0, 0);
    const timeStr = now.toTimeString().slice(0, 5);
    document.getElementById('scheduleTime').value = timeStr;

    // Check if already scheduled
    if (currentEmail.work_start_datetime) {
        const scheduled = new Date(currentEmail.work_start_datetime);
        document.getElementById('currentSchedule').textContent = formatFullDateTime(currentEmail.work_start_datetime);
        document.getElementById('scheduleCurrent').style.display = 'block';

        // Pre-fill with existing schedule
        document.getElementById('scheduleDate').value = scheduled.toISOString().split('T')[0];
        document.getElementById('scheduleTime').value = scheduled.toTimeString().slice(0, 5);
    } else {
        document.getElementById('scheduleCurrent').style.display = 'none';
    }

    document.getElementById('scheduleModal').classList.add('active');
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

async function saveSchedule() {
    const date = document.getElementById('scheduleDate').value;
    const time = document.getElementById('scheduleTime').value;

    if (!date || !time) {
        alert('Please select both date and time');
        return;
    }

    const workStart = `${date} ${time}:00`;

    try {
        const response = await fetch(API_BASE + 'schedule_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                work_start_datetime: workStart
            })
        });

        const data = await response.json();

        if (data.success) {
            currentEmail.work_start_datetime = workStart;
            closeScheduleModal();
            alert('Work scheduled successfully');
        } else {
            alert('Error scheduling: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to schedule work');
    }
}

async function clearSchedule() {
    if (!confirm('Are you sure you want to clear the scheduled work time?')) {
        return;
    }

    try {
        const response = await fetch(API_BASE + 'schedule_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                work_start_datetime: null
            })
        });

        const data = await response.json();

        if (data.success) {
            currentEmail.work_start_datetime = null;
            closeScheduleModal();
            alert('Schedule cleared');
        } else {
            alert('Error clearing schedule: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to clear schedule');
    }
}

// ===== AI Chat Functions (Ask AI) =====

let _ticketAiContextId = null; // Track which ticket has been auto-queried

function openTicketAiChat() {
    if (!currentEmail) return;

    const panel = document.getElementById('ticketAiPanel');
    const overlay = document.getElementById('ticketAiOverlay');
    panel.classList.add('active');
    overlay.classList.add('active');

    // If ticket changed, reset chat
    if (_ticketAiContextId !== currentEmail.ticket_id) {
        _ticketAiContextId = currentEmail.ticket_id;
        const messagesContainer = document.getElementById('ticketAiMessages');
        messagesContainer.innerHTML = '<div class="ai-chat-welcome">Ask a question about this ticket and the AI will search the knowledge base for relevant articles.</div>';

        // Auto-send initial context question
        const subject = currentEmail.subject || '';
        const bodyText = (currentEmail.body_content || '').replace(/<[^>]*>/g, '').substring(0, 1500);
        const autoQuestion = `I'm looking at ticket ${currentEmail.ticket_number || ''}: ${subject}.\n\nHere's the initial email:\n\n${bodyText}\n\nAre there any knowledge articles that might help resolve this?`;
        _sendTicketAiMessage(autoQuestion, true);
    }

    document.getElementById('ticketAiInput').focus();
}

function closeTicketAiChat() {
    document.getElementById('ticketAiPanel').classList.remove('active');
    document.getElementById('ticketAiOverlay').classList.remove('active');
}

function askTicketAi() {
    const input = document.getElementById('ticketAiInput');
    const question = input.value.trim();
    if (!question) return;
    input.value = '';
    _sendTicketAiMessage(question, false);
}

async function _sendTicketAiMessage(question, isAutoContext) {
    const messagesContainer = document.getElementById('ticketAiMessages');
    const input = document.getElementById('ticketAiInput');
    const sendBtn = document.getElementById('ticketAiSendBtn');

    // Clear welcome message
    const welcome = messagesContainer.querySelector('.ai-chat-welcome');
    if (welcome) welcome.remove();

    // Add user message bubble (show a shorter version for auto-context)
    const userMsg = document.createElement('div');
    userMsg.className = 'ai-chat-message user';
    const displayText = isAutoContext
        ? `Find knowledge articles relevant to: ${currentEmail.subject || 'this ticket'}`
        : question;
    userMsg.innerHTML = '<div class="ai-chat-bubble">' + escapeHtml(displayText) + '</div>';
    messagesContainer.appendChild(userMsg);

    // Disable input
    input.disabled = true;
    sendBtn.disabled = true;

    // Add thinking indicator
    const thinking = document.createElement('div');
    thinking.className = 'ai-chat-thinking';
    thinking.innerHTML = '<div class="dots"><span></span><span></span><span></span></div> Searching knowledge base...';
    messagesContainer.appendChild(thinking);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;

    try {
        const response = await fetch('../api/knowledge/ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question, include_archived: false })
        });
        const data = await response.json();

        thinking.remove();

        if (data.success) {
            const assistantMsg = document.createElement('div');
            assistantMsg.className = 'ai-chat-message assistant';
            assistantMsg.innerHTML = '<div class="ai-chat-bubble">' + formatTicketAiResponse(data.answer, data.articles || []) + '</div>' +
                '<div class="ai-chat-meta">Searched ' + data.articles_searched + ' articles</div>';
            messagesContainer.appendChild(assistantMsg);
        } else {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'ai-chat-error';
            errorMsg.textContent = data.error || 'Failed to get a response. Please check the AI API key in Knowledge Settings.';
            messagesContainer.appendChild(errorMsg);
        }
    } catch (error) {
        thinking.remove();
        const errorMsg = document.createElement('div');
        errorMsg.className = 'ai-chat-error';
        errorMsg.textContent = 'Network error: ' + error.message;
        messagesContainer.appendChild(errorMsg);
    }

    input.disabled = false;
    sendBtn.disabled = false;
    input.focus();
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function formatTicketAiResponse(text, articlesList) {
    // Replace quoted article titles with hyperlinks
    if (articlesList && articlesList.length > 0) {
        const sorted = [...articlesList].sort((a, b) => b.title.length - a.title.length);
        sorted.forEach(article => {
            const escapedTitle = article.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp('["\u201c]' + escapedTitle + '(\\s*\\(ID:\\s*\\d+\\))?["\u201d]', 'gi');
            const link = '<a href="../knowledge/?id=' + article.id + '" target="_blank" class="ai-article-link">\u201c' + escapeHtml(article.title) + '\u201d</a>';
            text = text.replace(regex, link);
        });
    }

    // Markdown-like formatting
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    text = text.replace(/(?<!\w)_([^_]+)_(?!\w)/g, '<em>$1</em>');
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Paragraphs and lists
    const paragraphs = text.split(/\n\n+/);
    if (paragraphs.length > 1) {
        text = paragraphs.map(p => {
            p = p.trim();
            if (!p) return '';
            if (/^[-*]\s/.test(p) || /^\d+\.\s/.test(p)) {
                const items = p.split(/\n/).map(line => {
                    line = line.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, '');
                    return '<li>' + line + '</li>';
                }).join('');
                return '<ul>' + items + '</ul>';
            }
            return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    } else {
        if (/^[-*]\s/m.test(text) || /^\d+\.\s/m.test(text)) {
            const lines = text.split(/\n/);
            let html = '';
            let inList = false;
            lines.forEach(line => {
                const isListItem = /^[-*]\s/.test(line) || /^\d+\.\s/.test(line);
                if (isListItem) {
                    if (!inList) { html += '<ul>'; inList = true; }
                    line = line.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, '');
                    html += '<li>' + line + '</li>';
                } else {
                    if (inList) { html += '</ul>'; inList = false; }
                    html += (line.trim() ? '<p>' + line + '</p>' : '');
                }
            });
            if (inList) html += '</ul>';
            text = html;
        } else {
            text = '<p>' + text.replace(/\n/g, '<br>') + '</p>';
        }
    }

    return text;
}

// Close AI chat on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const panel = document.getElementById('ticketAiPanel');
        if (panel && panel.classList.contains('active')) {
            closeTicketAiChat();
        }
    }
});
