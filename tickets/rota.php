<?php
/**
 * Staff Rota - Weekly shift schedule
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'rota';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Rota</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/rota.css">
    <script src="../assets/js/toast.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="rota-container">
        <div class="rota-header">
            <button class="btn btn-secondary" onclick="changeWeek(-1)">&lt; Previous</button>
            <h2 id="rotaTitle"></h2>
            <button class="btn btn-secondary" onclick="changeWeek(1)">Next &gt;</button>
            <button class="btn btn-primary" onclick="goToThisWeek()" style="margin-left: 20px;">Today</button>
        </div>

        <div class="rota-grid-wrapper">
            <div id="rotaGrid" class="rota-grid"></div>
        </div>
    </div>

    <!-- Rota Entry Modal -->
    <div class="modal" id="rotaEntryModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" id="rotaEntryModalTitle">Add Rota Entry</div>
            <form id="rotaEntryForm">
                <input type="hidden" id="entryId">
                <input type="hidden" id="entryAnalystId">
                <input type="hidden" id="entryDate">

                <p style="margin-bottom: 15px; font-weight: 600;" id="entryContext"></p>

                <div class="form-group">
                    <label for="entryShift">Shift *</label>
                    <select id="entryShift" required>
                        <option value="">Select shift...</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Location</label>
                    <div id="entryLocationOptions" style="display: flex; gap: 15px; margin-top: 5px; flex-wrap: wrap;"></div>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="entryOnCall"> On call
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" id="entryDeleteBtn" onclick="deleteRotaEntry()" style="display: none; margin-right: auto;">Delete</button>
                    <button type="button" class="btn btn-secondary" onclick="closeRotaEntryModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/rota.js?v=1"></script>
</body>
</html>
