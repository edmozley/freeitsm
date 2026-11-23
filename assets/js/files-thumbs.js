/**
 * Files thumbnails in the browser (GH #424) - see includes/files/thumbnails.php.
 *
 * Pictures are made by the server; the browser only shows them. A PDF's first page
 * can only be drawn by a browser, so this file does that, with the pdf.js the viewer
 * already ships, and sends the small picture to api/files/thumbnail.php:
 *
 *   - from a FILE on the uploader's own computer, straight after an upload
 *     (fromFile) - nothing downloaded;
 *   - from the server, for a PDF that has none yet (fromItem) - only offered to
 *     someone with Upload on the folder, two at a time, for icons on screen.
 *
 * Every drawing is queued, so a folder of 100 PDFs never draws more than two at
 * once, and each PDF is drawn once - the server keeps the result for everybody.
 *
 * @wiki Files-Thumbnails-And-Gallery-Design
 */
(function () {
    'use strict';
    if (window.FilesThumbs) return;

    var B = window.FILES_BOOT || {};
    var SIDE = 400;                       // drawn at this, the server keeps 256
    var MAX_LOCAL_BYTES = 50 * 1024 * 1024;
    var CONCURRENT = 2;

    var pdfjsPromise = null;
    /** pdf.js (an ES module), loaded once - the same files as the viewer. */
    function pdfjs() {
        if (!pdfjsPromise) {
            var V = B.base + 'assets/js/vendor/';
            pdfjsPromise = import(V + 'pdfjs/pdf.min.js').then(function (lib) {
                lib.GlobalWorkerOptions.workerSrc = V + 'pdfjs/pdf.worker.min.js';
                return lib;
            });
        }
        return pdfjsPromise;
    }

    /** Draws page 1 of a PDF (its bytes) on a canvas and returns it as a data: URL. */
    function drawPdf(bytes) {
        var task = null;
        /** Lets pdf.js free the document and its worker's copy (the loading task owns them). */
        var done = function () { try { if (task) task.destroy(); } catch (e) { /* gone */ } };
        return pdfjs().then(function (lib) {
            // TRAP: isEvalSupported false - a PDF can carry font programs; never let pdf.js eval them.
            task = lib.getDocument({ data: new Uint8Array(bytes), isEvalSupported: false });
            return task.promise;
        }).then(function (pdf) {
            return pdf.getPage(1).then(function (page) {
                var v1 = page.getViewport({ scale: 1 });
                var scale = SIDE / Math.max(v1.width, v1.height);
                var vp = page.getViewport({ scale: scale });
                var cv = document.createElement('canvas');
                cv.width = Math.max(1, Math.round(vp.width));
                cv.height = Math.max(1, Math.round(vp.height));
                var ctx = cv.getContext('2d');
                ctx.fillStyle = '#ffffff';            // a PDF page is white unless it says otherwise
                ctx.fillRect(0, 0, cv.width, cv.height);
                return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
                    done();
                    // WebP where the browser can write it (small); PNG otherwise. The server re-encodes either.
                    var d = cv.toDataURL('image/webp', 0.8);
                    return d.indexOf('data:image/webp') === 0 ? d : cv.toDataURL('image/png');
                });
            });
        }).catch(function (e) { done(); throw e; });
    }

    /** POSTs a drawn thumbnail for an item. */
    function put(itemId, dataUrl) {
        return fetch(B.api + 'thumbnail.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'put', item: itemId, data: dataUrl })
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.success) throw new Error(j.error || 'Thumbnail refused');
            return j;
        });
    }

    // ── The queue: at most CONCURRENT drawings at once ─────────────────────
    var queue = [], running = 0, queued = {};
    /** Adds a job (a function returning a promise) unless the same key is already waiting. */
    function enqueue(key, job) {
        if (queued[key]) return queued[key];
        var p = new Promise(function (res, rej) { queue.push({ key: key, job: job, res: res, rej: rej }); });
        queued[key] = p;
        pump();
        return p;
    }
    /** Starts waiting jobs while there is room. */
    function pump() {
        while (running < CONCURRENT && queue.length) {
            var q = queue.shift();
            running++;
            (function (q) {
                Promise.resolve().then(q.job).then(q.res, q.rej).then(function () {
                    running--;
                    delete queued[q.key];
                    pump();
                });
            })(q);
        }
    }

    /**
     * Draws and sends a PDF's thumbnail from the person's own file - straight after they
     * uploaded it, so nothing is downloaded. Quietly does nothing for anything else.
     */
    function fromFile(itemId, file) {
        if (!file || !/\.pdf$/i.test(file.name || '') || file.size > MAX_LOCAL_BYTES) return Promise.resolve(false);
        return enqueue('i' + itemId, function () {
            return file.arrayBuffer().then(drawPdf).then(function (d) { return put(itemId, d); }).then(function () { return true; });
        }).catch(function () { return false; });
    }

    /**
     * Draws and sends the thumbnail of a PDF already in Files that has none - read through
     * thumbnail.php?source=1, which only answers someone with Upload on the folder.
     *
     * @param {boolean} redraw  Make thumbnail on one that has one already
     */
    function fromItem(itemId, redraw) {
        return enqueue('i' + itemId, function () {
            return fetch(B.api + 'thumbnail.php?item=' + itemId + '&source=1' + (redraw ? '&redraw=1' : ''), { credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) return r.text().then(function (t) { throw new Error(t || ('HTTP ' + r.status)); });
                    return r.arrayBuffer();
                })
                .then(drawPdf)
                .then(function (d) { return put(itemId, d); })
                .then(function () { return true; });
        });
    }

    /** The address of an item's thumbnail; sha keeps each version's picture cached on its own. */
    function url(itemId, sha) {
        return B.api + 'thumbnail.php?item=' + itemId + (sha ? '&s=' + encodeURIComponent(sha) : '');
    }

    window.FilesThumbs = { url: url, fromFile: fromFile, fromItem: fromItem, drawPdf: drawPdf };
})();
