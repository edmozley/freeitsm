<?php
/**
 * Problem Management Help Guide — full page with left-pane navigation.
 * Mirrors the Tickets help page (tickets/help.php) for a consistent look + nav.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/theme.php';
if (!isset($_SESSION['analyst_id'])) { header('Location: ' . BASE_URL . 'login.php'); exit; }
$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Problem Management Help</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=11">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=37">
    <style>
        .tk-help-container { display: flex; height: calc(100vh - 48px); background: var(--app-bg, #f5f5f5); }
        .tk-help-sidebar { width: 260px; background: var(--surface, #fff); border-right: 1px solid var(--border, #ddd); padding: 20px; display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; overflow-y: auto; }
        .tk-help-sidebar h3 { font-size: 12px; font-weight: 600; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: .5px; margin: 0 0 12px; }
        .tk-help-nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; font-size: 13px; color: var(--text-muted, #555); text-decoration: none; transition: background .15s, color .15s; }
        .tk-help-nav-link:hover { background: var(--surface-hover, #f5f5f5); color: var(--text, #333); }
        .tk-help-nav-link.active { background: var(--pm-accent-soft, #f3e5f5); color: var(--pm-accent, #6a1b9a); font-weight: 600; }
        .tk-help-nav-num { display: flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: 50%; background: var(--border-soft, #eee); color: var(--text-dim, #888); font-weight: 700; font-size: 11px; flex-shrink: 0; }
        .tk-help-nav-link.active .tk-help-nav-num { background: var(--pm-accent, #6a1b9a); color: var(--pm-on-accent, #fff); }
        .tk-help-main { flex: 1; overflow-y: auto; }
        .tk-help-hero { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 50%, #7f1d1d 100%); color: #fff; padding: 40px 48px 36px; text-align: center; }
        /* Darken the hero in dark mode so it recedes instead of glowing bright red. */
        [data-theme-mode="dark"] .tk-help-hero { background: linear-gradient(135deg, #4a1515 0%, #3a0f0f 50%, #2a0c0c 100%); }
        .tk-help-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .tk-help-hero p { margin: 0; font-size: 15px; opacity: .85; }
        .tk-help-content { padding: 10px 48px 48px; }
        .tk-help-section { padding: 28px 0; border-bottom: 1px solid var(--border-soft, #eee); scroll-margin-top: 20px; }
        .tk-help-section:last-child { border-bottom: none; padding-bottom: 0; }
        .tk-help-section-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
        .tk-help-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .tk-help-section-header p { margin: 6px 0 0; font-size: 14px; color: var(--text-muted, #666); line-height: 1.6; }
        .tk-help-section > p { font-size: 14px; color: var(--text-muted, #555); line-height: 1.7; margin: 0 0 14px; }
        .tk-help-section > ul { font-size: 14px; color: var(--text-muted, #555); line-height: 1.7; margin: 0 0 14px; padding-left: 22px; }
        .tk-help-section > ul li { margin-bottom: 7px; }
        .tk-help-section-num { display: flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; border-radius: 50%; background: var(--pm-accent-soft, #f3e5f5); color: var(--pm-accent, #6a1b9a); font-weight: 700; font-size: 14px; flex-shrink: 0; }
        .tk-help-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin: 6px 0 4px; }
        .tk-help-feature-card { padding: 20px; border-radius: 10px; border: 1px solid var(--border, #e0e0e0); background: var(--surface, #fff); }
        .tk-help-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: var(--text, #333); }
        .tk-help-feature-card p { margin: 0; font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; }
        .tk-help-flow { display: flex; align-items: center; gap: 0; margin: 14px 0; flex-wrap: wrap; }
        .tk-help-flow-step { display: flex; align-items: center; justify-content: center; padding: 9px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; text-align: center; background: var(--pm-accent-soft, #f3e5f5); color: var(--pm-accent, #6a1b9a); }
        .tk-help-flow-step.closed { background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); }
        .tk-help-flow-arrow { padding: 0 7px; color: var(--text-faint, #bbb); font-size: 16px; }
        .tk-help-tip { font-size: 13px !important; color: var(--pm-accent, #6a1b9a) !important; background: var(--pm-accent-soft, #f3e5f5); padding: 10px 14px; border-radius: 8px; border-left: 3px solid var(--pm-accent, #6a1b9a); margin: 4px 0 0; line-height: 1.6; }
        .tk-help-warn { font-size: 13px; color: var(--warning-text, #8a6d3b); background: var(--warning-bg, #fff8e1); padding: 10px 14px; border-radius: 8px; border-left: 3px solid var(--warning-border, #ffb300); margin: 4px 0 0; line-height: 1.6; }
        @media (max-width: 900px) { .tk-help-sidebar { display: none; } .tk-help-content { padding: 10px 24px 40px; } .tk-help-features-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="tk-help-container">
        <div class="tk-help-sidebar">
            <h3>On this page</h3>
            <a href="#overview" class="tk-help-nav-link active" data-section="overview"><span class="tk-help-nav-num">1</span> Overview</a>
            <a href="#vs-incidents" class="tk-help-nav-link" data-section="vs-incidents"><span class="tk-help-nav-num">2</span> Problems vs incidents</a>
            <a href="#lifecycle" class="tk-help-nav-link" data-section="lifecycle"><span class="tk-help-nav-num">3</span> The lifecycle</a>
            <a href="#rca" class="tk-help-nav-link" data-section="rca"><span class="tk-help-nav-num">4</span> Root cause analysis</a>
            <a href="#known-errors" class="tk-help-nav-link" data-section="known-errors"><span class="tk-help-nav-num">5</span> Known errors &amp; workarounds</a>
            <a href="#linking" class="tk-help-nav-link" data-section="linking"><span class="tk-help-nav-num">6</span> Linking incidents &amp; the fix</a>
            <a href="#proactive" class="tk-help-nav-link" data-section="proactive"><span class="tk-help-nav-num">7</span> Reactive vs proactive</a>
            <a href="#ai" class="tk-help-nav-link" data-section="ai"><span class="tk-help-nav-num">8</span> AI helpers</a>
            <a href="#good-practice" class="tk-help-nav-link" data-section="good-practice"><span class="tk-help-nav-num">9</span> Good practice</a>
        </div>

        <div class="tk-help-main" id="helpMain">
            <div class="tk-help-hero">
                <h2>Problem Management</h2>
                <p>Find and remove the root causes behind recurring incidents — the ITIL way.</p>
            </div>
            <div class="tk-help-content">

                <div class="tk-help-section" id="overview">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">1</span><div><h3>What Problem Management is for</h3><p>In ITIL, <strong>Problem Management</strong> is the practice of reducing the likelihood and impact of incidents by finding and dealing with their underlying causes.</p></div></div>
                    <p>An <em>incident</em> is an unplanned interruption — you restore service as fast as you can. A <em>problem</em> is the underlying cause of one or more incidents. Incident Management asks <strong>“how do we get this person working again?”</strong>; Problem Management asks <strong>“why does this keep happening, and how do we stop it for good?”</strong></p>
                    <p>The aim is not to fix one ticket faster — it's to make whole categories of tickets stop occurring. A well-run problem practice steadily lowers incident volume, shortens future outages (because workarounds are ready), and feeds permanent fixes into Change Management.</p>
                    <p class="tk-help-tip">💡 Rule of thumb: if you find yourself fixing “the same thing” for the third time, or a major incident has just been resolved, raise a problem.</p>
                </div>

                <div class="tk-help-section" id="vs-incidents">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">2</span><div><h3>Problems vs incidents — keep them separate</h3><p>A common mistake is to bury root-cause work inside an incident ticket. ITIL deliberately keeps the two records distinct.</p></div></div>
                    <div class="tk-help-features-grid">
                        <div class="tk-help-feature-card"><h4>Incident (a ticket)</h4><p>One user/service affected now. Goal: <strong>restore service</strong> quickly, even with a workaround. Measured by speed (response/resolution SLAs).</p></div>
                        <div class="tk-help-feature-card"><h4>Problem (this module)</h4><p>The cause behind one or more incidents. Goal: <strong>eliminate the cause</strong>. Measured by reduced recurrence, not speed. May stay open for weeks.</p></div>
                    </div>
                    <p style="margin-top:14px;">Keeping them separate means your service desk can keep closing incidents fast (applying the workaround) while the problem runs its own, slower investigation in the background. Link the incidents to the problem so the relationship is visible from both sides.</p>
                    <p><strong>When to raise a problem:</strong></p>
                    <ul>
                        <li><strong>Recurrence</strong> — the same issue has happened several times.</li>
                        <li><strong>Major incident</strong> — always hold a problem record after a significant outage, even if service is restored.</li>
                        <li><strong>Trend / proactive</strong> — analysis shows a cluster of related incidents (see <a href="#proactive">Reactive vs proactive</a>).</li>
                        <li><strong>No known cause</strong> — an incident was resolved by a restart/workaround but nobody knows <em>why</em> it broke.</li>
                    </ul>
                </div>

                <div class="tk-help-section" id="lifecycle">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">3</span><div><h3>The problem lifecycle</h3><p>The status flow follows the ITIL problem lifecycle. Move a problem along as the investigation progresses.</p></div></div>
                    <div class="tk-help-flow">
                        <div class="tk-help-flow-step">New</div><span class="tk-help-flow-arrow">→</span>
                        <div class="tk-help-flow-step">Investigating</div><span class="tk-help-flow-arrow">→</span>
                        <div class="tk-help-flow-step">Root Cause Identified</div><span class="tk-help-flow-arrow">→</span>
                        <div class="tk-help-flow-step">Known Error</div><span class="tk-help-flow-arrow">→</span>
                        <div class="tk-help-flow-step closed">Resolved</div><span class="tk-help-flow-arrow">→</span>
                        <div class="tk-help-flow-step closed">Closed</div>
                    </div>
                    <ul>
                        <li><strong>New</strong> — logged, not yet being worked. Give it a clear title and link the incidents that prompted it.</li>
                        <li><strong>Investigating</strong> — detection &amp; diagnosis under way; gather data from the linked incidents.</li>
                        <li><strong>Root Cause Identified</strong> — the underlying cause is understood and recorded in <em>Root cause</em>.</li>
                        <li><strong>Known Error</strong> — cause known <em>and</em> a workaround documented; the desk can now mitigate new incidents immediately (see next section).</li>
                        <li><strong>Resolved</strong> — a permanent fix is in place (usually via a Change). Service is no longer at risk from this cause.</li>
                        <li><strong>Closed</strong> — verified and reviewed; nothing more to do.</li>
                    </ul>
                    <p class="tk-help-tip">💡 You can rename, recolour, add or retire statuses under <strong>Settings → Statuses</strong> to match your own process — the six above are sensible ITIL-aligned defaults.</p>
                </div>

                <div class="tk-help-section" id="rca">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">4</span><div><h3>Root cause analysis (RCA)</h3><p>The heart of Problem Management. Resist jumping to a fix before you understand the cause — record your reasoning in the <strong>Root cause</strong> field.</p></div></div>
                    <p>There's no single “correct” technique; pick one that fits the problem. Common ITIL-recommended approaches:</p>
                    <div class="tk-help-features-grid">
                        <div class="tk-help-feature-card"><h4>5 Whys</h4><p>Ask “why?” repeatedly (about five times) until you reach a cause you can act on. Fast and good for straightforward issues.</p></div>
                        <div class="tk-help-feature-card"><h4>Ishikawa (fishbone)</h4><p>Brainstorm causes across categories (people, process, technology, data, environment). Good for complex or contested problems.</p></div>
                        <div class="tk-help-feature-card"><h4>Kepner-Tregoe</h4><p>Structured problem analysis — what <em>is</em> vs <em>is not</em> affected — to narrow down the true cause. Good for elusive faults.</p></div>
                        <div class="tk-help-feature-card"><h4>Timeline / chronology</h4><p>Reconstruct exactly what changed and when (deploys, config, load). Often the cause sits next to a recent change.</p></div>
                    </div>
                    <p style="margin-top:14px;">Whatever you use, write the conclusion plainly in the problem so the next person understands it without re-doing the work. The <strong>Draft root cause</strong> AI helper can give you a first hypothesis from the linked incidents (see <a href="#ai">AI helpers</a>) — treat it as a starting point, not gospel.</p>
                </div>

                <div class="tk-help-section" id="known-errors">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">5</span><div><h3>Known errors &amp; workarounds</h3><p>A <strong>known error</strong> is a problem with a documented root cause <em>and</em> a workaround. This is one of ITIL's most valuable ideas.</p></div></div>
                    <p>Once you have a workaround, tick <strong>Known error</strong> and write the workaround in the <strong>Workaround</strong> field. This turns the problem into a reference the whole service desk can use: when a matching incident comes in, an analyst applies the documented workaround and closes the incident in seconds — no re-diagnosis — while the permanent fix is still being arranged.</p>
                    <ul>
                        <li>Make workarounds <strong>specific and repeatable</strong> — exact steps, not “restart it”.</li>
                        <li>Record a known error <strong>even before</strong> the permanent fix; the value is immediate.</li>
                        <li>Review known errors periodically — once the permanent fix ships, resolve the problem so it stops being applied unnecessarily.</li>
                    </ul>
                    <p class="tk-help-tip">💡 Think of your set of known-error problems as a living knowledge base of “things that break and how to dodge them”.</p>
                </div>

                <div class="tk-help-section" id="linking">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">6</span><div><h3>Linking incidents and the permanent fix</h3><p>The chain <strong>incidents → problem → change</strong> is what makes the practice work end-to-end.</p></div></div>
                    <p><strong>Link the incidents</strong> the problem explains, from either side:</p>
                    <ul>
                        <li>On a <strong>ticket</strong>, use the <em>Problem</em> strip in the reading pane — link an existing problem, or raise a new one straight from the incident.</li>
                        <li>On a <strong>problem</strong>, use <em>Link incident</em> and enter the ticket number.</li>
                    </ul>
                    <p>Linking lets you see how widespread a problem is, and gives RCA real evidence to work from. (On a multi-company install, an incident and a problem must belong to the same company.)</p>
                    <p><strong>Link the fix.</strong> A permanent fix is almost always a change to the live environment — so raise it in <a href="<?php echo BASE_URL; ?>change-management/">Change Management</a> and use <em>Link change</em> on the problem to connect it. That keeps the audit trail intact: the incidents that hurt, the problem that explained them, and the change that fixed them. After the change is implemented, confirm the incidents have stopped, then move the problem to <strong>Resolved</strong>.</p>
                    <p class="tk-help-warn">⚠ Don't close a problem the moment the change is deployed — wait until you're confident the incidents have actually stopped recurring. Closing too early is the classic way problems quietly come back.</p>
                </div>

                <div class="tk-help-section" id="proactive">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">7</span><div><h3>Reactive vs proactive problem management</h3><p>ITIL describes two modes — do both.</p></div></div>
                    <div class="tk-help-features-grid">
                        <div class="tk-help-feature-card"><h4>Reactive</h4><p>Triggered by incidents that have already happened — especially recurring ones or a major incident review. You group those incidents under a problem and investigate.</p></div>
                        <div class="tk-help-feature-card"><h4>Proactive</h4><p>Spotting trouble <em>before</em> it becomes a major incident — analysing incident trends to find patterns worth a problem record now.</p></div>
                    </div>
                    <p style="margin-top:14px;">The <strong>Detect problems</strong> button (on the Problems list) is your proactive helper: it scans recent open incidents in the active company and suggests groups that likely share a cause, so you catch a brewing problem early. Build a habit of running it — and reviewing major-incident reviews — on a regular cadence (e.g. weekly).</p>
                </div>

                <div class="tk-help-section" id="ai">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">8</span><div><h3>AI helpers</h3><p>Two optional assistants. Both use the <strong>Problem AI</strong> key set under Settings (bring your own provider/key).</p></div></div>
                    <ul>
                        <li><strong>Draft root cause</strong> (on a problem) — reads the linked incidents and proposes a likely root cause and workaround for you to review, edit and save. Nothing is saved automatically.</li>
                        <li><strong>Detect problems</strong> (on the list) — scans recent open incidents for recurring patterns and proposes candidate problems; you confirm a suggestion to create the problem and link those incidents in one step.</li>
                    </ul>
                    <p class="tk-help-warn">⚠ AI suggestions are a head start, not a verdict. Always apply your own judgement — confirm the root cause with real evidence before acting on it.</p>
                </div>

                <div class="tk-help-section" id="good-practice">
                    <div class="tk-help-section-header"><span class="tk-help-section-num">9</span><div><h3>Good practice checklist</h3><p>Habits that keep a problem practice healthy.</p></div></div>
                    <ul>
                        <li><strong>Raise problems for recurring and major incidents</strong> — make it routine, not exceptional.</li>
                        <li><strong>Always link the incidents</strong> — a problem with no linked incidents has no evidence and no measurable impact.</li>
                        <li><strong>Document the workaround early</strong> and mark the known error so the desk benefits straight away.</li>
                        <li><strong>Write the root cause for a human</strong> — the next analyst should understand it without redoing the analysis.</li>
                        <li><strong>Drive the permanent fix through Change</strong>, and link it, so the whole story is traceable.</li>
                        <li><strong>Don't close prematurely</strong> — verify recurrence has stopped first.</li>
                        <li><strong>Review proactively</strong> — run Detect problems and review trends on a regular cadence.</li>
                        <li><strong>Watch the numbers</strong> — falling repeat-incident volume is the real sign the practice is working.</li>
                    </ul>
                    <p style="margin-top:18px;"><a href="<?php echo BASE_URL; ?>problem-management/" style="color:var(--pm-accent,#6a1b9a);font-weight:600;text-decoration:none;">← Back to Problems</a></p>
                </div>

            </div>
        </div>
    </div>

    <script>
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.tk-help-nav-link');
        const sections = [];
        navLinks.forEach(link => { const el = document.getElementById(link.dataset.section); if (el) sections.push({ id: link.dataset.section, el }); });
        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0] && sections[0].id;
            for (const s of sections) { if (s.el.offsetTop - 200 <= scrollTop) current = s.id; }
            navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === current));
        });
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
