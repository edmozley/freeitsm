/**
 * Inbox JavaScript - Service Desk Ticketing System
 */

// API base path - can be overridden by page before loading this script
// Default is 'api/' for root-level pages; module pages should set window.API_BASE = '../api/'
const API_BASE = window.API_BASE || 'api/';

let emails = [];
let selectedEmailId = null;
let composeMode = 'new';
let folderGrouping = 'department'; // 'department' or 'analyst' — persisted via user_preferences

/**
 * Defensive HTML cleaner for email bodies. The real work lives in
 * assets/js/safe-html.js, shared with the self-service portal so the two
 * surfaces that display the same email bodies can never drift apart.
 *
 * This wrapper only supplies the inbox's API_BASE for inline-image rewriting.
 *
 * WHAT THIS FUNCTION USED TO GET WRONG — worth keeping, because the reasoning
 * looked sound. It was written for LAYOUT hygiene: balancing the unclosed tags
 * real email is full of (a runaway <div> otherwise swallows the CMDB, notes and
 * time-entry panels that sit inside the reading pane) and stripping <style>
 * blocks whose page-wide selectors bled into our chrome — the grey-box overlap
 * in MFG-151-13903 was an Outlook footer's stylesheet repositioning content.
 * On <script> it reasoned correctly: scripts genuinely do NOT execute when HTML
 * is assigned via innerHTML.
 *
 * But that is true of <script> ONLY. `<img src=x onerror=...>` fires the moment
 * it is inserted, in every browser, and the Shadow DOM below isolates CSS — not
 * script execution. So any of the hundreds of people who can email your service
 * desk could run code in an analyst's signed-in session. The shared cleaner now
 * strips every inline event handler and javascript:/data: URL as well.
 *
 * Fails CLOSED if safe-html.js is missing: markup is escaped to visible-but-inert
 * text rather than rendered raw.
 */
function safeEmailHtml(html) {
    if (typeof safeHtmlFragment !== 'function') {
        console.error('FreeITSM: assets/js/safe-html.js did not load — email bodies are being shown as plain text.');
        return typeof escapeHtmlText === 'function' ? escapeHtmlText(html) : '';
    }
    return safeHtmlFragment(html, { attachmentBase: API_BASE });
}

/*
 * Email bodies are arbitrary third-party HTML. Injecting them into the page as
 * normal DOM means the app's own stylesheet cascades INTO them — most visibly
 * the global `* { box-sizing: border-box }` reset, but also link/table/list
 * rules — distorting layouts the sender authored for a bare browser. Rather
 * than keep patching individual leaks, we render each body inside a Shadow DOM:
 * outer-page selectors (including `*`) do not match elements inside a shadow
 * tree, so NO app CSS reaches the email. Inherited properties (font, colour)
 * still cross the boundary from the host, which is what we want — plain-text
 * replies keep the app's readable font while designed emails override inline.
 *
 * Flow: emailBodyHost() emits an empty host <div> carrying a token; the body
 * HTML is stashed by token. After the container's innerHTML is set, the caller
 * calls hydrateEmailBodies(root) to attach a shadow root to each host and inject
 * the (already sanitised) HTML. Hydration runs synchronously right after render,
 * so there's no flash and no stranded map entries.
 */
let _emailBodySeq = 0;
const _emailBodyPending = new Map();

/**
 * @param bodyType  the row's `emails.body_type`. Chat channels (web chat,
 *                  WhatsApp) store the sender's message verbatim as 'text';
 *                  passing it through means their words are ESCAPED rather
 *                  than parsed as markup. Omitted / anything else = HTML.
 */
function emailBodyHost(rawHtml, cls, bodyType) {
    const token = 'eb' + (++_emailBodySeq);
    let cleaned;
    if (typeof messageBodyHtml === 'function') {
        cleaned = messageBodyHtml(rawHtml, bodyType, { attachmentBase: API_BASE });
    } else {
        // safe-html.js missing → fail closed, never render raw.
        console.error('FreeITSM: assets/js/safe-html.js did not load — message bodies are being shown as plain text.');
        cleaned = safeEmailHtml(rawHtml);
    }
    _emailBodyPending.set(token, cleaned);
    return `<div class="${cls}" data-email-body="${token}"></div>`;
}

function hydrateEmailBodies(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('[data-email-body]').forEach(host => {
        const token = host.getAttribute('data-email-body');
        host.removeAttribute('data-email-body');
        if (!_emailBodyPending.has(token)) return;
        const bodyHtml = _emailBodyPending.get(token);
        _emailBodyPending.delete(token);
        try {
            const shadow = host.attachShadow({ mode: 'open' });
            // Belt-and-braces content-box (the app's `*` reset can't reach in here,
            // but keep it explicit); everything else the email brings itself.
            shadow.innerHTML = '<style>*{box-sizing:content-box}</style>' + bodyHtml;
        } catch (e) {
            // Shadow DOM unsupported (very old browser): fall back to inline render
            // — already sanitised, just without the CSS isolation.
            host.innerHTML = bodyHtml;
        }
    });
    /* …and now they are in the document, so they can be measured. One
       call here covers the thread view and the single-email view, because
       both go through this function. */
    mcSweep(root);
}

/* =====================================================================
   COLLAPSING LONG MESSAGES  (discussion #104)

   A long email buries the thing you opened the ticket to read. Gmail's
   "···" is the shape everybody knows, so this is that.

   🔑 MEASURED, NOT COUNTED. The request asked for a line threshold; real
   inbound mail says lines are the wrong unit. A vendor notification laid
   out in a <table> is a handful of source lines and renders about a metre
   tall. So the trigger is the message's RENDERED height — the only number
   that matches what somebody has to scroll past. The SETTING is still
   phrased in lines because that is a sentence an administrator can reason
   about; includes/ticket_display.php does the conversion, once.

   ⚠️ NOTHING IS REMOVED. The whole message is in the page the whole time,
   behind a `max-height`. Boundary detection for quoted text is genuinely
   hard — the best-known solver quotes 98% on ordinary replies and names
   forwarded HTML as its weak spot — so being wrong here costs a tap, and
   can never cost a fact. That is the difference between this and stripping.
   ===================================================================== */
const MC = window.MESSAGE_COLLAPSE || {};
const MC_KEY = 'freeitsm_expanded_messages';

/* Which messages this analyst has opened, so a thread they are working
   through does not re-collapse under them on every refresh. Per browser,
   deliberately: it is a reading position, not a preference worth a round
   trip. ⚠️ Wrapped, because localStorage THROWS in a private window rather
   than returning null. */
function mcOpened() {
    if (!MC.collapse_remember) return new Set();
    try { return new Set(JSON.parse(localStorage.getItem(MC_KEY) || '[]')); }
    catch (e) { return new Set(); }
}
function mcRemember(id, open) {
    if (!MC.collapse_remember || !id) return;
    try {
        const set = mcOpened();
        open ? set.add(id) : set.delete(id);
        // Keep the last 400: a reading position from six months ago is not
        // worth carrying, and localStorage has a hard quota that throws.
        localStorage.setItem(MC_KEY, JSON.stringify([...set].slice(-400)));
    } catch (e) { /* private window — the setting simply does not persist */ }
}

/**
 * Collapse one rendered message if it is taller than the threshold.
 *
 * `host` is the div a message was hydrated into; `opts.newest` marks the
 * message the reader actually came for.
 */
function mcApply(host, opts) {
    opts = opts || {};
    if (!MC.collapse_enabled) return;
    if (host.dataset.mcDone) return;              // rendered twice; measure once

    /* ⚠️ Measured AFTER the body is in the document. The HOST is what to
       measure: its height comes from its shadow content, so it reports the
       real rendered height while the shadow root itself has no scrollHeight
       and its first child is the injected <style> — which is 0 tall and would
       quietly make every message look short enough to leave alone. */
    const full = Math.max(host.scrollHeight, host.offsetHeight);
    const limit = MC.collapse_px || 264;
    /* A message flagged as one that has arrived before is folded away whatever
       its height: its length is not the reason it is noise. */
    const forced = host.classList.contains('mc-force');
    if (!forced && full <= limit + 40) return;    // a shade over is not worth a control

    host.dataset.mcDone = '1';

    const id = opts.id || '';
    const startOpen = !forced && ((opts.newest && MC.collapse_expand_newest) || mcOpened().has(id));

    const wrap = document.createElement('div');
    wrap.className = 'mc-wrap';
    host.parentNode.insertBefore(wrap, host);
    wrap.appendChild(host);
    wrap.style.setProperty('--mc-max', limit + 'px');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mc-toggle';

    function paint(open) {
        wrap.classList.toggle('mc-collapsed', !open);
        btn.textContent = open ? t('tickets.reading.show_less') : t('tickets.reading.show_more');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', () => {
        const open = wrap.classList.contains('mc-collapsed');
        paint(open);
        mcRemember(id, open);
    });
    wrap.appendChild(btn);
    paint(startOpen);
}

/* ---------------------------------------------------------------------
   FOLD THE OLDER PART OF A LONG TICKET  (idea #4 from discussion #104)

   Collapsing a long MESSAGE and collapsing a long TICKET are different
   problems. Eighty short messages defeat a per-message limit completely:
   every one of them is under it, and the ticket is still unreadable.

   So beyond `group_show` recent messages, the rest fold into one line you
   can open. They stay in the page — this moves them, it does not drop
   them — and the count is stated so nobody wonders what is behind it.
   --------------------------------------------------------------------- */
function tgGroupOlder(container) {
    if (!MC.group_older || !container) return;
    const show = Math.max(2, parseInt(MC.group_show, 10) || 6);

    /* A "message" is a meta block plus everything up to the next one. The
       separator, the meta, any duplicate note and the body all have to travel
       together or the fold leaves orphans behind. */
    const metas = [...container.querySelectorAll('.thread-meta')];
    if (metas.length <= show + 1) return;          // folding one message saves nothing

    const older = metas.slice(0, metas.length - show);
    const fold = document.createElement('div');
    fold.className = 'tg-fold';
    container.insertBefore(fold, older[0].previousElementSibling || older[0]);

    older.forEach(meta => {
        const parts = [];
        let n = meta.previousElementSibling;
        if (n && n.classList.contains('thread-separator')) parts.push(n);
        parts.push(meta);
        n = meta.nextElementSibling;
        while (n && !n.classList.contains('thread-meta') && !n.classList.contains('thread-separator')) {
            const next = n.nextElementSibling;
            parts.push(n);
            n = next;
        }
        parts.forEach(el => fold.appendChild(el));
    });

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tg-toggle';
    btn.textContent = t('tickets.reading.older_messages').replace('{n}', older.length);
    btn.setAttribute('aria-expanded', 'false');
    fold.parentNode.insertBefore(btn, fold);
    fold.hidden = true;
    btn.addEventListener('click', () => {
        fold.hidden = !fold.hidden;
        btn.setAttribute('aria-expanded', fold.hidden ? 'false' : 'true');
        btn.textContent = fold.hidden
            ? t('tickets.reading.older_messages').replace('{n}', older.length)
            : t('tickets.reading.hide_older');
    });
}
/* Sweep a freshly rendered thread. The LAST message host is the newest —
   the thread renders oldest-first — and is the one that stays open. */
function mcSweep(root) {
    if (!MC.collapse_enabled || !root || !root.querySelectorAll) return;
    const hosts = root.querySelectorAll('.thread-message-body, .email-body-content');
    hosts.forEach((h, i) => mcApply(h, {
        newest: i === hosts.length - 1,
        id: h.closest('[data-email-id]') ? h.closest('[data-email-id]').getAttribute('data-email-id') : ''
    }));
}

let departments = [];
let ticketTypes = [];
let ticketOrigins = [];
let ticketStatuses = [];
// Multi-tenancy: companies this analyst can move tickets into. Empty / length<=1 on a
// single-company install, so the "Company" picker + wrong-company warning stay hidden.
let moveCompanies = [];
let isMultiCompany = false;
let ticketPriorities = [];   // loaded once at init from get_ticket_priorities.php
let analysts = [];
let currentEmail = null;
let currentRecordings = [];
let folderCounts = {};
// Messaging channels (WhatsApp etc.): set when a ticket's thread loads, so the
// reading pane composes over the channel instead of email. 'email' = normal ticket.
let currentTicketChannel = 'email';
let currentChannelWindowOpen = false;
let currentChannelProvider = '';
let channelTemplates = [];
// Auto-refresh: channel tickets (WhatsApp etc.) poll for new inbound messages every
// 15s while open. lastComposerWindowOpen lets us avoid re-rendering (and wiping) the
// composer on a refresh unless the 24h-window state actually changed.
let channelRefreshTimer = null;
let lastComposerWindowOpen = null;
let currentFilter = { type: 'all' };
let expandedFolders = {};
// Guards against out-of-order list responses - see loadEmails().
let loadEmailsToken = 0;
let currentNotes = [];
let emailEditor = null;
let emailAttachments = [];
let ticketAttachments = []; // Attachments linked to current ticket

// Helper function to log audit entries
async function logAudit(ticketId, fieldName, oldValue, newValue) {
    try {
        await fetch(API_BASE + 'log_ticket_audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: ticketId,
                field_name: fieldName,
                old_value: oldValue,
                new_value: newValue
            })
        });
    } catch (error) {
        console.error('Error logging audit:', error);
    }
}

// Helper to get display name for IDs
function getDisplayName(type, id) {
    if (!id) return null;
    if (type === 'department') {
        const dept = departments.find(d => d.id == id);
        return dept ? dept.name : id;
    } else if (type === 'ticket_type') {
        const tt = ticketTypes.find(t => t.id == id);
        return tt ? tt.name : id;
    } else if (type === 'origin') {
        const o = ticketOrigins.find(x => x.id == id);
        return o ? o.name : id;
    } else if (type === 'owner') {
        const a = analysts.find(x => x.id == id);
        return a ? a.full_name : id;
    }
    return id;
}

// Resolve API base for shared endpoints (api/system/...) — works whether the page is at
// the repo root or inside a module folder.
function sharedApiBase() {
    return API_BASE.replace(/[^/]+\/?$/, '');
}

async function loadFolderGroupingPreference() {
    try {
        const res = await fetch(sharedApiBase() + 'system/get_user_preference.php?key=tickets_folder_grouping');
        const data = await res.json();
        if (data && data.success && (data.value === 'analyst' || data.value === 'department')) {
            folderGrouping = data.value;
        }
    } catch (e) { /* fall back to default */ }
    // Sync the toggle UI to whatever we ended up with
    document.querySelectorAll('.folder-group-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.group === folderGrouping);
    });
}

async function setFolderGrouping(mode) {
    if (mode !== 'department' && mode !== 'analyst') return;
    if (mode === folderGrouping) return;
    folderGrouping = mode;

    // Reset selection back to "All Tickets" so we don't leave a stale dept/analyst filter active
    currentFilter = { type: 'all' };
    document.getElementById('emailListTitle').textContent = t('tickets.list.all_tickets');

    // Update the toggle UI
    document.querySelectorAll('.folder-group-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.group === folderGrouping);
    });

    renderFolders();
    loadEmails();

    // Persist (fire-and-forget)
    fetch(sharedApiBase() + 'system/set_user_preference.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'tickets_folder_grouping', value: folderGrouping })
    }).catch(() => {});
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDepartments();
    loadTicketTypes();
    loadTicketOrigins();
    loadTicketStatuses();
    loadTicketPriorities();
    loadAnalysts();
    loadMoveCompanies();
    loadMultiSelectPanePreference();
    loadFolderGroupingPreference().then(loadFolderCounts);
    initTinyMCE();
    initAttachmentHandlers();

    // Load all tickets by default
    loadEmails();

    // Check for ticket_id in URL and auto-load that ticket
    const urlParams = new URLSearchParams(window.location.search);
    const ticketId = urlParams.get('ticket_id');
    if (ticketId) {
        // Small delay to ensure page is ready, then load the ticket
        setTimeout(() => loadTicketById(ticketId), 500);
    }
});

// Initialize attachment drag/drop and file input handlers
function initAttachmentHandlers() {
    const dropzone = document.getElementById('attachmentDropzone');
    const fileInput = document.getElementById('attachmentInput');

    if (!dropzone || !fileInput) return;

    // File input change handler
    fileInput.addEventListener('change', function(e) {
        handleFiles(e.target.files);
        fileInput.value = ''; // Reset so same file can be selected again
    });

    // Drag and drop handlers
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });

    // Click on dropzone to open file browser
    dropzone.addEventListener('click', function(e) {
        if (e.target.tagName !== 'A') {
            fileInput.click();
        }
    });
}

// Handle selected files
function handleFiles(files) {
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        // Check if file already added
        if (!emailAttachments.some(a => a.name === file.name && a.size === file.size)) {
            emailAttachments.push(file);
        }
    }
    renderAttachments();
}

// Render attachment list
function renderAttachments() {
    const list = document.getElementById('attachmentList');
    if (!list) return;

    if (emailAttachments.length === 0) {
        list.innerHTML = '';
        return;
    }

    list.innerHTML = emailAttachments.map((file, index) => `
        <div class="attachment-item">
            <div class="attachment-info">
                <span class="attachment-icon">${getFileIcon(file.name)}</span>
                <span class="attachment-name">${escapeHtml(file.name)}</span>
                <span class="attachment-size">(${formatFileSize(file.size)})</span>
            </div>
            <button class="attachment-remove" onclick="removeAttachment(${index})" title="Remove">&times;</button>
        </div>
    `).join('');
}

// Remove attachment by index
function removeAttachment(index) {
    emailAttachments.splice(index, 1);
    renderAttachments();
}

// Get file icon based on extension
function getFileIcon(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const icons = {
        'pdf': '📄',
        'doc': '📝', 'docx': '📝',
        'xls': '📊', 'xlsx': '📊',
        'ppt': '📽️', 'pptx': '📽️',
        'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'bmp': '🖼️',
        'zip': '📦', 'rar': '📦', '7z': '📦',
        'txt': '📃',
        'html': '🌐', 'htm': '🌐',
        'mp3': '🎵', 'wav': '🎵',
        'mp4': '🎬', 'avi': '🎬', 'mov': '🎬'
    };
    return icons[ext] || '📎';
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// Initialize TinyMCE editor
function initTinyMCE() {
    // Match the editor chrome + content area to the active palette. TinyMCE ships
    // its own skins (the editor renders in an iframe), so we use the bundled
    // oxide-dark UI skin + dark content CSS rather than CSS overrides. Switching
    // palette reloads the page, so this runs fresh with the right data-theme.
    // The palette declares its own light/dark mode (Theme::THEMES in
    // includes/theme.php), surfaced as data-theme-mode on <html>. TinyMCE ships
    // only a light + a dark skin, so we pick by mode — any new palette (e.g.
    // "Miami Techno") works with no change here, just its registry mode.
    const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';

    tinymce.init({
        selector: '#emailBody',
        license_key: 'gpl',
        height: 350,
        menubar: false,
        skin: isDark ? 'oxide-dark' : 'oxide',
        content_css: isDark ? 'dark' : 'default',
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount'
        ],
        toolbar: 'undo redo | blocks | ' +
            'bold italic forecolor backcolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'link | removeformat | help',
        // 14px on desktop; 16px on touch devices (pointer: coarse) so iOS Safari
        // doesn't auto-zoom when you tap into the reply/forward editor on a phone
        // — the same <16px focus-zoom that broke the note sheet. Mouse users
        // (pointer: fine) are unchanged.
        content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
        extended_valid_elements: 'div[style|data-reply-marker|data-signature]',
        setup: function(editor) {
            emailEditor = editor;
        }
    });
}

// Load departments (filtered by team membership)
async function loadDepartments() {
    try {
        // Use get_my_departments.php which filters based on team membership
        const response = await fetch(API_BASE + 'get_my_departments.php');
        const data = await response.json();

        if (data.success) {
            // Already filtered by API based on team membership
            departments = data.departments;
        }
    } catch (error) {
        console.error('Error loading departments:', error);
    }
}

// Load ticket types
async function loadTicketTypes() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_types.php');
        const data = await response.json();

        if (data.success) {
            ticketTypes = data.ticket_types.filter(t => t.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket types:', error);
    }
}

// Load ticket origins
async function loadTicketOrigins() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_origins.php');
        const data = await response.json();

        if (data.success) {
            ticketOrigins = data.origins.filter(o => o.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket origins:', error);
    }
}

// Load the companies this analyst can move tickets into (multi-company installs only).
async function loadMoveCompanies() {
    try {
        const response = await fetch('../api/system/get_tenants.php?accessible=1');
        const data = await response.json();
        if (data.success) {
            moveCompanies = data.companies || [];
            // Multi-company UI only appears once there's more than one company in total.
            isMultiCompany = moveCompanies.length > 1;
        }
    } catch (error) {
        moveCompanies = [];
        isMultiCompany = false;
    }
}

// Move the current ticket to another company. targetId optional (used by the
// wrong-company banner's quick-move); otherwise read from the Company dropdown.
async function moveTicketCompany(targetId) {
    if (!currentEmail) return;
    const id = targetId || (document.getElementById('companySelect') || {}).value;
    if (!id) return;
    try {
        const res = await fetch(API_BASE + 'move_ticket_to_company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: currentEmail.ticket_id, tenant_id: parseInt(id, 10) })
        });
        const data = await res.json();
        if (data.success) {
            showToast(data.message || 'Ticket moved', 'success');
            currentEmail.tenant_id = parseInt(id, 10);
            // Moving may take the ticket out of the active-company view, so refresh.
            loadFolderCounts();
            loadEmails();
            selectEmail(currentEmail.id); // re-open to refresh the company field + banner
        } else {
            showToast('Could not move ticket: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (e) {
        showToast('Failed to move ticket', 'error');
    }
}

// Load ticket statuses (active only) for the reading-pane Status dropdown
async function loadTicketStatuses() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_statuses.php');
        const data = await response.json();

        if (data.success) {
            ticketStatuses = data.statuses.filter(s => s.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket statuses:', error);
    }
}

// Load ticket priorities (active only) for the reading-pane Priority dropdown
async function loadTicketPriorities() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_priorities.php');
        const data = await response.json();

        if (data.success) {
            ticketPriorities = data.priorities.filter(p => p.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket priorities:', error);
    }
}

// Load analysts
async function loadAnalysts() {
    try {
        const response = await fetch(API_BASE + 'get_analysts.php');
        const data = await response.json();

        if (data.success) {
            analysts = data.analysts.filter(a => a.is_active);
        }
    } catch (error) {
        console.error('Error loading analysts:', error);
    }
}

// Load folder counts
async function loadFolderCounts() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_counts.php');
        const data = await response.json();

        if (data.success) {
            folderCounts = data;
            renderFolders();
        }
    } catch (error) {
        console.error('Error loading folder counts:', error);
    }
}

// Render folder structure. Branches on folderGrouping (department vs analyst).
function renderFolders() {
    const folderListEl = document.getElementById('folderList');

    let html = '';

    // All Tickets folder (GH #73) — expandable, with a count per status, exactly
    // as departments and analysts already are.
    //
    // 🔑 The counts were ALREADY being sent: get_ticket_counts.php has returned
    // `overall_statuses` all along and nothing consumed it. It is scoped by the
    // same $ttSql as total_count, so the children sum to the parent rather than
    // being a second, differently-filtered count that quietly disagrees.
    //
    // The leading 📁/📂 comes from CSS on .folder-item and now earns its place
    // here: on this row it is the expand indicator, the same as on a department.
    const allExpanded  = !!expandedFolders['all'];
    const allStatusMap = folderCounts.overall_statuses || {};
    html += `
        <div class="folder-item ${allExpanded ? 'expanded' : ''} ${currentFilter.type === 'all' ? 'active' : ''}"
             data-folder-key="all" onclick="toggleFolder('all', null, { kind: 'all' })">
            <div class="folder-name">
                <span class="folder-icon">📬</span>
                <span>${escapeHtml(t('tickets.list.all_tickets'))}</span>
            </div>
            <span class="folder-count">${folderCounts.total_count || 0}</span>
        </div>
    `;
    html += `<div class="subfolder-group ${allExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
    (folderCounts.statuses || []).forEach(s => {
        const status = s.name;
        const count  = allStatusMap[status] || 0;
        const subActive = currentFilter.type === 'all_status' && currentFilter.status === status;
        html += `
            <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                 data-drop-type="all_status" data-status="${escapeHtml(status)}">
                <span>${escapeHtml(status)}</span>
                <span class="folder-count">${count}</span>
            </div>
        `;
    });
    html += `</div></div>`;

    // Unassigned folder — semantics depend on grouping mode (no department vs no
    // analyst), and so, therefore, do its status children: the folder is drawn
    // once but answers a different question in each mode, so the counts come from
    // a different map. Both are scoped exactly as their own total is.
    const unassignedCount = folderGrouping === 'analyst'
        ? (folderCounts.unassigned_analyst_count || 0)
        : (folderCounts.unassigned_count || 0);
    const unassignedStatusMap = folderGrouping === 'analyst'
        ? (folderCounts.unassigned_analyst_statuses || {})
        : (folderCounts.unassigned_statuses || {});
    const unassignedExpanded = !!expandedFolders['unassigned'];
    html += `
        <div class="folder-item drop-zone ${unassignedExpanded ? 'expanded' : ''} ${currentFilter.type === 'unassigned' ? 'active' : ''}"
             data-drop-type="unassigned" onclick="toggleFolder('unassigned', null, { kind: 'unassigned' })">
            <div class="folder-name">
                <span class="folder-icon">⚠️</span>
                <span>${escapeHtml(t('tickets.list.unassigned'))}</span>
            </div>
            <span class="folder-count">${unassignedCount}</span>
        </div>
    `;
    html += `<div class="subfolder-group ${unassignedExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
    (folderCounts.statuses || []).forEach(s => {
        const status = s.name;
        const count  = unassignedStatusMap[status] || 0;
        const subActive = currentFilter.type === 'unassigned_status' && currentFilter.status === status;
        html += `
            <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                 data-drop-type="unassigned_status" data-status="${escapeHtml(status)}">
                <span>${escapeHtml(status)}</span>
                <span class="folder-count">${count}</span>
            </div>
        `;
    });
    html += `</div></div>`;

    html += '<div class="folder-divider"></div>';

    if (folderGrouping === 'analyst') {
        const analysts = folderCounts.analysts || [];
        analysts.forEach(an => {
            const folderKey = `analyst_${an.id}`;
            const isExpanded = expandedFolders[folderKey];
            const isActive = currentFilter.type === 'analyst' && currentFilter.id == an.id;

            html += `
                <div class="folder-item drop-zone ${isExpanded ? 'expanded' : ''} ${isActive ? 'active' : ''}"
                     data-drop-type="analyst" data-analyst-id="${an.id}"
                     onclick="toggleFolder('${folderKey}', ${an.id}, { kind: 'analyst' })">
                    <div class="folder-name">
                        <span class="folder-icon">👤</span>
                        <span>${escapeHtml(an.name)}</span>
                    </div>
                    <span class="folder-count">${an.count}</span>
                </div>
            `;

            html += `<div class="subfolder-group ${isExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
            const statuses = (folderCounts.statuses || []).map(s => s.name);
            statuses.forEach(status => {
                const count = (an.statuses || {})[status] || 0;
                const subActive = currentFilter.type === 'analyst_status' && currentFilter.analyst_id == an.id && currentFilter.status === status;
                html += `
                    <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                         data-drop-type="analyst_status" data-analyst-id="${an.id}" data-status="${escapeHtml(status)}">
                        <span>${escapeHtml(status)}</span>
                        <span class="folder-count">${count}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        });
    } else if (folderCounts.departments) {
        folderCounts.departments.forEach(dept => {
            const folderKey = `dept_${dept.id}`;
            const isExpanded = expandedFolders[folderKey];
            const isActive = currentFilter.type === 'department' && currentFilter.id == dept.id;

            html += `
                <div class="folder-item drop-zone ${isExpanded ? 'expanded' : ''} ${isActive ? 'active' : ''}"
                     data-drop-type="department" data-dept-id="${dept.id}"
                     onclick="toggleFolder('${folderKey}', ${dept.id}, { kind: 'department' })">
                    <div class="folder-name">
                        <span class="folder-icon"></span>
                        <span>${escapeHtml(dept.name)}</span>
                    </div>
                    <span class="folder-count">${dept.count}</span>
                </div>
            `;

            html += `<div class="subfolder-group ${isExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
            const statuses = (folderCounts.statuses || []).map(s => s.name);
            statuses.forEach(status => {
                const count = dept.statuses[status] || 0;
                const subActive = currentFilter.type === 'dept_status' && currentFilter.dept_id == dept.id && currentFilter.status === status;
                html += `
                    <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                         data-drop-type="dept_status" data-dept-id="${dept.id}" data-status="${escapeHtml(status)}">
                        <span>${escapeHtml(status)}</span>
                        <span class="folder-count">${count}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        });
    }

    html += '<div class="folder-divider"></div>';

    // Snoozed folder (#933) — tickets that have left the queue until their time
    // comes. Pinned above Trash: both are "not in the working queue", and this is
    // the one you actually visit. Not a drop target, because dropping a ticket
    // here would have to invent a wake time.
    html += `
        <div class="folder-item ${currentFilter.type === 'snoozed' ? 'active' : ''}" data-folder-key="snoozed"
             onclick="selectFolder('snoozed')">
            <div class="folder-name">
                <span class="folder-icon">🌙</span>
                <span>${escapeHtml(t('tickets.list.snoozed'))}</span>
            </div>
            <span class="folder-count">${folderCounts.snoozed_count || 0}</span>
        </div>
    `;

    // Trash folder — soft-deleted tickets, restorable. Pinned to the bottom.
    // It's a drop target (drag a ticket here to trash it) and has its own
    // right-click menu (Empty trash).
    html += `
        <div class="folder-item drop-zone ${currentFilter.type === 'trash' ? 'active' : ''}" data-folder-key="trash" data-drop-type="trash"
             onclick="selectFolder('trash')" oncontextmenu="openTrashContextMenu(event)">
            <div class="folder-name">
                <span class="folder-icon">🗑️</span>
                <span>${escapeHtml(t('tickets.list.trash'))}</span>
            </div>
            <span class="folder-count">${folderCounts.trash_count || 0}</span>
        </div>
    `;

    folderListEl.innerHTML = html;

    // Wire drag-and-drop on freshly rendered folder rows
    attachFolderDropHandlers();
}

// Update only the .active class on existing folder/subfolder rows — does NOT rebuild
// the folder list. Used by selection paths so the .subfolder-group expand transition
// (which requires the element to persist) actually fires.
function updateActiveFolderClasses() {
    const list = document.getElementById('folderList');
    if (!list) return;
    list.querySelectorAll('.folder-item, .subfolder-item').forEach(el => el.classList.remove('active'));

    if (currentFilter.type === 'unassigned_status') {
        const sel = `.subfolder-item[data-drop-type="unassigned_status"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    } else if (currentFilter.type === 'all_status') {
        const sel = `.subfolder-item[data-drop-type="all_status"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    } else if (currentFilter.type === 'all') {
        list.querySelector('[data-folder-key="all"]')?.classList.add('active');
    } else if (currentFilter.type === 'unassigned') {
        list.querySelector('[data-drop-type="unassigned"]')?.classList.add('active');
    } else if (currentFilter.type === 'department') {
        list.querySelector(`[data-drop-type="department"][data-dept-id="${currentFilter.id}"]`)
            ?.classList.add('active');
    } else if (currentFilter.type === 'dept_status') {
        const sel = `.subfolder-item[data-dept-id="${currentFilter.dept_id}"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    } else if (currentFilter.type === 'analyst') {
        list.querySelector(`[data-drop-type="analyst"][data-analyst-id="${currentFilter.id}"]`)
            ?.classList.add('active');
    } else if (currentFilter.type === 'analyst_status') {
        const sel = `.subfolder-item[data-analyst-id="${currentFilter.analyst_id}"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    } else if (currentFilter.type === 'snoozed') {
        list.querySelector('[data-folder-key="snoozed"]')?.classList.add('active');
    } else if (currentFilter.type === 'trash') {
        list.querySelector('[data-folder-key="trash"]')?.classList.add('active');
    }
}

// Toggle folder expansion. Works for both department and analyst folders.
// opts.kind — 'department' (default) or 'analyst'
// opts.selectAfter — if false, don't change the active filter/view (used by drag hover)
// opts.forceExpand — if true, only expand (no toggle), used by drag hover
function toggleFolder(folderId, groupId, opts = {}) {
    const { selectAfter = true, forceExpand = false, kind = 'department' } = opts;
    const wasExpanded = !!expandedFolders[folderId];
    let willBeExpanded;
    if (forceExpand) {
        if (wasExpanded) return;
        willBeExpanded = true;
    } else {
        willBeExpanded = !wasExpanded;
    }
    expandedFolders[folderId] = willBeExpanded;

    // Targeted class flip on the existing nodes so the CSS grid-row transition fires.
    const list = document.getElementById('folderList');
    // ⚠️ The All Tickets row is found by its folder-key: unlike a department or
    // an analyst it has no id and is not a drop target, so the generic
    // [data-drop-type][data-id] lookup below cannot see it.
    let folderRow;
    if (kind === 'all') {
        folderRow = list?.querySelector('.folder-item[data-folder-key="all"]');
    } else if (kind === 'unassigned') {
        folderRow = list?.querySelector('.folder-item[data-drop-type="unassigned"]');
    } else {
        const dataAttr = kind === 'analyst' ? 'data-analyst-id' : 'data-dept-id';
        folderRow = list?.querySelector(`.folder-item[data-drop-type="${kind}"][${dataAttr}="${groupId}"]`);
    }
    const subGroup = folderRow?.nextElementSibling;
    folderRow?.classList.toggle('expanded', willBeExpanded);
    if (subGroup && subGroup.classList.contains('subfolder-group')) {
        subGroup.classList.toggle('expanded', willBeExpanded);
    }

    if (selectAfter) {
        if (kind === 'unassigned') {
            currentFilter = { type: 'unassigned' };
            document.getElementById('emailListTitle').textContent = t('tickets.list.unassigned_tickets');
        } else if (kind === 'all') {
            // Clicking All Tickets still selects All Tickets, exactly as before.
            // Expanding is additive: it must not change what you are looking at.
            currentFilter = { type: 'all' };
            document.getElementById('emailListTitle').textContent = t('tickets.list.all_tickets');
        } else if (kind === 'analyst') {
            currentFilter = { type: 'analyst', id: groupId };
            const an = folderCounts.analysts?.find(a => a.id == groupId);
            document.getElementById('emailListTitle').textContent = an ? an.name : 'Analyst';
        } else {
            currentFilter = { type: 'department', id: groupId };
            const dept = folderCounts.departments?.find(d => d.id == groupId);
            document.getElementById('emailListTitle').textContent = dept ? dept.name : 'Department';
        }
        updateActiveFolderClasses();
        loadEmails();
    }
}

// Select folder
function selectFolder(type, id = null) {
    if (type === 'all') {
        currentFilter = { type: 'all' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.all_tickets');
    } else if (type === 'unassigned') {
        currentFilter = { type: 'unassigned' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.unassigned_tickets');
    } else if (type === 'department') {
        currentFilter = { type: 'department', id: id };
        const dept = folderCounts.departments.find(d => d.id == id);
        document.getElementById('emailListTitle').textContent = dept ? dept.name : t('tickets.folders.group_department');
    } else if (type === 'trash') {
        currentFilter = { type: 'trash' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.trash');
    } else if (type === 'snoozed') {
        currentFilter = { type: 'snoozed' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.snoozed');
    }

    updateActiveFolderClasses();
    loadEmails();
}

// Select department + status
function selectDeptStatus(deptId, status) {
    currentFilter = { type: 'dept_status', dept_id: deptId, status: status };
    const dept = folderCounts.departments.find(d => d.id == deptId);
    document.getElementById('emailListTitle').textContent = `${dept ? dept.name : 'Department'} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// Select a status within Unassigned. ⚠️ What "unassigned" means follows the
// active grouping, so this filter has to carry the grouping with it.
function selectUnassignedStatus(status) {
    currentFilter = { type: 'unassigned_status', status: status };
    document.getElementById('emailListTitle').textContent = `${t('tickets.list.unassigned_tickets')} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// Select a status across every ticket (GH #73) — the All Tickets equivalent of
// selectDeptStatus / selectAnalystStatus.
function selectAllStatus(status) {
    currentFilter = { type: 'all_status', status: status };
    document.getElementById('emailListTitle').textContent = `${t('tickets.list.all_tickets')} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// Select analyst + status
function selectAnalystStatus(analystId, status) {
    currentFilter = { type: 'analyst_status', analyst_id: analystId, status: status };
    const an = folderCounts.analysts?.find(a => a.id == analystId);
    document.getElementById('emailListTitle').textContent = `${an ? an.name : 'Analyst'} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// ===== Drag-and-drop: tickets onto folders =====
let draggedTicketId = null;
let draggedTicketNumber = null;
// Every ticket travelling in the current drag. Length 1 for an ordinary drag, so
// the single-ticket path below stays the path it always was.
let draggedTicketIds = [];
let dragHoverTimer = null;
let dragHoverFolderId = null;

function attachEmailDragHandlers() {
    document.querySelectorAll('#emailList .email-item').forEach(el => {
        el.addEventListener('dragstart', (e) => {
            const mailId = Number(el.dataset.emailId);

            // Dragging a row that is part of the selection drags the WHOLE
            // selection — same rule as the right-click menu, for the same reason.
            // Dragging a row outside it collapses the selection to that row first,
            // so you can never drop a set you thought you had deselected.
            if (!selectedEmailIds.has(mailId)) {
                selectedEmailIds  = new Set([mailId]);
                selectionAnchorId = mailId;
                selectionFocusId  = mailId;
                renderSelectionUi();
            }
            draggedTicketIds = selectedTicketIds();

            draggedTicketId = el.dataset.ticketId;
            draggedTicketNumber = el.dataset.ticketNumber;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedTicketId);

            // Every dragged row dims, not just the one under the cursor, so the drag
            // visibly carries the set. Driven from the selection SET rather than from
            // whichever rows happen to be painted `.selected`, so the dimming can
            // never disagree with what is actually being dragged.
            selectedEmailIds.forEach(id => {
                const r = document.querySelector(`#emailList .email-item[data-email-id="${id}"]`);
                if (r) r.classList.add('dragging');
            });

            // Dragging several shows a STACK OF SHEETS with the count on it, rather
            // than the browser's default ghost of the single row under the cursor —
            // which would say "one ticket" while carrying twelve. Three offset,
            // slightly-rotated sheets read as "a pile" at a glance; the badge gives
            // the exact number.
            //
            // Built from real child elements, not ::before/::after, because
            // setDragImage rasterises the element and pseudo-element support in that
            // snapshot is not something to rely on across browsers. It has to be in
            // the document to rasterise at all, so it is parked off-screen and
            // removed on the next tick — by then the browser has taken its bitmap.
            if (draggedTicketIds.length > 1 && e.dataTransfer.setDragImage) {
                const ghost = document.createElement('div');
                ghost.className = 'drag-stack-ghost';
                ghost.innerHTML =
                    '<span class="drag-sheet drag-sheet-3"></span>' +
                    '<span class="drag-sheet drag-sheet-2"></span>' +
                    '<span class="drag-sheet drag-sheet-1">' +
                        '<span class="drag-sheet-line"></span>' +
                        '<span class="drag-sheet-line short"></span>' +
                        '<span class="drag-sheet-line"></span>' +
                    '</span>' +
                    '<span class="drag-stack-badge">' + draggedTicketIds.length + '</span>';
                document.body.appendChild(ghost);
                // Grab point near the top-left of the front sheet, so the stack sits
                // under the pointer the way a picked-up pile would.
                e.dataTransfer.setDragImage(ghost, 26, 22);
                setTimeout(() => ghost.remove(), 0);
            }
        });
        el.addEventListener('dragend', () => {
            document.querySelectorAll('#emailList .email-item.dragging').forEach(r => r.classList.remove('dragging'));
            draggedTicketId = null;
            draggedTicketNumber = null;
            draggedTicketIds = [];
            cancelDragHover();
            document.querySelectorAll('.drop-target').forEach(t => t.classList.remove('drop-target'));
        });
    });
}

function attachFolderDropHandlers() {
    // Click handler for subfolder rows (delegated so status names with apostrophes are safe)
    document.querySelectorAll('#folderList .subfolder-item').forEach(el => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropType = el.dataset.dropType;
            const status = el.dataset.status;
            if (dropType === 'unassigned_status') {
                if (status) selectUnassignedStatus(status);
            } else if (dropType === 'all_status') {
                if (status) selectAllStatus(status);
            } else if (dropType === 'analyst_status') {
                const analystId = parseInt(el.dataset.analystId, 10);
                if (analystId && status) selectAnalystStatus(analystId, status);
            } else {
                const deptId = parseInt(el.dataset.deptId, 10);
                if (deptId && status) selectDeptStatus(deptId, status);
            }
        });
    });

    document.querySelectorAll('#folderList .drop-zone').forEach(el => {
        el.addEventListener('dragover', (e) => {
            if (!draggedTicketId) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            el.classList.add('drop-target');

            // Hover-to-expand on collapsed group folders (works for both dept and analyst)
            const dt = el.dataset.dropType;
            if (dt === 'department' || dt === 'analyst' || dt === 'unassigned') {
                // ⚠️ Unassigned has no id, so it cannot go through the `${kind}_${id}`
                // key the other two use — that would give 'dept_undefined' and the
                // hover would silently expand nothing.
                const groupId  = dt === 'unassigned' ? null
                               : (dt === 'analyst' ? el.dataset.analystId : el.dataset.deptId);
                const folderId = dt === 'unassigned' ? 'unassigned'
                               : `${dt === 'analyst' ? 'analyst' : 'dept'}_${groupId}`;
                if (!expandedFolders[folderId]) {
                    if (dragHoverFolderId !== folderId) {
                        cancelDragHover();
                        dragHoverFolderId = folderId;
                        dragHoverTimer = setTimeout(() => {
                            toggleFolder(folderId, groupId, { selectAfter: false, forceExpand: true, kind: dt });
                            dragHoverTimer = null;
                        }, 600);
                    }
                }
            }
        });
        el.addEventListener('dragleave', (e) => {
            el.classList.remove('drop-target');
            const dt = el.dataset.dropType;
            // Only cancel hover timer if leaving the row that started it
            if (dt === 'department' && dragHoverFolderId === `dept_${el.dataset.deptId}`) {
                cancelDragHover();
            } else if (dt === 'analyst' && dragHoverFolderId === `analyst_${el.dataset.analystId}`) {
                cancelDragHover();
            }
        });
        el.addEventListener('drop', (e) => {
            e.preventDefault();
            el.classList.remove('drop-target');
            cancelDragHover();
            if (!draggedTicketId) return;
            handleTicketDrop(el, draggedTicketId, draggedTicketNumber);
        });
    });
}

function cancelDragHover() {
    if (dragHoverTimer) {
        clearTimeout(dragHoverTimer);
        dragHoverTimer = null;
    }
    dragHoverFolderId = null;
}

async function handleTicketDrop(targetEl, ticketId, ticketNumber) {
    const dropType = targetEl.dataset.dropType;

    // Dropping onto the Trash folder soft-deletes the ticket.
    if (dropType === 'trash') {
        if (draggedTicketIds.length > 1) {
            // Dragging to Trash is already a deliberate gesture and the delete is
            // soft, so this does NOT re-ask for confirmation the way the Delete
            // button does — Restore is the undo.
            await applyToSelection('bulk_delete_tickets.php',
                chunk => ({ ticket_ids: chunk }), t('tickets.bulk.label_delete'));
            clearSelectionToNone();
            return;
        }
        try {
            const res = await fetch(API_BASE + 'delete_ticket.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: parseInt(ticketId, 10) })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'failed');
            showToast(`${ticketNumber || 'Ticket'} → Trash`, 'success');
            clearReadingPaneIfTicket(parseInt(ticketId, 10));
            await loadFolderCounts();
            loadEmails();
        } catch (e) { showToast('Move to trash failed: ' + e.message, 'error'); }
        return;
    }

    const payload = { ticket_id: parseInt(ticketId, 10) };
    let toastMsg = '';

    // Capture old values from the in-memory email row for audit logging
    const sourceEmail = emails.find(e => String(e.ticket_id) === String(ticketId));
    const oldDeptName = sourceEmail ? getDisplayName('department', sourceEmail.department_id) : null;
    const oldStatusName = sourceEmail ? sourceEmail.status : null;
    const oldAnalystName = sourceEmail ? getDisplayName('owner', sourceEmail.assigned_analyst_id) : null;

    let newDeptName = null;
    let newStatusName = null;
    let newAnalystName = null;

    // "Unassigned" target means different things depending on the active grouping
    if (dropType === 'unassigned_status') {
        // Both halves of what the row means: unassigned in the sense the
        // current grouping uses, plus the status it sits under.
        if (folderGrouping === 'analyst') {
            payload.assigned_analyst_id = '';
        } else {
            payload.department_id = '';
        }
        payload.status = targetEl.dataset.status;
        newStatusName = payload.status;
        newDeptName = folderGrouping === 'analyst' ? newDeptName : null;
        toastMsg = `${ticketNumber || 'Ticket'} → Unassigned / ${payload.status}`;
    } else if (dropType === 'unassigned') {
        if (folderGrouping === 'analyst') {
            payload.assigned_analyst_id = '';
            toastMsg = `${ticketNumber || 'Ticket'} → Unassigned (no analyst)`;
        } else {
            payload.department_id = '';
            toastMsg = `${ticketNumber || 'Ticket'} → Unassigned`;
        }
    } else if (dropType === 'department') {
        payload.department_id = parseInt(targetEl.dataset.deptId, 10);
        const dept = folderCounts.departments.find(d => d.id == payload.department_id);
        newDeptName = dept ? dept.name : null;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newDeptName || 'Department'}`;
    } else if (dropType === 'all_status') {
        // Only the status. Dropping onto a status under All Tickets says nothing
        // about department or owner, so it must not quietly change either.
        payload.status = targetEl.dataset.status;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${payload.status}`;
    } else if (dropType === 'dept_status') {
        payload.department_id = parseInt(targetEl.dataset.deptId, 10);
        payload.status = targetEl.dataset.status;
        const dept = folderCounts.departments.find(d => d.id == payload.department_id);
        newDeptName = dept ? dept.name : null;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newDeptName || 'Department'} / ${payload.status}`;
    } else if (dropType === 'analyst') {
        payload.assigned_analyst_id = parseInt(targetEl.dataset.analystId, 10);
        const an = folderCounts.analysts?.find(a => a.id == payload.assigned_analyst_id);
        newAnalystName = an ? an.name : null;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newAnalystName || 'Analyst'}`;
    } else if (dropType === 'analyst_status') {
        payload.assigned_analyst_id = parseInt(targetEl.dataset.analystId, 10);
        payload.status = targetEl.dataset.status;
        const an = folderCounts.analysts?.find(a => a.id == payload.assigned_analyst_id);
        newAnalystName = an ? an.name : null;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newAnalystName || 'Analyst'} / ${payload.status}`;
    } else {
        return;
    }

    // Multi-drag: the payload above already says what the drop MEANS; send it for
    // every dragged ticket through the same service the single drop uses. The
    // values are passed through byte-for-byte (including '' for "clear"), so a
    // dropped set is treated exactly as N dropped tickets would have been.
    if (draggedTicketIds.length > 1) {
        const fields = Object.assign({}, payload);
        delete fields.ticket_id;
        await bulkSetField(fields, t('tickets.bulk.label_move'));
        return;
    }

    try {
        const res = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Update failed');

        // Audit log — only for fields that actually changed
        const ticketIdInt = parseInt(ticketId, 10);
        const auditCalls = [];
        if (newDeptName !== oldDeptName && (dropType === 'department' || dropType === 'dept_status' || ((dropType === 'unassigned' || dropType === 'unassigned_status') && folderGrouping !== 'analyst'))) {
            auditCalls.push(logAudit(ticketIdInt, 'Department', oldDeptName, newDeptName));
        }
        if (newStatusName !== null && newStatusName !== oldStatusName) {
            auditCalls.push(logAudit(ticketIdInt, 'Status', oldStatusName, newStatusName));
        }
        if (dropType === 'analyst' || dropType === 'analyst_status' || ((dropType === 'unassigned' || dropType === 'unassigned_status') && folderGrouping === 'analyst')) {
            if (newAnalystName !== oldAnalystName) {
                auditCalls.push(logAudit(ticketIdInt, 'Owner', oldAnalystName, newAnalystName));
            }
        }
        await Promise.all(auditCalls);

        showToast(toastMsg, 'success');
        await loadFolderCounts();
        loadEmails();

        // If the dragged ticket is the one open in the reading pane, refresh it
        // so the Department/Status dropdowns show the new values.
        if (currentEmail && String(currentEmail.ticket_id) === String(ticketId)) {
            loadTicketById(currentEmail.ticket_id);
        }
    } catch (err) {
        console.error('Drop assign error:', err);
        showToast('Failed to move ticket: ' + (err.message || err), 'error');
    }
}

// Load emails based on current filter
async function loadEmails() {
    try {
        let url = API_BASE + 'get_emails.php?';

        if (currentFilter.type === 'unassigned_status') {
            // Same rule as the plain Unassigned folder: which column is NULL
            // depends on how the folder list is grouped.
            const base = folderGrouping === 'analyst' ? 'assignee_id=unassigned' : 'department_id=unassigned';
            url += `${base}&status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'all_status') {
            // Status alone, with no department or assignee — get_emails.php already
            // supports exactly this, which is why GH #73 needed no backend work.
            url += `status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'unassigned') {
            // "Unassigned" semantics depend on the active grouping
            url += folderGrouping === 'analyst' ? 'assignee_id=unassigned' : 'department_id=unassigned';
        } else if (currentFilter.type === 'department') {
            url += `department_id=${currentFilter.id}`;
        } else if (currentFilter.type === 'dept_status') {
            url += `department_id=${currentFilter.dept_id}&status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'analyst') {
            url += `assignee_id=${currentFilter.id}`;
        } else if (currentFilter.type === 'analyst_status') {
            url += `assignee_id=${currentFilter.analyst_id}&status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'trash') {
            url += 'trashed=1';
        } else if (currentFilter.type === 'snoozed') {
            url += 'snoozed=1';
        }

        // 🔴 LAST RESPONSE WINS, NOT LAST CLICK. Two folder clicks in quick
        // succession start two fetches, and without this the SLOWER one paints
        // the list - so clicking All Tickets and then a status under it could
        // leave you looking at every ticket while the status sits highlighted.
        // Caught by driving the real page: the status list was replaced by the
        // 96-row All Tickets response that landed after it.
        const token = ++loadEmailsToken;
        const response = await fetch(url);
        const data = await response.json();
        if (token !== loadEmailsToken) return;   // a newer request has overtaken us

        if (data.success) {
            emails = data.emails;
            renderEmailList();
        } else {
            showToast('Error loading emails: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to load emails', 'error');
    }
}

// Render email list
function renderEmailList() {
    const emailListEl = document.getElementById('emailList');

    if (emails.length === 0) {
        emailListEl.innerHTML = '<div class="reading-pane-empty">No tickets found</div>';
        return;
    }

    const inTrash = currentFilter.type === 'trash';
    emailListEl.innerHTML = emails.map(email => {
        const snoozePill = snoozeRowPill(email.snoozed_until, email.snooze_reason);
        const emailCount = email.email_count || 1;
        const countBadge = emailCount > 1 ? `<span class="email-count-badge">${emailCount}</span>` : '';
        const ticketId = email.ticket_id || email.id;
        const trashActions = inTrash ? `
                <div style="display:flex;gap:8px;margin-top:7px;">
                    <button onclick="event.stopPropagation(); restoreTicketFromTrash(${ticketId})" style="font-size:11px;padding:3px 9px;border:1px solid #c8d6cf;background:#eefaf2;color:#1b7a43;border-radius:4px;cursor:pointer;">↩ Restore</button>
                    <button onclick="event.stopPropagation(); permanentlyDeleteFromTrash(${ticketId}, '${escapeHtml(email.ticket_number || '')}')" style="font-size:11px;padding:3px 9px;border:1px solid #e6c4c4;background:#fdeceb;color:#b71c1c;border-radius:4px;cursor:pointer;">✕ Delete forever</button>
                </div>` : '';
        // Reserve a slot for the SLA dot; populated asynchronously by loadInboxSlaIndicators()
        // once the batch endpoint responds. Stays empty (and invisible) for tickets without SLA.
        return `
            <div class="email-item ${email.id === selectedEmailId ? 'selected' : ''} ${!email.is_read ? 'unread' : ''}"
                 draggable="true" data-email-id="${email.id}" data-ticket-id="${ticketId}" data-ticket-number="${escapeHtml(email.ticket_number || '')}"
                 onclick="handleEmailRowClick(event, ${email.id})" ondblclick="selectEmailFullScreen(${email.id})"
                 oncontextmenu="openTicketContextMenu(event, ${ticketId}, '${escapeHtml(email.ticket_number || '')}')">
                ${inboxRowStripes(email)}${inboxRowBlocks(email)}
                <div class="email-from">${escapeHtml(email.ticket_number || '')} - ${senderLabel(email.from_name, email.from_address, false)} ${countBadge}</div>
                <div class="email-subject">${escapeHtml(email.subject)}</div>
                <div class="email-preview">${escapeHtml(email.body_preview || '')}</div>
                <div class="email-footer-row">
                    <div class="email-time">${formatDateTime(email.received_datetime)}</div>
                    ${inboxRowChips(email)}
                    ${snoozePill}
                    <div class="email-sla-slot" data-sla-slot="${ticketId}"></div>
                </div>${trashActions}
            </div>
        `;
    }).join('');

    // Wire drag handlers on freshly rendered email rows
    attachEmailDragHandlers();

    // The rows are new DOM, so the selection highlight has to be repainted. Rows
    // that scrolled out of the result set drop out of the selection here too —
    // acting on a ticket you can no longer see is worse than losing it from the set.
    if (selectedEmailIds && selectedEmailIds.size) {
        const present = new Set(visibleEmailIds());
        Array.from(selectedEmailIds).forEach(id => { if (!present.has(id)) selectedEmailIds.delete(id); });
        renderSelectionUi();
    }

    // Fire-and-forget batch SLA fetch to colour the dots in
    loadInboxSlaIndicators();
}

/**
 * Populate the SLA dot in each email row via the batch endpoint.
 *
 * One request per render covers every visible row (cap = 200 server-side).
 * Tickets without SLA simply don't come back in the response, so their slot
 * stays empty. Re-rendering the list (e.g. on filter change) re-runs this.
 */
async function loadInboxSlaIndicators() {
    const slots = document.querySelectorAll('#emailList [data-sla-slot]');
    if (!slots.length) return;
    const ids = Array.from(slots).map(el => el.getAttribute('data-sla-slot')).filter(Boolean);
    if (!ids.length) return;
    try {
        const res = await fetch(API_BASE + 'get_tickets_sla_batch.php?ticket_ids=' + encodeURIComponent(ids.join(',')));
        const data = await res.json();
        if (!data.success || !data.sla) return;
        slots.forEach(slot => {
            const id = slot.getAttribute('data-sla-slot');
            const row = data.sla[id];
            if (!row) return;
            slot.innerHTML = renderInboxSlaIndicator(row);
        });
    } catch (e) {
        console.error('Batch SLA load failed:', e);
    }
}

/**
 * Build the inline dot + label for one email row.
 *
 * Surfaces the *more urgent* of response / resolution — if response is still
 * outstanding it wins (analysts care about the first thing on the clock).
 * Once response is achieved, we follow the resolution target until it lands.
 */
function renderInboxSlaIndicator(row) {
    const pickTarget = () => {
        const r = row.response;
        const f = row.resolution;
        if (r && r.achieved_at === null) return { t: r, label: 'R' };
        if (f && f.achieved_at === null) return { t: f, label: 'F' };
        if (f) return { t: f, label: 'F' };
        if (r) return { t: r, label: 'R' };
        return null;
    };
    const pick = pickTarget();
    if (!pick) return '';
    const { t, label } = pick;
    let cls = 'sla-ok';
    if (t.achieved_at !== null) {
        cls = t.breached ? 'sla-breached' : 'sla-achieved';
    } else if (t.breached) {
        cls = 'sla-breached';
    } else if (t.percent >= 80) {
        cls = 'sla-warning';
    }
    const fmt = (mins) => {
        if (mins === null || mins === undefined) return '';
        const n = Math.abs(mins);
        const sign = mins < 0 ? '-' : '';
        if (n < 60) return sign + n + 'm';
        const h = Math.floor(n / 60), r = n % 60;
        return sign + (r ? `${h}h${r}m` : `${h}h`);
    };
    const text = t.achieved_at !== null
        ? (t.breached ? 'breached' : 'met')
        : (t.breached ? `+${fmt(Math.abs(t.remaining_minutes))}` : fmt(t.remaining_minutes));
    const priorityName = row.priority ? row.priority.name : '';
    const title = `${priorityName} SLA · ${label === 'R' ? 'Response' : 'Resolution'} · ${text}`;
    return `<span class="email-sla-pill ${cls}" title="${escapeHtml(title)}">
                <span class="email-sla-dot"></span>${escapeHtml(label)} ${escapeHtml(text)}
            </span>`;
}

// Move the "selected" highlight to one row without rebuilding the whole list.
// Rebuilding (renderEmailList) on every click also re-fired the batch SLA fetch,
// which made the SLA pills flash off and back on — this just toggles a class.
function setSelectedEmailRow(emailId) {
    // With a block selected, the multi-select painter owns the highlight — this
    // function would otherwise strip `.selected` off the other rows and leave the
    // list looking like one ticket is selected while an action hits several.
    if (selectedEmailIds && selectedEmailIds.size > 1) { renderSelectionUi(); return; }
    document.querySelectorAll('#emailList .email-item.selected')
        .forEach(el => el.classList.remove('selected'));
    const row = document.querySelector(`#emailList .email-item[data-email-id="${emailId}"]`);
    if (row) row.classList.add('selected');
}

// ===========================================================================
// Multi-select (#910)
// ===========================================================================
//
// The Explorer/Outlook model, because that is the one every analyst already has
// in their fingers:
//
//   click              select just this one, and open it
//   Ctrl+click         toggle this one in or out, leaving the rest alone
//   Shift+click        select the block from the ANCHOR to here
//   Ctrl+Shift+click   extend the block without discarding what is already picked
//
// Deliberately NO checkboxes. A checkbox column costs horizontal space on every
// row forever to serve the rare case, and it trains people to reach for the mouse
// when the keyboard is faster.
//
// THE ANCHOR IS THE PART PEOPLE GET WRONG. Shift+click extends from the last
// PLAIN click (the anchor), not from the last row touched. That is what makes
// "click row 3, shift+click row 9, shift+click row 6" narrow the block to 3-6
// instead of leaving 3-9 stuck. Ctrl+click moves the anchor to itself, so a
// Ctrl+click followed by a Shift+click ranges from the Ctrl+clicked row.
//
// `selectedEmailIds` is the truth. `selectedEmailId` (singular, pre-existing)
// stays as "what the reading pane is showing" so every existing caller keeps
// working untouched.

let selectedEmailIds   = new Set();   // every row in the selection
let selectionAnchorId  = null;        // where a Shift range measures from
let selectionFocusId   = null;        // the keyboard cursor

/** Email ids in the order they are on screen — range selection follows what you SEE. */
function visibleEmailIds() {
    return Array.from(document.querySelectorAll('#emailList .email-item'))
        .map(el => Number(el.dataset.emailId));
}

/** The ticket id behind a row (actions work on tickets; rows are keyed by email). */
function ticketIdForEmail(emailId) {
    const row = document.querySelector(`#emailList .email-item[data-email-id="${emailId}"]`);
    if (row && row.dataset.ticketId) return Number(row.dataset.ticketId);
    const rec = emails.find(e => e.id == emailId);
    return rec ? Number(rec.ticket_id) : null;
}

function selectedTicketIds() {
    return Array.from(selectedEmailIds).map(ticketIdForEmail).filter(id => id);
}

function selectionCount() {
    return selectedEmailIds.size;
}

/** Paint the list: `.selected` on every chosen row, `.kb-focus` on the cursor. */
function renderSelectionUi() {
    document.querySelectorAll('#emailList .email-item').forEach(el => {
        const id = Number(el.dataset.emailId);
        el.classList.toggle('selected', selectedEmailIds.has(id));
        el.classList.toggle('kb-focus', selectionFocusId === id);
    });
    // Suppress the browser's blue text-drag highlight while a block is selected —
    // Shift+click across rows would otherwise smear text selection over the list.
    // NOTE: this class alone cannot prevent the FIRST shift-click's smear, because
    // the browser starts selecting on mousedown and this runs on click. The
    // mousedown handler further down is what actually prevents it; this stays for
    // the drag case.
    const list = document.getElementById('emailList');
    if (list) list.classList.toggle('multi-selecting', selectedEmailIds.size > 1);
    // If a selection did start anyway, clear it rather than leave it smeared.
    const sel = window.getSelection && window.getSelection();
    if (sel && !sel.isCollapsed && selectedEmailIds.size > 1) sel.removeAllRanges();
    updateSelectionSurfaces();
}

function clearMultiSelection({ repaint = true } = {}) {
    selectedEmailIds.clear();
    if (selectedEmailId) selectedEmailIds.add(selectedEmailId);
    selectionAnchorId = selectedEmailId;
    selectionFocusId  = selectedEmailId;
    if (repaint) renderSelectionUi();
}

/**
 * The one entry point for a click on a row. Every modifier combination lands here
 * so the rules live in one readable place rather than spread across handlers.
 */
function handleEmailRowClick(event, emailId) {
    const ctrl  = event.ctrlKey || event.metaKey;   // ⌘ on a Mac does the same job
    const shift = event.shiftKey;

    if (shift) {
        // Shift, with or without Ctrl: take the block from the anchor to here.
        // Plain Shift replaces the selection; Ctrl+Shift adds the block to it.
        const ids   = visibleEmailIds();
        const from  = ids.indexOf(selectionAnchorId !== null ? selectionAnchorId : emailId);
        const to    = ids.indexOf(emailId);
        if (from === -1 || to === -1) return;

        const block = ids.slice(Math.min(from, to), Math.max(from, to) + 1);
        if (!ctrl) selectedEmailIds.clear();
        block.forEach(id => selectedEmailIds.add(id));

        // The anchor deliberately STAYS PUT so a second Shift+click re-measures
        // from the same origin and can shrink the block as well as grow it.
        selectionFocusId = emailId;
        renderSelectionUi();
        onSelectionChanged({ clickedId: emailId });
        return;
    }

    if (ctrl) {
        // Toggle this one. Ctrl+clicking the only selected row leaves nothing
        // selected, which is legitimate — the reading pane empties.
        if (selectedEmailIds.has(emailId)) selectedEmailIds.delete(emailId);
        else                               selectedEmailIds.add(emailId);
        selectionAnchorId = emailId;
        selectionFocusId  = emailId;
        renderSelectionUi();
        onSelectionChanged({ clickedId: emailId });
        return;
    }

    // Plain click — collapse to this one and open it, exactly as before.
    selectedEmailIds = new Set([emailId]);
    selectionAnchorId = emailId;
    selectionFocusId  = emailId;
    renderSelectionUi();
    selectEmail(emailId);
}

// ---------------------------------------------------------------------------
// What the screen does when more than one is selected — the analyst's choice.
// ---------------------------------------------------------------------------
//
// There is no single right answer here, so it is a per-analyst preference
// (`tickets_multiselect_pane`, set in the account menu → Preferences):
//
//   summary  the reading pane becomes a "5 tickets selected" panel with the
//            actions on it. The default: what you are about to act on is on
//            screen, and the actions are discoverable without right-clicking.
//   keep     the reading pane carries on showing the ticket you opened, with a
//            warning strip — because the pane showing ONE while an action hits
//            FIVE is exactly the mistake worth warning about.
//   bar      a compact strip above the list instead, keeping the actions beside
//            the rows they affect.
//
// All three share the same actions and the same code path; only the container
// differs. Nothing below decides what an action DOES.

let multiSelectPaneMode = 'summary';

async function loadMultiSelectPanePreference() {
    try {
        const res  = await fetch(sharedApiBase() + 'system/get_user_preference.php?key=tickets_multiselect_pane');
        const data = await res.json();
        if (data.success && data.value && ['summary', 'keep', 'bar'].includes(data.value)) {
            multiSelectPaneMode = data.value;
        }
    } catch (e) { /* default stands */ }
}

/** Called whenever the selection changes. Routes to whichever surface is chosen. */
function onSelectionChanged({ clickedId = null } = {}) {
    const n = selectionCount();

    if (n === 0) {
        hideSelectionBar();
        hideKeepModeWarning();
        selectedEmailId = null;
        currentEmail = null;
        const pane = document.getElementById('readingPane');
        if (pane) pane.innerHTML = `<div class="reading-pane-empty">${escapeHtml(t('tickets.reading_pane.select_ticket'))}</div>`;
        return;
    }

    if (n === 1) {
        // Back to ordinary single-ticket behaviour, whatever the mode.
        hideSelectionBar();
        hideKeepModeWarning();
        const only = Array.from(selectedEmailIds)[0];
        if (only !== selectedEmailId) selectEmail(only);
        return;
    }

    // n > 1
    if (multiSelectPaneMode === 'summary') {
        hideSelectionBar();
        hideKeepModeWarning();
        renderSelectionSummaryPane();
    } else if (multiSelectPaneMode === 'bar') {
        hideKeepModeWarning();
        renderSelectionBar();
        // If nothing has ever been opened, the pane would sit empty and confusing;
        // open whatever was clicked so there is context beside the bar.
        if (!currentEmail && clickedId) selectEmail(clickedId);
    } else { // 'keep'
        hideSelectionBar();
        if (!currentEmail && clickedId) selectEmail(clickedId);
        renderKeepModeWarning();
    }
}

/**
 * Re-run the surface rendering without recomputing the selection.
 *
 * The n <= 1 branch is NOT optional tidying. A plain click already collapses the
 * selection correctly, but without this the bar (or the "keep" warning strip) was
 * left on screen still reading "5 tickets selected" while exactly one was held —
 * a surface lying about what an action would hit. Every path that changes the
 * selection funnels through renderSelectionUi() into here, so hiding belongs at
 * this one choke point rather than at each of the callers.
 */
function updateSelectionSurfaces() {
    const n = selectionCount();
    if (n <= 1) { hideSelectionBar(); hideKeepModeWarning(); return; }
    if (multiSelectPaneMode === 'summary')    renderSelectionSummaryPane();
    else if (multiSelectPaneMode === 'bar')   renderSelectionBar();
    else                                      renderKeepModeWarning();
}

/** The shared action buttons, used by both the summary pane and the bar. */
function selectionActionsHtml(compact) {
    const cls = compact ? 'btn btn-secondary sel-act-btn' : 'btn btn-secondary';
    return `
        <button type="button" class="${cls}" onclick="openSelectionMenu(event, 'assignee')">${escapeHtml(t('tickets.bulk.assign'))} ▾</button>
        <button type="button" class="${cls}" onclick="openSelectionMenu(event, 'status')">${escapeHtml(t('tickets.bulk.status'))} ▾</button>
        <button type="button" class="${cls}" onclick="openSelectionMenu(event, 'priority')">${escapeHtml(t('tickets.bulk.priority'))} ▾</button>
        <button type="button" class="${cls}" onclick="openSelectionMenu(event, 'department')">${escapeHtml(t('tickets.bulk.department'))} ▾</button>
        <button type="button" class="${cls} sel-act-danger" onclick="bulkDeleteSelection()">${escapeHtml(t('tickets.bulk.delete'))}</button>
    `;
}

function renderSelectionSummaryPane() {
    const pane = document.getElementById('readingPane');
    if (!pane) return;
    const ids  = visibleEmailIds().filter(id => selectedEmailIds.has(id));   // keep list order
    const rows = ids.map(id => {
        const row = document.querySelector(`#emailList .email-item[data-email-id="${id}"]`);
        const ref = row ? (row.dataset.ticketNumber || '') : '';
        const rec = emails.find(e => e.id == id);
        return `<div class="sel-summary-row">
                    <span class="sel-summary-ref">${escapeHtml(ref)}</span>
                    <span class="sel-summary-subj">${escapeHtml(rec ? (rec.subject || '') : '')}</span>
                </div>`;
    }).join('');

    pane.innerHTML = `
        <div class="sel-summary">
            <div class="sel-summary-count">${escapeHtml(t('tickets.bulk.n_selected').replace('%d', ids.length))}</div>
            <div class="sel-summary-list">${rows}</div>
            ${bulkProgressHtml()}
            <div class="sel-summary-actions">${selectionActionsHtml(false)}</div>
            <button type="button" class="sel-summary-clear" onclick="clearSelectionToNone()">${escapeHtml(t('tickets.bulk.clear'))}</button>
            <div class="sel-summary-hint">${escapeHtml(t('tickets.bulk.right_click_hint'))}</div>
        </div>`;
}

function renderSelectionBar() {
    const bar = document.getElementById('selectionBar');
    if (!bar) return;
    // Count + actions wrap together inside sel-bar-main; the ✕ stays pinned to the
    // right of the bar rather than wrapping onto a line of its own.
    bar.innerHTML = `
        <span class="sel-bar-main">
            <span class="sel-bar-count">${escapeHtml(t('tickets.bulk.n_selected').replace('%d', String(selectionCount())))}</span>
            ${bulkProgressHtml()}
            <span class="sel-bar-actions">${selectionActionsHtml(true)}</span>
        </span>
        <button type="button" class="sel-bar-close" onclick="clearSelectionToNone()" title="${escapeHtml(t('tickets.bulk.clear'))}">✕</button>
    `;
    bar.style.display = 'flex';
}

function hideSelectionBar() {
    const bar = document.getElementById('selectionBar');
    if (bar) { bar.style.display = 'none'; bar.innerHTML = ''; }
}

// The strip is CREATED here rather than living in the page markup, because
// selectEmail() replaces the whole reading pane's innerHTML — a static element
// would be destroyed the moment the ticket it warns about finished loading.
// Re-prepending is self-healing and needs no change to the pane's layout.
function renderKeepModeWarning() {
    const pane = document.getElementById('readingPane');
    if (!pane) return;
    let strip = document.getElementById('keepModeWarning');
    if (!strip) {
        strip = document.createElement('div');
        strip.className = 'keep-mode-warning';
        strip.id = 'keepModeWarning';
    }
    strip.innerHTML = `<span>⚠ ${escapeHtml(t('tickets.bulk.keep_warning').replace('%d', String(selectionCount())))}</span>
        ${bulkProgressHtml()}
        <span class="sel-keep-actions">${selectionActionsHtml(true)}</span>
        <button type="button" class="sel-keep-clear" onclick="clearSelectionToNone()">${escapeHtml(t('tickets.bulk.clear'))}</button>`;
    if (strip.parentElement !== pane || pane.firstChild !== strip) {
        pane.insertBefore(strip, pane.firstChild);
    }
    strip.style.display = 'flex';
}

function hideKeepModeWarning() {
    const strip = document.getElementById('keepModeWarning');
    if (strip) strip.remove();
}

/** Clear right down to nothing selected (the ✕ / Escape route). */
function clearSelectionToNone() {
    selectedEmailIds.clear();
    selectionAnchorId = null;
    selectionFocusId  = null;
    selectedEmailId   = null;
    renderSelectionUi();
    onSelectionChanged({});
}

/** Select every row currently listed (Ctrl+A). */
function selectAllVisible() {
    const ids = visibleEmailIds();
    if (!ids.length) return;
    selectedEmailIds = new Set(ids);
    if (selectionAnchorId === null) selectionAnchorId = ids[0];
    selectionFocusId = ids[ids.length - 1];
    renderSelectionUi();
    onSelectionChanged({});
}

// ---------------------------------------------------------------------------
// Keyboard
// ---------------------------------------------------------------------------
//
//   ↑ / ↓              move the cursor and select just that row (and open it)
//   Shift + ↑ / ↓      extend the block from the anchor
//   Ctrl + ↑ / ↓       move the cursor WITHOUT changing the selection
//   Space              toggle the row under the cursor (pairs with Ctrl+arrows)
//   Ctrl + A           select everything listed
//   Escape             clear the selection
//
// Ctrl+arrows + Space is the pair that makes a keyboard-only analyst able to
// build a scattered selection — the keyboard equivalent of Ctrl+click, and the
// reason the cursor is tracked separately from the selection at all.

function moveSelectionCursor(delta, { extend = false, keepSelection = false }) {
    const ids = visibleEmailIds();
    if (!ids.length) return;

    const from = selectionFocusId !== null ? ids.indexOf(selectionFocusId) : -1;
    let next = from === -1 ? (delta > 0 ? 0 : ids.length - 1) : from + delta;
    next = Math.max(0, Math.min(ids.length - 1, next));
    const nextId = ids[next];

    if (keepSelection) {                 // Ctrl+arrow — cursor only
        selectionFocusId = nextId;
        renderSelectionUi();
        scrollRowIntoView(nextId);
        return;
    }

    if (extend) {                        // Shift+arrow — range from the anchor
        if (selectionAnchorId === null) selectionAnchorId = selectionFocusId !== null ? selectionFocusId : nextId;
        const a = ids.indexOf(selectionAnchorId);
        const block = ids.slice(Math.min(a, next), Math.max(a, next) + 1);
        selectedEmailIds = new Set(block);
        selectionFocusId = nextId;
        renderSelectionUi();
        scrollRowIntoView(nextId);
        onSelectionChanged({ clickedId: nextId });
        return;
    }

    // Plain arrow — behave like a plain click.
    selectedEmailIds  = new Set([nextId]);
    selectionAnchorId = nextId;
    selectionFocusId  = nextId;
    renderSelectionUi();
    scrollRowIntoView(nextId);
    selectEmail(nextId);
}

function scrollRowIntoView(emailId) {
    const row = document.querySelector(`#emailList .email-item[data-email-id="${emailId}"]`);
    if (!row) return;

    // Scroll ONLY the list's own scroll container, never the page. Element.scrollIntoView()
    // can't be scoped — it scrolls every scrollable ancestor up to the document, so at the
    // end of the list it scrolls the whole window and drags the fixed header off the top
    // (the bug this replaces). Nudge the container's scrollTop by hand instead, and stop
    // short of <body>/<html> so nothing outside the pane ever moves.
    let box = row.parentElement;
    while (box && box !== document.body && box !== document.documentElement) {
        const oy = getComputedStyle(box).overflowY;
        if ((oy === 'auto' || oy === 'scroll') && box.scrollHeight > box.clientHeight) break;
        box = box.parentElement;
    }
    if (!box || box === document.body || box === document.documentElement) return;

    const rowRect = row.getBoundingClientRect();
    const boxRect = box.getBoundingClientRect();
    if (rowRect.top < boxRect.top) {
        box.scrollTop -= (boxRect.top - rowRect.top);
    } else if (rowRect.bottom > boxRect.bottom) {
        box.scrollTop += (rowRect.bottom - boxRect.bottom);
    }
}

/** Space — toggle the row under the cursor, the keyboard's Ctrl+click. */
function toggleFocusedRow() {
    if (selectionFocusId === null) return;
    if (selectedEmailIds.has(selectionFocusId)) selectedEmailIds.delete(selectionFocusId);
    else                                        selectedEmailIds.add(selectionFocusId);
    selectionAnchorId = selectionFocusId;
    renderSelectionUi();
    onSelectionChanged({ clickedId: selectionFocusId });
}

document.addEventListener('keydown', function (e) {
    // Never steal keys from a field, an editor, or an open modal — the inbox
    // shares this page with TinyMCE, the search box and a dozen dialogs.
    const el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)) return;
    if (document.querySelector('.modal.active')) return;
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor && tinymce.activeEditor.hasFocus && tinymce.activeEditor.hasFocus()) return;

    const ctrl = e.ctrlKey || e.metaKey;

    if (ctrl && (e.key === 'a' || e.key === 'A')) {
        if (!document.getElementById('emailList')) return;
        e.preventDefault();
        selectAllVisible();
        return;
    }
    if (e.key === 'Escape' && selectionCount() > 1) {
        e.preventDefault();
        clearSelectionToNone();
        return;
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!document.getElementById('emailList')) return;
        e.preventDefault();
        moveSelectionCursor(e.key === 'ArrowDown' ? 1 : -1, { extend: e.shiftKey, keepSelection: ctrl && !e.shiftKey });
        return;
    }
    if (e.key === ' ' && selectionFocusId !== null) {
        e.preventDefault();
        toggleFocusedRow();
    }
});

// ---------------------------------------------------------------------------
// Applying an action to the whole selection
// ---------------------------------------------------------------------------
//
// 🔑 A bulk action is deliberately "do the SINGLE action, N times" — the very
// same endpoint the one-ticket path uses, not a new bulk endpoint with its own
// SQL. Tickets is the busiest and most side-effect-laden module in the product
// (audit rows, SLA clocks, workflow triggers, notifications): a second write
// path would eventually drift from the first, and the symptom would be an
// action that silently skips an audit entry only when done in bulk.
//
// The cost is N requests. That is paid for with a small concurrency window so
// fifty tickets do not open fifty sockets, and a progress toast so a long run
// does not look like a hang.

// Chunk size. The server caps a single request at 100; chunking below that keeps
// each request short enough to stay well inside PHP's execution limit (each ticket
// runs the full service: audit, workflow dispatch, possibly a template email) and
// gives the progress counter something honest to count.
const BULK_CHUNK = 25;

// Live progress goes in OUR OWN surface, not the toast. `showToast(msg, type)`
// is a fire-and-forget shared component that auto-dismisses after four seconds
// and returns nothing — there is no handle to update. Teaching it to be sticky
// and updatable would change a component every module in the app depends on, for
// the benefit of one screen. The summary pane and the bar are both ours, so the
// counter lives there and the toast just reports the outcome.
let bulkBusy = null;   // { done, total, label } while a run is in flight

/**
 * Apply one change to every selected ticket.
 *
 * @param endpoint 'bulk_update_tickets.php' | 'bulk_delete_tickets.php'
 * @param payloadFor  (idsChunk) => body object
 * @param label       human label for the progress line and the result toast
 */
async function applyToSelection(endpoint, payloadFor, label) {
    const ids = selectedTicketIds();
    if (!ids.length) return;
    if (bulkBusy) return;                     // one run at a time

    const total = ids.length;
    let ok = 0;
    const failures = [];

    bulkBusy = { done: 0, total, label };
    updateSelectionSurfaces();

    for (let i = 0; i < ids.length; i += BULK_CHUNK) {
        const chunk = ids.slice(i, i + BULK_CHUNK);
        try {
            const res = await fetch(API_BASE + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payloadFor(chunk))
            });
            const data = await res.json();
            if (data.success) {
                ok += (data.updated ?? data.deleted ?? 0);
                (data.failed || []).forEach(f => failures.push(f));
            } else {
                chunk.forEach(id => failures.push({ id, error: data.error || 'unknown' }));
            }
        } catch (err) {
            chunk.forEach(id => failures.push({ id, error: String(err) }));
        }
        bulkBusy.done = Math.min(total, i + chunk.length);
        updateSelectionSurfaces();
    }

    bulkBusy = null;

    if (!failures.length) {
        showToast(t('tickets.bulk.done').replace('%d', String(ok)).replace('%s', label), 'success');
    } else {
        // Never report a partial run as a success — say exactly how many failed.
        showToast(t('tickets.bulk.partial').replace('%d', String(ok)).replace('%f', String(failures.length)), 'error');
        console.warn('Bulk action failures:', failures);
    }

    await loadEmails();
    if (typeof loadFolderCounts === 'function') loadFolderCounts();
    renderSelectionUi();
}

/** Set one field across the selection. */
function bulkSetField(fields, label) {
    return applyToSelection('bulk_update_tickets.php',
        chunk => ({ ticket_ids: chunk, fields: fields }), label);
}

// ---------------------------------------------------------------------------
// The action dropdown on the summary pane / bar
// ---------------------------------------------------------------------------
// Built from the SAME lookups the right-click submenus use, so a status added in
// settings appears in both without a second registration.

function openSelectionMenu(event, kind) {
    event.stopPropagation();
    closeSelectionMenu();

    let items = [];
    if (kind === 'status') {
        items = ticketStatuses.map(s => ({
            label: s.name,
            colour: s.colour,
            run: () => bulkSetField({ status: s.name }, t('tickets.bulk.label_status'))
        }));
    } else if (kind === 'priority') {
        items = [{ label: t('tickets.context.clear_priority'), italic: true,
                   run: () => bulkSetField({ priority_id: null }, t('tickets.bulk.label_priority')) }]
            .concat(ticketPriorities.map(p => ({
                label: p.name,
                colour: p.colour,
                run: () => bulkSetField({ priority_id: p.id }, t('tickets.bulk.label_priority'))
            })));
    } else if (kind === 'department') {
        items = [{ label: t('tickets.context.clear_department'), italic: true,
                   run: () => bulkSetField({ department_id: null }, t('tickets.bulk.label_department')) }]
            .concat(departments.map(d => ({
                label: d.name,
                run: () => bulkSetField({ department_id: d.id }, t('tickets.bulk.label_department'))
            })));
    } else if (kind === 'assignee') {
        items = [{ label: t('tickets.context.clear_assignee'), italic: true,
                   run: () => bulkSetField({ assigned_analyst_id: null }, t('tickets.bulk.label_assignee')) }]
            .concat(analysts.map(a => ({
                label: a.full_name || a.username,
                run: () => bulkSetField({ assigned_analyst_id: a.id }, t('tickets.bulk.label_assignee'))
            })));
    }

    if (!items.length) return;

    const menu = document.createElement('div');
    menu.className = 'sel-dropdown';
    menu.id = 'selectionDropdown';
    menu.innerHTML = items.map((it, i) => `
        <button type="button" class="sel-dropdown-item" data-i="${i}">
            ${it.colour ? `<span class="ctx-status-swatch" style="background:${escapeHtml(it.colour)};"></span>` : ''}
            <span${it.italic ? ' style="font-style:italic;color:var(--text-muted,#888);"' : ''}>${escapeHtml(it.label)}</span>
        </button>`).join('');
    document.body.appendChild(menu);

    menu.querySelectorAll('.sel-dropdown-item').forEach(btn => {
        btn.addEventListener('click', () => {
            const it = items[Number(btn.dataset.i)];
            closeSelectionMenu();
            it.run();
        });
    });

    // Position under the button that opened it, flipped if it would overflow.
    const r = event.currentTarget.getBoundingClientRect();
    const mr = menu.getBoundingClientRect();
    let x = r.left, y = r.bottom + 4;
    if (x + mr.width  > window.innerWidth)  x = window.innerWidth  - mr.width  - 8;
    if (y + mr.height > window.innerHeight) y = Math.max(8, r.top - mr.height - 4);
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
}

function closeSelectionMenu() {
    const m = document.getElementById('selectionDropdown');
    if (m) m.remove();
}
document.addEventListener('click', function (e) {
    const m = document.getElementById('selectionDropdown');
    if (m && !m.contains(e.target)) closeSelectionMenu();
});
window.addEventListener('scroll', closeSelectionMenu, true);

async function bulkDeleteSelection() {
    const n = selectionCount();
    if (!n) return;
    const okToGo = await showConfirm({
        title: t('tickets.bulk.delete_title'),
        message: t('tickets.bulk.delete_message').replace('%d', String(n)),
        okLabel: t('tickets.bulk.delete'),
        okClass: 'danger'
    });
    if (!okToGo) return;

    await applyToSelection('bulk_delete_tickets.php',
        chunk => ({ ticket_ids: chunk }), t('tickets.bulk.label_delete'));
    clearSelectionToNone();
}

/** The progress line both surfaces show mid-run. */
function bulkProgressHtml() {
    if (!bulkBusy) return '';
    return `<div class="sel-progress">
                <span class="spinner-inline"></span>
                ${escapeHtml(bulkBusy.label)} — ${bulkBusy.done}/${bulkBusy.total}
            </div>`;
}

// Select and display email by email ID
async function selectEmail(emailId) {
    // 🔑 A NEGATIVE ID IS A TICKET WITH NO EMAIL. get_emails.php sends
    // COALESCE(le.id, -t.id), so the sign says which kind of thing the row is.
    // Selection, drag and the highlight all work off this id unchanged; only
    // the way the reading pane is loaded differs.
    if (emailId < 0) {
        selectedEmailIds  = new Set([emailId]);
        selectionAnchorId = emailId;
        selectionFocusId  = emailId;
        setSelectedEmailRow(emailId);
        return loadTicketById(-emailId);
    }
    selectedEmailId = emailId;
    // Opening a single ticket collapses the selection to it, so the highlight,
    // the reading pane and what an action would hit can never disagree. Callers
    // that want a multi-selection never route through here.
    if (!(selectedEmailIds.size > 1 && selectedEmailIds.has(emailId))) {
        selectedEmailIds  = new Set([emailId]);
        selectionAnchorId = emailId;
        selectionFocusId  = emailId;
    }
    setSelectedEmailRow(emailId);

    const readingPane = document.getElementById('readingPane');

    // Avoid the blank-pane flicker when switching between tickets: keep the
    // current ticket on screen during the (usually instant) fetch and swap
    // straight to the new one. Only blank to a spinner when the pane is empty
    // (first open), or if the fetch is actually slow — a delayed timer covers
    // that so a slow network still gives feedback.
    const hadTicket = !!currentEmail;
    let spinnerTimer = null;
    if (!hadTicket) {
        readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    } else {
        spinnerTimer = setTimeout(() => {
            readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
        }, 250);
    }

    try {
        const response = await fetch(`${API_BASE}get_email_detail.php?id=${emailId}`);
        const data = await response.json();
        if (spinnerTimer) clearTimeout(spinnerTimer);

        if (data.success) {
            displayEmail(data.email, data.recordings || []);
        } else {
            readingPane.innerHTML = '<div class="reading-pane-empty">Error loading email</div>';
            syncPopoutToTicketState(false);
        }
    } catch (error) {
        if (spinnerTimer) clearTimeout(spinnerTimer);
        console.error('Error:', error);
        readingPane.innerHTML = '<div class="reading-pane-empty">Failed to load email</div>';
        syncPopoutToTicketState(false);
    }
}

// Load and display ticket by ticket ID (from URL parameter)
async function loadTicketById(ticketId) {
    const readingPane = document.getElementById('readingPane');
    readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_BASE}get_email_detail.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            // For a ticket with no email the row id is the negative ticket id,
            // so keep using that or the highlight would clear itself.
            selectedEmailId = data.email.id !== null ? data.email.id : -ticketId;
            renderEmailList();
            displayEmail(data.email, data.recordings || []);
        } else {
            readingPane.innerHTML = '<div class="reading-pane-empty">Ticket not found</div>';
            syncPopoutToTicketState(false);
        }
    } catch (error) {
        console.error('Error:', error);
        readingPane.innerHTML = '<div class="reading-pane-empty">Failed to load ticket</div>';
        syncPopoutToTicketState(false);
    }
}

// Display email in reading pane
function displayEmail(email, recordings) {
    currentEmail = email;
    currentRecordings = recordings || [];

    // The recent trail (#124). Here rather than in loadTicketById() because
    // EVERY way of putting a ticket in the reading pane ends up in this
    // function — the list, a deep link, a search result, the previous/next
    // keys — and a hook per entry point would have missed one.
    if (window.trailVisit) window.trailVisit('ticket', email.ticket_id);
    const readingPane = document.getElementById('readingPane');

    // Build department dropdown
    const departmentOptions = departments.map(dept =>
        `<option value="${dept.id}" ${email.department_id == dept.id ? 'selected' : ''}>${escapeHtml(dept.name)}</option>`
    ).join('');

    // Multi-company only: a Company picker (move the ticket) + a soft wrong-company
    // warning. Both stay empty on a single-company install, so nothing changes at N=1.
    let companyField = '';
    let companyWarningBanner = '';
    if (isMultiCompany) {
        const defaultCo = moveCompanies.find(c => c.is_default) || {};
        const currentTid = (email.tenant_id != null) ? email.tenant_id : defaultCo.id;
        const companyOptions = moveCompanies.map(c =>
            `<option value="${c.id}" ${String(currentTid) === String(c.id) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`
        ).join('');
        companyField = `
            <div class="toolbar-field">
                <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_company'))}</label>
                <select class="toolbar-select" id="companySelect" onchange="moveTicketCompany()">
                    ${companyOptions}
                </select>
            </div>`;
        if (email.company_warning) {
            const w = email.company_warning;
            companyWarningBanner = `
                <div class="wrong-company-banner">
                    <span class="wrong-company-text">⚠ Filed under <strong>${escapeHtml(email.company_name || '')}</strong>, but the requester (${escapeHtml(w.requester)}) looks like <strong>${escapeHtml(w.suggested_name)}</strong>.</span>
                    <span class="wrong-company-actions">
                        <button class="action-btn action-btn-primary" onclick="moveTicketCompany(${w.suggested_id})">Move to ${escapeHtml(w.suggested_name)}</button>
                        <button class="action-btn" onclick="this.closest('.wrong-company-banner').remove()">Dismiss</button>
                    </span>
                </div>`;
        }
    }

    // Build ticket type dropdown
    const ticketTypeOptions = ticketTypes.map(type =>
        `<option value="${type.id}" ${email.ticket_type_id == type.id ? 'selected' : ''}>${escapeHtml(type.name)}</option>`
    ).join('');

    // Build status dropdown from the active ticket_statuses lookup
    const statusOptions = ticketStatuses.map(s =>
        `<option value="${escapeHtml(s.name)}" ${email.status === s.name ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
    ).join('');

    // Build priority dropdown from the active ticket_priorities lookup.
    // A blank option lets the user clear the priority — useful since priority
    // is nullable and not every ticket needs an SLA-driving priority assigned.
    const priorityOptions = ticketPriorities.map(p =>
        `<option value="${p.id}" ${email.priority_id == p.id ? 'selected' : ''}>${escapeHtml(p.name)}</option>`
    ).join('');

    // Build ticket origin dropdown
    const originOptions = ticketOrigins.map(origin =>
        `<option value="${origin.id}" ${email.origin_id == origin.id ? 'selected' : ''}>${escapeHtml(origin.name)}</option>`
    ).join('');

    // Build first time fix dropdown
    const firstTimeFixOptions = `
        <option value="" ${email.first_time_fix === null ? 'selected' : ''}>--</option>
        <option value="1" ${email.first_time_fix === true || email.first_time_fix === 1 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_yes'))}</option>
        <option value="0" ${email.first_time_fix === false || email.first_time_fix === 0 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_no'))}</option>
    `;

    // Build IT training provided dropdown
    const itTrainingOptions = `
        <option value="" ${email.it_training_provided === null ? 'selected' : ''}>--</option>
        <option value="1" ${email.it_training_provided === true || email.it_training_provided === 1 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_yes'))}</option>
        <option value="0" ${email.it_training_provided === false || email.it_training_provided === 0 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_no'))}</option>
    `;

    // Build owner/analyst dropdown
    const ownerOptions = analysts.map(analyst =>
        `<option value="${analyst.id}" ${email.owner_id == analyst.id ? 'selected' : ''}>${escapeHtml(analyst.full_name)}</option>`
    ).join('');

    // Build summary values for collapsed view
    const summaryDept = getDisplayName('department', email.department_id) || t('tickets.reading_pane.summary_none');
    // A ticket with no status reads as "none". It used to claim "Open", which is
    // why #79 looked self-contradictory: the header said Open while the list row
    // and the Status dropdown correctly showed nothing.
    const summaryStatus = email.status || t('tickets.reading_pane.summary_none');
    const summaryOwner = getDisplayName('owner', email.owner_id) || t('tickets.reading_pane.summary_unassigned');

    // When the open ticket is in the trash, lead with a banner offering Restore /
    // Delete forever instead of the usual workflow actions.
    const isTrashed = !!email.deleted_datetime;
    const trashBanner = isTrashed ? `
        <div style="background:#fdeceb;border:1px solid #e6c4c4;border-radius:8px;padding:12px 16px;margin:0 0 14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <span style="font-size:18px;">🗑️</span>
            <span style="color:#b71c1c;font-weight:600;flex:1;min-width:180px;">This ticket is in the trash — its actions are disabled until you restore it.</span>
            <button onclick="restoreTicketFromTrash(${email.ticket_id})" style="font-size:12.5px;padding:6px 14px;border:1px solid #c8d6cf;background:#eefaf2;color:#1b7a43;border-radius:5px;cursor:pointer;font-weight:600;">↩ Restore</button>
            <button onclick="permanentlyDeleteFromTrash(${email.ticket_id}, '${escapeHtml(email.ticket_number || '')}')" style="font-size:12.5px;padding:6px 14px;border:1px solid #e6c4c4;background:#fff;color:#b71c1c;border-radius:5px;cursor:pointer;font-weight:600;">✕ Delete forever</button>
        </div>` : '';

    readingPane.innerHTML = trashBanner + (isTrashed ? '<div style="pointer-events:none;opacity:0.55;">' : '') + `
        <div class="ticket-properties-container" id="ticketPropertiesContainer">
            <div class="ticket-properties-header" onclick="toggleTicketProperties(event)">
                <div class="ticket-properties-title">
                    <span class="ticket-properties-chevron">&#9660;</span>
                    ${escapeHtml(t('tickets.reading_pane.properties_title'))}
                </div>
                <div class="ticket-properties-summary">
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.summary_dept'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryDept">${escapeHtml(summaryDept)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.summary_status'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryStatus">${escapeHtml(summaryStatus)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.summary_owner'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryOwner">${escapeHtml(summaryOwner)}</span>
                    </span>
                </div>
            </div>
            <div class="ticket-properties-panel">
                <div class="ticket-toolbar">
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_department'))}</label>
                        <select class="toolbar-select" id="departmentSelect" onchange="assignDepartment()">
                            <option value=""></option>
                            ${departmentOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_type'))}</label>
                        <select class="toolbar-select" id="ticketTypeSelect" onchange="assignTicketType()">
                            <option value=""></option>
                            ${ticketTypeOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_status'))}</label>
                        <select class="toolbar-select" id="statusSelect" onchange="assignStatus()">
                            ${statusOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_priority'))}</label>
                        <select class="toolbar-select" id="prioritySelect" onchange="assignPriority()">
                            <option value=""></option>
                            ${priorityOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_origin'))}</label>
                        <select class="toolbar-select" id="originSelect" onchange="assignOrigin()">
                            <option value=""></option>
                            ${originOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_first_time_fix'))}</label>
                        <select class="toolbar-select" id="firstTimeFixSelect" onchange="assignFirstTimeFix()">
                            ${firstTimeFixOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_it_training'))}</label>
                        <select class="toolbar-select" id="itTrainingSelect" onchange="assignItTraining()">
                            ${itTrainingOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_owner'))}</label>
                        <select class="toolbar-select" id="ownerSelect" onchange="assignOwner()">
                            <option value=""></option>
                            ${ownerOptions}
                        </select>
                    </div>
                    ${companyField}
                </div>
            </div>
        </div>
        ${companyWarningBanner}
        <div class="email-header">
            <div class="email-subject-line">
                <span class="email-subject-text">${escapeHtml(t('tickets.reading_pane.ticket_label'))} ${escapeHtml(email.ticket_number || '')} - ${escapeHtml(email.subject)}</span>
                <button class="icon-btn ticket-popout-toggle" onclick="toggleTicketPopout()" title="${escapeHtml(t('tickets.reading_pane.toggle_fullscreen'))}" aria-label="${escapeHtml(t('tickets.reading_pane.toggle_fullscreen'))}">
                    <svg class="popout-icon-expand" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                    <svg class="popout-icon-contract" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                </button>
            </div>
            <div class="email-meta">
                <div class="email-meta-row">
                    <div class="email-meta-label">${escapeHtml(t('tickets.reading_pane.meta_from'))}</div>
                    <div class="email-meta-value">${senderLabel(email.from_name, email.from_address, true)}</div>
                </div>
                <div class="email-meta-row">
                    <div class="email-meta-label">${escapeHtml(t('tickets.reading_pane.meta_to'))}</div>
                    <div class="email-meta-value">${escapeHtml(email.to_recipients)}</div>
                    <div class="email-meta-date"><span class="email-meta-date-label">${escapeHtml(t('tickets.reading_pane.meta_date'))}</span> ${formatFullDateTime(email.received_datetime)}</div>
                </div>
                ${email.cc_recipients ? `
                <div class="email-meta-row">
                    <div class="email-meta-label">${escapeHtml(t('tickets.reading_pane.meta_cc'))}</div>
                    <div class="email-meta-value">${escapeHtml(email.cc_recipients)}</div>
                </div>
                ` : ''}
            </div>
        </div>
        <div class="attachment-info-bar" id="attachmentInfoBar" onclick="showAttachmentList()" style="display: none;">
            <span>${escapeHtml(t('tickets.actions.loading_attachments'))}</span>
        </div>
        ${buildSnoozeBanner(email)}
        ${buildMergeBanner(email)}
        <!-- Collision detection (#934). Left empty by the render and filled by the
             presence poll, so a re-render never flashes a stale set of faces. -->
        <div class="presence-strip" id="presenceStrip" hidden></div>
        ${buildLinksSection(email)}
        ${buildRecordingsStrip(currentRecordings)}
        ${buildAiSummarySlot()}
        <div class="action-toolbar">
            <button class="action-btn" onclick="openNoteModal()">
                <span class="action-btn-icon">📝</span>
                <span>${escapeHtml(t('tickets.actions.add_note'))}</span>
            </button>
            <button class="action-btn" onclick="openReplyModal()">
                <span class="action-btn-icon">↩️</span>
                <span>${escapeHtml(t('tickets.actions.reply'))}</span>
            </button>
            <button class="action-btn" onclick="openForwardModal()">
                <span class="action-btn-icon">➡️</span>
                <span>${escapeHtml(t('tickets.actions.forward'))}</span>
            </button>
            <button class="action-btn" onclick="openScheduleModal()">
                <span class="action-btn-icon">📅</span>
                <span>${escapeHtml(t('tickets.actions.schedule'))}</span>
            </button>
            <button class="action-btn" onclick="openTicketAiChat()">
                <span class="action-btn-icon">🤖</span>
                <span>${escapeHtml(t('tickets.actions.ask_ai'))}</span>
            </button>
            ${buildReadForMeButton()}
            <button class="action-btn" onclick="showAuditHistory()">
                <span class="action-btn-icon">📋</span>
                <span>${escapeHtml(t('tickets.actions.audit'))}</span>
            </button>
            ${buildWriteUpButton(email)}
            <button class="action-btn" onclick="requestCsatSurvey()" title="Send a satisfaction survey to the requester">
                <span class="action-btn-icon">⭐</span>
                <span>${escapeHtml(t('tickets.actions.request_feedback'))}</span>
            </button>
            <button class="action-btn action-btn-danger" onclick="deleteTicket()">
                <span class="action-btn-icon">🗑️</span>
                <span>${escapeHtml(t('tickets.actions.delete'))}</span>
            </button>
        </div>
        <div class="email-body">
            <div id="threadContainer">
                ${emailBodyHost(email.body_content, 'email-body-content', email.body_type)}
            </div>
            <div id="slaContainer"></div>
            <div id="timeEntriesContainer"></div>
            <div id="notesContainer"></div>
        </div>
    ` + (isTrashed ? '</div>' : '');

    // Isolate the just-rendered email body in a shadow root (see emailBodyHost).
    hydrateEmailBodies(readingPane);

    loadAiSummary(email.ticket_id);
    // Load full correspondence thread, notes, attachments and linked CMDB objects after rendering
    loadCorrespondenceThread(email.ticket_id);
    loadNotes(email.ticket_id);
    loadTicketAttachments(email.ticket_id);
    loadCmdbObjects(email.ticket_id);
    loadTicketAssets(email.ticket_id);
    loadTicketTasks(email.ticket_id);
    loadTimeEntries(email.ticket_id);
    loadSlaState(email.ticket_id);

    // Announce ourselves on this ticket, and start listening for anyone else
    // (#934). Moving between tickets stops the old announcement first, so a
    // colleague never sees us lingering on a ticket we have left.
    startPresence(email.ticket_id);

    // A ticket is now displayed — apply popout class if the saved pref says so.
    syncPopoutToTicketState(true);

    // Rendering the ticket has just replaced the pane's contents, which takes the
    // "keep" mode's warning strip with it. Put the selection surfaces back.
    updateSelectionSurfaces();
}

// Render the recordings strip that sits between the email header and the action
// toolbar. Returns the empty string when the ticket has no recordings, so the
// gap collapses cleanly. Stream URL is the same endpoint the self-service portal
// uses — auth check inside accepts either a session analyst or the ticket owner.
// The linked-items section (problems + changes + tickets combined) is rendered
// by buildLinksSection() further down; the link/unlink + modal helpers below are
// shared by it and the right-click context menu.

// Right-click "Link to problem…" — targets whichever ticket was right-clicked,
// even if a different one is open in the reading pane.
function openContextLinkProblem() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const subj = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId) ? (currentEmail.subject || '') : '';
    openLinkProblemModal(ctxTargetTicketId, ctxTargetTicketRef, subj);
}

let linkProblemTicketId = null;
let linkProblemTicketRef = '';
let linkProblemTicketSubject = '';
let linkProblemSearchTimer = null;

function openLinkProblemModal(ticketId, ticketRef, subject) {
    linkProblemTicketId = ticketId;
    linkProblemTicketRef = ticketRef || ('Ticket ' + ticketId);
    linkProblemTicketSubject = subject || '';
    document.getElementById('linkProblemTicketRef').textContent = linkProblemTicketRef;
    const s = document.getElementById('linkProblemSearch'); if (s) s.value = '';
    document.getElementById('linkProblemModal').classList.add('active');
    loadLinkProblemList();
}
function closeLinkProblemModal() { document.getElementById('linkProblemModal').classList.remove('active'); }
function linkProblemSearchDebounced() { clearTimeout(linkProblemSearchTimer); linkProblemSearchTimer = setTimeout(loadLinkProblemList, 250); }

async function loadLinkProblemList() {
    const list = document.getElementById('linkProblemList');
    const q = (document.getElementById('linkProblemSearch') || {}).value || '';
    list.innerHTML = '<div class="lp-empty">Loading…</div>';
    try {
        const data = await fetch('../api/problem-management/list.php?q=' + encodeURIComponent(q.trim())).then(r => r.json());
        if (!data.success) { list.innerHTML = '<div class="lp-empty">' + escapeHtml(data.error || 'Failed to load') + '</div>'; return; }
        const createRow = `<div class="lp-row lp-create" onclick="createProblemFromIncident()">
            <span class="lp-plus">＋</span><span>Create a new problem from this incident</span></div>`;
        const open = (data.problems || []).filter(p => p.is_closed != 1);
        const rows = open.map(p => `<div class="lp-row" onclick="pickProblem(${p.id})">
            <span class="lp-num">${escapeHtml(p.problem_number || ('#' + p.id))}</span>
            <span class="lp-title">${escapeHtml(p.title || '')}</span>
            <span class="lp-status">${escapeHtml(p.status_name || '')}</span></div>`).join('');
        list.innerHTML = createRow + (rows || '<div class="lp-empty">' + (q.trim() ? 'No matching open problems.' : 'No open problems yet — create one above.') + '</div>');
    } catch (e) { list.innerHTML = '<div class="lp-empty">Failed to load problems</div>'; }
}
function pickProblem(problemId) { doLinkTicketProblem({ problem_id: problemId }); }
async function createProblemFromIncident() {
    try {
        const title = linkProblemTicketSubject || ('Problem from ' + linkProblemTicketRef);
        const cr = await fetch('../api/problem-management/save.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: title, description: '' })
        }).then(r => r.json());
        if (!cr.success) { showToast('Could not create problem: ' + (cr.error || ''), 'error'); return; }
        doLinkTicketProblem({ problem_id: cr.id });
    } catch (e) { showToast('Failed to create problem', 'error'); }
}
async function doLinkTicketProblem(target) {
    try {
        const res = await fetch('../api/problem-management/link_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ ticket_id: linkProblemTicketId }, target))
        });
        const data = await res.json();
        if (data.success) {
            showToast('Linked to problem', 'success');
            closeLinkProblemModal();
            if (currentEmail && currentEmail.ticket_id == linkProblemTicketId) selectEmail(currentEmail.id);
        } else showToast('Could not link: ' + (data.error || 'unknown error'), 'error');
    } catch (e) { showToast('Failed to link problem', 'error'); }
}
async function unlinkTicketFromProblem(problemId) {
    if (!currentEmail) return;
    try {
        const res = await fetch('../api/problem-management/unlink_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ problem_id: problemId, ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (data.success) { showToast('Unlinked', 'success'); selectEmail(currentEmail.id); }
        else showToast(data.error || 'Failed', 'error');
    } catch (e) { showToast('Failed', 'error'); }
}

// Right-click "Link to change…" — targets whichever ticket was right-clicked,
// even if a different one is open in the reading pane.
function openContextLinkChange() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const subj = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId) ? (currentEmail.subject || '') : '';
    openLinkChangeModal(ctxTargetTicketId, ctxTargetTicketRef, subj);
}

let linkChangeTicketId = null;
let linkChangeTicketRef = '';
let linkChangeTicketSubject = '';
let linkChangeSearchTimer = null;

function openLinkChangeModal(ticketId, ticketRef, subject) {
    linkChangeTicketId = ticketId;
    linkChangeTicketRef = ticketRef || ('Ticket ' + ticketId);
    linkChangeTicketSubject = subject || '';
    document.getElementById('linkChangeTicketRef').textContent = linkChangeTicketRef;
    const s = document.getElementById('linkChangeSearch'); if (s) s.value = '';
    document.getElementById('linkChangeModal').classList.add('active');
    loadLinkChangeList();
}
function closeLinkChangeModal() { document.getElementById('linkChangeModal').classList.remove('active'); }
function linkChangeSearchDebounced() { clearTimeout(linkChangeSearchTimer); linkChangeSearchTimer = setTimeout(loadLinkChangeList, 250); }

async function loadLinkChangeList() {
    const list = document.getElementById('linkChangeList');
    const q = (document.getElementById('linkChangeSearch') || {}).value || '';
    list.innerHTML = '<div class="lp-empty">Loading…</div>';
    try {
        const data = await fetch('../api/change-management/list.php?search=' + encodeURIComponent(q.trim())).then(r => r.json());
        if (!data.success) { list.innerHTML = '<div class="lp-empty">' + escapeHtml(data.error || 'Failed to load') + '</div>'; return; }
        const createRow = `<div class="lp-row lp-create" onclick="createChangeFromIncident()">
            <span class="lp-plus">＋</span><span>Create a new change from this ticket</span></div>`;
        const rows = (data.changes || []).map(c => {
            const ref = 'CHG-' + String(c.id).padStart(4, '0');
            return `<div class="lp-row" onclick="pickChange(${c.id})">
            <span class="lp-num">${escapeHtml(ref)}</span>
            <span class="lp-title">${escapeHtml(c.title || '')}</span>
            <span class="lp-status">${escapeHtml(c.status || '')}</span></div>`;
        }).join('');
        list.innerHTML = createRow + (rows || '<div class="lp-empty">' + (q.trim() ? 'No matching changes.' : 'No changes yet — create one above.') + '</div>');
    } catch (e) { list.innerHTML = '<div class="lp-empty">Failed to load changes</div>'; }
}
function pickChange(changeId) { doLinkTicketChange(changeId); }
async function createChangeFromIncident() {
    try {
        const title = linkChangeTicketSubject || ('Change from ' + linkChangeTicketRef);
        const cr = await fetch('../api/change-management/save.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: title })
        }).then(r => r.json());
        if (!cr.success) { showToast('Could not create change: ' + (cr.error || ''), 'error'); return; }
        doLinkTicketChange(cr.change_id);
    } catch (e) { showToast('Failed to create change', 'error'); }
}
async function doLinkTicketChange(changeId) {
    try {
        const res = await fetch('../api/change-management/link_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: changeId, ticket_id: linkChangeTicketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Linked to change', 'success');
            closeLinkChangeModal();
            if (currentEmail && currentEmail.ticket_id == linkChangeTicketId) selectEmail(currentEmail.id);
        } else showToast('Could not link: ' + (data.error || 'unknown error'), 'error');
    } catch (e) { showToast('Failed to link change', 'error'); }
}
async function unlinkTicketFromChange(changeId) {
    if (!currentEmail) return;
    try {
        const res = await fetch('../api/change-management/unlink_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: changeId, ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (data.success) { showToast('Unlinked', 'success'); selectEmail(currentEmail.id); }
        else showToast(data.error || 'Failed', 'error');
    } catch (e) { showToast('Failed', 'error'); }
}

// ============================================================
// Unified "Links" section (#38): one strip with pills for every linked
// problem / change / ticket, plus a single "Link to…" menu. Replaces the three
// separate strips — far better use of space on a wide reading pane.
// ============================================================
/**
 * The merge banner, in both directions.
 *
 * On a MERGED-AWAY ticket this is the most important thing on the screen: the
 * conversation has moved, this ticket is a redirect, and an analyst who starts
 * typing a reply here is talking into a closed ticket nobody is watching. It says
 * so first, before anything else in the pane.
 *
 * On a SURVIVING ticket it is quieter — a note of which references now resolve
 * here, so an analyst asked about ABC can see at a glance that they are already
 * looking at the right conversation.
 */
function buildMergeBanner(email) {
    if (email.merged_away && email.merged_away.merged_into_id) {
        const ref = email.merged_away.ticket_number || ('#' + email.merged_away.merged_into_id);
        // Undo is offered only when the merge recorded what it moved. Merges made
        // before that recording existed cannot be reversed, and a button that always
        // fails is worse than no button.
        const undoBtn = (email.merged_away.merge_id && Number(email.merged_away.can_undo))
            ? `<button type="button" class="btn btn-secondary merge-banner-btn"
                       onclick="undoMerge(${email.merged_away.merge_id})">${escapeHtml(t('tickets.merge.undo'))}</button>`
            : '';
        return `
        <div class="merge-banner merge-banner-away">
            <span class="merge-banner-icon">⤵</span>
            <div class="merge-banner-text">
                <strong>${escapeHtml(t('tickets.merge.banner_away_title'))}</strong>
                <div>${escapeHtml(t('tickets.merge.banner_away_body'))}</div>
            </div>
            ${undoBtn}
            <button type="button" class="btn btn-secondary merge-banner-btn"
                    onclick="openTicketByNumber('${escapeHtml(ref).replace(/'/g, "\\'")}')">
                ${escapeHtml(t('tickets.merge.banner_go').replace('%s', ref))}
            </button>
        </div>`;
    }

    // Split banners. Quieter than the merged-away one: nothing here is a redirect,
    // both tickets are live, and this is context rather than a warning.
    if (email.split_from && email.split_from.source_ticket_number) {
        const ref = email.split_from.source_ticket_number;
        return `
        <div class="merge-banner merge-banner-in">
            <span class="merge-banner-icon">⑂</span>
            <div class="merge-banner-text">
                ${escapeHtml(splitPlural(email.split_from.message_count || 0, 'tickets.split.banner_from'))}
                <span class="merge-banner-refs">${escapeHtml(ref)}</span>
            </div>
            <button type="button" class="btn btn-secondary merge-banner-btn"
                    onclick="openTicketByNumber('${escapeHtml(ref).replace(/'/g, "\\'")}')">
                ${escapeHtml(t('tickets.merge.banner_go').replace('%s', ref))}
            </button>
        </div>`;
    }

    if (Array.isArray(email.split_out) && email.split_out.length) {
        const refs = email.split_out.map(s => escapeHtml(s.new_ticket_number || ('#' + s.new_ticket_id))).join(', ');
        // Undo is offered only for the MOST RECENT split, and only when there is
        // exactly one — "undo" with several candidates is a menu, not a button, and
        // the engine will refuse anything that has been worked on anyway.
        const undoBtn = email.split_out.length === 1
            ? `<button type="button" class="btn btn-secondary merge-banner-btn"
                       onclick="undoSplit(${email.split_out[0].id})">${escapeHtml(t('tickets.split.undo'))}</button>`
            : '';
        return `
        <div class="merge-banner merge-banner-in">
            <span class="merge-banner-icon">⑂</span>
            <div class="merge-banner-text">
                ${escapeHtml(splitPlural(email.split_out.length, 'tickets.split.banner_out'))}
                <span class="merge-banner-refs">${refs}</span>
            </div>
            ${undoBtn}
        </div>`;
    }

    if (Array.isArray(email.merged_in) && email.merged_in.length) {
        const refs = email.merged_in.map(m => escapeHtml(m.source_ticket_number || ('#' + m.source_ticket_id))).join(', ');
        return `
        <div class="merge-banner merge-banner-in">
            <span class="merge-banner-icon">⤴</span>
            <div class="merge-banner-text">
                ${escapeHtml(splitPlural(email.merged_in.length, 'tickets.merge.banner_in'))}
                <span class="merge-banner-refs">${refs}</span>
            </div>
        </div>`;
    }
    return '';
}

/**
 * Undo a merge — take the messages back and reopen this ticket.
 *
 * The confirm spells out what is NOT reversed: replies written on the surviving
 * ticket since the merge stay there. Somebody expecting a clean rewind should be
 * told before they press the button, not after.
 */
async function undoMerge(mergeId) {
    const ok = await showConfirm({
        title: t('tickets.merge.undo_title'),
        message: t('tickets.merge.undo_message'),
        okLabel: t('tickets.merge.undo'),
        okClass: 'danger'
    });
    if (!ok) return;

    try {
        const res = await fetch(API_BASE + 'undo_merge.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ merge_id: mergeId })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Could not undo the merge', 'error');
            return;
        }
        showToast(t('tickets.merge.undo_done').replace('%s', data.source_ticket_number || ''), 'success');
        await loadEmails();
        if (typeof loadFolderCounts === 'function') loadFolderCounts();
        selectEmailByTicketId(data.source_ticket_id);
    } catch (e) {
        showToast('Could not undo the merge', 'error');
    }
}

/** Jump to a ticket by its reference — used by the merge banner's button. */
async function openTicketByNumber(ticketNumber) {
    const row = Array.from(document.querySelectorAll('#emailList .email-item'))
        .find(el => (el.dataset.ticketNumber || '') === ticketNumber);
    if (row) { handleEmailRowClick({ ctrlKey: false, shiftKey: false, metaKey: false }, Number(row.dataset.emailId)); return; }
    // Not in the current folder's list — search for it so the jump works from
    // anywhere, which is the whole point of a redirect.
    if (typeof openSearchModal === 'function') {
        openSearchModal();
        const box = document.getElementById('searchInput');
        if (box) { box.value = ticketNumber; box.dispatchEvent(new Event('input', { bubbles: true })); }
    } else {
        showToast(ticketNumber, 'info');
    }
}

/**
 * The ⓘ preview badge (#91), guarded.
 *
 * ⚠️ Guarded because the pills are built by this file whether or not
 * record-preview.js loaded. An unguarded call would throw inside the template
 * literal and take the WHOLE links strip with it — a missing preview would cost
 * you the links, which is much worse than no preview.
 */
function rpBadge(type, id) {
    return window.FreeITSMPreview ? window.FreeITSMPreview.badge(type, id) : '';
}

function buildLinksSection(email) {
    const pills = [];

    // Problems (⚠) — open in the Problem module.
    (email.problems || []).forEach(p => {
        pills.push(`<a class="pm-ticket-badge" href="../problem-management/index.php?id=${p.id}" target="_blank" title="Problem: ${escapeHtml(p.title || '')}">
            ⚠ ${escapeHtml(p.problem_number || ('#' + p.id))}${p.status ? ' · ' + escapeHtml(p.status) : ''}
            ${rpBadge('problem', p.id)}
            <span class="pm-ticket-unlink" onclick="event.preventDefault();event.stopPropagation();unlinkTicketFromProblem(${p.id});">✕</span>
        </a>`);
    });

    // Changes (🔁) — open in the Change module.
    (email.changes || []).forEach(c => {
        const ref = 'CHG-' + String(c.id).padStart(4, '0');
        pills.push(`<a class="pm-ticket-badge" href="../change-management/index.php?id=${c.id}" target="_blank" title="Change: ${escapeHtml(c.title || '')}">
            🔁 ${escapeHtml(ref)}${c.status ? ' · ' + escapeHtml(c.status) : ''}
            ${rpBadge('change', c.id)}
            <span class="pm-ticket-unlink" onclick="event.preventDefault();event.stopPropagation();unlinkTicketFromChange(${c.id});">✕</span>
        </a>`);
    });

    // Linked tickets (🔗) — grouped by relation, open in-place.
    const L = email.linked_tickets || {};
    const tp = (item, prefix) => `<a class="pm-ticket-badge" href="#" onclick="event.preventDefault();loadTicketById(${item.ticket_id});" title="${escapeHtml(item.subject || '')}">
        🔗 ${escapeHtml(prefix)} ${escapeHtml(item.ticket_number || ('#' + item.ticket_id))}${item.status ? ' · ' + escapeHtml(item.status) : ''}
        ${rpBadge('ticket', item.ticket_id)}
        <span class="pm-ticket-unlink" onclick="event.preventDefault();event.stopPropagation();unlinkTicketLink(${item.link_id});">✕</span>
    </a>`;
    if (L.parent) pills.push(tp(L.parent, 'Parent:'));
    (L.children || []).forEach(c => pills.push(tp(c, 'Child:')));
    if (L.duplicate_of) pills.push(tp(L.duplicate_of, 'Duplicate of:'));
    (L.duplicates || []).forEach(d => pills.push(tp(d, 'Duplicate:')));
    (L.related || []).forEach(r => pills.push(tp(r, 'Related:')));

    // External issue trackers (#950). A Jira issue IS a link, so it belongs in
    // this strip rather than in a panel of its own — an analyst already looks
    // here for "what else is this connected to".
    //
    // The status shown is whatever the poll last cached; the pill never waits on
    // the tracker's API. The dot colours the four categories we normalise every
    // provider onto, so nothing here depends on Jira's vocabulary.
    (email.tracker_links || []).forEach(k => {
        const dot = { todo: '○', in_progress: '◐', done: '●', cancelled: '⊘' }[k.status_category] || '○';
        const ref = k.external_key || t('tickets.tracker.issue');
        const bits = [];
        if (k.status_name)   bits.push(k.status_name);
        if (k.assignee_name) bits.push(k.assignee_name);
        const title = [k.connection_name, bits.join(' · ')].filter(Boolean).join(' — ');
        pills.push(`<a class="pm-ticket-badge tracker-badge tracker-${escapeHtml(k.status_category || 'unknown')}"
            href="${escapeHtml(k.external_url || '#')}" target="_blank" rel="noopener"
            title="${escapeHtml(title)}">
            ${dot} ${escapeHtml(ref)}${bits.length ? ' · ' + escapeHtml(bits.join(' · ')) : ''}
        </a>`);
    });

    // Equipment and CMDB pills can't be built here: unlike the rest of this
    // strip they aren't on `email`, they need their own fetches. So the strip
    // renders empty holders and loadTicketAssets/loadCmdbObjects fill them in.
    //
    // That is also why the "nothing yet" note carries an id — those loaders may
    // arrive with pills a moment after it has already been drawn, and it has to
    // get out of the way when they do.
    const body = pills.length
        ? pills.join('')
        : `<span class="links-strip-empty" id="linksStripEmpty">Not linked to anything yet</span>`;

    return `<div class="problem-strip links-strip">
        <span class="problem-strip-label">Links</span>
        ${body}
        <span class="strip-pill-group" id="stripAssetPills"></span>
        <span class="strip-pill-group" id="stripCmdbPills"></span>
        <span class="strip-pill-group" id="stripTaskPills"></span>
        <div class="link-add-wrap">
            <button class="problem-link-btn" onclick="toggleLinkAddMenu(event)">Link to… ▾</button>
            <div class="link-add-menu" id="linkAddMenu">
                <button type="button" onclick="linkAddChoose('problem')">Problem</button>
                <button type="button" onclick="linkAddChoose('change')">Change</button>
                <button type="button" onclick="linkAddChoose('ticket')">Ticket</button>
                <button type="button" onclick="linkAddChoose('equipment')">${escapeHtml(t('tickets.assets.menu_item'))}</button>
                <button type="button" onclick="linkAddChoose('cmdb')">${escapeHtml(t('tickets.cmdb.menu_item'))}</button>
                <button type="button" onclick="linkAddChoose('tracker')">${escapeHtml(t('tickets.tracker.menu_item'))}</button>
                <button type="button" onclick="linkAddChoose('task')">${escapeHtml(t('tickets.tasks.menu_item'))}</button>
            </div>
        </div>
        <div class="strip-picker-host" id="stripPickerHost" hidden></div>
    </div>`;
}

/**
 * Hide the strip's "Not linked to anything yet" note once anything at all has
 * been linked — including the pills that arrive after the strip was drawn.
 * Called by both async loaders; safe to call when the note isn't there.
 */
function syncLinksStripEmpty() {
    const note = document.getElementById('linksStripEmpty');
    if (!note) return;
    const a = document.getElementById('stripAssetPills');
    const c = document.getElementById('stripCmdbPills');
    const has = (a && a.children.length) || (c && c.children.length);
    note.hidden = !!has;
}

/**
 * Shared picker chrome for the Links strip.
 *
 * Equipment and CMDB objects are searched rather than chosen from a modal like
 * problems and changes are, so they get an inline box under the strip instead.
 * One host, reused — two pickers can never be open at once, which is the point:
 * the strip is a single row and so is the thing hanging off it.
 *
 * ⚠️ The host lives INSIDE `.problem-strip`, not after it, and that is not a
 * layout preference. On a phone, mobile.js relocates the whole strip into a
 * full-screen "Links" sheet by selector. A sibling would be left behind in the
 * reading pane, so opening the picker would render it somewhere the user cannot
 * see. It wraps onto its own line because the strip is flex-wrap with a
 * full-width basis on this child.
 */
function openStripPicker(placeholder) {
    const host = document.getElementById('stripPickerHost');
    if (!host) return null;
    host.hidden = false;
    host.innerHTML = `
        <div class="strip-picker">
            <input type="text" id="stripPickerInput" placeholder="${escapeHtml(placeholder)}" autocomplete="off">
            <div class="strip-picker-results" id="stripPickerResults"></div>
        </div>`;
    const input   = document.getElementById('stripPickerInput');
    const results = document.getElementById('stripPickerResults');
    const close   = () => { host.hidden = true; host.innerHTML = ''; };
    setTimeout(() => input.focus(), 0);
    return { input, results, close };
}

// "Link to…" popover: pick Problem / Change / Ticket, then open its picker for
// the open ticket. Closes on the next click anywhere.
function toggleLinkAddMenu(e) {
    e.stopPropagation();
    const m = document.getElementById('linkAddMenu');
    if (!m) return;
    const open = m.classList.toggle('open');
    if (open) setTimeout(() => document.addEventListener('click', closeLinkAddMenu, { once: true }), 0);
}
function closeLinkAddMenu() {
    const m = document.getElementById('linkAddMenu');
    if (m) m.classList.remove('open');
}
function linkAddChoose(kind) {
    closeLinkAddMenu();
    if (!currentEmail) return;
    const id = currentEmail.ticket_id, ref = currentEmail.ticket_number, subj = currentEmail.subject || '';
    if (kind === 'problem') openLinkProblemModal(id, ref, subj);
    else if (kind === 'change') openLinkChangeModal(id, ref, subj);
    else if (kind === 'tracker') openEscalateTrackerModal(id, ref);
    else if (kind === 'equipment') openLinkAssetPicker(id);
    else if (kind === 'cmdb') openLinkCmdbPicker(id);
    else if (kind === 'task') openLinkTaskPicker(id);
    else openLinkTicketModal(id, ref, subj);
}

// ============================================================
// Tasks on a ticket (discussion #83).
//
// Deliberately no new button: tasks are a seventh entry in the "Link to…" menu
// that already existed, and task pills sit in the same strip as problems,
// changes, equipment and Jira issues. The ticket screen is busy enough, and
// this is the place an analyst already looks to answer "what else is this
// connected to".
// ============================================================

let tasksForTicket = [];

async function loadTicketTasks(ticketId) {
    const host = document.getElementById('stripTaskPills');
    if (!host) return;
    // Clear FIRST. Otherwise a failed fetch leaves the previous ticket's tasks in
    // place, and the close warning then reports a count belonging to a ticket the
    // analyst is no longer looking at.
    tasksForTicket = [];
    try {
        const res = await fetch('../api/tickets/get_ticket_tasks.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) return;
        tasksForTicket = data.tasks || [];
        renderTicketTasks(ticketId);
    } catch (e) { /* silent — the strip simply shows no task pills */ }
}

function renderTicketTasks(ticketId) {
    const host = document.getElementById('stripTaskPills');
    if (!host) return;

    host.innerHTML = (tasksForTicket || []).map(tk => {
        // ☑ / ☐ carries the state at a glance; the fraction is the subtask
        // progress #83 asked for, and is omitted entirely when there are no
        // subtasks rather than showing a meaningless 0/0.
        const box  = tk.status_is_closed ? '&#9745;' : '&#9744;';
        const prog = tk.subtasks_total > 0 ? ` ${tk.subtasks_done}/${tk.subtasks_total}` : '';
        const bits = [];
        if (tk.status) bits.push(tk.status);
        if (tk.analyst_name) bits.push(tk.analyst_name);
        const cls = tk.status_is_closed ? 'pm-ticket-badge task-badge task-done' : 'pm-ticket-badge task-badge';
        // ⚠️ `?task=`, not `?id=`. assets/js/tasks.js reads exactly that one
        // parameter, so the wrong name opens the Tasks board and quietly does
        // nothing — a link that looks like it worked. Same trap the notification
        // deep-links document for `?ticket_id=`.
        return `<a class="${cls}" href="../tasks/index.php?task=${tk.id}" target="_blank"
                   title="${escapeHtml(bits.join(' · '))}">
            ${box} ${escapeHtml(tk.title)}${prog}
            ${rpBadge('task', tk.id)}
            <span class="pm-ticket-unlink" title="${escapeHtml(t('tickets.tasks.unlink_title'))}"
                  onclick="event.preventDefault();event.stopPropagation();unlinkTicketTask(event, ${tk.id}, ${ticketId});">&#10005;</span>
        </a>`;
    }).join('');

    // The strip's "nothing yet" note is drawn before these arrive, so it has to
    // get out of the way once there is something to show.
    if (tasksForTicket.length) {
        const empty = document.getElementById('linksStripEmpty');
        if (empty) empty.style.display = 'none';
    }
}

/**
 * One picker, both verbs. Typing searches existing tasks; if what you typed
 * matches nothing (or nothing quite right) the first row creates a task with
 * that title. That is why linking and creating are not two menu entries: you
 * see the near-matches as you type, so you link the task that already exists
 * instead of quietly making a second one.
 */
function openLinkTaskPicker(ticketId) {
    const ui = openStripPicker(t('tickets.tasks.search_placeholder'));
    if (!ui) return;
    const { input, results, close } = ui;
    let timer = null;

    // One flat list so the rows are index-addressable: the "create" row is just
    // the first entry rather than a special case, which is what lets creating
    // and linking be the same gesture all the way down to the click handler.
    let current = [];

    const render = () => {
        if (!current.length) {
            results.innerHTML = `<div class="asset-picker-empty">${escapeHtml(
                input.value.trim() === '' ? t('tickets.tasks.type_to_search') : t('tickets.tasks.no_matches')
            )}</div>`;
            results.classList.add('active');
            return;
        }

        let lastKind = null;
        results.innerHTML = current.map((r, i) => {
            const heading = r.kind !== lastKind
                ? `<div class="asset-picker-group">${escapeHtml(
                    r.kind === 'create' ? t('tickets.tasks.group_create') : t('tickets.tasks.group_existing'))}</div>`
                : '';
            lastKind = r.kind;

            if (r.kind === 'create') {
                return heading + `<div class="asset-picker-result" data-idx="${i}">
                    <span>&#10010; ${escapeHtml(t('tickets.tasks.create_named', { title: r.title }))}</span>
                </div>`;
            }
            const box    = r.status_is_closed ? '&#9745;' : '&#9744;';
            const detail = r.linked_elsewhere
                ? t('tickets.tasks.moves_from', { ticket: r.linked_ticket_number || '' })
                : (r.status || '');
            return heading + `<div class="asset-picker-result" data-idx="${i}">
                <span>${box} ${escapeHtml(r.title)}</span>
                <span class="asset-picker-detail">${escapeHtml(detail)}</span>
            </div>`;
        }).join('');

        // ⚠️ Without this the rows are written into a container that CSS keeps at
        // display:none, so the picker looks completely dead while working
        // perfectly. That is exactly how this shipped the first time.
        results.classList.add('active');

        // Bound rather than inlined: a task title carrying an apostrophe or a
        // quote would break an onclick attribute built by string concatenation.
        results.querySelectorAll('.asset-picker-result').forEach(el => {
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                pick(current[parseInt(el.dataset.idx, 10)]);
            });
        });
    };

    const pick = async (r) => {
        if (!r) return;
        if (r.kind === 'create') { createTaskForTicket(ticketId, r.title); return; }

        // ⚠️ A task belongs to ONE ticket — `tasks.ticket_id` is a single
        // column — so linking one that is already attached MOVES it off the
        // other ticket. The picker labelled the row "moves from TICKET-x", but a
        // label you may have read is not consent, and the other ticket loses a
        // task without anybody being asked. (Ed)
        //
        // The task side asks before replacing a link; this is the same event
        // approached from the other end, so it asks in the same way.
        if (r.linked_elsewhere) {
            const ok = await showConfirm({
                title:   t('tickets.tasks.move_title'),
                message: t('tickets.tasks.move_confirm', {
                    title:  r.title || '',
                    ticket: r.linked_ticket_number || t('tickets.tasks.another_ticket')
                }),
                okLabel: t('tickets.tasks.move_ok'),
                okClass: 'primary'
            });
            if (!ok) return;
        }
        linkTaskToTicket(ticketId, r.id);
    };

    const search = async (typed) => {
        let rows = [];
        try {
            const res = await fetch('../api/tickets/search_linkable_tasks.php?ticket_id=' + ticketId
                + '&q=' + encodeURIComponent(typed));
            const data = await res.json();
            if (data.success) rows = data.results || [];
        } catch (e) { /* still offer to create what they typed */ }

        current = [{ kind: 'create', title: typed }]
            .concat(rows.map(r => Object.assign({ kind: 'task' }, r)));
        render();
    };

    input.oninput = () => {
        clearTimeout(timer);
        const typed = input.value.trim();
        if (typed === '') { current = []; render(); return; }
        timer = setTimeout(() => search(typed), 200);
    };

    input.onkeydown = e => { if (e.key === 'Escape') close(); };

    render();   // "type to search" straight away, so the box never looks broken
}

async function createTaskForTicket(ticketId, title) {
    await postTaskLink(ticketId, { ticket_id: ticketId, title: title },
        t('tickets.tasks.created_toast', { title: title }));
}

async function linkTaskToTicket(ticketId, taskId) {
    await postTaskLink(ticketId, { ticket_id: ticketId, task_id: taskId },
        t('tickets.tasks.linked_toast'));
}

async function postTaskLink(ticketId, body, okMessage) {
    try {
        const res = await fetch('../api/tickets/save_ticket_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || t('tickets.tasks.link_failed'), 'error');
            return;
        }
        const host = document.getElementById('stripPickerHost');
        if (host) { host.hidden = true; host.innerHTML = ''; }
        showToast(okMessage, 'success');
        await loadTicketTasks(ticketId);
    } catch (e) {
        showToast(t('tickets.tasks.link_failed'), 'error');
    }
}

async function unlinkTicketTask(event, taskId, ticketId) {
    event.preventDefault();
    event.stopPropagation();
    // Unlink, not delete — say so, because a ✕ on a pill could reasonably be
    // read either way and one of those readings destroys somebody's work.
    if (!(await showConfirm({
        title: t('tickets.tasks.unlink_title'),
        message: t('tickets.tasks.unlink_confirm'),
        okLabel: 'OK', okClass: 'primary'
    }))) return;
    try {
        const res = await fetch('../api/tickets/delete_ticket_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, task_id: taskId })
        });
        const data = await res.json();
        if (!data.success) { showToast(data.error || t('tickets.tasks.link_failed'), 'error'); return; }
        showToast(t('tickets.tasks.unlinked_toast'), 'success');
        await loadTicketTasks(ticketId);
    } catch (e) {
        showToast(t('tickets.tasks.link_failed'), 'error');
    }
}

// ============================================================
// Escalate to an external issue tracker (#950).
//
// The MANUAL entry point. It calls the same service the workflow action does —
// api/integrations/escalate_ticket.php → integrationsEscalate() — so every
// guard, including the company check, has exactly one implementation.
//
// ⚠️ The preview is the point. This is a one-way door into a system we do not
// control: once the issue exists we cannot unsend it, and everyone with access
// to that tracker can read it. So the analyst sees the exact text FIRST, and
// the modal opens with the escalate button disabled until they have.
// ============================================================
let escalateTicketId = null;

async function openEscalateTrackerModal(ticketId, ref) {
    escalateTicketId = ticketId;
    const modal = document.getElementById('escalateTrackerModal');
    if (!modal) return;

    document.getElementById('escTicketRef').textContent = ref || ('#' + ticketId);
    document.getElementById('escProject').value = '';
    document.getElementById('escIssueType').value = 'Bug';
    document.getElementById('escPreview').textContent = t('tickets.tracker.loading_preview');
    document.getElementById('escSummary').value = '';
    // ⚠️ classList.add('active'), NOT style.display. `.modal` is already
    // `display: flex` and is hidden with visibility/opacity, so setting display
    // achieves precisely nothing — the modal stays invisible and the click looks
    // like it did nothing at all.
    modal.classList.add('active');

    // Only connections this ticket's company may actually use — the endpoint
    // filters by the same rule the service enforces, so the analyst is never
    // offered a tracker that would then refuse them.
    const sel = document.getElementById('escConnection');
    sel.innerHTML = `<option>${escapeHtml(t('common.loading'))}</option>`;
    try {
        const r = await fetch(`../api/integrations/connections_for_ticket.php?ticket_id=${ticketId}`);
        const j = await r.json();
        const list = (j && j.connections) || [];
        sel.innerHTML = list.length
            ? list.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('')
            : `<option value="">${escapeHtml(t('tickets.tracker.no_connections'))}</option>`;
        document.getElementById('escalateGoBtn').disabled = !list.length;
    } catch (e) {
        sel.innerHTML = `<option value="">${escapeHtml(t('tickets.tracker.no_connections'))}</option>`;
        document.getElementById('escalateGoBtn').disabled = true;
    }

    // Build the description server-side and show it verbatim. Preview mode never
    // touches the tracker.
    try {
        const r = await fetch('../api/integrations/escalate_ticket.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ticket_id: ticketId, preview: 1})
        });
        const j = await r.json();
        if (j.success) {
            document.getElementById('escSummary').value = j.summary || '';
            document.getElementById('escPreview').textContent = j.body || '';
            renderEscalateAttachments(j.attachments || []);
        } else {
            document.getElementById('escPreview').textContent = j.error || '';
            renderEscalateAttachments([]);
        }
    } catch (e) {
        document.getElementById('escPreview').textContent = t('tickets.tracker.preview_failed');
    }
}

/**
 * The files that will travel with the issue.
 *
 * ⚠️ Shown BEFORE anything is sent, for the same reason the description is: an
 * attachment cannot be unsent, and a screenshot can carry far more than the
 * person attaching it intended. A file that will NOT be sent (too large, or
 * missing on disk) is still listed, struck through — "it silently did not go"
 * is exactly the surprise this is here to prevent.
 */
function renderEscalateAttachments(files) {
    const box = document.getElementById('escAttachments');
    if (!box) return;
    if (!files.length) { box.style.display = 'none'; box.innerHTML = ''; return; }

    const rows = files.map(f => {
        const skipped = !!f.skip_reason;
        const name = escapeHtml(f.filename) + ' <span style="opacity:.65">(' + escapeHtml(f.size_human) + ')</span>';
        return '<li style="margin:2px 0;' + (skipped ? 'opacity:.55;text-decoration:line-through;' : '') + '">'
             + name
             + (skipped ? ' <span style="text-decoration:none;font-style:italic">— '
                          + escapeHtml(t('tickets.tracker.attach_skipped')) + '</span>' : '')
             + '</li>';
    }).join('');

    const sending = files.filter(f => !f.skip_reason).length;
    box.innerHTML =
        '<div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:4px;">'
        + escapeHtml(t('tickets.tracker.attach_heading').replace('{count}', sending)) + '</div>'
        + '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--text-muted);">' + rows + '</ul>';
    box.style.display = '';
}

function closeEscalateTrackerModal() {
    const m = document.getElementById('escalateTrackerModal');
    if (m) m.classList.remove('active');
    escalateTicketId = null;
}

async function submitEscalateTracker() {
    if (!escalateTicketId) return;
    const btn = document.getElementById('escalateGoBtn');
    const project = document.getElementById('escProject').value.trim();
    if (!project) { showToast(t('tickets.tracker.project_required'), 'error'); return; }

    btn.disabled = true;
    try {
        const r = await fetch('../api/integrations/escalate_ticket.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                ticket_id:     escalateTicketId,
                connection_id: document.getElementById('escConnection').value,
                project:       project,
                issue_type:    document.getElementById('escIssueType').value.trim() || 'Bug',
                summary:       document.getElementById('escSummary').value.trim()
            })
        });
        const j = await r.json();
        if (j.success) {
            // The tracker's key is the useful bit of feedback — it is what the
            // analyst will quote to the dev team.
            const key = (j.link && j.link.external_key) ? j.link.external_key : '';
            showToast(key ? t('tickets.tracker.raised').replace('{key}', key)
                          : t('tickets.tracker.raised_generic'), 'success');
            // Capture the id BEFORE closing — closing nulls it, and reloading
            // ticket 0 would blank the pane just as the analyst wants to see the
            // new pill appear.
            const reloadId = escalateTicketId;
            closeEscalateTrackerModal();
            loadTicketById(reloadId);
        } else {
            // Pass the real reason through: "Epic Link is required", or the
            // company guard's refusal. A generic failure teaches nobody anything.
            showToast(j.error || t('tickets.tracker.failed'), 'error');
        }
    } catch (e) {
        showToast(t('tickets.tracker.failed'), 'error');
    } finally {
        btn.disabled = false;
    }
}

// Right-click "Link to ticket…" — targets whichever ticket was right-clicked.
function openContextLinkTicket() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    openLinkTicketModal(ctxTargetTicketId, ctxTargetTicketRef, '');
}

let linkTicketSourceId = null;
let linkTicketSourceRef = '';
let linkTicketSearchTimer = null;

function openLinkTicketModal(ticketId, ticketRef, subject) {
    linkTicketSourceId = ticketId;
    linkTicketSourceRef = ticketRef || ('Ticket ' + ticketId);
    document.getElementById('linkTicketRef').textContent = linkTicketSourceRef;
    const rel = document.querySelector('input[name="ticketLinkRelation"][value="related"]');
    if (rel) rel.checked = true;
    const s = document.getElementById('linkTicketSearch'); if (s) s.value = '';
    document.getElementById('linkTicketModal').classList.add('active');
    loadLinkTicketList();
}
function closeLinkTicketModal() { document.getElementById('linkTicketModal').classList.remove('active'); }
function linkTicketSearchDebounced() { clearTimeout(linkTicketSearchTimer); linkTicketSearchTimer = setTimeout(loadLinkTicketList, 250); }

async function loadLinkTicketList() {
    const list = document.getElementById('linkTicketList');
    const q = (document.getElementById('linkTicketSearch') || {}).value || '';
    list.innerHTML = '<div class="lp-empty">Loading…</div>';
    try {
        const data = await fetch('../api/tickets/list_linkable_tickets.php?source_ticket_id=' + encodeURIComponent(linkTicketSourceId) + '&q=' + encodeURIComponent(q.trim())).then(r => r.json());
        if (!data.success) { list.innerHTML = '<div class="lp-empty">' + escapeHtml(data.error || 'Failed to load') + '</div>'; return; }
        const rows = (data.tickets || []).map(tk => `<div class="lp-row" onclick="pickLinkTicket(${tk.id})">
            <span class="lp-num">${escapeHtml(tk.ticket_number || ('#' + tk.id))}</span>
            <span class="lp-title">${escapeHtml(tk.subject || '')}</span>
            <span class="lp-status">${escapeHtml(tk.status || '')}</span></div>`).join('');
        list.innerHTML = rows || '<div class="lp-empty">' + (q.trim() ? 'No matching tickets.' : 'No other tickets found.') + '</div>';
    } catch (e) { list.innerHTML = '<div class="lp-empty">Failed to load tickets</div>'; }
}

async function pickLinkTicket(targetId) {
    const relEl = document.querySelector('input[name="ticketLinkRelation"]:checked');
    const relation = relEl ? relEl.value : 'related';
    try {
        const res = await fetch('../api/tickets/create_ticket_link.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_ticket_id: linkTicketSourceId, target_ticket_id: targetId, relation: relation })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Tickets linked', 'success');
            closeLinkTicketModal();
            if (currentEmail && currentEmail.ticket_id == linkTicketSourceId) selectEmail(currentEmail.id);
        } else showToast(data.error || 'Could not link', 'error');
    } catch (e) { showToast('Failed to link tickets', 'error'); }
}

async function unlinkTicketLink(linkId) {
    try {
        const res = await fetch('../api/tickets/delete_ticket_link.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (data.success) { showToast('Unlinked', 'success'); if (currentEmail) selectEmail(currentEmail.id); }
        else showToast(data.error || 'Failed', 'error');
    } catch (e) { showToast('Failed', 'error'); }
}

function buildRecordingsStrip(recordings) {
    if (!recordings || !recordings.length) return '';
    const cards = recordings.map(r => {
        const url = `../api/self-service/get_recording.php?id=${r.id}`;
        const sizeMb = (r.file_size / 1048576).toFixed(1);
        const durLabel = r.duration_seconds ? formatRecordingDuration(r.duration_seconds) : '';
        const audioLabel = r.has_audio ? ' &middot; with audio' : '';
        return `
            <div class="recording-card">
                <video controls preload="metadata" src="${url}"></video>
                <div class="recording-meta">
                    ${escapeHtml(r.original_filename || 'recording')}
                    &middot; ${sizeMb} MB
                    ${durLabel ? '&middot; ' + durLabel : ''}
                    ${audioLabel}
                </div>
            </div>`;
    }).join('');
    return `
        <div class="recordings-strip">
            <div class="recordings-strip-header">🎥 Screen recordings (${recordings.length})</div>
            ${cards}
        </div>`;
}

function formatRecordingDuration(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}

// Load and display all correspondence for a ticket. isAuto=true marks a 15s
// background refresh (channel tickets) so we don't disturb the analyst's draft.
async function loadCorrespondenceThread(ticketId, isAuto = false) {
    const container = document.getElementById('threadContainer');
    if (!container) { if (isAuto) stopChannelAutoRefresh(); return; }

    try {
        const response = await fetch(`${API_BASE}get_ticket_thread.php?ticket_id=${ticketId}`);
        const data = await response.json();

        // Remember the channel so Reply composes over WhatsApp etc. rather than email.
        currentTicketChannel = data.channel || 'email';
        currentChannelWindowOpen = !!data.window_open;
        currentChannelProvider = data.channel_provider || '';

        // Render the composer on a fresh open, or when the 24h-window state flips —
        // but NOT on every auto-refresh, so an in-progress draft/template isn't wiped.
        if (currentTicketChannel === 'email' || !isAuto || currentChannelWindowOpen !== lastComposerWindowOpen) {
            renderChannelComposer(ticketId);
        }
        lastComposerWindowOpen = currentChannelWindowOpen;

        if (data.success && data.emails && data.emails.length > 0) {
            // Reverse so most recent email is at the top
            const emails = [...data.emails].reverse();
            container.innerHTML = emails.map((e, index) => {
                const isOutbound = e.direction === 'Outbound';
                return `
                    ${index > 0 ? '<div class="thread-separator"></div>' : ''}
                    ${/* Right-click on the message HEADER opens split too — that is what
                          an analyst reaches for, and it was the first thing Ed tried.
                          Deliberately NOT on the message body: analysts copy text out of
                          messages constantly, and stealing the browser's own context menu
                          to save one click would be a bad trade. */''}
                    <div class="thread-meta" oncontextmenu="event.preventDefault(); openSplitModal(${ticketId}, ${e.id}); return false;">
                        <span class="thread-direction-badge ${isOutbound ? 'outbound' : 'inbound'}">${escapeHtml(isOutbound ? t('tickets.reading_pane.badge_sent') : t('tickets.reading_pane.badge_received'))}</span>
                        <strong>${senderLabel(e.from_name, e.from_address, false)}</strong>
                        ${e.from_address ? '&lt;' + escapeHtml(e.from_address) + '&gt; ' : ''}&mdash; ${formatFullDateTime(e.received_datetime)}
                        ${/* Split starts FROM a message, so the control belongs on the
                              message — not in the toolbar, where you would first have to
                              say which one. Revealed on hover: it is an occasional action
                              and eleven of them would shout over the conversation. */''}
                        <button type="button" class="thread-split-btn" title="${escapeHtml(t('tickets.split.from_here_title'))}"
                                onclick="openSplitModal(${ticketId}, ${e.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v6a3 3 0 0 0 3 3h6"/><path d="M6 21v-6"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="12" r="3"/></svg>
                            <span>${escapeHtml(t('tickets.split.from_here'))}</span>
                        </button>
                    </div>
                    ${e.same_kind && MC.flag_duplicates ? `<div class="dup-note">${escapeHtml(
                        e.same_kind === 'identical'
                            ? t('tickets.reading.same_as_identical').replace('{time}', formatFullDateTime(e.same_as_time))
                            : t('tickets.reading.same_as_near').replace('{time}', formatFullDateTime(e.same_as_time)))}</div>` : ''}
                    ${emailBodyHost(e.body_content, 'thread-message-body' + (e.same_kind && MC.flag_duplicates ? ' mc-force' : ''), e.body_type)}
                `;
            }).join('');
            // Isolate each thread body in a shadow root (see emailBodyHost).
            hydrateEmailBodies(container);
            tgGroupOlder(container);
        }
    } catch (error) {
        console.error('Error loading thread:', error);
    }

    // Manage the 15s live refresh for channel tickets. Only (re)arm on a fresh open;
    // the timer itself calls back with isAuto=true.
    if (!isAuto) {
        stopChannelAutoRefresh();
        if (currentTicketChannel !== 'email') {
            channelRefreshTimer = setInterval(() => {
                // Stop if the analyst is no longer viewing this ticket's thread.
                if (!document.getElementById('threadContainer')) { stopChannelAutoRefresh(); return; }
                loadCorrespondenceThread(ticketId, true);
                loadTicketAttachments(ticketId); // keep the "N attachments" bar current
            }, 15000);
        }
    }
}

function stopChannelAutoRefresh() {
    if (channelRefreshTimer) {
        clearInterval(channelRefreshTimer);
        channelRefreshTimer = null;
    }
}

// Render (or remove) the inline channel reply composer for WhatsApp-style tickets.
// Email tickets use the existing email modal and get no composer here.
function renderChannelComposer(ticketId) {
    const existing = document.getElementById('channelComposer');
    if (currentTicketChannel === 'email') {
        if (existing) existing.remove();
        return;
    }

    const label = currentTicketChannel === 'whatsapp' ? 'WhatsApp' : currentTicketChannel;

    let inner;
    if (currentChannelWindowOpen) {
        // Inside the 24h window: free-text composer.
        inner = `
            <textarea id="channelComposerText" class="channel-composer-text" rows="3" placeholder="Type your reply…"></textarea>
            <div class="channel-composer-actions">
                <button class="action-btn" onclick="aiSuggestChannelReply(${ticketId})" title="Draft a reply with AI">
                    <span class="action-btn-icon">🤖</span><span>Suggest</span>
                </button>
                <button class="action-btn" onclick="aiSummariseChannel(${ticketId})" title="Summarise this conversation into the ticket">
                    <span class="action-btn-icon">📝</span><span>Summarise</span>
                </button>
                <button class="action-btn action-btn-primary" id="channelSendBtn" onclick="sendChannelMessage(${ticketId})">
                    <span class="action-btn-icon">📤</span><span>Send</span>
                </button>
            </div>`;
    } else {
        // Window closed: only a pre-approved template can re-open the conversation.
        inner = `
            <div class="channel-window-closed">⏳ The 24-hour reply window has closed. Free-text replies are blocked by WhatsApp — send a pre-approved template to re-open the conversation.</div>
            <label class="channel-tpl-label">Template</label>
            <select id="channelTemplateSelect" class="channel-composer-text" onchange="onChannelTemplatePick(${ticketId})">
                <option value="">Loading templates…</option>
            </select>
            <div id="channelTemplateVars"></div>
            <div class="channel-composer-actions">
                <button class="action-btn" onclick="aiSummariseChannel(${ticketId})" title="Summarise this conversation into the ticket">
                    <span class="action-btn-icon">📝</span><span>Summarise</span>
                </button>
                <button class="action-btn action-btn-primary" id="channelSendTplBtn" onclick="sendChannelTemplate(${ticketId})" disabled>
                    <span class="action-btn-icon">📤</span><span>Send template</span>
                </button>
            </div>`;
    }

    const html = `
        <div id="channelComposer" class="channel-composer">
            <div class="channel-composer-head">
                <span class="thread-direction-badge outbound">${escapeHtml(label)}</span>
                <span class="channel-composer-title">Reply to the customer over ${escapeHtml(label)}</span>
            </div>
            ${inner}
        </div>`;

    const body = document.querySelector('.email-body');
    if (!body) return;
    if (existing) {
        existing.outerHTML = html;
    } else {
        body.insertAdjacentHTML('afterbegin', html);
    }

    if (!currentChannelWindowOpen) {
        loadChannelTemplates();
    }
}

// Load the templates matching this channel's provider into the picker.
async function loadChannelTemplates() {
    const sel = document.getElementById('channelTemplateSelect');
    if (!sel) return;
    try {
        const q = currentChannelProvider ? ('?provider=' + encodeURIComponent(currentChannelProvider)) : '';
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'get_templates.php' + q, { credentials: 'same-origin' });
        const data = await res.json();
        channelTemplates = (data.success && data.templates) ? data.templates : [];
        if (!channelTemplates.length) {
            sel.innerHTML = '<option value="">No templates set up — add them in Settings → Messaging</option>';
            return;
        }
        sel.innerHTML = '<option value="">— choose a template —</option>' +
            channelTemplates.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
    } catch (e) {
        sel.innerHTML = '<option value="">Failed to load templates</option>';
    }
}

// When a template is chosen, render an input per {{n}} variable + a live preview.
function onChannelTemplatePick(ticketId) {
    const sel = document.getElementById('channelTemplateSelect');
    const varsEl = document.getElementById('channelTemplateVars');
    const sendBtn = document.getElementById('channelSendTplBtn');
    const tpl = channelTemplates.find(t => String(t.id) === String(sel.value));
    if (!tpl) { varsEl.innerHTML = ''; if (sendBtn) sendBtn.disabled = true; return; }

    let fields = '';
    for (let i = 1; i <= (tpl.var_count || 0); i++) {
        fields += `<input type="text" class="channel-composer-text channel-tpl-var" data-idx="${i}" placeholder="Value for {{${i}}}" oninput="updateChannelTemplatePreview()" style="margin-top:6px;">`;
    }
    varsEl.innerHTML = `
        ${fields}
        <div class="channel-tpl-preview" id="channelTemplatePreview"></div>`;
    if (sendBtn) sendBtn.disabled = false;
    updateChannelTemplatePreview();
}

// Live preview of the rendered template (placeholders filled in).
function updateChannelTemplatePreview() {
    const sel = document.getElementById('channelTemplateSelect');
    const tpl = channelTemplates.find(t => String(t.id) === String(sel && sel.value));
    const prev = document.getElementById('channelTemplatePreview');
    if (!tpl || !prev) return;
    const vals = Array.from(document.querySelectorAll('.channel-tpl-var')).map(i => i.value);
    let body = tpl.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => vals[parseInt(n, 10) - 1] || m);
    prev.textContent = body;
}

// Send the chosen template.
async function sendChannelTemplate(ticketId) {
    const sel = document.getElementById('channelTemplateSelect');
    const btn = document.getElementById('channelSendTplBtn');
    if (!sel || !sel.value) { showToast('Choose a template first', 'error'); return; }
    const vars = Array.from(document.querySelectorAll('.channel-tpl-var')).map(i => i.value.trim());
    if (vars.some(v => v === '')) { showToast('Fill in all template values', 'error'); return; }

    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span>Sending…</span>'; }
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'send_template.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId, template_id: parseInt(sel.value, 10), vars })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Template sent', 'success');
            loadCorrespondenceThread(ticketId);
        } else {
            showToast('Could not send: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (e) {
        showToast('Failed to send template', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }
}

// Send the analyst's reply out over the ticket's channel.
async function sendChannelMessage(ticketId) {
    const ta = document.getElementById('channelComposerText');
    const btn = document.getElementById('channelSendBtn');
    if (!ta) return;
    const body = ta.value.trim();
    if (!body) { showToast('Type a message first', 'error'); return; }

    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span>Sending…</span>'; }
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'send_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId, body })
        });
        const data = await res.json();
        if (data.success) {
            ta.value = '';
            showToast('Message sent', 'success');
            loadCorrespondenceThread(ticketId);
        } else {
            showToast('Could not send: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (e) {
        showToast('Failed to send message', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }
}

// AI: draft a suggested reply into the composer (analyst reviews before sending).
async function aiSuggestChannelReply(ticketId) {
    const ta = document.getElementById('channelComposerText');
    if (!ta || ta.disabled) return;
    showToast('Drafting a reply…', 'info');
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'ai_suggest_reply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success && data.reply) {
            ta.value = data.reply;
            ta.focus();
        } else {
            showToast(data.error || 'Could not draft a reply', 'error');
        }
    } catch (e) {
        showToast('Failed to draft a reply', 'error');
    }
}

// AI: summarise the conversation and save it as an internal note on the ticket.
async function aiSummariseChannel(ticketId) {
    showToast('Summarising…', 'info');
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'ai_summary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Summary added to ticket notes', 'success');
            if (typeof loadNotes === 'function') loadNotes(ticketId);
        } else {
            showToast(data.error || 'Could not summarise', 'error');
        }
    } catch (e) {
        showToast('Failed to summarise', 'error');
    }
}

// Assign department
async function assignDepartment() {
    const departmentId = document.getElementById('departmentSelect').value;
    const oldValue = getDisplayName('department', currentEmail.department_id);
    const newValue = getDisplayName('department', departmentId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                department_id: departmentId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Department', oldValue, newValue);
            currentEmail.department_id = departmentId || null;
            updatePropertiesSummary();
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Error assigning department: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign department', 'error');
    }
}

// Assign ticket type
async function assignTicketType() {
    const ticketTypeId = document.getElementById('ticketTypeSelect').value;
    const oldValue = getDisplayName('ticket_type', currentEmail.ticket_type_id);
    const newValue = getDisplayName('ticket_type', ticketTypeId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                ticket_type_id: ticketTypeId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Ticket Type', oldValue, newValue);
            currentEmail.ticket_type_id = ticketTypeId || null;
        } else {
            showToast('Error assigning ticket type: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign ticket type', 'error');
    }
}

// Assign status
async function assignStatus() {
    const select = document.getElementById('statusSelect');
    const status = select.value;
    const oldValue = currentEmail.status;

    // #83: closing a ticket that still has unfinished tasks WARNS, it never
    // blocks — the same line collision detection draws. The analyst may well
    // know the remaining task is somebody else's problem now.
    //
    // No request: the strip has already loaded this ticket's tasks. If that
    // fetch failed, tasksForTicket is empty and this simply says nothing, which
    // is the right way round — a warning that cannot be shown must not become a
    // block that cannot be cleared.
    const closing = ticketStatuses.some(s => s.name === status && s.is_closed);
    const openTasks = (tasksForTicket || []).filter(tk => !tk.status_is_closed).length;
    if (closing && openTasks > 0) {
        const ok = await showConfirm({
            title: 'Confirm',
            message: t('tickets.tasks.close_with_open', { count: openTasks }),
            okLabel: 'OK', okClass: 'primary'
        });
        if (!ok) {
            select.value = oldValue;   // or the dropdown shows a status never applied
            return;
        }
    }

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                status: status
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Status', oldValue, status);
            currentEmail.status = status;
            updatePropertiesSummary();
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Error assigning status: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign status', 'error');
    }
}

// Assign priority. Sends priority_id (or null for the "no priority" blank
// option) to assign_ticket.php; the SLA engine recomputes lazily on next
// read, so we don't need a separate recompute call here.
async function assignPriority() {
    const priorityId = document.getElementById('prioritySelect').value;
    const oldPriority = ticketPriorities.find(p => p.id == currentEmail.priority_id);
    const newPriority = ticketPriorities.find(p => p.id == priorityId);
    const oldLabel = oldPriority ? oldPriority.name : '';
    const newLabel = newPriority ? newPriority.name : '';

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                priority_id: priorityId === '' ? null : priorityId,
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Priority', oldLabel, newLabel);
            currentEmail.priority_id = priorityId === '' ? null : Number(priorityId);
            currentEmail.priority    = newLabel;
            updatePropertiesSummary();
            loadEmails();
        } else {
            showToast('Error assigning priority: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign priority', 'error');
    }
}

// Assign origin
async function assignOrigin() {
    const originId = document.getElementById('originSelect').value;
    const oldValue = getDisplayName('origin', currentEmail.origin_id);
    const newValue = getDisplayName('origin', originId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                origin_id: originId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Origin', oldValue, newValue);
            currentEmail.origin_id = originId || null;
        } else {
            showToast('Error assigning origin: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign origin', 'error');
    }
}

// Assign first time fix
async function assignFirstTimeFix() {
    const value = document.getElementById('firstTimeFixSelect').value;
    const oldValue = currentEmail.first_time_fix === null ? null : (currentEmail.first_time_fix ? 'Yes' : 'No');
    const newValue = value === '' ? null : (value === '1' ? 'Yes' : 'No');

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                first_time_fix: value === '' ? null : (value === '1' ? 1 : 0)
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'First Time Fix', oldValue, newValue);
            currentEmail.first_time_fix = value === '' ? null : (value === '1');
        } else {
            showToast('Error assigning first time fix: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign first time fix', 'error');
    }
}

// Assign IT training provided
async function assignItTraining() {
    const value = document.getElementById('itTrainingSelect').value;
    const oldValue = currentEmail.it_training_provided === null ? null : (currentEmail.it_training_provided ? 'Yes' : 'No');
    const newValue = value === '' ? null : (value === '1' ? 'Yes' : 'No');

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                it_training_provided: value === '' ? null : (value === '1' ? 1 : 0)
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'IT Training', oldValue, newValue);
            currentEmail.it_training_provided = value === '' ? null : (value === '1');
        } else {
            showToast('Error assigning IT training: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign IT training', 'error');
    }
}

// Assign owner (analyst)
async function assignOwner() {
    const ownerId = document.getElementById('ownerSelect').value;
    const oldValue = getDisplayName('owner', currentEmail.owner_id);
    const newValue = getDisplayName('owner', ownerId);

    try {
        const response = await fetch(API_BASE + 'update_ticket_owner.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                owner_id: ownerId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Owner', oldValue, newValue);
            currentEmail.owner_id = ownerId || null;
            updatePropertiesSummary();
        } else {
            showToast('Error assigning owner: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign owner', 'error');
    }
}

// Delete ticket
async function requestCsatSurvey() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }
    if (!(await showConfirm({ title: 'Confirm', message: 'Send a satisfaction survey email to the requester?', okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const res = await fetch(`${API_BASE}request_csat.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Survey email sent.', 'error');
        } else {
            showToast('Could not send survey: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (err) {
        showToast('Failed: ' + err.message, 'error');
    }
}

async function deleteTicket() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }

    if (!(await showConfirm({ title: 'Move to trash', message: 'Move this ticket to the trash? You can restore it from the Trash folder.', okLabel: 'Move to trash', okClass: 'danger' }))) return;

    try {
        const response = await fetch(API_BASE + 'delete_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id
            })
        });
        const data = await response.json();

        if (data.success) {
            // Clear current selection
            currentEmail = null;
            selectedEmailId = null;

            // Clear reading pane
            document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';

            showToast('Moved to trash', 'success');
            // Refresh folder counts and email list
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Error moving ticket to trash: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to delete ticket', 'error');
    }
}

// Restore a ticket from the Trash folder.
async function restoreTicketFromTrash(ticketId) {
    try {
        const res = await fetch(API_BASE + 'restore_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Ticket restored', 'success');
            clearReadingPaneIfTicket(ticketId);
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Restore failed: ' + data.error, 'error');
        }
    } catch (e) { showToast('Restore failed', 'error'); }
}

// Clear the reading pane if it's showing the given ticket (it just left the trash).
function clearReadingPaneIfTicket(ticketId) {
    if (currentEmail && currentEmail.ticket_id == ticketId) {
        currentEmail = null;
        selectedEmailId = null;
        document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';
        stopPresence();   // nothing is open, so stop announcing (#934)
    }
}

// Permanently delete a ticket from the Trash folder (irreversible).
async function permanentlyDeleteFromTrash(ticketId, ticketNumber) {
    if (!(await showConfirm({
        title: 'Delete permanently',
        message: `Permanently delete ticket ${ticketNumber || ''} and all its emails, attachments and notes? This cannot be undone.`,
        okLabel: 'Delete permanently', okClass: 'danger'
    }))) return;
    try {
        const res = await fetch(API_BASE + 'permanently_delete_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Ticket permanently deleted', 'success');
            clearReadingPaneIfTicket(ticketId);
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Delete failed: ' + data.error, 'error');
        }
    } catch (e) { showToast('Delete failed', 'error'); }
}

// Who made a history entry. The endpoint says WHICH case it is (analyst /
// system / former) rather than leaving a blank name to be guessed at; this
// turns that into the words, which is where the translations live.
//
// It used to read `entry.analyst_name || 'Unknown'`. "Unknown" says *we do not
// know* when the truth is *we did not look* — and it hid the two cases worth
// telling apart: an entry written by a workflow (GH #120) and one written by
// somebody who has since left. Same three-way split, and the same two locale
// keys, that the notes list already uses.
function auditAuthor(entry) {
    if (entry.analyst_name) return entry.analyst_name;
    return entry.author_kind === 'system'
        ? t('tickets.note_author.system')
        : t('tickets.note_author.former');
}

// Show audit history modal
async function showAuditHistory() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }

    try {
        const response = await fetch(`${API_BASE}get_ticket_audit.php?ticket_id=${currentEmail.ticket_id}`);
        const data = await response.json();

        if (data.success) {
            const auditHtml = data.audit.length === 0
                ? '<p style="text-align: center; color: #888;">No audit history for this ticket.</p>'
                : `<table class="audit-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Analyst</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.audit.map(entry => `
                            <tr>
                                <td>${formatFullDateTime(entry.created_datetime)}</td>
                                <td>${escapeHtml(auditAuthor(entry))}</td>
                                <td>${escapeHtml(entry.field_name)}</td>
                                <td>${escapeHtml(entry.old_value || '-')}</td>
                                <td>${escapeHtml(entry.new_value || '-')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;

            // Create modal
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'auditModal';
            modal.innerHTML = `
                <div class="modal-content audit-modal">
                    <div class="modal-header">
                        <h3>Audit History - ${escapeHtml(currentEmail.ticket_number)}</h3>
                        <button class="modal-close" onclick="closeAuditModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${auditHtml}
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        } else {
            showToast('Error loading audit history: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to load audit history', 'error');
    }
}

// Close audit modal
function closeAuditModal() {
    const modal = document.getElementById('auditModal');
    if (modal) {
        modal.remove();
    }
}

// Refresh current view
function refreshCurrentView() {
    loadFolderCounts();
    if (currentFilter.type !== 'none') {
        loadEmails();
    }
}

// Utility: Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * How a message's sender is labelled, given that from_address may be NULL.
 *
 * A self-service requester who signs in through a directory may have no mailbox
 * at all (GitHub #47), so their messages carry a name and nothing else. Every
 * site that renders "Name <address>" has to cope, or it prints `Wendy <>`.
 *
 * Returns escaped HTML, ready to interpolate.
 *
 * @param {string} name
 * @param {string|null} address
 * @param {boolean} withAngles include " <address>" when there is one
 */
function senderLabel(name, address, withAngles) {
    const n = (name || '').trim();
    const a = (address || '').trim();

    // Neither recorded — say so rather than rendering a blank where a person
    // should be, which reads as a broken row.
    if (!n && !a) return escapeHtml(t('tickets.reading_pane.unknown_sender'));
    if (!a) return escapeHtml(n);
    if (!n) return escapeHtml(a);

    return withAngles
        ? `${escapeHtml(n)} &lt;${escapeHtml(a)}&gt;`
        : escapeHtml(n);
}

// parseUTCDate / tzOpts / ymdInZone used to be defined here — inbox.js predates
// assets/js/tz.js, which was written by lifting these very functions out of it.
// tickets/index.php was then the one page still not loading tz.js, so the copies
// had to stay. It loads it now, and the copies are gone: two identical
// implementations of a date helper is exactly how the 'en-GB' drift behind
// GH #105 spread in the first place.

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    const now = new Date();
    // ymdInZone is a MACHINE format (always ISO) — it is a bucket key compared
    // against another bucket key, never shown. It must not follow the analyst's
    // chosen date format or Today/Yesterday would stop matching.
    const todayStr = ymdInZone(now);
    const yesterdayStr = ymdInZone(new Date(now.getTime() - 86400000));
    const dateYmd = ymdInZone(date);

    if (dateYmd === todayStr) {
        return fmtTime(date);
    } else if (dateYmd === yesterdayStr) {
        return 'Yesterday ' + fmtTime(date);
    } else {
        return fmtDayMonth(date) + ' ' + fmtTime(date);
    }
}

// Utility: Format full date/time (always shows date and time)
function formatFullDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    return fmtWeekday(date, true) + ' ' + fmtDate(date) + ' ' + fmtTime(date);
}

// Format a NAIVE wall-clock datetime (a user-entered scheduling value stored
// WITHOUT a zone, e.g. a ticket's scheduled work-start) exactly as typed — no
// timezone conversion, so "2pm" reads 2pm for every analyst. Scheduling times
// are naive; only server-stamped UTC timestamps (received/created/…) convert.
function formatNaiveFullDateTime(dateStr) {
    if (!dateStr) return '';
    const d = parseNaiveDate(dateStr);
    if (!d || isNaN(d.getTime())) return dateStr;
    return fmtNaiveWeekday(d, true) + ' ' + fmtNaiveDate(d) + ' ' + fmtNaiveTime(d);
}

// Toggle ticket properties panel
function toggleTicketProperties(event) {
    event.stopPropagation();
    const container = document.getElementById('ticketPropertiesContainer');
    if (container) {
        container.classList.toggle('expanded');
    }
}

// Close ticket properties panel when clicking outside
document.addEventListener('click', function(event) {
    const container = document.getElementById('ticketPropertiesContainer');
    if (container && container.classList.contains('expanded')) {
        // Check if click is outside the properties container
        if (!container.contains(event.target)) {
            container.classList.remove('expanded');
        }
    }
});

// Update summary values when properties change
function updatePropertiesSummary() {
    const summaryDept = document.getElementById('summaryDept');
    const summaryStatus = document.getElementById('summaryStatus');
    const summaryOwner = document.getElementById('summaryOwner');

    // Same strings the initial render uses, via t() — these were hardcoded English
    // and overwrote the localised values as soon as any property changed (#79).
    if (summaryDept && currentEmail) {
        summaryDept.textContent = getDisplayName('department', currentEmail.department_id) || t('tickets.reading_pane.summary_none');
    }
    if (summaryStatus && currentEmail) {
        // No status is "none", not "Open" — claiming Open is what hid #79.
        summaryStatus.textContent = currentEmail.status || t('tickets.reading_pane.summary_none');
    }
    if (summaryOwner && currentEmail) {
        summaryOwner.textContent = getDisplayName('owner', currentEmail.owner_id) || t('tickets.reading_pane.summary_unassigned');
    }
}

// ===== Linked CMDB objects on a ticket =====
// Renders an "Affected CMDB" section in the reading pane below the email
// thread. Click + Link to add (autocomplete searches every CMDB object);
// X on a card removes the link.

let cmdbObjectsForTicket = [];
let cmdbAcTimer = null;
let cmdbAcHighlightedIdx = -1;

async function loadCmdbObjects(ticketId) {
    const host = document.getElementById('stripCmdbPills');
    if (!host) return;
    try {
        const res = await fetch('../api/tickets/get_ticket_cmdb_objects.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) return;
        cmdbObjectsForTicket = data.links || [];
        renderCmdbObjects(ticketId);
    } catch (e) { /* silent — the strip simply shows no CI pills */ }
}

function renderCmdbObjects(ticketId) {
    const host = document.getElementById('stripCmdbPills');
    if (!host) return;
    host.innerHTML = cmdbObjectsForTicket.map(link => {
        const where = link.parent_name
            ? `in ${link.parent_name}${link.parent_class_name ? ' (' + link.parent_class_name + ')' : ''}`
            : '';
        const title = [link.class_name, where].filter(Boolean).join(' · ');
        return `<a class="pm-ticket-badge" href="../cmdb/object.php?id=${link.object_id}" title="${escapeHtml(title)}">
            🗄️ ${escapeHtml(link.name)}
            <span class="pm-ticket-unlink" title="${escapeHtml(t('tickets.cmdb.unlink_title'))}" onclick="event.preventDefault();event.stopPropagation();removeCmdbObject(event, ${link.link_id}, ${ticketId});">✕</span>
        </a>`;
    }).join('');
    syncLinksStripEmpty();
}

function openLinkCmdbPicker(ticketId) {
    const ui = openStripPicker(t('tickets.cmdb.search_placeholder'));
    if (!ui) return;
    const { input, results, close } = ui;

    let current = [];
    cmdbAcHighlightedIdx = -1;

    const renderResults = () => {
        if (current.length === 0) {
            results.innerHTML = `<div class="cmdb-picker-empty">${escapeHtml(t('tickets.cmdb.no_matches'))}</div>`;
            results.classList.add('active');
            return;
        }
        results.innerHTML = current.map((r, i) => `
            <div class="cmdb-picker-result ${i === cmdbAcHighlightedIdx ? 'highlighted' : ''}" data-idx="${i}">
                <span>${escapeHtml(r.name)}</span>
                <span class="cmdb-picker-class">${escapeHtml(r.class_name)}</span>
            </div>`).join('');
        results.classList.add('active');
        results.querySelectorAll('.cmdb-picker-result').forEach(el => {
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                pick(current[parseInt(el.dataset.idx, 10)]);
            });
        });
    };

    const pick = async (r) => {
        try {
            const res = await fetch('../api/tickets/save_ticket_cmdb_object.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: ticketId, cmdb_object_id: r.id })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Link failed');
            if (data.already_linked) {
                showToast(t('tickets.cmdb.already_linked', { name: r.name }), 'error');
            } else {
                showToast(t('tickets.cmdb.linked_toast', { name: r.name }), 'success');
            }
            close();
            await loadCmdbObjects(ticketId);
        } catch (err) {
            showToast('Error: ' + err.message, 'error');
        }
    };

    input.oninput = () => {
        const q = input.value.trim();
        if (cmdbAcTimer) clearTimeout(cmdbAcTimer);
        if (q === '') { results.classList.remove('active'); return; }
        cmdbAcTimer = setTimeout(async () => {
            try {
                const url = '../api/cmdb/search_objects.php?q=' + encodeURIComponent(q);
                const res = await fetch(url);
                const data = await res.json();
                current = data.success ? (data.results || []) : [];
                cmdbAcHighlightedIdx = -1;
                renderResults();
            } catch (e) { /* silent */ }
        }, 200);
    };

    input.onkeydown = e => {
        if (!results.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); cmdbAcHighlightedIdx = Math.min(current.length - 1, cmdbAcHighlightedIdx + 1); renderResults(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); cmdbAcHighlightedIdx = Math.max(0, cmdbAcHighlightedIdx - 1); renderResults(); }
        else if (e.key === 'Enter' && cmdbAcHighlightedIdx >= 0) { e.preventDefault(); pick(current[cmdbAcHighlightedIdx]); }
        else if (e.key === 'Escape') { close(); }
    };
}

async function removeCmdbObject(ev, linkId, ticketId) {
    ev.preventDefault();
    ev.stopPropagation();
    if (!(await showConfirm({ title: 'Confirm', message: t('tickets.cmdb.unlink_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const res = await fetch('../api/tickets/delete_ticket_cmdb_object.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unlink failed');
        showToast(t('tickets.cmdb.unlinked_toast'), 'success');
        await loadCmdbObjects(ticketId);
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

// ─── Linked assets (discussion #57) ──────────────────────────────────────────
// Deliberately the same shape as the CMDB section above, with one difference in
// the picker: it opens showing the REQUESTER'S own equipment before a single
// key is pressed, because "my monitor is flickering" is nearly always their own
// monitor. Typing then searches the whole estate, because sometimes it is the
// TV in a meeting room that nobody owns.
let assetsForTicket = [];
let assetAcTimer = null;
let assetAcHighlightedIdx = -1;

async function loadTicketAssets(ticketId) {
    const host = document.getElementById('stripAssetPills');
    if (!host) return;
    try {
        const res = await fetch('../api/tickets/get_ticket_assets.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) return;
        assetsForTicket = data.links || [];
        renderTicketAssets(ticketId);
    } catch (e) { /* silent — the strip simply shows no equipment pills */ }
}

/** The one-line name for an asset: hostname if it has one, else the tag. */
function assetDisplayName(a) {
    return a.hostname || a.asset_tag || t('tickets.assets.unnamed');
}

function renderTicketAssets(ticketId) {
    const host = document.getElementById('stripAssetPills');
    if (!host) return;

    host.innerHTML = assetsForTicket.map(link => {
        // Everything except the name goes in the tooltip. The strip is one line;
        // the make, model, serial and location belong on the asset's own page,
        // which is one click away on the pill itself.
        const makeModel = [link.manufacturer, link.model].filter(Boolean).join(' ');
        const bits = [];
        if (link.type_name)     bits.push(link.type_name);
        if (makeModel)          bits.push(makeModel);
        if (link.service_tag)   bits.push(t('tickets.assets.serial', { serial: link.service_tag }));
        if (link.location_name) bits.push(link.location_name);
        return `<a class="pm-ticket-badge" href="../asset-management/index.php?asset_id=${link.asset_id}" title="${escapeHtml(bits.join(' · '))}">
            🖥️ ${escapeHtml(assetDisplayName(link))}
            ${rpBadge('asset', link.asset_id)}
            <span class="pm-ticket-unlink" title="${escapeHtml(t('tickets.assets.unlink_title'))}" onclick="event.preventDefault();event.stopPropagation();removeTicketAsset(event, ${link.link_id}, ${ticketId});">✕</span>
        </a>`;
    }).join('');
    syncLinksStripEmpty();
}

function openLinkAssetPicker(ticketId) {
    const ui = openStripPicker(t('tickets.assets.search_placeholder'));
    if (!ui) return;
    const { input, results, close } = ui;

    // `current` is the flat, keyboard-navigable list. `firstOtherIdx` only decides
    // where the headings are drawn, so arrow keys never land on a heading.
    let current = [];
    let firstOtherIdx = -1;
    assetAcHighlightedIdx = -1;

    const renderResults = () => {
        if (current.length === 0) {
            results.innerHTML = `<div class="asset-picker-empty">${escapeHtml(
                input.value.trim() === '' ? t('tickets.assets.type_to_search') : t('tickets.assets.no_matches')
            )}</div>`;
            results.classList.add('active');
            return;
        }
        const rows = current.map((r, i) => {
            const makeModel = [r.manufacturer, r.model].filter(Boolean).join(' ');
            const detail = [makeModel, r.service_tag, r.location_name].filter(Boolean).join(' &middot; ');
            const heading =
                i === 0 && firstOtherIdx !== 0
                    ? `<div class="asset-picker-group">${escapeHtml(t('tickets.assets.group_requester'))}</div>`
                    : (i === firstOtherIdx
                        ? `<div class="asset-picker-group">${escapeHtml(t('tickets.assets.group_all'))}</div>`
                        : '');
            return heading + `
                <div class="asset-picker-result ${i === assetAcHighlightedIdx ? 'highlighted' : ''}" data-idx="${i}">
                    <span>${escapeHtml(assetDisplayName(r))}</span>
                    <span class="asset-picker-detail">${detail}</span>
                </div>`;
        }).join('');
        results.innerHTML = rows;
        results.classList.add('active');
        results.querySelectorAll('.asset-picker-result').forEach(el => {
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                pick(current[parseInt(el.dataset.idx, 10)]);
            });
        });
    };

    const search = async (q) => {
        try {
            const url = '../api/tickets/search_linkable_assets.php?ticket_id=' + ticketId +
                        '&q=' + encodeURIComponent(q);
            const res  = await fetch(url);
            const data = await res.json();
            if (!data.success) { current = []; firstOtherIdx = -1; renderResults(); return; }
            const mine   = data.requester || [];
            const others = data.others || [];
            current       = mine.concat(others);
            firstOtherIdx = others.length ? mine.length : -1;
            assetAcHighlightedIdx = -1;
            renderResults();
        } catch (e) { /* silent */ }
    };

    const pick = async (r) => {
        try {
            const res = await fetch('../api/tickets/save_ticket_asset.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: ticketId, asset_id: r.asset_id })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Link failed');
            const name = assetDisplayName(r);
            if (data.already_linked) {
                showToast(t('tickets.assets.already_linked', { name }), 'error');
            } else {
                showToast(t('tickets.assets.linked_toast', { name }), 'success');
            }
            close();
            await loadTicketAssets(ticketId);
        } catch (err) {
            showToast('Error: ' + err.message, 'error');
        }
    };

    input.oninput = () => {
        if (assetAcTimer) clearTimeout(assetAcTimer);
        assetAcTimer = setTimeout(() => search(input.value.trim()), 200);
    };

    input.onkeydown = e => {
        if (!results.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); assetAcHighlightedIdx = Math.min(current.length - 1, assetAcHighlightedIdx + 1); renderResults(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); assetAcHighlightedIdx = Math.max(0, assetAcHighlightedIdx - 1); renderResults(); }
        else if (e.key === 'Enter' && assetAcHighlightedIdx >= 0) { e.preventDefault(); pick(current[assetAcHighlightedIdx]); }
        else if (e.key === 'Escape') { close(); }
    };

    // Show the requester's own equipment straight away, before anything is typed.
    search('');
}

async function removeTicketAsset(ev, linkId, ticketId) {
    ev.preventDefault();
    ev.stopPropagation();
    if (!(await showConfirm({ title: 'Confirm', message: t('tickets.assets.unlink_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const res = await fetch('../api/tickets/delete_ticket_asset.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unlink failed');
        showToast(t('tickets.assets.unlinked_toast'), 'success');
        await loadTicketAssets(ticketId);
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

// Load notes for a ticket
async function loadNotes(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_notes.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            currentNotes = data.notes;
            renderNotes();
        }
    } catch (error) {
        console.error('Error loading notes:', error);
    }
}

// Load attachments for a ticket
async function loadTicketAttachments(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_ticket_attachments.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            ticketAttachments = data.attachments;
            renderAttachmentInfoBar();
        }
    } catch (error) {
        console.error('Error loading attachments:', error);
    }
}

// Render the attachment info bar
function renderAttachmentInfoBar() {
    const infoBar = document.getElementById('attachmentInfoBar');
    if (!infoBar) return;

    if (ticketAttachments.length > 0) {
        const regularCount = ticketAttachments.filter(a => !a.is_inline).length;
        const inlineCount = ticketAttachments.filter(a => a.is_inline).length;

        const regularPhrase = t(regularCount === 1 ? 'tickets.reading_pane.attach_one' : 'tickets.reading_pane.attach_many', { count: regularCount });
        let message = '';
        if (regularCount > 0 && inlineCount > 0) {
            message = `${regularPhrase} ${t('tickets.reading_pane.attach_inline_suffix', { count: inlineCount })}`;
        } else if (regularCount > 0) {
            message = regularPhrase;
        } else {
            message = t(inlineCount === 1 ? 'tickets.reading_pane.attach_inline_one' : 'tickets.reading_pane.attach_inline_many', { count: inlineCount });
        }

        infoBar.style.display = 'block';
        infoBar.innerHTML = `
            <span>${escapeHtml(t('tickets.reading_pane.attach_bar', { message: message }))}</span>
        `;
    } else {
        infoBar.style.display = 'none';
    }
}

// A stored UTC timestamp in the analyst's zone and chosen format.
//
// This used to hardcode DD/MM/YYYY HH:MM (hence the name). That was one of the
// 67 places behind GH #105 — the whole point of the setting is that DD/MM/YYYY
// is a preference, not a fact, so it now renders like every other date.
function formatDateDMY(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    if (!date) return '';
    return fmtDateTime(date);
}

// Show attachment list modal
function showAttachmentList() {
    if (ticketAttachments.length === 0) return;

    // A "send to the issue" action, but only when this ticket actually has a
    // linked issue — otherwise the column is a promise the ticket cannot keep.
    // Inline images are excluded for the same reason they are excluded from
    // escalation: they are signatures and tracking pixels, not evidence.
    const trackerLink = (currentEmail && (currentEmail.tracker_links || [])[0]) || null;
    const canSend = !!trackerLink;

    const tableHtml = `
        <table class="attachment-modal-table">
            <thead>
                <tr>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_from'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_datetime'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_filename'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_size'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_type'))}</th>
                    ${canSend ? `<th></th>` : ''}
                </tr>
            </thead>
            <tbody>
                ${ticketAttachments.map(att => `
                    <tr class="attachment-row">
                        <td onclick="openAttachment(${att.id})" title="${escapeHtml(t('tickets.reading_pane.attach_click_download'))}">${escapeHtml(att.from_name || att.from_address || '')}</td>
                        <td onclick="openAttachment(${att.id})">${formatDateDMY(att.received_datetime)}</td>
                        <td onclick="openAttachment(${att.id})">
                            <span class="attachment-icon">${getFileIcon(att.filename)}</span>
                            ${escapeHtml(att.filename)}
                        </td>
                        <td onclick="openAttachment(${att.id})">${formatFileSize(att.file_size || 0)}</td>
                        <td onclick="openAttachment(${att.id})">${att.is_inline ? `<span class="inline-badge">${escapeHtml(t('tickets.reading_pane.attach_inline_badge'))}</span>` : ''}</td>
                        ${canSend ? `<td style="text-align:right;white-space:nowrap;">${
                            att.is_inline ? '' :
                            `<button class="att-send-btn" data-att="${att.id}" data-link="${trackerLink.id}"
                                     data-name="${escapeHtml(att.filename)}" data-issue="${escapeHtml(trackerLink.external_key || '')}"
                                     onclick="sendAttachmentToTracker(this)">${
                                escapeHtml(t('tickets.tracker.attach_send_btn').replace('{issue}', trackerLink.external_key || ''))
                            }</button>`
                        }</td>` : ''}
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;

    // Inline previews for media that browsers can render directly (images, audio,
    // video) — so the analyst doesn't have to download then open. Everything still
    // appears in the table below for download.
    const previewable = ticketAttachments.filter(a => /^(image|audio|video)\//i.test(a.content_type || ''));
    const previewsHtml = previewable.length ? `
        <div class="attachment-previews">
            ${previewable.map(att => {
                const url = `${API_BASE}get_attachment.php?id=${att.id}`;
                const ct = (att.content_type || '').toLowerCase();
                let media;
                if (ct.startsWith('image/')) {
                    media = `<img src="${url}" alt="${escapeHtml(att.filename)}" class="att-preview-media" loading="lazy" onclick="window.open('${url}','_blank')" title="${escapeHtml(t('tickets.reading_pane.attach_click_fullsize'))}">`;
                } else if (ct.startsWith('audio/')) {
                    media = `<audio controls preload="none" src="${url}" class="att-preview-audio"></audio>`;
                } else {
                    media = `<video controls preload="metadata" src="${url}" class="att-preview-media"></video>`;
                }
                return `<figure class="att-preview-card">${media}<figcaption>${escapeHtml(att.filename)}</figcaption></figure>`;
            }).join('')}
        </div>` : '';

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = 'attachmentListModal';
    modal.innerHTML = `
        <div class="modal-content attachment-list-modal">
            <button class="modal-close-top" onclick="closeAttachmentListModal()">&times;</button>
            <div class="modal-header">
                <h3>${escapeHtml(t('tickets.reading_pane.attach_modal_title', { ref: currentEmail.ticket_number }))}</h3>
            </div>
            <div class="modal-body">
                ${previewsHtml}
                ${tableHtml}
            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

// Close attachment list modal
function closeAttachmentListModal() {
    const modal = document.getElementById('attachmentListModal');
    if (modal) {
        modal.remove();
    }
}

/**
 * Send one attachment up to the issue this ticket is linked to.
 *
 * ⚠️ Confirms first. This is a one-way door into a system we do not control —
 * the file cannot be unsent and anyone with access to that project can read it.
 * The same reason the escalate modal previews the description before raising.
 */
async function sendAttachmentToTracker(btn) {
    const name  = btn.getAttribute('data-name');
    const issue = btn.getAttribute('data-issue');

    const ok = await showConfirm(
        t('tickets.tracker.attach_send_confirm').replace('{file}', name).replace('{issue}', issue),
        t('tickets.tracker.attach_send_title')
    );
    if (!ok) return;

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = t('tickets.tracker.attach_sending');

    try {
        const r = await fetch('../api/integrations/send_attachment.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                attachment_id: parseInt(btn.getAttribute('data-att'), 10),
                link_id:       parseInt(btn.getAttribute('data-link'), 10)
            })
        });
        const j = await r.json();
        if (j.success) {
            // Left visibly done rather than reset: it stops a second analyst
            // sending the same file again, and the note on the ticket says so too.
            btn.textContent = t('tickets.tracker.attach_sent');
            showToast(t('tickets.tracker.attach_sent_toast').replace('{file}', name).replace('{issue}', j.issue || issue), 'success');
            loadNotes(currentEmail.id);
        } else {
            btn.disabled = false;
            btn.textContent = original;
            showToast(j.error || t('tickets.tracker.attach_send_failed'), 'error');
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = original;
        showToast(t('tickets.tracker.attach_send_failed'), 'error');
    }
}

// Open/download an attachment
function openAttachment(attachmentId) {
    window.open(`${API_BASE}get_attachment.php?id=${attachmentId}`, '_blank');
}

// Render notes
function renderNotes() {
    const container = document.getElementById('notesContainer');

    if (!currentNotes || currentNotes.length === 0) {
        container.innerHTML = '';
        return;
    }

    let html = '<div class="notes-section"><div class="notes-header">Notes</div>';

    currentNotes.forEach(note => {
        // A note imported from an external issue tracker has no analyst author.
        // The API attributes it to the connection ("Jira") and sets `source`; the
        // note text itself names the issue and the person who wrote it there.
        const external = note.source ? ' note-item-external' : '';
        // ⚠️ Never show a note with no author at all, and never hide one. Deleting
        // an analyst reassigns nothing, so their notes outlive them — saying
        // "Former analyst" keeps the ticket's history readable and honest.
        let author = note.analyst_name;
        if (!author) {
            author = note.author_kind === 'system'
                ? t('tickets.note_author.system')
                : t('tickets.note_author.former');
        }
        // Files attached to this note (discussion #69). A file is a download
        // link; a DMS entry is a link out. Both go through the documents block's
        // own endpoints, so the permission check happens server-side on every
        // click rather than being implied by the row being on screen.
        let files = '';
        if (note.documents && note.documents.length) {
            files = '<div class="note-file-list">' + note.documents.map(d => {
                const href = d.kind === 'link'
                    ? safeExternalUrl(d.external_url)
                    : '../api/documents/download.php?id=' + encodeURIComponent(d.id);
                if (!href) return '';
                const name = d.original_name || d.title || '';
                const size = d.kind === 'link' ? '' : noteFileSize(d.size_bytes);
                return `<a class="note-file" href="${escapeHtml(href)}"
                           ${d.kind === 'link' ? 'target="_blank" rel="noopener noreferrer"' : ''}
                           title="${escapeHtml(name)}">
                            <span class="note-file-name">${escapeHtml(name)}</span>
                            <span class="note-file-size">${escapeHtml(size)}</span>
                        </a>`;
            }).join('') + '</div>';
        }

        // Who can see this note (discussion #103). Until now a ticket's history
        // gave no way to tell an internal remark from something the requester
        // can read, which makes a mixed thread impossible to audit after the
        // fact. BOTH states carry a label: if only shared notes were marked,
        // "no label" would have to mean internal, and any note kind added later
        // would silently inherit that meaning.
        //
        // A note imported from a tracker is neither — it is not ours to describe
        // as internal or shared — so it gets no visibility label at all.
        const isShared = note.is_internal === false;
        const visClass = note.source ? '' : (isShared ? ' note-item-shared' : ' note-item-internal');
        const visBadge = note.source ? '' : `
                    <span class="note-visibility ${isShared ? 'is-shared' : 'is-internal'}"
                          title="${escapeHtml(t(isShared ? 'tickets.note_visibility.shared_title'
                                                        : 'tickets.note_visibility.internal_title'))}">
                        ${escapeHtml(t(isShared ? 'tickets.note_visibility.shared'
                                                : 'tickets.note_visibility.internal'))}
                    </span>`;

        html += `
            <div class="note-item${external}${visClass}">
                <div class="note-header">
                    <span class="note-author">${escapeHtml(author)}${visBadge}</span>
                    <span>${formatDateTime(note.created_datetime)}</span>
                </div>
                <div class="note-text">${escapeHtml(note.note_text)}</div>
                ${files}
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

// ===== Files chosen for the note being written (discussion #69) =============
// Held in the browser, never uploaded, until the note exists. A note has no id
// until it is saved, and uploading first would leave a file on disk every time
// somebody attached one and then closed the box. Cleared whenever the modal
// opens, so a file abandoned last time cannot ride along on the next note.
let pendingNoteFiles = [];

// A DMS entry's URL is typed by an analyst, so it reaches here as data and must
// never become an href on trust — `javascript:` in that box would otherwise run
// on every colleague who opens the ticket. Same allowlist rule as safeHref() in
// documents.js, restated rather than shared because it is one regex and the
// alternative is a cross-module util contract for two lines.
function safeExternalUrl(url) {
    return /^https?:\/\//i.test(String(url || '')) ? String(url) : '';
}

// ⚠️ formatFileSize() already exists at the top of this file and is used for
// email attachments. Reused deliberately — a second one here would mean the same
// file reported a different size in two places on the same screen. It returns
// 'NaN undefined' for a null size, so callers guard rather than it being changed
// under the code that already depends on it.
function noteFileSize(bytes) {
    return (bytes === null || bytes === undefined) ? '' : formatFileSize(bytes);
}

// Paint the chosen-files list in the modal. Rebuilt wholesale rather than
// patched: the list is tiny and an index-based remove has to stay in step with
// the array it indexes into.
function renderPendingNoteFiles() {
    const list = document.getElementById('noteFileList');
    if (!list) return;
    list.innerHTML = pendingNoteFiles.map((f, i) => `
        <span class="note-file">
            <span class="note-file-name">${escapeHtml(f.name)}</span>
            <span class="note-file-size">${escapeHtml(noteFileSize(f.size))}</span>
            <button type="button" class="note-file-remove" data-idx="${i}"
                    title="${escapeHtml(t('tickets.note_modal.remove_file'))}"
                    aria-label="${escapeHtml(t('tickets.note_modal.remove_file'))}">&times;</button>
        </span>
    `).join('');
}

// Files on a note work whether or not the note is shared. They did NOT before
// discussion #103: the portal had no way to hand a document back, so the Attach
// button hid itself when Share was ticked and saveNote() asked before discarding
// anything already chosen. api/self-service/get_document.php removed that
// limitation, and syncNoteAttachVisibility() went with it - there is no longer a
// rule for it to enforce.

// Open note modal
function openNoteModal() {
    document.getElementById('noteText').value = '';
    pendingNoteFiles = [];
    renderPendingNoteFiles();
    const fileInput = document.getElementById('noteFileInput');
    if (fileInput) fileInput.value = '';

    // Always reset to INTERNAL. Leaving it ticked from a previous note is how
    // somebody shares an internal remark with a customer by accident.
    const shared = document.getElementById('noteShared');
    if (shared) shared.checked = false;

    // If the requester has no mailbox, a shared note is the only way to reach
    // them at all — so say so here rather than leaving them to work it out.
    const hint = document.getElementById('noteSharedHint');
    if (hint) {
        const noMailbox = currentEmail && !currentEmail.from_address && !currentEmail.requester_email;
        hint.textContent = noMailbox
            ? t('tickets.note_modal.share_hint_no_mailbox')
            : t('tickets.note_modal.share_hint');
        hint.style.color = noMailbox ? '#b45309' : '#666';
    }


    document.getElementById('noteModal').classList.add('active');
    // A note counts as writing too — a colleague should know before they type
    // the same thing (#934).
    setPresenceComposing(true);
}

// Wire the modal's file controls once, on the elements themselves rather than
// per open — openNoteModal() runs on every note, and re-binding there would add
// a listener each time.
document.addEventListener('DOMContentLoaded', function () {
    const fileInput = document.getElementById('noteFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            // Appended, not replaced: picking a second time should add to what
            // you have, which is what "Attach" reads as. Clearing the input
            // afterwards is what lets the same file be re-picked after removal —
            // without it the change event never fires for an unchanged value.
            pendingNoteFiles = pendingNoteFiles.concat(Array.prototype.slice.call(fileInput.files));
            fileInput.value = '';
            renderPendingNoteFiles();
        });
    }
    const list = document.getElementById('noteFileList');
    if (list) {
        list.addEventListener('click', function (e) {
            const btn = e.target.closest('.note-file-remove');
            if (!btn) return;
            pendingNoteFiles.splice(parseInt(btn.dataset.idx, 10), 1);
            renderPendingNoteFiles();
        });
    }
});

// Close note modal
function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('active');
    setPresenceComposing(false);
}

// Save note
async function saveNote() {
    const noteText = document.getElementById('noteText').value.trim();

    if (!noteText) {
        showToast('Please enter a note', 'error');
        return;
    }

    const isShared = document.getElementById('noteShared').checked;

    // A shared note used to have to DROP its files, and this asked before doing
    // so. The portal can serve them now (discussion #103), so there is nothing
    // to ask and nothing to discard — a shared note's files go to the requester
    // like any other.
    const filesToAttach = pendingNoteFiles;

    try {
        const response = await fetch(API_BASE + 'save_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                note_text: noteText,
                // Ticked = the requester reads this in the self-service portal.
                // Defaults to unticked, so a note is internal unless someone
                // deliberately says otherwise — the behaviour this replaced,
                // and the safe direction.
                is_internal: !isShared
            })
        });
        const data = await response.json();

        if (data.success) {
            // The note exists now, so its files finally have something to attach
            // to. Uploaded through the documents block's own helper rather than
            // a second copy of the endpoint contract here.
            if (filesToAttach.length && data.note_id && window.FreeITSMDocuments) {
                const res = await window.FreeITSMDocuments.upload(
                    'ticket_note', data.note_id, filesToAttach, '../api/documents/'
                );
                // ⚠️ SAY SO WHEN SOME FAILED. The note is already saved and cannot
                // be un-saved, so silence here would leave somebody believing a
                // file was filed when it was not — the one outcome worth more
                // than a toast. Names the files, because "1 of 3 failed" without
                // saying which is not actionable.
                if (res.failed.length) {
                    // ⚠️ Say WHY, not just which. uploadFiles() already collects the
                    // server's reason per file — "That file's contents do not match
                    // its txt extension" — and this used to map to f.name alone and
                    // throw the reason away. The result was a message naming a file
                    // and giving no clue what was wrong with it, for a refusal the
                    // person can usually act on once they know: rename it, convert
                    // it, or send it another way. The note cannot be un-saved and a
                    // note has no per-note file controls, so this toast is the ONLY
                    // chance to explain.
                    showToast(t('tickets.note_modal.files_failed', {
                        n:     res.failed.length,
                        total: filesToAttach.length,
                        // The server's reasons are whole sentences ending in a full
                        // stop, and the string adds one after {names} — so trim
                        // theirs rather than showing "…extension.. The note…".
                        names: res.failed
                            .map(f => f.name + ' — ' + String(f.error || '').replace(/\s*\.\s*$/, ''))
                            .join('; ')
                    }), 'error');
                }
            }
            pendingNoteFiles = [];
            closeNoteModal();
            loadNotes(currentEmail.ticket_id);
        } else {
            showToast('Error saving note: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to save note', 'error');
    }
}

// Open reply modal
function openReplyModal() {
    // Channel tickets (WhatsApp etc.) reply via the inline composer, not email.
    if (currentTicketChannel && currentTicketChannel !== 'email') {
        // Channel ticket: jump to the inline composer (free-text box if the window is
        // open, otherwise the template picker).
        const composer = document.getElementById('channelComposer');
        const focusEl = document.getElementById('channelComposerText') || document.getElementById('channelTemplateSelect');
        if (composer) composer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (focusEl) focusEl.focus();
        return;
    }
    composeMode = 'reply';
    // The sender may have NO address: a self-service requester who signs in
    // through a directory and was never given a mailbox (GitHub #47). Assigning
    // null straight into .value stringifies it to the literal text "null" —
    // which is truthy, passes every emptiness check on the way out, and gets
    // handed to Graph/Gmail/SMTP as a recipient. Fall back to the requester's
    // address if the payload has one, else leave it empty so the send is
    // refused rather than sent somewhere meaningless.
    const replyTo = currentEmail.from_address || currentEmail.requester_email || '';
    document.getElementById('emailTo').value = replyTo;
    document.getElementById('emailCc').value = '';
    if (!replyTo) {
        // Say why, rather than letting them write a reply that cannot be sent.
        // A note is how you reach someone with no mailbox — they read it in the
        // portal.
        showToast('This person has no email address on file — add a recipient, or share a note on the ticket instead.', 'warning');
    }
    // Add ticket reference to subject if not already present
    let subject = currentEmail.subject;
    const ticketRef = `[SDREF:${currentEmail.ticket_number}]`;
    if (!subject.includes(ticketRef)) {
        subject = `RE: ${subject} ${ticketRef}`;
    } else {
        subject = `RE: ${subject}`;
    }
    document.getElementById('emailSubject').value = subject;

    // Empty editor - server will assemble the full thread when sending
    if (emailEditor) {
        emailEditor.setContent('<p><br></p>');
    }

    applyDefaultSignature();
    setReplyCleanupVisibility('reply');
    document.getElementById('emailModal').classList.add('active');
    // Tell colleagues we're writing, and show their warning if they already are.
    setPresenceComposing(true);
    renderComposerCollisionWarning();
}

// Open forward modal
function openForwardModal() {
    composeMode = 'forward';
    document.getElementById('emailTo').value = '';
    document.getElementById('emailCc').value = '';
    // Add ticket reference to subject if not already present
    let subject = currentEmail.subject;
    const ticketRef = `[SDREF:${currentEmail.ticket_number}]`;
    if (!subject.includes(ticketRef)) {
        subject = `FW: ${subject} ${ticketRef}`;
    } else {
        subject = `FW: ${subject}`;
    }
    document.getElementById('emailSubject').value = subject;

    // Empty editor - server will assemble the full thread when sending
    if (emailEditor) {
        emailEditor.setContent('<p><br></p>');
    }

    applyDefaultSignature();
    setReplyCleanupVisibility('forward');
    document.getElementById('emailModal').classList.add('active');
    setPresenceComposing(true);
    renderComposerCollisionWarning();
}

// Close email modal
function closeEmailModal() {
    document.getElementById('emailModal').classList.remove('active');
    setPresenceComposing(false);   // we've stopped writing (#934)
    composeMode = 'new';
    // Clear the TinyMCE content
    if (emailEditor) {
        emailEditor.setContent('');
    }
    // Clear attachments
    emailAttachments = [];
    renderAttachments();
    hideReplyCleanupUndoBar();
    closeReplyTemplateMenu();
    closeSignatureMenu();
}

// ===========================================================================
// Splitting a ticket (#914)
// ===========================================================================
//
// Reached from a message in the thread, because a split starts FROM a message —
// putting it in the toolbar would mean asking "which one?" first.
//
// ⚠️ THE THREAD IS RENDERED NEWEST-FIRST. "Everything after" in the data sense
// means LATER IN TIME, which appears ABOVE the chosen message on screen. Every
// user-facing string here says "newer" rather than "after" for that reason —
// "after" would point the analyst's eye the wrong way down the page.

let splitTicketId  = null;
let splitAnchorId  = null;          // the message the dialog was opened from
let splitMessages  = [];            // every movable message on the ticket, oldest-first
let splitSelected  = new Set();     // ids the analyst has ticked to move

/**
 * Pick the singular or plural translation for a count.
 *
 * "1 message(s)" reads as a bug to anyone who sees it, and these counts are shown
 * at the exact moment an analyst is deciding whether to commit. Convention: the
 * base key is the plural and `<key>_one` is the singular.
 */
function splitPlural(n, baseKey) {
    const count = Number(n) || 0;
    if (count === 1) {
        const one = t(baseKey + '_one');
        // t() echoes the key back when it is missing; fall through rather than
        // printing "tickets.split.done_one" at somebody.
        if (one && one !== baseKey + '_one') return one;
    }
    return t(baseKey).replace('%d', String(count));
}

async function openSplitModal(ticketId, emailId) {
    splitTicketId = ticketId;
    splitAnchorId = Number(emailId) || null;
    splitMessages = [];
    splitSelected = new Set();

    document.getElementById('splitSubject').value = '';
    document.getElementById('splitWarning').style.display = 'none';
    document.getElementById('splitConfirmBtn').disabled = true;
    document.getElementById('splitSelCount').textContent = '';
    document.getElementById('splitPreviewList').innerHTML =
        '<div class="split-preview-empty">' + escapeHtml(t('tickets.split.loading')) + '</div>';
    document.getElementById('splitModal').classList.add('active');

    await loadSplitMessages();
}

function closeSplitModal() {
    document.getElementById('splitModal').classList.remove('active');
}

/**
 * Fetch every movable message on the ticket and paint the checklist. The whole set
 * comes from the server (markers already excluded) so the dialog offers exactly what
 * the split will accept — the same reason the anchor preview was never counted in JS.
 */
async function loadSplitMessages() {
    if (!splitTicketId) return;
    const list = document.getElementById('splitPreviewList');
    try {
        const res = await fetch(API_BASE + 'split_ticket_preview.php?ticket_id=' + splitTicketId + '&list_all=1');
        const data = await res.json();
        if (!data.success) {
            list.innerHTML = '<div class="split-preview-empty">' + escapeHtml(data.error || 'Could not load') + '</div>';
            return;
        }
        splitMessages = data.messages || [];

        // Pre-tick the message the analyst opened the dialog from — the common case is
        // "split this one (and maybe more) off". If it isn't movable, start with none.
        splitSelected = new Set();
        if (splitAnchorId && splitMessages.some(m => Number(m.id) === splitAnchorId)) {
            splitSelected.add(splitAnchorId);
        }
        renderSplitList();
        splitUpdateState();
    } catch (e) {
        list.innerHTML = '<div class="split-preview-empty">Could not load</div>';
    }
}

/** Paint the checklist newest-first (matching the reading pane) from splitSelected. */
function renderSplitList() {
    const list = document.getElementById('splitPreviewList');
    if (!splitMessages.length) {
        list.innerHTML = '<div class="split-preview-empty">' + escapeHtml(t('tickets.split.none')) + '</div>';
        return;
    }
    // splitMessages is oldest-first; the thread shows newest-first, so reverse to match.
    list.innerHTML = splitMessages.slice().reverse().map(m => {
        const id  = Number(m.id);
        const dir = String(m.direction).toLowerCase() === 'outbound' ? 'outbound' : 'inbound';
        const subj = (m.subject || '').trim();
        return `<label class="split-preview-row split-pick-row">
            <input type="checkbox" class="split-pick-cb" ${splitSelected.has(id) ? 'checked' : ''} onchange="splitToggle(${id}, this.checked)">
            <span class="split-preview-dir ${dir}">${escapeHtml(m.direction || '')}</span>
            <span class="split-preview-who">${escapeHtml(m.from_name || m.from_address || '')}</span>
            ${subj ? `<span class="split-preview-subj">${escapeHtml(subj)}</span>` : ''}
            <span class="split-preview-when">${escapeHtml(formatFullDateTime(m.received_datetime))}</span>
        </label>`;
    }).join('');
}

function splitToggle(id, on) {
    id = Number(id);
    if (on) splitSelected.add(id); else splitSelected.delete(id);
    splitUpdateState();
}

/**
 * Tick the opened-from message and everything newer than it — the old contiguous
 * default, now a one-click helper rather than the only option. "Newer" means later in
 * time, which is ABOVE the anchor in the newest-first list.
 */
function splitSelectNewer(ev) {
    if (ev) ev.preventDefault();
    const anchor = splitMessages.find(m => Number(m.id) === splitAnchorId) || splitMessages[0];
    if (!anchor) return;
    splitSelected = new Set();
    splitMessages.forEach(m => {
        const newer = m.received_datetime > anchor.received_datetime
            || (m.received_datetime === anchor.received_datetime && Number(m.id) >= Number(anchor.id));
        if (newer) splitSelected.add(Number(m.id));
    });
    renderSplitList();
    splitUpdateState();
}

function splitSelectAll(ev) {
    if (ev) ev.preventDefault();
    splitSelected = new Set(splitMessages.map(m => Number(m.id)));
    renderSplitList();
    splitUpdateState();
}

function splitClearSel(ev) {
    if (ev) ev.preventDefault();
    splitSelected = new Set();
    renderSplitList();
    splitUpdateState();
}

/** Recompute the count, subject default, warning and the Split button's state. */
function splitUpdateState() {
    const n = splitSelected.size;
    document.getElementById('splitSelCount').textContent = splitPlural(n, 'tickets.split.selected');

    // Default the subject from the OLDEST ticked message — usually the customer saying
    // what the new problem is. splitMessages is oldest-first, so the first ticked one.
    const subjBox = document.getElementById('splitSubject');
    if (!subjBox.value) {
        const oldest = splitMessages.find(m => splitSelected.has(Number(m.id)));
        if (oldest) subjBox.value = oldest.subject || '';
    }

    // Refusing to move EVERY message is a server rule too; mirroring it here just means
    // the analyst learns before they click rather than after.
    const warn = document.getElementById('splitWarning');
    const wouldEmpty = n > 0 && n >= splitMessages.length;
    if (n === 0) {
        warn.textContent = t('tickets.split.pick_none');
        warn.style.display = '';
        document.getElementById('splitConfirmBtn').disabled = true;
    } else if (wouldEmpty) {
        warn.textContent = t('tickets.split.would_empty');
        warn.style.display = '';
        document.getElementById('splitConfirmBtn').disabled = true;
    } else {
        warn.style.display = 'none';
        document.getElementById('splitConfirmBtn').disabled = false;
    }
}

/**
 * Undo a split — send the messages back and bin the ticket that was created.
 *
 * Confirms first: it is reversing something deliberate, and the new ticket may
 * already have been mentioned to somebody.
 */
async function undoSplit(splitId) {
    const ok = await showConfirm({
        title: t('tickets.split.undo_title'),
        message: t('tickets.split.undo_message'),
        okLabel: t('tickets.split.undo'),
        okClass: 'danger'
    });
    if (!ok) return;

    try {
        const res = await fetch(API_BASE + 'undo_split.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ split_id: splitId })
        });
        const data = await res.json();
        if (!data.success) {
            // These refusals explain themselves ("3 newer messages on X…"), so show
            // the server's wording rather than a generic failure.
            showToast(data.error || 'Could not undo the split', 'error');
            return;
        }
        showToast(splitPlural(data.returned, 'tickets.split.undo_done').replace('%s', data.new_ticket_number), 'success');
        await loadEmails();
        if (typeof loadFolderCounts === 'function') loadFolderCounts();
        selectEmailByTicketId(data.source_ticket_id);
    } catch (e) {
        showToast('Could not undo the split', 'error');
    }
}

async function confirmSplit() {
    if (!splitSelected.size) return;
    const btn = document.getElementById('splitConfirmBtn');
    btn.disabled = true;
    try {
        const res = await fetch(API_BASE + 'split_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: splitTicketId,
                email_ids: Array.from(splitSelected),
                subject: document.getElementById('splitSubject').value
            })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Split failed', 'error');
            btn.disabled = false;
            return;
        }
        showToast(splitPlural(data.moved, 'tickets.split.done').replace('%s', data.new_ticket_number), 'success');
        closeSplitModal();

        await loadEmails();
        if (typeof loadFolderCounts === 'function') loadFolderCounts();
        // Land on the NEW ticket: it is the thing that did not exist a moment ago and
        // the one that now needs triage.
        selectEmailByTicketId(data.new_ticket_id);
    } catch (e) {
        showToast('Split failed', 'error');
        btn.disabled = false;
    }
}

// ===========================================================================
// Changing a ticket's subject (#930)
// ===========================================================================
//
// Reached from the right-click menu, targeting whichever ticket was clicked
// (ctxTargetTicketId) — not necessarily the one open in the reading pane.

let subjectTicketId = null;

function openSubjectModal() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    subjectTicketId = ctxTargetTicketId;

    // Prefill with the current subject. Read from the loaded list (t.subject), or the
    // open ticket if it's the target — no round-trip needed just to show what's there.
    const rec = emails.find(e => e.ticket_id == ctxTargetTicketId)
        || (currentEmail && currentEmail.ticket_id == ctxTargetTicketId ? currentEmail : null);
    const input = document.getElementById('subjectInput');
    input.value = rec ? (rec.subject || '') : '';

    document.getElementById('subjectSaveBtn').disabled = false;
    document.getElementById('subjectModal').classList.add('active');
    setTimeout(() => { input.focus(); input.select(); }, 50);
}

function closeSubjectModal() {
    document.getElementById('subjectModal').classList.remove('active');
    subjectTicketId = null;
}

async function saveSubject() {
    if (!subjectTicketId) return;
    const input = document.getElementById('subjectInput');
    const subject = input.value.trim();
    if (!subject) { showToast(t('tickets.subject.empty'), 'error'); return; }

    const btn = document.getElementById('subjectSaveBtn');
    btn.disabled = true;
    try {
        const res = await fetch(API_BASE + 'save_ticket_subject.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: subjectTicketId, subject: subject })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || t('tickets.subject.failed'), 'error');
            btn.disabled = false;
            return;
        }
        const openId = (currentEmail && currentEmail.ticket_id == subjectTicketId) ? currentEmail.id : null;
        showToast(t('tickets.subject.saved'), 'success');
        closeSubjectModal();
        await loadEmails();
        // Repaint the reading pane if the renamed ticket is the one on screen.
        if (openId) selectEmail(openId);
    } catch (e) {
        showToast(t('tickets.subject.failed'), 'error');
        btn.disabled = false;
    }
}

// ===========================================================================
// Merging tickets (#912)
// ===========================================================================
//
// Reached from the multi-select right-click menu, which is the natural home for
// it: you have already gathered the tickets that are the same thing, so merging
// them is the next thought. Two or more selected, right-click, Merge.
//
// The dialog does NOT decide policy — the install does (Tickets → Settings →
// Merge behaviour). It reads that policy so it can say plainly what is about to
// happen, because "merge" means two quite different things depending on the
// reference mode and an analyst should not have to remember which is configured.

let mergeSettingsCache = null;
let mergeCandidateList = [];
let mergeResultTicketId = null;

async function loadMergeSettings() {
    if (mergeSettingsCache) return mergeSettingsCache;
    try {
        const res  = await fetch(API_BASE + 'get_merge_settings.php');
        const data = await res.json();
        mergeSettingsCache = data.success ? data.settings
                                          : { reference_mode: 'survivor', originals_mode: 'thread', ai_summary: '1' };
    } catch (e) {
        mergeSettingsCache = { reference_mode: 'survivor', originals_mode: 'thread', ai_summary: '1' };
    }
    return mergeSettingsCache;
}

async function openMergeModal() {
    closeTicketContextMenu();

    const ids = visibleEmailIds().filter(id => selectedEmailIds.has(id));
    if (ids.length < 2) {
        showToast(t('tickets.merge.need_two'), 'error');
        return;
    }

    const s = await loadMergeSettings();

    mergeCandidateList = ids.map(id => {
        const row = document.querySelector(`#emailList .email-item[data-email-id="${id}"]`);
        const rec = emails.find(e => e.id == id);
        return {
            emailId:  id,
            ticketId: row ? Number(row.dataset.ticketId) : null,
            ref:      row ? (row.dataset.ticketNumber || '') : '',
            subject:  rec ? (rec.subject || '') : ''
        };
    }).filter(c => c.ticketId);

    // In 'new' mode nothing survives, so asking "which one lives?" would be a lie.
    // The choice still matters — the picked ticket donates its department, priority
    // and so on to the new one — so the question is reworded rather than removed.
    const isNew = (s.reference_mode === 'new');
    document.getElementById('mergePickLabel').textContent = isNew
        ? t('tickets.merge.pick_model')
        : t('tickets.merge.pick_survivor');
    document.getElementById('mergeIntro').textContent =
        t('tickets.merge.intro').replace('%d', String(mergeCandidateList.length));

    document.getElementById('mergeCandidates').innerHTML = mergeCandidateList.map((c, i) => `
        <label class="merge-candidate">
            <input type="radio" name="mergeTarget" value="${c.ticketId}" ${i === 0 ? 'checked' : ''}>
            <span class="merge-candidate-ref">${escapeHtml(c.ref)}</span>
            <span class="merge-candidate-subj">${escapeHtml(c.subject)}</span>
        </label>`).join('');

    document.getElementById('mergeEffect').innerHTML = mergeEffectText(s, mergeCandidateList.length);

    // Reset to stage one — the modal is reused across merges.
    document.getElementById('mergeStageChoose').style.display  = '';
    document.getElementById('mergeStageSummary').style.display = 'none';
    document.getElementById('mergeFooterChoose').style.display  = '';
    document.getElementById('mergeFooterSummary').style.display = 'none';
    document.getElementById('mergeSummaryText').value = '';
    document.getElementById('mergeConfirmBtn').disabled = false;
    mergeResultTicketId = null;

    document.getElementById('mergeModal').classList.add('active');
}

/** Spell out what the configured policy will actually do, in plain words. */
function mergeEffectText(s, count) {
    const parts = [];
    parts.push(s.reference_mode === 'new'
        ? t('tickets.merge.effect_ref_new')
        : t('tickets.merge.effect_ref_survivor'));
    if (s.originals_mode === 'thread')           parts.push(t('tickets.merge.effect_orig_thread'));
    else if (s.originals_mode === 'thread_html') parts.push(t('tickets.merge.effect_orig_thread_html'));
    else                                         parts.push(t('tickets.merge.effect_orig_html'));
    parts.push(t('tickets.merge.effect_kept'));
    return parts.map(p => '• ' + escapeHtml(p)).join('<br>');
}

function closeMergeModal() {
    document.getElementById('mergeModal').classList.remove('active');
}

async function confirmMerge() {
    const chosen = document.querySelector('input[name="mergeTarget"]:checked');
    if (!chosen) return;
    const targetId  = Number(chosen.value);
    const sourceIds = mergeCandidateList.map(c => c.ticketId).filter(id => id !== targetId);

    const btn = document.getElementById('mergeConfirmBtn');
    btn.disabled = true;

    try {
        const res = await fetch(API_BASE + 'merge_tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_ids: sourceIds, target_id: targetId })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Merge failed', 'error');
            btn.disabled = false;
            return;
        }

        mergeResultTicketId = data.target_id;
        showToast(t('tickets.merge.done').replace('%d', String(data.merged.length)).replace('%s', data.target_number), 'success');

        clearSelectionToNone();
        await loadEmails();
        if (typeof loadFolderCounts === 'function') loadFolderCounts();

        // Stage two: the AI briefing, if the install wants one.
        const s = mergeSettingsCache || {};
        if (String(s.ai_summary) === '1') {
            showMergeSummaryStage(data);
            streamMergeSummary(data.target_id);
        } else {
            closeMergeModal();
            selectEmailByTicketId(data.target_id);
        }
    } catch (e) {
        showToast('Merge failed', 'error');
        btn.disabled = false;
    }
}

function showMergeSummaryStage(data) {
    document.getElementById('mergeStageChoose').style.display  = 'none';
    document.getElementById('mergeStageSummary').style.display = '';
    document.getElementById('mergeFooterChoose').style.display  = 'none';
    document.getElementById('mergeFooterSummary').style.display = '';
    document.getElementById('mergeModalTitle').textContent = t('tickets.merge.summary_title');
    document.getElementById('mergeResultLine').textContent =
        t('tickets.merge.done').replace('%d', String(data.merged.length)).replace('%s', data.target_number);
    document.getElementById('mergeSaveSummaryBtn').disabled = true;
}

/**
 * Stream the briefing into the textarea as it is written.
 *
 * Streaming rather than a spinner because this call takes many seconds on a long
 * merged thread, and watching it appear is the difference between "it's working"
 * and "it's hung" — the same reason reply cleanup streams.
 */
/**
 * Progress for the AI briefing.
 *
 * 🔑 ONLY ANTHROPIC STREAMS TOKEN BY TOKEN. OpenAI and OpenRouter go through the
 * shared one-shot client, which returns the whole answer at the end and is emitted
 * as a single chunk — so on those providers the textarea sits empty for the entire
 * call. On a long merged thread that is ~30 seconds of a blank box, which reads as
 * "it has crashed" and gets X-ed out (Ed hit exactly this).
 *
 * So the liveness signal must NOT depend on tokens arriving. This runs a spinner and
 * an elapsed-seconds counter from the moment the request starts, and says out loud
 * that it can take up to a minute. Tokens, when a provider does stream them, are a
 * bonus on top rather than the only evidence anything is happening.
 */
let mergeSummaryTimer = null;

function startMergeSummaryProgress() {
    const el = document.getElementById('mergeSummaryProgress');
    if (!el) return;
    const started = Date.now();
    el.style.display = 'flex';
    const paint = () => {
        const secs = Math.floor((Date.now() - started) / 1000);
        el.innerHTML = '<span class="spinner-inline"></span><span>'
            + escapeHtml(t('tickets.merge.ai_progress').replace('%d', String(secs))) + '</span>';
    };
    paint();
    mergeSummaryTimer = setInterval(paint, 1000);
}

function stopMergeSummaryProgress() {
    if (mergeSummaryTimer) { clearInterval(mergeSummaryTimer); mergeSummaryTimer = null; }
    const el = document.getElementById('mergeSummaryProgress');
    if (el) { el.style.display = 'none'; el.innerHTML = ''; }
}

function streamMergeSummary(ticketId) {
    const box  = document.getElementById('mergeSummaryText');
    const save = document.getElementById('mergeSaveSummaryBtn');
    box.value = '';
    box.placeholder = t('tickets.merge.ai_thinking');
    startMergeSummaryProgress();

    fetch(API_BASE + 'ai_merge_summary.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ticket_id: ticketId })
    }).then(response => {
        if (!response.body) throw new Error('No stream');
        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        function pump() {
            return reader.read().then(({ done, value }) => {
                if (done) { stopMergeSummaryProgress(); save.disabled = (box.value.trim() === ''); return; }
                buffer += decoder.decode(value, { stream: true });

                // SSE frames are separated by a blank line.
                const frames = buffer.split('\n\n');
                buffer = frames.pop();
                frames.forEach(frame => {
                    const evMatch = frame.match(/^event: (.+)$/m);
                    const dtMatch = frame.match(/^data: (.+)$/m);
                    if (!evMatch || !dtMatch) return;
                    let payload;
                    try { payload = JSON.parse(dtMatch[1]); } catch (e) { return; }

                    if (evMatch[1] === 'text') {
                        box.value += (payload.delta || '');
                        box.scrollTop = box.scrollHeight;
                    } else if (evMatch[1] === 'unconfigured') {
                        // No AI provider: not a failure. Say so and let the analyst
                        // write their own note, or just close.
                        stopMergeSummaryProgress();
                        box.placeholder = t('tickets.merge.ai_unconfigured');
                    } else if (evMatch[1] === 'error') {
                        stopMergeSummaryProgress();
                        showToast(payload.message || 'AI summary failed', 'error');
                    }
                });
                return pump();
            });
        }
        return pump();
    }).catch(() => {
        stopMergeSummaryProgress();
        showToast(t('tickets.merge.ai_failed'), 'error');
        save.disabled = (box.value.trim() === '');
    });
}

async function saveMergeSummary() {
    const text = document.getElementById('mergeSummaryText').value.trim();
    if (!text || !mergeResultTicketId) { closeMergeModal(); return; }
    try {
        const res = await fetch(API_BASE + 'save_merge_summary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: mergeResultTicketId, summary: text })
        });
        const data = await res.json();
        showToast(data.success ? t('tickets.merge.summary_saved') : (data.error || 'Could not save'), data.success ? 'success' : 'error');
    } catch (e) {
        showToast('Could not save the summary', 'error');
    }
    closeMergeModal();
    selectEmailByTicketId(mergeResultTicketId);
}

function discardMergeSummary() {
    // The merge itself already happened and stands; only the briefing is discarded.
    closeMergeModal();
    if (mergeResultTicketId) selectEmailByTicketId(mergeResultTicketId);
}

/** Open whichever list row belongs to a ticket id (after a merge, the survivor). */
function selectEmailByTicketId(ticketId) {
    const row = document.querySelector(`#emailList .email-item[data-ticket-id="${ticketId}"]`);
    if (row) handleEmailRowClick({ ctrlKey: false, shiftKey: false, metaKey: false }, Number(row.dataset.emailId));
}

// ===== Reply templates (canned responses) =====
//
// Two lists in one menu: the team's SHARED templates (curated in Tickets →
// Settings → Reply templates, behind a capability) and the analyst's OWN, which
// are saved from right here and need no permission at all. The server decides
// which is which; this file only renders what it is handed.
//
// Merge codes are resolved SERVER-side (render_reply_template.php), not here.
// The values substituted in — a requester's name, ultimately from the From header
// of a stranger's email — have to be escaped before they are dropped into an HTML
// editor, and that rule lives once, in includes/reply_templates.php.

let replyTemplateCache = null;
let saveReplyTemplateMode = { mode: 'new', id: null };

async function loadReplyTemplatesForPicker(force = false) {
    if (replyTemplateCache && !force) return replyTemplateCache;
    try {
        const res = await fetch(API_BASE + 'get_reply_templates.php');
        const data = await res.json();
        replyTemplateCache = data.success ? data.templates : [];
    } catch (e) {
        replyTemplateCache = [];
    }
    return replyTemplateCache;
}

async function toggleReplyTemplateMenu(event) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('replyTemplateMenu');
    if (!menu) return;

    if (menu.style.display === 'block') {
        menu.style.display = 'none';
        return;
    }

    // Always re-fetch on open: a colleague may have added a shared template since
    // this page was loaded, and the inbox stays open for a whole shift.
    await loadReplyTemplatesForPicker(true);
    renderReplyTemplateMenu();
    menu.style.display = 'block';
}

function closeReplyTemplateMenu() {
    const menu = document.getElementById('replyTemplateMenu');
    if (menu) menu.style.display = 'none';
}

// Any click outside the menu dismisses it — the button itself stops propagation.
document.addEventListener('click', function(e) {
    const menu = document.getElementById('replyTemplateMenu');
    if (menu && menu.style.display === 'block' && !menu.contains(e.target)) {
        closeReplyTemplateMenu();
    }
});

function renderReplyTemplateMenu() {
    const menu = document.getElementById('replyTemplateMenu');
    if (!menu) return;

    const shared = (replyTemplateCache || []).filter(t => t.scope === 'shared');
    const mine   = (replyTemplateCache || []).filter(t => t.scope === 'mine');

    const editIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    const binIcon  = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

    let html = '';

    html += '<div class="reply-tpl-menu-heading">' + escapeHtml(t('tickets.reply_modal.templates_team')) + '</div>';
    if (shared.length === 0) {
        html += '<div class="reply-tpl-menu-empty">' + escapeHtml(t('tickets.reply_modal.templates_none_team')) + '</div>';
    } else {
        html += shared.map(tpl => `
            <div class="reply-tpl-menu-row">
                <button type="button" class="reply-tpl-insert" onclick="insertReplyTemplate(${tpl.id})" title="${escapeHtml(tpl.name)}">${escapeHtml(tpl.name)}</button>
            </div>`).join('');
    }

    html += '<div class="reply-tpl-menu-sep"></div>';
    html += '<div class="reply-tpl-menu-heading">' + escapeHtml(t('tickets.reply_modal.templates_mine')) + '</div>';
    if (mine.length === 0) {
        html += '<div class="reply-tpl-menu-empty">' + escapeHtml(t('tickets.reply_modal.templates_none_mine')) + '</div>';
    } else {
        html += mine.map(tpl => `
            <div class="reply-tpl-menu-row">
                <button type="button" class="reply-tpl-insert" onclick="insertReplyTemplate(${tpl.id})" title="${escapeHtml(tpl.name)}">${escapeHtml(tpl.name)}</button>
                <button type="button" class="reply-tpl-mini" onclick="event.stopPropagation(); openSaveReplyTemplateModal('update', ${tpl.id})" title="${escapeHtml(t('tickets.reply_modal.template_update'))}">${editIcon}</button>
                <button type="button" class="reply-tpl-mini danger" onclick="event.stopPropagation(); deleteMyReplyTemplate(${tpl.id})" title="${escapeHtml(t('common.delete'))}">${binIcon}</button>
            </div>`).join('');
    }

    html += '<div class="reply-tpl-menu-sep"></div>';
    html += `<button type="button" class="reply-tpl-menu-action" onclick="event.stopPropagation(); openSaveReplyTemplateModal('new', null)">
                <span style="font-size:15px;line-height:1;">+</span> ${escapeHtml(t('tickets.reply_modal.save_draft_as_template'))}
             </button>`;

    menu.innerHTML = html;
}

async function insertReplyTemplate(templateId) {
    closeReplyTemplateMenu();
    if (!emailEditor) return;

    if (!currentEmail || !currentEmail.ticket_id) {
        showToast(t('tickets.reply_modal.template_no_ticket'), 'error');
        return;
    }

    try {
        const res = await fetch(API_BASE + 'render_reply_template.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: templateId, ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Could not load template', 'error');
            return;
        }
        // Inserted at the cursor, NOT replacing the draft: a canned response is
        // usually the middle of a reply, and silently discarding something the
        // analyst had already typed would be unforgivable.
        emailEditor.insertContent(data.body);
        emailEditor.focus();
    } catch (e) {
        showToast('Could not load template', 'error');
    }
}

function openSaveReplyTemplateModal(mode, id) {
    closeReplyTemplateMenu();

    const draft = emailEditor ? emailEditor.getContent() : '';
    if (!draft.replace(/<[^>]*>/g, '').trim()) {
        showToast(t('tickets.reply_modal.template_empty_draft'), 'error');
        return;
    }

    saveReplyTemplateMode = { mode: mode, id: id };
    const existing = (replyTemplateCache || []).find(x => x.id == id);

    document.getElementById('saveReplyTemplateName').value = existing ? existing.name : '';
    const modal = document.getElementById('saveReplyTemplateModal');
    modal.querySelector('.modal-header').textContent = (mode === 'update')
        ? t('tickets.reply_modal.save_template_update_title')
        : t('tickets.reply_modal.save_template_title');
    modal.classList.add('active');
    document.getElementById('saveReplyTemplateName').focus();
}

function closeSaveReplyTemplateModal() {
    document.getElementById('saveReplyTemplateModal').classList.remove('active');
}

async function savePersonalReplyTemplate() {
    const name = document.getElementById('saveReplyTemplateName').value.trim();
    if (name === '') {
        showToast(t('tickets.reply_modal.template_name_required'), 'error');
        return;
    }
    const body = emailEditor ? emailEditor.getContent() : '';

    try {
        const res = await fetch(API_BASE + 'save_reply_template.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            // scope 'mine' is what makes this need no permission. The server does not
            // take the client's word for it — it re-checks ownership before writing.
            body: JSON.stringify({
                id: saveReplyTemplateMode.mode === 'update' ? saveReplyTemplateMode.id : null,
                name: name,
                body: body,
                scope: 'mine'
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast(t('tickets.reply_modal.template_saved'), 'success');
            closeSaveReplyTemplateModal();
            await loadReplyTemplatesForPicker(true);
        } else {
            showToast(data.error || 'Could not save template', 'error');
        }
    } catch (e) {
        showToast('Could not save template', 'error');
    }
}

async function deleteMyReplyTemplate(id) {
    const tpl = (replyTemplateCache || []).find(x => x.id == id);
    const ok = await showConfirm({
        title: t('tickets.reply_modal.template_delete_title'),
        message: t('tickets.reply_modal.template_delete_message').replace('%s', tpl ? tpl.name : ''),
        okLabel: t('common.delete'),
        okClass: 'danger'
    });
    if (!ok) { return; }

    try {
        const res = await fetch(API_BASE + 'delete_reply_template.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await res.json();
        if (data.success) {
            showToast(t('tickets.reply_modal.template_deleted'), 'success');
            await loadReplyTemplatesForPicker(true);
            renderReplyTemplateMenu();
        } else {
            showToast(data.error || 'Could not delete template', 'error');
        }
    } catch (e) {
        showToast('Could not delete template', 'error');
    }
}

// ===== Reply Cleanup AI =====

let replyCleanupOriginalDraft = null;
let replyCleanupUndoTimer = null;
let replyCleanupCountdownTimer = null;

function setReplyCleanupVisibility(mode) {
    const btn = document.getElementById('replyCleanupBtn');
    if (btn) btn.style.display = (mode === 'reply') ? '' : 'none';
    hideReplyCleanupUndoBar();
}

// Convert plain-text-with-blank-lines (Claude's output) to TinyMCE-friendly HTML
function replyCleanupTextToHtml(text) {
    if (!text) return '<p><br></p>';
    // Normalise newlines, split on 2+ newlines for paragraphs, single newlines become <br>
    const normalised = text.replace(/\r\n/g, '\n');
    const paragraphs = normalised.split(/\n{2,}/);
    return paragraphs.map(p => {
        const safe = escapeHtml(p).replace(/\n/g, '<br>');
        return `<p>${safe}</p>`;
    }).join('');
}

async function cleanupReplyDraft() {
    if (!emailEditor) return;
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket loaded', 'error');
        return;
    }

    const editorContent = emailEditor.getContent({ format: 'text' }).trim();
    if (editorContent === '') {
        showToast('Type something first, then click Cleanup', 'error');
        return;
    }

    // Stash original HTML so the undo link can restore it verbatim
    replyCleanupOriginalDraft = emailEditor.getContent();

    const cleanupBtn = document.getElementById('replyCleanupBtn');
    const sendBtn = document.getElementById('replySendBtn');
    cleanupBtn.disabled = true;
    sendBtn.disabled = true;
    cleanupBtn.classList.add('is-loading');
    cleanupBtn.innerHTML = '<span class="spinner-inline"></span> Cleaning up…';
    hideReplyCleanupUndoBar();

    // Clear the editor — we'll stream into it.
    emailEditor.setContent('<p><em style="color:#999;">Cleaning up…</em></p>');

    let buffer = '';
    let firstChunk = true;
    let streamFailed = false;

    try {
        const res = await fetch(API_BASE + 'ai_cleanup_reply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                draft_text: editorContent,
            }),
        });

        if (!res.ok || !res.body) {
            throw new Error('HTTP ' + res.status);
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let sseBuffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            sseBuffer += decoder.decode(value, { stream: true });

            // Split on SSE event boundaries (\n\n)
            let idx;
            while ((idx = sseBuffer.indexOf('\n\n')) !== -1) {
                const block = sseBuffer.slice(0, idx);
                sseBuffer = sseBuffer.slice(idx + 2);

                let eventName = '';
                let dataLine = '';
                for (const line of block.split('\n')) {
                    if (line.startsWith('event: ')) eventName = line.slice(7).trim();
                    else if (line.startsWith('data: ')) dataLine += line.slice(6);
                }
                if (!dataLine) continue;
                let payload;
                try { payload = JSON.parse(dataLine); } catch { continue; }

                if (eventName === 'text') {
                    if (firstChunk) {
                        emailEditor.setContent('');
                        firstChunk = false;
                    }
                    buffer += payload.delta || '';
                    emailEditor.setContent(replyCleanupTextToHtml(buffer));
                } else if (eventName === 'error') {
                    streamFailed = true;
                    showToast(payload.message || 'Cleanup failed', 'error');
                    break;
                }
                // 'usage' / 'done' events are ignored for this UI
            }

            if (streamFailed) break;
        }
    } catch (err) {
        streamFailed = true;
        console.error('Cleanup error:', err);
        showToast('Cleanup failed: ' + err.message, 'error');
    }

    cleanupBtn.disabled = false;
    sendBtn.disabled = false;
    cleanupBtn.classList.remove('is-loading');
    cleanupBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg> Cleanup';

    if (streamFailed) {
        // Restore the user's original draft so they don't lose their typing
        if (replyCleanupOriginalDraft !== null) {
            emailEditor.setContent(replyCleanupOriginalDraft);
        }
        return;
    }

    showReplyCleanupUndoBar();
}

function showReplyCleanupUndoBar() {
    const bar = document.getElementById('replyCleanupUndoBar');
    const timer = document.getElementById('replyCleanupUndoTimer');
    if (!bar) return;
    bar.style.display = 'block';

    let secondsLeft = 30;
    timer.textContent = `(${secondsLeft}s)`;

    if (replyCleanupCountdownTimer) clearInterval(replyCleanupCountdownTimer);
    replyCleanupCountdownTimer = setInterval(() => {
        secondsLeft--;
        if (secondsLeft <= 0) {
            hideReplyCleanupUndoBar();
        } else {
            timer.textContent = `(${secondsLeft}s)`;
        }
    }, 1000);

    if (replyCleanupUndoTimer) clearTimeout(replyCleanupUndoTimer);
    replyCleanupUndoTimer = setTimeout(hideReplyCleanupUndoBar, 30000);
}

function hideReplyCleanupUndoBar() {
    const bar = document.getElementById('replyCleanupUndoBar');
    if (bar) bar.style.display = 'none';
    if (replyCleanupUndoTimer) {
        clearTimeout(replyCleanupUndoTimer);
        replyCleanupUndoTimer = null;
    }
    if (replyCleanupCountdownTimer) {
        clearInterval(replyCleanupCountdownTimer);
        replyCleanupCountdownTimer = null;
    }
}

// Wire the Undo link once on first inbox.js load
document.addEventListener('DOMContentLoaded', function() {
    const undoLink = document.getElementById('replyCleanupUndoLink');
    if (undoLink) {
        undoLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (replyCleanupOriginalDraft !== null && emailEditor) {
                emailEditor.setContent(replyCleanupOriginalDraft);
                showToast('Restored your original draft', 'success');
            }
            hideReplyCleanupUndoBar();
        });
    }
});

// Send email via Microsoft Graph API
async function sendEmail() {
    // Get values from form
    const to = document.getElementById('emailTo').value.trim();
    const cc = document.getElementById('emailCc').value.trim();
    const subject = document.getElementById('emailSubject').value;
    const body = emailEditor ? emailEditor.getContent() : '';

    // Basic validation
    if (!to) {
        showToast('Please enter a recipient email address', 'error');
        return;
    }
    if (!subject) {
        showToast('Please enter a subject', 'error');
        return;
    }

    // Get send button and show loading state
    const sendBtn = document.querySelector('#emailModal .btn-primary');
    const originalText = sendBtn.textContent;
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';

    try {
        // Convert attachments to base64
        const attachmentData = await prepareAttachments();

        // Send the email
        const response = await fetch(API_BASE + 'send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to: to,
                cc: cc,
                subject: subject,
                body: body,
                ticket_id: currentEmail ? currentEmail.ticket_id : null,
                type: composeMode,
                attachments: attachmentData
            })
        });

        // Get raw response text first to handle non-JSON errors
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Raw response:', responseText);
            showToast('Server error: ' + responseText.substring(0, 200), 'error');
            return;
        }

        if (data.success) {
            showToast('Email sent successfully!', 'success');
            closeEmailModal();
            // Refresh the current view to show the sent email
            if (currentEmail) {
                selectEmail(selectedEmailId);
            }
        } else {
            showToast('Failed to send email: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showToast('Error sending email: ' + error.message, 'error');
    } finally {
        // Restore button state
        sendBtn.disabled = false;
        sendBtn.textContent = originalText;
    }
}

// Prepare attachments by converting to base64
async function prepareAttachments() {
    const attachments = [];

    for (const file of emailAttachments) {
        const base64 = await fileToBase64(file);
        attachments.push({
            name: file.name,
            type: file.type || 'application/octet-stream',
            content: base64
        });
    }

    return attachments;
}

// Convert file to base64
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            // Remove the data URL prefix (e.g., "data:application/pdf;base64,")
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// Logout
function logout() {
    showConfirm({
        title: 'Logout',
        message: 'Are you sure you want to logout?',
        okLabel: 'Logout',
        okClass: 'primary',
        onConfirm: () => { window.location.href = 'analyst_logout.php'; }
    });
}

// New Ticket Modal Functions
function openNewTicketModal() {
    // Clear form. The requester picker owns the name/email fields now, so it
    // resets them — clearing them here as well would fight it.
    initRequesterPicker();
    reqReset();
    document.getElementById('newTicketSubject').value = '';
    document.getElementById('newTicketBody').value = '';

    // Populate department dropdown
    const deptSelect = document.getElementById('newTicketDepartment');
    deptSelect.innerHTML = '<option value="">-- Select --</option>' +
        departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

    // Populate ticket type dropdown
    const typeSelect = document.getElementById('newTicketType');
    typeSelect.innerHTML = '<option value="">-- Select --</option>' +
        ticketTypes.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

    // Populate priority dropdown from the CONFIGURED priorities (active only) so
    // custom ones like Urgent/Critical appear instead of a hardcoded Low/Normal/High
    // subset (#40). The value is the priority NAME — createTicket resolves name→id.
    const prioSelect = document.getElementById('newTicketPriority');
    if (ticketPriorities.length) {
        prioSelect.innerHTML = ticketPriorities
            .map(p => `<option value="${escapeHtml(p.name)}">${escapeHtml(p.name)}</option>`)
            .join('');
        const def = ticketPriorities.find(p => p.is_default) || ticketPriorities.find(p => p.name === 'Normal');
        if (def) prioSelect.value = def.name;
    } else {
        // Fallback if priorities haven't loaded yet — keeps the form usable.
        prioSelect.innerHTML = '<option value="Normal">Normal</option>';
    }

    // Populate the "Send replies from" mailbox dropdown for the active company.
    loadNewTicketMailboxes();

    document.getElementById('newTicketModal').classList.add('active');
}

// Load the mailboxes this ticket can be sent from (scoped to the active company)
// and populate the New Ticket modal's mailbox picker. Fires async; the modal can
// open before it returns.
async function loadNewTicketMailboxes() {
    const sel = document.getElementById('newTicketMailbox');
    const label = document.getElementById('newTicketCompanyLabel');
    const hint = document.getElementById('newTicketMailboxHint');
    if (!sel) return;
    sel.innerHTML = '<option value="">Loading…</option>';
    if (label) label.textContent = '';
    if (hint) hint.textContent = '';
    try {
        const r = await fetch(API_BASE + 'get_sendable_mailboxes.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'failed');

        // Show which company these mailboxes belong to (multi-company installs only).
        if (label && d.multi_tenant && d.tenant_name) label.textContent = ' — ' + d.tenant_name;

        if (!d.mailboxes || !d.mailboxes.length) {
            sel.innerHTML = '<option value="">(no sendable mailbox)</option>';
            if (hint) hint.textContent = d.multi_tenant
                ? "No active, signed-in mailbox for this company — you can still create the ticket, but replies can't be emailed until one is set up."
                : "No active, signed-in mailbox — you can still create the ticket, but replies can't be emailed until one is set up.";
            return;
        }
        // Server orders pinned-to-company first, so the first option is the sensible default.
        sel.innerHTML = d.mailboxes.map(m =>
            `<option value="${m.id}">${escapeHtml(m.name)}${m.pinned ? '' : ' (shared)'}</option>`
        ).join('');
    } catch (e) {
        sel.innerHTML = '<option value="">(could not load mailboxes)</option>';
    }
}

function closeNewTicketModal() {
    document.getElementById('newTicketModal').classList.remove('active');
}

async function createNewTicket() {
    const fromName = document.getElementById('newTicketFromName').value.trim();
    const fromEmail = document.getElementById('newTicketFromEmail').value.trim();
    const subject = document.getElementById('newTicketSubject').value.trim();
    const body = document.getElementById('newTicketBody').value.trim();
    const departmentId = document.getElementById('newTicketDepartment').value;
    const ticketTypeId = document.getElementById('newTicketType').value;
    const priority = document.getElementById('newTicketPriority').value;
    const mailboxId = document.getElementById('newTicketMailbox').value;

    // Validate required fields. Two valid shapes now: a requester chosen from
    // the picker, or a name and address typed for somebody genuinely new.
    if (!reqChosenUser) {
        if (!fromName || !fromEmail) {
            showToast(t('tickets.new_ticket_modal.requester_required'), 'error');
            document.getElementById('reqSearch').focus();
            return;
        }
    }
    if (!subject) {
        showToast('Please enter a subject', 'error');
        return;
    }

    // Get the create button and show loading state
    const createBtn = document.querySelector('#newTicketModal .btn-primary');
    const originalText = createBtn.textContent;
    createBtn.disabled = true;
    createBtn.textContent = 'Creating...';

    try {
        const response = await fetch(API_BASE + 'create_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                // The id when one was chosen, the typed pair when it was not.
                // Never both: a stale name beside a chosen person is exactly the
                // ambiguity this replaced.
                user_id: reqChosenUser ? reqChosenUser.id : null,
                from_name: reqChosenUser ? '' : fromName,
                from_email: reqChosenUser ? '' : fromEmail,
                subject: subject,
                body: body,
                department_id: departmentId || null,
                ticket_type_id: ticketTypeId || null,
                priority: priority,
                mailbox_id: mailboxId || null
            })
        });

        const data = await response.json();

        if (data.success) {
            closeNewTicketModal();
            // Refresh the view
            loadFolderCounts();
            loadEmails();
            showToast('Ticket created successfully: ' + data.ticket_number, 'success');
        } else {
            showToast('Error creating ticket: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to create ticket', 'error');
    } finally {
        createBtn.disabled = false;
        createBtn.textContent = originalText;
    }
}

// ============================================
// Requester picker (discussion #54)
//
// One field replacing the old name + email pair. The email was already the
// identity — TicketsService looks the requester up by address — so the name box
// was doing nothing whenever the person existed, and a typo in the address
// silently created a second, ghost requester. Both of those are what this fixes.
//
// Shape, and why:
//   · Typeahead inline, NOT a modal. Raising a ticket on someone's behalf is a
//     tens-of-times-a-day action; a second surface to open and close is friction
//     paid every time to help a rare case. The modal still exists, behind the
//     magnifier, for when you want to browse rather than search.
//   · "+ Add someone new" is ALWAYS the last row, not a zero-results state. If
//     you are adding a Dan and a Dan already exists, a no-matches trigger would
//     never appear — the one time you most need it.
//   · Choosing sends user_id. The server re-checks it with analystCanAccessUser:
//     the list being scoped governs what is easy to pick, not what can be sent.
// ============================================

let reqChosenUser = null;      // {id, name, email, company} or null
let reqSearchTimer = null;
let reqActiveIndex = -1;       // keyboard cursor into reqLastResults
let reqLastResults = [];

function reqEls() {
    return {
        picker:  document.getElementById('reqPicker'),
        input:   document.getElementById('reqSearch'),
        results: document.getElementById('reqResults'),
        chosen:  document.getElementById('reqChosen'),
        newBox:  document.getElementById('reqNew'),
        name:    document.getElementById('newTicketFromName'),
        email:   document.getElementById('newTicketFromEmail')
    };
}

/** Back to the empty state: nothing chosen, nothing typed, no new-person form. */
function reqReset() {
    const e = reqEls();
    if (!e.input) return;
    reqChosenUser = null;
    reqLastResults = [];
    reqActiveIndex = -1;
    e.input.value = '';
    e.results.hidden = true;
    e.results.innerHTML = '';
    e.input.setAttribute('aria-expanded', 'false');
    e.chosen.hidden = true;
    e.picker.hidden = false;
    e.newBox.hidden = true;
    if (e.name) e.name.value = '';
    if (e.email) e.email.value = '';
}

function reqCloseList() {
    const e = reqEls();
    if (!e.results) return;
    e.results.hidden = true;
    e.input.setAttribute('aria-expanded', 'false');
    reqActiveIndex = -1;
}

/** Commit a chosen requester and swap the input for the chip. */
function reqChoose(user) {
    const e = reqEls();
    reqChosenUser = user;
    document.getElementById('reqChosenAvatar').textContent = inboxInitials(user.name || user.email || '?');
    document.getElementById('reqChosenName').textContent = user.name || '(no name)';
    document.getElementById('reqChosenEmail').textContent = user.email || '';
    const comp = document.getElementById('reqChosenCompany');
    // The company is the confirmation that you picked the right Daniel, so it
    // shows only when there is more than one to confuse.
    if (user.company) { comp.textContent = user.company; comp.hidden = false; }
    else { comp.hidden = true; }
    e.picker.hidden = true;
    e.newBox.hidden = true;
    e.chosen.hidden = false;
    reqCloseList();
}

/** Reveal the new-person fields, pre-filled from whatever was typed. */
function reqShowNew(term) {
    const e = reqEls();
    const looksLikeEmail = term.indexOf('@') !== -1;
    e.newBox.hidden = false;
    // Put what they typed where it belongs, then land focus on the empty one —
    // retyping a name you have already typed is the thing that makes a fallback
    // feel like a punishment.
    if (looksLikeEmail) {
        e.email.value = term;
        e.name.value = '';
        e.name.focus();
    } else {
        e.name.value = term;
        e.email.value = '';
        e.email.focus();
    }
    reqCloseList();
}

function reqRenderResults(users, term) {
    const e = reqEls();
    reqLastResults = users;
    reqActiveIndex = -1;

    const rows = users.map((u, i) => {
        const name  = u.display_name || u.preferred_name || u.username || '(no name)';
        const email = u.email || u.username || '';
        const comp  = u.tenant_name ? `<span class="req-opt-company">${escapeHtml(u.tenant_name)}</span>` : '';
        return `<div class="req-opt" role="option" data-i="${i}" aria-selected="false">
                    <span class="req-opt-avatar">${escapeHtml(inboxInitials(name))}</span>
                    <span class="req-opt-text">
                        <span class="req-opt-name">${escapeHtml(name)}</span>
                        <span class="req-opt-email">${escapeHtml(email)}</span>
                    </span>${comp}
                </div>`;
    }).join('');

    // Always last, never conditional on emptiness — see the header note.
    const addLabel = term
        ? t('tickets.new_ticket_modal.requester_add_named').replace('%s', term)
        : t('tickets.new_ticket_modal.requester_add');
    const addRow = `<div class="req-opt req-opt-add" role="option" data-add="1" aria-selected="false">
                        <span class="req-opt-avatar req-opt-avatar-add">+</span>
                        <span class="req-opt-text"><span class="req-opt-name">${escapeHtml(addLabel)}</span></span>
                    </div>`;

    const empty = users.length ? '' :
        `<div class="req-empty">${escapeHtml(t('tickets.new_ticket_modal.requester_none'))}</div>`;

    e.results.innerHTML = empty + rows + addRow;
    e.results.hidden = false;
    e.input.setAttribute('aria-expanded', 'true');
}

async function reqSearch() {
    const e = reqEls();
    const term = e.input.value.trim();
    try {
        const res = await fetch(API_BASE + 'get_users.php?limit=8&search=' + encodeURIComponent(term));
        const data = await res.json();
        reqRenderResults((data.success && data.users) ? data.users : [], term);
    } catch (err) {
        reqRenderResults([], term);
    }
}

/** Move the keyboard cursor. Rows include the trailing "add" row. */
function reqMove(delta) {
    const e = reqEls();
    const opts = e.results.querySelectorAll('.req-opt');
    if (!opts.length) return;
    reqActiveIndex += delta;
    if (reqActiveIndex < 0) reqActiveIndex = opts.length - 1;
    if (reqActiveIndex >= opts.length) reqActiveIndex = 0;
    opts.forEach((o, i) => {
        const on = i === reqActiveIndex;
        o.classList.toggle('is-active', on);
        o.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) o.scrollIntoView({ block: 'nearest' });
    });
}

function reqCommitActive() {
    const e = reqEls();
    const opts = e.results.querySelectorAll('.req-opt');
    const el = opts[reqActiveIndex];
    if (!el) return false;
    reqActivateOption(el);
    return true;
}

function reqActivateOption(el) {
    const e = reqEls();
    if (el.hasAttribute('data-add')) {
        reqShowNew(e.input.value.trim());
        return;
    }
    const u = reqLastResults[parseInt(el.getAttribute('data-i'), 10)];
    if (!u) return;
    reqChoose({
        id: u.id,
        name: u.display_name || u.preferred_name || u.username || '',
        email: u.email || '',
        company: u.tenant_name || ''
    });
}

function initRequesterPicker() {
    const e = reqEls();
    if (!e.input || e.input.getAttribute('data-req-ready')) return;
    e.input.setAttribute('data-req-ready', '1');

    e.input.addEventListener('input', () => {
        clearTimeout(reqSearchTimer);
        reqSearchTimer = setTimeout(reqSearch, 200);   // one request per pause
    });
    e.input.addEventListener('focus', () => { if (e.results.hidden) reqSearch(); });

    e.input.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowDown')      { ev.preventDefault(); reqMove(1); }
        else if (ev.key === 'ArrowUp')   { ev.preventDefault(); reqMove(-1); }
        else if (ev.key === 'Enter')     { if (!e.results.hidden && reqCommitActive()) ev.preventDefault(); }
        else if (ev.key === 'Escape')    { if (!e.results.hidden) { ev.stopPropagation(); reqCloseList(); } }
    });

    e.results.addEventListener('mousedown', (ev) => {
        // mousedown, not click: blur would close the list before click landed.
        const opt = ev.target.closest('.req-opt');
        if (opt) { ev.preventDefault(); reqActivateOption(opt); }
    });

    document.addEventListener('click', (ev) => {
        if (e.picker && !e.picker.contains(ev.target)) reqCloseList();
    });

    document.getElementById('reqChosenClear').addEventListener('click', () => {
        reqReset();
        e.input.focus();
    });
    document.getElementById('reqNewCancel').addEventListener('click', () => {
        e.newBox.hidden = true;
        e.name.value = '';
        e.email.value = '';
        e.input.focus();
    });
    document.getElementById('reqBrowse').addEventListener('click', openReqBrowse);
}

// ─── Browse modal: the escape hatch ──────────────────────────────────────────

function openReqBrowse() {
    const m = document.getElementById('reqBrowseModal');
    m.classList.add('active');
    const s = document.getElementById('reqBrowseSearch');
    s.value = document.getElementById('reqSearch').value.trim();   // carry the term across
    reqBrowseLoad();
    setTimeout(() => s.focus(), 30);
    if (!s.getAttribute('data-ready')) {
        s.setAttribute('data-ready', '1');
        let tmr = null;
        s.addEventListener('input', () => { clearTimeout(tmr); tmr = setTimeout(reqBrowseLoad, 200); });
    }
}

function closeReqBrowse() {
    document.getElementById('reqBrowseModal').classList.remove('active');
}

async function reqBrowseLoad() {
    const list = document.getElementById('reqBrowseList');
    const term = document.getElementById('reqBrowseSearch').value.trim();
    list.innerHTML = '<div class="req-empty">' + escapeHtml(t('common.loading')) + '</div>';
    try {
        const res = await fetch(API_BASE + 'get_users.php?limit=100&search=' + encodeURIComponent(term));
        const data = await res.json();
        const users = (data.success && data.users) ? data.users : [];
        if (!users.length) {
            list.innerHTML = '<div class="req-empty">' + escapeHtml(t('tickets.new_ticket_modal.requester_none')) + '</div>';
            return;
        }
        list.innerHTML = users.map(u => {
            const name = u.display_name || u.preferred_name || u.username || '(no name)';
            return `<div class="req-browse-row" data-id="${u.id}"
                         data-name="${escapeHtml(name)}"
                         data-email="${escapeHtml(u.email || '')}"
                         data-company="${escapeHtml(u.tenant_name || '')}">
                        <span class="req-opt-avatar">${escapeHtml(inboxInitials(name))}</span>
                        <span class="req-browse-name">${escapeHtml(name)}</span>
                        <span class="req-browse-email">${escapeHtml(u.email || '')}</span>
                        <span class="req-browse-company">${escapeHtml(u.tenant_name || '')}</span>
                    </div>`;
        }).join('');
    } catch (err) {
        list.innerHTML = '<div class="req-empty">' + escapeHtml(t('tickets.new_ticket_modal.requester_none')) + '</div>';
    }
}

document.addEventListener('click', function (ev) {
    const row = ev.target.closest ? ev.target.closest('.req-browse-row') : null;
    if (!row) return;
    reqChoose({
        id: parseInt(row.getAttribute('data-id'), 10),
        name: row.getAttribute('data-name'),
        email: row.getAttribute('data-email'),
        company: row.getAttribute('data-company')
    });
    closeReqBrowse();
});

// ============================================
// Search Modal Functions
// ============================================

let searchModalDragging = false;
let searchModalOffsetX = 0;
let searchModalOffsetY = 0;

function openSearchModal() {
    const modal = document.getElementById('searchModal');
    modal.classList.add('active');

    // Position modal so right edge aligns with refresh button's right edge
    const refreshBtn = document.querySelector('.refresh-btn');
    if (refreshBtn) {
        const btnRect = refreshBtn.getBoundingClientRect();
        const modalWidth = 500; // matches CSS width
        const rightEdge = btnRect.right;
        const leftPos = rightEdge - modalWidth;

        modal.style.left = Math.max(10, leftPos) + 'px';
        modal.style.top = (btnRect.bottom + 10) + 'px';
        modal.style.transform = 'none';
    } else {
        // Fallback to center
        modal.style.left = '50%';
        modal.style.top = '100px';
        modal.style.transform = 'translateX(-50%)';
    }

    // Initialize dragging
    initSearchModalDrag();

    // Focus the first input
    document.getElementById('searchTicketNumber').focus();
}

function closeSearchModal() {
    document.getElementById('searchModal').classList.remove('active');
    // Don't clear search - user can reopen to see previous results
    // Use the Clear button to reset if needed
}

function initSearchModalDrag() {
    const modal = document.getElementById('searchModal');
    const header = document.getElementById('searchModalHeader');

    header.onmousedown = function(e) {
        if (e.target.classList.contains('search-modal-close')) return;

        searchModalDragging = true;

        // Remove the transform so we can use left/top directly
        const rect = modal.getBoundingClientRect();
        modal.style.transform = 'none';
        modal.style.left = rect.left + 'px';
        modal.style.top = rect.top + 'px';

        searchModalOffsetX = e.clientX - rect.left;
        searchModalOffsetY = e.clientY - rect.top;

        document.onmousemove = function(e) {
            if (!searchModalDragging) return;

            let newX = e.clientX - searchModalOffsetX;
            let newY = e.clientY - searchModalOffsetY;

            // Keep within viewport bounds
            newX = Math.max(0, Math.min(newX, window.innerWidth - modal.offsetWidth));
            newY = Math.max(0, Math.min(newY, window.innerHeight - modal.offsetHeight));

            modal.style.left = newX + 'px';
            modal.style.top = newY + 'px';
        };

        document.onmouseup = function() {
            searchModalDragging = false;
            document.onmousemove = null;
            document.onmouseup = null;
        };
    };
}

async function performSearch() {
    const ticketNumber = document.getElementById('searchTicketNumber').value.trim();
    const email = document.getElementById('searchEmail').value.trim();
    const subject = document.getElementById('searchSubject').value.trim();
    const contentEl = document.getElementById('searchContent');
    const content = contentEl ? contentEl.value.trim() : '';

    // Content search is a different question — "which tickets mention this?" rather
    // than "which ticket is this?" — so it goes to its own endpoint and renders its
    // own result shape. It wins when both are filled, because it is the more
    // specific thing the analyst asked for.
    if (content) { return performContentSearch(content); }

    // Validate at least one field
    if (!ticketNumber && !email && !subject) {
        showToast('Please enter at least one search criterion', 'error');
        return;
    }

    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = '<div class="search-loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(API_BASE + 'search_tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_number: ticketNumber,
                email: email,
                subject: subject
            })
        });

        const data = await response.json();

        if (data.success) {
            renderSearchResults(data.results);
        } else {
            resultsContainer.innerHTML = `<div class="search-results-empty">Error: ${data.error}</div>`;
        }
    } catch (error) {
        console.error('Search error:', error);
        resultsContainer.innerHTML = '<div class="search-results-empty">Search failed. Please try again.</div>';
    }
}

function renderSearchResults(results) {
    const container = document.getElementById('searchResults');

    if (!results || results.length === 0) {
        container.innerHTML = '<div class="search-results-empty">No tickets found matching your criteria</div>';
        return;
    }

    let html = `<div class="search-results-count">${results.length} ticket${results.length === 1 ? '' : 's'} found</div>`;

    results.forEach(ticket => {
        html += `
            <div class="search-result-item" onclick="selectSearchResult(${ticket.email_id})">
                <div class="search-result-ticket">${escapeHtml(ticket.ticket_number)}</div>
                <div class="search-result-subject">${escapeHtml(ticket.subject)}</div>
                <div class="search-result-meta">
                    <span>${senderLabel(ticket.from_name, ticket.from_address, false)}</span>
                    <span>${ticket.status}</span>
                    <span>${formatDateTime(ticket.received_datetime)}</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// --- Searching INSIDE tickets (message bodies and notes) --------------------
// Its own function because the results answer a different question and so carry
// a different shape: a snippet, and which parts of the ticket matched.
async function performContentSearch(query) {
    const container = document.getElementById('searchResults');
    container.innerHTML = '<div class="search-loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(API_BASE + 'search_content.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query: query })
        });
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<div class="search-results-empty">${escapeHtml(data.error || 'Search failed')}</div>`;
            return;
        }
        renderContentSearchResults(data);
    } catch (error) {
        console.error('Content search error:', error);
        container.innerHTML = '<div class="search-results-empty">Search failed. Please try again.</div>';
    }
}

function renderContentSearchResults(data) {
    const container = document.getElementById('searchResults');
    const T = (k, p) => window.t ? window.t('tickets.search_modal.' + k, p) : k;

    // "We could not search that" is not the same as "it is not in your tickets",
    // and saying so is the difference between a useful answer and a dead end.
    if (data.reason === 'no_usable_terms') {
        container.innerHTML = `<div class="search-results-empty">${escapeHtml(T('too_short', { n: data.min_length || 3 }))}</div>`;
        return;
    }
    if (data.reason === 'not_ready') {
        container.innerHTML = `<div class="search-results-empty">${escapeHtml(T('not_indexed'))}</div>`;
        return;
    }
    if (!data.results || data.results.length === 0) {
        let msg = 'No tickets found matching your criteria';
        if (data.dropped && data.dropped.length) {
            msg += ' — ' + T('ignored_terms', { terms: data.dropped.join(', ') });
        }
        container.innerHTML = `<div class="search-results-empty">${escapeHtml(msg)}</div>`;
        return;
    }

    const partName = (t) => T('part_' + t) || t;
    let html = `<div class="search-results-count">${data.total} ticket${data.total === 1 ? '' : 's'} found</div>`;
    if (data.dropped && data.dropped.length) {
        html += `<div class="search-results-note">${escapeHtml(T('ignored_terms', { terms: data.dropped.join(', ') }))}</div>`;
    }

    data.results.forEach(r => {
        const where = (r.matched || []).map(partName).join(', ');
        const more  = r.hit_count > 1 ? ` ${escapeHtml(T('more_hits', { n: r.hit_count - 1 }))}` : '';
        html += `
            <div class="search-result-item" onclick="selectSearchResult(${r.email_id})">
                <div class="search-result-ticket">${escapeHtml(r.ticket_number)}</div>
                <div class="search-result-subject">${escapeHtml(r.subject)}</div>
                ${r.snippet ? `<div class="search-result-snippet">${escapeHtml(r.snippet)}</div>` : ''}
                <div class="search-result-meta">
                    <span>${escapeHtml(T('found_in'))}: ${escapeHtml(where)}${more}</span>
                    <span>${escapeHtml(r.status || '')}</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function selectSearchResult(emailId) {
    // Keep the modal open so user can try another result if needed
    // Select the email in the reading pane
    selectEmail(emailId);
}

function clearSearch() {
    document.getElementById('searchTicketNumber').value = '';
    document.getElementById('searchEmail').value = '';
    document.getElementById('searchSubject').value = '';
    const c = document.getElementById('searchContent');
    if (c) c.value = '';
    document.getElementById('searchResults').innerHTML = '<div class="search-results-empty">Enter search criteria above</div>';
}

// Allow Enter key to trigger search
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = ['searchTicketNumber', 'searchEmail', 'searchSubject', 'searchContent'];
    searchInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        }
    });
});

// ============================================
// Schedule Modal Functions
// ============================================

// Scheduling maths lives in assets/js/schedule.js, shared with the tickets
// calendar (which can now reschedule too) — one statement of what a duration
// means, what all-day is stored as, and how a naive value is parsed. See the
// header there for why toISOString() must never touch these.
const SCHEDULE_DEFAULT_MINUTES = FreeITSMSchedule.DEFAULT_MINUTES;
const parseNaiveDateTime = FreeITSMSchedule.parseNaive;

/**
 * Put `minutes` in the duration select.
 *
 * A value the list cannot express — 90 minutes set through the REST API, say —
 * gets an option of its own rather than being snapped to the nearest listed one.
 * Snapping would silently rewrite somebody's duration the next time they opened
 * the box to change the date, which is a data change nobody asked for.
 */
function setScheduleDuration(minutes) {
    const sel = document.getElementById('scheduleDuration');
    if (!sel) return;
    const custom = sel.querySelector('option[data-custom]');
    if (custom) custom.remove();
    if (![...sel.options].some(o => parseInt(o.value, 10) === minutes)) {
        const opt = document.createElement('option');
        opt.value = String(minutes);
        opt.dataset.custom = '1';
        opt.textContent = t('tickets.schedule_modal.dur_custom', { n: minutes });
        sel.appendChild(opt);
    }
    sel.value = String(minutes);
}

/** All-day means no start time and no duration, so both fields go away. */
function syncScheduleAllDay() {
    const allDay = document.getElementById('scheduleAllDay').checked;
    document.getElementById('scheduleTimeGroup').style.display     = allDay ? 'none' : '';
    document.getElementById('scheduleDurationGroup').style.display = allDay ? 'none' : '';
}

// Which ticket the schedule modal is editing. NOT always the one in the reading
// pane: the inbox context menu can target a row that is not open, or act when
// nothing is open at all, so the modal must carry its own subject.
let scheduleTargetId = null;

/**
 * Open Schedule work.
 *
 * With no arguments it targets the reading pane, which is what the ticket's own
 * Schedule button wants. The context menu passes a row from `emails` instead —
 * that row carries the schedule columns (see api/tickets/get_emails.php), so
 * right-clicking a ticket that is not open still prefills what it is set to.
 */
function openScheduleModal(ticketId, ticketRef, sched) {
    if (ticketId === undefined) {
        if (!currentEmail || !currentEmail.ticket_id) {
            showToast('No ticket selected', 'error');
            return;
        }
        ticketId  = currentEmail.ticket_id;
        ticketRef = `${currentEmail.ticket_number} - ${currentEmail.subject}`;
        sched     = currentEmail;
    }
    if (!ticketId) return;
    scheduleTargetId = ticketId;
    sched = sched || {};

    // Set ticket info
    document.getElementById('scheduleTicketInfo').textContent = ticketRef || '';

    // Default: today, on the next hour, for an hour, not all day.
    const now = new Date();
    const p = n => String(n).padStart(2, '0');
    document.getElementById('scheduleDate').value =
        `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`;
    now.setHours(now.getHours() + 1, 0, 0, 0);
    document.getElementById('scheduleTime').value = `${p(now.getHours())}:${p(now.getMinutes())}`;
    document.getElementById('scheduleAllDay').checked = false;
    setScheduleDuration(SCHEDULE_DEFAULT_MINUTES);

    // Check if already scheduled
    const existing = parseNaiveDateTime(sched.work_start_datetime);
    if (existing) {
        document.getElementById('currentSchedule').textContent = formatNaiveFullDateTime(sched.work_start_datetime);
        document.getElementById('scheduleCurrent').style.display = 'block';

        // Pre-fill with existing schedule
        document.getElementById('scheduleDate').value = existing.date;
        document.getElementById('scheduleTime').value = existing.time;
        document.getElementById('scheduleAllDay').checked = !!sched.work_all_day;

        // A ticket scheduled before the end column existed has none, and gets the
        // default rather than reading as "zero minutes".
        setScheduleDuration(
            FreeITSMSchedule.durationMinutes(sched.work_start_datetime, sched.work_end_datetime)
        );
    } else {
        document.getElementById('scheduleCurrent').style.display = 'none';
    }

    syncScheduleAllDay();
    document.getElementById('scheduleModal').classList.add('active');
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

/**
 * Reflect a saved schedule in whatever the page is already holding.
 *
 * TWO places, because the modal can be opened on a ticket that is not the one in
 * the reading pane: the inbox row in `emails` (so re-opening the context menu
 * prefills what was just set) and `currentEmail` — but the latter ONLY when it
 * is actually this ticket, or scheduling from the context menu would rewrite the
 * open ticket's schedule with another ticket's times.
 */
function applyScheduleLocally(ticketId, start, end, allDay) {
    const row = emails.find(e => e.ticket_id == ticketId);
    [row, (currentEmail && currentEmail.ticket_id == ticketId) ? currentEmail : null]
        .filter(Boolean)
        .forEach(o => {
            o.work_start_datetime = start;
            o.work_end_datetime   = end;
            o.work_all_day        = allDay;
        });
}

async function saveSchedule() {
    const date   = document.getElementById('scheduleDate').value;
    const time   = document.getElementById('scheduleTime').value;
    const allDay = document.getElementById('scheduleAllDay').checked;

    if (!date || (!allDay && !time)) {
        showToast('Please select both date and time', 'error');
        return;
    }

    const range = FreeITSMSchedule.toStoredRange(
        date, time, document.getElementById('scheduleDuration').value, allDay
    );
    const workStart = range.start;
    const workEnd   = range.end;

    try {
        const response = await fetch(API_BASE + 'schedule_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: scheduleTargetId,
                work_start_datetime: workStart,
                work_end_datetime: workEnd,
                all_day: allDay ? 1 : 0
            })
        });

        const data = await response.json();

        if (data.success) {
            applyScheduleLocally(scheduleTargetId, workStart, workEnd, allDay ? 1 : 0);
            closeScheduleModal();
            showToast('Work scheduled successfully', 'success');
        } else {
            showToast('Error scheduling: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to schedule work', 'error');
    }
}

async function clearSchedule() {
    if (!(await showConfirm({ title: 'Confirm', message: 'Are you sure you want to clear the scheduled work time?', okLabel: 'OK', okClass: 'primary' }))) return;

    try {
        const response = await fetch(API_BASE + 'schedule_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: scheduleTargetId,
                work_start_datetime: null
            })
        });

        const data = await response.json();

        if (data.success) {
            // Clear the whole schedule locally, exactly as the service does on the
            // server — leaving a stale end behind would have the modal reopen
            // offering a duration for work that is no longer scheduled.
            applyScheduleLocally(scheduleTargetId, null, null, 0);
            closeScheduleModal();
            showToast('Schedule cleared', 'error');
        } else {
            showToast('Error clearing schedule: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to clear schedule', 'error');
    }
}

// ===== AI Chat Functions (Ask AI) =====

let _ticketAiContextId = null; // Track which ticket has been auto-queried

function openTicketAiChat() {
    if (!currentEmail) return;

    const panel = document.getElementById('ticketAiPanel');
    const overlay = document.getElementById('ticketAiOverlay');
    panel.classList.add('active');
    overlay.classList.add('active');

    // If ticket changed, reset chat
    if (_ticketAiContextId !== currentEmail.ticket_id) {
        _ticketAiContextId = currentEmail.ticket_id;
        const messagesContainer = document.getElementById('ticketAiMessages');
        messagesContainer.innerHTML = '<div class="ai-chat-welcome">Ask a question about this ticket and the AI will search the knowledge base for relevant articles.</div>';

        // Auto-send initial context question
        const subject = currentEmail.subject || '';
        const bodyText = (currentEmail.body_content || '').replace(/<[^>]*>/g, '').substring(0, 1500);
        const autoQuestion = `I'm looking at ticket ${currentEmail.ticket_number || ''}: ${subject}.\n\nHere's the initial email:\n\n${bodyText}\n\nAre there any knowledge articles that might help resolve this?`;
        _sendTicketAiMessage(autoQuestion, true);
    }

    document.getElementById('ticketAiInput').focus();
}

function closeTicketAiChat() {
    document.getElementById('ticketAiPanel').classList.remove('active');
    document.getElementById('ticketAiOverlay').classList.remove('active');
}

function askTicketAi() {
    const input = document.getElementById('ticketAiInput');
    const question = input.value.trim();
    if (!question) return;
    input.value = '';
    _sendTicketAiMessage(question, false);
}

async function _sendTicketAiMessage(question, isAutoContext) {
    const messagesContainer = document.getElementById('ticketAiMessages');
    const input = document.getElementById('ticketAiInput');
    const sendBtn = document.getElementById('ticketAiSendBtn');

    // Clear welcome message
    const welcome = messagesContainer.querySelector('.ai-chat-welcome');
    if (welcome) welcome.remove();

    // Add user message bubble (show a shorter version for auto-context)
    const userMsg = document.createElement('div');
    userMsg.className = 'ai-chat-message user';
    const displayText = isAutoContext
        ? `Find knowledge articles relevant to: ${currentEmail.subject || 'this ticket'}`
        : question;
    userMsg.innerHTML = '<div class="ai-chat-bubble">' + escapeHtml(displayText) + '</div>';
    messagesContainer.appendChild(userMsg);

    // Disable input
    input.disabled = true;
    sendBtn.disabled = true;

    // Add thinking indicator
    const thinking = document.createElement('div');
    thinking.className = 'ai-chat-thinking';
    thinking.innerHTML = '<div class="dots"><span></span><span></span><span></span></div> Searching knowledge base...';
    messagesContainer.appendChild(thinking);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;

    try {
        const response = await fetch('../api/knowledge/ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question, include_archived: false })
        });
        const data = await response.json();

        thinking.remove();

        if (data.success) {
            const assistantMsg = document.createElement('div');
            assistantMsg.className = 'ai-chat-message assistant';
            assistantMsg.innerHTML = '<div class="ai-chat-bubble">' + formatTicketAiResponse(data.answer, data.articles || []) + '</div>' +
                '<div class="ai-chat-meta">Searched ' + data.articles_searched + ' articles</div>';
            messagesContainer.appendChild(assistantMsg);
        } else {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'ai-chat-error';
            errorMsg.textContent = data.error || 'Failed to get a response. Please check the AI API key in Knowledge Settings.';
            messagesContainer.appendChild(errorMsg);
        }
    } catch (error) {
        thinking.remove();
        const errorMsg = document.createElement('div');
        errorMsg.className = 'ai-chat-error';
        errorMsg.textContent = 'Network error: ' + error.message;
        messagesContainer.appendChild(errorMsg);
    }

    input.disabled = false;
    sendBtn.disabled = false;
    input.focus();
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function formatTicketAiResponse(text, articlesList) {
    // Replace quoted article titles with hyperlinks
    if (articlesList && articlesList.length > 0) {
        const sorted = [...articlesList].sort((a, b) => b.title.length - a.title.length);
        sorted.forEach(article => {
            const escapedTitle = article.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp('["\u201c]' + escapedTitle + '(\\s*\\(ID:\\s*\\d+\\))?["\u201d]', 'gi');
            const link = '<a href="../knowledge/?id=' + article.id + '" target="_blank" class="ai-article-link">\u201c' + escapeHtml(article.title) + '\u201d</a>';
            text = text.replace(regex, link);
        });
    }

    // Markdown-like formatting
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    text = text.replace(/(?<!\w)_([^_]+)_(?!\w)/g, '<em>$1</em>');
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Paragraphs and lists
    const paragraphs = text.split(/\n\n+/);
    if (paragraphs.length > 1) {
        text = paragraphs.map(p => {
            p = p.trim();
            if (!p) return '';
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
        const panel = document.getElementById('ticketAiPanel');
        if (panel && panel.classList.contains('active')) {
            closeTicketAiChat();
        }
    }
});

/* --- Pop-out (full-screen) ticket view ---
 * Toggles a body class that hides the folder list + email list and floats the
 * ticket properties container as a right-hand panel. Pure CSS — no DOM
 * restructuring. Preference persists in localStorage so the analyst's choice
 * sticks across reloads / ticket selections.
 */
function toggleTicketPopout() {
    const on = document.body.classList.toggle('ticket-popout');
    try { localStorage.setItem('tickets_popout', on ? '1' : '0'); } catch (e) {}
}

/* Double-click on an email row: open it AND pop out. Sets the popout pref
 * so the syncPopoutToTicketState call inside displayEmail applies the class
 * once the ticket renders. Goes through the same storage path as the toggle
 * button so the state is consistent (an F5 mid-popout will land you in 3-col
 * view, but as soon as you pick a ticket again you're back in popout). */
function selectEmailFullScreen(emailId) {
    try { localStorage.setItem('tickets_popout', '1'); } catch (e) {}
    selectEmail(emailId);
}

/* --- Time entries ----------------------------------------------------------
 * Per-ticket time logging. List + inline add form, soft-delete on own rows.
 * API lives at api/tickets/{get,save,delete}_time_entry.php.
 */
let currentTimeEntries = [];

async function loadTimeEntries(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_time_entries.php?ticket_id=${ticketId}`);
        const data = await response.json();

        // Time tracking switched off for this ticket's company (discussion #72).
        // The SERVER decides, per ticket, because the answer is per company and a
        // ticket always belongs to one — so an analyst working across two clients
        // sees it on one ticket and not the next, which is correct rather than odd.
        // Nothing is deleted; the entries are simply not shown.
        if (data.disabled) {
            const container = document.getElementById('timeEntriesContainer');
            if (container) container.innerHTML = '';
            currentTimeEntries = [];
            return;
        }

        currentTimeEntries = data.success ? data.entries : [];
        renderTimeEntries(data.success ? data.total_minutes : 0);
    } catch (e) {
        console.error('Time entries load failed:', e);
        currentTimeEntries = [];
        renderTimeEntries(0);
    }
}

// Convert minutes int to a short display string: 45 → "45m", 90 → "1h 30m".
function formatMinutes(mins) {
    mins = Math.max(0, parseInt(mins, 10) || 0);
    if (mins < 60) return mins + 'm';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}

function renderTimeEntries(totalMinutes) {
    const container = document.getElementById('timeEntriesContainer');
    if (!container) return;

    const myAnalystId = window.CURRENT_ANALYST_ID || 0;
    const deleteTitle = t('tickets.time_entries.delete_title');
    const totalLabel = totalMinutes > 0
        ? ' &middot; ' + escapeHtml(t('tickets.time_entries.total_prefix', { amount: formatMinutes(totalMinutes) }))
        : '';

    let rowsHtml = '';
    if (currentTimeEntries.length === 0) {
        rowsHtml = `<div class="time-entry-empty">${escapeHtml(t('tickets.time_entries.empty'))}</div>`;
    } else {
        rowsHtml = currentTimeEntries.map(e => {
            const canDelete = parseInt(e.analyst_id, 10) === parseInt(myAnalystId, 10);
            const deleteBtn = canDelete
                ? `<button class="time-entry-delete" onclick="deleteTimeEntry(${e.id})" title="${escapeHtml(deleteTitle)}" aria-label="${escapeHtml(deleteTitle)}">&times;</button>`
                : '';
            const notesHtml = e.notes
                ? `<div class="time-entry-notes">${escapeHtml(e.notes)}</div>`
                : '';
            return `
                <div class="time-entry-item">
                    <div class="time-entry-row">
                        <span class="time-entry-spent">${escapeHtml(formatMinutes(e.time_spent_minutes))}</span>
                        <span class="time-entry-analyst">${escapeHtml(e.analyst_name)}</span>
                        <span class="time-entry-date">${formatDateTime(e.entry_datetime)}</span>
                        ${deleteBtn}
                    </div>
                    ${notesHtml}
                </div>
            `;
        }).join('');
    }

    container.innerHTML = `
        <div class="time-entries-section">
            <div class="time-entries-header">${escapeHtml(t('tickets.time_entries.section_title'))}${totalLabel}</div>
            <form class="time-entry-form" onsubmit="event.preventDefault(); saveTimeEntry();">
                <input type="number" id="timeEntryMinutes" class="time-entry-input-minutes"
                       min="1" step="1" placeholder="${escapeHtml(t('tickets.time_entries.minutes_placeholder'))}" required>
                <input type="text" id="timeEntryNotes" class="time-entry-input-notes"
                       placeholder="${escapeHtml(t('tickets.time_entries.notes_placeholder'))}">
                <button type="submit" class="time-entry-add-btn">${escapeHtml(t('tickets.time_entries.add_btn'))}</button>
            </form>
            <div class="time-entry-list">${rowsHtml}</div>
        </div>
    `;
}

async function saveTimeEntry() {
    if (!currentEmail) return;
    const minutes = parseInt(document.getElementById('timeEntryMinutes').value, 10);
    const notes   = document.getElementById('timeEntryNotes').value.trim();

    if (!minutes || minutes <= 0) {
        showToast(t('tickets.time_entries.minutes_required'), 'error');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                time_spent_minutes: minutes,
                notes: notes
            })
        });
        const data = await response.json();
        if (data.success) {
            loadTimeEntries(currentEmail.ticket_id);
        } else {
            showToast(t('tickets.time_entries.save_failed', { error: data.error || 'unknown error' }), 'error');
        }
    } catch (e) {
        console.error('Save time entry failed:', e);
        showToast(t('tickets.time_entries.save_failed', { error: 'network error' }), 'error');
    }
}

async function deleteTimeEntry(id) {
    if (!(await showConfirm({ title: 'Confirm', message: t('tickets.time_entries.delete_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const response = await fetch(API_BASE + 'delete_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await response.json();
        if (data.success) {
            if (currentEmail) loadTimeEntries(currentEmail.ticket_id);
        } else {
            showToast(t('tickets.time_entries.delete_failed', { error: data.error || 'unknown error' }), 'error');
        }
    } catch (e) {
        console.error('Delete time entry failed:', e);
        showToast(t('tickets.time_entries.delete_failed', { error: 'network error' }), 'error');
    }
}

// Sync body.ticket-popout to the actual reading-pane state. Called by the
// ticket-render path (hasTicket=true: apply class if pref says so) and by
// every empty / loading / error state in the reading pane (hasTicket=false:
// always strip the class). Tying popout to a rendered ticket avoids the
// trap where an F5 with the pref saved leaves the user with folder + list
// hidden and the empty "select a ticket" message in the reading pane.
function syncPopoutToTicketState(hasTicket) {
    if (!hasTicket) {
        document.body.classList.remove('ticket-popout');
        return;
    }
    let prefersPopout = false;
    try { prefersPopout = localStorage.getItem('tickets_popout') === '1'; } catch (e) {}
    if (prefersPopout) document.body.classList.add('ticket-popout');
}

/* --- Right-click context menu for email rows -------------------------------
 * Two actions to start: link CMDB object(s), and record time. Both operate
 * on the right-clicked ticket without changing the current reading-pane
 * selection — handy when you're reading ticket A but need to log time
 * against ticket B without losing your place.
 */
let ctxTargetTicketId = null;
// Set when the menu opens: does this menu act on the whole selection or one row?
// Read by the setXFromContext handlers below, which fan out to the bulk path
// instead of their single-ticket path when it is true.
let ctxActsOnSelection = false;
let ctxTargetTicketRef = '';
let ctxCmdbAcTimer = null;
let ctxCmdbSessionCount = 0;

function openTicketContextMenu(event, ticketId, ticketRef) {
    event.preventDefault();
    ctxTargetTicketId = ticketId;
    ctxTargetTicketRef = ticketRef || ('Ticket ' + ticketId);
    const menu = document.getElementById('ticketContextMenu');
    if (!menu) return;

    // Outlook's rule, and the one people expect without being able to name it:
    // right-clicking a row INSIDE the selection acts on the whole selection;
    // right-clicking a row OUTSIDE it throws the selection away and targets just
    // that row. Getting this backwards is how a bulk action hits the wrong set.
    const rowEl   = event.target.closest ? event.target.closest('.email-item') : null;
    const rowMail = rowEl ? Number(rowEl.dataset.emailId) : null;
    if (rowMail !== null && !selectedEmailIds.has(rowMail)) {
        selectedEmailIds  = new Set([rowMail]);
        selectionAnchorId = rowMail;
        selectionFocusId  = rowMail;
        renderSelectionUi();
    }
    ctxActsOnSelection = selectionCount() > 1;

    // Merge only makes sense for two or more, so the item appears only then.
    const mergeItem = document.getElementById('ctxMergeItem');
    if (mergeItem) {
        mergeItem.style.display = ctxActsOnSelection ? '' : 'none';
        const lbl = document.getElementById('ctxMergeLabel');
        if (lbl) lbl.textContent = t('tickets.context.merge').replace('%d', String(selectionCount()));
    }

    // Change subject is single-ticket only — the inverse of Merge.
    const subjItem = document.getElementById('ctxSubjectItem');
    if (subjItem) subjItem.style.display = ctxActsOnSelection ? 'none' : '';

    // Schedule likewise. One start time across a selection would put every ticket
    // in it at the same minute — N overlapping blocks on the calendar, which is
    // not what anyone means by "schedule these".
    const schedItem = document.getElementById('ctxScheduleItem');
    if (schedItem) schedItem.style.display = ctxActsOnSelection ? 'none' : '';

    // Wake appears only when there is something to wake. On a selection that is
    // any sleeping ticket in it — Wake is harmless on the ones already awake, and
    // hiding it because the first row happens to be awake would be worse.
    const wakeItem = document.getElementById('ctxWakeItem');
    if (wakeItem) {
        const anyAsleep = ctxActsOnSelection
            ? selectedTicketIds().some(id => (emails.find(e => e.ticket_id == id) || {}).snoozed_until)
            : ctxTargetIsSnoozed();
        wakeItem.style.display = anyAsleep ? '' : 'none';
    }

    document.getElementById('ticketContextMenuHeader').textContent = ctxActsOnSelection
        ? t('tickets.bulk.n_selected').replace('%d', String(selectionCount()))
        : ctxTargetTicketRef;

    // Populate the Set-status / Set-priority / Assign-to submenus from
    // their lookups. Rebuilt each time so newly-added entries appear
    // without a page refresh, and so the current value gets a tick when
    // right-clicking the ticket that's already open in the reading pane.
    populateContextSnoozeSubmenu();
    populateContextStatusSubmenu();
    populateContextPrioritySubmenu();
    populateContextDepartmentSubmenu();
    populateContextTypeSubmenu();
    populateContextAssigneeSubmenu();
    populateContextCompanySubmenu();

    // Position at cursor — flip if it would overflow the viewport
    menu.classList.add('active');
    const rect = menu.getBoundingClientRect();
    let x = event.clientX;
    let y = event.clientY;
    if (x + rect.width  > window.innerWidth)  x = window.innerWidth  - rect.width  - 4;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 4;
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';

    // Submenu position — flip leftward when the parent menu lives close to
    // the right edge of the viewport and the submenu wouldn't fit on the right.
    const SUBMENU_W = 220;
    menu.classList.toggle('flip-sub', x + rect.width + SUBMENU_W > window.innerWidth);
}

// Build the Set-status submenu HTML from the active ticket_statuses lookup.
// Tick mark on the row matching the currently-open ticket's status (only
// shows when right-clicking the ticket that's in the reading pane, since
// context actions can target any ticket regardless of selection).
function populateContextStatusSubmenu() {
    const sub = document.getElementById('ctxStatusSubmenu');
    if (!sub) return;
    if (!ticketStatuses.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_statuses')) + '</div>';
        return;
    }
    const currentStatus = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.status || '')
        : '';
    sub.innerHTML = ticketStatuses.map(s => {
        const isCurrent = (s.name === currentStatus);
        const swatch = s.colour
            ? `<span class="ctx-status-swatch" style="background: ${escapeHtml(s.colour)};"></span>`
            : '<span class="ctx-status-swatch" style="background:#ddd;"></span>';
        return `<div class="ticket-context-submenu-item" data-status-name="${escapeHtml(s.name)}" onclick="setStatusFromContext('${escapeHtml(s.name).replace(/'/g, "\\'")}')">
            ${swatch}<span class="ctx-status-name">${escapeHtml(s.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Build the Set-priority submenu HTML from the active ticket_priorities
// lookup. Priority is nullable on tickets, so the first row is a "no priority"
// option that clears the assignment. Same chip + tick pattern as the status
// submenu — colour swatch from the priority's stored colour.
function populateContextPrioritySubmenu() {
    const sub = document.getElementById('ctxPrioritySubmenu');
    if (!sub) return;
    if (!ticketPriorities.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_priorities')) + '</div>';
        return;
    }
    const currentPriorityId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.priority_id ?? null)
        : undefined;
    // "No priority" row that clears the assignment (priority_id is nullable).
    const clearRow = `<div class="ticket-context-submenu-item" data-priority-id="" onclick="setPriorityFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_priority'))}</span>
        ${(currentPriorityId === null || currentPriorityId === undefined) && currentEmail && currentEmail.ticket_id == ctxTargetTicketId ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + ticketPriorities.map(p => {
        const isCurrent = (currentPriorityId != null && p.id == currentPriorityId);
        const swatch = p.colour
            ? `<span class="ctx-status-swatch" style="background: ${escapeHtml(p.colour)};"></span>`
            : '<span class="ctx-status-swatch" style="background:#ddd;"></span>';
        return `<div class="ticket-context-submenu-item" data-priority-id="${p.id}" onclick="setPriorityFromContext(${p.id})">
            ${swatch}<span class="ctx-status-name">${escapeHtml(p.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's priority from the right-click menu. Empty string clears the
// priority (priority_id is nullable on tickets). SLA recomputes lazily on
// the next ticket fetch — no explicit recompute call needed.
async function setPriorityFromContext(priorityId) {
    closeTicketContextMenu();
    // Fan out to the whole selection when the menu was opened on one. The
    // single-ticket path below is left EXACTLY as it was — this module is the
    // busiest in the product and a bulk feature is no reason to rewrite the
    // one-ticket flow every analyst uses all day.
    if (ctxActsOnSelection) return bulkSetField({ priority_id: priorityId === '' ? null : priorityId }, t('tickets.bulk.label_priority'));
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = priorityId !== '' ? ticketPriorities.find(p => p.id == priorityId) : null;
    const newLabel = newRow ? newRow.name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                priority_id: priorityId === '' ? null : priorityId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting priority: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldRow   = (currentEmail && currentEmail.ticket_id == targetId)
                ? ticketPriorities.find(p => p.id == currentEmail.priority_id)
                : null;
            const oldLabel = oldRow ? oldRow.name : '';
            await logAudit(targetId, 'Priority', oldLabel, newLabel);
        } catch (e) { /* audit is best-effort */ }
        // Keep the open ticket's toolbar in sync when the same ticket is in the reading pane.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.priority_id = priorityId === '' ? null : Number(priorityId);
            currentEmail.priority    = newLabel;
            const sel = document.getElementById('prioritySelect');
            if (sel) sel.value = priorityId === '' ? '' : String(priorityId);
            updatePropertiesSummary();
        }
        loadEmails();
    } catch (error) {
        console.error('Error setting priority from context:', error);
        showToast('Failed to set priority', 'error');
    }
}

// Build the Set-department submenu HTML from the analyst's team departments
// (same `departments` lookup as the in-panel Department dropdown). department_id
// is nullable, so the first row is a "(no department)" option that clears it.
function populateContextDepartmentSubmenu() {
    const sub = document.getElementById('ctxDepartmentSubmenu');
    if (!sub) return;
    if (!departments.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_departments')) + '</div>';
        return;
    }
    const currentDeptId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.department_id ?? null)
        : undefined;
    const onOpenTicket = currentEmail && currentEmail.ticket_id == ctxTargetTicketId;
    const clearRow = `<div class="ticket-context-submenu-item" data-department-id="" onclick="setDepartmentFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_department'))}</span>
        ${(currentDeptId === null || currentDeptId === undefined) && onOpenTicket ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + departments.map(d => {
        const isCurrent = (currentDeptId != null && d.id == currentDeptId);
        return `<div class="ticket-context-submenu-item" data-department-id="${d.id}" onclick="setDepartmentFromContext(${d.id})">
            <span class="ctx-status-swatch" style="background:#e5e7eb; border:none;"></span><span class="ctx-status-name">${escapeHtml(d.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's department from the right-click menu. Empty string clears it
// (department_id is nullable). Refreshes folder counts + the list because the
// inbox can be grouped by department.
async function setDepartmentFromContext(departmentId) {
    closeTicketContextMenu();
    if (ctxActsOnSelection) return bulkSetField({ department_id: departmentId === '' ? null : departmentId }, t('tickets.bulk.label_department'));
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = departmentId !== '' ? departments.find(d => d.id == departmentId) : null;
    const newLabel = newRow ? newRow.name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                department_id: departmentId === '' ? null : departmentId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting department: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldLabel = (currentEmail && currentEmail.ticket_id == targetId)
                ? (getDisplayName('department', currentEmail.department_id) || '')
                : '';
            await logAudit(targetId, 'Department', oldLabel, newLabel);
        } catch (e) { /* audit is best-effort */ }
        // Keep the open ticket's toolbar in sync when it's the same one.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.department_id = departmentId === '' ? null : Number(departmentId);
            const sel = document.getElementById('departmentSelect');
            if (sel) sel.value = departmentId === '' ? '' : String(departmentId);
            updatePropertiesSummary();
        }
        loadFolderCounts();
        loadEmails();
    } catch (error) {
        console.error('Error setting department from context:', error);
        showToast('Failed to set department', 'error');
    }
}

// Build the Move-to-company submenu (multi-company installs only; hidden at N=1).
// Lists the companies this analyst can access; the current one is ticked when
// right-clicking the ticket open in the reading pane.
function populateContextCompanySubmenu() {
    const parent = document.getElementById('ctxCompanyParent');
    const sub = document.getElementById('ctxCompanySubmenu');
    if (!parent || !sub) return;
    if (!isMultiCompany || !moveCompanies.length) {
        parent.style.display = 'none';
        return;
    }
    parent.style.display = '';
    const currentTid = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.tenant_id ?? (moveCompanies.find(c => c.is_default) || {}).id)
        : undefined;
    sub.innerHTML = moveCompanies.map(c => {
        const isCurrent = (currentTid != null && String(c.id) === String(currentTid));
        return `<div class="ticket-context-submenu-item" data-tenant-id="${c.id}" onclick="moveToCompanyFromContext(${c.id})">
            <span class="ctx-status-swatch" style="background:#ede7f6; border:none;"></span><span class="ctx-status-name">${escapeHtml(c.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Move a ticket to another company from the right-click menu. The endpoint writes
// the audit entry server-side, so (unlike the other context actions) there's no
// client-side logAudit here.
async function moveToCompanyFromContext(tenantId) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    try {
        const res = await fetch(API_BASE + 'move_ticket_to_company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: targetId, tenant_id: tenantId })
        });
        const data = await res.json();
        if (!data.success) {
            showToast('Could not move ticket: ' + (data.error || 'unknown error'), 'error');
            return;
        }
        showToast(data.message || 'Ticket moved', 'success');
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.tenant_id = tenantId;
            selectEmail(currentEmail.id); // refresh the open ticket's company field + banner
        }
        loadFolderCounts();
        loadEmails();
    } catch (e) {
        showToast('Failed to move ticket', 'error');
    }
}

// Build the Set-type submenu HTML from the active ticket_types lookup (same
// `ticketTypes` source as the in-panel Type dropdown). ticket_type_id is
// nullable, so the first row is a "(no type)" option that clears it.
function populateContextTypeSubmenu() {
    const sub = document.getElementById('ctxTypeSubmenu');
    if (!sub) return;
    if (!ticketTypes.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_types')) + '</div>';
        return;
    }
    const currentTypeId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.ticket_type_id ?? null)
        : undefined;
    const onOpenTicket = currentEmail && currentEmail.ticket_id == ctxTargetTicketId;
    const clearRow = `<div class="ticket-context-submenu-item" data-type-id="" onclick="setTypeFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_type'))}</span>
        ${(currentTypeId === null || currentTypeId === undefined) && onOpenTicket ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + ticketTypes.map(tt => {
        const isCurrent = (currentTypeId != null && tt.id == currentTypeId);
        return `<div class="ticket-context-submenu-item" data-type-id="${tt.id}" onclick="setTypeFromContext(${tt.id})">
            <span class="ctx-status-swatch" style="background:#e5e7eb; border:none;"></span><span class="ctx-status-name">${escapeHtml(tt.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's type from the right-click menu. Empty string clears it
// (ticket_type_id is nullable).
async function setTypeFromContext(typeId) {
    closeTicketContextMenu();
    if (ctxActsOnSelection) return bulkSetField({ ticket_type_id: typeId === '' ? null : typeId }, t('tickets.bulk.label_type'));
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = typeId !== '' ? ticketTypes.find(t => t.id == typeId) : null;
    const newLabel = newRow ? newRow.name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                ticket_type_id: typeId === '' ? null : typeId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting type: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldLabel = (currentEmail && currentEmail.ticket_id == targetId)
                ? (getDisplayName('ticket_type', currentEmail.ticket_type_id) || '')
                : '';
            await logAudit(targetId, 'Ticket Type', oldLabel, newLabel);
        } catch (e) { /* audit is best-effort */ }
        // Keep the open ticket's toolbar in sync when it's the same one.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.ticket_type_id = typeId === '' ? null : Number(typeId);
            const sel = document.getElementById('ticketTypeSelect');
            if (sel) sel.value = typeId === '' ? '' : String(typeId);
            updatePropertiesSummary();
        }
        loadEmails();
    } catch (error) {
        console.error('Error setting type from context:', error);
        showToast('Failed to set type', 'error');
    }
}

// Build the Assign-to submenu HTML from the loaded analysts list. Picking
// an analyst sets both assigned_analyst_id and owner_id (mirrors the
// drag-to-analyst-folder behaviour in assign_ticket.php). The first row
// is an "Unassigned" option that clears both fields.
function populateContextAssigneeSubmenu() {
    const sub = document.getElementById('ctxAssigneeSubmenu');
    if (!sub) return;
    if (!analysts.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_analysts')) + '</div>';
        return;
    }
    // Use owner_id as the "currently assigned" indicator since drag-to-folder
    // keeps owner_id and assigned_analyst_id in sync; the in-panel Owner
    // dropdown is also the canonical assignment view.
    const currentOwnerId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.owner_id ?? null)
        : undefined;
    const clearRow = `<div class="ticket-context-submenu-item" data-analyst-id="" onclick="setAssigneeFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_assignee'))}</span>
        ${(currentOwnerId === null) ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + analysts.map(a => {
        const isCurrent = (currentOwnerId != null && a.id == currentOwnerId);
        // Use the first letter of the name as a colourless initial chip — keeps
        // the row visually aligned with status / priority swatches without
        // inventing a colour per analyst.
        const initial = (a.full_name || '').charAt(0).toUpperCase() || '?';
        const initialChip = `<span class="ctx-status-swatch" style="background:#e5e7eb; color:#374151; font-size:9px; font-weight:600; display:inline-flex; align-items:center; justify-content:center; border:none;">${escapeHtml(initial)}</span>`;
        return `<div class="ticket-context-submenu-item" data-analyst-id="${a.id}" onclick="setAssigneeFromContext(${a.id})">
            ${initialChip}<span class="ctx-status-name">${escapeHtml(a.full_name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's assignee from the right-click menu. Empty string = unassign.
// Sends assigned_analyst_id to assign_ticket.php, which sets both
// assigned_analyst_id and owner_id (same behaviour as drag-to-folder).
async function setAssigneeFromContext(analystId) {
    closeTicketContextMenu();
    if (ctxActsOnSelection) return bulkSetField({ assigned_analyst_id: analystId === '' ? null : analystId }, t('tickets.bulk.label_assignee'));
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = analystId !== '' ? analysts.find(a => a.id == analystId) : null;
    const newLabel = newRow ? newRow.full_name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                assigned_analyst_id: analystId === '' ? null : analystId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error assigning ticket: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldRow   = (currentEmail && currentEmail.ticket_id == targetId)
                ? analysts.find(a => a.id == currentEmail.owner_id)
                : null;
            const oldLabel = oldRow ? oldRow.full_name : '';
            await logAudit(targetId, 'Owner', oldLabel, newLabel);
        } catch (e) { /* audit best-effort */ }
        // Keep the open ticket's toolbar in sync when it's the same one.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.owner_id = analystId === '' ? null : Number(analystId);
            currentEmail.assigned_analyst_id = analystId === '' ? null : Number(analystId);
            const sel = document.getElementById('ownerSelect');
            if (sel) sel.value = analystId === '' ? '' : String(analystId);
            updatePropertiesSummary();
        }
        loadFolderCounts();
        loadEmails();
    } catch (error) {
        console.error('Error assigning ticket from context:', error);
        showToast('Failed to assign ticket', 'error');
    }
}

// Set a ticket's status from the right-click menu — works on whichever ticket
// was right-clicked, even if a different ticket is open in the reading pane.
async function setStatusFromContext(statusName) {
    closeTicketContextMenu();
    if (ctxActsOnSelection) return bulkSetField({ status: statusName }, t('tickets.bulk.label_status'));
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                status: statusName
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting status: ' + (data.error || 'unknown'), 'error');
            return;
        }
        // Audit-trail entry mirrors the in-panel assignStatus() flow.
        try {
            const oldStatus = (currentEmail && currentEmail.ticket_id == targetId) ? (currentEmail.status || '') : '';
            await logAudit(targetId, 'Status', oldStatus, statusName);
        } catch (e) { /* audit is best-effort */ }
        // If the same ticket is open in the reading pane, keep its toolbar in sync.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.status = statusName;
            const sel = document.getElementById('statusSelect');
            if (sel) sel.value = statusName;
            updatePropertiesSummary();
        }
        loadFolderCounts();
        loadEmails();
    } catch (error) {
        console.error('Error setting status from context:', error);
        showToast('Failed to set status', 'error');
    }
}

function closeTicketContextMenu() {
    const menu = document.getElementById('ticketContextMenu');
    if (menu) menu.classList.remove('active');
}

// Right-click a ticket -> Move to trash (soft-delete the context-menu target).
async function contextMoveToTrash() {
    const id = ctxTargetTicketId;
    const ref = ctxTargetTicketRef;
    closeTicketContextMenu();
    if (!id) return;
    try {
        const res = await fetch(API_BASE + 'delete_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: id })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'failed');
        showToast(`${ref || 'Ticket'} → Trash`, 'success');
        clearReadingPaneIfTicket(id);
        loadFolderCounts();
        loadEmails();
    } catch (e) { showToast('Move to trash failed: ' + e.message, 'error'); }
}

// ===========================================================================
// Snoozing tickets (#933)
// ===========================================================================
//
// "I can't do anything with this until Thursday." Snoozing takes the ticket out
// of every folder and puts it in Snoozed until its time comes, so the morning
// queue is work rather than a list of things to re-decide you can't touch.
//
// The wake time is computed SERVER-SIDE from a preset key — the labels here are
// only labels. That keeps one definition of what "tomorrow morning" means (the
// analyst's zone, the install's wake hour) instead of two that drift, and means
// a stale page can't snooze a ticket to yesterday.
//
// Waking is the clock: a ticket is asleep while snoozed_until is in the future
// and awake the moment it isn't. Nothing has to run for it to come back.

// The presets, in the order they appear. `key` is what the server understands;
// `at` computes the same instant locally so the row can show what it means.
//
// `available` drops a preset whose name would stop being true. "This evening"
// after 18:00 can only mean tomorrow evening, and a row reading "This evening —
// Tomorrow 18:00" is a menu arguing with itself. Gmail hides its presets the
// same way, and "In 3 hours" already covers the rest of the night.
const SNOOZE_PRESETS = [
    { key: 'three_hours', label: 'tickets.snooze.three_hours', at: () => new Date(Date.now() + 3 * 3600 * 1000) },
    { key: 'tonight',     label: 'tickets.snooze.tonight',     at: () => atHourLocal(18, 0),
      available: () => new Date().getHours() < 18 },
    { key: 'tomorrow',    label: 'tickets.snooze.tomorrow',    at: () => atHourLocal(snoozeWakeHour(), 1) },
    { key: 'next_week',   label: 'tickets.snooze.next_week',   at: () => nextMondayLocal(snoozeWakeHour()) },
];

function availableSnoozePresets() {
    return SNOOZE_PRESETS.filter(p => !p.available || p.available());
}

function snoozeWakeHour() {
    const h = Number(window.SNOOZE_WAKE_HOUR);
    return (Number.isInteger(h) && h >= 0 && h <= 23) ? h : 9;
}

// A Date at `hour` today plus `addDays`. Mirrors the server's "tonight"/"tomorrow"
// arithmetic; "tonight" rolls forward a day when 18:00 has already gone.
function atHourLocal(hour, addDays) {
    const d = new Date();
    d.setDate(d.getDate() + (addDays || 0));
    d.setHours(hour, 0, 0, 0);
    if (!addDays && d <= new Date()) d.setDate(d.getDate() + 1);
    return d;
}

function nextMondayLocal(hour) {
    const d = new Date();
    // 1..7 days forward — never today, so "next week" can't mean "in ten minutes".
    const delta = ((8 - d.getDay()) % 7) || 7;
    d.setDate(d.getDate() + delta);
    d.setHours(hour, 0, 0, 0);
    return d;
}

// "Today 15:00" / "Tomorrow 09:00" / "Mon 4 Aug, 09:00" — in the analyst's zone.
// Deliberately always carries the time: "Thursday" alone leaves them wondering
// whether the ticket is back first thing or at the end of the day.
function formatWakeTime(value) {
    if (!value) return '';
    const d = (value instanceof Date) ? value : parseUTCDate(value);
    if (!d || isNaN(d.getTime())) return '';
    const time = fmtTime(d);
    const ymd = ymdInZone(d);
    const now = new Date();
    if (ymd === ymdInZone(now)) return t('tickets.snooze.today_at').replace('%s', time);
    if (ymd === ymdInZone(new Date(now.getTime() + 86400000))) return t('tickets.snooze.tomorrow_at').replace('%s', time);
    const day = fmtWeekday(d, true) + ' ' + fmtDayMonth(d);
    return `${day}, ${time}`;
}

// The small moon pill on a list row. Only rendered for a snooze still in the
// future — the endpoint already nulls expired ones, and this is the second belt.
/* ─────────────────────────────────────────────────────────────────────────────
 * Row display chips (discussion #61)
 *
 * What appears on each ticket row is per-analyst, resolved server-side and handed
 * to us as INBOX_ROW_DISPLAY — see includes/inbox_display.php. Every value has
 * already been validated against the registry there, but these functions still
 * whitelist before building a class name, because a renderer that trusts its
 * input is one stored value away from markup injection.
 *
 * ⚠️ Three placements, and the reason there are three:
 *   stripe → a bar on the LEFT EDGE. Costs no horizontal space, reads instantly
 *            down a queue, and cannot be confused with the SLA dot.
 *   block  → a small square top-RIGHT. Quiet; deliberately not top-left, where it
 *            would fight the unread marker.
 *   pill/dot → in the footer row beside the date, next to the SLA pill. The
 *            noisiest, and the only one that carries the actual word.
 *
 * The SLA indicator in that footer is ALREADY a red/amber/green dot. A priority
 * dot is therefore available but not the default: two traffic lights in one row,
 * meaning different things, is worse than one.
 * ───────────────────────────────────────────────────────────────────────────── */

const INBOX_STYLE_WHITELIST = ['off', 'stripe', 'pill', 'block', 'dot', 'name', 'initials'];

function inboxRowStyle(field) {
    const cfg = (typeof INBOX_ROW_DISPLAY === 'object' && INBOX_ROW_DISPLAY) ? INBOX_ROW_DISPLAY : {};
    const v = cfg[field];
    return INBOX_STYLE_WHITELIST.includes(v) ? v : 'off';
}

/** Is any field currently asking for this placement? Lets us skip empty containers. */
function inboxAnyStyleIs(placement) {
    return ['priority', 'status'].some(f => inboxRowStyle(f) === placement);
}

/**
 * "Ed Mozley" → "EM". Falls back to the first two characters of a single word,
 * so a mononym or a service account still renders something rather than a blank
 * chip that looks like a bug.
 */
function inboxInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '';
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** A safe CSS colour, or null. Anything unrecognised is dropped rather than emitted. */
function inboxSafeColour(value) {
    const v = String(value || '').trim();
    return /^#[0-9a-fA-F]{3,8}$/.test(v) ? v : null;
}

/** The left-edge stripes. Both priority and status may ask for one; they stack. */
function inboxRowStripes(email) {
    if (!inboxAnyStyleIs('stripe')) return '';
    const bars = [];
    if (inboxRowStyle('priority') === 'stripe') {
        const c = inboxSafeColour(email.priority_colour);
        if (c && email.priority) {
            bars.push(`<span class="email-stripe" style="background:${c}" title="${escapeHtml(email.priority)}"></span>`);
        }
    }
    if (inboxRowStyle('status') === 'stripe') {
        const c = inboxSafeColour(email.status_colour);
        if (c && email.status) {
            bars.push(`<span class="email-stripe" style="background:${c}" title="${escapeHtml(email.status)}"></span>`);
        }
    }
    return bars.length ? `<span class="email-stripes">${bars.join('')}</span>` : '';
}

/** The top-right blocks. Same stacking rule as stripes. */
function inboxRowBlocks(email) {
    if (!inboxAnyStyleIs('block')) return '';
    const blocks = [];
    if (inboxRowStyle('priority') === 'block') {
        const c = inboxSafeColour(email.priority_colour);
        if (c && email.priority) {
            blocks.push(`<span class="email-block" style="background:${c}" title="${escapeHtml(email.priority)}"></span>`);
        }
    }
    if (inboxRowStyle('status') === 'block') {
        const c = inboxSafeColour(email.status_colour);
        if (c && email.status) {
            blocks.push(`<span class="email-block" style="background:${c}" title="${escapeHtml(email.status)}"></span>`);
        }
    }
    return blocks.length ? `<span class="email-blocks">${blocks.join('')}</span>` : '';
}

/** Pills and dots for the footer row, plus the agent chip. */
function inboxRowChips(email) {
    const out = [];

    const colourField = (field, value, colour) => {
        const style = inboxRowStyle(field);
        if (style !== 'pill' && style !== 'dot') return;
        if (!value) return;
        const c = inboxSafeColour(colour);
        if (style === 'dot') {
            out.push(`<span class="email-chip-dot" style="background:${c || '#999'}" title="${escapeHtml(value)}"></span>`);
        } else {
            // Tint the pill from the same colour rather than inventing a palette.
            // The border carries the hue; the text stays readable on any theme.
            // ⚠️ The label is its own element so a narrow screen can hide it and
            // leave the dot — a pill degrades to its quietest form rather than
            // wrapping or being dropped entirely. See mobile.css.
            const border = c ? ` style="border-color:${c}"` : '';
            out.push(`<span class="email-chip-pill"${border} title="${escapeHtml(value)}">`
                   + (c ? `<span class="email-chip-dot" style="background:${c}"></span>` : '')
                   + `<span class="email-chip-label">${escapeHtml(value)}</span></span>`);
        }
    };

    colourField('priority', email.priority, email.priority_colour);
    colourField('status',   email.status,   email.status_colour);

    const agentStyle = inboxRowStyle('agent');
    if (agentStyle === 'name' || agentStyle === 'initials') {
        const name = email.assignee_name || '';
        if (name) {
            const label = agentStyle === 'initials' ? inboxInitials(name) : name;
            out.push(`<span class="email-chip-agent ${agentStyle === 'initials' ? 'is-initials' : ''}" `
                   + `title="${escapeHtml(name)}">${escapeHtml(label)}</span>`);
        }
    }

    return out.join('');
}

function snoozeRowPill(snoozedUntil, reason) {
    if (!snoozedUntil) return '';
    const d = parseUTCDate(snoozedUntil);
    if (!d || isNaN(d.getTime()) || d <= new Date()) return '';
    const when = formatWakeTime(d);
    const title = reason
        ? `${t('tickets.snooze.until').replace('%s', when)} — ${reason}`
        : t('tickets.snooze.until').replace('%s', when);
    return `<span class="email-snooze-pill" title="${escapeHtml(title)}">🌙 ${escapeHtml(when)}</span>`;
}

// Build the Snooze flyout. Rebuilt on every open so the labels carry live times —
// a menu built at page load would still be offering "Tomorrow · Wed 09:00" on
// Thursday, and an analyst reads the label, not the key behind it.
function populateContextSnoozeSubmenu() {
    const sub = document.getElementById('ctxSnoozeSubmenu');
    if (!sub) return;
    const rows = availableSnoozePresets().map(p => {
        const when = formatWakeTime(p.at());
        return `<div class="ticket-context-submenu-item" onclick="snoozeFromContext('${p.key}')">
            <span class="ctx-status-name">${escapeHtml(t(p.label))}</span>
            <span class="ctx-snooze-when">${escapeHtml(when)}</span>
        </div>`;
    }).join('');
    sub.innerHTML = rows +
        `<div class="ticket-context-submenu-item" onclick="openSnoozeModal()">
            <span class="ctx-status-name" style="font-style: italic;">${escapeHtml(t('tickets.snooze.pick'))}</span>
        </div>`;
}

// Is the context-menu target asleep? Read from the loaded list row, or from the
// open ticket when the menu was raised on the one in the reading pane.
function ctxTargetIsSnoozed() {
    const rec = emails.find(e => e.ticket_id == ctxTargetTicketId);
    if (rec && rec.snoozed_until) return true;
    return !!(currentEmail && currentEmail.ticket_id == ctxTargetTicketId && currentEmail.snooze);
}

// Which tickets does a snooze/wake act on? Same rule as every other context
// action: the whole selection when the menu was raised inside it, else the one row.
function ctxSnoozeTargets() {
    return ctxActsOnSelection ? selectedTicketIds() : (ctxTargetTicketId ? [ctxTargetTicketId] : []);
}

async function snoozeFromContext(preset, untilLocal, reason) {
    const ids = ctxSnoozeTargets();
    closeTicketContextMenu();
    if (!ids.length) return;
    try {
        const res = await fetch(API_BASE + 'snooze_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_ids: ids,
                preset: preset,
                until_local: untilLocal || null,
                reason: reason || ''
            })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'failed');

        const when = formatWakeTime(data.snoozed_until);
        showToast(
            data.snoozed > 1
                ? t('tickets.snooze.toast_many').replace('%d', String(data.snoozed)).replace('%s', when)
                : t('tickets.snooze.toast_one').replace('%s', when),
            'success'
        );
        // The ticket has left the current folder, so the reading pane would be
        // showing something the list no longer holds.
        ids.forEach(id => clearReadingPaneIfTicket(id));
        clearMultiSelection({ repaint: false });
        loadFolderCounts();
        loadEmails();
    } catch (e) {
        showToast(t('tickets.snooze.failed') + ': ' + e.message, 'error');
    }
}

async function wakeFromContext() {
    const ids = ctxSnoozeTargets();
    closeTicketContextMenu();
    await wakeTickets(ids);
}

// Shared by the context menu and the reading-pane banner button.
async function wakeTickets(ids) {
    if (!ids || !ids.length) return;
    try {
        const res = await fetch(API_BASE + 'wake_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_ids: ids })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'failed');
        // Report what actually happened. "Woke 0 tickets" is a real outcome when
        // the snooze expired a second earlier, and claiming otherwise is a lie.
        if (!data.woken) { showToast(t('tickets.snooze.wake_none'), 'info'); }
        else {
            showToast(
                data.woken > 1
                    ? t('tickets.snooze.wake_many').replace('%d', String(data.woken))
                    : t('tickets.snooze.wake_one'),
                'success'
            );
        }
        const openId = (currentEmail && ids.includes(Number(currentEmail.ticket_id))) ? currentEmail.id : null;
        loadFolderCounts();
        await loadEmails();
        if (openId) selectEmail(openId);
    } catch (e) {
        showToast(t('tickets.snooze.wake_failed') + ': ' + e.message, 'error');
    }
}

// --- Snooze until a specific time -----------------------------------------
let snoozeModalTargets = [];

function openSnoozeModal() {
    snoozeModalTargets = ctxSnoozeTargets();
    closeTicketContextMenu();
    if (!snoozeModalTargets.length) return;

    document.getElementById('snoozeTicketRef').textContent = snoozeModalTargets.length > 1
        ? t('tickets.bulk.n_selected').replace('%d', String(snoozeModalTargets.length))
        : ctxTargetTicketRef;

    // Default to tomorrow at the install's wake hour — the most likely answer,
    // and it means the dialog opens on a valid future time rather than on today
    // at 00:00, which the server would (correctly) refuse.
    const d = atHourLocal(snoozeWakeHour(), 1);
    document.getElementById('snoozeDate').value = ymdInZone(d);
    document.getElementById('snoozeTime').value =
        String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    document.getElementById('snoozeReason').value = '';
    document.getElementById('snoozeSaveBtn').disabled = false;
    document.getElementById('snoozeModal').classList.add('active');
    setTimeout(() => document.getElementById('snoozeDate').focus(), 50);
}

function closeSnoozeModal() {
    document.getElementById('snoozeModal').classList.remove('active');
    snoozeModalTargets = [];
}

async function saveCustomSnooze() {
    const date = document.getElementById('snoozeDate').value;
    const time = document.getElementById('snoozeTime').value;
    const reason = document.getElementById('snoozeReason').value.trim();
    if (!date || !time) { showToast(t('tickets.snooze.need_datetime'), 'error'); return; }

    const ids = snoozeModalTargets.slice();
    const btn = document.getElementById('snoozeSaveBtn');
    btn.disabled = true;
    closeSnoozeModal();
    // Reuse the one write path. The ids are passed through ctxSnoozeTargets() by
    // way of the module-level context, which the modal has not disturbed.
    ctxTargetTicketId = ids.length === 1 ? ids[0] : ctxTargetTicketId;
    await snoozeFromContext('custom', `${date} ${time}`, reason);
    btn.disabled = false;
}

// Reading-pane banner on a sleeping ticket. Quiet like the split banners rather
// than loud like merged-away: nothing is wrong here, the ticket is just resting,
// and the one thing an analyst wants is the way out of it.
function buildSnoozeBanner(email) {
    const s = email && email.snooze;
    if (!s || !s.snoozed_until) return '';
    const when = formatWakeTime(s.snoozed_until);
    const who = s.snoozed_by_name ? t('tickets.snooze.by').replace('%s', s.snoozed_by_name) : '';
    const why = s.reason ? ` — ${escapeHtml(s.reason)}` : '';
    return `
        <div class="merge-banner merge-banner-snooze">
            <span class="merge-banner-icon">🌙</span>
            <div class="merge-banner-text">
                <strong>${escapeHtml(t('tickets.snooze.until').replace('%s', when))}</strong>${why}
                <div>${escapeHtml(who)} ${escapeHtml(t('tickets.snooze.banner_hint'))}</div>
            </div>
            <button type="button" class="btn btn-secondary merge-banner-btn"
                    onclick="wakeTickets([${Number(email.ticket_id)}])">${escapeHtml(t('tickets.context.wake'))}</button>
        </div>`;
}

// ===========================================================================
// Collision detection — who else is in this ticket (#934)
// ===========================================================================
//
// Two analysts answering the same customer is the oldest service-desk annoyance
// there is, and until now nothing said a word about it. The open ticket sends a
// heartbeat; the same request comes back with everyone else who is here.
//
// Presence is a HEARTBEAT, not a session: a row counts only while it is fresh,
// so a closed tab, a crashed browser or a dead network clears itself after one
// stale window with nothing having to run. The explicit leave below is only an
// optimisation to make that instant.
//
// It WARNS, it never blocks. Two people may legitimately both need to write.

const PRESENCE_BEAT_MS = 10000;   // three beats inside the server's 30s stale window
let presenceTicketId = null;      // the ticket we are currently announcing on
let presenceTimer    = null;
let presenceOthers   = [];        // last known others, for the composer warning
let presenceComposing = false;    // is OUR reply/forward/note composer open?

/** Start (or move) the heartbeat to a ticket. Safe to call repeatedly. */
function startPresence(ticketId) {
    ticketId = Number(ticketId) || 0;
    if (!ticketId) return;
    if (presenceTicketId === ticketId) return;      // already announcing there

    // Moving between tickets: stop announcing on the old one straight away, so a
    // colleague doesn't see us lingering on a ticket we have left.
    if (presenceTicketId) leavePresence(presenceTicketId);

    presenceTicketId = ticketId;
    presenceOthers = [];
    presenceComposing = false;
    renderPresenceStrip();

    beatPresence();
    if (presenceTimer) clearInterval(presenceTimer);
    presenceTimer = setInterval(beatPresence, PRESENCE_BEAT_MS);
}

/** Stop announcing entirely (the reading pane emptied). */
function stopPresence() {
    if (presenceTimer) { clearInterval(presenceTimer); presenceTimer = null; }
    if (presenceTicketId) leavePresence(presenceTicketId);
    presenceTicketId = null;
    presenceOthers = [];
    presenceComposing = false;
    renderPresenceStrip();
}

async function beatPresence() {
    const id = presenceTicketId;
    if (!id) return;
    // A hidden tab is not "here" — a background tab left open all afternoon
    // would otherwise keep announcing a colleague who walked away hours ago.
    // Skipping the beat lets them go stale naturally, and the next visible beat
    // brings them straight back.
    if (document.hidden) return;
    try {
        const res = await fetch(API_BASE + 'ticket_presence.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: id, composing: presenceComposing })
        });
        const data = await res.json();
        // The reply may land after the analyst has moved on; painting it would
        // put the previous ticket's faces on the current one.
        if (!data.success || presenceTicketId !== id) return;
        presenceOthers = data.others || [];
        renderPresenceStrip();
        renderComposerCollisionWarning();
    } catch (e) {
        // A failed heartbeat is not worth a toast. The strip simply keeps its
        // last state and the next beat corrects it.
    }
}

/** Fire-and-forget leave. Uses sendBeacon during teardown, when fetch is unreliable. */
function leavePresence(ticketId) {
    const body = JSON.stringify({ ticket_id: Number(ticketId) || 0, leave: true });
    try {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(API_BASE + 'ticket_presence.php', new Blob([body], { type: 'application/json' }));
            return;
        }
    } catch (e) { /* fall through to fetch */ }
    try {
        fetch(API_BASE + 'ticket_presence.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: body, keepalive: true
        });
    } catch (e) { /* nothing depends on this arriving */ }
}

/**
 * Tell the server whether our composer is open.
 *
 * Beats immediately rather than waiting for the next tick: "is writing a reply"
 * is only useful if it appears while they are still writing it.
 */
function setPresenceComposing(on) {
    const next = !!on;
    if (next === presenceComposing) return;
    presenceComposing = next;
    beatPresence();
}

/**
 * Join names the way a person would: "A", "A and B", "A, B and C".
 * The conjunction is translated — t() returns the KEY when a string is missing,
 * so it must be a real one rather than a `|| 'and'` fallback that would render
 * the literal text "common.and" on screen.
 */
function joinNames(names) {
    const and = t('tickets.presence.and');
    if (names.length <= 1) return names[0] || '';
    if (names.length === 2) return `${names[0]} ${and} ${names[1]}`;
    return `${names.slice(0, -1).join(', ')} ${and} ${names[names.length - 1]}`;
}

/**
 * Paint the strip. Two states, because they deserve different weight: someone
 * merely having the ticket open is information, someone WRITING is the moment
 * your work becomes duplicated effort.
 */
function renderPresenceStrip() {
    const el = document.getElementById('presenceStrip');
    if (!el) return;
    if (!presenceOthers.length) { el.hidden = true; el.innerHTML = ''; return; }

    const composing = presenceOthers.filter(p => p.composing);
    const viewing   = presenceOthers.filter(p => !p.composing);

    const faces = presenceOthers.map(p => `
        <span class="presence-face ${p.composing ? 'is-composing' : ''}" title="${escapeHtml(p.name)}">
            ${escapeHtml(p.initials)}
        </span>`).join('');

    let message;
    if (composing.length) {
        const names = joinNames(composing.map(p => p.name));
        message = `<strong>${escapeHtml(
            composing.length > 1
                ? t('tickets.presence.are_replying').replace('%s', names)
                : t('tickets.presence.is_replying').replace('%s', names)
        )}</strong>`;
        if (viewing.length) {
            message += ' ' + escapeHtml(
                viewing.length > 1
                    ? t('tickets.presence.also_viewing_many').replace('%s', joinNames(viewing.map(p => p.name)))
                    : t('tickets.presence.also_viewing').replace('%s', viewing[0].name)
            );
        }
    } else {
        const names = joinNames(viewing.map(p => p.name));
        message = escapeHtml(
            viewing.length > 1
                ? t('tickets.presence.also_viewing_many').replace('%s', names)
                : t('tickets.presence.also_viewing').replace('%s', names)
        );
    }

    el.className = 'presence-strip' + (composing.length ? ' presence-strip-composing' : '');
    el.innerHTML = `<span class="presence-faces">${faces}</span><span class="presence-text">${message}</span>`;
    el.hidden = false;
}

/**
 * The warning inside our own composer.
 *
 * Deliberately a line of text above the editor and NOT a block: two analysts may
 * genuinely both need to write (an escalation and a holding reply), so this is a
 * "did you know" and never a refusal. It appears and disappears live, because
 * the person you would collide with may start or stop typing while you draft.
 */
function renderComposerCollisionWarning() {
    const slot = document.getElementById('composerCollisionWarning');
    if (!slot) return;
    const composing = presenceOthers.filter(p => p.composing);
    if (!composing.length) { slot.hidden = true; slot.innerHTML = ''; return; }
    const names = joinNames(composing.map(p => p.name));
    slot.innerHTML = `⚠️ ${escapeHtml(
        composing.length > 1
            ? t('tickets.presence.warn_many').replace('%s', names)
            : t('tickets.presence.warn_one').replace('%s', names)
    )}`;
    slot.hidden = false;
}

// Leave properly when the page goes away. `pagehide` fires where `unload`
// doesn't (bfcache, mobile Safari), and `visibilitychange` covers the tab being
// hidden — the beat already skips hidden tabs, this just makes it immediate.
window.addEventListener('pagehide', () => { if (presenceTicketId) leavePresence(presenceTicketId); });
document.addEventListener('visibilitychange', () => {
    if (document.hidden) { if (presenceTicketId) leavePresence(presenceTicketId); }
    else { beatPresence(); }
});

// ===== Trash folder context menu (Empty trash) =====
function openTrashContextMenu(event) {
    event.preventDefault();
    const menu = document.getElementById('trashContextMenu');
    if (!menu) return;
    menu.classList.add('active');
    const rect = menu.getBoundingClientRect();
    let x = event.clientX, y = event.clientY;
    if (x + rect.width  > window.innerWidth)  x = window.innerWidth  - rect.width  - 4;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 4;
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}
function closeTrashContextMenu() {
    const m = document.getElementById('trashContextMenu');
    if (m) m.classList.remove('active');
}
async function emptyTrash() {
    closeTrashContextMenu();
    const n = folderCounts.trash_count || 0;
    if (n === 0) { showToast('Trash is already empty', 'info'); return; }
    if (!(await showConfirm({
        title: 'Empty trash',
        message: `Permanently delete all ${n} ticket(s) in the trash, including their emails, attachments and notes? This cannot be undone.`,
        okLabel: 'Empty trash', okClass: 'danger'
    }))) return;
    try {
        const res = await fetch(API_BASE + 'empty_trash.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}'
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'failed');
        showToast(`Trash emptied — ${data.deleted} ticket(s) permanently deleted`, 'success');
        currentEmail = null;
        selectedEmailId = null;
        document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';
        loadFolderCounts();
        if (currentFilter.type === 'trash') loadEmails();
    } catch (e) { showToast('Empty trash failed: ' + e.message, 'error'); }
}

/**
 * Shift+click in the ticket list must not smear the browser's text selection
 * across the rows — and in Edge that selection also pops the "mini menu"
 * toolbar, so the two symptoms share one cause. The selection begins on
 * MOUSEDOWN, which is why the `.multi-selecting` class (applied on click) can't
 * prevent the first one; cancelling the default here does. Reported by Ed
 * against the assets list, fixed in both.
 */
document.addEventListener('mousedown', function (e) {
    if (!e.shiftKey) return;
    if (e.target.closest && e.target.closest('#emailList')) e.preventDefault();
});

// Outside click + Escape close the menus
document.addEventListener('mousedown', function (e) {
    const menu = document.getElementById('ticketContextMenu');
    if (menu && menu.classList.contains('active') && !menu.contains(e.target)) closeTicketContextMenu();
    const tmenu = document.getElementById('trashContextMenu');
    if (tmenu && tmenu.classList.contains('active') && !tmenu.contains(e.target)) closeTrashContextMenu();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeTicketContextMenu(); closeTrashContextMenu(); }
});
// Right-clicking a different row should reopen, not stack
window.addEventListener('blur', closeTicketContextMenu);
window.addEventListener('scroll', closeTicketContextMenu, true);

/* --- Context menu action: Link CMDB object --- */
/**
 * Right-click → Link to task (discussion #83).
 *
 * The other link items open a modal of their own. This one deliberately does
 * not: the task picker already exists on the reading pane's Links strip, and a
 * second copy of it in a modal is the "same question answered in two places"
 * that GH #77 was made of. So it opens the ticket you right-clicked — the one
 * you are about to work on anyway — and puts you in front of the real picker.
 */
async function openContextLinkTask() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const id = ctxTargetTicketId;
    await loadTicketById(id);
    openLinkTaskPicker(id);
}

function openContextLinkCmdb() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    document.getElementById('ctxCmdbTicketRef').textContent = ctxTargetTicketRef;
    document.getElementById('ctxCmdbSearchInput').value = '';
    document.getElementById('ctxCmdbResults').innerHTML = '';
    ctxCmdbSessionCount = 0;
    document.getElementById('ctxCmdbSessionLog').textContent = 'None yet — pick from the search results above.';
    document.getElementById('ctxCmdbModal').classList.add('active');
    setTimeout(() => document.getElementById('ctxCmdbSearchInput').focus(), 50);
}

function closeContextCmdbModal() {
    document.getElementById('ctxCmdbModal').classList.remove('active');
    // If we linked anything and the affected ticket is the one currently open
    // in the reading pane, refresh its CMDB-objects list so the UI matches.
    if (ctxCmdbSessionCount > 0 && currentEmail && parseInt(currentEmail.ticket_id, 10) === parseInt(ctxTargetTicketId, 10)) {
        loadCmdbObjects(currentEmail.ticket_id);
    }
}

// Wire search-as-you-type once
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('ctxCmdbSearchInput');
    if (!input) return;
    input.addEventListener('input', function () {
        const q = input.value.trim();
        const results = document.getElementById('ctxCmdbResults');
        if (ctxCmdbAcTimer) clearTimeout(ctxCmdbAcTimer);
        if (q === '') { results.innerHTML = ''; return; }
        ctxCmdbAcTimer = setTimeout(async () => {
            try {
                const res = await fetch('../api/cmdb/search_objects.php?q=' + encodeURIComponent(q));
                const data = await res.json();
                const rows = data.success ? (data.results || []) : [];
                if (rows.length === 0) {
                    results.innerHTML = '<div class="ctx-cmdb-result" style="cursor:default;color:#999;font-style:italic;">No matches.</div>';
                    return;
                }
                results.innerHTML = rows.map(r => `
                    <div class="ctx-cmdb-result" data-id="${r.id}" data-name="${escapeHtml(r.name)}">
                        <span class="ctx-cmdb-result-name">${escapeHtml(r.name)}</span>
                        <span class="ctx-cmdb-result-class">${escapeHtml(r.class_name)}</span>
                    </div>
                `).join('');
                results.querySelectorAll('.ctx-cmdb-result[data-id]').forEach(el => {
                    el.addEventListener('click', () => linkContextCmdbObject(parseInt(el.dataset.id, 10), el.dataset.name));
                });
            } catch (e) {
                results.innerHTML = '<div class="ctx-cmdb-result" style="cursor:default;color:#c62828;">Search failed.</div>';
            }
        }, 200);
    });
});

async function linkContextCmdbObject(objectId, objectName) {
    if (!ctxTargetTicketId) return;
    try {
        const res = await fetch('../api/tickets/save_ticket_cmdb_object.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ctxTargetTicketId, cmdb_object_id: objectId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Link failed');

        ctxCmdbSessionCount++;
        const logEl = document.getElementById('ctxCmdbSessionLog');
        if (data.already_linked) {
            showToast(objectName + ' is already linked', 'error');
        } else {
            showToast('Linked ' + objectName, 'success');
            const line = document.createElement('div');
            line.textContent = '✓ ' + objectName;
            line.style.color = '#16a34a';
            if (ctxCmdbSessionCount === 1) logEl.innerHTML = '';
            logEl.appendChild(line);
        }
        // Clear input for the next pick — keeps the modal open for multi-link
        const input = document.getElementById('ctxCmdbSearchInput');
        input.value = '';
        input.focus();
        document.getElementById('ctxCmdbResults').innerHTML = '';
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

/* --- Context menu action: Record time --- */
// Schedule work on the right-clicked ticket, which may not be the one open in
// the reading pane. The row in `emails` carries the schedule columns, so the
// modal prefills what this ticket is already set to rather than the open one's.
function openContextSchedule() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    openScheduleModal(
        ctxTargetTicketId,
        ctxTargetTicketRef,
        emails.find(e => e.ticket_id == ctxTargetTicketId) || {}
    );
}

function openContextRecordTime() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    document.getElementById('ctxTimeTicketRef').textContent = ctxTargetTicketRef;
    document.getElementById('ctxTimeMinutes').value = '';
    document.getElementById('ctxTimeNotes').value = '';
    // Default the datetime-local field to "now" in the analyst's DISPLAY zone,
    // not the browser's (GH #116). The list of entries right below this modal
    // renders in USER_TIMEZONE, so an analyst in London set to Europe/Vienna
    // must be offered 19:03, not their laptop's 18:03 — otherwise the row they
    // are about to create disagrees with the time they were shown when typing.
    document.getElementById('ctxTimeWhen').value = nowForInput();
    document.getElementById('ctxTimeModal').classList.add('active');
    setTimeout(() => document.getElementById('ctxTimeMinutes').focus(), 50);
}

function closeContextTimeModal() {
    document.getElementById('ctxTimeModal').classList.remove('active');
}

async function saveContextTimeEntry() {
    if (!ctxTargetTicketId) return;
    const minutes = parseInt(document.getElementById('ctxTimeMinutes').value, 10);
    const notes   = document.getElementById('ctxTimeNotes').value.trim();
    const when    = document.getElementById('ctxTimeWhen').value;

    if (!minutes || minutes <= 0) {
        showToast('Enter the number of minutes spent', 'error');
        return;
    }

    try {
        const res = await fetch('../api/tickets/save_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: ctxTargetTicketId,
                time_spent_minutes: minutes,
                notes: notes,
                // Convert the picked wall clock to a UTC instant before sending
                // (GH #116). `entry_datetime` is stored UTC and read back
                // through parseUTCDate; posting the raw input value hands the
                // server a zone-less string it can only read as UTC, landing
                // the entry a whole UTC offset out.
                entry_datetime: inputToUTC(when)
            })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');
        showToast('Logged ' + minutes + 'm on ' + ctxTargetTicketRef, 'success');
        closeContextTimeModal();
        // If the affected ticket is currently open in the reading pane,
        // refresh its time-entries list so the new row appears.
        if (currentEmail && parseInt(currentEmail.ticket_id, 10) === parseInt(ctxTargetTicketId, 10)) {
            loadTimeEntries(currentEmail.ticket_id);
        }
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

/* --- SLA panel in the reading pane ---------------------------------------
 * Fetches /api/tickets/get_ticket_sla.php and renders a small status panel
 * showing response + resolution targets, elapsed / remaining / percent, and
 * a green/amber/red colour-coded badge per target. Hides itself silently if
 * SLA is disabled for the ticket (no priority, no target set, ticket pre-dates
 * cutoff, SLA disabled globally) — so the section just doesn't appear and
 * doesn't bother the analyst with "SLA disabled for this ticket" noise.
 */
async function loadSlaState(ticketId) {
    const container = document.getElementById('slaContainer');
    if (!container) return;
    container.innerHTML = '';
    try {
        const res = await fetch(API_BASE + 'get_ticket_sla.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success || !data.sla || !data.sla.enabled) return; // silent hide
        renderSlaPanel(data.sla);
    } catch (e) {
        console.error('SLA load failed:', e);
    }
}

function renderSlaPanel(sla) {
    const container = document.getElementById('slaContainer');
    if (!container) return;
    if (!sla.response && !sla.resolution) return;

    const fmt = (mins) => {
        if (mins === null || mins === undefined) return '—';
        const n = Math.abs(mins);
        const sign = mins < 0 ? '-' : '';
        if (n < 60) return sign + n + 'm';
        const h = Math.floor(n / 60), r = n % 60;
        return sign + (r ? `${h}h ${r}m` : `${h}h`);
    };

    const renderRow = (label, target) => {
        if (!target) return '';
        const achieved = target.achieved_at !== null;
        // Colour: green if achieved (clock stopped) or < 80%; amber 80-100%; red > 100%
        let cls = 'sla-ok';
        let badge = 'On track';
        if (achieved) {
            cls = target.breached ? 'sla-breached' : 'sla-achieved';
            badge = target.breached ? 'Breached on response' : 'Achieved';
        } else if (target.breached) {
            cls = 'sla-breached';
            badge = 'Breached';
        } else if (target.percent >= 80) {
            cls = 'sla-warning';
            badge = 'Approaching breach';
        }
        const remainingLabel = achieved
            ? `Achieved in ${fmt(target.achieved_minutes)}`
            : (target.breached
                ? `Over by ${fmt(Math.abs(target.remaining_minutes))}`
                : `${fmt(target.remaining_minutes)} remaining`);
        return `
            <div class="sla-row ${cls}">
                <div class="sla-row-head">
                    <span class="sla-row-label">${escapeHtml(label)}</span>
                    <span class="sla-row-badge">${escapeHtml(badge)}</span>
                </div>
                <div class="sla-bar"><div class="sla-bar-fill" style="width: ${Math.min(100, target.percent)}%;"></div></div>
                <div class="sla-row-meta">
                    Target ${fmt(target.target_minutes)} &middot; Elapsed ${fmt(target.elapsed_minutes)} &middot; ${remainingLabel}
                </div>
            </div>
        `;
    };

    container.innerHTML = `
        <div class="sla-section">
            <div class="sla-section-header">SLA</div>
            ${renderRow('Response', sla.response)}
            ${renderRow('Resolution', sla.resolution)}
        </div>
    `;
}

/* ====================================================================== *
 * Write this up — the Knowledge assistant, from the ticket side
 *
 * The Tickets module owns the MOMENT (an analyst has just solved something and
 * it is fresh in their head); the Knowledge module owns the JUDGEMENT (is there
 * an article here, and is it worth having). Everything below is a thin client
 * for api/knowledge/writeup_stream.php — no prompt, no policy and no opinion
 * about what makes a good article lives on this side.
 *
 * ⚠️ The button deliberately only appears on a CLOSED ticket. Offering it
 * mid-conversation invites a write-up of a problem nobody has finished solving,
 * which is exactly the kind of half-true article that teaches people to stop
 * trusting the knowledge base.
 * ====================================================================== */

/** Is this ticket in a status the admin has marked as closed? */
function ticketIsClosed(email) {
    if (!email || !email.status) return false;
    const s = ticketStatuses.find(x => x.name === email.status);
    return !!(s && s.is_closed);
}

function buildWriteUpButton(email) {
    if (!window.KB_WRITEUP_ENABLED || !ticketIsClosed(email)) return '';
    return `
            <button class="action-btn" onclick="openWriteUpModal()" title="Turn this into a knowledge base article">
                <span class="action-btn-icon">📖</span>
                <span>Write up</span>
            </button>`;
}

let wuDraftHtml = '';
let wuTicketId = 0;

function openWriteUpModal() {
    if (!currentEmail) return;
    wuTicketId = currentEmail.ticket_id || currentEmail.id;
    wuDraftHtml = '';

    let modal = document.getElementById('writeUpModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'writeUpModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-content" style="max-width:820px;">
                <div class="modal-header">
                    <h2>Write this up</h2>
                    <button class="modal-close" onclick="closeWriteUpModal()">&times;</button>
                </div>
                <div class="modal-body" id="wuBody" style="max-height:60vh;overflow-y:auto;">
                    <div id="wuStatus" style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Reading the ticket…</div>
                    <div id="wuContent"></div>
                </div>
                <div class="modal-footer" id="wuFoot"></div>
            </div>`;
        document.body.appendChild(modal);
    }
    document.getElementById('wuStatus').textContent = 'Reading the ticket…';
    document.getElementById('wuContent').innerHTML = '';
    document.getElementById('wuFoot').innerHTML = '';
    modal.style.display = 'flex';

    wuStream({ ticket_id: wuTicketId });
}

function closeWriteUpModal() {
    const m = document.getElementById('writeUpModal');
    if (m) m.style.display = 'none';
}

function wuRetryWithAnswers() {
    const answers = Array.from(document.querySelectorAll('#wuContent .wu-q')).map(q => {
        const label = q.querySelector('label').textContent;
        const val = q.querySelector('textarea').value.trim();
        return val ? ('Q: ' + label + '\nA: ' + val) : '';
    }).filter(Boolean).join('\n\n');

    if (!answers) { showToast('Answer at least one question first', 'error'); return; }

    document.getElementById('wuStatus').textContent = 'Writing it up…';
    document.getElementById('wuContent').innerHTML = '';
    document.getElementById('wuFoot').innerHTML = '';
    wuStream({ ticket_id: wuTicketId, answers });
}

/**
 * Stream the write-up. The server sends the verdict as its own event before any
 * prose, so nothing is rendered until we know whether this is an article or a
 * refusal — otherwise the words "VERDICT: ARTICLE" land in the analyst's draft.
 */
async function wuStream(payload) {
    wuDraftHtml = '';
    const statusEl = () => document.getElementById('wuStatus');
    const contentEl = () => document.getElementById('wuContent');
    let verdict = null;
    let buffer = '';

    let res;
    try {
        res = await fetch((window.KB_BASE || '../api/knowledge/') + 'writeup_stream.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    } catch (e) {
        statusEl().textContent = 'Could not reach the assistant.';
        return;
    }

    const handle = (event, data) => {
        if (event === 'error') { statusEl().textContent = data.message || 'Something went wrong.'; return; }
        if (event === 'verdict') {
            verdict = data.verdict;
            statusEl().textContent = verdict === 'article' ? 'Writing it up…' : 'There is not enough in this ticket yet.';
            contentEl().innerHTML = verdict === 'article'
                ? '<div id="wuPreview" style="border:1px solid var(--border);border-radius:8px;padding:16px 18px;line-height:1.6;"></div>'
                : '<div id="wuRefusal" style="border-left:3px solid var(--accent);padding:2px 0 2px 14px;margin-bottom:16px;line-height:1.55;"></div>';
            return;
        }
        if (event === 'text') {
            buffer += (data.delta || '');
            if (verdict === 'article') {
                wuDraftHtml = buffer;
                const p = document.getElementById('wuPreview');
                // Model output still goes through the one shared sanitiser
                // before it touches innerHTML — same rule as every message body.
                if (p) p.innerHTML = safeHtmlFragment(buffer);
            } else {
                const r = document.getElementById('wuRefusal');
                if (r) r.textContent = buffer;
            }
            return;
        }
        if (event === 'done') wuFinish(data);
    };

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let sseBuf = '';
    for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        sseBuf += decoder.decode(value, { stream: true });
        const chunks = sseBuf.split('\n\n');
        sseBuf = chunks.pop();
        chunks.forEach(chunk => {
            const ev = (chunk.match(/^event: (.+)$/m) || [])[1];
            const dm = (chunk.match(/^data: (.+)$/m) || [])[1];
            if (!ev || !dm) return;
            try { handle(ev, JSON.parse(dm)); } catch (e) { /* partial frame */ }
        });
    }
}

function wuFinish(data) {
    const statusEl = document.getElementById('wuStatus');
    const contentEl = document.getElementById('wuContent');
    const footEl = document.getElementById('wuFoot');

    if (data.verdict === 'article') {
        statusEl.textContent = 'Draft ready — read it before publishing.';
        contentEl.insertAdjacentHTML('beforeend',
            '<div style="font-size:12px;color:var(--text-muted);margin-top:12px;">' +
            'Saving puts this in the knowledge base as an unpublished draft. Nobody can read it until you publish it.</div>');
        footEl.innerHTML =
            '<button class="btn btn-secondary" onclick="closeWriteUpModal()">Cancel</button>' +
            '<button class="btn btn-primary" id="wuSaveBtn" onclick="wuSaveDraft()">Save</button>';
        return;
    }

    statusEl.textContent = 'Not enough to write from yet.';
    const refusal = document.getElementById('wuRefusal');
    if (refusal) {
        refusal.textContent = data.explanation || 'This ticket does not say what caused the problem or how it was fixed.';
    }
    if ((data.questions || []).length) {
        contentEl.insertAdjacentHTML('beforeend',
            '<p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">Answer what you can and it will try again.</p>' +
            data.questions.map(q =>
                '<div class="wu-q" style="margin-bottom:14px;">' +
                '<label style="display:block;font-size:13px;margin-bottom:5px;line-height:1.45;">' + escapeHtml(q) + '</label>' +
                '<textarea class="form-textarea" style="width:100%;box-sizing:border-box;min-height:58px;"></textarea></div>').join(''));
        footEl.innerHTML =
            '<button class="btn btn-secondary" onclick="closeWriteUpModal()">Close</button>' +
            '<button class="btn btn-primary" onclick="wuRetryWithAnswers()">Retry</button>';
    } else {
        footEl.innerHTML = '<button class="btn btn-secondary" onclick="closeWriteUpModal()">Close</button>';
    }
}

async function wuSaveDraft() {
    const btn = document.getElementById('wuSaveBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    // The <h1> the model opened with is the title; everything after it is body.
    const tmp = document.createElement('div');
    tmp.innerHTML = safeHtmlFragment(wuDraftHtml);
    const h1 = tmp.querySelector('h1');
    const title = h1 ? h1.textContent.trim() : (currentEmail ? currentEmail.subject : 'Untitled');
    if (h1) h1.remove();

    try {
        const res = await fetch((window.KB_BASE || '../api/knowledge/') + 'writeup_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, body_html: tmp.innerHTML, ticket_id: wuTicketId })
        });
        const data = await res.json();
        if (!data.success) {
            if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
            showToast(data.error || 'Could not save', 'error');
            return;
        }
        closeWriteUpModal();
        showToast('Saved as a draft in Knowledge', 'success');
    } catch (e) {
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
        showToast('Could not save', 'error');
    }
}

// ==================== Signatures in the composer (#80) ====================
//
// The signature is put INTO THE EDITOR, visibly, when a reply or forward is
// opened — not stapled on at send time. The analyst can read it, change it or
// delete it before sending, which is the whole point: what they see is what the
// customer gets. A signature appended invisibly on send is how somebody ends up
// with two sign-offs, or with the wrong one, and never knows.
//
// The wrapper carries data-signature so the picker can replace it. That attribute
// only survives because it is declared in extended_valid_elements on the editor —
// TinyMCE drops attributes its schema does not know about, silently.

let mySignatureCache = null;

async function loadMySignatures(force) {
    if (mySignatureCache && !force) return mySignatureCache;
    try {
        const base = window.MYACCOUNT_API || '../api/myaccount/';
        const resp = await fetch(base + 'get_signatures.php');
        const data = await resp.json();
        // null, not [], when the request failed: "you have none" and "we could not
        // find out" must not look the same, or a failed load silently composes a
        // reply with no signature and nobody knows why.
        mySignatureCache = data.success ? (data.signatures || []) : null;
    } catch (e) {
        mySignatureCache = null;
    }
    return mySignatureCache;
}

function signatureWrap(sig) {
    return '<div data-signature="' + sig.id + '">' + (sig.rendered || '') + '</div>';
}

/** Drop the analyst's default signature into a freshly opened composer. */
function applyDefaultSignature() {
    loadMySignatures().then(function (sigs) {
        if (!emailEditor || !sigs || !sigs.length) return;
        // The editor may have been closed again while this was in flight.
        const modal = document.getElementById('emailModal');
        if (!modal || !modal.classList.contains('active')) return;
        // Never overwrite something already typed — the analyst may have been
        // quicker than the fetch.
        if (emailEditor.getBody().querySelector('[data-signature]')) return;
        let def = null;
        for (let i = 0; i < sigs.length; i++) {
            if (Number(sigs[i].is_default) === 1) { def = sigs[i]; break; }
        }
        setSignatureInEditor(def || sigs[0]);
    });
}

/** Put this signature in, replacing whichever one is already there. */
function setSignatureInEditor(sig) {
    if (!emailEditor) return;
    const existing = emailEditor.getBody().querySelector('[data-signature]');
    if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

    // ⚠️ An empty editor returns '' from getContent() — TinyMCE does not hand back
    // the <p><br></p> that is sitting in it. Concatenating onto that would leave the
    // signature as the FIRST thing in the body, and the cursor would land inside it:
    // the analyst starts typing in the middle of their own sign-off. So the blank
    // paragraph to write in is put there explicitly.
    let current = emailEditor.getContent();
    if (current.replace(/<[^>]*>|&nbsp;|\s/g, '') === '') {
        current = '<p><br></p>';
    }
    emailEditor.setContent(current + signatureWrap(sig));

    // Cursor above the signature, the way every mail client does it — otherwise
    // the analyst starts typing underneath their own sign-off.
    try {
        const first = emailEditor.getBody().firstChild;
        if (first) {
            emailEditor.selection.select(first, true);
            emailEditor.selection.collapse(true);
        }
        emailEditor.focus();
    } catch (e) { /* cursor placement is a nicety, never a reason to fail */ }
}

async function toggleSignatureMenu(event) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('signatureMenu');
    if (!menu) return;
    if (menu.style.display === 'block') { menu.style.display = 'none'; return; }
    // Re-fetch on open: the analyst may have just added one in another tab.
    await loadMySignatures(true);
    renderSignatureMenu();
    menu.style.display = 'block';
}

function closeSignatureMenu() {
    const menu = document.getElementById('signatureMenu');
    if (menu) menu.style.display = 'none';
}

document.addEventListener('click', function (e) {
    const menu = document.getElementById('signatureMenu');
    if (menu && menu.style.display === 'block' && !menu.contains(e.target)) {
        closeSignatureMenu();
    }
});

function renderSignatureMenu() {
    const menu = document.getElementById('signatureMenu');
    if (!menu) return;

    const manage = '<div class="reply-tpl-menu-empty"><a href="' + (window.PREFS_URL || '../system/preferences/') + '#details">'
                 + escapeHtml(t('tickets.reply_modal.signature_manage')) + '</a></div>';

    if (mySignatureCache === null) {
        menu.innerHTML = '<div class="reply-tpl-menu-empty">'
                       + escapeHtml(t('tickets.reply_modal.signature_failed')) + '</div>';
        return;
    }
    if (!mySignatureCache.length) {
        menu.innerHTML = '<div class="reply-tpl-menu-empty">'
                       + escapeHtml(t('tickets.reply_modal.signature_none')) + '</div>' + manage;
        return;
    }

    menu.innerHTML = mySignatureCache.map(function (s) {
        const def = Number(s.is_default) === 1
            ? ' <span class="sig-menu-default">' + escapeHtml(t('tickets.reply_modal.signature_default')) + '</span>' : '';
        return '<div class="reply-tpl-menu-row">'
             +   '<button type="button" class="reply-tpl-insert" onclick="pickSignature(' + s.id + ')">'
             +     escapeHtml(s.name) + def
             +   '</button>'
             + '</div>';
    }).join('')
    + '<div class="reply-tpl-menu-row">'
    +   '<button type="button" class="reply-tpl-insert" onclick="pickSignature(0)">'
    +     escapeHtml(t('tickets.reply_modal.signature_remove'))
    +   '</button>'
    + '</div>'
    + manage;
}

function pickSignature(id) {
    closeSignatureMenu();
    if (!emailEditor) return;

    if (Number(id) === 0) {
        const existing = emailEditor.getBody().querySelector('[data-signature]');
        if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
        // getContent() re-reads the body, so the removal is what gets kept.
        emailEditor.setContent(emailEditor.getContent());
        return;
    }
    const sig = (mySignatureCache || []).find(function (s) { return Number(s.id) === Number(id); });
    if (sig) setSignatureInEditor(sig);
}

/* =========================================================================
   THE AI READING AIDS  (discussion #104, ideas 7 and 12)

   Two separate things that happen to share a provider:

     • THE MAINTAINED SUMMARY — a standing few lines at the top of the ticket
       saying where it stands. Stored, versioned, and refreshed when the
       conversation has moved on.
     • READ THIS TICKET FOR ME — a briefing you ask for and read once. Stored
       nowhere.

   ⚠️ Both are OFF unless an administrator turned them on. window.TICKET_AI is
   emitted by the page rather than fetched, so on an install that wants none of
   this the buttons are never even built — there is nothing to click, nothing to
   fail, and nothing to explain.

   🔑 THE SUMMARY NEVER STANDS IN FOR THE TICKET. It carries the time it was
   written, what it read, and — the part that matters — how far the conversation
   has moved since. A summary that quietly describes a ticket as it was four
   messages ago is worse than no summary, because it reads exactly like one that
   is current.
   ========================================================================= */

let _aiSummaryTicketId = 0;
let _aiSummaryState = null;
let _aiSummaryBusy = false;

function aiSummaryOn() { return !!(window.TICKET_AI && window.TICKET_AI.summary_enabled); }
function aiReadOn()    { return !!(window.TICKET_AI && window.TICKET_AI.read_enabled); }

/** The slot. Empty markup when the feature is off, so nothing reserves space. */
function buildAiSummarySlot() {
    if (!aiSummaryOn()) return '';
    return '<div class="ai-summary" id="aiSummarySlot" hidden></div>';
}

function buildReadForMeButton() {
    if (!aiReadOn()) return '';
    return `            <button class="action-btn" onclick="openReadForMe()" title="${escapeHtml(t('tickets.ai.read_for_me_help'))}">
                <span class="action-btn-icon">📖</span>
                <span>${escapeHtml(t('tickets.ai.read_for_me'))}</span>
            </button>
`;
}

/* ── the standing summary ───────────────────────────────────────────────── */

async function loadAiSummary(ticketId) {
    if (!aiSummaryOn() || !ticketId) return;
    _aiSummaryTicketId = ticketId;
    _aiSummaryState = null;
    try {
        const r = await fetch(`../api/tickets/ai_summary.php?ticket_id=${encodeURIComponent(ticketId)}`);
        const d = await r.json();
        // The ticket may have changed underneath a slow request. Landing an old
        // ticket's summary on a new ticket would be a quiet, confident lie.
        if (!d.success || d.disabled || _aiSummaryTicketId !== ticketId) return;
        _aiSummaryState = d;
        renderAiSummary();

        /* An automatic refresh, if the administrator asked for one and the
           conversation has moved far enough. The SERVER decides — this only
           asks, and asking twice cannot bill twice. */
        if (d.auto_due && d.configured) refreshAiSummary(true);
    } catch (e) {
        // A summary that cannot load leaves the ticket exactly as it was.
    }
}

function renderAiSummary() {
    const slot = document.getElementById('aiSummarySlot');
    if (!slot || !_aiSummaryState) return;
    const st = _aiSummaryState;
    const s = st.summary;

    if (!s && !st.configured) {
        // Turned on but never given a key. Say so where somebody can act on it,
        // rather than showing an empty panel or nothing at all.
        slot.hidden = false;
        slot.innerHTML = `<div class="ai-summary-head">
            <span class="ai-summary-badge">${escapeHtml(t('tickets.ai.badge'))}</span>
            <span class="ai-summary-note">${escapeHtml(t('tickets.ai.not_configured'))}</span>
        </div>`;
        return;
    }

    slot.hidden = false;
    const collapsed = localStorage.getItem('aiSummaryCollapsed') === '1';

    if (!s) {
        slot.innerHTML = `<div class="ai-summary-head">
            <span class="ai-summary-badge">${escapeHtml(t('tickets.ai.badge'))}</span>
            <span class="ai-summary-note">${escapeHtml(t('tickets.ai.none_yet'))}</span>
            <button type="button" class="ai-summary-btn" onclick="refreshAiSummary(false)">${escapeHtml(t('tickets.ai.summarise'))}</button>
        </div>`;
        return;
    }

    /* "Written at, from N messages, and M have arrived since." All three, always.
       The staleness line is the one that stops this becoming the only thing
       anybody reads. */
    const meta = [
        t('tickets.ai.written_at').replace('{time}', formatFullDateTime(s.created_at)),
        t('tickets.ai.read_n').replace('{n}', s.message_count),
        'v' + s.version,
    ].join(' · ');

    /* Two different warnings, and the order matters: "this summary is
       incomplete" is about the words you are looking at, while "the ticket has
       moved on" is about everything below them. */
    const cut = s.truncated
        ? `<div class="ai-summary-stale">${escapeHtml(t('tickets.ai.cut_off'))}</div>`
        : '';
    const behind = st.behind > 0
        ? `<div class="ai-summary-stale">${escapeHtml(
              (st.behind === 1 ? t('tickets.ai.behind_one') : t('tickets.ai.behind_n')).replace('{n}', st.behind))}</div>`
        : '';

    slot.innerHTML = `
        <div class="ai-summary-head">
            <span class="ai-summary-badge">${escapeHtml(t('tickets.ai.badge'))}</span>
            <span class="ai-summary-meta">${escapeHtml(meta)}</span>
            <span class="ai-summary-actions">
                ${s.version > 1 ? `<button type="button" class="ai-summary-link" onclick="showAiSummaryHistory()">${escapeHtml(t('tickets.ai.history'))}</button>` : ''}
                <button type="button" class="ai-summary-btn" onclick="refreshAiSummary(false)">${escapeHtml(t('tickets.ai.refresh'))}</button>
                <button type="button" class="ai-summary-link" onclick="toggleAiSummary()">${escapeHtml(collapsed ? t('tickets.ai.show') : t('tickets.ai.hide'))}</button>
            </span>
        </div>
        ${cut}
        ${behind}
        <div class="ai-summary-body"${collapsed ? ' hidden' : ''}>${escapeHtml(s.text)}</div>`;
}

function toggleAiSummary() {
    const body = document.querySelector('#aiSummarySlot .ai-summary-body');
    if (!body) return;
    body.hidden = !body.hidden;
    try { localStorage.setItem('aiSummaryCollapsed', body.hidden ? '1' : '0'); } catch (e) {}
    renderAiSummary();
}

async function refreshAiSummary(auto) {
    if (_aiSummaryBusy || !_aiSummaryTicketId) return;
    _aiSummaryBusy = true;
    const ticketId = _aiSummaryTicketId;
    const slot = document.getElementById('aiSummarySlot');
    if (slot && !auto) {
        slot.hidden = false;
        slot.innerHTML = `<div class="ai-summary-head">
            <span class="ai-summary-badge">${escapeHtml(t('tickets.ai.badge'))}</span>
            <span class="ai-summary-note" id="aiSummaryWait">${escapeHtml(t('tickets.ai.working'))}</span>
        </div>`;
    }
    /* Same counting wait as "read it for me": a reasoning model can take a
       minute, and a line that never changes reads as a broken feature rather
       than a slow one. An AUTOMATIC refresh gets none of this — it happens
       behind the summary already on screen and must not disturb it. */
    const startedAt = Date.now();
    const waitTick = (slot && !auto) ? setInterval(() => {
        const el = document.getElementById('aiSummaryWait');
        if (el) el.textContent = t('tickets.ai.working_secs').replace('{n}', Math.round((Date.now() - startedAt) / 1000));
    }, 1000) : null;
    try {
        const r = await fetch('../api/tickets/ai_summary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, auto: !!auto }),
        });
        const d = await r.json();
        if (_aiSummaryTicketId !== ticketId) return;
        if (d.success) {
            _aiSummaryState = { ...(_aiSummaryState || {}), summary: d.summary, behind: 0, configured: true };
            renderAiSummary();
        } else if (auto) {
            // A refused automatic refresh is not an error anybody needs to see —
            // it means the server decided it was not due, which is its job.
            renderAiSummary();
        } else {
            const slot2 = document.getElementById('aiSummarySlot');
            if (slot2) slot2.innerHTML = `<div class="ai-summary-head">
                <span class="ai-summary-badge">${escapeHtml(t('tickets.ai.badge'))}</span>
                <span class="ai-summary-note">${escapeHtml(aiErrorText(d.error))}</span>
                <button type="button" class="ai-summary-btn" onclick="refreshAiSummary(false)">${escapeHtml(t('tickets.ai.try_again'))}</button>
            </div>`;
        }
    } catch (e) {
        if (!auto) renderAiSummary();
    } finally {
        if (waitTick) clearInterval(waitTick);
        _aiSummaryBusy = false;
    }
}

/** Every version ever written, because nothing here is ever overwritten. */
async function showAiSummaryHistory() {
    if (!_aiSummaryTicketId) return;
    const r = await fetch(`../api/tickets/ai_summary.php?ticket_id=${encodeURIComponent(_aiSummaryTicketId)}&history=1`);
    const d = await r.json();
    if (!d.success) return;

    const rows = (d.versions || []).map(v => `
        <div class="ai-history-item">
            <div class="ai-history-meta">v${v.version} · ${escapeHtml(formatFullDateTime(v.created_at))}
                ${v.generated_by_name ? '· ' + escapeHtml(v.generated_by_name) : '· ' + escapeHtml(t('tickets.ai.automatic'))}
                · ${escapeHtml(t('tickets.ai.read_n').replace('{n}', v.message_count))}
                ${v.model ? '· ' + escapeHtml(v.model) : ''}</div>
            ${+v.truncated ? `<div class="ai-summary-stale">${escapeHtml(t('tickets.ai.cut_off'))}</div>` : ''}
            <div class="ai-history-body">${escapeHtml(v.summary)}</div>
        </div>`).join('');

    showAiPanel(t('tickets.ai.history_title'), `<div class="ai-history">${rows}</div>`);
}

/* ── read this ticket for me ────────────────────────────────────────────── */

let _aiReadBusy = false;
let _aiReadTicketId = 0;

/* Opening the panel READS what is already stored — from the database, never the
   provider — so a briefing you have seen comes back instantly and free. Only
   "Read again" spends anything, and only when there is nothing stored at all is
   one written without being asked for. */
async function openReadForMe() {
    if (!currentEmail) return;
    const ticketId = currentEmail.ticket_id || currentEmail.id;
    _aiReadTicketId = ticketId;
    showAiPanel(t('tickets.ai.read_for_me'),
        `<div class="ai-read-working">${escapeHtml(t('tickets.ai.loading'))}</div>`);
    try {
        const r = await fetch(`../api/tickets/ai_read.php?ticket_id=${encodeURIComponent(ticketId)}`);
        const d = await r.json();
        if (_aiReadTicketId !== ticketId) return;
        if (!d.success) { aiReadShowError(d.error); return; }
        if (d.briefing) { aiReadRender(d.briefing, (d.versions || []).length); return; }
        // Nothing written yet — this is the one case that goes straight to the model.
        runReadForMe();
    } catch (e) {
        aiReadShowError('provider_unreachable');
    }
}

/* ⚠️ A REASONING MODEL CAN TAKE A MINUTE, and every call here is PAID. The first
   version showed a motionless "Reading the ticket…" for the whole of it, which
   is precisely when somebody clicks again — so a slow answer billed twice and
   the second call overwrote the first. The guard is the part that matters; the
   counter only stops it LOOKING broken. */
async function runReadForMe() {
    if (_aiReadBusy || !_aiReadTicketId) return;
    _aiReadBusy = true;
    const ticketId = _aiReadTicketId;
    const box = document.querySelector('#aiPanelModal .ai-panel-body');
    if (box) box.innerHTML = `<div class="ai-read-working"><span id="aiReadWait">${escapeHtml(t('tickets.ai.working'))}</span></div>`;

    /* Counts up rather than animating: "38s" says it is still going AND roughly
       how long this model takes on this desk, which a spinner does not. Cleared
       in `finally` so it cannot outlive the request. */
    const started = Date.now();
    const tick = setInterval(() => {
        const el = document.getElementById('aiReadWait');
        if (el) el.textContent = t('tickets.ai.working_secs').replace('{n}', Math.round((Date.now() - started) / 1000));
    }, 1000);

    try {
        const r = await fetch('../api/tickets/ai_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId }),
        });
        const d = await r.json();
        if (_aiReadTicketId !== ticketId) return;
        if (!d.success) { aiReadShowError(d.error); return; }
        aiReadRender(d.briefing, d.briefing.version);
    } catch (e) {
        aiReadShowError('provider_unreachable');
    } finally {
        clearInterval(tick);
        _aiReadBusy = false;
    }
}

/* One renderer for a stored briefing and a freshly written one, so a re-read
   cannot come back looking different from what reopening shows. */
function aiReadRender(b, versionCount) {
    const box = document.querySelector('#aiPanelModal .ai-panel-body');
    if (!box) return;
    const meta = [
        t('tickets.ai.written_at').replace('{time}', formatFullDateTime(b.created_at)),
        t('tickets.ai.read_n').replace('{n}', b.message_count),
        'v' + b.version,
    ].join(' · ');
    box.innerHTML = `
        <div class="ai-summary-head" style="padding-left:0;padding-right:0">
            <span class="ai-summary-badge">${escapeHtml(t('tickets.ai.badge'))}</span>
            <span class="ai-summary-meta">${escapeHtml(meta)}</span>
            <span class="ai-summary-actions">
                ${versionCount > 1 ? `<button type="button" class="ai-summary-link" onclick="showAiReadHistory()">${escapeHtml(t('tickets.ai.history'))}</button>` : ''}
                <button type="button" class="ai-summary-btn" onclick="runReadForMe()">${escapeHtml(t('tickets.ai.read_again'))}</button>
            </span>
        </div>
        ${b.truncated ? `<div class="ai-summary-stale">${escapeHtml(t('tickets.ai.cut_off'))}</div>` : ''}
        ${b.behind > 0 ? `<div class="ai-summary-stale">${escapeHtml(
            (b.behind === 1 ? t('tickets.ai.behind_one') : t('tickets.ai.behind_n')).replace('{n}', b.behind))}</div>` : ''}
        <div class="ai-read-body">${escapeHtml(b.text)}</div>
        <div class="ai-read-foot">${escapeHtml(b.model || '')}</div>`;
}

function aiReadShowError(code) {
    const box = document.querySelector('#aiPanelModal .ai-panel-body');
    if (box) box.innerHTML = `<div class="ai-read-working">${escapeHtml(aiErrorText(code))}</div>`;
}

/** Every briefing ever written for this ticket — nothing is overwritten. */
async function showAiReadHistory() {
    if (!_aiReadTicketId) return;
    const r = await fetch(`../api/tickets/ai_read.php?ticket_id=${encodeURIComponent(_aiReadTicketId)}`);
    const d = await r.json();
    if (!d.success) return;
    const rows = (d.versions || []).map(v => `
        <div class="ai-history-item">
            <div class="ai-history-meta">v${v.version} · ${escapeHtml(formatFullDateTime(v.created_at))}
                ${v.generated_by_name ? '· ' + escapeHtml(v.generated_by_name) : ''}
                · ${escapeHtml(t('tickets.ai.read_n').replace('{n}', v.message_count))}
                ${v.model ? '· ' + escapeHtml(v.model) : ''}</div>
            ${+v.truncated ? `<div class="ai-summary-stale">${escapeHtml(t('tickets.ai.cut_off'))}</div>` : ''}
            <div class="ai-history-body">${escapeHtml(v.summary)}</div>
        </div>`).join('');
    showAiPanel(t('tickets.ai.read_history_title'), `<div class="ai-history">${rows}</div>`);
}

/* ── the shared panel ───────────────────────────────────────────────────── */

function showAiPanel(title, html) {
    let m = document.getElementById('aiPanelModal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'aiPanelModal';
        m.className = 'ai-panel-overlay';
        m.addEventListener('click', e => { if (e.target === m) closeAiPanel(); });
        document.body.appendChild(m);
    }
    m.innerHTML = `
        <div class="ai-panel" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}">
            <div class="ai-panel-head">
                <span>${escapeHtml(title)}</span>
                <button type="button" class="ai-panel-close" onclick="closeAiPanel()" aria-label="${escapeHtml(t('common.close'))}">&times;</button>
            </div>
            <div class="ai-panel-body">${html}</div>
        </div>`;
    m.classList.add('active');
}

function closeAiPanel() {
    const m = document.getElementById('aiPanelModal');
    if (m) m.classList.remove('active');
}

/** Every failure this can have, said in words rather than as a code. */
function aiErrorText(code) {
    switch (code) {
        case 'not_configured':       return t('tickets.ai.not_configured');
        case 'provider_unreachable': return t('tickets.ai.unreachable');
        case 'reasoning_overran':   return t('tickets.ai.reasoning_overran');
        case 'nothing_to_summarise':
        case 'nothing_to_read':      return t('tickets.ai.nothing_to_read');
        case 'disabled':
        case 'auto_disabled':        return t('tickets.ai.turned_off');
        default:                     return t('tickets.ai.failed');
    }
}
