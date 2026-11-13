/**
 * Documents panel — attach files or DMS links to any record (discussion #76).
 *
 * ONE component for every module. Mount it and give it a parent:
 *
 *     FreeITSMDocuments.mount(element, {
 *         parentType: 'contract',
 *         parentId:   42,
 *         apiBase:    '../api/documents/',
 *         canEdit:    true
 *     });
 *
 * The component knows nothing about any module, and no module knows anything
 * about how documents are stored or authorised. The server decides both — see
 * includes/documents.php.
 *
 * ⚠️ window.t IS GUARDED HERE. The notification bell called it bare and broke on
 * the one page that did not load i18n.js, and because the throw happened above
 * the try, it never even made its request (GH #78). Same pattern as calendar.js
 * and war-room.js: fall back to English rather than take the page down.
 *
 * @wiki Attached-Documents-Developer-Guide
 */
(function () {
    'use strict';

    if (window.FreeITSMDocuments) return;   // self-guard against double loading

    var FALLBACK = {
        heading:      'Documents',
        count_one:    '1 document',
        count_many:   '{n} documents',
        none:         'No documents attached yet.',
        drop:         'Drop a file here, or click to choose one',
        drop_or:      'or paste a link to it in your document system below',
        link_url:     'https://link-to-your-document',
        link_title:   'What is it? (optional)',
        add_link:     'Add link',
        open:         'Open',
        download:     'Download',
        remove:       'Remove',
        remove_confirm: 'Remove "{name}" from this record?',
        removed_last: 'That was the last place it was attached, so the document has been deleted.',
        also_on:      'Also on {label}',
        uploading:    'Uploading…',
        show_more:    'Show more',
        failed:       'Something went wrong.',
        by:           'by {name}',
        loading:      'Loading…',
        close:        'Close',
        info_title:   'Document details',
        attached_to:  'Attached to',
        attached_none:'Not attached to anything you can see.',
        attached_hidden: 'And {n} other record(s) you do not have access to.',
        kind_link:    'A link to an external document',
        idx_ok:       'Searchable — {n} characters of text indexed.',
        idx_pending:  'Not searchable yet — the text is still being read.',
        idx_unsupported: 'Its contents cannot be read, so only its name and description are searchable.',
        idx_failed:   'Its contents could not be read.',
        find_existing:'Or attach a document already in FreeITSM — start typing its name',
        find_none:    'No documents match, that you can see and that are not already here.',
        // GH #303: files from Files
        from_files:   'In Files',
        attach_link:  'Link',
        attach_copy:  'Copy',
        attach_link_title: 'Attach a link - opened through Files, under its folder permissions',
        attach_copy_title: 'Attach a copy of the current version - readable by everyone who can see this',
        kind_files:   'In Files',
        files_no_access: 'A file in Files you do not have access to',
        files_gone:   'A file that has been deleted from Files',
        save_to_files: 'Save to Files',
        from_files:   'From Files',
        currently_on: 'currently on {where}',
        // The Help Centre - knowledge articles only (GH #177).
        hc_customer:  'Customers who can read this article in the Help Centre can download the documents marked Shown.',
        hc_internal:  'Customers cannot read this article, so none of its documents are in the Help Centre.',
        hc_verify:    'Run System → Database Verification to choose which documents customers can download.',
        hc_how:       'How this works',
        hc_shown:     'Shown in Help Centre',
        hc_hidden:    'Hidden from Help Centre',
        hc_show:      'Show',
        hc_hide:      'Hide'
    };

    /**
     * Translates a key under common.documents, falling back to the English in FALLBACK with {name} placeholders filled.
     */
    function t(key, params) {
        var full = 'common.documents.' + key;
        if (typeof window.t === 'function') {
            var got = window.t(full, params);
            if (got && got !== full) return got;
        }
        var s = FALLBACK[key] || key;
        if (params) {
            Object.keys(params).forEach(function (k) {
                s = s.replace('{' + k + '}', params[k]);
            });
        }
        return s;
    }

    /** HTML-escapes a value for building the panel's markup. */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Formats a byte count as B, KB or MB. */
    function bytes(n) {
        if (n == null) return '';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    /** Only ever produce an href we built, from a scheme we checked. */
    function safeHref(url) {
        return /^https?:\/\//i.test(String(url || '')) ? String(url) : '#';
    }

    /** The inline SVG icon for a document row - a chain link for a DMS link, a page for an uploaded file. */
    function icon(kind) {
        if (kind === 'files') return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>';
        return kind === 'link'
            ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>'
            : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
    }

    /**
     * One Documents panel mounted on a record: reads the options, draws the panel and loads the first page of documents.
     *
     * Created by FreeITSMDocuments.mount().
     */
    function Panel(el, opts) {
        this.el         = el;
        this.parentType = opts.parentType;
        this.parentId   = parseInt(opts.parentId, 10);
        this.api        = opts.apiBase || '../api/documents/';
        this.canEdit    = opts.canEdit !== false;
        // Most hosts already name the section — a tab called Documents, or a form
        // heading — so repeating it inside the panel just says the word twice.
        // The COUNT still shows, because that is the part the host does not know.
        this.showHeading = opts.showHeading === true;
        this.pageSize   = opts.pageSize || 25;
        // Where "How this works" points, on a knowledge article (GH #177). The
        // server decides whether the Help Centre applies; this is only the link.
        this.helpCentreHelp = opts.helpCentreHelpUrl || '';
        this.helpCentre = null;
        this.offset     = 0;
        this.items      = [];
        this.total      = 0;
        this.render();
        this.load(true);
    }

    Panel.prototype.render = function () {
        this.el.classList.add('fd-panel');
        this.el.innerHTML =
            '<div class="fd-head">' +
                (this.showHeading ? '<h4>' + esc(t('heading')) + '</h4>' : '') +
                '<span class="fd-count"></span>' +
                '<span class="fd-spacer"></span>' +
            '</div>' +
            (this.canEdit
                ? '<label class="fd-drop">' +
                      esc(t('drop')) +
                      '<span class="fd-drop-or">' + esc(t('drop_or')) + '</span>' +
                      '<input type="file" multiple>' +
                  '</label>' +
                  '<div class="fd-linkrow">' +
                      '<input type="url" class="fd-url" placeholder="' + esc(t('link_url')) + '">' +
                      '<input type="text" class="fd-linktitle" placeholder="' + esc(t('link_title')) + '">' +
                      '<button type="button" class="fd-btn fd-addlink">' + esc(t('add_link')) + '</button>' +
                  '</div>' +
                  // Attach one that already exists — the other half of the join
                  // table. Without this, one warranty on eleven laptops means
                  // eleven uploads of the same file.
                  '<div class="fd-existing">' +
                      // GH #303: From Files - a file-dialog window (files-picker.js); shown once the
                      // list says this person can open Files.
                      '<button type="button" class="fd-btn fd-fromfiles" hidden>' + esc(t('from_files')) + '</button>' +
                      '<input type="text" class="fd-find" placeholder="' + esc(t('find_existing')) + '">' +
                      '<div class="fd-findresults" hidden></div>' +
                  '</div>'
                : '') +
            '<div class="fd-error" hidden></div>' +
            '<div class="fd-hc" hidden></div>' +
            '<div class="fd-list"></div>' +
            '<div class="fd-more" hidden><button type="button" class="fd-btn fd-moreBtn">' + esc(t('show_more')) + '</button></div>';

        this.$count = this.el.querySelector('.fd-count');
        this.$list  = this.el.querySelector('.fd-list');
        this.$err   = this.el.querySelector('.fd-error');
        this.$more  = this.el.querySelector('.fd-more');
        this.$hc    = this.el.querySelector('.fd-hc');

        var self = this;
        if (this.canEdit) {
            var drop  = this.el.querySelector('.fd-drop');
            var input = drop.querySelector('input[type=file]');
            input.addEventListener('change', function () {
                self.upload(Array.prototype.slice.call(input.files));
                input.value = '';
            });
            ['dragenter', 'dragover'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('fd-over'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('fd-over'); });
            });
            drop.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files) {
                    self.upload(Array.prototype.slice.call(e.dataTransfer.files));
                }
            });
            this.el.querySelector('.fd-addlink').addEventListener('click', function () { self.addLink(); });

            var fromFiles = this.el.querySelector('.fd-fromfiles');
            if (fromFiles) fromFiles.addEventListener('click', function () {
                window.FilesPicker.open({
                    mode: self.attachMode || 'link', parentType: self.parentType, parentId: self.parentId,
                    onPick: function (itemId, how) { self.attachFromFiles(itemId, how); }
                });
            });
            var find = this.el.querySelector('.fd-find');
            var findTimer = null;
            find.addEventListener('input', function () {
                clearTimeout(findTimer);
                findTimer = setTimeout(function () { self.findExisting(find.value.trim()); }, 250);
            });
            this.el.querySelector('.fd-findresults').addEventListener('click', function (e) {
                var fromFiles = e.target.closest('[data-files]');
                if (fromFiles) { self.attachFromFiles(parseInt(fromFiles.getAttribute('data-files'), 10), fromFiles.getAttribute('data-how')); return; }
                var pick = e.target.closest('[data-attach]');
                if (pick) self.attachExisting(parseInt(pick.getAttribute('data-attach'), 10));
            });
        }
        this.el.querySelector('.fd-moreBtn').addEventListener('click', function () { self.load(false); });

        // One delegated handler rather than a listener per row, so re-rendering
        // the list never leaves stale handlers behind.
        this.$list.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-remove]');
            if (rm) { self.remove(parseInt(rm.getAttribute('data-remove'), 10), rm.getAttribute('data-name')); return; }
            // GH #303: open in the page - a Files link through view.php (permissions, View
            // only, watermark), an uploaded file the viewer can show from download.php.
            // Ctrl / middle-click still follow the link to a new tab.
            var vw = e.target.closest('[data-open-files], [data-open-doc]');
            if (vw && window.FilesViewer && !(e.ctrlKey || e.metaKey || e.shiftKey || e.button)) {
                if (vw.hasAttribute('data-open-files')) {
                    e.preventDefault();
                    var href = vw.getAttribute('href');
                    window.FilesViewer.openItem(parseInt(vw.getAttribute('data-open-files'), 10)).catch(function () { window.open(href, '_blank'); });
                    return;
                }
                var w = window.FilesViewer.openUrl({ name: vw.getAttribute('data-name'), src: vw.getAttribute('href'), downloadUrl: vw.getAttribute('href'), size: parseInt(vw.getAttribute('data-size'), 10) || 0, key: 'doc' + vw.getAttribute('data-open-doc') });
                if (w) { e.preventDefault(); return; }
            }
            var stf = e.target.closest('[data-stf]');
            if (stf) { window.SaveToFiles.open({ source: 'document', id: parseInt(stf.getAttribute('data-stf'), 10), name: stf.getAttribute('data-name'), ticket: self.parentType === 'ticket' }); return; }
            var hc = e.target.closest('[data-hc]');
            if (hc) { self.setHelpCentre(parseInt(hc.getAttribute('data-hc'), 10), hc.getAttribute('data-show') === '1'); return; }
            var nfo = e.target.closest('[data-info]');
            if (nfo) {
                // The panel sits at a module path, the parent links are relative
                // to the install root — so strip one segment off the api base.
                showInfo(self.api, parseInt(nfo.getAttribute('data-info'), 10),
                         self.api.replace(/api\/documents\/$/, ''));
            }
        });
    };

    Panel.prototype.fail = function (msg) {
        this.$err.hidden = false;
        this.$err.textContent = msg || t('failed');
    };
    Panel.prototype.clearFail = function () { this.$err.hidden = true; };

    /**
     * Point the panel at a different record, without remounting it.
     *
     * ⚠️ THIS EXISTS BECAUSE OF ASSETS. Contracts renders one record per page, so
     * a parent baked in at render time is fine. The asset detail panel swaps
     * between assets in JavaScript with no page load, so the same mounted panel
     * has to follow it. Building both callers before rolling this out anywhere
     * else is what surfaced that — a fixed parent would have been copied into a
     * dozen modules before the first tabbed one hit it.
     */
    Panel.prototype.setParent = function (parentType, parentId) {
        var id = parseInt(parentId, 10) || 0;
        if (parentType === this.parentType && id === this.parentId) return;
        this.parentType = parentType;
        this.parentId   = id;
        this.clearFail();
        this.load(true);
    };

    Panel.prototype.load = function (reset) {
        var self = this;
        if (reset) { this.offset = 0; this.items = []; }

        // No record selected yet. Show the empty state rather than asking the
        // server about record 0, which would only ever be a 403.
        if (!this.parentId) {
            this.total = 0; this.items = [];
            this.paint();
            return Promise.resolve();
        }
        var url = this.api + 'list.php?parent_type=' + encodeURIComponent(this.parentType)
                + '&parent_id=' + this.parentId
                + '&offset=' + this.offset + '&limit=' + this.pageSize;
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { self.fail(d.error); return; }
                self.clearFail();
                self.total  = d.total;
                self.helpCentre = d.help_centre || null;
                self.canSave = !!d.can_save_to_files && !!window.SaveToFiles;   // GH #303
                self.attachMode = d.files_attach_mode || 'link';
                var ff = self.el.querySelector('.fd-fromfiles');
                if (ff) ff.hidden = !(d.can_save_to_files && window.FilesPicker && self.canEdit);
                self.items  = self.items.concat(d.documents || []);
                self.offset = self.items.length;
                self.paint();
            })
            .catch(function () { self.fail(); });
    };

    Panel.prototype.paint = function () {
        this.$count.textContent = this.total === 1
            ? t('count_one') : t('count_many', { n: this.total });
        // Told to whoever holds the panel - the task window puts it on its
        // Documents tab, which is drawn before the list has loaded.
        try {
            this.el.dispatchEvent(new CustomEvent('fd:count', { bubbles: true, detail: { total: this.total } }));
        } catch (e) {}

        // The Help Centre line (GH #177). Present only when the server sent
        // help_centre, i.e. on a knowledge article - every other record type is
        // untouched. Also told to the host, so the article editor can warn when
        // a change of audience is about to publish these documents.
        var hc = this.helpCentre;
        if (hc && this.items.length) {
            var msg = !hc.ready ? t('hc_verify') : (hc.customer_facing ? t('hc_customer') : t('hc_internal'));
            this.$hc.innerHTML = esc(msg) +
                (this.helpCentreHelp ? ' <a href="' + esc(this.helpCentreHelp) + '" target="_blank" rel="noopener">' + esc(t('hc_how')) + '</a>' : '');
            this.$hc.hidden = false;
        } else {
            this.$hc.hidden = true;
        }
        if (hc) {
            try {
                this.el.dispatchEvent(new CustomEvent('fd:helpcentre', { bubbles: true, detail: hc }));
            } catch (e) {}
        }
        var hcRows = !!(hc && hc.ready);

        if (!this.items.length) {
            this.$list.innerHTML = '<div class="fd-empty">' + esc(t('none')) + '</div>';
            this.$more.hidden = true;
            return;
        }

        var self = this;
        this.$list.innerHTML = this.items.map(function (d) {
            var isLink = d.kind === 'link';
            // GH #303: a file in Files opens THROUGH Files (download.php sends it to files/?item=),
            // and someone who cannot open its folder sees neither a link nor its name.
            var isFiles = d.kind === 'files';
            var href   = isLink ? safeHref(d.external_url)
                                : self.api + 'download.php?id=' + d.id;
            var title  = d.title;
            if (isFiles && !d.files_access) { href = null; title = d.files_gone ? t('files_gone') : t('files_no_access'); }
            // GH #303: what lets a click open it in the page instead of leaving it
            var viewAttrs = isFiles && d.files_item_id_open ? ' data-open-files="' + d.files_item_id_open + '"'
                : (d.kind === 'file' ? ' data-open-doc="' + d.id + '" data-name="' + esc(d.original_name || d.title) + '" data-size="' + (d.size_bytes || 0) + '"' : '');
            var meta = [];
            if (isFiles) meta.push(esc(t('kind_files')));
            if (!isLink && !isFiles && d.size_bytes) meta.push(bytes(d.size_bytes));
            if (d.uploaded_by_name)      meta.push(t('by', { name: d.uploaded_by_name }));
            if (d.created_datetime)      meta.push(esc(d.created_datetime));

            var also = (d.also_on || []).map(function (a) {
                return '<span class="fd-also-tag">' +
                    esc(t('also_on', { label: a.label })) +
                    (a.name ? ': ' + esc(a.name) : '') + '</span>';
            }).join('');

            return '<div class="fd-item">' +
                '<span class="fd-icon">' + icon(d.kind) + '</span>' +
                '<div class="fd-body">' +
                    '<div class="fd-title">' + (href === null ? '<span class="fd-muted">' + esc(title) + '</span>'
                        : '<a href="' + esc(href) + '"' + viewAttrs +
                        (isLink || isFiles ? ' target="_blank" rel="noopener noreferrer"' : '') +
                        '>' + esc(title) + '</a>') + '</div>' +
                    (d.description ? '<div class="fd-desc">' + esc(d.description) + '</div>' : '') +
                    (meta.length ? '<div class="fd-meta">' + meta.join(' · ') + '</div>' : '') +
                    (also ? '<div class="fd-also">' + also + '</div>' : '') +
                    (hcRows && !isFiles
                        ? '<span class="fd-hc-tag' + (d.show_in_help_centre ? '' : ' fd-hc-hidden') + '">' +
                          esc(d.show_in_help_centre ? t('hc_shown') : t('hc_hidden')) + '</span>'
                        : '') +
                '</div>' +
                '<div class="fd-actions">' +
                    '<button type="button" class="fd-btn fd-info" data-info="' + d.id +
                        '" title="' + esc(t('info_title')) + '" aria-label="' + esc(t('info_title')) + '">i</button>' +
                    (href === null ? '' : '<a class="fd-btn" href="' + esc(href) + '"' + viewAttrs +
                        (isLink || isFiles ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' +
                        esc(isLink || isFiles ? t('open') : t('download')) + '</a>') +
                    // GH #303: Save to Files - a stored file only (a web link or a Files link has no bytes here)
                    (self.canSave && d.kind === 'file'
                        ? '<button type="button" class="fd-btn" data-stf="' + d.id + '" data-name="' + esc(d.title) + '">' + esc(t('save_to_files')) + '</button>'
                        : '') +
                    (self.canEdit && hcRows
                        ? '<button type="button" class="fd-btn" data-hc="' + d.id + '" data-show="' +
                          (d.show_in_help_centre ? '0' : '1') + '">' +
                          esc(d.show_in_help_centre ? t('hc_hide') : t('hc_show')) + '</button>'
                        : '') +
                    (self.canEdit
                        ? '<button type="button" class="fd-btn fd-danger" data-remove="' + d.id +
                          '" data-name="' + esc(d.title) + '">' + esc(t('remove')) + '</button>'
                        : '') +
                '</div>' +
            '</div>';
        }).join('');

        this.$more.hidden = this.items.length >= this.total;
    };

    /**
     * Upload files against a parent, one request per file.
     *
     * A single multi-file request would fail as a lump, and one oversized file
     * among ten should not lose the other nine — so each is sent on its own and
     * reported on its own.
     *
     * Extracted from Panel.upload() and exported, because the ticket note modal
     * (discussion #69) uploads without a panel: a new note has no id until it is
     * saved, so its files are held in the browser and sent once it has one.
     * There is one statement of the endpoint's contract, and both callers use it.
     *
     * @return Promise<{ok: File[], failed: {name: string, error: string}[]}>
     *         Never rejects — a caller has to be able to tell "three of four
     *         worked" from "the whole thing blew up", and a rejection collapses
     *         those into the same outcome.
     */
    function uploadFiles(api, parentType, parentId, files) {
        var res = { ok: [], failed: [] };
        if (!files || !files.length || !parentId) return Promise.resolve(res);

        var chain = Promise.resolve();
        files.forEach(function (f) {
            chain = chain.then(function () {
                var fd = new FormData();
                fd.append('parent_type', parentType);
                fd.append('parent_id', parentId);
                fd.append('document', f);
                return fetch(api + 'save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) res.ok.push(f);
                        else res.failed.push({ name: f.name, error: (d && d.error) || t('failed') });
                    })
                    .catch(function () { res.failed.push({ name: f.name, error: t('failed') }); });
            });
        });
        return chain.then(function () { return res; });
    }

    Panel.prototype.upload = function (files) {
        if (!files || !files.length || !this.parentId) return;
        var self = this;
        this.clearFail();
        uploadFiles(this.api, this.parentType, this.parentId, files).then(function (res) {
            res.failed.forEach(function (f) { self.fail(f.name + ': ' + f.error); });
            self.load(true);
        });
    };

    Panel.prototype.addLink = function () {
        var $url   = this.el.querySelector('.fd-url');
        var $title = this.el.querySelector('.fd-linktitle');
        var url    = $url.value.trim();
        if (!url || !this.parentId) return;
        var self = this;
        this.clearFail();
        fetch(this.api + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                parent_type:  this.parentType,
                parent_id:    this.parentId,
                external_url: url,
                title:        $title.value.trim()
            })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d.success) { self.fail(d.error); return; }
              $url.value = ''; $title.value = '';
              self.load(true);
          })
          .catch(function () { self.fail(); });
    };

    Panel.prototype.findExisting = function (q) {
        var box = this.el.querySelector('.fd-findresults');
        if (!q || q.length < 2 || !this.parentId) { box.hidden = true; box.innerHTML = ''; return; }
        var self = this;
        fetch(this.api + 'find.php?q=' + encodeURIComponent(q)
              + '&parent_type=' + encodeURIComponent(this.parentType) + '&parent_id=' + this.parentId,
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { self.fail(d.error); return; }
                box.hidden = false;
                var files = d.files || [];
                if (!d.documents.length && !files.length) {
                    box.innerHTML = '<div class="fd-findempty">' + esc(t('find_none')) + '</div>';
                    return;
                }
                // GH #303: files in Files the person can open - as a link, a copy, or either
                // (Files -> Settings -> Sharing). The server enforces it; this only offers it.
                var filesHtml = files.length ? '<div class="fd-findhead">' + esc(t('from_files')) + '</div>' + files.map(function (f) {
                    var btn = function (how) {
                        return '<button type="button" class="fd-findhow" data-files="' + f.item_id + '" data-how="' + how + '" title="' + esc(t('attach_' + how + '_title')) + '">' + esc(t('attach_' + how)) + '</button>';
                    };
                    return '<div class="fd-findrow fd-findfile">' +
                        '<span class="fd-findtitle">' + esc(f.name) + '</span>' +
                        '<span class="fd-findwhere">' + esc(f.path) + '</span>' +
                        '<span class="fd-findhows">' + (f.can_link ? btn('link') : '') + (f.can_copy ? btn('copy') : '') + '</span>' +
                    '</div>';
                }).join('') : '';
                box.innerHTML = filesHtml + d.documents.map(function (x) {
                    // Say where it already is. Attaching WIDENS who can read it,
                    // so the person doing it should be able to see that first.
                    var where = (x.also_on || []).map(function (a) {
                        return esc(a.label) + (a.name ? ': ' + esc(a.name) : '');
                    }).join(', ');
                    return '<button type="button" class="fd-findrow" data-attach="' + x.id + '">' +
                        '<span class="fd-findtitle">' + esc(x.title) + '</span>' +
                        (where ? '<span class="fd-findwhere">' + esc(t('currently_on', { where: where })) + '</span>' : '') +
                    '</button>';
                }).join('');
            })
            .catch(function () { self.fail(); });
    };

    /** GH #303: attach a file from Files, as a link or a copy (attach.php -> FilesAttach::attach). */
    Panel.prototype.attachFromFiles = function (itemId, how) {
        var self = this;
        fetch(this.api + 'attach.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ files_item_id: itemId, how: how, parent_type: this.parentType, parent_id: this.parentId })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d.success) { self.fail(d.error); return; }
              self.el.querySelector('.fd-find').value = '';
              self.el.querySelector('.fd-findresults').hidden = true;
              self.load(true);
          })
          .catch(function () { self.fail(); });
    };

    Panel.prototype.attachExisting = function (documentId) {
        var self = this;
        fetch(this.api + 'attach.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                document_id: documentId,
                parent_type: this.parentType,
                parent_id:   this.parentId
            })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d.success) { self.fail(d.error); return; }
              var find = self.el.querySelector('.fd-find');
              find.value = '';
              self.el.querySelector('.fd-findresults').hidden = true;
              self.load(true);
          })
          .catch(function () { self.fail(); });
    };

    /** Show (true) or hide (false) one document in the Help Centre, then redraw. */
    Panel.prototype.setHelpCentre = function (documentId, show) {
        var self = this;
        this.clearFail();
        fetch(this.api + 'help_centre.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ document_id: documentId, parent_id: this.parentId, show: show ? 1 : 0 })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d.success) { self.fail(d.error); return; }
              self.load(true);
          })
          .catch(function () { self.fail(); });
    };

    Panel.prototype.remove = function (id, name) {
        var self = this;
        var ask = t('remove_confirm', { name: name || '' });
        // Use the app's own dialogue where the page has it, so this looks like
        // every other confirmation rather than a browser alert.
        //
        // 🔴 `showConfirm` takes an OPTIONS OBJECT, not a string. Passing the
        // message as a bare argument meant `opts.message` was undefined, so
        // confirm.js set the body to '' and the dialogue appeared with a
        // title, an OK and a Cancel and NO QUESTION IN IT — on every one of
        // the record types this panel is used on. The browser `confirm`
        // fallback took the same string and read correctly, which is why this
        // only ever showed up where the app dialogue was present.
        //
        // 🔑 A helper whose argument is a string in one branch and an object
        // in the other cannot fail loudly: both are truthy, both resolve, and
        // the only symptom is a missing sentence.
        //
        // No new locale keys: the title and the button reuse `remove`, which
        // this panel already renders on the button that opens the dialogue.
        var confirmed = (typeof window.showConfirm === 'function')
            ? window.showConfirm({
                  title:    t('remove'),
                  message:  ask,
                  okLabel:  t('remove'),
                  okClass:  'danger'
              })
            : Promise.resolve(window.confirm(ask));

        Promise.resolve(confirmed).then(function (ok) {
            if (!ok) return;
            return fetch(self.api + 'unlink.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    document_id: id,
                    parent_type: self.parentType,
                    parent_id:   self.parentId
                })
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  if (!d.success) { self.fail(d.error); return; }
                  // Say so when it was the last link — the file is now gone, and
                  // "Remove" reading as "detach" everywhere else would mislead.
                  if (d.orphaned && typeof window.showToast === 'function') {
                      window.showToast(t('removed_last'), 'success');
                  }
                  self.load(true);
              });
        }).catch(function () { self.fail(); });
    };

    /* ─── The ⓘ modal: what is this document attached to? ──────────────────
       Its own thing rather than part of Panel, because the command palette
       needs it too and has no panel — a document result there is a row in an
       overlay, not a list on a record. One implementation, two callers, same
       reason the resolver is shared server-side. */

    /** Removes the document details dialog and its Escape handler. */
    function closeInfo() {
        var old = document.getElementById('fdInfoOverlay');
        if (old) old.remove();
        document.removeEventListener('keydown', onInfoKey);
    }
    /** Closes the document details dialog on Escape. */
    function onInfoKey(e) { if (e.key === 'Escape') closeInfo(); }

    /**
     * Opens the document details dialog: what the document is, whether its contents are searchable, and every record it is attached to.
     *
     * Reads api/documents/links.php. Records the analyst cannot see are shown only as a count.
     */
    function showInfo(apiBase, documentId, baseUrl) {
        closeInfo();
        var ov = document.createElement('div');
        ov.id = 'fdInfoOverlay';
        ov.className = 'fd-modal-overlay';
        ov.innerHTML = '<div class="fd-modal" role="dialog" aria-modal="true">' +
                '<div class="fd-modal-head"><strong class="fd-modal-title">' + esc(t('info_title')) + '</strong>' +
                '<button type="button" class="fd-modal-x" aria-label="' + esc(t('close')) + '">&times;</button></div>' +
                '<div class="fd-modal-body">' + esc(t('loading')) + '</div>' +
            '</div>';
        document.body.appendChild(ov);
        document.addEventListener('keydown', onInfoKey);

        // Click the backdrop to dismiss, but not a click inside the dialogue.
        ov.addEventListener('click', function (e) { if (e.target === ov) closeInfo(); });
        ov.querySelector('.fd-modal-x').addEventListener('click', closeInfo);

        var body = ov.querySelector('.fd-modal-body');
        fetch(apiBase + 'links.php?id=' + encodeURIComponent(documentId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { body.textContent = d.error || t('failed'); return; }
                var doc = d.document || {};
                ov.querySelector('.fd-modal-title').textContent = doc.title || t('info_title');

                var meta = [];
                if (doc.kind === 'link') meta.push(t('kind_link'));
                else {
                    if (doc.original_name) meta.push(doc.original_name);
                    if (doc.size_bytes)    meta.push(bytes(doc.size_bytes));
                }
                if (doc.uploaded_by_name) meta.push(t('by', { name: doc.uploaded_by_name }));
                if (doc.created_datetime) meta.push(doc.created_datetime);

                // Say plainly whether the contents are searchable. "Not indexed
                // yet" and "nothing readable in it" look identical otherwise.
                var idx = '';
                if (doc.kind !== 'link') {
                    if (doc.index_status === 'extracted' || doc.index_status === 'truncated') {
                        idx = t('idx_ok', { n: doc.index_chars || 0 });
                    } else if (doc.index_status === 'pending' || doc.index_status === 'extracting') {
                        idx = t('idx_pending');
                    } else if (doc.index_status === 'unsupported') {
                        idx = t('idx_unsupported');
                    } else if (doc.index_status) {
                        idx = t('idx_failed');
                    }
                }

                var links = (d.links || []).map(function (l) {
                    var label = esc(l.label) + (l.name ? ': ' + esc(l.name) : '');
                    return '<li>' + (l.url
                        ? '<a href="' + esc((baseUrl || '') + l.url) + '">' + label + '</a>'
                        : label) + '</li>';
                }).join('');

                body.innerHTML =
                    (doc.description ? '<p class="fd-modal-desc">' + esc(doc.description) + '</p>' : '') +
                    (meta.length ? '<p class="fd-modal-meta">' + esc(meta.join(' · ')) + '</p>' : '') +
                    (idx ? '<p class="fd-modal-meta">' + esc(idx) + '</p>' : '') +
                    '<h5 class="fd-modal-sub">' + esc(t('attached_to')) + '</h5>' +
                    (links ? '<ul class="fd-modal-links">' + links + '</ul>'
                           : '<p class="fd-modal-meta">' + esc(t('attached_none')) + '</p>') +
                    (d.hidden_count > 0
                        // A count, never a name. It tells you the document is more
                        // widely attached than you can see — which matters before
                        // you attach it somewhere else — and identifies nothing.
                        ? '<p class="fd-modal-hidden">' + esc(t('attached_hidden', { n: d.hidden_count })) + '</p>'
                        : '');
            })
            .catch(function () { body.textContent = t('failed'); });
    }

    window.FreeITSMDocuments = {
        /**
         * Mounts a Documents panel in an element (or a selector) and returns it, or null when the element is missing.
         */
        mount: function (el, opts) {
            if (typeof el === 'string') el = document.querySelector(el);
            if (!el) return null;
            return new Panel(el, opts || {});
        },
        /** Open the "what is this attached to" dialogue for a document id. */
        info: showInfo,
        /**
         * Attach already-chosen files to a parent, without a panel.
         *
         * For a host that has files before it has a parent to hang them on — the
         * ticket note modal being the case it was built for. Resolves with
         * {ok, failed}; see uploadFiles().
         */
        upload: function (parentType, parentId, files, apiBase) {
            return uploadFiles(apiBase || '../api/documents/', parentType, parseInt(parentId, 10), files);
        }
    };
})();
