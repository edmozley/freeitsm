<?php
/**
 * Contracts Module Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Contracts Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .ct-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .ct-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .ct-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .ct-help-nav-link {
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

        .ct-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .ct-help-nav-link.active {
            background: #fffbeb;
            color: #92400e;
            font-weight: 600;
        }

        .ct-help-nav-link.highlight {
            color: #92400e;
        }

        .ct-help-nav-link.highlight.active {
            background: #d97706;
            color: white;
        }

        .ct-help-nav-num {
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

        .ct-help-nav-link.active .ct-help-nav-num {
            background: #f59e0b;
            color: white;
        }

        .ct-help-nav-num.highlight {
            background: #fffbeb;
            color: #92400e;
        }

        .ct-help-nav-link.highlight.active .ct-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .ct-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .ct-help-hero {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .ct-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .ct-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .ct-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .ct-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .ct-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .ct-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .ct-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .ct-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .ct-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .ct-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #fffbeb;
            color: #92400e;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .ct-help-section-num.highlight {
            background: #f59e0b;
            color: white;
        }

        /* Feature cards grid */
        .ct-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .ct-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .ct-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .ct-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .ct-help-feature-icon.amber { background: #fffbeb; color: #f59e0b; }
        .ct-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .ct-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .ct-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }

        .ct-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .ct-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .ct-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .ct-help-step-item {
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

        .ct-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #f59e0b;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .ct-help-section-highlight {
            background: #fffbeb;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #fcd34d;
        }

        .ct-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .ct-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .ct-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards grid */
        .ct-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .ct-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #f59e0b;
        }

        .ct-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .ct-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Tab cards for contract terms */
        .ct-help-tabs-demo {
            display: flex;
            gap: 0;
            margin: 14px 0 0;
            border-bottom: 2px solid #eee;
        }

        .ct-help-tab-demo {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 500;
            color: #888;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }

        .ct-help-tab-demo.active {
            color: #f59e0b;
            border-bottom-color: #f59e0b;
        }

        .ct-help-tab-body {
            padding: 16px;
            background: #fafafa;
            border-radius: 0 0 8px 8px;
            border: 1px solid #eee;
            border-top: none;
            font-size: 13px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        /* Tip callout */
        .ct-help-tip {
            font-size: 13px !important;
            color: #92400e !important;
            background: #fffbeb;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f59e0b;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .ct-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .ct-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .ct-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .ct-help-tip-card strong {
            color: #333;
        }

        /* Settings config cards */
        .ct-help-config-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .ct-help-config-card {
            padding: 16px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .ct-help-config-card h4 {
            margin: 0 0 6px;
            font-size: 14px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ct-help-config-card h4 .config-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .ct-help-config-card h4 .config-dot.amber { background: #f59e0b; }
        .ct-help-config-card h4 .config-dot.blue { background: #1565c0; }
        .ct-help-config-card h4 .config-dot.green { background: #2e7d32; }
        .ct-help-config-card h4 .config-dot.purple { background: #7b1fa2; }
        .ct-help-config-card h4 .config-dot.red { background: #c62828; }

        .ct-help-config-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .ct-help-sidebar { display: none; }
            .ct-help-content { padding: 10px 24px 40px; }
            .ct-help-hero { padding: 30px 24px; }
            .ct-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .ct-help-features-grid { grid-template-columns: 1fr; }
            .ct-help-data-grid { grid-template-columns: 1fr; }
            .ct-help-tips-grid { grid-template-columns: 1fr; }
            .ct-help-config-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="ct-help-container">
        <!-- Left pane navigation -->
        <div class="ct-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="ct-help-nav-link active" data-section="overview">
                <span class="ct-help-nav-num">1</span>
                Overview
            </a>
            <a href="#managing-contracts" class="ct-help-nav-link" data-section="managing-contracts">
                <span class="ct-help-nav-num">2</span>
                Managing contracts
            </a>
            <a href="#contract-terms" class="ct-help-nav-link highlight" data-section="contract-terms">
                <span class="ct-help-nav-num highlight">3</span>
                Contract terms
            </a>
            <a href="#suppliers" class="ct-help-nav-link" data-section="suppliers">
                <span class="ct-help-nav-num">4</span>
                Suppliers
            </a>
            <a href="#contacts" class="ct-help-nav-link" data-section="contacts">
                <span class="ct-help-nav-num">5</span>
                Contacts
            </a>
            <a href="#settings" class="ct-help-nav-link" data-section="settings">
                <span class="ct-help-nav-num">6</span>
                Settings
            </a>
            <a href="#tips" class="ct-help-nav-link" data-section="tips">
                <span class="ct-help-nav-num">7</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="ct-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="ct-help-hero">
                <h2>Contracts management guide</h2>
                <p>Track contracts, manage suppliers, and stay on top of renewals &mdash; all in one place.</p>
            </div>

            <div class="ct-help-content">

                <!-- Section 1: Overview -->
                <div class="ct-help-section" id="overview">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Contracts module gives you complete visibility over your supplier agreements, from initial creation through to renewal or expiry. Track financial commitments, store contract terms in rich text tabs, manage supplier relationships, and never miss a renewal date again.</p>
                        </div>
                    </div>
                    <div class="ct-help-features-grid">
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon amber">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4>Contracts</h4>
                            <p>Create, view, and edit contracts with full lifecycle tracking. Record start dates, end dates, values, payment schedules, and link to suppliers. Upload supporting documents directly to each contract.</p>
                        </div>
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                            </div>
                            <h4>Suppliers</h4>
                            <p>Maintain a directory of every supplier your organisation works with. Record legal and trading names, company registration numbers, addresses, and categorise them by type and status.</p>
                        </div>
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </div>
                            <h4>Contacts</h4>
                            <p>Keep track of the people behind your suppliers. Store names, job titles, email addresses, and phone numbers. Link each contact to their supplier for quick reference.</p>
                        </div>
                        <div class="ct-help-feature-card">
                            <div class="ct-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4>Settings</h4>
                            <p>Configure the dropdown options that drive the module &mdash; supplier types, supplier statuses, contract statuses, payment schedules, and custom contract term tabs.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Managing Contracts -->
                <div class="ct-help-section" id="managing-contracts">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">2</span>
                        <h3>Managing contracts</h3>
                    </div>
                    <p>Contracts are the core of this module. Each contract record captures everything you need to know about a supplier agreement &mdash; who it's with, how long it runs, what it costs, and what documents support it.</p>
                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div>
                                <strong>Create a contract</strong> &mdash; from the contracts dashboard, click the Add button. Fill in the contract name, select a supplier, and set the status.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div>
                                <strong>Set the dates</strong> &mdash; enter the start date, end date, and review date. The review date acts as your early warning so you have time to renegotiate or renew before the contract expires.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div>
                                <strong>Record the financials</strong> &mdash; enter the total contract value and select the payment schedule (monthly, quarterly, annually, or a custom schedule configured in settings).
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">4</div>
                            <div>
                                <strong>Add contract terms</strong> &mdash; use the rich text tabs to write out SLAs, KPIs, special conditions, or any other terms. Each tab uses the TinyMCE editor for full formatting.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">5</div>
                            <div>
                                <strong>Upload documents</strong> &mdash; attach signed copies, schedules, amendments, or any other supporting files directly to the contract record.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">6</div>
                            <div>
                                <strong>Save</strong> and the contract appears on your dashboard. You can return to edit it at any time or update the status as it moves through its lifecycle.
                            </div>
                        </div>
                    </div>
                    <p>The contracts dashboard gives you an at-a-glance view of all active, expiring, and expired contracts. The sidebar shows summary statistics &mdash; total contracts, those expiring soon, and quick links to create new records or jump to suppliers.</p>
                    <p class="ct-help-tip">Use the review date field to set reminders well before a contract's end date. This gives your procurement or legal team enough lead time to negotiate renewals on favourable terms.</p>
                </div>

                <!-- Section 3: Contract Terms (highlighted) -->
                <div class="ct-help-section ct-help-section-highlight" id="contract-terms">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num highlight">3</span>
                        <h3>Contract terms &mdash; rich text tabs</h3>
                    </div>
                    <p class="ct-help-intro">Every contract can have multiple term tabs, each containing rich formatted text. This is where you capture the substance of the agreement &mdash; service levels, performance indicators, obligations, and special conditions.</p>

                    <div class="ct-help-tabs-demo">
                        <div class="ct-help-tab-demo active">SLA</div>
                        <div class="ct-help-tab-demo">KPIs</div>
                        <div class="ct-help-tab-demo">Special Terms</div>
                        <div class="ct-help-tab-demo">Obligations</div>
                    </div>
                    <div class="ct-help-tab-body">
                        Each tab opens a TinyMCE rich text editor where you can write formatted content with headings, bullet lists, tables, links, and more. The content is saved per tab, so you can organise complex contracts into logical sections.
                    </div>

                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div>
                                <strong>Default tabs</strong> &mdash; new contracts start with the term tabs defined in Settings. Common examples include SLA, KPIs, and Special Terms.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div>
                                <strong>Write your terms</strong> &mdash; click a tab to open the editor. Use the toolbar to format text, create tables for SLA metrics, or add numbered lists for obligations.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div>
                                <strong>Add custom tabs</strong> &mdash; need a section for Penalties, Insurance, or Data Processing? Go to Settings and add new term tab types. They will appear on all new contracts.
                            </div>
                        </div>
                    </div>

                    <p class="ct-help-tip">Term tabs are configured globally in Settings &gt; Contract Term Tabs. Adding a new tab type makes it available across all contracts, helping maintain a consistent structure for your agreements.</p>
                </div>

                <!-- Section 4: Suppliers -->
                <div class="ct-help-section" id="suppliers">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">4</span>
                        <h3>Suppliers</h3>
                    </div>
                    <p>The Suppliers section is your central directory for every organisation you do business with. Whether it's a software vendor, a facilities management company, or a consultancy firm, each supplier gets a comprehensive record.</p>

                    <div class="ct-help-data-grid">
                        <div class="ct-help-data-card">
                            <strong>Legal name</strong>
                            <span>The official registered company name as it appears on legal documents</span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong>Trading name</strong>
                            <span>The name the supplier commonly trades under, if different from the legal name</span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong>Registration number</strong>
                            <span>Company registration or incorporation number for due diligence and compliance</span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong>Address</strong>
                            <span>Full registered address including street, city, county, postcode, and country</span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong>Supplier type</strong>
                            <span>Categorise suppliers (e.g. Software, Hardware, Consultancy, Facilities) using configurable types</span>
                        </div>
                        <div class="ct-help-data-card">
                            <strong>Status</strong>
                            <span>Track whether a supplier is Active, Inactive, Under Review, or any custom status you define</span>
                        </div>
                    </div>

                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div>
                                <strong>Add a supplier</strong> &mdash; navigate to the Suppliers page and click Add. Fill in the legal name, trading name, and registration details.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div>
                                <strong>Enter the address</strong> &mdash; record the supplier's registered address. This is important for legal correspondence and contract documentation.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div>
                                <strong>Set type and status</strong> &mdash; categorise the supplier using the type dropdown and mark their current status. Both are configurable in Settings.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">4</div>
                            <div>
                                <strong>Track questionnaires</strong> &mdash; record whether due diligence questionnaires have been sent and returned. This helps with supplier onboarding and compliance.
                            </div>
                        </div>
                    </div>

                    <p class="ct-help-tip">Keep supplier records up to date, especially registration numbers and addresses. These details are often needed for audit and compliance purposes, and outdated information can cause delays in procurement.</p>
                </div>

                <!-- Section 5: Contacts -->
                <div class="ct-help-section" id="contacts">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">5</span>
                        <h3>Contacts</h3>
                    </div>
                    <p>Contacts represent the individual people at your supplier organisations. Knowing who to call, email, or escalate to is essential when managing ongoing supplier relationships, especially when issues arise or contracts need renewing.</p>

                    <div class="ct-help-fields">
                        <div><strong>Name</strong> &mdash; the contact's full name, used as the primary identifier in the contacts list</div>
                        <div><strong>Job title</strong> &mdash; their role at the supplier organisation (e.g. Account Manager, Technical Lead, Sales Director)</div>
                        <div><strong>Email</strong> &mdash; their business email address for correspondence</div>
                        <div><strong>Mobile</strong> &mdash; a direct phone number for urgent or time-sensitive communication</div>
                        <div><strong>Supplier</strong> &mdash; which supplier this contact belongs to, selected from your supplier directory</div>
                        <div><strong>Status</strong> &mdash; Active or Inactive, so you can keep historical records without cluttering your active contact list</div>
                    </div>

                    <div class="ct-help-steps">
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">1</div>
                            <div>
                                <strong>Add a contact</strong> &mdash; go to the Contacts page and click Add. Enter the person's name, job title, and contact details.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">2</div>
                            <div>
                                <strong>Link to a supplier</strong> &mdash; select the supplier this contact works for. This creates the relationship so you can see all contacts for a given supplier.
                            </div>
                        </div>
                        <div class="ct-help-step-item">
                            <div class="ct-help-step-num">3</div>
                            <div>
                                <strong>Manage over time</strong> &mdash; when contacts leave or change roles, update their record or set them to Inactive rather than deleting. This preserves the audit trail and historical context.
                            </div>
                        </div>
                    </div>

                    <p class="ct-help-tip">Record multiple contacts per supplier wherever possible. Having a backup contact means you are not left without a point of contact if your primary person is unavailable or leaves the organisation.</p>
                </div>

                <!-- Section 6: Settings -->
                <div class="ct-help-section" id="settings">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">6</span>
                        <h3>Settings</h3>
                    </div>
                    <p>The Settings page lets you configure the dropdown lists and options used throughout the Contracts module. Everything is organised into tabs, and each setting type can be added, edited, or deactivated independently.</p>

                    <div class="ct-help-config-grid">
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot amber"></span> Supplier Types</h4>
                            <p>Define the categories used to classify suppliers. Examples: Software Vendor, Hardware Supplier, Consultancy, Managed Service Provider, Facilities. These appear in the type dropdown when creating or editing a supplier.</p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot green"></span> Supplier Statuses</h4>
                            <p>Control the lifecycle states for suppliers. Typical values include Active, Inactive, Under Review, and Onboarding. Use these to track which suppliers are currently approved for use.</p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot blue"></span> Contract Statuses</h4>
                            <p>Define the stages a contract moves through. Common statuses: Draft, Active, Expiring, Expired, Renewed, Terminated. These drive the status badges shown on the contracts dashboard.</p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot purple"></span> Payment Schedules</h4>
                            <p>Set up the payment frequency options available when recording a contract's financial terms. Standard options include Monthly, Quarterly, Annually, and One-off, but you can add any schedule that fits your needs.</p>
                        </div>
                        <div class="ct-help-config-card">
                            <h4><span class="config-dot red"></span> Contract Term Tabs</h4>
                            <p>Configure the rich text tabs that appear on every contract. Add tabs for SLAs, KPIs, Special Terms, Penalties, Data Processing, or any other section you need. Each tab gets its own TinyMCE editor on the contract form.</p>
                        </div>
                    </div>

                    <p>Each settings tab shows a list of existing items with their name, status, and action buttons. You can add new items, edit existing ones, or toggle them between active and inactive. Inactive items are hidden from dropdown menus but preserved in the database for historical records.</p>

                    <p class="ct-help-tip">Take time to set up your settings before creating contracts and suppliers. Well-defined categories and statuses make filtering, reporting, and auditing much easier down the line.</p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="ct-help-section" id="tips">
                    <div class="ct-help-section-header">
                        <span class="ct-help-section-num">7</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="ct-help-tips-grid">
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128197;</div>
                            <div><strong>Review dates</strong><br>Always set a review date at least 60&ndash;90 days before the contract end date. This gives you enough time to evaluate the supplier's performance, negotiate better terms, or find an alternative if needed.</div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128200;</div>
                            <div><strong>Track the money</strong><br>Record the contract value and payment schedule for every agreement, even low-value ones. This builds a complete picture of your supplier spend and helps with budgeting and forecasting.</div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128101;</div>
                            <div><strong>Supplier relationships</strong><br>Link contacts to suppliers and keep records current. When a key contact leaves, update the record immediately so your team always knows who to reach out to.</div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128196;</div>
                            <div><strong>Upload everything</strong><br>Attach signed contracts, amendments, and correspondence as documents. Having everything in one place saves time when you need to check terms or resolve disputes.</div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#128295;</div>
                            <div><strong>Use term tabs</strong><br>Break complex contracts into structured tabs &mdash; SLAs in one, KPIs in another, special conditions in a third. This makes it easy to find specific clauses without scrolling through a single long document.</div>
                        </div>
                        <div class="ct-help-tip-card">
                            <div class="ct-help-tip-icon">&#9889;</div>
                            <div><strong>Keep statuses current</strong><br>Update contract and supplier statuses as things change. An expired contract should be marked as such, and a supplier under review should reflect that state. Accurate statuses make the dashboard reliable.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.ct-help-nav-link');
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