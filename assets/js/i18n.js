/**
 * i18n — Client-side translation lookup mirroring the PHP I18n::t() contract.
 *
 * Reads from window.translations which is populated by the host page from
 * I18n::exportForJs() at render time. The PHP-side export already merges
 * English fallback into the active locale per key, so the JS lookup only
 * needs to walk the dotted path — no fallback chain needed here.
 *
 *   t('common.save')                -> "Save" (or "Enregistrer" if locale is fr)
 *   t('common.welcome', {name: 'Ed'}) -> uses {name} placeholder substitution
 *
 * Missing keys return the key itself so unfilled strings are visible in the UI.
 *
 * @wiki Internationalisation
 */
(function () {
    'use strict';

    /**
     * Fills {name} placeholders from params and turns a literal \n into a real line break.
     *
     * A placeholder with no matching param is left as it is.
     */
    function interpolate(s, params) {
        // A translation authored with a literal "\n" (single-quoted PHP strings
        // don't turn \n into a real newline) should still render as a line break.
        // Consumers like the confirm dialog honour real newlines via
        // white-space:pre-wrap, so normalise the two-character sequence here and
        // both quote styles behave identically across every module and locale.
        if (typeof s === 'string' && s.indexOf('\\n') !== -1) {
            s = s.replace(/\\n/g, '\n');
        }
        if (!params || typeof params !== 'object') return s;
        return s.replace(/\{(\w+)\}/g, function (_, k) {
            return Object.prototype.hasOwnProperty.call(params, k) ? String(params[k]) : '{' + k + '}';
        });
    }

    /**
     * Translates a dotted key from window.translations and fills its placeholders (exposed as window.t).
     *
     * @return the translation, or the key itself when it is missing
     */
    function lookup(key, params) {
        if (typeof key !== 'string') return key;
        var translations = window.translations || {};
        var parts = key.split('.');
        if (parts.length < 2) return interpolate(key, params); // No namespace - usage error
        var cursor = translations;
        for (var i = 0; i < parts.length; i++) {
            if (cursor && typeof cursor === 'object' && Object.prototype.hasOwnProperty.call(cursor, parts[i])) {
                cursor = cursor[parts[i]];
            } else {
                return key; // missing - surface the key
            }
        }
        return typeof cursor === 'string' ? interpolate(cursor, params) : key;
    }

    /**
     * Translate, or fall back to the English passed at the call site.
     *
     * The JavaScript counterpart of includes/i18n_guarded.php's tr(). Shared
     * files like confirm.js and command-palette.js are pulled in by pages that
     * do not all export window.translations, and a missing key currently
     * renders as the key itself - which is right during development and wrong
     * in front of a user. Passing the English makes the worst case "the string
     * it always showed" rather than "common.palette.search_ph".
     */
    function lookupOr(key, english, params) {
        var out = lookup(key, params);
        // The fallback has to go through interpolate() too. Returning the
        // raw English left "Tickets {verb} per {unit}" on screen for every
        // key that had not been added yet - which, on an English install,
        // is the ONLY path this function ever takes.
        return (out === key || out === '' || out === undefined) ? interpolate(english, params) : out;
    }

    /**
     * A value as a JavaScript string argument inside an inline handler:
     *     `<button onclick="deleteTeam(${id}, ${jsArg(name)})">`   - note: NO quotes around it.
     *
     * 🔴 TRAP: never `onclick="fn('${escapeHtml(x)}')"`. The browser HTML-decodes an
     * attribute BEFORE running it as script, so the &#39; that escapeHtml() makes is a
     * real ' again by the time the JavaScript is read, and a value like  x');alert(1);//
     * ends the string and runs. Adding .replace(/'/g, "\\'") after escapeHtml() does
     * nothing at all - there is no ' left for it to find. Until 5.1.0 fifty-eight
     * handlers did one or the other (software names from inventory agents among them).
     *
     * JSON.stringify() makes a complete string literal, quotes included; entity-encoding
     * it makes it safe inside the attribute. After the browser decodes the attribute the
     * script sees exactly the JSON literal. tests/inline-handler-strings.php guards it.
     * It lives here because i18n.js is loaded on every page that builds these handlers.
     *
     * @param {*} value  anything; null and undefined become ""
     * @returns {string}  e.g. &quot;O&#39;Brien&quot;
     */
    function jsArg(value) {
        return JSON.stringify(value === null || value === undefined ? '' : String(value))
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Expose globally
    window.t = lookup;
    window.tf = lookupOr;
    window.jsArg = jsArg;
})();
