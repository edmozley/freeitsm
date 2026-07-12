<?php
/**
 * LMS — the player for an authored (native) course.
 *
 * Included by player.php once it knows the course is native; $course, $courseId
 * and $conn are already set up there, and the session/module guard has run.
 *
 * The content itself is fetched by the JS from api/lms/course_content.php rather
 * than rendered here, because that endpoint is the one that never sends the
 * answer key. Rendering the lessons server-side would mean writing a second
 * query, and a second chance to leak it.
 */
$current_page = 'lms';
$path_prefix  = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($course['title']); ?> - <?php echo htmlspecialchars(t('lms.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lms-native">
        <div class="lms-native-bar">
            <span class="course-title"><?php echo htmlspecialchars($course['title']); ?></span>
            <div class="lms-native-bar-right">
                <div class="lms-native-progress" title="">
                    <div class="lms-native-progress-fill" id="progressFill"></div>
                </div>
                <span class="lms-native-step" id="stepLabel"></span>
                <a href="./" class="btn btn-secondary" style="font-size: 12px; padding: 5px 12px;"><?php echo htmlspecialchars(t('lms.player.back')); ?></a>
            </div>
        </div>

        <div class="lms-native-body">
            <!-- Contents -->
            <nav class="lms-native-toc" id="toc"></nav>

            <!-- The lesson -->
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

    <script>
        window.API_BASE  = '../api/lms/';
        window.COURSE_ID = <?php echo (int)$courseId; ?>;
    </script>
    <script src="../assets/js/lms-native-player.js?v=1"></script>
</body>
</html>
