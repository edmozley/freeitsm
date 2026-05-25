<?php
/**
 * Morning Checks Dashboard
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$analyst_email = $_SESSION['analyst_email'] ?? '';
$analyst_id = $_SESSION['analyst_id'] ?? 0;
$current_page = 'dashboard';
$path_prefix = '../';

// Pre-fetch the analyst's chart preferences AND the configured status
// list so the first paint is already correct (no flash of empty status
// buttons or wrong chart height).
$chart_height_pct = 35.0;     // matches DEFAULT_CHART_PCT in the page-script
$chart_fill_style = 'plain';
$mc_statuses = [];            // [{StatusID, Label, Colour, RequiresNotes, SortOrder, IsActive}]
try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT preference_key, preference_value FROM user_preferences
         WHERE analyst_id = ? AND preference_key IN ('mc_chart_height_pct', 'mc_chart_fill_style')"
    );
    $stmt->execute([(int)$analyst_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['preference_key'] === 'mc_chart_height_pct' && is_numeric($row['preference_value'])) {
            $v = (float)$row['preference_value'];
            if ($v >= 12 && $v <= 80) $chart_height_pct = $v;
        } elseif ($row['preference_key'] === 'mc_chart_fill_style' && $row['preference_value'] === 'gradient') {
            $chart_fill_style = 'gradient';
        }
    }

    // Active statuses — drive the dashboard's status buttons. We fetch
    // ALL of them (active + inactive) so a historical result saved
    // against a now-deactivated status can still resolve its colour
    // for the row classname / PDF; the UI filters to active for the
    // actual button list.
    $sStmt = $conn->query(
        "SELECT StatusID, Label, Colour, RequiresNotes, SortOrder, IsActive
         FROM morningChecks_Statuses ORDER BY SortOrder, StatusID"
    );
    foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $mc_statuses[] = [
            'StatusID'      => (int)$s['StatusID'],
            'Label'         => $s['Label'],
            'Colour'        => $s['Colour'],
            'RequiresNotes' => (bool)$s['RequiresNotes'],
            'SortOrder'     => (int)$s['SortOrder'],
            'IsActive'      => (bool)$s['IsActive'],
        ];
    }
} catch (Exception $e) {
    // Stick with the defaults / empty statuses; JS will deal with it
}
// CSS calc() needs the percentage as a fraction (0-1). The 60px is a
// reasonable approximation of the global header height — JS will
// reconcile to the exact pixel value once the DOM is ready, so any
// sub-pixel mismatch here is invisible.
$chart_initial_height_calc = 'calc((100vh - 60px) * ' . ($chart_height_pct / 100) . ')';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Morning Checks</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Layout: body is the outer flex column so .container fills
           exactly the viewport minus the global header — no manual
           calc(100vh - Npx), which was undershooting (the global header
           is actually ~60px tall, not 48). Modals are position: fixed
           so they sit outside the flex flow. */
        body {
            padding-top: 0;
            display: flex;
            flex-direction: column;
            /* inbox.css already sets height: 100vh; overflow: hidden */
        }
        .container {
            max-width: none;
            margin: 0;
            padding: 0;
            flex: 1;
            min-height: 0;   /* lets the inner overflow:auto work in a flex parent */
            display: flex;
            flex-direction: column;
        }
        .date-display {
            flex-shrink: 0;
            padding: 16px 30px 5px;
        }
        .checks-section {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 16px 30px 16px;
            margin-bottom: 0;
        }
        /* Chart sits in flow at the bottom of the flex column (was
           position: fixed). flex-shrink: 0 pins it; the checks-section
           above scrolls underneath. position: relative anchors the
           absolutely-positioned collapse chevron; min-height keeps the
           chevron visible when the chart is collapsed (the chart-
           container-inner is the only in-flow child, so without
           min-height the footer would shrink to nothing — bumped a
           little to leave room for the chevron now that it sits near
           the bottom edge). */
        .chart-footer {
            position: relative;
            flex-shrink: 0;
            min-height: 40px;
            border-top-color: #00acc1;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.06);
        }

        /* Tighten the chart-container-inner padding. style.css defaults
           to 20px 30px 30px 30px which leaves ~30px of dead space
           around the canvas — the chart and legend now use that
           reclaimed area. Chevron is positioned relative to .chart-
           footer (one level up) so it's unaffected by this. */
        .chart-container-inner {
            padding: 6px 14px 4px 14px;
            overflow: hidden;
            transition: height 0.25s ease, padding 0.25s ease, opacity 0.2s ease;
        }
        /* Collapsed state — height animates from its inline px value to
           0 over the transition. !important needed to beat the inline
           style.height that applyChartHeightFromPct sets. The chart-
           footer's min-height (40px) keeps the chevron visible. */
        .chart-container-inner.collapsed {
            height: 0 !important;
            padding-top: 0;
            padding-bottom: 0;
            opacity: 0;
        }

        /* Collapse chevron — small floating button in the bottom-right
           of the chart area, vertically aligned with the Chart.js
           bottom-position legend. Footer's min-height keeps it visible
           when chart is collapsed. */
        .chart-toggle-btn {
            position: absolute;
            bottom: 8px;
            right: 12px;
            z-index: 5;
            width: 26px;
            height: 22px;
            padding: 0;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            color: #007bff;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .chart-toggle-btn:hover {
            background: white;
            border-color: #007bff;
        }

        /* Dynamic status buttons — colour comes from CSS custom
           properties on each button (--c = base, --tc = readable
           text on filled background). Overrides the legacy hard-coded
           .status-btn.green / .amber / .red rules in style.css. */
        .status-btn {
            border-color: var(--c, #999) !important;
            color: var(--c, #555) !important;
            background: white !important;
        }
        .status-btn:hover,
        .status-btn.active {
            background: var(--c, #999) !important;
            color: var(--tc, #fff) !important;
        }

        /* Drag handle between the checks list and the chart. Hover /
           active states use a subtle blue tint so it's discoverable
           without being noisy in its resting state. */
        .mc-divider {
            flex-shrink: 0;
            height: 6px;
            background: transparent;
            cursor: row-resize;
            border-top: 1px solid #e0e0e0;
            transition: background 0.15s;
            user-select: none;
        }
        .mc-divider:hover { background: rgba(0, 123, 255, 0.18); }
        .mc-divider.dragging { background: rgba(0, 123, 255, 0.35); }

    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="date-display">
            <h2 id="dateDisplayText">Today's checks - <?php echo date('l, F j, Y'); ?></h2>
            <div class="date-selector-container">
                <label for="checkDate">Select date:</label>
                <input type="date" id="checkDate" value="<?php echo date('Y-m-d'); ?>" onchange="dateChanged()">
                <button onclick="setToday()" class="btn-today">Today</button>
                <button onclick="saveToPDF()" class="btn-pdf">Save to PDF</button>
            </div>
        </div>

        <div class="checks-section">
            <table id="checksTable">
                <thead>
                    <tr>
                        <th>Check name</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody id="checksTableBody">
                    <tr>
                        <td colspan="4" class="loading">Loading checks...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Drag-handle between the checks list and the chart. The chart's
             height is a per-analyst preference (mc_chart_height_pct) so the
             split each user prefers persists across reloads. -->
        <div class="mc-divider" id="mcDivider" title="Drag to resize chart"></div>

        <!-- Chart sits in the flex column at the bottom of .container.
             No grey header bar anymore — the collapse chevron is
             overlaid in the chart's top-right corner so the entire
             chart-footer height goes to the canvas. Chart-footer keeps a
             small min-height so the chevron stays visible when the
             chart-container is collapsed.
             The chart-container's inline height is set server-side from
             the analyst's saved preference so the first paint matches
             their chosen split (no snap from the CSS default of 280px
             to the saved value once JS finished its preference fetch). -->
        <div class="chart-footer">
            <button id="chartToggle" class="chart-toggle-btn" onclick="toggleChart()" aria-label="Collapse chart">▼</button>
            <div id="chartContainer" class="chart-container-inner" style="height: <?php echo $chart_initial_height_calc; ?>;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div id="notesModal" class="modal">
        <div class="modal-content">
            <h2>Add notes</h2>
            <p>Please provide details about this <span id="modalStatus"></span> status.</p>
            <form id="notesForm">
                <input type="hidden" id="modalCheckId">
                <input type="hidden" id="modalStatusValue">
                <div class="form-group">
                    <label for="modalNotes">Notes *</label>
                    <textarea id="modalNotes" name="modalNotes" rows="5" required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" class="btn-secondary" onclick="closeNotesModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Raise Ticket Modal -->
    <div id="raiseTicketModal" class="modal">
        <div class="modal-content" style="max-width: 640px;">
            <span class="close" onclick="closeRaiseTicketModal()">&times;</span>
            <h2>Raise a ticket</h2>
            <p>Create a ticket linked to this morning check. The check name, status and notes are pre-filled below.</p>
            <form id="raiseTicketForm">
                <div class="form-group">
                    <label for="rtSubject">Subject *</label>
                    <input type="text" id="rtSubject" required>
                </div>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label for="rtPriority">Priority</label>
                        <select id="rtPriority">
                            <option value="Low">Low</option>
                            <option value="Normal">Normal</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rtDepartment">Department</label>
                        <select id="rtDepartment">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rtTicketType">Type</label>
                        <select id="rtTicketType">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="rtAssignee">Assign to</label>
                    <select id="rtAssignee">
                        <option value="">Unassigned</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rtBody">Description</label>
                    <textarea id="rtBody" rows="6"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary" id="rtSubmitBtn">Create ticket</button>
                    <button type="button" class="btn-secondary" onclick="closeRaiseTicketModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        const API_BASE = '../api/morning-checks/';
        const TICKETS_API = '../api/tickets/';
        const SESSION_ANALYST = {
            id: <?php echo (int)$analyst_id; ?>,
            name: <?php echo json_encode($analyst_name); ?>,
            email: <?php echo json_encode($analyst_email); ?>
        };
        // Chart preferences pre-fetched server-side so we don't
        // need a separate AJAX round-trip (and so the page paints at
        // the right size and style from the start).
        const INITIAL_CHART_PCT = <?php echo json_encode($chart_height_pct); ?>;
        const CHART_FILL_STYLE = <?php echo json_encode($chart_fill_style); ?>;
        // All configured statuses (active + inactive). Drives the
        // status buttons on each check row and the colour lookup for
        // historical results.
        const MC_STATUSES = <?php echo json_encode($mc_statuses); ?>;
        const MC_ACTIVE_STATUSES = MC_STATUSES.filter(s => s.IsActive);
        let rtAnalystOptions = [];
        let rtDepartmentOptions = [];
        let rtTicketTypeOptions = [];

        // Load checks for selected date
        async function loadChecks() {
            const selectedDate = document.getElementById('checkDate').value;
            try {
                const response = await fetch(`${API_BASE}get_todays_checks.php?date=${selectedDate}`);
                const data = await response.json();

                if (data.error) {
                    document.getElementById('checksTableBody').innerHTML =
                        `<tr><td colspan="4" class="error">Error: ${data.error}</td></tr>`;
                    return;
                }

                displayChecks(data);
            } catch (error) {
                document.getElementById('checksTableBody').innerHTML =
                    `<tr><td colspan="4" class="error">Error loading checks: ${error.message}</td></tr>`;
            }
        }

        function dateChanged() {
            const selectedDate = document.getElementById('checkDate').value;
            const dateObj = new Date(selectedDate + 'T00:00:00');
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let displayText = '';
            if (dateObj.getTime() === today.getTime()) {
                displayText = "Today's checks - " + formatDate(dateObj);
            } else {
                displayText = "Checks for " + formatDate(dateObj);
            }
            document.getElementById('dateDisplayText').textContent = displayText;

            loadChecks();
            loadChart();
        }

        function setToday() {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            document.getElementById('checkDate').value = `${yyyy}-${mm}-${dd}`;
            dateChanged();
        }

        function formatDate(date) {
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }

        // Pick black/white text for a given hex background so the active
        // pill is always readable. Standard relative-luminance threshold.
        function readableTextOn(hexColour) {
            const m = /^#([0-9a-f]{6})$/i.exec(hexColour || '');
            if (!m) return '#fff';
            const r = parseInt(m[1].slice(0, 2), 16);
            const g = parseInt(m[1].slice(2, 4), 16);
            const b = parseInt(m[1].slice(4, 6), 16);
            const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            return lum > 0.6 ? '#333' : '#fff';
        }

        // Resolve the saved status string to a status row. Falls back to
        // null if the label no longer matches any configured status
        // (e.g. the status was deleted after this result was saved).
        function statusByLabel(label) {
            if (!label) return null;
            return MC_STATUSES.find(s => s.Label === label) || null;
        }

        function displayChecks(checks) {
            const tbody = document.getElementById('checksTableBody');

            if (checks.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="no-data">No checks defined. <a href="settings/">Add some checks</a> to get started.</td></tr>';
                return;
            }
            if (MC_ACTIVE_STATUSES.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="no-data">No statuses defined. <a href="settings/">Add statuses</a> to get started.</td></tr>';
                return;
            }

            tbody.innerHTML = checks.map(check => {
                // Status row currently saved against this check (if any).
                const currentStatus = statusByLabel(check.Status);
                // "Raise ticket" appears only for statuses that require
                // notes (a reasonable proxy for "something needs attention").
                const showRaise = currentStatus && currentStatus.RequiresNotes;
                const raiseBtn = showRaise
                    ? `<button class="raise-ticket-btn" onclick="openRaiseTicketModal(${check.CheckID}, '${escapeJsString(check.CheckName)}', '${escapeJsString(check.CheckDescription || '')}', '${escapeJsString(check.Status)}', '${escapeJsString(check.Notes || '')}')">+ Raise ticket</button>`
                    : '';

                // Status buttons — one per active status. Inline CSS
                // custom properties drive border/background/text colour
                // (see .status-btn rules in the page-local <style>).
                const buttonsHtml = MC_ACTIVE_STATUSES.map(s => {
                    const isActive = currentStatus && currentStatus.StatusID === s.StatusID;
                    const textColour = readableTextOn(s.Colour);
                    const styleVars = '--c: ' + s.Colour + '; --tc: ' + textColour + ';';
                    return `<button class="status-btn${isActive ? ' active' : ''}"
                                    style="${styleVars}"
                                    onclick="handleStatusClick(${check.CheckID}, ${s.StatusID}, '${escapeJsString(check.Notes || '')}')">${escapeHtml(s.Label)}</button>`;
                }).join('');

                // Row's status-{slug} class — keeps existing CSS hooks
                // working for legacy row-level highlight rules.
                const slug = check.Status ? check.Status.toLowerCase().replace(/[^a-z0-9]+/g, '-') : 'none';

                return `
                <tr data-check-id="${check.CheckID}" class="status-${slug}">
                    <td><strong>${escapeHtml(check.CheckName)}</strong></td>
                    <td>${escapeHtml(check.CheckDescription || '')}</td>
                    <td>
                        <div class="status-buttons">${buttonsHtml}</div>
                        ${raiseBtn}
                    </td>
                    <td class="notes-display">${check.Notes ? escapeHtml(check.Notes) : '-'}</td>
                </tr>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Escape string for use inside JavaScript single-quoted strings in onclick handlers
        function escapeJsString(text) {
            if (!text) return '';
            return text.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\r/g, '\\r');
        }

        // Click handler for a status button. Looks up the status by its
        // numeric StatusID, then decides whether to save immediately
        // (RequiresNotes = false) or pop the notes modal first
        // (RequiresNotes = true).
        function handleStatusClick(checkId, statusId, existingNotes = '') {
            const s = MC_STATUSES.find(x => x.StatusID === statusId);
            if (!s) return;
            if (!s.RequiresNotes) {
                saveCheckResult(checkId, s.Label, '');
            } else {
                document.getElementById('modalCheckId').value = checkId;
                document.getElementById('modalStatusValue').value = s.Label;
                document.getElementById('modalStatus').textContent = s.Label;
                document.getElementById('modalNotes').value = existingNotes;
                document.getElementById('notesModal').classList.add('active');
            }
        }

        async function saveCheckResult(checkId, status, notes) {
            const selectedDate = document.getElementById('checkDate').value;
            try {
                const response = await fetch(`${API_BASE}save_check_result.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        checkId: checkId,
                        status: status,
                        notes: notes,
                        checkDate: selectedDate
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showToast('Check saved successfully', 'success');
                    loadChecks();
                    loadChart();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Error saving check: ' + error.message, 'error');
            }
        }

        function closeNotesModal() {
            document.getElementById('notesModal').classList.remove('active');
            document.getElementById('notesForm').reset();
        }

        document.getElementById('notesForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const checkId = parseInt(document.getElementById('modalCheckId').value);
            const status = document.getElementById('modalStatusValue').value;
            const notes = document.getElementById('modalNotes').value.trim();

            if (!notes) {
                showToast('Notes are required for ' + status + ' status', 'error');
                return;
            }

            closeNotesModal();
            saveCheckResult(checkId, status, notes);
        });

        window.onclick = function(event) {
            const notesModal = document.getElementById('notesModal');
            if (event.target === notesModal && notesModal.classList.contains('active')) {
                closeNotesModal();
            }
            const raiseModal = document.getElementById('raiseTicketModal');
            if (event.target === raiseModal && raiseModal.classList.contains('active')) {
                closeRaiseTicketModal();
            }
        }

        // Raise Ticket from morning check
        async function openRaiseTicketModal(checkId, checkName, checkDesc, status, notes) {
            // Lazy-load lookup lists
            try {
                if (!rtAnalystOptions.length) {
                    const r = await fetch(TICKETS_API + 'get_analysts.php');
                    const d = await r.json();
                    if (d.success) rtAnalystOptions = (d.analysts || []).filter(a => a.is_active);
                }
                if (!rtDepartmentOptions.length) {
                    const r = await fetch(TICKETS_API + 'get_departments.php');
                    const d = await r.json();
                    if (d.success) rtDepartmentOptions = (d.departments || []).filter(x => x.is_active);
                }
                if (!rtTicketTypeOptions.length) {
                    const r = await fetch(TICKETS_API + 'get_ticket_types.php');
                    const d = await r.json();
                    if (d.success) rtTicketTypeOptions = (d.ticket_types || []).filter(x => x.is_active);
                }
            } catch (e) {
                showToast('Failed to load lookup lists: ' + e.message, 'error');
                return;
            }

            const checkDate = document.getElementById('checkDate').value;
            const subject = `[Morning Check] ${checkName} (${status})`;
            let body = `Morning check '${checkName}' was set to ${status} on ${checkDate}.`;
            if (checkDesc) body += `\n\nCheck description: ${checkDesc}`;
            if (notes)     body += `\n\nNotes: ${notes}`;

            document.getElementById('rtSubject').value = subject;
            document.getElementById('rtBody').value = body;
            // Map status → ticket priority. Used to be hard-coded Red→High,
            // others→Normal; with configurable statuses we use the
            // highest-sort-order RequiresNotes status as the "High"
            // proxy (typically the most severe — e.g. Red). Falls back
            // to Normal if no such status or the current one isn't it.
            const sevStatuses = MC_STATUSES.filter(s => s.RequiresNotes)
                                          .sort((a, b) => b.SortOrder - a.SortOrder);
            const isMostSevere = sevStatuses.length > 0 && status === sevStatuses[0].Label;
            document.getElementById('rtPriority').value = isMostSevere ? 'High' : 'Normal';

            // Populate selects
            const assigneeSel = document.getElementById('rtAssignee');
            assigneeSel.innerHTML = '<option value="">Unassigned</option>' +
                rtAnalystOptions.map(a =>
                    `<option value="${a.id}" ${a.id === SESSION_ANALYST.id ? 'selected' : ''}>${escapeHtml(a.full_name)}</option>`
                ).join('');

            const deptSel = document.getElementById('rtDepartment');
            deptSel.innerHTML = '<option value="">-- Select --</option>' +
                rtDepartmentOptions.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

            const typeSel = document.getElementById('rtTicketType');
            typeSel.innerHTML = '<option value="">-- Select --</option>' +
                rtTicketTypeOptions.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

            // Stash check id for reference (currently not persisted; included in description above)
            document.getElementById('raiseTicketModal').dataset.checkId = String(checkId);
            document.getElementById('raiseTicketModal').classList.add('active');
        }

        function closeRaiseTicketModal() {
            document.getElementById('raiseTicketModal').classList.remove('active');
            document.getElementById('raiseTicketForm').reset();
        }

        document.getElementById('raiseTicketForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('rtSubmitBtn');
            const subject = document.getElementById('rtSubject').value.trim();
            if (!subject) {
                showToast('Subject is required', 'error');
                return;
            }
            if (!SESSION_ANALYST.email) {
                showToast('Your analyst account has no email — set one before raising tickets', 'error');
                return;
            }
            btn.disabled = true;
            try {
                const resp = await fetch(TICKETS_API + 'create_ticket.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        from_name: SESSION_ANALYST.name,
                        from_email: SESSION_ANALYST.email,
                        subject: subject,
                        body: document.getElementById('rtBody').value,
                        priority: document.getElementById('rtPriority').value,
                        department_id: document.getElementById('rtDepartment').value || null,
                        ticket_type_id: document.getElementById('rtTicketType').value || null,
                        assigned_analyst_id: document.getElementById('rtAssignee').value || null
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast('Ticket ' + data.ticket_number + ' created', 'success');
                    closeRaiseTicketModal();
                } else {
                    showToast('Error: ' + (data.error || 'Failed to create ticket'), 'error');
                }
            } catch (err) {
                showToast('Error creating ticket: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        });

        // Chart functionality
        let chartInstance = null;
        let chartRawDates = [];

        async function loadChart() {
            const selectedDate = document.getElementById('checkDate').value;
            try {
                const response = await fetch(`${API_BASE}get_chart_data.php?endDate=${selectedDate}`);
                const data = await response.json();

                if (data.error) {
                    showToast('Error loading chart: ' + data.error, 'error');
                    return;
                }

                chartRawDates = data.rawDates || [];
                displayChart(data);
            } catch (error) {
                showToast('Error loading chart: ' + error.message, 'error');
            }
        }

        function displayChart(data) {
            const canvas = document.getElementById('statusChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');

            if (chartInstance) chartInstance.destroy();

            // X-axis: just the day number per tick (instead of the full
            // "May 25" label). The chart heading and month name(s) move
            // to a single axis title below — frees up the chart-footer
            // header to be just the collapse chevron. When the 30-day
            // window spans two months we show both joined by an en-dash.
            // Tooltips still show the full original label so hovering a
            // bar is still informative.
            const monthTitle = monthRangeText(chartRawDates);
            const axisTitle = monthTitle
                ? ('Last 30 days overview · ' + monthTitle)
                : 'Last 30 days overview';

            // Background colour resolver — returns the solid colour when
            // CHART_FILL_STYLE is 'plain', or a function that produces a
            // top-to-bottom linear gradient when 'gradient'. Returning a
            // function lets Chart.js rebuild the gradient on every draw,
            // so it tracks the chart's actual area as it resizes.
            function barFill(baseHex) {
                if (CHART_FILL_STYLE !== 'gradient') return baseHex;
                return function(context) {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return baseHex;   // first frame before layout — fall back
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, lighten(baseHex, 0.45));
                    gradient.addColorStop(1, baseHex);
                    return gradient;
                };
            }

            // Build one Chart.js dataset per status returned by the API
            // (already filtered to active + ordered by SortOrder server-side).
            const datasets = (data.datasets || []).map(d => ({
                label: d.label,
                data: d.data,
                backgroundColor: barFill(d.colour)
            }));

            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.dates,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            ticks: {
                                // Day-of-month only. chartRawDates is the
                                // ISO YYYY-MM-DD parallel array we
                                // already store for the click-through
                                // (parsing through Date avoids timezone
                                // skew on the day component).
                                callback: function(value, index) {
                                    const raw = chartRawDates[index];
                                    if (!raw) return '';
                                    const d = new Date(raw + 'T00:00:00');
                                    return d.getDate();
                                },
                                autoSkip: false,
                                maxRotation: 0
                            },
                            title: {
                                display: true,
                                text: axisTitle,
                                font: { size: 13, weight: '600' },
                                color: '#2c3e50',
                                padding: { top: 6 }
                            }
                        },
                        y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                    },
                    plugins: {
                        // Legend at the bottom — the top-right of the
                        // chart area now hosts the floating collapse
                        // chevron, so leaving the legend on top would
                        // mean the two overlap each other.
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                title: (context) => context[0].label,
                                label: (context) => context.dataset.label + ': ' + context.parsed.y
                            }
                        }
                    },
                    onClick: function(e, elements) {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            if (chartRawDates[index]) {
                                document.getElementById('checkDate').value = chartRawDates[index];
                                dateChanged();
                            }
                        }
                    }
                }
            });
        }

        // Blend a #rrggbb colour toward white by `amount` (0 = no change,
        // 1 = fully white). Used to compute the top stop of the bar
        // gradient when CHART_FILL_STYLE is 'gradient'.
        function lighten(hex, amount) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            const nr = Math.round(r + (255 - r) * amount);
            const ng = Math.round(g + (255 - g) * amount);
            const nb = Math.round(b + (255 - b) * amount);
            return 'rgb(' + nr + ',' + ng + ',' + nb + ')';
        }

        // Build the x-axis title that replaces per-tick month names.
        // Returns 'May' if all dates fall in one month, 'May – June' if
        // the window spans two. Empty string if no dates.
        function monthRangeText(rawDates) {
            if (!rawDates || !rawDates.length) return '';
            const first = new Date(rawDates[0] + 'T00:00:00');
            const last = new Date(rawDates[rawDates.length - 1] + 'T00:00:00');
            const fmt = d => d.toLocaleString('en-GB', { month: 'long' });
            const sameMonth = first.getMonth() === last.getMonth()
                && first.getFullYear() === last.getFullYear();
            return sameMonth ? fmt(first) : (fmt(first) + ' – ' + fmt(last));
        }

        function toggleChart() {
            const chartContainer = document.getElementById('chartContainer');
            const toggleIcon = document.getElementById('chartToggle');
            const divider = document.getElementById('mcDivider');
            const collapsed = chartContainer.classList.contains('collapsed');

            if (collapsed) {
                // Update the target inline height before un-collapsing
                // (handles the window-was-resized-while-collapsed case)
                // but DON'T call chartInstance.resize() yet — the canvas
                // parent is still 0px tall, that'd fit the chart to nothing.
                applyChartHeightFromPct(currentChartPct, false);
                chartContainer.classList.remove('collapsed');
                toggleIcon.textContent = '▼';
                if (divider) divider.style.display = '';
                // After the slide-out transition lands, redraw the chart
                // at the new canvas size.
                setTimeout(() => {
                    if (typeof chartInstance !== 'undefined' && chartInstance) {
                        chartInstance.resize();
                    }
                }, 280);
            } else {
                chartContainer.classList.add('collapsed');
                toggleIcon.textContent = '▲';
                // Nothing to resize once collapsed, hide the handle.
                if (divider) divider.style.display = 'none';
            }
        }

        // ===== Resizable chart =====
        // The divider between the checks list and the chart can be
        // dragged to resize the chart. The chosen split is saved per
        // analyst as a percentage of the container height so it follows
        // the user across reloads / window sizes.

        const CHART_PCT_PREF = 'mc_chart_height_pct';
        const DEFAULT_CHART_PCT = 35;   // chart takes ~a third of the available vertical space by default
        const MIN_CHART_PCT = 12;
        const MAX_CHART_PCT = 80;
        // Seed from the server-fetched value so the initial JS-applied
        // height matches what the page was painted with — no flicker.
        let currentChartPct = (typeof INITIAL_CHART_PCT === 'number' && !isNaN(INITIAL_CHART_PCT))
            ? INITIAL_CHART_PCT
            : DEFAULT_CHART_PCT;

        function applyChartHeightFromPct(pct, resizeChart) {
            // resizeChart defaults to true. Pass false when the chart is
            // hidden / collapsed (calling resize() would fit the canvas
            // to 0 and require a re-resize on expand).
            if (resizeChart === undefined) resizeChart = true;
            const container = document.querySelector('.container');
            const inner = document.getElementById('chartContainer');
            if (!container || !inner) return;
            const containerH = container.clientHeight;
            // No header bar any more — chart-footer height IS the inner
            // (canvas) height. The collapse chevron is absolutely
            // positioned so it doesn't take in-flow space.
            const innerH = Math.max(60, containerH * (pct / 100));
            inner.style.height = innerH + 'px';
            if (resizeChart && typeof chartInstance !== 'undefined' && chartInstance) {
                chartInstance.resize();
            }
        }

        // Pin the chart-container-inner height to the exact pixel value
        // for the current container size. The server-side calc() got us
        // very close; this just locks in the precise number so any later
        // adjustment (drag, window resize) starts from a clean base.
        function lockChartHeightFromInitial() {
            applyChartHeightFromPct(currentChartPct);
        }

        function saveChartHeightPref(pct) {
            // Fire-and-forget — a missed save isn't worth blocking the UI.
            fetch('../api/system/set_user_preference.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: CHART_PCT_PREF, value: pct.toFixed(1) })
            }).catch(() => {});
        }

        function startChartResize(e) {
            const chartContainer = document.getElementById('chartContainer');
            // Don't allow drag when the chart is collapsed — nothing to resize.
            if (chartContainer && chartContainer.style.display === 'none') return;

            const container = document.querySelector('.container');
            const chartFooter = document.querySelector('.chart-footer');
            const divider = document.getElementById('mcDivider');
            const containerH = container.clientHeight;
            const startY = e.clientY;
            const startFooterH = chartFooter.offsetHeight;

            document.body.style.cursor = 'row-resize';
            document.body.style.userSelect = 'none';
            divider.classList.add('dragging');

            function onMove(ev) {
                const dy = ev.clientY - startY;
                // Cursor moving DOWN shrinks the chart; UP grows it.
                let newFooterH = startFooterH - dy;
                const minH = containerH * (MIN_CHART_PCT / 100);
                const maxH = containerH * (MAX_CHART_PCT / 100);
                newFooterH = Math.max(minH, Math.min(maxH, newFooterH));
                currentChartPct = (newFooterH / containerH) * 100;
                applyChartHeightFromPct(currentChartPct);
            }

            function onUp() {
                window.removeEventListener('mousemove', onMove);
                window.removeEventListener('mouseup', onUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                divider.classList.remove('dragging');
                saveChartHeightPref(currentChartPct);
            }

            window.addEventListener('mousemove', onMove);
            window.addEventListener('mouseup', onUp);
            e.preventDefault();
        }

        // Save to PDF
        async function saveToPDF() {
            const selectedDate = document.getElementById('checkDate').value;
            const dateText = document.getElementById('dateDisplayText').textContent;
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });

            let startY = 10;

            // Add logo
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
                doc.addImage(img, 'PNG', 10, startY, w, maxH);
                startY += maxH + 5;
            } catch (e) {
                // Continue without logo
            }

            // Add title
            doc.setFontSize(14);
            doc.setTextColor(44, 62, 80);
            doc.text(dateText, 10, startY + 5);
            startY += 12;

            // Build table data from the DOM
            const rows = document.querySelectorAll('#checksTableBody tr');
            const body = [];
            rows.forEach(row => {
                if (row.cells.length > 1) {
                    const name = row.cells[0].textContent.trim();
                    const desc = row.cells[1].textContent.trim();
                    const activeBtn = row.cells[2]?.querySelector('.status-btn.active');
                    const status = activeBtn ? activeBtn.textContent : 'Not set';
                    const notes = row.cells[3]?.textContent.trim() || '-';
                    body.push([name, desc, status, notes]);
                }
            });

            // Generate table
            doc.autoTable({
                startY: startY,
                head: [['Check name', 'Description', 'Status', 'Notes']],
                body: body,
                styles: { fontSize: 9, cellPadding: 3 },
                headStyles: { fillColor: [248, 249, 250], textColor: [0, 0, 0], fontStyle: 'bold' },
                columnStyles: {
                    0: { cellWidth: 35, fontStyle: 'bold' },
                    2: { cellWidth: 20, halign: 'center' },
                    3: { cellWidth: 35 }
                },
                didParseCell: function(data) {
                    if (data.section === 'body' && data.column.index === 2) {
                        const status = data.cell.raw;
                        data.cell.styles.fontStyle = 'bold';
                        // Look up the status's configured colour from MC_STATUSES
                        // and convert #rrggbb → [r,g,b] for jsPDF. Unknown
                        // statuses (e.g. a "Not set" placeholder, or a label
                        // that's been since deleted) fall back to grey.
                        const s = MC_STATUSES.find(x => x.Label === status);
                        if (s) {
                            const m = /^#([0-9a-f]{6})$/i.exec(s.Colour);
                            data.cell.styles.textColor = m
                                ? [parseInt(m[1].slice(0,2),16), parseInt(m[1].slice(2,4),16), parseInt(m[1].slice(4,6),16)]
                                : [108, 117, 125];
                        } else {
                            data.cell.styles.textColor = [108, 117, 125];
                        }
                    }
                }
            });

            doc.save(`morning-checks-${selectedDate}.pdf`);
            showToast('PDF saved successfully', 'success');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadChecks();
            loadChart();
            // Pin the calc()-based initial height to an exact pixel
            // value (so drag math and window-resize handling work).
            lockChartHeightFromInitial();

            // Wire up the resize divider and keep the chosen proportion
            // consistent across window-resizes.
            const divider = document.getElementById('mcDivider');
            if (divider) divider.addEventListener('mousedown', startChartResize);
            window.addEventListener('resize', () => applyChartHeightFromPct(currentChartPct));
        });
    </script>
</body>
</html>
