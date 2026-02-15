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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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
                    showNotification('Check saved successfully', 'success');
                    loadChecks();
                    loadChart();
                } else {
                    showNotification('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showNotification('Error saving check: ' + error.message, 'error');
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
                showNotification('Notes are required for ' + status + ' status', 'error');
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

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => notification.classList.add('show'), 10);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
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
                    showNotification('Error loading chart: ' + data.error, 'error');
                    return;
                }

                chartRawDates = data.rawDates || [];
                displayChart(data);
            } catch (error) {
                showNotification('Error loading chart: ' + error.message, 'error');
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

            const pdfContent = document.createElement('div');
            pdfContent.style.padding = '20px';
            pdfContent.style.backgroundColor = 'white';

            pdfContent.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <img src="../assets/images/CompanyLogo.png" alt="Company Logo" style="max-height: 40px; margin-bottom: 15px;">
                    <h2 style="margin: 10px 0; color: #2c3e50; font-size: 18px;">${dateText}</h2>
                </div>
            `;

            const table = document.getElementById('checksTable').cloneNode(true);
            const headerRow = table.querySelector('thead tr');
            const statusHeaderIndex = Array.from(headerRow.children).findIndex(th => th.textContent === 'Status');
            if (statusHeaderIndex !== -1) {
                headerRow.deleteCell(statusHeaderIndex);
            }

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.cells.length > 1) {
                    const statusCell = row.cells[statusHeaderIndex];
                    let status = 'Not set';
                    if (statusCell) {
                        const activeBtn = statusCell.querySelector('.status-btn.active');
                        if (activeBtn) status = activeBtn.textContent;
                    }

                    if (statusHeaderIndex !== -1 && row.cells[statusHeaderIndex]) {
                        row.deleteCell(statusHeaderIndex);
                    }

                    const checkNameCell = row.cells[0];
                    if (checkNameCell) {
                        const originalName = checkNameCell.textContent.trim();
                        let statusColor = '#6c757d';
                        if (status === 'Green') statusColor = '#28a745';
                        if (status === 'Amber') statusColor = '#ffc107';
                        if (status === 'Red') statusColor = '#dc3545';

                        checkNameCell.innerHTML = `
                            <strong>${originalName}</strong>
                            <br><span style="color: ${statusColor}; font-weight: bold; font-size: 12px;">${status}</span>
                        `;
                    }
                }
            });

            table.style.width = '100%';
            table.style.borderCollapse = 'collapse';
            table.style.fontSize = '11px';

            table.querySelectorAll('th, td').forEach(cell => {
                cell.style.border = '1px solid #dee2e6';
                cell.style.padding = '8px';
                cell.style.textAlign = 'left';
            });

            table.querySelectorAll('th').forEach(th => {
                th.style.backgroundColor = '#f8f9fa';
                th.style.fontWeight = 'bold';
            });

            pdfContent.appendChild(table);

            // Temporarily add to DOM so html2canvas can measure it, and hide the chart footer
            const chartFooter = document.querySelector('.chart-footer');
            chartFooter.style.display = 'none';
            pdfContent.style.position = 'absolute';
            pdfContent.style.left = '-9999px';
            pdfContent.style.top = '0';
            document.body.appendChild(pdfContent);

            const opt = {
                margin: [10, 10, 10, 10],
                filename: `morning-checks-${selectedDate}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            try {
                await html2pdf().set(opt).from(pdfContent).save();
                showNotification('PDF saved successfully', 'success');
            } catch (error) {
                showNotification('Error generating PDF: ' + error.message, 'error');
            } finally {
                document.body.removeChild(pdfContent);
                chartFooter.style.display = '';
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadChecks();
            loadChart();
        });
    </script>
</body>
</html>
