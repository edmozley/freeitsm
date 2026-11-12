<?php
/**
 * System Help — API.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'api';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What the API is</h3>
            <p>The REST API (version 1) lives at <code>/api/v1/</code> and covers most of FreeITSM - tickets, tasks, problems, changes, assets, the CMDB, knowledge, contracts, projects (including their documents), the chart of accounts and more - plus the reference data an integration needs. The <strong>Documentation</strong> button lists every endpoint and the permission it needs.</p>
        </div>
    </div>
    <p>Everything an API key does behaves exactly as if it were done in the UI: audit entries are written, assignment and closure emails go out, CSAT can auto-trigger on close, and the workflow engine fires its events — so your workflows run whether a ticket was updated by an analyst or by an integration.</p>
    <div class="help-note"><strong>One prerequisite.</strong> Run <a href="db-verify.php">Database Verification</a> once after updating, so the API key tables are created.</div>
</div>

<!-- 2. Keys -->
<div class="help-section" id="keys">
    <div class="help-section-header"><?php echo helpSectionNum('keys'); ?>
        <div>
            <h3>Creating keys</h3>
            <p>Open <strong>System &gt; API</strong> and click <strong>Add</strong>. A key has a name (what it's for), an analyst it <em>acts as</em>, a permission set, an optional company scope, an optional expiry date and an optional rate limit.</p>
        </div>
    </div>
    <div class="help-steps">
        <div class="help-step"><span class="help-step-num">1</span><div><strong>Name the key</strong> after the integration ("Zabbix monitoring", "Onboarding script") so you can revoke the right one later.</div></div>
        <div class="help-step"><span class="help-step-num">2</span><div><strong>Choose who it acts as.</strong> Notes, audit entries and time logged through the key are attributed to this analyst. Many teams create a dedicated "Integration" analyst for this.</div></div>
        <div class="help-step"><span class="help-step-num">3</span><div><strong>Tick only the permissions it needs</strong> — see the next section.</div></div>
        <div class="help-step"><span class="help-step-num">4</span><div><strong>Copy the key immediately.</strong> It is shown once, then only a SHA-256 hash is stored — it can never be displayed again. Lose it and you simply create a new key.</div></div>
    </div>
    <p>From the key list you can edit a key's name, permissions, scope, expiry and rate limit at any time, disable it temporarily, or delete it outright — integrations using a disabled or deleted key stop working immediately.</p>
</div>

<!-- 3. Permissions -->
<div class="help-section" id="permissions">
    <div class="help-section-header"><?php echo helpSectionNum('permissions'); ?>
        <div>
            <h3>Granular permissions</h3>
            <p>Keys start with <strong>no permissions</strong>. Each resource has its own actions you grant independently, so a key can, say, create tickets but never read them, or read everything but change nothing. The table shows the most-used resources; the key form lists every one, and the <strong>Documentation</strong> button shows which permission each endpoint needs.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Resource</th><th>Actions</th></tr></thead>
        <tbody>
            <tr><td>Tickets</td><td>read, create, update, delete (trash), restore</td></tr>
            <tr><td>Ticket notes</td><td>read, create</td></tr>
            <tr><td>Ticket checklists</td><td>read, update</td></tr>
            <tr><td>Ticket conversation</td><td>read</td></tr>
            <tr><td>Ticket audit log</td><td>read</td></tr>
            <tr><td>Ticket SLA</td><td>read</td></tr>
            <tr><td>Time entries</td><td>read, create, delete</td></tr>
            <tr><td>Task checklists</td><td>read, update</td></tr>
            <tr><td>Requesters</td><td>read, create, update</td></tr>
            <tr><td>Analysts</td><td>read</td></tr>
            <tr><td>Companies</td><td>read</td></tr>
            <tr><td>Reference data</td><td>read</td></tr>
        </tbody>
    </table></div>
    <div class="help-note"><strong>Example.</strong> A monitoring tool that raises tickets when servers go down needs only <em>Tickets: create</em> (and perhaps <em>Reference data: read</em> to look up priority ids). If its key leaks, nobody can read a single ticket with it.</div>
</div>

<!-- 4. Companies -->
<div class="help-section" id="companies">
    <div class="help-section-header"><?php echo helpSectionNum('companies'); ?>
        <div>
            <h3>Company scope (multi-company installs)</h3>
            <p>On a multi-company (MSP) install each key is either scoped to <strong>all companies</strong> or to a <strong>specific list</strong>. A scoped key only ever sees, creates or touches records belonging to its companies — the same isolation analysts get.</p>
        </div>
    </div>
    <p>Tickets created through a scoped key default to your Default company if it's in scope, otherwise the key's first scoped company; pass <code>company_id</code> to file under a specific one. Moving a ticket between companies requires the key to be scoped to <em>both</em> sides.</p>
    <div class="help-note"><strong>A key never reaches further than its analyst.</strong> Every key acts as an analyst, and only ever covers companies that analyst can see: <em>all companies</em> means all of that analyst's companies, and a list is cut down to them. You cannot save a key for a company its analyst cannot see.</div>
    <p>On a single-company install this section doesn't appear — there's nothing to scope.</p>
</div>

<!-- 5. Using -->
<div class="help-section" id="using">
    <div class="help-section-header"><?php echo helpSectionNum('using'); ?>
        <div>
            <h3>Using the API</h3>
            <p>Send the key in the <code>Authorization</code> header. Requests and responses are JSON; real HTTP status codes carry the outcome (200/201 success, 401 bad key, 403 missing permission, 404 not visible, 422 invalid input, 429 rate limited).</p>
        </div>
    </div>
    <div class="help-card">
        <h4>Create a ticket from anything that can make an HTTP request</h4>
        <pre style="background:#263238;color:#eceff1;border-radius:6px;padding:14px;font-size:12px;overflow-x:auto;">curl -X POST "https://your-server/api/v1/tickets" \
  -H "Authorization: Bearer fitsm_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"subject":"Disk space low on SQL01","requester_email":"alerts@example.com","description":"C: below 5% free.","priority":"High"}'</pre>
    </div>
    <p>List endpoints support filtering (<code>?state=open&amp;priority=High</code>), search (<code>?q=printer</code>), date ranges, sorting and pagination. Every response from a list endpoint includes a <code>meta</code> block with the total count and page numbers.</p>
    <div class="help-note"><strong>Rate limits.</strong> Keys get 60 requests/minute by default (configurable per key). Responses carry <code>X-RateLimit-Remaining</code> so integrations can pace themselves; exceeding the limit returns 429.</div>
    <div class="help-note"><strong>No URL rewriting?</strong> If your web server doesn't have mod_rewrite, the same endpoints work at <code>/api/v1/index.php/tickets</code> — the docs tester and paths are otherwise identical.</div>
</div>

<!-- 6. Docs -->
<div class="help-section" id="docs">
    <div class="help-section-header"><?php echo helpSectionNum('docs'); ?>
        <div>
            <h3>Documentation &amp; testing</h3>
            <p>The <strong>Documentation</strong> button on the API page opens a live reference: every endpoint with its parameters, required permission and an example body — plus a <strong>Try it</strong> panel that fires real requests against your install and shows the response, status code and remaining rate limit in the browser.</p>
        </div>
    </div>
    <p>Paste a key at the top of the page (it stays in your browser only), hit <strong>Test</strong> to confirm what the key can do, then expand any endpoint and send. Each request also shows the equivalent <code>curl</code> command ready to paste into a script.</p>
</div>

<!-- 7. MCP -->
<div class="help-section" id="mcp">
    <div class="help-section-header"><?php echo helpSectionNum('mcp'); ?>
        <div>
            <h3>Connecting an AI assistant (MCP)</h3>
            <p>FreeITSM speaks the <strong>Model Context Protocol</strong>, so an AI assistant such as Claude Code or Claude Desktop can read it: <em>"Which projects are off track, and why?"</em>, <em>"Is the VPN down?"</em></p>
        </div>
    </div>
    <p>Create a key with the <strong>MCP server</strong> permission and give the assistant the address shown in the <strong>Connect an AI assistant</strong> card on the API page, with the key as a bearer token. The card shows the command for Claude Code. It is <strong>read-only</strong>: the assistant sees exactly what the key's analyst may see - their modules and their companies - and can change nothing.</p>
    <div class="help-note"><strong>Pick the analyst with care.</strong> The assistant reads with that analyst's eyes. A key for an analyst who can see everything gives the assistant everything.</div>
</div>

<!-- 8. Safety -->
<!-- Where and when (5.1.0, GH #287) -->
<div class="help-section" id="restrict">
    <div class="help-section-header"><?php echo helpSectionNum('restrict'); ?>
        <div>
            <h3>Where and when a key may be used</h3>
            <p>Each key's <strong>Edit</strong> window has an optional <strong>Where and when</strong> box. Leave it blank and the key works from anywhere, at any time, as before.</p>
        </div>
    </div>
    <ul>
        <li><strong>Allowed addresses</strong> - one address or range per line (<code>203.0.113.7</code>, <code>10.20.0.0/16</code>, IPv6 too). A request from anywhere else is refused. This is the best protection there is against a stolen key: whoever took it is not on your network. Behind a reverse proxy every request arrives from the proxy, so list the proxy.</li>
        <li><strong>Allowed hours</strong> - days and a time window, in the server's time zone (<em>Mon-Fri, 07:00-19:00</em>). Outside it, either <strong>refuse the request</strong> or <strong>allow it but alert administrators</strong>. A window that ends before it starts runs past midnight.</li>
        <li><strong>Download limits for this key</strong> - its own alert and stop levels for document downloads; blank uses the Threat protection tab.</li>
    </ul>
</div>

<!-- Threat protection (5.1.0, GH #287) -->
<div class="help-section" id="threats">
    <div class="help-section-header"><?php echo helpSectionNum('threats'); ?>
        <div>
            <h3>Threat protection</h3>
            <p>The <strong>Threat protection</strong> tab of System &gt; API decides what happens when a key behaves like it might have been stolen. Every alert goes to administrators by bell and email (from the first mailbox that can send), at most once an hour for the same key and reason, and every alert and refusal is in the <strong>Log</strong> on that tab.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Watching for</th><th>What happens</th><th>Default</th></tr></thead>
        <tbody>
            <tr><td>Many document downloads</td><td>Alert, then refuse downloads until the hour has passed - or switch the key off</td><td>Alert at 50 files or 500 MB an hour; stop at 200 files or 2 GB</td></tr>
            <tr><td>Probing - a key refused again and again (403 or 404)</td><td>Alert, then switch the key off</td><td>Alert at 20, off at 100, in 10 minutes</td></tr>
            <tr><td>Key guessing - an address sending missing or wrong keys</td><td>Block that address from the API for a while, and alert</td><td>50 bad keys in 15 minutes blocks it for 15 minutes</td></tr>
            <tr><td>A key used from an address it has not used in 30 days</td><td>Alert - never a block</td><td>Bell and email</td></tr>
            <tr><td>A key used from an address or at a time it may not</td><td>Refuse (or alert only, for hours), and alert</td><td>Set per key</td></tr>
        </tbody>
    </table></div>
    <p>FreeITSM answers <em>"not yours"</em> and <em>"does not exist"</em> the same way on purpose, so nobody can map what exists - which also means probing and working through ids show up as the same thing: a key refused again and again.</p>
    <p>A key switched off by threat protection says why on the key list. Check the log, then switch it back on (or delete it and issue a new one).</p>
    <div class="help-note"><strong>Downloading documents needs its own permission.</strong> <em>Document contents: download</em> is never ticked by <strong>Select all</strong> and no existing key has it. A key that files paperwork onto projects needs only <em>Projects: update</em> - give <em>download</em> only to a key that genuinely has to take files away. Every download through the API is logged with the key, the address and the size.</div>
</div>

<div class="help-section" id="safety">
    <div class="help-section-header"><?php echo helpSectionNum('safety'); ?>
        <div>
            <h3>Good practice</h3>
        </div>
    </div>
    <ul>
        <li><strong>One key per integration.</strong> Never share a key between systems — you lose the ability to revoke one without breaking the other.</li>
        <li><strong>Least privilege.</strong> Grant the smallest permission set that works; you can always add more later.</li>
        <li><strong>Make integration keys for a dedicated, limited analyst - never an administrator.</strong> A key can see exactly what its analyst can: their companies, modules, projects and documents. An administrator's key sees everything.</li>
        <li><strong>Lock keys down</strong> with allowed addresses (and hours, where the integration keeps them) - see <em>Where and when</em>.</li>
        <li><strong>Set expiry dates</strong> on keys for temporary work (contractors, migrations) so they clean themselves up.</li>
        <li><strong>Watch "Last used".</strong> The key list shows when and from which IP each key last authenticated — a key that's never used, or used from somewhere unexpected, is worth investigating.</li>
        <li><strong>Use HTTPS in production.</strong> The key travels in a header; TLS keeps it private.</li>
    </ul>
    <div class="help-note"><strong>Note:</strong> the legacy keys under Software settings (used by the asset-inventory script and Watchtower extension) are a separate, older mechanism and are unaffected by anything here.</div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
