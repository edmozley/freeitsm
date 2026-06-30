<?php
/**
 * Knowledge Base - Articles management and viewing
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

$current_page = 'knowledge';
$path_prefix = '../';
$translationNamespaces = ['common', 'knowledge'];

// Read the per-analyst sidebar preference server-side so the .sidebar-hover
// class is on the HTML from the first paint — avoids the flash where the
// 280px panel renders visible and then snaps shut once the JS lookup completes.
$sidebarMode = 'always';
if (isset($_SESSION['analyst_id'])) {
    try {
        $prefConn = connectToDatabase();
        $prefStmt = $prefConn->prepare(
            "SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = ? LIMIT 1"
        );
        $prefStmt->execute([(int)$_SESSION['analyst_id'], 'knowledge_sidebar_mode']);
        $prefRow = $prefStmt->fetch(PDO::FETCH_ASSOC);
        if ($prefRow && $prefRow['preference_value'] === 'hover') {
            $sidebarMode = 'hover';
        }
    } catch (Exception $e) {
        // Non-fatal — fall through with 'always' default
    }
}
$sidebarHoverClass = $sidebarMode === 'hover' ? ' sidebar-hover' : '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('knowledge.browser_title.main')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=9">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/knowledge.css?v=2">
    <!-- Prism.js for code syntax highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="knowledge-container<?php echo $sidebarHoverClass; ?>">
        <!-- Sidebar with search and tags -->
        <div class="knowledge-sidebar">
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('knowledge.sidebar.search_heading')); ?></h3>
                <div class="search-box">
                    <input type="text" id="articleSearch" placeholder="<?php echo htmlspecialchars(t('knowledge.sidebar.search_placeholder')); ?>" onkeyup="debounceSearch()">
                </div>
            </div>
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('knowledge.sidebar.tags_heading')); ?></h3>
                <div class="tag-filter-list" id="tagFilterList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
            <div class="sidebar-section">
                <button class="btn btn-primary btn-full" onclick="openCreateArticle()"><?php echo htmlspecialchars(t('knowledge.sidebar.new_article')); ?></button>
            </div>
            <div class="sidebar-section">
                <button class="btn btn-secondary btn-full recycle-bin-toggle" id="recycleBinToggle" onclick="toggleRecycleBin()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <?php echo htmlspecialchars(t('knowledge.sidebar.recycle_bin')); ?>
                </button>
            </div>
        </div>

        <!-- Main content area -->
        <div class="knowledge-main">
            <!-- Article list view -->
            <div class="article-list-view" id="articleListView">
                <div class="article-list-header">
                    <h2 id="articleListHeader"><?php echo htmlspecialchars(t('knowledge.list.heading')); ?></h2>
                    <div class="article-count" id="articleCount"></div>
                </div>
                <div class="article-list" id="articleList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>

            <!-- Article detail view -->
            <div class="article-detail-view" id="articleDetailView" style="display: none;">
                <div class="article-detail-header">
                    <a class="btn btn-secondary" href="./"><?php echo htmlspecialchars(t('knowledge.detail.back')); ?></a>
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
                                <?php echo htmlspecialchars(t('knowledge.detail.share')); ?>
                            </button>
                            <div class="share-dropdown-menu" id="shareDropdownMenu">
                                <a href="#" onclick="shareArticleLink(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                    </svg>
                                    <?php echo htmlspecialchars(t('knowledge.share.link')); ?>
                                </a>
                                <a href="#" onclick="shareArticlePdf(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                        <polyline points="10 9 9 9 8 9"></polyline>
                                    </svg>
                                    <?php echo htmlspecialchars(t('knowledge.share.pdf')); ?>
                                </a>
                                <a href="#" onclick="shareArticleBoth(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                    <?php echo htmlspecialchars(t('knowledge.share.email')); ?>
                                </a>
                            </div>
                        </div>
                        <button class="btn btn-primary" onclick="editCurrentArticle()"><?php echo htmlspecialchars(t('knowledge.detail.edit')); ?></button>
                        <button class="btn btn-danger" onclick="deleteCurrentArticle()"><?php echo htmlspecialchars(t('knowledge.detail.archive')); ?></button>
                    </div>
                </div>
                <div class="article-content" id="articleContent"></div>
            </div>

            <!-- Article editor view -->
            <div class="article-editor-view" id="articleEditorView" style="display: none;">
                <div class="editor-scroll">
                    <div class="editor-header">
                        <h2 id="editorTitle"><?php echo htmlspecialchars(t('knowledge.editor.new_title')); ?></h2>
                        <button class="icon-btn editor-popout-toggle" onclick="toggleEditorPopout()" title="<?php echo htmlspecialchars(t('knowledge.editor.popout_title')); ?>" aria-label="<?php echo htmlspecialchars(t('knowledge.editor.popout_title')); ?>">
                            <svg class="popout-icon-expand" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                            <svg class="popout-icon-contract" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                        </button>
                    </div>
                    <div class="editor-form">
                        <input type="hidden" id="editArticleId" value="">
                        <!-- Property fields. Wrapped so popout mode can reflow
                             them into a right-hand panel via CSS only. -->
                        <div class="editor-properties">
                            <div class="form-row" style="display: flex; gap: 20px;">
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_title')); ?></label>
                                    <input type="text" class="form-input" id="articleTitle" placeholder="<?php echo htmlspecialchars(t('knowledge.editor.title_placeholder')); ?>">
                                </div>
                                <div class="form-group tag-form-group" style="flex: 1;">
                                    <div class="tag-label-row">
                                        <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_tags')); ?> <small style="display: inline; margin-top: 0; font-weight: normal; color: var(--text-dim, #888);"><?php echo htmlspecialchars(t('knowledge.editor.tags_hint')); ?></small></label>
                                        <div class="selected-tags" id="selectedTags"></div>
                                    </div>
                                    <div class="tag-input-container">
                                        <input type="text" class="tag-input" id="tagInput" placeholder="<?php echo htmlspecialchars(t('knowledge.editor.tags_placeholder')); ?>">
                                        <div class="tag-suggestions" id="tagSuggestions"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-row" style="display: flex; gap: 20px;">
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_owner')); ?></label>
                                    <select class="form-input" id="articleOwner">
                                        <option value=""><?php echo htmlspecialchars(t('knowledge.editor.owner_none')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_review')); ?></label>
                                    <input type="date" class="form-input" id="articleReviewDate">
                                </div>
                            </div>
                        </div>
                        <div class="editor-content">
                            <div class="form-group">
                                <textarea id="articleBody"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="editor-actions">
                    <button class="btn btn-secondary" onclick="cancelEdit()"><?php echo htmlspecialchars(t('knowledge.editor.cancel')); ?></button>
                    <button class="btn btn-primary" onclick="saveArticle()"><?php echo htmlspecialchars(t('knowledge.editor.save')); ?></button>
                    <button class="btn btn-primary" id="btnSaveAsVersion" onclick="saveAsNewVersion()" style="display:none;"><?php echo htmlspecialchars(t('knowledge.editor.version')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Email Modal -->
    <div class="modal" id="shareEmailModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><?php echo htmlspecialchars(t('knowledge.modal.share_title')); ?></h3>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.modal.recipient')); ?></label>
                    <input type="email" class="form-input" id="shareEmailTo" placeholder="<?php echo htmlspecialchars(t('knowledge.modal.recipient_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.modal.message')); ?></label>
                    <textarea class="form-textarea" id="shareEmailMessage" rows="3" placeholder="<?php echo htmlspecialchars(t('knowledge.modal.message_placeholder')); ?>"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.modal.include')); ?></label>
                    <div style="display: flex; gap: 20px; margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludeLink" checked> <?php echo htmlspecialchars(t('knowledge.modal.include_link')); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludePdf" checked> <?php echo htmlspecialchars(t('knowledge.modal.include_pdf')); ?>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeShareEmailModal()"><?php echo htmlspecialchars(t('knowledge.modal.cancel')); ?></button>
                <button class="btn btn-primary" onclick="sendShareEmail()"><?php echo htmlspecialchars(t('knowledge.modal.send')); ?></button>
            </div>
        </div>
    </div>

    <!-- Archived Article Preview Modal -->
    <div class="modal" id="archivedArticleModal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 id="archivedArticleTitle" style="margin: 0;"></h3>
            </div>
            <div class="modal-body">
                <div id="archivedArticleMeta" style="font-size: 13px; color: var(--text-muted, #666); margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border, #e0e0e0);"></div>
                <div id="archivedArticleBody" class="article-content-body"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeArchivedArticleModal()"><?php echo htmlspecialchars(t('knowledge.modal.close')); ?></button>
            </div>
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
                <?php echo htmlspecialchars(t('knowledge.ai.title')); ?>
            </div>
            <button class="ai-chat-close" onclick="closeAiChat()">&times;</button>
        </div>
        <div class="ai-chat-messages" id="aiChatMessages">
            <div class="ai-chat-welcome">
                <p><?php echo htmlspecialchars(t('knowledge.ai.welcome')); ?></p>
                <p style="font-size:12px; color:var(--text-faint, #999); margin-top:8px;"><?php echo htmlspecialchars(t('knowledge.ai.powered_by')); ?></p>
            </div>
        </div>
        <div class="ai-chat-options">
            <label class="ai-archive-toggle" title="<?php echo htmlspecialchars(t('knowledge.ai.include_archived_title')); ?>">
                <span class="toggle-label"><?php echo htmlspecialchars(t('knowledge.ai.include_archived')); ?></span>
                <div class="toggle-switch">
                    <input type="checkbox" id="aiIncludeArchived">
                    <span class="toggle-slider"></span>
                </div>
            </label>
        </div>
        <div class="ai-chat-input-area">
            <textarea id="aiChatInput" placeholder="<?php echo htmlspecialchars(t('knowledge.ai.input_placeholder')); ?>" rows="2" onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); askAi();}"></textarea>
            <button class="ai-chat-send" onclick="askAi()" id="aiSendBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>
    </div>

    <!-- Link Copied Toast -->

    <!-- jsPDF for searchable PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>window.API_BASE = '../api/knowledge/';</script>
    <script src="../assets/js/knowledge.js?v=12"></script>
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
