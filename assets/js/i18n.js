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
 */
(function () {
    'use strict';

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

    // Expose globally
    window.t = lookup;
})();
