<?php
/**
 * Tickets — Calendar Sync Guide
 * Standalone deep-dive linked from the main tickets help page (Calendar section).
 * Covers the two routes out (subscription link vs Microsoft 365), the admin
 * connection, what each analyst turns on for themselves, changes coming back in,
 * the cron-vs-notifications choice, and troubleshooting.
 *
 * English only, like the other deep-dive help pages beside it.
 *
 * ⚠️ No `body { --accent: var(--accent-accent) }` block. The three help pages
 * next to this one all carry it and it has never done anything — --accent-accent
 * is defined nowhere in the product, so the declaration is invalid and --accent
 * falls back to the theme's own value. Copying it here would only spread dead
 * code; omitting it renders identically.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar Sync — Guide</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=69">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="help-container">
    <!-- Left pane navigation -->
    <div class="help-sidebar">
        <a href="help.php" class="help-back">&larr; Back to Tickets help</a>
        <h3>Calendar Sync</h3>
        <a href="#overview" class="help-nav-link active" data-section="overview">
            <span class="help-nav-num">1</span> Overview
        </a>
        <a href="#choices" class="help-nav-link" data-section="choices">
            <span class="help-nav-num">2</span> Which route to use
        </a>
        <a href="#feed" class="help-nav-link" data-section="feed">
            <span class="help-nav-num">3</span> Subscription link (any calendar)
        </a>
        <a href="#connection" class="help-nav-link" data-section="connection">
            <span class="help-nav-num">4</span> Microsoft 365 connection
        </a>
        <a href="#mailboxes" class="help-nav-link" data-section="mailboxes">
            <span class="help-nav-num">5</span> Which mailbox is whose
        </a>
        <a href="#switch-on" class="help-nav-link" data-section="switch-on">
            <span class="help-nav-num">6</span> Turning it on for yourself
        </a>
        <a href="#tasks" class="help-nav-link" data-section="tasks">
            <span class="help-nav-num">7</span> Tasks in your calendar
        </a>
        <a href="#inbound" class="help-nav-link" data-section="inbound">
            <span class="help-nav-num">8</span> Changes coming back
        </a>
        <a href="#cron-vs-notify" class="help-nav-link" data-section="cron-vs-notify">
            <span class="help-nav-num">9</span> Scheduled job vs notifications
        </a>
        <a href="#scheduling-the-job" class="help-nav-link" data-section="scheduling-the-job">
            <span class="help-nav-num">10</span> Setting up the scheduled job
        </a>
        <a href="#health" class="help-nav-link" data-section="health">
            <span class="help-nav-num">11</span> Is it working?
        </a>
        <a href="#troubleshooting" class="help-nav-link" data-section="troubleshooting">
            <span class="help-nav-num">12</span> Troubleshooting
        </a>
    </div>

    <!-- Main content -->
    <div class="help-main" id="helpMain">
        <div class="help-hero">
            <h2>Calendar Sync</h2>
            <p>Getting the tickets you have scheduled out of FreeITSM and into the calendar you actually look at.</p>
        </div>

        <div class="help-content">

            <!-- 1. Overview -->
            <div class="help-section" id="overview">
                <div class="help-section-header">
                    <span class="help-section-num">1</span>
                    <div>
                        <h3>Overview</h3>
                        <p>What this does, and the choices involved in setting it up.</p>
                    </div>
                </div>
                <p>When you schedule a ticket &mdash; <strong>Schedule work</strong> in the inbox, or by dragging a job around <strong>Tickets &rarr; Calendar</strong> &mdash; FreeITSM records when you intend to do it. Calendar sync puts that same information into the calendar you use for everything else, so your day is in one place rather than two.</p>
                <p>There are <strong>two routes out</strong>, and they are not alternatives so much as different levels of ambition:</p>
                <div class="help-list">
                    <div><strong>A subscription link</strong> &mdash; a private URL your calendar app reads. Works with Google, Apple, Outlook, Thunderbird, anything. Nothing to configure and no administrator involvement.</div>
                    <div><strong>Microsoft 365</strong> &mdash; FreeITSM writes real appointments into your Exchange mailbox using the Microsoft Graph API. Richer, faster, two-way, and it needs an administrator to connect it once.</div>
                </div>
                <p>Both are per analyst, and <strong>each analyst chooses for themselves</strong> under <strong>Preferences &rarr; General &rarr; My work calendar</strong>. An administrator sets up what is <em>possible</em>; they never switch it on for somebody else.</p>
                <p class="help-note">In a hurry? If you are not a Microsoft 365 organisation, use the subscription link &mdash; it needs no setup at all. If you are, the Microsoft 365 route is worth the ten minutes.</p>
            </div>

            <!-- 2. Which route -->
            <div class="help-section" id="choices">
                <div class="help-section-header">
                    <span class="help-section-num">2</span>
                    <div>
                        <h3>Which route to use</h3>
                        <p>Both are first-class. The difference is what your calendar can do with the result.</p>
                    </div>
                </div>

                <div class="help-table">
                <table>
                    <tr><th style="width:28%;"></th><th>Subscription link (iCalendar)</th><th>Microsoft 365 (Graph)</th></tr>
                    <tr><td><strong>Works with</strong></td><td>Any calendar app</td><td>Exchange / Microsoft 365 mailboxes</td></tr>
                    <tr><td><strong>Admin setup</strong></td><td>None</td><td>One connection, once</td></tr>
                    <tr><td><strong>How fast</strong></td><td>Whenever your calendar app refreshes &mdash; often <strong>hours</strong>, and not under our control</td><td><strong>Immediately</strong> when the ticket changes</td></tr>
                    <tr><td><strong>Marks you busy</strong></td><td>No &mdash; it is a read-only overlay</td><td>Yes &mdash; a real appointment</td></tr>
                    <tr><td><strong>Direction</strong></td><td>Out of FreeITSM only</td><td>Both ways, if enabled</td></tr>
                    <tr><td><strong>Edit in your calendar</strong></td><td>No</td><td>Yes &mdash; move it and the ticket follows</td></tr>
                    <tr><td><strong>Needs internet access</strong></td><td>Only from your calendar app to FreeITSM</td><td>From FreeITSM out to Microsoft</td></tr>
                </table>
                </div>

                <div class="help-card">
                    <span class="help-pill info">Use the subscription link when</span>
                    <p>Your organisation is not on Microsoft 365; or you want your scheduled work on your phone today without waiting for anybody; or FreeITSM has no route out to the internet.</p>
                </div>
                <div class="help-card">
                    <span class="help-pill info">Use Microsoft 365 when</span>
                    <p>Your analysts have Exchange mailboxes and you want scheduled work to behave like everything else in their diary &mdash; blocking time, syncing to their phone, and rearranged from either side.</p>
                </div>
                <p class="help-note">They are not mutually exclusive at an organisation level &mdash; some analysts can use one and some the other. Each person picks a single option for themselves.</p>
            </div>

            <!-- 3. Subscription link -->
            <div class="help-section" id="feed">
                <div class="help-section-header">
                    <span class="help-section-num">3</span>
                    <div>
                        <h3>Subscription link &mdash; works with any calendar</h3>
                        <p>The universal option. No administrator, no Microsoft account, no configuration.</p>
                    </div>
                </div>
                <p>Go to <strong>Preferences &rarr; General &rarr; My work calendar</strong> and choose <strong>Subscribe link</strong>, then <strong>Get my link</strong>. You will be given a private URL and a QR code &mdash; scanning the code with your phone is usually the fastest way to get there.</p>

                <div class="help-steps">
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div><strong>New Outlook / Outlook on the web</strong> &mdash; Calendar &rarr; <strong>Add calendar</strong> &rarr; <strong>Subscribe from web</strong>, paste the link, give it a name, <strong>Import</strong>.</div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div><strong>Google Calendar</strong> &mdash; Other calendars &rarr; <strong>+</strong> &rarr; <strong>From URL</strong>, paste, <strong>Add calendar</strong>.</div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div><strong>Apple Calendar / iPhone</strong> &mdash; File &rarr; <strong>New Calendar Subscription</strong> (or Settings &rarr; Calendar &rarr; Accounts &rarr; Add &rarr; Other &rarr; Add Subscribed Calendar), paste, done.</div>
                    </div>
                </div>

                <div class="help-card">
                    <span class="help-pill warn">The link is a secret, not a login</span>
                    <p>Anyone holding the URL can read your scheduled work without signing in. Treat it like a password and do not forward it. <strong>Reset</strong> revokes every copy of it instantly &mdash; including on devices you have already set up, which will stop updating until you give them the new one.</p>
                </div>
                <div class="help-card">
                    <span class="help-pill bad">If FreeITSM is not on HTTPS</span>
                    <p>The page will say so plainly. Without HTTPS the link and everything it shows travel unprotected across the network every time your calendar refreshes &mdash; which for a calendar app is constantly, unattended, wherever the device happens to be. Do not use it outside a trusted network until HTTPS is enabled.</p>
                </div>

                <p><strong>What it publishes.</strong> Under <strong>What the calendar shows</strong> you can choose <em>Ticket number and subject</em> or <em>Ticket number only</em>. The second exists for exactly one reason: it lets the shape of your day leave the building without the detail of it.</p>
                <p>An administrator can set a ceiling for everybody under <strong>System &rarr; Calendar sync &rarr; Subscription links</strong> &mdash; allowing subjects, restricting everyone to ticket numbers, or switching links off entirely. <strong>Switching them off revokes existing links</strong> rather than merely hiding the option.</p>
                <p class="help-note">Refresh rate is up to your calendar app and is frequently slow &mdash; Outlook in particular can take several hours and offers no way to hurry it. That is a property of calendar subscriptions everywhere, not of FreeITSM. If you need changes to appear promptly, that is what the Microsoft 365 route is for.</p>
            </div>

            <!-- 4. Connection -->
            <div class="help-section" id="connection">
                <div class="help-section-header">
                    <span class="help-section-num">4</span>
                    <div>
                        <h3>Microsoft 365 &mdash; connecting FreeITSM</h3>
                        <p>An administrator does this once, at <strong>System &rarr; Calendar sync</strong>.</p>
                    </div>
                </div>
                <p>FreeITSM writes appointments using <strong>app-only</strong> authentication &mdash; it authenticates as itself with a client ID and secret, and writes directly into each analyst's mailbox. <strong>There is no sign-in for each analyst</strong>, no consent screen, and no per-person tokens to maintain or renew.</p>

                <p><strong>Step one: credentials.</strong> Under <strong>Connection</strong> you have two options:</p>
                <div class="help-list">
                    <div><strong>Borrow the credentials from a mailbox</strong> &mdash; reuse the Azure app registration you already set up for reading email. This is the normal answer, and it means nothing new to register.</div>
                    <div><strong>Enter credentials</strong> &mdash; a separate tenant ID, client ID and secret. Needed only if the calendars you are writing to live in a different tenant from your intake mailbox, which is unusual but real.</div>
                </div>

                <p><strong>Step two: the permission.</strong> In Azure, the app registration needs <code>Calendars.ReadWrite</code> as an <strong>Application</strong> permission, with admin consent granted.</p>
                <div class="help-card">
                    <span class="help-pill warn">Application, not Delegated</span>
                    <p>Azure lists these separately, and the Delegated list contains a permission with the same name. Picking the wrong list is the single most common setup mistake &mdash; everything looks correct and nothing works. Check it appears under <strong>Application permissions</strong> and shows <strong>Granted</strong>.</p>
                </div>
                <div class="help-card">
                    <span class="help-pill bad">Understand what you are granting</span>
                    <p><code>Calendars.ReadWrite</code> as an Application permission reaches <strong>every mailbox in the tenant</strong>, not only the analysts you list here. That is how app-only permissions work. If that is broader than you want, restrict it in Exchange with an <strong>Application Access Policy</strong> scoped to a mail-enabled security group containing just your analysts.</p>
                </div>

                <p><strong>Step three: test.</strong> The <strong>Test</strong> button answers two separate questions, deliberately &mdash; whether the credentials and permission work at all, and whether one particular mailbox can be reached. Keeping them apart means a failure points at its own fix rather than leaving you guessing which half is wrong.</p>
                <p class="help-note">A calendar problem can never stop you scheduling a ticket. If Microsoft is unreachable the save still succeeds and the schedule is still recorded &mdash; the failure is reported on the settings screen rather than in your way.</p>
            </div>

            <!-- 5. Mailboxes -->
            <div class="help-section" id="mailboxes">
                <div class="help-section-header">
                    <span class="help-section-num">5</span>
                    <div>
                        <h3>Which mailbox is whose</h3>
                        <p>The step that is easy to skip and then puzzling to debug.</p>
                    </div>
                </div>
                <p>By default FreeITSM assumes an analyst's calendar lives at the email address on their FreeITSM account. <strong>That is frequently wrong.</strong> An account created locally might be <code>admin@local</code>; a directory import might be keyed on a payroll address; a person's sign-in name and their mailbox are often simply different things.</p>
                <p>So <strong>System &rarr; Calendar sync &rarr; Which mailbox each analyst syncs to</strong> lists every active analyst and lets you set the real one. Addresses FreeITSM is merely <em>assuming</em> are shown greyed out; ones somebody actually chose are shown normally. The distinction is the point &mdash; you can see at a glance which have been confirmed.</p>
                <div class="help-card">
                    <span class="help-pill warn">Aliases</span>
                    <p>Use the address the mailbox actually answers to. An alias frequently works, but if a test reports that no calendar could be found for an address that plainly exists, try the primary address instead &mdash; that is usually the reason.</p>
                </div>
            </div>

            <!-- 6. Switch on -->
            <div class="help-section" id="switch-on">
                <div class="help-section-header">
                    <span class="help-section-num">6</span>
                    <div>
                        <h3>Turning it on for yourself</h3>
                        <p>The administrator makes it possible; you decide whether it happens.</p>
                    </div>
                </div>
                <p>Go to <strong>Preferences &rarr; General &rarr; My work calendar</strong> and choose <strong>Add to my calendar</strong>. FreeITSM tells you which mailbox it will write to before you commit.</p>
                <div class="help-card">
                    <span class="help-pill info">Nobody can switch this on for you</span>
                    <p>Writing into somebody's personal calendar is not a decision an administrator should be making on their behalf, so there is no way for them to do it. It is deliberately the analyst's own choice.</p>
                </div>
                <p><strong>What happens when you turn it on.</strong> Everything you already have scheduled is written into your calendar &mdash; it does not only affect tickets you touch from now on. <strong>Turning it off takes it all back out again</strong>, rather than abandoning a pile of entries for you to delete by hand.</p>
                <p><strong>From then on it follows the ticket.</strong> Move it to Thursday and the appointment moves. Hand it to a colleague and it <strong>leaves your calendar and appears in theirs</strong>. Close, delete or unschedule it and the appointment disappears &mdash; a calendar should say what you are <em>going</em> to do, not accumulate everything you have ever finished.</p>
            </div>

            <!-- 7. Tasks -->
            <div class="help-section" id="tasks">
                <div class="help-section-header">
                    <span class="help-section-num">7</span>
                    <div>
                        <h3>Tasks in your calendar</h3>
                        <p>A task can put two different things in your diary, and they are not the same kind of fact.</p>
                    </div>
                </div>

                <p>Once you have chosen <strong>Add to my calendar</strong>, a second choice appears underneath it: <strong>Tasks in this calendar</strong>. It starts at <strong>None</strong>, so nothing about your tasks leaves FreeITSM until you say so.</p>

                <div class="help-cards">
                    <div class="help-card">
                        <h4>When I plan to do it</h4>
                        <p>The <strong>work window</strong> you set aside on the task, written as an ordinary timed appointment. It blocks out time, exactly as a scheduled ticket does.</p>
                    </div>
                    <div class="help-card">
                        <h4>When it is due</h4>
                        <p>The task's <strong>due date</strong>, written as an all-day entry titled <strong>Due: &hellip;</strong> so a deadline is never mistaken for a booked slot.</p>
                    </div>
                </div>

                <p><strong>Why it is two choices and not one switch.</strong> A work window is a <em>plan</em> and a due date is a <em>commitment</em>, and most tasks only ever get the second. Somebody who wants their deadlines in front of them does not necessarily want every half-hour they have blocked out, and the reverse is just as reasonable. Choose <strong>Both</strong> and a task with both dates appears twice, deliberately.</p>

                <p class="help-note"><strong>Only tasks assigned to you.</strong> A task assigned to a <em>team</em> is never added to anybody's calendar &mdash; there is no one person's diary it belongs in, and writing it into everybody's would fill several calendars with an entry nobody owns. Assign it to a person and it appears in theirs.</p>

                <p><strong>Completed tasks are removed</strong>, the same as closed tickets. Clearing a due date, reassigning the task, or changing your mind about which kinds you want all take the entries back out again rather than leaving them behind.</p>

                <div class="help-card">
                    <span class="help-pill info">Moving a task's entry in Outlook moves the task</span>
                    <p>Drag its appointment and the work window follows. Drag its <strong>Due:</strong> entry to another day and <strong>the due date itself changes</strong> &mdash; so treat that entry as the deadline, not as a note about it. Every change made from a calendar is written to the task's history with the mailbox it came from, so a deadline that moves can always be traced.</p>
                </div>

                <p class="help-note warn"><strong>Changes only come back if the scheduled job is running.</strong> This is the same job the rest of this page describes, and it is the commonest reason a change in Outlook appears not to have worked. See <a href="#scheduling-the-job">Setting up the scheduled job</a>.</p>
            </div>
            <!-- 7. Inbound -->
            <div class="help-section" id="inbound">
                <div class="help-section-header">
                    <span class="help-section-num">8</span>
                    <div>
                        <h3>Changes coming back from your calendar</h3>
                        <p>Optional, and off until you set up the scheduled job.</p>
                    </div>
                </div>
                <p>With the scheduled job running, moving one of these appointments in Outlook <strong>moves the ticket</strong>. And if an administrator enables it, deleting the appointment <strong>unschedules the ticket</strong>.</p>
                <p>The case this was built for: you are on the train, you look at your phone, and a job in your calendar turns out not to be needed. Delete it there, and by the time you sit down at your desk FreeITSM is already clean.</p>

                <p><strong>Accepting deletions is off by default</strong> &mdash; <strong>System &rarr; Calendar sync &rarr; Changes made in the calendar &rarr; &ldquo;Deleting the appointment unschedules the ticket&rdquo;</strong>. Whether tidying a personal calendar should reach work the whole service desk can see is an organisation's decision rather than ours. With it off, a deleted appointment simply reappears the next time the ticket changes.</p>

                <p><strong>Three things protect it:</strong></p>
                <div class="help-list">
                    <div><strong>It never acts on a check that lost its place.</strong> If the connection has to start again from scratch it re-learns where it is and changes nothing &mdash; an empty answer can mean &ldquo;everything was deleted&rdquo; or &ldquo;I have no idea what happened&rdquo;, and only one of those is safe to act on.</div>
                    <div><strong>A large number of deletions at once is refused, not obeyed</strong>, and reported instead. That is far more likely to be a fault than an instruction.</div>
                    <div><strong>Every change is written to the ticket's history</strong>, naming the calendar it came from, so a ticket never silently moves with no record of why.</div>
                </div>
            </div>

            <!-- 8. Cron vs notifications -->
            <div class="help-section" id="cron-vs-notify">
                <div class="help-section-header">
                    <span class="help-section-num">9</span>
                    <div>
                        <h3>Scheduled job vs notifications</h3>
                        <p>Not either/or &mdash; the job is required, and notifications make it fast.</p>
                    </div>
                </div>
                <p>This is the part most worth understanding, because the two are easily mistaken for alternatives.</p>

                <div class="help-table">
                <table>
                    <tr><th style="width:28%;"></th><th>Scheduled job (required)</th><th>Notifications (optional)</th></tr>
                    <tr><td><strong>How it works</strong></td><td>FreeITSM asks Microsoft what changed</td><td>Microsoft calls FreeITSM when something changes</td></tr>
                    <tr><td><strong>Speed</strong></td><td>Your cron interval &mdash; minutes</td><td>Seconds</td></tr>
                    <tr><td><strong>Needs a public address</strong></td><td><strong>No</strong> &mdash; an ordinary outbound request</td><td><strong>Yes</strong> &mdash; reachable HTTPS from the internet</td></tr>
                    <tr><td><strong>Works behind a firewall</strong></td><td>Yes</td><td>No</td></tr>
                    <tr><td><strong>Set up where</strong></td><td>Your server's scheduler</td><td>System &rarr; Calendar sync &rarr; Notification URL</td></tr>
                </table>
                </div>

                <div class="help-card">
                    <span class="help-pill warn">Notifications never replace the job</span>
                    <p>Two reasons, and both matter. First, <strong>subscriptions expire</strong> &mdash; Microsoft caps calendar subscriptions at about three days, and the scheduled job is what renews them. Stop running it and notifications stop a few days later. Second, <strong>notifications go missing</strong>: dropped in transit, lost while the server was restarting, or quietly stopped when a subscription lapsed. A gap in notifications looks exactly like nothing having changed. So the job stays as the backstop and notifications simply make the common case fast.</p>
                </div>
                <p><strong>Leaving the Notification URL blank is a perfectly good answer</strong>, and for a FreeITSM the internet cannot reach it is the only sensible one. Changes then arrive within a few minutes instead of seconds, and nothing else differs.</p>
                <p>If you do fill it in, it must be an HTTPS address Microsoft can reach from the internet, ending <code>/api/calendar/graph_notify.php</code>. The <strong>Use this address</strong> button offers the one you are currently browsing on as a starting point &mdash; check it, because behind a proxy or a tunnel that is routinely not the address Microsoft would use.</p>
            </div>

            <!-- 9. Scheduling the job -->
            <div class="help-section" id="scheduling-the-job">
                <div class="help-section-header">
                    <span class="help-section-num">10</span>
                    <div>
                        <h3>Setting up the scheduled job</h3>
                        <p>Every five minutes is sensible. It both reads changes and renews subscriptions.</p>
                    </div>
                </div>
                <p>The script is <code>cron/calendar_sync_pull.php</code>. Run it as often as you want changes to arrive.</p>

                <p><strong>Linux &mdash; crontab</strong></p>
                <div class="help-code"><pre>*/5 * * * * /usr/bin/php /var/www/freeitsm/cron/calendar_sync_pull.php &gt;&gt; /var/log/freeitsm-calendar-sync.log 2&gt;&amp;1</pre></div>

                <p><strong>Windows &mdash; Task Scheduler.</strong> Put the command in a small batch file rather than into the task itself:</p>
                <div class="help-code"><pre>@echo off
set PHP=C:\path\to\php.exe
set SCRIPT=C:\path\to\freeitsm\cron\calendar_sync_pull.php
set LOG=C:\path\to\logs\freeitsm-calendar-sync.log

echo. &gt;&gt; "%LOG%"
echo ===== %DATE% %TIME% ===== &gt;&gt; "%LOG%"
"%PHP%" "%SCRIPT%" &gt;&gt; "%LOG%" 2&gt;&amp;1</pre></div>
                <p>Then register it:</p>
                <div class="help-code"><pre>schtasks /Create /TN "FreeITSM Calendar Sync" /TR "C:\path\to\freeitsm-calendar-sync.bat" /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F</pre></div>
                <div class="help-card">
                    <span class="help-pill warn">Why a batch file and not the command directly</span>
                    <p><code>schtasks</code> parses the <code>/TR</code> argument itself, and a command containing both quoted paths and a redirect is exactly where its quoting quietly breaks. The result is a task that appears to have been created successfully and does nothing at all. The batch file sidesteps it entirely, and gives you a log to read.</p>
                </div>
                <p><strong>Running it over HTTP instead.</strong> Like the other FreeITSM crons it can be triggered by URL, protected by a shared secret: append <code>?token=</code> with the value of <code>webhook_cron_token</code> from your system settings. Command-line runs skip the token, since there is no untrusted caller.</p>
                <p class="help-note">Not running the job at all costs you nothing you already had &mdash; the sync simply stays one-way, exactly as it works without it. Deleting an appointment in Outlook then just means the next change to that ticket puts a fresh one back.</p>
            </div>

            <!-- 10. Health -->
            <div class="help-section" id="health">
                <div class="help-section-header">
                    <span class="help-section-num">11</span>
                    <div>
                        <h3>Is it working?</h3>
                        <p><strong>System &rarr; Calendar sync</strong> answers this at the top of <strong>Changes made in the calendar</strong>.</p>
                    </div>
                </div>
                <p>Most faults here announce themselves &mdash; a broken connection reports an error, a subscription that will not create reports an error. <strong>One does not:</strong> a scheduled job that has stopped running is indistinguishable from a calendar in which nothing has changed, and it will go on looking healthy for weeks while every change made in Outlook is silently lost.</p>
                <p>So the screen reports <strong>how long since the calendars were last checked</strong>, and warns past half an hour. If you deliberately run the job less often than that, this is expected and it says so &mdash; FreeITSM has no way to know what interval you chose.</p>
                <p>Each analyst also carries a status:</p>
                <div class="help-defs">
                    <div class="help-def"><div class="help-def-term">Syncing</div><div class="help-def-desc">They have turned it on.</div></div>
                    <div class="help-def"><div class="help-def-term">Notified</div><div class="help-def-desc">Microsoft has a live subscription for them, so their changes arrive in seconds. Hover for the renewal date.</div></div>
                    <div class="help-def"><div class="help-def-term">Not subscribed</div><div class="help-def-desc">A notification address is set, but Microsoft has nothing to call for this person &mdash; their changes only arrive when the job runs. The next run will try to create it.</div></div>
                    <div class="help-def"><div class="help-def-term">Subscription lapsed</div><div class="help-def-desc">It expired without being renewed, which normally means the job stopped running.</div></div>
                    <div class="help-def"><div class="help-def-term">Last sync failed</div><div class="help-def-desc">Hover it &mdash; the reason is in the tooltip, and the reasons need entirely different fixes.</div></div>
                </div>
            </div>

            <!-- 11. Troubleshooting -->
            <div class="help-section" id="troubleshooting">
                <div class="help-section-header">
                    <span class="help-section-num">12</span>
                    <div>
                        <h3>Troubleshooting</h3>
                        <p>The failures you are most likely to meet, and what each one actually means.</p>
                    </div>
                </div>

                <div class="help-table">
                <table>
                    <tr><th style="width:38%;">What you see</th><th>What it means</th></tr>
                    <tr>
                        <td><strong>Test says the credentials work but a mailbox cannot be found</strong></td>
                        <td>The address is wrong, or it is an alias rather than the mailbox's primary address. Try the primary address.</td>
                    </tr>
                    <tr>
                        <td><strong>Nothing appears in the calendar at all</strong></td>
                        <td>Check the analyst has actually chosen <strong>Add to my calendar</strong> in their own Preferences. An administrator cannot do this for them, so it is easy to assume it has been done.</td>
                    </tr>
                    <tr>
                        <td><strong>&ldquo;Check the client secret has not expired&rdquo;</strong></td>
                        <td>Usually exactly that &mdash; Azure client secrets expire. Otherwise <code>Calendars.ReadWrite</code> is missing from <strong>Application</strong> permissions, or consent was never granted.</td>
                    </tr>
                    <tr>
                        <td><strong>&ldquo;Subscription validation request failed&rdquo;</strong></td>
                        <td>Microsoft could not reach your Notification URL, or something in front of FreeITSM answered instead. Confirm the address is reachable from the public internet &mdash; not just from your network &mdash; and that it ends <code>/api/calendar/graph_notify.php</code>.</td>
                    </tr>
                    <tr>
                        <td><strong>Notifications worked and then stopped</strong></td>
                        <td>Either the scheduled job stopped renewing the subscription, or the public address changed. A tunnel that hands out a new URL each time it restarts will do this silently &mdash; update the Notification URL when it changes.</td>
                    </tr>
                    <tr>
                        <td><strong>The log says &ldquo;baseline taken (nothing applied)&rdquo;</strong></td>
                        <td>Not a fault. The job had lost its place and has re-learned where it is without changing anything. The next run behaves normally.</td>
                    </tr>
                    <tr>
                        <td><strong>The log says an unusual number of deletions was refused</strong></td>
                        <td>A safety cap. Something removed a lot of appointments at once, which is far more likely to be a fault than an instruction. Check what happened in the mailbox before overriding it.</td>
                    </tr>
                    <tr>
                        <td><strong>&ldquo;Calendars last checked&rdquo; keeps growing</strong></td>
                        <td>The scheduled job is not running. Check the task or cron entry still exists, still fires, and that the account it runs as can execute PHP &mdash; on Windows, security software blocking <code>php.exe</code> for a task running as SYSTEM is a common and silent cause.</td>
                    </tr>
                    <tr>
                        <td><strong>A deleted appointment came back</strong></td>
                        <td>Working as intended, unless <strong>&ldquo;Deleting the appointment unschedules the ticket&rdquo;</strong> is switched on. Without it, FreeITSM owns the schedule and restores what it put there.</td>
                    </tr>
                </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="../assets/js/i18n.js?v=2"></script>
<script>
    // Scroll-spy: highlight active sidebar entry as user scrolls
    const helpMain = document.getElementById('helpMain');
    const navLinks = document.querySelectorAll('.help-nav-link');
    const sections = Array.from(navLinks).map(l => document.getElementById(l.dataset.section)).filter(Boolean);

    function setActive(id) {
        navLinks.forEach(l => l.classList.toggle('active', l.dataset.section === id));
    }

    helpMain.addEventListener('scroll', () => {
        const scrollY = helpMain.scrollTop + 100;
        for (let i = sections.length - 1; i >= 0; i--) {
            if (sections[i].offsetTop <= scrollY) {
                setActive(sections[i].id);
                return;
            }
        }
    });

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const target = document.getElementById(link.dataset.section);
            if (target) {
                helpMain.scrollTo({ top: target.offsetTop - 20, behavior: 'smooth' });
                setActive(link.dataset.section);
            }
        });
    });
</script>
</body>
</html>
