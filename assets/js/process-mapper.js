/**
 * Process Mapper – flowchart editor
 *
 * Features:
 *   - Dot-grid canvas with snap-to-grid (20px)
 *   - Shape types: process, decision, terminal (start/end), document
 *   - Drag to move steps; snap on drop
 *   - Ctrl+click or rubber-band to multi-select; arrow keys to nudge
 *   - Edge handles for drawing connectors; connectors have optional text
 *   - Slide-in detail panel on step click
 *   - Full CRUD via JSON API
 */
const PM = (() => {
    const GRID = 20;
    const snap = v => Math.round(v / GRID) * GRID;

    // ---- state ----
    let processes = [];
    let currentProcessId = null;
    let steps = [];          // { id, tempId, type, label, description, x, y, width, height, color, el }
    let connectors = [];     // { id, tempId, fromId, toId, label }
    let groups = [];         // { id, tempId, label, color, x, y, width, height, el } — visual underlay only
    let lanes = [];          // { id, tempId, label, color, color2, display_order, height, el, headerEl, _bandTop }
    let selectedStepIds = new Set();
    let selectedConnectorId = null;
    let selectedGroupId = null;
    let selectedLaneId = null;
    let groupDragging = null;  // { id, mode: 'move'|'resize', offsetX, offsetY, startW, startH }
    let laneDragging  = null;  // { id, mode: 'reorder'|'resize', startMouseY, startHeight }
    const LANE_WIDTH = 4000;
    let nextTempId = -1;
    let dirty = false;

    // ---- autosave state ----
    let autosaveOn = false;
    let autosaveTimer = null;
    let saveInFlight = false;
    const AUTOSAVE_DEBOUNCE_MS = 2000;
    const MIN_SAVING_VISIBLE_MS = 400;  // keep "Saving…" on screen long enough to be noticed
    const AUTOSAVE_PREF_KEY = 'process_mapper_autosave';

    // ---- drag state ----
    let dragging = null;        // { stepId, offsetX, offsetY, startPositions }
    let connectDrag = null;     // { fromStepId, side, startX, startY }
    let rubberBand = null;      // { startX, startY, el }
    let connectMode = false;

    // ---- DOM refs ----
    let canvas, svg, detailPanel, processList, canvasEmpty;

    // =========================================================
    //  Initialisation
    // =========================================================
    function init() {
        canvas      = document.getElementById('pmCanvas');
        svg         = document.getElementById('connectorsSvg');
        detailPanel = document.getElementById('detailPanel');
        processList = document.getElementById('processList');
        canvasEmpty = document.getElementById('canvasEmpty');

        addArrowheadDef();
        bindCanvasEvents();
        bindToolbar();
        loadProcesses();
        loadAutosavePreference();
    }

    // =========================================================
    //  Autosave: dirty tracking, debounced save, status line
    // =========================================================

    // Single entry-point for "something changed" — replaces the dozen scattered
    // `dirty = true;` writes so we can hook status updates and the debounce
    // timer in one place.
    function markDirty() {
        dirty = true;
        if (!currentProcessId) return;
        setStatus('unsaved');
        if (autosaveOn) scheduleAutosave();
    }

    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            if (autosaveOn && dirty && currentProcessId && !saveInFlight) {
                save(true);
            }
        }, AUTOSAVE_DEBOUNCE_MS);
    }

    // Status states: 'idle' | 'unsaved' | 'saving' | 'saved' | 'failed' | 'off'
    function setStatus(state) {
        const el = document.getElementById('pmStatus');
        if (!el) return;
        const states = {
            idle:    { html: '', cls: '' },
            unsaved: { html: autosaveOn ? 'Unsaved' : 'Unsaved changes', cls: 'pm-status-unsaved' },
            saving:  { html: '<span class="pm-status-spinner"></span> Saving…', cls: 'pm-status-saving' },
            saved:   { html: '<span class="pm-status-tick">✓</span> Saved', cls: 'pm-status-saved' },
            failed:  { html: '<span class="pm-status-warn">⚠</span> Save failed — <a href="#" id="pmRetrySave">retry</a>', cls: 'pm-status-failed' },
            off:     { html: 'Autosave off', cls: 'pm-status-off' },
        };
        const s = states[state] || states.idle;
        el.className = 'pm-status ' + s.cls;
        el.innerHTML = s.html;
        if (state === 'failed') {
            const retry = document.getElementById('pmRetrySave');
            if (retry) retry.onclick = (e) => { e.preventDefault(); save(autosaveOn); };
        }
    }

    async function loadAutosavePreference() {
        try {
            const r = await fetch('../api/system/get_user_preference.php?key=' + encodeURIComponent(AUTOSAVE_PREF_KEY), { credentials: 'same-origin' });
            const d = await r.json();
            const on = d.success && d.value === '1';
            applyAutosaveState(on, false);
        } catch (e) {
            applyAutosaveState(false, false);
        }
    }

    function applyAutosaveState(on, persist) {
        autosaveOn = !!on;
        const cb = document.getElementById('pmAutosaveToggle');
        if (cb) cb.checked = autosaveOn;
        // Initial status reflects the toggle plus current dirtiness.
        if (!currentProcessId) {
            setStatus('idle');
        } else if (dirty) {
            setStatus('unsaved');
            if (autosaveOn) scheduleAutosave();
        } else {
            setStatus(autosaveOn ? 'saved' : 'off');
        }
        if (persist) {
            fetch('../api/system/set_user_preference.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: AUTOSAVE_PREF_KEY, value: autosaveOn ? '1' : '0' })
            }).catch(() => {});
        }
    }

    function toggleAutosave(on) {
        clearTimeout(autosaveTimer);
        applyAutosaveState(on, true);
        // If we just turned autosave ON and there are pending edits, fire one.
        if (autosaveOn && dirty && currentProcessId) scheduleAutosave();
    }

    function addArrowheadDef() {
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        defs.innerHTML = `<marker id="arrowhead" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
            <polygon points="0 0, 10 3.5, 0 7" fill="#666"/>
        </marker>
        <marker id="arrowhead-sel" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
            <polygon points="0 0, 10 3.5, 0 7" fill="#0078d4"/>
        </marker>`;
        svg.appendChild(defs);
    }

    // =========================================================
    //  Process list (sidebar)
    // =========================================================
    async function loadProcesses() {
        try {
            const r = await fetch(API_BASE + 'list.php');
            const d = await r.json();
            if (d.success) {
                processes = d.data;
                renderProcessList();
            }
        } catch (e) { console.error(e); }
    }

    function renderProcessList(filter = '') {
        const fl = filter.toLowerCase();
        const filtered = fl ? processes.filter(p => p.title.toLowerCase().includes(fl)) : processes;
        if (!filtered.length) {
            processList.innerHTML = '<div class="pm-empty">No processes found</div>';
            return;
        }
        processList.innerHTML = filtered.map(p => {
            const active = p.id == currentProcessId ? ' active' : '';
            const date = p.updated_datetime ? new Date(p.updated_datetime).toLocaleDateString() : '';
            return `<div class="pm-process-item${active}" data-id="${p.id}" onclick="PM.openProcess(${p.id})">
                <span class="pm-process-name">${esc(p.title)}</span>
                <span class="pm-process-date">${date}</span>
                <button class="pm-process-delete" onclick="event.stopPropagation(); PM.deleteProcess(${p.id})" title="Delete">&times;</button>
            </div>`;
        }).join('');
    }

    function filterProcesses(val) {
        renderProcessList(val);
    }

    async function createProcess() {
        const title = prompt('Process name:');
        if (!title || !title.trim()) return;
        try {
            const r = await fetch(API_BASE + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: title.trim(), steps: [], connectors: [] })
            });
            const d = await r.json();
            if (d.success) {
                await loadProcesses();
                openProcess(d.id);
            } else {
                toast(d.error, 'error');
            }
        } catch (e) { toast('Failed to create process', 'error'); }
    }

    async function deleteProcess(id) {
        if (!confirm('Delete this process?')) return;
        try {
            const r = await fetch(API_BASE + 'delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) {
                if (currentProcessId == id) {
                    currentProcessId = null;
                    clearCanvas();
                    canvasEmpty.style.display = '';
                }
                await loadProcesses();
                toast('Deleted');
            }
        } catch (e) { toast('Failed to delete', 'error'); }
    }

    // =========================================================
    //  Open / load a process
    // =========================================================
    async function openProcess(id) {
        if (dirty && !confirm('Unsaved changes will be lost. Continue?')) return;
        try {
            const r = await fetch(API_BASE + 'get.php?id=' + id);
            const d = await r.json();
            if (d.success) {
                currentProcessId = id;
                steps = (d.data.steps || []).map(s => ({
                    id: s.id,
                    type: s.type,
                    label: s.label,
                    description: s.description || '',
                    x: +s.x,
                    y: +s.y,
                    width: +s.width,
                    height: +s.height,
                    color: s.color || '#0078d4',
                    color2: s.color2 || null,
                    lane_id: s.lane_id != null ? +s.lane_id : null,
                    el: null
                }));
                connectors = (d.data.connectors || []).map(c => ({
                    id: c.id,
                    fromId: +c.from_step_id,
                    toId: +c.to_step_id,
                    label: c.label || ''
                }));
                groups = (d.data.groups || []).map(g => ({
                    id: g.id,
                    label: g.label || '',
                    color: g.color || '#e3f2fd',
                    color2: g.color2 || null,
                    x: +g.x,
                    y: +g.y,
                    width: +g.width,
                    height: +g.height,
                    el: null
                }));
                lanes = (d.data.lanes || []).map(l => ({
                    id: +l.id,
                    label: l.label || '',
                    color: l.color || '#f5f7fa',
                    color2: l.color2 || null,
                    display_order: +l.display_order,
                    height: +l.height,
                    el: null,
                    headerEl: null,
                    _bandTop: 0
                }));
                dirty = false;
                canvasEmpty.style.display = 'none';
                renderAll();
                renderProcessList();
                closeDetail();
                setStatus(autosaveOn ? 'saved' : 'off');
            }
        } catch (e) { toast('Failed to load process', 'error'); }
    }

    // =========================================================
    //  Render
    // =========================================================
    function renderAll() {
        // Remove old elements
        canvas.querySelectorAll('.pm-step').forEach(el => el.remove());
        canvas.querySelectorAll('.pm-group').forEach(el => el.remove());
        canvas.querySelectorAll('.pm-lane').forEach(el => el.remove());
        // Lanes first (they sit furthest back via CSS z-index = -1)
        renderLanes();
        // Groups next so they sit behind steps + connectors
        groups.forEach(g => {
            g.el = createGroupEl(g);
            canvas.appendChild(g.el);
        });
        steps.forEach(s => {
            s.el = createStepEl(s);
            canvas.appendChild(s.el);
        });
        renderConnectors();
    }

    function createStepEl(step) {
        const el = document.createElement('div');
        el.className = 'pm-step';
        el.dataset.type = step.type;
        el.dataset.stepId = step.id || step.tempId;
        el.style.left = step.x + 'px';
        el.style.top = step.y + 'px';
        el.style.width = step.width + 'px';
        el.style.height = step.height + 'px';
        el.style.background = fillStyle(step.color, step.color2);
        el.textContent = step.label || '(unnamed)';

        // Edge handles for connectors
        ['top','right','bottom','left'].forEach(side => {
            const h = document.createElement('div');
            h.className = 'pm-edge-handle ' + side;
            h.dataset.side = side;
            h.addEventListener('mousedown', e => startConnectDrag(e, step, side));
            el.appendChild(h);
        });

        // Click / drag
        el.addEventListener('mousedown', e => onStepMouseDown(e, step));
        el.addEventListener('dblclick', e => onStepDblClick(e, step));

        return el;
    }

    function renderConnectors() {
        // Clear existing
        svg.querySelectorAll('.pm-connector-group').forEach(g => g.remove());

        connectors.forEach(c => {
            const from = getStep(c.fromId);
            const to = getStep(c.toId);
            if (!from || !to) return;

            const pts = calcConnectorPoints(from, to);
            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.classList.add('pm-connector-group');
            g.dataset.connId = c.id || c.tempId;

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', `M${pts.x1},${pts.y1} L${pts.x2},${pts.y2}`);
            path.classList.add('pm-connector-line');
            if (selectedConnectorId && (selectedConnectorId == c.id || selectedConnectorId == c.tempId)) {
                path.classList.add('selected');
                path.style.markerEnd = 'url(#arrowhead-sel)';
            }
            path.addEventListener('click', e => {
                e.stopPropagation();
                selectConnector(c);
            });
            g.appendChild(path);

            // Hit area (wider invisible path for easier clicking)
            const hit = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            hit.setAttribute('d', `M${pts.x1},${pts.y1} L${pts.x2},${pts.y2}`);
            hit.setAttribute('stroke', 'transparent');
            hit.setAttribute('stroke-width', '14');
            hit.setAttribute('fill', 'none');
            hit.style.pointerEvents = 'stroke';
            hit.style.cursor = 'pointer';
            hit.addEventListener('click', e => {
                e.stopPropagation();
                selectConnector(c);
            });
            g.appendChild(hit);

            // Label
            if (c.label) {
                const mx = (pts.x1 + pts.x2) / 2;
                const my = (pts.y1 + pts.y2) / 2;

                // Background rect
                const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                bg.classList.add('pm-connector-label-bg');
                const textLen = c.label.length * 7 + 12;
                bg.setAttribute('x', mx - textLen / 2);
                bg.setAttribute('y', my - 10);
                bg.setAttribute('width', textLen);
                bg.setAttribute('height', 20);
                bg.setAttribute('rx', 3);
                g.appendChild(bg);

                const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                text.classList.add('pm-connector-label');
                text.setAttribute('x', mx);
                text.setAttribute('y', my + 4);
                text.textContent = c.label;
                text.addEventListener('dblclick', e => {
                    e.stopPropagation();
                    editConnectorLabel(c, mx, my);
                });
                g.appendChild(text);
            }

            svg.appendChild(g);
        });
    }

    function calcConnectorPoints(from, to) {
        // Centre of each step
        const fcx = from.x + from.width / 2;
        const fcy = from.y + from.height / 2;
        const tcx = to.x + to.width / 2;
        const tcy = to.y + to.height / 2;

        // Determine best sides
        const dx = tcx - fcx;
        const dy = tcy - fcy;

        let x1, y1, x2, y2;

        if (Math.abs(dx) > Math.abs(dy)) {
            // Horizontal dominant
            if (dx > 0) {
                x1 = from.x + from.width; y1 = fcy;
                x2 = to.x; y2 = tcy;
            } else {
                x1 = from.x; y1 = fcy;
                x2 = to.x + to.width; y2 = tcy;
            }
        } else {
            // Vertical dominant
            if (dy > 0) {
                x1 = fcx; y1 = from.y + from.height;
                x2 = tcx; y2 = to.y;
            } else {
                x1 = fcx; y1 = from.y;
                x2 = tcx; y2 = to.y + to.height;
            }
        }

        return { x1, y1, x2, y2 };
    }

    // =========================================================
    //  Step interaction
    // =========================================================
    function onStepMouseDown(e, step) {
        if (e.target.classList.contains('pm-edge-handle')) return;
        e.stopPropagation();
        e.preventDefault();
        canvas.focus({ preventScroll: true });

        const id = step.id || step.tempId;

        // Ctrl+click toggles selection
        if (e.ctrlKey || e.metaKey) {
            if (selectedStepIds.has(id)) {
                selectedStepIds.delete(id);
            } else {
                selectedStepIds.add(id);
            }
            updateSelectionVisuals();
            showDetailForStep(step);
            return;
        }

        // If not already selected, select only this one
        if (!selectedStepIds.has(id)) {
            selectedStepIds.clear();
            selectedConnectorId = null;
            selectedStepIds.add(id);
            updateSelectionVisuals();
        }

        showDetailForStep(step);

        // Start drag
        const rect = canvas.getBoundingClientRect();
        const startPositions = {};
        selectedStepIds.forEach(sid => {
            const s = getStep(sid);
            if (s) startPositions[sid] = { x: s.x, y: s.y };
        });

        dragging = {
            stepId: id,
            offsetX: e.clientX - (step.x + rect.left - canvas.scrollLeft),
            offsetY: e.clientY - (step.y + rect.top - canvas.scrollTop),
            startPositions,
            moved: false
        };
    }

    function onStepDblClick(e, step) {
        e.stopPropagation();
        // Inline rename
        const el = step.el;
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'pm-rename-input';
        input.value = step.label;
        input.style.left = el.style.left;
        input.style.top = el.style.top;
        input.style.width = el.offsetWidth + 'px';
        input.style.height = el.offsetHeight + 'px';

        const finish = () => {
            step.label = input.value;
            el.childNodes.forEach(n => { if (n.nodeType === 3) n.textContent = step.label || '(unnamed)'; });
            // Update first text node
            el.firstChild.textContent = step.label || '(unnamed)';
            input.remove();
            markDirty();
            renderConnectors();
            if (selectedStepIds.has(step.id || step.tempId)) {
                document.getElementById('detailLabel').value = step.label;
            }
        };

        input.addEventListener('blur', finish);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') input.blur();
            if (e.key === 'Escape') { input.value = step.label; input.blur(); }
        });

        canvas.appendChild(input);
        input.focus();
        input.select();
    }

    // =========================================================
    //  Canvas events (drag, rubber-band, keys)
    // =========================================================
    function bindCanvasEvents() {
        canvas.addEventListener('mousedown', onCanvasMouseDown);
        document.addEventListener('mousemove', onDocMouseMove);
        document.addEventListener('mouseup', onDocMouseUp);
        canvas.addEventListener('click', onCanvasClick);
        canvas.addEventListener('keydown', onCanvasKeyDown);
    }

    function onCanvasMouseDown(e) {
        if (e.target !== canvas && !e.target.classList.contains('pm-canvas-empty')) return;
        if (!currentProcessId) return;
        canvas.focus({ preventScroll: true });

        // Start rubber-band selection
        const rect = canvas.getBoundingClientRect();
        const sx = e.clientX - rect.left + canvas.scrollLeft;
        const sy = e.clientY - rect.top + canvas.scrollTop;

        const box = document.createElement('div');
        box.className = 'pm-selection-box';
        box.style.left = sx + 'px';
        box.style.top = sy + 'px';
        box.style.width = '0px';
        box.style.height = '0px';
        canvas.appendChild(box);

        rubberBand = { startX: sx, startY: sy, el: box };
    }

    function onDocMouseMove(e) {
        // Dragging a lane (reorder via header / resize via divider)
        if (laneDragging) {
            onLaneDocMouseMove(e);
            return;
        }
        // Dragging or resizing a group
        if (groupDragging) {
            onGroupDocMouseMove(e);
            return;
        }
        // Dragging step(s)
        if (dragging) {
            dragging.moved = true;
            const rect = canvas.getBoundingClientRect();
            const mx = e.clientX - rect.left + canvas.scrollLeft - dragging.offsetX;
            const my = e.clientY - rect.top + canvas.scrollTop - dragging.offsetY;
            const primary = getStep(dragging.stepId);
            if (!primary) return;
            const dx = snap(mx) - dragging.startPositions[dragging.stepId].x;
            const dy = snap(my) - dragging.startPositions[dragging.stepId].y;

            selectedStepIds.forEach(sid => {
                const s = getStep(sid);
                if (!s) return;
                const sp = dragging.startPositions[sid];
                if (!sp) return;
                s.x = Math.max(0, sp.x + dx);
                s.y = Math.max(0, sp.y + dy);
                if (s.el) {
                    s.el.style.left = s.x + 'px';
                    s.el.style.top = s.y + 'px';
                }
            });
            renderConnectors();
            updateDetailPosition();
            return;
        }

        // Drawing connector
        if (connectDrag) {
            const rect = canvas.getBoundingClientRect();
            const mx = e.clientX - rect.left + canvas.scrollLeft;
            const my = e.clientY - rect.top + canvas.scrollTop;
            let tempLine = svg.querySelector('.pm-temp-connector');
            if (!tempLine) {
                tempLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                tempLine.classList.add('pm-temp-connector');
                svg.appendChild(tempLine);
            }
            tempLine.setAttribute('x1', connectDrag.startX);
            tempLine.setAttribute('y1', connectDrag.startY);
            tempLine.setAttribute('x2', mx);
            tempLine.setAttribute('y2', my);
            return;
        }

        // Rubber-band
        if (rubberBand) {
            const rect = canvas.getBoundingClientRect();
            const mx = e.clientX - rect.left + canvas.scrollLeft;
            const my = e.clientY - rect.top + canvas.scrollTop;
            const x = Math.min(rubberBand.startX, mx);
            const y = Math.min(rubberBand.startY, my);
            const w = Math.abs(mx - rubberBand.startX);
            const h = Math.abs(my - rubberBand.startY);
            rubberBand.el.style.left = x + 'px';
            rubberBand.el.style.top = y + 'px';
            rubberBand.el.style.width = w + 'px';
            rubberBand.el.style.height = h + 'px';
        }
    }

    function onDocMouseUp(e) {
        if (laneDragging) {
            onLaneDocMouseUp();
            return;
        }
        if (groupDragging) {
            onGroupDocMouseUp();
            return;
        }
        if (dragging) {
            if (dragging.moved) {
                // After a step drag, auto-assign lane_id for each moved step.
                reassignStepLanes([...selectedStepIds]);
                markDirty();
            }
            dragging = null;
            return;
        }

        if (connectDrag) {
            svg.querySelectorAll('.pm-temp-connector').forEach(el => el.remove());
            // Find target step under cursor
            const rect = canvas.getBoundingClientRect();
            const mx = e.clientX - rect.left + canvas.scrollLeft;
            const my = e.clientY - rect.top + canvas.scrollTop;
            const target = findStepAt(mx, my);
            if (target && (target.id || target.tempId) !== connectDrag.fromStepId) {
                addConnector(connectDrag.fromStepId, target.id || target.tempId);
            }
            connectDrag = null;
            return;
        }

        if (rubberBand) {
            // Select steps within the box
            const bx = parseInt(rubberBand.el.style.left);
            const by = parseInt(rubberBand.el.style.top);
            const bw = parseInt(rubberBand.el.style.width);
            const bh = parseInt(rubberBand.el.style.height);
            rubberBand.el.remove();
            rubberBand = null;

            if (bw < 5 && bh < 5) {
                // Tiny box = just a click, deselect
                if (!e.ctrlKey && !e.metaKey) {
                    selectedStepIds.clear();
                    selectedConnectorId = null;
                    updateSelectionVisuals();
                    closeDetail();
                }
                return;
            }

            if (!e.ctrlKey && !e.metaKey) selectedStepIds.clear();

            steps.forEach(s => {
                const scx = s.x + s.width / 2;
                const scy = s.y + s.height / 2;
                if (scx >= bx && scx <= bx + bw && scy >= by && scy <= by + bh) {
                    selectedStepIds.add(s.id || s.tempId);
                }
            });
            updateSelectionVisuals();
        }
    }

    function onCanvasClick(e) {
        if (e.target === canvas || e.target.classList.contains('pm-canvas-empty')) {
            if (!rubberBand) {
                selectedStepIds.clear();
                selectedConnectorId = null;
                selectedGroupId = null;
                selectedLaneId = null;
                updateSelectionVisuals();
                closeDetail();
            }
        }
    }

    function onCanvasKeyDown(e) {
        // Delete key
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            e.preventDefault();
            deleteSelected();
            return;
        }

        // Arrow keys to nudge selected steps
        const arrowKeys = { ArrowUp: [0, -GRID], ArrowDown: [0, GRID], ArrowLeft: [-GRID, 0], ArrowRight: [GRID, 0] };
        if (arrowKeys[e.key] && selectedStepIds.size > 0) {
            e.preventDefault();
            const [dx, dy] = arrowKeys[e.key];
            selectedStepIds.forEach(sid => {
                const s = getStep(sid);
                if (!s) return;
                s.x = Math.max(0, s.x + dx);
                s.y = Math.max(0, s.y + dy);
                if (s.el) {
                    s.el.style.left = s.x + 'px';
                    s.el.style.top = s.y + 'px';
                }
            });
            renderConnectors();
            updateDetailPosition();
            markDirty();
            return;
        }

        // Ctrl+S save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            save();
            return;
        }

        // Ctrl+A select all
        if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
            e.preventDefault();
            steps.forEach(s => selectedStepIds.add(s.id || s.tempId));
            updateSelectionVisuals();
        }
    }

    // =========================================================
    //  Connector dragging from edge handles
    // =========================================================
    function startConnectDrag(e, step, side) {
        e.stopPropagation();
        e.preventDefault();

        const id = step.id || step.tempId;
        let sx, sy;

        switch (side) {
            case 'top':    sx = step.x + step.width / 2; sy = step.y; break;
            case 'bottom': sx = step.x + step.width / 2; sy = step.y + step.height; break;
            case 'left':   sx = step.x; sy = step.y + step.height / 2; break;
            case 'right':  sx = step.x + step.width; sy = step.y + step.height / 2; break;
        }

        connectDrag = { fromStepId: id, side, startX: sx, startY: sy };
    }

    function findStepAt(x, y) {
        return steps.find(s =>
            x >= s.x && x <= s.x + s.width &&
            y >= s.y && y <= s.y + s.height
        );
    }

    // =========================================================
    //  Add / remove steps and connectors
    // =========================================================
    function addStep(type) {
        if (!currentProcessId) { toast('Open or create a process first', 'error'); return; }

        const tempId = nextTempId--;
        const cx = canvas.scrollLeft + canvas.clientWidth / 2 - 80;
        const cy = canvas.scrollTop + canvas.clientHeight / 2 - 40;
        const w = type === 'decision' ? 140 : 160;
        const h = type === 'decision' ? 140 : (type === 'start' ? 50 : 80);
        const colors = {
            process: '#0078d4',
            decision: '#f59e0b',
            start: '#10b981',
            document: '#8764b8'
        };

        const step = {
            tempId,
            type,
            label: type === 'start' ? 'Start' : '',
            description: '',
            x: snap(cx),
            y: snap(cy),
            width: w,
            height: h,
            color: colors[type] || '#0078d4',
            color2: null,
            lane_id: null,
            el: null
        };
        // If the new step lands inside a lane band, assign that lane immediately.
        const containingLane = laneAtY(step.y + step.height / 2);
        if (containingLane) step.lane_id = laneRef(containingLane);

        steps.push(step);
        step.el = createStepEl(step);
        canvas.appendChild(step.el);
        canvasEmpty.style.display = 'none';

        selectedStepIds.clear();
        selectedStepIds.add(tempId);
        updateSelectionVisuals();
        showDetailForStep(step);
        markDirty();
    }

    // =========================================================
    //  Groups (visual underlays)
    // =========================================================

    function addGroup() {
        if (!currentProcessId) { toast('Open or create a process first', 'error'); return; }
        const tempId = nextTempId--;
        const w = 240, h = 160;
        const x = snap(canvas.scrollLeft + canvas.clientWidth / 2 - w / 2);
        const y = snap(canvas.scrollTop + canvas.clientHeight / 2 - h / 2);
        const group = {
            tempId,
            label: '',
            color: '#e3f2fd',
            x, y, width: w, height: h,
            el: null
        };
        groups.push(group);
        group.el = createGroupEl(group);
        canvas.appendChild(group.el);
        canvasEmpty.style.display = 'none';
        selectGroup(group);
        markDirty();
    }

    function createGroupEl(group) {
        const el = document.createElement('div');
        el.className = 'pm-group';
        el.dataset.groupId = group.id || group.tempId;
        applyGroupStyle(el, group);

        // Label across the top
        const label = document.createElement('div');
        label.className = 'pm-group-label';
        label.textContent = group.label || '';
        el.appendChild(label);

        // Resize handle (bottom-right corner)
        const handle = document.createElement('div');
        handle.className = 'pm-group-resize';
        el.appendChild(handle);

        el.addEventListener('mousedown', e => onGroupMouseDown(e, group));
        el.addEventListener('dblclick', e => {
            // Quick-rename: focus the detail panel label input
            e.stopPropagation();
            selectGroup(group);
            const input = document.getElementById('detailGroupLabel');
            if (input) { input.focus(); input.select(); }
        });
        return el;
    }

    function applyGroupStyle(el, group) {
        el.style.left   = group.x + 'px';
        el.style.top    = group.y + 'px';
        el.style.width  = group.width + 'px';
        el.style.height = group.height + 'px';
        el.style.background = fillStyle(group.color, group.color2);
        // Border darkens the primary fill — keeps the outline visible against
        // both solid and gradient backgrounds without having to recompute per stop.
        el.style.borderColor = shade(group.color, -25);
        const labelEl = el.querySelector('.pm-group-label');
        if (labelEl) labelEl.textContent = group.label || '';
    }

    // Returns the right CSS background string: gradient when color2 is set, otherwise solid.
    function fillStyle(color, color2) {
        if (color2 && color2 !== '') {
            return `linear-gradient(135deg, ${color}, ${color2})`;
        }
        return color;
    }

    // Light/darken a #rrggbb colour by `amt` units per channel (negative = darker).
    function shade(hex, amt) {
        if (!/^#[0-9a-f]{6}$/i.test(hex)) return hex;
        let r = parseInt(hex.slice(1, 3), 16);
        let g = parseInt(hex.slice(3, 5), 16);
        let b = parseInt(hex.slice(5, 7), 16);
        r = Math.max(0, Math.min(255, r + amt));
        g = Math.max(0, Math.min(255, g + amt));
        b = Math.max(0, Math.min(255, b + amt));
        return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
    }

    function getGroup(id) {
        return groups.find(g => g.id == id || g.tempId == id);
    }

    function selectGroup(g) {
        // Group selection is exclusive — clear step + connector selection.
        selectedStepIds.clear();
        selectedConnectorId = null;
        selectedGroupId = g.id || g.tempId;
        canvas.focus({ preventScroll: true });
        updateSelectionVisuals();
        showDetailForGroup(g);
    }

    function onGroupMouseDown(e, group) {
        if (e.button !== 0) return;
        // Resize handle? Start resize. Otherwise move.
        const isResize = e.target.classList.contains('pm-group-resize');
        e.stopPropagation();
        e.preventDefault();
        selectGroup(group);

        groupDragging = isResize
            ? { id: group.id || group.tempId, mode: 'resize', startMouseX: e.clientX, startMouseY: e.clientY, startW: group.width, startH: group.height, moved: false }
            : { id: group.id || group.tempId, mode: 'move',   startMouseX: e.clientX, startMouseY: e.clientY, startX: group.x, startY: group.y,         moved: false };
    }

    function onGroupDocMouseMove(e) {
        if (!groupDragging) return;
        const g = getGroup(groupDragging.id);
        if (!g) return;
        const dx = e.clientX - groupDragging.startMouseX;
        const dy = e.clientY - groupDragging.startMouseY;
        if (Math.abs(dx) + Math.abs(dy) > 2) groupDragging.moved = true;
        if (groupDragging.mode === 'move') {
            g.x = Math.max(0, snap(groupDragging.startX + dx));
            g.y = Math.max(0, snap(groupDragging.startY + dy));
        } else {
            g.width  = Math.max(80, snap(groupDragging.startW + dx));
            g.height = Math.max(60, snap(groupDragging.startH + dy));
        }
        applyGroupStyle(g.el, g);
        // Reflect into open detail panel so the numeric inputs track the drag.
        if (selectedGroupId == groupDragging.id) {
            document.getElementById('detailGroupX').value = g.x;
            document.getElementById('detailGroupY').value = g.y;
            document.getElementById('detailGroupW').value = g.width;
            document.getElementById('detailGroupH').value = g.height;
        }
    }

    function onGroupDocMouseUp() {
        if (!groupDragging) return;
        if (groupDragging.moved) markDirty();
        groupDragging = null;
    }

    function showDetailForGroup(g) {
        detailPanel.classList.add('open');
        document.getElementById('detailBodyStep').style.display = 'none';
        document.getElementById('detailBodyGroup').style.display = '';
        document.getElementById('detailTitle').textContent = 'Group Details';
        document.getElementById('detailGroupLabel').value = g.label || '';
        document.getElementById('detailGroupColor').value = g.color || '#e3f2fd';
        document.getElementById('detailGroupX').value = g.x;
        document.getElementById('detailGroupY').value = g.y;
        document.getElementById('detailGroupW').value = g.width;
        document.getElementById('detailGroupH').value = g.height;
        const useGrad = !!g.color2;
        const gradCb = document.getElementById('detailGroupGradient');
        const grad2  = document.getElementById('detailGroupColor2');
        gradCb.checked = useGrad;
        grad2.value = g.color2 || shade(g.color || '#e3f2fd', -40);
        grad2.style.display = useGrad ? '' : 'none';
        detailPanel.dataset.groupId = g.id || g.tempId;
        detailPanel.dataset.stepId = '';
    }

    function updateGroupFromDetail() {
        const id = detailPanel.dataset.groupId;
        if (!id) return;
        const g = getGroup(id);
        if (!g) return;
        g.label  = document.getElementById('detailGroupLabel').value;
        g.color  = document.getElementById('detailGroupColor').value;
        g.x      = parseInt(document.getElementById('detailGroupX').value, 10) || 0;
        g.y      = parseInt(document.getElementById('detailGroupY').value, 10) || 0;
        g.width  = Math.max(80, parseInt(document.getElementById('detailGroupW').value, 10) || 240);
        g.height = Math.max(60, parseInt(document.getElementById('detailGroupH').value, 10) || 160);

        const useGrad = document.getElementById('detailGroupGradient').checked;
        const grad2El = document.getElementById('detailGroupColor2');
        grad2El.style.display = useGrad ? '' : 'none';
        g.color2 = useGrad ? grad2El.value : null;

        if (g.el) applyGroupStyle(g.el, g);
        markDirty();
    }

    // =========================================================
    //  Lanes (swimlanes — structured with step ownership)
    // =========================================================

    function getLane(id) {
        return lanes.find(l => (l.id != null && l.id == id) || l.tempId == id);
    }

    function laneRef(lane) {
        return lane.id != null ? lane.id : lane.tempId;
    }

    function lanesOrdered() {
        return [...lanes].sort((a, b) => (a.display_order || 0) - (b.display_order || 0));
    }

    // Cache each lane's bandTop on the lane object after sorting. Run before any
    // hit-test or render pass that needs lane Y coordinates.
    function recomputeLaneBandTops() {
        let top = 0;
        for (const l of lanesOrdered()) {
            l._bandTop = top;
            top += l.height;
        }
    }

    function laneAtY(y) {
        recomputeLaneBandTops();
        for (const l of lanes) {
            if (y >= l._bandTop && y < l._bandTop + l.height) return l;
        }
        return null;
    }

    function renderLanes() {
        canvas.querySelectorAll('.pm-lane').forEach(el => el.remove());
        recomputeLaneBandTops();
        for (const l of lanesOrdered()) {
            l.el = createLaneEl(l);
            // Insert at the start of the canvas so lanes sit behind everything
            canvas.insertBefore(l.el, canvas.firstChild);
        }
        updateLaneSelectionVisuals();
    }

    function createLaneEl(lane) {
        const el = document.createElement('div');
        el.className = 'pm-lane';
        el.dataset.laneId = laneRef(lane);
        applyLaneStyle(el, lane);

        const header = document.createElement('div');
        header.className = 'pm-lane-header';
        header.textContent = lane.label || '(unnamed lane)';
        header.title = 'Drag up/down to reorder. Click to select.';
        header.addEventListener('mousedown', e => onLaneHeaderMouseDown(e, lane));
        el.appendChild(header);
        lane.headerEl = header;

        const divider = document.createElement('div');
        divider.className = 'pm-lane-divider';
        divider.title = 'Drag to resize';
        divider.addEventListener('mousedown', e => onLaneDividerMouseDown(e, lane));
        el.appendChild(divider);

        return el;
    }

    function applyLaneStyle(el, lane) {
        el.style.top    = lane._bandTop + 'px';
        el.style.left   = '0px';
        el.style.width  = LANE_WIDTH + 'px';
        el.style.height = lane.height + 'px';
        el.style.background = fillStyle(lane.color || '#f5f7fa', lane.color2);
        const headerEl = el.querySelector('.pm-lane-header');
        if (headerEl) headerEl.textContent = lane.label || '(unnamed lane)';
    }

    function updateLaneSelectionVisuals() {
        canvas.querySelectorAll('.pm-lane').forEach(el => {
            el.classList.toggle('selected', el.dataset.laneId == selectedLaneId);
        });
    }

    function addLane() {
        if (!currentProcessId) { toast('Open or create a process first', 'error'); return; }
        const tempId = nextTempId--;
        // Suggest a max(display_order)+1 so the new lane is placed at the bottom.
        const maxOrder = lanes.reduce((m, l) => Math.max(m, l.display_order || 0), -1);
        const lane = {
            tempId,
            label: 'Lane ' + (lanes.length + 1),
            color: '#f5f7fa',
            color2: null,
            display_order: maxOrder + 1,
            height: 180,
            el: null,
            headerEl: null,
            _bandTop: 0
        };
        lanes.push(lane);
        renderLanes();
        renderConnectors();
        selectLane(lane);
        canvasEmpty.style.display = 'none';
        markDirty();
    }

    function selectLane(lane) {
        // Exclusive single-select: clear step / connector / group selection.
        selectedStepIds.clear();
        selectedConnectorId = null;
        selectedGroupId = null;
        selectedLaneId = laneRef(lane);
        canvas.focus({ preventScroll: true });
        updateSelectionVisuals();
        showDetailForLane(lane);
    }

    function showDetailForLane(lane) {
        detailPanel.classList.add('open');
        document.getElementById('detailBodyStep').style.display = 'none';
        document.getElementById('detailBodyGroup').style.display = 'none';
        document.getElementById('detailBodyLane').style.display = '';
        document.getElementById('detailTitle').textContent = 'Lane Details';
        document.getElementById('detailLaneLabel').value = lane.label || '';
        document.getElementById('detailLaneColor').value = lane.color || '#f5f7fa';
        document.getElementById('detailLaneHeight').value = lane.height;
        document.getElementById('detailLaneOrder').value = lane.display_order;
        const useGrad = !!lane.color2;
        const gradCb = document.getElementById('detailLaneGradient');
        const grad2  = document.getElementById('detailLaneColor2');
        gradCb.checked = useGrad;
        grad2.value = lane.color2 || shade(lane.color || '#f5f7fa', -40);
        grad2.style.display = useGrad ? '' : 'none';
        detailPanel.dataset.laneId = laneRef(lane);
        detailPanel.dataset.stepId = '';
        detailPanel.dataset.groupId = '';
    }

    function updateLaneFromDetail() {
        const id = detailPanel.dataset.laneId;
        if (!id) return;
        const lane = getLane(id);
        if (!lane) return;

        lane.label = document.getElementById('detailLaneLabel').value;
        lane.color = document.getElementById('detailLaneColor').value;
        const newHeight = Math.max(80, parseInt(document.getElementById('detailLaneHeight').value, 10) || 180);
        const newOrder  = parseInt(document.getElementById('detailLaneOrder').value, 10);
        const useGrad = document.getElementById('detailLaneGradient').checked;
        const grad2El = document.getElementById('detailLaneColor2');
        grad2El.style.display = useGrad ? '' : 'none';
        lane.color2 = useGrad ? grad2El.value : null;

        if (newHeight !== lane.height) {
            resizeLaneTo(lane, newHeight);
        }
        if (!isNaN(newOrder) && newOrder !== lane.display_order) {
            reorderLaneTo(lane, newOrder);
        }
        renderLanes();
        renderAllSteps();
        renderConnectors();
        markDirty();
    }

    // Re-render only steps (cheap re-render that preserves selection visuals).
    function renderAllSteps() {
        canvas.querySelectorAll('.pm-step').forEach(el => el.remove());
        steps.forEach(s => {
            s.el = createStepEl(s);
            canvas.appendChild(s.el);
        });
        updateSelectionVisuals();
    }

    // Resize lane to `newHeight`, shifting all steps in lanes below by the delta
    // (so they stay anchored to their own lane band as their bandTop moves).
    function resizeLaneTo(lane, newHeight) {
        const delta = newHeight - lane.height;
        if (delta === 0) return;
        lane.height = Math.max(80, newHeight);
        const ord = lanesOrdered();
        const myIdx = ord.findIndex(l => laneRef(l) == laneRef(lane));
        const idsBelow = new Set(ord.slice(myIdx + 1).map(l => laneRef(l)));
        steps.forEach(s => {
            if (s.lane_id != null && idsBelow.has(s.lane_id)) {
                s.y = snap(s.y + delta);
            }
        });
    }

    // Reorder lane to display_order = targetOrder. Steps in every affected lane
    // are shifted so they stay anchored to their lane band.
    function reorderLaneTo(lane, targetOrder) {
        if (lanes.length < 2) { lane.display_order = 0; return; }

        // Snapshot each step's offset within its current lane before we move things.
        recomputeLaneBandTops();
        const stepYWithinLane = new Map(); // step.id|tempId -> { laneRef, yWithinLane }
        steps.forEach(s => {
            if (s.lane_id == null) return;
            const l = getLane(s.lane_id);
            if (!l) return;
            stepYWithinLane.set(s.id || s.tempId, { laneRef: s.lane_id, yWithinLane: s.y - l._bandTop });
        });

        // Move dragged lane to its new slot.
        const ord = lanesOrdered().filter(l => laneRef(l) != laneRef(lane));
        const clamped = Math.max(0, Math.min(ord.length, targetOrder));
        ord.splice(clamped, 0, lane);
        ord.forEach((l, i) => { l.display_order = i; });

        // Recompute bandTops and reapply each step's offset against its lane's new bandTop.
        recomputeLaneBandTops();
        steps.forEach(s => {
            const meta = stepYWithinLane.get(s.id || s.tempId);
            if (!meta) return;
            const l = getLane(meta.laneRef);
            if (!l) return;
            s.y = snap(l._bandTop + meta.yWithinLane);
        });
    }

    // Drag-to-reorder via the left-edge header. While dragging the header we
    // visually translate the lane element with the cursor; on mouseup we work
    // out which slot the cursor landed in and call reorderLaneTo().
    function onLaneHeaderMouseDown(e, lane) {
        if (e.button !== 0) return;
        e.stopPropagation();
        e.preventDefault();
        // Tag every visually-inside-a-lane step with its lane_id so the reorder
        // pass moves them too. Catches steps that were created before lanes
        // existed or that have stale/missing lane_id values.
        autoAssignAllStepLanes();
        selectLane(lane);
        laneDragging = {
            id: laneRef(lane),
            mode: 'reorder',
            startMouseY: e.clientY,
            origOrder: lane.display_order,
            moved: false
        };
        if (lane.el) lane.el.classList.add('pm-lane-dragging');
    }

    function onLaneDividerMouseDown(e, lane) {
        if (e.button !== 0) return;
        e.stopPropagation();
        e.preventDefault();
        autoAssignAllStepLanes();
        selectLane(lane);
        laneDragging = {
            id: laneRef(lane),
            mode: 'resize',
            startMouseY: e.clientY,
            startHeight: lane.height,
            moved: false
        };
    }

    function onLaneDocMouseMove(e) {
        if (!laneDragging) return;
        const lane = getLane(laneDragging.id);
        if (!lane) return;
        const dy = e.clientY - laneDragging.startMouseY;
        if (Math.abs(dy) > 2) laneDragging.moved = true;

        if (laneDragging.mode === 'resize') {
            const targetH = Math.max(80, laneDragging.startHeight + dy);
            if (targetH !== lane.height) {
                resizeLaneTo(lane, targetH);
                renderLanes();
                renderAllSteps();
                renderConnectors();
                // Reflect in detail panel if open for this lane
                if (selectedLaneId == laneDragging.id) {
                    document.getElementById('detailLaneHeight').value = lane.height;
                }
            }
            return;
        }

        // Reorder: count how many non-dragged lanes have their visual midline
        // ABOVE the cursor — that's how many lanes should sit above the dragged
        // lane in the final order. Important: we accumulate `acc` for every lane
        // including the dragged one, because the dragged lane is still occupying
        // its visual slot until we commit the swap.
        const ord = lanesOrdered();
        const rect = canvas.getBoundingClientRect();
        const cursorY = e.clientY - rect.top + canvas.scrollTop;
        let targetOrder = 0;
        let acc = 0;
        for (const l of ord) {
            const mid = acc + l.height / 2;
            if (laneRef(l) != laneDragging.id && cursorY > mid) targetOrder++;
            acc += l.height;
        }
        if (targetOrder !== lane.display_order) {
            reorderLaneTo(lane, targetOrder);
            renderLanes();
            renderAllSteps();
            renderConnectors();
            if (selectedLaneId == laneDragging.id) {
                document.getElementById('detailLaneOrder').value = lane.display_order;
            }
        }
    }

    function onLaneDocMouseUp() {
        if (!laneDragging) return;
        if (laneDragging.moved) markDirty();
        const lane = getLane(laneDragging.id);
        if (lane && lane.el) lane.el.classList.remove('pm-lane-dragging');
        laneDragging = null;
    }

    // Called after a step drag completes — auto-assign lane_id based on
    // where each moved step ended up.
    function reassignStepLanes(movedIds) {
        if (!lanes.length) return;
        movedIds.forEach(sid => {
            const s = getStep(sid);
            if (!s) return;
            // Use the step's vertical centre for the hit test so a step
            // straddling a divider settles into whichever lane holds its middle.
            const lane = laneAtY(s.y + (s.height / 2));
            const newLaneId = lane ? laneRef(lane) : null;
            if (s.lane_id != newLaneId) s.lane_id = newLaneId;
        });
    }

    // Scan every step on the canvas and assign lane_id based on where it visually
    // sits. Run at the start of any lane drag so steps that *look* like they belong
    // to a lane (but never got tagged — e.g. steps that pre-existed the lanes, or
    // were dragged in before lanes existed) come along for the ride during reorder
    // and resize. Cheap and idempotent.
    function autoAssignAllStepLanes() {
        if (!lanes.length) return;
        recomputeLaneBandTops();
        steps.forEach(s => {
            const lane = laneAtY(s.y + (s.height / 2));
            const newLaneId = lane ? laneRef(lane) : null;
            if (s.lane_id != newLaneId) s.lane_id = newLaneId;
        });
    }

    function deleteSelectedLane() {
        if (!selectedLaneId) return;
        const lane = getLane(selectedLaneId);
        if (!lane) return;
        // Steps that belonged to this lane lose their lane assignment but keep their position.
        steps.forEach(s => {
            if (s.lane_id != null && s.lane_id == selectedLaneId) s.lane_id = null;
        });
        // Lanes below shift up by the deleted lane's height — steps in those lanes follow.
        const ord = lanesOrdered();
        const myIdx = ord.findIndex(l => laneRef(l) == selectedLaneId);
        const idsBelow = new Set(ord.slice(myIdx + 1).map(l => laneRef(l)));
        const shift = -lane.height;
        steps.forEach(s => {
            if (s.lane_id != null && idsBelow.has(s.lane_id)) {
                s.y = snap(s.y + shift);
            }
        });
        // Remove the lane and renumber display_order
        lanes = lanes.filter(l => laneRef(l) != selectedLaneId);
        lanesOrdered().forEach((l, i) => { l.display_order = i; });
        selectedLaneId = null;
        closeDetail();
        renderLanes();
        renderAllSteps();
        renderConnectors();
        markDirty();
    }

    function addConnector(fromId, toId) {
        // Check for duplicate
        const exists = connectors.some(c =>
            (c.fromId == fromId && c.toId == toId) ||
            (c.fromId == toId && c.toId == fromId)
        );
        if (exists) return;

        const tempId = nextTempId--;
        connectors.push({ tempId, fromId: +fromId, toId: +toId, label: '' });
        renderConnectors();
        markDirty();
    }

    function deleteSelected() {
        if (selectedLaneId) {
            deleteSelectedLane();
            return;
        }
        if (selectedGroupId) {
            const g = getGroup(selectedGroupId);
            if (g && g.el) g.el.remove();
            groups = groups.filter(gr => (gr.id || gr.tempId) != selectedGroupId);
            selectedGroupId = null;
            closeDetail();
            markDirty();
            return;
        }
        if (selectedConnectorId) {
            connectors = connectors.filter(c => (c.id || c.tempId) != selectedConnectorId);
            selectedConnectorId = null;
            renderConnectors();
            markDirty();
            return;
        }

        if (selectedStepIds.size === 0) return;

        selectedStepIds.forEach(sid => {
            const s = getStep(sid);
            if (s && s.el) s.el.remove();
            // Remove connectors attached to this step
            connectors = connectors.filter(c => c.fromId != sid && c.toId != sid);
        });
        steps = steps.filter(s => !selectedStepIds.has(s.id || s.tempId));
        selectedStepIds.clear();
        updateSelectionVisuals();
        renderConnectors();
        closeDetail();
        markDirty();
    }

    // =========================================================
    //  Selection
    // =========================================================
    function updateSelectionVisuals() {
        canvas.querySelectorAll('.pm-step').forEach(el => {
            const sid = el.dataset.stepId;
            el.classList.toggle('selected', selectedStepIds.has(+sid) || selectedStepIds.has(parseInt(sid)));
        });
        canvas.querySelectorAll('.pm-group').forEach(el => {
            el.classList.toggle('selected', el.dataset.groupId == selectedGroupId);
        });
        canvas.querySelectorAll('.pm-lane').forEach(el => {
            el.classList.toggle('selected', el.dataset.laneId == selectedLaneId);
        });
        renderConnectors();
    }

    function selectConnector(c) {
        selectedStepIds.clear();
        selectedConnectorId = c.id || c.tempId;
        canvas.focus({ preventScroll: true });
        updateSelectionVisuals();
        closeDetail();
    }

    // =========================================================
    //  Detail panel
    // =========================================================
    function showDetailForStep(step) {
        detailPanel.classList.add('open');
        document.getElementById('detailLabel').value = step.label;
        document.getElementById('detailType').value = step.type;
        document.getElementById('detailColor').value = step.color;
        document.getElementById('detailDescription').value = step.description || '';
        document.getElementById('detailX').value = step.x;
        document.getElementById('detailY').value = step.y;
        const useGrad = !!step.color2;
        const gradCb = document.getElementById('detailGradient');
        const grad2  = document.getElementById('detailColor2');
        gradCb.checked = useGrad;
        grad2.value = step.color2 || shade(step.color || '#0078d4', -40);
        grad2.style.display = useGrad ? '' : 'none';
        detailPanel.dataset.stepId = step.id || step.tempId;

        // Show connectors related to this step
        const sid = step.id || step.tempId;
        const related = connectors.filter(c => c.fromId == sid || c.toId == sid);
        const container = document.getElementById('detailConnectors');
        if (!related.length) {
            container.innerHTML = '<div style="color: #999; font-size: 12px;">No connectors</div>';
        } else {
            container.innerHTML = related.map(c => {
                const cid = c.id || c.tempId;
                const other = c.fromId == sid ? getStep(c.toId) : getStep(c.fromId);
                const dir = c.fromId == sid ? '&rarr;' : '&larr;';
                const otherName = other ? (other.label || '(unnamed)') : '?';
                return `<div class="pm-detail-connector">
                    <span>${dir} ${esc(otherName)}</span>
                    <input type="text" value="${esc(c.label)}" placeholder="Label..." onchange="PM.updateConnectorLabel(${cid}, this.value)">
                    <button class="pm-conn-delete" onclick="PM.removeConnector(${cid})">&times;</button>
                </div>`;
            }).join('');
        }
    }

    function closeDetail() {
        detailPanel.classList.remove('open');
        detailPanel.dataset.stepId = '';
        detailPanel.dataset.groupId = '';
        detailPanel.dataset.laneId = '';
        document.getElementById('detailBodyStep').style.display = '';
        document.getElementById('detailBodyGroup').style.display = 'none';
        document.getElementById('detailBodyLane').style.display = 'none';
        document.getElementById('detailTitle').textContent = 'Step Details';
    }

    function updateStepFromDetail() {
        const sid = detailPanel.dataset.stepId;
        if (!sid) return;
        const step = getStep(+sid);
        if (!step) return;

        step.label = document.getElementById('detailLabel').value;
        step.type = document.getElementById('detailType').value;
        step.color = document.getElementById('detailColor').value;
        step.description = document.getElementById('detailDescription').value;
        step.x = snap(+document.getElementById('detailX').value || 0);
        step.y = snap(+document.getElementById('detailY').value || 0);

        const useGrad = document.getElementById('detailGradient').checked;
        const grad2El = document.getElementById('detailColor2');
        grad2El.style.display = useGrad ? '' : 'none';
        step.color2 = useGrad ? grad2El.value : null;

        // Re-render step element
        if (step.el) step.el.remove();
        step.el = createStepEl(step);
        canvas.appendChild(step.el);
        if (selectedStepIds.has(step.id || step.tempId)) {
            step.el.classList.add('selected');
        }
        renderConnectors();
        markDirty();
    }

    function updateDetailPosition() {
        const sid = detailPanel.dataset.stepId;
        if (!sid) return;
        const step = getStep(+sid);
        if (!step) return;
        document.getElementById('detailX').value = step.x;
        document.getElementById('detailY').value = step.y;
    }

    function updateConnectorLabel(cid, value) {
        const c = connectors.find(c => (c.id || c.tempId) == cid);
        if (c) {
            c.label = value;
            renderConnectors();
            markDirty();
        }
    }

    function removeConnector(cid) {
        connectors = connectors.filter(c => (c.id || c.tempId) != cid);
        renderConnectors();
        // Refresh detail panel connector list
        const sid = detailPanel.dataset.stepId;
        if (sid) {
            const step = getStep(+sid);
            if (step) showDetailForStep(step);
        }
        markDirty();
    }

    function editConnectorLabel(c, mx, my) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'pm-rename-input';
        input.value = c.label;
        input.style.left = (mx - 60) + 'px';
        input.style.top = (my - 14) + 'px';
        input.style.width = '120px';
        input.style.height = '28px';

        const finish = () => {
            c.label = input.value;
            input.remove();
            renderConnectors();
            markDirty();
        };

        input.addEventListener('blur', finish);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') input.blur();
            if (e.key === 'Escape') { input.value = c.label; input.blur(); }
        });

        canvas.appendChild(input);
        input.focus();
        input.select();
    }

    // =========================================================
    //  Toolbar
    // =========================================================
    function bindToolbar() {
        document.querySelectorAll('.pm-tool-btn[data-type]').forEach(btn => {
            btn.addEventListener('click', () => addStep(btn.dataset.type));
        });

        document.getElementById('connectBtn').addEventListener('click', function() {
            connectMode = !connectMode;
            this.classList.toggle('active', connectMode);
        });
    }

    // =========================================================
    //  Save
    // =========================================================
    // `isAutosave` controls toast suppression and re-scheduling on retry.
    async function save(isAutosave = false) {
        if (!currentProcessId) {
            if (!isAutosave) toast('No process open', 'error');
            return;
        }
        if (saveInFlight) return;
        saveInFlight = true;
        clearTimeout(autosaveTimer);
        setStatus('saving');
        const startedAt = Date.now();

        const title = processes.find(p => p.id == currentProcessId)?.title || 'Untitled';
        const payload = {
            id: currentProcessId,
            title,
            steps: steps.map(s => ({
                id: s.id || null,
                tempId: s.tempId || null,
                type: s.type,
                label: s.label,
                description: s.description,
                x: s.x,
                y: s.y,
                width: s.width,
                height: s.height,
                color: s.color,
                color2: s.color2 || null,
                lane_id: s.lane_id != null ? s.lane_id : null
            })),
            connectors: connectors.map(c => ({
                id: c.id || null,
                from_step_id: c.fromId,
                to_step_id: c.toId,
                label: c.label
            })),
            groups: groups.map(g => ({
                id: g.id || null,
                label: g.label,
                color: g.color,
                color2: g.color2 || null,
                x: g.x,
                y: g.y,
                width: g.width,
                height: g.height
            })),
            lanes: lanes.map(l => ({
                id: l.id || null,
                tempId: l.tempId || null,
                label: l.label,
                color: l.color,
                color2: l.color2 || null,
                display_order: l.display_order,
                height: l.height
            }))
        };

        let ok = false;
        let errMsg = '';
        let newId = null;
        try {
            const r = await fetch(API_BASE + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                ok = true;
                newId = d.id;
            } else {
                errMsg = d.error || 'Failed to save';
            }
        } catch (e) {
            errMsg = 'Network error';
        }

        // Keep "Saving…" visible for at least MIN_SAVING_VISIBLE_MS so users
        // can actually see that something happened on fast saves.
        const elapsed = Date.now() - startedAt;
        if (elapsed < MIN_SAVING_VISIBLE_MS) {
            await new Promise(r => setTimeout(r, MIN_SAVING_VISIBLE_MS - elapsed));
        }

        if (ok) {
            dirty = false;
            saveInFlight = false;
            // Reload to get real IDs (temp negative IDs from newly-added steps
            // need replacing with the server's auto-increment values).
            await openProcess(newId);
            // openProcess resets dirty + status, so set the "Saved" status AFTER it.
            setStatus('saved');
            if (!isAutosave) toast('Saved', 'success');
            // If edits arrived during the save, schedule another autosave.
            if (autosaveOn && dirty) scheduleAutosave();
        } else {
            saveInFlight = false;
            setStatus('failed');
            if (!isAutosave) toast(errMsg, 'error');
        }
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function getStep(id) {
        return steps.find(s => s.id == id || s.tempId == id);
    }

    function clearCanvas() {
        canvas.querySelectorAll('.pm-step').forEach(el => el.remove());
        canvas.querySelectorAll('.pm-group').forEach(el => el.remove());
        canvas.querySelectorAll('.pm-lane').forEach(el => el.remove());
        svg.querySelectorAll('.pm-connector-group').forEach(g => g.remove());
        steps = [];
        connectors = [];
        groups = [];
        lanes = [];
        selectedStepIds.clear();
        selectedConnectorId = null;
        selectedGroupId = null;
        selectedLaneId = null;
        dirty = false;
    }

    function toast(msg, type = 'success') {
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.className = 'toast show ' + type;
        setTimeout(() => { el.className = 'toast'; }, 2500);
    }

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // =========================================================
    //  Boot
    // =========================================================
    document.addEventListener('DOMContentLoaded', init);

    // Public API
    return {
        createProcess, deleteProcess, openProcess,
        filterProcesses, save, deleteSelected,
        closeDetail, updateStepFromDetail,
        updateConnectorLabel, removeConnector,
        toggleAutosave,
        addGroup, updateGroupFromDetail,
        addLane, updateLaneFromDetail
    };
})();
