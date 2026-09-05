<?php
/**
 * System — how to set up an issue tracker, end to end.
 *
 * Reached as /system/integrations/jira/help (see .htaccess) or, without
 * mod_rewrite, help.php?provider=jira.
 *
 * ⚠️ Written for somebody who does NOT already know Jira. That is the whole
 * point of it existing: an admin who knows Jira can read the mapping screen and
 * guess, and everybody else is looking at a modal full of dropdowns with no idea
 * what a "project key" is. Prose over reference tables, and every step says what
 * happens if you skip it.
 *
 * ONE page for every provider, like provider.php — the provider's name comes
 * from the registry. The Jira-specific parts (where Atlassian keeps API tokens,
 * what a project key looks like) are guarded on the provider key so a second
 * connector can add its own without unpicking this.
 *
 * Layout deliberately mirrors tickets/help.php (left nav, hero, scroll-spy) so
 * every help screen in the product feels like the same screen.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/integrations/integrations.php';

$current_page   = 'integrations';
$current_module = 'system';

// ⚠️ FILESYSTEM-relative, and it must stay that way: system/includes/header.php
// does `require_once $path_prefix . 'includes/functions.php'`, so BASE_URL here
// would break the include. Links are not affected — the header and waffle menu
// build those from BASE_URL themselves.
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// ⚠️ Every URL this page emits is ABSOLUTE. It is served at the pretty URL
// /system/integrations/<provider>/help, one segment deeper than the file, and a
// browser resolves relative links against the URL rather than the filesystem —
// so '../../assets/…' 404s as /system/assets/…. That is exactly how theme.css
// and inbox.css broke the first time.
$assetBase   = BASE_URL;
$providerUrl = BASE_URL . 'system/integrations/';

$providerKey = strtolower(trim((string)($_GET['provider'] ?? '')));
$meta        = integrationsProviderMeta($providerKey);
if (!$meta) {
    header('Location: ' . $providerUrl);
    exit;
}
$name    = $meta['name'];
$isJira  = ($providerKey === 'jira');
$isDevOps = ($providerKey === 'azuredevops');
// Slack is not a tracker: no base URL, no API token, no project. It gets its own
// sections from help_slack.php rather than an awkward reading of the tracker ones.
$isSlack = ($providerKey === 'slack');

/**
 * What each tracker calls its credential, because getting this wrong is not a
 * cosmetic problem: someone hunting for an "API token" in Azure DevOps will not
 * find one — it is a *personal access token*, in a different menu — and the
 * setup stalls at the first step.
 *
 * Anything not listed falls back to the generic wording, so provider #4 reads
 * awkwardly rather than incorrectly.
 */
$tokenNoun = $isDevOps ? 'personal access token' : 'API token';

/** The left-nav sections, in order. Single source for nav + scroll-spy. */
$sections = $isSlack ? [
    ['id' => 'overview',  'title' => 'What this does'],
    ['id' => 'workspace', 'title' => 'If you have never used Slack'],
    ['id' => 'add',       'title' => 'Add the workspace here first'],
    ['id' => 'app',       'title' => 'Create the Slack app'],
    ['id' => 'secrets',   'title' => 'Copy the two secrets back'],
    ['id' => 'invite',    'title' => 'Invite it to a channel'],
    ['id' => 'try',       'title' => 'Try it'],
    ['id' => 'trouble',   'title' => 'If something is wrong'],
] : [
    ['id' => 'overview', 'title' => 'What this does'],
    ['id' => 'token',    'title' => 'Get a ' . $tokenNoun],
    ['id' => 'connect',  'title' => 'Add the connection'],
    ['id' => 'schedule', 'title' => 'Schedule the check'],
    ['id' => 'comments', 'title' => 'Let comments come back'],
    ['id' => 'mapping',  'title' => 'Mapping'],
    ['id' => 'raise',    'title' => 'Raise an issue'],
    ['id' => 'trouble',  'title' => 'If something is wrong'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars($name); ?> setup</title>
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/theme.css?v=23">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--sys-accent);
            --accent-hover: var(--sys-accent-hover);
            --accent-soft:  var(--sys-accent-soft);
            --on-accent:    var(--sys-on-accent);
        }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="integrations-help">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3>Setup guide</h3>
            <?php foreach ($sections as $i => $s): ?>
                <a href="#<?php echo $s['id']; ?>" class="help-nav-link<?php echo $i === 0 ? ' active' : ''; ?>" data-section="<?php echo $s['id']; ?>">
                    <span class="help-nav-num"><?php echo $i + 1; ?></span>
                    <?php echo htmlspecialchars($s['title']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2>Setting up <?php echo htmlspecialchars($name); ?></h2>
                <p>
                    <?php if ($isSlack): ?>
                        Let people ask for help in Slack and have it become a ticket, answered from the
                        FreeITSM inbox without either of you leaving the thread.
                    <?php else: ?>
                        Hand a ticket to the development team without leaving FreeITSM: raise the issue,
                        watch its status, and read the developers' replies on the ticket.
                    <?php endif; ?>
                    This guide starts from nothing and assumes you have <strong>not</strong> used
                    <?php echo htmlspecialchars($name); ?> before.
                </p>
            </div>

            <a class="help-back" href="<?php echo htmlspecialchars($providerUrl . $providerKey); ?>">&larr; Back to <?php echo htmlspecialchars($name); ?> settings</a>

            <div class="help-content">

                <?php if ($isSlack): include __DIR__ . '/help_slack.php'; else: ?>

                <!-- 1 ───────────────────────────────────────────── -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <h3>What this does</h3>
                    </div>
                    <p>
                        Some tickets are not really support tickets. Somebody reports that a screen crashes,
                        and no amount of service desk work will fix it — a developer has to. Without an
                        integration that means copying the details into
                        <?php echo htmlspecialchars($name); ?> by hand and remembering to check back.
                    </p>
                    <p>Once this is set up you get three things:</p>
                    <ul>
                        <li><strong>Raise an issue from a ticket</strong> — by hand, or automatically by a rule.</li>
                        <li><strong>See its status on the ticket</strong>, updating on its own as the developers work.</li>
                        <li><strong>Read their comments</strong> on the ticket, so a question reaches you without anyone watching <?php echo htmlspecialchars($name); ?>.</li>
                    </ul>
                    <p class="help-muted">Roughly ten minutes to set up. Steps 1 to 3 are required; 4 and 5 are worth doing and can wait.</p>
                </div>

                <!-- 2 ───────────────────────────────────────────── -->
                <div class="help-section" id="token">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3>Get a <?php echo htmlspecialchars($tokenNoun); ?></h3>
                    </div>
                    <p>
                        FreeITSM signs in to <?php echo htmlspecialchars($name); ?> as <em>you</em> — or better,
                        as a dedicated account — using a <strong><?php echo htmlspecialchars($tokenNoun); ?></strong>. That is a long password
                        created specifically for other software, so you never put your real one into another
                        system, and you can revoke it without changing your own login.
                    </p>
                    <?php if ($isJira): ?>
                    <p>The token is <strong>not</strong> in Jira itself. It lives in your Atlassian account:</p>
                    <ol>
                        <li>Go to <code>id.atlassian.com</code> &rarr; <strong>Security</strong> &rarr; <strong>API tokens</strong>.</li>
                        <li>Choose <strong>Create API token</strong> and name it something you will recognise later, e.g. <code>FreeITSM</code>.</li>
                        <li>Copy it now. <strong>Atlassian shows it once</strong> and never again.</li>
                    </ol>
                    <div class="help-note">
                        <b>Worth deciding first</b>
                        Whoever owns this token is who <?php echo htmlspecialchars($name); ?> thinks FreeITSM is.
                        Use your own account and issues raised by FreeITSM look as though you raised them. A
                        separate account (say <code>freeitsm@yourcompany</code>) keeps that tidy — but your own
                        is perfectly fine to start with, and comments you write yourself still come back to the
                        ticket either way.
                    </div>
                    <?php elseif ($isDevOps): ?>
                    <p>
                        Azure DevOps calls this a <strong>personal access token</strong>, and it is not in the
                        Azure portal — the two are different products that share a sign-in. If you land on
                        <code>portal.azure.com</code> you have gone to the wrong one.
                    </p>
                    <ol>
                        <li>Go to <code>dev.azure.com</code> and open your organisation. <span class="help-muted">If it redirects you to the Azure portal, go to <code>aex.dev.azure.com</code> instead.</span></li>
                        <li>Click the <strong>user settings</strong> icon in the top right — the one just left of your avatar — then <strong>Personal access tokens</strong>.</li>
                        <li>Choose <strong>New Token</strong> and name it <code>FreeITSM</code>.</li>
                        <li>Under <strong>Scopes</strong>, pick <strong>Custom defined</strong>, then set <strong>Work Items</strong> to <strong>Read, write &amp; manage</strong>. Leave everything else — FreeITSM never touches your code.</li>
                        <li>Copy it now. <strong>Azure DevOps shows it once</strong> and never again.</li>
                    </ol>
                    <div class="help-note">
                        <b>Note the expiry date</b>
                        Azure DevOps caps these at <strong>one year</strong>, and often defaults to 90 days.
                        When it lapses, escalation stops and the connection test will say the token was
                        rejected. Put the date in a calendar now — this is the single most common reason a
                        working Azure DevOps connection stops working months later.
                    </div>
                    <div class="help-note">
                        <b>Worth deciding first</b>
                        Whoever owns this token is who <?php echo htmlspecialchars($name); ?> thinks FreeITSM is.
                        Use your own account and work items raised by FreeITSM look as though you raised them. A
                        separate account keeps that tidy — but your own is perfectly fine to start with, and
                        comments you write yourself still come back to the ticket either way.
                    </div>
                    <?php else: ?>
                    <p class="help-muted">Create an API token in <?php echo htmlspecialchars($name); ?> and copy it — you will paste it in the next step.</p>
                    <?php endif; ?>
                </div>

                <!-- 3 ───────────────────────────────────────────── -->
                <div class="help-section" id="connect">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3>Add the connection</h3>
                    </div>
                    <p>On the <a href="<?php echo htmlspecialchars($providerUrl . $providerKey); ?>"><?php echo htmlspecialchars($name); ?> settings page</a>, press <strong>Add</strong>.</p>
                    <div class="help-table">
                    <table>
                        <tr><th>Field</th><th>What to put</th></tr>
                        <tr><td><strong>Name</strong></td><td>Anything you will recognise — <?php echo $isDevOps ? '"Our DevOps", "Acme\'s DevOps"' : '"Our Jira", "Acme\'s Jira"'; ?>.</td></tr>
                        <tr><td><strong><?php echo $isDevOps ? 'Organisation URL' : 'Site URL'; ?></strong></td><td><?php
                            if ($isJira) {
                                echo 'The address you use to reach Jira, e.g. <code>https://yourcompany.atlassian.net</code>.';
                            } elseif ($isDevOps) {
                                // The commonest setup mistake here is pasting the URL of a
                                // PROJECT rather than the organisation, which fails in a way
                                // that reads as "bad credentials".
                                echo 'Your organisation address, e.g. <code>https://dev.azure.com/yourorg</code> — '
                                   . 'the organisation only, <strong>not</strong> a project. '
                                   . '<span class="help-muted">On-premises Azure DevOps Server: the collection address, e.g. '
                                   . '<code>https://tfs.yourcompany.local/tfs/DefaultCollection</code>.</span>';
                            } else {
                                echo 'The address of your ' . htmlspecialchars($name) . ' server.';
                            }
                        ?></td></tr>
                        <?php if ($isJira): ?>
                        <tr><td><strong>Email address</strong></td><td>The email your Atlassian account uses. <span class="help-muted">Leave blank for Jira Data Center / Server, which uses only a token.</span></td></tr>
                        <?php endif; ?>
                        <tr><td><strong><?php echo $isDevOps ? 'Personal access token' : 'API token'; ?></strong></td><td>The token from step 2.</td></tr>
                        <?php if ($isDevOps): ?>
                        <tr><td><strong>When a work item is marked Resolved</strong></td><td>
                            Azure DevOps has a <em>Resolved</em> state that sits between in-progress and closed: a developer
                            believes it is fixed, but nobody has checked. Bugs use it; user stories do not.
                            <strong>Treat it as still in progress</strong> (the default) and the person who raised the ticket
                            hears nothing until someone verifies. <strong>Treat it as done</strong> and they hear as soon as
                            the developer marks it — right if your team closes on resolve.
                        </td></tr>
                        <?php endif; ?>
                        <tr><td><strong>Company</strong></td><td>Leave as <em>All companies</em> unless this tracker belongs to one client. A ticket can only ever be escalated to a tracker its own company is allowed to use, and that rule cannot be overridden.</td></tr>
                    </table>
                    </div>
                    <p><strong>Press Test before saving.</strong> It tells you which account it connected as, or exactly what <?php echo htmlspecialchars($name); ?> objected to — far easier to fix now than after a rule fails at 2am.</p>
                    <div class="help-note">
                        <b>Coming back to edit it later?</b>
                        Leave the token box <strong>empty</strong>. FreeITSM never shows you a stored token, so an
                        empty box means "keep the one you already have", not "clear it".
                    </div>
                </div>

                <!-- 4 ───────────────────────────────────────────── -->
                <div class="help-section" id="schedule">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3>Schedule the check</h3>
                    </div>
                    <p>
                        FreeITSM does not sit waiting for <?php echo htmlspecialchars($name); ?> to call — it asks.
                        Every few minutes a small scheduled job wakes up and puts one question to it:
                        <em>what has changed?</em> Every status update and every comment arrives because that job ran.
                    </p>
                    <p><strong>Nothing on this page works until it is scheduled</strong>, and a new install has nothing scheduled.</p>
                    <p class="help-muted">On Windows, in a Command Prompt opened as administrator (adjust the paths for your install):</p>
                    <span class="help-code">schtasks /create /tn "FreeITSM Tracker Poll" /tr "C:\wamp64\bin\php\php8.4.0\php.exe C:\wamp64\www\freeitsm-app\cron\integration_poll.php" /sc minute /mo 5 /ru SYSTEM</span>
                    <p class="help-muted">On Linux, in your crontab:</p>
                    <span class="help-code">*/5 * * * * /usr/bin/php /var/www/freeitsm-app/cron/integration_poll.php &gt;/dev/null 2&gt;&amp;1</span>
                    <p>You can also run it by hand at any time to see what it does — useful while testing:</p>
                    <span class="help-code">php cron/integration_poll.php</span>
                    <div class="help-note">
                        <b>If you skip this</b>
                        Every issue stays frozen at the status it had when it was raised, forever, and no comment
                        ever reaches a ticket. There is no error and nothing in a log — a tracker nobody is asking
                        about looks exactly like one where nothing has happened.
                    </div>
                </div>

                <!-- 5 ───────────────────────────────────────────── -->
                <div class="help-section" id="comments">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3>Let comments come back</h3>
                    </div>
                    <p>
                        Tick <strong>Accept updates from <?php echo htmlspecialchars($name); ?></strong> on the
                        connection (press the pencil on its row — it is under <em>Active</em>). Comments developers
                        write on the issue then arrive on the linked ticket as <strong>internal notes</strong>.
                    </p>
                    <ul>
                        <li><strong>They are always internal.</strong> Somebody writing in <?php echo htmlspecialchars($name); ?> does not know a customer might be reading, so a comment never reaches the requester unless you pass it on yourself.</li>
                        <li><strong>Turning it on does not bring back old comments.</strong> The first check only marks the starting point.</li>
                        <li><strong>You will not get echoes.</strong> Comments FreeITSM posted itself are recognised and never re-imported.</li>
                    </ul>
                    <div class="help-note">
                        <b>Testing it — the order matters</b>
                        Tick the box &rarr; let one check run &rarr; <em>then</em> write a comment in
                        <?php echo htmlspecialchars($name); ?> &rarr; let the next check run. Comment before that
                        first check and it will never appear, because as far as FreeITSM is concerned it happened
                        before it was asked to listen.
                    </div>
                </div>

                <!-- 6 ───────────────────────────────────────────── -->
                <div class="help-section" id="mapping">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3>Mapping — the part worth ten minutes</h3>
                    </div>
                    <p>
                        Every issue has to be filed <em>somewhere</em>. Mapping is where you say where, once,
                        instead of typing it into every rule you ever write. Press the <strong>arrows button</strong>
                        on a connection's row.
                    </p>

                    <?php if ($isJira): ?>
                    <p><strong>First, the vocabulary</strong>, because the screen assumes you know it:</p>
                    <div class="help-table">
                    <table>
                        <tr><th>Jira word</th><th>What it means</th></tr>
                        <tr><td><strong>Project</strong></td><td>A container for issues, usually one per team or product. Every issue lives in exactly one.</td></tr>
                        <tr><td><strong>Project key</strong></td><td>Its short code — the <code>KAN</code> in <code>KAN-6</code>. You will see it at the start of every issue number.</td></tr>
                        <tr><td><strong>Issue type</strong></td><td>What kind of work it is: Task, Bug, Incident, Story. <strong>Each project decides its own list</strong>, so not every project has a "Bug".</td></tr>
                        <tr><td><strong>Priority</strong></td><td>Jira's own urgency scale — often Highest / High / Medium / Low / Lowest, but projects can rename them.</td></tr>
                    </table>
                    </div>
                    <p class="help-muted">Not sure what your projects are called? The dropdowns are filled from your real <?php echo htmlspecialchars($name); ?>, so you can simply look.</p>
                    <?php endif; ?>

                    <p><strong>Which project issues go in.</strong> Set the default and you are done — most installs never need more. The other rows are exceptions, and the most specific one wins:</p>
                    <ul>
                        <li>a <strong>department</strong> rule beats</li>
                        <li>a <strong>company</strong> rule, which beats</li>
                        <li>the <strong>default</strong>.</li>
                    </ul>
                    <p class="help-muted">So "the Development team's tickets go to DEV, everything else goes to our main project" is two rows.</p>

                    <p><strong>Ticket type becomes issue type.</strong> Your <em>Fault</em> becomes their <em>Bug</em>, and so on. Set the default to whatever is safest — <?php echo $isJira ? '<code>Task</code> exists in almost every project' : 'the most generic type'; ?> — and add exceptions only if you need them.</p>

                    <p><strong>Priority.</strong> Your urgency levels translated to theirs. Two things are deliberately different here:</p>
                    <ul>
                        <li><strong>There is no "everything else" row.</strong> Marking a development team's entire backlog urgent helps nobody, so anything you do not map is simply not sent — it still appears as text in the description.</li>
                        <li><strong>A priority they reject will not lose your issue.</strong> Priorities are defined per project, so a project that renamed things may refuse yours. Rather than fail, FreeITSM raises the issue <em>without</em> a priority and notes why.</li>
                    </ul>

                    <div class="help-note">
                        <b>You can ignore all of this</b>
                        Mapping is optional. Skip it and you simply type a project key and issue type each time you
                        escalate. Fill in the default and that stops.
                    </div>
                </div>

                <!-- 7 ───────────────────────────────────────────── -->
                <div class="help-section" id="raise">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3>Raise an issue</h3>
                    </div>
                    <p><strong>By hand:</strong> open a ticket, and in the <strong>Links</strong> strip choose
                       <strong>Link to&hellip; &rarr; Issue tracker</strong>. Read the preview — it is exactly what the
                       development team will see — then press <strong>Raise</strong>.</p>
                    <p><strong>By rule:</strong> escalation is a <strong>Workflows</strong> action, so you can write
                       <em>"when a ticket's type becomes Bug, raise it in <?php echo htmlspecialchars($name); ?>"</em>.
                       In Workflows, add the action <strong>Escalate to issue tracker</strong>. Leave <em>Project key</em>
                       and <em>Issue type</em> blank to use your mapping.</p>
                    <ul>
                        <li>Keep <strong>Skip if this ticket already has an issue</strong> ticked, or a trigger that fires repeatedly will hand the developers a pile of duplicates.</li>
                        <li><strong>Dry run is safe</strong> — it describes what it would raise without creating anything.</li>
                    </ul>
                    <div class="help-note">
                        <b>You cannot unsend it</b>
                        An issue is visible to everyone with access to that project and FreeITSM cannot withdraw it.
                        That is why there is a preview. Internal notes are never included — only the requester's own
                        words and the ticket's details.
                    </div>
                </div>

                <!-- 8 ───────────────────────────────────────────── -->
                <div class="help-section" id="trouble">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3>If something is not working</h3>
                    </div>
                    <div class="help-table">
                    <table>
                        <tr><th>What you see</th><th>What it usually means</th></tr>
                        <tr><td>Nothing ever changes status</td><td>The scheduled check is not running. Step 4 — by far the most common cause.</td></tr>
                        <tr><td>Status updates, but no comments</td><td><strong>Accept updates</strong> is not ticked on that connection. Its row shows an <em>Updates on</em> badge when it is.</td></tr>
                        <tr><td>Ticked it, but my test comment never arrived</td><td>It was probably written before the first check. Write a fresh one and wait — step 5.</td></tr>
                        <tr><td>"rejected the credentials"</td><td>Wrong email, or the token has been revoked. Create a new token and press Test again.</td></tr>
                        <tr><td>Something "&hellip;is required" naming a field</td><td>That project demands a field FreeITSM did not send — often an Epic Link. Ask whoever administers it to make the field optional, or use a different project.</td></tr>
                        <tr><td>"doesn't exist or you don't have permission"</td><td>Wrong project key, or the account behind the token cannot see that project.</td></tr>
                        <tr><td>"belongs to a different company than this ticket"</td><td>The tracker is reserved for one client and this ticket belongs to another. Deliberate, and cannot be overridden.</td></tr>
                        <tr><td>The mapping screen says to run Database Verification</td><td>Mapping needs a table this install has not created yet. System &rarr; Database Verify.</td></tr>
                    </table>
                    </div>
                </div>

                <?php endif; ?>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy, lifted from tickets/help.php so both behave identically:
        // the sections scroll inside .help-main, never the page.
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el: el });
        });

        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections.length ? sections[0].id : null;
            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(l => l.classList.toggle('active', l.dataset.section === current));
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
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
