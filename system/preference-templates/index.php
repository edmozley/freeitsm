<?php
/**
 * System -> Preference templates (discussion #124).
 *
 * Named sets of preferences an administrator gives to people in one go, and
 * optionally to every new analyst. A template is taken from somebody's real
 * preferences - the Preferences page is the editor - so this page only names
 * templates, chooses which sections they hold, and gives them out.
 * The rules live in includes/preference_templates.php; the API is
 * api/system/preference_templates.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
$current_page = 'preference-templates';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
$ptT = fn(string $k) => t('system.pref_templates.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', $ptT('title'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            display: flex; flex-direction: column;
        }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--border, #cfd8dc); }
        .btn:disabled { opacity: .5; cursor: default; }

        /* Full width, like the other System settings pages. */
        .pt-container { flex: 1; min-height: 0; overflow-y: auto; width: 100%; box-sizing: border-box; padding: 20px 32px 40px; }
        .pt-intro { margin: 0 0 18px; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; max-width: 900px; }
        .pt-verify { margin-bottom: 18px; padding: 12px 14px; border-radius: 8px; font-size: 13px;
            background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); border: 1px solid var(--warning-border, #f2d675); }
        .pt-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 6px 20px 10px; }
        .pt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pt-table th { text-align: left; font-size: 12px; color: var(--text-muted, #888); font-weight: 600; padding: 10px 10px 8px; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        .pt-table td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: top; }
        .pt-table tr:last-child td { border-bottom: none; }
        .pt-name { font-weight: 600; color: var(--text, #333); }
        .pt-desc { font-size: 12.5px; color: var(--text-dim, #888); margin-top: 2px; }
        .pt-chips { display: flex; flex-wrap: wrap; gap: 4px; }
        .pt-chip { font-size: 11.5px; padding: 2px 8px; border-radius: 10px; background: var(--surface-2, #eceff1); color: var(--text-muted, #555); white-space: nowrap; }
        .pt-badge { font-size: 11.5px; padding: 2px 8px; border-radius: 10px; background: var(--accent-soft, #e3f2fd); color: var(--accent, #546e7a); font-weight: 600; white-space: nowrap; }
        .pt-actions { white-space: nowrap; text-align: right; }
        .pt-actions button { background: none; border: 1px solid var(--border, #ddd); border-radius: 5px; padding: 4px 10px; font-size: 12.5px; cursor: pointer; color: var(--text, #333); margin-left: 4px; }
        .pt-actions button:hover { border-color: var(--accent, #546e7a); color: var(--accent, #546e7a); }
        .pt-actions button.danger:hover { border-color: #dc2626; color: #dc2626; }
        .pt-empty { padding: 28px 10px; text-align: center; color: var(--text-faint, #999); font-size: 13px; }
        .pt-updated { white-space: nowrap; color: var(--text-dim, #888); }

        .pt-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 2100; align-items: center; justify-content: center; }
        .pt-overlay.open { display: flex; }
        .pt-modal { background: var(--surface, #fff); border-radius: 10px; width: 620px; max-width: 94vw; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .pt-modal-head { padding: 18px 22px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .pt-modal-body { padding: 18px 22px; overflow-y: auto; }
        .pt-modal-foot { padding: 14px 22px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; }
        .pt-field { margin-bottom: 16px; }
        .pt-field > label, .pt-field .pt-label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .pt-field .hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; line-height: 1.45; }
        .pt-field input[type=text], .pt-field select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; font-family: inherit; background: var(--surface, #fff); color: var(--text, #333); }
        .pt-field input:focus, .pt-field select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .pt-label-row { display: flex; align-items: baseline; gap: 10px; }
        .pt-label-row .pt-label { margin: 0; }
        .pt-label-row button { background: none; border: none; padding: 0; font-size: 12px; color: var(--accent, #546e7a); cursor: pointer; }
        .pt-sections { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 4px 14px; margin-top: 6px; }
        .pt-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #444); padding: 3px 0; cursor: pointer; }
        .pt-check input:disabled + span { color: var(--text-faint, #aaa); }
        .pt-warn { margin: 0 0 12px; padding: 10px 12px; border-radius: 6px; font-size: 12.5px; background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); border: 1px solid var(--warning-border, #f2d675); }
        .pt-people { border: 1px solid var(--border, #ddd); border-radius: 6px; max-height: 300px; overflow-y: auto; padding: 4px 10px; margin-top: 8px; }
        .pt-count { font-size: 12px; color: var(--text-dim, #888); }

        @media (max-width: 768px) {
            .pt-container { padding: 14px 12px 30px; }
            .pt-panel { padding: 4px 10px 8px; overflow-x: auto; }
            .pt-col-updated, .pt-col-new { display: none; }
        }
    </style>
</head>
<body data-mobile-module="system" data-mobile-page="preference-templates">
    <?php include '../includes/header.php'; ?>
    <?php require_once __DIR__ . '/../../includes/action_row.php';
          renderActionRow($ptT('title'), [actionRowNew('PT.openEdit(null)', $ptT('new_tip'))]); ?>
    <div class="pt-container">
        <p class="pt-intro"><?php echo htmlspecialchars($ptT('intro')); ?></p>
        <div class="pt-verify" id="ptVerify" hidden><?php echo htmlspecialchars($ptT('needs_verify')); ?></div>
        <div class="pt-panel">
            <table class="pt-table">
                <thead><tr>
                    <th><?php echo htmlspecialchars($ptT('col_name')); ?></th>
                    <th><?php echo htmlspecialchars($ptT('col_sections')); ?></th>
                    <th class="pt-col-new"><?php echo htmlspecialchars($ptT('col_new')); ?></th>
                    <th class="pt-col-updated"><?php echo htmlspecialchars($ptT('col_updated')); ?></th>
                    <th></th>
                </tr></thead>
                <tbody id="ptBody"><tr><td colspan="5" class="pt-empty"><?php echo htmlspecialchars($ptT('loading')); ?></td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- New / edit -->
    <div class="pt-overlay" id="ptEdit">
        <div class="pt-modal" role="dialog" aria-modal="true" aria-labelledby="ptEditTitle">
            <div class="pt-modal-head" id="ptEditTitle"></div>
            <div class="pt-modal-body">
                <div class="pt-field">
                    <label for="ptName"><?php echo htmlspecialchars($ptT('name')); ?></label>
                    <input type="text" id="ptName" maxlength="100" placeholder="<?php echo htmlspecialchars($ptT('name_ph')); ?>">
                </div>
                <div class="pt-field">
                    <label for="ptDesc"><?php echo htmlspecialchars($ptT('description')); ?></label>
                    <input type="text" id="ptDesc" maxlength="255" placeholder="<?php echo htmlspecialchars($ptT('description_ph')); ?>">
                </div>
                <div class="pt-field">
                    <label for="ptSource"><?php echo htmlspecialchars($ptT('source')); ?></label>
                    <select id="ptSource"></select>
                    <div class="hint"><?php echo htmlspecialchars($ptT('source_hint')); ?></div>
                </div>
                <div class="pt-field">
                    <div class="pt-label-row">
                        <span class="pt-label"><?php echo htmlspecialchars($ptT('sections')); ?></span>
                        <button type="button" id="ptAll"><?php echo htmlspecialchars($ptT('all')); ?></button>
                        <button type="button" id="ptNone"><?php echo htmlspecialchars($ptT('none')); ?></button>
                    </div>
                    <div class="hint" id="ptSectionsHint"></div>
                    <div class="pt-sections" id="ptSections"></div>
                </div>
                <div class="pt-field">
                    <label class="pt-check"><input type="checkbox" id="ptForNew"> <span><?php echo htmlspecialchars($ptT('for_new')); ?></span></label>
                    <div class="hint"><?php echo htmlspecialchars($ptT('for_new_hint')); ?></div>
                </div>
            </div>
            <div class="pt-modal-foot">
                <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars($ptT('cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="ptSave"><?php echo htmlspecialchars($ptT('save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Apply -->
    <div class="pt-overlay" id="ptApply">
        <div class="pt-modal" role="dialog" aria-modal="true" aria-labelledby="ptApplyTitle">
            <div class="pt-modal-head" id="ptApplyTitle"></div>
            <div class="pt-modal-body">
                <p class="pt-field" id="ptApplyIntro" style="margin:0 0 8px;font-size:13px;color:var(--text,#444)"></p>
                <div class="pt-chips" id="ptApplySections" style="margin-bottom:14px"></div>
                <p class="pt-warn"><?php echo htmlspecialchars($ptT('apply_warn')); ?></p>
                <div class="pt-field">
                    <div class="pt-label-row">
                        <input type="text" id="ptPeopleSearch" placeholder="<?php echo htmlspecialchars($ptT('apply_search')); ?>" style="flex:1;padding:8px 10px;border:1px solid var(--border,#ddd);border-radius:6px;font-size:13px;background:var(--surface,#fff);color:var(--text,#333)">
                        <button type="button" id="ptPeopleAll"><?php echo htmlspecialchars($ptT('all')); ?></button>
                        <button type="button" id="ptPeopleNone"><?php echo htmlspecialchars($ptT('none')); ?></button>
                    </div>
                    <div class="pt-people" id="ptPeople"></div>
                    <div class="pt-count" id="ptPeopleCount"></div>
                </div>
            </div>
            <div class="pt-modal-foot">
                <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars($ptT('cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="ptApplyGo"><?php echo htmlspecialchars($ptT('apply_go')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <script>
    const PT = (function () {
        const API = '../../api/system/preference_templates.php';
        const $ = id => document.getElementById(id);
        const T = (k, p) => t('system.pref_templates.' + k, p);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const toast = (m, k) => { if (typeof showToast === 'function') showToast(m, k); };
        let data = { templates: [], sections: [], analysts: [], me: 0 };
        let editing = null, applying = null;

        async function post(body) {
            const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            return r.json();
        }

        /** Loads the templates, the sections a template can hold and the analysts, then draws the list. */
        async function load() {
            try {
                const d = await (await fetch(API + '?action=list', { credentials: 'same-origin' })).json();
                if (!d.success) throw new Error(d.error);
                data = d;
                $('ptVerify').hidden = true;
                render();
            } catch (e) {
                // The usual reason on an upgraded install: the table is not there yet.
                $('ptVerify').hidden = false;
                $('ptBody').innerHTML = '<tr><td colspan="5" class="pt-empty">' + esc(T('failed')) + '</td></tr>';
            }
        }

        const sectionLabel = id => (data.sections.find(s => s.id === id) || { label: id }).label;

        function render() {
            if (!data.templates.length) {
                $('ptBody').innerHTML = '<tr><td colspan="5" class="pt-empty">' + esc(T('empty')) + '</td></tr>';
                return;
            }
            $('ptBody').innerHTML = data.templates.map(tp => '<tr>'
                + '<td><div class="pt-name">' + esc(tp.name) + '</div>' + (tp.description ? '<div class="pt-desc">' + esc(tp.description) + '</div>' : '') + '</td>'
                + '<td><div class="pt-chips">' + tp.sections.map(s => '<span class="pt-chip">' + esc(sectionLabel(s)) + '</span>').join('') + '</div></td>'
                + '<td class="pt-col-new">' + (tp.for_new_analysts ? '<span class="pt-badge">' + esc(T('for_new_badge')) + '</span>' : '') + '</td>'
                + '<td class="pt-col-updated pt-updated">' + esc(tp.updated_datetime && window.fmtDate ? window.fmtDate(tp.updated_datetime) : '') + '</td>'
                + '<td class="pt-actions">'
                +   '<button type="button" data-apply="' + tp.id + '">' + esc(T('apply')) + '</button>'
                +   '<button type="button" data-edit="' + tp.id + '">' + esc(T('edit')) + '</button>'
                +   '<button type="button" class="danger" data-delete="' + tp.id + '">' + esc(T('delete')) + '</button>'
                + '</td></tr>').join('');
        }

        // ---- new / edit ----------------------------------------------------
        /**
         * Opens the template form. A new template takes its settings from a person
         * (you, by default); an existing one can keep what it has - then only
         * sections it already holds can stay ticked - or be taken again.
         */
        function openEdit(id) {
            editing = id ? data.templates.find(tp => tp.id === id) : null;
            $('ptEditTitle').textContent = T(editing ? 'edit_title' : 'new_title');
            $('ptName').value = editing ? editing.name : '';
            $('ptDesc').value = editing ? editing.description : '';
            $('ptForNew').checked = !!(editing && editing.for_new_analysts);
            const opts = (editing ? ['<option value="">' + esc(T('source_keep')) + '</option>'] : [])
                .concat(data.analysts.map(a => '<option value="' + a.id + '">'
                    + esc(a.id === data.me ? T('source_me', { name: a.name }) : a.name) + '</option>'));
            $('ptSource').innerHTML = opts.join('');
            $('ptSource').value = editing ? '' : String(data.me);
            $('ptSections').innerHTML = data.sections.map(s => '<label class="pt-check"><input type="checkbox" value="' + esc(s.id) + '"'
                + (!editing || editing.sections.includes(s.id) ? ' checked' : '') + '><span>' + esc(s.label) + '</span></label>').join('');
            syncSections();
            $('ptEdit').classList.add('open');
            $('ptName').focus();
        }

        /** "Keep what it has" cannot add a section it never captured, so those are disabled. */
        function syncSections() {
            const keep = !!editing && $('ptSource').value === '';
            $('ptSections').querySelectorAll('input').forEach(i => {
                const held = !!editing && editing.sections.includes(i.value);
                i.disabled = keep && !held;
                if (i.disabled) i.checked = false;
            });
            $('ptSectionsHint').textContent = T(keep ? 'sections_keep_hint' : 'sections_hint');
        }

        async function save() {
            const sections = [...$('ptSections').querySelectorAll('input:checked')].map(i => i.value);
            const body = { action: 'save', id: editing ? editing.id : 0, name: $('ptName').value.trim(),
                           description: $('ptDesc').value.trim(), for_new_analysts: $('ptForNew').checked, sections };
            if ($('ptSource').value) body.capture_from = parseInt($('ptSource').value, 10);
            if (!body.name) { toast(T('name_required'), 'error'); $('ptName').focus(); return; }
            if (!sections.length) { toast(T('sections_required'), 'error'); return; }
            $('ptSave').disabled = true;
            try {
                const d = await post(body);
                if (!d.success) throw new Error(d.error);
                close('ptEdit');
                toast(T('saved'), 'success');
                await load();
            } catch (e) {
                toast(e.message || T('failed'), 'error');
            }
            $('ptSave').disabled = false;
        }

        async function remove(id) {
            const tp = data.templates.find(x => x.id === id);
            if (!tp) return;
            const ok = await showConfirm({ title: T('delete_title', { name: tp.name }), message: T('delete_body'),
                                           okLabel: T('delete'), okClass: 'danger' });
            if (!ok) return;
            const d = await post({ action: 'delete', id });
            if (d.success) { toast(T('deleted'), 'success'); load(); }
            else toast(d.error || T('failed'), 'error');
        }

        // ---- apply ---------------------------------------------------------
        function openApply(id) {
            applying = data.templates.find(x => x.id === id);
            if (!applying) return;
            $('ptApplyTitle').textContent = T('apply_title', { name: applying.name });
            $('ptApplyIntro').textContent = T('apply_intro', { n: applying.sections.length });
            $('ptApplySections').innerHTML = applying.sections.map(s => '<span class="pt-chip">' + esc(sectionLabel(s)) + '</span>').join('');
            $('ptPeopleSearch').value = '';
            $('ptPeople').innerHTML = data.analysts.map(a => '<label class="pt-check" data-name="' + esc(a.name.toLowerCase()) + '">'
                + '<input type="checkbox" value="' + a.id + '"><span>' + esc(a.name) + '</span></label>').join('');
            countPeople();
            $('ptApply').classList.add('open');
            $('ptPeopleSearch').focus();
        }

        function countPeople() {
            const n = $('ptPeople').querySelectorAll('input:checked').length;
            $('ptPeopleCount').textContent = T('apply_count', { n });
            $('ptApplyGo').disabled = n === 0;
        }

        async function apply() {
            const ids = [...$('ptPeople').querySelectorAll('input:checked')].map(i => parseInt(i.value, 10));
            if (!ids.length) return;
            const ok = await showConfirm({ title: T('apply_confirm_title', { n: ids.length }),
                                           message: T('apply_confirm_body', { name: applying.name }), okLabel: T('apply_go') });
            if (!ok) return;
            $('ptApplyGo').disabled = true;
            try {
                const d = await post({ action: 'apply', id: applying.id, analyst_ids: ids });
                if (!d.success) throw new Error(d.error);
                close('ptApply');
                toast(T('applied', { n: d.applied }), 'success');
            } catch (e) {
                toast(e.message || T('failed'), 'error');
                $('ptApplyGo').disabled = false;
            }
        }

        function close(id) { $(id).classList.remove('open'); }

        // ---- wiring --------------------------------------------------------
        $('ptBody').addEventListener('click', e => {
            const b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.edit)   openEdit(parseInt(b.dataset.edit, 10));
            if (b.dataset.apply)  openApply(parseInt(b.dataset.apply, 10));
            if (b.dataset.delete) remove(parseInt(b.dataset.delete, 10));
        });
        $('ptSource').addEventListener('change', syncSections);
        $('ptAll').addEventListener('click', () => $('ptSections').querySelectorAll('input:not(:disabled)').forEach(i => { i.checked = true; }));
        $('ptNone').addEventListener('click', () => $('ptSections').querySelectorAll('input').forEach(i => { i.checked = false; }));
        $('ptSave').addEventListener('click', save);
        $('ptPeople').addEventListener('change', countPeople);
        $('ptPeopleSearch').addEventListener('input', () => {
            const q = $('ptPeopleSearch').value.trim().toLowerCase();
            $('ptPeople').querySelectorAll('.pt-check').forEach(l => { l.style.display = !q || l.dataset.name.includes(q) ? '' : 'none'; });
        });
        // All / None act on the people the search is showing.
        $('ptPeopleAll').addEventListener('click', () => { $('ptPeople').querySelectorAll('.pt-check').forEach(l => { if (l.style.display !== 'none') l.querySelector('input').checked = true; }); countPeople(); });
        $('ptPeopleNone').addEventListener('click', () => { $('ptPeople').querySelectorAll('input').forEach(i => { i.checked = false; }); countPeople(); });
        $('ptApplyGo').addEventListener('click', apply);
        document.querySelectorAll('.pt-overlay [data-close]').forEach(b => b.addEventListener('click', () => b.closest('.pt-overlay').classList.remove('open')));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.pt-overlay.open').forEach(o => o.classList.remove('open')); });

        load();
        return { openEdit };
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
