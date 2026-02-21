<?php
/**
 * Knowledge Base - Articles management and viewing
 */
session_start();
require_once '../config.php';

$current_page = 'knowledge';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Knowledge Base</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/knowledge.css">
    <!-- Prism.js for code syntax highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.css">
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="knowledge-container">
        <!-- Sidebar with search and tags -->
        <div class="knowledge-sidebar">
            <div class="sidebar-section">
                <h3>Search Articles</h3>
                <div class="search-box">
                    <input type="text" id="articleSearch" placeholder="Search by title or content..." onkeyup="debounceSearch()">
                </div>
            </div>
            <div class="sidebar-section">
                <h3>Filter by Tags</h3>
                <div class="tag-filter-list" id="tagFilterList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
            <div class="sidebar-section">
                <button class="btn btn-primary btn-full" onclick="openCreateArticle()">+ New Article</button>
            </div>
            <div class="sidebar-section">
                <button class="btn btn-secondary btn-full recycle-bin-toggle" id="recycleBinToggle" onclick="toggleRecycleBin()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    Recycle Bin
                </button>
            </div>
        </div>

        <!-- Main content area -->
        <div class="knowledge-main">
            <!-- Article list view -->
            <div class="article-list-view" id="articleListView">
                <div class="article-list-header">
                    <h2 id="articleListHeader">Knowledge Articles</h2>
                    <div class="article-count" id="articleCount"></div>
                </div>
                <div class="article-list" id="articleList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>

            <!-- Article detail view -->
            <div class="article-detail-view" id="articleDetailView" style="display: none;">
                <div class="article-detail-header">
                    <button class="btn btn-secondary" onclick="backToList()">Back to List</button>
                    <div class="article-actions" id="articleActions">
                        <div class="share-dropdown">
                            <button class="btn btn-share" onclick="toggleShareDropdown()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="18" cy="5" r="3"></circle>
                                    <circle cx="6" cy="12" r="3"></circle>
                                    <circle cx="18" cy="19" r="3"></circle>
                                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                                </svg>
                                Share
                            </button>
                            <div class="share-dropdown-menu" id="shareDropdownMenu">
                                <a href="#" onclick="shareArticleLink(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                    </svg>
                                    Share Link
                                </a>
                                <a href="#" onclick="shareArticlePdf(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                        <polyline points="10 9 9 9 8 9"></polyline>
                                    </svg>
                                    Export as PDF
                                </a>
                                <a href="#" onclick="shareArticleBoth(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                    Email (Link + PDF)
                                </a>
                            </div>
                        </div>
                        <button class="btn btn-primary" onclick="editCurrentArticle()">Edit</button>
                        <button class="btn btn-danger" onclick="deleteCurrentArticle()">Archive</button>
                    </div>
                </div>
                <div class="article-content" id="articleContent"></div>
            </div>

            <!-- Article editor view -->
            <div class="article-editor-view" id="articleEditorView" style="display: none;">
                <div class="editor-header">
                    <h2 id="editorTitle">New Article</h2>
                </div>
                <div class="editor-form">
                    <input type="hidden" id="editArticleId" value="">
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" class="form-input" id="articleTitle" placeholder="Enter article title...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tags</label>
                        <div class="tag-input-container">
                            <div class="selected-tags" id="selectedTags"></div>
                            <input type="text" class="tag-input" id="tagInput" placeholder="Type to add tags...">
                            <div class="tag-suggestions" id="tagSuggestions"></div>
                        </div>
                        <small>Press Enter or comma to add a new tag</small>
                    </div>
                    <div class="form-row" style="display: flex; gap: 20px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Owner</label>
                            <select class="form-input" id="articleOwner">
                                <option value="">-- No owner assigned --</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Next Review Date</label>
                            <input type="date" class="form-input" id="articleReviewDate">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Content</label>
                        <textarea id="articleBody"></textarea>
                    </div>
                    <div class="editor-actions">
                        <button class="btn btn-secondary" onclick="cancelEdit()">Cancel</button>
                        <button class="btn btn-primary" onclick="saveArticle()">Save</button>
                        <button class="btn btn-primary" id="btnSaveAsVersion" onclick="saveAsNewVersion()" style="display:none;">Version</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Email Modal -->
    <div class="modal" id="shareEmailModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Share Article via Email</h3>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Recipient Email *</label>
                    <input type="email" class="form-input" id="shareEmailTo" placeholder="recipient@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Message (optional)</label>
                    <textarea class="form-textarea" id="shareEmailMessage" rows="3" placeholder="Add a personal message..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Include:</label>
                    <div style="display: flex; gap: 20px; margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludeLink" checked> Link to article
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludePdf" checked> PDF attachment
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeShareEmailModal()">Cancel</button>
                <button class="btn btn-primary" onclick="sendShareEmail()">Send</button>
            </div>
        </div>
    </div>

    <!-- Archived Article Preview Modal -->
    <div class="modal" id="archivedArticleModal">
        <div class="modal-content" style="max-width: 800px; max-height: 85vh; display: flex; flex-direction: column; padding: 30px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 id="archivedArticleTitle" style="margin: 0;"></h3>
                <button class="btn btn-secondary" onclick="closeArchivedArticleModal()" style="flex-shrink: 0;">&times;</button>
            </div>
            <div id="archivedArticleMeta" style="font-size: 13px; color: #666; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e0e0e0;"></div>
            <div id="archivedArticleBody" class="article-content-body" style="overflow-y: auto; flex: 1; padding: 4px 0;"></div>
        </div>
    </div>

    <!-- AI Chat Panel (slide-out from right) -->
    <div class="ai-chat-overlay" id="aiChatOverlay" onclick="closeAiChat()"></div>
    <div class="ai-chat-panel" id="aiChatPanel">
        <div class="ai-chat-header">
            <div class="ai-chat-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                Ask AI
            </div>
            <button class="ai-chat-close" onclick="closeAiChat()">&times;</button>
        </div>
        <div class="ai-chat-messages" id="aiChatMessages">
            <div class="ai-chat-welcome">
                <p>Ask me anything about your knowledge base articles. I'll search through all published articles to find the answer.</p>
                <p style="font-size:12px; color:#999; margin-top:8px;">Powered by Claude Haiku</p>
            </div>
        </div>
        <div class="ai-chat-options">
            <label class="ai-archive-toggle" title="Include archived (recycle bin) articles in AI search">
                <span class="toggle-label">Include archived articles</span>
                <div class="toggle-switch">
                    <input type="checkbox" id="aiIncludeArchived">
                    <span class="toggle-slider"></span>
                </div>
            </label>
        </div>
        <div class="ai-chat-input-area">
            <textarea id="aiChatInput" placeholder="Ask a question about your knowledge base..." rows="2" onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); askAi();}"></textarea>
            <button class="ai-chat-send" onclick="askAi()" id="aiSendBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>
    </div>

    <!-- Link Copied Toast -->
    <div class="toast" id="linkCopiedToast">Link copied to clipboard!</div>

    <!-- html2pdf for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>window.API_BASE = '../api/knowledge/';</script>
    <script src="../assets/js/custom-alert.js?v=1"></script>
    <script src="../assets/js/knowledge.js?v=5"></script>
    <!-- Prism.js for code syntax highlighting when viewing articles -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-powershell.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-batch.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-csharp.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js"></script>
</body>
</html>
