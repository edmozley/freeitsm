<?php
/**
 * System Help — AI thinking.
 * One switch per AI feature. The help's job is to explain why the default is
 * off, and why that is not FreeITSM being cautious about cost alone.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'ai';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What extended thinking is</h3>
            <p>Some AI models can work through a problem at length before they answer. This page decides whether each of FreeITSM's AI features is allowed to do that. Every one is <strong>off</strong> unless you switch it on.</p>
        </div>
    </div>
    <p>It is charged for, it is slow, and for the work FreeITSM asks of a model it rarely helps. That last part is the interesting bit, and it is worth a moment because "more thinking must be better" is the natural assumption.</p>
    <div class="help-note"><strong>None of these jobs is a puzzle.</strong> Every AI feature here <em>summarises, extracts or drafts</em> from text that is already in front of it — a ticket thread, an article, a message. Extended thinking is for problems that need reasoning towards an answer that is not in the input. Summarising is not one of those.</div>
</div>

<!-- 2. The measurement -->
<div class="help-section" id="evidence">
    <div class="help-section-header"><?php echo helpSectionNum('evidence'); ?>
        <div>
            <h3>Why off is the default</h3>
            <p>Measured, not assumed — and the page shows you the numbers from this install.</p>
        </div>
    </div>
    <p>Summarising a two-message ticket, the same feature, the same model, with thinking on and off:</p>
    <div class="help-note ok"><strong>Eight times faster with thinking off — and the faster answer was the better one.</strong> It picked up details the slow one missed.</div>
    <p>That result is counter-intuitive enough to be worth explaining. A reasoning model is given a budget, and it spends that budget before it writes anything. On a long ticket it is entirely possible to <strong>pay the full bill and receive an empty answer</strong>, because the budget was exhausted during the thinking and nothing was left for the reply. On a short one it simply takes longer to say the same thing.</p>
    <div class="help-note warn"><strong>So switching this on can make a feature worse, not just slower.</strong> That is the opposite of what most people expect from a setting called "extended thinking", which is why it is off and why it is per-feature rather than one master switch.</div>
</div>

<!-- 3. Per feature -->
<div class="help-section" id="per-feature">
    <div class="help-section-header"><?php echo helpSectionNum('per-feature'); ?>
        <div>
            <h3>One switch per feature</h3>
            <p>Every AI feature FreeITSM has is listed, each with its own toggle.</p>
        </div>
    </div>
    <p>There is no global on. Features differ in how much text they are given and what they are asked to do with it, so the useful answer can differ between them — and the only way to know is to try one and read the results.</p>
    <div class="help-note"><strong>How to decide.</strong> Leave everything off. If a particular feature's answers disappoint you, turn thinking on for <em>that one</em>, use it for a day, and compare. If it is no better, turn it back off — you are paying for the difference.</div>
</div>

<!-- 4. Providers -->
<div class="help-section" id="providers">
    <div class="help-section-header"><?php echo helpSectionNum('providers'); ?>
        <div>
            <h3>Which providers it reaches</h3>
            <p>The switch is only meaningful for one of them, and the page tells you which of your features that affects.</p>
        </div>
    </div>
    <p>The setting is sent to <strong>OpenRouter</strong>, which is the only provider that accepts it. A feature configured against Anthropic or OpenAI directly is still listed here, and its setting is still saved, marked <span class="help-pill">no effect</span> — so that if you move it to OpenRouter later, your choice is already there rather than silently lost.</p>
    <p>Each row also shows the model it is pointed at, so you do not have to go and look it up elsewhere. A feature with no API key is shown as <span class="help-pill warn">no API key set</span> — the switch is not the problem there, the feature has nothing to talk to.</p>
    <div class="help-note"><strong>Provider, model and key are not set here.</strong> Each feature belongs to the module that uses it — Knowledge AI in Knowledge's settings, CMDB AI in CMDB's, Workflow, Forms and the ticket reply cleanup likewise. This page is only the thinking switch, and it reads the rest so you can see what you are switching.</div>
    <div class="help-note"><strong>If the list will not load at all</strong>, the page says so rather than showing an empty table. That is a database connection problem, not an AI one.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
