<?php
/**
 * CMDB Module Help Guide — full page with left pane navigation
 */
session_start();
require_once '../config.php';

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
    <title>FreeITSM - CMDB Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .cmdb-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .cmdb-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .cmdb-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .cmdb-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .cmdb-help-nav-link:hover { background: #fdf2f8; color: #be185d; }
        .cmdb-help-nav-link.active { background: #fce7f3; color: #be185d; font-weight: 600; }
        .cmdb-help-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 24px; height: 24px;
            border-radius: 50%;
            background: #eee; color: #888;
            font-weight: 700; font-size: 11px;
            flex-shrink: 0;
        }
        .cmdb-help-nav-link.active .cmdb-help-nav-num { background: #be185d; color: white; }

        /* Main content area */
        .cmdb-help-main { flex: 1; overflow-y: auto; }
        .cmdb-help-hero {
            background: linear-gradient(135deg, #ec4899 0%, #be185d 50%, #9d174d 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .cmdb-help-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .cmdb-help-hero p { margin: 0; font-size: 15px; opacity: 0.85; max-width: 720px; margin: 0 auto; }
        .cmdb-help-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* Sections */
        .cmdb-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }
        .cmdb-help-section:last-child { border-bottom: none; padding-bottom: 0; }
        .cmdb-help-section-header {
            display: flex; align-items: flex-start; gap: 14px;
            margin-bottom: 16px;
        }
        .cmdb-help-section-header h3 { margin: 0; font-size: 18px; color: #333; }
        .cmdb-help-section-header p { margin: 6px 0 0; font-size: 14px; color: #666; line-height: 1.6; }
        .cmdb-help-section > p { font-size: 14px; color: #555; line-height: 1.7; margin: 0 0 14px; }
        .cmdb-help-section-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px;
            border-radius: 50%;
            background: #fce7f3; color: #be185d;
            font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        .cmdb-help-section-num.highlight { background: #be185d; color: white; }

        /* Highlighted section */
        .cmdb-help-section-highlight {
            background: #fdf2f8;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #fbcfe8;
        }

        /* Feature cards grid */
        .cmdb-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-left: 46px;
        }
        .cmdb-help-feature-card {
            padding: 16px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
        }
        .cmdb-help-feature-card h4 { margin: 0 0 6px; font-size: 14px; color: #333; }
        .cmdb-help-feature-card p { margin: 0; font-size: 12.5px; color: #666; line-height: 1.5; }
        .cmdb-help-feature-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 10px;
            background: #fce7f3; color: #be185d;
        }

        /* Concept callouts (Class / Object / Property / etc) */
        .concept-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 16px;
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #be185d;
            margin-bottom: 10px;
            margin-left: 46px;
            align-items: start;
        }
        .concept-name {
            font-weight: 700;
            color: #be185d;
            font-size: 14px;
        }
        .concept-desc {
            font-size: 13px;
            color: #555;
            line-height: 1.55;
        }
        .concept-desc em { color: #be185d; font-style: normal; font-weight: 500; }

        /* Numbered steps */
        .cmdb-help-steps {
            display: flex; flex-direction: column; gap: 10px;
            margin-left: 46px;
        }
        .cmdb-help-step-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: #fafafa;
            font-size: 14px; color: #444; line-height: 1.5;
        }
        .cmdb-help-step-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 26px; height: 26px;
            border-radius: 50%;
            background: #be185d; color: white;
            font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }

        /* Tip callout */
        .cmdb-help-tip {
            font-size: 13px;
            color: #9d174d;
            background: #fdf2f8;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #be185d;
            margin-top: 14px;
            margin-left: 46px;
            line-height: 1.55;
        }
        .cmdb-help-tip strong { color: #831843; }

        /* The SQL hierarchy diagram */
        .hierarchy-diagram {
            font-family: 'Consolas', 'Monaco', monospace;
            background: #fafafa;
            border-radius: 8px;
            padding: 18px 20px;
            margin: 14px 0 14px 46px;
            font-size: 13px;
            color: #333;
            line-height: 1.8;
        }
        .hierarchy-diagram .node {
            display: inline-block;
            background: white;
            padding: 3px 10px;
            border-radius: 4px;
            border: 1px solid #fbcfe8;
            color: #be185d;
            font-weight: 600;
        }
        .hierarchy-diagram .arrow { color: #d1d5db; }

        /* When to use which — three-column comparison */
        .when-table {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin: 16px 0 0 46px;
        }
        .when-card {
            background: white;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            padding: 16px;
        }
        .when-card h4 {
            font-size: 13px;
            color: #be185d;
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .when-card p { font-size: 12.5px; color: #555; margin: 0 0 6px; line-height: 1.5; }
        .when-card .ex { font-size: 12px; color: #888; font-style: italic; }

        /* Two-column tip pairs */
        .tips-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 14px 0 0 46px;
        }
        .tip-card {
            background: white;
            padding: 14px 16px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .tip-card h4 {
            font-size: 13px;
            color: #be185d;
            margin: 0 0 6px;
        }
        .tip-card p {
            font-size: 12.5px;
            color: #555;
            margin: 0;
            line-height: 1.55;
        }

        kbd {
            display: inline-block;
            background: white;
            border: 1px solid #d1d5db;
            border-bottom-width: 2px;
            border-radius: 3px;
            padding: 1px 5px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 11px;
            color: #4b5563;
        }

        @media (max-width: 900px) {
            .cmdb-help-features-grid,
            .when-table,
            .tips-grid { grid-template-columns: 1fr; }
            .concept-row { grid-template-columns: 1fr; }
            .concept-row { margin-left: 0; }
            .cmdb-help-steps { margin-left: 0; }
            .cmdb-help-tip { margin-left: 0; }
            .hierarchy-diagram { margin-left: 0; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="cmdb-help-container">
        <!-- Left pane navigation -->
        <div class="cmdb-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="cmdb-help-nav-link active" data-section="overview">
                <span class="cmdb-help-nav-num">1</span> Overview
            </a>
            <a href="#concepts" class="cmdb-help-nav-link" data-section="concepts">
                <span class="cmdb-help-nav-num">2</span> Core concepts
            </a>
            <a href="#classes" class="cmdb-help-nav-link" data-section="classes">
                <span class="cmdb-help-nav-num">3</span> Classes &amp; properties
            </a>
            <a href="#ai-suggest" class="cmdb-help-nav-link" data-section="ai-suggest">
                <span class="cmdb-help-nav-num">4</span> AI Suggest Properties
            </a>
            <a href="#objects" class="cmdb-help-nav-link" data-section="objects">
                <span class="cmdb-help-nav-num">5</span> Adding objects
            </a>
            <a href="#hierarchy" class="cmdb-help-nav-link" data-section="hierarchy">
                <span class="cmdb-help-nav-num">6</span> Parent &amp; children
            </a>
            <a href="#relationships" class="cmdb-help-nav-link" data-section="relationships">
                <span class="cmdb-help-nav-num">7</span> Relationships
            </a>
            <a href="#when-to-use" class="cmdb-help-nav-link" data-section="when-to-use">
                <span class="cmdb-help-nav-num">8</span> Property vs parent vs relationship
            </a>
            <a href="#synthesis" class="cmdb-help-nav-link" data-section="synthesis">
                <span class="cmdb-help-nav-num">9</span> AI summary, Impact &amp; Map
            </a>
            <a href="#tickets" class="cmdb-help-nav-link" data-section="tickets">
                <span class="cmdb-help-nav-num">10</span> Linking tickets
            </a>
            <a href="#settings" class="cmdb-help-nav-link" data-section="settings">
                <span class="cmdb-help-nav-num">11</span> Settings
            </a>
            <a href="#tips" class="cmdb-help-nav-link" data-section="tips">
                <span class="cmdb-help-nav-num">12</span> Tips &amp; conventions
            </a>

        </div>

        <!-- Main content -->
        <div class="cmdb-help-main" id="helpMain">
            <div class="cmdb-help-hero">
                <h2>CMDB guide</h2>
                <p>Configuration Management Database &mdash; model your IT estate as a graph of typed objects, see what depends on what, and link everything back to the tickets that touch it.</p>
            </div>

            <div class="cmdb-help-content">

                <!-- 1. Overview -->
                <div class="cmdb-help-section" id="overview">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The CMDB is where you record what makes up your IT estate &mdash; servers, databases, applications, services, the people who own them &mdash; and how they fit together. Done well, it stops being a static inventory and becomes the answer to questions like <em>"if I take this server down for patching, what breaks?"</em> and <em>"which databases is Bob the owner of?"</em></p>
                        </div>
                    </div>

                    <div class="cmdb-help-features-grid">
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 22V8l10-6 10 6v14"></path><path d="M2 12h20"></path><path d="M2 17h20"></path><line x1="12" y1="2" x2="12" y2="22"></line></svg>
                            </div>
                            <h4>Typed objects</h4>
                            <p>Define your own classes (Database, Server, Application, etc), each with its own user-defined properties.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4>Impact at a glance</h4>
                            <p>Every object shows what would break if it disappeared &mdash; descendants, properties pointing at it, incoming relationships.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            </div>
                            <h4>AI synthesis</h4>
                            <p>Claude writes a 2-3 sentence summary of every object, and suggests properties &amp; missing classes when you create a new one.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4>Cross-module</h4>
                            <p>Tickets link to CMDB objects. Each object's Activity panel shows the tickets that touch it.</p>
                        </div>
                    </div>
                </div>

                <!-- 2. Core concepts -->
                <div class="cmdb-help-section cmdb-help-section-highlight" id="concepts">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num highlight">2</span>
                        <div>
                            <h3>Core concepts</h3>
                            <p>Five things to know &mdash; everything else builds on these.</p>
                        </div>
                    </div>

                    <div class="concept-row">
                        <div class="concept-name">Class</div>
                        <div class="concept-desc">A <em>type</em> of thing. <em>Server</em>, <em>Database</em>, <em>Person</em>. Each class has its own properties. You define classes in <strong>Settings &rarr; Classes</strong>.</div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name">Object</div>
                        <div class="concept-desc">An <em>instance</em> of a class. <em>SQLSVR01</em> is an object whose class is <em>Server</em>. You create objects from the browse page.</div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name">Property</div>
                        <div class="concept-desc">A field on a class. Properties can be text, number, date, Yes/No, dropdown, or a <em>reference to another object</em>. Once a class has properties, every object of that class gets the same set of fields to fill in.</div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name">Hierarchy (parent &amp; children)</div>
                        <div class="concept-desc">A strict tree. Each object has 0 or 1 parent and any number of children. <em>Parent</em> means <strong>"this thing can't exist without it"</strong> &mdash; if you delete the parent, the children go too.</div>
                    </div>
                    <div class="concept-row">
                        <div class="concept-name">Relationship</div>
                        <div class="concept-desc">A named link between two objects, separate from the hierarchy. Verbs like <em>depends on</em>, <em>connects to</em>, <em>managed by</em>. You can have as many of these as you like, in any direction.</div>
                    </div>

                    <div class="cmdb-help-tip">
                        <strong>Why split hierarchy from relationships?</strong> A single graph with no distinction becomes a hairball within ~20 objects. Forcing every object into one parent gives you a navigable tree; pushing the messy many-to-many stuff into a separate relationships table keeps the picture readable.
                    </div>
                </div>

                <!-- 3. Classes & properties -->
                <div class="cmdb-help-section" id="classes">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">3</span>
                        <div>
                            <h3>Classes &amp; properties</h3>
                            <p>Define what kinds of things you want to track, and what fields each kind needs.</p>
                        </div>
                    </div>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div>Go to <strong>Settings &rarr; Classes</strong>, click <strong>Add</strong>, give it a name (e.g. <em>Database</em>) and a description.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div>Save. The new class appears in the table with a property-count badge (initially 0).</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div>Click that property-count badge to open the per-class properties manager.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">4</span>
                            <div>Either click <strong>Add Property</strong> for each one manually, or use <strong>&#x2728; Suggest with AI</strong> &mdash; covered in the next section.</div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <strong>Property types:</strong> Text, Number, Date, Yes/No, Dropdown (with optional per-option colours that render as tinted pills on the object detail page), and Object Reference (points at another object &mdash; pick the target class so the picker is scoped). The label is freely editable; the underlying <em>property key</em> is immutable so renaming the label never breaks stored values.
                    </div>

                    <div class="cmdb-help-tip">
                        <strong>Editing properties from anywhere:</strong> Every property row on the object detail page has a small &#9881; cog next to its type tag &mdash; click it to open a draggable floating modal that edits the property's full definition (including its dropdown options and colours) without leaving the page.
                    </div>
                </div>

                <!-- 4. AI Suggest Properties -->
                <div class="cmdb-help-section cmdb-help-section-highlight" id="ai-suggest">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num highlight">4</span>
                        <div>
                            <h3>AI Suggest Properties</h3>
                            <p>Claude asks about <em>your</em> environment, then suggests tailored properties.</p>
                        </div>
                    </div>

                    <p style="margin-left: 46px;">When you click <strong>&#x2728; Suggest with AI</strong> in the per-class properties manager, the wizard runs in two stages:</p>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div><strong>Clarifying questions.</strong> Claude asks 3-5 short questions about your specific environment &mdash; for <em>Database</em> that might be "What kind &mdash; SQL Server / Postgres / Mongo / Redis?", "Do you track schemas separately?". Skip any you're not sure about.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div><strong>Suggestions.</strong> Based on your answers, Claude proposes 6-12 properties tailored to <em>your</em> stack &mdash; with type, key, required flag, and a one-line "why". Untick anything you don't want, click <strong>Add Selected</strong>.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div><strong>Auto-create missing classes.</strong> If a suggestion is an Object Reference (e.g. <em>Owner &rarr; Person</em>) and you don't have a Person class yet, the CMDB creates it on the fly so the link works straight away. The Result panel lists exactly what was added and what was created.</div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        Re-running the wizard later is safe &mdash; suggestions whose label or key already exist on the class are filtered out, so you can run it again after adding a few properties manually and only get new ideas.
                    </div>
                </div>

                <!-- 5. Adding objects -->
                <div class="cmdb-help-section" id="objects">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">5</span>
                        <div>
                            <h3>Adding objects</h3>
                            <p>Once a class has properties, you can populate it.</p>
                        </div>
                    </div>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div>From the CMDB browse page, pick the class in the left sidebar (it shows the object count beside each class).</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div>Click <strong>+ New</strong>. The modal asks for a name. If the class has any required properties, those appear as fields below the name input &mdash; fill them and click <strong>Create &amp; open</strong>.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div>You're taken straight to the object detail page. Click any property value to edit it in place. Saves on <kbd>Enter</kbd> or blur. Press <kbd>Escape</kbd> to cancel.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">4</span>
                            <div>Object Reference properties open an autocomplete scoped to the target class &mdash; type a few characters to find the linked object.</div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <strong>The detail page is one big inline-edit form.</strong> The page header shows the object name (also click-to-edit), class badge, and parent breadcrumb. Below that, the AI summary, Impact panel, Activity (linked tickets), Map (mini-graph), Properties, Hierarchy, and Relationships sections all live on the same page &mdash; no separate edit screen.
                    </div>
                </div>

                <!-- 6. Hierarchy -->
                <div class="cmdb-help-section" id="hierarchy">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">6</span>
                        <div>
                            <h3>Parent &amp; children &mdash; the hierarchy</h3>
                            <p>Strict containment. <em>Parent</em> = required for the child to exist.</p>
                        </div>
                    </div>

                    <p>Use the parent/child link when removing the parent makes the child meaningless. Classic example: a SQL chain.</p>

                    <div class="hierarchy-diagram">
                        <span class="node">Server (SQLSVR01)</span><br>
                        <span class="arrow">&#9492;&#9472;&#9472;</span> <span class="node">SQL Instance (MSSQLSERVER)</span><br>
                        <span class="arrow">&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="node">Database (FREEITSM)</span><br>
                        <span class="arrow">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="node">Stored Procedure (sp_archive)</span><br>
                        <span class="arrow">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="node">SQL Job (Nightly archive)</span>
                    </div>

                    <p style="margin-left: 46px;">Each level genuinely can't exist without its parent &mdash; delete the Server, every layer below goes with it. <strong>Cascade delete is enforced</strong>; the confirmation tells you exactly how many descendants will go.</p>

                    <div class="cmdb-help-tip">
                        Click <strong>Edit</strong> next to the Parent field on the detail page to change it &mdash; an autocomplete searches every object across every class. The CMDB walks the parent chain on save and refuses cycles ("the new parent is a descendant of this object").
                    </div>
                </div>

                <!-- 7. Relationships -->
                <div class="cmdb-help-section" id="relationships">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">7</span>
                        <div>
                            <h3>Relationships</h3>
                            <p>Named links between objects, separate from the hierarchy.</p>
                        </div>
                    </div>

                    <p>Relationships are how you record everything that <strong>isn't</strong> a parent/child link &mdash; cross-cutting connections like <em>depends on</em>, <em>connects to</em>, <em>managed by</em>, <em>replicates to</em>, <em>monitored by</em>.</p>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div>On the object detail page's <strong>Relationships</strong> section, click <strong>+ Add relationship</strong>.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div>Pick a verb from the dropdown (a hint shows the inverse verb so you can see how it'll read from the other side).</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div>Type to search the linked object &mdash; the autocomplete searches every class. Pick and save.</div>
                        </div>
                    </div>

                    <p style="margin-left: 46px; margin-top: 14px;">The relationship is symmetric: when you view the linked object, it appears in <em>its</em> incoming column with the inverse verb. So <em>"FREEITSM depends on AD"</em> on the database shows as <em>"FREEITSM is depended on by AD"</em> when you're viewing AD.</p>

                    <div class="cmdb-help-tip">
                        Add new verbs in <strong>Settings &rarr; Relationship Types</strong> &mdash; each verb has an inverse. Three are seeded on first run: <em>depends on</em>, <em>connects to</em>, <em>managed by</em>.
                    </div>
                </div>

                <!-- 8. When to use which -->
                <div class="cmdb-help-section cmdb-help-section-highlight" id="when-to-use">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num highlight">8</span>
                        <div>
                            <h3>Property vs parent vs relationship</h3>
                            <p>The single most common question. Pick the right link type and the rest follows.</p>
                        </div>
                    </div>

                    <div class="when-table">
                        <div class="when-card">
                            <h4>Object Reference Property</h4>
                            <p>Use when the link is intrinsic, single, named &mdash; an attribute of <em>what this object is</em>.</p>
                            <p class="ex">e.g. <em>Owner</em> &rarr; Person, <em>Vendor</em> &rarr; Company, <em>Host Server</em> &rarr; Server.</p>
                        </div>
                        <div class="when-card">
                            <h4>Parent / child</h4>
                            <p>Use when the child <strong>cannot exist</strong> without the parent. Cascade delete makes sense.</p>
                            <p class="ex">e.g. SQL Job &rarr; Stored Proc &rarr; Database &rarr; SQL Instance &rarr; Server.</p>
                        </div>
                        <div class="when-card">
                            <h4>Relationship</h4>
                            <p>Use for cross-cutting context &mdash; many-to-many, verb-driven, often optional.</p>
                            <p class="ex">e.g. <em>depends on</em> AD, <em>monitored by</em> SolarWinds, <em>replicates to</em> DR site.</p>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        <strong>Don't double-record.</strong> If a database has <em>Host Server</em> as a property pointing at SQLSVR01, you don't also need a <em>"depends on SQLSVR01"</em> relationship &mdash; the property already implies the dependency. Save relationships for links that aren't already captured by properties or parent/child.
                    </div>
                </div>

                <!-- 9. Synthesis layer -->
                <div class="cmdb-help-section" id="synthesis">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">9</span>
                        <div>
                            <h3>AI summary, Impact &amp; Map</h3>
                            <p>Three sections that turn raw data into something useful at a glance.</p>
                        </div>
                    </div>

                    <div class="cmdb-help-features-grid">
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            </div>
                            <h4>AI Summary</h4>
                            <p>2-3 sentence prose synthesis at the top of every detail page &mdash; what it is, where it sits, who owns it, what depends on it, plus open ticket context. Click <strong>Generate</strong> / <strong>Regenerate</strong> on demand. Cached on the row so reloads don't re-call the AI.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4>Impact panel</h4>
                            <p>Three buckets showing what would break: <em>Descendants</em> (cascade-deletes), <em>Referenced by property</em> (other objects pointing at this), <em>Things that link in</em> (incoming relationships rendered with the inverse verb).</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            </div>
                            <h4>Map (mini-graph)</h4>
                            <p>Compact visual: parent above, this object centred (pink), children below, and outgoing/incoming relationships in side columns. Click any node to navigate.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <div class="cmdb-help-feature-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4>Activity panel</h4>
                            <p>Open and recent closed tickets that reference this object &mdash; status pill in the lookup colour, priority, assignee, last update. Click any card to deep-link to the ticket.</p>
                        </div>
                    </div>
                </div>

                <!-- 10. Linking tickets -->
                <div class="cmdb-help-section" id="tickets">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">10</span>
                        <div>
                            <h3>Linking tickets to CMDB objects</h3>
                            <p>The cross-module wiring that makes everything else click into place.</p>
                        </div>
                    </div>

                    <div class="cmdb-help-steps">
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">1</span>
                            <div>Open a ticket in the inbox. Below the email thread you'll see a pink-tinted <strong>Affected CMDB Objects</strong> section.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">2</span>
                            <div>Click <strong>+ Link object</strong>, type a few letters of the object's name in the autocomplete, pick from the results.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">3</span>
                            <div>The link appears as an info card showing the object's class and parent context. Click the card to open the object in the CMDB; click the <strong>&times;</strong> to unlink.</div>
                        </div>
                        <div class="cmdb-help-step-item">
                            <span class="cmdb-help-step-num">4</span>
                            <div>On the CMDB side, that object now shows the ticket in its <strong>Activity</strong> panel &mdash; with status pill, assignee, last update. Future AI summaries will mention the open ticket count too.</div>
                        </div>
                    </div>

                    <div class="cmdb-help-tip">
                        Linking is many-to-many &mdash; a ticket can affect multiple CMDB objects, and an object can have many tickets touching it. Re-linking the same object surfaces a friendly "already linked" toast rather than a duplicate.
                    </div>
                </div>

                <!-- 11. Settings tour -->
                <div class="cmdb-help-section" id="settings">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">11</span>
                        <div>
                            <h3>Settings tour</h3>
                            <p>Three tabs: <em>Classes</em>, <em>Relationship Types</em>, <em>AI Integration</em>.</p>
                        </div>
                    </div>

                    <div class="cmdb-help-features-grid">
                        <div class="cmdb-help-feature-card">
                            <h4>Classes</h4>
                            <p>CRUD for class definitions. The property-count badge on each row opens the per-class properties manager. Property keys are immutable (auto-generated from the label); labels are freely editable. Drop dropdown options as rows with optional colour swatches.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <h4>Relationship Types</h4>
                            <p>The verb library. Each row has a verb and its inverse (e.g. <em>depends on</em> &harr; <em>is depended on by</em>). Three defaults are seeded on first run.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <h4>AI Integration</h4>
                            <p>Anthropic key (encrypted at rest, masked when read), model picker (Haiku 4.5 default &mdash; fine for summaries and suggestions), Custom Instructions textarea (appended to every CMDB AI prompt &mdash; great for environment-specific tweaks like "use British English" or "always assume cloud-hosted unless stated"), and Test connection button.</p>
                        </div>
                        <div class="cmdb-help-feature-card">
                            <h4>Per-feature billing</h4>
                            <p>The CMDB AI key is separate from RFP AI / Knowledge AI / Reply Cleanup, so usage shows as its own line on the Anthropic billing console. Easy to track if AI costs ever matter.</p>
                        </div>
                    </div>
                </div>

                <!-- 12. Tips -->
                <div class="cmdb-help-section" id="tips">
                    <div class="cmdb-help-section-header">
                        <span class="cmdb-help-section-num">12</span>
                        <div>
                            <h3>Tips &amp; conventions</h3>
                            <p>Hard-won lessons that save time later.</p>
                        </div>
                    </div>

                    <div class="tips-grid">
                        <div class="tip-card">
                            <h4>Use plain English</h4>
                            <p>Property labels and relationship verbs feed straight into AI prompts. <em>"depends on"</em> reads better than <em>"DEP"</em>; <em>"Host Server"</em> beats <em>"hostsrv"</em>.</p>
                        </div>
                        <div class="tip-card">
                            <h4>One generic class &gt; many specific ones</h4>
                            <p>For a small estate, a single <em>Service</em> class covering AD, monitoring tools, file servers etc beats a dozen narrow classes. Split them later if you genuinely have many.</p>
                        </div>
                        <div class="tip-card">
                            <h4>Required is for genuinely required</h4>
                            <p>Required properties block create until filled. Use sparingly &mdash; better to have an object with most fields blank than to have analysts skip the CMDB because creates are too painful.</p>
                        </div>
                        <div class="tip-card">
                            <h4>Don't duplicate links</h4>
                            <p>If a property already captures the link (e.g. <em>Host Server</em>), you don't need a relationship for the same thing. Save relationships for what isn't already implied.</p>
                        </div>
                        <div class="tip-card">
                            <h4>Regenerate the AI summary after big changes</h4>
                            <p>The summary is cached on the row. Adding several new properties or relationships? Click Regenerate to refresh the synthesis.</p>
                        </div>
                        <div class="tip-card">
                            <h4>Drag the property-edit modal</h4>
                            <p>The floating Edit Property modal (the &#9881; cog) has a drag handle on its pink header. Drag it to a corner so you can keep referring to the data while you tweak the schema.</p>
                        </div>
                        <div class="tip-card">
                            <h4>The ⚙ cog is your friend</h4>
                            <p>You don't have to bounce to Settings every time you want to add a dropdown option or rename a label. Edit any property's definition right from the object detail page.</p>
                        </div>
                        <div class="tip-card">
                            <h4>Object names are NOT unique</h4>
                            <p>Two databases can both be called <em>master</em> on different SQL instances &mdash; that's intentional. Use the parent + class context to disambiguate, not the name.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.cmdb-help-nav-link');
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
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Smooth-scroll within the help container, not the page
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
