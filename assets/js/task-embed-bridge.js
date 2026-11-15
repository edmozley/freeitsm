/**
 * The task editor's side of a ticket workspace's Work tab (PR #212, by @Dr4inder).
 *
 * Loaded only by tasks/embedded.php, inside the frame that
 * assets/js/ticket-workspace.js opens. The page is the real Tasks detail panel
 * saving through the real Tasks endpoints; this file adds nothing that writes. It
 * tells the ticket around it what is going on, by postMessage:
 *
 *   ready    the editor is drawn            resize   its height changed
 *   dirty    something is / is not unsaved  changed  a save succeeded
 *   error    the task can no longer be      closed   the editor's own Close
 *            edited from this ticket
 *
 * and answers the one question the ticket asks - "may I close you?"
 * (freeitsm:task-embed:leave) - after flushing the description's pending save,
 * waiting for writes in flight, and asking to Discard anything unsaved.
 *
 * Every message carries {ticketId, taskId}, goes to this origin only, and the
 * ticket checks it came from this frame. Messages in are checked the same way.
 *
 * TRAP: a new field in the detail panel that saves through saveField() is
 * covered automatically, provided it carries data-task-field="<payload key>" so
 * saved() can move its baseline. A compound control with its own endpoint must
 * call TaskEmbedBridge.saved() itself (see moveToCompanyFromPanel), or a value
 * it saved still looks unsaved and the analyst is asked to discard it.
 *
 * @wiki Tasks-on-Tickets-Developer-Guide
 */
window.TaskEmbedBridge = (() => {
    'use strict';
    const context = window.TASK_EMBED_CONTEXT;   // {taskId, ticketId}, from tasks/index.php

    const values = new WeakMap();   // input -> its saved value: the baseline "unsaved" is measured against
    const touched = new Set();      // inputs edited since they were drawn
    const watched = new WeakSet();  // documents already listening for edits

    let pending = 0;                // writes in flight
    let failedWrite = false;        // the last write failed, so what is on screen is not what is stored
    let savedDescription = null;    // the description editor's content when last saved
    let savedEditor = null;         // ...and which TinyMCE instance that was for
    let observer = null;            // ResizeObserver on the panel
    let lastHeight = 0;
    let validating = false;         // a re-check of the task's link is in flight
    let unavailableState = false;   // the task can no longer be edited from this ticket
    let discarded = false;          // the analyst chose Discard; stay clean until they type again

    const text = key => t('tasks.embed.' + key);
    const value = node => node.type === 'checkbox' || node.type === 'radio' ? String(node.checked) : node.value;
    const post = (event, extra = {}) => parent.postMessage({type: 'freeitsm:task-embed', event, ...context, ...extra}, location.origin);

    /**
     * Note the current value of every input in a document as its baseline, and
     * listen for edits. Run again on each measure, so inputs drawn later (a
     * subtask, a form) get a baseline too. A form on a task lives in its own
     * frame (.tf-frame), so its document is watched as well.
     */
    function watch(doc) {
        doc.querySelectorAll('input,textarea,select').forEach(node => {
            if (!values.has(node)) values.set(node, value(node));
        });
        if (!watched.has(doc)) {
            watched.add(doc);
            const changed = event => {
                if (!event.target.matches?.('input,textarea,select')) return;
                discarded = false;
                touched.add(event.target);
                post('dirty', {dirty: dirty()});
            };
            doc.addEventListener('input', changed, true);
            doc.addEventListener('change', changed, true);
            doc.addEventListener('click', leaveInNewTab);
        }
    }

    /**
     * A link to another page opens in a new browser tab, never in this frame
     * (GH #360). Followed in place, a linked record (the task's ticket, an asset, a
     * project) loaded the whole of FreeITSM - header, menus, another copy of the
     * ticket - inside the ticket's Work tab, and its links did the same again.
     *
     * Left alone: a link already opening elsewhere (target=_blank), Ctrl/Shift/
     * middle clicks (the browser opens those separately anyway), a download, a link
     * to somewhere on this same page (#...), and a click the page itself handled -
     * this listens in the bubbling phase, so an onclick that calls preventDefault()
     * has already run. Anything that still navigates the frame is caught by the
     * ticket (frameLeftEditor in ticket-workspace.js).
     *
     * TRAP: keep this for every document the editor draws in, including a form's
     * own frame (watch() adds it), or that frame's links escape the same way.
     */
    function leaveInNewTab(event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('a[href]');
        if (!link || link.hasAttribute('download') || (link.getAttribute('target') || '').toLowerCase() === '_blank') return;
        const raw = link.getAttribute('href').trim();
        if (raw === '' || raw.startsWith('#') || /^javascript:/i.test(raw)) return;
        const here = (link.ownerDocument.defaultView || window).location;
        let url;
        try { url = new URL(link.href, here.href); } catch (_) { return; }
        if (!/^https?:$/.test(url.protocol)) return;   // mailto:, tel: - the browser hands those to another app
        if (url.origin === here.origin && url.pathname === here.pathname && url.search === here.search) return;
        event.preventDefault();
        window.open(url.href, '_blank', 'noopener');
    }

    /** Is anything unsaved? An edited input that differs from its baseline, a failed write, or an edited description. */
    function dirty() {
        if (discarded) return false;
        if (failedWrite) return true;
        const documents = [document];
        for (const frame of document.querySelectorAll('.tf-frame')) {
            try { if (frame.contentDocument) documents.push(frame.contentDocument); }
            catch (_) { return true; }   // a form frame we cannot read: assume the worst
        }
        for (const node of touched) {
            // A submitted form replaces its document. A control from the old one
            // must not keep an answer that WAS submitted looking unsaved.
            if (!node.isConnected || !documents.includes(node.ownerDocument)) touched.delete(node);
            else if (values.get(node) !== value(node)) return true;
        }
        // The description saves itself a second after typing stops. It counts
        // only against the baseline of THIS editor - a new editor (another task)
        // starts clean.
        return typeof tinyEditor !== 'undefined' && tinyEditor && tinyEditor === savedEditor && savedDescription !== null
            && tinyEditor.getContent() !== savedDescription;
    }

    /** Tell the ticket the editor's height, so the frame grows and shrinks with it. */
    function measure() {
        const panel = document.getElementById('detailPanel');
        if (!panel) return;
        watch(document);
        document.querySelectorAll('.tf-frame').forEach(frame => {
            try { if (frame.contentDocument) watch(frame.contentDocument); } catch (_) { /* not ours to read */ }
        });
        // TRAP: measure the panel, not the page. The page's body is as tall as the
        // frame, so it can report growing but never shrinking. If this ever stops
        // working the frame keeps its last height and scrolls - usable, not broken.
        const height = Math.ceil(panel.getBoundingClientRect().height + 2);
        if (height !== lastHeight && height > 0) {
            lastHeight = height;
            post('resize', {height});
        }
    }

    /**
     * The detail panel has been drawn (openDetailPanel, tasks.js) - for the linked
     * task or for a subtask opened from it. Adds Open separately to the panel's
     * header, pointing at whichever task is showing, and starts measuring.
     */
    function ready() {
        unavailableState = false;
        watch(document);
        const actions = document.querySelector('.detail-panel-actions');
        if (actions) {
            let external = actions.querySelector('.task-embed-open');
            if (!external) {
                external = document.createElement('a');
                external.className = 'btn-icon task-embed-open';
                external.textContent = text('open_separately');
                external.target = '_blank';
                external.rel = 'noopener';
                actions.prepend(external);
            }
            external.href = 'index.php?task=' + (typeof selectedTaskId !== 'undefined' && selectedTaskId ? selectedTaskId : context.taskId);
        }
        if (observer) observer.disconnect();
        const panel = document.getElementById('detailPanel');
        if (panel && typeof ResizeObserver !== 'undefined') {
            observer = new ResizeObserver(measure);
            observer.observe(panel);
        }
        requestAnimationFrame(measure);
        // hasActions: the header has the Close and Open separately the ticket
        // would otherwise draw above the frame.
        post('ready', {hasActions: !!actions});
    }

    /**
     * A save succeeded (postTaskChange, tasks.js): the saved values become the new
     * baselines, matched by each input's data-task-field to the payload's keys.
     */
    function saved(payload) {
        document.querySelectorAll('[data-task-field]').forEach(node => {
            const key = node.dataset.taskField;
            if (!Object.prototype.hasOwnProperty.call(payload, key)) return;
            // The work window is stored as a datetime but shown in a date or
            // datetime-local input; compare like with like.
            const stored = ['work_start_at', 'work_end_at'].includes(key)
                ? workValue(payload[key], node.type === 'date') : payload[key];
            values.set(node, node.type === 'checkbox' || node.type === 'radio' ? String(!!Number(stored)) : String(stored ?? ''));
        });
        if (Object.prototype.hasOwnProperty.call(payload, 'description')) savedDescription = payload.description;
        post('dirty', {dirty: dirty()});
    }

    /**
     * May the editor be closed? Saves the description now if its save is still
     * waiting, waits up to ten seconds for writes in flight, then asks to Discard
     * anything still unsaved. "No" while a write is stuck or a form is mid-submit.
     */
    async function canLeave() {
        document.activeElement?.blur();   // commits a field still being typed in (its change event)
        // The description's save is debounced. Run it through its usual writer
        // now: removing the frame would otherwise cancel the timer and lose it.
        if (typeof descSaveTimer !== 'undefined' && descSaveTimer && typeof tinyEditor !== 'undefined' && tinyEditor) {
            clearTimeout(descSaveTimer);
            descSaveTimer = null;
            await saveField('description', tinyEditor.getContent());
        }
        const deadline = Date.now() + 10000;
        while (pending && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 50));
        if (pending) return false;
        for (const frame of document.querySelectorAll('.tf-frame')) {
            try {
                if (frame.contentDocument?.querySelector('button[type="submit"]:disabled') && dirty()) return false;
            } catch (_) {
                return false;
            }
        }
        if (!dirty()) return true;
        const accepted = typeof showConfirm === 'function'
            ? await showConfirm({title: text('leave_title'), message: text('discard'), okLabel: text('discard_button'), okClass: 'danger'})
            : window.confirm(text('discard'));
        if (accepted) discarded = true;
        return accepted;
    }

    /** The task can no longer be edited from this ticket: hide the editor and say so. */
    function unavailable() {
        unavailableState = true;
        const panel = document.getElementById('detailPanel');
        if (panel) { panel.inert = true; panel.hidden = true; }
        let error = document.getElementById('taskEmbedError');
        if (!error) {
            error = document.createElement('p');
            error.id = 'taskEmbedError';
            error.className = 'task-embed-error';
            error.setAttribute('role', 'alert');
            document.body.append(error);
        }
        error.textContent = text(context.ticketId ? 'unavailable' : 'unavailable_tasks');   // no ticket to blame from Tasks' own views (GH #219)
        post('error');
    }

    /**
     * A write succeeded. Tell the ticket (its task pills re-load), then re-read the
     * linked task with the embed check (api/tasks/get.php), so an unlink or a lost
     * permission is noticed on the next save rather than never.
     */
    async function changed() {
        post('changed');
        if (validating || unavailableState) return;
        validating = true;
        try {
            // From a ticket: the link to it is re-checked too. From Tasks' own views
            // (no ticket, GH #219): the task alone, by Tasks' own rules.
            const url = window.API_BASE + 'get.php?id=' + context.taskId
                + (context.ticketId ? '&embed_root_id=' + context.taskId + '&ticket_id=' + context.ticketId : '');
            const response = await fetch(url);
            const data = await response.json();
            if (!response.ok || !data.success) unavailable();
        } catch (_) {
            unavailable();
        } finally {
            validating = false;
        }
    }

    // TRAP: THIS WRAPS window.fetch, BUT ONLY IN THIS FRAME'S OWN DOCUMENT.
    //
    // Counting writes in flight, and noticing a failed one, without changing any
    // of the Tasks module's writers. It sits on top of the CSRF wrapper
    // (csrf.js), so the token is still added; payloads, responses and errors pass
    // through unchanged. A response that cannot be read as JSON, or says
    // success: false, counts as failed - what is on screen is then not what is
    // stored, so leaving asks first.
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
        const url = new URL(input instanceof Request ? input.url : String(input), location.href);
        const method = (init?.method || (typeof input !== 'string' ? input.method : '') || 'GET').toUpperCase();
        const write = url.origin === location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(method);
        if (!write) return nativeFetch(input, init);
        pending++;
        try {
            const response = await nativeFetch(input, init);
            let result = null;
            try { result = await response.clone().json(); } catch (_) { /* unreadable: treated as failed below */ }
            if (response.ok && result?.success) {
                failedWrite = false;
                void changed();
            } else {
                failedWrite = true;
            }
            return response;
        } catch (error) {
            failedWrite = true;
            throw error;
        } finally {
            pending--;
            post('dirty', {dirty: dirty()});
        }
    };

    // Messages from the ticket: only from the parent window, on this origin.
    window.addEventListener('message', async event => {
        const message = event.data;
        if (event.origin !== location.origin || event.source !== parent || !message) return;
        if (message.type === 'freeitsm:task-embed:context-lost') { unavailable(); return; }
        if (message.type !== 'freeitsm:task-embed:leave' || typeof message.requestId !== 'string') return;
        const can = await canLeave();
        parent.postMessage({type: 'freeitsm:task-embed:leave-result', requestId: message.requestId, ...context, canLeave: can}, location.origin);
    });

    // The frame reloaded or the tab closed with something unsaved.
    window.addEventListener('beforeunload', event => {
        if (pending || dirty()) { event.preventDefault(); event.returnValue = ''; }
    });

    // Also measure on a timer: an older browser without ResizeObserver, and a
    // form whose late-loading content resizes nothing the observer is watching.
    const timer = setInterval(measure, 800);
    window.addEventListener('pagehide', () => { observer?.disconnect(); clearInterval(timer); });

    return {
        ready, changed, saved, canLeave, unavailable,
        closed: () => post('closed'),
        /** The description editor is ready: its content is the baseline, for this editor only. */
        editorReady(editor) {
            savedEditor = editor;
            savedDescription = editor.getContent();
            editor.on('input change', () => { discarded = false; });
        },
    };
})();
