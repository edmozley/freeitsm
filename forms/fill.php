<?php
/**
 * Forms Module - Fill In a Form
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'forms';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.fill.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=16">
    <link rel="stylesheet" href="../assets/css/inbox.css">
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
            max-width: 860px;
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
    </style>
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
                } else {
                    document.getElementById('formCard').innerHTML = '<p style="color:var(--danger-text, #c00);text-align:center">' + esc(data.error) + '</p>';
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderForm() {
            const card = document.getElementById('formCard');
            const alignClass = 'align-' + logoAlignment;
            let html = `<img src="../assets/images/CompanyLogo.png" alt="${escAttr(window.t('forms.fill.logo_alt'))}" class="form-logo ${alignClass}">`;
            html += `<h1 class="fill-title">${esc(formData.title)}</h1>`;
            if (formData.description) {
                html += `<p class="fill-desc">${esc(formData.description)}</p>`;
            }

            html += '<form id="fillForm" onsubmit="submitForm(event)">';

            formData.fields.forEach(f => {
                const req = f.is_required == 1;
                const reqStar = req ? '<span class="required-star">*</span>' : '';
                const reqAttr = req ? 'data-required="1"' : '';

                switch (f.field_type) {
                    case 'text':
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="text" name="field_${f.id}" data-field-id="${f.id}">
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                        break;
                    case 'textarea':
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <textarea name="field_${f.id}" data-field-id="${f.id}"></textarea>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                        break;
                    case 'email':
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="email" name="field_${f.id}" data-field-id="${f.id}" placeholder="${escAttr(window.t('forms.fill.email_ph'))}">
                            <div class="field-error">${esc(window.t('forms.fill.err_email'))}</div>
                        </div>`;
                        break;
                    case 'number':
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="number" name="field_${f.id}" data-field-id="${f.id}" inputmode="decimal" step="any">
                            <div class="field-error">${esc(window.t('forms.fill.err_number'))}</div>
                        </div>`;
                        break;
                    case 'checkbox':
                        html += `<div class="form-field checkbox-field" ${reqAttr}>
                            <input type="checkbox" name="field_${f.id}" data-field-id="${f.id}" id="cb_${f.id}">
                            <label for="cb_${f.id}">${esc(f.label)}${reqStar}</label>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                        break;
                    case 'dropdown': {
                        const opts = f.options ? JSON.parse(f.options) : [];
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <select name="field_${f.id}" data-field-id="${f.id}">
                                <option value="">${esc(window.t('forms.fill.select_ph'))}</option>
                                ${opts.map(o => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}
                            </select>
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                        break;
                    }
                    case 'radio': {
                        const opts = f.options ? JSON.parse(f.options) : [];
                        // Radios share a name so the browser enforces
                        // single-select. data-field-id on the wrapper
                        // (not the individual inputs) so submitForm can
                        // read the chosen value via name=field_X.
                        html += `<div class="form-field choice-field" ${reqAttr} data-field-id="${f.id}" data-field-kind="radio">
                            <label>${esc(f.label)}${reqStar}</label>
                            ${opts.map((o, i) => `
                                <div class="choice-row">
                                    <input type="radio" name="field_${f.id}" value="${esc(o)}" id="r_${f.id}_${i}">
                                    <label for="r_${f.id}_${i}">${esc(o)}</label>
                                </div>
                            `).join('')}
                            <div class="field-error">${esc(window.t('forms.fill.err_required'))}</div>
                        </div>`;
                        break;
                    }
                    case 'checkboxes': {
                        const opts = f.options ? JSON.parse(f.options) : [];
                        // Multi-checkbox group — each option is its own
                        // <input type="checkbox">; submitForm reads the
                        // wrapper's [data-field-kind="checkboxes"] and
                        // collects every checked value into an array.
                        html += `<div class="form-field choice-field" ${reqAttr} data-field-id="${f.id}" data-field-kind="checkboxes">
                            <label>${esc(f.label)}${reqStar}</label>
                            ${opts.map((o, i) => `
                                <div class="choice-row">
                                    <input type="checkbox" name="field_${f.id}[]" value="${esc(o)}" id="c_${f.id}_${i}">
                                    <label for="c_${f.id}_${i}">${esc(o)}</label>
                                </div>
                            `).join('')}
                            <div class="field-error">${esc(window.t('forms.fill.err_checkboxes'))}</div>
                        </div>`;
                        break;
                    }
                }
            });

            html += `<div class="form-actions">
                <button type="submit" class="btn btn-primary">${esc(window.t('forms.fill.submit'))}</button>
                <a href="./" class="btn btn-secondary">${esc(window.t('forms.fill.cancel'))}</a>
            </div>`;
            html += '</form>';
            html += '<div class="submit-message" id="submitMessage"></div>';

            card.innerHTML = html;
        }

        async function submitForm(e) {
            e.preventDefault();

            // Clear errors
            document.querySelectorAll('.form-field.has-error').forEach(el => el.classList.remove('has-error'));

            // Collect values
            const data = {};
            let valid = true;

            formData.fields.forEach(f => {
                // The wrapper carries data-field-id for radio +
                // checkboxes groups; for everything else it's on the
                // input itself. Read the wrapper first so the lookup
                // works for both shapes.
                const wrapper = document.querySelector(`.form-field[data-field-id="${f.id}"]`);
                const el = wrapper ? null : document.querySelector(`[data-field-id="${f.id}"]`);

                let value;
                let isEmpty = false;
                if (f.field_type === 'checkbox') {
                    // Single yes/no toggle — '1' or '0'.
                    if (!el) return;
                    value = el.checked ? '1' : '0';
                    isEmpty = !el.checked;
                } else if (f.field_type === 'radio') {
                    // Single-select from a group — the value is the
                    // checked radio's value (or empty string if none).
                    const picked = wrapper && wrapper.querySelector('input[type="radio"]:checked');
                    value = picked ? picked.value : '';
                    isEmpty = !picked;
                } else if (f.field_type === 'checkboxes') {
                    // Multi-select — collect every ticked value into
                    // an array and serialise as JSON. submit_form.php
                    // stores it as a JSON string; submissions.php
                    // decodes for display.
                    const picked = wrapper ? wrapper.querySelectorAll('input[type="checkbox"]:checked') : [];
                    const arr = Array.from(picked).map(p => p.value);
                    value = JSON.stringify(arr);
                    isEmpty = arr.length === 0;
                } else {
                    if (!el) return;
                    value = (el.value || '').trim();
                    isEmpty = !value;
                }

                data[f.id] = value;

                if (f.is_required == 1 && isEmpty) {
                    (wrapper || el.closest('.form-field')).classList.add('has-error');
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
</body>
</html>
