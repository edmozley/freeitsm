<?php
/**
 * System Help — Security.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'security';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page controls</h3>
            <p>System &rarr; Security is where you tune the defences around the local username-and-password login. Each card is a separate guard rail; set the ones you want, leave the rest at their defaults, and press <strong>Save</strong>.</p>
        </div>
    </div>
    <p>These are the guard rails, each covered below:</p>
    <div class="help-cards">
        <div class="help-card">
            <h4>Trusted device</h4>
            <p>How long a remembered device can skip the one-time code after a successful sign-in.</p>
        </div>
        <div class="help-card">
            <h4>Keep me signed in</h4>
            <p>Let analysts stay signed in on a browser for a set number of days.</p>
        </div>
        <div class="help-card">
            <h4>Password expiry</h4>
            <p>Force people to set a new password after a set number of days.</p>
        </div>
        <div class="help-card">
            <h4>Account lockout</h4>
            <p>Lock a single account after too many failed attempts, for a cooling-off period.</p>
        </div>
        <div class="help-card">
            <h4>IP ban</h4>
            <p>Block an attacking IP address that is spraying attempts across many accounts.</p>
        </div>
    </div>
    <div class="help-note"><strong>Most fields treat <code>0</code> as &ldquo;off&rdquo;.</strong> A duration or count of zero disables that particular control, so you can switch features on one at a time. All values save together when you press <strong>Save</strong>.</div>
    <div class="help-note"><strong>Identity-provider users are unaffected.</strong> These rules apply to local passwords only. If someone signs in via SSO, their provider enforces lockout, MFA and password rules — see System &rarr; Single Sign-On.</div>
</div>

<!-- 2. Trusted device -->
<div class="help-section" id="trusted">
    <div class="help-section-header"><?php echo helpSectionNum('trusted'); ?>
        <div>
            <h3>Trusted device</h3>
            <p>After a successful sign-in with a one-time code, a device can be remembered so the person isn't asked for the <strong>code</strong> again for a while. This setting controls how long that trust lasts. They still type their password whenever they sign in - to stay signed in, see <a href="#keep">Keep me signed in</a>.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Trust duration</strong></td><td>The number of days a remembered device stays trusted before the person is challenged again. Range 0&ndash;365.</td></tr>
    </table></div>
    <p>Set it to <code>0</code> to never trust a device — every sign-in is treated fresh. A higher number is more convenient but means a lost or shared machine stays trusted for longer.</p>
    <div class="help-note"><strong>Choosing a value:</strong> 30 days suits most internal staff. For higher-sensitivity desks, keep it short (7 days or less), or <code>0</code> to disable trusted devices entirely.</div>
</div>

<!-- Keep me signed in (GH #443) -->
<div class="help-section" id="keep">
    <div class="help-section-header"><?php echo helpSectionNum('keep'); ?>
        <div>
            <h3>Keep me signed in</h3>
            <p>Without it, a sign-in lasts only as long as the browser session: about 24 minutes of no activity, closing the browser, or restarting the server (or the Docker container) means signing in again. With it, analysts can tick <strong>Keep me signed in</strong> on the sign-in page and stay signed in on that browser for the period you choose.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Keep analysts signed in</strong></td><td><em>Off</em> (the default: no tick on the sign-in page), or 1, 7, 14 or 30 days. Shortening it applies to browsers already kept signed in.</td></tr>
    </table></div>
    <ul>
        <li><strong>It ends</strong> when the period is up, when they sign out, when their password changes (by them or an administrator), when they are deactivated or locked, and when you turn this off.</li>
        <li><strong>Two-factor:</strong> they type the code once, when they tick the box. After that, the browser counts as having passed it.</li>
        <li><strong>Avatar menu &rarr; Signed-in devices</strong> lists every browser an analyst is signed in on - kept signed in or not - with its IP address, when it signed in and when it was last active. <strong>Sign out</strong> on any of them, or <strong>Sign out of all other devices</strong>, takes effect at that browser's next click and ends its Keep me signed in too.</li>
        <li>A copied cookie that is used after the owner's is spotted, and every browser of that analyst is signed out.</li>
    </ul>
    <div class="help-note"><strong>Choosing a value:</strong> 14 days suits most desks where each analyst has their own computer. On shared or public computers, analysts should simply not tick it. Not for single sign-on (the identity provider decides how long that lasts) or the self-service portal.</div>
</div>

<!-- 3. Password expiry -->
<div class="help-section" id="password">
    <div class="help-section-header"><?php echo helpSectionNum('password'); ?>
        <div>
            <h3>Password expiry</h3>
            <p>Require people to change their local password periodically. When a password reaches this age, the person is prompted to set a new one at their next sign-in.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Password expiry</strong></td><td>The maximum age, in days, of a local password before a change is forced. Range 0&ndash;365.</td></tr>
    </table></div>
    <p>Set it to <code>0</code> to never expire passwords.</p>
    <div class="help-note"><strong>Choosing a value:</strong> modern guidance (e.g. NCSC) discourages frequent forced rotation, because it pushes people toward weaker, predictable passwords. If you must rotate, 90 days is a common compromise; otherwise leave it at <code>0</code> and rely on strong, unique passwords plus the lockout controls below.</div>
</div>

<!-- 4. Account lockout -->
<div class="help-section" id="lockout">
    <div class="help-section-header"><?php echo helpSectionNum('lockout'); ?>
        <div>
            <h3>Account lockout</h3>
            <p>Lock a single account after repeated failed sign-ins, then release it automatically after a cooling-off period. This blunts password-guessing against one person.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Maximum failed attempts</strong></td><td>How many failed sign-ins are allowed before the account is locked. Range 0&ndash;20. Set to <code>0</code> to disable account lockout.</td></tr>
        <tr><td><strong>Lockout duration</strong></td><td>How long, in minutes, the account stays locked once the limit is hit. Range 1&ndash;1440 (up to 24 hours).</td></tr>
    </table></div>
    <p>When the duration elapses the account unlocks itself — no admin action needed.</p>
    <div class="help-note"><strong>Choosing values:</strong> 5 attempts with a 15&ndash;30 minute lockout stops automated guessing while leaving room for genuine typos. Avoid very low limits (1&ndash;2), which lock people out on honest mistakes, and very long durations that turn into support tickets.</div>
</div>

<!-- 5. IP ban -->
<div class="help-section" id="ipban">
    <div class="help-section-header"><?php echo helpSectionNum('ipban'); ?>
        <div>
            <h3>IP ban</h3>
            <p>Account lockout protects one account; an IP ban protects against an attacker who spreads attempts across <em>many</em> accounts from the same address. Once an IP looks abusive, it is blocked outright.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>First-ban threshold</strong></td><td>The total failed attempts from one IP address that triggers a ban. Range 0&ndash;20. Set to <code>0</code> to disable IP banning.</td></tr>
        <tr><td><strong>Minimum accounts targeted</strong></td><td>How many distinct accounts the IP must have attempted before the ban applies. Range 1&ndash;10. This stops one person fat-fingering their own password from banning the whole office's shared address.</td></tr>
    </table></div>
    <div class="help-note"><strong>How they work together:</strong> with a first-ban threshold of <code>5</code> and a minimum of <code>2</code>, an IP is banned once it has racked up 5 failed attempts <em>across at least 2 different accounts</em> — the signature of credential spraying rather than a single forgetful user.</div>
    <div class="help-note warn"><strong>Mind shared addresses.</strong> A whole office often shares one outbound IP. Keep the minimum-accounts value at 2 or more so normal mistakes from behind a single NAT don't ban everyone at once.</div>
    <div class="help-note ok"><strong>Done.</strong> Set what you need, leave the rest at <code>0</code>, and press <strong>Save</strong> — changes take effect immediately for new sign-in attempts.</div>
</div>

<div class="help-section" id="attachments">
    <div class="help-section-header"><?php echo helpSectionNum('attachments'); ?>
        <div>
            <h3>Attachments</h3>
            <p>Which files FreeITSM accepts, and how big they may be - for email, the self-service portal, chat channels, replies and attached documents alike.</p>
        </div>
    </div>
    <ul class="help-list">
        <li><strong>When a file type is not accepted</strong> - keep it, but only as a download that never opens in the browser; or do not keep it. Either way the ticket says what happened.</li>
        <li><strong>Accepted file types</strong> - leave empty for everything FreeITSM considers safe, or list only the extensions you want. You can only narrow the list.</li>
        <li><strong>Largest attachment</strong> - no single attachment can be bigger than this, wherever it arrives. 10 MB unless you change it.</li>
        <li><strong>Largest email</strong> - all the attachments on one inbound email together. 0 means no limit beyond the one above.</li>
    </ul>
    <div class="help-note warn"><strong>PHP has limits of its own.</strong> A file uploaded through the browser (a reply, the portal) is refused by PHP before FreeITSM sees it if it is over <code>upload_max_filesize</code> or <code>post_max_size</code> in php.ini, and a web server or proxy in front can have a lower limit again (nginx <code>client_max_body_size</code>, IIS <code>maxAllowedContentLength</code>). The card shows what PHP on your server accepts and warns when your limit is higher. Email is not uploaded, so it is not held back by those - but it is processed in memory, so a very large email needs PHP's <code>memory_limit</code> to match.</div>
    <p>What an inbound email over these limits does - keep it without the attachments that are too big, or reject it, and who is told - is set in <strong>Tickets &rarr; Settings &rarr; Mailboxes</strong>, where each mailbox can also set limits of its own.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
