<?php
/**
 * System Help — Security.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'security';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this page controls</h3></div>
    <p class="syshelp-lead">System &rarr; Security is where you tune the defences around the local username-and-password login. Each card is a separate guard rail; set the ones you want, leave the rest at their defaults, and press <strong>Save</strong>.</p>
    <p>There are four guard rails, each covered below:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Trusted device</h4>
            <p>How long a remembered device can skip a re-prompt after a successful sign-in.</p>
        </div>
        <div class="syshelp-card">
            <h4>Password expiry</h4>
            <p>Force people to set a new password after a set number of days.</p>
        </div>
        <div class="syshelp-card">
            <h4>Account lockout</h4>
            <p>Lock a single account after too many failed attempts, for a cooling-off period.</p>
        </div>
        <div class="syshelp-card">
            <h4>IP ban</h4>
            <p>Block an attacking IP address that is spraying attempts across many accounts.</p>
        </div>
    </div>
    <div class="syshelp-callout info"><strong>Most fields treat <code>0</code> as &ldquo;off&rdquo;.</strong> A duration or count of zero disables that particular control, so you can switch features on one at a time. All values save together when you press <strong>Save</strong>.</div>
    <div class="syshelp-callout"><strong>Identity-provider users are unaffected.</strong> These rules apply to local passwords only. If someone signs in via SSO, their provider enforces lockout, MFA and password rules — see System &rarr; Single Sign-On.</div>
</div>

<!-- 2. Trusted device -->
<div class="syshelp-section" id="trusted">
    <div class="syshelp-section-header"><h3>Trusted device</h3></div>
    <p class="syshelp-lead">After a successful sign-in, a device can be remembered so the person isn't challenged again for a while. This setting controls how long that trust lasts.</p>
    <table class="syshelp-table">
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Trust duration</strong></td><td>The number of days a remembered device stays trusted before the person is challenged again. Range 0&ndash;365.</td></tr>
    </table>
    <p>Set it to <code>0</code> to never trust a device — every sign-in is treated fresh. A higher number is more convenient but means a lost or shared machine stays trusted for longer.</p>
    <div class="syshelp-callout"><strong>Choosing a value:</strong> 30 days suits most internal staff. For higher-sensitivity desks, keep it short (7 days or less), or <code>0</code> to disable trusted devices entirely.</div>
</div>

<!-- 3. Password expiry -->
<div class="syshelp-section" id="password">
    <div class="syshelp-section-header"><h3>Password expiry</h3></div>
    <p class="syshelp-lead">Require people to change their local password periodically. When a password reaches this age, the person is prompted to set a new one at their next sign-in.</p>
    <table class="syshelp-table">
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Password expiry</strong></td><td>The maximum age, in days, of a local password before a change is forced. Range 0&ndash;365.</td></tr>
    </table>
    <p>Set it to <code>0</code> to never expire passwords.</p>
    <div class="syshelp-callout info"><strong>Choosing a value:</strong> modern guidance (e.g. NCSC) discourages frequent forced rotation, because it pushes people toward weaker, predictable passwords. If you must rotate, 90 days is a common compromise; otherwise leave it at <code>0</code> and rely on strong, unique passwords plus the lockout controls below.</div>
</div>

<!-- 4. Account lockout -->
<div class="syshelp-section" id="lockout">
    <div class="syshelp-section-header"><h3>Account lockout</h3></div>
    <p class="syshelp-lead">Lock a single account after repeated failed sign-ins, then release it automatically after a cooling-off period. This blunts password-guessing against one person.</p>
    <table class="syshelp-table">
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Maximum failed attempts</strong></td><td>How many failed sign-ins are allowed before the account is locked. Range 0&ndash;20. Set to <code>0</code> to disable account lockout.</td></tr>
        <tr><td><strong>Lockout duration</strong></td><td>How long, in minutes, the account stays locked once the limit is hit. Range 1&ndash;1440 (up to 24 hours).</td></tr>
    </table>
    <p>When the duration elapses the account unlocks itself — no admin action needed.</p>
    <div class="syshelp-callout"><strong>Choosing values:</strong> 5 attempts with a 15&ndash;30 minute lockout stops automated guessing while leaving room for genuine typos. Avoid very low limits (1&ndash;2), which lock people out on honest mistakes, and very long durations that turn into support tickets.</div>
</div>

<!-- 5. IP ban -->
<div class="syshelp-section" id="ipban">
    <div class="syshelp-section-header"><h3>IP ban</h3></div>
    <p class="syshelp-lead">Account lockout protects one account; an IP ban protects against an attacker who spreads attempts across <em>many</em> accounts from the same address. Once an IP looks abusive, it is blocked outright.</p>
    <table class="syshelp-table">
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>First-ban threshold</strong></td><td>The total failed attempts from one IP address that triggers a ban. Range 0&ndash;20. Set to <code>0</code> to disable IP banning.</td></tr>
        <tr><td><strong>Minimum accounts targeted</strong></td><td>How many distinct accounts the IP must have attempted before the ban applies. Range 1&ndash;10. This stops one person fat-fingering their own password from banning the whole office's shared address.</td></tr>
    </table>
    <div class="syshelp-callout info"><strong>How they work together:</strong> with a first-ban threshold of <code>5</code> and a minimum of <code>2</code>, an IP is banned once it has racked up 5 failed attempts <em>across at least 2 different accounts</em> — the signature of credential spraying rather than a single forgetful user.</div>
    <div class="syshelp-callout warn"><strong>Mind shared addresses.</strong> A whole office often shares one outbound IP. Keep the minimum-accounts value at 2 or more so normal mistakes from behind a single NAT don't ban everyone at once.</div>
    <div class="syshelp-callout ok"><strong>Done.</strong> Set what you need, leave the rest at <code>0</code>, and press <strong>Save</strong> — changes take effect immediately for new sign-in attempts.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
