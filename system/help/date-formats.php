<?php
/**
 * System Help — Date and time formats.
 * Deliberately short: the screen is two lists of radio buttons with a live
 * preview, so the help exists mainly to say what it does NOT change.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'date-formats';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this sets</h3>
            <p>How a date and a time are <em>written</em> everywhere in FreeITSM — whether 5 August 2026 appears as <strong>05 Aug 2026</strong>, <strong>05/08/2026</strong> or <strong>2026-08-05</strong>, and whether half past two in the afternoon reads <strong>14:30</strong> or <strong>2:30 PM</strong>.</p>
        </div>
    </div>
    <p>It is the install-wide default. Any analyst can pick a different one for themselves under <strong>Preferences</strong>, and theirs wins — so this is the answer for everybody who has never thought about it, which is most people.</p>
    <div class="help-note"><strong>The preview is the real thing.</strong> Every example on the page is rendered through the same code that will render the application, so what you read there is exactly what you will get. The sample deliberately uses a single-digit day and an afternoon time, because those are the two things that actually tell the formats apart.</div>
</div>

<!-- 2. The choices -->
<div class="help-section" id="choices">
    <div class="help-section-header"><?php echo helpSectionNum('choices'); ?>
        <div>
            <h3>The choices</h3>
            <p>Nine date arrangements and two clocks. Pick one of each and save.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Date</th><th>5 August 2026 becomes</th></tr></thead>
        <tbody>
            <tr><td>DD MON YYYY <strong>(default)</strong></td><td><code>05 Aug 2026</code></td></tr>
            <tr><td>D MONTH YYYY</td><td><code>5 August 2026</code></td></tr>
            <tr><td>MON D, YYYY</td><td><code>Aug 5, 2026</code></td></tr>
            <tr><td>DD/MM/YYYY</td><td><code>05/08/2026</code></td></tr>
            <tr><td>DD.MM.YYYY</td><td><code>05.08.2026</code></td></tr>
            <tr><td>DD-MM-YYYY</td><td><code>05-08-2026</code></td></tr>
            <tr><td>DD/MM/YY</td><td><code>05/08/26</code></td></tr>
            <tr><td>MM/DD/YYYY</td><td><code>08/05/2026</code></td></tr>
            <tr><td>YYYY-MM-DD</td><td><code>2026-08-05</code></td></tr>
        </tbody>
    </table></div>
    <div class="help-table"><table>
        <thead><tr><th>Time</th><th>Half past two in the afternoon becomes</th></tr></thead>
        <tbody>
            <tr><td>24-hour <strong>(default)</strong></td><td><code>14:30</code></td></tr>
            <tr><td>12-hour</td><td><code>2:30 PM</code></td></tr>
        </tbody>
    </table></div>
    <div class="help-note ok"><strong>Month names follow the interface language, not this setting.</strong> Choose <em>DD MON YYYY</em> and a German analyst sees <code>05 Aug 2026</code> with the German abbreviation. You are choosing the <em>arrangement</em>; the words come from whichever language that person is reading FreeITSM in.</div>
    <p>If your install has people in several countries, <code>DD MON YYYY</code> or <code>YYYY-MM-DD</code> are the two that cannot be misread. <strong>05/08/2026</strong> and <strong>08/05/2026</strong> are the same date to a machine and different dates to a reader, which is the entire argument for naming the month.</p>
</div>

<!-- 3. What it does not change -->
<div class="help-section" id="scope">
    <div class="help-section-header"><?php echo helpSectionNum('scope'); ?>
        <div>
            <h3>What it does not change</h3>
            <p>Nothing about the times themselves — this is presentation only, and it is safe to change on a live system.</p>
        </div>
    </div>
    <ul>
        <li><strong>Not the timezone.</strong> Which zone a time is shown in is a separate, per-analyst setting under Preferences. Somebody in Auckland and somebody in London can read the same timestamp in their own zone and in the same format.</li>
        <li><strong>Not what is stored.</strong> Dates are held unchanged whatever you pick here, so service level targets, reports, exports and the API are all unaffected.</li>
        <li><strong>Not the date pickers.</strong> Those come from the browser and follow the device's own regional settings.</li>
    </ul>
    <div class="help-note"><strong>So you can change it back.</strong> There is no migration and nothing is rewritten — the same instants are simply printed differently. If a format turns out to annoy everybody, switch it and the change is complete immediately.</div>
</div>

<!-- 4. Per person -->
<div class="help-section" id="per-person">
    <div class="help-section-header"><?php echo helpSectionNum('per-person'); ?>
        <div>
            <h3>When somebody wants their own</h3>
            <p>They set it themselves; you do not need to do anything.</p>
        </div>
    </div>
    <p>An analyst opens <strong>Preferences</strong> from their own menu and picks a date and time format there. It applies only to them and overrides this page. An analyst who has never touched it follows whatever is set here — including if you change it later.</p>
    <div class="help-note"><strong>Portal users always follow this page.</strong> The people who raise tickets have no format setting of their own, so what you choose here is what your customers read.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
