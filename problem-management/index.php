<?php
/**
 * Problem Management — list, view and manage problems (root causes behind incidents).
 */
session_start();
require_once __DIR__ . '/../config.php';

$current_page = 'problems';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Problem Management</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <style>
        .pm-container { display: flex; height: calc(100vh - 48px); width: 100%; }
        .pm-sidebar { width: 250px; min-width: 250px; border-right: 1px solid #e5e7eb; background: #fafbfc; padding: 16px; overflow-y: auto; box-sizing: border-box; }
        .pm-sidebar h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; margin: 18px 0 8px; }
        .pm-search { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #cfd8dc; border-radius: 6px; font: inherit; }
        .pm-new-btn { display: block; width: 100%; box-sizing: border-box; text-align: center; margin-top: 14px; padding: 10px; background: #6a1b9a; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .pm-new-btn:hover { background: #581580; }
        .pm-filter { display: flex; justify-content: space-between; align-items: center; padding: 7px 10px; border-radius: 6px; cursor: pointer; font-size: 14px; color: #374151; }
        .pm-filter:hover { background: #f0e6f6; }
        .pm-filter.active { background: #ede7f6; color: #6a1b9a; font-weight: 600; }
        .pm-filter .cnt { background: #e5e7eb; border-radius: 10px; padding: 1px 8px; font-size: 12px; color: #555; }
        .pm-main { flex: 1; min-width: 0; overflow-y: auto; padding: 20px 24px; }
        .pm-list-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .pm-list-head h2 { margin: 0; font-size: 1.4rem; }
        .pm-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; cursor: pointer; background: #fff; }
        .pm-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.07); }
        .pm-card-top { display: flex; align-items: center; gap: 10px; }
        .pm-num { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #6b7280; }
        .pm-card-title { font-weight: 600; color: #1a1a1a; flex: 1; }
        .pm-badge { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 12px; color: #fff; white-space: nowrap; }
        .pm-meta { margin-top: 6px; font-size: 12px; color: #6b7280; display: flex; gap: 14px; flex-wrap: wrap; }
        .pm-ke { background: #fff3e0; color: #e65100; border-radius: 10px; padding: 1px 8px; font-size: 11px; font-weight: 600; }
        .pm-empty { color: #9ca3af; text-align: center; padding: 40px; }
        /* Detail */
        .pm-detail-head { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
        .pm-detail h1 { font-size: 1.4rem; margin: 0; flex: 1; }
        .pm-section { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; margin: 14px 0; background: #fff; }
        .pm-section h3 { margin: 0 0 10px; font-size: 1rem; color: #6a1b9a; }
        .pm-field-label { font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; margin-bottom: 3px; }
        .pm-field-val { margin-bottom: 12px; white-space: pre-wrap; }
        .pm-link-row { display: flex; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
        .pm-link-row a { color: #6a1b9a; text-decoration: none; font-weight: 600; }
        .pm-audit { font-size: 12px; color: #555; padding: 4px 0; border-bottom: 1px solid #f4f4f4; }
        /* Editor modal */
        .pm-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1000; align-items: flex-start; justify-content: center; overflow-y: auto; }
        .pm-modal.active { display: flex; }
        .pm-modal-content { background: #fff; border-radius: 10px; max-width: 720px; width: 92%; margin: 40px 0; padding: 0; }
        .pm-modal-head { padding: 16px 20px; border-bottom: 1px solid #eee; font-size: 1.1rem; font-weight: 600; }
        .pm-modal-body { padding: 20px; }
        .pm-modal-foot { padding: 14px 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
        .pm-form-row { margin-bottom: 14px; }
        .pm-form-row label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; color: #374151; }
        .pm-form-row input[type=text], .pm-form-row select, .pm-form-row textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #cfd8dc; border-radius: 6px; font: inherit; }
        .pm-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .pm-btn { padding: 9px 18px; border-radius: 6px; border: 1px solid #cfd8dc; background: #fff; cursor: pointer; font-weight: 600; }
        .pm-btn-primary { background: #6a1b9a; color: #fff; border-color: #6a1b9a; }
        .pm-btn-danger { color: #c62828; border-color: #e0a3a3; }
        .pm-ai-out { background: #f7f3fb; border: 1px dashed #c9a8e0; border-radius: 6px; padding: 10px 12px; margin-top: 8px; white-space: pre-wrap; font-size: 13px; display: none; }
    </style>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="pm-container">
        <div class="pm-sidebar">
            <input type="text" class="pm-search" id="pmSearch" placeholder="Search problems…" oninput="pmDebouncedSearch()">
            <button class="pm-new-btn" onclick="pmOpenEditor()">+ New problem</button>
            <h3>Status</h3>
            <div id="pmStatusFilters">
                <div class="pm-filter active" data-status="all" onclick="pmFilter('all')"><span>All</span><span class="cnt" id="pmCountAll">0</span></div>
            </div>
        </div>
        <div class="pm-main">
            <div id="pmListView">
                <div class="pm-list-head">
                    <h2>Problems</h2>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button class="pm-btn" onclick="pmSuggest()" title="Let AI scan recent open incidents for recurring patterns">🤖 Detect problems</button>
                        <div id="pmCount" style="color:#6b7280;"></div>
                    </div>
                </div>
                <div id="pmList"><div class="pm-empty">Loading…</div></div>
            </div>
            <div id="pmDetailView" style="display:none;"></div>
        </div>
    </div>

    <!-- Editor modal -->
    <div class="pm-modal" id="pmModal">
        <div class="pm-modal-content">
            <div class="pm-modal-head" id="pmModalTitle">New problem</div>
            <div class="pm-modal-body">
                <input type="hidden" id="pmId">
                <div class="pm-form-row"><label>Title *</label><input type="text" id="pmTitle" placeholder="Short summary of the underlying problem"></div>
                <div class="pm-grid2">
                    <div class="pm-form-row"><label>Status</label><select id="pmStatus"></select></div>
                    <div class="pm-form-row"><label>Priority</label><select id="pmPriority"></select></div>
                    <div class="pm-form-row"><label>Assigned to</label><select id="pmAssignee"></select></div>
                    <div class="pm-form-row"><label>&nbsp;</label><label style="font-weight:normal;"><input type="checkbox" id="pmKnownError"> Known error (workaround available)</label></div>
                </div>
                <div class="pm-form-row"><label>Description</label><textarea id="pmDescription" rows="3" placeholder="What's the problem?"></textarea></div>
                <div class="pm-form-row"><label>Root cause</label><textarea id="pmRootCause" rows="3" placeholder="The underlying cause (fill in as the investigation progresses)"></textarea></div>
                <div class="pm-form-row"><label>Workaround</label><textarea id="pmWorkaround" rows="2" placeholder="Temporary workaround for affected users"></textarea></div>
            </div>
            <div class="pm-modal-foot">
                <button class="pm-btn" onclick="pmCloseEditor()">Cancel</button>
                <button class="pm-btn pm-btn-primary" onclick="pmSave()">Save</button>
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

    <script src="<?php echo BASE_URL; ?>assets/js/problem-management.js?v=2"></script>
</body>
</html>
