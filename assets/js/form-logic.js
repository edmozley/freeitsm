/**
 * FormLogic — the one shared brain for the Forms module.
 *
 * The three places a form gets drawn (the builder preview, forms/fill.php and the
 * portal's self-service/catalogue.php) each keep their own markup and CSS, because
 * they genuinely look different and merging them would change how existing forms
 * render. What they must NOT each keep their own copy of is the *thinking*: which
 * types exist, which carry options, and whether a field is currently visible.
 * That all lives here.
 *
 * ⚠️ Mirrors includes/form_logic.php, which is the source of truth — the server
 * re-evaluates visibility on submit, because a browser can simply not run this.
 * Change one, change the other; tests/forms-logic runs the same cases through both.
 *
 * @wiki Form-Sections-and-Conditional-Logic-Developer-Guide
 */
(function (global) {
    'use strict';

    // Every type the module knows. 'section' is presentational — a heading that owns
    // the fields below it until the next section, and never produces an answer.
    var TYPES = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'dropdown', 'radio', 'datetime', 'lookup', 'section', 'note', 'image', 'grid'];

    /* The types that are READ, not answered. Mirrors
       FormsService::PRESENTATIONAL_TYPES — see isAnswerable() for why this is a
       list rather than a comparison written out in thirteen places. */
    var PRESENTATIONAL = ['section', 'note', 'image'];
    var WITH_OPTIONS = ['dropdown', 'radio', 'checkboxes'];
    var MULTI_VALUE  = ['checkboxes'];

    // What a 'datetime' field asks for, held in config.date_mode. ONE type with a mode
    // rather than three types, because field_type cannot be changed once a field exists.
    var DATE_MODES = ['date', 'time', 'datetime'];
    var DATE_MODE_DEFAULT = 'date';

    // A lookup answer is JSON: {"id":11,"label":"LT-001"}. The LABEL is what a
    // condition compares against, because 'is LT-001' is what a form builder
    // means — nobody writes a rule against a database id.
    function lookupLabel(raw) {
        if (raw === null || raw === undefined || raw === '') return '';
        try { var d = JSON.parse(raw); return (d && d.label) ? String(d.label) : ''; }
        catch (e) { return ''; }
    }
    /**
     * A field's config as an object.
     *
     * ⚠️ It arrives from get_form.php as a raw JSON STRING — the endpoint selects
     * the column and hands it to json_encode untouched — but callers elsewhere
     * may already have parsed it. Accept both, and never throw: a field with
     * unreadable config should lose its settings, not its form.
     */
    function configOf(field) {
        var c = field && field.config;
        if (typeof c === 'string') { try { c = JSON.parse(c); } catch (e) { c = null; } }
        return (c && typeof c === 'object') ? c : {};
    }

    /** The record type a lookup field searches (its config's lookup_source), or '' when none is set. */
    function lookupSource(field) {
        var c = configOf(field);
        return c.lookup_source ? String(c.lookup_source) : '';
    }

    /**
     * A field's width in twelfths. Absent means full, which is every field that
     * predates the layout work.
     *
     * 🔑 WIDTHS mirrors FormsService::FIELD_WIDTHS. Two lists that have to agree
     * is exactly the drift that bit the index backfill, so anything not on the
     * list falls back to full rather than being trusted — a bad value cannot
     * reach here through the service, but it can through a hand-edited row.
     */
    function fieldWidth(field) {
        var w = parseInt(configOf(field).width, 10);
        return WIDTHS.indexOf(w) !== -1 ? w : WIDTH_DEFAULT;
    }

    /* Twelfths. Mirrors FormsService::FIELD_WIDTHS — see
       docs/design/form-layout-and-grid.md for why 12. */
    var WIDTHS = [12, 9, 8, 6, 4, 3];
    var WIDTH_DEFAULT = 12;

    /**
     * Does this field type collect an answer?
     *
     * 🔴 This was `type !== 'section'`, written out again in PHP, in SQL and in
     * two more JavaScript files. One presentational type made that harmless;
     * a second makes every un-updated copy treat standing text as a question —
     * collected on submit, required if somebody ticked required, and a column in
     * every export. Mirrors FormsService::PRESENTATIONAL_TYPES.
     *
     * ⚠️ NOT the same question as "may a condition depend on this". A grid
     * collects an answer and still cannot be a condition trigger.
     */
    function isAnswerable(type) { return PRESENTATIONAL.indexOf(type) === -1; }
    /** Whether a field type carries a list of options - drop-down, radio buttons or tickboxes. */
    function hasOptions(type)   { return WITH_OPTIONS.indexOf(type) !== -1; }
    /** Whether a field type's answer is a list of several values rather than one. */
    function isMultiValue(type) { return MULTI_VALUE.indexOf(type) !== -1; }

    /**
     * A note's named style, defaulting when unset or unrecognised.
     *
     * 🔴 A NAME, never a colour. Mirrors FormsService::NOTE_STYLES. Anything not
     * on the list falls back rather than being trusted through — the service
     * refuses a bad value on save, but a hand-edited row can still carry one,
     * and an unknown style must not reach the DOM as an attribute selector that
     * matches no rule and renders an unstyled box.
     */
    function noteStyle(field) {
        var s = configOf(field).note_style;
        return NOTE_STYLES.indexOf(s) !== -1 ? s : NOTE_STYLE_DEFAULT;
    }
    var NOTE_STYLES = ['plain', 'info', 'warning', 'danger', 'success'];
    var NOTE_STYLE_DEFAULT = 'info';

    /**
     * Where a question's label sits. Mirrors FormsService::LABEL_POSITIONS.
     *
     * Anything off the list falls back to 'above' rather than being trusted
     * through — the service refuses a bad value on save, but a hand-edited row
     * can still carry one, and an unknown position would reach the DOM as an
     * attribute selector matching no rule.
     */
    function labelPosition(field) {
        var p = configOf(field).label_position;
        return LABEL_POSITIONS.indexOf(p) !== -1 ? p : LABEL_POSITION_DEFAULT;
    }
    var LABEL_POSITIONS = ['above', 'beside'];
    var LABEL_POSITION_DEFAULT = 'above';

    /**
     * The URL an image block's picture is fetched from.
     *
     * 🔴 BY FIELD ID, never by path. api/forms/image.php reads the stored path
     * out of that field's own config server-side, so no caller-supplied string
     * goes anywhere near the filesystem — directory traversal is removed as a
     * category rather than filtered for. Returning '' when there is no image
     * lets a renderer draw its empty state rather than a broken picture.
     *
     * @param {string} base path prefix, because the three surfaces sit at
     *                 different depths and none of them can assume a root.
     */
    function imageUrl(field, base) {
        if (!configOf(field).image_path) return '';
        return (base || '') + 'api/forms/image.php?field=' + encodeURIComponent(field.id);
    }

    /** How wide an image may draw, as a percentage of its column. */
    function imageMaxWidth(field) {
        var m = parseInt(configOf(field).image_max, 10);
        return IMAGE_MAX_WIDTHS.indexOf(m) !== -1 ? m : IMAGE_MAX_DEFAULT;
    }
    var IMAGE_MAX_WIDTHS = [100, 75, 50, 25];
    var IMAGE_MAX_DEFAULT = 100;

    /* ── A table question's columns ──────────────────────────────────────────
       Mirrors FormsService::GRID_CELL_TYPES. Deliberately RESTRICTED: a file
       upload or a signature pad in a 200px column is unusable, and per-cell
       conditional logic is combinatorial. Widening later is easy; narrowing
       after people have built forms is not. */
    var GRID_CELL_TYPES = ['text', 'number', 'dropdown', 'radio', 'checkbox', 'datetime'];
    var GRID_CELL_TYPES_WITH_OPTIONS = ['dropdown', 'radio'];

    /**
     * A table question's columns AS STORED — retired ones included.
     *
     * 🔑 Reading out an old answer needs the retired ones: a value stored
     * against a withdrawn column must still say what it was, or a submission
     * from last year silently loses a field. Use gridLiveColumns() for anything
     * somebody is about to FILL IN.
     */
    function gridColumns(field) {
        var cols = configOf(field).columns;
        return Array.isArray(cols) ? cols.filter(function (c) { return c && c.id; }) : [];
    }

    /** The columns still being asked — what a filler draws. */
    function gridLiveColumns(field) {
        return gridColumns(field).filter(function (c) { return !c.deleted; });
    }

    /**
     * A table question's answer: always a list of rows, each a map of
     * column id → value.
     *
     * ⚠️ Never throws. An unreadable answer should cost its rows, not the form.
     */
    function gridRows(raw) {
        if (raw === null || raw === undefined || raw === '') return [];
        var parsed = raw;
        if (typeof raw === 'string') {
            try { parsed = JSON.parse(raw); } catch (e) { return []; }
        }
        if (!Array.isArray(parsed)) return [];
        return parsed.filter(function (r) { return r && typeof r === 'object' && !Array.isArray(r); });
    }

    /** A note's optional longer text, beneath its title. */
    function noteBody(field) {
        var b = configOf(field).note_body;
        return (b === null || b === undefined) ? '' : String(b);
    }

    /** The mode of a datetime field, defaulting when unset. Mirrors FormsService::dateModeOf(). */
    function dateMode(field) {
        var mode = configOf(field).date_mode;
        return DATE_MODES.indexOf(mode) !== -1 ? mode : DATE_MODE_DEFAULT;
    }

    /** The HTML input type a given mode needs. */
    function dateInputType(mode) {
        return mode === 'time' ? 'time' : (mode === 'datetime' ? 'datetime-local' : 'date');
    }

    /**
     * Render a stored value for READING (submissions table, CSV, detail drawer).
     *
     * ⚠️ Deliberately does NOT parse to a Date. These are naive local values — "needed
     * by 14 August" means the 14th to whoever typed it — and new Date() would apply the
     * reader's timezone, showing an analyst in another zone the 13th. The only change
     * made is swapping the ISO 'T' for a space so it reads as text rather than a
     * machine format. ISO order is also kept on purpose: dd/mm vs mm/dd is ambiguous
     * across the 21 locales this product ships in, and this is never ambiguous.
     */
    function formatDateValue(raw) {
        return String(raw == null ? '' : raw).replace('T', ' ');
    }

    /** Options come back as a JSON string from the API but as a live array in the builder. */
    function parseOptions(raw) {
        if (!raw) return [];
        if (Array.isArray(raw)) return raw;
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) { return []; }
    }

    /** Same as the PHP: a scalar string plus a list, so one operator handles both shapes. */
    function normaliseValue(raw) {
        var scalar = '', list = [];
        if (Array.isArray(raw)) {
            list = raw.map(String);
            scalar = list.join(', ');
        } else {
            scalar = (raw === null || raw === undefined) ? '' : String(raw).trim();
            if (scalar.charAt(0) === '[') {
                try {
                    var decoded = JSON.parse(scalar);
                    if (Array.isArray(decoded)) {
                        list = decoded.map(String);
                        scalar = list.join(', ');
                    }
                } catch (e) { /* not JSON — treat as plain text */ }
            } else if (scalar.charAt(0) === '{') {
                // A lookup answer. Compare against the LABEL, not the id —
                // mirrors formLogicNormaliseValue() in includes/form_logic.php,
                // which is the copy that actually decides.
                var lbl = lookupLabel(scalar);
                if (lbl !== '') scalar = lbl;
            }
        }
        if (!list.length && scalar !== '') list = [scalar];
        return { scalar: scalar, list: list };
    }

    /**
     * Tests one visibility condition against the current answers.
     *
     * @param rule             {field, op, value} - the field it depends on, the comparison and the value to compare with
     * @param valuesByFieldId  the current answers, keyed by field id
     * @return true when the condition holds
     */
    function testRule(rule, valuesByFieldId) {
        var refId = parseInt(rule.field, 10) || 0;
        var op    = rule.op || 'equals';
        var want  = (rule.value === null || rule.value === undefined) ? '' : String(rule.value).trim();

        var norm   = normaliseValue(valuesByFieldId[refId]);
        var scalar = norm.scalar;
        var list   = norm.list;
        var inList = list.indexOf(want) !== -1;

        switch (op) {
            case 'is_empty':     return scalar === '' || scalar === '0';
            case 'is_not_empty': return scalar !== '' && scalar !== '0';
            case 'equals':       return inList || scalar === want;
            case 'not_equals':   return !(inList || scalar === want);
            case 'contains':
                if (inList) return true;
                return want !== '' && scalar.toLowerCase().indexOf(want.toLowerCase()) !== -1;
            case 'greater_than':
                return scalar !== '' && want !== '' && !isNaN(parseFloat(scalar)) && !isNaN(parseFloat(want))
                    && parseFloat(scalar) > parseFloat(want);
            case 'less_than':
                return scalar !== '' && want !== '' && !isNaN(parseFloat(scalar)) && !isNaN(parseFloat(want))
                    && parseFloat(scalar) < parseFloat(want);

            // Date-shaped comparison. A plain string compare is CORRECT rather than
            // lazy: stored date/time values are ISO-8601, whose lexical order IS
            // chronological order. new Date() would drag in a timezone these naive
            // values deliberately do not have.
            case 'is_after':  return scalar !== '' && want !== '' && scalar > want;
            case 'is_before': return scalar !== '' && want !== '' && scalar < want;
        }
        // Unknown operator must never silently hide a question.
        return true;
    }

    /**
     * fields: array of {id, field_type, config} IN sort_order.
     * values: { fieldId: answer }.
     * Returns { fieldId: true|false }.
     */
    function visibility(fields, values) {
        var out = {}, sectionOk = true;
        (fields || []).forEach(function (f) {
            var cfg = f.config;
            if (typeof cfg === 'string') {
                try { cfg = JSON.parse(cfg); } catch (e) { cfg = null; }
            }
            var own = true;
            var vif = cfg && cfg.visible_if;
            if (vif && Array.isArray(vif.rules) && vif.rules.length) {
                var results = vif.rules.map(function (r) { return testRule(r, values); });
                own = (vif.match === 'any')
                    ? results.indexOf(true) !== -1
                    : results.indexOf(false) === -1;
            }
            if (f.field_type === 'section') {
                sectionOk = own;
                out[f.id] = own;
                return;
            }
            out[f.id] = own && sectionOk;
        });
        return out;
    }

    /** True when a rule on `field` may point at `candidate` — earlier fields only, which is what stops cycles. */
    function canReference(fields, fieldIndex, candidateIndex) {
        return candidateIndex < fieldIndex && isAnswerable(fields[candidateIndex].field_type);
    }

    /**
     * Wire up every lookup box inside `root`.
     *
     * ⚠️ THE ANSWER IS ONLY EVER WRITTEN BY CHOOSING FROM THE LIST. Typing
     * "my laptop" and walking away leaves the hidden input empty, so the field
     * reads as unanswered rather than storing a name that matches no record.
     * That is the point of a lookup — the server refuses anything else anyway.
     *
     * Markup this expects (each surface supplies its own CSS):
     *   .lookup-wrap > input.lookup-search[data-lookup-field]
     *                > input[type=hidden]
     *                > .lookup-results
     *
     * @param root      element to search within
     * @param searchUrl endpoint taking ?field_id=&q=
     */
    function attachLookups(root, searchUrl) {
        var boxes = (root || document).querySelectorAll('input.lookup-search[data-lookup-field]');
        Array.prototype.forEach.call(boxes, function (box) {
            if (box.getAttribute('data-lookup-ready')) return;
            box.setAttribute('data-lookup-ready', '1');

            var wrap    = box.closest('.lookup-wrap');
            var hidden  = wrap.querySelector('input[type=hidden]');
            var results = wrap.querySelector('.lookup-results');
            var fieldId = box.getAttribute('data-lookup-field');
            var timer   = null;

            /** Hides and empties this lookup box's results list. */
            function close() { results.hidden = true; results.innerHTML = ''; }

            /**
             * Stores the chosen record as {id, label} in the hidden input, shows its label and fires change so visibility rules re-run.
             */
            function choose(id, label) {
                hidden.value = JSON.stringify({ id: id, label: label });
                box.value = label;
                close();
                // Conditional visibility keys off answers, and this one just
                // changed — tell whatever is listening, the same way a native
                // input would.
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }

            /** Clears any earlier choice, then fetches matches for the typed text and lists them as buttons. */
            function search() {
                var q = box.value.trim();
                // Anything typed invalidates a previous choice: the box no
                // longer shows what was chosen, so the answer must not persist.
                if (hidden.value) {
                    hidden.value = '';
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
                fetch(searchUrl + '?field_id=' + encodeURIComponent(fieldId) + '&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j.success || !j.results || !j.results.length) {
                            results.innerHTML = '<div class="lookup-empty">'
                                + ((global.t ? global.t('forms.fill.lookup_none') : 'No matches')) + '</div>';
                            results.hidden = false;
                            return;
                        }
                        results.innerHTML = j.results.map(function (r) {
                            var safe = String(r.label).replace(/[&<>"']/g, function (ch) {
                                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
                            });
                            return '<button type="button" class="lookup-option" data-id="' + r.id
                                 + '" data-label="' + safe + '">' + safe + '</button>';
                        }).join('');
                        results.hidden = false;
                    })
                    .catch(function () { close(); });
            }

            // GH #160: a lookup that starts on an answer (the requester's own cost centre),
            // worked out and checked by the server. Chosen as if picked - the person can
            // still change it - unless something (a restored draft) already answered it.
            var dflt = box.getAttribute('data-lookup-default');
            if (dflt && !hidden.value) {
                try {
                    var d = JSON.parse(dflt);
                    if (d && d.id && d.label) { hidden.value = JSON.stringify({ id: d.id, label: d.label }); box.value = d.label; }
                } catch (e) { /* no default */ }
            }

            box.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(search, 220);   // one request per pause, not per keystroke
            });
            box.addEventListener('focus', function () { if (!results.innerHTML) search(); });

            results.addEventListener('click', function (e) {
                var opt = e.target.closest('.lookup-option');
                if (opt) choose(parseInt(opt.getAttribute('data-id'), 10), opt.getAttribute('data-label'));
            });

            document.addEventListener('click', function (e) {
                if (!wrap.contains(e.target)) close();
            });
        });
    }

    global.FormLogic = {
        TYPES: TYPES,
        DATE_MODES: DATE_MODES,
        DATE_MODE_DEFAULT: DATE_MODE_DEFAULT,
        isAnswerable: isAnswerable,
        PRESENTATIONAL_TYPES: PRESENTATIONAL,
        NOTE_STYLES: NOTE_STYLES,
        NOTE_STYLE_DEFAULT: NOTE_STYLE_DEFAULT,
        noteStyle: noteStyle,
        noteBody: noteBody,
        LABEL_POSITIONS: LABEL_POSITIONS,
        LABEL_POSITION_DEFAULT: LABEL_POSITION_DEFAULT,
        labelPosition: labelPosition,
        IMAGE_MAX_WIDTHS: IMAGE_MAX_WIDTHS,
        IMAGE_MAX_DEFAULT: IMAGE_MAX_DEFAULT,
        imageUrl: imageUrl,
        imageMaxWidth: imageMaxWidth,
        GRID_CELL_TYPES: GRID_CELL_TYPES,
        GRID_CELL_TYPES_WITH_OPTIONS: GRID_CELL_TYPES_WITH_OPTIONS,
        gridColumns: gridColumns,
        gridLiveColumns: gridLiveColumns,
        gridRows: gridRows,
        hasOptions: hasOptions,
        isMultiValue: isMultiValue,
        dateMode: dateMode,
        configOf: configOf,
        fieldWidth: fieldWidth,
        FIELD_WIDTHS: WIDTHS,
        dateInputType: dateInputType,
        formatDateValue: formatDateValue,
        parseOptions: parseOptions,
        normaliseValue: normaliseValue,
        testRule: testRule,
        visibility: visibility,
        canReference: canReference,
        lookupLabel: lookupLabel,
        lookupSource: lookupSource,
        attachLookups: attachLookups
    };
})(window);
