<?php
/**
 * Asset Management - Settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/settings_manifest.php';
require_once '../../includes/handover_styles.php';   // the preview uses the real document CSS (#56)
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

// RBAC Layer 2: which of these tabs may this analyst see? Everything below is rendered
// from the manifest, so a tab they lack the capability for is never emitted — there is
// no hidden panel to un-hide. Administrators hold every capability and see the lot.
$settingsManifest = settingsManifestFor('assets');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);
/* Honour ?tab=, so a link from elsewhere (the Watchtower settings signpost,
   say) lands on the tab it names rather than on whichever is first.
   Validated against the VISIBLE tabs, not just the manifest - a tab this
   analyst has no capability for is never rendered, and asking for it must
   not select nothing, nor slip past the check. Same three lines as
   forms/settings/index.php. */
if (!empty($_GET['tab']) && settingsTabVisible($visibleTabs, (string) $_GET['tab'])) {
    $activeTabId = (string) $_GET['tab'];
}


/**
 * The shared icon library, for the asset-type icon picker (#1146).
 *
 * ⚠️ Read here rather than fetched from api/cmdb/*: the glyphs are shared
 * reference data, and an assets administrator has no reason to hold CMDB module
 * access. Gating an asset-type icon behind the CMDB would be a permission bug
 * dressed up as reuse.
 */
$assetTypeIcons = [];
try {
    $assetTypeIcons = connectToDatabase()->query(
        "SELECT id, icon_key, label FROM cmdb_icons WHERE is_active = 1 ORDER BY display_order, label"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // No icon table on this install — the picker renders empty and everything
    // else on the page still works.
}

$activeCompanies = [];
try {
    $activeCompanies = connectToDatabase()->query(
        "SELECT id, name, is_default FROM tenants WHERE is_active = 1 ORDER BY is_default DESC, name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Single-tenant
}

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars(t('asset-management.settings.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <script src="../../assets/js/chart.min.js"></script>
    <style>
        /* Module accent — drives toggle, focus rings, button colours.
           Modal form CSS lives entirely in inbox.css. */
        body { --accent: #107c10; }

        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
        }

        .tab-content .action-btn {
            background: none;
            border: 1px solid var(--border, #ddd);
            color: var(--text-muted, #666);
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover {
            background: var(--surface-hover, #f0f0f0);
            border-color: #107c10;
            color: #107c10;
        }

        .tab-content .action-btn.delete {
            color: var(--danger-accent, #d13438);
        }

        .tab-content .action-btn.delete:hover {
            background: var(--danger-bg, #fdf3f3);
            border-color: var(--danger-accent, #d13438);
            color: var(--danger-text, #a00);
        }

        .tab-content .action-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Active/Inactive badges use the shared .status-badge / .status-active
           / .status-inactive classes from inbox.css (canonical shape + colour). */

        /* vCenter section styles */
        .settings-section {
            background: var(--surface, #fff);
            border-radius: 8px;
            box-shadow: var(--shadow, 0 1px 4px rgba(0, 0, 0, 0.08));
            margin-bottom: 25px;
            overflow: hidden;
        }

        .settings-section-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-section-header svg { color: #107c10; flex-shrink: 0; }
        .settings-section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .settings-section-body { padding: 25px; }
        .settings-description { font-size: 13px; color: var(--text-muted, #666); margin: 0 0 20px 0; line-height: 1.5; }

        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: var(--text, #333); }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-input:focus { outline: none; border-color: #107c10; box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1); }
        .form-hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }

        .form-actions {
            display: flex; align-items: center; gap: 12px;
            margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-soft, #eee);
        }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #107c10; color: white; }
        .btn-primary:hover { background-color: #0b5c0b; }
        .btn-primary:disabled { background-color: #999; cursor: not-allowed; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-secondary:disabled { background-color: #b0b6bb; cursor: not-allowed; }

        .intune-progress { margin-top: 18px; }
        .intune-progress-bar { background: var(--border, #e0e0e0); border-radius: 4px; height: 10px; overflow: hidden; }
        .intune-progress-fill { background: #107c10; height: 100%; width: 0; transition: width 0.3s ease-out; }
        .intune-progress-meta { font-size: 12px; color: var(--text-muted, #666); margin-top: 6px; }
        .intune-progress.intune-error .intune-progress-fill { background: #d13438; }


        .intune-software-section { margin-top: 30px; padding-top: 25px; border-top: 1px solid var(--border-soft, #eee); }
        .intune-subsection-title { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px 0; }
        .intune-freshness-wrap { margin-top: 22px; padding: 14px 16px; background: var(--surface-3, #fafbfc); border: 1px solid var(--border-soft, #eee); border-radius: 6px; }
        .intune-freshness-title { font-size: 12px; color: var(--text-muted, #666); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px; }
        .intune-freshness-canvas-wrap { position: relative; height: 180px; }
        .intune-jobs-list { margin-top: 18px; }
        .intune-jobs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .intune-jobs-table th { text-align: left; padding: 8px 10px; background: var(--surface-3, #f8f9fa); color: var(--text-muted, #666); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--border, #e0e0e0); }
        .intune-jobs-table td { padding: 8px 10px; border-bottom: 1px solid var(--surface-hover, #f0f0f0); color: var(--text, #333); }
        .intune-jobs-table tbody tr:hover { background: var(--surface-2, #fafafa); }
        .intune-job-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .intune-job-status.pending { background: #fff3e0; color: #e65100; }
        .intune-job-status.running { background: #e3f2fd; color: #1565c0; }
        .intune-job-status.done    { background: #e8f5e9; color: #2e7d32; }
        .intune-job-status.error   { background: #ffebee; color: #c62828; }


        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 45px; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-dim, #888); font-size: 13px; padding: 4px; }
        .password-toggle:hover { color: var(--text, #333); }

        .modal-content {
            padding: 20px;
            max-width: 500px;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text, #333);
            padding: 0;
            border-bottom: none;
        }

        /* Modal form CSS now lives entirely in inbox.css. */
        .modal-actions { margin-top: 20px; }

        /* ── Location tree ─────────────────────────────────────────── */
        .loc-tree {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 8px 4px;
            max-width: 760px;
        }
        .loc-tree ul { list-style: none; margin: 0; padding: 0; }
        /* Children indent + a guide line down the branch. */
        .loc-children { margin-left: 22px; border-left: 1px solid var(--border-soft, #eee); padding-left: 4px; }
        .loc-children.collapsed { display: none; }

        .loc-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 5px;
        }
        .loc-row:hover { background: var(--surface-hover, #f6f8f6); }

        .loc-caret {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-dim, #888);
            border-radius: 3px;
            user-select: none;
            font-size: 11px;
            transition: transform 0.12s;
        }
        .loc-caret:hover { background: var(--surface-hover, #e8efe8); color: var(--text, #333); }
        .loc-caret.collapsed { transform: rotate(-90deg); }
        .loc-caret.leaf { cursor: default; visibility: hidden; }

        .loc-name { flex: 1; font-size: 14px; color: var(--text, #222); }
        .loc-name .loc-count { color: var(--text-faint, #999); font-size: 12px; margin-left: 6px; }
        /* 2.6.0 shared locations */
        .loc-shared {
            margin-left: 8px; padding: 1px 7px; border-radius: 9px;
            font-size: 11px; font-weight: 600;
            background: var(--accent-soft, #e8f0f8); color: var(--accent, #0078d4);
        }
        .loc-actions-locked .action-btn:not(:first-child) { display: none; }

        .loc-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.12s; }
        .loc-row:hover .loc-actions { opacity: 1; }

        .loc-empty { color: var(--text-faint, #999); padding: 16px 12px; }

        /* ── Custom fields tab ─────────────────────────────────────── */
        .cf-notice {
            padding: 14px 16px;
            border: 1px solid var(--border, #e0e0e0);
            /* ⚠️ --warning does NOT exist; the real tokens are the
               --warning-bg / -border / -text trio. A phantom token is invisible
               until somebody looks at it in dark mode. */
            border-left: 3px solid var(--warning-border, #d18b00);
            border-radius: 6px;
            background: var(--warning-bg, #fff8e6);
            color: var(--warning-text, #6b4e00);
            font-size: 13px;
            margin-bottom: 18px;
        }
        .cf-block {
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 22px;
            background: var(--surface, #fff);
        }
        .cf-block-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .cf-block-title { margin: 0 0 4px 0; font-size: 15px; font-weight: 600; color: var(--text, #333); }
        .cf-block-intro { margin: 0 0 14px 0; font-size: 13px; color: var(--text-muted, #666); line-height: 1.5; }
        .cf-typebar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .cf-typebar label { font-size: 13px; color: var(--text-muted, #666); }
        .cf-typebar select { min-width: 220px; }
        .cf-typeactions { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }

        /* A field on the selected type. The set it arrives from is shown on
           every row, because "why is this here?" is the question this screen
           gets asked most. */
        .cf-row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px;
            border: 1px solid var(--border-soft, #eee);
            border-radius: 6px;
            margin-bottom: 6px;
            background: var(--surface-2, #fafafa);
        }
        .cf-row-name { font-weight: 600; color: var(--text, #333); font-size: 13px; }
        .cf-row-kind { font-size: 12px; color: var(--text-muted, #666); }
        .cf-row-from { margin-left: auto; font-size: 11px; color: var(--text-muted, #666); }
        .cf-req { color: var(--danger-text, #c0392b); margin-left: 3px; }
        .cf-empty { color: var(--text-faint, #999); padding: 14px 12px; font-size: 13px; }
        .cf-warn { color: var(--danger-text, #c0392b); }
        .cf-scope { font-size: 11px; color: var(--text-muted, #666); }

        /* Field/set pickers inside the modals. */
        .cf-picker {
            max-height: 260px; overflow-y: auto;
            border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 6px;
        }
        .cf-pick {
            display: flex; align-items: center; gap: 8px;
            padding: 7px 8px; border-radius: 4px; font-size: 13px; color: var(--text, #333);
        }
        .cf-pick:hover { background: var(--surface-hover, #f0f0f0); }
        .cf-pick input[type="checkbox"] { flex-shrink: 0; }
        .cf-pick-kind { color: var(--text-muted, #666); font-size: 12px; }
        .cf-pick-req { margin-left: auto; font-size: 11px; color: var(--text-muted, #666); display: flex; align-items: center; gap: 4px; }

        /* ── Custom fields: the tree ───────────────────────────────── */
        .cf-tree { font-size: 13px; }
        .cf-tree-node { padding: 2px 0; }
        .cf-tree-type {
            display: flex; align-items: baseline; gap: 8px;
            padding: 7px 0 4px 0; font-weight: 600; color: var(--text, #333);
            border-top: 1px solid var(--border-soft, #eee);
        }
        .cf-tree-type:first-child { border-top: 0; }
        /* The connector lines. A left border plus a short horizontal rule is
           enough to read as a tree without dragging in an icon set. */
        .cf-tree-set, .cf-tree-field { position: relative; padding-left: 22px; }
        .cf-tree-set::before, .cf-tree-field::before {
            content: ''; position: absolute; left: 7px; top: 0; bottom: 0;
            border-left: 1px solid var(--border, #e0e0e0);
        }
        .cf-tree-set > span, .cf-tree-field > span { position: relative; }
        .cf-tree-set { padding-top: 4px; }
        .cf-tree-set-name { color: var(--text, #333); font-weight: 600; }
        .cf-tree-field { padding-left: 44px; color: var(--text-muted, #666); }
        .cf-tree-field::before { left: 29px; }
        .cf-tree-kind { color: var(--text-dim, #888); font-size: 12px; }
        .cf-tree-req { color: var(--danger-text, #c0392b); }
        .cf-tree-count { font-size: 11px; color: var(--text-dim, #888); font-weight: 400; }
        /* A type with no custom fields, or a field in no set. Stated, not hidden. */
        .cf-tree-none { color: var(--text-faint, #999); font-style: italic; padding-left: 22px; }
        .cf-tree-warn { color: var(--warning-text, #6b4e00); }
        .cf-tree-section {
            margin-top: 16px; padding-top: 12px;
            border-top: 1px solid var(--border, #e0e0e0);
            font-size: 11px; font-weight: 600; letter-spacing: 0.4px;
            text-transform: uppercase; color: var(--text-muted, #666);
        }

        /* ── Asset type icon picker ────────────────────────────────── */
        .ic-picked { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .ic-preview { display: inline-flex; width: 24px; height: 24px; color: var(--accent, #0078d4); }
        .ic-name { font-size: 13px; color: var(--text-muted, #666); flex: 1; }
        .ic-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(38px, 1fr));
            gap: 4px; max-height: 190px; overflow-y: auto;
            border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 6px;
        }
        .ic-tile {
            display: flex; align-items: center; justify-content: center;
            height: 34px; border: 1px solid transparent; border-radius: 5px;
            background: none; cursor: pointer; color: var(--text-muted, #666);
        }
        .ic-tile:hover { background: var(--surface-hover, #f0f0f0); color: var(--text, #333); }
        .ic-tile.selected {
            border-color: var(--accent, #0078d4);
            background: var(--accent-soft, #e7f1fb);
            color: var(--accent, #0078d4);
        }
        /* An icon inline in a list row. Sits on the text baseline rather than
           forcing the row taller. */
        .ic-inline { vertical-align: -3px; margin-right: 7px; color: var(--text-muted, #666); flex-shrink: 0; }

        /* ── Import tab ────────────────────────────────────────────── */
        .imp-filerow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .imp-info {
            margin-top: 12px; padding: 10px 12px; border-radius: 6px;
            background: var(--surface-2, #fafafa);
            border: 1px solid var(--border-soft, #eee);
            font-size: 13px; color: var(--text, #333);
        }
        .imp-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-top: 16px; }
        .imp-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .imp-hint { font-size: 12px; color: var(--text-dim, #888); }
        .imp-badge {
            font-size: 12px; padding: 2px 9px; border-radius: 999px;
            background: var(--surface-3, #f8f9fa); border: 1px solid var(--border, #e0e0e0);
            color: var(--text-muted, #666);
        }
        .imp-badge.attention {
            background: var(--warning-bg, #fff8e6);
            border-color: var(--warning-border, #d18b00);
            color: var(--warning-text, #6b4e00);
        }
        /* One row's outcome. The colour carries the meaning at a glance; the
           word carries it for anybody who cannot see the colour. */
        .imp-act {
            display: inline-block; min-width: 76px; text-align: center;
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.3px; padding: 2px 8px; border-radius: 4px;
        }
        .imp-act-create    { background: var(--success-bg, #e6f4ea); color: var(--success-text, #1e7e34); }
        /* ⚠️ --info-bg / --info-text do NOT exist. The module's own soft accent
           is the right "this is a change, not a problem" colour, and it already
           tracks the theme. */
        .imp-act-update    { background: var(--accent-soft, #e7f1fb); color: var(--accent, #10508a); }
        .imp-act-unchanged { background: var(--surface-3, #f8f9fa);  color: var(--text-muted, #666); }
        .imp-act-skip      { background: var(--surface-3, #f8f9fa);  color: var(--text-muted, #666); }
        .imp-act-conflict,
        .imp-act-error     { background: var(--danger-bg, #fdecea);  color: var(--danger-text, #c0392b); }
        .imp-act-deactivate{ background: var(--warning-bg, #fff8e6); color: var(--warning-text, #6b4e00); }

        .imp-tally { display: flex; gap: 10px; flex-wrap: wrap; margin: 14px 0; }
        .imp-tally span {
            font-size: 12px; padding: 4px 10px; border-radius: 6px;
            background: var(--surface-2, #fafafa); border: 1px solid var(--border-soft, #eee);
            color: var(--text, #333);
        }
        .imp-rows { max-height: 340px; overflow-y: auto; margin-top: 10px; }
        .imp-row {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee);
            font-size: 13px;
        }
        .imp-row-main { flex: 1; min-width: 0; }
        .imp-row-name { font-weight: 600; color: var(--text, #333); }
        .imp-row-detail { color: var(--text-muted, #666); font-size: 12px; margin-top: 2px; word-break: break-word; }
        .imp-row-raw {
            margin-top: 4px; font-family: ui-monospace, Consolas, monospace;
            font-size: 11px; color: var(--text-dim, #888); word-break: break-all;
        }

        /* ── Suppliers tab ─────────────────────────────────────────── */
        .supplier-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        /* ─── Handover designer (discussion #56) ─────────────────────────── */
        .ho-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 6px 0 16px; }
        .ho-spacer { flex: 1 1 auto; }
        .ho-select {
            padding: 8px 10px; border: 1px solid var(--border, #d5dbe1); border-radius: 5px;
            background: var(--surface, #fff); color: var(--text, #333); font-size: 14px; min-width: 220px;
        }
        .ho-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; align-items: start; }
        .ho-field { display: block; margin-bottom: 14px; }
        .ho-field-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted, #666); margin-bottom: 4px; }
        .ho-field input[type=text], .ho-block textarea, .ho-block input[type=text] {
            width: 100%; box-sizing: border-box; padding: 8px 10px;
            border: 1px solid var(--border, #d5dbe1); border-radius: 5px;
            background: var(--surface, #fff); color: var(--text, #333);
            font-size: 13px; font-family: inherit;
        }
        .ho-block textarea { resize: vertical; min-height: 62px; }
        .ho-merge { border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; }
        .ho-merge-title { font-size: 12px; font-weight: 700; color: var(--text, #333); }
        .ho-merge-hint { font-size: 12px; color: var(--text-muted, #666); margin: 2px 0 8px; }
        .ho-merge-codes { display: flex; flex-wrap: wrap; gap: 6px; }
        .ho-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px;
            padding: 3px 7px; border-radius: 4px; cursor: pointer;
            border: 1px solid var(--border, #d5dbe1); background: var(--surface-hover, #f4f6f8); color: var(--text, #333);
        }
        .ho-code:hover { border-color: var(--accent, #0078d4); color: var(--accent, #0078d4); }
        .ho-block {
            border: 1px solid var(--border, #e0e0e0); border-radius: 6px;
            margin-bottom: 8px; background: var(--surface, #fff);
        }
        .ho-block.disabled { opacity: 0.55; }
        .ho-block-head { display: flex; align-items: center; gap: 8px; padding: 8px 10px; }
        .ho-block-name { font-weight: 600; font-size: 13px; flex: 1 1 auto; color: var(--text, #333); }
        .ho-mini {
            border: 1px solid var(--border, #d5dbe1); background: var(--surface, #fff);
            color: var(--text-muted, #666); border-radius: 4px; cursor: pointer;
            width: 26px; height: 26px; line-height: 1; font-size: 13px;
        }
        .ho-mini:hover:not(:disabled) { color: var(--accent, #0078d4); border-color: var(--accent, #0078d4); }
        .ho-mini:disabled { opacity: 0.35; cursor: default; }
        .ho-block-body { padding: 0 10px 10px; display: grid; gap: 8px; }
        .ho-cols { display: flex; flex-wrap: wrap; gap: 10px; }
        .ho-col-toggle { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text, #333); }
        .ho-preview-wrap { position: sticky; top: 12px; }
        .ho-preview-head {
            font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
            color: var(--text-muted, #666); margin-bottom: 6px;
        }
        /* The preview is a real document, so it keeps the document's own light
           palette even when the settings page around it is dark. */
        .ho-preview {
            background: #fff; padding: 26px 28px; border-radius: 6px;
            border: 1px solid var(--border, #e0e0e0);
            max-height: 70vh; overflow: auto;
        }
        @media (max-width: 1100px) {
            .ho-grid { grid-template-columns: 1fr; }
            .ho-preview-wrap { position: static; }
        }
<?php echo handoverDocumentCss(); ?>
    </style>
    <?php /* Mobile-friendly opt-in (#937). AFTER this page's own <style> so its
             @media rules win on ties. Every rule inside is gated at 768px. */ ?>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=189">
    <script src="../../assets/js/qrcode.min.js"></script>
</head>
<?php /* The marker mobile.css LAYER 15e keys on. `.container` is far too common
         a class to restyle globally, so a settings page opts in by name. */ ?>
<body data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <!-- Left panel tab — per-analyst preference, so it declares no capability and is never gated -->
        <div class="tab-content<?php echo $activeTabId === 'left-panel' ? ' active' : ''; ?>" id="left-panel-tab" data-capability="none">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('asset-management.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text, #333);"><?php echo htmlspecialchars(t('common.left_panel.visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="assetsSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.always')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('asset-management.settings.left_panel_always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="assetsSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.hover')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('asset-management.settings.left_panel_hover_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>

        <?php if (settingsTabVisible($visibleTabs, 'asset-types')): ?>
        <!-- Asset Types Tab -->
        <div class="tab-content<?php echo $activeTabId === 'asset-types' ? ' active' : ''; ?>" id="asset-types-tab" data-capability="<?php echo Cap::ASSETS_TYPES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_asset_types')); ?></h2>
                <button class="add-btn" onclick="openAddModal('asset-type')"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <p class="settings-description" style="margin-bottom: 16px;">
                <?php echo t('asset-management.settings.asset_types_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.field.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.common.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="asset-types-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'import')): ?>
        <?php /*
            Import. A wizard in four steps, in the order the decisions actually
            get made:

              1. the file            — what have you got?
              2. the mapping         — where does each column go?
              3. what identifies a row — the setting that decides whether run two
                                        UPDATES or DUPLICATES. Given its own step
                                        because it is the one people skip.
              4. preview, then go    — the same run, stopped before it writes.

            Below the wizard: past runs, and the holding area for rows that could
            not be imported.
        */ ?>
        <div class="tab-content<?php echo $activeTabId === 'import' ? ' active' : ''; ?>" id="import-tab" data-capability="<?php echo Cap::ASSETS_IMPORT; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_import')); ?></h2>
            </div>
            <p class="settings-description" style="margin-bottom: 18px;">
                <?php echo t('asset-management.settings.imp_intro'); ?>
            </p>

            <div id="impNotReady" class="cf-notice" style="display:none;">
                <?php echo t('asset-management.settings.imp_not_ready'); ?>
            </div>

            <div id="impBody" style="display:none;">
                <!-- Step 1 — the file -->
                <div class="cf-block">
                    <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.imp_step_file')); ?></h3>
                    <p class="cf-block-intro"><?php echo t('asset-management.settings.imp_step_file_intro'); ?></p>
                    <div class="imp-filerow">
                        <input type="file" id="impFile" accept=".csv,text/csv">
                        <button type="button" class="btn btn-outline btn-sm" onclick="impUpload()"><?php echo htmlspecialchars(t('asset-management.settings.imp_read')); ?></button>
                    </div>
                    <div id="impFileInfo" class="imp-info" style="display:none;"></div>
                </div>

                <!-- Steps 2-4 appear once a file has been read -->
                <div id="impWizard" style="display:none;">
                    <div class="cf-block">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.imp_step_map')); ?></h3>
                        <p class="cf-block-intro"><?php echo t('asset-management.settings.imp_step_map_intro'); ?></p>
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_source')); ?></th>
                                    <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_sample')); ?></th>
                                    <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_target')); ?></th>
                                </tr>
                            </thead>
                            <tbody id="impMapList"></tbody>
                        </table>
                        <?php /* Unmapped columns are named out loud. A column that
                                 silently goes nowhere is how half an import
                                 disappears without anybody noticing. */ ?>
                        <div id="impIgnored" class="imp-info" style="display:none;"></div>
                    </div>

                    <div class="cf-block">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.imp_step_match')); ?></h3>
                        <p class="cf-block-intro"><?php echo t('asset-management.settings.imp_step_match_intro'); ?></p>
                        <div id="impMatchKeys" class="cf-picker"></div>

                        <div class="imp-options">
                            <div class="form-group">
                                <label for="impWriteMode"><?php echo htmlspecialchars(t('asset-management.settings.imp_write_mode')); ?></label>
                                <select id="impWriteMode">
                                    <option value="fill"><?php echo htmlspecialchars(t('asset-management.settings.imp_write_fill')); ?></option>
                                    <option value="overwrite"><?php echo htmlspecialchars(t('asset-management.settings.imp_write_overwrite')); ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="impUnknownOption"><?php echo htmlspecialchars(t('asset-management.settings.imp_unknown_option')); ?></label>
                                <select id="impUnknownOption">
                                    <option value="reject"><?php echo htmlspecialchars(t('asset-management.settings.imp_unknown_reject')); ?></option>
                                    <option value="add"><?php echo htmlspecialchars(t('asset-management.settings.imp_unknown_add')); ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="impDefaultType"><?php echo htmlspecialchars(t('asset-management.settings.imp_default_type')); ?></label>
                                <select id="impDefaultType"></select>
                            </div>
                        </div>
                    </div>

                    <div class="cf-block">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.imp_step_go')); ?></h3>
                        <p class="cf-block-intro"><?php echo t('asset-management.settings.imp_step_go_intro'); ?></p>
                        <div class="imp-actions">
                            <button type="button" class="btn btn-outline" onclick="impRun('preview')"><?php echo htmlspecialchars(t('asset-management.settings.imp_preview')); ?></button>
                            <?php /* Disabled until a preview has been seen. The
                                     preview is not optional advice — it is the
                                     only thing standing between a mis-mapped
                                     column and 500 wrong records. */ ?>
                            <button type="button" class="btn btn-primary" id="impGoBtn" onclick="impRun('live')" disabled><?php echo htmlspecialchars(t('asset-management.settings.imp_go')); ?></button>
                            <span class="imp-hint" id="impGoHint"><?php echo htmlspecialchars(t('asset-management.settings.imp_preview_first')); ?></span>
                        </div>
                        <div id="impResult"></div>
                    </div>
                </div>

                <!-- The holding area -->
                <div class="cf-block">
                    <div class="cf-block-head">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.imp_held')); ?></h3>
                        <span class="imp-badge" id="impHeldCount"></span>
                    </div>
                    <p class="cf-block-intro"><?php echo t('asset-management.settings.imp_held_intro'); ?></p>
                    <div id="impHeldList"></div>
                </div>

                <!-- Past runs -->
                <div class="cf-block">
                    <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.imp_history')); ?></h3>
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_when')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_file')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_result')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.imp_col_who')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="impRunList"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'custom-fields')): ?>
        <?php /*
            Custom asset fields. Three sections, deliberately in this order:

            1. BY ASSET TYPE — the guided path, and the only one most people
               need. "What gets recorded against a Television?" Adding a field
               here quietly creates or reuses a set behind the scenes, so
               somebody who never wants to think about sets never meets one.
            2. ALL FIELDS — the catalogue, because a field is defined once and
               reused. This is what makes one search span every asset type.
            3. FIELD SETS — the bundles. Last, because they only start to make
               sense once you want to reuse a handful of fields, which is
               exactly when somebody comes looking for them.
        */ ?>
        <div class="tab-content<?php echo $activeTabId === 'custom-fields' ? ' active' : ''; ?>" id="custom-fields-tab" data-capability="<?php echo Cap::ASSETS_FIELDS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_custom_fields')); ?></h2>
            </div>
            <p class="settings-description" style="margin-bottom: 18px;">
                <?php echo t('asset-management.settings.cf_intro'); ?>
            </p>

            <?php /* Told, never implied — an install that has not run Database
                     Verification must not read as "you have no fields". */ ?>
            <div id="cfNotReady" class="cf-notice" style="display:none;">
                <?php echo t('asset-management.settings.cf_not_ready'); ?>
            </div>

            <div id="cfBody" style="display:none;">
                <?php /* Read-only overview, first because it is orientation
                         rather than an action. Asked for after somebody set up
                         two sets across four types and could not see, anywhere
                         in one place, how types / sets / fields joined up —
                         which is exactly when the model stops being obvious.

                         ⚠️ Types with NOTHING are listed too. "This type records
                         nothing extra" and "we did not look" must never render
                         the same way, and a missing type is precisely the
                         mistake this view exists to catch. */ ?>
                <div class="cf-block">
                    <div class="cf-block-head">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.cf_tree')); ?></h3>
                        <button type="button" class="btn btn-outline btn-sm" onclick="cfToggleTree()" id="cfTreeToggle"></button>
                    </div>
                    <p class="cf-block-intro"><?php echo t('asset-management.settings.cf_tree_intro'); ?></p>
                    <div id="cfTree"></div>
                </div>

                <!-- 1. By asset type -->
                <div class="cf-block">
                    <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.cf_sec_types')); ?></h3>
                    <p class="cf-block-intro"><?php echo t('asset-management.settings.cf_sec_types_intro'); ?></p>
                    <div class="cf-typebar">
                        <label for="cfTypeSelect"><?php echo htmlspecialchars(t('asset-management.settings.cf_choose_type')); ?></label>
                        <select id="cfTypeSelect" onchange="cfRenderType()"></select>
                    </div>
                    <div id="cfTypeFields"></div>
                    <div class="cf-typeactions">
                        <button class="btn btn-outline btn-sm" onclick="cfOpenFieldModal(null, true)"><?php echo htmlspecialchars(t('asset-management.settings.cf_add_field')); ?></button>
                        <button class="btn btn-outline btn-sm" onclick="cfOpenAttachSet()"><?php echo htmlspecialchars(t('asset-management.settings.cf_type_add_set')); ?></button>
                    </div>
                </div>

                <!-- 2. The catalogue -->
                <div class="cf-block">
                    <div class="cf-block-head">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.cf_sec_catalogue')); ?></h3>
                        <button class="add-btn" onclick="cfOpenFieldModal(null, false)"><?php echo htmlspecialchars(t('asset-management.settings.cf_add_field')); ?></button>
                    </div>
                    <p class="cf-block-intro"><?php echo t('asset-management.settings.cf_sec_catalogue_intro'); ?></p>
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.cf_col_field')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.cf_col_kind')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.cf_col_used')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.common.actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cfFieldList"></tbody>
                    </table>
                </div>

                <!-- 3. Sets -->
                <div class="cf-block">
                    <div class="cf-block-head">
                        <h3 class="cf-block-title"><?php echo htmlspecialchars(t('asset-management.settings.cf_sec_sets')); ?></h3>
                        <button class="add-btn" onclick="cfOpenSetModal(null)"><?php echo htmlspecialchars(t('asset-management.settings.cf_add_set')); ?></button>
                    </div>
                    <p class="cf-block-intro"><?php echo t('asset-management.settings.cf_sec_sets_intro'); ?></p>
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.cf_set_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.cf_set_fields')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.settings.cf_col_used')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.common.actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cfSetList"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'asset-statuses')): ?>
        <!-- Asset Statuses Tab -->
        <div class="tab-content<?php echo $activeTabId === 'asset-statuses' ? ' active' : ''; ?>" id="asset-statuses-tab" data-capability="<?php echo Cap::ASSETS_STATUSES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_asset_statuses')); ?></h2>
                <button class="add-btn" onclick="openAddModal('asset-status')"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <p class="settings-description" style="margin-bottom: 16px;">
                <?php echo t('asset-management.settings.asset_statuses_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.field.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.common.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="asset-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'locations')): ?>
        <!-- Locations Tab -->
        <div class="tab-content<?php echo $activeTabId === 'locations' ? ' active' : ''; ?>" id="locations-tab" data-capability="<?php echo Cap::ASSETS_LOCATIONS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_locations')); ?></h2>
                <button class="add-btn" onclick="openAddLocation(null)"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <p class="settings-description" style="margin-bottom: 18px;">
                <?php echo t('asset-management.settings.locations_intro'); ?>
            </p>
            <div id="locations-tree" class="loc-tree">
                <div style="color:#999; padding: 12px;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></div>
            </div>
        </div>

        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'suppliers')): ?>
        <!-- Suppliers Tab -->
        <div class="tab-content<?php echo $activeTabId === 'suppliers' ? ' active' : ''; ?>" id="suppliers-tab" data-capability="<?php echo Cap::ASSETS_SUPPLIERS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_suppliers')); ?></h2>
            </div>
            <p class="settings-description" style="margin-bottom: 16px;">
                <?php echo t('asset-management.settings.suppliers_intro'); ?>
            </p>
            <div class="supplier-toolbar">
                <input type="text" id="supplierSearch" class="form-input" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.supplier_search_placeholder')); ?>" autocomplete="off" oninput="renderSupplierList()" style="max-width: 280px;">
                <span style="flex: 1;"></span>
                <input type="text" id="supplierQuickAdd" class="form-input" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.supplier_new_placeholder')); ?>" autocomplete="off" style="max-width: 220px;">
                <button class="add-btn" onclick="quickAddSupplier()"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <table style="margin-top: 14px;">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_supplier')); ?></th>
                        <th style="width: 160px;"><?php echo htmlspecialchars(t('asset-management.settings.available_for_assets')); ?></th>
                    </tr>
                </thead>
                <tbody id="suppliers-list">
                    <tr><td colspan="2" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'handover')): ?>
        <!-- Handover document designer (discussion #56) -->
        <div class="tab-content<?php echo $activeTabId === 'handover' ? ' active' : ''; ?>" id="handover-tab" data-capability="<?php echo Cap::ASSETS_HANDOVER; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.handover_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description"><?php echo t('asset-management.settings.handover_intro'); ?></p>

                    <div class="ho-toolbar">
                        <select id="hoTemplateSelect" class="ho-select"></select>
                        <button type="button" class="btn btn-secondary" id="hoNewBtn"><?php echo htmlspecialchars(t('asset-management.settings.handover_new')); ?></button>
                        <button type="button" class="btn btn-secondary" id="hoDefaultBtn"><?php echo htmlspecialchars(t('asset-management.settings.handover_make_default')); ?></button>
                        <button type="button" class="btn btn-secondary danger" id="hoDeleteBtn"><?php echo htmlspecialchars(t('asset-management.settings.handover_delete')); ?></button>
                        <span class="ho-spacer"></span>
                        <button type="button" class="btn btn-primary" id="hoSaveBtn"><?php echo htmlspecialchars(t('asset-management.settings.handover_save')); ?></button>
                    </div>

                    <div class="ho-grid">
                        <div class="ho-blocks">
                            <label class="ho-field">
                                <span class="ho-field-label"><?php echo htmlspecialchars(t('asset-management.settings.handover_name')); ?></span>
                                <input type="text" id="hoName" maxlength="120">
                            </label>

                            <div class="ho-merge">
                                <div class="ho-merge-title"><?php echo htmlspecialchars(t('asset-management.settings.handover_merge_title')); ?></div>
                                <div class="ho-merge-hint"><?php echo htmlspecialchars(t('asset-management.settings.handover_merge_hint')); ?></div>
                                <div id="hoMergeCodes" class="ho-merge-codes"></div>
                            </div>

                            <div id="hoBlockList"></div>
                        </div>

                        <div class="ho-preview-wrap">
                            <div class="ho-preview-head"><?php echo htmlspecialchars(t('asset-management.settings.handover_preview')); ?></div>
                            <div class="ho-preview hb-doc" id="hoPreview"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'warranty')): ?>
        <!-- Expiry alerts tab: warranty AND lease, two blocks, one tab -->
        <div class="tab-content<?php echo $activeTabId === 'warranty' ? ' active' : ''; ?>" id="warranty-tab" data-capability="<?php echo Cap::ASSETS_WARRANTY; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.warranty_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo t('asset-management.settings.warranty_intro'); ?>
                    </p>
                    <form id="warrantyForm" onsubmit="saveWarrantySettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="warrantySurface"><?php echo htmlspecialchars(t('asset-management.settings.warranty_show_in')); ?></label>
                            <select class="form-input" id="warrantySurface" style="max-width: 340px;">
                                <option value="off"><?php echo htmlspecialchars(t('asset-management.settings.warranty_off')); ?></option>
                                <option value="dashboard"><?php echo htmlspecialchars(t('asset-management.settings.warranty_dashboard_only')); ?></option>
                                <option value="calendar"><?php echo htmlspecialchars(t('asset-management.settings.warranty_calendar_only')); ?></option>
                                <option value="both"><?php echo htmlspecialchars(t('asset-management.settings.warranty_both')); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="warrantyDays"><?php echo htmlspecialchars(t('asset-management.settings.warranty_days_label')); ?></label>
                            <input type="number" class="form-input" id="warrantyDays" min="1" max="3650" value="30" style="max-width: 140px;">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.warranty_days_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="warrantySaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>

                    <?php /* Leased kit. The original lifecycle brief said
                             "warranty/lease expiries" and only warranty was
                             built; this is the other half, configured the same
                             way because it is the same question. */ ?>
                    <hr class="settings-divider" style="margin: 28px 0 22px; border: none; border-top: 1px solid var(--border-color, #e5e7eb);">
                    <h3 style="margin: 0 0 6px; font-size: 15px;"><?php echo htmlspecialchars(t('asset-management.settings.lease_heading')); ?></h3>
                    <p class="settings-description">
                        <?php echo t('asset-management.settings.lease_intro'); ?>
                    </p>
                    <form id="leaseForm" onsubmit="saveLeaseSettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="leaseSurface"><?php echo htmlspecialchars(t('asset-management.settings.lease_show_in')); ?></label>
                            <select class="form-input" id="leaseSurface" style="max-width: 340px;">
                                <option value="off"><?php echo htmlspecialchars(t('asset-management.settings.warranty_off')); ?></option>
                                <option value="dashboard"><?php echo htmlspecialchars(t('asset-management.settings.warranty_dashboard_only')); ?></option>
                                <option value="calendar"><?php echo htmlspecialchars(t('asset-management.settings.warranty_calendar_only')); ?></option>
                                <option value="both"><?php echo htmlspecialchars(t('asset-management.settings.warranty_both')); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="leaseDays"><?php echo htmlspecialchars(t('asset-management.settings.lease_days_label')); ?></label>
                            <input type="number" class="form-input" id="leaseDays" min="1" max="3650" value="60" style="max-width: 140px;">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.lease_days_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="leaseSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'vcenter')): ?>
        <!-- vCenter Tab -->
        <div class="tab-content<?php echo $activeTabId === 'vcenter' ? ' active' : ''; ?>" id="vcenter-tab" data-capability="<?php echo Cap::ASSETS_VCENTER; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                        <line x1="6" y1="6" x2="6.01" y2="6"></line>
                        <line x1="6" y1="18" x2="6.01" y2="18"></line>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.vcenter_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.vcenter_intro')); ?>
                    </p>
                    <form id="vcenterForm" onsubmit="saveVcenterSettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="vcenterServer"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_server')); ?></label>
                            <input type="text" class="form-input" id="vcenterServer" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.vcenter_server_placeholder')); ?>">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_server_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="vcenterUser"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_user')); ?></label>
                            <input type="text" class="form-input" id="vcenterUser" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.vcenter_user_placeholder')); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="vcenterPassword"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_password')); ?></label>
                            <div class="password-wrapper">
                                <input type="password" class="form-input" id="vcenterPassword" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.enter_password')); ?>">
                                <button type="button" class="password-toggle" onclick="togglePassword()"><?php echo htmlspecialchars(t('asset-management.settings.show')); ?></button>
                            </div>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.password_keep_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'asset-tags')): ?>
        <!-- Asset Tags Tab -->
        <div class="tab-content<?php echo $activeTabId === 'asset-tags' ? ' active' : ''; ?>" id="asset-tags-tab" data-capability="<?php echo Cap::ASSETS_TAGS; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                        <line x1="7" y1="7" x2="7.01" y2="7"></line>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.asset_tags_intro')); ?>
                    </p>

                    <form id="assetTagsForm" onsubmit="saveAssetTagsSettings(event); return false;">
                        <div class="form-group">
                            <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="tagAutogenEnabled" onchange="updateTagPreview()">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_enable_label')); ?></span>
                            </label>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_enable_hint')); ?></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="tagFormat"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_format_label')); ?></label>
                            <input type="text" class="form-input" id="tagFormat" maxlength="64" value="AST-{#####}" autocomplete="off" spellcheck="false" oninput="updateTagPreview()" style="font-family:monospace;">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_format_hint')); ?></div>
                        </div>

                        <div class="form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
                            <div class="form-group" style="flex:1; min-width:180px;">
                                <label class="form-label" for="tagNextNumber"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_next_label')); ?></label>
                                <input type="number" class="form-input" id="tagNextNumber" min="1" value="1" oninput="updateTagPreview()">
                                <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_next_hint')); ?></div>
                            </div>
                            <div class="form-group" style="flex:1; min-width:180px;">
                                <label class="form-label" for="tagScope"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_scope_label')); ?></label>
                                <select class="form-input" id="tagScope">
                                    <option value="per_company"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_scope_per_company')); ?></option>
                                    <option value="global"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_scope_global')); ?></option>
                                </select>
                                <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_tags_scope_hint')); ?></div>
                            </div>
                        </div>

                        <div class="card" style="background:var(--surface-2, #f3f6fa); border:1px solid var(--border, #e2e6ea); border-radius:8px; padding:14px 16px; margin:16px 0;">
                            <div style="font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted, #667); margin-bottom:4px;">
                                <?php echo htmlspecialchars(t('asset-management.settings.asset_tags_preview_label')); ?>
                            </div>
                            <div style="font-size:16px; font-family:monospace; font-weight:700; color:var(--accent, #0056b3);" id="tagFormatPreview">AST-00001</div>
                            <ul id="tagProblems" style="margin:8px 0 0; padding-left:18px; color:var(--danger, #c0392b); font-size:13px;" hidden></ul>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="tagSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (settingsTabVisible($visibleTabs, 'asset-labels')): ?>
        <!-- Asset Labels & QR Tab -->
        <div class="tab-content<?php echo $activeTabId === 'asset-labels' ? ' active' : ''; ?>" id="asset-labels-tab" data-capability="<?php echo Cap::ASSETS_LABELS; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <rect x="7" y="7" width="3" height="3"></rect>
                        <rect x="14" y="7" width="3" height="3"></rect>
                        <rect x="7" y="14" width="3" height="3"></rect>
                        <path d="M14 14h3v3h-3z"></path>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.asset_labels_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.asset_labels_intro')); ?>
                    </p>
                    <form id="assetLabelsForm" onsubmit="saveAssetLabelSettings(event); return false;" enctype="multipart/form-data">
                        <div class="asset-labels-layout" style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 20px;">
                            <!-- Left Column -->
                            <div class="left-col" style="flex: 1; min-width: 320px; display: flex; flex-direction: column; gap: 16px;">
                                <div class="form-group">
                                    <label class="form-label" for="lblTitle"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_title_label')); ?></label>
                                    <input type="text" class="form-input" id="lblTitle" maxlength="100" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.asset_label_title_ph')); ?>" oninput="updateLabelLivePreview()">
                                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_title_hint')); ?></div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="lblFooter"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_footer_label')); ?></label>
                                    <input type="text" class="form-input" id="lblFooter" maxlength="100" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.asset_label_footer_ph')); ?>" oninput="updateLabelLivePreview()">
                                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_footer_hint')); ?></div>
                                </div>
                                
                                <!-- QR Logo Section directly in Left Column -->
                                <div style="border-top: 1px solid var(--border, #e2e6ea); padding-top: 16px;">
                                    <h3 style="font-size:14px; font-weight:600; margin:0 0 10px 0;"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_logo_heading')); ?></h3>
                                    <div class="form-group">
                                        <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                                            <label class="toggle-switch">
                                                <input type="checkbox" id="lblLogoEnabled" onchange="updateLabelLivePreview()">
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span><?php echo htmlspecialchars(t('asset-management.settings.asset_label_logo_enabled_label')); ?></span>
                                        </label>
                                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_logo_enabled_hint')); ?></div>
                                    </div>
                                    <div class="form-group" id="logoUploadRow" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-top:10px;">
                                        <div>
                                            <input type="file" id="lblLogoFile" accept=".png,.jpg,.jpeg,image/png,image/jpeg" style="display:none;" onchange="handleLabelLogoSelect(event)">
                                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('lblLogoFile').click()">
                                                <?php echo htmlspecialchars(t('asset-management.settings.asset_label_logo_upload_btn')); ?>
                                            </button>
                                            <button type="button" class="btn btn-secondary delete" id="lblRemoveLogoBtn" style="display:none; margin-left:8px;" onclick="removeLabelLogo()">
                                                <?php echo htmlspecialchars(t('asset-management.settings.asset_label_logo_remove_btn')); ?>
                                            </button>
                                        </div>
                                        <div id="lblLogoStatusText" style="font-size:12px; color:var(--text-muted, #778);"></div>
                                        <div class="form-hint" style="width:100%; margin-top:6px;"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_logo_restrictions_hint', 'Logo format: PNG or JPG only, max 2 MB. Square, bold, or high-contrast logos work best. Scaled to 22% in the center of the QR code with Error Correction Level H (30% redundancy) to ensure reliable scanning.')); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="right-col" style="flex: 1; min-width: 320px; display: flex; flex-direction: column; gap: 16px;">
                                <div class="form-group">
                                    <label class="form-label" style="font-weight:600;"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_fields_label')); ?></label>
                                    <div class="form-hint" style="margin-bottom:8px;"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_fields_hint')); ?></div>
                                    
                                    <div style="margin: 8px 0 12px 0;">
                                        <label class="form-label" style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
                                            <label class="toggle-switch">
                                                <input type="checkbox" id="lblShowFieldLabels" onchange="updateLabelLivePreview()">
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span style="font-size:13px;"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_show_labels_label', 'Include field labels on printed tag')); ?></span>
                                        </label>
                                        <div class="form-hint" style="margin-left: 48px;"><?php echo htmlspecialchars(t('asset-management.settings.asset_label_show_labels_hint', 'When enabled, prints field names before values (e.g. "Hostname: LON-01"). When disabled, prints only values for maximum space efficiency.')); ?></div>
                                    </div>
                                    
                                    <div id="lblSelectedFieldsContainer" style="display:flex; flex-direction:column; gap:6px; margin-bottom:10px;"></div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <select class="form-select" id="lblAvailableFieldsSelect" style="flex:1;"></select>
                                        <button type="button" class="btn btn-secondary" id="lblAddFieldBtn" onclick="addSelectedLabelField()">+ <?php echo htmlspecialchars(t('asset-management.settings.asset_label_add_field')); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Live Physical Label Preview Card -->
                        <div class="card" style="background:var(--surface-2, #f3f6fa); border:1px solid var(--border, #e2e6ea); border-radius:8px; padding:16px; margin:20px 0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                                <div style="font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted, #667);">
                                    <?php echo htmlspecialchars(t('asset-management.settings.asset_label_preview_heading', 'Live physical label previews (100% physical scale — all 4 sheet sizes)')); ?>
                                </div>
                                <div style="font-size:11px; color:var(--text-muted, #778); font-style:italic;">
                                    Shown at actual 1:1 physical print dimensions (mm). Verify that your selected fields fit comfortably without excessive truncation.
                                </div>
                            </div>
                            
                            <div style="display:flex; gap:20px; align-items:flex-start; overflow-x:auto; padding:12px 6px 18px 6px;">
                                <!-- 65 per sheet: 38.1 x 21.2 mm -->
                                <div style="flex:0 0 auto;">
                                    <div style="font-size:11px; font-weight:600; color:#556; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                        <span>65 per sheet</span>
                                        <span style="font-size:10px; color:#889; font-weight:normal;">(38.1 × 21.2 mm)</span>
                                    </div>
                                    <div class="label-preview-item" data-sheet="65" style="width:38.1mm; height:21.2mm; padding:1.2mm 1.5mm; background:#fff; border:1px dashed #bbb; border-radius:2px; box-shadow:0 2px 5px rgba(0,0,0,0.06); display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; box-sizing:border-box;">
                                        <div class="prevHeader" style="text-align:right; font-size:5pt; font-weight:600; color:#444; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:0.3mm; display:none;"></div>
                                        <div class="label-body" style="display:flex; align-items:center; gap:1.5mm; flex:1; min-height:0;">
                                            <div class="prevQrBox" style="position:relative; flex:0 0 auto; width:16mm; height:16mm; background:#fff;"></div>
                                            <div class="prevTextContainer" style="min-width:0; flex:1; display:flex; flex-direction:column; justify-content:center; overflow:hidden;">
                                                <div class="prevTag" style="font-weight:700; font-size:6.4pt; color:#000; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">AST-00042</div>
                                                <div class="prevSubs" style="font-size:4.8pt; color:#333; line-height:1.15; overflow:hidden;"></div>
                                            </div>
                                        </div>
                                        <div class="prevFooter" style="text-align:right; font-size:4.5pt; color:#555; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:0.3mm; display:none;"></div>
                                    </div>
                                </div>

                                <!-- 40 per sheet: 45.7 x 25.4 mm (Standard) -->
                                <div style="flex:0 0 auto;">
                                    <div style="font-size:11px; font-weight:600; color:#556; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                        <span>40 per sheet</span>
                                        <span style="font-size:10px; color:#889; font-weight:normal;">(45.7 × 25.4 mm)</span>
                                        <span style="font-size:9px; background:#e8f5e9; color:#2e7d32; padding:1px 4px; border-radius:3px;">Standard</span>
                                    </div>
                                    <div class="label-preview-item" data-sheet="40" style="width:45.7mm; height:25.4mm; padding:1.2mm 1.5mm; background:#fff; border:1px dashed #bbb; border-radius:2px; box-shadow:0 2px 5px rgba(0,0,0,0.06); display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; box-sizing:border-box;">
                                        <div class="prevHeader" style="text-align:right; font-size:6.0pt; font-weight:600; color:#444; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:0.4mm; display:none;"></div>
                                        <div class="label-body" style="display:flex; align-items:center; gap:1.5mm; flex:1; min-height:0;">
                                            <div class="prevQrBox" style="position:relative; flex:0 0 auto; width:19mm; height:19mm; background:#fff;"></div>
                                            <div class="prevTextContainer" style="min-width:0; flex:1; display:flex; flex-direction:column; justify-content:center; overflow:hidden;">
                                                <div class="prevTag" style="font-weight:700; font-size:7.6pt; color:#000; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">AST-00042</div>
                                                <div class="prevSubs" style="font-size:5.2pt; color:#333; line-height:1.15; overflow:hidden;"></div>
                                            </div>
                                        </div>
                                        <div class="prevFooter" style="text-align:right; font-size:5.3pt; color:#555; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:0.4mm; display:none;"></div>
                                    </div>
                                </div>

                                <!-- 24 per sheet: 63.5 x 33.9 mm -->
                                <div style="flex:0 0 auto;">
                                    <div style="font-size:11px; font-weight:600; color:#556; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                        <span>24 per sheet</span>
                                        <span style="font-size:10px; color:#889; font-weight:normal;">(63.5 × 33.9 mm)</span>
                                    </div>
                                    <div class="label-preview-item" data-sheet="24" style="width:63.5mm; height:33.9mm; padding:1.2mm 1.5mm; background:#fff; border:1px dashed #bbb; border-radius:2px; box-shadow:0 2px 5px rgba(0,0,0,0.06); display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; box-sizing:border-box;">
                                        <div class="prevHeader" style="text-align:right; font-size:7.5pt; font-weight:600; color:#444; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:0.5mm; display:none;"></div>
                                        <div class="label-body" style="display:flex; align-items:center; gap:1.5mm; flex:1; min-height:0;">
                                            <div class="prevQrBox" style="position:relative; flex:0 0 auto; width:25mm; height:25mm; background:#fff;"></div>
                                            <div class="prevTextContainer" style="min-width:0; flex:1; display:flex; flex-direction:column; justify-content:center; overflow:hidden;">
                                                <div class="prevTag" style="font-weight:700; font-size:10.0pt; color:#000; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">AST-00042</div>
                                                <div class="prevSubs" style="font-size:6.5pt; color:#333; line-height:1.15; overflow:hidden;"></div>
                                            </div>
                                        </div>
                                        <div class="prevFooter" style="text-align:right; font-size:7.0pt; color:#555; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:0.5mm; display:none;"></div>
                                    </div>
                                </div>

                                <!-- 12 per sheet: 63.5 x 72.0 mm -->
                                <div style="flex:0 0 auto;">
                                    <div style="font-size:11px; font-weight:600; color:#556; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                        <span>12 per sheet</span>
                                        <span style="font-size:10px; color:#889; font-weight:normal;">(63.5 × 72.0 mm)</span>
                                    </div>
                                    <div class="label-preview-item" data-sheet="12" style="width:63.5mm; height:72.0mm; padding:1.2mm 1.5mm; background:#fff; border:1px dashed #bbb; border-radius:2px; box-shadow:0 2px 5px rgba(0,0,0,0.06); display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; box-sizing:border-box;">
                                        <div class="prevHeader" style="text-align:right; font-size:7.5pt; font-weight:600; color:#444; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:0.5mm; display:none;"></div>
                                        <div class="label-body is-portrait" style="display:flex; flex-direction:column; align-items:center; text-align:center; gap:1.2mm; flex:1; min-height:0;">
                                            <div class="prevQrBox" style="position:relative; flex:0 0 auto; width:38mm; height:38mm; background:#fff; margin:0 auto;"></div>
                                            <div class="prevTextContainer" style="width:100%; min-width:0; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; overflow:hidden;">
                                                <div class="prevTag" style="font-weight:700; font-size:13.0pt; color:#000; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%; text-align:center;">AST-00042</div>
                                                <div class="prevSubs" style="font-size:8.5pt; color:#333; line-height:1.15; overflow:hidden;"></div>
                                            </div>
                                        </div>
                                        <div class="prevFooter" style="text-align:right; font-size:7.0pt; color:#555; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:0.5mm; display:none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="labelSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (settingsTabVisible($visibleTabs, 'reconciliation')): ?>
        <!-- Discovery & Reconciliation Tab -->
        <div class="tab-content<?php echo $activeTabId === 'reconciliation' ? ' active' : ''; ?>" id="reconciliation-tab" data-capability="<?php echo Cap::ASSETS_RECONCILIATION; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        <path d="M11 8v6M8 11h6"></path>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.reconciliation_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.reconciliation_intro')); ?>
                    </p>
                    <form id="reconciliationForm" onsubmit="saveReconciliationSettings(event); return false;">
                        <div class="form-group">
                            <label class="form-label" for="reconIgnoredSerials"><?php echo htmlspecialchars(t('asset-management.settings.ignored_serials_label')); ?></label>
                            <textarea class="form-input" id="reconIgnoredSerials" rows="6" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.ignored_serials_placeholder')); ?>" style="font-family: monospace; font-size: 12px;"></textarea>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.ignored_serials_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="reconSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (settingsTabVisible($visibleTabs, 'intune')): ?>
        <!-- InTune Tab -->
        <div class="tab-content<?php echo $activeTabId === 'proxmox' ? ' active' : ''; ?>" id="proxmox-tab" data-capability="<?php echo Cap::ASSETS_PROXMOX; ?>">
            <div class="settings-section">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;"><?php echo htmlspecialchars(t('asset-management.proxmox.title')); ?></h3>
                        <p style="color:var(--text-muted, #666); margin:4px 0 0;"><?php echo htmlspecialchars(t('asset-management.proxmox.intro')); ?></p>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="proxmoxOpenForm()"><?php echo htmlspecialchars(t('asset-management.proxmox.add')); ?></button>
                </div>
                <div class="table-responsive" style="margin-top:12px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.col_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.col_address')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.col_last_sync')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.col_status')); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="proxmoxList"><tr><td colspan="5" style="text-align:center;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="settings-section" id="proxmoxVmsPanel" style="display:none; margin-top:16px;">
                <h3 id="proxmoxVmsTitle" style="margin-top:0;"></h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.vm_vmid')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.vm_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.vm_node')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.vm_status')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.vm_resources')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.proxmox.vm_network')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="proxmoxVmsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Proxmox server form -->
        <div class="modal" id="proxmoxModal">
            <div class="modal-content" style="max-width: 560px;">
                <div class="modal-header" id="proxmoxModalTitle"><?php echo htmlspecialchars(t('asset-management.proxmox.add')); ?></div>
                <div class="modal-body">
                    <form id="proxmoxForm" autocomplete="off" onsubmit="proxmoxSave(event)">
                        <input type="hidden" id="proxmoxId">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="proxmoxName"><?php echo htmlspecialchars(t('asset-management.proxmox.field_name')); ?> *</label>
                                <input type="text" id="proxmoxName" maxlength="100" required>
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="proxmoxHost"><?php echo htmlspecialchars(t('asset-management.proxmox.field_host')); ?> *</label>
                                <input type="text" id="proxmoxHost" placeholder="https://pve.example.com:8006" required>
                            </div>
                            <div class="form-group">
                                <label for="proxmoxUser"><?php echo htmlspecialchars(t('asset-management.proxmox.field_user')); ?> *</label>
                                <input type="text" id="proxmoxUser" placeholder="freeitsm@pve!itsm" required>
                                <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('asset-management.proxmox.token_help')); ?></small>
                            </div>
                            <div class="form-group">
                                <label for="proxmoxPassword"><?php echo htmlspecialchars(t('asset-management.proxmox.field_password')); ?></label>
                                <input type="password" id="proxmoxPassword" placeholder="••••••••">
                                <small style="color:var(--text-muted, #666);" id="proxmoxPasswordHelp"></small>
                            </div>
                            <div class="form-group">
                                <label for="proxmoxInterval"><?php echo htmlspecialchars(t('asset-management.proxmox.field_interval')); ?></label>
                                <input type="number" id="proxmoxInterval" min="5" max="10080" value="60">
                            </div>
                            <div class="form-group" style="display:flex; flex-direction:column; gap:8px; justify-content:flex-end;">
                                <label><input type="checkbox" id="proxmoxVerify" checked> <?php echo htmlspecialchars(t('asset-management.proxmox.field_verify')); ?></label>
                                <label><input type="checkbox" id="proxmoxActive" checked> <?php echo htmlspecialchars(t('asset-management.proxmox.field_active')); ?></label>
                            </div>
                        </div>
                        <p style="color:var(--text-muted, #666); font-size:12px; margin-top:10px;"><?php echo htmlspecialchars(t('asset-management.proxmox.perm_help')); ?></p>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="proxmoxCloseForm()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" form="proxmoxForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </div>
        </div>

        <div class="tab-content<?php echo $activeTabId === 'vcloud' ? ' active' : ''; ?>" id="vcloud-tab" data-capability="<?php echo Cap::ASSETS_VCLOUD; ?>">
            <div class="settings-section">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;"><?php echo htmlspecialchars(t('asset-management.vcloud.title')); ?></h3>
                        <p style="color:var(--text-muted, #666); margin:4px 0 0;"><?php echo htmlspecialchars(t('asset-management.vcloud.intro')); ?></p>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="vcloudOpenForm()"><?php echo htmlspecialchars(t('asset-management.vcloud.add')); ?></button>
                </div>
                <div class="table-responsive" style="margin-top:12px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.col_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.col_address')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.col_last_sync')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.col_status')); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="vcloudList"><tr><td colspan="5" style="text-align:center;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="settings-section" id="vcloudVmsPanel" style="display:none; margin-top:16px;">
                <h3 id="vcloudVmsTitle" style="margin-top:0;"></h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_vapp')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_vdc')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_status')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_resources')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_network')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="vcloudVmsBody"></tbody>
                    </table>
                </div>
                <h4 style="margin:18px 0 8px;"><?php echo htmlspecialchars(t('asset-management.vcloud.edges_title')); ?></h4>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_vdc')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.vm_status')); ?></th>
                                <th><?php echo htmlspecialchars(t('asset-management.vcloud.edge_uplinks')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="vcloudEdgesBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- vCloud Director server form -->
        <div class="modal" id="vcloudModal">
            <div class="modal-content" style="max-width: 560px;">
                <div class="modal-header" id="vcloudModalTitle"><?php echo htmlspecialchars(t('asset-management.vcloud.add')); ?></div>
                <div class="modal-body">
                    <form id="vcloudForm" autocomplete="off" onsubmit="vcloudSave(event)">
                        <input type="hidden" id="vcloudId">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="vcloudName"><?php echo htmlspecialchars(t('asset-management.vcloud.field_name')); ?> *</label>
                                <input type="text" id="vcloudName" maxlength="100" required>
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="vcloudHost"><?php echo htmlspecialchars(t('asset-management.vcloud.field_host')); ?> *</label>
                                <input type="text" id="vcloudHost" placeholder="https://vcd.example.com" required>
                            </div>
                            <div class="form-group">
                                <label for="vcloudUser"><?php echo htmlspecialchars(t('asset-management.vcloud.field_user')); ?> *</label>
                                <input type="text" id="vcloudUser" placeholder="admin" required>
                            </div>
                            <div class="form-group">
                                <label for="vcloudOrg"><?php echo htmlspecialchars(t('asset-management.vcloud.field_org')); ?> *</label>
                                <input type="text" id="vcloudOrg" placeholder="System">
                            </div>
                            <div class="form-group">
                                <label for="vcloudVersion"><?php echo htmlspecialchars(t('asset-management.vcloud.field_version')); ?></label>
                                <input type="text" id="vcloudVersion" value="38.0" placeholder="38.0">
                            </div>
                            <div class="form-group">
                                <label for="vcloudPassword"><?php echo htmlspecialchars(t('asset-management.vcloud.field_password')); ?></label>
                                <input type="password" id="vcloudPassword" placeholder="••••••••">
                                <small style="color:var(--text-muted, #666);" id="vcloudPasswordHelp"></small>
                            </div>
                            <div class="form-group">
                                <label for="vcloudInterval"><?php echo htmlspecialchars(t('asset-management.vcloud.field_interval')); ?></label>
                                <input type="number" id="vcloudInterval" min="5" max="10080" value="60">
                            </div>
                            <div class="form-group" style="display:flex; flex-direction:column; gap:8px; justify-content:flex-end;">
                                <label><input type="checkbox" id="vcloudVerify" checked> <?php echo htmlspecialchars(t('asset-management.vcloud.field_verify')); ?></label>
                                <label><input type="checkbox" id="vcloudActive" checked> <?php echo htmlspecialchars(t('asset-management.vcloud.field_active')); ?></label>
                            </div>
                        </div>
                        <p style="color:var(--text-muted, #666); font-size:12px; margin-top:10px;"><?php echo htmlspecialchars(t('asset-management.vcloud.perm_help')); ?></p>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="vcloudCloseForm()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" form="proxmoxForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </div>
        </div>

        <div class="tab-content<?php echo $activeTabId === 'intune' ? ' active' : ''; ?>" id="intune-tab" data-capability="<?php echo Cap::ASSETS_INTUNE; ?>">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="12" rx="2" ry="2"></rect>
                        <line x1="8" y1="20" x2="16" y2="20"></line>
                        <line x1="12" y1="16" x2="12" y2="20"></line>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.intune_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.intune_intro')); ?>
                    </p>
                    <form id="intuneForm" onsubmit="saveIntuneSettings(event)">
                        <?php if (!empty($activeCompanies)): ?>
                        <div class="form-group">
                            <label class="form-label" for="intuneCompanyId"><?php echo htmlspecialchars(t('asset-management.settings.intune_company_label')); ?></label>
                            <select class="form-input" id="intuneCompanyId">
                                <option value=""><?php echo htmlspecialchars(t('asset-management.settings.default_company')); ?></option>
                                <?php foreach ($activeCompanies as $c): ?>
                                    <?php if (empty($c['is_default'])): ?>
                                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_company_hint')); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label" for="intuneTenantId"><?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_id')); ?></label>
                            <input type="text" class="form-input" id="intuneTenantId" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_placeholder')); ?>">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneClientId"><?php echo htmlspecialchars(t('asset-management.settings.intune_client_id')); ?></label>
                            <input type="text" class="form-input" id="intuneClientId" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_client_id_placeholder')); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneClientSecret"><?php echo htmlspecialchars(t('asset-management.settings.intune_client_secret')); ?></label>
                            <div class="password-wrapper">
                                <input type="password" class="form-input" id="intuneClientSecret" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_secret_placeholder')); ?>">
                                <button type="button" class="password-toggle" onclick="toggleIntuneSecret()"><?php echo htmlspecialchars(t('asset-management.settings.show')); ?></button>
                            </div>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_secret_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="intuneSyncHostnames">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span><?php echo htmlspecialchars(t('asset-management.settings.intune_sync_hostnames_label')); ?></span>
                            </label>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_sync_hostnames_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneAppBatchSize"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_label')); ?></label>
                            <input type="number" class="form-input" id="intuneAppBatchSize" min="1" max="500" value="30" style="max-width: 140px;">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="intuneSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                            <button type="button" class="btn btn-secondary" id="intuneSyncBtn" onclick="startIntuneSync()"><?php echo htmlspecialchars(t('asset-management.settings.sync')); ?></button>
                            <span id="intuneLastSync" class="form-hint" style="margin-left: auto;"></span>
                        </div>
                        <div id="intuneSyncProgress" class="intune-progress" style="display: none;">
                            <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneProgressFill"></div></div>
                            <div class="intune-progress-meta" id="intuneProgressMeta"><?php echo htmlspecialchars(t('asset-management.settings.starting')); ?></div>
                        </div>
                    </form>

                    <div class="intune-software-section">
                        <h3 class="intune-subsection-title"><?php echo htmlspecialchars(t('asset-management.settings.software_sync_heading')); ?></h3>
                        <p class="settings-description">
                            <?php echo t('asset-management.settings.software_sync_intro'); ?>
                        </p>
                        <div class="form-actions" style="border-top: none; padding-top: 0;">
                            <button type="button" class="btn btn-secondary" id="intuneAppSyncBtn" onclick="startAppSync()"><?php echo htmlspecialchars(t('asset-management.settings.sync_software')); ?></button>
                            <span id="intuneAppEligible" class="form-hint" style="margin-left: auto;"></span>
                        </div>
                        <div id="intuneAppSyncProgress" class="intune-progress" style="display: none;">
                            <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneAppProgressFill"></div></div>
                            <div class="intune-progress-meta" id="intuneAppProgressMeta"><?php echo htmlspecialchars(t('asset-management.settings.starting')); ?></div>
                        </div>
                        <div class="intune-freshness-wrap" id="intuneFreshnessWrap" style="display: none;">
                            <div class="intune-freshness-title"><?php echo htmlspecialchars(t('asset-management.settings.inventory_freshness')); ?></div>
                            <div class="intune-freshness-canvas-wrap"><canvas id="intuneFreshnessChart"></canvas></div>
                        </div>
                        <div id="intuneAppJobsList" class="intune-jobs-list"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('asset-management.settings.add_item')); ?></div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">
                <div class="form-group">
                    <label for="itemName"><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></label>
                    <input type="text" id="itemName" required>
                </div>
                <div class="form-group">
                    <label for="itemDescription"><?php echo htmlspecialchars(t('asset-management.settings.col_description')); ?></label>
                    <textarea id="itemDescription"></textarea>
                </div>
                <div class="form-group">
                    <label for="itemOrder"><?php echo htmlspecialchars(t('asset-management.settings.display_order')); ?></label>
                    <input type="number" id="itemOrder" value="0" min="0">
                </div>
                <?php /* Icon picker (#1146). Asset TYPES only — the same modal
                         is reused for statuses, so the whole row is hidden for
                         those. Glyphs come from the shared library the CMDB's
                         classes already use; a printer must not look different
                         depending on which module you are in. */ ?>
                <div class="form-group" id="itemIconRow" style="display:none;">
                    <label><?php echo htmlspecialchars(t('asset-management.settings.type_icon')); ?></label>
                    <input type="hidden" id="itemIconId" value="">
                    <div class="ic-picked">
                        <span id="itemIconPreview" class="ic-preview"></span>
                        <span id="itemIconName" class="ic-name"></span>
                        <button type="button" class="btn btn-outline btn-sm" onclick="icClear()"><?php echo htmlspecialchars(t('asset-management.settings.type_icon_none')); ?></button>
                    </div>
                    <div class="ic-grid" id="itemIconGrid"></div>
                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.type_icon_hint')); ?></div>
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="itemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.status.active')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Location Add/Edit Modal -->
    <div class="modal" id="locationModal">
        <div class="modal-content">
            <div class="modal-header" id="locationModalTitle"><?php echo htmlspecialchars(t('asset-management.settings.add_location')); ?></div>
            <form id="locationForm">
                <input type="hidden" id="locationId">
                <div class="form-group">
                    <label for="locationName"><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></label>
                    <input type="text" id="locationName" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="locationParent"><?php echo htmlspecialchars(t('asset-management.settings.parent_location')); ?></label>
                    <select id="locationParent">
                        <option value=""><?php echo htmlspecialchars(t('asset-management.settings.none_top_level')); ?></option>
                    </select>
                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.parent_location_hint')); ?></div>
                </div>
                <?php /* 2.6.0. Only for someone who can reach every company (the
                         list endpoint says so as can_share); the save endpoint
                         enforces the same rule whatever this shows. */ ?>
                <div class="form-group" id="locationSharedGroup" hidden>
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="locationShared">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.settings.location_shared')); ?>
                    </label>
                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.location_shared_hint')); ?></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLocationModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php /* ── Custom fields: field editor ──────────────────────────────
             The "kind of information" row rewrites the rows beneath it, so a
             number offers a unit and a dropdown offers its choices — rather
             than showing every option for every type and letting people guess
             which apply. */ ?>
    <div class="modal" id="cfFieldModal">
        <div class="modal-content">
            <div class="modal-header" id="cfFieldModalTitle"><?php echo htmlspecialchars(t('asset-management.settings.cf_new_field')); ?></div>
            <form id="cfFieldForm">
                <input type="hidden" id="cfFieldId">
                <input type="hidden" id="cfFieldAttachToType" value="0">
                <div class="form-group">
                    <label for="cfFieldLabel"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_label')); ?></label>
                    <input type="text" id="cfFieldLabel" required autocomplete="off" oninput="cfCheckBuiltin()"
                           placeholder="<?php echo htmlspecialchars(t('asset-management.settings.cf_field_label_ph')); ?>">
                    <?php /* Every asset already has manufacturer, model, serial,
                             purchase date and so on as real columns. Somebody
                             adding "Make" to a Television type has no way to know
                             that, and the result is two questions that look like
                             one — which is exactly what happened the first time
                             this screen was used. Advisory, never a block: a
                             deliberate second field is a legitimate choice. */ ?>
                    <div class="form-hint cf-warn" id="cfBuiltinWarn" style="display:none;"></div>
                    <div class="form-hint" id="cfFieldKeyNote" style="display:none;"></div>
                </div>
                <div class="form-group">
                    <label for="cfFieldType"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_type')); ?></label>
                    <select id="cfFieldType" onchange="cfSyncFieldModal()"></select>
                    <?php /* Shown INSTEAD of letting somebody try and be refused —
                             a locked control that explains itself beats an error. */ ?>
                    <div class="form-hint cf-warn" id="cfTypeLocked" style="display:none;"></div>
                </div>

                <div class="form-group cf-forType cf-forType-number">
                    <label for="cfFieldUnit"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_unit')); ?></label>
                    <input type="text" id="cfFieldUnit" autocomplete="off"
                           placeholder="<?php echo htmlspecialchars(t('asset-management.settings.cf_field_unit_ph')); ?>">
                </div>
                <div class="form-group cf-forType cf-forType-number">
                    <label for="cfFieldDecimals"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_decimals')); ?></label>
                    <input type="number" id="cfFieldDecimals" min="0" max="4" value="0">
                </div>
                <div class="form-group cf-forType cf-forType-date">
                    <label for="cfFieldDateMode"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_date_mode')); ?></label>
                    <select id="cfFieldDateMode">
                        <option value="date"><?php echo htmlspecialchars(t('asset-management.settings.cf_date_date')); ?></option>
                        <option value="time"><?php echo htmlspecialchars(t('asset-management.settings.cf_date_time')); ?></option>
                        <option value="datetime"><?php echo htmlspecialchars(t('asset-management.settings.cf_date_datetime')); ?></option>
                    </select>
                </div>
                <div class="form-group cf-forType cf-forType-dropdown">
                    <label for="cfFieldOptions"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_options')); ?></label>
                    <textarea id="cfFieldOptions" rows="5"
                              placeholder="<?php echo htmlspecialchars(t('asset-management.settings.cf_field_options_ph')); ?>"></textarea>
                </div>
                <div class="form-group cf-forType cf-forType-ref">
                    <label for="cfFieldRefKind"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_ref_kind')); ?></label>
                    <select id="cfFieldRefKind">
                        <option value="user"><?php echo htmlspecialchars(t('asset-management.settings.cf_ref_user')); ?></option>
                        <option value="asset"><?php echo htmlspecialchars(t('asset-management.settings.cf_ref_asset')); ?></option>
                        <option value="cmdb_object"><?php echo htmlspecialchars(t('asset-management.settings.cf_ref_cmdb_object')); ?></option>
                    </select>
                </div>
                <div class="form-group cf-forType cf-forType-text">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="cfFieldMultiline">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.settings.cf_field_multiline')); ?>
                    </label>
                </div>

                <div class="form-group">
                    <label for="cfFieldHelp"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_help')); ?></label>
                    <input type="text" id="cfFieldHelp" autocomplete="off"
                           placeholder="<?php echo htmlspecialchars(t('asset-management.settings.cf_field_help_ph')); ?>">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="cfFieldInList">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.settings.cf_field_in_list')); ?>
                    </label>
                </div>
                <?php /* Feeds ⌘K and the global search. Off by default and
                         opt-in per field on purpose: quietly searching a
                         free-text notes field would make half the estate match
                         half the queries. */ ?>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="cfFieldSearchable">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.settings.cf_field_searchable')); ?>
                    </label>
                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.cf_field_searchable_hint')); ?></div>
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="cfFieldUnique">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.settings.cf_field_unique')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="cfCloseFieldModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom fields: set editor -->
    <div class="modal" id="cfSetModal">
        <div class="modal-content">
            <div class="modal-header" id="cfSetModalTitle"><?php echo htmlspecialchars(t('asset-management.settings.cf_new_set')); ?></div>
            <form id="cfSetForm">
                <input type="hidden" id="cfSetId">
                <div class="form-group">
                    <label for="cfSetName"><?php echo htmlspecialchars(t('asset-management.settings.cf_set_name')); ?></label>
                    <input type="text" id="cfSetName" required autocomplete="off"
                           placeholder="<?php echo htmlspecialchars(t('asset-management.settings.cf_set_name_ph')); ?>">
                </div>
                <div class="form-group">
                    <label for="cfSetDesc"><?php echo htmlspecialchars(t('asset-management.settings.cf_set_desc')); ?></label>
                    <textarea id="cfSetDesc" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('asset-management.settings.cf_set_fields')); ?></label>
                    <div id="cfSetFieldPicker" class="cf-picker"></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="cfCloseSetModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom fields: attach a set to the selected asset type -->
    <div class="modal" id="cfAttachSetModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('asset-management.settings.cf_type_add_set')); ?></div>
            <form id="cfAttachSetForm">
                <div class="form-group">
                    <div id="cfAttachSetPicker" class="cf-picker"></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="cfCloseAttachSet()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/assets/';
        const API_SETTINGS = '../../api/settings/';
        let currentTab = 'asset-types';
        let allItems = { 'asset-type': [], 'asset-status': [] };

        const endpoints = {
            'asset-type': {
                get: API_BASE + 'get_asset_types.php',
                save: API_BASE + 'save_asset_type.php',
                delete: API_BASE + 'delete_asset_type.php',
                setHidden: API_BASE + 'set_asset_type_hidden.php',
                hiddenParam: 'asset_type_id',
                key: 'asset_types',
                listId: 'asset-types-list',
                label: window.t('asset-management.settings.label_asset_type')
            },
            'asset-status': {
                get: API_BASE + 'get_asset_status_types.php',
                save: API_BASE + 'save_asset_status_type.php',
                delete: API_BASE + 'delete_asset_status_type.php',
                setHidden: API_BASE + 'set_asset_status_type_hidden.php',
                hiddenParam: 'asset_status_type_id',
                key: 'asset_status_types',
                listId: 'asset-statuses-list',
                label: window.t('asset-management.settings.label_asset_status')
            }
        };

        // Icons for the per-company "shared defaults" hide/show toggle.
        const ASSET_EYE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
        const ASSET_EYE_OFF_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

        document.addEventListener('DOMContentLoaded', function() {
            // ⚠️ Custom fields waits for the asset TYPES: its "by asset type"
            // picker is built from allItems['asset-type'], and racing it would
            // render an empty dropdown that looks like "you have no types".
            // Both wait for the asset TYPES: custom fields builds its per-type
            // picker from them, and import builds its default-type dropdown.
            loadItems('asset-type').then(() => { cfLoad(); impInit(); });
            loadItems('asset-status');
            loadLocations();
            loadSuppliers();
            loadIntegrationSettings();
            loadAssetTagSettings();
            loadAssetLabelSettings();

            // Hash / Query parameter tab deep-linking
            const wantHash = decodeURIComponent((location.hash || '').slice(1));
            const urlParams = new URLSearchParams(window.location.search);
            const wantTab = wantHash || urlParams.get('tab');
            if (wantTab && document.getElementById(wantTab + '-tab')) {
                switchTab(wantTab);
            }
        });

        // =========================================================================
        // Asset Labels & Physical Preview Configuration
        // =========================================================================
        let currentLabelLogoPath = '';
        let pendingLabelLogoFile = null;
        let removeLabelLogoFlag = false;
        let selectedLabelFields = ['asset_tag', 'hostname'];
        let availableLabelFieldsMap = { asset_tag: 'Asset Tag', hostname: 'Hostname', service_tag: 'Serial / Service Tag', model: 'Model' };

        async function loadAssetLabelSettings() {
            const titleEl = document.getElementById('lblTitle');
            if (!titleEl) return;
            try {
                const res = await fetch(API_BASE + 'get_asset_label_settings.php');
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('get_asset_label_settings returned non-JSON:', text);
                    return;
                }
                if (data.success) {
                    if (data.available_fields) {
                        availableLabelFieldsMap = data.available_fields;
                    }
                    if (data.print_fields) {
                        window.printLabelFieldsMap = data.print_fields;
                    }
                    if (data.settings) {
                        const s = data.settings;
                        titleEl.value = s.title || '';
                        document.getElementById('lblFooter').value = s.footer || '';
                        document.getElementById('lblLogoEnabled').checked = !!s.logo_enabled;
                        if (document.getElementById('lblShowFieldLabels')) {
                            document.getElementById('lblShowFieldLabels').checked = !!s.show_field_labels;
                        }
                        selectedLabelFields = Array.isArray(s.fields) && s.fields.length > 0 ? s.fields : ['asset_tag', 'hostname'];
                        if (!selectedLabelFields.includes('asset_tag')) {
                            selectedLabelFields.unshift('asset_tag');
                        }
                        currentLabelLogoPath = s.logo_path || '';
                        removeLabelLogoFlag = false;
                        pendingLabelLogoFile = null;
                    }
                }
            } catch (err) {
                console.error('Failed to load asset label settings', err);
            } finally {
                renderSelectedLabelFieldsList();
                updateLogoStatusDisplay();
                updateLabelLivePreview();
            }
        }

        function renderSelectedLabelFieldsList() {
            const container = document.getElementById('lblSelectedFieldsContainer');
            if (!container) return;
            container.innerHTML = '';

            selectedLabelFields.forEach((fk, idx) => {
                const labelName = availableLabelFieldsMap[fk] || fk;
                const row = document.createElement('div');
                row.className = 'lbl-field-item';
                row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; background:var(--surface,#fff); border:1px solid var(--border,#d5dbe1); border-radius:4px; padding:6px 10px; font-size:13px;';

                const left = document.createElement('div');
                left.style.cssText = 'display:flex; align-items:center; gap:8px;';
                const dragIcon = '<span style="color:var(--text-muted,#889); font-size:12px;">☰</span>';
                left.innerHTML = dragIcon + '<strong>' + escapeHtml(labelName) + '</strong>' + (fk === 'asset_tag' ? ' <span style="font-size:11px; color:#2e7d32; background:#e8f5e9; padding:1px 5px; border-radius:3px; font-weight:normal;">Required</span>' : '');

                const actions = document.createElement('div');
                actions.style.cssText = 'display:flex; align-items:center; gap:4px;';

                const upBtn = document.createElement('button');
                upBtn.type = 'button';
                upBtn.className = 'btn btn-secondary';
                upBtn.style.cssText = 'padding:2px 6px; font-size:11px;';
                upBtn.textContent = '↑';
                upBtn.disabled = (idx === 0);
                upBtn.onclick = () => moveSelectedLabelField(idx, -1);

                const dnBtn = document.createElement('button');
                dnBtn.type = 'button';
                dnBtn.className = 'btn btn-secondary';
                dnBtn.style.cssText = 'padding:2px 6px; font-size:11px;';
                dnBtn.textContent = '↓';
                dnBtn.disabled = (idx === selectedLabelFields.length - 1);
                dnBtn.onclick = () => moveSelectedLabelField(idx, 1);

                actions.appendChild(upBtn);
                actions.appendChild(dnBtn);

                if (fk !== 'asset_tag') {
                    const delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn btn-secondary delete';
                    delBtn.style.cssText = 'padding:2px 6px; font-size:11px; margin-left:4px;';
                    delBtn.textContent = '✕';
                    delBtn.onclick = () => removeSelectedLabelField(idx);
                    actions.appendChild(delBtn);
                }

                row.appendChild(left);
                row.appendChild(actions);
                container.appendChild(row);
            });

            // Populate Available Fields Dropdown
            const selectEl = document.getElementById('lblAvailableFieldsSelect');
            if (selectEl) {
                selectEl.innerHTML = '';
                let addedCount = 0;
                Object.keys(availableLabelFieldsMap).forEach(key => {
                    if (!selectedLabelFields.includes(key)) {
                        const opt = document.createElement('option');
                        opt.value = key;
                        opt.textContent = availableLabelFieldsMap[key];
                        selectEl.appendChild(opt);
                        addedCount++;
                    }
                });

                if (addedCount === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = window.t('asset-management.settings.no_more_fields');
                    opt.disabled = true;
                    opt.selected = true;
                    selectEl.appendChild(opt);
                }

                const btnAdd = document.getElementById('lblAddFieldBtn');
                if (btnAdd) {
                    btnAdd.disabled = (addedCount === 0);
                }
            }
        }

        function addSelectedLabelField() {
            const selectEl = document.getElementById('lblAvailableFieldsSelect');
            if (!selectEl || !selectEl.value) return;
            const key = selectEl.value;
            if (!selectedLabelFields.includes(key)) {
                selectedLabelFields.push(key);
                renderSelectedLabelFieldsList();
                updateLabelLivePreview();
            }
        }

        function moveSelectedLabelField(index, dir) {
            const targetIdx = index + dir;
            if (targetIdx < 0 || targetIdx >= selectedLabelFields.length) return;
            const temp = selectedLabelFields[index];
            selectedLabelFields[index] = selectedLabelFields[targetIdx];
            selectedLabelFields[targetIdx] = temp;
            renderSelectedLabelFieldsList();
            updateLabelLivePreview();
        }

        function removeSelectedLabelField(index) {
            const key = selectedLabelFields[index];
            if (key === 'asset_tag') return;
            selectedLabelFields.splice(index, 1);
            renderSelectedLabelFieldsList();
            updateLabelLivePreview();
        }

        function updateLogoStatusDisplay() {
            const statusEl = document.getElementById('lblLogoStatusText');
            const removeBtn = document.getElementById('lblRemoveLogoBtn');
            if (!statusEl) return;
            if (pendingLabelLogoFile) {
                statusEl.innerHTML = '<span style="color:#0277bd; font-weight:500;">Selected: ' + escapeHtml(pendingLabelLogoFile.name) + '</span>';
                if (removeBtn) removeBtn.style.display = 'inline-block';
            } else if (currentLabelLogoPath && !removeLabelLogoFlag) {
                const name = currentLabelLogoPath.split('/').pop();
                statusEl.innerHTML = '<span style="color:#2e7d32; font-weight:500;">✓ Logo configured</span> <span style="color:#888;">(' + escapeHtml(name) + ')</span>';
                if (removeBtn) removeBtn.style.display = 'inline-block';
            } else {
                statusEl.textContent = window.t('asset-management.labels.no_logo');
                if (removeBtn) removeBtn.style.display = 'none';
            }
        }

        function handleLabelLogoSelect(e) {
            const file = e.target.files?.[0];
            if (file) {
                if (file.size > 2 * 1024 * 1024) {
                    showToast(window.t('asset-management.labels.logo_too_large'), 'error');
                    e.target.value = '';
                    return;
                }
                pendingLabelLogoFile = file;
                removeLabelLogoFlag = false;
                document.getElementById('lblLogoEnabled').checked = true;
                updateLogoStatusDisplay();
                updateLabelLivePreview();
            }
        }

        function removeLabelLogo() {
            pendingLabelLogoFile = null;
            removeLabelLogoFlag = true;
            document.getElementById('lblLogoFile').value = '';
            updateLogoStatusDisplay();
            updateLabelLivePreview();
        }

        function updateLabelLivePreview() {
            const title = (document.getElementById('lblTitle')?.value || '').trim();
            const footer = (document.getElementById('lblFooter')?.value || '').trim();
            const logoEnabled = document.getElementById('lblLogoEnabled')?.checked;
            const showLabels = document.getElementById('lblShowFieldLabels')?.checked;

            const sheetDefs = {
                '65': { w: 38.1, h: 21.2, qr: 16, tagPt: 6.4, subDivisor: 3.2 },
                '40': { w: 45.7, h: 25.4, qr: 19, tagPt: 7.6, subDivisor: 3.2 },
                '24': { w: 63.5, h: 33.9, qr: 25, tagPt: 10.0, subDivisor: 3.2 },
                '12': { w: 63.5, h: 72.0, qr: 38, tagPt: 13.0, subDivisor: 3.2 }
            };


            const sampleCatalogue = {
                'asset_tag': 'AST-00042',
                'hostname': 'LON-LT-042',
                'service_tag': 'CN-0XYZ987',
                'model': 'Dell Latitude 5540',
                'manufacturer': 'Dell',
                'company': 'ACME Corp',
                'location': 'HQ - Floor 3',
                'asset_type': 'Laptop'
            };

            document.querySelectorAll('.label-preview-item').forEach(card => {
                const sheetKey = card.getAttribute('data-sheet') || '40';
                const def = sheetDefs[sheetKey] || sheetDefs['40'];
                const isPortrait = def.h > def.w;
                const indent = isPortrait ? '0' : ('calc(' + def.qr + 'mm + 1.5mm)');

                // Update Header at physical TOP of label
                const headerEl = card.querySelector('.prevHeader');
                if (headerEl) {
                    headerEl.textContent = title;
                    headerEl.style.display = title ? 'block' : 'none';
                    headerEl.style.marginLeft = indent;
                    headerEl.style.textAlign = isPortrait ? 'center' : 'left';
                }

                // Update Footer at physical BOTTOM of label
                const footerEl = card.querySelector('.prevFooter');
                if (footerEl) {
                    footerEl.textContent = footer;
                    footerEl.style.display = footer ? 'block' : 'none';
                    footerEl.style.marginLeft = indent;
                    footerEl.style.textAlign = isPortrait ? 'center' : 'left';
                }

                // Update Fields in strict chosen catalogue order mirroring labels.php
                const txtContainer = card.querySelector('.prevTextContainer');
                if (txtContainer) {
                    txtContainer.innerHTML = '';
                    const countFields = selectedLabelFields.length;
                    const subPt = Math.max(4.8, Math.min(8.5, def.qr / (def.subDivisor + (countFields * 0.35))));

                    selectedLabelFields.forEach(fk => {
                        if (fk === 'asset_tag') {
                            const tagDiv = document.createElement('div');
                            tagDiv.className = 'tag prevTag';
                            tagDiv.style.cssText = 'font-weight:700; font-size:' + def.tagPt + 'pt; color:#000; letter-spacing:0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' + (isPortrait ? ' width:100%; text-align:center;' : '');
                            tagDiv.textContent = 'AST-00042';
                            txtContainer.appendChild(tagDiv);
                        } else {
                            // The printed names come from the server, translated (PR #164).
                            const fieldTitle = (window.printLabelFieldsMap && window.printLabelFieldsMap[fk]) || availableLabelFieldsMap[fk] || fk;

                            let sampleVal = sampleCatalogue[fk];
                            if (!sampleVal) {
                                sampleVal = fk.indexOf('cf_') === 0 ? 'FAR-88092' : window.t('asset-management.labels.sample_value', { field: fieldTitle });
                            }

                            const displayText = showLabels ? (fieldTitle + ': ' + sampleVal) : sampleVal;
                            const subDiv = document.createElement('div');
                            subDiv.className = 'sub';
                            subDiv.style.cssText = 'font-size:' + subPt.toFixed(1) + 'pt; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.15; margin-top:0.3mm;' + (isPortrait ? ' width:100%; text-align:center;' : '');
                            subDiv.textContent = displayText;
                            txtContainer.appendChild(subDiv);
                        }
                    });
                }

                // Update QR with center logo if enabled
                const qrBox = card.querySelector('.prevQrBox');
                if (qrBox && typeof qrcode === 'function') {
                    qrBox.innerHTML = '';
                    const hasLogo = logoEnabled && ((currentLabelLogoPath && !removeLabelLogoFlag) || pendingLabelLogoFile);
                    const ec = hasLogo ? 'H' : 'M';
                    const qr = qrcode(0, ec);
                    qr.addData('https://example.com/a/preview');
                    qr.make();
                    qrBox.innerHTML = qr.createImgTag(2, 0);
                    const img = qrBox.querySelector('img');
                    if (img) {
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.display = 'block';
                    }

                    if (hasLogo) {
                        const overlay = document.createElement('div');
                        overlay.style.position = 'absolute';
                        overlay.style.top = '50%';
                        overlay.style.left = '50%';
                        overlay.style.transform = 'translate(-50%, -50%)';
                        overlay.style.width = '22%';
                        overlay.style.height = '22%';
                        overlay.style.background = '#ffffff';
                        overlay.style.padding = '1px';
                        overlay.style.boxSizing = 'border-box';
                        overlay.style.display = 'flex';
                        overlay.style.alignItems = 'center';
                        overlay.style.justifyContent = 'center';
                        overlay.style.borderRadius = '2px';

                        const logoImg = document.createElement('img');
                        if (pendingLabelLogoFile) {
                            logoImg.src = URL.createObjectURL(pendingLabelLogoFile);
                        } else if (currentLabelLogoPath) {
                            logoImg.src = '../../' + currentLabelLogoPath;
                        }
                        logoImg.style.maxWidth = '100%';
                        logoImg.style.maxHeight = '100%';
                        logoImg.style.objectFit = 'contain';
                        overlay.appendChild(logoImg);
                        qrBox.appendChild(overlay);
                    }
                }
            });
        }

        async function saveAssetLabelSettings(e) {
            if (e) e.preventDefault();
            const btn = document.getElementById('labelSaveBtn');
            if (btn) { btn.disabled = true; btn.textContent = window.t('asset-management.settings.saving'); }
            try {
                const formData = new FormData();
                formData.append('title', (document.getElementById('lblTitle')?.value || '').trim());
                formData.append('fields', JSON.stringify(selectedLabelFields));
                formData.append('footer', (document.getElementById('lblFooter')?.value || '').trim());
                formData.append('logo_enabled', document.getElementById('lblLogoEnabled')?.checked ? '1' : '0');
                formData.append('show_field_labels', document.getElementById('lblShowFieldLabels')?.checked ? '1' : '0');
                if (removeLabelLogoFlag) {
                    formData.append('remove_logo', '1');
                }
                if (pendingLabelLogoFile) {
                    formData.append('logo', pendingLabelLogoFile);
                }

                const res = await fetch(API_BASE + 'save_asset_label_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    showToast(window.t('asset-management.settings.save_settings_failed') + ': ' + text.substring(0, 100), 'error');
                    return;
                }
                if (data.success) {
                    showToast(window.t('asset-management.settings.asset_label_saved'), 'success');
                    if (data.settings) {
                        currentLabelLogoPath = data.settings.logo_path || '';
                        pendingLabelLogoFile = null;
                        removeLabelLogoFlag = false;
                        updateLogoStatusDisplay();
                        updateLabelLivePreview();
                    }
                } else {
                    showToast(data.error || window.t('asset-management.settings.save_settings_failed'), 'error');
                }
            } catch (err) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = window.t('asset-management.common.save'); }
            }
        }

        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            const targetPanel = document.getElementById(tab + '-tab');
            if (targetPanel) targetPanel.classList.add('active');
            if (tab === 'left-panel') loadSidebarMode();
            if (tab === 'asset-tags') updateLabelLivePreview();
            try {
                if (history.replaceState) {
                    history.replaceState(null, '', '?tab=' + encodeURIComponent(tab));
                }
            } catch (e) {}
        }

        // --- Left panel preference ------------------------------------
        // 'always' vs 'hover', stored per-analyst via user_preferences.
        // header.php reads the same key on every assets page and toggles
        // .sidebar-hover on .assets-container. Also editable under
        // System → Preferences.
        const SIDEBAR_MODE_KEY = 'asset_management_sidebar_mode';
        let sidebarModeLoaded = false;
        async function loadSidebarMode() {
            if (sidebarModeLoaded) return;
            sidebarModeLoaded = true;
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
                document.querySelectorAll('input[name="assetsSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="assetsSidebarMode"][value="always"]');
                if (first) first.checked = true;
            }
        }
        async function saveSidebarMode(value) {
            if (value !== 'always' && value !== 'hover') return;
            try {
                const r = await fetch('../../api/system/set_user_preference.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: SIDEBAR_MODE_KEY, value: value })
                });
                const d = await r.json();
                if (d.success) showToast(window.t('asset-management.toast.saved'), 'success');
            } catch (e) { /* no-op */ }
        }

        async function loadItems(type) {
            const ep = endpoints[type];
            try {
                // ?manage=1 → in a client company's context the endpoint also returns
                // `scoped` (this company's own + the shared defaults with hide state).
                const response = await fetch(ep.get + '?manage=1');
                const data = await response.json();
                if (data.success) {
                    // Only a company's OWN rows are editable by id; in the flat /
                    // Default view that's every row, in a client view it's scoped.own.
                    allItems[type] = (data.scoped && data.scoped.is_default === false)
                        ? data.scoped.own : data[ep.key];
                    renderItems(type, data);
                } else {
                    document.getElementById(ep.listId).innerHTML =
                        `<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.toast.error', { error: escapeHtml(data.error) })}</td></tr>`;
                }
            } catch (error) {
                console.error('Error loading ' + type + ':', error);
                document.getElementById(ep.listId).innerHTML =
                    `<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.settings.load_data_failed')}</td></tr>`;
            }
        }

        // The editable row for a company's-own / global-default item.
        function assetItemRow(type, item) {
            return `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.description || '-')}</td>
                    <td>${item.display_order}</td>
                    <td><span class="status-badge status-${item.is_active ? 'active' : 'inactive'}">${item.is_active ? window.t('asset-management.status.active') : window.t('asset-management.status.inactive')}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('${type}', ${item.id})" title="${window.t('asset-management.common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('${type}', ${item.id}, '${escapeHtml(item.name)}')" title="${window.t('asset-management.common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>`;
        }

        function renderItems(type, data) {
            const ep = endpoints[type];
            const tbody = document.getElementById(ep.listId);

            // Multi-company, inside a client company's context → the two-group
            // "this company's own + shared defaults (add/hide)" view.
            if (data.scoped && data.scoped.is_default === false) {
                renderItemsScoped(type, tbody, data.scoped);
                return;
            }

            // Otherwise: a flat list (single-company install, or the MSP/Default
            // context where you manage the shared defaults themselves).
            const items = data[ep.key] || [];
            if (items.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">${window.t('asset-management.settings.no_items')}</td></tr>`;
                return;
            }
            tbody.innerHTML = items.map(item => assetItemRow(type, item)).join('');
        }

        // Per-company add+hide view: the company's own items, then the shared
        // defaults it inherits, each with a Hide/Show toggle.
        function renderItemsScoped(type, tbody, scoped) {
            const groupRow = (label, hint) =>
                `<tr><td colspan="5" style="background:var(--surface-2, #f7f9fa);border-top:1px solid var(--border, #e3e8ea);font-size:12px;font-weight:600;color:var(--text-muted, #455a64);padding:10px;">${escapeHtml(label)}${hint ? ` <span style="font-weight:400;color:var(--text-faint, #90a4ae);">— ${escapeHtml(hint)}</span>` : ''}</td></tr>`;

            let html = '';
            html += groupRow(`${scoped.company.name}’s own`);
            html += scoped.own.length
                ? scoped.own.map(item => assetItemRow(type, item)).join('')
                : '<tr><td colspan="5" style="color:#aaa;font-style:italic;padding:10px;">None yet — use Add to create one just for this company.</td></tr>';

            html += groupRow('Shared defaults', `inherited by ${scoped.company.name}`);
            html += scoped.globals.map(g => {
                const dim = g.hidden ? 'opacity:0.5;' : '';
                const statusCell = g.hidden
                    ? '<span class="status-badge status-inactive">Hidden here</span>'
                    : `<span class="status-badge status-${g.is_active ? 'active' : 'inactive'}">${g.is_active ? window.t('asset-management.status.active') : window.t('asset-management.status.inactive')}</span>`;
                const toggle = g.hidden
                    ? `<button class="action-btn" onclick="toggleHidden('${type}', ${g.id}, false)" title="Hidden from this company — click to show">${ASSET_EYE_OFF_SVG}</button>`
                    : `<button class="action-btn" onclick="toggleHidden('${type}', ${g.id}, true)" title="Visible to this company — click to hide">${ASSET_EYE_SVG}</button>`;
                return `
                    <tr style="${dim}">
                        <td><strong>${escapeHtml(g.name)}</strong></td>
                        <td>${escapeHtml(g.description || '-')}</td>
                        <td>${g.display_order}</td>
                        <td>${statusCell}</td>
                        <td>${toggle}</td>
                    </tr>`;
            }).join('');

            tbody.innerHTML = html;
        }

        // Hide / show a shared default for the active company (add+hide model).
        async function toggleHidden(type, id, hidden) {
            const ep = endpoints[type];
            try {
                const body = { hidden: hidden };
                body[ep.hiddenParam] = id;
                const response = await fetch(ep.setHidden, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const data = await response.json();
                if (data.success) {
                    showToast(hidden ? 'Hidden from this company' : 'Shown for this company', 'success');
                    loadItems(type);
                } else {
                    showToast(data.error || 'Could not update', 'error');
                }
            } catch (error) {
                showToast('Could not update', 'error');
            }
        }

        function openAddModal(type) {
            const ep = endpoints[type];
            document.getElementById('modalTitle').textContent = window.t('asset-management.settings.add_kind', { kind: ep.label });
            document.getElementById('itemId').value = '';
            document.getElementById('itemType').value = type;
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            icSetup(type, null);
            document.getElementById('editModal').classList.add('active');
        }

        function editItem(type, id) {
            const ep = endpoints[type];
            const item = allItems[type].find(i => i.id == id);
            if (!item) return;

            document.getElementById('modalTitle').textContent = window.t('asset-management.settings.edit_kind', { kind: ep.label });
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemType').value = type;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemDescription').value = item.description || '';
            document.getElementById('itemOrder').value = item.display_order || 0;
            document.getElementById('itemActive').checked = item.is_active;
            icSetup(type, item.icon_id || null);
            document.getElementById('editModal').classList.add('active');
        }

        async function deleteItem(type, id, name) {
            const ep = endpoints[type];
            if (!(await showConfirm({ title: window.t('asset-management.common.delete'), message: window.t('asset-management.settings.delete_item_confirm', { name: name, kind: ep.label.toLowerCase() }), okLabel: window.t('asset-management.common.delete'), okClass: 'danger' }))) return;

            try {
                const response = await fetch(ep.delete, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.toast.deleted'), 'success');
                    loadItems(type);
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error deleting:', error);
                showToast(window.t('asset-management.settings.delete_item_failed'), 'error');
            }
        }

        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const type = document.getElementById('itemType').value;
            const ep = endpoints[type];
            const id = document.getElementById('itemId').value;

            const payload = {
                name: document.getElementById('itemName').value.trim(),
                description: document.getElementById('itemDescription').value.trim(),
                display_order: parseInt(document.getElementById('itemOrder').value) || 0,
                is_active: document.getElementById('itemActive').checked ? 1 : 0,
                // Asset types only; the same modal serves statuses, where the
                // row is hidden and this stays empty.
                icon_id: document.getElementById('itemIconId').value || null
            };
            if (id) payload.id = parseInt(id);

            try {
                const response = await fetch(ep.save, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    showToast(window.t('asset-management.toast.saved'), 'success');
                    loadItems(type);
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error saving:', error);
                showToast(window.t('asset-management.settings.save_item_failed'), 'error');
            }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) {
            modalMouseDownTarget = e.target;
        });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this && modalMouseDownTarget === this) closeModal();
        });

        // Asset tag numbering (PR #164). The preview is drawn by the server -
        // the same validateFormat() and renderer that save and generate use, so
        // what the preview accepts is what will be saved.
        async function loadAssetTagSettings() {
            if (!document.getElementById('tagFormat')) return;
            try {
                const res = await fetch(API_BASE + 'get_asset_tag_settings.php');
                const data = await res.json();
                if (data.success && data.settings) {
                    const s = data.settings;
                    document.getElementById('tagAutogenEnabled').checked = !!s.enabled;
                    document.getElementById('tagFormat').value = s.format || 'AST-{#####}';
                    document.getElementById('tagNextNumber').value = s.next_number || 1;
                    document.getElementById('tagNextNumber').min = s.next_number || 1;
                    document.getElementById('tagScope').value = s.scope || 'per_company';
                    showTagPreview(s.examples || [], []);
                }
            } catch (err) {
                console.error('Failed to load asset tag settings', err);
            }
        }

        function showTagPreview(examples, problems) {
            const preview = document.getElementById('tagFormatPreview');
            const list = document.getElementById('tagProblems');
            preview.textContent = examples.length ? examples.join('   ') : '-';
            list.replaceChildren(...problems.map(p => { const li = document.createElement('li'); li.textContent = p; return li; }));
            list.hidden = problems.length === 0;
        }

        let tagPreviewTimer = null, tagPreviewSeq = 0;
        function updateTagPreview() {
            clearTimeout(tagPreviewTimer);
            tagPreviewTimer = setTimeout(async () => {
                const seq = ++tagPreviewSeq;
                try {
                    const res = await fetch(API_BASE + 'asset_tag_preview.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            format: document.getElementById('tagFormat').value.trim(),
                            start: parseInt(document.getElementById('tagNextNumber').value, 10) || 1
                        })
                    });
                    const data = await res.json();
                    if (seq === tagPreviewSeq && data.success) showTagPreview(data.examples || [], data.problems || []);
                } catch (err) { /* the save will say what is wrong */ }
            }, 250);
        }

        async function saveAssetTagsSettings(e) {
            e.preventDefault();
            const btn = document.getElementById('tagSaveBtn');
            if (btn) { btn.disabled = true; btn.textContent = window.t('asset-management.settings.saving'); }
            try {
                const res = await fetch(API_BASE + 'save_asset_tag_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        enabled: document.getElementById('tagAutogenEnabled').checked,
                        format: document.getElementById('tagFormat').value.trim(),
                        scope: document.getElementById('tagScope').value,
                        next_number: parseInt(document.getElementById('tagNextNumber').value, 10) || 1
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.asset_tags_saved'), 'success');
                    if (data.settings) {
                        document.getElementById('tagNextNumber').value = data.settings.next_number;
                        document.getElementById('tagNextNumber').min = data.settings.next_number;
                        showTagPreview(data.settings.examples || [], []);
                    }
                } else {
                    if (data.problems) showTagPreview([], data.problems);
                    showToast(data.error || window.t('asset-management.settings.save_settings_failed'), 'error');
                }
            } catch (err) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = window.t('asset-management.common.save'); }
            }
        }
        // Integration settings (vCenter + InTune). Secret fields are left empty;
        // the placeholder tells the user one is already saved. The save endpoint
        // treats blank/asterisk values as "keep existing", so leaving them
        // alone preserves the stored secret.
        async function loadIntegrationSettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('vcenterServer').value = data.settings.vcenter_server || '';
                    document.getElementById('vcenterUser').value = data.settings.vcenter_user || '';
                    const vcPwField = document.getElementById('vcenterPassword');
                    vcPwField.value = '';
                    vcPwField.placeholder = data.settings.vcenter_password
                        ? window.t('asset-management.settings.password_saved_placeholder')
                        : window.t('asset-management.settings.enter_password');

                    document.getElementById('intuneTenantId').value = data.settings.intune_tenant_id || '';
                    document.getElementById('intuneClientId').value = data.settings.intune_client_id || '';
                    const intSecField = document.getElementById('intuneClientSecret');
                    intSecField.value = '';
                    intSecField.placeholder = data.settings.intune_client_secret
                        ? window.t('asset-management.settings.secret_saved_placeholder')
                        : window.t('asset-management.settings.intune_secret_placeholder');
                    // batch size: default to 30 if not stored
                    const batch = parseInt(data.settings.intune_app_batch_size, 10);
                    document.getElementById('intuneAppBatchSize').value = (batch > 0 ? batch : 30);
                    if (document.getElementById('intuneCompanyId')) {
                        document.getElementById('intuneCompanyId').value = data.settings.intune_company_id || '';
                    }
                    document.getElementById('intuneSyncHostnames').checked = data.settings.intune_sync_hostnames === '1';
                    if (document.getElementById('reconIgnoredSerials')) {
                        document.getElementById('reconIgnoredSerials').value = data.settings.asset_reconciliation_ignored_serials || '';
                    }

                    // Warranty alert settings
                    document.getElementById('warrantySurface').value = data.settings.asset_warranty_surface || 'dashboard';
                    const wDays = parseInt(data.settings.asset_warranty_days, 10);
                    document.getElementById('warrantyDays').value = (wDays > 0 ? wDays : 30);

                    // Lease alert settings. The default window is 60 days
                    // rather than warranty's 30: a warranty running out is
                    // something to note, whereas a lease ending is a date you
                    // have to have done something about beforehand.
                    document.getElementById('leaseSurface').value = data.settings.asset_lease_surface || 'dashboard';
                    const lDays = parseInt(data.settings.asset_lease_days, 10);
                    document.getElementById('leaseDays').value = (lDays > 0 ? lDays : 60);
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        // Save the lease alert settings. A sibling of saveWarrantySettings
        // rather than a shared function taking a prefix: two near-identical
        // twenty-line handlers read better than one handler with a parameter
        // deciding which of six element ids it is talking to.
        async function saveLeaseSettings(e) {
            e.preventDefault();
            const btn = document.getElementById('leaseSaveBtn');
            btn.disabled = true; btn.textContent = window.t('asset-management.settings.saving');
            try {
                const res = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: {
                        asset_lease_surface: document.getElementById('leaseSurface').value,
                        asset_lease_days: String(Math.max(1, Math.min(3650, parseInt(document.getElementById('leaseDays').value, 10) || 60)))
                    }})
                });
                const data = await res.json();
                if (data.success) {
                    // Resync the calendar so it immediately matches the new
                    // choice - the same endpoint the warranty block calls,
                    // because one pass writes both kinds of entry.
                    try { await fetch(API_BASE + 'sync_warranty_calendar.php', { method: 'POST' }); } catch (e) {}
                    showToast(window.t('asset-management.settings.lease_saved'), 'success');
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }
            btn.disabled = false; btn.textContent = window.t('asset-management.common.save');
        }
        async function saveWarrantySettings(e) {
            e.preventDefault();
            const btn = document.getElementById('warrantySaveBtn');
            btn.disabled = true; btn.textContent = window.t('asset-management.settings.saving');
            try {
                const res = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: {
                        asset_warranty_surface: document.getElementById('warrantySurface').value,
                        asset_warranty_days: String(Math.max(1, Math.min(3650, parseInt(document.getElementById('warrantyDays').value, 10) || 30)))
                    }})
                });
                const data = await res.json();
                if (data.success) {
                    // Resync the calendar so it immediately matches the new choice.
                    try { await fetch(API_BASE + 'sync_warranty_calendar.php', { method: 'POST' }); } catch (e) {}
                    showToast(window.t('asset-management.settings.warranty_saved'), 'success');
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }
            btn.disabled = false; btn.textContent = window.t('asset-management.common.save');
        }

        async function saveVcenterSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = window.t('asset-management.settings.saving');

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            vcenter_server: document.getElementById('vcenterServer').value.trim(),
                            vcenter_user: document.getElementById('vcenterUser').value.trim(),
                            vcenter_password: document.getElementById('vcenterPassword').value
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntegrationSettings();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = window.t('asset-management.common.save');
        }

        async function saveReconciliationSettings(e) {
            if (e) e.preventDefault();
            const btn = document.getElementById('reconSaveBtn');
            if (btn) btn.disabled = true;
            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            asset_reconciliation_ignored_serials: document.getElementById('reconIgnoredSerials').value.trim()
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.saved_success'), 'success');
                } else {
                    showToast(data.message || window.t('asset-management.settings.error_saving'), 'error');
                }
            } catch (error) {
                showToast(window.t('asset-management.settings.error_saving'), 'error');
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        async function saveIntuneSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('intuneSaveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = window.t('asset-management.settings.saving');

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            intune_tenant_id: document.getElementById('intuneTenantId').value.trim(),
                            intune_client_id: document.getElementById('intuneClientId').value.trim(),
                            intune_client_secret: document.getElementById('intuneClientSecret').value,
                            intune_app_batch_size: String(Math.max(1, Math.min(500, parseInt(document.getElementById('intuneAppBatchSize').value, 10) || 30))),
                            intune_company_id: document.getElementById('intuneCompanyId') ? document.getElementById('intuneCompanyId').value.trim() : '',
                            intune_sync_hostnames: document.getElementById('intuneSyncHostnames').checked ? '1' : '0'
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntegrationSettings();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = window.t('asset-management.common.save');
        }

        function togglePassword() {
            const input = document.getElementById('vcenterPassword');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = window.t('asset-management.settings.hide'); }
            else { input.type = 'password'; btn.textContent = window.t('asset-management.settings.show'); }
        }

        function toggleIntuneSecret() {
            const input = document.getElementById('intuneClientSecret');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = window.t('asset-management.settings.hide'); }
            else { input.type = 'password'; btn.textContent = window.t('asset-management.settings.show'); }
        }

        // InTune sync
        const API_INTUNE = '../../api/intune/';
        let intunePollTimer = null;

        async function startIntuneSync() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = true;
            btn.textContent = window.t('asset-management.settings.starting');
            showIntuneProgress(0, window.t('asset-management.settings.starting'), false);

            try {
                const response = await fetch(API_INTUNE + 'sync.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showIntuneProgress(0, window.t('asset-management.toast.error', { error: data.error }), true);
                    btn.disabled = false;
                    btn.textContent = window.t('asset-management.settings.sync');
                    return;
                }
                pollIntuneStatus(data.id);
            } catch (e) {
                showIntuneProgress(0, window.t('asset-management.settings.sync_start_error'), true);
                btn.disabled = false;
                btn.textContent = window.t('asset-management.settings.sync');
            }
        }

        function pollIntuneStatus(jobId) {
            clearTimeout(intunePollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'sync_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showIntuneProgress(0, window.t('asset-management.settings.status_unavailable'), true);
                        resetIntuneSyncButton();
                        return;
                    }
                    const job = data.job;
                    showIntuneProgress(job.percent, job.message || job.status, job.status === 'error');

                    if (job.status === 'running') {
                        intunePollTimer = setTimeout(tick, 1500);
                    } else {
                        resetIntuneSyncButton();
                        loadIntuneLastSync();
                    }
                } catch (e) {
                    showIntuneProgress(0, window.t('asset-management.settings.status_poll_error'), true);
                    resetIntuneSyncButton();
                }
            };
            tick();
        }

        function showIntuneProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneSyncProgress');
            const fill = document.getElementById('intuneProgressFill');
            const meta = document.getElementById('intuneProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetIntuneSyncButton() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = false;
            btn.textContent = window.t('asset-management.settings.sync');
        }

        async function loadIntuneLastSync() {
            try {
                const response = await fetch(API_INTUNE + 'sync_status.php');
                const data = await response.json();
                const last = document.getElementById('intuneLastSync');
                if (data.success && data.job) {
                    const job = data.job;
                    if (job.status === 'running') {
                        last.textContent = '';
                        pollIntuneStatus(job.id);
                        return;
                    }
                    const when = job.finished_datetime || job.started_datetime;
                    const date = when ? fmtDateTime(when) : '';
                    last.textContent = window.t('asset-management.settings.last_sync', { date: date, status: job.status });
                } else {
                    last.textContent = '';
                }
            } catch (e) {
                document.getElementById('intuneLastSync').textContent = '';
            }
        }

        // Pull last-sync info on first load
        document.addEventListener('DOMContentLoaded', loadIntuneLastSync);

        // ─── Software (app) sync ────────────────────────────────────────────
        let appSyncPollTimer = null;

        async function startAppSync() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = true;
            btn.textContent = window.t('asset-management.settings.starting');
            showAppSyncProgress(0, window.t('asset-management.settings.starting'), false);

            try {
                const response = await fetch(API_INTUNE + 'create_app_sync_job.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showAppSyncProgress(0, window.t('asset-management.toast.error', { error: data.error }), true);
                    resetAppSyncButton();
                    return;
                }
                const queuedMsg = data.reused ? window.t('asset-management.settings.resuming_job') : window.t('asset-management.settings.job_queued');
                showAppSyncProgress(0, window.t('asset-management.settings.job_for_assets', { msg: queuedMsg, count: data.asset_count }), false);
                pollAppSyncStatus(data.id);
            } catch (e) {
                showAppSyncProgress(0, window.t('asset-management.settings.app_sync_start_error'), true);
                resetAppSyncButton();
            }
        }

        function pollAppSyncStatus(jobId) {
            clearTimeout(appSyncPollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'app_sync_job_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showAppSyncProgress(0, window.t('asset-management.settings.status_unavailable'), true);
                        resetAppSyncButton();
                        return;
                    }
                    const job = data.job;
                    const r = job.rollup || {};
                    const summary = window.t('asset-management.settings.sync_summary_done', { processed: job.processed, total: job.total }) +
                                    (job.failed > 0 ? window.t('asset-management.settings.sync_summary_failed', { failed: job.failed }) : '') +
                                    ((r.obsolete || 0) > 0 ? window.t('asset-management.settings.sync_summary_obsolete', { obsolete: r.obsolete }) : '');
                    const message = job.message ? `${job.message} (${summary})` : summary;
                    showAppSyncProgress(job.percent, message, job.status === 'error');

                    if (job.status === 'pending' || job.status === 'running') {
                        appSyncPollTimer = setTimeout(tick, 2000);
                    } else {
                        resetAppSyncButton();
                        loadAppSyncJobs();
                        loadIntuneFreshness();
                    }
                } catch (e) {
                    showAppSyncProgress(0, window.t('asset-management.settings.status_poll_error'), true);
                    resetAppSyncButton();
                }
            };
            tick();
        }

        function showAppSyncProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneAppSyncProgress');
            const fill = document.getElementById('intuneAppProgressFill');
            const meta = document.getElementById('intuneAppProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetAppSyncButton() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = false;
            btn.textContent = window.t('asset-management.settings.sync_software');
        }

        async function loadAppSyncJobs() {
            try {
                const response = await fetch(API_INTUNE + 'list_app_sync_jobs.php');
                const data = await response.json();
                const list = document.getElementById('intuneAppJobsList');
                const eligible = document.getElementById('intuneAppEligible');

                if (!data.success) {
                    list.innerHTML = '';
                    eligible.textContent = '';
                    return;
                }

                eligible.textContent = data.eligible_assets > 0
                    ? window.t('asset-management.settings.eligible_for_sync', { count: data.eligible_assets })
                    : window.t('asset-management.settings.no_eligible_assets');

                if (!data.jobs || data.jobs.length === 0) {
                    list.innerHTML = `<div class="form-hint" style="margin-top: 12px;">${window.t('asset-management.settings.no_app_sync_jobs')}</div>`;
                    return;
                }

                // If the latest job is still mid-flight, resume polling
                const latest = data.jobs[0];
                if (latest && (latest.status === 'pending' || latest.status === 'running')) {
                    pollAppSyncStatus(latest.id);
                }

                list.innerHTML = `
                    <table class="intune-jobs-table">
                        <thead>
                            <tr><th>${window.t('asset-management.settings.job_col_job')}</th><th>${window.t('asset-management.field.status')}</th><th>${window.t('asset-management.settings.job_col_started')}</th><th>${window.t('asset-management.settings.job_col_finished')}</th><th>${window.t('asset-management.settings.job_col_result')}</th></tr>
                        </thead>
                        <tbody>
                            ${data.jobs.map(j => `
                                <tr>
                                    <td>#${j.id}</td>
                                    <td><span class="intune-job-status ${escapeHtml(j.status)}">${escapeHtml(j.status)}</span></td>
                                    <td>${j.started_datetime ? fmtDateTime(j.started_datetime) : '-'}</td>
                                    <td>${j.finished_datetime ? fmtDateTime(j.finished_datetime) : '-'}</td>
                                    <td>${j.processed}/${j.total}${j.failed > 0 ? ` ${window.t('asset-management.settings.failed_count', { failed: j.failed })}` : ''}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>`;
            } catch (e) {
                console.error('Error loading app sync jobs:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', loadAppSyncJobs);
        document.addEventListener('DOMContentLoaded', loadIntuneFreshness);

        // ─── Inventory freshness chart ──────────────────────────────────────
        let intuneFreshnessChart = null;

        async function loadIntuneFreshness() {
            try {
                const response = await fetch(API_INTUNE + 'app_sync_freshness.php');
                const data = await response.json();
                if (!data.success) return;

                const wrap = document.getElementById('intuneFreshnessWrap');
                const buckets = data.buckets || {};
                const labels = ['<1d', '1d', '2d', '3d', '4d', '5d', '6d', '7+d', 'never'];
                const values = labels.map(k => buckets[k] || 0);
                const total = values.reduce((s, n) => s + n, 0);

                // Hide chart entirely when there's nothing to show (e.g. no
                // Intune-eligible assets — no point rendering an empty chart).
                if (total === 0) {
                    wrap.style.display = 'none';
                    return;
                }
                wrap.style.display = '';

                // Fresh = green, ageing = amber gradient, never = red
                const colours = ['#107c10', '#3fa83f', '#76c043', '#a8c93a', '#d4c537',
                                 '#e6a82e', '#e07a26', '#d65420', '#d13438'];

                const ctx = document.getElementById('intuneFreshnessChart').getContext('2d');
                if (intuneFreshnessChart) {
                    intuneFreshnessChart.data.datasets[0].data = values;
                    intuneFreshnessChart.update();
                    return;
                }

                intuneFreshnessChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: window.t('asset-management.settings.assets_label'),
                            data: values,
                            backgroundColor: colours,
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => window.t('asset-management.settings.asset_count', { count: ctx.parsed.y }),
                                },
                            },
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                        },
                    },
                });
            } catch (e) {
                console.error('Error loading freshness chart:', e);
            }
        }

        // ─── Locations (arbitrary-depth tree) ───────────────────────────────
        let allLocations = [];
        let canShareLocations = false;   // 2.6.0 — from get_asset_locations.php
        const collapsedLocations = new Set();

        async function loadLocations() {
            const tree = document.getElementById('locations-tree');
            try {
                const res = await fetch(API_BASE + 'get_asset_locations.php');
                const data = await res.json();
                if (!data.success) {
                    tree.innerHTML = `<div class="loc-empty" style="color:#d13438;">${window.t('asset-management.toast.error', { error: escapeHtml(data.error) })}</div>`;
                    return;
                }
                allLocations = data.locations || [];
                canShareLocations = !!data.can_share;
                renderLocationTree();
            } catch (e) {
                console.error('Error loading locations:', e);
                tree.innerHTML = `<div class="loc-empty" style="color:#d13438;">${window.t('asset-management.settings.locations_load_failed')}</div>`;
            }
        }

        function locationChildren(parentId) {
            return allLocations.filter(l => l.parent_id === parentId);
        }

        function renderLocationTree() {
            const tree = document.getElementById('locations-tree');
            if (allLocations.length === 0) {
                tree.innerHTML = `<div class="loc-empty">${window.t('asset-management.settings.no_locations')}</div>`;
                return;
            }
            const roots = locationChildren(null);
            tree.innerHTML = '<ul>' + roots.map(r => renderLocationNode(r)).join('') + '</ul>';
        }

        function renderLocationNode(loc) {
            const kids = locationChildren(loc.id);
            const hasKids = kids.length > 0;
            const collapsed = collapsedLocations.has(loc.id);
            const caretClass = hasKids ? (collapsed ? 'collapsed' : '') : 'leaf';
            const count = hasKids ? `<span class="loc-count">${kids.length}</span>` : '';
            // 2.6.0. A shared location is every company's, so someone who
            // cannot reach them all may add inside it but not change or remove
            // it: the buttons would only lead to a refusal.
            const shared = loc.is_shared
                ? `<span class="loc-shared" title="${escapeHtml(window.t('asset-management.settings.location_shared_hint'))}">${escapeHtml(window.t('asset-management.settings.location_shared_badge'))}</span>` : '';
            const locked = loc.is_shared && !canShareLocations;
            const row = `
                <div class="loc-row">
                    <span class="loc-caret ${caretClass}" onclick="toggleLocation(${loc.id})">&#9662;</span>
                    <span class="loc-name">${escapeHtml(loc.name)}${shared}${count}</span>
                    <span class="loc-actions${locked ? ' loc-actions-locked' : ''}">
                        <button class="action-btn" title="${window.t('asset-management.settings.add_sublocation')}" onclick="openAddLocation(${loc.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <button class="action-btn" title="${window.t('asset-management.common.edit')}" onclick="editLocation(${loc.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" title="${window.t('asset-management.common.delete')}" onclick="deleteLocation(${loc.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </span>
                </div>`;
            const childrenHtml = hasKids
                ? `<div class="loc-children ${collapsed ? 'collapsed' : ''}"><ul>${kids.map(k => renderLocationNode(k)).join('')}</ul></div>`
                : '';
            return `<li class="loc-node">${row}${childrenHtml}</li>`;
        }

        function toggleLocation(id) {
            if (collapsedLocations.has(id)) collapsedLocations.delete(id);
            else collapsedLocations.add(id);
            renderLocationTree();
        }

        // Indented <option>s for the parent select. When editing, exclude the
        // node itself and its whole subtree (a node can't sit under itself).
        function buildParentOptions(excludeId) {
            const exclude = new Set();
            if (excludeId != null) {
                const stack = [excludeId];
                while (stack.length) {
                    const cur = stack.pop();
                    exclude.add(cur);
                    locationChildren(cur).forEach(c => stack.push(c.id));
                }
            }
            const opts = [`<option value="">${window.t('asset-management.settings.none_top_level')}</option>`];
            const walk = (parentId, depth) => {
                locationChildren(parentId).forEach(loc => {
                    if (!exclude.has(loc.id)) {
                        opts.push(`<option value="${loc.id}">${'   '.repeat(depth)}${escapeHtml(loc.name)}</option>`);
                        walk(loc.id, depth + 1);
                    }
                });
            };
            walk(null, 0);
            return opts.join('');
        }

        function openAddLocation(parentId) {
            document.getElementById('locationModalTitle').textContent = window.t('asset-management.settings.add_location');
            document.getElementById('locationId').value = '';
            document.getElementById('locationName').value = '';
            const sel = document.getElementById('locationParent');
            sel.innerHTML = buildParentOptions(null);
            sel.value = parentId != null ? String(parentId) : '';
            setLocationSharedField(false);
            document.getElementById('locationModal').classList.add('active');
            setTimeout(() => document.getElementById('locationName').focus(), 50);
        }

        // The Shared tick (2.6.0): shown only to someone who may use it, and
        // pre-set on edit — the save sends it every time, so leaving it unset on
        // a shared location would unshare it on an unrelated rename.
        function setLocationSharedField(isShared) {
            document.getElementById('locationSharedGroup').hidden = !canShareLocations;
            document.getElementById('locationShared').checked = !!isShared;
        }

        function editLocation(id) {
            const loc = allLocations.find(l => l.id === id);
            if (!loc) return;
            document.getElementById('locationModalTitle').textContent = window.t('asset-management.settings.edit_location');
            document.getElementById('locationId').value = loc.id;
            document.getElementById('locationName').value = loc.name;
            const sel = document.getElementById('locationParent');
            sel.innerHTML = buildParentOptions(loc.id);
            sel.value = loc.parent_id != null ? String(loc.parent_id) : '';
            setLocationSharedField(loc.is_shared);
            document.getElementById('locationModal').classList.add('active');
            setTimeout(() => document.getElementById('locationName').focus(), 50);
        }

        function closeLocationModal() {
            document.getElementById('locationModal').classList.remove('active');
        }

        async function deleteLocation(id) {
            const loc = allLocations.find(l => l.id === id);
            if (!loc) return;
            if (locationChildren(id).length > 0) {
                showToast(window.t('asset-management.settings.location_has_children'), 'error');
                return;
            }
            if (!(await showConfirm({ title: window.t('asset-management.common.delete'), message: window.t('asset-management.settings.delete_location_confirm', { name: loc.name }), okLabel: window.t('asset-management.common.delete'), okClass: 'danger' }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_asset_location.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) { showToast(window.t('asset-management.toast.deleted'), 'success'); loadLocations(); }
                else showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
            } catch (e) { showToast(window.t('asset-management.settings.delete_location_failed'), 'error'); }
        }

        document.getElementById('locationForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('locationId').value;
            const payload = {
                name: document.getElementById('locationName').value.trim(),
                parent_id: document.getElementById('locationParent').value || null
            };
            if (canShareLocations) payload.is_shared = document.getElementById('locationShared').checked;
            if (!payload.name) { showToast(window.t('asset-management.settings.name_required'), 'error'); return; }
            if (id) payload.id = parseInt(id);
            try {
                const res = await fetch(API_BASE + 'save_asset_location.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) { closeLocationModal(); showToast(window.t('asset-management.toast.saved'), 'success'); loadLocations(); }
                else showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
            } catch (e) { showToast(window.t('asset-management.settings.save_location_failed'), 'error'); }
        });

        let locationMouseDownTarget = null;
        document.getElementById('locationModal').addEventListener('mousedown', function(e) { locationMouseDownTarget = e.target; });
        document.getElementById('locationModal').addEventListener('click', function(e) {
            if (e.target === this && locationMouseDownTarget === this) closeLocationModal();
        });

        // ─── Suppliers (shared registry, flagged for assets) ────────────────
        let allSuppliers = [];

        async function loadSuppliers() {
            const tbody = document.getElementById('suppliers-list');
            try {
                const res = await fetch(API_BASE + 'search_suppliers.php');
                const data = await res.json();
                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="2" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.toast.error', { error: escapeHtml(data.error) })}</td></tr>`;
                    return;
                }
                allSuppliers = data.suppliers || [];
                renderSupplierList();
            } catch (e) {
                console.error('Error loading suppliers:', e);
                tbody.innerHTML = `<tr><td colspan="2" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.settings.suppliers_load_failed')}</td></tr>`;
            }
        }

        function renderSupplierList() {
            const tbody = document.getElementById('suppliers-list');
            const term = (document.getElementById('supplierSearch').value || '').trim().toLowerCase();
            const rows = allSuppliers.filter(s =>
                !term || (s.name || '').toLowerCase().includes(term) || (s.legal_name || '').toLowerCase().includes(term)
            );
            if (rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="2" style="text-align:center;padding:20px;color:#999;">${
                    allSuppliers.length === 0 ? window.t('asset-management.settings.no_suppliers') : window.t('asset-management.settings.no_supplier_match')}</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(s => {
                const alt = (s.trading_name && s.legal_name && s.trading_name !== s.legal_name)
                    ? ` <span style="color:#999;font-size:12px;">(${escapeHtml(s.legal_name)})</span>` : '';
                const inactive = !s.is_active ? ` <span class="status-badge status-inactive">${window.t('asset-management.status.inactive')}</span>` : '';
                return `
                    <tr>
                        <td><strong>${escapeHtml(s.name)}</strong>${alt}${inactive}</td>
                        <td>
                            <label class="toggle-label" style="margin:0;">
                                <span class="toggle-switch">
                                    <input type="checkbox" ${s.supplies_assets ? 'checked' : ''} onchange="toggleSupplierAssets(${s.id}, this.checked)">
                                    <span class="toggle-slider"></span>
                                </span>
                            </label>
                        </td>
                    </tr>`;
            }).join('');
        }

        async function toggleSupplierAssets(id, checked) {
            try {
                const res = await fetch(API_BASE + 'toggle_supplier_assets.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, supplies_assets: checked ? 1 : 0 })
                });
                const data = await res.json();
                if (data.success) {
                    const s = allSuppliers.find(x => x.id === id);
                    if (s) s.supplies_assets = checked ? 1 : 0;
                    showToast(checked ? window.t('asset-management.settings.supplier_enabled') : window.t('asset-management.settings.supplier_disabled'), 'success');
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                    renderSupplierList();
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.update_supplier_failed'), 'error');
                renderSupplierList();
            }
        }

        async function quickAddSupplier() {
            const input = document.getElementById('supplierQuickAdd');
            const name = input.value.trim();
            if (!name) { showToast(window.t('asset-management.settings.enter_supplier_name'), 'error'); return; }
            try {
                const res = await fetch(API_BASE + 'quick_add_supplier.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name })
                });
                const data = await res.json();
                if (data.success) {
                    input.value = '';
                    showToast(data.existing ? window.t('asset-management.settings.supplier_existed') : window.t('asset-management.settings.supplier_added'), 'success');
                    loadSuppliers();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.add_supplier_failed'), 'error');
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ════════════════════════════════════════════════════════════════
        //  Asset type icons (#1146)
        //
        //  Glyphs come from the SHARED library (assets/js/network-mapper-icons.js
        //  + the cmdb_icons table) that the CMDB's classes already use. A second
        //  library would drift, and a printer would look different depending on
        //  which module you were in.
        // ════════════════════════════════════════════════════════════════

        let icIconsById = null;   // id -> {key, label}

        /** Show the picker for asset types; hide it for anything else. */
        function icSetup(type, selectedId) {
            const row = document.getElementById('itemIconRow');
            if (!row) return;
            const isType = (type === 'asset-type');
            row.style.display = isType ? '' : 'none';
            document.getElementById('itemIconId').value = isType && selectedId ? selectedId : '';
            if (isType) {
                icRenderGrid();
                icShowSelected();
            }
        }

        /**
         * The library, rendered server-side into a global by this page.
         *
         * ⚠️ NOT fetched from api/cmdb/*: the glyphs are shared reference data,
         * and an assets administrator has no reason to hold CMDB module access.
         * Gating an asset-type icon behind the CMDB would be a permission bug
         * dressed up as reuse.
         */
        function icLoadIcons() {
            if (icIconsById) return icIconsById;
            icIconsById = {};
            (window.assetTypeIcons || []).forEach(i => {
                icIconsById[i.id] = { key: i.icon_key, label: i.label };
            });
            return icIconsById;
        }

        function icRenderGrid() {
            const grid = document.getElementById('itemIconGrid');
            if (!grid) return;
            const icons = icLoadIcons();
            const keys = Object.keys(icons);
            if (!keys.length) { grid.innerHTML = ''; return; }
            grid.innerHTML = keys.map(id => {
                const i = icons[id];
                // ⚠️ nmRenderIcon returns an <svg> string, not text — it is our
                // own markup from a fixed library, never user input.
                return `<button type="button" class="ic-tile" data-icon-id="${id}"
                                title="${escapeHtml(i.label)}" onclick="icPick(${id})">
                            ${window.nmRenderIcon ? window.nmRenderIcon(i.key, 22) : ''}
                        </button>`;
            }).join('');
            icShowSelected();
        }

        function icPick(id) {
            document.getElementById('itemIconId').value = id;
            icShowSelected();
        }

        function icClear() {
            document.getElementById('itemIconId').value = '';
            icShowSelected();
        }

        function icShowSelected() {
            const id = document.getElementById('itemIconId').value;
            const icons = icIconsById || {};
            const chosen = id ? icons[id] : null;
            const prev = document.getElementById('itemIconPreview');
            const name = document.getElementById('itemIconName');
            if (prev) prev.innerHTML = chosen && window.nmRenderIcon ? window.nmRenderIcon(chosen.key, 24) : '';
            if (name) name.textContent = chosen ? chosen.label : window.t('asset-management.settings.type_icon_none');
            document.querySelectorAll('.ic-tile').forEach(t => {
                t.classList.toggle('selected', t.dataset.iconId === String(id));
            });
        }

        /** An asset type's icon, for the settings list. '' when it has none. */
        function icFor(item, size) {
            if (!item || !item.icon_key || !window.nmRenderIcon) return '';
            return window.nmRenderIcon(item.icon_key, size || 16, 'class="ic-inline"');
        }

        // ════════════════════════════════════════════════════════════════
        //  Import  (docs/design/flexible-asset-fields.md §6)
        //
        //  Upload -> map -> say what identifies a row -> preview -> go.
        //  Nothing is written until the last step, and that step stays disabled
        //  until a preview has actually been looked at.
        // ════════════════════════════════════════════════════════════════

        let impState = { stored: null, headers: [], suggested: {}, fields: [], core: [],
                         matchKeys: [], sample: [], previewed: false };

        function impT(k, v) { return window.t('asset-management.settings.' + k, v); }
        function impPresent() { return !!document.getElementById('import-tab'); }

        async function impInit() {
            if (!impPresent()) return;
            try {
                const res  = await fetch(API_BASE + 'import_history.php?runs=1&unresolved=1');
                const data = await res.json();
                document.getElementById('impNotReady').style.display = data.schema_ready ? 'none' : '';
                document.getElementById('impBody').style.display     = data.schema_ready ? '' : 'none';
                if (!data.schema_ready) return;
                impRenderRuns(data.runs || []);
                impRenderHeld(data.unresolved || [], data.unresolved_count || 0);
            } catch (e) { /* the tab still works for a fresh import */ }
        }

        async function impUpload() {
            const input = document.getElementById('impFile');
            if (!input.files || !input.files.length) {
                showToast(impT('imp_pick_file'), 'error');
                return;
            }
            const fd = new FormData();
            fd.append('file', input.files[0]);
            try {
                const res  = await fetch(API_BASE + 'import_upload.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                impState = {
                    stored: data.stored_file, sourceName: data.source_name,
                    headers: data.headers || [], suggested: data.suggested || {},
                    fields: data.fields || [], core: data.core || [],
                    matchKeys: data.match_keys || [], sample: data.sample || [],
                    previewed: false
                };

                const info = document.getElementById('impFileInfo');
                let msg = impT('imp_file_read', { file: escapeHtml(data.source_name),
                                                  rows: data.row_count,
                                                  cols: (data.headers || []).length });
                // ⚠️ A cap must SURFACE. "Imported 5000 rows" from an 8000-row
                // file reads as complete success.
                if (data.truncated) {
                    msg += ' <strong>' + impT('imp_truncated', { max: data.max_rows }) + '</strong>';
                }
                info.innerHTML = msg;
                info.style.display = '';

                impRenderMapping();
                impRenderMatchKeys();
                impFillTypeSelect();
                document.getElementById('impWizard').style.display = '';
                document.getElementById('impResult').innerHTML = '';
                impSetPreviewed(false);
            } catch (e) {
                showToast(e.message || impT('imp_failed'), 'error');
            }
        }

        /** One row per source column, with its first few values so a wrong guess is obvious. */
        function impRenderMapping() {
            const tbody = document.getElementById('impMapList');
            tbody.innerHTML = impState.headers.map(h => {
                const sug = impState.suggested[h];
                const opts = [`<option value="">${impT('imp_ignore')}</option>`];
                opts.push(`<optgroup label="${impT('imp_group_core')}">`);
                impState.core.forEach(c => {
                    const on = sug && sug.target_kind === 'core' && sug.target_key === c;
                    opts.push(`<option value="core:${c}" ${on ? 'selected' : ''}>${escapeHtml(impCoreLabel(c))}</option>`);
                });
                opts.push('</optgroup>');
                if (impState.fields.length) {
                    opts.push(`<optgroup label="${impT('imp_group_fields')}">`);
                    impState.fields.forEach(f => {
                        const on = sug && sug.target_kind === 'field' && sug.target_key === f.field_key;
                        opts.push(`<option value="field:${escapeHtml(f.field_key)}" ${on ? 'selected' : ''}>${escapeHtml(f.label)}</option>`);
                    });
                    opts.push('</optgroup>');
                }
                const sample = impState.sample.map(r => r[h]).filter(v => v !== '' && v != null).slice(0, 3);
                return `
                    <tr>
                        <td><strong>${escapeHtml(h)}</strong></td>
                        <td class="cf-scope">${escapeHtml(sample.join(' · ')) || '&mdash;'}</td>
                        <td>
                            <select data-imp-source="${escapeHtml(h)}" onchange="impSyncIgnored()">
                                ${opts.join('')}
                            </select>
                        </td>
                    </tr>`;
            }).join('');
            impSyncIgnored();
        }

        function impCoreLabel(key) {
            const map = {
                hostname: 'asset-management.new.name',
                asset_type_id: 'asset-management.field.type',
                asset_status_id: 'asset-management.field.status',
                location_id: 'asset-management.field.location',
                manufacturer: 'asset-management.field.manufacturer',
                model: 'asset-management.field.model',
                service_tag: 'asset-management.detail.service_tag',
                supplier_id: 'asset-management.field.supplier',
                purchase_date: 'asset-management.field.purchase_date',
                purchase_cost: 'asset-management.field.purchase_cost',
                order_number: 'asset-management.field.order_number',
                warranty_expiry: 'asset-management.field.warranty_expiry'
            };
            return map[key] ? window.t(map[key]) : key;
        }

        /** Name the columns going nowhere. Silence here is how half an import vanishes. */
        function impSyncIgnored() {
            const ignored = Array.from(document.querySelectorAll('[data-imp-source]'))
                .filter(s => !s.value)
                .map(s => s.getAttribute('data-imp-source'));
            const box = document.getElementById('impIgnored');
            if (!ignored.length) { box.style.display = 'none'; return; }
            box.innerHTML = impT('imp_ignored', { n: ignored.length }) + ' ' +
                            ignored.map(escapeHtml).join(', ');
            box.style.display = '';
            impSetPreviewed(false);   // the mapping changed, so the preview is stale
        }

        function impRenderMatchKeys() {
            const box = document.getElementById('impMatchKeys');
            box.innerHTML = impState.matchKeys.map((k, i) => `
                <label class="cf-pick">
                    <input type="checkbox" value="${k}" ${i === 0 ? 'checked' : ''} onchange="impSetPreviewed(false)">
                    <span>${escapeHtml(impCoreLabel(k))}</span>
                    <span class="cf-pick-kind">${escapeHtml(k)}</span>
                </label>`).join('');
        }

        function impFillTypeSelect() {
            const sel = document.getElementById('impDefaultType');
            sel.innerHTML = `<option value="">${window.t('asset-management.common.none_option')}</option>` +
                (allItems['asset-type'] || []).map(t =>
                    `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
        }

        /**
         * 🔑 Go stays disabled until a preview has been run, and any change to
         * the mapping or the match keys disables it again. The preview is the
         * only thing between a mis-mapped column and hundreds of wrong records.
         */
        function impSetPreviewed(v) {
            impState.previewed = !!v;
            const btn  = document.getElementById('impGoBtn');
            const hint = document.getElementById('impGoHint');
            if (!btn) return;
            btn.disabled = !v;
            hint.style.display = v ? 'none' : '';
        }

        function impCollectMapping() {
            const mapping = {};
            document.querySelectorAll('[data-imp-source]').forEach(sel => {
                const src = sel.getAttribute('data-imp-source');
                if (!sel.value) { mapping[src] = null; return; }
                const [kind, key] = sel.value.split(':');
                mapping[src] = { target_kind: kind, target_key: key };
            });
            return mapping;
        }

        async function impRun(mode) {
            const keys = Array.from(document.querySelectorAll('#impMatchKeys input:checked')).map(b => b.value);
            if (!keys.length) {
                showToast(impT('imp_need_match'), 'error');
                return;
            }
            const btn = document.getElementById('impGoBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'import_run.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        stored_file: impState.stored,
                        source_name: impState.sourceName,
                        mapping: impCollectMapping(),
                        match_keys: keys,
                        mode: mode,
                        write_mode: document.getElementById('impWriteMode').value,
                        on_unknown_option: document.getElementById('impUnknownOption').value,
                        default_asset_type_id: document.getElementById('impDefaultType').value || null
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                impRenderResult(data.run, data.entries || [], mode);
                if (mode === 'preview') {
                    impSetPreviewed(true);
                } else {
                    showToast(impT('imp_done'), 'success');
                    impSetPreviewed(false);   // that file is spent; preview again to re-run
                    await impInit();
                }
            } catch (e) {
                showToast(e.message || impT('imp_failed'), 'error');
                impSetPreviewed(mode === 'preview' ? false : impState.previewed);
            } finally {
                if (mode !== 'live') btn.disabled = !impState.previewed;
            }
        }

        function impRenderResult(run, entries, mode) {
            // Every counter is shown, zeroes included: "0 conflicts" and "we did
            // not check for conflicts" must never look the same.
            const tally = ['create', 'update', 'unchanged', 'conflict', 'skip', 'error']
                .map(a => `<span><strong>${run[impCountKey(a)]}</strong> ${impT('imp_act_' + a)}</span>`)
                .join('');

            const rows = entries.map(e => `
                <div class="imp-row">
                    <span class="imp-act imp-act-${escapeHtml(e.action)}">${impT('imp_act_' + e.action)}</span>
                    <div class="imp-row-main">
                        <div class="imp-row-name">${escapeHtml(e.display_name || e.source_ref || '&mdash;')}
                            ${e.row_number ? `<span class="cf-scope">${impT('imp_row_n', { n: e.row_number })}</span>` : ''}</div>
                        <div class="imp-row-detail">${escapeHtml(e.detail || '')}</div>
                    </div>
                </div>`).join('');

            document.getElementById('impResult').innerHTML = `
                <div class="imp-info"><strong>${mode === 'preview' ? impT('imp_preview_heading') : impT('imp_live_heading')}</strong></div>
                <div class="imp-tally">${tally}</div>
                <div class="imp-rows">${rows || `<div class="cf-empty">${impT('imp_no_rows')}</div>`}</div>`;
        }

        function impCountKey(action) {
            return { create: 'created_count', update: 'updated_count', unchanged: 'unchanged_count',
                     conflict: 'conflict_count', skip: 'skipped_count', error: 'error_count' }[action];
        }

        /** The holding area — rows that could not be imported, kept to be fixed. */
        function impRenderHeld(rows, count) {
            const badge = document.getElementById('impHeldCount');
            badge.textContent = count ? impT('imp_held_count', { n: count }) : impT('imp_held_none');
            badge.className = 'imp-badge' + (count ? ' attention' : '');

            const list = document.getElementById('impHeldList');
            if (!rows.length) {
                list.innerHTML = `<div class="cf-empty">${impT('imp_held_empty')}</div>`;
                return;
            }
            list.innerHTML = '<div class="imp-rows">' + rows.map(e => {
                // The source row verbatim — the whole point of parking it.
                const raw = Object.entries(e.raw_row || {})
                    .map(([k, v]) => `${escapeHtml(k)}=${escapeHtml(String(v))}`).join('  ');
                return `
                    <div class="imp-row">
                        <span class="imp-act imp-act-${escapeHtml(e.action)}">${impT('imp_act_' + e.action)}</span>
                        <div class="imp-row-main">
                            <div class="imp-row-name">${escapeHtml(e.display_name || e.source_ref || '&mdash;')}
                                <span class="cf-scope">${escapeHtml(e.source_name || '')}${e.row_number ? ' ' + impT('imp_row_n', { n: e.row_number }) : ''}</span></div>
                            <div class="imp-row-detail">${escapeHtml(e.detail || '')}</div>
                            <div class="imp-row-raw">${raw}</div>
                        </div>
                        <button class="action-btn" onclick="impResolve(${e.id})">${impT('imp_resolve')}</button>
                    </div>`;
            }).join('') + '</div>';
        }

        async function impResolve(id) {
            try {
                const res = await fetch(API_BASE + 'import_resolve.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                await impInit();
            } catch (e) {
                showToast(e.message || impT('imp_failed'), 'error');
            }
        }

        function impRenderRuns(runs) {
            const tbody = document.getElementById('impRunList');
            if (!runs.length) {
                tbody.innerHTML = `<tr><td colspan="4" class="cf-empty">${impT('imp_no_runs')}</td></tr>`;
                return;
            }
            tbody.innerHTML = runs.map(r => `
                <tr>
                    <td>${escapeHtml(r.started_datetime || '')}</td>
                    <td>${escapeHtml(r.source_name || '&mdash;')}</td>
                    <td>${impT('imp_run_summary', {
                            created: r.created_count, updated: r.updated_count,
                            unchanged: r.unchanged_count, problems: (+r.error_count) + (+r.conflict_count)
                        })}</td>
                    <td>${escapeHtml(r.analyst_name || '&mdash;')}</td>
                </tr>`).join('');
        }

        // ════════════════════════════════════════════════════════════════
        //  Custom asset fields
        //
        //  One fetch fills all three sections, so the catalogue, the sets and
        //  the per-type view can never disagree about what exists.
        // ════════════════════════════════════════════════════════════════

        let cfData = { fields: [], sets: [], type_sets: {}, schema_ready: false };

        /**
         * ⚠️ This whole block runs even when the tab is NOT rendered — the tab is
         * gated by a capability, the <script> is not. Every DOM touch below is
         * therefore guarded, because one addEventListener on a null element
         * throws and silently kills every function defined after it. That is
         * exactly how the notification bell broke: the throw was one line above
         * the try, so the fetch never ran AND the catch never fired.
         */
        function cfPresent() { return !!document.getElementById('custom-fields-tab'); }

        function cfOn(id, ev, fn) {
            const el = document.getElementById(id);
            if (el) el.addEventListener(ev, fn);
        }

        function cfT(key, vars) { return window.t('asset-management.settings.' + key, vars); }

        /** The analyst-facing name for a field type. */
        function cfKindName(type) {
            return cfT('cf_type_' + type);
        }

        async function cfLoad() {
            if (!cfPresent()) return;   // tab not rendered for this analyst
            try {
                const res = await fetch(API_BASE + 'get_asset_fields.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');

                cfData = data;
                // ⚠️ Not-ready is a STATE, not an empty list. An install that has
                // pulled the update but not run Database Verification must be told
                // why there is nothing here — otherwise "no fields yet" is a lie
                // that survives until somebody files a bug.
                document.getElementById('cfNotReady').style.display = data.schema_ready ? 'none' : '';
                document.getElementById('cfBody').style.display     = data.schema_ready ? '' : 'none';
                if (!data.schema_ready) return;

                cfFillTypeSelect();
                cfRenderTree();
                cfRenderType();
                cfRenderCatalogue();
                cfRenderSets();
            } catch (e) {
                showToast(cfT('cf_save_failed'), 'error');
            }
        }

        /**
         * The read-only overview: asset type → field set → field.
         *
         * 🔑 The whole point is to make the SHAPE visible. A field defined once
         * and reused by two sets across four types is the right design and it is
         * completely invisible from three separate lists — which is how somebody
         * ends up with a set attached to two types and no idea the other two are
         * missing.
         *
         * Two directions, because there are two questions:
         *   "what does a Monitor record?"     → the tree
         *   "where on earth is Resolution used?" → the field summary below it
         */
        let cfTreeOpen = true;

        function cfToggleTree() {
            cfTreeOpen = !cfTreeOpen;
            const tree = document.getElementById('cfTree');
            if (tree) tree.style.display = cfTreeOpen ? '' : 'none';
            const btn = document.getElementById('cfTreeToggle');
            if (btn) btn.textContent = cfTreeOpen ? cfT('cf_tree_hide') : cfT('cf_tree_show');
        }

        function cfRenderTree() {
            const box = document.getElementById('cfTree');
            if (!box) return;

            const setById = {};
            (cfData.sets || []).forEach(s => { setById[s.id] = s; });
            const fieldById = {};
            (cfData.fields || []).forEach(f => { fieldById[f.id] = f; });

            const types = allItems['asset-type'] || [];
            const parts = [];
            const bare  = [];   // types recording nothing extra

            types.forEach(t => {
                const setIds = (cfData.type_sets && cfData.type_sets[t.id]) || [];
                const rows = [];

                setIds.forEach(sid => {
                    const set = setById[sid];
                    if (!set) return;
                    const fields = set.fields || [];
                    rows.push(`
                        <div class="cf-tree-set">
                            <span class="cf-tree-set-name">${escapeHtml(set.name)}</span>
                            <span class="cf-tree-count">${cfT('cf_tree_set_of', { n: fields.length })}</span>
                        </div>` +
                        (fields.length
                            ? fields.map(m => `
                                <div class="cf-tree-field">
                                    <span>${escapeHtml(m.label)}${m.is_required ? '<span class="cf-tree-req">*</span>' : ''}
                                    <span class="cf-tree-kind">${escapeHtml(cfKindName(m.field_type))}</span></span>
                                </div>`).join('')
                            : `<div class="cf-tree-none" style="padding-left:44px;">${cfT('cf_tree_set_empty')}</div>`)
                    );
                });

                // Types that record something come first, in one block. The
                // rest are gathered at the bottom rather than interleaved —
                // otherwise six "nothing extra recorded" lines break up the
                // four that carry the actual answer. Still LISTED, though:
                // a type you forgot to set up is the mistake this view is for.
                if (!rows.length) {
                    bare.push(escapeHtml(t.name));
                    return;
                }
                parts.push(`
                    <div class="cf-tree-node">
                        <div class="cf-tree-type"><span>${escapeHtml(t.name)}</span></div>
                        ${rows.join('')}
                    </div>`);
            });

            if (bare.length) {
                parts.push(`
                    <div class="cf-tree-section">${cfT('cf_tree_bare', { n: bare.length })}</div>
                    <div class="cf-tree-none" style="padding-left:0;">${bare.join(', ')}</div>`);
            }

            // The other direction. "Where is Resolution used?" is the question
            // the tree above cannot answer at a glance once a field is shared.
            const usage = (cfData.fields || []).map(f => {
                const inSets = (cfData.sets || []).filter(s =>
                    (s.fields || []).some(m => m.field_id === f.id));
                const typeNames = [];
                inSets.forEach(s => {
                    types.forEach(t => {
                        const ids = (cfData.type_sets && cfData.type_sets[t.id]) || [];
                        if (ids.includes(s.id) && !typeNames.includes(t.name)) typeNames.push(t.name);
                    });
                });
                // ⚠️ A field in NO set is a real state and is called out — it was
                // created and then never attached, so nothing records it.
                const where = inSets.length
                    ? `${inSets.map(s => escapeHtml(s.name)).join(', ')} &rarr; ${typeNames.length ? typeNames.map(escapeHtml).join(', ') : `<span class="cf-tree-warn">${cfT('cf_tree_set_unused')}</span>`}`
                    : `<span class="cf-tree-warn">${cfT('cf_tree_field_unused')}</span>`;
                return `
                    <div class="cf-tree-field" style="padding-left:22px;">
                        <span><strong>${escapeHtml(f.label)}</strong>
                        <span class="cf-tree-kind">${escapeHtml(cfKindName(f.field_type))}</span> &mdash; ${where}</span>
                    </div>`;
            }).join('');

            box.innerHTML =
                (types.length ? parts.join('') : `<div class="cf-tree-none">${cfT('cf_tree_no_types')}</div>`) +
                (cfData.fields && cfData.fields.length
                    ? `<div class="cf-tree-section">${cfT('cf_tree_where')}</div>${usage}`
                    : '');

            const btn = document.getElementById('cfTreeToggle');
            if (btn) btn.textContent = cfTreeOpen ? cfT('cf_tree_hide') : cfT('cf_tree_show');
        }

        function cfFillTypeSelect() {
            const sel = document.getElementById('cfTypeSelect');
            const keep = sel.value;
            const types = allItems['asset-type'] || [];
            sel.innerHTML = types.map(t =>
                `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
            if (keep && types.some(t => String(t.id) === keep)) sel.value = keep;
        }

        /** Which sets, and therefore which fields, the selected type carries. */
        function cfRenderType() {
            const typeId = parseInt(document.getElementById('cfTypeSelect').value, 10) || 0;
            const box    = document.getElementById('cfTypeFields');
            const setIds = (cfData.type_sets && cfData.type_sets[typeId]) || [];

            const rows = [];
            setIds.forEach(sid => {
                const set = cfData.sets.find(s => s.id === sid);
                if (!set) return;
                (set.fields || []).forEach(f => {
                    rows.push(`
                        <div class="cf-row">
                            <span class="cf-row-name">${escapeHtml(f.label)}${f.is_required ? '<span class="cf-req">*</span>' : ''}</span>
                            <span class="cf-row-kind">${escapeHtml(cfKindName(f.field_type))}</span>
                            <span class="cf-row-from">${cfT('cf_col_from')}: ${escapeHtml(set.name)}</span>
                        </div>`);
                });
            });

            box.innerHTML = rows.length
                ? rows.join('')
                : `<div class="cf-empty">${cfT('cf_type_none')}</div>`;
        }

        function cfRenderCatalogue() {
            const tbody = document.getElementById('cfFieldList');
            if (!cfData.fields.length) {
                tbody.innerHTML = `<tr><td colspan="4" class="cf-empty">${cfT('cf_type_none')}</td></tr>`;
                return;
            }
            tbody.innerHTML = cfData.fields.map(f => {
                const scope = f.scope === 'company'
                    ? ` <span class="cf-scope">(${escapeHtml(window.t('common.company') || 'company')})</span>` : '';
                const used = f.set_count > 0
                    ? cfT('cf_used_by', { n: f.set_count })
                    : `<span class="cf-empty" style="padding:0;">${cfT('cf_used_by_none')}</span>`;
                return `
                    <tr>
                        <td><strong>${escapeHtml(f.label)}</strong>${scope}</td>
                        <td>${escapeHtml(cfKindName(f.field_type))}</td>
                        <td>${used}</td>
                        <td>
                            <button class="action-btn" onclick="cfOpenFieldModal(${f.id}, false)">${window.t('asset-management.common.edit')}</button>
                            <button class="action-btn delete" onclick="cfRetireField(${f.id})">${cfT('cf_retire')}</button>
                        </td>
                    </tr>`;
            }).join('');
        }

        function cfRenderSets() {
            const tbody = document.getElementById('cfSetList');
            if (!cfData.sets.length) {
                tbody.innerHTML = `<tr><td colspan="4" class="cf-empty">${cfT('cf_type_none')}</td></tr>`;
                return;
            }
            tbody.innerHTML = cfData.sets.map(s => {
                const names = (s.fields || []).map(f => escapeHtml(f.label)).join(', ');
                // Both counts are shown even at zero: "on 0 types" and "we did
                // not look" must never render the same way.
                const used = [
                    cfT('cf_set_used_types', { n: s.type_count }),
                    s.asset_count > 0 ? cfT('cf_set_used_assets', { n: s.asset_count }) : ''
                ].filter(Boolean).join('<br>');
                return `
                    <tr>
                        <td><strong>${escapeHtml(s.name)}</strong></td>
                        <td>${names || `<span class="cf-empty" style="padding:0;">&mdash;</span>`}</td>
                        <td>${used}</td>
                        <td>
                            <button class="action-btn" onclick="cfOpenSetModal(${s.id})">${window.t('asset-management.common.edit')}</button>
                            <button class="action-btn delete" onclick="cfDeleteSet(${s.id})">${window.t('asset-management.common.delete')}</button>
                        </td>
                    </tr>`;
            }).join('');
        }

        // ── Field editor ────────────────────────────────────────────────

        function cfOpenFieldModal(id, attachToType) {
            const f = id ? cfData.fields.find(x => x.id === id) : null;

            document.getElementById('cfFieldId').value = id || '';
            document.getElementById('cfFieldAttachToType').value = attachToType ? '1' : '0';
            document.getElementById('cfFieldModalTitle').textContent = f ? cfT('cf_edit_field') : cfT('cf_new_field');

            const typeSel = document.getElementById('cfFieldType');
            typeSel.innerHTML = (cfData.types || []).map(tp =>
                `<option value="${tp}">${escapeHtml(cfKindName(tp))}</option>`).join('');

            document.getElementById('cfFieldLabel').value    = f ? f.label : '';
            typeSel.value                                    = f ? f.field_type : 'text';
            document.getElementById('cfFieldHelp').value     = (f && f.help_text) || '';
            document.getElementById('cfFieldInList').checked = !!(f && f.show_in_list);
            document.getElementById('cfFieldSearchable').checked = !!(f && f.is_searchable);

            document.getElementById('cfFieldUnique').checked = !!(f && f.is_unique);

            const cfg = (f && f.config) || {};
            document.getElementById('cfFieldUnit').value      = cfg.unit || '';
            document.getElementById('cfFieldDecimals').value  = cfg.decimals != null ? cfg.decimals : 0;
            document.getElementById('cfFieldDateMode').value  = cfg.date_mode || 'date';
            document.getElementById('cfFieldRefKind').value   = cfg.ref_kind || 'user';
            document.getElementById('cfFieldMultiline').checked = !!cfg.multiline;
            document.getElementById('cfFieldOptions').value =
                f && f.options ? f.options.map(o => o.option_value).join('\n') : '';

            // 🔑 The key is shown, and shown as FIXED, on an existing field —
            // an import that maps onto it must not silently break when somebody
            // renames the label.
            const note = document.getElementById('cfFieldKeyNote');
            if (f) {
                note.innerHTML = cfT('cf_field_key_note', { key: escapeHtml(f.field_key) });
                note.style.display = '';
            } else {
                note.style.display = 'none';
            }

            // Locked type: say so up front rather than letting somebody try.
            const locked = document.getElementById('cfTypeLocked');
            if (f && f.type_locked) {
                locked.textContent = cfT('cf_type_locked', { n: f.value_count });
                locked.style.display = '';
                typeSel.disabled = true;
            } else {
                locked.style.display = 'none';
                typeSel.disabled = false;
            }

            cfSyncFieldModal();
            cfCheckBuiltin();   // clears a warning left over from the last open
            document.getElementById('cfFieldModal').classList.add('active');
        }

        /**
         * The columns every asset already has. A custom field duplicating one of
         * these is legal but almost never what somebody means, and the cost of
         * finding out later is high: the Add dialog ends up asking for
         * "Manufacturer" and "Make" side by side, and no report can join them.
         *
         * Synonyms included, because the clash is about MEANING, not spelling —
         * "Make" is the one that actually caught somebody out.
         */
        const CF_BUILTIN = {
            'manufacturer': 'manufacturer', 'make': 'manufacturer', 'brand': 'manufacturer',
            'model': 'model', 'model number': 'model', 'model no': 'model',
            'serial': 'service_tag', 'serial number': 'service_tag',
            'service tag': 'service_tag', 'asset tag': 'asset_tag',
            'hostname': 'hostname', 'name': 'hostname',
            'location': 'location', 'status': 'status', 'type': 'type',
            'supplier': 'supplier', 'order number': 'order_number',
            'purchase date': 'purchase_date', 'purchase cost': 'purchase_cost',
            'cost': 'purchase_cost', 'price': 'purchase_cost',
            'warranty': 'warranty_expiry', 'warranty expiry': 'warranty_expiry',
            'memory': 'memory', 'ram': 'memory', 'cpu': 'cpu',
            'operating system': 'operating_system', 'os': 'operating_system',
            'bios': 'bios_version', 'bios version': 'bios_version'
        };

        function cfCheckBuiltin() {
            const box = document.getElementById('cfBuiltinWarn');
            if (!box) return;
            // Only for NEW fields: warning about an existing one every time it is
            // edited is a nag about a decision already taken.
            if (document.getElementById('cfFieldId').value) { box.style.display = 'none'; return; }

            const typed = document.getElementById('cfFieldLabel').value.trim().toLowerCase();
            const hit   = CF_BUILTIN[typed];
            if (!hit) { box.style.display = 'none'; return; }
            box.innerHTML = cfT('cf_builtin_warn', { field: escapeHtml(hit.replace(/_/g, ' ')) });
            box.style.display = '';
        }

        /** Show only the settings that belong to the chosen kind of information. */
        function cfSyncFieldModal() {
            const type = document.getElementById('cfFieldType').value;
            document.querySelectorAll('.cf-forType').forEach(el => {
                el.style.display = el.classList.contains('cf-forType-' + type) ? '' : 'none';
            });
        }

        function cfCloseFieldModal() {
            document.getElementById('cfFieldModal').classList.remove('active');
        }

        cfOn('cfFieldForm', 'submit', async function (e) {
            e.preventDefault();
            const id   = parseInt(document.getElementById('cfFieldId').value, 10) || 0;
            const type = document.getElementById('cfFieldType').value;

            const config = {};
            if (type === 'number') {
                const unit = document.getElementById('cfFieldUnit').value.trim();
                if (unit) config.unit = unit;
                config.decimals = parseInt(document.getElementById('cfFieldDecimals').value, 10) || 0;
            } else if (type === 'date') {
                config.date_mode = document.getElementById('cfFieldDateMode').value;
            } else if (type === 'ref') {
                config.ref_kind = document.getElementById('cfFieldRefKind').value;
            } else if (type === 'text' && document.getElementById('cfFieldMultiline').checked) {
                config.multiline = true;
            }

            const payload = {
                label:         document.getElementById('cfFieldLabel').value.trim(),
                field_type:    type,
                config:        config,
                help_text:     document.getElementById('cfFieldHelp').value.trim(),
                show_in_list:  document.getElementById('cfFieldInList').checked,
                is_searchable: document.getElementById('cfFieldSearchable').checked,

                is_unique:     document.getElementById('cfFieldUnique').checked
            };
            if (id) payload.id = id;
            if (type === 'dropdown') {
                payload.options = document.getElementById('cfFieldOptions').value
                    .split('\n').map(s => s.trim()).filter(Boolean);
            }

            try {
                const res  = await fetch(API_BASE + 'save_asset_field.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                // 🔑 THE GUIDED PATH. Adding a field from the type view attaches
                // it to that type immediately, creating a set behind the scenes
                // if the type has none. Somebody who never wants to think about
                // field sets never has to meet one.
                if (document.getElementById('cfFieldAttachToType').value === '1') {
                    await cfAttachFieldToCurrentType(data.id);
                }

                cfCloseFieldModal();
                showToast(cfT('cf_saved'), 'success');
                await cfLoad();
            } catch (err) {
                showToast(err.message || cfT('cf_save_failed'), 'error');
            }
        });

        /**
         * Put a field onto the selected asset type without the analyst having to
         * know what a set is. Reuses the type's first set if it has one;
         * otherwise makes one named after the type.
         */
        async function cfAttachFieldToCurrentType(fieldId) {
            const typeId  = parseInt(document.getElementById('cfTypeSelect').value, 10) || 0;
            if (!typeId) return;
            const typeName = (allItems['asset-type'].find(t => t.id === typeId) || {}).name || '';
            const existing = (cfData.type_sets && cfData.type_sets[typeId]) || [];

            let setId = existing[0] || 0;
            let fields = [];

            if (setId) {
                const set = cfData.sets.find(s => s.id === setId);
                fields = (set && set.fields ? set.fields : []).map((f, i) => ({
                    field_id: f.field_id, sort_order: i, is_required: f.is_required,
                    default_value: f.default_value
                }));
            }
            fields.push({ field_id: fieldId, sort_order: fields.length, is_required: false });

            const body = { name: setId ? undefined : typeName, fields: fields };
            if (setId) {
                const set = cfData.sets.find(s => s.id === setId);
                body.id = setId;
                body.name = set.name;
                body.description = set.description;
            }

            const res  = await fetch(API_BASE + 'save_asset_field_set.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            if (!setId) {
                await fetch(API_BASE + 'save_type_field_sets.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ asset_type_id: typeId, set_ids: [data.id] })
                });
            }
        }

        async function cfRetireField(id) {
            const f = cfData.fields.find(x => x.id === id);
            if (!f) return;
            // The wording says the values are KEPT, because that is the fact
            // that decides whether somebody dares press the button.
            if (!(await showConfirm({
                title:   cfT('cf_retire'),
                message: cfT('cf_retire_confirm', { name: f.label }),
                okLabel: cfT('cf_retire'),
                okClass: 'danger'
            }))) return;
            try {
                const res  = await fetch(API_BASE + 'delete_asset_field.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                showToast(cfT('cf_saved'), 'success');
                await cfLoad();
            } catch (err) {
                showToast(err.message || cfT('cf_save_failed'), 'error');
            }
        }

        // ── Set editor ──────────────────────────────────────────────────

        function cfOpenSetModal(id) {
            const s = id ? cfData.sets.find(x => x.id === id) : null;
            document.getElementById('cfSetId').value   = id || '';
            document.getElementById('cfSetName').value = s ? s.name : '';
            document.getElementById('cfSetDesc').value = (s && s.description) || '';
            document.getElementById('cfSetModalTitle').textContent = s ? cfT('cf_edit_set') : cfT('cf_new_set');

            const chosen = {};
            (s && s.fields ? s.fields : []).forEach(f => { chosen[f.field_id] = f; });

            document.getElementById('cfSetFieldPicker').innerHTML = cfData.fields.map(f => {
                const on  = !!chosen[f.id];
                const req = on && chosen[f.id].is_required;
                return `
                    <label class="cf-pick">
                        <input type="checkbox" value="${f.id}" ${on ? 'checked' : ''}>
                        <span>${escapeHtml(f.label)}</span>
                        <span class="cf-pick-kind">${escapeHtml(cfKindName(f.field_type))}</span>
                        <span class="cf-pick-req">
                            <input type="checkbox" class="cf-pick-required" ${req ? 'checked' : ''}>
                            ${cfT('cf_set_required')}
                        </span>
                    </label>`;
            }).join('') || `<div class="cf-empty">${cfT('cf_used_by_none')}</div>`;

            document.getElementById('cfSetModal').classList.add('active');
        }

        function cfCloseSetModal() {
            document.getElementById('cfSetModal').classList.remove('active');
        }

        cfOn('cfSetForm', 'submit', async function (e) {
            e.preventDefault();
            const id = parseInt(document.getElementById('cfSetId').value, 10) || 0;

            const fields = [];
            document.querySelectorAll('#cfSetFieldPicker .cf-pick').forEach((row, i) => {
                const box = row.querySelector('input[type="checkbox"]');
                if (!box.checked) return;
                fields.push({
                    field_id:    parseInt(box.value, 10),
                    sort_order:  fields.length,
                    is_required: row.querySelector('.cf-pick-required').checked
                });
            });

            const payload = {
                name:        document.getElementById('cfSetName').value.trim(),
                description: document.getElementById('cfSetDesc').value.trim(),
                fields:      fields
            };
            if (id) payload.id = id;

            try {
                const res  = await fetch(API_BASE + 'save_asset_field_set.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                cfCloseSetModal();
                showToast(cfT('cf_saved'), 'success');
                await cfLoad();
            } catch (err) {
                showToast(err.message || cfT('cf_save_failed'), 'error');
            }
        });

        async function cfDeleteSet(id) {
            const s = cfData.sets.find(x => x.id === id);
            if (!s) return;
            if (!(await showConfirm({
                title:   window.t('asset-management.common.delete'),
                message: cfT('cf_set_delete_confirm', { name: s.name }),
                okLabel: window.t('asset-management.common.delete'),
                okClass: 'danger'
            }))) return;
            try {
                const res  = await fetch(API_BASE + 'delete_asset_field_set.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                showToast(cfT('cf_saved'), 'success');
                await cfLoad();
            } catch (err) {
                showToast(err.message || cfT('cf_save_failed'), 'error');
            }
        }

        // ── Attaching whole sets to the selected type ───────────────────

        function cfOpenAttachSet() {
            const typeId = parseInt(document.getElementById('cfTypeSelect').value, 10) || 0;
            const on     = (cfData.type_sets && cfData.type_sets[typeId]) || [];
            document.getElementById('cfAttachSetPicker').innerHTML = cfData.sets.map(s => `
                <label class="cf-pick">
                    <input type="checkbox" value="${s.id}" ${on.includes(s.id) ? 'checked' : ''}>
                    <span>${escapeHtml(s.name)}</span>
                    <span class="cf-pick-kind">${(s.fields || []).length}</span>
                </label>`).join('') || `<div class="cf-empty">${cfT('cf_used_by_none')}</div>`;
            document.getElementById('cfAttachSetModal').classList.add('active');
        }

        function cfCloseAttachSet() {
            document.getElementById('cfAttachSetModal').classList.remove('active');
        }

        cfOn('cfAttachSetForm', 'submit', async function (e) {
            e.preventDefault();
            const typeId = parseInt(document.getElementById('cfTypeSelect').value, 10) || 0;
            const setIds = Array.from(
                document.querySelectorAll('#cfAttachSetPicker input[type="checkbox"]:checked')
            ).map(b => parseInt(b.value, 10));

            try {
                const res  = await fetch(API_BASE + 'save_type_field_sets.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ asset_type_id: typeId, set_ids: setIds })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                cfCloseAttachSet();
                showToast(cfT('cf_saved'), 'success');
                await cfLoad();
            } catch (err) {
                showToast(err.message || cfT('cf_save_failed'), 'error');
            }
        });
    </script>

    <?php if (settingsTabVisible($visibleTabs, 'handover')): ?>
    <script>
    /* ─── Handover document designer (discussion #56) ──────────────────────────
       The blocks come from the server's catalogue, so this file never decides
       what a document can contain — add a block type in HandoverTemplates and it
       appears here. The preview renders through the SAME server-side renderer as
       the printed page, which is why it cannot show something the document will
       not produce. */
    (function () {
        const API = '../../api/assets/handover_templates.php';
        let META = null;            // catalogue, columns, merge codes
        let blocks = [];            // the template being edited
        let currentId = 0;
        let lastFocused = null;     // where a merge code gets inserted

        const el = id => document.getElementById(id);
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
            ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

        const blockLabel = type => {
            const k = 'asset-management.settings.handover_block_' + type;
            const v = window.t(k);
            return v === k ? type : v;
        };
        const fieldLabel = (type, field) => {
            const k = 'asset-management.settings.handover_field_' + field;
            const v = window.t(k);
            return v === k ? field : v;
        };
        const colLabel = col => {
            const k = 'asset-management.settings.handover_col_' + col;
            const v = window.t(k);
            return v === k ? col : v;
        };

        async function api(payload, isPost) {
            const opts = isPost
                ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }
                : undefined;
            const url = isPost ? API : API + '?' + new URLSearchParams(payload);
            const r = await fetch(url, opts);
            return r.json();
        }

        function renderMergeCodes() {
            el('hoMergeCodes').innerHTML = META.merge_codes
                .map(c => `<button type="button" class="ho-code" data-code="${esc(c)}">${esc(c)}</button>`).join('');
        }

        function renderBlocks() {
            el('hoBlockList').innerHTML = blocks.map((b, i) => {
                const def = META.catalogue[b.type] || { text: {} };
                const textFields = Object.keys(def.text || {}).map(f => `
                    <label class="ho-field" style="margin:0">
                        <span class="ho-field-label">${esc(fieldLabel(b.type, f))}</span>
                        ${(b.text[f] || '').length > 60 || f === 'body'
                            ? `<textarea data-block="${i}" data-field="${esc(f)}">${esc(b.text[f] || '')}</textarea>`
                            : `<input type="text" data-block="${i}" data-field="${esc(f)}" value="${esc(b.text[f] || '')}">`}
                    </label>`).join('');

                const cols = b.columns ? `
                    <div>
                        <span class="ho-field-label">${esc(window.t('asset-management.settings.handover_columns'))}</span>
                        <div class="ho-cols">
                            ${Object.keys(META.columns).map(c => `
                                <label class="ho-col-toggle">
                                    <input type="checkbox" data-block="${i}" data-col="${esc(c)}" ${b.columns[c] ? 'checked' : ''}>
                                    ${esc(colLabel(c))}
                                </label>`).join('')}
                        </div>
                    </div>` : '';

                const hasBody = textFields || cols;
                return `
                <div class="ho-block ${b.enabled ? '' : 'disabled'}">
                    <div class="ho-block-head">
                        <input type="checkbox" data-block="${i}" data-enabled="1" ${b.enabled ? 'checked' : ''}
                               title="${esc(window.t('asset-management.settings.handover_show'))}">
                        <span class="ho-block-name">${esc(blockLabel(b.type))}</span>
                        <button type="button" class="ho-mini" data-move="up" data-block="${i}" ${i === 0 ? 'disabled' : ''}>&uarr;</button>
                        <button type="button" class="ho-mini" data-move="down" data-block="${i}" ${i === blocks.length - 1 ? 'disabled' : ''}>&darr;</button>
                    </div>
                    ${hasBody ? `<div class="ho-block-body">${textFields}${cols}</div>` : ''}
                </div>`;
            }).join('');
        }

        let previewTimer = null;
        function schedulePreview() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(refreshPreview, 300);
        }
        async function refreshPreview() {
            try {
                const d = await api({ action: 'preview', blocks: blocks }, true);
                // Server-rendered and already escaped there; assigning it here is
                // what makes the preview honest about the real output.
                if (d.success) el('hoPreview').innerHTML = d.html;
            } catch (e) { /* preview is advisory */ }
        }

        async function loadTemplateList(selectId) {
            const d = await api({ action: 'list' });
            const sel = el('hoTemplateSelect');
            const list = d.templates || [];
            sel.innerHTML = list.map(t =>
                `<option value="${t.id}">${esc(t.name)}${t.is_default ? ' ★' : ''}${t.is_active ? '' : ' (' + esc(window.t('asset-management.settings.handover_inactive')) + ')'}</option>`
            ).join('') || `<option value="0">${esc(window.t('asset-management.settings.handover_default_name'))}</option>`;
            if (selectId) sel.value = String(selectId);
            await loadTemplate(parseInt(sel.value, 10) || 0);
        }

        async function loadTemplate(id) {
            currentId = id;
            if (!id) {
                blocks = JSON.parse(JSON.stringify(META.defaults));
                el('hoName').value = window.t('asset-management.settings.handover_default_name');
            } else {
                const d = await api({ action: 'get', id: id });
                if (!d.success) return;
                blocks = d.template.blocks;
                el('hoName').value = d.template.name;
            }
            renderBlocks();
            refreshPreview();
        }

        // ── events ──────────────────────────────────────────────────────────
        el('hoBlockList').addEventListener('input', function (e) {
            const t = e.target, i = parseInt(t.dataset.block, 10);
            if (isNaN(i)) return;
            if (t.dataset.field) { blocks[i].text[t.dataset.field] = t.value; schedulePreview(); }
        });
        el('hoBlockList').addEventListener('change', function (e) {
            const t = e.target, i = parseInt(t.dataset.block, 10);
            if (isNaN(i)) return;
            if (t.dataset.enabled) { blocks[i].enabled = t.checked; renderBlocks(); refreshPreview(); }
            else if (t.dataset.col) { blocks[i].columns[t.dataset.col] = t.checked; refreshPreview(); }
        });
        el('hoBlockList').addEventListener('click', function (e) {
            const btn = e.target.closest('[data-move]');
            if (!btn) return;
            const i = parseInt(btn.dataset.block, 10);
            const j = btn.dataset.move === 'up' ? i - 1 : i + 1;
            if (j < 0 || j >= blocks.length) return;
            [blocks[i], blocks[j]] = [blocks[j], blocks[i]];
            renderBlocks();
            refreshPreview();
        });
        // Remember the last text box touched, so a merge code lands where the
        // administrator was typing rather than at the end of some other field.
        el('hoBlockList').addEventListener('focusin', function (e) {
            if (e.target.dataset && e.target.dataset.field) lastFocused = e.target;
        });
        el('hoMergeCodes').addEventListener('click', function (e) {
            const btn = e.target.closest('.ho-code');
            if (!btn) return;
            const code = btn.dataset.code;
            if (!lastFocused) { showToast(window.t('asset-management.settings.handover_pick_field'), 'info'); return; }
            const s = lastFocused.selectionStart ?? lastFocused.value.length;
            lastFocused.value = lastFocused.value.slice(0, s) + code + lastFocused.value.slice(lastFocused.selectionEnd ?? s);
            lastFocused.dispatchEvent(new Event('input', { bubbles: true }));
            lastFocused.focus();
            lastFocused.selectionStart = lastFocused.selectionEnd = s + code.length;
        });

        el('hoTemplateSelect').addEventListener('change', function () { loadTemplate(parseInt(this.value, 10) || 0); });

        el('hoNewBtn').addEventListener('click', function () {
            currentId = 0;
            blocks = JSON.parse(JSON.stringify(META.defaults));
            el('hoName').value = window.t('asset-management.settings.handover_new_name');
            renderBlocks();
            refreshPreview();
        });

        el('hoSaveBtn').addEventListener('click', async function () {
            const d = await api({ action: 'save', id: currentId, name: el('hoName').value, blocks: blocks, is_active: 1 }, true);
            if (d.success) {
                showToast(window.t('asset-management.settings.handover_saved'), 'success');
                loadTemplateList(d.id);
            } else {
                showToast(d.error || window.t('asset-management.settings.handover_save_failed'), 'error');
            }
        });

        el('hoDefaultBtn').addEventListener('click', async function () {
            if (!currentId) { showToast(window.t('asset-management.settings.handover_save_first'), 'info'); return; }
            const d = await api({ action: 'default', id: currentId }, true);
            if (d.success) { showToast(window.t('asset-management.settings.handover_default_set'), 'success'); loadTemplateList(currentId); }
        });

        el('hoDeleteBtn').addEventListener('click', async function () {
            if (!currentId) return;
            const ok = await showConfirm({
                title:   window.t('asset-management.settings.handover_delete'),
                message: window.t('asset-management.settings.handover_delete_confirm', { name: el('hoName').value }),
                okLabel: window.t('asset-management.settings.handover_delete'),
                okClass: 'danger'
            });
            if (!ok) return;
            const d = await api({ action: 'delete', id: currentId }, true);
            if (d.success) { showToast(window.t('asset-management.settings.handover_deleted'), 'success'); loadTemplateList(0); }
        });

        // Loaded on first paint rather than on tab open: the tab may already be
        // the active one when the page loads from a deep link.
        (async function init() {
            const m = await api({ action: 'meta' });
            if (!m.success) return;
            META = m;
            renderMergeCodes();
            loadTemplateList(0);
        })();
    })();
    </script>
    <?php endif; ?>

    <?php /* Loaded last so it can wrap this page's globals; inert on desktop. */ ?>
    <script>window.assetTypeIcons = <?php echo json_encode($assetTypeIcons, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/proxmox-settings.js?v=2"></script>
    <script src="../../assets/js/vcloud-settings.js?v=2"></script>
    <script src="../../assets/js/network-mapper-icons.js?v=3"></script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
