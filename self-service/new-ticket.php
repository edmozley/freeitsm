<?php
/**
 * Self-Service Portal - New Ticket
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();
require_once 'includes/auth.php';

$translationNamespaces = ['common', 'self-service'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('self-service.new_ticket.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }

        .portal-header {
            background: #0078d4;
            color: white;
            padding: 0 24px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .portal-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 15px;
        }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .portal-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .portal-nav a:hover { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2); color: white; }
        .portal-user {
            display: flex;
            align-items: center;
            position: relative;
        }

        .portal-layout {
            max-width: 700px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 24px 0;
        }

        .form-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 28px;
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0078d4;
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
            background: #0078d4;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover { background: #005a9e; }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }
        .btn-cancel {
            padding: 10px 24px;
            background: #f3f4f6;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-cancel:hover { background: #e5e7eb; }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
            display: none;
        }
        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #065f46;
            display: none;
        }
        .success-message a {
            color: #065f46;
            font-weight: 600;
        }

        /* Attachment dropzone */
        .dropzone {
            border: 2px dashed #ddd;
            border-radius: 6px;
            padding: 20px;
            text-align: center;
            color: #999;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dropzone:hover { border-color: #0078d4; color: #0078d4; }
        .dropzone.dragover { border-color: #0078d4; background: #f0f7ff; color: #0078d4; }
        .dropzone-icon { font-size: 24px; margin-bottom: 6px; }
        .dropzone-browse { color: #0078d4; font-weight: 600; }

        .attachment-list { margin-top: 10px; }
        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
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
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .attachment-item .file-size { color: #999; white-space: nowrap; }
        .attachment-item .remove-btn {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 18px;
            padding: 0 4px;
            line-height: 1;
        }
        .attachment-item .remove-btn:hover { color: #c33; }

        /* Screen recording */
        .record-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: #fff;
            color: #0078d4;
            border: 1px solid #0078d4;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            margin-top: 8px;
        }
        .record-toggle:hover { background: #f0f7ff; }
        .record-toggle:disabled { opacity: 0.5; cursor: not-allowed; }
        .record-toggle .rec-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dc2626;
        }
        .record-panel {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 16px;
            margin-top: 10px;
            background: #fafafa;
        }
        .record-panel.hidden { display: none; }
        .record-panel .mic-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #555;
            margin-bottom: 12px;
        }
        .record-panel video {
            width: 100%;
            max-height: 360px;
            background: #000;
            border-radius: 4px;
            margin-top: 10px;
        }
        .record-controls {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .record-controls button {
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-rec-start { background: #dc2626; color: white; }
        .btn-rec-start:hover { background: #b91c1c; }
        .btn-rec-stop { background: #1f2937; color: white; }
        .btn-rec-stop:hover { background: #111827; }
        .btn-rec-use { background: #0078d4; color: white; }
        .btn-rec-use:hover { background: #005a9e; }
        .btn-rec-discard { background: #f3f4f6; color: #333; border-color: #ddd; }
        .btn-rec-discard:hover { background: #e5e7eb; }
        .rec-status {
            font-size: 13px;
            color: #555;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .rec-status.recording { color: #dc2626; font-weight: 600; }
        .rec-status .pulse {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dc2626;
            animation: rec-pulse 1.2s infinite;
        }
        @keyframes rec-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .recording-item .file-info::before {
            content: '🎥';
            margin-right: 4px;
        }
    </style>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav">
            <a href="index.php"><?php echo htmlspecialchars(t('self-service.nav.dashboard')); ?></a>
            <a href="new-ticket.php" class="active"><?php echo htmlspecialchars(t('self-service.nav.new_ticket')); ?></a>
            <a href="help.php"><?php echo htmlspecialchars(t('self-service.nav.help')); ?></a>
        </nav>
        <?php include 'includes/user-menu.php'; ?>
    </div>

    <div class="portal-layout">
        <h1 class="page-title"><?php echo htmlspecialchars(t('self-service.new_ticket.heading')); ?></h1>

        <div class="error-message" id="errorMsg"></div>
        <div class="success-message" id="successMsg"></div>

        <div class="form-card" id="formCard">
            <form id="ticketForm" onsubmit="return handleSubmit(event)" autocomplete="off">
                <div class="form-group">
                    <label for="mailbox"><?php echo htmlspecialchars(t('self-service.new_ticket.mailbox')); ?></label>
                    <select id="mailbox" required>
                        <option value=""><?php echo htmlspecialchars(t('self-service.new_ticket.mailbox_loading')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="subject"><?php echo htmlspecialchars(t('self-service.new_ticket.subject')); ?></label>
                    <input type="text" id="subject" required placeholder="<?php echo htmlspecialchars(t('self-service.new_ticket.subject_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label for="priority"><?php echo htmlspecialchars(t('self-service.new_ticket.priority')); ?></label>
                    <select id="priority">
                        <option value="Low"><?php echo htmlspecialchars(t('self-service.new_ticket.priority_low')); ?></option>
                        <option value="Normal" selected><?php echo htmlspecialchars(t('self-service.new_ticket.priority_normal')); ?></option>
                        <option value="High"><?php echo htmlspecialchars(t('self-service.new_ticket.priority_high')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="description"><?php echo htmlspecialchars(t('self-service.new_ticket.description')); ?></label>
                    <textarea id="description" placeholder="<?php echo htmlspecialchars(t('self-service.new_ticket.description_placeholder')); ?>"></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('self-service.new_ticket.attachments')); ?></label>
                    <div class="dropzone" id="dropzone">
                        <div class="dropzone-icon">📎</div>
                        <?php echo t('self-service.new_ticket.dropzone', ['browse' => '<span class="dropzone-browse">' . htmlspecialchars(t('self-service.new_ticket.dropzone_browse')) . '</span>']); ?>
                    </div>
                    <input type="file" id="fileInput" multiple style="display:none">
                    <div class="attachment-list" id="attachmentList"></div>

                    <button type="button" class="record-toggle" id="recordToggle" onclick="toggleRecordPanel()">
                        <span class="rec-dot"></span> <?php echo htmlspecialchars(t('self-service.new_ticket.record_screen')); ?>
                    </button>
                    <div class="record-panel hidden" id="recordPanel">
                        <label class="mic-toggle">
                            <input type="checkbox" id="recMicToggle"> <?php echo htmlspecialchars(t('self-service.new_ticket.include_mic')); ?>
                        </label>
                        <div class="record-controls">
                            <button type="button" class="btn-rec-start" id="recStartBtn" onclick="startRecording()"><?php echo htmlspecialchars(t('self-service.new_ticket.rec_start')); ?></button>
                            <button type="button" class="btn-rec-stop" id="recStopBtn" onclick="stopRecording()" style="display:none"><?php echo htmlspecialchars(t('self-service.new_ticket.rec_stop')); ?></button>
                            <button type="button" class="btn-rec-use" id="recUseBtn" onclick="useRecording()" style="display:none"><?php echo htmlspecialchars(t('self-service.new_ticket.rec_use')); ?></button>
                            <button type="button" class="btn-rec-discard" id="recDiscardBtn" onclick="discardRecording()" style="display:none"><?php echo htmlspecialchars(t('self-service.new_ticket.rec_discard')); ?></button>
                            <span class="rec-status" id="recStatus"><?php echo htmlspecialchars(t('self-service.new_ticket.rec_ready')); ?></span>
                        </div>
                        <video id="recPreview" controls style="display:none"></video>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="index.php" class="btn-cancel"><?php echo htmlspecialchars(t('self-service.new_ticket.cancel')); ?></a>
                    <button type="submit" class="btn-submit" id="submitBtn"><?php echo htmlspecialchars(t('self-service.new_ticket.submit')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>
    let attachments = [];
    let recordings = []; // [{recording_id, name, size_bytes, duration_seconds}]

    // Recording state
    let mediaRecorder = null;
    let recordedChunks = [];
    let recordedBlob = null;
    let recordedMime = null;
    let recordedDuration = 0;
    let recordStart = 0;
    let recordTimer = null;
    let captureStream = null;
    const MAX_DURATION_SEC = 300;

    document.addEventListener('DOMContentLoaded', function() {
        loadMailboxes();
        initDropzone();

        // Hide the record button entirely if the browser can't do screen capture
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            document.getElementById('recordToggle').style.display = 'none';
        }
    });

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

    function toggleRecordPanel() {
        const panel = document.getElementById('recordPanel');
        panel.classList.toggle('hidden');
    }

    function pickRecorderMime() {
        // Modern Chrome/Edge can produce MP4/H.264 directly via MediaRecorder.
        // Firefox + older Chrome falls back to webm. The video element plays
        // either, so the analyst's experience is identical regardless.
        const candidates = [
            'video/mp4; codecs=avc1.42E01E,mp4a.40.2',
            'video/mp4; codecs=avc1',
            'video/mp4',
            'video/webm; codecs=vp9,opus',
            'video/webm; codecs=vp9',
            'video/webm; codecs=vp8,opus',
            'video/webm'
        ];
        for (const mime of candidates) {
            if (MediaRecorder.isTypeSupported(mime)) return mime;
        }
        return '';
    }

    async function startRecording() {
        const errEl = document.getElementById('errorMsg');
        errEl.style.display = 'none';

        const wantMic = document.getElementById('recMicToggle').checked;

        try {
            // Always request video + system audio (the user's browser tab/window)
            captureStream = await navigator.mediaDevices.getDisplayMedia({
                video: { frameRate: 30 },
                audio: true
            });

            // Mix in the mic track if requested. The browser shows a separate
            // permission prompt for the mic — silently failing back to no-mic
            // is friendlier than aborting the whole recording.
            if (wantMic) {
                try {
                    const micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    micStream.getAudioTracks().forEach(t => captureStream.addTrack(t));
                } catch (micErr) {
                    console.warn('Mic permission denied or unavailable:', micErr);
                }
            }

            // If the user clicks the browser's "Stop sharing" bar instead of our Stop button
            captureStream.getVideoTracks()[0].onended = () => {
                if (mediaRecorder && mediaRecorder.state === 'recording') {
                    stopRecording();
                }
            };

            const mime = pickRecorderMime();
            recordedChunks = [];
            recordedMime = mime || 'video/webm';
            mediaRecorder = new MediaRecorder(captureStream, mime ? { mimeType: mime } : undefined);
            mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size > 0) recordedChunks.push(e.data); };
            mediaRecorder.onstop = () => {
                recordedDuration = Math.floor((Date.now() - recordStart) / 1000);
                recordedBlob = new Blob(recordedChunks, { type: recordedMime });
                captureStream.getTracks().forEach(t => t.stop());
                captureStream = null;
                clearInterval(recordTimer);
                showPreview();
            };

            mediaRecorder.start(1000); // 1s timeslice
            recordStart = Date.now();

            document.getElementById('recStartBtn').style.display = 'none';
            document.getElementById('recStopBtn').style.display = '';
            document.getElementById('recMicToggle').disabled = true;
            const status = document.getElementById('recStatus');
            status.className = 'rec-status recording';
            status.innerHTML = '<span class="pulse"></span> ' + escapeHtml(window.t('self-service.new_ticket.rec_recording', { time: '0:00' }));

            recordTimer = setInterval(() => {
                const elapsed = Math.floor((Date.now() - recordStart) / 1000);
                status.innerHTML = '<span class="pulse"></span> ' + escapeHtml(window.t('self-service.new_ticket.rec_recording', { time: formatDuration(elapsed) }));
                if (elapsed >= MAX_DURATION_SEC) stopRecording();
            }, 500);
        } catch (err) {
            console.error(err);
            if (err.name === 'NotAllowedError') {
                document.getElementById('recStatus').textContent = window.t('self-service.new_ticket.rec_permission_denied');
            } else {
                document.getElementById('recStatus').textContent = window.t('self-service.new_ticket.rec_start_failed', { message: err.message });
            }
        }
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        }
    }

    function showPreview() {
        const preview = document.getElementById('recPreview');
        preview.src = URL.createObjectURL(recordedBlob);
        preview.style.display = '';

        document.getElementById('recStopBtn').style.display = 'none';
        document.getElementById('recUseBtn').style.display = '';
        document.getElementById('recDiscardBtn').style.display = '';
        document.getElementById('recMicToggle').disabled = false;

        const status = document.getElementById('recStatus');
        status.className = 'rec-status';
        status.textContent = window.t('self-service.new_ticket.rec_recorded', { time: formatDuration(recordedDuration) });
    }

    function discardRecording() {
        recordedBlob = null;
        recordedChunks = [];
        const preview = document.getElementById('recPreview');
        if (preview.src) URL.revokeObjectURL(preview.src);
        preview.src = '';
        preview.style.display = 'none';

        document.getElementById('recStartBtn').style.display = '';
        document.getElementById('recUseBtn').style.display = 'none';
        document.getElementById('recDiscardBtn').style.display = 'none';
        const status = document.getElementById('recStatus');
        status.style.color = '';
        status.style.fontWeight = '';
        status.textContent = window.t('self-service.new_ticket.rec_ready');
    }

    async function useRecording() {
        if (!recordedBlob) return;
        const useBtn = document.getElementById('recUseBtn');
        const discardBtn = document.getElementById('recDiscardBtn');
        useBtn.disabled = true;
        discardBtn.disabled = true;
        useBtn.textContent = window.t('self-service.new_ticket.rec_uploading');

        try {
            const ext = recordedMime.startsWith('video/mp4') ? 'mp4' : 'webm';
            const now = new Date();
            const stamp = now.toISOString().slice(0, 19).replace(/[T:]/g, '-');
            const filename = 'screen-recording-' + stamp + '.' + ext;

            const fd = new FormData();
            fd.append('file', recordedBlob, filename);
            fd.append('duration_seconds', String(recordedDuration));
            fd.append('has_audio', document.getElementById('recMicToggle').checked ? '1' : '0');

            const resp = await fetch('../api/self-service/upload_recording.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || window.t('self-service.new_ticket.rec_upload_failed'));

            recordings.push({
                recording_id: data.recording_id,
                name: filename,
                size_bytes: recordedBlob.size,
                duration_seconds: recordedDuration
            });
            renderAttachments();
            discardRecording();
            document.getElementById('recordPanel').classList.add('hidden');
        } catch (err) {
            alert(window.t('self-service.new_ticket.rec_upload_failed_alert', { message: err.message }));
        } finally {
            useBtn.disabled = false;
            discardBtn.disabled = false;
            useBtn.textContent = window.t('self-service.new_ticket.rec_use');
        }
    }

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
        if (recordedBlob !== null) {
            const panel = document.getElementById('recordPanel');
            panel.classList.remove('hidden');
            panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const status = document.getElementById('recStatus');
            status.style.color = '#dc2626';
            status.style.fontWeight = '600';
            status.innerHTML = window.t('self-service.new_ticket.rec_claim_prompt', {
                use: '<strong>' + escapeHtml(window.t('self-service.new_ticket.rec_use')) + '</strong>',
                discard: '<strong>' + escapeHtml(window.t('self-service.new_ticket.rec_discard')) + '</strong>'
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
                    description: document.getElementById('description').value.trim(),
                    attachments: attachmentData,
                    recording_ids: recordings.map(r => r.recording_id)
                })
            });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('formCard').style.display = 'none';
                successEl.innerHTML = window.t('self-service.new_ticket.created', {
                    number: '<strong>' + escapeHtml(data.ticket_number) + '</strong>',
                    view: '<a href="ticket.php?id=' + data.ticket_id + '">' + escapeHtml(window.t('self-service.new_ticket.view_ticket')) + '</a>',
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
    </script>
</body>
</html>
