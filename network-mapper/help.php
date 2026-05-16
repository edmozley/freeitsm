<?php
/**
 * Network Mapper Module Help Guide — full page with left pane navigation.
 *
 * Mirrors the process-mapper/help.php structure (sidebar + hero + numbered
 * sections + scroll-spy). Cyan branding to match the module palette.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM &mdash; Network Mapper Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .nh-container { display: flex; height: calc(100vh - 48px); background: #f5f5f5; }

        /* ---- Sidebar nav ---- */
        .nh-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }
        .nh-sidebar h3 {
            font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .nh-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 13px; color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .nh-nav-link:hover { background: #f5f5f5; color: #333; }
        .nh-nav-link.active { background: #ecfeff; color: #0e7490; font-weight: 600; }
        .nh-nav-link.highlight { color: #0e7490; }
        .nh-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px;
            border-radius: 50%;
            background: #f5f5f5; color: #888;
            font-size: 11px; font-weight: 700;
        }
        .nh-nav-link.active .nh-nav-num { background: #06b6d4; color: white; }
        .nh-nav-num.highlight { background: #ecfeff; color: #0e7490; }
        .nh-nav-link.highlight.active .nh-nav-num { background: rgba(255,255,255,0.25); color: white; }

        /* ---- Main content ---- */
        .nh-main { flex: 1; overflow-y: auto; }
        .nh-hero {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .nh-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .nh-hero p  { margin: 0; font-size: 15px; opacity: 0.9; }
        .nh-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* ---- Sections ---- */
        .nh-section { padding: 28px 0; border-bottom: 1px solid #eee; scroll-margin-top: 20px; }
        .nh-section:last-child { border-bottom: 0; padding-bottom: 0; }
        .nh-section-header {
            display: flex; align-items: flex-start; gap: 14px;
            margin-bottom: 16px;
        }
        .nh-section-header h3 { margin: 0; font-size: 18px; color: #333; }
        .nh-section-header p  { margin: 6px 0 0; font-size: 14px; color: #666; line-height: 1.6; }
        .nh-section > p {
            font-size: 14px; color: #555; line-height: 1.7;
            margin: 0 0 14px;
        }
        .nh-section-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px;
            border-radius: 50%;
            background: #ecfeff; color: #0e7490;
            font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        .nh-section-num.highlight { background: #06b6d4; color: white; }

        /* ---- Feature card grid ---- */
        .nh-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .nh-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .nh-feature-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .nh-feature-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .nh-feature-icon.cyan   { background: #ecfeff; color: #0891b2; }
        .nh-feature-icon.blue   { background: #e3f2fd; color: #1565c0; }
        .nh-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .nh-feature-icon.amber  { background: #fff7ed; color: #c2410c; }
        .nh-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: #333; }
        .nh-feature-card p  { margin: 0; font-size: 12.5px; color: #666; line-height: 1.5; }

        /* ---- Numbered steps ---- */
        .nh-steps { display: flex; flex-direction: column; gap: 12px; margin-left: 46px; }
        .nh-step-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 10px 14px; border-radius: 8px;
            background: #fafafa;
            font-size: 14px; color: #444; line-height: 1.5;
        }
        .nh-step-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 28px; height: 28px;
            border-radius: 50%;
            background: #06b6d4; color: white;
            font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }

        /* ---- Highlighted section ---- */
        .nh-section-highlight {
            background: #ecfeff;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #a5f3fc;
        }
        .nh-intro {
            font-size: 14px; color: #555; line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* ---- Flow row ---- */
        .nh-flow {
            display: flex; align-items: center; gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }
        .nh-flow-step {
            display: flex; align-items: center; justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px; font-weight: 600;
            text-align: center;
        }
        .nh-flow-step.s1 { background: #ecfeff; color: #0e7490; }
        .nh-flow-step.s2 { background: #e3f2fd; color: #1565c0; }
        .nh-flow-step.s3 { background: #e8f5e9; color: #2e7d32; }
        .nh-flow-step.s4 { background: #fff3e0; color: #c2410c; }
        .nh-flow-arrow { padding: 0 8px; color: #bbb; font-size: 18px; }

        /* ---- Callouts ---- */
        .nh-tip {
            font-size: 13px !important;
            color: #0e7490 !important;
            background: #ecfeff;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #06b6d4;
            margin-top: 10px;
        }
        .nh-warn {
            font-size: 13px !important;
            color: #9a3412 !important;
            background: #fff7ed;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f97316;
            margin-top: 10px;
        }

        /* ---- Keyboard chip ---- */
        .nh-kbd {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            background: white;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 0 rgba(0,0,0,0.04);
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 11.5px;
            color: #334155;
        }

        /* ---- Tips grid ---- */
        .nh-tips-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .nh-tip-card {
            display: flex; gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }
        .nh-tip-icon { font-size: 24px; flex-shrink: 0; line-height: 1; }
        .nh-tip-card strong { color: #333; }

        /* ---- Pill mock-ups used in copy ---- */
        .nh-pill {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            vertical-align: middle;
        }
        .nh-pill.planned { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .nh-pill.current { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; }
        .nh-pill.readonly { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }

        /* ---- Responsive ---- */
        @media (max-width: 900px) {
            .nh-sidebar { display: none; }
            .nh-content { padding: 10px 24px 40px; }
            .nh-hero { padding: 30px 24px; }
            .nh-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }
        @media (max-width: 700px) {
            .nh-features-grid { grid-template-columns: 1fr; }
            .nh-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="nh-container">
        <!-- Sidebar nav -->
        <div class="nh-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="nh-nav-link active" data-section="overview">
                <span class="nh-nav-num">1</span> Overview
            </a>
            <a href="#creating" class="nh-nav-link highlight" data-section="creating">
                <span class="nh-nav-num highlight">2</span> Creating a diagram
            </a>
            <a href="#placing" class="nh-nav-link" data-section="placing">
                <span class="nh-nav-num">3</span> Placing nodes
            </a>
            <a href="#connectors" class="nh-nav-link" data-section="connectors">
                <span class="nh-nav-num">4</span> Drawing connectors
            </a>
            <a href="#related" class="nh-nav-link highlight" data-section="related">
                <span class="nh-nav-num highlight">5</span> Adding related objects
            </a>
            <a href="#planned" class="nh-nav-link" data-section="planned">
                <span class="nh-nav-num">6</span> Planned objects
            </a>
            <a href="#versioning" class="nh-nav-link" data-section="versioning">
                <span class="nh-nav-num">7</span> Versioning
            </a>
            <a href="#saving" class="nh-nav-link" data-section="saving">
                <span class="nh-nav-num">8</span> Saving
            </a>
            <a href="#tips" class="nh-nav-link" data-section="tips">
                <span class="nh-nav-num">9</span> Quick tips
            </a>
        </div>

        <!-- Main content -->
        <div class="nh-main" id="helpMain">
            <div class="nh-hero">
                <h2>Network Mapper guide</h2>
                <p>Draw your network and architecture diagrams over the top of the CMDB &mdash; every box you place is a real object the rest of the platform knows about.</p>
            </div>

            <div class="nh-content">

                <!-- 1. Overview -->
                <div class="nh-section" id="overview">
                    <div class="nh-section-header">
                        <span class="nh-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>Network Mapper is a visual layer on top of the CMDB. Each node on the canvas is a binding to a real <code>cmdb_objects</code> row, so the diagram doesn't drift from what the rest of the platform knows about your estate. Move a node, the binding stays. Delete an object in CMDB, the diagram updates. Want a future-state architecture diagram? Mark the objects as planned in CMDB &mdash; they'll render with a dashed amber border on the diagram automatically.</p>
                        </div>
                    </div>

                    <div class="nh-flow">
                        <div class="nh-flow-step s1">Create a diagram</div>
                        <div class="nh-flow-arrow">&rarr;</div>
                        <div class="nh-flow-step s2">Drag objects in</div>
                        <div class="nh-flow-arrow">&rarr;</div>
                        <div class="nh-flow-step s3">Draw connectors</div>
                        <div class="nh-flow-arrow">&rarr;</div>
                        <div class="nh-flow-step s4">Save</div>
                    </div>

                    <div class="nh-features-grid">
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon cyan">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="6" height="6"/><rect x="14" y="14" width="6" height="6"/><line x1="10" y1="7" x2="14" y2="14"/></svg>
                            </div>
                            <h4>CMDB-bound nodes</h4>
                            <p>Every node references a real CMDB object &mdash; click through to its detail page from the side panel.</p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon blue">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="12" r="3"/><circle cx="18" cy="12" r="3"/><line x1="9" y1="12" x2="15" y2="12"/></svg>
                            </div>
                            <h4>Provenance-linked connectors</h4>
                            <p>Drawing a connector via Add related objects writes the CMDB relationship id, so the line traces back to a real link.</p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon green">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7L9 18l-5-5"/></svg>
                            </div>
                            <h4>Autosave + manual save</h4>
                            <p>Toggle autosave on for ~2-second debounced background saves, or use <span class="nh-kbd">Ctrl</span>+<span class="nh-kbd">S</span> any time.</p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4>Linear version history</h4>
                            <p>Save-as-new-version forks the current diagram forward; older versions become read-only historical records.</p>
                        </div>
                    </div>
                </div>

                <!-- 2. Creating -->
                <div class="nh-section" id="creating">
                    <div class="nh-section-header">
                        <span class="nh-section-num highlight">2</span>
                        <div>
                            <h3>Creating a diagram</h3>
                            <p>From the Diagrams landing page, hit <strong>+ New diagram</strong>. Give it a title (e.g. <em>Production stack &mdash; web tier</em>), an optional description, and a starting version label (default <code>v1</code>). You'll land straight in the editor.</p>
                        </div>
                    </div>
                    <p class="nh-tip"><strong>Tip:</strong> Diagrams are intended to be focussed views, not exhaustive maps. One diagram per system, environment, or change is usually the right grain. You can always pull in extra related objects later.</p>
                </div>

                <!-- 3. Placing nodes -->
                <div class="nh-section" id="placing">
                    <div class="nh-section-header">
                        <span class="nh-section-num">3</span>
                        <div>
                            <h3>Placing nodes</h3>
                            <p>The left palette lists every active CMDB class with its icon and object count. Drag a class tile onto the canvas, drop opens a picker scoped to that class &mdash; type to filter, arrow keys to navigate, Enter to pick. The node lands at the drop coordinates, snapped to the 20-pixel grid, with the chosen object's name as the label.</p>
                        </div>
                    </div>

                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div>Drag a class tile from the left palette onto the canvas.</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div>Type in the picker to filter by name (Up/Down + Enter also work).</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div>Click an object to place it &mdash; the node appears at the drop point.</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">4</span><div>Click to select, drag to move, <span class="nh-kbd">Delete</span> to remove.</div></div>
                    </div>

                    <p class="nh-tip"><strong>Already on the canvas?</strong> Objects you've already placed are filtered out of the picker so you can't accidentally place the same object twice on one diagram.</p>
                </div>

                <!-- 4. Connectors -->
                <div class="nh-section" id="connectors">
                    <div class="nh-section-header">
                        <span class="nh-section-num">4</span>
                        <div>
                            <h3>Drawing connectors</h3>
                            <p>Hover or select a node &mdash; four small cyan dots appear at the edges of the icon. Mousedown on a dot, drag to another node, mouseup to create the connector. A dashed cyan line tracks the cursor while you drag so you can see where it'll land.</p>
                        </div>
                    </div>

                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div><strong>Draw:</strong> mousedown on an edge dot &rarr; drag to target node &rarr; mouseup creates an arrow.</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div><strong>Select:</strong> click any connector &mdash; it turns cyan with a thicker stroke.</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div><strong>Label:</strong> double-click a connector &mdash; an inline text input opens at the midpoint (Enter saves, Esc cancels).</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">4</span><div><strong>Delete:</strong> select a connector and press <span class="nh-kbd">Delete</span>.</div></div>
                    </div>

                    <p class="nh-tip"><strong>Direction matters:</strong> arrows point from <em>source</em> to <em>target</em> in the order you drew them. If you want to flip an arrow, delete it and re-draw from the other end.</p>
                </div>

                <!-- 5. Add related objects -->
                <div class="nh-section nh-section-highlight" id="related">
                    <div class="nh-section-header">
                        <span class="nh-section-num highlight">5</span>
                        <div>
                            <h3>Adding related objects</h3>
                            <p>This is the killer feature. Click a placed node &mdash; the detail panel slides in beside the canvas. Hit <strong>Add related objects</strong> and the modal lists every CMDB object connected to this one across three buckets:</p>
                        </div>
                    </div>

                    <div class="nh-features-grid" style="margin-top: 14px;">
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon cyan">&rarr;</div>
                            <h4>This object &rarr; others</h4>
                            <p>Outgoing relationships &mdash; what this object depends on, hosts, owns, etc.</p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon blue">&larr;</div>
                            <h4>Others &rarr; this object</h4>
                            <p>Incoming relationships &mdash; what depends on it, what it's part of, what hosts it.</p>
                        </div>
                        <div class="nh-feature-card">
                            <div class="nh-feature-icon green">&loz;</div>
                            <h4>Referenced by properties</h4>
                            <p>Other objects that point at this one via an object-ref property (e.g. "Owner = Jane").</p>
                        </div>
                    </div>

                    <p style="margin-top: 14px;">Tick the rows you want, hit <strong>Add</strong>, and the selected objects get placed in a ring around the source node with a connector each. The relationship verb becomes the connector label, and the connector is provenance-linked back to the real CMDB relationship row when applicable.</p>
                    <p class="nh-tip"><strong>Why this matters:</strong> CMDB usually has way more information than fits on one diagram. Add related objects gives you <em>guided exploration</em> &mdash; start from one object you care about, and pull in only the neighbours you actually want to show.</p>
                </div>

                <!-- 6. Planned objects -->
                <div class="nh-section" id="planned">
                    <div class="nh-section-header">
                        <span class="nh-section-num">6</span>
                        <div>
                            <h3>Planned objects (future-state architecture)</h3>
                            <p>If an object is marked as <span class="nh-pill planned">PLANNED</span> in CMDB (i.e. it's part of your future-state architecture but not yet real), it renders on the diagram with a dashed amber border, an italic amber label, and a small PLANNED pill above the icon. This turns any diagram into a visual as-is/to-be map without needing two separate diagrams.</p>
                        </div>
                    </div>
                    <p class="nh-tip"><strong>Workflow:</strong> mark CMDB objects as planned during design, draw them into the diagram alongside your real estate, then flip the planned flag off in CMDB when they go live &mdash; the diagram styling updates on its next load. No edits to the diagram needed.</p>
                </div>

                <!-- 7. Versioning -->
                <div class="nh-section" id="versioning">
                    <div class="nh-section-header">
                        <span class="nh-section-num">7</span>
                        <div>
                            <h3>Versioning</h3>
                            <p>Every diagram is part of a linear version chain. The leaf (no children) is the editable <span class="nh-pill current">v? (current)</span> version; older nodes in the chain are read-only history <span class="nh-pill readonly">v? (read-only)</span>. Saving as a new version clones the current state forward into a new editable leaf and demotes the old leaf to historical.</p>
                        </div>
                    </div>
                    <div class="nh-steps">
                        <div class="nh-step-item"><span class="nh-step-num">1</span><div>Edit the current version freely &mdash; changes save in place via the Save button or autosave.</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">2</span><div>When you want a snapshot, click <strong>Save as new version</strong> &mdash; the old state becomes the historical record, you continue on the new leaf.</div></div>
                        <div class="nh-step-item"><span class="nh-step-num">3</span><div>Historical versions open read-only &mdash; click any node or connector to inspect, but you can't modify them.</div></div>
                    </div>
                    <p class="nh-warn"><strong>No branching:</strong> a parent can have at most one child in the chain &mdash; the history is strictly linear. If you need to explore an alternative architecture, create a separate diagram rather than forking the chain.</p>
                </div>

                <!-- 8. Saving -->
                <div class="nh-section" id="saving">
                    <div class="nh-section-header">
                        <span class="nh-section-num">8</span>
                        <div>
                            <h3>Saving</h3>
                            <p>Two modes. <strong>Autosave</strong> (toggle in the toolbar) saves around 2 seconds after your last edit &mdash; the Word-style status indicator next to the toggle shows <em>Unsaved</em>, <em>Saving&hellip;</em>, then <em>Saved</em>. Toggle state is remembered per analyst. <strong>Manual save</strong> via the Save button or <span class="nh-kbd">Ctrl</span>+<span class="nh-kbd">S</span> works in either mode.</p>
                        </div>
                    </div>
                    <p class="nh-tip"><strong>Mid-drag is safe:</strong> autosave defers if you're dragging a node, so the diagram doesn't snap back to its last-saved position underneath you.</p>
                    <p class="nh-warn"><strong>Unsaved changes:</strong> if you try to navigate away with unsaved edits, the browser will prompt you. Don't ignore that prompt unless you really mean to discard.</p>
                </div>

                <!-- 9. Quick tips -->
                <div class="nh-section" id="tips">
                    <div class="nh-section-header">
                        <span class="nh-section-num">9</span>
                        <div>
                            <h3>Quick tips</h3>
                        </div>
                    </div>
                    <div class="nh-tips-grid">
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#8984;</span><div><strong>Ctrl+S</strong> saves regardless of autosave state.</div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x2316;</span><div><strong>Esc</strong> closes any open modal (picker, related-objects, save-as-version) and the detail panel.</div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x2715;</span><div>Click the empty canvas to deselect &mdash; closes the detail panel too.</div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x21BB;</span><div>Move the source node and connectors track its new position live.</div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x2713;</span><div>The picker filters out objects already on the canvas so you can't double-place.</div></div>
                        <div class="nh-tip-card"><span class="nh-tip-icon">&#x21AA;</span><div>Click the CMDB link in the detail panel to open the object's full page in a new tab.</div></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.nh-nav-link');
        const sections = [];
        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el: el });
        });
        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0] && sections[0].id;
            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === current));
        });
        // Smooth-scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const el = document.getElementById(this.dataset.section);
                if (el) {
                    const containerTop = helpMain.getBoundingClientRect().top;
                    const elTop = el.getBoundingClientRect().top;
                    helpMain.scrollTo({ top: helpMain.scrollTop + (elTop - containerTop) - 20, behavior: 'smooth' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
