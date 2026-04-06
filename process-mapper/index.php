<?php
/**
 * Process Mapper - Visual flowchart builder
 */
session_start();
require_once '../config.php';

$current_page = 'process-mapper';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Process Mapper</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/process-mapper.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="pm-layout">
        <!-- Sidebar: process list -->
        <div class="pm-sidebar" id="pmSidebar">
            <div class="pm-sidebar-header">
                <button class="btn btn-primary btn-full" onclick="PM.createProcess()">+ New Process</button>
            </div>
            <div class="pm-sidebar-search">
                <input type="text" id="processSearch" placeholder="Search processes..." oninput="PM.filterProcesses(this.value)">
            </div>
            <div class="pm-process-list" id="processList">
                <div class="pm-empty">No processes yet</div>
            </div>
        </div>

        <!-- Canvas area -->
        <div class="pm-canvas-wrap" id="canvasWrap">
            <!-- Toolbar -->
            <div class="pm-toolbar" id="pmToolbar">
                <div class="pm-toolbar-left">
                    <button class="pm-tool-btn" data-type="process" title="Process step">
                        <svg width="18" height="18" viewBox="0 0 18 18"><rect x="1" y="3" width="16" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span>Process</span>
                    </button>
                    <button class="pm-tool-btn" data-type="decision" title="Decision">
                        <svg width="18" height="18" viewBox="0 0 18 18"><polygon points="9,1 17,9 9,17 1,9" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span>Decision</span>
                    </button>
                    <button class="pm-tool-btn" data-type="start" title="Start / End">
                        <svg width="18" height="18" viewBox="0 0 18 18"><ellipse cx="9" cy="9" rx="8" ry="5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span>Terminal</span>
                    </button>
                    <button class="pm-tool-btn" data-type="document" title="Document">
                        <svg width="18" height="18" viewBox="0 0 18 18"><path d="M2 2h14v12c-2.3 1.3-4.7 1.3-7 0s-4.7-1.3-7 0V2z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span>Document</span>
                    </button>
                    <div class="pm-tool-sep"></div>
                    <button class="pm-tool-btn" id="connectBtn" title="Draw connector (or drag from edge handle)">
                        <svg width="18" height="18" viewBox="0 0 18 18"><line x1="3" y1="15" x2="15" y2="3" stroke="currentColor" stroke-width="1.5"/><polyline points="10,3 15,3 15,8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span>Connect</span>
                    </button>
                </div>
                <div class="pm-toolbar-right">
                    <button class="pm-tool-btn" onclick="PM.deleteSelected()" title="Delete selected (Del)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                    <div class="pm-tool-sep"></div>
                    <button class="pm-tool-btn" onclick="PM.save()" title="Save (Ctrl+S)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Save</span>
                    </button>
                </div>
            </div>

            <!-- Canvas with dot grid -->
            <div class="pm-canvas" id="pmCanvas" tabindex="0">
                <svg class="pm-connectors-svg" id="connectorsSvg"></svg>
                <!-- Steps are rendered here as absolutely positioned divs -->
                <div class="pm-canvas-empty" id="canvasEmpty">
                    <p>Select a process from the sidebar or create a new one</p>
                </div>
            </div>
        </div>

        <!-- Detail panel (slides in) -->
        <div class="pm-detail-panel" id="detailPanel">
            <div class="pm-detail-header">
                <h3 id="detailTitle">Step Details</h3>
                <button class="pm-detail-close" onclick="PM.closeDetail()">&times;</button>
            </div>
            <div class="pm-detail-body">
                <div class="form-group">
                    <label class="form-label">Label</label>
                    <input type="text" class="form-input" id="detailLabel" oninput="PM.updateStepFromDetail()">
                </div>
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select class="form-input" id="detailType" onchange="PM.updateStepFromDetail()">
                        <option value="process">Process</option>
                        <option value="decision">Decision</option>
                        <option value="start">Terminal (Start/End)</option>
                        <option value="document">Document</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Colour</label>
                    <input type="color" class="form-input" id="detailColor" value="#0078d4" onchange="PM.updateStepFromDetail()" style="height: 36px; padding: 2px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-input" id="detailDescription" rows="5" oninput="PM.updateStepFromDetail()" placeholder="Add notes about this step..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Position</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" class="form-input" id="detailX" style="width: 50%;" onchange="PM.updateStepFromDetail()">
                        <input type="number" class="form-input" id="detailY" style="width: 50%;" onchange="PM.updateStepFromDetail()">
                    </div>
                </div>
                <hr style="margin: 16px 0; border: none; border-top: 1px solid #e0e0e0;">
                <h4 style="margin-bottom: 12px; font-size: 13px; color: #666;">Connectors</h4>
                <div id="detailConnectors"></div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>window.API_BASE = '../api/process-mapper/';</script>
    <script src="../assets/js/process-mapper.js"></script>
</body>
</html>
