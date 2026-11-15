/**
 * Location dropdowns on people, tickets, contracts and projects (GH #311).
 *
 * One helper for all four: fills a <select> with the locations a record in a
 * company may have - that company's own and the shared ones, each as its full
 * path ("UK > London > Floor 2") - from api/locations/options.php. The list is
 * fetched once per company per page.
 *
 *   LocationPicker.fill(select, companyId, currentId, currentPath, basePath)
 *
 * The record's current location stays selectable even when the list no longer
 * offers it, so opening a record never quietly changes it.
 *
 * @wiki Locations-Developer-Guide
 */
(function () {
    'use strict';
    var cache = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * The locations for a company: [{id, path, shared}].
     *
     * @param {number|null} companyId  null for the Default company
     * @param {string} basePath  the app root relative to this page, e.g. '../'
     */
    function load(companyId, basePath) {
        var key = String(companyId || 0);
        if (!cache[key]) {
            cache[key] = fetch((basePath || '../') + 'api/locations/options.php?company_id=' + encodeURIComponent(companyId || 0),
                { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { return d && d.success ? d.locations || [] : []; })
                .catch(function () { delete cache[key]; return []; });
        }
        return cache[key];
    }

    /**
     * Fill a select: a blank "no location" choice, then the company's locations.
     *
     * @param {HTMLSelectElement} select
     * @param {number|null} companyId  the RECORD's company
     * @param {number|null} currentId  the location it has now
     * @param {string|null} currentPath  its path, shown if the list no longer offers it
     * @param {string} [basePath]  app root relative to the page ('../' by default)
     * @returns {Promise<void>}
     */
    function fill(select, companyId, currentId, currentPath, basePath) {
        if (!select) return Promise.resolve();
        return load(companyId, basePath).then(function (list) {
            var cur = currentId ? Number(currentId) : 0;
            var html = '<option value=""></option>';
            var found = false;
            list.forEach(function (l) {
                if (Number(l.id) === cur) found = true;
                html += '<option value="' + Number(l.id) + '"' + (Number(l.id) === cur ? ' selected' : '') + '>' + esc(l.path) + '</option>';
            });
            if (cur && !found) {
                html += '<option value="' + cur + '" selected>' + esc(currentPath || ('#' + cur)) + '</option>';
            }
            select.innerHTML = html;
        });
    }

    /** Forget the cached lists - after a location is added elsewhere on the page. */
    function reset() { cache = {}; }

    window.LocationPicker = { fill: fill, load: load, reset: reset };
})();
