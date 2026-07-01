<?php
/**
 * Staff Rota - Weekly shift schedule
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'rota';

// Namespaces the inline rota.js needs for translated strings.
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.rota.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=11">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/rota.css?v=2">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="rota-container">
        <div class="rota-header">
            <div class="rota-nav">
                <button class="btn btn-secondary" onclick="goToThisWeek()"><?php echo htmlspecialchars(t('common.calendar.today')); ?></button>
                <button class="btn btn-icon" onclick="changeWeek(-1)" title="<?php echo htmlspecialchars(t('common.calendar.previous')); ?>">&lsaquo;</button>
                <button class="btn btn-icon" onclick="changeWeek(1)" title="<?php echo htmlspecialchars(t('common.calendar.next')); ?>">&rsaquo;</button>
                <h2 class="rota-title" id="rotaTitle"></h2>
            </div>
        </div>

        <div class="rota-grid-wrapper">
            <div id="rotaGrid" class="rota-grid"></div>
        </div>
    </div>

    <!-- Rota Entry Modal -->
    <div class="modal" id="rotaEntryModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" id="rotaEntryModalTitle"><?php echo htmlspecialchars(t('tickets.rota.modal.add_title')); ?></div>
            <form id="rotaEntryForm">
                <input type="hidden" id="entryId">
                <input type="hidden" id="entryAnalystId">
                <input type="hidden" id="entryDate">

                <p style="margin-bottom: 15px; font-weight: 600;" id="entryContext"></p>

                <div class="form-group">
                    <label for="entryShift"><?php echo htmlspecialchars(t('tickets.rota.modal.shift_label')); ?></label>
                    <select id="entryShift" required>
                        <option value=""><?php echo htmlspecialchars(t('tickets.rota.modal.shift_placeholder')); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.rota.modal.location_label')); ?></label>
                    <div id="entryLocationOptions" style="display: flex; gap: 15px; margin-top: 5px; flex-wrap: wrap;"></div>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="entryOnCall"> <?php echo htmlspecialchars(t('tickets.rota.modal.on_call_checkbox')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" id="entryDeleteBtn" onclick="deleteRotaEntry()" style="display: none; margin-right: auto;"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="closeRotaEntryModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/rota.js?v=2"></script>
</body>
</html>
