<?php
/**
 * Process Mapper Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

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
    <title>Service Desk - Process Mapper Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .pm-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .pm-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .pm-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .pm-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .pm-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .pm-help-nav-link.active {
            background: #eef2ff;
            color: #3730a3;
            font-weight: 600;
        }

        .pm-help-nav-link.highlight {
            color: #3730a3;
        }

        .pm-help-nav-link.highlight.active {
            background: #4f46e5;
            color: white;
        }

        .pm-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #eee;
            color: #888;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .pm-help-nav-link.active .pm-help-nav-num {
            background: #6366f1;
            color: white;
        }

        .pm-help-nav-num.highlight {
            background: #eef2ff;
            color: #3730a3;
        }

        .pm-help-nav-link.highlight.active .pm-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .pm-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .pm-help-hero {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 50%, #4338ca 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .pm-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .pm-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .pm-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .pm-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .pm-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .pm-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .pm-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .pm-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .pm-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .pm-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #eef2ff;
            color: #3730a3;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .pm-help-section-num.highlight {
            background: #6366f1;
            color: white;
        }

        /* Feature cards grid */
        .pm-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .pm-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .pm-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .pm-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .pm-help-feature-icon.indigo { background: #eef2ff; color: #4f46e5; }
        .pm-help-feature-icon.blue   { background: #e3f2fd; color: #1565c0; }
        .pm-help-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .pm-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .pm-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .pm-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .pm-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .pm-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: #fafafa;
            font-size: 14px;
            color: #444;
            line-height: 1.5;
        }

        .pm-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #6366f1;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .pm-help-section-highlight {
            background: #eef2ff;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #c7d2fe;
        }

        .pm-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .pm-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .pm-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards */
        .pm-help-data-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .pm-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #6366f1;
        }

        .pm-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .pm-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Shape preview swatches */
        .pm-help-shape {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            margin-right: 8px;
            color: #4f46e5;
            vertical-align: middle;
        }

        /* Flow diagram */
        .pm-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pm-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .pm-help-flow-step.create { background: #eef2ff; color: #3730a3; }
        .pm-help-flow-step.draw   { background: #e3f2fd; color: #1565c0; }
        .pm-help-flow-step.connect{ background: #e8f5e9; color: #2e7d32; }
        .pm-help-flow-step.save   { background: #fff3e0; color: #e65100; }

        .pm-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .pm-help-tip {
            font-size: 13px !important;
            color: #3730a3 !important;
            background: #eef2ff;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #6366f1;
            margin-top: 10px;
        }

        /* Keyboard shortcut chip */
        .pm-help-kbd {
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

        /* Quick tips grid */
        .pm-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .pm-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .pm-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .pm-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .pm-help-sidebar { display: none; }
            .pm-help-content { padding: 10px 24px 40px; }
            .pm-help-hero { padding: 30px 24px; }
            .pm-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .pm-help-features-grid { grid-template-columns: 1fr; }
            .pm-help-data-grid { grid-template-columns: 1fr; }
            .pm-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="pm-help-container">
        <!-- Left pane navigation -->
        <div class="pm-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="pm-help-nav-link active" data-section="overview">
                <span class="pm-help-nav-num">1</span>
                Overview
            </a>
            <a href="#creating" class="pm-help-nav-link highlight" data-section="creating">
                <span class="pm-help-nav-num highlight">2</span>
                Creating a process
            </a>
            <a href="#step-types" class="pm-help-nav-link" data-section="step-types">
                <span class="pm-help-nav-num">3</span>
                Step types
            </a>
            <a href="#connectors" class="pm-help-nav-link" data-section="connectors">
                <span class="pm-help-nav-num">4</span>
                Drawing connectors
            </a>
            <a href="#arranging" class="pm-help-nav-link highlight" data-section="arranging">
                <span class="pm-help-nav-num highlight">5</span>
                Arranging &amp; editing
            </a>
            <a href="#saving" class="pm-help-nav-link" data-section="saving">
                <span class="pm-help-nav-num">6</span>
                Saving &amp; loading
            </a>
            <a href="#tips" class="pm-help-nav-link" data-section="tips">
                <span class="pm-help-nav-num">7</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="pm-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="pm-help-hero">
                <h2>Process Mapper guide</h2>
                <p>Sketch out your team's processes as visual flowcharts &mdash; steps, decisions, and connectors on a snap-to-grid canvas.</p>
            </div>

            <div class="pm-help-content">

                <!-- Section 1: Overview -->
                <div class="pm-help-section" id="overview">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>Process Mapper is a lightweight flowchart builder for documenting how things actually get done &mdash; incident triage, onboarding, change approval, escalation paths, anything you would normally sketch on a whiteboard. The canvas uses a dot grid with snap-to-grid placement so diagrams stay tidy without much effort, and every process is saved server-side so the whole team works from the same source of truth.</p>
                        </div>
                    </div>

                    <div class="pm-help-flow">
                        <div class="pm-help-flow-step create">Create a process</div>
                        <div class="pm-help-flow-arrow">&rarr;</div>
                        <div class="pm-help-flow-step draw">Add steps</div>
                        <div class="pm-help-flow-arrow">&rarr;</div>
                        <div class="pm-help-flow-step connect">Connect them</div>
                        <div class="pm-help-flow-arrow">&rarr;</div>
                        <div class="pm-help-flow-step save">Save &amp; share</div>
                    </div>

                    <div class="pm-help-features-grid">
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon indigo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1" fill="currentColor"/><circle cx="14" cy="8" r="1" fill="currentColor"/><circle cx="20" cy="8" r="1" fill="currentColor"/><circle cx="8" cy="14" r="1" fill="currentColor"/><circle cx="14" cy="14" r="1" fill="currentColor"/><circle cx="20" cy="14" r="1" fill="currentColor"/></svg>
                            </div>
                            <h4>Snap-to-grid canvas</h4>
                            <p>Steps snap to a 20-pixel dot grid as you drag them, so spacing stays even and lines run cleanly without manual nudging.</p>
                        </div>
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="6" height="12" rx="1"/><polygon points="12,4 18,12 12,20 6,12" transform="translate(2 0)"/><ellipse cx="20" cy="12" rx="3" ry="2"/></svg>
                            </div>
                            <h4>Four step types</h4>
                            <p>Process boxes, decision diamonds, terminal ovals for start/end, and document shapes for outputs &mdash; everything a standard flowchart needs.</p>
                        </div>
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="17" x2="15" y2="5"/><polyline points="10,5 15,5 15,10"/><circle cx="3" cy="17" r="2"/></svg>
                            </div>
                            <h4>Labelled connectors</h4>
                            <p>Draw arrows between steps from edge handles or via the Connect tool. Add inline labels like "Yes", "No", or "Approved" to clarify branches.</p>
                        </div>
                        <div class="pm-help-feature-card">
                            <div class="pm-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                            </div>
                            <h4>Saved &amp; searchable</h4>
                            <p>Every process is saved to the server and listed in the sidebar with a search box, so the whole team can open the same diagram from any browser.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Creating a process (highlighted) -->
                <div class="pm-help-section pm-help-section-highlight" id="creating">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num highlight">2</span>
                        <h3>Creating a process</h3>
                    </div>
                    <p class="pm-help-intro">A process is one diagram &mdash; usually a single workflow with a start, a few steps, maybe a decision or two, and an end. Start by creating an empty process from the sidebar, then build it up step by step on the canvas.</p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <strong>Click "+ New Process"</strong> &mdash; the button at the top of the left sidebar creates a new, empty process and selects it. You will be prompted for a title; keep it short and recognisable, e.g. "Incident triage" or "New starter onboarding".
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <strong>Add the first step</strong> &mdash; click any of the shape buttons in the toolbar (Process, Decision, Terminal, Document) to drop a new step on the canvas. Most processes start with a Terminal labelled "Start" or describing the trigger event.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <strong>Set its label</strong> &mdash; double-click the step to rename it inline, or single-click to open the slide-in detail panel on the right where you can change label, type, description, and colour.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <strong>Build out the rest</strong> &mdash; keep adding steps and connectors until the diagram tells the full story. Use Decision diamonds for any "yes/no" or branching points, and Document shapes for outputs like reports, certificates, or sign-offs.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">5</div>
                            <div>
                                <strong>Save</strong> &mdash; press <span class="pm-help-kbd">Ctrl</span>+<span class="pm-help-kbd">S</span> or click the Save button in the toolbar. The whole process is sent to the server in one go &mdash; steps, connectors, positions, and all.
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip">If you have already imported the demo data, the sidebar comes pre-populated with six worked examples (incident triage, onboarding, change approval, major incident response, asset disposal, and password reset) that you can open and tweak as starting points.</p>
                </div>

                <!-- Section 3: Step types -->
                <div class="pm-help-section" id="step-types">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">3</span>
                        <h3>Step types</h3>
                    </div>
                    <p>The toolbar exposes four shape types, each with its own conventional meaning in flowchart notation. Pick the one that matches what the step actually does &mdash; readers will pick up the structure of the diagram much faster when shapes are used consistently.</p>

                    <div class="pm-help-data-grid">
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><rect x="1" y="3" width="16" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>Process</strong>
                            <span>A standard action or task &mdash; "Create AD account", "Send notification", "Run backup". The default shape for most steps. Rectangle, 160&times;80.</span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><polygon points="9,1 17,9 9,17 1,9" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>Decision</strong>
                            <span>A branching point with two or more outcomes &mdash; "Approved?", "Priority?", "MFA enrolled?". Use connector labels (Yes/No, P1/P2/P3) to mark each branch. Diamond, 140&times;140.</span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><ellipse cx="9" cy="9" rx="8" ry="5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>Terminal</strong>
                            <span>The start or end of a flow &mdash; "Ticket received", "Onboarding complete", "End". Every diagram should have at least one terminal at the start. Oval, 160&times;50.</span>
                        </div>
                        <div class="pm-help-data-card">
                            <strong><svg class="pm-help-shape" viewBox="0 0 18 18"><path d="M2 2h14v12c-2.3 1.3-4.7 1.3-7 0s-4.7-1.3-7 0V2z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>Document</strong>
                            <span>An artefact produced or consumed by the flow &mdash; "Post-incident review", "Destruction certificate", "Approval form". Wavy-bottomed rectangle, 160&times;80.</span>
                        </div>
                    </div>

                    <p class="pm-help-tip">The detail panel lets you change a step's type after it is placed, so you can re-shape an existing step (for example, promoting a Process box to a Decision diamond) without having to delete and recreate it.</p>
                </div>

                <!-- Section 4: Drawing connectors -->
                <div class="pm-help-section" id="connectors">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">4</span>
                        <h3>Drawing connectors</h3>
                    </div>
                    <p>Connectors are the arrows between steps. Process Mapper offers a few ways to draw them &mdash; pick whichever feels more natural in the moment.</p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <strong>Drag from an edge handle</strong> &mdash; hover over any step and small handles appear on its top, bottom, left and right edges. Click and drag from a handle to the target step; release to drop the connector. Quickest way for one-off arrows.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <strong>Use the Connect tool</strong> &mdash; click the Connect button in the toolbar to enter connect mode. Click the source step, then the target step, and a connector is drawn between them. Click Connect again (or press <span class="pm-help-kbd">Esc</span>) to leave the mode.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <strong>Add a label</strong> &mdash; double-click any connector to add or edit a short text label. Useful on Decision branches (Yes / No, Approved / Rejected, P1 / P2 / P3) and any time the meaning of an arrow is not obvious from context.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <strong>Connect to anywhere</strong> &mdash; you do not need to pick a specific edge on the target step. Drop the connector anywhere on the destination and Process Mapper picks the closest edge automatically, redrawing if you later move either step.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">5</div>
                            <div>
                                <strong>Right-click to branch off a step</strong> &mdash; right-click any step and choose <em>Create new</em>, then pick a shape (Process, Decision, Terminal or Document). A new step of that type is dropped just to the right, already connected from the step you clicked, with the detail panel open and the cursor in the label box &mdash; name it and carry on. The quickest way to build a flow out left-to-right.
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip">Connector routing is recomputed every time you drag a step, so arrows always run to the nearest edges. You never need to redraw an arrow just because you moved a box around.</p>
                </div>

                <!-- Section 5: Arranging & editing (highlighted) -->
                <div class="pm-help-section pm-help-section-highlight" id="arranging">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num highlight">5</span>
                        <h3>Arranging &amp; editing</h3>
                    </div>
                    <p class="pm-help-intro">Once the steps are in place, the canvas behaves like any other diagramming tool &mdash; drag, multi-select, nudge, recolour, delete. Everything snaps to the underlying 20-pixel grid so the diagram stays neat without any fiddling.</p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <strong>Drag to move</strong> &mdash; click and drag any step to reposition it. The step snaps to the dot grid on release. Connected arrows reroute automatically.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <strong>Multi-select</strong> &mdash; <span class="pm-help-kbd">Ctrl</span>+click toggles individual steps in and out of the selection, or rubber-band by dragging on empty canvas. Press <span class="pm-help-kbd">Ctrl</span>+<span class="pm-help-kbd">A</span> to select everything.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <strong>Nudge with arrow keys</strong> &mdash; once steps are selected, use the arrow keys to nudge them one grid square at a time. Handy for fine adjustments after a rough drag.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">4</div>
                            <div>
                                <strong>The detail panel</strong> &mdash; clicking a single step slides in a panel from the right with everything you can edit: label, type, description, colour, and exact x/y coordinates. Updates apply instantly as you type.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">5</div>
                            <div>
                                <strong>Delete</strong> &mdash; press <span class="pm-help-kbd">Delete</span> or <span class="pm-help-kbd">Backspace</span> to remove the current selection. Connectors attached to a deleted step are removed with it.
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip">Inline edit shortcut: double-click any step to rename it without opening the detail panel. Press <span class="pm-help-kbd">Enter</span> to commit or <span class="pm-help-kbd">Esc</span> to cancel.</p>
                </div>

                <!-- Section 6: Saving & loading -->
                <div class="pm-help-section" id="saving">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">6</span>
                        <h3>Saving &amp; loading</h3>
                    </div>
                    <p>Every process is stored on the server and accessible from the sidebar. Saving is a single round-trip that captures the whole diagram &mdash; steps, connectors, positions and labels &mdash; in one transaction.</p>

                    <div class="pm-help-steps">
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">1</div>
                            <div>
                                <strong>Save the current process</strong> &mdash; click the Save button in the toolbar or press <span class="pm-help-kbd">Ctrl</span>+<span class="pm-help-kbd">S</span>. The process reloads from the server after saving so any IDs assigned by the database are reflected in the canvas.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">2</div>
                            <div>
                                <strong>Switch processes</strong> &mdash; click any item in the sidebar to load it into the canvas. Use the search box at the top of the sidebar to filter the list when you have lots of processes.
                            </div>
                        </div>
                        <div class="pm-help-step-item">
                            <div class="pm-help-step-num">3</div>
                            <div>
                                <strong>Delete a process</strong> &mdash; hover over a process in the sidebar and click the &times; that appears. You will be asked to confirm; the process and all its steps and connectors are removed permanently.
                            </div>
                        </div>
                    </div>

                    <p class="pm-help-tip">Save replaces all the steps and connectors for a process in one transaction, so the database always matches what is on screen. There is no partial-save state to worry about.</p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="pm-help-section" id="tips">
                    <div class="pm-help-section-header">
                        <span class="pm-help-section-num">7</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="pm-help-tips-grid">
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#128200;</div>
                            <div><strong>Use shapes consistently</strong><br>Reserve diamonds for actual decisions, ovals for entry/exit points, and documents for tangible artefacts. Mixing shapes makes diagrams harder to scan.</div>
                        </div>
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#127919;</div>
                            <div><strong>Label every Decision branch</strong><br>An unlabelled diamond with two outgoing arrows leaves the reader guessing. "Yes / No", "Approved / Rejected", "P1 / P2-3 / P4-5" &mdash; spell it out.</div>
                        </div>
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#128229;</div>
                            <div><strong>Left-to-right flows scan best</strong><br>People read left-to-right, top-to-bottom. Lay out the happy path along a horizontal axis and branch decisions vertically &mdash; the demo data follows this convention.</div>
                        </div>
                        <div class="pm-help-tip-card">
                            <div class="pm-help-tip-icon">&#9997;</div>
                            <div><strong>Save often</strong><br>The save shortcut is <span class="pm-help-kbd">Ctrl</span>+<span class="pm-help-kbd">S</span>. There is no auto-save, so press it whenever you have made changes you would not want to redo.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.pm-help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const id = link.dataset.section;
            const el = document.getElementById(id);
            if (el) sections.push({ id, el });
        });

        helpMain.addEventListener('scroll', function() {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;

            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) {
                    current = s.id;
                }
            }

            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
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
