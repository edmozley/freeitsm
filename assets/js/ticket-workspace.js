/**
 * The ticket workspace (PR #211 and #212, by @Dr4inder): a ticket laid out for
 * working - its properties in a column, and its conversation, work, links, time
 * and history on tabs beside them.
 *
 * WHO DECIDES WHAT
 * ----------------
 * The page hands over window.TICKET_WORKSPACE = {mode, personal, ready, can_write}
 * (includes/ticket_workspace.php). enabled() below combines that with the ticket's
 * own is_workspace flag; it mirrors ticketWorkspaceEnabled() in PHP.
 *
 * HOW IT DRAWS - ONE RENDERER
 * ---------------------------
 * displayEmail() in inbox.js defines every reading-pane control ONCE, then
 * either joins the pieces into the classic pane or hands them to render() here
 * to place in the workspace. Nothing is drawn twice and nothing is moved after it
 * is drawn, so a control added to displayEmail() appears in both layouts and its
 * handler works in both. The ids (statusSelect, threadContainer, ...) are the
 * same in both, which is why inbox.js's loaders need no workspace knowledge
 * beyond reporting their state with setSectionState().
 *
 * NOTHING UNSAVED IS LOST
 * -----------------------
 * Changing ticket, changing layout, or a reload of the open ticket (after it is
 * dragged to a folder, say) could otherwise wipe a half-typed field or land in
 * the middle of a save. So:
 *   - canLeave() is asked before the ticket on screen is replaced, and offers
 *     Discard when something is unsaved (inbox.js calls it from selectEmail,
 *     loadTicketById, the selection handlers and Make workspace);
 *   - holdRender() parks a refresh of the SAME ticket while it is busy, and
 *     flushRender() draws it once the work is done;
 *   - a linked task open in the Work tab is asked through its bridge
 *     (assets/js/task-embed-bridge.js), which has its own unsaved state.
 *
 * @wiki Ticket-Workspace-Developer-Guide
 */
window.TicketWorkspace = (() => {
    'use strict';

    // The tabs, in order. 'history' is filled when first opened, not up front.
    const sections = ['conversation', 'work', 'links', 'time', 'history'];

    let root = null;               // the reading pane element, once prepared
    let ticketId = 0;              // the ticket it is showing
    let active = 'conversation';   // the selected tab, kept across a re-render of the same ticket
    const boundRoots = new WeakSet();   // panes whose delegated listeners are already attached
    let changingMode = false;      // a personal on/off switch is being saved
    let deferredRender = null;     // {email, recordings} held back by holdRender()

    // Custom-field inputs the analyst has focused, with the value they had then.
    // Those fields save on change, so a typed-but-not-yet-changed value is only
    // visible here (see hasPropertyDrafts).
    const propertyDrafts = new Map();

    // A linked task open in the Work tab (PR #212).
    let taskFrame = null;          // its <iframe>
    let taskContext = null;        // {ticketId, taskId, unavailable?}
    let leaveRequest = null;       // a pending "may I close you?" question to the frame
    let frameGeneration = 0;       // bumped when the frame goes, so a late answer is ignored
    let leaveRequestSeq = 0;       // ids for leave requests (see taskCanLeave)

    const text = key => t('tickets.workspace.' + key);
    const element = (tag, className, label) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (label !== undefined) node.textContent = label;
        return node;
    };

    /** Has a focused custom field been edited and not yet saved? Forgets fields that are gone. */
    function hasPropertyDrafts() {
        let dirty = false;
        for (const [field, value] of propertyDrafts) {
            if (!field.isConnected) propertyDrafts.delete(field);
            else if (field.value !== value) dirty = true;
        }
        return dirty;
    }

    /**
     * Is anything on this ticket unsaved or still saving? A typed custom field, a
     * write in flight (ticketPendingWrites, inbox.js), or an open reply / note /
     * channel draft (ticketHasUnsavedInput, inbox.js). The typeof guards let this
     * file load on a page without inbox.js, as the tests do.
     */
    function hasUnsavedWork() {
        return hasPropertyDrafts()
            || (typeof ticketPendingWrites !== 'undefined' && ticketPendingWrites > 0)
            || (typeof ticketHasUnsavedInput === 'function' && ticketHasUnsavedInput());
    }

    // TRAP: THIS WRAPS window.fetch FOR THE WHOLE TICKETS PAGE.
    //
    // A refresh must not re-draw the workspace while a save is on its way: the
    // save's own success handler would then update controls that belong to the
    // new render. Most of the reading pane's writers (assignStatus, saveTicket-
    // CustomField, the link pickers...) are plain fetch() calls that report
    // nothing, so instead of editing every one of them, this counts every
    // same-origin write to /api/ while a workspace is on screen, and releases the
    // count only once the response BODY has been read - when the writer's own
    // handler has run.
    //
    // - It is inert unless a workspace is showing: the classic pane is untouched.
    // - It is installed on top of the CSRF wrapper (csrf.js), so the token is
    //   still added; payloads and responses pass through unchanged.
    // - set_user_preference.php is not counted, because switching the layout
    //   itself must not wait for itself.
    // - saveNote, sendEmail and the channel senders also count themselves in
    //   inbox.js, around their WHOLE handler, so the thread they re-load after
    //   saving is not overtaken either.
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
        const url = new URL(input instanceof Request ? input.url : String(input), location.href);
        const method = (init?.method || (typeof input !== 'string' ? input.method : '') || 'GET').toUpperCase();
        const track = root?.classList.contains('ticket-workspace') && url.origin === location.origin
            && url.pathname.includes('/api/') && !url.pathname.endsWith('/system/set_user_preference.php')
            && !['GET', 'HEAD', 'OPTIONS'].includes(method);
        if (!track) return nativeFetch(input, init);

        ticketPendingWrites++;
        // setTimeout: let the caller's own .then() run first.
        const finish = () => setTimeout(() => { ticketPendingWrites--; flushRender(); }, 0);
        try {
            const response = await nativeFetch(input, init);
            response.clone().text().then(finish, finish);
            return response;
        } catch (error) {
            finish();
            throw error;
        }
    };

    // =====================================================================
    // Drawing
    // =====================================================================

    /** Is this ticket shown as a workspace for this analyst? Mirrors ticketWorkspaceEnabled() in PHP. */
    function enabled(email) {
        const config = window.TICKET_WORKSPACE || {};
        return config.personal !== false && (config.mode === 'all'
            || (config.mode === 'selected' && Number(email.is_workspace) === 1));
    }

    /** One of the properties column's groups (Classification, Responsibility, Other details). */
    function fieldGroup(name, html) {
        return '<fieldset class="tw-field-group"><legend>' + escapeHtml(text(name)) + '</legend>' + html + '</fieldset>';
    }

    /**
     * The workspace's markup, from the pieces displayEmail() built:
     * {header, properties, conversation, work, links, time}. History is an empty
     * holder that activate() fills on first opening.
     */
    function render(parts) {
        // The Time tab holds the SLA clocks and the time entries; with both switched
        // off for the install (GH #217) there is nothing to show, so no tab.
        const on = window.TICKETS_MODULE_ON || {};
        const timeOff = on.sla === false && on['time-tracking'] === false;
        const tabs = sections.map(section => `<button type="button" class="tw-tab" id="tw-tab-${section}"
            data-section="${section}" role="tab" aria-controls="tw-panel-${section}"${section === 'time' && timeOff ? ' hidden' : ''}
            aria-selected="false" tabindex="-1">${escapeHtml(text(section))}</button>`).join('');
        const panels = sections.map(section => `<section class="tw-panel" id="tw-panel-${section}"
            data-section="${section}" role="tabpanel" aria-labelledby="tw-tab-${section}" hidden>
            ${section === 'history' ? '<div class="tw-history-content" id="twAuditBody"></div>' : parts[section] || ''}</section>`).join('');
        return `<div class="tw-header">${parts.header}<button type="button" class="tw-sla-summary"
                id="twSlaSummary" onclick="TicketWorkspace.activate('time')" hidden></button></div>
            <div class="tw-grid"><aside class="tw-properties" aria-label="${escapeHtml(text('properties'))}">${parts.properties}</aside>
            <div class="tw-main"><div class="tw-tabs" role="tablist" aria-label="${escapeHtml(text('sections'))}">${tabs}</div>${panels}</div></div>`;
    }

    /**
     * Called by displayEmail() after EVERY render, in either layout. Records which
     * ticket is on screen, switches the layout classes, and wires the tabs. A
     * different ticket (or the classic layout) closes any open task editor and
     * goes back to the Conversation tab; the same ticket keeps its tab.
     *
     * @param {HTMLElement} pane  the reading pane
     * @param {{ticketId: number, enabled: boolean}} context
     */
    function prepare(pane, context) {
        const sameTicket = root === pane && Number(context.ticketId) === ticketId;
        if (!sameTicket || !context.enabled) disposeTask();
        if (!sameTicket) active = 'conversation';
        root = pane;
        ticketId = Number(context.ticketId);
        pane.classList.toggle('ticket-workspace', context.enabled);
        document.body.classList.toggle('ticket-workspace-mode', context.enabled);

        // Delegated, so attached once per pane rather than once per render.
        if (!boundRoots.has(pane)) {
            boundRoots.add(pane);
            pane.addEventListener('focusin', event => {
                const field = event.target;
                if (field.matches('#ticketCustomFields input, #ticketCustomFields textarea') && !propertyDrafts.has(field)) {
                    propertyDrafts.set(field, field.value);
                }
            });
            // A required field failing validation on a hidden tab: show that tab.
            pane.addEventListener('invalid', event => {
                const panel = event.target.closest('.tw-panel');
                if (panel) activate(panel.dataset.section);
            }, true);
        }
        if (!context.enabled) return;

        // The tabs are new elements on each render, so these are not duplicates.
        // Switching tab only hides panels - their inputs stay live and keep what
        // was typed in them.
        pane.querySelectorAll('.tw-tab').forEach(tab => {
            tab.addEventListener('click', () => activate(tab.dataset.section));
            tab.addEventListener('keydown', event => {
                // Arrow keys, Home and End move between tabs (WAI-ARIA tabs pattern).
                const index = sections.indexOf(tab.dataset.section);
                const next = event.key === 'ArrowRight' ? (index + 1) % sections.length
                    : event.key === 'ArrowLeft' ? (index + sections.length - 1) % sections.length
                    : event.key === 'Home' ? 0 : event.key === 'End' ? sections.length - 1 : -1;
                if (next >= 0) { event.preventDefault(); activate(sections[next], true); }
            });
        });
        watchWorkEmpty(pane);
        // ?section=work and the like open straight on a tab.
        const requested = new URLSearchParams(location.search).get('section');
        activate(sections.includes(requested) ? requested : active);
    }

    /**
     * The Work tab's "nothing here yet" note, and how to add something. Shown
     * while the ticket has no checklist (its container is hidden when there is
     * none - checklists/ticket_view.js), no linked task and no task open. An
     * observer keeps it right as the loaders fill the tab in, so none of them has
     * to know the note exists.
     */
    let workObserver = null;
    function watchWorkEmpty(pane) {
        workObserver?.disconnect();
        const panel = pane.querySelector('#tw-panel-work');
        if (!panel) return;
        const note = element('p', 'tw-empty', text('work_empty'));
        panel.append(note);
        const update = () => {
            const checklists = panel.querySelector('#ticketChecklistContainer');
            const hasChecklist = checklists && !checklists.hidden && checklists.childElementCount > 0;
            const forms = panel.querySelector('#twTicketForms');   // GH #272: forms on the ticket (ticket-forms.js)
            const hasForms = !!forms && !forms.hidden && forms.childElementCount > 0;
            const hasTasks = panel.querySelector('#twTaskList')?.childElementCount > 0
                || panel.querySelector('#twTaskArea')?.childElementCount > 0;
            // Only when it changes: setting `hidden` is itself a mutation this
            // observer sees, so setting it every time would never stop.
            const hide = !!(hasChecklist || hasForms || hasTasks);
            if (note.hidden !== hide) note.hidden = hide;
        };
        update();
        workObserver = new MutationObserver(update);
        workObserver.observe(panel, {childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']});
    }

    /** Show one tab. History loads the audit trail the first time it is opened. */
    function activate(section, focus = false) {
        if (!root || !sections.includes(section)) return;
        active = section;
        root.querySelectorAll('.tw-tab').forEach(tab => {
            const selected = tab.dataset.section === section;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) tab.focus();
        });
        root.querySelectorAll('.tw-panel').forEach(panel => { panel.hidden = panel.dataset.section !== section; });
        if (section === 'history') {
            const body = root.querySelector('#twAuditBody');
            if (body && body.getAttribute('aria-busy') !== 'true') {
                body.setAttribute('aria-busy', 'true');
                setSectionState(ticketId, 'history', 'loading');
                // true = draw the audit table inline here, not in a modal (inbox.js).
                showAuditHistory(true).finally(() => body.setAttribute('aria-busy', 'false'));
            }
        }
        root.dispatchEvent(new CustomEvent('ticket-workspace-section', {detail: {ticketId, section}}));
    }

    /**
     * The SLA summary in the header, using the reading pane's own SLA badge.
     * Called by loadSlaState() in inbox.js; sla === null means the read failed.
     */
    function updateSla(id, sla) {
        if (!root || Number(id) !== ticketId) return;
        const summary = root.querySelector('#twSlaSummary');
        if (!summary) return;
        summary.hidden = sla !== null && !sla.enabled;
        if (sla === null) summary.textContent = text('sla_unavailable');
        else summary.innerHTML = renderInboxSlaIndicator(sla);
        if (sla !== null && !summary.textContent.trim()) summary.hidden = true;
    }

    /**
     * Closing was blocked by mandatory fields (confirmCloseWithMandatoryFields in
     * inbox.js): take the analyst to the first missing one, on whichever tab or
     * group it is.
     *
     * @param {Array<{key: string}>} missing  'priority', 'cf:<key>' for a custom field, 'work_start', ...
     */
    function revealMissing(id, missing) {
        if (!root?.classList.contains('ticket-workspace') || Number(id) !== ticketId) return;
        const fields = {
            priority: 'prioritySelect', ticket_type: 'ticketTypeSelect', department: 'departmentSelect',
            category: 'categorySelect', closure_category: 'closureCategorySelect', resolution_code: 'resolutionCodeSelect',
            team: 'teamSelect', owner: 'ownerSelect', origin: 'originSelect',
            first_time_fix: 'firstTimeFixSelect', it_training: 'itTrainingSelect',
        };
        for (const {key} of missing) {
            const target = key.startsWith('cf:')
                ? [...root.querySelectorAll('[data-tf-key]')].find(node => node.dataset.tfKey === key.slice(3))
                : key === 'work_start' ? root.querySelector('[onclick="openScheduleModal()"]')
                : fields[key] ? root.querySelector('#' + fields[key]) : null;
            if (!target) continue;
            target.closest('.ticket-properties-container')?.classList.add('expanded');
            const panel = target.closest('.tw-panel');
            if (panel) activate(panel.dataset.section);
            target.scrollIntoView({block: 'nearest'});
            target.focus({preventScroll: true});
            return;
        }
    }

    /**
     * A notice at the top of a tab: 'loading', 'error' or 'ready' (removes it).
     * Each loader reports under its own `source`, so the thread failing does not
     * hide the fact that the notes loaded, and one success cannot clear another's
     * error. Ignored unless the ticket is still the one on screen.
     */
    function setSectionState(id, section, state, message, source = section) {
        if (!root || Number(id) !== ticketId) return;
        const panel = root.querySelector('#tw-panel-' + section);
        if (!panel) return;
        let notice = [...panel.querySelectorAll(':scope > .tw-state')].find(node => node.dataset.source === source);
        if (state === 'ready') { notice?.remove(); return; }
        if (!notice) {
            notice = element('div', 'tw-state');
            notice.dataset.source = source;
            panel.prepend(notice);
        }
        notice.setAttribute('role', state === 'error' ? 'alert' : 'status');
        notice.textContent = message || text(state === 'error' ? 'unavailable' : 'loading');
        notice.dataset.state = state;
    }

    // =====================================================================
    // Leaving a ticket
    // =====================================================================

    /**
     * May the ticket on screen be replaced? False while a save is in flight (wait
     * for it); otherwise asks to Discard anything unsaved, and on yes puts the
     * custom fields back and closes the drafts so nothing half-typed survives.
     */
    async function canLeave() {
        if (taskFrame && !await taskCanLeave()) return false;
        if (typeof ticketPendingWrites !== 'undefined' && ticketPendingWrites > 0) return false;
        const dirty = hasPropertyDrafts() || (typeof ticketHasUnsavedInput === 'function' && ticketHasUnsavedInput());
        const accepted = !dirty || (typeof showConfirm === 'function'
            ? await showConfirm({title: text('layout'), message: text('discard'), okLabel: text('discard_button'), okClass: 'danger'})
            : window.confirm(text('discard')));
        if (!accepted) return false;
        for (const [field, value] of propertyDrafts) field.value = value;
        propertyDrafts.clear();
        if (dirty && typeof discardTicketDraft === 'function') discardTicketDraft();
        return true;
    }

    /**
     * Hold back a re-render of the ticket on screen while something on it is busy
     * (loadTicketById() after it is dragged to a folder, escalated to a tracker,
     * or made a workspace). flushRender() draws it
     * when the work is done. A DIFFERENT ticket is never held - that is a
     * navigation, and canLeave() has already been asked.
     */
    function holdRender(email, recordings) {
        const busy = taskFrame || hasUnsavedWork();
        if (!root?.classList.contains('ticket-workspace') || !busy || Number(email.ticket_id) !== ticketId) return false;
        deferredRender = {email, recordings};
        return true;
    }

    /** Draw a held-back render, once nothing is busy any more. */
    function flushRender() {
        if (taskFrame || hasUnsavedWork()) return;
        const render = deferredRender;
        deferredRender = null;
        if (render && Number(render.email.ticket_id) === ticketId) displayEmail(render.email, render.recordings);
    }

    /** Closing the tab or reloading the page with something unsaved: the browser's own warning. */
    window.addEventListener('beforeunload', event => {
        if (root?.classList.contains('ticket-workspace') && hasUnsavedWork()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // =====================================================================
    // The analyst's own switch (Use the workspace)
    // =====================================================================

    /**
     * The view menu's Use the workspace toggle. Asks canLeave() first, saves the
     * preference, then re-draws the ticket on screen in the other layout. A failed
     * save puts the toggle back.
     */
    async function requestPersonal(value) {
        const toggle = document.getElementById('ticketWorkspacePreference');
        const config = window.TICKET_WORKSPACE;
        if (changingMode || !config || config.mode === 'off') return false;
        changingMode = true;
        if (toggle) toggle.disabled = true;
        try {
            if (value === config.personal) return true;
            if (!await canLeave()) return false;
            const response = await fetch(sharedApiBase() + 'system/set_user_preference.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({key: 'ticket_workspace', value: value ? '1' : '0'})
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(text('save_failed'));
            disposeTask();
            deferredRender = null;
            config.personal = value;
            if (typeof currentEmail !== 'undefined' && currentEmail) displayEmail(currentEmail, currentRecordings);
            return true;
        } catch (error) {
            if (typeof showToast === 'function') showToast(text('save_failed'), 'error');
            return false;
        } finally {
            changingMode = false;
            if (toggle) { toggle.checked = config.personal; toggle.disabled = false; }
        }
    }

    // =====================================================================
    // A linked task, opened in the Work tab (PR #212)
    // =====================================================================
    //
    // The task's OWN editor - tasks/embedded.php, which is tasks/index.php without
    // the board - in a same-origin frame. The two sides talk only by postMessage,
    // and each side checks the other on every message: the origin, that it came
    // from the expected window, and that it names the same ticket and task. See
    // assets/js/task-embed-bridge.js for the frame's side.

    /** Remove the task frame, and answer "no" to any question still waiting on it. */
    function disposeTask() {
        frameGeneration++;
        if (leaveRequest) {
            clearTimeout(leaveRequest.timer);
            leaveRequest.resolve(false);
            leaveRequest = null;
        }
        taskFrame?.remove();
        taskFrame = null;
        taskContext = null;
    }

    /**
     * Ask the task editor whether it can be closed. It flushes a pending
     * description save, waits for writes in flight, and asks to Discard anything
     * unsaved - so the answer can take a while, and only one question is asked at a
     * time. No answer within 45 seconds counts as "no": keeping the editor is the
     * safe way to be wrong.
     */
    function taskCanLeave() {
        if (!taskFrame || taskContext?.unavailable) return Promise.resolve(true);
        if (leaveRequest) return leaveRequest.promise;
        // TRAP: not crypto.randomUUID(). Browsers only offer it on HTTPS or
        // localhost, and FreeITSM is often reached over plain HTTP inside a
        // network - there it would throw, and no ticket could be left while a task
        // was open. The id only has to tell this page's own questions apart.
        const requestId = 'leave-' + (++leaveRequestSeq);
        let resolve;
        const promise = new Promise(done => { resolve = done; });
        const timer = setTimeout(() => {
            if (leaveRequest?.requestId === requestId) { leaveRequest = null; resolve(false); }
        }, 45000);
        leaveRequest = {requestId, resolve, promise, timer, generation: frameGeneration};
        taskFrame.contentWindow.postMessage({type: 'freeitsm:task-embed:leave', requestId}, location.origin);
        return promise;
    }

    /**
     * Open a linked task in the Work tab. Called from the task pill's click in
     * renderTicketTasks() (inbox.js); Ctrl/Shift-click still opens it separately.
     * The same task again just shows the tab; another task asks the open one first.
     */
    async function openTask(id, taskId) {
        if (!root || Number(id) !== ticketId || !Number.isInteger(Number(taskId)) || Number(taskId) <= 0) return false;
        if (taskContext?.taskId === Number(taskId)) { activate('work'); return true; }
        if (!await taskCanLeave() || Number(id) !== ticketId) return false;
        const area = root.querySelector('#twTaskArea');
        if (!area) return false;
        disposeTask();

        taskContext = {ticketId, taskId: Number(taskId)};
        taskFrame = element('iframe', 'tw-task-frame');
        taskFrame.title = text('task');
        taskFrame.src = '../tasks/embedded.php?task=' + taskContext.taskId + '&ticket_id=' + ticketId;
        // A frame that loads without the bridge is an error page (embedded.php
        // said 404 or 503). Nothing in it can be unsaved, so it can be left.
        taskFrame.addEventListener('load', event => {
            if (event.currentTarget !== taskFrame) return;
            try {
                if (!taskFrame.contentWindow.TaskEmbedBridge) {
                    taskContext.unavailable = true;
                    setSectionState(ticketId, 'work', 'error', null, 'task-editor');
                }
            } catch (_) {
                taskContext.unavailable = true;
            }
        });

        // Open separately and Close, above the frame. Hidden once the editor says
        // it has put its own in its header ('ready'); kept as the fallback if not.
        const controls = element('div', 'tw-task-controls');
        const external = element('a', 'btn btn-secondary', text('open_task'));
        external.href = '../tasks/index.php?task=' + taskContext.taskId;
        external.target = '_blank';
        external.rel = 'noopener';
        const close = element('button', 'btn btn-secondary', text('close_task'));
        close.type = 'button';
        close.addEventListener('click', async () => {
            if (await taskCanLeave()) { disposeTask(); area.replaceChildren(); flushRender(); }
        });
        controls.append(external, close);
        area.replaceChildren(controls, taskFrame);
        activate('work');
        return true;
    }

    /**
     * Messages from the task frame. Anything not from THIS frame, on this origin,
     * about this ticket and task, is ignored.
     *   leave-result  the answer to taskCanLeave()
     *   resize        the editor's height, so the frame never scrolls on its own
     *   changed       a task saved - reload the task pills (progress, done box)
     *   error         the editor lost the task (unlinked, or access removed)
     *   closed        the editor's own Close was pressed
     *   ready         the editor is drawn
     */
    window.addEventListener('message', event => {
        const message = event.data;
        if (!taskFrame || !taskContext || event.origin !== location.origin || event.source !== taskFrame.contentWindow
            || !message || message.ticketId !== taskContext.ticketId || message.taskId !== taskContext.taskId) return;

        if (message.type === 'freeitsm:task-embed:leave-result') {
            // An answer for a frame that has since been replaced is not an answer.
            if (leaveRequest?.requestId !== message.requestId || leaveRequest.generation !== frameGeneration) return;
            const request = leaveRequest;
            leaveRequest = null;
            clearTimeout(request.timer);
            request.resolve(message.canLeave === true);
            return;
        }
        if (message.type !== 'freeitsm:task-embed') return;

        if (message.event === 'resize' && Number.isFinite(message.height) && message.height > 0) {
            taskFrame.style.height = Math.min(50000, Math.max(300, message.height)) + 'px';
        } else if (message.event === 'changed') {
            if (typeof loadTicketTasks === 'function') loadTicketTasks(ticketId);
        } else if (message.event === 'error') {
            const controls = root.querySelector('.tw-task-controls');
            if (controls) controls.hidden = false;
            setSectionState(ticketId, 'work', 'error', null, 'task-editor');
        } else if (message.event === 'closed') {
            disposeTask();
            root.querySelector('#twTaskArea')?.replaceChildren();
            flushRender();
        } else if (message.event === 'ready') {
            const controls = root.querySelector('.tw-task-controls');
            if (controls) controls.hidden = message.hasActions === true;
            setSectionState(ticketId, 'work', 'ready', null, 'task-editor');
        }
    });

    /**
     * The ticket's task list has re-loaded (loadTicketTasks, inbox.js). If the open
     * task is no longer among them - unlinked by someone - the editor is told, and
     * shows that it can no longer be edited from here rather than carrying on.
     */
    function tasksLoaded(id, tasks) {
        if (taskFrame && Number(id) === ticketId && !tasks.some(task => Number(task.id) === taskContext.taskId)) {
            taskFrame.contentWindow.postMessage({type: 'freeitsm:task-embed:context-lost'}, location.origin);
            setSectionState(id, 'work', 'error', null, 'task-editor');
        }
    }

    return {
        enabled, render, fieldGroup, prepare, activate, updateSla, revealMissing, setSectionState,
        requestPersonal, canLeave, holdRender, flushRender, hasUnsavedWork,   // hasUnsavedWork: live updates (GH #267)
        openTask, releaseTask: disposeTask, tasksLoaded,
        /** A custom field saved: its saved value is the new baseline. */
        propertySaved(field) { propertyDrafts.set(field, field.value); },
        /** A section re-loaded without error. */
        refresh(id, section) { if (Number(id) === ticketId) setSectionState(id, section, 'ready'); },
        /** The reading pane is being emptied or given to something else (no ticket, a multi-selection summary). */
        dispose() {
            disposeTask();
            workObserver?.disconnect();
            root?.classList.remove('ticket-workspace');
            document.body.classList.remove('ticket-workspace-mode');
            deferredRender = null;
            root = null;
            ticketId = 0;
            active = 'conversation';
        },
        isActive() { return !!root?.classList.contains('ticket-workspace'); },
    };
})();
