<?php
/**
 * The Microsoft 365 half of system/integrations/help.php (GH #263).
 *
 * Its own file like help_msteams.php: Microsoft 365 is not a tracker, and not in the
 * integrations registry - it is the page at system/integrations/microsoft365/, which
 * shows Entra ID importing and Intune together. help.php lets it in by name.
 *
 * Included by help.php inside .help-content; the enclosing markup is that file's.
 */
?>
<!-- 1 ───────────────────────────────────────────── -->
<div class="help-section" id="overview">
    <div class="help-section-header">
        <span class="help-section-num">1</span>
        <h3>What this does</h3>
    </div>
    <p>
        <strong>Microsoft 365</strong> is where FreeITSM shows everything it reads from your Microsoft tenant, in one place.
        Two things are set up here, and they are separate connections - often to the same app registration in Entra,
        but each with its own settings, so setting up one never changes the other:
    </p>
    <ul>
        <li><strong>Entra ID</strong> - people, their groups and who manages whom, imported before anybody signs in.</li>
        <li><strong>Intune</strong> - devices, which become assets, held by their primary user, with their installed apps.</li>
    </ul>
    <p>
        Below them, <strong>Also from Microsoft</strong> lists everything else that talks to Microsoft 365 - mailboxes,
        Outlook calendars, Teams channel alerts and Teams chat - with how each stands and a button to where it is set up.
        Nothing there moves; this page is only the map.
    </p>
</div>

<!-- 2 ───────────────────────────────────────────── -->
<div class="help-section" id="entra">
    <div class="help-section-header">
        <span class="help-section-num">2</span>
        <h3>Entra ID</h3>
    </div>
    <p>
        Importing people comes with signing in: add <strong>Microsoft Entra ID</strong> under
        <strong>System &rsaquo; Authentication</strong> first. Each Entra sign-in then appears here, saying whether it
        imports people, whether sign-in is on, and when it last imported. <strong>Import</strong> opens what it brings in;
        <strong>Sign-in</strong> opens Authentication. Signing in with Entra ID stays there, with the other sign-in methods.
    </p>
</div>

<!-- 3 ───────────────────────────────────────────── -->
<div class="help-section" id="connection">
    <div class="help-section-header">
        <span class="help-section-num">3</span>
        <h3>Intune: the connection</h3>
    </div>
    <p>
        <strong>Connection</strong> holds three things: your tenant (directory) ID, the app registration's client ID,
        and its client secret. The app needs the Microsoft Graph application permissions to read managed devices and
        their apps, with admin consent granted. A saved secret is never shown again; leave the box empty to keep it.
    </p>
    <p>This used to be the Intune tab of <strong>Assets &rsaquo; Settings</strong>. Its old link brings you here.</p>
</div>

<!-- 4 ───────────────────────────────────────────── -->
<div class="help-section" id="import">
    <div class="help-section-header">
        <span class="help-section-num">4</span>
        <h3>Intune: what it imports</h3>
    </div>
    <p><strong>Import</strong> has three tabs, and one Save for all of them:</p>
    <ul>
        <li><strong>Devices</strong> - which company new devices go into, whether an asset is renamed when its device is,
            whether an asset nobody holds is given to its device's primary user, and whether it follows a new primary user.
            The device sync is here, with when it last ran.</li>
        <li><strong>Asset types</strong> - which of your asset types each kind of device becomes: laptop, desktop, phone and so on.</li>
        <li><strong>Software</strong> - how many devices each run of the software sync reads, the sync itself, and how fresh each asset's app list is.</li>
    </ul>
    <p>
        Both syncs also run on their own - devices every 6 hours, software once a day - from
        <strong>Reporting &rsaquo; System logs &rsaquo; Background jobs</strong>.
    </p>
</div>

<!-- 5 ───────────────────────────────────────────── -->
<div class="help-section" id="access">
    <div class="help-section-header">
        <span class="help-section-num">5</span>
        <h3>Who can open it</h3>
    </div>
    <p>
        System is for administrators. The Intune pages are the one exception: an analyst with the Assets module and the
        <strong>Configure the Intune connection</strong> permission (System &rsaquo; Roles, under Assets) can open them,
        as they could open the Intune tab before. They see the Intune half of this page and nothing else in System.
    </p>
    <p>Intune brings devices in as assets, so while Assets is switched off (System &rsaquo; Modules) it is hidden here.</p>
</div>

<!-- 6 ───────────────────────────────────────────── -->
<div class="help-section" id="trouble">
    <div class="help-section-header">
        <span class="help-section-num">6</span>
        <h3>If something is wrong</h3>
    </div>
    <ul>
        <li><strong>The device sync fails at once</strong> - check the three values on Connection, and that the client secret has not expired in Entra.</li>
        <li><strong>Devices arrive but nobody holds them</strong> - people must be imported from Entra ID first: the match is by Entra object ID, never by name.</li>
        <li><strong>Assets have no type</strong> - map each kind of device on the Asset types tab; nothing is mapped until somebody maps it.</li>
    </ul>
</div>
