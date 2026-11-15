<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal — Request Catalogue.
 *
 * Customers pick a form ("New laptop", "Access request"), fill it in, and it is
 * recorded as a submission for the service desk to action. Only forms an
 * analyst has deliberately offered appear here (forms.is_portal_visible).
 *
 * The field renderer mirrors the analyst-side forms/fill.php switch, minus its
 * analyst chrome.
 *
 * ⚠️ THAT USED TO BE THE WHOLE STORY. Every type was a plain literal input —
 * text, number, a dropdown of the form's own options — so nothing a customer
 * could see came out of our own records, and nothing here needed withholding.
 *
 * `lookup` broke that assumption: it is a search box over the install's own
 * assets, CMDB objects or users. So it renders here ONLY when both gates pass —
 * the source is marked portal-safe (a staff directory never is) and the field
 * itself was ticked for portal use by whoever built the form. Off by default.
 *
 * ⚠️ This markup appearing is NOT permission. api/forms/lookup_search.php
 * re-checks both gates and scopes results to the customer's own company, because
 * a request can be made without ever loading this page.
 */
$pageTitleKey = 'self-service.catalogue.title';   // a KEY: i18n starts in header.php
$activeNav    = 'catalogue';

/* 🔴 The 'forms' namespace, on top of header.php's default ['common','self-service'].
   This page renders a form and already calls window.t('forms.fill.lookup_placeholder'),
   but that namespace was never exported to it — and i18n.js surfaces the KEY on a
   miss, deliberately. So a customer opening a form with a lookup field saw a search
   box whose placeholder read "forms.fill.lookup_placeholder", in every language.
   The shared renderer's unsupported-type message lives in the same namespace. */
$translationNamespaces = ['common', 'self-service', 'forms'];

// Deep link to one form: /catalogue.php?id=3. Values reach the script through
// $pageData → window.PAGE, never interpolated into $pageScripts (a nowdoc — a
// PHP tag inside it is emitted verbatim and kills the whole block).
$pageData = ['formId' => (int)($_GET['id'] ?? 0)];

// Loads assets/js/form-logic.js (see includes/footer.php) — the shared field-type
// and conditional-visibility rules this page's renderer calls into.
$needsFormLogic = true;

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
        /* The shop card (GH #348): a picture on top, the price at the foot. A card
           without either is the card it always was. */
        .cat-card.has-image { padding: 0; overflow: hidden; display: flex; flex-direction: column; }
        .cat-card.has-image .cat-card-body { padding: 14px 18px 16px; display: flex; flex-direction: column; flex: 1; }
        .cat-card-img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; background: var(--surface-2, #f3f4f6); }
        .cat-card-body { display: flex; flex-direction: column; height: 100%; }
        .cat-card-price { margin-top: auto; padding-top: 10px; font-size: 15px; font-weight: 600; color: var(--ss-accent, #0078d4); }
        .cat-form-price { margin: -2px 0 12px; font-size: 16px; font-weight: 600; color: var(--ss-accent, #0078d4); }
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
            /* Shared with the analyst filler. This was 720px while the filler
               was 860px, so the same form was 140px narrower for the customer
               it was written for. See --form-card-max in form-shared.css. */
            max-width: var(--form-card-max, 1040px);
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

        /* A section heading — groups the fields beneath it, and hiding it hides
           that whole group. */
        .cat-section {
            margin: 26px 0 14px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border, #e5e7eb);
        }
        .cat-section:first-child { margin-top: 0; }
        .cat-section h2 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0;
        }


        /* Lookup field — search over records the app already holds. */
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

        /* Conditionally hidden — toggled by applyVisibility() as answers change. */
        .cat-field.is-hidden, .cat-section.is-hidden { display: none; }
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
        .cat-field input[type="date"],
        .cat-field input[type="time"],
        .cat-field input[type="datetime-local"],
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

                // GH #348: the one line when there is one (the server falls back to
                // the description), a picture and a price when the item has them.
                container.innerHTML = '<div class="cat-grid">' + forms.map(f => {
                    const text = f.summary || f.description || '';
                    return '<button type="button" class="cat-card' + (f.image_url ? ' has-image' : '') + '" onclick="openForm(' + f.id + ')">'
                        + (f.image_url ? '<img class="cat-card-img" src="' + esc(f.image_url) + '" alt="" loading="lazy">' : '')
                        + '<div class="cat-card-body">'
                        +   '<div class="cat-card-title">' + esc(f.title || '') + '</div>'
                        +   (text ? '<div class="cat-card-desc">' + esc(text) + '</div>' : '')
                        +   (f.price_label ? '<div class="cat-card-price">' + esc(f.price_label) + '</div>' : '')
                        + '</div>'
                        + '</button>';
                }).join('') + '</div>';
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

        // The form currently on screen — applyVisibility() needs its field list
        // (with each field's condition) after render, not just during it.
        let currentForm = null;

        // Mirrors the analyst renderer (forms/fill.php) one case per field type.
        function renderForm(form) {
            const container = document.getElementById('catContent');
            currentForm = form;
            /* The WALK is shared with the filler and the builder preview
               (FormRender); this page keeps its own markup, which is the point —
               see assets/js/form-render.js. */
            const fields = FormRender.render(form.fields, {
              name: 'self-service portal',
              field: function (f, ctx) {
                // A section is a heading, not a question: no label element, no answer.
                if (f.field_type === 'section') {
                    return '<div class="cat-section" ' + ctx.wrapAttrs + '><h2>'
                         + esc(f.label || '') + '</h2></div>';
                }
                /* A table question. Same shape as the analyst filler and the
                   same shared table styling — only the surrounding wrapper
                   differs. Cells are addressed by COLUMN ID, never by position. */
                if (f.field_type === 'grid') {
                    var gcols = FormLogic.gridLiveColumns(f);
                    /* ⚠️ Its OWN caption, not the shared `label` below. Two
                       reasons: that one is declared further down and using it
                       here is a temporal-dead-zone throw that takes the whole
                       form out, and it carries for="fN" pointing at a single
                       input — which a table does not have. */
                    var gLabel = '<span class="cat-label">' + esc(f.label || '')
                               + (f.is_required == 1 ? '<span class="cat-req">*</span>' : '') + '</span>';
                    if (!gcols.length) {
                        return '<div class="cat-field" ' + ctx.wrapAttrs + '>' + gLabel
                             + '<div class="form-table-empty">' + esc(window.t('forms.grid.not_configured')) + '</div></div>';
                    }
                    return '<div class="cat-field form-table-field" ' + ctx.wrapAttrs
                         + ' data-field-id="' + f.id + '" data-group="grid">' + gLabel
                         + '<div class="form-table-wrap"><table class="form-table"><thead><tr>'
                         +   gcols.map(function (c) {
                                 return '<th>' + esc(c.label)
                                      + (c.required ? '<span class="cat-req">*</span>' : '') + '</th>';
                             }).join('')
                         +   '<th class="form-table-rowaction"></th></tr></thead>'
                         +   '<tbody>' + catGridRowHtml(f, gcols) + '</tbody></table></div>'
                         + '<div class="form-table-actions">'
                         +   '<button type="button" class="btn btn-secondary btn-sm" onclick="addCatGridRow(' + f.id + ')">'
                         +     esc(window.t('forms.grid.add_row')) + '</button>'
                         + '</div></div>';
                }
                /* A picture on the form. Fetched by field id through the same
                   authorising endpoint the analyst side uses — which checks a
                   PORTAL session against the form's own visibility, so an image
                   on an unpublished form is a 404 here. */
                if (f.field_type === 'image') {
                    var imgSrc = FormLogic.imageUrl(f, '../');
                    if (!imgSrc) {
                        return '<div class="form-image is-empty" ' + ctx.wrapAttrs + '>'
                             + esc(window.t('forms.image.none')) + '</div>';
                    }
                    var imgPct = FormLogic.imageMaxWidth(f);
                    return '<div class="form-image" ' + ctx.wrapAttrs
                         + (imgPct === 100 ? '' : ' data-image-max="' + imgPct + '"') + '>'
                         + '<img src="' + esc(imgSrc) + '" alt="' + esc(f.label || '') + '" loading="lazy">'
                         + '</div>';
                }
                /* A note is standing text — an instruction or a warning the
                   customer needs before answering. Shared classes with the
                   analyst side deliberately: a notice panel has no reason to
                   look different here, unlike an input. */
                if (f.field_type === 'note') {
                    var noteBody = FormLogic.noteBody(f);
                    return '<div class="form-note" data-note-style="' + esc(FormLogic.noteStyle(f)) + '" ' + ctx.wrapAttrs + '>'
                         + '<p class="form-note-title">' + esc(f.label || '') + '</p>'
                         + (noteBody ? '<p class="form-note-body">' + esc(noteBody) + '</p>' : '')
                         + '</div>';
                }
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
                    case 'datetime':
                        // date / time / datetime-local, per the field's own mode.
                        input = '<input type="' + FormLogic.dateInputType(FormLogic.dateMode(f))
                              + '" id="f' + f.id + '" data-field-id="' + f.id + '">';
                        break;
                    case 'lookup':
                        // ⚠️ Only reachable here if the FIELD was ticked for portal
                        // use AND its source is portal-safe. The endpoint checks
                        // both again — this markup appearing is not permission.
                        input = '<div class="lookup-wrap">'
                              + '<input type="text" class="lookup-search" autocomplete="off"'
                              + ' data-lookup-field="' + f.id + '"'
                              + ' placeholder="' + esc(window.t('forms.fill.lookup_placeholder')) + '">'
                              + '<input type="hidden" id="f' + f.id + '" data-field-id="' + f.id + '">'
                              + '<div class="lookup-results" hidden></div>'
                              + '</div>';
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
                    case 'text':
                        input = '<input type="text" id="f' + f.id + '" data-field-id="' + f.id + '">';
                        break;
                    default:
                        /* 🔴 THIS USED TO BE `default: // text`, so a type the
                           portal had never been taught was drawn as a text box —
                           a control that looks like it works, takes whatever is
                           typed, and stores it as that field's answer. A customer
                           would have no way of knowing. Of the three surfaces this
                           was the worst failure, because the other two only ever
                           dropped the question. 'text' now has its own case and
                           anything unknown goes loudly to FormRender. */
                        return null;
                }
                /* The wrapper's id and width come from the shared walker, so the
                   filler, the portal and the preview cannot disagree about what a
                   missing or bad width means. */
                return '<div class="cat-field" ' + ctx.wrapAttrs + '>' + label + input + '</div>';
              }
            /* Same resolved layout the analyst side renders: a customer sees the
               form laid out as designed, not a portal-specific guess. */
            }, form.layout);

            // Whether there is anything to answer - an item may ask nothing (GH #348).
            const hasQuestions = (form.fields || []).some(f => FormLogic.isAnswerable(f.field_type));
            container.innerHTML = backBtn()
                + '<div class="cat-form">'
                +   '<h1>' + esc(form.title || '') + '</h1>'
                +   (form.price_label ? '<div class="cat-form-price">' + esc(form.price_label) + '</div>' : '')
                +   (form.description ? '<div class="cat-form-desc">' + esc(form.description) + '</div>' : '')
                /* 🔴 cat-form-grid, NOT cat-form-table. Every field here carries
                   data-width from the shared walk, but the twelfths only mean
                   anything inside the grid container — and `cat-form-table` is
                   defined in no stylesheet at all, so the widths were being
                   emitted and then ignored. The portal drew every field full
                   width while the analyst filler laid the same form out in two
                   columns. See .cat-form-grid in assets/css/self-service.css. */
                +   '<form id="catForm" class="cat-form-grid" onsubmit="return false;">' + fields + '</form>'
                +   '<div class="cat-actions">'
                +     '<button type="button" class="btn btn-primary" id="catSubmit" onclick="submitForm(' + form.id + ')">'
                +       esc(window.t('self-service.catalogue.submit')) + '</button>'
                /* An item that asks nothing (GH #348 - a mouse at a price) has no
                   answers to keep, and the draft endpoint refuses it: no draft button. */
                +     (hasQuestions ? '<button type="button" class="btn btn-secondary" onclick="saveCatDraft(' + form.id + ')">'
                +       esc(window.t('forms.draft.save')) + '</button>' : '')
                +   '</div>'
                + '</div>';

            // Any answer can trigger a condition, so re-evaluate on every edit.
            const formEl = document.getElementById('catForm');
            formEl.addEventListener('input', applyVisibility);
            formEl.addEventListener('change', applyVisibility);

            // Lookup boxes. Same shared behaviour as the analyst fill page —
            // the endpoint works out that this is a portal user and scopes to
            // their company, refusing outright if the field is not portal-ticked.
            FormLogic.attachLookups(formEl, '../api/forms/lookup_search.php');

            applyVisibility();
            if (hasQuestions) restoreCatDraft(form.id);
        }

        /* ══ Drafts ═════════════════════════════════════════════════════════
           A request somebody started and could not finish — the cost centre is
           with their manager, the serial number is on a machine upstairs. Saved
           on demand only: nothing a customer has half-typed is stored until
           they ask for it to be. */

        /** Save what is typed so far, hidden branches included. */
        async function saveCatDraft(formId) {
            try {
                const res = await fetch('../api/self-service/draft.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    /* collectAnswers(TRUE) — including fields a condition is
                       currently hiding. A draft is a snapshot of the typing;
                       submit is the one that records what was actually asked. */
                    body: JSON.stringify({ form_id: formId, answers: collectAnswers(true) })
                });
                const data = await res.json();
                notice(data.success ? window.t('forms.draft.saved')
                                    : (data.error || window.t('forms.draft.failed')), !data.success);
            } catch (e) {
                notice(window.t('forms.draft.failed'), true);
            }
        }

        /**
         * Put a saved draft back, if there is one.
         *
         * 🔴 A STALE one is NOT loaded — the form has had a new version and a
         * new version renumbers every question, so the answers would land in
         * the wrong boxes. Say so; do not guess.
         */
        async function restoreCatDraft(formId) {
            try {
                const res  = await fetch('../api/self-service/draft.php?form_id=' + formId);
                const data = await res.json();
                const d    = data && data.draft;
                if (!d) return;
                if (d.stale) { notice(window.t('forms.draft.stale'), true); return; }
                applyCatDraftValues(d.answers || {});
                applyVisibility();
                notice(window.t('forms.draft.restored', { when: d.modified_date }), false);
            } catch (e) { /* no draft is not a failure */ }
        }

        /** Write saved answers back into the controls, by field type. */
        function applyCatDraftValues(answers) {
            (currentForm.fields || []).forEach(function (f) {
                if (!Object.prototype.hasOwnProperty.call(answers, String(f.id))) return;
                var val = answers[String(f.id)];

                if (f.field_type === 'grid') {
                    var body = document.querySelector('.form-table-field[data-field-id="' + f.id + '"] tbody');
                    if (!body) return;
                    var rows = FormLogic.gridRows(val);
                    if (!rows.length) return;
                    var cols = FormLogic.gridLiveColumns(f);
                    body.innerHTML = rows.map(function (r) { return catGridRowHtml(f, cols, r); }).join('');
                    return;
                }

                var wrap = document.querySelector('[data-field-id="' + f.id + '"]');
                if (!wrap) return;

                if (f.field_type === 'radio') {
                    var hit = wrap.querySelector('input[type="radio"][value="' + CSS.escape(String(val)) + '"]');
                    if (hit) hit.checked = true;
                    return;
                }
                if (f.field_type === 'checkboxes') {
                    var list = [];
                    try { var p = JSON.parse(val); if (Array.isArray(p)) list = p.map(String); } catch (e) { /* legacy */ }
                    if (!list.length && val) list = String(val).split(',').map(function (s) { return s.trim(); });
                    wrap.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                        cb.checked = list.indexOf(cb.value) !== -1;
                    });
                    return;
                }
                if (wrap.type === 'checkbox') { wrap.checked = (String(val) === '1'); return; }
                if ('value' in wrap) wrap.value = val;
            });
        }

        function parseOptions(raw) {
            return FormLogic.parseOptions(raw);
        }

        /**
         * Show or hide fields to match the answers so far. The service re-derives
         * this on submit — this copy only exists so the page reacts as someone types.
         */
        function applyVisibility() {
            const vis = FormLogic.visibility(currentForm.fields || [], collectAnswers(true));
            (currentForm.fields || []).forEach(f => {
                const wrap = document.querySelector('[data-wrap-id="' + f.id + '"]');
                if (wrap) wrap.classList.toggle('is-hidden', vis[f.id] === false);
            });
            return vis;
        }

        // Collect answers in the shape the service expects: field_id => value.
        // includeHidden is for the evaluator itself, which needs every current answer
        // to decide what should be shown; the submit path leaves hidden fields out,
        // so what is recorded is what the person was actually asked.
        /* ══ A table question, portal side ══════════════════════════════════
           The same shape as forms/fill.php's, because it is the same answer
           going into the same column — a customer's rows and an analyst's must
           be indistinguishable once stored. */

        function catGridRowHtml(f, cols, values) {
            var v = values || {};
            return '<tr>'
                 + cols.map(function (c) {
                       return '<td data-col="' + c.id + '">' + catGridCellHtml(f.id, c, v[c.id]) + '</td>';
                   }).join('')
                 + '<td class="form-table-rowaction">'
                 +   '<button type="button" class="form-table-remove" onclick="removeCatGridRow(this)" title="'
                 +   esc(window.t('forms.grid.remove_row')) + '">&times;</button>'
                 + '</td></tr>';
        }

        var catGridRadioSeq = 0;
        function catGridCellHtml(fieldId, c, value) {
            var val = (value === undefined || value === null) ? '' : String(value);
            switch (c.type) {
                case 'number':   return '<input type="number" step="any" value="' + esc(val) + '">';
                case 'datetime': return '<input type="date" value="' + esc(val) + '">';
                case 'checkbox': return '<input type="checkbox"' + (val === '1' ? ' checked' : '') + '>';
                case 'dropdown': return '<select><option value=""></option>'
                    + (c.options || []).map(function (o) {
                          return '<option value="' + esc(o) + '"' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>';
                      }).join('') + '</select>';
                case 'radio': {
                    /* Scoped to field + column + ROW, or every row's radios are
                       one group and choosing in row two clears row one.
                       🔴 Allocated ONCE PER CELL. Incrementing inside the loop
                       named every option differently, making four groups of one
                       instead of one group of four — so every option could be
                       ticked at once. Same defect as forms/fill.php. */
                    var group = 'cg_' + fieldId + '_' + c.id + '_' + (catGridRadioSeq++);
                    return (c.options || []).map(function (o) {
                        return '<label class="grid-radio"><input type="radio" name="' + group
                             + '" value="' + esc(o) + '"' + (o === val ? ' checked' : '') + '> ' + esc(o) + '</label>';
                    }).join('');
                }
                default:         return '<input type="text" value="' + esc(val) + '">';
            }
        }

        function addCatGridRow(fieldId) {
            var wrap = document.querySelector('.form-table-field[data-field-id="' + fieldId + '"]');
            if (!wrap || !currentForm) return;
            var body = wrap.querySelector('tbody');
            var f = (currentForm.fields || []).find(function (x) { return Number(x.id) === Number(fieldId); });
            if (!body || !f || body.rows.length >= 500) return;
            /* Built fresh rather than cloned: cloning would duplicate a radio
               group's name and silently join the two rows together. */
            body.insertAdjacentHTML('beforeend', catGridRowHtml(f, FormLogic.gridLiveColumns(f)));
            applyVisibility();
        }

        function removeCatGridRow(btn) {
            var row = btn.closest('tr');
            var body = row && row.parentNode;
            if (!body) return;
            if (body.rows.length <= 1) {
                // Never leave a table with nowhere to type.
                row.querySelectorAll('input, select').forEach(function (el) {
                    if (el.type === 'checkbox' || el.type === 'radio') el.checked = false;
                    else el.value = '';
                });
                return;
            }
            row.remove();
            applyVisibility();
        }

        /** Rows keyed by column id. A row nobody typed into is not an answer. */
        function readCatGridValue(wrap) {
            var rows = [];
            wrap.querySelectorAll('tbody tr').forEach(function (tr) {
                var row = {}, any = false;
                tr.querySelectorAll('td[data-col]').forEach(function (td) {
                    var cid = td.getAttribute('data-col');
                    var cb = td.querySelector('input[type="checkbox"]');
                    if (cb) { row[cid] = cb.checked ? '1' : '0'; if (cb.checked) any = true; return; }
                    var picked = td.querySelector('input[type="radio"]:checked');
                    if (picked) { row[cid] = picked.value; any = true; return; }
                    if (td.querySelector('input[type="radio"]')) { row[cid] = ''; return; }
                    var el = td.querySelector('input, select');
                    if (!el) return;
                    row[cid] = el.value;
                    if (String(el.value).trim() !== '') any = true;
                });
                if (any) rows.push(row);
            });
            return rows;
        }

        function collectAnswers(includeHidden) {
            const data = {};
            document.querySelectorAll('#catForm [data-field-id]').forEach(el => {
                const id = el.getAttribute('data-field-id');
                if (!includeHidden) {
                    const wrap = el.closest('.cat-field');
                    if (wrap && wrap.classList.contains('is-hidden')) return;
                }
                const group = el.getAttribute('data-group');
                if (group === 'grid') {
                    /* A table's answer is a list of rows keyed by column id,
                       sent as JSON in the one value — the same shape the analyst
                       filler produces and the service stores.
                       ⚠️ Handled FIRST because the wrapper also matches the
                       `el.value` fallback below, which would send "undefined". */
                    data[id] = JSON.stringify(readCatGridValue(el));
                } else if (group === 'radio') {
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
                /* The draft has done its job. Not awaited and its failure
                   ignored: the request HAS been sent, and a tidy-up that did
                   not work must never make a customer think it was not. */
                fetch('../api/self-service/draft.php?form_id=' + formId, { method: 'DELETE' })
                    .catch(function () {});

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
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
