<?php
/**
 * System Help — Webhooks.
 *
 * The System > Webhooks page is an operations dashboard over the delivery
 * queue, not a CRUD screen: webhooks themselves are built as an action inside a
 * workflow. This page says so early and links out to the workflow help rather
 * than restating it.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'webhooks';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this page is</h3></div>
    <p class="syshelp-lead">A webhook pushes an event out of FreeITSM to somewhere else — a Slack channel, a Teams channel, Discord, or any URL that accepts an HTTP POST. &ldquo;Ticket P1 raised&rdquo; lands in the channel your team already watches.</p>
    <div class="syshelp-callout info"><strong>You don't create webhooks on this page.</strong> There is no list of webhooks to add or delete here, because a webhook isn't a thing you configure separately — it's the <strong>Send a webhook</strong> action inside a <strong>workflow</strong>. This page is the control room: it tells you whether the ones you built are actually being delivered, and lets you look at, diagnose and replay individual deliveries.</div>
    <p>It has four cards, top to bottom:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Setup &amp; status</h4>
            <p>Whether the background delivery worker is running. If it isn't, nothing is being sent — start here.</p>
        </div>
        <div class="syshelp-card">
            <h4>Data protection</h4>
            <p>Whether URLs and secrets are encrypted, and how long sent payloads are kept.</p>
        </div>
        <div class="syshelp-card">
            <h4>Overview</h4>
            <p>Success rate, volume, average delivery time, what's queued, and which endpoints and workflows are busiest.</p>
        </div>
        <div class="syshelp-card">
            <h4>Delivery log</h4>
            <p>Every individual delivery, with its status, attempts, response code — and a Replay button.</p>
        </div>
    </div>
</div>

<!-- 2. Building a webhook -->
<div class="syshelp-section" id="building">
    <div class="syshelp-section-header"><h3>Building a webhook</h3></div>
    <p class="syshelp-lead">Webhooks are built in the <strong>Workflows</strong> module. In short:</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><span class="syshelp-step-num">1</span><div>Create a workflow and pick its <strong>trigger</strong> — the event you want to push out (ticket created, change approved, SLA breached, and well over a hundred more). The trigger <em>is</em> the webhook's event; there's no separate event list to tick.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">2</span><div>Add conditions if you only want some of them — say, priority is P1.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">3</span><div>Add the <strong>Send a webhook</strong> action. Choose a <strong>format</strong> (Slack, Microsoft Teams, Discord, the full record as JSON, or your own raw JSON), paste the <strong>URL</strong>, write the <strong>message</strong>, and optionally set a <strong>signing secret</strong>.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">4</span><div>Press <strong>Send test</strong> in the action panel. It renders your message against a real recent record and posts it for real, straight away, showing you the exact headers and body sent and whatever came back.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">5</span><div>Turn the workflow <strong>Active</strong>. That's the webhook's on/off switch.</div></div>
    </div>
    <p>The formats are data, not code: Slack, Teams and Discord ship built in, and you can add your own (Google Chat, ntfy, anything that takes a JSON body) under <strong>Workflows &rarr; Settings &rarr; Message formats</strong> without touching PHP.</p>
    <p>The full guide — variables, formats, receiving ends — lives with the module: <a href="<?php echo BASE_URL; ?>workflow/help-webhooks.php">Workflows help &rarr; Webhooks</a>.</p>
    <div class="syshelp-callout"><strong>A test that passes does not prove live delivery works.</strong> The test is sent by the web server; real deliveries are sent by the background worker, which is a different PHP process with its own configuration. See Troubleshooting at the foot of this page.</div>
</div>

<!-- 3. The delivery worker -->
<div class="syshelp-section highlight" id="worker">
    <div class="syshelp-section-header"><h3>The delivery worker — do this first</h3></div>
    <p class="syshelp-lead">FreeITSM never sends a webhook while you wait. The workflow drops it into a queue and returns instantly; a background worker picks it up and sends it. That keeps a slow or dead endpoint from hanging up the app — but it has one consequence you cannot ignore:</p>
    <div class="syshelp-callout warn"><strong>If the worker isn't scheduled, nothing is ever sent.</strong> Webhooks queue up silently and for ever. No error appears anywhere else in the app. This is the single most common reason for &ldquo;my webhooks don't work&rdquo;.</div>
    <p>The status pill at the top of the page tells you where you stand — <strong>running</strong>, <strong>stale</strong>, <strong>down</strong>, or <strong>never run</strong>. If it is anything but running, the card shows you the exact command to schedule, with a copy button. There are two ways to run it:</p>
    <table class="syshelp-table">
        <tr><th>Method</th><th>Use when</th></tr>
        <tr><td><strong>Command line</strong> — run <code>cron/webhook_deliveries.php</code> with PHP</td><td>The normal choice. Windows Task Scheduler, or a Linux cron entry.</td></tr>
        <tr><td><strong>HTTP</strong> — request the same script with <code>?token=…</code></td><td>Shared hosting where you can only schedule a URL fetch. The token is generated for you; treat it as a secret.</td></tr>
    </table>
    <p><strong>Schedule it every minute.</strong> Delivery latency is essentially your cron interval — an hourly cron means a P1 alert lands in Slack up to an hour late. The worker protects itself against overlap, so a tight schedule is safe.</p>
    <div class="syshelp-callout info"><strong>Time-based triggers need a second cron.</strong> Workflows fired by the passage of time rather than by an action — <code>sla.warning</code>, <code>sla.breached</code>, <code>contract.expiring</code>, <code>asset.warranty_expiring</code> — depend on the scheduled-workflow cron as well. Without it those triggers never fire at all, so their webhooks never even reach the queue.</div>
</div>

<!-- 4. The delivery log -->
<div class="syshelp-section" id="log">
    <div class="syshelp-section-header"><h3>The delivery log</h3></div>
    <p class="syshelp-lead">One row per delivery attempt-chain: when it was queued, which workflow produced it, the format, the (redacted) URL, its status, how many attempts it has used, the last HTTP code it got and when it will next be retried. Filter with the chips along the top, and click a row to open it.</p>
    <p>A failing delivery is retried on a widening backoff — <strong>1 minute, 5 minutes, 15 minutes, 1 hour, 6 hours</strong> — and after six attempts it is given up on. Read the four statuses carefully, because two of the words don't mean quite what you'd guess:</p>
    <table class="syshelp-table">
        <tr><th>Status</th><th>What it means</th></tr>
        <tr><td><strong>Pending</strong></td><td>Queued, waiting for the worker's next run.</td></tr>
        <tr><td><strong>Delivered</strong></td><td>The endpoint answered with a 2xx. Done.</td></tr>
        <tr><td><strong>Retrying</strong></td><td>An attempt failed, but more are scheduled. It will sort itself out, or become Failed. No action needed yet.</td></tr>
        <tr><td><strong>Failed</strong></td><td><strong>Given up on.</strong> All six attempts are used. It will <em>never</em> retry on its own — the only way it is ever sent is if you Replay it.</td></tr>
    </table>
    <p>Opening a row shows the full request FreeITSM sent — method, URL, headers, body — the response that came back, and a plain-English <strong>diagnosis</strong> of the failure where it can work one out (a certificate problem, a DNS failure, a refused connection, a timeout). Take the diagnosis seriously; it usually names the fix.</p>
</div>

<!-- 5. Replay -->
<div class="syshelp-section" id="replay">
    <div class="syshelp-section-header"><h3>Replaying a delivery</h3></div>
    <p class="syshelp-lead"><strong>Replay</strong> puts a delivery back at the front of the queue, exactly as it was — same body, same signature — so the worker sends it again on its next run. It's how you recover the alerts that were lost while an endpoint was down, once you've fixed it.</p>
    <p>You can replay anything that has settled: delivered, failed, or given up on. Replaying a delivered one simply sends it a second time, which is occasionally what you want and occasionally not.</p>
    <div class="syshelp-callout warn"><strong>Replay needs the payload, and the payload has an expiry.</strong> Once a delivery's stored body has been scrubbed by the retention setting below (7 days by default), it can never be replayed — there is nothing left to send. The row stays in the log for the record, but the Replay button explains why it can't run.</div>
</div>

<!-- 6. Payload retention -->
<div class="syshelp-section" id="retention">
    <div class="syshelp-section-header"><h3>Payload retention</h3></div>
    <p class="syshelp-lead">Webhook bodies can contain real ticket content — subjects, requester names, whatever your message includes. <strong>Keep sent payloads for</strong> decides how long that content sits in the database after the delivery has settled. Choose from: don't store them at all, 1 day, 7 days (the default), 30 days, or as long as the delivery record itself.</p>
    <p>Changing it applies immediately, and the page tells you how many stored payloads it scrubbed on the spot.</p>
    <div class="syshelp-callout info"><strong>&ldquo;Don't store them at all&rdquo; doesn't mean the body is never written.</strong> Delivery is asynchronous, so the body has to survive in the queue until it's been sent — it simply gets wiped the instant the delivery settles. A queued webhook is never destroyed before it goes out.</div>
    <div class="syshelp-callout"><strong>Retention is a trade against Replay.</strong> Shorter retention is better for privacy; longer retention is what lets you re-send a week-old batch after an outage. &ldquo;Don't store them at all&rdquo; means you can never replay anything. Seven days is a reasonable middle.</div>
    <p>Separately, the log <em>rows</em> themselves are tidied after 30 days — that clock is shown on the setup card and isn't editable here.</p>
</div>

<!-- 7. Signing & encryption -->
<div class="syshelp-section" id="security">
    <div class="syshelp-section-header"><h3>Signing &amp; encryption</h3></div>
    <h4>Signing secret</h4>
    <p>If you set a signing secret on the action, every request carries an <code>X-FreeITSM-Signature</code> header — an HMAC-SHA256 of the exact body, keyed with your secret. The receiving end recomputes it and compares. That's how it knows the POST genuinely came from your FreeITSM and not from somebody who guessed the URL. Worth doing for any endpoint you've written yourself; Slack, Teams and Discord don't use it (their URL is the secret).</p>

    <h4>Encryption at rest</h4>
    <p>A webhook URL <em>is</em> a credential — anyone holding a Slack webhook URL can post to that channel. So FreeITSM encrypts both the URL and the signing secret in the database, using the key you set up under System &rarr; Encryption.</p>
    <div class="syshelp-callout warn"><strong>No encryption key means plain text.</strong> If no key is configured, FreeITSM stores these values unencrypted rather than refusing to work — and the red banner on the Data protection card says so. Set a key up on the Encryption page. Note that doing so does <em>not</em> retro-encrypt what's already saved: you must open and re-save each workflow that sends a webhook.</div>
    <p>In the delivery log, URLs are shown with their last segment masked, so the channel token isn't casually readable.</p>
</div>

<!-- 8. Troubleshooting -->
<div class="syshelp-section" id="trouble">
    <div class="syshelp-section-header"><h3>Troubleshooting</h3></div>
    <table class="syshelp-table">
        <tr><th>Symptom</th><th>Almost always</th></tr>
        <tr><td>Nothing is delivered, ever. The queue only grows.</td><td>The delivery worker isn't scheduled. Check the status pill at the top of the page.</td></tr>
        <tr><td><strong>Send test</strong> works, but live deliveries fail.</td><td>The test runs under the web server's PHP; the worker runs under command-line PHP — <em>two different php.ini files</em>. A certificate fix applied to only one of them produces exactly this. Fix both.</td></tr>
        <tr><td><code>SSL certificate problem: unable to get local issuer certificate</code></td><td>The stock Windows/WAMP CA bundle. It affects every outbound HTTPS call, not just webhooks. Follow the <a href="<?php echo BASE_URL; ?>workflow/help-ssl.php">certificate setup guide</a>.</td></tr>
        <tr><td>A row says <strong>Failed</strong> and never moves.</td><td>That means given-up-on, not in-progress. Fix the endpoint, then <strong>Replay</strong> it.</td></tr>
        <tr><td>Deliveries arrive, but minutes late.</td><td>Your cron interval. Schedule the worker every minute.</td></tr>
        <tr><td>An SLA or expiry webhook never fires at all — nothing in the log.</td><td>Time-based triggers need the scheduled-workflow cron as well as the webhook worker.</td></tr>
        <tr><td>A workflow errors with &ldquo;unknown message format&rdquo;.</td><td>Someone deactivated the custom message format it uses. Deleting a format in use is blocked; deactivating one isn't.</td></tr>
    </table>
    <div class="syshelp-callout ok"><strong>The 30-second health check:</strong> status pill says <em>running</em>, success rate is near 100%, and <em>Queued now</em> is a small number that keeps falling. If queued only ever grows, the worker isn't running.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
