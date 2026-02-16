<?php
/**
 * Service Status Module - Dashboard
 * Shows service board with worst current impact + recent incidents
 */
session_start();
require_once '../config.php';

$current_page = 'dashboard';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Service Status</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }

        .status-layout {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title .count {
            font-size: 13px;
            font-weight: 400;
            color: #888;
        }

        /* Service Board Grid */
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 36px;
        }

        .service-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            transition: box-shadow 0.2s;
        }

        .service-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

        .service-card .service-name {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .service-card .service-desc {
            font-size: 12px;
            color: #888;
            margin-bottom: 10px;
            min-height: 16px;
        }

        /* Impact badges */
        .impact-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .impact-major-outage { background: #fee2e2; color: #991b1b; }
        .impact-partial-outage { background: #fff1f2; color: #be123c; }
        .impact-degraded { background: #fff7ed; color: #c2410c; }
        .impact-maintenance { background: #dbeafe; color: #1e40af; }
        .impact-operational { background: #d1fae5; color: #065f46; }
        .impact-no-disruption { background: #f3f4f6; color: #6b7280; }

        /* Status badges for incident status */
        .incident-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .incident-status-3rd-party { background: #fef3c7; color: #92400e; }
        .incident-status-identified { background: #e0e7ff; color: #3730a3; }
        .incident-status-investigating { background: #fff7ed; color: #c2410c; }
        .incident-status-monitoring { background: #dbeafe; color: #1e40af; }
        .incident-status-resolved { background: #d1fae5; color: #065f46; }

        /* Incidents list */
        .incidents-section { margin-bottom: 30px; }

        .incident-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .incident-table th {
            background: #f9fafb;
            padding: 10px 14px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }

        .incident-table td {
            padding: 12px 14px;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f3f4f6;
        }

        .incident-table tr:last-child td { border-bottom: none; }

        .incident-table tr.resolved td { color: #999; }

        .incident-title {
            font-weight: 500;
            cursor: pointer;
        }

        .incident-title:hover { color: #10b981; }

        .incident-services-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .incident-svc-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }

        .new-btn {
            padding: 8px 18px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .new-btn:hover { background: #059669; }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
            font-size: 14px;
        }

        /* Incident modal */
        .modal-content { padding: 30px; max-width: 600px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }

        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input,
        .modal .form-group textarea,
        .modal .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .modal .form-group textarea { height: 80px; resize: vertical; }
        .modal .form-group input:focus,
        .modal .form-group textarea:focus,
        .modal .form-group select:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.1); }

        .modal-actions { margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #10b981; color: white; }
        .btn-primary:hover { background-color: #059669; }
        .btn-danger { background-color: #ef4444; color: white; }
        .btn-danger:hover { background-color: #dc2626; }

        /* Affected services rows in modal */
        .affected-services { margin-top: 5px; }

        .affected-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
        }

        .affected-row select { flex: 1; }

        .affected-row .remove-svc {
            background: none;
            border: none;
            color: #d13438;
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .affected-row .remove-svc:hover { background: #fdf3f3; }

        .add-svc-btn {
            background: none;
            border: 1px dashed #ccc;
            color: #666;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .add-svc-btn:hover { border-color: #10b981; color: #10b981; }

        .incident-date {
            font-size: 12px;
            color: #999;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="status-layout">
        <!-- Service Board -->
        <div class="section-title">
            Services
            <span class="count" id="serviceCount"></span>
        </div>
        <div class="service-grid" id="serviceGrid">
            <div class="empty-state">Loading...</div>
        </div>

        <!-- Incidents -->
        <div class="incidents-section">
            <div class="section-title">
                Incidents
                <button class="new-btn" onclick="openIncidentModal()">New</button>
            </div>
            <table class="incident-table" id="incidentTable" style="display: none;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Affected Services</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody id="incidentList"></tbody>
            </table>
            <div class="empty-state" id="incidentEmpty" style="display: none;">No incidents to show.</div>
        </div>
    </div>

    <!-- Incident Modal -->
    <div class="modal" id="incidentModal">
        <div class="modal-content">
            <div class="modal-header" id="incidentModalTitle">New Incident</div>
            <form id="incidentForm" autocomplete="off">
                <input type="hidden" id="incidentId">
                <div class="form-group">
                    <label for="incidentTitle">Title</label>
                    <input type="text" id="incidentTitle" required placeholder="Brief description of the incident">
                </div>
                <div class="form-group">
                    <label for="incidentStatus">Status</label>
                    <select id="incidentStatus">
                        <option value="Investigating">Investigating</option>
                        <option value="Identified">Identified</option>
                        <option value="3rd Party">3rd Party</option>
                        <option value="Monitoring">Monitoring</option>
                        <option value="Resolved">Resolved</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="incidentComment">Comment</label>
                    <textarea id="incidentComment" placeholder="Details about the incident..."></textarea>
                </div>
                <div class="form-group">
                    <label>Affected Services</label>
                    <div class="affected-services" id="affectedServices"></div>
                    <button type="button" class="add-svc-btn" onclick="addServiceRow()">+ Add Service</button>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" id="deleteIncidentBtn" onclick="deleteIncident()" style="display: none; margin-right: auto;">Delete</button>
                    <button type="button" class="btn btn-secondary" onclick="closeIncidentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../api/service-status/';
        let allServices = [];
        let dashboardData = { services: [], incidents: [] };

        const IMPACT_LEVELS = [
            'Major Outage', 'Partial Outage', 'Degraded', 'Maintenance', 'Operational', 'No Disruption'
        ];

        const IMPACT_CSS = {
            'Major Outage': 'impact-major-outage',
            'Partial Outage': 'impact-partial-outage',
            'Degraded': 'impact-degraded',
            'Maintenance': 'impact-maintenance',
            'Operational': 'impact-operational',
            'No Disruption': 'impact-no-disruption'
        };

        const STATUS_CSS = {
            '3rd Party': 'incident-status-3rd-party',
            'Identified': 'incident-status-identified',
            'Investigating': 'incident-status-investigating',
            'Monitoring': 'incident-status-monitoring',
            'Resolved': 'incident-status-resolved'
        };

        document.addEventListener('DOMContentLoaded', loadDashboard);

        async function loadDashboard() {
            try {
                // Load services list for dropdowns
                const svcResp = await fetch(API_BASE + 'get_services.php');
                const svcData = await svcResp.json();
                if (svcData.success) {
                    allServices = svcData.services.filter(s => s.is_active);
                }

                // Load dashboard data
                const resp = await fetch(API_BASE + 'get_dashboard.php');
                const data = await resp.json();
                if (data.success) {
                    dashboardData = data;
                    renderServiceGrid(data.services);
                    renderIncidents(data.incidents);
                }
            } catch (error) {
                console.error('Failed to load dashboard:', error);
            }
        }

        function renderServiceGrid(services) {
            const grid = document.getElementById('serviceGrid');
            document.getElementById('serviceCount').textContent = services.length + ' services';

            if (services.length === 0) {
                grid.innerHTML = '<div class="empty-state">No services configured. Go to Settings to add services.</div>';
                return;
            }

            grid.innerHTML = services.map(svc => `
                <div class="service-card">
                    <div class="service-name">${escapeHtml(svc.name)}</div>
                    <div class="service-desc">${escapeHtml(svc.description || '')}</div>
                    <span class="impact-badge ${IMPACT_CSS[svc.current_status] || 'impact-operational'}">${escapeHtml(svc.current_status)}</span>
                </div>
            `).join('');
        }

        function renderIncidents(incidents) {
            const table = document.getElementById('incidentTable');
            const empty = document.getElementById('incidentEmpty');
            const tbody = document.getElementById('incidentList');

            if (incidents.length === 0) {
                table.style.display = 'none';
                empty.style.display = 'block';
                return;
            }

            table.style.display = 'table';
            empty.style.display = 'none';

            tbody.innerHTML = incidents.map(inc => {
                const isResolved = inc.status === 'Resolved';
                const statusCss = STATUS_CSS[inc.status] || '';
                const svcs = (inc.services || []).map(s =>
                    `<span class="incident-svc-tag ${IMPACT_CSS[s.impact_level] || ''}">${escapeHtml(s.service_name)}</span>`
                ).join('');

                const date = inc.updated_datetime || inc.created_datetime;
                const dateStr = date ? formatDate(date) : '';

                return `
                    <tr class="${isResolved ? 'resolved' : ''}">
                        <td><span class="incident-title" onclick="editIncident(${inc.id})">${escapeHtml(inc.title)}</span></td>
                        <td><span class="incident-status ${statusCss}">${escapeHtml(inc.status)}</span></td>
                        <td><div class="incident-services-list">${svcs || '<span style="color:#999">None</span>'}</div></td>
                        <td><span class="incident-date">${dateStr}</span></td>
                    </tr>
                `;
            }).join('');
        }

        function formatDate(dateStr) {
            try {
                const d = new Date(dateStr);
                return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
                       ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return dateStr;
            }
        }

        // --- Incident Modal ---

        function openIncidentModal() {
            document.getElementById('incidentModalTitle').textContent = 'New Incident';
            document.getElementById('incidentId').value = '';
            document.getElementById('incidentTitle').value = '';
            document.getElementById('incidentStatus').value = 'Investigating';
            document.getElementById('incidentComment').value = '';
            document.getElementById('affectedServices').innerHTML = '';
            document.getElementById('deleteIncidentBtn').style.display = 'none';
            addServiceRow();
            document.getElementById('incidentModal').classList.add('active');
        }

        function editIncident(id) {
            const inc = dashboardData.incidents.find(i => i.id == id);
            if (!inc) return;

            document.getElementById('incidentModalTitle').textContent = 'Edit Incident';
            document.getElementById('incidentId').value = inc.id;
            document.getElementById('incidentTitle').value = inc.title;
            document.getElementById('incidentStatus').value = inc.status;
            document.getElementById('incidentComment').value = inc.comment || '';
            document.getElementById('deleteIncidentBtn').style.display = 'inline-flex';

            const container = document.getElementById('affectedServices');
            container.innerHTML = '';

            if (inc.services && inc.services.length > 0) {
                inc.services.forEach(s => addServiceRow(s.service_id, s.impact_level));
            } else {
                addServiceRow();
            }

            document.getElementById('incidentModal').classList.add('active');
        }

        function addServiceRow(serviceId, impactLevel) {
            const container = document.getElementById('affectedServices');
            const row = document.createElement('div');
            row.className = 'affected-row';

            const svcOptions = allServices.map(s =>
                `<option value="${s.id}" ${s.id == serviceId ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
            ).join('');

            const impactOptions = IMPACT_LEVELS.map(level =>
                `<option value="${level}" ${level === (impactLevel || 'Degraded') ? 'selected' : ''}>${level}</option>`
            ).join('');

            row.innerHTML = `
                <select class="svc-select">${svcOptions}</select>
                <select class="impact-select">${impactOptions}</select>
                <button type="button" class="remove-svc" onclick="this.parentElement.remove()">&times;</button>
            `;

            container.appendChild(row);
        }

        function closeIncidentModal() {
            document.getElementById('incidentModal').classList.remove('active');
        }

        document.getElementById('incidentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const rows = document.querySelectorAll('#affectedServices .affected-row');
            const services = [];
            rows.forEach(row => {
                const svcId = row.querySelector('.svc-select').value;
                const impact = row.querySelector('.impact-select').value;
                if (svcId) {
                    services.push({ service_id: parseInt(svcId), impact_level: impact });
                }
            });

            const payload = {
                id: document.getElementById('incidentId').value || null,
                title: document.getElementById('incidentTitle').value,
                status: document.getElementById('incidentStatus').value,
                comment: document.getElementById('incidentComment').value,
                services: services
            };

            try {
                const response = await fetch(API_BASE + 'save_incident.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeIncidentModal();
                    loadDashboard();
                } else {
                    alert(data.error || 'Failed to save');
                }
            } catch (error) {
                alert('Failed to save incident');
            }
        });

        async function deleteIncident() {
            const id = document.getElementById('incidentId').value;
            if (!id || !confirm('Delete this incident?')) return;

            try {
                const response = await fetch(API_BASE + 'delete_incident.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: parseInt(id) })
                });
                const data = await response.json();
                if (data.success) {
                    closeIncidentModal();
                    loadDashboard();
                } else {
                    alert(data.error || 'Failed to delete');
                }
            } catch (error) {
                alert('Failed to delete incident');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Close modal on outside click
        document.getElementById('incidentModal').addEventListener('click', function(e) {
            if (e.target === this) closeIncidentModal();
        });
    </script>
</body>
</html>
