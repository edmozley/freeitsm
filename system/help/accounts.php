<?php
/**
 * System Help — Chart of Accounts (GH #282, slice 1).
 * What an account is here, its kind, codes, the hierarchy, inactive versus delete,
 * the spreadsheet import and the API sync. Shaped like the Cost Centres help on
 * purpose - the two lists work the same way.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'accounts';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What an account is here</h3>
            <p>The codes your finance department reports costs and income by - <em>2.0.3.0.4 Software Licences</em>, <em>2.1.0.3.2 Computer Equipment</em>.</p>
        </div>
    </div>
    <p>Accountants keep two separate lists. A <strong>cost centre</strong> says <em>who</em> pays - a department or a budget holder. An <strong>account</strong> says <em>what kind of cost</em> it is - software licences, equipment, professional services, payroll. FreeITSM has both, side by side: one never replaces the other.</p>
    <p>Each account has a <strong>code</strong>, a <strong>name</strong>, a <strong>kind</strong>, an optional <strong>description</strong>, an optional <strong>parent</strong> and is either <strong>active</strong> or <strong>inactive</strong>. You manage them on <strong>System &rarr; Chart of Accounts</strong>.</p>
    <p><strong>Every account belongs to one company.</strong> The list shown is for the company chosen in the company switcher in the top bar, and two companies can both have an account 2.0.3.0.4. Choose <strong>All companies</strong> in the switcher to see every company's accounts at once; Add and Import then ask which company, and Export lists each company separately. With only one company there is nothing to choose.</p>
    <div class="help-note"><strong>This is the list itself.</strong> Naming accounts on a project's budget lines and tasks, and seeing a project's costs and income broken down by account, come next. Setting up the chart now means it is ready, and in step with your finance system, when they arrive.</div>
</div>

<!-- 2. Kinds -->
<div class="help-section" id="kinds">
    <div class="help-section-header"><?php echo helpSectionNum('kinds'); ?>
        <div>
            <h3>The kind of account</h3>
            <p>Every account is one of four kinds, chosen from a list.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Kind</th><th>For</th><th>Example</th></tr></thead>
        <tbody>
            <tr><td><strong>Expense</strong></td><td>Running costs - spent and gone</td><td>Software Licences, Professional Services</td></tr>
            <tr><td><strong>Fixed asset</strong></td><td>Equipment that is capitalised and kept - including the time spent building it</td><td>Computer Equipment</td></tr>
            <tr><td><strong>Payroll</strong></td><td>People's time and pay</td><td>Employee Payroll</td></tr>
            <tr><td><strong>Income</strong></td><td>Money coming in - a charge to a client, grant funding</td><td>Recharges</td></tr>
        </tbody>
    </table></div>
    <p>The kind decides which section of a project's costs-by-account breakdown an account is counted in, so income is never added to costs. A new account placed under a parent starts with the parent's kind; you can change it.</p>
</div>

<!-- 3. Codes -->
<div class="help-section" id="codes">
    <div class="help-section-header"><?php echo helpSectionNum('codes'); ?>
        <div>
            <h3>Codes and leading zeros</h3>
            <p>A code is kept exactly as your finance system writes it.</p>
        </div>
    </div>
    <ul>
        <li><strong>Any format works.</strong> 2.0.3.0.4, 4000-100, 0010 and N1414 are all fine, up to 50 characters.</li>
        <li><strong>Leading zeros are kept.</strong> A code is stored as text, never as a number.</li>
        <li><strong>Capitals are ignored when matching.</strong> N1414 and n1414 are the same code, so you cannot end up with both.</li>
        <li><strong>Spaces at either end are removed.</strong> Nothing else about the code is changed.</li>
    </ul>
</div>

<!-- 4. Hierarchy -->
<div class="help-section" id="hierarchy">
    <div class="help-section-header"><?php echo helpSectionNum('hierarchy'); ?>
        <div>
            <h3>Building a hierarchy</h3>
            <p>Put an account under another by choosing its <strong>Parent account</strong> - 2.0.3.0.4 under 2.0.3.0, say.</p>
        </div>
    </div>
    <p>The list shows the tree, each level sorted by code, as deep as you need. Searching keeps the parents of every match on screen, faded. A parent must be in the same company, and an account can never be put under itself or anything below it - FreeITSM refuses that from the screen, an import or the API.</p>
</div>

<!-- 5. Inactive -->
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
            <tr><td><strong>Inactive</strong></td><td>It stays on everything already charged to it, but is not offered for anything new. You can make it active again at any time.</td><td>The account has been closed or replaced. This is the normal path.</td></tr>
            <tr><td><strong>Delete</strong></td><td>It is gone. An account with others below it cannot be deleted - the message says what is in the way.</td><td>It was created by mistake.</td></tr>
        </tbody>
    </table></div>
    <p>Untick <strong>Show inactive</strong> to hide inactive accounts, and use the <strong>Every kind</strong> list to show one kind only.</p>
</div>

<!-- 6. Import / export -->
<div class="help-section" id="import">
    <div class="help-section-header"><?php echo helpSectionNum('import'); ?>
        <div>
            <h3>Importing and exporting</h3>
            <p>Bring a whole chart in from a spreadsheet, or take it out.</p>
        </div>
    </div>
    <p><strong>Export</strong> downloads the company's chart as an <strong>Excel workbook (.xlsx)</strong> or a <strong>CSV</strong> file, with the columns <strong>Code</strong>, <strong>Name</strong>, <strong>Kind</strong>, <strong>Description</strong>, <strong>Parent code</strong> and <strong>Active</strong>. The easiest way to prepare an import is to export, edit that file and import it again.</p>
    <p><strong>Import</strong> reads an .xlsx or CSV file whose first row is headings:</p>
    <ul>
        <li>Only <strong>Code</strong> is required. A code that is new also needs a <strong>Name</strong> and a <strong>Kind</strong> - or a <strong>Parent code</strong>, whose kind it then takes.</li>
        <li><strong>Kind</strong> takes Expense, Fixed asset, Payroll or Income, and common alternatives such as Opex, Capex, Salaries or Revenue (and German: Aufwand, Anlage, Personal, Ertrag). A blank cell leaves an existing account's kind as it is.</li>
        <li>Headings are matched ignoring capitals and spaces, and common alternatives work too - <em>Account</em>, <em>GL code</em>, <em>Konto</em> for the code; <em>Type</em> or <em>Kontoart</em> for the kind. Other columns are ignored, so an export straight from your finance system usually works.</li>
        <li><strong>Active</strong> takes Yes or No (also True/False, 1/0, Ja/Nein). A blank cell leaves it as it is; a new account is active.</li>
        <li>A blank <strong>Parent code</strong> means top level. A parent can be another row in the same file.</li>
    </ul>
    <p>Accounts are matched on their code: codes already in the list are updated, new ones are added, and <strong>nothing is ever deleted</strong>. Switch on <strong>Make accounts that are not in the file inactive</strong> when the file is your complete chart.</p>
    <p><strong>Preview</strong> shows exactly what will happen, row by row, before anything changes. If any row has a problem, every problem is listed with its line number and <strong>nothing is imported</strong>. Fix the file and preview again.</p>
    <div class="help-note warn"><strong>Leading zeros and CSV files.</strong> If you open a CSV in Excel and save it again, Excel turns 0010 into 10 before FreeITSM sees the file. Edit your chart in the <strong>Excel export</strong>: its codes are stored as text, so the zeros stay.</div>
</div>

<!-- 7. API -->
<div class="help-section" id="api">
    <div class="help-section-header"><?php echo helpSectionNum('api'); ?>
        <div>
            <h3>Syncing from your finance system</h3>
            <p>Keep the chart in step automatically, as often as you like.</p>
        </div>
    </div>
    <p>Accounts can be read and changed through the REST API. Create an API key on <strong>System &rarr; API</strong> with the <strong>Chart of accounts</strong> permissions it needs. The endpoint built for a finance system is <code>POST /api/v1/accounts/sync</code>:</p>
    <ul>
        <li>Send the chart for one company, and it is matched on code exactly as an import is. Sending the same chart every night creates nothing twice.</li>
        <li>Only the fields you send are changed. <code>kind</code> is <code>expense</code>, <code>fixed_asset</code>, <code>payroll</code> or <code>income</code> (the words the import accepts work too).</li>
        <li><code>"deactivate_missing": true</code> makes anything no longer in the list inactive. Nothing is ever deleted.</li>
        <li><code>"dry_run": true</code> reports what would change and changes nothing.</li>
        <li>It is all or nothing. One bad item and nothing is written; the response lists every problem with its position in the list.</li>
        <li>The key needs both the <strong>create</strong> and <strong>update</strong> permissions, because a sync can do both.</li>
    </ul>
    <p>The full reference is on <strong>System &rarr; API &rarr; Documentation</strong> under <em>Chart of accounts</em>.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
