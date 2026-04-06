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
    let selectedStepIds = new Set();
    let selectedConnectorId = null;
    let nextTempId = -1;
    let dirty = false;

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
                    el: null
                }));
                connectors = (d.data.connectors || []).map(c => ({
                    id: c.id,
                    fromId: +c.from_step_id,
                    toId: +c.to_step_id,
                    label: c.label || ''
                }));
                dirty = false;
                canvasEmpty.style.display = 'none';
                renderAll();
                renderProcessList();
                closeDetail();
            }
        } catch (e) { toast('Failed to load process', 'error'); }
    }

    // =========================================================
    //  Render
    // =========================================================
    function renderAll() {
        // Remove old step elements
        canvas.querySelectorAll('.pm-step').forEach(el => el.remove());
        // Render steps
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
        el.style.background = step.color;
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
            dirty = true;
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
        if (dragging) {
            if (dragging.moved) dirty = true;
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
            dirty = true;
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
            el: null
        };

        steps.push(step);
        step.el = createStepEl(step);
        canvas.appendChild(step.el);
        canvasEmpty.style.display = 'none';

        selectedStepIds.clear();
        selectedStepIds.add(tempId);
        updateSelectionVisuals();
        showDetailForStep(step);
        dirty = true;
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
        dirty = true;
    }

    function deleteSelected() {
        if (selectedConnectorId) {
            connectors = connectors.filter(c => (c.id || c.tempId) != selectedConnectorId);
            selectedConnectorId = null;
            renderConnectors();
            dirty = true;
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
        dirty = true;
    }

    // =========================================================
    //  Selection
    // =========================================================
    function updateSelectionVisuals() {
        canvas.querySelectorAll('.pm-step').forEach(el => {
            const sid = el.dataset.stepId;
            el.classList.toggle('selected', selectedStepIds.has(+sid) || selectedStepIds.has(parseInt(sid)));
        });
        renderConnectors();
    }

    function selectConnector(c) {
        selectedStepIds.clear();
        selectedConnectorId = c.id || c.tempId;
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

        // Re-render step element
        if (step.el) step.el.remove();
        step.el = createStepEl(step);
        canvas.appendChild(step.el);
        if (selectedStepIds.has(step.id || step.tempId)) {
            step.el.classList.add('selected');
        }
        renderConnectors();
        dirty = true;
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
            dirty = true;
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
        dirty = true;
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
            dirty = true;
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
    async function save() {
        if (!currentProcessId) { toast('No process open', 'error'); return; }

        const title = processes.find(p => p.id == currentProcessId)?.title || 'Untitled';
        const payload = {
            id: currentProcessId,
            title,
            steps: steps.map(s => ({
                id: s.id || null,
                type: s.type,
                label: s.label,
                description: s.description,
                x: s.x,
                y: s.y,
                width: s.width,
                height: s.height,
                color: s.color
            })),
            connectors: connectors.map(c => ({
                id: c.id || null,
                from_step_id: c.fromId,
                to_step_id: c.toId,
                label: c.label
            }))
        };

        try {
            const r = await fetch(API_BASE + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                dirty = false;
                toast('Saved', 'success');
                // Reload to get real IDs
                await openProcess(d.id);
            } else {
                toast(d.error || 'Failed to save', 'error');
            }
        } catch (e) { toast('Failed to save', 'error'); }
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function getStep(id) {
        return steps.find(s => s.id == id || s.tempId == id);
    }

    function clearCanvas() {
        canvas.querySelectorAll('.pm-step').forEach(el => el.remove());
        svg.querySelectorAll('.pm-connector-group').forEach(g => g.remove());
        steps = [];
        connectors = [];
        selectedStepIds.clear();
        selectedConnectorId = null;
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
        updateConnectorLabel, removeConnector
    };
})();
