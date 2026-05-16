/**
 * Network Mapper — editor module (chunks A + B + C + D part 1).
 *
 * Chunk D Part 1 adds connectors:
 *   - SVG layer behind nodes hosts connector paths + arrowhead defs
 *   - 4 edge handles per node (top/right/bottom/left), invisible until the
 *     node is hovered or selected. mousedown on a handle starts a connect
 *     drag — a dashed temp line tracks the cursor until mouseup. If mouseup
 *     lands on another node, a connector is created between source and
 *     target (free-form by default; cmdb_relationship_id stays NULL until
 *     part 2's relationships pull-in populates it from CMDB)
 *   - connectors store from_node_id/to_node_id as either the real node id
 *     (positive int from the server) or the tempId (negative int for nodes
 *     placed but not yet saved). save_diagram.php resolves both through
 *     its nodeIdMap, so connecting two brand-new nodes and hitting save
 *     works in a single round-trip
 *   - click a connector to select (cyan + thicker stroke); Delete removes
 *     it. Connector and node selections are mutually exclusive
 *   - double-click on a connector prompts for a label which renders mid-line
 *   - renderConnectors() is called after every node move/place/delete so
 *     lines track their endpoints; arrowheads are SVG markers (the same
 *     pattern Process Mapper uses)
 *
 * Convention: every exported entry point goes on window.NM so the inline
 * HTML can call NM.save(), NM.toggleAutosave() etc. without scope concerns.
 */
(function () {
    'use strict';

    const API = '../api/network-mapper/';
    const CMDB_API = '../api/cmdb/';
    const SYSTEM_API = '../api/system/';

    const AUTOSAVE_PREF_KEY = 'network_mapper_autosave';
    const AUTOSAVE_DEBOUNCE_MS = 2000;
    const MIN_SAVING_VISIBLE_MS = 400;
    const GRID = 20;                // snap-to-grid pitch, matches the canvas dot grid
    const NODE_SIZES = {            // pixel icon size per node.size enum value
        small: 40, medium: 56, large: 80
    };
    const NODE_PADDING = 6;         // matches .nm-node padding in diagram.php
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const SVG_SIZE = 5000;          // SVG layer width/height (matches Process Mapper)

    // ---- state ----
    let diagramId = 0;
    let diagram = null;          // metadata; null while loading
    let classes = [];            // CMDB classes for the palette
    let classById = {};          // id-keyed lookup, rebuilt after loadClasses
    let nodes = [];              // current canvas nodes
    let connectors = [];         // current canvas connectors
    let nextTempId = -1;         // tempIds count down so they never collide with real auto-inc IDs

    let dirty = false;
    let autosaveOn = false;
    let autosaveTimer = null;
    let saveInFlight = false;
    let lastSavingShownAt = 0;

    // ---- selection + drag state ----
    let selectedNodeKey = null;       // nodeKey() of currently selected node, or null
    let selectedConnectorKey = null;  // connectorKey() of currently selected connector, or null
    let nodeDrag = null;              // { key, offsetX, offsetY, startX, startY, moved }
    let connectDrag = null;           // { fromRef, startX, startY } while drawing a new connector
    let elSvgLayer = null;            // populated by ensureSvgLayer()

    // ---- picker state ----
    let pickerClassId = null;
    let pickerDropX = 0;
    let pickerDropY = 0;
    let pickerResults = [];      // last fetch's results, for keyboard selection
    let pickerHighlight = 0;
    let pickerSearchTimer = null;

    // ---- related-objects modal state ----
    let relatedSourceNode = null; // node we opened the modal for
    let relatedRows = [];         // [{ row index → fetched row object }]
    let relatedSelected = new Set(); // indices of ticked rows

    // ---- DOM refs (filled in init) ----
    let elTitle, elVersionPill, elMetaRow, elMetaAuthor, elMetaCreated, elMetaUpdated;
    let elStatus, elSaveBtn, elSaveVersionBtn, elAutosaveToggle, elAutosaveWrap;
    let elPaletteBody, elCanvas, elReadonlyBanner, elCanvasEmpty;
    let elPickerModal, elPickerClassLabel, elPickerSearch, elPickerResults;
    let elDetailPanel, elNdIcon, elNdName, elNdClass, elNdClassValue, elNdPlannedRow, elNdCmdbLink, elNdAddRelatedBtn;
    let elRelatedModal, elRmSourceName, elRmResults, elRmAddBtn;

    // =========================================================
    //  Initialisation
    // =========================================================
    function init(id) {
        diagramId = id;

        elTitle           = document.getElementById('diagramTitle');
        elVersionPill     = document.getElementById('versionPill');
        elMetaRow         = document.getElementById('metaRow');
        elMetaAuthor      = document.getElementById('metaAuthor');
        elMetaCreated     = document.getElementById('metaCreated');
        elMetaUpdated     = document.getElementById('metaUpdated');
        elStatus          = document.getElementById('saveStatus');
        elSaveBtn         = document.getElementById('saveBtn');
        elSaveVersionBtn  = document.getElementById('saveVersionBtn');
        elAutosaveToggle  = document.getElementById('nmAutosaveToggle');
        elAutosaveWrap    = document.getElementById('autosaveWrap');
        elPaletteBody     = document.getElementById('paletteBody');
        elCanvas          = document.getElementById('canvas');
        elReadonlyBanner  = document.getElementById('readonlyBanner');
        elCanvasEmpty     = document.getElementById('canvasEmpty');
        elPickerModal     = document.getElementById('objectPickerModal');
        elPickerClassLabel = document.getElementById('pickerClassLabel');
        elPickerSearch    = document.getElementById('pickerSearch');
        elPickerResults   = document.getElementById('pickerResults');
        elDetailPanel     = document.getElementById('nodeDetailPanel');
        elNdIcon          = document.getElementById('ndIcon');
        elNdName          = document.getElementById('ndName');
        elNdClass         = document.getElementById('ndClass');
        elNdClassValue    = document.getElementById('ndClassValue');
        elNdPlannedRow    = document.getElementById('ndPlannedRow');
        elNdCmdbLink      = document.getElementById('ndCmdbLink');
        elNdAddRelatedBtn = document.getElementById('ndAddRelatedBtn');
        elRelatedModal    = document.getElementById('relatedObjectsModal');
        elRmSourceName    = document.getElementById('rmSourceName');
        elRmResults       = document.getElementById('rmResults');
        elRmAddBtn        = document.getElementById('rmAddBtn');

        ensureSvgLayer();
        bindCanvasEvents();

        // Load diagram + palette + autosave preference in parallel
        Promise.all([loadDiagram(), loadClasses(), loadAutosavePreference()]).catch(() => {});
    }

    function bindCanvasEvents() {
        // Drop-target wiring: must preventDefault on dragover to permit drop
        elCanvas.addEventListener('dragover', e => {
            if (!diagram || !diagram.is_current) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        elCanvas.addEventListener('drop', onCanvasDrop);

        // Click on empty canvas / SVG-layer empty space clears selection
        elCanvas.addEventListener('mousedown', e => {
            // Treat the canvas background, the empty-state element, or the SVG
            // layer itself (not its children) as "empty space" — clicking
            // anything else (node, connector path, edge handle) deselects via
            // its own handler instead.
            const t = e.target;
            const isCanvasBg = t === elCanvas || t.classList.contains('nm-canvas-empty');
            const isSvgBg    = t === elSvgLayer;
            if (isCanvasBg || isSvgBg) {
                selectNode(null);
                selectConnector(null);
            }
        });

        // Document-level keyboard: Delete/Backspace removes whichever of node or
        // connector is selected, when the canvas (or one of its node children)
        // has focus / nothing else does
        document.addEventListener('keydown', e => {
            if (e.key !== 'Delete' && e.key !== 'Backspace') return;
            const tag = (document.activeElement && document.activeElement.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            // Don't fire if a modal is open (search inputs inside the picker etc.)
            if (document.querySelector('.nm-modal-overlay.active')) return;
            if (selectedConnectorKey != null) {
                e.preventDefault();
                deleteSelectedConnector();
            } else if (selectedNodeKey != null) {
                e.preventDefault();
                deleteSelectedNode();
            }
        });
    }

    // =========================================================
    //  SVG layer (connectors live here)
    // =========================================================
    function ensureSvgLayer() {
        if (elSvgLayer) return elSvgLayer;
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.classList.add('nm-svg-layer');
        svg.setAttribute('width', String(SVG_SIZE));
        svg.setAttribute('height', String(SVG_SIZE));
        const defs = document.createElementNS(SVG_NS, 'defs');
        defs.innerHTML =
            '<marker id="nm-arrow" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">' +
                '<polygon points="0 0, 10 3.5, 0 7" fill="#64748b"/>' +
            '</marker>' +
            '<marker id="nm-arrow-sel" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">' +
                '<polygon points="0 0, 10 3.5, 0 7" fill="#06b6d4"/>' +
            '</marker>';
        svg.appendChild(defs);
        // Insert before .nm-canvas-empty so the empty-state overlays the (empty)
        // SVG, and so future nodes (appended later by renderNodes) sit on top.
        elCanvas.insertBefore(svg, elCanvas.firstChild);
        elSvgLayer = svg;
        return svg;
    }

    // =========================================================
    //  Diagram + palette loading
    // =========================================================
    async function loadDiagram() {
        try {
            const resp = await fetch(API + 'get_diagram.php?id=' + diagramId);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load');
            diagram = data.diagram;
            nodes = data.nodes || [];
            connectors = data.connectors || [];
            renderHeader();
            applyReadOnlyState();
            renderNodes();
            setStatus(autosaveOn ? 'saved' : 'off');
        } catch (e) {
            elTitle.textContent = 'Failed to load diagram';
            elStatus.textContent = e.message;
        }
    }

    async function loadClasses() {
        try {
            const resp = await fetch(CMDB_API + 'get_classes.php');
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load classes');
            classes = (data.classes || []).filter(c => c.is_active);
            classById = {};
            classes.forEach(c => { classById[c.id] = c; });
            renderPalette();
        } catch (e) {
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">Failed to load classes: ' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderHeader() {
        document.title = 'FreeITSM — ' + (diagram.title || 'Network Diagram');
        elTitle.textContent = diagram.title || '(untitled)';

        const label = diagram.version_label || 'v?';
        if (diagram.is_current) {
            elVersionPill.className = 'nm-version-pill';
            elVersionPill.textContent = label + ' (current)';
        } else {
            elVersionPill.className = 'nm-version-pill readonly';
            elVersionPill.textContent = label + ' (read-only)';
        }
        elVersionPill.style.display = '';

        elMetaRow.style.display = '';
        elMetaAuthor.textContent  = diagram.author_name || 'Unknown';
        elMetaCreated.textContent = formatDate(diagram.created_datetime);
        elMetaUpdated.textContent = formatDate(diagram.updated_datetime);
    }

    function renderPalette() {
        if (!classes.length) {
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">No CMDB classes defined yet. <a href="../cmdb/settings/">Create one</a> to start dragging objects onto the diagram.</div>';
            return;
        }
        const html = classes.map(c => {
            const icon = window.nmRenderIcon ? window.nmRenderIcon(c.icon_key || 'box', 28) : '';
            const objCount = c.object_count || 0;
            return `
                <div class="nm-palette-tile" draggable="true" data-class-id="${c.id}" data-icon-key="${escapeAttr(c.icon_key || 'box')}" title="Drag onto the canvas (coming in chunk C)">
                    <div class="nm-palette-tile-icon">${icon}</div>
                    <div class="nm-palette-tile-name">${escapeHtml(c.name)}</div>
                    <div class="nm-palette-tile-count">${objCount} object${objCount === 1 ? '' : 's'}</div>
                </div>`;
        }).join('');
        elPaletteBody.innerHTML = html;

        // Drag-start: stash the class id for the drop handler to pick up in chunk C
        elPaletteBody.querySelectorAll('.nm-palette-tile').forEach(tile => {
            tile.addEventListener('dragstart', onTileDragStart);
        });
    }

    function onTileDragStart(e) {
        const classId = e.currentTarget.dataset.classId;
        e.dataTransfer.setData('text/plain', JSON.stringify({ kind: 'nm-class', class_id: parseInt(classId, 10) }));
        e.dataTransfer.effectAllowed = 'copy';
    }

    // =========================================================
    //  Read-only mode (historical version)
    // =========================================================
    function applyReadOnlyState() {
        if (diagram.is_current) {
            elReadonlyBanner.style.display = 'none';
            return;
        }
        elReadonlyBanner.style.display = '';
        elSaveBtn.disabled = true;
        elSaveBtn.title = 'This is a historical version — read-only';
        elAutosaveWrap.style.display = 'none';
        // Save-as-new-version on a non-leaf is refused by the backend (create_version
        // only forks from the leaf), so disable it here too.
        elSaveVersionBtn.disabled = true;
        elSaveVersionBtn.title = 'Only the current version can be forked into a new version';
    }

    // =========================================================
    //  Drop → CMDB object picker → place node
    // =========================================================
    function snap(v) { return Math.round(v / GRID) * GRID; }

    function onCanvasDrop(e) {
        if (!diagram || !diagram.is_current) return;
        e.preventDefault();
        let payload = null;
        try { payload = JSON.parse(e.dataTransfer.getData('text/plain') || '{}'); } catch (_) { /* ignore */ }
        if (!payload || payload.kind !== 'nm-class') return;
        const cls = classById[payload.class_id];
        if (!cls) return;

        const rect = elCanvas.getBoundingClientRect();
        // Drop point in canvas-local coordinates (account for scroll). We snap
        // the *top-left* of the node bounding box, computed by offsetting the
        // drop point by half the icon size so the drop visually centres.
        const iconPx = NODE_SIZES.medium;
        const dropX = e.clientX - rect.left + elCanvas.scrollLeft;
        const dropY = e.clientY - rect.top + elCanvas.scrollTop;
        const x = Math.max(0, snap(dropX - iconPx / 2));
        const y = Math.max(0, snap(dropY - iconPx / 2));

        openObjectPicker(cls, x, y);
    }

    function openObjectPicker(cls, x, y) {
        pickerClassId = cls.id;
        pickerDropX = x;
        pickerDropY = y;
        pickerResults = [];
        pickerHighlight = 0;
        elPickerClassLabel.textContent = cls.name;
        elPickerSearch.value = '';
        elPickerModal.classList.add('active');
        // Load the class's full object list as the default view; type-ahead
        // narrows it server-side from there.
        fetchPickerResults('');
        setTimeout(() => elPickerSearch.focus(), 50);
    }

    function closeObjectPicker() {
        elPickerModal.classList.remove('active');
        pickerClassId = null;
    }

    async function fetchPickerResults(q) {
        try {
            const url = q.trim()
                ? CMDB_API + 'get_objects.php?class_id=' + pickerClassId + '&search=' + encodeURIComponent(q.trim())
                : CMDB_API + 'get_objects.php?class_id=' + pickerClassId;
            const resp = await fetch(url, { credentials: 'same-origin' });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Search failed');
            pickerResults = data.objects || [];
            pickerHighlight = 0;
            renderPickerResults();
        } catch (e) {
            elPickerResults.innerHTML = '<div class="nm-picker-empty">Failed: ' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderPickerResults() {
        // Filter out objects already on the canvas — placing the same object
        // twice would be confusing and save_diagram would persist both.
        const onCanvas = new Set(nodes.map(n => n.cmdb_object_id));
        const available = pickerResults.filter(r => !onCanvas.has(r.id));

        if (!available.length) {
            const allInUse = pickerResults.length > 0;
            elPickerResults.innerHTML = '<div class="nm-picker-empty">' +
                (allInUse
                    ? 'Every object in this class is already on the diagram.'
                    : 'No objects in this class yet. <a href="../cmdb/" target="_blank">Create one in CMDB →</a>') +
                '</div>';
            return;
        }
        elPickerResults.innerHTML = available.map((r, i) => {
            const cls = pickerHighlight === i ? ' highlighted' : '';
            const planned = r.is_planned ? '<span class="nm-picker-planned">PLANNED</span>' : '';
            const parent = r.parent_name
                ? '<span class="nm-picker-parent">in ' + escapeHtml(r.parent_name) + '</span>'
                : '';
            return '<div class="nm-picker-row' + cls + '" data-object-id="' + r.id + '">' +
                '<span class="nm-picker-name">' + escapeHtml(r.name) + planned + '</span>' +
                parent +
                '</div>';
        }).join('');
        elPickerResults.querySelectorAll('.nm-picker-row').forEach(row => {
            row.addEventListener('click', () => {
                const id = parseInt(row.dataset.objectId, 10);
                const obj = available.find(o => o.id === id);
                if (obj) commitPickerSelection(obj);
            });
        });
    }

    function onPickerSearchInput(value) {
        clearTimeout(pickerSearchTimer);
        pickerSearchTimer = setTimeout(() => fetchPickerResults(value), 200);
    }

    function onPickerKeyDown(e) {
        const onCanvas = new Set(nodes.map(n => n.cmdb_object_id));
        const available = pickerResults.filter(r => !onCanvas.has(r.id));
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            pickerHighlight = Math.min(available.length - 1, pickerHighlight + 1);
            renderPickerResults();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            pickerHighlight = Math.max(0, pickerHighlight - 1);
            renderPickerResults();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const pick = available[pickerHighlight];
            if (pick) commitPickerSelection(pick);
        } else if (e.key === 'Escape') {
            closeObjectPicker();
        }
    }

    function commitPickerSelection(obj) {
        const cls = classById[obj.class_id];
        const node = {
            id: null,
            tempId: nextTempId--,
            cmdb_object_id: obj.id,
            name: obj.name,
            class_id: obj.class_id,
            class_name: obj.class_name || (cls ? cls.name : ''),
            class_icon: cls ? cls.icon_key : 'box',
            is_planned: !!obj.is_planned,
            x: pickerDropX,
            y: pickerDropY,
            size: 'medium',
            icon_override: null
        };
        nodes.push(node);
        closeObjectPicker();
        renderNodes();
        selectNode(nodeKey(node));
        markDirty();
    }

    // =========================================================
    //  Node rendering + selection + drag + delete
    // =========================================================
    function nodeKey(n) {
        // Stable identifier for selection / drag refs. Real id wins; tempId
        // (negative number) for unsaved nodes. Returned as a string so we can
        // use === safely on Set/Map lookups.
        return n.id ? 'r' + n.id : 't' + n.tempId;
    }

    function findNodeByKey(key) {
        if (key == null) return null;
        return nodes.find(n => nodeKey(n) === key) || null;
    }

    function renderNodes() {
        // Clear existing node DOM; keep the empty-state element + the SVG
        // layer (connectors render into it separately via renderConnectors).
        Array.from(elCanvas.querySelectorAll('.nm-node')).forEach(el => el.remove());

        if (!nodes.length) {
            if (elCanvasEmpty) elCanvasEmpty.style.display = '';
            renderConnectors();
            return;
        }
        if (elCanvasEmpty) elCanvasEmpty.style.display = 'none';

        nodes.forEach(n => elCanvas.appendChild(buildNodeEl(n)));
        renderConnectors();
    }

    function buildNodeEl(n) {
        const iconKey = n.icon_override || n.class_icon || 'box';
        const sizePx = NODE_SIZES[n.size] || NODE_SIZES.medium;
        const iconSvg = window.nmRenderIcon ? window.nmRenderIcon(iconKey, sizePx - 12) : '';

        const el = document.createElement('div');
        el.className = 'nm-node';
        if (n.is_planned) el.classList.add('is-planned');
        if (nodeKey(n) === selectedNodeKey) el.classList.add('selected');
        el.style.left = n.x + 'px';
        el.style.top  = n.y + 'px';
        el.style.width = sizePx + 'px';
        el.dataset.key = nodeKey(n);

        // Edge-handle positions: anchored to the icon's bounding box (which is
        // sizePx square, offset by NODE_PADDING inside the node element). The
        // label can extend wider/lower than the icon — anchoring to the icon
        // keeps the handles in predictable spots regardless of label length.
        const pad = NODE_PADDING;
        const cx  = pad + sizePx / 2;
        const cy  = pad + sizePx / 2;
        const x1  = pad + sizePx;
        const y1  = pad + sizePx;
        const handleHtml = (diagram && diagram.is_current) ? (
            '<div class="nm-edge-handle" data-side="top"    style="left:' + cx + 'px; top:'  + pad + 'px;"></div>' +
            '<div class="nm-edge-handle" data-side="right"  style="left:' + x1 + 'px; top:'  + cy  + 'px;"></div>' +
            '<div class="nm-edge-handle" data-side="bottom" style="left:' + cx + 'px; top:'  + y1  + 'px;"></div>' +
            '<div class="nm-edge-handle" data-side="left"   style="left:' + pad + 'px; top:' + cy  + 'px;"></div>'
        ) : '';

        el.innerHTML =
            handleHtml +
            '<div class="nm-node-icon" style="height:' + sizePx + 'px;">' + iconSvg + '</div>' +
            (n.is_planned ? '<span class="nm-node-planned-pill">PLANNED</span>' : '') +
            '<div class="nm-node-label" title="' + escapeAttr(n.name + ' (' + (n.class_name || '') + ')') + '">' +
                escapeHtml(n.name) +
            '</div>';

        el.addEventListener('mousedown', onNodeMouseDown);
        // Edge handles initiate connector drag (separate from node move drag —
        // startConnectDrag stopPropagation()s so the node's own mousedown
        // doesn't also fire)
        el.querySelectorAll('.nm-edge-handle').forEach(h => {
            h.addEventListener('mousedown', e => startConnectDrag(e, n, h.dataset.side));
        });
        return el;
    }

    function nodeRef(n) {
        // Stable cross-save identifier for connector references. Real id wins;
        // tempId (negative integer) for newly-placed nodes. save_diagram.php
        // looks up both forms in its nodeIdMap, so a connector between two
        // brand-new nodes resolves cleanly on the first save.
        return n.id || n.tempId;
    }

    function findNodeByRef(ref) {
        if (ref == null) return null;
        return nodes.find(n => n.id === ref || n.tempId === ref) || null;
    }

    function nodeIconBBox(n) {
        // Icon-only bounding box, used for connector endpoint placement and
        // edge-handle anchoring. We use icon bbox (not the full node bbox)
        // because the label below the icon can wrap to 2 lines of variable
        // height — anchoring to the icon keeps connector endpoints stable
        // regardless of label content.
        const sizePx = NODE_SIZES[n.size] || NODE_SIZES.medium;
        const pad = NODE_PADDING;
        return {
            x: n.x + pad,
            y: n.y + pad,
            w: sizePx,
            h: sizePx,
            cx: n.x + pad + sizePx / 2,
            cy: n.y + pad + sizePx / 2
        };
    }

    function selectNode(key) {
        if (selectedNodeKey === key) return;
        selectedNodeKey = key;
        if (key != null && selectedConnectorKey != null) {
            // node + connector selection are mutually exclusive; redraw to drop
            // the connector's selected stroke
            selectedConnectorKey = null;
            renderConnectors();
        }
        // Cheap DOM swap rather than full re-render
        Array.from(elCanvas.querySelectorAll('.nm-node')).forEach(el => {
            el.classList.toggle('selected', el.dataset.key === key);
        });
        // Drive the detail panel off the selection: open it when a node is
        // selected, close when selection clears. Keeps node/panel state in sync
        // without callers having to remember to call openDetail() themselves.
        const n = key ? findNodeByKey(key) : null;
        if (n) openDetailForNode(n);
        else closeDetail();
    }

    function onNodeMouseDown(e) {
        if (e.button !== 0) return;
        const key = e.currentTarget.dataset.key;
        const n = findNodeByKey(key);
        if (!n) return;
        selectNode(key);
        if (!diagram || !diagram.is_current) return; // read-only: select but no drag

        const rect = elCanvas.getBoundingClientRect();
        const cursorX = e.clientX - rect.left + elCanvas.scrollLeft;
        const cursorY = e.clientY - rect.top + elCanvas.scrollTop;
        nodeDrag = {
            key,
            offsetX: cursorX - n.x,
            offsetY: cursorY - n.y,
            startX: n.x,
            startY: n.y,
            moved: false
        };
        document.addEventListener('mousemove', onNodeMouseMove);
        document.addEventListener('mouseup', onNodeMouseUp);
        e.preventDefault();
    }

    function onNodeMouseMove(e) {
        if (!nodeDrag) return;
        const n = findNodeByKey(nodeDrag.key);
        if (!n) return;
        const rect = elCanvas.getBoundingClientRect();
        const cursorX = e.clientX - rect.left + elCanvas.scrollLeft;
        const cursorY = e.clientY - rect.top + elCanvas.scrollTop;
        const newX = Math.max(0, snap(cursorX - nodeDrag.offsetX));
        const newY = Math.max(0, snap(cursorY - nodeDrag.offsetY));
        if (newX === n.x && newY === n.y) return;
        n.x = newX;
        n.y = newY;
        nodeDrag.moved = true;
        const el = elCanvas.querySelector('.nm-node[data-key="' + cssEscape(nodeDrag.key) + '"]');
        if (el) {
            el.style.left = newX + 'px';
            el.style.top  = newY + 'px';
        }
        // Connectors anchored to this node need to redraw to track its new
        // position. Cheap to re-render the whole set each frame for v1.
        renderConnectors();
    }

    function onNodeMouseUp() {
        document.removeEventListener('mousemove', onNodeMouseMove);
        document.removeEventListener('mouseup', onNodeMouseUp);
        if (!nodeDrag) return;
        const moved = nodeDrag.moved;
        nodeDrag = null;
        if (moved) markDirty();
    }

    function deleteSelectedNode() {
        if (!diagram || !diagram.is_current) return;
        if (selectedNodeKey == null) return;
        const n = findNodeByKey(selectedNodeKey);
        if (!n) return;
        // Drop any connectors touching this node so save_diagram doesn't reject
        // the payload as having dangling refs.
        connectors = connectors.filter(c => {
            // Connectors reference nodes by their id (real or temp). Build the
            // node we're deleting's key form on both sides for the comparison.
            const myId = n.id;
            const myTemp = n.tempId;
            return c.from_node_id !== myId && c.from_node_id !== myTemp
                && c.to_node_id   !== myId && c.to_node_id   !== myTemp;
        });
        nodes = nodes.filter(other => other !== n);
        // Route deselection through selectNode/selectConnector so side effects
        // (detail panel close, connector re-render) fire correctly.
        selectNode(null);
        selectConnector(null);
        renderNodes();
        markDirty();
    }

    // =========================================================
    //  Connectors — render, select, delete, label, draw-new
    // =========================================================
    function connectorKey(c) {
        // Real id wins. Brand-new connectors get a transient JS-side key
        // (assigned in commitConnectorDrag) so they survive selection until
        // save→reload promotes them to real ids.
        return c.id ? 'cr' + c.id : 'ct' + c._tempKey;
    }

    function findConnectorByKey(key) {
        if (key == null) return null;
        return connectors.find(c => connectorKey(c) === key) || null;
    }

    function selectConnector(key) {
        if (selectedConnectorKey === key) return;
        selectedConnectorKey = key;
        if (key != null) selectNode(null);
        renderConnectors();
    }

    function renderConnectors() {
        if (!elSvgLayer) return;
        // Clear all rendered connector <g>s; keep the <defs> markers in place
        Array.from(elSvgLayer.querySelectorAll('.nm-connector-group')).forEach(g => g.remove());

        connectors.forEach(c => {
            const from = findNodeByRef(c.from_node_id);
            const to   = findNodeByRef(c.to_node_id);
            if (!from || !to) return; // node was deleted but connector lingered — skip silently

            const fromBox = nodeIconBBox(from);
            const toBox   = nodeIconBBox(to);
            const pts = calcConnectorPoints(fromBox, toBox);

            const g = document.createElementNS(SVG_NS, 'g');
            g.classList.add('nm-connector-group');
            const cKey = connectorKey(c);
            g.dataset.connKey = cKey;
            const isSel = selectedConnectorKey === cKey;

            // Wide invisible hit-area underneath for easier clicking
            const hit = document.createElementNS(SVG_NS, 'path');
            hit.setAttribute('d', 'M' + pts.x1 + ',' + pts.y1 + ' L' + pts.x2 + ',' + pts.y2);
            hit.classList.add('nm-connector-hit');
            hit.addEventListener('mousedown', e => {
                e.stopPropagation();
                selectConnector(cKey);
            });
            hit.addEventListener('dblclick', e => {
                e.stopPropagation();
                promptConnectorLabel(c);
            });
            g.appendChild(hit);

            const path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', 'M' + pts.x1 + ',' + pts.y1 + ' L' + pts.x2 + ',' + pts.y2);
            path.classList.add('nm-connector-line');
            if (c.line_style === 'dashed') path.classList.add('dashed');
            if (isSel) path.classList.add('selected');
            path.setAttribute('marker-end', isSel ? 'url(#nm-arrow-sel)' : 'url(#nm-arrow)');
            g.appendChild(path);

            if (c.label) {
                const mx = (pts.x1 + pts.x2) / 2;
                const my = (pts.y1 + pts.y2) / 2;
                const textLen = c.label.length * 6.5 + 14;

                const bg = document.createElementNS(SVG_NS, 'rect');
                bg.classList.add('nm-connector-label-bg');
                bg.setAttribute('x', String(mx - textLen / 2));
                bg.setAttribute('y', String(my - 10));
                bg.setAttribute('width', String(textLen));
                bg.setAttribute('height', '20');
                bg.setAttribute('rx', '3');
                bg.addEventListener('mousedown', e => {
                    e.stopPropagation();
                    selectConnector(cKey);
                });
                bg.addEventListener('dblclick', e => {
                    e.stopPropagation();
                    promptConnectorLabel(c);
                });
                g.appendChild(bg);

                const text = document.createElementNS(SVG_NS, 'text');
                text.classList.add('nm-connector-label');
                text.setAttribute('x', String(mx));
                text.setAttribute('y', String(my + 4));
                text.textContent = c.label;
                text.addEventListener('dblclick', e => {
                    e.stopPropagation();
                    promptConnectorLabel(c);
                });
                g.appendChild(text);
            }

            elSvgLayer.appendChild(g);
        });
    }

    function calcConnectorPoints(from, to) {
        // Endpoints land on the side of each icon bbox that faces the other
        // node. Whichever axis (horizontal/vertical) has the larger separation
        // wins — same pattern Process Mapper uses for its connector geometry.
        const dx = to.cx - from.cx;
        const dy = to.cy - from.cy;
        let x1, y1, x2, y2;
        if (Math.abs(dx) > Math.abs(dy)) {
            if (dx > 0) {
                x1 = from.x + from.w; y1 = from.cy;
                x2 = to.x;            y2 = to.cy;
            } else {
                x1 = from.x;          y1 = from.cy;
                x2 = to.x + to.w;     y2 = to.cy;
            }
        } else {
            if (dy > 0) {
                x1 = from.cx; y1 = from.y + from.h;
                x2 = to.cx;   y2 = to.y;
            } else {
                x1 = from.cx; y1 = from.y;
                x2 = to.cx;   y2 = to.y + to.h;
            }
        }
        return { x1: x1, y1: y1, x2: x2, y2: y2 };
    }

    function deleteSelectedConnector() {
        if (!diagram || !diagram.is_current) return;
        if (selectedConnectorKey == null) return;
        const c = findConnectorByKey(selectedConnectorKey);
        if (!c) return;
        connectors = connectors.filter(x => x !== c);
        selectedConnectorKey = null;
        renderConnectors();
        markDirty();
    }

    function promptConnectorLabel(c) {
        if (!diagram || !diagram.is_current) return;
        const from = findNodeByRef(c.from_node_id);
        const to   = findNodeByRef(c.to_node_id);
        if (!from || !to) return;
        const pts = calcConnectorPoints(nodeIconBBox(from), nodeIconBBox(to));
        const mx = (pts.x1 + pts.x2) / 2;
        const my = (pts.y1 + pts.y2) / 2;

        // Inline input positioned at the connector midpoint — same pattern as
        // Process Mapper's editConnectorLabel. Feels in-place, doesn't yank
        // the user out of the canvas with a modal, blur or Enter commits,
        // Escape cancels.
        // Tear down any leftover instance from a previous edit first
        elCanvas.querySelectorAll('.nm-connector-label-input').forEach(el => el.remove());

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'nm-connector-label-input';
        input.value = c.label || '';
        input.maxLength = 255;
        input.placeholder = 'Label (Enter to save, Esc to cancel)';
        input.style.left = (mx - 80) + 'px';
        input.style.top  = (my - 14) + 'px';

        let cancelled = false;
        const finish = () => {
            input.removeEventListener('blur', finish);
            const next = cancelled ? (c.label || '') : input.value.trim();
            input.remove();
            const newLabel = next === '' ? null : next.slice(0, 255);
            if (newLabel === c.label || (newLabel == null && !c.label)) {
                renderConnectors(); // redraw in case selection changed underneath
                return;
            }
            c.label = newLabel;
            renderConnectors();
            markDirty();
        };

        input.addEventListener('blur', finish);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            else if (e.key === 'Escape') { cancelled = true; input.blur(); }
        });

        elCanvas.appendChild(input);
        input.focus();
        input.select();
    }

    // ---- Connect drag (edge handle → target node) ----
    function startConnectDrag(e, fromNode, side) {
        if (!diagram || !diagram.is_current) return;
        e.stopPropagation();
        e.preventDefault();

        const box = nodeIconBBox(fromNode);
        let sx, sy;
        switch (side) {
            case 'top':    sx = box.cx;         sy = box.y; break;
            case 'bottom': sx = box.cx;         sy = box.y + box.h; break;
            case 'left':   sx = box.x;          sy = box.cy; break;
            case 'right':
            default:       sx = box.x + box.w;  sy = box.cy; break;
        }
        connectDrag = { fromRef: nodeRef(fromNode), startX: sx, startY: sy };
        document.addEventListener('mousemove', onConnectDragMove);
        document.addEventListener('mouseup',   onConnectDragUp);
    }

    function onConnectDragMove(e) {
        if (!connectDrag || !elSvgLayer) return;
        const rect = elCanvas.getBoundingClientRect();
        const mx = e.clientX - rect.left + elCanvas.scrollLeft;
        const my = e.clientY - rect.top  + elCanvas.scrollTop;
        let line = elSvgLayer.querySelector('.nm-temp-connector');
        if (!line) {
            line = document.createElementNS(SVG_NS, 'line');
            line.classList.add('nm-temp-connector');
            elSvgLayer.appendChild(line);
        }
        line.setAttribute('x1', String(connectDrag.startX));
        line.setAttribute('y1', String(connectDrag.startY));
        line.setAttribute('x2', String(mx));
        line.setAttribute('y2', String(my));
    }

    function onConnectDragUp(e) {
        document.removeEventListener('mousemove', onConnectDragMove);
        document.removeEventListener('mouseup',   onConnectDragUp);
        if (!connectDrag) return;
        if (elSvgLayer) {
            elSvgLayer.querySelectorAll('.nm-temp-connector').forEach(el => el.remove());
        }
        // Resolve drop target: look for the closest .nm-node element under the
        // cursor. Using elementFromPoint with a temporary hide of the SVG layer
        // would also work, but the bbox hit-test is cheaper and avoids reflow.
        const rect = elCanvas.getBoundingClientRect();
        const mx = e.clientX - rect.left + elCanvas.scrollLeft;
        const my = e.clientY - rect.top  + elCanvas.scrollTop;
        const target = findNodeAt(mx, my);
        const fromRef = connectDrag.fromRef;
        connectDrag = null;
        if (!target) return;
        const toRef = nodeRef(target);
        if (toRef === fromRef) return; // self-loop not supported
        // Idempotent: if an identical connector already exists in this direction,
        // don't add a duplicate
        if (connectors.some(c => c.from_node_id === fromRef && c.to_node_id === toRef)) return;
        commitConnectorDrag(fromRef, toRef);
    }

    function commitConnectorDrag(fromRef, toRef) {
        const c = {
            id: null,
            _tempKey: nextTempId--,           // JS-side selection key, never sent
            from_node_id: fromRef,
            to_node_id: toRef,
            cmdb_relationship_id: null,        // free-form by default; part 2 populates this
            label: null,
            line_style: 'solid'
        };
        connectors.push(c);
        renderConnectors();
        selectConnector(connectorKey(c));
        markDirty();
    }

    function findNodeAt(x, y) {
        // Hit-test the icon bounding box, not the full node element — matches
        // how connectors anchor and feels more precise when dropping near a
        // node edge.
        return nodes.find(n => {
            const b = nodeIconBBox(n);
            return x >= b.x && x <= b.x + b.w && y >= b.y && y <= b.y + b.h;
        }) || null;
    }

    // =========================================================
    //  Node detail panel — opens beside the canvas when a node is selected
    // =========================================================
    function openDetailForNode(n) {
        if (!elDetailPanel) return;
        elDetailPanel.classList.add('open');
        elDetailPanel.setAttribute('aria-hidden', 'false');

        const iconKey = n.icon_override || n.class_icon || 'box';
        elNdIcon.innerHTML = window.nmRenderIcon ? window.nmRenderIcon(iconKey, 28) : '';
        elNdName.textContent = n.name;
        elNdName.title = n.name;
        elNdClass.textContent = n.class_name || '';
        elNdClassValue.textContent = n.class_name || '—';
        elNdPlannedRow.style.display = n.is_planned ? '' : 'none';
        // CMDB deep-link — opens the object detail page in a new tab
        elNdCmdbLink.href = '../cmdb/object.php?id=' + n.cmdb_object_id;

        // "Add related objects" is a mutation; gate on the editable version
        elNdAddRelatedBtn.disabled = !diagram || !diagram.is_current;
        elNdAddRelatedBtn.title = elNdAddRelatedBtn.disabled
            ? 'Historical versions are read-only'
            : 'Pull in CMDB neighbours of this object';
    }

    function closeDetail() {
        if (!elDetailPanel) return;
        elDetailPanel.classList.remove('open');
        elDetailPanel.setAttribute('aria-hidden', 'true');
    }

    // =========================================================
    //  Add-related-objects modal
    // =========================================================
    function openRelatedModal() {
        if (!diagram || !diagram.is_current) return;
        if (selectedNodeKey == null) return;
        const src = findNodeByKey(selectedNodeKey);
        if (!src) return;
        // Persisted nodes only — a brand-new (unsaved) node has no real CMDB
        // edge to walk yet on the server, and from_node_id on the resulting
        // connectors would need a real id eventually anyway. Asking the user
        // to save first is the simplest contract.
        if (!src.id) {
            if (window.showToast) showToast('Save the diagram first so this node has a stable id', 'info');
            return;
        }
        relatedSourceNode = src;
        relatedRows = [];
        relatedSelected.clear();
        elRmSourceName.textContent = src.name;
        elRmResults.innerHTML = '<div class="nm-rm-loading">Loading related objects&hellip;</div>';
        elRmAddBtn.disabled = true;
        elRmAddBtn.textContent = 'Add';
        elRelatedModal.classList.add('active');
        fetchRelatedRows(src.cmdb_object_id);
    }

    function closeRelatedModal() {
        elRelatedModal.classList.remove('active');
        relatedSourceNode = null;
        relatedRows = [];
        relatedSelected.clear();
    }

    async function fetchRelatedRows(cmdbObjectId) {
        try {
            const resp = await fetch(API + 'get_related_objects.php?object_id=' + cmdbObjectId, { credentials: 'same-origin' });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load');
            relatedRows = data.related || [];
            renderRelatedRows();
        } catch (e) {
            elRmResults.innerHTML = '<div class="nm-rm-empty">Failed to load: ' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderRelatedRows() {
        if (!relatedRows.length) {
            elRmResults.innerHTML = '<div class="nm-rm-empty">' +
                'No related objects in CMDB yet. Add relationships or object-ref properties on the source object in CMDB, then come back.' +
                '</div>';
            return;
        }
        // Group by kind so the modal reads naturally: "what this depends on",
        // "what depends on this", "what references this via a property"
        const groups = [
            { kind: 'outgoing', label: 'This object &rarr; others', rows: [] },
            { kind: 'incoming', label: 'Others &rarr; this object', rows: [] },
            { kind: 'property', label: 'Referenced by properties', rows: [] }
        ];
        const onCanvas = new Set(nodes.map(n => n.cmdb_object_id));
        relatedRows.forEach((r, idx) => {
            const g = groups.find(x => x.kind === r.kind);
            if (g) g.rows.push({ row: r, idx: idx, onCanvas: onCanvas.has(r.object_id) });
        });

        let html = '';
        groups.forEach(g => {
            if (!g.rows.length) return;
            html += '<div class="nm-rm-group">';
            html += '<div class="nm-rm-group-header"><span>' + g.label + '</span><span class="nm-rm-group-count">' + g.rows.length + '</span></div>';
            g.rows.forEach(({ row, idx, onCanvas }) => {
                const icon = window.nmRenderIcon ? window.nmRenderIcon(row.class_icon || 'box', 18) : '';
                const planned = row.is_planned ? '<span class="nm-rm-planned-pill">PLANNED</span>' : '';
                const onBoard = onCanvas ? '<span class="nm-rm-onboard">on canvas</span>' : '';
                const disabled = onCanvas ? ' disabled' : '';
                const checked = (!onCanvas && relatedSelected.has(idx)) ? ' checked' : '';
                html +=
                    '<label class="nm-rm-row' + disabled + '" data-idx="' + idx + '">' +
                        '<input type="checkbox" class="nm-rm-checkbox"' + checked + (onCanvas ? ' disabled' : '') + '>' +
                        '<span class="nm-rm-icon">' + icon + '</span>' +
                        '<span class="nm-rm-main">' +
                            '<span class="nm-rm-name">' +
                                '<span class="nm-rm-name-text">' + escapeHtml(row.name) + '</span>' +
                                planned + onBoard +
                            '</span>' +
                            '<span class="nm-rm-class">' +
                                escapeHtml(row.class_name) +
                                ' &middot; <span class="nm-rm-link-text">' + escapeHtml(row.label || '') + '</span>' +
                            '</span>' +
                        '</span>' +
                    '</label>';
            });
            html += '</div>';
        });
        elRmResults.innerHTML = html;
        elRmResults.querySelectorAll('.nm-rm-row').forEach(row => {
            const cb = row.querySelector('input[type=checkbox]');
            if (!cb || cb.disabled) return;
            // Click anywhere on the row toggles the checkbox; checkbox click
            // already handled natively so don't double-fire on input changes.
            cb.addEventListener('change', () => {
                const idx = parseInt(row.dataset.idx, 10);
                if (cb.checked) relatedSelected.add(idx);
                else relatedSelected.delete(idx);
                updateRelatedAddBtn();
            });
        });
        updateRelatedAddBtn();
    }

    function updateRelatedAddBtn() {
        const n = relatedSelected.size;
        elRmAddBtn.disabled = n === 0;
        elRmAddBtn.textContent = n === 0 ? 'Add' : 'Add ' + n + ' object' + (n === 1 ? '' : 's');
    }

    function commitRelatedSelections() {
        if (!relatedSourceNode) return;
        if (!relatedSelected.size) return;
        const src = relatedSourceNode;
        const picks = [...relatedSelected].map(i => relatedRows[i]).filter(Boolean);
        if (!picks.length) return;

        // Figure out which picks correspond to objects not yet on the canvas;
        // those need a placed node. Deduplicate by object_id (an object might
        // be ticked twice via two different relationship paths — place it
        // once, draw a connector per path).
        const existingByCmdb = new Map();
        nodes.forEach(n => existingByCmdb.set(n.cmdb_object_id, n));
        const newPlacements = [];
        const seenNew = new Set();
        picks.forEach(p => {
            if (existingByCmdb.has(p.object_id) || seenNew.has(p.object_id)) return;
            seenNew.add(p.object_id);
            newPlacements.push(p);
        });

        // Lay new nodes out in a ring around the source. Radius scales with
        // the count so a big pull-in doesn't crowd. Angles start at 12 o'clock
        // and walk clockwise; existing nodes get nudged onto the grid if they
        // collide with another node.
        const srcCx = src.x + NODE_PADDING + (NODE_SIZES[src.size] || NODE_SIZES.medium) / 2;
        const srcCy = src.y + NODE_PADDING + (NODE_SIZES[src.size] || NODE_SIZES.medium) / 2;
        const iconPx = NODE_SIZES.medium;
        const baseRadius = 180;
        const radius = Math.max(baseRadius, baseRadius + (newPlacements.length - 6) * 12);
        const n = newPlacements.length;

        newPlacements.forEach((p, i) => {
            const angle = (-Math.PI / 2) + (i / Math.max(1, n)) * Math.PI * 2;
            const cx = srcCx + Math.cos(angle) * radius;
            const cy = srcCy + Math.sin(angle) * radius;
            const x = Math.max(0, snap(cx - iconPx / 2 - NODE_PADDING));
            const y = Math.max(0, snap(cy - iconPx / 2 - NODE_PADDING));
            const cls = classById[p.class_id];
            const node = {
                id: null,
                tempId: nextTempId--,
                cmdb_object_id: p.object_id,
                name: p.name,
                class_id: p.class_id,
                class_name: p.class_name,
                class_icon: p.class_icon || (cls ? cls.icon_key : 'box'),
                is_planned: !!p.is_planned,
                x: x,
                y: y,
                size: 'medium',
                icon_override: null
            };
            nodes.push(node);
            existingByCmdb.set(p.object_id, node);
        });

        // Now create a connector per picked path. Direction respects the
        // relationship kind: outgoing goes src→other, incoming goes other→src,
        // property-ref goes other→src too (since other.property = src).
        let connectorsAdded = 0;
        picks.forEach(p => {
            const target = existingByCmdb.get(p.object_id);
            if (!target) return;
            const srcRef    = nodeRef(src);
            const targetRef = nodeRef(target);
            if (srcRef === targetRef) return;

            let fromRef, toRef;
            if (p.kind === 'outgoing') {
                fromRef = srcRef;    toRef = targetRef;
            } else {
                // incoming / property — other points at src
                fromRef = targetRef; toRef = srcRef;
            }
            // Skip if an identical directed connector already exists
            if (connectors.some(c => c.from_node_id === fromRef && c.to_node_id === toRef)) return;

            connectors.push({
                id: null,
                _tempKey: nextTempId--,
                from_node_id: fromRef,
                to_node_id: toRef,
                // Real CMDB relationships get a provenance link; property-ref
                // paths have no row in cmdb_object_relationships, so the
                // connector is labelled with the property name instead.
                cmdb_relationship_id: p.relationship_id || null,
                label: p.label || null,
                line_style: 'solid'
            });
            connectorsAdded++;
        });

        closeRelatedModal();
        renderNodes();
        if (window.showToast) {
            const placedMsg = newPlacements.length
                ? newPlacements.length + ' object' + (newPlacements.length === 1 ? '' : 's') + ' added'
                : 'No new objects placed';
            const connMsg = connectorsAdded
                ? connectorsAdded + ' connector' + (connectorsAdded === 1 ? '' : 's')
                : '';
            showToast(connMsg ? placedMsg + ' · ' + connMsg : placedMsg, 'success');
        }
        markDirty();
    }

    function cssEscape(s) {
        // Minimal escape for data-key selector use (keys are 'r123' or 't-1' so
        // safe in practice, but be defensive in case the format ever changes)
        return String(s).replace(/(["\\])/g, '\\$1');
    }

    // =========================================================
    //  Autosave: dirty / debounce / status / preference
    // =========================================================

    // Single entry-point for "something changed" — wraps dirty/status/debounce
    // so the call sites (place node, drag, delete, …) don't have to think about
    // any of it. No-op on read-only versions so even programmatic edits can't
    // dirty a historical diagram.
    function markDirty() {
        if (!diagram || !diagram.is_current) return;
        dirty = true;
        setStatus('unsaved');
        if (autosaveOn) scheduleAutosave();
    }

    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            if (!autosaveOn || !dirty || saveInFlight) return;
            // Don't save during an active node drag — save() reloads the diagram,
            // which would destroy the in-progress drag DOM and snap the node
            // back to its last-committed position. Reschedule and re-check.
            if (nodeDrag) {
                scheduleAutosave();
                return;
            }
            save(true);
        }, AUTOSAVE_DEBOUNCE_MS);
    }

    // Status states: 'idle' | 'unsaved' | 'saving' | 'saved' | 'failed' | 'off'
    function setStatus(state) {
        if (!elStatus) return;
        const map = {
            idle:    { html: '', cls: '' },
            unsaved: { html: autosaveOn ? 'Unsaved' : 'Unsaved changes', cls: 'nm-status-unsaved' },
            saving:  { html: '<span class="nm-status-spinner"></span> Saving…', cls: 'nm-status-saving' },
            saved:   { html: '<span class="nm-status-tick">✓</span> Saved', cls: 'nm-status-saved' },
            failed:  { html: '<span class="nm-status-warn">⚠</span> Save failed — <a href="#" id="nmRetrySave">retry</a>', cls: 'nm-status-failed' },
            off:     { html: 'Autosave off', cls: 'nm-status-off' }
        };
        const s = map[state] || map.idle;
        elStatus.className = 'nm-status ' + s.cls;
        elStatus.innerHTML = s.html;
        if (state === 'failed') {
            const retry = document.getElementById('nmRetrySave');
            if (retry) retry.onclick = (e) => { e.preventDefault(); save(autosaveOn); };
        }
    }

    async function loadAutosavePreference() {
        try {
            const r = await fetch(SYSTEM_API + 'get_user_preference.php?key=' + encodeURIComponent(AUTOSAVE_PREF_KEY), { credentials: 'same-origin' });
            const d = await r.json();
            applyAutosaveState(d.success && d.value === '1', false);
        } catch (e) {
            applyAutosaveState(false, false);
        }
    }

    function applyAutosaveState(on, persist) {
        autosaveOn = !!on;
        if (elAutosaveToggle) elAutosaveToggle.checked = autosaveOn;
        if (!diagram) {
            setStatus('idle');
        } else if (!diagram.is_current) {
            // Read-only versions don't show a save status — banner does the work
            setStatus('idle');
        } else if (dirty) {
            setStatus('unsaved');
            if (autosaveOn) scheduleAutosave();
        } else {
            setStatus(autosaveOn ? 'saved' : 'off');
        }
        if (persist) {
            fetch(SYSTEM_API + 'set_user_preference.php', {
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
        if (autosaveOn && dirty && diagram && diagram.is_current) scheduleAutosave();
    }

    // =========================================================
    //  Save
    // =========================================================
    async function save(isAutoSave) {
        if (!diagram || !diagram.is_current || saveInFlight) return;
        saveInFlight = true;
        setStatus('saving');
        lastSavingShownAt = Date.now();

        try {
            const resp = await fetch(API + 'save_diagram.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: diagramId,
                    nodes: nodes.map(n => ({
                        // tempId is critical here — save_diagram's nodeIdMap is
                        // keyed by id ?? tempId; without it, a connector that
                        // references a brand-new node by its tempId can't
                        // resolve and gets silently dropped on the server.
                        id: n.id,
                        tempId: n.tempId || null,
                        cmdb_object_id: n.cmdb_object_id,
                        x: n.x, y: n.y,
                        size: n.size || 'medium',
                        icon_override: n.icon_override || null
                    })),
                    connectors: connectors.map(c => ({
                        from_node_id: c.from_node_id,
                        to_node_id: c.to_node_id,
                        cmdb_relationship_id: c.cmdb_relationship_id || null,
                        label: c.label || null,
                        line_style: c.line_style || 'solid'
                    }))
                })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Save failed');

            dirty = false;
            // Reload from the server so temp ids resolve into real ids — vital
            // before any connector references those nodes. Preserve the current
            // selection by cmdb_object_id so the user's focus survives the swap.
            const selectedCmdbId = selectedNodeKey
                ? (findNodeByKey(selectedNodeKey) || {}).cmdb_object_id
                : null;
            const resp2 = await fetch(API + 'get_diagram.php?id=' + diagramId, { credentials: 'same-origin' });
            const data2 = await resp2.json();
            if (data2.success) {
                diagram = data2.diagram;
                nodes = data2.nodes || [];
                connectors = data2.connectors || [];
                // Connector selection used a transient _tempKey for newly-drawn
                // connectors; after reload everything has a real id so any
                // existing selection key is stale by definition. Drop it.
                selectedConnectorKey = null;
                // Force selectNode() below to actually run rather than
                // short-circuit on an unchanged key — the node *object* under
                // that key has been swapped out by the reload and the detail
                // panel needs to refresh with the new instance.
                selectedNodeKey = null;
                renderNodes();
                if (selectedCmdbId) {
                    const reselect = nodes.find(n => n.cmdb_object_id === selectedCmdbId);
                    if (reselect) selectNode(nodeKey(reselect));
                }
            }

            // Hold "Saving…" on screen for MIN_SAVING_VISIBLE_MS so it doesn't flash
            const elapsed = Date.now() - lastSavingShownAt;
            const wait = Math.max(0, MIN_SAVING_VISIBLE_MS - elapsed);
            setTimeout(() => {
                setStatus('saved');
                if (!isAutoSave && window.showToast) showToast('Saved', 'success');
            }, wait);
        } catch (e) {
            setStatus('failed');
            if (!isAutoSave && window.showToast) showToast('Save failed: ' + e.message, 'error');
        } finally {
            saveInFlight = false;
        }
    }

    // =========================================================
    //  Save as new version
    // =========================================================
    async function openNewVersionModal() {
        if (!diagram || !diagram.is_current) {
            if (window.showToast) showToast('Only the current version can be forked', 'error');
            return;
        }
        // create_version.php clones from the *persisted* state, so any in-memory
        // edits would be silently dropped. Save first so the user gets what
        // they see — they don't need to think about persistence semantics.
        if (dirty) {
            if (window.showToast) showToast('Saving pending changes first…', 'info');
            await save(false);
            if (dirty) return; // save failed; bail and let the user retry
        }
        // Pre-fill with the current diagram's metadata so the user only needs to
        // tweak the version label most of the time
        document.getElementById('nvTitle').value = diagram.title || '';
        document.getElementById('nvDescription').value = diagram.description || '';
        document.getElementById('nvVersionLabel').value = suggestNextVersionLabel(diagram.version_label);
        document.getElementById('newVersionModal').classList.add('active');
        setTimeout(() => document.getElementById('nvVersionLabel').focus(), 50);
    }

    function closeNewVersionModal() {
        document.getElementById('newVersionModal').classList.remove('active');
    }

    function suggestNextVersionLabel(current) {
        if (!current) return 'v2';
        // Try to bump a trailing integer ("v3" -> "v4", "Draft 2" -> "Draft 3")
        const m = String(current).match(/^(.*?)(\d+)\s*$/);
        if (m) return m[1] + (parseInt(m[2], 10) + 1);
        return current + ' (new)';
    }

    async function createNewVersion() {
        const title = document.getElementById('nvTitle').value.trim();
        const description = document.getElementById('nvDescription').value.trim();
        const versionLabel = document.getElementById('nvVersionLabel').value.trim();
        if (!title) {
            if (window.showToast) showToast('Title is required', 'error');
            return;
        }
        const btn = document.getElementById('nvCreateBtn');
        btn.disabled = true;
        try {
            const resp = await fetch(API + 'create_version.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    parent_diagram_id: diagramId,
                    title, description, version_label: versionLabel
                })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to create version');
            // Navigate to the new (leaf, editable) version
            window.location.href = 'diagram.php?id=' + data.id;
        } catch (e) {
            if (window.showToast) showToast('Failed: ' + e.message, 'error');
            btn.disabled = false;
        }
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function formatDate(s) {
        if (!s) return '—';
        try { return new Date(s.replace(' ', 'T') + 'Z').toLocaleString(); }
        catch (e) { return s; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/'/g, "\\'"); }

    // =========================================================
    //  Public surface
    // =========================================================
    window.NM = {
        init,
        save: () => save(false),
        toggleAutosave,
        openNewVersionModal,
        closeNewVersionModal,
        createNewVersion,
        markDirty,
        // picker
        closeObjectPicker,
        onPickerSearchInput,
        onPickerKeyDown,
        // detail panel
        closeDetail,
        // related-objects modal
        openRelatedModal,
        closeRelatedModal,
        commitRelatedSelections
    };
})();
