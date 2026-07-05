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

    // Paper dimensions at 96 DPI (the standard CSS-pixel-to-physical-inch
    // assumption). Width × height in pixels, in PORTRAIT orientation;
    // landscape just swaps them. Used by the page-outline overlay so what
    // analysts see on the canvas matches what will fit in a PNG/PDF export.
    const PAPER_SIZES_PX = {
        A4:      { w: 794,  h: 1123, label: 'A4',      mm: '210 × 297 mm' },
        A3:      { w: 1123, h: 1587, label: 'A3',      mm: '297 × 420 mm' },
        A2:      { w: 1587, h: 2245, label: 'A2',      mm: '420 × 594 mm' },
        Letter:  { w: 816,  h: 1056, label: 'Letter',  mm: '8.5 × 11 in'  },
        Tabloid: { w: 1056, h: 1632, label: 'Tabloid', mm: '11 × 17 in'   }
    };
    const PAPER_SIZE_ORDER = ['A4', 'A3', 'A2', 'Letter', 'Tabloid'];

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

    // ---- icon picker state ----
    let iconPickerNode = null;    // node whose icon we're editing
    let iconPickerFilter = '';    // current search filter

    // ---- branding state ----
    // Org-wide defaults fetched once on init from api/system/get_branding.php.
    // Shape: { logo_path, header_left, header_center, header_right,
    //          footer_left, footer_center, footer_right } — strings (defaults
    // applied server-side). null until the fetch resolves; renderBrandHeaderFooter
    // bails out until then so we don't flash an unbranded overlay.
    let brandingDefaults = null;

    // ---- DOM refs (filled in init) ----
    let elTitle, elVersionPill, elMetaRow, elMetaAuthor, elMetaCreated, elMetaUpdated;
    let elStatus, elSaveBtn, elSaveVersionBtn, elAutosaveToggle, elAutosaveWrap;
    let elPaletteBody, elCanvas, elCanvasInner, elCanvasSpacer, elReadonlyBanner, elCanvasEmpty;
    let elZoomLabel, elEditor;
    // Zoom state — visual scale only. Node coordinates (n.x/n.y) stay in
    // 1× model space; the transform on elCanvasInner does the visual work,
    // and hit-area math in event handlers divides client deltas by `zoom`
    // to translate back into model space.
    const ZOOM_LEVELS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 3];
    // Base footprint for the canvas-spacer — gives the scroll viewport a
    // sensible default extent so zooming in still has scrollable area
    // beyond the visible canvas. Real content beyond this (absolutely-
    // positioned nodes at x>3000) extends the scroll area naturally.
    const ZOOM_BASE_PX = 3000;
    let zoom = 1;
    let elPickerModal, elPickerClassLabel, elPickerSearch, elPickerResults;
    let elDetailPanel, elNdIcon, elNdName, elNdClass, elNdClassValue, elNdPlannedRow, elNdCmdbLink, elNdAddRelatedBtn;
    let elNdIconPreview, elNdIconChangeBtn, elNdIconResetBtn;
    let elNdPropertiesSection, elNdProperties;
    // Track the currently-pending properties fetch so a fast-click swap
    // (open node A → click node B before A's fetch resolves) ignores the
    // stale response and renders B's properties instead of A's.
    let currentPropertiesObjectId = null;
    let elRelatedModal, elRmSourceName, elRmResults, elRmAddBtn;
    let elVersionsBtn, elVersionsDropdown;
    let elPageBtn, elPageDropdown, elPageBtnLabel;
    let elIconPickerModal, elIpNodeName, elIpSearch, elIpGrid;
    let elCentreBtn;

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
        elCanvasInner     = document.getElementById('canvasInner');
        elCanvasSpacer    = document.getElementById('canvasSpacer');
        elReadonlyBanner  = document.getElementById('readonlyBanner');
        elCanvasEmpty     = document.getElementById('canvasEmpty');
        elZoomLabel       = document.getElementById('zoomLabel');
        elEditor          = document.querySelector('.nm-editor');
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
        elNdIconPreview   = document.getElementById('ndIconPreview');
        elNdIconChangeBtn = document.getElementById('ndIconChangeBtn');
        elNdIconResetBtn  = document.getElementById('ndIconResetBtn');
        elNdPropertiesSection = document.getElementById('ndPropertiesSection');
        elNdProperties        = document.getElementById('ndProperties');
        elIconPickerModal = document.getElementById('iconPickerModal');
        elIpNodeName      = document.getElementById('ipNodeName');
        elIpSearch        = document.getElementById('ipSearch');
        elIpGrid          = document.getElementById('ipGrid');
        elRelatedModal    = document.getElementById('relatedObjectsModal');
        elRmSourceName    = document.getElementById('rmSourceName');
        elRmResults       = document.getElementById('rmResults');
        elRmAddBtn        = document.getElementById('rmAddBtn');
        elVersionsBtn     = document.getElementById('versionsBtn');
        elVersionsDropdown = document.getElementById('versionsDropdown');
        elPageBtn         = document.getElementById('pageBtn');
        elPageDropdown    = document.getElementById('pageDropdown');
        elPageBtnLabel    = document.getElementById('pageBtnLabel');
        elCentreBtn       = document.getElementById('centreBtn');

        // Close toolbar dropdowns on outside click. Each dropdown checks for
        // its anchor button + its panel; click outside both → dismiss. Same
        // pattern shared by Versions + Page.
        document.addEventListener('mousedown', e => {
            if (elVersionsDropdown && elVersionsDropdown.style.display !== 'none') {
                if (!(elVersionsBtn && elVersionsBtn.contains(e.target)) && !elVersionsDropdown.contains(e.target)) {
                    closeVersionsDropdown();
                }
            }
            if (elPageDropdown && elPageDropdown.style.display !== 'none') {
                if (!(elPageBtn && elPageBtn.contains(e.target)) && !elPageDropdown.contains(e.target)) {
                    closePageDropdown();
                }
            }
        });

        ensureSvgLayer();
        bindCanvasEvents();
        applyZoom();  // sync the spacer + label with the initial zoom (1)

        // Load diagram + palette + autosave preference + org branding in
        // parallel. Branding feeds into renderBrandHeaderFooter (header/footer
        // overlay on the canvas); if it lands after loadDiagram the render
        // function re-fires once defaults are cached.
        Promise.all([
            loadDiagram(),
            loadClasses(),
            loadAutosavePreference(),
            loadBrandingDefaults(),
        ]).catch(() => {});
    }

    async function loadBrandingDefaults() {
        try {
            const resp = await fetch('../api/system/get_branding.php', { credentials: 'same-origin' });
            const data = await resp.json();
            if (data && data.success && data.branding) {
                brandingDefaults = data.branding;
                // If the diagram already rendered before branding came back,
                // refresh the overlay now so the user sees the org-default
                // header/footer without having to nudge the page setting.
                if (diagram) renderBrandHeaderFooter();
            }
        } catch (e) {
            // Silently fall back to "no overlay" — branding is a polish layer,
            // not a load-blocker. The diagram still works without it.
        }
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
            // Escape exits Present mode regardless of focus — F11 is left to
            // the browser for true fullscreen escalation. Checked first so a
            // user in Present mode can always escape without us blocking on
            // input/modal heuristics.
            if (e.key === 'Escape' && isPresenting()) {
                e.preventDefault();
                exitPresent();
                return;
            }
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
        // Lives inside elCanvasInner so the zoom transform scales it with the
        // rest of the diagram (nodes, brand strips).
        elCanvasInner.insertBefore(svg, elCanvasInner.firstChild);
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
            updatePageButtonLabel();
            renderPageOutline();
            renderBrandHeaderFooter();
            setStatus(autosaveOn ? 'saved' : 'off');
        } catch (e) {
            elTitle.textContent = t('network-mapper.editor.load_failed');
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
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">' + escapeHtml(t('network-mapper.editor.palette_load_failed', { message: e.message })) + '</div>';
        }
    }

    function renderHeader() {
        document.title = diagram.title
            ? t('network-mapper.editor.browser_title_named', { title: diagram.title })
            : t('network-mapper.editor.browser_title');
        elTitle.textContent = diagram.title || t('network-mapper.editor.untitled');

        const label = diagram.version_label || t('network-mapper.editor.version_unknown');
        if (diagram.is_current) {
            elVersionPill.className = 'nm-version-pill';
            elVersionPill.textContent = t('network-mapper.editor.pill_current', { label: label });
        } else {
            elVersionPill.className = 'nm-version-pill readonly';
            elVersionPill.textContent = t('network-mapper.editor.pill_readonly', { label: label });
        }
        elVersionPill.style.display = '';

        elMetaRow.style.display = '';
        elMetaAuthor.textContent  = diagram.author_name || t('network-mapper.editor.author_unknown');
        elMetaCreated.textContent = formatDate(diagram.created_datetime);
        elMetaUpdated.textContent = formatDate(diagram.updated_datetime);
    }

    function renderPalette() {
        if (!classes.length) {
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">' + t('network-mapper.editor.palette_empty') + '</div>';
            return;
        }
        const html = classes.map(c => {
            const icon = window.nmRenderIcon ? window.nmRenderIcon(c.icon_key || 'box', 28) : '';
            const objCount = c.object_count || 0;
            const countText = objCount === 1
                ? t('network-mapper.editor.palette_object', { count: objCount })
                : t('network-mapper.editor.palette_objects', { count: objCount });
            return `
                <div class="nm-palette-tile" draggable="true" data-class-id="${c.id}" data-icon-key="${escapeAttr(c.icon_key || 'box')}" title="${escapeAttr(t('network-mapper.editor.palette_tile_title'))}">
                    <div class="nm-palette-tile-icon">${icon}</div>
                    <div class="nm-palette-tile-name">${escapeHtml(c.name)}</div>
                    <div class="nm-palette-tile-count">${escapeHtml(countText)}</div>
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
        elSaveBtn.title = t('network-mapper.editor.readonly_save_title');
        elAutosaveWrap.style.display = 'none';
        // Save-as-new-version on a non-leaf is refused by the backend (create_version
        // only forks from the leaf), so disable it here too.
        elSaveVersionBtn.disabled = true;
        elSaveVersionBtn.title = t('network-mapper.editor.readonly_fork_title');
        // Centre is a destructive op (modifies node positions) so block it
        // on historical versions. Other read-only-gated UI either silently
        // bails or is hidden; this button is visible so disabling reads
        // more honestly than letting the user click into a no-op.
        if (elCentreBtn) {
            elCentreBtn.disabled = true;
            elCentreBtn.title = t('network-mapper.editor.readonly_generic_title');
        }
    }

    // =========================================================
    //  Drop → CMDB object picker → place node
    // =========================================================
    function snap(v) { return Math.round(v / GRID) * GRID; }

    // Translate a mouse/drag event's client coordinates into model (1×, pre-zoom)
    // coordinates inside the canvas. elCanvas itself is NOT transformed (it
    // provides the scroll viewport + dot-grid background); elCanvasInner is
    // the scaled wrapper containing nodes/SVG/brand strips. We measure relative
    // to elCanvas (scroll-aware) and divide by zoom to undo the visual scale,
    // landing in the coordinate space that node.x/node.y are stored in.
    function canvasModelCoords(e) {
        const rect = elCanvas.getBoundingClientRect();
        return {
            x: (e.clientX - rect.left + elCanvas.scrollLeft) / zoom,
            y: (e.clientY - rect.top  + elCanvas.scrollTop)  / zoom,
        };
    }

    function onCanvasDrop(e) {
        if (!diagram || !diagram.is_current) return;
        e.preventDefault();
        let payload = null;
        try { payload = JSON.parse(e.dataTransfer.getData('text/plain') || '{}'); } catch (_) { /* ignore */ }
        if (!payload || payload.kind !== 'nm-class') return;
        const cls = classById[payload.class_id];
        if (!cls) return;

        // Drop point in model (pre-zoom) coordinates. We snap the *top-left*
        // of the node bounding box, computed by offsetting the drop point by
        // half the icon size so the drop visually centres on the cursor.
        const iconPx = NODE_SIZES.medium;
        const drop = canvasModelCoords(e);
        const x = Math.max(0, snap(drop.x - iconPx / 2));
        const y = Math.max(0, snap(drop.y - iconPx / 2));

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
            elPickerResults.innerHTML = '<div class="nm-picker-empty">' + escapeHtml(t('network-mapper.picker.search_failed', { message: e.message })) + '</div>';
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
                    ? escapeHtml(t('network-mapper.picker.all_in_use'))
                    : t('network-mapper.picker.none_yet')) +
                '</div>';
            return;
        }
        elPickerResults.innerHTML = available.map((r, i) => {
            const cls = pickerHighlight === i ? ' highlighted' : '';
            const planned = r.is_planned ? '<span class="nm-picker-planned">' + escapeHtml(t('network-mapper.picker.planned')) + '</span>' : '';
            const parent = r.parent_name
                ? '<span class="nm-picker-parent">' + escapeHtml(t('network-mapper.picker.in_parent', { parent: r.parent_name })) + '</span>'
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
        // Nodes live inside elCanvasInner (the zoom-transformed wrapper),
        // not elCanvas itself.
        Array.from(elCanvasInner.querySelectorAll('.nm-node')).forEach(el => el.remove());

        if (!nodes.length) {
            if (elCanvasEmpty) elCanvasEmpty.style.display = '';
            renderConnectors();
            return;
        }
        if (elCanvasEmpty) elCanvasEmpty.style.display = 'none';

        nodes.forEach(n => elCanvasInner.appendChild(buildNodeEl(n)));
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
            (n.is_planned ? '<span class="nm-node-planned-pill">' + escapeHtml(t('network-mapper.detail.planned_pill')) + '</span>' : '') +
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
        Array.from(elCanvasInner.querySelectorAll('.nm-node')).forEach(el => {
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

        const cursor = canvasModelCoords(e);
        nodeDrag = {
            key,
            offsetX: cursor.x - n.x,
            offsetY: cursor.y - n.y,
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
        const cursor = canvasModelCoords(e);
        const newX = Math.max(0, snap(cursor.x - nodeDrag.offsetX));
        const newY = Math.max(0, snap(cursor.y - nodeDrag.offsetY));
        if (newX === n.x && newY === n.y) return;
        n.x = newX;
        n.y = newY;
        nodeDrag.moved = true;
        const el = elCanvasInner.querySelector('.nm-node[data-key="' + cssEscape(nodeDrag.key) + '"]');
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
        elCanvasInner.querySelectorAll('.nm-connector-label-input').forEach(el => el.remove());

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'nm-connector-label-input';
        input.value = c.label || '';
        input.maxLength = 255;
        input.placeholder = t('network-mapper.connector.label_ph');
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

        elCanvasInner.appendChild(input);
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
        const cursor = canvasModelCoords(e);
        const mx = cursor.x, my = cursor.y;
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
        const cursor = canvasModelCoords(e);
        const target = findNodeAt(cursor.x, cursor.y);
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

        // Icon preview + change/reset state. The Reset button only matters
        // when an override is active (clears it back to the class default).
        if (elNdIconPreview) {
            elNdIconPreview.innerHTML = window.nmRenderIcon ? window.nmRenderIcon(iconKey, 20) : '';
        }
        const editable = diagram && diagram.is_current;
        if (elNdIconChangeBtn) {
            elNdIconChangeBtn.disabled = !editable;
            elNdIconChangeBtn.title = editable
                ? t('network-mapper.detail.icon_change_title')
                : t('network-mapper.editor.readonly_generic_title');
        }
        if (elNdIconResetBtn) {
            elNdIconResetBtn.style.display = (editable && n.icon_override) ? '' : 'none';
        }

        // "Add related objects" is a mutation; gate on the editable version
        elNdAddRelatedBtn.disabled = !editable;
        elNdAddRelatedBtn.title = !editable
            ? t('network-mapper.editor.readonly_generic_title')
            : t('network-mapper.detail.add_related_title');

        // Kick off the CMDB properties fetch. Selection swaps mid-flight are
        // handled by stamping currentPropertiesObjectId — the resolver checks
        // it's still the latest before rendering.
        loadNodeProperties(n.cmdb_object_id);
    }

    // ---- CMDB properties (lazy-loaded per selection) ----
    async function loadNodeProperties(cmdbObjectId) {
        if (!elNdProperties) return;
        currentPropertiesObjectId = cmdbObjectId;
        elNdPropertiesSection.style.display = '';
        elNdProperties.innerHTML = '<div class="nm-prop-loading">' + escapeHtml(t('network-mapper.detail.properties_loading')) + '</div>';
        try {
            const resp = await fetch(CMDB_API + 'get_object.php?id=' + cmdbObjectId, { credentials: 'same-origin' });
            const data = await resp.json();
            // Stale-response guard: user might have clicked another node by now
            if (currentPropertiesObjectId !== cmdbObjectId) return;
            if (!data.success) throw new Error(data.error || 'Failed to load');
            renderNodeProperties(data.object.properties || []);
        } catch (e) {
            if (currentPropertiesObjectId !== cmdbObjectId) return;
            elNdProperties.innerHTML = '<div class="nm-prop-empty">' + escapeHtml(t('network-mapper.detail.properties_load_failed', { message: e.message })) + '</div>';
        }
    }

    function renderNodeProperties(props) {
        // Filter out empty values — Ed's explicit ask is "don't show empty
        // properties". Per-type emptiness rules:
        //   text/dropdown: null or empty string
        //   number:        null (zero IS a value, show it)
        //   date:          null or empty string
        //   boolean:       null (explicit yes/no should both show)
        //   object_ref:    value (the int id) is null/0
        const withValues = props.filter(p => {
            switch (p.property_type) {
                case 'text':
                case 'dropdown': return p.value !== null && p.value !== undefined && String(p.value).trim() !== '';
                case 'number':   return p.value !== null && p.value !== undefined;
                case 'date':     return p.value !== null && p.value !== undefined && String(p.value).trim() !== '';
                case 'boolean':  return p.value !== null && p.value !== undefined;
                case 'object_ref': return p.value !== null && p.value !== undefined && p.value !== 0;
                default: return p.value !== null && p.value !== undefined && String(p.value).trim() !== '';
            }
        });

        if (!withValues.length) {
            elNdProperties.innerHTML = '<div class="nm-prop-empty">' + escapeHtml(t('network-mapper.detail.properties_empty')) + '</div>';
            return;
        }

        const html = withValues.map(p => {
            const label = '<span class="nm-prop-label">' + escapeHtml(p.label || p.property_key || '') + '</span>';
            return '<div class="nm-prop-row">' + label + renderPropertyValue(p) + '</div>';
        }).join('');
        elNdProperties.innerHTML = html;
    }

    function renderPropertyValue(p) {
        switch (p.property_type) {
            case 'boolean': {
                const yes = p.value === true;
                const cls = yes ? 'bool-yes' : 'bool-no';
                return '<span class="nm-prop-value ' + cls + '">' + escapeHtml(yes ? t('network-mapper.detail.bool_yes') : t('network-mapper.detail.bool_no')) + '</span>';
            }
            case 'date': {
                // Dates come back as ISO strings (YYYY-MM-DD). Localised
                // formatting matches the rest of the app's date rendering.
                let display = p.value;
                try {
                    const d = new Date(String(p.value).replace(' ', 'T'));
                    if (!isNaN(d.getTime())) display = d.toLocaleDateString();
                } catch (_) { /* fall through to raw */ }
                return '<span class="nm-prop-value">' + escapeHtml(display) + '</span>';
            }
            case 'number': {
                // Use locale-aware formatting so big numbers get thousand separators
                let n = p.value;
                if (typeof n === 'number' && isFinite(n)) n = n.toLocaleString();
                return '<span class="nm-prop-value">' + escapeHtml(String(n)) + '</span>';
            }
            case 'dropdown': {
                // Coloured pill matching the dropdown option's colour if any.
                // p.options is an array of { value, colour }; find the matching one.
                let bg = null, fg = null, border = null;
                if (Array.isArray(p.options)) {
                    const match = p.options.find(o => o.value === p.value);
                    if (match && match.colour) {
                        bg = match.colour;
                        // Pick legible text colour off the swatch — simple
                        // luminance check, matches the heuristic CMDB uses
                        fg = pillTextColour(match.colour);
                        border = match.colour;
                    }
                }
                const style = bg
                    ? ' style="background:' + escapeAttr(bg) + ';color:' + escapeAttr(fg) + ';border-color:' + escapeAttr(border) + ';"'
                    : '';
                return '<span class="nm-prop-pill"' + style + '>' + escapeHtml(p.value) + '</span>';
            }
            case 'object_ref': {
                // Linked CMDB object — render as a pink pill matching the CMDB
                // detail page's reference styling. Click opens in a new tab.
                if (!p.value_object) return '<span class="nm-prop-value">&mdash;</span>';
                const ref = p.value_object;
                const href = '../cmdb/object.php?id=' + ref.id;
                return '<a class="nm-prop-ref" href="' + escapeAttr(href) + '" target="_blank" title="' + escapeAttr(t('network-mapper.detail.ref_open_title')) + '">' +
                    escapeHtml(ref.name) +
                    (ref.class_name ? '<span class="nm-prop-ref-class">' + escapeHtml(ref.class_name) + '</span>' : '') +
                '</a>';
            }
            case 'text':
            default: {
                // Linkify URLs in plain text — common case for properties like
                // "Documentation" or "Repo". Anything else renders as-is.
                const s = String(p.value);
                if (/^https?:\/\/\S+$/i.test(s.trim())) {
                    return '<a class="nm-prop-value" href="' + escapeAttr(s.trim()) + '" target="_blank" rel="noopener" style="color:#0e7490;text-decoration:underline;word-break:break-all;">' +
                        escapeHtml(s.trim()) + '</a>';
                }
                return '<span class="nm-prop-value">' + escapeHtml(s) + '</span>';
            }
        }
    }

    function pillTextColour(hex) {
        // Best-effort luminance test to pick black or white text for a coloured
        // pill background. Mirrors what CMDB browse table does for dropdown pills.
        const m = String(hex || '').replace('#', '').match(/^([0-9a-f]{6})$/i);
        if (!m) return '#1f2937';
        const r = parseInt(m[1].slice(0,2), 16);
        const g = parseInt(m[1].slice(2,4), 16);
        const b = parseInt(m[1].slice(4,6), 16);
        const lum = (0.299*r + 0.587*g + 0.114*b) / 255;
        return lum > 0.6 ? '#1f2937' : '#ffffff';
    }

    function closeDetail() {
        if (!elDetailPanel) return;
        elDetailPanel.classList.remove('open');
        elDetailPanel.setAttribute('aria-hidden', 'true');
        // Cancel any pending properties render so a slow fetch can't paint
        // into a closed panel and re-expose stale content next open.
        currentPropertiesObjectId = null;
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
            if (window.showToast) showToast(t('network-mapper.related.save_first'), 'info');
            return;
        }
        relatedSourceNode = src;
        relatedRows = [];
        relatedSelected.clear();
        elRmSourceName.textContent = src.name;
        elRmResults.innerHTML = '<div class="nm-rm-loading">' + escapeHtml(t('network-mapper.related.loading')) + '</div>';
        elRmAddBtn.disabled = true;
        elRmAddBtn.textContent = t('network-mapper.related.add');
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
            elRmResults.innerHTML = '<div class="nm-rm-empty">' + escapeHtml(t('network-mapper.related.load_failed', { message: e.message })) + '</div>';
        }
    }

    function renderRelatedRows() {
        if (!relatedRows.length) {
            elRmResults.innerHTML = '<div class="nm-rm-empty">' +
                escapeHtml(t('network-mapper.related.empty')) +
                '</div>';
            return;
        }
        // Group by kind so the modal reads naturally: "what this depends on",
        // "what depends on this", "what references this via a property"
        const groups = [
            { kind: 'outgoing', label: escapeHtml(t('network-mapper.related.group_outgoing')), rows: [] },
            { kind: 'incoming', label: escapeHtml(t('network-mapper.related.group_incoming')), rows: [] },
            { kind: 'property', label: escapeHtml(t('network-mapper.related.group_property')), rows: [] }
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
                const planned = row.is_planned ? '<span class="nm-rm-planned-pill">' + escapeHtml(t('network-mapper.related.planned')) + '</span>' : '';
                const onBoard = onCanvas ? '<span class="nm-rm-onboard">' + escapeHtml(t('network-mapper.related.on_canvas')) + '</span>' : '';
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
        elRmAddBtn.textContent = n === 0
            ? t('network-mapper.related.add')
            : (n === 1
                ? t('network-mapper.related.add_one', { count: n })
                : t('network-mapper.related.add_many', { count: n }));
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
                ? (newPlacements.length === 1
                    ? t('network-mapper.related.placed_one', { count: newPlacements.length })
                    : t('network-mapper.related.placed_many', { count: newPlacements.length }))
                : t('network-mapper.related.placed_none');
            const connMsg = connectorsAdded
                ? (connectorsAdded === 1
                    ? t('network-mapper.related.connector_one', { count: connectorsAdded })
                    : t('network-mapper.related.connector_many', { count: connectorsAdded }))
                : '';
            showToast(connMsg ? t('network-mapper.related.result_combined', { placed: placedMsg, connectors: connMsg }) : placedMsg, 'success');
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
            unsaved: { html: escapeHtml(autosaveOn ? t('network-mapper.status.unsaved') : t('network-mapper.status.unsaved_changes')), cls: 'nm-status-unsaved' },
            saving:  { html: '<span class="nm-status-spinner"></span> ' + escapeHtml(t('network-mapper.status.saving')), cls: 'nm-status-saving' },
            saved:   { html: '<span class="nm-status-tick">✓</span> ' + escapeHtml(t('network-mapper.status.saved')), cls: 'nm-status-saved' },
            failed:  { html: '<span class="nm-status-warn">⚠</span> ' + escapeHtml(t('network-mapper.status.save_failed')) + ' <a href="#" id="nmRetrySave">' + escapeHtml(t('network-mapper.status.retry')) + '</a>', cls: 'nm-status-failed' },
            off:     { html: escapeHtml(t('network-mapper.status.autosave_off')), cls: 'nm-status-off' }
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
    //  Per-node icon picker
    //  Opens from the detail panel's Change button; lets the user override
    //  the class's default icon for this specific node. icon_override is a
    //  free-text VARCHAR on network_diagram_nodes so any key from
    //  NM_ICONS works (not constrained to the cmdb_icons table).
    // =========================================================
    function openIconPicker() {
        if (!diagram || !diagram.is_current) return;
        if (selectedNodeKey == null) return;
        const n = findNodeByKey(selectedNodeKey);
        if (!n) return;
        iconPickerNode = n;
        iconPickerFilter = '';
        elIpNodeName.textContent = n.name;
        elIpSearch.value = '';
        renderIconPicker();
        elIconPickerModal.classList.add('active');
        setTimeout(() => elIpSearch.focus(), 50);
    }

    function closeIconPicker() {
        elIconPickerModal.classList.remove('active');
        iconPickerNode = null;
        iconPickerFilter = '';
    }

    function onIconSearchInput(value) {
        iconPickerFilter = (value || '').toLowerCase().trim();
        renderIconPicker();
    }

    function renderIconPicker() {
        if (!elIpGrid) return;
        const cats = window.NM_ICON_CATEGORIES || [];
        const meta = window.NM_ICON_META || {};
        const icons = window.NM_ICONS || {};
        const currentKey = iconPickerNode
            ? (iconPickerNode.icon_override || iconPickerNode.class_icon || 'box')
            : null;

        let html = '';
        let anyMatches = false;
        cats.forEach(cat => {
            // Filter keys for this category
            const inCat = Object.keys(meta).filter(k => meta[k].category === cat.key);
            const matches = inCat.filter(k => {
                if (!iconPickerFilter) return true;
                const label = (meta[k].label || k).toLowerCase();
                return k.includes(iconPickerFilter) || label.includes(iconPickerFilter);
            });
            if (!matches.length) return;
            anyMatches = true;
            html += '<div class="nm-ip-category">' + escapeHtml(cat.label) + '</div>';
            html += '<div class="nm-ip-category-grid">';
            matches.forEach(k => {
                const selected = k === currentKey ? ' selected' : '';
                const svg = icons[k] ? window.nmRenderIcon(k, 24) : '';
                html +=
                    '<div class="nm-ip-tile' + selected + '" data-key="' + escapeAttr(k) + '" title="' + escapeAttr(meta[k].label || k) + '">' +
                        '<span class="nm-ip-tile-icon">' + svg + '</span>' +
                        '<span class="nm-ip-tile-name">' + escapeHtml(meta[k].label || k) + '</span>' +
                    '</div>';
            });
            html += '</div>';
        });

        if (!anyMatches) {
            html = '<div class="nm-ip-empty">' + escapeHtml(t('network-mapper.iconpicker.no_match', { query: iconPickerFilter })) + '</div>';
        }
        elIpGrid.innerHTML = html;
        elIpGrid.querySelectorAll('.nm-ip-tile').forEach(tile => {
            tile.addEventListener('click', () => commitIconPick(tile.dataset.key));
        });
    }

    function commitIconPick(key) {
        if (!iconPickerNode) return;
        if (!key) return;
        // If they pick the same icon as the class default, store NULL (reset)
        // rather than a redundant override — keeps the data clean.
        const isClassDefault = key === iconPickerNode.class_icon;
        iconPickerNode.icon_override = isClassDefault ? null : key;
        const target = iconPickerNode;
        closeIconPicker();
        // Refresh the on-canvas node + the detail panel preview
        renderNodes();
        openDetailForNode(target);
        markDirty();
    }

    function resetIconOverride() {
        if (!diagram || !diagram.is_current) return;
        if (selectedNodeKey == null) return;
        const n = findNodeByKey(selectedNodeKey);
        if (!n || !n.icon_override) return;
        n.icon_override = null;
        renderNodes();
        openDetailForNode(n);
        markDirty();
    }

    // =========================================================
    //  Versions dropdown — anchored to the toolbar Versions button.
    //  Lazy-fetches the chain each open so save-as-new-version mutations
    //  show up next time. Click a version to navigate.
    // =========================================================
    function toggleVersionsDropdown(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        if (!elVersionsDropdown) return;
        if (elVersionsDropdown.style.display !== 'none') {
            closeVersionsDropdown();
            return;
        }
        elVersionsDropdown.innerHTML = '<div class="nm-vd-loading">' + escapeHtml(t('network-mapper.versions.loading')) + '</div>';
        elVersionsDropdown.style.display = '';
        fetchVersionList();
    }

    function closeVersionsDropdown() {
        if (elVersionsDropdown) elVersionsDropdown.style.display = 'none';
    }

    async function fetchVersionList() {
        try {
            const resp = await fetch(API + 'list_versions.php?id=' + diagramId, { credentials: 'same-origin' });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load');
            renderVersionList(data.versions || []);
        } catch (e) {
            elVersionsDropdown.innerHTML = '<div class="nm-vd-empty">' + escapeHtml(t('network-mapper.versions.load_failed', { message: e.message })) + '</div>';
        }
    }

    function renderVersionList(versions) {
        if (!versions.length) {
            elVersionsDropdown.innerHTML = '<div class="nm-vd-empty">' + escapeHtml(t('network-mapper.versions.empty')) + '</div>';
            return;
        }
        // Latest version at the top reads more naturally for a history list —
        // users want to scan "what's the newest" first. list_versions returns
        // oldest-first (chain order); reverse for display.
        const ordered = versions.slice().reverse();
        const html = ordered.map(v => {
            const isViewing = v.id === diagramId;
            const isCurrent = v.is_current;
            // Pill priority: viewing > current > readonly. Viewing wins when both
            // apply (you're on the current version) so the row clearly highlights.
            let pill;
            if (isViewing && isCurrent) pill = '<span class="nm-vd-pill viewing">' + escapeHtml(t('network-mapper.versions.viewing_current')) + '</span>';
            else if (isViewing)         pill = '<span class="nm-vd-pill viewing">' + escapeHtml(t('network-mapper.versions.viewing')) + '</span>';
            else if (isCurrent)         pill = '<span class="nm-vd-pill current">' + escapeHtml(t('network-mapper.versions.current')) + '</span>';
            else                        pill = '<span class="nm-vd-pill readonly">' + escapeHtml(t('network-mapper.versions.readonly')) + '</span>';
            const label = v.version_label || ('v?' + (v.id));
            const meta = escapeHtml(v.author_name || t('network-mapper.versions.author_unknown')) + ' &middot; ' + formatDate(v.updated_datetime || v.created_datetime);
            const rowCls = isViewing ? 'nm-vd-row active' : 'nm-vd-row';
            // Hard link rather than JS navigation so middle-click / Ctrl-click
            // opens in a new tab — useful when comparing two versions side-by-side
            return '<a class="' + rowCls + '" href="diagram.php?id=' + v.id + '">' +
                '<div class="nm-vd-row-top">' +
                    '<span class="nm-vd-label">' + escapeHtml(label) + '</span>' +
                    pill +
                '</div>' +
                '<div class="nm-vd-row-meta">' + meta + '</div>' +
            '</a>';
        }).join('');
        elVersionsDropdown.innerHTML = html;
    }

    // =========================================================
    //  Page-size overlay — toolbar dropdown picks paper size + orientation,
    //  a dashed outline renders inside the SVG layer at the canvas origin.
    //  Persisted per-diagram (paper_size + paper_orientation cols) so the
    //  outline survives reload. Sets up the bounds for PNG/PDF export.
    // =========================================================
    function togglePageDropdown(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        if (!elPageDropdown) return;
        if (elPageDropdown.style.display !== 'none') {
            closePageDropdown();
            return;
        }
        renderPageDropdown();
        elPageDropdown.style.display = '';
    }

    function closePageDropdown() {
        if (elPageDropdown) elPageDropdown.style.display = 'none';
    }

    function renderPageDropdown() {
        const cur = diagram || {};
        const curSize = cur.paper_size || null;
        const curOrient = cur.paper_orientation || 'landscape';
        const editable = !!(diagram && diagram.is_current);

        const offRow =
            '<a class="nm-vd-row' + (!curSize ? ' active' : '') + '" href="#" data-size="" data-orient="">' +
                '<div class="nm-vd-row-top">' +
                    '<span class="nm-vd-label">' + escapeHtml(t('network-mapper.page.off')) + '</span>' +
                    (!curSize ? '<span class="nm-vd-pill viewing">' + escapeHtml(t('network-mapper.page.current')) + '</span>' : '') +
                '</div>' +
                '<div class="nm-vd-row-meta">' + escapeHtml(t('network-mapper.page.off_meta')) + '</div>' +
            '</a>';

        let rows = offRow;
        PAPER_SIZE_ORDER.forEach(size => {
            const def = PAPER_SIZES_PX[size];
            if (!def) return;
            ['landscape', 'portrait'].forEach(orient => {
                const isCur = curSize === size && curOrient === orient;
                const dims = orient === 'portrait'
                    ? def.w + ' × ' + def.h + ' px'
                    : def.h + ' × ' + def.w + ' px';
                const orientLabel = orient === 'portrait'
                    ? t('network-mapper.page.orient_portrait')
                    : t('network-mapper.page.orient_landscape');
                rows +=
                    '<a class="nm-vd-row' + (isCur ? ' active' : '') + '" href="#" data-size="' + escapeAttr(size) + '" data-orient="' + orient + '">' +
                        '<div class="nm-vd-row-top">' +
                            '<span class="nm-vd-label">' + escapeHtml(t('network-mapper.page.row_label', { label: def.label, orient: orientLabel })) + '</span>' +
                            (isCur ? '<span class="nm-vd-pill viewing">' + escapeHtml(t('network-mapper.page.current')) + '</span>' : '') +
                        '</div>' +
                        '<div class="nm-vd-row-meta">' + escapeHtml(def.mm) + ' &middot; ' + dims + '</div>' +
                    '</a>';
            });
        });

        elPageDropdown.innerHTML = rows;
        // Wire click handlers
        elPageDropdown.querySelectorAll('.nm-vd-row').forEach(row => {
            row.addEventListener('click', e => {
                e.preventDefault();
                if (!editable) {
                    if (window.showToast) showToast(t('network-mapper.page.readonly'), 'info');
                    closePageDropdown();
                    return;
                }
                const size   = row.dataset.size   || null;
                const orient = row.dataset.orient || null;
                applyPageSetting(size, orient);
                closePageDropdown();
            });
        });
    }

    function applyPageSetting(size, orient) {
        if (!diagram) return;
        // No-op if unchanged — avoids dirtying the diagram unnecessarily
        if ((diagram.paper_size || null) === (size || null) &&
            (diagram.paper_orientation || null) === (orient || null)) {
            return;
        }
        diagram.paper_size = size;
        diagram.paper_orientation = orient;
        updatePageButtonLabel();
        renderPageOutline();
        // Header/footer is gated on the page outline being set, so it
        // appears/disappears in lockstep with the paper choice.
        renderBrandHeaderFooter();
        markDirty();
    }

    function updatePageButtonLabel() {
        if (!elPageBtnLabel) return;
        const size = diagram && diagram.paper_size;
        const orient = diagram && diagram.paper_orientation;
        if (!size) {
            elPageBtnLabel.textContent = t('network-mapper.editor.page_off');
        } else {
            const def = PAPER_SIZES_PX[size];
            const lbl = def ? def.label : size;
            const shortOrient = (orient === 'portrait') ? 'P' : 'L';
            elPageBtnLabel.textContent = t('network-mapper.editor.page_label', { label: lbl, orient: shortOrient });
        }
    }

    function pageDimensionsPx(size, orient) {
        const def = PAPER_SIZES_PX[size];
        if (!def) return null;
        return (orient === 'portrait')
            ? { w: def.w, h: def.h }
            : { w: def.h, h: def.w };
    }

    function renderPageOutline() {
        if (!elSvgLayer) return;
        // Tear down the previous outline + label group
        Array.from(elSvgLayer.querySelectorAll('.nm-page-outline-group')).forEach(g => g.remove());

        const size = diagram && diagram.paper_size;
        const orient = diagram && diagram.paper_orientation;
        if (!size) return;
        const dims = pageDimensionsPx(size, orient);
        if (!dims) return;

        // Insert as the FIRST child of the SVG (after <defs>) so it renders
        // behind connectors. defs is the first child; insert after it.
        const g = document.createElementNS(SVG_NS, 'g');
        g.classList.add('nm-page-outline-group');

        // Soft fill so the page area is visually distinguished from the
        // surrounding canvas — helps users see what's "on page" vs "off page"
        const fill = document.createElementNS(SVG_NS, 'rect');
        fill.setAttribute('x', '0');
        fill.setAttribute('y', '0');
        fill.setAttribute('width',  String(dims.w));
        fill.setAttribute('height', String(dims.h));
        fill.classList.add('nm-page-outline-fill');
        g.appendChild(fill);

        // Dashed cyan border
        const border = document.createElementNS(SVG_NS, 'rect');
        border.setAttribute('x', '0');
        border.setAttribute('y', '0');
        border.setAttribute('width',  String(dims.w));
        border.setAttribute('height', String(dims.h));
        border.classList.add('nm-page-outline-border');
        g.appendChild(border);

        // Corner label so analysts can see at a glance which paper they picked
        const def = PAPER_SIZES_PX[size];
        const label = document.createElementNS(SVG_NS, 'text');
        label.setAttribute('x', '8');
        label.setAttribute('y', '16');
        label.classList.add('nm-page-outline-label');
        const orientLabel = orient === 'portrait'
            ? t('network-mapper.page.orient_portrait')
            : t('network-mapper.page.orient_landscape');
        label.textContent = t('network-mapper.page.row_label', { label: (def ? def.label : size), orient: orientLabel });
        g.appendChild(label);

        // Place above <defs> but below connectors. defs is the first child
        // already; inserting after it puts the page-outline group second.
        const defs = elSvgLayer.querySelector('defs');
        if (defs && defs.nextSibling) elSvgLayer.insertBefore(g, defs.nextSibling);
        else elSvgLayer.appendChild(g);
    }

    // =========================================================
    //  Header / footer branding overlay
    //  Per-diagram override (six nullable columns on network_diagrams) sits
    //  on top of the org-wide defaults loaded from system_settings via
    //  api/system/get_branding.php. NULL on the diagram column = inherit
    //  the default; non-NULL (including '') = explicit override. Renders
    //  only when a page outline is set so the overlay has clean bounds.
    // =========================================================

    // Resolve the effective text for a slot — per-diagram override wins, then
    // org-wide default, then empty string. The null-check is deliberate:
    // empty string is an explicit override ("blank this slot"), null is
    // "inherit". `diagram[key] != null` (loose !=) matches both null and
    // undefined which is what we want when the field hasn't been set on
    // a diagram created before this column existed.
    function resolveBrandingSlot(key) {
        if (diagram && diagram[key] != null) return diagram[key];
        if (brandingDefaults && brandingDefaults[key] != null) return brandingDefaults[key];
        return '';
    }

    function brandingTokenContext() {
        return {
            title:    diagram && diagram.title          ? diagram.title          : '',
            author:   diagram && diagram.author_name    ? diagram.author_name    : '',
            version:  diagram && diagram.version_label  ? diagram.version_label  : '',
            modified: diagram && diagram.updated_datetime ? formatDate(diagram.updated_datetime) : '',
            // diagram.php lives at network-mapper/diagram.php so the relative
            // prefix back to app root is ../ — same one used by the other
            // ../api/* fetches in this module. logo_path is stored relative to
            // the app root (e.g. system/uploads/branding/logo.svg).
            logoUrl:  brandingDefaults && brandingDefaults.logo_path ? '../' + brandingDefaults.logo_path : null,
        };
    }

    // Token resolver — walks the slot text once with a single regex,
    // HTML-escaping non-token segments and the substituted values; logo
    // renders as an <img>. Tokens that don't have a value in ctx come back
    // as empty strings so the slot collapses gracefully.
    function renderSlotHtml(text, ctx) {
        if (!text) return '';
        let html = '';
        let pos = 0;
        const re = /\{\{(title|author|version|modified|logo)\}\}/g;
        let m;
        while ((m = re.exec(text)) !== null) {
            html += escapeHtml(text.slice(pos, m.index));
            switch (m[1]) {
                case 'title':    html += escapeHtml(ctx.title); break;
                case 'author':   html += escapeHtml(ctx.author); break;
                case 'version':  html += escapeHtml(ctx.version); break;
                case 'modified': html += escapeHtml(ctx.modified); break;
                case 'logo':
                    if (ctx.logoUrl) {
                        html += '<img src="' + escapeAttr(ctx.logoUrl) + '" alt="" class="nm-brand-logo">';
                    }
                    break;
            }
            pos = re.lastIndex;
        }
        html += escapeHtml(text.slice(pos));
        return html;
    }

    function renderBrandHeaderFooter() {
        if (!elCanvasInner) return;
        // Tear down previous overlays — re-renders are full rebuilds since
        // the slot count is tiny and computing per-slot diffs isn't worth it.
        Array.from(elCanvasInner.querySelectorAll('.nm-brand-header, .nm-brand-footer')).forEach(el => el.remove());

        if (!diagram) return;
        // Gated on page outline being on. Without bounds, "header" and
        // "footer" don't have anchor points — they'd float in canvas space.
        if (!diagram.paper_size) return;
        const dims = pageDimensionsPx(diagram.paper_size, diagram.paper_orientation);
        if (!dims) return;

        const ctx = brandingTokenContext();
        const hL = resolveBrandingSlot('header_left');
        const hC = resolveBrandingSlot('header_center');
        const hR = resolveBrandingSlot('header_right');
        const fL = resolveBrandingSlot('footer_left');
        const fC = resolveBrandingSlot('footer_center');
        const fR = resolveBrandingSlot('footer_right');

        // Skip the strip entirely if all three slots are empty — no point
        // reserving 32px of vertical space for nothing.
        if (hL || hC || hR) {
            const header = document.createElement('div');
            header.className = 'nm-brand-header';
            header.style.top = '0px';
            header.style.width = dims.w + 'px';
            header.innerHTML =
                '<div class="nm-brand-slot left">'   + renderSlotHtml(hL, ctx) + '</div>' +
                '<div class="nm-brand-slot center">' + renderSlotHtml(hC, ctx) + '</div>' +
                '<div class="nm-brand-slot right">'  + renderSlotHtml(hR, ctx) + '</div>';
            elCanvasInner.appendChild(header);
        }
        if (fL || fC || fR) {
            const footer = document.createElement('div');
            footer.className = 'nm-brand-footer';
            footer.style.top = (dims.h - 32) + 'px';
            footer.style.width = dims.w + 'px';
            footer.innerHTML =
                '<div class="nm-brand-slot left">'   + renderSlotHtml(fL, ctx) + '</div>' +
                '<div class="nm-brand-slot center">' + renderSlotHtml(fC, ctx) + '</div>' +
                '<div class="nm-brand-slot right">'  + renderSlotHtml(fR, ctx) + '</div>';
            elCanvasInner.appendChild(footer);
        }
    }

    // =========================================================
    //  Branding modal — per-diagram override of the org-wide header/footer
    // =========================================================
    const BRAND_SLOT_MAP = [
        ['bmHeaderLeft',   'header_left'],
        ['bmHeaderCenter', 'header_center'],
        ['bmHeaderRight',  'header_right'],
        ['bmFooterLeft',   'footer_left'],
        ['bmFooterCenter', 'footer_center'],
        ['bmFooterRight',  'footer_right'],
    ];

    function openBrandingModal() {
        if (!diagram) return;
        if (!diagram.is_current) {
            if (window.showToast) showToast(t('network-mapper.branding.readonly'), 'info');
            return;
        }
        const modal = document.getElementById('brandingModal');
        if (!modal) return;
        // Inputs show the per-diagram override (or empty if inheriting).
        // Placeholder shows the org-wide default — that's what the slot
        // would render if the field were null. Lets the user see at a glance
        // what they're overriding without having to leave the page.
        BRAND_SLOT_MAP.forEach(pair => {
            const inputId = pair[0]; const key = pair[1];
            const input = document.getElementById(inputId);
            if (!input) return;
            input.value = (diagram[key] != null) ? diagram[key] : '';
            const fallback = (brandingDefaults && brandingDefaults[key] != null) ? brandingDefaults[key] : '';
            input.placeholder = fallback || t('network-mapper.branding.blank_default');
        });
        modal.classList.add('active');
    }

    function closeBrandingModal() {
        const modal = document.getElementById('brandingModal');
        if (modal) modal.classList.remove('active');
    }

    // Save commits the modal inputs as per-diagram overrides. An empty input
    // means "inherit the org-wide default" (stored as null) — the modal's
    // placeholder already previews that default, so a blank field naturally
    // reads as "use that". Non-empty input is an explicit override.
    function commitBrandingOverrides() {
        if (!diagram || !diagram.is_current) { closeBrandingModal(); return; }
        let changed = false;
        BRAND_SLOT_MAP.forEach(pair => {
            const inputId = pair[0]; const key = pair[1];
            const input = document.getElementById(inputId);
            if (!input) return;
            const v = input.value === '' ? null : input.value;
            if ((diagram[key] == null ? null : diagram[key]) !== v) {
                changed = true;
            }
            diagram[key] = v;
        });
        closeBrandingModal();
        if (changed) {
            renderBrandHeaderFooter();
            markDirty();
        }
    }

    // Reset clears all six overrides (sets them to null on the diagram object)
    // so the slots inherit the org-wide defaults again. Marks the diagram
    // dirty so the change persists on next save.
    function resetBrandingOverrides() {
        if (!diagram || !diagram.is_current) { closeBrandingModal(); return; }
        let changed = false;
        ['header_left','header_center','header_right','footer_left','footer_center','footer_right'].forEach(key => {
            if (diagram[key] != null) { changed = true; }
            diagram[key] = null;
        });
        closeBrandingModal();
        if (changed) {
            renderBrandHeaderFooter();
            markDirty();
        }
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
                    // Paper-outline settings: NULL clears the overlay; whitelisted
                    // server-side. Always sent so toggling Off persists too.
                    paper_size: diagram && diagram.paper_size ? diagram.paper_size : null,
                    paper_orientation: diagram && diagram.paper_orientation ? diagram.paper_orientation : null,
                    // Per-diagram header/footer overrides. NULL = inherit the
                    // org-wide default; non-NULL (including '') = explicit
                    // override. We always send all 6 so toggling between
                    // override → inherit also persists, not just override →
                    // different-override.
                    header_left:   diagram && diagram.header_left   !== undefined ? diagram.header_left   : null,
                    header_center: diagram && diagram.header_center !== undefined ? diagram.header_center : null,
                    header_right:  diagram && diagram.header_right  !== undefined ? diagram.header_right  : null,
                    footer_left:   diagram && diagram.footer_left   !== undefined ? diagram.footer_left   : null,
                    footer_center: diagram && diagram.footer_center !== undefined ? diagram.footer_center : null,
                    footer_right:  diagram && diagram.footer_right  !== undefined ? diagram.footer_right  : null,
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
                updatePageButtonLabel();
                renderPageOutline();
                renderBrandHeaderFooter();
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
                if (!isAutoSave && window.showToast) showToast(t('network-mapper.toast.saved'), 'success');
            }, wait);
        } catch (e) {
            setStatus('failed');
            if (!isAutoSave && window.showToast) showToast(t('network-mapper.toast.save_failed', { message: e.message }), 'error');
        } finally {
            saveInFlight = false;
        }
    }

    // =========================================================
    //  Save as new version
    // =========================================================
    async function openNewVersionModal() {
        if (!diagram || !diagram.is_current) {
            if (window.showToast) showToast(t('network-mapper.newversion.only_current'), 'error');
            return;
        }
        // create_version.php clones from the *persisted* state, so any in-memory
        // edits would be silently dropped. Save first so the user gets what
        // they see — they don't need to think about persistence semantics.
        if (dirty) {
            if (window.showToast) showToast(t('network-mapper.newversion.saving_first'), 'info');
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
            if (window.showToast) showToast(t('network-mapper.newversion.title_required'), 'error');
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
            if (window.showToast) showToast(t('network-mapper.newversion.create_failed', { message: e.message }), 'error');
            btn.disabled = false;
        }
    }

    // =========================================================
    //  Zoom + Present mode
    //  Zoom is purely visual: transform: scale(zoom) on elCanvasInner. Node
    //  coordinates stay in 1× model space; canvasModelCoords() divides client
    //  deltas by zoom so drag/drop math keeps working at any level. Present
    //  mode adds an .is-presenting class to .nm-editor which CSS uses to hide
    //  the toolbar/palette/detail-panel and reveal the floating Exit pill.
    // =========================================================
    function applyZoom() {
        if (elCanvasInner) {
            elCanvasInner.style.transform = 'scale(' + zoom + ')';
        }
        if (elCanvasSpacer) {
            // Grow the layout footprint so .nm-canvas's overflow:auto scrolls
            // over the full visual extent of the transformed content. Without
            // this, zoom-in would clip everything past the unscaled bounds.
            elCanvasSpacer.style.width  = (ZOOM_BASE_PX * zoom) + 'px';
            elCanvasSpacer.style.height = (ZOOM_BASE_PX * zoom) + 'px';
        }
        if (elZoomLabel) {
            elZoomLabel.textContent = Math.round(zoom * 100) + '%';
        }
        // Connectors anchor to node coords in model space, which haven't
        // changed — the transform handles the visual update for them too.
    }

    function setZoom(z) {
        // Snap to the nearest level so the label always reads 25/50/75/...
        // and Fit picks a clean integer percentage even when computed from
        // a ratio.
        let nearest = ZOOM_LEVELS[0];
        let bestDelta = Math.abs(z - nearest);
        for (let i = 1; i < ZOOM_LEVELS.length; i++) {
            const d = Math.abs(z - ZOOM_LEVELS[i]);
            if (d < bestDelta) { bestDelta = d; nearest = ZOOM_LEVELS[i]; }
        }
        zoom = nearest;
        applyZoom();
    }

    function zoomIn()    { setZoom(zoomNext(+1)); }
    function zoomOut()   { setZoom(zoomNext(-1)); }
    function zoomReset() { setZoom(1); }

    function zoomNext(dir) {
        // Find the current level's index then step. Falls back gracefully if
        // zoom drifted off-step somehow (rounds to nearest first).
        let i = ZOOM_LEVELS.indexOf(zoom);
        if (i === -1) {
            // Drift recovery — find the closest existing level
            i = 0;
            let best = Math.abs(zoom - ZOOM_LEVELS[0]);
            for (let k = 1; k < ZOOM_LEVELS.length; k++) {
                const d = Math.abs(zoom - ZOOM_LEVELS[k]);
                if (d < best) { best = d; i = k; }
            }
        }
        const next = Math.max(0, Math.min(ZOOM_LEVELS.length - 1, i + dir));
        return ZOOM_LEVELS[next];
    }

    function zoomFit(opts) {
        // Fit-to-page if a paper size is chosen (most common case once the
        // user has set up an export-ready diagram), otherwise fit to the
        // bounding box of all placed nodes. Leaves zoom unchanged with a
        // toast if there's nothing meaningful to fit to.
        //
        // opts: { pad?: number, snap?: boolean }
        //   pad   — padding around the content in viewport pixels (default 40).
        //           Present mode passes 0 so the diagram fills the screen edge
        //           to edge.
        //   snap  — whether to snap the computed zoom to the nearest ZOOM_LEVELS
        //           rung (default true). Present mode passes false so the fit
        //           is tight rather than rounded down/up to a discrete level.
        if (!elCanvas) return;
        const pad  = (opts && typeof opts.pad  === 'number') ? opts.pad  : 40;
        const useSnap = !(opts && opts.snap === false);
        const viewW = elCanvas.clientWidth  - pad * 2;
        const viewH = elCanvas.clientHeight - pad * 2;
        if (viewW <= 0 || viewH <= 0) return;

        let contentW = 0, contentH = 0;
        if (diagram && diagram.paper_size) {
            const dims = pageDimensionsPx(diagram.paper_size, diagram.paper_orientation);
            if (dims) { contentW = dims.w; contentH = dims.h; }
        }
        if ((!contentW || !contentH) && nodes.length) {
            // Bounding box of placed nodes (approximate — uses medium icon size).
            const sz = NODE_SIZES.medium;
            let maxX = 0, maxY = 0;
            nodes.forEach(n => {
                if (n.x + sz > maxX) maxX = n.x + sz;
                if (n.y + sz > maxY) maxY = n.y + sz;
            });
            contentW = maxX;
            contentH = maxY;
        }
        if (contentW <= 0 || contentH <= 0) {
            if (window.showToast) showToast(t('network-mapper.toast.nothing_to_fit'), 'info');
            return;
        }
        const target = Math.min(viewW / contentW, viewH / contentH);
        if (useSnap) {
            setZoom(target);
        } else {
            // Tight fit: clamp to the ZOOM_LEVELS extremes but otherwise use
            // the exact ratio. zoomNext handles drift recovery if the user
            // hits +/- afterwards, so off-grid values don't break the rest
            // of the zoom system.
            const min = ZOOM_LEVELS[0];
            const max = ZOOM_LEVELS[ZOOM_LEVELS.length - 1];
            zoom = Math.max(min, Math.min(max, target));
            applyZoom();
        }
        // Scroll back to the origin so the fitted content shows from top-left
        elCanvas.scrollLeft = 0;
        elCanvas.scrollTop  = 0;
    }

    // Pre-Present zoom + scroll, restored on exit so the user lands back
    // on what they were looking at before the presentation.
    let presentRestore = null;

    function enterPresent() {
        if (!elEditor) return;
        presentRestore = {
            zoom,
            scrollLeft: elCanvas ? elCanvas.scrollLeft : 0,
            scrollTop:  elCanvas ? elCanvas.scrollTop  : 0,
        };
        elEditor.classList.add('is-presenting');
        // Also mark <body> so CSS can reach elements that live outside
        // .nm-editor — specifically the module .header bar.
        document.body.classList.add('nm-presenting');
        // Fit-to-screen after the chrome (toolbar/palette/detail panel/nav
        // bar) has actually hidden — wait two rAF ticks so flex sizing on
        // .nm-canvas has reflowed to its new clientWidth/Height before
        // zoomFit() reads it. One tick isn't always enough on the first
        // paint after a display-none flip.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                // Tight fit for Present: no padding around the bbox, no
                // snap-to-discrete-level — fill the screen exactly.
                if (isPresenting()) zoomFit({ pad: 0, snap: false });
            });
        });
    }

    function exitPresent() {
        if (!elEditor) return;
        elEditor.classList.remove('is-presenting');
        document.body.classList.remove('nm-presenting');
        // Restore pre-Present zoom + scroll. Defer one frame so the
        // re-shown chrome has reclaimed its layout space before we set
        // scroll positions against the now-smaller canvas viewport.
        if (presentRestore) {
            const r = presentRestore;
            presentRestore = null;
            requestAnimationFrame(() => {
                setZoom(r.zoom);
                if (elCanvas) {
                    elCanvas.scrollLeft = r.scrollLeft;
                    elCanvas.scrollTop  = r.scrollTop;
                }
            });
        }
    }

    function isPresenting() {
        return elEditor && elEditor.classList.contains('is-presenting');
    }

    // =========================================================
    //  Auto-centre the diagram on the selected paper size
    //  Translates all node coordinates so the diagram's bounding box sits
    //  centred inside the page rectangle. Persistent (mutates n.x/n.y) so
    //  the centring survives save/reload, not a transient visual transform.
    //  Connectors auto-follow since renderConnectors() reads live node
    //  positions. Bails with a toast if the diagram is bigger than the
    //  selected page in either dimension.
    // =========================================================
    function centreOnPage() {
        if (!diagram || !diagram.is_current) return;
        if (!nodes.length) {
            if (window.showToast) showToast(t('network-mapper.toast.centre_no_nodes'), 'info');
            return;
        }
        if (!diagram.paper_size) {
            if (window.showToast) showToast(t('network-mapper.toast.centre_no_page'), 'info');
            return;
        }
        const dims = pageDimensionsPx(diagram.paper_size, diagram.paper_orientation);
        if (!dims) return;

        // Bounding box including the label that hangs ~40px below the icon
        // (mirrors the approximation in computeExportRect so centring and
        // exporting agree on what "the diagram" extends to).
        const sz = NODE_SIZES.medium;
        let minX = Infinity, minY = Infinity, maxX = 0, maxY = 0;
        nodes.forEach(n => {
            if (n.x < minX) minX = n.x;
            if (n.y < minY) minY = n.y;
            if (n.x + sz > maxX) maxX = n.x + sz;
            if (n.y + sz + 40 > maxY) maxY = n.y + sz + 40;
        });
        const bboxW = maxX - minX;
        const bboxH = maxY - minY;

        if (bboxW > dims.w || bboxH > dims.h) {
            if (window.showToast) showToast(t('network-mapper.toast.centre_too_large'), 'info');
            return;
        }

        // Compute the shift that lands the bbox centre on the page centre.
        // Snap the delta itself (not each node individually) so the existing
        // 20px grid alignment is preserved across the whole diagram.
        const deltaX = snap((dims.w - bboxW) / 2 - minX);
        const deltaY = snap((dims.h - bboxH) / 2 - minY);

        if (deltaX === 0 && deltaY === 0) {
            if (window.showToast) showToast(t('network-mapper.toast.centre_already'), 'info');
            return;
        }

        nodes.forEach(n => {
            n.x = Math.max(0, n.x + deltaX);
            n.y = Math.max(0, n.y + deltaY);
        });
        renderNodes();   // re-renders nodes + calls renderConnectors() inside
        markDirty();
        if (window.showToast) showToast(t('network-mapper.toast.centred'), 'success');
    }

    // =========================================================
    //  PNG / PDF export
    //  Snapshot the canvas via html2canvas, optionally wrap into a paper-
    //  sized PDF via jsPDF. Driven entirely from the live DOM: temporarily
    //  reset zoom to 1, hide selection/edge-handle chrome via .is-exporting,
    //  render the clipped rect, restore everything. The page outline drives
    //  the clip rect when a paper size is set (WYSIWYG export); otherwise
    //  we crop to the bounding box of placed nodes + 40px padding.
    // =========================================================
    function exportFilename(ext) {
        const title = (diagram && diagram.title) ? diagram.title : 'diagram';
        const vers = (diagram && diagram.version_label) ? diagram.version_label : '';
        const slug = (title + (vers ? '-' + vers : ''))
            .replace(/[^a-z0-9._-]+/gi, '-')
            .replace(/^-+|-+$/g, '')
            .toLowerCase();
        return (slug || 'diagram') + '.' + ext;
    }

    function computeExportRect() {
        if (!diagram) return null;
        if (diagram.paper_size) {
            const dims = pageDimensionsPx(diagram.paper_size, diagram.paper_orientation);
            if (dims) return { x: 0, y: 0, width: dims.w, height: dims.h };
        }
        if (!nodes.length) return null;
        // Bounding box of nodes — approximate using medium icon size, +40px
        // for the label below the icon so it isn't clipped. Pad each side
        // by 40px so the export doesn't feel cramped at the edges.
        const sz = NODE_SIZES.medium;
        let minX = Infinity, minY = Infinity, maxX = 0, maxY = 0;
        nodes.forEach(n => {
            if (n.x < minX) minX = n.x;
            if (n.y < minY) minY = n.y;
            if (n.x + sz > maxX) maxX = n.x + sz;
            if (n.y + sz + 40 > maxY) maxY = n.y + sz + 40;
        });
        const PAD = 40;
        const x = Math.max(0, minX - PAD);
        const y = Math.max(0, minY - PAD);
        return { x, y, width: (maxX + PAD) - x, height: (maxY + PAD) - y };
    }

    async function captureCanvas() {
        if (!diagram) return null;
        if (typeof html2canvas !== 'function') {
            if (window.showToast) showToast(t('network-mapper.toast.export_lib_failed'), 'error');
            return null;
        }
        const rect = computeExportRect();
        if (!rect) {
            if (window.showToast) showToast(t('network-mapper.toast.nothing_to_export'), 'info');
            return null;
        }

        // Stash state so we can restore exactly what was on screen before
        const stashed = {
            zoom,
            scrollLeft: elCanvas ? elCanvas.scrollLeft : 0,
            scrollTop:  elCanvas ? elCanvas.scrollTop  : 0,
            selectedNode: selectedNodeKey,
            selectedConnector: selectedConnectorKey,
        };

        // Clear selection so the export doesn't include cyan rings/strokes
        selectedNodeKey = null;
        selectedConnectorKey = null;
        if (elCanvasInner) {
            Array.from(elCanvasInner.querySelectorAll('.nm-node.selected')).forEach(el => el.classList.remove('selected'));
        }
        renderConnectors();

        // Reset zoom to 1 so html2canvas captures at the model's native
        // resolution. The `scale: 2` option below then upsamples for crisp
        // print output independent of the user's editor zoom.
        zoom = 1;
        applyZoom();

        // Capture mode hides edge handles + selection chrome via CSS
        if (elCanvasInner) elCanvasInner.classList.add('is-exporting');

        try {
            const canvas = await html2canvas(elCanvasInner, {
                x: rect.x,
                y: rect.y,
                width:  rect.width,
                height: rect.height,
                scale: 2,                  // 2× for crisp print/PDF output
                backgroundColor: '#ffffff', // page is white in print, not the dot-grid
                logging: false,
                useCORS: true,             // permit cross-origin logo if ever hosted off-domain
            });
            return { canvas, rect };
        } catch (err) {
            if (window.showToast) showToast(t('network-mapper.toast.export_failed', { message: (err && err.message ? err.message : t('network-mapper.toast.export_failed_unknown')) }), 'error');
            return null;
        } finally {
            if (elCanvasInner) elCanvasInner.classList.remove('is-exporting');
            zoom = stashed.zoom;
            applyZoom();
            if (elCanvas) {
                elCanvas.scrollLeft = stashed.scrollLeft;
                elCanvas.scrollTop  = stashed.scrollTop;
            }
            if (stashed.selectedNode != null) selectNode(stashed.selectedNode);
            if (stashed.selectedConnector != null) {
                selectedConnectorKey = stashed.selectedConnector;
                renderConnectors();
            }
        }
    }

    async function exportPng() {
        const result = await captureCanvas();
        if (!result) return;
        // Browser-native download via a one-shot <a download> click
        const link = document.createElement('a');
        link.download = exportFilename('png');
        link.href = result.canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        if (window.showToast) showToast(t('network-mapper.toast.png_exported'), 'success');
    }

    async function exportPdf() {
        // jsPDF UMD exposes its constructor under window.jspdf.jsPDF
        const jsPDF = (window.jspdf && window.jspdf.jsPDF) || null;
        if (!jsPDF) {
            if (window.showToast) showToast(t('network-mapper.toast.pdf_lib_failed'), 'error');
            return;
        }
        const result = await captureCanvas();
        if (!result) return;
        // PDF page setup mirrors the diagram's paper choice. With no paper
        // size we fall back to A4 portrait — the rasterised image gets
        // scaled to fit the page, which is fine for content-bbox exports.
        const paperSize  = (diagram && diagram.paper_size)        || 'A4';
        const orient     = (diagram && diagram.paper_orientation) || 'portrait';
        const doc = new jsPDF({
            orientation: orient === 'landscape' ? 'l' : 'p',
            unit: 'pt',
            // jsPDF accepts paper sizes lowercased; our stored values are
            // capitalised so .toLowerCase() bridges the convention gap.
            format: paperSize.toLowerCase(),
        });
        const pageW = doc.internal.pageSize.getWidth();
        const pageH = doc.internal.pageSize.getHeight();
        doc.addImage(result.canvas.toDataURL('image/png'), 'PNG', 0, 0, pageW, pageH);
        doc.save(exportFilename('pdf'));
        if (window.showToast) showToast(t('network-mapper.toast.pdf_exported'), 'success');
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function formatDate(s) {
        if (!s) return '—';
        // KIND 1: server-stamped UTC (created/updated_datetime) — convert to the
        // analyst's display zone via the shared tz helpers.
        try { return parseUTCDate(s).toLocaleString(undefined, tzOpts({})); }
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
        commitRelatedSelections,
        // versions dropdown
        toggleVersionsDropdown,
        closeVersionsDropdown,
        // page outline dropdown
        togglePageDropdown,
        closePageDropdown,
        // icon picker
        openIconPicker,
        closeIconPicker,
        onIconSearchInput,
        resetIconOverride,
        // branding (header/footer overrides per diagram)
        openBrandingModal,
        closeBrandingModal,
        commitBrandingOverrides,
        resetBrandingOverrides,
        // zoom + present mode
        zoomIn,
        zoomOut,
        zoomReset,
        zoomFit,
        enterPresent,
        exitPresent,
        // export
        exportPng,
        exportPdf,
        // auto-centre
        centre: centreOnPage
    };
})();
