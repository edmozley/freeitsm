/**
 * CMDB reference summary for delete dialogs (GH #312).
 *
 * A CMDB property can point at a real record - a person, analyst, team,
 * location, company, supplier or contract. Deleting that record is still
 * allowed (Ed, 2026-11-14): the CI then shows "(removed)". This helper lets a
 * delete dialog say so BEFORE the person confirms:
 *
 *   const note = await cmdbRefSummary('person', 42);
 *   if (!(await showConfirm({ title, message, detailHtml: note, ... }))) return;
 *
 * Resolves to an HTML string, already escaped, or '' when nothing points at the
 * record, the person has no CMDB module, or the lookup fails. It never rejects:
 * a failed lookup must not stand between someone and a delete they asked for.
 *
 * Asks api/cmdb/record_references.php, found next to this file's own URL so it
 * works from any page depth. Strings: common.cmdb_refs.* (tf() carries the
 * English for pages that do not load the common namespace).
 *
 * @wiki CMDB-Record-References-Developer-Guide
 */
(function () {
    'use strict';
    if (window.cmdbRefSummary) return;

    // TRAP: document.currentScript is only set while this file first runs, so
    // the endpoint URL is worked out now, not inside the function.
    var src = (document.currentScript && document.currentScript.src) || '';
    var cut = src.indexOf('assets/js/cmdb-ref-summary.js');
    var ENDPOINT = (cut >= 0 ? src.slice(0, cut) : '../') + 'api/cmdb/record_references.php';

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function tr(key, english, params) {
        return window.tf ? window.tf('common.cmdb_refs.' + key, english, params)
            : english.replace(/\{(\w+)\}/g, function (m, k) { return params && k in params ? String(params[k]) : m; });
    }

    /**
     * @param {string} type  person | analyst | team | location | company | supplier | contract
     * @param {number} id    the record's id
     * @returns {Promise<string>}  escaped HTML, or ''
     */
    window.cmdbRefSummary = function (type, id) {
        return fetch(ENDPOINT + '?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id),
            { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var count = d && d.success ? parseInt(d.count, 10) || 0 : 0;
                if (count <= 0) return '';
                var items = Array.isArray(d.items) ? d.items : [];
                var hidden = parseInt(d.hidden, 10) || 0;
                var more = Math.max(0, count - hidden - items.length);

                // Each piece is escaped on its own, then joined - so a CI named
                // "<b>" stays text, and the translated glue around it is text too.
                var line = function (html, first) {
                    return '<div' + (first ? '' : ' style="margin-top:4px"') + '>' + html + '</div>';
                };
                var out = line('<strong>' + esc(count === 1
                    ? tr('points_one', '1 configuration item points at this record.')
                    : tr('points_other', '{count} configuration items point at this record.', { count: count })) + '</strong>', true);

                if (items.length) {
                    var names = items.map(function (it) {
                        return esc(it.name) + (it.property ? ' (' + esc(it.property) + ')' : '');
                    }).join(', ');
                    if (more > 0) names += ' ' + esc(tr('more', 'and {count} more', { count: more }));
                    out += line(names + '.');
                }
                if (hidden > 0) {
                    out += line(esc(hidden === 1
                        ? tr('hidden_one', '1 of them is in a company you cannot see.')
                        : tr('hidden_other', '{count} of them are in companies you cannot see.', { count: hidden })));
                }
                out += line(esc(count === 1
                    ? tr('removed_one', 'After the delete, that item will show "(removed)" instead.')
                    : tr('removed_other', 'After the delete, those items will show "(removed)" instead.')));
                return '<div class="cmdb-ref-summary">' + out + '</div>';
            })
            .catch(function () { return ''; });
    };
})();
