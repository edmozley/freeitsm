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
    <script src="../assets/js/i18n.js?v=4"></script>
    <!-- Cleans untrusted message bodies; shared with the analyst inbox. -->
    <script src="../assets/js/safe-html.js?v=4"></script>
    <!-- The app-wide toast. Self-contained (injects its own CSS) and falls back
         to sensible defaults for position/animation, which are analyst
         preferences the portal has no equivalent of. Using it here means portal
         confirmations look and behave exactly like the rest of FreeITSM instead
         of each page inventing its own little message strip. -->
    <script src="../assets/js/toast.js"></script>
    <!-- The same OK/Cancel dialog as the rest of FreeITSM, for the same reason:
         the browser's own confirm() looks like a different application, and
         cannot be themed or translated beyond its message. Guards against a
         second load, so a page that also includes it (course.php) is fine. -->
    <script src="../assets/js/confirm.js?v=6"></script>
    <?php if (!empty($needsFormLogic)): ?>
    <!-- Field types + conditional visibility for catalogue forms. Shared with the
         analyst side (forms/fill.php, the builder preview) and mirroring
         includes/form_logic.php, which is what actually decides on submit. Loaded
         per-page: only the catalogue renders a form. -->
    <script src="../assets/js/form-logic.js?v=9"></script>
    <script src="../assets/js/form-render.js?v=3"></script>
    <?php endif; ?>
    <?php if (!empty($needsRecorder)): ?>
    <!-- Screen recording, for the pages that offer it (raising a ticket and
         replying to one). Loaded per-page rather than globally: it is dead
         weight on the dashboard, and it must be present BEFORE the page script
         runs, since that calls ScreenRecorder.init() at parse time. -->
    <script src="../assets/js/screen-recorder.js?v=2"></script>
    <?php endif; ?>
    <script>const API_BASE = '../api/self-service/';</script>
    <script>window.PAGE = <?php echo json_encode($pageData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php /* The administrator's portal switches, in one place for any page that
             needs them. 🔑 These decide what is DRAWN, never what is allowed:
             every endpoint re-checks the same setting, because a button that is
             not on the page is not a permission. $ssAppearance is resolved by
             the header, which every signed-in portal page goes through. */ ?>
    <script>window.SS_PORTAL = <?php echo json_encode([
        'allow_self_close' => !empty($ssAppearance['allow_self_close']),
        'show_my_assets'   => !empty($ssAppearance['show_my_assets']),
    ]); ?>;</script>
    <?php if ($pageScripts !== ''): ?>
    <?php if (str_contains($pageScripts, '<?php')): ?>
    <?php /* Fail LOUD. A PHP tag in here never ran (see the note above) and would
             otherwise take the page's entire script block down with a syntax error
             that says nothing about the cause. */ ?>
    <script>console.error('FreeITSM: $pageScripts contains a raw PHP tag. Nowdoc blocks are not parsed by PHP — pass the value through $pageData/window.PAGE instead.');</script>
    <?php endif; ?>
    <script><?php echo $pageScripts; ?></script>
    <?php endif; ?>
</body>
</html>
