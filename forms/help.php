<?php
/**
 * Forms Module Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Forms Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .fm-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .fm-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .fm-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .fm-help-nav-link {
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

        .fm-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .fm-help-nav-link.active {
            background: #e0f2f1;
            color: #004d40;
            font-weight: 600;
        }

        .fm-help-nav-link.highlight {
            color: #004d40;
        }

        .fm-help-nav-link.highlight.active {
            background: #00695c;
            color: white;
        }

        .fm-help-nav-num {
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

        .fm-help-nav-link.active .fm-help-nav-num {
            background: #00897b;
            color: white;
        }

        .fm-help-nav-num.highlight {
            background: #e0f2f1;
            color: #004d40;
        }

        .fm-help-nav-link.highlight.active .fm-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .fm-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .fm-help-hero {
            background: linear-gradient(135deg, #00897b 0%, #00695c 50%, #004d40 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .fm-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .fm-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .fm-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .fm-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .fm-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .fm-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .fm-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .fm-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .fm-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .fm-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e0f2f1;
            color: #004d40;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .fm-help-section-num.highlight {
            background: #00897b;
            color: white;
        }

        /* Feature cards grid */
        .fm-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .fm-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .fm-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .fm-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .fm-help-feature-icon.teal { background: #e0f2f1; color: #00897b; }
        .fm-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .fm-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .fm-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .fm-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .fm-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .fm-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .fm-help-step-item {
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

        .fm-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #00897b;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .fm-help-section-highlight {
            background: #e0f2f1;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #80cbc4;
        }

        .fm-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .fm-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .fm-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards */
        .fm-help-data-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .fm-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #00897b;
        }

        .fm-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .fm-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Flow diagram */
        .fm-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .fm-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .fm-help-flow-step.build { background: #e0f2f1; color: #004d40; }
        .fm-help-flow-step.fill { background: #e3f2fd; color: #1565c0; }
        .fm-help-flow-step.submit { background: #e8f5e9; color: #2e7d32; }
        .fm-help-flow-step.review { background: #fff3e0; color: #e65100; }

        .fm-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .fm-help-tip {
            font-size: 13px !important;
            color: #004d40 !important;
            background: #e0f2f1;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #00897b;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .fm-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .fm-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .fm-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .fm-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .fm-help-sidebar { display: none; }
            .fm-help-content { padding: 10px 24px 40px; }
            .fm-help-hero { padding: 30px 24px; }
            .fm-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .fm-help-features-grid { grid-template-columns: 1fr; }
            .fm-help-data-grid { grid-template-columns: 1fr; }
            .fm-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="fm-help-container">
        <!-- Left pane navigation -->
        <div class="fm-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="fm-help-nav-link active" data-section="overview">
                <span class="fm-help-nav-num">1</span>
                Overview
            </a>
            <a href="#building-forms" class="fm-help-nav-link highlight" data-section="building-forms">
                <span class="fm-help-nav-num highlight">2</span>
                Building forms
            </a>
            <a href="#filling-in" class="fm-help-nav-link" data-section="filling-in">
                <span class="fm-help-nav-num">3</span>
                Filling in forms
            </a>
            <a href="#submissions" class="fm-help-nav-link" data-section="submissions">
                <span class="fm-help-nav-num">4</span>
                Submissions
            </a>
            <a href="#export" class="fm-help-nav-link highlight" data-section="export">
                <span class="fm-help-nav-num highlight">5</span>
                Export
            </a>
            <a href="#settings" class="fm-help-nav-link" data-section="settings">
                <span class="fm-help-nav-num">6</span>
                Settings
            </a>
            <a href="#tips" class="fm-help-nav-link" data-section="tips">
                <span class="fm-help-nav-num">7</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="fm-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="fm-help-hero">
                <h2>Forms guide</h2>
                <p>Build custom forms, collect structured data, and export submissions &mdash; all without writing a single line of code.</p>
            </div>

            <div class="fm-help-content">

                <!-- Section 1: Overview -->
                <div class="fm-help-section" id="overview">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Forms module lets you create custom forms with a visual drag-and-drop builder, share them with your team for completion, review every submission in one place, and export the data to CSV. Whether you need an onboarding checklist, a request form, or a feedback survey, Forms handles it all.</p>
                        </div>
                    </div>

                    <div class="fm-help-flow">
                        <div class="fm-help-flow-step build">Build a form</div>
                        <div class="fm-help-flow-arrow">&rarr;</div>
                        <div class="fm-help-flow-step fill">Fill it in</div>
                        <div class="fm-help-flow-arrow">&rarr;</div>
                        <div class="fm-help-flow-step submit">Submit</div>
                        <div class="fm-help-flow-arrow">&rarr;</div>
                        <div class="fm-help-flow-step review">Review &amp; export</div>
                    </div>

                    <div class="fm-help-features-grid">
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </div>
                            <h4>Builder</h4>
                            <p>Design forms visually with a drag-and-drop field editor. Add text inputs, text areas, checkboxes, and dropdowns, then rearrange them in seconds.</p>
                        </div>
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <h4>Fill in</h4>
                            <p>A clean, A4-style form interface with your company logo at the top. Required field validation ensures nothing important gets missed.</p>
                        </div>
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4>Submissions</h4>
                            <p>Browse every submission in a sortable table. Click any row to open the full detail view and see exactly what was entered.</p>
                        </div>
                        <div class="fm-help-feature-card">
                            <div class="fm-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            </div>
                            <h4>Export</h4>
                            <p>Download submission data as a CSV file with UTF-8 BOM encoding so it opens correctly in Excel without character issues.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Building Forms (highlighted) -->
                <div class="fm-help-section fm-help-section-highlight" id="building-forms">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num highlight">2</span>
                        <h3>Building forms</h3>
                    </div>
                    <p class="fm-help-intro">The form builder is where you design your forms. You start with a title and optional description, then add fields one by one. The builder gives you a live preview so you can see exactly how the finished form will look before sharing it.</p>

                    <div class="fm-help-steps">
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">1</div>
                            <div>
                                <strong>Create a new form</strong> &mdash; click the "New Form" button in the sidebar. Give your form a title and, optionally, a description that explains what the form is for.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">2</div>
                            <div>
                                <strong>Add fields</strong> &mdash; click the "Add" button to open the field type menu. Choose from four field types: <strong>Text Input</strong> for short answers, <strong>Text Area</strong> for longer responses, <strong>Checkbox</strong> for yes/no questions, and <strong>Dropdown</strong> for a fixed list of options.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">3</div>
                            <div>
                                <strong>Configure each field</strong> &mdash; give every field a label and decide whether it should be required. For dropdowns, enter the list of options that users can choose from.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">4</div>
                            <div>
                                <strong>Reorder fields</strong> &mdash; drag and drop fields using the handle to arrange them in the order you want. The form will display fields in exactly this order when filled in.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">5</div>
                            <div>
                                <strong>Preview your form</strong> &mdash; switch to the Preview tab to see how your form will look to the person filling it in. This shows the A4-style layout with your company logo.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">6</div>
                            <div>
                                <strong>Save</strong> &mdash; click the Save button in the toolbar. The unsaved changes indicator disappears once the form is saved successfully.
                            </div>
                        </div>
                    </div>

                    <p class="fm-help-tip">The unsaved changes warning protects you from accidentally navigating away. If you have pending changes, you will be prompted before leaving the page.</p>
                </div>

                <!-- Section 3: Filling in Forms -->
                <div class="fm-help-section" id="filling-in">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">3</span>
                        <h3>Filling in forms</h3>
                    </div>
                    <p>When you open a form to fill in, it is presented in a clean A4-style layout designed to look professional and easy to read. Your company logo appears at the top of the form, followed by the title, description, and each field in order.</p>

                    <div class="fm-help-data-grid">
                        <div class="fm-help-data-card">
                            <strong>Company logo</strong>
                            <span>Displayed at the top of every form. Alignment (left, centre, or right) is controlled in Settings.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Text inputs</strong>
                            <span>Single-line fields for short answers like names, reference numbers, or email addresses.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Text areas</strong>
                            <span>Multi-line fields for longer responses such as descriptions, notes, or explanations.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Checkboxes</strong>
                            <span>Simple tick boxes for yes/no or agree/disagree selections.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Dropdowns</strong>
                            <span>Pick one option from a predefined list. Ideal for categories, departments, or priority levels.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Required fields</strong>
                            <span>Marked with a red asterisk. The form cannot be submitted until all required fields are completed.</span>
                        </div>
                    </div>

                    <p>Each field validates as you fill it in. Required fields that are left empty will be highlighted, and the form will not submit until every required field has a value. This prevents incomplete submissions from reaching the reviewer.</p>

                    <p class="fm-help-tip">The form interface is designed to feel like a printed document. The white card on a grey background mimics an A4 sheet, making it intuitive for users who are familiar with traditional paper forms.</p>
                </div>

                <!-- Section 4: Submissions -->
                <div class="fm-help-section" id="submissions">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">4</span>
                        <h3>Submissions</h3>
                    </div>
                    <p>Every completed form is stored as a submission. The Submissions page gives you a comprehensive view of all the data that has been collected, with tools to search, filter, and drill into individual responses.</p>

                    <div class="fm-help-steps">
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">1</div>
                            <div>
                                <strong>Table view</strong> &mdash; submissions are displayed in a sortable table showing the submitter, submission date, and a summary of the responses. The total count is shown in a badge next to the heading.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">2</div>
                            <div>
                                <strong>Detail view</strong> &mdash; click any row in the table to open a modal showing the complete submission. Every field label and its corresponding answer are displayed in a clean, readable format.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">3</div>
                            <div>
                                <strong>Date range filtering</strong> &mdash; use the date pickers in the toolbar to narrow submissions down to a specific time period. Set a start date, an end date, or both to focus on exactly the window you need.
                            </div>
                        </div>
                    </div>

                    <p class="fm-help-tip">Date range filtering is especially useful for recurring forms. For example, if you run a weekly checklist, filter by the current week to see only the latest responses.</p>
                </div>

                <!-- Section 5: Export (highlighted) -->
                <div class="fm-help-section fm-help-section-highlight" id="export">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num highlight">5</span>
                        <h3>Export</h3>
                    </div>
                    <p class="fm-help-intro">Submission data can be exported to CSV at any time, giving you a portable file that works in Excel, Google Sheets, or any spreadsheet application. The export respects any active date range filters, so you can download just the data you need.</p>

                    <div class="fm-help-fields">
                        <div><strong>UTF-8 BOM encoding</strong> &mdash; the CSV file includes a byte order mark (BOM) so that Excel correctly displays special characters, accented letters, and currency symbols without manual encoding setup.</div>
                        <div><strong>All fields included</strong> &mdash; every field from the form is represented as a column in the CSV. The submitter name and submission date are always included as the first columns.</div>
                        <div><strong>Filtered export</strong> &mdash; if you have set a date range filter on the Submissions page, only submissions within that range are included in the export. Clear the filters to export everything.</div>
                        <div><strong>Instant download</strong> &mdash; the CSV is generated on the server and downloaded directly to your browser. No email or background processing required.</div>
                    </div>

                    <p class="fm-help-tip">If you open the CSV in Excel and see garbled characters, make sure you are double-clicking the file to open it rather than using File &gt; Import. The BOM ensures automatic detection when opening directly.</p>
                </div>

                <!-- Section 6: Settings -->
                <div class="fm-help-section" id="settings">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">6</span>
                        <h3>Settings</h3>
                    </div>
                    <p>The Settings page lets you configure how forms appear when they are filled in. These settings apply globally to all forms in the module.</p>

                    <div class="fm-help-steps">
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">1</div>
                            <div>
                                <strong>Logo alignment</strong> &mdash; choose whether your company logo appears on the left, in the centre, or on the right of the form header. The alignment is shown visually with preview tiles so you can see the result before saving.
                            </div>
                        </div>
                        <div class="fm-help-step-item">
                            <div class="fm-help-step-num">2</div>
                            <div>
                                <strong>Company logo</strong> &mdash; the logo used on forms is the same one configured in your global system settings. To change the logo itself, update it in the main application settings.
                            </div>
                        </div>
                    </div>

                    <p>The alignment setting is saved per module, so changing it here does not affect logos in other parts of the application. The three options are:</p>

                    <div class="fm-help-data-grid">
                        <div class="fm-help-data-card">
                            <strong>Left</strong>
                            <span>Logo aligned to the left edge of the form. Works well for formal or corporate documents.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Centre</strong>
                            <span>Logo centred above the form title. The default option, giving a balanced and symmetrical appearance.</span>
                        </div>
                        <div class="fm-help-data-card">
                            <strong>Right</strong>
                            <span>Logo aligned to the right edge. Useful when form fields start on the left and you want the logo out of the way.</span>
                        </div>
                    </div>

                    <p class="fm-help-tip">Navigate to Settings from the header navigation bar. Changes take effect immediately on any form that is opened after saving.</p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="fm-help-section" id="tips">
                    <div class="fm-help-section-header">
                        <span class="fm-help-section-num">7</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="fm-help-tips-grid">
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#128221;</div>
                            <div><strong>Keep forms focused</strong><br>Shorter forms get higher completion rates. If a form grows beyond ten fields, consider splitting it into two separate forms with distinct purposes.</div>
                        </div>
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#9989;</div>
                            <div><strong>Use required wisely</strong><br>Only mark fields as required when the data is genuinely essential. Over-using required fields can frustrate users and lead to placeholder answers.</div>
                        </div>
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#128203;</div>
                            <div><strong>Preview before sharing</strong><br>Always switch to the Preview tab before saving a form. This is the exact view your users will see, so check that field order and labels make sense.</div>
                        </div>
                        <div class="fm-help-tip-card">
                            <div class="fm-help-tip-icon">&#128202;</div>
                            <div><strong>Export regularly</strong><br>Download submissions periodically for backup or analysis. The CSV format is compatible with pivot tables, mail merge, and most reporting tools.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.fm-help-nav-link');
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
