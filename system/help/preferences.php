<?php
/**
 * System Help — Preferences.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'preferences';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What Preferences are</h3></div>
    <p class="syshelp-lead">Preferences are <strong>personal to you</strong>, not system-wide. Changing one here only affects your own view — it never changes anything for other analysts.</p>
    <p>There are no Save or Apply buttons. Every control saves the moment you click it, and a brief confirmation or preview tells you it stuck.</p>
    <div class="syshelp-callout info"><strong>Your settings travel with you.</strong> Choices are stored against your account, so they apply on any computer or browser you sign in from — not just the one you set them on.</div>
</div>

<!-- 2. Language -->
<div class="syshelp-section" id="language">
    <div class="syshelp-section-header"><h3>Interface language</h3></div>
    <p class="syshelp-lead">Pick the language the interface is shown in. The dropdown lists every language FreeITSM ships translations for, with its language code alongside.</p>
    <p>When you choose a new language the page <strong>reloads</strong> so everything re-renders in your selection. Ticket content and other data you've entered are not translated — only the interface labels, menus and messages.</p>
</div>

<!-- 3. Notifications -->
<div class="syshelp-section" id="toasts">
    <div class="syshelp-section-header"><h3>Notifications (toasts)</h3></div>
    <p class="syshelp-lead">Toasts are the small pop-up messages that confirm an action or report an error. Two settings control where they appear and how they move.</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Position</h4>
            <p>A 3&times;3 grid representing your screen. Click any of the nine cells — top/middle/bottom by left/centre/right — to choose the corner or edge toasts appear in. A sample toast previews your choice.</p>
        </div>
        <div class="syshelp-card">
            <h4>Animation</h4>
            <p>Choose <strong>Slide</strong> (toasts glide in from the edge) or <strong>Fade</strong> (they fade in on the spot). A sample previews each.</p>
        </div>
    </div>
    <div class="syshelp-callout">If you set these before they became account settings, your old per-browser choices are migrated to your account automatically the first time you open this page.</div>
</div>

<!-- 4. Left panels -->
<div class="syshelp-section" id="panels">
    <div class="syshelp-section-header"><h3>Left-panel visibility</h3></div>
    <p class="syshelp-lead">Several modules have a left-hand panel for navigation. This list gives you one toggle per module so you can decide, per module, whether that panel is always on screen or tucked away until you need it.</p>
    <p>Each toggle offers two modes:</p>
    <ul>
        <li><strong>Always</strong> — the panel stays pinned open whenever you're in that module.</li>
        <li><strong>Hover</strong> — the panel collapses to reclaim space and slides out when you move the pointer to the edge.</li>
    </ul>
    <p>The modules with their own panel toggle here are Knowledge, Process Mapper, Contracts, Calendar, Tasks, CMDB, Change Management, Asset Management and System Wiki.</p>
    <div class="syshelp-callout info">Where a module also has its own settings page, the same toggle lives there too — both edit the one setting, so changing it in either place has the same effect.</div>
</div>

<!-- 5. Display options -->
<div class="syshelp-section" id="display">
    <div class="syshelp-section-header"><h3>Display options</h3></div>
    <p class="syshelp-lead">A small extra display choice.</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Mission Control chart fill</h4>
            <p>Sets how charts on the Mission Control dashboard are filled: <strong>Plain</strong> (solid colour) or <strong>Gradient</strong> (a graded fill). Purely cosmetic — it changes the look, not the data.</p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
