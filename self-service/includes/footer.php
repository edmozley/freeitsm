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
 *   $pageData    — page-specific VALUES for that JS, as an array. Emitted as
 *                  window.PAGE, JSON-encoded.
 *   $needsRecorder — true to load assets/js/screen-recorder.js. Pair it with
 *                  `require __DIR__ . '/includes/record-modal.php';` in the
 *                  markup; the script binds to that partial's element ids.
 *
 * $pageData exists because $pageScripts is echoed as a plain STRING, and pages
 * build it with a nowdoc (<<<'JS') so that JS template literals — ${...} — are
 * not eaten by PHP interpolation. The cost of a nowdoc is that PHP tags inside
 * it are NOT executed: a `<?php echo $id; ?>` written in there reaches the
 * browser verbatim, and because the stray `<` is a syntax error at the top of
 * the block, the ENTIRE script silently fails to parse and the whole page's JS
 * dies. That happened to the ticket page. So values come through here instead
 * of being interpolated into the script text.
 */
$pageScripts = $pageScripts ?? '';
$pageData    = $pageData ?? [];
?>
    </div><!-- /.portal-layout -->

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces ?? ['common', 'self-service']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <!-- Cleans untrusted message bodies; shared with the analyst inbox. -->
    <script src="../assets/js/safe-html.js?v=1"></script>
    <!-- The app-wide toast. Self-contained (injects its own CSS) and falls back
         to sensible defaults for position/animation, which are analyst
         preferences the portal has no equivalent of. Using it here means portal
         confirmations look and behave exactly like the rest of FreeITSM instead
         of each page inventing its own little message strip. -->
    <script src="../assets/js/toast.js"></script>
    <?php if (!empty($needsRecorder)): ?>
    <!-- Screen recording, for the pages that offer it (raising a ticket and
         replying to one). Loaded per-page rather than globally: it is dead
         weight on the dashboard, and it must be present BEFORE the page script
         runs, since that calls ScreenRecorder.init() at parse time. -->
    <script src="../assets/js/screen-recorder.js?v=1"></script>
    <?php endif; ?>
    <script>const API_BASE = '../api/self-service/';</script>
    <script>window.PAGE = <?php echo json_encode($pageData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php if ($pageScripts !== ''): ?>
    <?php if (strpos($pageScripts, '<?php') !== false): ?>
    <?php /* Fail LOUD. A PHP tag in here never ran (see the note above) and would
             otherwise take the page's entire script block down with a syntax error
             that says nothing about the cause. */ ?>
    <script>console.error('FreeITSM: $pageScripts contains a raw PHP tag. Nowdoc blocks are not parsed by PHP — pass the value through $pageData/window.PAGE instead.');</script>
    <?php endif; ?>
    <script><?php echo $pageScripts; ?></script>
    <?php endif; ?>
</body>
</html>
