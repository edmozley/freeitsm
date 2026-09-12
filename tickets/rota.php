<?php
/**
 * Staff Rota - Weekly shift schedule
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'rota';

// Namespaces the inline rota.js needs for translated strings.
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.rota.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=70">
    <link rel="stylesheet" href="../assets/css/rota.css?v=2">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
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
            <?php /* Copy / paste a whole week (Ed). Paste stays hidden until
                     something is on the clipboard, and then says WHICH week is
                     on it — by the time you have navigated three weeks away, a
                     bare "Paste week" is a guess about what is about to land on
                     top of what you are looking at. */ ?>
            <div class="rota-actions">
                <button class="btn btn-secondary" id="rotaCopyWeekBtn" onclick="copyRotaWeek()"><?php echo htmlspecialchars(t('tickets.rota.copy.week_btn')); ?></button>
                <button class="btn btn-primary" id="rotaPasteWeekBtn" onclick="pasteRotaWeek()" style="display:none;"></button>
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

    <?php /* Right-click a cell to copy or paste one shift (Ed). Uses the shared
             .ticket-context-menu component from inbox.css, which this page
             already loads and which the ticket, asset, change and service
             status lists all use — a fifth bespoke menu would look almost the
             same and behave slightly differently. */ ?>
    <div class="ticket-context-menu" id="rotaContextMenu">
        <div class="ticket-context-menu-header" id="rotaCtxHeader"><?php echo htmlspecialchars(t('tickets.rota.ctx.heading')); ?></div>
        <button class="ticket-context-menu-item" type="button" id="rotaCtxCopy" onclick="rotaCtxAction('copy')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            <span><?php echo htmlspecialchars(t('tickets.rota.ctx.copy_cell')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" id="rotaCtxPaste" onclick="rotaCtxAction('paste')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
            <span id="rotaCtxPasteLabel"><?php echo htmlspecialchars(t('tickets.rota.ctx.paste_cell')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" id="rotaCtxClear" onclick="rotaCtxAction('clear')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            <span><?php echo htmlspecialchars(t('tickets.rota.ctx.clear_cell')); ?></span>
        </button>
    </div>

    <script src="../assets/js/rota.js?v=4"></script>
</body>
</html>
