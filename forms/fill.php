<?php
/**
 * Forms Module - Fill In a Form
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/branding.php';   // the organisation's logo (GH #87)
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('forms');

$current_page = 'forms';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.fill.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <!-- Shared with the builder preview and the portal: field types + conditional
         visibility. Mirrors includes/form_logic.php, which decides on submit. -->
    <script src="../assets/js/form-logic.js?v=9"></script>
    <script src="../assets/js/form-render.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <!-- Presentation shared with the portal and the builder preview: blocks
         (notes, images) and label position. A notice panel and a picture have no
         reason to look different in the three places; an INPUT does. -->
    <link rel="stylesheet" href="../assets/css/form-shared.css?v=6">
    <style>
        /* Module accent (teal). */
        body { --accent: var(--forms-accent, #00897b); --accent-hover: var(--forms-accent-hover, #00695c); }

        .fill-container {
            flex: 1 1 100%;
            overflow-y: auto;
            background-color: var(--app-bg, #f5f7fa);
        }

        .fill-content {
            width: 100%;
            /* 🔑 THE TOKEN IS THE WIDTH OF THE FORM, NOT OF THIS BOX. Two lots
               of padding sit between this element's max-width and the grid, and
               BOTH count, because this page loads inbox.css — whose opening rule
               is a global `* { box-sizing: border-box }`. The portal does not
               load it, so the same number means different things on the two
               surfaces: there, .cat-form is content-box and its max-width IS the
               grid. That is why one shared token still produced two widths.
                 this element's own padding   25px x 2 = 50
                 .fill-card's padding         50px x 2 = 100
               🔴 MEASURED, NOT CALCULATED. The first attempt reasoned it out as
               100px and produced a 990px form against the portal's 1040px; only
               putting both surfaces side by side in an iframe showed it. Use
               mobile-probe/formwidths.php, which lifts the real CSS from both
               pages and fails if the two grids differ.
               ⚠️ Change either padding, or stop loading inbox.css, and this
               number must change with it. */
            max-width: calc(var(--form-card-max, 1040px) + 150px);
            margin: 0 auto;
            padding: 30px 25px;
        }

        .fill-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            box-shadow: 0 2px 12px var(--shadow, rgba(0,0,0,0.08));
            padding: 40px 50px;
            min-height: 600px;
            box-sizing: border-box;
        }

        .form-logo {
            display: block;
            max-width: 220px;
            height: auto;
            margin: 0 auto 28px;
        }

        .form-logo.align-left { margin: 0 auto 28px 0; }
        .form-logo.align-center { margin: 0 auto 28px; }
        .form-logo.align-right { margin: 0 0 28px auto; }

        .fill-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 4px;
        }

        .fill-desc {
            font-size: 14px;
            color: var(--text-dim, #888);
            margin: 0 0 24px;
        }

        .form-field {
            margin-bottom: 18px;
        }

        /* A section heading. Not a question — it groups the fields beneath it,
           and hiding it hides that whole group. */
        .form-section {
            margin: 26px 0 14px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border, #ddd);
        }
        .form-section:first-child { margin-top: 0; }
        .form-section h2 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0;
        }

        /* Conditionally hidden — set by applyVisibility() as answers change. */
        .form-field.is-hidden,
        .form-section.is-hidden { display: none; }

        .form-field label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text, #333);
            margin-bottom: 5px;
        }

        .form-field label .required-star {
            color: #d32f2f;
            margin-left: 2px;
        }

        .form-field input[type="text"],
        .form-field input[type="email"],
        .form-field input[type="number"],
        .form-field input[type="date"],
        .form-field input[type="time"],
        .form-field input[type="datetime-local"],
        .form-field textarea,
        .form-field select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
            background: var(--surface, #fff);
            color: var(--text, #333);
        }

        .form-field input:focus,
        .form-field textarea:focus,
        .form-field select:focus {
            outline: none;
            border-color: var(--forms-accent, #00897b);
            box-shadow: 0 0 0 2px rgba(0,137,123,0.1);
        }

        .form-field textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-field.checkbox-field {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-field.checkbox-field input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
        }

        .form-field.checkbox-field label {
            margin-bottom: 0;
            cursor: pointer;
        }

        /* .choice-field is the wrapper for radio groups and multi-
           checkbox groups. Each option lives in a .choice-row beneath
           the field label. */
        .form-field.choice-field .choice-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }
        .form-field.choice-field .choice-row input {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
            flex-shrink: 0;
        }
        .form-field.choice-field .choice-row label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
            font-size: 14px;
            color: var(--text, #333);
        }

        .form-field.has-error input,
        .form-field.has-error textarea,
        .form-field.has-error select {
            border-color: var(--danger-text, #d32f2f);
        }
        /* When a radio/checkbox group errors, highlight the wrapper
           border instead of every input. */
        .form-field.choice-field.has-error {
            border: 1px solid var(--danger-text, #d32f2f);
            border-radius: 6px;
            padding: 8px 12px;
        }

        .field-error {
            font-size: 12px;
            color: var(--danger-text, #d32f2f);
            margin-top: 4px;
            display: none;
        }

        .form-field.has-error .field-error {
            display: block;
        }

        .form-actions {
            margin-top: 24px;
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 22px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary { background: var(--forms-accent, #00897b); color: white; }
        .btn-primary:hover { background: var(--forms-accent-hover, #00695c); }
        .btn-secondary { background: var(--surface-2, #f5f7fa); color: var(--text, #333); border: 1px solid var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #eef0f2); }

        .submit-message {
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-top: 16px;
            display: none;
        }

        .submit-message.success {
            display: block;
            background: var(--success-bg, #e8f5e9);
            color: var(--success-text, #2e7d32);
            border: 1px solid var(--success-bg, #c8e6c9);
        }

        .submit-message.error {
            display: block;
            background: var(--danger-bg, #ffebee);
            color: var(--danger-text, #c62828);
            border: 1px solid var(--danger-bg, #ffcdd2);
        }

        .success-actions {
            margin-top: 14px;
            display: flex;
            gap: 8px;
        }
    
        /* Lookup field — search-as-you-type over records the app already holds.
           The results panel is absolutely positioned so it overlays the fields
           below rather than shoving the form around as you type. */
        .lookup-wrap { position: relative; }
        .lookup-results {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 40;
            max-height: 220px; overflow-y: auto;
            background: var(--surface, #fff); border: 1px solid var(--border, #ddd);
            border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,.12); margin-top: 2px;
        }
        .lookup-option {
            display: block; width: 100%; text-align: left; background: none; border: 0;
            padding: 9px 12px; font-size: 14px; color: var(--text, #333); cursor: pointer;
        }
        .lookup-option:hover { background: var(--surface-hover, #f3f4f6); }
        .lookup-empty { padding: 9px 12px; font-size: 13px; color: var(--text-muted, #666); }

        /* ---- Field widths, in twelfths (docs/design/form-layout-and-grid.md) ----
           12 divides by 1, 2, 3, 4 and 6, so full / three-quarters / two-thirds /
           half / third / quarter are all whole columns — and so are the
           asymmetric pairs a real document wants, like 8+4 for "Area | Date".

           `align-items: start` matters: without it a short field stretches to
           the height of a tall neighbour and its input grows with it. */
        .fill-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 0 18px;
            align-items: start;
        }
        /* Anything without a width spans the row — including a field type that
           predates this and a submit button that is not a field at all. */
        .fill-grid > * { grid-column: span 12; }
        .fill-grid > [data-width="9"] { grid-column: span 9; }
        .fill-grid > [data-width="8"] { grid-column: span 8; }
        .fill-grid > [data-width="6"] { grid-column: span 6; }
        .fill-grid > [data-width="4"] { grid-column: span 4; }
        .fill-grid > [data-width="3"] { grid-column: span 3; }

        /* 🔴 ONE COLUMN ON A PHONE, always. Two controls side by side on a
           360px screen is worse than one, and at quarter width a label wraps
           to three lines before its input is even narrow. Equal specificity
           and later in the file, so it wins without !important. */
        @media (max-width: 768px) {
            .fill-grid { grid-template-columns: minmax(0, 1fr); gap: 0; }
            .fill-grid > *,
            .fill-grid > [data-width="9"],
            .fill-grid > [data-width="8"],
            .fill-grid > [data-width="6"],
            .fill-grid > [data-width="4"],
            .fill-grid > [data-width="3"] { grid-column: span 1; }
        }

        /* ⚠️ minmax(0, 1fr) rather than 1fr: a grid track's default minimum is
           auto, so one long unbroken word in a narrow column would push the
           track wider than its share and break the row. */    </style>
    <!-- Mobile layer. Linked AFTER this page's inline <style> on purpose: the
         mobile rules must win on equal specificity, and a link placed above it
         would silently lose to the desktop block below (the load-order trap). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=170">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container fill-container">
        <div class="fill-content">
            <div class="fill-card" id="formCard">
                <p style="color:var(--text-dim, #888);text-align:center;padding:20px"><?php echo htmlspecialchars(t('forms.fill.loading')); ?></p>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/forms/';
        let formData = null;
        let logoAlignment = 'center';

        document.addEventListener('DOMContentLoaded', async function() {
            // Load settings first, then form
            try {
                const sRes = await fetch(API_BASE + 'get_settings.php');
                const sData = await sRes.json();
                if (sData.success && sData.settings) {
                    logoAlignment = sData.settings.logo_alignment || 'center';
                }
            } catch (e) {}

            const params = new URLSearchParams(window.location.search);
            const id = params.get('id');
            if (id) {
                loadForm(id);
            } else {
                document.getElementById('formCard').innerHTML = '<p style="color:var(--danger-text, #c00);text-align:center">' + esc(window.t('forms.fill.no_id')) + '</p>';
            }
        });

        async function loadForm(id) {
            try {
                const res = await fetch(API_BASE + 'get_form.php?id=' + id);
                const data = await res.json();

                if (data.success) {
                    formData = data.form;
                    renderForm();
                    restoreDraft();
                } else {
                    document.getElementById('formCard').innerHTML = '<p style="color:var(--danger-text, #c00);text-align:center">' + esc(data.error) + '</p>';
                }
            } catch (e) {
                console.error(e);
            }
        }

        /* ══ Drafts ═════════════════════════════════════════════════════════
           A form you started and have not finished — because the cost code is
           with finance, or the serial number is on a machine you are not sitting
           at. Saved on demand only; nothing is stored until somebody asks. */

        /**
         * Save what is typed so far.
         *
         * 🔑 collectValues() reads EVERY answerable field, including ones a
         * condition is currently hiding — which is what a draft wants and is
         * why it is used here rather than submitForm's own collection. A draft
         * is a snapshot of the typing, not of the answer: tick a box, fill the
         * branch it reveals, untick it, and that typing should still be there
         * tomorrow. Submit deliberately does the opposite and drops hidden
         * answers, because it is recording what the person was actually asked.
         */
        async function saveDraft() {
            try {
                const res = await fetch(API_BASE + 'draft.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ form_id: formData.id, answers: collectValues() })
                });
                const data = await res.json();
                showMsg(data.success ? window.t('forms.draft.saved')
                                     : (data.error || window.t('forms.draft.failed')),
                        data.success ? 'success' : 'error');
            } catch (e) {
                showMsg(window.t('forms.draft.failed'), 'error');
            }
        }

        /**
         * Put a saved draft back on screen, if there is one.
         *
         * 🔴 A STALE DRAFT IS NOT LOADED. The form has had a new version since
         * it was saved, and createVersion() renumbers every field — so those
         * answers would attach to whichever questions now hold those ids, or to
         * none. Saying so and leaving the form blank is the only honest option;
         * silently filling in the wrong boxes is the worst one.
         */
        async function restoreDraft() {
            try {
                const res  = await fetch(API_BASE + 'draft.php?form_id=' + formData.id);
                const data = await res.json();
                const d    = data && data.draft;
                if (!d) return;

                if (d.stale) {
                    showMsg(window.t('forms.draft.stale'), 'error');
                    return;
                }
                applyDraftValues(d.answers || {});
                applyVisibility();
                showMsg(window.t('forms.draft.restored', { when: d.modified_date }), 'success');
            } catch (e) { /* no draft is not a failure worth reporting */ }
        }

        /** Write saved answers back into the controls, by field type. */
        function applyDraftValues(answers) {
            formData.fields.forEach(f => {
                if (!Object.prototype.hasOwnProperty.call(answers, String(f.id))) return;
                const val = answers[String(f.id)];

                if (f.field_type === 'grid') {
                    /* Rebuild the rows the person had, rather than the single
                       empty starter row. Values are keyed by column id, which is
                       why reordering the columns since does not matter. */
                    const wrap = document.querySelector(`.form-table-field[data-field-id="${f.id}"] tbody`);
                    if (!wrap) return;
                    const rows = FormLogic.gridRows(val);
                    if (!rows.length) return;
                    const cols = FormLogic.gridLiveColumns(f);
                    wrap.innerHTML = rows.map(r => gridRowHtml(f, cols, r)).join('');
                    return;
                }

                const wrapper = document.querySelector(`.form-field[data-field-id="${f.id}"]`);
                if (f.field_type === 'radio') {
                    const hit = wrapper && wrapper.querySelector(`input[type="radio"][value="${CSS.escape(String(val))}"]`);
                    if (hit) hit.checked = true;
                    return;
                }
                if (f.field_type === 'checkboxes') {
                    let list = [];
                    try { const p = JSON.parse(val); if (Array.isArray(p)) list = p.map(String); } catch (e) { /* legacy CSV */ }
                    if (!list.length && val) list = String(val).split(',').map(s => s.trim());
                    (wrapper ? wrapper.querySelectorAll('input[type="checkbox"]') : []).forEach(cb => {
                        cb.checked = list.indexOf(cb.value) !== -1;
                    });
                    return;
                }

                const el = document.querySelector(`[data-field-id="${f.id}"]`);
                if (!el || !('value' in el)) return;
                if (el.type === 'checkbox') el.checked = (String(val) === '1');
                else el.value = val;
            });
        }

        /* ══ A table question ═══════════════════════════════════════════════
           🔑 A cell is addressed by COLUMN ID (data-col), never by its position
           in the row. Reordering the columns in the builder must not re-point a
           single answer already stored, which is the whole reason columns carry
           a stable id. */

        /** One row of cells. Values, when given, are keyed by column id. */
        function gridRowHtml(f, cols, values) {
            const v = values || {};
            return `<tr>
                ${cols.map(c => `<td data-col="${c.id}">${gridCellHtml(f.id, c, v[c.id])}</td>`).join('')}
                <td class="form-table-rowaction">
                    <button type="button" class="form-table-remove" onclick="removeGridRow(this)"
                            title="${escAttr(window.t('forms.grid.remove_row'))}">&times;</button>
                </td>
            </tr>`;
        }

        /** One cell's control, from the restricted palette a table column may be. */
        function gridCellHtml(fieldId, c, value) {
            const val = value === undefined || value === null ? '' : String(value);
            switch (c.type) {
                case 'number':
                    return `<input type="number" step="any" value="${escAttr(val)}">`;
                case 'datetime':
                    return `<input type="date" value="${escAttr(val)}">`;
                case 'checkbox':
                    return `<input type="checkbox"${val === '1' ? ' checked' : ''}>`;
                case 'dropdown':
                    return `<select><option value=""></option>${(c.options || []).map(o =>
                        `<option value="${escAttr(o)}"${o === val ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
                case 'radio': {
                    /* The radio group is scoped to the field, the column AND the
                       row, or every row's radios would be one group and choosing
                       in the second row would clear the first.
                       🔴 The name is allocated ONCE PER CELL, before the options
                       are walked. Incrementing the counter inside the loop gave
                       every option its own group name, which is not a broken
                       group — it is FOUR groups of one, so all four could be
                       ticked at once and a "single select" column stopped being
                       single. The cell is the group; the option is a member. */
                    const group = `g_${fieldId}_${c.id}_${gridRadioSeq++}`;
                    return (c.options || []).map(o =>
                        `<label class="grid-radio"><input type="radio" name="${group}"
                            value="${escAttr(o)}"${o === val ? ' checked' : ''}> ${esc(o)}</label>`).join('');
                }
                default:
                    return `<input type="text" value="${escAttr(val)}">`;
            }
        }
        let gridRadioSeq = 0;

        function addGridRow(fieldId) {
            const wrap = document.querySelector(`.form-table-field[data-field-id="${fieldId}"]`);
            if (!wrap) return;
            const body = wrap.querySelector('tbody');
            const f    = formData.fields.find(x => Number(x.id) === Number(fieldId));
            if (!body || !f) return;
            if (body.rows.length >= 500) {
                showMsg(window.t('forms.grid.too_many_rows'), 'error');
                return;
            }
            /* ⚠️ Radio groups have to stay unique across rows, and gridRowHtml
               allocates names from a counter — so the row is built the same way
               a first row is rather than cloned from an existing one. Cloning
               would duplicate the name and silently join the two rows' radios. */
            body.insertAdjacentHTML('beforeend', gridRowHtml(f, FormLogic.gridLiveColumns(f)));
            applyVisibility();
        }

        function removeGridRow(btn) {
            const row  = btn.closest('tr');
            const body = row && row.parentNode;
            if (!body) return;
            // Never leave a table with no rows: an empty one reads as broken and
            // gives nowhere to type.
            if (body.rows.length <= 1) {
                row.querySelectorAll('input, select').forEach(el => {
                    if (el.type === 'checkbox' || el.type === 'radio') el.checked = false;
                    else el.value = '';
                });
                return;
            }
            row.remove();
            applyVisibility();
        }

        /** Every row's answers, keyed by column id. Empty rows are dropped. */
        function readGridValue(f) {
            const wrap = document.querySelector(`.form-table-field[data-field-id="${f.id}"]`);
            if (!wrap) return null;
            const rows = [];
            wrap.querySelectorAll('tbody tr').forEach(tr => {
                const row = {};
                let any = false;
                tr.querySelectorAll('td[data-col]').forEach(td => {
                    const cid = td.getAttribute('data-col');
                    const cb  = td.querySelector('input[type="checkbox"]');
                    if (cb) { row[cid] = cb.checked ? '1' : '0'; if (cb.checked) any = true; return; }
                    const radio = td.querySelector('input[type="radio"]:checked');
                    if (radio) { row[cid] = radio.value; any = true; return; }
                    if (td.querySelector('input[type="radio"]')) { row[cid] = ''; return; }
                    const el = td.querySelector('input, select');
                    if (!el) return;
                    row[cid] = el.value;
                    if (String(el.value).trim() !== '') any = true;
                });
                /* 🔑 A row nobody typed anything into is not an answer. Dropping
                   it here is what lets a table start with one empty row without
                   that row becoming a blank record on every submission. */
                if (any) rows.push(row);
            });
            return rows;
        }

        function renderForm() {
            const card = document.getElementById('formCard');
            const alignClass = 'align-' + logoAlignment;
            let html = `<img src="<?php echo htmlspecialchars(brandingLogoUrl()); ?>" alt="${escAttr(window.t('forms.fill.logo_alt'))}" class="form-logo ${alignClass}">`;
            html += `<h1 class="fill-title">${esc(formData.title)}</h1>`;
            if (formData.description) {
                html += `<p class="fill-desc">${esc(formData.description)}</p>`;
            }

            html += '<form id="fillForm" class="fill-grid" onsubmit="submitForm(event)">';

            /* The WALK is shared (FormRender); the MARKUP is not. See
               assets/js/form-render.js for why those two are separated — in
               short, the three surfaces genuinely look different, but a type
               none of them was taught used to fail three different silent ways.
               Returning null below is what makes this one loud. */
            html += FormRender.render(formData.fields, {
              name: 'analyst filler',
              field: (f, ctx) => {
                const req = f.is_required == 1;
                const reqStar = req ? '<span class="required-star">*</span>' : '';
                const reqAttr = req ? 'data-required="1"' : '';
                /* data-wrap-id and the width both come from the shared walker now,
                   so one decision covers all three surfaces rather than one
                   covering all eleven types on this one. */
                const wrap = ctx.wrapAttrs;

                switch (f.field_type) {
                    case 'section':
                        return `<div class="form-section" ${wrap}><h2>${esc(f.label)}</h2></div>`;
                    case 'grid': {
                        /* A table question: headings, and rows the person adds
                           as they go. One row is drawn to start with, because an
                           empty table with an Add button reads as broken.
                           🔑 Every cell is named by its COLUMN ID, never by its
                           position — reordering the columns later must not
                           re-point a single stored answer. */
                        const gcols = FormLogic.gridLiveColumns(f);
                        if (!gcols.length) {
                            return `<div class="form-field" ${wrap} ${reqAttr}>
                                <label>${esc(f.label)}${reqStar}</label>
                                <div class="form-table-empty">${esc(window.t('forms.grid.not_configured'))}</div>
                            </div>`;
                        }
                        return `<div class="form-field form-table-field" ${wrap} ${reqAttr} data-field-id="${f.id}" data-field-kind="grid">
                            <label>${esc(f.label)}${reqStar}</label>
                            <div class="form-table-wrap">
                                <table class="form-table">
                                    <thead><tr>
                                        ${gcols.map(c => `<th>${esc(c.label)}${c.required ? '<span class="required-star">*</span>' : ''}</th>`).join('')}
                                        <th class="form-table-rowaction"></th>
                                    </tr></thead>
                                    <tbody>${gridRowHtml(f, gcols)}</tbody>
                                </table>
                            </div>
                            <div class="form-table-actions">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="addGridRow(${f.id})">${esc(window.t('forms.grid.add_row'))}</button>
                            </div>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    }
                    case 'image': {
                        /* The picture is fetched BY FIELD ID — the stored path
                           never leaves the server. See api/forms/image.php.
                           alt falls back to the field's label, which is what an
                           author typed to describe it; an image with no text
                           alternative is unusable to anyone on a screen reader. */
                        const src = FormLogic.imageUrl(f, '../');
                        if (!src) return `<div class="form-image is-empty" ${wrap}>${esc(window.t('forms.image.none'))}</div>`;
                        const pct = FormLogic.imageMaxWidth(f);
                        return `<div class="form-image" ${wrap}${pct === 100 ? '' : ` data-image-max="${pct}"`}>
                            <img src="${escAttr(src)}" alt="${escAttr(f.label || '')}" loading="lazy">
                        </div>`;
                    }
                    case 'note': {
                        /* Standing text, not a question. Its classes and styling
                           live in assets/css/form-shared.css, shared with the
                           portal and the preview — a notice panel has no reason
                           to look different in the three places, unlike an input.
                           🔴 The style is a NAME resolved from the theme, never a
                           colour the author typed: see FormsService::NOTE_STYLES. */
                        const body = FormLogic.noteBody(f);
                        return `<div class="form-note" data-note-style="${escAttr(FormLogic.noteStyle(f))}" ${wrap}>
                            <p class="form-note-title">${esc(f.label)}</p>
                            ${body ? `<p class="form-note-body">${esc(body)}</p>` : ''}
                        </div>`;
                    }
                    case 'text':
                        return `<div class="form-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="text" name="field_${f.id}" data-field-id="${f.id}">
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    case 'textarea':
                        return `<div class="form-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <textarea name="field_${f.id}" data-field-id="${f.id}"></textarea>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    case 'email':
                        return `<div class="form-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="email" name="field_${f.id}" data-field-id="${f.id}" placeholder="${escAttr(window.t('forms.fill.email_ph'))}">
                            <div class="field-error">${esc(window.t('forms.fill.err_email'))}</div>
                        </div>`;
                    case 'number':
                        return `<div class="form-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="number" name="field_${f.id}" data-field-id="${f.id}" inputmode="decimal" step="any">
                            <div class="field-error">${esc(window.t('forms.fill.err_number'))}</div>
                        </div>`;
                    case 'datetime': {
                        // One field type, three shapes — date / time / date and time.
                        // The browser's own picker produces exactly the value we store.
                        const dtMode = FormLogic.dateMode(f);
                        return `<div class="form-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="${FormLogic.dateInputType(dtMode)}" name="field_${f.id}" data-field-id="${f.id}">
                            <div class="field-error">${esc(window.t('forms.fill.err_' + dtMode))}</div>
                        </div>`;
                    }
                    case 'lookup': {
                        // Search-as-you-type over records we already hold. The
                        // visible box is a search box; the ANSWER lives in the
                        // hidden input as {"id":…,"label":…} and is only written
                        // when something is chosen from the list — so a typed
                        // string that matches nothing is not an answer.
                        return `<div class="form-field lookup-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <div class="lookup-wrap">
                                <input type="text" class="lookup-search" autocomplete="off"
                                       data-lookup-field="${f.id}"
                                       placeholder="${esc(window.t('forms.fill.lookup_placeholder'))}">
                                <input type="hidden" name="field_${f.id}" data-field-id="${f.id}">
                                <div class="lookup-results" hidden></div>
                            </div>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    }
                    case 'checkbox':
                        return `<div class="form-field checkbox-field" ${wrap} ${reqAttr}>
                            <input type="checkbox" name="field_${f.id}" data-field-id="${f.id}" id="cb_${f.id}">
                            <label for="cb_${f.id}">${esc(f.label)}${reqStar}</label>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    case 'dropdown': {
                        const opts = FormLogic.parseOptions(f.options);
                        return `<div class="form-field" ${wrap} ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <select name="field_${f.id}" data-field-id="${f.id}">
                                <option value="">${esc(window.t('forms.fill.select_ph'))}</option>
                                ${opts.map(o => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}
                            </select>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    }
                    case 'radio': {
                        const opts = FormLogic.parseOptions(f.options);
                        // Radios share a name so the browser enforces
                        // single-select. data-field-id on the wrapper
                        // (not the individual inputs) so submitForm can
                        // read the chosen value via name=field_X.
                        return `<div class="form-field choice-field" ${wrap} ${reqAttr} data-field-id="${f.id}" data-field-kind="radio">
                            <label>${esc(f.label)}${reqStar}</label>
                            ${opts.map((o, i) => `
                                <div class="choice-row">
                                    <input type="radio" name="field_${f.id}" value="${esc(o)}" id="r_${f.id}_${i}">
                                    <label for="r_${f.id}_${i}">${esc(o)}</label>
                                </div>
                            `).join('')}
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                    }
                    case 'checkboxes': {
                        const opts = FormLogic.parseOptions(f.options);
                        // Multi-checkbox group — each option is its own
                        // <input type="checkbox">; submitForm reads the
                        // wrapper's [data-field-kind="checkboxes"] and
                        // collects every checked value into an array.
                        return `<div class="form-field choice-field" ${wrap} ${reqAttr} data-field-id="${f.id}" data-field-kind="checkboxes">
                            <label>${esc(f.label)}${reqStar}</label>
                            ${opts.map((o, i) => `
                                <div class="choice-row">
                                    <input type="checkbox" name="field_${f.id}[]" value="${esc(o)}" id="c_${f.id}_${i}">
                                    <label for="c_${f.id}_${i}">${esc(o)}</label>
                                </div>
                            `).join('')}
                            <div class="field-error">${esc(window.t('forms.fill.err_checkboxes'))}</div>
                        </div>`;
                    }
                    default:
                        /* 🔴 There was no default here at all, so a type this page
                           had never been taught was simply left out of the form —
                           a required question silently absent. Returning null hands
                           it to FormRender, which draws a visible notice and logs. */
                        return null;
                }
              }
            /* The layout decides WHERE the questions go; the server resolves it
               (stored or derived) so all three surfaces get the same answer.
               Absent means walk the pool, which is what this did before. */
            }, formData.layout);

            html += `<div class="form-actions">
                <button type="submit" class="btn btn-primary">${esc(window.t('forms.fill.submit'))}</button>
                <button type="button" class="btn btn-secondary" onclick="saveDraft()">${esc(window.t('forms.draft.save'))}</button>
                <a href="./" class="btn btn-secondary">${esc(window.t('forms.fill.cancel'))}</a>
            </div>`;
            html += '</form>';
            html += '<div class="submit-message" id="submitMessage"></div>';

            card.innerHTML = html;

            // Any answer can be the trigger for a condition, so re-evaluate on every
            // edit. 'input' covers typing, 'change' covers ticking and selecting.
            const formEl = document.getElementById('fillForm');
            formEl.addEventListener('input', applyVisibility);
            formEl.addEventListener('change', applyVisibility);

            // Lookup boxes search the app's own records. Wired after the markup
            // exists, and shared with the portal so both behave identically.
            FormLogic.attachLookups(formEl, '../api/forms/lookup_search.php');

            applyVisibility();
        }

        /**
         * Read one field's current answer from the DOM.
         *
         * The wrapper carries data-field-id for radio + checkboxes groups; for
         * everything else it's on the input itself. Read the wrapper first so the
         * lookup works for both shapes. Returns null when the field isn't on the page.
         */
        function readField(f) {
            const wrapper = document.querySelector(`.form-field[data-field-id="${f.id}"]`);
            const el = wrapper ? null : document.querySelector(`[data-field-id="${f.id}"]`);

            if (f.field_type === 'grid') {
                /* A table's answer is a LIST OF ROWS, each a map of column id to
                   value — so it is sent as JSON in the one field_value, which is
                   what the service stores and reads back.
                   🔑 "Empty" means no row had anything typed into it. A table
                   starts with one blank row so there is somewhere to type; that
                   blank row must not make a required table look answered. */
                const gridValue = readGridValue(f);
                if (gridValue === null) return null;
                return {
                    value: JSON.stringify(gridValue),
                    isEmpty: gridValue.length === 0,
                    wrapper: document.querySelector(`.form-table-field[data-field-id="${f.id}"]`),
                    el: null
                };
            }
            if (f.field_type === 'checkbox') {
                // Single yes/no toggle — '1' or '0'.
                if (!el) return null;
                return { value: el.checked ? '1' : '0', isEmpty: !el.checked, wrapper, el };
            }
            if (f.field_type === 'radio') {
                // Single-select from a group — the checked radio's value, or ''.
                const picked = wrapper && wrapper.querySelector('input[type="radio"]:checked');
                return { value: picked ? picked.value : '', isEmpty: !picked, wrapper, el };
            }
            if (f.field_type === 'checkboxes') {
                // Multi-select — every ticked value, serialised as JSON. The service
                // stores that string; submissions.php decodes it for display.
                const picked = wrapper ? wrapper.querySelectorAll('input[type="checkbox"]:checked') : [];
                const arr = Array.from(picked).map(p => p.value);
                return { value: JSON.stringify(arr), isEmpty: arr.length === 0, wrapper, el };
            }
            if (!el) return null;
            const value = (el.value || '').trim();
            return { value, isEmpty: !value, wrapper, el };
        }

        /** field_id => current answer, for the condition evaluator. */
        function collectValues() {
            const values = {};
            formData.fields.forEach(f => {
                if (!FormLogic.isAnswerable(f.field_type)) return;
                const read = readField(f);
                if (read) values[f.id] = read.value;
            });
            return values;
        }

        /**
         * Show or hide fields to match the answers so far. The server re-derives this
         * on submit — this copy only exists so the page reacts as someone types.
         */
        function applyVisibility() {
            const vis = FormLogic.visibility(formData.fields, collectValues());
            formData.fields.forEach(f => {
                const wrap = document.querySelector(`[data-wrap-id="${f.id}"]`);
                if (!wrap) return;
                const hidden = vis[f.id] === false;
                wrap.classList.toggle('is-hidden', hidden);
                // A hidden field can't be the thing standing between you and Submit.
                if (hidden) wrap.classList.remove('has-error');
            });
            return vis;
        }

        async function submitForm(e) {
            e.preventDefault();

            // Clear errors
            document.querySelectorAll('.form-field.has-error').forEach(el => el.classList.remove('has-error'));

            const vis = applyVisibility();
            const data = {};
            let valid = true;

            formData.fields.forEach(f => {
                // Headings collect nothing; a question that was never shown was never
                // asked, so it is neither sent nor required.
                if (!FormLogic.isAnswerable(f.field_type) || vis[f.id] === false) return;

                const read = readField(f);
                if (!read) return;

                data[f.id] = read.value;

                if (f.is_required == 1 && read.isEmpty) {
                    (read.wrapper || read.el.closest('.form-field')).classList.add('has-error');
                    valid = false;
                }
            });

            if (!valid) {
                showMsg(window.t('forms.fill.fill_required'), 'error');
                return;
            }

            try {
                const res = await fetch(API_BASE + 'submit_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ form_id: formData.id, data: data })
                });
                const result = await res.json();

                if (result.success) {
                    /* 🔑 The draft has served its purpose — throw it away, or
                       "Submit another" reloads the answers that were just sent
                       and the person submits them twice. Deliberately not
                       awaited and its failure ignored: the submission has
                       already succeeded and must not be reported as failed
                       because a tidy-up did not. */
                    fetch(API_BASE + 'draft.php?form_id=' + formData.id, { method: 'DELETE' })
                        .catch(() => {});

                    document.getElementById('fillForm').style.display = 'none';
                    const msgEl = document.getElementById('submitMessage');
                    msgEl.className = 'submit-message success';
                    msgEl.innerHTML = esc(window.t('forms.fill.success')) +
                        '<div class="success-actions">' +
                        '<a href="fill.php?id=' + formData.id + '" class="btn btn-primary">' + esc(window.t('forms.fill.submit_another')) + '</a>' +
                        '<a href="./" class="btn btn-secondary">' + esc(window.t('forms.fill.back_to_forms')) + '</a>' +
                        '</div>';
                } else {
                    showMsg(window.t('forms.fill.error_prefix', { message: result.error }), 'error');
                }
            } catch (e) {
                showMsg(window.t('forms.fill.submit_failed'), 'error');
            }
        }

        function showMsg(text, type) {
            const el = document.getElementById('submitMessage');
            el.textContent = text;
            el.className = 'submit-message ' + type;
        }

        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        function escAttr(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    </script>
    <!-- Mobile layer. Adds the views hamburger and the module drawer on a phone.
         Loaded last so it can wrap the page's own globals rather than edit them. -->
    <script src="../assets/js/mobile.js?v=71"></script>
</body>
</html>
