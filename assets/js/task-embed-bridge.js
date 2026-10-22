/** Presentation/lifecycle bridge for the native task editor. No alternate task writes. */
window.TaskEmbedBridge = (() => {
    'use strict';
    const context = window.TASK_EMBED_CONTEXT;
    const values = new Map(), touched = new Set(), watched = new WeakSet();
    let pending = 0, failedWrite = false, savedDescription = null, observer = null, lastHeight = 0;
    let validating = false, unavailableState = false, discarded = false;
    const text = key => t('tasks.embed.' + key);
    const value = node => node.type === 'checkbox' || node.type === 'radio' ? String(node.checked) : node.value;
    const post = (event, extra = {}) => parent.postMessage({type:'freeitsm:task-embed', event, ...context, ...extra}, location.origin);

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
                post('dirty', {dirty:dirty()});
            };
            doc.addEventListener('input', changed, true);
            doc.addEventListener('change', changed, true);
        }
    }

    function dirty() {
        if (discarded) return false;
        if (failedWrite) return true;
        const documents = [document, ...[...document.querySelectorAll('.tf-frame')].map(frame => frame.contentDocument)];
        for (const node of touched) {
            // Submitted forms replace their document. Detached form controls
            // must not keep an already submitted answer marked as unsaved.
            if (node.isConnected && documents.includes(node.ownerDocument) && values.get(node) !== value(node)) return true;
        }
        return typeof tinyEditor !== 'undefined' && tinyEditor && savedDescription !== null
            && tinyEditor.getContent() !== savedDescription;
    }

    function measure() {
        const panel = document.getElementById('detailPanel');
        if (!panel) return;
        watch(document);
        document.querySelectorAll('.tf-frame').forEach(frame => {
            try { if (frame.contentDocument) watch(frame.contentDocument); } catch (_) { /* no cross-origin inspection */ }
        });
        // TRAP: the viewport-sized body cannot report shrink. Measure the native
        // content panel; leave document scrolling available if the bridge fails.
        const height = Math.ceil(panel.getBoundingClientRect().height + 2);
        if (height !== lastHeight && height > 0) { lastHeight = height; post('resize', {height}); }
    }

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
            observer = new ResizeObserver(measure); observer.observe(panel);
        }
        requestAnimationFrame(measure);
        post('ready', {hasActions: !!actions});
    }

    function saved(payload) {
        document.querySelectorAll('[data-task-field]').forEach(node => {
            const key = node.dataset.taskField;
            if (Object.prototype.hasOwnProperty.call(payload, key)) values.set(node, node.type === 'checkbox' || node.type === 'radio' ? String(!!Number(payload[key])) : String(payload[key] ?? ''));
        });
        if (Object.prototype.hasOwnProperty.call(payload, 'description')) savedDescription = payload.description;
        post('dirty', {dirty:dirty()});
    }

    async function canLeave() {
        document.activeElement?.blur();
        // A native description save is debounced. Flush it through its existing
        // writer before answering; destroying the frame must not cancel the timer.
        if (typeof descSaveTimer !== 'undefined' && descSaveTimer && typeof tinyEditor !== 'undefined' && tinyEditor) {
            clearTimeout(descSaveTimer); descSaveTimer = null;
            await saveField('description', tinyEditor.getContent());
        }
        const deadline = Date.now() + 10000;
        while (pending && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 50));
        if (pending) return false;
        for (const frame of document.querySelectorAll('.tf-frame')) {
            try {
                if (frame.contentDocument?.querySelector('button[type="submit"]:disabled') && dirty()) return false;
            } catch (_) { return false; }
        }
        if (!dirty()) return true;
        const accepted = typeof showConfirm === 'function'
            ? await showConfirm({title:text('leave_title'), message:text('discard'), okLabel:text('discard_button'), okClass:'danger'})
            : window.confirm(text('discard'));
        if (accepted) discarded = true;
        return accepted;
    }

    function unavailable() {
        unavailableState = true;
        const panel = document.getElementById('detailPanel');
        if (panel) { panel.inert = true; panel.hidden = true; }
        let error = document.getElementById('taskEmbedError');
        if (!error) { error = document.createElement('p'); error.id='taskEmbedError'; error.className='task-embed-error'; error.setAttribute('role','alert'); document.body.append(error); }
        error.textContent = text('unavailable');
        post('error');
    }

    async function changed() {
        post('changed');
        if (validating || unavailableState) return;
        validating = true;
        try {
            const url = window.API_BASE + 'get.php?id=' + context.taskId + '&embed_root_id=' + context.taskId + '&ticket_id=' + context.ticketId;
            const response = await fetch(url);
            const data = await response.json();
            if (!response.ok || !data.success) unavailable();
        } catch (_) { unavailable(); }
        finally { validating = false; }
    }

    // Observe native mutations only inside this dedicated document. The shared
    // CSRF wrapper is already installed; payloads, responses and errors pass through.
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
        const url = new URL(input instanceof Request ? input.url : String(input), location.href);
        const method = (init?.method || (typeof input !== 'string' ? input.method : '') || 'GET').toUpperCase();
        const write = url.origin === location.origin && !['GET','HEAD','OPTIONS'].includes(method);
        if (!write) return nativeFetch(input, init);
        pending++;
        try {
            const response = await nativeFetch(input, init);
            let result = null;
            try { result = await response.clone().json(); } catch (_) { /* an unreadable save is not success */ }
            if (response.ok && result?.success) { failedWrite = false; void changed(); }
            else failedWrite = true;
            return response;
        } catch (error) { failedWrite = true; throw error; }
        finally { pending--; post('dirty', {dirty:dirty()}); }
    };

    window.addEventListener('message', async event => {
        const message = event.data;
        if (event.origin !== location.origin || event.source !== parent || !message) return;
        if (message.type === 'freeitsm:task-embed:context-lost') { unavailable(); return; }
        if (message.type !== 'freeitsm:task-embed:leave' || typeof message.requestId !== 'string') return;
        const can = await canLeave();
        parent.postMessage({type:'freeitsm:task-embed:leave-result', requestId:message.requestId, ...context, canLeave:can}, location.origin);
    });
    window.addEventListener('beforeunload', event => { if (pending || dirty()) { event.preventDefault(); event.returnValue=''; } });
    // Covers older browsers and nested forms whose late DOM does not resize yet.
    const timer = setInterval(measure, 800);
    window.addEventListener('pagehide', () => { observer?.disconnect(); clearInterval(timer); });
    return {ready, changed, saved, canLeave, unavailable, closed:()=>post('closed'),
        editorReady(editor) { savedDescription=editor.getContent(); editor.on('input change',()=>{discarded=false;}); },
    };
})();
