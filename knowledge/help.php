<?php
/**
 * Knowledge Base Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Knowledge Base Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .kb-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .kb-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .kb-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .kb-help-nav-link {
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

        .kb-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .kb-help-nav-link.active {
            background: #f3e5f5;
            color: #6b4fa2;
            font-weight: 600;
        }

        .kb-help-nav-link.highlight {
            color: #6b4fa2;
        }

        .kb-help-nav-link.highlight.active {
            background: #6b4fa2;
            color: white;
        }

        .kb-help-nav-num {
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

        .kb-help-nav-link.active .kb-help-nav-num {
            background: #6b4fa2;
            color: white;
        }

        .kb-help-nav-num.highlight {
            background: #f3e5f5;
            color: #6b4fa2;
        }

        .kb-help-nav-link.highlight.active .kb-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .kb-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .kb-help-hero {
            background: linear-gradient(135deg, #8764b8 0%, #6b4fa2 50%, #4a3570 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .kb-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .kb-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .kb-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .kb-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .kb-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .kb-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .kb-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .kb-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .kb-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .kb-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f3e5f5;
            color: #6b4fa2;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .kb-help-section-num.highlight {
            background: #6b4fa2;
            color: white;
        }

        /* Feature cards grid */
        .kb-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .kb-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .kb-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .kb-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .kb-help-feature-icon.purple { background: #f3e5f5; color: #8764b8; }
        .kb-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .kb-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .kb-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .kb-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .kb-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .kb-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .kb-help-step-item {
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

        .kb-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #8764b8;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .kb-help-section-highlight {
            background: #f3e5f5;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #ce93d8;
        }

        .kb-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Review workflow flow */
        .kb-help-workflow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 20px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .kb-help-workflow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .kb-help-workflow-step.draft { background: #e3f2fd; color: #1565c0; }
        .kb-help-workflow-step.review { background: #fff3e0; color: #e65100; }
        .kb-help-workflow-step.approved { background: #e8f5e9; color: #2e7d32; }
        .kb-help-workflow-step.published { background: #f3e5f5; color: #6b4fa2; }

        .kb-help-workflow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Review status badges */
        .kb-help-status-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .kb-help-status-card {
            padding: 14px 16px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #8764b8;
        }

        .kb-help-status-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .kb-help-status-card span {
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        .kb-help-status-card.pending { border-left-color: #e65100; }
        .kb-help-status-card.approved { border-left-color: #2e7d32; }
        .kb-help-status-card.rejected { border-left-color: #c62828; }

        /* AI chat preview */
        .kb-help-ai-demo {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin: 16px 0;
        }

        .kb-help-ai-msg {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
        }

        .kb-help-ai-msg:last-child {
            margin-bottom: 0;
        }

        .kb-help-ai-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .kb-help-ai-avatar.user { background: #f3e5f5; color: #6b4fa2; }
        .kb-help-ai-avatar.ai { background: #e3f2fd; color: #1565c0; }

        .kb-help-ai-bubble {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            color: #444;
            max-width: 80%;
        }

        .kb-help-ai-bubble.user { background: #f3e5f5; }
        .kb-help-ai-bubble.ai { background: #f5f5f5; }

        /* Info fields list */
        .kb-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .kb-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Settings options grid */
        .kb-help-settings-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .kb-help-setting-card {
            padding: 14px 16px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #8764b8;
        }

        .kb-help-setting-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .kb-help-setting-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Tip callout */
        .kb-help-tip {
            font-size: 13px !important;
            color: #6b4fa2 !important;
            background: #f3e5f5;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #8764b8;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .kb-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .kb-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .kb-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .kb-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .kb-help-sidebar { display: none; }
            .kb-help-content { padding: 10px 24px 40px; }
            .kb-help-hero { padding: 30px 24px; }
            .kb-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .kb-help-features-grid { grid-template-columns: 1fr; }
            .kb-help-status-grid { grid-template-columns: 1fr; }
            .kb-help-settings-grid { grid-template-columns: 1fr; }
            .kb-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="kb-help-container">
        <!-- Left pane navigation -->
        <div class="kb-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="kb-help-nav-link active" data-section="overview">
                <span class="kb-help-nav-num">1</span>
                Overview
            </a>
            <a href="#writing-articles" class="kb-help-nav-link" data-section="writing-articles">
                <span class="kb-help-nav-num">2</span>
                Writing articles
            </a>
            <a href="#review-workflow" class="kb-help-nav-link highlight" data-section="review-workflow">
                <span class="kb-help-nav-num highlight">3</span>
                Review workflow
            </a>
            <a href="#ask-ai" class="kb-help-nav-link" data-section="ask-ai">
                <span class="kb-help-nav-num">4</span>
                Ask AI
            </a>
            <a href="#search-navigation" class="kb-help-nav-link" data-section="search-navigation">
                <span class="kb-help-nav-num">5</span>
                Search &amp; navigation
            </a>
            <a href="#sharing-export" class="kb-help-nav-link highlight" data-section="sharing-export">
                <span class="kb-help-nav-num highlight">6</span>
                Sharing &amp; export
            </a>
            <a href="#tips" class="kb-help-nav-link" data-section="tips">
                <span class="kb-help-nav-num">7</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="kb-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="kb-help-hero">
                <h2>Knowledge base guide</h2>
                <p>Create, review, and share knowledge articles so your team always has the answers they need.</p>
            </div>

            <div class="kb-help-content">

                <!-- Section 1: Overview -->
                <div class="kb-help-section" id="overview">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Knowledge module is your team's central repository for documentation, how-to guides, troubleshooting steps, and institutional know-how. Instead of answers living in someone's head or buried in email threads, they live here &mdash; searchable, version-controlled, and available to everyone. Well-maintained knowledge articles reduce ticket volumes, speed up resolution times, and help new team members get up to speed faster.</p>
                        </div>
                    </div>
                    <div class="kb-help-features-grid">
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                            </div>
                            <h4>Articles</h4>
                            <p>Write rich-text articles with a full-featured editor. Add headings, lists, tables, code blocks, images, and more. Every article supports tagging, ownership, and scheduled review dates to keep content fresh.</p>
                        </div>
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4>Review workflow</h4>
                            <p>Submit articles for peer review before publishing. Reviewers can approve, request changes, or reject. The review queue keeps track of what needs attention so nothing falls through the cracks.</p>
                        </div>
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <h4>Ask AI</h4>
                            <p>Chat with an AI assistant that understands your knowledge base. Ask questions in natural language and get answers drawn from your published articles, complete with source references you can click through to.</p>
                        </div>
                        <div class="kb-help-feature-card">
                            <div class="kb-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4>Search &amp; tags</h4>
                            <p>Find articles instantly with full-text search across titles and content. Filter by tags to narrow results. Tags act as flexible categories that let you organise articles across multiple dimensions.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Writing Articles -->
                <div class="kb-help-section" id="writing-articles">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">2</span>
                        <h3>Writing articles &mdash; step by step</h3>
                    </div>
                    <p>A good knowledge article answers a single question clearly and completely. Whether it's a how-to guide, a troubleshooting runbook, or a policy document, the goal is the same: someone who reads it should be able to act on it without needing to ask a colleague.</p>
                    <div class="kb-help-steps">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <strong>Click "+ New Article"</strong> in the sidebar to open the article editor. This gives you a blank canvas with all the fields you need.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <strong>Give it a clear title</strong> &mdash; use the kind of phrasing someone would actually search for. "How to reset a user's password in Active Directory" is better than "Password Reset Procedure v2.1".
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <strong>Add tags</strong> &mdash; type in the tag input and press Enter or comma to add. Tags let people find your article through filtering as well as search. Use a mix of broad tags (e.g. "Active Directory") and specific ones (e.g. "password-reset").
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">4</div>
                            <div>
                                <strong>Assign an owner</strong> &mdash; the owner is the person responsible for keeping the article up to date. When it comes up for review, the owner gets notified.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">5</div>
                            <div>
                                <strong>Set a review date</strong> &mdash; knowledge goes stale. Set a next-review date so the article surfaces in the review queue at the right time. Quarterly reviews work well for most content; critical procedures might need monthly checks.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">6</div>
                            <div>
                                <strong>Write the content</strong> &mdash; the rich text editor supports headings, bold, italic, bullet and numbered lists, tables, code blocks with syntax highlighting, images, and links. Structure your article with clear headings so readers can scan it quickly.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">7</div>
                            <div>
                                <strong>Save</strong> &mdash; your article is saved as a draft. You can come back and edit it anytime. When you're happy with it, submit it for review or publish it directly depending on your team's workflow.
                            </div>
                        </div>
                    </div>
                    <p class="kb-help-tip">Every time you save significant changes, use the "Save as new version" option. This creates a versioned snapshot so you can always look back at what the article said previously. Version numbers display on the article view so readers know how current it is.</p>
                </div>

                <!-- Section 3: Review Workflow (highlighted) -->
                <div class="kb-help-section kb-help-section-highlight" id="review-workflow">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num highlight">3</span>
                        <h3>Review workflow &mdash; getting articles approved</h3>
                    </div>
                    <p class="kb-help-intro">For teams that need quality control over published content, the review workflow ensures every article is checked by a second pair of eyes before it goes live. This is especially valuable for customer-facing documentation, compliance procedures, and technical runbooks where accuracy matters.</p>

                    <div class="kb-help-workflow">
                        <div class="kb-help-workflow-step draft">Draft</div>
                        <div class="kb-help-workflow-arrow">&rarr;</div>
                        <div class="kb-help-workflow-step review">Pending Review</div>
                        <div class="kb-help-workflow-arrow">&rarr;</div>
                        <div class="kb-help-workflow-step approved">Approved</div>
                        <div class="kb-help-workflow-arrow">&rarr;</div>
                        <div class="kb-help-workflow-step published">Published</div>
                    </div>

                    <div class="kb-help-steps" style="margin-left: 0;">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <strong>Author submits for review</strong> &mdash; when the article is ready, change its status to "Pending Review". It appears in the Review queue where reviewers can see it.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <strong>Reviewer opens the article</strong> &mdash; navigate to the Review page from the top navigation. The queue shows all articles awaiting review, with filter tabs to focus on what needs your attention.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <strong>Approve, request changes, or reject</strong> &mdash; after reading the article, the reviewer picks an action. If changes are needed, the article goes back to the author with feedback.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">4</div>
                            <div>
                                <strong>Article is published</strong> &mdash; once approved, the article becomes visible to all team members and is indexed for search and AI queries.
                            </div>
                        </div>
                    </div>

                    <div class="kb-help-status-grid" style="margin-top: 20px;">
                        <div class="kb-help-status-card pending">
                            <strong>Pending Review</strong>
                            <span>The article is waiting for a reviewer to assess it. It shows in the review queue with the author's name and submission date.</span>
                        </div>
                        <div class="kb-help-status-card approved">
                            <strong>Approved</strong>
                            <span>A reviewer has signed off on the content. The article is accurate, well-structured, and ready for the team to use.</span>
                        </div>
                        <div class="kb-help-status-card rejected">
                            <strong>Changes Requested</strong>
                            <span>The reviewer found issues that need addressing. The article returns to draft status with the reviewer's feedback attached.</span>
                        </div>
                        <div class="kb-help-status-card">
                            <strong>Scheduled Review</strong>
                            <span>Articles with a review date appear in the queue when that date arrives. This keeps knowledge fresh without anyone having to remember manually.</span>
                        </div>
                    </div>

                    <p class="kb-help-tip">Review isn't just about catching mistakes. It's an opportunity for knowledge sharing &mdash; reviewers often add useful context, alternative approaches, or edge cases the original author didn't consider.</p>
                </div>

                <!-- Section 4: Ask AI -->
                <div class="kb-help-section" id="ask-ai">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">4</span>
                        <h3>Ask AI &mdash; your intelligent assistant</h3>
                    </div>
                    <p>The Ask AI feature connects you to an AI-powered assistant that has been trained on your published knowledge articles. Instead of searching and reading through multiple articles, you can ask a question in plain English and get a synthesised answer in seconds.</p>

                    <div class="kb-help-ai-demo">
                        <div class="kb-help-ai-msg">
                            <div class="kb-help-ai-avatar user">You</div>
                            <div class="kb-help-ai-bubble user">How do I set up multi-factor authentication for a new starter?</div>
                        </div>
                        <div class="kb-help-ai-msg">
                            <div class="kb-help-ai-avatar ai">AI</div>
                            <div class="kb-help-ai-bubble ai">Based on your knowledge base, here's the process for setting up MFA for new starters: First, ensure their account is created in Active Directory. Then navigate to the Azure AD portal and enable MFA for their account... <em>(references: "New Starter Onboarding Guide", "MFA Setup Procedure")</em></div>
                        </div>
                    </div>

                    <div class="kb-help-steps">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <strong>Open the chat</strong> &mdash; click "Ask AI" in the top navigation bar. A chat panel slides in from the right side of the screen, ready for your question.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <strong>Type your question</strong> &mdash; ask anything you'd normally look up in the knowledge base. The AI searches across all published articles to find relevant information.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <strong>Read the answer</strong> &mdash; the AI returns a synthesised response drawn from your articles. Source articles are referenced so you can click through to read the full content.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">4</div>
                            <div>
                                <strong>Follow up</strong> &mdash; ask clarifying questions in the same conversation. The AI remembers the context so you can drill deeper without repeating yourself.
                            </div>
                        </div>
                    </div>
                    <p class="kb-help-tip">The AI can also be accessed from the ticket detail view. When working a ticket, click "Ask AI" to get the AI pre-loaded with the ticket's context, so it can suggest relevant knowledge articles and solutions specific to that issue.</p>
                </div>

                <!-- Section 5: Search & Navigation -->
                <div class="kb-help-section" id="search-navigation">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">5</span>
                        <h3>Search &amp; navigation</h3>
                    </div>
                    <p>Finding the right article quickly is the difference between a knowledge base people actually use and one they ignore. The Knowledge module offers multiple ways to find what you need.</p>

                    <div class="kb-help-fields">
                        <div><strong>Full-text search</strong> &mdash; the search box in the sidebar searches across article titles and body content as you type. Results update in real time with a short debounce so it doesn't fire on every keystroke.</div>
                        <div><strong>Tag filtering</strong> &mdash; the sidebar lists all available tags. Click one or more tags to filter the article list to only those articles that carry the selected tags. This is a powerful way to browse by topic.</div>
                        <div><strong>Combined search + tags</strong> &mdash; search and tag filters work together. Select a tag to narrow the scope, then type a search term to find specific content within that tag group.</div>
                        <div><strong>Article count</strong> &mdash; the header always shows how many articles match your current filters, so you know how broad or narrow your results are.</div>
                        <div><strong>Recycle bin</strong> &mdash; archived articles are moved to the recycle bin rather than permanently deleted. Click the Recycle Bin button in the sidebar to view and restore archived articles when needed.</div>
                    </div>
                    <p class="kb-help-tip">Consistent tagging makes a huge difference. Agree on a tagging convention with your team &mdash; for example, always using lowercase, hyphen-separated tags like "active-directory" or "network-setup". The tag suggestion feature helps by showing existing tags as you type.</p>
                </div>

                <!-- Section 6: Sharing & Export (highlighted) -->
                <div class="kb-help-section kb-help-section-highlight" id="sharing-export">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num highlight">6</span>
                        <h3>Sharing &amp; export</h3>
                    </div>
                    <p class="kb-help-intro">Knowledge is most valuable when it reaches the people who need it. The sharing features make it easy to get articles to colleagues, end users, or external stakeholders without requiring them to log into the system.</p>

                    <div class="kb-help-steps" style="margin-left: 0;">
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">1</div>
                            <div>
                                <strong>Share Link</strong> &mdash; generates a direct link to the article. Send it to a colleague in chat or paste it into a ticket. Anyone with access to the knowledge base can open it.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">2</div>
                            <div>
                                <strong>Export as PDF</strong> &mdash; converts the article to a clean PDF document. Ideal for sending to people outside the system, including in support emails or attaching to change requests.
                            </div>
                        </div>
                        <div class="kb-help-step-item">
                            <div class="kb-help-step-num">3</div>
                            <div>
                                <strong>Email (Link + PDF)</strong> &mdash; opens your email client with both the link and a PDF attachment pre-loaded. This gives the recipient the option to read online or offline.
                            </div>
                        </div>
                    </div>

                    <p>All sharing options are accessed from the <strong>Share</strong> dropdown button when viewing an article. Click the share icon to reveal the three options above.</p>

                    <p class="kb-help-tip">When resolving a ticket, sharing a relevant knowledge article with the end user helps them solve similar problems in the future without needing to raise another ticket. This is one of the best ways to reduce repeat contacts.</p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="kb-help-section" id="tips">
                    <div class="kb-help-section-header">
                        <span class="kb-help-section-num">7</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="kb-help-tips-grid">
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128221;</div>
                            <div><strong>Write for scanning</strong><br>Use headings, short paragraphs, and bullet lists. Most readers scan articles looking for the specific step they need, rather than reading top to bottom.</div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128197;</div>
                            <div><strong>Review dates matter</strong><br>Set a review date on every article. Outdated knowledge is worse than no knowledge &mdash; it leads people down the wrong path and erodes trust in the knowledge base.</div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128278;</div>
                            <div><strong>Version your changes</strong><br>Use "Save as new version" for significant updates. This preserves a history of how the article evolved and lets you roll back if something goes wrong.</div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#127991;</div>
                            <div><strong>Tag consistently</strong><br>Agree on naming conventions for tags across your team. Consistent tagging makes filtering reliable and helps the AI give better answers.</div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#128172;</div>
                            <div><strong>AI from tickets</strong><br>When working on a ticket, use the Ask AI button to get contextual suggestions. The AI reads the ticket details and searches your knowledge base for relevant solutions.</div>
                        </div>
                        <div class="kb-help-tip-card">
                            <div class="kb-help-tip-icon">&#9851;</div>
                            <div><strong>Recycle bin</strong><br>Archived articles go to the recycle bin, not into the void. If you archive something by mistake, you can restore it with a click. Check the bin periodically and clean up articles that are truly no longer needed.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.kb-help-nav-link');
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
