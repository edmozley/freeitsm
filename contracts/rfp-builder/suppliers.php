<?php
/**
 * RFP Builder — invited suppliers (Phase 5 step 5a).
 * Lists every supplier invited to evaluate this RFP, with a picker
 * over the existing suppliers table for adding more, and an inline
 * "create prospective" form for suppliers not yet in FreeITSM.
 * Demo dates and notes editable per row. Removing an invitation
 * also clears any submitted scores for that supplier (the scoring
 * page lands in 5b).
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix  = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Suppliers</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }
        .page-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span.sep { margin: 0 6px; color: #ccc; }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #222; }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; font-family: inherit;
        }
        .btn-primary { background: #f59e0b; color: white; }
        .btn-primary:hover:not(:disabled) { background: #d97706; }
        .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }
        .btn-danger { background: white; color: #ef4444; border-color: #fca5a5; }
        .btn-danger:hover { background: #fef2f2; }

        .empty-card {
            background: white; border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .empty-card p { color: #666; margin: 6px 0; }

        .supplier-list {
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .supplier-row {
            padding: 14px 22px; border-bottom: 1px solid #f0f0f0;
            display: grid; grid-template-columns: 1fr 160px 1fr auto;
            gap: 18px; align-items: start;
        }
        .supplier-row:last-child { border-bottom: none; }
        .supplier-name { font-size: 15px; font-weight: 600; color: #222; }
        .supplier-name .trading {
            font-size: 12px; color: #888; font-weight: 400; margin-top: 2px;
        }
        .supplier-name .status-pill {
            display: inline-block; padding: 1px 7px; border-radius: 9px;
            font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
            background: #eef0f2; color: #555;
            margin-left: 8px;
        }
        .supplier-name .status-pill.prospective { background: #fef3c7; color: #92400e; }
        .supplier-name .invited-at {
            font-size: 11px; color: #aaa; margin-top: 4px;
        }

        .supplier-field label {
            display: block; font-size: 11px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
            margin-bottom: 3px;
        }
        .supplier-field input[type="date"],
        .supplier-field textarea {
            width: 100%; padding: 6px 10px; font-size: 13px;
            border: 1px solid #d1d5db; border-radius: 5px;
            font-family: inherit;
        }
        .supplier-field textarea { resize: vertical; min-height: 38px; }
        .supplier-field .save-status {
            font-size: 11px; color: #888; margin-top: 4px; min-height: 14px;
        }
        .supplier-field .save-status.saving { color: #b45309; }
        .supplier-field .save-status.saved  { color: #047857; }
        .supplier-field .save-status.error  { color: #b91c1c; }

        .supplier-actions {
            display: flex; flex-direction: column; gap: 6px;
        }

        /* Modal — picker / create */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: flex; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-shell {
            background: white; border-radius: 12px; width: 600px; max-width: 92vw;
            max-height: 86vh; display: flex; flex-direction: column;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25); overflow: hidden;
        }
        .modal-header {
            padding: 14px 22px; border-bottom: 1px solid #eee;
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #222; }
        .modal-header .close-x {
            background: none; border: none; font-size: 22px; color: #888; cursor: pointer; padding: 0; line-height: 1;
        }
        .modal-body { padding: 18px 22px; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 12px 22px; border-top: 1px solid #eee;
            display: flex; justify-content: flex-end; gap: 8px;
        }

        .modal-tabs { display: flex; gap: 0; border-bottom: 1px solid #eee; }
        .modal-tab {
            flex: 1; padding: 10px 14px; font-size: 13px; font-weight: 500;
            color: #555; background: white; border: none; cursor: pointer;
            border-bottom: 2px solid transparent;
            font-family: inherit;
        }
        .modal-tab:hover { background: #fafafa; }
        .modal-tab.active { color: #f59e0b; border-bottom-color: #f59e0b; font-weight: 600; }

        .form-row { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .form-row label {
            font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .form-row .help { font-size: 12px; color: #888; }
        .form-row input[type="text"],
        .form-row input[type="date"],
        .form-row select,
        .form-row textarea {
            padding: 8px 10px; font-size: 14px; font-family: inherit;
            border: 1px solid #d1d5db; border-radius: 6px;
            color: #222; background: white;
        }
        .form-row textarea { resize: vertical; min-height: 60px; line-height: 1.5; }
        .form-row-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }

        .loading, .error-state { text-align: center; padding: 40px; color: #999; }
        .error-state { color: #d13438; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span>Suppliers</span>
        </div>

        <div class="page-header">
            <h1>Suppliers</h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; Overview</a>
                <button id="inviteBtn" class="btn btn-primary" onclick="openInviteModal()">+ Add supplier</button>
            </div>
        </div>

        <div id="loadingEl" class="loading">Loading…</div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <!-- Invite supplier modal — two tabs: pick existing, or create new -->
    <div id="inviteModal" class="modal-backdrop" style="display:none;">
        <div class="modal-shell">
            <div class="modal-header">
                <h3>Add supplier</h3>
                <button class="close-x" onclick="closeInviteModal()">&times;</button>
            </div>
            <div class="modal-tabs">
                <button class="modal-tab active" id="tabExisting" onclick="setInviteTab('existing')">Pick existing</button>
                <button class="modal-tab" id="tabNew" onclick="setInviteTab('new')">Create prospective</button>
            </div>
            <div class="modal-body">
                <!-- Existing supplier picker -->
                <div id="existingPane">
                    <div class="form-row">
                        <label for="pickSupplier">Supplier</label>
                        <select id="pickSupplier">
                            <option value="">— Select a supplier —</option>
                        </select>
                        <div class="help">Pulled from your existing suppliers list. Suppliers already invited to this RFP are hidden.</div>
                    </div>
                    <div class="form-row-grid">
                        <div class="form-row">
                            <label for="pickDemoDate">Demo date (optional)</label>
                            <input type="date" id="pickDemoDate">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="pickNotes">Notes (optional)</label>
                        <textarea id="pickNotes" rows="3" placeholder="Anything specific to this RFP — pricing approach, key contact, etc."></textarea>
                    </div>
                </div>

                <!-- Create prospective supplier -->
                <div id="newPane" style="display:none;">
                    <div class="form-row">
                        <label for="newLegalName">Supplier name</label>
                        <input type="text" id="newLegalName" placeholder="e.g. ServiceNow Limited" maxlength="255">
                        <div class="help">The legal name as you'd write it on a contract. Required.</div>
                    </div>
                    <div class="form-row">
                        <label for="newTradingName">Trading name (optional)</label>
                        <input type="text" id="newTradingName" placeholder="e.g. ServiceNow" maxlength="255">
                    </div>
                    <div class="form-row">
                        <label for="newComments">Comments (optional)</label>
                        <textarea id="newComments" rows="2" placeholder="Anything you know about them — referrer, prior conversations, etc."></textarea>
                    </div>
                    <hr style="border:none; border-top:1px solid #eee; margin:14px 0;">
                    <div class="form-row-grid">
                        <div class="form-row">
                            <label for="newDemoDate">Demo date (optional)</label>
                            <input type="date" id="newDemoDate">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="newNotes">RFP notes (optional)</label>
                        <textarea id="newNotes" rows="2"></textarea>
                    </div>
                    <div class="help" style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;color:#92400e;font-size:12px;">
                        This creates a new entry in the suppliers list (status: Prospective if that lookup exists) and adds them to this RFP. You can flesh out the supplier record later from the Suppliers module.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeInviteModal()">Cancel</button>
                <button class="btn btn-primary" id="inviteSaveBtn" onclick="saveInvite()">Add</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let inviteTab = 'existing';
        // Per-row autosave timers, keyed by invitation id and field name.
        const saveTimers = {};

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError('No RFP id supplied. <a href="./">Back to list</a>.');
                return;
            }
            document.getElementById('backLink').href = 'view.php?id=' + encodeURIComponent(rfpId);
            loadAll();
        });

        async function loadAll() {
            try {
                const [rfpRes, invRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_invited_suppliers.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success) throw new Error(rfpRes.error || 'Failed to load RFP');
                if (!invRes.success) throw new Error(invRes.error || 'Failed to load suppliers');

                const bc = document.getElementById('bcRfp');
                bc.textContent = rfpRes.rfp.name;
                bc.href = 'view.php?id=' + encodeURIComponent(rfpId);

                renderInvited(invRes.invited);
                document.getElementById('loadingEl').style.display = 'none';
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function renderInvited(rows) {
            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';

            if (!rows || rows.length === 0) {
                contentEl.innerHTML = `
                    <div class="empty-card">
                        <p><strong>No suppliers added yet.</strong></p>
                        <p>Click <em>+ Add supplier</em> to add one from your existing suppliers list, or create a new prospective supplier inline.</p>
                    </div>
                `;
                return;
            }

            contentEl.innerHTML = '<div class="supplier-list">' +
                rows.map(r => renderSupplierRow(r)).join('') +
                '</div>';
        }

        function renderSupplierRow(r) {
            const display    = r.display_name || r.legal_name;
            const tradingExtra = (r.trading_name && r.trading_name !== r.legal_name)
                ? '<div class="trading">Legal: ' + escapeHtml(r.legal_name) + '</div>'
                : '';
            const statusKey = (r.status_name || '').toLowerCase();
            const statusPill = r.status_name
                ? '<span class="status-pill ' + escapeHtml(statusKey) + '">' + escapeHtml(r.status_name) + '</span>'
                : '';
            const invited = r.invited_datetime
                ? '<div class="invited-at">Added ' + escapeHtml(formatDate(r.invited_datetime)) + '</div>'
                : '';

            return `
                <div class="supplier-row" data-id="${r.id}">
                    <div>
                        <div class="supplier-name">
                            ${escapeHtml(display)}
                            ${statusPill}
                        </div>
                        ${tradingExtra}
                        ${invited}
                    </div>
                    <div class="supplier-field">
                        <label>Demo date</label>
                        <input type="date" value="${r.demo_date ? escapeHtml(r.demo_date) : ''}" onchange="onFieldChange(${r.id}, 'demo_date', this.value)">
                        <div class="save-status" id="ss-${r.id}-demo_date"></div>
                    </div>
                    <div class="supplier-field">
                        <label>Notes</label>
                        <textarea rows="2" oninput="onFieldChange(${r.id}, 'notes', this.value)" placeholder="…">${escapeHtml(r.notes || '')}</textarea>
                        <div class="save-status" id="ss-${r.id}-notes"></div>
                    </div>
                    <div class="supplier-actions">
                        <a class="btn btn-primary" href="scoring.php?id=${encodeURIComponent(rfpId)}&supplier=${r.supplier_id}">Score</a>
                        <button class="btn btn-danger" onclick="removeInvitation(${r.id})">Remove</button>
                    </div>
                </div>
            `;
        }

        // ─── Per-row autosave ──────────────────────────────────────

        function onFieldChange(invitationId, field, value) {
            const key = invitationId + '-' + field;
            const statusEl = document.getElementById('ss-' + invitationId + '-' + field);
            if (statusEl) {
                statusEl.textContent = 'saving…';
                statusEl.className = 'save-status saving';
            }
            clearTimeout(saveTimers[key]);
            // Debounce so a fast typist doesn't hammer the API on every
            // keystroke. Date inputs only fire onchange so this only
            // matters for the notes textarea.
            saveTimers[key] = setTimeout(() => saveField(invitationId, field, value), 600);
        }

        async function saveField(invitationId, field, value) {
            // Pull current values from the row's inputs to send the
            // whole payload in one call (the API takes both fields).
            const row = document.querySelector('.supplier-row[data-id="' + invitationId + '"]');
            if (!row) return;
            const dateEl = row.querySelector('input[type="date"]');
            const notesEl = row.querySelector('textarea');
            const statusEl = document.getElementById('ss-' + invitationId + '-' + field);
            try {
                const res = await fetch(API_BASE + 'update_invited_supplier.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: invitationId,
                        demo_date: dateEl ? dateEl.value : null,
                        notes:     notesEl ? notesEl.value : null
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Save failed');
                if (statusEl) {
                    statusEl.textContent = 'saved';
                    statusEl.className = 'save-status saved';
                    setTimeout(() => {
                        if (statusEl.textContent === 'saved') statusEl.textContent = '';
                    }, 1500);
                }
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = 'error: ' + err.message;
                    statusEl.className = 'save-status error';
                }
            }
        }

        // ─── Remove ────────────────────────────────────────────────

        async function removeInvitation(invitationId) {
            if (!confirm('Remove this supplier from the RFP?\n\nAny scores already entered for them will be deleted. The supplier record itself stays in your main suppliers list.')) {
                return;
            }
            try {
                const res = await fetch(API_BASE + 'remove_invited_supplier.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id: invitationId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Remove failed');
                loadAll();
            } catch (err) {
                alert('Remove failed: ' + err.message);
            }
        }

        // ─── Invite modal ──────────────────────────────────────────

        async function openInviteModal() {
            inviteTab = 'existing';
            applyInviteTab();
            // Reset all fields
            ['pickDemoDate','pickNotes','newLegalName','newTradingName','newComments','newDemoDate','newNotes']
                .forEach(id => { document.getElementById(id).value = ''; });
            document.getElementById('inviteModal').style.display = 'flex';
            // Populate the picker
            try {
                const res = await fetch(API_BASE + 'get_available_suppliers.php?rfp_id=' + encodeURIComponent(rfpId));
                const data = await res.json();
                const sel = document.getElementById('pickSupplier');
                sel.innerHTML = '<option value="">— Select a supplier —</option>' +
                    (data.success ? data.suppliers.map(s =>
                        '<option value="' + s.id + '">' + escapeHtml(s.display_name) +
                        (s.status_name ? ' (' + escapeHtml(s.status_name) + ')' : '') +
                        '</option>'
                    ).join('') : '');
            } catch (_) { /* swallow — user can switch to "create" tab */ }
        }

        function closeInviteModal() {
            document.getElementById('inviteModal').style.display = 'none';
        }

        function setInviteTab(tab) {
            inviteTab = tab;
            applyInviteTab();
        }

        function applyInviteTab() {
            document.getElementById('tabExisting').classList.toggle('active', inviteTab === 'existing');
            document.getElementById('tabNew').classList.toggle('active',      inviteTab === 'new');
            document.getElementById('existingPane').style.display = inviteTab === 'existing' ? '' : 'none';
            document.getElementById('newPane').style.display      = inviteTab === 'new'      ? '' : 'none';
        }

        async function saveInvite() {
            const btn = document.getElementById('inviteSaveBtn');
            btn.disabled = true;
            try {
                let url, body;
                if (inviteTab === 'existing') {
                    const supplierId = parseInt(document.getElementById('pickSupplier').value, 10);
                    if (!supplierId) throw new Error('Pick a supplier first');
                    url  = API_BASE + 'invite_supplier.php';
                    body = {
                        rfp_id: parseInt(rfpId, 10),
                        supplier_id: supplierId,
                        demo_date: document.getElementById('pickDemoDate').value || null,
                        notes:     document.getElementById('pickNotes').value
                    };
                } else {
                    const legalName = document.getElementById('newLegalName').value.trim();
                    if (!legalName) throw new Error('Supplier name is required');
                    url  = API_BASE + 'create_prospective_supplier.php';
                    body = {
                        rfp_id: parseInt(rfpId, 10),
                        legal_name:   legalName,
                        trading_name: document.getElementById('newTradingName').value,
                        comments:     document.getElementById('newComments').value,
                        demo_date:    document.getElementById('newDemoDate').value || null,
                        notes:        document.getElementById('newNotes').value
                    };
                }
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(body)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Invite failed');
                closeInviteModal();
                loadAll();
            } catch (err) {
                alert('Invite failed: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        // ─── Helpers ───────────────────────────────────────────────

        function showError(html) {
            document.getElementById('loadingEl').style.display = 'none';
            const el = document.getElementById('errorEl');
            el.innerHTML = html;
            el.style.display = 'block';
        }

        function formatDate(s) {
            if (!s) return '';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    </script>
</body>
</html>
