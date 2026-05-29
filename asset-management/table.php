<?php
/**
 * Asset Management — Full-screen table view
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css). Read-only: clicking a row deep-links to the
 * split-pane view for that asset. Adds PDF export on top of the shared CSV.
 * The asset-specific columns + loading live in assets/js/asset-table.js.
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'table';
$path_prefix = '../';
$dtShowPdf = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Asset table</title>
    <link rel="stylesheet" href="../assets/css/inbox.css?v=22">
    <link rel="stylesheet" href="../assets/css/data-table.css?v=1">
    <!-- jsPDF + autotable (same versions as morning-checks) for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="dt-page">
        <?php include '../includes/data-table-skeleton.php'; ?>
    </div>

    <script src="../assets/js/data-table.js?v=1"></script>
    <script src="../assets/js/asset-table.js?v=2"></script>
</body>
</html>
