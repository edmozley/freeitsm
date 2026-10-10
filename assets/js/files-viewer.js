/**
 * Files — the viewer. Opens a file in a desktop window (or full screen on a
 * phone) without downloading it.
 *
 *   PDF          Mozilla pdf.js (assets/js/vendor/pdfjs/), pages drawn to canvas
 *                as they scroll into view; pdf.js fetches only the byte ranges it
 *                needs, so a 300-page manual opens at page one straight away.
 *   Word .docx   mammoth.js turns the document into plain HTML, which is then
 *                SANITISED here against an allowlist before it touches the page.
 *   Spreadsheets SheetJS, one tab per sheet, cells written as text only.
 *   Images, video, audio  the browser's own elements.
 *   Text and code shown as text, never as markup.
 *   Zip archives  browsed like a folder (SheetJS's own zip reader, XLSX.CFB); any
 *                entry opens in this same viewer. Entries are NOT fetched from the
 *                server one by one - the browser unpacks the zip in memory and the
 *                viewer works from those bytes (pdf.js from data, media from a blob:
 *                URL). Opening or downloading an entry is still audited: view.php's
 *                'entry' action records which one.
 *
 * The bytes come from api/files/view.php through a short-lived token that lives
 * in the person's session; download.php is only used when they may download.
 *
 * VIEW-ONLY (no Download permission): no download button, no right-click, no
 * text selection, nodownload on media, and printing blanks the page. A deterrent,
 * not DRM - the watermark (a per-folder setting) is what makes a leak traceable,
 * and it is drawn over whatever is shown, so it is in any screenshot.
 *
 * TRAP: libraries load on first use (pdf.js alone is ~1.7 MB) - never add them
 * to files/index.php's <script> tags.
 */
(function () {
    'use strict';

    var B   = window.FILES_BOOT;
    var WM  = window.FilesWM;
    var FI  = window.FilesIcons;
    var L   = WM.L;
    var esc = WM.esc;
    var V   = B.base + 'assets/js/vendor/';
    var MAX_PARSE_BYTES = 60 * 1024 * 1024;   // docx / spreadsheets are parsed in the browser
    var MAX_TEXT_BYTES  = 5 * 1024 * 1024;

    function api(body) {
        return fetch(B.api + 'view.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.error || 'Error'); return j; });
    }
    function h(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
    function fmtSize(n) {
        if (n < 1024) return n + ' B';
        var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toFixed(n >= 10 ? 1 : 2) + ' ' + u[i];
    }
    function icon(path) { return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>'; }
    var IC = {
        zoomIn: icon('<circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5M11 8v6M8 11h6"/>'),
        zoomOut: icon('<circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5M8 11h6"/>'),
        fit: icon('<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>'),
        download: icon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>'),
        props: icon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>'),
        eye: icon('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>')
    };

    // ── Loading the libraries, once each, on first use ─────────────────────
    var loaded = {};
    function loadScript(src) {
        if (!loaded[src]) {
            loaded[src] = new Promise(function (res, rej) {
                var s = document.createElement('script');
                s.src = src;
                s.onload = res;
                s.onerror = function () { rej(new Error('Could not load ' + src)); };
                document.head.appendChild(s);
            });
        }
        return loaded[src];
    }
    var pdfjsPromise = null;
    function loadPdfjs() {
        if (!pdfjsPromise) {
            // pdf.js is an ES module; the files are renamed .js so every web server
            // serves them as JavaScript (.mjs is unknown to IIS and older nginx).
            pdfjsPromise = import(V + 'pdfjs/pdf.min.js').then(function (lib) {
                lib.GlobalWorkerOptions.workerSrc = V + 'pdfjs/pdf.worker.min.js';
                return lib;
            });
        }
        return pdfjsPromise;
    }
    function fetchBytes(url, cap) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return r.text().then(function (t) { throw new Error(t || ('HTTP ' + r.status)); });
            var len = +(r.headers.get('Content-Length') || 0);
            if (cap && len > cap) throw new Error(L('viewer.too_big', 'This file is too large to preview ({size}).', { size: fmtSize(len) }));
            return r.arrayBuffer();
        });
    }

    // ── Sanitising mammoth's HTML ──────────────────────────────────────────
    // mammoth produces simple semantic HTML, but the document is untrusted: a
    // hyperlink can be javascript:, and nothing it emits should be able to run.
    // An ALLOWLIST - anything not named here is unwrapped (its text kept).
    var ALLOWED_TAGS = { P: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, SUB: 1, SUP: 1, BR: 1,
        UL: 1, OL: 1, LI: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TD: 1, TH: 1, IMG: 1, A: 1, SPAN: 1, DIV: 1, BLOCKQUOTE: 1, PRE: 1, CODE: 1, HR: 1 };
    function sanitise(html) {
        var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
        var root = doc.body.firstChild;
        (function walk(node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (c) {
                if (c.nodeType === 3) return;
                if (c.nodeType !== 1) { c.remove(); return; }
                // TRAP: elements inside <svg>/<math> report LOWER-case tag names, so
                // every name check here is case-insensitive.
                if (!ALLOWED_TAGS[c.tagName] || c.namespaceURI !== 'http://www.w3.org/1999/xhtml') {
                    if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|LINK|META|SVG|MATH|FORM|INPUT|BUTTON|TEXTAREA|SELECT|TEMPLATE|NOSCRIPT)$/i.test(c.tagName)) { c.remove(); return; }
                    walk(c);
                    while (c.firstChild) c.parentNode.insertBefore(c.firstChild, c);
                    c.remove();
                    return;
                }
                Array.prototype.slice.call(c.attributes).forEach(function (a) {
                    var n = a.name.toLowerCase(), v = a.value.trim();
                    var keep = (c.tagName === 'A' && n === 'href' && /^(https?:|mailto:|#)/i.test(v))
                        || (c.tagName === 'A' && n === 'id')
                        || (c.tagName === 'IMG' && n === 'src' && /^data:image\/(png|jpe?g|gif|webp|bmp);base64,/i.test(v))
                        || (c.tagName === 'IMG' && n === 'alt')
                        || ((c.tagName === 'TD' || c.tagName === 'TH') && (n === 'colspan' || n === 'rowspan') && /^\d{1,3}$/.test(v));
                    if (!keep) c.removeAttribute(a.name);
                });
                if (c.tagName === 'A' && /^https?:|^mailto:/i.test(c.getAttribute('href') || '')) { c.setAttribute('target', '_blank'); c.setAttribute('rel', 'noopener noreferrer'); }
                if (c.tagName === 'IMG' && !c.getAttribute('src')) { c.remove(); return; }
                walk(c);
            });
        })(root);
        return root.innerHTML;
    }

    var MAX_ZIP_BYTES = 250 * 1024 * 1024;   // unpacked in the browser's memory
    var KINDS = {
        pdf: ['pdf'], docx: ['docx'], sheet: ['xlsx', 'xlsm', 'xls', 'ods', 'csv'],
        image: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], video: ['mp4', 'm4v', 'webm', 'mov', 'ogv'],
        audio: ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'flac'], zip: ['zip'],
        text: ['txt', 'log', 'md', 'ini', 'cfg', 'conf', 'json', 'xml', 'yml', 'yaml', 'ps1', 'sh', 'bat', 'cmd', 'sql',
               'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'cs', 'java', 'c', 'cpp', 'h', 'go', 'rb', 'csr', 'pem', 'crt']
    };
    /** Media types for blob: URLs of zip entries. SVG is deliberately absent - it can carry script. */
    var MEDIA_TYPES = {
        png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', bmp: 'image/bmp',
        mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime', ogv: 'video/ogg',
        mp3: 'audio/mpeg', m4a: 'audio/mp4', aac: 'audio/aac', wav: 'audio/wav', ogg: 'audio/ogg', flac: 'audio/flac'
    };
    function extOf(name) { var m = /\.([A-Za-z0-9]{1,8})$/.exec(name || ''); return m ? m[1].toLowerCase() : ''; }
    function kindOf(name) {
        var e = extOf(name);
        for (var k in KINDS) if (KINDS[k].indexOf(e) >= 0) return k;
        return 'none';
    }
    /** Opening or saving something INSIDE a zip happens in the browser - so tell the server, for the audit trail. */
    function auditEntry(entry, what) {
        api({ action: 'entry', id: entry.id, version: entry.version_id, entry: entry.entryPath, what: what }).catch(function () {});
    }

    // ── Spreadsheet column letters ─────────────────────────────────────────
    function colName(i) { var s = ''; i++; while (i > 0) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; }

    /**
     * Draw a file into `host` (a window body or the phone overlay).
     * info = view.php's open response. Returns a controller {destroy()}.
     */
    function renderInto(host, info, opts) {
        opts = opts || {};
        var url = info.bytes ? null : B.api + info.url;
        var viewOnly = !info.can_download;
        var blobUrls = [];
        /** The file's bytes - from memory for a zip entry, else from view.php. */
        function getBytes(cap) {
            if (info.bytes) {
                if (cap && info.bytes.byteLength > cap) return Promise.reject(new Error(L('viewer.too_big', 'This file is too large to preview ({size}).', { size: fmtSize(info.bytes.byteLength) })));
                return Promise.resolve(info.bytes);
            }
            return fetchBytes(url, cap);
        }
        /** A URL an <img>/<video> can use: view.php's, or a blob: for a zip entry (typed by us, never SVG). */
        function mediaUrl() {
            if (!info.bytes) return url;
            var u = URL.createObjectURL(new Blob([info.bytes], { type: MEDIA_TYPES[extOf(info.name)] || 'application/octet-stream' }));
            blobUrls.push(u);
            return u;
        }
        host.classList.add('fdv');
        if (viewOnly) host.classList.add('fdv-viewonly');
        host.innerHTML =
            '<div class="fdv-bar">' +
                (info.is_current ? '' : '<span class="fdv-badge">' + esc(L('viewer.version', 'Version {n}', { n: info.version_no })) + '</span>') +
                (viewOnly ? '<span class="fdv-badge fdv-badge-vo" title="' + esc(L('viewer.view_only_tip', 'You can view this file but not download it.')) + '">' + IC.eye + esc(L('viewer.view_only', 'View only')) + '</span>' : '') +
                '<span class="fdv-grow"></span>' +
                '<span class="fdv-tools"></span>' +
                (info.can_download ? '<button type="button" class="fdv-btn" data-act="download">' + IC.download + '<span>' + esc(L('ex.download', 'Download')) + '</span></button>' : '') +
                (opts.onProperties ? '<button type="button" class="fdv-btn" data-act="props" title="' + esc(L('ex.properties', 'Properties')) + '">' + IC.props + '</button>' : '') +
            '</div>' +
            '<div class="fdv-stagewrap"><div class="fdv-stage" tabindex="0"><div class="fdv-loading">' + esc(L('loading', 'Loading...')) + '</div></div>' +
                (info.watermark ? '<div class="fdv-wm" aria-hidden="true"></div>' : '') +
            '</div>' +
            '<div class="fdv-foot" hidden></div>';
        // No file name in this bar: the window title (desktop), the phone viewer's own
        // bar, and a zip entry's Back bar already say what is open (Ed, 2026-10-10).
        var stage = host.querySelector('.fdv-stage');
        var tools = host.querySelector('.fdv-tools');
        var foot = host.querySelector('.fdv-foot');

        if (info.watermark) {
            // A tiled diagonal stamp. Built as an SVG image so it covers any size,
            // and from text the SERVER chose (name, time, IP) - not the page's.
            var t = info.watermark.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="560" height="260"><text x="280" y="135" text-anchor="middle" transform="rotate(-22 280 130)" ' +
                'font-family="Segoe UI, Arial, sans-serif" font-size="15" font-weight="600" fill="rgba(120,120,120,0.28)">' + t + '</text></svg>';
            host.querySelector('.fdv-wm').style.backgroundImage = 'url("data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg) + '")';
        }

        host.querySelector('.fdv-bar').addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-act]');
            if (!b) return;
            if (b.dataset.act === 'download') {
                var a = document.createElement('a');
                if (info.bytes) {
                    // A zip entry: saved from memory, and the server told which one.
                    var bu = URL.createObjectURL(new Blob([info.bytes], { type: 'application/octet-stream' }));
                    a.href = bu;
                    a.download = info.name;
                    setTimeout(function () { URL.revokeObjectURL(bu); }, 10000);
                    auditEntry(info, 'download');
                } else {
                    a.href = B.api + 'download.php?id=' + info.id + '&version=' + info.version_id;
                }
                document.body.appendChild(a); a.click(); a.remove();
            }
            if (b.dataset.act === 'props' && opts.onProperties) opts.onProperties();
        });
        if (viewOnly) stage.addEventListener('contextmenu', function (ev) { ev.preventDefault(); });

        var ctl = { destroy: function () {} };
        var baseDestroy = function () { blobUrls.forEach(function (u) { URL.revokeObjectURL(u); }); };
        function error(e) {
            stage.className = 'fdv-stage';   // drop the kind's stage (a black video stage made the message invisible)
            stage.innerHTML = '<div class="fdv-msg">' + esc(e && e.message ? e.message : String(e)) + '</div>';
        }
        function noPreview() {
            stage.innerHTML = '<div class="fdv-none">' + FI.file(info.name, 96) + '<div class="fdv-none-name"></div><div class="fdv-muted">' +
                esc(fmtSize(info.size)) + '</div><p>' + esc(L('viewer.no_preview', 'There is no preview for this kind of file.')) + '</p>' +
                (info.can_download ? '<button type="button" class="fdv-btn fdv-btn-primary" data-act="download">' + IC.download + '<span>' + esc(L('ex.download', 'Download')) + '</span></button>' : '') + '</div>';
            stage.querySelector('.fdv-none-name').textContent = info.name;
            var b = stage.querySelector('[data-act="download"]');
            if (b) b.addEventListener('click', function () { host.querySelector('.fdv-bar [data-act="download"]').click(); });
        }
        function zoomTools(onZoom) {
            tools.innerHTML = '<button type="button" class="fdv-btn" data-z="out" title="' + esc(L('viewer.zoom_out', 'Zoom out')) + '">' + IC.zoomOut + '</button>' +
                '<span class="fdv-zoom">100%</span>' +
                '<button type="button" class="fdv-btn" data-z="in" title="' + esc(L('viewer.zoom_in', 'Zoom in')) + '">' + IC.zoomIn + '</button>' +
                '<button type="button" class="fdv-btn" data-z="fit" title="' + esc(L('viewer.fit', 'Fit to width')) + '">' + IC.fit + '</button>';
            tools.addEventListener('click', function (ev) { var b = ev.target.closest('[data-z]'); if (b) onZoom(b.dataset.z); });
            return function (pct) { tools.querySelector('.fdv-zoom').textContent = Math.round(pct) + '%'; };
        }

        switch (info.kind) {
            case 'pdf': renderPdf(); break;
            case 'docx': renderDocx(); break;
            case 'sheet': renderSheet(); break;
            case 'image': renderImage(); break;
            case 'video': case 'audio': renderMedia(); break;
            case 'text': renderText(); break;
            case 'zip': renderZip(); break;
            default: noPreview();
        }
        var innerDestroy = ctl.destroy;
        return { destroy: function () { try { (ctl.destroy || innerDestroy)(); } catch (e) { /* gone */ } baseDestroy(); } };

        // ── PDF ────────────────────────────────────────────────────────────
        function renderPdf() {
            var pdf = null, scale = 1, fitScale = 1, base = null, pages = [], observer = null, destroyed = false;
            var showZoom = zoomTools(function (z) {
                if (z === 'fit') scale = fitScale; else scale = Math.max(0.25, Math.min(5, scale * (z === 'in' ? 1.2 : 1 / 1.2)));
                layout();
            });
            var pageLabel = h('span', 'fdv-page');
            tools.insertBefore(pageLabel, tools.firstChild);
            loadPdfjs().then(function (lib) {
                var task = lib.getDocument({
                    url: url || undefined,
                    data: info.bytes ? new Uint8Array(info.bytes.slice(0)) : undefined,
                    cMapUrl: V + 'pdfjs/cmaps/', cMapPacked: true,
                    standardFontDataUrl: V + 'pdfjs/standard_fonts/',
                    wasmUrl: V + 'pdfjs/wasm/', iccUrl: V + 'pdfjs/iccs/',
                    enableXfa: false, enableScripting: false
                });
                ctl.destroy = function () { destroyed = true; if (observer) observer.disconnect(); task.destroy(); };
                return task.promise;
            }).then(function (doc) {
                pdf = doc;
                return pdf.getPage(1);
            }).then(function (p1) {
                if (destroyed) return;
                base = p1.getViewport({ scale: 1 });
                fitScale = Math.max(0.25, Math.min(3, (stage.clientWidth - 48) / base.width));
                scale = fitScale;
                stage.innerHTML = '';
                stage.classList.add('fdv-pdf');
                for (var i = 1; i <= pdf.numPages; i++) {
                    var d = h('div', 'fdv-page-box');
                    d.dataset.page = i;
                    stage.appendChild(d);
                    pages.push({ el: d, n: i, rendered: 0, task: null });
                }
                observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) { if (en.isIntersecting) draw(pages[+en.target.dataset.page - 1]); });
                }, { root: stage, rootMargin: '600px 0px' });
                pages.forEach(function (pg) { observer.observe(pg.el); });
                layout();
                stage.addEventListener('scroll', updatePage);
                updatePage();
            }).catch(error);

            function layout() {
                if (!base) return;
                showZoom(scale * 100);
                pages.forEach(function (pg) {
                    pg.el.style.width = Math.floor(base.width * scale) + 'px';
                    pg.el.style.height = Math.floor(base.height * scale) + 'px';
                    if (pg.rendered && pg.rendered !== scale) { pg.rendered = 0; }
                });
                // Redraw what is on screen at the new size.
                pages.forEach(function (pg) {
                    var r = pg.el.getBoundingClientRect(), s = stage.getBoundingClientRect();
                    if (r.bottom > s.top - 600 && r.top < s.bottom + 600) draw(pg);
                });
            }
            function draw(pg) {
                if (!pdf || pg.rendered === scale) return;
                var want = scale;
                pg.rendered = want;
                pdf.getPage(pg.n).then(function (page) {
                    if (pg.rendered !== want || destroyed) return;
                    var vp = page.getViewport({ scale: want });
                    var dpr = window.devicePixelRatio || 1;
                    var c = document.createElement('canvas');
                    c.width = Math.floor(vp.width * dpr);
                    c.height = Math.floor(vp.height * dpr);
                    c.style.width = Math.floor(vp.width) + 'px';
                    c.style.height = Math.floor(vp.height) + 'px';
                    // Pages can differ in size - the box follows the real page.
                    pg.el.style.width = c.style.width;
                    pg.el.style.height = c.style.height;
                    if (pg.task) try { pg.task.cancel(); } catch (e) { /* done */ }
                    pg.task = page.render({ canvas: c, viewport: vp, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null });
                    return pg.task.promise.then(function () {
                        if (pg.rendered !== want) return;
                        pg.el.innerHTML = '';
                        pg.el.appendChild(c);
                    });
                }).catch(function (e) { if (e && e.name !== 'RenderingCancelledException') pg.rendered = 0; });
            }
            function updatePage() {
                if (!pdf) return;
                var s = stage.getBoundingClientRect(), mid = s.top + s.height / 3, cur = 1;
                for (var i = 0; i < pages.length; i++) { if (pages[i].el.getBoundingClientRect().top <= mid) cur = i + 1; else break; }
                pageLabel.textContent = L('viewer.page_of', '{n} of {total}', { n: cur, total: pdf.numPages });
            }
        }

        // ── Word ───────────────────────────────────────────────────────────
        function renderDocx() {
            Promise.all([loadScript(V + 'mammoth.browser.min.js'), getBytes(MAX_PARSE_BYTES)]).then(function (r) {
                return window.mammoth.convertToHtml({ arrayBuffer: r[1] });
            }).then(function (res) {
                stage.innerHTML = '';
                stage.classList.add('fdv-paperstage');
                var paper = h('div', 'fdv-paper');
                paper.innerHTML = sanitise(res.value) || '<p class="fdv-muted">' + esc(L('viewer.empty_doc', 'This document has no text.')) + '</p>';
                stage.appendChild(paper);
                var zoom = 1, showZoom = zoomTools(function (z) {
                    zoom = z === 'fit' ? 1 : Math.max(0.5, Math.min(3, zoom * (z === 'in' ? 1.15 : 1 / 1.15)));
                    paper.style.zoom = zoom;
                    showZoom(zoom * 100);
                });
                showZoom(100);
                foot.hidden = false;
                foot.textContent = L('viewer.docx_note', 'A simplified view of the document - layout, fonts and page breaks may differ from Word.');
            }).catch(error);
        }

        // ── Spreadsheets ───────────────────────────────────────────────────
        function renderSheet() {
            var MAX_ROWS = 5000, MAX_COLS = 200;
            Promise.all([loadScript(V + 'xlsx.full.min.js'), getBytes(MAX_PARSE_BYTES)]).then(function (r) {
                var wb = window.XLSX.read(new Uint8Array(r[1]), { type: 'array', cellDates: true, sheetRows: MAX_ROWS + 1 });
                stage.innerHTML = '';
                stage.classList.add('fdv-sheetstage');
                var tabs = h('div', 'fdv-sheettabs');
                function show(name) {
                    var ws = wb.Sheets[name];
                    var rows = window.XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '', blankrows: true });
                    var cols = 0;
                    rows.forEach(function (r) { if (r.length > cols) cols = r.length; });
                    cols = Math.min(cols, MAX_COLS);
                    var html = '<table class="fdv-grid"><thead><tr><th class="fdv-corner"></th>';
                    for (var c = 0; c < cols; c++) html += '<th>' + colName(c) + '</th>';
                    html += '</tr></thead><tbody>';
                    rows.slice(0, MAX_ROWS).forEach(function (r, i) {
                        html += '<tr><th>' + (i + 1) + '</th>';
                        for (var c = 0; c < cols; c++) {
                            var cv = r[c] == null ? '' : String(r[c]);
                            // Numbers sit on the right, as they do in Excel.
                            html += (/^[-+]?[\d,]*\.?\d+%?$/.test(cv.trim()) ? '<td class="fdv-num">' : '<td>') + esc(cv) + '</td>';
                        }
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    stage.innerHTML = html;
                    stage.scrollTop = 0;
                    var cut = rows.length > MAX_ROWS || cols >= MAX_COLS;
                    foot.hidden = !cut;
                    if (cut) foot.textContent = L('viewer.sheet_cut', 'Showing the first {rows} rows and {cols} columns. Download the file to see all of it.', { rows: MAX_ROWS, cols: MAX_COLS });
                    tabs.querySelectorAll('button').forEach(function (b) { b.classList.toggle('fdv-on', b.dataset.sheet === name); });
                }
                wb.SheetNames.forEach(function (n) {
                    var b = h('button', 'fdv-sheettab');
                    b.type = 'button';
                    b.dataset.sheet = n;
                    b.textContent = n;
                    b.addEventListener('click', function () { show(n); });
                    tabs.appendChild(b);
                });
                host.appendChild(tabs);
                show(wb.SheetNames[0]);
            }).catch(error);
        }

        // ── Images ─────────────────────────────────────────────────────────
        function renderImage() {
            var img = new Image(), zoom = 0;
            img.className = 'fdv-img';
            img.alt = info.name;
            img.draggable = false;
            var showZoom = zoomTools(function (z) {
                var fit = fitZoom();
                zoom = z === 'fit' ? fit : Math.max(0.05, Math.min(8, (zoom || fit) * (z === 'in' ? 1.25 : 0.8)));
                apply();
            });
            function fitZoom() { return Math.min(1, (stage.clientWidth - 32) / img.naturalWidth, (stage.clientHeight - 32) / img.naturalHeight); }
            function apply() { img.style.width = Math.round(img.naturalWidth * zoom) + 'px'; showZoom(zoom * 100); }
            img.onload = function () { stage.innerHTML = ''; stage.classList.add('fdv-imgstage'); stage.appendChild(img); zoom = fitZoom(); apply(); };
            img.onerror = function () { error(new Error(L('viewer.load_failed', 'The file could not be shown.'))); };
            img.src = mediaUrl();
        }

        // ── Video and audio ────────────────────────────────────────────────
        function renderMedia() {
            var m = document.createElement(info.kind === 'video' ? 'video' : 'audio');
            m.controls = true;
            m.preload = 'metadata';
            m.className = 'fdv-media';
            if (viewOnly) {
                m.setAttribute('controlsList', 'nodownload noplaybackrate');
                m.disablePictureInPicture = true;
            }
            m.onerror = function () { error(new Error(L('viewer.media_failed', 'This browser cannot play this file.'))); };
            m.src = mediaUrl();
            stage.innerHTML = '';
            stage.classList.add('fdv-mediastage');
            stage.appendChild(m);
            ctl.destroy = function () { try { m.pause(); m.removeAttribute('src'); m.load(); } catch (e) { /* gone */ } };
        }

        // ── Zip archives ───────────────────────────────────────────────────
        // A folder view of the archive; opening an entry swaps the stage for
        // that entry's own viewer, with a way back to the list. Nested zips
        // just work - an entry that is a zip is browsed the same way.
        function renderZip() {
            Promise.all([loadScript(V + 'xlsx.full.min.js'), getBytes(MAX_ZIP_BYTES)]).then(function (r) {
                var cfb;
                try { cfb = window.XLSX.CFB.read(new Uint8Array(r[1]), { type: 'array' }); }
                catch (e) { throw new Error(L('viewer.zip_bad', 'This archive could not be opened. It may be damaged or password protected.')); }
                // FullPaths begin with the archive's root ("Root Entry/" or similar) - drop it.
                var files = [];
                cfb.FileIndex.forEach(function (fi, i) {
                    if (fi.type !== 2) return;
                    var p = cfb.FullPaths[i].split('/').slice(1).filter(Boolean);
                    // TRAP: SheetJS's reader adds a marker entry "\u0001Sh33tJ5"; no real
                    // name starts with a control character, so anything that does is skipped.
                    if (!p.length || p.some(function (s) { return s === '..' || /[\x00-\x1f]/.test(s); })) return;
                    files.push({ parts: p, size: fi.size || (fi.content ? fi.content.length : 0), content: fi.content });
                });
                var path = [];
                stage.classList.add('fdv-zipstage');
                var list = h('div', 'fdv-zip');
                var entryHost = h('div', 'fdv-zip-entry');
                entryHost.hidden = true;
                stage.innerHTML = '';
                stage.appendChild(list);
                host.querySelector('.fdv-stagewrap').appendChild(entryHost);
                var sub = null;

                function showList() {
                    var here = {}, rows = [];
                    files.forEach(function (f) {
                        for (var i = 0; i < path.length; i++) if (f.parts[i] !== path[i]) return;
                        if (f.parts.length === path.length + 1) rows.push({ type: 'file', name: f.parts[path.length], f: f });
                        else if (f.parts.length > path.length + 1) here[f.parts[path.length]] = true;
                    });
                    rows = Object.keys(here).sort().map(function (n) { return { type: 'dir', name: n }; })
                        .concat(rows.sort(function (a, b) { return a.name.localeCompare(b.name, undefined, { numeric: true }); }));
                    var crumbs = '<button type="button" class="fdv-zcrumb" data-up="0">' + esc(info.name) + '</button>' +
                        path.map(function (p, i) { return ' / <button type="button" class="fdv-zcrumb" data-up="' + (i + 1) + '">' + esc(p) + '</button>'; }).join('');
                    list.innerHTML = '<div class="fdv-zcrumbs">' + crumbs + '</div>' +
                        '<div class="fdv-zhint fdv-muted">' + esc(L('viewer.zip_count', '{n} files in this archive', { n: files.length })) + '</div>' +
                        (rows.length ? '' : '<div class="fdv-msg">' + esc(L('viewer.zip_empty', 'This archive is empty.')) + '</div>') +
                        rows.map(function (r, i) {
                            return '<button type="button" class="fdv-zrow" data-i="' + i + '">' + (r.type === 'dir' ? FI.folder(20) : FI.file(r.name, 20)) +
                                '<span class="fdv-zname">' + esc(r.name) + '</span><span class="fdv-muted">' + (r.type === 'dir' ? '' : esc(fmtSize(r.f.size))) + '</span></button>';
                        }).join('');
                    list.querySelectorAll('[data-up]').forEach(function (b) { b.addEventListener('click', function () { path = path.slice(0, +b.dataset.up); showList(); }); });
                    list.querySelectorAll('.fdv-zrow').forEach(function (b) {
                        b.addEventListener('click', function () {
                            var r = rows[+b.dataset.i];
                            if (r.type === 'dir') { path.push(r.name); showList(); return; }
                            openEntry(r.f);
                        });
                    });
                }

                function openEntry(f) {
                    var name = f.parts[f.parts.length - 1];
                    var c = f.content;
                    var bytes = c.buffer ? c.buffer.slice(c.byteOffset, c.byteOffset + c.byteLength) : new Uint8Array(c).buffer;
                    var child = {
                        id: info.id, version_id: info.version_id, name: name, kind: kindOf(name), size: bytes.byteLength,
                        bytes: bytes, entryPath: (info.entryPath ? info.entryPath + ' > ' : '') + f.parts.join('/'),
                        can_download: info.can_download, watermark: info.watermark, is_current: true, version_no: info.version_no
                    };
                    auditEntry(child, 'view');
                    stage.hidden = true;
                    tools.hidden = true;
                    host.classList.add('fdv-in-entry');   // the archive's own Download steps aside for the entry's
                    entryHost.hidden = false;
                    entryHost.innerHTML = '<div class="fdv-zback"><button type="button" class="fdv-btn">&lsaquo; ' + esc(L('viewer.zip_back', 'Back to {name}', { name: info.name })) + '</button>' +
                        '<span class="fdv-muted"></span></div><div class="fdv-zentry"></div>';
                    entryHost.querySelector('.fdv-zback .fdv-muted').textContent = child.entryPath;
                    sub = renderInto(entryHost.querySelector('.fdv-zentry'), child, {});
                    entryHost.querySelector('.fdv-zback .fdv-btn').addEventListener('click', function () {
                        if (sub) sub.destroy();
                        sub = null;
                        entryHost.hidden = true;
                        entryHost.innerHTML = '';
                        stage.hidden = false;
                        tools.hidden = false;
                        host.classList.remove('fdv-in-entry');
                    });
                }
                ctl.destroy = function () { if (sub) sub.destroy(); };
                showList();
            }).catch(error);
        }

        // ── Text ───────────────────────────────────────────────────────────
        function renderText() {
            getBytes(MAX_TEXT_BYTES).then(function (buf) {
                var pre = h('pre', 'fdv-text');
                pre.textContent = new TextDecoder('utf-8').decode(buf);
                stage.innerHTML = '';
                stage.appendChild(pre);
            }).catch(error);
        }
    }

    /** Open a file in a viewer window. versionId optional (Properties -> Versions). */
    function open(id, versionId, opts) {
        opts = opts || {};
        return api({ action: 'open', id: id, version: versionId || 0 }).then(function (info) {
            info.id = id;
            if (opts.phone) return openPhone(info, opts);
            var w = WM.open({
                kind: 'viewer', key: 'v' + info.version_id, single: true,
                title: info.name + (info.is_current ? '' : ' - ' + L('viewer.version', 'Version {n}', { n: info.version_no })),
                icon: FI.file(info.name, 16), width: 920, height: 680, geometry: opts.geometry || null,
                onClose: function () { if (w.viewer) w.viewer.destroy(); }
            });
            if (w.viewer) return w;   // already open: just focused
            w.viewer = renderInto(w.body, info, { onProperties: opts.onProperties ? function () { opts.onProperties(id); } : null });
            return w;
        });
    }

    /** Phones: the same viewer, full screen over the list. */
    function openPhone(info) {
        var ov = h('div', 'fdm-viewer');
        ov.innerHTML = '<div class="fdm-viewer-bar"><button type="button" class="fdm-back">' +
            icon('<path d="M19 12H5M12 19l-7-7 7-7"/>') + '</button><span class="fdm-title"></span></div><div class="fdm-viewer-body"></div>';
        ov.querySelector('.fdm-title').textContent = info.name;
        document.body.appendChild(ov);
        var v = renderInto(ov.querySelector('.fdm-viewer-body'), info, {});
        ov.querySelector('.fdm-back').addEventListener('click', function () { v.destroy(); ov.remove(); });
        return ov;
    }

    window.FilesViewer = { open: open, sanitise: sanitise };
})();
