<?php
/**
 * System Help — Help assistant (wiki Help-Assistant-Design).
 * The point to land first: it works with no AI at all.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'help-assistant';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What the help assistant is</h3>
            <p>A help icon in the header of every page. Click it and a small chat window opens: ask how to do something in your own words, and it finds the part of FreeITSM's help that answers it.</p>
        </div>
    </div>
    <p>People learning a system do not think in modules. They think <em>"how do I create a ticketing department?"</em>, not <em>"which module's help covers departments, and where on that page?"</em>. The assistant takes the question as asked, searches every help page at once, and takes the reader straight to the right section.</p>
    <div class="help-cards cols-3">
        <div class="help-card"><strong>On every page</strong><span>The icon sits beside the notification bell, in every module.</span></div>
        <div class="help-card"><strong>Remembers the conversation</strong><span>Moving to another page keeps it. Only <strong>Reset</strong> clears it.</span></div>
        <div class="help-card"><strong>Always shows its sources</strong><span>Every reply links to the help section it came from, so it can be checked - and people learn where things are.</span></div>
    </div>
</div>

<!-- 2. No AI -->
<div class="help-section" id="no-ai">
    <div class="help-section-header"><?php echo helpSectionNum('no-ai'); ?>
        <div>
            <h3>It works without any AI</h3>
            <p>Most people assume an "AI assistant" does nothing without an AI key. This one does.</p>
        </div>
    </div>
    <p>With nothing set up, the assistant <strong>searches the help</strong>: it scores every section of every help page against the question and replies with the best few, each with a short preview and an <strong>Open</strong> link that lands on exactly that section. It runs entirely on your server - free, instant, and nothing is sent anywhere.</p>
    <p>It still feels like a conversation. The reply opens with a short line chosen by how good the match is - <em>"This looks like exactly what you need"</em> when one section clearly wins, <em>"I couldn't find an exact match, but these might be close"</em> when nothing does - so it never sounds sure about a weak result. Those lines never claim an AI read anything; it found these sections in the help.</p>
    <div class="help-table"><table>
        <thead><tr><th>Asked</th><th>A right section in the first five, with no AI</th></tr></thead>
        <tbody>
            <tr><td>A plain question - <em>"how do I create a ticketing department"</em></td><td>About 9 times in 10</td></tr>
            <tr><td>An exact name - <em>"Warbot"</em>, <em>"Enforce approvals"</em></td><td>Every time, in our tests</td></tr>
            <tr><td>Something vague, sharing no words with the help - <em>"my boss wants a weekly PDF"</em></td><td>About 1 in 3</td></tr>
            <tr><td>A language FreeITSM has no help files for</td><td>Rarely</td></tr>
        </tbody>
    </table></div>
    <div class="help-note">The last two rows are what later versions improve, with an embedding key (matching by meaning) and a chat AI (a written answer in the asker's own language) - see <a href="#coming">What is coming</a>.</div>
</div>

<!-- 3. Turning it on -->
<div class="help-section" id="turning-on">
    <div class="help-section-header"><?php echo helpSectionNum('turning-on'); ?>
        <div>
            <h3>Turning it on</h3>
            <p>Two decisions: switch it on for the install, then choose who has it.</p>
        </div>
    </div>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div>Open <strong>System → Help assistant</strong> and tick <strong>Turn on the help assistant</strong>. It is off until somebody does.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div>Under <strong>Who has it</strong>, tick the analysts who should get it. <strong>All</strong> and <strong>None</strong> tick or clear everybody the filter shows, so a team can start with a few people.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Save.</strong> The first time, the help index is built in the background (a minute or so). Each analyst ticked sees the help icon from their next page.</div></div>
    </div>
</div>

<!-- 4. Using it -->
<div class="help-section" id="using">
    <div class="help-section-header"><?php echo helpSectionNum('using'); ?>
        <div>
            <h3>Using it</h3>
            <p>Ask the way you would ask a colleague.</p>
        </div>
    </div>
    <ul>
        <li><strong>Type the question</strong> and press <strong>Enter</strong> (Shift and Enter for a new line).</li>
        <li><strong>Click a result</strong> to open that help section. The window stays open on the next page, so you can try another.</li>
        <li><strong>Drag the window</strong> by its header to wherever it is out of the way; it stays there.</li>
        <li><strong>Reset</strong> clears the conversation. Nothing else does - it is kept as you move around, for you alone.</li>
        <li><strong>The module you are in counts a little.</strong> In Workflows, a question that fits several modules leans towards the Workflows help.</li>
        <li><strong>On a phone</strong> it opens full screen, and the back button closes it.</li>
    </ul>
</div>

<!-- 5. What it shows -->
<div class="help-section" id="permissions">
    <div class="help-section-header"><?php echo helpSectionNum('permissions'); ?>
        <div>
            <h3>What it will and will not show</h3>
            <p>It follows the same access rules as everything else.</p>
        </div>
    </div>
    <ul>
        <li><strong>Only modules the analyst can open.</strong> Somebody without Asset Management never gets Asset Management help, and nobody learns about modules hidden from them. A module switched off for the install is never searched.</li>
        <li><strong>System help only for administrators</strong> - the same rule as System itself.</li>
        <li><strong>In their language.</strong> Each analyst is searched and answered from the help in their interface language, with English filling any part not yet translated. The System help pages are in English for now.</li>
    </ul>
</div>

<!-- 6. The index -->
<div class="help-section" id="index">
    <div class="help-section-header"><?php echo helpSectionNum('index'); ?>
        <div>
            <h3>The help index</h3>
            <p>Why there is a Rebuild button, and when to press it.</p>
        </div>
    </div>
    <p>So it can answer instantly, the assistant reads every help page once and keeps them ready to search - the <strong>help index</strong>. It holds English and every language your analysts have chosen in their Preferences. It is only a copy of the help: rebuilding it never loses anything.</p>
    <ul>
        <li><strong>It is built for you</strong> the first time you save with the assistant switched on.</li>
        <li><strong>Rebuild after an upgrade.</strong> New versions add and improve help. System → Help assistant says so when the help has changed since the index was built.</li>
        <li><strong>Rebuild after adding a language</strong> - when an analyst switches to a language nobody used before.</li>
        <li>A rebuild runs in the background and takes about half a minute per language. You can leave the page.</li>
    </ul>
</div>

<!-- 7. Coming -->
<div class="help-section" id="coming">
    <div class="help-section-header"><?php echo helpSectionNum('coming'); ?>
        <div>
            <h3>What is coming</h3>
            <p>The same window, getting better as you give it more - never worse without it.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Set up</th><th>What it adds</th></tr></thead>
        <tbody>
            <tr><td><strong>Nothing</strong> (today)</td><td>Search help - the right sections, instantly, free.</td></tr>
            <tr><td><strong>An embedding key</strong></td><td>Matching by meaning, so <em>"my boss wants a weekly PDF"</em> finds Report packs. It will have its own key, so its cost shows separately.</td></tr>
            <tr><td><strong>A chat AI</strong> - any provider FreeITSM supports</td><td>A written answer in a sentence or two, in the asker's own language, still linking to the help it used.</td></tr>
        </tbody>
    </table></div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
