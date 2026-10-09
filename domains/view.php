<?php
/**
 * Domains — one domain (#154): everything known about it, what is wrong with
 * it and how to fix it, what it looks like to the outside world, and every
 * change ever made to it. Behaviour in assets/js/domains-view.js.
 *
 * ⚠️ Reads ONLY ?id= — the value entityLink('domain', …) produces, so the
 * notification bell, search, the recent trail and alert e-mails all land here.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/tenancy.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('domains');

$domainId = (int)($_GET['id'] ?? 0);
$conn = connectToDatabase();
$exists = false;
if ($domainId > 0) {
    $st = $conn->prepare("SELECT id FROM domains WHERE id = ?");
    $st->execute([$domainId]);
    $exists = (bool)$st->fetchColumn() && analystCanAccessDomain($conn, (int)$_SESSION['analyst_id'], $domainId);
}
if ($exists) {
    require_once '../includes/recent_trail.php';
    entityVisit('domain', $domainId, $conn);
}

$current_page = 'register';
$path_prefix = '../';
$translationNamespaces = ['common', 'domains'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('domains.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=25">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../assets/css/domains.css?v=7">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=186">
</head>
<body data-mobile-module="domains" data-mobile-page="domains-view">
    <?php include 'includes/header.php'; ?>

    <div class="dom-shell">
    <div class="dom-shell-pad">
        <div class="dom-back-row" style="margin-bottom:10px"><a href="./" id="domBack" class="dom-sub" style="text-decoration:none">← <?php echo htmlspecialchars(t('domains.page.back')); ?></a></div>

        <?php if (!$exists): ?>
            <div class="dom-card"><div class="dom-empty"><h3><?php echo htmlspecialchars(t('domains.page.not_found')); ?></h3></div></div>
        <?php else: ?>
        <div class="dom-card dom-hero" id="hero"><div class="dom-sub"><?php echo htmlspecialchars(t('common.loading')); ?></div></div>

        <div class="dom-actions">
            <button type="button" class="dom-btn" id="btnRefresh" title="<?php echo htmlspecialchars(t('domains.page.refresh_title')); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                <?php echo htmlspecialchars(t('domains.page.refresh')); ?>
            </button>
            <button type="button" class="dom-btn" id="btnCheck" title="<?php echo htmlspecialchars(t('domains.page.check_title')); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                <?php echo htmlspecialchars(t('domains.page.check')); ?>
            </button>
            <button type="button" class="dom-btn primary" id="btnEdit"><?php echo htmlspecialchars(t('common.edit')); ?></button>
            <span style="flex:1"></span>
            <button type="button" class="dom-btn danger" id="btnDelete"><?php echo htmlspecialchars(t('common.delete')); ?></button>
        </div>

        <div class="tabs" id="domTabs">
            <button type="button" class="tab active" data-tab="overview"><?php echo htmlspecialchars(t('domains.tab.overview')); ?></button>
            <button type="button" class="tab" data-tab="security"><?php echo htmlspecialchars(t('domains.tab.security')); ?> <span id="secBadge"></span></button>
            <button type="button" class="tab" data-tab="certs"><?php echo htmlspecialchars(t('domains.tab.certificates')); ?> <span id="certBadge"></span></button>
            <button type="button" class="tab" data-tab="lookalikes"><?php echo htmlspecialchars(t('domains.tab.lookalikes')); ?> <span id="laBadge"></span></button>
            <button type="button" class="tab" data-tab="history"><?php echo htmlspecialchars(t('domains.tab.history')); ?></button>
            <button type="button" class="tab" data-tab="documents"><?php echo htmlspecialchars(t('domains.tab.documents')); ?></button>
            <button type="button" class="tab" data-tab="connections"><?php echo htmlspecialchars(t('domains.tab.connections')); ?> <span id="connBadge"></span></button>
        </div>

        <div class="tab-content active" id="tab-overview" style="padding:0;background:none;box-shadow:none"><div class="dom-grid" id="overview"></div></div>
        <div class="tab-content" id="tab-security"><div id="security"></div></div>
        <div class="tab-content" id="tab-certs"><div id="certs"></div></div>
        <div class="tab-content" id="tab-lookalikes"><div id="lookalikes"></div></div>
        <div class="tab-content" id="tab-history"><div id="history" class="dom-timeline"></div></div>
        <div class="tab-content" id="tab-connections" style="padding:0;background:none;box-shadow:none"><div id="connections"><div class="dom-sub"><?php echo htmlspecialchars(t('common.loading')); ?></div></div></div>
        <div class="tab-content" id="tab-documents">
            <?php require_once '../includes/documents_panel.php'; renderDocumentsPanel('domain', $domainId, '../'); ?>
        </div>
        <?php endif; ?>
    </div>
    </div>

    <?php if ($exists): ?>
    <!-- Edit -->
    <div class="modal" id="mEdit">
        <div class="modal-content" style="max-width:860px">
            <div class="modal-header"><?php echo htmlspecialchars(t('domains.edit.title')); ?></div>
            <div class="modal-body">
                <div class="dom-form-grid" id="editForm">
                    <div class="dom-section-title"><?php echo htmlspecialchars(t('domains.edit.s_basics')); ?></div>
                    <div class="form-group"><label for="eName"><?php echo htmlspecialchars(t('domains.field.domain_name')); ?></label><input type="text" id="eName" data-f="domain_name"></div>
                    <div class="form-group"><label for="ePurpose"><?php echo htmlspecialchars(t('domains.field.purpose')); ?></label><select id="ePurpose" data-f="purpose"></select><div class="dom-hint" id="ePurposeHint"></div></div>
                    <div class="form-group"><label for="eStatus"><?php echo htmlspecialchars(t('domains.field.status')); ?></label><select id="eStatus" data-f="status_id"></select></div>
                    <div class="form-group"><label for="eOwner"><?php echo htmlspecialchars(t('domains.field.owner')); ?></label><select id="eOwner" data-f="owner_analyst_id"></select></div>
                    <div class="form-group full"><label for="eTags"><?php echo htmlspecialchars(t('domains.field.tags')); ?></label><input type="text" id="eTags" data-f="tags" placeholder="<?php echo htmlspecialchars(t('domains.field.tags_hint')); ?>"></div>

                    <div class="dom-section-title"><?php echo htmlspecialchars(t('domains.edit.s_registration')); ?></div>
                    <div class="form-group"><label for="eRegistrar"><?php echo htmlspecialchars(t('domains.field.registrar')); ?></label><select id="eRegistrar" data-f="registrar_supplier_id"></select><div class="dom-hint"><?php echo htmlspecialchars(t('domains.edit.registrar_hint')); ?></div></div>
                    <div class="form-group"><label for="eAccount"><?php echo htmlspecialchars(t('domains.field.registrar_account')); ?></label><select id="eAccount" data-f="registrar_account_id"></select></div>
                    <div class="form-group"><label for="eRegName"><?php echo htmlspecialchars(t('domains.field.registrar_name')); ?></label><input type="text" id="eRegName" data-f="registrar_name"></div>
                    <div class="form-group"><label for="eRenewal"><?php echo htmlspecialchars(t('domains.field.renewal_mode')); ?></label><select id="eRenewal" data-f="renewal_mode"></select></div>
                    <div class="form-group"><label for="eReg"><?php echo htmlspecialchars(t('domains.field.registration_date')); ?></label><input type="date" id="eReg" data-f="registration_date"></div>
                    <div class="form-group"><label for="eExp"><?php echo htmlspecialchars(t('domains.field.expiry_date')); ?></label><input type="date" id="eExp" data-f="expiry_date"></div>
                    <div class="form-group"><label for="eRenewed"><?php echo htmlspecialchars(t('domains.field.last_renewed_date')); ?></label><input type="date" id="eRenewed" data-f="last_renewed_date"></div>
                    <div class="form-group"><label for="eRegistrant"><?php echo htmlspecialchars(t('domains.field.registrant_name')); ?></label><input type="text" id="eRegistrant" data-f="registrant_name"></div>
                    <div class="form-group"><label for="eTLock"><?php echo htmlspecialchars(t('domains.field.transfer_lock')); ?></label><select id="eTLock" data-f="transfer_lock" data-tri="1"></select></div>
                    <div class="form-group"><label for="eTech"><?php echo htmlspecialchars(t('domains.field.tech_contact')); ?></label><select id="eTech"></select><div class="dom-hint"><?php echo htmlspecialchars(t('domains.field.tech_contact_hint')); ?></div></div>
                    <div class="form-group"><label for="eCustomer"><?php echo htmlspecialchars(t('domains.field.customer')); ?></label>
                        <div class="dom-person">
                            <input type="text" id="eCustomer" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('domains.field.customer_ph')); ?>">
                            <input type="hidden" id="eCustomerId">
                            <button type="button" class="dom-person-clear" id="eCustomerClear" title="<?php echo htmlspecialchars(t('domains.field.customer_clear')); ?>" aria-label="<?php echo htmlspecialchars(t('domains.field.customer_clear')); ?>" hidden>&times;</button>
                            <ul class="dom-person-results" id="eCustomerResults" role="listbox" hidden></ul>
                        </div>
                        <div class="dom-hint"><?php echo htmlspecialchars(t('domains.field.customer_hint')); ?></div>
                    </div>

                    <div class="dom-section-title"><?php echo htmlspecialchars(t('domains.edit.s_dns')); ?></div>
                    <div class="form-group"><label for="eNs"><?php echo htmlspecialchars(t('domains.field.nameservers')); ?></label><textarea id="eNs" data-f="nameservers" rows="3"></textarea></div>
                    <div class="form-group"><label for="eSsl"><?php echo htmlspecialchars(t('domains.field.ssl_hosts')); ?></label><textarea id="eSsl" data-f="ssl_hosts" rows="3"></textarea><div class="dom-hint"><?php echo htmlspecialchars(t('domains.field.ssl_hosts_hint')); ?></div></div>
                    <div class="form-group"><label for="eDns"><?php echo htmlspecialchars(t('domains.field.dns_provider')); ?></label><input type="text" id="eDns" data-f="dns_provider"></div>
                    <div class="form-group"><label for="eHost"><?php echo htmlspecialchars(t('domains.field.hosting_provider')); ?></label><input type="text" id="eHost" data-f="hosting_provider"></div>
                    <div class="form-group full"><label for="eDkim"><?php echo htmlspecialchars(t('domains.field.dkim_selectors')); ?></label><input type="text" id="eDkim" data-f="dkim_selectors"><div class="dom-hint"><?php echo htmlspecialchars(t('domains.field.dkim_selectors_hint')); ?></div></div>

                    <div class="dom-section-title"><?php echo htmlspecialchars(t('domains.edit.s_money')); ?></div>
                    <div class="form-group"><label for="eCost"><?php echo htmlspecialchars(t('domains.field.cost')); ?></label><input type="number" step="0.01" min="0" id="eCost" data-f="cost"></div>
                    <div class="form-group"><label for="eCur"><?php echo htmlspecialchars(t('domains.field.currency')); ?></label><input type="text" id="eCur" data-f="currency" maxlength="3" placeholder="GBP"></div>
                    <div class="form-group"><label for="eYears"><?php echo htmlspecialchars(t('domains.field.billing_years')); ?></label><input type="number" min="1" max="10" id="eYears" data-f="billing_years"></div>
                    <div class="form-group"><label for="eCc"><?php echo htmlspecialchars(t('domains.field.cost_centre')); ?></label><input type="text" id="eCc" data-f="cost_centre"></div>
                    <div class="form-group full"><label for="eContract"><?php echo htmlspecialchars(t('domains.field.contract')); ?></label><select id="eContract" data-f="contract_id"></select></div>

                    <div class="dom-section-title"><?php echo htmlspecialchars(t('domains.edit.s_other')); ?></div>
                    <div class="form-group full"><label for="eNotes"><?php echo htmlspecialchars(t('domains.field.notes')); ?></label><textarea id="eNotes" data-f="notes" rows="3"></textarea></div>
                    <div class="form-group full">
                        <label class="toggle-label"><span class="toggle-switch"><input type="checkbox" id="eMon" data-f="monitoring_enabled" data-bool="1"><span class="toggle-slider"></span></span>
                        <?php echo htmlspecialchars(t('domains.edit.monitoring')); ?></label>
                        <div class="dom-hint"><?php echo htmlspecialchars(t('domains.edit.monitoring_hint')); ?></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="mEdit"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="eSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Auth code -->
    <div class="modal" id="mCode">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header"><?php echo htmlspecialchars(t('domains.code.title')); ?></div>
            <div class="modal-body">
                <p class="dom-hint" style="font-size:13px"><?php echo htmlspecialchars(t('domains.code.intro')); ?></p>
                <div class="form-group"><label for="cCode"><?php echo htmlspecialchars(t('domains.field.auth_code')); ?></label><input type="text" id="cCode" autocomplete="off" spellcheck="false"></div>
                <div class="dom-hint"><?php echo htmlspecialchars(t('domains.code.blank_clears')); ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="mCode"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="cSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>
    <script>window.DOMAIN_ID = <?php echo (int)$domainId; ?>;
    // The customer links to their People page (#153), for analysts who can open People.
    window.DOM_PEOPLE = <?php echo json_encode(analystCanAccessModule($conn, (int)$_SESSION['analyst_id'], 'people') ? BASE_URL . 'people/' : null); ?>;
    // Supplier and contact pages in People (#162) also need Contracts, which owns those records.
    window.DOM_PEOPLE_SUPPLIERS = <?php echo json_encode(analystCanAccessModule($conn, (int)$_SESSION['analyst_id'], 'people') && analystCanAccessModule($conn, (int)$_SESSION['analyst_id'], 'contracts')); ?>;</script>
    <script src="../assets/js/domains.js?v=1"></script>
    <script src="../assets/js/domains-view.js?v=6"></script>
    <?php endif; ?>
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
