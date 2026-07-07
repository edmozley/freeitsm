<?php
/**
 * Problem Management — list, view and manage problems (root causes behind incidents).
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/timezone.php';
Tz::init();

$current_page = 'problems';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Problem Management</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=16">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=37">
    <style>
        /* Pin the shared accent to the module red so shared components on this
           page (the editor modal's .btn-primary + input focus rings, confirm
           dialog) read on-brand. The red header gradient (.header.problems-header)
           is explicit and unaffected. */
        body { --accent: var(--pm-accent, #dc2626); --accent-hover: var(--pm-accent-hover, #b91c1c); }
        .pm-container { display: flex; height: calc(100vh - 48px); width: 100%; }
        .pm-sidebar { width: 250px; min-width: 250px; border-right: 1px solid var(--border, #e5e7eb); background: var(--surface-2, #fafbfc); padding: 16px; overflow-y: auto; box-sizing: border-box; }
        .pm-sidebar h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-dim, #6b7280); margin: 18px 0 8px; }
        .pm-search { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font: inherit; }
        .pm-new-btn { display: block; width: 100%; box-sizing: border-box; text-align: center; margin-top: 14px; padding: 10px; background: var(--pm-accent, #6a1b9a); color: var(--pm-on-accent, #fff); border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .pm-new-btn:hover { background: var(--pm-accent-hover, #581580); }
        /* Status list styled like the help guide's left-nav headings: rounded
           rows with a circular count badge, grey hover, accent-soft active
           state with a filled-accent badge. A small gap keeps a hovered row's
           highlight from touching the active (red) row above/below it. */
        #pmStatusFilters { display: flex; flex-direction: column; gap: 3px; }
        .pm-filter { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; color: var(--text-muted, #374151); transition: background .15s, color .15s; }
        .pm-filter:hover { background: var(--surface-hover, #f5f5f5); color: var(--text, #333); }
        .pm-filter.active { background: var(--pm-accent-soft, #fde8e8); color: var(--pm-accent, #dc2626); font-weight: 600; }
        .pm-filter .cnt { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; padding: 0 7px; box-sizing: border-box; border-radius: 999px; background: var(--border-soft, #eee); color: var(--text-dim, #888); font-weight: 700; font-size: 11px; flex-shrink: 0; }
        .pm-filter.active .cnt { background: var(--pm-accent, #dc2626); color: var(--pm-on-accent, #fff); }
        .pm-main { flex: 1; min-width: 0; overflow-y: auto; padding: 20px 24px; }
        .pm-list-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .pm-list-head h2 { margin: 0; font-size: 1.4rem; }
        .pm-card { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; cursor: pointer; background: var(--surface, #fff); }
        .pm-card:hover { box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,.07)); }
        .pm-card-top { display: flex; align-items: center; gap: 10px; }
        .pm-num { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: var(--text-dim, #6b7280); }
        .pm-card-title { font-weight: 600; color: var(--text, #1a1a1a); flex: 1; }
        .pm-badge { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 12px; color: #fff; white-space: nowrap; }
        .pm-meta { margin-top: 6px; font-size: 12px; color: var(--text-dim, #6b7280); display: flex; gap: 14px; flex-wrap: wrap; }
        .pm-ke { background: #fff3e0; color: #e65100; border-radius: 10px; padding: 1px 8px; font-size: 11px; font-weight: 600; }
        .pm-empty { color: var(--text-faint, #9ca3af); text-align: center; padding: 40px; }
        /* Detail */
        .pm-detail-head { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
        .pm-detail h1 { font-size: 1.4rem; margin: 0; flex: 1; }
        .pm-section { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 14px 16px; margin: 14px 0; background: var(--surface, #fff); }
        .pm-section h3 { margin: 0 0 10px; font-size: 1rem; color: var(--pm-accent, #6a1b9a); }
        .pm-field-label { font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-dim, #6b7280); margin-bottom: 3px; }
        .pm-field-val { margin-bottom: 12px; white-space: pre-wrap; }
        .pm-link-row { display: flex; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border-soft, #f0f0f0); }
        .pm-link-row a { color: var(--pm-accent, #6a1b9a); text-decoration: none; font-weight: 600; }
        .pm-icon-btn { background: none; border: none; cursor: pointer; color: var(--text-dim, #6b7280); padding: 4px; border-radius: 4px; display: inline-flex; align-items: center; line-height: 0; }
        .pm-icon-btn:hover { background: var(--surface-hover, #f0f0f0); color: var(--text-muted, #374151); }
        .pm-icon-btn.danger { color: var(--danger-accent, #c62828); }
        .pm-icon-btn.danger:hover { background: var(--danger-bg, #fdeaea); }
        .pm-icon-btn svg { width: 16px; height: 16px; }
        .pm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pm-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-dim, #6b7280); font-weight: 600; padding: 6px 10px; border-bottom: 2px solid var(--border-soft, #eee); }
        .pm-table td { padding: 7px 10px; border-bottom: 1px solid var(--border-soft, #f0f0f0); vertical-align: top; }
        .pm-table tr:last-child td { border-bottom: none; }
        .pm-table a { color: var(--pm-accent, #6a1b9a); text-decoration: none; font-weight: 600; }
        .pm-table .pm-actions { white-space: nowrap; text-align: right; width: 1%; }
        .pm-table .pm-when { white-space: nowrap; color: var(--text-dim, #6b7280); }
        .pm-empty-row td { color: var(--text-faint, #9ca3af); }
        /* Notes */
        .pm-note-add { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
        .pm-note-add textarea { flex: 1; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font: inherit; resize: vertical; }
        .pm-note { border-bottom: 1px solid var(--border-soft, #f0f0f0); padding: 9px 0; }
        .pm-note:last-child { border-bottom: none; }
        .pm-note-head { display: flex; gap: 10px; align-items: baseline; margin-bottom: 3px; }
        .pm-note-who { font-weight: 600; color: var(--text, #1a1a1a); font-size: 13px; }
        .pm-note-when { color: var(--text-dim, #6b7280); font-size: 12px; }
        .pm-note-body { white-space: pre-wrap; font-size: 13px; color: var(--text-muted, #374151); }
        .pm-audit { font-size: 12px; color: var(--text-muted, #555); padding: 4px 0; border-bottom: 1px solid var(--border-soft, #f4f4f4); }
        /* Editor modal */
        .pm-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1000; align-items: flex-start; justify-content: center; overflow-y: auto; }
        .pm-modal.active { display: flex; }
        .pm-modal-content { background: var(--surface, #fff); border-radius: 10px; max-width: 720px; width: 92%; margin: 40px 0; padding: 0; }
        .pm-modal-head { padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 1.1rem; font-weight: 600; }
        .pm-modal-body { padding: 20px; }
        .pm-modal-foot { padding: 14px 20px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; }
        .pm-form-row { margin-bottom: 14px; }
        .pm-form-row label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; color: var(--text-muted, #374151); }
        .pm-form-row input[type=text], .pm-form-row select, .pm-form-row textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font: inherit; }
        .pm-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .pm-btn { padding: 9px 18px; border-radius: 6px; border: 1px solid var(--border, #cfd8dc); background: var(--surface, #fff); cursor: pointer; font-weight: 600; }
        .pm-btn-primary { background: var(--pm-accent, #6a1b9a); color: var(--pm-on-accent, #fff); border-color: var(--pm-accent, #6a1b9a); }
        .pm-btn-danger { color: var(--danger-accent, #c62828); border-color: var(--danger-accent, #e0a3a3); }
        .pm-ai-out { background: var(--pm-accent-soft, #fde8e8); border: 1px dashed var(--pm-accent, #dc2626); border-radius: 6px; padding: 10px 12px; margin-top: 8px; white-space: pre-wrap; font-size: 13px; display: none; }
        /* Incident picker */
        .pm-pick-row { display: flex; gap: 12px; align-items: flex-start; padding: 10px 14px; border-bottom: 1px solid var(--border-soft, #f0f0f0); cursor: pointer; }
        .pm-pick-row:hover { background: var(--pm-accent-soft, #fde8e8); }
        .pm-pick-row input { margin-top: 3px; }
        .pm-pick-main { flex: 1; min-width: 0; }
        .pm-pick-title { font-weight: 600; color: var(--text, #1a1a1a); }
        .pm-pick-meta { font-size: 12px; color: var(--text-dim, #6b7280); margin-top: 2px; }
        .pm-pick-num { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: var(--text-dim, #6b7280); }
        /* Sidebar Search button — opens the draggable search modal (mirrors
           the Change Management search). The modal itself uses the shared
           .search-modal styling from inbox.css (header picks up --accent = red). */
        .search-btn { width: 100%; box-sizing: border-box; padding: 8px 12px; background: var(--surface, #fff); color: var(--text-muted, #374151); border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font: inherit; font-weight: 600; cursor: pointer; transition: border-color .15s, color .15s; }
        .search-btn:hover { border-color: var(--pm-accent, #dc2626); color: var(--pm-accent, #dc2626); }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=1"></script>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="pm-container">
        <div class="pm-sidebar">
            <button class="search-btn" onclick="pmOpenSearchModal()">Search</button>
            <button class="pm-new-btn" onclick="pmOpenEditor()">+ New problem</button>
            <h3>Status</h3>
            <div id="pmStatusFilters">
                <div class="pm-filter active" data-status="all" onclick="pmFilter('all')"><span>All</span><span class="cnt" id="pmCountAll">0</span></div>
            </div>
        </div>
        <div class="pm-main">
            <div id="pmListView">
                <div class="pm-list-head">
                    <h2><?php echo htmlspecialchars(t('common.modules.problems.name')); ?></h2>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button class="pm-btn" onclick="pmSuggest()" title="Let AI scan recent open incidents for recurring patterns">🤖 Detect problems</button>
                        <div id="pmCount" style="color:var(--text-dim, #6b7280);"></div>
                    </div>
                </div>
                <div id="pmList"><div class="pm-empty">Loading…</div></div>
            </div>
            <div id="pmDetailView" style="display:none;"></div>
        </div>
    </div>

    <!-- Search modal (draggable) — mirrors the Change Management search.
         Uses the shared .search-modal styling from inbox.css. -->
    <div class="search-modal" id="pmSearchModal">
        <div class="search-modal-header" id="pmSearchModalHeader">
            <span>Search problems</span>
            <button class="search-modal-close" onclick="pmCloseSearchModal()">&times;</button>
        </div>
        <div class="search-modal-body">
            <div class="search-form">
                <div class="search-field">
                    <label>Problem number</label>
                    <input type="text" id="pmSearchNumber" placeholder="e.g. PRB-0001" onkeydown="if(event.key==='Enter')pmPerformSearch()">
                </div>
                <div class="search-field">
                    <label>Title</label>
                    <input type="text" id="pmSearchTitle" placeholder="Search by title…" onkeydown="if(event.key==='Enter')pmPerformSearch()">
                </div>
                <div class="search-actions">
                    <button class="btn btn-primary" onclick="pmPerformSearch()">Search</button>
                    <button class="btn btn-secondary" onclick="pmClearSearch()">Clear</button>
                </div>
            </div>
            <div class="search-results" id="pmSearchResults">
                <div class="search-results-empty">Enter a problem number or title above and press Search.</div>
            </div>
        </div>
    </div>

    <!-- Editor modal — uses the shared inbox.css .modal primitives so its
         chrome (header, body, footer, form fields, buttons) matches the
         Settings modals exactly. -->
    <div class="modal" id="pmModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="pmModalTitle">New problem</div>
            <div class="modal-body">
                <input type="hidden" id="pmId">
                <div class="form-group"><label>Title *</label><input type="text" id="pmTitle" placeholder="Short summary of the underlying problem"></div>
                <div class="form-row">
                    <div class="form-group"><label>Status</label><select id="pmStatus"></select></div>
                    <div class="form-group"><label>Priority</label><select id="pmPriority"></select></div>
                    <div class="form-group"><label>Assigned to</label><select id="pmAssignee"></select></div>
                    <div class="form-group"><label>&nbsp;</label><label style="font-weight:normal;"><input type="checkbox" id="pmKnownError"> Known error (workaround available)</label></div>
                </div>
                <div class="form-group"><label>Description</label><textarea id="pmDescription" rows="3" placeholder="What's the problem?"></textarea></div>
                <div class="form-group"><label>Root cause</label><textarea id="pmRootCause" rows="3" placeholder="The underlying cause (fill in as the investigation progresses)"></textarea></div>
                <div class="form-group"><label>Workaround</label><textarea id="pmWorkaround" rows="2" placeholder="Temporary workaround for affected users"></textarea></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="pmCloseEditor()">Cancel</button>
                <button class="btn btn-primary" onclick="pmSave()">Save</button>
            </div>
        </div>
    </div>

    <!-- Link-incident picker modal -->
    <div class="pm-modal" id="pmLinkModal">
        <div class="pm-modal-content" style="max-width: 780px;">
            <div class="pm-modal-head">Link incidents to this problem</div>
            <div class="pm-modal-body">
                <input type="text" id="pmLinkSearch" class="pm-search" placeholder="Search open incidents by number or subject…" oninput="pmLinkSearchDebounced()" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border, #cfd8dc);border-radius:6px;">
                <div id="pmLinkList" style="margin-top:12px; max-height:52vh; overflow-y:auto; border:1px solid var(--border-soft, #eee); border-radius:8px;"><div class="pm-empty">Loading…</div></div>
            </div>
            <div class="pm-modal-foot">
                <label style="margin-right:auto; font-size:13px; color:var(--text-muted, #555); display:flex; align-items:center; gap:6px;"><input type="checkbox" id="pmLinkAll" onchange="pmToggleAllLinkable(this.checked)"> Select all</label>
                <button class="pm-btn" onclick="document.getElementById('pmLinkModal').classList.remove('active')">Cancel</button>
                <button class="pm-btn pm-btn-primary" id="pmLinkSelBtn" onclick="pmLinkSelected()">Link selected</button>
            </div>
        </div>
    </div>

    <!-- Link-change picker modal -->
    <div class="pm-modal" id="pmLinkChangeModal">
        <div class="pm-modal-content" style="max-width: 780px;">
            <div class="pm-modal-head">Link the change that fixes this problem</div>
            <div class="pm-modal-body">
                <input type="text" id="pmLinkChangeSearch" class="pm-search" placeholder="Search changes by title or ID…" oninput="pmLinkChangeSearchDebounced()" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border, #cfd8dc);border-radius:6px;">
                <div id="pmLinkChangeList" style="margin-top:12px; max-height:52vh; overflow-y:auto; border:1px solid var(--border-soft, #eee); border-radius:8px;"><div class="pm-empty">Loading…</div></div>
            </div>
            <div class="pm-modal-foot">
                <label style="margin-right:auto; font-size:13px; color:var(--text-muted, #555); display:flex; align-items:center; gap:6px;"><input type="checkbox" id="pmLinkChangeAll" onchange="pmToggleAllLinkableChanges(this.checked)"> Select all</label>
                <button class="pm-btn" onclick="document.getElementById('pmLinkChangeModal').classList.remove('active')">Cancel</button>
                <button class="pm-btn pm-btn-primary" id="pmLinkChangeSelBtn" onclick="pmLinkChangeSelected()">Link selected</button>
            </div>
        </div>
    </div>

    <!-- AI suggestions modal -->
    <div class="pm-modal" id="pmSuggestModal">
        <div class="pm-modal-content">
            <div class="pm-modal-head">Suggested problems</div>
            <div class="pm-modal-body" id="pmSuggestBody"></div>
            <div class="pm-modal-foot"><button class="pm-btn" onclick="document.getElementById('pmSuggestModal').classList.remove('active')">Close</button></div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/confirm.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/problem-management.js?v=16"></script>
</body>
</html>
