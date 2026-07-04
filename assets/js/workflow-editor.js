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
        // Seed args from the action's spec — picks up `default` values
        // like `{{ticket.id}}` for ticket_id, so the user doesn't have
        // to type the variable themselves.
        const def = window.WF_ACTION_DEFS[firstType] || {};
        const defaults = {};
        Object.keys(def.args || {}).forEach(k => {
            const s = def.args[k];
            if (s && typeof s === 'object' && s.default != null) defaults[k] = s.default;
        });
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
            // Pretty-print the value: array → joined labels; lookup → resolve
            // id to label; otherwise use the raw value as-is. Keeps the node
            // glanceable rather than showing opaque ids.
            const lookup = window.WF_LOOKUP_VALUES[n.field] || null;
            const labelFor = id => {
                if (!lookup) return String(id);
                const row = lookup.find(v => String(v.id) === String(id));
                return row ? row.label : String(id);
            };
            const arrValue = Array.isArray(n.value) ? n.value : (n.value == null || n.value === '' ? [] : [n.value]);
            let valueStr;
            if (n.op === 'is_empty' || n.op === 'is_not_empty') {
                valueStr = '';
            } else if (n.op === 'in' || n.op === 'not_in') {
                valueStr = arrValue.length ? arrValue.map(labelFor).join(', ') : '(no values)';
            } else {
                valueStr = labelFor(n.value);
            }
            return `
                <div class="wf-node-kind">Condition</div>
                <div class="wf-node-title">${escHtml(fieldShort)} <em style="color:#888; font-style: normal; font-weight: 400;">${escHtml(op)}</em></div>
                <div class="wf-node-sub">${escHtml(valueStr)}</div>
            `;
        }
        if (n.kind === 'action') {
            const def = window.WF_ACTION_DEFS[n.type];
            const label = def ? def.label : n.type;
            // Show a one-line summary of the configured args so the action's
            // intent is visible on the canvas without selecting it. Picks
            // the most salient arg for each action type — message / status /
            // priority / analyst / subject — falling back to the first
            // non-empty arg's value otherwise.
            const args = n.args || {};
            const lookupLabel = (lookupKey, idVal) => {
                const list = (window.WF_ACTION_LOOKUPS && window.WF_ACTION_LOOKUPS[lookupKey]) || [];
                const hit = list.find(v => String(v.id) === String(idVal));
                return hit ? hit.label : String(idVal);
            };
            let snippet = '';
            switch (n.type) {
                case 'log_message':         snippet = String(args.message || ''); break;
                case 'set_ticket_status':   if (args.status_id)   snippet = '→ ' + lookupLabel('ticket_status',   args.status_id);   break;
                case 'set_ticket_priority': if (args.priority_id) snippet = '→ ' + lookupLabel('ticket_priority', args.priority_id); break;
                case 'assign_ticket':       if (args.analyst_id)  snippet = '→ ' + lookupLabel('analyst',         args.analyst_id);  break;
                case 'add_ticket_note':     snippet = String(args.note || ''); break;
                case 'send_email':          snippet = String(args.subject || ''); break;
                case 'create_task':         snippet = String(args.title || ''); break;
                case 'create_ticket':       snippet = String(args.subject || ''); break;
                default: {
                    const first = Object.values(args).find(v => v != null && v !== '');
                    if (first != null) snippet = String(first);
                }
            }
            if (snippet.length > 60) snippet = snippet.slice(0, 60) + '…';
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
        rebuildOperatorDropdown(n);
        renderConditionValueInput(n);
    }

    /**
     * The operator dropdown's contents depend on whether the field is a
     * normalised lookup (priority_id, status_id, etc.) or free-text.
     *
     * For lookup fields the value control is always a multi-select
     * checkbox list, so the user-visible operator collapses down to:
     *     is / is not / is empty / is not empty
     * We always store `in` / `not_in` under the hood (they handle a
     * one-element array the same as `equals` did), upgrading any legacy
     * `equals` / `not_equals` ops the first time the user opens the
     * condition. The engine's existing `in` case treats a 1-item list
     * identically to `equals`, so behaviour is preserved.
     *
     * For free-text fields the full operator catalogue is shown.
     */
    function rebuildOperatorDropdown(n) {
        const opSel = document.getElementById('wfCondOp');
        const type  = window.WF_FIELD_TYPES[n.field] || 'text';
        const isLookup = (type === 'lookup');

        // Silent upgrade from the older single-value ops to the
        // multi-select-friendly ones when the field is a lookup. Keeps the
        // UI consistent without forcing a one-off migration of stored data
        // (workflows re-save with the upgraded op next time the user saves).
        if (isLookup) {
            if (n.op === 'equals')     n.op = 'in';
            if (n.op === 'not_equals') n.op = 'not_in';
        }

        let options;
        if (isLookup) {
            // Friendly labels for the lookup-field case. `in` reads as
            // "is" because tick-one-box also "is" — the multi-select is
            // a generalisation of strict equality.
            options = [
                ['in',           'is'],
                ['not_in',       'is not'],
                ['is_empty',     'is empty'],
                ['is_not_empty', 'is not empty'],
            ];
        } else {
            // text: contains/not_contains, no gt/lt.
            // numeric: gt/lt, no contains/not_contains.
            const allowed = type === 'numeric'
                ? ['equals', 'not_equals', 'in', 'not_in', 'gt', 'lt', 'is_empty', 'is_not_empty']
                : ['equals', 'not_equals', 'in', 'not_in', 'contains', 'not_contains', 'is_empty', 'is_not_empty'];
            options = allowed.map(k => [k, window.WF_OPS[k] || k]);
        }

        // If the currently-saved op isn't valid for this field type
        // (e.g. loading an old workflow that had subject gt "foo"), fall
        // back to a sane default for the type so the dropdown isn't blank.
        const validSlugs = new Set(options.map(o => o[0]));
        if (!validSlugs.has(n.op)) {
            n.op = isLookup ? 'in' : 'equals';
        }

        opSel.innerHTML = options.map(([k, label]) =>
            `<option value="${escAttr(k)}" ${k === n.op ? 'selected' : ''}>${escAttr(label)}</option>`
        ).join('');
    }

    /**
     * The value control adapts to (a) whether the chosen field has a lookup
     * (i.e. a normalised id like `ticket.priority_id`) and (b) the operator.
     *
     *   Lookup field + (in / not_in)        → checkbox list (single tick =
     *                                          equals semantics; many ticked
     *                                          = OR). This is the default.
     *   Lookup field + is_empty/is_not_empty → no control
     *   Free text + single-value op         → text input
     *   Free text + in / not_in             → comma-separated text input
     *   Free text + is_empty/is_not_empty   → no control
     *
     * The operator dropdown is filtered upstream in `rebuildOperatorDropdown`
     * so lookup fields don't even offer `equals` / `contains` / `gt` etc. —
     * the user just sees "is", "is not", and the empties.
     */
    function renderConditionValueInput(n) {
        const host = document.getElementById('wfCondValueHost');
        const lookup = window.WF_LOOKUP_VALUES[n.field] || null;
        const op = n.op;

        if (op === 'is_empty' || op === 'is_not_empty') {
            host.innerHTML = '<div style="font-size:12px; color:#888; padding: 6px 0;"><em>No value needed — this operator just checks presence.</em></div>';
            return;
        }

        // Lookup field → multi-select checkboxes regardless of op direction.
        // Operator dropdown is restricted to in/not_in/is_empty/is_not_empty
        // for lookup fields, so we're guaranteed a list-shaped value here.
        if (lookup) {
            const selected = Array.isArray(n.value) ? n.value.map(String)
                           : (typeof n.value === 'string' && n.value !== '')
                             ? n.value.split(',').map(s => s.trim())
                             : (n.value != null && n.value !== '' ? [String(n.value)] : []);
            host.innerHTML =
                '<div style="border:1px solid #ddd; border-radius:4px; padding:8px 10px; max-height:200px; overflow-y:auto; background:#fafafa;">' +
                lookup.map(v => {
                    const isOn = selected.includes(String(v.id));
                    return `<label style="display:flex; align-items:center; gap:8px; padding:4px 0; cursor:pointer; font-size: 13px; color: #333;">
                        <input type="checkbox" value="${escAttr(v.id)}" ${isOn ? 'checked' : ''} onchange="WFE.onConditionMultiToggle()">
                        ${escAttr(v.label)} <span style="color:#999; font-size:11px;">(id ${escAttr(v.id)})</span>
                    </label>`;
                }).join('') +
                '</div>' +
                '<small style="display:block; color:#888; margin-top:4px;">Tick one for an exact match, or several for an "any of" match.</small>';
            return;
        }

        // Free-text field, in / not_in → comma-separated.
        if (op === 'in' || op === 'not_in') {
            const arr = Array.isArray(n.value) ? n.value
                       : (typeof n.value === 'string' && n.value !== '') ? n.value.split(',').map(s => s.trim())
                       : [];
            host.innerHTML =
                '<input type="text" id="wfCondValue" autocomplete="off" oninput="WFE.updateConditionFromDetail()" value="' + escAttr(arr.join(', ')) + '" placeholder="comma-separated values">' +
                '<small style="display:block; color:#888; margin-top:4px;">Comma-separate the values.</small>';
            return;
        }

        // Free-text field, single-value op.
        const valStr = Array.isArray(n.value) ? n.value.join(', ') : String(n.value ?? '');
        host.innerHTML =
            '<input type="text" id="wfCondValue" autocomplete="off" oninput="WFE.updateConditionFromDetail()" value="' + escAttr(valStr) + '">';
    }

    // When a multi-select checkbox toggles, gather every checked value and
    // store as an array on the condition node. Re-render the canvas node so
    // its preview reflects the new list.
    function onConditionMultiToggle() {
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n || n.kind !== 'condition') return;
        const checks = document.querySelectorAll('#wfCondValueHost input[type="checkbox"]:checked');
        n.value = Array.from(checks).map(c => c.value);
        if (n.el) n.el.innerHTML = renderNodeContent(n);
        markDirty();
    }

    function showDetailForAction(n) {
        showBody('Action', 'wfBodyAction');
        const typeSel = document.getElementById('wfActType');
        typeSel.value = n.type;
        const def = window.WF_ACTION_DEFS[n.type];
        document.getElementById('wfActDesc').textContent = def ? def.description : '';
        renderActionArgsForm(n, def);
    }

    /**
     * Render the per-action form into #wfActArgsHost based on the action's
     * args spec in WF_ACTION_DEFS. Replaces the old single JSON textarea —
     * users now get a labelled control per arg (text input / textarea /
     * number / checkbox / lookup dropdown). Free-text args that support
     * variables show a small `{{ticket.id}}` hint underneath.
     */
    function renderActionArgsForm(n, def) {
        const host = document.getElementById('wfActArgsHost');
        if (!host) return;
        host.innerHTML = '';
        if (!def || !def.args) return;
        const argsSpec = def.args;
        n.args = n.args || {};
        Object.keys(argsSpec).forEach(argName => {
            const spec = argsSpec[argName] || {};
            // Allow the legacy short-form `'message' => 'string'` spec to
            // still work — treat unknown spec shapes as a plain text input.
            const norm = (typeof spec === 'object' && spec) ? spec : { type: 'text', label: argName };
            const fg = document.createElement('div');
            fg.className = 'form-group';
            fg.dataset.argFg = argName;
            // Optional conditional visibility: show this field only when another
            // arg has one of the listed values, e.g. show_when: {preset:['slack']}.
            if (norm.show_when) fg._showWhen = norm.show_when;
            const labelEl = document.createElement('label');
            labelEl.textContent = (norm.label || argName) + (norm.required ? ' *' : '');
            labelEl.setAttribute('for', 'wfActArg_' + argName);
            fg.appendChild(labelEl);

            const currentVal = (argName in n.args) ? n.args[argName] : (norm.default != null ? norm.default : '');

            let ctrl;
            if (norm.type === 'textarea') {
                ctrl = document.createElement('textarea');
                ctrl.rows = 4;
                ctrl.value = currentVal == null ? '' : String(currentVal);
            } else if (norm.type === 'numeric') {
                ctrl = document.createElement('input');
                ctrl.type = 'number';
                ctrl.value = currentVal == null ? '' : String(currentVal);
            } else if (norm.type === 'bool') {
                ctrl = document.createElement('input');
                ctrl.type = 'checkbox';
                ctrl.checked = !!currentVal;
            } else if (norm.type === 'lookup') {
                ctrl = document.createElement('select');
                const blank = document.createElement('option');
                blank.value = ''; blank.textContent = norm.required ? '— select —' : '(none)';
                ctrl.appendChild(blank);
                const values = (window.WF_ACTION_LOOKUPS && window.WF_ACTION_LOOKUPS[norm.lookup]) || [];
                values.forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = String(v.id);
                    opt.textContent = v.label;
                    if (String(currentVal) === String(v.id)) opt.selected = true;
                    ctrl.appendChild(opt);
                });
            } else if (norm.type === 'select') {
                // Fixed-option dropdown; options are [{value, label}] (or plain
                // strings) supplied inline in the action's arg spec.
                ctrl = document.createElement('select');
                (norm.options || []).forEach(o => {
                    const val = (o && typeof o === 'object') ? o.value : o;
                    const lab = (o && typeof o === 'object') ? (o.label || o.value) : o;
                    const opt = document.createElement('option');
                    opt.value = String(val);
                    opt.textContent = lab;
                    if (String(currentVal) === String(val)) opt.selected = true;
                    ctrl.appendChild(opt);
                });
            } else {
                ctrl = document.createElement('input');
                ctrl.type = 'text';
                ctrl.value = currentVal == null ? '' : String(currentVal);
            }
            ctrl.id = 'wfActArg_' + argName;
            ctrl.dataset.argName = argName;
            // Dropdowns/checkboxes fire 'change'; free-text fires 'input' for live updates.
            const evt = (norm.type === 'bool' || norm.type === 'lookup' || norm.type === 'select') ? 'change' : 'input';
            ctrl.addEventListener(evt, () => {
                updateActionArgFromControl(argName, norm.type, ctrl);
            });
            fg.appendChild(ctrl);

            if (norm.supports_vars) {
                const hint = document.createElement('small');
                hint.style.cssText = 'display: block; color: #6b7280; margin-top: 4px; font-size: 11.5px;';
                hint.textContent = 'Supports variables like {{ticket.id}}, {{ticket.subject}}, {{ticket.priority_id}}.';
                fg.appendChild(hint);
            }
            host.appendChild(fg);

            // Seed n.args with the rendered default so it round-trips
            // through save without the user having to touch every field.
            if (!(argName in n.args) && norm.default != null) n.args[argName] = norm.default;
        });
        applyArgVisibility(host, n);
    }

    /**
     * Show/hide fields whose `show_when` condition (another arg's current value)
     * isn't met — e.g. the webhook Message field only for the chat presets, the
     * raw JSON body only for Custom.
     */
    function applyArgVisibility(host, n) {
        if (!host) return;
        host.querySelectorAll('[data-arg-fg]').forEach(fg => {
            const sw = fg._showWhen;
            if (!sw) return;
            let show = true;
            Object.keys(sw).forEach(key => {
                const cur = (n && n.args && key in n.args) ? String(n.args[key]) : '';
                if (!(sw[key] || []).map(String).includes(cur)) show = false;
            });
            fg.style.display = show ? '' : 'none';
        });
    }

    function updateActionArgFromControl(argName, type, ctrl) {
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n || n.kind !== 'action') return;
        let val;
        if (type === 'bool') val = ctrl.checked;
        else val = ctrl.value;
        n.args = n.args || {};
        if (val === '' && type !== 'bool') delete n.args[argName];
        else n.args[argName] = val;
        if (n.el) n.el.innerHTML = renderNodeContent(n);
        // Re-apply conditional visibility (e.g. changing the webhook preset
        // swaps the Message / Raw JSON body fields).
        applyArgVisibility(document.getElementById('wfActArgsHost'), n);
        markDirty();
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
        const newField = document.getElementById('wfCondField').value;
        const newOp    = document.getElementById('wfCondOp').value;
        const fieldChanged = (newField !== n.field);
        const opChanged    = (newOp !== n.op);
        n.field = newField;
        n.op    = newOp;

        // Field change can flip the field between lookup ↔ free-text, which
        // changes the available operators. Rebuild the op dropdown so the
        // user sees the right set.
        if (fieldChanged) {
            rebuildOperatorDropdown(n);
            // rebuildOperatorDropdown may have upgraded n.op (equals → in,
            // not_equals → not_in) when moving to a lookup field. Pull the
            // possibly-changed value out of the select for downstream use.
            n.op = document.getElementById('wfCondOp').value;
        }

        if (fieldChanged || opChanged) {
            // Pick the right default value shape for the (possibly new) op.
            if (n.op === 'in' || n.op === 'not_in') n.value = Array.isArray(n.value) ? n.value : (n.value != null && n.value !== '' ? [String(n.value)] : []);
            else if (n.op === 'is_empty' || n.op === 'is_not_empty') n.value = '';
            else n.value = Array.isArray(n.value) ? (n.value[0] ?? '') : (n.value ?? '');
            renderConditionValueInput(n);
        } else {
            const ctrl = document.getElementById('wfCondValue');
            if (ctrl) n.value = ctrl.value;
        }
        if (n.el) n.el.innerHTML = renderNodeContent(n);
        markDirty();
    }
    function updateActionTypeFromDetail() {
        const n = nodes.find(x => x.id === selectedNodeId);
        if (!n || n.kind !== 'action') return;
        n.type = document.getElementById('wfActType').value;
        // Reset args and seed defaults from the new action's spec so the
        // user sees the right per-arg controls (and pre-filled `{{ticket.id}}`
        // / similar defaults) rather than the previous action's keys.
        const def = window.WF_ACTION_DEFS[n.type] || {};
        const next = {};
        Object.keys(def.args || {}).forEach(k => {
            const s = def.args[k];
            if (s && typeof s === 'object' && s.default != null) next[k] = s.default;
        });
        n.args = next;
        showDetailForAction(n);
        if (n.el) n.el.innerHTML = renderNodeContent(n);
        markDirty();
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
        // No "actions required" block — let the user save in-progress drafts.
        // We warn after a successful save if there are 0 actions, so they
        // know the workflow won't do anything useful yet.
        const actionNodes = nodes.filter(n => n.kind === 'action');
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
            // Saved successfully. If the workflow has no actions yet, tell
            // the user it won't do anything — but don't block the save.
            if (!actionNodes.length) {
                window.showToast(window.t('workflow.toast.saved_no_actions'), 'info');
            } else {
                window.showToast(window.t('workflow.toast.saved'), 'success');
            }
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

    // Build a synthetic payload from the workflow's own conditions so Test
    // fire actually exercises the action path. For each condition with an
    // equality-ish op we set `payload[field] = value` (or the first item of
    // a list), so the condition trivially passes. Other ops (is_empty /
    // is_not_empty / contains / gt / lt) get a sensible sentinel. The user
    // wants Test fire to mean "do my actions run when conditions pass" —
    // an empty payload would skip every workflow that has any condition.
    function buildTestFirePayload() {
        const payload = {};
        const conditions = nodes.filter(n => n.kind === 'condition');
        const setPath = (path, value) => {
            const parts = String(path).split('.');
            let cur = payload;
            for (let i = 0; i < parts.length - 1; i++) {
                const p = parts[i];
                if (typeof cur[p] !== 'object' || cur[p] === null) cur[p] = {};
                cur = cur[p];
            }
            cur[parts[parts.length - 1]] = value;
        };
        for (const c of conditions) {
            const field = c.data && c.data.field;
            const op = (c.data && c.data.op) || 'equals';
            const val = c.data ? c.data.value : null;
            if (!field) continue;
            switch (op) {
                case 'equals':
                case 'not_equals':
                    setPath(field, op === 'equals' ? val : (val == null ? '__not_match__' : val + '_x'));
                    break;
                case 'in':
                case 'not_in': {
                    let first = null;
                    if (Array.isArray(val)) first = val[0];
                    else if (typeof val === 'string' && val.indexOf(',') !== -1) first = val.split(',')[0].trim();
                    else first = val;
                    setPath(field, op === 'in' ? first : (first == null ? '__not_match__' : first + '_x'));
                    break;
                }
                case 'contains':
                case 'not_contains':
                    setPath(field, op === 'contains' ? ('test ' + (val || '') + ' value') : '__not_match__');
                    break;
                case 'gt': setPath(field, (parseFloat(val) || 0) + 1); break;
                case 'lt': setPath(field, (parseFloat(val) || 0) - 1); break;
                case 'is_empty':     setPath(field, ''); break;
                case 'is_not_empty': setPath(field, 'x'); break;
                default: setPath(field, val);
            }
        }
        return payload;
    }

    async function testFire() {
        if (!workflow.id) { window.showToast('Save the workflow first.', 'error'); return; }
        window.showToast(window.t('workflow.toast.fire_started'), 'info');
        try {
            const r = await fetch(window.WF_API + 'fire.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: workflow.id, payload: buildTestFirePayload() }),
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
                       : e.status === 'aborted'  ? '<span class="status-badge"   style="background:#fee2e2;color:#991b1b;">' + escHtml(window.t('workflow.status.aborted')) + '</span>'
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
        onConditionMultiToggle,
        updateActionTypeFromDetail,
        // AI co-author
        openAiModal, closeAiModal, aiGenerate, aiApply, aiDiscard,
    };
})();

window.WFE = WFE;
