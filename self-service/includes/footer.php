<?php
/**
 * Self-service portal — the shared page bottom.
 *
 * Closes the layout opened by header.php and bootstraps client-side i18n, which
 * every portal page needs and each one used to wire up itself.
 *
 * A page may set BEFORE including this:
 *   $pageScripts — a string of page-specific JS, emitted after the i18n bootstrap
 *                  so window.t() and API_BASE are already available to it.
 */
$pageScripts = $pageScripts ?? '';
?>
    </div><!-- /.portal-layout -->

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces ?? ['common', 'self-service']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>const API_BASE = '../api/self-service/';</script>
    <?php if ($pageScripts !== ''): ?>
    <script><?php echo $pageScripts; ?></script>
    <?php endif; ?>
</body>
</html>
