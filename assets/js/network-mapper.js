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
    // GH #433: freestanding shapes and their editing state
    let shapes = [];
    let nextTempShapeId = -1;
    let selectedShapeKey = null;
    let shapeDrag = null;        // { key, offX, offY, moved }
    let shapeResize = null;      // { key, dir, start, x, y, w, h, moved }
    let shapeEditKey = null;     // the shape whose text is being typed
    let paletteTab = 'cmdb';
    // GH #433: pages (tabs) and layers
    let pages = [];              // [{page_key, name, sort}]
    let layers = [];             // [{page_key, layer_key, name, sort, visible, locked}]
    let currentPage = 0;
    const activeLayers = {};     // page_key -> the layer new items go on
    let elShapePanel = null, elShapePalette = null;

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
    let elTitle, elVersionPill, elMetaRow, elMetaAuthor, elMetaCreated, elMetaUpdated, elTitleEditBtn;
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
        elTitleEditBtn    = document.getElementById('titleEditBtn');
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
        elShapePanel      = document.getElementById('shapeFormatPanel');   // GH #433
        elShapePalette    = document.getElementById('shapePalette');
        renderShapePalette();
        loadIconPalette();   // GH #433: imported icon libraries

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
            const lDd = document.getElementById('layersDropdown'), lBtn = document.getElementById('layersBtn');   // GH #433
            if (lDd && lDd.style.display !== 'none' && !(lBtn && lBtn.contains(e.target)) && !lDd.contains(e.target)) closeLayersPanel();
            const aDd = document.getElementById('articlesDropdown'), aBtn = document.getElementById('articlesBtn');   // GH #433
            if (aDd && aDd.style.display !== 'none' && !(aBtn && aBtn.contains(e.target)) && !aDd.contains(e.target)
                && !e.target.closest('#articlePickerModal')) closeArticlesPanel();
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
        ]).then(() => {
            // ?fit=1 — open zoomed to fit. Used when a diagram is arriving from
            // somewhere that generated its layout (the CMDB blast radius), where
            // the extent depends on the estate and no fixed zoom is right. Only
            // honoured on load, so it never fights the user's own zooming.
            try {
                if (new URLSearchParams(location.search).get('fit') === '1') zoomFit();
            } catch (e) { /* fit is a nicety, never a failure */ }
        }).catch(() => {});
    }

    /**
     * Fetches the organisation-wide header and footer branding and redraws the overlay if the diagram has
     * already rendered; failures are ignored.
     */
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

    /**
     * Wires the canvas drop target, click-on-empty-space deselection and the document keys (Escape leaves
     * Present mode, Delete or Backspace removes the selected node or connector).
     */
    function bindCanvasEvents() {
        // Drop-target wiring: must preventDefault on dragover to permit drop
        elCanvas.addEventListener('dragover', e => {
            if (!diagram || !diagram.is_current) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        elCanvas.addEventListener('drop', onCanvasDrop);
        elCanvas.addEventListener('contextmenu', onCanvasContextMenu);   // GH #433: right-click menus
        document.addEventListener('paste', onPaste);                     // GH #433: pictures from the clipboard
        elCanvas.addEventListener('mousemove', e => { lastCanvasPoint = canvasModelCoords(e); });
        elCanvas.addEventListener('mouseleave', () => { lastCanvasPoint = null; });
        document.addEventListener('mousedown', e => { if (!(e.target.closest && e.target.closest('.nm-ctx-menu'))) closeCtxMenu(); }, true);
        window.addEventListener('blur', closeCtxMenu);

        // Click on empty canvas / SVG-layer empty space clears selection
        elCanvas.addEventListener('mousedown', e => {
            // Treat the canvas background, the empty-state element, or the SVG
            // layer itself (not its children) as "empty space" — clicking
            // anything else (node, connector path, edge handle) deselects via
            // its own handler instead.
            const t = e.target;
            const isCanvasBg = t === elCanvas || t.classList.contains('nm-canvas-empty');
            const isSvgBg    = t === elSvgLayer;
            if (isCanvasBg || isSvgBg || t === elCanvasInner) {
                selectNode(null);
                selectConnector(null);
                selectShape(null);   // GH #433
                if (!e.shiftKey) clearMulti();
                if (!isPresenting()) startBand(e);   // drag on empty space selects an area
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
            const tag = (document.activeElement && document.activeElement.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if ((e.key === 'PageDown' || e.key === 'PageUp') && pages.length > 1) {   // GH #433: next / previous page
                const i = pages.findIndex(p => p.page_key === currentPage) + (e.key === 'PageDown' ? 1 : -1);
                if (i >= 0 && i < pages.length) { e.preventDefault(); switchPage(pages[i].page_key); }
                return;
            }
            // GH #433: typing in a shape - its own keys, never a delete of the shape
            if (document.activeElement && document.activeElement.isContentEditable) return;
            // Don't fire if a modal is open (search inputs inside the picker etc.)
            if (document.querySelector('.nm-modal-overlay.active')) return;
            // GH #433: the multi-selection, grouping, copy / paste
            const mod = e.ctrlKey || e.metaKey;
            if (e.key === 'Escape' && document.querySelector('.nm-ctx-menu')) { closeCtxMenu(); return; }
            if (e.key === 'Escape' && (multi.size || selectedShapeKey != null)) { clearSelection(); return; }
            if (mod && (e.key === 'x' || e.key === 'X') && canEdit()) {   // cut
                if (copySelection()) {
                    e.preventDefault();
                    if (multi.size) deleteSelection();
                    else if (selectedShapeKey != null) deleteSelectedShape();
                    else if (selectedNodeKey != null) deleteSelectedNode();
                }
                return;
            }
            if (mod && (e.key === 'a' || e.key === 'A')) { e.preventDefault(); selectAllOnPage(); return; }
            if (mod && (e.key === 'c' || e.key === 'C') && !(window.getSelection() && String(window.getSelection()))) { if (copySelection()) e.preventDefault(); return; }
            if (mod && (e.key === 'v' || e.key === 'V')) return;   // handled by the paste event: it may be a picture
            if (mod && (e.key === 'g' || e.key === 'G')) { e.preventDefault(); if (e.shiftKey) ungroupSelection(); else groupSelection(); return; }
            if (multi.size && canEdit() && e.key.indexOf('Arrow') === 0) {
                e.preventDefault();
                const st = e.shiftKey ? 10 : 1;
                nudgeSelection(e.key === 'ArrowLeft' ? -st : e.key === 'ArrowRight' ? st : 0, e.key === 'ArrowUp' ? -st : e.key === 'ArrowDown' ? st : 0);
                return;
            }
            if (multi.size && (e.key === 'Delete' || e.key === 'Backspace')) { e.preventDefault(); deleteSelection(); return; }
            // GH #433: arrow keys nudge the selected shape (Shift = 10px)
            if (selectedShapeKey != null && canEdit() && e.key.indexOf('Arrow') === 0) {
                const sh = findShapeByKey(selectedShapeKey);
                if (sh) {
                    e.preventDefault();
                    const step = e.shiftKey ? 10 : 1;
                    if (e.key === 'ArrowLeft') sh.x -= step;
                    if (e.key === 'ArrowRight') sh.x += step;
                    if (e.key === 'ArrowUp') sh.y -= step;
                    if (e.key === 'ArrowDown') sh.y += step;
                    rerenderShape(sh);
                    renderConnectors();
                    syncShapePanelGeometry();
                    markDirty();
                }
                return;
            }
            if (e.key !== 'Delete' && e.key !== 'Backspace') return;
            if (selectedShapeKey != null) {
                e.preventDefault();
                deleteSelectedShape();
            } else if (selectedConnectorKey != null) {
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
            shapes = data.shapes || [];   // GH #433
            pages = data.pages || [];
            layers = data.layers || [];
            kbRelated = (data.articles && data.articles.related) || [];   // GH #433: Knowledge articles
            kbTitles = (data.articles && data.articles.titles && !Array.isArray(data.articles.titles)) ? data.articles.titles : {};
            canReadKb = !!data.can_read_knowledge;
            renderArticlesPanel();
            ensurePagesLayers();
            renderHeader();
            applyReadOnlyState();
            renderNodes();
            renderShapes();
            renderPageTabs();
            renderLayersPanel();
            updatePageButtonLabel();
            renderPageOutline();
            renderBrandHeaderFooter();
            setStatus(autosaveOn ? 'saved' : 'off');
        } catch (e) {
            elTitle.textContent = t('network-mapper.editor.load_failed');
            elStatus.textContent = e.message;
        }
    }

    /**
     * Loads the active CMDB classes into the class lookup and renders the palette, or shows an error in
     * the palette.
     */
    async function loadClasses() {
        // GH #217: CMDB switched off - say so instead of a load failure. Not granted keeps the old error.
        if (!window.NM_CMDB && window.NM_CMDB_OFF) {
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">' + escapeHtml(window.NM_CMDB_OFF) + '</div>';
            return;
        }
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

    /**
     * Fills in the page title, diagram title, version pill (current or read-only) and the author, created
     * and updated meta row.
     */
    function renderHeader() {
        // GH #173: the diagram's name appended to the server's pageTitle(), captured once.
        document.title = (document.titleBase = document.titleBase || document.title) + (diagram.title ? ' - ' + diagram.title : '');
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
        // GH #433: only the current version can be renamed - older ones are read-only records.
        if (elTitleEditBtn) elTitleEditBtn.style.display = diagram.is_current ? '' : 'none';

        elMetaRow.style.display = '';
        elMetaAuthor.textContent  = diagram.author_name || t('network-mapper.editor.author_unknown');
        elMetaCreated.textContent = formatDate(diagram.created_datetime);
        elMetaUpdated.textContent = formatDate(diagram.updated_datetime);
    }

    /** Renders one draggable palette tile per CMDB class with its icon and object count. */
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

    /** Puts the dragged palette tile's class id on the drag payload for the canvas drop handler. */
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

    // GH #433: a node's own colour and box size.
    const NODE_COLOURS = ['#dc2626', '#ea580c', '#d97706', '#16a34a', '#0d9488', '#2563eb', '#7c3aed', '#475569'];

    /** True when the node has its own box size (w/h set), rather than the automatic one. */
    function hasBoxSize(n) { return !!(n && (n.w || n.h)); }

    /**
     * A node's outer box in canvas px: its own size, or the automatic one (icon plus padding, with the
     * label hanging about 40px below). Used to frame fit, centre and export.
     */
    function nodeExtent(n) {
        const sz = NODE_SIZES[n.size] || NODE_SIZES.medium;
        return { w: n.w || (sz + NODE_PADDING * 2), h: n.h || (sz + NODE_PADDING * 2 + 40) };
    }

    /** '#rrggbb' as 'rgba(r, g, b, a)' - the soft tint behind a coloured node. */
    function hexToRgba(hex, a) {
        const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
        if (!m) return 'transparent';
        return 'rgba(' + parseInt(m[1], 16) + ', ' + parseInt(m[2], 16) + ', ' + parseInt(m[3], 16) + ', ' + a + ')';
    }

    /** Left edge of the icon inside its node element - centred when the box has its own width. */
    function iconOffsetX(n) {
        const sz = NODE_SIZES[n.size] || NODE_SIZES.medium;
        return hasBoxSize(n) ? (nodeExtent(n).w - sz) / 2 : NODE_PADDING;
    }

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

    /**
     * Handles a palette tile dropped on the canvas - works out a grid-snapped position centred on the
     * cursor and opens the object picker for that class.
     */
    function onCanvasDrop(e) {
        if (!diagram || !diagram.is_current) return;
        e.preventDefault();
        let payload = null;
        try { payload = JSON.parse(e.dataTransfer.getData('text/plain') || '{}'); } catch (_) { /* ignore */ }
        if (payload && payload.kind === 'nm-shape') {   // GH #433: a shape from the Shapes tab
            const at = canvasModelCoords(e);
            addShapeAt(String(payload.shape_type || ''), at.x, at.y, { icon_id: payload.icon_id || null });
            return;
        }
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

    /** Opens the object picker for a class, remembering the drop position, and loads the class's full object list. */
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

    /** Closes the object picker modal and clears the picked class. */
    function closeObjectPicker() {
        elPickerModal.classList.remove('active');
        pickerClassId = null;
    }

    /**
     * Fetches the CMDB objects of the picker's class, filtered by the search text when there is any, and
     * renders them.
     */
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

    /**
     * Renders the picker results, leaving out objects already on the canvas, with planned and parent
     * badges and click-to-place.
     */
    function renderPickerResults() {
        // Filter out objects already on the canvas — placing the same object
        // twice would be confusing and save_diagram would persist both.
        const onCanvas = new Set(pageNodes().map(n => n.cmdb_object_id));   // GH #433: on this page
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

    /** Debounces the picker search box by 200ms before refetching results. */
    function onPickerSearchInput(value) {
        clearTimeout(pickerSearchTimer);
        pickerSearchTimer = setTimeout(() => fetchPickerResults(value), 200);
    }

    /**
     * Keyboard handling in the object picker - arrow keys move the highlight, Enter places the highlighted
     * object, Escape closes.
     */
    function onPickerKeyDown(e) {
        const onCanvas = new Set(pageNodes().map(n => n.cmdb_object_id));   // GH #433: on this page
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

    /**
     * Places the picked CMDB object as a new unsaved node at the drop position, selects it and marks the
     * diagram dirty.
     */
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
        placeOnPage(node);   // GH #433
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

    /** Returns the node whose selection key matches, or null. */
    function findNodeByKey(key) {
        if (key == null) return null;
        return nodes.find(n => nodeKey(n) === key) || null;
    }

    /** Rebuilds every node element on the canvas, toggles the empty state and redraws the connectors. */
    function renderNodes() {
        // Clear existing node DOM; keep the empty-state element + the SVG
        // layer (connectors render into it separately via renderConnectors).
        // Nodes live inside elCanvasInner (the zoom-transformed wrapper),
        // not elCanvas itself.
        Array.from(elCanvasInner.querySelectorAll('.nm-node')).forEach(el => el.remove());

        const shown = visibleNodes();   // GH #433: the current page, visible layers
        if (!shown.length) {
            updateCanvasEmpty();   // GH #433: shapes count too
            renderConnectors();
            return;
        }
        if (elCanvasEmpty) elCanvasEmpty.style.display = 'none';

        shown.forEach(n => {
            const el = buildNodeEl(n);
            elCanvasInner.appendChild(el);
            syncIconY(el, n);
        });
        renderConnectors();
    }

    /**
     * Builds the DOM element for one node - icon, planned pill, label and, on the current version, the
     * four edge handles that start a connector drag.
     */
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
        if (itemLocked(n)) el.classList.add('is-locked');   // GH #433: on a locked layer
        if (multi.has('n:' + nodeKey(n))) el.classList.add('in-multi');
        if (hasBoxSize(n)) {
            // GH #433: its own box - the label gets the full width and is no longer cut to two lines.
            el.classList.add('has-size');
            el.style.boxSizing = 'border-box';
            el.style.width = nodeExtent(n).w + 'px';
            if (n.h) el.style.minHeight = n.h + 'px';
        }
        if (n.colour_override) {
            // GH #433: custom properties, not inline border/background, so the selected ring still wins.
            el.classList.add('has-colour');
            el.style.setProperty('--nmc', n.colour_override);
            el.style.setProperty('--nmc-bg', hexToRgba(n.colour_override, 0.12));
        }
        if (n.style && window.nmNodeBoxCss) {
            // GH #433: the node's own fill, border, shadow, corners and opacity (font goes on the label below)
            el.classList.add('has-style');
            el.style.cssText += window.nmNodeBoxCss(n.style);
        }

        // Edge-handle positions: anchored to the icon's bounding box (which is
        // sizePx square, offset by NODE_PADDING inside the node element). The
        // label can extend wider/lower than the icon — anchoring to the icon
        // keeps the handles in predictable spots regardless of label length.
        const pad = NODE_PADDING;
        const ix  = iconOffsetX(n);          // GH #433: the icon is centred in a box of its own width
        const cx  = ix + sizePx / 2;
        const cy  = pad + sizePx / 2;
        const x1  = ix + sizePx;
        const y1  = pad + sizePx;
        const handleHtml = (diagram && diagram.is_current) ? (
            '<div class="nm-edge-handle" data-side="top"    style="left:' + cx + 'px; top:'  + pad + 'px;"></div>' +
            '<div class="nm-edge-handle" data-side="right"  style="left:' + x1 + 'px; top:'  + cy  + 'px;"></div>' +
            '<div class="nm-edge-handle" data-side="bottom" style="left:' + cx + 'px; top:'  + y1  + 'px;"></div>' +
            '<div class="nm-edge-handle" data-side="left"   style="left:' + ix + 'px; top:' + cy  + 'px;"></div>' +
            '<div class="nm-resize-handle" title="' + escapeAttr(t('network-mapper.detail.box_hint')) + '"></div>'
        ) : '';

        el.innerHTML =
            handleHtml +
            '<div class="nm-node-icon" style="height:' + sizePx + 'px;">' + iconSvg + '</div>' +
            (n.is_planned ? '<span class="nm-node-planned-pill">' + escapeHtml(t('network-mapper.detail.planned_pill')) + '</span>' : '') +
            '<div class="nm-node-label" title="' + escapeAttr(n.name + ' (' + (n.class_name || '') + ')') + '"'
                + (n.style && window.nmTextCss ? ' style="' + escapeAttr(window.nmTextCss(n.style)) + '"' : '') + '>' +
                escapeHtml(n.name) +
            '</div>';

        addKbMark(el, n);   // GH #433
        el.addEventListener('mousedown', onNodeMouseDown);
        // Edge handles initiate connector drag (separate from node move drag —
        // startConnectDrag stopPropagation()s so the node's own mousedown
        // doesn't also fire)
        el.querySelectorAll('.nm-edge-handle').forEach(h => {
            h.addEventListener('mousedown', e => startConnectDrag(e, n, h.dataset.side));
        });
        const rh = el.querySelector('.nm-resize-handle');
        if (rh) rh.addEventListener('mousedown', e => startNodeResize(e, n));
        return el;
    }

    /** Swaps one node's element for a freshly built one (after a colour, size or icon size change). */
    function rerenderNode(n) {
        const el = elCanvasInner.querySelector('.nm-node[data-key="' + cssEscape(nodeKey(n)) + '"]');
        if (!el) return;
        const fresh = buildNodeEl(n);
        el.replaceWith(fresh);
        syncIconY(fresh, n);
    }

    /**
     * GH #433: in a box with its own height the icon and name are centred vertically as one group, so
     * where the icon sits depends on how far the name wraps - only the drawn element knows. Reads it
     * once the node is in the page, keeps it on the node (n._iconY, never saved) for nodeIconBBox, and
     * moves the four connection dots onto the icon.
     */
    function syncIconY(el, n) {
        const icon = el ? el.querySelector('.nm-node-icon') : null;
        const sz = NODE_SIZES[n.size] || NODE_SIZES.medium;
        const iy = (n.h && icon) ? icon.offsetTop : NODE_PADDING;
        n._iconY = iy;
        if (!el || iy === NODE_PADDING) return;
        el.querySelectorAll('.nm-edge-handle').forEach(h => {
            const side = h.dataset.side;
            h.style.top = (side === 'top' ? iy : side === 'bottom' ? iy + sz : iy + sz / 2) + 'px';
        });
    }

    // ---- GH #433: resize a node's box by its bottom-right corner ----
    let nodeResize = null;

    /** Starts dragging the selected node's corner handle. */
    function startNodeResize(e, n) {
        if (e.button !== 0 || !diagram || !diagram.is_current) return;
        e.stopPropagation();
        e.preventDefault();
        const el = e.currentTarget.closest('.nm-node');
        const sz = NODE_SIZES[n.size] || NODE_SIZES.medium;
        nodeResize = {
            key: nodeKey(n),
            start: canvasModelCoords(e),
            w: el.offsetWidth,
            h: el.offsetHeight,
            minW: sz + NODE_PADDING * 2,
            minH: sz + NODE_PADDING * 2 + 20,
            moved: false
        };
        document.addEventListener('mousemove', onNodeResizeMove);
        document.addEventListener('mouseup', onNodeResizeUp);
    }

    /** Sizes the box to the grid-snapped cursor, never smaller than its icon. */
    function onNodeResizeMove(e) {
        if (!nodeResize) return;
        const n = findNodeByKey(nodeResize.key);
        if (!n) return;
        const c = canvasModelCoords(e);
        const w = Math.min(2000, Math.max(nodeResize.minW, snap(nodeResize.w + c.x - nodeResize.start.x)));
        const h = Math.min(2000, Math.max(nodeResize.minH, snap(nodeResize.h + c.y - nodeResize.start.y)));
        if (w === n.w && h === n.h) return;
        n.w = w;
        n.h = h;
        nodeResize.moved = true;
        rerenderNode(n);
        renderConnectors();
    }

    /** Ends a corner drag, marks the diagram dirty and refreshes the side panel's size line. */
    function onNodeResizeUp() {
        document.removeEventListener('mousemove', onNodeResizeMove);
        document.removeEventListener('mouseup', onNodeResizeUp);
        if (!nodeResize) return;
        const moved = nodeResize.moved;
        const n = findNodeByKey(nodeResize.key);
        nodeResize = null;
        if (moved) {
            markDirty();
            if (n) renderNodeStyleControls(n);
        }
    }

    /**
     * Returns the identifier connectors use to reference a node - its real id, or its negative temp id
     * when unsaved.
     */
    function nodeRef(n) {
        // Stable cross-save identifier for connector references. Real id wins;
        // tempId (negative integer) for newly-placed nodes. save_diagram.php
        // looks up both forms in its nodeIdMap, so a connector between two
        // brand-new nodes resolves cleanly on the first save.
        return n.id || n.tempId;
    }

    /** Returns the node matching a connector reference (real id or temp id), or null. */
    function findNodeByRef(ref) {
        if (ref == null) return null;
        return nodes.find(n => n.id === ref || n.tempId === ref) || null;
    }

    /**
     * Returns the bounding box and centre of a node's icon in model coordinates, used to anchor connectors
     * and hit-test drops.
     */
    function nodeIconBBox(n) {
        // Icon-only bounding box, used for connector endpoint placement and
        // edge-handle anchoring. We use icon bbox (not the full node bbox)
        // because the label below the icon can wrap to 2 lines of variable
        // height — anchoring to the icon keeps connector endpoints stable
        // regardless of label content.
        const sizePx = NODE_SIZES[n.size] || NODE_SIZES.medium;
        const pad = NODE_PADDING;
        const ix = iconOffsetX(n);   // GH #433: centred when the box has its own width
        const iy = (n._iconY != null) ? n._iconY : pad;   // ...and its own height (syncIconY)
        return {
            x: n.x + ix,
            y: n.y + iy,
            w: sizePx,
            h: sizePx,
            cx: n.x + ix + sizePx / 2,
            cy: n.y + iy + sizePx / 2
        };
    }

    /**
     * Selects a node by key (null clears), clears any connector selection, and opens or closes the detail
     * panel to match.
     */
    function selectNode(key) {
        if (key != null && multi.size) { multi = new Set(); applyMultiClasses(); }   // GH #433
        if (selectedNodeKey === key) return;
        if (key != null && selectedShapeKey != null) selectShape(null);   // GH #433
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

    /** Selects the clicked node and, on the current version, starts a node drag. */
    function onNodeMouseDown(e) {
        if (e.button !== 0) return;
        const key = e.currentTarget.dataset.key;
        const n = findNodeByKey(key);
        if (!n) return;
        if (isPresenting() && n.article_id) { e.preventDefault(); openArticle(n.article_id); return; }   // GH #433
        if (multiMouseDown(e, 'n', n)) return;   // GH #433: Shift-click, the selection, a group
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

    /** Moves the dragged node to the grid-snapped cursor position and redraws the connectors. */
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

    /** Ends a node drag and marks the diagram dirty if the node actually moved. */
    function onNodeMouseUp() {
        document.removeEventListener('mousemove', onNodeMouseMove);
        document.removeEventListener('mouseup', onNodeMouseUp);
        if (!nodeDrag) return;
        const moved = nodeDrag.moved;
        nodeDrag = null;
        if (moved) markDirty();
    }

    /**
     * Removes the selected node and every connector touching it, then clears the selection and marks the
     * diagram dirty.
     */
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

    /** Returns the connector whose selection key matches, or null. */
    function findConnectorByKey(key) {
        if (key == null) return null;
        return connectors.find(c => connectorKey(c) === key) || null;
    }

    /** Selects a connector by key (null clears), deselecting any node, and redraws the connectors. */
    function selectConnector(key) {
        if (selectedConnectorKey === key) return;
        if (key != null && selectedShapeKey != null) selectShape(null);   // GH #433
        if (key != null && multi.size) { multi = new Set(); applyMultiClasses(); }
        const had = selectedConnectorKey != null;
        selectedConnectorKey = key;
        if (key != null) selectNode(null);
        renderConnectors();
        if (key != null) renderConnectorPanel();          // GH #433: its Format panel
        else if (had && selectedShapeKey == null && !multi.size) closeShapePanel();
    }

    /**
     * Redraws every connector in the SVG layer as an arrowed line with a wide click target and optional
     * label; double-click edits the label.
     */
    function renderConnectors() {
        if (!elSvgLayer) return;
        // Clear all rendered connector <g>s; keep the <defs> markers in place
        Array.from(elSvgLayer.querySelectorAll('.nm-connector-group')).forEach(g => g.remove());

        connectors.forEach(c => {
            // GH #433: either end may be a node or a shape.
            const from = endOf(c, 'from');
            const to   = endOf(c, 'to');
            if (!from || !to) return; // an end was deleted but the connector lingered - skip silently
            if (!itemVisible(from.it) || !itemVisible(to.it)) return;   // another page, or a hidden layer

            const pts = calcConnectorPoints(from.box, to.box);
            const st = connStyle(c);
            const d = connPath(pts, st.routing);
            const mid = connMid(pts, st.routing);

            const g = document.createElementNS(SVG_NS, 'g');
            g.classList.add('nm-connector-group');
            const cKey = connectorKey(c);
            g.dataset.connKey = cKey;
            const isSel = selectedConnectorKey === cKey;

            // Wide invisible hit-area underneath for easier clicking
            const hit = document.createElementNS(SVG_NS, 'path');
            hit.setAttribute('d', d);
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
            path.setAttribute('d', d);
            path.classList.add('nm-connector-line');
            if (isSel) path.classList.add('selected');
            else {
                // Inline style, not attributes: the stylesheet's own stroke would beat an attribute,
                // while the selected rule above still wins over nothing.
                path.style.stroke = st.colour;
                path.style.strokeWidth = String(st.width);
            }
            const dash = connDash(c.line_style, st.width);
            if (dash) { path.style.strokeDasharray = dash; if (c.line_style === 'dotted') path.style.strokeLinecap = 'round'; }
            const mc = isSel ? '#06b6d4' : st.colour;
            if (st.arrowEnd !== 'none') path.setAttribute('marker-end', 'url(#' + ensureMarker(st.arrowEnd, mc, st.width) + ')');
            if (st.arrowStart !== 'none') path.setAttribute('marker-start', 'url(#' + ensureMarker(st.arrowStart, mc, st.width) + ')');
            g.appendChild(path);

            if (c.label) {
                const mx = mid.x, my = mid.y;
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

    // ---- GH #433: connector ends, look and routing ----
    const CONN_DEFAULT = { colour: '#64748b', width: 2, arrowStart: 'none', arrowEnd: 'arrow', routing: 'straight' };
    function connStyle(c) { return Object.assign({}, CONN_DEFAULT, c.style || {}); }
    function shapeByUid(uid) { return uid ? (shapes.find(s => s.uid === uid) || null) : null; }
    function shapeBox(s) { return { x: s.x, y: s.y, w: s.w, h: s.h, cx: s.x + s.w / 2, cy: s.y + s.h / 2 }; }
    function newUid() { return 'u' + Date.now().toString(36).slice(-5) + Math.random().toString(36).slice(2, 8); }
    function ensureShapeUid(s) { if (!s.uid) s.uid = newUid(); return s.uid; }
    /** One end of a connector: {kind 'n' | 's', it, box} or null when it is gone. */
    function endOf(c, side) {
        const uid = c[side + '_shape_uid'];
        if (uid) { const s = shapeByUid(uid); return s ? { kind: 's', it: s, box: shapeBox(s) } : null; }
        const n = findNodeByRef(c[side + '_node_id']);
        if (!n) return null;
        // The icon for top / left / right, but the bottom of the whole node - a line arriving from
        // below would otherwise end behind the name, arrowhead and all.
        const ib = nodeIconBBox(n);
        const bottom = Math.max(ib.y + ib.h, n.y + nodeExtent(n).h - 6);
        return { kind: 'n', it: n, box: Object.assign({}, ib, { h: bottom - ib.y }) };
    }
    /** True when a connector has an end on this shape. */
    function touchesShape(c, s) { return !!(s.uid && (c.from_shape_uid === s.uid || c.to_shape_uid === s.uid)); }

    function connPath(p, routing) {
        if (routing === 'elbow') {
            if (p.axis === 'h') { const mx = (p.x1 + p.x2) / 2; return 'M' + p.x1 + ',' + p.y1 + ' H' + mx + ' V' + p.y2 + ' H' + p.x2; }
            const my = (p.y1 + p.y2) / 2; return 'M' + p.x1 + ',' + p.y1 + ' V' + my + ' H' + p.x2 + ' V' + p.y2;
        }
        if (routing === 'curved') {
            if (p.axis === 'h') { const k = (p.x2 - p.x1) / 2; return 'M' + p.x1 + ',' + p.y1 + ' C' + (p.x1 + k) + ',' + p.y1 + ' ' + (p.x2 - k) + ',' + p.y2 + ' ' + p.x2 + ',' + p.y2; }
            const k = (p.y2 - p.y1) / 2; return 'M' + p.x1 + ',' + p.y1 + ' C' + p.x1 + ',' + (p.y1 + k) + ' ' + p.x2 + ',' + (p.y2 - k) + ' ' + p.x2 + ',' + p.y2;
        }
        return 'M' + p.x1 + ',' + p.y1 + ' L' + p.x2 + ',' + p.y2;
    }
    /** Where the label sits: the middle of the line, whichever way it runs. */
    function connMid(p) { return { x: (p.x1 + p.x2) / 2, y: (p.y1 + p.y2) / 2 }; }
    function connDash(lineStyle, w) {
        if (lineStyle === 'dashed') return (w * 3 + 2) + ' ' + (w * 2 + 2);
        if (lineStyle === 'dotted') return '0.1 ' + (w * 2 + 2);
        return '';
    }
    /** An arrowhead marker for this type, colour and width, made once and kept in the SVG's <defs>. */
    function ensureMarker(type, colour, width) {
        const id = 'nm-mk-' + type + '-' + String(colour).replace('#', '') + '-' + String(width).replace('.', '_');
        if (document.getElementById(id)) return id;
        const defs = elSvgLayer.querySelector('defs');
        const s = 4 + width * 3;                     // a heavier line gets a bigger head
        const m = document.createElementNS(SVG_NS, 'marker');
        m.setAttribute('id', id);
        m.setAttribute('viewBox', '0 0 10 10');
        m.setAttribute('markerUnits', 'userSpaceOnUse');
        m.setAttribute('markerWidth', String(s));
        m.setAttribute('markerHeight', String(s));
        m.setAttribute('refY', '5');
        m.setAttribute('orient', 'auto-start-reverse');
        let inner;
        if (type === 'open') { m.setAttribute('refX', '9'); inner = '<polyline points="1,1 9,5 1,9" fill="none" stroke="' + colour + '" stroke-width="1.6" stroke-linejoin="round"/>'; }
        else if (type === 'diamond') { m.setAttribute('refX', '10'); inner = '<polygon points="0,5 5,1 10,5 5,9" fill="' + colour + '"/>'; }
        else if (type === 'circle') { m.setAttribute('refX', '9'); inner = '<circle cx="5" cy="5" r="4" fill="' + colour + '"/>'; }
        else { m.setAttribute('refX', '10'); inner = '<polygon points="0,1 10,5 0,9" fill="' + colour + '"/>'; }
        m.innerHTML = inner;
        defs.appendChild(m);
        return id;
    }

    // ---- GH #433: the connector's Format panel ----
    function renderConnectorPanel() {
        const c = findConnectorByKey(selectedConnectorKey);
        if (!c || !elShapePanel) return;
        closeDetail();
        elShapePanel.classList.add('open');
        elShapePanel.setAttribute('aria-hidden', 'false');
        const ed = canEdit();
        const dis = ed ? '' : ' disabled';
        const st = connStyle(c);
        document.getElementById('spIcon').innerHTML = '<svg width="26" height="26" viewBox="0 0 26 26"><path d="M3 21 L21 5" stroke="currentColor" stroke-width="2"/><polygon points="16,4 23,3 22,10" fill="currentColor"/></svg>';
        document.getElementById('spName').textContent = t('network-mapper.connector.panel_title');
        const C = k => escapeHtml(t('network-mapper.connector.' + k));
        const arrows = ['none', 'arrow', 'open', 'diamond', 'circle'];
        const arrowSel = (id, v) => '<select class="nm-fmt-select" id="' + id + '"' + dis + '>' + arrows.map(a => '<option value="' + a + '"' + (a === v ? ' selected' : '') + '>' + C('arrow_' + a) + '</option>').join('') + '</select>';
        const body = document.getElementById('spBody');
        body.innerHTML = '<div class="nm-fmt-section"><div class="nm-fmt-title">' + C('line') + '</div>'
            + '<div class="nm-fmt-row"><span>' + C('label') + '</span><input type="text" class="nm-fmt-n nm-conn-label" id="cpLabel" maxlength="255" value="' + escapeAttr(c.label || '') + '"' + dis + '></div>'
            + '<div class="nm-fmt-row"><span>' + escapeHtml(t('network-mapper.connector.style')) + '</span>'
            + seg([['solid', t('network-mapper.connector.solid')], ['dashed', t('network-mapper.connector.dashed')], ['dotted', t('network-mapper.style.dotted')]], c.line_style || 'solid', 'cpDash', !ed) + '</div>'
            + '<div class="nm-fmt-row"><span>' + escapeHtml(t('network-mapper.style.colour')) + '</span><span class="nm-fmt-inline">'
            + '<input type="color" class="nm-fmt-colour" id="cpColour" value="' + escapeAttr(st.colour) + '"' + dis + '>'
            + '<label class="nm-fmt-num nm-fmt-num-inline"><span>' + escapeHtml(t('network-mapper.style.width')) + '</span><input type="number" id="cpWidth" value="' + st.width + '" min="0.5" max="12" step="0.5"' + dis + '></label></span></div>'
            + '<div class="nm-fmt-row"><span>' + C('start') + '</span>' + arrowSel('cpStart', st.arrowStart) + '</div>'
            + '<div class="nm-fmt-row"><span>' + C('end') + '</span>' + arrowSel('cpEnd', st.arrowEnd) + '</div>'
            + '<div class="nm-fmt-row"><span>' + C('routing') + '</span>'
            + seg([['straight', t('network-mapper.connector.straight')], ['elbow', t('network-mapper.connector.elbow')], ['curved', t('network-mapper.connector.curved')]], st.routing, 'cpRoute', !ed) + '</div>'
            + '<div class="nm-fmt-btns">'
            + '<button type="button" class="nm-detail-icon-btn" id="cpSwap"' + dis + '>' + C('swap') + '</button>'
            + '<button type="button" class="nm-detail-icon-btn nm-detail-icon-reset" id="cpReset"' + dis + '>' + escapeHtml(t('network-mapper.detail.icon_reset')) + '</button>'
            + '<button type="button" class="nm-detail-icon-btn nm-fmt-danger" id="cpDel"' + dis + '>' + escapeHtml(t('common.delete')) + '</button></div>'
            + (ed ? '<div class="nm-detail-hint">' + C('hint') + '</div>' : '') + '</div>';
        if (!ed) return;
        const apply = patch => { c.style = Object.assign({}, c.style || {}, patch); renderConnectors(); markDirty(); };
        document.getElementById('cpLabel').addEventListener('change', e => { const v = e.target.value.trim(); c.label = v || null; renderConnectors(); markDirty(); });
        body.querySelectorAll('[data-seg="cpDash"] button').forEach(b => b.addEventListener('click', () => { c.line_style = b.dataset.value; renderConnectors(); markDirty(); renderConnectorPanel(); }));
        body.querySelectorAll('[data-seg="cpRoute"] button').forEach(b => b.addEventListener('click', () => { apply({ routing: b.dataset.value }); renderConnectorPanel(); }));
        document.getElementById('cpColour').addEventListener('input', e => apply({ colour: e.target.value }));
        document.getElementById('cpWidth').addEventListener('change', e => { const v = parseFloat(e.target.value); if (v >= 0.5 && v <= 12) apply({ width: v }); });
        document.getElementById('cpStart').addEventListener('change', e => apply({ arrowStart: e.target.value }));
        document.getElementById('cpEnd').addEventListener('change', e => apply({ arrowEnd: e.target.value }));
        document.getElementById('cpSwap').addEventListener('click', () => {
            ['node_id', 'shape_uid'].forEach(k => { const t2 = c['from_' + k]; c['from_' + k] = c['to_' + k]; c['to_' + k] = t2; });
            renderConnectors(); markDirty();
        });
        document.getElementById('cpReset').addEventListener('click', () => { c.style = null; c.line_style = 'solid'; renderConnectors(); markDirty(); renderConnectorPanel(); });
        document.getElementById('cpDel').addEventListener('click', () => { deleteSelectedConnector(); closeShapePanel(); });
    }

    /**
     * Returns the start and end points of a connector, on the facing sides of the two icon boxes along
     * whichever axis has the larger gap.
     */
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
        return { x1: x1, y1: y1, x2: x2, y2: y2, axis: Math.abs(dx) > Math.abs(dy) ? 'h' : 'v' };   // GH #433: for elbow / curved
    }

    /** Removes the selected connector and marks the diagram dirty. */
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

    /** Shows an inline text box at the connector midpoint to edit its label - Enter or blur saves, Escape cancels. */
    function promptConnectorLabel(c) {
        if (!diagram || !diagram.is_current) return;
        const from = endOf(c, 'from');
        const to   = endOf(c, 'to');
        if (!from || !to) return;
        const mid = connMid(calcConnectorPoints(from.box, to.box));
        const mx = mid.x;
        const my = mid.y;

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
        /**
         * Removes the label input and, unless cancelled or unchanged, stores the trimmed label (empty becomes
         * null) and marks the diagram dirty.
         */
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
    /** Starts drawing a new connector from the given edge handle of a node. */
    function startConnectDrag(e, fromNode, side) { startConnectFrom(e, { kind: 'n', it: fromNode }, side); }

    /** GH #433: starts drawing a connector from one of a node's or a shape's four connection dots. */
    function startConnectFrom(e, end, side) {
        if (!diagram || !diagram.is_current) return;
        e.stopPropagation();
        e.preventDefault();
        const box = end.kind === 'n' ? nodeIconBBox(end.it) : shapeBox(end.it);
        let sx, sy;
        switch (side) {
            case 'top':    sx = box.cx;         sy = box.y; break;
            case 'bottom': sx = box.cx;         sy = box.y + box.h; break;
            case 'left':   sx = box.x;          sy = box.cy; break;
            case 'right':
            default:       sx = box.x + box.w;  sy = box.cy; break;
        }
        connectDrag = { from: end, startX: sx, startY: sy };
        document.addEventListener('mousemove', onConnectDragMove);
        document.addEventListener('mouseup',   onConnectDragUp);
    }

    /** Draws the temporary line from the dot to the cursor while a connector is being dragged. */
    function onConnectDragMove(e) {
        if (!connectDrag || !elSvgLayer) return;
        const cursor = canvasModelCoords(e);
        let line = elSvgLayer.querySelector('.nm-temp-connector');
        if (!line) {
            line = document.createElementNS(SVG_NS, 'line');
            line.classList.add('nm-temp-connector');
            elSvgLayer.appendChild(line);
        }
        line.setAttribute('x1', String(connectDrag.startX));
        line.setAttribute('y1', String(connectDrag.startY));
        line.setAttribute('x2', String(cursor.x));
        line.setAttribute('y2', String(cursor.y));
    }

    /** Ends a connector drag: dropped on a node or a shape (not itself), it makes a connector. */
    function onConnectDragUp(e) {
        document.removeEventListener('mousemove', onConnectDragMove);
        document.removeEventListener('mouseup',   onConnectDragUp);
        if (!connectDrag) return;
        if (elSvgLayer) elSvgLayer.querySelectorAll('.nm-temp-connector').forEach(el => el.remove());
        const cursor = canvasModelCoords(e);
        const from = connectDrag.from;
        connectDrag = null;
        const node = findNodeAt(cursor.x, cursor.y);
        const to = node ? { kind: 'n', it: node } : (findShapeAt(cursor.x, cursor.y, from.kind === 's' ? from.it : null));
        if (!to || to.it === from.it) return;   // nowhere, or itself
        const c = { id: null, _tempKey: nextTempId--, cmdb_relationship_id: null, label: null, line_style: 'solid', style: null,
                    from_node_id: null, to_node_id: null, from_shape_uid: null, to_shape_uid: null };
        if (from.kind === 'n') c.from_node_id = nodeRef(from.it); else c.from_shape_uid = ensureShapeUid(from.it);
        if (to.kind === 'n') c.to_node_id = nodeRef(to.it); else c.to_shape_uid = ensureShapeUid(to.it);
        // The same two ends the same way round already joined: nothing to add.
        if (connectors.some(x => x.from_node_id === c.from_node_id && x.to_node_id === c.to_node_id
                              && (x.from_shape_uid || null) === c.from_shape_uid && (x.to_shape_uid || null) === c.to_shape_uid)) return;
        connectors.push(c);
        renderConnectors();
        selectConnector(connectorKey(c));
        markDirty();
    }

    /** GH #433: the topmost visible, unlocked shape under a point (front layer first), or null. */
    function findShapeAt(x, y, except) {
        const hits = visibleShapes().filter(s => s !== except && !itemLocked(s) && x >= s.x && x <= s.x + s.w && y >= s.y && y <= s.y + s.h);
        if (!hits.length) return null;
        hits.sort((a, b) => ((b.layer === 'front') - (a.layer === 'front')) || (layerRank(b) - layerRank(a)) || ((b.z || 0) - (a.z || 0)));
        return { kind: 's', it: hits[0] };
    }

    /** Returns the node whose icon box contains the given model coordinates, or null. */
    function findNodeAt(x, y) {
        // Hit-test the icon bounding box, not the full node element — matches
        // how connectors anchor and feels more precise when dropping near a
        // node edge.
        return nodes.find(n => {
            if (!itemVisible(n) || itemLocked(n)) return false;   // GH #433
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
        if (elNdCmdbLink) elNdCmdbLink.href = '../cmdb/object.php?id=' + n.cmdb_object_id;   // GH #217: absent without CMDB

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
        // GH #217: the button and the properties are absent without CMDB.
        if (elNdAddRelatedBtn) {
            elNdAddRelatedBtn.disabled = !editable;
            elNdAddRelatedBtn.title = !editable
                ? t('network-mapper.editor.readonly_generic_title')
                : t('network-mapper.detail.add_related_title');
        }

        // Kick off the CMDB properties fetch. Selection swaps mid-flight are
        // handled by stamping currentPropertiesObjectId — the resolver checks
        // it's still the latest before rendering.
        if (window.NM_CMDB) loadNodeProperties(n.cmdb_object_id);
        else if (elNdPropertiesSection) elNdPropertiesSection.style.display = 'none';
        renderNodeStyleControls(n);
        renderNodeStyleEditor(n);
    }

    /**
     * GH #433: the side panel's Colour, Icon size and Box size rows for one node. Disabled on a
     * read-only version.
     */
    function renderNodeStyleControls(n) {
        const editable = !!(diagram && diagram.is_current);
        const colours = document.getElementById('ndColours');
        if (colours) {
            const cur = (n.colour_override || '').toLowerCase();
            let html = NODE_COLOURS.map(c =>
                '<button type="button" class="nm-swatch' + (c === cur ? ' active' : '') + '" data-colour="' + c + '"'
                + ' style="background:' + c + ';" title="' + c + '"' + (editable ? '' : ' disabled') + '></button>').join('');
            const custom = cur && NODE_COLOURS.indexOf(cur) === -1;
            html += '<label class="nm-swatch-custom' + (custom ? ' active' : '') + '" title="' + escapeAttr(t('network-mapper.detail.colour_custom_title')) + '">'
                + '<input type="color" value="' + (cur || '#2563eb') + '"' + (editable ? '' : ' disabled') + '></label>';
            if (editable && cur) {
                html += '<button type="button" class="nm-detail-icon-btn nm-detail-icon-reset nm-colour-reset" title="'
                    + escapeAttr(t('network-mapper.detail.colour_reset_title')) + '">' + escapeHtml(t('network-mapper.detail.icon_reset')) + '</button>';
            }
            colours.innerHTML = html;
            colours.querySelectorAll('.nm-swatch').forEach(b => b.addEventListener('click', () => setNodeColour(n, b.dataset.colour)));
            const input = colours.querySelector('input[type="color"]');
            if (input) input.addEventListener('change', () => setNodeColour(n, input.value));
            const reset = colours.querySelector('.nm-colour-reset');
            if (reset) reset.addEventListener('click', () => setNodeColour(n, null));
        }
        const sizes = document.getElementById('ndIconSize');
        if (sizes) {
            const curSize = n.size || 'medium';
            sizes.innerHTML = ['small', 'medium', 'large'].map(s =>
                '<button type="button" data-size="' + s + '" class="' + (s === curSize ? 'active' : '') + '"' + (editable ? '' : ' disabled') + '>'
                + escapeHtml(t('network-mapper.detail.size_' + s)) + '</button>').join('');
            sizes.querySelectorAll('button').forEach(b => b.addEventListener('click', () => setNodeIconSize(n, b.dataset.size)));
        }
        const box = document.getElementById('ndBoxSize');
        if (box) {
            const ext = nodeExtent(n);
            box.textContent = hasBoxSize(n)
                ? t('network-mapper.detail.box_value', { w: ext.w, h: ext.h })
                : t('network-mapper.detail.box_auto');
        }
        const boxReset = document.getElementById('ndBoxReset');
        if (boxReset) boxReset.style.display = (editable && hasBoxSize(n)) ? '' : 'none';
        const boxHint = document.getElementById('ndBoxHint');
        if (boxHint) boxHint.style.display = editable ? '' : 'none';
        const lay = document.getElementById('ndLayer');   // GH #433
        if (lay) { lay.innerHTML = layerSelectHtml(n, 'ndLayerSel'); bindLayerSelect('ndLayerSel', n); }
        renderKbField(document.getElementById('ndArticle'), n);   // GH #433
    }

    /** Sets (or with null clears) the selected node's colour. */
    function setNodeColour(n, hex) {
        if (!diagram || !diagram.is_current) return;
        const v = hex ? String(hex).toLowerCase() : null;
        if ((n.colour_override || null) === v) return;
        n.colour_override = v;
        rerenderNode(n);
        markDirty();
        renderNodeStyleControls(n);
    }

    /** Changes the selected node's icon size (small / medium / large). */
    function setNodeIconSize(n, size) {
        if (!diagram || !diagram.is_current || !NODE_SIZES[size] || (n.size || 'medium') === size) return;
        n.size = size;
        // An own box must still fit the bigger icon.
        const ext = NODE_SIZES[size] + NODE_PADDING * 2;
        if (n.w && n.w < ext) n.w = snap(ext + GRID / 2);
        if (n.h && n.h < ext + 20) n.h = snap(ext + 20 + GRID / 2);
        rerenderNode(n);
        renderConnectors();
        markDirty();
        renderNodeStyleControls(n);
    }

    /** Puts the selected node's box back to the automatic size. */
    function resetBoxSize() {
        const n = selectedNodeKey ? findNodeByKey(selectedNodeKey) : null;
        if (!n || !diagram || !diagram.is_current || !hasBoxSize(n)) return;
        n.w = null;
        n.h = null;
        rerenderNode(n);
        renderConnectors();
        markDirty();
        renderNodeStyleControls(n);
    }

    // ---- CMDB properties (lazy-loaded per selection) ----
    /**
     * Fetches a CMDB object's properties for the detail panel, ignoring the response if the selection has
     * changed meanwhile.
     */
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

    /** Renders the property rows in the detail panel, skipping properties with no value (per-type rules). */
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

    /**
     * Returns the HTML for one property value by type - yes/no, naive date, formatted number, coloured
     * dropdown pill, linked CMDB object, or text (linkified when it is a URL).
     */
    function renderPropertyValue(p) {
        switch (p.property_type) {
            case 'boolean': {
                const yes = p.value === true;
                const cls = yes ? 'bool-yes' : 'bool-no';
                return '<span class="nm-prop-value ' + cls + '">' + escapeHtml(yes ? t('network-mapper.detail.bool_yes') : t('network-mapper.detail.bool_no')) + '</span>';
            }
            case 'date': {
                // Dates come back as ISO strings (YYYY-MM-DD) - a date-only value
                // with no zone, so it is formatted NAIVELY: shifting it into a
                // display timezone could move it to the previous day.
                let display = p.value;
                try {
                    const shown = fmtNaiveDate(String(p.value).replace(' ', 'T'));
                    if (shown) display = shown;
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
            default:
                // GH #312: a person / team / location ... property - the record's name
                // (get_object.php resolves it; a removed or other-company record has none).
                if (p.value_record) {
                    const r = p.value_record;
                    if (!r.name) return '<span class="nm-prop-value">&mdash;</span>';
                    return '<span class="nm-prop-value">' + escapeHtml(r.name) + '</span>';
                }
                // fall through: plain text
            case 'text': {
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

    /** Returns dark or white text colour for a hex pill background, based on its luminance. */
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

    /** Closes the node detail panel and cancels any pending properties render. */
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

    /** Closes the add-related-objects modal and clears its state. */
    function closeRelatedModal() {
        elRelatedModal.classList.remove('active');
        relatedSourceNode = null;
        relatedRows = [];
        relatedSelected.clear();
    }

    /** Fetches the CMDB objects related to the source object and renders them in the modal. */
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

    /**
     * Renders the related objects grouped as outgoing, incoming and property references, with objects
     * already on the canvas disabled.
     */
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
        const onCanvas = new Set(pageNodes().map(n => n.cmdb_object_id));   // GH #433: on this page
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

    /** Enables the related-objects Add button and shows the ticked count on it. */
    function updateRelatedAddBtn() {
        const n = relatedSelected.size;
        elRmAddBtn.disabled = n === 0;
        elRmAddBtn.textContent = n === 0
            ? t('network-mapper.related.add')
            : (n === 1
                ? t('network-mapper.related.add_one', { count: n })
                : t('network-mapper.related.add_many', { count: n }));
    }

    /**
     * Places the ticked related objects in a ring around the source node and draws a connector for each
     * relationship path.
     */
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
        pageNodes().forEach(n => existingByCmdb.set(n.cmdb_object_id, n));   // GH #433: this page
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
        const srcBox = nodeIconBBox(src);
        const srcCx = srcBox.cx;
        const srcCy = srcBox.cy;
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
            placeOnPage(node);   // GH #433
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

    /** Escapes quotes and backslashes in a node key for use in an attribute selector. */
    function cssEscape(s) {
        // Minimal escape for data-key selector use (keys are 'r123' or 't-1' so
        // safe in practice, but be defensive in case the format ever changes)
        return String(s).replace(/(["\\])/g, '\\$1');
    }

    // =========================================================
    //  Shapes (GH #433) - freestanding shapes beside the CMDB nodes
    //  The library and the drawing live in network-mapper-shapes.js; this is
    //  the editing: palette, drop, select, move, resize, type, arrange, and the
    //  Format panel shared with the node detail panel's Style section.
    // =========================================================
    const SHAPE_GRID = 10;
    function snapS(v) { return Math.round(v / SHAPE_GRID) * SHAPE_GRID; }

    /** Stable key for a shape - its real id, or its temp id while unsaved. */
    function shapeKey(s) { return s.id ? 's' + s.id : 't' + s.tempId; }
    function findShapeByKey(key) { return key == null ? null : (shapes.find(s => shapeKey(s) === key) || null); }
    function canEdit() { return !!(diagram && diagram.is_current); }
    function shapeName(type) {
        const v = t('network-mapper.shapes.name_' + type);
        return (v && v !== 'network-mapper.shapes.name_' + type) ? v : type.replace(/_/g, ' ');
    }

    /** Draws every shape. Keeps the array sorted by z so the saved order and the drawn order agree. */
    function renderShapes() {
        if (!elCanvasInner) return;
        elCanvasInner.querySelectorAll('.nm-shape').forEach(el => el.remove());
        shapes.sort((a, b) => (a.z || 0) - (b.z || 0));
        const first = elCanvasInner.querySelector('.nm-node');
        // GH #433: this page's visible layers, a higher layer's shapes over a lower one's
        visibleShapes().sort((a, b) => (layerRank(a) - layerRank(b)) || ((a.z || 0) - (b.z || 0)))
            .forEach(s => elCanvasInner.insertBefore(buildShapeEl(s), first || null));
        updateCanvasEmpty();
        drawGroupOutlines();
    }

    /** Swaps one shape's element for a freshly built one. */
    function rerenderShape(s) {
        const el = elCanvasInner.querySelector('.nm-shape[data-key="' + cssEscape(shapeKey(s)) + '"]');
        if (el) el.replaceWith(buildShapeEl(s)); else renderShapes();
    }

    /** Shows the "empty diagram" hint only when there are neither nodes nor shapes. */
    function updateCanvasEmpty() {
        if (elCanvasEmpty) elCanvasEmpty.style.display = (visibleNodes().length || visibleShapes().length) ? 'none' : '';
    }

    /** Builds one shape: its SVG, its text, and - when selected on the current version - 8 resize handles. */
    function buildShapeEl(s) {
        const key = shapeKey(s);
        const st = window.nmShapeStyle ? window.nmShapeStyle(s.shape_type, s.style) : {};
        const el = document.createElement('div');
        el.className = 'nm-shape' + (s.layer === 'front' ? ' is-front' : '') + (key === selectedShapeKey ? ' selected' : '');
        el.dataset.key = key;
        if (itemLocked(s)) el.classList.add('is-locked');   // GH #433
        if (multi.has('s:' + key)) el.classList.add('in-multi');
        el.style.left = s.x + 'px';
        el.style.top = s.y + 'px';
        el.style.width = s.w + 'px';
        el.style.height = s.h + 'px';
        if (s.rotation) el.style.transform = 'rotate(' + s.rotation + 'deg)';
        if (st.opacity != null && +st.opacity < 100) el.style.opacity = String(+st.opacity / 100);

        let html = window.nmShapeSvg ? window.nmShapeSvg(s, 'nms-' + key) : '';
        if (s.shape_type === 'icon') {
            // GH #433: an <img>, not an SVG <image> - html2canvas draws an <img> on export, but an
            // SVG rasterised from markup may not load anything outside itself.
            if (s.icon_id) html += '<img class="nm-shape-img" src="' + iconUrl(s.icon_id) + '" alt="" draggable="false">';
            else el.classList.add('is-missing');
        }
        const tb = window.nmShapeTextBox ? window.nmShapeTextBox(s) : { x: 0, y: 0, w: s.w, h: s.h };
        const editing = key === shapeEditKey;
        if ((s.text || editing) && tb.w > 0 && tb.h > 0) {
            const jc = st.valign === 'top' ? 'flex-start' : st.valign === 'bottom' ? 'flex-end' : 'center';
            html += '<div class="nm-shape-text" style="left:' + tb.x + 'px;top:' + tb.y + 'px;width:' + tb.w + 'px;height:' + tb.h + 'px;justify-content:' + jc + ';">'
                + '<div class="nm-shape-text-inner" style="' + escapeAttr(window.nmTextCss ? window.nmTextCss(st) : '') + '"'
                + (editing ? ' contenteditable="true" spellcheck="true"' : '') + '>' + escapeHtml(s.text || '') + '</div></div>';
        }
        if (key === selectedShapeKey && canEdit() && !editing) {
            ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(d => { html += '<div class="nm-shape-handle" data-dir="' + d + '"></div>'; });
        }
        if (canEdit() && !editing && !itemLocked(s)) {
            // GH #433: drag from a dot to another shape or a node to connect them
            ['top', 'right', 'bottom', 'left'].forEach(side => { html += '<div class="nm-shape-port" data-side="' + side + '"></div>'; });
        }
        el.innerHTML = html;
        addKbMark(el, s);   // GH #433
        el.addEventListener('mousedown', onShapeMouseDown);
        el.addEventListener('dblclick', e => { e.stopPropagation(); startShapeTextEdit(s); });
        el.querySelectorAll('.nm-shape-handle').forEach(h => h.addEventListener('mousedown', e => startShapeResize(e, s, h.dataset.dir)));
        el.querySelectorAll('.nm-shape-port').forEach(p => p.addEventListener('mousedown', e => startConnectFrom(e, { kind: 's', it: s }, p.dataset.side)));
        if (editing) {
            const ed = el.querySelector('.nm-shape-text-inner');
            if (ed) {
                ed.addEventListener('mousedown', e => e.stopPropagation());
                ed.addEventListener('blur', () => commitShapeTextEdit(s, ed));
                ed.addEventListener('keydown', e => {
                    if (e.key === 'Escape') { e.preventDefault(); shapeEditKey = null; rerenderShape(s); }
                    e.stopPropagation();
                });
            }
        }
        return el;
    }

    /** Selects a shape (null clears) - clears any node or connector selection - and opens the Format panel. */
    function selectShape(key) {
        if (key != null && multi.size) { multi = new Set(); applyMultiClasses(); }   // GH #433
        if (shapeEditKey && shapeEditKey !== key) {
            const ed = elCanvasInner.querySelector('.nm-shape-text-inner[contenteditable="true"]');
            if (ed) ed.blur();
        }
        const prev = selectedShapeKey;
        selectedShapeKey = key;
        if (key != null) {
            if (selectedNodeKey != null) selectNode(null);
            if (selectedConnectorKey != null) selectConnector(null);
        }
        const p = findShapeByKey(prev);
        if (p) rerenderShape(p);
        const s = findShapeByKey(key);
        if (s && key !== prev) rerenderShape(s);
        if (s) renderShapePanel(); else closeShapePanel();
    }

    /** Selects the shape and, on the current version, starts moving it. */
    function onShapeMouseDown(e) {
        if (e.button !== 0) return;
        const key = e.currentTarget.dataset.key;
        if (key === shapeEditKey) return;
        e.stopPropagation();
        const s = findShapeByKey(key);
        if (!s) return;
        if (isPresenting() && s.article_id) { e.preventDefault(); openArticle(s.article_id); return; }   // GH #433
        if (multiMouseDown(e, 's', s)) return;   // GH #433
        if (selectedShapeKey !== key) selectShape(key);
        if (!canEdit()) return;
        const c = canvasModelCoords(e);
        shapeDrag = { key, offX: c.x - s.x, offY: c.y - s.y, moved: false };
        document.addEventListener('mousemove', onShapeDragMove);
        document.addEventListener('mouseup', onShapeDragUp);
        e.preventDefault();
    }
    function onShapeDragMove(e) {
        if (!shapeDrag) return;
        const s = findShapeByKey(shapeDrag.key);
        if (!s) return;
        const c = canvasModelCoords(e);
        const x = snapS(c.x - shapeDrag.offX), y = snapS(c.y - shapeDrag.offY);
        if (x === s.x && y === s.y) return;
        s.x = x; s.y = y; shapeDrag.moved = true;
        const el = elCanvasInner.querySelector('.nm-shape[data-key="' + cssEscape(shapeDrag.key) + '"]');
        if (el) { el.style.left = x + 'px'; el.style.top = y + 'px'; }
        renderConnectors();   // GH #433: connectors on this shape follow it
    }
    function onShapeDragUp() {
        document.removeEventListener('mousemove', onShapeDragMove);
        document.removeEventListener('mouseup', onShapeDragUp);
        if (!shapeDrag) return;
        const moved = shapeDrag.moved;
        shapeDrag = null;
        if (moved) { markDirty(); syncShapePanelGeometry(); }
    }

    /** Starts dragging one of the 8 handles. */
    function startShapeResize(e, s, dir) {
        if (e.button !== 0 || !canEdit()) return;
        e.stopPropagation();
        e.preventDefault();
        shapeResize = { key: shapeKey(s), dir, start: canvasModelCoords(e), x: s.x, y: s.y, w: s.w, h: s.h, moved: false };
        document.addEventListener('mousemove', onShapeResizeMove);
        document.addEventListener('mouseup', onShapeResizeUp);
    }
    function onShapeResizeMove(e) {
        if (!shapeResize) return;
        const s = findShapeByKey(shapeResize.key);
        if (!s) return;
        const r = shapeResize, c = canvasModelCoords(e);
        const dx = c.x - r.start.x, dy = c.y - r.start.y, MIN = 10;
        let x = r.x, y = r.y, w = r.w, h = r.h;
        if (r.dir.indexOf('e') >= 0) w = Math.max(MIN, snapS(r.w + dx));
        if (r.dir.indexOf('s') >= 0) h = Math.max(MIN, snapS(r.h + dy));
        if (r.dir.indexOf('w') >= 0) { const nx = Math.min(snapS(r.x + dx), r.x + r.w - MIN); w = r.w + (r.x - nx); x = nx; }
        if (r.dir.indexOf('n') >= 0) { const ny = Math.min(snapS(r.y + dy), r.y + r.h - MIN); h = r.h + (r.y - ny); y = ny; }
        if (x === s.x && y === s.y && w === s.w && h === s.h) return;
        Object.assign(s, { x, y, w, h });
        r.moved = true;
        rerenderShape(s);
        renderConnectors();
    }
    function onShapeResizeUp() {
        document.removeEventListener('mousemove', onShapeResizeMove);
        document.removeEventListener('mouseup', onShapeResizeUp);
        if (!shapeResize) return;
        const moved = shapeResize.moved;
        shapeResize = null;
        if (moved) { markDirty(); syncShapePanelGeometry(); }
    }

    /** Double-click: type straight into the shape. Enter makes a new line; click away to finish; Esc cancels. */
    function startShapeTextEdit(s) {
        if (!canEdit()) return;
        if (selectedShapeKey !== shapeKey(s)) selectShape(shapeKey(s));
        shapeEditKey = shapeKey(s);
        rerenderShape(s);
        const ed = elCanvasInner.querySelector('.nm-shape[data-key="' + cssEscape(shapeEditKey) + '"] .nm-shape-text-inner');
        if (!ed) { shapeEditKey = null; return; }
        ed.focus();
        const range = document.createRange();
        range.selectNodeContents(ed);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    function commitShapeTextEdit(s, ed) {
        if (shapeEditKey !== shapeKey(s)) return;
        const text = (ed.innerText || '').replace(/ /g, ' ').replace(/\n+$/, '');
        shapeEditKey = null;
        const changed = text !== (s.text || '');
        s.text = text;
        rerenderShape(s);
        if (changed) markDirty();
    }

    /** The text a shape is dropped with - its name for zones, lanes and labels, empty otherwise. */
    function defaultShapeText(type) {
        const k = { text: 'default_text', heading: 'default_heading', zone: 'default_zone', frame: 'default_zone',
                    swimlane: 'default_lane', pool_vertical: 'default_lane', label: 'default_label' }[type];
        return k ? t('network-mapper.shapes.' + k) : '';
    }

    /** Adds a shape centred on (cx, cy) in model px, selects it and marks the diagram dirty. */
    function addShapeAt(type, cx, cy, extra) {
        if (!canEdit() || !window.NM_SHAPES) return;
        const def = window.NM_SHAPES[type];
        if (!def) return;
        let w = def.w, h = def.h, iconId = null;
        if (type === 'icon') {
            // An imported icon, at most 64px on its longer side, keeping its proportions.
            const ic = iconsById[(extra || {}).icon_id];
            if (!ic) return;
            iconId = ic.id;
            const maxSide = (extra && extra.maxSide) || 64;   // a picture of your own may be bigger than an icon
            const k = Math.min(1, maxSide / Math.max(ic.w || 64, ic.h || 64));
            w = Math.max(16, Math.round((ic.w || 64) * k));
            h = Math.max(16, Math.round((ic.h || 64) * k));
        }
        const maxZ = shapes.reduce((m, s) => Math.max(m, s.z || 0), 0);
        const s = {
            id: null, tempId: nextTempShapeId--, shape_type: type, icon_id: iconId,
            x: Math.max(0, snapS(cx - w / 2)), y: Math.max(0, snapS(cy - h / 2)),
            w: w, h: h, rotation: 0, text: defaultShapeText(type),
            style: window.nmShapeStyle(type, {}),   // the full look, so later library changes never move it
            layer: 'back', z: maxZ + 1, uid: newUid()
        };
        placeOnPage(s);   // GH #433
        shapes.push(s);
        renderShapes();
        selectShape(shapeKey(s));
        markDirty();
    }

    /** Adds a shape in the middle of what the canvas is showing (palette click). */
    function addShapeInView(type, extra) {
        if (!elCanvas) return;
        const cx = (elCanvas.scrollLeft + elCanvas.clientWidth / 2) / zoom;
        const cy = (elCanvas.scrollTop + elCanvas.clientHeight / 2) / zoom;
        addShapeAt(type, cx, cy, extra);
    }

    function deleteSelectedShape() {
        const s = findShapeByKey(selectedShapeKey);
        if (!s || !canEdit()) return;
        shapes = shapes.filter(x => x !== s);
        connectors = connectors.filter(c => !touchesShape(c, s));   // GH #433
        selectedShapeKey = null;
        closeShapePanel();
        renderShapes();
        markDirty();
    }

    function duplicateSelectedShape() {
        const s = findShapeByKey(selectedShapeKey);
        if (!s || !canEdit()) return;
        const maxZ = shapes.reduce((m, x) => Math.max(m, x.z || 0), 0);
        const copy = Object.assign({}, s, {
            id: null, tempId: nextTempShapeId--, x: s.x + 20, y: s.y + 20, z: maxZ + 1, uid: newUid(),
            style: s.style ? JSON.parse(JSON.stringify(s.style)) : null
        });
        shapes.push(copy);
        renderShapes();
        selectShape(shapeKey(copy));
        markDirty();
    }

    /** To front / to back within the shape's layer, or move it to the other layer. */
    function arrangeShape(how) {
        const s = findShapeByKey(selectedShapeKey);
        if (!s || !canEdit()) return;
        if (how === 'front') s.z = shapes.reduce((m, x) => Math.max(m, x.z || 0), 0) + 1;
        else if (how === 'back') s.z = shapes.reduce((m, x) => Math.min(m, x.z || 0), 0) - 1;
        else if (how === 'layer_front') s.layer = 'front';
        else if (how === 'layer_back') s.layer = 'back';
        renderShapes();
        renderShapePanel();
        markDirty();
    }

    /** Applies a style change to the selected shape. */
    function patchShapeStyle(patch) {
        const s = findShapeByKey(selectedShapeKey);
        if (!s || !canEdit()) return;
        s.style = Object.assign({}, window.nmShapeStyle(s.shape_type, s.style), patch);
        rerenderShape(s);
        markDirty();
    }

    // ---- palette: CMDB classes | Shapes ----
    let shapeFilter = '';
    /** Switches the left palette between the CMDB classes and the shape library. */
    function setPaletteTab(tab) {
        paletteTab = tab === 'shapes' ? 'shapes' : 'cmdb';
        document.querySelectorAll('.nm-palette-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === paletteTab));
        if (elPaletteBody) elPaletteBody.style.display = paletteTab === 'cmdb' ? '' : 'none';
        if (elShapePalette) elShapePalette.style.display = paletteTab === 'shapes' ? '' : 'none';
        if (paletteTab === 'shapes') renderShapePalette();
    }

    /** Draws the shape library, grouped, filtered by the search box. */
    function renderShapePalette() {
        const body = document.getElementById('shapePaletteBody');
        if (!body || !window.NM_SHAPES) return;
        const q = shapeFilter.trim().toLowerCase();
        let html = '';
        (window.NM_SHAPE_CATS || []).forEach(cat => {
            const keys = Object.keys(window.NM_SHAPES).filter(k => window.NM_SHAPES[k].cat === cat
                && (!q || shapeName(k).toLowerCase().indexOf(q) >= 0 || k.indexOf(q) >= 0));
            if (!keys.length) return;
            html += '<div class="nm-shape-cat">' + escapeHtml(t('network-mapper.shapes.cat_' + cat)) + '</div><div class="nm-shape-grid">';
            keys.forEach(k => {
                html += '<div class="nm-shape-tile" draggable="true" data-shape="' + escapeAttr(k) + '" title="' + escapeAttr(shapeName(k) + ' - ' + t('network-mapper.shapes.tile_title')) + '">'
                    + window.nmShapePreview(k, 34) + '</div>';
            });
            html += '</div>';
        });
        const iconsHtml = iconPaletteHtml(q);
        body.innerHTML = (html || (q ? '<div class="nm-palette-empty">' + escapeHtml(t('network-mapper.shapes.no_match')) + '</div>' : '')) + iconsHtml;
        bindIconPalette(body);
        body.querySelectorAll('.nm-shape-tile:not(.nm-icon-tile)').forEach(tile => {
            tile.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', JSON.stringify({ kind: 'nm-shape', shape_type: tile.dataset.shape }));
                e.dataTransfer.effectAllowed = 'copy';
            });
            tile.addEventListener('click', () => addShapeInView(tile.dataset.shape));
        });
    }
    function onShapeSearchInput(v) { shapeFilter = v || ''; renderShapePalette(); }

    // ---- GH #433: icon libraries imported from icons.diagrams.net ----
    // Imported sets show on the Shapes tab after the drawn shapes; the browser (a large modal)
    // previews any of the site's ~1,000 sets before importing it. The pictures are copied into
    // the install - icon.php serves them - so a diagram never depends on that site.
    let iconLibs = [];            // imported libraries (with licence / author)
    let iconsById = {};           // icon id -> {id, library_id, title, tags, w, h}
    let iconsByLib = {};          // library id -> [icon]
    const iconLibOpen = {};       // library id -> expanded in the palette
    let libCatalogue = null;      // the site's sets, fetched once per page
    let libFilter = 'all';
    let libSearch = '';
    let libSelected = null;       // slug being previewed
    const libPreviews = {};       // slug -> preview data
    let libIconSearch = '';
    let libShowAll = false;
    let libImporting = null;      // {slug, done, total}

    const ICON_API = API + 'icon_libraries.php';
    function iconUrl(id) { return API + 'icon.php?id=' + encodeURIComponent(id); }

    /** Loads the imported libraries and their icon list (no pictures) for the Shapes tab. */
    async function loadIconPalette() {
        try {
            const r = await fetch(ICON_API + '?action=palette', { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'load failed');
            iconLibs = (d.libraries || []).filter(l => l.status === 'ready');
            iconsById = {};
            iconsByLib = {};
            (d.icons || []).forEach(i => {
                iconsById[i.id] = i;
                (iconsByLib[i.library_id] = iconsByLib[i.library_id] || []).push(i);
            });
        } catch (e) {
            iconLibs = [];
        }
        if (paletteTab === 'shapes') renderShapePalette();
        if (elCanvasInner && shapes.some(s => s.icon_id)) renderShapes();
    }

    /** The Icon libraries part of the Shapes tab: a Libraries button, then each imported set. */
    function iconPaletteHtml(q) {
        let html = '<div class="nm-shape-cat nm-iconlib-head"><span>' + escapeHtml(t('network-mapper.iconlib.section')) + '</span>'
            + '<span class="nm-iconlib-btns">'
            + (canEdit() ? '<button type="button" class="nm-detail-icon-btn" id="uploadImages" title="' + escapeAttr(t('network-mapper.iconlib.upload_title')) + '">' + escapeHtml(t('network-mapper.iconlib.upload')) + '</button>' : '')
            + '<button type="button" class="nm-detail-icon-btn" id="openIconLibs">' + escapeHtml(t('network-mapper.iconlib.button')) + '</button></span></div>';
        if (!iconLibs.length) {
            return html + '<div class="nm-iconlib-empty">' + escapeHtml(t('network-mapper.iconlib.none_yet')) + '</div>';
        }
        iconLibs.forEach(lib => {
            const all = iconsByLib[lib.id] || [];
            const hits = q ? all.filter(i => (i.title || '').toLowerCase().indexOf(q) >= 0 || (i.tags || '').toLowerCase().indexOf(q) >= 0) : all;
            if (q && !hits.length) return;
            const open = q ? true : !!iconLibOpen[lib.id];
            const mine = lib.slug === MY_IMAGES;
            html += '<div class="nm-iconlib-group' + (open ? ' open' : '') + '">'
                + '<button type="button" class="nm-iconlib-toggle" data-lib="' + lib.id + '" title="' + escapeAttr((lib.licence ? t('network-mapper.iconlib.licence') + ': ' + lib.licence : '') + (lib.author ? ' - ' + lib.author : '')) + '">'
                + '<span class="nm-iconlib-caret">' + (open ? '&#9662;' : '&#9656;') + '</span>' + escapeHtml(mine ? t('network-mapper.iconlib.my_images') : lib.title)
                + '<span class="nm-iconlib-count">' + (q ? hits.length + ' / ' : '') + all.length + '</span></button>';
            if (open) {
                html += '<div class="nm-shape-grid">' + hits.slice(0, 300).map(i =>
                    '<div class="nm-shape-tile nm-icon-tile" draggable="true" data-icon="' + i.id + '" title="' + escapeAttr((i.title || '') + ' - ' + t('network-mapper.shapes.tile_title')) + '">'
                    + '<img src="' + iconUrl(i.id) + '" alt="" loading="lazy" draggable="false">'
                    + (mine && canEdit() ? '<button type="button" class="nm-icon-del" data-del="' + i.id + '" title="' + escapeAttr(t('network-mapper.iconlib.delete_image')) + '">&times;</button>' : '')
                    + '</div>').join('') + '</div>';
                if (hits.length > 300) html += '<div class="nm-iconlib-empty">' + escapeHtml(t('network-mapper.iconlib.more_hint', { count: hits.length - 300 })) + '</div>';
            }
            html += '</div>';
        });
        return html;
    }

    /** Wires the Libraries button, the group toggles and the icon tiles after the palette is drawn. */
    function bindIconPalette(body) {
        const btn = body.querySelector('#openIconLibs');
        if (btn) btn.addEventListener('click', openLibraryBrowser);
        const up = body.querySelector('#uploadImages');
        if (up) up.addEventListener('click', () => {
            const inp = document.createElement('input');
            inp.type = 'file';
            inp.accept = 'image/png,image/jpeg,image/gif,image/webp';
            inp.multiple = true;
            inp.addEventListener('change', () => uploadImages(Array.from(inp.files || [])));
            inp.click();
        });
        body.querySelectorAll('.nm-icon-del').forEach(b => b.addEventListener('click', e => {
            e.stopPropagation();
            deleteMyImage(+b.dataset.del);
        }));
        body.querySelectorAll('.nm-iconlib-toggle').forEach(b => b.addEventListener('click', () => {
            const id = b.dataset.lib;
            iconLibOpen[id] = !iconLibOpen[id];
            renderShapePalette();
        }));
        body.querySelectorAll('.nm-icon-tile').forEach(tile => {
            tile.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', JSON.stringify({ kind: 'nm-shape', shape_type: 'icon', icon_id: +tile.dataset.icon }));
                e.dataTransfer.effectAllowed = 'copy';
            });
            tile.addEventListener('click', e => { if (!e.target.closest('.nm-icon-del')) addShapeInView('icon', { icon_id: +tile.dataset.icon }); });
        });
    }

    // ---- the library browser ----
    async function openLibraryBrowser() {
        const m = document.getElementById('iconLibModal');
        if (!m) return;
        m.classList.add('active');
        renderLibraryList();
        if (!libCatalogue) {
            document.getElementById('libList').innerHTML = '<div class="nm-iconlib-empty">' + escapeHtml(t('network-mapper.iconlib.loading')) + '</div>';
            try {
                const r = await fetch(ICON_API + '?action=catalogue', { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'failed');
                libCatalogue = d.sets || [];
            } catch (e) {
                libCatalogue = [];
                document.getElementById('libList').innerHTML = '<div class="nm-iconlib-empty nm-iconlib-error">' + escapeHtml(t('network-mapper.iconlib.error', { message: e.message })) + '</div>';
                return;
            }
            renderLibraryList();
        }
        setTimeout(() => { const s = document.getElementById('libSearch'); if (s) s.focus(); }, 50);
    }
    function closeLibraryBrowser() {
        const m = document.getElementById('iconLibModal');
        if (m) m.classList.remove('active');
    }

    function isImported(slug) { return iconLibs.some(l => l.slug === slug); }

    /** The left-hand list of sets, filtered by the search box and All / Vendors / Imported. */
    function renderLibraryList() {
        const box = document.getElementById('libList');
        if (!box) return;
        document.querySelectorAll('#libFilter button').forEach(b => b.classList.toggle('active', b.dataset.value === libFilter));
        let sets = (libCatalogue || []).slice();
        // Imported sets that the catalogue does not list (offline, renamed) still appear.
        iconLibs.forEach(l => { if (l.slug !== MY_IMAGES && !sets.some(s => s.slug === l.slug)) sets.push({ slug: l.slug, title: l.title, count: l.icon_count, group: '' }); });
        const q = libSearch.trim().toLowerCase();
        if (q) sets = sets.filter(s => s.title.toLowerCase().indexOf(q) >= 0 || s.slug.toLowerCase().indexOf(q) >= 0 || (s.group || '').toLowerCase().indexOf(q) >= 0);
        if (libFilter === 'vendor') sets = sets.filter(s => s.group);
        if (libFilter === 'imported') sets = sets.filter(s => isImported(s.slug));
        if (!libCatalogue && !sets.length) return;
        if (!sets.length) { box.innerHTML = '<div class="nm-iconlib-empty">' + escapeHtml(t('network-mapper.iconlib.no_sets')) + '</div>'; return; }
        let html = '', lastGroup = null;
        sets.forEach(s => {
            const g = s.group ? s.group : t('network-mapper.iconlib.group_community');
            if (g !== lastGroup && !q) { html += '<div class="nm-lib-group">' + escapeHtml(g) + '</div>'; lastGroup = g; }
            html += '<button type="button" class="nm-lib-item' + (s.slug === libSelected ? ' active' : '') + '" data-slug="' + escapeAttr(s.slug) + '">'
                + '<span class="nm-lib-name">' + escapeHtml(s.title) + '</span>'
                + (isImported(s.slug) ? '<span class="nm-lib-badge">&#10003;</span>' : '')
                + '<span class="nm-lib-n">' + (+s.count || 0) + '</span></button>';
        });
        box.innerHTML = html;
        box.querySelectorAll('.nm-lib-item').forEach(b => b.addEventListener('click', () => selectLibrary(b.dataset.slug)));
    }

    async function selectLibrary(slug) {
        libSelected = slug;
        libIconSearch = '';
        libShowAll = false;
        renderLibraryList();
        const main = document.getElementById('libMain');
        if (!libPreviews[slug]) {
            main.innerHTML = '<div class="nm-lib-placeholder">' + escapeHtml(t('network-mapper.iconlib.loading')) + '</div>';
            try {
                const r = await fetch(ICON_API + '?action=preview&slug=' + encodeURIComponent(slug), { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'failed');
                libPreviews[slug] = d.library;
            } catch (e) {
                if (libSelected === slug) main.innerHTML = '<div class="nm-lib-placeholder nm-iconlib-error">' + escapeHtml(t('network-mapper.iconlib.error', { message: e.message })) + '</div>';
                return;
            }
        }
        if (libSelected === slug) renderLibraryPreview();
    }

    /** The right-hand preview: licence, author, Import / progress / Remove, and the icons. */
    function renderLibraryPreview() {
        const main = document.getElementById('libMain');
        const lib = libPreviews[libSelected];
        if (!main || !lib) return;
        const imported = iconLibs.find(l => l.slug === lib.slug);
        const unknown = escapeHtml(t('network-mapper.iconlib.not_stated'));
        const author = lib.author ? (lib.author_url ? '<a href="' + escapeAttr(lib.author_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(lib.author) + '</a>' : escapeHtml(lib.author)) : unknown;
        let action = '';
        if (libImporting && libImporting.slug === lib.slug) {
            const pct = libImporting.total ? Math.round(libImporting.done / libImporting.total * 100) : 0;
            action = '<div class="nm-lib-progress"><div class="nm-lib-bar"><span style="width:' + pct + '%"></span></div>'
                + '<div class="nm-lib-progress-text">' + escapeHtml(t('network-mapper.iconlib.importing', { done: libImporting.done, total: libImporting.total })) + '</div></div>';
        } else if (imported) {
            action = '<span class="nm-lib-done">&#10003; ' + escapeHtml(t('network-mapper.iconlib.imported')) + '</span>'
                + '<button type="button" class="nm-btn secondary" id="libRemove">' + escapeHtml(t('network-mapper.iconlib.remove')) + '</button>'
                + (imported.used ? '<div class="nm-lib-used">' + escapeHtml(t('network-mapper.iconlib.used', { count: imported.used })) + '</div>' : '');
        } else {
            action = '<button type="button" class="nm-btn" id="libImport"' + (libImporting ? ' disabled' : '') + '>'
                + escapeHtml(t('network-mapper.iconlib.import_n', { count: lib.icons.length })) + '</button>';
        }
        const q = libIconSearch.trim().toLowerCase();
        const icons = q ? lib.icons.filter(i => (i.title || '').toLowerCase().indexOf(q) >= 0 || (i.tags || '').toLowerCase().indexOf(q) >= 0) : lib.icons;
        const LIMIT = 240;
        const shown = libShowAll ? icons : icons.slice(0, LIMIT);
        main.innerHTML =
            '<div class="nm-lib-head"><div class="nm-lib-titles"><h3>' + escapeHtml(lib.title) + '</h3>'
            + '<div class="nm-lib-meta"><span>' + escapeHtml(t('network-mapper.iconlib.icons_count', { count: lib.icons.length })) + '</span>'
            + '<span><strong>' + escapeHtml(t('network-mapper.iconlib.licence')) + ':</strong> ' + (lib.licence ? escapeHtml(lib.licence) : unknown) + '</span>'
            + '<span><strong>' + escapeHtml(t('network-mapper.iconlib.author')) + ':</strong> ' + author + '</span>'
            + (lib.source ? '<span><strong>' + escapeHtml(t('network-mapper.iconlib.source')) + ':</strong> ' + escapeHtml(lib.source) + '</span>' : '')
            + '</div></div><div class="nm-lib-actions">' + action + '</div></div>'
            + '<input type="search" class="nm-shape-search nm-lib-icon-search" id="libIconSearch" placeholder="' + escapeAttr(t('network-mapper.iconlib.search_icons')) + '" value="' + escapeAttr(libIconSearch) + '">'
            + '<div class="nm-lib-grid">' + shown.map(i =>
                '<div class="nm-lib-icon" title="' + escapeAttr(i.title + (i.tags ? ' - ' + i.tags : '')) + '"><img src="' + escapeAttr(i.src) + '" alt="" loading="lazy" referrerpolicy="no-referrer"><span>' + escapeHtml(i.title) + '</span></div>').join('') + '</div>'
            + (!libShowAll && icons.length > LIMIT ? '<div class="nm-lib-more"><button type="button" class="nm-btn secondary" id="libShowAll">' + escapeHtml(t('network-mapper.iconlib.show_all', { count: icons.length })) + '</button></div>' : '');
        const imp = document.getElementById('libImport'); if (imp) imp.addEventListener('click', () => importLibrary(lib.slug));
        const rem = document.getElementById('libRemove'); if (rem) rem.addEventListener('click', () => removeLibrary(imported));
        const all = document.getElementById('libShowAll'); if (all) all.addEventListener('click', () => { libShowAll = true; renderLibraryPreview(); });
        const srch = document.getElementById('libIconSearch');
        if (srch) srch.addEventListener('input', () => {
            libIconSearch = srch.value;
            const pos = srch.selectionStart;
            renderLibraryPreview();
            const again = document.getElementById('libIconSearch');
            if (again) { again.focus(); again.setSelectionRange(pos, pos); }
        });
    }

    async function libPost(body) {
        const r = await fetch(ICON_API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'failed');
        return d;
    }

    /** Imports a set in batches, showing progress; the palette gains the set when it is done. */
    async function importLibrary(slug) {
        if (libImporting) return;
        const lib = libPreviews[slug];
        libImporting = { slug, done: 0, total: lib ? lib.icons.length : 0 };
        renderLibraryPreview();
        let skipped = 0;
        try {
            const start = await libPost({ action: 'import_start', slug });
            libImporting.total = start.total;
            let offset = 0;
            for (;;) {
                const b = await libPost({ action: 'import_batch', id: start.id, offset });
                skipped += b.skipped || 0;
                offset = b.next;
                libImporting.done = offset;
                if (libSelected === slug) renderLibraryPreview();
                if (b.done) break;
            }
            libImporting = null;
            await loadIconPalette();
            const added = iconLibs.find(l => l.slug === slug);
            if (added) iconLibOpen[added.id] = true;
            setPaletteTab('shapes');
            renderLibraryList();
            if (libSelected === slug) renderLibraryPreview();
            if (window.showToast) showToast(t('network-mapper.iconlib.imported_toast', { count: added ? added.icon_count : 0, name: lib ? lib.title : slug })
                + (skipped ? ' ' + t('network-mapper.iconlib.skipped', { count: skipped }) : ''), 'success');
        } catch (e) {
            libImporting = null;
            if (libSelected === slug) renderLibraryPreview();
            if (window.showToast) showToast(t('network-mapper.iconlib.error', { message: e.message }), 'error');
        }
    }

    async function removeLibrary(lib) {
        if (!lib) return;
        if (!confirm(t('network-mapper.iconlib.remove_confirm', { name: lib.title }))) return;
        try {
            await libPost({ action: 'delete', id: lib.id });
            await loadIconPalette();
            renderLibraryList();
            renderLibraryPreview();
            if (window.showToast) showToast(t('network-mapper.iconlib.removed_toast', { name: lib.title }), 'success');
        } catch (e) {
            if (window.showToast) showToast(t('network-mapper.iconlib.error', { message: e.message }), 'error');
        }
    }
    function onLibSearchInput(v) { libSearch = v || ''; renderLibraryList(); }

    // ---- GH #433: Knowledge articles - a node or a shape links to one; the diagram lists related ones ----
    // Titles come from the server already filtered by Knowledge's own read rule: an article
    // this analyst may not read arrives as null and shows as "an article you don't have access
    // to" - never its title. includes/services/network_articles.php has the rules.
    let kbRelated = [];      // article ids related to this diagram, in the order added
    let kbTitles = {};       // article id -> title, or null when linked but not readable by this analyst
    let canReadKb = false;   // has the Knowledge module - the picker needs it
    let kbPick = null;       // the picker's callback while it is open
    let kbTimer = null, kbSeq = 0;
    const KB_BOOK = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2z"/><path d="M22 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z"/></svg>';

    function kbTitle(id) { const v = kbTitles[id]; return typeof v === 'string' ? v : null; }
    function kbLabel(id) { const v = kbTitle(id); return v !== null ? v : t('network-mapper.kb.no_access'); }

    /** Opens a linked article in a new tab - or says it cannot, without naming it. */
    function openArticle(id) {
        if (!id) return;
        if (kbTitle(id) === null) { if (window.showToast) showToast(t('network-mapper.kb.no_access'), 'error'); return; }
        window.open('../knowledge/?article=' + encodeURIComponent(id), '_blank', 'noopener');
    }

    /** The small book on a node or shape that links to an article; clicking it opens the article. */
    function addKbMark(el, it) {
        if (!it.article_id) return;
        el.classList.add('has-article');
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'nm-kb-mark' + (kbTitle(it.article_id) === null ? ' is-hidden-article' : '');
        b.title = kbLabel(it.article_id);
        b.setAttribute('aria-label', kbLabel(it.article_id));
        b.innerHTML = KB_BOOK;
        b.addEventListener('mousedown', e => { e.stopPropagation(); e.preventDefault(); });
        b.addEventListener('click', e => { e.stopPropagation(); openArticle(it.article_id); });
        el.appendChild(b);
    }

    /** Links (or with null unlinks) an article on a node or shape. Saved with the diagram. */
    function setItemArticle(it, a) {
        it.article_id = a ? a.id : null;
        if (a) kbTitles[a.id] = a.title;
        if (nodes.includes(it)) { rerenderNode(it); renderConnectors(); } else { rerenderShape(it); }
        markDirty();
        ['ndArticle', 'spArticle'].forEach(id => { const h = document.getElementById(id); if (h && h._kbItem === it) renderKbField(h, it); });
    }

    /** The "Article" line in the node and shape panels. */
    function renderKbField(host, it) {
        if (!host) return;
        host._kbItem = it;
        const ed = canEdit();
        const id = it.article_id;
        const btn = (act, label) => '<button type="button" class="nm-detail-icon-btn" data-kb="' + act + '">' + escapeHtml(label) + '</button>';
        let html;
        if (id) {
            const title = kbTitle(id);
            html = '<span class="nm-kb-name' + (title === null ? ' is-hidden-article' : '') + '" title="' + escapeAttr(kbLabel(id)) + '">' + escapeHtml(kbLabel(id)) + '</span>'
                + (title !== null ? btn('open', t('network-mapper.kb.open')) : '')
                + (ed && canReadKb ? btn('change', t('network-mapper.kb.change')) : '')
                + (ed ? btn('remove', t('network-mapper.kb.remove')) : '');
        } else if (ed && canReadKb) {
            html = btn('change', t('network-mapper.kb.link'));
        } else {
            html = '<span class="nm-kb-none">' + escapeHtml(t('network-mapper.kb.none')) + '</span>';
        }
        host.innerHTML = html;
        host.querySelectorAll('[data-kb]').forEach(b => b.addEventListener('click', () => {
            if (b.dataset.kb === 'open') openArticle(id);
            else if (b.dataset.kb === 'remove') setItemArticle(it, null);
            else openArticlePicker(a => setItemArticle(it, a));
        }));
    }

    /** The article picker: articles this analyst can read, by title. onPick gets {id, title}. */
    function openArticlePicker(onPick) {
        kbPick = onPick;
        const m = document.getElementById('articlePickerModal');
        const inp = document.getElementById('articleSearch');
        if (!m || !inp) return;
        inp.value = '';
        m.classList.add('active');
        searchArticles('');
        setTimeout(() => inp.focus(), 30);
    }
    function closeArticlePicker() {
        const m = document.getElementById('articlePickerModal');
        if (m) m.classList.remove('active');
        kbPick = null;
    }
    function onArticleSearchInput(v) { clearTimeout(kbTimer); kbTimer = setTimeout(() => searchArticles(v), 200); }
    async function searchArticles(q) {
        const box = document.getElementById('articleResults');
        const seq = ++kbSeq;
        try {
            const r = await fetch(API + 'articles.php?q=' + encodeURIComponent(q || ''), { credentials: 'same-origin' });
            const d = await r.json();
            if (seq !== kbSeq) return;   // a newer search has started
            if (!d.success) throw new Error(d.error || 'failed');
            const list = d.articles || [];
            box.innerHTML = list.length
                ? list.map(a => '<div class="nm-picker-row" data-id="' + a.id + '"><span class="nm-picker-name">' + KB_BOOK + escapeHtml(a.title) + '</span></div>').join('')
                : '<div class="nm-picker-empty">' + escapeHtml(t('network-mapper.kb.no_results')) + '</div>';
            box.querySelectorAll('.nm-picker-row').forEach(row => row.addEventListener('click', () => {
                const a = list.find(x => x.id === +row.dataset.id);
                const cb = kbPick;
                closeArticlePicker();
                if (cb && a) cb(a);
            }));
        } catch (e) {
            box.innerHTML = '<div class="nm-picker-empty">' + escapeHtml(e.message) + '</div>';
        }
    }

    /** The toolbar's Articles list - related articles for the whole diagram, saved as they change. */
    function toggleArticlesPanel(e) {
        if (e) e.stopPropagation();
        const dd = document.getElementById('articlesDropdown');
        if (!dd) return;
        const open = dd.style.display === 'none';
        dd.style.display = open ? '' : 'none';
        if (open) renderArticlesPanel();
    }
    function closeArticlesPanel() { const dd = document.getElementById('articlesDropdown'); if (dd) dd.style.display = 'none'; }
    function renderArticlesPanel() {
        const lab = document.getElementById('articlesBtnCount');
        if (lab) lab.textContent = String(kbRelated.length);
        const dd = document.getElementById('articlesDropdown');
        if (!dd || dd.style.display === 'none') return;
        const ed = !!(diagram && diagram.is_current);
        dd.innerHTML = '<div class="nm-layers-head">' + escapeHtml(t('network-mapper.kb.related_title')) + '</div>'
            + (kbRelated.length ? kbRelated.map(id => '<div class="nm-kb-row">'
                    + (kbTitle(id) !== null
                        ? '<a href="../knowledge/?article=' + encodeURIComponent(id) + '" target="_blank" rel="noopener">' + KB_BOOK + '<span>' + escapeHtml(kbTitle(id)) + '</span></a>'
                        : '<span class="is-hidden-article">' + KB_BOOK + '<span>' + escapeHtml(t('network-mapper.kb.no_access')) + '</span></span>')
                    + (ed ? '<button type="button" class="nm-layer-ico nm-layer-del" data-del="' + id + '" title="' + escapeAttr(t('network-mapper.kb.remove')) + '">&times;</button>' : '')
                    + '</div>').join('')
                : '<div class="nm-kb-empty">' + escapeHtml(t('network-mapper.kb.related_empty')) + '</div>')
            + (ed && canReadKb ? '<button type="button" class="nm-layers-add" id="kbRelAdd">+ ' + escapeHtml(t('network-mapper.kb.related_add')) + '</button>' : '')
            + (!canReadKb ? '<div class="nm-layers-hint">' + escapeHtml(t('network-mapper.kb.no_knowledge')) + '</div>' : '')
            + '<div class="nm-layers-hint">' + escapeHtml(t('network-mapper.kb.related_hint')) + '</div>';
        dd.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); relatedPost('remove', +b.dataset.del); }));
        const add = dd.querySelector('#kbRelAdd');
        if (add) add.addEventListener('click', e => {
            e.stopPropagation();
            openArticlePicker(a => { kbTitles[a.id] = a.title; relatedPost('add', a.id); });
        });
    }
    async function relatedPost(action, articleId) {
        try {
            const r = await fetch(API + 'articles.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                                                         body: JSON.stringify({ action: action, diagram_id: diagramId, article_id: articleId }) });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'failed');
            kbRelated = d.related || [];
            kbTitles = Object.assign({}, kbTitles, d.titles || {});
            const dd = document.getElementById('articlesDropdown');
            if (dd) dd.style.display = '';
            renderArticlesPanel();
        } catch (e) {
            if (window.showToast) showToast(e.message, 'error');
        }
    }

    // ---- GH #433: My images - pictures uploaded from the computer or pasted with Ctrl+V ----
    const MY_IMAGES = 'my-images';
    let lastCanvasPoint = null;   // where the mouse last was on the canvas, for pasting a picture there

    /** Uploads pictures to My images and, on the current version, puts each on the diagram. */
    async function uploadImages(files, at) {
        const ok = files.filter(f => /^image\/(png|jpeg|gif|webp)$/.test(f.type));
        if (!ok.length) {
            if (files.length && window.showToast) showToast(t('network-mapper.iconlib.not_image'), 'error');
            return;
        }
        let placed = 0;
        for (let i = 0; i < ok.length; i++) {
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('file', ok[i], ok[i].name || 'pasted.png');
            try {
                const r = await fetch(ICON_API, { method: 'POST', credentials: 'same-origin', body: fd });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'upload failed');
                const ic = d.icon;
                iconsById[ic.id] = ic;
                (iconsByLib[ic.library_id] = iconsByLib[ic.library_id] || []).push(ic);
                if (!iconLibs.some(l => l.id === ic.library_id)) iconLibs.push({ id: ic.library_id, slug: MY_IMAGES, title: 'My images', status: 'ready', icon_count: 1 });
                iconLibOpen[ic.library_id] = true;
                if (canEdit()) {
                    const p = at || viewCentre();
                    addShapeAt('icon', p.x + i * 30, p.y + i * 30, { icon_id: ic.id, maxSide: 240 });
                    placed++;
                }
            } catch (e) {
                if (window.showToast) showToast(t('network-mapper.iconlib.error', { message: e.message }), 'error');
            }
        }
        setPaletteTab('shapes');
        renderShapePalette();
        if (placed && window.showToast) showToast(t('network-mapper.iconlib.added', { count: placed }), 'success');
    }
    function viewCentre() {
        return { x: (elCanvas.scrollLeft + elCanvas.clientWidth / 2) / zoom, y: (elCanvas.scrollTop + elCanvas.clientHeight / 2) / zoom };
    }
    async function deleteMyImage(id) {
        if (!confirm(t('network-mapper.iconlib.delete_image_confirm'))) return;
        try {
            await libPost({ action: 'delete_image', id: id });
            shapes.forEach(s => { if (s.icon_id === id) s.icon_id = null; });
            await loadIconPalette();
            renderShapes();
        } catch (e) {
            if (window.showToast) showToast(t('network-mapper.iconlib.error', { message: e.message }), 'error');
        }
    }
    /**
     * Ctrl+V: a picture on the clipboard (a screenshot, a copied image) goes into My images and
     * onto the diagram where the mouse is; otherwise what was copied in the diagram is pasted.
     */
    function onPaste(e) {
        const a = document.activeElement;
        if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable)) return;
        if (document.querySelector('.nm-modal-overlay.active')) return;
        const files = Array.from((e.clipboardData && e.clipboardData.files) || []).filter(f => /^image\//.test(f.type));
        if (files.length && canEdit()) {
            e.preventDefault();
            uploadImages(files, lastCanvasPoint);
            return;
        }
        if (pasteClipboard()) e.preventDefault();
    }
    function setLibFilter(v) { libFilter = v; renderLibraryList(); }

    // ---- the Format panel (shapes) ----
    function closeShapePanel() {
        if (!elShapePanel) return;
        elShapePanel.classList.remove('open');
        elShapePanel.setAttribute('aria-hidden', 'true');
    }

    /** Fills the Format panel for the selected shape: arrange, then the shared style editor. */
    function renderShapePanel() {
        const s = findShapeByKey(selectedShapeKey);
        if (!s || !elShapePanel) { closeShapePanel(); return; }
        if (selectedNodeKey == null) closeDetail();
        elShapePanel.classList.add('open');
        elShapePanel.setAttribute('aria-hidden', 'false');
        const ed = canEdit();
        const dis = ed ? '' : ' disabled';
        const def = (window.NM_SHAPES || {})[s.shape_type] || {};
        document.getElementById('spIcon').innerHTML = window.nmShapePreview(s.shape_type, 26);
        document.getElementById('spName').textContent = (s.shape_type === 'icon' && iconsById[s.icon_id] && iconsById[s.icon_id].title)
            ? iconsById[s.icon_id].title : shapeName(s.shape_type);
        const num = (id, label, v, min, max, step) =>
            '<label class="nm-fmt-num"><span>' + escapeHtml(label) + '</span><input type="number" id="' + id + '" value="' + v + '" min="' + min + '" max="' + max + '" step="' + (step || 1) + '"' + dis + '></label>';
        let html = '<div class="nm-fmt-section"><div class="nm-fmt-title">' + escapeHtml(t('network-mapper.shapes.arrange')) + '</div>'
            + '<div class="nm-fmt-nums">'
            + num('spX', t('network-mapper.shapes.x'), s.x, -10000, 20000)
            + num('spY', t('network-mapper.shapes.y'), s.y, -10000, 20000)
            + num('spW', t('network-mapper.shapes.width'), s.w, 10, 5000)
            + num('spH', t('network-mapper.shapes.height'), s.h, 10, 5000)
            + num('spR', t('network-mapper.shapes.rotation'), s.rotation || 0, -360, 360, 5)
            + '</div>'
            + '<div class="nm-fmt-row"><span>' + escapeHtml(t('network-mapper.layers.field')) + '</span>' + layerSelectHtml(s, 'spLayer') + '</div>'
            + '<div class="nm-fmt-row"><span>' + escapeHtml(t('network-mapper.kb.field')) + '</span><span class="nm-kb-field" id="spArticle"></span></div>'
            + '<div class="nm-fmt-row"><span>' + escapeHtml(t('network-mapper.layers.placement')) + '</span>'
            + seg([['layer_back', t('network-mapper.shapes.layer_back')], ['layer_front', t('network-mapper.shapes.layer_front')]], s.layer === 'front' ? 'layer_front' : 'layer_back', 'arrange', !ed) + '</div>'
            + '<div class="nm-fmt-btns">'
            + '<button type="button" class="nm-detail-icon-btn" data-arrange="front"' + dis + '>' + escapeHtml(t('network-mapper.shapes.to_front')) + '</button>'
            + '<button type="button" class="nm-detail-icon-btn" data-arrange="back"' + dis + '>' + escapeHtml(t('network-mapper.shapes.to_back')) + '</button>'
            + '<button type="button" class="nm-detail-icon-btn" id="spDup"' + dis + '>' + escapeHtml(t('network-mapper.shapes.duplicate')) + '</button>'
            + '<button type="button" class="nm-detail-icon-btn nm-fmt-danger" id="spDel"' + dis + '>' + escapeHtml(t('common.delete')) + '</button>'
            + '</div>'
            + (ed ? '<div class="nm-detail-hint">' + escapeHtml(t('network-mapper.shapes.text_hint')) + '</div>' : '')
            + '</div><div id="spStyle"></div>';
        const body = document.getElementById('spBody');
        body.innerHTML = html;
        const bindNum = (id, apply) => {
            const inp = document.getElementById(id);
            if (inp) inp.addEventListener('change', () => { const v = parseFloat(inp.value); if (!isNaN(v)) apply(v); });
        };
        const geo = (fn) => v => { fn(v); rerenderShape(s); renderConnectors(); markDirty(); };
        bindNum('spX', geo(v => { s.x = Math.round(v); }));
        bindNum('spY', geo(v => { s.y = Math.round(v); }));
        bindNum('spW', geo(v => { s.w = Math.max(10, Math.round(v)); }));
        bindNum('spH', geo(v => { s.h = Math.max(10, Math.round(v)); }));
        bindNum('spR', geo(v => { s.rotation = Math.max(-360, Math.min(360, Math.round(v))); }));
        body.querySelectorAll('[data-seg="arrange"] button').forEach(b => b.addEventListener('click', () => arrangeShape(b.dataset.value)));
        body.querySelectorAll('[data-arrange]').forEach(b => b.addEventListener('click', () => arrangeShape(b.dataset.arrange)));
        const dup = document.getElementById('spDup'); if (dup) dup.addEventListener('click', duplicateSelectedShape);
        bindLayerSelect('spLayer', s);
        renderKbField(document.getElementById('spArticle'), s);   // GH #433
        const del = document.getElementById('spDel'); if (del) del.addEventListener('click', deleteSelectedShape);
        renderStyleEditor(document.getElementById('spStyle'), window.nmShapeStyle(s.shape_type, s.style), {
            rounded: !!def.rounded, valign: true, editable: ed,
            onChange: patch => { patchShapeStyle(patch); },
            onRebuild: () => renderShapePanel(),
            onReset: () => { s.style = window.nmShapeStyle(s.shape_type, {}); rerenderShape(s); markDirty(); renderShapePanel(); }
        });
    }

    /** Keeps the panel's X / Y / W / H in step after a drag on the canvas. */
    function syncShapePanelGeometry() {
        const s = findShapeByKey(selectedShapeKey);
        if (!s) return;
        [['spX', s.x], ['spY', s.y], ['spW', s.w], ['spH', s.h]].forEach(([id, v]) => {
            const inp = document.getElementById(id);
            if (inp && document.activeElement !== inp) inp.value = v;
        });
    }

    /** A segmented control: [[value, label]...]. */
    function seg(items, current, name, disabled) {
        return '<span class="nm-seg" data-seg="' + name + '">' + items.map(([v, l]) =>
            '<button type="button" data-value="' + escapeAttr(v) + '" class="' + (v === current ? 'active' : '') + '"' + (disabled ? ' disabled' : '') + '>' + escapeHtml(l) + '</button>').join('') + '</span>';
    }

    // Quick styles: one click sets fill, border and text colour together (draw.io's style palette).
    const STYLE_PRESETS = [
        { fill: 'solid', fillColour: '#ffffff', stroke: 'solid', strokeColour: '#64748b', fontColour: '#1f2937' },
        { fill: 'solid', fillColour: '#dbeafe', stroke: 'solid', strokeColour: '#2563eb', fontColour: '#1e3a8a' },
        { fill: 'solid', fillColour: '#dcfce7', stroke: 'solid', strokeColour: '#16a34a', fontColour: '#14532d' },
        { fill: 'solid', fillColour: '#fef3c7', stroke: 'solid', strokeColour: '#d97706', fontColour: '#78350f' },
        { fill: 'solid', fillColour: '#fee2e2', stroke: 'solid', strokeColour: '#dc2626', fontColour: '#7f1d1d' },
        { fill: 'solid', fillColour: '#ede9fe', stroke: 'solid', strokeColour: '#7c3aed', fontColour: '#4c1d95' },
        { fill: 'solid', fillColour: '#334155', stroke: 'solid', strokeColour: '#0f172a', fontColour: '#ffffff' },
        { fill: 'gradient', fillColour: '#ffffff', fillColour2: '#bfdbfe', gradientDir: 'down', stroke: 'solid', strokeColour: '#2563eb', fontColour: '#1e3a8a', shadow: true },
        { fill: 'gradient', fillColour: '#ecfccb', fillColour2: '#22c55e', gradientDir: 'down', stroke: 'solid', strokeColour: '#15803d', fontColour: '#14532d', shadow: true },
        { fill: 'gradient', fillColour: '#fef9c3', fillColour2: '#f59e0b', gradientDir: 'diag', stroke: 'solid', strokeColour: '#b45309', fontColour: '#451a03', shadow: true }
    ];

    /**
     * The style editor shared by the shape Format panel and the node panel's Style section.
     * st is the style to show; opts.onChange(patch) applies a change (inputs keep their focus);
     * opts.onRebuild() redraws the editor when a choice shows or hides controls.
     */
    function renderStyleEditor(box, st, opts) {
        if (!box) return;
        const ed = opts.editable;
        const dis = ed ? '' : ' disabled';
        const L = k => escapeHtml(t('network-mapper.style.' + k));
        const colour = (key, val) => '<input type="color" class="nm-fmt-colour" data-key="' + key + '" value="' + escapeAttr(/^#[0-9a-f]{6}$/i.test(val || '') ? val : '#ffffff') + '"' + dis + '>';
        const fill = st.fill || 'solid', stroke = st.stroke || 'solid';
        let html = '<div class="nm-fmt-section"><div class="nm-fmt-title">' + L('title')
            + (ed ? ' <button type="button" class="nm-detail-icon-btn nm-detail-icon-reset nm-fmt-reset" title="' + escapeAttr(t('network-mapper.style.reset_title')) + '">' + escapeHtml(t('network-mapper.detail.icon_reset')) + '</button>' : '') + '</div>';
        html += '<div class="nm-fmt-presets">' + STYLE_PRESETS.map((p, i) => {
            const bg = p.fill === 'gradient' ? 'linear-gradient(' + p.fillColour + ',' + p.fillColour2 + ')' : p.fillColour;
            return '<button type="button" class="nm-fmt-preset" data-preset="' + i + '" style="background:' + bg + ';border-color:' + p.strokeColour + ';color:' + p.fontColour + ';"' + dis + '>Aa</button>';
        }).join('') + '</div>';
        // Fill
        html += '<div class="nm-fmt-row"><span>' + L('fill') + '</span>' + seg([['none', t('network-mapper.style.none')], ['solid', t('network-mapper.style.solid')], ['gradient', t('network-mapper.style.gradient')]], fill, 'fill', !ed) + '</div>';
        if (fill !== 'none') {
            html += '<div class="nm-fmt-row nm-fmt-sub"><span>' + L('colour') + '</span><span class="nm-fmt-inline">' + colour('fillColour', st.fillColour)
                + (fill === 'gradient' ? colour('fillColour2', st.fillColour2 || '#dbeafe')
                    + '<select class="nm-fmt-select" data-key="gradientDir"' + dis + '>' + ['down', 'right', 'diag', 'radial'].map(d =>
                        '<option value="' + d + '"' + ((st.gradientDir || 'down') === d ? ' selected' : '') + '>' + L('dir_' + d) + '</option>').join('') + '</select>' : '')
                + '</span></div>';
        }
        // Border
        html += '<div class="nm-fmt-row"><span>' + L('border') + '</span>' + seg([['none', t('network-mapper.style.none')], ['solid', t('network-mapper.style.solid')], ['dashed', t('network-mapper.style.dashed')], ['dotted', t('network-mapper.style.dotted')]], stroke, 'stroke', !ed) + '</div>';
        if (stroke !== 'none') {
            html += '<div class="nm-fmt-row nm-fmt-sub"><span>' + L('colour') + '</span><span class="nm-fmt-inline">' + colour('strokeColour', st.strokeColour || '#64748b')
                + '<label class="nm-fmt-num nm-fmt-num-inline"><span>' + L('width') + '</span><input type="number" class="nm-fmt-n" data-key="strokeWidth" value="' + (st.strokeWidth != null ? st.strokeWidth : 1.5) + '" min="0.5" max="20" step="0.5"' + dis + '></label></span></div>';
        }
        // Opacity, shadow, rounded
        const op = st.opacity != null ? st.opacity : 100;
        html += '<div class="nm-fmt-row"><span>' + L('opacity') + '</span><span class="nm-fmt-inline"><input type="range" class="nm-fmt-range" data-key="opacity" min="10" max="100" step="5" value="' + op + '"' + dis + '><span class="nm-fmt-val" data-val="opacity">' + op + '%</span></span></div>';
        html += '<div class="nm-fmt-row"><span>' + L('shadow') + '</span><input type="checkbox" class="nm-fmt-check" data-key="shadow"' + (st.shadow ? ' checked' : '') + dis + '></div>';
        if (opts.rounded) {
            const rd = st.rounded || 0;
            html += '<div class="nm-fmt-row"><span>' + L('rounded') + '</span><span class="nm-fmt-inline"><input type="range" class="nm-fmt-range" data-key="rounded" min="0" max="60" step="1" value="' + rd + '"' + dis + '><span class="nm-fmt-val" data-val="rounded">' + rd + '</span></span></div>';
        }
        html += '</div>';
        // Text
        html += '<div class="nm-fmt-section"><div class="nm-fmt-title">' + L('text') + '</div>';
        html += '<div class="nm-fmt-row"><span>' + L('font') + '</span><select class="nm-fmt-select" data-key="font"' + dis + '>' + (window.NM_FONTS || ['sans']).map(fk =>
            '<option value="' + fk + '"' + ((st.font || 'sans') === fk ? ' selected' : '') + '>' + escapeHtml(fontLabel(fk)) + '</option>').join('') + '</select></div>';
        html += '<div class="nm-fmt-row"><span>' + L('size') + '</span><span class="nm-fmt-inline"><input type="number" class="nm-fmt-n" data-key="fontSize" value="' + (st.fontSize || 13) + '" min="6" max="96" step="1"' + dis + '>'
            + colour('fontColour', st.fontColour || '#1f2937') + '</span></div>';
        html += '<div class="nm-fmt-row"><span>' + L('emphasis') + '</span><span class="nm-seg nm-fmt-toggles">'
            + ['bold', 'italic', 'underline'].map(k => '<button type="button" data-toggle="' + k + '" class="nm-fmt-' + k + (st[k] ? ' active' : '') + '" title="' + L(k) + '"' + dis + '>' + ({ bold: 'B', italic: 'I', underline: 'U' })[k] + '</button>').join('') + '</span></div>';
        html += '<div class="nm-fmt-row"><span>' + L('align') + '</span>' + seg([['left', t('network-mapper.style.left')], ['center', t('network-mapper.style.centre')], ['right', t('network-mapper.style.right')]], st.align || 'center', 'align', !ed) + '</div>';
        if (opts.valign) {
            html += '<div class="nm-fmt-row"><span>' + L('position') + '</span>' + seg([['top', t('network-mapper.style.top')], ['middle', t('network-mapper.style.middle')], ['bottom', t('network-mapper.style.bottom')]], st.valign || 'middle', 'valign', !ed) + '</div>';
        }
        html += '</div>';
        box.innerHTML = html;
        if (!ed) return;

        const change = patch => opts.onChange(patch);
        box.querySelectorAll('.nm-fmt-preset').forEach(b => b.addEventListener('click', () => {
            change(Object.assign({ shadow: false }, STYLE_PRESETS[+b.dataset.preset]));
            opts.onRebuild();
        }));
        box.querySelectorAll('[data-seg]').forEach(g => g.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
            const key = g.dataset.seg, v = b.dataset.value, patch = { [key]: v };
            if (key === 'fill' && v !== 'none' && !st.fillColour) patch.fillColour = '#ffffff';
            if (key === 'fill' && v === 'gradient' && !st.fillColour2) patch.fillColour2 = '#dbeafe';
            if (key === 'stroke' && v !== 'none' && !st.strokeColour) patch.strokeColour = '#64748b';
            if (key === 'stroke' && v !== 'none' && st.strokeWidth == null) patch.strokeWidth = 1.5;
            change(patch);
            opts.onRebuild();
        })));
        box.querySelectorAll('.nm-fmt-colour').forEach(inp => inp.addEventListener('input', () => change({ [inp.dataset.key]: inp.value })));
        box.querySelectorAll('.nm-fmt-select').forEach(sel => sel.addEventListener('change', () => change({ [sel.dataset.key]: sel.value })));
        box.querySelectorAll('.nm-fmt-n').forEach(inp => inp.addEventListener('change', () => {
            const v = parseFloat(inp.value);
            if (!isNaN(v)) change({ [inp.dataset.key]: v });
        }));
        box.querySelectorAll('.nm-fmt-range').forEach(inp => inp.addEventListener('input', () => {
            const v = parseInt(inp.value, 10);
            const lab = box.querySelector('[data-val="' + inp.dataset.key + '"]');
            if (lab) lab.textContent = v + (inp.dataset.key === 'opacity' ? '%' : '');
            change({ [inp.dataset.key]: v });
        }));
        box.querySelectorAll('.nm-fmt-check').forEach(inp => inp.addEventListener('change', () => change({ [inp.dataset.key]: inp.checked })));
        box.querySelectorAll('[data-toggle]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.toggle, on = !b.classList.contains('active');
            b.classList.toggle('active', on);
            change({ [k]: on });
        }));
        const reset = box.querySelector('.nm-fmt-reset');
        if (reset) reset.addEventListener('click', () => opts.onReset());
    }

    function fontLabel(k) {
        const v = t('network-mapper.style.font_' + k);
        if (v && v !== 'network-mapper.style.font_' + k) return v;
        return ({ arial: 'Arial', verdana: 'Verdana', tahoma: 'Tahoma', trebuchet: 'Trebuchet MS', georgia: 'Georgia',
                  times: 'Times New Roman', courier: 'Courier New', comic: 'Comic Sans' })[k] || k;
    }

    // ---- node Style section (same editor, applied to the node's box and label) ----
    // What an unstyled node looks like, so the controls start from the truth.
    const NODE_STYLE_SHOWN = { fill: 'solid', fillColour: '#ffffff', stroke: 'none', strokeColour: '#64748b', strokeWidth: 1.5,
        opacity: 100, shadow: false, rounded: 8, font: 'sans', fontSize: 12, fontColour: '#1f2937', bold: false, italic: false, underline: false, align: 'center' };

    function renderNodeStyleEditor(n) {
        const box = document.getElementById('ndStyle');
        if (!box) return;
        renderStyleEditor(box, Object.assign({}, NODE_STYLE_SHOWN, n.style || {}), {
            rounded: true, valign: false, editable: canEdit(),
            onChange: patch => {
                if (!canEdit()) return;
                n.style = Object.assign({}, n.style || {}, patch);
                rerenderNode(n);
                renderConnectors();
                markDirty();
            },
            onRebuild: () => renderNodeStyleEditor(n),
            onReset: () => { n.style = null; rerenderNode(n); renderConnectors(); markDirty(); renderNodeStyleEditor(n); }
        });
    }

    // =========================================================
    //  Pages (tabs) and layers (GH #433)
    //  Every node and shape carries page_key + layer_key. Only the current
    //  page's items on visible layers are drawn; a locked layer's items can't
    //  be picked up. Layers also order shapes: a higher layer's shapes draw
    //  over a lower one's (each still Behind nodes or In front).
    // =========================================================
    function itemPage(it) { return (it && it.page_key) || 0; }
    function itemLayer(it) { return (it && it.layer_key) || 0; }
    function pageLayers(pk) {
        return layers.filter(l => l.page_key === pk).sort((a, b) => (a.sort - b.sort) || (a.layer_key - b.layer_key));
    }
    function findLayer(pk, lk) { return layers.find(l => l.page_key === pk && l.layer_key === lk) || null; }
    function layerOfItem(it) { return findLayer(itemPage(it), itemLayer(it)); }
    /** On the current page and on a visible layer. */
    function itemVisible(it) {
        if (itemPage(it) !== currentPage) return false;
        const l = layerOfItem(it);
        return !l || l.visible !== false;
    }
    function itemLocked(it) { const l = layerOfItem(it); return !!(l && l.locked); }
    function layerRank(it) {
        const list = pageLayers(itemPage(it));
        const i = list.findIndex(l => l.layer_key === itemLayer(it));
        return i < 0 ? 0 : i;
    }
    function visibleNodes() { return nodes.filter(itemVisible); }
    function visibleShapes() { return shapes.filter(itemVisible); }
    function pageNodes() { return nodes.filter(n => itemPage(n) === currentPage); }
    function activeLayerKey() {
        const k = activeLayers[currentPage];
        const l = k != null ? findLayer(currentPage, k) : null;
        // A new item never lands on a locked or hidden layer - it would vanish or be stuck.
        if (l && !l.locked && l.visible !== false) return l.layer_key;
        const list = pageLayers(currentPage).filter(x => !x.locked && x.visible !== false);
        return list.length ? list[list.length - 1].layer_key : 0;
    }
    /** Stamps a new item with the current page and the active layer. */
    function placeOnPage(it) { it.page_key = currentPage; it.layer_key = activeLayerKey(); return it; }

    /** Makes sure there is at least one page, every page has a layer, and the current page exists. */
    function ensurePagesLayers() {
        if (!pages.length) pages = [{ page_key: 0, name: t('network-mapper.pages.default_name', { n: 1 }), sort: 0 }];
        // Items on a page that has no row (an API caller, an older save) still get a tab.
        nodes.concat(shapes).forEach(it => {
            if (!pages.some(p => p.page_key === itemPage(it))) {
                pages.push({ page_key: itemPage(it), name: t('network-mapper.pages.default_name', { n: pages.length + 1 }), sort: pages.length });
            }
        });
        pages.sort((a, b) => (a.sort - b.sort) || (a.page_key - b.page_key));
        pages.forEach(p => {
            if (!pageLayers(p.page_key).length) {
                layers.push({ page_key: p.page_key, layer_key: 0, name: t('network-mapper.layers.default_name', { n: 1 }), sort: 0, visible: true, locked: false });
            }
        });
        // GH #433: every shape has a uid for connectors; a connector whose end is gone is dropped.
        shapes.forEach(ensureShapeUid);
        connectors = connectors.filter(c => endOf(c, 'from') && endOf(c, 'to'));
        // An item on a layer that is gone goes on the page's bottom layer.
        nodes.concat(shapes).forEach(it => {
            if (!layerOfItem(it)) it.layer_key = pageLayers(itemPage(it))[0].layer_key;
        });
        if (!pages.some(p => p.page_key === currentPage)) currentPage = pages[0].page_key;
    }

    /** Clears every selection - used before switching page. */
    function clearSelection() {
        selectNode(null);
        selectConnector(null);
        selectShape(null);
        clearMulti();
    }

    function switchPage(pk) {
        if (pk === currentPage || !pages.some(p => p.page_key === pk)) return;
        clearSelection();
        currentPage = pk;
        renderNodes();
        renderShapes();
        renderPageTabs();
        renderLayersPanel();
    }

    // ---- the tab bar ----
    function renderPageTabs() {
        const bar = document.getElementById('pageTabs');
        if (!bar) return;
        const ed = canEdit();
        bar.innerHTML = pages.map(p =>
            '<button type="button" class="nm-page-tab' + (p.page_key === currentPage ? ' active' : '') + '" data-page="' + p.page_key + '"'
            + ' title="' + escapeAttr(ed ? t('network-mapper.pages.tab_title') : p.name) + '">' + escapeHtml(p.name) + '</button>').join('')
            + (ed ? '<button type="button" class="nm-page-add" title="' + escapeAttr(t('network-mapper.pages.add')) + '">+</button>' : '');
        bar.querySelectorAll('.nm-page-tab').forEach(b => {
            const pk = +b.dataset.page;
            b.addEventListener('click', () => switchPage(pk));
            if (ed) {
                b.addEventListener('dblclick', () => renamePageInline(b, pk));
                b.addEventListener('contextmenu', e => { e.preventDefault(); openPageMenu(e, pk); });
            }
        });
        const add = bar.querySelector('.nm-page-add');
        if (add) add.addEventListener('click', addPage);
    }

    function renamePageInline(btn, pk) {
        const p = pages.find(x => x.page_key === pk);
        if (!p || !canEdit()) return;
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'nm-page-rename';
        inp.value = p.name;
        inp.maxLength = 100;
        btn.replaceWith(inp);
        inp.focus();
        inp.select();
        let done = false;
        const finish = keep => {
            if (done) return;
            done = true;
            const v = inp.value.trim();
            if (keep && v && v !== p.name) { p.name = v; markDirty(); }
            renderPageTabs();
        };
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            e.stopPropagation();
        });
        inp.addEventListener('blur', () => finish(true));
    }

    function closePageMenu() { document.querySelectorAll('.nm-page-menu').forEach(m => m.remove()); }
    function openPageMenu(e, pk) {
        closePageMenu();
        const m = document.createElement('div');
        m.className = 'nm-page-menu';
        const idx = pages.findIndex(p => p.page_key === pk);
        const items = [
            ['rename', t('network-mapper.pages.rename'), true],
            ['duplicate', t('network-mapper.pages.duplicate'), true],
            ['left', t('network-mapper.pages.move_left'), idx > 0],
            ['right', t('network-mapper.pages.move_right'), idx < pages.length - 1],
            ['delete', t('network-mapper.pages.delete'), pages.length > 1]
        ];
        m.innerHTML = items.map(([k, l, on]) => '<button type="button" data-act="' + k + '"' + (on ? '' : ' disabled') + '>' + escapeHtml(l) + '</button>').join('');
        document.body.appendChild(m);
        const r = m.getBoundingClientRect();
        m.style.left = Math.min(e.clientX, window.innerWidth - r.width - 8) + 'px';
        m.style.top = Math.max(8, e.clientY - r.height - 4) + 'px';
        m.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
            closePageMenu();
            const act = b.dataset.act;
            if (act === 'rename') { const tab = document.querySelector('.nm-page-tab[data-page="' + pk + '"]'); if (tab) renamePageInline(tab, pk); }
            if (act === 'duplicate') duplicatePage(pk);
            if (act === 'left' || act === 'right') movePage(pk, act === 'left' ? -1 : 1);
            if (act === 'delete') deletePage(pk);
        }));
        setTimeout(() => document.addEventListener('mousedown', function off(ev) {
            if (!m.contains(ev.target)) { closePageMenu(); document.removeEventListener('mousedown', off); }
        }), 0);
    }

    function nextPageKey() { return pages.reduce((m, p) => Math.max(m, p.page_key), -1) + 1; }

    function addPage() {
        if (!canEdit()) return;
        const pk = nextPageKey();
        pages.push({ page_key: pk, name: t('network-mapper.pages.default_name', { n: pages.length + 1 }), sort: pages.reduce((m, p) => Math.max(m, p.sort), 0) + 1 });
        layers.push({ page_key: pk, layer_key: 0, name: t('network-mapper.layers.default_name', { n: 1 }), sort: 0, visible: true, locked: false });
        markDirty();
        clearSelection();
        currentPage = pk;
        renderNodes();
        renderShapes();
        renderPageTabs();
        renderLayersPanel();
    }

    function movePage(pk, dir) {
        const i = pages.findIndex(p => p.page_key === pk), j = i + dir;
        if (i < 0 || j < 0 || j >= pages.length) return;
        const tmp = pages[i]; pages[i] = pages[j]; pages[j] = tmp;
        pages.forEach((p, k) => { p.sort = k; });
        markDirty();
        renderPageTabs();
    }

    function deletePage(pk) {
        if (!canEdit() || pages.length < 2) return;
        const p = pages.find(x => x.page_key === pk);
        const has = nodes.some(n => itemPage(n) === pk) || shapes.some(s => itemPage(s) === pk);
        if (has && !confirm(t('network-mapper.pages.delete_confirm', { name: p ? p.name : '' }))) return;
        const gone = new Set(nodes.filter(n => itemPage(n) === pk).map(n => nodeRef(n)));
        connectors = connectors.filter(c => !gone.has(c.from_node_id) && !gone.has(c.to_node_id)
            && !shapes.some(sh => itemPage(sh) === pk && touchesShape(c, sh)));
        nodes = nodes.filter(n => itemPage(n) !== pk);
        shapes = shapes.filter(s => itemPage(s) !== pk);
        layers = layers.filter(l => l.page_key !== pk);
        pages = pages.filter(x => x.page_key !== pk);
        pages.forEach((x, k) => { x.sort = k; });
        clearSelection();
        if (currentPage === pk) currentPage = pages[0].page_key;
        markDirty();
        renderNodes();
        renderShapes();
        renderPageTabs();
        renderLayersPanel();
    }

    /** Copies a page - its layers, nodes, shapes and the connectors between its nodes - into a new tab. */
    function duplicatePage(pk) {
        if (!canEdit()) return;
        const src = pages.find(x => x.page_key === pk);
        if (!src) return;
        const nk = nextPageKey();
        const at = pages.indexOf(src) + 1;
        pages.splice(at, 0, { page_key: nk, name: t('network-mapper.pages.copy_name', { name: src.name }), sort: 0 });
        pages.forEach((x, k) => { x.sort = k; });
        pageLayers(pk).forEach(l => layers.push(Object.assign({}, l, { page_key: nk })));
        const refMap = new Map();
        nodes.filter(n => itemPage(n) === pk).forEach(n => {
            const copy = Object.assign({}, n, { id: null, tempId: nextTempId--, page_key: nk,
                style: n.style ? JSON.parse(JSON.stringify(n.style)) : null });
            refMap.set(nodeRef(n), copy.tempId);
            nodes.push(copy);
        });
        const uidMap = new Map();
        shapes.filter(s => itemPage(s) === pk).forEach(s => {
            const copy = Object.assign({}, s, { id: null, tempId: nextTempShapeId--, page_key: nk, uid: newUid(),
                style: s.style ? JSON.parse(JSON.stringify(s.style)) : null });
            if (s.uid) uidMap.set(s.uid, copy.uid);
            shapes.push(copy);
        });
        remapConnectors(connectors.slice(), refMap, uidMap);
        markDirty();
        clearSelection();
        currentPage = nk;
        renderNodes();
        renderShapes();
        renderPageTabs();
        renderLayersPanel();
    }

    // ---- the Layers panel (toolbar dropdown) ----
    function toggleLayersPanel(e) {
        if (e) e.stopPropagation();
        const dd = document.getElementById('layersDropdown');
        if (!dd) return;
        const open = dd.style.display === 'none';
        dd.style.display = open ? '' : 'none';
        if (open) renderLayersPanel();
    }
    function closeLayersPanel() { const dd = document.getElementById('layersDropdown'); if (dd) dd.style.display = 'none'; }

    function renderLayersPanel() {
        const lab = document.getElementById('layersBtnCount');
        if (lab) lab.textContent = String(pageLayers(currentPage).length);
        const dd = document.getElementById('layersDropdown');
        if (!dd || dd.style.display === 'none') return;
        const ed = canEdit();
        const list = pageLayers(currentPage).slice().reverse();   // top layer first, as draw.io lists them
        const active = activeLayerKey();
        const count = l => nodes.filter(n => itemPage(n) === currentPage && itemLayer(n) === l.layer_key).length
                         + shapes.filter(s => itemPage(s) === currentPage && itemLayer(s) === l.layer_key).length;
        const eye = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        const eyeOff = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/></svg>';
        const lock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
        const unlock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';
        dd.innerHTML = '<div class="nm-layers-head">' + escapeHtml(t('network-mapper.layers.title')) + '</div>'
            + list.map((l, i) => '<div class="nm-layer-row' + (l.layer_key === active ? ' active' : '') + (l.visible === false ? ' is-hidden' : '') + '" data-layer="' + l.layer_key + '">'
                + '<button type="button" class="nm-layer-ico" data-act="eye" title="' + escapeAttr(t(l.visible === false ? 'network-mapper.layers.show' : 'network-mapper.layers.hide')) + '">' + (l.visible === false ? eyeOff : eye) + '</button>'
                + '<button type="button" class="nm-layer-ico' + (l.locked ? ' on' : '') + '" data-act="lock" title="' + escapeAttr(t(l.locked ? 'network-mapper.layers.unlock' : 'network-mapper.layers.lock')) + '"' + (ed ? '' : ' disabled') + '>' + (l.locked ? lock : unlock) + '</button>'
                + '<span class="nm-layer-name" title="' + escapeAttr(ed ? t('network-mapper.layers.name_title') : '') + '">' + escapeHtml(l.name) + '</span>'
                + '<span class="nm-layer-count">' + count(l) + '</span>'
                + (ed ? '<button type="button" class="nm-layer-ico" data-act="up" title="' + escapeAttr(t('network-mapper.layers.up')) + '"' + (i === 0 ? ' disabled' : '') + '>&#9650;</button>'
                      + '<button type="button" class="nm-layer-ico" data-act="down" title="' + escapeAttr(t('network-mapper.layers.down')) + '"' + (i === list.length - 1 ? ' disabled' : '') + '>&#9660;</button>'
                      + '<button type="button" class="nm-layer-ico nm-layer-del" data-act="del" title="' + escapeAttr(t('network-mapper.layers.delete')) + '"' + (list.length < 2 ? ' disabled' : '') + '>&times;</button>' : '')
                + '</div>').join('')
            + (ed ? '<button type="button" class="nm-layers-add">+ ' + escapeHtml(t('network-mapper.layers.add')) + '</button>' : '')
            + '<div class="nm-layers-hint">' + escapeHtml(t('network-mapper.layers.hint')) + '</div>';
        dd.querySelectorAll('.nm-layer-row').forEach(row => {
            const lk = +row.dataset.layer;
            const l = findLayer(currentPage, lk);
            row.addEventListener('click', e => {
                const b = e.target.closest('[data-act]');
                if (b) {
                    e.stopPropagation();
                    layerAction(l, b.dataset.act);
                    return;
                }
                if (!l.locked) { activeLayers[currentPage] = lk; renderLayersPanel(); }
            });
            if (ed) row.querySelector('.nm-layer-name').addEventListener('dblclick', e => { e.stopPropagation(); renameLayerInline(row, l); });
        });
        const add = dd.querySelector('.nm-layers-add');
        if (add) add.addEventListener('click', e => { e.stopPropagation(); addLayer(); });
    }

    function layerAction(l, act) {
        if (!l) return;
        const ed = canEdit();
        if (act === 'eye') {
            l.visible = l.visible === false;
            if (l.visible === false) clearSelection();
            if (ed) markDirty();   // a read-only version can still hide a layer to look - it just isn't saved
        } else if (!ed) {
            return;
        } else if (act === 'lock') {
            l.locked = !l.locked;
            if (l.locked) clearSelection();
            markDirty();
        } else if (act === 'up' || act === 'down') {
            const list = pageLayers(currentPage);
            const i = list.indexOf(l), j = i + (act === 'up' ? 1 : -1);
            if (j < 0 || j >= list.length) return;
            const tmp = list[i]; list[i] = list[j]; list[j] = tmp;
            list.forEach((x, k) => { x.sort = k; });
            markDirty();
        } else if (act === 'del') {
            deleteLayer(l);
            return;
        }
        renderNodes();
        renderShapes();
        renderLayersPanel();
    }

    function addLayer() {
        if (!canEdit()) return;
        const list = pageLayers(currentPage);
        const lk = list.reduce((m, l) => Math.max(m, l.layer_key), -1) + 1;
        layers.push({ page_key: currentPage, layer_key: lk, name: t('network-mapper.layers.default_name', { n: list.length + 1 }), sort: list.length, visible: true, locked: false });
        activeLayers[currentPage] = lk;
        markDirty();
        renderLayersPanel();
    }

    /** Deletes a layer; what was on it moves to the layer below it (or above, for the bottom one). */
    function deleteLayer(l) {
        const list = pageLayers(currentPage);
        if (list.length < 2) return;
        const i = list.indexOf(l);
        const to = list[i > 0 ? i - 1 : 1];
        const n = nodes.filter(x => itemPage(x) === currentPage && itemLayer(x) === l.layer_key).length
                + shapes.filter(x => itemPage(x) === currentPage && itemLayer(x) === l.layer_key).length;
        if (n && !confirm(t('network-mapper.layers.delete_confirm', { name: l.name, to: to.name }))) return;
        nodes.concat(shapes).forEach(it => { if (itemPage(it) === currentPage && itemLayer(it) === l.layer_key) it.layer_key = to.layer_key; });
        layers = layers.filter(x => x !== l);
        pageLayers(currentPage).forEach((x, k) => { x.sort = k; });
        if (activeLayers[currentPage] === l.layer_key) delete activeLayers[currentPage];
        markDirty();
        renderNodes();
        renderShapes();
        renderLayersPanel();
    }

    function renameLayerInline(row, l) {
        const span = row.querySelector('.nm-layer-name');
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'nm-layer-rename';
        inp.value = l.name;
        inp.maxLength = 100;
        span.replaceWith(inp);
        inp.focus();
        inp.select();
        let done = false;
        const finish = keep => {
            if (done) return;
            done = true;
            const v = inp.value.trim();
            if (keep && v && v !== l.name) { l.name = v; markDirty(); }
            renderLayersPanel();
        };
        inp.addEventListener('click', e => e.stopPropagation());
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            e.stopPropagation();
        });
        inp.addEventListener('blur', () => finish(true));
    }

    /** A Layer row (select) for the node or shape panels: moves the item to another layer of its page. */
    function layerSelectHtml(it, id) {
        const ed = canEdit();
        return '<select class="nm-fmt-select" id="' + id + '"' + (ed ? '' : ' disabled') + '>'
            + pageLayers(itemPage(it)).slice().reverse().map(l => '<option value="' + l.layer_key + '"' + (l.layer_key === itemLayer(it) ? ' selected' : '')
                + (l.locked ? ' disabled' : '') + '>' + escapeHtml(l.name) + '</option>').join('') + '</select>';
    }
    function bindLayerSelect(id, it, after) {
        const sel = document.getElementById(id);
        if (!sel) return;
        sel.addEventListener('change', () => {
            it.layer_key = +sel.value;
            markDirty();
            renderNodes();
            renderShapes();
            renderLayersPanel();
            if (after) after();
        });
    }

    // =========================================================
    //  Multi-select, grouping, align / distribute, copy / paste (GH #433)
    //  multi holds 'n:<nodeKey>' / 's:<shapeKey>'. Two or more = the Selection
    //  panel; one is handed back to the ordinary single selection. Items with
    //  the same group_key on a page select and move together (Alt+click picks
    //  one out).
    // =========================================================
    let multi = new Set();
    let multiDrag = null;        // { start, items: [{it, kind, x0, y0}], moved }
    let band = null;             // rubber band { x0, y0, el, add }
    let clipboard = null;        // { nodes, shapes, connectors } copied with Ctrl+C

    const mkN = n => 'n:' + nodeKey(n);
    const mkS = s => 's:' + shapeKey(s);
    function multiItems() {
        const out = [];
        multi.forEach(k => {
            if (k.indexOf('n:') === 0) { const n = findNodeByKey(k.slice(2)); if (n) out.push({ kind: 'n', it: n }); }
            else { const s = findShapeByKey(k.slice(2)); if (s) out.push({ kind: 's', it: s }); }
        });
        return out;
    }
    /** The members of an item's group on this page (visible, unlocked) - or just the item. */
    function groupOf(kind, it) {
        if (!it.group_key) return [{ kind, it }];
        return visibleNodes().filter(n => n.group_key === it.group_key && !itemLocked(n)).map(n => ({ kind: 'n', it: n }))
            .concat(visibleShapes().filter(s => s.group_key === it.group_key && !itemLocked(s)).map(s => ({ kind: 's', it: s })));
    }

    /** Replaces the selection with these items: one = the ordinary selection, two or more = multi. */
    function setSelection(list) {
        const keys = new Set(list.map(x => x.kind === 'n' ? mkN(x.it) : mkS(x.it)));
        if (keys.size < 2) {
            multi = new Set();
            applyMultiClasses();
            const only = list[0];
            if (!only) { selectNode(null); selectShape(null); selectConnector(null); closeShapePanel(); return; }
            if (only.kind === 'n') selectNode(nodeKey(only.it)); else selectShape(shapeKey(only.it));
            return;
        }
        selectNode(null);
        selectConnector(null);
        if (selectedShapeKey != null) { const p = findShapeByKey(selectedShapeKey); selectedShapeKey = null; if (p) rerenderShape(p); }
        multi = keys;
        applyMultiClasses();
        renderMultiPanel();
    }
    function clearMulti() { if (multi.size) { multi = new Set(); applyMultiClasses(); closeShapePanel(); } }

    /** Marks the multi-selected items on the canvas without rebuilding them. */
    function applyMultiClasses() {
        if (!elCanvasInner) return;
        elCanvasInner.querySelectorAll('.nm-node').forEach(el => el.classList.toggle('in-multi', multi.has('n:' + el.dataset.key)));
        elCanvasInner.querySelectorAll('.nm-shape').forEach(el => el.classList.toggle('in-multi', multi.has('s:' + el.dataset.key)));
        drawGroupOutlines();
    }

    /** A dashed box round each group that is part of the selection. */
    function drawGroupOutlines() {
        elCanvasInner.querySelectorAll('.nm-group-outline').forEach(el => el.remove());
        const groups = {};
        multiItems().forEach(x => { if (x.it.group_key) (groups[x.it.group_key] = groups[x.it.group_key] || []).push(x); });
        if (selectedShapeKey != null) { const s = findShapeByKey(selectedShapeKey); if (s && s.group_key) groups[s.group_key] = groups[s.group_key] || []; }
        Object.keys(groups).forEach(g => {
            const b = boundsOf(groupOf('s', { group_key: g }));
            if (!b) return;
            const o = document.createElement('div');
            o.className = 'nm-group-outline';
            o.style.left = (b.x - 8) + 'px'; o.style.top = (b.y - 8) + 'px';
            o.style.width = (b.w + 16) + 'px'; o.style.height = (b.h + 16) + 'px';
            elCanvasInner.appendChild(o);
        });
    }

    /** An item's box in model px. */
    function boxOf(x) {
        if (x.kind === 's') return { x: x.it.x, y: x.it.y, w: x.it.w, h: x.it.h };
        const e = nodeExtent(x.it);
        return { x: x.it.x, y: x.it.y, w: e.w, h: e.h };
    }
    function boundsOf(list) {
        if (!list.length) return null;
        let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        list.forEach(x => { const b = boxOf(x); x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y); x2 = Math.max(x2, b.x + b.w); y2 = Math.max(y2, b.y + b.h); });
        return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }

    /**
     * Mousedown on a node or shape, before its own handler: Shift / Ctrl toggles it in the
     * selection; a click on a selected item (or a grouped one) starts moving the whole selection.
     * Returns true when it has handled the event.
     */
    function multiMouseDown(e, kind, it) {
        if (e.button !== 0) return false;
        const key = kind === 'n' ? mkN(it) : mkS(it);
        if (e.shiftKey || e.ctrlKey || e.metaKey) {
            e.stopPropagation();
            e.preventDefault();
            let cur = multi.size ? multiItems() : [];
            if (!cur.length) {
                if (selectedNodeKey != null) { const n = findNodeByKey(selectedNodeKey); if (n) cur.push({ kind: 'n', it: n }); }
                if (selectedShapeKey != null) { const s = findShapeByKey(selectedShapeKey); if (s) cur.push({ kind: 's', it: s }); }
            }
            const members = e.altKey ? [{ kind, it }] : groupOf(kind, it);
            const has = cur.some(x => (x.kind === 'n' ? mkN(x.it) : mkS(x.it)) === key);
            const mk = x => x.kind === 'n' ? mkN(x.it) : mkS(x.it);
            const memberKeys = new Set(members.map(mk));
            cur = has ? cur.filter(x => !memberKeys.has(mk(x))) : cur.concat(members.filter(m => !cur.some(x => mk(x) === mk(m))));
            setSelection(cur);
            return true;
        }
        let sel = null;
        if (multi.has(key)) sel = multiItems();
        else if (it.group_key && !e.altKey) {
            const g = groupOf(kind, it);
            if (g.length > 1) { setSelection(g); sel = g; }
        }
        if (!sel) { if (multi.size) clearMulti(); return false; }
        e.stopPropagation();
        e.preventDefault();
        if (!canEdit()) return true;
        multiDrag = { start: canvasModelCoords(e), items: sel.map(x => ({ kind: x.kind, it: x.it, x0: x.it.x, y0: x.it.y })), moved: false };
        document.addEventListener('mousemove', onMultiDragMove);
        document.addEventListener('mouseup', onMultiDragUp);
        return true;
    }
    function onMultiDragMove(e) {
        if (!multiDrag) return;
        const c = canvasModelCoords(e);
        const dx = snapS(c.x - multiDrag.start.x), dy = snapS(c.y - multiDrag.start.y);
        if (!multiDrag.moved && !dx && !dy) return;
        multiDrag.moved = true;
        multiDrag.items.forEach(m => {
            m.it.x = Math.max(0, m.x0 + dx);
            m.it.y = Math.max(0, m.y0 + dy);
            const sel = m.kind === 'n' ? '.nm-node[data-key="' + cssEscape(nodeKey(m.it)) + '"]' : '.nm-shape[data-key="' + cssEscape(shapeKey(m.it)) + '"]';
            const el = elCanvasInner.querySelector(sel);
            if (el) { el.style.left = m.it.x + 'px'; el.style.top = m.it.y + 'px'; }
        });
        renderConnectors();
        drawGroupOutlines();
    }
    function onMultiDragUp() {
        document.removeEventListener('mousemove', onMultiDragMove);
        document.removeEventListener('mouseup', onMultiDragUp);
        if (multiDrag && multiDrag.moved) markDirty();
        multiDrag = null;
    }

    // ---- rubber band on empty canvas ----
    function startBand(e) {
        if (e.button !== 0) return;
        const c = canvasModelCoords(e);
        const el = document.createElement('div');
        el.className = 'nm-band';
        elCanvasInner.appendChild(el);
        band = { x0: c.x, y0: c.y, el, add: e.shiftKey, moved: false };
        document.addEventListener('mousemove', onBandMove);
        document.addEventListener('mouseup', onBandUp);
    }
    function onBandMove(e) {
        if (!band) return;
        const c = canvasModelCoords(e);
        const x = Math.min(band.x0, c.x), y = Math.min(band.y0, c.y), w = Math.abs(c.x - band.x0), h = Math.abs(c.y - band.y0);
        if (w > 3 || h > 3) band.moved = true;
        Object.assign(band.el.style, { left: x + 'px', top: y + 'px', width: w + 'px', height: h + 'px' });
        band.rect = { x, y, w, h };
    }
    function onBandUp() {
        document.removeEventListener('mousemove', onBandMove);
        document.removeEventListener('mouseup', onBandUp);
        if (!band) return;
        const b = band;
        band = null;
        b.el.remove();
        if (!b.moved || !b.rect) return;
        const r = b.rect;
        const inside = x => { const bx = boxOf(x); return bx.x >= r.x && bx.y >= r.y && bx.x + bx.w <= r.x + r.w && bx.y + bx.h <= r.y + r.h; };
        let picked = visibleNodes().filter(n => !itemLocked(n)).map(n => ({ kind: 'n', it: n }))
            .concat(visibleShapes().filter(s => !itemLocked(s)).map(s => ({ kind: 's', it: s }))).filter(inside);
        // A grouped item brings its whole group.
        const seen = new Set(), out = [];
        const mk = x => x.kind === 'n' ? mkN(x.it) : mkS(x.it);
        picked.forEach(x => groupOf(x.kind, x.it).forEach(m => { if (!seen.has(mk(m))) { seen.add(mk(m)); out.push(m); } }));
        if (b.add) multiItems().forEach(m => { if (!seen.has(mk(m))) { seen.add(mk(m)); out.push(m); } });
        setSelection(out);
    }

    /** Copies the connectors whose both ends were copied, pointing them at the copies. */
    function remapConnectors(list, refMap, uidMap) {
        const mapEnd = (c, side, out) => {
            const uid = c[side + '_shape_uid'];
            if (uid) { if (!uidMap.has(uid)) return false; out[side + '_shape_uid'] = uidMap.get(uid); out[side + '_node_id'] = null; return true; }
            if (!refMap.has(c[side + '_node_id'])) return false;
            out[side + '_node_id'] = refMap.get(c[side + '_node_id']); out[side + '_shape_uid'] = null; return true;
        };
        list.forEach(c => {
            const out = Object.assign(JSON.parse(JSON.stringify(c)), { id: null, _tempKey: nextTempId-- });
            if (mapEnd(c, 'from', out) && mapEnd(c, 'to', out)) connectors.push(out);
        });
    }

    function selectAllOnPage() {
        setSelection(visibleNodes().filter(n => !itemLocked(n)).map(n => ({ kind: 'n', it: n }))
            .concat(visibleShapes().filter(s => !itemLocked(s)).map(s => ({ kind: 's', it: s }))));
    }

    // ---- actions on the selection ----
    function rerenderAfterMulti() {
        renderShapes();
        renderNodes();   // ...which redraws the connectors with everything in place
        applyMultiClasses();
        markDirty();
    }
    function alignSelection(how) {
        const items = multiItems();
        if (items.length < 2 || !canEdit()) return;
        const B = boundsOf(items);
        items.forEach(x => {
            const b = boxOf(x);
            if (how === 'left') x.it.x = B.x;
            if (how === 'centre') x.it.x = Math.round(B.x + B.w / 2 - b.w / 2);
            if (how === 'right') x.it.x = B.x + B.w - b.w;
            if (how === 'top') x.it.y = B.y;
            if (how === 'middle') x.it.y = Math.round(B.y + B.h / 2 - b.h / 2);
            if (how === 'bottom') x.it.y = B.y + B.h - b.h;
        });
        rerenderAfterMulti();
    }
    function distributeSelection(axis) {
        const items = multiItems();
        if (items.length < 3 || !canEdit()) return;
        const c = x => { const b = boxOf(x); return axis === 'h' ? b.x + b.w / 2 : b.y + b.h / 2; };
        items.sort((a, b) => c(a) - c(b));
        const first = c(items[0]), last = c(items[items.length - 1]), step = (last - first) / (items.length - 1);
        items.forEach((x, i) => {
            const b = boxOf(x);
            if (axis === 'h') x.it.x = Math.round(first + step * i - b.w / 2);
            else x.it.y = Math.round(first + step * i - b.h / 2);
        });
        rerenderAfterMulti();
    }
    function groupSelection() {
        const items = multiItems();
        if (items.length < 2 || !canEdit()) return;
        const g = 'g' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
        items.forEach(x => { x.it.group_key = g; });
        markDirty();
        applyMultiClasses();
        renderMultiPanel();
    }
    function ungroupSelection() {
        const items = multiItems();
        if (!items.length || !canEdit()) return;
        items.forEach(x => { x.it.group_key = null; });
        markDirty();
        applyMultiClasses();
        renderMultiPanel();
    }
    function deleteSelection() {
        const items = multiItems();
        if (!items.length || !canEdit()) return;
        const gone = new Set(items.filter(x => x.kind === 'n').map(x => nodeRef(x.it)));
        connectors = connectors.filter(c => !gone.has(c.from_node_id) && !gone.has(c.to_node_id));
        const goneShapes = items.filter(x => x.kind === 's').map(x => x.it);
        connectors = connectors.filter(c => !goneShapes.some(sh => touchesShape(c, sh)));
        nodes = nodes.filter(n => !items.some(x => x.it === n));
        shapes = shapes.filter(s => !items.some(x => x.it === s));
        multi = new Set();
        closeShapePanel();
        renderNodes();
        renderShapes();
        applyMultiClasses();
        markDirty();
    }
    function nudgeSelection(dx, dy) {
        multiItems().forEach(x => { x.it.x = Math.max(0, x.it.x + dx); x.it.y = Math.max(0, x.it.y + dy); });
        rerenderAfterMulti();
    }
    function moveSelectionToLayer(lk) {
        multiItems().forEach(x => { x.it.layer_key = lk; });
        rerenderAfterMulti();
        renderLayersPanel();
    }

    // ---- copy / paste ----
    function copySelection() {
        let items = multiItems();
        if (!items.length) {
            if (selectedShapeKey != null) { const s = findShapeByKey(selectedShapeKey); if (s) items = [{ kind: 's', it: s }]; }
            else if (selectedNodeKey != null) { const n = findNodeByKey(selectedNodeKey); if (n) items = [{ kind: 'n', it: n }]; }
        }
        if (!items.length) return false;
        const ns = items.filter(x => x.kind === 'n').map(x => x.it);
        const ss = items.filter(x => x.kind === 's').map(x => x.it);
        const refs = new Set(ns.map(nodeRef));
        const uids = new Set(ss.map(sh => sh.uid).filter(Boolean));
        const inside = (c, side) => c[side + '_shape_uid'] ? uids.has(c[side + '_shape_uid']) : refs.has(c[side + '_node_id']);
        clipboard = {
            nodes: JSON.parse(JSON.stringify(ns)),
            shapes: JSON.parse(JSON.stringify(ss)),
            connectors: JSON.parse(JSON.stringify(connectors.filter(c => inside(c, 'from') && inside(c, 'to'))))
        };
        if (window.showToast) showToast(t('network-mapper.select.copied', { count: items.length }), 'success');
        return true;
    }
    /**
     * Pastes onto the current page and active layer, 20px along when it lands on the page it came
     * from. A CMDB object is on a page once, so one that is already here is left out.
     */
    function pasteClipboard(at) {
        if (!clipboard || !canEdit()) return false;
        const lk = activeLayerKey();
        const here = new Set(pageNodes().map(n => n.cmdb_object_id));
        const groupMap = {};
        const newGroup = g => g ? (groupMap[g] = groupMap[g] || ('g' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6))) : null;
        const fromThisPage = clipboard.nodes.concat(clipboard.shapes).some(x => itemPage(x) === currentPage);
        let off = fromThisPage ? 20 : 0, offY = off;
        if (at && at.x != null) {
            // "Paste here": the copied items' top-left corner lands on the spot that was right-clicked.
            const all = clipboard.nodes.concat(clipboard.shapes);
            if (all.length) {
                off = snapS(at.x - Math.min.apply(null, all.map(x => x.x)));
                offY = snapS(at.y - Math.min.apply(null, all.map(x => x.y)));
            }
        }
        const refMap = new Map();
        const pasted = [];
        let skipped = 0;
        clipboard.nodes.forEach(src => {
            if (here.has(src.cmdb_object_id)) { skipped++; return; }
            const n = Object.assign(JSON.parse(JSON.stringify(src)), { id: null, tempId: nextTempId--, page_key: currentPage, layer_key: lk,
                x: Math.max(0, src.x + off), y: Math.max(0, src.y + offY), group_key: newGroup(src.group_key) });
            refMap.set(nodeRef(src), n.tempId);
            nodes.push(n);
            here.add(n.cmdb_object_id);
            pasted.push({ kind: 'n', it: n });
        });
        const maxZ = shapes.reduce((m, s) => Math.max(m, s.z || 0), 0);
        const uidMap = new Map();
        clipboard.shapes.forEach((src, i) => {
            const s = Object.assign(JSON.parse(JSON.stringify(src)), { id: null, tempId: nextTempShapeId--, page_key: currentPage, layer_key: lk,
                x: src.x + off, y: src.y + offY, z: maxZ + 1 + i, group_key: newGroup(src.group_key), uid: newUid() });
            if (src.uid) uidMap.set(src.uid, s.uid);
            shapes.push(s);
            pasted.push({ kind: 's', it: s });
        });
        remapConnectors(clipboard.connectors, refMap, uidMap);
        // Paste again and it moves along again.
        clipboard.nodes.forEach(n => { n.x += off; n.y += offY; n.page_key = currentPage; });
        clipboard.shapes.forEach(s => { s.x += off; s.y += offY; s.page_key = currentPage; });
        renderNodes();
        renderShapes();
        setSelection(pasted);
        markDirty();
        if (skipped && window.showToast) showToast(t('network-mapper.select.skipped', { count: skipped }), 'info');
        return true;
    }

    // ---- the Selection panel (in the Format panel's frame) ----
    function renderMultiPanel() {
        const items = multiItems();
        if (items.length < 2 || !elShapePanel) return;
        closeDetail();
        elShapePanel.classList.add('open');
        elShapePanel.setAttribute('aria-hidden', 'false');
        const ed = canEdit();
        const dis = ed ? '' : ' disabled';
        document.getElementById('spIcon').innerHTML = '';
        document.getElementById('spName').textContent = t('network-mapper.select.title', { count: items.length });
        const grouped = items.some(x => x.it.group_key);
        const L = k => escapeHtml(t('network-mapper.select.' + k));
        const btn = (act, label, extra) => '<button type="button" class="nm-detail-icon-btn" data-act="' + act + '"' + dis + (extra || '') + '>' + label + '</button>';
        const pageLayersList = pageLayers(currentPage).slice().reverse();
        let html = '<div class="nm-fmt-section"><div class="nm-fmt-title">' + L('align') + '</div><div class="nm-fmt-btns">'
            // Some languages use one word for centre and middle: the arrows say which way it aligns.
            + ['left', 'centre', 'right', 'top', 'middle', 'bottom'].map(a => btn('align-' + a, L('align_' + a) + (a === 'centre' ? ' &#8596;' : a === 'middle' ? ' &#8597;' : ''))).join('') + '</div>'
            + '<div class="nm-fmt-title">' + L('distribute') + '</div><div class="nm-fmt-btns">'
            + btn('dist-h', L('dist_h'), items.length < 3 ? ' disabled' : '') + btn('dist-v', L('dist_v'), items.length < 3 ? ' disabled' : '') + '</div>'
            + '<div class="nm-fmt-btns">' + btn('group', L('group')) + (grouped ? btn('ungroup', L('ungroup')) : '')
            + btn('copy', L('copy')) + '<button type="button" class="nm-detail-icon-btn nm-fmt-danger" data-act="delete"' + dis + '>' + escapeHtml(t('common.delete')) + '</button></div>'
            + '<div class="nm-fmt-row"><span>' + escapeHtml(t('network-mapper.layers.field')) + '</span><select class="nm-fmt-select" id="msLayer"' + dis + '>'
            + '<option value="">-</option>' + pageLayersList.map(l => '<option value="' + l.layer_key + '"' + (l.locked ? ' disabled' : '') + '>' + escapeHtml(l.name) + '</option>').join('') + '</select></div>'
            + (ed ? '<div class="nm-detail-hint">' + L('hint') + '</div>' : '')
            + '</div><div id="msStyle"></div>';
        const body = document.getElementById('spBody');
        body.innerHTML = html;
        body.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => {
            const a = b.dataset.act;
            if (a.indexOf('align-') === 0) alignSelection(a.slice(6));
            else if (a === 'dist-h') distributeSelection('h');
            else if (a === 'dist-v') distributeSelection('v');
            else if (a === 'group') groupSelection();
            else if (a === 'ungroup') ungroupSelection();
            else if (a === 'copy') copySelection();
            else if (a === 'delete') deleteSelection();
        }));
        const lay = document.getElementById('msLayer');
        if (lay) lay.addEventListener('change', () => { if (lay.value !== '') moveSelectionToLayer(+lay.value); });
        // One style editor for all of them: it starts from the first shape's look (or a node's).
        const firstShape = items.find(x => x.kind === 's');
        const shown = firstShape ? window.nmShapeStyle(firstShape.it.shape_type, firstShape.it.style) : Object.assign({}, NODE_STYLE_SHOWN, items[0].it.style || {});
        renderStyleEditor(document.getElementById('msStyle'), shown, {
            rounded: true, valign: true, editable: ed,
            onChange: patch => {
                if (!canEdit()) return;
                multiItems().forEach(x => {
                    if (x.kind === 's') x.it.style = Object.assign({}, window.nmShapeStyle(x.it.shape_type, x.it.style), patch);
                    else x.it.style = Object.assign({}, x.it.style || {}, patch);
                });
                renderNodes();
                renderShapes();
                applyMultiClasses();
                markDirty();
            },
            onRebuild: () => renderMultiPanel(),
            onReset: () => {
                multiItems().forEach(x => { if (x.kind === 's') x.it.style = window.nmShapeStyle(x.it.shape_type, {}); else x.it.style = null; });
                renderNodes(); renderShapes(); applyMultiClasses(); markDirty(); renderMultiPanel();
            }
        });
    }

    // =========================================================
    //  Right-click menus (GH #433)
    //  One menu, built from what was right-clicked: a shape, a node, a
    //  connector, the selection, or empty canvas. A read-only version only
    //  offers what changes nothing.
    // =========================================================
    let styleClipboard = null;   // { style } from Copy style

    function closeCtxMenu() { document.querySelectorAll('.nm-ctx-menu').forEach(m => m.remove()); }

    /**
     * Shows a menu at (x, y) in the window. items: {label, act(), disabled, sub: [...], hint} or 'sep'.
     * A sub-menu opens to the side on hover (or on click, for touch).
     */
    function showCtxMenu(x, y, items, parentEl) {
        if (!parentEl) closeCtxMenu();
        const m = document.createElement('div');
        m.className = 'nm-ctx-menu';
        m.setAttribute('role', 'menu');
        items.forEach(it => {
            if (it === 'sep') { const hr = document.createElement('div'); hr.className = 'nm-ctx-sep'; m.appendChild(hr); return; }
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.className = 'nm-ctx-item' + (it.sub ? ' has-sub' : '');
            b.disabled = !!it.disabled;
            b.innerHTML = '<span>' + escapeHtml(it.label) + '</span>' + (it.hint ? '<span class="nm-ctx-hint">' + escapeHtml(it.hint) + '</span>' : '') + (it.sub ? '<span class="nm-ctx-arrow">&#9656;</span>' : '');
            if (it.sub) {
                const open = () => {
                    document.querySelectorAll('.nm-ctx-menu.sub').forEach(s => s.remove());
                    m.querySelectorAll('.nm-ctx-item.open').forEach(o => o.classList.remove('open'));
                    b.classList.add('open');
                    const r = b.getBoundingClientRect();
                    const sm = showCtxMenu(r.right - 2, r.top - 4, it.sub, m);
                    sm.classList.add('sub');
                    sm.dataset.parent = String(items.indexOf(it));
                };
                b.addEventListener('mouseenter', () => { if (!b.disabled) open(); });
                b.addEventListener('click', e => { e.stopPropagation(); if (!b.disabled) open(); });
            } else {
                if (!parentEl) b.addEventListener('mouseenter', () => {
                    document.querySelectorAll('.nm-ctx-menu.sub').forEach(s => s.remove());
                    m.querySelectorAll('.nm-ctx-item.open').forEach(o => o.classList.remove('open'));
                });
                b.addEventListener('click', () => { closeCtxMenu(); if (it.act) it.act(); });
            }
            m.appendChild(b);
        });
        document.body.appendChild(m);
        // Keep it on screen.
        const r = m.getBoundingClientRect();
        let left = x, top = y;
        if (left + r.width > window.innerWidth - 6) left = parentEl ? (parentEl.getBoundingClientRect().left - r.width + 2) : (window.innerWidth - r.width - 6);
        if (top + r.height > window.innerHeight - 6) top = Math.max(6, window.innerHeight - r.height - 6);
        m.style.left = left + 'px';
        m.style.top = top + 'px';
        return m;
    }

    /** The item (if any) a contextmenu / mouse event is over. */
    function ctxTarget(e) {
        const t = e.target;
        const sEl = t.closest && t.closest('.nm-shape');
        if (sEl) { const s = findShapeByKey(sEl.dataset.key); if (s) return { kind: 's', it: s }; }
        const nEl = t.closest && t.closest('.nm-node');
        if (nEl) { const n = findNodeByKey(nEl.dataset.key); if (n) return { kind: 'n', it: n }; }
        const g = t.closest && t.closest('.nm-connector-group');
        if (g) { const c = findConnectorByKey(g.dataset.connKey); if (c) return { kind: 'c', it: c }; }
        return null;
    }

    function onCanvasContextMenu(e) {
        if (isPresenting()) return;
        e.preventDefault();
        const ed = canEdit();
        const tg = ctxTarget(e);
        const at = canvasModelCoords(e);
        const M = k => t('network-mapper.menu.' + k);
        const layerSub = apply => pageLayers(currentPage).slice().reverse().map(l => ({ label: l.name, disabled: !ed || l.locked, act: () => apply(l.layer_key) }));
        let items;

        if (tg && tg.kind !== 'c') {
            const key = tg.kind === 'n' ? mkN(tg.it) : mkS(tg.it);
            if (!multi.has(key)) {
                // Right-click selects what is under it - its whole group, as a left click would.
                const g = groupOf(tg.kind, tg.it);
                if (g.length > 1) setSelection(g); else setSelection([tg]);
            }
        } else if (tg && tg.kind === 'c') {
            selectConnector(connectorKey(tg.it));
        }

        if (multi.size > 1) {
            const sel = multiItems();
            const grouped = sel.some(x => x.it.group_key);
            items = [
                { label: M('cut'), hint: 'Ctrl+X', disabled: !ed, act: () => { copySelection(); deleteSelection(); } },
                { label: M('copy'), hint: 'Ctrl+C', act: copySelection },
                { label: M('delete'), hint: 'Del', disabled: !ed, act: deleteSelection },
                'sep',
                { label: t('network-mapper.select.group'), hint: 'Ctrl+G', disabled: !ed, act: groupSelection },
                { label: t('network-mapper.select.ungroup'), hint: 'Ctrl+Shift+G', disabled: !ed || !grouped, act: ungroupSelection },
                { label: t('network-mapper.select.align'), disabled: !ed, sub: ['left', 'centre', 'right', 'top', 'middle', 'bottom'].map(a => ({ label: t('network-mapper.select.align_' + a), act: () => alignSelection(a) })) },
                { label: t('network-mapper.select.distribute'), disabled: !ed || sel.length < 3, sub: [
                    { label: t('network-mapper.select.dist_h'), act: () => distributeSelection('h') },
                    { label: t('network-mapper.select.dist_v'), act: () => distributeSelection('v') }] },
                { label: M('move_to_layer'), disabled: !ed, sub: layerSub(moveSelectionToLayer) },
                'sep',
                { label: M('paste_style'), disabled: !ed || !styleClipboard, act: () => pasteStyleTo(sel) }
            ];
        } else if (tg && tg.kind === 's') {
            const s = tg.it;
            items = [
                { label: M('edit_text'), disabled: !ed, act: () => startShapeTextEdit(s) },
                { label: M('open_article'), disabled: !s.article_id || kbTitle(s.article_id) === null, act: () => openArticle(s.article_id) },
                { label: M('link_article'), disabled: !ed || !canReadKb, act: () => openArticlePicker(a => setItemArticle(s, a)) },
                'sep',
                { label: M('cut'), hint: 'Ctrl+X', disabled: !ed, act: () => { copySelection(); deleteSelectedShape(); } },
                { label: M('copy'), hint: 'Ctrl+C', act: copySelection },
                { label: M('paste'), hint: 'Ctrl+V', disabled: !ed || !clipboard, act: () => pasteClipboard(at) },
                { label: t('network-mapper.shapes.duplicate'), disabled: !ed, act: duplicateSelectedShape },
                { label: M('delete'), hint: 'Del', disabled: !ed, act: deleteSelectedShape },
                'sep',
                { label: M('bring_front'), disabled: !ed, act: () => arrangeShape('front') },
                { label: M('send_back'), disabled: !ed, act: () => arrangeShape('back') },
                { label: s.layer === 'front' ? t('network-mapper.shapes.layer_back') : t('network-mapper.shapes.layer_front'), disabled: !ed,
                  act: () => arrangeShape(s.layer === 'front' ? 'layer_back' : 'layer_front') },
                { label: M('move_to_layer'), disabled: !ed, sub: layerSub(lk => { s.layer_key = lk; markDirty(); renderShapes(); renderLayersPanel(); }) },
                'sep',
                { label: M('copy_style'), act: () => { styleClipboard = { style: window.nmShapeStyle(s.shape_type, s.style) }; } },
                { label: M('paste_style'), disabled: !ed || !styleClipboard, act: () => pasteStyleTo([tg]) },
                { label: M('reset_style'), disabled: !ed, act: () => { s.style = window.nmShapeStyle(s.shape_type, {}); rerenderShape(s); markDirty(); renderShapePanel(); } }
            ];
        } else if (tg && tg.kind === 'n') {
            const n = tg.it;
            items = [
                { label: M('open_cmdb'), disabled: !window.NM_CMDB, act: () => window.open('../cmdb/object.php?id=' + n.cmdb_object_id, '_blank') },
                { label: t('network-mapper.detail.add_related'), disabled: !ed || !window.NM_CMDB, act: openRelatedModal },
                { label: M('change_icon'), disabled: !ed, act: openIconPicker },
                { label: M('open_article'), disabled: !n.article_id || kbTitle(n.article_id) === null, act: () => openArticle(n.article_id) },
                { label: M('link_article'), disabled: !ed || !canReadKb, act: () => openArticlePicker(a => setItemArticle(n, a)) },
                'sep',
                { label: M('cut'), hint: 'Ctrl+X', disabled: !ed, act: () => { copySelection(); deleteSelectedNode(); } },
                { label: M('copy'), hint: 'Ctrl+C', act: copySelection },
                { label: M('delete'), hint: 'Del', disabled: !ed, act: deleteSelectedNode },
                'sep',
                { label: M('move_to_layer'), disabled: !ed, sub: layerSub(lk => { n.layer_key = lk; markDirty(); renderNodes(); renderLayersPanel(); }) },
                { label: M('copy_style'), act: () => { styleClipboard = { style: Object.assign({}, n.style || {}) }; } },
                { label: M('paste_style'), disabled: !ed || !styleClipboard, act: () => pasteStyleTo([tg]) },
                { label: M('reset_style'), disabled: !ed, act: () => { n.style = null; rerenderNode(n); renderConnectors(); markDirty(); renderNodeStyleEditor(n); } }
            ];
        } else if (tg && tg.kind === 'c') {
            const c = tg.it;
            const route = r => () => { c.style = Object.assign({}, c.style || {}, { routing: r }); renderConnectors(); markDirty(); renderConnectorPanel(); };
            items = [
                { label: M('edit_label'), disabled: !ed, act: () => promptConnectorLabel(c) },
                { label: t('network-mapper.connector.swap'), disabled: !ed, act: () => {
                    ['node_id', 'shape_uid'].forEach(k => { const x = c['from_' + k]; c['from_' + k] = c['to_' + k]; c['to_' + k] = x; });
                    renderConnectors(); markDirty(); } },
                { label: t('network-mapper.connector.routing'), disabled: !ed, sub: ['straight', 'elbow', 'curved'].map(r => ({ label: t('network-mapper.connector.' + r), act: route(r) })) },
                'sep',
                { label: M('delete'), hint: 'Del', disabled: !ed, act: () => { deleteSelectedConnector(); closeShapePanel(); } }
            ];
        } else {
            clearSelection();
            items = [
                { label: M('paste_here'), hint: 'Ctrl+V', disabled: !ed || !clipboard, act: () => pasteClipboard(at) },
                { label: M('select_all'), hint: 'Ctrl+A', act: selectAllOnPage },
                'sep',
                { label: M('fit'), act: () => zoomFit() },
                { label: M('centre'), disabled: !ed, act: centreOnPage }
            ];
        }
        showCtxMenu(e.clientX, e.clientY, items);
    }

    /** Applies the copied style to shapes and nodes (each keeps what does not apply to it). */
    function pasteStyleTo(list) {
        if (!styleClipboard || !canEdit()) return;
        list.forEach(x => {
            if (x.kind === 's') x.it.style = Object.assign({}, window.nmShapeStyle(x.it.shape_type, x.it.style), styleClipboard.style);
            else if (x.kind === 'n') x.it.style = Object.assign({}, x.it.style || {}, styleClipboard.style);
        });
        renderShapes();
        renderNodes();
        applyMultiClasses();
        markDirty();
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

    /** Restarts the autosave debounce timer; when it fires it saves, or reschedules if a node drag is in progress. */
    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            if (!autosaveOn || !dirty || saveInFlight) return;
            // Don't save during an active node drag — save() reloads the diagram,
            // which would destroy the in-progress drag DOM and snap the node
            // back to its last-committed position. Reschedule and re-check.
            if (nodeDrag || nodeResize || shapeDrag || shapeResize || shapeEditKey) {   // GH #433
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

    /** Loads the user's autosave preference (off when it cannot be read) and applies it. */
    async function loadAutosavePreference() {
        try {
            const r = await fetch(SYSTEM_API + 'get_user_preference.php?key=' + encodeURIComponent(AUTOSAVE_PREF_KEY), { credentials: 'same-origin' });
            const d = await r.json();
            applyAutosaveState(d.success && d.value === '1', false);
        } catch (e) {
            applyAutosaveState(false, false);
        }
    }

    /**
     * Sets autosave on or off, updates the toggle and the save status, and optionally stores the choice as
     * a user preference.
     */
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

    /** Handles the autosave toggle - stores the new setting and schedules a save when there are unsaved changes. */
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

    /** Closes the icon picker modal and clears its state. */
    function closeIconPicker() {
        elIconPickerModal.classList.remove('active');
        iconPickerNode = null;
        iconPickerFilter = '';
    }

    /** Filters the icon picker by the search text. */
    function onIconSearchInput(value) {
        iconPickerFilter = (value || '').toLowerCase().trim();
        renderIconPicker();
    }

    /**
     * Renders the icon picker grid by category, filtered by the search text, with the node's current icon
     * highlighted.
     */
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

    /**
     * Sets the picked icon as the node's override (null when it matches the class default), refreshes the
     * node and detail panel and marks the diagram dirty.
     */
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

    /** Clears the selected node's icon override so it uses the class icon again. */
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

    /** Hides the versions dropdown. */
    function closeVersionsDropdown() {
        if (elVersionsDropdown) elVersionsDropdown.style.display = 'none';
    }

    /** Fetches the diagram's version chain and renders it in the versions dropdown. */
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

    /** Renders the version chain newest first as links, each with a viewing, current or read-only pill. */
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

    /** Hides the page-size dropdown. */
    function closePageDropdown() {
        if (elPageDropdown) elPageDropdown.style.display = 'none';
    }

    /**
     * Renders the page-size dropdown - Off plus each paper size in landscape and portrait - and wires the
     * rows to apply the choice.
     */
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

    /**
     * Sets the diagram's paper size and orientation, refreshes the button label, page outline and
     * branding, and marks the diagram dirty.
     */
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

    /** Shows the chosen paper size and orientation (or Off) on the page toolbar button. */
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

    /** Returns the pixel width and height of a paper size in the given orientation, or null for an unknown size. */
    function pageDimensionsPx(size, orient) {
        const def = PAPER_SIZES_PX[size];
        if (!def) return null;
        return (orient === 'portrait')
            ? { w: def.w, h: def.h }
            : { w: def.h, h: def.w };
    }

    /** Draws the shaded, dashed page outline and its size label in the SVG layer behind the connectors. */
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

    /** Returns the values for the branding tokens - title, author, version, modified date and logo URL. */
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

    /**
     * Rebuilds the branded header and footer strips on the page outline; shown only when a paper size is
     * set and a strip has any text.
     */
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

    /**
     * Opens the branding modal with the diagram's overrides as values and the organisation defaults as
     * placeholders.
     */
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

    /** Closes the branding modal. */
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
                        icon_override: n.icon_override || null,
                        colour_override: n.colour_override || null,   // GH #433
                        w: n.w || null,
                        h: n.h || null,
                        style: n.style || null,                       // GH #433
                        page_key: itemPage(n), layer_key: itemLayer(n), group_key: n.group_key || null,
                        article_id: n.article_id || null                // GH #433
                    })),
                    pages: pages.map(p => ({ page_key: p.page_key, name: p.name, sort: p.sort })),
                    layers: layers.map(l => ({ page_key: l.page_key, layer_key: l.layer_key, name: l.name, sort: l.sort, visible: l.visible !== false, locked: !!l.locked })),
                    // GH #433: the freestanding shapes, in z order
                    shapes: shapes.map(sh => ({
                        shape_type: sh.shape_type, x: sh.x, y: sh.y, w: sh.w, h: sh.h,
                        rotation: sh.rotation || 0, text: sh.text || '', style: sh.style || null,
                        layer: sh.layer || 'back', z: sh.z || 0, icon_id: sh.icon_id || null, uid: sh.uid || null,
                        page_key: itemPage(sh), layer_key: itemLayer(sh), group_key: sh.group_key || null,
                        article_id: sh.article_id || null
                    })),
                    connectors: connectors.map(c => ({
                        from_node_id: c.from_shape_uid ? null : c.from_node_id,
                        to_node_id: c.to_shape_uid ? null : c.to_node_id,
                        from_shape_uid: c.from_shape_uid || null,   // GH #433: an end on a shape
                        to_shape_uid: c.to_shape_uid || null,
                        cmdb_relationship_id: c.cmdb_relationship_id || null,
                        label: c.label || null,
                        line_style: c.line_style || 'solid',
                        style: c.style || null
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
            // GH #433: shapes have no natural id across the reload - keep the selection by position
            // (the array is kept in z order and the server returns ORDER BY z, id).
            const selShapeIdx = selectedShapeKey != null ? shapes.indexOf(findShapeByKey(selectedShapeKey)) : -1;
            const resp2 = await fetch(API + 'get_diagram.php?id=' + diagramId, { credentials: 'same-origin' });
            const data2 = await resp2.json();
            if (data2.success) {
                diagram = data2.diagram;
                nodes = data2.nodes || [];
                connectors = data2.connectors || [];
                shapes = data2.shapes || [];
                pages = data2.pages || [];
                layers = data2.layers || [];
                ensurePagesLayers();
                if (multi.size) { multi = new Set(); closeShapePanel(); }   // temp keys are gone
                selectedShapeKey = selShapeIdx >= 0 && shapes[selShapeIdx] ? shapeKey(shapes[selShapeIdx]) : null;
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
                renderShapes();
                renderPageTabs();
                renderLayersPanel();
                if (selectedShapeKey == null) closeShapePanel();
                updatePageButtonLabel();
                renderPageOutline();
                renderBrandHeaderFooter();
                if (selectedCmdbId) {
                    const reselect = pageNodes().find(n => n.cmdb_object_id === selectedCmdbId);
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
    /** GH #433: opens the details modal to change the current version's title and description. */
    function openDetailsModal() {
        if (!diagram || !diagram.is_current) return;
        document.getElementById('dtTitle').value = diagram.title || '';
        document.getElementById('dtDescription').value = diagram.description || '';
        document.getElementById('detailsModal').classList.add('active');
        setTimeout(() => document.getElementById('dtTitle').focus(), 50);
    }

    /** Closes the details modal. */
    function closeDetailsModal() {
        const m = document.getElementById('detailsModal');
        if (m) m.classList.remove('active');
    }

    /**
     * Saves the title and description straight away through save_diagram.php. Only those two fields
     * are sent, so unsaved canvas edits are left alone for the next Save.
     */
    async function saveDetails() {
        if (!diagram || !diagram.is_current) return;
        const title = document.getElementById('dtTitle').value.trim();
        const description = document.getElementById('dtDescription').value.trim();
        if (!title) {
            if (window.showToast) showToast(t('network-mapper.newversion.title_required'), 'error');
            return;
        }
        const btn = document.getElementById('dtSaveBtn');
        btn.disabled = true;
        try {
            const resp = await fetch(API + 'save_diagram.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: diagramId, title: title, description: description })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Save failed');
            diagram.title = title;
            diagram.description = description || null;
            renderHeader();
            closeDetailsModal();
            if (window.showToast) showToast(t('network-mapper.details.saved'), 'success');
        } catch (e) {
            if (window.showToast) showToast(t('network-mapper.toast.save_failed', { message: e.message }), 'error');
        } finally {
            btn.disabled = false;
        }
    }

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

    /** Closes the new version modal. */
    function closeNewVersionModal() {
        document.getElementById('newVersionModal').classList.remove('active');
    }

    /** Suggests the next version label by bumping a trailing number, or appending " (new)" when there is none. */
    function suggestNextVersionLabel(current) {
        if (!current) return 'v2';
        // Try to bump a trailing integer ("v3" -> "v4", "Draft 2" -> "Draft 3")
        const m = String(current).match(/^(.*?)(\d+)\s*$/);
        if (m) return m[1] + (parseInt(m[2], 10) + 1);
        return current + ' (new)';
    }

    /** Creates a new version of the diagram from the modal fields and opens it. */
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

    /** Sets the zoom to the nearest preset level and applies it. */
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

    /** Zooms in one preset level. */
    function zoomIn()    { setZoom(zoomNext(+1)); }
    /** Zooms out one preset level. */
    function zoomOut()   { setZoom(zoomNext(-1)); }
    /** Resets the zoom to 100%. */
    function zoomReset() { setZoom(1); }

    /** Returns the preset zoom level one step in the given direction from the current zoom. */
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

    /**
     * Zooms to fit the page outline, or the placed nodes when no paper size is set, into the canvas and
     * scrolls to the top left.
     */
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
        if ((!contentW || !contentH) && (visibleNodes().length || visibleShapes().length)) {
            // Bounding box of placed nodes, each by its own box (GH #433) - this page, visible layers.
            let maxX = 0, maxY = 0;
            visibleNodes().forEach(n => {
                const ext = nodeExtent(n);
                if (n.x + ext.w > maxX) maxX = n.x + ext.w;
                if (n.y + ext.h > maxY) maxY = n.y + ext.h;
            });
            visibleShapes().forEach(sh => {   // GH #433
                if (sh.x + sh.w > maxX) maxX = sh.x + sh.w;
                if (sh.y + sh.h > maxY) maxY = sh.y + sh.h;
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

    /**
     * Enters Present mode - hides the editor chrome and fits the diagram to the screen, remembering the
     * zoom and scroll to restore later.
     */
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

    /** Leaves Present mode and restores the zoom and scroll position from before. */
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

    /** Returns true while Present mode is on. */
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
        if (!visibleNodes().length && !visibleShapes().length) {
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
        let minX = Infinity, minY = Infinity, maxX = 0, maxY = 0;
        visibleNodes().forEach(n => {
            const ext = nodeExtent(n);   // GH #433: each node's own box, label included
            if (n.x < minX) minX = n.x;
            if (n.y < minY) minY = n.y;
            if (n.x + ext.w > maxX) maxX = n.x + ext.w;
            if (n.y + ext.h > maxY) maxY = n.y + ext.h;
        });
        visibleShapes().forEach(sh => {   // GH #433: shapes are part of the diagram too
            if (sh.x < minX) minX = sh.x;
            if (sh.y < minY) minY = sh.y;
            if (sh.x + sh.w > maxX) maxX = sh.x + sh.w;
            if (sh.y + sh.h > maxY) maxY = sh.y + sh.h;
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

        nodes.filter(n => itemPage(n) === currentPage).forEach(n => {   // GH #433: this page
            n.x = Math.max(0, n.x + deltaX);
            n.y = Math.max(0, n.y + deltaY);
        });
        shapes.filter(sh => itemPage(sh) === currentPage).forEach(sh => { sh.x += deltaX; sh.y += deltaY; });
        renderNodes();   // re-renders nodes + calls renderConnectors() inside
        renderShapes();
        markDirty();
        if (window.showToast) showToast(t('network-mapper.toast.centred'), 'success');
    }

    // =========================================================
    //  PNG / PDF export
    //  Snapshot the canvas via html2canvas, optionally wrap into a paper-
    //  sized PDF via the shared PDF writer. Driven entirely from the live DOM: temporarily
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

    /**
     * Returns the area to export - the page outline when a paper size is set, otherwise the nodes'
     * bounding box with padding.
     */
    function computeExportRect() {
        if (!diagram) return null;
        if (diagram.paper_size) {
            const dims = pageDimensionsPx(diagram.paper_size, diagram.paper_orientation);
            if (dims) return { x: 0, y: 0, width: dims.w, height: dims.h };
        }
        if (!visibleNodes().length && !visibleShapes().length) return null;
        // Bounding box of nodes — approximate using medium icon size, +40px
        // for the label below the icon so it isn't clipped. Pad each side
        // by 40px so the export doesn't feel cramped at the edges.
        let minX = Infinity, minY = Infinity, maxX = 0, maxY = 0;
        visibleNodes().forEach(n => {
            const ext = nodeExtent(n);   // GH #433: each node's own box, label included
            if (n.x < minX) minX = n.x;
            if (n.y < minY) minY = n.y;
            if (n.x + ext.w > maxX) maxX = n.x + ext.w;
            if (n.y + ext.h > maxY) maxY = n.y + ext.h;
        });
        visibleShapes().forEach(sh => {   // GH #433: shapes are part of the diagram too
            if (sh.x < minX) minX = sh.x;
            if (sh.y < minY) minY = sh.y;
            if (sh.x + sh.w > maxX) maxX = sh.x + sh.w;
            if (sh.y + sh.h > maxY) maxY = sh.y + sh.h;
        });
        const PAD = 40;
        const x = Math.max(0, minX - PAD);
        const y = Math.max(0, minY - PAD);
        return { x, y, width: (maxX + PAD) - x, height: (maxY + PAD) - y };
    }

    /**
     * Captures the export area with html2canvas at 100% zoom with selection and handles hidden, then
     * restores the view.
     */
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
            selectedShape: selectedShapeKey,   // GH #433
        };
        if (selectedShapeKey != null) { selectedShapeKey = null; renderShapes(); }

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
            if (stashed.selectedShape != null) { selectedShapeKey = stashed.selectedShape; renderShapes(); }
            if (stashed.selectedConnector != null) {
                selectedConnectorKey = stashed.selectedConnector;
                renderConnectors();
            }
        }
    }

    /** Exports the diagram as a PNG download. */
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

    /** Exports the diagram as a PDF sized to its paper choice (A4 portrait when none is set). */
    async function exportPdf() {
        // The shared PDF writer (assets/js/pdf-writer.js - GH #254).
        if (!window.FitsmPdf) {
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
        const doc = await FitsmPdf.create({
            orientation: orient === 'landscape' ? 'l' : 'p',
            unit: 'pt',
            // The PDF writer takes paper sizes lowercased; our stored values are
            // capitalised so .toLowerCase() bridges the convention gap.
            format: paperSize.toLowerCase(),
        });
        const pageW = doc.internal.pageSize.getWidth();
        const pageH = doc.internal.pageSize.getHeight();
        doc.addImage(result.canvas.toDataURL('image/png'), 'PNG', 0, 0, pageW, pageH);
        await doc.save(exportFilename('pdf'));
        if (window.showToast) showToast(t('network-mapper.toast.pdf_exported'), 'success');
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function formatDate(s) {
        if (!s) return '—';
        // KIND 1: server-stamped UTC (created/updated_datetime) — convert to the
        // analyst's display zone via the shared tz helpers.
        try { return fmtDateTime(s); }
        catch (e) { return s; }
    }

    /** Escapes a value for safe use in HTML. */
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    /** Escapes a value for use in an HTML attribute. */
    function escapeAttr(s) { return escapeHtml(s).replace(/'/g, "\\'"); }

    // =========================================================
    //  Public surface
    // =========================================================
    window.NM = {
        toggleArticlesPanel, closeArticlePicker, onArticleSearchInput,   // GH #433: Knowledge articles
        init,
        save: () => save(false),
        toggleAutosave,
        openNewVersionModal,
        closeNewVersionModal,
        // title + description (GH #433)
        openDetailsModal,
        closeDetailsModal,
        saveDetails,
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
        // node box size (GH #433)
        resetBoxSize,
        // shapes (GH #433)
        setPaletteTab,
        onShapeSearchInput,
        openLibraryBrowser,
        toggleLayersPanel,
        closeLibraryBrowser,
        onLibSearchInput,
        setLibFilter,
        closeShapePanel: () => selectShape(null),
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
