<?php
/**
 * System → Authentication → one LDAP / Active Directory provider.
 *
 * A directory carries far too much configuration for a dialog: a connection, a
 * sign-in scope, group gating, an import scope, attribute mapping, safety
 * settings and a run history. It had all been bolted onto the modal on the list
 * page, where the import section ended up below the fold and people reasonably
 * concluded it was not there.
 *
 * OIDC providers keep the modal — an issuer, a client id and a secret genuinely
 * is a dialog's worth of information.
 *
 * Tabs use the shared renderSettingsTabBar() and the same .tabs/.tab markup as
 * every module settings screen, so this page behaves the way the rest of the
 * application has already taught people to expect.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/settings_manifest.php';   // renderSettingsTabBar()

$current_page = 'sso';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

if (empty($_SESSION['analyst_id'])) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'auth/login.php');
    exit;
}

$conn = connectToDatabase();
if (!analystIsAdmin($conn, (int)$_SESSION['analyst_id'])) {
    http_response_code(403);
    exit('Administrator access required.');
}

$providerId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'ldap'");
$stmt->execute([$providerId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
    header('Location: index.php');
    exit;
}

// Never send the stored bind password to the browser. A masked placeholder means
// "leave it alone", which is the same contract the modal already used.
$hasBindPassword = !empty($p['ldap_bind_password']);

$multiTenant = isMultiTenant($conn);
$tenants = $multiTenant
    ? $conn->query("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY is_default DESC, name")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$tabs = [
    ['id' => 'connection', 'cap' => null, 'label' => t('system.sso.tab_connection')],
    ['id' => 'signin',     'cap' => null, 'label' => t('system.sso.tab_signin')],
    ['id' => 'import',     'cap' => null, 'label' => t('system.sso.tab_import')],
    ['id' => 'mapping',    'cap' => null, 'label' => t('system.sso.tab_mapping')],
    ['id' => 'history',    'cap' => null, 'label' => t('system.sso.tab_history')],
];
$activeTab = in_array($_GET['tab'] ?? '', ['connection','signin','import','mapping','history'], true)
    ? $_GET['tab'] : 'connection';

/** Print a value into an input safely. */
function v($row, string $k): string { return htmlspecialchars((string)($row[$k] ?? '')); }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars($p['display_name']); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            margin: 0; background: var(--app-bg, #f5f5f5);
            /* A flex column, so the scrolling area is "whatever is left below the
               header" and nothing has to know how tall the header is.
               ⚠️ It was calc(100vh - 48px) first. The header is 58px, so the
               wrapper hung 10px past the bottom of the window and took the Save
               button with it. Measuring found that; the CSS read as correct. */
            display: flex; flex-direction: column; height: 100vh; overflow: hidden;
        }
        /* Full width, edge to edge. ⚠️ `max-width: none` alone is not enough
           elsewhere in this app — an inherited `margin: … auto` inside a flex
           parent cancels the stretch and re-centres the column. Belt (width) and
           braces (margin: 0) both stated so this cannot regress into a narrow
           centred page that looks exactly like the cap is still there. */
        .prov-wrap {
            width: 100%;
            max-width: none;
            margin: 0;
            box-sizing: border-box;
            /* No bottom padding: the sticky save bar is the last element and
               provides the end-of-page spacing itself. With padding here AND a
               negative margin on the bar to cancel it, the bar ended up sitting
               over the final field and clipping it. */
            padding: 24px 32px 0;
            /* ⚠️ THIS is what scrolls, not the document. Without it the content
               below the fold is simply unreachable — there is nothing to scroll.
               flex:1 + min-height:0 takes exactly the space the header leaves,
               with no hardcoded header height to get wrong. */
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
        }
        .prov-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 6px; }
        .prov-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0; }
        .prov-sub { font-size: 13px; color: var(--text-dim, #888); margin: 2px 0 18px; }
        .prov-card { background: var(--surface, #fff); border-radius: 8px; padding: 22px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); }
        .fld { margin-bottom: 18px; }
        .fld label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #333); margin-bottom: 3px; }
        .fld .hint { font-size: 12px; color: var(--text-dim, #888); margin-bottom: 6px; line-height: 1.5; }
        .fld input[type=text], .fld input[type=password], .fld input[type=number], .fld select {
            width: 100%; box-sizing: border-box; padding: 9px 11px; font-size: 13px;
            border: 1px solid var(--border, #ddd); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        .fld-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .fld-row > * { flex: 1; min-width: 160px; }
        .chk { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #333); font-weight: 600; }
        .chk input { width: auto; }
        .attr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; }
        .result { margin-top: 8px; font-size: 12px; padding: 9px 11px; border-radius: 6px; display: none; white-space: pre-wrap; line-height: 1.5; }
        .result.ok   { display: block; background: #e8f5e9; color: #2e7d32; }
        .result.err  { display: block; background: #ffebee; color: #c62828; }
        /* The safety brake is a REFUSAL, not a failure. Amber: nothing is broken,
           we declined to act on something that looked wrong. */
        .result.warn { display: block; background: #fff4ce; color: #6b5900; }
        [data-theme-mode="dark"] .result.ok   { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .result.err  { background: #3a1b1e; color: #fca5a5; }
        [data-theme-mode="dark"] .result.warn { background: #3a3218; color: #fde68a; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-test { background: var(--surface, #fff); color: var(--sys-accent, #546e7a); border: 1px solid var(--border, #cfd8dc); }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
        /* Sticky to the bottom of the SCROLLING wrapper, so Save is reachable
           without scrolling to the end of a long tab. The negative margins let
           its background span the wrapper's padding rather than leaving a
           transparent gutter each side that the content shows through. */
        .save-bar {
            position: sticky; bottom: 0; z-index: 5;
            margin: 20px -32px 0; padding: 14px 32px;
            background: var(--app-bg, #f5f5f5);
            border-top: 1px solid var(--border, #e0e0e0);
            display: flex; gap: 10px; align-items: center;
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        /* Using the width is not the same as stretching ONE text box across it.
           On a wide screen the field blocks flow into two columns; anything that
           genuinely wants the full run (the attribute grids, the test and run
           buttons) opts out with .wide.
           ⚠️ MUST come after `.tab-pane.active { display: block }` — same
           specificity, so the later rule wins. Placed above it first, and the
           measured display stayed `block` while the CSS read as though it were
           grid. */
        @media (min-width: 1250px) {
            .tab-pane.active { display: grid; grid-template-columns: 1fr 1fr; gap: 0 40px; align-items: start; }
            .tab-pane.active > .wide { grid-column: 1 / -1; }
        }
        /* The mapping table. Fixed layout so the three columns stay put as
           example values of wildly different lengths arrive — a distinguished
           name in the manager row is enormous and would otherwise shove the
           attribute column into a sliver. */
        table.map { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        table.map th, table.map td { text-align: left; padding: 8px 10px; vertical-align: top; border-bottom: 1px solid var(--border-soft, #f0f0f0); }
        table.map thead th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #888); border-bottom: 1px solid var(--border, #e0e0e0); }
        table.map tbody th { font-weight: 600; color: var(--text, #333); }
        table.map .map-hint { display: block; font-weight: 400; font-size: 11.5px; color: var(--text-dim, #888); margin-top: 2px; line-height: 1.45; }
        table.map input { width: 100%; box-sizing: border-box; padding: 7px 9px; font-size: 12.5px; font-family: ui-monospace, Consolas, monospace;
            border: 1px solid var(--border, #ddd); border-radius: 5px; background: var(--surface, #fff); color: var(--text, #333); }
        tr.map-group td { background: var(--app-bg, #f7f7f7); font-size: 11.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; color: var(--text-dim, #888); padding: 7px 10px; border-bottom: 1px solid var(--border, #e6e6e6); }
        /* The example column. Before a test it is a dash, not an empty cell —
           an empty cell reads as "this field imports nothing", which is exactly
           the thing the test exists to tell you. */
        td.map-sample { font-size: 12.5px; color: var(--text-muted, #555); word-break: break-word; }
        td.map-sample.filled { color: var(--text, #2e7d32); }
        td.map-sample.missing { color: #c62828; font-style: italic; }
        [data-theme-mode="dark"] td.map-sample.filled { color: #86efac; }
        [data-theme-mode="dark"] td.map-sample.missing { color: #fca5a5; }
        .avail { margin-top: 12px; font-size: 12px; color: var(--text-dim, #888); }
        .avail summary { cursor: pointer; font-weight: 600; color: var(--sys-accent, #546e7a); }
        .avail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 3px 18px; margin-top: 10px; }
        .avail-grid code { font-family: ui-monospace, Consolas, monospace; color: var(--text, #444); }
        .avail-grid span { color: var(--text-dim, #999); }
        table.runs { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.runs th { text-align: left; padding: 7px 9px; color: var(--text-dim, #888); font-weight: 600; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        table.runs td { padding: 7px 9px; border-bottom: 1px solid var(--border-soft, #f4f4f4); color: var(--text, #444); white-space: nowrap; }
        td.run-msg { white-space: normal; color: var(--text-muted, #666); font-size: 11.5px; padding-bottom: 12px; }
        .pill { display: inline-block; padding: 1px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; }
        .pill.ok { background: #e8f5e9; color: #2e7d32; }
        .pill.stopped { background: #fff4ce; color: #6b5900; }
        .pill.failed, .pill.running { background: #ffebee; color: #c62828; }
        /* The OU tree. Scrolls in its own box: a directory with 200 OUs must not
           make the settings page itself thousands of pixels tall. */
        .ou-tree { border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff);
            max-height: 420px; overflow: auto; padding: 6px 0; }
        .ou-row { display: flex; align-items: center; gap: 8px; padding: 4px 12px; font-size: 13px; color: var(--text, #333); }
        .ou-row:hover { background: var(--surface-hover, rgba(127,127,127,0.06)); }
        .ou-row input[type=checkbox] { flex: 0 0 auto; margin: 0; cursor: pointer; }
        /* The twisty is a button-shaped span, fixed width so names line up
           whether or not a node has children. */
        .ou-twisty { flex: 0 0 14px; width: 14px; text-align: center; cursor: pointer; color: var(--text-dim, #999); font-size: 10px; user-select: none; }
        .ou-twisty.leaf { cursor: default; opacity: 0; }
        .ou-name { flex: 1 1 auto; }
        /* Two numbers, because they answer different questions: how many sit
           here, and how many ticking this branch would bring in. */
        .ou-count { flex: 0 0 auto; font-size: 11.5px; color: var(--text-dim, #999); white-space: nowrap; }
        .ou-count b { color: var(--text-muted, #666); font-weight: 600; }
        .ou-row.excluded .ou-name { text-decoration: line-through; color: var(--text-dim, #999); }
        .ou-kids.collapsed { display: none; }
        .ou-manual { margin-top: 12px; }
        .ou-ignored { background: #fff4ce; color: #6b5900; padding: 7px 10px; border-radius: 5px; margin: 8px 0 0; font-weight: 600; }
        [data-theme-mode="dark"] .ou-ignored { background: #3a3218; color: #fde68a; }
        .ou-manual summary { cursor: pointer; font-size: 12.5px; color: var(--sys-accent, #546e7a); font-weight: 600; }
        /* The run detail modal. Namespaced `sso-` for the same reason index.php
           namespaces its own: inbox.css carries a global .modal framework that
           defaults to opacity:0/visibility:hidden, and an un-namespaced modal
           here would open invisibly. */
        .sso-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .sso-modal-overlay.open { display: flex; }
        /* Wider and taller than the config modal on the list page: this one holds
           a table of people, and a cramped list of names is the thing it exists
           to replace. Flex column so the table body scrolls and the header,
           filters and footer stay put. */
        /* height:auto with a cap, NOT a fixed 82vh: a preview with three rows in
           it should be three rows tall. A fixed height left most of the dialog
           as empty grey, which reads as "something failed to load". */
        .sso-modal { background: var(--surface, #fff); border-radius: 10px; width: 1100px; max-width: 94vw; height: auto; max-height: 86vh;
            display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .sso-modal-header { padding: 18px 24px 14px; border-bottom: 1px solid var(--border-soft, #eee); }
        .sso-modal-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .sso-modal-header .sub { font-size: 12.5px; color: var(--text-dim, #888); margin-top: 3px; }
        .sso-modal-body { padding: 0; flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .sso-modal-footer { padding: 14px 24px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .run-filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 12px 24px; border-bottom: 1px solid var(--border-soft, #f0f0f0); position: sticky; top: 0; background: var(--surface, #fff); z-index: 2; }
        /* A filter chip carries its own count, so "nothing to see here" is
           readable without clicking into each one in turn. */
        .chip { border: 1px solid var(--border, #ddd); background: var(--surface, #fff); color: var(--text-muted, #555);
            border-radius: 20px; padding: 5px 13px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .chip.on { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); border-color: var(--sys-accent, #546e7a); }
        .chip .n { opacity: .7; margin-left: 5px; font-weight: 400; }
        .run-search { margin-left: auto; flex: 0 1 240px; padding: 7px 11px; font-size: 12.5px; border: 1px solid var(--border, #ddd);
            border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        table.people { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        table.people th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #888);
            font-weight: 600; padding: 10px 24px; border-bottom: 1px solid var(--border, #e6e6e6); }
        table.people td { padding: 9px 24px; border-bottom: 1px solid var(--border-soft, #f4f4f4); color: var(--text, #444); vertical-align: top; word-break: break-word; }
        table.people tr.hide { display: none; }
        .who { font-weight: 600; }
        .who small { display: block; font-weight: 400; font-size: 11.5px; color: var(--text-dim, #999); font-family: ui-monospace, Consolas, monospace; }
        /* Colour carries the meaning at a glance: green arrives, amber changes,
           grey leaves, red needs you. */
        .act { display: inline-block; padding: 2px 10px; border-radius: 11px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .act.create { background: #e8f5e9; color: #2e7d32; }
        .act.update, .act.adopt { background: #fff4ce; color: #6b5900; }
        .act.deactivate, .act.skip, .act.unchanged { background: var(--app-bg, #eee); color: var(--text-dim, #777); }
        .act.conflict, .act.error { background: #ffebee; color: #c62828; }
        [data-theme-mode="dark"] .act.create { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .act.update, [data-theme-mode="dark"] .act.adopt { background: #3a3218; color: #fde68a; }
        [data-theme-mode="dark"] .act.conflict, [data-theme-mode="dark"] .act.error { background: #3a1b1e; color: #fca5a5; }
        .run-empty { padding: 40px 24px; text-align: center; color: var(--text-dim, #999); font-size: 13px; }
        /* A preview changed nothing. Say so where it cannot be missed, because
           the table below reads exactly like a record of things that happened. */
        .preview-banner { background: #fff4ce; color: #6b5900; padding: 10px 24px; font-size: 12.5px; font-weight: 600; }
        [data-theme-mode="dark"] .preview-banner { background: #3a3218; color: #fde68a; }
        table.runs tbody tr.clickable { cursor: pointer; }
        table.runs tbody tr.clickable:hover td { background: var(--surface-hover, rgba(127,127,127,0.07)); }
        .back-link { font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        @media (max-width: 700px) { .prov-wrap { padding: 14px 12px 50px; } }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="sso-provider">
<?php include '../includes/header.php'; ?>

<div class="prov-wrap">
    <a class="back-link" href="index.php">&larr; <?php echo htmlspecialchars(t('system.sso.back_to_list')); ?></a>
    <div class="prov-head">
        <div>
            <h1 class="prov-title"><?php echo htmlspecialchars($p['display_name']); ?></h1>
            <div class="prov-sub"><?php echo htmlspecialchars(t('system.sso.provider_page_sub')); ?></div>
        </div>
    </div>

    <?php renderSettingsTabBar($tabs, $activeTab, 'switchProvTab'); ?>

    <div class="prov-card">
        <!-- ================= Connection ================= -->
        <div class="tab-pane<?php echo $activeTab === 'connection' ? ' active' : ''; ?>" id="connection-pane">
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_display_name')); ?></label>
                <input type="text" id="fDisplayName" value="<?php echo v($p, 'display_name'); ?>">
            </div>
            <div class="fld fld-row">
                <div>
                    <label><?php echo htmlspecialchars(t('system.sso.field_ldap_host')); ?></label>
                    <input type="text" id="fHost" value="<?php echo v($p, 'ldap_host'); ?>">
                </div>
                <div style="max-width:130px;">
                    <label><?php echo htmlspecialchars(t('system.sso.field_ldap_port')); ?></label>
                    <input type="number" id="fPort" value="<?php echo v($p, 'ldap_port'); ?>">
                </div>
                <div style="max-width:170px;">
                    <label><?php echo htmlspecialchars(t('system.sso.field_ldap_encryption')); ?></label>
                    <select id="fEncryption">
                        <?php foreach (['none' => 'None', 'ldaps' => 'LDAPS', 'starttls' => 'STARTTLS'] as $k => $lbl): ?>
                        <option value="<?php echo $k; ?>"<?php echo ($p['ldap_encryption'] ?? 'none') === $k ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_dn')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_dn_hint')); ?></div>
                <input type="text" id="fBindDn" value="<?php echo v($p, 'ldap_bind_dn'); ?>">
            </div>
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_password')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_password_hint')); ?></div>
                <input type="password" id="fBindPassword" autocomplete="new-password"
                       placeholder="<?php echo $hasBindPassword ? '••••••••  (unchanged)' : ''; ?>">
            </div>
            <?php if ($multiTenant): ?>
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_tenant')); ?></label>
                <select id="fTenantId">
                    <option value=""><?php echo htmlspecialchars(t('system.sso.tenant_all')); ?></option>
                    <?php foreach ($tenants as $tn): ?>
                    <option value="<?php echo (int)$tn['id']; ?>"<?php echo (int)$p['tenant_id'] === (int)$tn['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($tn['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="fld">
                <label class="chk"><input type="checkbox" id="fEnabled"<?php echo (int)$p['enabled'] === 1 ? ' checked' : ''; ?>> <?php echo htmlspecialchars(t('system.sso.field_enabled')); ?></label>
            </div>
            <div class="fld wide">
                <label><?php echo htmlspecialchars(t('system.sso.ldap_test_heading')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.ldap_test_desc')); ?></div>
                <div class="fld-row">
                    <input type="text" id="fTestUser" placeholder="<?php echo htmlspecialchars(t('system.sso.ldap_test_user')); ?>">
                    <input type="password" id="fTestPass" placeholder="<?php echo htmlspecialchars(t('system.sso.ldap_test_pass')); ?>" autocomplete="new-password">
                    <button class="btn btn-test" id="testBtn" type="button" style="flex:0 0 auto;"><?php echo htmlspecialchars(t('system.sso.test')); ?></button>
                </div>
                <div class="result" id="testResult"></div>
            </div>
        </div>

        <!-- ================= Sign-in ================= -->
        <div class="tab-pane<?php echo $activeTab === 'signin' ? ' active' : ''; ?>" id="signin-pane">
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_ldap_base_dn')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_base_dn_hint')); ?></div>
                <input type="text" id="fBaseDn" value="<?php echo v($p, 'ldap_base_dn'); ?>">
            </div>
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_ldap_user_filter')); ?></label>
                <input type="text" id="fUserFilter" value="<?php echo v($p, 'ldap_user_filter'); ?>">
            </div>
            <!-- The attribute boxes used to sit here. Leaving a pointer rather
                 than nothing: somebody who knew where they were will otherwise
                 conclude they have been removed. -->
            <div class="fld">
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.attrs_moved')); ?>
                    <a href="#" onclick="switchProvTab('mapping');return false;"><?php echo htmlspecialchars(t('system.sso.tab_mapping')); ?></a>.</div>
            </div>
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.field_ldap_groups')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_groups_hint')); ?></div>
                <div class="fld-row">
                    <input type="text" id="fAnalystGroup" value="<?php echo v($p, 'ldap_analyst_group'); ?>" placeholder="<?php echo htmlspecialchars(t('system.sso.field_ldap_analyst_group')); ?>">
                    <input type="text" id="fUserGroup"    value="<?php echo v($p, 'ldap_user_group'); ?>"    placeholder="<?php echo htmlspecialchars(t('system.sso.field_ldap_user_group')); ?>">
                </div>
                <div style="margin-top:8px;">
                    <input type="text" id="fGroupFilter"  value="<?php echo v($p, 'ldap_group_filter'); ?>"  placeholder="(&(objectClass=group)(member=%s))">
                </div>
                <div style="margin-top:8px;">
                    <input type="text" id="fGroupBaseDn"  value="<?php echo v($p, 'ldap_group_base_dn'); ?>" placeholder="<?php echo htmlspecialchars(t('system.sso.field_ldap_group_base_dn_placeholder')); ?>">
                </div>
            </div>
            <div class="fld">
                <label class="chk"><input type="checkbox" id="fAutoCreate"<?php echo (int)$p['auto_create_users'] === 1 ? ' checked' : ''; ?>> <?php echo htmlspecialchars(t('system.sso.field_auto_create')); ?></label>
            </div>
        </div>

        <!-- ================= Importing people ================= -->
        <div class="tab-pane<?php echo $activeTab === 'import' ? ' active' : ''; ?>" id="import-pane">
            <div class="fld">
                <div class="hint" style="margin-bottom:10px;"><?php echo htmlspecialchars(t('system.sso.sync_desc')); ?></div>
                <label class="chk"><input type="checkbox" id="fSyncEnabled"<?php echo (int)$p['sync_enabled'] === 1 ? ' checked' : ''; ?>> <?php echo htmlspecialchars(t('system.sso.sync_enabled')); ?></label>
            </div>
            <div id="syncFields">
                <!-- Which parts of the directory to import. A DN typed into a
                     box is a guess you find out about at run time; a tree with
                     head counts on it is a choice you can check before running
                     anything. The typed box survives underneath, because a
                     directory the bind account cannot enumerate still has to be
                     configurable. -->
                <div class="fld wide">
                    <label><?php echo htmlspecialchars(t('system.sso.sync_scope')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.ou_tree_hint')); ?></div>
                    <div class="btn-row" style="margin-bottom:10px;">
                        <button class="btn btn-test" id="browseBtn" type="button"><?php echo htmlspecialchars(t('system.sso.ou_browse')); ?></button>
                        <span class="hint" id="ouSummary" style="align-self:center;"></span>
                    </div>
                    <div class="ou-tree" id="ouTree" style="display:none;"></div>
                    <div class="result" id="ouResult"></div>
                    <input type="hidden" id="fOuIncludes" value="<?php echo v($p, 'sync_ou_includes'); ?>">
                    <input type="hidden" id="fOuExcludes" value="<?php echo v($p, 'sync_ou_excludes'); ?>">
                    <!-- Kept, and honest about when it applies: the engine falls
                         back to it only when nothing is ticked, so an install
                         that predates the browser goes on importing exactly who
                         it imported yesterday. -->
                    <details class="ou-manual"<?php echo trim((string)($p['sync_ou_includes'] ?? '')) === '' ? ' open' : ''; ?>>
                        <summary><?php echo htmlspecialchars(t('system.sso.ou_manual')); ?></summary>
                        <div class="hint ou-ignored" id="ouManualNote" style="display:none;"></div>
                        <div class="hint" style="margin:8px 0 6px;"><?php echo htmlspecialchars(t('system.sso.sync_base_dn_hint')); ?></div>
                        <input type="text" id="fSyncBaseDn" value="<?php echo v($p, 'sync_base_dn'); ?>" placeholder="<?php echo htmlspecialchars(t('system.sso.sync_base_dn_placeholder')); ?>">
                    </details>
                    <div class="hint" style="margin-top:14px;"><?php echo htmlspecialchars(t('system.sso.sync_filter_hint')); ?></div>
                    <input type="text" id="fSyncFilter" value="<?php echo v($p, 'sync_filter'); ?>" placeholder="(&(objectClass=user)(objectCategory=person))">
                </div>
                <div class="fld">
                    <label><?php echo htmlspecialchars(t('system.sso.sync_conflict')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.sync_conflict_hint')); ?></div>
                    <select id="fSyncOnConflict">
                        <option value="adopt"<?php echo ($p['sync_on_conflict'] ?? 'adopt') === 'adopt' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('system.sso.sync_conflict_adopt')); ?></option>
                        <option value="flag"<?php echo ($p['sync_on_conflict'] ?? '') === 'flag' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('system.sso.sync_conflict_flag')); ?></option>
                    </select>
                </div>
                <div class="fld">
                    <label><?php echo htmlspecialchars(t('system.sso.sync_safety')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.sync_deactivate_hint')); ?></div>
                    <input type="number" id="fSyncDeactivateAfter" min="0" max="50" style="max-width:130px;" value="<?php echo (int)$p['sync_deactivate_after']; ?>">
                    <div class="hint" style="margin-top:10px;"><?php echo htmlspecialchars(t('system.sso.sync_brake_hint')); ?></div>
                    <input type="number" id="fSyncBrakePercent" min="0" max="100" style="max-width:130px;" value="<?php echo (int)$p['sync_brake_percent']; ?>">
                </div>
                <div class="fld">
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.attrs_moved')); ?>
                        <a href="#" onclick="switchProvTab('mapping');return false;"><?php echo htmlspecialchars(t('system.sso.tab_mapping')); ?></a>.</div>
                </div>
                <div class="fld wide">
                    <label><?php echo htmlspecialchars(t('system.sso.sync_run_heading')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.sync_run_hint')); ?></div>
                    <div class="btn-row">
                        <button class="btn btn-test" id="previewBtn" type="button"><?php echo htmlspecialchars(t('system.sso.sync_preview')); ?></button>
                        <button class="btn btn-test" id="runBtn" type="button"><?php echo htmlspecialchars(t('system.sso.sync_run')); ?></button>
                    </div>
                    <div class="result" id="syncResult"></div>
                </div>
            </div>
        </div>

        <!-- ================= Field mapping =================
             Every attribute box on one screen, FreeITSM's field on the left and
             the directory's on the right, because that IS the sentence being
             written: "put THIS of theirs into THAT of ours". They were split
             across two tabs before — four on Signing in, seven on Importing
             people — which read as two unrelated settings rather than one map.

             The identity rows are separated out because they are the ones
             sign-in also depends on: changing `Unique id` after people have
             been imported re-identifies everybody, so it is worth knowing which
             four carry that weight. -->
        <div class="tab-pane<?php echo $activeTab === 'mapping' ? ' active' : ''; ?>" id="mapping-pane">
            <?php
            // key => [input id, column, placeholder, whether it is an identity field]
            $mapRows = [
                'name'        => ['fAttrName',       'ldap_attr_name',        'displayName',                true],
                'username'    => ['fAttrUsername',   'ldap_attr_username',    'sAMAccountName',             true],
                'email'       => ['fAttrEmail',      'ldap_attr_email',       'mail',                       true],
                'guid'        => ['fAttrGuid',       'ldap_attr_guid',        'objectGUID',                 true],
                'job_title'   => ['fAttrJobTitle',   'ldap_attr_job_title',   'title',                      false],
                'department'  => ['fAttrDepartment', 'ldap_attr_department',  'department',                 false],
                'office'      => ['fAttrOffice',     'ldap_attr_office',      'physicalDeliveryOfficeName', false],
                'phone'       => ['fAttrPhone',      'ldap_attr_phone',       'telephoneNumber',            false],
                'mobile'      => ['fAttrMobile',     'ldap_attr_mobile',      'mobile',                     false],
                'employee_id' => ['fAttrEmployeeId', 'ldap_attr_employee_id', 'employeeID',                 false],
                'manager'     => ['fAttrManager',    'ldap_attr_manager',     'manager',                    false],
            ];
            $renderMapRows = function (bool $identity) use ($mapRows, $p) {
                foreach ($mapRows as $key => [$inputId, $col, $ph, $isIdentity]) {
                    if ($isIdentity !== $identity) continue;
                    ?>
                    <tr data-field="<?php echo $key; ?>">
                        <th scope="row">
                            <?php echo htmlspecialchars(t('system.sso.map_field_' . $key)); ?>
                            <span class="map-hint"><?php echo htmlspecialchars(t('system.sso.map_hint_' . $key)); ?></span>
                        </th>
                        <td><input type="text" id="<?php echo $inputId; ?>" value="<?php echo v($p, $col); ?>" placeholder="<?php echo $ph; ?>"></td>
                        <td class="map-sample">&mdash;</td>
                    </tr>
                    <?php
                }
            };
            ?>
            <div class="fld wide">
                <div class="hint" style="margin-bottom:14px;"><?php echo htmlspecialchars(t('system.sso.map_desc')); ?></div>
                <table class="map">
                    <thead>
                        <tr>
                            <th style="width:32%;"><?php echo htmlspecialchars(t('system.sso.map_col_ours')); ?></th>
                            <th style="width:30%;"><?php echo htmlspecialchars(t('system.sso.map_col_theirs')); ?></th>
                            <th><?php echo htmlspecialchars(t('system.sso.map_col_example')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="map-group"><td colspan="3"><?php echo htmlspecialchars(t('system.sso.map_group_identity')); ?></td></tr>
                        <?php $renderMapRows(true); ?>
                        <tr class="map-group"><td colspan="3"><?php echo htmlspecialchars(t('system.sso.map_group_details')); ?></td></tr>
                        <?php $renderMapRows(false); ?>
                    </tbody>
                </table>
            </div>
            <div class="fld wide">
                <label><?php echo htmlspecialchars(t('system.sso.map_test_heading')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.map_test_hint')); ?></div>
                <div class="fld-row">
                    <input type="text" id="fMapSample" placeholder="<?php echo htmlspecialchars(t('system.sso.map_test_sample')); ?>">
                    <button class="btn btn-test" id="mapTestBtn" type="button" style="flex:0 0 auto;"><?php echo htmlspecialchars(t('system.sso.map_test')); ?></button>
                </div>
                <div class="result" id="mapResult"></div>
                <div id="mapAvailable"></div>
            </div>
        </div>

        <!-- ================= History ================= -->
        <!-- ⚠️ Both children are .wide. Without it the wide-screen two-column
             rule applies here too, and a nine-column table does not belong in
             half a screen: the heading wrapped to one word per line and the
             table ran off the right edge of the page. -->
        <div class="tab-pane<?php echo $activeTab === 'history' ? ' active' : ''; ?>" id="history-pane">
            <div class="fld wide">
                <label><?php echo htmlspecialchars(t('system.sso.history_heading')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.history_hint')); ?></div>
            </div>
            <!-- The table scrolls inside its own box rather than widening the
                 page — a horizontally scrolling body is the worse of the two. -->
            <div id="runsBox" class="wide" style="overflow-x:auto;"></div>
        </div>
    </div>

    <div class="save-bar">
        <button class="btn btn-primary" id="saveBtn" type="button"><?php echo htmlspecialchars(t('common.save')); ?></button>
        <span id="saveMsg" style="font-size:13px;color:var(--text-dim,#888);"></span>
    </div>
</div>

<!-- ================= What one run did, person by person =================
     The counts alone ("31 found, 4 added") are not something anybody can act
     on, and a preview that only produces a number is asking to be trusted
     rather than checked. The entries have been recorded since the engine was
     written; nothing ever displayed them. This is that display, opened
     automatically after a preview and from any row of the history. -->
<div class="sso-modal-overlay" id="runModal">
    <div class="sso-modal">
        <div class="sso-modal-header">
            <h2 id="runModalTitle"></h2>
            <div class="sub" id="runModalSub"></div>
        </div>
        <div class="preview-banner" id="runPreviewBanner" style="display:none;"><?php echo htmlspecialchars(t('system.sso.sync_preview_prefix')); ?></div>
        <div class="sso-modal-body">
            <div class="run-filters" id="runFilters"></div>
            <div id="runPeople"></div>
        </div>
        <div class="sso-modal-footer">
            <span class="hint" id="runFooterNote"></span>
            <button class="btn btn-primary" type="button" onclick="closeRunModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
        </div>
    </div>
</div>

<script>
const PROVIDER_ID = <?php echo (int)$p['id']; ?>;
const API = '../../api/system/';
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

/* Tabs. The URL carries the tab so a reload, a bookmark or the back button all
   land where you were — the modal could not do that at all. */
function switchProvTab(id) {
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === id));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === id + '-pane'));
    history.replaceState(null, '', '?id=' + PROVIDER_ID + '&tab=' + id);
    if (id === 'history') loadRuns();
}

function syncToggle() { $('syncFields').style.display = $('fSyncEnabled').checked ? '' : 'none'; }
$('fSyncEnabled').addEventListener('change', syncToggle);
syncToggle();

/** Everything on the page, as the save endpoint expects it. */
function payload() {
    return {
        id: PROVIDER_ID,
        protocol: 'ldap',
        display_name: $('fDisplayName').value.trim(),
        enabled: $('fEnabled').checked ? 1 : 0,
        auto_create_users: $('fAutoCreate').checked ? 1 : 0,
        tenant_id: $('fTenantId') ? ($('fTenantId').value || null) : null,
        ldap_host: $('fHost').value.trim(),
        ldap_port: parseInt($('fPort').value, 10) || 389,
        ldap_encryption: $('fEncryption').value,
        ldap_bind_dn: $('fBindDn').value.trim(),
        // Blank means "keep the stored one" — the same contract as the modal.
        ldap_bind_password: $('fBindPassword').value,
        ldap_base_dn: $('fBaseDn').value.trim(),
        ldap_user_filter: $('fUserFilter').value.trim(),
        ldap_attr_username: $('fAttrUsername').value.trim(),
        ldap_attr_email: $('fAttrEmail').value.trim(),
        ldap_attr_name: $('fAttrName').value.trim(),
        ldap_attr_guid: $('fAttrGuid').value.trim(),
        ldap_group_base_dn: $('fGroupBaseDn').value.trim(),
        ldap_group_filter: $('fGroupFilter').value.trim(),
        ldap_analyst_group: $('fAnalystGroup').value.trim(),
        ldap_user_group: $('fUserGroup').value.trim(),
        sync_enabled: $('fSyncEnabled').checked ? 1 : 0,
        sync_base_dn: $('fSyncBaseDn').value.trim(),
        // The ticked branches and the carve-outs. Sent as text, one DN per
        // line, which is exactly how they are stored — no shape to get wrong
        // between here and the engine.
        sync_ou_includes: $('fOuIncludes').value,
        sync_ou_excludes: $('fOuExcludes').value,
        sync_filter: $('fSyncFilter').value.trim(),
        sync_on_conflict: $('fSyncOnConflict').value,
        sync_deactivate_after: parseInt($('fSyncDeactivateAfter').value, 10) || 0,
        sync_brake_percent: parseInt($('fSyncBrakePercent').value, 10) || 0,
        ldap_attr_job_title: $('fAttrJobTitle').value.trim(),
        ldap_attr_department: $('fAttrDepartment').value.trim(),
        ldap_attr_office: $('fAttrOffice').value.trim(),
        ldap_attr_phone: $('fAttrPhone').value.trim(),
        ldap_attr_mobile: $('fAttrMobile').value.trim(),
        ldap_attr_employee_id: $('fAttrEmployeeId').value.trim(),
        ldap_attr_manager: $('fAttrManager').value.trim()
    };
}

$('saveBtn').addEventListener('click', async function () {
    this.disabled = true;
    $('saveMsg').textContent = '';
    try {
        const d = await (await fetch(API + 'save_sso_provider.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload())
        })).json();
        if (!d.success) { showToast(d.error || 'Save failed', 'error'); return; }
        // The bind password field is emptied after a save so it goes back to
        // meaning "unchanged" — leaving the typed value in it would send it again
        // on the next save, which is harmless but misleading.
        $('fBindPassword').value = '';
        showToast(window.t('common.saved') || 'Saved', 'success');
    } catch (e) {
        showToast(String(e.message || e), 'error');
    } finally { this.disabled = false; }
});

$('testBtn').addEventListener('click', async function () {
    const box = $('testResult');
    box.className = 'result'; box.textContent = window.t('system.sso.testing') || 'Testing…';
    box.classList.add('ok');
    try {
        const body = Object.assign(payload(), {
            test_user: $('fTestUser').value.trim(),
            test_pass: $('fTestPass').value
        });
        const d = await (await fetch(API + 'test_ldap_connection.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        })).json();
        box.className = 'result ' + (d.success ? 'ok' : 'err');
        box.textContent = d.message || d.error || (d.success ? 'OK' : 'Failed');
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    }
});

async function runSync(mode) {
    const box = $('syncResult');
    if (mode === 'live') {
        const ok = await showConfirm({
            title:   window.t('system.sso.sync_run'),
            message: window.t('system.sso.sync_confirm'),
            okLabel: window.t('system.sso.sync_run')
        });
        if (!ok) return;
    }
    box.className = 'result ok'; box.textContent = window.t('system.sso.sync_running');
    try {
        const d = await (await fetch(API + 'run_directory_sync.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ provider_id: PROVIDER_ID, mode: mode })
        })).json();
        if (!d.success) { box.className = 'result err'; box.textContent = d.error || 'Failed'; return; }
        const r = d.run || {};
        const line = [
            window.t('system.sso.sync_found',   { n: r.seen_count }),
            window.t('system.sso.sync_created', { n: r.created_count }),
            window.t('system.sso.sync_updated', { n: Number(r.updated_count) + Number(r.adopted_count) }),
            window.t('system.sso.sync_left',    { n: r.deactivated_count })
        ].join(' · ');
        // 'stopped' is the brake: amber, not red. Nothing broke.
        box.className = 'result ' + (r.status === 'ok' ? 'ok' : (r.status === 'stopped' ? 'warn' : 'err'));
        box.textContent = (mode === 'preview' ? window.t('system.sso.sync_preview_prefix') + '\n' : '')
                        + line + (r.message ? '\n\n' + r.message : '');
        loadRuns();
        // Open the detail straight away. A preview whose entire output is four
        // numbers asks to be trusted; this asks to be read. The summary line
        // above stays put, so closing the modal does not lose the result.
        if (r.id) openRunDetail(r.id, mode, r.started_datetime, r.status);
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    }
}
$('previewBtn').addEventListener('click', () => runSync('preview'));
$('runBtn').addEventListener('click',   () => runSync('live'));

/* Fill the example column from one real person.
   Sends the values ON THE FORM, so you can check a mapping before committing to
   it. Testing the saved values would only ever confirm the last thing saved. */
$('mapTestBtn').addEventListener('click', async function () {
    const box = $('mapResult');
    box.className = 'result ok';
    box.textContent = window.t('system.sso.map_testing');
    this.disabled = true;
    try {
        const body = Object.assign(payload(), { provider_id: PROVIDER_ID, sample: $('fMapSample').value.trim() });
        const d = await (await fetch(API + 'test_directory_mapping.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        })).json();
        if (!d.success) {
            box.className = 'result err';
            box.textContent = d.error || 'Failed';
            clearSamples();
            return;
        }
        (d.rows || []).forEach(r => {
            const cell = document.querySelector('tr[data-field="' + r.key + '"] .map-sample');
            if (!cell) return;
            if (!r.attribute) {
                // Not mapped at all: neither a success nor a problem.
                cell.className = 'map-sample';
                cell.textContent = window.t('system.sso.map_not_mapped');
            } else if (r.missing) {
                cell.className = 'map-sample missing';
                cell.textContent = window.t('system.sso.map_empty');
            } else {
                cell.className = 'map-sample filled';
                cell.textContent = r.value;
            }
        });
        const bad = (d.rows || []).filter(r => r.attribute && r.missing).length;
        if (d.skipped) {
            // Neither sign-in name nor unique id resolved: the importer would
            // pass this person over. Red, because it is not a gap, it is a miss.
            box.className = 'result err';
            box.textContent = window.t('system.sso.map_result_skipped', { name: d.sample });
        } else {
            box.className = 'result ' + (bad ? 'warn' : 'ok');
            box.textContent = bad
                ? window.t('system.sso.map_result_gaps', { name: d.sample, n: bad })
                : window.t('system.sso.map_result_ok',   { name: d.sample });
        }
        renderAvailable(d.available || []);
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    } finally { this.disabled = false; }
});

function clearSamples() {
    document.querySelectorAll('td.map-sample').forEach(c => { c.className = 'map-sample'; c.textContent = '—'; });
}

/* Everything the sample person actually carries. An empty example is ambiguous
   on its own — the attribute might be misspelt, or the directory might simply
   not hold that detail. This list is what tells the two apart. */
function renderAvailable(list) {
    const box = $('mapAvailable');
    if (!list.length) { box.innerHTML = ''; return; }
    box.innerHTML = '<details class="avail"><summary>'
        + esc(window.t('system.sso.map_available', { n: list.length }))
        + '</summary><div class="avail-grid">'
        + list.map(a => '<div><code>' + esc(a.name) + '</code> <span>' + esc(a.value) + '</span></div>').join('')
        + '</div></details>';
}

/* ---------------- The OU browser ----------------
   Two sets decide everything: branches ticked IN, and branches carved OUT of
   them. A node's state is decided by the NEAREST of its ancestors-or-self that
   appears in either set — so ticking a parent covers children that do not
   appear in either list, which is what makes an OU created next year import on
   its own.

   Storing the exceptions rather than the members is the whole point. A list of
   every OU would freeze the selection at today's shape of the directory, and
   nothing would tell you when it went stale. */
let ouNodes = [];              // flat, parents before children
let ouInc = new Set();
let ouExc = new Set();
let ouCollapsed = new Set();

const dnParent = dn => { const i = dn.search(/(?<!\\),/); return i === -1 ? '' : dn.slice(i + 1).trim(); };

/** Is this node in scope, and was it decided here or inherited? */
function ouState(dn) {
    let walk = dn;
    while (walk !== '') {
        if (ouExc.has(walk)) return { in: false, self: walk === dn };
        if (ouInc.has(walk)) return { in: true,  self: walk === dn };
        walk = dnParent(walk);
    }
    return { in: false, self: false };
}

/* A box is indeterminate when the branch is not uniformly in or out — which is
   exactly the case a carve-out creates, and the only visible sign that one
   exists further down a collapsed branch. */
function ouBranchState(dn) {
    const self = ouState(dn).in;
    let anyIn = self, anyOut = !self;
    for (const n of ouNodes) {
        if (n.dn !== dn && isUnder(n.dn, dn)) {
            if (ouState(n.dn).in) anyIn = true; else anyOut = true;
        }
    }
    return anyIn && anyOut ? 'partial' : (anyIn ? 'on' : 'off');
}

// ⚠️ The comma is not optional. Without it "ou=sales,dc=x" tests true against
// "ou=wholesalesales,dc=x" and a carve-out swallows an unrelated OU.
const isUnder = (dn, anc) => dn === anc || dn.endsWith(',' + anc);

function ouToggle(dn) {
    const on = ouBranchState(dn) === 'on';
    // Whatever happens, anything said about this branch's interior is now moot.
    [...ouInc].forEach(d => { if (d !== dn && isUnder(d, dn)) ouInc.delete(d); });
    [...ouExc].forEach(d => { if (isUnder(d, dn)) ouExc.delete(d); });

    if (on) {
        // Turning off: if an ancestor brings us in, the only way to opt out is
        // a carve-out. Otherwise simply stop being included.
        ouInc.delete(dn);
        if (ouState(dn).in) ouExc.add(dn);
    } else {
        ouInc.add(dn);
        // An include under an include is redundant; keep the stored list as
        // small as the selection actually is.
        [...ouInc].forEach(d => { if (d !== dn && isUnder(dn, d)) ouInc.delete(dn); });
    }
    renderOuTree();
    syncOuHidden();
}

function syncOuHidden() {
    $('fOuIncludes').value = [...ouInc].join('\n');
    $('fOuExcludes').value = [...ouExc].join('\n');
    // How many people the current ticks would import. The tree exists to make
    // this number visible BEFORE a run, so it must react to every click.
    let n = 0;
    for (const node of ouNodes) if (ouState(node.dn).in) n += node.people;
    $('ouSummary').textContent = ouInc.size
        ? window.t('system.sso.ou_selected', { n: n })
        : window.t('system.sso.ou_none_selected');
    ouManualNote();
}

/* The typed starting point still exists and is still saved, but the engine only
   consults it when nothing is ticked. A box showing OU=Staff while the tree
   says otherwise is a trap: it reads as the setting in force when it is not. */
function ouManualNote() {
    const note = $('ouManualNote');
    if (!note) return;
    const overridden = $('fOuIncludes').value.trim() !== '';
    note.textContent = overridden ? window.t('system.sso.ou_manual_ignored') : '';
    note.style.display = overridden ? '' : 'none';
}

function renderOuTree() {
    const box = $('ouTree');
    const kids = {};
    ouNodes.forEach(n => { (kids[n.parent] = kids[n.parent] || []).push(n); });

    const rowFor = (n, depth) => {
        const st = ouBranchState(n.dn);
        const carved = ouExc.has(n.dn);
        const children = kids[n.dn] || [];
        const collapsed = ouCollapsed.has(n.dn);
        return `<div class="ou-row${carved ? ' excluded' : ''}" style="padding-left:${12 + depth * 20}px">
            <span class="ou-twisty${children.length ? '' : ' leaf'}" data-twisty="${esc(n.dn)}">${children.length ? (collapsed ? '▶' : '▼') : '▶'}</span>
            <input type="checkbox" data-dn="${esc(n.dn)}" ${st === 'on' ? 'checked' : ''}>
            <span class="ou-name">${esc(n.name)}</span>
            <span class="ou-count">${n.total ? window.t('system.sso.ou_count', { here: n.people, total: n.total }) : window.t('system.sso.ou_count_empty')}</span>
        </div>`
        + (children.length ? `<div class="ou-kids${collapsed ? ' collapsed' : ''}">`
            + children.map(c => rowFor(c, depth + 1)).join('') + '</div>' : '');
    };

    const roots = ouNodes.filter(n => !ouNodes.some(o => o.dn === n.parent));
    box.innerHTML = roots.map(r => rowFor(r, 0)).join('');
    box.style.display = '';

    // Indeterminate is a property, not an attribute — it cannot be set in the
    // markup above and has to be applied after the nodes exist.
    box.querySelectorAll('input[type=checkbox]').forEach(cb => {
        cb.indeterminate = ouBranchState(cb.dataset.dn) === 'partial';
        cb.addEventListener('change', () => ouToggle(cb.dataset.dn));
    });
    box.querySelectorAll('.ou-twisty').forEach(t => t.addEventListener('click', () => {
        const dn = t.dataset.twisty;
        if (ouCollapsed.has(dn)) ouCollapsed.delete(dn); else ouCollapsed.add(dn);
        renderOuTree();
    }));
}

$('browseBtn').addEventListener('click', async function () {
    const box = $('ouResult');
    box.className = 'result ok';
    box.textContent = window.t('system.sso.ou_reading');
    this.disabled = true;
    try {
        const d = await (await fetch(API + 'browse_directory_ous.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign(payload(), { provider_id: PROVIDER_ID }))
        })).json();
        if (!d.success) { box.className = 'result err'; box.textContent = d.error || 'Failed'; return; }
        ouNodes = d.ous || [];
        if (!ouNodes.length) {
            box.className = 'result warn';
            box.textContent = window.t('system.sso.ou_none_found');
            return;
        }
        // Seed from what is stored, NOT from what the tree shows: the stored
        // selection is the truth, and includes may name an OU that has since
        // been renamed or deleted. Those simply do not draw, and the engine
        // finds nothing under them, which is the honest outcome.
        ouInc = new Set(d.includes || []);
        ouExc = new Set(d.excludes || []);
        renderOuTree();
        syncOuHidden();
        box.className = 'result ' + (d.capped ? 'warn' : 'ok');
        box.textContent = d.capped
            ? window.t('system.sso.ou_capped', { n: d.counted })
            : window.t('system.sso.ou_read', { n: ouNodes.length, people: d.counted });
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    } finally { this.disabled = false; }
});

/* ---------------- What one run did, person by person ---------------- */

/* Every action the engine records, in the order somebody cares about them:
   the things that need attention first, the 400 rows saying "nothing happened"
   last. `on` is whether the filter starts selected — 'unchanged' does not,
   because on a healthy run it is nearly everything and says nothing. */
const RUN_ACTIONS = [
    { key: 'error',      on: true  },
    { key: 'conflict',   on: true  },
    { key: 'create',     on: true  },
    { key: 'update',     on: true  },
    { key: 'adopt',      on: true  },
    { key: 'deactivate', on: true  },
    { key: 'skip',       on: true  },
    { key: 'unchanged',  on: false }
];
let runShown = new Set();

/* A preview describes the future and an import describes the past. Labelling a
   preview row "Added" would be a plain lie about something nobody has agreed
   to yet, so the tense follows the mode. */
function actionLabel(action, mode) {
    return window.t('system.sso.act_' + (mode === 'preview' ? 'will_' : 'did_') + action) || action;
}

async function openRunDetail(runId, mode, startedAt, status) {
    $('runModalTitle').textContent = window.t('system.sso.run_modal_' + (mode === 'preview' ? 'preview' : 'live'));
    $('runModalSub').textContent = startedAt
        ? fmtDateTime(startedAt)
        : '';
    $('runPreviewBanner').style.display = mode === 'preview' ? '' : 'none';
    $('runFilters').innerHTML = '';
    $('runPeople').innerHTML = '<div class="run-empty">' + esc(window.t('system.sso.run_loading')) + '</div>';
    $('runFooterNote').textContent = '';
    $('runModal').classList.add('open');

    try {
        const d = await (await fetch(API + 'get_directory_sync_log.php?run_id=' + runId + '&action=all')).json();
        if (!d.success) {
            $('runPeople').innerHTML = '<div class="run-empty">' + esc(d.error || 'Failed') + '</div>';
            return;
        }
        const counts = d.by_action || {};
        // A run the brake stopped has counts of zero and no entries at all —
        // which is correct and needs saying, not an empty table.
        if (!(d.entries || []).length) {
            $('runPeople').innerHTML = '<div class="run-empty">' + esc(window.t(
                status === 'stopped' ? 'system.sso.run_none_stopped' : 'system.sso.run_none')) + '</div>';
            return;
        }

        runShown = new Set(RUN_ACTIONS.filter(a => a.on && counts[a.key]).map(a => a.key));
        $('runFilters').innerHTML = RUN_ACTIONS.filter(a => counts[a.key]).map(a =>
            '<button type="button" class="chip' + (runShown.has(a.key) ? ' on' : '') + '" data-act="' + a.key + '">'
            + esc(actionLabel(a.key, mode)) + '<span class="n">' + counts[a.key] + '</span></button>'
        ).join('') + '<input type="text" class="run-search" id="runSearch" placeholder="'
            + esc(window.t('system.sso.run_search')) + '">';

        $('runPeople').innerHTML = '<table class="people"><thead><tr>'
            + '<th style="width:30%;">' + esc(window.t('system.sso.run_col_person')) + '</th>'
            + '<th style="width:20%;">' + esc(window.t('system.sso.run_col_what')) + '</th>'
            + '<th>' + esc(window.t('system.sso.run_col_detail')) + '</th>'
            + '</tr></thead><tbody>'
            + d.entries.map(e => `<tr data-act="${esc(e.action)}" data-name="${esc(((e.display_name || '') + ' ' + (e.directory_username || '')).toLowerCase())}">
                <td class="who">${esc(e.display_name || '—')}${e.directory_username ? '<small>' + esc(e.directory_username) + '</small>' : ''}</td>
                <td><span class="act ${esc(e.action)}">${esc(actionLabel(e.action, mode))}</span></td>
                <td>${esc(e.detail || '')}</td>
            </tr>`).join('') + '</tbody></table>';

        $('runFilters').querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
            const k = c.dataset.act;
            if (runShown.has(k)) runShown.delete(k); else runShown.add(k);
            c.classList.toggle('on');
            applyRunFilter();
        }));
        $('runSearch').addEventListener('input', applyRunFilter);
        applyRunFilter();

        // The API caps at 1000 rows. A bigger directory would otherwise show a
        // truncated list that looks complete — say so rather than mislead.
        if (d.entries.length >= 1000) {
            $('runFooterNote').textContent = window.t('system.sso.run_capped');
        }
    } catch (e) {
        $('runPeople').innerHTML = '<div class="run-empty">' + esc(String(e.message || e)) + '</div>';
    }
}

function applyRunFilter() {
    const q = ($('runSearch') ? $('runSearch').value : '').trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#runPeople tbody tr').forEach(tr => {
        const show = runShown.has(tr.dataset.act) && (!q || tr.dataset.name.includes(q));
        tr.classList.toggle('hide', !show);
        if (show) visible++;
    });
    const empty = $('runPeople').querySelector('.run-empty-filter');
    if (!visible && !empty) {
        $('runPeople').insertAdjacentHTML('beforeend',
            '<div class="run-empty run-empty-filter">' + esc(window.t('system.sso.run_none_shown')) + '</div>');
    } else if (visible && empty) {
        empty.remove();
    }
}

function closeRunModal() { $('runModal').classList.remove('open'); }
$('runModal').addEventListener('click', e => { if (e.target === $('runModal')) closeRunModal(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && $('runModal').classList.contains('open')) closeRunModal();
});

async function loadRuns() {
    const box = $('runsBox');
    box.innerHTML = '';
    try {
        const d = await (await fetch(API + 'get_directory_sync_log.php?provider_id=' + PROVIDER_ID)).json();
        if (!d.success || !(d.runs || []).length) {
            box.innerHTML = '<div class="hint">' + esc(window.t('system.sso.history_none')) + '</div>';
            return;
        }
        box.innerHTML = '<table class="runs"><thead><tr>'
            + ['when','mode','result','found','added','changed','left','issues','by']
                .map(h => '<th>' + esc(window.t('system.sso.hist_' + h) || h) + '</th>').join('')
            + '</tr></thead><tbody>' + d.runs.map(r => {
                const cls = r.status === 'ok' ? 'ok' : (r.status === 'stopped' ? 'stopped' : 'failed');
                // Every row opens the same modal the preview does. The history
                // has listed counts since it was built and never let you see
                // behind them, which is the half that answers "updated how?".
                return `<tr class="clickable" onclick="openRunDetail(${Number(r.id)}, '${esc(r.mode)}', '${esc(r.started_datetime)}', '${esc(r.status)}')">
                    <td>${esc(fmtDateTime(r.started_datetime))}</td>
                    <td>${esc(r.mode)}</td>
                    <td><span class="pill ${cls}">${esc(r.status)}</span></td>
                    <td>${r.seen_count}</td><td>${r.created_count}</td>
                    <td>${Number(r.updated_count) + Number(r.adopted_count)}</td>
                    <td>${r.deactivated_count}</td>
                    <td>${Number(r.conflict_count) + Number(r.error_count)}</td>
                    <td>${esc(r.triggered_by || '—')}</td>
                </tr>` + (r.message ? `<tr><td colspan="9" class="run-msg">${esc(r.message)}</td></tr>` : '');
            }).join('') + '</tbody></table>';
    } catch (e) {
        box.innerHTML = '<div class="hint">' + esc(String(e.message || e)) + '</div>';
    }
}

if (<?php echo json_encode($activeTab); ?> === 'history') loadRuns();
// The typed-DN warning must be right on arrival, not only after a click.
ouManualNote();
</script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
