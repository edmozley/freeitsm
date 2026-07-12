<?php
/**
 * System Help — Module colours.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'colours';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What module colours control</h3></div>
    <p class="syshelp-lead">Every module has a small coloured icon — the tile you see in the app launcher and the badge that sits in each module's header. Module colours let you set that colour for each module, so teams can tell modules apart at a glance and you can match your own branding.</p>
    <p>Each module uses <strong>two</strong> colours that blend into a gradient: a <strong>primary</strong> and a <strong>secondary</strong>. The page shows one row per module with a live preview of the icon, so you can see the result before you save.</p>
    <div class="syshelp-callout info">The colours are stored centrally and apply for everyone — this is an install-wide setting, not a per-user preference.</div>
</div>

<!-- 2. Changing a colour -->
<div class="syshelp-section" id="change">
    <div class="syshelp-section-header"><h3>Changing a module's colour</h3></div>
    <p class="syshelp-lead">Each module row has a <strong>Primary</strong> and a <strong>Secondary</strong> colour, and you can set either one in two ways.</p>

    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Pick a colour.</strong> Click the colour swatch to open your browser's colour picker, or type a hex code (e.g. <code>#546E7A</code>) straight into the box next to it. The swatch and the hex box stay in sync.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Check the preview.</strong> The module's icon at the start of the row updates instantly to show the new primary-to-secondary gradient.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Save.</strong> Make changes to as many modules as you like, then click <strong>Save</strong> at the bottom. Everything is saved together.</div></div>
    </div>

    <div class="syshelp-callout warn">Hex codes must be a full six-digit value such as <code>#1A73E8</code>. A partial or invalid code is ignored, so the field keeps its last valid colour.</div>
</div>

<!-- 3. Resetting -->
<div class="syshelp-section" id="reset">
    <div class="syshelp-section-header"><h3>Resetting a module to default</h3></div>
    <p>Each row has a <strong>Reset</strong> button on the right. Click it to put that module back to its built-in default primary and secondary colours. The swatches, hex boxes and preview all update straight away.</p>
    <div class="syshelp-callout"><strong>Remember to save.</strong> Reset only changes the row on screen — click <strong>Save</strong> afterwards to make it stick. Reset affects one module at a time; there's no all-at-once reset.</div>
</div>

<!-- 4. Notes -->
<div class="syshelp-section" id="notes">
    <div class="syshelp-section-header"><h3>Good to know</h3></div>
    <ul>
        <li>Modules you've never touched use their built-in defaults until you change and save them.</li>
        <li>Changes apply across the whole install — everyone sees the same module colours.</li>
        <li>Only the colours shown on this page are affected; the rest of the interface is unchanged.</li>
    </ul>
    <div class="syshelp-callout ok">Tip: pick a primary and a secondary that are close in shade for a subtle gradient, or further apart for a bolder, more obvious icon.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
