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
    <div class="help-note">The last two rows are what an embedding key improves - see <a href="#meaning">Matching by meaning</a>.</div>
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

<!-- 7. Unanswered questions -->
<div class="help-section" id="unanswered">
    <div class="help-section-header"><?php echo helpSectionNum('unanswered'); ?>
        <div>
            <h3>Unanswered questions</h3>
            <p>Every question the help could not answer, in the words people used - the best list there is of help worth writing.</p>
        </div>
    </div>
    <p>When the assistant finds nothing, or only weak matches, the question is kept on <strong>System → Help assistant → Unanswered questions</strong>, with the language it was asked in, the module the person was in and the closest section it did find.</p>
    <ul>
        <li><strong>Administrators get a bell notification</strong> - one entry with a count, however many come in. <strong>The person who asked never does</strong>, even when they are an administrator themselves. Anyone can turn the notification off in Preferences.</li>
        <li><strong>It is only kept when the help is really missing.</strong> A question the help answers in a module the asker cannot open is not recorded - the help exists; they just cannot see it.</li>
        <li><strong>Done</strong> marks a question handled once the help covers it; <strong>Reopen</strong> puts it back. <strong>New</strong>, <strong>Handled</strong> and <strong>All</strong> filter the list.</li>
    </ul>
    <h4>Recording who asked</h4>
    <p>By default a question is kept <strong>without a name</strong> - the list is there to improve the help, not to watch who asked. To follow up with someone, tick <strong>Record who asked</strong> beside them under <strong>Who has it</strong>. Their name is then kept with their unanswered questions, and <strong>their window says so</strong>: <em>"If the help can't answer a question, it's shared with your admins with your name."</em> Recording names silently would cost the trust that makes people willing to ask basic questions.</p>
</div>

<!-- 8. Matching by meaning -->
<div class="help-section" id="meaning">
    <div class="help-section-header"><?php echo helpSectionNum('meaning'); ?>
        <div>
            <h3>Matching by meaning</h3>
            <p>Optional. Give the assistant an embedding model and it understands loosely worded questions.</p>
        </div>
    </div>
    <p>Word search finds help that uses the same words as the question. An <strong>embedding model</strong> turns each help section, and each question, into a list of numbers that captures what it <em>means</em> - so <em>"my boss wants a weekly PDF of how we are doing"</em> finds <strong>Report packs</strong> although the two share no words. The assistant then merges both: a section that matches by words <em>and</em> by meaning comes first.</p>
    <div class="help-table"><table>
        <thead><tr><th>Asked</th><th>Words alone</th><th>Words and meaning</th></tr></thead>
        <tbody>
            <tr><td>A plain question</td><td>43 of 47</td><td>46 of 47</td></tr>
            <tr><td>An exact name</td><td>6 of 6</td><td>6 of 6</td></tr>
            <tr><td>Something vague</td><td>2 of 6</td><td>6 of 6</td></tr>
            <tr><td>Another language</td><td>2 of 6</td><td>3 of 6</td></tr>
        </tbody>
    </table></div>
    <p class="help-muted">A right section in the first five, on the same 65 test questions.</p>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div>On <strong>System → Help assistant</strong>, under <strong>Matching by meaning</strong>, tick <strong>Match questions by meaning</strong>.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div>Choose the provider - <strong>OpenAI</strong>, <strong>Azure OpenAI</strong> or <strong>your own model server</strong> (Ollama, LM Studio and the like) - enter its key or address, and pick the model from the list. <strong>Test</strong> checks it works.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Save.</strong> The first time, the help's vectors are built in the background. With OpenAI's <strong>text-embedding-3-small</strong> they come ready-made with each release, so only sections changed on your install are paid for.</div></div>
    </div>
    <ul>
        <li><strong>Its own key</strong>, stored encrypted, so its cost shows separately. Building costs about a penny on OpenAI; each question afterwards a tiny fraction of that.</li>
        <li><strong>Each question is sent to that provider.</strong> Choose your own model server to keep everything on your network.</li>
        <li><strong>Changing the model needs new vectors</strong> - vectors from different models cannot be compared. The page says so and offers <strong>Rebuild</strong>; the old model keeps answering until the new vectors are complete. Switching back to text-embedding-3-small uses the ready-made ones, at no cost.</li>
        <li><strong>It never makes things worse.</strong> If the provider cannot be reached, the assistant quietly searches by words, as it does with nothing set up.</li>
    </ul>
</div>

<!-- 9. Written answers -->
<div class="help-section" id="answers">
    <div class="help-section-header"><?php echo helpSectionNum('answers'); ?>
        <div>
            <h3>Written answers</h3>
            <p>Optional. Give the assistant a chat AI and it writes the answer in a sentence or two, in the asker's own language.</p>
        </div>
    </div>
    <p>The AI is given the help sections the search found - and only those - and told to answer from them alone: never from what it knows of other products, never inventing a menu or button, and writing menu names exactly as the help does, so they match the screen. It keeps the links to the sections it used, so every answer can be checked. When the sections do not answer the question it searches the help again itself; when the help really does not cover it, it says so, and the question joins <a href="#unanswered">Unanswered questions</a>.</p>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div>On <strong>System → Help assistant</strong>, under <strong>Written answers</strong>, choose a provider, model and key in the AI panel - any provider FreeITSM supports, including your own model server. A small, fast model is plenty.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div>Tick <strong>Write answers with AI</strong>.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div>Use <strong>Try it</strong> to ask a question as yourself and see the answer, the sections the AI was given and how long it took. Nothing is saved.</div></div>
    </div>
    <ul>
        <li><strong>Measured:</strong> on 65 test questions with Claude Haiku, 46 of 47 plain questions and all 6 asking for a feature by name were answered from the right section, in about three seconds and a third of a penny each.</li>
        <li><strong>Its own key</strong>, stored encrypted, so its cost shows separately. Each question, and the help sections found for it, are sent to that provider - choose your own model server to keep everything on your network.</li>
        <li><strong>Permissions are respected.</strong> Someone asking about a setting they cannot change is told who can - an administrator, or someone with that module's settings access - rather than how.</li>
        <li><strong>Other languages.</strong> The answer comes in the asker's language, with menu names left as they appear on the screen. The System help pages are in English only for now, so a question in another language about System can be answered less well.</li>
        <li><strong>It never makes things worse.</strong> If the AI cannot be reached, or its credit runs out, the assistant replies with the search results instead.</li>
    </ul>
</div>

<!-- 10. Coming -->
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
            <tr><td><strong>Nothing</strong></td><td>Search help - the right sections, instantly, free.</td></tr>
            <tr><td><strong>An embedding key</strong> (available now)</td><td>Matching by meaning, so <em>"my boss wants a weekly PDF"</em> finds Report packs - see <a href="#meaning">above</a>.</td></tr>
            <tr><td><strong>A chat AI</strong> (available now)</td><td>A written answer in a sentence or two, in the asker's own language, still linking to the help it used - see <a href="#answers">above</a>.</td></tr>
        </tbody>
    </table></div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
