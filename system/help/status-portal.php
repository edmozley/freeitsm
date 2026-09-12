<?php
/**
 * System Help — Service status on the portal.
 * The one that needs reading BEFORE it is switched on: incident titles were
 * written for the service desk, and this shows them to customers.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'status-portal';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this adds</h3>
            <p>End users already see which of your services are healthy when they open the self-service portal. This decides whether they also see the <strong>incidents</strong> behind an outage, and the updates your team has marked as external.</p>
        </div>
    </div>
    <p>The case for it is the oldest one in service management: a customer who can see that you already know about the outage does not raise a ticket about it, and does not ring up to ask. A status page is the cheapest ticket deflection there is.</p>
    <div class="help-note"><strong>It is off by default.</strong> While it is off, the portal shows service health only — exactly as it did before — and nothing you write in an incident is visible to anybody outside your team.</div>
</div>

<!-- 2. The warning -->
<div class="help-section" id="before">
    <div class="help-section-header"><?php echo helpSectionNum('before'); ?>
        <div>
            <h3>Read this before switching it on</h3>
            <p>One thing to check, and it takes five minutes.</p>
        </div>
    </div>
    <div class="help-note warn"><strong>Your incident titles were written for your team, not for your customers.</strong> Go and read the last ten. Titles like <em>"Exchange again — 3rd time this month"</em> or <em>"DC2 fell over, Dave looking"</em> are completely normal internal shorthand and are not what you want a customer reading on a status page. Nothing stops you keeping that style; just know that switching this on publishes it.</div>
    <p>Two things protect you, and it is worth knowing exactly how far each goes:</p>
    <ul>
        <li><strong>Only updates explicitly marked <em>external</em> are ever shown.</strong> The running commentary your team writes while fixing something stays internal unless somebody deliberately marks an update for customers.</li>
        <li><strong>Everything written before this feature existed is internal, and stays internal.</strong> Switching this on cannot retroactively publish old notes. You are not exposing your back catalogue.</li>
    </ul>
    <div class="help-note"><strong>But the incident <em>title</em> is shown.</strong> That is the gap between the two protections above and what a customer actually sees, and it is the reason for the five minutes of reading.</div>
</div>

<!-- 3. How much history -->
<div class="help-section" id="history">
    <div class="help-section-header"><?php echo helpSectionNum('history'); ?>
        <div>
            <h3>How much history to show</h3>
            <p>Three answers, and the middle one is recommended for a reason worth stating.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Choice</th><th>What your customers see</th></tr></thead>
        <tbody>
            <tr>
                <td><strong>Only what is happening now</strong></td>
                <td>The cleanest page. ⚠️ An outage disappears the moment it is resolved — so somebody who was affected an hour ago gets <em>no confirmation it was fixed</em>, which is usually the thing they came to find out.</td>
            </tr>
            <tr>
                <td><strong>Now, plus anything resolved in the last <em>n</em> days</strong> <span class="help-pill ok">recommended</span></td>
                <td>A resolved entry is the most reassuring thing on a status page, and this is what most people mean when they say "status page". Set the number of days to taste; a week or two suits most service desks.</td>
            </tr>
            <tr>
                <td><strong>Everything, ever</strong></td>
                <td>The full history, collapsed. Most transparent and closest to a public status page — but on a quiet portal it reads as a long list of things that are no longer wrong.</td>
            </tr>
        </tbody>
    </table></div>
    <div class="help-note ok"><strong>Why the middle one.</strong> "Resolved 40 minutes ago" answers the question the visitor actually has. "Nothing is wrong" does not, because they cannot tell it apart from a status page that is simply not being kept up.</div>
</div>

<!-- 4. Working with it -->
<div class="help-section" id="using">
    <div class="help-section-header"><?php echo helpSectionNum('using'); ?>
        <div>
            <h3>Living with it</h3>
            <p>What changes for your team once it is on.</p>
        </div>
    </div>
    <ul>
        <li><strong>Someone has to mark an update external</strong> for customers to see any commentary at all. If nobody ever does, the portal shows the incident and its status but no story — which is still better than silence, but less than the feature can do.</li>
        <li><strong>Write the customer-facing update as its own thing.</strong> The useful external update is short, says what is affected and what to expect, and does not mention hostnames. It is a different piece of writing from the internal one, not a tidied version of it.</li>
        <li><strong>Titles are worth a house style</strong> once this is on — what is affected, in the customer's words. "Email is slow for some users" rather than "Exchange transport queue backlog".</li>
    </ul>
    <div class="help-note"><strong>You can switch it off again at any time</strong>, and the portal returns to service health only, immediately. Nothing is deleted and nothing needs undoing — which makes it safe to try on a quiet week and judge from your own incidents.</div>
    <p>See <a href="../../self-service/help.php">the self-service portal guide</a> for everything else your end users can do there.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
