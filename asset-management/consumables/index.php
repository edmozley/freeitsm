<?php
/**
 * Assets -> Consumables (5.8.0): the things a service desk counts rather than tracks
 * one by one - toner, paper, mice, RAM. The list, an item's panel, and receiving,
 * issuing, returning and adjusting stock. Stock takes are stocktake.php, the
 * differences report report.php.
 *
 * Everything is read and written through api/assets/consumables.php ->
 * includes/services/consumables.php, the only writer. Design: wiki Consumables-Design.
 *
 *   ?item=N        open that item (the low-stock bell links here)
 *   ?status=low    only items that are low or out (Watchtower's number)
 *   ?ticket=N      issuing against that ticket: each row offers Issue, filled in with the
 *                  ticket and its requester (the ticket's Link to... -> Consumables)
 *
 * @wiki Consumables-Developer-Guide
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/money.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/consumables.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

$conn = connectToDatabase();
$aid = (int)$_SESSION['analyst_id'];
$current_page = 'consumables';
$path_prefix = '../../';
$translationNamespaces = ['common', 'asset-management'];
$ready = ConsumablesService::ready($conn);

// Issuing against a ticket: resolved here, so the page knows the ticket and its requester.
$forTicket = null;
if ($ready && isset($_GET['ticket']) && ctype_digit((string)$_GET['ticket']) && analystCanAccessTicket($conn, $aid, (int)$_GET['ticket'])) {
    require_once '../../includes/people.php';
    $st = $conn->prepare("SELECT t.id, t.ticket_number, t.subject, t.user_id, u.display_name, u.preferred_name, u.email, u.username
                            FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $st->execute([(int)$_GET['ticket']]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $forTicket = ['id' => (int)$r['id'], 'number' => $r['ticket_number'], 'subject' => $r['subject'],
                      'person_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                      'person_name' => $r['user_id'] !== null ? peopleName($r + ['id' => $r['user_id']]) : null];
    }
}
// The reorder form (Assets -> Settings -> Consumables) and which of its fields get what.
$reorder = null;
if ($ready) {
    $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('consumables_reorder_form', 'consumables_reorder_map')");
    $st->execute();
    $set = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($set['consumables_reorder_form']) && ctype_digit((string)$set['consumables_reorder_form']) && moduleIsEnabled($conn, 'forms')) {
        $map = json_decode((string)($set['consumables_reorder_map'] ?? ''), true);
        $reorder = ['form_id' => (int)$set['consumables_reorder_form'], 'map' => is_array($map) ? $map : []];
    }
}
$mayWrite = analystCanWriteModule($conn, $aid, 'assets');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.consumables.title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=97">
    <link rel="stylesheet" href="../../assets/css/consumables.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <?php echo Money::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <script src="../../assets/js/money.js?v=1"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="cs-wrap">
<?php if (!$ready): ?>
        <div class="cs-empty"><?php echo htmlspecialchars(t('asset-management.consumables.needs_verify')); ?></div>
<?php else: ?>
        <div class="cs-head">
            <div>
                <h1><?php echo htmlspecialchars(t('asset-management.consumables.title')); ?></h1>
                <p class="cs-intro"><?php echo htmlspecialchars(t('asset-management.consumables.intro')); ?></p>
            </div>
            <div class="cs-head-actions">
                <a class="btn btn-secondary" href="deliveries.php"><?php echo htmlspecialchars(t('asset-management.consumables.deliveries')); ?></a>
                <a class="btn btn-secondary" href="stocktake.php"><?php echo htmlspecialchars(t('asset-management.consumables.stocktake')); ?></a>
                <a class="btn btn-secondary" href="report.php"><?php echo htmlspecialchars(t('asset-management.consumables.report')); ?></a>
                <?php if ($mayWrite): ?><button type="button" class="btn btn-primary" id="csNew"><?php echo htmlspecialchars(t('common.action_row.new')); ?></button><?php endif; ?>
            </div>
        </div>
        <?php if ($forTicket): ?>
        <div class="cs-ticket-banner" id="csTicketBanner">
            <?php echo htmlspecialchars(t('asset-management.consumables.for_ticket', ['ticket' => $forTicket['number'], 'subject' => $forTicket['subject']])); ?>
        </div>
        <?php endif; ?>
        <div class="cs-bar">
            <input type="search" id="csSearch" placeholder="<?php echo htmlspecialchars(t('asset-management.consumables.search_ph')); ?>" aria-label="<?php echo htmlspecialchars(t('asset-management.consumables.search_ph')); ?>">
            <select id="csType" aria-label="<?php echo htmlspecialchars(t('asset-management.consumables.type')); ?>"><option value=""><?php echo htmlspecialchars(t('asset-management.consumables.all_types')); ?></option></select>
            <select id="csStatus" aria-label="<?php echo htmlspecialchars(t('asset-management.consumables.status')); ?>">
                <option value=""><?php echo htmlspecialchars(t('asset-management.consumables.status_all')); ?></option>
                <option value="low"><?php echo htmlspecialchars(t('asset-management.consumables.status_low_or_out')); ?></option>
                <option value="out"><?php echo htmlspecialchars(t('asset-management.consumables.status_out')); ?></option>
            </select>
            <label class="cs-check"><input type="checkbox" id="csInactive"> <?php echo htmlspecialchars(t('asset-management.consumables.show_retired')); ?></label>
            <span class="cs-count" id="csCount"></span>
        </div>
        <div id="csList"></div>
<?php endif; ?>
    </div>

<?php if ($ready): ?>
    <!-- One item: its details, what can be done with it, and everything that ever happened to it. -->
    <aside class="cs-panel" id="csPanel" hidden aria-labelledby="csPanelTitle">
        <div class="cs-panel-head">
            <h2 id="csPanelTitle"></h2>
            <button type="button" class="cs-x" id="csPanelClose" aria-label="<?php echo htmlspecialchars(t('common.close')); ?>">&times;</button>
        </div>
        <div class="cs-panel-body" id="csPanelBody"></div>
    </aside>

    <div class="modal" id="csItemModal">
        <div class="modal-content cs-modal-wide">
            <div class="modal-header"><span id="csItemTitle"></span></div>
            <form id="csItemForm">
                <div class="modal-body">
                    <div class="cs-grid">
                        <div class="form-group cs-span2">
                            <label class="form-label" for="ciName"><?php echo htmlspecialchars(t('asset-management.consumables.name')); ?></label>
                            <input type="text" class="search-box" id="ciName" maxlength="200" required autocomplete="off" placeholder="<?php echo htmlspecialchars(t('asset-management.consumables.name_ph')); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciType"><?php echo htmlspecialchars(t('asset-management.consumables.type')); ?></label>
                            <select class="search-box" id="ciType"></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciPart"><?php echo htmlspecialchars(t('asset-management.consumables.part_number')); ?></label>
                            <input type="text" class="search-box" id="ciPart" maxlength="100" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciUnit"><?php echo htmlspecialchars(t('asset-management.consumables.unit')); ?></label>
                            <input type="text" class="search-box" id="ciUnit" maxlength="30" autocomplete="off" list="ciUnits" placeholder="<?php echo htmlspecialchars(t('asset-management.consumables.unit_ph')); ?>">
                            <datalist id="ciUnits"></datalist>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciSupplier"><?php echo htmlspecialchars(t('asset-management.consumables.supplier')); ?></label>
                            <select class="search-box" id="ciSupplier"></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciCost"><?php echo htmlspecialchars(t('asset-management.consumables.unit_cost')); ?></label>
                            <input type="number" class="search-box" id="ciCost" min="0" step="0.01">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.consumables.unit_cost_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciReorder"><?php echo htmlspecialchars(t('asset-management.consumables.reorder_point')); ?></label>
                            <input type="number" class="search-box" id="ciReorder" min="0" step="1">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.consumables.reorder_point_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ciMin"><?php echo htmlspecialchars(t('asset-management.consumables.min_level')); ?></label>
                            <input type="number" class="search-box" id="ciMin" min="0" step="1">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.consumables.min_level_hint')); ?></div>
                        </div>
                        <div class="form-group cs-span2">
                            <label class="form-label" for="ciNotes"><?php echo htmlspecialchars(t('asset-management.consumables.notes')); ?></label>
                            <textarea class="search-box" id="ciNotes" rows="2"></textarea>
                        </div>
                        <div class="cs-span2" id="ciFields"></div>
                        <label class="cs-check cs-span2" id="ciActiveRow"><input type="checkbox" id="ciActive" checked> <?php echo htmlspecialchars(t('asset-management.consumables.in_use')); ?></label>
                    </div>
                    <div class="cs-error" id="ciError" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="csMoveModal">
        <div class="modal-content">
            <div class="modal-header"><span id="csMoveTitle"></span></div>
            <form id="csMoveForm">
                <div class="modal-body">
                    <p class="cs-move-what" id="csMoveWhat"></p>
                    <div class="form-group">
                        <label class="form-label" for="cmQty" id="cmQtyLabel"><?php echo htmlspecialchars(t('asset-management.consumables.quantity')); ?></label>
                        <input type="number" class="search-box" id="cmQty" step="1" required>
                        <div class="form-hint" id="cmQtyHint"></div>
                    </div>
                    <div data-kinds="receive">
                        <div class="cs-grid">
                            <div class="form-group">
                                <label class="form-label" for="cmCost"><?php echo htmlspecialchars(t('asset-management.consumables.unit_cost')); ?></label>
                                <input type="number" class="search-box" id="cmCost" min="0" step="0.01">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="cmSupplier"><?php echo htmlspecialchars(t('asset-management.consumables.supplier')); ?></label>
                                <select class="search-box" id="cmSupplier"></select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="cmOrder"><?php echo htmlspecialchars(t('asset-management.consumables.order_ref')); ?></label>
                                <input type="text" class="search-box" id="cmOrder" maxlength="100" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('asset-management.consumables.optional')); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="cmInvoice"><?php echo htmlspecialchars(t('asset-management.consumables.invoice_ref')); ?></label>
                                <input type="text" class="search-box" id="cmInvoice" maxlength="100" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="cmInvoiceDate"><?php echo htmlspecialchars(t('asset-management.consumables.invoice_date')); ?></label>
                                <input type="date" class="search-box" id="cmInvoiceDate">
                            </div>
                        </div>
                    </div>
                    <div class="form-group cs-person" data-kinds="issue return">
                        <label class="form-label" for="cmPerson" id="cmPersonLabel"></label>
                        <input type="text" class="search-box" id="cmPerson" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('asset-management.consumables.person_ph')); ?>">
                        <div class="cs-person-results" id="cmPersonResults" hidden></div>
                    </div>
                    <div class="form-group" data-kinds="issue return">
                        <label class="form-label" for="cmTicket"><?php echo htmlspecialchars(t('asset-management.consumables.ticket')); ?></label>
                        <input type="text" class="search-box" id="cmTicket" readonly>
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.consumables.ticket_hint')); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cmReason" id="cmReasonLabel"></label>
                        <input type="text" class="search-box" id="cmReason" maxlength="500" autocomplete="off">
                    </div>
                    <div class="cs-error" id="cmError" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="cmSubmit"></button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="csUndoModal">
        <div class="modal-content">
            <div class="modal-header"><span><?php echo htmlspecialchars(t('asset-management.consumables.undo_title')); ?></span></div>
            <form id="csUndoForm">
                <div class="modal-body">
                    <p id="cuWhat"></p>
                    <div class="form-group">
                        <label class="form-label" for="cuReason"><?php echo htmlspecialchars(t('asset-management.consumables.undo_reason')); ?></label>
                        <input type="text" class="search-box" id="cuReason" maxlength="500" required autocomplete="off">
                    </div>
                    <div class="cs-error" id="cuError" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.consumables.undo')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
    window.CONSUMABLES = <?php echo json_encode([
        'api'      => '../../api/assets/consumables.php',
        'write'    => $mayWrite,
        'ticket'   => $forTicket,
        'reorder'  => $reorder,
        'openItem' => isset($_GET['item']) && ctype_digit((string)$_GET['item']) ? (int)$_GET['item'] : 0,
        'status'   => in_array($_GET['status'] ?? '', ['low', 'out'], true) ? $_GET['status'] : '',
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../../assets/js/consumables.js?v=3"></script>
<?php endif; ?>
</body>
</html>
