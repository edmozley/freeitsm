/**
 * Screen recording for the self-service portal — the ONE implementation.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Recording started life on the new-ticket form. Letting people record while
 * REPLYING needed the same ~250 lines: capture, MIME negotiation, the countdown,
 * the preview, the claim-or-discard decision, the upload. Copying it would have
 * meant two recorders drifting apart — one growing a fix for a browser quirk the
 * other never gets, which is exactly how the HTML sanitiser ended up protecting
 * the portal and not the inbox.
 *
 * It pairs with:
 *   self-service/includes/record-modal.php  — the markup (ids matched here)
 *   assets/css/self-service.css             — .rec-modal and friends
 *   api/self-service/upload_recording.php   — the pending upload
 *
 * The module owns the modal and the capture; the PAGE owns what happens to a
 * claimed recording (attach to a new ticket / to a reply), which arrives via the
 * onClaimed callback. That is the whole seam between them.
 *
 * ONE modal per page — it binds by element id, matching the single partial.
 */
(function (window, document) {
    'use strict';

    var cfg = {
        uploadUrl: 'upload_recording.php',
        maxSeconds: 300,
        onClaimed: function () {}
    };

    // Capture state. Lives here rather than on the page so two pages cannot
    // disagree about what "currently recording" means.
    var mediaRecorder = null;
    var recordedChunks = [];
    var recordedBlob = null;
    var recordedMime = null;
    var recordedDuration = 0;
    var recordStart = 0;
    var recordTimer = null;
    var captureStream = null;

    function el(id) { return document.getElementById(id); }

    function t(key, params) {
        return (window.t ? window.t(key, params) : key);
    }

    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    function formatDuration(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function notifyError(message) {
        // The app-wide toast when it is loaded (every portal page loads it via
        // footer.php); alert() only as a last resort, because an upload failure
        // that vanishes silently means someone believes their video was sent.
        if (typeof window.showToast === 'function') {
            window.showToast(message, 'error');
        } else {
            window.alert(message);
        }
    }

    /**
     * iOS Safari has no getDisplayMedia at all. Callers hide their Record button
     * rather than letting someone press it and meet a confusing failure.
     */
    function isSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia);
    }

    /** A captured-but-unclaimed recording — what the submit guard asks about. */
    function hasUnclaimed() {
        return !!recordedBlob;
    }

    function isRecording() {
        return !!(mediaRecorder && mediaRecorder.state === 'recording');
    }

    function open() {
        var modal = el('recordModal');
        if (!modal) return;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }

    /**
     * Closing DISCARDS an unclaimed recording — cancelling should not silently
     * leave something attached. That is the opposite of the submit guard, which
     * blocks: there they were heading onward and might lose work; here they have
     * explicitly said no.
     *
     * Refuses to close mid-recording, so the modal can never be dismissed while
     * the screen is still being captured.
     */
    function close() {
        if (isRecording()) return;
        if (recordedBlob) discard();
        var modal = el('recordModal');
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }

    function pickRecorderMime() {
        // Modern Chrome/Edge produce MP4/H.264 directly. Firefox and older
        // Chrome fall back to WebM. A <video> plays either, so no server-side
        // transcode is needed and the analyst's experience is identical.
        var candidates = [
            'video/mp4; codecs=avc1.42E01E,mp4a.40.2',
            'video/mp4; codecs=avc1',
            'video/mp4',
            'video/webm; codecs=vp9,opus',
            'video/webm; codecs=vp9',
            'video/webm; codecs=vp8,opus',
            'video/webm'
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i])) return candidates[i];
        }
        return '';
    }

    async function start() {
        var wantMic = el('recMicToggle').checked;

        try {
            captureStream = await navigator.mediaDevices.getDisplayMedia({
                video: { frameRate: 30 },
                audio: true
            });

            // The mic is a SEPARATE browser permission prompt. Falling back to
            // no-mic is friendlier than aborting a recording they have already
            // picked a screen for.
            if (wantMic) {
                try {
                    var micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    micStream.getAudioTracks().forEach(function (track) {
                        captureStream.addTrack(track);
                    });
                } catch (micErr) {
                    console.warn('Mic permission denied or unavailable:', micErr);
                }
            }

            // The browser's own "Stop sharing" bar, rather than our Stop button.
            captureStream.getVideoTracks()[0].onended = function () {
                if (isRecording()) stop();
            };

            var mime = pickRecorderMime();
            recordedChunks = [];
            recordedMime = mime || 'video/webm';
            mediaRecorder = new MediaRecorder(captureStream, mime ? { mimeType: mime } : undefined);

            mediaRecorder.ondataavailable = function (e) {
                if (e.data && e.data.size > 0) recordedChunks.push(e.data);
            };
            mediaRecorder.onstop = function () {
                recordedDuration = Math.floor((Date.now() - recordStart) / 1000);
                recordedBlob = new Blob(recordedChunks, { type: recordedMime });
                captureStream.getTracks().forEach(function (track) { track.stop(); });
                captureStream = null;
                clearInterval(recordTimer);
                showPreview();
            };

            mediaRecorder.start(1000); // 1s timeslice
            recordStart = Date.now();

            el('recStartBtn').style.display = 'none';
            el('recStopBtn').style.display = '';
            el('recMicToggle').disabled = true;

            var status = el('recStatus');
            status.className = 'rec-status recording';
            status.innerHTML = '<span class="pulse"></span> ' +
                escapeHtml(t('self-service.recorder.recording', { time: '0:00' }));

            recordTimer = setInterval(function () {
                var elapsed = Math.floor((Date.now() - recordStart) / 1000);
                status.innerHTML = '<span class="pulse"></span> ' +
                    escapeHtml(t('self-service.recorder.recording', { time: formatDuration(elapsed) }));
                if (elapsed >= cfg.maxSeconds) stop();
            }, 500);
        } catch (err) {
            console.error(err);
            el('recStatus').textContent = (err.name === 'NotAllowedError')
                ? t('self-service.recorder.permission_denied')
                : t('self-service.recorder.start_failed', { message: err.message });
        }
    }

    function stop() {
        if (isRecording()) mediaRecorder.stop();
    }

    function showPreview() {
        var preview = el('recPreview');
        preview.src = URL.createObjectURL(recordedBlob);
        preview.style.display = '';

        el('recStopBtn').style.display = 'none';
        el('recUseBtn').style.display = '';
        el('recDiscardBtn').style.display = '';
        el('recMicToggle').disabled = false;

        var status = el('recStatus');
        status.className = 'rec-status';
        status.textContent = t('self-service.recorder.recorded', { time: formatDuration(recordedDuration) });
    }

    function discard() {
        recordedBlob = null;
        recordedChunks = [];

        var preview = el('recPreview');
        if (preview.src) URL.revokeObjectURL(preview.src);
        preview.src = '';
        preview.style.display = 'none';

        el('recStartBtn').style.display = '';
        el('recUseBtn').style.display = 'none';
        el('recDiscardBtn').style.display = 'none';

        var status = el('recStatus');
        status.className = 'rec-status';
        status.textContent = t('self-service.recorder.ready');
    }

    /**
     * Upload and hand the result to the page.
     *
     * The recording is uploaded as PENDING (no ticket yet, or no reply yet) and
     * claimed server-side when the ticket or reply is written — see
     * api/self-service/upload_recording.php.
     */
    async function use() {
        if (!recordedBlob) return;

        var useBtn = el('recUseBtn');
        var discardBtn = el('recDiscardBtn');
        useBtn.disabled = true;
        discardBtn.disabled = true;
        useBtn.textContent = t('self-service.recorder.uploading');

        try {
            var ext = recordedMime.indexOf('video/mp4') === 0 ? 'mp4' : 'webm';
            var stamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
            var filename = 'screen-recording-' + stamp + '.' + ext;

            var fd = new FormData();
            fd.append('file', recordedBlob, filename);
            fd.append('duration_seconds', String(recordedDuration));
            fd.append('has_audio', el('recMicToggle').checked ? '1' : '0');

            var resp = await fetch(cfg.uploadUrl, { method: 'POST', body: fd });
            var data = await resp.json();
            if (!data.success) throw new Error(data.error || t('self-service.recorder.upload_failed'));

            cfg.onClaimed({
                recording_id: data.recording_id,
                name: filename,
                size_bytes: recordedBlob.size,
                duration_seconds: recordedDuration
            });

            discard();
            close();   // claimed — nothing left to decide
        } catch (err) {
            notifyError(t('self-service.recorder.upload_failed_message', { message: err.message }));
        } finally {
            useBtn.disabled = false;
            discardBtn.disabled = false;
            useBtn.textContent = t('self-service.recorder.use');
        }
    }

    function init(options) {
        options = options || {};
        if (options.uploadUrl)  cfg.uploadUrl  = options.uploadUrl;
        if (options.maxSeconds) cfg.maxSeconds = options.maxSeconds;
        if (options.onClaimed)  cfg.onClaimed  = options.onClaimed;
    }

    window.ScreenRecorder = {
        init: init,
        open: open,
        close: close,
        start: start,
        stop: stop,
        use: use,
        discard: discard,
        isSupported: isSupported,
        isRecording: isRecording,
        hasUnclaimed: hasUnclaimed,
        formatDuration: formatDuration
    };
})(window, document);
