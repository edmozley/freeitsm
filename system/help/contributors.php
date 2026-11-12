<?php
/**
 * System Help — Contributors.
 *
 * The screen shipped in 2.0.0 with no help page, leaving it the only System
 * area besides the uploads folder (which is storage, not a screen) without one.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'contributors';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is</h3>
            <p>A thank-you, in the product rather than buried in a commit log. <strong>System &rarr; Contributors</strong> is a card for each person who has made a substantial contribution to FreeITSM &mdash; their name, their GitHub username, what they did and when.</p>
        </div>
    </div>
    <p>It exists because FreeITSM started receiving real contributions from people who do not work on it. The first was an entire module: <strong>Checklists &amp; SOPs</strong>, designed, built and offered to the project unprompted. A line in a changelog is not adequate thanks for that.</p>
    <div class="help-note"><strong>The same on every install.</strong> This is not a list you curate for your own site &mdash; it is part of what FreeITSM is, so it reads identically everywhere and arrives with each release.</div>
</div>

<!-- 2. Reading a card -->
<div class="help-section" id="cards">
    <div class="help-section-header"><?php echo helpSectionNum('cards'); ?>
        <div>
            <h3>Reading a card</h3>
            <p>Every card carries the same four things, so contributions can be compared rather than ranked by how well somebody wrote about themselves.</p>
        </div>
    </div>
    <ul class="help-list">
        <li><strong>Name</strong> &mdash; exactly how the person asked to be credited.</li>
        <li><strong>GitHub username</strong> &mdash; linked to their profile, or absent if they would rather not be.</li>
        <li><strong>What they did</strong> &mdash; two or three sentences saying what it was and why it mattered.</li>
        <li><strong>When</strong> &mdash; the month and year of the contribution.</li>
    </ul>
</div>

<!-- 3. Adding someone -->
<div class="help-section" id="adding">
    <div class="help-section-header"><?php echo helpSectionNum('adding'); ?>
        <div>
            <h3>Adding someone (maintainers)</h3>
            <p>There is no admin screen for this, deliberately.</p>
        </div>
    </div>
    <p>The list lives in <code>includes/contributors.php</code> as a plain PHP array. A database table would need a migration, a schema entry, an editing screen and a permission deciding who may use it &mdash; all so that a list which grows a handful of times a year can change without a deploy. Add the person to the file, commit, and they ship with the next release.</p>
    <div class="help-note warn"><strong>Ask first, every time.</strong> Putting somebody's name and GitHub handle on a public product page is their decision, not yours. Ask how they want to be credited and use exactly what they give you &mdash; some people want their full name, some a handle, some no link at all.</div>
    <p>And write the <em>what</em> properly. &ldquo;Helped with the project&rdquo; credits nobody and reads as an afterthought; say what they actually built or found, and what it changed.</p>
</div>

<!-- 4. Contributing -->
<div class="help-section" id="contributing">
    <div class="help-section-header"><?php echo helpSectionNum('contributing'); ?>
        <div>
            <h3>Want to be on it?</h3>
            <p>Contributions are welcome and they do not have to be code.</p>
        </div>
    </div>
    <ul class="help-list">
        <li><strong>Report a bug properly.</strong> A report with the steps to reproduce it is worth a great deal &mdash; several fixes have been diagnosed correctly by the person who reported them.</li>
        <li><strong>Suggest a feature</strong> in <a href="https://github.com/edmozley/freeitsm/discussions" target="_blank" rel="noopener">Discussions</a>, with the problem you are actually trying to solve. The best requests describe the day-to-day annoyance, not the solution.</li>
        <li><strong>Translate.</strong> FreeITSM ships 25 languages and some are still incomplete. A native speaker fixing the register of one module is genuinely useful.</li>
        <li><strong>Send code</strong> as a pull request. Start with a discussion for anything large, so the design can be agreed before the work.</li>
    </ul>
    <p>Start at <a href="https://github.com/edmozley/freeitsm/issues" target="_blank" rel="noopener">github.com/edmozley/freeitsm/issues</a>.</p>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
