<?php
/**
 * Self-Service Portal — New ticket.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css.
 */
$pageTitleKey = 'self-service.new_ticket.title';   // a KEY: i18n starts in header.php
$activeNav    = '';   // reached from the dashboard button, not the nav
// App-shell: the compose screen fills the viewport and the EDITOR takes up the
// slack, rather than the page scrolling with a fixed-height box in it.
$bodyClass    = 'portal-app';
// Loads assets/js/screen-recorder.js — shared with the reply composer on
// tickets.php. Pairs with includes/record-modal.php in the markup below.
$needsRecorder = true;

// The rich-text editor, loaded only here — no other portal page needs 500KB of
// editor. Bundled locally, so no third-party CDN is involved.
$pageHead = '<script src="../assets/js/tinymce/tinymce.min.js"></script>';

// Page-specific styling only — shared chrome lives in self-service.css.
$pageStyles = <<<'CSS'
.page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 14px 0;
            flex-shrink: 0;
        }

        .form-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 20px 22px;
        }

        /* ── Compose layout ────────────────────────────────────────────────
           Subject across the top, then the editor and the settings panel side
           by side. The editor is the thing people actually spend time in, so it
           gets the height; everything you merely SET lives on the right. */
        .compose-top { margin-bottom: 14px; }
        .compose-top label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text, #333);
        }
        .compose-top input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            font-size: 15px;
            font-family: inherit;
            background: var(--surface, #fff);
            color: var(--text, #333);
        }
        .compose-top input:focus { outline: none; border-color: var(--ss-accent, #10b981); }

        /* ── Filling the height ────────────────────────────────────────────
           The editor should take whatever space is left, not a guessed number of
           pixels. So the whole column is a flex chain from the viewport down —
           layout → card → body → editor — and each link sets `min-height: 0`,
           without which a flex child refuses to shrink below its content and the
           chain silently breaks. TinyMCE is then told height:100% and fills its
           box. Any magic `calc(100vh - 330px)` would drift the moment the
           subject field, the title or the padding changed. */
        /* Selector matches `body.portal-app .portal-layout` in self-service.css,
           which zeroes the padding for the full-bleed My Tickets panes. Compose
           wants the app-shell height but KEEPS its breathing room, and an equal
           selector is needed to say so — a bare `.portal-layout` loses on
           specificity however late it appears. */
        body.portal-app .portal-layout {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 48px);   /* 48px = the portal header */
            padding: 20px 24px;
            box-sizing: border-box;
            overflow: hidden;
        }
        .form-card {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .form-card form { flex: 1; display: flex; flex-direction: column; min-height: 0; }

        .compose-body {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 18px;
            align-items: stretch;
            min-height: 0;
        }
        .compose-editor { min-width: 0; display: flex; flex-direction: column; min-height: 0; }
        .compose-editor .tox-tinymce { flex: 1 !important; height: auto !important; }
        /* The panel scrolls on its own when the recording controls open. */
        .compose-side {
            border-left: 1px solid var(--border, #e5e7eb);
            padding-left: 18px;
            overflow-y: auto;
            min-height: 0;
        }
        .compose-side .form-group { margin-bottom: 16px; }
        .compose-side .form-actions { margin-top: 20px; flex-direction: column; align-items: stretch; gap: 8px; }
        .compose-side .btn-submit,
        .compose-side .btn-cancel { width: 100%; justify-content: center; text-align: center; }

        /* TinyMCE ships its own chrome; soften the seam so it reads as one field. */
        .compose-editor .tox-tinymce {
            border: 1px solid var(--border, #e5e7eb) !important;
            border-radius: 6px !important;
        }

        @media (max-width: 900px) {
            .compose-body { grid-template-columns: 1fr; }
            .compose-side {
                border-left: none;
                border-top: 1px solid var(--border, #e5e7eb);
                padding-left: 0;
                padding-top: 16px;
                max-height: none;
            }
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text, #333);
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--ss-accent, #0078d4);
            box-shadow: 0 0 0 2px rgba(0,120,212,0.1);
        }
        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .btn-submit {
            padding: 10px 24px;
            background: var(--ss-accent, #0078d4);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover { background: var(--ss-accent-hover, #005a9e); }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }
        .btn-cancel {
            padding: 10px 24px;
            background: var(--surface-hover, #f3f4f6);
            color: var(--text, #333);
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-cancel:hover { background: var(--surface-hover, #e5e7eb); }

        .error-message {
            background: var(--danger-bg, #fee);
            color: var(--danger-text, #c33);
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid var(--danger-border, #c33);
            display: none;
        }
        .success-message {
            background: var(--success-bg, #d1fae5);
            color: var(--success-text, #065f46);
            padding: 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid var(--success-border, #065f46);
            display: none;
        }
        .success-message a {
            color: var(--success-text, #065f46);
            font-weight: 600;
        }

        /* Attachment dropzone */
        .dropzone {
            border: 2px dashed var(--border, #ddd);
            border-radius: 6px;
            padding: 20px;
            text-align: center;
            color: var(--text-faint, #999);
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dropzone:hover { border-color: var(--ss-accent, #0078d4); color: var(--ss-accent, #0078d4); }
        .dropzone.dragover { border-color: var(--ss-accent, #0078d4); background: var(--ss-accent-soft, #f0f7ff); color: var(--ss-accent, #0078d4); }
        .dropzone-icon { font-size: 24px; margin-bottom: 6px; }
        .dropzone-browse { color: var(--ss-accent, #0078d4); font-weight: 600; }

        .attachment-list { margin-top: 10px; }
        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            background: var(--surface-hover, #f9fafb);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 4px;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .attachment-item .file-info {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .attachment-item .file-name {
            font-weight: 500;
            color: var(--text, #333);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .attachment-item .file-size { color: var(--text-faint, #999); white-space: nowrap; }
        .attachment-item .remove-btn {
            background: none;
            border: none;
            color: var(--text-faint, #999);
            cursor: pointer;
            font-size: 18px;
            padding: 0 4px;
            line-height: 1;
        }
        .attachment-item .remove-btn:hover { color: var(--danger-text, #c33); }

        /* Screen recording */
        .record-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: var(--surface, #fff);
            color: var(--ss-accent, #0078d4);
            border: 1px solid var(--ss-accent, #0078d4);
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            margin-top: 8px;
        }
        .record-toggle:hover { background: var(--ss-accent-soft, #f0f7ff); }
        .record-toggle:disabled { opacity: 0.5; cursor: not-allowed; }
        .record-toggle .rec-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dc2626;
        }
        /* The recording modal's styling is shared with the reply composer and
           lives in assets/css/self-service.css (.rec-modal and friends). */
        .recording-item .file-info::before {
            content: '🎥';
            margin-right: 4px;
        }

        /* Deflection suggestions under the subject field. Quiet by design: a
           helpful aside, never a barrier to reporting a problem. */
        .deflect {
            margin-top: 10px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            background: var(--surface-hover, #fafafa);
            padding: 12px 14px;
        }
        .deflect-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted, #666);
            margin-bottom: 8px;
        }
        .deflect-list { display: grid; gap: 6px; }
        .deflect-item {
            display: block;
            padding: 8px 10px;
            border-radius: 6px;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            text-decoration: none;
        }
        .deflect-item:hover { border-color: var(--ss-accent, #0078d4); }
        .deflect-item-title {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ss-accent, #0078d4);
        }
        .deflect-item-preview {
            display: block;
            font-size: 12px;
            color: var(--text-muted, #666);
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
CSS;

$pageScripts = <<<'JS'
let attachments = [];
    let recordings = []; // [{recording_id, name, size_bytes, duration_seconds}]

    // Capture state lives inside ScreenRecorder (assets/js/screen-recorder.js),
    // not here — two pages must not disagree about what "currently recording"
    // means.

    document.addEventListener('DOMContentLoaded', function() {
        loadMailboxes();
        initDropzone();
        initDeflection();
        initEditor();

        // Hide the record button entirely if the browser can't do screen capture
        // (iOS Safari has no getDisplayMedia at all) — better than letting them
        // press it and meet a confusing failure.
        if (!ScreenRecorder.isSupported()) {
            document.getElementById('recordToggle').style.display = 'none';
        }
    });

    /*
     * The rich-text editor. Same component and licence key the analyst compose
     * window uses, with a DELIBERATELY SHORTER toolbar: a customer describing a
     * problem needs emphasis, lists and the odd link — not fonts, colours,
     * tables, media embeds or a source-code view. Fewer choices, less to get
     * wrong, and less exotic markup arriving in the analyst's inbox.
     *
     * The height fills the column rather than being a fixed box, which is the
     * point of the compose layout.
     */
    function initEditor() {
        if (typeof tinymce === 'undefined') {
            // Editor unavailable — the plain textarea underneath still works, and
            // the server escapes plain text, so the form degrades rather than breaks.
            console.error('FreeITSM: TinyMCE did not load — using the plain text box.');
            return;
        }
        const isDark = document.documentElement.getAttribute('data-theme-mode') === 'dark';
        tinymce.init({
            selector: '#description',
            license_key: 'gpl',
            // 100% of the flex box the CSS gives it — see the height notes above.
            height: '100%',
            menubar: false,
            statusbar: false,
            branding: false,
            skin: isDark ? 'oxide-dark' : 'oxide',
            content_css: isDark ? 'dark' : 'default',
            plugins: ['autolink', 'lists', 'link', 'wordcount'],
            toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
            // TinyMCE ignores the textarea's own placeholder, and an empty
            // full-height editor is an intimidating blank void — tell them what
            // to write, as the old plain box did.
            placeholder: document.getElementById('description').getAttribute('placeholder') || '',
            // 16px on touch so iOS Safari doesn't zoom the page when tapping in.
            content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
            setup: function (editor) { window.ssEditor = editor; }
        });
    }

    /*
     * Ticket deflection: show Help Centre articles matching the subject as it is
     * typed, so someone can find the answer before raising a ticket at all.
     *
     * Deliberately quiet. It only appears once there is something to show, never
     * interrupts, and never blocks Submit — a suggestion that gets in the way of
     * reporting a problem is worse than no suggestion. Nothing is logged about
     * what was typed.
     *
     * Reuses the Help Centre's endpoint, so the same two scopes apply: articles
     * marked for customers, in this requester's company or shared. It cannot
     * surface anything the Help Centre itself wouldn't show.
     */
    let deflectTimer = null;
    let deflectLastQuery = '';

    function initDeflection() {
        const subject = document.getElementById('subject');
        if (!subject) return;

        subject.addEventListener('input', function () {
            const q = subject.value.trim();
            clearTimeout(deflectTimer);

            // Below three characters the results are noise.
            if (q.length < 3) { hideDeflection(); return; }
            if (q === deflectLastQuery) return;

            deflectTimer = setTimeout(() => runDeflection(q), 400);
        });
    }

    async function runDeflection(query) {
        deflectLastQuery = query;
        try {
            const response = await fetch('../api/self-service/get_knowledge_articles.php?limit=3&q='
                                         + encodeURIComponent(query));
            const data = await response.json();
            if (!data.success || !data.articles || !data.articles.length) { hideDeflection(); return; }
            showDeflection(data.articles);
        } catch (e) {
            hideDeflection();   // never let a suggestion failure disturb the form
        }
    }

    function showDeflection(articles) {
        const box = document.getElementById('deflect');
        const list = document.getElementById('deflectList');
        const title = document.getElementById('deflectTitle');
        if (!box || !list) return;

        title.textContent = window.t('self-service.new_ticket.deflect_title');
        list.innerHTML = articles.map(a =>
            '<a class="deflect-item" href="help-centre.php?id=' + encodeURIComponent(a.id) + '" target="_blank" rel="noopener">'
            + '<span class="deflect-item-title">' + escapeHtmlSs(a.title || '') + '</span>'
            + '<span class="deflect-item-preview">' + escapeHtmlSs((a.preview || '').slice(0, 120)) + '</span>'
            + '</a>'
        ).join('');
        box.style.display = '';
    }

    function hideDeflection() {
        const box = document.getElementById('deflect');
        if (box) box.style.display = 'none';
    }

    function escapeHtmlSs(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : text;
        return div.innerHTML;
    }

    function initDropzone() {
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');

        dropzone.addEventListener('click', () => fileInput.click());

        dropzone.addEventListener('dragover', e => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone.addEventListener('drop', e => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            addFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', () => {
            addFiles(fileInput.files);
            fileInput.value = '';
        });
    }

    function addFiles(files) {
        for (const file of files) {
            const duplicate = attachments.some(a => a.file.name === file.name && a.file.size === file.size);
            if (!duplicate) {
                attachments.push({ file });
            }
        }
        renderAttachments();
    }

    function removeAttachment(index) {
        attachments.splice(index, 1);
        renderAttachments();
    }

    function renderAttachments() {
        const list = document.getElementById('attachmentList');
        const items = [];

        attachments.forEach((a, i) => {
            items.push(
                '<div class="attachment-item">' +
                    '<div class="file-info">' +
                        '<span class="file-name">' + escapeHtml(a.file.name) + '</span>' +
                        '<span class="file-size">(' + formatFileSize(a.file.size) + ')</span>' +
                    '</div>' +
                    '<button type="button" class="remove-btn" onclick="removeAttachment(' + i + ')">&times;</button>' +
                '</div>'
            );
        });

        recordings.forEach((r, i) => {
            const durLabel = r.duration_seconds ? ' &middot; ' + formatDuration(r.duration_seconds) : '';
            items.push(
                '<div class="attachment-item recording-item">' +
                    '<div class="file-info">' +
                        '<span class="file-name">' + escapeHtml(r.name) + '</span>' +
                        '<span class="file-size">(' + formatFileSize(r.size_bytes) + durLabel + ')</span>' +
                    '</div>' +
                    '<button type="button" class="remove-btn" onclick="removeRecording(' + i + ')">&times;</button>' +
                '</div>'
            );
        });

        list.innerHTML = items.join('');
    }

    function formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function removeRecording(index) {
        recordings.splice(index, 1);
        renderAttachments();
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(',')[1]);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    // -------------------- Screen recording --------------------
    //
    // The recorder itself lives in assets/js/screen-recorder.js and is shared
    // with the reply composer on tickets.php — capture, MIME negotiation, the
    // countdown, preview and upload are identical whether you are opening a
    // ticket or answering one. Only what happens to a CLAIMED recording differs,
    // which is this callback.

    ScreenRecorder.init({
        uploadUrl: '../api/self-service/upload_recording.php',
        onClaimed: function (rec) {
            recordings.push(rec);
            renderAttachments();
        }
    });

    // -------------------- Mailbox loading --------------------

    async function loadMailboxes() {
        const select = document.getElementById('mailbox');
        try {
            const resp = await fetch('../api/self-service/get_mailboxes.php');
            const data = await resp.json();
            if (data.success && data.mailboxes.length > 0) {
                select.innerHTML = data.mailboxes.map(m =>
                    '<option value="' + m.id + '">' + escapeHtml(m.name) + ' (' + escapeHtml(m.target_mailbox) + ')</option>'
                ).join('');
                if (data.mailboxes.length === 1) {
                    select.value = data.mailboxes[0].id;
                }
            } else {
                select.innerHTML = '<option value="">' + escapeHtml(window.t('self-service.new_ticket.mailbox_none')) + '</option>';
            }
        } catch (err) {
            select.innerHTML = '<option value="">' + escapeHtml(window.t('self-service.new_ticket.mailbox_failed')) + '</option>';
        }
    }

    async function handleSubmit(e) {
        e.preventDefault();

        // Block submit if there's a recorded-but-not-claimed blob sitting in the
        // preview. Easy to miss the "Use this" button otherwise, and the recording
        // would be silently lost when the form posts.
        if (ScreenRecorder.hasUnclaimed()) {
            // Reopen the recording modal so the Use this / Discard choice is
            // in front of them — the whole point of the guard.
            ScreenRecorder.open();
            const status = document.getElementById('recStatus');
            status.style.color = '#dc2626';
            status.style.fontWeight = '600';
            status.innerHTML = window.t('self-service.recorder.claim_prompt', {
                use: '<strong>' + escapeHtml(window.t('self-service.recorder.use')) + '</strong>',
                discard: '<strong>' + escapeHtml(window.t('self-service.recorder.discard')) + '</strong>'
            });
            return;
        }

        const btn = document.getElementById('submitBtn');
        const errEl = document.getElementById('errorMsg');
        const successEl = document.getElementById('successMsg');
        errEl.style.display = 'none';
        successEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = window.t('self-service.new_ticket.submitting');

        try {
            // Prepare attachments as base64
            const attachmentData = [];
            for (const a of attachments) {
                const content = await fileToBase64(a.file);
                attachmentData.push({ name: a.file.name, type: a.file.type, size: a.file.size, content });
            }

            const resp = await fetch('../api/self-service/create_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mailbox_id: document.getElementById('mailbox').value || null,
                    subject: document.getElementById('subject').value.trim(),
                    priority: document.getElementById('priority').value,
                    // The editor holds the content, not the textarea it replaced.
                    // Falls back to the textarea if TinyMCE failed to load, so the
                    // form still works rather than silently posting nothing.
                    description: (window.ssEditor ? window.ssEditor.getContent()
                                                  : document.getElementById('description').value).trim(),
                    attachments: attachmentData,
                    recording_ids: recordings.map(r => r.recording_id)
                })
            });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('formCard').style.display = 'none';
                successEl.innerHTML = window.t('self-service.new_ticket.created', {
                    number: '<strong>' + escapeHtml(data.ticket_number) + '</strong>',
                    view: '<a href="tickets.php?id=' + data.ticket_id + '">' + escapeHtml(window.t('self-service.new_ticket.view_ticket')) + '</a>',
                    dashboard: '<a href="index.php">' + escapeHtml(window.t('self-service.new_ticket.return_dashboard')) + '</a>'
                });
                successEl.style.display = 'block';
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = window.t('self-service.new_ticket.submit');
            }
        } catch (err) {
            errEl.textContent = window.t('self-service.new_ticket.create_failed');
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = window.t('self-service.new_ticket.submit');
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
JS;

require __DIR__ . '/includes/header.php';
?>
        <h1 class="page-title"><?php echo htmlspecialchars(t('self-service.new_ticket.heading')); ?></h1>

        <div class="error-message" id="errorMsg"></div>
        <div class="success-message" id="successMsg"></div>

        <div class="form-card" id="formCard">
            <form id="ticketForm" onsubmit="return handleSubmit(event)" autocomplete="off">
                <!-- Compose layout: subject across the top, the editor filling the
                     height on the left, and everything you SET (queue, priority,
                     files, recording) gathered in a panel on the right — so the
                     thing you spend the time on gets the space. -->
                <div class="compose-top">
                    <label for="subject"><?php echo htmlspecialchars(t('self-service.new_ticket.subject')); ?></label>
                    <input type="text" id="subject" required placeholder="<?php echo htmlspecialchars(t('self-service.new_ticket.subject_placeholder')); ?>" autocomplete="off">
                    <!-- Deflection: answers matching what they're typing. Hidden
                         until there's something to show, so the form is unchanged
                         for anyone whose question isn't in the knowledge base. -->
                    <div class="deflect" id="deflect" style="display:none;">
                        <div class="deflect-title" id="deflectTitle"></div>
                        <div class="deflect-list" id="deflectList"></div>
                    </div>
                </div>

                <div class="compose-body">
                    <div class="compose-editor">
                        <textarea id="description" placeholder="<?php echo htmlspecialchars(t('self-service.new_ticket.description_placeholder')); ?>"></textarea>
                    </div>

                    <aside class="compose-side">
                <div class="form-group">
                    <label for="mailbox"><?php echo htmlspecialchars(t('self-service.new_ticket.mailbox')); ?></label>
                    <select id="mailbox" required>
                        <option value=""><?php echo htmlspecialchars(t('self-service.new_ticket.mailbox_loading')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="priority"><?php echo htmlspecialchars(t('self-service.new_ticket.priority')); ?></label>
                    <select id="priority">
                        <?php
                        // Populate from the configured active priorities (consistent
                        // with the analyst New Ticket form, #40) rather than a fixed
                        // Low/Normal/High list. Requesters can pick any priority; the
                        // analyst re-triages if it's not appropriate.
                        try {
                            $ssPrioConn = connectToDatabase();
                            $ssPrios = $ssPrioConn->query("SELECT name, is_default FROM ticket_priorities WHERE is_active = 1 ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) { $ssPrios = []; }
                        if (!$ssPrios) {
                            // Fallback keeps the form usable if the table isn't reachable.
                            $ssPrios = [['name' => 'Low', 'is_default' => 0], ['name' => 'Normal', 'is_default' => 1], ['name' => 'High', 'is_default' => 0]];
                        }
                        $ssHasDefault = false;
                        foreach ($ssPrios as $p) { if ((int)$p['is_default'] === 1) { $ssHasDefault = true; break; } }
                        foreach ($ssPrios as $p):
                            $sel = ((int)$p['is_default'] === 1) || (!$ssHasDefault && $p['name'] === 'Normal');
                        ?>
                        <option value="<?php echo htmlspecialchars($p['name']); ?>"<?php echo $sel ? ' selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('self-service.new_ticket.attachments')); ?></label>
                    <div class="dropzone" id="dropzone">
                        <div class="dropzone-icon">📎</div>
                        <?php echo t('self-service.new_ticket.dropzone', ['browse' => '<span class="dropzone-browse">' . htmlspecialchars(t('self-service.new_ticket.dropzone_browse')) . '</span>']); ?>
                    </div>
                    <input type="file" id="fileInput" multiple style="display:none">
                    <div class="attachment-list" id="attachmentList"></div>

                    <button type="button" class="record-toggle" id="recordToggle" onclick="ScreenRecorder.open()">
                        <span class="rec-dot"></span> <?php echo htmlspecialchars(t('self-service.recorder.button')); ?>
                    </button>
                </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-submit" id="submitBtn"><?php echo htmlspecialchars(t('self-service.new_ticket.submit')); ?></button>
                            <a href="index.php" class="btn-cancel"><?php echo htmlspecialchars(t('self-service.new_ticket.cancel')); ?></a>
                        </div>
                    </aside>
                </div>
            </form>
        </div>

        <?php require __DIR__ . '/includes/record-modal.php'; ?>
<?php require __DIR__ . '/includes/footer.php';
