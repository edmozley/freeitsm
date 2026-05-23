/**
 * Workflow Editor — visual canvas builder.
 *
 * Stage 2 of the Workflows module: replaces the form-based editor with a
 * Process Mapper-style dot-grid canvas where each node represents a single
 * piece of the rule. Layout is the UI, but the engine's data shape is
 * unchanged — at save time we sort condition + action nodes by y position
 * and write them in order.
 *
 * Node kinds:
 *   - trigger    (singleton, pinned at top by default)
 *   - condition  (zero or more, drawn as diamonds)
 *   - action     (one or more, drawn as rounded rectangles)
 *
 * Connectors are auto-routed at render time based on the y-sorted order
 * trigger -> condition[0..n-1] -> action[0..m-1]. They aren't stored.
 *
 * The trigger event itself lives on the workflow row (trigger_event column);
 * the trigger node just visualises it. Each condition / action node stores
 * its x, y on its JSON object — the engine ignores the extra keys.
 */
const WFE = (() => {
    // ---- Grid / sizing ----
    const GRID = 20;
    const snap = v => Math.round(v / GRID) * GRID;
    const SIZE = {
        trigger:   { w: 200, h: 70  },
        condition: { w: 160, h: 160 },
        action:    { w: 200, h: 80  },
    };
    const TRIGGER_POS = { x: 220, y: 60 };
    const COL_X        = 220;   // x at which auto-layout stacks nodes
    const ROW_GAP      = 60;    // gap between stacked nodes

    // ---- State ----
    let canvas, svg, detail;
    let workflow = { id: 0, name: '', description: '', isActive: true };
    let triggerEvent = '';
    let nodes = [];               // [{ id, kind, x, y, el, ... }]
    let selectedNodeId = null;
    let nextLocalId = -1;         // negative ids for local-only nodes
    let dragging = null;          // { id, offsetX, offsetY, startX, startY, moved }
    let dirty = false;
    let dirtyTimer = null;

    // =========================================================
    //  Init
    // =========================================================
    function init() {
        canvas = document.getElementById('wfCanvas');
        svg    = document.getElementById('wfConnectors');
        detail = document.getElementById('wfDetail');

        bindCanvasEvents();
        bindWorkflowDetailEvents();

        if (window.WF_ID) {
            loadWorkflow(window.WF_ID);
        } else {
            // Brand new workflow — seed a trigger + one log_message action
            // a sensible distance below.
            workflow = { id: 0, name: '', description: '', isActive: true };
            triggerEvent = Object.keys(window.WF_TRIGGERS)[0];
            nodes = [];
            addTriggerNode();
            const startY = TRIGGER_POS.y + SIZE.trigger.h + ROW_GAP;
            addActionNode(COL_X, startY, 'log_message', { message: '' });
            renderAll();
            selectWorkflow();
            setStatus('unsaved');
            document.getElementById('testFireBtn').disabled = true;
        }
    }

    async function loadWorkflow(id) {
        try {
            const r = await fetch(window.WF_API + 'get.php?id=' + id, { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) { window.showToast(d.error || 'Load failed', 'error'); return; }
            const w = d.workflow;
            workflow.id          = +w.id;
            workflow.name        = w.name || '';
            workflow.description = w.description || '';
            workflow.isActive    = !!parseInt(w.is_active, 10);
            triggerEvent         = w.trigger_event;
            nodes = [];

            // Trigger node (singleton, position fixed unless overridden below).
            addTriggerNode();

            // Conditions — restore x/y if present, else auto-layout below.
            const condArr = Array.isArray(w.conditions) ? w.conditions : [];
            let nextY = TRIGGER_POS.y + SIZE.trigger.h + ROW_GAP;
            condArr.forEach((c, i) => {
                const hasPos = (typeof c.x === 'number' && typeof c.y === 'number');
                const x = hasPos ? c.x : COL_X;
                const y = hasPos ? c.y : nextY;
                addConditionNode(x, y, c.field || '', c.op || 'equals', c.value ?? '');
                if (!hasPos) nextY = y + SIZE.condition.h + ROW_GAP;
            });
            // Track the lowest y to stack actions below the conditions.
            const lowestConditionY = nodes
                .filter(n => n.kind === 'condition')
                .reduce((m, n) => Math.max(m, n.y + SIZE.condition.h), TRIGGER_POS.y + SIZE.trigger.h);

            // Actions — same pattern.
            const actArr = Array.isArray(w.actions) ? w.actions : [];
            nextY = lowestConditionY + ROW_GAP;
            actArr.forEach((a, i) => {
                const hasPos = (typeof a.x === 'number' && typeof a.y === 'number');
                const x = hasPos ? a.x : COL_X;
                const y = hasPos ? a.y : nextY;
                addActionNode(x, y, a.type || 'log_message', a.args || {});
                if (!hasPos) nextY = y + SIZE.action.h + ROW_GAP;
            });

            // Hydrate the workflow detail panel.
            document.getElementById('wfName').value = workflow.name;
            document.getElementById('wfDescription').value = workflow.description;
            document.getElementById('wfActive').checked = workflow.isActive;

            renderAll();
            selectWorkflow();
            renderExecutions(d.executions || []);
            document.getElementById('testFireBtn').disabled = false;
            setStatus('saved');
        } catch (e) {
            window.showToast('Load failed', 'error');
        }
    }

    // =========================================================
    //  Node model
    // =========================================================
    function addTriggerNode() {
        nodes.push({
            id: nextLocalId--,
            kind: 'trigger',
            x: TRIGGER_POS.x,
            y: TRIGGER_POS.y,
            el: null,
        });
    }
    function addConditionNode(x, y, field, op, value) {
        const n = {
            id: nextLocalId--,
            kind: 'condition',
            x: snap(x), y: snap(y),
            field, op, value,
            el: null,
        };
        nodes.push(n);
        return n;
    }
    function addActionNode(x, y, type, args) {
        const n = {
            id: nextLocalId--,
            kind: 'action',
            x: snap(x), y: snap(y),
            type, args: args || {},
            el: null,
        };
        nodes.push(n);
        return n;
    }

    // Public — toolbar buttons.
    function addCondition() {
        const fields = window.WF_FIELDS_BY_TRIG[triggerEvent] || [];
        const lastY = lowestNodeY('condition', 'trigger');
        const n = addConditionNode(COL_X, lastY + ROW_GAP, fields[0] || '', 'equals', '');
        renderAll();
        selectNode(n.id);
    }
    function addAction() {
        const firstType = Object.keys(window.WF_ACTION_DEFS)[0];
        const defaults = firstType === 'log_message' ? { message: '' } : {};
        const lastY = lowestNodeY('action', 'condition', 'trigger');
        const n = addActionNode(COL_X, lastY + ROW_GAP, firstType, defaults);
        renderAll();
        selectNode(n.id);
    }

    // Helper — find the lowest y of any node in the listed kinds (used to
    // place a new node below the existing stack).
    function lowestNodeY(...kinds) {
        let lowest = TRIGGER_POS.y;
        nodes.forEach(n => {
            if (!kinds.includes(n.kind)) return;
            const sz = SIZE[n.kind];
            const bottom = n.y + sz.h;
            if (bottom > lowest) lowest = bottom;
        });
        return lowest;
    }

    function deleteSelected() {
        if (selectedNodeId == null) return;
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n) return;
        if (n.kind === 'trigger') return;   // can't delete the trigger
        nodes = nodes.filter(x => x.id !== selectedNodeId);
        selectedNodeId = null;
        renderAll();
        selectWorkflow();
        markDirty();
    }

    // =========================================================
    //  Rendering — nodes + connectors
    // =========================================================
    function renderAll() {
        // Wipe everything except the connectors SVG.
        Array.from(canvas.querySelectorAll('.wf-node')).forEach(el => el.remove());
        nodes.forEach(n => {
            n.el = createNodeEl(n);
            canvas.appendChild(n.el);
        });
        renderConnectors();
        updateSelectionVisuals();
        updateCanvasSize();
    }

    function createNodeEl(n) {
        const el = document.createElement('div');
        el.className = 'wf-node wf-node-' + n.kind;
        el.dataset.nodeId = n.id;
        el.style.left = n.x + 'px';
        el.style.top  = n.y + 'px';
        const sz = SIZE[n.kind];
        el.style.width  = sz.w + 'px';
        el.style.height = sz.h + 'px';
        el.innerHTML = renderNodeContent(n);
        el.addEventListener('mousedown', e => onNodeMouseDown(e, n));
        return el;
    }

    function renderNodeContent(n) {
        const escHtml = s => {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        };
        if (n.kind === 'trigger') {
            const label = window.WF_TRIGGERS[triggerEvent] || triggerEvent || 'Pick a trigger';
            return `
                <div class="wf-node-kind">Trigger</div>
                <div class="wf-node-title">${escHtml(label)}</div>
            `;
        }
        if (n.kind === 'condition') {
            // Diamonds need extra inner padding so the label fits inside the
            // visible polygon — the .wf-node-condition CSS handles the clip.
            const op = window.WF_OPS[n.op] || n.op;
            const fieldShort = (n.field || '?').split('.').pop();
            return `
                <div class="wf-node-kind">Condition</div>
                <div class="wf-node-title">${escHtml(fieldShort)} <em style="color:#888; font-style: normal; font-weight: 400;">${escHtml(op)}</em></div>
                <div class="wf-node-sub">${escHtml(n.value)}</div>
            `;
        }
        if (n.kind === 'action') {
            const def = window.WF_ACTION_DEFS[n.type];
            const label = def ? def.label : n.type;
            // Show a snippet of the args so the action's intent is visible
            // on the canvas without selecting it.
            let snippet = '';
            if (n.type === 'log_message' && n.args && typeof n.args.message === 'string') {
                snippet = n.args.message.slice(0, 60) + (n.args.message.length > 60 ? '…' : '');
            }
            return `
                <div class="wf-node-kind">Action</div>
                <div class="wf-node-title">${escHtml(label)}</div>
                ${snippet ? '<div class="wf-node-sub">' + escHtml(snippet) + '</div>' : ''}
            `;
        }
        return '';
    }

    // Connectors run trigger -> conditions (in y order) -> actions (in y order).
    // No branching in v1 — purely linear visual of execution flow.
    function renderConnectors() {
        Array.from(svg.querySelectorAll('.wf-conn')).forEach(el => el.remove());
        const trigger = nodes.find(n => n.kind === 'trigger');
        const conditions = nodes.filter(n => n.kind === 'condition').slice().sort((a, b) => a.y - b.y);
        const actions    = nodes.filter(n => n.kind === 'action').slice().sort((a, b) => a.y - b.y);
        const chain = [trigger, ...conditions, ...actions].filter(Boolean);
        for (let i = 0; i + 1 < chain.length; i++) {
            drawConnector(chain[i], chain[i + 1]);
        }
        // Make sure arrowhead def is present.
        ensureArrowheadDef();
    }

    function ensureArrowheadDef() {
        if (svg.querySelector('#wfArrow')) return;
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        defs.innerHTML = '<marker id="wfArrow" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto"><polygon points="0 0, 10 3.5, 0 7" fill="#999"/></marker>';
        svg.appendChild(defs);
    }

    function drawConnector(a, b) {
        // Pick the closest edge between the two boxes — same routing as
        // Process Mapper's calcConnectorPoints. Defaults to short straight
        // segments which look clean on a vertical layout.
        const az = SIZE[a.kind], bz = SIZE[b.kind];
        const acx = a.x + az.w / 2, acy = a.y + az.h / 2;
        const bcx = b.x + bz.w / 2, bcy = b.y + bz.h / 2;
        const dx = bcx - acx, dy = bcy - acy;
        let x1, y1, x2, y2;
        if (Math.abs(dx) > Math.abs(dy)) {
            // horizontal dominant
            if (dx > 0) { x1 = a.x + az.w; y1 = acy; x2 = b.x;          y2 = bcy; }
            else        { x1 = a.x;        y1 = acy; x2 = b.x + bz.w;   y2 = bcy; }
        } else {
            // vertical dominant
            if (dy > 0) { x1 = acx;        y1 = a.y + az.h; x2 = bcx;        y2 = b.y; }
            else        { x1 = acx;        y1 = a.y;        x2 = bcx;        y2 = b.y + bz.h; }
        }
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('class', 'wf-conn');
        path.setAttribute('d', `M${x1},${y1} L${x2},${y2}`);
        path.setAttribute('stroke', '#999');
        path.setAttribute('stroke-width', '2');
        path.setAttribute('fill', 'none');
        path.setAttribute('marker-end', 'url(#wfArrow)');
        svg.appendChild(path);
    }

    // Resize the canvas's intrinsic dimensions so all nodes fit and the SVG
    // overlay stretches over the whole content area.
    function updateCanvasSize() {
        let maxRight = 800, maxBottom = 600;
        nodes.forEach(n => {
            const sz = SIZE[n.kind];
            if (n.x + sz.w + 80 > maxRight)  maxRight  = n.x + sz.w + 80;
            if (n.y + sz.h + 80 > maxBottom) maxBottom = n.y + sz.h + 80;
        });
        // The SVG layer matches the canvas content area.
        svg.setAttribute('width', String(maxRight));
        svg.setAttribute('height', String(maxBottom));
    }

    function updateSelectionVisuals() {
        Array.from(canvas.querySelectorAll('.wf-node')).forEach(el => {
            const isSel = (+el.dataset.nodeId === selectedNodeId);
            el.classList.toggle('selected', isSel);
        });
    }

    // =========================================================
    //  Interaction — drag, select, keys
    // =========================================================
    function bindCanvasEvents() {
        canvas.addEventListener('mousedown', onCanvasMouseDown);
        document.addEventListener('mousemove', onDocMouseMove);
        document.addEventListener('mouseup', onDocMouseUp);
        canvas.addEventListener('keydown', e => {
            if (e.key === 'Delete' || e.key === 'Backspace') {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                if (selectedNodeId != null) { e.preventDefault(); deleteSelected(); }
            }
        });
    }

    function onCanvasMouseDown(e) {
        // Click on bare canvas = deselect → show workflow detail.
        if (e.target === canvas || e.target === svg) {
            selectWorkflow();
        }
    }

    function onNodeMouseDown(e, n) {
        if (e.button !== 0) return;
        e.stopPropagation();
        e.preventDefault();
        canvas.focus({ preventScroll: true });
        // Start drag-with-deferred-select: panel opens only on mouseup-no-drag
        // (same pattern Process Mapper landed in #335).
        const rect = canvas.getBoundingClientRect();
        dragging = {
            id: n.id,
            offsetX: e.clientX - (n.x + rect.left - canvas.scrollLeft),
            offsetY: e.clientY - (n.y + rect.top  - canvas.scrollTop),
            startX: n.x,
            startY: n.y,
            moved: false,
        };
        // If the user clicks a different node, switch selection immediately
        // (so the selected outline tracks the cursor even before mouseup).
        if (selectedNodeId !== n.id) selectNode(n.id, /*deferDetail*/ true);
    }

    function onDocMouseMove(e) {
        if (!dragging) return;
        const n = nodes.find(x => x.id === dragging.id);
        if (!n) return;
        if (n.kind === 'trigger') {
            // Trigger is pinned for v1 — don't move it. Future stages may
            // allow free placement.
            return;
        }
        const rect = canvas.getBoundingClientRect();
        const nx = e.clientX - rect.left + canvas.scrollLeft - dragging.offsetX;
        const ny = e.clientY - rect.top  + canvas.scrollTop  - dragging.offsetY;
        const sx = Math.max(0, snap(nx));
        const sy = Math.max(0, snap(ny));
        if (sx !== n.x || sy !== n.y) {
            n.x = sx; n.y = sy;
            n.el.style.left = sx + 'px';
            n.el.style.top  = sy + 'px';
            dragging.moved = true;
            renderConnectors();
            updateCanvasSize();
        }
    }

    function onDocMouseUp(e) {
        if (!dragging) return;
        if (dragging.moved) markDirty();
        else selectNode(dragging.id);   // click-without-drag opens the panel
        dragging = null;
    }

    // =========================================================
    //  Selection + detail panel
    // =========================================================
    function selectNode(id, deferDetail) {
        selectedNodeId = id;
        updateSelectionVisuals();
        if (!deferDetail) {
            const n = nodes.find(x => x.id === id);
            if (!n) return;
            if (n.kind === 'trigger')   showDetailForTrigger();
            if (n.kind === 'condition') showDetailForCondition(n);
            if (n.kind === 'action')    showDetailForAction(n);
        }
    }

    function selectWorkflow() {
        selectedNodeId = null;
        updateSelectionVisuals();
        showBody('Workflow', 'wfBodyWorkflow');
    }

    function showBody(title, id) {
        document.getElementById('wfDetailTitle').textContent = title;
        ['wfBodyWorkflow', 'wfBodyTrigger', 'wfBodyCondition', 'wfBodyAction'].forEach(b => {
            document.getElementById(b).style.display = (b === id) ? '' : 'none';
        });
    }

    function showDetailForTrigger() {
        showBody('Trigger', 'wfBodyTrigger');
        document.getElementById('wfTrigger').value = triggerEvent;
    }

    function showDetailForCondition(n) {
        showBody('Condition', 'wfBodyCondition');
        // Field dropdown is repopulated whenever the trigger event changes,
        // so the available options match the current trigger's payload shape.
        const fields = window.WF_FIELDS_BY_TRIG[triggerEvent] || [];
        const fieldSel = document.getElementById('wfCondField');
        fieldSel.innerHTML = fields.map(f =>
            `<option value="${escAttr(f)}" ${f === n.field ? 'selected' : ''}>${escAttr(f)}</option>`
        ).join('');
        if (n.field && fields.indexOf(n.field) < 0) {
            // Field stored from a previous trigger choice — show it as a
            // custom option so the user can change it deliberately rather
            // than having it silently swapped out.
            const opt = document.createElement('option');
            opt.value = n.field; opt.selected = true;
            opt.textContent = n.field + ' (custom)';
            fieldSel.appendChild(opt);
        }
        document.getElementById('wfCondOp').value = n.op;
        const valueInput = document.getElementById('wfCondValue');
        valueInput.value = n.value ?? '';
        valueInput.disabled = (n.op === 'is_empty' || n.op === 'is_not_empty');
    }

    function showDetailForAction(n) {
        showBody('Action', 'wfBodyAction');
        const typeSel = document.getElementById('wfActType');
        typeSel.value = n.type;
        const def = window.WF_ACTION_DEFS[n.type];
        document.getElementById('wfActDesc').textContent = def ? def.description : '';
        document.getElementById('wfActArgs').value = JSON.stringify(n.args || {}, null, 2);
    }

    function bindWorkflowDetailEvents() {
        ['wfName', 'wfDescription'].forEach(id => {
            document.getElementById(id).addEventListener('input', () => {
                workflow.name        = document.getElementById('wfName').value;
                workflow.description = document.getElementById('wfDescription').value;
                markDirty();
            });
        });
        document.getElementById('wfActive').addEventListener('change', () => {
            workflow.isActive = document.getElementById('wfActive').checked;
            markDirty();
        });
    }

    // Detail-panel handlers exposed via WFE.* for inline onchange / oninput.
    function updateTriggerFromDetail() {
        triggerEvent = document.getElementById('wfTrigger').value;
        // Re-render: the trigger node label updates, and any condition node's
        // field-dropdown options will need to refresh next time it's selected.
        nodes.forEach(n => { if (n.el) n.el.innerHTML = renderNodeContent(n); });
        markDirty();
    }
    function updateConditionFromDetail() {
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n || n.kind !== 'condition') return;
        n.field = document.getElementById('wfCondField').value;
        n.op    = document.getElementById('wfCondOp').value;
        n.value = document.getElementById('wfCondValue').value;
        document.getElementById('wfCondValue').disabled = (n.op === 'is_empty' || n.op === 'is_not_empty');
        if (n.el) n.el.innerHTML = renderNodeContent(n);
        markDirty();
    }
    function updateActionTypeFromDetail() {
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n || n.kind !== 'action') return;
        n.type = document.getElementById('wfActType').value;
        // Reset args to the new action's defaults so the user sees a sensible
        // starting JSON rather than the previous action's keys.
        if (n.type === 'log_message') n.args = { message: '' };
        else n.args = {};
        showDetailForAction(n);
        if (n.el) n.el.innerHTML = renderNodeContent(n);
        markDirty();
    }
    function updateActionArgsFromDetail() {
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n || n.kind !== 'action') return;
        const raw = document.getElementById('wfActArgs').value;
        try {
            n.args = raw ? JSON.parse(raw) : {};
            if (n.el) n.el.innerHTML = renderNodeContent(n);
            markDirty();
        } catch (e) {
            // Invalid JSON — keep the old args; the user is mid-edit.
        }
    }

    // =========================================================
    //  Dirty flag + status pip
    // =========================================================
    function markDirty() {
        dirty = true;
        setStatus('unsaved');
    }
    function setStatus(state) {
        const el = document.getElementById('wfStatus');
        if (!el) return;
        const states = {
            idle:    { html: '', cls: '' },
            unsaved: { html: 'Unsaved changes', cls: 'wf-status-unsaved' },
            saving:  { html: '<span class="wf-status-dot"></span> Saving…', cls: 'wf-status-saving' },
            saved:   { html: '<span class="wf-status-tick">✓</span> Saved', cls: 'wf-status-saved' },
        };
        const s = states[state] || states.idle;
        el.className = 'wf-status ' + s.cls;
        el.innerHTML = s.html;
    }

    // =========================================================
    //  Save / Test fire
    // =========================================================
    async function save() {
        if (!workflow.name.trim()) {
            window.showToast(window.t('workflow.toast.name_required'), 'error');
            return;
        }
        const actionNodes = nodes.filter(n => n.kind === 'action');
        if (!actionNodes.length) {
            window.showToast(window.t('workflow.toast.actions_required'), 'error');
            return;
        }
        setStatus('saving');

        // Sort by y so execution order matches visual order on the canvas.
        const conditions = nodes.filter(n => n.kind === 'condition')
            .slice().sort((a, b) => a.y - b.y)
            .map(n => ({ field: n.field, op: n.op, value: n.value, x: n.x, y: n.y }));
        const actions = actionNodes
            .slice().sort((a, b) => a.y - b.y)
            .map(n => ({ type: n.type, args: n.args || {}, x: n.x, y: n.y }));

        const payload = {
            id: workflow.id || null,
            name: workflow.name.trim(),
            description: workflow.description.trim(),
            trigger_event: triggerEvent,
            conditions, actions,
            is_active: workflow.isActive ? 1 : 0,
        };
        try {
            const r = await fetch(window.WF_API + 'save.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const d = await r.json();
            if (!d.success) {
                setStatus('unsaved');
                window.showToast(d.error || 'Save failed', 'error');
                return;
            }
            window.showToast(window.t('workflow.toast.saved'), 'success');
            dirty = false;
            setStatus('saved');
            if (!workflow.id && d.id) {
                workflow.id = d.id;
                window.WF_ID = d.id;
                window.history.replaceState({}, '', 'editor.php?id=' + d.id);
                document.getElementById('testFireBtn').disabled = false;
            }
        } catch (e) {
            setStatus('unsaved');
            window.showToast('Save failed', 'error');
        }
    }

    async function testFire() {
        if (!workflow.id) { window.showToast('Save the workflow first.', 'error'); return; }
        window.showToast(window.t('workflow.toast.fire_started'), 'info');
        try {
            const r = await fetch(window.WF_API + 'fire.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: workflow.id, payload: {} }),
            });
            const d = await r.json();
            if (d.success) {
                const status = d.result.status;
                window.showToast(
                    window.t('workflow.toast.fire_done').replace('%s', status),
                    status === 'failed' ? 'error' : 'success'
                );
                const re = await fetch(window.WF_API + 'get.php?id=' + workflow.id, { credentials: 'same-origin' });
                const dd = await re.json();
                if (dd.success) renderExecutions(dd.executions || []);
            } else {
                window.showToast(window.t('workflow.toast.fire_failed').replace('%s', d.error || ''), 'error');
            }
        } catch (e) { window.showToast('Test fire failed', 'error'); }
    }

    function renderExecutions(execs) {
        const host = document.getElementById('execList');
        if (!host) return;
        if (!execs.length) {
            host.innerHTML = '<em>No runs yet. Test-fire the workflow to see one here.</em>';
            return;
        }
        const escHtml = s => {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        };
        const fmtDate = s => {
            if (!s) return '';
            try { return new Date(s.replace(' ', 'T') + 'Z').toLocaleString(); }
            catch (e) { return s; }
        };
        host.innerHTML = execs.map(e => {
            const pill = e.status === 'success'  ? '<span class="status-badge status-active">' + escHtml(window.t('workflow.status.success')) + '</span>'
                       : e.status === 'failed'   ? '<span class="status-badge status-inactive">' + escHtml(window.t('workflow.status.failed'))  + '</span>'
                       : e.status === 'skipped'  ? '<span class="status-badge"   style="background:#fef3c7;color:#92400e;">' + escHtml(window.t('workflow.status.skipped')) + '</span>'
                       :                           '<span class="status-badge"   style="background:#e0e7ff;color:#3730a3;">' + escHtml(window.t('workflow.status.running')) + '</span>';
            return `<div style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                ${pill}
                <div style="font-size: 12px; color: #888; margin-top: 4px;">${escHtml(fmtDate(e.started_datetime))}</div>
                ${e.error_message ? '<div style="font-size: 12px; color: #c33; margin-top: 4px;">' + escHtml(e.error_message) + '</div>' : ''}
            </div>`;
        }).join('');
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function escAttr(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // =========================================================
    //  AI co-author (Stage 3)
    //  -----------------------------------------------------------------
    //  The toolbar's AI button opens a modal. The user describes the
    //  workflow they want; we send the prompt + current state to
    //  api/workflow/ai_compose.php which returns a structured proposal.
    //  Apply replaces the canvas with the proposed nodes (preserving
    //  workflow id + active flag so a save round-trip still works).
    // =========================================================
    let aiProposal = null;   // last successful proposal from the API

    function openAiModal() {
        const modal = document.getElementById('wfAiModal');
        if (!modal) return;
        // Reset state on each open so a previous proposal doesn't linger.
        aiProposal = null;
        document.getElementById('wfAiResult').style.display = 'none';
        document.getElementById('wfAiGenerateBtn').style.display = '';
        document.getElementById('wfAiApplyBtn').style.display = 'none';
        document.getElementById('wfAiDiscardBtn').style.display = 'none';
        document.getElementById('wfAiPrompt').value = '';
        // Iterate hint surfaces only when there's already content on the canvas.
        const hasContent = nodes.some(n => n.kind === 'condition' || n.kind === 'action');
        document.getElementById('wfAiIterateHint').style.display = hasContent ? '' : 'none';
        modal.classList.add('active');
        setTimeout(() => document.getElementById('wfAiPrompt').focus(), 80);
    }
    function closeAiModal() {
        const modal = document.getElementById('wfAiModal');
        if (modal) modal.classList.remove('active');
    }

    async function aiGenerate() {
        const prompt = document.getElementById('wfAiPrompt').value.trim();
        if (!prompt) return;
        const btn = document.getElementById('wfAiGenerateBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="wf-status-dot" style="margin-right:6px;"></span>' + window.t('workflow.ai.thinking');

        // Snapshot the current workflow state so the AI can iterate on it.
        const existing = collectWorkflowForApi(/* dropPositions */ true);

        try {
            const r = await fetch(window.WF_API + 'ai_compose.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt, existing }),
            });
            const d = await r.json();
            if (!d.success) {
                window.showToast(window.t('workflow.toast.ai_failed').replace('%s', d.error || ''), 'error');
                return;
            }
            aiProposal = d;
            renderAiResult(d);
        } catch (e) {
            window.showToast(window.t('workflow.toast.ai_failed').replace('%s', e.message || ''), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = window.t('workflow.ai.generate');
        }
    }

    // Build a payload that mirrors what save.php expects, with optional
    // `dropPositions` to omit the x/y the canvas tracks — the AI doesn't
    // need to see those.
    function collectWorkflowForApi(dropPositions) {
        const conditions = nodes.filter(n => n.kind === 'condition')
            .slice().sort((a, b) => a.y - b.y)
            .map(n => {
                const o = { field: n.field, op: n.op, value: n.value };
                if (!dropPositions) { o.x = n.x; o.y = n.y; }
                return o;
            });
        const actions = nodes.filter(n => n.kind === 'action')
            .slice().sort((a, b) => a.y - b.y)
            .map(n => {
                const o = { type: n.type, args: n.args || {} };
                if (!dropPositions) { o.x = n.x; o.y = n.y; }
                return o;
            });
        return {
            name: workflow.name,
            description: workflow.description,
            trigger_event: triggerEvent,
            conditions, actions,
        };
    }

    function renderAiResult(d) {
        const escHtml = s => {
            const el = document.createElement('div');
            el.textContent = s == null ? '' : String(s);
            return el.innerHTML;
        };
        document.getElementById('wfAiResult').style.display = '';
        document.getElementById('wfAiGenerateBtn').style.display = 'none';
        document.getElementById('wfAiApplyBtn').style.display = '';
        document.getElementById('wfAiDiscardBtn').style.display = '';

        document.getElementById('wfAiExplanation').textContent = d.explanation || '(no explanation given)';

        // Build a compact preview of the proposed workflow.
        const w = d.workflow || {};
        const trigLabel = window.WF_TRIGGERS[w.trigger_event] || w.trigger_event || '?';
        const parts = [];
        parts.push('<div style="padding: 6px 10px; background: #fef3c7; border-radius: 4px; margin-bottom: 6px;"><strong>Trigger:</strong> ' + escHtml(trigLabel) + '</div>');
        (w.conditions || []).forEach((c, i) => {
            const op = window.WF_OPS[c.op] || c.op;
            parts.push('<div style="padding: 6px 10px; background: #ffedd5; border-radius: 4px; margin-bottom: 6px;"><strong>If</strong> ' + escHtml(c.field) + ' <em style="color:#888;">' + escHtml(op) + '</em> ' + escHtml(c.value) + '</div>');
        });
        (w.actions || []).forEach((a, i) => {
            const def = window.WF_ACTION_DEFS[a.type];
            const label = def ? def.label : a.type;
            const args = JSON.stringify(a.args || {});
            parts.push('<div style="padding: 6px 10px; background: #dbeafe; border-radius: 4px; margin-bottom: 6px;"><strong>' + escHtml(label) + '</strong>: <code style="font-size: 11.5px;">' + escHtml(args) + '</code></div>');
        });
        if (w.name) {
            parts.unshift('<div style="margin-bottom: 8px;"><strong>' + escHtml(w.name) + '</strong>' + (w.description ? ' <span style="color:#777;">— ' + escHtml(w.description) + '</span>' : '') + '</div>');
        }
        document.getElementById('wfAiPreview').innerHTML = parts.join('');

        // Warnings — only shown when the server validated-down the proposal.
        const wb = document.getElementById('wfAiWarnings');
        const wl = document.getElementById('wfAiWarningsList');
        if (d.warnings && d.warnings.length) {
            wb.style.display = '';
            wl.innerHTML = d.warnings.map(w => '<li>' + escHtml(w) + '</li>').join('');
        } else {
            wb.style.display = 'none';
        }
    }

    function aiDiscard() {
        aiProposal = null;
        // Reset back to the prompt view so the user can try again.
        document.getElementById('wfAiResult').style.display = 'none';
        document.getElementById('wfAiGenerateBtn').style.display = '';
        document.getElementById('wfAiApplyBtn').style.display = 'none';
        document.getElementById('wfAiDiscardBtn').style.display = 'none';
        document.getElementById('wfAiPrompt').focus();
    }

    function aiApply() {
        if (!aiProposal) return;
        const w = aiProposal.workflow;
        if (!w) return;

        // Carry over the workflow's id + active flag — the AI owns the rule
        // shape, not the identity / state.
        const keepId       = workflow.id;
        const keepActive   = workflow.isActive;
        workflow.name        = w.name || workflow.name;
        workflow.description = w.description || workflow.description;
        workflow.id          = keepId;
        workflow.isActive    = keepActive;
        triggerEvent         = w.trigger_event;

        // Replace nodes with the proposal — same auto-layout as a fresh load
        // (trigger pinned, conditions then actions stacked vertically).
        nodes = [];
        addTriggerNode();
        let nextY = TRIGGER_POS.y + SIZE.trigger.h + ROW_GAP;
        (w.conditions || []).forEach(c => {
            addConditionNode(COL_X, nextY, c.field || '', c.op || 'equals', c.value ?? '');
            nextY += SIZE.condition.h + ROW_GAP;
        });
        const lowestCondY = nodes
            .filter(n => n.kind === 'condition')
            .reduce((m, n) => Math.max(m, n.y + SIZE.condition.h), TRIGGER_POS.y + SIZE.trigger.h);
        nextY = lowestCondY + ROW_GAP;
        (w.actions || []).forEach(a => {
            addActionNode(COL_X, nextY, a.type || 'log_message', a.args || {});
            nextY += SIZE.action.h + ROW_GAP;
        });

        // Reflect into the workflow detail panel.
        document.getElementById('wfName').value = workflow.name;
        document.getElementById('wfDescription').value = workflow.description;

        renderAll();
        selectWorkflow();
        markDirty();
        closeAiModal();
        window.showToast(window.t('workflow.toast.ai_applied'), 'success');
    }

    document.addEventListener('DOMContentLoaded', init);

    // Public API used by the editor.php inline handlers and toolbar.
    return {
        addCondition, addAction, deleteSelected,
        save, testFire,
        updateTriggerFromDetail,
        updateConditionFromDetail,
        updateActionTypeFromDetail,
        updateActionArgsFromDetail,
        // AI co-author
        openAiModal, closeAiModal, aiGenerate, aiApply, aiDiscard,
    };
})();

window.WFE = WFE;
