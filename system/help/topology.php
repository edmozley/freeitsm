<?php
/**
 * System Help — Topology.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'topology';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What it's for</h3></div>
    <p class="syshelp-lead">Your configuration is spread across half a dozen System pages — companies here, mailboxes there, sign-in providers somewhere else. Topology gathers all of it into one read-only view, arranged the way you actually think about it: <strong>a company, and everything that hangs off it</strong>.</p>
    <p>It answers the questions that are otherwise a five-page tour:</p>
    <ul>
        <li>Which mailbox does this company receive on — and is it still signed in?</li>
        <li>Which email domains route to it?</li>
        <li>Can it actually <em>send</em> a reply, or has it got no working mailbox?</li>
        <li>Who can see it — and who has access to <em>everything</em>?</li>
    </ul>
    <div class="syshelp-callout info"><strong>Nothing here is editable.</strong> Topology only looks. Every row has an <strong>open</strong> or <strong>manage</strong> link that takes you to the page where the thing can actually be changed, so it doubles as a map of where to go next.</div>
</div>

<!-- 2. Reading the tree -->
<div class="syshelp-section" id="reading">
    <div class="syshelp-section-header"><h3>Reading the tree</h3></div>
    <p class="syshelp-lead">Despite the name, it isn't a drawn diagram — it's a collapsible tree, which is far easier to scan and to search. Across the top is a strip of totals: companies, mailboxes, sign-in providers, analysts and requesters.</p>
    <p>Below that, one node per company, expanded, with its categories folded up underneath. You can:</p>
    <table class="syshelp-table">
        <tr><th>Control</th><th>What it does</th></tr>
        <tr><td><strong>Click a row</strong></td><td>Expands or collapses it.</td></tr>
        <tr><td><strong>Expand all</strong> / <strong>Collapse all</strong></td><td>Opens or closes everything at once. Expand all then Ctrl-F is a good way to hunt for a specific address.</td></tr>
        <tr><td><strong>Filter companies</strong></td><td>Narrows the tree to companies whose name matches what you type.</td></tr>
    </table>
    <p>Watch the tags on a company row. <strong>default</strong> marks the company that unmatched email falls back to. An amber <strong>no sendable mailbox</strong> is the one that should stop you: it means that company has no active, signed-in mailbox — pinned or shared — so FreeITSM has nothing to send its replies from.</p>
</div>

<!-- 3. What's under a company -->
<div class="syshelp-section" id="company">
    <div class="syshelp-section-header"><h3>What's under a company</h3></div>
    <table class="syshelp-table">
        <tr><th>Category</th><th>What it tells you</th></tr>
        <tr><td><strong>Mailboxes</strong></td><td>Every mailbox this company can use — the ones pinned to it, plus every shared mailbox (tagged <strong>shared</strong>). Expand one for its address, kind (Microsoft 365, Google Workspace or IMAP), folder, status and when it was last checked. The badge is the bit that matters: <strong>signed in</strong> green, <strong>not signed in</strong> amber, or <strong>inactive</strong>.</td></tr>
        <tr><td><strong>Domains</strong></td><td>The email domains that route inbound mail to this company.</td></tr>
        <tr><td><strong>Specific senders</strong></td><td>Individual addresses pinned to this company — how you route someone on a personal or free email address that no domain rule could catch. Only shown if any exist.</td></tr>
        <tr><td><strong>Sign-in providers</strong></td><td>The identity providers this company's people sign in with. Disabled ones are tagged. &ldquo;None&rdquo; means they use the global provider or a local password.</td></tr>
        <tr><td><strong>Analysts</strong></td><td>The <em>restricted</em> analysts who have access to this company. Analysts who can reach everything aren't repeated here — they're in the Global node instead.</td></tr>
        <tr><td><strong>Requesters</strong></td><td>How many requesters are attributed to this company, matched by the domain of their email address.</td></tr>
        <tr><td><strong>Tickets</strong></td><td>Total and open ticket counts for the whole company, across every department.</td></tr>
    </table>
</div>

<!-- 4. Global & shared -->
<div class="syshelp-section" id="global">
    <div class="syshelp-section-header"><h3>Global &amp; shared</h3></div>
    <p class="syshelp-lead">The last node in the tree isn't a company — it's everything that serves <em>every</em> company: shared mailboxes, global sign-in providers, and the analysts who have all-company access.</p>
    <div class="syshelp-callout info"><strong>This is the node to read carefully on a multi-company install.</strong> The all-access analyst list is your answer to &ldquo;who can see every client's tickets?&rdquo; — a question worth asking out loud now and again. If someone is on that list who shouldn't be, fix it on System &rarr; Analysts (or on their team, which may be granting it).</div>
</div>

<!-- 5. What to check for -->
<div class="syshelp-section" id="using">
    <div class="syshelp-section-header"><h3>What to check for</h3></div>
    <p class="syshelp-lead">Topology is at its best right after you set something up, and once in a while as a review. A quick pass:</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><span class="syshelp-step-num">1</span><div>Does any company carry the amber <strong>no sendable mailbox</strong> tag? Its replies aren't going anywhere. Reconnect or pin a mailbox.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">2</span><div>Is every mailbox badge green? An amber <strong>not signed in</strong> means the OAuth connection has lapsed and mail has quietly stopped flowing.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">3</span><div>Does each company have the domains you expect? A missing domain is the usual cause of email landing in the wrong company — and the <strong>Email routing test</strong> page will prove exactly which rule decides.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">4</span><div>Is the <strong>all-access analysts</strong> list under Global the list you'd have written from memory?</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">5</span><div>Does any company show zero analysts? Nobody is looking after it.</div></div>
    </div>
</div>

<!-- 6. Good to know -->
<div class="syshelp-section" id="notes">
    <div class="syshelp-section-header"><h3>Good to know</h3></div>
    <ul>
        <li><strong>Shared mailboxes appear more than once</strong> — under every company that can use them, and again under Global. That's not a duplicate, it's the point: it shows you every company the mailbox serves.</li>
        <li><strong>The filter box only matches company names</strong>, and it filters the top-level nodes — so typing a company name also hides the Global node. Clear it to bring everything back.</li>
        <li><strong>Requester counts are an approximation.</strong> A requester is attributed to a company by the domain of their email address, so anyone on a domain you haven't mapped counts in the total but appears under no company.</li>
        <li><strong>An empty section may mean &ldquo;not set up&rdquo; rather than &ldquo;nothing there&rdquo;</strong> — the page is deliberately tolerant and shows a section as empty rather than erroring. If a whole category looks blank and you didn't expect it to, run <strong>Database verification</strong>.</li>
        <li><strong>It works on a single-company install too</strong>, where everything simply hangs off one default company.</li>
    </ul>
    <div class="syshelp-callout ok"><strong>Nothing you do here can break anything.</strong> It's read-only. Expand it, scan it, and follow the links to fix whatever looks wrong.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
