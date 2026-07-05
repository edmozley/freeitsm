/**
 * Knowledge Base JavaScript
 */

// API base path - can be overridden by page before loading this script
const API_BASE = window.API_BASE || 'api/';

let articles = [];
let tags = [];
let selectedTags = [];
let currentArticle = null;
let articleEditor = null;
let searchTimeout = null;
let activeTagFilters = [];
let isRecycleBinView = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadTags();
    loadArticles();
    loadAnalysts();
    initTinyMCE();
    initTagInput();
    loadSidebarModePreference();

    // Auto-open AI chat if redirected from Settings/Review
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('askai') === '1') {
        history.replaceState(null, '', window.location.pathname);
        openAiChat();
    }
});

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
        content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; line-height: 1.6; }',
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

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            articles = data.articles;
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

// Render article list
function renderArticleList() {
    const container = document.getElementById('articleList');
    const countEl = document.getElementById('articleCount');

    countEl.textContent = window.t(articles.length === 1 ? 'knowledge.list.count_one' : 'knowledge.list.count', { count: articles.length });

    if (articles.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📚</div>
                <div class="empty-state-text">${escapeHtml(window.t('knowledge.list.no_articles'))}</div>
                <button class="btn btn-primary" onclick="openCreateArticle()">${escapeHtml(window.t('knowledge.list.create_first'))}</button>
            </div>
        `;
        return;
    }

    container.innerHTML = articles.map(article => `
        <div class="article-card" onclick="viewArticle(${article.id})">
            <div class="article-card-title">${escapeHtml(article.title)}</div>
            <div class="article-card-preview">${escapeHtml(article.preview || '')}</div>
            <div class="article-card-meta">
                <div class="article-card-tags">
                    ${(article.tags || []).map(tag => `<span class="article-tag">${escapeHtml(tag.name)}</span>`).join('')}
                </div>
                <div class="article-card-info">
                    <span>${escapeHtml(window.t('knowledge.list.by', { name: article.author_name }))}</span>
                    <span>${formatDate(article.modified_datetime)}</span>
                </div>
            </div>
        </div>
    `).join('');
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

    container.innerHTML = `
        <div class="article-content-header">
            <h1 class="article-content-title">${escapeHtml(currentArticle.title)}</h1>
            <div class="article-content-meta">
                <span>${escapeHtml(window.t('knowledge.detail.by', { name: currentArticle.author_name }))}</span>
                <span>${escapeHtml(window.t('knowledge.detail.created', { date: formatDate(currentArticle.created_datetime), version: currentArticle.version || 1 }))}</span>
                <span>${escapeHtml(window.t('knowledge.detail.modified', { date: formatDate(currentArticle.modified_datetime) }))}</span>
                <span>${escapeHtml(window.t('knowledge.detail.views', { count: currentArticle.view_count }))}</span>
            </div>
            <div class="article-content-tags">
                ${(currentArticle.tags || []).map(tag => `<span class="article-tag">${escapeHtml(tag.name)}</span>`).join('')}
            </div>
        </div>
        <div class="article-content-body">
            ${currentArticle.body}
        </div>
    `;

    // Apply syntax highlighting to any code blocks
    if (typeof Prism !== 'undefined') {
        Prism.highlightAll();
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

    if (articleEditor) {
        articleEditor.setContent('');
    }

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
            body: JSON.stringify({
                id: articleId,
                title: title,
                body: body,
                tags: selectedTags,
                owner_id: ownerId || null,
                next_review_date: nextReviewDate || null,
                save_as_version: true
            })
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
            body: JSON.stringify({
                id: articleId || null,
                title: title,
                body: body,
                tags: selectedTags,
                owner_id: ownerId || null,
                next_review_date: nextReviewDate || null
            })
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

    navigator.clipboard.writeText(url).then(() => {
        showToast(window.t('knowledge.toast.link_copied'), 'success');
    }).catch(() => {
        // Fallback for older browsers
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast(window.t('knowledge.toast.link_copied'), 'success');
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
    return date.toLocaleDateString('en-GB', tzOpts({
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    }));
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
