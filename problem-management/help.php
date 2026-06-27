<?php
session_start();
require_once __DIR__ . '/../config.php';
$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Problem Management Help</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <style>
        .pm-help { max-width: 820px; margin: 24px auto; padding: 0 20px 60px; line-height: 1.65; color: #2a2a2a; }
        .pm-help h1 { font-size: 1.6rem; }
        .pm-help h2 { font-size: 1.15rem; color: #6a1b9a; margin-top: 1.6em; }
        .pm-help code { background: #f3f4f6; padding: 1px 5px; border-radius: 3px; }
        .pm-help li { margin-bottom: 6px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>
    <div class="pm-help">
        <h1>Problem Management</h1>
        <p>A <strong>Problem</strong> is the underlying root cause behind one or more incidents
        (tickets). Use it to group recurring incidents, record the root cause and a
        workaround, mark a <strong>known error</strong>, and link the Change that permanently fixes it.</p>

        <h2>Creating &amp; working a problem</h2>
        <ul>
            <li>Click <strong>New problem</strong>, give it a title, and set a status as you investigate
            (<em>New → Investigating → Root Cause Identified → Known Error → Resolved → Closed</em>).</li>
            <li>Record the <strong>root cause</strong> and a <strong>workaround</strong>; tick <strong>Known error</strong>
            once a workaround exists so the service desk can apply it to new incidents.</li>
        </ul>

        <h2>Linking incidents</h2>
        <ul>
            <li>From a problem, click <strong>Link incident</strong> and enter its ticket number — or link from the
            ticket itself: the ticket reading pane has a <strong>Problem</strong> section.</li>
            <li>Linked incidents are listed on the problem; unlink with the ✕.</li>
        </ul>

        <h2>Linking the fix (a Change)</h2>
        <p>Use <strong>Link change</strong> to connect the Change Management record that implements the
        permanent fix, so the whole chain — incidents → problem → change — is visible.</p>

        <h2>AI helper</h2>
        <p><strong>Draft root cause</strong> reads the linked incidents and proposes a root cause and
        workaround for you to review and save. It uses the <em>Problem AI</em> key configured in
        <strong>Settings</strong> (bring your own provider/key).</p>

        <h2>Companies</h2>
        <p>On a multi-company install, each problem belongs to a company (taken from your active
        company when you create it) and is only visible to people who can access that company. On a
        single-company install this is invisible.</p>

        <p style="margin-top:28px;"><a href="<?php echo BASE_URL; ?>problem-management/" class="btn">← Back to Problems</a></p>
    </div>
</body>
</html>
