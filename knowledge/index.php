<?php
/**
 * Knowledge Base - Articles management and viewing
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('knowledge');

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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('knowledge.browser_title.main')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/knowledge.css?v=26">
    <!-- Prism.js for code syntax highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
    <!-- Mobile-friendly overrides (LAYER 17). Linked LAST so its @media rules win ties against knowledge.css. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=133">
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
            <!-- Folders. One tree and one list is what Explorer actually is;
                 three view modes would be three renderers to build, test, theme
                 and mobile-proof for one module. -->
            <div class="sidebar-section" id="kbFolderSection">
                <h3>
                    <?php echo htmlspecialchars(t('knowledge.sidebar.folders_heading')); ?>
                    <button type="button" class="kb-folder-add" id="kbFolderAdd" onclick="createFolderPrompt()" title="<?php echo htmlspecialchars(t('knowledge.folders.new')); ?>">+</button>
                </h3>
                <div class="kb-folder-tree" id="kbFolderTree">
                    <div class="loading"><div class="spinner"></div></div>
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
            <!-- Shown only to someone who may manage access; see knowledge.js. -->
            <div class="sidebar-section" id="kbExceptionsSection" style="display:none;">
                <button class="btn btn-secondary btn-full" onclick="openExceptionsModal()"><?php echo htmlspecialchars(t('knowledge.exceptions.button')); ?></button>
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
                <!-- Where you are, when you are inside a folder. Hidden at "All
                     articles", which is not a place you can be inside. -->
                <nav class="kb-breadcrumb" id="kbBreadcrumb" style="display:none;"></nav>
                <div class="article-list-header">
                    <h2 id="articleListHeader"><?php echo htmlspecialchars(t('knowledge.list.heading')); ?></h2>
                    <div class="article-count" id="articleCount"></div>
                    <!-- How the main pane draws things. A toggle rather than only
                         a setting: unlike WHERE you browse, this is something
                         people flip during a session. It persists all the same. -->
                    <div class="kb-layout-toggle">
                        <button type="button" class="kb-layout-btn active" data-layout="list" onclick="setLayout('list')" title="<?php echo htmlspecialchars(t('knowledge.layout.list')); ?>">☰</button>
                        <button type="button" class="kb-layout-btn" data-layout="cards" onclick="setLayout('cards')" title="<?php echo htmlspecialchars(t('knowledge.layout.cards')); ?>">▦</button>
                        <button type="button" class="kb-layout-btn" data-layout="tree" onclick="setLayout('tree')" title="<?php echo htmlspecialchars(t('knowledge.layout.tree')); ?>">🌳</button>
                        <button type="button" class="kb-layout-btn" data-layout="details" onclick="setLayout('details')" title="<?php echo htmlspecialchars(t('knowledge.layout.details')); ?>">▤</button>
                    </div>
                </div>

                <!-- Bulk audience bar. Hidden until something is ticked; selection
                     survives searching and tag-filtering (see knowledge.js). -->
                <div class="kb-bulk-bar" id="kbBulkBar" style="display:none;">
                    <span class="kb-bulk-count" id="kbBulkCount"></span>
                    <label for="kbBulkAudience"><?php echo htmlspecialchars(t('knowledge.bulk.set_to')); ?></label>
                    <select id="kbBulkAudience">
                        <option value="internal"><?php echo htmlspecialchars(t('knowledge.editor.audience_internal')); ?></option>
                        <option value="customer"><?php echo htmlspecialchars(t('knowledge.editor.audience_customer')); ?></option>
                        <option value="public"><?php echo htmlspecialchars(t('knowledge.editor.audience_public')); ?></option>
                    </select>
                    <button type="button" class="btn btn-primary" id="kbBulkApply" onclick="applyBulkAudience()"><?php echo htmlspecialchars(t('knowledge.bulk.apply')); ?></button>
                    <label for="kbBulkFolder"><?php echo htmlspecialchars(t('knowledge.bulk.move_to')); ?></label>
                    <select id="kbBulkFolder"></select>
                    <button type="button" class="btn btn-primary" id="kbBulkMove" onclick="applyBulkMove()"><?php echo htmlspecialchars(t('knowledge.bulk.move')); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="selectAllVisibleArticles()"><?php echo htmlspecialchars(t('knowledge.bulk.select_all')); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="clearArticleSelection()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
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
                                <span class="kb-act-label"><?php echo htmlspecialchars(t('knowledge.detail.share')); ?></span>
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
                        <!-- Shown only to someone who may actually change access;
                             see kbCanManagePerms in knowledge.js. -->
                        <button class="btn btn-secondary" onclick="kbMoveCurrentArticle()" title="<?php echo htmlspecialchars(t('knowledge.move.title')); ?>"><span class="kb-act-icon" aria-hidden="true">📁</span><span class="kb-act-label"><?php echo htmlspecialchars(t('knowledge.move.button')); ?></span></button>
                        <button class="btn btn-secondary" id="kbArticleAuditBtn" style="display:none;" onclick="openArticleAuditModal()" title="<?php echo htmlspecialchars(t('knowledge.audit.title')); ?>"><span class="kb-act-icon" aria-hidden="true">🕘</span><span class="kb-act-label"><?php echo htmlspecialchars(t('knowledge.audit.button')); ?></span></button>
                        <button class="btn btn-secondary" id="kbArticlePermBtn" style="display:none;" onclick="openArticlePermModal()"><span class="kb-act-icon" aria-hidden="true">🔒</span><span class="kb-act-label"><?php echo htmlspecialchars(t('knowledge.perm.manage')); ?></span></button>
                        <button class="btn btn-primary" onclick="editCurrentArticle()"><span class="kb-act-icon" aria-hidden="true">✏️</span><span class="kb-act-label"><?php echo htmlspecialchars(t('knowledge.detail.edit')); ?></span></button>
                        <button class="btn btn-danger" onclick="deleteCurrentArticle()"><span class="kb-act-icon" aria-hidden="true">🗑️</span><span class="kb-act-label"><?php echo htmlspecialchars(t('knowledge.detail.archive')); ?></span></button>
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
                            <div class="form-row" style="display: flex; gap: 20px;">
                                <!-- Who can see this. Always shown: it is orthogonal to
                                     multi-company, and on a single-company install it is
                                     still the only thing between an internal runbook and
                                     an anonymous web chat visitor. -->
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_audience')); ?></label>
                                    <!-- TWO choices plus an opt-in, not three choices.
                                         Reaching the open internet is a different KIND of
                                         decision from the other two, and as the third item
                                         in a dropdown it looked like their peer. As a
                                         tickbox it looks like what it is: a deliberate act.
                                         The stored value is unchanged — ticked means
                                         audience='public'. See knowledge.js. -->
                                    <select class="form-input" id="articleAudience">
                                        <option value="internal"><?php echo htmlspecialchars(t('knowledge.editor.audience_internal')); ?></option>
                                        <option value="customer"><?php echo htmlspecialchars(t('knowledge.editor.audience_customer')); ?></option>
                                    </select>
                                    <label class="kb-audience-public" for="articleAudiencePublic">
                                        <input type="checkbox" id="articleAudiencePublic" onchange="updateAudienceHint()">
                                        <span><?php echo htmlspecialchars(t('knowledge.editor.audience_public_toggle')); ?></span>
                                    </label>
                                    <!-- Which channels that actually reaches, shown only when
                                         ticked. Separate from the label on purpose: the label
                                         states the consequence and never changes, this names
                                         today's channels and gets updated as they are added. -->
                                    <small class="field-hint" id="audiencePublicChannels" style="display:none;"><?php echo htmlspecialchars(t('knowledge.editor.audience_public_channels')); ?></small>
                                    <small class="field-hint" id="audienceHint"></small>
                                </div>
                                <!-- Which folder it is filed in. One folder, never
                                     several — see the design page. -->
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_folder')); ?></label>
                                    <select class="form-input" id="articleFolder">
                                        <option value=""><?php echo htmlspecialchars(t('knowledge.folders.root')); ?></option>
                                    </select>
                                    <small class="field-hint"><?php echo htmlspecialchars(t('knowledge.editor.folder_hint')); ?></small>
                                </div>
                                <!-- Company. Hidden unless the install has more than one
                                     (the isMultiTenant mirror) — invisible at N=1. -->
                                <div class="form-group" style="flex: 1; display: none;" id="articleCompanyGroup">
                                    <label class="form-label"><?php echo htmlspecialchars(t('knowledge.editor.field_company')); ?></label>
                                    <select class="form-input" id="articleCompany">
                                        <option value=""><?php echo htmlspecialchars(t('knowledge.editor.company_shared')); ?></option>
                                    </select>
                                    <small class="field-hint"><?php echo htmlspecialchars(t('knowledge.editor.company_hint')); ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="editor-content">
                            <div class="form-group">
                                <textarea id="articleBody"></textarea>
                            </div>
                        </div>
                        <!-- Attached documents. This is where adding and removing
                             them lives; the article page itself only LISTS them.
                             Hidden until the article exists, because an
                             attachment needs something to attach to. -->
                        <div class="editor-documents" id="kbEditorDocumentsWrap">
                            <div id="kbEditorDocuments"></div>
                            <p class="field-hint" id="kbEditorDocumentsHint" style="display:none;"><?php echo htmlspecialchars(t('knowledge.editor.documents_after_save')); ?></p>
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
    <!-- Every tag on one article, from the "+N more" pill on a card. A dialog
         rather than expanding the pill in place: expanding reflows the card,
         which re-rags the grid the cap exists to keep even, and leaves no way to
         put it back. -->
    <div class="modal" id="kbTagsModal">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h3 id="kbTagsModalTitle"></h3>
            </div>
            <div class="modal-body">
                <div class="kb-tags-modal-list" id="kbTagsModalList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTagsModal()"><?php echo htmlspecialchars(t('knowledge.modal.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Asking for a name. A real dialog rather than the browser's prompt():
         the native one is unstyled, unbranded, says "freeitsm.internal says",
         cannot be translated, and looks like a phishing box in the middle of an
         otherwise finished product. -->
    <!-- Move one article. Until now an article could only be filed by dragging
         it or right-clicking it in the LIST - so from the article's own page,
         which is where you are when you notice it is in the wrong place, there
         was no way to move it at all. -->
    <div class="modal" id="kbMoveModal">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h3><?php echo htmlspecialchars(t('knowledge.move.title')); ?></h3>
            </div>
            <div class="modal-body">
                <select class="form-input" id="kbMoveFolder"></select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="kbCloseMoveModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" onclick="kbConfirmMoveArticle()"><?php echo htmlspecialchars(t('knowledge.bulk.move')); ?></button>
            </div>
        </div>
    </div>

    <div class="modal" id="kbPromptModal">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h3 id="kbPromptTitle"></h3>
            </div>
            <div class="modal-body">
                <input type="text" class="form-input" id="kbPromptInput" autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="kbPromptCancel()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="kbPromptOk" onclick="kbPromptAccept()"><?php echo htmlspecialchars(t('knowledge.editor.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Everything carrying its own permissions rather than its parent's.
         An exception is invisible from the tree, so without a list of them the
         count only goes up and nobody can audit it. -->
    <div class="modal" id="kbExceptionsModal">
        <div class="modal-content" style="max-width: 620px;">
            <div class="modal-header">
                <h3><?php echo htmlspecialchars(t('knowledge.exceptions.title')); ?></h3>
            </div>
            <div class="modal-body">
                <p class="field-hint"><?php echo htmlspecialchars(t('knowledge.exceptions.hint')); ?></p>
                <div class="kb-perm-list" id="kbExceptionsList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeExceptionsModal()"><?php echo htmlspecialchars(t('knowledge.modal.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Who can see this folder / article.
         A modal rather than a second tab on the left panel: the panel is 280px,
         and a principal search plus a list of people does not read at that
         width. The panel idiom is still open as a presentation change on top of
         exactly this. -->
    <div class="modal" id="kbPermModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header">
                <h3 id="kbPermTitle"><?php echo htmlspecialchars(t('knowledge.perm.title')); ?></h3>
            </div>
            <div class="modal-body">
                <label class="kb-perm-row" for="kbPermInherit">
                    <input type="checkbox" id="kbPermInherit" onchange="permSetMode()">
                    <span><?php echo htmlspecialchars(t('knowledge.perm.inherit')); ?></span>
                </label>
                <!-- What it inherits, when it inherits: read-only, because these
                     rules belong to the folder above and editing them here would
                     change what OTHER things see without saying so. -->
                <div id="kbPermInherited" style="display:none;"></div>
                <div id="kbPermOwnRules">
                    <label class="kb-perm-row" for="kbPermRestricted">
                        <input type="checkbox" id="kbPermRestricted" onchange="permSetMode(true)">
                        <span><?php echo htmlspecialchars(t('knowledge.perm.restrict')); ?></span>
                    </label>
                    <!-- The list heading changes with the polarity, because the list
                         means the opposite thing in each mode and a fixed heading
                         would be wrong half the time. -->
                    <p class="field-hint" id="kbPermExplain"></p>
                    <div class="kb-perm-list" id="kbPermList"></div>
                    <div class="form-group">
                        <input type="text" class="form-input" id="kbPermSearch"
                               placeholder="<?php echo htmlspecialchars(t('knowledge.perm.search_placeholder')); ?>"
                               onkeyup="permSearch()" autocomplete="off">
                        <div class="kb-perm-results" id="kbPermResults"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePermModal()"><?php echo htmlspecialchars(t('knowledge.modal.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- The history of one folder or article. Fixed height for the same reason
         the permissions window is: it is read repeatedly in one sitting, and a
         box that resizes itself as it loads moves the Close button under the
         cursor. -->
    <div class="modal" id="kbAuditModal">
        <div class="modal-content kb-audit-content">
            <div class="modal-header">
                <h3 id="kbAuditTitle"><?php echo htmlspecialchars(t('knowledge.audit.title')); ?></h3>
            </div>
            <div class="modal-body">
                <p class="field-hint"><?php echo htmlspecialchars(t('knowledge.audit.hint')); ?></p>
                <div class="kb-audit-list" id="kbAuditList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAuditModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

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
    <script src="../assets/js/knowledge.js?v=56"></script>
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
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
