<?php
/**
 * RFP Builder — analyst help page (Phase 6 step 6b).
 *
 * In-app guide covering the six-phase workflow, key concepts (lock
 * gate, multi-analyst scoring, hash-skip, prompt caching), and the
 * cost / time expectations for each AI pass. Static — written for
 * FreeITSM's actual implementation, not lifted from the prototype.
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix  = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - RFP Builder help</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }
        .page-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span.sep { margin: 0 6px; color: #ccc; }

        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #222; }
        .page-header { margin-bottom: 20px; }

        .help-layout {
            display: grid; grid-template-columns: 220px 1fr;
            gap: 24px;
        }
        .help-sidebar {
            background: white; border-radius: 10px; padding: 12px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: sticky; top: 16px; align-self: start;
            max-height: calc(100vh - 80px); overflow-y: auto;
        }
        .help-sidebar .sidebar-title {
            font-size: 11px; color: #999; text-transform: uppercase;
            letter-spacing: 0.5px; padding: 12px 16px 6px; font-weight: 600;
        }
        .help-sidebar a {
            display: block; padding: 7px 16px; font-size: 13px;
            color: #555; text-decoration: none;
            border-left: 3px solid transparent;
        }
        .help-sidebar a:hover { color: #f59e0b; background: #fffbeb; }

        .help-main { display: flex; flex-direction: column; gap: 18px; }
        .help-card {
            background: white; border-radius: 10px; padding: 22px 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            scroll-margin-top: 16px;
        }
        .help-card h2 {
            margin: 0 0 12px 0; font-size: 18px; color: #222;
            display: flex; align-items: center; gap: 12px;
        }
        .help-card h2 .step-num {
            background: #f59e0b; color: white;
            width: 28px; height: 28px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700; flex-shrink: 0;
        }
        .help-card h3 {
            font-size: 14px; color: #92400e; margin: 16px 0 6px 0;
            font-weight: 700;
        }
        .help-card p, .help-card li {
            font-size: 14px; line-height: 1.65; color: #444;
        }
        .help-card p { margin: 0 0 10px 0; }
        .help-card ul, .help-card ol { margin: 8px 0 14px 22px; }
        .help-card li { margin-bottom: 4px; }
        .help-card code {
            background: #f3f4f6; padding: 1px 5px; border-radius: 3px;
            font-size: 13px; color: #1f2937;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .help-card .tip {
            background: #ecfdf5; border-left: 4px solid #10b981;
            padding: 10px 14px; border-radius: 4px;
            margin: 12px 0; font-size: 13px; color: #065f46;
        }
        .help-card .warn {
            background: #fff7ed; border-left: 4px solid #f59e0b;
            padding: 10px 14px; border-radius: 4px;
            margin: 12px 0; font-size: 13px; color: #92400e;
        }
        .help-card .gotcha {
            background: #fef2f2; border-left: 4px solid #ef4444;
            padding: 10px 14px; border-radius: 4px;
            margin: 12px 0; font-size: 13px; color: #991b1b;
        }

        .workflow-strip {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px;
            margin: 16px 0;
        }
        .workflow-strip .step {
            background: #fafbfc; border: 1px solid #eef0f2; border-radius: 6px;
            padding: 10px 12px; text-align: center;
            font-size: 12px; color: #374151;
        }
        .workflow-strip .step strong {
            display: block; color: #f59e0b; font-size: 16px; margin-bottom: 4px;
        }

        .cost-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 8px 0; }
        .cost-table th, .cost-table td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; text-align: left; }
        .cost-table th { background: #fafbfc; color: #555; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
        .cost-table tbody tr:last-child td { border-bottom: none; }
        .cost-table .num { text-align: right; font-variant-numeric: tabular-nums; }

        @media (max-width: 900px) {
            .help-layout { grid-template-columns: 1fr; }
            .help-sidebar { position: static; max-height: none; }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <span>Help</span>
        </div>
        <div class="page-header">
            <h1>RFP Builder — user guide</h1>
        </div>

        <div class="help-layout">
            <nav class="help-sidebar">
                <div class="sidebar-title">Getting started</div>
                <a href="#overview">Overview</a>
                <a href="#workflow">Six-phase workflow</a>
                <a href="#cost">Cost &amp; time expectations</a>

                <div class="sidebar-title">The phases</div>
                <a href="#p1">1. Source documents</a>
                <a href="#p2">2. Extract requirements</a>
                <a href="#p3">3. Consolidate</a>
                <a href="#p4">4. Generate document</a>
                <a href="#p5">5. Suppliers &amp; scoring</a>
                <a href="#p6">6. Compare &amp; decide</a>

                <div class="sidebar-title">Concepts</div>
                <a href="#lock">The lock gate</a>
                <a href="#multi-analyst">Multi-analyst scoring</a>
                <a href="#caching">Prompt caching</a>
                <a href="#audit">Audit trail</a>

                <div class="sidebar-title">Reference</div>
                <a href="#faq">FAQ</a>
            </nav>

            <main class="help-main">

                <div class="help-card" id="overview">
                    <h2>Overview</h2>
                    <p>
                        The RFP Builder takes departmental feedback documents (one per contributing department), uses AI to extract every individual requirement, deduplicates and categorises them, drafts a professional RFP document you can send to suppliers, and then scores supplier responses requirement-by-requirement to drive a decision.
                    </p>
                    <p>
                        The tool is <strong>internal-only</strong>. Suppliers never log in — you share the generated PDF with them via your usual procurement channel (email, sourcing platform, etc.) and they reply outside FreeITSM. You enter their scores yourself based on reading their responses.
                    </p>
                    <div class="tip">
                        <strong>The differentiating step is consolidation.</strong> Five departments asking for "the same thing" rarely use the same words — Pass 2 (consolidation) merges those duplicates while preserving every original quote for political cover, and flags genuine contradictions between departments for you to resolve.
                    </div>
                </div>

                <div class="help-card" id="workflow">
                    <h2>Six-phase workflow</h2>
                    <div class="workflow-strip">
                        <div class="step"><strong>1</strong>Source docs</div>
                        <div class="step"><strong>2</strong>Extract</div>
                        <div class="step"><strong>3</strong>Consolidate</div>
                        <div class="step"><strong>4</strong>Generate</div>
                        <div class="step"><strong>5</strong>Score</div>
                        <div class="step"><strong>6</strong>Compare</div>
                    </div>
                    <p>Each phase tile on an RFP's overview page lights up amber once it's ready to use, so the natural reading order tells you what to do next.</p>
                </div>

                <div class="help-card" id="cost">
                    <h2>Cost &amp; time expectations</h2>
                    <p>Approximate AI cost per RFP run, assuming Claude Sonnet pricing:</p>
                    <table class="cost-table">
                        <thead><tr><th>Pass</th><th>Per-call time</th><th class="num">Tokens (in/out)</th><th class="num">Approx cost</th></tr></thead>
                        <tbody>
                            <tr><td>Pass 1 — Extract (×N docs)</td><td>30–60s</td><td class="num">~2k / 4k each</td><td class="num">£0.05–0.10 each</td></tr>
                            <tr><td>Pass 2 — Consolidate (×1)</td><td>60–180s</td><td class="num">~6k / 12k</td><td class="num">£0.20–0.40</td></tr>
                            <tr><td>Pass 3 — Generate (×N categories)</td><td>30–90s each</td><td class="num">~2k / 5k each</td><td class="num">£0.05–0.10 each</td></tr>
                            <tr><td>Pass 4 — Restyle (per section)</td><td>15–40s</td><td class="num">~2k / 4k</td><td class="num">£0.04–0.08</td></tr>
                            <tr><td>Framing — Intro/Scope/Resp.</td><td>15–40s each</td><td class="num">~1.5k / 2k each</td><td class="num">£0.03–0.05 each</td></tr>
                        </tbody>
                    </table>
                    <p>Whole-RFP run end-to-end: typically <strong>£1–3</strong>. Prompt caching (see below) cuts that significantly on repeat work in the same RFP.</p>
                </div>

                <!-- Phases -->

                <div class="help-card" id="p1">
                    <h2><span class="step-num">1</span>Source documents</h2>
                    <p>Upload one .docx per contributing department. Each document gets tagged with the department it came from (use the <a href="../settings/index.php">Departments lookup</a> in Contracts settings to manage the list). Maximum 20 MB each.</p>
                    <p>Documents can be free-form: existing requirement spreadsheets, meeting notes, RFI responses, or just the email body where someone said "what we really need is…". The extraction pass handles all of these.</p>
                    <div class="tip">Tag documents accurately — department attribution is preserved through every later step, including the eventual RFP document, so suppliers know which areas of the business asked for what.</div>
                </div>

                <div class="help-card" id="p2">
                    <h2><span class="step-num">2</span>Extract requirements (Pass 1)</h2>
                    <p>For each uploaded document, click <strong>Extract requirements</strong>. The AI reads the document and returns a structured list of items, each tagged with:</p>
                    <ul>
                        <li><strong>Type:</strong> requirement / pain point / challenge</li>
                        <li><strong>Source quote:</strong> verbatim excerpt from the source document</li>
                        <li><strong>Confidence:</strong> 0.0–1.0 (how sure the AI is the item is distinct and worth keeping)</li>
                    </ul>
                    <p>Review the extracted requirements at <em>Extracted</em> on the overview. Edit, delete, or filter by department / type as needed.</p>
                </div>

                <div class="help-card" id="p3">
                    <h2><span class="step-num">3</span>Consolidate (Pass 2)</h2>
                    <p>The differentiating step. One AI call takes every extracted item across every department and produces three things in one shot:</p>
                    <ul>
                        <li><strong>Categories:</strong> 8–20 RFP categories the AI proposes based on the actual content (specific to this RFP, not generic).</li>
                        <li><strong>Consolidated requirements:</strong> deduplicated across departments, with priority (critical / high / medium / low), category assignment, and a list of source extracted IDs preserving the multi-department traceability.</li>
                        <li><strong>Conflicts:</strong> pairs of consolidated requirements that contradict each other — e.g. "must run on-prem" vs "must be cloud-only" — flagged with an AI explanation of why they conflict.</li>
                    </ul>
                    <h3>Editing tools</h3>
                    <p>Hover any consolidated row for <strong>Edit / Split / Delete</strong>. Tick checkboxes on multiple rows to <strong>Merge</strong>. <strong>+ Add custom</strong> at the top adds a row the AI missed entirely. Splits and merges preserve source attribution.</p>
                    <h3>Conflict resolution</h3>
                    <p>For each open conflict: <strong>Choose A</strong>, <strong>Choose B</strong>, <strong>Merge into one</strong>, or <strong>Dismiss</strong>. Resolved conflicts move to a "Resolved" subsection with notes; re-open at any time.</p>
                    <h3>Lock for generation</h3>
                    <p>Once you're happy, click <strong>Lock for generation</strong>. This freezes the consolidation set and unlocks Phase 4. While locked, all editing tools are hidden — see <a href="#lock">the lock gate</a>.</p>
                </div>

                <div class="help-card" id="p4">
                    <h2><span class="step-num">4</span>Generate document</h2>
                    <p>The Generate page produces:</p>
                    <ul>
                        <li><strong>Framing:</strong> Introduction, Scope, and Response Instructions sections that sit above the body.</li>
                        <li><strong>Body:</strong> One section per category, each written from the consolidated requirements with department attribution preserved.</li>
                    </ul>
                    <p>Use <strong>Generate all</strong> to produce everything at once, or per-row buttons for individual sections. Each section is editable in a WYSIWYG editor (TinyMCE) — see the <strong>Edit</strong> button on each card. <strong>Restyle</strong> re-applies the style guide without changing meaning. <strong>History</strong> (after the first regen) lets you view and restore prior versions.</p>
                    <h3>Procurement context</h3>
                    <p>Click <strong>Set context</strong> on the document framing panel to give the AI a short note about why the procurement is happening — e.g. "We're replacing our legacy ITSM tool which is end-of-life". The AI uses this to ground the introduction.</p>
                    <h3>Preview &amp; export</h3>
                    <p><strong>Preview document</strong> in the page header opens a clean single-page rendered view. Use Ctrl+P / Cmd+P to print or save as PDF — that's the artifact you share with suppliers.</p>
                </div>

                <div class="help-card" id="p5">
                    <h2><span class="step-num">5</span>Suppliers &amp; scoring</h2>
                    <p>Add suppliers to the shortlist via the Suppliers page — pick from existing FreeITSM suppliers or create a new prospective one inline. Track demo dates and per-RFP notes per supplier.</p>
                    <p><strong>Score</strong> on each supplier opens the scoring page. Click the score boxes 0–5 (red→green gradient) for each consolidated requirement, add notes for evidence, and watch the running averages update live in the left-hand "Score by category" panel and the bottom bar. The <strong>Spider</strong> button opens a full-screen radar chart of your category averages.</p>
                    <div class="tip">Multiple analysts can score the same supplier independently. The tool rolls those up — see <a href="#multi-analyst">multi-analyst scoring</a>.</div>
                </div>

                <div class="help-card" id="p6">
                    <h2><span class="step-num">6</span>Compare &amp; decide</h2>
                    <p>The Compare page is the decision-making view. Three sections:</p>
                    <ul>
                        <li><strong>Big-number cards:</strong> every supplier ranked by overall score, gold/silver/bronze ribbons for the top three.</li>
                        <li><strong>Multi-supplier radar:</strong> all suppliers overlaid on one set of category spokes, each in their own colour.</li>
                        <li><strong>Category winners:</strong> table marking the leader per category and the gap to second place. Ties marked explicitly.</li>
                    </ul>
                </div>

                <!-- Concepts -->

                <div class="help-card" id="lock">
                    <h2>The lock gate</h2>
                    <p>Phase 4 (generation) and Phase 5 (scoring) both require the consolidation set to be <strong>fully locked</strong> first. This stops the inputs from drifting underneath section generation and scoring — if a category gets renamed or a requirement gets edited mid-flight, your generated sections and your scores would no longer reflect what's there.</p>
                    <p>While locked: editing tools on the consolidate page disappear, and a green banner explains. Click <strong>Unlock</strong> to revert. Generated sections, manual edits, and scores all survive an unlock — they just become editable again.</p>
                    <div class="warn">If you edit consolidation after generation has run, regenerate the affected sections to keep the document in sync. Manually-edited sections are protected from "Generate all" hash-skip — only an explicit Re-generate will overwrite them.</div>
                </div>

                <div class="help-card" id="multi-analyst">
                    <h2>Multi-analyst scoring</h2>
                    <p>Each analyst's scores are recorded independently per (rfp, supplier, requirement) tuple. The aggregate shown on the scoring page and on the Compare page is computed in three steps:</p>
                    <ol>
                        <li>Per (supplier, requirement): mean across analysts who scored.</li>
                        <li>Per (supplier, category): mean of those per-requirement means.</li>
                        <li>Overall per supplier: mean of all per-requirement means.</li>
                    </ol>
                    <p>Peer review stays single-blind: you can see the count and average from other analysts on each requirement, but not their individual scores or names. Calibrate without being unduly influenced.</p>
                </div>

                <div class="help-card" id="caching">
                    <h2>Prompt caching</h2>
                    <p>Anthropic supports prompt caching: identical prefixes across calls within a 5-minute window get served at ~10% of normal input cost. The RFP Builder caches the system prompt + style guide on every Pass 2 / Pass 3 / Pass 4 / framing call.</p>
                    <p>Real-world impact: generating 14 sections in one batch goes from "14 full-price calls" to "1 cache write + 13 cache reads", roughly a 5× cost reduction. Visible in the AI activity panel as the "Cached input" stat — the percentage shown is across the recent rows.</p>
                    <div class="tip">Pass 1 (extraction) currently has too short a system prompt to cache (Anthropic's minimum cacheable prefix is 1024 tokens). The marker is in place for future expansion. The big spends — consolidation and generation — both cache fine.</div>
                </div>

                <div class="help-card" id="audit">
                    <h2>Audit trail</h2>
                    <p>Every AI call (Pass 1 through Pass 4) is logged to <code>rfp_processing_log</code> with action, status, target, tokens (in / out / cached), call duration, and the model used. The AI activity panel on the RFP overview shows the last 25 entries and stats; click <strong>View full audit trail</strong> there to see everything in a filterable table.</p>
                </div>

                <!-- FAQ -->

                <div class="help-card" id="faq">
                    <h2>FAQ</h2>

                    <h3>Can I run consolidation on partial extraction?</h3>
                    <p>Yes — consolidation considers every extracted requirement that exists at the moment you click Run. If you've extracted 4 of 5 documents, it'll consolidate those 4. Add the 5th later and re-run if needed; the prior consolidation gets discarded entirely (it's not incremental).</p>

                    <h3>What happens if I re-run consolidation after editing?</h3>
                    <p>The wipe-and-replace transactional save discards all prior categories, consolidated rows, source links, and conflicts, then inserts the fresh AI output. <strong>Manual edits to consolidated rows are lost.</strong> If you've spent time editing, lock instead of re-running.</p>

                    <h3>Can I have different style guides for different RFPs?</h3>
                    <p>Yes — set a per-RFP style guide on the RFP edit modal. The system-wide default at <a href="../settings/index.php">Contracts → Settings → RFP AI</a> is the fallback for any RFP without an override.</p>

                    <h3>What if the AI gets a category badly wrong?</h3>
                    <p>Edit the category name or description directly (categories aren't editable in 6.x — coming soon). Workaround: re-run consolidation after manually editing the input requirements to nudge the AI toward a better structure, or split / merge / re-categorise consolidated rows.</p>

                    <h3>Why doesn't extraction cache?</h3>
                    <p>Anthropic's prompt caching has a 1024-token minimum on the cached prefix. The Pass 1 system prompt is around 500 tokens — not enough. The other passes are well over the threshold and cache fine.</p>

                    <h3>Can I use OpenAI instead of Anthropic?</h3>
                    <p>For Pass 1 (extraction) and Pass 2 (consolidation, when not streaming): yes. For the streaming passes (consolidation streaming, Pass 3 generation, Pass 4 restyle, framing) the SSE format implementation is currently Anthropic-only. Switch under <a href="../settings/index.php">Contracts → Settings → RFP AI</a> if you need to.</p>
                </div>

            </main>
        </div>
    </div>
</body>
</html>
