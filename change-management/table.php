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
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="dt-page">
        <?php include '../includes/data-table-skeleton.php'; ?>
    </div>

    <script src="../assets/js/data-table.js?v=1"></script>
    <script src="../assets/js/change-table.js?v=1"></script>
</body>
</html>
