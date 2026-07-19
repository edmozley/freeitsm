<?php
/**
 * Self-service portal — the screen-recording modal.
 *
 * Included by any page that offers screen recording: raising a ticket
 * (new-ticket.php) and replying to one (tickets.php). The behaviour lives in
 * assets/js/screen-recorder.js, which binds to these ids — so include this
 * ONCE per page, and keep the ids in step with that file.
 *
 * A modal rather than an inline panel because recording is a self-contained
 * task with its own start / stop / keep decision: expanding it in place shoved
 * the rest of the form around and left the video preview no room. Per Ed it
 * also has a Cancel that closes it outright.
 *
 * i18n keys are `self-service.recorder.*` — shared, not the caller's namespace,
 * since both pages show exactly the same dialog.
 */
?>
<div class="rec-modal" id="recordModal" aria-hidden="true">
    <div class="rec-modal-box" role="dialog" aria-modal="true" aria-labelledby="recModalTitle">
        <div class="rec-modal-head">
            <h2 id="recModalTitle"><?php echo htmlspecialchars(t('self-service.recorder.title')); ?></h2>
            <button type="button" class="rec-modal-x" onclick="ScreenRecorder.close()"
                    aria-label="<?php echo htmlspecialchars(t('self-service.recorder.cancel')); ?>">&times;</button>
        </div>
        <div class="rec-modal-body">
            <label class="mic-toggle">
                <input type="checkbox" id="recMicToggle"> <?php echo htmlspecialchars(t('self-service.recorder.include_mic')); ?>
            </label>
            <video id="recPreview" controls style="display:none"></video>
            <span class="rec-status" id="recStatus"><?php echo htmlspecialchars(t('self-service.recorder.ready')); ?></span>
        </div>
        <div class="rec-modal-foot">
            <button type="button" class="btn-rec-start"   id="recStartBtn"   onclick="ScreenRecorder.start()"><?php echo htmlspecialchars(t('self-service.recorder.start')); ?></button>
            <button type="button" class="btn-rec-stop"    id="recStopBtn"    onclick="ScreenRecorder.stop()"    style="display:none"><?php echo htmlspecialchars(t('self-service.recorder.stop')); ?></button>
            <button type="button" class="btn-rec-use"     id="recUseBtn"     onclick="ScreenRecorder.use()"     style="display:none"><?php echo htmlspecialchars(t('self-service.recorder.use')); ?></button>
            <button type="button" class="btn-rec-discard" id="recDiscardBtn" onclick="ScreenRecorder.discard()" style="display:none"><?php echo htmlspecialchars(t('self-service.recorder.discard')); ?></button>
            <button type="button" class="btn-cancel" onclick="ScreenRecorder.close()"><?php echo htmlspecialchars(t('self-service.recorder.cancel')); ?></button>
        </div>
    </div>
</div>
