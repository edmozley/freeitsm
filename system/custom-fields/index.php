<?php
/**
 * System - Custom fields (GH #279).
 *
 * The fields an administrator makes up for companies and for people - an
 * account manager, a support tier, a badge number - on the shared TypedFields
 * engine, as ticket fields are. Two tabs:
 *   - Companies: one list for the whole install (Ed, 15 Nov 2026);
 *   - People: shared by every company, or one company's own.
 * Answers are entered on the company (System -> Companies) and the person
 * (the person editor), and through the REST API as custom_fields.
 *
 * Rules: includes/services/record_fields.php. Endpoint: api/system/custom_fields.php.
 * Design: wiki Custom-Fields-Companies-People-Design.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'custom-fields';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
$e = fn(string $k, array $p = []) => htmlspecialchars(t('system.custom_fields.' . $k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.custom_fields.heading'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=95">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Layout: assets/css/system-page.css (.sys-page, .sys-page-head). Only the scroll box is local. */
        .cf-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f7fa); }
        .cf-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border, #e5e7eb); margin: 0 0 16px; }
        .cf-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 9px 14px; font-size: 13px; font-weight: 600; color: var(--text-muted, #666); cursor: pointer; }
        .cf-tab.active { color: var(--sys-accent, #546e7a); border-bottom-color: var(--sys-accent, #546e7a); }
        .cf-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin: 0 0 12px; flex-wrap: wrap; }
        .cf-intro { font-size: 13px; color: var(--text-muted, #555); margin: 0; max-width: 760px; }
        table.cf { width: 100%; border-collapse: collapse; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; }
        table.cf th, table.cf td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); font-size: 13px; color: var(--text, #444); vertical-align: middle; }
        table.cf th { background: var(--surface-3, #f9fafb); font-weight: 600; font-size: 12px; }
        table.cf tr:last-child td { border-bottom: none; }
        .cf-key { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; color: var(--text-dim, #888); }
        .cf-dim { color: var(--text-dim, #888); font-size: 12px; }
        .cf-actions { white-space: nowrap; text-align: right; }
        .cf-actions button { background: none; border: 1px solid var(--border, #ddd); border-radius: 5px; padding: 4px 8px; font-size: 12px; cursor: pointer; color: var(--text, #444); margin-left: 4px; }
        .cf-actions button:hover { background: var(--surface-hover, #f3f4f6); }
        .cf-empty td { text-align: center; color: var(--text-faint, #aaa); padding: 24px; font-style: italic; }
        .cf-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .cf-modal-overlay.open { display: flex; }
        .form-field { margin-bottom: 14px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: var(--text-faint, #999); font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text], .form-field input[type=number], .form-field select, .form-field textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); }
        .form-field textarea { min-height: 90px; }
        .checkbox-field { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 13px; color: var(--text, #444); }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .cf-error { color: var(--danger-text, #c0392b); font-size: 13px; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="custom-fields">
    <?php include '../includes/header.php'; ?>

    <div class="cf-wrap sys-page">
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo $e('heading'); ?></h1>
            <p class="sys-subtitle"><?php echo $e('intro'); ?></p>
        </div></div>

        <div class="cf-tabs" role="tablist">
            <button type="button" class="cf-tab active" data-entity="company" role="tab"><?php echo $e('tab_companies'); ?></button>
            <button type="button" class="cf-tab" data-entity="person" role="tab"><?php echo $e('tab_people'); ?></button>
        </div>
        <div class="cf-bar">
            <p class="cf-intro" id="cfTabIntro"></p>
            <button type="button" class="btn btn-primary" id="cfAdd"><?php echo htmlspecialchars(t('common.add')); ?></button>
        </div>
        <div id="cfList"></div>
    </div>

    <div class="cf-modal-overlay" id="cfModal">
        <div class="sys-modal" role="dialog" aria-modal="true">
            <div class="sys-modal-head" id="cfModalTitle"></div>
            <div class="sys-modal-body">
                <input type="hidden" id="cfId">
                <div class="form-field">
                    <label for="cfLabel"><?php echo $e('f_label'); ?></label>
                    <input type="text" id="cfLabel" maxlength="150" autocomplete="off">
                </div>
                <div class="form-field">
                    <label for="cfType"><?php echo $e('f_type'); ?></label>
                    <select id="cfType">
                        <?php foreach (['text', 'number', 'date', 'boolean', 'dropdown', 'url', 'email'] as $ty): ?>
                            <option value="<?php echo $ty; ?>"><?php echo $e('type_' . $ty); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint" id="cfTypeLocked" hidden><?php echo $e('type_locked'); ?></div>
                </div>
                <label class="checkbox-field" id="cfMultiWrap"><input type="checkbox" id="cfMultiline"> <?php echo $e('f_multiline'); ?></label>
                <div class="form-field" id="cfNumberWrap">
                    <label for="cfDecimals"><?php echo $e('f_decimals'); ?></label>
                    <input type="number" id="cfDecimals" min="0" max="4" style="max-width:120px">
                    <label for="cfUnit" style="margin-top:8px"><?php echo $e('f_unit'); ?></label>
                    <input type="text" id="cfUnit" maxlength="20" style="max-width:200px">
                </div>
                <div class="form-field" id="cfDateWrap">
                    <label for="cfDateMode"><?php echo $e('f_date_mode'); ?></label>
                    <select id="cfDateMode">
                        <option value="date"><?php echo $e('mode_date'); ?></option>
                        <option value="datetime"><?php echo $e('mode_datetime'); ?></option>
                        <option value="time"><?php echo $e('mode_time'); ?></option>
                    </select>
                </div>
                <div class="form-field" id="cfOptionsWrap">
                    <label for="cfOptions"><?php echo $e('f_options'); ?></label>
                    <div class="hint"><?php echo $e('f_options_hint'); ?></div>
                    <textarea id="cfOptions"></textarea>
                </div>
                <div class="form-field">
                    <label for="cfHelp"><?php echo $e('f_help'); ?></label>
                    <input type="text" id="cfHelp" maxlength="500" autocomplete="off">
                </div>
                <label class="checkbox-field"><input type="checkbox" id="cfRequired"> <?php echo $e('f_required'); ?></label>
                <div class="form-field" id="cfCompanyWrap">
                    <label for="cfCompany"><?php echo $e('f_company'); ?></label>
                    <div class="hint"><?php echo $e('f_company_hint'); ?></div>
                    <select id="cfCompany"></select>
                </div>
                <p class="cf-error" id="cfErr"></p>
            </div>
            <div class="sys-modal-foot">
                <button type="button" class="btn btn-secondary" id="cfCancel"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="cfSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js?v=7"></script>
    <script>
    (function () {
        'use strict';
        var T = function (k, p) { return window.t('system.custom_fields.' + k, p); };
        var API = '../../api/system/custom_fields.php';
        var entity = 'company', fields = [], companies = [], multi = false;
        function $(id) { return document.getElementById(id); }
        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        async function post(body) {
            var r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            return r.json();
        }

        async function load() {
            $('cfTabIntro').textContent = T(entity === 'company' ? 'intro_companies' : 'intro_people');
            var d;
            try { d = await (await fetch(API + '?entity=' + entity, { credentials: 'same-origin' })).json(); }
            catch (e) { $('cfList').innerHTML = '<p class="cf-error">' + esc(T('load_failed')) + '</p>'; return; }
            if (!d.success) { $('cfList').innerHTML = '<p class="cf-error">' + esc(d.error || T('load_failed')) + '</p>'; return; }
            if (d.ready === false) { $('cfList').innerHTML = '<p class="cf-error">' + esc(T('not_ready')) + '</p>'; return; }
            fields = d.fields || [];
            if (entity === 'person') { companies = d.companies || []; multi = !!d.multi; }
            render();
        }

        function render() {
            var person = entity === 'person' && multi;
            var html = '<table class="cf"><thead><tr><th>' + esc(T('col_name')) + '</th><th>' + esc(T('col_type')) + '</th>'
                + (person ? '<th>' + esc(T('col_company')) + '</th>' : '')
                + '<th>' + esc(T('col_required')) + '</th><th>' + esc(T('col_answers')) + '</th><th></th></tr></thead><tbody>';
            if (!fields.length) html += '<tr class="cf-empty"><td colspan="' + (person ? 6 : 5) + '">' + esc(T('none')) + '</td></tr>';
            fields.forEach(function (f, i) {
                html += '<tr><td><strong>' + esc(f.label) + '</strong><div class="cf-key">' + esc(f.key) + '</div></td>'
                    + '<td>' + esc(T('type_' + f.type)) + '</td>'
                    + (person ? '<td>' + esc(f.tenant_id ? (f.company_name || '#' + f.tenant_id) : T('every_company')) + '</td>' : '')
                    + '<td>' + esc(f.required_on_create ? T('yes') : T('no')) + '</td>'
                    + '<td><span class="cf-dim">' + Number(f.answered || 0) + '</span></td>'
                    + '<td class="cf-actions">'
                    + (i > 0 ? '<button type="button" data-up="' + i + '" title="' + esc(T('up')) + '">&uarr;</button>' : '')
                    + (i < fields.length - 1 ? '<button type="button" data-down="' + i + '" title="' + esc(T('down')) + '">&darr;</button>' : '')
                    + '<button type="button" data-edit="' + i + '">' + esc(window.t('common.edit')) + '</button>'
                    + '<button type="button" data-del="' + i + '">' + esc(window.t('common.delete')) + '</button></td></tr>';
            });
            $('cfList').innerHTML = html + '</tbody></table>';
        }

        function showTypeBits() {
            var ty = $('cfType').value;
            $('cfMultiWrap').hidden = ty !== 'text';
            $('cfNumberWrap').hidden = ty !== 'number';
            $('cfDateWrap').hidden = ty !== 'date';
            $('cfOptionsWrap').hidden = ty !== 'dropdown';
        }

        function openModal(f) {
            $('cfModalTitle').textContent = f ? T('edit_title') : T('add_title');
            $('cfId').value = f ? f.id : '';
            $('cfLabel').value = f ? f.label : '';
            $('cfType').value = f ? f.type : 'text';
            var locked = !!(f && f.answered > 0);
            $('cfType').disabled = locked;
            $('cfTypeLocked').hidden = !locked;
            var c = (f && f.config) || {};
            $('cfMultiline').checked = !!c.multiline;
            $('cfDecimals').value = c.decimals != null ? c.decimals : '';
            $('cfUnit').value = c.unit || '';
            $('cfDateMode').value = c.date_mode || 'date';
            $('cfOptions').value = f ? (f.options || []).join('\n') : '';
            $('cfHelp').value = f ? (f.help_text || '') : '';
            $('cfRequired').checked = !!(f && f.required_on_create);
            // The company a people field belongs to is chosen once, when it is made.
            var showCompany = entity === 'person' && multi && !f;
            $('cfCompanyWrap').hidden = !showCompany;
            if (showCompany) {
                $('cfCompany').innerHTML = '<option value="">' + esc(T('every_company')) + '</option>'
                    + companies.map(function (co) { return '<option value="' + co.id + '">' + esc(co.name) + '</option>'; }).join('');
            }
            $('cfErr').textContent = '';
            showTypeBits();
            $('cfModal').classList.add('open');
            $('cfLabel').focus();
        }
        function closeModal() { $('cfModal').classList.remove('open'); }

        async function save() {
            var ty = $('cfType').value;
            var body = {
                action: 'save', entity: entity, id: Number($('cfId').value || 0),
                label: $('cfLabel').value.trim(), field_type: ty,
                config: { multiline: $('cfMultiline').checked, decimals: $('cfDecimals').value, unit: $('cfUnit').value.trim(), date_mode: $('cfDateMode').value },
                options: $('cfOptions').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
                help_text: $('cfHelp').value.trim(), is_required: $('cfRequired').checked ? 1 : 0
            };
            if (!$('cfCompanyWrap').hidden && $('cfCompany').value) body.tenant_id = Number($('cfCompany').value);
            $('cfSave').disabled = true;
            try {
                var d = await post(body);
                if (!d.success) { $('cfErr').textContent = d.error || T('save_failed'); return; }
                window.showToast(T('saved'), 'success');
                closeModal();
                load();
            } catch (e) { $('cfErr').textContent = T('save_failed'); }
            finally { $('cfSave').disabled = false; }
        }

        async function del(f) {
            var ok = await window.showConfirm({ title: window.t('common.delete'), message: T('delete_confirm', { name: f.label, n: f.answered || 0 }), okLabel: window.t('common.delete'), okClass: 'danger' });
            if (!ok) return;
            var d = await post({ action: 'delete', entity: entity, id: f.id });
            if (!d.success) { window.showToast(d.error || T('save_failed'), 'error'); return; }
            load();
        }

        async function move(i, by) {
            var j = i + by;
            if (j < 0 || j >= fields.length) return;
            var tmp = fields[i]; fields[i] = fields[j]; fields[j] = tmp;
            render();
            var d = await post({ action: 'reorder', entity: entity, ids: fields.map(function (f) { return f.id; }) });
            if (!d.success) { window.showToast(d.error || T('save_failed'), 'error'); load(); }
        }

        document.querySelectorAll('.cf-tab').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('.cf-tab').forEach(function (x) { x.classList.toggle('active', x === b); });
                entity = b.dataset.entity;
                load();
            });
        });
        $('cfList').addEventListener('click', function (ev) {
            var b = ev.target.closest('button');
            if (!b) return;
            if (b.dataset.edit !== undefined) openModal(fields[Number(b.dataset.edit)]);
            else if (b.dataset.del !== undefined) del(fields[Number(b.dataset.del)]);
            else if (b.dataset.up !== undefined) move(Number(b.dataset.up), -1);
            else if (b.dataset.down !== undefined) move(Number(b.dataset.down), 1);
        });
        $('cfAdd').addEventListener('click', function () { openModal(null); });
        $('cfType').addEventListener('change', showTypeBits);
        $('cfCancel').addEventListener('click', closeModal);
        $('cfSave').addEventListener('click', save);
        load();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
