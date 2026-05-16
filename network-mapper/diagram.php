<?php
/**
 * Network Mapper — Diagram editor (Chunk A stub).
 *
 * Loads a single diagram by ?id= and renders the editor shell. Chunk A only
 * shows the title bar, version pill, description and an empty canvas — drag /
 * bind / connect comes in later chunks. The shell is here now so the routing
 * from the landing page works and we can iterate on layout.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$diagramId = (int)($_GET['id'] ?? 0);
if ($diagramId <= 0) {
    header('Location: index.php');
    exit;
}

$current_page = 'diagrams';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM &mdash; Network Diagram</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <style>
        body { background: #f5f5f5; height: 100vh; overflow: hidden; }

        .nm-editor {
            height: calc(100vh - 60px);
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
        }

        .nm-editor-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            gap: 16px;
        }
        .nm-editor-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }
        .nm-back-btn {
            background: transparent;
            border: 1px solid #e5e7eb;
            color: #6b7280;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            flex-shrink: 0;
        }
        .nm-back-btn:hover { background: #f9fafb; color: #111827; }
        .nm-editor-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nm-version-pill {
            display: inline-block;
            background: #ecfeff;
            color: #0e7490;
            border: 1px solid #a5f3fc;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .nm-version-pill.readonly { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }

        .nm-editor-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
        }
        .nm-status {
            font-size: 12px;
            color: #6b7280;
            min-width: 80px;
            text-align: right;
        }
        .nm-btn {
            padding: 7px 14px;
            background: #06b6d4;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
        }
        .nm-btn:hover { background: #0891b2; }
        .nm-btn.secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
        .nm-btn.secondary:hover { background: #f9fafb; }
        .nm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .nm-canvas-wrap {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        .nm-palette {
            width: 220px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .nm-palette-header {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .nm-palette-body {
            flex: 1;
            overflow-y: auto;
            padding: 14px 16px;
            color: #9ca3af;
            font-size: 13px;
            line-height: 1.5;
        }

        .nm-canvas {
            flex: 1;
            position: relative;
            background:
                radial-gradient(circle, #d1d5db 1px, transparent 1px) 0 0 / 20px 20px,
                #fafbfc;
            overflow: auto;
        }
        .nm-canvas-empty {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
            max-width: 360px;
        }
        .nm-canvas-empty h3 { color: #6b7280; font-weight: 600; margin: 0 0 6px 0; }

        .nm-meta-row {
            font-size: 12px;
            color: #6b7280;
            padding: 8px 20px;
            background: #fafbfc;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 18px;
        }
        .nm-meta-row strong { color: #374151; font-weight: 500; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="nm-editor">
        <div class="nm-editor-bar">
            <div class="nm-editor-title-area">
                <button class="nm-back-btn" onclick="window.location.href='index.php'">&larr; All diagrams</button>
                <h1 class="nm-editor-title" id="diagramTitle">Loading&hellip;</h1>
                <span class="nm-version-pill" id="versionPill" style="display:none;"></span>
            </div>
            <div class="nm-editor-actions">
                <span class="nm-status" id="saveStatus"></span>
                <button class="nm-btn secondary" onclick="alert('Coming in next chunk')">Save as new version</button>
                <button class="nm-btn" id="saveBtn" onclick="saveDiagram()" disabled>Save</button>
            </div>
        </div>

        <div class="nm-meta-row" id="metaRow" style="display:none;">
            <span><strong>Author:</strong> <span id="metaAuthor">&mdash;</span></span>
            <span><strong>Created:</strong> <span id="metaCreated">&mdash;</span></span>
            <span><strong>Updated:</strong> <span id="metaUpdated">&mdash;</span></span>
        </div>

        <div class="nm-canvas-wrap">
            <aside class="nm-palette">
                <div class="nm-palette-header">CMDB classes</div>
                <div class="nm-palette-body">
                    Drag-to-canvas comes in the next chunk. The palette will list every CMDB class with its icon, and dragging a class onto the canvas will open a picker to bind a specific CMDB object.
                </div>
            </aside>
            <div class="nm-canvas" id="canvas">
                <div class="nm-canvas-empty">
                    <h3>Editor coming soon</h3>
                    <p>The diagram shell is in place &mdash; drag-to-canvas, bind-to-CMDB, relationship pull-in and autosave land in the next iteration.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API = '../api/network-mapper/';
        const DIAGRAM_ID = <?php echo $diagramId; ?>;
        let diagram = null;

        async function loadDiagram() {
            try {
                const resp = await fetch(API + 'get_diagram.php?id=' + DIAGRAM_ID);
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                diagram = data.diagram;
                renderHeader();
            } catch (e) {
                document.getElementById('diagramTitle').textContent = 'Failed to load diagram';
                document.getElementById('saveStatus').textContent = e.message;
            }
        }

        function renderHeader() {
            document.title = 'FreeITSM — ' + (diagram.title || 'Network Diagram');
            document.getElementById('diagramTitle').textContent = diagram.title || '(untitled)';

            const pill = document.getElementById('versionPill');
            const label = diagram.version_label || 'v?';
            if (diagram.is_current) {
                pill.className = 'nm-version-pill';
                pill.textContent = label + ' (current)';
            } else {
                pill.className = 'nm-version-pill readonly';
                pill.textContent = label + ' (read-only)';
            }
            pill.style.display = '';

            document.getElementById('metaRow').style.display = '';
            document.getElementById('metaAuthor').textContent = diagram.author_name || 'Unknown';
            document.getElementById('metaCreated').textContent = formatDate(diagram.created_datetime);
            document.getElementById('metaUpdated').textContent = formatDate(diagram.updated_datetime);
        }

        function formatDate(s) {
            if (!s) return '—';
            try { return new Date(s.replace(' ', 'T') + 'Z').toLocaleString(); }
            catch (e) { return s; }
        }

        function saveDiagram() {
            // Wired up in the next chunk when there's actual canvas content.
            window.showToast ? showToast('Save lands with the editor in the next chunk', 'info') : alert('Save lands in the next chunk');
        }

        loadDiagram();
    </script>
</body>
</html>
