<?php
/**
 * LMS SCORM Player
 * Loads a SCORM course in an iframe with the API bridge on the parent window.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
I18n::initFromSession();
Tz::init();
require_once '../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

requireModuleAccess('lms');

$courseId = (int)($_GET['course_id'] ?? 0);
if (!$courseId) {
    die('Missing course ID');
}

$conn = connectToDatabase();
$stmt = $conn->prepare("SELECT * FROM lms_courses WHERE id = ? AND is_active = 1");
$stmt->execute([$courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die('Course not found');
}

if (empty($course['launch_url'])) {
    die('Course has no launch URL. The SCORM package may not have been parsed correctly.');
}

$launchUrl = 'content/' . $courseId . '/' . $course['launch_url'];

$current_page = 'lms';
$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($course['title']); ?> - <?php echo htmlspecialchars(t('lms.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css?v=3">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lms-player">
        <div class="lms-player-toolbar">
            <span class="course-title"><?php echo htmlspecialchars($course['title']); ?></span>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span style="font-size: 12px; color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('lms.player.scorm_version', ['version' => $course['scorm_version'] ?? '?'])); ?></span>
                <a href="./" class="btn btn-secondary" style="font-size: 12px; padding: 5px 12px;"><?php echo htmlspecialchars(t('lms.player.back')); ?></a>
            </div>
        </div>
        <iframe id="scormFrame" class="lms-player-frame" src="<?php echo htmlspecialchars($launchUrl); ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope" sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe>
    </div>

    <!-- SCORM API Bridge (must be on parent window before iframe loads) -->
    <script>
    window.SCORM_CONFIG = {
        courseId: <?php echo $courseId; ?>,
        analystId: <?php echo $_SESSION['analyst_id']; ?>,
        scormVersion: <?php echo json_encode($course['scorm_version'] ?? '1.2'); ?>,
        apiEndpoint: '../api/lms/scorm_data.php'
    };
    </script>
    <script src="../assets/js/scorm-api.js"></script>

    <script>
    // Auto-commit on page unload
    window.addEventListener('beforeunload', function() {
        if (window.API) {
            try { window.API.LMSCommit(''); } catch(e) {}
            try { window.API.LMSFinish(''); } catch(e) {}
        }
        if (window.API_1484_11) {
            try { window.API_1484_11.Commit(''); } catch(e) {}
            try { window.API_1484_11.Terminate(''); } catch(e) {}
        }
    });
    </script>
</body>
</html>
