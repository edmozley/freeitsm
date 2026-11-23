<?php
/** Forms help — calculated totals, option prices and bundles (GH #160 Part D). */
$helpTopic = 'totals';
require __DIR__ . '/_top.php';
?>

<?php helpSection('what', 'What a calculated total is',
    'A question nobody types into: FreeITSM works it out from the other answers.'); ?>
    <p>Add a <strong>Calculated total</strong> from the Add menu. The person filling the form in sees it change as they answer: pick <em>Pro</em>, type <em>4</em>, and <em>Order total: 6,000.00 &euro;</em> appears. When they send it, the total is kept on the request like any other answer. The approver sees it, the ticket carries it, and it appears in the CSV and the PDF.</p>
    <div class="help-note">
        <strong>The server works it out, not the browser.</strong> What the page shows is a courtesy. When the form is sent, FreeITSM works the total out again from the answers and ignores whatever the browser sent, so nobody can post a total of 1 to go round an approval.
    </div>
    <p>Untick <strong>Show the total to the person filling in the form</strong> if you would rather customers did not see prices. It is still worked out and kept.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('options', 'A price on each option',
    'A dropdown or choose-one question can carry an amount on each option.'); ?>
    <p>Beside each option of a <strong>Dropdown</strong> or <strong>Choose one</strong> question there is a small <strong>Value</strong> box: <em>Standard laptop - 900</em>, <em>Pro laptop - 1500</em>. Leave it empty for an option that costs nothing. Renaming an option keeps its value.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('lines', 'What a total adds up',
    'A total is a list of lines, added together. Each line is one of four kinds.'); ?>
    <div class="help-table">
        <table>
            <thead><tr><th>Line</th><th>Example</th><th>Worked out as</th></tr></thead>
            <tbody>
                <tr><td>A fixed amount</td><td>A <em>New laptop</em> form at 900</td><td>900 &times; the answer to <em>Quantity</em> (or &times; 1)</td></tr>
                <tr><td>The value of the option chosen in&hellip;</td><td>A dropdown of models</td><td>The chosen option's value &times; <em>Quantity</em></td></tr>
                <tr><td>A table: amount &times; quantity</td><td>A basket, one row per item</td><td>Each row's amount column &times; its quantity column, added up down the rows</td></tr>
                <tr><td>A catalogue item at its own price</td><td>A workplace set</td><td>The item's price &times; a quantity (&times; a question, if you choose one)</td></tr>
            </tbody>
        </table>
    </div>
    <p>Each total has one <strong>currency</strong>, chosen from the same list as everywhere else. Amounts are never added across currencies or converted.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('bundles', 'Bundles of catalogue items',
    'One form for a whole set - a notebook, two monitors, a headset - where each is also a catalogue item on its own.'); ?>
    <p>Give each item its price on its own catalogue card (the item's <strong>Catalogue</strong> tab). Then on the bundle form, add a Calculated total with a line <strong>A catalogue item at its own price</strong> for each item and how many. The price lives in one place, on the item, and every bundle that contains it follows it. When an item is edited, the bundle follows its newest version.</p>
    <p>Add <em>&times; How many desks?</em> to each line, and one form orders a set for each new starter.</p>
<?php helpSectionEnd(); ?>

<?php helpSection('rules', 'How it is worked out',
    'The rules, so a total is never a surprise.'); ?>
    <ul>
        <li><strong>Hidden questions add nothing.</strong> A question hidden by a condition was not asked, so its answer is not counted.</li>
        <li><strong>A blank quantity counts as nothing.</strong> Mark the quantity question required if a line must always count.</li>
        <li><strong>An answer that cannot be read leaves the total blank</strong>, never a guess: a catalogue item with no price, or a price in a different currency.</li>
        <li><strong>The total is frozen on the request.</strong> Changing a price next month does not change last month's request.</li>
        <li>It is an ordinary answer: <code>{{submission.fields.Order total}}</code> in a workflow, the CSV, the PDF and conditions on approval steps all read it. The CSV has the plain number (<em>6000.00</em>); screens show it as money.</li>
    </ul>
<?php helpSectionEnd(); ?>

<?php helpSection('approval', 'Using a total in approval',
    'Send big orders to the board, and let small ones through.'); ?>
    <p>In the form's <strong>Approval settings</strong>, give a step <strong>Only when</strong>: <em>Order total</em> &middot; <em>is more than</em> &middot; <em>5000</em>. A request under that skips the step, and the record says why. A blank total never skips a step: if the total cannot be worked out, the step runs. See <a href="approvals.php#steps">More than one step</a>.</p>
<?php helpSectionEnd(); ?>

<?php require __DIR__ . '/_bottom.php'; ?>
