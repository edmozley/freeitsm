<?php
/**
 * Self-Service Portal — Request Catalogue.
 *
 * Customers pick a form ("New laptop", "Access request"), fill it in, and it is
 * recorded as a submission for the service desk to action. Only forms an
 * analyst has deliberately offered appear here (forms.is_portal_visible).
 *
 * The field renderer mirrors the analyst-side forms/fill.php switch, minus its
 * analyst chrome. Every field type in the product (text, textarea, email,
 * number, checkbox, checkboxes, dropdown, radio) is a plain literal input —
 * there is no picker of internal records — so nothing here needs withholding
 * from a customer.
 */
$pageTitleKey = 'self-service.catalogue.title';   // a KEY: i18n starts in header.php
$activeNav    = 'catalogue';

// Deep link to one form: /catalogue.php?id=3. Values reach the script through
// $pageData → window.PAGE, never interpolated into $pageScripts (a nowdoc — a
// PHP tag inside it is emitted verbatim and kills the whole block).
$pageData = ['formId' => (int)($_GET['id'] ?? 0)];

$pageStyles = <<<'CSS'
.cat-header { margin-bottom: 20px; }
        .cat-header h1 {
            font-size: 22px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 6px 0;
        }
        .cat-header p { font-size: 14px; color: var(--text-muted, #666); margin: 0; }

        .cat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
        }
        .cat-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 18px 20px;
            cursor: pointer;
            text-align: left;
            font-family: inherit;
            width: 100%;
        }
        .cat-card:hover { border-color: var(--ss-accent, #0078d4); }
        .cat-card-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 6px;
        }
        .cat-card-desc { font-size: 13px; color: var(--text-muted, #666); line-height: 1.5; }

        .cat-form {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 28px 32px;
            max-width: 720px;
        }
        .cat-form h1 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 6px 0;
        }
        .cat-form-desc {
            font-size: 14px;
            color: var(--text-muted, #666);
            margin-bottom: 22px;
            line-height: 1.5;
        }
        .cat-field { margin-bottom: 18px; }
        .cat-field label.cat-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 6px;
        }
        .cat-req { color: var(--danger-text, #c33); margin-left: 2px; }
        .cat-field input[type="text"],
        .cat-field input[type="email"],
        .cat-field input[type="number"],
        .cat-field select,
        .cat-field textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 6px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-family: inherit;
            font-size: 14px;
        }
        .cat-field textarea { min-height: 110px; resize: vertical; }
        .cat-field input:focus, .cat-field select:focus, .cat-field textarea:focus {
            outline: none;
            border-color: var(--ss-accent, #0078d4);
        }
        .cat-option {
            display: block;
            font-weight: 400;
            font-size: 14px;
            color: var(--text, #333);
            margin: 4px 0;
        }
        .cat-actions { display: flex; gap: 10px; align-items: center; margin-top: 24px; }
        .cat-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ss-accent, #0078d4);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
        }
        .cat-back:hover { text-decoration: underline; }
        .cat-empty {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 40px 24px;
            text-align: center;
        }
        .cat-empty-title { font-size: 15px; font-weight: 600; color: var(--text, #333); margin-bottom: 6px; }
        .cat-empty-hint { font-size: 13px; color: var(--text-muted, #666); }
CSS;

$pageScripts = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
            if (window.PAGE.formId) openForm(window.PAGE.formId, true);
            else loadCatalogue();
        });

        async function loadCatalogue() {
            const container = document.getElementById('catContent');
            try {
                const response = await fetch('../api/self-service/get_catalogue.php');
                const data = await response.json();
                if (!data.success) { catError(); return; }

                const forms = data.forms || [];
                if (!forms.length) {
                    container.innerHTML = '<div class="cat-empty">'
                        + '<div class="cat-empty-title">' + esc(window.t('self-service.catalogue.empty')) + '</div>'
                        + '<div class="cat-empty-hint">' + esc(window.t('self-service.catalogue.empty_hint')) + '</div></div>';
                    return;
                }

                container.innerHTML = '<div class="cat-grid">' + forms.map(f =>
                    '<button type="button" class="cat-card" onclick="openForm(' + f.id + ')">'
                    + '<div class="cat-card-title">' + esc(f.title || '') + '</div>'
                    + (f.description ? '<div class="cat-card-desc">' + esc(f.description) + '</div>' : '')
                    + '</button>'
                ).join('') + '</div>';
            } catch (e) { catError(); }
        }

        async function openForm(id, isDeepLink) {
            const container = document.getElementById('catContent');
            try {
                const response = await fetch('../api/self-service/get_catalogue_form.php?id=' + encodeURIComponent(id));
                const data = await response.json();
                if (!data.success) {
                    container.innerHTML = backBtn()
                        + '<div class="cat-empty"><div class="cat-empty-title">'
                        + esc(window.t('self-service.catalogue.not_found')) + '</div></div>';
                    return;
                }
                renderForm(data.form);
                if (!isDeepLink && window.history && window.history.pushState) {
                    window.history.pushState({ formId: id }, '', 'catalogue.php?id=' + id);
                }
            } catch (e) { catError(); }
        }

        // Mirrors the analyst renderer (forms/fill.php) one case per field type.
        function renderForm(form) {
            const container = document.getElementById('catContent');
            const fields = (form.fields || []).map(f => {
                const req = f.is_required == 1
                    ? '<span class="cat-req" title="' + esc(window.t('self-service.catalogue.required')) + '">*</span>' : '';
                const label = '<label class="cat-label" for="f' + f.id + '">' + esc(f.label || '') + req + '</label>';
                let input = '';

                switch (f.field_type) {
                    case 'textarea':
                        input = '<textarea id="f' + f.id + '" data-field-id="' + f.id + '"></textarea>';
                        break;
                    case 'email':
                        input = '<input type="email" id="f' + f.id + '" data-field-id="' + f.id + '">';
                        break;
                    case 'number':
                        input = '<input type="number" id="f' + f.id + '" data-field-id="' + f.id + '">';
                        break;
                    case 'checkbox':
                        input = '<label class="cat-option"><input type="checkbox" id="f' + f.id + '" data-field-id="' + f.id + '"> '
                              + esc(window.t('self-service.catalogue.yes')) + '</label>';
                        break;
                    case 'dropdown':
                        input = '<select id="f' + f.id + '" data-field-id="' + f.id + '"><option value=""></option>'
                              + parseOptions(f.options).map(o => '<option value="' + esc(o) + '">' + esc(o) + '</option>').join('')
                              + '</select>';
                        break;
                    case 'radio':
                        input = '<div data-field-id="' + f.id + '" data-group="radio">'
                              + parseOptions(f.options).map(o =>
                                  '<label class="cat-option"><input type="radio" name="rf' + f.id + '" value="' + esc(o) + '"> '
                                  + esc(o) + '</label>').join('')
                              + '</div>';
                        break;
                    case 'checkboxes':
                        input = '<div data-field-id="' + f.id + '" data-group="checkboxes">'
                              + parseOptions(f.options).map(o =>
                                  '<label class="cat-option"><input type="checkbox" value="' + esc(o) + '"> '
                                  + esc(o) + '</label>').join('')
                              + '</div>';
                        break;
                    default:   // text
                        input = '<input type="text" id="f' + f.id + '" data-field-id="' + f.id + '">';
                }
                return '<div class="cat-field">' + label + input + '</div>';
            }).join('');

            container.innerHTML = backBtn()
                + '<div class="cat-form">'
                +   '<h1>' + esc(form.title || '') + '</h1>'
                +   (form.description ? '<div class="cat-form-desc">' + esc(form.description) + '</div>' : '')
                +   '<form id="catForm" onsubmit="return false;">' + fields + '</form>'
                +   '<div class="cat-actions">'
                +     '<button type="button" class="btn btn-primary" id="catSubmit" onclick="submitForm(' + form.id + ')">'
                +       esc(window.t('self-service.catalogue.submit')) + '</button>'
                +   '</div>'
                + '</div>';
        }

        function parseOptions(raw) {
            if (!raw) return [];
            try {
                const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) { return []; }
        }

        // Collect answers in the shape the service expects: field_id => value.
        function collectAnswers() {
            const data = {};
            document.querySelectorAll('#catForm [data-field-id]').forEach(el => {
                const id = el.getAttribute('data-field-id');
                const group = el.getAttribute('data-group');
                if (group === 'radio') {
                    const picked = el.querySelector('input[type="radio"]:checked');
                    if (picked) data[id] = picked.value;
                } else if (group === 'checkboxes') {
                    const ticked = Array.from(el.querySelectorAll('input[type="checkbox"]:checked')).map(c => c.value);
                    data[id] = JSON.stringify(ticked);
                } else if (el.type === 'checkbox') {
                    data[id] = el.checked ? '1' : '0';
                } else {
                    data[id] = el.value;
                }
            });
            return data;
        }

        async function submitForm(formId) {
            const btn = document.getElementById('catSubmit');
            btn.disabled = true;
            btn.textContent = window.t('self-service.catalogue.submitting');

            try {
                const response = await fetch('../api/self-service/submit_catalogue_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ form_id: formId, data: collectAnswers() })
                });
                const data = await response.json();

                if (!data.success) {
                    // The service's validation messages name the field, so show them.
                    notice(data.error || window.t('self-service.catalogue.failed'), true);
                    return;
                }
                document.getElementById('catContent').innerHTML = backBtn()
                    + '<div class="cat-empty">'
                    + '<div class="cat-empty-title">' + esc(window.t('self-service.catalogue.sent')) + '</div>'
                    + '<div class="cat-empty-hint">' + esc(window.t('self-service.catalogue.sent_hint')) + '</div></div>';
            } catch (e) {
                notice(window.t('self-service.catalogue.failed'), true);
            } finally {
                const b = document.getElementById('catSubmit');
                if (b) { b.disabled = false; b.textContent = window.t('self-service.catalogue.submit'); }
            }
        }

        /**
         * The app-wide toast, so portal messages match the rest of FreeITSM.
         *
         * Note what does NOT use it: the "Request submitted" confirmation is a
         * page STATE, not a passing message — the form is replaced by it and the
         * person needs to see where they've got to. A toast that fades after a
         * few seconds would leave them staring at a blank panel wondering
         * whether it worked.
         */
        function notice(message, isError) {
            if (typeof showToast === 'function') {
                showToast(message, isError ? 'error' : 'success');
                return;
            }
            alert(message);
        }

        function backBtn() {
            return '<button type="button" class="cat-back" onclick="backToCatalogue()">&lsaquo; '
                 + esc(window.t('self-service.catalogue.back')) + '</button>';
        }

        function backToCatalogue() {
            if (window.history && window.history.pushState) window.history.pushState({}, '', 'catalogue.php');
            loadCatalogue();
        }

        window.addEventListener('popstate', function (e) {
            if (e.state && e.state.formId) openForm(e.state.formId, true);
            else backToCatalogue();
        });

        function catError() {
            document.getElementById('catContent').innerHTML =
                '<div class="cat-empty"><div class="cat-empty-title">'
                + esc(window.t('self-service.catalogue.failed')) + '</div></div>';
        }

        function esc(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : text;
            return div.innerHTML;
        }
JS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="cat-header">
        <h1><?php echo htmlspecialchars(t('self-service.catalogue.heading')); ?></h1>
        <p><?php echo htmlspecialchars(t('self-service.catalogue.lede')); ?></p>
    </div>

    <div id="catContent">
        <div class="cat-empty"><div class="cat-empty-hint"><?php echo htmlspecialchars(t('self-service.catalogue.loading')); ?></div></div>
    </div>
<?php
require_once __DIR__ . '/includes/footer.php';
