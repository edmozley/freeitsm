<?php
/**
 * Change Management Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Change Management Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/change-management.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="cm-help-container">
        <!-- Left pane navigation -->
        <div class="cm-help-sidebar">
            <h3>Guide</h3>
            <a href="#what-is-a-change" class="cm-help-nav-link active" data-section="what-is-a-change">
                <span class="cm-help-nav-num">1</span>
                What is a Change?
            </a>
            <a href="#change-types" class="cm-help-nav-link" data-section="change-types">
                <span class="cm-help-nav-num">2</span>
                Change Types
            </a>
            <a href="#lifecycle" class="cm-help-nav-link" data-section="lifecycle">
                <span class="cm-help-nav-num">3</span>
                The Change Lifecycle
            </a>
            <a href="#recording" class="cm-help-nav-link" data-section="recording">
                <span class="cm-help-nav-num">4</span>
                Recording a Change
            </a>
            <a href="#cab" class="cm-help-nav-link cab" data-section="cab">
                <span class="cm-help-nav-num cab">5</span>
                CAB Review
            </a>
            <a href="#risk" class="cm-help-nav-link" data-section="risk">
                <span class="cm-help-nav-num">6</span>
                Risk Assessment
            </a>
            <a href="#pir" class="cm-help-nav-link" data-section="pir">
                <span class="cm-help-nav-num">7</span>
                Post-Implementation Review
            </a>
            <a href="#tips" class="cm-help-nav-link" data-section="tips">
                <span class="cm-help-nav-num">8</span>
                Quick Tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="cm-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="cm-help-hero">
                <h2>Change Management Guide</h2>
                <p>Everything you need to know about recording, reviewing, and approving changes.</p>
            </div>

            <div class="cm-help-content">

                <!-- Section 1: What is a Change? -->
                <div class="cm-help-section" id="what-is-a-change">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">1</span>
                        <div>
                            <h3>What is a Change?</h3>
                            <p>A change is any planned modification to your IT environment &mdash; installing software, upgrading a server, changing a firewall rule, deploying new hardware. Recording changes ensures nothing is done without proper planning, approval, and a rollback plan if things go wrong.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Change Types -->
                <div class="cm-help-section" id="change-types">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">2</span>
                        <h3>Change Types</h3>
                    </div>
                    <div class="cm-help-types-grid">
                        <div class="cm-help-type-card standard">
                            <div class="cm-help-type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <h4>Standard</h4>
                            <p>Pre-approved, low-risk changes that follow a well-tested procedure. Think monthly patching or routine maintenance. No CAB review needed.</p>
                        </div>
                        <div class="cm-help-type-card normal">
                            <div class="cm-help-type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4>Normal</h4>
                            <p>The most common type. Requires planning, risk assessment, and usually approval from one or more people. May need CAB review for higher-impact changes.</p>
                        </div>
                        <div class="cm-help-type-card emergency">
                            <div class="cm-help-type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h4>Emergency</h4>
                            <p>For urgent fixes that can't wait &mdash; like a critical security patch or a production outage. Expedited approval, but still documented after the fact.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 3: The Change Lifecycle -->
                <div class="cm-help-section" id="lifecycle">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">3</span>
                        <h3>The Change Lifecycle</h3>
                    </div>
                    <div class="cm-help-lifecycle">
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge draft">Draft</div>
                            <div class="cm-help-step-desc">
                                <strong>Create &amp; plan</strong>
                                <span>Fill in the details: what, why, who, when. Add a risk assessment, test plan, and rollback plan.</span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge pending-approval">Pending Approval</div>
                            <div class="cm-help-step-desc">
                                <strong>Submit for review</strong>
                                <span>Change the status to Pending Approval. If CAB is enabled, reviewers are notified to vote.</span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge approved">Approved</div>
                            <div class="cm-help-step-desc">
                                <strong>Green light</strong>
                                <span>Approver or CAB signs off. The change is authorised to proceed during its scheduled window.</span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge in-progress">In Progress</div>
                            <div class="cm-help-step-desc">
                                <strong>Implementation</strong>
                                <span>The work is underway. Update the status when you start. Add comments to log progress.</span>
                            </div>
                        </div>
                        <div class="cm-help-lifecycle-arrow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        </div>
                        <div class="cm-help-lifecycle-step">
                            <div class="cm-help-step-badge completed">Completed</div>
                            <div class="cm-help-step-desc">
                                <strong>Done!</strong>
                                <span>Change is finished. Fill in the Post-Implementation Review: was it successful? Lessons learned?</span>
                            </div>
                        </div>
                    </div>
                    <div class="cm-help-lifecycle-alt">
                        <span>A change can also be marked <span class="cm-help-step-badge-inline failed">Failed</span> if it didn't work (trigger the rollback plan) or <span class="cm-help-step-badge-inline cancelled">Cancelled</span> if it's no longer needed.</span>
                    </div>
                </div>

                <!-- Section 4: Recording a Change -->
                <div class="cm-help-section" id="recording">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">4</span>
                        <h3>Recording a Change &mdash; Step by Step</h3>
                    </div>
                    <div class="cm-help-steps">
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">1</div>
                            <div>
                                <strong>Click "+ New Change"</strong> in the sidebar to open the editor.
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">2</div>
                            <div>
                                <strong>Fill in General Info</strong> &mdash; title, type (Standard/Normal/Emergency), priority, and impact.
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">3</div>
                            <div>
                                <strong>Assign people</strong> &mdash; requester (who wants the change), assigned to (who's doing it), and approver.
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">4</div>
                            <div>
                                <strong>Set the schedule</strong> &mdash; when the work window starts/ends and any expected outage times.
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">5</div>
                            <div>
                                <strong>Assess risk</strong> &mdash; pick Likelihood (1&ndash;5) and Impact (1&ndash;5). The risk score auto-calculates. A colour-coded matrix shows where it falls.
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">6</div>
                            <div>
                                <strong>Write the plans</strong> &mdash; use the tabs to fill in Description, Reason, Risk Evaluation, Test Plan, and Rollback Plan.
                            </div>
                        </div>
                        <div class="cm-help-step-item">
                            <div class="cm-help-step-num">7</div>
                            <div>
                                <strong>Save</strong> as Draft. You can come back and edit anytime before submitting for approval.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 5: CAB -->
                <div class="cm-help-section cm-help-section-highlight" id="cab">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num cab">5</span>
                        <h3>CAB Review &mdash; When You Need Multiple Approvers</h3>
                    </div>
                    <p class="cm-help-intro">For higher-risk or higher-impact changes, a single approver may not be enough. The <strong>Change Advisory Board (CAB)</strong> lets you assemble a panel of reviewers who each vote on the change.</p>

                    <div class="cm-help-cab-flow">
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon setup">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4>Set Up the Board</h4>
                            <p>In the editor, tick <strong>"Require CAB review"</strong>. Choose your approval type and add members from the analyst list.</p>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon choose">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                            </div>
                            <h4>Choose Approval Type</h4>
                            <div class="cm-help-approval-types">
                                <div class="cm-help-approval-type">
                                    <span class="cm-help-approval-label all">All must approve</span>
                                    <span>Every <em>required</em> member must vote Approve. One rejection sends it back to Draft.</span>
                                </div>
                                <div class="cm-help-approval-type">
                                    <span class="cm-help-approval-label majority">Majority</span>
                                    <span>More than half of required members must vote Approve. One rejection still sends it back.</span>
                                </div>
                            </div>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon members">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                            </div>
                            <h4>Required vs Optional Members</h4>
                            <p>Each member can be toggled between <strong>Required</strong> and <strong>Optional</strong>. Only required members' votes count toward the threshold. Optional members can provide input but their vote isn't mandatory.</p>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon vote">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>
                            </div>
                            <h4>The Voting Process</h4>
                            <p>When the change moves to <strong>Pending Approval</strong>, each CAB member sees a voting panel on the change detail page with three options:</p>
                            <div class="cm-help-vote-options">
                                <span class="cm-help-vote approve">Approve</span>
                                <span class="cm-help-vote reject">Reject</span>
                                <span class="cm-help-vote abstain">Abstain</span>
                            </div>
                            <p>Members can also add a comment explaining their vote.</p>
                        </div>
                        <div class="cm-help-cab-step">
                            <div class="cm-help-cab-step-icon auto">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline></svg>
                            </div>
                            <h4>Automatic Status Changes</h4>
                            <div class="cm-help-auto-rules">
                                <div class="cm-help-auto-rule approve">
                                    <span class="cm-help-auto-arrow">&#10003;</span>
                                    <div>
                                        <strong>Threshold met</strong>
                                        <span>Status automatically moves to <strong>Approved</strong></span>
                                    </div>
                                </div>
                                <div class="cm-help-auto-rule reject">
                                    <span class="cm-help-auto-arrow">&#10007;</span>
                                    <div>
                                        <strong>Any required member rejects</strong>
                                        <span>Status reverts to <strong>Draft</strong> so you can revise and resubmit</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 6: Risk Matrix -->
                <div class="cm-help-section" id="risk">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">6</span>
                        <h3>Risk Assessment</h3>
                    </div>
                    <p>Every change should be assessed for risk. Pick a <strong>Likelihood</strong> (1&ndash;5) and <strong>Impact</strong> (1&ndash;5) score. They multiply to give a risk level:</p>
                    <div class="cm-help-risk-scale">
                        <div class="cm-help-risk-level low"><span>1&ndash;4</span> Low</div>
                        <div class="cm-help-risk-level medium"><span>5&ndash;9</span> Medium</div>
                        <div class="cm-help-risk-level high"><span>10&ndash;15</span> High</div>
                        <div class="cm-help-risk-level very-high"><span>16&ndash;20</span> Very High</div>
                        <div class="cm-help-risk-level critical"><span>21&ndash;25</span> Critical</div>
                    </div>
                    <p class="cm-help-tip">Higher risk changes should generally go through CAB review and have a detailed rollback plan.</p>
                </div>

                <!-- Section 7: PIR -->
                <div class="cm-help-section" id="pir">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">7</span>
                        <h3>Post-Implementation Review</h3>
                    </div>
                    <p>When a change is marked <strong>Completed</strong> or <strong>Failed</strong>, a PIR section appears in the editor. This is your chance to record what actually happened:</p>
                    <div class="cm-help-pir-fields">
                        <div><strong>Was it successful?</strong> &mdash; Yes or No</div>
                        <div><strong>Actual start / end</strong> &mdash; Did it run to schedule?</div>
                        <div><strong>Lessons learned</strong> &mdash; What went well? What didn't?</div>
                        <div><strong>Follow-up actions</strong> &mdash; Anything that needs doing next?</div>
                    </div>
                    <p class="cm-help-tip">PIR is how teams learn and improve. Even successful changes often have useful insights.</p>
                </div>

                <!-- Section 8: Quick tips -->
                <div class="cm-help-section" id="tips">
                    <div class="cm-help-section-header">
                        <span class="cm-help-section-num">8</span>
                        <h3>Quick Tips</h3>
                    </div>
                    <div class="cm-help-tips-grid">
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128197;</div>
                            <div><strong>Calendar</strong><br>All scheduled changes appear on the Calendar view so the team can spot clashes.</div>
                        </div>
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128172;</div>
                            <div><strong>Comments</strong><br>Use the Activity section to discuss changes. Everything is logged with timestamps.</div>
                        </div>
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128206;</div>
                            <div><strong>Attachments</strong><br>Upload screenshots, config backups, or approval emails alongside the change.</div>
                        </div>
                        <div class="cm-help-tip-card">
                            <div class="cm-help-tip-icon">&#128269;</div>
                            <div><strong>Audit Trail</strong><br>Every field change is automatically tracked. See who changed what and when in the Activity timeline.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.cm-help-nav-link');
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
                // offset by hero height + some padding
                if (s.el.offsetTop - 200 <= scrollTop) {
                    current = s.id;
                }
            }

            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Smooth scroll to section on click
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.dataset.section;
                const el = document.getElementById(id);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
