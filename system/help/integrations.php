<?php
/**
 * System Help — Integrations.
 *
 * ⚠️ Deliberately an OVERVIEW, not a setup guide. Each provider already has a
 * full step-by-step guide of its own at system/integrations/help.php?provider=<key>
 * (the pretty form is <provider>/help; NOT help?provider=, which resolves to
 * the provider CONFIG page - verified by fetching all three),
 * written against that provider's own screens. Restating any of it here would
 * give us two copies to keep in step, and the per-provider one is the copy
 * somebody actually reaches from the connection they are configuring.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'integrations';
require __DIR__ . '/_top.php';

$intBase = BASE_URL . 'system/integrations/';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What lives here</h3>
            <p>Connections to other systems that FreeITSM talks to. Four of them, doing three quite different jobs — they share a screen because they are all "somewhere else FreeITSM connects to", not because they are alike.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Integration</th><th>What it is for</th></tr></thead>
        <tbody>
            <tr>
                <td><strong>Jira</strong></td>
                <td>Raise Jira issues from tickets and see their status without leaving FreeITSM. Works with Jira Cloud and Jira Data Center.</td>
            </tr>
            <tr>
                <td><strong>Azure DevOps</strong></td>
                <td>Raise Azure DevOps work items from tickets and see their state. Works with Azure DevOps Services and Server.</td>
            </tr>
            <tr>
                <td><strong>Slack</strong></td>
                <td>Turn a message in a Slack channel into a ticket, and answer it from the inbox without leaving the thread.</td>
            </tr>
            <tr>
                <td><strong>Apache Tika</strong></td>
                <td>Read the text inside PDFs, older Office files and scanned documents so they can be searched.</td>
            </tr>
        </tbody>
    </table></div>
    <div class="help-note"><strong>Each one has its own setup guide</strong>, written against that provider's own screens and reached from its connection here. This page is the map; those are the instructions.</div>
    <div class="help-note warn"><strong>"The integration tables are not in the database yet."</strong> Run <a href="db-verify.php">Database Verification</a> before adding a connection.</div>
</div>

<!-- 2. Issue trackers -->
<div class="help-section" id="trackers">
    <div class="help-section-header"><?php echo helpSectionNum('trackers'); ?>
        <div>
            <h3>Issue trackers — Jira and Azure DevOps</h3>
            <p>For the ticket that turns out to be a bug. It gets raised with the development team and tracked from the service desk, so the requester can be told where it stands without anybody going to ask.</p>
        </div>
    </div>
    <p>Each <strong>connection</strong> is one site you can raise issues in. Add more than one if different teams or clients use separate sites — an MSP with three clients on three Jira instances needs three connections.</p>
    <div class="help-cards">
        <div class="help-card">
            <h4>Jira</h4>
            <p>Needs the site URL, an account email and an API token.</p>
            <p><a href="<?php echo htmlspecialchars($intBase); ?>help.php?provider=jira">Jira setup guide →</a></p>
        </div>
        <div class="help-card">
            <h4>Azure DevOps</h4>
            <p>Needs the organisation URL and a personal access token.</p>
            <p><a href="<?php echo htmlspecialchars($intBase); ?>help.php?provider=azuredevops">Azure DevOps setup guide →</a></p>
        </div>
    </div>
    <div class="help-note"><strong>Spend ten minutes on the mapping.</strong> Both guides say so and both are right: mapping decides what a tracker status means back here, and getting it wrong means a requester is told their bug is fixed when a developer has only said they think it is.</div>
    <p>Azure DevOps makes that concrete with its own setting: it has a <strong>Resolved</strong> state meaning a developer believes it is fixed but nobody has checked yet. Bugs use it; user stories do not. You choose whether FreeITSM treats Resolved as <em>still in progress</em> or as <em>done</em> — and "still in progress" is the honest answer for most service desks, because somebody still has to verify it.</p>
    <div class="help-note"><strong>Both trackers need a scheduled check</strong> to notice changes made at the other end, and both can let developer comments come back onto the ticket. The per-provider guides cover the schedule and the comment settings.</div>
</div>

<!-- 3. Slack -->
<div class="help-section" id="slack">
    <div class="help-section-header"><?php echo helpSectionNum('slack'); ?>
        <div>
            <h3>Slack</h3>
            <p>A different shape of integration: an intake channel rather than an outbound link.</p>
        </div>
    </div>
    <p>Somebody asks for help in a Slack channel, and that message becomes a ticket. Your team answers from the FreeITSM inbox and the reply appears back in the thread, so the person who asked never has to learn a second system.</p>
    <div class="help-note ok"><strong>This is usually the highest-value integration on the page</strong> for an organisation that already lives in Slack, because it captures the requests that currently never become tickets at all — the ones asked in passing, which are invisible to every report you run.</div>
    <p><a href="<?php echo htmlspecialchars($intBase); ?>help.php?provider=slack">Slack setup guide →</a></p>
</div>

<!-- 4. Tika -->
<div class="help-section" id="tika">
    <div class="help-section-header"><?php echo helpSectionNum('tika'); ?>
        <div>
            <h3>Apache Tika — reading documents</h3>
            <p>The odd one out: not a place FreeITSM sends things, but a service it asks to read files.</p>
        </div>
    </div>
    <p>Word, Excel, PowerPoint and plain text attachments are already searched <strong>without this</strong>. Tika adds the formats FreeITSM cannot read on its own — PDFs, older Office formats, and scanned documents. Point it at an address such as <code>http://127.0.0.1:9998</code> and set a timeout; scanned pages take much longer than ordinary text.</p>
    <div class="help-note bad"><strong>Keep it off the network.</strong> Tika has no password and no authentication of any kind: anything that can reach it will read whatever file it is sent. Run it so only this server can talk to it — bound to <code>127.0.0.1</code>, or on a private container network — and <strong>never expose its port to the internet</strong>.</div>
    <p>Leave the address empty to switch document reading off; PDFs are then simply listed as unreadable rather than failing. <a href="search.php">Search</a> shows exactly which attachments could not be read and why, which is the page to check when a document is not turning up.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
