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
                select.innerHTML = '<option value="">-- No owner assigned --</option>';
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
    tinymce.init({
        selector: '#articleBody',
        license_key: 'gpl',
        height: 400,
        menubar: true,
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
            articleList.innerHTML = '<div class="no-results">Error loading articles</div>';
        }
    } catch (error) {
        console.error('Error loading articles:', error);
        articleList.innerHTML = '<div class="no-results">Failed to load articles</div>';
    }
}

// Render tag filters in sidebar
function renderTagFilters() {
    const container = document.getElementById('tagFilterList');

    if (tags.length === 0) {
        container.innerHTML = '<div class="no-results">No tags yet</div>';
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

    countEl.textContent = `${articles.length} article${articles.length === 1 ? '' : 's'}`;

    if (articles.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📚</div>
                <div class="empty-state-text">No articles found</div>
                <button class="btn btn-primary" onclick="openCreateArticle()">Create your first article</button>
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
                    <span>By ${escapeHtml(article.author_name)}</span>
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
            alert('Error loading article: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to load article');
    }
}

// Render article detail
function renderArticleDetail() {
    const container = document.getElementById('articleContent');

    container.innerHTML = `
        <div class="article-content-header">
            <h1 class="article-content-title">${escapeHtml(currentArticle.title)}</h1>
            <div class="article-content-meta">
                <span>By ${escapeHtml(currentArticle.author_name)}</span>
                <span>Created: ${formatDate(currentArticle.created_datetime)} (v${currentArticle.version || 1})</span>
                <span>Modified: ${formatDate(currentArticle.modified_datetime)}</span>
                <span>Views: ${currentArticle.view_count}</span>
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
    document.getElementById('editorTitle').textContent = 'New Article';
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
}

// Edit current article
function editCurrentArticle() {
    if (!currentArticle) return;

    document.getElementById('editArticleId').value = currentArticle.id;
    document.getElementById('articleTitle').value = currentArticle.title;
    document.getElementById('editorTitle').textContent = 'Edit Article';

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
}

// Save as new version
async function saveAsNewVersion() {
    const currentVersion = currentArticle.version || 1;
    const confirmed = await customAlert({
        title: 'Save as New Version',
        message: 'The current content will be archived as <strong>v' + currentVersion + '</strong> and your changes will become <strong>v' + (currentVersion + 1) + '</strong>.',
        type: 'info',
        confirm: true,
        okText: 'Confirm',
        cancelText: 'Cancel'
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
        await customAlert({ title: 'Missing Title', message: 'Please enter a title before saving.', type: 'warning' });
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
            showToast('Saved as v' + (currentVersion + 1));
            loadTags();
            loadArticles();
            showView('list');
        } else {
            await customAlert({ title: 'Error', message: data.error || 'Failed to save version.', type: 'danger' });
        }
    } catch (error) {
        console.error('Error:', error);
        await customAlert({ title: 'Error', message: 'Failed to save version. Please try again.', type: 'danger' });
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
        alert('Please enter a title');
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
            alert(articleId ? 'Article updated successfully' : 'Article created successfully');
            loadTags(); // Refresh tags in case new ones were added
            loadArticles();
            showView('list');
        } else {
            alert('Error saving article: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to save article');
    }
}

// Delete current article
async function deleteCurrentArticle() {
    if (!currentArticle) return;

    if (!confirm('Move this article to the recycle bin?')) {
        return;
    }

    try {
        const response = await fetch(API_BASE + 'knowledge_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentArticle.id })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Article moved to recycle bin');
            loadTags();
            loadArticles();
            showView('list');
        } else {
            alert('Error archiving article: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to archive article');
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
        header.textContent = 'Knowledge Articles';
        loadArticles();
        showView('list');
    } else {
        // Enter recycle bin
        isRecycleBinView = true;
        toggle.classList.add('active');
        header.textContent = 'Recycle Bin';
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
            articleList.innerHTML = '<div class="empty-state">Error loading recycle bin</div>';
            return;
        }

        const items = data.articles || [];
        const retentionDays = data.retention_days || 0;
        articleCount.textContent = items.length + ' archived';

        if (items.length === 0) {
            articleList.innerHTML = '<div class="empty-state">Recycle bin is empty</div>';
            return;
        }

        let html = '';
        if (retentionDays > 0) {
            html += `<div class="recycle-bin-notice">Articles are automatically deleted after ${retentionDays} days in the recycle bin.</div>`;
        } else {
            html += '<div class="recycle-bin-notice">Articles are kept indefinitely until manually deleted.</div>';
        }

        items.forEach(item => {
            const archivedDate = item.archived_datetime ? formatDate(item.archived_datetime) : 'Unknown';
            const archivedBy = item.archived_by_name || 'Unknown';
            html += `
                <div class="article-card recycle-bin-card">
                    <div class="article-card-title">${escapeHtml(item.title)}</div>
                    <div class="article-card-meta">
                        By ${escapeHtml(item.author_name)} &middot; Archived ${archivedDate} by ${escapeHtml(archivedBy)}
                    </div>
                    <div class="recycle-bin-actions">
                        <button class="btn btn-secondary btn-sm" onclick="viewArchivedArticle(${item.id})">View</button>
                        <button class="btn btn-primary btn-sm" onclick="restoreArticle(${item.id})">Restore</button>
                        <button class="btn btn-danger btn-sm" onclick="hardDeleteArticle(${item.id}, '${escapeHtml(item.title).replace(/'/g, "\\'")}')">Delete Permanently</button>
                    </div>
                </div>
            `;
        });

        articleList.innerHTML = html;
    } catch (error) {
        console.error('Error loading recycle bin:', error);
        articleList.innerHTML = '<div class="empty-state">Failed to load recycle bin</div>';
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
            showToast('Article restored');
            loadTags();
            await loadRecycleBin();
        } else {
            alert('Error restoring article: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to restore article');
    }
}

async function hardDeleteArticle(id, title) {
    if (!confirm(`Permanently delete "${title}"? This cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch(API_BASE + 'knowledge_archive.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'hard_delete', id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast('Article permanently deleted');
            loadTags();
            await loadRecycleBin();
        } else {
            alert('Error deleting article: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to delete article');
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
                `By ${escapeHtml(article.author_name)} &middot; Created ${formatDate(article.created_datetime)} &middot; Modified ${formatDate(article.modified_datetime)}` +
                (article.tags && article.tags.length ? '<div style="margin-top: 8px;">' + article.tags.map(t => `<span class="article-tag">${escapeHtml(t.name)}</span>`).join(' ') + '</div>' : '');
            document.getElementById('archivedArticleBody').innerHTML = article.body;
            document.getElementById('archivedArticleModal').classList.add('active');

            if (typeof Prism !== 'undefined') {
                Prism.highlightAll();
            }
        } else {
            alert('Error loading article: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to load article');
    }
}

function closeArchivedArticleModal() {
    document.getElementById('archivedArticleModal').classList.remove('active');
}

function showToast(message) {
    const existing = document.querySelector('.kb-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'kb-toast';
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
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
function backToList() {
    currentArticle = null;
    showView('list');
}

// Show/hide views
function showView(view) {
    document.getElementById('articleListView').style.display = view === 'list' ? 'block' : 'none';
    document.getElementById('articleDetailView').style.display = view === 'detail' ? 'block' : 'none';
    document.getElementById('articleEditorView').style.display = view === 'editor' ? 'block' : 'none';

    // Reset recycle bin state when navigating away from list
    if (view !== 'list' && isRecycleBinView) {
        isRecycleBinView = false;
        const toggle = document.getElementById('recycleBinToggle');
        const header = document.getElementById('articleListHeader');
        if (toggle) toggle.classList.remove('active');
        if (header) header.textContent = 'Knowledge Articles';
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
                Create "${escapeHtml(query)}"
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
        showToast('Link copied to clipboard!');
    }).catch(() => {
        // Fallback for older browsers
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast('Link copied to clipboard!');
    });
}

// Show toast notification
function showToast(message) {
    const toast = document.getElementById('linkCopiedToast');
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 2500);
}

// Export article as PDF
function shareArticlePdf() {
    closeShareDropdown();

    if (!currentArticle) return;

    // Create a clean version of the article for PDF
    const pdfContent = document.createElement('div');
    pdfContent.innerHTML = `
        <div style="font-family: 'Segoe UI', Tahoma, sans-serif; padding: 20px;">
            <h1 style="color: #333; margin-bottom: 10px;">${escapeHtml(currentArticle.title)}</h1>
            <div style="color: #666; font-size: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #ddd;">
                By ${escapeHtml(currentArticle.author_name)} |
                Created: ${formatDate(currentArticle.created_datetime)} |
                Modified: ${formatDate(currentArticle.modified_datetime)}
            </div>
            <div style="line-height: 1.6; color: #333;">
                ${currentArticle.body}
            </div>
        </div>
    `;

    // PDF options
    const opt = {
        margin: 10,
        filename: `${currentArticle.title.replace(/[^a-z0-9]/gi, '_')}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    // Generate PDF
    html2pdf().set(opt).from(pdfContent).save();
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
        alert('Please enter a recipient email address');
        return;
    }

    if (!includeLink && !includePdf) {
        alert('Please select at least one option to include');
        return;
    }

    // Generate PDF if needed
    let pdfBase64 = null;
    if (includePdf) {
        const pdfContent = document.createElement('div');
        pdfContent.innerHTML = `
            <div style="font-family: 'Segoe UI', Tahoma, sans-serif; padding: 20px;">
                <h1 style="color: #333; margin-bottom: 10px;">${escapeHtml(currentArticle.title)}</h1>
                <div style="color: #666; font-size: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #ddd;">
                    By ${escapeHtml(currentArticle.author_name)} |
                    Created: ${formatDate(currentArticle.created_datetime)} |
                    Modified: ${formatDate(currentArticle.modified_datetime)}
                </div>
                <div style="line-height: 1.6; color: #333;">
                    ${currentArticle.body}
                </div>
            </div>
        `;

        const opt = {
            margin: 10,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        try {
            const pdfBlob = await html2pdf().set(opt).from(pdfContent).outputPdf('blob');
            pdfBase64 = await blobToBase64(pdfBlob);
        } catch (error) {
            console.error('Error generating PDF:', error);
            alert('Error generating PDF. Please try again.');
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
            showToast('Email sent successfully!');
        } else {
            alert('Error sending email: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to send email. Please try again.');
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
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const articleId = urlParams.get('article') || urlParams.get('id');
    if (articleId) {
        // Wait for articles to load, then open the specific article
        const checkAndLoad = setInterval(() => {
            if (articles.length > 0 || document.getElementById('articleList').innerHTML.includes('No articles')) {
                clearInterval(checkAndLoad);
                viewArticle(articleId);
            }
        }, 100);
        // Timeout after 5 seconds
        setTimeout(() => clearInterval(checkAndLoad), 5000);
    }
})();

function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    });
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
    thinking.innerHTML = '<div class="dots"><span></span><span></span><span></span></div> Searching knowledge base...';
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
                '<div class="ai-chat-meta">Searched ' + data.articles_searched + ' articles</div>';
            messagesContainer.appendChild(assistantMsg);
        } else {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'ai-chat-error';
            errorMsg.textContent = data.error || 'Failed to get a response. Please check your API key in Settings.';
            messagesContainer.appendChild(errorMsg);
        }
    } catch (error) {
        thinking.remove();
        const errorMsg = document.createElement('div');
        errorMsg.className = 'ai-chat-error';
        errorMsg.textContent = 'Network error: ' + error.message;
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
