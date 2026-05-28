<?php
/**
 * LMS Module Help Guide — full page with left pane navigation.
 *
 * Mirrors the network-mapper/help.php and process-mapper/help.php structure
 * (sidebar + hero + numbered sections + scroll-spy). Blue branding to match
 * the LMS module palette.
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
    <title>FreeITSM &mdash; LMS Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .lh-container { display: flex; height: calc(100vh - 48px); background: #f5f5f5; }

        /* ---- Sidebar nav ---- */
        .lh-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }
        .lh-sidebar h3 {
            font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .lh-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 13px; color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .lh-nav-link:hover { background: #f5f5f5; color: #333; }
        .lh-nav-link.active { background: #dbeafe; color: #1e40af; font-weight: 600; }
        .lh-nav-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px;
            border-radius: 50%;
            background: #f5f5f5; color: #888;
            font-size: 11px; font-weight: 700;
        }
        .lh-nav-link.active .lh-nav-num { background: #2563eb; color: white; }

        /* ---- Main content ---- */
        .lh-main { flex: 1; overflow-y: auto; }
        .lh-hero {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 50%, #1e3a8a 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .lh-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .lh-hero p  { margin: 0; font-size: 15px; opacity: 0.9; }
        .lh-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* ---- Sections ---- */
        .lh-section { padding: 28px 0; border-bottom: 1px solid #eee; scroll-margin-top: 20px; }
        .lh-section:last-child { border-bottom: 0; padding-bottom: 0; }
        .lh-section-header {
            display: flex; align-items: flex-start; gap: 14px;
            margin-bottom: 16px;
        }
        .lh-section-header h3 { margin: 0; font-size: 18px; color: #333; }
        .lh-section-header p  { margin: 6px 0 0; font-size: 14px; color: #666; line-height: 1.6; }
        .lh-section > p {
            font-size: 14px; color: #555; line-height: 1.7;
            margin: 0 0 14px;
        }
        .lh-section-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px;
            border-radius: 50%;
            background: #dbeafe; color: #1e40af;
            font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        .lh-section-num.highlight { background: #2563eb; color: white; }

        /* ---- Feature card grid ---- */
        .lh-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .lh-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .lh-feature-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .lh-feature-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .lh-feature-icon.blue   { background: #dbeafe; color: #1e40af; }
        .lh-feature-icon.indigo { background: #e0e7ff; color: #4338ca; }
        .lh-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .lh-feature-icon.amber  { background: #fff7ed; color: #c2410c; }
        .lh-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: #333; }
        .lh-feature-card p  { margin: 0; font-size: 12.5px; color: #666; line-height: 1.5; }

        /* ---- Numbered steps ---- */
        .lh-steps { display: flex; flex-direction: column; gap: 12px; margin-left: 46px; }
        .lh-step-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 10px 14px; border-radius: 8px;
            background: #fafafa;
            font-size: 14px; color: #444; line-height: 1.5;
        }
        .lh-step-num {
            display: flex; align-items: center; justify-content: center;
            min-width: 28px; height: 28px;
            border-radius: 50%;
            background: #2563eb; color: white;
            font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }

        /* ---- Highlighted section ---- */
        .lh-section-highlight {
            background: #dbeafe;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #93c5fd;
        }

        /* ---- Flow row ---- */
        .lh-flow {
            display: flex; align-items: center; gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }
        .lh-flow-step {
            display: flex; align-items: center; justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px; font-weight: 600;
            text-align: center;
        }
        .lh-flow-step.s1 { background: #dbeafe; color: #1e40af; }
        .lh-flow-step.s2 { background: #e0e7ff; color: #4338ca; }
        .lh-flow-step.s3 { background: #e8f5e9; color: #2e7d32; }
        .lh-flow-step.s4 { background: #fff3e0; color: #c2410c; }
        .lh-flow-arrow { padding: 0 8px; color: #bbb; font-size: 18px; }

        /* ---- Callouts ---- */
        .lh-tip {
            font-size: 13px !important;
            color: #1e40af !important;
            background: #dbeafe;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #2563eb;
            margin-top: 10px;
        }
        .lh-warn {
            font-size: 13px !important;
            color: #9a3412 !important;
            background: #fff7ed;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f97316;
            margin-top: 10px;
        }

        /* ---- Keyboard chip ---- */
        .lh-kbd {
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
        .lh-tips-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .lh-tip-card {
            display: flex; gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }
        .lh-tip-icon { font-size: 24px; flex-shrink: 0; line-height: 1; }
        .lh-tip-card strong { color: #333; }

        /* ---- Status pills used in copy ---- */
        .lh-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            vertical-align: middle;
            text-transform: uppercase;
        }
        .lh-pill.not-started { background: #f5f5f5; color: #666; border: 1px solid #e0e0e0; }
        .lh-pill.incomplete  { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .lh-pill.completed   { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .lh-pill.passed      { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .lh-pill.failed      { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .lh-pill.overdue     { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        /* ---- Responsive ---- */
        @media (max-width: 900px) {
            .lh-sidebar { display: none; }
            .lh-content { padding: 10px 24px 40px; }
            .lh-hero { padding: 30px 24px; }
            .lh-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }
        @media (max-width: 700px) {
            .lh-features-grid { grid-template-columns: 1fr; }
            .lh-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lh-container">
        <!-- Sidebar nav -->
        <div class="lh-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="lh-nav-link active" data-section="overview">
                <span class="lh-nav-num">1</span> Overview
            </a>
            <a href="#uploading" class="lh-nav-link" data-section="uploading">
                <span class="lh-nav-num">2</span> Uploading a course
            </a>
            <a href="#groups" class="lh-nav-link" data-section="groups">
                <span class="lh-nav-num">3</span> Learning groups
            </a>
            <a href="#assigning" class="lh-nav-link" data-section="assigning">
                <span class="lh-nav-num">4</span> Assigning courses
            </a>
            <a href="#launching" class="lh-nav-link" data-section="launching">
                <span class="lh-nav-num">5</span> Launching a course
            </a>
            <a href="#progress" class="lh-nav-link" data-section="progress">
                <span class="lh-nav-num">6</span> Tracking progress
            </a>
            <a href="#learner-data" class="lh-nav-link" data-section="learner-data">
                <span class="lh-nav-num">7</span> Learner data drill-down
            </a>
            <a href="#scorm" class="lh-nav-link" data-section="scorm">
                <span class="lh-nav-num">8</span> SCORM support
            </a>
            <a href="#tips" class="lh-nav-link" data-section="tips">
                <span class="lh-nav-num">9</span> Quick tips
            </a>
        </div>

        <!-- Main content -->
        <div class="lh-main" id="helpMain">
            <div class="lh-hero">
                <h2>LMS guide</h2>
                <p>Upload SCORM packages, group your analysts, assign training, and track every learner's progress &mdash; right alongside the rest of the service desk.</p>
            </div>

            <div class="lh-content">

                <!-- 1. Overview -->
                <div class="lh-section" id="overview">
                    <div class="lh-section-header">
                        <span class="lh-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The LMS module is a full SCORM player and tracker built into the service desk. Drop in any SCORM 1.1, 1.2, or 2004 package, organise your analysts into learning groups, assign courses with optional deadlines, and watch progress roll in as people work through the content. No external LMS, no paid integration, no separate login.</p>
                        </div>
                    </div>

                    <div class="lh-flow">
                        <div class="lh-flow-step s1">Upload SCORM</div>
                        <div class="lh-flow-arrow">&rarr;</div>
                        <div class="lh-flow-step s2">Create groups</div>
                        <div class="lh-flow-arrow">&rarr;</div>
                        <div class="lh-flow-step s3">Assign</div>
                        <div class="lh-flow-arrow">&rarr;</div>
                        <div class="lh-flow-step s4">Track progress</div>
                    </div>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5"/></svg>
                            </div>
                            <h4>SCORM 1.1 / 1.2 / 2004</h4>
                            <p>Version auto-detected from the manifest. Both runtime APIs (LMS-prefixed for 1.x, IEEE 1484.11 for 2004) supported on the same player.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <h4>Learning groups</h4>
                            <p>Group analysts by team, role, or topic; assign training to a group with one click.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7L9 18l-5-5"/></svg>
                            </div>
                            <h4>Bookmarks &amp; resume</h4>
                            <p>SCORM <code>lesson_location</code> and <code>suspend_data</code> persist between sessions &mdash; learners pick up where they left off.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </div>
                            <h4>Granular tracking</h4>
                            <p>Status, score, attempts, total time, every interaction and objective &mdash; visible per learner in the drill-down view.</p>
                        </div>
                    </div>
                </div>

                <!-- 2. Uploading a course -->
                <div class="lh-section" id="uploading">
                    <div class="lh-section-header">
                        <span class="lh-section-num highlight">2</span>
                        <div>
                            <h3>Uploading a course</h3>
                            <p>From the <strong>Courses</strong> tab, hit <strong>Upload</strong>. Give the course a title (the manifest title isn't always learner-friendly, so this is your chance to rename it for the catalogue) and an optional description. Pick the SCORM ZIP and submit.</p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div>Click <strong>Upload</strong> on the Courses tab.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div>Enter a title (required) and a description (optional).</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div>Pick the <code>.zip</code> file &mdash; upload the package as-is, don't extract it first.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div>Submit. The server unzips, parses <code>imsmanifest.xml</code>, detects the SCORM version, and stores the launch URL.</div></div>
                    </div>

                    <p class="lh-tip"><strong>Manifest location:</strong> the upload handler looks for <code>imsmanifest.xml</code> at the root of the ZIP first, then falls back to checking inside a single top-level subfolder. Both layouts work.</p>
                    <p class="lh-tip"><strong>Launch URL detection:</strong> the first <code>&lt;resource&gt;</code> with <code>scormType="sco"</code> wins. If no SCO is flagged, the first resource with an <code>href</code> is used as a fallback.</p>
                    <p class="lh-warn"><strong>Upload size limits:</strong> SCORM ZIPs can be large (hundreds of MB if they contain video). If a big package fails to upload, check your PHP <code>upload_max_filesize</code> and <code>post_max_size</code> in <code>php.ini</code> &mdash; the LMS doesn't impose its own cap, but the web server might.</p>
                </div>

                <!-- 3. Learning groups -->
                <div class="lh-section" id="groups">
                    <div class="lh-section-header">
                        <span class="lh-section-num">3</span>
                        <div>
                            <h3>Learning groups</h3>
                            <p>Groups are the unit of assignment. Rather than assigning every course to every analyst individually, you group analysts &mdash; by team (Service Desk Tier 1), by role (New Starter), by topic (Compliance 2026) &mdash; and assign courses to the group. Anyone added to a group later automatically inherits its assignments.</p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div>Switch to the <strong>Groups</strong> tab and hit <strong>New</strong>.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div>Give the group a name (e.g. <em>New Starters</em>) and an optional description.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div>Tick the analysts who should be members &mdash; you can change membership any time.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div>Save. The group is now available in the Assignments tab.</div></div>
                    </div>

                    <p class="lh-tip"><strong>An analyst can be in many groups:</strong> overlapping memberships are fine. If Jane is in both <em>Tier 1</em> and <em>New Starters</em>, she just sees both groups' assignments in her progress view.</p>
                </div>

                <!-- 4. Assigning courses -->
                <div class="lh-section" id="assigning">
                    <div class="lh-section-header">
                        <span class="lh-section-num">4</span>
                        <div>
                            <h3>Assigning courses</h3>
                            <p>The <strong>Assignments</strong> tab pairs a course with a group, optionally with a deadline. As soon as the assignment is saved, every member of the group gets a progress record for that course (status <span class="lh-pill not-started">Not Started</span>) and the course becomes playable for them.</p>
                        </div>
                    </div>

                    <div class="lh-steps">
                        <div class="lh-step-item"><span class="lh-step-num">1</span><div>Click <strong>Assign</strong> on the Assignments tab.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">2</span><div>Pick a course and a group from the dropdowns.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">3</span><div>Set a deadline (optional). Leave blank for open-ended training.</div></div>
                        <div class="lh-step-item"><span class="lh-step-num">4</span><div>Save. Progress records appear immediately in the Progress tab.</div></div>
                    </div>

                    <p class="lh-tip"><strong>Deadlines drive the Overdue filter:</strong> any progress record where the deadline has passed and the status isn't <span class="lh-pill completed">Completed</span> or <span class="lh-pill passed">Passed</span> shows up under the <span class="lh-pill overdue">Overdue</span> filter on the Progress tab.</p>
                    <p class="lh-warn"><strong>One assignment per course/group pair:</strong> a duplicate combination is rejected (you'll see a friendly error). Want to re-assign with a new deadline? Delete the old assignment first, then create a new one.</p>
                </div>

                <!-- 5. Launching a course -->
                <div class="lh-section lh-section-highlight" id="launching">
                    <div class="lh-section-header">
                        <span class="lh-section-num highlight">5</span>
                        <div>
                            <h3>Launching a course</h3>
                            <p>Click the play icon next to a course in the Courses tab (or open it from the Progress tab) and the SCORM player loads in an iframe. The SCORM API bridge runs on the parent window, so the course's runtime calls (<code>LMSInitialize</code>, <code>LMSGetValue</code>, <code>LMSSetValue</code>, <code>LMSCommit</code>, <code>LMSFinish</code> for 1.x, or <code>Initialize</code> / <code>GetValue</code> / <code>SetValue</code> / <code>Commit</code> / <code>Terminate</code> for 2004) are intercepted and persisted to the database.</p>
                        </div>
                    </div>

                    <div class="lh-features-grid" style="margin-top: 14px;">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">&#x21BB;</div>
                            <h4>Resume from bookmark</h4>
                            <p><code>cmi.core.lesson_location</code> (1.2) or <code>cmi.location</code> (2004) is restored on launch &mdash; courses that track which slide you're on will jump straight there.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">&#x1F4BE;</div>
                            <h4>Suspend data preserved</h4>
                            <p><code>cmi.suspend_data</code> survives between sessions, so quiz state, branching choices, and progress within a SCO all carry over.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">&#x2713;</div>
                            <h4>Auto-commit on close</h4>
                            <p>The <code>beforeunload</code> handler fires <code>Commit</code> + <code>Finish</code> / <code>Terminate</code> automatically, so closing the tab without clicking the course's exit button still saves progress.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">&#x1F4CA;</div>
                            <h4>Status flows through</h4>
                            <p><code>lesson_status</code> / <code>completion_status</code> / <code>success_status</code> are mapped into the normalised LMS status (Incomplete &rarr; Completed &rarr; Passed/Failed) and denormalised onto the progress row.</p>
                        </div>
                    </div>

                    <p style="margin-top: 14px;">Each launch increments the <strong>attempt count</strong> on the progress record. The first access timestamp is set on the very first launch; the last access timestamp updates on every launch and every commit.</p>
                    <p class="lh-tip"><strong>Player sandbox:</strong> the iframe runs with <code>sandbox="allow-scripts allow-same-origin allow-forms allow-popups"</code> &mdash; tight enough to limit what a hostile package could do, loose enough that legitimate SCORM content works.</p>
                </div>

                <!-- 6. Tracking progress -->
                <div class="lh-section" id="progress">
                    <div class="lh-section-header">
                        <span class="lh-section-num">6</span>
                        <div>
                            <h3>Tracking progress</h3>
                            <p>The <strong>Progress</strong> tab is the dashboard for every analyst &times; course pair. Each row shows the analyst, the course, the group it came through, the current status, the score (if scored), the deadline, and the last access time. Three filters let you slice the view: by course, by group, and by status.</p>
                        </div>
                    </div>

                    <p>Status values, in roughly the order learners progress through them:</p>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon" style="background: #f5f5f5; color: #666;">&#x25CB;</div>
                            <h4><span class="lh-pill not-started">Not Started</span></h4>
                            <p>Course is assigned but the learner hasn't launched it yet.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">&#x25D0;</div>
                            <h4><span class="lh-pill incomplete">Incomplete</span></h4>
                            <p>Learner has started but the course hasn't reported completion yet.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">&#x2714;</div>
                            <h4><span class="lh-pill completed">Completed</span></h4>
                            <p>Course reported completion (no pass/fail concept, or not yet judged).</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">&#x2605;</div>
                            <h4><span class="lh-pill passed">Passed</span> / <span class="lh-pill failed">Failed</span></h4>
                            <p>Course reported success or failure (scored courses). Outranks plain Completed.</p>
                        </div>
                    </div>

                    <p class="lh-tip"><strong>Overdue is computed live:</strong> there's no "Overdue" status stored on the row &mdash; the filter checks the deadline against today and excludes anything already Completed or Passed. So a deadline you change later, or a course that gets completed on the deadline day, behaves the way you'd expect.</p>
                </div>

                <!-- 7. Learner data drill-down -->
                <div class="lh-section" id="learner-data">
                    <div class="lh-section-header">
                        <span class="lh-section-num">7</span>
                        <div>
                            <h3>Learner data drill-down</h3>
                            <p>Click the eye icon at the end of any progress row and the Learner Data modal opens. This is the full SCORM CMI dump for that analyst's attempt at that course &mdash; useful for audits, troubleshooting "I thought I passed" disputes, and just understanding what a course is actually tracking.</p>
                        </div>
                    </div>

                    <p>The modal groups the data into:</p>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <h4>Summary</h4>
                            <p>Status, score (raw / min / max), total time spent, attempt count, first &amp; last access, completion date, bookmark.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            </div>
                            <h4>Interactions</h4>
                            <p>Every quiz question, answer given, correct answer, score, and result &mdash; one row per <code>cmi.interactions.N</code>.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4>Objectives</h4>
                            <p>SCORM 2004 learning objectives with per-objective status and score (when the course tracks them).</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </div>
                            <h4>Suspend data + raw CMI</h4>
                            <p>The opaque <code>cmi.suspend_data</code> blob (decoded if it's JSON) plus every other CMI element written by the course.</p>
                        </div>
                    </div>

                    <p class="lh-tip"><strong>Audit-friendly:</strong> the Interactions table is the closest thing to a tamper-evident answer log most LMS systems offer &mdash; useful evidence if you ever need to prove someone completed mandatory training honestly.</p>
                </div>

                <!-- 8. SCORM support -->
                <div class="lh-section" id="scorm">
                    <div class="lh-section-header">
                        <span class="lh-section-num">8</span>
                        <div>
                            <h3>SCORM support</h3>
                            <p>The LMS speaks all three mainstream SCORM versions and detects which one a course expects automatically.</p>
                        </div>
                    </div>

                    <div class="lh-features-grid">
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon blue">1.1</div>
                            <h4>SCORM 1.1</h4>
                            <p>Legacy AICC-style API. Detected when <code>schemaversion</code> reads "1.1". Same runtime contract as 1.2 in practice.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon indigo">1.2</div>
                            <h4>SCORM 1.2</h4>
                            <p>The classic. <code>API</code> object exposed on the parent window with the <code>LMS</code>-prefixed methods. Default fallback if version detection is ambiguous.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon green">2004</div>
                            <h4>SCORM 2004 (2nd, 3rd, 4th Edition)</h4>
                            <p><code>API_1484_11</code> object with IEEE 1484.11 unprefixed methods. Detected from the <code>2004</code> schema version or the <code>adlcp v1p3</code> namespace.</p>
                        </div>
                        <div class="lh-feature-card">
                            <div class="lh-feature-icon amber">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                            </div>
                            <h4>Manifest requirements</h4>
                            <p><code>imsmanifest.xml</code> at the ZIP root (or one level deep); at least one <code>&lt;resource&gt;</code> with an <code>href</code>; a <code>scormType="sco"</code> resource preferred but not required.</p>
                        </div>
                    </div>

                    <p class="lh-tip"><strong>Testing:</strong> the Rustici Software "Golf Explained" sample packages (scorm.com/scorm-explained/technical-scorm/golf-examples) are the gold-standard SCORM conformance tests and all play cleanly in the LMS module &mdash; useful as a smoke test if you ever wonder whether a problem is in the player or the package.</p>
                </div>

                <!-- 9. Quick tips -->
                <div class="lh-section" id="tips">
                    <div class="lh-section-header">
                        <span class="lh-section-num">9</span>
                        <div>
                            <h3>Quick tips</h3>
                        </div>
                    </div>
                    <div class="lh-tips-grid">
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F4E6;</span><div>Upload the SCORM ZIP <strong>as-is</strong> &mdash; the server extracts it for you.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F465;</span><div>Group memberships are <strong>live</strong> &mdash; add an analyst to a group and they pick up that group's existing assignments.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F4CB;</span><div><strong>One assignment per course+group</strong>. The UI blocks duplicates with a friendly error.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x23F2;</span><div>Deadline is optional &mdash; leave it blank for open-ended training.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F4D6;</span><div>Use the <strong>Title</strong> field to rename a course on upload &mdash; the manifest title isn't always learner-friendly.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F441;</span><div>The <strong>eye icon</strong> on each progress row opens the full CMI dump for that learner's attempt.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F501;</span><div>Closing the player tab still saves &mdash; the <code>beforeunload</code> handler fires Commit + Finish automatically.</div></div>
                        <div class="lh-tip-card"><span class="lh-tip-icon">&#x1F3CC;</span><div>The Rustici <em>Golf Explained</em> samples are a free, well-formed SCORM smoke test &mdash; useful if you're not sure whether a problem is the player or the package.</div></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.lh-nav-link');
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
