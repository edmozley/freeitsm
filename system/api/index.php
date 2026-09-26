<?php
/**
 * System - API
 * Manage REST API v1 keys: create keys with granular per-resource permissions,
 * company scoping, expiry and rate limits. Links to the interactive docs page.
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

$current_page = 'api';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// The live base URL of the v1 API for this deployment.
$scheme = requestScheme();
$apiBaseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/v1';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('system.api.browser_title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=72">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <style>
        /* System module accent (blue-grey) — pin the generic --accent to it. */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .api-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 30px 0; }

        .settings-card { background: var(--surface, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); margin-bottom: 24px; }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 20px 0; line-height: 1.5; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .info-note { background: #f5f7fa; border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 14px 16px; font-size: 12px; color: var(--text-muted, #666); line-height: 1.6; }
        .info-note strong { color: var(--text, #333); }
        .base-url-box { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .base-url-box code { flex: 1; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 8px 10px; font-size: 12px; color: var(--text, #333); overflow-x: auto; white-space: nowrap; }

        .keys-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.keys { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.keys th { text-align: left; color: var(--text-dim, #888); font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        table.keys td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: middle; }
        table.keys tr:last-child td { border-bottom: none; }
        .key-prefix { font-family: Consolas, Monaco, monospace; font-size: 12px; color: var(--text-dim, #888); }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-badge.on { background: #e8f5e9; color: #2e7d32; }
        .status-badge.off { background: #f0f0f0; color: #999; }
        .status-badge.expired { background: #fff3e0; color: #e65100; }
        .perm-count { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e3f2fd; color: #1565c0; }
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: 13px; border-radius: 4px; }
        .table-action-btn:hover { background: var(--sys-accent-soft, #eceff1); }
        .table-action-btn.danger:hover { background: #ffebee; color: #c62828; }
        .empty-row td { text-align: center; color: var(--text-faint, #aaa); padding: 24px; font-style: italic; }

        /* Modal — namespaced (apik-) so it doesn't inherit inbox.css's global
           .modal framework (opacity:0/visibility:hidden by default). */
        .apik-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .apik-modal-overlay.open { display: flex; }
        .apik-modal { background: var(--surface, #fff); border-radius: 10px; width: 640px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .apik-modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .apik-modal-body { padding: 20px 24px; }
        .apik-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: var(--text-faint, #999); font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text], .form-field input[type=number], .form-field input[type=date], .form-field select {
            width: 100%; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333);
        }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .form-2col { display: flex; gap: 14px; }
        .form-2col .form-field { flex: 1; }

        /* Permission matrix */
        .perm-matrix { border: 1px solid var(--border-soft, #eee); border-radius: 6px; overflow: hidden; }
        .perm-row { display: flex; align-items: flex-start; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); gap: 12px; }
        .perm-row:last-child { border-bottom: none; }
        .perm-row:nth-child(even) { background: #fafbfc; }
        .perm-resource { width: 170px; flex: none; font-size: 13px; font-weight: 600; color: var(--text, #444); padding-top: 2px; }
        .perm-actions { display: flex; flex-wrap: wrap; gap: 4px 16px; flex: 1; }
        .perm-action { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted, #555); cursor: pointer; }
        .perm-action input { margin: 0; cursor: pointer; }
        .perm-toolbar { display: flex; justify-content: flex-end; gap: 14px; margin: 0 0 8px 0; font-size: 12px; }
        .perm-toolbar a { color: var(--sys-accent, #546e7a); cursor: pointer; text-decoration: underline; }

        /* Company scope */
        .scope-options { display: flex; flex-direction: column; gap: 8px; }
        .scope-option { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #444); cursor: pointer; }
        .company-checks { margin: 8px 0 0 24px; display: flex; flex-direction: column; gap: 6px; max-height: 140px; overflow-y: auto; }
        .company-checks label { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted, #555); cursor: pointer; }

        /* New-key reveal */
        .newkey-box { background: #f5f7fa; border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 14px 16px; margin-top: 10px; color: var(--text, #333); }
        .newkey-box code { display: block; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 10px 12px; font-size: 13px; color: var(--text, #333); word-break: break-all; margin: 8px 0; }
        .newkey-warning { font-size: 12px; color: #c62828; font-weight: 600; }

        /* ---------- Dark mode overrides ----------
           Light values above are deliberately unchanged; these only apply in dark. */
        [data-theme-mode="dark"] .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .btn-secondary { color: #cfd8dc; }
        [data-theme-mode="dark"] .btn-secondary:hover { background: #37474f; }
        [data-theme-mode="dark"] .add-btn:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .info-note { background: #20242b; }
        [data-theme-mode="dark"] .newkey-box { background: #20242b; }
        [data-theme-mode="dark"] .newkey-warning { color: #fca5a5; }
        [data-theme-mode="dark"] .perm-row:nth-child(even) { background: #20242b; }
        [data-theme-mode="dark"] .status-badge.on { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .status-badge.off { background: #2a3039; color: #8b95a3; }
        [data-theme-mode="dark"] .status-badge.expired { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .perm-count { background: #14324a; color: #7fc4f5; }
        [data-theme-mode="dark"] .table-action-btn { color: #90a4ae; }
        [data-theme-mode="dark"] .table-action-btn.danger:hover { background: #3a1a1d; color: #fca5a5; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=152">
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="api">
    <?php include '../includes/header.php'; ?>

    <div class="api-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.api.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.api.subtitle')); ?></p>

        <div class="settings-card">
            <h3><?php echo htmlspecialchars(t('system.api.base_url')); ?></h3>
            <p class="card-desc"><?php echo t('system.api.base_url_desc'); ?></p>
            <div class="base-url-box">
                <code id="apiBaseUrl"><?php echo htmlspecialchars($apiBaseUrl); ?></code>
                <button class="btn btn-secondary" id="copyBaseBtn"><?php echo htmlspecialchars(t('common.copy')); ?></button>
                <a class="btn btn-primary" href="docs.php"><?php echo htmlspecialchars(t('system.api.documentation')); ?></a>
            </div>
            <p class="card-desc" style="margin-top:14px;">
                <?php echo t('system.api.docs_desc'); ?>
            </p>
        </div>

        <div class="settings-card">
            <h3><?php echo htmlspecialchars(t('system.api.openapi')); ?></h3>
            <p class="card-desc">
                <?php echo t('system.api.openapi_desc'); ?>
            </p>
            <div class="base-url-box">
                <code><?php echo htmlspecialchars($apiBaseUrl); ?>/openapi.json</code>
                <a class="btn btn-secondary" href="<?php echo htmlspecialchars($apiBaseUrl); ?>/openapi.json" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('system.api.view_json')); ?></a>
                <a class="btn btn-secondary" href="<?php echo htmlspecialchars($apiBaseUrl); ?>/openapi.yaml" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('system.api.view_yaml')); ?></a>
            </div>
        </div>

        <div class="settings-card">
            <div class="keys-head">
                <div>
                    <h3 style="margin:0;"><?php echo htmlspecialchars(t('system.api.keys')); ?></h3>
                    <p class="card-desc" style="margin:4px 0 0;"><?php echo htmlspecialchars(t('system.api.keys_desc')); ?></p>
                </div>
                <button class="add-btn" id="addKeyBtn"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <div class="info-note" id="tableNotReady" style="display:none; margin-bottom:14px;">
                <?php echo t('system.api.not_ready'); ?>
            </div>
            <table class="keys">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('system.api.col_name')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_key')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_acts_as')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_permissions')); ?></th>
                        <th class="company-col" style="display:none;"><?php echo htmlspecialchars(t('system.api.col_companies')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.api.col_status')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_last_used')); ?></th><th style="text-align:right;"><?php echo htmlspecialchars(t('system.api.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="keysBody">
                    <tr class="empty-row"><td colspan="8"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / edit modal -->
    <div class="apik-modal-overlay" id="keyModal">
        <div class="apik-modal">
            <div class="apik-modal-header" id="modalTitle"><?php echo htmlspecialchars(t('system.api.new_key')); ?></div>
            <div class="apik-modal-body">
                <input type="hidden" id="fKeyId">
                <div class="form-2col">
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.col_name')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.name_hint')); ?></div>
                        <input type="text" id="fName" placeholder="<?php echo htmlspecialchars(t('system.api.name_ph')); ?>">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.col_acts_as')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.acts_as_hint')); ?></div>
                        <select id="fAnalyst"></select>
                    </div>
                </div>

                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.api.col_permissions')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.api.perms_hint')); ?></div>
                    <div class="perm-toolbar"><a id="permAll"><?php echo htmlspecialchars(t('system.api.select_all')); ?></a><a id="permNone"><?php echo htmlspecialchars(t('system.api.clear_all')); ?></a></div>
                    <div class="perm-matrix" id="permMatrix"></div>
                </div>

                <div class="form-field" id="scopeField" style="display:none;">
                    <label><?php echo htmlspecialchars(t('system.api.scope')); ?></label>
                    <div class="scope-options">
                        <label class="scope-option"><input type="radio" name="scope" value="all" checked> <?php echo htmlspecialchars(t('system.api.scope_all')); ?></label>
                        <label class="scope-option"><input type="radio" name="scope" value="specific"> <?php echo htmlspecialchars(t('system.api.scope_specific')); ?></label>
                    </div>
                    <div class="company-checks" id="companyChecks" style="display:none;"></div>
                </div>

                <div class="form-2col">
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.expires')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.expires_hint')); ?></div>
                        <input type="date" id="fExpires">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.rate_limit')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.rate_limit_hint')); ?></div>
                        <input type="number" id="fRateLimit" min="1" placeholder="60">
                    </div>
                </div>
            </div>
            <div class="apik-modal-footer">
                <button class="btn btn-secondary" id="cancelBtn"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- New-key reveal modal -->
    <div class="apik-modal-overlay" id="revealModal">
        <div class="apik-modal">
            <div class="apik-modal-header"><?php echo htmlspecialchars(t('system.api.created')); ?></div>
            <div class="apik-modal-body">
                <div class="newkey-box">
                    <?php echo htmlspecialchars(t('system.api.your_new_key')); ?>
                    <code id="newKeyValue"></code>
                    <div class="newkey-warning"><?php echo htmlspecialchars(t('system.api.copy_now')); ?></div>
                </div>
            </div>
            <div class="apik-modal-footer">
                <button class="btn btn-secondary" id="copyKeyBtn"><?php echo htmlspecialchars(t('common.copy')); ?></button>
                <button class="btn btn-primary" id="revealCloseBtn"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script>
    const API = '../../api/system/api_keys/';
    /** Shorthand for this page's namespace; window.t() comes from i18n.js. */
    const ap = (k, p) => t('system.api.' + k, p);
    let catalog = {};
    let analysts = [];
    let companies = [];
    let multiTenant = false;
    let keys = [];

    async function loadKeys() {
        const res = await fetch(API + 'list_keys.php');
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Failed to load keys'); return; }
        catalog = data.catalog; analysts = data.analysts; companies = data.companies;
        multiTenant = data.multi_tenant; keys = data.keys;
        document.getElementById('tableNotReady').style.display = data.table_ready ? 'none' : 'block';
        document.querySelectorAll('.company-col').forEach(el => el.style.display = multiTenant ? '' : 'none');
        renderKeys();
    }

    function permSummary(perms) {
        let n = 0;
        Object.values(perms || {}).forEach(a => n += a.length);
        return n;
    }

    function esc(s) {
        const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML;
    }

    function renderKeys() {
        const body = document.getElementById('keysBody');
        const colspan = multiTenant ? 8 : 7;
        if (!keys.length) {
            body.innerHTML = `<tr class="empty-row"><td colspan="${colspan}">${esc(ap('empty'))}</td></tr>`;
            return;
        }
        const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
        body.innerHTML = keys.map(k => {
            const expired = k.expires_at && k.expires_at <= now;
            const status = expired ? '<span class="status-badge expired">' + esc(ap('status_expired')) + '</span>'
                : k.active ? '<span class="status-badge on">' + esc(ap('status_active')) + '</span>'
                : '<span class="status-badge off">' + esc(ap('status_disabled')) + '</span>';
            let companyCell = '';
            if (multiTenant) {
                const names = k.company_ids === null ? ap('all')
                    : k.company_ids.map(id => (companies.find(c => c.id === id) || {name: '#' + id}).name).join(', ');
                companyCell = `<td>${esc(names)}</td>`;
            }
            // last_used_at is a server-stamped UTC timestamp (kind-1) — show in the analyst's zone.
        const lu = parseUTCDate(k.last_used_at);
        const lastUsed = lu ? esc(fmtDateTime(lu)) : esc(ap('never'));
            return `<tr>
                <td><strong>${esc(k.name)}</strong></td>
                <td class="key-prefix">${esc(k.key_prefix)}…</td>
                <td>${esc(k.analyst_name || '')}</td>
                <td><span class="perm-count">${esc(ap('perm_granted', { n: permSummary(k.permissions) }))}</span></td>
                ${companyCell}
                <td>${status}</td>
                <td>${lastUsed}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="table-action-btn" onclick="openEdit(${k.id})">${esc(t('common.edit'))}</button>
                    <button class="table-action-btn" onclick="toggleKey(${k.id}, ${k.active ? 'false' : 'true'})">${esc(k.active ? ap('disable') : ap('enable'))}</button>
                    <button class="table-action-btn danger" onclick="deleteKey(${k.id})">${esc(t('common.delete'))}</button>
                </td>
            </tr>`;
        }).join('');
    }

    // ---- Modal ----
    function buildMatrix(selected) {
        const matrix = document.getElementById('permMatrix');
        matrix.innerHTML = Object.entries(catalog).map(([resource, def]) => {
            const actions = Object.entries(def.actions).map(([action, desc]) => {
                const checked = selected && selected[resource] && selected[resource].includes(action) ? 'checked' : '';
                return `<label class="perm-action" title="${esc(desc)}">
                    <input type="checkbox" data-resource="${esc(resource)}" data-action="${esc(action)}" ${checked}> ${esc(action)}
                </label>`;
            }).join('');
            return `<div class="perm-row"><div class="perm-resource">${esc(def.label)}</div><div class="perm-actions">${actions}</div></div>`;
        }).join('');
    }

    function readMatrix() {
        const perms = {};
        document.querySelectorAll('#permMatrix input:checked').forEach(cb => {
            const r = cb.dataset.resource;
            (perms[r] = perms[r] || []).push(cb.dataset.action);
        });
        return perms;
    }

    function buildScope(companyIds) {
        const field = document.getElementById('scopeField');
        field.style.display = multiTenant ? '' : 'none';
        if (!multiTenant) return;
        const specific = Array.isArray(companyIds);
        document.querySelector('input[name=scope][value=all]').checked = !specific;
        document.querySelector('input[name=scope][value=specific]').checked = specific;
        const checks = document.getElementById('companyChecks');
        checks.style.display = specific ? '' : 'none';
        checks.innerHTML = companies.map(c => {
            const checked = specific && companyIds.includes(c.id) ? 'checked' : '';
            return `<label><input type="checkbox" value="${c.id}" ${checked}> ${esc(c.name)}</label>`;
        }).join('');
    }

    function openModal(key) {
        document.getElementById('modalTitle').textContent = key ? ap('edit_key') : ap('new_key');
        document.getElementById('fKeyId').value = key ? key.id : '';
        document.getElementById('fName').value = key ? key.name : '';
        document.getElementById('fExpires').value = key && key.expires_at ? key.expires_at.slice(0, 10) : '';
        document.getElementById('fRateLimit').value = key && key.rate_limit_per_minute ? key.rate_limit_per_minute : '';
        const sel = document.getElementById('fAnalyst');
        sel.innerHTML = analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        if (key) sel.value = key.analyst_id;
        buildMatrix(key ? key.permissions : null);
        buildScope(key ? key.company_ids : null);
        document.getElementById('keyModal').classList.add('open');
    }

    function closeModal() { document.getElementById('keyModal').classList.remove('open'); }

    window.openEdit = function (id) {
        const key = keys.find(k => k.id === id);
        if (key) openModal(key);
    };

    window.toggleKey = async function (id, active) {
        const res = await fetch(API + 'update_key.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id, active: active === true || active === 'true'})
        });
        const data = await res.json();
        if (!data.success) { alert(data.error || ap('update_failed')); return; }
        loadKeys();
    };

    window.deleteKey = async function (id) {
        const key = keys.find(k => k.id === id);
        if (!confirm(ap('confirm_delete', { name: key ? key.name : id }))) return;
        const res = await fetch(API + 'delete_key.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id})});
        const data = await res.json();
        if (!data.success) { alert(data.error || ap('delete_failed')); return; }
        loadKeys();
    };

    async function saveKey() {
        const id = document.getElementById('fKeyId').value;
        const payload = {
            name: document.getElementById('fName').value.trim(),
            analyst_id: parseInt(document.getElementById('fAnalyst').value, 10),
            permissions: readMatrix(),
            expires_at: document.getElementById('fExpires').value || null,
            rate_limit_per_minute: document.getElementById('fRateLimit').value || null
        };
        if (multiTenant) {
            const specific = document.querySelector('input[name=scope][value=specific]').checked;
            payload.company_ids = specific
                ? Array.from(document.querySelectorAll('#companyChecks input:checked')).map(cb => parseInt(cb.value, 10))
                : null;
            if (specific && !payload.company_ids.length) { alert(ap('need_company')); return; }
        }
        if (!payload.name) { alert(ap('need_name')); return; }
        if (!Object.keys(payload.permissions).length) { alert(ap('need_permission')); return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        try {
            if (id) payload.id = parseInt(id, 10);
            const res = await fetch(API + (id ? 'update_key.php' : 'create_key.php'), {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.success) { alert(data.error || ap('save_failed')); return; }
            closeModal();
            if (!id && data.key) {
                document.getElementById('newKeyValue').textContent = data.key;
                document.getElementById('revealModal').classList.add('open');
            }
            loadKeys();
        } finally {
            btn.disabled = false;
        }
    }

    // ---- Wire up ----
    document.getElementById('addKeyBtn').addEventListener('click', () => openModal(null));
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('saveBtn').addEventListener('click', saveKey);
    document.getElementById('permAll').addEventListener('click', () => document.querySelectorAll('#permMatrix input').forEach(cb => cb.checked = true));
    document.getElementById('permNone').addEventListener('click', () => document.querySelectorAll('#permMatrix input').forEach(cb => cb.checked = false));
    document.querySelectorAll('input[name=scope]').forEach(r => r.addEventListener('change', () => {
        document.getElementById('companyChecks').style.display =
            document.querySelector('input[name=scope][value=specific]').checked ? '' : 'none';
    }));
    // ⚠️ These two copied in silence and reported nothing either way - and one
    // of them is an API KEY shown exactly once. copyToClipboard says whether it
    // worked; see clipboard.js.
    function copyInto(btnId, sourceId, failMsg) {
        document.getElementById(btnId).addEventListener('click', () => {
            const b = document.getElementById(btnId);
            copyToClipboard(document.getElementById(sourceId).textContent).then(ok => {
                const old = b.textContent;
                b.textContent = ok ? t('common.copied') : ap('copy_failed');
                setTimeout(() => { b.textContent = old; }, 1600);
                if (!ok && failMsg) alert(failMsg);
            });
        });
    }
    copyInto('copyBaseBtn', 'apiBaseUrl', null);
    copyInto('copyKeyBtn', 'newKeyValue',
             ap('copy_key_failed'));
    document.getElementById('revealCloseBtn').addEventListener('click', () => {
        if (confirm(ap('confirm_copied'))) {
            document.getElementById('revealModal').classList.remove('open');
        }
    });

    loadKeys();
    </script>
    <script src="../../assets/js/mobile.js?v=65"></script>
</body>
</html>
