<?php
/**
 * Workflows — Webhooks deep-dive help page.
 *
 * A standalone, beginner-to-expert guide to outbound webhooks: what they are,
 * how they slot into the Workflows module, the four payload formats (incl. the
 * Full-record delighter), testing, HMAC signing, the async delivery engine, the
 * overview dashboard, the event catalogue, and troubleshooting.
 *
 * Linked from workflow/help.php (Actions section + sidebar). English-first — the
 * i18n rollout backfills the other locales later, same as any new content.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) { header('Location: ../login.php'); exit; }

requireModuleAccess('workflow');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhooks guide</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* Same shape as workflow/help.php */
        .wfh-container { display: flex; height: calc(100vh - 48px); background: var(--surface-3, #f5f5f5); }
        .wfh-sidebar { width: 260px; background: var(--surface, #fff); border-right: 1px solid var(--border, #ddd); padding: 20px; display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; overflow-y: auto; }
        .wfh-sidebar h3 { font-size: 12px; font-weight: 600; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px; }
        .wfh-nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; font-size: 13px; color: var(--text-muted, #555); text-decoration: none; transition: background 0.15s, color 0.15s; }
        .wfh-nav-link:hover { background: var(--surface-3, #f5f5f5); color: var(--text, #333); }
        .wfh-nav-link.active { background: var(--wf-accent-soft, #fff7ed); color: var(--warning-text, #b45309); font-weight: 600; }
        .wfh-nav-num { display: flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 50%; background: var(--surface-3, #f5f5f5); color: var(--text-dim, #888); font-size: 11px; font-weight: 700; }
        .wfh-nav-link.active .wfh-nav-num { background: var(--wf-accent, #f59e0b); color: white; }
        .wfh-back { display: flex; align-items: center; gap: 8px; padding: 8px 12px; margin-bottom: 8px; font-size: 12.5px; color: var(--text-muted, #6b7280); text-decoration: none; border-radius: 6px; }
        .wfh-back:hover { background: var(--surface-3, #f5f5f5); color: var(--text, #374151); }

        .wfh-main { flex: 1; overflow-y: auto; padding: 24px 32px 60px; }
        .wf-help h2 { font-size: 22px; color: var(--text, #333); margin: 0 0 4px; }
        .wf-help p { color: var(--text-muted, #555); line-height: 1.6; }
        .wf-help .lede { font-size: 15px; color: var(--text, #444); }
        .wf-help h3 { margin: 34px 0 10px; font-size: 16px; color: var(--text, #333); padding-bottom: 6px; border-bottom: 1px solid var(--border-soft, #eee); scroll-margin-top: 20px; }
        .wf-help h3:first-of-type { margin-top: 22px; }
        .wf-help h4 { margin: 22px 0 6px; font-size: 14px; color: var(--text, #444); }
        .wf-help ul, .wf-help ol { color: var(--text-muted, #555); line-height: 1.7; padding-left: 22px; }
        .wf-help li { margin-bottom: 6px; }
        .wf-help code { background: var(--surface-3, #f4f4f4); padding: 1px 6px; border-radius: 3px; font-size: 12.5px; color: var(--warning-text, #b45309); }
        .wf-help pre { background: #263238; color: #eceff1; border-radius: 6px; padding: 14px 16px; font-size: 12.5px; line-height: 1.55; overflow-x: auto; margin: 14px 0; }
        .wf-help pre code { background: none; color: inherit; padding: 0; font-size: 12.5px; }
        .wf-help table { border-collapse: collapse; width: 100%; margin: 14px 0; font-size: 13px; }
        .wf-help table th, .wf-help table td { border: 1px solid var(--border, #e5e7eb); padding: 8px 10px; text-align: left; vertical-align: top; }
        .wf-help table th { background: var(--surface-2, #f9fafb); font-weight: 600; color: var(--text, #374151); }
        .wf-help .callout { background: var(--wf-accent-soft, #fff7ed); border-left: 3px solid var(--wf-accent, #f59e0b); padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: var(--warning-text, #92400e); }
        .wf-help .callout strong { color: var(--warning-text, #78350f); }
        .wf-help .tip { background: #f0f9ff; border-left: 3px solid #0ea5e9; padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: #075985; }
        .wf-help .tip code, .wf-help .callout code { background: rgba(255,255,255,0.55); }
        .wf-help .steps-num { counter-reset: qs; list-style: none; padding-left: 0; }
        .wf-help .steps-num li { position: relative; padding: 2px 0 10px 40px; }
        .wf-help .steps-num li::before { counter-increment: qs; content: counter(qs); position: absolute; left: 0; top: 0; width: 26px; height: 26px; border-radius: 50%; background: var(--wf-accent, #f59e0b); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        /* Dark: sink the pale sky-blue tip box + the translucent-white inline
           code chips inside tip/callout so they don't glow. */
        [data-theme-mode="dark"] .wf-help .tip { background: #12263a; border-left-color: #38bdf8; color: #7dd3fc; }
        [data-theme-mode="dark"] .wf-help .tip code, [data-theme-mode="dark"] .wf-help .callout code { background: rgba(0,0,0,0.30); }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wfh-container">
        <aside class="wfh-sidebar">
            <a href="help.php" class="wfh-back">&larr; Back to Workflows guide</a>
            <h3>Webhooks</h3>
            <a href="#what"        class="wfh-nav-link active" data-section="what"><span class="wfh-nav-num">1</span> What is a webhook?</a>
            <a href="#how"         class="wfh-nav-link" data-section="how"><span class="wfh-nav-num">2</span> How they work here</a>
            <a href="#quickstart"  class="wfh-nav-link" data-section="quickstart"><span class="wfh-nav-num">3</span> Quick start</a>
            <a href="#formats"     class="wfh-nav-link" data-section="formats"><span class="wfh-nav-num">4</span> Payload formats</a>
            <a href="#full"        class="wfh-nav-link" data-section="full"><span class="wfh-nav-num">5</span> Full-record payloads</a>
            <a href="#testing"     class="wfh-nav-link" data-section="testing"><span class="wfh-nav-num">6</span> Test before you ship</a>
            <a href="#signing"     class="wfh-nav-link" data-section="signing"><span class="wfh-nav-num">7</span> Signing (HMAC)</a>
            <a href="#reliability" class="wfh-nav-link" data-section="reliability"><span class="wfh-nav-num">8</span> Reliable delivery</a>
            <a href="#dashboard"   class="wfh-nav-link" data-section="dashboard"><span class="wfh-nav-num">9</span> The dashboard</a>
            <a href="#catalogue"   class="wfh-nav-link" data-section="catalogue"><span class="wfh-nav-num">10</span> Event catalogue</a>
            <a href="#dataprotection" class="wfh-nav-link" data-section="dataprotection"><span class="wfh-nav-num">&#128274;</span> What&rsquo;s stored</a>
            <a href="#troubleshoot" class="wfh-nav-link" data-section="troubleshoot"><span class="wfh-nav-num">11</span> Troubleshooting</a>
            <a href="#recipes"     class="wfh-nav-link" data-section="recipes"><span class="wfh-nav-num">12</span> Recipes</a>
        </aside>

        <main class="wfh-main">
            <div class="tab-content active wf-help">
            <h2>Webhooks</h2>
            <p class="lede">A webhook is how FreeITSM tells <strong>other systems</strong> that something happened &mdash; post to Slack when a P1 is raised, ping a monitoring tool when a change is approved, kick off a provisioning script when a new starter form is submitted. This guide takes you from &ldquo;what is a webhook?&rdquo; all the way to signing, retries and the delivery dashboard.</p>

            <!-- 1 -->
            <h3 id="what">1. What is a webhook? (the 101)</h3>
            <p>Most integrations you know work by <em>pulling</em> &mdash; a program asks an API &ldquo;anything new?&rdquo; over and over. A webhook flips that around: instead of others polling FreeITSM, <strong>FreeITSM pushes to them the instant something happens</strong>. It&rsquo;s a plain HTTP <code>POST</code> carrying a small JSON body, sent to a URL you control (a &ldquo;receiver&rdquo;).</p>
            <ul>
                <li><strong>Event</strong> &mdash; something happened (a ticket was raised, a change approved).</li>
                <li><strong>Payload</strong> &mdash; a JSON description of that event.</li>
                <li><strong>Endpoint</strong> &mdash; the URL that receives the POST (a Slack incoming-webhook URL, an automation platform like Zapier/Make, or your own server).</li>
            </ul>
            <p>If you&rsquo;ve ever set up a &ldquo;post to Slack when X happens&rdquo; rule, you&rsquo;ve used a webhook. FreeITSM is the sender; Slack is the receiver.</p>
            <div class="tip"><strong>Inbound vs outbound.</strong> Webhooks here are <em>outbound</em> &mdash; FreeITSM makes other systems do things. The <em>inbound</em> direction (other systems making FreeITSM do things) is covered by the REST API, the email pipeline, and messaging ingest.</div>

            <!-- 2 -->
            <h3 id="how">2. How webhooks work here &mdash; a workflow action</h3>
            <p>There is no separate &ldquo;webhooks&rdquo; feature to learn. A webhook is simply the <strong>Send a webhook</strong> action inside a <a href="help.php">Workflow</a>. That&rsquo;s deliberate: it means a webhook inherits the whole workflow engine for free.</p>
            <ul>
                <li><strong>Triggers</strong> &mdash; fire the webhook on any of the catalogue&rsquo;s events (see <a href="#catalogue">Event catalogue</a>).</li>
                <li><strong>Conditions</strong> &mdash; only send when it matters (<em>&ldquo;priority is Critical AND department is Finance&rdquo;</em>).</li>
                <li><strong>Ordering</strong> &mdash; a webhook is one action among others; put a note-add before it and a task-create after.</li>
                <li><strong>Audit</strong> &mdash; every fire is logged with its full request and the receiver&rsquo;s response.</li>
            </ul>
            <p>So the mental model is: <em>a workflow catches an event &rarr; conditions decide &rarr; the Send-a-webhook action posts JSON to your endpoint.</em></p>

            <!-- 3 -->
            <h3 id="quickstart">3. Quick start &mdash; Slack in five steps</h3>
            <ol class="steps-num">
                <li>In Slack, create an <strong>Incoming Webhook</strong> and copy its URL (it looks like <code>https://hooks.slack.com/services/&hellip;</code>).</li>
                <li>In FreeITSM, open <strong>Workflows &rarr; New workflow</strong> and pick a trigger &mdash; e.g. <code>ticket.created</code>.</li>
                <li><em>(Optional)</em> Add a condition, e.g. <em>priority is Critical</em>, so you only ping Slack for P1s.</li>
                <li>Add action &rarr; <strong>Send a webhook</strong>. Choose the <strong>Slack</strong> preset, paste the URL, and write a message like <code>P1 raised: {{ticket.subject}}</code>.</li>
                <li>Click <strong>Send test</strong> to see it arrive in Slack, then <strong>Save</strong> and toggle the workflow <em>Active</em>.</li>
            </ol>
            <div class="callout"><strong>One-time setup:</strong> outbound webhooks are delivered by a background worker that must be scheduled to run (once). If it isn&rsquo;t, <strong>System &rarr; Webhooks</strong> tells you in red and gives you the exact command &mdash; see <a href="#reliability">Reliable delivery</a>.</div>

            <!-- 4 -->
            <h3 id="formats">4. Choosing a payload format</h3>
            <p>The Send-a-webhook action offers four body formats. Pick the one that matches your receiver:</p>
            <table>
                <tr><th>Format</th><th>Best for</th><th>What it sends</th></tr>
                <tr><td><strong>Slack / Teams / Discord</strong></td><td>Chat notifications</td><td>A templated <code>message</code> wrapped in that platform&rsquo;s exact chat-message JSON, so it lands as a proper formatted message with zero work on your side.</td></tr>
                <tr><td><strong>Custom</strong></td><td>Any other endpoint</td><td>A raw JSON <code>body</code> you write yourself, interpolating event fields with <code>{{&hellip;}}</code> placeholders. Match any target&rsquo;s exact contract.</td></tr>
                <tr><td><strong>Full record</strong></td><td>Data sync / automation platforms</td><td>The <em>entire object as JSON</em>, in exactly the shape the REST API returns for that record (see next section).</td></tr>
            </table>
            <p>In Custom mode, the field-dropdown in the editor lists the variables available for your chosen trigger, so you don&rsquo;t have to guess field names.</p>

            <!-- 5 -->
            <h3 id="full">5. Full-record payloads &mdash; the whole object, for free</h3>
            <p>Sometimes a receiver wants the <em>whole record</em>, not just a line of text &mdash; e.g. a data warehouse or an automation platform that mirrors your tickets. Two ways to send it:</p>
            <ul>
                <li>The <strong>Full record</strong> preset &mdash; sends the entire object, nothing else to configure.</li>
                <li>In <strong>Custom</strong> mode, embed it anywhere with <code>{{entity.full}}</code> &mdash; e.g. <code>{{ticket.full}}</code>, <code>{{change.full}}</code> &mdash; and the template engine drops the whole object in as JSON.</li>
            </ul>
            <p>The magic: the payload is <strong>byte-for-byte identical to what the REST API returns</strong> for <code>GET /&lt;resource&gt;/{id}</code>, because it reuses the very same serialisers. So a webhook payload and an API response for the same record share the same rich, typed, documented (OpenAPI) shape &mdash; free consistency.</p>
            <p>Supported today &mdash; one per record type whose API <code>GET /{id}</code> is a clean single fetch:</p>
            <p><code>ticket.full</code>, <code>change.full</code>, <code>problem.full</code>, <code>task.full</code>, <code>asset.full</code>, <code>article.full</code> (knowledge), <code>contract.full</code>, <code>supplier.full</code>, <code>calendar_event.full</code>, <code>software_licence.full</code>, and <code>incident.full</code> (service-status).</p>
            <div class="tip">It&rsquo;s loaded <strong>lazily</strong> (only when the Full-record preset is chosen or your body mentions <code>.full</code>, so a plain Slack ping never pays for it) and <strong>best-effort</strong> (if the record can&rsquo;t be loaded, the webhook still sends, just without <code>.full</code>). CMDB objects and network diagrams don&rsquo;t have a <code>.full</code> yet &mdash; their API GET hydrates deep child collections &mdash; but their events still carry id + name inline.</div>

            <!-- 6 -->
            <h3 id="testing">6. Test before you ship</h3>
            <p>Guessing whether a webhook is wired up correctly is miserable. Don&rsquo;t &mdash; use <strong>Send test</strong> in the Send-a-webhook action panel:</p>
            <ul>
                <li>It renders your templates against a <strong>real, representative sample</strong> (the most recent real ticket, so <code>{{ticket.*}}</code> and Full-record look true-to-life).</li>
                <li>It sends the request <strong>right then</strong> and shows you the endpoint&rsquo;s <strong>real response</strong> &mdash; a Delivered / Not-delivered pill, the HTTP status and round-trip time, the exact JSON that was sent, and the response body.</li>
                <li>It does <strong>not</strong> save or enqueue anything &mdash; it&rsquo;s a one-off preview.</li>
            </ul>
            <div class="tip">Send test uses the <em>identical</em> request builder and transport as a real delivery, so &ldquo;it worked in test&rdquo; genuinely means it will work live.</div>

            <!-- 7 -->
            <h3 id="signing">7. Signing payloads (HMAC)</h3>
            <p>Anyone who learns your endpoint URL could POST fake events to it. To prove a delivery genuinely came from your FreeITSM instance, set a <strong>signing secret</strong> on the action. Each request is then signed:</p>
            <pre><code>X-FreeITSM-Signature: sha256=&lt;HMAC-SHA256(body, your_secret)&gt;</code></pre>
            <p>Your receiver recomputes the same signature with the same secret and rejects anything that doesn&rsquo;t match. A tiny PHP receiver:</p>
            <pre><code>$secret = getenv('FREEITSM_WEBHOOK_SECRET');
$body   = file_get_contents('php://input');
$sig    = $_SERVER['HTTP_X_FREEITSM_SIGNATURE'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    exit('bad signature');   // not from us — drop it
}
$event = json_decode($body, true);   // trusted from here</code></pre>
            <div class="callout"><strong>The secret is never stored.</strong> FreeITSM computes the signature at the moment a delivery is queued and keeps only the resulting header &mdash; so your secret never lands in the database or the delivery log. Keep it out of source control and treat it like a password.</div>

            <!-- 8 -->
            <h3 id="reliability">8. Reliable delivery &mdash; the async engine</h3>
            <p>Networks fail. Receivers go down or rate-limit you. A naive &ldquo;POST and hope&rdquo; would silently lose those events &mdash; FreeITSM doesn&rsquo;t. Every send is <strong>queued</strong> and handed to a background worker, which gives you:</p>
            <ul>
                <li><strong>Retries with backoff</strong> &mdash; a failed delivery is retried on an increasing delay (1m &rarr; 5m &rarr; 15m &rarr; 1h &rarr; 6h), not hammered.</li>
                <li><strong>Dead-letter</strong> &mdash; after the retry budget is spent, the delivery is parked as <em>failed</em> rather than lost or retried forever.</li>
                <li><strong>Replay</strong> &mdash; re-send any delivery by hand once the receiver is back.</li>
                <li><strong>No blocking</strong> &mdash; because sending is asynchronous, a slow or dead endpoint <em>never</em> holds up the ticket (or whatever) that triggered it.</li>
            </ul>
            <h4>The one-time setup</h4>
            <p>The background worker (<code>cron/webhook_deliveries.php</code>) has to be scheduled to run every minute &mdash; via Windows Task Scheduler, cron, or a hosted cron service hitting it over HTTP. <strong>System &rarr; Webhooks</strong> detects live whether it&rsquo;s running and, if not, shows the exact command for <em>your</em> install with a copy button. Until it runs, webhooks queue but don&rsquo;t leave the building.</p>

            <!-- 9 -->
            <h3 id="dashboard">9. The Webhooks dashboard</h3>
            <p><strong>System &rarr; Webhooks</strong> (also reachable from <strong>Workflows &rarr; Webhook deliveries</strong>) is the single home for everything delivery-related, in three bands:</p>
            <ul>
                <li><strong>Worker status</strong> &mdash; a live green/amber/red check of whether the background worker is running, with setup steps if it isn&rsquo;t.</li>
                <li><strong>Overview</strong> &mdash; delivery health at a glance: a 7-day <em>success rate</em>, <em>volume sent</em> (plus last 24h), <em>average delivery time</em>, how many are <em>queued</em> and <em>dead-lettered</em>, a 14-day delivered-vs-failed <em>volume chart</em>, and tables of your busiest <em>endpoints</em> and <em>source workflows</em>, each with its own success rate. Enough to spot a failing integration without reading the log line by line.</li>
                <li><strong>Delivery log</strong> &mdash; every send, filterable by status, with the full request payload, the full response, the last error, and a <strong>Replay</strong> button.</li>
            </ul>
            <div class="tip">Success rate counts <em>terminal</em> outcomes only (delivered vs dead) &mdash; deliveries still in flight or mid-retry aren&rsquo;t counted against you.</div>

            <!-- 10 -->
            <h3 id="catalogue">10. The event catalogue</h3>
            <p>You can fire a webhook on any event in the catalogue &mdash; and it&rsquo;s big: <strong>138 triggers and counting</strong>, spanning every module. There are two kinds:</p>
            <ul>
                <li><strong>Rich domain events</strong> &mdash; meaningful lifecycle moments with a full payload: <code>ticket.created</code>, <code>ticket.status_changed</code>, <code>change.approved</code>, <code>problem.status_changed</code>, <code>task.completed</code>, <code>service_status.incident_resolved</code>, <code>software.application_discovered</code>, and many more.</li>
                <li><strong>Create / update / delete events</strong> &mdash; every reusable record and settings lookup emits <code>&lt;entity&gt;.created</code> / <code>.updated</code> / <code>.deleted</code>: tickets, assets, changes, problems, tasks, CMDB, contracts &amp; suppliers, calendar, software licences, network diagrams, and all their settings lists (statuses, priorities, types, tags&hellip;).</li>
            </ul>
            <p>Because the list is dozens deep, the workflow editor&rsquo;s <strong>trigger picker is searchable</strong> &mdash; start typing (<code>resolved</code>, <code>contract</code>, <code>delete</code>&hellip;) to filter it. Each event carries a typed payload, so conditions get real dropdowns of values rather than opaque numbers.</p>
            <div class="tip">Every event fires from a <strong>single</strong> shared write path, so it behaves identically whether the change was made by an analyst in the browser, by a script hitting the REST API, or by another workflow &mdash; it can&rsquo;t drift. The full, always-current list lives in the <a href="https://github.com/edmozley/freeitsm/wiki/Webhooks#event-catalogue" target="_blank" rel="noopener">Webhooks wiki</a>.</div>

            <!-- 11 -->
            <h3 id="dataprotection">What FreeITSM stores, and for how long</h3>
            <p>Three things about a webhook end up on disk, and it&rsquo;s worth knowing what happens to each.</p>
            <h4>The URL and the signing secret &mdash; encrypted</h4>
            <p>A webhook URL is a <strong>credential in its own right</strong>: anyone holding your Discord or Slack URL can post into that channel. The signing secret is a true secret &mdash; its whole job is proving a message really came from you, which anyone who could read it could forge. Both are <strong>encrypted at rest</strong> (AES-256-GCM), in the workflow itself and in the delivery queue, and the URL is <strong>redacted in the delivery log</strong> so the token isn&rsquo;t sitting in a screen any analyst can open.</p>
            <p>This depends on an encryption key being configured. If your install has none, FreeITSM stores them as-is rather than breaking your webhooks &mdash; and says so plainly, in a warning at the top of <strong>System &rarr; Webhooks</strong>. It never pretends to a protection it isn&rsquo;t providing.</p>
            <h4>The payload &mdash; kept only as long as you choose</h4>
            <p>The delivery log stores <em>the exact payload that was sent</em>, so you can see what went out. With the <strong>Full record</strong> format that is an <strong>entire ticket</strong> &mdash; subject, requester, the lot &mdash; copied into the queue table in plain text. That is a real data-at-rest question, so it gets an explicit answer rather than an accidental one: <strong>System &rarr; Webhooks &rarr; Data protection</strong> sets how long payload bodies are kept (default <strong>7 days</strong>; you can also choose never to store them at all).</p>
            <p>When the window passes, the payload and response bodies are scrubbed but the <strong>delivery record is kept</strong> &mdash; endpoint, status, timing, errors &mdash; so your dashboard and your audit trail survive intact. The whole row is eventually removed by the separate log-retention setting.</p>
            <div class="callout"><strong>The trade-off, stated plainly.</strong> <em>Replay</em> re-sends the stored payload. Once a payload has been scrubbed there is nothing left to re-send, so that delivery can no longer be replayed &mdash; FreeITSM tells you exactly that, rather than quietly POSTing an empty body to a live endpoint. In practice you replay a webhook within hours of it failing, not weeks, which is why the payload window is shorter than the record&rsquo;s.</div>

            <h3 id="troubleshoot">11. Troubleshooting</h3>
            <table>
                <tr><th>Symptom</th><th>Likely cause &amp; fix</th></tr>
                <tr><td><em>&ldquo;unable to get local issuer certificate&rdquo;</em> / any SSL certificate error</td><td>Your server has no list of trusted certificate authorities, so it can&rsquo;t confirm it&rsquo;s really talking to Slack or Discord. Extremely common on a fresh Windows/WAMP install, and <strong>not a problem with your webhook</strong>. Full explanation and the five-minute fix: <a href="help-ssl.php" style="font-weight:600;">HTTPS certificate verification &rarr;</a></td></tr>
                <tr><td>Nothing arrives, log shows deliveries <em>queued</em></td><td>The background worker isn&rsquo;t running. Check the red banner on <strong>System &rarr; Webhooks</strong> and schedule the command it shows.</td></tr>
                <tr><td>Delivery is <em>failed</em> / <em>retrying</em></td><td>Open it in the log &mdash; the response body and last error are captured. Usually a 4xx/5xx from the receiver (bad URL, auth, or payload shape). Fix and <strong>Replay</strong>.</td></tr>
                <tr><td>&ldquo;the rendered body is not valid JSON&rdquo;</td><td>A Custom body didn&rsquo;t parse after variables were filled in &mdash; often an empty variable breaking quotes. Use <strong>Send test</strong> to see the rendered body, or switch to the Full-record preset.</td></tr>
                <tr><td>Receiver rejects with 401</td><td>Signature mismatch &mdash; the secret on the action and on your receiver differ, or the receiver hashes something other than the raw body.</td></tr>
                <tr><td>The webhook never fires</td><td>Check the workflow is <em>Active</em>, its trigger matches, and its conditions pass &mdash; the workflow&rsquo;s <em>Recent runs</em> shows <em>skipped</em> runs and why.</td></tr>
            </table>

            <!-- 12 -->
            <h3 id="recipes">12. Recipes</h3>
            <ul>
                <li><strong>P1 &rarr; Slack / PagerDuty</strong> &mdash; trigger <code>ticket.created</code> (or <code>ticket.priority_changed</code>), condition <em>priority is Critical</em>, Slack preset or a Custom PagerDuty Events API body.</li>
                <li><strong>Change approved &rarr; deploy pipeline</strong> &mdash; trigger <code>change.approved</code>, Custom body posting to your CI/CD webhook with <code>{{change.full}}</code>.</li>
                <li><strong>New starter form &rarr; provisioning</strong> &mdash; trigger <code>form.submitted</code>, condition on the form, Full-record (or <code>{{submission.fields}}</code>) to your onboarding automation.</li>
                <li><strong>New software discovered &rarr; security review</strong> &mdash; trigger <code>software.application_discovered</code>, Teams preset to your security channel.</li>
                <li><strong>Data mirror</strong> &mdash; trigger the relevant <code>&lt;entity&gt;.created/.updated/.deleted</code>, Full-record preset to your warehouse ingest endpoint (signed).</li>
            </ul>
            <p style="margin-top:24px;"><a href="help.php" class="wfh-back" style="display:inline-flex; padding-left:0;">&larr; Back to the Workflows guide</a></p>
            </div>
        </main>
    </div>

    <script>
    // Scroll-spy (identical to workflow/help.php).
    (function () {
        const helpMain = document.querySelector('.wfh-main');
        const navLinks = document.querySelectorAll('.wfh-nav-link[data-section]');
        const sections = [];
        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el });
        });
        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;
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
    })();
    </script>
</body>
</html>
