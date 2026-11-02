<?php
/**
 * The notification bell's markup - one bell for the analyst app and the
 * self-service portal (discussions #55 and #62). The look is
 * assets/css/notification-bell.css and the behaviour assets/js/notification-bell.js;
 * each header prints this and calls NotificationBell.init() with its own URLs.
 */

/**
 * Print the bell: its stylesheet, markup and script, and start it.
 *
 * @param string $assetBase  URL prefix for assets/ from the current page
 * @param array  $cfg        NotificationBell.init() config: list, markRead, clear, linkPrefix, seenKey
 */
function notificationBellRender(string $assetBase, array $cfg): void
{
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase); ?>assets/css/notification-bell.css?v=1">
    <div class="nb-wrap">
        <button class="nb-btn" id="nbBtn" type="button" aria-label="<?php echo htmlspecialchars(t('common.notifications.aria')); ?>" title="<?php echo htmlspecialchars(t('common.notifications.title')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            <span class="nb-count" id="nbCount" hidden>0</span>
        </button>
        <div class="nb-panel" id="nbPanel">
            <div class="nb-head">
                <span><?php echo htmlspecialchars(t('common.notifications.title')); ?></span>
                <span class="nb-head-actions">
                    <button type="button" class="nb-markall" id="nbMarkAll"><?php echo htmlspecialchars(t('common.notifications.mark_all')); ?></button>
                    <button type="button" class="nb-markall nb-clearall" id="nbClearAll"><?php echo htmlspecialchars(t('common.notifications.clear_all')); ?></button>
                </span>
            </div>
            <div id="nbList"></div>
        </div>
    </div>
    <script src="<?php echo htmlspecialchars($assetBase); ?>assets/js/notification-bell.js?v=4"></script>
    <script>NotificationBell.init(<?php echo json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>);</script>
    <?php
}
