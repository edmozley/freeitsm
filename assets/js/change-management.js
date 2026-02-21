/**
 * Change Management Module - Client-side logic
 */

const API_BASE = window.API_BASE || '../api/change-management/';

let changes = [];
let analysts = [];
let currentChange = null;
let currentFilter = 'all';
let searchQuery = '';
let fieldVisibility = {};
let cabEditorMembers = []; // [{analyst_id, name, is_required}]

// TinyMCE editor instances
const editorIds = ['editorDescription', 'editorReason', 'editorRisk', 'editorTestplan', 'editorRollback', 'editorPir'];
let editorsReady = false;

// ============ Initialization ============

document.addEventListener('DOMContentLoaded', function() {
    loadFieldVisibility();
    loadAnalysts();
    loadChanges();
    setupFileUpload();

    // Handle ?open=ID from calendar or direct link
    const urlParams = new URLSearchParams(window.location.search);
    const openId = urlParams.get('open');
    if (openId) {
        viewChange(parseInt(openId, 10));
    }

    // Enter key triggers search in search modal
    document.querySelectorAll('#searchChangeNumber, #searchChangeTitle').forEach(input => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') performSearch();
        });
    });

    // Status change toggles PIR fields visibility
    const statusSelect = document.getElementById('changeStatus');
    if (statusSelect) {
        statusSelect.addEventListener('change', updatePirVisibility);
    }
});

// ============ Field Visibility ============

// Section-to-fields mapping for hiding empty sections
const SECTION_FIELDS = {
    general:     ['title', 'change_type', 'status', 'priority', 'impact', 'category'],
    people:      ['requester', 'assigned_to', 'approver'],
    schedule:    ['work_start', 'work_end', 'outage_start', 'outage_end'],
    details:     ['description', 'reason', 'risk', 'testplan', 'rollback', 'pir'],
    attachments: ['attachments']
};

// Detail view field-to-data mapping
const DETAIL_FIELD_MAP = {
    impact: 'impact', category: 'category',
    requester: 'requester_name', assigned_to: 'assigned_to_name', approver: 'approver_name',
    work_start: 'work_start_datetime', work_end: 'work_end_datetime',
    outage_start: 'outage_start_datetime', outage_end: 'outage_end_datetime'
};

async function loadFieldVisibility() {
    try {
        const res = await fetch(API_BASE + 'get_settings.php');
        const data = await res.json();
        if (data.success && data.settings && data.settings.field_visibility) {
            fieldVisibility = data.settings.field_visibility;
        }
    } catch (e) {
        console.error('Error loading field visibility:', e);
    }
}

function isFieldVisible(fieldId) {
    return fieldVisibility[fieldId] !== false;
}

function applyFieldVisibility() {
    const editor = document.getElementById('changeEditorView');
    if (!editor) return;

    // Show/hide individual fields
    editor.querySelectorAll('[data-field]').forEach(el => {
        const fieldId = el.dataset.field;
        // Skip rich-text tab buttons (handled separately)
        if (el.tagName === 'BUTTON') return;
        el.style.display = isFieldVisible(fieldId) ? '' : 'none';
    });

    // Show/hide rich-text tab buttons
    const tabBar = document.getElementById('richTextTabs');
    if (tabBar) {
        let firstVisibleTab = null;
        tabBar.querySelectorAll('.rich-text-tab[data-field]').forEach(btn => {
            const vis = isFieldVisible(btn.dataset.field);
            btn.style.display = vis ? '' : 'none';
            if (vis && !firstVisibleTab) firstVisibleTab = btn.dataset.field;
        });
        // Activate first visible tab if current active is hidden
        const activeTab = tabBar.querySelector('.rich-text-tab.active');
        if (activeTab && activeTab.style.display === 'none' && firstVisibleTab) {
            switchTab(firstVisibleTab);
        }
    }

    // Show/hide form-rows: hide if ALL child .form-group elements are hidden
    editor.querySelectorAll('.form-row[data-section]').forEach(row => {
        const groups = row.querySelectorAll('.form-group[data-field]');
        const allHidden = Array.from(groups).every(g => g.style.display === 'none');
        row.style.display = allHidden ? 'none' : '';
    });

    // Show/hide section headings: hide if ALL fields in section are hidden
    editor.querySelectorAll('.form-section-title[data-section]').forEach(heading => {
        const section = heading.dataset.section;
        const fields = SECTION_FIELDS[section] || [];
        const allHidden = fields.every(f => !isFieldVisible(f));
        heading.style.display = allHidden ? 'none' : '';
    });

    // Hide details tab bar if all detail fields hidden
    if (tabBar) {
        const detailFields = SECTION_FIELDS.details || [];
        const allHidden = detailFields.every(f => !isFieldVisible(f));
        tabBar.style.display = allHidden ? 'none' : '';
    }
}

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
    const dropdowns = ['changeRequester', 'changeAssignedTo', 'changeApprover', 'cabMemberSelect'];
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
                    ${c.risk_level ? `<span class="risk-badge risk-${c.risk_level.toLowerCase().replace(/\s+/g, '-')}">${c.risk_level}</span>` : ''}
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
    const v = isFieldVisible;

    // Build badges
    let badgesHtml = '';
    if (v('status')) badgesHtml += `<span class="status-badge ${statusClass}">${c.status}</span>`;
    if (v('change_type')) badgesHtml += `<span class="type-badge ${typeClass}">${c.change_type}</span>`;
    if (v('priority')) badgesHtml += `<span class="priority-badge ${priorityClass}">${c.priority}</span>`;

    // Build meta grid — only include visible fields
    let metaItems = '';
    if (v('impact')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Impact</span><span class="detail-meta-value">${c.impact}</span></div>`;
    if (v('category') && c.category) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Category</span><span class="detail-meta-value">${escapeHtml(c.category)}</span></div>`;
    if (v('requester')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Requester</span><span class="detail-meta-value">${c.requester_name || 'Not set'}</span></div>`;
    if (v('assigned_to')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Assigned To</span><span class="detail-meta-value">${c.assigned_to_name || 'Not set'}</span></div>`;
    if (v('approver')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Approver</span><span class="detail-meta-value">${c.approver_name || 'Not set'}</span></div>`;
    if (v('approver') && c.approval_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Approved</span><span class="detail-meta-value">${formatDateTime(c.approval_datetime)}</span></div>`;
    if (v('work_start') && c.work_start_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Work Start</span><span class="detail-meta-value">${formatDateTime(c.work_start_datetime)}</span></div>`;
    if (v('work_end') && c.work_end_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Work End</span><span class="detail-meta-value">${formatDateTime(c.work_end_datetime)}</span></div>`;
    if (v('outage_start') && c.outage_start_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Outage Start</span><span class="detail-meta-value">${formatDateTime(c.outage_start_datetime)}</span></div>`;
    if (v('outage_end') && c.outage_end_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Outage End</span><span class="detail-meta-value">${formatDateTime(c.outage_end_datetime)}</span></div>`;
    if (v('risk') && c.risk_level) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Risk Level</span><span class="detail-meta-value"><span class="risk-badge risk-${c.risk_level.toLowerCase().replace(/\s+/g, '-')}">${c.risk_level} (${c.risk_score})</span></span></div>`;
    metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Created</span><span class="detail-meta-value">${formatDateTime(c.created_datetime)}${c.created_by_name ? ' by ' + c.created_by_name : ''}</span></div>`;
    metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">Last Modified</span><span class="detail-meta-value">${formatDateTime(c.modified_datetime)}</span></div>`;

    // Build sticky header with buttons, title, badges, and meta grid
    let html = `
        <div class="change-detail-sticky-header">
            <div class="change-detail-header">
                <button class="btn btn-secondary" onclick="backToList()">Back</button>
                <div class="change-detail-actions">
                    <div class="share-dropdown">
                        <button class="btn btn-share" onclick="toggleShareDropdown()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                            Share
                        </button>
                        <div class="share-dropdown-menu" id="shareDropdownMenu">
                            <a href="#" onclick="shareChangeLink(); return false;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                </svg>
                                Copy Link
                            </a>
                            <a href="#" onclick="shareChangePdf(); return false;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                Export as PDF
                            </a>
                            <a href="#" onclick="shareChangeBoth(); return false;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                Email (Link + PDF)
                            </a>
                        </div>
                    </div>
                    <button class="btn btn-primary" onclick="editCurrentChange()">Edit</button>
                    <button class="btn btn-danger" onclick="deleteCurrentChange()">Delete</button>
                </div>
            </div>
            <div class="sticky-header-top">
                <div class="change-detail-ref">${ref}</div>
                ${badgesHtml ? `<div class="sticky-header-badges">${badgesHtml}</div>` : ''}
            </div>
            ${v('title') ? `<div class="change-detail-title">${escapeHtml(c.title)}</div>` : ''}
            ${metaItems ? `<div class="detail-meta-grid">${metaItems}</div>` : ''}
        </div>
    `;

    // CAB Review panel
    html += renderCabReviewPanel(c);

    // Detail sections — only include visible fields
    let sections = '';
    if (v('description')) sections += renderDetailSection('Description', c.description);
    if (v('reason')) sections += renderDetailSection('Reason for Change', c.reason_for_change);
    if (v('risk')) sections += renderDetailSection('Risk Evaluation', c.risk_evaluation);
    if (v('testplan')) sections += renderDetailSection('Test Plan', c.test_plan);
    if (v('rollback')) sections += renderDetailSection('Rollback Plan', c.rollback_plan);
    if (v('pir')) sections += renderDetailSection('Post-Implementation Review', c.post_implementation_review);

    if (sections) html += `<div class="detail-sections">${sections}</div>`;

    // Risk Matrix (shown when risk scores exist)
    if (v('risk') && c.risk_likelihood && c.risk_impact_score) {
        html += renderRiskMatrix(c.risk_likelihood, c.risk_impact_score, c.risk_score, c.risk_level);
    }

    // PIR Structured Data (for Completed/Failed changes)
    if (v('pir') && (c.status === 'Completed' || c.status === 'Failed') && (c.pir_was_successful !== null || c.pir_actual_start || c.pir_lessons_learned)) {
        html += renderPirDetail(c);
    }

    // Attachments
    if (v('attachments') && c.attachments && c.attachments.length) {
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

    // Activity section (comments + audit trail)
    html += `
        <div class="activity-section">
            <h3>Activity</h3>
            <div class="comment-input-area">
                <textarea class="form-input" id="commentInput" rows="2" placeholder="Add a comment..."></textarea>
                <button class="btn btn-primary btn-sm" onclick="postComment()">Post</button>
            </div>
            <div class="activity-timeline" id="activityTimeline">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    `;

    document.getElementById('changeDetailContent').innerHTML = html;

    // Load activity timeline asynchronously
    loadActivityTimeline(c.id);
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

// ============ Risk Matrix ============

function renderRiskMatrix(likelihood, impact, score, level) {
    const riskClass = level ? level.toLowerCase().replace(/\s+/g, '-') : '';

    // 5x5 grid: rows = likelihood (5 top to 1 bottom), cols = impact (1 left to 5 right)
    const cellColors = [
        // [likelihood][impact] => color class
        // Row 1 (likelihood=1): impacts 1-5
        ['low','low','low','medium','medium'],
        // Row 2 (likelihood=2): impacts 1-5
        ['low','low','medium','medium','high'],
        // Row 3 (likelihood=3): impacts 1-5
        ['low','medium','medium','high','high'],
        // Row 4 (likelihood=4): impacts 1-5
        ['medium','medium','high','very-high','very-high'],
        // Row 5 (likelihood=5): impacts 1-5
        ['medium','high','high','very-high','critical'],
    ];

    let gridHtml = '';
    // Render rows from top (likelihood=5) to bottom (likelihood=1)
    for (let l = 5; l >= 1; l--) {
        gridHtml += `<div class="risk-matrix-label-y">${l}</div>`;
        for (let i = 1; i <= 5; i++) {
            const cellClass = cellColors[l-1][i-1];
            const isActive = (l === parseInt(likelihood) && i === parseInt(impact));
            gridHtml += `<div class="risk-matrix-cell ${cellClass}${isActive ? ' active' : ''}">${l * i}</div>`;
        }
    }

    return `
        <div class="risk-matrix-section">
            <h3>Risk Assessment</h3>
            <div class="risk-matrix-wrapper">
                <div class="risk-matrix-info">
                    <div class="risk-score-badge ${riskClass}">
                        <span class="risk-score-value">${score}</span>
                        <span class="risk-score-label">${level}</span>
                    </div>
                    <div class="risk-matrix-details">
                        <div><strong>Likelihood:</strong> ${likelihood} / 5</div>
                        <div><strong>Impact:</strong> ${impact} / 5</div>
                    </div>
                </div>
                <div class="risk-matrix-grid-container">
                    <div class="risk-matrix-y-label">Likelihood</div>
                    <div class="risk-matrix-grid">
                        ${gridHtml}
                        <div class="risk-matrix-spacer"></div>
                        <div class="risk-matrix-label-x">1</div>
                        <div class="risk-matrix-label-x">2</div>
                        <div class="risk-matrix-label-x">3</div>
                        <div class="risk-matrix-label-x">4</div>
                        <div class="risk-matrix-label-x">5</div>
                    </div>
                    <div class="risk-matrix-x-label">Impact</div>
                </div>
            </div>
        </div>
    `;
}

// ============ PIR Detail ============

function renderPirDetail(c) {
    let html = '<div class="pir-detail-section"><h3>Post-Implementation Review</h3><div class="pir-detail-grid">';

    if (c.pir_was_successful !== null && c.pir_was_successful !== '') {
        const successText = parseInt(c.pir_was_successful) === 1 ? 'Yes' : 'No';
        const successClass = parseInt(c.pir_was_successful) === 1 ? 'pir-success' : 'pir-fail';
        html += `<div class="detail-meta-item"><span class="detail-meta-label">Successful</span><span class="detail-meta-value ${successClass}">${successText}</span></div>`;
    }
    if (c.pir_actual_start) {
        html += `<div class="detail-meta-item"><span class="detail-meta-label">Actual Start</span><span class="detail-meta-value">${formatDateTime(c.pir_actual_start)}</span></div>`;
    }
    if (c.pir_actual_end) {
        html += `<div class="detail-meta-item"><span class="detail-meta-label">Actual End</span><span class="detail-meta-value">${formatDateTime(c.pir_actual_end)}</span></div>`;
    }
    html += '</div>';

    if (c.pir_lessons_learned) {
        html += `<div class="pir-text-block"><strong>Lessons Learned</strong><p>${escapeHtml(c.pir_lessons_learned)}</p></div>`;
    }
    if (c.pir_follow_up) {
        html += `<div class="pir-text-block"><strong>Follow-up Actions</strong><p>${escapeHtml(c.pir_follow_up)}</p></div>`;
    }

    html += '</div>';
    return html;
}

// ============ Activity Timeline ============

async function loadActivityTimeline(changeId) {
    const container = document.getElementById('activityTimeline');
    if (!container) return;

    try {
        // Fetch audit trail and comments in parallel
        const [auditRes, commentsRes] = await Promise.all([
            fetch(API_BASE + 'get_audit.php?change_id=' + changeId),
            fetch(API_BASE + 'get_comments.php?change_id=' + changeId)
        ]);

        const auditData = await auditRes.json();
        const commentsData = await commentsRes.json();

        const auditEntries = (auditData.success ? auditData.entries : []).map(e => ({
            type: 'audit',
            datetime: e.created_datetime,
            analyst: e.analyst_name || 'System',
            action_type: e.action_type,
            field_name: e.field_name,
            old_value: e.old_value,
            new_value: e.new_value
        }));

        const commentEntries = (commentsData.success ? commentsData.comments : []).map(e => ({
            type: 'comment',
            id: e.id,
            datetime: e.created_datetime,
            analyst: e.analyst_name || 'Unknown',
            text: e.comment_text
        }));

        // Merge and sort by datetime descending
        const timeline = [...auditEntries, ...commentEntries]
            .sort((a, b) => new Date(b.datetime) - new Date(a.datetime));

        renderActivityTimeline(timeline, container);
    } catch (error) {
        console.error('Error loading activity:', error);
        container.innerHTML = '<div class="timeline-empty">Error loading activity</div>';
    }
}

function renderActivityTimeline(timeline, container) {
    if (!timeline.length) {
        container.innerHTML = '<div class="timeline-empty">No activity yet</div>';
        return;
    }

    container.innerHTML = timeline.map(entry => {
        if (entry.type === 'comment') {
            return `
                <div class="timeline-item timeline-comment">
                    <div class="timeline-icon comment-icon">&#128172;</div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <strong>${escapeHtml(entry.analyst)}</strong> commented
                            <span class="timeline-date">${formatDateTime(entry.datetime)}</span>
                        </div>
                        <div class="timeline-body">${escapeHtml(entry.text)}</div>
                    </div>
                </div>
            `;
        } else {
            // Audit entry
            let description = '';
            if (entry.action_type === 'status_change') {
                description = `changed <strong>Status</strong> from <span class="old-val">${escapeHtml(entry.old_value || '')}</span> to <span class="new-val">${escapeHtml(entry.new_value || '')}</span>`;
            } else if (entry.action_type === 'comment') {
                return ''; // Already shown as comment entry
            } else if (entry.action_type === 'cab_vote') {
                description = `<strong>${escapeHtml(entry.field_name || 'CAB')}</strong>`;
                if (entry.new_value) description += `: <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                if (entry.old_value) description += ` <span class="old-val">(${escapeHtml(entry.old_value)})</span>`;
            } else {
                description = `changed <strong>${escapeHtml(entry.field_name || '')}</strong>`;
                if (entry.old_value && entry.new_value) {
                    description += ` from <span class="old-val">${escapeHtml(entry.old_value)}</span> to <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                } else if (entry.new_value) {
                    description += ` to <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                } else if (entry.old_value) {
                    description += ` (was <span class="old-val">${escapeHtml(entry.old_value)}</span>)`;
                }
            }

            const icon = entry.action_type === 'status_change' ? '&#9654;' : entry.action_type === 'cab_vote' ? '&#9734;' : '&#9998;';
            const itemClass = entry.action_type === 'status_change' ? 'timeline-status' : entry.action_type === 'cab_vote' ? 'timeline-cab' : 'timeline-field';

            return `
                <div class="timeline-item ${itemClass}">
                    <div class="timeline-icon">${icon}</div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <strong>${escapeHtml(entry.analyst)}</strong> ${description}
                            <span class="timeline-date">${formatDateTime(entry.datetime)}</span>
                        </div>
                    </div>
                </div>
            `;
        }
    }).join('');
}

async function postComment() {
    if (!currentChange) return;

    const input = document.getElementById('commentInput');
    const text = input.value.trim();
    if (!text) {
        showToast('Please enter a comment');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: currentChange.id, comment_text: text })
        });
        const data = await response.json();

        if (data.success) {
            input.value = '';
            showToast('Comment added');
            loadActivityTimeline(currentChange.id);
        } else {
            showToast('Error: ' + data.error);
        }
    } catch (error) {
        showToast('Error posting comment');
    }
}

// ============ Filtering & Search ============

function filterByStatus(status) {
    currentFilter = status;
    document.querySelectorAll('.status-filter').forEach(el => {
        el.classList.toggle('active', el.dataset.status === status);
    });
    loadChanges();
}

// ============ Search Modal ============

let searchModalOffsetX = 0;
let searchModalOffsetY = 0;

function openSearchModal() {
    const modal = document.getElementById('searchModal');
    modal.classList.add('active');

    // Position near the search button
    const searchBtn = document.querySelector('.search-btn');
    if (searchBtn) {
        const btnRect = searchBtn.getBoundingClientRect();
        modal.style.left = btnRect.left + 'px';
        modal.style.top = (btnRect.bottom + 10) + 'px';
        modal.style.transform = 'none';
    } else {
        modal.style.left = '50%';
        modal.style.top = '100px';
        modal.style.transform = 'translateX(-50%)';
    }

    initSearchModalDrag();
    document.getElementById('searchChangeNumber').focus();
}

function closeSearchModal() {
    document.getElementById('searchModal').classList.remove('active');
}

function initSearchModalDrag() {
    const header = document.getElementById('searchModalHeader');
    const modal = document.getElementById('searchModal');

    header.onmousedown = function(e) {
        if (e.target.tagName === 'BUTTON') return;
        e.preventDefault();
        const rect = modal.getBoundingClientRect();
        searchModalOffsetX = e.clientX - rect.left;
        searchModalOffsetY = e.clientY - rect.top;

        function onMouseMove(e) {
            modal.style.left = (e.clientX - searchModalOffsetX) + 'px';
            modal.style.top = (e.clientY - searchModalOffsetY) + 'px';
            modal.style.transform = 'none';
        }
        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    };
}

async function performSearch() {
    const changeNumber = document.getElementById('searchChangeNumber').value.trim();
    const title = document.getElementById('searchChangeTitle').value.trim();

    if (!changeNumber && !title) {
        alert('Please enter at least one search criterion');
        return;
    }

    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    // Build search query — extract numeric ID from CHG-0001 format
    let searchId = '';
    if (changeNumber) {
        searchId = changeNumber.replace(/^CHG-0*/i, '').replace(/^0+/, '') || changeNumber;
    }

    try {
        let url = API_BASE + 'list.php?';
        if (searchId || title) {
            url += 'search=' + encodeURIComponent(title || searchId);
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            let results = data.changes || [];

            // If searching by change number, filter more precisely
            if (searchId && !title) {
                results = results.filter(c => String(c.id) === searchId || String(c.id).includes(searchId));
            }

            renderSearchResults(results);
        } else {
            resultsContainer.innerHTML = '<div class="search-results-empty">Error: ' + (data.error || 'Unknown') + '</div>';
        }
    } catch (e) {
        console.error(e);
        resultsContainer.innerHTML = '<div class="search-results-empty">Search failed</div>';
    }
}

function renderSearchResults(results) {
    const container = document.getElementById('searchResults');

    if (!results || results.length === 0) {
        container.innerHTML = '<div class="search-results-empty">No changes found</div>';
        return;
    }

    let html = '<div class="search-results-count">' + results.length + ' change' + (results.length === 1 ? '' : 's') + ' found</div>';

    results.forEach(c => {
        const ref = 'CHG-' + String(c.id).padStart(4, '0');
        html += `
            <div class="search-result-item" onclick="selectSearchResult(${c.id})">
                <div class="search-result-ticket">${ref}</div>
                <div class="search-result-subject">${escapeHtml(c.title)}</div>
                <div class="search-result-meta">
                    <span>${escapeHtml(c.status)}</span>
                    <span>${escapeHtml(c.change_type)}</span>
                    ${c.assigned_to_name ? '<span>' + escapeHtml(c.assigned_to_name) + '</span>' : ''}
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function selectSearchResult(changeId) {
    closeSearchModal();
    viewChange(changeId);
}

function clearSearch() {
    document.getElementById('searchChangeNumber').value = '';
    document.getElementById('searchChangeTitle').value = '';
    document.getElementById('searchResults').innerHTML = '<div class="search-results-empty">Enter search criteria above</div>';
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

    // Risk scoring
    document.getElementById('riskLikelihood').value = '';
    document.getElementById('riskImpactScore').value = '';
    document.getElementById('riskScoreDisplay').textContent = '-';
    document.getElementById('riskScoreDisplay').className = 'risk-score-display';

    // PIR fields
    document.querySelectorAll('input[name="pirWasSuccessful"]').forEach(r => r.checked = false);
    document.getElementById('pirActualStart').value = '';
    document.getElementById('pirActualEnd').value = '';
    document.getElementById('pirLessonsLearned').value = '';
    document.getElementById('pirFollowUp').value = '';
    document.getElementById('pirStructuredFields').style.display = 'none';

    // CAB fields
    document.getElementById('cabRequired').checked = false;
    document.getElementById('cabConfigSection').style.display = 'none';
    document.getElementById('cabApprovalType').value = 'all';
    cabEditorMembers = [];
    renderCabMemberChips();

    initEditors(() => {
        editorIds.forEach(id => {
            const editor = tinymce.get(id);
            if (editor) editor.setContent('');
        });
    });

    showView('editor');
    updatePirVisibility();
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

    // Risk scoring
    document.getElementById('riskLikelihood').value = c.risk_likelihood || '';
    document.getElementById('riskImpactScore').value = c.risk_impact_score || '';
    updateRiskScore();

    // PIR fields
    document.querySelectorAll('input[name="pirWasSuccessful"]').forEach(r => {
        r.checked = (c.pir_was_successful !== null && c.pir_was_successful !== '' && String(r.value) === String(c.pir_was_successful));
    });
    document.getElementById('pirActualStart').value = toDatetimeLocal(c.pir_actual_start);
    document.getElementById('pirActualEnd').value = toDatetimeLocal(c.pir_actual_end);
    document.getElementById('pirLessonsLearned').value = c.pir_lessons_learned || '';
    document.getElementById('pirFollowUp').value = c.pir_follow_up || '';

    // CAB fields
    const cabReq = parseInt(c.cab_required) === 1;
    document.getElementById('cabRequired').checked = cabReq;
    document.getElementById('cabConfigSection').style.display = cabReq ? '' : 'none';
    document.getElementById('cabApprovalType').value = c.cab_approval_type || 'all';
    cabEditorMembers = (c.cab_members || []).map(m => ({
        analyst_id: parseInt(m.analyst_id),
        name: m.analyst_name || 'Unknown',
        is_required: parseInt(m.is_required)
    }));
    renderCabMemberChips();

    initEditors(() => {
        setEditorContent('editorDescription', c.description || '');
        setEditorContent('editorReason', c.reason_for_change || '');
        setEditorContent('editorRisk', c.risk_evaluation || '');
        setEditorContent('editorTestplan', c.test_plan || '');
        setEditorContent('editorRollback', c.rollback_plan || '');
        setEditorContent('editorPir', c.post_implementation_review || '');
    });

    showView('editor');
    updatePirVisibility();
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
        post_implementation_review: getEditorContent('editorPir'),
        risk_likelihood: document.getElementById('riskLikelihood').value || null,
        risk_impact_score: document.getElementById('riskImpactScore').value || null,
        pir_was_successful: (() => { const r = document.querySelector('input[name="pirWasSuccessful"]:checked'); return r ? r.value : null; })(),
        pir_actual_start: document.getElementById('pirActualStart').value || null,
        pir_actual_end: document.getElementById('pirActualEnd').value || null,
        pir_lessons_learned: document.getElementById('pirLessonsLearned').value || null,
        pir_follow_up: document.getElementById('pirFollowUp').value || null,
        cab_required: document.getElementById('cabRequired').checked ? 1 : 0,
        cab_approval_type: document.getElementById('cabApprovalType').value
    };

    try {
        const response = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (data.success) {
            // Save CAB members if CAB is enabled
            if (payload.cab_required) {
                await fetch(API_BASE + 'save_cab_members.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        change_id: data.change_id,
                        members: cabEditorMembers.map(m => ({
                            analyst_id: m.analyst_id,
                            is_required: m.is_required
                        }))
                    })
                });
            }

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

// ============ Risk Scoring & PIR Visibility ============

function updateRiskScore() {
    const likelihood = parseInt(document.getElementById('riskLikelihood').value) || 0;
    const impact = parseInt(document.getElementById('riskImpactScore').value) || 0;
    const display = document.getElementById('riskScoreDisplay');

    if (likelihood && impact) {
        const score = likelihood * impact;
        let level, cssClass;
        if (score <= 4) { level = 'Low'; cssClass = 'risk-low'; }
        else if (score <= 9) { level = 'Medium'; cssClass = 'risk-medium'; }
        else if (score <= 15) { level = 'High'; cssClass = 'risk-high'; }
        else if (score <= 20) { level = 'Very High'; cssClass = 'risk-very-high'; }
        else { level = 'Critical'; cssClass = 'risk-critical'; }
        display.textContent = score + ' - ' + level;
        display.className = 'risk-score-display ' + cssClass;
    } else {
        display.textContent = '-';
        display.className = 'risk-score-display';
    }
}

function updatePirVisibility() {
    const status = document.getElementById('changeStatus').value;
    const pirSection = document.getElementById('pirStructuredFields');
    if (pirSection) {
        pirSection.style.display = (status === 'Completed' || status === 'Failed') ? '' : 'none';
    }
}

// ============ CAB Editor Functions ============

function toggleCabConfig() {
    const checked = document.getElementById('cabRequired').checked;
    document.getElementById('cabConfigSection').style.display = checked ? '' : 'none';
}

function addCabMember() {
    const select = document.getElementById('cabMemberSelect');
    const analystId = parseInt(select.value);
    if (!analystId) return;

    // Check if already added
    if (cabEditorMembers.some(m => m.analyst_id === analystId)) {
        showToast('Already added');
        return;
    }

    const name = select.options[select.selectedIndex].text;
    cabEditorMembers.push({ analyst_id: analystId, name: name, is_required: 1 });
    select.value = '';
    renderCabMemberChips();
}

function removeCabMember(analystId) {
    cabEditorMembers = cabEditorMembers.filter(m => m.analyst_id !== analystId);
    renderCabMemberChips();
}

function toggleCabMemberRequired(analystId) {
    const member = cabEditorMembers.find(m => m.analyst_id === analystId);
    if (member) {
        member.is_required = member.is_required ? 0 : 1;
        renderCabMemberChips();
    }
}

function renderCabMemberChips() {
    const container = document.getElementById('cabMembersList');
    if (!container) return;

    if (!cabEditorMembers.length) {
        container.innerHTML = '<div style="color: #999; font-size: 13px; padding: 8px 0;">No CAB members added</div>';
        return;
    }

    container.innerHTML = cabEditorMembers.map(m => `
        <div class="cab-member-chip">
            <span class="cab-member-name">${escapeHtml(m.name)}</span>
            <button type="button" class="cab-member-toggle ${m.is_required ? 'required' : 'optional'}" onclick="toggleCabMemberRequired(${m.analyst_id})" title="Toggle required/optional">
                ${m.is_required ? 'Required' : 'Optional'}
            </button>
            <button type="button" class="cab-member-remove" onclick="removeCabMember(${m.analyst_id})" title="Remove">&times;</button>
        </div>
    `).join('');
}

// ============ CAB Review Panel (Detail View) ============

function renderCabReviewPanel(c) {
    if (!parseInt(c.cab_required) || !c.cab_members) return '';

    const members = c.cab_members;
    const requiredMembers = members.filter(m => parseInt(m.is_required));
    const requiredApproved = requiredMembers.filter(m => m.vote === 'Approve').length;
    const approvalType = c.cab_approval_type === 'majority' ? 'Majority' : 'All must approve';
    const currentAnalystId = parseInt(document.body.dataset.analystId) || 0;
    const myMembership = members.find(m => parseInt(m.analyst_id) === currentAnalystId);
    const canVote = myMembership && !myMembership.vote && c.status === 'Pending Approval';

    let html = `
        <div class="cab-review-section">
            <div class="cab-review-header">
                <h3>CAB Review</h3>
                <div class="cab-review-meta">
                    <span class="cab-progress-badge">${requiredApproved} of ${requiredMembers.length} required</span>
                    <span class="cab-approval-type">${approvalType}</span>
                </div>
            </div>
            <div class="cab-members-grid">
    `;

    members.forEach(m => {
        const isReq = parseInt(m.is_required);
        const voteClass = m.vote ? m.vote.toLowerCase() : 'pending';
        const voteLabel = m.vote || 'Pending';

        html += `
            <div class="cab-member-card vote-${voteClass}">
                <div class="cab-member-card-header">
                    <span class="cab-member-card-name">${escapeHtml(m.analyst_name || 'Unknown')}</span>
                    <span class="cab-member-badge ${isReq ? 'required' : 'optional'}">${isReq ? 'Required' : 'Optional'}</span>
                </div>
                <div class="cab-member-card-vote">
                    <span class="cab-vote-status vote-${voteClass}">${voteLabel}</span>
                    ${m.vote_datetime ? `<span class="cab-vote-date">${formatDateTime(m.vote_datetime)}</span>` : ''}
                </div>
                ${m.vote_comment ? `<div class="cab-vote-comment">${escapeHtml(m.vote_comment)}</div>` : ''}
            </div>
        `;
    });

    html += '</div>';

    // Vote form for current user
    if (canVote) {
        html += `
            <div class="cab-vote-form">
                <textarea class="form-input" id="cabVoteComment" rows="2" placeholder="Add a comment with your vote (optional)"></textarea>
                <div class="cab-vote-buttons">
                    <button class="btn cab-btn-approve" onclick="submitCabVote('Approve')">Approve</button>
                    <button class="btn cab-btn-reject" onclick="submitCabVote('Reject')">Reject</button>
                    <button class="btn cab-btn-abstain" onclick="submitCabVote('Abstain')">Abstain</button>
                </div>
            </div>
        `;
    }

    html += '</div>';
    return html;
}

async function submitCabVote(vote) {
    if (!currentChange) return;

    const comment = document.getElementById('cabVoteComment')?.value?.trim() || '';

    try {
        const response = await fetch(API_BASE + 'submit_cab_vote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                change_id: currentChange.id,
                vote: vote,
                vote_comment: comment
            })
        });
        const data = await response.json();

        if (data.success) {
            showToast('Vote recorded: ' + vote);
            // Refresh the change detail
            await viewChange(currentChange.id);
            if (data.status_changed) {
                loadChanges();
            }
        } else {
            showToast('Error: ' + data.error);
        }
    } catch (error) {
        showToast('Error submitting vote');
    }
}

// ============ View Management ============

function showView(view) {
    document.getElementById('changeListView').style.display = view === 'list' ? '' : 'none';
    document.getElementById('changeDetailView').style.display = view === 'detail' ? '' : 'none';
    document.getElementById('changeEditorView').style.display = view === 'editor' ? '' : 'none';
    if (view === 'editor') {
        applyFieldVisibility();
    }
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
