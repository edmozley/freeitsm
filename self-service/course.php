<?php
/**
 * Self-Service Portal — taking a course.
 *
 * The portal's player. Both kinds of course work here, because neither the
 * authored player nor the SCORM runtime cares who is sitting in front of it:
 *   native  assets/js/lms-native-player.js, fed by api/lms/course_content.php
 *   scorm   the package in an iframe with assets/js/scorm-api.js bridging to
 *           api/lms/scorm_data.php
 *
 * 🔑 THE SAME JS AND THE SAME ENDPOINTS AS THE ANALYST PLAYER. A second player
 * written for the portal would be a second implementation of SCORM sequencing
 * and of quiz grading, and the day they disagreed one population's training
 * records would quietly stop matching the other's. Only the page around it —
 * portal chrome instead of analyst chrome — is different.
 *
 * ⚠️ The gate is re-checked HERE, server-side, before anything is drawn. The
 * Training page only ever links to assigned courses, but a link is a suggestion
 * and the URL is typeable.
 */
/* ⚠️ SESSION FIRST. includes/auth.php reads $_SESSION and redirects to login
   when it finds nothing — so requiring it before the session is open sends a
   perfectly well signed-in person to the login page. Same opening sequence as
   includes/header.php, which is where every other portal page gets it from;
   this page needs the guard EARLIER than the header, because it has to decide
   whether the course may be opened before drawing anything at all. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';       // redirects to login.php if not signed in
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lms_access.php';

$courseId = (int)($_GET['id'] ?? 0);
$conn     = connectToDatabase();

$learner = LmsLearner::user((int)$ss_user_id);
if (!$courseId || !$learner) {
    header('Location: training.php');
    exit;
}

$st = $conn->prepare("SELECT * FROM lms_courses WHERE id = ? AND is_active = 1");
$st->execute([$courseId]);
$course = $st->fetch(PDO::FETCH_ASSOC);

// A course that is not theirs and a course that does not exist are the SAME
// answer, deliberately: telling somebody "that exists but is not yours" hands
// them the fact that a course with that id exists at all.
if (!$course || !lmsCanAccessCourse($conn, $learner, $courseId)) {
    header('Location: training.php?denied=1');
    exit;
}

$isNative = ($course['content_type'] ?? 'scorm') === 'native';

// SCORM content is static files under the LMS module, one directory up. Its own
// .htaccess turns execution off for that whole tree, so serving it to a portal
// visitor is no different from serving it to an analyst.
$launchUrl = (!$isNative && !empty($course['launch_url']))
    ? '../lms/content/' . $courseId . '/' . $course['launch_url']
    : null;

$pageTitleKey = 'self-service.training.title';
$activeNav    = 'training';
$bodyClass    = 'portal-app';
// The player's markup and JS speak the `lms` namespace, so the portal has to
// load it alongside its own or every label renders as its key.
$translationNamespaces = ['common', 'self-service', 'lms'];

// The player's own stylesheet, in the HEAD with the portal's own rather than
// half way down the body.
$pageHead = '<link rel="stylesheet" href="../assets/css/lms.css?v=9">';

$pageStyles = <<<'CSS'
.cr-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    background: var(--surface, #fff);
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.cr-bar .course-title { font-weight: 600; font-size: 15px; color: var(--text, #333); flex: 1; min-width: 0;
                        overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cr-bar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.cr-back {
    font-size: 13px; padding: 6px 12px; border-radius: 6px; text-decoration: none;
    border: 1px solid var(--border, #e5e7eb); color: var(--text, #333); background: var(--surface, #fff);
}
.cr-frame { width: 100%; height: calc(100vh - 48px - 49px); border: 0; display: block; background: var(--surface, #fff); }

/* The player's stylesheet is shared with the analyst app and paints in the
   analyst accent, so inside the portal its Next button and progress bar came
   out blue against a green header — a control that looks borrowed from another
   product. The ACCENT TOKENS are remapped on the container rather than lms.css
   being edited, because that file is the analyst player's too and every module
   here already carries its own accent (--ss-*, --kb-*, --lms-* and the rest).
   Custom properties inherit, so this reaches everything drawn inside.

   ⚠️ It is --lms-* that has to be remapped, NOT --accent. Every module carries
   its own accent pair and lms.css names --lms-accent twelve times; overriding
   --accent looked right and changed nothing, because the rules that paint the
   player never read it. Measured, not assumed: rgb(37,99,235) is #2563eb, the
   LMS blue from includes/module-colors.php. */
.lms-native {
    --lms-accent:       var(--ss-accent, #0f9d58);
    --lms-accent-hover: var(--ss-accent-hover, #0b8043);
    --lms-accent-soft:  var(--ss-accent-soft, #e6f4ea);
    --lms-on-accent:    var(--ss-on-accent, #fff);
    --accent:           var(--ss-accent, #0f9d58);
    --accent-hover:     var(--ss-accent-hover, #0b8043);
    --on-accent:        var(--ss-on-accent, #fff);
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($isNative): ?>
    <!-- The authored player. These element ids are the contract with
         assets/js/lms-native-player.js — it looks them up by name, so they must
         match the analyst player's markup exactly. -->
    <div class="lms-native">
        <div class="lms-native-bar cr-bar">
            <span class="course-title"><?php echo htmlspecialchars($course['title']); ?></span>
            <div class="lms-native-bar-right cr-bar-right">
                <div class="lms-native-progress"><div class="lms-native-progress-fill" id="progressFill"></div></div>
                <span class="lms-native-step" id="stepLabel"></span>
                <a href="training.php" class="cr-back"><?php echo htmlspecialchars(t('self-service.training.back')); ?></a>
            </div>
        </div>

        <div class="lms-native-body">
            <nav class="lms-native-toc" id="toc"></nav>
            <main class="lms-native-main">
                <div id="stage" class="lms-native-stage">
                    <p class="lms-empty"><?php echo htmlspecialchars(t('lms.player.loading')); ?></p>
                </div>
                <div class="lms-native-nav" id="navBar" style="display:none;">
                    <button class="btn btn-secondary" id="prevBtn" onclick="LMSPlayer.prev()"><?php echo htmlspecialchars(t('lms.player.prev')); ?></button>
                    <button class="btn btn-primary" id="nextBtn" onclick="LMSPlayer.next()"><?php echo htmlspecialchars(t('lms.player.next')); ?></button>
                </div>
            </main>
        </div>
    </div>
<?php elseif ($launchUrl !== null): ?>
    <div class="cr-bar">
        <span class="course-title"><?php echo htmlspecialchars($course['title']); ?></span>
        <div class="cr-bar-right">
            <a href="training.php" class="cr-back"><?php echo htmlspecialchars(t('self-service.training.back')); ?></a>
        </div>
    </div>
    <iframe id="scormFrame" class="cr-frame" src="<?php echo htmlspecialchars($launchUrl); ?>"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope"
            sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe>
<?php else: ?>
    <div class="cr-bar">
        <span class="course-title"><?php echo htmlspecialchars($course['title']); ?></span>
        <div class="cr-bar-right"><a href="training.php" class="cr-back"><?php echo htmlspecialchars(t('self-service.training.back')); ?></a></div>
    </div>
    <p style="padding: 40px 20px; text-align: center; color: var(--text-muted, #666);">
        <?php echo htmlspecialchars(t('self-service.training.unplayable')); ?>
    </p>
<?php endif; ?>

<?php
/* The confirm dialog the player uses when somebody submits with questions
   unanswered. Not in the portal's normal bundle — loaded here rather than
   globally so every other portal page stays as light as it was.

   ⚠️ ORDER. These sit before footer.php, which is where window.translations and
   i18n.js are emitted — so window.t() does NOT exist while these files are being
   parsed. That is fine, and only because the player defers its real work to
   DOMContentLoaded, which fires after every script in the document has run. Do
   not "simplify" the player to call init() at parse time: it would come up with
   every label rendered as its translation key. */
?>
<script src="../assets/js/confirm.js"></script>
<script>
    window.API_BASE     = '../api/lms/';
    window.COURSE_ID    = <?php echo (int)$courseId; ?>;
    // The portal has no LMS console to go back to.
    window.LMS_BACK_URL = 'training.php';
    /* 🔴 WHICH IDENTITY IS TAKING THIS COURSE. The analyst app and the portal
       share one PHP session, so an administrator signed into both has two, and
       the server cannot tell from the session alone. Every call the players make
       carries this; without it an administrator's attempt in the portal is
       written onto their ANALYST training record while this page has gated it as
       the portal user — the gate and the writer disagreeing about who is acting. */
    window.LMS_AS = 'portal';
</script>
<?php if ($isNative): ?>
<script src="../assets/js/lms-native-player.js?v=2"></script>
<?php elseif ($launchUrl !== null): ?>
<script>
window.SCORM_CONFIG = {
    courseId: <?php echo (int)$courseId; ?>,
    // The runtime only uses this to label its own debug output; the SERVER
    // decides whose progress row is written, from the session. A portal learner
    // is not an analyst, so this is their portal id and is never treated as one.
    analystId: <?php echo (int)$ss_user_id; ?>,
    scormVersion: <?php echo json_encode($course['scorm_version'] ?? '1.2'); ?>,
    apiEndpoint: '../api/lms/scorm_data.php'
};
</script>
<script src="../assets/js/scorm-api.js?v=2"></script>
<script>
// Commit before the tab goes, so a half-finished attempt is not lost.
window.addEventListener('beforeunload', function () {
    if (window.API) {
        try { window.API.LMSCommit(''); } catch (e) {}
        try { window.API.LMSFinish(''); } catch (e) {}
    }
    if (window.API_1484_11) {
        try { window.API_1484_11.Commit(''); } catch (e) {}
        try { window.API_1484_11.Terminate(''); } catch (e) {}
    }
});
</script>
<?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
