<?php
/**
 * Change Management — Full-screen table view
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css). Read-only: clicking a row deep-links to that
 * change record. (Inline editing is deliberately off — save.php rewrites the
 * whole record and would null out fields the table doesn't carry.) The
 * change-specific columns + loading live in assets/js/change-table.js.
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'table';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Change Table</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/change-management.css">
    <link rel="stylesheet" href="../assets/css/data-table.css?v=1">
    <style>
        /* Read-only note in the toolbar's right slot (replaces the row count). */
        .dt-readonly-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            color: #00897b;
            padding: 0;
        }
        .dt-readonly-note:hover { text-decoration: underline; }
        .dt-readonly-note svg { flex-shrink: 0; }

        /* Narrower modal for the plain-English explainer. */
        #readonlyModal .modal-content { max-width: 560px; }
        #readonlyModal .modal-body p { margin: 0 0 12px; line-height: 1.5; color: #333; }
        #readonlyModal .modal-body p:last-child { margin-bottom: 0; }
        #readonlyModal .modal-body ul { margin: 0 0 12px; padding-left: 20px; line-height: 1.5; color: #333; }
        #readonlyModal .modal-body li { margin-bottom: 8px; }
        #readonlyModal .modal-body strong { color: #111; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="dt-page">
        <?php include '../includes/data-table-skeleton.php'; ?>
    </div>

    <!-- Plain-English explainer for why the table is read-only -->
    <div class="modal" id="readonlyModal">
        <div class="modal-content">
            <div class="modal-header">Why is this table read-only?</div>
            <div class="modal-body">
                <p>This table is a fast, read-only overview of every change. To edit a
                   change &mdash; including its priority &mdash; <strong>click any row</strong>
                   to open it and use the full change form.</p>
                <p>You'd think changing something simple like priority right here in the
                   table would be easy &mdash; and honestly, it could be. The reason it
                   isn't (yet) comes down to how saving a change works behind the scenes:</p>
                <ul>
                    <li><strong>Saving a change rewrites the whole record at once.</strong>
                        The save was built for the full edit form, where every field is on
                        screen &mdash; title, description, test and rollback plans, risk
                        scoring, CAB settings and so on &mdash; and it logs an audit-trail
                        entry for each field that changed.</li>
                    <li><strong>The table only loads a lightweight summary</strong> of each
                        change, not the long fields like the description or rollback plan.
                        So if it saved a single cell, it would send back "blank" for
                        everything it doesn't know about &mdash; wiping those fields and
                        cluttering the audit trail.</li>
                    <li>Rather than risk silently erasing a change's documented plans, the
                        table stays read-only and sends you to the full form, where nothing
                        can be lost.</li>
                </ul>
                <p>Editing single cells here is on the cards for the future &mdash; it just
                   needs a dedicated "update one field" save that leaves the rest of the
                   record untouched. For now, click any row to open and edit the change.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="readonlyModalClose">Close</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/data-table.js?v=1"></script>
    <script src="../assets/js/change-table.js?v=2"></script>
</body>
</html>
