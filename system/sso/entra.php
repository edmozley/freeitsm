<?php
/**
 * System → Authentication → one Microsoft Entra ID provider → directory sync.
 *
 * The OIDC provider itself (issuer, client id, secret, who may sign in) is still
 * edited in the dialog on the list page. This page is the import half: which
 * app reads the directory, which people and which groups come in, the safety
 * settings, a preview, and the history — the same shape as provider.php
 * (LDAP) and carddav.php, because it is the same engine underneath
 * (includes/entra_sync.php on top of includes/directory_sync.php).
 *
 * It saves through save_entra_sync.php, which writes only the import columns,
 * so this page and the dialog cannot overwrite each other.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/settings_manifest.php';   // renderSettingsTabBar()
require_once '../../includes/entra_sync.php';

$current_page = 'sso';
require_once __DIR__ . '/../includes/page_gate.php';
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
$stmt = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'oidc'");
$stmt->execute([$providerId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p || !entraIsEntraIssuer((string)$p['issuer_url'])) {
    header('Location: index.php');
    exit;
}

// Before Database Verification the graph_* columns do not exist yet. The page
// still opens, says so, and reads as defaults rather than throwing notices.
$columnsReady = array_key_exists('graph_user_scope', $p);
$p += ['graph_tenant_id' => null, 'graph_client_id' => null, 'graph_client_secret' => null,
       'graph_user_scope' => 'all', 'graph_user_groups' => null, 'graph_include_guests' => 0,
       'graph_group_scope' => 'none', 'graph_groups' => null];

// The secret never goes to the browser; only whether one is stored.
$ownApp       = trim((string)$p['graph_client_id']) !== '';
$hasOwnSecret = (string)$p['graph_client_secret'] !== '';
$issuerTenant = entraTenantId(array_merge($p, ['graph_tenant_id' => '']));

$tabs = [
    ['id' => 'connection', 'cap' => null, 'label' => t('system.sso.tab_connection')],
    ['id' => 'import',     'cap' => null, 'label' => t('system.sso.tab_import')],
    ['id' => 'mapping',    'cap' => null, 'label' => t('system.sso.tab_mapping')],
    ['id' => 'groups',     'cap' => null, 'label' => t('system.sso.entra_tab_groups')],
    ['id' => 'history',    'cap' => null, 'label' => t('system.sso.tab_history')],
];
$activeTab = in_array($_GET['tab'] ?? '', ['connection', 'import', 'mapping', 'groups', 'history'], true) ? $_GET['tab'] : 'connection';

/**
 * Print a value into an input safely.
 *
 * @param mixed $row  the provider row being edited
 * @param string $k  the column to print
 */
function v($row, string $k): string { return htmlspecialchars((string)($row[$k] ?? '')); }
/** @param string $k  a system.sso.* key */
function et(string $k, array $params = []): string { return htmlspecialchars(t('system.sso.' . $k, $params)); }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.sso.title'), $p['display_name'])); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <style>
        /* The layout, fields, buttons, results, history table and run modal are
           provider.php's, so the three people-source pages look and behave as one. */
        body { margin: 0; background: var(--app-bg, #f5f5f5); display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        .prov-wrap { width: 100%; max-width: none; margin: 0; box-sizing: border-box; padding: 24px 32px 0; flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .prov-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0; }
        .prov-sub { font-size: 13px; color: var(--text-dim, #888); margin: 2px 0 18px; }
        .prov-card { background: var(--surface, #fff); border-radius: 8px; padding: 22px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); }
        .fld { margin-bottom: 18px; }
        .fld label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #333); margin-bottom: 3px; }
        .fld .hint, .hint { font-size: 12px; color: var(--text-dim, #888); margin-bottom: 6px; line-height: 1.5; }
        .fld input[type=text], .fld input[type=password], .fld input[type=number], .fld select {
            width: 100%; box-sizing: border-box; padding: 9px 11px; font-size: 13px;
            border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        .fld-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .fld-row > * { flex: 1; min-width: 160px; }
        .chk { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #333); font-weight: 600; }
        .chk input { width: auto; }
        .radio { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: var(--text, #333); margin: 6px 0; cursor: pointer; }
        .radio input { margin-top: 2px; }
        .result { margin-top: 8px; font-size: 12px; padding: 9px 11px; border-radius: 6px; display: none; white-space: pre-wrap; line-height: 1.5; }
        .result.ok   { display: block; background: #e8f5e9; color: #2e7d32; }
        .result.err  { display: block; background: #ffebee; color: #c62828; }
        .result.warn { display: block; background: #fff4ce; color: #6b5900; }
        [data-theme-mode="dark"] .result.ok   { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .result.err  { background: #3a1b1e; color: #fca5a5; }
        [data-theme-mode="dark"] .result.warn { background: #3a3218; color: #fde68a; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-test { background: var(--surface, #fff); color: var(--sys-accent, #546e7a); border: 1px solid var(--border, #cfd8dc); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .save-bar { position: sticky; bottom: 0; z-index: 5; margin: 20px -32px 0; padding: 14px 32px; background: var(--app-bg, #f5f5f5);
            border-top: 1px solid var(--border, #e0e0e0); display: flex; gap: 10px; align-items: center; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        @media (min-width: 1250px) {
            .tab-pane.active { display: grid; grid-template-columns: 1fr 1fr; gap: 0 40px; align-items: start; }
            .tab-pane.active > .wide { grid-column: 1 / -1; }
        }
        .notice { background: #fff4ce; color: #6b5900; padding: 10px 14px; border-radius: 6px; font-size: 12.5px; margin-bottom: 16px; line-height: 1.5; }
        [data-theme-mode="dark"] .notice { background: #3a3218; color: #fde68a; }
        .perm-box { border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 12px 14px; font-size: 12.5px; line-height: 1.6; color: var(--text-muted, #555); }
        .perm-box code, .sched code { font-family: ui-monospace, Consolas, monospace; background: var(--app-bg, #f3f3f3); padding: 1px 5px; border-radius: 4px; color: var(--text, #333); }
        /* The group picker: what is chosen, then a search to choose more. */
        .picker { border: 1px solid var(--border, #ddd); border-radius: 6px; padding: 10px; background: var(--surface, #fff); margin-top: 8px; }
        .chosen { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .gchip { display: inline-flex; align-items: center; gap: 6px; background: var(--app-bg, #eef1f3); border-radius: 14px; padding: 4px 6px 4px 12px; font-size: 12px; color: var(--text, #333); }
        .gchip.missing { background: #ffebee; color: #c62828; }
        .gchip button { border: none; background: none; cursor: pointer; color: inherit; font-size: 14px; line-height: 1; padding: 0 4px; }
        .picker-results { max-height: 260px; overflow-y: auto; margin-top: 8px; }
        .picker-row { display: flex; align-items: center; gap: 10px; padding: 6px 4px; border-bottom: 1px solid var(--border-soft, #f2f2f2); font-size: 12.5px; }
        .picker-row .nm { flex: 1 1 auto; color: var(--text, #333); }
        .picker-row .nm small { display: block; color: var(--text-dim, #999); }
        .kind { font-size: 11px; color: var(--text-dim, #888); white-space: nowrap; }
        table.runs, table.people { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.runs th, table.people th { text-align: left; padding: 7px 9px; color: var(--text-dim, #888); font-weight: 600; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        table.runs td, table.people td { padding: 7px 9px; border-bottom: 1px solid var(--border-soft, #f4f4f4); color: var(--text, #444); vertical-align: top; }
        table.people td { word-break: break-word; }
        table.people tr.hide { display: none; }
        td.run-msg { white-space: normal; color: var(--text-muted, #666); font-size: 11.5px; padding-bottom: 12px; }
        table.runs tbody tr.clickable { cursor: pointer; }
        table.runs tbody tr.clickable:hover td { background: var(--surface-hover, rgba(127,127,127,0.07)); }
        .pill { display: inline-block; padding: 1px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; }
        .pill.ok { background: #e8f5e9; color: #2e7d32; }
        .pill.stopped { background: #fff4ce; color: #6b5900; }
        .pill.failed, .pill.running, .pill.off { background: #ffebee; color: #c62828; }
        .pill.off { background: var(--app-bg, #eee); color: var(--text-dim, #777); }
        tr.members td { background: var(--app-bg, #f8f8f8); }
        .sso-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .sso-modal-overlay.open { display: flex; }
        .sso-modal { background: var(--surface, #fff); border-radius: 10px; width: 1100px; max-width: 94vw; max-height: 86vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .sso-modal-header { padding: 18px 24px 14px; border-bottom: 1px solid var(--border-soft, #eee); }
        .sso-modal-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .sso-modal-header .sub { font-size: 12.5px; color: var(--text-dim, #888); margin-top: 3px; }
        .sso-modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .sso-modal-footer { padding: 14px 24px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .run-filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 12px 24px; border-bottom: 1px solid var(--border-soft, #f0f0f0); position: sticky; top: 0; background: var(--surface, #fff); z-index: 2; }
        .chip { border: 1px solid var(--border, #ddd); background: var(--surface, #fff); color: var(--text-muted, #555); border-radius: 20px; padding: 5px 13px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .chip.on { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); border-color: var(--sys-accent, #546e7a); }
        .chip .n { opacity: .7; margin-left: 5px; font-weight: 400; }
        .run-search { margin-left: auto; flex: 0 1 240px; padding: 7px 11px; font-size: 12.5px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        .who { font-weight: 600; }
        .who small { display: block; font-weight: 400; font-size: 11.5px; color: var(--text-dim, #999); font-family: ui-monospace, Consolas, monospace; }
        .act { display: inline-block; padding: 2px 10px; border-radius: 11px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .act.create { background: #e8f5e9; color: #2e7d32; }
        .act.update, .act.adopt { background: #fff4ce; color: #6b5900; }
        .act.deactivate, .act.skip, .act.unchanged { background: var(--app-bg, #eee); color: var(--text-dim, #777); }
        .act.conflict, .act.error { background: #ffebee; color: #c62828; }
        .run-empty { padding: 40px 24px; text-align: center; color: var(--text-dim, #999); font-size: 13px; }
        .preview-banner { background: #fff4ce; color: #6b5900; padding: 10px 24px; font-size: 12.5px; font-weight: 600; }
        [data-theme-mode="dark"] .preview-banner { background: #3a3218; color: #fde68a; }
        /* The field mapping table - the same look as the LDAP provider's (provider.php). */
        table.map { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        table.map th, table.map td { text-align: left; padding: 8px 10px; vertical-align: top; border-bottom: 1px solid var(--border-soft, #f0f0f0); }
        table.map thead th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #888); border-bottom: 1px solid var(--border, #e0e0e0); }
        table.map tbody th { font-weight: 600; color: var(--text, #333); }
        table.map .map-hint { display: block; font-weight: 400; font-size: 11.5px; color: var(--text-dim, #888); margin-top: 2px; line-height: 1.45; }
        table.map input { width: 100%; box-sizing: border-box; padding: 7px 9px; font-size: 12.5px; font-family: ui-monospace, Consolas, monospace;
            border: 1px solid var(--border, #ddd); border-radius: 5px; background: var(--surface, #fff); color: var(--text, #333); }
        table.map .map-fixed { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; color: var(--text-dim, #888); padding: 7px 0; display: block; }
        tr.map-group td { background: var(--app-bg, #f7f7f7); font-size: 11.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; color: var(--text-dim, #888); padding: 7px 10px; border-bottom: 1px solid var(--border, #e6e6e6); }
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
        .back-link { font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        @media (max-width: 700px) { .prov-wrap { padding: 14px 12px 50px; } }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="sso-provider">
<?php include '../includes/header.php'; ?>

<div class="prov-wrap">
    <a class="back-link" href="index.php">&larr; <?php echo et('back_to_list'); ?></a>
    <h1 class="prov-title"><?php echo htmlspecialchars($p['display_name']); ?></h1>
    <div class="prov-sub"><?php echo et('entra_page_sub'); ?></div>

    <?php if (!$columnsReady): ?>
        <div class="notice"><?php echo et('entra_not_ready'); ?></div>
    <?php endif; ?>

    <?php renderSettingsTabBar($tabs, $activeTab, 'switchProvTab'); ?>

    <div class="prov-card">
        <!-- ================= Connection ================= -->
        <div class="tab-pane<?php echo $activeTab === 'connection' ? ' active' : ''; ?>" id="connection-pane">
            <div class="fld wide">
                <label><?php echo et('entra_perm_heading'); ?></label>
                <div class="perm-box"><?php echo t('system.sso.entra_perm_desc', [
                    'perms' => '<code>User.Read.All</code>, <code>GroupMember.Read.All</code>',
                ]); ?></div>
            </div>
            <div class="fld">
                <label><?php echo et('entra_tenant'); ?></label>
                <div class="hint"><?php echo $issuerTenant !== ''
                    ? et('entra_tenant_hint_detected', ['tenant' => $issuerTenant])
                    : et('entra_tenant_hint_required'); ?></div>
                <input type="text" id="fTenant" value="<?php echo v($p, 'graph_tenant_id'); ?>" placeholder="<?php echo htmlspecialchars($issuerTenant ?: '00000000-0000-0000-0000-000000000000'); ?>">
            </div>
            <div class="fld">
                <label><?php echo et('entra_app_heading'); ?></label>
                <div class="hint"><?php echo et('entra_app_hint'); ?></div>
                <label class="radio"><input type="radio" name="appMode" value="same"<?php echo $ownApp ? '' : ' checked'; ?>>
                    <span><?php echo et('entra_app_same', ['client' => (string)$p['client_id']]); ?></span></label>
                <label class="radio"><input type="radio" name="appMode" value="own"<?php echo $ownApp ? ' checked' : ''; ?>>
                    <span><?php echo et('entra_app_own'); ?></span></label>
                <div id="ownAppFields" style="margin-top:10px;">
                    <div class="fld">
                        <label><?php echo et('entra_client_id'); ?></label>
                        <input type="text" id="fClientId" value="<?php echo v($p, 'graph_client_id'); ?>" placeholder="00000000-0000-0000-0000-000000000000">
                    </div>
                    <div class="fld">
                        <label><?php echo et('entra_client_secret'); ?></label>
                        <input type="password" id="fClientSecret" autocomplete="new-password"
                               placeholder="<?php echo $hasOwnSecret ? et('entra_secret_keep') : ''; ?>">
                    </div>
                </div>
            </div>
            <div class="fld wide">
                <div class="btn-row">
                    <button class="btn btn-test" id="testBtn" type="button"><?php echo et('entra_test'); ?></button>
                </div>
                <div class="result" id="testResult"></div>
            </div>
        </div>

        <!-- ================= Importing ================= -->
        <div class="tab-pane<?php echo $activeTab === 'import' ? ' active' : ''; ?>" id="import-pane">
            <div class="fld wide">
                <div class="hint" style="margin-bottom:10px;"><?php echo et('entra_sync_desc'); ?></div>
                <label class="chk"><input type="checkbox" id="fSyncEnabled"<?php echo (int)$p['sync_enabled'] === 1 ? ' checked' : ''; ?>> <?php echo et('sync_enabled'); ?></label>
            </div>
            <div class="fld">
                <label><?php echo et('entra_user_scope'); ?></label>
                <div class="hint"><?php echo et('entra_user_scope_hint'); ?></div>
                <label class="radio"><input type="radio" name="userScope" value="all"<?php echo $p['graph_user_scope'] !== 'groups' ? ' checked' : ''; ?>> <span><?php echo et('entra_user_scope_all'); ?></span></label>
                <label class="radio"><input type="radio" name="userScope" value="groups"<?php echo $p['graph_user_scope'] === 'groups' ? ' checked' : ''; ?>> <span><?php echo et('entra_user_scope_groups'); ?></span></label>
                <div class="picker" id="userGroupsPicker" data-ids="<?php echo v($p, 'graph_user_groups'); ?>"></div>
                <label class="chk" style="margin-top:12px;"><input type="checkbox" id="fGuests"<?php echo (int)$p['graph_include_guests'] === 1 ? ' checked' : ''; ?>> <?php echo et('entra_guests'); ?></label>
            </div>
            <div class="fld">
                <label><?php echo et('entra_group_scope'); ?></label>
                <div class="hint"><?php echo et('entra_group_scope_hint'); ?></div>
                <?php foreach (['none', 'selected', 'all'] as $gs): ?>
                    <label class="radio"><input type="radio" name="groupScope" value="<?php echo $gs; ?>"<?php echo $p['graph_group_scope'] === $gs ? ' checked' : ''; ?>> <span><?php echo et('entra_group_scope_' . $gs); ?></span></label>
                <?php endforeach; ?>
                <div class="picker" id="groupsPicker" data-ids="<?php echo v($p, 'graph_groups'); ?>"></div>
            </div>
            <div class="fld">
                <label><?php echo et('sync_conflict'); ?></label>
                <div class="hint"><?php echo et('sync_conflict_hint'); ?></div>
                <select id="fSyncOnConflict">
                    <option value="adopt"<?php echo ($p['sync_on_conflict'] ?? 'adopt') === 'adopt' ? ' selected' : ''; ?>><?php echo et('sync_conflict_adopt'); ?></option>
                    <option value="flag"<?php echo ($p['sync_on_conflict'] ?? '') === 'flag' ? ' selected' : ''; ?>><?php echo et('sync_conflict_flag'); ?></option>
                </select>
            </div>
            <div class="fld">
                <label><?php echo et('sync_safety'); ?></label>
                <div class="hint"><?php echo et('sync_deactivate_hint'); ?></div>
                <input type="number" id="fSyncDeactivateAfter" min="0" max="50" style="max-width:130px;" value="<?php echo (int)$p['sync_deactivate_after']; ?>">
                <div class="hint" style="margin-top:10px;"><?php echo et('sync_brake_hint'); ?></div>
                <input type="number" id="fSyncBrakePercent" min="0" max="100" style="max-width:130px;" value="<?php echo (int)$p['sync_brake_percent']; ?>">
            </div>
            <div class="fld wide">
                <label><?php echo et('sync_run_heading'); ?></label>
                <div class="hint"><?php echo et('sync_run_hint'); ?></div>
                <div class="btn-row">
                    <button class="btn btn-test" id="previewBtn" type="button"><?php echo et('sync_preview'); ?></button>
                    <button class="btn btn-test" id="runBtn" type="button"><?php echo et('sync_run'); ?></button>
                </div>
                <div class="result" id="syncResult"></div>
                <div class="hint sched" style="margin-top:12px;"><?php echo t('system.sso.entra_schedule_hint', [
                    'cmd' => '<code>php scripts/directory_sync.php --all</code>',
                ]); ?></div>
            </div>
        </div>

        <!-- ================= Field mapping =================
             The LDAP provider's mapping tab (provider.php) for Graph: FreeITSM's
             field on the left, the Entra attribute it is read from on the right,
             a blank box meaning the default. The unique id and the manager are
             shown but fixed: the object id is the identity everything else
             hangs on, and the manager is a Graph relationship, not an attribute. -->
        <div class="tab-pane<?php echo $activeTab === 'mapping' ? ' active' : ''; ?>" id="mapping-pane">
            <?php
            // key => whether it is an identity field (signing in also depends on it)
            $mapRows = ['name' => true, 'username' => true, 'email' => true, 'job_title' => false, 'department' => false,
                        'office' => false, 'phone' => false, 'mobile' => false, 'employee_id' => false];
            $fixedRow = function (string $key, string $attr, string $hintKey) {
                ?>
                <tr data-field="<?php echo $key; ?>">
                    <th scope="row"><?php echo et('map_field_' . $key); ?><span class="map-hint"><?php echo et($hintKey); ?></span></th>
                    <td><span class="map-fixed"><?php echo htmlspecialchars($attr); ?></span></td>
                    <td class="map-sample">&mdash;</td>
                </tr>
                <?php
            };
            $renderMapRows = function (bool $identity) use ($mapRows, $p) {
                foreach ($mapRows as $key => $isIdentity) {
                    if ($isIdentity !== $identity) continue;
                    ?>
                    <tr data-field="<?php echo $key; ?>">
                        <th scope="row"><?php echo et('map_field_' . $key); ?><span class="map-hint"><?php echo et('map_hint_' . $key); ?></span></th>
                        <td><input type="text" id="fGraphAttr_<?php echo $key; ?>" class="graph-attr" data-field="<?php echo $key; ?>" list="entraAttrList"
                                   value="<?php echo v($p, 'graph_attr_' . $key); ?>" placeholder="<?php echo htmlspecialchars(ESYNC_ATTR_DEFAULTS[$key]); ?>"></td>
                        <td class="map-sample">&mdash;</td>
                    </tr>
                    <?php
                }
            };
            ?>
            <div class="fld wide">
                <div class="hint" style="margin-bottom:14px;"><?php echo et('entra_map_desc'); ?></div>
                <table class="map">
                    <thead>
                        <tr>
                            <th style="width:32%;"><?php echo et('map_col_ours'); ?></th>
                            <th style="width:30%;"><?php echo et('entra_map_col_theirs'); ?></th>
                            <th><?php echo et('map_col_example'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="map-group"><td colspan="3"><?php echo et('map_group_identity'); ?></td></tr>
                        <?php $renderMapRows(true); $fixedRow('guid', 'id', 'entra_map_hint_guid'); ?>
                        <tr class="map-group"><td colspan="3"><?php echo et('map_group_details'); ?></td></tr>
                        <?php $renderMapRows(false); $fixedRow('manager', 'manager', 'entra_map_hint_manager'); ?>
                    </tbody>
                </table>
                <datalist id="entraAttrList">
                    <?php foreach (['displayName', 'givenName', 'surname', 'userPrincipalName', 'mail', 'otherMails', 'mailNickname',
                                    'onPremisesSamAccountName', 'onPremisesUserPrincipalName', 'jobTitle', 'department', 'companyName',
                                    'employeeType', 'employeeId', 'employeeOrgData/division', 'employeeOrgData/costCenter',
                                    'officeLocation', 'city', 'state', 'country', 'streetAddress', 'postalCode', 'usageLocation',
                                    'businessPhones', 'mobilePhone', 'faxNumber'] as $a): ?>
                    <option value="<?php echo $a; ?>">
                    <?php endforeach; ?>
                    <?php for ($i = 1; $i <= 15; $i++): ?>
                    <option value="onPremisesExtensionAttributes/extensionAttribute<?php echo $i; ?>">
                    <?php endfor; ?>
                </datalist>
                <div class="hint" style="margin-top:10px;"><?php echo et('entra_map_names_hint'); ?></div>
            </div>
            <div class="fld wide">
                <label><?php echo et('map_test_heading'); ?></label>
                <div class="hint"><?php echo et('map_test_hint'); ?></div>
                <div class="fld-row">
                    <input type="text" id="fMapSample" placeholder="<?php echo et('entra_map_test_sample'); ?>">
                    <button class="btn btn-test" id="mapTestBtn" type="button" style="flex:0 0 auto;"><?php echo et('map_test'); ?></button>
                </div>
                <div class="result" id="mapResult"></div>
                <div id="mapAvailable"></div>
            </div>
        </div>

        <!-- ================= Groups ================= -->
        <div class="tab-pane<?php echo $activeTab === 'groups' ? ' active' : ''; ?>" id="groups-pane">
            <div class="fld wide">
                <label><?php echo et('entra_groups_heading'); ?></label>
                <div class="hint"><?php echo et('entra_groups_hint'); ?></div>
            </div>
            <div id="groupsBox" class="wide" style="overflow-x:auto;"></div>
        </div>

        <!-- ================= History ================= -->
        <div class="tab-pane<?php echo $activeTab === 'history' ? ' active' : ''; ?>" id="history-pane">
            <div class="fld wide">
                <label><?php echo et('history_heading'); ?></label>
                <div class="hint"><?php echo et('history_hint'); ?></div>
            </div>
            <div id="runsBox" class="wide" style="overflow-x:auto;"></div>
        </div>
    </div>

    <div class="save-bar">
        <button class="btn btn-primary" id="saveBtn" type="button"><?php echo htmlspecialchars(t('common.save')); ?></button>
    </div>
</div>

<div class="sso-modal-overlay" id="runModal">
    <div class="sso-modal">
        <div class="sso-modal-header">
            <h2 id="runModalTitle"></h2>
            <div class="sub" id="runModalSub"></div>
        </div>
        <div class="preview-banner" id="runPreviewBanner" style="display:none;"><?php echo et('sync_preview_prefix'); ?></div>
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
const HAS_OWN_SECRET = <?php echo $hasOwnSecret ? 'true' : 'false'; ?>;
const API = '../../api/system/';
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
const radio = name => (document.querySelector('input[name="' + name + '"]:checked') || {}).value;
const post = async (url, body) => (await fetch(API + url, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
})).json();

function switchProvTab(id) {
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === id));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === id + '-pane'));
    history.replaceState(null, '', '?id=' + PROVIDER_ID + '&tab=' + id);
    if (id === 'history') loadRuns();
    if (id === 'groups') loadGroups();
}

/* The connection values on the form. Sent with Test and with every group
   search, so both check what is about to be saved, not what was saved last. */
function connection() {
    const own = radio('appMode') === 'own';
    return {
        provider_id: PROVIDER_ID,
        graph_tenant_id: $('fTenant').value.trim(),
        graph_use_own_app: own ? 1 : 0,
        graph_client_id: own ? $('fClientId').value.trim() : '',
        graph_client_secret: own ? $('fClientSecret').value : ''
    };
}

function toggles() {
    $('ownAppFields').style.display = radio('appMode') === 'own' ? '' : 'none';
    $('userGroupsPicker').style.display = radio('userScope') === 'groups' ? '' : 'none';
    $('groupsPicker').style.display = radio('groupScope') === 'selected' ? '' : 'none';
}
document.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', toggles));
toggles();

/* ---------------- Group picker ----------------
   Object ids are what is stored and what Graph is asked about; names are only
   ever looked up, because a group renamed in Entra must not stop matching. */
const KIND = k => k ? (window.t('system.sso.entra_kind_' + k.replace('-', '_')) || k) : '';

class GroupPicker {
    constructor(el) {
        this.el = el;
        this.chosen = new Map();   // id -> { name, missing }
        el.innerHTML = '<div class="chosen"></div>'
            + '<div class="fld-row"><input type="text" class="pk-q" placeholder="' + esc(window.t('system.sso.entra_pick_search_ph')) + '"'
            + ' style="padding:8px 10px;font-size:12.5px;border:1px solid var(--border,#ddd);border-radius:6px;background:var(--surface,#fff);color:var(--text,#333);">'
            + '<button type="button" class="btn btn-test btn-sm pk-go" style="flex:0 0 auto;">' + esc(window.t('system.sso.entra_pick_search')) + '</button></div>'
            + '<div class="result pk-msg"></div><div class="picker-results"></div>';
        el.querySelector('.pk-go').addEventListener('click', () => this.search());
        el.querySelector('.pk-q').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); this.search(); } });
        const ids = (el.dataset.ids || '').split(/\s+/).filter(Boolean);
        ids.forEach(id => this.chosen.set(id, { name: id, missing: false }));
        this.renderChosen();
        if (ids.length) this.resolve(ids);
    }
    ids() { return [...this.chosen.keys()].join('\n'); }
    msg(cls, text) { const m = this.el.querySelector('.pk-msg'); m.className = 'result pk-msg' + (cls ? ' ' + cls : ''); m.textContent = text || ''; }
    renderChosen() {
        const box = this.el.querySelector('.chosen');
        if (!this.chosen.size) { box.innerHTML = '<span class="hint">' + esc(window.t('system.sso.entra_pick_none_chosen')) + '</span>'; return; }
        box.innerHTML = [...this.chosen].map(([id, g]) =>
            '<span class="gchip' + (g.missing ? ' missing' : '') + '" title="' + esc(id) + '">'
            + esc(g.missing ? window.t('system.sso.entra_pick_missing') + ' (' + id + ')' : g.name)
            + '<button type="button" data-rm="' + esc(id) + '" aria-label="×">×</button></span>').join('');
        box.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => { this.chosen.delete(b.dataset.rm); this.renderChosen(); }));
    }
    async resolve(ids) {
        try {
            const d = await post('search_entra_groups.php', Object.assign(connection(), { ids: ids }));
            if (!d.success) { this.msg('warn', d.error); return; }
            d.groups.forEach(g => { if (this.chosen.has(g.id)) this.chosen.set(g.id, { name: g.name || g.id, missing: !!g.missing }); });
            this.renderChosen();
        } catch (e) { this.msg('warn', String(e.message || e)); }
    }
    async search() {
        const out = this.el.querySelector('.picker-results');
        this.msg('', '');
        out.innerHTML = '<div class="hint">' + esc(window.t('system.sso.testing')) + '</div>';
        try {
            const d = await post('search_entra_groups.php', Object.assign(connection(), { q: this.el.querySelector('.pk-q').value.trim() }));
            if (!d.success) { out.innerHTML = ''; this.msg('err', d.error); return; }
            if (!d.groups.length) { out.innerHTML = '<div class="hint">' + esc(window.t('system.sso.entra_pick_no_results')) + '</div>'; return; }
            out.innerHTML = d.groups.map(g => '<div class="picker-row"><span class="nm">' + esc(g.name)
                + (g.description ? '<small>' + esc(g.description) + '</small>' : '') + '</span>'
                + '<span class="kind">' + esc(KIND(g.kind)) + '</span>'
                + '<button type="button" class="btn btn-test btn-sm" data-add="' + esc(g.id) + '" data-name="' + esc(g.name) + '"'
                + (this.chosen.has(g.id) ? ' disabled' : '') + '>' + esc(window.t('system.sso.entra_pick_add')) + '</button></div>').join('');
            out.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => {
                this.chosen.set(b.dataset.add, { name: b.dataset.name, missing: false });
                b.disabled = true;
                this.renderChosen();
            }));
        } catch (e) { out.innerHTML = ''; this.msg('err', String(e.message || e)); }
    }
}
const userGroupsPicker = new GroupPicker($('userGroupsPicker'));
const groupsPicker     = new GroupPicker($('groupsPicker'));

/* ---------------- Save, test, run ---------------- */
function payload() {
    return Object.assign(connection(), {
        id: PROVIDER_ID,
        sync_enabled: $('fSyncEnabled').checked ? 1 : 0,
        ...mappingValues(),
        graph_user_scope: radio('userScope'),
        graph_user_groups: userGroupsPicker.ids(),
        graph_include_guests: $('fGuests').checked ? 1 : 0,
        graph_group_scope: radio('groupScope'),
        graph_groups: groupsPicker.ids(),
        sync_on_conflict: $('fSyncOnConflict').value,
        sync_deactivate_after: parseInt($('fSyncDeactivateAfter').value, 10) || 0,
        sync_brake_percent: parseInt($('fSyncBrakePercent').value, 10) || 0
    });
}

$('saveBtn').addEventListener('click', async function () {
    this.disabled = true;
    try {
        const d = await post('save_entra_sync.php', payload());
        if (!d.success) { showToast(d.error || 'Save failed', 'error'); return; }
        $('fClientSecret').value = '';
        showToast(window.t('common.saved') || 'Saved', 'success');
    } catch (e) {
        showToast(String(e.message || e), 'error');
    } finally { this.disabled = false; }
});

$('testBtn').addEventListener('click', async function () {
    const box = $('testResult');
    box.className = 'result ok'; box.textContent = window.t('system.sso.testing');
    this.disabled = true;
    try {
        const d = await post('test_entra_connection.php', connection());
        box.className = 'result ' + (d.success ? 'ok' : 'err');
        box.textContent = d.message || d.error || '';
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    } finally { this.disabled = false; }
});

/* A run uses the SAVED settings (the scheduled task can only ever see those),
   so unsaved changes are saved first rather than silently ignored. */
async function runSync(mode) {
    const box = $('syncResult');
    if (mode === 'live') {
        const ok = await showConfirm({
            title: window.t('system.sso.sync_run'), message: window.t('system.sso.sync_confirm'), okLabel: window.t('system.sso.sync_run')
        });
        if (!ok) return;
    }
    box.className = 'result ok'; box.textContent = window.t('system.sso.sync_running');
    try {
        const s = await post('save_entra_sync.php', payload());
        if (!s.success) { box.className = 'result err'; box.textContent = s.error || 'Save failed'; return; }
        const d = await post('run_directory_sync.php', { provider_id: PROVIDER_ID, mode: mode });
        if (!d.success) { box.className = 'result err'; box.textContent = d.error || 'Failed'; return; }
        const r = d.run || {};
        const line = [
            window.t('system.sso.sync_found',   { n: r.seen_count }),
            window.t('system.sso.sync_created', { n: r.created_count }),
            window.t('system.sso.sync_updated', { n: Number(r.updated_count) + Number(r.adopted_count) }),
            window.t('system.sso.sync_left',    { n: r.deactivated_count })
        ].join(' · ');
        box.className = 'result ' + (r.status === 'ok' ? 'ok' : (r.status === 'stopped' ? 'warn' : 'err'));
        box.textContent = (mode === 'preview' ? window.t('system.sso.sync_preview_prefix') + '\n' : '')
                        + line + (r.message ? '\n\n' + r.message : '');
        if (r.id) openRunDetail(r.id, mode, r.started_datetime, r.status);
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    }
}
$('previewBtn').addEventListener('click', () => runSync('preview'));
$('runBtn').addEventListener('click',   () => runSync('live'));

/* ---------------- Imported groups ---------------- */
async function loadGroups() {
    const box = $('groupsBox');
    box.innerHTML = '';
    try {
        const d = await (await fetch(API + 'get_directory_groups.php?provider_id=' + PROVIDER_ID)).json();
        if (!d.success) { box.innerHTML = '<div class="hint">' + esc(d.error) + '</div>'; return; }
        if (d.not_ready) { box.innerHTML = '<div class="hint">' + esc(window.t('system.sso.entra_not_ready')) + '</div>'; return; }
        if (!d.groups.length) { box.innerHTML = '<div class="hint">' + esc(window.t('system.sso.entra_groups_none')) + '</div>'; return; }
        box.innerHTML = '<table class="runs"><thead><tr>'
            + ['name', 'kind', 'members', 'linked', 'status'].map(h => '<th>' + esc(window.t('system.sso.entra_gcol_' + h)) + '</th>').join('')
            + '</tr></thead><tbody>' + d.groups.map(g => `<tr class="clickable" data-gid="${Number(g.id)}">
                <td class="who">${esc(g.display_name)}${g.mail ? '<small>' + esc(g.mail) + '</small>' : ''}</td>
                <td>${esc(KIND(g.group_kind))}</td>
                <td>${Number(g.member_count)}</td>
                <td>${Number(g.linked_count)}</td>
                <td><span class="pill ${Number(g.is_active) ? 'ok' : 'off'}">${esc(window.t('system.sso.entra_group_' + (Number(g.is_active) ? 'active' : 'inactive')))}</span></td>
            </tr>`).join('') + '</tbody></table>';
        box.querySelectorAll('tr[data-gid]').forEach(tr => tr.addEventListener('click', () => toggleMembers(tr)));
    } catch (e) {
        box.innerHTML = '<div class="hint">' + esc(String(e.message || e)) + '</div>';
    }
}

async function toggleMembers(tr) {
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('members')) { next.remove(); return; }
    const row = document.createElement('tr');
    row.className = 'members';
    row.innerHTML = '<td colspan="5" class="hint">' + esc(window.t('system.sso.run_loading')) + '</td>';
    tr.after(row);
    try {
        const d = await (await fetch(API + 'get_directory_groups.php?group_id=' + tr.dataset.gid)).json();
        const m = d.members || [];
        row.firstChild.innerHTML = m.length
            ? m.map(u => esc(u.display_name) + (u.email ? ' <span class="kind">' + esc(u.email) + '</span>' : '')
                + (Number(u.is_active) ? '' : ' <span class="pill off">' + esc(window.t('system.sso.entra_group_inactive')) + '</span>')).join('<br>')
            : esc(window.t('system.sso.entra_members_none'));
    } catch (e) { row.firstChild.textContent = String(e.message || e); }
}

/* ---------------- What one run did (as provider.php) ---------------- */
const RUN_ACTIONS = [
    { key: 'error', on: true }, { key: 'conflict', on: true }, { key: 'create', on: true },
    { key: 'update', on: true }, { key: 'adopt', on: true }, { key: 'deactivate', on: true },
    { key: 'skip', on: true }, { key: 'unchanged', on: false }
];
let runShown = new Set();
const actionLabel = (action, mode) => window.t('system.sso.act_' + (mode === 'preview' ? 'will_' : 'did_') + action) || action;

async function openRunDetail(runId, mode, startedAt, status) {
    $('runModalTitle').textContent = window.t('system.sso.run_modal_' + (mode === 'preview' ? 'preview' : 'live'));
    $('runModalSub').textContent = startedAt ? fmtDateTime(startedAt) : '';
    $('runPreviewBanner').style.display = mode === 'preview' ? '' : 'none';
    $('runFilters').innerHTML = '';
    $('runPeople').innerHTML = '<div class="run-empty">' + esc(window.t('system.sso.run_loading')) + '</div>';
    $('runFooterNote').textContent = '';
    $('runModal').classList.add('open');
    try {
        const d = await (await fetch(API + 'get_directory_sync_log.php?run_id=' + runId + '&action=all')).json();
        if (!d.success) { $('runPeople').innerHTML = '<div class="run-empty">' + esc(d.error || 'Failed') + '</div>'; return; }
        const counts = d.by_action || {};
        if (!(d.entries || []).length) {
            $('runPeople').innerHTML = '<div class="run-empty">' + esc(window.t(status === 'stopped' ? 'system.sso.run_none_stopped' : 'system.sso.run_none')) + '</div>';
            return;
        }
        runShown = new Set(RUN_ACTIONS.filter(a => a.on && counts[a.key]).map(a => a.key));
        $('runFilters').innerHTML = RUN_ACTIONS.filter(a => counts[a.key]).map(a =>
            '<button type="button" class="chip' + (runShown.has(a.key) ? ' on' : '') + '" data-act="' + a.key + '">'
            + esc(actionLabel(a.key, mode)) + '<span class="n">' + counts[a.key] + '</span></button>'
        ).join('') + '<input type="text" class="run-search" id="runSearch" placeholder="' + esc(window.t('system.sso.run_search')) + '">';
        $('runPeople').innerHTML = '<table class="people"><thead><tr>'
            + '<th style="width:30%;">' + esc(window.t('system.sso.run_col_person')) + '</th>'
            + '<th style="width:20%;">' + esc(window.t('system.sso.run_col_what')) + '</th>'
            + '<th>' + esc(window.t('system.sso.run_col_detail')) + '</th></tr></thead><tbody>'
            + d.entries.map(e => `<tr data-act="${esc(e.action)}" data-name="${esc(((e.display_name || '') + ' ' + (e.directory_username || '')).toLowerCase())}">
                <td class="who">${esc(e.display_name || '—')}${e.directory_username ? '<small>' + esc(e.directory_username) + '</small>' : ''}</td>
                <td><span class="act ${esc(e.action)}">${esc(actionLabel(e.action, mode))}</span></td>
                <td>${esc(e.detail || '')}</td></tr>`).join('') + '</tbody></table>';
        $('runFilters').querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
            const k = c.dataset.act;
            if (runShown.has(k)) runShown.delete(k); else runShown.add(k);
            c.classList.toggle('on');
            applyRunFilter();
        }));
        $('runSearch').addEventListener('input', applyRunFilter);
        applyRunFilter();
        if (d.entries.length >= 1000) $('runFooterNote').textContent = window.t('system.sso.run_capped');
    } catch (e) {
        $('runPeople').innerHTML = '<div class="run-empty">' + esc(String(e.message || e)) + '</div>';
    }
}

function applyRunFilter() {
    const q = ($('runSearch') ? $('runSearch').value : '').trim().toLowerCase();
    document.querySelectorAll('#runPeople tbody tr').forEach(tr => {
        tr.classList.toggle('hide', !(runShown.has(tr.dataset.act) && (!q || tr.dataset.name.includes(q))));
    });
}

function closeRunModal() { $('runModal').classList.remove('open'); }
$('runModal').addEventListener('click', e => { if (e.target === $('runModal')) closeRunModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('runModal').classList.contains('open')) closeRunModal(); });

async function loadRuns() {
    const box = $('runsBox');
    box.innerHTML = '';
    try {
        const d = await (await fetch(API + 'get_directory_sync_log.php?provider_id=' + PROVIDER_ID)).json();
        if (!d.success || !(d.runs || []).length) { box.innerHTML = '<div class="hint">' + esc(window.t('system.sso.history_none')) + '</div>'; return; }
        box.innerHTML = '<table class="runs"><thead><tr>'
            + ['when','mode','result','found','added','changed','left','issues','by'].map(h => '<th>' + esc(window.t('system.sso.hist_' + h) || h) + '</th>').join('')
            + '</tr></thead><tbody>' + d.runs.map(r => {
                const cls = r.status === 'ok' ? 'ok' : (r.status === 'stopped' ? 'stopped' : 'failed');
                return `<tr class="clickable" onclick="openRunDetail(${Number(r.id)}, '${esc(r.mode)}', '${esc(r.started_datetime)}', '${esc(r.status)}')">
                    <td>${esc(fmtDateTime(r.started_datetime))}</td><td>${esc(r.mode)}</td>
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

/* ---------------- Field mapping ----------------
   The LDAP provider's mapping test (provider.php), for Graph. */
function mappingValues() {
    const out = {};
    document.querySelectorAll('input.graph-attr').forEach(i => { out['graph_attr_' + i.dataset.field] = i.value.trim(); });
    return out;
}

/* Fill the example column from one real person, with the values ON THE FORM,
   so a mapping can be checked before it is saved. */
$('mapTestBtn').addEventListener('click', async function () {
    const box = $('mapResult');
    box.className = 'result ok';
    box.textContent = window.t('system.sso.map_testing');
    this.disabled = true;
    try {
        const d = await post('test_entra_mapping.php', Object.assign(connection(), mappingValues(), { sample: $('fMapSample').value.trim() }));
        if (!d.success) {
            box.className = 'result err';
            box.textContent = d.error || 'Failed';
            clearSamples();
            return;
        }
        (d.rows || []).forEach(r => {
            const cell = document.querySelector('#mapping-pane tr[data-field="' + r.key + '"] .map-sample');
            if (!cell) return;
            cell.className = 'map-sample ' + (r.missing ? 'missing' : 'filled');
            cell.textContent = r.missing ? window.t('system.sso.map_empty') : r.value;
        });
        const bad = (d.rows || []).filter(r => r.missing).length;
        box.className = 'result ' + (bad ? 'warn' : 'ok');
        box.textContent = bad
            ? window.t('system.sso.map_result_gaps', { name: d.sample, n: bad })
            : window.t('system.sso.map_result_ok',   { name: d.sample });
        renderAvailable(d.available || []);
    } catch (e) {
        box.className = 'result err'; box.textContent = String(e.message || e);
    } finally { this.disabled = false; }
});

function clearSamples() {
    document.querySelectorAll('#mapping-pane td.map-sample').forEach(c => { c.className = 'map-sample'; c.textContent = '—'; });
}

/* Everything the sample person has, so an empty example can be told apart:
   a misspelt attribute, or a detail this person simply does not have. */
function renderAvailable(list) {
    const box = $('mapAvailable');
    if (!list.length) { box.innerHTML = ''; return; }
    box.innerHTML = '<details class="avail"><summary>'
        + esc(window.t('system.sso.map_available', { n: list.length }))
        + '</summary><div class="avail-grid">'
        + list.map(a => '<div><code>' + esc(a.name) + '</code> <span>' + esc(a.value) + '</span></div>').join('')
        + '</div></details>';
}

const START_TAB = <?php echo json_encode($activeTab); ?>;
if (START_TAB === 'history') loadRuns();
if (START_TAB === 'groups') loadGroups();
</script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
