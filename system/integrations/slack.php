<?php
/**
 * System — Slack workspaces.
 *
 * Reached as /system/integrations/slack (see .htaccess), or by provider.php
 * handing over when the registry says this provider's `kind` is not a tracker.
 *
 * ⚠️ It sits in Integrations because that is where people look for "connect
 * FreeITSM to X", but underneath it is a MESSAGING CHANNEL: rows live in
 * `messaging_channels`, the same table as WhatsApp and web chat, and it reuses
 * api/messaging/*. It has its own page rather than the registry-rendered
 * credential form because its setup is a different shape — install an app from a
 * manifest, then paste two secrets back — not "a base URL and a token".
 *
 * ⚠️ The manifest can only be built once the channel row EXISTS, because the
 * webhook endpoint is per-channel (webhook.php?channel=<id>). That is why the
 * flow is: name it and save → collect the app → paste the secrets.
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
require_once '../../includes/integrations/integrations.php';
require_once '../../includes/messaging/messaging.php';

$current_page = 'integrations';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

$conn = connectToDatabase();

$meta = integrationsProviderMeta('slack');

// The messaging tables arrive with the same Database Verification run as the
// tracker ones, but they are a different set — so check the one we actually use
// rather than inheriting the trackers' answer.
$schemaOk = true;
try {
    $conn->query("SELECT 1 FROM messaging_channels LIMIT 1");
} catch (Exception $e) {
    $schemaOk = false;
}

$multiCompany = function_exists('isMultiTenant') ? isMultiTenant($conn) : false;
$companies    = $multiCompany ? getAllTenants($conn, true) : [];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Slack</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../../assets/css/integrations.css?v=1">
    <style>
        /* Only what this page alone draws — everything else is integrations.css. */

        /* The setup steps. Numbered because the order genuinely matters: the
           manifest cannot exist before the connection does. */
        .setup-steps { counter-reset: step; margin: 0; padding: 0; list-style: none; }
        .setup-steps li {
            counter-increment: step;
            position: relative;
            padding: 0 0 16px 40px;
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .setup-steps li::before {
            content: counter(step);
            position: absolute; left: 0; top: -1px;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: var(--sys-accent-soft); color: var(--sys-accent);
            font-size: 13px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .setup-steps li strong { color: var(--text); }

        /* The manifest and the webhook URL. Monospace and selectable — both are
           pasted somewhere else, so the only job here is "copy this exactly". */
        .code-box {
            background: #263238; color: #eceff1;
            border-radius: 8px; padding: 14px 16px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12.5px; line-height: 1.55;
            overflow: auto; max-height: 360px;
            white-space: pre;
        }
        .copy-row { display: flex; gap: 8px; align-items: center; margin: 10px 0 4px; }
        .copy-row .grow { flex: 1; min-width: 0; }
        .url-box {
            font-family: 'Consolas', 'Monaco', monospace; font-size: 12.5px;
            background: var(--surface-2); color: var(--text);
            border: 1px solid var(--border); border-radius: 6px;
            padding: 9px 11px; width: 100%; box-sizing: border-box;
        }
        .scope-list { margin: 10px 0 0; padding: 0; list-style: none; font-size: 13px; }
        .scope-list li { padding: 5px 0; border-bottom: 1px solid var(--border-soft); color: var(--text-muted); }
        .scope-list li:last-child { border-bottom: none; }
        .scope-list code {
            background: var(--surface-2); border-radius: 3px; padding: 1px 5px;
            font-size: 12px; color: var(--text);
        }
        /* The health check. One row per thing that can silently be wrong, each
           with the sentence that fixes it — every one of these corresponds to a
           real failure hit while getting the first workspace working. */
        .diag-summary {
            padding: 12px 15px; border-radius: 8px; margin-bottom: 16px;
            font-size: 14px; line-height: 1.5;
        }
        .diag-summary.ok   { background: var(--success-bg); color: var(--success-text); }
        .diag-summary.warn { background: var(--warning-bg); color: var(--warning-text); }
        .diag-summary.fail { background: var(--danger-bg);  color: var(--danger-text); }

        .diag-row {
            display: grid; grid-template-columns: 22px 1fr; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--border-soft);
        }
        .diag-row:last-child { border-bottom: none; }
        .diag-icon {
            width: 20px; height: 20px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; margin-top: 1px;
        }
        .diag-icon.ok   { background: var(--success-bg); color: var(--success-text); }
        .diag-icon.warn { background: var(--warning-bg); color: var(--warning-text); }
        .diag-icon.fail { background: var(--danger-bg);  color: var(--danger-text); }
        .diag-icon.skip { background: var(--surface-2);  color: var(--text-faint); }
        .diag-label  { font-size: 14px; font-weight: 600; color: var(--text); }
        .diag-detail { font-size: 13px; color: var(--text-muted); line-height: 1.55; margin-top: 2px; word-break: break-word; }
        /* The fix is the point of the whole panel, so it is styled to be read —
           a diagnostic that names a fault and stops has moved the problem. */
        .diag-fix {
            font-size: 13px; line-height: 1.55; margin-top: 6px;
            padding: 8px 11px; border-radius: 6px;
            background: var(--surface-2); color: var(--text);
            border-left: 3px solid var(--sys-accent);
        }
        .diag-running { font-size: 14px; color: var(--text-muted); padding: 20px 0; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="integrations-slack">
    <?php include '../includes/header.php'; ?>

    <div class="int-container">
        <a class="back-link" href="./">&larr; <?php echo htmlspecialchars(t('system.integrations.title')); ?></a>
        <h1 class="page-title">Slack</h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.integrations.slack_blurb')); ?></p>

        <a class="help-link" href="./slack/help">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true"><circle cx="12" cy="12" r="10"></circle>
                 <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <?php echo htmlspecialchars(t('system.integrations.help_link', ['name' => 'Slack'])); ?>
        </a>

        <?php if (!$schemaOk): ?>
            <div class="setup-warning"><?php echo htmlspecialchars(t('system.integrations.needs_db_verify')); ?></div>
        <?php endif; ?>

        <div class="settings-card">
            <div class="section-header">
                <h3><?php echo htmlspecialchars(t('system.integrations.slack_workspaces')); ?></h3>
                <button class="add-btn" id="addBtn"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p class="card-desc"><?php echo htmlspecialchars(t('system.integrations.slack_workspaces_desc')); ?></p>

            <table class="int-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('system.integrations.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.integrations.slack_col_channel')); ?></th>
                        <?php if ($multiCompany): ?>
                            <th><?php echo htmlspecialchars(t('system.integrations.col_company')); ?></th>
                        <?php endif; ?>
                        <th><?php echo htmlspecialchars(t('system.integrations.col_status')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="chanRows">
                    <tr><td class="empty-row" colspan="5"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add / edit a workspace -->
    <div class="modal-backdrop" id="editModal">
        <div class="modal-box">
            <h3 id="editTitle"><?php echo htmlspecialchars(t('system.integrations.slack_add_title')); ?></h3>
            <input type="hidden" id="fId">

            <div class="int-field">
                <label for="fName"><?php echo htmlspecialchars(t('system.integrations.col_name')); ?> *</label>
                <input type="text" id="fName" placeholder="IT help desk">
                <div class="hint"><?php echo htmlspecialchars(t('system.integrations.slack_name_hint')); ?></div>
            </div>

            <div class="int-field">
                <label for="fBotToken"><?php echo htmlspecialchars(t('system.integrations.slack_bot_token')); ?></label>
                <input type="password" id="fBotToken" autocomplete="off" placeholder="xoxb-…">
                <div class="hint"><?php echo htmlspecialchars(t('system.integrations.slack_bot_token_hint')); ?></div>
            </div>

            <div class="int-field">
                <label for="fSigning"><?php echo htmlspecialchars(t('system.integrations.slack_signing_secret')); ?></label>
                <input type="password" id="fSigning" autocomplete="off">
                <div class="hint"><?php echo htmlspecialchars(t('system.integrations.slack_signing_secret_hint')); ?></div>
            </div>

            <div class="int-field">
                <label for="fWatch"><?php echo htmlspecialchars(t('system.integrations.slack_watch_channel')); ?></label>
                <input type="text" id="fWatch" placeholder="C08ABCDEF">
                <div class="hint"><?php echo htmlspecialchars(t('system.integrations.slack_watch_channel_hint')); ?></div>
            </div>

            <?php if ($multiCompany): ?>
            <div class="int-field">
                <label for="fCompany"><?php echo htmlspecialchars(t('system.integrations.col_company')); ?></label>
                <select id="fCompany">
                    <option value=""><?php echo htmlspecialchars(t('system.integrations.slack_company_shared')); ?></option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint"><?php echo htmlspecialchars(t('system.integrations.slack_company_hint')); ?></div>
            </div>
            <?php endif; ?>

            <div class="checkbox-row">
                <input type="checkbox" id="fActive" checked>
                <label for="fActive" style="margin:0;"><?php echo htmlspecialchars(t('system.integrations.active_label')); ?></label>
            </div>

            <div class="modal-actions">
                <button class="btn-secondary" id="editCancel"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn-primary" id="editSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Health check -->
    <div class="modal-backdrop" id="diagModal">
        <div class="modal-box map-box">
            <h3><?php echo htmlspecialchars(t('system.integrations.slack_diag_title')); ?></h3>
            <p class="card-desc"><?php echo htmlspecialchars(t('system.integrations.slack_diag_desc')); ?></p>
            <div id="diagBody"><div class="diag-running"><?php echo htmlspecialchars(t('common.loading')); ?></div></div>
            <div class="modal-actions">
                <button class="btn-secondary" id="diagRerun"><?php echo htmlspecialchars(t('system.integrations.slack_diag_rerun')); ?></button>
                <button class="btn-primary" id="diagClose"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- The Slack app: manifest, webhook URL, scopes -->
    <div class="modal-backdrop" id="appModal">
        <div class="modal-box map-box">
            <h3><?php echo htmlspecialchars(t('system.integrations.slack_app_title')); ?></h3>

            <div id="urlProblem" class="setup-warning" style="display:none;"></div>

            <ol class="setup-steps">
                <li><?php echo t('system.integrations.slack_step1'); ?></li>
                <li>
                    <?php echo t('system.integrations.slack_step2'); ?>
                    <div class="copy-row">
                        <div class="grow"><textarea class="code-box" id="manifestBox" rows="12" readonly style="width:100%;border:0;"></textarea></div>
                    </div>
                    <button class="btn-secondary" id="copyManifest"><?php echo htmlspecialchars(t('system.integrations.slack_copy_manifest')); ?></button>
                </li>
                <li><?php echo t('system.integrations.slack_step3'); ?></li>
                <li>
                    <?php echo t('system.integrations.slack_step4'); ?>
                    <div class="copy-row">
                        <input type="text" class="url-box grow" id="webhookBox" readonly>
                        <button class="btn-secondary" id="copyUrl"><?php echo htmlspecialchars(t('common.copy')); ?></button>
                    </div>
                </li>
                <li><?php echo t('system.integrations.slack_step5'); ?></li>
            </ol>

            <h4 style="margin:18px 0 4px;font-size:15px;color:var(--text);"><?php echo htmlspecialchars(t('system.integrations.slack_scopes_heading')); ?></h4>
            <p class="card-desc" style="margin-bottom:6px;"><?php echo htmlspecialchars(t('system.integrations.slack_scopes_desc')); ?></p>
            <ul class="scope-list">
                <?php
                require_once '../../includes/messaging/slack_manifest.php';
                foreach (slackManifestScopes() as $scope => $why): ?>
                    <li><code><?php echo htmlspecialchars($scope); ?></code> — <?php echo htmlspecialchars($why); ?></li>
                <?php endforeach; ?>
            </ul>

            <div class="modal-actions">
                <button class="btn-secondary" id="appClose"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const API = '<?php echo BASE_URL; ?>api/messaging/';
        const MULTI = <?php echo $multiCompany ? 'true' : 'false'; ?>;
        const COMPANIES = <?php echo json_encode(array_column($companies, 'name', 'id')); ?>;
        let channels = [];

        const $ = id => document.getElementById(id);
        const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        function load() {
            fetch(API + 'get_channels.php', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    // The endpoint returns every channel type; this page owns Slack only.
                    channels = (d.channels || []).filter(c => c.provider === 'slack');
                    render();
                })
                .catch(() => {
                    $('chanRows').innerHTML = '<tr><td class="empty-row" colspan="5">' +
                        <?php echo json_encode(t('common.failed')); ?> + '</td></tr>';
                });
        }

        function render() {
            const tb = $('chanRows');
            if (!channels.length) {
                tb.innerHTML = '<tr><td class="empty-row" colspan="5">' +
                    <?php echo json_encode(t('system.integrations.slack_empty')); ?> + '</td></tr>';
                return;
            }
            tb.innerHTML = channels.map(c => {
                const watch = c.watch_channel
                    ? esc(c.watch_channel)
                    : '<span style="color:var(--text-faint)">' +
                      <?php echo json_encode(t('system.integrations.slack_any_channel')); ?> + '</span>';
                const company = MULTI
                    ? '<td>' + (c.tenant_id ? esc(COMPANIES[c.tenant_id] || c.tenant_id)
                        : '<span class="badge-shared">' + <?php echo json_encode(t('system.integrations.slack_company_shared')); ?> + '</span>') + '</td>'
                    : '';
                // "Set up" rather than "Active" when there are no credentials yet:
                // a row that is switched on but has no token is not working, and
                // saying "Active" would be a lie the admin acts on.
                const status = !c.has_credentials
                    ? '<span class="status-badge off">' + <?php echo json_encode(t('system.integrations.slack_needs_setup')); ?> + '</span>'
                    : (c.is_active
                        ? '<span class="status-badge on">' + <?php echo json_encode(t('system.integrations.active_label')); ?> + '</span>'
                        : '<span class="status-badge off">' + <?php echo json_encode(t('system.integrations.inactive_label')); ?> + '</span>');
                return '<tr>'
                    + '<td>' + esc(c.name) + '</td>'
                    + '<td>' + watch + '</td>'
                    + company
                    + '<td>' + status + '</td>'
                    + '<td style="text-align:right;white-space:nowrap">'
                    +   '<button class="action-btn" data-app="' + c.id + '" title="' + <?php echo json_encode(t('system.integrations.slack_app_title')); ?> + '">'
                    +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2v20M2 12h20"/></svg></button>'
                    +   '<button class="action-btn" data-test="' + c.id + '" title="' + <?php echo json_encode(t('system.integrations.test')); ?> + '">'
                    +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg></button>'
                    +   '<button class="action-btn" data-edit="' + c.id + '" title="' + <?php echo json_encode(t('common.edit')); ?> + '">'
                    +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>'
                    +   '<button class="action-btn delete" data-del="' + c.id + '" title="' + <?php echo json_encode(t('common.delete')); ?> + '">'
                    +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button>'
                    + '</td></tr>';
            }).join('');
        }

        // ---- add / edit -------------------------------------------------
        function openEdit(c) {
            $('editTitle').textContent = c
                ? <?php echo json_encode(t('system.integrations.slack_edit_title')); ?>
                : <?php echo json_encode(t('system.integrations.slack_add_title')); ?>;
            $('fId').value      = c ? c.id : '';
            $('fName').value    = c ? c.name : '';
            $('fWatch').value   = c ? (c.watch_channel || '') : '';
            $('fActive').checked = c ? !!c.is_active : true;
            if (MULTI) $('fCompany').value = c && c.tenant_id ? String(c.tenant_id) : '';
            // Secrets are never sent to the browser. On edit the fields show a
            // placeholder rather than a value, and a blank field means "leave it".
            $('fBotToken').value = '';
            $('fSigning').value  = '';
            $('fBotToken').placeholder = c && c.has_credentials
                ? <?php echo json_encode(t('system.integrations.slack_unchanged')); ?> : 'xoxb-…';
            $('fSigning').placeholder = c && c.has_credentials
                ? <?php echo json_encode(t('system.integrations.slack_unchanged')); ?> : '';
            $('editModal').classList.add('open');
        }

        $('addBtn').addEventListener('click', () => openEdit(null));
        $('editCancel').addEventListener('click', () => $('editModal').classList.remove('open'));
        $('appClose').addEventListener('click', () => $('appModal').classList.remove('open'));

        $('editSave').addEventListener('click', function () {
            const name = $('fName').value.trim();
            if (!name) { alert(<?php echo json_encode(t('system.integrations.slack_name_required')); ?>); return; }
            const body = {
                id: $('fId').value || null,
                name: name,
                provider: 'slack',
                channel_type: 'slack',
                bot_token: $('fBotToken').value,
                signing_secret: $('fSigning').value,
                watch_channel: $('fWatch').value.trim(),
                tenant_id: MULTI ? ($('fCompany').value || null) : null,
                is_active: $('fActive').checked ? 1 : 0
            };
            this.disabled = true;
            fetch(API + 'save_channel.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(r => r.json()).then(d => {
                this.disabled = false;
                if (!d.success) { alert(d.error || 'Save failed'); return; }
                $('editModal').classList.remove('open');
                load();
            }).catch(() => { this.disabled = false; alert('Save failed'); });
        });

        // ---- the Slack app modal ---------------------------------------
        function openApp(c) {
            fetch(API + 'slack_manifest.php?channel=' + encodeURIComponent(c.id), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (!d.success) { alert(d.error || 'Could not build the manifest'); return; }
                    $('manifestBox').value = d.manifest;
                    $('webhookBox').value  = d.webhook_url;
                    const p = $('urlProblem');
                    if (d.url_problem) {
                        p.textContent = d.url_problem;
                        p.style.display = '';
                    } else {
                        p.style.display = 'none';
                    }
                    $('appModal').classList.add('open');
                })
                .catch(() => alert('Could not build the manifest'));
        }

        function copyFrom(el, btn) {
            el.select();
            el.setSelectionRange(0, 99999);
            try { document.execCommand('copy'); } catch (e) {}
            const was = btn.textContent;
            btn.textContent = <?php echo json_encode(t('common.copied')); ?>;
            setTimeout(() => { btn.textContent = was; }, 1500);
        }
        $('copyManifest').addEventListener('click', function () { copyFrom($('manifestBox'), this); });
        $('copyUrl').addEventListener('click', function () { copyFrom($('webhookBox'), this); });

        // ---- the health check --------------------------------------------
        // Replaces what used to be a one-line "connected as @x" alert. Everything
        // it reports is something that can be silently wrong: the token works,
        // tickets even arrive, and one thing quietly is not right.
        let diagChannel = null;

        function runDiagnostics(c) {
            diagChannel = c;
            $('diagBody').innerHTML = '<div class="diag-running">' +
                <?php echo json_encode(t('system.integrations.slack_diag_running')); ?> + '</div>';
            $('diagRerun').disabled = true;
            $('diagModal').classList.add('open');

            fetch(API + 'slack_diagnose.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: c.id })
            }).then(r => r.json()).then(d => {
                $('diagRerun').disabled = false;
                if (!d.success) {
                    $('diagBody').innerHTML = '<div class="diag-summary fail">' + esc(d.error || 'Check failed') + '</div>';
                    return;
                }
                const summary = {
                    ok:   <?php echo json_encode(t('system.integrations.slack_diag_all_ok')); ?>,
                    warn: <?php echo json_encode(t('system.integrations.slack_diag_some_warn')); ?>,
                    fail: <?php echo json_encode(t('system.integrations.slack_diag_some_fail')); ?>
                }[d.overall];
                const mark = { ok: '✓', warn: '!', fail: '✕', skip: '–' };

                $('diagBody').innerHTML =
                    '<div class="diag-summary ' + d.overall + '">' + esc(summary) + '</div>' +
                    d.checks.map(k =>
                        '<div class="diag-row">'
                        + '<div class="diag-icon ' + k.status + '">' + mark[k.status] + '</div>'
                        + '<div>'
                        +   '<div class="diag-label">' + esc(k.label) + '</div>'
                        +   '<div class="diag-detail">' + esc(k.detail) + '</div>'
                        +   (k.fix ? '<div class="diag-fix">' + esc(k.fix) + '</div>' : '')
                        + '</div></div>'
                    ).join('');
            }).catch(() => {
                $('diagRerun').disabled = false;
                $('diagBody').innerHTML = '<div class="diag-summary fail">Check failed</div>';
            });
        }

        $('diagClose').addEventListener('click', () => $('diagModal').classList.remove('open'));
        $('diagRerun').addEventListener('click', () => { if (diagChannel) runDiagnostics(diagChannel); });

        // ---- row actions -------------------------------------------------
        $('chanRows').addEventListener('click', function (e) {
            const btn = e.target.closest('button');
            if (!btn) return;
            const find = id => channels.filter(c => String(c.id) === String(id))[0];

            if (btn.dataset.edit) { openEdit(find(btn.dataset.edit)); return; }
            if (btn.dataset.app)  { openApp(find(btn.dataset.app));  return; }

            if (btn.dataset.del) {
                const c = find(btn.dataset.del);
                if (!confirm(<?php echo json_encode(t('system.integrations.slack_delete_confirm')); ?>.replace('{name}', c.name))) return;
                fetch(API + 'delete_channel.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: c.id })
                }).then(r => r.json()).then(d => {
                    if (!d.success) { alert(d.error || 'Delete failed'); return; }
                    load();
                });
                return;
            }

            if (btn.dataset.test) { runDiagnostics(find(btn.dataset.test)); return; }
        });

        load();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
