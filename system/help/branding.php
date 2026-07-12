<?php
/**
 * System Help — Branding.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'branding';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What branding controls</h3></div>
    <p class="syshelp-lead">Branding holds two things shared across the whole install: an organisation <strong>logo</strong>, and the default <strong>header and footer text</strong> placed on branded output. These act as the fallback for any module that renders a branded document.</p>
    <p>Today that means the <strong>Network Mapper</strong> diagram header and footer; future export surfaces (PDF/PNG) read the same settings, so anything you set here applies consistently wherever branded output is produced.</p>
    <div class="syshelp-callout info"><strong>Where it lives:</strong> System &rarr; Branding. The settings are organisation-wide, so a change here affects every diagram and export across the install.</div>
</div>

<!-- 2. Logo -->
<div class="syshelp-section" id="logo">
    <div class="syshelp-section-header"><h3>Logo</h3></div>
    <p class="syshelp-lead">Upload one image to use as your organisation logo. It's dropped into branded output wherever the <code>{{logo}}</code> token appears.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Choose a file.</strong> Use the file picker in the Logo card. Accepted formats are <code>PNG</code>, <code>JPG/JPEG</code> and <code>SVG</code>.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Check the preview.</strong> The picked image shows immediately in the preview box so you can confirm it before saving.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Save.</strong> Press <strong>Save</strong> to store it. The preview then reflects whatever is actually on disk.</div></div>
    </div>
    <p>To clear the logo, press <strong>Remove</strong> next to the preview, then <strong>Save</strong>. The preview returns to a "No logo" placeholder.</p>
    <div class="syshelp-callout warn"><strong>Size limit:</strong> the logo file must be 2&nbsp;MB or smaller. A larger file is rejected when you pick it. The preview box is roughly 140&times;80, and the logo is scaled to fit — a wide, transparent <code>PNG</code> or <code>SVG</code> reproduces best.</div>
</div>

<!-- 3. Header & footer text -->
<div class="syshelp-section" id="slots">
    <div class="syshelp-section-header"><h3>Header &amp; footer text</h3></div>
    <p class="syshelp-lead">Two rows of three slots — left, centre and right — for the header and again for the footer. Each slot is plain text, up to 200 characters, and may contain tokens (see below).</p>
    <table class="syshelp-table">
        <tr><th>Row</th><th>Left</th><th>Centre</th><th>Right</th></tr>
        <tr><td><strong>Header</strong></td><td>Header left slot</td><td>Header centre slot</td><td>Header right slot</td></tr>
        <tr><td><strong>Footer</strong></td><td>Footer left slot</td><td>Footer centre slot</td><td>Footer right slot</td></tr>
    </table>
    <p>Leave any slot empty to print nothing in that position. Slots can mix plain text and tokens freely, for example <code>Author: {{author}}</code>.</p>
</div>

<!-- 4. Tokens -->
<div class="syshelp-section" id="tokens">
    <div class="syshelp-section-header"><h3>Tokens</h3></div>
    <p class="syshelp-lead">Tokens are placeholders that are replaced with live values when the document is rendered, so one template suits every diagram or export.</p>
    <table class="syshelp-table">
        <tr><th>Token</th><th>Replaced with</th></tr>
        <tr><td><code>{{logo}}</code></td><td>The uploaded organisation logo.</td></tr>
        <tr><td><code>{{title}}</code></td><td>The title of the diagram or document.</td></tr>
        <tr><td><code>{{author}}</code></td><td>The author of the diagram or document.</td></tr>
        <tr><td><code>{{version}}</code></td><td>The version of the diagram or document.</td></tr>
        <tr><td><code>{{modified}}</code></td><td>The last-modified date.</td></tr>
    </table>
    <div class="syshelp-callout"><strong>Example:</strong> a footer slot of <code>Author: {{author}}</code> renders as <em>Author:</em> followed by the document's actual author when the output is produced.</div>
</div>

<!-- 5. Saving & resetting -->
<div class="syshelp-section" id="save">
    <div class="syshelp-section-header"><h3>Saving &amp; resetting</h3></div>
    <p class="syshelp-lead">Changes only take effect once saved. Two buttons sit at the bottom of the page.</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Save</h4>
            <p>Stores the logo and all six text slots. A confirmation appears, and the logo preview refreshes to match what's on disk.</p>
        </div>
        <div class="syshelp-card">
            <h4>Reset</h4>
            <p>Restores the header and footer slots to their built-in defaults — the same values a brand-new install starts with. The logo is left untouched.</p>
        </div>
    </div>
    <div class="syshelp-callout info"><strong>Defaults:</strong> header left <code>{{logo}}</code>, header centre <code>{{title}}</code>; footer left <code>Author: {{author}}</code>, footer centre <code>{{version}}</code>, footer right <code>Modified: {{modified}}</code>. <strong>Reset</strong> fills the boxes with these — you still need to <strong>Save</strong> to apply them.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
