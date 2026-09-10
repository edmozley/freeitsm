<?php
/**
 * Tickets — Automatic emails & signatures Admin Guide
 * Standalone deep-dive linked from the main tickets help page (Settings section).
 * Covers merge codes and [ticket_url], the public web address, limiting templates
 * to particular senders, the simulator, and per-analyst signatures.
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
    <title>Automatic emails &amp; signatures — Admin Guide</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=68">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--accent-accent);
            --accent-hover: var(--accent-accent-hover);
            --accent-soft:  var(--accent-accent-soft);
            --on-accent:    var(--accent-on-accent);
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="help-container">
    <!-- Left pane navigation -->
    <div class="help-sidebar">
        <a href="help.php" class="help-back">&larr; Back to Tickets help</a>
        <h3>Automatic emails &amp; signatures</h3>
        <a href="#overview" class="help-nav-link active" data-section="overview">
            <span class="help-nav-num">1</span> Overview
        </a>
        <a href="#merge-codes" class="help-nav-link" data-section="merge-codes">
            <span class="help-nav-num">2</span> Merge codes
        </a>
        <a href="#web-address" class="help-nav-link" data-section="web-address">
            <span class="help-nav-num">3</span> The public web address
        </a>
        <a href="#senders" class="help-nav-link" data-section="senders">
            <span class="help-nav-num">4</span> Limiting to certain senders
        </a>
        <a href="#simulator" class="help-nav-link" data-section="simulator">
            <span class="help-nav-num">5</span> Checking before you trust it
        </a>
        <a href="#not-sent" class="help-nav-link" data-section="not-sent">
            <span class="help-nav-num">6</span> When nothing is sent
        </a>
        <a href="#signatures" class="help-nav-link" data-section="signatures">
            <span class="help-nav-num">7</span> Signatures
        </a>
        <a href="#troubleshooting" class="help-nav-link" data-section="troubleshooting">
            <span class="help-nav-num">8</span> Troubleshooting
        </a>
    </div>

    <!-- Main content -->
    <div class="help-main" id="helpMain">
        <div class="help-hero">
            <h2>Automatic emails &amp; signatures</h2>
            <p>What FreeITSM sends on its own, who receives it, and how your team signs off.</p>
        </div>

        <div class="help-content">

            <!-- 1 -->
            <div class="help-section" id="overview">
                <div class="help-section-header">
                    <span class="help-section-num">1</span>
                    <div>
                        <h3>Overview</h3>
                        <p>Two different kinds of email, set up in two different places.</p>
                    </div>
                </div>
                <div class="help-list">
                    <div><strong>Email templates</strong> — what FreeITSM sends <em>by itself</em> when something happens: a ticket arrives by email, is assigned, is closed, or a satisfaction survey goes out. Set under <strong>Tickets &rarr; Settings &rarr; Email templates</strong>.</div>
                    <div><strong>Signatures</strong> — how each analyst signs off a reply <em>they</em> write. Each person writes their own, under <strong>their name &rarr; Preferences</strong>.</div>
                </div>
                <p class="help-note">The difference matters when something looks wrong. If a customer got a reply nobody wrote, that is a template. If a reply is signed by the wrong person, that is a signature.</p>
            </div>

            <!-- 2 -->
            <div class="help-section" id="merge-codes">
                <div class="help-section-header">
                    <span class="help-section-num">2</span>
                    <div>
                        <h3>Merge codes</h3>
                        <p>Placeholders that fill themselves in when the email is sent.</p>
                    </div>
                </div>
                <p>Write a code in square brackets and FreeITSM replaces it as the email goes out. The full list is shown under the message box while you are editing a template.</p>
                <div class="help-code">Hi [requester_first_name],<br><br>Thanks &mdash; we've logged this as [ticket_reference] and someone will be in touch.<br><br>You can track it here: [ticket_url]</div>
                <p><strong>[ticket_url]</strong> is a link straight to the requester's own view of their ticket in the self-service portal, so they can click through instead of going to find it. Somebody who is not signed in lands on the portal login page with the ticket remembered, and arrives where they meant to.</p>
                <p>The <strong>Preview</strong> tab beside the editor shows the email with every code filled in with sample values, so you can read it as the customer will.</p>
                <p class="help-note">The message box has formatting buttons &mdash; bold, italic, lists, links. The <strong>&lt;&gt;</strong> button is still there if you would rather write the HTML yourself, for a styled button or a layout the toolbar cannot produce.</p>
            </div>

            <!-- 3 -->
            <div class="help-section" id="web-address">
                <div class="help-section-header">
                    <span class="help-section-num">3</span>
                    <div>
                        <h3>The public web address</h3>
                        <p>One setting, without which links in automatic emails do not work.</p>
                    </div>
                </div>
                <p>A link in an email has to carry your whole web address. FreeITSM can usually work that out from the browser &mdash; but <strong>most automatic email is sent when nobody is using FreeITSM</strong>. The mail collector runs on a schedule, notices a new message, opens a ticket and sends the acknowledgement, with no browser involved at all. There is nothing to work an address out from, so a link built at that moment has no site name in it.</p>
                <p>So set it once, at the top of the <strong>Email templates</strong> screen:</p>
                <div class="help-table">
                <table>
                    <tr><th>Your installation</th><th>What to enter</th></tr>
                    <tr><td>https://itsm.example.com/</td><td><code>https://itsm.example.com</code></td></tr>
                    <tr><td>https://example.com/freeitsm/</td><td><code>https://example.com/freeitsm</code></td></tr>
                </table>
                </div>
                <p class="help-note"><strong>Include the folder</strong> if FreeITSM is not at the root of the site. That is the part people leave off, and it is the part that breaks the link.</p>
                <p>If a template uses <code>[ticket_url]</code> and nothing has been set, the screen says so. The warning can be dismissed &mdash; it is legitimately fine on an installation whose templates are only ever sent by somebody pressing a button.</p>
            </div>

            <!-- 4 -->
            <div class="help-section" id="senders">
                <div class="help-section-header">
                    <span class="help-section-num">4</span>
                    <div>
                        <h3>Limiting a template to certain senders</h3>
                        <p>So external senders stop receiving an internal-sounding reply.</p>
                    </div>
                </div>
                <p>Each template can be set to <strong>Everyone</strong>, or to <strong>only these senders</strong> &mdash; an email address, a whole domain, or several of either. Type <code>someone@example.com</code> for one person or <code>example.com</code> for everybody at a company.</p>
                <p><strong>The order templates are listed in does not matter.</strong> The most specific one wins:</p>
                <div class="help-flow">
                    <div class="help-flow-step">An exact address<br><code>alerts@a.com</code></div>
                    <div class="help-flow-arrow">beats</div>
                    <div class="help-flow-step">A domain<br><code>a.com</code></div>
                    <div class="help-flow-arrow">beats</div>
                    <div class="help-flow-step">Everyone<br><em>no rules set</em></div>
                </div>
                <p>So a template written for one customer's domain automatically beats your general one, and you cannot break it by dragging rows into the wrong order.</p>
                <p class="help-note"><strong>A new template applies to everyone until you narrow it.</strong> That is deliberate: it means your installation always has a catch-all unless somebody deliberately removes it.</p>
            </div>

            <!-- 5 -->
            <div class="help-section" id="simulator">
                <div class="help-section-header">
                    <span class="help-section-num">5</span>
                    <div>
                        <h3>Checking before you trust it</h3>
                        <p>Type an address, see exactly what would happen.</p>
                    </div>
                </div>
                <p><strong>Check what a sender would get</strong>, on the same screen, takes an email address and names the template that would go back and why:</p>
                <div class="help-code">someone@a.com &rarr; "Customer reply" would be sent, because a rule names the domain @a.com.<br><br>someone@c.com &rarr; No reply would be sent. This address matches none of the templates for that event, and none of them applies to everyone.</div>
                <p>It uses the same code that will choose the template for real, so the answer cannot disagree with what actually happens.</p>
                <p>Worth trying an address you have <em>not</em> written a rule for &mdash; that is the case that goes wrong quietly.</p>
            </div>

            <!-- 6 -->
            <div class="help-section" id="not-sent">
                <div class="help-section-header">
                    <span class="help-section-num">6</span>
                    <div>
                        <h3>When nothing is sent</h3>
                        <p>The problem that only appears months later.</p>
                    </div>
                </div>
                <p>Restricting templates makes it possible for a sender to match none of them. Picture it: two templates, one for <code>@a.com</code> and one for <code>@b.com</code>, both correct, everybody happy. Twelve months later a new customer at <code>c.com</code> is taken on by somebody who has never seen this screen. They match nothing. They hear nothing back. Nothing is broken and there is no error to search for.</p>
                <p>So when that happens it is <strong>written down at the moment it happens</strong>. Open the mailbox's activity view, <strong>Outbound</strong> tab, and it is there:</p>
                <div class="help-table">
                <table>
                    <tr><th>To</th><th>Sent by</th><th>Result</th></tr>
                    <tr><td>someone@c.com</td><td>Ticket template</td><td><strong>Not sent</strong></td></tr>
                </table>
                </div>
                <p>with the reason underneath: <em>&ldquo;No email template applies to this sender.&rdquo;</em></p>
                <p class="help-note"><strong>Not sent</strong> is not a failure and does not count towards the red badge on the tab. Nothing went wrong &mdash; and nothing arrived either, which is exactly the combination that is otherwise impossible to find out about.</p>
            </div>

            <!-- 7 -->
            <div class="help-section" id="signatures">
                <div class="help-section-header">
                    <span class="help-section-num">7</span>
                    <div>
                        <h3>Signatures</h3>
                        <p>Each analyst writes their own, under their name &rarr; Preferences.</p>
                    </div>
                </div>
                <p>Signatures are <strong>personal</strong>. There is no shared or install-wide signature, and nobody &mdash; including administrators &mdash; can see or change anybody else's. A signature is a person signing their own name.</p>
                <div class="help-steps">
                    <div class="help-step"><span class="help-step-num">1</span><div>Fill in <strong>My details</strong> first &mdash; job title, department, phone, mobile. These are what a signature can merge, and with them empty the preview looks like nothing is happening.</div></div>
                    <div class="help-step"><span class="help-step-num">2</span><div>Under <strong>Email signatures</strong>, add one. Use <code>[my_name]</code>, <code>[my_job_title]</code>, <code>[my_phone]</code> and so on &mdash; there are buttons to insert them.</div></div>
                    <div class="help-step"><span class="help-step-num">3</span><div>The preview shows it filled in as you type, including details you have typed but not yet saved.</div></div>
                </div>
                <p><strong>You can keep more than one</strong> &mdash; a formal one for customers and a short one for colleagues, or one per language if you answer in more than one. Exactly one is the <strong>Default</strong>, and that is the one used automatically, so if you only want a single signature you never have to choose.</p>
                <p>Open a reply and your default signature is already in the editor, with a blank line above it to write in. A <strong>Signature</strong> button sits beside <strong>Templates</strong> above the message box: it swaps the one in the draft for another, or takes it out for that one email.</p>
                <p class="help-note">It is put <strong>into the editor rather than added when you press Send</strong>, on purpose &mdash; so you can read it, change it or delete it, and what you see in front of you is what the customer receives.</p>
                <p>A code you have no value for is removed rather than left showing, so an empty mobile number does not leave <code>[my_mobile]</code> at the foot of every email you send. Note that punctuation around it is left alone: if you write <code>[my_phone] | [my_mobile]</code> and have no mobile, you get a trailing <code>|</code>. The preview shows you exactly that.</p>
            </div>

            <!-- 8 -->
            <div class="help-section" id="troubleshooting">
                <div class="help-section-header">
                    <span class="help-section-num">8</span>
                    <div>
                        <h3>Troubleshooting</h3>
                        <p>What each symptom usually means.</p>
                    </div>
                </div>
                <div class="help-table">
                <table>
                    <tr><th>Symptom</th><th>Usual cause</th></tr>
                    <tr><td>A customer says they never got an acknowledgement</td><td>Check the mailbox's <strong>Outbound</strong> tab for a <strong>Not sent</strong> row against their address &mdash; their domain probably matches no template. Add a template set to Everyone.</td></tr>
                    <tr><td>The link in an automatic email is broken</td><td>No public web address set (section 3). Links built while you click around look fine; the ones sent overnight do not.</td></tr>
                    <tr><td>A second template you added never seems to be used</td><td>Only one template is sent per event. Use <strong>Check what a sender would get</strong> to see which one wins and why.</td></tr>
                    <tr><td>A reply is signed with the wrong person's name</td><td>A signature using <code>[analyst_name]</code> rather than <code>[my_name]</code>. <code>[analyst_name]</code> means the ticket's owner, not whoever is typing.</td></tr>
                    <tr><td>No signature appears in a reply</td><td>None marked Default, or none written yet &mdash; check under your name &rarr; Preferences.</td></tr>
                    <tr><td>Two sign-offs in one email</td><td>The signature is in the editor where you can see it &mdash; delete whichever you do not want before sending.</td></tr>
                </table>
                </div>
                <p class="help-note">Deeper write-ups, kept up to date, live on the wiki: <a href="https://github.com/edmozley/freeitsm/wiki/Email-Template-Sender-Rules" target="_blank" rel="noopener">Limiting replies to particular senders</a>, <a href="https://github.com/edmozley/freeitsm/wiki/Public-Web-Address" target="_blank" rel="noopener">The public web address</a> and <a href="https://github.com/edmozley/freeitsm/wiki/Email-Signatures" target="_blank" rel="noopener">Email signatures</a>.</p>
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
