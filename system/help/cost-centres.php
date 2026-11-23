<?php
/**
 * System Help — Cost Centres (GH #160).
 * What a cost centre is in FreeITSM, why codes are text, the hierarchy,
 * inactive versus delete, the spreadsheet import, and the API sync.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'cost-centres';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What a cost centre is here</h3>
            <p>The codes your finance department charges costs to. Some organisations call them nominal codes or budget codes.</p>
        </div>
    </div>
    <p>Each cost centre has a <strong>code</strong>, a <strong>name</strong>, an optional <strong>description</strong>, an optional <strong>parent</strong> and is either <strong>active</strong> or <strong>inactive</strong>. You manage them on <strong>System &rarr; Cost Centres</strong>.</p>
    <p><strong>Every cost centre belongs to one company.</strong> If you run several companies from one install, the list shown is for the company chosen in the company switcher in the top bar; each company has its own list, and two companies can both have a cost centre 0010 without getting in each other's way. Choose <strong>All companies</strong> in the switcher to see every company's cost centres at once, with a Company column; Add and Import then ask which company, and Export lists each company separately, because a file is always one company's list. If you have only ever had one company there is nothing to choose and nothing to think about.</p>
    <div class="help-note"><strong>This is the list itself.</strong> Charging assets and service bookings to a cost centre comes next. Setting up the list now means it is ready, and already in step with your finance system, when that arrives.</div>
</div>

<!-- 2. Codes -->
<div class="help-section" id="codes">
    <div class="help-section-header"><?php echo helpSectionNum('codes'); ?>
        <div>
            <h3>Codes and leading zeros</h3>
            <p>A code is kept exactly as your finance system writes it.</p>
        </div>
    </div>
    <ul>
        <li><strong>Letters and numbers both work.</strong> 0010, N1414, 4000-100 and CC.01 are all fine, up to 50 characters.</li>
        <li><strong>Leading zeros are kept.</strong> A code is stored as text, never as a number, so 0010 stays 0010.</li>
        <li><strong>Capitals are ignored when matching.</strong> N1414 and n1414 are the same code, so you cannot end up with both. The code is shown the way it was last written.</li>
        <li><strong>Spaces at either end are removed.</strong> Nothing else about the code is changed.</li>
    </ul>
</div>

<!-- 3. Hierarchy -->
<div class="help-section" id="hierarchy">
    <div class="help-section-header"><?php echo helpSectionNum('hierarchy'); ?>
        <div>
            <h3>Building a hierarchy</h3>
            <p>Put a cost centre under another by choosing its <strong>Parent cost centre</strong>.</p>
        </div>
    </div>
    <p>The list shows the tree: each cost centre sits under its parent, and levels are sorted by code. There is no limit to how deep it goes. Searching keeps the parents of every match on screen, faded, so you can see where a match sits.</p>
    <p>A parent must be in the same company. A cost centre can never be put under itself or under anything below it, because the tree would then go round in a loop. FreeITSM refuses that, whether it comes from the screen, an import or the API.</p>
</div>

<!-- 4. Inactive -->
<div class="help-section" id="inactive">
    <div class="help-section-header"><?php echo helpSectionNum('inactive'); ?>
        <div>
            <h3>Inactive or delete?</h3>
            <p>Almost always: make it inactive.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th></th><th>What happens</th><th>Use it when</th></tr></thead>
        <tbody>
            <tr><td><strong>Inactive</strong></td><td>It stays on everything already charged to it, but is not offered for anything new. You can make it active again at any time.</td><td>The cost centre has been closed or replaced. This is the normal path.</td></tr>
            <tr><td><strong>Delete</strong></td><td>It is gone. A cost centre with others below it, or with anything charged to it, cannot be deleted - the message says what is in the way.</td><td>It was created by mistake.</td></tr>
        </tbody>
    </table></div>
    <p>Untick <strong>Show inactive</strong> to hide inactive cost centres from the list.</p>
</div>

<!-- Charging things -->
<div class="help-section" id="charging">
    <div class="help-section-header"><?php echo helpSectionNum('charging'); ?>
        <div>
            <h3>Charging assets, contracts and people</h3>
            <p>Each asset, contract and person can be charged to one cost centre.</p>
        </div>
    </div>
    <ul>
        <li><strong>Assets</strong> - the <strong>Cost centre</strong> box beside Supplier on an asset. Assets &rarr; Table has a Cost centre column to sort and filter by, and the asset spreadsheet import takes a <strong>Cost centre</strong> column of codes.</li>
        <li><strong>Contracts</strong> - <strong>Cost centre</strong> under Financial on the contract form.</li>
        <li><strong>People</strong> - <strong>Cost centre</strong> when you edit someone in Tickets &rarr; Users or Assets &rarr; Users, and shown on their People page.</li>
    </ul>
    <p>The list offers the <strong>active</strong> cost centres of the record's own company (someone with no company: the Default company's). Something already charged to a cost centre that has since been made inactive keeps it. Moving a contract or a person to another company needs one of the new company's cost centres, or none.</p>
    <div class="help-note"><strong>Contracts from before 3.5.0</strong> had a cost centre box you typed into. Where the text matched a cost centre's code it was linked automatically; anything else is shown on the contract as <em>Previously recorded</em> until you choose a cost centre.</div>
    <p>The REST API takes <code>cost_centre_id</code> on assets, contracts and requesters (contracts also take the code as <code>cost_centre</code>), and the asset import matches on code - so your finance system's codes are all you need.</p>
</div>

<!-- GH #160 Part B: owner and deputy -->
<div class="help-section" id="owner">
    <div class="help-section-header"><?php echo helpSectionNum('owner'); ?>
        <div>
            <h3>An owner and a deputy</h3>
            <p>Who answers for each budget - the people who will approve requests charged to it.</p>
        </div>
    </div>
    <ul>
        <li><strong>Owner</strong> - one <strong>person</strong>, or a <strong>people group</strong> when a team shares the budget (controlling, or a third-party funded project, say). A people group can include portal users, so its members do not need a service desk account.</li>
        <li><strong>Deputy</strong> - a person who covers for the owner.</li>
    </ul>
    <p>Set them in the cost centre's <strong>Edit</strong> window, where you search for a person by name or email. A named person must be <strong>active</strong> and in the cost centre's own company (someone with no company counts as the Default company's), and the deputy cannot be the owner. People groups belong to the whole install.</p>
    <p>The <strong>Owner</strong> column shows both, and a person who has since left is kept and marked <em>left</em> rather than removed, so you can see what needs a new owner. Each person's People page lists the cost centres they are <strong>Responsible for</strong>.</p>
    <div class="help-note">Your finance system can keep them up to date: the import and the API take an <strong>Owner email</strong>, <strong>Owner group</strong> or <strong>Deputy email</strong> for each cost centre (see below). Requests charged to a cost centre will be approved by its owner when financial approvals arrive.</div>
</div>

<!-- 5. Import / export -->
<div class="help-section" id="import">
    <div class="help-section-header"><?php echo helpSectionNum('import'); ?>
        <div>
            <h3>Importing and exporting</h3>
            <p>Bring a whole list in from a spreadsheet, or take it out.</p>
        </div>
    </div>
    <p><strong>Export</strong> downloads the company's list as an <strong>Excel workbook (.xlsx)</strong> or a <strong>CSV</strong> file, with the columns <strong>Code</strong>, <strong>Name</strong>, <strong>Description</strong>, <strong>Parent code</strong> and <strong>Active</strong>. The easiest way to prepare an import is to export, edit that file and import it again.</p>
    <p><strong>Import</strong> reads an .xlsx or CSV file whose first row is headings:</p>
    <ul>
        <li>Only <strong>Code</strong> is required. A code that is new also needs a <strong>Name</strong>.</li>
        <li>Headings are matched ignoring capitals and spaces, and common alternatives work too, for example <em>Cost centre</em>, <em>Number</em> or <em>Kostenstelle</em> for the code. Columns that are not recognised are ignored, so an export straight from your finance system usually works as it is.</li>
        <li><strong>Active</strong> takes Yes or No (also True/False, 1/0, Active/Inactive, Ja/Nein). A blank cell leaves it as it is; a new cost centre is active.</li>
        <li>A blank <strong>Parent code</strong> means top level. A parent can be another row in the same file.</li>
        <li>CSV files saved with commas, semicolons (Excel in much of Europe) or tabs are all read.</li>
    </ul>
    <p>Cost centres are matched on their code: codes already in the list are updated, new ones are added, and <strong>nothing is ever deleted</strong>. Switch on <strong>Make cost centres that are not in the file inactive</strong> when the file is your complete list.</p>
    <p><strong>Preview</strong> shows exactly what will happen, row by row, before anything changes. If any row has a problem, every problem is listed with its line number and <strong>nothing is imported</strong>, not even the good rows. Fix the file and preview again.</p>
    <div class="help-note warn"><strong>Leading zeros and CSV files.</strong> If you open a CSV in Excel and save it again, Excel turns 0010 into 10 before FreeITSM ever sees the file, and there is no way to tell afterwards. Edit your list in the <strong>Excel export</strong>: its codes are stored as text, so the zeros stay. A workbook where a code was typed as a number but formatted to show its zeros (a 0000 format) is read the way it is shown.</div>
</div>

<!-- 6. API -->
<div class="help-section" id="api">
    <div class="help-section-header"><?php echo helpSectionNum('api'); ?>
        <div>
            <h3>Syncing from your finance system</h3>
            <p>Keep the list in step automatically, as often as you like.</p>
        </div>
    </div>
    <p>Cost centres can be read and changed through the REST API. Create an API key on <strong>System &rarr; API</strong> with the <strong>Cost centres</strong> permissions it needs. The endpoint built for an accounting system is <code>POST /api/v1/cost-centres/sync</code>:</p>
    <ul>
        <li>Send the list for one company, and it is matched on code exactly as an import is. Sending the same list every night creates nothing twice.</li>
        <li>Only the fields you send are changed. Send just codes and names, and descriptions are left alone.</li>
        <li><code>"deactivate_missing": true</code> makes anything no longer in the list inactive. Nothing is ever deleted.</li>
        <li><code>"dry_run": true</code> reports what would change and changes nothing.</li>
        <li>It is all or nothing. One bad item and nothing is written; the response lists every problem with its position in the list.</li>
        <li>The key needs both the <strong>create</strong> and <strong>update</strong> permissions, because a sync can do both.</li>
    </ul>
    <p>The full reference, with examples you can try, is on <strong>System &rarr; API &rarr; Documentation</strong> under <em>Cost centres</em>.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
