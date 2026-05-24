<?php
/**
 * Self-Service Portal - Help / Guide page
 * Targeted at end users (not analysts). Covers registration, raising tickets,
 * screen recording, viewing tickets, and account/MFA management.
 */
session_start();
require_once '../config.php';
require_once 'includes/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Self-Service Portal &mdash; Help</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }

        .portal-header {
            background: #0078d4;
            color: white;
            padding: 0 24px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .portal-brand { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav { display: flex; align-items: center; gap: 4px; }
        .portal-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .portal-nav a:hover  { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2);  color: white; }

        .ss-help-page {
            max-width: 800px;
            margin: 0 auto;
            padding: 32px 24px 64px;
        }
        .ss-help-page h1 {
            font-size: 28px;
            font-weight: 600;
            color: #222;
            margin: 0 0 6px;
        }
        .ss-help-page p.lede {
            font-size: 15px;
            color: #666;
            line-height: 1.55;
            margin-bottom: 32px;
        }

        .ss-help-section {
            background: white;
            border-radius: 10px;
            padding: 26px 30px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .ss-help-section h2 {
            font-size: 18px;
            font-weight: 600;
            color: #222;
            margin: 0 0 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ss-help-section h2 .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #0078d4;
            color: white;
            font-size: 13px;
        }
        .ss-help-section h3 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 18px 0 8px;
        }
        .ss-help-section p {
            font-size: 14px;
            line-height: 1.6;
            color: #444;
            margin: 0 0 12px;
        }
        .ss-help-section ol, .ss-help-section ul {
            padding-left: 20px;
            margin: 0 0 14px;
        }
        .ss-help-section li {
            font-size: 14px;
            line-height: 1.7;
            color: #444;
            margin-bottom: 4px;
        }
        .ss-help-section .tip {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.55;
            color: #1e3a8a;
            margin: 14px 0 0;
        }
        .ss-help-section code {
            background: #f5f5f5;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span>Self-Service Portal</span>
        </div>
        <nav class="portal-nav">
            <a href="index.php">Dashboard</a>
            <a href="new-ticket.php">New Ticket</a>
            <a href="help.php" class="active">Help</a>
        </nav>
        <?php include 'includes/user-menu.php'; ?>
    </div>

    <div class="ss-help-page">
        <h1>Help &amp; Guide</h1>
        <p class="lede">Everything you need to know about the Self-Service Portal &mdash; how to raise a ticket, attach a screen recording, track progress on existing tickets, and manage your account security.</p>

        <!-- 1. Welcome -->
        <div class="ss-help-section">
            <h2><span class="num">1</span> Welcome</h2>
            <p>This portal is the fastest way to ask the IT team for help. You can raise a new ticket, attach files and screen recordings, view the status of your existing tickets, and read replies from the support team &mdash; all without sending an email.</p>
            <p>Most things you do here will route through the same system the IT team use to manage their work, so your request lands directly in their queue and you'll see updates in near-real-time.</p>
        </div>

        <!-- 2. Signing in -->
        <div class="ss-help-section">
            <h2><span class="num">2</span> Signing in</h2>
            <p>There are three ways your account gets created &mdash; you might not even need to register:</p>
            <ol>
                <li><strong>Self-registration</strong> &mdash; click <em>Register</em> on the sign-in page, enter your work email, name, and a password. You're in.</li>
                <li><strong>"Claiming" an account</strong> &mdash; if you've previously sent the IT team an email and they raised a ticket on your behalf, the system already has your email on file. Registering with that same email will <em>claim</em> the existing account (and link any past tickets to it) rather than create a duplicate.</li>
                <li><strong>Created by IT</strong> &mdash; the team can pre-create an account for you. You'll be told to register with your email and pick a password &mdash; same claim flow as above.</li>
            </ol>
            <p class="tip"><strong>Forgotten password?</strong> Use the <em>Forgot password</em> link on the sign-in page &mdash; you'll get an email with a reset link.</p>
        </div>

        <!-- 3. Raising a ticket -->
        <div class="ss-help-section">
            <h2><span class="num">3</span> Raising a ticket</h2>
            <p>Click <strong>New Ticket</strong> in the top nav. Fill in:</p>
            <ul>
                <li><strong>Mailbox</strong> &mdash; which support queue the ticket should land in (e.g. <em>IT Support</em>, <em>HR</em>). If your organisation only has one, it's pre-selected.</li>
                <li><strong>Subject</strong> &mdash; a short, clear summary of the issue (e.g. <em>"Outlook keeps disconnecting"</em>).</li>
                <li><strong>Priority</strong> &mdash; <em>Low</em>, <em>Normal</em>, or <em>High</em>. The IT team may adjust this based on impact.</li>
                <li><strong>Description</strong> &mdash; the full details. Include what you were trying to do, what happened instead, any error messages, and roughly when it started. More context = faster resolution.</li>
                <li><strong>Attachments</strong> &mdash; screenshots, logs, documents. Drag and drop onto the dropzone, or click to browse.</li>
            </ul>
            <p>Click <strong>Submit</strong>. You'll see a confirmation with your ticket reference (something like <em>LVB-805-40499</em>) &mdash; quote that if you ever need to chase it up.</p>
            <p class="tip">A picture is worth 1000 words. A screenshot or recording (see below) is worth 1000 pictures. Don't be shy &mdash; the more visual context you can attach, the quicker the IT team can identify the problem.</p>
        </div>

        <!-- 4. Screen recording -->
        <div class="ss-help-section">
            <h2><span class="num">4</span> Recording your screen</h2>
            <p>For anything visual &mdash; a UI glitch, a workflow that doesn't behave, an error that flashes by &mdash; record your screen instead of trying to describe it in words. The portal does this natively, no plugins or third-party tools needed.</p>
            <ol>
                <li>On the <strong>New Ticket</strong> form, scroll down to the attachments area and click <strong>Record screen</strong>.</li>
                <li>Tick <strong>Include microphone audio</strong> if you want to narrate the issue out loud as you record &mdash; this is often the most useful thing you can do for the IT team. Off by default for privacy.</li>
                <li>Click <strong>Start</strong>. Your browser will ask which tab, window, or whole screen you'd like to share &mdash; pick one and click <em>Share</em>.</li>
                <li>Demonstrate the issue. Max 5 minutes &mdash; you'll see a live countdown.</li>
                <li>Click <strong>Stop</strong> (or hit the browser's own <em>"Stop sharing"</em> bar &mdash; either works).</li>
                <li>Preview the result. Happy? Click <strong>Use this</strong> to attach. Not happy? Click <strong>Discard</strong> and re-record.</li>
                <li>Submit your ticket as normal &mdash; the recording goes along with it.</li>
            </ol>
            <p class="tip"><strong>Heads up</strong>: if you click Submit while a recording is still in the preview without clicking <em>Use this</em> or <em>Discard</em>, the form will stop and ask you to do one or the other first. This is deliberate &mdash; we don't want to silently lose your recording.</p>
            <p class="tip"><strong>iPhone / iPad</strong>: Apple Safari on iOS doesn't support screen recording from web pages (an Apple limitation, not ours). The Record button won't appear on those devices &mdash; use a desktop or laptop browser to capture a recording.</p>
        </div>

        <!-- 5. Viewing & tracking tickets -->
        <div class="ss-help-section">
            <h2><span class="num">5</span> Viewing &amp; tracking your tickets</h2>
            <p>The <strong>Dashboard</strong> is your home page. It shows:</p>
            <ul>
                <li><strong>Summary cards</strong> &mdash; how many of your tickets are Open, In Progress, On Hold, and the total.</li>
                <li><strong>Recent tickets</strong> &mdash; a quick table of your most recent items. Click any row to open it.</li>
                <li><strong>System status</strong> &mdash; live indicator of any known IT outages, so you can check before raising a ticket about something that's already a known issue.</li>
            </ul>
            <p>Clicking a ticket opens the <strong>ticket detail</strong> page where you can see:</p>
            <ul>
                <li>The full conversation between you and the IT team &mdash; emails in both directions, in date order.</li>
                <li>Any screen recordings you attached, with inline video players.</li>
                <li>Notes the analyst chose to share with you (internal-only notes stay hidden).</li>
                <li>The current status, priority, and assigned analyst.</li>
            </ul>
            <p>To reply or add information, just reply to the email notification you received &mdash; your reply threads back into the ticket automatically. Or send a fresh email to the support address with the ticket reference in the subject line.</p>
        </div>

        <!-- 6. Account & security -->
        <div class="ss-help-section">
            <h2><span class="num">6</span> Account &amp; security</h2>
            <p>Click your initials in the top-right corner to open the account menu. From there:</p>
            <ul>
                <li><strong>My Account</strong> &mdash; set a <strong>preferred name</strong> (e.g. <em>"Ed"</em> instead of <em>"Ed Mozley"</em>) that's used when the system greets you in emails. Change your password.</li>
                <li><strong>Multi-factor authentication (MFA)</strong> &mdash; turn on TOTP-based MFA using an authenticator app like Google Authenticator, Microsoft Authenticator, or Authy. Strongly recommended &mdash; the portal is on the internet, and a second factor is your best protection if your password ever leaks.</li>
                <li><strong>Sign out</strong> &mdash; ends your session. Useful on shared computers.</li>
            </ul>
            <p class="tip"><strong>About the feedback survey</strong>: when your ticket is closed, the IT team may email you a short 1&ndash;5 satisfaction survey. It takes 5 seconds &mdash; please do fill it in. It helps them improve the service and makes a real difference in development conversations within the team.</p>
        </div>

        <!-- 7. Tips -->
        <div class="ss-help-section">
            <h2><span class="num">7</span> Tips for a fast resolution</h2>
            <ul>
                <li><strong>One issue per ticket</strong> &mdash; if you've got three unrelated problems, raise three tickets. It's easier for the team to route them to the right specialist.</li>
                <li><strong>Reproducibility info</strong> &mdash; if you can reliably reproduce the issue, write down the exact steps. <em>"Sometimes it does X"</em> is much harder to fix than <em>"every time I do A then B, X happens"</em>.</li>
                <li><strong>Mention what you've already tried</strong> &mdash; saves the analyst suggesting things you already know don't work.</li>
                <li><strong>Check the system status</strong> &mdash; if the issue is in the known outages list, it's already being worked on; no need to raise a duplicate ticket.</li>
                <li><strong>Reply by email</strong> &mdash; you don't have to come back to the portal to add information; just reply to the notification email and your message will thread into the ticket.</li>
            </ul>
        </div>

    </div>
</body>
</html>
