/**
 * Ticket Checklists / SOP Module for FreeITSM
 *
 * 🔴 API PATHS ARE RELATIVE TO THE HOST PAGE, NEVER ROOT-ABSOLUTE.
 *
 * These calls used to be fetch('/api/tickets/ticket_checklists.php'), which
 * resolves only when FreeITSM is installed AT the document root. On an install
 * in a subdirectory every one of them 404s and the whole SOP panel silently
 * does nothing — measured: /api/... returned 404 and /freeitsm-app/api/...
 * returned 200 on the same machine.
 *
 * tickets/index.php already publishes window.API_BASE ('../api/tickets/'), which
 * is derived from BASE_URL and therefore correct in both layouts. The fallback
 * matches inbox.js's own, for a page that forgets to set it.
 */
const CHK_API = (window.API_BASE || '../api/tickets/') + 'ticket_checklists.php';

let ticketChecklistsData = [];
let availableTemplatesCache = [];
let currentViewingTicketId = null;

/**
 * Translate, the way assets/js/calendar.js does it.
 *
 * 🔴 `window.t`, never a bare `t`. Two of the loops in this file are written
 * `forEach(t => ...)` and `forEach(({ template: t }) => ...)`, where `t` is the
 * template row — which SHADOWS the global translation function. An inline
 * `typeof t === 'function'` test inside those loops is always false, so the
 * string silently falls back to English and no locale can ever reach it. Going
 * through window makes the lookup immune to whatever `t` means locally.
 */
function chkT(key, fallback) {
    const v = (typeof window.t === 'function') ? window.t(key) : '';
    return (v && v !== key) ? v : fallback;
}

/**
 * The padlock marking a checklist that blocks closure until it is complete.
 *
 * One definition rather than the same 400-character expression pasted into the
 * inline panel, the popup modal and the attach dialogue — the three places it
 * appears, two of which are inside the shadowed loops described above.
 */
function closureLockIcon(effectiveMode, px) {
    if (effectiveMode !== 'block') return '';
    const label = escapeHtml(chkT('tickets.checklists.mandatory_for_closure', 'Mandatory for closure'));
    const size  = px || 12;
    return '<span class="chk-lock" title="' + label + '" aria-label="' + label + '"'
         + ' style="display: inline-flex; color: #dc2626;">'
         + '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"'
         + ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"'
         + ' aria-hidden="true">'
         + '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>'
         + '<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>'
         + '</svg></span>';
}

// Format exact server datetime as dd-mmm-yy HH:MM
function formatStepDatetime(dtStr) {
    if (!dtStr) return '';
    try {
        const parts = dtStr.split(/[\sT]+/);
        if (parts.length >= 2) {
            const dateParts = parts[0].split('-');
            const timeParts = parts[1].split(':');
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const day = dateParts[2].padStart(2, '0');
            const monthIdx = parseInt(dateParts[1], 10) - 1;
            const mon = months[monthIdx] || dateParts[1];
            const year = dateParts[0].slice(-2);
            const hours = timeParts[0].padStart(2, '0');
            const mins = timeParts[1].padStart(2, '0');
            return `${day}-${mon}-${year} ${hours}:${mins}`;
        }
        return dtStr;
    } catch (e) {
        return dtStr;
    }
}

// 1. Fetch & Render Checklists for a Ticket
async function loadTicketChecklists(ticketId) {
    if (!ticketId) return;
    const isCurrent = typeof ticketReadGuard === 'function' ? ticketReadGuard(ticketId) : () => currentViewingTicketId === ticketId;
    currentViewingTicketId = ticketId;
    try {
        const res = await fetch(CHK_API + '?action=get_ticket_checklists&ticket_id=' + ticketId);
        const data = await res.json();
        if (!isCurrent()) return;
        if (!data.success) {
            console.error('Error fetching ticket checklists:', data.error);
            window.TicketWorkspace?.setSectionState(ticketId, 'work', 'error', null, 'checklists');
            return;
        }

        ticketChecklistsData = data.checklists || [];
        window.TicketWorkspace?.setSectionState(ticketId, 'work', 'ready', null, 'checklists');
        window.ticketChecklistEmptyClosureMode = data.empty_closure_mode || 'off';
        renderChecklistToolbarButton(ticketId);
        renderTicketChecklistsInline(ticketId);
    } catch (e) {
        if (!isCurrent()) return;
        console.error('Failed to load checklists:', e);
        window.TicketWorkspace?.setSectionState(ticketId, 'work', 'error', null, 'checklists');
    }
}

/** Finds a step by id across the ticket's attached checklists and returns {checklist, item}, or null. */
function findChecklistItem(itemId) {
    for (const chk of ticketChecklistsData) {
        for (const it of (chk.items || [])) {
            if (parseInt(it.id, 10) === parseInt(itemId, 10)) {
                return { checklist: chk, item: it };
            }
        }
    }
    return null;
}

/**
 * Lists the mandatory steps not yet completed, with each checklist's closure mode - read by inbox.js before a ticket is closed.
 */
function getIncompleteMandatorySteps() {
    const list = [];
    (ticketChecklistsData || []).forEach(chk => {
        (chk.items || []).forEach(it => {
            const isMand = (it.is_mandatory == 1 || it.is_mandatory === true || it.is_mandatory === '1');
            const isComp = (it.is_completed == 1 || it.is_completed === true || it.is_completed === '1');
            if (isMand && !isComp) {
                list.push({
                    checklist: chk.title,
                    step: it.title,
                    closure_mode: chk.effective_closure_mode || chk.closure_mode || 'warn'
                });
            }
        });
    });
    return list;
}

// 2. Render Action Toolbar Button
function renderChecklistToolbarButton(ticketId) {
    const toolbar = document.querySelector('.action-toolbar');
    if (!toolbar) return;

    let btn = document.getElementById('btnSopChecklists');
    let completedCount = 0;
    let totalItems = 0;

    ticketChecklistsData.forEach(c => {
        totalItems += (c.total_items || 0);
        completedCount += (c.completed_items || 0);
    });

    const badgeText = totalItems > 0 ? ` (${completedCount}/${totalItems})` : '';

    if (!btn) {
        btn = document.createElement('button');
        btn.id = 'btnSopChecklists';
        btn.className = 'action-btn';
        btn.type = 'button';
        btn.onclick = () => openChecklistModal(ticketId);
        toolbar.insertBefore(btn, toolbar.firstChild);
    }

    btn.innerHTML = `
        <span class="action-btn-icon">✅</span>
        <span>${chkT('checklists.panel.title', 'Checklist')}${badgeText}</span>
    `;
}

// 3. Render Inline SOP Card
function renderTicketChecklistsInline(ticketId) {
    let host = document.getElementById('ticketChecklistContainer');
    if (!host) {
        const emailBody = document.querySelector('.email-body');
        if (!emailBody) return;
        host = document.createElement('div');
        host.id = 'ticketChecklistContainer';
        host.style.marginBottom = '22px'; host.style.paddingBottom = '16px'; host.style.borderBottom = '1px solid var(--border-soft, #e2e8f0)';
        emailBody.insertBefore(host, emailBody.firstChild);
    }

    // Hidden until there is something to say: a ticket with no checklist and
    // nothing to suggest gets no strip - the Checklist button attaches one.
    host.hidden = !ticketChecklistsData || ticketChecklistsData.length === 0;

    if (!ticketChecklistsData || ticketChecklistsData.length === 0) {
        host.innerHTML = `
            <div id="sopEmptyStateHost_${ticketId}" style="background: var(--surface, #ffffff); border: 1px dashed var(--border, #cbd5e1); border-radius: 6px; padding: 8px 14px; margin: -15px 0 12px 0; display: flex; justify-content: space-between; align-items: center;">
                <div style="color: var(--text-muted, #64748b); font-size: 13px;">
                    <strong>${chkT('checklists.panel.title', 'Checklist')}:</strong> ${chkT('checklists.panel.none_attached', 'None attached to this ticket.')}
                </div>
                <button type="button" class="chk-chip" onclick="openAttachChecklistModal(${ticketId})">
                    ${chkT('checklists.panel.attach', 'Attach')}
                </button>
            </div>
        `;
        checkAndShowSopSuggestion(ticketId);
        return;
    }

    let html = `
        <div style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 6px; padding: 10px 14px; color: var(--text, #1e293b); box-shadow: 0 1px 2px var(--shadow, rgba(0,0,0,0.04)); margin: -15px 0 12px 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 14px;">✅</span>
                    <strong style="font-size: 13px; color: var(--text, #1e293b);">${chkT('checklists.panel.next_steps', 'Checklist next steps')}</strong>
                </div>
                <div style="display: flex; gap: 6px;">
                    <button type="button" class="chk-chip" onclick="openChecklistModal(${ticketId})" title="${chkT('checklists.panel.pop_out', 'Pop out full checklist')}">
                        <span>⤢</span> Pop out
                    </button>
                    <button type="button" class="chk-chip" onclick="openAttachChecklistModal(${ticketId})">
                        ${chkT('checklists.panel.attach', 'Attach')}
                    </button>
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
    `;

    ticketChecklistsData.forEach(chk => {
        const items = chk.items || [];
        const nextPendingItem = items.find(it => !(it.is_completed == 1 || it.is_completed === true || it.is_completed === '1'));
        const percent = chk.percent || 0;
        const allDone = !nextPendingItem && items.length > 0;

        html += `
            <div style="border: 1px solid var(--border, #f1f5f9); border-radius: 6px; padding: 8px 10px; background: var(--surface-hover, #f8fafc);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <span style="font-size: 12px; font-weight: 600; color: var(--text, #334155); display: inline-flex; align-items: center; gap: 4px;">${escapeHtml(chk.title)}${closureLockIcon(chk.effective_closure_mode, 12)}</span>
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-muted, #64748b);">
                        <span style="font-weight: 600; color: ${allDone ? '#16a34a' : 'inherit'};">${percent}%</span>
                        <div style="width: 50px; height: 5px; background: var(--border, #e2e8f0); border-radius: 3px; overflow: hidden;">
                            <div style="width: ${percent}%; height: 100%; background: ${allDone ? '#16a34a' : 'var(--primary, #2563eb)'}; transition: width 0.3s ease;"></div>
                        </div>
                    </div>
                </div>
        `;

        if (allDone) {
            html += `
                <div style="font-size: 12px; color: #16a34a; display: flex; align-items: center; gap: 6px; padding: 2px 0;">
                    <span>✓</span> All ${items.length} steps completed
                </div>
            `;
        } else if (nextPendingItem) {
            const isMand = (nextPendingItem.is_mandatory == 1 || nextPendingItem.is_mandatory === true || nextPendingItem.is_mandatory === '1');
            const reqInputBadge = (nextPendingItem.requires_input == 1) ? `<span style="font-size: 10px; background: #e0e7ff; color: #3730a3; padding: 1px 5px; border-radius: 3px; margin-left: 6px;">📝 Note required</span>` : '';
            const roleBadge = nextPendingItem.suggested_role ? `<span style="font-size: 10px; background: rgba(13,148,136,0.1); color: #0d9488; font-weight: 600; padding: 1px 6px; border-radius: 4px; white-space: nowrap; margin-left: 6px;" title="Suggested Role"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 3px; display: inline-block; vertical-align: -1px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>${escapeHtml(nextPendingItem.suggested_role)}</span>` : '';
            
            html += `
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 2px 0;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; cursor: pointer; color: var(--text, #1e293b); flex: 1;">
                        <input type="checkbox" onchange="handleStepCheckboxChange(${ticketId}, ${nextPendingItem.id}, this, false)" style="cursor: pointer; width: 15px; height: 15px; accent-color: var(--primary, #2563eb);">
                        <span>
                            <strong style="color: var(--text-muted, #64748b); font-weight: normal; margin-right: 4px;">Next:</strong>
                            ${escapeHtml(nextPendingItem.title)}
                            ${isMand ? '<span style="color: #ef4444; margin-left: 2px; font-weight: bold;" title="Mandatory">*</span>' : ''}
                            ${roleBadge}
                            ${reqInputBadge}
                        </span>
                    </label>
                    <a href="javascript:void(0)" onclick="openChecklistModal(${ticketId})" style="font-size: 11px; color: var(--primary, #2563eb); text-decoration: none; white-space: nowrap; margin-left: 8px;">View all steps &raquo;</a>
                </div>
            `;
        }

        html += `</div>`;
    });

    html += `</div></div>`;
    host.innerHTML = html;
}

// 4. Full Pop-out Checklist Modal
function openChecklistModal(ticketId) {
    if (!ticketId) ticketId = currentViewingTicketId;
    let modal = document.getElementById('ticketChecklistModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'ticketChecklistModal';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); z-index: 3500; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px);';
        document.body.appendChild(modal);
    }

    let checklistsHtml = '';
    if (!ticketChecklistsData || ticketChecklistsData.length === 0) {
        checklistsHtml = `
            <div style="text-align: center; padding: 30px; color: var(--text-muted, #64748b);">
                <p style="margin-bottom: 12px;">No checklists are attached to this ticket.</p>
                <button class="btn btn-primary" type="button" onclick="closeChecklistModal(); openAttachChecklistModal(${ticketId});">
                    ${chkT('checklists.panel.attach', 'Attach')}
                </button>
            </div>
        `;
    } else {
        ticketChecklistsData.forEach(chk => {
            const percent = chk.percent || 0;
            checklistsHtml += `
                <div style="border: 1px solid var(--border, #e2e8f0); border-radius: 8px; margin-bottom: 16px; overflow: hidden; background: var(--surface, #ffffff);">
                    <div style="padding: 10px 14px; background: var(--surface-hover, #f8fafc); border-bottom: 1px solid var(--border, #e2e8f0); display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong style="font-size: 14px; color: var(--text, #1e293b); display: inline-flex; align-items: center; gap: 6px;">${escapeHtml(chk.title)}${closureLockIcon(chk.effective_closure_mode, 13)}</strong>
                            <div style="font-size: 11px; color: var(--text-muted, #64748b); margin-top: 2px;">
                                ${chk.completed_items || 0} of ${chk.total_items || 0} steps completed (${percent}%)
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <div style="width: 80px; height: 6px; background: var(--border, #e2e8f0); border-radius: 3px; overflow: hidden;">
                                <div style="width: ${percent}%; height: 100%; background: ${percent === 100 ? '#16a34a' : 'var(--primary, #2563eb)'};"></div>
                            </div>
                            <button type="button" onclick="removeTicketChecklist(${ticketId}, ${chk.id})" title="Remove checklist" style="border: none; background: none; color: #ef4444; font-size: 16px; cursor: pointer; padding: 2px 6px;">&times;</button>
                        </div>
                    </div>
                    <div style="padding: 10px 14px; display: flex; flex-direction: column; gap: 8px;">
            `;

            (chk.items || []).forEach(it => {
                const isChecked = (it.is_completed == 1 || it.is_completed === true || it.is_completed === '1');
                const isMand = (it.is_mandatory == 1 || it.is_mandatory === true || it.is_mandatory === '1');
                // `|| it.completed_at` was the client half of the same phantom-column
                // fallback the API carried; there has never been a completed_at.
                const completedDate = formatStepDatetime(it.completed_datetime);
                const completedMeta = isChecked && it.completed_by_name ? `<div style="font-size: 11px; color: var(--text-muted, #64748b); margin-left: 24px;">Completed by ${escapeHtml(it.completed_by_name)}${completedDate ? ' on ' + escapeHtml(completedDate) : ''}</div>` : '';
                const responseMeta = it.response_value ? `<div style="font-size: 11px; color: #3730a3; background: #eef2ff; padding: 3px 8px; border-radius: 4px; margin-left: 24px; margin-top: 2px; border-left: 2px solid #6366f1;"><strong>Input:</strong> ${escapeHtml(it.response_value)}</div>` : '';
                const reqBadge = (!isChecked && it.requires_input == 1) ? `<span style="font-size: 10px; background: #e0e7ff; color: #3730a3; padding: 1px 5px; border-radius: 3px; margin-left: 6px;">📝 Note required</span>` : '';
                const roleBadge = it.suggested_role ? `<span style="font-size: 10px; background: rgba(13,148,136,0.1); color: #0d9488; font-weight: 600; padding: 1px 6px; border-radius: 4px; white-space: nowrap; margin-left: 6px;" title="Suggested Role"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 3px; display: inline-block; vertical-align: -1px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>${escapeHtml(it.suggested_role)}</span>` : '';

                checklistsHtml += `
                    <div style="padding: 4px 0;">
                        <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text, #1e293b);">
                            <input type="checkbox" ${isChecked ? 'checked' : ''} onchange="handleStepCheckboxChange(${ticketId}, ${it.id}, this, true)" style="cursor: pointer; width: 16px; height: 16px; margin-top: 2px; accent-color: var(--primary, #2563eb);">
                            <div style="flex: 1;">
                                <span style="${isChecked ? 'text-decoration: line-through; color: var(--text-muted, #94a3b8);' : ''}">
                                    ${escapeHtml(it.title)}
                                    ${isMand ? '<span style="color: #ef4444; font-weight: bold; margin-left: 2px;" title="Mandatory">*</span>' : ''}
                                    ${roleBadge}
                                    ${reqBadge}
                                </span>
                            </div>
                        </label>
                        ${responseMeta}
                        ${completedMeta}
                    </div>
                `;
            });

            checklistsHtml += `</div></div>`;
        });
    }

    modal.innerHTML = `
        <div style="background: var(--surface, #ffffff); border-radius: 8px; width: 92%; max-width: 650px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.25); border: 1px solid var(--border, #cbd5e1); color: var(--text, #1e293b);">
            <div style="padding: 14px 18px; border-bottom: 1px solid var(--border, #e2e8f0); display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 18px;">✅</span>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #1e293b);">${chkT('checklists.panel.modal_title', 'Checklists for ticket #{id}').replace('{id}', ticketId)}</h3>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button type="button" class="chk-chip" onclick="openAttachChecklistModal(${ticketId})">
                        ${chkT('checklists.panel.attach', 'Attach')}
                    </button>
                    
                </div>
            </div>
            <div style="padding: 16px 18px; overflow-y: auto; flex: 1;">
                ${checklistsHtml}
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" onclick="closeChecklistModal()">
                    Close
                </button>
            </div>
        </div>
    `;
    modal.style.display = 'flex';
}

/** Hides the ticket checklist pop-out. */
function closeChecklistModal() {
    const modal = document.getElementById('ticketChecklistModal');
    if (modal) modal.style.display = 'none';
}

// 5. Handle Checkbox Click with Data Capture Prompt
function handleStepCheckboxChange(ticketId, itemId, checkboxElem, reopenPopout) {
    const isChecking = checkboxElem.checked;
    const itemInfo = findChecklistItem(itemId);
    if (!itemInfo) return;

    const item = itemInfo.item;
    const chk = itemInfo.checklist;

    const needsInput = isChecking && (item.requires_input == 1 || item.requires_input === true || item.requires_input === '1');

    if (needsInput) {
        checkboxElem.checked = false;
        promptDataCapture(ticketId, item, chk.title, reopenPopout);
        return;
    }

    executeToggleItem(ticketId, itemId, isChecking ? 1 : 0, null, chk.title, item.title, reopenPopout);
}

// Prompt for note or data input (Rejects blank entries!)
function promptDataCapture(ticketId, item, checklistTitle, reopenPopout) {
    let modal = document.getElementById('chkDataCaptureModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'chkDataCaptureModal';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); z-index: 3600; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px);';
        document.body.appendChild(modal);
    }

    const placeholder = item.input_placeholder || 'Enter step details, serial #, or note...';

    modal.innerHTML = `
        <div style="background: var(--surface, #ffffff); border-radius: 8px; width: 90%; max-width: 440px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); border: 1px solid var(--border, #cbd5e1); color: var(--text, #1e293b); padding: 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 600;">📝 Step Data Capture</h4>
                
            </div>
            <p style="font-size: 12px; color: var(--text-muted, #64748b); margin: 0 0 10px 0;">
                Step: <strong>${escapeHtml(item.title)}</strong>
            </p>
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 500;">
                    Details / Recorded Value: <span style="color: #ef4444;">*</span>
                </label>
                <input type="text" id="chkCaptureInput" style="width: 100%; box-sizing: border-box; padding: 7px 10px; font-size: 13px; border: 1px solid var(--border, #cbd5e1); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333);" placeholder="${escapeHtml(placeholder)}" autofocus>
                <div id="chkCaptureError" style="display: none; color: #ef4444; font-size: 11px; margin-top: 4px;">This step requires a value or note. Please enter details before completing.</div>
            </div>
            <div class="modal-footer" style="border-top: none; padding-right: 0; padding-bottom: 0;">
                <button class="btn btn-secondary" type="button" onclick="closeDataCaptureModal()">
                    Cancel
                </button>
                <button class="btn btn-primary" type="button" id="chkSaveCaptureBtn">
                    Complete Step
                </button>
            </div>
        </div>
    `;
    modal.style.display = 'flex';

    const input = document.getElementById('chkCaptureInput');
    const err = document.getElementById('chkCaptureError');
    input.focus();

    /** Refuses a blank value; otherwise closes the prompt and completes the step with the value recorded. */
    const doSave = () => {
        const val = input.value.trim();
        if (!val) {
            err.style.display = 'block';
            input.style.borderColor = '#ef4444';
            input.focus();
            return;
        }
        closeDataCaptureModal();
        executeToggleItem(ticketId, item.id, 1, val, checklistTitle, item.title, reopenPopout);
    };

    document.getElementById('chkSaveCaptureBtn').onclick = doSave;
    input.onkeydown = (e) => { 
        err.style.display = 'none';
        input.style.borderColor = 'var(--border, #cbd5e1)';
        if (e.key === 'Enter') doSave(); 
    };
}

/** Hides the step data-capture prompt. */
function closeDataCaptureModal() {
    const modal = document.getElementById('chkDataCaptureModal');
    if (modal) modal.style.display = 'none';
}

// 6. Execute Toggle Item in Backend
async function executeToggleItem(ticketId, itemId, completed, responseValue, checklistTitle, stepTitle, reopenPopout) {
    try {
        const res = await fetch(CHK_API + '', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'toggle_item',
                item_id: itemId,
                completed: completed,
                response_value: responseValue
            })
        });
        const data = await res.json();
        if (data.success) {
            await loadTicketChecklists(ticketId);
            if (reopenPopout) {
                openChecklistModal(ticketId);
            }
            logStepNote(ticketId, checklistTitle, stepTitle, completed, responseValue);
        } else {
            showToast(data.error || 'Could not update the step', 'error');
        }
    } catch (e) {
        console.error('Error toggling step:', e);
    }
}

/**
 * Adds an internal ticket note saying a step was completed (with any recorded value) or reopened, then reloads the notes.
 */
async function logStepNote(ticketId, checklistTitle, stepTitle, completed, responseValue) {
    let noteText = "";
    if (completed) {
        noteText = `✅ Checklist step completed: [${checklistTitle}] "${stepTitle}"`;
        if (responseValue) {
            noteText += ` — Response: ${responseValue}`;
        }
    } else {
        noteText = `↩️ Checklist step reopened: [${checklistTitle}] "${stepTitle}" was marked incomplete`;
    }

    try {
        await fetch((window.API_BASE || "../api/tickets/") + "save_note.php", {   // was root-absolute: broke on a subfolder install
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                ticket_id: ticketId,
                note_text: noteText,
                is_internal: true
            })
        });
        if (typeof loadNotes === "function") {
            loadNotes(ticketId);
        }
    } catch (e) {
        console.warn("Could not log note for step action:", e);
    }
}

// 7. Remove Checklist from Ticket
async function removeTicketChecklist(ticketId, checklistId) {
    const chk = (ticketChecklistsData || []).find(c => parseInt(c.id, 10) === parseInt(checklistId, 10));
    const done = chk ? (chk.completed_items || 0) : 0;
    const chkName = chk ? chk.title : chkT('checklists.panel.title', 'Checklist');
    const ok = await showConfirm({
        title: chkT('checklists.panel.remove_title', 'Remove checklist'),
        // Say what is actually lost. Removing a part-completed checklist throws
        // away who ticked what and when, which is the point of the feature.
        //
        // ⚠️ Two messages, not one with a plural suffix bolted on. "step" +
        // "s" is an English-only rule; a language with three plural forms, or
        // one that inflects the noun after a number, cannot be served by
        // appending a letter. The count goes in as a placeholder and each
        // locale words the whole sentence.
        message: done > 0
            ? chkT('checklists.panel.remove_confirm_done',
                   'Remove "{name}" from this ticket? {count} completed step(s) and their attribution go with it.')
                .replace('{name}', chkName).replace('{count}', done)
            : chkT('checklists.panel.remove_confirm', 'Remove "{name}" from this ticket?')
                .replace('{name}', chkName),
        okLabel: chkT('checklists.editor.remove', 'Remove'), okClass: 'danger'
    });
    if (!ok) return;
    try {
        const res = await fetch(CHK_API + '', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'remove_checklist',
                checklist_id: checklistId
            })
        });
        const data = await res.json();
        if (data.success) {
            await loadTicketChecklists(ticketId);
            openChecklistModal(ticketId);
        } else {
            showToast(data.error || 'Could not remove the checklist', 'error');
        }
    } catch (e) {
        console.error('Error removing checklist:', e);
    }
}

// 8. Attach checklist Modal
async function openAttachChecklistModal(ticketId) {
    if (!ticketId) ticketId = currentViewingTicketId;
    let modal = document.getElementById('ticketAttachChecklistModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'ticketAttachChecklistModal';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); z-index: 3600; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px);';
        document.body.appendChild(modal);
    }

    try {
        const res = await fetch(CHK_API + '?action=list_templates_for_ticket&ticket_id=' + encodeURIComponent(ticketId || ''));
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Could not load the templates', 'error');
            return;
        }
        availableTemplatesCache = data.templates || [];
    } catch (e) {
        showToast('Could not load the templates', 'error');
        return;
    }

    modal.innerHTML = `
        <div class="chk-attach-dialog" style="background: var(--surface, #ffffff); border-radius: 8px; width: 90%; max-width: 560px; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.25); border: 1px solid var(--border, #cbd5e1); color: var(--text, #1e293b);">
            <div style="padding: 14px 18px; border-bottom: 1px solid var(--border, #e2e8f0); display: flex; justify-content: space-between; align-items: center;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 600;">Attach checklist</h4>
            </div>
            <div style="padding: 12px 18px 6px 18px;">
                <input type="text" id="chkSearchInput" onkeyup="filterTemplatesList(${ticketId})" placeholder="Search checklists..." style="width: 100%; box-sizing: border-box; padding: 7px 10px; font-size: 13px; border: 1px solid var(--border, #cbd5e1); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333);">
            </div>
            <div id="chkTemplatesList" class="chk-attach-list" style="padding: 10px 18px; display: flex; flex-direction: column; gap: 8px;">
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" onclick="closeAttachModal()">
                    Cancel
                </button>
            </div>
        </div>
    `;
    modal.style.display = 'flex';
    filterTemplatesList(ticketId);
}

/**
 * A stable colour per category, derived from its name.
 *
 * Not random and not stored: the same category is always the same colour, on
 * every install and every reload, without anybody having to pick one. Six hues
 * is enough to tell a short list apart and few enough that none of them fight
 * the teal the module already uses.
 */
function categoryPillClass(name) {
    const s = String(name || 'General');
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return 'chk-pill-c' + (h % 6);
}

/** Lists the templates matching the attach dialogue's search, best match first, each with an Attach button. */
function filterTemplatesList(ticketId) {
    const list = document.getElementById('chkTemplatesList');
    if (!list) return;
    const q = (document.getElementById('chkSearchInput')?.value || '').trim();

    const scored = [];
    (availableTemplatesCache || []).forEach(t => {
        const res = scoreChecklistTemplate(t, q);
        if (res.matched) {
            scored.push({ template: t, score: res.score });
        }
    });

    if (scored.length === 0) {
        list.innerHTML = `<div style="text-align: center; color: var(--text-muted, #64748b); padding: 20px; font-size: 13px;">${chkT('checklists.panel.no_match', 'No matching checklists found.')}</div>`;
        return;
    }

    // Sort by relevance score descending, then by title
    scored.sort((a, b) => b.score - a.score || (a.template.title || '').localeCompare(b.template.title || ''));

    let html = '';
    scored.forEach(({ template: t }) => {
        // Title and pill share the top row, pill hard right. Then the
        // description on its own full-width line — it is a real sentence and
        // deserves the width — then the action beneath it, left aligned under
        // the text it belongs to. The old layout floated the button against the
        // vertical centre, which pinched the description into a narrow column
        // and left the button hanging beside nothing on a one-line entry.
        html += `
            <div class="chk-tpl-row">
                <div class="chk-tpl-top">
                    <span class="chk-tpl-title" style="display: inline-flex; align-items: center; gap: 5px;">
                        ${escapeHtml(t.title)}
                        ${closureLockIcon(t.effective_closure_mode, 12)}
                    </span>
                    <span class="chk-tpl-pill ${categoryPillClass(t.category)}">${escapeHtml(t.category || 'General')}</span>
                </div>
                ${t.description ? `<p class="chk-tpl-desc">${escapeHtml(t.description)}</p>` : ''}
                <div>
                    <button type="button" onclick="attachSopToTicket(${ticketId}, ${t.id})" class="chk-tpl-attach">Attach</button>
                </div>
            </div>
        `;
    });
    list.innerHTML = html;
}

/**
 * Attaches a checklist template to the ticket through ticket_checklists.php, then reloads and opens the ticket's checklists.
 */
async function attachSopToTicket(ticketId, templateId) {
    try {
        const res = await fetch(CHK_API + '', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'attach_template',
                ticket_id: ticketId,
                template_id: templateId
            })
        });
        const data = await res.json();
        if (data.success) {
            closeAttachModal();
            await loadTicketChecklists(ticketId);
            openChecklistModal(ticketId);
        } else {
            showToast(data.error || 'Could not attach the checklist', 'error');
        }
    } catch (e) {
        console.error('Error attaching SOP:', e);
    }
}

/** Hides the attach-checklist dialogue. */
function closeAttachModal() {
    const modal = document.getElementById('ticketAttachChecklistModal');
    if (modal) modal.style.display = 'none';
}

/** Escapes text for safe use in HTML and attributes. */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Expose functions globally
window.loadTicketChecklists = loadTicketChecklists;
window.openChecklistModal = openChecklistModal;
window.openAttachChecklistModal = openAttachChecklistModal;
/** Returns how many checklists are attached to the open ticket - read by inbox.js at close. */
function getAttachedChecklistsCount() {
    if (!Array.isArray(ticketChecklistsData)) return 0;
    return ticketChecklistsData.length;
}

/**
 * Returns what closing a ticket with no checklist does ('off' unless the page set window.ticketChecklistEmptyClosureMode).
 */
function getTicketChecklistEmptyClosureMode() {
    return window.ticketChecklistEmptyClosureMode || 'off';
}

window.getAttachedChecklistsCount = getAttachedChecklistsCount;
window.getTicketChecklistEmptyClosureMode = getTicketChecklistEmptyClosureMode;
window.getIncompleteMandatorySteps = getIncompleteMandatorySteps;


// ============================================================================


// ============================================================================

// ============================================================================
// SOP Response Styling in Notes Timeline (Italics & Distinct Color)
// ============================================================================
function styleSopResponsesInNotes() {
    const notesContainer = document.getElementById("notesContainer");
    if (!notesContainer) return;

    const noteTexts = notesContainer.querySelectorAll(".note-text");
    noteTexts.forEach(el => {
        if (el.dataset.sopStyled) return;
        const text = el.innerText || el.textContent;
        if (text.includes("Response:")) {
            const formatted = el.innerHTML.replace(
                /(Response:\s*)([^\n<]+)/g,
                `<span style="color: #64748b; font-weight: 500;">$1</span><span style="font-style: italic; color: #2563eb; font-weight: 600; background: #eff6ff; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 2px;">$2</span>`
            );
            el.innerHTML = formatted;
            el.dataset.sopStyled = "true";
        }
    });
}

// Observe notes container for changes
if (typeof MutationObserver !== "undefined") {
    const observer = new MutationObserver(() => {
        styleSopResponsesInNotes();
    });
    const checkExist = setInterval(() => {
        const container = document.getElementById("notesContainer");
        if (container) {
            observer.observe(container, { childList: true, subtree: true });
            clearInterval(checkExist);
        }
    }, 500);
}

window.dismissedSuggestions = window.dismissedSuggestions || {};

/**
 * Asks ticket_checklists.php for templates that suit this ticket and shows them, with confidence and Attach buttons, in the empty-checklist box (unless dismissed).
 */
async function checkAndShowSopSuggestion(ticketId) {
    if (window.dismissedSuggestions[ticketId]) return;
    try {
        const res = await fetch(CHK_API + `?action=suggest_template&ticket_id=${ticketId}`);   // was root-absolute: broke on a subfolder install
        const data = await res.json();
        const hostDiv = document.getElementById(`sopEmptyStateHost_${ticketId}`);
        if (!hostDiv) return;

        const list = (data.suggestions && data.suggestions.length > 0) 
            ? data.suggestions 
            : (data.suggested ? [data.suggested] : []);

        if (list.length > 0) {
            const strip = document.getElementById('ticketChecklistContainer');
            if (strip) strip.hidden = false;   // a suggestion is worth the room
            hostDiv.style.background = '#f0fdfa';
            hostDiv.style.border = '1px solid #99f6e4';
            hostDiv.style.borderRadius = '6px';
            hostDiv.style.padding = '10px 14px';

            const matchLabel = list.length === 1 
                ? '💡 ' + chkT('checklists.panel.suggested_one', 'Suggested checklist for this ticket (Best match):')
                : '💡 ' + chkT('checklists.panel.suggested_many', 'Suggested checklists for this ticket (Top {count} matches):').replace('{count}', list.length);

            let itemsHtml = '';
            list.forEach((s, idx) => {
                const borderBottom = (idx < list.length - 1) ? 'border-bottom: 1px dashed #ccfbf1;' : '';
                const conf = s.confidence_percent || 75;
                const confTooltip = `title="Confidence level: ${conf}% based on ticket content"`;
                
                // Target icon SVG
                const targetIcon = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block; vertical-align:-1px; margin-right:3px;"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>`;

                itemsHtml += `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; gap: 12px; ${borderBottom}">
                        <!-- Left side: Title and Category badge (shrinks with ellipsis if space is constrained) -->
                        <div style="min-width: 0; flex: 1; display: flex; align-items: center; gap: 8px; overflow: hidden;">
                            <span style="font-weight: 600; color: #0f766e; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(s.title)}">
                                ${escapeHtml(s.title)}
                            </span>
                            <span style="flex-shrink: 0; font-size: 11px; background: #ccfbf1; color: #0f766e; padding: 1px 6px; border-radius: 3px; white-space: nowrap;">
                                ${escapeHtml(s.category || 'General')}
                            </span>
                        </div>

                        <!-- Right side: Confidence pill directly before the Attach button (never shrinks) -->
                        <div style="flex-shrink: 0; display: flex; align-items: center; gap: 8px;">
                            <span style="display: inline-flex; align-items: center; font-size: 11px; font-weight: 600; background: #e6fffa; color: #0d9488; border: 1px solid #99f6e4; padding: 2px 8px; border-radius: 12px; white-space: nowrap; cursor: help;" ${confTooltip}>
                                ${targetIcon}${conf}%
                            </span>
                            <button type="button" onclick="attachSopToTicket(${ticketId}, ${s.id})" style="background: #0d9488; color: #fff; border: none; padding: 4px 10px; font-size: 11px; border-radius: 4px; font-weight: 600; cursor: pointer; white-space: nowrap;">
                                ${chkT('checklists.panel.attach', 'Attach')}
                            </button>
                        </div>
                    </div>
                `;
            });

            hostDiv.innerHTML = `
                <div style="display: flex; flex-direction: column; width: 100%; gap: 6px;">
                    <!-- First row: Explicit matches count on left, Browse & Dismiss on right -->
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 4px; border-bottom: 1px solid #99f6e4;">
                        <div style="font-size: 12px; font-weight: 700; color: #0f766e; display: flex; align-items: center; gap: 6px;">
                            <span>${matchLabel}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button type="button" class="chk-chip" onclick="openAttachChecklistModal(${ticketId})" style="padding: 2px 8px; font-size: 11px; cursor: pointer; border-radius: 4px; border: 1px solid #99f6e4; background: #ffffff; color: #0f766e; font-weight: 500;">
                                Browse all checklists
                            </button>
                            <button type="button" onclick="dismissSopSuggestion(${ticketId})" style="background: none; border: none; color: #0f766e; font-size: 12px; cursor: pointer; padding: 2px 4px; opacity: 0.8;" title="Dismiss suggestions">
                                ✕ Dismiss
                            </button>
                        </div>
                    </div>
                    <!-- Second row and onward: List of suggested SOPs with right-aligned confidence pill & attach button -->
                    <div style="display: flex; flex-direction: column; width: 100%;">
                        ${itemsHtml}
                    </div>
                </div>
            `;
        } else {
            hostDiv.innerHTML = `
                <div style="color: var(--text-muted, #64748b); font-size: 13px;">
                    <strong>${chkT('checklists.panel.title', 'Checklist')}:</strong> ${chkT('checklists.panel.none_attached', 'None attached to this ticket.')} <span style="font-style: italic; opacity: 0.85;">(No suggestions found)</span>
                </div>
                <button type="button" class="chk-chip" onclick="openAttachChecklistModal(${ticketId})">
                    ${chkT('checklists.panel.attach', 'Attach')}
                </button>
            `;
        }
    } catch (e) {
        console.debug('SOP suggestion fetch skipped:', e);
    }
}

/** Hides the suggestions for this ticket for the rest of the page visit and puts back the plain "none attached" box. */
function dismissSopSuggestion(ticketId) {
    window.dismissedSuggestions[ticketId] = true;
    // Dismissed: back to no strip at all, like any ticket with nothing attached.
    const strip = document.getElementById('ticketChecklistContainer');
    if (strip) strip.hidden = true;
    const hostDiv = document.getElementById(`sopEmptyStateHost_${ticketId}`);
    if (hostDiv) {
        hostDiv.style.background = 'var(--surface, #ffffff)';
        hostDiv.style.border = '1px dashed var(--border, #cbd5e1)';
        hostDiv.style.padding = '8px 14px';
        hostDiv.innerHTML = `
            <div style="color: var(--text-muted, #64748b); font-size: 13px;">
                <strong>${chkT('checklists.panel.title', 'Checklist')}:</strong> ${chkT('checklists.panel.none_attached', 'None attached to this ticket.')}
            </div>
            <button type="button" class="chk-chip" onclick="openAttachChecklistModal(${ticketId})">
                ${chkT('checklists.panel.attach', 'Attach')}
            </button>
        `;
    }
}
