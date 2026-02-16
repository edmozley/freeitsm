<?php
/**
 * Morning Checks Dashboard
 */
session_start();
require_once '../config.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'dashboard';
$path_prefix = '../';
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
        /* Override body padding for header */
        body {
            padding-top: 0;
        }
        .container {
            padding-top: 20px;
        }
        /* Update chart border color to match theme */
        .chart-footer {
            border-top-color: #00acc1;
        }
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
    </div>

    <!-- Notes Modal -->
    <div id="notesModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeNotesModal()">&times;</span>
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

    <!-- Sticky Footer Chart -->
    <div class="chart-footer">
        <div class="chart-footer-header" onclick="toggleChart()">
            <h2 id="chartTitle">Last 30 days overview</h2>
            <span id="chartToggle" class="toggle-icon">▼</span>
        </div>
        <div id="chartContainer" class="chart-container-inner">
            <canvas id="statusChart"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        const API_BASE = '../api/morning-checks/';

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

        function displayChecks(checks) {
            const tbody = document.getElementById('checksTableBody');

            if (checks.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="no-data">No checks defined. <a href="settings/">Add some checks</a> to get started.</td></tr>';
                return;
            }

            tbody.innerHTML = checks.map(check => `
                <tr data-check-id="${check.CheckID}" class="status-${check.Status ? check.Status.toLowerCase() : 'none'}">
                    <td><strong>${escapeHtml(check.CheckName)}</strong></td>
                    <td>${escapeHtml(check.CheckDescription || '')}</td>
                    <td>
                        <div class="status-buttons">
                            <button class="status-btn green ${check.Status === 'Green' ? 'active' : ''}"
                                    onclick="handleStatusClick(${check.CheckID}, 'Green')">Green</button>
                            <button class="status-btn amber ${check.Status === 'Amber' ? 'active' : ''}"
                                    onclick="handleStatusClick(${check.CheckID}, 'Amber', '${escapeJsString(check.Notes || '')}')">Amber</button>
                            <button class="status-btn red ${check.Status === 'Red' ? 'active' : ''}"
                                    onclick="handleStatusClick(${check.CheckID}, 'Red', '${escapeJsString(check.Notes || '')}')">Red</button>
                        </div>
                    </td>
                    <td class="notes-display">${check.Notes ? escapeHtml(check.Notes) : '-'}</td>
                </tr>
            `).join('');
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

        function handleStatusClick(checkId, status, existingNotes = '') {
            if (status === 'Green') {
                saveCheckResult(checkId, status, '');
            } else {
                document.getElementById('modalCheckId').value = checkId;
                document.getElementById('modalStatusValue').value = status;
                document.getElementById('modalStatus').textContent = status;
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
            const modal = document.getElementById('notesModal');
            if (event.target === modal && modal.classList.contains('active')) {
                closeNotesModal();
            }
        }

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

            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.dates,
                    datasets: [
                        { label: 'Green', data: data.green, backgroundColor: '#28a745' },
                        { label: 'Amber', data: data.amber, backgroundColor: '#ffc107' },
                        { label: 'Red', data: data.red, backgroundColor: '#dc3545' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                    },
                    plugins: {
                        legend: { position: 'top' },
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

        function toggleChart() {
            const chartContainer = document.getElementById('chartContainer');
            const toggleIcon = document.getElementById('chartToggle');

            if (chartContainer.style.display === 'none') {
                chartContainer.style.display = 'block';
                toggleIcon.textContent = '▼';
            } else {
                chartContainer.style.display = 'none';
                toggleIcon.textContent = '▲';
            }
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
                        if (status === 'Green') data.cell.styles.textColor = [40, 167, 69];
                        else if (status === 'Amber') data.cell.styles.textColor = [200, 150, 0];
                        else if (status === 'Red') data.cell.styles.textColor = [220, 53, 69];
                        else data.cell.styles.textColor = [108, 117, 125];
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
        });
    </script>
</body>
</html>
