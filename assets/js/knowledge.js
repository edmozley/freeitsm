/**
 * Knowledge Base JavaScript
 */

// API base path - can be overridden by page before loading this script
const API_BASE = window.API_BASE || 'api/';

let articles = [];
// Whether the first fetch has come back. An empty `articles` means both
// "nothing here" and "not asked yet", and only the first should ever draw an
// empty state — see renderArticleList().
let kbArticlesLoaded = false;
let tags = [];
let selectedTags = [];
let currentArticle = null;
let articleEditor = null;
let searchTimeout = null;
let activeTagFilters = [];
let isRecycleBinView = false;

// Folders. `activeFolder` is '' for every article, 'root' for the ones filed
// nowhere, or a folder id — three distinct states, which is why it is not a
// nullable number: "no filter" and "the folder that is not a row" are different
// questions and an empty value cannot mean both.
let kbFolders = [];
let activeFolder = '';
// Whether this analyst may EDIT access, which is a stricter question than
// whether they may read a folder: anyone who could merely read one must not be
// able to hand it to somebody else.
let kbCanManagePerms = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadTags();
    loadFolders();
    loadBrowseModePreference();
    loadLayoutPreference();
    loadArticles();
    loadAnalysts();
    loadCompanies();
    const audienceSel = document.getElementById('articleAudience');
    if (audienceSel) {
        audienceSel.addEventListener('change', updateAudienceHint);
        updateAudienceHint();
    }
    initTinyMCE();
    initTagInput();
    loadSidebarModePreference();

    // Auto-open AI chat if redirected from Settings/Review
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('askai') === '1') {
        // Strip only askai, not the whole querystring: `?article=N&askai=1` is a
        // legitimate combination, and blanking the search threw the article away
        // before the deep-link handler below ever looked at it.
        const url = new URL(window.location.href);
        url.searchParams.delete('askai');
        const qs = url.searchParams.toString();
        history.replaceState(null, '', url.pathname + (qs ? '?' + qs : ''));
        openAiChat();
    }
});

// ---------------------------------------------------------------------------
//  Visibility: which company owns an article, and who may read it
// ---------------------------------------------------------------------------

let kbCompanies = [];

/**
 * Companies this analyst can file an article against. Same source and
 * hide-unless-more-than-one idiom as the tickets/changes "Move to company"
 * pickers — on a single-company install the control never appears.
 */
async function loadCompanies() {
    const group = document.getElementById('articleCompanyGroup');
    try {
        const res = await fetch('../api/system/get_tenants.php?accessible=1');
        const data = await res.json();
        kbCompanies = (data.success && data.companies) ? data.companies : [];
    } catch (e) {
        kbCompanies = [];
    }
    if (!group) return;

    // One company (or none) => nothing to choose. Stay invisible at N=1.
    if (kbCompanies.length < 2) {
        group.style.display = 'none';
        return;
    }
    const select = document.getElementById('articleCompany');
    select.innerHTML = '<option value="">' + escapeHtml(window.t('knowledge.editor.company_shared')) + '</option>';
    kbCompanies.forEach(c => {
        const o = document.createElement('option');
        o.value = c.id;
        o.textContent = c.name;
        select.appendChild(o);
    });
    group.style.display = '';
}

/**
 * The editor shows the three stored audiences as TWO choices plus an opt-in
 * tickbox. This pair converts between the two shapes; nothing else in the
 * product knows the difference, because the stored value is unchanged.
 *
 *   internal  -> 'Analysts only',                 unticked
 *   customer  -> 'Analysts and signed-in...',     unticked
 *   public    -> 'Analysts and signed-in...',     TICKED
 */
function audienceFromControls() {
    const sel = document.getElementById('articleAudience');
    const pub = document.getElementById('articleAudiencePublic');
    const base = sel ? sel.value : 'internal';
    // ⚠️ Ticked only counts when the dropdown is already at 'customer'. The
    // stored values are a LADDER, and its guarantee is that a contradiction
    // cannot be expressed — "on the internet but not visible to analysts" must
    // stay unsayable. The tickbox is also disabled below, so this is the second
    // of two locks rather than the only one.
    return (base === 'customer' && pub && pub.checked) ? 'public' : base;
}

function audienceToControls(stored) {
    const sel = document.getElementById('articleAudience');
    const pub = document.getElementById('articleAudiencePublic');
    const val = stored || 'internal';
    if (sel) sel.value = (val === 'public') ? 'customer' : val;
    if (pub) pub.checked = (val === 'public');
    updateAudienceHint();
}

/** Spell out what the chosen audience actually means, in the editor. */
function updateAudienceHint() {
    const sel  = document.getElementById('articleAudience');
    const hint = document.getElementById('audienceHint');
    const pub  = document.getElementById('articleAudiencePublic');
    if (!sel || !hint) return;

    // 'Analysts only' cannot also be on the internet. Rather than let someone
    // tick it and silently ignore them, the box is disabled and cleared — a
    // control that accepts input and discards it is worse than one that says no.
    if (pub) {
        const allowed = sel.value === 'customer';
        pub.disabled = !allowed;
        if (!allowed) pub.checked = false;
        const wrap = pub.closest('.kb-audience-public');
        if (wrap) wrap.classList.toggle('is-disabled', !allowed);
        const channels = document.getElementById('audiencePublicChannels');
        if (channels) channels.style.display = pub.checked ? '' : 'none';
    }

    hint.textContent = window.t('knowledge.editor.audience_hint_' + audienceFromControls());
}

/** Put the two visibility controls back to the safe default (a new article). */
function resetVisibilityFields() {
    audienceToControls('internal');
    const co = document.getElementById('articleCompany');
    if (co) co.value = '';
    // A new article is created in whatever folder you are looking at — that is
    // what "New article" means while a folder is selected. 'root' and '' both
    // mean the top level here.
    const fo = document.getElementById('articleFolder');
    if (fo) fo.value = (activeFolder && activeFolder !== 'root') ? activeFolder : '';
}

/** The visibility half of a save payload. */
function visibilityPayload() {
    const co  = document.getElementById('articleCompany');
    const fo  = document.getElementById("articleFolder");
    const out = { audience: audienceFromControls() };
    // Sent only when the picker exists, so an install whose page predates it
    // cannot post folder_id: null on every save and quietly unfile everything.
    if (fo) out.folder_id = fo.value === "" ? null : parseInt(fo.value, 10);
    // Only send a company when the picker is actually in play; otherwise a
    // single-company install would post an empty string on every save.
    if (co && kbCompanies.length >= 2) {
        out.tenant_id = co.value || null;
    }
    return out;
}

// Load analysts for owner dropdown
async function loadAnalysts() {
    try {
        const response = await fetch(API_BASE + 'get_analysts.php');
        const data = await response.json();

        if (data.success) {
            const select = document.getElementById('articleOwner');
            if (select) {
                // Keep the first "no owner" option
                select.innerHTML = '<option value="">' + escapeHtml(window.t('knowledge.editor.owner_none')) + '</option>';
                data.analysts.forEach(analyst => {
                    const option = document.createElement('option');
                    option.value = analyst.id;
                    option.textContent = analyst.name;
                    select.appendChild(option);
                });
            }
        }
    } catch (error) {
        console.error('Error loading analysts:', error);
    }
}

// Initialize TinyMCE editor
function initTinyMCE() {
    // Match the editor chrome + content area to the active palette. TinyMCE ships
    // its own skins (the editor renders in an iframe), so we use the bundled
    // oxide-dark UI skin + dark content CSS rather than CSS overrides. Switching
    // palette reloads the page, so this runs fresh with the right data-theme.
    // TinyMCE ships only a light + a dark skin, so we pick by the palette's
    // declared mode (data-theme-mode on <html>) — any new palette works with no
    // change here. Same approach as the tickets reply editor (inbox.js).
    const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';

    tinymce.init({
        selector: '#articleBody',
        license_key: 'gpl',
        height: 400,
        menubar: true,
        skin: isDark ? 'oxide-dark' : 'oxide',
        content_css: isDark ? 'dark' : 'default',
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount', 'codesample'
        ],
        toolbar: 'undo redo | blocks | ' +
            'bold italic forecolor backcolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'link image table | codesample code | removeformat | help',
        codesample_languages: [
            { text: 'PowerShell', value: 'powershell' },
            { text: 'Bash/Shell', value: 'bash' },
            { text: 'Command Prompt', value: 'batch' },
            { text: 'JavaScript', value: 'javascript' },
            { text: 'HTML/XML', value: 'markup' },
            { text: 'CSS', value: 'css' },
            { text: 'SQL', value: 'sql' },
            { text: 'Python', value: 'python' },
            { text: 'C#', value: 'csharp' },
            { text: 'JSON', value: 'json' },
            { text: 'Plain Text', value: 'plaintext' }
        ],
        // The `pointer: coarse` block is the one thing mobile.css cannot do
        // from outside: TinyMCE renders into an IFRAME, so no rule in our
        // stylesheet reaches this text. It has to be 16px on a touch device —
        // iOS zooms in on focus for anything smaller, which on a full-screen
        // mobile editor spills the layout wide and makes Safari reflow the
        // whole page to desktop width, switching every mobile rule off. Keyed
        // on the pointer rather than a width so a desktop browser resized
        // narrow is unaffected. Same single justified edit inbox.js took (#766).
        content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; line-height: 1.6; }' +
                       ' @media (pointer: coarse) { body { font-size: 16px; } }',
        setup: function(editor) {
            articleEditor = editor;
        }
    });
}

// Initialize tag input functionality
function initTagInput() {
    const tagInput = document.getElementById('tagInput');

    tagInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(this.value.trim());
            this.value = '';
            hideSuggestions();
        } else if (e.key === 'Backspace' && this.value === '' && selectedTags.length > 0) {
            removeTag(selectedTags[selectedTags.length - 1]);
        }
    });

    tagInput.addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length > 0) {
            showTagSuggestions(query);
        } else {
            hideSuggestions();
        }
    });

    tagInput.addEventListener('blur', function() {
        setTimeout(hideSuggestions, 200);
    });
}

// Load all tags
// Per-analyst sidebar visibility preference — 'always' (default) keeps the
// 280px sidebar pinned open; 'hover' collapses it to a thin 16px hot-zone
// that expands when the cursor approaches. CSS does the actual sliding via
// the .sidebar-hover class on .knowledge-container. Pattern mirrors the
// Process Mapper module (#324). Set under Knowledge → Settings → Left panel.
const KB_SIDEBAR_MODE_KEY = 'knowledge_sidebar_mode';

/**
 * WHERE you browse folders. Per analyst, like the sidebar mode above.
 *
 *   'panel'    (default) the tree lives in the left panel and the main pane
 *              shows a flat list at "All articles" — the behaviour that has
 *              always existed.
 *   'explorer' the tree section is taken OUT of the left panel and the main
 *              pane does the browsing: top-level folders at "All articles",
 *              then in and out with the breadcrumb.
 *
 * A preference rather than a toggle in the toolbar, matching how the left panel
 * itself is configured — and because it is a working style, not something you
 * flip twice an hour. Ed's reason for wanting it is the one that decides the
 * default: the left panel already carries search, folders, tags, three buttons
 * and the bin, and folders are the newest and largest addition to it. Moving
 * them out is a choice, not an improvement everyone wants, so 'panel' stays the
 * default and nobody's screen changes without them asking.
 */
const KB_BROWSE_MODE_KEY = 'knowledge_browse_mode';
let kbBrowseMode = 'panel';

/**
 * HOW the main pane draws things: 'list' (default) | 'cards' | 'tree'.
 *
 * I argued against offering three layouts, on the grounds that "details" and
 * "tree" already existed as the list and the left panel. Ed asked for them in
 * the MAIN PANE twice, having used the thing, and that settles it — the
 * argument was about duplication in a 280px strip, and the main pane is where
 * the room is. Recorded because the reasoning against is still on the wiki and
 * a later reader deserves to know it was overtaken rather than forgotten.
 *
 *   list   full-width rows with a preview. What has always existed.
 *   cards  a grid, matching the System landing page — many titles at a glance,
 *          which is what you want when you know roughly what you are after.
 *   tree   folders expanded in place with their articles inside, so the whole
 *          shape is visible at once rather than one level at a time.
 *
 * A toggle in the header rather than only a setting: unlike where you browse,
 * this is something people genuinely flip during a session. It still persists,
 * so it is not forgotten between visits.
 */
const KB_LAYOUT_KEY = 'knowledge_main_layout';
let kbLayout = 'list';

async function loadBrowseModePreference() {
    try {
        const r = await fetch('../api/system/get_user_preference.php?key=' + encodeURIComponent(KB_BROWSE_MODE_KEY), { credentials: 'same-origin' });
        const d = await r.json();
        kbBrowseMode = (d.success && d.value === 'explorer') ? 'explorer' : 'panel';
    } catch (e) {
        kbBrowseMode = 'panel';
    }
    applyBrowseMode();
}

async function loadLayoutPreference() {
    try {
        const r = await fetch('../api/system/get_user_preference.php?key=' + encodeURIComponent(KB_LAYOUT_KEY), { credentials: 'same-origin' });
        const d = await r.json();
        kbLayout = (d.success && ['list', 'cards', 'tree', 'details'].includes(d.value)) ? d.value : 'list';
    } catch (e) {
        kbLayout = 'list';
    }
    // ⚠️ The first fetch may already have gone out while this was still in
    // flight, and it would have asked for the top level only — which the tree
    // cannot draw from. Re-fetch once, and only for the tree, where the whole
    // shape is the point.
    if (kbLayout === 'tree') {
        const box = document.getElementById('articleSearch');
        loadArticles(box ? box.value : '', activeTagFilters).then(() => applyLayout(false));
        return;
    }
    applyLayout(false);
}

function applyLayout(save) {
    document.querySelectorAll('.kb-layout-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.layout === kbLayout);
    });
    if (typeof articles !== 'undefined') renderArticleList();
    if (save) {
        fetch('../api/system/set_user_preference.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: KB_LAYOUT_KEY, value: kbLayout })
        }).catch(() => { /* the layout still changed; only the memory of it failed */ });
    }
}

function setLayout(mode) {
    if (!['list', 'cards', 'tree', 'details'].includes(mode)) return;
    kbLayout = mode;
    // The tree draws the WHOLE shape, so it needs every folder's articles rather
    // than the one folder currently selected. Reload unfiltered when switching
    // into it, or it would show a tree containing one branch.
    if (mode === 'tree' || mode === 'details') {
        activeFolder = '';
        renderBreadcrumb();
        loadArticles(document.getElementById('articleSearch').value, activeTagFilters).then(() => applyLayout(true));
        return;
    }
    applyLayout(true);
}

/**
 * The Details table — Explorer's, with columns you can sort.
 *
 * Folders come FIRST and stay first whatever the sort, exactly as Explorer does:
 * they are containers rather than a kind of file, and mixing them into a sort by
 * date would scatter the way into the tree through the middle of the list.
 *
 * Sorting is done here rather than by re-querying: the rows are already loaded
 * and already permission-filtered, so a round trip would add latency and a
 * second place for the rules to be got wrong.
 */
let kbDetailsSort = { col: 'title', dir: 1 };

function kbSortDetails(col) {
    kbDetailsSort = (kbDetailsSort.col === col)
        ? { col: col, dir: -kbDetailsSort.dir }
        : { col: col, dir: 1 };
    renderArticleList();
}

function renderDetailsLayout() {
    const folders = (activeFolder === 'root') ? []
        : kbFolders.filter(f => activeFolder === ''
            ? f.parent_id === null
            : String(f.parent_id) === String(activeFolder));

    const dir = kbDetailsSort.dir;
    const val = (a, col) => {
        if (col === 'modified') return a.modified_datetime || '';
        if (col === 'author')   return (a.author_name || '').toLowerCase();
        if (col === 'tags')     return (a.tags || []).length;
        return (a.title || '').toLowerCase();
    };
    const rows = articles.slice().sort((x, y) => {
        const a = val(x, kbDetailsSort.col), b = val(y, kbDetailsSort.col);
        return (a < b ? -1 : a > b ? 1 : 0) * dir;
    });

    const arrow = c => kbDetailsSort.col === c ? (dir === 1 ? ' ▲' : ' ▼') : '';
    const head = `
        <div class="kb-details-row kb-details-head">
            <span></span>
            <span class="kb-details-name" onclick="kbSortDetails('title')">${escapeHtml(window.t('knowledge.details.name'))}${arrow('title')}</span>
            <span class="kb-details-author" onclick="kbSortDetails('author')">${escapeHtml(window.t('knowledge.details.author'))}${arrow('author')}</span>
            <span class="kb-details-date" onclick="kbSortDetails('modified')">${escapeHtml(window.t('knowledge.details.modified'))}${arrow('modified')}</span>
            <span class="kb-details-tags" onclick="kbSortDetails('tags')">${escapeHtml(window.t('knowledge.details.tags'))}${arrow('tags')}</span>
        </div>`;

    const folderRows = folders.map(f => `
        <div class="kb-details-row kb-details-folder" onclick="selectFolder('${f.id}')"
             draggable="true" ondragstart="kbDragStart(event, 'folder', ${f.id})" ondragend="kbDragEnd()"
             ondragover="kbDragOver(event)" ondragleave="kbDragLeave(event)" ondrop="kbDrop(event, ${f.id})"
             data-folder="${f.id}"
             oncontextmenu="kbContextMenu(event, 'folder', ${f.id}, ${jsAttr(f.name)})">
            <span></span>
            <span class="kb-details-name">📁 ${escapeHtml(f.name)}${f.is_restricted ? ' 🔒' : ''}</span>
            <span class="kb-details-author">—</span>
            <span class="kb-details-date">—</span>
            <span class="kb-details-tags">${f.article_count}</span>
        </div>`).join('');

    const articleRows = rows.map(a => `
        <div class="kb-details-row" onclick="kbRowClick(event, ${a.id})"
             data-article="${a.id}"
             draggable="true" ondragstart="kbDragStart(event, 'article', ${a.id})" ondragend="kbDragEnd()"
             oncontextmenu="kbContextMenu(event, 'article', ${a.id}, ${jsAttr(a.title)})">
            ${kbSelectBox(a.id)}
            <span class="kb-details-name">📄 ${kbRowIsShortcut(a.id) ? '↗ ' : ''}${Number(a.inherit_permissions) === 0 ? '🔒 ' : ''}${escapeHtml(a.title)}</span>
            <span class="kb-details-author">${escapeHtml(a.author_name || '')}</span>
            <span class="kb-details-date">${formatDate(a.modified_datetime)}</span>
            <span class="kb-details-tags">${(a.tags || []).map(t => `<span class="article-tag">${escapeHtml(t.name)}</span>`).join('')}</span>
        </div>`).join('');

    if (!folderRows && !articleRows) {
        return `<div class="no-results">${escapeHtml(window.t(
            activeFolder === '' ? 'knowledge.list.no_articles' : 'knowledge.folders.empty'))}</div>`;
    }
    return head + folderRows + articleRows;
}

/**
 * The whole shape at once: every folder, expanded, with its articles inside.
 *
 * Built from what is already loaded rather than from a new endpoint — the tree
 * comes from loadFolders() and the articles from the unfiltered list, both of
 * which are already filtered to what this analyst may see. A second endpoint
 * would be a second place for the permission rules to be got wrong.
 */
function renderTreeLayout() {
    // ⚠️ ALPHABETICAL, and by NAME rather than by whatever order the list
    // happened to arrive in. The articles come back newest-first, which is right
    // for a list you are scanning for recent work and wrong for a tree, where
    // you are looking for a particular thing by its name.
    //
    // localeCompare with numeric:true so "Step 2" sorts before "Step 10" rather
    // than after it — the ordering people actually expect of names with numbers.
    const byName = (a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });

    const byFolder = {};
    for (const a of articles) {
        const k = a.folder_id === null ? 'root' : String(a.folder_id);
        (byFolder[k] = byFolder[k] || []).push(a);
    }
    for (const k of Object.keys(byFolder)) {
        byFolder[k].sort((x, y) => byName(x.title, y.title));
    }

    const article = (a, depth) => `
        <div class="kb-tree-article" style="padding-left:${10 + depth * 18}px" onclick="kbRowClick(event, ${a.id})"
             data-article="${a.id}"
             draggable="true" ondragstart="kbDragStart(event, 'article', ${a.id})" ondragend="kbDragEnd()"
             oncontextmenu="kbContextMenu(event, 'article', ${a.id}, ${jsAttr(a.title)})">
            ${kbSelectBox(a.id)}
            <span class="kb-tree-icon">📄</span>
            <span class="kb-tree-label">${Number(a.inherit_permissions) === 0 ? '🔒 ' : ''}${escapeHtml(a.title)}</span>
        </div>`;

    let html = '';
    const walk = (parent, depth) => {
        for (const f of kbFolders.filter(x => x.parent_id === parent).sort((a, b) => byName(a.name, b.name))) {
            html += `
                <div class="kb-tree-folder${String(activeFolder) === String(f.id) ? ' active' : ''}" style="padding-left:${10 + depth * 18}px"
                     draggable="true" ondragstart="kbDragStart(event, 'folder', ${f.id})" ondragend="kbDragEnd()"
                     ondragover="kbDragOver(event)" ondragleave="kbDragLeave(event)" ondrop="kbDrop(event, ${f.id})"
                     data-folder="${f.id}" onclick="selectFolder('${f.id}')"
                     oncontextmenu="kbContextMenu(event, 'folder', ${f.id}, ${jsAttr(f.name)})">
                    <span class="kb-tree-icon">📁</span>
                    <span class="kb-tree-label">${escapeHtml(f.name)}${f.is_restricted ? ' 🔒' : ''}</span>
                    <span class="kb-folder-count">${f.article_count}</span>
                </div>`;
            // ⚠️ SUBFOLDERS BEFORE DOCUMENTS. This was the other way round, so a
            // folder's articles came first and its subfolders appeared below
            // them — which puts the way FURTHER IN underneath the contents of
            // where you already are, and is not what any file browser does.
            walk(f.id, depth + 1);
            for (const a of (byFolder[String(f.id)] || [])) html += article(a, depth + 1);
        }
    };
    walk(null, 0);

    // Articles filed nowhere belong at the bottom, not silently omitted — the
    // tree is meant to show everything, and "everything except the unfiled ones"
    // is exactly the sort of quiet gap that makes people distrust a view.
    for (const a of (byFolder['root'] || [])) html += article(a, 0);

    return html || `<div class="no-results">${escapeHtml(window.t('knowledge.list.no_articles'))}</div>`;
}

function applyBrowseMode() {
    // Hiding the tree section is the whole point on the explorer setting: the
    // complaint was a crowded panel, so leaving the tree there and ALSO putting
    // folders in the main pane would make it worse, not better.
    const sec = document.getElementById('kbFolderSection');
    if (sec) sec.style.display = (kbBrowseMode === 'explorer') ? 'none' : '';
    if (typeof articles !== 'undefined') renderArticleList();
}
async function loadSidebarModePreference() {
    try {
        const r = await fetch('../api/system/get_user_preference.php?key=' + encodeURIComponent(KB_SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && d.value === 'hover') ? 'hover' : 'always';
        applySidebarMode(mode);
    } catch (e) {
        applySidebarMode('always');
    }
}
function applySidebarMode(mode) {
    const container = document.querySelector('.knowledge-container');
    if (!container) return;
    container.classList.toggle('sidebar-hover', mode === 'hover');
}

// ---------------------------------------------------------------------------
//  Asking for a name
//
//  The browser's prompt() is unstyled, says "freeitsm.internal says", cannot be
//  translated, and blocks the whole tab. Returning a Promise keeps every call
//  site reading exactly as it did with prompt() — `const name = await kbPrompt(…)`
//  — so replacing it changed the dialog and nothing else.
// ---------------------------------------------------------------------------

let kbPromptResolve = null;

/**
 * The app's shared confirmation dialog, with a safety net.
 *
 * showConfirm() lives in assets/js/confirm.js and is auto-loaded by the waffle
 * menu, so it is normally there. Falling back to the browser's confirm() rather
 * than assuming: if the shared one is ever missing, the choice is between an
 * ugly dialog and NO CONFIRMATION AT ALL on a destructive action — and a
 * `showConfirm(...)` that throws would take the whole handler with it, deleting
 * nothing and reporting nothing.
 */
async function kbConfirm(opts) {
    if (typeof window.showConfirm === 'function') {
        return await window.showConfirm(opts);
    }
    return confirm(opts.message || '');
}

function kbPrompt(title, value) {
    const modal = document.getElementById('kbPromptModal');
    // No dialog on the page (an older cached template): fall back rather than
    // silently doing nothing, because "nothing happens when I click New folder"
    // is a much worse failure than an ugly box.
    if (!modal) return Promise.resolve(prompt(title, value || ''));

    document.getElementById('kbPromptTitle').textContent = title;
    const input = document.getElementById('kbPromptInput');
    input.value = value || '';
    modal.classList.add('active');
    // Focus and select, so typing replaces a suggested name the way it does in
    // every rename box people already use.
    setTimeout(() => { input.focus(); input.select(); }, 30);

    return new Promise(resolve => {
        kbPromptResolve = resolve;
        input.onkeydown = (e) => {
            if (e.key === 'Enter') { e.preventDefault(); kbPromptAccept(); }
            if (e.key === 'Escape') { e.preventDefault(); kbPromptCancel(); }
        };
    });
}

function kbPromptAccept() {
    const v = document.getElementById('kbPromptInput').value;
    kbPromptClose();
    if (kbPromptResolve) { const r = kbPromptResolve; kbPromptResolve = null; r(v); }
}

/** Cancel resolves with null — the same thing prompt() returns, so callers
    that check for null keep working unchanged. */
function kbPromptCancel() {
    kbPromptClose();
    if (kbPromptResolve) { const r = kbPromptResolve; kbPromptResolve = null; r(null); }
}

function kbPromptClose() {
    const modal = document.getElementById('kbPromptModal');
    if (modal) modal.classList.remove('active');
}

// ---------------------------------------------------------------------------
//  Folders
//
//  The tree arrives ALREADY FILTERED — a folder you may not read is not sent at
//  all, rather than sent with a flag for this code to respect. Filtering here
//  would mean the names had already crossed the wire, and a folder name is
//  exactly the sort of thing worth restricting.
// ---------------------------------------------------------------------------

async function loadFolders() {
    const box = document.getElementById('kbFolderTree');
    if (!box) return;
    try {
        const r = await fetch(API_BASE + 'folders.php?action=list');
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'failed');
        kbFolders = d.folders || [];
        kbCanManagePerms = !!d.can_manage;
        // ⚠️ REPAINT THE MAIN PANE TOO. The folders and the articles are fetched
        // in parallel, and both the tree layout and the folder rows are drawn
        // from kbFolders — so whichever request finishes second has to redraw,
        // or the pane keeps whatever it rendered while the folders were still in
        // flight. On a fast local install the articles usually lose that race
        // and it looks fine; on a slow one you get a tree with no folders in it.
        if (typeof articles !== 'undefined' && articles.length >= 0) renderArticleList();
        // The exceptions report is an administrator's tool, so it appears only for
        // someone who can act on what it shows.
        const exSec = document.getElementById('kbExceptionsSection');
        if (exSec) exSec.style.display = kbCanManagePerms ? '' : 'none';
        renderFolderTree(d.root_count || 0);
        renderFolderPicker();
    } catch (e) {
        // Say so rather than rendering an empty tree: "no folders" and "the
        // folders did not load" look identical, and one of them is a lie that
        // makes a document look deleted.
        box.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.folders.load_failed')) + '</div>';
    }
}

/** Children of `parent`, in the order the server sent them (by name). */
function folderChildren(parent) {
    return kbFolders.filter(f => f.parent_id === parent);
}

function renderFolderTree(rootCount) {
    const box = document.getElementById('kbFolderTree');
    if (!box) return;

    // "All articles" is the no-filter state, not a folder — hence value ''. It
    // IS a drop target though: dropping here means "take it out of its folder",
    // which is the only way back to the top level without opening the editor.
    let html = `<div class="kb-folder${activeFolder === '' ? ' active' : ''}" data-folder=""
                     ondragover="kbDragOver(event)" ondragleave="kbDragLeave(event)" ondrop="kbDrop(event, null)">
                    <span class="kb-folder-name" onclick="selectFolder('')">${escapeHtml(window.t('knowledge.folders.root'))}</span>
                    <span class="kb-folder-count">${rootCount + kbFolders.reduce((n, f) => n + f.article_count, 0)}</span>
                 </div>`;

    const walk = (parent, depth) => {
        for (const f of folderChildren(parent)) {
            // 🔒 marks a folder with its own rules. The badge is the whole point
            // of §9's "visible and rare": an exception you cannot see from the
            // tree is one nobody can audit.
            const restricted = f.is_restricted ? ' 🔒' : '';
            const manage = kbCanManagePerms
                ? `<button type="button" class="kb-folder-perm" title="${escapeHtml(window.t('knowledge.perm.manage'))}"
                           onclick="openPermModal('folder', ${f.id}, ${jsAttr(f.name)})">🔑</button>`
                : '';
            html += `<div class="kb-folder${activeFolder === String(f.id) ? ' active' : ''}" data-folder="${f.id}" style="padding-left:${8 + depth * 14}px"
                          title="${f.is_restricted ? escapeHtml(window.t('knowledge.folders.restricted')) : ''}"
                          draggable="true"
                          ondragstart="kbDragStart(event, 'folder', ${f.id})"
                          ondragend="kbDragEnd()"
                          ondragover="kbDragOver(event)" ondragleave="kbDragLeave(event)" ondrop="kbDrop(event, ${f.id})"
                          oncontextmenu="kbContextMenu(event, 'folder', ${f.id}, ${jsAttr(f.name)})">
                        <span class="kb-folder-name" onclick="selectFolder('${f.id}')">${escapeHtml(f.name)}${restricted}</span>
                        ${manage}
                        <span class="kb-folder-count">${f.article_count}</span>
                     </div>`;
            walk(f.id, depth + 1);
        }
    };
    walk(null, 0);

    box.innerHTML = html;
}

/** Fill the editor's folder picker, indented so the shape is readable. */
function renderFolderPicker() {
    const sel = document.getElementById('articleFolder');
    if (!sel) return;
    const keep = sel.value;
    sel.innerHTML = '';
    const root = document.createElement('option');
    root.value = '';
    root.textContent = window.t('knowledge.folders.root');
    sel.appendChild(root);

    const walk = (parent, depth) => {
        for (const f of folderChildren(parent)) {
            const o = document.createElement('option');
            o.value = String(f.id);
            o.textContent = ' '.repeat(depth * 3) + f.name;
            sel.appendChild(o);
            walk(f.id, depth + 1);
        }
    };
    walk(null, 0);
    sel.value = keep;
}

/**
 * Up one level.
 *
 * ⚠️ From a TOP-LEVEL folder this goes to "All articles", not to nothing — the
 * root is a real destination even though it is not a row in the table. Without
 * that, the button would be dead exactly where people expect it to take them
 * out of the tree.
 */
function goUpOneLevel() {
    if (activeFolder === '' || activeFolder === 'root') return;
    const path = folderPath(activeFolder);
    const parent = path.length >= 2 ? path[path.length - 2].id : '';
    selectFolder(String(parent));
}

function selectFolder(value) {
    activeFolder = String(value);
    // ⚠️ The URL syncs from showView(), and changing folder does not change
    // VIEW — so without this the address bar keeps naming whichever folder you
    // arrived on and every link copied afterwards is wrong.
    syncArticleUrl('list');
    renderFolderTree(0);   // repaint the selection; counts come back on reload
    loadFolders();
    loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
}

async function createFolderPrompt() {
    const name = await kbPrompt(window.t('knowledge.folders.new_prompt'));
    if (name === null || name.trim() === '') return;
    // Create inside whatever is selected, which is what "new folder" means when
    // you are looking at one. '' (All articles) means the top level.
    const parent = (activeFolder && activeFolder !== 'root') ? activeFolder : null;
    await folderAction({ action: 'create', name: name.trim(), parent_id: parent }, 'knowledge.folders.created');
}

async function renameFolderPrompt(id, current) {
    const name = await kbPrompt(window.t('knowledge.folders.rename_prompt'), current);
    if (name === null || name.trim() === '') return;
    await folderAction({ action: 'rename', id: id, name: name.trim() }, 'knowledge.folders.renamed');
}

async function deleteFolderPrompt(id, name) {
    if (!await kbConfirm({
        title: window.t('knowledge.folders.delete'),
        message: window.t('knowledge.folders.delete_confirm', { name: name }),
        okLabel: window.t('knowledge.folders.delete'),
        okClass: 'danger'
    })) return;
    await folderAction({ action: 'delete', id: id }, 'knowledge.folders.deleted');
}

async function folderAction(payload, okKey) {
    try {
        const r = await fetch(API_BASE + 'folders.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (!d.success) { showToast(d.error || window.t('knowledge.folders.load_failed'), 'error'); return; }
        showToast(window.t(okKey), 'success');
        await loadFolders();
        loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
    } catch (e) {
        showToast(window.t('knowledge.folders.load_failed'), 'error');
    }
}

// ---------------------------------------------------------------------------
//  Who can see this — the access list for one folder or one article
//
//  The list means the OPPOSITE thing in each mode: on an Open object it is who
//  is excluded, on a Restricted one it is who is admitted. So the heading, the
//  empty state and the confirmation all change with the tickbox. A fixed
//  heading would be wrong half the time, and wrong about a permission.
// ---------------------------------------------------------------------------

let permTarget = null;   // { type: 'folder'|'article', id, name }
let permState  = null;   // the last server answer
let permSearchTimer = null;

// ---------------------------------------------------------------------------
//  Drag and drop, and the right-click menu
//
//  ⚠️ DESKTOP ONLY, and that is a decision rather than an omission. A drag needs
//  a pointer that can hover, and a right-click needs a second button; a phone
//  has neither. Everything reachable here is ALSO reachable another way — the
//  folder picker in the editor files an article, the key opens permissions — so
//  a phone loses convenience, never capability.
// ---------------------------------------------------------------------------

let kbDrag = null;   // { type: 'article'|'folder', id }

function kbDragStart(e, type, id) {
    kbDrag = { type: type, id: id };
    // Fade the row being dragged. Half of "where will this land?" is knowing
    // what is in flight - especially in cards view, where the pointer is often
    // nowhere near the card it picked up.
    if (e.currentTarget && e.currentTarget.classList) e.currentTarget.classList.add('kb-dragging');
    // Some browsers refuse to start a drag with no payload, even when the
    // handler carries the state itself.
    try { e.dataTransfer.setData('text/plain', type + ':' + id); } catch (_) {}
    e.dataTransfer.effectAllowed = 'move';
    e.stopPropagation();
}

function kbDragEnd() {
    kbDrag = null;
    document.querySelectorAll('.kb-dragging').forEach(el => el.classList.remove('kb-dragging'));
    // Every kind of drop target, not just the tree in the panel — the folder
    // CARDS and the tree ROWS in the main pane are targets too, and a highlight
    // left behind after an abandoned drag looks like the row is selected.
    document.querySelectorAll('.drop-target')
            .forEach(el => el.classList.remove('drop-target'));
}

function kbDragOver(e) {
    if (!kbDrag) return;
    // A folder cannot be dropped on itself. Refusing here rather than letting
    // the drop land and the server say no means the row never lights up as a
    // target, so the answer is visible before the mouse is released.
    if (kbDrag.type === 'folder' && String(kbDrag.id) === e.currentTarget.dataset.folder) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('drop-target');
}

function kbDragLeave(e) {
    e.currentTarget.classList.remove('drop-target');
}

async function kbDrop(e, folderId) {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.classList.remove('drop-target');
    if (!kbDrag) return;
    const drag = kbDrag;
    kbDrag = null;

    if (drag.type === 'article') {
        // Ctrl-drag makes a SHORTCUT instead of moving — the same modifier
        // Explorer uses for "copy here rather than move here", so the muscle
        // memory already exists. A shortcut needs a destination folder, so
        // dropping on "All articles" (null) is always a move.
        if (e.ctrlKey && folderId !== null) {
            await folderAction({ action: 'add_shortcut', article_id: drag.id, folder_id: folderId }, 'knowledge.folders.shortcut_added');
        } else {
            await folderAction({ action: 'move_article', article_id: drag.id, folder_id: folderId }, 'knowledge.folders.moved_article');
        }
    } else {
        if (String(drag.id) === String(folderId)) return;
        // The server refuses a cycle; this only avoids the pointless round trip.
        await folderAction({ action: 'move', id: drag.id, parent_id: folderId }, 'knowledge.folders.moved');
    }
}

/**
 * The right-click menu.
 *
 * One menu element, moved and refilled, rather than one per row: a tree of 200
 * folders would otherwise carry 200 hidden menus, and only one can ever be open.
 */
function kbContextMenu(e, type, id, name) {
    e.preventDefault();
    e.stopPropagation();
    closeContextMenu();

    // ⚠️ EACH ITEM IS A LABEL AND A FUNCTION, never a string of code.
    //
    // These used to be JavaScript source built by interpolation and dropped into
    // an onclick attribute. That is the same defect that stopped the menu
    // OPENING (a name containing a quote closed the attribute), and I fixed the
    // opening while leaving the items alone — reasoning that wrapping the whole
    // call in escapeHtml made it safe. It does not: escapeHtml here is
    // `textContent -> innerHTML`, which escapes < > and & but deliberately NOT
    // quotes. So the menu opened and every item on it did nothing.
    //
    // Escaping harder would have worked and been wrong. A closure carries the
    // name as a VALUE; there is no text for a quote to escape from, and no
    // amount of punctuation in a folder name can ever break it again.
    const items = [];
    if (type === 'folder') {
        items.push([window.t('knowledge.folders.new'),    () => createFolderIn(id)]);
        items.push([window.t('knowledge.folders.rename'), () => renameFolderPrompt(id, name)]);
        if (kbCanManagePerms) items.push([window.t('knowledge.perm.manage'), () => openPermModal('folder', id, name)]);
        if (kbCanManagePerms) items.push([window.t('knowledge.audit.button'),  () => openAuditModal('folder', id, name)]);
        items.push([window.t('knowledge.folders.delete'), () => deleteFolderPrompt(id, name)]);
    } else {
        items.push([window.t('knowledge.folders.rename'), () => renameArticlePrompt(id, name)]);
        items.push([window.t('knowledge.detail.edit'),    () => Promise.resolve(viewArticle(id)).then(editCurrentArticle)]);
        if (kbCanManagePerms) items.push([window.t('knowledge.perm.manage'), () => openPermModal('article', id, name)]);
        if (kbCanManagePerms) items.push([window.t('knowledge.audit.button'),  () => openAuditModal('article', id, name)]);
        // A row showing in a folder it does not LIVE in is being shown by a
        // shortcut, which is computable from what we already have — the article
        // carries its real folder_id. Removing the shortcut must never be
        // offered as "delete": it takes away a pointer and leaves the document
        // exactly where it lives.
        if (kbRowIsShortcut(id)) {
            items.push([window.t('knowledge.folders.shortcut_remove'),
                () => folderAction({ action: 'remove_shortcut', article_id: id, folder_id: activeFolder }, 'knowledge.folders.shortcut_removed')]);
        } else {
            items.push([window.t('knowledge.folders.move_to_root'),
                () => folderAction({ action: 'move_article', article_id: id, folder_id: null }, 'knowledge.folders.moved_article')]);
        }
    }

    const menu = document.createElement('div');
    menu.className = 'kb-context-menu';
    menu.id = 'kbContextMenu';
    for (const [label, fn] of items) {
        const row = document.createElement('div');
        row.className = 'kb-context-item';
        row.textContent = label;
        row.addEventListener('click', (ev) => {
            ev.stopPropagation();
            closeContextMenu();
            fn();
        });
        menu.appendChild(row);
    }
    document.body.appendChild(menu);

    // Keep it on screen: a menu opened near the right or bottom edge would
    // otherwise hang off the page with its last item unreachable.
    const r = menu.getBoundingClientRect();
    const x = Math.min(e.clientX, window.innerWidth  - r.width  - 8);
    const y = Math.min(e.clientY, window.innerHeight - r.height - 8);
    menu.style.left = Math.max(4, x) + 'px';
    menu.style.top  = Math.max(4, y) + 'px';

    setTimeout(() => document.addEventListener('click', closeContextMenu, { once: true }), 0);
}

function closeContextMenu() {
    const m = document.getElementById('kbContextMenu');
    if (m) m.remove();
}

/**
 * Rename an article without opening the editor.
 *
 * Sends ONLY the id and the title. KnowledgeService updates the fields it is
 * given and leaves the rest alone, so this cannot touch the body — which
 * matters, because loading a body just to send it straight back is how a rename
 * quietly overwrites an edit somebody else has open. Same reasoning as
 * move_article being its own action rather than a save.
 */
async function renameArticlePrompt(id, current) {
    const title = await kbPrompt(window.t('knowledge.folders.rename_article_prompt'), current);
    if (title === null || title.trim() === '') return;
    try {
        const r = await fetch(API_BASE + 'knowledge_save.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, title: title.trim() })
        });
        const d = await r.json();
        if (!d.success) { showToast(d.error || window.t('knowledge.toast.save_failed'), 'error'); return; }
        showToast(window.t('knowledge.folders.renamed_article'), 'success');
        loadFolders();
        loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
    } catch (e) {
        showToast(window.t('knowledge.toast.save_failed'), 'error');
    }
}

/** "New folder" from a folder's own menu means a subfolder of THAT folder. */
async function createFolderIn(parentId) {
    const name = await kbPrompt(window.t('knowledge.folders.new_prompt'));
    if (name === null || name.trim() === '') return;
    await folderAction({ action: 'create', name: name.trim(), parent_id: parentId }, 'knowledge.folders.created');
}

// ---------------------------------------------------------------------------
//  Going INTO a folder from the main pane
//
//  Deliberately NOT a view-mode switcher. Of the three modes such a thing
//  usually offers, two already exist — "details" is this list, "tree" is the
//  left panel — so a switcher would mostly let you choose between what you have
//  and the same thing again. The only genuinely missing piece was seeing folders
//  where you are actually working, so the main pane simply shows where you are:
//  a breadcrumb, then the subfolders, then the articles. Nothing to choose,
//  nothing to remember, and one renderer rather than three.
//
//  At "All articles" there are no folder rows and the list is flat — exactly
//  what the module did before folders existed.
// ---------------------------------------------------------------------------

/**
 * Is the folder tree actually on screen?
 *
 * ⚠️ offsetWidth, NOT `display`. On a phone the panel is not display:none — it
 * is laid out and collapsed to ZERO WIDTH, so a display check returns "block"
 * and reads as visible. That exact mistake made the measuring harness report
 * "folder tree reachable on a phone: YES" about a tree nobody could see.
 */
function kbTreeIsVisible() {
    const el = document.querySelector('.knowledge-sidebar');
    return !!el && el.offsetWidth > 0;
}

/** The chain from the root down to the folder being viewed. */
function folderPath(id) {
    const out = [];
    let cursor = kbFolders.find(f => String(f.id) === String(id));
    const seen = {};
    while (cursor && !seen[cursor.id]) {   // a cycle would otherwise hang the page
        seen[cursor.id] = true;
        out.unshift(cursor);
        cursor = kbFolders.find(f => f.id === cursor.parent_id);
    }
    return out;
}

function renderBreadcrumb() {
    const bar = document.getElementById('kbBreadcrumb');
    if (!bar) return;
    if (activeFolder === '' || activeFolder === 'root') { bar.innerHTML = ''; bar.style.display = 'none'; return; }

    const parts = [`<a onclick="selectFolder('')">${escapeHtml(window.t('knowledge.folders.root'))}</a>`];
    for (const f of folderPath(activeFolder)) {
        parts.push(`<a onclick="selectFolder('${f.id}')">${escapeHtml(f.name)}</a>`);
    }
    bar.style.display = '';
    // An explicit "up one level" as well as the trail. The trail tells you where
    // you are and lets you jump anywhere along it; going up ONE step is the
    // commonest move of all and should not require reading the trail first to
    // work out which crumb is the parent — which is precisely why Explorer has
    // both.
    bar.innerHTML =
        `<button type="button" class="kb-crumb-up" onclick="goUpOneLevel()"
                 title="${escapeHtml(window.t('knowledge.folders.up'))}"
                 aria-label="${escapeHtml(window.t('knowledge.folders.up'))}">↑</button>`
        + parts.join('<span class="kb-crumb-sep">›</span>');
}

/**
 * Subfolders of wherever we are, as rows above the articles.
 *
 * Returns '' at "All articles" and at the unfiled view — neither is a place you
 * can be inside, so neither has children to show.
 */
function renderFolderRows() {
    if (activeFolder === 'root') return '';

    let kids;
    if (activeFolder === '') {
        // ⚠️ ON A PHONE THE TREE IS NOT ON SCREEN. mobile.css collapses the left
        // panel to nothing, so without this there is no way into a folder at all
        // — the breadcrumb and the folder rows only appear once you are already
        // inside one, and nothing could get you there.
        //
        // The test is whether the tree is ACTUALLY VISIBLE, not what the viewport
        // width is. A width guess duplicates the breakpoint in a second place and
        // then drifts from it; measuring the panel asks the real question, and
        // answers it correctly for a narrow window on a desktop too.
        // ⚠️ ALWAYS, in every layout — Ed's call, and it overrides an earlier
        // decision of mine that folders should only appear here when the tree
        // was absent. That was defensible as "do not change the view people
        // already have", but it made the main pane a dead end at the top level:
        // you could see folders once you were inside one, and had no way to get
        // inside one without the panel.
        //
        // Explorer shows folders in the tree AND in the list, and that is the
        // thing being imitated. The browse preference now decides only whether
        // the panel KEEPS its copy, not whether the main pane gets one.
        kids = kbFolders.filter(f => f.parent_id === null);
    } else {
        kids = kbFolders.filter(f => String(f.parent_id) === String(activeFolder));
    }
    if (!kids.length) return '';

    return kids.map(f => `
        <div class="article-card kb-folder-card" onclick="selectFolder('${f.id}')"
             draggable="true" ondragstart="kbDragStart(event, 'folder', ${f.id})" ondragend="kbDragEnd()"
             ondragover="kbDragOver(event)" ondragleave="kbDragLeave(event)" ondrop="kbDrop(event, ${f.id})"
             data-folder="${f.id}"
             oncontextmenu="kbContextMenu(event, 'folder', ${f.id}, ${jsAttr(f.name)})">
            <div class="article-card-title">📁 ${escapeHtml(f.name)}${f.is_restricted ? ' 🔒' : ''}</div>
            <div class="article-card-meta">
                <div class="article-card-info">
                    <span>${f.article_count} ${escapeHtml(window.t(
                        f.article_count === 1 ? 'knowledge.folders.item_one' : 'knowledge.folders.items'))}</span>
                </div>
            </div>
        </div>`).join('');
}

/**
 * Tags on a card, capped so every card is the same height.
 *
 * ⚠️ ONLY IN THE CARD LAYOUT. A grid is a grid because the cells line up; one
 * article with four tags and its neighbour with none makes a ragged wall with
 * gaps under the short ones, which is what a grid is FOR avoiding. The list and
 * the tree have a whole row each and no such problem, so they are left alone —
 * capping tags there would hide information for no gain.
 *
 * Two shown, then "+N more". Clicking it reveals the rest IN PLACE rather than
 * opening anything: you wanted to see the tags, not to be taken somewhere.
 */
const KB_CARD_TAG_LIMIT = 5;

function renderCardTags(article) {
    const tags = article.tags || [];
    const pill = t => `<span class="article-tag">${escapeHtml(t.name)}</span>`;
    // FIVE, not two. The first cut capped at two because the meta line was still
    // fighting the author and date for half a card; now each has the full width
    // there is room for a normal number of tags, and hiding what fits is just
    // hiding things. The pill is for the genuinely over-tagged article.
    if (kbLayout !== 'cards' || tags.length <= KB_CARD_TAG_LIMIT) {
        return tags.map(pill).join('');
    }
    const shown  = tags.slice(0, KB_CARD_TAG_LIMIT).map(pill).join('');
    const hidden = tags.length - KB_CARD_TAG_LIMIT;
    // ⚠️ A JSON ARRAY on the attribute, never a joined string. Joining names and
    // splitting them apart needs a separator no tag can contain, and there is no
    // such character — an earlier attempt joined on '' and split on '', which
    // does not round-trip: it would have exploded "Firewall" into eight
    // single-letter tags.
    //
    // ALL the tags go on the pill, not just the hidden ones: the dialog it opens
    // shows the article's whole set, which is the question being asked ("what is
    // this tagged with?") rather than "what did you crop?".
    return shown
        + `<span class="article-tag article-tag--more" onclick="event.stopPropagation(); kbShowAllTags(this)"
                 data-tags="${jsAttr(JSON.stringify(tags.map(t => t.name)))}"
                 data-title="${jsAttr(article.title)}">`
        + escapeHtml(window.t('knowledge.list.tags_more', { count: hidden }))
        + '</span>';
}

/**
 * Show every tag on the article in a small dialog.
 *
 * A dialog rather than expanding in place, at Ed's ask — and it is the better
 * answer: expanding reflows the card, which re-ragged the grid the cap exists to
 * keep even, and it left no way to put it back.
 */
function kbShowAllTags(el) {
    let names = [];
    try {
        // jsAttr JSON-encodes for the attribute, and what it encoded was itself
        // JSON — so this returns a JSON string containing JSON.
        names = JSON.parse(JSON.parse(el.dataset.tags || '"[]"'));
    } catch (e) { return; }
    if (!Array.isArray(names)) return;

    let title = '';
    try { title = JSON.parse(el.dataset.title || '""'); } catch (e) { title = ''; }

    document.getElementById('kbTagsModalTitle').textContent = title || window.t('knowledge.sidebar.tags_heading');
    document.getElementById('kbTagsModalList').innerHTML =
        names.map(n => `<span class="article-tag">${escapeHtml(n)}</span>`).join('');
    document.getElementById('kbTagsModal').classList.add('active');
}

function closeTagsModal() {
    document.getElementById('kbTagsModal').classList.remove('active');
}

/**
 * Is this row appearing here because of a shortcut rather than because it lives
 * here? No extra column needed: a row whose real folder_id differs from the
 * folder being viewed is, by definition, being shown by a pointer.
 */
/**
 * Which folder an article is in, shown on the card at "All articles".
 *
 * ⚠️ THIS IS WHY A MOVE LOOKED LIKE A COPY. "All articles" shows every article
 * whatever folder it is in — correctly — so after dragging one into a folder the
 * card is still sitting there, and the folder now shows it too. Two copies, as
 * far as anyone can see. The data was always right; nothing on screen said
 * where the article had gone.
 *
 * Naming the folder on the card fixes it at the source: drag it and the label
 * changes from "Unfiled" to "Service Desk", which is a move, visibly.
 *
 * Only at "All articles". Inside a folder every article is in that folder, so
 * the label would be the same on every row and would say nothing.
 */
function kbFolderLabel(article) {
    // Only worth saying when the list can hold articles from several places —
    // which, now that the top level shows only the top level, means while
    // SEARCHING or filtering by tag. Browsing a folder, every row is in that
    // folder and the label would say the same thing on every one.
    const searchBox = document.getElementById('articleSearch');
    const mixed = activeFolder === ''
        && (((searchBox && searchBox.value) || '') !== '' || activeTagFilters.length > 0);
    if (!mixed) return '';
    const f = kbFolders.find(x => String(x.id) === String(article.folder_id));
    const name = f ? f.name : window.t('knowledge.list.unfiled');
    return `<span class="kb-card-folder" title="${escapeHtml(window.t('knowledge.editor.field_folder'))}">📁 ${escapeHtml(name)}</span>`;
}

function kbRowIsShortcut(articleId) {
    if (activeFolder === '' || activeFolder === 'root') return false;
    const a = articles.find(x => x.id === articleId);
    return !!a && String(a.folder_id) !== String(activeFolder);
}

/**
 * Everything with its OWN permissions rather than its parent's.
 *
 * ⚠️ THIS SCREEN IS WHY PER-DOCUMENT PERMISSIONS ARE MANAGEABLE AT ALL. An
 * exception is invisible from the tree — you cannot look at a folder and know
 * what is true inside it — so without a list of them, the count only ever goes
 * up and nobody can audit it.
 */
async function openExceptionsModal() {
    const box = document.getElementById('kbExceptionsList');
    document.getElementById('kbExceptionsModal').classList.add('active');
    box.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    try {
        const r = await fetch(API_BASE + 'folders.php?action=exceptions');
        const d = await r.json();
        if (!d.success) { box.innerHTML = '<div class="no-results">' + escapeHtml(d.error) + '</div>'; return; }
        const rows = d.exceptions || [];
        if (!rows.length) {
            box.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.exceptions.none')) + '</div>';
            return;
        }
        box.innerHTML = rows.map(x => `
            <div class="kb-perm-entry">
                <span class="kb-perm-entry-name">${x.is_restricted ? '🔒 ' : ''}${escapeHtml(x.name)}</span>
                <span class="kb-perm-entry-kind">${escapeHtml(window.t('knowledge.exceptions.' + x.type))}
                    · ${x.entries} ${escapeHtml(window.t('knowledge.exceptions.listed'))}</span>
                <button type="button" class="btn btn-secondary btn-sm"
                        onclick="closeExceptionsModal(); openPermModal('${escapeHtml(x.type)}', ${x.id}, ${jsAttr(x.name)})">
                    ${escapeHtml(window.t('knowledge.perm.manage'))}</button>
            </div>`).join('');
    } catch (e) {
        box.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.perm.failed')) + '</div>';
    }
}

function closeExceptionsModal() {
    document.getElementById('kbExceptionsModal').classList.remove('active');
}

/** The article being read. Its own rules, not its folder's. */
function openArticlePermModal() {
    if (!currentArticle) return;
    openPermModal('article', currentArticle.id, currentArticle.title);
}

function openPermModal(type, id, name) {
    permTarget = { type: type, id: id, name: name || '' };
    document.getElementById('kbPermTitle').textContent = name
        ? window.t('knowledge.perm.title_folder', { name: name })
        : window.t('knowledge.perm.title');
    document.getElementById('kbPermSearch').value = '';
    document.getElementById('kbPermResults').innerHTML = '';
    document.getElementById('kbPermModal').classList.add('active');
    permLoad();
}

function closePermModal() {
    document.getElementById('kbPermModal').classList.remove('active');
    permTarget = null;
    // Permissions may have changed what is visible, so repaint rather than
    // leaving a tree that reflects the state before the edit.
    loadFolders();
    loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
}

async function permLoad() {
    if (!permTarget) return;
    const box = document.getElementById('kbPermList');
    box.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    try {
        const r = await fetch(`${API_BASE}permissions.php?action=get&object_type=${permTarget.type}&object_id=${permTarget.id}`);
        const d = await r.json();
        if (!d.success) {
            // Say WHY rather than showing an empty list: an empty list and "you
            // may not look" are the same picture and opposite meanings.
            box.innerHTML = '<div class="no-results">' + escapeHtml(d.error || window.t('knowledge.perm.failed')) + '</div>';
            document.getElementById('kbPermOwnRules').style.display = 'none';
            return;
        }
        permState = d;
        document.getElementById('kbPermOwnRules').style.display = d.inherits ? 'none' : '';
        document.getElementById('kbPermInherit').checked = !!d.inherits;
        document.getElementById('kbPermRestricted').checked = !!d.is_restricted;
        renderInherited(d);
        renderPermList();
    } catch (e) {
        box.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.perm.failed')) + '</div>';
    }
}

/**
 * What this inherits, shown READ-ONLY.
 *
 * ⚠️ Ticking "use the same permissions as the folder above" used to hide
 * everything, leaving a dialog headed "Who can see this" that answered nothing
 * — the single question it exists for. Inheriting is the ordinary case, so that
 * was the ordinary case showing the least.
 *
 * Read-only rather than editable, because these rules belong to the FOLDER
 * ABOVE: editing them here would change what other things see without saying
 * so. The folder is named, so the way to change them is obvious.
 */
function renderInherited(d) {
    const box = document.getElementById('kbPermInherited');
    if (!box) return;
    if (!d.inherits) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.style.display = '';

    // Nothing above restricts anything. Say so plainly: an empty list and "no
    // restrictions anywhere" look identical and mean opposite things.
    if (!d.inherited) {
        box.innerHTML = '<p class="field-hint">'
            + escapeHtml(window.t('knowledge.perm.inherit_none')) + '</p>';
        return;
    }

    const inh = d.inherited;
    const rows = (inh.entries || []).length
        ? inh.entries.map(e => `
            <div class="kb-perm-entry kb-perm-entry--readonly">
                <span class="kb-perm-entry-name">${escapeHtml(e.name)}</span>
                <span class="kb-perm-entry-kind">${escapeHtml(e.principal_type.replace('_', ' '))}</span>
            </div>`).join('')
        : `<div class="no-results">${escapeHtml(window.t(
              inh.is_restricted ? 'knowledge.perm.none_restricted' : 'knowledge.perm.none_open'))}</div>`;

    box.innerHTML =
        `<p class="field-hint">${escapeHtml(window.t('knowledge.perm.inherit_from', { folder: inh.folder_name }))}</p>`
      + `<p class="field-hint">${escapeHtml(window.t(
             inh.is_restricted ? 'knowledge.perm.explain_restricted' : 'knowledge.perm.explain_open'))}</p>`
      + `<div class="kb-perm-list">${rows}</div>`
      + `<p class="field-hint">${escapeHtml(window.t('knowledge.perm.inherit_readonly'))}</p>`;
}

function renderPermList() {
    const restricted = document.getElementById('kbPermRestricted').checked;
    document.getElementById('kbPermExplain').textContent =
        window.t(restricted ? 'knowledge.perm.explain_restricted' : 'knowledge.perm.explain_open');

    const box = document.getElementById('kbPermList');
    const entries = (permState && permState.entries) || [];
    if (!entries.length) {
        box.innerHTML = '<div class="no-results">'
            + escapeHtml(window.t(restricted ? 'knowledge.perm.none_restricted' : 'knowledge.perm.none_open'))
            + '</div>';
        return;
    }
    box.innerHTML = entries.map(e => `
        <div class="kb-perm-entry">
            <span class="kb-perm-entry-name">${escapeHtml(e.name)}</span>
            <span class="kb-perm-entry-kind">${escapeHtml(e.principal_type.replace('_', ' '))}</span>
            <button type="button" class="btn btn-secondary btn-sm" onclick="permRemove(${e.id}, ${jsAttr(e.name)})">${escapeHtml(window.t('knowledge.perm.remove'))}</button>
        </div>`).join('');
}

/**
 * Save the two tickboxes.
 *
 * $askIfWiping is true only for the polarity tickbox, because only that one
 * destroys the list. The count comes from what is already on screen, so the
 * question names a real number rather than warning vaguely.
 */
async function permSetMode(askIfWiping) {
    if (!permTarget) return;
    const inherits   = document.getElementById('kbPermInherit').checked;
    const restricted = document.getElementById('kbPermRestricted').checked;

    if (askIfWiping) {
        const n = ((permState && permState.entries) || []).length;
        // The question has to explain BOTH states, because the whole difficulty
        // is that the same list means opposite things either side of the switch.
        // Naming only the consequence ("this clears N") tells somebody what will
        // be destroyed without telling them why, which reads as an obstacle
        // rather than an explanation.
        const people = window.t(n === 1 ? 'knowledge.perm.wipe_people_one' : 'knowledge.perm.wipe_people', { count: n });
        if (n > 0 && !await kbConfirm({
            title:   window.t(restricted ? 'knowledge.perm.wipe_title_restrict' : 'knowledge.perm.wipe_title_open'),
            message: window.t(restricted ? 'knowledge.perm.wipe_to_restricted'  : 'knowledge.perm.wipe_to_open', { people: people }),
            okLabel: window.t(restricted ? 'knowledge.perm.wipe_ok_restrict'    : 'knowledge.perm.wipe_ok_open'),
            okClass: 'danger'
        })) {
            // Put the tickbox back: it must show what is stored, not what was
            // clicked and then abandoned.
            document.getElementById('kbPermRestricted').checked = !restricted;
            return;
        }
    }

    await permPost({ action: 'set_mode', is_restricted: restricted ? 1 : 0, inherits: inherits ? 1 : 0 });
    await permLoad();
}

/**
 * Take somebody off the list.
 *
 * Asked for first, because on a RESTRICTED object this takes access away and
 * the person it happens to is not in the room to notice. The name is in the
 * question rather than a bare "are you sure?" — the rows all look alike, and
 * the mis-click this guards against is removing the one below the one you meant.
 */
async function permRemove(entryId, name) {
    const ok = await kbConfirm({
        title: window.t('knowledge.perm.remove'),
        message: window.t('knowledge.perm.remove_confirm', { name: name || '' }),
        okLabel: window.t('knowledge.perm.remove'),
        okClass: 'danger'
    });
    if (!ok) return;

    const d = await permPost({ action: 'remove', entry_id: entryId });
    if (!d) return;                       // permPost already said why
    showToast(window.t('knowledge.perm.removed', { name: name || '' }), 'success');
    // Said AFTER the removal succeeded, and as a warning rather than a success:
    // "restricted to nobody" is legal and occasionally meant, but it is almost
    // never what somebody intended by removing one person.
    if (d.now_unreachable) showToast(window.t('knowledge.perm.now_unreachable'), 'warning');
    await permLoad();
}

async function permAdd(type, id) {
    const d = await permPost({ action: 'add', principal_type: type, principal_id: id });
    if (d) showToast(window.t('knowledge.perm.added'), 'success');
    document.getElementById('kbPermSearch').value = '';
    document.getElementById('kbPermResults').innerHTML = '';
    await permLoad();
}

async function permPost(payload) {
    if (!permTarget) return null;
    try {
        const r = await fetch(API_BASE + 'permissions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({
                object_type: permTarget.type,
                object_id:   permTarget.id
            }, payload))
        });
        const d = await r.json();
        if (!d.success) { showToast(d.error || window.t('knowledge.perm.failed'), 'error'); return null; }
        return d;
    } catch (e) {
        showToast(window.t('knowledge.perm.failed'), 'error');
        return null;
    }
}

function permSearch() {
    clearTimeout(permSearchTimer);
    permSearchTimer = setTimeout(async () => {
        const q = document.getElementById('kbPermSearch').value.trim();
        const box = document.getElementById('kbPermResults');
        if (q.length < 2) { box.innerHTML = ''; return; }
        try {
            const r = await fetch(`${API_BASE}permissions.php?action=search_principals&q=${encodeURIComponent(q)}`);
            const d = await r.json();
            const rows = (d.results || []);
            box.innerHTML = rows.length
                ? rows.map(p => `<div class="kb-perm-result" onclick="permAdd('${escapeHtml(p.type)}', ${p.id})">
                        <span>${escapeHtml(p.name)}</span><span class="kb-perm-entry-kind">${escapeHtml(p.kind)}</span>
                     </div>`).join('')
                : '';
        } catch (e) { box.innerHTML = ''; }
    }, 250);
}

async function loadTags() {
    try {
        const response = await fetch(API_BASE + 'knowledge_tags.php');
        const data = await response.json();

        if (data.success) {
            tags = data.tags;
            renderTagFilters();
        }
    } catch (error) {
        console.error('Error loading tags:', error);
    }
}

// Load articles
async function loadArticles(search = '', tagIds = []) {
    if (isRecycleBinView) return;
    const articleList = document.getElementById('articleList');
    articleList.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        let url = API_BASE + 'knowledge_articles.php?';
        if (search) url += `search=${encodeURIComponent(search)}&`;
        if (tagIds.length > 0) url += `tags=${tagIds.join(',')}&`;
        // ⚠️ THE TOP LEVEL SHOWS THE TOP LEVEL, not everything.
        //
        // It used to list every article whatever folder it was in, which is what
        // made a drag look like a copy: move an article into a folder and the
        // card stayed exactly where it was, while the folder now showed it too.
        // Explorer's root shows what is AT the root, not every file on the disk,
        // and Ed's instinct was the same.
        //
        // ⚠️ EXCEPT WHEN SEARCHING OR FILTERING BY TAG. Those are questions about
        // the whole knowledge base, not about a place in it — a search that
        // silently ignored everything inside folders would be worse than useless,
        // because it would answer "no results" about articles that exist.
        // ⚠️ NOT IN TREE VIEW. The tree draws the WHOLE SHAPE from this one
        // fetch — every folder with its articles nested inside — so restricting
        // it to the top level gives a tree of folders with nothing in any of
        // them. That is exactly what happened the moment the root-only change
        // landed, and only a test that looked for the DOCUMENTS caught it.
        //
        // ⚠️ AND A FOLDER NEVER NARROWS THE TREE EITHER, for the same reason.
        // Clicking JML used to refetch "articles in JML" - so every document
        // elsewhere vanished from the tree while every FOLDER stayed, because
        // the folder list is not filtered by this call. The result was a
        // skeleton of folders with nothing in any of them, which reads as a
        // broken screen rather than as a selection. A tree earns its keep by
        // showing the whole shape at once; selecting a place inside it must not
        // destroy the view of everywhere else. In the tree, clicking a folder
        // SELECTS it - breadcrumb, highlight, and where a new folder or article
        // will go - and nothing more.
        //
        // Search and tags still filter the tree: those are questions about the
        // whole knowledge base, and a pruned tree is the answer.
        const treeShowsEverything = kbLayout === 'tree';
        const browsingTop = activeFolder === '' && !search && tagIds.length === 0
                         && !treeShowsEverything;
        if (browsingTop) {
            url += 'folder=root&';
        } else if (activeFolder !== '' && !treeShowsEverything) {
            url += `folder=${encodeURIComponent(activeFolder)}&`;
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            articles = data.articles;
            kbArticlesLoaded = true;
            renderArticleList();
        } else {
            articleList.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.list.error_loading')) + '</div>';
        }
    } catch (error) {
        console.error('Error loading articles:', error);
        articleList.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.list.failed_load')) + '</div>';
    }
}

// Render tag filters in sidebar
function renderTagFilters() {
    const container = document.getElementById('tagFilterList');

    if (tags.length === 0) {
        container.innerHTML = '<div class="no-results">' + escapeHtml(window.t('knowledge.sidebar.no_tags')) + '</div>';
        return;
    }

    container.innerHTML = tags.map(tag => `
        <div class="tag-filter ${activeTagFilters.includes(tag.id) ? 'active' : ''}"
             onclick="toggleTagFilter(${tag.id})">
            ${escapeHtml(tag.name)}
            <span class="tag-count">(${tag.article_count || 0})</span>
        </div>
    `).join('');
}

// Toggle tag filter
function toggleTagFilter(tagId) {
    const index = activeTagFilters.indexOf(tagId);
    if (index === -1) {
        activeTagFilters.push(tagId);
    } else {
        activeTagFilters.splice(index, 1);
    }
    renderTagFilters();
    loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
}

/*
 * Selecting more than one article.
 *
 * ⚠️ THE CONSTRAINT THAT SHAPES ALL OF THIS: a plain click on a card must open
 * the article. That is what a card is for, and Ed ruled it out of scope for
 * selection early — so the tickets inbox model, where a plain click on a row
 * selects it and a modifier extends, cannot simply be copied across. The
 * TICKBOX is the selection mechanism here; the card body stays a link.
 *
 * What that leaves, and what is implemented:
 *
 *   tickbox click          toggle that one, and become the anchor
 *   Shift + tickbox        take the block from the anchor to here
 *   Ctrl/Shift + card      select rather than open — the modifier is a clear
 *                          statement of intent, so the card can serve both
 *   Arrow up/down          move the focused row (and select just it)
 *   Shift + arrow          extend the block from the anchor
 *   Ctrl + arrow           move the focus WITHOUT changing the selection
 *   Space                  toggle the focused row
 *   Ctrl + A               select everything on screen
 *   Escape                 clear
 *
 * Selection survives a re-render — it lives in a Set here, not in the DOM —
 * because filtering to "VPN", ticking six, then filtering to "printer" and
 * ticking four more is the whole point.
 */
const kbSelected = new Set();

// Where a Shift-range measures FROM. It deliberately does NOT move when a range
// is taken, so a second Shift-click re-measures from the same origin and can
// shrink the block as well as grow it.
let kbAnchorId = null;
// The row the keyboard is on. Separate from the anchor: Ctrl+arrow moves this
// without disturbing the origin of a range.
let kbFocusId = null;

/**
 * The article ids in the order they are ON SCREEN.
 *
 * ⚠️ NOT the order of `articles`. The details view sorts by whichever column
 * was clicked, and the tree groups by folder — so a Shift-range built from the
 * data order would select rows the user never saw between the two they clicked.
 * A range means "everything BETWEEN these two, as displayed", which makes the
 * DOM the only honest source.
 */
function kbVisibleArticleIds() {
    return Array.from(document.querySelectorAll('#articleList [data-article]'))
                .map(el => Number(el.dataset.article))
                .filter(id => !Number.isNaN(id));
}

function kbRowEl(id) {
    return document.querySelector('#articleList [data-article="' + id + '"]');
}

/** Repaint ticks, row highlighting and the bar from the Set. */
function kbRenderSelection() {
    document.querySelectorAll('#articleList [data-article]').forEach(el => {
        const id = Number(el.dataset.article);
        const on = kbSelected.has(id);
        el.classList.toggle('kb-row-selected', on);
        el.classList.toggle('kb-row-focus', id === kbFocusId);
        const cb = el.querySelector('.article-select input[type="checkbox"]');
        if (cb) cb.checked = on;
    });
    renderBulkBar();
}

function kbSetSelection(ids, { anchor = null, focus = null } = {}) {
    kbSelected.clear();
    ids.forEach(id => kbSelected.add(id));
    if (anchor !== null) kbAnchorId = anchor;
    if (focus !== null) kbFocusId = focus;
    kbRenderSelection();
}

/** Everything between the anchor and `id`, as displayed. */
function kbSelectRangeTo(id, { add = false } = {}) {
    const ids = kbVisibleArticleIds();
    const from = ids.indexOf(kbAnchorId === null ? id : kbAnchorId);
    const to = ids.indexOf(id);
    if (from === -1 || to === -1) { kbToggleOne(id); return; }
    const block = ids.slice(Math.min(from, to), Math.max(from, to) + 1);
    if (!add) kbSelected.clear();
    block.forEach(x => kbSelected.add(x));
    kbFocusId = id;                 // the anchor stays put, on purpose
    kbRenderSelection();
}

function kbToggleOne(id) {
    if (kbSelected.has(id)) kbSelected.delete(id); else kbSelected.add(id);
    kbAnchorId = id;
    kbFocusId = id;
    kbRenderSelection();
}

/**
 * A click on the tick itself. Returns nothing — the tick is the mechanism, so
 * this never opens anything.
 */
function kbCheckboxClick(e, id) {
    e.stopPropagation();            // the card underneath opens the article
    e.preventDefault();             // we set `checked` ourselves, from the Set
    if (e.shiftKey) kbSelectRangeTo(id, { add: true });
    else kbToggleOne(id);
}

/**
 * A click on the row itself. Returns true if it was handled as a SELECTION, in
 * which case the caller must not open the article.
 *
 * ⚠️ Only a modifier can do this. A bare click opens the article, always — that
 * is the rule the rest of this file is built around.
 */
function kbRowSelectClick(e, id) {
    const ctrl = e.ctrlKey || e.metaKey;
    if (e.shiftKey) { e.preventDefault(); kbSelectRangeTo(id, { add: ctrl }); return true; }
    if (ctrl) { e.preventDefault(); kbToggleOne(id); return true; }
    return false;
}

/** Every article row's onclick. */
function kbRowClick(e, id) {
    if (kbRowSelectClick(e, id)) return;
    viewArticle(id);
}

function clearArticleSelection() {
    kbSelected.clear();
    kbAnchorId = null;
    kbFocusId = null;
    kbRenderSelection();
}

function selectAllVisibleArticles() {
    // ⚠️ What is ON SCREEN, not every article loaded. In the tree that is the
    // whole knowledge base; in a folder it is that folder. Either way it is
    // what the person can see, which is what "all" has to mean or the count in
    // the bar describes a selection nobody can inspect.
    kbSetSelection(kbVisibleArticleIds());
}

function kbMoveFocus(delta, { extend = false, keepSelection = false } = {}) {
    const ids = kbVisibleArticleIds();
    if (!ids.length) return;
    let i = ids.indexOf(kbFocusId);
    if (i === -1) i = delta > 0 ? -1 : 0;
    const next = Math.max(0, Math.min(ids.length - 1, i + delta));
    const id = ids[next];

    if (extend) {
        if (kbAnchorId === null) kbAnchorId = kbFocusId === null ? id : kbFocusId;
        kbSelectRangeTo(id);
    } else if (keepSelection) {
        kbFocusId = id;
        kbRenderSelection();
    } else {
        kbSetSelection([id], { anchor: id, focus: id });
    }
    const el = kbRowEl(id);
    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
}

/**
 * ⚠️ A DOCUMENT-LEVEL KEY HANDLER IN A MODULE THAT CONTAINS A RICH TEXT EDITOR
 * has to be paranoid about when it is allowed to act. Swallowing Space or
 * Ctrl+A while somebody is writing an article would be a far worse bug than
 * the one this fixes, so the guards come first and refuse by default.
 */
function kbSelectionKeydown(e) {
    const listView = document.getElementById('articleListView');
    if (!listView || listView.style.display === 'none') return;   // not on the list
    if (document.querySelector('.kb-modal-backdrop, .kb-perm-modal')) return;

    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;

    if (e.key === 'Escape') { if (kbSelected.size) { clearArticleSelection(); e.preventDefault(); } return; }

    if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
        selectAllVisibleArticles(); e.preventDefault(); return;
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        kbMoveFocus(e.key === 'ArrowDown' ? 1 : -1, {
            extend: e.shiftKey,
            keepSelection: (e.ctrlKey || e.metaKey) && !e.shiftKey,
        });
        e.preventDefault(); return;
    }
    if (e.key === ' ' || e.key === 'Spacebar') {
        if (kbFocusId !== null) { kbToggleOne(kbFocusId); e.preventDefault(); }
        return;
    }
    if (e.key === 'Enter' && kbFocusId !== null) { viewArticle(kbFocusId); e.preventDefault(); }
}
document.addEventListener('keydown', kbSelectionKeydown);

/** The tick, rendered the same way in every view that has rows. */
function kbSelectBox(id) {
    return `<label class="article-select" onclick="kbCheckboxClick(event, ${id})"
                   title="${escapeHtml(window.t('knowledge.bulk.select_title'))}">
                <input type="checkbox" ${kbSelected.has(id) ? 'checked' : ''} tabindex="-1">
            </label>`;
}

function renderBulkBar() {
    const bar = document.getElementById('kbBulkBar');
    if (!bar) return;
    const n = kbSelected.size;
    if (n === 0) { bar.style.display = 'none'; return; }
    bar.style.display = '';
    const countEl = document.getElementById('kbBulkCount');
    if (countEl) {
        countEl.textContent = window.t(n === 1 ? 'knowledge.bulk.selected_one' : 'knowledge.bulk.selected', { count: n });
    }
    kbRenderBulkFolders();
}

/**
 * "Move them to…" — the folder list, rebuilt whenever the bar appears because
 * folders can be created while a selection is held.
 */
function kbRenderBulkFolders() {
    const sel = document.getElementById('kbBulkFolder');
    if (!sel) return;
    const keep = sel.value;
    const opts = ['<option value="">' + escapeHtml(window.t('knowledge.bulk.move_choose')) + '</option>',
                  '<option value="root">' + escapeHtml(window.t('knowledge.folders.root')) + '</option>'];
    const walk = (parent, depth) => {
        kbFolders.filter(f => String(f.parent_id) === String(parent) || (parent === null && f.parent_id === null))
                 .sort((a, b) => String(a.name).localeCompare(String(b.name), undefined, { numeric: true, sensitivity: 'base' }))
                 .forEach(f => {
                     opts.push('<option value="' + f.id + '">' + '&nbsp;'.repeat(depth * 3) + escapeHtml(f.name) + '</option>');
                     walk(f.id, depth + 1);
                 });
    };
    walk(null, 0);
    sel.innerHTML = opts.join('');
    if (keep) sel.value = keep;
}

/**
 * Move everything selected into one folder.
 *
 * ⚠️ ONE AT A TIME THROUGH THE ORDINARY ENDPOINT, not a new bulk one. Every
 * move then goes through the same permission checks, the same audit entry and
 * the same cycle refusal as a drag does — a bulk path that reimplemented any of
 * that would be a second place for the rules to drift.
 */
async function applyBulkMove() {
    const sel = document.getElementById('kbBulkFolder');
    const value = sel ? sel.value : '';
    if (!value || kbSelected.size === 0) return;
    const folderId = value === 'root' ? null : Number(value);
    const btn = document.getElementById('kbBulkMove');
    if (btn) { btn.disabled = true; btn.textContent = window.t('knowledge.bulk.applying'); }

    let moved = 0, failed = 0;
    for (const id of Array.from(kbSelected)) {
        try {
            // ⚠️ API_BASE, never a hardcoded relative path. This page is
            // reachable at more than one URL depth, and a relative path is
            // correct at exactly one of them - the fault behind GH #74.
            const r = await fetch(API_BASE + 'folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'move_article', article_id: id, folder_id: folderId }),
            });
            const data = await r.json();
            if (data.success) moved++; else failed++;
        } catch (_) { failed++; }
    }

    if (btn) { btn.disabled = false; btn.textContent = window.t('knowledge.bulk.move'); }
    if (failed && moved) showToast(window.t('knowledge.bulk.moved_partial', { moved: moved, failed: failed }), 'warning');
    else if (failed) showToast(window.t('knowledge.bulk.move_failed'), 'error');
    else showToast(window.t('knowledge.bulk.moved', { count: moved }), 'success');

    clearArticleSelection();
    loadFolders();
    loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
}

async function applyBulkAudience() {
    const select = document.getElementById('kbBulkAudience');
    const audience = select ? select.value : '';
    if (!audience || kbSelected.size === 0) return;

    const ids = Array.from(kbSelected);
    const btn = document.getElementById('kbBulkApply');
    if (btn) { btn.disabled = true; btn.textContent = window.t('knowledge.bulk.applying'); }

    try {
        const response = await fetch('../api/knowledge/knowledge_bulk_audience.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids, audience: audience })
        });
        const data = await response.json();

        if (!data.success) {
            showToast(data.error || window.t('knowledge.bulk.failed'), 'error');
            return;
        }

        // Report partial success honestly: silently skipping articles the analyst
        // can't reach is how someone believes a document is published when it
        // isn't.
        if (data.failed && data.failed.length) {
            showToast(window.t('knowledge.bulk.partial', {
                updated: data.updated, failed: data.failed.length
            }), 'error');
        } else {
            showToast(window.t('knowledge.bulk.done', { count: data.updated }), 'success');
        }

        clearArticleSelection();
        loadArticles();
    } catch (e) {
        showToast(window.t('knowledge.bulk.failed'), 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = window.t('knowledge.bulk.apply'); }
    }
}

// Render article list
function renderArticleList() {
    const container = document.getElementById('articleList');
    const countEl = document.getElementById('articleCount');

    // ⚠️ NOTHING IS DRAWN UNTIL THE ARTICLES HAVE ARRIVED, and this is a bug I
    // introduced. `articles` starts as [], and I added several callers that
    // repaint the list — applyBrowseMode(), applyLayout(), loadFolders() — every
    // one of which can fire before the fetch returns. Each painted the empty
    // state over the spinner, so a refresh flashed "No articles found", then a
    // count of 0, then the real content.
    //
    // An empty array means TWO different things: "nothing here" and "not asked
    // yet". Only the first deserves an empty state; the second is a spinner. The
    // flag is what tells them apart, because the array cannot.
    if (!kbArticlesLoaded) {
        if (container && !container.querySelector('.spinner')) {
            container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
        }
        if (countEl) countEl.textContent = '';
        return;
    }

    countEl.textContent = window.t(articles.length === 1 ? 'knowledge.list.count_one' : 'knowledge.list.count', { count: articles.length });
    renderBreadcrumb();

    // The tree and the details table both draw folders and articles together, so
    // each replaces the whole pane rather than sitting above the usual rows.
    container.className = 'article-list kb-layout-' + kbLayout;
    // ⚠️ EVERY PATH OUT OF THIS FUNCTION HAS TO REPAINT THE SELECTION. The ticks
    // come out right because each row asks the Set as it is built, but the row
    // HIGHLIGHT is a class applied afterwards - so without this, changing view
    // or searching left the ticks on and the highlighting off, which reads as a
    // half-selected list.
    if (kbLayout === 'tree') {
        container.innerHTML = renderTreeLayout();
        kbRenderSelection();
        return;
    }
    if (kbLayout === 'details') {
        container.innerHTML = renderDetailsLayout();
        kbRenderSelection();
        return;
    }

    // ⚠️ An empty FOLDER is not an empty knowledge base. Showing "create your
    // first article" inside a folder that merely holds subfolders would be
    // alarming and wrong — and it would hide the subfolders, which are the only
    // way further in.
    const folderRows = renderFolderRows();
    if (articles.length === 0 && !folderRows) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📚</div>
                <div class="empty-state-text">${escapeHtml(window.t(
                    activeFolder === '' ? 'knowledge.list.no_articles' : 'knowledge.folders.empty'))}</div>
                <button class="btn btn-primary" onclick="openCreateArticle()">${escapeHtml(window.t('knowledge.list.create_first'))}</button>
            </div>
        `;
        return;
    }

    // ⚠️ BY NAME, not newest-first. The API returns articles in modified order,
    // which suits a feed of recent work and not a folder you are looking
    // something up in - and it left the folders sorted alphabetically above a
    // list of documents that were not, which reads as no order at all. The tree
    // was fixed first; Ed spotted that cards had the same fault. Sorted on a
    // COPY so nothing else that reads `articles` is disturbed.
    const ordered = articles.slice().sort((a, b) =>
        String(a.title).localeCompare(String(b.title), undefined, { numeric: true, sensitivity: 'base' }));

    container.innerHTML = folderRows + ordered.map(article => `
        <div class="article-card" onclick="kbRowClick(event, ${article.id})"
             data-article="${article.id}"
             draggable="true"
             ondragstart="kbDragStart(event, 'article', ${article.id})"
             ondragend="kbDragEnd()"
             oncontextmenu="kbContextMenu(event, 'article', ${article.id}, ${jsAttr(article.title)})">
            ${kbSelectBox(article.id)}
            <div class="article-card-title">${
                // ↗ it is here via a shortcut; 🔒 it carries its OWN permissions.
                // The second is the §9 badge: an exception you cannot see from
                // the list is an exception nobody will ever audit.
                (kbRowIsShortcut(article.id) ? '<span class="kb-badge" title="' + escapeHtml(window.t('knowledge.folders.is_shortcut')) + '">↗</span> ' : '')
              + (Number(article.inherit_permissions) === 0 ? '<span class="kb-badge" title="' + escapeHtml(window.t('knowledge.exceptions.own_rules')) + '">🔒</span> ' : '')
            }${escapeHtml(article.title)}</div>
            <div class="article-card-preview">${escapeHtml(article.preview || '')}</div>
            <div class="article-card-meta">
                <div class="article-card-tags">
                    ${renderCardTags(article)}
                </div>
                <div class="article-card-info">
                    <span>${escapeHtml(window.t('knowledge.list.by', { name: article.author_name }))}</span>
                    <span>${formatDate(article.modified_datetime)}</span>
                    ${kbFolderLabel(article)}
                </div>
            </div>
        </div>
    `).join('');
    kbRenderSelection();
}

// Debounced search
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        loadArticles(document.getElementById('articleSearch').value, activeTagFilters);
    }, 300);
}

// View article detail
async function viewArticle(articleId) {
    try {
        const response = await fetch(`${API_BASE}knowledge_article.php?id=${articleId}`);
        const data = await response.json();

        if (data.success) {
            currentArticle = data.article;
            // The recent trail (#124) — recorded on success only, so an article
            // that failed to open never appears as one you read.
            if (window.trailVisit) window.trailVisit('knowledge_article', articleId);
            renderArticleDetail();
            showView('detail');
        } else {
            showToast(window.t('knowledge.toast.error_loading', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.load_failed'), 'error');
    }
}

// Render article detail
function renderArticleDetail() {
    const container = document.getElementById('articleContent');

    // The Permissions button appears only for someone who may change access.
    // Hidden rather than disabled: a control you can see but never use is a
    // question you have to ask somebody, every time.
    const permBtn = document.getElementById('kbArticlePermBtn');
    if (permBtn) permBtn.style.display = kbCanManagePerms ? '' : 'none';
    // The history names people and says when they read something, so it sits
    // behind the same capability as the access list rather than being open to
    // anyone who can open the article.
    const auditBtn = document.getElementById('kbArticleAuditBtn');
    if (auditBtn) auditBtn.style.display = kbCanManagePerms ? '' : 'none';

    container.innerHTML = `
        <div class="article-content-header">
            <h1 class="article-content-title">${escapeHtml(currentArticle.title)}</h1>
            <!-- The four meta lines carry a class each purely so a stylesheet
                 can name them. Nothing targets these on desktop; mobile.css
                 collapses all but "modified" behind a tap (LAYER 17h), and
                 doing that by :nth-child would silently point at the wrong
                 line the moment a fifth is added or the order changes. -->
            <div class="article-content-meta">
                <span class="kb-meta-by">${escapeHtml(window.t('knowledge.detail.by', { name: currentArticle.author_name }))}</span>
                <span class="kb-meta-created">${escapeHtml(window.t('knowledge.detail.created', { date: formatDate(currentArticle.created_datetime), version: currentArticle.version || 1 }))}</span>
                <span class="kb-meta-modified">${escapeHtml(window.t('knowledge.detail.modified', { date: formatDate(currentArticle.modified_datetime) }))}</span>
                <span class="kb-meta-views">${escapeHtml(window.t('knowledge.detail.views', { count: currentArticle.view_count }))}</span>
            </div>
            <div class="article-content-tags">
                ${(currentArticle.tags || []).map(tag => `<span class="article-tag">${escapeHtml(tag.name)}</span>`).join('')}
            </div>
        </div>
        <div class="article-content-body">
            ${currentArticle.body}
        </div>
        <div id="kbDocuments" style="margin-top:24px;"></div>
    `;

    // Apply syntax highlighting to any code blocks
    if (typeof Prism !== 'undefined') {
        Prism.highlightAll();
    }

    // Attached documents (discussion #76) — the manuals, procedures and PDFs the
    // feature request asked for. Mounted rather than re-pointed because this
    // container is rebuilt for every article; see the note in documents.js.
    //
    // ⚠️ canEdit: false — READING an article is not EDITING it. The panel used
    // to bring its drop zone, its "add a link" row and its "find an existing
    // document" box onto the page you land on just to read something, which put
    // three pieces of editing furniture under every article for everybody. It is
    // most obvious on a phone, where they take most of a screen, but the
    // reasoning is not about screen size: everything else that changes an
    // article is behind Edit, and this was the one exception. The list itself
    // stays - the attachments are part of the article and you are here to read
    // it. Adding and removing moved to the editor.
    if (window.FreeITSMDocuments) {
        FreeITSMDocuments.mount(document.getElementById('kbDocuments'), {
            parentType: 'knowledge_article',
            parentId:   currentArticle.id,
            apiBase:    '../api/documents/',
            canEdit:    false,
            showHeading: true      // nothing else on this page names the section
        });
    }
}

// Open create article view
function openCreateArticle() {
    currentArticle = null;
    selectedTags = [];
    document.getElementById('editArticleId').value = '';
    document.getElementById('articleTitle').value = '';
    document.getElementById('editorTitle').textContent = window.t('knowledge.editor.new_title');
    renderSelectedTags();

    // Clear owner and review date
    const ownerSelect = document.getElementById('articleOwner');
    if (ownerSelect) ownerSelect.value = '';
    const reviewDateInput = document.getElementById('articleReviewDate');
    if (reviewDateInput) reviewDateInput.value = '';
    // A new article starts internal + shared: never public until someone says so.
    resetVisibilityFields();

    if (articleEditor) {
        articleEditor.setContent('');
    }

    mountEditorDocuments();      // new article: says "save it first"
    document.getElementById('btnSaveAsVersion').style.display = 'none';
    showView('editor');
    applyEditorPopoutFromPref();
}

// Edit current article
function editCurrentArticle() {
    if (!currentArticle) return;

    document.getElementById('editArticleId').value = currentArticle.id;
    document.getElementById('articleTitle').value = currentArticle.title;
    document.getElementById('editorTitle').textContent = window.t('knowledge.editor.edit_title');

    selectedTags = (currentArticle.tags || []).map(t => t.name);
    renderSelectedTags();

    // Set visibility from the stored article. Fall back to the safe end if the
    // read endpoint hasn't been taught these fields yet.
    //
    // ⚠️ audienceToControls(), NOT a direct assignment to the dropdown. The
    // dropdown has only TWO options now, so `select.value = 'public'` silently
    // matches nothing and the browser clears the control — the editor would open
    // showing "Analysts only" on an article visible to the whole internet, and
    // saving would then quietly narrow it.
    audienceToControls(currentArticle.audience);
    const companySelect = document.getElementById('articleCompany');
    if (companySelect) companySelect.value = currentArticle.tenant_id || '';
    const folderSelect = document.getElementById('articleFolder');
    if (folderSelect) folderSelect.value = currentArticle.folder_id ? String(currentArticle.folder_id) : '';

    // Set owner and review date
    const ownerSelect = document.getElementById('articleOwner');
    if (ownerSelect) ownerSelect.value = currentArticle.owner_id || '';
    const reviewDateInput = document.getElementById('articleReviewDate');
    if (reviewDateInput) {
        // Format date as YYYY-MM-DD for input[type=date]
        if (currentArticle.next_review_date) {
            const date = new Date(currentArticle.next_review_date);
            reviewDateInput.value = date.toISOString().split('T')[0];
        } else {
            reviewDateInput.value = '';
        }
    }

    if (articleEditor) {
        articleEditor.setContent(currentArticle.body || '');
    }

    document.getElementById('btnSaveAsVersion').style.display = '';
    mountEditorDocuments();      // existing article: the full panel
    showView('editor');
    // Restore the user's last popout preference on every entry to the editor.
    applyEditorPopoutFromPref();
}

// Per-analyst editor popout state — same localStorage pattern the tickets
// inbox uses for its full-screen toggle. The CSS does all the layout; this
// just flips a class on .knowledge-container.
function toggleEditorPopout() {
    const container = document.querySelector('.knowledge-container');
    if (!container) return;
    const on = container.classList.toggle('editor-popout');
    try { localStorage.setItem('knowledge_editor_popout', on ? '1' : '0'); } catch (e) {}
}

function applyEditorPopoutFromPref() {
    const container = document.querySelector('.knowledge-container');
    if (!container) return;
    let prefersPopout = false;
    try { prefersPopout = localStorage.getItem('knowledge_editor_popout') === '1'; } catch (e) {}
    container.classList.toggle('editor-popout', prefersPopout);
}

// Save as new version
async function saveAsNewVersion() {
    const currentVersion = currentArticle.version || 1;
    const confirmed = await showConfirm({
        title: window.t('knowledge.confirm.version_title'),
        message: window.t('knowledge.confirm.version_message', { old: currentVersion, new: currentVersion + 1 }),
        okLabel: window.t('knowledge.confirm.version_ok'),
        okClass: 'primary'
    });
    if (!confirmed) return;

    const articleId = document.getElementById('editArticleId').value;
    const title = document.getElementById('articleTitle').value.trim();
    const body = articleEditor ? articleEditor.getContent() : '';
    const ownerSelect = document.getElementById('articleOwner');
    const ownerId = ownerSelect ? ownerSelect.value : null;
    const reviewDateInput = document.getElementById('articleReviewDate');
    const nextReviewDate = reviewDateInput ? reviewDateInput.value : null;

    if (!title) {
        showToast(window.t('knowledge.toast.need_title_save'), 'warning');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'knowledge_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({
                id: articleId,
                title: title,
                body: body,
                tags: selectedTags,
                owner_id: ownerId || null,
                next_review_date: nextReviewDate || null,
                save_as_version: true
            }, visibilityPayload()))
        });

        const data = await response.json();

        if (data.success) {
            showToast(window.t('knowledge.toast.saved_version', { version: currentVersion + 1 }), 'success');
            loadTags();
            loadArticles();
            showView('list');
        } else {
            showToast(data.error || window.t('knowledge.toast.save_version_failed'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.save_version_failed'), 'error');
    }
}

// Save article
async function saveArticle() {
    const articleId = document.getElementById('editArticleId').value;
    const title = document.getElementById('articleTitle').value.trim();
    const body = articleEditor ? articleEditor.getContent() : '';

    // Get owner and review date
    const ownerSelect = document.getElementById('articleOwner');
    const ownerId = ownerSelect ? ownerSelect.value : null;
    const reviewDateInput = document.getElementById('articleReviewDate');
    const nextReviewDate = reviewDateInput ? reviewDateInput.value : null;

    if (!title) {
        showToast(window.t('knowledge.toast.need_title'), 'error');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'knowledge_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({
                id: articleId || null,
                title: title,
                body: body,
                tags: selectedTags,
                owner_id: ownerId || null,
                next_review_date: nextReviewDate || null
            }, visibilityPayload()))
        });

        const data = await response.json();

        if (data.success) {
            showToast(window.t(articleId ? 'knowledge.toast.article_updated' : 'knowledge.toast.article_created'), 'success');
            loadTags(); // Refresh tags in case new ones were added
            loadArticles();
            showView('list');
        } else {
            showToast(window.t('knowledge.toast.error_saving', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.save_failed'), 'error');
    }
}

// Delete current article
async function deleteCurrentArticle() {
    if (!currentArticle) return;

    if (!(await showConfirm({ title: window.t('knowledge.confirm.delete_title'), message: window.t('knowledge.confirm.delete_message'), okLabel: window.t('knowledge.confirm.delete_ok'), okClass: 'danger' }))) return;

    try {
        const response = await fetch(API_BASE + 'knowledge_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentArticle.id })
        });

        const data = await response.json();

        if (data.success) {
            showToast(window.t('knowledge.toast.archived'), 'success');
            loadTags();
            loadArticles();
            showView('list');
        } else {
            showToast(window.t('knowledge.toast.error_archiving', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.archive_failed'), 'error');
    }
}

// Recycle Bin functions
async function toggleRecycleBin() {
    const toggle = document.getElementById('recycleBinToggle');
    const header = document.getElementById('articleListHeader');

    if (isRecycleBinView) {
        // Exit recycle bin
        isRecycleBinView = false;
        toggle.classList.remove('active');
        header.textContent = window.t('knowledge.list.heading');
        loadArticles();
        showView('list');
    } else {
        // Enter recycle bin
        isRecycleBinView = true;
        toggle.classList.add('active');
        header.textContent = window.t('knowledge.list.recycle_bin');
        showView('list');
        await loadRecycleBin();
    }
}

async function loadRecycleBin() {
    const articleList = document.getElementById('articleList');
    const articleCount = document.getElementById('articleCount');
    articleList.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(API_BASE + 'knowledge_archive.php?action=list');
        const data = await response.json();

        if (!data.success) {
            articleList.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('knowledge.recycle.error_loading')) + '</div>';
            return;
        }

        const items = data.articles || [];
        const retentionDays = data.retention_days || 0;
        articleCount.textContent = window.t('knowledge.list.archived', { count: items.length });

        if (items.length === 0) {
            articleList.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('knowledge.recycle.empty')) + '</div>';
            return;
        }

        let html = '';
        if (retentionDays > 0) {
            html += `<div class="recycle-bin-notice">${escapeHtml(window.t('knowledge.recycle.notice_days', { days: retentionDays }))}</div>`;
        } else {
            html += `<div class="recycle-bin-notice">${escapeHtml(window.t('knowledge.recycle.notice_forever'))}</div>`;
        }

        items.forEach(item => {
            const archivedDate = item.archived_datetime ? formatDate(item.archived_datetime) : window.t('knowledge.recycle.unknown');
            const archivedBy = item.archived_by_name || window.t('knowledge.recycle.unknown');
            html += `
                <div class="article-card recycle-bin-card">
                    <div class="article-card-title">${escapeHtml(item.title)}</div>
                    <div class="article-card-meta">
                        ${escapeHtml(window.t('knowledge.recycle.archived_by', { author: item.author_name, date: archivedDate, by: archivedBy }))}
                    </div>
                    <div class="recycle-bin-actions">
                        <button class="btn btn-secondary btn-sm" onclick="viewArchivedArticle(${item.id})">${escapeHtml(window.t('knowledge.recycle.view'))}</button>
                        <button class="btn btn-primary btn-sm" onclick="restoreArticle(${item.id})">${escapeHtml(window.t('knowledge.recycle.restore'))}</button>
                        <button class="btn btn-danger btn-sm" onclick="hardDeleteArticle(${item.id}, '${escapeHtml(item.title).replace(/'/g, "\\'")}')">${escapeHtml(window.t('knowledge.recycle.delete_forever'))}</button>
                    </div>
                </div>
            `;
        });

        articleList.innerHTML = html;
    } catch (error) {
        console.error('Error loading recycle bin:', error);
        articleList.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('knowledge.recycle.failed_load')) + '</div>';
    }
}

async function restoreArticle(id) {
    try {
        const response = await fetch(API_BASE + 'knowledge_archive.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'restore', id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast(window.t('knowledge.toast.restored'), 'success');
            loadTags();
            await loadRecycleBin();
        } else {
            showToast(window.t('knowledge.toast.error_restoring', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.restore_failed'), 'error');
    }
}

async function hardDeleteArticle(id, title) {
    if (!(await showConfirm({ title: window.t('knowledge.confirm.delete_title'), message: window.t('knowledge.confirm.hard_delete_message', { title: title }), okLabel: window.t('knowledge.confirm.delete_ok'), okClass: 'danger' }))) return;

    try {
        const response = await fetch(API_BASE + 'knowledge_archive.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'hard_delete', id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast(window.t('knowledge.toast.deleted_forever'), 'success');
            loadTags();
            await loadRecycleBin();
        } else {
            showToast(window.t('knowledge.toast.error_deleting', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.delete_failed'), 'error');
    }
}

async function viewArchivedArticle(id) {
    try {
        const response = await fetch(`${API_BASE}knowledge_article.php?id=${id}&include_archived=1`);
        const data = await response.json();

        if (data.success) {
            const article = data.article;
            document.getElementById('archivedArticleTitle').textContent = article.title;
            document.getElementById('archivedArticleMeta').innerHTML =
                escapeHtml(window.t('knowledge.recycle.meta', { author: article.author_name, created: formatDate(article.created_datetime), modified: formatDate(article.modified_datetime) })) +
                (article.tags && article.tags.length ? '<div style="margin-top: 8px;">' + article.tags.map(t => `<span class="article-tag">${escapeHtml(t.name)}</span>`).join(' ') + '</div>' : '');
            document.getElementById('archivedArticleBody').innerHTML = article.body;
            document.getElementById('archivedArticleModal').classList.add('active');

            if (typeof Prism !== 'undefined') {
                Prism.highlightAll();
            }
        } else {
            showToast(window.t('knowledge.toast.error_loading', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.load_failed'), 'error');
    }
}

function closeArchivedArticleModal() {
    document.getElementById('archivedArticleModal').classList.remove('active');
}


// Cancel edit
function cancelEdit() {
    if (currentArticle) {
        showView('detail');
    } else {
        showView('list');
    }
}

// Back to list
// (backToList removed — the "Back to list" button now navigates to ./
// directly so the URL reflects the list page and the article state is
// fully reset by the page reload. Other call sites still use
// showView('list') after save / archive flows.)

/**
 * Keep the address bar on the article you are actually looking at.
 *
 * Deep links IN already worked (`?article=N`); what was missing was the other
 * direction, so clicking through the list left the URL on the bare module page
 * and there was nothing to copy, bookmark or reload. Same behaviour Assets
 * gained in #1198.
 *
 * Called from showView() rather than from viewArticle(): that is the ONE place
 * every view transition passes through, so the URL cannot drift out of step
 * with the screen via a path somebody forgot to update.
 *
 * replaceState, NOT pushState — on purpose. A list-and-detail screen would
 * otherwise stack a history entry per article clicked, and Back would crawl
 * through them one at a time instead of leaving the module.
 *
 * `?id=` (the accepted alias) and `&edit=1` are dropped as the URL is rewritten,
 * so a link arriving in either form quietly corrects itself to the canonical
 * `?article=N` that includes/entity_links.php produces. `edit=1` is deliberately
 * NOT re-added when the editor opens: the URL names the ARTICLE, not what you
 * happen to be doing to it, and a copied link should not drop a colleague
 * straight into an editor.
 */
function syncArticleUrl(view) {
    if (!window.history || !history.replaceState) return;

    const url = new URL(window.location.href);
    const showsAnArticle = (view === 'detail' || view === 'editor')
                        && currentArticle && currentArticle.id;

    if (showsAnArticle) {
        url.searchParams.set('article', currentArticle.id);
    } else if (view === 'list' || view === 'editor') {
        // 'editor' with no current article is a NEW article — there is no id to
        // name yet, so the URL must not keep pointing at the last one read.
        url.searchParams.delete('article');
    } else {
        return;
    }

    // The folder you are looking at, so a folder can be linked to and reloaded
    // as easily as an article. Only on the list: while an article is open the
    // URL names the ARTICLE, which is the more specific thing and the one
    // somebody means to share.
    if (view === 'list' && activeFolder !== '') {
        url.searchParams.set('folder', activeFolder);
    } else {
        url.searchParams.delete('folder');
    }
    // One-shot params that must never survive into a copied link.
    ['id', 'edit', 'askai'].forEach(function (k) { url.searchParams.delete(k); });

    const qs = url.searchParams.toString();
    const next = url.pathname + (qs ? '?' + qs : '');
    if (next !== window.location.pathname + window.location.search) {
        history.replaceState(null, '', next);
    }
}

// Show/hide views
function showView(view) {
    document.getElementById('articleListView').style.display = view === 'list' ? 'block' : 'none';
    document.getElementById('articleDetailView').style.display = view === 'detail' ? 'block' : 'none';
    // 'flex' (not 'block') so the column layout that holds the sticky-footer
    // action row activates — overrides the inline display: none from PHP.
    document.getElementById('articleEditorView').style.display = view === 'editor' ? 'flex' : 'none';

    // Editor popout is only meaningful for the editor view. Strip the class
    // when navigating elsewhere so the sidebar reappears on the list/detail
    // pages. The localStorage pref is preserved — next edit restores it.
    if (view !== 'editor') {
        const container = document.querySelector('.knowledge-container');
        if (container) container.classList.remove('editor-popout');
    }

    // Reset recycle bin state when navigating away from list
    if (view !== 'list' && isRecycleBinView) {
        isRecycleBinView = false;
        const toggle = document.getElementById('recycleBinToggle');
        const header = document.getElementById('articleListHeader');
        if (toggle) toggle.classList.remove('active');
        if (header) header.textContent = window.t('knowledge.list.heading');
    }

    syncArticleUrl(view);
}

// Tag input functions
function addTag(tagName) {
    tagName = tagName.replace(/,/g, '').trim();
    if (tagName && !selectedTags.includes(tagName)) {
        selectedTags.push(tagName);
        renderSelectedTags();
    }
}

function removeTag(tagName) {
    selectedTags = selectedTags.filter(t => t !== tagName);
    renderSelectedTags();
}

function renderSelectedTags() {
    const container = document.getElementById('selectedTags');
    container.innerHTML = selectedTags.map(tag => `
        <span class="selected-tag">
            ${escapeHtml(tag)}
            <span class="remove-tag" onclick="removeTag('${escapeHtml(tag)}')">&times;</span>
        </span>
    `).join('');
}

function showTagSuggestions(query) {
    const container = document.getElementById('tagSuggestions');
    const matchingTags = tags.filter(t =>
        t.name.toLowerCase().includes(query.toLowerCase()) &&
        !selectedTags.includes(t.name)
    );

    let html = matchingTags.map(tag => `
        <div class="tag-suggestion" onclick="addTag('${escapeHtml(tag.name)}'); document.getElementById('tagInput').value = '';">
            ${escapeHtml(tag.name)}
        </div>
    `).join('');

    // Option to create new tag
    const exactMatch = tags.some(t => t.name.toLowerCase() === query.toLowerCase());
    if (!exactMatch && query.length > 0) {
        html += `
            <div class="tag-suggestion new-tag" onclick="addTag('${escapeHtml(query)}'); document.getElementById('tagInput').value = '';">
                ${escapeHtml(window.t('knowledge.editor.create_tag', { name: query }))}
            </div>
        `;
    }

    if (html) {
        container.innerHTML = html;
        container.classList.add('active');
    } else {
        hideSuggestions();
    }
}

function hideSuggestions() {
    document.getElementById('tagSuggestions').classList.remove('active');
}

// Utility functions
/**
 * A JS string literal that is safe INSIDE a double-quoted HTML attribute.
 *
 * ⚠️ JSON.stringify() ALONE IS NOT ENOUGH, and this is the bug it caused:
 * it returns "Policies", WITH double quotes, and dropping that into
 * `oncontextmenu="fn(event, 1, "Policies")"` ends the attribute at the first
 * quote. The handler is then malformed and the browser silently does nothing —
 * so right-clicking any row appeared to be unimplemented rather than broken.
 *
 * It survived a Chrome harness because that harness called kbContextMenu()
 * DIRECTLY. Calling the function proves the function works; only dispatching a
 * real contextmenu event at a real row proves the WIRING does.
 */
function jsAttr(value) {
    // ⚠️ NOT escapeHtml(). That one is `div.textContent = x; return div.innerHTML`,
    // which escapes < > and & but deliberately NOT quotes — a quote is harmless
    // in TEXT, which is the only thing that idiom is for. In an ATTRIBUTE the
    // surviving quote closes it, which is precisely how the right-click handler
    // came out as
    //     oncontextmenu="kbContextMenu(event, 'folder', 116, "
    // with the rest of the folder's name reinterpreted as stray attributes. The
    // browser reports nothing: the element simply has no usable handler.
    //
    // So escape the quotes explicitly here. The whole file uses escapeHtml() in
    // attribute positions elsewhere; those are safe only because their values
    // cannot contain a quote, which is a property of today's data rather than a
    // guarantee — worth revisiting.
    return String(value === null || value === undefined ? '' : JSON.stringify(String(value)))
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Share dropdown functions
function toggleShareDropdown() {
    const menu = document.getElementById('shareDropdownMenu');
    menu.classList.toggle('active');

    // Close when clicking outside
    if (menu.classList.contains('active')) {
        setTimeout(() => {
            document.addEventListener('click', closeShareDropdownOnClickOutside);
        }, 0);
    }
}

function closeShareDropdownOnClickOutside(e) {
    const dropdown = document.querySelector('.share-dropdown');
    if (!dropdown.contains(e.target)) {
        document.getElementById('shareDropdownMenu').classList.remove('active');
        document.removeEventListener('click', closeShareDropdownOnClickOutside);
    }
}

function closeShareDropdown() {
    document.getElementById('shareDropdownMenu').classList.remove('active');
    document.removeEventListener('click', closeShareDropdownOnClickOutside);
}

// Share article link - copy to clipboard
function shareArticleLink() {
    closeShareDropdown();

    if (!currentArticle) return;

    const url = `${window.location.origin}${window.location.pathname}?article=${currentArticle.id}`;

    // ⚠️ copyToClipboard(), not navigator.clipboard directly — see clipboard.js.
    // The old code read `.writeText` off an object that does not exist outside a
    // secure context, which throws before the promise is created, so the
    // `.catch()` fallback never ran. On a phone this was silent: no message, and
    // nothing copied.
    copyToClipboard(url).then(ok => {
        if (ok) {
            showToast(window.t('knowledge.toast.link_copied'), 'success');
            return;
        }
        // Do not claim it worked. Hand over the link instead, selected and ready
        // to copy by hand — the browser refused, the person has not.
        kbPrompt(window.t('knowledge.share.copy_manually'), url);
    });
}


// Build a searchable jsPDF document from the current article
async function buildArticlePdf() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
    const pageW = doc.internal.pageSize.getWidth();
    const margin = 15;
    const contentW = pageW - margin * 2;
    let y = margin;

    // --- Logo ---
    try {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        await new Promise((resolve, reject) => {
            img.onload = resolve;
            img.onerror = reject;
            img.src = '../assets/images/CompanyLogo.png';
        });
        const maxH = 12;
        const w = maxH * (img.width / img.height);
        doc.addImage(img, 'PNG', margin, y, w, maxH);
        y += maxH + 6;
    } catch (e) { /* continue without logo */ }

    // --- Title ---
    doc.setFontSize(18);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(30, 30, 30);
    const titleLines = doc.splitTextToSize(currentArticle.title, contentW);
    doc.text(titleLines, margin, y);
    y += titleLines.length * 7 + 2;

    // --- Meta line ---
    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(120, 120, 120);
    const meta = window.t('knowledge.detail.by', { name: currentArticle.author_name }) + '  |  ' +
        window.t('knowledge.detail.created', { date: formatDate(currentArticle.created_datetime), version: currentArticle.version || 1 }) + '  |  ' +
        window.t('knowledge.detail.modified', { date: formatDate(currentArticle.modified_datetime) });
    doc.text(meta, margin, y);
    y += 4;

    // --- Divider ---
    doc.setDrawColor(200, 200, 200);
    doc.line(margin, y, pageW - margin, y);
    y += 6;

    // --- Body ---
    // Parse HTML to structured text blocks
    const temp = document.createElement('div');
    temp.innerHTML = currentArticle.body || '';

    const pageH = doc.internal.pageSize.getHeight();
    const bottomLimit = pageH - margin;

    function ensureSpace(needed) {
        if (y + needed > bottomLimit) { doc.addPage(); y = margin; }
    }

    // Print wrapped lines one-by-one, adding pages as needed
    function printLines(lines, x, lineH) {
        for (let i = 0; i < lines.length; i++) {
            ensureSpace(lineH);
            doc.text(lines[i], x, y);
            y += lineH;
        }
    }

    function renderNode(node) {
        if (node.nodeType === 3) {
            const text = node.textContent.replace(/\s+/g, ' ').trim();
            if (!text) return;
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(50, 50, 50);
            printLines(doc.splitTextToSize(text, contentW), margin, 5);
            return;
        }
        if (node.nodeType !== 1) return;

        const tag = node.tagName.toLowerCase();

        if (tag === 'h1' || tag === 'h2' || tag === 'h3' || tag === 'h4') {
            const sizes = { h1: 16, h2: 14, h3: 12, h4: 11 };
            const lh = sizes[tag] * 0.45;
            y += 3;
            ensureSpace(lh + 3);
            doc.setFontSize(sizes[tag]);
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(30, 30, 30);
            printLines(doc.splitTextToSize(node.textContent.trim(), contentW), margin, lh);
            y += 3;
            return;
        }

        if (tag === 'p') {
            const text = node.textContent.trim();
            if (!text) return;
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(50, 50, 50);
            printLines(doc.splitTextToSize(text, contentW), margin, 5);
            y += 2;
            return;
        }

        if (tag === 'ul' || tag === 'ol') {
            const items = node.querySelectorAll(':scope > li');
            items.forEach((li, idx) => {
                const bullet = tag === 'ul' ? '\u2022' : `${idx + 1}.`;
                const text = li.textContent.trim();
                doc.setFontSize(10);
                doc.setFont('helvetica', 'normal');
                doc.setTextColor(50, 50, 50);
                const lines = doc.splitTextToSize(text, contentW - 8);
                // Print bullet on first line, then remaining lines
                for (let i = 0; i < lines.length; i++) {
                    ensureSpace(5);
                    if (i === 0) doc.text(bullet, margin + 2, y);
                    doc.text(lines[i], margin + 8, y);
                    y += 5;
                }
                y += 1;
            });
            y += 2;
            return;
        }

        if (tag === 'pre' || tag === 'code') {
            const text = node.textContent.trim();
            if (!text) return;
            doc.setFontSize(9);
            doc.setFont('courier', 'normal');
            doc.setTextColor(80, 80, 80);
            const lines = doc.splitTextToSize(text, contentW - 6);
            const lineH = 4.5;
            // Render each line with its own grey background strip
            for (let i = 0; i < lines.length; i++) {
                ensureSpace(lineH + 2);
                doc.setFillColor(245, 245, 245);
                doc.rect(margin, y - 3, contentW, lineH + 1, 'F');
                doc.text(lines[i], margin + 3, y);
                y += lineH;
            }
            y += 3;
            return;
        }

        if (tag === 'br') { y += 3; return; }
        if (tag === 'hr') {
            y += 2;
            ensureSpace(4);
            doc.setDrawColor(200, 200, 200);
            doc.line(margin, y, pageW - margin, y);
            y += 4;
            return;
        }

        // Table support — render as simple rows
        if (tag === 'table') {
            const rows = node.querySelectorAll('tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('th, td');
                const cellTexts = Array.from(cells).map(c => c.textContent.trim());
                const text = cellTexts.join('  |  ');
                doc.setFontSize(9);
                const isHeader = row.querySelector('th');
                doc.setFont('helvetica', isHeader ? 'bold' : 'normal');
                doc.setTextColor(50, 50, 50);
                const lines = doc.splitTextToSize(text, contentW);
                printLines(lines, margin, 4.5);
                y += 1;
            });
            y += 2;
            return;
        }

        for (const child of node.childNodes) renderNode(child);
    }

    for (const child of temp.childNodes) renderNode(child);

    return doc;
}

// Export article as PDF
async function shareArticlePdf() {
    closeShareDropdown();
    if (!currentArticle) return;

    const doc = await buildArticlePdf();
    doc.save(`${currentArticle.title.replace(/[^a-z0-9]/gi, '_')}.pdf`);
}

// Open email share modal with both link and PDF options
function shareArticleBoth() {
    closeShareDropdown();

    if (!currentArticle) return;

    // Reset form
    document.getElementById('shareEmailTo').value = '';
    document.getElementById('shareEmailMessage').value = '';
    document.getElementById('shareIncludeLink').checked = true;
    document.getElementById('shareIncludePdf').checked = true;

    // Show modal
    document.getElementById('shareEmailModal').classList.add('active');
}

function closeShareEmailModal() {
    document.getElementById('shareEmailModal').classList.remove('active');
}

// Send share email
async function sendShareEmail() {
    const toEmail = document.getElementById('shareEmailTo').value.trim();
    const message = document.getElementById('shareEmailMessage').value.trim();
    const includeLink = document.getElementById('shareIncludeLink').checked;
    const includePdf = document.getElementById('shareIncludePdf').checked;

    if (!toEmail) {
        showToast(window.t('knowledge.toast.need_recipient'), 'error');
        return;
    }

    if (!includeLink && !includePdf) {
        showToast(window.t('knowledge.toast.need_include'), 'error');
        return;
    }

    // Generate PDF if needed
    let pdfBase64 = null;
    if (includePdf) {
        try {
            const doc = await buildArticlePdf();
            const pdfBlob = doc.output('blob');
            pdfBase64 = await blobToBase64(pdfBlob);
        } catch (error) {
            console.error('Error generating PDF:', error);
            showToast(window.t('knowledge.toast.pdf_error'), 'error');
            return;
        }
    }

    // Build article URL
    const articleUrl = `${window.location.origin}${window.location.pathname}?article=${currentArticle.id}`;

    try {
        const response = await fetch(API_BASE + 'send_share_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to_email: toEmail,
                article_id: currentArticle.id,
                article_title: currentArticle.title,
                article_url: includeLink ? articleUrl : null,
                message: message,
                pdf_data: pdfBase64,
                pdf_filename: includePdf ? `${currentArticle.title.replace(/[^a-z0-9]/gi, '_')}.pdf` : null
            })
        });

        const data = await response.json();

        if (data.success) {
            closeShareEmailModal();
            showToast(window.t('knowledge.toast.email_sent'), 'success');
        } else {
            showToast(window.t('knowledge.toast.error_email', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast(window.t('knowledge.toast.email_failed'), 'error');
    }
}

// Convert blob to base64
function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => {
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

// Check for article ID in URL on page load (for shared links)
//
// Supports two flavours of deep-link:
//   ?article=N        — opens the article in view mode
//   ?article=N&edit=1 — opens straight into edit mode (used by the
//                       review screen's edit icon so the user doesn't
//                       have to click View → Edit themselves)
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const articleId = urlParams.get('article') || urlParams.get('id');
    const wantEdit = urlParams.get('edit') === '1';

    // ?folder=N (or 'root') opens straight into that folder, so a folder can be
    // linked to and reloaded exactly as an article can. Applied only when no
    // article is named: an article link is the more specific request, and
    // opening a folder underneath it would just be a flicker on the way past.
    const folderParam = urlParams.get('folder');
    if (!articleId && folderParam !== null && folderParam !== '') {
        activeFolder = (folderParam === 'root' || /^\d+$/.test(folderParam)) ? folderParam : '';
        // No fetch here — the initial loadArticles() has not run yet and will
        // pick this up. Kicking off a second one would race the first, and the
        // loser would overwrite the winner.
    }

    if (!articleId) return;

    const checkAndLoad = setInterval(() => {
        if (articles.length > 0 || document.getElementById('articleList').innerHTML.includes('No articles')) {
            clearInterval(checkAndLoad);
            Promise.resolve(viewArticle(articleId)).then(() => {
                if (!wantEdit) return;
                // editCurrentArticle populates TinyMCE — wait until the
                // editor instance is ready so the article body isn't lost.
                const editCheck = setInterval(() => {
                    if (articleEditor) {
                        clearInterval(editCheck);
                        editCurrentArticle();
                    }
                }, 100);
                setTimeout(() => clearInterval(editCheck), 8000);
            });
        }
    }, 100);
    setTimeout(() => clearInterval(checkAndLoad), 5000);
})();

// Server-stamped UTC timestamps (created/modified/archived). Parse as UTC and
// render in the analyst's chosen zone so the calendar day is correct locally.
function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    return fmtDate(date);
}

// ===== AI Chat Functions =====

function openAiChat() {
    document.getElementById('aiChatPanel').classList.add('active');
    document.getElementById('aiChatOverlay').classList.add('active');
    document.getElementById('aiChatInput').focus();
}

function closeAiChat() {
    document.getElementById('aiChatPanel').classList.remove('active');
    document.getElementById('aiChatOverlay').classList.remove('active');
}

async function askAi() {
    const input = document.getElementById('aiChatInput');
    const messagesContainer = document.getElementById('aiChatMessages');
    const sendBtn = document.getElementById('aiSendBtn');
    const question = input.value.trim();

    if (!question) return;

    // Clear welcome message on first question
    const welcome = messagesContainer.querySelector('.ai-chat-welcome');
    if (welcome) welcome.remove();

    // Add user message
    const userMsg = document.createElement('div');
    userMsg.className = 'ai-chat-message user';
    userMsg.innerHTML = '<div class="ai-chat-bubble">' + escapeHtml(question) + '</div>';
    messagesContainer.appendChild(userMsg);

    // Clear input and disable
    input.value = '';
    input.disabled = true;
    sendBtn.disabled = true;

    // Add thinking indicator
    const thinking = document.createElement('div');
    thinking.className = 'ai-chat-thinking';
    thinking.innerHTML = '<div class="dots"><span></span><span></span><span></span></div> ' + escapeHtml(window.t('knowledge.ai.searching'));
    messagesContainer.appendChild(thinking);

    // Scroll to bottom
    messagesContainer.scrollTop = messagesContainer.scrollHeight;

    try {
        const response = await fetch(API_BASE + 'ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question, include_archived: document.getElementById('aiIncludeArchived')?.checked || false })
        });
        const data = await response.json();

        // Remove thinking indicator
        thinking.remove();

        if (data.success) {
            const assistantMsg = document.createElement('div');
            assistantMsg.className = 'ai-chat-message assistant';
            assistantMsg.innerHTML = '<div class="ai-chat-bubble">' + formatAiResponse(data.answer, data.articles || []) + '</div>' +
                '<div class="ai-chat-meta">' + escapeHtml(window.t('knowledge.ai.searched', { count: data.articles_searched })) + '</div>';
            messagesContainer.appendChild(assistantMsg);
        } else {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'ai-chat-error';
            errorMsg.textContent = data.error || window.t('knowledge.ai.error_default');
            messagesContainer.appendChild(errorMsg);
        }
    } catch (error) {
        thinking.remove();
        const errorMsg = document.createElement('div');
        errorMsg.className = 'ai-chat-error';
        errorMsg.textContent = window.t('knowledge.ai.error_network', { message: error.message });
        messagesContainer.appendChild(errorMsg);
    }

    // Re-enable input
    input.disabled = false;
    sendBtn.disabled = false;
    input.focus();

    // Scroll to bottom
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function formatAiResponse(text, articlesList) {
    // Replace quoted article titles with hyperlinks before any other formatting
    if (articlesList && articlesList.length > 0) {
        // Sort by title length descending so longer titles match first
        const sorted = [...articlesList].sort((a, b) => b.title.length - a.title.length);
        sorted.forEach(article => {
            // Match title in quotes: "Title" or "Title", with optional (ID: X) suffix
            const escapedTitle = article.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            // Match with optional " (ID: X)" suffix that AI sometimes adds
            const regex = new RegExp('["\u201c]' + escapedTitle + '(\\s*\\(ID:\\s*\\d+\\))?["\u201d]', 'gi');
            const link = '<a href="javascript:void(0)" data-article-id="' + article.id + '" class="ai-article-link">\u201c' + escapeHtml(article.title) + '\u201d</a>';
            text = text.replace(regex, link);
        });
    }

    // Convert markdown-like formatting to HTML
    // Bold: **text** or __text__
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');

    // Italic: *text* or _text_
    text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    text = text.replace(/(?<!\w)_([^_]+)_(?!\w)/g, '<em>$1</em>');

    // Inline code: `text`
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Line breaks to paragraphs
    const paragraphs = text.split(/\n\n+/);
    if (paragraphs.length > 1) {
        text = paragraphs.map(p => {
            p = p.trim();
            if (!p) return '';
            // Check if it's a list
            if (/^[-*]\s/.test(p) || /^\d+\.\s/.test(p)) {
                const items = p.split(/\n/).map(line => {
                    line = line.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, '');
                    return '<li>' + line + '</li>';
                }).join('');
                return '<ul>' + items + '</ul>';
            }
            return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    } else {
        // Single paragraph - check for line breaks with list items
        if (/^[-*]\s/m.test(text) || /^\d+\.\s/m.test(text)) {
            const lines = text.split(/\n/);
            let html = '';
            let inList = false;
            lines.forEach(line => {
                const isListItem = /^[-*]\s/.test(line) || /^\d+\.\s/.test(line);
                if (isListItem) {
                    if (!inList) { html += '<ul>'; inList = true; }
                    line = line.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, '');
                    html += '<li>' + line + '</li>';
                } else {
                    if (inList) { html += '</ul>'; inList = false; }
                    html += (line.trim() ? '<p>' + line + '</p>' : '');
                }
            });
            if (inList) html += '</ul>';
            text = html;
        } else {
            text = '<p>' + text.replace(/\n/g, '<br>') + '</p>';
        }
    }

    return text;
}

// Close AI chat on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const panel = document.getElementById('aiChatPanel');
        if (panel && panel.classList.contains('active')) {
            closeAiChat();
        }
    }
});

// Handle article link clicks inside AI chat — load article without closing chat
document.addEventListener('click', function(e) {
    const link = e.target.closest('.ai-article-link[data-article-id]');
    if (link) {
        e.preventDefault();
        e.stopPropagation();
        viewArticle(link.dataset.articleId);
    }
});

// ---------------------------------------------------------------------------
//  Moving ONE article, from the article itself.
//
//  ⚠️ There was no way to do this. An article could be filed by dragging it or
//  right-clicking it IN THE LIST — but the moment you are reading an article is
//  exactly when you notice it is filed in the wrong place, and from there the
//  only route was to go back, find it again, and right-click it.
// ---------------------------------------------------------------------------

function kbFolderOptions(selectedId) {
    const opts = ['<option value="root">' + escapeHtml(window.t('knowledge.folders.root')) + '</option>'];
    const walk = (parent, depth) => {
        kbFolders.filter(f => (parent === null ? f.parent_id === null : String(f.parent_id) === String(parent)))
                 .sort((a, b) => String(a.name).localeCompare(String(b.name), undefined, { numeric: true, sensitivity: 'base' }))
                 .forEach(f => {
                     const on = String(f.id) === String(selectedId) ? ' selected' : '';
                     opts.push('<option value="' + f.id + '"' + on + '>'
                             + '&nbsp;'.repeat(depth * 3) + escapeHtml(f.name) + '</option>');
                     walk(f.id, depth + 1);
                 });
    };
    walk(null, 0);
    return opts.join('');
}

function kbMoveCurrentArticle() {
    const modal = document.getElementById('kbMoveModal');
    if (!modal || !currentArticle) return;
    const sel = document.getElementById('kbMoveFolder');
    // Pre-selected to where it lives now, so the box always opens telling you
    // the truth about where the article is rather than proposing a move.
    sel.innerHTML = kbFolderOptions(currentArticle.folder_id);
    if (currentArticle.folder_id === null) sel.value = 'root';
    modal.classList.add('active');
}

function kbCloseMoveModal() {
    const modal = document.getElementById('kbMoveModal');
    if (modal) modal.classList.remove('active');
}

async function kbConfirmMoveArticle() {
    if (!currentArticle) return;
    const sel = document.getElementById('kbMoveFolder');
    const value = sel ? sel.value : '';
    const folderId = value === 'root' ? null : Number(value);
    kbCloseMoveModal();
    // Same endpoint as the drag, so the permission check, the audit entry and
    // the refusals are the ones that already exist.
    await folderAction({ action: 'move_article', article_id: currentArticle.id, folder_id: folderId },
                       'knowledge.folders.moved_article');
    // The article is still on screen and its folder has changed underneath it.
    currentArticle.folder_id = folderId;
}

/**
 * The documents panel inside the EDITOR — where attaching actually happens.
 *
 * ⚠️ Only for an article that EXISTS. A document is attached to a parent id, and
 * a new article has none until it is saved, so the panel would be posting
 * attachments against nothing. Rather than hold files in the browser and flush
 * them after save — which is what ticket notes had to do, because a note has no
 * id either — an article gets saved in one step and reopened, so the honest
 * answer is to say "save it first" and mean it.
 */
function mountEditorDocuments() {
    const box  = document.getElementById('kbEditorDocuments');
    const hint = document.getElementById('kbEditorDocumentsHint');
    if (!box) return;

    const id = currentArticle && currentArticle.id ? currentArticle.id : null;
    if (!id || !window.FreeITSMDocuments) {
        // ⚠️ Drop the class as well as the contents. The widget puts `fd-panel`
        // on the container ITSELF, so emptying the container alone leaves its
        // border and padding behind — an empty bordered box under the editor
        // that reads as a panel which failed to load.
        box.innerHTML = '';
        box.classList.remove('fd-panel');
        if (hint) hint.style.display = id ? 'none' : '';
        return;
    }
    if (hint) hint.style.display = 'none';
    FreeITSMDocuments.mount(box, {
        parentType: 'knowledge_article',
        parentId:   id,
        apiBase:    '../api/documents/',
        canEdit:    true,
        showHeading: true
    });
}

// ---------------------------------------------------------------------------
//  The history — who did what to this, and when.
//
//  ⚠️ Every event has been recorded since folders shipped and shown NOWHERE,
//  which made the audit a claim rather than a feature: the design said "every
//  use of the administrator floor is recorded" and nothing in the product could
//  demonstrate it. An override nobody can look at is not a safety net.
// ---------------------------------------------------------------------------

let kbAuditFor = null;   // { type, id, name }

function openAuditModal(type, id, name) {
    kbAuditFor = { type: type, id: id, name: name };
    const modal = document.getElementById('kbAuditModal');
    if (!modal) return;
    document.getElementById('kbAuditTitle').textContent =
        window.t('knowledge.audit.title_named', { name: name || '' });
    // A spinner, never an empty list. An empty list and "not asked yet" look
    // identical, and only one of them deserves "nothing has happened".
    document.getElementById('kbAuditList').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    modal.classList.add('active');
    auditLoad();
}

function closeAuditModal() {
    const modal = document.getElementById('kbAuditModal');
    if (modal) modal.classList.remove('active');
    kbAuditFor = null;
}

/** The current article's history, from the article itself. */
function openArticleAuditModal() {
    if (!currentArticle) return;
    openAuditModal('article', currentArticle.id, currentArticle.title);
}

async function auditLoad() {
    if (!kbAuditFor) return;
    const box = document.getElementById('kbAuditList');
    try {
        const r = await fetch(API_BASE + 'audit.php?type=' + encodeURIComponent(kbAuditFor.type)
                            + '&id=' + encodeURIComponent(kbAuditFor.id));
        const d = await r.json();
        if (!d.success) {
            box.innerHTML = `<div class="no-results">${escapeHtml(d.error || window.t('knowledge.audit.failed'))}</div>`;
            return;
        }
        if (!d.entries.length) {
            box.innerHTML = `<div class="no-results">${escapeHtml(window.t('knowledge.audit.none'))}</div>`;
            return;
        }
        box.innerHTML = d.entries.map(renderAuditEntry).join('');
    } catch (e) {
        box.innerHTML = `<div class="no-results">${escapeHtml(window.t('knowledge.audit.failed'))}</div>`;
    }
}

/**
 * One line of history.
 *
 * ⚠️ The ACTION is translated from a fixed list, never printed raw. `action` is
 * a machine value written by the engine; showing it directly would put
 * `admin_override` in front of somebody as though that were English, and would
 * also mean a new action type silently reaching the screen untranslated.
 */
function renderAuditEntry(e) {
    const known = ['create', 'edit', 'view', 'move', 'rename', 'delete',
                   'permissions', 'admin_override', 'restore', 'archive'];
    const label = known.includes(e.action)
        ? window.t('knowledge.audit.action_' + e.action)
        : e.action;

    // The override is the row somebody is looking for when they open this at
    // all, so it is marked rather than left to be spotted in a list of forty.
    const isOverride = e.action === 'admin_override';

    return `
        <div class="kb-audit-entry${isOverride ? ' kb-audit-override' : ''}">
            <div class="kb-audit-line">
                <span class="kb-audit-action">${escapeHtml(label)}</span>
                <span class="kb-audit-when">${escapeHtml(formatDateTime(e.when))}</span>
            </div>
            <div class="kb-audit-who">
                ${escapeHtml(e.who)}${e.is_portal ? ' <span class="kb-badge">' + escapeHtml(window.t('knowledge.audit.portal_user')) + '</span>' : ''}
            </div>
            ${renderAuditDetail(e)}
        </div>`;
}

/**
 * The detail blob, in words where we understand it.
 *
 * The engine stores JSON so it can record anything; this turns the handful of
 * shapes that actually occur into a sentence, and falls back to showing the raw
 * value rather than hiding something it does not recognise. A detail that is
 * silently dropped is worse than an ugly one.
 */
function renderAuditDetail(e) {
    const d = e.detail;
    if (!d || typeof d !== 'object') return '';

    if (e.action === 'admin_override') {
        return `<div class="kb-audit-detail">${escapeHtml(window.t('knowledge.audit.detail_override'))}</div>`;
    }
    const bits = [];
    if (d.added)   bits.push(window.t('knowledge.audit.detail_added',   { who: principalLabel(d.added) }));
    if (d.removed_entry) bits.push(window.t('knowledge.audit.detail_removed'));
    if (typeof d.is_restricted !== 'undefined') {
        bits.push(window.t(d.is_restricted
            ? 'knowledge.audit.detail_restricted'
            : 'knowledge.audit.detail_opened'));
    }
    if (d.entries_dropped_by_polarity_change) {
        bits.push(window.t('knowledge.audit.detail_wiped', { count: d.entries_dropped_by_polarity_change }));
    }
    if (typeof d.folder_id !== 'undefined') {
        const f = kbFolders.find(x => String(x.id) === String(d.folder_id));
        bits.push(window.t('knowledge.audit.detail_moved', {
            folder: f ? f.name : window.t('knowledge.folders.root')
        }));
    }
    if (d.name) bits.push(escapeHtml(d.name));

    if (!bits.length) return `<div class="kb-audit-detail">${escapeHtml(JSON.stringify(d))}</div>`;
    return `<div class="kb-audit-detail">${bits.join(' · ')}</div>`;
}

/** "team:21" -> "Team 21" is useless; resolve what we can from what is loaded. */
function principalLabel(raw) {
    const parts = String(raw).split(':');
    if (parts.length !== 2) return escapeHtml(String(raw));
    const kind = window.t('knowledge.audit.principal_' + parts[0]);
    return escapeHtml(kind + ' #' + parts[1]);
}

/**
 * A stored timestamp as a date AND a time in the reader's zone.
 *
 * A history where every row says only the date is unreadable the moment more
 * than one thing happened in a day, which on a busy article is most days.
 * Same UTC-parse path as formatDate(), so the two cannot disagree about what
 * the stored value means; fmtDateTime comes from tz.js.
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    return fmtDateTime(parseUTCDate(dateStr));
}
