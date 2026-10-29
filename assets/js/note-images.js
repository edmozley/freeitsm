/**
 * Pictures inside a ticket note (GH #224) - the note box, and drawing a saved note.
 *
 * A note stays PLAIN TEXT. A pasted picture travels with the note as a file and
 * the text holds a marker where it sits: "[image:new-1]" in the browser, which
 * api/tickets/save_note.php swaps for "[image:<document id>]" once the picture is
 * stored. includes/note_images.php says why notes are not HTML.
 *
 *   NoteImages.attachEditor(el)      the note box: paste a screenshot at the cursor
 *   NoteImages.render(text, docs, o) a saved note's text as HTML, pictures in place
 *
 * Loaded by the Tickets page and the self-service portal; the portal only renders.
 */
(function () {
    'use strict';

    var MARKER_RE = /\[image:(\d+)\]/g;
    // What may be drawn inline. SVG is deliberately absent: it can carry script.
    var PICTURE_RE = /\.(png|jpe?g|gif|webp|bmp)$/i;
    var PASTE_TYPES = /^image\/(png|jpeg|gif|webp|bmp)$/;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * A saved note's text as HTML: escaped, with each marker drawn as its picture.
     *
     * TRAP: a marker becomes a picture ONLY when that document is one of THIS
     * note's own files. Anyone can type "[image:123]"; it must never pull in a
     * file from another ticket, so an unknown id is left as the text it is.
     *
     * @param {string} text   the note's text
     * @param {Array}  docs   the note's documents ({id, kind, original_name, title, size_bytes})
     * @param {Object} o      url(doc) -> where the picture is served; attrs(doc, name) ->
     *                        extra attributes for its link (the viewer hook); alt
     * @return {{html: string, used: Object}} used = the document ids drawn inline,
     *         so the caller can leave them out of the note's file list
     */
    function render(text, docs, o) {
        o = o || {};
        var byId = {};
        (docs || []).forEach(function (d) {
            var name = d.original_name || d.title || '';
            if (d.kind !== 'link' && PICTURE_RE.test(name)) byId[String(d.id)] = d;
        });
        var used = {};
        var html = '';
        var last = 0;
        var src = String(text || '');
        src.replace(MARKER_RE, function (m, id, at) {
            html += escapeHtml(src.slice(last, at));
            var d = byId[id];
            if (d) {
                used[id] = true;
                var name = d.original_name || d.title || '';
                var url = o.url(d);
                html += '<a class="note-inline-image" href="' + escapeHtml(url) + '"'
                      + (o.attrs ? ' ' + o.attrs(d, name) : '') + '>'
                      + '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(o.alt || name) + '" loading="lazy"></a>';
            } else {
                html += escapeHtml(m);
            }
            last = at + m.length;
            return m;
        });
        html += escapeHtml(src.slice(last));
        return { html: html, used: used };
    }

    /**
     * Make a contenteditable element the note box.
     *
     * Plain text in, plain text out: pasted or dropped text goes in as text (no
     * formatting from Word or a web page), and a pasted picture goes in at the
     * cursor. Uses the paste EVENT, not navigator.clipboard - that one needs
     * HTTPS, and FreeITSM runs on plain HTTP too.
     *
     * Text wins over a picture when the clipboard has both: a cell copied from
     * Excel carries a PNG of itself as well, and the analyst wanted the text.
     */
    function attachEditor(el) {
        var files = {};   // key -> File, for the pictures pasted since clear()
        var seq = 0;
        var urls = [];

        function syncEmpty() {
            var empty = !el.textContent.trim() && !el.querySelector('img');
            el.classList.toggle('is-empty', empty);
        }

        function insertText(txt) {
            if (!document.execCommand('insertText', false, txt)) {
                insertNode(document.createTextNode(txt));
            }
        }

        function insertNode(node) {
            var sel = window.getSelection();
            if (sel && sel.rangeCount && el.contains(sel.getRangeAt(0).commonAncestorContainer)) {
                var r = sel.getRangeAt(0);
                r.deleteContents();
                r.insertNode(node);
                r.setStartAfter(node);
                r.collapse(true);
                sel.removeAllRanges();
                sel.addRange(r);
            } else {
                el.appendChild(node);
            }
        }

        function insertPicture(file) {
            var key = 'new-' + (++seq);
            var ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
            // A screenshot arrives named "image.png", every one of them; give each
            // its own name, which is the title it will carry as a document.
            var f = new File([file], 'Pasted picture ' + seq + '.' + ext, { type: file.type });
            files[key] = f;
            var url = URL.createObjectURL(f);
            urls.push(url);
            var img = document.createElement('img');
            img.src = url;
            img.setAttribute('data-note-image', key);
            img.alt = f.name;
            insertNode(img);
        }

        function pictures(list) {
            var out = [];
            for (var i = 0; i < (list ? list.length : 0); i++) {
                var it = list[i];
                var f = it.getAsFile ? (it.kind === 'file' ? it.getAsFile() : null) : it;
                if (f && PASTE_TYPES.test(f.type)) out.push(f);
            }
            return out;
        }

        el.addEventListener('paste', function (e) {
            var cd = e.clipboardData;
            if (!cd) return;   // very old browser: leave it to the default
            e.preventDefault();
            var txt = cd.getData('text/plain');
            if (txt) insertText(txt);
            else pictures(cd.items).forEach(insertPicture);
            syncEmpty();
        });

        el.addEventListener('drop', function (e) {
            var dt = e.dataTransfer;
            if (!dt) return;
            e.preventDefault();
            if (document.caretRangeFromPoint) {
                var r = document.caretRangeFromPoint(e.clientX, e.clientY);
                if (r && el.contains(r.startContainer)) {
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(r);
                }
            }
            var pics = pictures(dt.files);
            if (pics.length) pics.forEach(insertPicture);
            else if (dt.getData('text/plain')) insertText(dt.getData('text/plain'));
            syncEmpty();
        });

        el.addEventListener('input', syncEmpty);

        /**
         * The box as {text, images: [{key, file}]}. Line breaks come from
         * whichever of <div>, <br> or a newline the browser chose for Enter; any
         * other markup (Ctrl+B, say) is dropped and its text kept.
         */
        function serialize() {
            var out = '';
            var images = [];
            var seen = {};
            (function walk(node) {
                node.childNodes.forEach(function (c) {
                    if (c.nodeType === 3) { out += c.nodeValue; return; }
                    if (c.nodeType !== 1) return;
                    var tag = c.tagName;
                    if (tag === 'BR') { out += '\n'; return; }
                    if (tag === 'IMG') {
                        var key = c.getAttribute('data-note-image');
                        if (key && files[key]) {
                            out += '[image:' + key + ']';
                            if (!seen[key]) { seen[key] = 1; images.push({ key: key, file: files[key] }); }
                        }
                        return;
                    }
                    if ((tag === 'DIV' || tag === 'P') && out !== '' && out.slice(-1) !== '\n') out += '\n';
                    walk(c);
                });
            })(el);
            return { text: out.replace(/ /g, ' '), images: images };
        }

        function clear() {
            el.innerHTML = '';
            files = {};
            urls.forEach(function (u) { URL.revokeObjectURL(u); });
            urls = [];
            syncEmpty();
        }

        syncEmpty();
        return { serialize: serialize, clear: clear, focus: function () { el.focus(); } };
    }

    window.NoteImages = { render: render, attachEditor: attachEditor };
})();
